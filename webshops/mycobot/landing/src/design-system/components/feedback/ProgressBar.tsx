import type { CSSProperties, ReactNode } from 'react';

/** Determinate progress for translation coverage, onboarding steps and licence mix. */
export interface ProgressBarProps {
  value?: number;
  max?: number;
  /** Left-hand caption, e.g. "Onboarding". */
  label?: ReactNode;
  /** Overrides the right-hand readout, e.g. "6 / 9 étapes". */
  valueLabel?: string | null;
  tone?: 'brand' | 'success' | 'warning' | 'danger' | 'teal';
  /** sm = 4px, md = 6px, lg = 10px. */
  size?: 'sm' | 'md' | 'lg';
  showValue?: boolean;
  style?: CSSProperties;
}

export function ProgressBar({ value = 0, max = 100, label, valueLabel, tone = 'brand', size = 'md', showValue = true, style, ...rest }: ProgressBarProps) {
  const pct = Math.max(0, Math.min(100, (value / max) * 100));
  const height = size === 'sm' ? 4 : size === 'lg' ? 10 : 6;
  const fill = { brand: 'var(--brand)', success: 'var(--status-success)', warning: 'var(--status-warning)', danger: 'var(--status-danger)', teal: 'var(--teal-500)' }[tone];
  return (
    <div {...rest} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)', minWidth: 0, ...style }}>
      {(label || (showValue && valueLabel !== null)) && (
        <div style={{ display: 'flex', alignItems: 'baseline', gap: 'var(--space-3)' }}>
          {label && <span style={{ font: 'var(--type-label)', color: 'var(--text-secondary)' }}>{label}</span>}
          {showValue && (
            // LTR-isolated: "44%" must not reorder inside Arabic text.
            <span className="fb-num" style={{ marginInlineStart: 'auto', font: 'var(--type-data)', color: 'var(--text-primary)', direction: 'ltr', unicodeBidi: 'isolate' }}>
              {valueLabel != null ? valueLabel : Math.round(pct) + '%'}
            </span>
          )}
        </div>
      )}
      <div
        role="progressbar"
        aria-valuenow={value}
        aria-valuemax={max}
        style={{ height, borderRadius: 'var(--radius-pill)', background: 'var(--slate-100)', overflow: 'hidden' }}
      >
        <div style={{
          width: pct + '%', height: '100%', borderRadius: 'var(--radius-pill)', background: fill,
          transition: 'width var(--duration-slow) var(--ease-out)',
        }} />
      </div>
    </div>
  );
}
