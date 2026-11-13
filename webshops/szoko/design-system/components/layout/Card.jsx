import React from 'react';

// Warm surface container. `hover` enables the gentle lift used by product cards.
export function Card({
  children, padding = 'var(--space-6)', hover = false, tone = 'card',
  radius = 'var(--radius-lg)', style, ...rest
}) {
  const [h, setH] = React.useState(false);
  const tones = {
    card:   { background: 'var(--surface-card)', color: 'var(--text-body)' },
    raised: { background: 'var(--surface-raised)', color: 'var(--text-body)' },
    choco:  { background: 'var(--bg-inverse)', color: 'var(--text-inverse)' },
  };
  return (
    <div
      onMouseEnter={() => hover && setH(true)}
      onMouseLeave={() => hover && setH(false)}
      style={{
        borderRadius: radius, padding, overflow: 'hidden',
        boxShadow: h ? 'var(--shadow-md)' : 'var(--shadow-sm)',
        transform: h ? 'var(--lift)' : 'none',
        transition: 'transform var(--dur-base) var(--ease-soft), box-shadow var(--dur-base) var(--ease-out)',
        ...tones[tone], ...style,
      }}
      {...rest}
    >
      {children}
    </div>
  );
}
