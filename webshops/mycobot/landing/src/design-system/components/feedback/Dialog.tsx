'use client';

import React from 'react';
import { Icon } from '../core/Icon';
import { IconButton } from '../core/IconButton';

/**
 * Modal for confirmations and short focused tasks. Renders positioned within the nearest
 * positioned ancestor, so wrap console screens in a `position: relative` shell.
 */
export interface DialogProps {
  open?: boolean;
  title?: string;
  /** One or two lines explaining the consequence of the action. */
  description?: string;
  children?: React.ReactNode;
  /** Right-aligned action row — secondary then primary. */
  footer?: React.ReactNode;
  /** sm = 400px, md = 520px, lg = 680px. */
  size?: 'sm' | 'md' | 'lg';
  /** danger / warning add a tinted alert circle beside the title. */
  tone?: 'default' | 'danger' | 'warning';
  /** Shows the close button and enables scrim click-to-dismiss. */
  onClose?: () => void;
  style?: React.CSSProperties;
}

export function Dialog({ open = true, title, description, children, footer, size = 'md', tone = 'default', onClose, style, ...rest }: DialogProps) {
  // Escape closes — a modal that traps keyboard users is an AA failure.
  React.useEffect(() => {
    if (!open || !onClose) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;
  const width = { sm: 400, md: 520, lg: 680 }[size] || 520;
  const accent = { default: null, danger: 'var(--status-danger)', warning: 'var(--status-warning)' }[tone];
  return (
    <div
      style={{
        position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center',
        padding: 'var(--space-6)', background: 'var(--surface-scrim)', backdropFilter: 'var(--blur-panel)', zIndex: 60,
      }}
      onClick={onClose}
    >
      <div
        role="dialog"
        aria-modal="true"
        onClick={(e) => e.stopPropagation()}
        {...rest}
        style={{
          width, maxWidth: '100%', display: 'flex', flexDirection: 'column',
          background: 'var(--surface-card)', borderRadius: 'var(--radius-panel)',
          boxShadow: 'var(--shadow-xl)', overflow: 'hidden',
          ...style,
        }}
      >
        <header style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--space-3)', padding: 'var(--space-6) var(--space-6) var(--space-3)' }}>
          {accent && (
            <span style={{
              display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 32, height: 32, flex: 'none',
              borderRadius: 'var(--radius-circle)', color: accent,
              background: tone === 'danger' ? 'var(--surface-danger-subtle)' : 'var(--surface-warning-subtle)',
            }}>
              <Icon name="triangle-alert" size={17} />
            </span>
          )}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)', minWidth: 0, flex: 1 }}>
            {title && <h2 style={{ font: 'var(--type-heading-4)', letterSpacing: 'var(--tracking-tight)' }}>{title}</h2>}
            {description && <p style={{ font: 'var(--type-body)', color: 'var(--text-secondary)' }}>{description}</p>}
          </div>
          {onClose && <IconButton icon="x" label="Fermer" onClick={onClose} style={{ marginTop: -4, marginInlineEnd: -6 }} />}
        </header>
        {children != null && <div style={{ padding: '0 var(--space-6) var(--space-5)', font: 'var(--type-body)' }}>{children}</div>}
        {footer && (
          <footer style={{
            display: 'flex', justifyContent: 'flex-end', gap: 'var(--space-3)',
            padding: 'var(--space-4) var(--space-6)', background: 'var(--slate-25)',
            borderTop: '1px solid var(--border-subtle)',
          }}>{footer}</footer>
        )}
      </div>
    </div>
  );
}
