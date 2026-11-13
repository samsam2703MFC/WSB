import React from 'react';

export function Input({
  label, hint, error, icon, size = 'md', id, style, ...rest
}) {
  const [focus, setFocus] = React.useState(false);
  const uid = id || React.useId();
  const pad = size === 'lg' ? '15px 16px' : '12px 14px';
  return (
    <label htmlFor={uid} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)', fontFamily: 'var(--font-sans)' }}>
      {label && (
        <span style={{ fontSize: 'var(--text-sm)', fontWeight: 'var(--weight-semibold)', color: 'var(--text-strong)' }}>{label}</span>
      )}
      <span style={{
        display: 'flex', alignItems: 'center', gap: 'var(--space-2)',
        background: 'var(--surface-card)', borderRadius: 'var(--radius-md)',
        padding: pad, color: 'var(--text-muted)',
        boxShadow: error ? 'inset 0 0 0 1.5px var(--danger)'
          : focus ? 'inset 0 0 0 1.5px var(--brand), var(--ring)'
          : 'inset 0 0 0 1.5px var(--border-default)',
        transition: 'box-shadow var(--dur-base) var(--ease-out)',
      }}>
        {icon}
        <input
          id={uid}
          onFocus={() => setFocus(true)}
          onBlur={() => setFocus(false)}
          style={{
            flex: 1, border: 'none', outline: 'none', background: 'transparent',
            fontFamily: 'var(--font-sans)', fontSize: 'var(--text-md)',
            color: 'var(--text-body)', minWidth: 0, ...style,
          }}
          {...rest}
        />
      </span>
      {(hint || error) && (
        <span style={{ fontSize: 'var(--text-xs)', color: error ? 'var(--danger)' : 'var(--text-muted)' }}>
          {error || hint}
        </span>
      )}
    </label>
  );
}
