'use client';

import React from 'react';
import { Icon } from '../core/Icon';

/** Multi-select box with optional description line. Controlled. */
export interface CheckboxProps {
  label?: React.ReactNode;
  /** Secondary line under the label for policy/consent detail. */
  description?: React.ReactNode;
  checked?: boolean;
  /** Mixed state for "select all" headers. */
  indeterminate?: boolean;
  disabled?: boolean;
  name?: string;
  value?: string;
  id?: string;
  onChange?: (checked: boolean, e: React.ChangeEvent<HTMLInputElement>) => void;
  style?: React.CSSProperties;
}

export function Checkbox({ label, description, checked = false, indeterminate = false, disabled = false, onChange, id, style, ...rest }: CheckboxProps) {
  const generated = React.useId();
  const uid = id || generated;
  const on = checked || indeterminate;
  return (
    <label
      htmlFor={uid}
      style={{
        display: 'inline-flex', alignItems: 'flex-start', gap: 'var(--space-3)',
        cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.5 : 1, ...style,
      }}
    >
      <input
        id={uid} type="checkbox" checked={checked} disabled={disabled}
        onChange={(e) => onChange && onChange(e.target.checked, e)}
        {...rest}
        style={{ position: 'absolute', opacity: 0, width: 0, height: 0 }}
      />
      <span style={{
        display: 'inline-flex', alignItems: 'center', justifyContent: 'center', flex: 'none',
        width: 18, height: 18, marginTop: 1, borderRadius: 'var(--radius-xs)',
        background: on ? 'var(--brand)' : 'var(--surface-card)',
        border: '1px solid ' + (on ? 'var(--brand)' : 'var(--border-strong)'),
        color: 'var(--brand-on)', transition: 'var(--transition-control)',
      }}>
        {indeterminate ? <span style={{ width: 9, height: 2, background: 'currentColor', borderRadius: 1 }} /> : checked ? <Icon name="check" size={13} /> : null}
      </span>
      {(label || description) && (
        <span style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          {label && <span style={{ font: 'var(--type-body)', color: 'var(--text-primary)' }}>{label}</span>}
          {description && <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{description}</span>}
        </span>
      )}
    </label>
  );
}
