'use client';

import React from 'react';
import { Icon } from '../core/Icon';
import { Badge } from '../core/Badge';

export interface SidebarItem {
  value: string;
  label: string;
  /** Icon name from public/icons — required; every nav row carries a glyph. */
  icon: string;
  /** Trailing count for queues needing attention. */
  count?: number;
}

export interface SidebarSection {
  /** Uppercase group label; hidden when collapsed. */
  title?: string;
  items: SidebarItem[];
}

/** The console's navy primary navigation rail, grouped into labelled sections. */
export interface SidebarNavProps {
  sections: SidebarSection[];
  active?: string;
  onSelect?: (value: string) => void;
  /** Icon-only 68px rail. */
  collapsed?: boolean;
  /** Slot above the sections — the logo lockup. */
  header?: React.ReactNode;
  /** Slot pinned to the bottom — account switcher, support link. */
  footer?: React.ReactNode;
  style?: React.CSSProperties;
}

function Item({ item, active, collapsed, onSelect }: {
  item: SidebarItem; active?: string; collapsed?: boolean; onSelect?: (value: string) => void;
}) {
  const [hover, setHover] = React.useState(false);
  const on = active === item.value;
  return (
    <button
      type="button"
      aria-current={on ? 'page' : undefined}
      title={collapsed ? item.label : undefined}
      onClick={() => onSelect && onSelect(item.value)}
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      style={{
        display: 'flex', alignItems: 'center', gap: 'var(--space-3)', width: '100%',
        height: 38, padding: collapsed ? 0 : '0 10px', justifyContent: collapsed ? 'center' : 'flex-start',
        border: 0, cursor: 'pointer', textAlign: 'start',
        borderRadius: 'var(--radius-md)',
        background: on ? 'var(--alpha-white-16)' : hover ? 'var(--alpha-white-08)' : 'transparent',
        color: on ? 'var(--text-inverse)' : 'rgba(255,255,255,0.72)',
        font: (on ? 'var(--weight-semibold) ' : 'var(--weight-regular) ') + 'var(--text-base)/1 var(--font-sans)',
        transition: 'var(--transition-control)',
      }}
    >
      <Icon name={item.icon} size={18} color={on ? 'var(--plum-300)' : 'currentColor'} />
      {!collapsed && <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{item.label}</span>}
      {!collapsed && item.count != null && <Badge size="sm" tone="inverse">{item.count}</Badge>}
    </button>
  );
}

export function SidebarNav({ sections = [], active, onSelect, collapsed = false, header, footer, style, ...rest }: SidebarNavProps) {
  return (
    <nav
      {...rest}
      style={{
        display: 'flex', flexDirection: 'column',
        width: collapsed ? 'var(--sidebar-width-collapsed)' : 'var(--sidebar-width)',
        flex: 'none', height: '100%', padding: 'var(--space-4) var(--space-3)',
        gap: 'var(--space-5)', background: 'var(--gradient-ink)',
        borderInlineEnd: '1px solid var(--border-inverse)', color: 'var(--text-inverse)',
        transition: 'width var(--duration-base) var(--ease-standard)', overflow: 'hidden',
        ...style,
      }}
    >
      {header}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)', flex: 1, minHeight: 0, overflowY: 'auto', overflowX: 'hidden' }}>
        {sections.map((sec, i) => (
          <div key={sec.title || i} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-1)' }}>
            {sec.title && !collapsed && (
              <span style={{
                font: 'var(--weight-semibold) var(--text-2xs)/1.2 var(--font-sans)',
                letterSpacing: 'var(--tracking-caps)', textTransform: 'uppercase',
                color: 'rgba(255,255,255,0.42)', padding: '0 10px', marginBottom: 'var(--space-2)',
              }}>{sec.title}</span>
            )}
            {sec.items.map((it) => <Item key={it.value} item={it} active={active} collapsed={collapsed} onSelect={onSelect} />)}
          </div>
        ))}
      </div>
      {footer && <div style={{ flex: 'none' }}>{footer}</div>}
    </nav>
  );
}
