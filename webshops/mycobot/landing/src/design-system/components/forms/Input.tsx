'use client';

import React from 'react';
import { Icon } from '../core/Icon';

/**
 * Single-line text field with label, hint, error and icon/affix slots.
 */
export interface InputProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'size' | 'prefix'> {
  /** Sentence-case label above the field. */
  label?: string;
  /** Helper text below the field; hidden while `error` is set. */
  hint?: React.ReactNode;
  /** Error message — turns the border red and shows a red focus ring. */
  error?: string;
  /** Icon name from public/icons, inside the field on the left (e.g. "search"). */
  iconLeft?: string;
  iconRight?: string;
  size?: 'sm' | 'md' | 'lg';
  /** Static leading text, e.g. "€". */
  prefix?: string;
  /** Static trailing text, e.g. "%" or "/mois". */
  suffix?: string;
  /** Styles for the outer label+field+hint stack. */
  wrapperStyle?: React.CSSProperties;
}

export function Input({
  label, hint, error, iconLeft, iconRight, size = 'md', prefix, suffix,
  disabled = false, required = false, id, style, wrapperStyle, ...rest
}: InputProps) {
  const [focus, setFocus] = React.useState(false);
  const generated = React.useId();
  const uid = id || generated;
  const height = size === 'sm' ? 32 : size === 'lg' ? 46 : 38;
  const borderColor = error ? 'var(--border-danger)' : focus ? 'var(--brand)' : 'var(--border-default)';
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)', minWidth: 0, ...wrapperStyle }}>
      {label && (
        <label htmlFor={uid} style={{ font: 'var(--type-label)', color: 'var(--text-primary)' }}>
          {label}{required && <span style={{ color: 'var(--status-danger)' }}> *</span>}
        </label>
      )}
      <div style={{
        display: 'flex', alignItems: 'center', gap: 'var(--space-2)',
        height, padding: '0 ' + (size === 'sm' ? '10px' : '12px'),
        background: disabled ? 'var(--surface-sunken)' : 'var(--surface-card)',
        border: '1px solid ' + borderColor,
        borderRadius: 'var(--radius-control)',
        boxShadow: focus ? (error ? 'var(--shadow-focus-danger)' : 'var(--shadow-focus)') : 'none',
        transition: 'var(--transition-control)',
        color: disabled ? 'var(--text-disabled)' : 'var(--text-primary)',
      }}>
        {iconLeft && <Icon name={iconLeft} size={15} color="var(--text-tertiary)" />}
        {prefix && <span style={{ font: 'var(--type-body-sm)', color: 'var(--text-tertiary)' }}>{prefix}</span>}
        <input
          id={uid}
          disabled={disabled}
          required={required}
          aria-invalid={error ? true : undefined}
          onFocus={() => setFocus(true)}
          onBlur={() => setFocus(false)}
          {...rest}
          style={{
            flex: 1, minWidth: 0, height: '100%', border: 0, outline: 'none', background: 'transparent',
            font: size === 'sm' ? 'var(--type-body-sm)' : 'var(--type-body)', color: 'inherit', padding: 0, ...style,
          }}
        />
        {suffix && <span style={{ font: 'var(--type-body-sm)', color: 'var(--text-tertiary)' }}>{suffix}</span>}
        {iconRight && <Icon name={iconRight} size={15} color="var(--text-tertiary)" />}
      </div>
      {(error || hint) && (
        <span style={{ font: 'var(--type-caption)', color: error ? 'var(--text-danger)' : 'var(--text-tertiary)' }}>{error || hint}</span>
      )}
    </div>
  );
}
