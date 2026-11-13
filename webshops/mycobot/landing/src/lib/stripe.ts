/**
 * Stripe integration — STUBBED.
 *
 * The `stripe` package is deliberately not a dependency yet: no keys were
 * available for this cut. Everything Stripe-shaped funnels through this module so
 * going live is one file plus `npm i stripe`:
 *
 *   1. `npm i stripe`
 *   2. In `createCheckoutSession`, replace the stub return with
 *      `stripe.checkout.sessions.create({ mode: 'subscription', line_items: [...] })`.
 *   3. In `verifyWebhook`, replace the guard with
 *      `stripe.webhooks.constructEvent(rawBody, signature, STRIPE_WEBHOOK_SECRET)`.
 *   4. In `createBillingPortalSession`, call `stripe.billingPortal.sessions.create`.
 *
 * The routes, the DB writes and the UI already behave as though Stripe were live;
 * only these three calls are absent.
 */

export const STRIPE_SECRET_KEY = process.env.STRIPE_SECRET_KEY || '';
export const STRIPE_WEBHOOK_SECRET = process.env.STRIPE_WEBHOOK_SECRET || '';

/** True once keys are present. Every caller branches on this, never on NODE_ENV. */
export function stripeConfigured(): boolean {
  return STRIPE_SECRET_KEY.length > 0;
}

export interface CheckoutRequest {
  priceId: string;
  planKey: string;
  email?: string;
  successUrl: string;
  cancelUrl: string;
}

export type CheckoutResult =
  | { status: 'redirect'; url: string }
  | { status: 'stub'; reason: string };

export async function createCheckoutSession(req: CheckoutRequest): Promise<CheckoutResult> {
  if (!stripeConfigured()) {
    return {
      status: 'stub',
      reason: 'STRIPE_SECRET_KEY absent — aucune session Checkout créée.',
    };
  }
  // Real implementation goes here (see the header comment). Until then a
  // configured-but-unimplemented call must not silently pretend to succeed.
  throw new Error(
    `Stripe est configuré mais createCheckoutSession n'est pas implémenté (plan ${req.planKey}, price ${req.priceId}).`,
  );
}

export type WebhookVerification =
  | { ok: true; event: { type: string; data: unknown; id: string } }
  | { ok: false; reason: string };

/**
 * Verifies the `stripe-signature` header. Refuses everything while
 * STRIPE_WEBHOOK_SECRET is unset — an unverified webhook must never be trusted to
 * mutate tfb_subscriptions.
 */
export async function verifyWebhook(rawBody: string, signature: string | null): Promise<WebhookVerification> {
  if (!STRIPE_WEBHOOK_SECRET) {
    return { ok: false, reason: 'STRIPE_WEBHOOK_SECRET absent — webhook refusé.' };
  }
  if (!signature) {
    return { ok: false, reason: 'En-tête stripe-signature manquant.' };
  }
  void rawBody;
  throw new Error('Stripe est configuré mais verifyWebhook n\'est pas implémenté.');
}

export async function createBillingPortalSession(customerId: string, returnUrl: string): Promise<CheckoutResult> {
  if (!stripeConfigured()) {
    return { status: 'stub', reason: 'STRIPE_SECRET_KEY absent — portail client indisponible.' };
  }
  throw new Error(`Stripe est configuré mais createBillingPortalSession n'est pas implémenté (${customerId} → ${returnUrl}).`);
}

/** Stripe subscription statuses mapped onto tfb_subscriptions.status. */
export const SUBSCRIPTION_STATUSES = [
  'trialing', 'active', 'past_due', 'canceled', 'unpaid', 'incomplete', 'incomplete_expired', 'paused',
] as const;
