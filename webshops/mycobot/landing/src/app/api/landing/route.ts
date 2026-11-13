import { NextResponse } from 'next/server';
import { getLanding } from '@/lib/landing/queries';
import { DEFAULT_LOCALE, isLocale } from '@/lib/i18n/config';

/**
 * GET /api/landing?lang=fr
 *
 * Everything the landing renders, resolved into the requested language with the
 * default locale filled in behind it (prompt_technique_tfb.md §3.1).
 */
export async function GET(request: Request) {
  const requested = new URL(request.url).searchParams.get('lang') ?? DEFAULT_LOCALE;
  const locale = isLocale(requested) ? requested : DEFAULT_LOCALE;

  try {
    const payload = await getLanding(locale);
    return NextResponse.json(payload, {
      headers: {
        // Content changes when the morning routine publishes a module; a short
        // shared cache keeps the marketing site fast without going stale for long.
        'Cache-Control': 'public, s-maxage=60, stale-while-revalidate=300',
      },
    });
  } catch (error) {
    console.error('GET /api/landing failed', error);
    return NextResponse.json({ error: 'Contenu indisponible.' }, { status: 503 });
  }
}
