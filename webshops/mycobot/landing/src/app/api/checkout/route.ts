import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { createCheckoutSession, stripeConfigured } from '@/lib/stripe';
import { rateLimit, clientIp } from '@/lib/rate-limit';

/**
 * POST /api/checkout — starts a Stripe Checkout session from the plan's
 * stripe_price_id and returns the redirect URL (§3.2).
 *
 * Stripe is stubbed in this cut: with no keys configured the route answers 200
 * with `{ status: 'stub' }` so the pricing UI can report honestly instead of
 * pretending to redirect. See src/lib/stripe.ts to switch it on.
 */
export async function POST(request: Request) {
  const limit = rateLimit(`checkout:${clientIp(request)}`, 20, 10 * 60 * 1000);
  if (!limit.ok) {
    return NextResponse.json({ error: 'Trop de tentatives.' }, { status: 429, headers: { 'Retry-After': String(limit.retryAfter) } });
  }

  let body: { planKey?: unknown; email?: unknown };
  try {
    body = (await request.json()) as typeof body;
  } catch {
    return NextResponse.json({ error: 'Corps de requête invalide.' }, { status: 400 });
  }

  const planKey = typeof body.planKey === 'string' ? body.planKey : '';
  if (!planKey) return NextResponse.json({ error: 'planKey manquant.' }, { status: 400 });

  const plan = await prisma.plan.findFirst({ where: { key: planKey, isActive: true } });
  if (!plan) return NextResponse.json({ error: 'Plan inconnu ou inactif.' }, { status: 404 });

  // amount NULL means "sur devis" — those plans route to the contact form, never
  // to Checkout.
  if (plan.amount == null) {
    return NextResponse.json({ status: 'contact', planKey: plan.key }, { status: 200 });
  }

  if (!plan.stripePriceId) {
    return NextResponse.json(
      { status: 'stub', reason: `tfb_plans.stripe_price_id est vide pour « ${plan.key} ».`, planKey: plan.key },
      { status: 200 },
    );
  }

  const origin = new URL(request.url).origin;
  try {
    const result = await createCheckoutSession({
      priceId: plan.stripePriceId,
      planKey: plan.key,
      email: typeof body.email === 'string' ? body.email : undefined,
      successUrl: `${origin}/?checkout=success`,
      cancelUrl: `${origin}/?checkout=cancelled`,
    });
    return NextResponse.json({ ...result, planKey: plan.key, configured: stripeConfigured() });
  } catch (error) {
    console.error('POST /api/checkout failed', error);
    return NextResponse.json({ error: 'Session de paiement indisponible.' }, { status: 503 });
  }
}
