# Ola flora — schéma MariaDB et données de départ

Source : spécification fonctionnelle (§3 schéma relationnel, §4 seed, §5 contradictions).
Fichiers : `schema.sql` (82 tables, 191 clés étrangères) et `seed.sql`. Aucun code applicatif.
Testé sur MariaDB 10.11 (compatible 10.6+), InnoDB, `utf8mb4` / `utf8mb4_polish_ci`.

## Lancer

```sh
mariadb -uroot olaflora_test < schema.sql
mariadb -uroot olaflora_test < seed.sql
```

Les deux fichiers sont **idempotents** : `CREATE TABLE IF NOT EXISTS`, aucun `DROP` ;
`INSERT IGNORE` avec identifiants explicites et clés `UNIQUE` naturelles. Relancer ne
change rien (vérifié : `CHECKSUM TABLE` identique avant/après une nouvelle exécution).
Chaque fichier commence par `SET NAMES utf8mb4` : ne pas le retirer, sinon les accents
polonais sont abîmés à l'import.

`schema.sql` désactive `FOREIGN_KEY_CHECKS` le temps des `CREATE` (références circulaires :
`of_events ↔ of_offers`, `of_brands → of_media`, `of_inquiries → of_mail_messages`…) puis le
remet à 1. `seed.sql` s'exécute avec les contrôles actifs (ordre d'insertion = dépendances).

## Groupes de tables

| groupe (§ spec) | tables |
|---|---|
| Plateforme, utilisateurs, marques (3.1) | `of_plans`, `of_tenants`, `of_subscription_charges`, `of_platform_settings`, `of_users`, `of_impersonation_log`, `of_brands`, `of_number_sequences` |
| Réglages, intégrations (3.2) | `of_settings`, `of_integrations`, **`of_stages`** (ajout) |
| Clients, lieux (3.3) | `of_clients`, `of_venues`, `of_lookup_memory` |
| Formulaire et demandes (3.4) | `of_form_questions`, `_fields`, `_options`, `of_palettes`, `of_accent_colors`, `of_color_library`, `of_inquiries`, `of_inquiry_answers` |
| Catalogue, paquets, ressources (3.5) | `of_catalogue_categories`, `_items`, `_upgrades`, `of_packages`, `of_package_items`, `of_suppliers`, `of_resources`, `of_equipment_reservations` |
| Dossiers / mariages (3.6) | `of_events`, `of_event_stage_log`, `of_staff`, `of_event_team`, `of_event_schedule` |
| Offres (3.7) | `of_offers`, `of_offer_lines`, `of_offer_shown_items`, `of_offer_confirmations`, `of_offer_sends`, `of_calls`, `of_call_checks` |
| Contrat, échéances, paiements (3.8) | `of_document_types`, `of_contract_clauses`, `of_installment_templates`, `of_contracts`, `of_payment_schedule`, `of_payments`, `of_invoices`, `of_doc_variables` |
| Rétroplanning (3.9) | `of_retro_templates`, `of_retro_template_steps`, `of_event_tasks` |
| Cartes de mission (3.10) | `of_mission_templates`, `_template_tasks`, `of_missions`, `of_mission_tasks`, `of_mission_kit` |
| Achats, finances (3.11) | `of_purchase_orders`, `of_shopping_items`, `of_expenses` |
| Courrier, automatismes, SMS (3.12) | `of_mail_templates`, `of_mail_threads`, `of_mail_messages`, `of_mail_attachments`, `of_automation_rules`, `of_automation_runs`, `of_sms_messages` |
| CMS, médias, portfolio (3.13) | `of_media`, `of_media_blobs`, `of_sites`, `of_pages`, `of_page_sections`, `of_page_section_photos`, `of_realisations`, `of_tags`, `of_realisation_tags`, `of_realisation_photos` |
| Assistant d'appel (3.15) | `of_call_scripts`, `_steps`, `_points`, `_step_items` |
| Audit (3.16) | `of_audit_log` |

Conventions : `id BIGINT UNSIGNED AUTO_INCREMENT`, `created_at` / `updated_at` partout ;
montants en grosze (`*_gr`, INT) ; « enums » en `VARCHAR` avec slugs ASCII, valeurs admises
en `COMMENT` et `CHECK` sur les statuts clés (étape, offre, demande, échéance, type de
question…). `tenant_id` (ON DELETE CASCADE) est sur toutes les tables racines métier comme
le prévoit la spec ; les tables de détail (lignes d'offre, étapes, réponses, tâches…) sont
rattachées au tenant par leur parent, en cascade.

## Les 5 décisions du client et où elles vivent

1. **Une seule chaîne de 7 étapes** — table ajoutée `of_stages` (seed : 7 étapes + `odrzucone`
   hors tableau). Slugs et libellés = modèle recommandé §2.3 (`zapytanie`, `rozmowa`,
   `oferta_wyslana`, `oferta_zaakceptowana`, `zadatek_oplacony`, `doplata_oplacona`,
   `zrealizowane`) ; la colonne `short_label` porte la formulation du client (zapytanie →
   wycena → wysłana → zaakceptowana → zadatek (opłacone) → przygotowania → zrealizowane) ;
   `view_all_label` / `funnel_label` donnent les vues « wszystko » (5) et « lejek » (4).
   `CHECK` sur `of_events.stage`. Les automatismes (`of_automation_rules.trigger_stage`)
   utilisent ces slugs ; la relance « podziękowanie D+2 » est rattachée à `zrealizowane`
   (§4.4 la plaçait sur « dopłata opłacona »).
2. **Validité de l'offre 30 jours** — `of_settings.offer_validity_days = 30` ; conditions PDF
   (`offer_pdf_terms_json`) cohérentes ; le SMS « Oferta wygasa za 7 dni » passe de D+7 à
   **D+23** pour rester vrai.
3. **3 échéances 30 / 35 / 35** — `of_installment_templates` : I zadatek 30 % à la signature
   (3 jours ouvrés), II 35 % D-90, III 35 % D-14 (Σ = 100) ; `deposit_pct = 30` (réglage et
   défaut de `of_offers.deposit_pct`). Les textes qui disaient « reste 14 jours avant »
   (mails `oferta_weselna`, `oferta_kreator`, conditions PDF) annoncent désormais les deux
   tranches de 35 %.
4. **Prix du paquet de base = somme des articles = 267 500 gr** — `of_packages.fixed_price_gr`
   est `NULL` pour tous les paquets (= somme des positions) ; `of_call_scripts.base_price_gr`
   et le réglage `call_base_price_gr` = 267500.
5. **Un seul tenant, structure multi-tenant conservée** — `of_tenants` id 1 « Ola flora »
   (plan `studio`, actif), marque id 1 « ola flora » (`of`) ; les 3 plans plateforme et les
   réglages Stripe (vides) sont semés.

## Choix pour les points ambigus

- **Kreator (Kamelia / Piwonia / Magnolia)** : ses positions sont rattachées au catalogue ;
  « ołtarz 1 350 zł » = version améliorée (`of_package_items.use_upgrade = 1`, colonne
  ajoutée), « brama / ścianka 2 400 zł » = łuk kwiatowy (OF-CER-03).
- **Modèles de mail** : un seul système, syntaxe `{{x}}` ; les variables de la Poczta sont
  renommées (`{imiona}`→`{{para}}`, `{data}`→`{{data_wydarzenia}}`, `{telefon pracowni}`→
  `{{telefon}}`). Les 11 textes (3 Paramètres + 7 Poczta + texte du kreator) sont gardés,
  chacun avec un code distinct — aucun texte du client perdu.
- **Automatismes** : AUTO (par étape) et RULES0 (par événement) fusionnés dans
  `of_automation_rules` (14 règles, colonne `code` ajoutée) ; « oferta bez odpowiedzi +4 j »
  fusionnée avec la relance D+3 de l'étape `oferta_wyslana`.
- **Rétroplanning** : un seul modèle = RETRO_TASKS ∪ RELANS, trié par offset (16 étapes,
  `kind` zadanie / relanca / platnosc) ; « właścicielka (Ola) » → rôle « właścicielka ».
- **Cartes de mission** : fenêtre, rendez-vous, transport et matériel du modèle §4.6 ajoutés
  à `of_mission_templates` (colonnes absentes de §3.10).
- **Formulaire** : indices du wizard retenus (version longue) ; photos des étapes 5 et 8 =
  celles du back office ; `imiona`, `data`, `kontakt` obligatoires (§5.17 : sinon un
  formulaire vide part).
- **Petits écarts au §3** : `of_offer_lines.section` en `VARCHAR(16)` (`dodatek_klienta` ne
  tient pas en 12) ; `of_settings` reçoit `label` et une colonne générée `brand_key` pour que
  l'unicité (tenant, marque, clé) tienne aussi quand `brand_id` est NULL ;
  `of_installment_templates.kind` ajouté ; `UNIQUE (tenant_id, path)` sur `of_media`.
- Contenu sans table dédiée dans la spec (dodatki klienta, défauts du kreator, chips de
  budget, conditions PDF / offre en ligne, paragraphes du contrat, mentions, harmonogram
  type, types de réponses) : réglages `of_settings` (texte ou JSON).
- `of_number_sequences` n'est pas semée : l'application crée la ligne de l'année au premier
  numéro.

## Données personnelles

Aucune : NIP, adresse, téléphone, e-mail, compte bancaire, ID Tpay, e-mail cible du
formulaire, adresse de l'atelier, expéditeur SMTP et e-mail du propriétaire restent vides.
Pas d'utilisateur ni d'équipe semés. Restent : la marque (« Ola flora », olaflora.pl,
@olaflora), la signature de marque « Ola / Ola flora » et des lieux publics (sans adresse).
L'exemple `anna@example.com` est un placeholder de champ.

## Non créé

- **Module « poszła w las »** (§3.14 / §4.16 : `of_shop_categories`, `of_shop_products`,
  `of_seasons`, `of_season_products`, `of_season_photos`, `of_quick_quotes` et la colonne
  `of_offer_lines.shop_product_id`) : optionnel, aucune table du cœur n'en dépend. La marque
  `pwl` et les fournisseurs propres au module ne sont pas semés.
- Table optionnelle `of_units` (unités = liste fixe en `CHECK`).
- Pied de page du site : signalé « manquant » dans la spec, sans contenu à semer.
- Fichiers photo : la spec indique qu'ils ne sont pas dans le paquet ; `of_media` référence
  les chemins (20 photos du catalogue + 75 de la galerie publique) avec `bytes = 0`.
