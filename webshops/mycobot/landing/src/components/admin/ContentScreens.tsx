'use client';

import React from 'react';
import { useRouter } from 'next/navigation';
import {
  Badge, Button, Card, DataTable, Icon, IconButton, Switch, Toast, Tooltip,
  type DataTableSort,
} from '@/design-system';
import { formatMoney } from '@/lib/i18n/config';

type ToastState = { tone: 'info' | 'success' | 'warning' | 'danger'; title: string; message: string } | null;

function useToast() {
  const [toast, setToast] = React.useState<ToastState>(null);
  const timer = React.useRef<number | undefined>(undefined);
  const flash = React.useCallback((next: NonNullable<ToastState>) => {
    setToast(next);
    window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => setToast(null), 5000);
  }, []);
  React.useEffect(() => () => window.clearTimeout(timer.current), []);
  const slot = toast ? (
    <div style={{ position: 'fixed', insetInlineEnd: 24, bottom: 24, zIndex: 80 }}>
      <Toast {...toast} onDismiss={() => setToast(null)} />
    </div>
  ) : null;
  return { flash, slot };
}

/* -------------------------------------------------------------- Sections ---- */

export interface SectionRow {
  id: number;
  key: string;
  type: string;
  isActive: boolean;
  sortOrder: number;
}

/** tfb_sections — order and activation. The landing renders active rows in sort_order. */
export function SectionsList({ sections }: { sections: SectionRow[] }) {
  const router = useRouter();
  const { flash, slot } = useToast();
  const [rows, setRows] = React.useState(sections);

  const toggle = async (row: SectionRow, isActive: boolean) => {
    // Optimistic: the switch is an instant-effect control, so it must not lag.
    setRows((current) => current.map((r) => (r.id === row.id ? { ...r, isActive } : r)));
    const response = await fetch(`/api/admin/sections/${row.id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ isActive }),
    });
    if (!response.ok) {
      setRows((current) => current.map((r) => (r.id === row.id ? { ...r, isActive: !isActive } : r)));
      flash({ tone: 'danger', title: 'Enregistrement impossible', message: `La section « ${row.key} » n'a pas été modifiée.` });
      return;
    }
    router.refresh();
  };

  return (
    <>
      <Card
        padding="none"
        style={{ maxWidth: 900 }}
        title="Sections de la landing"
        subtitle="Ordre et activation — tfb_sections"
        footer="Le front rend les sections actives dans l'ordre de sort_order."
      >
        <div>
          {rows.map((s) => (
            <div key={s.id} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3) var(--space-5)', borderTop: '1px solid var(--border-subtle)' }}>
              <Icon name="grip-vertical" size={15} color="var(--text-tertiary)" />
              <span className="fb-num" style={{ width: 22, font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{s.sortOrder}</span>
              <span className="fb-num" style={{ font: 'var(--weight-medium) var(--text-base)/1.3 var(--font-sans)', flex: 1 }}>{s.key}</span>
              <Badge tone="neutral" size="sm">{s.type}</Badge>
              <Switch size="sm" checked={s.isActive} onChange={(checked) => toggle(s, checked)} />
              <Tooltip content="Traductions">
                <IconButton
                  icon="languages" label="Traductions" size="sm"
                  onClick={() => router.push(`/admin/translations?entityType=section&entityId=${s.id}`)}
                />
              </Tooltip>
            </div>
          ))}
        </div>
      </Card>
      {slot}
    </>
  );
}

/* --------------------------------------------------------------- Modules ---- */

export interface ModuleRow {
  id: number;
  key: string;
  slug: string;
  name: string;
  group: string;
  repo: string | null;
  isNew: boolean;
  isActive: boolean;
  screenshotCount: number;
  updatedAt: string;
}

/** tfb_modules — the list the morning routine fills in. */
export function ModulesTable({ modules }: { modules: ModuleRow[] }) {
  const router = useRouter();
  const { flash, slot } = useToast();
  const [rows, setRows] = React.useState(modules);
  const [q, setQ] = React.useState('');
  const [sort, setSort] = React.useState<DataTableSort>({ key: 'name', dir: 'asc' });

  const toggle = async (row: ModuleRow, isActive: boolean) => {
    setRows((current) => current.map((r) => (r.id === row.id ? { ...r, isActive } : r)));
    const response = await fetch(`/api/admin/modules/${row.id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ isActive }),
    });
    if (!response.ok) {
      setRows((current) => current.map((r) => (r.id === row.id ? { ...r, isActive: !isActive } : r)));
      flash({ tone: 'danger', title: 'Enregistrement impossible', message: `Le module « ${row.key} » n'a pas été modifié.` });
      return;
    }
    router.refresh();
  };

  const shown = React.useMemo(() => {
    const filtered = rows.filter((m) => `${m.name}${m.key}${m.group}`.toLowerCase().includes(q.toLowerCase()));
    return [...filtered].sort((a, b) => {
      const dir = sort.dir === 'asc' ? 1 : -1;
      const av = a[sort.key as keyof ModuleRow];
      const bv = b[sort.key as keyof ModuleRow];
      if (typeof av === 'number' && typeof bv === 'number') return dir * (av - bv);
      return dir * String(av ?? '').localeCompare(String(bv ?? ''));
    });
  }, [rows, q, sort]);

  return (
    <>
      <DataTable<ModuleRow & Record<string, unknown>>
        style={{ maxWidth: 1240 }}
        columns={[
          {
            key: 'name', label: 'Module', sortable: true,
            render: (r) => (
              <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 28, height: 28, flex: 'none', borderRadius: 'var(--radius-sm)', background: 'var(--surface-brand-subtle)', color: 'var(--text-brand)' }}>
                  <Icon name="layers" size={14} />
                </span>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 1 }}>
                  <span style={{ font: 'var(--weight-medium) var(--text-base)/1.3 var(--font-sans)' }}>{r.name}</span>
                  <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{r.key}</span>
                </div>
              </div>
            ),
          },
          { key: 'group', label: 'Groupe', sortable: true },
          { key: 'repo', label: 'Dépôt', render: (r) => <span className="fb-num" style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>{r.repo ?? '—'}</span> },
          { key: 'screenshotCount', label: 'Screenshots', align: 'right', render: (r) => <span className="fb-num">{r.screenshotCount}</span> },
          { key: 'isNew', label: 'Nouveau', render: (r) => (r.isNew ? <Badge tone="warm" size="sm">Nouveau</Badge> : null) },
          { key: 'isActive', label: 'Actif', render: (r) => <Switch size="sm" checked={r.isActive} onChange={(checked) => toggle(r, checked)} /> },
          {
            key: 'actions', label: '', align: 'right', width: 72,
            render: (r) => (
              <Tooltip content="Éditer">
                <IconButton icon="pencil" label="Éditer" size="sm" onClick={() => router.push(`/admin/modules/${r.id}`)} />
              </Tooltip>
            ),
          },
        ]}
        rows={shown as (ModuleRow & Record<string, unknown>)[]}
        total={rows.length}
        sort={sort}
        onSort={setSort}
        searchValue={q}
        onSearch={setQ}
        searchPlaceholder="Rechercher un module"
        onRowClick={(r) => router.push(`/admin/modules/${r.id}`)}
      />
      {slot}
    </>
  );
}

/* -------------------------------------------------------------- Enseignes ---- */

export interface BrandRow {
  id: number;
  name: string;
  slug: string;
  logoPath: string | null;
  isActive: boolean;
}

export function BrandsTable({ brands }: { brands: BrandRow[] }) {
  const router = useRouter();
  const { flash, slot } = useToast();
  const [rows, setRows] = React.useState(brands);
  const fileInput = React.useRef<HTMLInputElement>(null);
  const [uploadFor, setUploadFor] = React.useState<number | null>(null);

  const toggle = async (row: BrandRow, isActive: boolean) => {
    setRows((current) => current.map((r) => (r.id === row.id ? { ...r, isActive } : r)));
    const response = await fetch(`/api/admin/brands/${row.id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ isActive }),
    });
    if (!response.ok) {
      setRows((current) => current.map((r) => (r.id === row.id ? { ...r, isActive: !isActive } : r)));
      flash({ tone: 'danger', title: 'Enregistrement impossible', message: `L'enseigne « ${row.slug} » n'a pas été modifiée.` });
    }
  };

  const pickLogo = (id: number) => { setUploadFor(id); fileInput.current?.click(); };

  const upload = async (file: File) => {
    if (uploadFor == null) return;
    const body = new FormData();
    body.append('file', file);
    body.append('category', 'brands');
    const uploaded = await fetch('/api/admin/upload', { method: 'POST', body });
    const result = (await uploaded.json().catch(() => ({}))) as { path?: string; error?: string };
    if (!uploaded.ok || !result.path) {
      flash({ tone: 'danger', title: 'Upload impossible', message: result.error ?? 'Le fichier a été refusé.' });
      return;
    }
    const patched = await fetch(`/api/admin/brands/${uploadFor}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ logoPath: result.path }),
    });
    if (!patched.ok) {
      flash({ tone: 'danger', title: 'Enregistrement impossible', message: 'Le logo a été stocké mais pas rattaché.' });
      return;
    }
    flash({ tone: 'success', title: 'Logo enregistré', message: result.path });
    router.refresh();
  };

  return (
    <>
      <DataTable<BrandRow & Record<string, unknown>>
        style={{ maxWidth: 1000 }}
        columns={[
          {
            key: 'name', label: 'Enseigne', sortable: true,
            render: (r) => (
              <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
                {r.logoPath ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={`/api/storage${r.logoPath.replace(/^\/storage/, '')}`} alt="" height={26} style={{ height: 26, width: 'auto' }} />
                ) : (
                  <span style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 34, height: 26, flex: 'none', borderRadius: 'var(--radius-xs)', border: '1px dashed var(--border-strong)', color: 'var(--text-tertiary)' }}>
                    <Icon name="image" size={13} />
                  </span>
                )}
                <div style={{ display: 'flex', flexDirection: 'column', gap: 1 }}>
                  <span style={{ font: 'var(--weight-medium) var(--text-base)/1.3 var(--font-sans)' }}>{r.name}</span>
                  <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{r.logoPath ?? 'logo_path vide'}</span>
                </div>
              </div>
            ),
          },
          { key: 'slug', label: 'Slug', render: (r) => <span className="fb-num" style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>{r.slug}</span> },
          { key: 'isActive', label: 'Actif', render: (r) => <Switch size="sm" checked={r.isActive} onChange={(checked) => toggle(r, checked)} /> },
          {
            key: 'actions', label: '', align: 'right', width: 104,
            render: (r) => (
              <span style={{ display: 'inline-flex', gap: 4 }}>
                <Tooltip content="Téléverser un logo">
                  <IconButton icon="upload" label="Logo" size="sm" onClick={() => pickLogo(r.id)} />
                </Tooltip>
                <Tooltip content="Traductions">
                  <IconButton
                    icon="languages" label="Traductions" size="sm"
                    onClick={() => router.push(`/admin/translations?entityType=brand&entityId=${r.id}`)}
                  />
                </Tooltip>
              </span>
            ),
          },
        ]}
        rows={rows as (BrandRow & Record<string, unknown>)[]}
        total={rows.length}
        searchPlaceholder="Rechercher une enseigne"
      />
      <input
        ref={fileInput}
        type="file"
        accept="image/png,image/jpeg,image/webp,image/svg+xml"
        style={{ display: 'none' }}
        onChange={(e) => {
          const file = e.target.files?.[0];
          e.target.value = '';
          if (file) upload(file);
        }}
      />
      {slot}
    </>
  );
}

/* ----------------------------------------------------------- Landing plans ---- */

export interface PlanRow {
  id: number;
  key: string;
  name: string;
  amount: number | null;
  currency: string;
  interval: string;
  stripePriceId: string | null;
  isFeatured: boolean;
  isActive: boolean;
  subscriberCount: number;
}

export function PlansTable({ plans }: { plans: PlanRow[] }) {
  const router = useRouter();
  const { flash, slot } = useToast();
  const [rows, setRows] = React.useState(plans);

  const toggle = async (row: PlanRow, isActive: boolean) => {
    setRows((current) => current.map((r) => (r.id === row.id ? { ...r, isActive } : r)));
    const response = await fetch(`/api/admin/plans/${row.id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ isActive }),
    });
    if (!response.ok) {
      setRows((current) => current.map((r) => (r.id === row.id ? { ...r, isActive: !isActive } : r)));
      flash({ tone: 'danger', title: 'Enregistrement impossible', message: `Le plan « ${row.key} » n'a pas été modifié.` });
      return;
    }
    router.refresh();
  };

  return (
    <>
      <DataTable<PlanRow & Record<string, unknown>>
        style={{ maxWidth: 1240 }}
        searchable={false}
        columns={[
          {
            key: 'key', label: 'Plan',
            render: (r) => (
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                <span style={{ font: 'var(--weight-medium) var(--text-base)/1.3 var(--font-sans)' }}>{r.name}</span>
                {r.isFeatured && <Badge tone="warm" size="sm">Recommandé</Badge>}
              </span>
            ),
          },
          {
            key: 'amount', label: 'Prix', align: 'right',
            render: (r) => <span className="fb-num">{formatMoney(r.amount, r.currency, 'fr') ?? 'Sur devis'}</span>,
          },
          { key: 'interval', label: 'Période', render: (r) => (r.interval === 'month' ? 'Mensuel' : 'Annuel') },
          {
            key: 'stripePriceId', label: 'Stripe price',
            render: (r) => (r.stripePriceId
              ? <span className="fb-num" style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>{r.stripePriceId}</span>
              : <Badge tone="warning" size="sm">non lié</Badge>),
          },
          { key: 'subscriberCount', label: 'Abonnés', align: 'right', render: (r) => <span className="fb-num">{r.subscriberCount}</span> },
          { key: 'isActive', label: 'Actif', render: (r) => <Switch size="sm" checked={r.isActive} onChange={(checked) => toggle(r, checked)} /> },
          {
            key: 'actions', label: '', align: 'right', width: 72,
            render: (r) => (
              <Tooltip content="Traductions">
                <IconButton
                  icon="languages" label="Traductions" size="sm"
                  onClick={() => router.push(`/admin/translations?entityType=plan&entityId=${r.id}`)}
                />
              </Tooltip>
            ),
          },
        ]}
        rows={rows as (PlanRow & Record<string, unknown>)[]}
        total={rows.length}
      />
      {slot}
    </>
  );
}

/* -------------------------------------------------------------- Messages ---- */

export interface MessageRow {
  id: number;
  name: string;
  email: string;
  company: string | null;
  languageCode: string | null;
  status: string;
  message: string;
  createdAt: string;
}

/** tfb_contact_messages — each message renders in its own language and direction. */
export function MessagesInbox({ messages }: { messages: MessageRow[] }) {
  const router = useRouter();
  const { flash, slot } = useToast();
  const [rows, setRows] = React.useState(messages);
  const [selectedId, setSelectedId] = React.useState<number | null>(messages[0]?.id ?? null);
  const selected = rows.find((m) => m.id === selectedId) ?? null;

  const setStatus = async (row: MessageRow, status: string) => {
    const response = await fetch(`/api/admin/contact-messages/${row.id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ status }),
    });
    if (!response.ok) {
      flash({ tone: 'danger', title: 'Enregistrement impossible', message: 'Le statut du message est inchangé.' });
      return;
    }
    setRows((current) => current.map((r) => (r.id === row.id ? { ...r, status } : r)));
    flash({ tone: 'success', title: status === 'archived' ? 'Message archivé' : 'Message marqué comme lu', message: row.email });
    router.refresh();
  };

  if (rows.length === 0) {
    return (
      <Card padding="lg" title="Boîte de réception" subtitle="tfb_contact_messages">
        <p style={{ font: 'var(--type-body)', color: 'var(--text-tertiary)' }}>Aucun message pour le moment.</p>
      </Card>
    );
  }

  return (
    <>
      <div style={{ display: 'grid', gridTemplateColumns: '380px 1fr', gap: 'var(--space-4)', alignItems: 'start', maxWidth: 1240 }}>
        <Card padding="none" title="Boîte de réception" subtitle="tfb_contact_messages">
          <div>
            {rows.map((m) => {
              const on = selectedId === m.id;
              return (
                <button
                  key={m.id}
                  type="button"
                  onClick={() => setSelectedId(m.id)}
                  style={{
                    display: 'flex', flexDirection: 'column', gap: 4, width: '100%', textAlign: 'start',
                    padding: 'var(--space-3) var(--space-5)', border: 0, cursor: 'pointer',
                    borderTop: '1px solid var(--border-subtle)',
                    background: on ? 'var(--surface-brand-subtle)' : 'transparent',
                  }}
                >
                  <span style={{ display: 'flex', alignItems: 'center', gap: 8, width: '100%' }}>
                    <span style={{ font: 'var(--weight-medium) var(--text-base)/1.3 var(--font-sans)' }}>{m.name}</span>
                    {m.status === 'new' && <Badge tone="brand" size="sm">Nouveau</Badge>}
                    {m.status === 'archived' && <Badge tone="neutral" size="sm">Archivé</Badge>}
                    <span className="fb-num" style={{ marginInlineStart: 'auto', font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>
                      {(m.languageCode ?? 'fr').toUpperCase()}
                    </span>
                  </span>
                  <span style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', width: '100%' }}>
                    {m.company ?? '—'} · {m.createdAt}
                  </span>
                </button>
              );
            })}
          </div>
        </Card>
        {selected && (
          <Card
            title={selected.name}
            subtitle={`${selected.company ?? '—'} · ${selected.email}`}
            actions={<Badge tone="neutral" size="sm">{(selected.languageCode ?? 'fr').toUpperCase()}</Badge>}
            padding="lg"
            footer={
              <div style={{ display: 'flex', gap: 'var(--space-3)' }}>
                <Button variant="secondary" size="sm" iconLeft="check" disabled={selected.status !== 'new'} onClick={() => setStatus(selected, 'read')}>
                  Marquer comme lu
                </Button>
                <Button variant="ghost" size="sm" iconLeft="trash-2" disabled={selected.status === 'archived'} onClick={() => setStatus(selected, 'archived')}>
                  Archiver
                </Button>
              </div>
            }
          >
            <p style={{
              font: 'var(--type-body-lg)', color: 'var(--text-primary)', maxWidth: '60ch',
              // The message renders in the sender's own direction and script.
              direction: selected.languageCode === 'ar' ? 'rtl' : 'ltr',
              fontFamily: selected.languageCode === 'ar' ? 'var(--font-arabic)' : undefined,
            }}>
              {selected.message}
            </p>
          </Card>
        )}
      </div>
      {slot}
    </>
  );
}
