import * as React from 'react';

export interface SelectOption { value: string; label: string; }

export interface SelectProps extends React.SelectHTMLAttributes<HTMLSelectElement> {
  label?: string;
  hint?: string;
  /** Options as plain strings or {value,label} objects. */
  options?: (string | SelectOption)[];
}
/** Styled native select with chevron affordance and focus ring. */
export function Select(props: SelectProps): JSX.Element;
