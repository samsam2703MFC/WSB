import { prisma } from '@/lib/prisma';
import { DEFAULT_LOCALE } from '@/lib/i18n/config';
import { PlansTable } from '@/components/admin/ContentScreens';

/** tfb_plans — the landing's pricing cards and their Stripe price links. */
export default async function PlansPage() {
  const [plans, names] = await Promise.all([
    prisma.plan.findMany({
      orderBy: { sortOrder: 'asc' },
      include: { _count: { select: { subscriptions: true } } },
    }),
    prisma.translation.findMany({
      where: { entityType: 'plan', field: 'name', languageCode: DEFAULT_LOCALE },
      select: { entityId: true, value: true },
    }),
  ]);
  const nameById = new Map(names.map((n) => [n.entityId, n.value]));

  return (
    <PlansTable
      plans={plans.map((p) => ({
        id: p.id,
        key: p.key,
        name: nameById.get(p.id) ?? p.key,
        amount: p.amount,
        currency: p.currency,
        interval: p.interval,
        stripePriceId: p.stripePriceId,
        isFeatured: p.isFeatured,
        isActive: p.isActive,
        subscriberCount: p._count.subscriptions,
      }))}
    />
  );
}
