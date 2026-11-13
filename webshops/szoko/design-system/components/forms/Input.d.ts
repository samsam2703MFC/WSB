import * as React from 'react';

export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  /** Helper text shown below. */
  hint?: string;
  /** Error message; also switches the field to danger styling. */
  error?: string;
  /** Leading icon node (e.g. an <Icon/>). */
  icon?: React.ReactNode;
  /** @default 'md' */
  size?: 'md' | 'lg';
}
/** Text input with warm inset border, focus ring, label, hint/error. */
export function Input(props: InputProps): JSX.Element;
