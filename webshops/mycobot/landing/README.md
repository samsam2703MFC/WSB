# The Franchise Buddy — landing + back office

ERP SaaS for franchise networks and store chains in food service and retail. Two
surfaces in one Next.js app:

- **`/[locale]`** — the commercial landing. 8 locales (FR default, AR right-to-left),
  entirely driven by the `tfb_` tables: sections, modules with screenshot carousels,
  Stripe-backed pricing, contact form.
- **`/admin`** — the back office. Two domains side by side: **billing** (multi-tenant
  licences, Stripe invoices, price tiers, sync queue) and **landing content**
  (sections, modules, brands, plans, translations, contact inbox).

Built from the Claude Design handoff *The Franchise Buddy Design System*, plus
`prompt_design_tfb.md`, `prompt_technique_tfb.md` and `billing_service_db.sql`.

## Stack

| Piece | Choice |
| --- | --- |
| Framework | Next.js 15 (App Router) + React 19 + TypeScript |
| Styling | The design system's CSS custom properties, plus Tailwind wired to the same tokens |
| Database | **MySQL** via Prisma — the `tfb_landing` schema |
| Payments | Stripe — **stubbed** in this cut, see [Stripe](#stripe) |
| Auth | Signed JWT session cookie (`jose`), scrypt password hashes |
| File storage | Server filesystem under `STORAGE_PATH`; the DB stores only relative paths |

**On the database engine:** `prompt_technique_tfb.md` §1 suggested PostgreSQL but marked
the stack *« à adapter »*. The live database is MySQL (`tfb_landing`), and the billing
service is MySQL too, so the schema targets MySQL.

## Getting started

```bash
npm install
cp .env.example .env        # then fill in DATABASE_URL and ADMIN_SESSION_SECRET
npx prisma migrate deploy   # creates every tfb_ table
npm run seed                # 8 languages, 8 sections, 6 brands, 8 modules, 3 plans, FR/EN/AR copy
npm run dev
```

- Landing: http://localhost:3000 (redirects to your best matching locale)
- Back office: http://localhost:3000/admin
- Seeded account: `admin@franchisebuddy.eu` / `changeme` — **change it.** Override with
  `SEED_ADMIN_EMAIL` and `SEED_ADMIN_PASSWORD` before seeding.

Pour un vrai serveur — création de la base, droits, migration, seed, sync, stockage
des fichiers, **mise à jour du code** (§11) et dépannage — la marche à suivre complète est dans
[`docs/DEPLOIEMENT-BASE.md`](docs/DEPLOIEMENT-BASE.md). La structure des 12 tables
est aussi disponible en SQL brut dans [`docs/sql/`](docs/sql/) pour les hébergements
où seul phpMyAdmin est accessible.

Generate a session secret with:

```bash
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

### Environment

See `.env.example`. `DATABASE_URL` and `ADMIN_SESSION_SECRET` are required; everything
else has a working default or degrades explicitly.

## How the data model works

Every table is `tfb_`-prefixed, `snake_case`, per `prompt_technique_tfb.md` §2. Two rules
run through the whole app:

**No copy in the code.** Every string the landing renders is a `tfb_translations` row.
Chrome and section copy live under a single pseudo-entity — `entity_type = 'ui'`,
`entity_id = 0`, and `field` is a dotted key (`hero.title`, `nav.modules`,
`group.finance`). Module, plan, brand and screenshot copy hang off their own rows.
Multi-value fields (feature lists, explanation bullets) are pipe-joined.

**No media in the database.** Uploads are written to `<STORAGE_PATH>/<category>/` and
the DB keeps the relative path (`/storage/screenshots/scan-a1b2c3.png`). They are served
back through `GET /api/storage/[...path]`, which refuses anything escaping the storage
root and sends uploaded SVGs under a restrictive CSP.

## Rester à jour — les modules viennent des dépôts

La landing n'écrit pas la fiche de ses modules : **chaque dépôt module publie la
sienne**, dans `.tfb/module.json`, et la landing la récupère.

```bash
npm run sync:modules                              # lit les manifestes sur GitHub, écrit en base
npm run sync:modules -- --dry-run                 # valide et rapporte, sans rien écrire
npm run sync:modules -- --from-disk /chemin       # lit des clones locaux au lieu de GitHub
npm run sync:modules -- --only signage,delivery   # se limite à quelques dépôts
npm run sync:modules -- --retire-unlisted         # désactive les modules sans manifeste
```

Le manifeste décrit le module, ses **fonctions** et les **captures** qui les
illustrent — le contrat complet est dans `src/lib/sync/manifest.ts` :

```json
{
  "key": "signage", "slug": "signage", "group": "Marketing", "icon": "panels-top-left",
  "name":        { "fr": "Régie d'affichage", "en": "Digital signage" },
  "description": { "fr": "Les écrans du magasin pilotés depuis un seul back office." },
  "overview":    { "fr": "Un magasin affiche des prix, des promos et des menus…" },
  "metric": { "value": "0", "label": { "fr": "clé USB en magasin" } },
  "screenshots": [{ "file": "docs/landing/signage-accueil.png", "alt": { "fr": "Accueil de la régie" } }],
  "features": [
    { "key": "compositeur", "icon": "layers",
      "name": { "fr": "Compositeur de film" },
      "description": { "fr": "La bibliothèque d'éléments et la playlist qui en fait un film…" },
      "screenshots": [{ "file": "docs/landing/signage-compositeur.png" }] }
  ]
}
```

Ce que la sync écrit : `tfb_modules` (upsert sur `key`), `tfb_module_features`
(upsert sur `module_id` + `key`), `tfb_module_screenshots` (réécrites, fichiers
copiés sous `STORAGE_PATH`) et les `tfb_translations` correspondantes. `fr` est
obligatoire dans chaque bloc de copie — c'est la locale de repli.

Trois garde-fous : une fonction retirée d'un manifeste est **désactivée**, jamais
supprimée, donc ses traductions survivent à un mauvais manifeste ; un module sans
manifeste n'est touché que si vous passez `--retire-unlisted`, et seulement quand
tous les dépôts ont répondu ; un groupe sans traduction `group.<valeur>` est
signalé dans le rapport.

Un dépôt qui n'a **pas encore** publié sa fiche est signalé et ignoré, sans faire
échouer la commande — c'est un état connu, pas une panne. Une fiche **malformée ou
injoignable**, elle, sort en code 1 : c'est ce qui doit réveiller quelqu'un.

La liste des dépôts interrogés est `content/modules.repos.json`.

**En production, la sync tourne sur le serveur**, pas dans GitHub Actions —
`deploy/tfb-sync.timer`, toutes les 10 minutes. La CI se contente de valider les
fiches : elle ne peut pas écrire, parce que la sync copie les captures dans
`STORAGE_PATH` et que le disque d'un runner disparaît avec le job. Les pages étant
en ISR (`revalidate = 60`), un `git push` dans un dépôt module est en ligne en une
dizaine de minutes, sans redéploiement. La mise en place complète est dans
[`docs/DEPLOIEMENT-BASE.md`](docs/DEPLOIEMENT-BASE.md) §9.

### Fallback

FR is `tfb_languages.is_default`. A missing or empty translation resolves to FR — so all
8 locales are routable, and the 5 unauthored ones (NL, DE, PL, UK, RU) render French
until a translator fills them in. The editor shows those cells as
« Vide — fallback FR », and clearing a cell **deletes** the row rather than storing `''`,
because an absent row is what the fallback keys off.

### RTL

Arabic is a full mirror driven by one attribute: `dir="rtl"` on `<html>`. Everything
downstream uses logical properties only (`margin-inline-*`, `inset-inline-start`,
`text-align: start`) — never `left`/`right`. Directional glyphs carry `.fb-flip`; figures
and IDs carry `.fb-num`, which pins them LTR inside Arabic text.

## Public API

| Route | Purpose |
| --- | --- |
| `GET /api/landing?lang=fr` | The whole landing payload, resolved into one locale |
| `GET /api/languages` | Active locales with their RTL flag |
| `POST /api/contact` | Writes `tfb_contact_messages`. Honeypot + per-IP rate limit |
| `POST /api/checkout` | Stripe Checkout session from the plan's `stripe_price_id` |
| `POST /api/stripe/webhook` | Keeps `tfb_subscriptions` in step. Signature verified first |
| `GET /api/billing/portal` | Stripe customer portal link (session required) |
| `GET /api/storage/[...path]` | Serves uploaded files |
| `GET /api/app-config?tenant=…` | One tenant's resolved configuration + grants, with an `ETag` |
| `GET /api/app-config/schema` | The variable registry — what drives the console's forms |
| `GET /api/data/:resource` | One catalogue resource. Refuses an unauthenticated caller |
| `GET /preview/:slug` | The client's whole interface in their theme — the link you send them |

The admin API lives under `/api/admin/*` and is gated by `withAdmin` — brands, sections,
modules (+ screenshots), plans, subscriptions, contact messages, translations, uploads,
two billing actions that delegate to the billing service, and
`app-config/:tenant` (+ `/publish`) for the per-tenant overlay.

## The Clients tab

One rail entry, first in the list, leading to everything about a customer.
`/admin/clients/<slug>` has five tabs, and they are links rather than local state
because a support call ends with someone pasting a URL to a colleague:

| Tab | What it holds |
| --- | --- |
| Aperçu | Identity from the billing service, licence and invoice counts, served version |
| Personnalisation | The theme and the 29 variables — the editor described below |
| Facturation | That client's licences and invoices |
| Données | Where their data lives, which catalogue resources they use, the `tfb_settings` keys they occupy |
| Journal | Publication history, licence events and sync-queue rows for this client |

Before this, a client was split across two groups of the rail — licences under
Facturation, configuration under Personnalisation — and nothing joined them. Whoever
answers the phone should not have to know which half a question belongs to.

**Publication history** is written *with* the publication, not after it: version,
timestamp, the admin's email taken from the session, and the keys whose published
value changed. Twenty entries per tenant, in `tenant.<slug>.history`. An entry that
can go missing is worse than no history, because it makes the log look complete.

`/admin/profiles` and `/admin/profiles/<slug>` still resolve — they redirect into the
new tabs, so links shared before the move keep working.

## Per-tenant configuration

The PWA is personalised per customer without forking an endpoint, a table or a
schema. Customisation is **data**, stored in this app's database — never in the
tenants' own containers.

**The registry is the only hard-coded part.** `src/lib/config/registry.ts` declares
every variable: key, type, default, validation. The console reads it and generates
its own form, so adding a variable is one entry there — no admin screen, no
migration. `GET /api/app-config/schema` exposes the same thing to any other client.

**Resolution mirrors the locale fallback.** Registry default → `global.published` →
`tenant.<slug>.published`, exactly as a missing `tfb_translations` row falls back to
French. An absent entry is inheritance, and "Réinitialiser" **deletes** the override
rather than storing a blank — the same rule, for the same reason.

**Storage needs no migration.** Everything lives in `tfb_settings` (`key` /
`value JSON`), which the init migration already created:

| Key | Holds |
| --- | --- |
| `global.published` | house defaults, above the registry's |
| `tenant.<slug>.published` | what the PWA is served right now |
| `tenant.<slug>.draft` | what the console is editing |

**Draft and publish are separate.** Editing changes nothing for the customer;
`POST /api/admin/app-config/:tenant/publish` promotes the draft and increments
`version`, which is what the service worker compares against its cached copy.
Publishing is refused while any value fails validation.

**Entitlements are derived, never edited.** What a tenant may see comes from
`licenses` + `packages` in the billing service; the overlay only says how it looks.
`nav.modules` is filtered against those grants at resolution time, so a module that
stops being paid for disappears without anyone touching the profile. Ticking
entitlements in the console would give two sources of truth about what a customer
bought, and they diverge at the first Stripe webhook.

### A client's whole theme, and the link you send them

A tenant's card holds its **complete stylesheet**, not just an accent colour: brand and
CTA colours with the ink that sits on each, surfaces, text, borders, the four status
colours, fonts, corner radius, shadow depth and spacing density. `src/lib/config/theme.ts`
turns those into the CSS custom properties the design system already reads, so a client
theme is a list of property overrides — no CSS is built, shipped or duplicated per
tenant, and changing a colour needs no deploy.

**Why a token set and not a stylesheet field.** Letting the console hold raw CSS would
be simpler and is a bad trade: CSS is not inert. Attribute selectors with
`background-image: url(...)` exfiltrate what a user types, and one `content` rule
rewrites what a customer reads. Tokens express every legitimate theme and none of
that — every value emitted into a `<style>` comes from a validated enum or a
`#RRGGBB` checked on write.

**Contrast is checked as a pair.** A colour is not legible on its own, so
`validateTheme` compares the resolved set — the chosen ink against the primary and the
accent, the text colours against the card surface. Those checks are advisory while
editing (you have to be able to set a light primary and *then* switch its ink) and
**blocking at publish**.

**`/preview/<slug>` is the link you hand the client.** Every component the product is
built from, rendered with their theme: all button variants, sizes and states, the
dropdowns, fields, checkboxes, switches and radios, tabs, cards, badges, stat tiles,
the data table, dialogs and toasts, and all 75 icons — read from `public/icons` at
request time so the page cannot drift from the folder. Nothing is styled for the
preview: if a colour reads badly there, it reads badly in the app.

The page needs no session — it shows branding, never customer data — and is `noindex`.
`?draft=1` renders what the console is editing instead of what is published, and that
one does require an admin session, so a customer is never shown something that is not
live.

### The data catalogue

`src/lib/data/catalogue.ts` is the library of resources a PWA may read, served by one
route — `GET /api/data/:resource`. Two kinds sit in the same list: `proxy` (an
endpoint that already exists on the tenant's own service, host from
`tenants.internal_api_url`) and `query` (generated from a declaration against a
**view**, never a bare table — exposing a table makes its column names your public
contract, and `CREATE VIEW` changes no table).

A registry variable of type `source` *selects* a catalogue entry. It never authors a
URL or a query: a URL typed into an admin form is an SSRF with no review and no
history. A proxy's parameters are fed by other variables — `stock.low_items` takes
its `threshold` from `stock.threshold`.

The catalogue is code, so it is reviewed in a pull request. `/admin/catalogue` is
read-only and shows the guarantees per resource, computed rather than asserted:
tenant column, column allowlist, sortable columns, row ceiling, writes refused.

**The import wizard proposes, it does not publish.** *Importer depuis une table* reads
`information_schema` (scoped to `DATABASE()`, so it cannot enumerate another database
on the same server) and writes a *proposal*: a declaration stored in `tfb_settings`,
listed on the catalogue screen, and served by no route. Making it real means pasting
the generated entry into `catalogue.ts` and putting it through review.

Its defaults are opt-in, not opt-out. Columns matching a margin, HR, personal-data or
secret pattern start unchecked; the tenant column is excluded from the exposed set
(it filters, it does not leave); sorting is only offered on columns that carry an
index; and the last step refuses to produce anything without a tenant column. When the
source is a bare table it says so first and offers the `CREATE VIEW` — as text, to run
yourself. This app never issues DDL.

**Reading rows needs a tenant identity that this app does not have yet.**
`/api/data/:resource` never infers a tenant from `?tenant=` for an anonymous caller —
that is exactly how one customer reads another's rows. It currently resolves the
tenant from a back-office session (the console's preview) and refuses everything
else, because the billing service holds the API keys and only exposes
`api_key_hint`. Point `resolveTenant` at that check when it exists; nothing else in
the file changes.

## The two external services

### Stripe

**Stubbed.** No keys were available for this cut, so the `stripe` package is not a
dependency yet. Everything Stripe-shaped funnels through `src/lib/stripe.ts`; going live
is `npm i stripe` plus three call sites, documented in that file's header. Until then:

- `POST /api/checkout` answers `200 { status: 'stub', reason }` and the pricing UI reports
  it honestly rather than pretending to redirect.
- `POST /api/stripe/webhook` **rejects every call** while `STRIPE_WEBHOOK_SECRET` is unset.
  An unverified webhook must never write to the database.
- A plan with `amount = NULL` is "sur devis" and routes to the contact form, never to
  Checkout.

### Billing service

`billing_service_db.sql` is a **separate Laravel/MySQL service** that owns `tenants`,
`stores`, `licenses`, `invoices`, `packages`, `pricing_tiers`, `license_events` and
`sync_queue`. Those tables are deliberately **not** in this app's Prisma schema —
duplicating another service's database is how the two drift apart. The console reads them
through `src/lib/billing/client.ts`.

With `BILLING_SERVICE_URL` unset, every billing screen renders the fixture snapshot in
`src/lib/billing/fixtures.ts` and shows a banner saying so. Destructive actions (block a
licence, replay a sync row) are **refused** in that mode rather than faking success. Set
the env var and the same screens hit the real endpoints — expected shapes are in
`src/lib/billing/types.ts`, whose field names are copied verbatim from the SQL dump.

## Layout

```
prisma/
  schema.prisma            every tfb_ table
  seed.ts, seed-content.ts the seed and its FR/EN/AR copy
content/
  modules.repos.json       the module repositories sync:modules polls
scripts/
  sync-modules.ts          pulls every .tfb/module.json into the database
src/
  design-system/           the handoff, ported
    tokens/*.css           colours, type, spacing, radius, elevation, motion, fonts, base
    tokens/tailwind.preset.js  the same values as a Tailwind preset
    components/            26 components in 6 groups (core, forms, navigation, content, data, feedback)
  lib/
    i18n/                  locales + the translation resolver
    landing/               the GET /api/landing payload builder
    billing/               the billing-service client, types and fixtures
    config/                registry (the only hard-coded part), tfb_settings store, resolver, theme
    data/                  the catalogue of readable resources and its query executor
    auth/                  session (JWT cookie) and scrypt passwords
    storage.ts             uploads, path safety
  components/
    landing/               header, hero, brand strip, module grid, module page, steps, differentiators, pricing, contact, footer
    admin/                 shell, billing tables, content screens, translation editor, module editor, profile editor
  app/
    (site)/[locale]/       the landing — root layout #1, lang + dir per locale
    (admin)/admin/         the back office — root layout #2, fr-only LTR
    (preview)/preview/     the per-tenant theme page — root layout #3, noindex
    api/                   public and admin routes
  middleware.ts            bare paths → a locale
public/
  icons/                   75 Lucide glyphs
  brand/                   the supplied mark, 4 variants
```

Three root layouts in route groups: the landing mirrors across 8 locales, the console
does not, and the theme page belongs to neither — it is a link handed to one client.

## Design decisions carried over from the handoff

- **Modules use layout A + C**, the combination settled on in the design chat: a filterable
  grid on the landing, opening a full module page (`/[locale]/modules/[slug]`) with an
  autoplay carousel, explanation bullets, the measured gain, and connected modules as chips.
- **Ember (`--cta-warm`) is the landing's conversion colour, always with navy ink** — white
  on ember fails AA. The back office stays plum.
- **Status values are never translated.** `past_due`, `uncollectible`, `sub_1QaBelBast` are
  data: they render in mono, in English, straight from the column.
- **No emoji, no hand-drawn SVG.** Every glyph comes from `public/icons`.
- **Tailwind's preflight is off.** The design system ships its own reset; preflight would
  load after it and undo the body font, heading weights and focus rings.

## Verified

`npm run typecheck`, `npm run lint` and `npm run build` all pass clean, and the build makes
no database calls. **The app has not been run against a live MySQL** — no database was
reachable from the machine it was built on, so the migration, the seed and the rendered
pages are unverified end to end. Run the four commands under [Getting started](#getting-started)
first.

The per-tenant configuration layer **was** run end to end, against a real MySQL-family
database (MariaDB 10.11) with the migration and seed applied: registry validation, the
three-layer resolution chain, draft/publish and version increment, override counting,
introspection over `information_schema`, proposal validation, and executing a `query`
resource through a view. The refusals were exercised too — undeclared column,
disallowed operator, unindexed sort, over-large `limit`, missing tenant column,
unauthenticated `/api/data` (401), `POST` to a generated resource (405), and an
injection attempt through the tenant value (0 rows, bound parameter).

The console was then driven in a browser against that database: logging in, editing a
profile, publishing, and running the import wizard through to a stored proposal. Two
things that only a live run surfaces were found and fixed this way — see the
BIGINT note in `src/lib/data/query.ts`.

Still unverified: the `proxy` half of the catalogue, which needs a reachable
`BILLING_SERVICE_URL` (it refuses explicitly rather than guessing while unset), and the
views the shipped catalogue names — `v_sales_daily` and `v_loyalty_members` — which do
not exist in your schema yet. Create them, or replace those entries with your own.

The check scripts are not committed: the repo has no test runner, and adding one was
outside this change.

## Known gaps

- **Arabic copy is translator-review quality**, carried over from the design handoff. Have
  it reviewed before release.
- **Fonts and icons are substitutions** — Manrope / IBM Plex from Google Fonts, and 75
  Lucide glyphs. No binaries or icon set were supplied. Both are one-directory swaps:
  `src/design-system/tokens/fonts.css` and `public/icons`.
- **No brand logos or module screenshots.** `tfb_brands.logo_path` is empty, so the trust
  strip renders dashed name chips; screenshot rows are seeded with paths whose files do not
  exist yet, so carousels show a labelled placeholder naming the storage folder. Upload
  real files through the back office and both finish themselves.
- **No admin-users screen.** `tfb_admin_users` has no UI spec in the briefs; accounts are
  seeded. Say the word and it gets designed.
- **Sections cannot be reordered by dragging yet.** The API takes a whole new order
  (`PUT /api/admin/sections`); the screen only toggles `is_active` so far.
- **The rate limiter is in-process.** It protects a single instance; behind more than one
  Node process, move it to Redis or the reverse proxy. The call sites do not change.
- **The logo is the supplied mark**, used as-is in four mechanically derived variants. No
  monkey, wordmark or vector version was drawn or invented.
