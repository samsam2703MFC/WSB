import * as React from 'react';

export interface CardProps extends React.HTMLAttributes<HTMLDivElement> {
  /** CSS padding value. @default 'var(--space-6)' */
  padding?: string;
  /** Enable gentle lift + deeper shadow on hover. @default false */
  hover?: boolean;
  /** Surface tone. @default 'card' */
  tone?: 'card' | 'raised' | 'choco';
  /** CSS border-radius. @default 'var(--radius-lg)' */
  radius?: string;
  children: React.ReactNode;
}
/** Warm surface container with soft brown-tinted shadow; optional hover lift. */
export function Card(props: CardProps): JSX.Element;
