'use client';

import React from 'react';
import { Icon } from '../core/Icon';

export interface CarouselItem {
  /** Server path from tfb_module_screenshots.file_path. Omit to render the labelled placeholder. */
  src?: string;
  /** Translated alt text (tfb_translations, entity_type='screenshot'). Required for accessibility. */
  alt?: string;
}

/** Screenshot carousel — one per module card. Arrows, dots, counter, keyboard and optional autoplay. */
export interface CarouselProps {
  items: CarouselItem[];
  /** True when the page is Arabic: swaps what ← and → mean so "next" stays forward. */
  rtl?: boolean;
  /** Off by default. When on, hover and Space pause it, and a pause control appears. */
  autoplay?: boolean;
  /** Autoplay delay in ms. */
  interval?: number;
  /** CSS aspect-ratio for the frame. Default "16 / 10". */
  aspect?: string;
  /** Placeholder caption when `src` is empty — names the storage folder. */
  placeholderPath?: string;
  onChange?: (index: number) => void;
  style?: React.CSSProperties;
}

export function Carousel({
  items = [], rtl = false, autoplay = false, interval = 5200, aspect = '16 / 10',
  placeholderPath = '/storage/screenshots/…', onChange, style, ...rest
}: CarouselProps) {
  const [i, setI] = React.useState(0);
  const [paused, setPaused] = React.useState(!autoplay);
  const n = items.length;
  const go = React.useCallback((next: number) => {
    setI(() => { const v = ((next % Math.max(n, 1)) + Math.max(n, 1)) % Math.max(n, 1); if (onChange) onChange(v); return v; });
  }, [n, onChange]);

  React.useEffect(() => {
    if (paused || n < 2) return undefined;
    const t = window.setInterval(() => go(i + 1), interval);
    return () => window.clearInterval(t);
  }, [paused, i, n, interval, go]);

  const prev = () => go(i - 1);
  const next = () => go(i + 1);
  const current = items[i] || {};

  return (
    <div
      role="group"
      aria-roledescription="carousel"
      tabIndex={0}
      onKeyDown={(e) => {
        if (e.key === 'ArrowRight') { e.preventDefault(); if (rtl) prev(); else next(); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); if (rtl) next(); else prev(); }
        if (e.key === ' ') { e.preventDefault(); setPaused((p) => !p); }
      }}
      onMouseEnter={() => autoplay && setPaused(true)}
      onMouseLeave={() => autoplay && setPaused(false)}
      {...rest}
      style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)', minWidth: 0, ...style }}
    >
      <div style={{
        position: 'relative', aspectRatio: aspect, borderRadius: 'var(--radius-md)',
        background: 'var(--surface-sunken)', border: '1px solid var(--border-default)', overflow: 'hidden',
      }}>
        {current.src ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={current.src} alt={current.alt || ''} style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }} />
        ) : (
          <div style={{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 6, textAlign: 'center', padding: 'var(--space-4)' }}>
            <Icon name="image" size={20} color="var(--text-tertiary)" />
            <span style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>{current.alt || 'Screenshot ' + (i + 1)}</span>
            <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{placeholderPath}</span>
          </div>
        )}
        {n > 1 && (
          <>
            <button type="button" aria-label={rtl ? 'Suivant' : 'Précédent'} onClick={prev} style={arrow('start')}>
              <Icon name="chevron-left" size={16} />
            </button>
            <button type="button" aria-label={rtl ? 'Précédent' : 'Suivant'} onClick={next} style={arrow('end')}>
              <Icon name="chevron-right" size={16} />
            </button>
          </>
        )}
      </div>
      {n > 1 && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
          {items.map((it, k) => (
            <button
              key={k}
              type="button"
              aria-label={'Screenshot ' + (k + 1)}
              aria-current={k === i}
              onClick={() => go(k)}
              style={{
                width: k === i ? 18 : 7, height: 7, padding: 0, border: 0, cursor: 'pointer',
                borderRadius: 'var(--radius-pill)',
                background: k === i ? 'var(--brand)' : 'var(--slate-300)',
                transition: 'width var(--duration-base) var(--ease-standard), background-color var(--duration-fast) var(--ease-standard)',
              }}
            />
          ))}
          <span className="fb-num" style={{ marginInlineStart: 'auto', font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{i + 1} / {n}</span>
          {autoplay && (
            <button type="button" aria-label={paused ? 'Lecture' : 'Pause'} onClick={() => setPaused((p) => !p)} style={{ display: 'inline-flex', padding: 2, border: 0, background: 'transparent', cursor: 'pointer', color: 'var(--text-tertiary)' }}>
              <Icon name={paused ? 'play' : 'pause'} size={13} />
            </button>
          )}
        </div>
      )}
    </div>
  );
}

function arrow(side: 'start' | 'end'): React.CSSProperties {
  return {
    position: 'absolute', top: '50%', transform: 'translateY(-50%)',
    ...(side === 'start' ? { insetInlineStart: 8 } : { insetInlineEnd: 8 }),
    display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
    width: 30, height: 30, borderRadius: 'var(--radius-circle)', cursor: 'pointer',
    background: 'rgba(255,255,255,0.92)', color: 'var(--navy-900)',
    border: '1px solid var(--border-default)', boxShadow: 'var(--shadow-sm)',
  };
}
