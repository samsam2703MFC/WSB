import * as React from 'react';

export interface TagProps extends React.HTMLAttributes<HTMLSpanElement> {
  /** @default 'origin' */
  tone?: 'origin' | 'accent' | 'berry' | 'plain';
  /** Optional leading icon node. */
  icon?: React.ReactNode;
  children: React.ReactNode;
}
/** Uppercase mono eyebrow/category label with wide tracking (e.g. "SINGLE ORIGIN"). */
export function Tag(props: TagProps): JSX.Element;
