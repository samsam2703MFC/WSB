import { getBillingSnapshot } from '@/lib/billing/client';
import { LicensesTable } from '@/components/admin/BillingTables';
import { BillingFixtureNotice } from '@/components/admin/shared';

/** licenses — status filter, discount_percent, subscription, block action. */
export default async function LicensesPage() {
  const b = await getBillingSnapshot();
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
      <BillingFixtureNotice fixture={b.fixture} />
      <LicensesTable licenses={b.licenses} fixture={b.fixture} />
    </div>
  );
}
