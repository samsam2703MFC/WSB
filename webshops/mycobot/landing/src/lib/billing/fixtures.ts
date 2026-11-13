import type { BillingSnapshot } from './types';

/**
 * Fixture rows for the billing service, ported from the design handoff
 * (ui_kits/backoffice/billing-data.js). Shapes and status vocabularies match
 * uploads/billing_service_db.sql exactly.
 *
 * These are used only when BILLING_SERVICE_URL is unset, so the console renders
 * and can be reviewed before the service is reachable. Every screen that shows
 * them also says so — see BillingFixtureNotice.
 */
export const BILLING_FIXTURE: BillingSnapshot = {
  fixture: true,

  tenants: [
    { id: 'a1f0…c3', slug: 'belleville', name: 'Belleville Bistro', stripe_customer_id: 'cus_QBelleville', internal_api_url: 'https://api.belleville.fr', admin_email: 'finance@belleville.fr', api_key_hint: 'tfb_live_9c41…c7a2', stores: 64, licenses: 71, onboarded_at: '12.03.2025', archived_at: null },
    { id: 'b7c2…19', slug: 'kebab-house', name: 'Kebab House', stripe_customer_id: 'cus_QKebab', internal_api_url: 'https://api.kebabhouse.be', admin_email: 'admin@kebabhouse.be', api_key_hint: 'tfb_live_4b18…9f01', stores: 41, licenses: 38, onboarded_at: '04.09.2025', archived_at: null },
    { id: 'c4d8…7e', slug: 'sushi-loop', name: 'Sushi Loop', stripe_customer_id: 'cus_QSushi', internal_api_url: 'https://api.sushiloop.nl', admin_email: 'ops@sushiloop.nl', api_key_hint: 'tfb_live_77aa…31bd', stores: 23, licenses: 19, onboarded_at: '21.01.2026', archived_at: null },
    { id: 'd9e1…4a', slug: 'green-bowl', name: 'Green Bowl', stripe_customer_id: null, internal_api_url: 'https://api.greenbowl.pl', admin_email: 'hello@greenbowl.pl', api_key_hint: null, stores: 12, licenses: 0, onboarded_at: null, archived_at: null },
  ],

  packages: [
    { id: 'p-core', code: 'core', name: 'Core', description: 'Socle toujours actif, aucune facturation Stripe.', stripe_price_id: null, is_free: true, is_active: true, licenses: 140 },
    { id: 'p-admin', code: 'admin', name: 'Admin', description: 'Back office réseau : tenants, magasins, licences.', stripe_price_id: 'price_1QsAdmin', is_free: false, is_active: true, licenses: 46 },
    { id: 'p-sklep', code: 'sklep', name: 'Sklep', description: 'Boutique et caisse par point de vente.', stripe_price_id: 'price_1QsSklep', is_free: false, is_active: true, licenses: 82 },
  ],

  // pricing_tiers — volume bands per package. NULL max_qty = no upper limit,
  // NULL valid_to = currently in effect.
  tiers: [
    { id: 't1', package: 'sklep', min_qty: 1, max_qty: 9, unit_price: 149.0, currency: 'EUR', valid_from: '01.01.2026', valid_to: null },
    { id: 't2', package: 'sklep', min_qty: 10, max_qty: 49, unit_price: 129.0, currency: 'EUR', valid_from: '01.01.2026', valid_to: null },
    { id: 't3', package: 'sklep', min_qty: 50, max_qty: null, unit_price: 109.0, currency: 'EUR', valid_from: '01.01.2026', valid_to: null },
    { id: 't4', package: 'admin', min_qty: 1, max_qty: null, unit_price: 89.0, currency: 'EUR', valid_from: '01.01.2026', valid_to: null },
    { id: 't5', package: 'sklep', min_qty: 1, max_qty: 9, unit_price: 159.0, currency: 'EUR', valid_from: '01.01.2025', valid_to: '31.12.2025' },
  ],

  licenses: [
    { id: 'l-0091', tenant: 'Belleville Bistro', store: 'Paris Bastille', package: 'sklep', stripe_subscription_id: 'sub_1QaBelBast', discount_percent: null, status: 'active', trial_ends_at: null, current_period_end: '05.08.2026', cancel_at_period_end: false, unit_price: 149.0 },
    { id: 'l-0092', tenant: 'Belleville Bistro', store: 'Lyon Part-Dieu', package: 'sklep', stripe_subscription_id: 'sub_1QaBelLyon', discount_percent: 10.0, status: 'active', trial_ends_at: null, current_period_end: '05.08.2026', cancel_at_period_end: false, unit_price: 134.1 },
    { id: 'l-0093', tenant: 'Belleville Bistro', store: null, package: 'admin', stripe_subscription_id: 'sub_1QaBelAdmin', discount_percent: null, status: 'active', trial_ends_at: null, current_period_end: '05.08.2026', cancel_at_period_end: false, unit_price: 89.0 },
    { id: 'l-0117', tenant: 'Sushi Loop', store: 'Amsterdam Zuid', package: 'admin', stripe_subscription_id: 'sub_1QaSushiAms', discount_percent: null, status: 'trialing', trial_ends_at: '14.08.2026', current_period_end: '14.08.2026', cancel_at_period_end: false, unit_price: 89.0 },
    { id: 'l-0121', tenant: 'Green Bowl', store: 'Kraków Kazimierz', package: 'sklep', stripe_subscription_id: 'sub_1QaGreenKra', discount_percent: null, status: 'past_due', trial_ends_at: null, current_period_end: '02.07.2026', cancel_at_period_end: false, unit_price: 129.0 },
    { id: 'l-0122', tenant: 'Green Bowl', store: 'Warszawa Wola', package: 'sklep', stripe_subscription_id: null, discount_percent: null, status: 'pending', trial_ends_at: null, current_period_end: null, cancel_at_period_end: false, unit_price: 129.0 },
    { id: 'l-0104', tenant: 'Kebab House', store: 'Bruxelles Midi', package: 'sklep', stripe_subscription_id: 'sub_1QaKebMidi', discount_percent: null, status: 'blocked', trial_ends_at: null, current_period_end: '18.07.2026', cancel_at_period_end: false, unit_price: 149.0 },
    { id: 'l-0088', tenant: 'Kebab House', store: 'Anvers Centraal', package: 'sklep', stripe_subscription_id: 'sub_1QaKebAnv', discount_percent: null, status: 'canceled', trial_ends_at: null, current_period_end: '30.06.2026', cancel_at_period_end: true, unit_price: 149.0 },
  ],

  invoices: [
    { id: 'i-4410', tenant: 'Belleville Bistro', store: 'Paris Bastille', stripe_invoice_id: 'in_1QzB4410', amount_due: 149.0, amount_paid: 149.0, currency: 'EUR', status: 'paid', period: '01.07 — 31.07.2026', pdf: true },
    { id: 'i-4411', tenant: 'Belleville Bistro', store: 'Lyon Part-Dieu', stripe_invoice_id: 'in_1QzB4411', amount_due: 134.1, amount_paid: 134.1, currency: 'EUR', status: 'paid', period: '01.07 — 31.07.2026', pdf: true },
    { id: 'i-4429', tenant: 'Green Bowl', store: 'Kraków Kazimierz', stripe_invoice_id: 'in_1QzG4429', amount_due: 129.0, amount_paid: 0, currency: 'EUR', status: 'open', period: '01.07 — 31.07.2026', pdf: true },
    { id: 'i-4430', tenant: 'Kebab House', store: 'Bruxelles Midi', stripe_invoice_id: 'in_1QzK4430', amount_due: 149.0, amount_paid: 0, currency: 'EUR', status: 'uncollectible', period: '01.06 — 30.06.2026', pdf: true },
    { id: 'i-4431', tenant: 'Sushi Loop', store: 'Amsterdam Zuid', stripe_invoice_id: 'in_1QzS4431', amount_due: 0, amount_paid: 0, currency: 'EUR', status: 'draft', period: '01.08 — 31.08.2026', pdf: false },
  ],

  events: [
    { id: 'e-9912', license: 'l-0121', event_type: 'invoice.payment_failed', stripe_event_id: 'evt_1QzF9912', occurred_at: '28.07.2026 04:12' },
    { id: 'e-9908', license: 'l-0117', event_type: 'customer.subscription.created', stripe_event_id: 'evt_1QzF9908', occurred_at: '27.07.2026 11:03' },
    { id: 'e-9904', license: 'l-0104', event_type: 'license.blocked', stripe_event_id: null, occurred_at: '26.07.2026 09:41' },
    { id: 'e-9901', license: 'l-0092', event_type: 'customer.subscription.updated', stripe_event_id: 'evt_1QzF9901', occurred_at: '25.07.2026 17:22' },
  ],

  sync: [
    { id: 4821, license: 'l-0121', attempts: 3, next_retry: '30.07.2026 08:15', last_error: 'HTTP 502 — /internal/license-sync timeout (10s)' },
    { id: 4822, license: 'l-0122', attempts: 1, next_retry: '30.07.2026 07:40', last_error: 'HTTP 401 — api_key_hash mismatch' },
  ],
};
