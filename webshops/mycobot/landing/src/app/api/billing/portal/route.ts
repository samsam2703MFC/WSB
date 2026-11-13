import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { createBillingPortalSession } from '@/lib/stripe';
import { getSession } from '@/lib/auth/session';

/**
 * GET /api/billing/portal?subscription=<stripe_subscription_id> (§3.2)
 *
 * Returns a Stripe customer-portal link. Requires a back-office session: the
 * portal exposes another party's billing history, so it is not public.
 * Stubbed while STRIPE_SECRET_KEY is unset.
 */
export async function GET(request: Request) {
  const session = await getSession();
  if (!session) return NextResponse.json({ error: 'Authentification requise.' }, { status: 401 });

  const subscriptionId = new URL(request.url).searchParams.get('subscription');
  if (!subscriptionId) return NextResponse.json({ error: 'Paramètre subscription manquant.' }, { status: 400 });

  const subscription = await prisma.subscription.findUnique({ where: { stripeSubscriptionId: subscriptionId } });
  if (!subscription?.stripeCustomerId) {
    return NextResponse.json({ error: 'Aucun stripe_customer_id pour cet abonnement.' }, { status: 404 });
  }

  try {
    const result = await createBillingPortalSession(
      subscription.stripeCustomerId,
      `${new URL(request.url).origin}/admin/plans`,
    );
    return NextResponse.json(result);
  } catch (error) {
    console.error('GET /api/billing/portal failed', error);
    return NextResponse.json({ error: 'Portail indisponible.' }, { status: 503 });
  }
}
