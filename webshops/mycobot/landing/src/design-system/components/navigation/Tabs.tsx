'use client';

import React from 'react';
import { Icon } from '../core/Icon';
import { Badge } from '../core/Badge';

export interface TabItem {
  value: string;
  label: React.ReactNode;
  /** Icon name from public/icons. */
  icon?: string;
  /** Trailing count badge. */
  count?: number;
}

/**
 * Section switcher. `underline` for page-level views, `segmented` for in-card scoping.
 */
export interface TabsProps {
  items: TabItem[];
  /** Active tab value; falls back to the first item. */
  value?: string;
  onChange?: (value: string) => void;
  variant?: 'underline' | 'segmented';
  size?: 'sm' | 'md';
  style?: React.CSSProperties;
}

export function Tabs({ items = [], value, onChange, variant = 'underline', size = 'md', style, ...rest }: TabsProps) {
  const active = value != null ? value : items[0] && items[0].value;
  const underline = variant === 'underline';
  const pad = size === 'sm' ? '0 10px' : '0 14px';
  const height = size === 'sm' ? 32 : 38;
  return (
    <div
      role="tablist"
      {...rest}
      style={{
        display: 'flex', alignItems: 'center', gap: underline ? 'var(--space-5)' : 'var(--space-1)',
        borderBottom: underline ? '1px solid var(--border-default)' : 'none',
        background: underline ? 'transparent' : 'var(--surface-sunken)',
        padding: underline ? 0 : 'var(--space-1)',
        borderRadius: underline ? 0 : 'var(--radius-md)',
        ...style,
      }}
    >
      {items.map((it) => {
        const on = it.value === active;
        return (
          <button
            key={it.value}
            type="button"
            role="tab"
            aria-selected={on}
            onClick={() => onChange && onChange(it.value)}
            style={{
              display: 'inline-flex', alignItems: 'center', gap: 'var(--space-2)',
              height, padding: underline ? '0 2px' : pad, border: 0, cursor: 'pointer',
              background: underline ? 'transparent' : on ? 'var(--surface-card)' : 'transparent',
              boxShadow: !underline && on ? 'var(--shadow-xs)' : 'none',
              borderRadius: underline ? 0 : 'var(--radius-sm)',
              borderBottom: underline ? '2px solid ' + (on ? 'var(--brand)' : 'transparent') : 'none',
              marginBottom: underline ? -1 : 0,
              font: (on ? 'var(--weight-semibold) ' : 'var(--weight-medium) ') + (size === 'sm' ? 'var(--text-sm)' : 'var(--text-base)') + '/1 var(--font-sans)',
              color: on ? 'var(--text-primary)' : 'var(--text-secondary)',
              transition: 'var(--transition-control)',
            }}
          >
            {it.icon && <Icon name={it.icon} size={15} />}
            {it.label}
            {it.count != null && <Badge size="sm" tone={on ? 'brand' : 'neutral'}>{it.count}</Badge>}
          </button>
        );
      })}
    </div>
  );
}
