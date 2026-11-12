import React from 'react';

// Editorial section header: uppercase eyebrow + serif title + optional lead.
export function SectionHeading({
  eyebrow, title, lead, align = 'left', invert = false, style, ...rest
}) {
  const strong = invert ? 'var(--text-inverse)' : 'var(--text-strong)';
  const body = invert ? 'var(--cream-200)' : 'var(--text-muted)';
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)', textAlign: align, alignItems: align === 'center' ? 'center' : 'flex-start', ...style }} {...rest}>
      {eyebrow && (
        <span style={{
          fontFamily: 'var(--font-mono)', fontSize: 'var(--text-xs)', textTransform: 'uppercase',
          letterSpacing: 'var(--tracking-caps)', color: 'var(--accent)', fontWeight: 'var(--weight-medium)',
        }}>{eyebrow}</span>
      )}
      {title && (
        <h2 style={{
          fontFamily: 'var(--font-display)', fontSize: 'var(--text-3xl)', lineHeight: 'var(--leading-tight)',
          fontWeight: 700, color: strong, margin: 0, letterSpacing: 'var(--tracking-tight)', textWrap: 'balance',
        }}>{title}</h2>
      )}
      {lead && (
        <p style={{
          fontFamily: 'var(--font-sans)', fontSize: 'var(--text-lg)', lineHeight: 'var(--leading-normal)',
          color: body, margin: 0, maxWidth: '52ch', textWrap: 'pretty',
        }}>{lead}</p>
      )}
    </div>
  );
}
