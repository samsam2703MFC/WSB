import { getBillingSnapshot } from '@/lib/billing/client';
import { EventsTable } from '@/components/admin/BillingTables';
import { BillingFixtureNotice } from '@/components/admin/shared';

/** license_events — Stripe webhooks (idempotent by stripe_event_id) vs internal. */
export default async function EventsPage() {
  const b = await getBillingSnapshot();
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
      <BillingFixtureNotice fixture={b.fixture} />
      <EventsTable events={b.events} />
    </div>
  );
}
