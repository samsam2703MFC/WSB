import { NextResponse } from 'next/server';
import { withAdmin, badRequest } from '@/lib/api';
import { isSlug, publish } from '@/lib/config/store';

type Ctx = { params: Promise<{ tenant: string }> };

/**
 * POST /api/admin/app-config/:tenant/publish
 *
 * Promotes the draft to what the PWA is served and increments `version`, which is
 * the signal the service worker compares against its cached copy.
 *
 * Refused while any value fails validation. A console that can push an invalid
 * configuration to a paying customer's app is the one thing this whole layer exists
 * to prevent.
 */
export async function POST(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (session, c) => {
    const slug = (await c.params).tenant;
    if (!isSlug(slug)) return badRequest('Slug de tenant invalide.');

    // The actor is taken from the session, never from the body — a log an admin can
    // sign with someone else's name is not a log.
    const result = await publish(slug, session.email);
    if (!result.ok) {
      return NextResponse.json(
        { error: 'Publication refusée — corrigez les valeurs invalides.', errors: result.errors },
        { status: 422 },
      );
    }
    return NextResponse.json({ ok: true, version: result.version });
  })(request, ctx);
}
