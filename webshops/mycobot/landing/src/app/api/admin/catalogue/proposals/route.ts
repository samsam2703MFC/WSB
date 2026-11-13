import { NextResponse } from 'next/server';
import { withAdmin, readJson, badRequest } from '@/lib/api';
import { addProposal, listProposals, removeProposal, proposalCode, validateProposal, type Proposal } from '@/lib/data/proposals';

/** GET /api/admin/catalogue/proposals — everything waiting to be reviewed. */
export const GET = withAdmin(async () => {
  const items = await listProposals();
  return NextResponse.json({ proposals: items.map((p) => ({ ...p, code: proposalCode(p) })) });
});

/**
 * POST /api/admin/catalogue/proposals — record one proposal.
 *
 * This creates no route and exposes no data. It writes a declaration for someone to
 * paste into catalogue.ts and put through review — the gap between "generated" and
 * "served" is the whole point.
 */
export async function POST(request: Request) {
  return withAdmin(async (session) => {
    const body = await readJson<Partial<Proposal>>(request);
    if (!body) return badRequest('Corps de requête invalide.');

    const message = validateProposal(body);
    if (message) return badRequest(message);

    const proposal: Proposal = {
      key: body.key!,
      source: body.source!,
      sourceKind: body.sourceKind === 'view' ? 'view' : 'table',
      columns: body.columns!,
      filters: body.filters ?? {},
      orderable: body.orderable ?? [],
      maxRows: body.maxRows!,
      tenantColumn: body.tenantColumn!,
      createdAt: new Date().toISOString(),
      createdBy: session.email,
    };

    await addProposal(proposal);
    return NextResponse.json({ ok: true, proposal, code: proposalCode(proposal) }, { status: 201 });
  })(request, undefined);
}

/** DELETE /api/admin/catalogue/proposals?key=… — discard one. */
export async function DELETE(request: Request) {
  return withAdmin(async () => {
    const key = new URL(request.url).searchParams.get('key');
    if (!key) return badRequest('Paramètre key manquant.');
    await removeProposal(key);
    return NextResponse.json({ ok: true });
  })(request, undefined);
}
