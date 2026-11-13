'use client';

import React from 'react';
import { Icon } from './Icon';

/** Primary action control. */
export interface ButtonProps {
  children?: React.ReactNode;
  /**
   * primary = plum fill (back office + in-product), warm = ember fill with navy ink
   * (landing conversion CTAs only), secondary = bordered white, subtle = plum tint,
   * ghost = chrome actions, inverse = on navy, danger = destructive.
   */
  variant?: 'primary' | 'warm' | 'secondary' | 'subtle' | 'ghost' | 'inverse' | 'danger';
  /** sm = 30px (tables, toolbars), md = 38px (default), lg = 46px (landing CTAs). */
  size?: 'sm' | 'md' | 'lg';
  /** Icon name from public/icons rendered before the label. */
  iconLeft?: string;
  /** Icon name rendered after the label — use for "arrow-right" style forward motion. */
  iconRight?: string;
  fullWidth?: boolean;
  disabled?: boolean;
  /** Swaps the leading icon for a pending indicator and blocks clicks. */
  loading?: boolean;
  type?: 'button' | 'submit' | 'reset';
  onClick?: (e: React.MouseEvent<HTMLButtonElement>) => void;
  style?: React.CSSProperties;
}

type ButtonVariant = NonNullable<ButtonProps['variant']>;
type ButtonSize = NonNullable<ButtonProps['size']>;

const SIZES: Record<ButtonSize, { height: number; padding: string; font: string; gap: number; icon: number }> = {
  sm: { height: 30, padding: '0 12px', font: 'var(--weight-medium) var(--text-sm)/1 var(--font-sans)', gap: 6, icon: 14 },
  md: { height: 38, padding: '0 16px', font: 'var(--weight-medium) var(--text-base)/1 var(--font-sans)', gap: 8, icon: 16 },
  lg: { height: 46, padding: '0 22px', font: 'var(--weight-semibold) var(--text-md)/1 var(--font-sans)', gap: 8, icon: 18 },
};

type Paint = React.CSSProperties;

function paint(variant: ButtonVariant, state: 'rest' | 'hover' | 'press'): Paint {
  const v: { rest?: Paint; hover?: Paint; press?: Paint } = {
    primary: {
      rest: { background: 'var(--brand)', color: 'var(--brand-on)', border: '1px solid var(--brand)', boxShadow: 'var(--shadow-xs)' },
      hover: { background: 'var(--brand-hover)', border: '1px solid var(--brand-hover)', boxShadow: 'var(--shadow-brand)' },
      press: { background: 'var(--brand-press)', border: '1px solid var(--brand-press)', boxShadow: 'none' },
    },
    warm: {
      rest: { background: 'var(--cta-warm)', color: 'var(--cta-warm-on)', border: '1px solid var(--cta-warm)', boxShadow: 'var(--shadow-xs)' },
      hover: { background: 'var(--cta-warm-hover)', border: '1px solid var(--cta-warm-hover)', boxShadow: 'var(--shadow-md)' },
      press: { background: 'var(--cta-warm-press)', color: 'var(--slate-0)', border: '1px solid var(--cta-warm-press)', boxShadow: 'none' },
    },
    secondary: {
      rest: { background: 'var(--surface-card)', color: 'var(--text-primary)', border: '1px solid var(--border-default)', boxShadow: 'var(--shadow-xs)' },
      hover: { background: 'var(--surface-hover)', border: '1px solid var(--border-strong)' },
      press: { background: 'var(--surface-active)', boxShadow: 'none' },
    },
    subtle: {
      rest: { background: 'var(--brand-subtle)', color: 'var(--text-brand)', border: '1px solid transparent' },
      hover: { background: 'var(--brand-subtle-hover)' },
      press: { background: 'var(--plum-200)' },
    },
    ghost: {
      rest: { background: 'transparent', color: 'var(--text-secondary)', border: '1px solid transparent' },
      hover: { background: 'var(--surface-hover)', color: 'var(--text-primary)' },
      press: { background: 'var(--surface-active)' },
    },
    inverse: {
      rest: { background: 'var(--alpha-white-08)', color: 'var(--text-inverse)', border: '1px solid var(--border-inverse)' },
      hover: { background: 'var(--alpha-white-16)' },
      press: { background: 'var(--alpha-white-08)' },
    },
    danger: {
      rest: { background: 'var(--red-500)', color: 'var(--slate-0)', border: '1px solid var(--red-500)' },
      hover: { background: 'var(--red-700)', border: '1px solid var(--red-700)' },
      press: { background: 'var(--red-700)' },
    },
  }[variant];
  return { ...v.rest, ...(state === 'hover' ? v.hover : null), ...(state === 'press' ? v.press : null) };
}

export function Button({
  children, variant = 'primary', size = 'md', iconLeft, iconRight, fullWidth = false,
  disabled = false, loading = false, type = 'button', onClick, style, ...rest
}: ButtonProps) {
  const [hover, setHover] = React.useState(false);
  const [press, setPress] = React.useState(false);
  const s = SIZES[size] || SIZES.md;
  const state = disabled || loading ? 'rest' : press ? 'press' : hover ? 'hover' : 'rest';
  return (
    <button
      type={type}
      disabled={disabled || loading}
      onClick={onClick}
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => { setHover(false); setPress(false); }}
      onMouseDown={() => setPress(true)}
      onMouseUp={() => setPress(false)}
      {...rest}
      style={{
        display: fullWidth ? 'flex' : 'inline-flex',
        width: fullWidth ? '100%' : undefined,
        alignItems: 'center',
        justifyContent: 'center',
        gap: s.gap,
        height: s.height,
        padding: s.padding,
        font: s.font,
        letterSpacing: 'var(--tracking-normal)',
        borderRadius: 'var(--radius-control)',
        cursor: disabled || loading ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.45 : 1,
        transform: state === 'press' ? 'translateY(0.5px)' : 'none',
        transition: 'var(--transition-control)',
        whiteSpace: 'nowrap',
        ...paint(variant, state),
        ...style,
      }}
    >
      {loading ? <Icon name="clock" size={s.icon} /> : iconLeft ? <Icon name={iconLeft} size={s.icon} /> : null}
      {children}
      {iconRight ? <Icon name={iconRight} size={s.icon} /> : null}
    </button>
  );
}
