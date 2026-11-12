-- ============================================================================
--  webshop_mrszoko — SQLite mirror of the canonical MySQL schema.
--  Structurally identical (same tables, columns, wsm_ prefix). Used for local
--  dev / CI / the end-to-end delivery test, where no MySQL server is present.
--  Production uses webshop_mrszoko.mysql.sql. Keep the two in sync.
-- ============================================================================
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS wsm_kpis (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  sort_order  INTEGER NOT NULL DEFAULT 0,
  label       TEXT NOT NULL,
  value       TEXT NOT NULL,
  val_color   TEXT NOT NULL DEFAULT 'var(--color-text)',
  delta       TEXT NOT NULL DEFAULT '',
  delta_color TEXT NOT NULL DEFAULT '#2d7a3e'
);

CREATE TABLE IF NOT EXISTS wsm_shops (
  id         TEXT PRIMARY KEY,
  nom        TEXT NOT NULL,
  ville      TEXT NOT NULL DEFAULT '',
  web        INTEGER NOT NULL DEFAULT 1,
  contrat    TEXT NOT NULL DEFAULT 'Franchise',
  act        INTEGER NOT NULL DEFAULT 1,
  ca_shop    INTEGER NOT NULL DEFAULT 0,
  ca_office  INTEGER NOT NULL DEFAULT 0,
  adoption   INTEGER NOT NULL DEFAULT 0,
  accent     TEXT NOT NULL DEFAULT 'var(--color-primary)',
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS wsm_categories (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  name            TEXT NOT NULL UNIQUE,
  parent_id       INTEGER NOT NULL DEFAULT 0,      -- 0 = rayon de premier niveau
  sort_order      INTEGER NOT NULL DEFAULT 0,
  menu_default    INTEGER NOT NULL DEFAULT 0,
  brand_whitelist INTEGER NOT NULL DEFAULT 1,
  office_delivery INTEGER NOT NULL DEFAULT 0,
  brand_mandatory INTEGER NOT NULL DEFAULT 0,
  active          INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS wsm_products (
  id              TEXT PRIMARY KEY,
  -- Vitrine de la boutique (textes traduits dans wsm_shop_i18n)
  slug            TEXT NOT NULL DEFAULT '',
  shop_visible    INTEGER NOT NULL DEFAULT 0,
  stock           INTEGER NOT NULL DEFAULT 0,
  image_url       TEXT NOT NULL DEFAULT '',
  swatch_from     TEXT NOT NULL DEFAULT '--choco-500',
  swatch_to       TEXT NOT NULL DEFAULT '--choco-800',
  origin          TEXT NOT NULL DEFAULT '',
  cocoa           TEXT NOT NULL DEFAULT '',
  unit_label      TEXT NOT NULL DEFAULT '',
  badge           TEXT NOT NULL DEFAULT '',
  nutrition       TEXT,                          -- wartości odżywcze / 100 g, JSON
  -- Expédition InPost + fiscalité tpay
  rodzaj          TEXT NOT NULL DEFAULT 'towar',   -- towar | usluga | subskrypcja
  sku             TEXT NOT NULL DEFAULT '',
  ean             TEXT NOT NULL DEFAULT '',
  vat_rate        REAL NOT NULL DEFAULT 0.23,    -- PL : 0 / 0.05 / 0.08 / 0.23
  weight_g        INTEGER NOT NULL DEFAULT 0,
  length_mm       INTEGER NOT NULL DEFAULT 0,
  width_mm        INTEGER NOT NULL DEFAULT 0,
  height_mm       INTEGER NOT NULL DEFAULT 0,
  parcel_template TEXT NOT NULL DEFAULT '',      -- A | B | C (déduit si vide)
  category_id     INTEGER NOT NULL REFERENCES wsm_categories(id) ON DELETE CASCADE,
  nom             TEXT NOT NULL,
  prix            REAL NOT NULL DEFAULT 0,
  base_cost       REAL NOT NULL DEFAULT 0,
  statut          TEXT NOT NULL DEFAULT 'Publié',
  saison          TEXT NOT NULL DEFAULT '',
  brand_whitelist INTEGER NOT NULL DEFAULT 0,
  brand_mandatory INTEGER NOT NULL DEFAULT 0,
  adoption        INTEGER NOT NULL DEFAULT 0,
  menu_override   TEXT DEFAULT NULL,
  sort_order      INTEGER NOT NULL DEFAULT 0,
  active          INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS wsm_vouchers (
  id       INTEGER PRIMARY KEY AUTOINCREMENT,
  code     TEXT NOT NULL UNIQUE,
  valeur   TEXT NOT NULL DEFAULT '',
  type     TEXT NOT NULL DEFAULT 'Panier',
  validite TEXT NOT NULL DEFAULT '',
  -- Ce qui agit REELLEMENT sur le prix. Les quatre colonnes ci-dessus sont
  -- d'affichage : elles etaient seules, et aucune caisse n'a jamais lu un code.
  kind       TEXT NOT NULL DEFAULT 'procent',   -- procent | kwota | wysylka
  pct        REAL NOT NULL DEFAULT 0,
  kwota      INTEGER NOT NULL DEFAULT 0,        -- grosze
  min_gross  INTEGER NOT NULL DEFAULT 0,        -- grosze, 0 = sans minimum
  starts_at  TEXT DEFAULT NULL,
  ends_at    TEXT DEFAULT NULL,
  max_uses   INTEGER NOT NULL DEFAULT 0,        -- 0 = illimite
  per_email  INTEGER NOT NULL DEFAULT 0,        -- 0 = illimite
  used       INTEGER NOT NULL DEFAULT 0,
  active     INTEGER NOT NULL DEFAULT 1,
  note       TEXT NOT NULL DEFAULT '',
  created_at TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wsm_pricing_rules (
  id      INTEGER PRIMARY KEY AUTOINCREMENT,
  nom     TEXT NOT NULL,
  cible   TEXT NOT NULL DEFAULT '',
  effet   TEXT NOT NULL DEFAULT '',
  shop_id TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wsm_params (
  cle  TEXT PRIMARY KEY,
  type TEXT NOT NULL DEFAULT 'text',
  val  TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS wsm_email_templates (
  id     INTEGER PRIMARY KEY AUTOINCREMENT,
  cle    TEXT NOT NULL,
  langue TEXT NOT NULL DEFAULT 'FR',
  sujet  TEXT NOT NULL DEFAULT '',
  UNIQUE (cle, langue)
);

CREATE TABLE IF NOT EXISTS wsm_users (
  id     INTEGER PRIMARY KEY AUTOINCREMENT,
  nom    TEXT NOT NULL,
  email  TEXT NOT NULL UNIQUE,
  role   TEXT NOT NULL DEFAULT 'Franczyza',
  portee TEXT NOT NULL DEFAULT '',
  act    INTEGER NOT NULL DEFAULT 1,
  -- Authentification (voir auth.php) : sans hachage, le compte ne peut pas
  -- se connecter.
  password_hash   TEXT DEFAULT NULL,
  last_login      TEXT DEFAULT NULL,
  failed_attempts INTEGER NOT NULL DEFAULT 0,
  locked_until    TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wsm_user_shops (
  user_id INTEGER NOT NULL REFERENCES wsm_users(id) ON DELETE CASCADE,
  shop_id TEXT NOT NULL,
  PRIMARY KEY (user_id, shop_id)
);

CREATE TABLE IF NOT EXISTS wsm_audit (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  ts         TEXT NOT NULL DEFAULT '',
  user       TEXT NOT NULL DEFAULT '',
  verb       TEXT NOT NULL DEFAULT '',
  entity     TEXT NOT NULL DEFAULT '',
  shop       TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wsm_catchment (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  name      TEXT NOT NULL,
  postcodes TEXT,
  exclusive INTEGER NOT NULL DEFAULT 1,
  active    INTEGER NOT NULL DEFAULT 1,
  shop_id   TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wsm_bundles (
  id             TEXT PRIMARY KEY,
  product_id     TEXT NOT NULL,
  name           TEXT NOT NULL DEFAULT 'Nouvelle formule',
  description    TEXT NOT NULL DEFAULT '',
  price_modifier REAL NOT NULL DEFAULT 0,
  sort_order     INTEGER NOT NULL DEFAULT 0,
  active         INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS wsm_bundle_slots (
  id         TEXT PRIMARY KEY,
  bundle_id  TEXT NOT NULL REFERENCES wsm_bundles(id) ON DELETE CASCADE,
  label      TEXT NOT NULL DEFAULT 'Nouvelle étape',
  required   INTEGER NOT NULL DEFAULT 1,
  kind       TEXT NOT NULL DEFAULT 'single',
  min_select INTEGER NOT NULL DEFAULT 1,
  max_select INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  active     INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS wsm_bundle_slot_choices (
  id         TEXT PRIMARY KEY,
  slot_id    TEXT NOT NULL REFERENCES wsm_bundle_slots(id) ON DELETE CASCADE,
  label      TEXT NOT NULL DEFAULT 'Nouveau choix',
  img        TEXT NOT NULL DEFAULT '',
  delta      REAL NOT NULL DEFAULT 0,
  cost       REAL NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  active     INTEGER NOT NULL DEFAULT 1
);

-- ===== DELIVERY MODULE ======================================================
CREATE TABLE IF NOT EXISTS wsm_clients (
  id       INTEGER PRIMARY KEY AUTOINCREMENT,
  -- tpay (payeur + facture) & InPost (destinataire) — voir commerce.php
  client_type   TEXT NOT NULL DEFAULT 'firma',   -- firma | osoba
  email         TEXT NOT NULL DEFAULT '',        -- tpay : obligatoire
  phone         TEXT NOT NULL DEFAULT '',        -- InPost : 9 chiffres
  first_name    TEXT NOT NULL DEFAULT '',
  last_name     TEXT NOT NULL DEFAULT '',
  nip           TEXT NOT NULL DEFAULT '',        -- NIP polonais (facture)
  vat_eu        TEXT NOT NULL DEFAULT '',        -- TVA intracom. (VIES)
  bill_street   TEXT NOT NULL DEFAULT '',
  bill_building TEXT NOT NULL DEFAULT '',
  bill_postcode TEXT NOT NULL DEFAULT '',        -- NN-NNN
  bill_city     TEXT NOT NULL DEFAULT '',
  bill_country  TEXT NOT NULL DEFAULT 'PL',
  code     TEXT NOT NULL UNIQUE,
  raison   TEXT NOT NULL,
  seg      TEXT NOT NULL DEFAULT 'horeca',
  statut   TEXT NOT NULL DEFAULT 'actif',
  tva      TEXT NOT NULL DEFAULT '',
  paiement TEXT NOT NULL DEFAULT '',
  plafond  INTEGER NOT NULL DEFAULT 0,
  encours  INTEGER NOT NULL DEFAULT 0,
  franco   TEXT NOT NULL DEFAULT '',
  remise   TEXT NOT NULL DEFAULT '',
  fact     TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS wsm_client_points (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  -- InPost : Paczkomat (code) ou coursier (adresse structurée)
  delivery_method TEXT NOT NULL DEFAULT 'inpost_locker',
  inpost_point    TEXT NOT NULL DEFAULT '',      -- ex. KRA010
  street          TEXT NOT NULL DEFAULT '',
  building        TEXT NOT NULL DEFAULT '',
  postcode        TEXT NOT NULL DEFAULT '',
  city            TEXT NOT NULL DEFAULT '',
  country         TEXT NOT NULL DEFAULT 'PL',
  contact_phone   TEXT NOT NULL DEFAULT '',
  contact_email   TEXT NOT NULL DEFAULT '',
  client_id  INTEGER NOT NULL REFERENCES wsm_clients(id) ON DELETE CASCADE,
  libelle    TEXT NOT NULL,
  adresse    TEXT NOT NULL DEFAULT '',
  fenetre    TEXT NOT NULL DEFAULT '',
  jours      TEXT NOT NULL DEFAULT '',
  validation TEXT NOT NULL DEFAULT 'QR',
  marge      INTEGER NOT NULL DEFAULT 0,
  lat        REAL DEFAULT NULL,
  lng        REAL DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wsm_drivers (
  id       INTEGER PRIMARY KEY AUTOINCREMENT,
  nom      TEXT NOT NULL,
  info     TEXT NOT NULL DEFAULT '',
  color    TEXT NOT NULL DEFAULT '#8D1D2C',
  vehicule TEXT NOT NULL DEFAULT '',
  zone     TEXT NOT NULL DEFAULT '',
  active   INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS wsm_rounds (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL,
  driver_id  INTEGER REFERENCES wsm_drivers(id) ON DELETE SET NULL,
  round_date TEXT DEFAULT NULL,
  status     TEXT NOT NULL DEFAULT 'planifiée'
);

CREATE TABLE IF NOT EXISTS wsm_deliveries (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  ref               TEXT NOT NULL UNIQUE,
  client_id         INTEGER REFERENCES wsm_clients(id) ON DELETE SET NULL,
  point_id          INTEGER REFERENCES wsm_client_points(id) ON DELETE SET NULL,
  driver_id         INTEGER REFERENCES wsm_drivers(id) ON DELETE SET NULL,
  round_id          INTEGER REFERENCES wsm_rounds(id) ON DELETE SET NULL,
  status            TEXT NOT NULL DEFAULT 'brouillon',
  window_label      TEXT NOT NULL DEFAULT '',
  validation_method TEXT NOT NULL DEFAULT 'QR',
  confirm_code      TEXT NOT NULL DEFAULT '',
  confirmed         INTEGER NOT NULL DEFAULT 0,
  ca                REAL NOT NULL DEFAULT 0,
  couts             REAL NOT NULL DEFAULT 0,
  scheduled_date    TEXT DEFAULT NULL,
  notes             TEXT NOT NULL DEFAULT '',
  created_at        TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at      TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wsm_delivery_events (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  delivery_id INTEGER NOT NULL REFERENCES wsm_deliveries(id) ON DELETE CASCADE,
  event       TEXT NOT NULL,
  detail      TEXT NOT NULL DEFAULT '',
  actor       TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wsm_incidents (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  ref         TEXT NOT NULL UNIQUE,
  delivery_id INTEGER REFERENCES wsm_deliveries(id) ON DELETE SET NULL,
  type        TEXT NOT NULL,
  point       TEXT NOT NULL DEFAULT '',
  statut      TEXT NOT NULL DEFAULT 'Do obsłużenia',
  impact      TEXT NOT NULL DEFAULT '',
  description TEXT,
  geo         TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- --- Landing Mister Szoko (contenu du site public, 3 langues) ---------------
-- Tout le texte de la landing vit ici (aucun libellé en dur dans la page) ;
-- seedé depuis landing/content_seed.json, éditable via l'API admin.
CREATE TABLE IF NOT EXISTS wsm_landing_i18n (
  lang TEXT NOT NULL,
  k    TEXT NOT NULL,
  v    TEXT NOT NULL,
  PRIMARY KEY (lang, k)
);

-- Données structurées des cartes produit (les textes sont dans wsm_landing_i18n
-- sous product.<id>.name/.meta/.specs).
CREATE TABLE IF NOT EXISTS wsm_landing_products (
  id              TEXT PRIMARY KEY,
  sort_order      INTEGER NOT NULL DEFAULT 0,
  swatch_from     TEXT NOT NULL DEFAULT '--choco-900',
  swatch_to      TEXT NOT NULL DEFAULT '--choco-700',
  fluidity        INTEGER NOT NULL DEFAULT 3,
  active          INTEGER NOT NULL DEFAULT 1,
  price_from_pln  REAL,
  price_perkg_pln REAL,
  price_from_eur  REAL,
  price_perkg_eur REAL
);

-- ============================================================================
--  BOUTIQUE EN LIGNE — miroir SQLite (dev / CI), structurellement identique.
--  Montants en GROSZE (entiers) : jamais de flottant sur de l'argent.
-- ============================================================================

CREATE TABLE IF NOT EXISTS wsm_shipping_methods (
  id           TEXT PRIMARY KEY,
  carrier      TEXT NOT NULL DEFAULT 'inpost',
  -- 'punkt' : le client designe un point (Paczkomat, DPD Pickup).
  -- 'adres' : le colis va a une adresse. Voir le schema MySQL.
  kind         TEXT NOT NULL DEFAULT 'adres',
  sort_order   INTEGER NOT NULL DEFAULT 0,
  active       INTEGER NOT NULL DEFAULT 1,
  price_net    INTEGER NOT NULL DEFAULT 0,
  vat_rate     REAL NOT NULL DEFAULT 0.23,
  free_from    INTEGER NOT NULL DEFAULT 0,
  -- Les deux bornes de poids. Voir le schema MySQL : un transporteur de
  -- palettes commence a 200 kg, un Paczkomat s'arrete a 25.
  min_weight_g INTEGER NOT NULL DEFAULT 0,
  max_weight_g INTEGER NOT NULL DEFAULT 25000
);

CREATE TABLE IF NOT EXISTS wsm_orders (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  code            TEXT NOT NULL UNIQUE,
  access_token    TEXT NOT NULL,
  lang            TEXT NOT NULL DEFAULT 'pl',
  currency        TEXT NOT NULL DEFAULT 'PLN',
  status          TEXT NOT NULL DEFAULT 'nowe',
  payment_status  TEXT NOT NULL DEFAULT 'oczekuje',
  client_id       INTEGER REFERENCES wsm_clients(id) ON DELETE SET NULL,
  client_type     TEXT NOT NULL DEFAULT 'osoba',
  email           TEXT NOT NULL DEFAULT '',
  phone           TEXT NOT NULL DEFAULT '',
  first_name      TEXT NOT NULL DEFAULT '',
  last_name       TEXT NOT NULL DEFAULT '',
  company         TEXT NOT NULL DEFAULT '',
  nip             TEXT NOT NULL DEFAULT '',
  vat_eu          TEXT NOT NULL DEFAULT '',
  invoice         INTEGER NOT NULL DEFAULT 0,
  paragon_forma   TEXT NOT NULL DEFAULT '',
  bill_street     TEXT NOT NULL DEFAULT '',
  bill_building   TEXT NOT NULL DEFAULT '',
  bill_postcode   TEXT NOT NULL DEFAULT '',
  bill_city       TEXT NOT NULL DEFAULT '',
  bill_country    TEXT NOT NULL DEFAULT 'PL',
  delivery_method TEXT NOT NULL DEFAULT 'inpost_locker',
  inpost_point    TEXT NOT NULL DEFAULT '',
  ship_street     TEXT NOT NULL DEFAULT '',
  ship_building   TEXT NOT NULL DEFAULT '',
  ship_postcode   TEXT NOT NULL DEFAULT '',
  ship_city       TEXT NOT NULL DEFAULT '',
  ship_country    TEXT NOT NULL DEFAULT 'PL',
  items_net       INTEGER NOT NULL DEFAULT 0,
  items_vat       INTEGER NOT NULL DEFAULT 0,
  items_gross     INTEGER NOT NULL DEFAULT 0,
  shipping_net    INTEGER NOT NULL DEFAULT 0,
  shipping_vat    INTEGER NOT NULL DEFAULT 0,
  shipping_gross  INTEGER NOT NULL DEFAULT 0,
  total_net       INTEGER NOT NULL DEFAULT 0,
  total_vat       INTEGER NOT NULL DEFAULT 0,
  total_gross     INTEGER NOT NULL DEFAULT 0,
  weight_g        INTEGER NOT NULL DEFAULT 0,
  parcel_template TEXT NOT NULL DEFAULT '',
  note            TEXT,
  created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at         TEXT DEFAULT NULL,
  shipped_at      TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wsm_order_items (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id   INTEGER NOT NULL REFERENCES wsm_orders(id) ON DELETE CASCADE,
  product_id TEXT NOT NULL,
  name       TEXT NOT NULL,
  sku        TEXT NOT NULL DEFAULT '',
  ean        TEXT NOT NULL DEFAULT '',
  qty        INTEGER NOT NULL DEFAULT 1,
  unit_net   INTEGER NOT NULL DEFAULT 0,
  unit_gross INTEGER NOT NULL DEFAULT 0,
  vat_rate   REAL NOT NULL DEFAULT 0.23,
  line_net   INTEGER NOT NULL DEFAULT 0,
  line_vat   INTEGER NOT NULL DEFAULT 0,
  line_gross INTEGER NOT NULL DEFAULT 0,
  weight_g   INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS wsm_payments (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id     INTEGER NOT NULL REFERENCES wsm_orders(id) ON DELETE CASCADE,
  provider     TEXT NOT NULL DEFAULT 'stripe',
  tr_id        TEXT NOT NULL DEFAULT '',
  tr_title     TEXT NOT NULL DEFAULT '',
  amount       INTEGER NOT NULL DEFAULT 0,
  currency     TEXT NOT NULL DEFAULT 'PLN',
  status       TEXT NOT NULL DEFAULT 'oczekuje',
  redirect_url TEXT NOT NULL DEFAULT '',
  app_fee      INTEGER NOT NULL DEFAULT 0,
  created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wsm_payment_events (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id     INTEGER REFERENCES wsm_orders(id) ON DELETE SET NULL,
  provider     TEXT NOT NULL DEFAULT 'stripe',
  event_key    TEXT NOT NULL UNIQUE,
  status       TEXT NOT NULL DEFAULT '',
  amount       INTEGER NOT NULL DEFAULT 0,
  signature_ok INTEGER NOT NULL DEFAULT 0,
  raw          TEXT,
  created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wsm_shipments (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id        INTEGER NOT NULL REFERENCES wsm_orders(id) ON DELETE CASCADE,
  carrier         TEXT NOT NULL DEFAULT 'inpost',
  service         TEXT NOT NULL DEFAULT 'inpost_locker',
  target_point    TEXT NOT NULL DEFAULT '',
  parcel_template TEXT NOT NULL DEFAULT '',
  weight_g        INTEGER NOT NULL DEFAULT 0,
  shipment_id     TEXT NOT NULL DEFAULT '',
  tracking_number TEXT NOT NULL DEFAULT '',
  label_url       TEXT NOT NULL DEFAULT '',
  status          TEXT NOT NULL DEFAULT 'do_utworzenia',
  created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wsm_order_events (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id   INTEGER NOT NULL REFERENCES wsm_orders(id) ON DELETE CASCADE,
  event      TEXT NOT NULL,
  detail     TEXT NOT NULL DEFAULT '',
  actor      TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wsm_shop_i18n (
  lang TEXT NOT NULL,
  k    TEXT NOT NULL,
  v    TEXT NOT NULL,
  PRIMARY KEY (lang, k)
);

-- --- Contrôles VIES (miroir SQLite) -----------------------------------------
CREATE TABLE IF NOT EXISTS wsm_vies_checks (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  vat_eu       TEXT NOT NULL,
  country      TEXT NOT NULL DEFAULT '',
  number       TEXT NOT NULL DEFAULT '',
  status       TEXT NOT NULL DEFAULT '',
  reason       TEXT NOT NULL DEFAULT '',
  name         TEXT NOT NULL DEFAULT '',
  address      TEXT NOT NULL DEFAULT '',
  consultation TEXT NOT NULL DEFAULT '',
  source       TEXT NOT NULL DEFAULT 'vies',
  raw          TEXT,
  checked_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_wsm_vies_vat ON wsm_vies_checks (vat_eu, id);

-- --- Recherche de commande sans compte ---------------------------------------
-- Un plafond, et rien d'autre. Le numéro de commande s'écrit MS-AAMMJJ-0001 :
-- une date et un compteur, donc devinable. C'est le COUPLE numéro + e-mail qui
-- ouvre la page, et cette table empêche d'en essayer mille.
--
-- Elle ne va PAS dans wsm_audit : ce journal-là ne montre que les 150 derniers
-- gestes de la console, et une rafale de tentatives publiques en chasserait
-- « qui a changé ce prix » — la seule question à laquelle il sert à répondre.
CREATE TABLE IF NOT EXISTS wsm_order_lookups (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  ip         TEXT NOT NULL DEFAULT '',
  code       TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_wsm_lookups_ip ON wsm_order_lookups (ip, created_at);

-- --- Pays servis (miroir SQLite) --------------------------------------------
CREATE TABLE IF NOT EXISTS wsm_countries (
  code       TEXT PRIMARY KEY,
  name_pl    TEXT NOT NULL,
  name_uk    TEXT NOT NULL DEFAULT '',
  name_en    TEXT NOT NULL DEFAULT '',
  is_eu      INTEGER NOT NULL DEFAULT 1,
  active     INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0
);

-- --- Rabaty ilościowe (miroir SQLite) ---------------------------------------
CREATE TABLE IF NOT EXISTS wsm_discount_tiers (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  min_weight_g INTEGER NOT NULL DEFAULT 0,
  percent      REAL NOT NULL DEFAULT 0,
  label        TEXT NOT NULL DEFAULT '',
  active       INTEGER NOT NULL DEFAULT 1
);

-- --- Poczta : modèles, file d'envoi (miroir SQLite) -------------------------
CREATE TABLE IF NOT EXISTS wsm_mail_templates (
  id      INTEGER PRIMARY KEY AUTOINCREMENT,
  code    TEXT NOT NULL,
  lang    TEXT NOT NULL DEFAULT 'pl',
  name    TEXT NOT NULL DEFAULT '',
  subject TEXT NOT NULL DEFAULT '',
  body    TEXT NOT NULL DEFAULT '',
  event   TEXT NOT NULL DEFAULT '',
  active  INTEGER NOT NULL DEFAULT 1,
  UNIQUE (code, lang)
);

CREATE TABLE IF NOT EXISTS wsm_messages (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id      INTEGER NULL REFERENCES wsm_orders(id) ON DELETE SET NULL,
  email         TEXT NOT NULL DEFAULT '',
  direction     TEXT NOT NULL DEFAULT 'wyjscie',
  subject       TEXT NOT NULL DEFAULT '',
  body          TEXT NOT NULL DEFAULT '',
  html          TEXT NULL,
  unsub_url     TEXT NOT NULL DEFAULT '',
  template_code TEXT NOT NULL DEFAULT '',
  event_key     TEXT NULL UNIQUE,
  status        TEXT NOT NULL DEFAULT 'kolejka',
  error         TEXT NOT NULL DEFAULT '',
  actor         TEXT NOT NULL DEFAULT '',
  created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at       TEXT DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_wsm_messages_order ON wsm_messages (order_id, id);

CREATE TABLE IF NOT EXISTS wsm_settings (
  cle        TEXT PRIMARY KEY,
  val        TEXT NOT NULL DEFAULT '',
  secret     INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by TEXT NOT NULL DEFAULT ''
);

-- --- Faktury (miroir SQLite) -------------------------------------------------
CREATE TABLE IF NOT EXISTS wsm_invoices (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id       INTEGER NULL REFERENCES wsm_orders(id) ON DELETE SET NULL,
  kind           TEXT NOT NULL DEFAULT 'faktura',
  kind_group     TEXT NOT NULL DEFAULT 'faktura',
  corrects_id    INTEGER NULL,
  number         TEXT NOT NULL,
  series         TEXT NOT NULL DEFAULT '',
  seq            INTEGER NOT NULL DEFAULT 0,
  issued_at      TEXT NOT NULL,
  sold_at        TEXT NOT NULL,
  due_at         TEXT NOT NULL,
  place          TEXT NOT NULL DEFAULT '',
  seller_name    TEXT NOT NULL DEFAULT '',
  seller_nip     TEXT NOT NULL DEFAULT '',
  seller_address TEXT NOT NULL DEFAULT '',
  iban           TEXT NOT NULL DEFAULT '',
  bank           TEXT NOT NULL DEFAULT '',
  buyer_name     TEXT NOT NULL DEFAULT '',
  buyer_nip      TEXT NOT NULL DEFAULT '',
  buyer_vat_eu   TEXT NOT NULL DEFAULT '',
  buyer_address  TEXT NOT NULL DEFAULT '',
  buyer_email    TEXT NOT NULL DEFAULT '',
  currency       TEXT NOT NULL DEFAULT 'PLN',
  total_net      INTEGER NOT NULL DEFAULT 0,
  total_vat      INTEGER NOT NULL DEFAULT 0,
  total_gross    INTEGER NOT NULL DEFAULT 0,
  reverse_charge INTEGER NOT NULL DEFAULT 0,
  paid           INTEGER NOT NULL DEFAULT 0,
  note           TEXT NOT NULL DEFAULT '',
  sent_at        TEXT DEFAULT NULL,
  ksef_number    TEXT NOT NULL DEFAULT '',
  ksef_status    TEXT NOT NULL DEFAULT '',
  ksef_at        TEXT DEFAULT NULL,
  fiskal_status  TEXT NOT NULL DEFAULT '',
  fiskal_forma   TEXT NOT NULL DEFAULT '',
  fiskal_id      TEXT NOT NULL DEFAULT '',
  fiskal_url     TEXT NOT NULL DEFAULT '',
  fiskal_note    TEXT NOT NULL DEFAULT '',
  fiskal_proby   INTEGER NOT NULL DEFAULT 0,
  fiskal_at      TEXT DEFAULT NULL,
  created_by     TEXT NOT NULL DEFAULT '',
  created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (number),
  UNIQUE (kind_group, series, seq)
);
CREATE INDEX IF NOT EXISTS idx_wsm_invoices_order ON wsm_invoices (order_id);

CREATE TABLE IF NOT EXISTS wsm_invoice_items (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_id INTEGER NOT NULL REFERENCES wsm_invoices(id) ON DELETE CASCADE,
  name       TEXT NOT NULL DEFAULT '',
  sku        TEXT NOT NULL DEFAULT '',
  qty        INTEGER NOT NULL DEFAULT 1,
  unit_net   INTEGER NOT NULL DEFAULT 0,
  unit_gross INTEGER NOT NULL DEFAULT 0,
  vat_rate   REAL NOT NULL DEFAULT 0.23,
  line_net   INTEGER NOT NULL DEFAULT 0,
  line_vat   INTEGER NOT NULL DEFAULT 0,
  line_gross INTEGER NOT NULL DEFAULT 0
);

-- --- Ruchy magazynowe (miroir SQLite) ---------------------------------------
CREATE TABLE IF NOT EXISTS wsm_stock_moves (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id  TEXT NOT NULL,
  delta       INTEGER NOT NULL DEFAULT 0,
  kind        TEXT NOT NULL DEFAULT 'korekta',
  stock_after INTEGER NOT NULL DEFAULT 0,
  reason      TEXT NOT NULL DEFAULT '',
  note        TEXT NOT NULL DEFAULT '',
  doc         TEXT NOT NULL DEFAULT '',
  supplier    TEXT NOT NULL DEFAULT '',
  unit_cost   INTEGER NOT NULL DEFAULT 0,
  actor       TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_wsm_stock_moves ON wsm_stock_moves (product_id, id);

-- --- Dokumenty magazynowe (miroir SQLite) -----------------------------------
CREATE TABLE IF NOT EXISTS wsm_stock_docs (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  kind       TEXT NOT NULL DEFAULT 'PZ',
  number     TEXT NOT NULL,
  series     TEXT NOT NULL DEFAULT '',
  seq        INTEGER NOT NULL DEFAULT 0,
  order_id   INTEGER NULL,
  partner    TEXT NOT NULL DEFAULT '',
  ref        TEXT NOT NULL DEFAULT '',
  issued_at  TEXT NOT NULL,
  note       TEXT NOT NULL DEFAULT '',
  units      INTEGER NOT NULL DEFAULT 0,
  value      INTEGER NOT NULL DEFAULT 0,
  actor      TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (number)
);

-- --- Marki (miroir SQLite) --------------------------------------------------
--  Une marque est une entité à part entière, pas une chaîne recopiée sur
--  chaque produit : le logo, l'adresse du site et l'orthographe du nom se
--  corrigent une fois pour toutes. Le produit ne porte qu'une référence.
CREATE TABLE IF NOT EXISTS wsm_brands (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT NOT NULL,
  slug        TEXT NOT NULL,
  logo_url    TEXT NOT NULL DEFAULT '',
  site_url    TEXT NOT NULL DEFAULT '',
  note        TEXT NOT NULL DEFAULT '',
  sort_order  INTEGER NOT NULL DEFAULT 0,
  active      INTEGER NOT NULL DEFAULT 1,
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (slug)
);

-- Plateforme : ce que la boutique doit à son propriétaire.
-- wsm_platform_terms est en ajout seul (historique du contrat) ;
-- wsm_platform_periods fige volume, taux, loyer et TVA à l'émission.
-- L'UNIQUE sur ym est le garde-fou : un mois ne se facture qu'une fois.
CREATE TABLE IF NOT EXISTS wsm_platform_terms (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  rent_net    INTEGER NOT NULL DEFAULT 0,
  rate        REAL NOT NULL DEFAULT 0.15,
  basis       TEXT NOT NULL DEFAULT 'brutto',
  vat_rate    REAL NOT NULL DEFAULT 0.23,
  from_ym     TEXT NOT NULL,
  note        TEXT NOT NULL DEFAULT '',
  created_by  TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_wsm_platform_terms_from ON wsm_platform_terms (from_ym);

CREATE TABLE IF NOT EXISTS wsm_platform_periods (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  ym             TEXT NOT NULL,
  status         TEXT NOT NULL DEFAULT 'szkic',
  gross_volume   INTEGER NOT NULL DEFAULT 0,
  goods_gross    INTEGER NOT NULL DEFAULT 0,
  shipping_gross INTEGER NOT NULL DEFAULT 0,
  orders_count   INTEGER NOT NULL DEFAULT 0,
  basis          TEXT NOT NULL DEFAULT 'brutto',
  rate           REAL NOT NULL DEFAULT 0.15,
  base_amount    INTEGER NOT NULL DEFAULT 0,
  commission_net INTEGER NOT NULL DEFAULT 0,
  rent_net       INTEGER NOT NULL DEFAULT 0,
  total_net      INTEGER NOT NULL DEFAULT 0,
  vat_rate       REAL NOT NULL DEFAULT 0.23,
  total_vat      INTEGER NOT NULL DEFAULT 0,
  total_gross    INTEGER NOT NULL DEFAULT 0,
  issued_at      TEXT,
  issued_by      TEXT NOT NULL DEFAULT '',
  paid_at        TEXT,
  note           TEXT NOT NULL DEFAULT '',
  created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (ym)
);

-- Langues servies au public (décision explicite, pas effet de bord d'une
-- traduction partielle) et historique avant → après du contenu traduit.
CREATE TABLE IF NOT EXISTS wsm_langs (
  code       TEXT PRIMARY KEY,
  published  INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wsm_i18n_history (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tbl        TEXT NOT NULL,
  lang       TEXT NOT NULL,
  k          TEXT NOT NULL,
  old_v      TEXT,
  new_v      TEXT,
  origin     TEXT NOT NULL DEFAULT 'human',
  actor      TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_wsm_i18n_history_lookup ON wsm_i18n_history (tbl, lang, k);

-- Traductions du courrier : l'original reste dans wsm_messages (c'est la
-- pièce), la traduction vit ici. L'UNIQUE évite de payer deux fois la même.
CREATE TABLE IF NOT EXISTS wsm_message_tr (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  message_id INTEGER NOT NULL,
  lang       TEXT NOT NULL,
  src_lang   TEXT NOT NULL DEFAULT '',
  subject    TEXT NOT NULL DEFAULT '',
  body       TEXT,
  actor      TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  UNIQUE (message_id, lang)
);

-- Notes sur les clients : la clé est l'adresse e-mail, parce que le client de
-- la boutique n'a pas de fiche — il a des commandes. Signée et datée.
CREATE TABLE IF NOT EXISTS wsm_client_notes (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  email      TEXT NOT NULL,
  note       TEXT,
  actor      TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_wsm_client_notes_email ON wsm_client_notes (email);

-- Utilisations des bons de réduction.
--
--  L'UNICITÉ (voucher_id, order_id) EST LA RÈGLE MÉTIER : un webhook rejoué
--  ou un double clic ne peuvent pas décompter deux fois la même commande.
--
--  Le montant est GELÉ ici. Le bon peut être modifié ou retiré demain ; ce
--  que cette commande-là a réellement obtenu ne doit plus jamais bouger,
--  exactement comme une facture ou un mouvement de stock.
CREATE TABLE IF NOT EXISTS wsm_voucher_uses (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  voucher_id INTEGER NOT NULL,
  order_id   INTEGER NOT NULL,
  email      TEXT NOT NULL DEFAULT '',
  amount     INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL,
  UNIQUE (voucher_id, order_id)
);
CREATE INDEX IF NOT EXISTS ix_wsm_voucher_uses_email ON wsm_voucher_uses (email);

-- Abonnements — commandes récurrentes.
--
--  RIEN N'EST PRELEVE. Cette boutique n'enregistre aucune carte : tpay
--  encaisse chez lui et ne rend qu'un etat. A l'echeance on PREPARE la
--  commande et on envoie un lien de paiement. Promettre un prelevement
--  automatique serait un mensonge a l'ecran et un litige au premier
--  renouvellement.
--
--  L'adresse est FIGEE ici. Elle vient de la commande d'origine et ne suit
--  pas la fiche client : quelqu'un qui demenage doit le dire, et une adresse
--  qui change toute seule envoie un colis chez l'ancien occupant.
CREATE TABLE IF NOT EXISTS wsm_subscriptions (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  email           TEXT NOT NULL,
  first_name      TEXT NOT NULL DEFAULT '',
  last_name       TEXT NOT NULL DEFAULT '',
  phone           TEXT NOT NULL DEFAULT '',
  company         TEXT NOT NULL DEFAULT '',
  nip             TEXT NOT NULL DEFAULT '',
  lang            TEXT NOT NULL DEFAULT 'pl',
  rytm            TEXT NOT NULL DEFAULT 'co_miesiac',
  statut          TEXT NOT NULL DEFAULT 'aktywny',   -- aktywny | wstrzymana | zakonczona
  next_at         TEXT NOT NULL,
  last_run_at     TEXT DEFAULT NULL,
  runs            INTEGER NOT NULL DEFAULT 0,
  unpaid_streak   INTEGER NOT NULL DEFAULT 0,
  delivery_method TEXT NOT NULL DEFAULT 'inpost_locker',
  inpost_point    TEXT NOT NULL DEFAULT '',
  ship_street     TEXT NOT NULL DEFAULT '',
  ship_building   TEXT NOT NULL DEFAULT '',
  ship_postcode   TEXT NOT NULL DEFAULT '',
  ship_city       TEXT NOT NULL DEFAULT '',
  ship_country    TEXT NOT NULL DEFAULT 'PL',
  token           TEXT NOT NULL,
  source_order_id INTEGER NOT NULL DEFAULT 0,
  note            TEXT NOT NULL DEFAULT '',
  created_at      TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_wsm_subs_next ON wsm_subscriptions (statut, next_at);
CREATE INDEX IF NOT EXISTS ix_wsm_subs_email ON wsm_subscriptions (email);

CREATE TABLE IF NOT EXISTS wsm_subscription_items (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  subscription_id INTEGER NOT NULL,
  product_id      TEXT NOT NULL,
  qty             INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS ix_wsm_sub_items ON wsm_subscription_items (subscription_id);

-- Reclamations et retractations.
--
--  LE MONTANT PAYE EST FIGE ICI (paid_gross). Le prix peut changer demain ;
--  ce que cette commande-la a coute ne bouge plus. C'est la borne du
--  remboursement : on ne rend jamais plus que ce qu'on a encaisse.
--
--  Une demande ne se SUPPRIME pas : elle se clot, avec sa raison. Une
--  reclamation effacee est une preuve detruite — et le client, lui, a garde
--  son courriel.
CREATE TABLE IF NOT EXISTS wsm_claims (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  numer        TEXT NOT NULL UNIQUE,
  order_id     INTEGER NOT NULL,
  order_code   TEXT NOT NULL DEFAULT '',
  email        TEXT NOT NULL DEFAULT '',
  type         TEXT NOT NULL DEFAULT 'reklamacja',
  statut       TEXT NOT NULL DEFAULT 'nowa',
  raison       TEXT NOT NULL DEFAULT '',
  decision     TEXT NOT NULL DEFAULT '',
  paid_gross   INTEGER NOT NULL DEFAULT 0,
  refund_gross INTEGER NOT NULL DEFAULT 0,
  created_at   TEXT NOT NULL,
  resolved_at  TEXT DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS ix_wsm_claims_order ON wsm_claims (order_id);
CREATE INDEX IF NOT EXISTS ix_wsm_claims_statut ON wsm_claims (statut);

-- Liens directs traces.
--
--  Un lien partage doit pouvoir DIRE ce qu'il a rapporte, sinon on reconduit
--  une campagne sans savoir si elle a vendu quoi que ce soit. Le compteur de
--  clics et le chiffre d'affaires vivent ici ; la commande, elle, garde la
--  source dans sa propre colonne (wsm_orders.source), figee.
CREATE TABLE IF NOT EXISTS wsm_links (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  code       TEXT NOT NULL UNIQUE,
  nom        TEXT NOT NULL DEFAULT '',
  cible      TEXT NOT NULL DEFAULT '',
  produkt    TEXT NOT NULL DEFAULT '',
  kod        TEXT NOT NULL DEFAULT '',
  klikniec   INTEGER NOT NULL DEFAULT 0,
  active     INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL
);

-- Campagnes d'e-mails.
--
--  RIEN NE PART D'ICI VERS UN SERVEUR SMTP. L'envoi met les messages en
--  `kolejka` dans wsm_messages, et le travailleur de fond les ecoule a son
--  rythme. Cent messages pousses d'un coup depuis une IP qui n'en envoie
--  jamais coutent la reputation du domaine — et avec elle, les
--  confirmations de commande.
--
--  L'idempotence vit sur wsm_messages.event_key (« camp-<id>-<adresse> ») :
--  rejouer l'ecran ne renvoie rien.
CREATE TABLE IF NOT EXISTS wsm_campaigns (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  nom        TEXT NOT NULL DEFAULT '',
  segment    TEXT NOT NULL DEFAULT 'klienci',
  sujet      TEXT NOT NULL DEFAULT '',
  corps      TEXT NOT NULL DEFAULT '',
  statut     TEXT NOT NULL DEFAULT 'przygotowana',
  wyslane    INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL,
  sent_at    TEXT DEFAULT NULL
);

-- ---------------------------------------------------------------------------
--  L'enregistreur de pages. Voir le schema MySQL pour les trois regles :
--  pas de query string (elle porte des numeros de commande et des noms), pas
--  de « qui » (le role suffit a la question posee), et des tables bornees par
--  construction (on agrege a l'ecriture, donc rien a purger).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wsm_page_views (
  id     INTEGER PRIMARY KEY AUTOINCREMENT,
  ekran  TEXT NOT NULL,
  dzien  TEXT NOT NULL,
  rola   TEXT NOT NULL DEFAULT '',
  n      INTEGER NOT NULL DEFAULT 0,
  ms_sum INTEGER NOT NULL DEFAULT 0,
  ms_max INTEGER NOT NULL DEFAULT 0,
  UNIQUE (ekran, dzien, rola)
);

CREATE TABLE IF NOT EXISTS wsm_page_paths (
  id    INTEGER PRIMARY KEY AUTOINCREMENT,
  skad  TEXT NOT NULL,
  dokad TEXT NOT NULL,
  n     INTEGER NOT NULL DEFAULT 0,
  UNIQUE (skad, dokad)
);

-- ---------------------------------------------------------------------------
--  Les profils redefinis en console. Voir le schema MySQL pour les regles :
--  surcouche par-dessus le code (vide = rien ne change), « Administrator » et
--  « Superadmin » jamais modifiables, « superadmin.php » jamais accordable.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wsm_role_profiles (
  rola TEXT PRIMARY KEY,
  opis TEXT NOT NULL DEFAULT '',
  maj  TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wsm_role_screens (
  rola  TEXT NOT NULL,
  ekran TEXT NOT NULL,
  droit TEXT NOT NULL DEFAULT 'r',
  PRIMARY KEY (rola, ekran)
);

-- ---------------------------------------------------------------------------
--  Pages et blocs écrits depuis la console (pages.php). Une « strona » a une
--  adresse ; un « blok » s'insère dans l'accueil. Les textes vivent à part,
--  une ligne par langue, avec repli sur le polonais à la lecture.
--  Le slug est NULL pour un bloc : UNIQUE accepte plusieurs NULL, pas
--  plusieurs chaînes vides.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wsm_pages (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  slug        TEXT,
  kind        TEXT NOT NULL DEFAULT 'strona',
  placement   TEXT NOT NULL DEFAULT '',
  published   INTEGER NOT NULL DEFAULT 0,
  in_nav      INTEGER NOT NULL DEFAULT 0,
  in_footer   INTEGER NOT NULL DEFAULT 0,
  sort_order  INTEGER NOT NULL DEFAULT 100,
  image_url   TEXT NOT NULL DEFAULT '',
  styl        TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL,
  updated_at  TEXT NOT NULL,
  updated_by  TEXT NOT NULL DEFAULT '',
  UNIQUE (slug)
);

CREATE TABLE IF NOT EXISTS wsm_page_i18n (
  page_id    INTEGER NOT NULL,
  lang       TEXT NOT NULL,
  title      TEXT NOT NULL DEFAULT '',
  lead       TEXT,
  body       TEXT,
  meta_desc  TEXT NOT NULL DEFAULT '',
  cta_label  TEXT NOT NULL DEFAULT '',
  cta_url    TEXT NOT NULL DEFAULT '',
  PRIMARY KEY (page_id, lang)
);

-- ---------------------------------------------------------------------------
--  Le Kreator (sections.php) : des sections typées, composées depuis la
--  console, pour l'accueil (page_id = 0), une page (page_id > 0) ou tout le
--  site (page_id = -1, la barre d'annonce). Textes à part, une ligne par
--  langue, avec repli sur le polonais à la lecture ; items en JSON.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wsm_sections (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  page_id     INTEGER NOT NULL DEFAULT 0,
  type        TEXT NOT NULL,
  sort_order  INTEGER NOT NULL DEFAULT 100,
  published   INTEGER NOT NULL DEFAULT 0,
  styl        TEXT NOT NULL DEFAULT '',
  image_url   TEXT NOT NULL DEFAULT '',
  settings    TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL,
  updated_at  TEXT NOT NULL,
  updated_by  TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS wsm_section_i18n (
  section_id INTEGER NOT NULL,
  lang       TEXT NOT NULL,
  title      TEXT NOT NULL DEFAULT '',
  lead       TEXT,
  body       TEXT,
  cta_label  TEXT NOT NULL DEFAULT '',
  cta_url    TEXT NOT NULL DEFAULT '',
  items      TEXT,
  PRIMARY KEY (section_id, lang)
);

-- Les noms des fichiers de la médiathèque : l'adresse (media/<hash>.webp) est
-- stable et citée partout, le nom est pour les gens — modifiable dans Media.
CREATE TABLE IF NOT EXISTS wsm_media (
  url        TEXT PRIMARY KEY,
  title      TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  updated_by TEXT NOT NULL DEFAULT ''
);

-- Les slajdy du haut de la page d'accueil (slajdy.php). Voir le schéma MySQL.
CREATE TABLE IF NOT EXISTS wsm_slides (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  sort_order INTEGER NOT NULL DEFAULT 100,
  active     INTEGER NOT NULL DEFAULT 1,
  image_url  TEXT NOT NULL DEFAULT '',
  link       TEXT NOT NULL DEFAULT '',
  date_from  TEXT DEFAULT NULL,
  date_to    TEXT DEFAULT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  updated_by TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS wsm_slide_i18n (
  slide_id  INTEGER NOT NULL,
  lang      TEXT NOT NULL,
  eyebrow   TEXT NOT NULL DEFAULT '',
  title     TEXT NOT NULL DEFAULT '',
  tekst     TEXT NOT NULL DEFAULT '',
  cta_label TEXT NOT NULL DEFAULT '',
  PRIMARY KEY (slide_id, lang)
);

-- MARKETING (marketing.php, newsletter.php, smsapi.php) — voir le schéma MySQL.
CREATE TABLE IF NOT EXISTS wsm_subscribers (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  email             TEXT NOT NULL DEFAULT '' UNIQUE,
  phone             TEXT NOT NULL DEFAULT '',
  name              TEXT NOT NULL DEFAULT '',
  lang              TEXT NOT NULL DEFAULT 'pl',
  source            TEXT NOT NULL DEFAULT '',
  email_status      TEXT NOT NULL DEFAULT '',
  email_zgoda_at    TEXT DEFAULT NULL,
  email_potw_at     TEXT DEFAULT NULL,
  email_wypis_at    TEXT DEFAULT NULL,
  email_zgoda_tekst TEXT NOT NULL DEFAULT '',
  sms_status        TEXT NOT NULL DEFAULT '',
  sms_zgoda_at      TEXT DEFAULT NULL,
  sms_wypis_at      TEXT DEFAULT NULL,
  sms_zgoda_tekst   TEXT NOT NULL DEFAULT '',
  token             TEXT NOT NULL DEFAULT '',
  token_at          TEXT DEFAULT NULL,
  sms_token         TEXT NOT NULL DEFAULT '',
  ip                TEXT NOT NULL DEFAULT '',
  created_at        TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TEXT DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_wsm_subscribers_phone ON wsm_subscribers (phone);
CREATE INDEX IF NOT EXISTS idx_wsm_subscribers_token ON wsm_subscribers (token);
CREATE INDEX IF NOT EXISTS idx_wsm_subscribers_sms_token ON wsm_subscribers (sms_token);

CREATE TABLE IF NOT EXISTS wsm_mkt_events (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  typ        TEXT NOT NULL DEFAULT '',
  ref        TEXT NOT NULL DEFAULT '',
  kto        TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_wsm_mkt_events_ref ON wsm_mkt_events (ref, typ);

CREATE TABLE IF NOT EXISTS wsm_newsletters (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  nazwa       TEXT NOT NULL DEFAULT '',
  temat       TEXT NOT NULL DEFAULT '',
  preheader   TEXT NOT NULL DEFAULT '',
  bloki       TEXT,
  tlumaczenia TEXT,
  segment     TEXT NOT NULL DEFAULT 'wszyscy',
  kod         TEXT NOT NULL DEFAULT '',
  link_code   TEXT NOT NULL DEFAULT '',
  status      TEXT NOT NULL DEFAULT 'szkic',
  odbiorcow   INTEGER NOT NULL DEFAULT 0,
  created_by  TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TEXT DEFAULT NULL,
  sent_at     TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wsm_sms_campaigns (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  nazwa       TEXT NOT NULL DEFAULT '',
  tresc       TEXT,
  tlumaczenia TEXT,
  segment     TEXT NOT NULL DEFAULT 'wszyscy',
  ascii       INTEGER NOT NULL DEFAULT 1,
  kod         TEXT NOT NULL DEFAULT '',
  link_code   TEXT NOT NULL DEFAULT '',
  status      TEXT NOT NULL DEFAULT 'szkic',
  odbiorcow   INTEGER NOT NULL DEFAULT 0,
  czesci      INTEGER NOT NULL DEFAULT 0,
  created_by  TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TEXT DEFAULT NULL,
  sent_at     TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wsm_sms (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  campaign_id INTEGER NULL,
  phone       TEXT NOT NULL DEFAULT '',
  tresc       TEXT,
  czesci      INTEGER NOT NULL DEFAULT 1,
  status      TEXT NOT NULL DEFAULT 'kolejka',
  raport      TEXT NOT NULL DEFAULT '',
  sms_id      TEXT NOT NULL DEFAULT '',
  error       TEXT NOT NULL DEFAULT '',
  event_key   TEXT NULL UNIQUE,
  test        INTEGER NOT NULL DEFAULT 0,
  actor       TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at     TEXT DEFAULT NULL,
  raport_at   TEXT DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_wsm_sms_status ON wsm_sms (status, id);
CREATE INDEX IF NOT EXISTS idx_wsm_sms_smsid ON wsm_sms (sms_id);
CREATE INDEX IF NOT EXISTS idx_wsm_sms_campaign ON wsm_sms (campaign_id);

CREATE TABLE IF NOT EXISTS wsm_purchase_invoices (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  status        TEXT NOT NULL DEFAULT 'szkic',
  source        TEXT NOT NULL DEFAULT 'pdf',
  supplier_name TEXT NOT NULL DEFAULT '',
  supplier_nip  TEXT NOT NULL DEFAULT '',
  buyer_nip     TEXT NOT NULL DEFAULT '',
  number        TEXT NOT NULL DEFAULT '',
  issued_at     TEXT DEFAULT NULL,
  currency      TEXT NOT NULL DEFAULT 'PLN',
  net           INTEGER NOT NULL DEFAULT 0,
  vat           INTEGER NOT NULL DEFAULT 0,
  gross         INTEGER NOT NULL DEFAULT 0,
  file          TEXT NOT NULL DEFAULT '',
  file_name     TEXT NOT NULL DEFAULT '',
  file_sha1     TEXT NOT NULL DEFAULT '',
  uwagi         TEXT,
  doc_id        INTEGER NULL,
  actor         TEXT NOT NULL DEFAULT '',
  created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  posted_at     TEXT DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_wsm_purchase_inv_status ON wsm_purchase_invoices (status, id);
CREATE INDEX IF NOT EXISTS idx_wsm_purchase_inv_number ON wsm_purchase_invoices (supplier_nip, number);
CREATE INDEX IF NOT EXISTS idx_wsm_purchase_inv_doc ON wsm_purchase_invoices (doc_id);

CREATE TABLE IF NOT EXISTS wsm_purchase_lines (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_id  INTEGER NOT NULL,
  lp          INTEGER NOT NULL DEFAULT 0,
  name        TEXT NOT NULL DEFAULT '',
  code        TEXT NOT NULL DEFAULT '',
  ean         TEXT NOT NULL DEFAULT '',
  qty         REAL NOT NULL DEFAULT 0,
  unit        TEXT NOT NULL DEFAULT '',
  unit_net    INTEGER NOT NULL DEFAULT 0,
  vat_rate    TEXT NOT NULL DEFAULT '',
  net         INTEGER NOT NULL DEFAULT 0,
  vat         INTEGER NOT NULL DEFAULT 0,
  gross       INTEGER NOT NULL DEFAULT 0,
  product_id  TEXT NULL,
  factor      REAL NOT NULL DEFAULT 1,
  stock_qty   INTEGER NOT NULL DEFAULT 0,
  dopasowanie TEXT NOT NULL DEFAULT '',
  score       INTEGER NOT NULL DEFAULT 0,
  skip        INTEGER NOT NULL DEFAULT 0,
  move_id     INTEGER NULL
);
CREATE INDEX IF NOT EXISTS idx_wsm_purchase_lines_inv ON wsm_purchase_lines (invoice_id, lp);
CREATE INDEX IF NOT EXISTS idx_wsm_purchase_lines_move ON wsm_purchase_lines (move_id);
CREATE INDEX IF NOT EXISTS idx_wsm_purchase_lines_product ON wsm_purchase_lines (product_id);

CREATE TABLE IF NOT EXISTS wsm_purchase_map (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  supplier_key TEXT NOT NULL,
  line_key     TEXT NOT NULL,
  product_id   TEXT NOT NULL,
  factor       REAL NOT NULL DEFAULT 1,
  uses         INTEGER NOT NULL DEFAULT 0,
  updated_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (supplier_key, line_key)
);

-- Marketing Google / Meta (reklama.php, mediateka.php, social.php, formularze.php)
CREATE TABLE IF NOT EXISTS wsm_ad_orders (
  order_id     INTEGER PRIMARY KEY,
  analityczne  INTEGER NOT NULL DEFAULT 0,
  marketingowe INTEGER NOT NULL DEFAULT 0,
  fbp          TEXT NOT NULL DEFAULT '',
  fbc          TEXT NOT NULL DEFAULT '',
  ip           TEXT NOT NULL DEFAULT '',
  ua           TEXT NOT NULL DEFAULT '',
  page_url     TEXT NOT NULL DEFAULT '',
  created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_wsm_ad_orders_date ON wsm_ad_orders (created_at);

CREATE TABLE IF NOT EXISTS wsm_capi_events (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id   INTEGER NOT NULL,
  event_name TEXT NOT NULL,
  status     TEXT NOT NULL DEFAULT 'nowe',
  attempts   INTEGER NOT NULL DEFAULT 0,
  response   TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  tried_at   TEXT NULL,
  sent_at    TEXT NULL,
  UNIQUE (order_id, event_name)
);
CREATE INDEX IF NOT EXISTS ix_wsm_capi_events_status ON wsm_capi_events (status, id);

CREATE TABLE IF NOT EXISTS wsm_media_meta (
  url        TEXT PRIMARY KEY,
  kategoria  TEXT NOT NULL DEFAULT '',
  okazja     TEXT NOT NULL DEFAULT '',
  product_id TEXT NOT NULL DEFAULT '',
  alt        TEXT NOT NULL DEFAULT '',
  tagi       TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS ix_wsm_media_meta_kat ON wsm_media_meta (kategoria, okazja);
CREATE INDEX IF NOT EXISTS ix_wsm_media_meta_prod ON wsm_media_meta (product_id);

CREATE TABLE IF NOT EXISTS wsm_social_posts (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tekst        TEXT NOT NULL,
  media        TEXT NOT NULL,
  product_id   TEXT NOT NULL DEFAULT '',
  link_code    TEXT NOT NULL DEFAULT '',
  kanaly       TEXT NOT NULL DEFAULT 'fb,ig',
  termin       TEXT NULL,
  status       TEXT NOT NULL DEFAULT 'szkic',
  fb_status    TEXT NOT NULL DEFAULT '',
  fb_id        TEXT NOT NULL DEFAULT '',
  fb_url       TEXT NOT NULL DEFAULT '',
  fb_error     TEXT NOT NULL DEFAULT '',
  ig_status    TEXT NOT NULL DEFAULT '',
  ig_id        TEXT NOT NULL DEFAULT '',
  ig_url       TEXT NOT NULL DEFAULT '',
  ig_error     TEXT NOT NULL DEFAULT '',
  created_by   TEXT NOT NULL DEFAULT '',
  created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  claimed_at   TEXT NULL,
  published_at TEXT NULL
);
CREATE INDEX IF NOT EXISTS ix_wsm_social_posts_due ON wsm_social_posts (status, termin);

CREATE TABLE IF NOT EXISTS wsm_forms (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  slug          TEXT NOT NULL UNIQUE,
  nazwa         TEXT NOT NULL,
  wstep         TEXT NOT NULL,
  pola          TEXT NOT NULL,
  podziekowanie TEXT NOT NULL DEFAULT '',
  powiadom      TEXT NOT NULL DEFAULT '',
  newsletter    INTEGER NOT NULL DEFAULT 1,
  aktywny       INTEGER NOT NULL DEFAULT 1,
  created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wsm_form_entries (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  form_id     INTEGER NOT NULL,
  dane        TEXT NOT NULL,
  email       TEXT NOT NULL DEFAULT '',
  status      TEXT NOT NULL DEFAULT 'nowe',
  zgoda_tekst TEXT NOT NULL DEFAULT '',
  newsletter  INTEGER NOT NULL DEFAULT 0,
  ip          TEXT NOT NULL DEFAULT '',
  lang        TEXT NOT NULL DEFAULT 'pl',
  notatka     TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_wsm_form_entries_form ON wsm_form_entries (form_id, created_at);
CREATE INDEX IF NOT EXISTS ix_wsm_form_entries_ip ON wsm_form_entries (ip, created_at);
