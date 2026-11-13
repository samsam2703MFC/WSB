import * as React from 'react';

export interface RatingStarsProps extends React.HTMLAttributes<HTMLSpanElement> {
  /** Rating 0–5. @default 0 */
  value?: number;
  /** Review count shown after the value. */
  count?: number;
  /** Star pixel size. @default 16 */
  size?: number;
  /** Show numeric value + count next to the stars. @default false */
  showValue?: boolean;
}
/** Gold five-star rating display with optional numeric value/count. */
export function RatingStars(props: RatingStarsProps): JSX.Element;
