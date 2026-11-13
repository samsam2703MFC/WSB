import type { CSSProperties, ReactNode } from 'react';
import { Icon } from './Icon';

/**
 * Single KPI readout — the console's most repeated pattern. Lay 3–4 across a grid row.
 * Value and delta are LTR-isolated so figures read correctly inside Arabic.
 */
export interface StatTileProps {
  /** Uppercase eyebrow label. */
  label: ReactNode;
  /** Pre-formatted headline figure — format before passing (e.g. "418 k€", "92 %"). */
  value: string | number;
  /** Small trailing unit, e.g. "ce mois" or "points de vente". */
  unit?: ReactNode;
  /** Pre-formatted change string, e.g. "+6,4%". */
  delta?: string;
  /** Comparison context, e.g. "vs. juin". */
  deltaLabel?: ReactNode;
  /** Icon name from public/icons shown in the tinted square. */
  icon?: string;
  /** Colors the delta green (up) or red (down). */
  trend?: 'up' | 'down';
  tone?: 'default' | 'inverse';
  style?: CSSProperties;
}

export function StatTile({ label, value, unit, delta, deltaLabel, icon, trend = 'up', tone = 'default', style, ...rest }: StatTileProps) {
  const positive = trend === 'up';
  const deltaColor = delta == null ? undefined : positive ? 'var(--text-success)' : 'var(--text-danger)';
  const inverse = tone === 'inverse';
  return (
    <div
      {...rest}
      style={{
        display: 'flex',
        flexDirection: 'column',
        gap: 'var(--space-3)',
        padding: 'var(--space-5)',
        borderRadius: 'var(--radius-card)',
        background: inverse ? 'var(--gradient-ink)' : 'var(--surface-card)',
        border: '1px solid ' + (inverse ? 'var(--border-inverse)' : 'var(--border-default)'),
        boxShadow: 'var(--shadow-sm)',
        minWidth: 0,
        ...style,
      }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
        {icon && (
          <span style={{
            display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 26, height: 26,
            borderRadius: 'var(--radius-sm)', flex: 'none',
            background: inverse ? 'var(--alpha-white-08)' : 'var(--surface-brand-subtle)',
            color: inverse ? 'var(--plum-300)' : 'var(--text-brand)',
          }}>
            <Icon name={icon} size={15} />
          </span>
        )}
        <span style={{
          font: 'var(--weight-semibold) var(--text-xs)/1.2 var(--font-sans)',
          letterSpacing: 'var(--tracking-caps)', textTransform: 'uppercase',
          color: inverse ? 'var(--text-inverse-secondary)' : 'var(--text-tertiary)',
        }}>{label}</span>
      </div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 6, direction: 'ltr', unicodeBidi: 'isolate' }}>
        <span style={{ font: 'var(--type-metric)', letterSpacing: 'var(--tracking-display)', fontVariantNumeric: 'tabular-nums', color: inverse ? 'var(--text-inverse)' : 'var(--text-primary)' }}>{value}</span>
        {unit && <span style={{ font: 'var(--type-body)', color: inverse ? 'var(--text-inverse-secondary)' : 'var(--text-tertiary)' }}>{unit}</span>}
      </div>
      {(delta != null || deltaLabel) && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 6, font: 'var(--type-body-sm)', color: inverse ? 'var(--text-inverse-secondary)' : 'var(--text-secondary)' }}>
          {delta != null && (
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, color: deltaColor, fontWeight: 'var(--weight-medium)', direction: 'ltr', unicodeBidi: 'isolate' }}>
              <Icon name={positive ? 'trending-up' : 'trending-down'} size={14} />
              {delta}
            </span>
          )}
          {deltaLabel}
        </div>
      )}
    </div>
  );
}
