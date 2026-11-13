'use client';

import React from 'react';
import { Icon } from './Icon';

/** Square, label-less action for toolbars, table rows and chrome. */
export interface IconButtonProps {
  /** Icon name from public/icons. */
  icon: string;
  /** Required — becomes aria-label and the native tooltip. */
  label: string;
  variant?: 'ghost' | 'secondary' | 'primary' | 'inverse';
  size?: 'sm' | 'md' | 'lg';
  disabled?: boolean;
  /** Held-open / selected state (e.g. an open filter popover). */
  active?: boolean;
  onClick?: (e: React.MouseEvent<HTMLButtonElement>) => void;
  style?: React.CSSProperties;
}

const SIZES = { sm: 30, md: 38, lg: 46 } as const;
const GLYPH = { sm: 15, md: 17, lg: 19 } as const;

export function IconButton({
  icon, label, variant = 'ghost', size = 'md', disabled = false, active = false, onClick, style, ...rest
}: IconButtonProps) {
  const [hover, setHover] = React.useState(false);
  const box = SIZES[size] || SIZES.md;
  const paint: React.CSSProperties = {
    ghost: {
      background: active ? 'var(--surface-active)' : hover ? 'var(--surface-hover)' : 'transparent',
      color: active ? 'var(--text-primary)' : hover ? 'var(--text-primary)' : 'var(--text-secondary)',
      border: '1px solid transparent',
    },
    secondary: {
      background: hover ? 'var(--surface-hover)' : 'var(--surface-card)',
      color: 'var(--text-primary)',
      border: '1px solid ' + (hover ? 'var(--border-strong)' : 'var(--border-default)'),
      boxShadow: 'var(--shadow-xs)',
    },
    primary: {
      background: hover ? 'var(--brand-hover)' : 'var(--brand)',
      color: 'var(--brand-on)',
      border: '1px solid transparent',
      boxShadow: hover ? 'var(--shadow-brand)' : 'var(--shadow-xs)',
    },
    inverse: {
      background: hover ? 'var(--alpha-white-16)' : 'var(--alpha-white-08)',
      color: 'var(--text-inverse)',
      border: '1px solid var(--border-inverse)',
    },
  }[variant];
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      disabled={disabled}
      onClick={onClick}
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      {...rest}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        width: box,
        height: box,
        borderRadius: 'var(--radius-control)',
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.45 : 1,
        transition: 'var(--transition-control)',
        ...paint,
        ...style,
      }}
    >
      <Icon name={icon} size={GLYPH[size] || GLYPH.md} />
    </button>
  );
}
