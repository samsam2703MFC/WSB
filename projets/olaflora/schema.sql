-- =====================================================================
-- Ola flora — schéma MariaDB 10.6+ (testé sur 10.11)
-- Source : spécification fonctionnelle, §3 « Schemat relacyjny ».
-- Décisions client appliquées : voir SCHEMA.md.
--
-- Idempotent : CREATE TABLE IF NOT EXISTS uniquement, aucun DROP.
-- Les clés étrangères sont déclarées dans chaque CREATE TABLE ; les
-- références circulaires (of_events <-> of_offers, of_brands -> of_media,
-- of_inquiries -> of_mail_messages…) passent grâce à FOREIGN_KEY_CHECKS=0.
--
-- Conventions :
--   * id BIGINT UNSIGNED AUTO_INCREMENT, created_at / updated_at partout ;
--   * tenant_id sur toutes les tables « racines » métier (CASCADE) ; les
--     tables de détail (lignes, étapes, réponses…) héritent du tenant par
--     leur parent (ON DELETE CASCADE) ;
--   * montants en grosze (INT, suffixe _gr) ;
--   * « enums » = VARCHAR en slugs ASCII, valeurs admises en COMMENT.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_polish_ci;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 3.1 Plateforme, utilisateurs, marques
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS of_plans (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                VARCHAR(20)  NOT NULL COMMENT 'pracownia | studio | enterprise',
  name                VARCHAR(60)  NOT NULL,
  tag                 VARCHAR(60)  NULL,
  price_month_gr      INT          NOT NULL,
  yearly_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 15.00,
  max_brands          SMALLINT     NULL COMMENT 'NULL = bez limitu',
  max_shops           SMALLINT     NULL COMMENT 'NULL = bez limitu',
  has_rental_module   TINYINT(1)   NOT NULL DEFAULT 0,
  has_roles           TINYINT(1)   NOT NULL DEFAULT 0,
  has_custom_domain   TINYINT(1)   NOT NULL DEFAULT 0,
  features_json       JSON         NULL,
  sort                SMALLINT     NOT NULL DEFAULT 0,
  active              TINYINT(1)   NOT NULL DEFAULT 1,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_plans_code (code),
  CONSTRAINT ck_of_plans_price CHECK (price_month_gr >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Plany abonamentowe platformy';

CREATE TABLE IF NOT EXISTS of_tenants (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                   VARCHAR(120) NOT NULL,
  domain                 VARCHAR(190) NULL,
  city                   VARCHAR(80)  NULL,
  color_hex              CHAR(7)      NULL,
  owner_email            VARCHAR(190) NOT NULL DEFAULT '',
  plan_code              VARCHAR(20)  NOT NULL,
  billing_cycle          VARCHAR(10)  NOT NULL DEFAULT 'mies' COMMENT 'mies | rok',
  status                 VARCHAR(12)  NOT NULL DEFAULT 'probny' COMMENT 'aktywny | probny | uspiony | zawieszony',
  trial_ends_at          DATE         NULL,
  suspended_at           DATETIME     NULL,
  last_login_at          DATETIME     NULL,
  stripe_customer_id     VARCHAR(64)  NULL,
  stripe_subscription_id VARCHAR(64)  NULL,
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_tenants_domain (domain),
  KEY ix_of_tenants_status (status),
  KEY ix_of_tenants_plan (plan_code),
  CONSTRAINT fk_of_tenants_plan FOREIGN KEY (plan_code) REFERENCES of_plans (code) ON UPDATE CASCADE,
  CONSTRAINT ck_of_tenants_cycle  CHECK (billing_cycle IN ('mies','rok')),
  CONSTRAINT ck_of_tenants_status CHECK (status IN ('aktywny','probny','uspiony','zawieszony'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Kwiaciarnie (konta pracowni)';

CREATE TABLE IF NOT EXISTS of_subscription_charges (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id        BIGINT UNSIGNED NULL COMMENT 'NULL = wypłata na konto platformy',
  kind             VARCHAR(12)  NOT NULL COMMENT 'abonament | wyplata | probny',
  description      VARCHAR(190) NOT NULL,
  amount_gr        INT          NOT NULL,
  charged_at       DATETIME     NOT NULL,
  status           VARCHAR(12)  NOT NULL COMMENT 'oplacone | wyplacone | nieudane | probny',
  period_from      DATE         NULL,
  period_to        DATE         NULL,
  next_charge_at   DATE         NULL,
  stripe_object_id VARCHAR(64)  NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_subch_stripe (stripe_object_id),
  KEY ix_of_subch_tenant_date (tenant_id, charged_at),
  CONSTRAINT fk_of_subch_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Obciążenia i wypłaty Stripe';

CREATE TABLE IF NOT EXISTS of_platform_settings (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  skey       VARCHAR(60) NOT NULL,
  svalue     TEXT        NULL,
  is_secret  TINYINT(1)  NOT NULL DEFAULT 0,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_platform_settings_key (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Ustawienia platformy (klucz/wartość)';

CREATE TABLE IF NOT EXISTS of_users (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      BIGINT UNSIGNED NULL COMMENT 'NULL = super admin platformy',
  email          VARCHAR(190) NOT NULL,
  name           VARCHAR(120) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role           VARCHAR(20)  NOT NULL DEFAULT 'owner' COMMENT 'superadmin | owner | manager | staff',
  can_see_margin TINYINT(1)   NOT NULL DEFAULT 1,
  active         TINYINT(1)   NOT NULL DEFAULT 1,
  invited_at     DATETIME     NULL,
  invite_token   CHAR(64)     NULL,
  last_login_at  DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_users_tenant_email (tenant_id, email),
  KEY ix_of_users_email (email),
  CONSTRAINT fk_of_users_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT ck_of_users_role CHECK (role IN ('superadmin','owner','manager','staff'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Użytkownicy (super admin i zespoły pracowni)';

CREATE TABLE IF NOT EXISTS of_impersonation_log (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  superadmin_user_id BIGINT UNSIGNED NOT NULL,
  tenant_id          BIGINT UNSIGNED NOT NULL,
  started_at         DATETIME     NOT NULL,
  ended_at           DATETIME     NULL,
  ip                 VARCHAR(45)  NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_imp_tenant (tenant_id, started_at),
  CONSTRAINT fk_of_imp_user   FOREIGN KEY (superadmin_user_id) REFERENCES of_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_imp_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='„Wejdź jako właściciel”';

-- of_media est déclarée ici (§3.13) car référencée par de nombreuses tables.
CREATE TABLE IF NOT EXISTS of_media (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  kind              VARCHAR(10)  NOT NULL DEFAULT 'photo' COMMENT 'photo | pdf | logo | qr | file',
  path              VARCHAR(255) NOT NULL,
  filename          VARCHAR(190) NOT NULL,
  mime              VARCHAR(60)  NOT NULL,
  bytes             INT          NOT NULL DEFAULT 0,
  width             SMALLINT     NULL,
  height            SMALLINT     NULL,
  alt               VARCHAR(190) NULL,
  gallery_category  VARCHAR(12)  NULL COMMENT 'bukiety | ceremonia | sala | detale | firmy | kwiaty | para',
  display_scale     DECIMAL(3,2) NOT NULL DEFAULT 1.00,
  in_public_gallery TINYINT(1)   NOT NULL DEFAULT 0,
  sort              INT          NOT NULL DEFAULT 0,
  sha1              CHAR(40)     NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_media_path (tenant_id, path),
  KEY ix_of_media_gallery (tenant_id, gallery_category, sort),
  CONSTRAINT fk_of_media_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Biblioteka zdjęć i plików';

CREATE TABLE IF NOT EXISTS of_media_blobs (
  media_id   BIGINT UNSIGNED NOT NULL,
  data       MEDIUMBLOB      NOT NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (media_id),
  CONSTRAINT fk_of_media_blob FOREIGN KEY (media_id) REFERENCES of_media (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Zdjęcia wgrane z CMS (zawartość pliku)';

CREATE TABLE IF NOT EXISTS of_brands (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      BIGINT UNSIGNED NOT NULL,
  slug           VARCHAR(20)  NOT NULL COMMENT 'of, pwl…',
  name           VARCHAR(80)  NOT NULL,
  display_name   VARCHAR(120) NOT NULL,
  color_hex      CHAR(7)      NOT NULL,
  dot_token      VARCHAR(40)  NULL,
  logo_media_id  BIGINT UNSIGNED NULL,
  sender_name    VARCHAR(120) NOT NULL DEFAULT '',
  sender_email   VARCHAR(190) NOT NULL DEFAULT '',
  mail_footer    VARCHAR(255) NOT NULL DEFAULT '',
  mail_signature TEXT         NULL COMMENT 'zmienna {{podpis}}',
  number_prefix  VARCHAR(10)  NOT NULL,
  public_domain  VARCHAR(190) NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_brands_slug (tenant_id, slug),
  CONSTRAINT fk_of_brands_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_brands_logo   FOREIGN KEY (logo_media_id) REFERENCES of_media (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Marki pracowni';

CREATE TABLE IF NOT EXISTS of_number_sequences (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  prefix     VARCHAR(10) NOT NULL COMMENT 'OF, ZAP, PWL, FV',
  year       SMALLINT    NOT NULL,
  last_no    INT         NOT NULL DEFAULT 0,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_numseq (tenant_id, prefix, year),
  CONSTRAINT fk_of_numseq_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Numeracja {prefix}-{year}-{last_no:03d} (SELECT … FOR UPDATE)';

-- ---------------------------------------------------------------------
-- 3.2 Ustawienia pracowni i integracje
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS of_settings (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  brand_id   BIGINT UNSIGNED NULL COMMENT 'NULL = ustawienie całej pracowni',
  brand_key  BIGINT UNSIGNED AS (IFNULL(brand_id, 0)) STORED COMMENT 'pomocnicza: unikalność przy brand_id NULL',
  skey       VARCHAR(60) NOT NULL,
  svalue     TEXT        NULL,
  value_type VARCHAR(10) NOT NULL DEFAULT 'text' COMMENT 'text | int | money_gr | pct | bool | json',
  label      VARCHAR(120) NULL COMMENT 'etykieta w Parametrach',
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_settings_key (tenant_id, brand_key, skey),
  KEY ix_of_settings_brand (brand_id),
  CONSTRAINT fk_of_settings_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_settings_brand  FOREIGN KEY (brand_id) REFERENCES of_brands (id),
  CONSTRAINT ck_of_settings_type CHECK (value_type IN ('text','int','money_gr','pct','bool','json'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Ustawienia pracowni (klucz/wartość)';

CREATE TABLE IF NOT EXISTS of_integrations (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id        BIGINT UNSIGNED NOT NULL,
  kind             VARCHAR(20)    NOT NULL COMMENT 'smtp | sms | google_maps | tpay | imap',
  config_json      JSON           NULL,
  secret_enc       VARBINARY(512) NULL COMMENT 'szyfrowane libsodium',
  status           VARCHAR(12)    NOT NULL DEFAULT 'nieskonfig' COMMENT 'ok | blad | nieskonfig',
  last_test_at     DATETIME       NULL,
  last_test_result VARCHAR(255)   NULL,
  created_at       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME       NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_integrations_kind (tenant_id, kind),
  CONSTRAINT fk_of_integrations_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT ck_of_integrations_status CHECK (status IN ('ok','blad','nieskonfig'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Sekrety i połączenia (SMTP, SMS, Tpay…)';

-- Ajout : dictionnaire des 7 étapes (décision n° 1, modèle §2.3).
-- of_events.stage / of_automation_rules.trigger_stage contiennent le slug.
CREATE TABLE IF NOT EXISTS of_stages (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      BIGINT UNSIGNED NOT NULL,
  slug           VARCHAR(24)  NOT NULL COMMENT 'wartość of_events.stage (§2.3)',
  label          VARCHAR(60)  NOT NULL COMMENT 'etykieta tablicy ofert (§2.3)',
  short_label    VARCHAR(40)  NOT NULL COMMENT 'etykieta krótka jednej osi (decyzja 1)',
  view_all_label VARCHAR(40)  NULL COMMENT 'widok „wszystko” (5)',
  funnel_label   VARCHAR(40)  NULL COMMENT 'lejek (4); NULL = poza lejkiem',
  sort           SMALLINT     NOT NULL,
  is_pipeline    TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = etap poza tablicą (odrzucone)',
  is_terminal    TINYINT(1)   NOT NULL DEFAULT 0,
  occupies_slot  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = sprawa zajmuje slot dnia (od zadatku)',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_stages_slug (tenant_id, slug),
  KEY ix_of_stages_sort (tenant_id, sort),
  CONSTRAINT fk_of_stages_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Etapy sprawy — jedna oś 7 etapów';

-- ---------------------------------------------------------------------
-- 3.3 Klienci, miejsca
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS of_clients (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id              BIGINT UNSIGNED NOT NULL,
  brand_id               BIGINT UNSIGNED NOT NULL,
  kind                   VARCHAR(12)  NOT NULL DEFAULT 'para' COMMENT 'para | firma | zaklad_pogrzebowy | hotel | inny',
  display_name           VARCHAR(160) NOT NULL,
  partner1_name          VARCHAR(80)  NULL,
  partner2_name          VARCHAR(80)  NULL,
  last_name              VARCHAR(80)  NULL,
  email                  VARCHAR(190) NULL,
  phone                  VARCHAR(32)  NULL COMMENT 'E.164',
  address_correspondence VARCHAR(255) NULL,
  default_place          VARCHAR(190) NULL,
  default_when           VARCHAR(120) NULL,
  notes                  TEXT         NULL,
  source                 VARCHAR(20)  NOT NULL DEFAULT 'formularz' COMMENT 'formularz | mail | telefon | polecenie | instagram | reczny',
  gdpr_consent_at        DATETIME     NULL,
  anonymized_at          DATETIME     NULL,
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_clients_email (tenant_id, email),
  KEY ix_of_clients_name (tenant_id, display_name),
  KEY ix_of_clients_brand (brand_id),
  FULLTEXT KEY ft_of_clients (display_name, email),
  CONSTRAINT fk_of_clients_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_clients_brand  FOREIGN KEY (brand_id) REFERENCES of_brands (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Klienci (pary, firmy)';

CREATE TABLE IF NOT EXISTS of_venues (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id           BIGINT UNSIGNED NOT NULL,
  name                VARCHAR(160) NOT NULL,
  address             VARCHAR(255) NULL,
  kind                VARCHAR(12)  NOT NULL DEFAULT 'inne' COMMENT 'kosciol | sala | plener | usc | hotel | inne',
  google_place_id     VARCHAR(128) NULL,
  lat                 DECIMAL(9,6) NULL,
  lng                 DECIMAL(9,6) NULL,
  distance_km         DECIMAL(6,1) NULL COMMENT 'z adresu atelier, w jedną stronę',
  distance_checked_at DATETIME     NULL,
  onsite_contact      VARCHAR(190) NULL,
  remembered          TINYINT(1)   NOT NULL DEFAULT 1,
  use_count           INT          NOT NULL DEFAULT 0,
  notes               TEXT         NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_venues_place (tenant_id, google_place_id),
  KEY ix_of_venues_name (tenant_id, name),
  CONSTRAINT fk_of_venues_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Miejsca: kościoły, sale, plenery, USC';

CREATE TABLE IF NOT EXISTS of_lookup_memory (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    BIGINT UNSIGNED NOT NULL,
  field        VARCHAR(12)  NOT NULL COMMENT 'date | cer | rec | guests',
  label        VARCHAR(190) NOT NULL,
  last_used_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_lookup (tenant_id, field, label),
  CONSTRAINT fk_of_lookup_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Zapamiętane wartości combobox-ów asystenta';

-- ---------------------------------------------------------------------
-- 3.4 Formularz (wizard) i zapytania
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS of_form_questions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      BIGINT UNSIGNED NOT NULL,
  brand_id       BIGINT UNSIGNED NOT NULL,
  qkey           VARCHAR(30)  NOT NULL,
  sort           SMALLINT     NOT NULL DEFAULT 0,
  eyebrow        VARCHAR(60)  NOT NULL,
  question       VARCHAR(190) NOT NULL,
  hint           VARCHAR(255) NULL,
  caption        VARCHAR(255) NULL COMMENT 'Notka na dole ekranu',
  kind           VARCHAR(20)  NOT NULL COMMENT 'text | two_fields | date | choice | multi | choice_photo | colors | number | contact | long_text',
  active         TINYINT(1)   NOT NULL DEFAULT 1,
  required       TINYINT(1)   NOT NULL DEFAULT 0,
  photo_media_id BIGINT UNSIGNED NULL,
  placeholder    VARCHAR(190) NULL,
  max_choices    TINYINT      NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_fq_key (tenant_id, brand_id, qkey),
  KEY ix_of_fq_sort (tenant_id, brand_id, sort),
  CONSTRAINT fk_of_fq_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_fq_brand  FOREIGN KEY (brand_id) REFERENCES of_brands (id),
  CONSTRAINT fk_of_fq_photo  FOREIGN KEY (photo_media_id) REFERENCES of_media (id) ON DELETE SET NULL,
  CONSTRAINT ck_of_fq_kind CHECK (kind IN ('text','two_fields','date','choice','multi','choice_photo','colors','number','contact','long_text'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Pytania formularza (wizard)';

CREATE TABLE IF NOT EXISTS of_form_question_fields (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id BIGINT UNSIGNED NOT NULL,
  fkey        VARCHAR(30)  NOT NULL COMMENT 'ona, on, sala, mail, tel…',
  placeholder VARCHAR(120) NULL,
  input_type  VARCHAR(10)  NOT NULL DEFAULT 'text' COMMENT 'text | email | tel',
  sort        SMALLINT     NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_fqf (question_id, fkey),
  CONSTRAINT fk_of_fqf_question FOREIGN KEY (question_id) REFERENCES of_form_questions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Pola pytań text / two_fields / contact';

CREATE TABLE IF NOT EXISTS of_form_question_options (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id    BIGINT UNSIGNED NOT NULL,
  label          VARCHAR(120) NOT NULL,
  sort           SMALLINT     NOT NULL DEFAULT 0,
  photo_media_id BIGINT UNSIGNED NULL,
  description    VARCHAR(255) NULL,
  active         TINYINT(1)   NOT NULL DEFAULT 1,
  score_tag      VARCHAR(20)  NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_fqo (question_id, label),
  KEY ix_of_fqo_sort (question_id, sort),
  CONSTRAINT fk_of_fqo_question FOREIGN KEY (question_id) REFERENCES of_form_questions (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_fqo_photo    FOREIGN KEY (photo_media_id) REFERENCES of_media (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Opcje odpowiedzi';

CREATE TABLE IF NOT EXISTS of_palettes (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  label         VARCHAR(60) NOT NULL,
  swatches_json JSON        NOT NULL COMMENT '2–5 kolorów hex',
  is_custom     TINYINT(1)  NOT NULL DEFAULT 0,
  visible       TINYINT(1)  NOT NULL DEFAULT 1,
  sort          SMALLINT    NOT NULL DEFAULT 0,
  created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_palettes (tenant_id, label),
  CONSTRAINT fk_of_palettes_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Palety kolorystyki formularza';

CREATE TABLE IF NOT EXISTS of_accent_colors (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  hex        CHAR(7)    NOT NULL,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  sort       SMALLINT   NOT NULL DEFAULT 0,
  created_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME   NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_accents (tenant_id, hex),
  CONSTRAINT fk_of_accents_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Kolory akcentów';

CREATE TABLE IF NOT EXISTS of_color_library (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NULL COMMENT 'NULL = globalna',
  name       VARCHAR(40) NOT NULL,
  hex        CHAR(7)     NOT NULL,
  sort       SMALLINT    NOT NULL DEFAULT 0,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_colorlib (tenant_id, sort),
  CONSTRAINT fk_of_colorlib_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Paleta źródłowa kreatora palet';

CREATE TABLE IF NOT EXISTS of_inquiries (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          BIGINT UNSIGNED NOT NULL,
  brand_id           BIGINT UNSIGNED NOT NULL,
  number             VARCHAR(20)  NOT NULL COMMENT 'ZAP-RRRR-NNN',
  client_id          BIGINT UNSIGNED NULL,
  source             VARCHAR(20)  NOT NULL DEFAULT 'formularz',
  status             VARCHAR(20)  NOT NULL DEFAULT 'nowe' COMMENT 'nowe | oferta_w_robocie | wycena_wyslana | odmowa',
  received_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  event_date         DATE         NULL,
  event_date_approx  TINYINT(1)   NOT NULL DEFAULT 0,
  venue_text         VARCHAR(190) NULL,
  venue_id           BIGINT UNSIGNED NULL,
  ceremony_text      VARCHAR(60)  NULL,
  guests_range       VARCHAR(30)  NULL,
  tables_kind        VARCHAR(30)  NULL,
  head_table         VARCHAR(40)  NULL,
  style              VARCHAR(40)  NULL,
  palette_label      VARCHAR(60)  NULL,
  accents_json       JSON         NULL,
  colors_note        TEXT         NULL,
  budget_range       VARCHAR(30)  NULL,
  extras_json        JSON         NULL,
  email              VARCHAR(190) NULL,
  phone              VARCHAR(32)  NULL,
  estimated_total_gr INT          NULL,
  decline_sent_at    DATETIME     NULL,
  decline_message_id BIGINT UNSIGNED NULL,
  event_id           BIGINT UNSIGNED NULL,
  ip                 VARCHAR(45)  NULL,
  user_agent         VARCHAR(255) NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_inquiries_number (tenant_id, number),
  KEY ix_of_inquiries_status (tenant_id, status, received_at),
  KEY ix_of_inquiries_date (tenant_id, event_date),
  CONSTRAINT fk_of_inq_tenant  FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_inq_brand   FOREIGN KEY (brand_id) REFERENCES of_brands (id),
  CONSTRAINT fk_of_inq_client  FOREIGN KEY (client_id) REFERENCES of_clients (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_inq_venue   FOREIGN KEY (venue_id) REFERENCES of_venues (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_inq_decline FOREIGN KEY (decline_message_id) REFERENCES of_mail_messages (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_inq_event   FOREIGN KEY (event_id) REFERENCES of_events (id) ON DELETE SET NULL,
  CONSTRAINT ck_of_inq_status CHECK (status IN ('nowe','oferta_w_robocie','wycena_wyslana','odmowa'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Zapytania z formularza';

CREATE TABLE IF NOT EXISTS of_inquiry_answers (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  inquiry_id    BIGINT UNSIGNED NOT NULL,
  question_id   BIGINT UNSIGNED NULL,
  qkey          VARCHAR(30)  NOT NULL,
  question_text VARCHAR(190) NOT NULL,
  value_text    TEXT         NULL,
  value_json    JSON         NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_inqans (inquiry_id, qkey),
  CONSTRAINT fk_of_inqans_inquiry  FOREIGN KEY (inquiry_id) REFERENCES of_inquiries (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_inqans_question FOREIGN KEY (question_id) REFERENCES of_form_questions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Pełne odpowiedzi z formularza';

-- ---------------------------------------------------------------------
-- 3.5 Katalog weselny, pakiety, zasoby, dostawcy
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS of_catalogue_categories (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  brand_id   BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(40) NOT NULL,
  sort       SMALLINT    NOT NULL DEFAULT 0,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_catcat (tenant_id, brand_id, name),
  CONSTRAINT fk_of_catcat_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_catcat_brand  FOREIGN KEY (brand_id) REFERENCES of_brands (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Kategorie katalogu weselnego';

CREATE TABLE IF NOT EXISTS of_catalogue_items (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  brand_id        BIGINT UNSIGNED NOT NULL,
  code            VARCHAR(40)  NOT NULL,
  sku             VARCHAR(30)  NOT NULL,
  category_id     BIGINT UNSIGNED NOT NULL,
  name            VARCHAR(190) NOT NULL,
  price_gr        INT          NOT NULL,
  cost_gr         INT          NOT NULL DEFAULT 0,
  unit            VARCHAR(8)   NOT NULL DEFAULT 'szt.' COMMENT 'szt. | kpl. | usł. | godz. | m',
  default_qty     SMALLINT     NOT NULL DEFAULT 1,
  in_base_package TINYINT(1)   NOT NULL DEFAULT 0,
  photo_media_id  BIGINT UNSIGNED NULL,
  pitch           VARCHAR(500) NULL,
  offer_section   VARCHAR(12)  NOT NULL DEFAULT 'kwiaty' COMMENT 'kwiaty | transport | personel | sprzet',
  active          TINYINT(1)   NOT NULL DEFAULT 1,
  sort            SMALLINT     NOT NULL DEFAULT 0,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_catitem_sku (tenant_id, sku),
  UNIQUE KEY uq_of_catitem_code (tenant_id, brand_id, code),
  KEY ix_of_catitem_cat (tenant_id, category_id, sort),
  CONSTRAINT fk_of_catitem_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_catitem_brand  FOREIGN KEY (brand_id) REFERENCES of_brands (id),
  CONSTRAINT fk_of_catitem_cat    FOREIGN KEY (category_id) REFERENCES of_catalogue_categories (id),
  CONSTRAINT fk_of_catitem_photo  FOREIGN KEY (photo_media_id) REFERENCES of_media (id) ON DELETE SET NULL,
  CONSTRAINT ck_of_catitem_money CHECK (price_gr >= 0 AND cost_gr >= 0),
  CONSTRAINT ck_of_catitem_qty   CHECK (default_qty BETWEEN 1 AND 200),
  CONSTRAINT ck_of_catitem_unit  CHECK (unit IN ('szt.','kpl.','usł.','godz.','m')),
  CONSTRAINT ck_of_catitem_sect  CHECK (offer_section IN ('kwiaty','transport','personel','sprzet'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Katalog weselny (asystent, oferta na żywo, kreator)';

CREATE TABLE IF NOT EXISTS of_catalogue_upgrades (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id        BIGINT UNSIGNED NOT NULL,
  name           VARCHAR(190) NOT NULL,
  price_gr       INT          NOT NULL,
  cost_gr        INT          NOT NULL DEFAULT 0,
  photo_media_id BIGINT UNSIGNED NULL,
  pitch          VARCHAR(500) NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_catupg_item (item_id),
  CONSTRAINT fk_of_catupg_item  FOREIGN KEY (item_id) REFERENCES of_catalogue_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_catupg_photo FOREIGN KEY (photo_media_id) REFERENCES of_media (id) ON DELETE SET NULL,
  CONSTRAINT ck_of_catupg_money CHECK (price_gr >= 0 AND cost_gr >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Lepsza opcja (upselling), 0..1 na pozycję';

CREATE TABLE IF NOT EXISTS of_packages (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      BIGINT UNSIGNED NOT NULL,
  brand_id       BIGINT UNSIGNED NOT NULL,
  code           VARCHAR(20) NOT NULL COMMENT 'kamelia | piwonia | magnolia | bazowy',
  label          VARCHAR(80) NOT NULL,
  fixed_price_gr INT         NULL COMMENT 'NULL = suma pozycji (decyzja 4)',
  is_default     TINYINT(1)  NOT NULL DEFAULT 0,
  sort           SMALLINT    NOT NULL DEFAULT 0,
  created_at     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_packages_code (tenant_id, brand_id, code),
  CONSTRAINT fk_of_packages_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_packages_brand  FOREIGN KEY (brand_id) REFERENCES of_brands (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Pakiety kreatora i pakiet bazowy asystenta';

CREATE TABLE IF NOT EXISTS of_package_items (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id  BIGINT UNSIGNED NOT NULL,
  item_id     BIGINT UNSIGNED NOT NULL,
  qty         SMALLINT   NOT NULL DEFAULT 1,
  use_upgrade TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = w pakiecie wersja „lepsza opcja” (of_catalogue_upgrades)',
  created_at  DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME   NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_pkgitem (package_id, item_id),
  CONSTRAINT fk_of_pkgitem_package FOREIGN KEY (package_id) REFERENCES of_packages (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_pkgitem_item    FOREIGN KEY (item_id) REFERENCES of_catalogue_items (id) ON DELETE CASCADE,
  CONSTRAINT ck_of_pkgitem_qty CHECK (qty >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Skład pakietu';

CREATE TABLE IF NOT EXISTS of_suppliers (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  slug       VARCHAR(30)  NOT NULL,
  name       VARCHAR(120) NOT NULL,
  kind       VARCHAR(20)  NOT NULL DEFAULT 'inne' COMMENT 'kwiaty | zielen | wynajem | materialy | wlasna | inne',
  email      VARCHAR(190) NULL,
  phone      VARCHAR(32)  NULL,
  notes      TEXT         NULL,
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_suppliers_slug (tenant_id, slug),
  CONSTRAINT fk_of_suppliers_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Dostawcy: hurtownie, wynajem, materiały';

CREATE TABLE IF NOT EXISTS of_resources (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id        BIGINT UNSIGNED NOT NULL,
  brand_id         BIGINT UNSIGNED NULL,
  code             VARCHAR(30)  NOT NULL,
  kind             VARCHAR(10)  NOT NULL COMMENT 'sprzet | transport | personel',
  name             VARCHAR(160) NOT NULL,
  unit_desc        VARCHAR(120) NULL,
  stock_qty        INT          NULL COMMENT 'tylko sprzęt',
  price_block_gr   INT          NOT NULL DEFAULT 0,
  price_hour_gr    INT          NOT NULL DEFAULT 0,
  own_cost_hour_gr INT          NOT NULL DEFAULT 0,
  ext_rent_cost_gr INT          NULL COMMENT 'sprzęt: dowynajem / szt.',
  supplier_id      BIGINT UNSIGNED NULL,
  default_mode     VARCHAR(4)   NOT NULL DEFAULT 'blok' COMMENT 'blok | godz',
  active           TINYINT(1)   NOT NULL DEFAULT 1,
  sort             SMALLINT     NOT NULL DEFAULT 0,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_resources_code (tenant_id, code),
  KEY ix_of_resources_kind (tenant_id, kind, sort),
  CONSTRAINT fk_of_resources_tenant   FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_resources_brand    FOREIGN KEY (brand_id) REFERENCES of_brands (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_resources_supplier FOREIGN KEY (supplier_id) REFERENCES of_suppliers (id) ON DELETE SET NULL,
  CONSTRAINT ck_of_resources_kind CHECK (kind IN ('sprzet','transport','personel')),
  CONSTRAINT ck_of_resources_mode CHECK (default_mode IN ('blok','godz')),
  CONSTRAINT ck_of_resources_money CHECK (price_block_gr >= 0 AND price_hour_gr >= 0 AND own_cost_hour_gr >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Zasoby: sprzęt, transport, personel';

CREATE TABLE IF NOT EXISTS of_equipment_reservations (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    BIGINT UNSIGNED NOT NULL,
  resource_id  BIGINT UNSIGNED NOT NULL,
  event_id     BIGINT UNSIGNED NOT NULL,
  qty          INT          NOT NULL,
  source       VARCHAR(10)  NOT NULL DEFAULT 'wlasny' COMMENT 'wlasny | dowynajem',
  date_from    DATETIME     NOT NULL,
  date_to      DATETIME     NOT NULL,
  status       VARCHAR(12)  NOT NULL DEFAULT 'wstepna' COMMENT 'wstepna | potwierdzona | wydana | zwrocona',
  returned_qty INT          NULL,
  damaged_qty  INT          NULL,
  damage_note  VARCHAR(255) NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_eqres (tenant_id, resource_id, date_from),
  KEY ix_of_eqres_event (event_id),
  CONSTRAINT fk_of_eqres_tenant   FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_eqres_resource FOREIGN KEY (resource_id) REFERENCES of_resources (id),
  CONSTRAINT fk_of_eqres_event    FOREIGN KEY (event_id) REFERENCES of_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Zajętość magazynu sprzętu';

-- ---------------------------------------------------------------------
-- 3.6 Sprawy (wydarzenia/wesela), harmonogram, zespół
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS of_events (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          BIGINT UNSIGNED NOT NULL,
  brand_id           BIGINT UNSIGNED NOT NULL,
  client_id          BIGINT UNSIGNED NOT NULL,
  number             VARCHAR(20)  NOT NULL COMMENT 'OF-RRRR-NNN',
  event_type         VARCHAR(12)  NOT NULL DEFAULT 'wesele' COMMENT 'wesele | chrzest | pogrzeb | firma | inne',
  event_date         DATE         NULL,
  start_time         TIME         NULL,
  end_time           TIME         NULL,
  ceremony_time      TIME         NULL,
  ceremony_kind      VARCHAR(20)  NULL,
  ceremony_venue_id  BIGINT UNSIGNED NULL,
  reception_venue_id BIGINT UNSIGNED NULL,
  guests_count       SMALLINT     NULL,
  guests_range       VARCHAR(30)  NULL,
  tables_count       SMALLINT     NULL,
  tables_kind        VARCHAR(30)  NULL,
  head_table         VARCHAR(40)  NULL,
  style              VARCHAR(40)  NULL,
  palette_label      VARCHAR(60)  NULL,
  budget_gr          INT          NULL,
  budget_range       VARCHAR(30)  NULL,
  stage              VARCHAR(24)  NOT NULL DEFAULT 'zapytanie' COMMENT 'zapytanie | rozmowa | oferta_wyslana | oferta_zaakceptowana | zadatek_oplacony | doplata_oplacona | zrealizowane | odrzucone',
  stage_entered_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lost_reason        VARCHAR(255) NULL,
  accepted_offer_id  BIGINT UNSIGNED NULL,
  capacity_slot      TINYINT      NULL,
  team_summary       VARCHAR(255) NULL,
  next_step_text     VARCHAR(255) NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_events_number (tenant_id, number),
  KEY ix_of_events_stage (tenant_id, stage),
  KEY ix_of_events_date (tenant_id, event_date),
  KEY ix_of_events_client (tenant_id, client_id),
  CONSTRAINT fk_of_events_tenant    FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_events_brand     FOREIGN KEY (brand_id) REFERENCES of_brands (id),
  CONSTRAINT fk_of_events_client    FOREIGN KEY (client_id) REFERENCES of_clients (id),
  CONSTRAINT fk_of_events_cer_venue FOREIGN KEY (ceremony_venue_id) REFERENCES of_venues (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_events_rec_venue FOREIGN KEY (reception_venue_id) REFERENCES of_venues (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_events_offer     FOREIGN KEY (accepted_offer_id) REFERENCES of_offers (id) ON DELETE SET NULL,
  CONSTRAINT ck_of_events_stage CHECK (stage IN ('zapytanie','rozmowa','oferta_wyslana','oferta_zaakceptowana','zadatek_oplacony','doplata_oplacona','zrealizowane','odrzucone'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Sprawa = wydarzenie klienta';

CREATE TABLE IF NOT EXISTS of_event_stage_log (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id           BIGINT UNSIGNED NOT NULL,
  from_stage         VARCHAR(24)  NULL,
  to_stage           VARCHAR(24)  NOT NULL,
  changed_by_user_id BIGINT UNSIGNED NULL,
  changed_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reason             VARCHAR(255) NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_esl_event (event_id, changed_at),
  CONSTRAINT fk_of_esl_event FOREIGN KEY (event_id) REFERENCES of_events (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_esl_user  FOREIGN KEY (changed_by_user_id) REFERENCES of_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Historia etapów sprawy';

CREATE TABLE IF NOT EXISTS of_staff (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    BIGINT UNSIGNED NOT NULL,
  user_id      BIGINT UNSIGNED NULL,
  name         VARCHAR(120) NOT NULL,
  default_role VARCHAR(60)  NOT NULL,
  phone        VARCHAR(32)  NULL,
  active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_staff_tenant (tenant_id, active),
  CONSTRAINT fk_of_staff_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_staff_user   FOREIGN KEY (user_id) REFERENCES of_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Członkowie zespołu';

CREATE TABLE IF NOT EXISTS of_event_team (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id   BIGINT UNSIGNED NOT NULL,
  staff_id   BIGINT UNSIGNED NOT NULL,
  role       VARCHAR(60) NOT NULL,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_evteam (event_id, staff_id),
  CONSTRAINT fk_of_evteam_event FOREIGN KEY (event_id) REFERENCES of_events (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_evteam_staff FOREIGN KEY (staff_id) REFERENCES of_staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Skład zespołu na wydarzenie';

CREATE TABLE IF NOT EXISTS of_event_schedule (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id          BIGINT UNSIGNED NOT NULL,
  sort              SMALLINT     NOT NULL DEFAULT 0,
  at_time           TIME         NOT NULL,
  duration_min      SMALLINT     NULL,
  what              VARCHAR(190) NOT NULL,
  where_text        VARCHAR(190) NULL,
  team_text         VARCHAR(120) NULL,
  kind              VARCHAR(12)  NOT NULL DEFAULT 'montaz' COMMENT 'zaladunek | przejazd | montaz | obecnosc | dostawa | demontaz',
  visible_to_client TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_evsched (event_id, sort),
  CONSTRAINT fk_of_evsched_event FOREIGN KEY (event_id) REFERENCES of_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Harmonogram dnia';

-- ---------------------------------------------------------------------
-- 3.7 Oferty, wersje, linie, link na żywo, potwierdzenie
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS of_offers (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          BIGINT UNSIGNED NOT NULL,
  brand_id           BIGINT UNSIGNED NOT NULL,
  event_id           BIGINT UNSIGNED NOT NULL,
  number             VARCHAR(20)  NOT NULL,
  version            SMALLINT     NOT NULL DEFAULT 1,
  mode               VARCHAR(14)  NOT NULL DEFAULT 'pelna' COMMENT 'pelna | szybka',
  origin             VARCHAR(10)  NOT NULL DEFAULT 'kreator' COMMENT 'kreator | asystent | szybka',
  status             VARCHAR(14)  NOT NULL DEFAULT 'szkic' COMMENT 'szkic | wyslana | otwarta | zaakceptowana | odrzucona | wygasla | zastapiona',
  package_id         BIGINT UNSIGNED NULL,
  issued_at          DATE         NULL,
  valid_until        DATE         NULL COMMENT 'issued_at + offer_validity_days (30)',
  distance_km        DECIMAL(6,1) NULL,
  km_rate_gr         INT          NOT NULL DEFAULT 0,
  hours_window       SMALLINT     NOT NULL DEFAULT 0,
  is_weekend         TINYINT(1)   NOT NULL DEFAULT 0,
  is_night           TINYINT(1)   NOT NULL DEFAULT 0,
  weekend_fee_pct    DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  night_fee_gr       INT          NOT NULL DEFAULT 0,
  floral_gr          INT          NOT NULL DEFAULT 0,
  transport_gr       INT          NOT NULL DEFAULT 0,
  travel_base_gr     INT          NOT NULL DEFAULT 0,
  staff_gr           INT          NOT NULL DEFAULT 0,
  weekend_fee_gr     INT          NOT NULL DEFAULT 0,
  rent_gr            INT          NOT NULL DEFAULT 0,
  addons_gr          INT          NOT NULL DEFAULT 0,
  upgrades_gr        INT          NOT NULL DEFAULT 0,
  extras_gr          INT          NOT NULL DEFAULT 0,
  base_price_gr      INT          NULL,
  total_gr           INT          NOT NULL DEFAULT 0,
  client_total_gr    INT          NOT NULL DEFAULT 0,
  cost_gr            INT          NOT NULL DEFAULT 0,
  ext_rent_cost_gr   INT          NOT NULL DEFAULT 0,
  margin_gr          INT          NOT NULL DEFAULT 0,
  vat_pct            DECIMAL(5,2) NOT NULL DEFAULT 8.00,
  budget_gr          INT          NULL,
  deposit_pct        DECIMAL(5,2) NOT NULL DEFAULT 30.00,
  deposit_gr         INT          NOT NULL DEFAULT 0,
  live_enabled       TINYINT(1)   NOT NULL DEFAULT 1,
  public_token       CHAR(32)     NOT NULL COMMENT 'link /o/{token}, ≥128 bit losowe',
  live_step_no       TINYINT      NULL,
  live_step_count    TINYINT      NULL,
  live_step_title    VARCHAR(80)  NULL,
  live_snapshot_json JSON         NULL,
  live_snapshot_at   DATETIME     NULL,
  live_hash          VARCHAR(40)  NULL,
  first_viewed_at    DATETIME     NULL,
  last_viewed_at     DATETIME     NULL,
  view_count         INT          NOT NULL DEFAULT 0,
  accepted_at        DATETIME     NULL,
  rejected_at        DATETIME     NULL,
  pdf_media_id       BIGINT UNSIGNED NULL,
  contract_media_id  BIGINT UNSIGNED NULL,
  intro_text         TEXT         NULL,
  notes_internal     TEXT         NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_offers_version (event_id, version),
  UNIQUE KEY uq_of_offers_token (public_token),
  KEY ix_of_offers_status (tenant_id, status),
  KEY ix_of_offers_event (tenant_id, event_id),
  CONSTRAINT fk_of_offers_tenant   FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_offers_brand    FOREIGN KEY (brand_id) REFERENCES of_brands (id),
  CONSTRAINT fk_of_offers_event    FOREIGN KEY (event_id) REFERENCES of_events (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_offers_package  FOREIGN KEY (package_id) REFERENCES of_packages (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_offers_pdf      FOREIGN KEY (pdf_media_id) REFERENCES of_media (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_offers_contract FOREIGN KEY (contract_media_id) REFERENCES of_media (id) ON DELETE SET NULL,
  CONSTRAINT ck_of_offers_status CHECK (status IN ('szkic','wyslana','otwarta','zaakceptowana','odrzucona','wygasla','zastapiona')),
  CONSTRAINT ck_of_offers_mode   CHECK (mode IN ('pelna','szybka'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Oferta (wersja)';

CREATE TABLE IF NOT EXISTS of_offer_lines (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  offer_id          BIGINT UNSIGNED NOT NULL,
  sort              SMALLINT     NOT NULL DEFAULT 0,
  section           VARCHAR(16)  NOT NULL COMMENT 'bazowy | kwiaty | transport | personel | sprzet | dodatek | ulepszenie | dodatek_klienta',
  catalogue_item_id BIGINT UNSIGNED NULL,
  resource_id       BIGINT UNSIGNED NULL,
  is_upgrade        TINYINT(1)   NOT NULL DEFAULT 0,
  replaces_name     VARCHAR(190) NULL,
  name              VARCHAR(190) NOT NULL,
  qty               DECIMAL(8,2) NOT NULL DEFAULT 1.00,
  unit              VARCHAR(12)  NOT NULL DEFAULT 'szt.',
  billing_mode      VARCHAR(4)   NULL COMMENT 'blok | godz',
  hours             DECIMAL(5,2) NULL,
  unit_price_gr     INT          NOT NULL DEFAULT 0,
  unit_cost_gr      INT          NOT NULL DEFAULT 0,
  line_total_gr     INT          NOT NULL DEFAULT 0,
  line_cost_gr      INT          NOT NULL DEFAULT 0,
  ext_rent_qty      INT          NOT NULL DEFAULT 0,
  ext_rent_cost_gr  INT          NOT NULL DEFAULT 0,
  is_optional       TINYINT(1)   NOT NULL DEFAULT 0,
  selected          TINYINT(1)   NOT NULL DEFAULT 1,
  client_label      VARCHAR(60)  NULL,
  photo_media_id    BIGINT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_oline (offer_id, section, sort),
  CONSTRAINT fk_of_oline_offer    FOREIGN KEY (offer_id) REFERENCES of_offers (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_oline_item     FOREIGN KEY (catalogue_item_id) REFERENCES of_catalogue_items (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_oline_resource FOREIGN KEY (resource_id) REFERENCES of_resources (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_oline_photo    FOREIGN KEY (photo_media_id) REFERENCES of_media (id) ON DELETE SET NULL,
  CONSTRAINT ck_of_oline_section CHECK (section IN ('bazowy','kwiaty','transport','personel','sprzet','dodatek','ulepszenie','dodatek_klienta'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Linie oferty';

CREATE TABLE IF NOT EXISTS of_offer_shown_items (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  offer_id          BIGINT UNSIGNED NOT NULL,
  catalogue_item_id BIGINT UNSIGNED NOT NULL,
  shown_at          DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  hidden_at         DATETIME   NULL,
  in_offer          TINYINT(1) NOT NULL DEFAULT 0,
  reaction          VARCHAR(4) NULL COMMENT 'tak | nie',
  reaction_up       VARCHAR(4) NULL COMMENT 'tak | nie (lepsza opcja)',
  reacted_at        DATETIME   NULL,
  created_at        DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME   NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_oshown (offer_id, catalogue_item_id),
  CONSTRAINT fk_of_oshown_offer FOREIGN KEY (offer_id) REFERENCES of_offers (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_oshown_item  FOREIGN KEY (catalogue_item_id) REFERENCES of_catalogue_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Pozycje pokazane parze na żywo i reakcje';

CREATE TABLE IF NOT EXISTS of_offer_confirmations (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  offer_id            BIGINT UNSIGNED NOT NULL,
  phone_e164          VARCHAR(20)  NOT NULL,
  phone_masked        VARCHAR(24)  NOT NULL,
  code_hash           CHAR(64)     NOT NULL COMMENT 'sha256(kod+sól)',
  sent_at             DATETIME     NOT NULL,
  resend_available_at DATETIME     NOT NULL,
  attempts            TINYINT      NOT NULL DEFAULT 0,
  expires_at          DATETIME     NOT NULL,
  accepted_terms      TINYINT(1)   NOT NULL DEFAULT 0,
  offer_hash          VARCHAR(40)  NOT NULL,
  total_gr            INT          NOT NULL,
  confirmed_at        DATETIME     NULL,
  ip                  VARCHAR(45)  NULL,
  user_agent          VARCHAR(255) NULL,
  status              VARCHAR(10)  NOT NULL DEFAULT 'wyslany' COMMENT 'wyslany | potwierdzony | zablokowany | nieaktualny',
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_oconf (offer_id, status),
  CONSTRAINT fk_of_oconf_offer FOREIGN KEY (offer_id) REFERENCES of_offers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Potwierdzenie oferty kodem SMS';

CREATE TABLE IF NOT EXISTS of_offer_sends (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id        BIGINT UNSIGNED NOT NULL,
  offer_id         BIGINT UNSIGNED NOT NULL,
  sent_at          DATETIME     NOT NULL,
  sent_by_user_id  BIGINT UNSIGNED NULL,
  mode             VARCHAR(14)  NOT NULL DEFAULT 'pelna',
  to_email         VARCHAR(190) NOT NULL,
  cc_email         VARCHAR(190) NULL,
  template_id      BIGINT UNSIGNED NULL,
  subject          VARCHAR(255) NOT NULL,
  body             TEXT         NOT NULL,
  channel          VARCHAR(20)  NOT NULL DEFAULT 'mail + Tpay',
  amount_gr        INT          NOT NULL DEFAULT 0,
  status           VARCHAR(14)  NOT NULL DEFAULT 'wyslana' COMMENT 'wyslana | otwarta | zaakceptowana | bez_odpowiedzi | oplacona | odrzucona',
  mail_message_id  BIGINT UNSIGNED NULL,
  attachments_json JSON         NULL,
  duration_sec     INT          NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_osend_date (tenant_id, sent_at),
  KEY ix_of_osend_offer (offer_id),
  CONSTRAINT fk_of_osend_tenant   FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_osend_offer    FOREIGN KEY (offer_id) REFERENCES of_offers (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_osend_user     FOREIGN KEY (sent_by_user_id) REFERENCES of_users (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_osend_template FOREIGN KEY (template_id) REFERENCES of_mail_templates (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_osend_message  FOREIGN KEY (mail_message_id) REFERENCES of_mail_messages (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Wysyłki ofert';

CREATE TABLE IF NOT EXISTS of_calls (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  event_id        BIGINT UNSIGNED NULL,
  inquiry_id      BIGINT UNSIGNED NULL,
  offer_id        BIGINT UNSIGNED NULL,
  script_id       BIGINT UNSIGNED NOT NULL,
  started_at      DATETIME     NULL,
  elapsed_sec     INT          NOT NULL DEFAULT 0,
  status          VARCHAR(10)  NOT NULL DEFAULT 'trwa' COMMENT 'trwa | pauza | zakonczona',
  current_step_id BIGINT UNSIGNED NULL,
  screen_share    TINYINT(1)   NOT NULL DEFAULT 0,
  names           VARCHAR(160) NULL,
  date_label      VARCHAR(80)  NULL,
  ceremony_label  VARCHAR(190) NULL,
  reception_label VARCHAR(190) NULL,
  guests_label    VARCHAR(40)  NULL,
  email           VARCHAR(190) NULL,
  phone           VARCHAR(32)  NULL,
  budget_gr       INT          NULL,
  notes           TEXT         NULL,
  link_sent_at    DATETIME     NULL,
  summary_sent_at DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_calls_tenant (tenant_id, started_at),
  CONSTRAINT fk_of_calls_tenant  FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_calls_event   FOREIGN KEY (event_id) REFERENCES of_events (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_calls_inquiry FOREIGN KEY (inquiry_id) REFERENCES of_inquiries (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_calls_offer   FOREIGN KEY (offer_id) REFERENCES of_offers (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_calls_script  FOREIGN KEY (script_id) REFERENCES of_call_scripts (id),
  CONSTRAINT fk_of_calls_step    FOREIGN KEY (current_step_id) REFERENCES of_call_script_steps (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Sesja asystenta rozmowy';

CREATE TABLE IF NOT EXISTS of_call_checks (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  call_id    BIGINT UNSIGNED NOT NULL,
  point_id   BIGINT UNSIGNED NOT NULL,
  checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_callchk (call_id, point_id),
  CONSTRAINT fk_of_callchk_call  FOREIGN KEY (call_id) REFERENCES of_calls (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_callchk_point FOREIGN KEY (point_id) REFERENCES of_call_script_points (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Odhaczone punkty rozmowy';

-- ---------------------------------------------------------------------
-- 3.8 Umowa, dokumenty, raty, płatności, faktury
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS of_document_types (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  brand_id    BIGINT UNSIGNED NOT NULL,
  code        VARCHAR(20)  NOT NULL COMMENT 'potw | oferta | umowa | faktura',
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  send_when   VARCHAR(80)  NOT NULL DEFAULT '',
  enabled     TINYINT(1)   NOT NULL DEFAULT 1,
  sort        SMALLINT     NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_doctypes (tenant_id, brand_id, code),
  CONSTRAINT fk_of_doctypes_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_doctypes_brand  FOREIGN KEY (brand_id) REFERENCES of_brands (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Dokumenty systemu';

CREATE TABLE IF NOT EXISTS of_contract_clauses (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  brand_id   BIGINT UNSIGNED NOT NULL,
  sort       SMALLINT     NOT NULL DEFAULT 0,
  ckey       VARCHAR(10)  NOT NULL COMMENT 'c1…c12',
  title      VARCHAR(120) NOT NULL,
  pdf_title  VARCHAR(120) NOT NULL,
  body       TEXT         NOT NULL,
  enabled    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_clauses (tenant_id, brand_id, ckey),
  CONSTRAINT fk_of_clauses_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_clauses_brand  FOREIGN KEY (brand_id) REFERENCES of_brands (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Klauzule umowy (szablon)';

CREATE TABLE IF NOT EXISTS of_installment_templates (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id           BIGINT UNSIGNED NOT NULL,
  brand_id            BIGINT UNSIGNED NOT NULL,
  no_label            VARCHAR(4)   NOT NULL COMMENT 'I, II, III',
  sort                SMALLINT     NOT NULL DEFAULT 0,
  label               VARCHAR(190) NOT NULL,
  pct                 DECIMAL(5,2) NOT NULL COMMENT 'krok 5; Σ = 100',
  kind                VARCHAR(10)  NOT NULL DEFAULT 'rata' COMMENT 'zadatek | rata | koncowa',
  due_rule            VARCHAR(20)  NOT NULL COMMENT 'przy_podpisie | D-{n} | +{n}d',
  due_offset_days     SMALLINT     NULL COMMENT 'ujemne = przed dniem wydarzenia',
  grace_business_days TINYINT      NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_insttpl (tenant_id, brand_id, no_label),
  CONSTRAINT fk_of_insttpl_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_insttpl_brand  FOREIGN KEY (brand_id) REFERENCES of_brands (id),
  CONSTRAINT ck_of_insttpl_pct CHECK (pct > 0 AND pct <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Raty (szablon) — 30/35/35';

CREATE TABLE IF NOT EXISTS of_contracts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  offer_id        BIGINT UNSIGNED NOT NULL,
  number          VARCHAR(20)  NOT NULL,
  generated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pdf_media_id    BIGINT UNSIGNED NULL,
  clauses_json    JSON         NOT NULL,
  contractor_json JSON         NOT NULL,
  client_json     JSON         NOT NULL,
  signed_at       DATETIME     NULL,
  signed_method   VARCHAR(12)  NULL COMMENT 'skan_mail | sms_kod | papier',
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_contracts_offer (offer_id),
  CONSTRAINT fk_of_contracts_offer FOREIGN KEY (offer_id) REFERENCES of_offers (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_contracts_pdf   FOREIGN KEY (pdf_media_id) REFERENCES of_media (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Umowa wygenerowana dla oferty';

CREATE TABLE IF NOT EXISTS of_payment_schedule (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  offer_id               BIGINT UNSIGNED NOT NULL,
  no_label               VARCHAR(4)   NOT NULL,
  sort                   SMALLINT     NOT NULL DEFAULT 0,
  label                  VARCHAR(190) NOT NULL,
  pct                    DECIMAL(5,2) NOT NULL,
  amount_gr              INT          NOT NULL,
  due_date               DATE         NULL,
  kind                   VARCHAR(10)  NOT NULL COMMENT 'zadatek | rata | koncowa',
  status                 VARCHAR(12)  NOT NULL DEFAULT 'oczekuje' COMMENT 'oczekuje | po_terminie | oplacona | anulowana',
  tpay_link              VARCHAR(255) NULL,
  tpay_transaction_title VARCHAR(64)  NULL,
  paid_at                DATETIME     NULL,
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_paysched (offer_id, no_label),
  KEY ix_of_paysched_due (status, due_date),
  CONSTRAINT fk_of_paysched_offer FOREIGN KEY (offer_id) REFERENCES of_offers (id) ON DELETE CASCADE,
  CONSTRAINT ck_of_paysched_status CHECK (status IN ('oczekuje','po_terminie','oplacona','anulowana')),
  CONSTRAINT ck_of_paysched_kind   CHECK (kind IN ('zadatek','rata','koncowa'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Raty konkretnej oferty';

CREATE TABLE IF NOT EXISTS of_payments (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    BIGINT UNSIGNED NOT NULL,
  event_id     BIGINT UNSIGNED NOT NULL,
  schedule_id  BIGINT UNSIGNED NULL,
  amount_gr    INT          NOT NULL,
  method       VARCHAR(10)  NOT NULL COMMENT 'tpay | przelew | blik | karta | gotowka',
  provider_ref VARCHAR(80)  NULL,
  booked_at    DATETIME     NOT NULL,
  kind         VARCHAR(10)  NOT NULL COMMENT 'zadatek | doplata | zwrot',
  raw_payload  JSON         NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_payments_ref (provider_ref),
  KEY ix_of_payments_date (tenant_id, booked_at),
  KEY ix_of_payments_event (event_id),
  CONSTRAINT fk_of_payments_tenant   FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_payments_event    FOREIGN KEY (event_id) REFERENCES of_events (id),
  CONSTRAINT fk_of_payments_schedule FOREIGN KEY (schedule_id) REFERENCES of_payment_schedule (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Wpłaty zaksięgowane';

CREATE TABLE IF NOT EXISTS of_invoices (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    BIGINT UNSIGNED NOT NULL,
  payment_id   BIGINT UNSIGNED NOT NULL,
  number       VARCHAR(30)  NOT NULL,
  issued_at    DATE         NOT NULL,
  kind         VARCHAR(10)  NOT NULL DEFAULT 'faktura' COMMENT 'faktura | rachunek',
  net_gr       INT          NOT NULL,
  vat_pct      DECIMAL(5,2) NOT NULL,
  vat_gr       INT          NOT NULL,
  gross_gr     INT          NOT NULL,
  pdf_media_id BIGINT UNSIGNED NULL,
  sent_at      DATETIME     NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_invoices_number (tenant_id, number),
  CONSTRAINT fk_of_invoices_tenant  FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_invoices_payment FOREIGN KEY (payment_id) REFERENCES of_payments (id),
  CONSTRAINT fk_of_invoices_pdf     FOREIGN KEY (pdf_media_id) REFERENCES of_media (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Faktury / rachunki';

CREATE TABLE IF NOT EXISTS of_doc_variables (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope       VARCHAR(8)   NOT NULL COMMENT 'doc | mail',
  vkey        VARCHAR(40)  NOT NULL COMMENT 'nazwa bez nawiasów; składnia {{vkey}}',
  description VARCHAR(120) NOT NULL,
  example     VARCHAR(120) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_docvars (scope, vkey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Zmienne dokumentów i maili (globalne)';

-- ---------------------------------------------------------------------
-- 3.9 Retroplan
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS of_retro_templates (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  brand_id   BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(80) NOT NULL,
  is_default TINYINT(1)  NOT NULL DEFAULT 0,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_retrotpl (tenant_id, brand_id, name),
  CONSTRAINT fk_of_retrotpl_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_retrotpl_brand  FOREIGN KEY (brand_id) REFERENCES of_brands (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Szablony retroplanu';

CREATE TABLE IF NOT EXISTS of_retro_template_steps (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_id      BIGINT UNSIGNED NOT NULL,
  sort             SMALLINT     NOT NULL DEFAULT 0,
  offset_days      SMALLINT     NOT NULL COMMENT 'ujemne = przed, 0 = dzień zero, dodatnie = po',
  name             VARCHAR(190) NOT NULL,
  default_assignee VARCHAR(60)  NULL,
  default_staff_id BIGINT UNSIGNED NULL,
  channel          VARCHAR(16)  NULL COMMENT 'mail | sms | mail + sms | spotkanie | wewnetrzne | telefon',
  kind             VARCHAR(10)  NOT NULL DEFAULT 'zadanie' COMMENT 'zadanie | relanca | platnosc',
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_retrostep (template_id, offset_days, sort),
  CONSTRAINT fk_of_retrostep_tpl   FOREIGN KEY (template_id) REFERENCES of_retro_templates (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_retrostep_staff FOREIGN KEY (default_staff_id) REFERENCES of_staff (id) ON DELETE SET NULL,
  CONSTRAINT ck_of_retrostep_kind CHECK (kind IN ('zadanie','relanca','platnosc'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Kroki retroplanu (D ± n)';

CREATE TABLE IF NOT EXISTS of_event_tasks (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id          BIGINT UNSIGNED NOT NULL,
  step_id           BIGINT UNSIGNED NULL,
  sort              SMALLINT     NOT NULL DEFAULT 0,
  name              VARCHAR(190) NOT NULL,
  offset_days       SMALLINT     NOT NULL,
  due_date          DATE         NOT NULL,
  assignee_staff_id BIGINT UNSIGNED NULL,
  assignee_label    VARCHAR(60)  NULL,
  done_at           DATETIME     NULL,
  done_by_user_id   BIGINT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_evtask_event (event_id, due_date),
  KEY ix_of_evtask_due (due_date, done_at),
  CONSTRAINT fk_of_evtask_event FOREIGN KEY (event_id) REFERENCES of_events (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_evtask_step  FOREIGN KEY (step_id) REFERENCES of_retro_template_steps (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_evtask_staff FOREIGN KEY (assignee_staff_id) REFERENCES of_staff (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_evtask_user  FOREIGN KEY (done_by_user_id) REFERENCES of_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Zadania retroplanu wydarzenia';

-- ---------------------------------------------------------------------
-- 3.10 Karty misji
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS of_mission_templates (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    BIGINT UNSIGNED NOT NULL,
  role         VARCHAR(60)  NOT NULL,
  name         VARCHAR(80)  NOT NULL,
  sort         SMALLINT     NOT NULL DEFAULT 0,
  window_start TIME         NULL COMMENT 'ajout : okno pracy z szablonu §4.6',
  window_end   TIME         NULL,
  meet_time    TIME         NULL,
  meet_place   VARCHAR(120) NULL,
  transport    VARCHAR(190) NULL,
  kit_text     TEXT         NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_misstpl_role (tenant_id, role),
  CONSTRAINT fk_of_misstpl_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Checklista bazowa per rola';

CREATE TABLE IF NOT EXISTS of_mission_template_tasks (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_id BIGINT UNSIGNED NOT NULL,
  sort        SMALLINT     NOT NULL DEFAULT 0,
  at_time     TIME         NULL,
  name        VARCHAR(190) NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_misstpltask (template_id, sort),
  CONSTRAINT fk_of_misstpltask_tpl FOREIGN KEY (template_id) REFERENCES of_mission_templates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Zadania checklisty bazowej';

CREATE TABLE IF NOT EXISTS of_missions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id       BIGINT UNSIGNED NOT NULL,
  staff_id       BIGINT UNSIGNED NOT NULL,
  role           VARCHAR(60)  NOT NULL,
  sort           SMALLINT     NOT NULL DEFAULT 0,
  window_start   TIME         NOT NULL,
  window_end     TIME         NOT NULL COMMENT 'może przechodzić przez północ',
  meet_time      TIME         NULL,
  meet_place     VARCHAR(120) NULL,
  place_name     VARCHAR(190) NOT NULL,
  address        VARCHAR(255) NOT NULL DEFAULT '',
  venue_id       BIGINT UNSIGNED NULL,
  transport      VARCHAR(190) NULL,
  onsite_contact VARCHAR(190) NULL,
  kit_text       TEXT         NULL,
  template_id    BIGINT UNSIGNED NULL,
  field_notes    TEXT         NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_missions (event_id, staff_id),
  CONSTRAINT fk_of_missions_event FOREIGN KEY (event_id) REFERENCES of_events (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_missions_staff FOREIGN KEY (staff_id) REFERENCES of_staff (id),
  CONSTRAINT fk_of_missions_venue FOREIGN KEY (venue_id) REFERENCES of_venues (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_missions_tpl   FOREIGN KEY (template_id) REFERENCES of_mission_templates (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Karta misji (osoba × wydarzenie)';

CREATE TABLE IF NOT EXISTS of_mission_tasks (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mission_id        BIGINT UNSIGNED NOT NULL,
  sort              SMALLINT     NOT NULL DEFAULT 0,
  at_time           TIME         NULL,
  name              VARCHAR(190) NOT NULL,
  assignee_staff_id BIGINT UNSIGNED NULL,
  done_at           DATETIME     NULL,
  done_by_user_id   BIGINT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_misstask (mission_id, sort),
  CONSTRAINT fk_of_misstask_mission FOREIGN KEY (mission_id) REFERENCES of_missions (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_misstask_staff   FOREIGN KEY (assignee_staff_id) REFERENCES of_staff (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_misstask_user    FOREIGN KEY (done_by_user_id) REFERENCES of_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Zadania karty misji';

CREATE TABLE IF NOT EXISTS of_mission_kit (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mission_id  BIGINT UNSIGNED NOT NULL,
  resource_id BIGINT UNSIGNED NULL,
  label       VARCHAR(120) NOT NULL,
  qty         INT          NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_misskit (mission_id),
  CONSTRAINT fk_of_misskit_mission  FOREIGN KEY (mission_id) REFERENCES of_missions (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_misskit_resource FOREIGN KEY (resource_id) REFERENCES of_resources (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Sprzęt na karcie misji';

-- ---------------------------------------------------------------------
-- 3.11 Zakupy, zamówienia u dostawców, koszty
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS of_purchase_orders (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  ordered_at  DATE         NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  total_gr    INT          NOT NULL DEFAULT 0,
  status      VARCHAR(12)  NOT NULL DEFAULT 'do_zaplaty' COMMENT 'do_zaplaty | w_drodze | zaplacone',
  invoice_no  VARCHAR(60)  NULL,
  due_date    DATE         NULL,
  paid_at     DATE         NULL,
  event_id    BIGINT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_po_tenant (tenant_id, status, ordered_at),
  CONSTRAINT fk_of_po_tenant   FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_po_supplier FOREIGN KEY (supplier_id) REFERENCES of_suppliers (id),
  CONSTRAINT fk_of_po_event    FOREIGN KEY (event_id) REFERENCES of_events (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Zamówienia / faktury od dostawców';

CREATE TABLE IF NOT EXISTS of_shopping_items (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  event_id          BIGINT UNSIGNED NOT NULL,
  sort              SMALLINT      NOT NULL DEFAULT 0,
  name              VARCHAR(160)  NOT NULL,
  kind              VARCHAR(12)   NOT NULL COMMENT 'kwiat_ciety | zielen | material | wynajem',
  qty               DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  qty_unit          VARCHAR(16)   NOT NULL DEFAULT 'szt.',
  unit_price_gr     INT           NOT NULL DEFAULT 0,
  supplier_id       BIGINT UNSIGNED NULL,
  bought_at         DATETIME      NULL,
  bought_by_user_id BIGINT UNSIGNED NULL,
  purchase_order_id BIGINT UNSIGNED NULL,
  catalogue_item_id BIGINT UNSIGNED NULL,
  resource_id       BIGINT UNSIGNED NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME      NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_shop_event (event_id, sort),
  KEY ix_of_shop_tenant (tenant_id),
  CONSTRAINT fk_of_shop_tenant   FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_shop_event    FOREIGN KEY (event_id) REFERENCES of_events (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_shop_supplier FOREIGN KEY (supplier_id) REFERENCES of_suppliers (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_shop_user     FOREIGN KEY (bought_by_user_id) REFERENCES of_users (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_shop_po       FOREIGN KEY (purchase_order_id) REFERENCES of_purchase_orders (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_shop_item     FOREIGN KEY (catalogue_item_id) REFERENCES of_catalogue_items (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_shop_resource FOREIGN KEY (resource_id) REFERENCES of_resources (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Lista zakupów na wydarzenie';

CREATE TABLE IF NOT EXISTS of_expenses (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  event_id          BIGINT UNSIGNED NULL,
  purchase_order_id BIGINT UNSIGNED NULL,
  spent_at          DATE         NOT NULL,
  amount_gr         INT          NOT NULL,
  category          VARCHAR(12)  NOT NULL COMMENT 'kwiaty | dowynajem | personel | transport | materialy | stale | inne',
  description       VARCHAR(255) NULL,
  supplier_id       BIGINT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_exp_date (tenant_id, spent_at),
  CONSTRAINT fk_of_exp_tenant   FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_exp_event    FOREIGN KEY (event_id) REFERENCES of_events (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_exp_po       FOREIGN KEY (purchase_order_id) REFERENCES of_purchase_orders (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_exp_supplier FOREIGN KEY (supplier_id) REFERENCES of_suppliers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Wypływy (finanse)';

-- ---------------------------------------------------------------------
-- 3.12 Poczta, szablony, automaty, SMS
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS of_mail_templates (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id                BIGINT UNSIGNED NOT NULL,
  brand_id                 BIGINT UNSIGNED NOT NULL,
  code                     VARCHAR(30)  NULL,
  category                 VARCHAR(12)  NOT NULL COMMENT 'zapytania | oferty | platnosci | realizacja',
  name                     VARCHAR(120) NOT NULL,
  subject                  VARCHAR(255) NOT NULL,
  body                     TEXT         NOT NULL,
  default_attachments_json JSON         NULL COMMENT '⊂ oferta_pdf, umowa_pdf, link_tpay, faktura_pdf, retroplan_pdf',
  trigger_note             VARCHAR(120) NULL,
  is_system_default        TINYINT(1)   NOT NULL DEFAULT 0,
  default_subject          VARCHAR(255) NULL,
  default_body             TEXT         NULL,
  sent_count               INT          NOT NULL DEFAULT 0,
  open_count               INT          NOT NULL DEFAULT 0,
  sort                     SMALLINT     NOT NULL DEFAULT 0,
  created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_mailtpl_code (tenant_id, brand_id, code),
  KEY ix_of_mailtpl_cat (tenant_id, category, sort),
  CONSTRAINT fk_of_mailtpl_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_mailtpl_brand  FOREIGN KEY (brand_id) REFERENCES of_brands (id),
  CONSTRAINT ck_of_mailtpl_cat CHECK (category IN ('zapytania','oferty','platnosci','realizacja'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Szablony maili (jeden system, składnia {{zmienna}})';

CREATE TABLE IF NOT EXISTS of_mail_threads (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  brand_id          BIGINT UNSIGNED NOT NULL,
  folder            VARCHAR(6)   NOT NULL DEFAULT 'in' COMMENT 'in | sent | sched | draft | arch',
  unread            TINYINT(1)   NOT NULL DEFAULT 1,
  tag               VARCHAR(12)  NULL COMMENT 'zapytanie | oferta | platnosc | realizacja | B2B | pogrzeb',
  subject           VARCHAR(255) NOT NULL,
  counterpart_name  VARCHAR(160) NOT NULL DEFAULT '',
  counterpart_email VARCHAR(190) NOT NULL DEFAULT '',
  client_id         BIGINT UNSIGNED NULL,
  event_id          BIGINT UNSIGNED NULL,
  offer_id          BIGINT UNSIGNED NULL,
  inquiry_id        BIGINT UNSIGNED NULL,
  last_message_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_mthr_folder (tenant_id, folder, last_message_at),
  KEY ix_of_mthr_email (tenant_id, counterpart_email),
  FULLTEXT KEY ft_of_mthr_subject (subject),
  CONSTRAINT fk_of_mthr_tenant  FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_mthr_brand   FOREIGN KEY (brand_id) REFERENCES of_brands (id),
  CONSTRAINT fk_of_mthr_client  FOREIGN KEY (client_id) REFERENCES of_clients (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_mthr_event   FOREIGN KEY (event_id) REFERENCES of_events (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_mthr_offer   FOREIGN KEY (offer_id) REFERENCES of_offers (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_mthr_inquiry FOREIGN KEY (inquiry_id) REFERENCES of_inquiries (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Wątki poczty';

CREATE TABLE IF NOT EXISTS of_mail_messages (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id         BIGINT UNSIGNED NOT NULL,
  direction         VARCHAR(3)   NOT NULL COMMENT 'in | out',
  from_addr         VARCHAR(255) NOT NULL,
  to_addr           VARCHAR(255) NOT NULL,
  cc_addr           VARCHAR(255) NULL,
  subject           VARCHAR(255) NOT NULL,
  body_text         MEDIUMTEXT   NOT NULL,
  body_html         MEDIUMTEXT   NULL,
  template_id       BIGINT UNSIGNED NULL,
  rule_id           BIGINT UNSIGNED NULL,
  sent_by_user_id   BIGINT UNSIGNED NULL,
  status            VARCHAR(12)  NOT NULL DEFAULT 'szkic' COMMENT 'odebrana | szkic | zaplanowana | wysylana | dostarczona | otwarta | blad',
  scheduled_at      DATETIME     NULL,
  sent_at           DATETIME     NULL,
  opened_count      INT          NOT NULL DEFAULT 0,
  first_opened_at   DATETIME     NULL,
  message_id_header VARCHAR(255) NULL,
  in_reply_to       VARCHAR(255) NULL,
  error             VARCHAR(255) NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_mmsg_msgid (message_id_header),
  KEY ix_of_mmsg_queue (status, scheduled_at),
  KEY ix_of_mmsg_thread (thread_id, created_at),
  FULLTEXT KEY ft_of_mmsg_body (body_text),
  CONSTRAINT fk_of_mmsg_thread   FOREIGN KEY (thread_id) REFERENCES of_mail_threads (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_mmsg_template FOREIGN KEY (template_id) REFERENCES of_mail_templates (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_mmsg_rule     FOREIGN KEY (rule_id) REFERENCES of_automation_rules (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_mmsg_user     FOREIGN KEY (sent_by_user_id) REFERENCES of_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Wiadomości poczty';

CREATE TABLE IF NOT EXISTS of_mail_attachments (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id BIGINT UNSIGNED NOT NULL,
  kind       VARCHAR(16)  NOT NULL COMMENT 'oferta_pdf | umowa_pdf | link_tpay | faktura_pdf | retroplan_pdf | plik',
  media_id   BIGINT UNSIGNED NULL,
  url        VARCHAR(255) NULL,
  filename   VARCHAR(190) NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_matt_msg (message_id),
  CONSTRAINT fk_of_matt_msg   FOREIGN KEY (message_id) REFERENCES of_mail_messages (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_matt_media FOREIGN KEY (media_id) REFERENCES of_media (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Załączniki wiadomości';

CREATE TABLE IF NOT EXISTS of_automation_rules (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  brand_id      BIGINT UNSIGNED NOT NULL,
  code          VARCHAR(30)  NULL COMMENT 'ajout : identifiant stable (seed)',
  sort          SMALLINT     NOT NULL DEFAULT 0,
  trigger_type  VARCHAR(14)  NOT NULL COMMENT 'stage_enter | event_offset | system_event',
  trigger_stage VARCHAR(24)  NULL COMMENT 'slug of_stages',
  system_event  VARCHAR(30)  NULL COMMENT 'form_submitted | offer_no_reply | offer_accepted | deposit_booked | payment_booked',
  offset_days   SMALLINT     NOT NULL DEFAULT 0 COMMENT 'D+1 = 1, D-14 = -14',
  send_time     TIME         NULL,
  channel       VARCHAR(10)  NOT NULL COMMENT 'mail | sms | mail+sms',
  template_id   BIGINT UNSIGNED NULL,
  sms_body      VARCHAR(480) NULL,
  description   VARCHAR(255) NOT NULL DEFAULT '',
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_autorule_code (tenant_id, brand_id, code),
  KEY ix_of_autorule (tenant_id, active, trigger_type),
  CONSTRAINT fk_of_autorule_tenant   FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_autorule_brand    FOREIGN KEY (brand_id) REFERENCES of_brands (id),
  CONSTRAINT fk_of_autorule_template FOREIGN KEY (template_id) REFERENCES of_mail_templates (id) ON DELETE SET NULL,
  CONSTRAINT ck_of_autorule_type CHECK (trigger_type IN ('stage_enter','event_offset','system_event')),
  CONSTRAINT ck_of_autorule_chan CHECK (channel IN ('mail','sms','mail+sms'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Automaty i relanse';

CREATE TABLE IF NOT EXISTS of_automation_runs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rule_id       BIGINT UNSIGNED NOT NULL,
  event_id      BIGINT UNSIGNED NULL,
  inquiry_id    BIGINT UNSIGNED NULL,
  offer_id      BIGINT UNSIGNED NULL,
  scheduled_for DATETIME     NOT NULL,
  executed_at   DATETIME     NULL,
  status        VARCHAR(10)  NOT NULL DEFAULT 'plan' COMMENT 'plan | wyslano | pominieto | blad | przesunieto',
  message_id    BIGINT UNSIGNED NULL,
  sms_id        BIGINT UNSIGNED NULL,
  manual        TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_autorun (rule_id, event_id, scheduled_for),
  KEY ix_of_autorun_due (status, scheduled_for),
  CONSTRAINT fk_of_autorun_rule    FOREIGN KEY (rule_id) REFERENCES of_automation_rules (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_autorun_event   FOREIGN KEY (event_id) REFERENCES of_events (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_autorun_inquiry FOREIGN KEY (inquiry_id) REFERENCES of_inquiries (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_autorun_offer   FOREIGN KEY (offer_id) REFERENCES of_offers (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_autorun_message FOREIGN KEY (message_id) REFERENCES of_mail_messages (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_autorun_sms     FOREIGN KEY (sms_id) REFERENCES of_sms_messages (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Wykonania automatów';

CREATE TABLE IF NOT EXISTS of_sms_messages (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    BIGINT UNSIGNED NOT NULL,
  to_phone     VARCHAR(20)  NOT NULL,
  body         VARCHAR(480) NOT NULL,
  sender       VARCHAR(11)  NOT NULL,
  purpose      VARCHAR(12)  NOT NULL COMMENT 'relanca | otp | reczny',
  event_id     BIGINT UNSIGNED NULL,
  offer_id     BIGINT UNSIGNED NULL,
  rule_id      BIGINT UNSIGNED NULL,
  status       VARCHAR(10)  NOT NULL DEFAULT 'kolejka' COMMENT 'kolejka | wyslany | dostarczony | blad',
  provider_ref VARCHAR(64)  NULL,
  sent_at      DATETIME     NULL,
  error        VARCHAR(255) NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_sms_queue (tenant_id, status, created_at),
  CONSTRAINT fk_of_sms_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_sms_event  FOREIGN KEY (event_id) REFERENCES of_events (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_sms_offer  FOREIGN KEY (offer_id) REFERENCES of_offers (id) ON DELETE SET NULL,
  CONSTRAINT fk_of_sms_rule   FOREIGN KEY (rule_id) REFERENCES of_automation_rules (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='SMS';

-- ---------------------------------------------------------------------
-- 3.13 CMS, portfolio (of_media déclarée plus haut)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS of_sites (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  brand_id   BIGINT UNSIGNED NOT NULL,
  label      VARCHAR(80)  NOT NULL,
  base_url   VARCHAR(190) NULL,
  live       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_sites (tenant_id, brand_id, label),
  CONSTRAINT fk_of_sites_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_sites_brand  FOREIGN KEY (brand_id) REFERENCES of_brands (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Witryny';

CREATE TABLE IF NOT EXISTS of_pages (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id    BIGINT UNSIGNED NOT NULL,
  slug       VARCHAR(30) NOT NULL COMMENT 'home | oferta | portfolio | omnie | kontakt | form',
  label      VARCHAR(60) NOT NULL,
  tab_anchor VARCHAR(30) NOT NULL DEFAULT '',
  visible    TINYINT(1)  NOT NULL DEFAULT 1,
  sort       SMALLINT    NOT NULL DEFAULT 0,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_pages (site_id, slug),
  CONSTRAINT fk_of_pages_site FOREIGN KEY (site_id) REFERENCES of_sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Strony witryny';

CREATE TABLE IF NOT EXISTS of_page_sections (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  page_id              BIGINT UNSIGNED NOT NULL,
  sort                 SMALLINT    NOT NULL DEFAULT 0,
  skey                 VARCHAR(40) NOT NULL,
  title                VARCHAR(80) NOT NULL,
  kind                 VARCHAR(12) NOT NULL COMMENT 'tekst | lista | galeria | zdjęcie | produkty | link | formularz | kolory | ustawienie',
  default_value        MEDIUMTEXT  NULL,
  draft_value          MEDIUMTEXT  NULL,
  published_value      MEDIUMTEXT  NULL,
  published_at         DATETIME    NULL,
  published_by_user_id BIGINT UNSIGNED NULL,
  created_at           DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_psect (page_id, skey),
  KEY ix_of_psect_sort (page_id, sort),
  CONSTRAINT fk_of_psect_page FOREIGN KEY (page_id) REFERENCES of_pages (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_psect_user FOREIGN KEY (published_by_user_id) REFERENCES of_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Sekcje stron (wersja wyjściowa / szkic / opublikowana)';

CREATE TABLE IF NOT EXISTS of_page_section_photos (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  section_id BIGINT UNSIGNED NOT NULL,
  media_id   BIGINT UNSIGNED NOT NULL,
  sort       SMALLINT    NOT NULL DEFAULT 0 COMMENT 'pierwsze = okładka',
  state      VARCHAR(10) NOT NULL DEFAULT 'published' COMMENT 'draft | published',
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_psphoto (section_id, media_id, state),
  CONSTRAINT fk_of_psphoto_section FOREIGN KEY (section_id) REFERENCES of_page_sections (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_psphoto_media   FOREIGN KEY (media_id) REFERENCES of_media (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Zdjęcia sekcji';

CREATE TABLE IF NOT EXISTS of_realisations (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  event_id          BIGINT UNSIGNED NOT NULL,
  mood              VARCHAR(24)  NULL COMMENT 'bez zgrzytów | dobrze, z poprawkami | było ciężko | nie powtarzam',
  notes_internal    TEXT         NULL,
  team_text         VARCHAR(120) NULL,
  distance_km       DECIMAL(6,1) NULL,
  weather           VARCHAR(80)  NULL,
  value_gr          INT          NULL,
  guests_count      SMALLINT     NULL,
  closed_at         DATETIME     NULL,
  closed_by_user_id BIGINT UNSIGNED NULL,
  in_portfolio      TINYINT(1)   NOT NULL DEFAULT 0,
  published_at      DATETIME     NULL,
  portfolio_title   VARCHAR(160) NULL,
  portfolio_sort    INT          NOT NULL DEFAULT 0,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_realis_event (event_id),
  KEY ix_of_realis_portfolio (tenant_id, in_portfolio, portfolio_sort),
  CONSTRAINT fk_of_realis_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_realis_event  FOREIGN KEY (event_id) REFERENCES of_events (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_realis_user   FOREIGN KEY (closed_by_user_id) REFERENCES of_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Realizacje (po weselu)';

CREATE TABLE IF NOT EXISTS of_tags (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  group_code VARCHAR(12) NOT NULL COMMENT 'typ | dekoracja',
  label      VARCHAR(60) NOT NULL,
  sort       SMALLINT    NOT NULL DEFAULT 0,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_tags (tenant_id, group_code, label),
  CONSTRAINT fk_of_tags_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Słowniki tagów realizacji';

CREATE TABLE IF NOT EXISTS of_realisation_tags (
  realisation_id BIGINT UNSIGNED NOT NULL,
  tag_id         BIGINT UNSIGNED NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (realisation_id, tag_id),
  KEY ix_of_realtag_tag (tag_id),
  CONSTRAINT fk_of_realtag_real FOREIGN KEY (realisation_id) REFERENCES of_realisations (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_realtag_tag  FOREIGN KEY (tag_id) REFERENCES of_tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Tagi realizacji';

CREATE TABLE IF NOT EXISTS of_realisation_photos (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  realisation_id BIGINT UNSIGNED NOT NULL,
  slot           VARCHAR(10) NOT NULL COMMENT 'okladka | ceremonia | sala | detale | extra',
  media_id       BIGINT UNSIGNED NOT NULL,
  sort           SMALLINT    NOT NULL DEFAULT 0,
  created_at     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_realphoto (realisation_id, slot, sort),
  CONSTRAINT fk_of_realphoto_real  FOREIGN KEY (realisation_id) REFERENCES of_realisations (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_realphoto_media FOREIGN KEY (media_id) REFERENCES of_media (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Zdjęcia realizacji';

-- ---------------------------------------------------------------------
-- 3.14 Module « poszła w las » (sklep, sezony, szybka wycena) : NON CRÉÉ
--      (optionnel, hors périmètre — voir SCHEMA.md).
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- 3.15 Scenariusz rozmowy (asystent)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS of_call_scripts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  brand_id        BIGINT UNSIGNED NOT NULL,
  name            VARCHAR(80) NOT NULL,
  base_package_id BIGINT UNSIGNED NULL,
  base_price_gr   INT         NOT NULL DEFAULT 267500 COMMENT 'decyzja 4: = suma pozycji pakietu bazowego',
  is_default      TINYINT(1)  NOT NULL DEFAULT 0,
  created_at      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_cscripts (tenant_id, brand_id, name),
  CONSTRAINT fk_of_cscripts_tenant  FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_cscripts_brand   FOREIGN KEY (brand_id) REFERENCES of_brands (id),
  CONSTRAINT fk_of_cscripts_package FOREIGN KEY (base_package_id) REFERENCES of_packages (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Scenariusze rozmowy';

CREATE TABLE IF NOT EXISTS of_call_script_steps (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  script_id  BIGINT UNSIGNED NOT NULL,
  sort       SMALLINT    NOT NULL DEFAULT 0,
  skey       VARCHAR(20) NOT NULL,
  kind       VARCHAR(8)  NOT NULL COMMENT 'intro | talk | budget | library | summary',
  title      VARCHAR(80) NOT NULL,
  minutes    TINYINT     NOT NULL DEFAULT 3,
  say_text   TEXT        NULL,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_of_csstep (script_id, skey),
  CONSTRAINT fk_of_csstep_script FOREIGN KEY (script_id) REFERENCES of_call_scripts (id) ON DELETE CASCADE,
  CONSTRAINT ck_of_csstep_kind CHECK (kind IN ('intro','talk','budget','library','summary')),
  CONSTRAINT ck_of_csstep_min  CHECK (minutes BETWEEN 1 AND 60)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Kroki scenariusza';

CREATE TABLE IF NOT EXISTS of_call_script_points (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  step_id    BIGINT UNSIGNED NOT NULL,
  sort       SMALLINT     NOT NULL DEFAULT 0,
  text       VARCHAR(255) NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_cspoint (step_id, sort),
  CONSTRAINT fk_of_cspoint_step FOREIGN KEY (step_id) REFERENCES of_call_script_steps (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Punkty do odhaczenia';

CREATE TABLE IF NOT EXISTS of_call_script_step_items (
  step_id           BIGINT UNSIGNED NOT NULL,
  catalogue_item_id BIGINT UNSIGNED NOT NULL,
  sort              SMALLINT NOT NULL DEFAULT 0,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (step_id, catalogue_item_id),
  KEY ix_of_csitem_item (catalogue_item_id),
  CONSTRAINT fk_of_csitem_step FOREIGN KEY (step_id) REFERENCES of_call_script_steps (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_csitem_item FOREIGN KEY (catalogue_item_id) REFERENCES of_catalogue_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Opcje do pokazania parze na etapie';

-- ---------------------------------------------------------------------
-- 3.16 Audyt
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS of_audit_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NULL,
  user_id    BIGINT UNSIGNED NULL,
  entity     VARCHAR(30) NOT NULL,
  entity_id  BIGINT      NOT NULL,
  action     VARCHAR(30) NOT NULL,
  diff_json  JSON        NULL,
  ip         VARCHAR(45) NULL,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_of_audit (tenant_id, entity, entity_id),
  CONSTRAINT fk_of_audit_tenant FOREIGN KEY (tenant_id) REFERENCES of_tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_of_audit_user   FOREIGN KEY (user_id) REFERENCES of_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci COMMENT='Dziennik audytu';

SET FOREIGN_KEY_CHECKS = 1;
