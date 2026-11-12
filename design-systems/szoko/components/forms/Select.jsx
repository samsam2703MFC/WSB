import React from 'react';
import { Icon } from '../core/Icon';

export function Select({ label, hint, options = [], id, style, ...rest }) {
  const [focus, setFocus] = React.useState(false);
  const uid = id || React.useId();
  return (
    <label htmlFor={uid} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)', fontFamily: 'var(--font-sans)' }}>
      {label && (
        <span style={{ fontSize: 'var(--text-sm)', fontWeight: 'var(--weight-semibold)', color: 'var(--text-strong)' }}>{label}</span>
      )}
      <span style={{
        position: 'relative', display: 'flex', alignItems: 'center',
        background: 'var(--surface-card)', borderRadius: 'var(--radius-md)',
        boxShadow: focus ? 'inset 0 0 0 1.5px var(--brand), var(--ring)' : 'inset 0 0 0 1.5px var(--border-default)',
        transition: 'box-shadow var(--dur-base) var(--ease-out)',
      }}>
        <select
          id={uid}
          onFocus={() => setFocus(true)}
          onBlur={() => setFocus(false)}
          style={{
            appearance: 'none', WebkitAppearance: 'none', border: 'none', outline: 'none',
            background: 'transparent', width: '100%', padding: '12px 40px 12px 14px',
            fontFamily: 'var(--font-sans)', fontSize: 'var(--text-md)', color: 'var(--text-body)',
            cursor: 'pointer', ...style,
          }}
          {...rest}
        >
          {options.map((o) => {
            const val = typeof o === 'string' ? o : o.value;
            const lbl = typeof o === 'string' ? o : o.label;
            return <option key={val} value={val}>{lbl}</option>;
          })}
        </select>
        <span style={{ position: 'absolute', right: 12, pointerEvents: 'none', color: 'var(--text-muted)', display: 'inline-flex' }}>
          <Icon name="chevronDown" size={18} />
        </span>
      </span>
      {hint && <span style={{ fontSize: 'var(--text-xs)', color: 'var(--text-muted)' }}>{hint}</span>}
    </label>
  );
}
