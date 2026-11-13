import type { CSSProperties } from 'react';

/** Icon directory. The 75 vendored Lucide glyphs live in public/icons. */
export const ICON_BASE = '/icons';

/**
 * Lucide glyph rendered as a recolorable mask. Icon names match the files in public/icons.
 */
export interface IconProps {
  /** File stem in public/icons, e.g. "map-pin". */
  name: string;
  /** Square size in px. 14 / 16 / 18 / 20 / 24 are the sanctioned steps. */
  size?: number;
  /** Any CSS color; defaults to currentColor so it inherits from text. */
  color?: string;
  /** Override the icon directory. Defaults to ICON_BASE. */
  base?: string;
  /** Accessible label. Omit for decorative icons (renders aria-hidden). */
  title?: string;
  className?: string;
  style?: CSSProperties;
}

/* Masked-SVG glyph wrapper for the Lucide set shipped in public/icons.
   Uses mask-image so the glyph inherits currentColor. */
export function Icon({ name, size = 18, color = 'currentColor', base, title, style, ...rest }: IconProps) {
  const url = `url("${base || ICON_BASE}/${name}.svg")`;
  return (
    <span
      role={title ? 'img' : undefined}
      aria-label={title}
      aria-hidden={title ? undefined : 'true'}
      {...rest}
      style={{
        display: 'inline-block',
        flex: 'none',
        width: size,
        height: size,
        background: color,
        WebkitMaskImage: url,
        maskImage: url,
        WebkitMaskRepeat: 'no-repeat',
        maskRepeat: 'no-repeat',
        WebkitMaskPosition: 'center',
        maskPosition: 'center',
        WebkitMaskSize: 'contain',
        maskSize: 'contain',
        ...style,
      }}
    />
  );
}
