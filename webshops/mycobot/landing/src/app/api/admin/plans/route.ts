import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { withAdmin, readJson, badRequest } from '@/lib/api';

/** GET /api/admin/plans — plans with their subscriber counts (§3.3). */
export const GET = withAdmin(async () => {
  const plans = await prisma.plan.findMany({
    orderBy: { sortOrder: 'asc' },
    include: { _count: { select: { subscriptions: true } } },
  });
  return NextResponse.json({
    plans: plans.map((p) => ({ ...p, subscriberCount: p._count.subscriptions })),
  });
});

/** POST /api/admin/plans — amount is in cents; null means "sur devis". */
export async function POST(request: Request) {
  return withAdmin(async () => {
    const body = await readJson<{
      key?: string; amount?: number | null; currency?: string; interval?: string;
      stripePriceId?: string | null; isFeatured?: boolean; sortOrder?: number;
    }>(request);
    if (!body?.key) return badRequest('key est obligatoire.');
    if (body.interval && !['month', 'year'].includes(body.interval)) {
      return badRequest("interval doit être 'month' ou 'year'.");
    }

    const existing = await prisma.plan.findUnique({ where: { key: body.key } });
    if (existing) return badRequest(`Le plan « ${body.key} » existe déjà.`);

    const plan = await prisma.plan.create({
      data: {
        key: body.key,
        amount: body.amount ?? null,
        currency: body.currency || 'EUR',
        interval: body.interval || 'month',
        stripePriceId: body.stripePriceId || null,
        isFeatured: body.isFeatured ?? false,
        isActive: false,
        sortOrder: body.sortOrder ?? 0,
      },
    });
    return NextResponse.json({ plan }, { status: 201 });
  })(request, undefined);
}
