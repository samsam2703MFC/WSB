'use client';

import React from 'react';

/** Multi-line field: the contact form message and every translation value in the back office. */
export interface TextareaProps extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
  label?: string;
  hint?: React.ReactNode;
  error?: string;
  rows?: number;
  maxLength?: number;
  /** Shows a "used / max" counter — requires maxLength and a controlled value. */
  showCount?: boolean;
  wrapperStyle?: React.CSSProperties;
}

export function Textarea({
  label, hint, error, rows = 4, maxLength, showCount = false, disabled = false, required = false,
  value, id, style, wrapperStyle, onChange, ...rest
}: TextareaProps) {
  const [focus, setFocus] = React.useState(false);
  const generated = React.useId();
  const uid = id || generated;
  const len = typeof value === 'string' ? value.length : 0;
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)', minWidth: 0, ...wrapperStyle }}>
      {label && (
        <label htmlFor={uid} style={{ font: 'var(--type-label)', color: 'var(--text-primary)' }}>
          {label}{required && <span style={{ color: 'var(--status-danger)' }}> *</span>}
        </label>
      )}
      <textarea
        id={uid}
        rows={rows}
        maxLength={maxLength}
        disabled={disabled}
        required={required}
        value={value}
        onChange={onChange}
        aria-invalid={error ? true : undefined}
        onFocus={() => setFocus(true)}
        onBlur={() => setFocus(false)}
        {...rest}
        style={{
          width: '100%', padding: 'var(--space-3)', resize: 'vertical',
          font: 'var(--type-body)', color: disabled ? 'var(--text-disabled)' : 'var(--text-primary)',
          background: disabled ? 'var(--surface-sunken)' : 'var(--surface-card)',
          border: '1px solid ' + (error ? 'var(--border-danger)' : focus ? 'var(--brand)' : 'var(--border-default)'),
          borderRadius: 'var(--radius-control)',
          boxShadow: focus ? (error ? 'var(--shadow-focus-danger)' : 'var(--shadow-focus)') : 'none',
          outline: 'none', transition: 'var(--transition-control)', ...style,
        }}
      />
      {(error || hint || (showCount && maxLength)) && (
        <div style={{ display: 'flex', gap: 'var(--space-3)', font: 'var(--type-caption)', color: error ? 'var(--text-danger)' : 'var(--text-tertiary)' }}>
          <span>{error || hint}</span>
          {showCount && maxLength && <span className="fb-num" style={{ marginInlineStart: 'auto' }}>{len} / {maxLength}</span>}
        </div>
      )}
    </div>
  );
}
