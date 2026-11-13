import { Badge, Icon } from '@/design-system';
import {
  LICENSE_STATUS_TONE, INVOICE_STATUS_TONE,
  type LicenseStatus, type InvoiceStatus,
} from '@/lib/billing/types';

/**
 * Status pill: tinted background, 6px dot, label in mono — and the label is the
 * raw English DB value, because status is data, never copy.
 */
export function LicenseStatusBadge({ value }: { value: LicenseStatus }) {
  return (
    <Badge tone={LICENSE_STATUS_TONE[value] ?? 'neutral'} dot size="sm">
      <span className="fb-num">{value}</span>
    </Badge>
  );
}

export function InvoiceStatusBadge({ value }: { value: InvoiceStatus }) {
  return (
    <Badge tone={INVOICE_STATUS_TONE[value] ?? 'neutral'} dot size="sm">
      <span className="fb-num">{value}</span>
    </Badge>
  );
}

/**
 * Shown on every billing screen while the rows come from the fixture snapshot.
 * A console that silently shows sample numbers as though they were live is how
 * someone makes a decision on invented data.
 */
export function BillingFixtureNotice({ fixture }: { fixture: boolean }) {
  if (!fixture) return null;
  return (
    <div
      role="status"
      style={{
        display: 'flex', alignItems: 'flex-start', gap: 'var(--space-3)',
        padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-card)',
        background: 'var(--surface-warning-subtle)', border: '1px solid var(--amber-500)',
        color: 'var(--text-warning)', maxWidth: 1240,
      }}
    >
      <Icon name="triangle-alert" size={17} style={{ marginTop: 1 }} />
      <span style={{ font: 'var(--type-body-sm)' }}>
        Jeu de démonstration — <span className="fb-num">BILLING_SERVICE_URL</span> n’est pas configuré, donc ces
        lignes viennent des fixtures et non du service de facturation. Les actions destructives sont refusées.
      </span>
    </div>
  );
}

/** Caption under a table that names the source table and its status vocabulary. */
export function SourceNote({ children }: { children: React.ReactNode }) {
  return (
    <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{children}</span>
  );
}

/** A screen with no source definition is left visibly blank, not invented. */
export function NotDesigned({ what, why }: { what: string; why: string }) {
  return (
    <div style={{
      display: 'flex', flexDirection: 'column', gap: 'var(--space-2)', maxWidth: 640,
      padding: 'var(--space-6)', borderRadius: 'var(--radius-card)',
      border: '1px dashed var(--border-strong)', background: 'var(--slate-25)',
    }}>
      <span style={{ font: 'var(--type-title)' }}>{what}</span>
      <p style={{ font: 'var(--type-body)', color: 'var(--text-secondary)' }}>{why}</p>
    </div>
  );
}
