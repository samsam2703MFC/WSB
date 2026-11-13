import * as React from 'react';

export interface SectionHeadingProps extends React.HTMLAttributes<HTMLDivElement> {
  /** Uppercase mono eyebrow above the title. */
  eyebrow?: string;
  /** Serif display title. */
  title?: string;
  /** Supporting lead paragraph. */
  lead?: string;
  /** @default 'left' */
  align?: 'left' | 'center';
  /** Use light text for placement on chocolate panels. @default false */
  invert?: boolean;
}
/** Editorial section header — eyebrow + serif title + lead. */
export function SectionHeading(props: SectionHeadingProps): JSX.Element;
