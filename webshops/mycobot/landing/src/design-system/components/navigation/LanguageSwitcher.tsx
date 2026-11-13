'use client';

import React from 'react';
import { Icon } from '../core/Icon';

export interface TfbLanguage {
  /** tfb_languages.code — 'fr','en','nl','de','pl','uk','ru','ar'. */
  code: string;
  /** Native name as stored in tfb_languages.name. */
  name: string;
  /** tfb_languages.is_rtl. */
  rtl?: boolean;
}

/** The 8 locales seeded into tfb_languages, in sort_order. */
export const TFB_LANGUAGES: TfbLanguage[] = [
  { code: 'fr', name: 'Français', rtl: false },
  { code: 'en', name: 'English', rtl: false },
  { code: 'nl', name: 'Nederlands', rtl: false },
  { code: 'de', name: 'Deutsch', rtl: false },
  { code: 'pl', name: 'Polski', rtl: false },
  { code: 'uk', name: 'Українська', rtl: false },
  { code: 'ru', name: 'Русский', rtl: false },
  { code: 'ar', name: 'العربية', rtl: true },
];

/**
 * Persistent language selector for the 8 active locales. Shows the uppercase code in the
 * trigger and native names in the menu; no flag icons — a flag is a country, not a language.
 */
export interface LanguageSwitcherProps {
  /** Active rows from tfb_languages, sorted by sort_order. Defaults to the 8 shipped locales. */
  languages?: TfbLanguage[];
  /** Current locale code. */
  value?: string;
  /** Called with (code, language). Set `dir="rtl"` on <html> when the language is RTL. */
  onChange?: (code: string, language: TfbLanguage) => void;
  /** `dark` for the ink header/footer, `light` on white chrome. */
  tone?: 'light' | 'dark';
  style?: React.CSSProperties;
}

export function LanguageSwitcher({ languages = TFB_LANGUAGES, value = 'fr', onChange, tone = 'light', style, ...rest }: LanguageSwitcherProps) {
  const [open, setOpen] = React.useState(false);
  const boxRef = React.useRef<HTMLDivElement>(null);
  const current = languages.find((l) => l.code === value) || languages[0];
  const dark = tone === 'dark';

  // Dismiss on outside click and on Escape — the trigger is keyboard-reachable, so the
  // menu must be dismissable the same way.
  React.useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent) => {
      if (boxRef.current && !boxRef.current.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('mousedown', onDoc); document.removeEventListener('keydown', onKey); };
  }, [open]);

  return (
    <div ref={boxRef} {...rest} style={{ position: 'relative', ...style }}>
      <button
        type="button"
        aria-haspopup="listbox"
        aria-expanded={open}
        onClick={() => setOpen((o) => !o)}
        style={{
          display: 'inline-flex', alignItems: 'center', gap: 6, height: 34, padding: '0 10px',
          borderRadius: 'var(--radius-control)', cursor: 'pointer',
          background: dark ? 'var(--alpha-white-08)' : 'var(--surface-card)',
          color: dark ? 'var(--text-inverse)' : 'var(--text-secondary)',
          border: '1px solid ' + (dark ? 'var(--border-inverse)' : 'var(--border-default)'),
          font: 'var(--weight-medium) var(--text-sm)/1 var(--font-sans)',
          transition: 'var(--transition-control)',
        }}
      >
        <Icon name="globe" size={15} />
        <span style={{ letterSpacing: 'var(--tracking-wide)' }}>{current.code.toUpperCase()}</span>
        <Icon name="chevron-down" size={13} />
      </button>
      {open && (
        <ul
          role="listbox"
          style={{
            position: 'absolute', insetInlineEnd: 0, top: 'calc(100% + 6px)', zIndex: 50,
            margin: 0, padding: 'var(--space-1)', listStyle: 'none', minWidth: 176,
            background: 'var(--surface-card)', border: '1px solid var(--border-default)',
            borderRadius: 'var(--radius-md)', boxShadow: 'var(--shadow-lg)',
          }}
        >
          {languages.map((l) => {
            const on = l.code === value;
            return (
              <li key={l.code}>
                <button
                  type="button"
                  role="option"
                  aria-selected={on}
                  onClick={() => { setOpen(false); if (onChange) onChange(l.code, l); }}
                  style={{
                    display: 'flex', alignItems: 'center', gap: 'var(--space-3)', width: '100%',
                    height: 34, padding: '0 8px', border: 0, cursor: 'pointer', textAlign: 'start',
                    borderRadius: 'var(--radius-sm)',
                    background: on ? 'var(--surface-brand-subtle)' : 'transparent',
                    color: on ? 'var(--text-brand)' : 'var(--text-primary)',
                    font: (on ? 'var(--weight-semibold) ' : 'var(--weight-regular) ') + 'var(--text-sm)/1 var(--font-sans)',
                  }}
                >
                  <span className="fb-num" style={{ width: 22, fontSize: 'var(--text-2xs)', color: on ? 'inherit' : 'var(--text-tertiary)' }}>{l.code.toUpperCase()}</span>
                  <span style={{ flex: 1, fontFamily: l.rtl ? 'var(--font-arabic)' : 'inherit' }}>{l.name}</span>
                  {l.rtl && <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>RTL</span>}
                  {on && <Icon name="check" size={14} />}
                </button>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
