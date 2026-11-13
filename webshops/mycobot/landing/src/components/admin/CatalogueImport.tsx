'use client';

import React from 'react';
import { useRouter } from 'next/navigation';
import { Badge, Button, Checkbox, Dialog, Icon, Input, Select, Toast } from '@/design-system';
import type { FilterOp } from '@/lib/data/catalogue';

/**
 * The introspection wizard.
 *
 * It reads information_schema and writes a *proposal* — never a route. The order of
 * the steps is the argument: the view warning comes before the form, columns are
 * opt-in rather than opt-out, and the tenant column gates the last step, because a
 * multi-tenant read without a server-side scope is the one mistake that leaks one
 * customer's rows to another.
 */

interface Source {
  name: string;
  kind: 'table' | 'view';
  rows: number;
  columns: number;
}

interface Column {
  name: string;
  dataType: string;
  columnType: string;
  nullable: boolean;
  indexedHint: boolean;
  tenantCandidate: boolean;
  sensitive: string | null;
  suggestedFilters: FilterOp[];
}

type Step = 'source' | 'columns' | 'tenant' | 'review';

const STEPS: { id: Step; label: string }[] = [
  { id: 'source', label: 'Source' },
  { id: 'columns', label: 'Colonnes & filtres' },
  { id: 'tenant', label: 'Portée tenant' },
  { id: 'review', label: 'Revue' },
];

export function CatalogueImport() {
  const router = useRouter();
  const [open, setOpen] = React.useState(false);
  const [step, setStep] = React.useState<Step>('source');
  const [busy, setBusy] = React.useState(false);
  const [toast, setToast] = React.useState<{ tone: 'success' | 'danger' | 'info'; title: string; message: string } | null>(null);

  const [sources, setSources] = React.useState<Source[] | null>(null);
  const [sourceError, setSourceError] = React.useState<string | null>(null);
  const [search, setSearch] = React.useState('');
  const [picked, setPicked] = React.useState<Source | null>(null);

  const [columns, setColumns] = React.useState<Column[]>([]);
  const [selected, setSelected] = React.useState<Set<string>>(new Set());
  const [filters, setFilters] = React.useState<Record<string, FilterOp[]>>({});
  const [orderable, setOrderable] = React.useState<Set<string>>(new Set());
  const [createViewSql, setCreateViewSql] = React.useState<string | null>(null);
  const [suggestedViewName, setSuggestedViewName] = React.useState('');

  const [key, setKey] = React.useState('');
  const [tenantColumn, setTenantColumn] = React.useState('');
  const [maxRows, setMaxRows] = React.useState(500);
  const [generated, setGenerated] = React.useState<string | null>(null);

  const reset = () => {
    setStep('source');
    setPicked(null);
    setColumns([]);
    setSelected(new Set());
    setFilters({});
    setOrderable(new Set());
    setKey('');
    setTenantColumn('');
    setMaxRows(500);
    setGenerated(null);
    setCreateViewSql(null);
  };

  const start = async () => {
    reset();
    setOpen(true);
    setSources(null);
    setSourceError(null);
    const response = await fetch('/api/admin/catalogue/sources');
    const body = (await response.json()) as { sources?: Source[]; error?: string };
    if (!response.ok) {
      setSourceError(body.error ?? 'Introspection impossible.');
      setSources([]);
      return;
    }
    setSources(body.sources ?? []);
  };

  const pick = async (source: Source) => {
    setBusy(true);
    try {
      const response = await fetch(`/api/admin/catalogue/sources/${encodeURIComponent(source.name)}`);
      const body = (await response.json()) as {
        columns?: Column[]; createViewSql?: string | null; suggestedViewName?: string; error?: string;
      };
      if (!response.ok || !body.columns) {
        setToast({ tone: 'danger', title: 'Lecture impossible', message: body.error ?? 'Colonnes indisponibles.' });
        return;
      }

      setPicked(source);
      setColumns(body.columns);
      setCreateViewSql(body.createViewSql ?? null);
      setSuggestedViewName(body.suggestedViewName ?? '');

      // Opt-in defaults: sensitive columns stay off, and so does the tenant column —
      // it is there to filter with, not to hand back.
      const tenantGuess = body.columns.find((c) => c.tenantCandidate)?.name ?? '';
      const on = body.columns.filter((c) => !c.sensitive && c.name !== tenantGuess);
      setSelected(new Set(on.map((c) => c.name)));
      setFilters(Object.fromEntries(on.filter((c) => c.suggestedFilters.length).map((c) => [c.name, c.suggestedFilters])));
      setOrderable(new Set(on.filter((c) => c.indexedHint && c.suggestedFilters.includes('gte')).map((c) => c.name)));
      setTenantColumn(tenantGuess);
      setKey(`${source.name.replace(/^tfb_/, '').split('_')[0] ?? 'data'}.${source.name.replace(/^tfb_/, '')}`);
      setStep('columns');
    } finally {
      setBusy(false);
    }
  };

  const toggleColumn = (name: string, on: boolean) => {
    setSelected((current) => {
      const next = new Set(current);
      if (on) next.add(name);
      else next.delete(name);
      return next;
    });
    if (!on) {
      setFilters((current) => {
        const next = { ...current };
        delete next[name];
        return next;
      });
      setOrderable((current) => {
        const next = new Set(current);
        next.delete(name);
        return next;
      });
    }
  };

  const submit = async () => {
    setBusy(true);
    try {
      const response = await fetch('/api/admin/catalogue/proposals', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          key,
          source: picked?.name,
          sourceKind: picked?.kind,
          columns: [...selected],
          filters,
          orderable: [...orderable],
          maxRows,
          tenantColumn,
        }),
      });
      const body = (await response.json()) as { code?: string; error?: string };
      if (!response.ok) {
        setToast({ tone: 'danger', title: 'Proposition refusée', message: body.error ?? 'Vérifiez les champs.' });
        return;
      }
      setGenerated(body.code ?? null);
      setStep('review');
      router.refresh();
    } finally {
      setBusy(false);
    }
  };

  const isTable = picked?.kind === 'table';
  const shown = (sources ?? []).filter((s) => s.name.includes(search.trim().toLowerCase()));

  return (
    <>
      <Button variant="primary" iconLeft="plus" onClick={start}>Importer depuis une table</Button>

      <Dialog
        open={open}
        size="lg"
        style={{ width: 1000, maxHeight: '86vh' }}
        title="Importer depuis une table"
        description="Produit une entrée de catalogue à relire — aucune route n’est créée."
        onClose={() => setOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>Fermer</Button>
            {step === 'columns' && (
              <Button variant="primary" iconRight="arrow-right" disabled={selected.size === 0} onClick={() => setStep('tenant')}>
                Portée tenant
              </Button>
            )}
            {step === 'tenant' && (
              <Button variant="primary" iconLeft="save" loading={busy} disabled={!tenantColumn || !key} onClick={submit}>
                Créer la proposition
              </Button>
            )}
          </>
        }
      >
        <div style={{ maxHeight: '58vh', overflowY: 'auto', paddingInlineEnd: 4 }}>
          <Stepper current={step} />

          {step === 'source' && (
            <div>
              {sourceError && (
                <Notice tone="danger" icon="circle-alert">
                  {sourceError} L’introspection lit <span className="fb-num">information_schema</span> sur la connexion
                  courante — vérifiez <span className="fb-num">DATABASE_URL</span>.
                </Notice>
              )}
              <Input
                size="sm"
                iconLeft="search"
                placeholder="Filtrer une table ou une vue…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                wrapperStyle={{ marginBottom: 'var(--space-3)' }}
              />
              {sources === null ? (
                <p style={{ font: 'var(--type-body-sm)', color: 'var(--text-tertiary)' }}>Lecture du schéma…</p>
              ) : shown.length === 0 ? (
                <p style={{ font: 'var(--type-body-sm)', color: 'var(--text-tertiary)' }}>Aucune source.</p>
              ) : (
                <div style={{ border: '1px solid var(--border-default)', borderRadius: 'var(--radius-md)', overflow: 'hidden' }}>
                  {shown.map((s) => (
                    <button
                      key={s.name}
                      type="button"
                      disabled={busy}
                      onClick={() => pick(s)}
                      style={rowButtonStyle}
                    >
                      <Icon name={s.kind === 'view' ? 'eye' : 'layers'} size={14} color="var(--text-tertiary)" />
                      <span className="fb-num" style={{ font: 'var(--type-data)', flex: 1, textAlign: 'start' }}>{s.name}</span>
                      <Badge tone={s.kind === 'view' ? 'success' : 'neutral'} size="sm">{s.kind}</Badge>
                      <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>
                        {s.columns} col. · ~{s.rows.toLocaleString('fr-FR')} lignes
                      </span>
                      <Icon name="chevron-right" size={13} className="fb-flip" color="var(--text-tertiary)" />
                    </button>
                  ))}
                </div>
              )}
            </div>
          )}

          {step === 'columns' && picked && (
            <div>
              {isTable && (
                <Notice tone="warning" icon="triangle-alert">
                  <strong>C’est une table, pas une vue.</strong> Exposer <span className="fb-num">{picked.name}</span> en
                  direct fige ses noms de colonnes dans votre contrat public : vous ne pourrez plus les renommer sans
                  casser les PWA installées. <span className="fb-num">CREATE VIEW</span> ne modifie aucune table.
                  {createViewSql && (
                    <details style={{ marginTop: 'var(--space-2)' }}>
                      <summary style={{ cursor: 'pointer', font: 'var(--type-label)' }}>
                        Voir le <span className="fb-num">CREATE VIEW {suggestedViewName}</span> à exécuter
                      </summary>
                      <pre style={preStyle}>{createViewSql}</pre>
                      <span style={{ font: 'var(--type-caption)' }}>
                        À exécuter vous-même : cette console n’émet jamais de DDL.
                      </span>
                    </details>
                  )}
                </Notice>
              )}

              <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', marginBottom: 'var(--space-2)' }}>
                <span style={{ font: 'var(--type-title)' }}>Colonnes exposées</span>
                <Badge tone="brand" size="sm">{selected.size} / {columns.length}</Badge>
                <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)', marginInlineStart: 'auto' }}>
                  le reste ne sortira jamais
                </span>
              </div>

              <div style={{ border: '1px solid var(--border-default)', borderRadius: 'var(--radius-md)', overflow: 'hidden' }}>
                {columns.map((c) => {
                  const on = selected.has(c.name);
                  return (
                    <div key={c.name} style={{ ...columnRowStyle, background: on ? undefined : 'var(--slate-25)' }}>
                      <Checkbox checked={on} disabled={c.name === tenantColumn} onChange={(next) => toggleColumn(c.name, next)} />
                      <div style={{ minWidth: 0 }}>
                        <span className="fb-num" style={{ font: 'var(--type-data)' }}>{c.name}</span>{' '}
                        <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>
                          {c.columnType}
                        </span>
                      </div>
                      <span style={{ font: 'var(--type-caption)', color: c.sensitive ? 'var(--text-danger)' : 'var(--text-tertiary)' }}>
                        {c.sensitive ? (
                          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                            <Icon name="lock" size={12} />{c.sensitive}
                          </span>
                        ) : c.indexedHint ? 'indexée' : '—'}
                      </span>
                      <Select
                        size="sm"
                        disabled={!on}
                        value={(filters[c.name] ?? []).join(',')}
                        options={filterOptions(c)}
                        onChange={(e) => {
                          const value = e.target.value;
                          setFilters((current) => {
                            const next = { ...current };
                            if (!value) delete next[c.name];
                            else next[c.name] = value.split(',') as FilterOp[];
                            return next;
                          });
                        }}
                      />
                      <Checkbox
                        label="tri"
                        checked={orderable.has(c.name)}
                        disabled={!on || !c.indexedHint}
                        onChange={(next) =>
                          setOrderable((current) => {
                            const set = new Set(current);
                            if (next) set.add(c.name);
                            else set.delete(c.name);
                            return set;
                          })
                        }
                      />
                    </div>
                  );
                })}
              </div>
              <p style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)', marginTop: 'var(--space-2)' }}>
                Le tri n’est proposé que sur les colonnes indexées — un tri libre sur{' '}
                {picked.rows.toLocaleString('fr-FR')} lignes est un déni de service que le client déclenche sans le vouloir.
                Sur une vue, MySQL ne tient pas de statistiques : « indexée » est une indication par nom, pas une garantie.
              </p>
            </div>
          )}

          {step === 'tenant' && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
              <Notice tone="info" icon="lock">
                La colonne de tenant est injectée côté serveur à chaque requête et n’est jamais lue du paramètre d’URL.
                Sans elle, la ressource ne peut pas être proposée.
              </Notice>
              <Select
                label="Colonne portant le tenant"
                options={columns.map((c) => ({ value: c.name, label: `${c.name} — ${c.columnType}` }))}
                value={tenantColumn}
                onChange={(e) => {
                  setTenantColumn(e.target.value);
                  toggleColumn(e.target.value, false);
                }}
                hint="Elle sert à filtrer, pas à sortir : elle est retirée des colonnes exposées."
              />
              <Input label="Clé du catalogue" value={key} onChange={(e) => setKey(e.target.value)} hint="Format « groupe.ressource ». Devient /api/data/<clé>." />
              <Input
                label="Plafond de lignes"
                type="number"
                value={maxRows}
                onChange={(e) => setMaxRows(Number(e.target.value))}
                hint="Obligatoire, 1 à 1000. Jamais optionnel."
              />
            </div>
          )}

          {step === 'review' && generated && (
            <div>
              <Notice tone="info" icon="circle-check">
                Proposition enregistrée. <strong>Elle n’est servie par aucune route.</strong> Collez cette entrée dans{' '}
                <span className="fb-num">src/lib/data/catalogue.ts</span> et passez-la en revue pour l’activer.
              </Notice>
              <pre style={preStyle}>{generated}</pre>
              <Notice tone="info" icon="lock">
                Aucune écriture n’est générée. <strong>POST</strong> et <strong>PUT</strong> restent des endpoints écrits à la main.
              </Notice>
            </div>
          )}
        </div>
      </Dialog>

      {toast && (
        <div style={{ position: 'fixed', insetInlineEnd: 24, bottom: 24, zIndex: 80 }}>
          <Toast {...toast} onDismiss={() => setToast(null)} />
        </div>
      )}
    </>
  );
}

/**
 * Proposals waiting to be reviewed. Rendered hatched and inert: they carry no
 * contract version and no consumer, because nothing serves them.
 */
export function CatalogueProposals({
  proposals,
}: {
  proposals: { key: string; source: string; sourceKind: string; createdAt: string; createdBy: string; code: string }[];
}) {
  const router = useRouter();
  const [busy, setBusy] = React.useState<string | null>(null);

  if (proposals.length === 0) return null;

  const discard = async (key: string) => {
    setBusy(key);
    try {
      await fetch(`/api/admin/catalogue/proposals?key=${encodeURIComponent(key)}`, { method: 'DELETE' });
      router.refresh();
    } finally {
      setBusy(null);
    }
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
      {proposals.map((p) => (
        <div
          key={p.key}
          style={{
            border: '1px dashed var(--border-strong)', borderRadius: 'var(--radius-card)',
            padding: 'var(--space-4)',
            background: 'repeating-linear-gradient(135deg, var(--slate-25), var(--slate-25) 9px, #fff 9px, #fff 18px)',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', marginBottom: 'var(--space-2)' }}>
            <span className="fb-num" style={{ font: 'var(--type-title)' }}>{p.key}</span>
            <Badge tone="neutral" size="sm">Proposition</Badge>
            <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>
              {p.source} · {p.sourceKind} · {p.createdBy} · {p.createdAt.slice(0, 10)}
            </span>
            <Button
              variant="ghost"
              size="sm"
              iconLeft="trash-2"
              loading={busy === p.key}
              onClick={() => discard(p.key)}
              style={{ marginInlineStart: 'auto' }}
            >
              Écarter
            </Button>
          </div>
          <pre style={preStyle}>{p.code}</pre>
          <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>
            Servie par aucune route. À coller dans <span className="fb-num">src/lib/data/catalogue.ts</span> et à passer en revue.
          </span>
        </div>
      ))}
    </div>
  );
}

function filterOptions(c: Column) {
  const options = [{ value: '', label: 'aucun filtre' }];
  if (c.suggestedFilters.length) options.push({ value: c.suggestedFilters.join(','), label: c.suggestedFilters.join(' · ') });
  if (c.dataType !== 'date' && c.dataType !== 'datetime' && c.dataType !== 'timestamp') {
    options.push({ value: 'eq', label: 'eq' }, { value: 'eq,in', label: 'eq · in' });
  } else {
    options.push({ value: 'gte', label: 'gte' }, { value: 'gte,lte', label: 'gte · lte' });
  }
  // De-duplicate: the suggestion often equals one of the generic pairs.
  return options.filter((o, i) => options.findIndex((x) => x.value === o.value) === i);
}

function Stepper({ current }: { current: Step }) {
  const index = STEPS.findIndex((s) => s.id === current);
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-4)', marginBottom: 'var(--space-4)' }}>
      {STEPS.map((s, i) => {
        const done = i < index;
        const now = i === index;
        return (
          <span key={s.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-2)' }}>
            <span
              style={{
                width: 21, height: 21, flex: 'none', borderRadius: 'var(--radius-circle)',
                display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                background: done ? 'var(--green-500)' : now ? 'var(--brand)' : 'var(--slate-200)',
                color: done || now ? '#fff' : 'var(--slate-600)', font: 'var(--type-caption)',
              }}
            >
              {done ? <Icon name="check" size={12} /> : <span className="fb-num">{i + 1}</span>}
            </span>
            <span style={{ font: now ? 'var(--type-label)' : 'var(--type-caption)', color: now ? undefined : 'var(--text-tertiary)' }}>
              {s.label}
            </span>
          </span>
        );
      })}
    </div>
  );
}

function Notice({ tone, icon, children }: { tone: 'warning' | 'info' | 'danger'; icon: string; children: React.ReactNode }) {
  const palette = {
    warning: { bg: 'var(--surface-warning-subtle)', border: 'var(--amber-500)', fg: 'var(--text-warning)' },
    info: { bg: 'var(--surface-info-subtle)', border: 'var(--blue-500)', fg: 'var(--blue-700)' },
    danger: { bg: 'var(--surface-danger-subtle)', border: 'var(--red-500)', fg: 'var(--text-danger)' },
  }[tone];
  return (
    <div
      role="status"
      style={{
        display: 'flex', alignItems: 'flex-start', gap: 'var(--space-3)',
        padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-card)',
        background: palette.bg, border: `1px solid ${palette.border}`, color: palette.fg,
        marginBottom: 'var(--space-4)',
      }}
    >
      <Icon name={icon} size={16} style={{ marginTop: 2, flex: 'none' }} />
      <div style={{ font: 'var(--type-body-sm)' }}>{children}</div>
    </div>
  );
}

const rowButtonStyle: React.CSSProperties = {
  display: 'flex', alignItems: 'center', gap: 'var(--space-3)', width: '100%',
  padding: 'var(--space-2) var(--space-4)', border: 0, borderTop: '1px solid var(--border-subtle)',
  background: 'transparent', cursor: 'pointer', color: 'inherit',
};

const columnRowStyle: React.CSSProperties = {
  display: 'grid', gridTemplateColumns: '26px minmax(0, 1fr) 130px 150px 80px',
  alignItems: 'center', gap: 'var(--space-3)',
  padding: 'var(--space-2) var(--space-3)', borderTop: '1px solid var(--border-subtle)',
};

const preStyle: React.CSSProperties = {
  font: 'var(--type-data)', lineHeight: 1.6, color: 'var(--slate-700)',
  background: 'var(--slate-25)', border: '1px solid var(--border-subtle)',
  borderRadius: 'var(--radius-md)', padding: 'var(--space-3)',
  overflowX: 'auto', margin: 'var(--space-2) 0',
};
