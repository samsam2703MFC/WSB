import Link from 'next/link';
import { Card, StatTile, Button, Icon, ProgressBar } from '@/design-system';
import { getBillingSnapshot } from '@/lib/billing/client';
import { LICENSE_STATUSES, LICENSE_STATUS_TONE, euro } from '@/lib/billing/types';
import { BillingFixtureNotice } from '@/components/admin/shared';

/**
 * Billing dashboard: MRR, licence mix by licenses.status, the sync_queue failures
 * and the latest license_events.
 */
export default async function BillingDashboard() {
  const b = await getBillingSnapshot();

  // Everything below is derived from the snapshot, so the figures stay consistent
  // with the tables the rail links to.
  const active = b.licenses.filter((l) => l.status === 'active');
  const trialing = b.licenses.filter((l) => l.status === 'trialing');
  const openInvoices = b.invoices.filter((i) => i.status === 'open');
  const mrr = active.reduce((sum, l) => sum + l.unit_price, 0);
  const byStatus = Object.fromEntries(
    LICENSE_STATUSES.map((s) => [s, b.licenses.filter((l) => l.status === s).length]),
  ) as Record<string, number>;
  const maxCount = Math.max(1, ...Object.values(byStatus));

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-6)', maxWidth: 1240 }}>
      <BillingFixtureNotice fixture={b.fixture} />

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 'var(--space-4)' }}>
        <StatTile tone="inverse" label="MRR" value={euro(mrr)} deltaLabel="licences actives" icon="wallet" />
        <StatTile label="Licences actives" value={active.length} unit={`${trialing.length} en essai`} icon="lock" />
        <StatTile
          label="Factures ouvertes"
          value={openInvoices.length}
          unit={euro(openInvoices.reduce((s, i) => s + i.amount_due, 0))}
          icon="receipt"
        />
        <StatTile label="File de sync" value={b.sync.length} unit="à réessayer" icon="triangle-alert" />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--space-4)', alignItems: 'start' }}>
        <Card
          padding="none"
          title="Licences par statut"
          subtitle="licenses.status"
          actions={
            <Link href="/admin/licenses" style={{ textDecoration: 'none' }}>
              <Button variant="secondary" size="sm" iconRight="arrow-right">Ouvrir</Button>
            </Link>
          }
        >
          <div style={{ padding: 'var(--space-5)', display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
            {LICENSE_STATUSES.map((status) => {
              const n = byStatus[status] ?? 0;
              const tone = LICENSE_STATUS_TONE[status];
              return (
                <div key={status} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
                  <span className="fb-num" style={{ width: 96, font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>{status}</span>
                  <ProgressBar
                    value={n}
                    max={maxCount}
                    size="sm"
                    showValue={false}
                    tone={tone === 'danger' ? 'danger' : tone === 'info' ? 'teal' : tone === 'success' ? 'success' : 'brand'}
                    style={{ flex: 1 }}
                  />
                  <span className="fb-num" style={{ width: 24, textAlign: 'end', font: 'var(--type-body-sm)' }}>{n}</span>
                </div>
              );
            })}
          </div>
        </Card>

        <Card
          padding="none"
          title="File de synchronisation"
          subtitle="sync_queue → /internal/license-sync"
          actions={
            <Link href="/admin/sync" style={{ textDecoration: 'none' }}>
              <Button size="sm" iconLeft="play">Ouvrir</Button>
            </Link>
          }
          footer="Les échecs sont réessayés avec un back-off exponentiel."
        >
          <div>
            {b.sync.length === 0 && (
              <div style={{ padding: 'var(--space-6) var(--space-5)', font: 'var(--type-body)', color: 'var(--text-tertiary)', borderTop: '1px solid var(--border-subtle)' }}>
                File vide — toutes les licences sont synchronisées.
              </div>
            )}
            {b.sync.map((s) => (
              <div key={s.id} style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--space-3)', padding: 'var(--space-3) var(--space-5)', borderTop: '1px solid var(--border-subtle)' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 30, height: 30, flex: 'none', borderRadius: 'var(--radius-sm)', background: 'var(--surface-danger-subtle)', color: 'var(--status-danger)' }}>
                  <Icon name="triangle-alert" size={15} />
                </span>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 2, flex: 1, minWidth: 0 }}>
                  <span className="fb-num" style={{ font: 'var(--weight-medium) var(--text-sm)/1.3 var(--font-sans)' }}>#{s.id} · {s.license}</span>
                  <span style={{ font: 'var(--type-caption)', color: 'var(--text-secondary)' }}>{s.last_error}</span>
                  <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>
                    {s.attempts} tentatives · prochain essai {s.next_retry}
                  </span>
                </div>
              </div>
            ))}
          </div>
        </Card>
      </div>

      <Card
        padding="none"
        title="Derniers événements"
        subtitle="license_events — idempotence par stripe_event_id"
        actions={
          <Link href="/admin/events" style={{ textDecoration: 'none' }}>
            <Button variant="secondary" size="sm">Tout voir</Button>
          </Link>
        }
      >
        <div>
          {b.events.map((e) => (
            <div key={e.id} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3) var(--space-5)', borderTop: '1px solid var(--border-subtle)' }}>
              <Icon name={e.stripe_event_id ? 'credit-card' : 'lock'} size={16} color="var(--text-tertiary)" />
              <span className="fb-num" style={{ font: 'var(--type-body-sm)', fontWeight: 'var(--weight-medium)', minWidth: 220 }}>{e.event_type}</span>
              <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{e.license}</span>
              <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{e.stripe_event_id ?? 'interne'}</span>
              <span className="fb-num" style={{ marginInlineStart: 'auto', font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>{e.occurred_at}</span>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}
