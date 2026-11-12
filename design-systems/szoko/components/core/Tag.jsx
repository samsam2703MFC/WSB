import React from 'react';

// Small uppercase eyebrow / category label with wide tracking.
const tones = {
  origin:  { color: 'var(--choco-600)', background: 'var(--brand-quiet)' },
  accent:  { color: 'var(--caramel-600)', background: 'var(--accent-quiet)' },
  berry:   { color: 'var(--white)', background: 'var(--berry-500)' },
  plain:   { color: 'var(--text-muted)', background: 'transparent', boxShadow: 'inset 0 0 0 1px var(--border-default)' },
};

export function Tag({ children, tone = 'origin', icon, style, ...rest }) {
  const t = tones[tone] || tones.origin;
  return (
    <span
      style={{
        display: 'inline-flex', alignItems: 'center', gap: 'var(--space-2)',
        fontFamily: 'var(--font-mono)', fontSize: 'var(--text-2xs)',
        fontWeight: 'var(--weight-medium)', textTransform: 'uppercase',
        letterSpacing: 'var(--tracking-caps)', padding: '5px 11px',
        borderRadius: 'var(--radius-pill)', lineHeight: 1, whiteSpace: 'nowrap',
        ...t, ...style,
      }}
      {...rest}
    >
      {icon}
      {children}
    </span>
  );
}
