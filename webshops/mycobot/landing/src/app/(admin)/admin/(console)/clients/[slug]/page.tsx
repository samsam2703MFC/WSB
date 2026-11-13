import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Badge, Card, Icon } from '@/design-system';
import { prisma } from '@/lib/prisma';
import { getBillingSnapshot } from '@/lib/billing/client';
import { isSlug, publicationHistory, publishedOverlay, draftOverlay } from '@/lib/config/store';
import { catalogueEntries, resource as catalogueResource } from '@/lib/data/catalogue';
import { buildProfileData } from '@/lib/admin/profile';
import { ProfileEditor } from '@/components/admin/ProfileEditor';
import { LicensesTable, InvoicesTable, EventsTable, SyncQueueTable } from '@/components/admin/BillingTables';
import { BillingFixtureNotice } from '@/components/admin/shared';

/**
 * One client, everything about them, behind one rail entry.
 *
 * Tabs rather than a single scroll, and tabs as links rather than local state: a
 * support call ends with someone pasting a URL to a colleague, and
 * ?tab=journal has to survive that.
 */

const TABS = [
  { id: 'apercu', label: 'Aperçu', icon: 'layout-dashboard' },
  { id: 'personnalisation', label: 'Personnalisation', icon: 'settings' },
  { id: 'facturation', label: 'Facturation', icon: 'receipt' },
  { id: 'donnees', label: 'Données', icon: 'file-text' },
  { id: 'journal', label: 'Journal', icon: 'clock' },
] as const;

type Tab = (typeof TABS)[number]['id'];

export default async function ClientPage({
  params,
  searchParams,
}: {
  params: Promise<{ slug: string }>;
  searchParams: Promise<{ tab?: string }>;
}) {
  const slug = (await params).slug;
  if (!isSlug(slug)) notFound();

  const requested = (await searchParams).tab;
  const tab: Tab = (TABS.find((t) => t.id === requested)?.id ?? 'apercu') as Tab;

  const [snapshot, profile] = await Promise.all([getBillingSnapshot(), buildProfileData(slug)]);
  const tenant = snapshot.tenants.find((t) => t.slug === slug);
  if (!tenant || !profile) notFound();

  const licences = snapshot.licenses.filter((l) => l.tenant === tenant.name);
  const invoices = snapshot.invoices.filter((i) => i.tenant === tenant.name);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', maxWidth: 1480 }}>
      <BillingFixtureNotice fixture={snapshot.fixture} />

      <Card padding="md">
        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-4)', flexWrap: 'wrap' }}>
          <span style={markStyle}>{initials(tenant.name)}</span>
          <div style={{ minWidth: 0, flex: 1 }}>
            <div style={{ font: 'var(--type-heading-4)' }}>{tenant.name}</div>
            <div className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)', marginTop: 2 }}>
              {tenant.slug} · {tenant.stores} points de vente · {licences.length} licence(s)
            </div>
          </div>
          <a href={`/preview/${slug}`} target="_blank" rel="noreferrer" style={previewStyle}>
            <Icon name="external-link" size={14} />
            Page charte du client
          </a>
        </div>

        <nav style={{ display: 'flex', gap: 2, marginTop: 'var(--space-4)', borderTop: '1px solid var(--border-subtle)', paddingTop: 'var(--space-3)', flexWrap: 'wrap' }}>
          {TABS.map((t) => (
            <Link key={t.id} href={`/admin/clients/${slug}?tab=${t.id}`} style={tabStyle(t.id === tab)}>
              <Icon name={t.icon} size={14} />
              {t.label}
            </Link>
          ))}
        </nav>
      </Card>

      {tab === 'apercu' && <Apercu tenant={tenant} licences={licences} invoices={invoices} slug={slug} />}
      {tab === 'personnalisation' && <ProfileEditor data={profile} />}
      {tab === 'facturation' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
          <LicensesTable licenses={licences} fixture={snapshot.fixture} />
          <InvoicesTable invoices={invoices} />
        </div>
      )}
      {tab === 'donnees' && <Donnees slug={slug} tenant={tenant} />}
      {tab === 'journal' && <Journal slug={slug} tenant={tenant} snapshot={snapshot} />}
    </div>
  );
}

/* ------------------------------------------------------------------ aperçu ---- */

type Tenant = Awaited<ReturnType<typeof getBillingSnapshot>>['tenants'][number];
type Licence = Awaited<ReturnType<typeof getBillingSnapshot>>['licenses'][number];
type Invoice = Awaited<ReturnType<typeof getBillingSnapshot>>['invoices'][number];

async function Apercu({
  tenant,
  licences,
  invoices,
  slug,
}: {
  tenant: Tenant;
  licences: Licence[];
  invoices: Invoice[];
  slug: string;
}) {
  const [published, draft, history] = await Promise.all([
    publishedOverlay(slug),
    draftOverlay(slug),
    publicationHistory(slug),
  ]);

  const active = licences.filter((l) => l.status === 'active' || l.status === 'trialing').length;
  const attention = licences.filter((l) => l.status === 'past_due' || l.status === 'blocked').length;
  const unpaid = invoices.filter((i) => i.status === 'open' || i.status === 'uncollectible').length;

  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 360px', gap: 'var(--space-4)', alignItems: 'start' }}>
      <Card padding="md" title="Identité" subtitle="Source : service de facturation — lecture seule ici">
        <Field label="Slug" mono>{tenant.slug}</Field>
        <Field label="URL du service du client" mono>{tenant.internal_api_url || '—'}</Field>
        <Field label="Contact administrateur" mono>{tenant.admin_email}</Field>
        <Field label="Clé d’API" mono>{tenant.api_key_hint ?? '— non visible'}</Field>
        <Field label="Client Stripe" mono>{tenant.stripe_customer_id ?? '—'}</Field>
        <Field label="Onboardé le" mono>{tenant.onboarded_at ?? '—'}</Field>
        <Field label="Points de vente" mono>{String(tenant.stores)}</Field>
      </Card>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
        <Card padding="md" title="État">
          <Field label="Licences actives">
            <Badge tone={active > 0 ? 'success' : 'neutral'} dot size="sm">{active}</Badge>
          </Field>
          <Field label="Licences à traiter">
            <Badge tone={attention > 0 ? 'danger' : 'neutral'} dot size="sm">{attention}</Badge>
          </Field>
          <Field label="Factures non réglées">
            <Badge tone={unpaid > 0 ? 'warning' : 'neutral'} dot size="sm">{unpaid}</Badge>
          </Field>
          <Field label="Configuration servie" mono>v{published.version}</Field>
          <Field label="Brouillon en attente" mono>{draft.updatedAt ? 'oui' : 'non'}</Field>
          <Field label="Publications" mono>{String(history.length)}</Field>
        </Card>

        <Card padding="md" title="Raccourcis">
          <Shortcut slug={slug} tab="personnalisation" icon="settings">Thème et variables</Shortcut>
          <Shortcut slug={slug} tab="facturation" icon="receipt">Licences et factures</Shortcut>
          <Shortcut slug={slug} tab="donnees" icon="file-text">Ressources et stockage</Shortcut>
          <Shortcut slug={slug} tab="journal" icon="clock">Journal des changements</Shortcut>
        </Card>
      </div>
    </div>
  );
}

/* ----------------------------------------------------------------- données ---- */

async function Donnees({ slug, tenant }: { slug: string; tenant: Tenant }) {
  const [published, rows] = await Promise.all([
    publishedOverlay(slug),
    prisma.setting.findMany({ where: { key: { startsWith: `tenant.${slug}.` } } }),
  ]);

  // Which catalogue entries this client's configuration actually points at.
  const selected = Object.values(published.values).filter(
    (v): v is string => typeof v === 'string' && catalogueResource(v) !== undefined,
  );
  const used = catalogueEntries().filter((e) => selected.includes(e.key));

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
      <Card padding="md" title="Où vivent les données de ce client">
        <Field label="Base applicative">
          <span className="fb-num">tfb_landing</span> — uniquement la surcouche de configuration
        </Field>
        <Field label="Service du client" mono>{tenant.internal_api_url || '— non renseigné'}</Field>
        <Field label="Portée serveur">
          Chaque lecture est filtrée sur le tenant côté serveur ; <span className="fb-num">?tenant=</span> n’est jamais cru sur parole.
        </Field>
      </Card>

      <Card padding="none" title="Ressources consommées" subtitle="Sélectionnées par les variables de type source">
        {used.length === 0 ? (
          <p style={emptyStyle}>Aucune ressource du catalogue n’est référencée par ce client.</p>
        ) : (
          <div>
            {used.map(({ key, resource }) => (
              <div key={key} style={listRowStyle}>
                <span className="fb-num" style={{ font: 'var(--type-data)', flex: 1 }}>{key}</span>
                <Badge tone={resource.kind === 'proxy' ? 'info' : 'success'} size="sm">
                  <span className="fb-num">{resource.kind}</span>
                </Badge>
                <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>
                  {resource.kind === 'query' ? resource.source : resource.path}
                </span>
                <Badge tone="neutral" size="sm"><span className="fb-num">@{resource.version}</span></Badge>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Card
        padding="none"
        title="Stockage dans tfb_landing"
        subtitle="Les seules lignes que cette application détient pour ce client"
        footer="Aucune table par client, aucune colonne par client — trois clés dans tfb_settings."
      >
        <div>
          {rows.map((row) => (
            <div key={row.key} style={listRowStyle}>
              <span className="fb-num" style={{ font: 'var(--type-data)', flex: 1 }}>{row.key}</span>
              <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>
                {JSON.stringify(row.value).length} octets
              </span>
            </div>
          ))}
          {rows.length === 0 && <p style={emptyStyle}>Rien encore — ce client sert la configuration par défaut.</p>}
        </div>
      </Card>
    </div>
  );
}

/* ----------------------------------------------------------------- journal ---- */

async function Journal({
  slug,
  tenant,
  snapshot,
}: {
  slug: string;
  tenant: Tenant;
  snapshot: Awaited<ReturnType<typeof getBillingSnapshot>>;
}) {
  const history = await publicationHistory(slug);
  const licenceIds = new Set(snapshot.licenses.filter((l) => l.tenant === tenant.name).map((l) => l.id));
  const events = snapshot.events.filter((e) => licenceIds.has(e.license));
  const sync = snapshot.sync.filter((s) => licenceIds.has(s.license));

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
      <Card
        padding="none"
        title="Publications de configuration"
        subtitle="Qui a publié quoi, et ce qui a changé"
        footer="Les 20 dernières. Écrit avec la publication, jamais après — une entrée qui peut manquer rendrait le journal trompeur."
      >
        {history.length === 0 ? (
          <p style={emptyStyle}>Aucune publication pour l’instant.</p>
        ) : (
          <div>
            {history.map((entry) => (
              <div key={`${entry.version}-${entry.at}`} style={{ ...listRowStyle, alignItems: 'flex-start' }}>
                <Badge tone="brand" size="sm"><span className="fb-num">v{entry.version}</span></Badge>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-secondary)' }}>
                    {entry.at.replace('T', ' ').slice(0, 16)} · {entry.by}
                  </div>
                  <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 4 }}>
                    {entry.changed.length === 0 ? (
                      <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>aucune valeur modifiée</span>
                    ) : (
                      entry.changed.map((key) => (
                        <span key={key} className="fb-num" style={changedChipStyle}>{key}</span>
                      ))
                    )}
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      <EventsTable events={events} />
      <SyncQueueTable rows={sync} fixture={snapshot.fixture} />
    </div>
  );
}

/* -------------------------------------------------------------------- bits ---- */

function Field({ label, children, mono }: { label: string; children: React.ReactNode; mono?: boolean }) {
  return (
    <div style={{ display: 'flex', alignItems: 'baseline', gap: 'var(--space-3)', padding: '7px 0', borderBottom: '1px dashed var(--border-default)' }}>
      <span style={{ font: 'var(--type-caption)', color: 'var(--text-secondary)', minWidth: 190 }}>{label}</span>
      <span className={mono ? 'fb-num' : undefined} style={{ font: mono ? 'var(--type-data)' : 'var(--type-body-sm)', marginInlineStart: 'auto', textAlign: 'end', wordBreak: 'break-all' }}>
        {children}
      </span>
    </div>
  );
}

function Shortcut({ slug, tab, icon, children }: { slug: string; tab: string; icon: string; children: React.ReactNode }) {
  return (
    <Link href={`/admin/clients/${slug}?tab=${tab}`} style={shortcutStyle}>
      <Icon name={icon} size={14} color="var(--text-tertiary)" />
      <span style={{ font: 'var(--type-body-sm)' }}>{children}</span>
      <Icon name="chevron-right" size={13} className="fb-flip" color="var(--text-tertiary)" style={{ marginInlineStart: 'auto' }} />
    </Link>
  );
}

function initials(name: string): string {
  return name.split(/\s+/).slice(0, 2).map((w) => w[0] ?? '').join('').toUpperCase();
}

function tabStyle(active: boolean): React.CSSProperties {
  return {
    display: 'inline-flex', alignItems: 'center', gap: 7,
    padding: '8px 14px', borderRadius: 'var(--radius-md)',
    font: active ? 'var(--type-label)' : 'var(--type-body-sm)',
    color: active ? 'var(--text-brand)' : 'var(--text-secondary)',
    background: active ? 'var(--surface-brand-subtle)' : 'transparent',
    textDecoration: 'none',
  };
}

const markStyle: React.CSSProperties = {
  display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
  width: 44, height: 44, flex: 'none', borderRadius: 'var(--radius-card)',
  background: 'var(--brand)', color: 'var(--brand-on)', font: 'var(--type-title)',
};

const previewStyle: React.CSSProperties = {
  display: 'inline-flex', alignItems: 'center', gap: 7,
  padding: '7px 12px', borderRadius: 'var(--radius-md)',
  border: '1px solid var(--border-default)', background: 'var(--surface-card)',
  color: 'var(--text-primary)', font: 'var(--type-label)', textDecoration: 'none',
};

const listRowStyle: React.CSSProperties = {
  display: 'flex', alignItems: 'center', gap: 'var(--space-3)',
  padding: 'var(--space-3) var(--space-5)', borderTop: '1px solid var(--border-subtle)',
};

const shortcutStyle: React.CSSProperties = {
  display: 'flex', alignItems: 'center', gap: 'var(--space-2)',
  padding: '9px 0', borderBottom: '1px dashed var(--border-default)',
  color: 'inherit', textDecoration: 'none',
};

const changedChipStyle: React.CSSProperties = {
  display: 'inline-flex', padding: '2px 7px', borderRadius: 'var(--radius-sm)',
  background: 'var(--surface-sunken)', color: 'var(--text-secondary)', font: 'var(--type-caption)',
};

const emptyStyle: React.CSSProperties = {
  padding: 'var(--space-5)', font: 'var(--type-body-sm)', color: 'var(--text-tertiary)',
};
