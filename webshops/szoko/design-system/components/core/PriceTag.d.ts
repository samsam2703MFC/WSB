import * as React from 'react';

export interface PriceTagProps extends React.HTMLAttributes<HTMLSpanElement> {
  /** Current price, e.g. "8.50". */
  amount: string | number;
  /** Original price to strike through when on sale. */
  was?: string | number;
  /** @default '€' */
  currency?: string;
  /** @default 'md' */
  size?: 'sm' | 'md' | 'lg';
}
/** Monospace price with optional struck-through original for sales. */
export function PriceTag(props: PriceTagProps): JSX.Element;
