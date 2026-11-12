import * as React from 'react';

export type IconName =
  | 'bag' | 'heart' | 'search' | 'plus' | 'minus' | 'check' | 'x'
  | 'chevronDown' | 'chevronRight' | 'arrowRight' | 'user' | 'leaf'
  | 'truck' | 'gift';

export interface IconProps extends React.SVGProps<SVGSVGElement> {
  /** Which glyph to render. @default 'bag' */
  name?: IconName;
  /** Pixel size (width & height). @default 20 */
  size?: number;
  /** Stroke width. @default 1.75 */
  strokeWidth?: number;
}
/** Line icon (Lucide-derived), currentColor, rounded caps. */
export function Icon(props: IconProps): JSX.Element;

export interface StarIconProps extends React.SVGProps<SVGSVGElement> {
  size?: number;
  filled?: boolean;
  strokeWidth?: number;
}
/** Star glyph used by RatingStars; can render filled. */
export function StarIcon(props: StarIconProps): JSX.Element;
