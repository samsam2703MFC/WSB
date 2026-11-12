import React from 'react';
import { Icon } from '../core/Icon';

// +/- quantity control for basket lines and PDP.
export function QuantityStepper({
  value = 1, min = 1, max = 99, onChange, size = 'md', style, ...rest
}) {
  const dim = size === 'sm' ? 32 : 40;
  const set = (n) => { const c = Math.max(min, Math.min(max, n)); onChange && onChange(c); };
  const btn = (dir, name, disabled) => (
    <button
      aria-label={dir}
      disabled={disabled}
      onClick={() => set(value + (dir === 'increase' ? 1 : -1))}
      style={{
        width: dim, height: dim, border: 'none', cursor: disabled ? 'default' : 'pointer',
        background: 'transparent', color: disabled ? 'var(--border-default)' : 'var(--brand)',
        display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
        borderRadius: 'var(--radius-pill)', transition: 'background var(--dur-base) var(--ease-out)',
      }}
      onMouseEnter={(e) => { if (!disabled) e.currentTarget.style.background = 'var(--surface-raised)'; }}
      onMouseLeave={(e) => { e.currentTarget.style.background = 'transparent'; }}
    >
      <Icon name={name} size={size === 'sm' ? 16 : 18} />
    </button>
  );
  return (
    <div
      style={{
        display: 'inline-flex', alignItems: 'center',
        background: 'var(--surface-card)', borderRadius: 'var(--radius-pill)',
        boxShadow: 'inset 0 0 0 1.5px var(--border-default)', ...style,
      }}
      {...rest}
    >
      {btn('decrease', 'minus', value <= min)}
      <span style={{
        minWidth: 28, textAlign: 'center', fontFamily: 'var(--font-mono)',
        fontSize: 'var(--text-md)', color: 'var(--text-strong)', fontWeight: 'var(--weight-medium)',
      }}>{value}</span>
      {btn('increase', 'plus', value >= max)}
    </div>
  );
}
