import { NextResponse } from 'next/server';
import { withAdmin } from '@/lib/api';
import { blockLicense } from '@/lib/billing/client';

type Ctx = { params: Promise<{ id: string }> };

/**
 * POST /api/admin/billing/licenses/:id/block
 *
 * Delegates to the billing service, which owns the licence: status = blocked, an
 * internal license_events row, and a sync_queue entry. Refused (200 with ok:false)
 * while BILLING_SERVICE_URL is unset, so the UI can say why instead of implying the
 * store lost access.
 */
export async function POST(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const { id } = await c.params;
    if (!id) return NextResponse.json({ ok: false, reason: 'Identifiant manquant.' }, { status: 400 });

    const result = await blockLicense(id);
    return NextResponse.json(result, { status: result.ok ? 200 : 409 });
  })(request, ctx);
}
