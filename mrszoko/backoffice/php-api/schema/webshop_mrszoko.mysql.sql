-- ============================================================================
--  webshop_mrszoko — canonical MySQL schema (Console marque · franchisor)
-- ----------------------------------------------------------------------------
--  Every domain table lives here, all prefixed `wsm_`. This is the single
--  source of truth read by the back-office through php-api/. No business data
--  is hardcoded in the front-end: the UI reads these tables via the API.
--
--  Idempotent & additive: safe to run more than once. Seed rows live in
--  seed.php (engine-agnostic, run by migrate.php).
--
--  MySQL 8+ / utf8mb4.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `webshop_mrszoko`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `webshop_mrszoko`;

-- --- Dashboard KPI snapshot ------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_kpis` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sort_order`  INT NOT NULL DEFAULT 0,
  `label`       VARCHAR(120) NOT NULL,
  `value`       VARCHAR(60)  NOT NULL,
  `val_color`   VARCHAR(40)  NOT NULL DEFAULT 'var(--color-text)',
  `delta`       VARCHAR(60)  NOT NULL DEFAULT '',
  `delta_color` VARCHAR(40)  NOT NULL DEFAULT '#2d7a3e',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Shops (boutiques du réseau) ------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_shops` (
  `id`         VARCHAR(24)  NOT NULL,
  `nom`        VARCHAR(160) NOT NULL,
  `ville`      VARCHAR(120) NOT NULL DEFAULT '',
  `web`        TINYINT(1)   NOT NULL DEFAULT 1,
  `contrat`    VARCHAR(40)  NOT NULL DEFAULT 'Franchise',
  `act`        TINYINT(1)   NOT NULL DEFAULT 1,
  `ca_shop`    INT NOT NULL DEFAULT 0,
  `ca_office`  INT NOT NULL DEFAULT 0,
  `adoption`   INT NOT NULL DEFAULT 0,
  `accent`     VARCHAR(40)  NOT NULL DEFAULT 'var(--color-primary)',
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Product categories -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_categories` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(120) NOT NULL,
  `parent_id`       INT NOT NULL DEFAULT 0,          -- 0 = rayon de premier niveau
  `sort_order`      INT NOT NULL DEFAULT 0,
  `menu_default`    TINYINT(1) NOT NULL DEFAULT 0,
  `brand_whitelist` TINYINT(1) NOT NULL DEFAULT 1,
  `office_delivery` TINYINT(1) NOT NULL DEFAULT 0,
  `brand_mandatory` TINYINT(1) NOT NULL DEFAULT 0,
  `active`          TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Products (catalogue) ---------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_products` (
  `id`              VARCHAR(48)  NOT NULL,
  -- Vitrine de la boutique (les textes traduits vivent dans wsm_shop_i18n
  -- sous product.<id>.name / .subtitle / .desc — rien en dur dans les pages)
  `slug`            VARCHAR(80)  NOT NULL DEFAULT '',
  `shop_visible`    TINYINT(1)   NOT NULL DEFAULT 0,
  `stock`           INT NOT NULL DEFAULT 0,
  `image_url`       VARCHAR(255) NOT NULL DEFAULT '',
  `swatch_from`     VARCHAR(32)  NOT NULL DEFAULT '--choco-500',
  `swatch_to`       VARCHAR(32)  NOT NULL DEFAULT '--choco-800',
  `origin`          VARCHAR(80)  NOT NULL DEFAULT '',
  `cocoa`           VARCHAR(16)  NOT NULL DEFAULT '',
  `unit_label`      VARCHAR(40)  NOT NULL DEFAULT '',
  `badge`           VARCHAR(40)  NOT NULL DEFAULT '',
  `nutrition`       TEXT,                          -- wartości odżywcze / 100 g, JSON
  -- Expédition InPost + fiscalité tpay
  `rodzaj`          VARCHAR(12)  NOT NULL DEFAULT 'towar',  -- towar | usluga | subskrypcja
  `sku`             VARCHAR(60)  NOT NULL DEFAULT '',
  `ean`             VARCHAR(14)  NOT NULL DEFAULT '',
  `vat_rate`        DECIMAL(4,2) NOT NULL DEFAULT 0.23,
  `weight_g`        INT NOT NULL DEFAULT 0,
  `length_mm`       INT NOT NULL DEFAULT 0,
  `width_mm`        INT NOT NULL DEFAULT 0,
  `height_mm`       INT NOT NULL DEFAULT 0,
  `parcel_template` CHAR(1)      NOT NULL DEFAULT '',
  `category_id`     INT UNSIGNED NOT NULL,
  `nom`             VARCHAR(160) NOT NULL,
  `prix`            DECIMAL(10,2) NOT NULL DEFAULT 0,
  `base_cost`       DECIMAL(10,2) NOT NULL DEFAULT 0,
  `statut`          VARCHAR(40)  NOT NULL DEFAULT 'Publié',
  `saison`          VARCHAR(40)  NOT NULL DEFAULT '',
  `brand_whitelist` TINYINT(1) NOT NULL DEFAULT 0,
  `brand_mandatory` TINYINT(1) NOT NULL DEFAULT 0,
  `adoption`        INT NOT NULL DEFAULT 0,
  `menu_override`   VARCHAR(8)   NULL DEFAULT NULL,
  `sort_order`      INT NOT NULL DEFAULT 0,
  `active`          TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_products_category` (`category_id`),
  CONSTRAINT `fk_wsm_products_category`
    FOREIGN KEY (`category_id`) REFERENCES `wsm_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Brand vouchers ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_vouchers` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`     VARCHAR(60)  NOT NULL,
  `valeur`   VARCHAR(160) NOT NULL DEFAULT '',
  `type`     VARCHAR(40)  NOT NULL DEFAULT 'Panier',
  `validite` VARCHAR(80)  NOT NULL DEFAULT '',
  -- Ce qui agit REELLEMENT sur le prix. Les quatre colonnes ci-dessus sont
  -- d'affichage : elles etaient seules, et aucune caisse n'a jamais lu un code.
  `kind`       VARCHAR(20)  NOT NULL DEFAULT 'procent',
  `pct`        DECIMAL(5,2) NOT NULL DEFAULT 0,
  `kwota`      INT          NOT NULL DEFAULT 0,
  `min_gross`  INT          NOT NULL DEFAULT 0,
  `starts_at`  DATETIME     NULL DEFAULT NULL,
  `ends_at`    DATETIME     NULL DEFAULT NULL,
  `max_uses`   INT          NOT NULL DEFAULT 0,
  `per_email`  INT          NOT NULL DEFAULT 0,
  `used`       INT          NOT NULL DEFAULT 0,
  `active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `note`       VARCHAR(190) NOT NULL DEFAULT '',
  `created_at` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_vouchers_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Network pricing rules ---------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_pricing_rules` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nom`     VARCHAR(160) NOT NULL,
  `cible`   VARCHAR(160) NOT NULL DEFAULT '',
  `effet`   VARCHAR(80)  NOT NULL DEFAULT '',
  `shop_id` VARCHAR(24)  NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Brand parameters (config) ----------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_params` (
  `cle`  VARCHAR(120) NOT NULL,
  `type` VARCHAR(16)  NOT NULL DEFAULT 'text',
  `val`  VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Email templates --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_email_templates` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cle`    VARCHAR(80)  NOT NULL,
  `langue` VARCHAR(8)   NOT NULL DEFAULT 'FR',
  `sujet`  VARCHAR(200) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_email_templates` (`cle`, `langue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Users (RBAC) -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_users` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nom`    VARCHAR(160) NOT NULL,
  `email`  VARCHAR(200) NOT NULL,
  `role`   VARCHAR(40)  NOT NULL DEFAULT 'Franczyza',
  `portee` VARCHAR(200) NOT NULL DEFAULT '',
  `act`    TINYINT(1)   NOT NULL DEFAULT 1,
  -- Authentification (voir auth.php). Un compte sans hachage ne peut pas se
  -- connecter : les comptes de démonstration sont donc inertes tant qu'un
  -- mot de passe n'a pas été posé (migrate.php --set-password).
  `password_hash`   VARCHAR(255) NULL DEFAULT NULL,
  `last_login`      DATETIME NULL DEFAULT NULL,
  `failed_attempts` INT NOT NULL DEFAULT 0,
  `locked_until`    DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_user_shops` (
  `user_id` INT UNSIGNED NOT NULL,
  `shop_id` VARCHAR(24)  NOT NULL,
  PRIMARY KEY (`user_id`, `shop_id`),
  CONSTRAINT `fk_wsm_user_shops_user`
    FOREIGN KEY (`user_id`) REFERENCES `wsm_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Audit log --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_audit` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ts`         VARCHAR(40)  NOT NULL DEFAULT '',
  `user`       VARCHAR(160) NOT NULL DEFAULT '',
  `verb`       VARCHAR(40)  NOT NULL DEFAULT '',
  `entity`     VARCHAR(200) NOT NULL DEFAULT '',
  `shop`       VARCHAR(120) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Catchment zones (zones de chalandise) ----------------------------------
CREATE TABLE IF NOT EXISTS `wsm_catchment` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`      VARCHAR(200) NOT NULL,
  `postcodes` TEXT NULL,
  `exclusive` TINYINT(1) NOT NULL DEFAULT 1,
  `active`    TINYINT(1) NOT NULL DEFAULT 1,
  `shop_id`   VARCHAR(24) NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Menu builder (bundles / slots / choices) -------------------------------
CREATE TABLE IF NOT EXISTS `wsm_bundles` (
  `id`             VARCHAR(48)  NOT NULL,
  `product_id`     VARCHAR(48)  NOT NULL,
  `name`           VARCHAR(160) NOT NULL DEFAULT 'Nouvelle formule',
  `description`    VARCHAR(255) NOT NULL DEFAULT '',
  `price_modifier` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `sort_order`     INT NOT NULL DEFAULT 0,
  `active`         TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_bundles_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_bundle_slots` (
  `id`         VARCHAR(48)  NOT NULL,
  `bundle_id`  VARCHAR(48)  NOT NULL,
  `label`      VARCHAR(160) NOT NULL DEFAULT 'Nouvelle étape',
  `required`   TINYINT(1) NOT NULL DEFAULT 1,
  `kind`       VARCHAR(16) NOT NULL DEFAULT 'single',
  `min_select` INT NOT NULL DEFAULT 1,
  `max_select` INT NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `active`     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_slots_bundle` (`bundle_id`),
  CONSTRAINT `fk_wsm_slots_bundle`
    FOREIGN KEY (`bundle_id`) REFERENCES `wsm_bundles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_bundle_slot_choices` (
  `id`         VARCHAR(48)  NOT NULL,
  `slot_id`    VARCHAR(48)  NOT NULL,
  `label`      VARCHAR(160) NOT NULL DEFAULT 'Nouveau choix',
  `img`        VARCHAR(16)  NOT NULL DEFAULT '',
  `delta`      DECIMAL(10,2) NOT NULL DEFAULT 0,
  `cost`       DECIMAL(10,2) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `active`     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_choices_slot` (`slot_id`),
  CONSTRAINT `fk_wsm_choices_slot`
    FOREIGN KEY (`slot_id`) REFERENCES `wsm_bundle_slots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  DELIVERY MODULE  (livraison B2B — bureaux / points de livraison)
-- ============================================================================

-- --- B2B clients ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_clients` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- tpay (payeur + facture) & InPost (destinataire) — voir commerce.php
  `client_type`   VARCHAR(10)  NOT NULL DEFAULT 'firma',
  `email`         VARCHAR(200) NOT NULL DEFAULT '',
  `phone`         VARCHAR(20)  NOT NULL DEFAULT '',
  `first_name`    VARCHAR(120) NOT NULL DEFAULT '',
  `last_name`     VARCHAR(120) NOT NULL DEFAULT '',
  `nip`           VARCHAR(15)  NOT NULL DEFAULT '',
  `vat_eu`        VARCHAR(20)  NOT NULL DEFAULT '',
  `bill_street`   VARCHAR(200) NOT NULL DEFAULT '',
  `bill_building` VARCHAR(30)  NOT NULL DEFAULT '',
  `bill_postcode` VARCHAR(10)  NOT NULL DEFAULT '',
  `bill_city`     VARCHAR(120) NOT NULL DEFAULT '',
  `bill_country`  CHAR(2)      NOT NULL DEFAULT 'PL',
  `code`     VARCHAR(24)  NOT NULL,
  `raison`   VARCHAR(200) NOT NULL,
  `seg`      VARCHAR(40)  NOT NULL DEFAULT 'horeca',
  `statut`   VARCHAR(40)  NOT NULL DEFAULT 'actif',
  `tva`      VARCHAR(40)  NOT NULL DEFAULT '',
  `paiement` VARCHAR(80)  NOT NULL DEFAULT '',
  `plafond`  INT NOT NULL DEFAULT 0,
  `encours`  INT NOT NULL DEFAULT 0,
  `franco`   VARCHAR(24)  NOT NULL DEFAULT '',
  `remise`   VARCHAR(24)  NOT NULL DEFAULT '',
  `fact`     VARCHAR(40)  NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_clients_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Delivery points (adresses de livraison d'un client) --------------------
CREATE TABLE IF NOT EXISTS `wsm_client_points` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- InPost : Paczkomat (code) ou coursier (adresse structurée)
  `delivery_method` VARCHAR(20)  NOT NULL DEFAULT 'inpost_locker',
  `inpost_point`    VARCHAR(20)  NOT NULL DEFAULT '',
  `street`          VARCHAR(200) NOT NULL DEFAULT '',
  `building`        VARCHAR(30)  NOT NULL DEFAULT '',
  `postcode`        VARCHAR(10)  NOT NULL DEFAULT '',
  `city`            VARCHAR(120) NOT NULL DEFAULT '',
  `country`         CHAR(2)      NOT NULL DEFAULT 'PL',
  `contact_phone`   VARCHAR(20)  NOT NULL DEFAULT '',
  `contact_email`   VARCHAR(200) NOT NULL DEFAULT '',
  `client_id`  INT UNSIGNED NOT NULL,
  `libelle`    VARCHAR(200) NOT NULL,
  `adresse`    VARCHAR(255) NOT NULL DEFAULT '',
  `fenetre`    VARCHAR(40)  NOT NULL DEFAULT '',
  `jours`      VARCHAR(40)  NOT NULL DEFAULT '',
  `validation` VARCHAR(24)  NOT NULL DEFAULT 'QR',
  `marge`      INT NOT NULL DEFAULT 0,
  `lat`        DECIMAL(9,6) NULL DEFAULT NULL,
  `lng`        DECIMAL(9,6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_points_client` (`client_id`),
  CONSTRAINT `fk_wsm_points_client`
    FOREIGN KEY (`client_id`) REFERENCES `wsm_clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Drivers (chauffeurs) ---------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_drivers` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nom`      VARCHAR(160) NOT NULL,
  `info`     VARCHAR(200) NOT NULL DEFAULT '',
  `color`    VARCHAR(40)  NOT NULL DEFAULT '#8D1D2C',
  `vehicule` VARCHAR(120) NOT NULL DEFAULT '',
  `zone`     VARCHAR(120) NOT NULL DEFAULT '',
  `active`   TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Rounds (tournées) ------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_rounds` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`      VARCHAR(160) NOT NULL,
  `driver_id` INT UNSIGNED NULL DEFAULT NULL,
  `round_date` DATE NULL DEFAULT NULL,
  `status`    VARCHAR(24) NOT NULL DEFAULT 'planifiée',
  PRIMARY KEY (`id`),
  KEY `idx_wsm_rounds_driver` (`driver_id`),
  CONSTRAINT `fk_wsm_rounds_driver`
    FOREIGN KEY (`driver_id`) REFERENCES `wsm_drivers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Deliveries (livraisons) ------------------------------------------------
--  status flow: brouillon → planifiée → assignée → en_cours → livrée
--                                                          ↘ échouée
CREATE TABLE IF NOT EXISTS `wsm_deliveries` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref`               VARCHAR(32)  NOT NULL,
  `client_id`         INT UNSIGNED NULL DEFAULT NULL,
  `point_id`          INT UNSIGNED NULL DEFAULT NULL,
  `driver_id`         INT UNSIGNED NULL DEFAULT NULL,
  `round_id`          INT UNSIGNED NULL DEFAULT NULL,
  `status`            VARCHAR(24)  NOT NULL DEFAULT 'brouillon',
  `window_label`      VARCHAR(40)  NOT NULL DEFAULT '',
  `validation_method` VARCHAR(16)  NOT NULL DEFAULT 'QR',
  `confirm_code`      VARCHAR(32)  NOT NULL DEFAULT '',
  `confirmed`         TINYINT(1)   NOT NULL DEFAULT 0,
  `ca`                DECIMAL(10,2) NOT NULL DEFAULT 0,
  `couts`             DECIMAL(10,2) NOT NULL DEFAULT 0,
  `scheduled_date`    DATE NULL DEFAULT NULL,
  `notes`             VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at`      DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_deliveries_ref` (`ref`),
  KEY `idx_wsm_deliveries_status` (`status`),
  KEY `idx_wsm_deliveries_driver` (`driver_id`),
  CONSTRAINT `fk_wsm_deliveries_client`
    FOREIGN KEY (`client_id`) REFERENCES `wsm_clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wsm_deliveries_point`
    FOREIGN KEY (`point_id`) REFERENCES `wsm_client_points` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wsm_deliveries_driver`
    FOREIGN KEY (`driver_id`) REFERENCES `wsm_drivers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wsm_deliveries_round`
    FOREIGN KEY (`round_id`) REFERENCES `wsm_rounds` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Delivery events (piste d'audit d'une livraison) ------------------------
CREATE TABLE IF NOT EXISTS `wsm_delivery_events` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_id` INT UNSIGNED NOT NULL,
  `event`       VARCHAR(40)  NOT NULL,
  `detail`      VARCHAR(255) NOT NULL DEFAULT '',
  `actor`       VARCHAR(160) NOT NULL DEFAULT '',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_devents_delivery` (`delivery_id`),
  CONSTRAINT `fk_wsm_devents_delivery`
    FOREIGN KEY (`delivery_id`) REFERENCES `wsm_deliveries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Delivery incidents -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_incidents` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref`         VARCHAR(32)  NOT NULL,
  `delivery_id` INT UNSIGNED NULL DEFAULT NULL,
  `type`        VARCHAR(80)  NOT NULL,
  `point`       VARCHAR(200) NOT NULL DEFAULT '',
  `statut`      VARCHAR(40)  NOT NULL DEFAULT 'Do obsłużenia',
  `impact`      VARCHAR(40)  NOT NULL DEFAULT '',
  `description` TEXT NULL,
  `geo`         VARCHAR(60)  NOT NULL DEFAULT '',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_incidents_ref` (`ref`),
  KEY `idx_wsm_incidents_delivery` (`delivery_id`),
  CONSTRAINT `fk_wsm_incidents_delivery`
    FOREIGN KEY (`delivery_id`) REFERENCES `wsm_deliveries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Landing Mister Szoko (contenu du site public, 3 langues) ---------------
-- Tout le texte de la landing vit ici (aucun libellé en dur dans la page) ;
-- seedé depuis landing/content_seed.json, éditable via l'API admin.
CREATE TABLE IF NOT EXISTS `wsm_landing_i18n` (
  `lang` VARCHAR(5)  NOT NULL,
  `k`    VARCHAR(64) NOT NULL,
  `v`    TEXT        NOT NULL,
  PRIMARY KEY (`lang`, `k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Données structurées des cartes produit (les textes sont dans wsm_landing_i18n
-- sous product.<id>.name/.meta/.specs).
CREATE TABLE IF NOT EXISTS `wsm_landing_products` (
  `id`              VARCHAR(32) NOT NULL,
  `sort_order`      INT NOT NULL DEFAULT 0,
  `swatch_from`     VARCHAR(32) NOT NULL DEFAULT '--choco-900',
  `swatch_to`       VARCHAR(32) NOT NULL DEFAULT '--choco-700',
  `fluidity`        TINYINT NOT NULL DEFAULT 3,
  `active`          TINYINT(1) NOT NULL DEFAULT 1,
  `price_from_pln`  DECIMAL(10,2) NULL,
  `price_perkg_pln` DECIMAL(10,2) NULL,
  `price_from_eur`  DECIMAL(10,2) NULL,
  `price_perkg_eur` DECIMAL(10,2) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  BOUTIQUE EN LIGNE — commandes, paiements (Stripe), expéditions (InPost)
-- ----------------------------------------------------------------------------
--  Tous les montants sont en GROSZE (entiers). Jamais de flottant sur de
--  l'argent : 0.1 + 0.2 != 0.3 en binaire, et une facture doit tomber juste.
--  Le client n'envoie JAMAIS un prix : le serveur les relit dans wsm_products.
-- ============================================================================

-- --- Modes de livraison (tarifs pilotés par la base, pas par le code) -------
CREATE TABLE IF NOT EXISTS `wsm_shipping_methods` (
  `id`           VARCHAR(24)  NOT NULL,          -- inpost_locker | inpost_courier | dpd_courier
  `carrier`      VARCHAR(24)  NOT NULL DEFAULT 'inpost',
  -- 'punkt' : le client designe un point (Paczkomat, DPD Pickup).
  -- 'adres' : le colis va a une adresse.
  -- Rangee ICI et pas devinee du nom : quatorze endroits comparaient
  -- l'identifiant a « inpost_locker », et repondaient donc « adresse » pour
  -- tout transporteur ajoute ensuite.
  `kind`         VARCHAR(12)  NOT NULL DEFAULT 'adres',
  `sort_order`   INT NOT NULL DEFAULT 0,
  `active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `price_net`    INT NOT NULL DEFAULT 0,         -- grosze, hors TVA
  `vat_rate`     DECIMAL(4,2) NOT NULL DEFAULT 0.23,
  `free_from`    INT NOT NULL DEFAULT 0,         -- franco en grosze TTC (0 = jamais)
  -- LE POIDS MINIMUM, et pas seulement le maximum. Un transporteur de palettes
  -- (Fresh Logistic) commence a 200 kg : le proposer pour une tablette de
  -- chocolat est absurde, et l'inverse — proposer un Paczkomat pour 300 kg —
  -- l'est tout autant. Les deux bornes se lisent donc ici, pas dans du code.
  `min_weight_g` INT NOT NULL DEFAULT 0,
  `max_weight_g` INT NOT NULL DEFAULT 25000,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Commandes --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_orders` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(24)  NOT NULL,       -- MS-260730-0001
  `access_token`    VARCHAR(64)  NOT NULL,       -- relire sa commande sans compte
  `lang`            VARCHAR(5)   NOT NULL DEFAULT 'pl',
  `currency`        CHAR(3)      NOT NULL DEFAULT 'PLN',
  `status`          VARCHAR(24)  NOT NULL DEFAULT 'nowe',
  `payment_status`  VARCHAR(24)  NOT NULL DEFAULT 'oczekuje',
  -- Acheteur : figé au moment de la commande (tpay payeur + InPost destinataire)
  `client_id`       INT UNSIGNED NULL DEFAULT NULL,
  `client_type`     VARCHAR(10)  NOT NULL DEFAULT 'osoba',
  `email`           VARCHAR(200) NOT NULL DEFAULT '',
  `phone`           VARCHAR(20)  NOT NULL DEFAULT '',
  `first_name`      VARCHAR(120) NOT NULL DEFAULT '',
  `last_name`       VARCHAR(120) NOT NULL DEFAULT '',
  `company`         VARCHAR(200) NOT NULL DEFAULT '',
  `nip`             VARCHAR(15)  NOT NULL DEFAULT '',
  `vat_eu`          VARCHAR(20)  NOT NULL DEFAULT '',
  `invoice`         TINYINT(1)   NOT NULL DEFAULT 0,
  `paragon_forma`   VARCHAR(8)   NOT NULL DEFAULT '',  -- 'e' accord e-paragon · 'papier' · '' pas demandé
  `bill_street`     VARCHAR(200) NOT NULL DEFAULT '',
  `bill_building`   VARCHAR(30)  NOT NULL DEFAULT '',
  `bill_postcode`   VARCHAR(10)  NOT NULL DEFAULT '',
  `bill_city`       VARCHAR(120) NOT NULL DEFAULT '',
  `bill_country`    CHAR(2)      NOT NULL DEFAULT 'PL',
  -- Livraison
  `delivery_method` VARCHAR(24)  NOT NULL DEFAULT 'inpost_locker',
  `inpost_point`    VARCHAR(20)  NOT NULL DEFAULT '',
  `ship_street`     VARCHAR(200) NOT NULL DEFAULT '',
  `ship_building`   VARCHAR(30)  NOT NULL DEFAULT '',
  `ship_postcode`   VARCHAR(10)  NOT NULL DEFAULT '',
  `ship_city`       VARCHAR(120) NOT NULL DEFAULT '',
  `ship_country`    CHAR(2)      NOT NULL DEFAULT 'PL',
  -- Montants en grosze
  `items_net`       INT NOT NULL DEFAULT 0,
  `items_vat`       INT NOT NULL DEFAULT 0,
  `items_gross`     INT NOT NULL DEFAULT 0,
  `shipping_net`    INT NOT NULL DEFAULT 0,
  `shipping_vat`    INT NOT NULL DEFAULT 0,
  `shipping_gross`  INT NOT NULL DEFAULT 0,
  `total_net`       INT NOT NULL DEFAULT 0,
  `total_vat`       INT NOT NULL DEFAULT 0,
  `total_gross`     INT NOT NULL DEFAULT 0,
  `weight_g`        INT NOT NULL DEFAULT 0,
  `parcel_template` CHAR(1)      NOT NULL DEFAULT '',
  `note`            TEXT,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `paid_at`         DATETIME NULL DEFAULT NULL,
  `shipped_at`      DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_orders_code` (`code`),
  KEY `idx_wsm_orders_status` (`status`),
  KEY `idx_wsm_orders_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Lignes de commande (prix figés : une facture ne bouge plus) -------------
CREATE TABLE IF NOT EXISTS `wsm_order_items` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED NOT NULL,
  `product_id` VARCHAR(48)  NOT NULL,
  `name`       VARCHAR(200) NOT NULL,
  `sku`        VARCHAR(60)  NOT NULL DEFAULT '',
  `ean`        VARCHAR(14)  NOT NULL DEFAULT '',
  `qty`        INT NOT NULL DEFAULT 1,
  `unit_net`   INT NOT NULL DEFAULT 0,
  `unit_gross` INT NOT NULL DEFAULT 0,
  `vat_rate`   DECIMAL(4,2) NOT NULL DEFAULT 0.23,
  `line_net`   INT NOT NULL DEFAULT 0,
  `line_vat`   INT NOT NULL DEFAULT 0,
  `line_gross` INT NOT NULL DEFAULT 0,
  `weight_g`   INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_order_items_order` (`order_id`),
  CONSTRAINT `fk_wsm_order_items_order`
    FOREIGN KEY (`order_id`) REFERENCES `wsm_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Paiements (Stripe Connect) --------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_payments` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     INT UNSIGNED NOT NULL,
  `provider`     VARCHAR(24)  NOT NULL DEFAULT 'stripe',
  `tr_id`        VARCHAR(64)  NOT NULL DEFAULT '',
  `tr_title`     VARCHAR(64)  NOT NULL DEFAULT '',
  `amount`       INT NOT NULL DEFAULT 0,
  `currency`     CHAR(3)      NOT NULL DEFAULT 'PLN',
  `status`       VARCHAR(24)  NOT NULL DEFAULT 'oczekuje',
  `redirect_url` VARCHAR(500) NOT NULL DEFAULT '',
  `app_fee`      INT NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_payments_order` (`order_id`),
  CONSTRAINT `fk_wsm_payments_order`
    FOREIGN KEY (`order_id`) REFERENCES `wsm_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Notifications de paiement : event_key UNIQUE = idempotence -------------
-- Stripe réémet son webhook tant qu'il ne reçoit pas 2xx. Sans cette clé unique
-- une commande serait encaissée deux fois.
CREATE TABLE IF NOT EXISTS `wsm_payment_events` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     INT UNSIGNED NULL DEFAULT NULL,
  `provider`     VARCHAR(24)  NOT NULL DEFAULT 'stripe',
  `event_key`    VARCHAR(160) NOT NULL,
  `status`       VARCHAR(24)  NOT NULL DEFAULT '',
  `amount`       INT NOT NULL DEFAULT 0,
  `signature_ok` TINYINT(1)   NOT NULL DEFAULT 0,
  `raw`          TEXT,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_payment_events_key` (`event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Expéditions InPost ShipX ----------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_shipments` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`        INT UNSIGNED NOT NULL,
  `carrier`         VARCHAR(24)  NOT NULL DEFAULT 'inpost',
  `service`         VARCHAR(24)  NOT NULL DEFAULT 'inpost_locker',
  `target_point`    VARCHAR(20)  NOT NULL DEFAULT '',
  `parcel_template` CHAR(1)      NOT NULL DEFAULT '',
  `weight_g`        INT NOT NULL DEFAULT 0,
  `shipment_id`     VARCHAR(64)  NOT NULL DEFAULT '',
  `tracking_number` VARCHAR(64)  NOT NULL DEFAULT '',
  `label_url`       VARCHAR(500) NOT NULL DEFAULT '',
  `status`          VARCHAR(32)  NOT NULL DEFAULT 'do_utworzenia',
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_shipments_order` (`order_id`),
  CONSTRAINT `fk_wsm_shipments_order`
    FOREIGN KEY (`order_id`) REFERENCES `wsm_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Journal d'une commande -------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_order_events` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED NOT NULL,
  `event`      VARCHAR(48)  NOT NULL,
  `detail`     VARCHAR(255) NOT NULL DEFAULT '',
  `actor`      VARCHAR(120) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_order_events_order` (`order_id`),
  CONSTRAINT `fk_wsm_order_events_order`
    FOREIGN KEY (`order_id`) REFERENCES `wsm_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Textes de la boutique (pl / uk / en) — aucun libellé en dur -----------
CREATE TABLE IF NOT EXISTS `wsm_shop_i18n` (
  `lang` VARCHAR(5)  NOT NULL,
  `k`    VARCHAR(80) NOT NULL,
  `v`    TEXT        NOT NULL,
  PRIMARY KEY (`lang`, `k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Contrôles VIES (TVA intracommunautaire) --------------------------------
-- Un contrôle par ligne, JAMAIS écrasé : c'est l'historique qui prouve, en cas
-- de contrôle fiscal, qu'on a vérifié tel numéro à telle date. Le numéro de
-- consultation délivré par VIES est la pièce opposable.
-- --- Recherche de commande sans compte ---------------------------------------
-- Un plafond, et rien d'autre. Le numéro de commande s'écrit MS-AAMMJJ-0001 :
-- une date et un compteur, donc devinable. C'est le COUPLE numéro + e-mail qui
-- ouvre la page, et cette table empêche d'en essayer mille.
--
-- Elle ne va PAS dans `wsm_audit` : ce journal-là ne montre que les 150
-- derniers gestes de la console, et une rafale de tentatives publiques en
-- chasserait « qui a changé ce prix » — sa seule raison d'être.
CREATE TABLE IF NOT EXISTS `wsm_order_lookups` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  `code`       VARCHAR(40)  NOT NULL DEFAULT '',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_lookups_ip` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_vies_checks` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vat_eu`       VARCHAR(20)  NOT NULL,
  `country`      CHAR(2)      NOT NULL DEFAULT '',
  `number`       VARCHAR(20)  NOT NULL DEFAULT '',
  `status`       VARCHAR(16)  NOT NULL DEFAULT '',   -- valid | invalid | unavailable | skipped
  `reason`       VARCHAR(160) NOT NULL DEFAULT '',
  `name`         VARCHAR(250) NOT NULL DEFAULT '',
  `address`      VARCHAR(500) NOT NULL DEFAULT '',
  `consultation` VARCHAR(64)  NOT NULL DEFAULT '',
  `source`       VARCHAR(8)   NOT NULL DEFAULT 'vies',
  `raw`          TEXT,
  `checked_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_vies_vat` (`vat_eu`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Pays servis ------------------------------------------------------------
-- Qui peut commander, et à quel régime de TVA. `is_eu` n'est pas décoratif :
-- c'est lui qui autorise l'autoliquidation (0 %) pour un acheteur professionnel
-- d'un autre État membre. La Pologne est le marché intérieur : jamais 0 %.
CREATE TABLE IF NOT EXISTS `wsm_countries` (
  `code`       CHAR(2)      NOT NULL,
  `name_pl`    VARCHAR(80)  NOT NULL,
  `name_uk`    VARCHAR(80)  NOT NULL DEFAULT '',
  `name_en`    VARCHAR(80)  NOT NULL DEFAULT '',
  `is_eu`      TINYINT(1)   NOT NULL DEFAULT 1,
  `active`     TINYINT(1)   NOT NULL DEFAULT 0,   -- ouvert à la commande
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Rabaty ilościowe : remise en % selon le POIDS total du panier ----------
-- Le kilogramme baisse avec le volume. Les paliers sont de la donnée, pas du
-- code : le back-office les règle sans redéploiement.
CREATE TABLE IF NOT EXISTS `wsm_discount_tiers` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `min_weight_g` INT NOT NULL DEFAULT 0,          -- à partir de ce poids
  `percent`      DECIMAL(5,2) NOT NULL DEFAULT 0, -- remise appliquée aux produits
  `label`        VARCHAR(80)  NOT NULL DEFAULT '',
  `active`       TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_discount_weight` (`min_weight_g`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Poczta : la messagerie du back-office ----------------------------------
-- Le modèle est une donnée : sujet et corps s'éditent en console, par langue,
-- et `event` attache le modèle à un fait (commande, hors stock, paiement,
-- expédition) pour que la réponse parte sans que personne y pense.
CREATE TABLE IF NOT EXISTS `wsm_mail_templates` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`    VARCHAR(60)  NOT NULL,
  `lang`    VARCHAR(5)   NOT NULL DEFAULT 'pl',
  `name`    VARCHAR(120) NOT NULL DEFAULT '',
  `subject` VARCHAR(250) NOT NULL DEFAULT '',
  `body`    TEXT         NOT NULL,
  `event`   VARCHAR(40)  NOT NULL DEFAULT '',
  `active`  TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_mail_templates` (`code`, `lang`),
  KEY `idx_wsm_mail_templates_event` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chaque message est écrit AVANT de partir. `event_key` est UNIQUE : c'est la
-- base qui garantit qu'une réponse automatique ne part qu'une fois, même si
-- deux notifications arrivent en même temps.
CREATE TABLE IF NOT EXISTS `wsm_messages` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`      INT UNSIGNED NULL DEFAULT NULL,
  `email`         VARCHAR(200) NOT NULL DEFAULT '',
  `direction`     VARCHAR(10)  NOT NULL DEFAULT 'wyjscie',
  `subject`       VARCHAR(250) NOT NULL DEFAULT '',
  `body`          MEDIUMTEXT   NOT NULL,
  `html`          MEDIUMTEXT   NULL,
  `unsub_url`     VARCHAR(500) NOT NULL DEFAULT '',
  `template_code` VARCHAR(60)  NOT NULL DEFAULT '',
  `event_key`     VARCHAR(100) NULL DEFAULT NULL,
  `status`        VARCHAR(12)  NOT NULL DEFAULT 'kolejka',
  `error`         VARCHAR(250) NOT NULL DEFAULT '',
  `actor`         VARCHAR(120) NOT NULL DEFAULT '',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`       DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_messages_event` (`event_key`),
  KEY `idx_wsm_messages_order` (`order_id`, `id`),
  CONSTRAINT `fk_wsm_messages_order`
    FOREIGN KEY (`order_id`) REFERENCES `wsm_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Réglages d'intégration saisis en console -------------------------------
-- tpay, InPost, compte d'envoi. Le fichier serveur reste prioritaire ; cette
-- table ne remplit que ce qu'il laisse vide (voir settings.php).
CREATE TABLE IF NOT EXISTS `wsm_settings` (
  `cle`        VARCHAR(60) NOT NULL,
  `val`        TEXT        NOT NULL,
  `secret`     TINYINT(1)  NOT NULL DEFAULT 0,
  `updated_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Faktury i e-paragony ---------------------------------------------------
-- Le document est AUTONOME : vendeur, acheteur et lignes y sont recopiés, pas
-- lus par jointure. Une facture doit se relire à l'identique dans dix ans,
-- même si le produit a changé de nom et le siège d'adresse.
--
-- Deux index UNIQUE portent la numérotation : `number` interdit le doublon,
-- et (kind_group, series, seq) interdit deux fois le même rang dans la même
-- série. C'est la base qui tranche entre deux émissions simultanées.
CREATE TABLE IF NOT EXISTS `wsm_invoices` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`       INT UNSIGNED NULL DEFAULT NULL,
  `kind`           VARCHAR(10)  NOT NULL DEFAULT 'faktura',   -- faktura | korekta | paragon
  `kind_group`     VARCHAR(10)  NOT NULL DEFAULT 'faktura',   -- suite de numérotation
  `corrects_id`    INT UNSIGNED NULL DEFAULT NULL,
  `number`         VARCHAR(40)  NOT NULL,
  `series`         VARCHAR(10)  NOT NULL DEFAULT '',
  `seq`            INT UNSIGNED NOT NULL DEFAULT 0,
  `issued_at`      DATE NOT NULL,
  `sold_at`        DATE NOT NULL,
  `due_at`         DATE NOT NULL,
  `place`          VARCHAR(80)  NOT NULL DEFAULT '',
  `seller_name`    VARCHAR(200) NOT NULL DEFAULT '',
  `seller_nip`     VARCHAR(20)  NOT NULL DEFAULT '',
  `seller_address` VARCHAR(250) NOT NULL DEFAULT '',
  `iban`           VARCHAR(40)  NOT NULL DEFAULT '',
  `bank`           VARCHAR(120) NOT NULL DEFAULT '',
  `buyer_name`     VARCHAR(200) NOT NULL DEFAULT '',
  `buyer_nip`      VARCHAR(20)  NOT NULL DEFAULT '',
  `buyer_vat_eu`   VARCHAR(20)  NOT NULL DEFAULT '',
  `buyer_address`  VARCHAR(250) NOT NULL DEFAULT '',
  `buyer_email`    VARCHAR(200) NOT NULL DEFAULT '',
  `currency`       CHAR(3)      NOT NULL DEFAULT 'PLN',
  `total_net`      INT NOT NULL DEFAULT 0,
  `total_vat`      INT NOT NULL DEFAULT 0,
  `total_gross`    INT NOT NULL DEFAULT 0,
  `reverse_charge` TINYINT(1)   NOT NULL DEFAULT 0,
  `paid`           TINYINT(1)   NOT NULL DEFAULT 0,
  `note`           VARCHAR(250) NOT NULL DEFAULT '',
  `sent_at`        DATETIME NULL DEFAULT NULL,
  `ksef_number`    VARCHAR(64)  NOT NULL DEFAULT '',
  `ksef_status`    VARCHAR(20)  NOT NULL DEFAULT '',
  `ksef_at`        DATETIME NULL DEFAULT NULL,
  -- La caisse fiscale (fiskal.php) : le reçu Fakturownia et son e-paragon.
  `fiskal_status`  VARCHAR(16)  NOT NULL DEFAULT '',
  `fiskal_forma`   VARCHAR(8)   NOT NULL DEFAULT '',
  `fiskal_id`      VARCHAR(32)  NOT NULL DEFAULT '',
  `fiskal_url`     VARCHAR(250) NOT NULL DEFAULT '',
  `fiskal_note`    VARCHAR(250) NOT NULL DEFAULT '',
  `fiskal_proby`   INT NOT NULL DEFAULT 0,
  `fiskal_at`      DATETIME NULL DEFAULT NULL,
  `created_by`     VARCHAR(120) NOT NULL DEFAULT '',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_invoices_number` (`number`),
  UNIQUE KEY `uq_wsm_invoices_seq` (`kind_group`, `series`, `seq`),
  KEY `idx_wsm_invoices_order` (`order_id`),
  CONSTRAINT `fk_wsm_invoices_order`
    FOREIGN KEY (`order_id`) REFERENCES `wsm_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_invoice_items` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT UNSIGNED NOT NULL,
  `name`       VARCHAR(250) NOT NULL DEFAULT '',
  `sku`        VARCHAR(60)  NOT NULL DEFAULT '',
  `qty`        INT NOT NULL DEFAULT 1,
  `unit_net`   INT NOT NULL DEFAULT 0,
  `unit_gross` INT NOT NULL DEFAULT 0,
  `vat_rate`   DECIMAL(4,2) NOT NULL DEFAULT 0.23,
  `line_net`   INT NOT NULL DEFAULT 0,
  `line_vat`   INT NOT NULL DEFAULT 0,
  `line_gross` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_invoice_items` (`invoice_id`),
  CONSTRAINT `fk_wsm_invoice_items`
    FOREIGN KEY (`invoice_id`) REFERENCES `wsm_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Ruchy magazynowe -------------------------------------------------------
-- wsm_products.stock reste la quantité qui décide de la vente ; cette table en
-- est l'histoire. Chaque ligne dit ce qui a bougé, de combien, pourquoi, sur
-- quel document et par qui — c'est ce qui permet d'expliquer un écart plutôt
-- que de le constater.
CREATE TABLE IF NOT EXISTS `wsm_stock_moves` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`  VARCHAR(60)  NOT NULL,
  `delta`       INT          NOT NULL DEFAULT 0,    -- + entrée, − sortie
  `kind`        VARCHAR(12)  NOT NULL DEFAULT 'korekta',
  `stock_after` INT          NOT NULL DEFAULT 0,
  `reason`      VARCHAR(120) NOT NULL DEFAULT '',
  `note`        VARCHAR(250) NOT NULL DEFAULT '',
  `doc`         VARCHAR(40)  NOT NULL DEFAULT '',   -- numéro de commande ou de facture
  `supplier`    VARCHAR(120) NOT NULL DEFAULT '',
  `unit_cost`   INT          NOT NULL DEFAULT 0,    -- prix d'achat unitaire, en grosze
  `actor`       VARCHAR(120) NOT NULL DEFAULT '',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_stock_moves` (`product_id`, `id`),
  KEY `idx_wsm_stock_moves_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Dokumenty magazynowe ---------------------------------------------------
-- Une entrée n'est pas un ajustement : c'est une livraison, avec un
-- fournisseur, une facture d'achat et plusieurs articles. Une sortie non plus :
-- c'est un bon de livraison qui accompagne le colis. Les mouvements
-- (wsm_stock_moves) restent la vérité comptable ; ce document les regroupe et
-- leur donne un numéro qu'on peut citer au téléphone.
CREATE TABLE IF NOT EXISTS `wsm_stock_docs` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`       VARCHAR(4)   NOT NULL DEFAULT 'PZ',   -- PZ przyjęcie · WZ wydanie
  `number`     VARCHAR(40)  NOT NULL,
  `series`     VARCHAR(10)  NOT NULL DEFAULT '',
  `seq`        INT UNSIGNED NOT NULL DEFAULT 0,
  `order_id`   INT UNSIGNED NULL DEFAULT NULL,
  `partner`    VARCHAR(160) NOT NULL DEFAULT '',     -- fournisseur ou destinataire
  `ref`        VARCHAR(60)  NOT NULL DEFAULT '',     -- n° de facture d'achat / de commande
  `issued_at`  DATE         NOT NULL,
  `note`       VARCHAR(250) NOT NULL DEFAULT '',
  `units`      INT          NOT NULL DEFAULT 0,
  `value`      INT          NOT NULL DEFAULT 0,      -- en grosze
  `actor`      VARCHAR(120) NOT NULL DEFAULT '',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_stock_docs_number` (`number`),
  KEY `idx_wsm_stock_docs_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Marki ------------------------------------------------------------------
--  Une marque est une entité à part entière, pas une chaîne recopiée sur
--  chaque produit : le logo, l'adresse du site et l'orthographe du nom se
--  corrigent une fois pour toutes. Le produit ne porte qu'une référence.
CREATE TABLE IF NOT EXISTS `wsm_brands` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120) NOT NULL,
  `slug`       VARCHAR(120) NOT NULL,
  `logo_url`   VARCHAR(255) NOT NULL DEFAULT '',
  `site_url`   VARCHAR(255) NOT NULL DEFAULT '',
  `note`       VARCHAR(255) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_brands_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Plateforme : ce que la boutique doit à son propriétaire.
--
--  wsm_platform_terms est en AJOUT SEUL. Modifier le contrat écrit une ligne
--  valable à partir d'un mois ; elle n'écrase jamais la précédente. On peut
--  donc relire deux ans plus tard à quelles conditions un décompte a été fait.
--
--  wsm_platform_periods FIGE tout : volume, taux, loyer, TVA. Changer le taux
--  en mars ne réécrit pas la note de février — même règle que les factures.
--  L'index UNIQUE sur ym est le garde-fou : un mois ne se facture qu'une fois.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_platform_terms` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rent_net`   INT NOT NULL DEFAULT 0,                    -- czynsz w groszach, netto
  `rate`       DECIMAL(6,4) NOT NULL DEFAULT 0.1500,      -- prowizja, np. 0.1500 = 15 %
  `basis`      VARCHAR(16) NOT NULL DEFAULT 'brutto',     -- brutto | towar
  `vat_rate`   DECIMAL(5,4) NOT NULL DEFAULT 0.2300,
  `from_ym`    CHAR(7) NOT NULL,                          -- obowiązuje od YYYY-MM
  `note`       VARCHAR(255) NOT NULL DEFAULT '',
  `created_by` VARCHAR(120) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_wsm_platform_terms_from` (`from_ym`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_platform_periods` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ym`             CHAR(7) NOT NULL,
  `status`         VARCHAR(16) NOT NULL DEFAULT 'szkic',  -- szkic | wystawione | oplacone
  `gross_volume`   BIGINT NOT NULL DEFAULT 0,
  `goods_gross`    BIGINT NOT NULL DEFAULT 0,
  `shipping_gross` BIGINT NOT NULL DEFAULT 0,
  `orders_count`   INT NOT NULL DEFAULT 0,
  `basis`          VARCHAR(16) NOT NULL DEFAULT 'brutto',
  `rate`           DECIMAL(6,4) NOT NULL DEFAULT 0.1500,
  `base_amount`    BIGINT NOT NULL DEFAULT 0,
  `commission_net` BIGINT NOT NULL DEFAULT 0,
  `rent_net`       BIGINT NOT NULL DEFAULT 0,
  `total_net`      BIGINT NOT NULL DEFAULT 0,
  `vat_rate`       DECIMAL(5,4) NOT NULL DEFAULT 0.2300,
  `total_vat`      BIGINT NOT NULL DEFAULT 0,
  `total_gross`    BIGINT NOT NULL DEFAULT 0,
  `issued_at`      DATETIME NULL,
  `issued_by`      VARCHAR(120) NOT NULL DEFAULT '',
  `paid_at`        DATETIME NULL,
  `note`           VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_platform_periods_ym` (`ym`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Langues et historique du contenu traduit.
--
--  wsm_langs porte UNE décision : cette langue est-elle servie au public.
--  Avant, la liste publique se déduisait de « SELECT DISTINCT lang » : une
--  seule clé traduite en allemand faisait apparaître un drapeau DE menant à
--  une boutique polonaise à 99 %. La publication est donc explicite.
--
--  wsm_i18n_history garde avant → après, qui et quand. Un texte public qui
--  change sans auteur ne se corrige qu'en relisant tout le site.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_langs` (
  `code`       VARCHAR(5) NOT NULL,
  `published`  TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_i18n_history` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tbl`        VARCHAR(40) NOT NULL,
  `lang`       VARCHAR(5) NOT NULL,
  `k`          VARCHAR(120) NOT NULL,
  `old_v`      TEXT,
  `new_v`      TEXT,
  `origin`     VARCHAR(10) NOT NULL DEFAULT 'human',  -- human | auto | revert
  `actor`      VARCHAR(120) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_wsm_i18n_history_lookup` (`tbl`, `lang`, `k`),
  KEY `ix_wsm_i18n_history_time` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Traductions du courrier (§11).
--
--  L'ORIGINAL NE BOUGE PAS : wsm_messages garde ce que le client a écrit,
--  c'est la pièce. La traduction vit ici, à côté, et se retrouve à la
--  réouverture sans rappeler l'API — sinon relire trois fois un fil de dix
--  messages coûterait trente traductions.
--
--  L'UNIQUE (message_id, lang) est le garde-fou : deux clics simultanés sur
--  « traduire » ne produisent pas deux appels facturés.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_message_tr` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` INT UNSIGNED NOT NULL,
  `lang`       VARCHAR(5) NOT NULL,      -- langue de la traduction
  `src_lang`   VARCHAR(5) NOT NULL DEFAULT '',  -- langue détectée à la source
  `subject`    VARCHAR(250) NOT NULL DEFAULT '',
  `body`       MEDIUMTEXT,
  `actor`      VARCHAR(120) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_message_tr` (`message_id`, `lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Notes sur les clients (§6).
--
--  La clé est l'ADRESSE E-MAIL et non un identifiant : le client de la
--  boutique n'a pas de fiche, il a des commandes. Une note doit pouvoir
--  exister avant qu'on ouvre une fiche B2B, sinon on ne note jamais rien.
--
--  Signée et datée : « client difficile » sans auteur est une rumeur.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_client_notes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(190) NOT NULL,
  `note`       TEXT,
  `actor`      VARCHAR(120) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_wsm_client_notes_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Utilisations des bons de réduction.
--
--  L'UNICITÉ (voucher_id, order_id) EST LA RÈGLE MÉTIER : un webhook rejoué
--  ou un double clic ne peuvent pas décompter deux fois la même commande.
--
--  Le montant est GELÉ ici. Le bon peut être modifié ou retiré demain ; ce
--  que cette commande-là a réellement obtenu ne doit plus jamais bouger,
--  exactement comme une facture ou un mouvement de stock.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_voucher_uses` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `voucher_id` INT UNSIGNED NOT NULL,
  `order_id`   INT UNSIGNED NOT NULL,
  `email`      VARCHAR(190) NOT NULL DEFAULT '',
  `amount`     INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_voucher_uses` (`voucher_id`, `order_id`),
  KEY `ix_wsm_voucher_uses_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Abonnements — commandes récurrentes.
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
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_subscriptions` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`           VARCHAR(190) NOT NULL,
  `first_name`      VARCHAR(120) NOT NULL DEFAULT '',
  `last_name`       VARCHAR(120) NOT NULL DEFAULT '',
  `phone`           VARCHAR(40)  NOT NULL DEFAULT '',
  `company`         VARCHAR(190) NOT NULL DEFAULT '',
  `nip`             VARCHAR(20)  NOT NULL DEFAULT '',
  `lang`            VARCHAR(5)   NOT NULL DEFAULT 'pl',
  `rytm`            VARCHAR(24)  NOT NULL DEFAULT 'co_miesiac',
  `statut`          VARCHAR(16)  NOT NULL DEFAULT 'aktywny',
  `next_at`         DATE         NOT NULL,
  `last_run_at`     DATETIME     NULL DEFAULT NULL,
  `runs`            INT          NOT NULL DEFAULT 0,
  `unpaid_streak`   INT          NOT NULL DEFAULT 0,
  `delivery_method` VARCHAR(40)  NOT NULL DEFAULT 'inpost_locker',
  `inpost_point`    VARCHAR(40)  NOT NULL DEFAULT '',
  `ship_street`     VARCHAR(190) NOT NULL DEFAULT '',
  `ship_building`   VARCHAR(40)  NOT NULL DEFAULT '',
  `ship_postcode`   VARCHAR(20)  NOT NULL DEFAULT '',
  `ship_city`       VARCHAR(120) NOT NULL DEFAULT '',
  `ship_country`    VARCHAR(2)   NOT NULL DEFAULT 'PL',
  `token`           VARCHAR(64)  NOT NULL,
  `source_order_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `note`            VARCHAR(190) NOT NULL DEFAULT '',
  `created_at`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_wsm_subs_next` (`statut`, `next_at`),
  KEY `ix_wsm_subs_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_subscription_items` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_id` INT UNSIGNED NOT NULL,
  `product_id`      VARCHAR(64)  NOT NULL,
  `qty`             INT          NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `ix_wsm_sub_items` (`subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Reclamations et retractations.
--
--  LE MONTANT PAYE EST FIGE ICI (paid_gross) : c'est la borne du
--  remboursement. On ne rend jamais plus que ce qu'on a encaisse.
--  Une demande ne se SUPPRIME pas : elle se clot, avec sa raison.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_claims` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `numer`        VARCHAR(24)  NOT NULL,
  `order_id`     INT UNSIGNED NOT NULL,
  `order_code`   VARCHAR(40)  NOT NULL DEFAULT '',
  `email`        VARCHAR(190) NOT NULL DEFAULT '',
  `type`         VARCHAR(20)  NOT NULL DEFAULT 'reklamacja',
  `statut`       VARCHAR(20)  NOT NULL DEFAULT 'nowa',
  `raison`       TEXT,
  `decision`     TEXT,
  `paid_gross`   INT NOT NULL DEFAULT 0,
  `refund_gross` INT NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL,
  `resolved_at`  DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_claims_numer` (`numer`),
  KEY `ix_wsm_claims_order` (`order_id`),
  KEY `ix_wsm_claims_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Liens directs traces. Un lien partage doit pouvoir DIRE ce qu'il a
--  rapporte, sinon on reconduit une campagne sans savoir si elle a vendu.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_links` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(40)  NOT NULL,
  `nom`        VARCHAR(190) NOT NULL DEFAULT '',
  `cible`      VARCHAR(190) NOT NULL DEFAULT '',
  `produkt`    VARCHAR(64)  NOT NULL DEFAULT '',
  `kod`        VARCHAR(60)  NOT NULL DEFAULT '',
  `klikniec`   INT NOT NULL DEFAULT 0,
  `active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_links_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Campagnes d'e-mails. RIEN NE PART D'ICI VERS UN SERVEUR SMTP : l'envoi met
--  les messages en file dans wsm_messages, et le travailleur de fond les
--  ecoule. Cent messages d'un coup coutent la reputation du domaine — et avec
--  elle, les confirmations de commande.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_campaigns` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nom`        VARCHAR(190) NOT NULL DEFAULT '',
  `segment`    VARCHAR(24)  NOT NULL DEFAULT 'klienci',
  `sujet`      VARCHAR(250) NOT NULL DEFAULT '',
  `corps`      TEXT,
  `statut`     VARCHAR(20)  NOT NULL DEFAULT 'przygotowana',
  `wyslane`    INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `sent_at`    DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  L'ENREGISTREUR DE PAGES. Deux tables minuscules, et ce qu'elles NE
--  contiennent pas compte autant que ce qu'elles contiennent.
--
--  PAS DE QUERY STRING. « ?zamowienie=MS-2026-0412 », « ?szukaj=Kowalski » :
--  l'adresse d'un ecran de back-office porte des numeros de commande et des
--  noms de clients. On enregistre l'ECRAN, jamais l'adresse complete — sinon
--  un journal d'ergonomie devient un fichier de donnees personnelles.
--
--  PAS DE « QUI ». On note le ROLE, pas la personne. La question posee est
--  « ou passe le temps de l'equipe », pas « qu'a fait Anna a 14h ». Le role
--  repond a la premiere, et rend la seconde impossible a poser. Les
--  ecritures, elles, restent tracees nominativement dans wsm_audit_log :
--  c'est leur role a elles, et ce n'est pas le meme sujet.
--
--  BORNEES PAR CONSTRUCTION. On agrege a l'ecriture au lieu d'empiler une
--  ligne par vue : wsm_page_views plafonne a (ecrans x jours x roles) et
--  wsm_page_paths a (ecrans x ecrans) — quelques centaines de lignes. Pas de
--  purge a programmer, donc pas de purge a oublier.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_page_views` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ekran`   VARCHAR(64) NOT NULL,
  `dzien`   DATE        NOT NULL,
  `rola`    VARCHAR(32) NOT NULL DEFAULT '',
  `n`       INT UNSIGNED NOT NULL DEFAULT 0,
  `ms_sum`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `ms_max`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_page_views` (`ekran`, `dzien`, `rola`),
  KEY `ix_wsm_page_views_dzien` (`dzien`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ce qui suit quoi. C'est la table qui dit dans quel ORDRE le travail se
-- fait — donc ce qu'il faudrait mettre a cote de quoi dans le rail. Une
-- suite d'ecrans, rien d'autre : ni horodatage, ni session, ni personne.
CREATE TABLE IF NOT EXISTS `wsm_page_paths` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skad`   VARCHAR(64) NOT NULL,
  `dokad`  VARCHAR(64) NOT NULL,
  `n`      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_page_paths` (`skad`, `dokad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  LES PROFILS REDEFINIS EN CONSOLE (voir roles.php).
--
--  Les profils vivent dans le CODE (auth.php, wsm_roles_base). Ces deux
--  tables ne les remplacent pas : elles posent une surcouche par-dessus, pour
--  les profils qu'on a voulu changer sans attendre un deploiement. Tant
--  qu'elles sont vides — le cas normal — rien ne change.
--
--  DEUX PROFILS N'Y ENTRENT JAMAIS : « Administrator » et « Superadmin ».
--  Ce sont eux qui ouvrent les comptes ; s'ils etaient modifiables, une case
--  decochee fermerait la console a tout le monde, y compris a celui qui vient
--  de la decocher. La regle est appliquee a l'ECRITURE et relue a la LECTURE,
--  pour qu'une ligne posee a la main avec un client SQL ne la contourne pas.
--
--  « superadmin.php » n'y entre jamais non plus : un profil qui pourrait
--  l'accorder permettrait a un Administrator de se fabriquer l'acces a sa
--  propre facture de plateforme.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_role_profiles` (
  -- 32 et pas 40 (la largeur de wsm_users.role) : l'enregistreur range le
  -- role dans wsm_page_views.rola en VARCHAR(32) et tronque au-dela. Un nom
  -- plus long existerait sous deux formes et le rapprochement « de droit /
  -- de fait » ne rapprocherait plus rien, sans rien signaler.
  `rola` VARCHAR(32)  NOT NULL,
  `opis` VARCHAR(160) NOT NULL DEFAULT '',
  `maj`  DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`rola`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_role_screens` (
  `rola`  VARCHAR(32) NOT NULL,
  `ekran` VARCHAR(64) NOT NULL,
  -- 'w' = ouvrir et modifier · 'r' = ouvrir en lecture seule. Un ecran ABSENT
  -- est ferme : on enumere ce qu'on ouvre, jamais ce qu'on ferme, sinon
  -- chaque ecran livre demain serait ouvert a tous par defaut.
  `droit` CHAR(1)     NOT NULL DEFAULT 'r',
  PRIMARY KEY (`rola`, `ekran`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Pages et blocs ecrits depuis la console (pages.php). Une « strona » a une
--  adresse ; un « blok » s'insere dans l'accueil. Les textes vivent a part,
--  une ligne par langue, avec repli sur le polonais a la lecture.
--  Le slug est NULL pour un bloc : UNIQUE accepte plusieurs NULL, pas
--  plusieurs chaines vides.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_pages` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`       VARCHAR(80) NULL,
  `kind`       VARCHAR(10) NOT NULL DEFAULT 'strona',
  `placement`  VARCHAR(20) NOT NULL DEFAULT '',
  `published`  TINYINT(1) NOT NULL DEFAULT 0,
  `in_nav`     TINYINT(1) NOT NULL DEFAULT 0,
  `in_footer`  TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 100,
  `image_url`  VARCHAR(255) NOT NULL DEFAULT '',
  `styl`       VARCHAR(60)  NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `updated_by` VARCHAR(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_pages_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_page_i18n` (
  `page_id`   INT UNSIGNED NOT NULL,
  `lang`      VARCHAR(5) NOT NULL,
  `title`     VARCHAR(200) NOT NULL DEFAULT '',
  `lead`      TEXT,
  `body`      MEDIUMTEXT,
  `meta_desc` VARCHAR(300) NOT NULL DEFAULT '',
  `cta_label` VARCHAR(120) NOT NULL DEFAULT '',
  `cta_url`   VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`page_id`, `lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Le Kreator (sections.php) : des sections typees, composees depuis la
--  console, pour l'accueil (page_id = 0), une page (page_id > 0) ou tout le
--  site (page_id = -1, la barre d'annonce). Textes a part, une ligne par
--  langue, avec repli sur le polonais a la lecture ; items en JSON.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_sections` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `page_id`    INT NOT NULL DEFAULT 0,
  `type`       VARCHAR(20) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 100,
  `published`  TINYINT(1) NOT NULL DEFAULT 0,
  `styl`       VARCHAR(80) NOT NULL DEFAULT '',
  `image_url`  VARCHAR(255) NOT NULL DEFAULT '',
  `settings`   VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `updated_by` VARCHAR(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `ix_wsm_sections_page` (`page_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_section_i18n` (
  `section_id` INT UNSIGNED NOT NULL,
  `lang`       VARCHAR(5) NOT NULL,
  `title`      VARCHAR(200) NOT NULL DEFAULT '',
  `lead`       TEXT,
  `body`       MEDIUMTEXT,
  `cta_label`  VARCHAR(120) NOT NULL DEFAULT '',
  `cta_url`    VARCHAR(255) NOT NULL DEFAULT '',
  `items`      MEDIUMTEXT,
  PRIMARY KEY (`section_id`, `lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Les noms des fichiers de la médiathèque : l'adresse (media/<hash>.webp) est
-- stable et citée partout, le nom est pour les gens — modifiable dans Media.
CREATE TABLE IF NOT EXISTS `wsm_media` (
  `url`        VARCHAR(80)  NOT NULL,
  `title`      VARCHAR(120) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `updated_by` VARCHAR(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Les slajdy du haut de la page d'accueil (slajdy.php) : une photo, un lien,
-- une période de mise en ligne facultative (« od 1 do 24 grudnia »), et
-- leurs textes par langue. Les dates sont NULL quand il n'y en a pas — jamais
-- '' : MySQL strict refuse une date vide.
CREATE TABLE IF NOT EXISTS `wsm_slides` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sort_order` INT NOT NULL DEFAULT 100,
  `active`     TINYINT(1) NOT NULL DEFAULT 1,
  `image_url`  VARCHAR(255) NOT NULL DEFAULT '',
  `link`       VARCHAR(255) NOT NULL DEFAULT '',
  `date_from`  DATE NULL DEFAULT NULL,
  `date_to`    DATE NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `updated_by` VARCHAR(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `ix_wsm_slides_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_slide_i18n` (
  `slide_id`  INT UNSIGNED NOT NULL,
  `lang`      VARCHAR(5) NOT NULL,
  `eyebrow`   VARCHAR(120) NOT NULL DEFAULT '',
  `title`     VARCHAR(200) NOT NULL DEFAULT '',
  `tekst`     VARCHAR(400) NOT NULL DEFAULT '',
  `cta_label` VARCHAR(80) NOT NULL DEFAULT '',
  PRIMARY KEY (`slide_id`, `lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  MARKETING (marketing.php, newsletter.php, smsapi.php). Le consentement est
--  une PREUVE : quand, d'où, et le texte exact qu'on a fait cocher. Un refus
--  ne s'efface pas — il survit à toutes les campagnes suivantes.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_subscribers` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`             VARCHAR(200) NOT NULL DEFAULT '',
  `phone`             VARCHAR(20)  NOT NULL DEFAULT '',
  `name`              VARCHAR(120) NOT NULL DEFAULT '',
  `lang`              VARCHAR(5)   NOT NULL DEFAULT 'pl',
  `source`            VARCHAR(20)  NOT NULL DEFAULT '',
  `email_status`      VARCHAR(12)  NOT NULL DEFAULT '',
  `email_zgoda_at`    DATETIME NULL DEFAULT NULL,
  `email_potw_at`     DATETIME NULL DEFAULT NULL,
  `email_wypis_at`    DATETIME NULL DEFAULT NULL,
  `email_zgoda_tekst` VARCHAR(500) NOT NULL DEFAULT '',
  `sms_status`        VARCHAR(12)  NOT NULL DEFAULT '',
  `sms_zgoda_at`      DATETIME NULL DEFAULT NULL,
  `sms_wypis_at`      DATETIME NULL DEFAULT NULL,
  `sms_zgoda_tekst`   VARCHAR(500) NOT NULL DEFAULT '',
  `token`             VARCHAR(40)  NOT NULL DEFAULT '',
  `token_at`          DATETIME NULL DEFAULT NULL,
  `sms_token`         VARCHAR(16)  NOT NULL DEFAULT '',
  `ip`                VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_subscribers_email` (`email`),
  KEY `idx_wsm_subscribers_phone` (`phone`),
  KEY `idx_wsm_subscribers_token` (`token`),
  KEY `idx_wsm_subscribers_sms_token` (`sms_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_mkt_events` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `typ`        VARCHAR(20)  NOT NULL DEFAULT '',
  `ref`        VARCHAR(40)  NOT NULL DEFAULT '',
  `kto`        VARCHAR(200) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_mkt_events_ref` (`ref`, `typ`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_newsletters` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nazwa`       VARCHAR(190) NOT NULL DEFAULT '',
  `temat`       VARCHAR(250) NOT NULL DEFAULT '',
  `preheader`   VARCHAR(250) NOT NULL DEFAULT '',
  `bloki`       MEDIUMTEXT,
  `tlumaczenia` MEDIUMTEXT,
  `segment`     VARCHAR(24)  NOT NULL DEFAULT 'wszyscy',
  `kod`         VARCHAR(40)  NOT NULL DEFAULT '',
  `link_code`   VARCHAR(40)  NOT NULL DEFAULT '',
  `status`      VARCHAR(12)  NOT NULL DEFAULT 'szkic',
  `odbiorcow`   INT NOT NULL DEFAULT 0,
  `created_by`  VARCHAR(120) NOT NULL DEFAULT '',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NULL DEFAULT NULL,
  `sent_at`     DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_sms_campaigns` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nazwa`       VARCHAR(190) NOT NULL DEFAULT '',
  `tresc`       TEXT,
  `tlumaczenia` TEXT,
  `segment`     VARCHAR(24)  NOT NULL DEFAULT 'wszyscy',
  `ascii`       TINYINT(1)   NOT NULL DEFAULT 1,
  `kod`         VARCHAR(40)  NOT NULL DEFAULT '',
  `link_code`   VARCHAR(40)  NOT NULL DEFAULT '',
  `status`      VARCHAR(12)  NOT NULL DEFAULT 'szkic',
  `odbiorcow`   INT NOT NULL DEFAULT 0,
  `czesci`      INT NOT NULL DEFAULT 0,
  `created_by`  VARCHAR(120) NOT NULL DEFAULT '',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NULL DEFAULT NULL,
  `sent_at`     DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_sms` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` INT UNSIGNED NULL DEFAULT NULL,
  `phone`       VARCHAR(20)  NOT NULL DEFAULT '',
  `tresc`       TEXT,
  `czesci`      INT NOT NULL DEFAULT 1,
  `status`      VARCHAR(12)  NOT NULL DEFAULT 'kolejka',
  `raport`      VARCHAR(20)  NOT NULL DEFAULT '',
  `sms_id`      VARCHAR(40)  NOT NULL DEFAULT '',
  `error`       VARCHAR(250) NOT NULL DEFAULT '',
  `event_key`   VARCHAR(120) NULL DEFAULT NULL,
  `test`        TINYINT(1)   NOT NULL DEFAULT 0,
  `actor`       VARCHAR(120) NOT NULL DEFAULT '',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`     DATETIME NULL DEFAULT NULL,
  `raport_at`   DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_sms_event` (`event_key`),
  KEY `idx_wsm_sms_status` (`status`, `id`),
  KEY `idx_wsm_sms_smsid` (`sms_id`),
  KEY `idx_wsm_sms_campaign` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  Factures d'achat importées (zakupy.php) : le PDF ou le XML KSeF du
--  fournisseur, lu ligne par ligne, rapproché des produits du site, puis
--  transformé en bon d'entrée (PZ). Un brouillon (szkic) se relit et se
--  corrige avant d'entrer en stock ; une facture reçue ne se reçoit pas deux fois.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_purchase_invoices` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status`        VARCHAR(12)  NOT NULL DEFAULT 'szkic',     -- szkic · przyjeta · odrzucona
  `source`        VARCHAR(8)   NOT NULL DEFAULT 'pdf',       -- pdf · ksef · reczna
  `supplier_name` VARCHAR(160) NOT NULL DEFAULT '',
  `supplier_nip`  VARCHAR(20)  NOT NULL DEFAULT '',
  `buyer_nip`     VARCHAR(20)  NOT NULL DEFAULT '',
  `number`        VARCHAR(60)  NOT NULL DEFAULT '',
  `issued_at`     DATE NULL DEFAULT NULL,
  `currency`      VARCHAR(3)   NOT NULL DEFAULT 'PLN',
  `net`           INT NOT NULL DEFAULT 0,                    -- en grosze, tel qu'imprimé
  `vat`           INT NOT NULL DEFAULT 0,
  `gross`         INT NOT NULL DEFAULT 0,
  `file`          VARCHAR(255) NOT NULL DEFAULT '',          -- relatif à api/data
  `file_name`     VARCHAR(160) NOT NULL DEFAULT '',
  `file_sha1`     CHAR(40)     NOT NULL DEFAULT '',
  `uwagi`         TEXT,                                      -- contrôles, JSON
  `doc_id`        INT UNSIGNED NULL DEFAULT NULL,            -- le PZ, une fois reçu
  `actor`         VARCHAR(120) NOT NULL DEFAULT '',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `posted_at`     DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_purchase_inv_status` (`status`, `id`),
  KEY `idx_wsm_purchase_inv_number` (`supplier_nip`, `number`),
  KEY `idx_wsm_purchase_inv_doc` (`doc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_purchase_lines` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id`  INT UNSIGNED NOT NULL,
  `lp`          INT NOT NULL DEFAULT 0,
  `name`        VARCHAR(250) NOT NULL DEFAULT '',
  `code`        VARCHAR(60)  NOT NULL DEFAULT '',
  `ean`         VARCHAR(14)  NOT NULL DEFAULT '',
  `qty`         DECIMAL(12,3) NOT NULL DEFAULT 0,             -- quantité de la facture
  `unit`        VARCHAR(16)  NOT NULL DEFAULT '',
  `unit_net`    INT NOT NULL DEFAULT 0,                       -- grosze, par unité de la facture
  `vat_rate`    VARCHAR(6)   NOT NULL DEFAULT '',             -- 23 · 8 · 5 · 0 · zw · np
  `net`         INT NOT NULL DEFAULT 0,
  `vat`         INT NOT NULL DEFAULT 0,
  `gross`       INT NOT NULL DEFAULT 0,
  `product_id`  VARCHAR(48)  NULL DEFAULT NULL,
  `factor`      DECIMAL(14,8) NOT NULL DEFAULT 1,             -- 1 unité facture = factor sztuk (1/300 g tient)
  `stock_qty`   INT NOT NULL DEFAULT 0,
  `dopasowanie` VARCHAR(10)  NOT NULL DEFAULT '',             -- pamiec · ean · sku · nazwa · reczne
  `score`       INT NOT NULL DEFAULT 0,
  `skip`        TINYINT(1)   NOT NULL DEFAULT 0,              -- transport, emballage… : pas un produit
  `move_id`     INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_purchase_lines_inv` (`invoice_id`, `lp`),
  KEY `idx_wsm_purchase_lines_move` (`move_id`),
  KEY `idx_wsm_purchase_lines_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ce que l'on a appris : chez CE fournisseur, CETTE ligne (EAN, code ou nom)
-- est CE produit, à CE facteur. La deuxième facture se rapproche toute seule.
CREATE TABLE IF NOT EXISTS `wsm_purchase_map` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_key` VARCHAR(60)  NOT NULL,
  `line_key`     VARCHAR(190) NOT NULL,
  `product_id`   VARCHAR(48)  NOT NULL,
  `factor`       DECIMAL(14,8) NOT NULL DEFAULT 1,
  `uses`         INT NOT NULL DEFAULT 0,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_purchase_map` (`supplier_key`, `line_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Marketing Google / Meta (reklama.php, mediateka.php, social.php, formularze.php)
-- ---------------------------------------------------------------------------
-- L'accord du visiteur au moment de commander. Sans accord marketing : le
-- « non » seul, ni IP ni navigateur.
CREATE TABLE IF NOT EXISTS `wsm_ad_orders` (
  `order_id`     INT UNSIGNED NOT NULL,
  `analityczne`  TINYINT(1)   NOT NULL DEFAULT 0,
  `marketingowe` TINYINT(1)   NOT NULL DEFAULT 0,
  `fbp`          VARCHAR(80)  NOT NULL DEFAULT '',
  `fbc`          VARCHAR(255) NOT NULL DEFAULT '',
  `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
  `ua`           VARCHAR(255) NOT NULL DEFAULT '',
  `page_url`     VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`order_id`),
  KEY `ix_wsm_ad_orders_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La file de la Conversions API : un achat payé = un événement, une fois.
CREATE TABLE IF NOT EXISTS `wsm_capi_events` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED NOT NULL,
  `event_name` VARCHAR(30)  NOT NULL,
  `status`     VARCHAR(12)  NOT NULL DEFAULT 'nowe',
  `attempts`   INT NOT NULL DEFAULT 0,
  `response`   VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `tried_at`   DATETIME NULL,
  `sent_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_capi_events` (`order_id`, `event_name`),
  KEY `ix_wsm_capi_events_status` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La médiathèque classée : ce que montre la photo, pour quelle occasion,
-- quel produit, et son texte alternatif.
CREATE TABLE IF NOT EXISTS `wsm_media_meta` (
  `url`        VARCHAR(80)  NOT NULL,
  `kategoria`  VARCHAR(30)  NOT NULL DEFAULT '',
  `okazja`     VARCHAR(30)  NOT NULL DEFAULT '',
  `product_id` VARCHAR(48)  NOT NULL DEFAULT '',
  `alt`        VARCHAR(250) NOT NULL DEFAULT '',
  `tagi`       VARCHAR(250) NOT NULL DEFAULT '',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`url`),
  KEY `ix_wsm_media_meta_kat` (`kategoria`, `okazja`),
  KEY `ix_wsm_media_meta_prod` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Les posts Facebook et Instagram : un texte, des photos, deux canaux,
-- chacun avec son propre résultat (l'un peut réussir sans l'autre).
CREATE TABLE IF NOT EXISTS `wsm_social_posts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tekst`        TEXT NOT NULL,
  `media`        TEXT NOT NULL,
  `product_id`   VARCHAR(48)  NOT NULL DEFAULT '',
  `link_code`    VARCHAR(40)  NOT NULL DEFAULT '',
  `kanaly`       VARCHAR(20)  NOT NULL DEFAULT 'fb,ig',
  `termin`       DATETIME NULL,
  `status`       VARCHAR(14)  NOT NULL DEFAULT 'szkic',
  `fb_status`    VARCHAR(12)  NOT NULL DEFAULT '',
  `fb_id`        VARCHAR(80)  NOT NULL DEFAULT '',
  `fb_url`       VARCHAR(255) NOT NULL DEFAULT '',
  `fb_error`     VARCHAR(500) NOT NULL DEFAULT '',
  `ig_status`    VARCHAR(12)  NOT NULL DEFAULT '',
  `ig_id`        VARCHAR(80)  NOT NULL DEFAULT '',
  `ig_url`       VARCHAR(255) NOT NULL DEFAULT '',
  `ig_error`     VARCHAR(500) NOT NULL DEFAULT '',
  `created_by`   VARCHAR(120) NOT NULL DEFAULT '',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `claimed_at`   DATETIME NULL,
  `published_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_wsm_social_posts_due` (`status`, `termin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Les formulaires publics (/f/<slug>) et leurs réponses.
CREATE TABLE IF NOT EXISTS `wsm_forms` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`          VARCHAR(60)  NOT NULL,
  `nazwa`         VARCHAR(120) NOT NULL,
  `wstep`         TEXT NOT NULL,
  `pola`          TEXT NOT NULL,
  `podziekowanie` VARCHAR(500) NOT NULL DEFAULT '',
  `powiadom`      VARCHAR(200) NOT NULL DEFAULT '',
  `newsletter`    TINYINT(1)   NOT NULL DEFAULT 1,
  `aktywny`       TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_forms_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_form_entries` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id`     INT UNSIGNED NOT NULL,
  `dane`        MEDIUMTEXT NOT NULL,
  `email`       VARCHAR(200) NOT NULL DEFAULT '',
  `status`      VARCHAR(12)  NOT NULL DEFAULT 'nowe',
  `zgoda_tekst` VARCHAR(600) NOT NULL DEFAULT '',
  `newsletter`  TINYINT(1)   NOT NULL DEFAULT 0,
  `ip`          VARCHAR(45)  NOT NULL DEFAULT '',
  `lang`        VARCHAR(5)   NOT NULL DEFAULT 'pl',
  `notatka`     VARCHAR(500) NOT NULL DEFAULT '',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_wsm_form_entries_form` (`form_id`, `created_at`),
  KEY `ix_wsm_form_entries_ip` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
