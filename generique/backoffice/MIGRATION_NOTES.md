# MIGRATION_NOTES — `backoffice_franchisor` → vraie DB

> **État actuel — base `webshop_mrszoko`, tables préfixées `wsm_`.** Le backend
> vit désormais **dans ce repo** sous `php-api/` : routeur PHP + schéma
> `wsm_*` (MySQL canonique `php-api/schema/webshop_mrszoko.mysql.sql` + miroir
> SQLite pour tourner/tester sans serveur) + seed + CLI `migrate.php` + **module
> livraison** (create → assigner → confirmer QR/PIN, piste d'audit) + test
> end-to-end (`php-api/tests/e2e_delivery.php`, 23 assertions vertes). Le tableau
> ci-dessous décrit la génération précédente (`ws_*`) ; la correspondance est
> 1:1 avec les tables `wsm_*` (mêmes colonnes, préfixe `wsm_`). Voir
> `php-api/README.md` pour la liste d'endpoints à jour et les variables d'env.


Comment le back-office franchisor est branché sur la vraie base, et ce qu'il
faut côté serveur pour l'activer. **Aucun changement de design / UX / logique** :
seule la *source* de la donnée passe du seed à l'API PHP partagée.

## Architecture du câblage

```
back_office_ws_franchisor (front)                webshop/php-api (backend, MÊME base que webshop + franchisee)
  api-config.js  → window.__FR {base, token}       GET <base>/franchisor/kpis
  bo_server.js   → BOServer.hydrate()  ───────────▶     …/shops · …/catalog · …/vouchers
     fetch <base>/franchisor/<table>                    …/pricing-rules · …/params
     (X-Admin-Token), repli seed par table              …/email-templates · …/users · …/audit
```

- **base** = same-origin `<origin>/mrszoko/backoffice/api` (dérivée = dossier de la page +
  `/api`). Sur `*.github.io` ou API injoignable → **mode seed**.
- **token** = `localStorage['adminToken']` (partagé par origine avec l'admin
  webshop). Override de test : `?api=<url>&token=<jeton>`.
- **Repli** : toute table en erreur / 401 / absente garde son seed → le rendu ne
  casse jamais (vérifié : avec jeton → données API ; sans jeton → seed).

## Endpoints ajoutés (`php-api/index.php`, gardés `require_admin()`)

| Méthode | Route | Source | Shape renvoyée (= attendue par le front) |
| --- | --- | --- | --- |
| GET | `/franchisor/kpis` | agrégat `ws_orders` + `ws_shops` | `[{label,value,valColor,delta,deltaColor}]` |
| GET | `/franchisor/shops` | `ws_shops` (+ CA `ws_orders`) | `[{id,nom,ville,web,contrat,act,caShop,caOffice,adoption,accent}]` |
| GET | `/franchisor/catalog` | `ws_categories`,`ws_products`,`ws_season` | `[{cat,prods:[{id,nom,prix,statut,bw,bm,ad,saison}]}]` |
| GET | `/franchisor/vouchers` | `ws_vouchers` | `[{code,valeur,type,validite}]` |
| GET | `/franchisor/pricing-rules` | `ws_pricing_rules` (shop_id NULL) | `[{nom,cible,effet}]` |
| GET | `/franchisor/params` | `ws_param` | `[{cle,type,val}]` |
| GET | `/franchisor/email-templates` | `ws_email_templates` | `[{cle,langue,sujet}]` |
| GET | `/franchisor/users` | `bo_users` | `[{nom,email,role,portee,act}]` |
| GET | `/franchisor/audit` | `bo_audit` | `[{ts,user,verb,entity,shop}]` |
| GET | `/franchisor/menus` | `ws_products` + `ws_bundles`→`slots`→`choices` + `ws_categories.menu_default` | objet `{_categories, <pid>:{productName,category,menuOverride,basePrice,baseCost,bundles[…]}}` (= DB du menu builder) |

`shops` lit `contrat` + `webshop_enabled` réels ; `catalog` lit `brand_whitelist`
(bw) + `brand_mandatory` (bm) réels, adoption = % boutiques ne l'excluant pas
(`ws_product_shops`). Le menu builder est hydraté via `window.__FR_MENUS`
(pré-chargé avant boot) ; `menu_api.js` prend la DB serveur sinon repli seed.

## Migration DB (`php-api/migrations/0003_franchisor_backoffice.sql`)

Idempotente, additive, compatible MySQL 8 (garde `information_schema` + `PREPARE`,
pas de `ADD COLUMN IF NOT EXISTS`). Appliquée une seule fois par `migrate.sh`.

- **Colonnes ajoutées** (seules réellement manquantes) : `ws_categories.menu_default` ·
  `ws_products.menu_override`,`base_cost`,`brand_whitelist` · `ws_shops.contrat`,`webshop_enabled` ·
  `ws_bundle_slots.kind`,`min_select`,`max_select` · `ws_bundle_slot_choices.cost`.
- **Tables NON recréées** : `bo_users`, `bo_user_shops`, `bo_audit`,
  `ws_email_templates` et `ws_products.brand_mandatory` **existent déjà**
  (canonique `backend/schema/` : `ws_schema.sql` + `alter-bo-brand-comms.sql` +
  `alter-product-brand-flags.sql`). Les endpoints lisent leurs **vraies colonnes**
  (`ws_email_templates.tpl_key/lang/subject`, `bo_users.display_name/role`,
  `bo_audit.action/entity/entity_id/user_id`). Aucun seed injecté dans ces tables
  d'auth/templates : si vides en prod, les écrans Utilisateurs / Communications /
  Audit s'affichent vides (honnête) jusqu'à peuplement réel.

## Pour activer côté serveur (config uniquement, pas de code)

1. **Déployer le webshop** (push `main`) → `migrate.sh` crée les tables/colonnes,
   les routes `/franchisor/*` deviennent disponibles sous `<origin>/mrszoko/backoffice/api`.
2. **`admin_token`** doit être configuré dans `php-api/config.php` (déjà requis
   par tout `/admin/*`). Le front l'envoie via `X-Admin-Token`.
3. **Déployer le franchisor** (déjà fait) → il hydrate tout seul dès que l'API
   répond et qu'un jeton admin est présent (`localStorage['adminToken']`, ou
   `?token=…` la première fois).

Rien d'autre : brancher = déployer + jeton. Aucune variable d'env applicative
nouvelle (la base URL est same-origin, les creds DB sont ceux du webshop).

## Cohérence cross-projets 🔗

`base` = `<origin>/mrszoko/backoffice/api` (l'app est autonome, servie sous `/mrszoko`).
Les données partagées (boutiques, catalogue, bons, commandes/CA)
sortent des **mêmes tables** (`ws_shops`, `ws_products`, `ws_vouchers`,
`ws_orders`). Pas de source divergente.

## Lecture : tout est branché MySQL

Tous les écrans (KPIs, boutiques, catalogue, promos, communications,
utilisateurs, audit **et** menu builder) lisent la vraie base. Aucun placeholder :
`contrat`, `webshop_enabled`, `brand_mandatory`, `brand_whitelist` sont des
colonnes réelles ; KPIs & CA agrégés depuis `ws_orders`.

## Reste à faire (écritures — incrément suivant)

- **Écritures** : les toggles / formulaires / mutations du menu builder
  persistent encore en `localStorage` (simulation). Les endpoints ajoutés sont
  en **lecture** ; au rechargement, la donnée serveur fait foi. Les `POST/PUT`
  franchisor (persister toggles, CRUD, arbre formules) sont l'étape suivante —
  ils réutiliseront `X-Admin-Token` et les mêmes tables.
- **KPI « adoption whitelist »** : proxy (part de boutiques en ligne) tant qu'un
  indicateur d'adoption dédié n'est pas défini.
