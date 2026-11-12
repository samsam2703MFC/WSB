import React from 'react';

const sizes = { sm: 34, md: 42, lg: 50 };
const iconSizes = { sm: 18, md: 20, lg: 24 };

const variants = {
  solid:   { rest: { background: 'var(--brand)', color: 'var(--text-inverse)' }, hover: { background: 'var(--brand-hover)' } },
  soft:    { rest: { background: 'var(--surface-raised)', color: 'var(--brand)' }, hover: { background: 'var(--accent-quiet)' } },
  ghost:   { rest: { background: 'transparent', color: 'var(--text-body)' }, hover: { background: 'var(--surface-raised)' } },
  outline: { rest: { background: 'var(--surface-card)', color: 'var(--brand)', boxShadow: 'inset 0 0 0 1.5px var(--border-default)' }, hover: { background: 'var(--surface-raised)' } },
};

export function IconButton({
  children, label, variant = 'ghost', size = 'md', disabled = false, style, ...rest
}) {
  const [hover, setHover] = React.useState(false);
  const [press, setPress] = React.useState(false);
  const v = variants[variant] || variants.ghost;
  const dim = sizes[size];
  return (
    <button
      aria-label={label}
      title={label}
      disabled={disabled}
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => { setHover(false); setPress(false); }}
      onMouseDown={() => setPress(true)}
      onMouseUp={() => setPress(false)}
      style={{
        width: dim, height: dim,
        display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
        border: 'none', cursor: 'pointer', borderRadius: 'var(--radius-pill)',
        transition: 'transform var(--dur-fast) var(--ease-soft), background var(--dur-base) var(--ease-out)',
        ...v.rest, ...(hover && !disabled ? v.hover : null),
        transform: press ? 'scale(0.92)' : 'none',
        opacity: disabled ? 0.5 : 1, pointerEvents: disabled ? 'none' : undefined,
        ...style,
      }}
      {...rest}
    >
      {React.isValidElement(children) ? React.cloneElement(children, { size: children.props.size || iconSizes[size] }) : children}
    </button>
  );
}
