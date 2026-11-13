import type { CSSProperties } from 'react';

/**
 * Landing layout rules from the design system's readme: 1200px content width,
 * 40px gutters, 96px section rhythm, alternating white / --surface-page / ink and
 * never two dark sections in a row.
 */
export const SECTION: CSSProperties = {
  maxWidth: 'var(--content-max)',
  margin: '0 auto',
  padding: '0 var(--space-10)',
};

export const eyebrow = 'fb-eyebrow';
