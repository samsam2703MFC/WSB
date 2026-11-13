'use client';

import React from 'react';
import { useRouter } from 'next/navigation';
import {
  Badge, Button, Card, DataTable, Dialog, Icon, IconButton, Input, Select, StatTile, Tabs, Toast, Tooltip,
  type DataTableSort,
} from '@/design-system';
import {
  euro, LICENSE_STATUSES, INVOICE_STATUSES,
  type BillingSnapshot, type License, type Tenant,
} from '@/lib/billing/types';
import { LicenseStatusBadge, InvoiceStatusBadge, SourceNote } from './shared';

type ToastState = { tone: 'info' | 'success' | 'warning' | 'danger'; title: string; message: string } | null;

/** Small helper so every table shares one toast slot. */
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

function contains(haystack: string, needle: string) {
  return haystack.toLowerCase().includes(needle.toLowerCase());
}

/* --------------------------------------------------------------- Tenants ---- */

export function TenantsTable({ tenants }: { tenants: Tenant[] }) {
  const router = useRouter();
  const [q, setQ] = React.useState('');
  const rows = tenants.filter((t) => contains(`${t.name}${t.slug}${t.admin_email}`, q));

  return (
    <DataTable<Tenant & Record<string, unknown>>
      style={{ maxWidth: 1240 }}
      columns={[
        {
          key: 'name', label: 'Tenant', sortable: true,
          render: (r) => (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 1 }}>
              <span style={{ font: 'var(--weight-medium) var(--text-base)/1.3 var(--font-sans)' }}>{r.name}</span>
              <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{r.slug} · {r.id}</span>
            </div>
          ),
        },
        { key: 'admin_email', label: 'Contact', render: (r) => <span style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>{r.admin_email}</span> },
        { key: 'internal_api_url', label: 'API interne', render: (r) => <span className="fb-num" style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>{r.internal_api_url}</span> },
        { key: 'stores', label: 'Magasins', align: 'right', sortable: true, render: (r) => <span className="fb-num">{r.stores}</span> },
        { key: 'licenses', label: 'Licences', align: 'right', render: (r) => <span className="fb-num">{r.licenses}</span> },
        {
          key: 'stripe_customer_id', label: 'Stripe',
          render: (r) => (r.stripe_customer_id
            ? <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-secondary)' }}>{r.stripe_customer_id}</span>
            : <Badge tone="warning" size="sm">à créer</Badge>),
        },
        {
          key: 'onboarded_at', label: 'Onboardé', align: 'right',
          render: (r) => (r.onboarded_at
            ? <span className="fb-num" style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>{r.onboarded_at}</span>
            : <Badge tone="info" size="sm">en attente</Badge>),
        },
        {
          key: 'actions', label: '', align: 'right', width: 72,
          render: (r) => <IconButton icon="chevron-right" label="Ouvrir" size="sm" onClick={() => router.push(`/admin/tenants/${encodeURIComponent(r.slug)}`)} />,
        },
      ]}
      rows={rows as (Tenant & Record<string, unknown>)[]}
      keyField="slug"
      total={tenants.length}
      searchValue={q}
      onSearch={setQ}
      searchPlaceholder="Rechercher un tenant"
      onRowClick={(r) => router.push(`/admin/tenants/${encodeURIComponent(r.slug)}`)}
    />
  );
}

/* -------------------------------------------------------------- Licences ---- */

export function LicensesTable({ licenses, fixture }: { licenses: License[]; fixture: boolean }) {
  const { flash, slot } = useToast();
  const [q, setQ] = React.useState('');
  const [status, setStatus] = React.useState('*');
  const [sort, setSort] = React.useState<DataTableSort>({ key: 'tenant', dir: 'asc' });
  const [blocking, setBlocking] = React.useState<License | null>(null);

  const rows = React.useMemo(() => {
    const filtered = licenses
      .filter((l) => status === '*' || l.status === status)
      .filter((l) => contains(`${l.tenant}${l.store ?? ''}${l.id}${l.package}`, q));
    return [...filtered].sort((a, b) => {
      const dir = sort.dir === 'asc' ? 1 : -1;
      const av = a[sort.key as keyof License];
      const bv = b[sort.key as keyof License];
      if (typeof av === 'number' && typeof bv === 'number') return dir * (av - bv);
      return dir * String(av ?? '').localeCompare(String(bv ?? ''));
    });
  }, [licenses, status, q, sort]);

  const block = async () => {
    if (!blocking) return;
    const target = blocking;
    setBlocking(null);
    const response = await fetch(`/api/admin/billing/licenses/${encodeURIComponent(target.id)}/block`, { method: 'POST' });
    const body = (await response.json().catch(() => ({}))) as { ok?: boolean; reason?: string };
    if (response.ok && body.ok) {
      flash({ tone: 'danger', title: 'Licence bloquée', message: 'license_events + sync_queue mis à jour.' });
    } else {
      flash({ tone: 'warning', title: 'Blocage refusé', message: body.reason ?? 'Le service de facturation a refusé la demande.' });
    }
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', maxWidth: 1240 }}>
      <DataTable<License & Record<string, unknown>>
        columns={[
          {
            key: 'tenant', label: 'Tenant / magasin', sortable: true,
            render: (r) => (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 1 }}>
                <span style={{ font: 'var(--weight-medium) var(--text-base)/1.3 var(--font-sans)' }}>{r.tenant}</span>
                <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{r.store ?? 'store_id NULL — licence tenant'}</span>
              </div>
            ),
          },
          { key: 'package', label: 'Package', sortable: true, render: (r) => <span className="fb-num" style={{ font: 'var(--type-body-sm)' }}>{r.package}</span> },
          {
            key: 'stripe_subscription_id', label: 'Subscription',
            render: (r) => (r.stripe_subscription_id
              ? <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-secondary)' }}>{r.stripe_subscription_id}</span>
              : <Badge tone="warning" size="sm">non créée</Badge>),
          },
          {
            key: 'discount_percent', label: 'Remise', align: 'right',
            render: (r) => (r.discount_percent ? <span className="fb-num">-{r.discount_percent}%</span> : <span style={{ color: 'var(--text-tertiary)' }}>—</span>),
          },
          { key: 'unit_price', label: 'Prix', align: 'right', sortable: true, render: (r) => <span className="fb-num">{euro(r.unit_price)}</span> },
          {
            key: 'current_period_end', label: 'Fin de période', align: 'right',
            render: (r) => <span className="fb-num" style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>{r.current_period_end ?? '—'}</span>,
          },
          { key: 'status', label: 'Statut', render: (r) => <LicenseStatusBadge value={r.status} /> },
          {
            key: 'actions', label: '', align: 'right', width: 104,
            render: (r) => (
              <span style={{ display: 'inline-flex', gap: 4 }}>
                <Tooltip content={fixture ? 'Indisponible sur les fixtures' : "Bloquer l'accès"}>
                  <IconButton icon="lock" label="Bloquer" size="sm" disabled={fixture} onClick={() => setBlocking(r)} />
                </Tooltip>
                <Tooltip content="Événements"><IconButton icon="clock" label="Événements" size="sm" /></Tooltip>
              </span>
            ),
          },
        ]}
        rows={rows as (License & Record<string, unknown>)[]}
        total={licenses.length}
        sort={sort}
        onSort={setSort}
        searchValue={q}
        onSearch={setQ}
        searchPlaceholder="Rechercher un tenant, un magasin ou un id"
        toolbar={
          <Select
            size="sm"
            options={[{ value: '*', label: 'tous' }, ...LICENSE_STATUSES.map((s) => ({ value: s, label: s }))]}
            value={status}
            onChange={(e) => setStatus(e.target.value)}
            wrapperStyle={{ width: 140 }}
          />
        }
      />
      <SourceNote>
        Statuts issus de <span className="fb-num">licenses.status</span> : {LICENSE_STATUSES.join(' · ')}. Une licence
        annulée expire 30 jours après <span className="fb-num">canceled_at</span>.
      </SourceNote>

      <Dialog
        open={Boolean(blocking)}
        tone="danger"
        title="Bloquer cette licence ?"
        description="L'accès du magasin est coupé immédiatement (status = blocked) et un événement interne est écrit dans license_events. Stripe continue de facturer jusqu'à l'annulation."
        onClose={() => setBlocking(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setBlocking(null)}>Annuler</Button>
            <Button variant="danger" iconLeft="lock" onClick={block}>Bloquer</Button>
          </>
        }
      />
      {slot}
    </div>
  );
}

/* -------------------------------------------------------------- Factures ---- */

export function InvoicesTable({ invoices }: { invoices: BillingSnapshot['invoices'] }) {
  const [q, setQ] = React.useState('');
  const [status, setStatus] = React.useState('*');

  const billed = invoices.reduce((s, i) => s + i.amount_due, 0);
  const collected = invoices.reduce((s, i) => s + i.amount_paid, 0);
  const open = invoices.filter((i) => i.status === 'open');
  const uncollectible = invoices.filter((i) => i.status === 'uncollectible');

  const rows = invoices
    .filter((i) => status === '*' || i.status === status)
    .filter((i) => contains(`${i.tenant}${i.stripe_invoice_id}${i.store ?? ''}`, q));

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)', maxWidth: 1240 }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 'var(--space-4)' }}>
        <StatTile label="Facturé" value={euro(billed)} icon="receipt" />
        <StatTile
          label="Encaissé" value={euro(collected)} icon="wallet"
          delta={billed > 0 ? `${((collected / billed) * 100).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} %` : undefined}
          deltaLabel="du facturé"
        />
        <StatTile label="Ouvert" value={euro(open.reduce((s, i) => s + i.amount_due, 0))} unit={`${open.length} factures`} icon="clock" />
        <StatTile
          label="Irrécouvrable"
          value={euro(uncollectible.reduce((s, i) => s + i.amount_due, 0))}
          unit={`${uncollectible.length} facture${uncollectible.length > 1 ? 's' : ''}`}
          icon="circle-alert"
        />
      </div>
      <DataTable<BillingSnapshot['invoices'][number] & Record<string, unknown>>
        columns={[
          {
            key: 'stripe_invoice_id', label: 'Facture',
            render: (r) => (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 1 }}>
                <span className="fb-num" style={{ font: 'var(--weight-medium) var(--text-sm)/1.3 var(--font-sans)' }}>{r.stripe_invoice_id}</span>
                <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{r.period}</span>
              </div>
            ),
          },
          {
            key: 'tenant', label: 'Tenant', sortable: true,
            render: (r) => (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 1 }}>
                <span style={{ font: 'var(--type-body)' }}>{r.tenant}</span>
                <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{r.store ?? '—'}</span>
              </div>
            ),
          },
          { key: 'amount_due', label: 'Dû', align: 'right', sortable: true, render: (r) => <span className="fb-num">{euro(r.amount_due)}</span> },
          {
            key: 'amount_paid', label: 'Payé', align: 'right',
            render: (r) => (
              <span className="fb-num" style={{ color: r.amount_paid >= r.amount_due && r.amount_due > 0 ? 'var(--text-success)' : 'var(--text-secondary)' }}>
                {euro(r.amount_paid)}
              </span>
            ),
          },
          { key: 'status', label: 'Statut', render: (r) => <InvoiceStatusBadge value={r.status} /> },
          {
            key: 'actions', label: '', align: 'right', width: 88,
            render: (r) => (
              <Tooltip content={r.pdf ? 'invoice_pdf_url' : 'PDF généré par Stripe en asynchrone'}>
                <IconButton icon="download" label="PDF" size="sm" disabled={!r.pdf} />
              </Tooltip>
            ),
          },
        ]}
        rows={rows as (BillingSnapshot['invoices'][number] & Record<string, unknown>)[]}
        total={invoices.length}
        searchValue={q}
        onSearch={setQ}
        searchPlaceholder="Rechercher une facture"
        toolbar={
          <Select
            size="sm"
            options={[{ value: '*', label: 'tous' }, ...INVOICE_STATUSES.map((s) => ({ value: s, label: s }))]}
            value={status}
            onChange={(e) => setStatus(e.target.value)}
            wrapperStyle={{ width: 150 }}
          />
        }
      />
    </div>
  );
}

/* ------------------------------------------------- Packages & price tiers ---- */

export function PackagesPanel({ packages, tiers }: { packages: BillingSnapshot['packages']; tiers: BillingSnapshot['tiers'] }) {
  const [selected, setSelected] = React.useState(packages[0]?.code ?? '');
  const shown = tiers.filter((t) => t.package === selected);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)', maxWidth: 1240 }}>
      <div style={{ display: 'grid', gridTemplateColumns: `repeat(${Math.min(packages.length, 3)}, 1fr)`, gap: 'var(--space-4)' }}>
        {packages.map((p) => (
          <Card
            key={p.id}
            interactive
            title={p.name}
            subtitle={p.description ?? undefined}
            padding="lg"
            onClick={() => setSelected(p.code)}
            style={{
              borderColor: selected === p.code ? 'var(--border-brand)' : undefined,
              boxShadow: selected === p.code ? 'var(--shadow-md)' : undefined,
            }}
          >
            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                <span className="fb-num" style={{ font: 'var(--type-data)', color: 'var(--text-secondary)' }}>{p.code}</span>
                {p.is_free ? <Badge tone="info" size="sm">gratuit</Badge> : <Badge tone="brand" size="sm">Stripe</Badge>}
                {!p.is_active && <Badge tone="neutral" size="sm">inactif</Badge>}
              </div>
              <div style={{ display: 'flex', gap: 'var(--space-4)', font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>
                <span className="fb-num">{p.stripe_price_id ?? 'stripe_price_id NULL'}</span>
                <span className="fb-num" style={{ marginInlineStart: 'auto' }}>{p.licenses} licences</span>
              </div>
            </div>
          </Card>
        ))}
      </div>

      <Card
        padding="none"
        title={`Paliers tarifaires — ${selected}`}
        subtitle="pricing_tiers · le palier applicable dépend du nombre de licences du tenant"
        footer="valid_to NULL = palier en vigueur. Un nouveau palier n'affecte pas les abonnements Stripe déjà créés."
      >
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead>
            <tr>
              {['Quantité', 'Prix unitaire', 'Devise', 'Valide du', 'Valide au', ''].map((h, i) => (
                <th key={h + i} style={{ ...thStyle, textAlign: i === 1 ? 'end' : 'start' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {shown.map((t) => {
              const inEffect = t.valid_to === null;
              return (
                <tr key={t.id} style={{ opacity: inEffect ? 1 : 0.6 }}>
                  <td style={tdStyle}>
                    <span className="fb-num" style={{ font: 'var(--type-body)' }}>{t.min_qty} — {t.max_qty === null ? '∞' : t.max_qty}</span>
                  </td>
                  <td style={{ ...tdStyle, textAlign: 'end' }}>
                    <span className="fb-num" style={{ fontWeight: 'var(--weight-medium)' }}>{euro(t.unit_price)}</span>
                  </td>
                  <td style={tdStyle}><span className="fb-num">{t.currency}</span></td>
                  <td style={tdStyle}><span className="fb-num" style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>{t.valid_from}</span></td>
                  <td style={tdStyle}>
                    {inEffect
                      ? <Badge tone="success" dot size="sm">en vigueur</Badge>
                      : <span className="fb-num" style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>{t.valid_to}</span>}
                  </td>
                  <td style={{ ...tdStyle, textAlign: 'end' }}>
                    <IconButton icon="pencil" label="Éditer" size="sm" />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </Card>
    </div>
  );
}

const thStyle: React.CSSProperties = {
  font: 'var(--weight-semibold) var(--text-xs)/1 var(--font-sans)',
  letterSpacing: 'var(--tracking-wide)', textTransform: 'uppercase', color: 'var(--text-tertiary)',
  padding: 'var(--space-3) var(--space-4)', background: 'var(--slate-25)',
  borderBottom: '1px solid var(--border-default)', whiteSpace: 'nowrap',
};

const tdStyle: React.CSSProperties = {
  padding: 'var(--space-3) var(--space-4)',
  borderBottom: '1px solid var(--border-subtle)',
};

/* ------------------------------------------------------------ Sync queue ---- */

export function SyncQueueTable({ rows, fixture }: { rows: BillingSnapshot['sync']; fixture: boolean }) {
  const router = useRouter();
  const { flash, slot } = useToast();

  const replay = async (queueId?: number) => {
    const response = await fetch('/api/admin/billing/sync/replay', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(queueId != null ? { queueId } : {}),
    });
    const body = (await response.json().catch(() => ({}))) as { ok?: boolean; reason?: string };
    if (response.ok && body.ok) {
      flash({ tone: 'success', title: 'Rejoué', message: 'Le payload a été renvoyé à /internal/license-sync.' });
      router.refresh();
    } else {
      flash({ tone: 'warning', title: 'Rejeu refusé', message: body.reason ?? 'Le service de facturation a refusé la demande.' });
    }
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', maxWidth: 1000 }}>
      <DataTable<BillingSnapshot['sync'][number] & Record<string, unknown>>
        searchable={false}
        columns={[
          { key: 'id', label: 'File', render: (r) => <span className="fb-num" style={{ fontWeight: 'var(--weight-medium)' }}>#{r.id}</span> },
          { key: 'license', label: 'Licence', render: (r) => <span className="fb-num" style={{ font: 'var(--type-body-sm)' }}>{r.license}</span> },
          {
            key: 'attempts', label: 'Tentatives', align: 'right',
            render: (r) => <Badge tone={r.attempts >= 3 ? 'danger' : 'warning'} size="sm"><span className="fb-num">{r.attempts}</span></Badge>,
          },
          { key: 'next_retry', label: 'Prochain essai', align: 'right', render: (r) => <span className="fb-num" style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>{r.next_retry}</span> },
          { key: 'last_error', label: 'Dernière erreur', render: (r) => <span style={{ font: 'var(--type-body-sm)', color: 'var(--text-danger)' }}>{r.last_error}</span> },
          {
            key: 'actions', label: '', align: 'right', width: 112,
            render: (r) => <Button variant="secondary" size="sm" iconLeft="play" disabled={fixture} onClick={() => replay(r.id)}>Rejouer</Button>,
          },
        ]}
        rows={rows as (BillingSnapshot['sync'][number] & Record<string, unknown>)[]}
        total={rows.length}
        emptyLabel="File vide — toutes les licences sont synchronisées."
        toolbar={<Button size="sm" iconLeft="play" disabled={fixture || rows.length === 0} onClick={() => replay()}>Tout rejouer</Button>}
      />
      <SourceNote>
        Chaque ligne est un POST vers <span className="fb-num">/internal/license-sync</span> du tenant. Le payload
        complet est conservé en JSON dans <span className="fb-num">sync_queue.payload</span>.
      </SourceNote>
      {slot}
    </div>
  );
}

/* -------------------------------------------------------- Licence events ---- */

export function EventsTable({ events }: { events: BillingSnapshot['events'] }) {
  const [q, setQ] = React.useState('');
  const rows = events.filter((e) => contains(`${e.event_type}${e.license}${e.stripe_event_id ?? ''}`, q));
  return (
    <DataTable<BillingSnapshot['events'][number] & Record<string, unknown>>
      style={{ maxWidth: 1100 }}
      searchValue={q}
      onSearch={setQ}
      searchPlaceholder="Rechercher un type d'événement"
      columns={[
        { key: 'occurred_at', label: 'Survenu le', render: (r) => <span className="fb-num" style={{ font: 'var(--type-body-sm)' }}>{r.occurred_at}</span> },
        { key: 'event_type', label: 'Type', render: (r) => <span className="fb-num" style={{ font: 'var(--type-body)', fontWeight: 'var(--weight-medium)' }}>{r.event_type}</span> },
        { key: 'license', label: 'Licence', render: (r) => <span className="fb-num" style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>{r.license}</span> },
        {
          key: 'stripe_event_id', label: 'Source',
          render: (r) => (r.stripe_event_id
            ? <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-secondary)' }}>{r.stripe_event_id}</span>
            : <Badge tone="brand" size="sm">interne</Badge>),
        },
        { key: 'actions', label: '', align: 'right', width: 96, render: () => <Button variant="ghost" size="sm" iconRight="chevron-right">Payload</Button> },
      ]}
      rows={rows as (BillingSnapshot['events'][number] & Record<string, unknown>)[]}
      total={events.length}
    />
  );
}

/* ---------------------------------------------------------- Tenant detail ---- */

export function TenantDetail({
  tenant,
  licenses,
  invoices,
  fixture,
}: {
  tenant: Tenant;
  licenses: License[];
  invoices: BillingSnapshot['invoices'];
  fixture: boolean;
}) {
  const [tab, setTab] = React.useState('licences');
  const { flash, slot } = useToast();
  const [revealKey, setRevealKey] = React.useState(false);

  const unpaid = invoices.filter((i) => i.status === 'open' || i.status === 'uncollectible');

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)', maxWidth: 1240 }}>
      <Card tone="inverse" padding="lg">
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--space-6)' }}>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)', flex: 1, minWidth: 0 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
              <h2 style={{ font: 'var(--type-heading-2)', color: 'var(--text-inverse)' }}>{tenant.name}</h2>
              <Badge tone={tenant.archived_at ? 'neutral' : 'success'} dot>{tenant.archived_at ? 'archivé' : 'actif'}</Badge>
            </div>
            <div style={{ display: 'flex', gap: 'var(--space-5)', flexWrap: 'wrap', font: 'var(--type-body-sm)', color: 'var(--text-inverse-secondary)' }}>
              <span className="fb-num">{tenant.id}</span>
              <span className="fb-num">{tenant.slug}</span>
              <span>{tenant.admin_email}</span>
              <span className="fb-num">{tenant.stripe_customer_id ?? 'stripe_customer_id : NULL'}</span>
            </div>
          </div>
          <div style={{ display: 'flex', gap: 'var(--space-3)' }}>
            <Button variant="inverse" iconLeft="eye" onClick={() => setTab('cle')}>Clé API</Button>
            <Button
              variant="inverse"
              iconRight="external-link"
              onClick={() => flash({
                tone: 'info',
                title: 'Portail Stripe',
                message: fixture ? 'Indisponible sur les fixtures.' : 'GET /api/billing/portal',
              })}
            >
              Portail Stripe
            </Button>
          </div>
        </div>
      </Card>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 'var(--space-4)' }}>
        <StatTile label="Magasins" value={tenant.stores} unit="stores" icon="store" />
        <StatTile label="Licences" value={tenant.licenses} unit="licenses" icon="lock" />
        <StatTile label="Facturé" value={euro(invoices.reduce((s, i) => s + i.amount_due, 0))} icon="receipt" />
        <StatTile label="Impayés" value={unpaid.length} unit="factures" icon="circle-alert" />
      </div>

      <Tabs
        items={[
          { value: 'licences', label: 'Licences', count: licenses.length },
          { value: 'factures', label: 'Factures', count: invoices.length },
          { value: 'cle', label: 'Accès API', icon: 'lock' },
        ]}
        value={tab}
        onChange={setTab}
      />

      {tab === 'licences' && (
        <Card padding="none" title="Licences du tenant" subtitle="uq_licenses_scope (tenant_id, package_id, store_id)">
          <div>
            {licenses.map((l) => (
              <div key={l.id} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3) var(--space-5)', borderTop: '1px solid var(--border-subtle)' }}>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 1, flex: 1, minWidth: 0 }}>
                  <span style={{ font: 'var(--weight-medium) var(--text-base)/1.3 var(--font-sans)' }}>{l.store ?? 'Licence tenant (store_id NULL)'}</span>
                  <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>
                    {l.id} · {l.package} · {l.stripe_subscription_id ?? 'pas de subscription Stripe'}
                  </span>
                </div>
                {l.discount_percent ? <Badge tone="brand" size="sm"><span className="fb-num">-{l.discount_percent}%</span></Badge> : null}
                <span className="fb-num" style={{ font: 'var(--type-body)', width: 96, textAlign: 'end' }}>{euro(l.unit_price)}</span>
                <LicenseStatusBadge value={l.status} />
              </div>
            ))}
          </div>
        </Card>
      )}

      {tab === 'factures' && (
        <Card padding="none" title="Factures" subtitle="invoices — dédupliquées par stripe_invoice_id">
          <div>
            {invoices.map((i) => (
              <div key={i.id} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3) var(--space-5)', borderTop: '1px solid var(--border-subtle)' }}>
                <Icon name="file-text" size={16} color="var(--text-tertiary)" />
                <div style={{ display: 'flex', flexDirection: 'column', gap: 1, flex: 1, minWidth: 0 }}>
                  <span className="fb-num" style={{ font: 'var(--weight-medium) var(--text-sm)/1.3 var(--font-sans)' }}>{i.stripe_invoice_id}</span>
                  <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{i.store ?? '—'} · {i.period}</span>
                </div>
                <span className="fb-num" style={{ width: 96, textAlign: 'end' }}>{euro(i.amount_due)}</span>
                <InvoiceStatusBadge value={i.status} />
                <Tooltip content={i.pdf ? 'PDF Stripe' : 'PDF non généré'}>
                  <IconButton icon="download" label="PDF" size="sm" disabled={!i.pdf} />
                </Tooltip>
              </div>
            ))}
          </div>
        </Card>
      )}

      {tab === 'cle' && (
        <Card
          title="Clé API du tenant"
          subtitle="api_key_hash (SHA-256) · api_key_encrypted (Laravel Crypt)"
          padding="lg"
          footer="La clé en clair n'est jamais stockée : seul le hash sert à l'authentification."
        >
          <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', maxWidth: 620 }}>
            <Input label="URL de l'API interne" defaultValue={tenant.internal_api_url} iconRight="external-link" readOnly />
            <Input
              label="Clé API (déchiffrée pour affichage)"
              value={revealKey ? (tenant.api_key_hint ?? 'aucune clé enregistrée') : '••••••••••••••••'}
              iconRight="eye"
              hint="Visible uniquement pour le rôle superadmin"
              readOnly
            />
            <div style={{ display: 'flex', gap: 'var(--space-3)' }}>
              <Button variant="secondary" iconLeft="eye" onClick={() => setRevealKey((v) => !v)}>
                {revealKey ? 'Masquer' : 'Afficher'}
              </Button>
              <Button
                variant="ghost"
                iconLeft="save"
                disabled={fixture}
                onClick={() => flash({ tone: 'warning', title: 'Régénération', message: 'Action déléguée au service de facturation.' })}
              >
                Régénérer
              </Button>
            </div>
          </div>
        </Card>
      )}
      {slot}
    </div>
  );
}
