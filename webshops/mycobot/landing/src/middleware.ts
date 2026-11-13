import { NextResponse, type NextRequest } from 'next/server';
import { LOCALES, DEFAULT_LOCALE, isLocale } from '@/lib/i18n/config';

/**
 * The landing lives under /<locale>. This sends bare paths to a locale, preferring
 * the visitor's Accept-Language when it names one we serve, and French otherwise
 * (tfb_languages.is_default).
 *
 * /api and /admin are untouched — the console is French-only and the API takes its
 * locale from ?lang=.
 */
function pickLocale(request: NextRequest): string {
  const cookie = request.cookies.get('tfb_locale')?.value;
  if (cookie && isLocale(cookie)) return cookie;

  const header = request.headers.get('accept-language');
  if (header) {
    // "fr-BE,fr;q=0.9,en;q=0.8" → ["fr-be", "fr", "en"] in weight order.
    const tags = header
      .split(',')
      .map((part) => {
        const [tag, q] = part.trim().split(';q=');
        return { tag: tag.trim().toLowerCase(), q: q ? Number(q) : 1 };
      })
      .sort((a, b) => b.q - a.q);
    for (const { tag } of tags) {
      const base = tag.split('-')[0]!;
      if (isLocale(base)) return base;
    }
  }
  return DEFAULT_LOCALE;
}

export function middleware(request: NextRequest) {
  const { pathname } = request.nextUrl;

  const hasLocale = LOCALES.some((l) => pathname === `/${l}` || pathname.startsWith(`/${l}/`));
  if (hasLocale) return NextResponse.next();

  const locale = pickLocale(request);
  const url = request.nextUrl.clone();
  url.pathname = pathname === '/' ? `/${locale}` : `/${locale}${pathname}`;
  return NextResponse.redirect(url);
}

export const config = {
  // Everything except the API, the back office, the per-tenant theme page, and Next's
  // own assets. /preview/<slug> is a link handed to one client and is not localised —
  // without this it would be redirected to /fr/preview/<slug>, which does not exist.
  matcher: ['/((?!api|admin|preview|_next|favicon.ico|icons|brand).*)'],
};
