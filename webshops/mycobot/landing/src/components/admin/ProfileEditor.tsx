'use client';

import React from 'react';
import { useRouter } from 'next/navigation';
import { Badge, Button, Card, Icon, Input, Select, Switch, Toast } from '@/design-system';
import type { Variable, ConfigValue } from '@/lib/config/registry';
import type { Resolution } from '@/lib/config/resolve';

/**
 * The per-tenant configuration screen — and the reason the back office does not grow
 * with the number of variables: it renders whatever the registry declares, one row
 * per variable, control chosen by `type`. A new variable is one entry in
 * registry.ts and zero lines here.
 *
 * The right-hand state chip is the point of the screen. "Surchargé" versus "Hérité"
 * is what tells an admin, at a glance, what actually differs for this client — the
 * same job « Vide — fallback FR » does in TranslationEditor.
 */

export interface ProfileTenant {
  slug: string;
  name: string;
  internalApiUrl: string;
  stores: number;
  licenseStatus: string | null;
}

export interface ProfileData {
  tenant: ProfileTenant;
  variables: Variable[];
  resolutions: Resolution[];
  errors: Record<string, string>;
  dirty: boolean;
  publishedVersion: number;
  publishedAt: string | null;
  grants: string[];
  packages: { code: string; name: string; granted: boolean; status: string | null }[];
  fixture: boolean;
}

type Toasty = { tone: 'success' | 'danger' | 'info' | 'warning'; title: string; message: string } | null;

export function ProfileEditor({ data }: { data: ProfileData }) {
  const router = useRouter();
  const [resolutions, setResolutions] = React.useState(data.resolutions);
  const [errors, setErrors] = React.useState(data.errors);
  const [dirty, setDirty] = React.useState(data.dirty);
  const [selected, setSelected] = React.useState<string>(data.variables[0]?.key ?? '');
  const [busy, setBusy] = React.useState(false);
  const [toast, setToast] = React.useState<Toasty>(null);

  const byKey = React.useMemo(() => new Map(resolutions.map((r) => [r.key, r])), [resolutions]);
  const overrides = resolutions.filter((r) => r.from === 'tenant').length;
  const invalid = Object.keys(errors).length;

  /** Sends one key at a time: a rejected value must not roll back the others. */
  const write = async (key: string, value: ConfigValue) => {
    setBusy(true);
    try {
      const response = await fetch(`/api/admin/app-config/${data.tenant.slug}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ values: { [key]: value } }),
      });
      const body = (await response.json()) as { errors?: Record<string, string> };

      if (!response.ok) {
        setErrors((current) => ({ ...current, ...(body.errors ?? { [key]: 'Valeur refusée.' }) }));
        setToast({
          tone: 'danger',
          title: 'Valeur refusée',
          message: body.errors?.[key] ?? 'La valeur n’a pas été enregistrée.',
        });
        return;
      }

      setErrors((current) => {
        const next = { ...current };
        delete next[key];
        return next;
      });
      setDirty(true);
      // Re-resolve locally so the "Hérité"/"Surchargé" chip flips immediately; the
      // server refresh below reconciles the chain.
      setResolutions((current) =>
        current.map((r) => {
          if (r.key !== key) return r;
          const chain = r.chain.map((link) => (link.layer === 'tenant' ? { ...link, value } : link));
          const winner = value !== null ? 'tenant' : (chain.find((l) => l.layer !== 'tenant' && l.value !== null)?.layer ?? 'registry');
          const winning = value !== null ? value : (chain.find((l) => l.layer === winner)?.value ?? null);
          return { ...r, value: winning, from: winner, chain };
        }),
      );
      router.refresh();
    } finally {
      setBusy(false);
    }
  };

  const publishDraft = async () => {
    setBusy(true);
    try {
      const response = await fetch(`/api/admin/app-config/${data.tenant.slug}/publish`, { method: 'POST' });
      const body = (await response.json()) as { version?: number; errors?: Record<string, string>; error?: string };
      if (!response.ok) {
        setErrors((current) => ({ ...current, ...(body.errors ?? {}) }));
        setToast({ tone: 'danger', title: 'Publication refusée', message: body.error ?? 'Corrigez les valeurs invalides.' });
        return;
      }
      setDirty(false);
      setToast({
        tone: 'success',
        title: `Publiée en v${body.version}`,
        message: 'La PWA rafraîchira sa configuration au prochain démarrage.',
      });
      router.refresh();
    } finally {
      setBusy(false);
    }
  };

  const current = byKey.get(selected);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', maxWidth: 1480 }}>
      {dirty && (
        <div role="status" style={noticeStyle}>
          <Icon name="triangle-alert" size={17} style={{ marginTop: 1 }} />
          <span style={{ font: 'var(--type-body-sm)' }}>
            <strong>Brouillon</strong> — ces valeurs ne sont pas encore servies. La PWA reçoit toujours{' '}
            <span className="fb-num">v{data.publishedVersion}</span>. La publication incrémente{' '}
            <span className="fb-num">version</span> et invalide le cache du service worker.
          </span>
        </div>
      )}

      <Card padding="md">
        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-4)', flexWrap: 'wrap' }}>
          <div style={{ minWidth: 0, flex: 1 }}>
            <div style={{ font: 'var(--type-heading-4)' }}>{data.tenant.name}</div>
            <div style={{ display: 'flex', gap: 'var(--space-2)', marginTop: 4, flexWrap: 'wrap' }}>
              <Chip icon="tag">{data.tenant.slug}</Chip>
              <Chip icon="globe">{data.tenant.internalApiUrl || '— aucune URL de service'}</Chip>
              <Chip icon="store">{data.tenant.stores} points de vente</Chip>
            </div>
          </div>
          <Stat label="Licence">
            {data.tenant.licenseStatus ? (
              <Badge tone={data.tenant.licenseStatus === 'active' ? 'success' : 'info'} dot size="sm">
                <span className="fb-num">{data.tenant.licenseStatus}</span>
              </Badge>
            ) : (
              <span style={{ font: 'var(--type-body-sm)', color: 'var(--text-tertiary)' }}>aucune</span>
            )}
          </Stat>
          <Stat label="Version servie">
            <span className="fb-num">v{data.publishedVersion}</span>
          </Stat>
          <Stat label="Surcharges">
            <span className="fb-num">{overrides}</span>
            <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}> / {data.variables.length}</span>
          </Stat>
        </div>

        {/* The link the client actually receives. Two of them, because showing a
            customer an unpublished draft by accident is the obvious way to promise
            something that is not live. */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap', marginTop: 'var(--space-4)', paddingTop: 'var(--space-4)', borderTop: '1px solid var(--border-subtle)' }}>
          <Icon name="external-link" size={15} color="var(--text-tertiary)" />
          <span style={{ font: 'var(--type-label)' }}>Page charte du client</span>
          <a href={`/preview/${data.tenant.slug}`} target="_blank" rel="noreferrer" className="fb-num" style={linkStyle}>
            /preview/{data.tenant.slug}
          </a>
          <Badge tone="success" size="sm">publié · v{data.publishedVersion}</Badge>
          <a href={`/preview/${data.tenant.slug}?draft=1`} target="_blank" rel="noreferrer" className="fb-num" style={linkStyle}>
            ?draft=1
          </a>
          <Badge tone="warning" size="sm">brouillon · session requise</Badge>
          <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)', marginInlineStart: 'auto' }}>
            Tout le produit rendu à sa charte — à envoyer tel quel.
          </span>
        </div>
      </Card>

      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 360px', gap: 'var(--space-4)', alignItems: 'start' }}>
        <Card
          padding="none"
          title="Variables"
          subtitle="Générées depuis le registre — src/lib/config/registry.ts"
          actions={
            <div style={{ display: 'flex', gap: 'var(--space-2)' }}>
              <Badge tone="neutral" size="sm">{data.variables.length} déclarées</Badge>
              <Badge tone="brand" size="sm">{overrides} surchargées</Badge>
              {invalid > 0 && <Badge tone="danger" size="sm">{invalid} invalide{invalid > 1 ? 's' : ''}</Badge>}
            </div>
          }
        >
          <div>
            {groupVariables(data.variables).map(({ group, variables }) => (
              <div key={group}>
                <div style={groupHeaderStyle}>
                  <span className="fb-num" style={{ font: 'var(--type-data)', color: 'var(--text-secondary)' }}>{group}.*</span>
                  <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>
                    {variables.length} variable{variables.length > 1 ? 's' : ''} ·{' '}
                    {variables.filter((v) => byKey.get(v.key)?.from === 'tenant').length} surchargée(s)
                  </span>
                </div>
                {variables.map((v) => (
                  <VariableRow
                    key={v.key}
                    variable={v}
                    resolution={byKey.get(v.key)}
                    error={errors[v.key]}
                    selected={selected === v.key}
                    busy={busy}
                    onSelect={() => setSelected(v.key)}
                    onChange={(value) => write(v.key, value)}
                  />
                ))}
              </div>
            ))}
          </div>

          <div style={lockedStyle}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', marginBottom: 'var(--space-2)' }}>
              <Icon name="lock" size={14} color="var(--text-tertiary)" />
              <span style={{ font: 'var(--type-title)' }}>Droits — dérivés de la facturation</span>
              <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>
                lecture seule · <span className="fb-num">licenses</span> + <span className="fb-num">packages</span>, jamais édités ici
              </span>
            </div>
            <div style={{ display: 'flex', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
              {data.packages.map((p) => (
                <span key={p.code} style={{ ...modStyle, opacity: p.granted ? 1 : 0.5, borderStyle: p.granted ? 'solid' : 'dashed' }}>
                  <span style={{ font: 'var(--type-label)' }}>{p.name}</span>
                  {p.granted ? (
                    <Badge tone={p.status === 'trialing' ? 'info' : 'success'} size="sm">
                      <span className="fb-num">{p.status ?? 'core'}</span>
                    </Badge>
                  ) : (
                    <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>non souscrit</span>
                  )}
                </span>
              ))}
            </div>
          </div>
        </Card>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
          <Card padding="md" title="Chaîne de résolution" subtitle={selected}>
            {current ? (
              <div>
                {current.chain.map((link, i) => {
                  const wins = link.layer === current.from;
                  return (
                    <div key={link.layer} style={{ ...linkStyle, opacity: wins ? 1 : 0.45 }}>
                      <span style={{ ...stepStyle, background: wins ? 'var(--green-500)' : 'var(--slate-100)', color: wins ? '#fff' : 'var(--slate-500)' }}>
                        <span className="fb-num">{i + 1}</span>
                      </span>
                      <span style={{ font: 'var(--type-caption)' }}>{LAYER_LABEL[link.layer]}</span>
                      <span
                        className="fb-num"
                        style={{
                          marginInlineStart: 'auto',
                          font: 'var(--type-data)',
                          color: 'var(--text-secondary)',
                          textDecoration: wins ? 'none' : 'line-through',
                        }}
                      >
                        {display(link.value)}
                      </span>
                    </div>
                  );
                })}
                <div style={infoStyle}>
                  <Icon name="info" size={14} style={{ marginTop: 1, flex: 'none' }} />
                  <span style={{ font: 'var(--type-caption)' }}>
                    Une ligne absente = héritage. « Réinitialiser » <strong>supprime</strong> la surcharge, il ne stocke pas une valeur vide.
                  </span>
                </div>
              </div>
            ) : null}
          </Card>

          <Card padding="md" title="Publication">
            <Row label="Version servie">
              <span className="fb-num">v{data.publishedVersion}</span>
              {data.publishedAt ? <span style={{ color: 'var(--text-tertiary)' }}> · {data.publishedAt.slice(0, 10)}</span> : null}
            </Row>
            <Row label="Brouillon en attente">{dirty ? 'oui' : 'non'}</Row>
            <Row label="Erreurs de validation">
              <span className="fb-num" style={{ color: invalid ? 'var(--text-danger)' : undefined }}>{invalid}</span>
            </Row>
            <Button
              variant="primary"
              fullWidth
              iconLeft="upload"
              disabled={busy || invalid > 0 || !dirty}
              loading={busy}
              onClick={publishDraft}
              style={{ marginTop: 'var(--space-3)' }}
            >
              {invalid > 0
                ? `Publier v${data.publishedVersion + 1} — corriger l’erreur d’abord`
                : `Publier v${data.publishedVersion + 1}`}
            </Button>
            {data.fixture && (
              <div style={{ ...infoStyle, marginTop: 'var(--space-3)' }}>
                <Icon name="triangle-alert" size={14} style={{ marginTop: 1, flex: 'none' }} />
                <span style={{ font: 'var(--type-caption)' }}>
                  Licences et packages viennent des fixtures — <span className="fb-num">BILLING_SERVICE_URL</span> n’est pas configuré.
                </span>
              </div>
            )}
          </Card>
        </div>
      </div>

      {toast && (
        <div style={{ position: 'fixed', insetInlineEnd: 24, bottom: 24, zIndex: 80 }}>
          <Toast {...toast} onDismiss={() => setToast(null)} />
        </div>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ one row ---- */

function VariableRow({
  variable,
  resolution,
  error,
  selected,
  busy,
  onSelect,
  onChange,
}: {
  variable: Variable;
  resolution: Resolution | undefined;
  error: string | undefined;
  selected: boolean;
  busy: boolean;
  onSelect: () => void;
  onChange: (value: ConfigValue) => void;
}) {
  const overridden = resolution?.from === 'tenant';
  const value = resolution?.value ?? null;

  return (
    <div
      onClick={onSelect}
      style={{
        display: 'grid',
        gridTemplateColumns: 'minmax(0, 1fr) 300px 150px',
        alignItems: error ? 'start' : 'center',
        gap: 'var(--space-4)',
        padding: 'var(--space-3) var(--space-5)',
        borderTop: '1px solid var(--border-subtle)',
        background: selected ? 'var(--plum-50)' : undefined,
        boxShadow: selected ? 'inset 3px 0 0 var(--brand)' : undefined,
        cursor: 'pointer',
      }}
    >
      <div>
        <div style={{ font: 'var(--type-label)' }}>{variable.label}</div>
        <div className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)', marginTop: 2 }}>
          {variable.key}
          {variable.type === 'number' && variable.min != null ? ` · number · min ${variable.min}` : ` · ${variable.type}`}
        </div>
        {variable.hint && !error ? (
          <div style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)', marginTop: 3 }}>{variable.hint}</div>
        ) : null}
      </div>

      <div>
        <Control variable={variable} value={value} inherited={!overridden} busy={busy} error={error} onChange={onChange} />
        {error ? (
          <div style={{ display: 'flex', gap: 6, alignItems: 'flex-start', marginTop: 6, color: 'var(--text-danger)' }}>
            <Icon name="circle-alert" size={13} style={{ marginTop: 2, flex: 'none' }} />
            <span style={{ font: 'var(--type-caption)' }}>{error}</span>
          </div>
        ) : null}
      </div>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: 'var(--space-2)' }}>
        <Badge tone={overridden ? 'brand' : 'neutral'} size="sm">{overridden ? 'Surchargé' : 'Hérité'}</Badge>
        {overridden && (
          <button
            type="button"
            disabled={busy}
            onClick={(e) => {
              e.stopPropagation();
              onChange(null);
            }}
            style={resetStyle}
          >
            Réinitialiser
          </button>
        )}
      </div>
    </div>
  );
}

/** One control per declared type — this switch is the whole "generic form" trick. */
function Control({
  variable,
  value,
  inherited,
  busy,
  error,
  onChange,
}: {
  variable: Variable;
  value: ConfigValue;
  inherited: boolean;
  busy: boolean;
  error: string | undefined;
  onChange: (value: ConfigValue) => void;
}) {
  const stop = (e: React.MouseEvent) => e.stopPropagation();

  switch (variable.type) {
    case 'bool':
      return (
        <div onClick={stop} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
          <Switch size="sm" checked={value === true} disabled={busy} onChange={(checked) => onChange(checked)} />
          <span style={{ font: 'var(--type-body-sm)', color: inherited ? 'var(--text-tertiary)' : undefined }}>
            {value === true ? 'Activé' : 'Désactivé'}
            {inherited ? ' — défaut' : ''}
          </span>
        </div>
      );

    case 'select':
      return (
        <div onClick={stop}>
          <Select
            size="sm"
            options={variable.options}
            value={typeof value === 'string' ? value : ''}
            disabled={busy}
            error={error}
            onChange={(e) => onChange(e.target.value)}
          />
        </div>
      );

    case 'source':
      return (
        <div onClick={stop}>
          <Select
            size="sm"
            options={variable.allowed.map((key) => ({ value: key, label: key }))}
            value={typeof value === 'string' ? value : ''}
            disabled={busy}
            error={error}
            onChange={(e) => onChange(e.target.value)}
          />
        </div>
      );

    case 'number':
      return (
        <div onClick={stop}>
          <Input
            size="sm"
            type="number"
            defaultValue={typeof value === 'number' ? value : ''}
            suffix={variable.unit}
            disabled={busy}
            error={error}
            onBlur={(e) => {
              const next = Number(e.target.value);
              if (Number.isFinite(next) && next !== value) onChange(next);
            }}
          />
        </div>
      );

    case 'color':
      return (
        <div onClick={stop} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
          <span
            aria-hidden
            style={{
              width: 20, height: 20, flex: 'none', borderRadius: 'var(--radius-xs)',
              border: '1px solid var(--alpha-ink-16)',
              background: typeof value === 'string' ? value : 'transparent',
            }}
          />
          <Input
            size="sm"
            defaultValue={typeof value === 'string' ? value : ''}
            disabled={busy}
            error={error}
            wrapperStyle={{ flex: 1 }}
            onBlur={(e) => {
              if (e.target.value && e.target.value !== value) onChange(e.target.value);
            }}
          />
        </div>
      );

    case 'list':
      return (
        <div onClick={stop} style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {(Array.isArray(value) ? value : []).map((item) => (
            <span key={item} style={tagStyle}>
              {item}
              <button
                type="button"
                disabled={busy}
                aria-label={`Retirer ${item}`}
                onClick={() => onChange((Array.isArray(value) ? value : []).filter((x) => x !== item))}
                style={{ border: 0, background: 'transparent', cursor: 'pointer', color: 'var(--plum-400)', padding: 0 }}
              >
                <Icon name="x" size={11} />
              </button>
            </span>
          ))}
          <AddToList current={Array.isArray(value) ? value : []} busy={busy} onChange={onChange} />
        </div>
      );

    case 'file':
    case 'text':
    default:
      return (
        <div onClick={stop}>
          <Input
            size="sm"
            defaultValue={typeof value === 'string' ? value : ''}
            placeholder={inherited ? 'hérité' : undefined}
            disabled={busy}
            error={error}
            onBlur={(e) => {
              if (e.target.value !== (typeof value === 'string' ? value : '')) onChange(e.target.value);
            }}
          />
        </div>
      );
  }
}

function AddToList({
  current,
  busy,
  onChange,
}: {
  current: string[];
  busy: boolean;
  onChange: (value: ConfigValue) => void;
}) {
  const [open, setOpen] = React.useState(false);
  const [draft, setDraft] = React.useState('');

  if (!open) {
    return (
      <button type="button" disabled={busy} onClick={() => setOpen(true)} style={{ ...tagStyle, borderStyle: 'dashed', cursor: 'pointer' }}>
        <Icon name="plus" size={11} />
        Ajouter
      </button>
    );
  }
  return (
    <Input
      size="sm"
      autoFocus
      value={draft}
      disabled={busy}
      placeholder="clé du module"
      onChange={(e) => setDraft(e.target.value)}
      onBlur={() => {
        if (draft.trim()) onChange([...current, draft.trim()]);
        setDraft('');
        setOpen(false);
      }}
      onKeyDown={(e) => {
        if (e.key === 'Enter') e.currentTarget.blur();
        if (e.key === 'Escape') {
          setDraft('');
          setOpen(false);
        }
      }}
      wrapperStyle={{ width: 150 }}
    />
  );
}

/* ------------------------------------------------------------------- bits ---- */

const LAYER_LABEL: Record<Resolution['from'], string> = {
  tenant: 'Tenant',
  global: 'Défaut global',
  registry: 'Défaut du registre',
};

function display(value: ConfigValue): string {
  if (value === null) return '—';
  if (Array.isArray(value)) return value.length ? value.join(', ') : '[]';
  return String(value);
}

function groupVariables(variables: Variable[]): { group: string; variables: Variable[] }[] {
  const groups = new Map<string, Variable[]>();
  for (const v of variables) {
    const g = v.key.split('.')[0] ?? '';
    const bucket = groups.get(g);
    if (bucket) bucket.push(v);
    else groups.set(g, [v]);
  }
  return [...groups.entries()].map(([group, list]) => ({ group, variables: list }));
}

function Chip({ icon, children }: { icon: string; children: React.ReactNode }) {
  return (
    <span
      className="fb-num"
      style={{
        display: 'inline-flex', alignItems: 'center', gap: 5, padding: '2px 8px',
        borderRadius: 'var(--radius-sm)', background: 'var(--slate-100)',
        color: 'var(--text-secondary)', font: 'var(--type-data)',
      }}
    >
      <Icon name={icon} size={12} />
      {children}
    </span>
  );
}

function Stat({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div style={{ textAlign: 'end' }}>
      <div style={{ font: 'var(--type-caption)', letterSpacing: 'var(--tracking-caps)', textTransform: 'uppercase', color: 'var(--text-tertiary)', marginBottom: 4 }}>
        {label}
      </div>
      <div style={{ font: 'var(--type-title)' }}>{children}</div>
    </div>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', padding: '6px 0' }}>
      <span style={{ font: 'var(--type-caption)', color: 'var(--text-secondary)' }}>{label}</span>
      <span className="fb-num" style={{ marginInlineStart: 'auto', font: 'var(--type-data)' }}>{children}</span>
    </div>
  );
}

const noticeStyle: React.CSSProperties = {
  display: 'flex', alignItems: 'flex-start', gap: 'var(--space-3)',
  padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-card)',
  background: 'var(--surface-warning-subtle)', border: '1px solid var(--amber-500)',
  color: 'var(--text-warning)',
};

const groupHeaderStyle: React.CSSProperties = {
  display: 'flex', alignItems: 'center', gap: 'var(--space-2)',
  padding: 'var(--space-2) var(--space-5)', background: 'var(--slate-25)',
  borderTop: '1px solid var(--border-subtle)',
};

const lockedStyle: React.CSSProperties = {
  padding: 'var(--space-4) var(--space-5)',
  background: 'var(--slate-25)',
  borderTop: '1px solid var(--border-default)',
};

const modStyle: React.CSSProperties = {
  display: 'inline-flex', alignItems: 'center', gap: 'var(--space-2)',
  padding: '5px 10px', borderRadius: 'var(--radius-md)',
  background: 'var(--surface-card)', border: '1px solid var(--border-default)',
};

const linkStyle: React.CSSProperties = {
  display: 'flex', alignItems: 'center', gap: 'var(--space-3)',
  padding: '8px 0', borderBottom: '1px dashed var(--border-default)',
};

const stepStyle: React.CSSProperties = {
  width: 22, height: 22, flex: 'none', borderRadius: 'var(--radius-circle)',
  display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
  font: 'var(--type-caption)',
};

const infoStyle: React.CSSProperties = {
  display: 'flex', gap: 'var(--space-2)', marginTop: 'var(--space-3)',
  padding: 'var(--space-2) var(--space-3)', borderRadius: 'var(--radius-md)',
  background: 'var(--surface-info-subtle)', color: 'var(--blue-700)',
};

const tagStyle: React.CSSProperties = {
  display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 8px',
  borderRadius: 'var(--radius-chip)', background: 'var(--plum-50)',
  border: '1px solid var(--plum-200)', color: 'var(--plum-700)',
  font: 'var(--type-caption)',
};

const resetStyle: React.CSSProperties = {
  border: 0, background: 'transparent', padding: 0, cursor: 'pointer',
  font: 'var(--type-caption)', color: 'var(--text-link)',
  textDecoration: 'underline', textUnderlineOffset: 2,
};
