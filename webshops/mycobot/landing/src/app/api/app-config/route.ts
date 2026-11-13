import { NextResponse } from 'next/server';
import { getAppConfig } from '@/lib/config/resolve';
import { isSlug } from '@/lib/config/store';
import { getSession } from '@/lib/auth/session';

/**
 * GET /api/app-config?tenant=<slug>[&draft=1]
 *
 * Everything a tenant's PWA reads at boot: theme, navigation, feature flags and the
 * package codes the tenant is entitled to. One endpoint, one extra dimension — the
 * same shape GET /api/landing already has for `lang`.
 *
 * This payload is presentation configuration, not customer data, so it is served by
 * slug. Actual rows go through /api/data/[resource], which refuses to identify a
 * tenant from the query string.
 *
 * `draft=1` serves what the console is editing and requires an admin session, so a
 * half-finished profile can be previewed without being published.
 */
export async function GET(request: Request) {
  const url = new URL(request.url);
  const slug = url.searchParams.get('tenant') ?? '';
  if (!isSlug(slug)) {
    return NextResponse.json({ error: 'Paramètre tenant manquant ou invalide.' }, { status: 400 });
  }

  const wantsDraft = url.searchParams.get('draft') === '1';
  if (wantsDraft && !(await getSession())) {
    return NextResponse.json({ error: 'Le brouillon exige une session back-office.' }, { status: 401 });
  }

  try {
    const payload = await getAppConfig(slug, { draft: wantsDraft });
    const etag = payload.etag;

    // A PWA that already holds this version must not re-download it: the whole point
    // of `version` is that the service worker can tell staleness in one round trip.
    if (request.headers.get('if-none-match') === etag) {
      return new NextResponse(null, { status: 304, headers: { ETag: etag } });
    }

    return NextResponse.json(payload, {
      headers: {
        ETag: etag,
        // A draft is per-admin and must never land in a shared cache.
        'Cache-Control': wantsDraft ? 'private, no-store' : 'public, s-maxage=60, stale-while-revalidate=300',
      },
    });
  } catch (error) {
    console.error('GET /api/app-config failed', error);
    return NextResponse.json({ error: 'Configuration indisponible.' }, { status: 503 });
  }
}
