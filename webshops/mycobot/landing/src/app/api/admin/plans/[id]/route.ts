import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { withAdmin, readJson, badRequest, notFound, intParam } from '@/lib/api';

type Ctx = { params: Promise<{ id: string }> };

/** PATCH /api/admin/plans/:id */
export async function PATCH(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const id = intParam((await c.params).id);
    if (!id) return badRequest('Identifiant invalide.');

    const body = await readJson<{
      amount?: number | null; currency?: string; interval?: string; stripePriceId?: string | null;
      isFeatured?: boolean; isActive?: boolean; sortOrder?: number;
    }>(request);
    if (!body) return badRequest('Corps de requête invalide.');
    if (body.interval && !['month', 'year'].includes(body.interval)) {
      return badRequest("interval doit être 'month' ou 'year'.");
    }

    const plan = await prisma.plan.findUnique({ where: { id } });
    if (!plan) return notFound('Plan introuvable.');

    // Exactly one featured plan per grid — promoting one demotes the rest.
    const updates = [];
    if (body.isFeatured === true) {
      updates.push(prisma.plan.updateMany({ where: { id: { not: id } }, data: { isFeatured: false } }));
    }
    updates.push(
      prisma.plan.update({
        where: { id },
        data: {
          ...(body.amount !== undefined ? { amount: body.amount } : {}),
          ...(body.currency !== undefined ? { currency: body.currency } : {}),
          ...(body.interval !== undefined ? { interval: body.interval } : {}),
          ...(body.stripePriceId !== undefined ? { stripePriceId: body.stripePriceId } : {}),
          ...(body.isFeatured !== undefined ? { isFeatured: body.isFeatured } : {}),
          ...(body.isActive !== undefined ? { isActive: body.isActive } : {}),
          ...(body.sortOrder !== undefined ? { sortOrder: body.sortOrder } : {}),
        },
      }),
    );
    const results = await prisma.$transaction(updates);

    return NextResponse.json({ plan: results[results.length - 1] });
  })(request, ctx);
}

/** DELETE /api/admin/plans/:id — refused while subscriptions reference it. */
export async function DELETE(request: Request, ctx: Ctx) {
  return withAdmin<Ctx>(async (_session, c) => {
    const id = intParam((await c.params).id);
    if (!id) return badRequest('Identifiant invalide.');

    const plan = await prisma.plan.findUnique({ where: { id }, include: { _count: { select: { subscriptions: true } } } });
    if (!plan) return notFound('Plan introuvable.');
    if (plan._count.subscriptions > 0) {
      return badRequest(
        `${plan._count.subscriptions} abonnement(s) pointent sur ce plan. Désactivez-le au lieu de le supprimer.`,
      );
    }

    await prisma.$transaction([
      prisma.translation.deleteMany({ where: { entityType: 'plan', entityId: id } }),
      prisma.plan.delete({ where: { id } }),
    ]);
    return NextResponse.json({ ok: true });
  })(request, ctx);
}
