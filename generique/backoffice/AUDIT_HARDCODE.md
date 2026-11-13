# AUDIT_HARDCODE — `backoffice_franchisor`

> PHASE B · étapes 1–2 (cartographie + audit). **Aucun refactor appliqué.**
> À valider avant câblage data-layer.

## 0. Architecture actuelle (important)

Ce back-office est **déjà abstrait** : aucun écran ne contient de donnée métier
en dur dans le JSX. Toute la présentation lit la donnée via **une seule couche** :

| Couche existante | Rôle | Lu par |
| --- | --- | --- |
| `bo_server.js` → `window.BOServer.table(name)` | « serveur » simulé, seed → `localStorage` | tous les écrans via `SRV(name)` |
| `menu_api.js` + `menu_seed.js` | « serveur » du menu builder (bundles/slots/choices, résolution prix/marge) | écran Menus (import dynamique) |

**Conséquence** : le hardcode métier est concentré dans **2 fichiers seed**
(`bo_server.js`, `menu_seed.js`). Le câblage DB = remplacer le `SEED` interne de
ces deux « serveurs » par des appels HTTP derrière une variable d'env — **sans
toucher au JSX, au style, ni à la logique** (`renderVals`, toggles, forms).
C'est exactement l'objectif PHASE B, et l'archi s'y prête sans réécriture.

---

## 1. Cartographie

- **Écrans** (nav Pilotage / Paramétrage) : Tableau de bord réseau · Boutiques ·
  Catalogue · Menus & formules · Promotions réseau · Communications ·
  Utilisateurs & rôles · Journal d'audit.
- **Couche data** : `window.BOServer` (`table/all/getParam/setParam/save/reset`)
  + module ES `menu_api.js`. Pas de `fetch` réel aujourd'hui — seed + `localStorage`.
- **Tables cibles** : déduites des **labels que le design affiche déjà lui-même**
  (badges `ws_shops ← franchise_shops`, `ws_products · product_categories`,
  `ws_vouchers`, `ws_pricing_rules`, `ws_param`, `ws_email_templates`,
  `bo_users · bo_user_shops`, `bo_audit`, et les commentaires SQL de l'écran
  Menus : `ws_categories.menu_default`, `ws_products.menu_override`,
  `ws_bundles / ws_bundle_slots / ws_bundle_slot_choices`). Ce ne sont donc pas
  des devinettes — sauf les 3 cas marqués **⚠ À CONFIRMER** ci-dessous.

---

## 2. Audit du hardcode

### 2a. Donnée métier → DB (le vrai périmètre du câblage)

| Fichier | Ligne(s) | Donnée en dur | Table / endpoint cible proposé | Type |
| --- | --- | --- | --- | --- |
| `bo_server.js` | 7–14 | `kpis` (CA réseau, CA boutique, CA livraison, adoption, cmd/jour…) | `GET /api/v1/franchisor/kpis` (agrégat) — **🔗 partagé** | métier |
| `bo_server.js` | 15–21 | `shops` (5 boutiques, CA, adoption, contrat, web/act) | `GET /api/v1/shops` ← `ws_shops`/`franchise_shops` — **🔗 partagé** | métier |
| `bo_server.js` | 22–42 | `catalog` (catégories › produits, prix, saison, bw/bm, adoption) | `GET /api/v1/catalog` ← `ws_products·product_categories·ws_season` | métier |
| `bo_server.js` | 43–47 | `vouchers` (bons marque) | `GET /api/v1/vouchers` ← `ws_vouchers` | métier |
| `bo_server.js` | 48–52 | `pricing_rules` | `GET /api/v1/pricing-rules` ← `ws_pricing_rules` | métier |
| `bo_server.js` | 53–60 | `params` (config marque) | `GET/PUT /api/v1/params` ← `ws_param` | métier (config) |
| `bo_server.js` | 61–67 | `email_templates` | `GET /api/v1/email-templates` ← `ws_email_templates` | métier |
| `bo_server.js` | 68–73 | `users` (RBAC) | `GET /api/v1/users` ← `bo_users`+`bo_user_shops` | métier |
| `bo_server.js` | 74–80 | `audit` | `GET /api/v1/audit` ← `bo_audit` | métier |
| `menu_seed.js` | `_categories` | `menu_default` par catégorie | `ws_categories.menu_default` | métier |
| `menu_seed.js` | `p-*` | produits menu (`menuOverride`, `basePrice`, `baseCost`) | `ws_products` (menu_override/price) | métier |
| `menu_seed.js` | `bundles/slots/choices` | arbre formules | `ws_bundles / ws_bundle_slots / ws_bundle_slot_choices` | métier |

### 2b. Listes de référence / enums en dur (dans la logique du composant)

| Fichier | Ligne(s) | Donnée en dur | Cible | Type |
| --- | --- | --- | --- | --- |
| `…dc.html` | 610 | `villes` (Bruxelles, Anderlecht, Uccle…) | table réf. villes / dérivé `shops` | réf. → DB |
| `…dc.html` | 611 / 621 | `cats` (Boulangerie, Pâtisserie…) | `product_categories` | réf. → DB |
| `…dc.html` | 684 | `scopeOpts` (« Réseau (14 boutiques) », bxl/and/ucc) | dérivé de `shops` (count + liste) | réf. → DB |
| `…dc.html` | 631 | voucher `shops` (checklist boutiques) | dérivé de `shops` | réf. → DB |
| `…dc.html` | 616 | `contrat` (Franchise/Succursale/Master) | enum réf. contrat | enum (à trancher) |
| `…dc.html` | 617 | `accent` (Ruby Red/Abricot/Old Copper) | enum branding | **UI pur** (garde) |
| `…dc.html` | 626 | voucher `type` (percent/amount/free_delivery/add_office) | enum `ws_vouchers.type` | enum (garde/DB) |
| `…dc.html` | 642 | user `role` (Siège/Franchise) | enum RBAC | enum (garde/DB) |
| `…dc.html` | 646 | template `cle` (order_confirm…) | clés `ws_email_templates` | réf. → DB |
| `…dc.html` | 647 | template `langue` (FR/NL/EN/DE) | enum i18n | enum (garde) |

### 2c. Contexte runtime en dur (ni DB « métier », mais à ne pas laisser figé)

| Fichier | Ligne(s) | Donnée en dur | Cible | Type |
| --- | --- | --- | --- | --- |
| `…dc.html` | 51 | Utilisateur « Sophie Renard · Admin réseau · Siège » | session / `GET /api/v1/me` | session |
| `…dc.html` | 63 | Date « jeu. 17 juil. 2026 » | date runtime (`new Date`) | UI runtime |

### 2d. Constantes UI pures — **restent en dur** (signalées, hors périmètre DB)

Couleurs/tokens (via `--color-*`), styles inline, libellés de colonnes, icônes
SVG, textes d'aide, palette accents (`APRICOT`/`COPPER`), badges tables affichés
comme repère. Aucun câblage.

---

## 3. Points à confirmer AVANT câblage (⚠ ne rien deviner)

1. **Tableau de mapping du handoff Claude Design absent** — le placeholder
   `[COLLER ICI le tableau de mapping]` est vide. Les cibles ci-dessus viennent
   des labels **du design lui-même** ; je les propose, à valider.

2. **🔗 Convention d'endpoints cross-projets (bloquant PHASE C).** Les données
   **partagées** (`kpis`/CA réseau, `shops`, commandes, royalties, food-cost,
   « 6 leviers ») doivent taper sur **les mêmes endpoints / la même base** que
   `back_office_ws_franchisee`. Je **n'ai pas** la convention de nommage de
   l'autre repo sous les yeux → je ne l'invente pas. À me fournir (ou je la lis
   dans le repo franchisee et on la fige comme référence commune).

3. **Tables `fr_*` orphelines dans ce repo.** `bo_server.js` contient
   `fr_alertes`, `fr_live_drivers`, `fr_clients`, `fr_incidents`,
   `fr_rentabilite` (lignes 81–122) qui **ne sont lues par aucun écran
   franchisor** (aucun `SRV('fr_*')`). Ce sont des tables domaine franchisé.
   → soit on les retire d'ici, soit elles restent en source partagée. À trancher.

4. **« 6 leviers de pilotage »** cités dans le playbook : pas d'écran dédié
   identifié côté franchisor pour l'instant (les KPIs couvrent une partie). À
   préciser si un module leviers est attendu ici.

---

## 4. Ce que sera le câblage (PHASE B étape 3 — PAS encore fait)

- Introduire un client HTTP centralisé + base URL derrière **`window.__env`**
  (ou `bo_server.js` lisant `API_BASE_URL`), un seul point par type de donnée.
- `BOServer.table(x)` / `initDB()` → `fetch(API_BASE + endpoint)` avec fallback
  seed en dev ; **le JSX et `renderVals` ne changent pas**.
- Loading / erreur / vide gérés au point d'appel.
- Livrable associé : `MIGRATION_NOTES.md` (endpoints + tables + variables d'env).

**STOP ici** — je ne câble pas tant que les points §3.1 et §3.2 ne sont pas tranchés.
