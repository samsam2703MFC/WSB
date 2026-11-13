import { getBillingSnapshot } from '@/lib/billing/client';
import { TenantsTable } from '@/components/admin/BillingTables';
import { BillingFixtureNotice } from '@/components/admin/shared';

/** tenants — the billing service's top-level entity. */
export default async function TenantsPage() {
  const b = await getBillingSnapshot();
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
      <BillingFixtureNotice fixture={b.fixture} />
      <TenantsTable tenants={b.tenants} />
    </div>
  );
}
