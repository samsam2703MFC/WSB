import React from 'react';

// Price display with optional struck-through original (sale). Monospace figures.
const sizes = {
  sm: { now: 'var(--text-md)', was: 'var(--text-xs)' },
  md: { now: 'var(--text-xl)', was: 'var(--text-sm)' },
  lg: { now: 'var(--text-3xl)', was: 'var(--text-lg)' },
};

export function PriceTag({ amount, was, currency = '€', size = 'md', style, ...rest }) {
  const s = sizes[size] || sizes.md;
  const onSale = was != null;
  return (
    <span style={{ display: 'inline-flex', alignItems: 'baseline', gap: 'var(--space-3)', fontFamily: 'var(--font-mono)', ...style }} {...rest}>
      <span style={{ fontSize: s.now, fontWeight: 'var(--weight-medium)', color: onSale ? 'var(--sale)' : 'var(--price)', letterSpacing: '-0.01em' }}>
        {currency}{amount}
      </span>
      {onSale && (
        <span style={{ fontSize: s.was, color: 'var(--text-muted)', textDecoration: 'line-through' }}>
          {currency}{was}
        </span>
      )}
    </span>
  );
}
