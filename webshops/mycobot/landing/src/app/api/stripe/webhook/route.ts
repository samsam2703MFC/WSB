import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { verifyWebhook } from '@/lib/stripe';

/**
 * POST /api/stripe/webhook (§3.2)
 *
 * Listens for checkout.session.completed and
 * customer.subscription.updated/deleted and keeps tfb_subscriptions in step.
 *
 * The signature is verified before anything is read out of the body. While
 * STRIPE_WEBHOOK_SECRET is unset every call is rejected with 400 — an unverified
 * webhook must never write to the database.
 */

// The raw body is required for signature verification, so no parsing middleware.
export const dynamic = 'force-dynamic';

interface StripeSubscriptionLike {
  id?: string;
  customer?: string;
  status?: string;
  current_period_end?: number;
  metadata?: { plan_key?: string };
}

export async function POST(request: Request) {
  const rawBody = await request.text();
  const signature = request.headers.get('stripe-signature');

  let verification;
  try {
    verification = await verifyWebhook(rawBody, signature);
  } catch (error) {
    console.error('Stripe webhook verification threw', error);
    return NextResponse.json({ error: 'Vérification impossible.' }, { status: 500 });
  }

  if (!verification.ok) {
    // 400, not 401: Stripe retries on 4xx/5xx either way, and the reason is logged.
    console.warn('Stripe webhook rejected:', verification.reason);
    return NextResponse.json({ error: verification.reason }, { status: 400 });
  }

  const event = verification.event;

  try {
    switch (event.type) {
      case 'checkout.session.completed': {
        const session = event.data as { customer?: string; subscription?: string; customer_email?: string; metadata?: { plan_key?: string } };
        const planKey = session.metadata?.plan_key;
        if (!planKey || !session.subscription) break;
        const plan = await prisma.plan.findUnique({ where: { key: planKey } });
        if (!plan) break;
        await prisma.subscription.upsert({
          where: { stripeSubscriptionId: session.subscription },
          create: {
            email: session.customer_email ?? '',
            stripeCustomerId: session.customer ?? null,
            stripeSubscriptionId: session.subscription,
            planId: plan.id,
            status: 'active',
          },
          update: { status: 'active', stripeCustomerId: session.customer ?? null },
        });
        break;
      }

      case 'customer.subscription.updated': {
        const sub = event.data as StripeSubscriptionLike;
        if (!sub.id) break;
        await prisma.subscription.updateMany({
          where: { stripeSubscriptionId: sub.id },
          data: {
            status: sub.status ?? 'active',
            currentPeriodEnd: sub.current_period_end ? new Date(sub.current_period_end * 1000) : null,
          },
        });
        break;
      }

      case 'customer.subscription.deleted': {
        const sub = event.data as StripeSubscriptionLike;
        if (!sub.id) break;
        await prisma.subscription.updateMany({
          where: { stripeSubscriptionId: sub.id },
          data: { status: 'canceled' },
        });
        break;
      }

      default:
        // Unhandled types are acknowledged so Stripe stops retrying them.
        break;
    }
  } catch (error) {
    console.error(`Stripe webhook ${event.type} failed`, error);
    // 500 so Stripe retries — the event has not been applied.
    return NextResponse.json({ error: 'Traitement impossible.' }, { status: 500 });
  }

  return NextResponse.json({ received: true });
}
