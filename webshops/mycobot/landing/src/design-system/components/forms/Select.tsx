'use client';

import React from 'react';
import { Icon } from '../core/Icon';

/** Native select styled to match Input, with a lucide chevron. */
export interface SelectOption { value: string; label: string }

export interface SelectProps extends Omit<React.SelectHTMLAttributes<HTMLSelectElement>, 'size'> {
  label?: string;
  hint?: React.ReactNode;
  error?: string;
  /** Plain strings or {value,label} pairs. */
  options?: (SelectOption | string)[];
  size?: 'sm' | 'md' | 'lg';
  wrapperStyle?: React.CSSProperties;
}

export function Select({ label, hint, error, options = [], size = 'md', disabled = false, id, style, wrapperStyle, ...rest }: SelectProps) {
  const [focus, setFocus] = React.useState(false);
  const generated = React.useId();
  const uid = id || generated;
  const height = size === 'sm' ? 32 : size === 'lg' ? 46 : 38;
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)', minWidth: 0, ...wrapperStyle }}>
      {label && <label htmlFor={uid} style={{ font: 'var(--type-label)', color: 'var(--text-primary)' }}>{label}</label>}
      <div style={{
        position: 'relative', display: 'flex', alignItems: 'center', height,
        background: disabled ? 'var(--surface-sunken)' : 'var(--surface-card)',
        border: '1px solid ' + (error ? 'var(--border-danger)' : focus ? 'var(--brand)' : 'var(--border-default)'),
        borderRadius: 'var(--radius-control)',
        boxShadow: focus ? 'var(--shadow-focus)' : 'none',
        transition: 'var(--transition-control)',
      }}>
        <select
          id={uid}
          disabled={disabled}
          onFocus={() => setFocus(true)}
          onBlur={() => setFocus(false)}
          {...rest}
          style={{
            appearance: 'none', WebkitAppearance: 'none', flex: 1, minWidth: 0, height: '100%',
            // Logical padding so the chevron gutter mirrors under dir="rtl".
            paddingBlock: 0,
            paddingInlineStart: size === 'sm' ? '10px' : '12px',
            paddingInlineEnd: '34px',
            border: 0, outline: 'none', background: 'transparent', cursor: disabled ? 'not-allowed' : 'pointer',
            font: size === 'sm' ? 'var(--type-body-sm)' : 'var(--type-body)',
            color: disabled ? 'var(--text-disabled)' : 'var(--text-primary)', ...style,
          }}
        >
          {options.map((o) => {
            const opt = typeof o === 'string' ? { value: o, label: o } : o;
            return <option key={opt.value} value={opt.value}>{opt.label}</option>;
          })}
        </select>
        <Icon name="chevron-down" size={15} color="var(--text-tertiary)" style={{ position: 'absolute', insetInlineEnd: 11, pointerEvents: 'none' }} />
      </div>
      {(error || hint) && <span style={{ font: 'var(--type-caption)', color: error ? 'var(--text-danger)' : 'var(--text-tertiary)' }}>{error || hint}</span>}
    </div>
  );
}
