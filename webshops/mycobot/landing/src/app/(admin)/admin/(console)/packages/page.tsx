import { getBillingSnapshot } from '@/lib/billing/client';
import { PackagesPanel } from '@/components/admin/BillingTables';
import { BillingFixtureNotice } from '@/components/admin/shared';

/** packages + pricing_tiers — volume bands, valid_to NULL = in effect. */
export default async function PackagesPage() {
  const b = await getBillingSnapshot();
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
      <BillingFixtureNotice fixture={b.fixture} />
      <PackagesPanel packages={b.packages} tiers={b.tiers} />
    </div>
  );
}
