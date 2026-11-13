'use client';

import React from 'react';

/** Instant-effect toggle for settings. Use Checkbox inside forms that get submitted. */
export interface SwitchProps {
  label?: React.ReactNode;
  description?: React.ReactNode;
  checked?: boolean;
  disabled?: boolean;
  size?: 'sm' | 'md';
  id?: string;
  onChange?: (checked: boolean, e: React.ChangeEvent<HTMLInputElement>) => void;
  style?: React.CSSProperties;
}

export function Switch({ label, description, checked = false, disabled = false, size = 'md', onChange, id, style, ...rest }: SwitchProps) {
  const generated = React.useId();
  const uid = id || generated;
  const w = size === 'sm' ? 32 : 40;
  const h = size === 'sm' ? 18 : 22;
  const knob = h - 6;
  return (
    <label
      htmlFor={uid}
      style={{
        display: 'inline-flex', alignItems: 'center', gap: 'var(--space-3)',
        cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.5 : 1, ...style,
      }}
    >
      <input
        id={uid} type="checkbox" role="switch" checked={checked} disabled={disabled}
        onChange={(e) => onChange && onChange(e.target.checked, e)}
        {...rest}
        style={{ position: 'absolute', opacity: 0, width: 0, height: 0 }}
      />
      <span style={{
        position: 'relative', flex: 'none', width: w, height: h, borderRadius: 'var(--radius-pill)',
        background: checked ? 'var(--brand)' : 'var(--slate-300)',
        transition: 'background-color var(--duration-base) var(--ease-standard)',
      }}>
        {/* insetInlineStart so the knob travels the correct way under dir="rtl". */}
        <span style={{
          position: 'absolute', top: 3, insetInlineStart: checked ? w - knob - 3 : 3,
          width: knob, height: knob, borderRadius: 'var(--radius-circle)',
          background: 'var(--slate-0)', boxShadow: 'var(--shadow-xs)',
          transition: 'inset-inline-start var(--duration-base) var(--ease-standard)',
        }} />
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
