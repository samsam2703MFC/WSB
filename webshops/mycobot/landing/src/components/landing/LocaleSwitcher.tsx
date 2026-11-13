'use client';

import { usePathname, useRouter } from 'next/navigation';
import { LanguageSwitcher, type TfbLanguage } from '@/design-system';
import { LOCALES } from '@/lib/i18n/config';

/**
 * Wires LanguageSwitcher to the router: the locale is the first path segment, so
 * switching swaps it and keeps the rest of the path (a module page stays on that
 * module). The choice is remembered so a later bare URL lands in the same language.
 */
export function LocaleSwitcher({
  locale,
  languages,
  tone = 'light',
}: {
  locale: string;
  languages: TfbLanguage[];
  tone?: 'light' | 'dark';
}) {
  const router = useRouter();
  const pathname = usePathname() || `/${locale}`;

  const change = (code: string) => {
    const segments = pathname.split('/').filter(Boolean);
    if (segments.length > 0 && (LOCALES as readonly string[]).includes(segments[0]!)) {
      segments[0] = code;
    } else {
      segments.unshift(code);
    }
    document.cookie = `tfb_locale=${code}; path=/; max-age=${60 * 60 * 24 * 365}; samesite=lax`;
    router.push(`/${segments.join('/')}`);
  };

  return <LanguageSwitcher languages={languages} value={locale} onChange={change} tone={tone} />;
}
