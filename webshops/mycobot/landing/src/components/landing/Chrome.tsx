'use client';

import Link from 'next/link';
import { Button } from '@/design-system';
import type { LandingPayload } from '@/lib/landing/types';
import { LocaleSwitcher } from './LocaleSwitcher';
import { SECTION } from './layout';

type Strings = LandingPayload['strings'];

/** Sticky translucent header: 88% white + 14px blur, 68px tall. */
export function LandingHeader({
  payload,
  onDemo,
}: {
  payload: LandingPayload;
  onDemo: () => void;
}) {
  const t = (key: string) => payload.strings[key] ?? key;
  const base = `/${payload.locale}`;
  return (
    <header style={{
      position: 'sticky', top: 0, zIndex: 40, background: 'rgba(255,255,255,0.88)',
      backdropFilter: 'var(--blur-panel)', borderBottom: '1px solid var(--border-subtle)',
    }}>
      <div style={{ ...SECTION, display: 'flex', alignItems: 'center', gap: 'var(--space-8)', height: 68 }}>
        <Link href={base} style={{ display: 'flex', alignItems: 'center', gap: 10, textDecoration: 'none', color: 'var(--navy-900)' }}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/brand/logo-mark.png" alt="TFB" width={30} height={30} />
          <span style={{ font: 'var(--weight-bold) var(--text-md)/1.05 var(--font-display)', letterSpacing: 'var(--tracking-tight)' }}>
            {t('brand.name')}
          </span>
        </Link>
        <nav style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-6)' }}>
          <a href={`${base}#modules`} style={navLink}>{t('nav.modules')}</a>
          <a href={`${base}#pricing`} style={navLink}>{t('nav.pricing')}</a>
          <a href={`${base}#contact`} style={navLink}>{t('nav.contact')}</a>
        </nav>
        <div style={{ marginInlineStart: 'auto', display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
          <LocaleSwitcher locale={payload.locale} languages={payload.languages} />
          <Link href="/admin/login" style={{ textDecoration: 'none' }}>
            <Button variant="ghost" iconLeft="log-in">{t('nav.signin')}</Button>
          </Link>
          <Button variant="warm" iconRight="arrow-right" onClick={onDemo}>{t('cta.demo')}</Button>
        </div>
      </div>
    </header>
  );
}

const navLink = {
  font: 'var(--type-body)',
  color: 'var(--text-secondary)',
  textDecoration: 'none',
} as const;

/** Ink footer, carrying the second language switcher. */
export function LandingFooter({ payload }: { payload: LandingPayload }) {
  const strings: Strings = payload.strings;
  const t = (key: string) => strings[key] ?? key;
  const list = (key: string) => (strings[key] ?? '').split('|').filter(Boolean);

  // The product column is the live module list, so a module published by the
  // morning routine appears in the footer too.
  const columns: [string, string[]][] = [
    [t('footer.product'), payload.modules.map((m) => m.name)],
    [t('footer.company'), list('footer.company.items')],
    [t('footer.legal'), list('footer.legal.items')],
  ];

  return (
    <footer style={{ background: 'var(--gradient-ink)', color: 'var(--text-inverse)', padding: 'var(--space-16) 0 var(--space-8)' }}>
      <div style={{ ...SECTION, display: 'grid', gridTemplateColumns: '1.5fr repeat(3, 1fr) auto', gap: 'var(--space-10)' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', maxWidth: 300 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/brand/logo-mark-inverse.png" alt="" width={28} height={28} />
            <span style={{ font: 'var(--weight-bold) var(--text-base)/1.05 var(--font-display)' }}>{t('brand.name')}</span>
          </div>
          <p style={{ font: 'var(--type-body-sm)', color: 'var(--text-inverse-secondary)' }}>{t('footer.tagline')}</p>
        </div>
        {columns.map(([heading, items], columnIndex) => (
          <div key={heading} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
            <span style={footerHeading}>{heading}</span>
            {items.map((item, i) => (
              columnIndex === 0 ? (
                <Link key={item} href={`/${payload.locale}/modules/${payload.modules[i]?.slug ?? ''}`} style={footerLink}>{item}</Link>
              ) : (
                <span key={item} style={footerLink}>{item}</span>
              )
            ))}
          </div>
        ))}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
          <span style={footerHeading}>{t('footer.langs')}</span>
          <LocaleSwitcher locale={payload.locale} languages={payload.languages} tone="dark" />
        </div>
      </div>
      <div style={{ ...SECTION, marginTop: 'var(--space-12)', paddingTop: 'var(--space-5)', borderTop: '1px solid var(--border-inverse)', font: 'var(--type-caption)', color: 'rgba(255,255,255,0.5)' }}>
        {t('footer.rights')}
      </div>
    </footer>
  );
}

const footerHeading = {
  font: 'var(--weight-semibold) var(--text-xs)/1 var(--font-sans)',
  letterSpacing: 'var(--tracking-caps)',
  textTransform: 'uppercase',
  color: 'rgba(255,255,255,0.46)',
} as const;

const footerLink = {
  font: 'var(--type-body-sm)',
  color: 'var(--text-inverse-secondary)',
  textDecoration: 'none',
} as const;
