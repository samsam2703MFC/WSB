'use client';

import React from 'react';
import { Icon } from './Icon';

/** Interactive chip: applied filters, selectable facets, editable attribute lists. */
export interface TagProps {
  children?: React.ReactNode;
  /** Icon name from public/icons. */
  icon?: string;
  /** Renders a trailing × and calls this on click. */
  onRemove?: (e: React.MouseEvent) => void;
  /** Plum-tinted selected state. */
  selected?: boolean;
  /**
   * Pointer + hover wash without a handler — for a Tag wrapped in a link, where
   * navigation is the anchor's job. Avoids a no-op onClick, which a server
   * component cannot pass to a client component anyway.
   */
  interactive?: boolean;
  onClick?: (e: React.MouseEvent) => void;
  style?: React.CSSProperties;
}

export function Tag({ children, icon, onRemove, selected = false, interactive = false, onClick, style, ...rest }: TagProps) {
  const [hover, setHover] = React.useState(false);
  const clickable = Boolean(onClick) || interactive;
  return (
    <span
      onClick={onClick}
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      {...rest}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        height: 28,
        padding: onRemove ? '0 6px 0 10px' : '0 10px',
        borderRadius: 'var(--radius-chip)',
        font: 'var(--weight-medium) var(--text-sm)/1 var(--font-sans)',
        cursor: clickable ? 'pointer' : 'default',
        background: selected ? 'var(--surface-brand-subtle)' : hover && clickable ? 'var(--surface-hover)' : 'var(--surface-card)',
        color: selected ? 'var(--text-brand)' : 'var(--text-secondary)',
        border: '1px solid ' + (selected ? 'var(--border-brand)' : 'var(--border-default)'),
        transition: 'var(--transition-control)',
        ...style,
      }}
    >
      {icon && <Icon name={icon} size={13} />}
      {children}
      {onRemove && (
        <button
          type="button"
          aria-label="Remove"
          onClick={(e) => { e.stopPropagation(); onRemove(e); }}
          style={{
            display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
            width: 18, height: 18, marginLeft: 1, padding: 0, border: 0, cursor: 'pointer',
            borderRadius: 'var(--radius-circle)', background: 'transparent', color: 'inherit', opacity: 0.7,
          }}
        >
          <Icon name="x" size={11} />
        </button>
      )}
    </span>
  );
}
