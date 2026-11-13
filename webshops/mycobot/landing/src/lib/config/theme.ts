/**
 * A tenant's complete stylesheet, generated from its resolved variables.
 *
 * The design system is authored entirely against CSS custom properties, so a full
 * per-client theme is a list of property overrides — no CSS is built, shipped or
 * duplicated per tenant, and a client who changes their primary colour does not
 * trigger a deploy.
 *
 * ON STORING RAW CSS INSTEAD. It would be simpler to let the console hold a
 * stylesheet as text, and it is a bad trade: CSS is not inert. Attribute selectors
 * with `background-image: url(...)` exfiltrate the values of inputs on the page, and
 * a single `content` rule can rewrite what a customer reads. A token set expresses
 * every legitimate theme and none of that — which is why every value below comes out
 * of a validated enum or a `#RRGGBB` that was checked on write.
 */

import { INK, registryDefaults, type ConfigValues } from './registry';

const HEX = /^#[0-9a-fA-F]{6}$/;

/** Last line of defence before a value is interpolated into a <style>. */
function safeHex(value: unknown, fallback: string): string {
  return typeof value === 'string' && HEX.test(value) ? value.toLowerCase() : fallback;
}

function pick<T extends string>(value: unknown, allowed: readonly T[], fallback: T): T {
  return typeof value === 'string' && (allowed as readonly string[]).includes(value) ? (value as T) : fallback;
}

/** Mixes a colour towards black (amount < 0) or white (amount > 0), for hover states. */
function shift(hexColour: string, amount: number): string {
  const to = amount < 0 ? 0 : 255;
  const ratio = Math.abs(amount);
  const channel = (offset: number) => {
    const from = parseInt(hexColour.slice(offset, offset + 2), 16);
    return Math.round(from + (to - from) * ratio)
      .toString(16)
      .padStart(2, '0');
  };
  return `#${channel(1)}${channel(3)}${channel(5)}`;
}

/** Same colour at a given alpha — for tinted badge and subtle-surface backgrounds. */
function alpha(hexColour: string, a: number): string {
  const c = (offset: number) => parseInt(hexColour.slice(offset, offset + 2), 16);
  return `rgba(${c(1)}, ${c(3)}, ${c(5)}, ${a})`;
}

const FONT_STACKS: Record<string, string> = {
  manrope: '"Manrope", "IBM Plex Sans", system-ui, sans-serif',
  'plex-sans': '"IBM Plex Sans", "Segoe UI", system-ui, sans-serif',
  system: 'system-ui, -apple-system, "Segoe UI", sans-serif',
};

const SHADOWS: Record<string, { sm: string; md: string; lg: string }> = {
  none: { sm: 'none', md: 'none', lg: 'none' },
  soft: {
    sm: '0 1px 3px rgba(12, 19, 41, 0.08), 0 1px 2px -1px rgba(12, 19, 41, 0.06)',
    md: '0 4px 12px -2px rgba(12, 19, 41, 0.1), 0 2px 4px -2px rgba(12, 19, 41, 0.06)',
    lg: '0 12px 28px -6px rgba(12, 19, 41, 0.16), 0 4px 8px -4px rgba(12, 19, 41, 0.08)',
  },
  strong: {
    sm: '0 2px 6px rgba(12, 19, 41, 0.16)',
    md: '0 10px 24px -4px rgba(12, 19, 41, 0.22), 0 4px 8px -4px rgba(12, 19, 41, 0.12)',
    lg: '0 24px 48px -10px rgba(12, 19, 41, 0.3), 0 8px 16px -8px rgba(12, 19, 41, 0.16)',
  },
};

/** Multiplier on the 4px spacing scale. A touch till is not a desk console. */
const DENSITY: Record<string, number> = { compact: 0.8, normal: 1, comfortable: 1.25 };

export interface ThemeAssets {
  logo: string | null;
  logoInverse: string | null;
  favicon: string | null;
  fontsHref: string | null;
}

/**
 * Every custom property the design system reads, resolved for one tenant. Returned as
 * a declaration block to place inside a scoping selector.
 */
export function themeCss(values: ConfigValues): string {
  const v: ConfigValues = { ...registryDefaults(), ...values };

  const primary = safeHex(v['theme.primary'], '#7b4488');
  const accent = safeHex(v['theme.accent'], '#f0912a');
  const page = safeHex(v['theme.surface_page'], '#f5f6f9');
  const card = safeHex(v['theme.surface_card'], '#ffffff');
  const textPrimary = safeHex(v['theme.text_primary'], '#0c1329');
  const textSecondary = safeHex(v['theme.text_secondary'], '#565d73');
  const border = safeHex(v['theme.border'], '#dddfe8');
  const success = safeHex(v['status.success'], '#157f5a');
  const warning = safeHex(v['status.warning'], '#b77500');
  const danger = safeHex(v['status.danger'], '#c0304a');
  const info = safeHex(v['status.info'], '#2a5fd0');

  const primaryInk = INK[pick(v['theme.primary_ink'], ['white', 'ink'] as const, 'white')];
  const accentInk = INK[pick(v['theme.accent_ink'], ['white', 'ink'] as const, 'ink')];
  const radius = Number(pick(v['theme.radius'], ['4', '8', '12', '16'] as const, '12'));
  const shadow = SHADOWS[pick(v['theme.shadow'], ['none', 'soft', 'strong'] as const, 'soft')]!;
  const scale = DENSITY[pick(v['theme.density'], ['compact', 'normal', 'comfortable'] as const, 'normal')]!;
  const display = FONT_STACKS[pick(v['theme.font_display'], Object.keys(FONT_STACKS), 'manrope')]!;
  const sans = FONT_STACKS[pick(v['theme.font_sans'], Object.keys(FONT_STACKS), 'plex-sans')]!;

  const space = (n: number) => `${Math.round(n * scale * 100) / 100}px`;

  return [
    `--brand: ${primary};`,
    `--brand-hover: ${shift(primary, -0.12)};`,
    `--brand-press: ${shift(primary, -0.24)};`,
    `--brand-subtle: ${alpha(primary, 0.08)};`,
    `--brand-subtle-hover: ${alpha(primary, 0.14)};`,
    `--brand-on: ${primaryInk};`,
    `--brand-ink: ${textPrimary};`,
    `--surface-brand: ${primary};`,
    `--surface-brand-subtle: ${alpha(primary, 0.08)};`,
    `--text-brand: ${shift(primary, -0.12)};`,
    `--text-link: ${shift(primary, -0.12)};`,
    `--text-link-hover: ${shift(primary, -0.24)};`,
    `--border-brand: ${alpha(primary, 0.45)};`,
    `--shadow-brand: 0 8px 22px -8px ${alpha(primary, 0.45)};`,
    `--shadow-focus: 0 0 0 3px ${alpha(primary, 0.26)};`,

    `--cta-warm: ${accent};`,
    `--cta-warm-hover: ${shift(accent, -0.1)};`,
    `--cta-warm-press: ${shift(accent, -0.2)};`,
    `--cta-warm-on: ${accentInk};`,
    `--cta-warm-subtle: ${alpha(accent, 0.1)};`,
    `--accent-new: ${accent};`,

    `--surface-page: ${page};`,
    `--surface-card: ${card};`,
    `--surface-raised: ${card};`,
    `--surface-sunken: ${shift(page, -0.04)};`,
    `--surface-hover: ${shift(page, -0.02)};`,
    `--surface-active: ${shift(page, -0.06)};`,
    `--surface-inverse: ${textPrimary};`,
    `--surface-inverse-raised: ${shift(textPrimary, 0.08)};`,

    `--text-primary: ${textPrimary};`,
    `--text-secondary: ${textSecondary};`,
    `--text-tertiary: ${shift(textSecondary, 0.28)};`,
    `--text-disabled: ${shift(textSecondary, 0.28)};`,
    `--text-inverse: ${card};`,

    `--border-subtle: ${shift(border, 0.5)};`,
    `--border-default: ${border};`,
    `--border-strong: ${shift(border, -0.18)};`,

    `--status-success: ${success};`,
    `--status-warning: ${warning};`,
    `--status-danger: ${danger};`,
    `--status-info: ${info};`,
    `--text-success: ${shift(success, -0.2)};`,
    `--text-warning: ${shift(warning, -0.2)};`,
    `--text-danger: ${shift(danger, -0.2)};`,
    `--border-danger: ${danger};`,
    `--surface-success-subtle: ${alpha(success, 0.12)};`,
    `--surface-warning-subtle: ${alpha(warning, 0.14)};`,
    `--surface-danger-subtle: ${alpha(danger, 0.12)};`,
    `--surface-info-subtle: ${alpha(info, 0.12)};`,

    `--chart-1: ${primary};`,
    `--chart-2: ${info};`,
    `--chart-3: ${success};`,
    `--chart-4: ${shift(primary, 0.35)};`,
    `--chart-5: ${accent};`,
    `--chart-6: ${shift(info, 0.35)};`,

    `--font-display: ${display};`,
    `--font-sans: ${sans};`,

    `--radius-sm: ${Math.max(2, radius - 6)}px;`,
    `--radius-md: ${Math.max(4, radius - 4)}px;`,
    `--radius-lg: ${radius}px;`,
    `--radius-xl: ${radius + 4}px;`,
    `--radius-control: ${Math.max(4, radius - 4)}px;`,
    `--radius-card: ${radius}px;`,
    `--radius-panel: ${radius + 4}px;`,

    `--shadow-xs: ${shadow.sm};`,
    `--shadow-sm: ${shadow.sm};`,
    `--shadow-md: ${shadow.md};`,
    `--shadow-lg: ${shadow.lg};`,

    `--space-1: ${space(4)};`,
    `--space-2: ${space(8)};`,
    `--space-3: ${space(12)};`,
    `--space-4: ${space(16)};`,
    `--space-5: ${space(20)};`,
    `--space-6: ${space(24)};`,
    `--space-8: ${space(32)};`,
    `--control-height-sm: ${space(30)};`,
    `--control-height-md: ${space(38)};`,
  ].join('\n  ');
}

/** Media paths, kept out of the CSS so they can be used in <img> and the manifest too. */
export function themeAssets(values: ConfigValues): ThemeAssets {
  const v: ConfigValues = { ...registryDefaults(), ...values };
  const path = (key: string) => {
    const value = v[key];
    // Only paths this app served itself — validated on write, re-checked here because
    // this one is interpolated into markup.
    return typeof value === 'string' && value.startsWith('/storage/') && !value.includes('..') ? value : null;
  };
  return {
    logo: path('theme.logo'),
    logoInverse: path('theme.logo_inverse'),
    favicon: path('theme.favicon'),
    fontsHref: null,
  };
}
