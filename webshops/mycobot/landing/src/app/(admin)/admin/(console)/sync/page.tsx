import { getBillingSnapshot } from '@/lib/billing/client';
import { SyncQueueTable } from '@/components/admin/BillingTables';
import { BillingFixtureNotice } from '@/components/admin/shared';

/** sync_queue — attempts, next_retry, last_error, replay. */
export default async function SyncPage() {
  const b = await getBillingSnapshot();
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
      <BillingFixtureNotice fixture={b.fixture} />
      <SyncQueueTable rows={b.sync} fixture={b.fixture} />
    </div>
  );
}
