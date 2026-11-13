import { getBillingSnapshot } from '@/lib/billing/client';
import { InvoicesTable } from '@/components/admin/BillingTables';
import { BillingFixtureNotice } from '@/components/admin/shared';

/** invoices — amount_due / amount_paid, and invoice_pdf_url availability. */
export default async function InvoicesPage() {
  const b = await getBillingSnapshot();
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
      <BillingFixtureNotice fixture={b.fixture} />
      <InvoicesTable invoices={b.invoices} />
    </div>
  );
}
