'use client';

import React from 'react';

/** Single-choice control. Group several with a shared `name`. */
export interface RadioProps {
  label?: React.ReactNode;
  description?: React.ReactNode;
  checked?: boolean;
  disabled?: boolean;
  /** Shared group name — required for keyboard grouping. */
  name?: string;
  value?: string;
  id?: string;
  onChange?: (value: string | undefined, e: React.ChangeEvent<HTMLInputElement>) => void;
  style?: React.CSSProperties;
}

export function Radio({ label, description, checked = false, disabled = false, name, value, onChange, id, style, ...rest }: RadioProps) {
  const generated = React.useId();
  const uid = id || generated;
  return (
    <label
      htmlFor={uid}
      style={{
        display: 'inline-flex', alignItems: 'flex-start', gap: 'var(--space-3)',
        cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.5 : 1, ...style,
      }}
    >
      <input
        id={uid} type="radio" name={name} value={value} checked={checked} disabled={disabled}
        onChange={(e) => onChange && onChange(value, e)}
        {...rest}
        style={{ position: 'absolute', opacity: 0, width: 0, height: 0 }}
      />
      <span style={{
        display: 'inline-flex', alignItems: 'center', justifyContent: 'center', flex: 'none',
        width: 18, height: 18, marginTop: 1, borderRadius: 'var(--radius-circle)',
        background: 'var(--surface-card)',
        border: '1px solid ' + (checked ? 'var(--brand)' : 'var(--border-strong)'),
        boxShadow: checked ? 'inset 0 0 0 1px var(--brand)' : 'none',
        transition: 'var(--transition-control)',
      }}>
        {checked && <span style={{ width: 8, height: 8, borderRadius: 'var(--radius-circle)', background: 'var(--brand)' }} />}
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
