'use client';

import React from 'react';

/** Hover/focus label for icon-only controls and truncated values. */
export interface TooltipProps {
  /** The trigger. Wrap exactly one interactive element. */
  children: React.ReactNode;
  /** Short label — a fragment, no full stop. */
  content: React.ReactNode;
  placement?: 'top' | 'bottom' | 'left' | 'right';
  style?: React.CSSProperties;
}

export function Tooltip({ children, content, placement = 'top', style, ...rest }: TooltipProps) {
  const [open, setOpen] = React.useState(false);
  const pos: React.CSSProperties = {
    top: { bottom: '100%', left: '50%', transform: 'translate(-50%, -8px)' },
    bottom: { top: '100%', left: '50%', transform: 'translate(-50%, 8px)' },
    left: { right: '100%', top: '50%', transform: 'translate(-8px, -50%)' },
    right: { left: '100%', top: '50%', transform: 'translate(8px, -50%)' },
  }[placement];
  return (
    <span
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
      onFocus={() => setOpen(true)}
      onBlur={() => setOpen(false)}
      {...rest}
      style={{ position: 'relative', display: 'inline-flex', ...style }}
    >
      {children}
      {open && content && (
        <span
          role="tooltip"
          style={{
            position: 'absolute', ...pos, zIndex: 70, pointerEvents: 'none',
            padding: '6px 9px', maxWidth: 240, width: 'max-content',
            background: 'var(--navy-900)', color: 'var(--text-inverse)',
            font: 'var(--type-body-sm)', borderRadius: 'var(--radius-sm)',
            boxShadow: 'var(--shadow-md)', textAlign: 'center',
          }}
        >{content}</span>
      )}
    </span>
  );
}
