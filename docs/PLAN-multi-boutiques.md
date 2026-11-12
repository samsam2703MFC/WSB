# Plan — plateforme multi-boutiques

Szoko Shop, mycobotpartner, Candle in the Wind… : plusieurs boutiques qui
partagent le même code, chacune avec son domaine et son design system, et un
Siège (superadmin) qui les crée, les met à jour, encaisse la commission et
tient le journal de maintenance.

## Décisions prises

| Sujet | Décision |
| --- | --- |
| Architecture | Un seul code, **une base de données par boutique** |
| Domaine | **Un domaine propre** par boutique (ex. `candleinthewind.pl`), servi à la racine `/` |
| Design | **Chacune son design system** (couleurs, typo, espacements, rayons, ombres, polices, logo) |
| Commission | **Prélevée automatiquement sur chaque paiement** (Stripe Connect, `application_fee_amount`) |
| Mises à jour | Sandbox → validation dans le Siège → boutique test → toutes les autres, retour arrière automatique |
| Back-office | **Le même pour toutes les boutiques** (Meta, Google, Instagram, marketing…) : seuls le logo au-dessus du rail et les couleurs changent |
| Hébergement | **Un nouveau serveur** (en recherche) — la nouvelle organisation y est montée directement, Szoko y migre en dernier |
| Propriétaires | **Une société par boutique** : son NIP, ses factures (KSeF), ses CGV, son compte Stripe connecté |
| Création | **Un assistant** guide chaque nouvelle boutique : quoi faire, où aller (voir plus bas) |

## Décision encore ouverte

**Le loyer et le seuil (« próg »).** Réponse reçue : « loyer mensuel sous le
próg de xxxx par mois, sinon xxx1 % ». Deux lectures possibles, à confirmer :

- **A. Minimum mensuel** — la commission (x %) est prélevée à chaque paiement ;
  si à la fin du mois elle n'atteint pas xxxx, la boutique paie la différence.
  Exemple : 1 %, minimum 200 zł ; 8 000 zł de ventes → 80 zł prélevés → 120 zł
  facturés en complément ; 50 000 zł de ventes → 500 zł, rien de plus.
- **B. Palier** — sous un seuil de ventes de xxxx par mois, la boutique paie un
  loyer fixe ; au-dessus, elle paie x % à la place.

A se prélève proprement (commission Stripe + un complément en fin de mois) ;
B oblige à rembourser ou à compléter selon le mois. Les montants (xxxx, x %)
se règlent par boutique dans le Siège.

## Point de départ (ce que le dépôt fait déjà)

- Une boutique : PHP + MySQL (base `mrszoko`, tables `wsm_`), déployée dans
  `/var/www/html/mrszoko`.
- Nom de marque en base (`wsm_brand_name()`), logo/images/clés réglables dans
  Ustawienia, secrets dans un `config.local.php` propre à l'installation.
- **La commission automatique existe déjà** : `wsm_stripe_fee()`
  (`mrszoko/backoffice/php-api/stripe.php`) calcule taux × base (+ TVA) et
  l'envoie en `application_fee_amount` à chaque paiement. Le taux vit dans
  `platform.php`, lisible et modifiable par le seul superadmin.
- Couplé à « mrszoko » : le déploiement (`deploy.yml`, ~70 × `/mrszoko`), le
  `.htaccess` de l'API (`RewriteBase /mrszoko/...`), ~30 lignes PHP.
- Design : `tokens.css` est assemblé au déploiement depuis
  `mrszoko/design-system/tokens/`. `shop.css` utilise 98 variables, dont
  **20 sont propres à Szoko** (`--choco-*`, `--cream-*`, `--caramel-*`,
  `--berry-*`, `--gold-*`, `--paper*`, `--white`). Une autre boutique n'aura
  pas de « chocolat » : c'est le premier verrou à lever.

## Organisation sur le serveur

```
/srv/webshops/
  releases/
    2026.11.20-d4e5f6/          ← le code + tous les design systems compilés
  shops/
    szoko/    current -> ../../releases/2026.11.20-d4e5f6
              config.local.php  (base ws_szoko, design_system=szoko, clés…)
              media/            (photos, jamais touchées par un déploiement)
    candle/   current -> ../../releases/2026.11.20-d4e5f6
    mycobot/  current -> ../../releases/2026.11.12-a1b2c3   (gelée exprès)
    sandbox/  current -> dernière version de main
  hq/         ← le Siège
```

Une boutique = un domaine + une base + une config + un dossier de photos. Le
code n'est jamais copié-collé.

## Le design system par boutique

**Le principe : un contrat de jetons.** Le code (pages, composants, CSS de la
boutique) n'utilise QUE des noms sémantiques — `--brand`, `--accent`,
`--bg-page`, `--surface-card`, `--text-strong`, `--font-display`,
`--radius-md`… Chaque design system fournit la valeur de chacun de ces noms à
sa façon. Szoko dit « `--brand` = chocolat », Candle dit « `--brand` = cire
ivoire ». Le code ne sait pas lequel il habille.

```
design-systems/
  contract.json        ← la liste des jetons obligatoires (la seule interface)
  szoko/               ← l'actuel mrszoko/design-system, déplacé
    tokens/*.css  fonts/  assets/logo.*  readme.md
    theme.css          ← (optionnel) retouches propres à la marque
  candle/
  mycobot/
  _neutre/             ← point de départ pour une boutique nouvelle
```

- **Compilation** : chaque release produit `themes/<slug>/tokens.css` (+
  polices et assets). La boutique charge celui de son `design_system`.
- **Contrôle automatique (CI)** — un design system qui oublie un jeton, ou un
  CSS de boutique qui utilise un nom hors contrat (`--choco-700`), fait
  échouer le build. Sans ça, une nouvelle fonction développée sur Szoko
  s'afficherait cassée sur Candle.
- **La sandbox affiche chaque design system** (`?ds=candle`), et l'audit
  visuel existant (`tools/audit-ui.js`) tourne une fois par design system :
  avant de valider une version, on voit qu'elle tient sur TOUTES les marques.
- **Nouveau design system** : importé depuis un export Claude Design (comme
  `mrszoko/design-system/` l'a été), ou créé dans le Siège à partir de
  `_neutre` (couleurs, polices, logo) puis affiné.
- **Limite assumée** : un design system change l'apparence (couleurs, typo,
  formes, logo, retouches CSS), pas la structure des pages. Si une marque
  veut une mise en page vraiment différente, on ajoute une **variante de
  section** au code commun (disponible pour toutes), jamais une copie.

## Commission automatique

- **Un compte Stripe plateforme (le tien)**, chaque boutique = un compte
  connecté. Chaque paiement porte `application_fee_amount = taux × base`
  (+ TVA) : l'argent arrive chez toi au moment où le client paie, sans
  facture à relancer. C'est le code de `wsm_stripe_fee()`, déjà en place.
- **Le taux est décidé par le Siège, pas par la boutique.** Le Siège l'écrit
  dans la config de la boutique (fichier que la console ne sait pas écrire) ;
  la boutique le lit, ne peut pas le modifier, et ne peut pas l'afficher
  autrement qu'en lecture.
- **Historique des taux** : chaque paiement enregistre la commission prélevée
  (`wsm_payments.app_fee`, déjà là) — changer le taux en mars ne réécrit pas
  février.
- **Le Siège rapproche** : volume encaissé et commissions prélevées par
  boutique, comparés aux virements Stripe (`application_fees` de l'API
  Stripe). Un écart = une alerte dans le journal.
- Remboursement : Stripe peut rendre la commission avec
  `refund_application_fee` — règle à choisir (je propose : oui, on ne prend
  pas de commission sur une vente annulée, comme `platform.php` le fait
  déjà).

## Le Siège (application `hq`, base `ws_hq`)

- **Registre** : nom, domaine, design system, propriétaire, compte Stripe,
  version installée, statut (active / maintenance / suspendue / gelée).
- **Commission** : taux et base par boutique, historique, encaissé par mois.
- **Tableau de bord** : chiffre d'affaires, commandes, commission par boutique
  et au total.
- **Journal de maintenance** : déploiements (qui, quand, quelle version,
  résultat), migrations, sauvegardes, erreurs remontées des boutiques
  (« Dziennik błędów »), SSL qui expire, disque, boutique qui ne répond plus,
  notes manuelles.
- **Bouton « Nouvelle boutique »** et **écran « Versions »** (ci-dessous).

**L'agent serveur.** Le Siège tourne en `www-data` et ne crée ni base ni
virtual host. Il dépose une demande dans `hq_jobs` ; un agent root
(cron/systemd, même modèle que `tools/auto-deploy.sh`) l'exécute et écrit le
résultat dans le journal. Tout est aussi en ligne de commande :
`wsm-shop create | upgrade | rollback | backup | freeze`.

## Créer une boutique

Formulaire : nom, domaine, langue par défaut, e-mail du propriétaire, taux de
commission, design system (existant ou nouveau à partir de `_neutre`).

1. Base `ws_<slug>` + utilisateur MySQL dédié.
2. `shops/<slug>/` : `config.local.php` (jeton de service tiré au sort,
   `design_system`, taux), `media/`.
3. `current` → dernière version **validée**.
4. Schéma + contenu « boutique vierge » (catalogue vide, textes pl/uk/en).
5. Compte du propriétaire + lien pour choisir son mot de passe.
6. Virtual host + certificat Let's Encrypt — si le DNS du domaine pointe déjà
   sur le serveur ; sinon la boutique reste « en attente DNS » et l'agent
   réessaie.
7. Lien d'ouverture du compte Stripe connecté envoyé au propriétaire.
8. Vérifications → statut « active ».

## L'assistant de démarrage

Créer la boutique (base, domaine, design system) ne prend que quelques
minutes. La rendre prête à vendre prend des jours, et dépend de choses hors
du code : la page Facebook, le compte Stripe de la société, les clés InPost,
la Search Console… L'assistant dit **quoi faire, dans quel ordre, et où
aller** — un écran « Start » dans la console de chaque boutique, dont le
Siège voit l'avancement (« MyCobotBuddy : 7/14 »).

Chaque étape : pourquoi, où aller (un écran de la console ou un lien
extérieur), et un état **détecté** quand le code peut le constater (clé
Stripe valide, compte connecté actif, produit avec photo, commande test
payée…) — cochée à la main seulement quand rien ne peut le prouver (page
Facebook créée).

1. **Identité** — nom, logo, couleurs (Treści, Ustawienia → Sklep, Wygląd)
2. **Société** — vendeur, NIP, adresse, adresse de retour (Treści, Integracje → Sklep)
3. **Pages légales** — CGV, confidentialité, retours (Strony)
4. **E-mail** — boîte d'envoi de la boutique (Integracje → Poczta)
5. **Paiements** — compte Stripe connecté de la société, actif (Płatności)
6. **Livraison** — InPost / DPD, modes et seuils (Integracje, Wysyłka)
7. **Catalogue** — produits, photos, prix, stock (Produkty, Magazyn)
8. **Facturation** — jeton KSeF, numérotation (KSeF, Faktury)
9. **Facebook & Instagram** — créer la page et le compte pro (lien Meta), pixel et vérification du domaine (Meta)
10. **Google** — Search Console, Merchant Center, flux produits (Widoczność)
11. **Newsletter & SMS** — expéditeur, consentements (Newsletter, SMS)
12. **Test de bout en bout** — commande test payée en mode test, e-mails reçus, étiquette, facture
13. **Ménage d'ouverture** — effacer l'activité de test (`golive.php`, déjà là)
14. **Ouverture** — Stripe en mode réel, annonce

## Sandbox → toutes les boutiques

```
push sur main ─► sandbox (auto, chaque design system)
                    │  tu testes
                    ▼
       Siège : « Valider la version 2026.11.20 »
                    ▼
       boutique test (Szoko) ─► vérifications OK ─► les autres, une par une
                    │ échec
                    ▼
       retour automatique à l'ancienne version + alerte
```

Par boutique : sauvegarde de la base → migrations → bascule du lien
`current` (instantanée) → vérifications → en cas d'échec, ancien lien +
restauration.

Règles : migrations **uniquement additives** (l'ancienne version tourne sur
la nouvelle base, le retour arrière reste possible) ; une boutique peut être
**gelée** sur une version.

## Ordre de travail

| Phase | Contenu | Fini quand |
| --- | --- | --- |
| **0a. Contrat de design — FAIT** | `contract.json` (125 jetons), code sans nom propre à Szoko, `design-systems/szoko/` + `design-systems/mycobot/`, compilation + contrôle dans les 3 déploiements, console aux couleurs de la boutique | Szoko identique au pixel près (34 captures, boutique + console, 2 largeurs) ; MyCobotBuddy habille tout le site et la console sans toucher au code |
| **0b. Code générique** | chemin de base, nom de base et domaine en config, contenu « boutique vierge », `.htaccess` sans `/mrszoko` | une 2e boutique tourne sur le même serveur, montée à la main |
| **1. Versions + ligne de commande** | `releases/ shops/`, `wsm-shop create/upgrade/rollback/backup/freeze`, Szoko migrée dans ce schéma | tout se fait en une commande |
| **2. Siège v1 + assistant** | registre, taux, tableau de bord, journal, « Nouvelle boutique » via l'agent, **assistant de démarrage** dans la console de chaque boutique, avancement visible au Siège | mycobotpartner créée depuis l'écran, guidée jusqu'à l'ouverture |
| **3. Versions** | sandbox multi-design, validation, boutique test, déploiement, retour arrière | un changement validé arrive partout |
| **4. Argent & surveillance** | taux poussé par le Siège, rapprochement Stripe, alertes | rapport mensuel par boutique |
