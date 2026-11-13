import React from 'react';

const base = {
  fontFamily: 'var(--font-sans)',
  fontWeight: 'var(--weight-bold)',
  border: 'none',
  cursor: 'pointer',
  display: 'inline-flex',
  alignItems: 'center',
  justifyContent: 'center',
  gap: 'var(--space-2)',
  borderRadius: 'var(--radius-pill)',
  lineHeight: 1,
  letterSpacing: '0.01em',
  textDecoration: 'none',
  whiteSpace: 'nowrap',
  transition: 'transform var(--dur-fast) var(--ease-soft), background var(--dur-base) var(--ease-out), box-shadow var(--dur-base) var(--ease-out), color var(--dur-base) var(--ease-out)',
};

const sizes = {
  sm: { fontSize: 'var(--text-sm)', padding: '9px 16px' },
  md: { fontSize: 'var(--text-md)', padding: '13px 24px' },
  lg: { fontSize: 'var(--text-lg)', padding: '17px 34px' },
};

const variants = {
  primary:   { rest: { background: 'var(--brand)', color: 'var(--text-inverse)', boxShadow: 'var(--shadow-sm)' },
               hover: { background: 'var(--brand-hover)', boxShadow: 'var(--shadow-md)' } },
  accent:    { rest: { background: 'var(--accent)', color: 'var(--text-on-accent)', boxShadow: 'var(--shadow-sm)' },
               hover: { background: 'var(--accent-hover)', boxShadow: 'var(--shadow-md)' } },
  secondary: { rest: { background: 'transparent', color: 'var(--brand)', boxShadow: 'inset 0 0 0 1.5px var(--border-strong)' },
               hover: { background: 'var(--brand-quiet)', boxShadow: 'inset 0 0 0 1.5px var(--brand)' } },
  ghost:     { rest: { background: 'transparent', color: 'var(--brand)' },
               hover: { background: 'var(--accent-quiet)' } },
};

export function Button({
  children, variant = 'primary', size = 'md', block = false,
  disabled = false, iconLeft, iconRight, as = 'button', style, ...rest
}) {
  const El = as;
  const [hover, setHover] = React.useState(false);
  const [press, setPress] = React.useState(false);
  const v = variants[variant] || variants.primary;
  return (
    <El
      disabled={El === 'button' ? disabled : undefined}
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => { setHover(false); setPress(false); }}
      onMouseDown={() => setPress(true)}
      onMouseUp={() => setPress(false)}
      style={{
        ...base, ...sizes[size], ...v.rest,
        ...(hover && !disabled ? v.hover : null),
        width: block ? '100%' : undefined,
        transform: press ? 'scale(0.97)' : (hover && !disabled ? 'translateY(-2px)' : 'none'),
        opacity: disabled ? 0.5 : 1,
        pointerEvents: disabled ? 'none' : undefined,
        ...style,
      }}
      {...rest}
    >
      {iconLeft}
      {children}
      {iconRight}
    </El>
  );
}
