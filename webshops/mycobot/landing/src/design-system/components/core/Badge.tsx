import type { CSSProperties, ReactNode } from 'react';
import { Icon } from './Icon';

/** Read-only status pill. Not interactive — use Tag for removable filters. */
export interface BadgeProps {
  children?: ReactNode;
  /** `warm` is reserved for the "Nouveau" module badge. */
  tone?: 'neutral' | 'brand' | 'warm' | 'success' | 'warning' | 'danger' | 'info' | 'inverse';
  /** Leading status dot — the house style for location/compliance state. */
  dot?: boolean;
  /** Icon name from public/icons, shown instead of / next to the dot. */
  icon?: string;
  size?: 'sm' | 'md';
  style?: CSSProperties;
}

const TONES = {
  neutral: { background: 'var(--slate-100)', color: 'var(--slate-700)', dot: 'var(--slate-400)' },
  brand: { background: 'var(--surface-brand-subtle)', color: 'var(--plum-700)', dot: 'var(--plum-500)' },
  warm: { background: 'var(--ember-100)', color: 'var(--ember-700)', dot: 'var(--ember-500)' },
  success: { background: 'var(--surface-success-subtle)', color: 'var(--text-success)', dot: 'var(--status-success)' },
  warning: { background: 'var(--surface-warning-subtle)', color: 'var(--text-warning)', dot: 'var(--status-warning)' },
  danger: { background: 'var(--surface-danger-subtle)', color: 'var(--text-danger)', dot: 'var(--status-danger)' },
  info: { background: 'var(--surface-info-subtle)', color: 'var(--blue-700)', dot: 'var(--status-info)' },
  inverse: { background: 'var(--alpha-white-16)', color: 'var(--text-inverse)', dot: 'var(--slate-0)' },
} as const;

export function Badge({ children, tone = 'neutral', dot = false, icon, size = 'md', style, ...rest }: BadgeProps) {
  const t = TONES[tone] || TONES.neutral;
  const dense = size === 'sm';
  return (
    <span
      {...rest}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: dense ? 4 : 6,
        height: dense ? 20 : 24,
        padding: dense ? '0 7px' : '0 9px',
        borderRadius: 'var(--radius-chip)',
        font: 'var(--weight-medium) ' + (dense ? 'var(--text-2xs)' : 'var(--text-xs)') + '/1 var(--font-sans)',
        letterSpacing: '0.01em',
        whiteSpace: 'nowrap',
        background: t.background,
        color: t.color,
        ...style,
      }}
    >
      {dot && <span style={{ width: 6, height: 6, borderRadius: 'var(--radius-circle)', background: t.dot, flex: 'none' }} />}
      {icon && <Icon name={icon} size={dense ? 11 : 12} />}
      {children}
    </span>
  );
}
