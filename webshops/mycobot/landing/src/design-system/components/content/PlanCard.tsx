import type { CSSProperties } from 'react';
import { Icon } from '../core/Icon';
import { Badge } from '../core/Badge';
import { Button } from '../core/Button';

/**
 * Pricing card built from a `tfb_plans` row. The CTA starts a Stripe Checkout session
 * (`POST /api/checkout` with the plan's stripe_price_id) — the card only reports the click.
 */
export interface PlanCardProps {
  /** Translated plan name (tfb_translations, entity_type='plan', field 'name'). */
  name: string;
  /** Formatted amount, e.g. "89 €" — format tfb_plans.amount/currency before passing. */
  priceLabel: string;
  /** Translated interval line, e.g. "/ mois par point de vente". */
  intervalLabel?: string | null;
  /** Translated description (field 'description'). */
  description?: string;
  /** Translated feature list (field 'features'). */
  features?: string[];
  /** tfb_plans.is_featured — ink background, warm CTA, badge. Exactly one per grid. */
  featured?: boolean;
  /** Translated badge label; defaults to "Recommandé". */
  badgeLabel?: string;
  /** Translated CTA label; defaults to "Choisir ce plan". */
  ctaLabel?: string;
  onSelect?: () => void;
  style?: CSSProperties;
}

export function PlanCard({
  name, priceLabel, intervalLabel, description, features = [], featured = false,
  badgeLabel = 'Recommandé', ctaLabel = 'Choisir ce plan', onSelect, style, ...rest
}: PlanCardProps) {
  return (
    <div
      {...rest}
      style={{
        display: 'flex', flexDirection: 'column', gap: 'var(--space-5)', padding: 'var(--space-6)',
        borderRadius: 'var(--radius-panel)',
        background: featured ? 'var(--gradient-ink)' : 'var(--surface-card)',
        color: featured ? 'var(--text-inverse)' : 'var(--text-primary)',
        border: '1px solid ' + (featured ? 'transparent' : 'var(--border-default)'),
        boxShadow: featured ? 'var(--shadow-xl)' : 'var(--shadow-sm)',
        minWidth: 0, ...style,
      }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
        <span style={{ font: 'var(--type-heading-4)', color: 'inherit' }}>{name}</span>
        {featured && <Badge tone="warm">{badgeLabel}</Badge>}
      </div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 6 }}>
        <span style={{ font: 'var(--weight-bold) var(--text-4xl)/1 var(--font-display)', letterSpacing: 'var(--tracking-display)', fontVariantNumeric: 'tabular-nums' }}>{priceLabel}</span>
        {intervalLabel && <span style={{ font: 'var(--type-body-sm)', color: featured ? 'var(--text-inverse-secondary)' : 'var(--text-tertiary)' }}>{intervalLabel}</span>}
      </div>
      {description && <p style={{ font: 'var(--type-body)', color: featured ? 'var(--text-inverse-secondary)' : 'var(--text-secondary)' }}>{description}</p>}
      <Button variant={featured ? 'warm' : 'secondary'} fullWidth onClick={onSelect}>{ctaLabel}</Button>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
        {features.map((f) => (
          <span key={f} style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--space-2)', font: 'var(--type-body-sm)', color: featured ? 'var(--text-inverse-secondary)' : 'var(--text-secondary)' }}>
            <Icon name="check" size={15} color={featured ? 'var(--teal-300)' : 'var(--status-success)'} />{f}
          </span>
        ))}
      </div>
    </div>
  );
}
