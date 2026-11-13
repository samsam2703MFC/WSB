import { NextResponse } from 'next/server';
import { withAdmin } from '@/lib/api';
import { listSources } from '@/lib/data/introspect';

/**
 * GET /api/admin/catalogue/sources — tables and views in the connection's own schema.
 *
 * Read-only and admin-gated. Listing what exists is the wizard's first step; it
 * exposes nothing on its own, and it never reaches outside DATABASE().
 */
export const GET = withAdmin(async () => {
  try {
    return NextResponse.json({ sources: await listSources() });
  } catch (error) {
    console.error('Catalogue introspection failed', error);
    return NextResponse.json(
      { error: 'Introspection impossible — la base n’a pas répondu.', sources: [] },
      { status: 503 },
    );
  }
});
