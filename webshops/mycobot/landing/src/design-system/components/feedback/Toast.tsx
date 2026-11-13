import type { CSSProperties, ReactNode } from 'react';
import { Icon } from '../core/Icon';
import { IconButton } from '../core/IconButton';

/** Transient confirmation or alert on a navy card. Stack bottom-end, 12px gap, max 3. */
export interface ToastProps {
  title?: string;
  /** One sentence of detail; omit when the title says everything. */
  message?: string;
  tone?: 'info' | 'success' | 'warning' | 'danger';
  /** Optional inline action — usually a `Button variant="inverse" size="sm"`. */
  action?: ReactNode;
  onDismiss?: () => void;
  style?: CSSProperties;
}

const TONES = {
  info: { icon: 'info', color: 'var(--status-info)' },
  success: { icon: 'circle-check', color: 'var(--status-success)' },
  warning: { icon: 'triangle-alert', color: 'var(--status-warning)' },
  danger: { icon: 'circle-alert', color: 'var(--status-danger)' },
} as const;

export function Toast({ title, message, tone = 'info', action, onDismiss, style, ...rest }: ToastProps) {
  const t = TONES[tone] || TONES.info;
  return (
    <div
      role="status"
      {...rest}
      style={{
        display: 'flex', alignItems: 'flex-start', gap: 'var(--space-3)',
        width: 380, maxWidth: '100%', padding: 'var(--space-4)',
        background: 'var(--surface-inverse)', color: 'var(--text-inverse)',
        border: '1px solid var(--border-inverse)', borderRadius: 'var(--radius-lg)',
        boxShadow: 'var(--shadow-lg)', ...style,
      }}
    >
      <Icon name={t.icon} size={17} color={t.color} style={{ marginTop: 1 }} />
      <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-1)', flex: 1, minWidth: 0 }}>
        {title && <span style={{ font: 'var(--weight-semibold) var(--text-base)/1.3 var(--font-sans)' }}>{title}</span>}
        {message && <span style={{ font: 'var(--type-body-sm)', color: 'var(--text-inverse-secondary)' }}>{message}</span>}
        {action && <div style={{ marginTop: 'var(--space-2)' }}>{action}</div>}
      </div>
      {onDismiss && <IconButton icon="x" label="Fermer" variant="inverse" size="sm" onClick={onDismiss} style={{ background: 'transparent', border: 0, marginTop: -4, marginInlineEnd: -4 }} />}
    </div>
  );
}
