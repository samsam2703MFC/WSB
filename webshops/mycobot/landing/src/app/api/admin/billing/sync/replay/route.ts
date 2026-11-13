import { NextResponse } from 'next/server';
import { withAdmin, readJson } from '@/lib/api';
import { replaySync } from '@/lib/billing/client';

/**
 * POST /api/admin/billing/sync/replay
 * Body: { queueId?: number } — one row, or every failed row when omitted.
 */
export async function POST(request: Request) {
  return withAdmin(async () => {
    const body = await readJson<{ queueId?: number }>(request);
    const queueId = Number.isInteger(body?.queueId) ? (body!.queueId as number) : undefined;

    const result = await replaySync(queueId);
    return NextResponse.json(result, { status: result.ok ? 200 : 409 });
  })(request, undefined);
}
