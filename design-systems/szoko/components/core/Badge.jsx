import React from 'react';

// Marketing flag for product imagery — "New", "-20%", "Bestseller".
const tones = {
  sale:  { background: 'var(--sale)', color: 'var(--white)' },
  new:   { background: 'var(--brand)', color: 'var(--text-inverse)' },
  gold:  { background: 'var(--gold-500)', color: 'var(--choco-900)' },
  soft:  { background: 'var(--surface-card)', color: 'var(--brand)', boxShadow: 'var(--shadow-sm)' },
};

export function Badge({ children, tone = 'new', style, ...rest }) {
  const t = tones[tone] || tones.new;
  return (
    <span
      style={{
        display: 'inline-flex', alignItems: 'center',
        fontFamily: 'var(--font-sans)', fontSize: 'var(--text-xs)',
        fontWeight: 'var(--weight-extra)', letterSpacing: '0.02em',
        padding: '5px 12px', borderRadius: 'var(--radius-pill)',
        lineHeight: 1, whiteSpace: 'nowrap', ...t, ...style,
      }}
      {...rest}
    >
      {children}
    </span>
  );
}
