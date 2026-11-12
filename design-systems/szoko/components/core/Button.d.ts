import * as React from 'react';

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  /** Visual style. @default 'primary' */
  variant?: 'primary' | 'accent' | 'secondary' | 'ghost';
  /** @default 'md' */
  size?: 'sm' | 'md' | 'lg';
  /** Stretch to container width. @default false */
  block?: boolean;
  disabled?: boolean;
  /** Node rendered before the label (e.g. an <Icon/>). */
  iconLeft?: React.ReactNode;
  /** Node rendered after the label. */
  iconRight?: React.ReactNode;
  /** Render as a different element, e.g. 'a'. @default 'button' */
  as?: keyof JSX.IntrinsicElements;
}

/**
 * Primary call-to-action button. Pill shaped, warm chocolate/caramel fills,
 * gentle lift on hover and settle on press.
 *
 * @startingPoint section="Core" subtitle="Pill CTA — brand, accent, secondary, ghost" viewport="700x200"
 */
export function Button(props: ButtonProps): JSX.Element;
