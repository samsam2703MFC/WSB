# mrszoko/ — Mister Szoko

Everything served under `http://185.180.206.46/mrszoko`.

- **`landing/`** — the public Mister Szoko landing page, **trilingual (pl / uk /
  en, default pl)** and **fully data-driven**: `index.html` contains zero copy;
  `app.js` renders everything from `GET /mrszoko/backoffice/api/landing/content`
  (tables `wsm_landing_i18n` + `wsm_landing_products`, editable through the
  admin API without redeploying), falling back to `content_seed.json` — the
  **same file** that seeds those tables server-side — when the API is
  unreachable (GitHub Pages, dev, outage). Language picking: `?lang=` →
  `localStorage` → browser language → `pl`; a PL/UA/EN switcher sits in the
  header. Served at **`/mrszoko/landing`** (and `/mrszoko/` redirects to it).
  Fonts load from Google Fonts per `tokens/fonts.css` (substitutes — see the
  design-system fonts caveat). End-to-end proof:
  `backoffice/php-api/tests/e2e_landing.php` (22 assertions).

- **`backoffice/`** — the Console marque · Siège (franchisor back-office) and its
  PHP API (`php-api/`, `webshop_mrszoko` DB). Served at **`/mrszoko/backoffice`**,
  API at **`/mrszoko/backoffice/api`**. **UI and demo data are in Polish** (screen
  labels translated in the page; seed data translated identically in
  `bo_server.js` and `php-api/seed.php`; stored workflow codes — delivery
  statuses/events — stay as-is and are mapped to Polish at display time).
  **Access is authenticated**: a login screen (`auth-gate.js`) gates the app,
  every `/franchisor/*` endpoint — reads included — requires a session or a
  service token, and only role `Centrala` may write. See
  `backoffice/README.md` and `backoffice/php-api/README.md`.

- **Design system** — moved to **`../webshops/szoko/design-system/`** (the Mister Szoko
  / Szoko Shop design system, imported from the Claude Design handoff). Every
  shop now carries its own design system next to it (`../webshops/<slug>/design-system/`)
  and the code only uses the token names of `../generique/design-contract.json`.
  See `../webshops/DESIGN-SYSTEMS.md`.
