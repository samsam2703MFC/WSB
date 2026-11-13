'use client';

import React from 'react';

/**
 * The universal surface: 12px radius, 1px slate border, navy-tinted shadow.
 */
export interface CardProps {
  children?: React.ReactNode;
  /** Optional header title (rendered in the display face). */
  title?: React.ReactNode;
  subtitle?: React.ReactNode;
  /** Header-right slot — usually a Button or IconButton. */
  actions?: React.ReactNode;
  /** Footer slot on a faint sunken strip. */
  footer?: React.ReactNode;
  padding?: 'none' | 'sm' | 'md' | 'lg';
  /** default = white, sunken = grey, brand = plum tint, inverse = navy gradient. */
  tone?: 'default' | 'sunken' | 'brand' | 'inverse';
  /** Adds hover lift + pointer cursor for whole-card links. */
  interactive?: boolean;
  as?: 'section' | 'article' | 'div' | 'aside' | 'li';
  onClick?: (e: React.MouseEvent) => void;
  style?: React.CSSProperties;
}

export function Card({
  children, title, subtitle, actions, footer, padding = 'md', tone = 'default',
  interactive = false, as = 'section', style, ...rest
}: CardProps) {
  const [hover, setHover] = React.useState(false);
  const pad = { none: 0, sm: 'var(--space-4)', md: 'var(--space-5)', lg: 'var(--space-6)' }[padding];
  const tones = {
    default: { background: 'var(--surface-card)', border: '1px solid var(--border-default)', color: 'var(--text-primary)' },
    sunken: { background: 'var(--surface-sunken)', border: '1px solid var(--border-subtle)', color: 'var(--text-primary)' },
    brand: { background: 'var(--surface-brand-subtle)', border: '1px solid var(--plum-100)', color: 'var(--text-primary)' },
    inverse: { background: 'var(--gradient-ink)', border: '1px solid var(--border-inverse)', color: 'var(--text-inverse)' },
  }[tone];
  const Tag = as;
  const hasHeader = Boolean(title || subtitle || actions);
  return (
    <Tag
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      {...rest}
      style={{
        display: 'flex',
        flexDirection: 'column',
        borderRadius: 'var(--radius-card)',
        boxShadow: interactive && hover ? 'var(--shadow-md)' : 'var(--shadow-sm)',
        transform: interactive && hover ? 'translateY(-1px)' : 'none',
        transition: 'var(--transition-surface)',
        cursor: interactive ? 'pointer' : undefined,
        overflow: 'hidden',
        ...tones,
        ...style,
      }}
    >
      {hasHeader && (
        <header style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--space-4)', padding: pad || 'var(--space-5)', paddingBottom: children ? 'var(--space-3)' : pad }}>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-1)', minWidth: 0 }}>
            {title && <h3 style={{ font: 'var(--type-title)', color: 'inherit', letterSpacing: 'var(--tracking-tight)' }}>{title}</h3>}
            {subtitle && <p style={{ font: 'var(--type-body-sm)', color: tone === 'inverse' ? 'var(--text-inverse-secondary)' : 'var(--text-secondary)' }}>{subtitle}</p>}
          </div>
          {actions && <div style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>{actions}</div>}
        </header>
      )}
      {children != null && (
        <div style={{ padding: pad, paddingTop: hasHeader ? 0 : pad, flex: 1, minWidth: 0 }}>{children}</div>
      )}
      {footer && (
        <footer style={{
          padding: padding === 'none' ? 'var(--space-4)' : pad,
          borderTop: '1px solid ' + (tone === 'inverse' ? 'var(--border-inverse)' : 'var(--border-subtle)'),
          background: tone === 'default' ? 'var(--slate-25)' : 'transparent',
          font: 'var(--type-body-sm)',
          color: tone === 'inverse' ? 'var(--text-inverse-secondary)' : 'var(--text-secondary)',
        }}>{footer}</footer>
      )}
    </Tag>
  );
}
