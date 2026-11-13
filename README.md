# Plateforme de webshops — un code, plusieurs boutiques

Szoko Shop, MyCobotBuddy, Poszła w las… partagent le même code ; chacune a
son dossier, son design system, son catalogue et son domaine.

```
generique/       le code commun : vitrine (shop/), console (backoffice/), API PHP
webshops/        une boutique par dossier — boutique.json, design-system/, katalog/
  szoko/  mycobot/  poszla-w-las/
serveur/         le nouveau serveur multi-boutiques : installer.sh, wsm, mode d'emploi
tools/           compilation des design systems, audits, ancien déploiement
docs/            le plan
```

- **Mettre en ligne sur le nouveau serveur** → [`serveur/README.md`](serveur/README.md)
- **Ajouter une boutique** → [`webshops/README.md`](webshops/README.md)
- **Design systems** (contrat de jetons) → [`webshops/DESIGN-SYSTEMS.md`](webshops/DESIGN-SYSTEMS.md)
- **Le plan** → [`docs/PLAN-multi-boutiques.md`](docs/PLAN-multi-boutiques.md)

### L'ancien serveur (Szoko seul, jusqu'au déménagement)

Déployé sous **`/mrszoko`** (`http://185.180.206.46/mrszoko`) par
[`.github/workflows/deploy.yml`](.github/workflows/deploy.yml) à chaque push
sur `main` ; les adresses `/mrszoko/…` ne changent pas.

Start with [`generique/README.md`](generique/README.md).

## Vérifier

Quatre outils, et ils ne se remplacent pas : chacun voit une classe de panne
que les autres ne voient pas.

| Outil | Ce qu'il prouve | Ce qu'il ne voit PAS |
| --- | --- | --- |
| `php tests/e2e_*.php` (depuis `generique/backoffice/php-api/`) | les **règles** — TVA, remises, numérotation, idempotence | qu'un écran s'affiche ou qu'un bouton marche |
| `node tools/audit-ui.js <session>` | les **écrans** — 500, débordement, cible tactile, champ sans étiquette, padding, à deux largeurs | ce qui se passe quand on clique |
| `node tools/audit-flux.js <session> <sqlite>` | les **gestes** — chaque flux mesuré par son effet EN BASE, pas par son bandeau | ce que le serveur de production, lui, fera |
| `php tools/rapport.php` | l'**état réel** — ce qui bloque, ce qui gêne, et ce qui n'a pas pu être vérifié | rien de tout ce qui précède |

Les deux harnais Chromium veulent l'arbre tel qu'il est DÉPLOYÉ (`php-api`
renommé en `api`) et, surtout, une base **peuplée** :

```bash
# arbre au format déployé, servi sur 8093, avec les données de développement
cp -r generique/backoffice/php-api/data <site>/backoffice/api/data
php -S localhost:8093 -t <site>
```

Sans ces données, tous les écrans s'ouvrent sur leur état vide, l'audit les
traverse en une seconde et les déclare bons — sans avoir regardé une seule
ligne de tableau. **Un vert obtenu sur une base vide ne prouve rien.**

Avant de toucher au déploiement : `./tools/check-workflow.sh`. GitHub rejette
un fichier de workflow dont une expression dépasse 21 000 caractères **en
silence** — zéro job, aucun message. Cinq déploiements y sont passés avant
qu'on le comprenne.
