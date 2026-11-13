import * as React from 'react';

export interface QuantityStepperProps {
  /** Current quantity. @default 1 */
  value?: number;
  /** @default 1 */
  min?: number;
  /** @default 99 */
  max?: number;
  onChange?: (value: number) => void;
  /** @default 'md' */
  size?: 'sm' | 'md';
  style?: React.CSSProperties;
}
/** Pill −/＋ quantity control for basket lines and product pages. */
export function QuantityStepper(props: QuantityStepperProps): JSX.Element;
