import * as React from 'react';

export interface BadgeProps extends React.HTMLAttributes<HTMLSpanElement> {
  /** @default 'new' */
  tone?: 'sale' | 'new' | 'gold' | 'soft';
  children: React.ReactNode;
}
/** Marketing flag pinned to product imagery — "New", "-20%", "Bestseller". */
export function Badge(props: BadgeProps): JSX.Element;
