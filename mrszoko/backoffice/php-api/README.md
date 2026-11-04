# php-api — `webshop_mrszoko` backend

The server the back-office is wired to. It exposes the `/franchisor/*` endpoints
over same-origin `<origin>/mrszoko/backoffice/api`, reading and writing the **`webshop_mrszoko`**
database whose tables are all prefixed **`wsm_`**. No business data is hardcoded
in the front-end: every screen reads these tables through this API.

## Run it (local / CI — zero server needed)

PHP ships `pdo_sqlite`, so out of the box the API runs on a local SQLite mirror
of the schema — the same tables, seeded identically:

```bash
php migrate.php --fresh      # build webshop_mrszoko + wsm_* tables and seed
./serve.sh                   # → http://localhost:8090/franchisor/kpis
php tests/e2e_delivery.php    # end-to-end delivery proof (create→assign→confirm)
```

## Run it against MySQL (production)

**On the server the API runs against the MySQL database `mrszoko`** (the one
visible in phpMyAdmin). The deploy workflow provisions it automatically on
first run: dedicated `mrszoko_app` user + `config.local.php` (engine mysql,
db `mrszoko`, generated password and admin token). `config.local.php` is
gitignored, web-denied and never overwritten by deploys — read the admin token
from it over SSH. The schema is applied INTO the configured database (the
canonical file's `CREATE DATABASE webshop_mrszoko`/`USE` header is skipped),
so the DB name is free.

Manual/env alternative:

```bash
export WSM_DB_ENGINE=mysql
export WSM_DB_HOST=127.0.0.1 WSM_DB_NAME=mrszoko
export WSM_DB_USER=... WSM_DB_PASS=...
export WSM_ADMIN_TOKEN=<shared admin token>
php migrate.php              # applies schema/webshop_mrszoko.mysql.sql + seed
```

The canonical MySQL DDL is `schema/webshop_mrszoko.mysql.sql`; the SQLite
mirror (`schema/webshop_mrszoko.sqlite.sql`, dev/CI fallback) is kept
structurally identical.

## Authentication

Nothing under `/franchisor/*` is public — **reads included**. Two ways in, and
neither has a default secret (fail-closed):

1. **User session** — `POST /auth/login {email, password}` against
   `wsm_users.password_hash` (`password_hash`/`PASSWORD_DEFAULT`). Sets an
   HttpOnly, SameSite=Lax session cookie (Secure as soon as the request is
   HTTPS); the id is regenerated on login. Idle sessions expire after 8 h.
   Five failed attempts lock the account for 15 minutes; wrong password and
   unknown e-mail return the exact same `401`, after a randomised delay.
2. **Service token** — `X-Admin-Token`, for automation and the test suites.
   Compared with `hash_equals`. **If no token is configured the whole token
   path is disabled** — there is no `dev-admin-token` fallback any more.

Roles from `wsm_users` are enforced, not decorative: any active account may
**read**, only role `Centrala` may **write** (`403 forbidden_role` otherwise).
The audit trail records the real actor, not a generic label.

Accounts have no password until one is set, so the demo users cannot log in:

```bash
php migrate.php --set-password <email> <password> [role] [name]
php migrate.php --ensure-admin <email> <password>   # no-op if a login exists
```

CORS emits nothing by default (front and API are same-origin). Set
`WSM_CORS_ORIGIN` to an explicit origin — never `*`, since requests carry a
session cookie.

## tpay.com and InPost — data captured

Neither API is called yet; what exists is the **data they will require**, captured
and validated at entry (`commerce.php`). A wrong NIP gets the invoice rejected and
an 8-digit phone gets the shipment rejected — cheaper to block on the form than to
discover it at checkout.

| Need | Where | Validation |
| --- | --- | --- |
| tpay payer | `wsm_clients.email` (mandatory), `phone` | e-mail syntax; phone normalised to 9 digits (`+48` stripped) |
| tpay invoice | `nip`, `vat_eu`, `bill_street/building/postcode/city/country` | NIP checksum; VIES format; postcode `NN-NNN` validated on the **raw** input |
| tpay VAT | `wsm_products.vat_rate` | Polish rates only: 0 / 5 / 8 / 23 % (`23` accepted as 23 %) |
| InPost receiver | `client_type`, `first_name`, `last_name`, `phone` | an individual must be named — InPost needs a named receiver |
| InPost locker | `wsm_client_points.inpost_point`, `delivery_method` | code format (`KRA010`), stored uppercase |
| InPost courier | `street`, `building`, `postcode`, `city`, `country` | all mandatory for the courier method |
| InPost parcel | `wsm_products.weight_g`, `length/width/height_mm`, `parcel_template` | ≤ 25 kg; A/B/C **deduced** from dimensions, recomputed whenever they change |

Invalid input returns `422` with a per-field map (`{"error":"validation","fields":{…}}`),
which the console shows next to the offending field. Proof: `tests/e2e_commerce.php`
(40 assertions).

## The shop — who decides the price

The storefront lives in `../../shop` and is rendered **server-side by PHP from
these tables**. The browser only ever sends product ids and quantities; every
price, VAT amount, shipping fee and total is recomputed here (`shop.php`). A
tampered basket changes nothing about what gets charged — `tests/e2e_shop.php`
asserts exactly that.

Money is carried in **grosze (integers)**. Floats have no business holding money:
`0.1 + 0.2` is not `0.3` in binary, and a VAT line has to add up. Consumer prices
are stored gross (`wsm_products.prix`, Polish B2C convention); net is derived and
VAT is the *remainder*, so `net + VAT == gross` always holds, line by line.

| Table | Holds |
| --- | --- |
| `wsm_orders` | one order: buyer snapshot, delivery target, all totals |
| `wsm_order_items` | frozen lines — an invoice must not move when a price does |
| `wsm_payments` | Stripe Checkout sessions (session id, amount, status, `app_fee`) |
| `wsm_payment_events` | every notification received; `event_key` **UNIQUE** = no double capture |
| `wsm_shipments` | InPost parcel: service, locker code, template, tracking |
| `wsm_order_events` | audit trail per order |
| `wsm_shipping_methods` | delivery options, prices and the countries each one serves |
| `wsm_discount_tiers` | volume discount: minimum weight → percentage |
| `wsm_countries` | where we sell, and which countries are EU (drives reverse charge) |
| `wsm_shop_i18n` | every shop string in pl / uk / en |

**Stock never refuses a sale.** An order beyond stock goes through: we take
what exists (floored at zero, guarded against a concurrent order), record the
shortfall per line in `wsm_order_items.backorder`, flag the order, and tell the
buyer we will confirm the date by e-mail. Turning a customer away because a
shelf is empty loses the customer; giving them a date does not. The console
tags those orders `do potwierdzenia` so somebody actually calls back.

**Volume discount by weight.** `wsm_discount_tiers` holds `min_weight_g` →
`percent`; the highest tier reached wins and tiers never stack. The percentage
is applied to each line's gross price before the VAT split, so `net + VAT ==
gross` still holds line by line and the shipping free-threshold is compared
against the discounted total. The cart tells the buyer how much more weight
unlocks the next tier. Managed at `/mrszoko/backoffice/rabaty.php`, which also
shows what each tier costs on a real product.

The parcel template (A/B/C) is computed from the **whole basket's volume**, not
from the largest single item — two 6 cm bags each fit an 8 cm locker, together
they do not. It stays an estimate (a real packing solver would be needed); the
console labels it as such and the packer can override.

## Stripe Connect — what makes the money real

All payments go through **Stripe Connect**. The platform owner holds the Stripe
platform account and its keys; the shop is a **connected account** (direct
charges: the shop is the merchant of record). On every payment Stripe pays the
platform its commission (`application_fee_amount` = Platforma rate × base,
platform VAT included); the monthly Platforma statement shows it as already
collected and deducts it. Both halves are **embedded**: the payment form
(Checkout, `ui_mode=embedded_page`) inside the shop's *Zapłać* page, and the
shop's account onboarding, payments and payouts (Connect embedded components)
inside the console's *Płatności* screen. API version pinned to
`2026-09-30.endive`, Stripe.js `endive`.

An order becomes *opłacone* only from (1) a webhook whose HMAC signature is
verified, or (2) a Checkout Session read back **from Stripe** with the secret
key on the return page. Never from the browser alone. Guards, all tested
(`e2e_stripe.php`, `e2e_shop.php`):

1. **Signature first.** `Stripe-Signature: t=…,v1=…`, HMAC-SHA256 of
   `t.payload`, compared with `hash_equals`, at most 5 minutes old. A rejected
   event is archived under a throwaway key — if it took the real idempotency
   key, knowing an `evt_…` would be enough to stop a legitimate payment.
2. **Recheck everything.** Order (`client_reference_id`), connected account,
   currency and amount must all match.
3. **Idempotency in the database.** `wsm_payment_events.event_key UNIQUE`.
   Stripe resends until it gets a 2xx; the unique index stops a second capture.

Without the webhook secret, **every** webhook is refused (`503`). Without the
platform keys and the shop's connected account, no session is created: the
order still exists and waits. The platform keys are written only by the
superadmin (*Płatności*), never from *Integracje*.

## Fiscal receipts — Fakturownia + Paragony.pl

The shop's own *paragon* (`PAR/…`, `invoice.php`) is an internal document. A
sale to a private buyer only exists for the tax office once it went through a
cash register, and a software-only register (*kasa wirtualna*) is reserved to
transport, hotels, catering and coal (MF regulation of 29 May 2020). A shop
selling goods needs a physical **online fiscal printer**.

`fiskal.php` drives one through Fakturownia's public API: it creates a
`receipt` from the **frozen** paragon (every line, VAT 23/8/5/0 %, sum checked
to the grosz) and asks for `fiscal_print` — `mode=e-receipt` when the buyer
ticked the e-paragon consent at checkout (`wsm_orders.paragon_forma = 'e'`),
`mode=print` otherwise, so the paper slip goes in the parcel. The e-paragon
link (`e_receipt_view_url`) arrives later, when the printer issues it: the
`invoice:update` webhook only **points** at a receipt, which is re-read from
Fakturownia with our own token, then the buyer gets the link by mail, once.

Our paragon number travels as `oid` with `oid_unique: yes`, so a lost response
is recovered by **finding** the receipt, never by creating a second one. A
failed creation retries on its own; a failed print is retried only by hand
(*Zamówienia*), because printing twice records the sale twice.

Needed at the company: a Fakturownia account with the Paragony.pl module, an
online fiscal printer that supports e-receipts (Posnet / Novitus / Elzab
*Online*), and a computer running the Paragony.pl program next to it. In
Fakturownia, leave **automatic fiscalisation after API** off: the shop orders it.

InPost is the same shape: `inpost.php` builds the exact ShipX payload and shows
it in the console *before* the integration is switched on, so missing data is
visible immediately. Without a ShipX token nothing is sent — the parcel is
prepared by hand and the shipment row stays `oczekuje_na_konfiguracje`.

## Marketing — newsletter and SMS

Four console screens under *Marketing*: **Newsletter**, **SMS**,
**Subskrybenci** (the consent register) and **Kampanie** (the existing
win-back mails).

**Who receives what.** A newsletter goes to people who confirmed a signup
(double opt-in: the footer form or the checkout box sends a link, nothing is
sent before the click). Since 10 November 2024 commercial e-mail needs
**prior consent** (*Prawo komunikacji elektronicznej*, art. 398), and the
"existing customers" exception is uncertain in Polish law, so buyers who did
not tick the box are **left out by default**: the buyer segments (regulars,
dormant, companies…) only narrow the confirmed list. Including them is a
switch, `WSM_MKT_KLIENCI_BEZ_ZGODY=1` or *Integracje → Marketing*, to turn on
only on a lawyer's advice. An SMS goes **only** to numbers whose owner ticked
the separate SMS box. At checkout that number is the delivery phone and the
consent is active at once; from the footer form anyone could type anyone's
number, so the consent waits until the **phone** opens the link received by
SMS (one confirmation SMS per number per day, the footer offers SMS only once
SMSAPI works). E-mail confirmation and SMS confirmation both take a button
press: mail scanners open links on their own.

Sending a campaign, erasing a record and exporting the register are reserved
to administrators; *Sprzedaż* prepares, tests and unsubscribes on request.
Campaigns wait for **Publiczny adres sklepu** (`WSM_SHOP_URL`): links, opt-outs
and delivery reports are built from it, and without it a forged `Host` header
could put a foreign address in a confirmation mail.

An e-mail refusal (unsubscribe link, one-click `List-Unsubscribe-Post`,
*Usuń dane*) lands in the same `wsm_camp_stop` list the win-back mails already
honour, so a later purchase never brings the address back. An SMS refusal
(link, `STOP` reported by the operator) ends the SMS consent, which every send
checks again just before it goes. The register keeps, per address, the date,
the source, the exact consent text and the IP: that is the answer to "where
did you get my address?" (CSV export).

**Sending.** Nothing leaves at the click: a campaign queues one line per
recipient (`nl-{id}-{email}` in `wsm_messages`, `wsm_sms` for SMS) and the
**pump** drains it in small batches (`wsm_mkt_pump`). Each line is claimed
(`kolejka` → `wysylanie`) before it goes, so two pumps never send it twice.
SMS leave only between 8:00 and 21:00 Warsaw time and skip a number that
unsubscribed after queueing. The pump runs when the *Newsletter* or *SMS*
screen opens and from its *Wyślij teraz partię* button; for unattended
sending, the server can run `php api/kolejka.php 30 30` every minute (CLI
only, file-locked, answers 403/404 from the web). The deploy does **not**
install that schedule.

**SMSAPI** is the same client as the CRM: OAuth token, approved sender name,
platform `pl` or `com`. The cost is shown before sending (GSM-7 160/153,
Unicode 70/67 per part, the unsubscribe line included). Delivery reports come
back on `api/shop/sms/raport?k=…` (HMAC key, HTTPS only); nothing has to be
pasted in the SMSAPI panel.

**AI drafting** (Claude, the same key as translations) proposes a newsletter
from a brief, SMS variants under the length limit, and the uk/en versions.
It never invents a discount, a code or a product: products come from the
catalogue, the code from *Rabaty*, and every draft is edited by a person.

## Stock receipts from supplier invoices

*Magazyn → Przyjęcie z faktury zakupu* turns the supplier's invoice into a PZ
instead of retyping it. Two ways in:

- **XML from KSeF** (FA(2), FA(3)): since 2026 every Polish B2B invoice exists
  in this form. Read exactly, offline, by local element names; external
  entities are never resolved.
- **PDF**: sent to Claude (the translation key) as a document, with the answer
  forced into a fixed tool schema. Claude proposes; every figure is recounted
  here (quantity × price, line sums against the totals, buyer NIP) and what
  does not add up is flagged, not silently fixed. Without the key, PDF import
  says so and points to the XML.

Each line is matched to a shop product: what was learned for this supplier,
then EAN, supplier code = SKU, then name similarity. The **factor** says how
many shop units one invoice unit is (25 kg into 2.5 kg bags = 0.4 → 10 bags);
for kg it is derived from the product's *net* content (`unit_label`, then the
name), never from the shipping weight. Transport and deposits are marked
"pomiń". Nothing enters stock until a person clicks *Przyjmij*: the posting
is one transaction (invoice claimed, PZ written, each line linked to its stock
movement, mapping learned, last net cost saved to the product for margins),
and an invoice already received, by number and supplier, cannot be received
again. Files are kept under `api/data/zakupy/` (denied by the web server,
excluded from deploys) and opened from the console only.

*Magazyn → product → "wykresy i historia"* shows one product end to end: stock
over time, units in and out per month, the net purchase price of each delivery,
then every delivery (supplier, invoice, VAT, net, gross) and every exit
(customer, order, WZ, parcel number with the carrier's tracking link).

## Product photos

Uploaded from the console (`/mrszoko/backoffice/produkty.php`, role `Centrala`),
or through `POST /franchisor/product-photo`.

A file is not an image because it ends in `.jpg`. It is one because we managed
to decode it — so every upload is decoded and **re-encoded** by GD (`media.php`).
What lands in `shop/media/` is an image *we* produced: metadata, comments and
anything else that might have travelled inside are left at the door. It is also
capped at 1400 px and written as WebP.

The filename is random — a user-chosen name is a user-chosen path. The extension
comes from the re-encoded format, not from what was claimed. And
`shop/media/.htaccess` disables script execution, so a perfectly valid image
carrying PHP is downloaded, never interpreted. `image_url` accepts only our own
`media/<32 hex>.webp|jpg` or an `https://` URL — `http://` would be blocked by
the browser as mixed content.

Replacing a photo deletes the old file. `media/` is not versioned and `rsync`
runs without `--delete`, so deploys never carry the photos away.

## VIES — is that VAT number real?

Format was never proof. `PL5252248481` *looks* like a VAT number; only VIES (the
Commission's VAT Information Exchange System) knows whether it exists and whose
it is. `vies.php` asks.

**The rule that matters.** VIES queries each national tax administration live,
and those administrations go down — routinely. So two things that nothing forces
us to conflate are kept apart:

| VIES says | We record | Consequence |
| --- | --- | --- |
| `INVALID`, `INVALID_INPUT` | `invalid` | the entry is **refused** (`422` on `vat_eu`) |
| `MS_UNAVAILABLE`, `TIMEOUT`, `*_BLOCKED`, `*_MAX_CONCURRENT_REQ`, network error | `unavailable` | **nothing is blocked** — saved, flagged for re-check |
| `isValid` | `valid` | company name, address and consultation number stored |

Refusing a sale because a Commission service is down would be a bigger loss than
the one being prevented. `tests/e2e_vies.php` asserts precisely this asymmetry.

**Proof.** With our own VAT number set (`WSM_VIES_REQUESTER`), VIES returns a
consultation number — that is what stands up to a tax audit, not a screenshot.
Every consultation is appended to `wsm_vies_checks`, including the ones that
answered nothing; the client and the order carry `vat_status`, `vat_checked_at`,
`vat_name`, `vat_consultation`.

**Cost control.** `valid` and `invalid` verdicts are cached for 30 days —
VIES is slow and must not be hammered. `unavailable` is **never** cached: caching
an outage would freeze it. Country prefixes are checked against the real list of
27 member states (Greece is `EL`, not `GR`) plus `XI`, so a non-EU prefix is
refused without a pointless round-trip.

**Reverse charge is applied**, from `wsm_countries` and the VIES verdict:

| Delivery country | EU VAT number | Rate |
| --- | --- | --- |
| Poland — home market | irrelevant | Polish VAT |
| Another member state | VIES says **valid** | **0 %, reverse charge** |
| Another member state | missing, invalid, or VIES silent | Polish VAT |

An outage never exempts: we do not zero-rate on an answer we did not get. The
buyer then pays the **net** price, not the gross one, and the VAT breakdown is
empty.

Countries and carrier coverage are managed in the console
(`/mrszoko/backoffice/kraje.php`). Only Poland is open out of the box, and a
country open for sale but served by no carrier refuses the order rather than
promising a delivery nobody makes.

**OSS threshold.** A private buyer in another member state pays Polish VAT.
That is correct only below the €10,000 EU distance-selling threshold; above it,
the destination country's rate is due under OSS. The console says so on the
same screen — the day that threshold nears, this is where to come back.
Zero-rating also requires proof the goods left Poland: the VAT number alone is
not enough.

Checked at both entry points: `POST /franchisor/client` (console) and the shop
checkout. `POST /franchisor/vies` runs a check on demand without saving anything.

## Configuration

All in `config.php`, entirely env-driven (see the header there).

Every credential below has **no default** and none of them belong in this
repository, which is public. They go in `config.local.php` on the server
(gitignored, web-denied, `chmod 640`) or in GitHub Actions secrets. Copy
`config.local.sample.php` to start.

### What is still missing, and what each one unblocks

`php tools/rapport.php` answers this against the live database at any time,
and separates what *blocks* from what merely *annoys*. The table is the same
list, written out.

| Setting | Env var | Without it |
| --- | --- | --- |
| **Bank account** | `WSM_INV_IBAN` | **No invoice can be issued at all.** The most expensive gap in the list, because it only shows itself at the moment of issuing. |
| **Stripe** | `WSM_STRIPE_SECRET_KEY`, `WSM_STRIPE_PUBLISHABLE_KEY`, `WSM_STRIPE_WEBHOOK_SECRET` (platform keys) + `WSM_STRIPE_ACCOUNT` (the shop's `acct_…`, created from *Płatności*) | The shop takes orders nobody can pay for. |
| **Outgoing mail** | `WSM_MAIL_TRANSPORT=smtp`, `WSM_MAIL_FROM`, `WSM_MAIL_SMTP_HOST`, `WSM_MAIL_SMTP_PORT`, `WSM_MAIL_SMTP_USER`, `WSM_MAIL_SMTP_PASS`, `WSM_MAIL_SMTP_SECURE` | Order confirmations sit in the queue and nobody receives them. |
| **InPost ShipX** | `WSM_INPOST_TOKEN`, `WSM_INPOST_ORG_ID`, `WSM_INPOST_GEOWIDGET_TOKEN` | Parcels are booked by hand. The *Wysyłka* screen shows which ones. |
| **Fiscal printer** | `WSM_FAKTUROWNIA_DOMENA`, `WSM_FAKTUROWNIA_TOKEN`, `WSM_FAKTUROWNIA_DRUKARKA`, `WSM_FAKTUROWNIA_WEBHOOK_TOKEN` | Paragons stay internal documents: no sale reaches a cash register. Only needed if the accountant confirms the shop is not exempt. |
| **KSeF** | `WSM_KSEF_TOKEN`, `WSM_KSEF_PUBLIC_KEY`, `WSM_KSEF_ENV` | Invoices are not filed automatically. The *KSeF* screen still builds the FA(2) XML for manual filing. `WSM_KSEF_PUBLIC_KEY` is a **path on the server** to the Ministry of Finance public key — without it the token cannot be encrypted, so no session opens. |
| **Allegro** | `WSM_ALLEGRO_CLIENT_ID`, `WSM_ALLEGRO_CLIENT_SECRET`, `WSM_ALLEGRO_REFRESH_TOKEN`, `WSM_ALLEGRO_SELLER_ID` | The second sales channel stays shut. The *Allegro* screen still shows which listings would go out and which are blocked. |
| **Superadmin** | `WSM_SUPERADMIN_EMAILS` | The platform-owner module is invisible and its page answers 404. Deliberately not a database role: an account that could grant itself the role could rewrite its own invoice. |
| **SMSAPI** | `WSM_SMSAPI_TOKEN`, `WSM_SMSAPI_NADAWCA`, `WSM_SMSAPI_REGION`, `WSM_SMSAPI_CENA` | SMS campaigns can be written and costed but not sent. Newsletters need **outgoing mail** above instead. |
| **Claude** | `WSM_ANTHROPIC_API_KEY` | The "translate the missing strings" button and the AI drafting in *Newsletter* / *SMS* do not appear, and supplier invoices can be imported from KSeF XML only, not PDF. |

Every one of these integrations is **fail-closed**, and `xxxx` counts as
unconfigured — it is what a demo field holds, and taking it for a credential
would open an integration on nothing. A half-wired integration is worse than
an absent one, because you believe you are selling.

Stripe's mode follows its keys (`sk_test_…` / `sk_live_…`). InPost and Allegro default to **sandbox**; KSeF defaults to the **test**
environment. Set `WSM_INPOST_SANDBOX=0` /
`WSM_ALLEGRO_SANDBOX=0` / `WSM_KSEF_ENV=prod` to go live.

VIES needs no credentials (it is a public service), but set
`WSM_VIES_REQUESTER` to our own VAT number so consultations come back with a
provable reference. `WSM_VIES_ENABLED=0` turns the network call off entirely —
numbers are then checked for shape only.

The company NIP is **not** a secret (it is in the public KRS registry) and
lives in `config.php` with the other invoice defaults.

Editorial content is additive on deploy: `php migrate.php --sync-content` adds
strings shipped since the last release **without** overwriting anything edited
from the console.

## Endpoints

| Method | Route | Source table(s) |
| --- | --- | --- |
| **Authentication** | | |
| POST | `/auth/login` | `wsm_users` — opens a session |
| POST | `/auth/logout` | destroys the session |
| GET | `/auth/me` | current identity (session or service token) |
| **Landing Mister Szoko (public)** | | |
| GET | `/landing/content?lang=pl\|uk\|en` | `wsm_landing_i18n` · `wsm_landing_products` — everything the landing renders (strings + product cards, texts resolved server-side; unknown lang → default `pl`) |
| POST | `/franchisor/landing-string` | upsert/delete one i18n string (admin) |
| POST | `/franchisor/landing-product` | upsert/delete one landing product card (admin) |
| **Sklep — boutique (public)** | | |
| GET | `/shop/catalog?lang=pl\|uk\|en` | `wsm_products` · `wsm_shop_i18n` · `wsm_shipping_methods` |
| GET | `/shop/product/{slug}` | one product, texts resolved server-side |
| POST | `/shop/quote` | prices the basket — **ids and quantities only**, prices ignored |
| POST | `/shop/order` | creates the order, decrements stock, returns the shop's *Zapłać* URL (embedded Stripe Checkout) |
| GET | `/shop/order/{code}?t=…` | order status; the token is compared with `hash_equals` |
| POST | `/shop/stripe/webhook` | signed Stripe webhook (connected accounts), idempotent |
| POST | `/shop/fakturownia/webhook` | Fakturownia `invoice:update`: names a receipt, which is re-read with our token |
| **Boutique côté console (protégé)** | | |
| GET | `/franchisor/orders` | order list |
| GET | `/franchisor/orders/{id}` | one order + events + the ShipX payload it would send |
| GET | `/franchisor/shop-kpis` | orders, paid, pending, revenue, average basket |
| GET | `/franchisor/shop-config` | integration state only — never a secret |
| POST | `/franchisor/orders/{id}/status` | status transition (admin) |
| POST | `/franchisor/orders/{id}/ship` | create the InPost shipment (admin, paid orders only) |
| POST | `/franchisor/product-photo` | multipart upload — decoded and **re-encoded** server-side |
| POST | `/franchisor/vies` | check an EU VAT number against VIES (no write) |
| **Commerce (tpay + InPost)** | | |
| POST | `/franchisor/client` | upsert/delete `wsm_clients` — validated payer + invoice data |
| POST | `/franchisor/client-point` | upsert/delete `wsm_client_points` — locker code or courier address |
| POST | `/franchisor/product` | product governance **and** shipping/VAT fields |
| **Franchisor (console)** | | |
| GET | `/franchisor/kpis` | `wsm_kpis` |
| GET | `/franchisor/shops` | `wsm_shops` |
| GET | `/franchisor/catalog` | `wsm_categories` · `wsm_products` |
| GET | `/franchisor/vouchers` | `wsm_vouchers` |
| GET | `/franchisor/pricing-rules` | `wsm_pricing_rules` |
| GET | `/franchisor/params` | `wsm_params` |
| GET | `/franchisor/email-templates` | `wsm_email_templates` |
| GET | `/franchisor/users` | `wsm_users` (+ `wsm_user_shops`) |
| GET | `/franchisor/audit` | `wsm_audit` |
| GET | `/franchisor/catchment` | `wsm_catchment` |
| GET | `/franchisor/menus` | `wsm_products` · `wsm_bundles` → `slots` → `choices` |
| POST | `/franchisor/param` | upsert `wsm_params` (admin) |
| POST | `/franchisor/category` | update `wsm_categories` flags (admin) |
| POST | `/franchisor/catchment` | upsert / delete `wsm_catchment` (admin) |
| **Delivery module** | | |
| GET | `/franchisor/deliveries` | `wsm_deliveries` (+ client/point/driver) |
| GET | `/franchisor/delivery-kpis` | aggregate of `wsm_deliveries` |
| GET | `/franchisor/drivers` | `wsm_drivers` |
| GET | `/franchisor/rounds` | `wsm_rounds` |
| GET | `/franchisor/delivery-clients` | `wsm_clients` (+ `wsm_client_points`) |
| GET | `/franchisor/incidents` | `wsm_incidents` |
| GET | `/franchisor/deliveries/{id}` · `/events` | one delivery · its `wsm_delivery_events` |
| POST | `/franchisor/deliveries` | create a delivery (admin) |
| POST | `/franchisor/deliveries/{id}/assign` | assign driver / round (admin) |
| POST | `/franchisor/deliveries/{id}/status` | status transition (admin) |
| POST | `/franchisor/deliveries/{id}/confirm` | confirm QR/PIN → livrée (admin) |

## Delivery lifecycle

`planifiée → assignée → en_cours → livrée` (or `échouée`). Every transition
writes a `wsm_delivery_events` row; create + confirm also write `wsm_audit`.
Confirmation checks the QR/PIN code issued at creation. The whole flow is
exercised by `tests/e2e_delivery.php` (23 assertions, all green).
