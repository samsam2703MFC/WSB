import * as React from 'react';

export interface IconButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  /** Accessible label (also used as title tooltip). */
  label: string;
  /** @default 'ghost' */
  variant?: 'solid' | 'soft' | 'ghost' | 'outline';
  /** @default 'md' */
  size?: 'sm' | 'md' | 'lg';
  disabled?: boolean;
  /** A single <Icon/> element (size is injected automatically). */
  children: React.ReactNode;
}
/** Circular icon-only button — header actions, card wishlist, steppers. */
export function IconButton(props: IconButtonProps): JSX.Element;
