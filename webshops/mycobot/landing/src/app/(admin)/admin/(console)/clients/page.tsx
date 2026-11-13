import Link from 'next/link';
import { Badge, Card, Icon } from '@/design-system';
import { getBillingSnapshot } from '@/lib/billing/client';
import { overrideCounts } from '@/lib/config/store';
import { REGISTRY } from '@/lib/config/registry';
import { BillingFixtureNotice } from '@/components/admin/shared';

/**
 * Clients — the one list that leads to everything about a customer.
 *
 * Before this, a client was split across two groups of the rail: their licences under
 * Facturation, their configuration under Personnalisation, and nothing tied the two
 * together. Whoever answers the phone should not have to know which half a question
 * belongs to.
 *
 * Tenants still come from the billing service; this screen joins them to what this
 * app owns rather than copying them.
 */
export default async function ClientsPage() {
  const [snapshot, counts] = await Promise.all([getBillingSnapshot(), overrideCounts()]);
  const tenants = snapshot.tenants.filter((t) => t.archived_at === null);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', maxWidth: 1180 }}>
      <BillingFixtureNotice fixture={snapshot.fixture} />

      <Card
        padding="none"
        title="Clients"
        subtitle="Licences, personnalisation, données et journal — une fiche par client"
        footer="Les tenants viennent du service de facturation ; seule la surcouche appartient à cette application."
      >
        <div>
          {tenants.map((t) => {
            const licences = snapshot.licenses.filter((l) => l.tenant === t.name);
            const blocked = licences.filter((l) => l.status === 'blocked' || l.status === 'past_due').length;
            const entry = counts.get(t.slug) ?? { overrides: 0, version: 0, dirty: false };

            return (
              <Link key={t.slug} href={`/admin/clients/${t.slug}`} style={rowStyle}>
                <Icon name="building" size={16} color="var(--text-tertiary)" />
                <div style={{ minWidth: 0, flex: 1 }}>
                  <div style={{ font: 'var(--type-label)' }}>{t.name}</div>
                  <div className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>
                    {t.slug} · {t.stores} PDV · {licences.length} licence(s)
                  </div>
                </div>

                {blocked > 0 ? (
                  <Badge tone="danger" dot size="sm">{blocked} licence(s) à traiter</Badge>
                ) : (
                  <Badge tone="success" dot size="sm">facturation à jour</Badge>
                )}
                <Badge tone={entry.overrides > 0 ? 'brand' : 'neutral'} size="sm">
                  {entry.overrides} / {REGISTRY.length} personnalisées
                </Badge>
                <Badge tone="neutral" size="sm"><span className="fb-num">v{entry.version}</span></Badge>
                {entry.dirty && <Badge tone="warning" dot size="sm">brouillon</Badge>}
                <Icon name="chevron-right" size={14} className="fb-flip" color="var(--text-tertiary)" />
              </Link>
            );
          })}
        </div>
      </Card>
    </div>
  );
}

const rowStyle = {
  display: 'flex', alignItems: 'center', gap: 'var(--space-3)',
  padding: 'var(--space-3) var(--space-5)', borderTop: '1px solid var(--border-subtle)',
  color: 'inherit', textDecoration: 'none',
} as const;
