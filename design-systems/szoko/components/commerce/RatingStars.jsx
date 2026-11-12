import React from 'react';
import { StarIcon } from '../core/Icon';

export function RatingStars({ value = 0, count, size = 16, showValue = false, style, ...rest }) {
  const full = Math.round(value);
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-2)', color: 'var(--gold-500)', ...style }} {...rest}>
      <span style={{ display: 'inline-flex', gap: 2 }}>
        {[0, 1, 2, 3, 4].map((i) => <StarIcon key={i} size={size} filled={i < full} />)}
      </span>
      {showValue && (
        <span style={{ fontFamily: 'var(--font-mono)', fontSize: 'var(--text-xs)', color: 'var(--text-muted)' }}>
          {value.toFixed(1)}{count != null ? ` (${count})` : ''}
        </span>
      )}
    </span>
  );
}
