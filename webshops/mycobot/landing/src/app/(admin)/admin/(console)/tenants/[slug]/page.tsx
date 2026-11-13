import { notFound } from 'next/navigation';
import { getBillingSnapshot } from '@/lib/billing/client';
import { TenantDetail } from '@/components/admin/BillingTables';
import { BillingFixtureNotice } from '@/components/admin/shared';

/** One tenant: its licences (uq_licenses_scope), invoices and API access. */
export default async function TenantPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const b = await getBillingSnapshot();
  const tenant = b.tenants.find((t) => t.slug === slug);
  if (!tenant) notFound();

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
      <BillingFixtureNotice fixture={b.fixture} />
      <TenantDetail
        tenant={tenant}
        licenses={b.licenses.filter((l) => l.tenant === tenant.name)}
        invoices={b.invoices.filter((i) => i.tenant === tenant.name)}
        fixture={b.fixture}
      />
    </div>
  );
}
