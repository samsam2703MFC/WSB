import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { withAdmin } from '@/lib/api';

/**
 * GET /api/admin/subscriptions — read-only (§3.3). Stripe owns this data; the
 * webhook writes it, the console only reads it.
 */
export const GET = withAdmin(async () => {
  const subscriptions = await prisma.subscription.findMany({
    orderBy: { createdAt: 'desc' },
    include: { plan: { select: { key: true, amount: true, currency: true } }, brand: { select: { name: true, slug: true } } },
  });
  return NextResponse.json({ subscriptions });
});
