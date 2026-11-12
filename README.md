# webshop-mrszoko-backoffice

**Mister Szoko** — mono-repo of the brand's web presence, deployed standalone
under **`/mrszoko`** on the server (`http://185.180.206.46/mrszoko`).

| URL | Content | Source |
| --- | --- | --- |
| `/mrszoko/` | redirect → landing | generated at deploy |
| `/mrszoko/landing` | Mister Szoko landing page | [`mrszoko/landing/`](mrszoko/landing/) |
| `/mrszoko/backoffice` | Console marque · Siège (franchisor back-office) | [`mrszoko/backoffice/`](mrszoko/backoffice/) |
| `/mrszoko/backoffice/api` | PHP API (`webshop_mrszoko` DB, `wsm_` tables) | [`mrszoko/backoffice/php-api/`](mrszoko/backoffice/php-api/) |

- **Design system** (imported Claude Design handoff: tokens, components,
  prototypes) — [`design-systems/szoko/`](design-systems/szoko/)
- **Design systems des boutiques** — un code, une marque par boutique :
  [`design-systems/`](design-systems/) (contrat de jetons, Szoko, MyCobotBuddy)
- **Deploy** (SSH/rsync on push to `main`, with on-server verification) —
  [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml)

Start with [`mrszoko/README.md`](mrszoko/README.md).

## Vérifier

Quatre outils, et ils ne se remplacent pas : chacun voit une classe de panne
que les autres ne voient pas.

| Outil | Ce qu'il prouve | Ce qu'il ne voit PAS |
| --- | --- | --- |
| `php tests/e2e_*.php` (depuis `mrszoko/backoffice/php-api/`) | les **règles** — TVA, remises, numérotation, idempotence | qu'un écran s'affiche ou qu'un bouton marche |
| `node tools/audit-ui.js <session>` | les **écrans** — 500, débordement, cible tactile, champ sans étiquette, padding, à deux largeurs | ce qui se passe quand on clique |
| `node tools/audit-flux.js <session> <sqlite>` | les **gestes** — chaque flux mesuré par son effet EN BASE, pas par son bandeau | ce que le serveur de production, lui, fera |
| `php tools/rapport.php` | l'**état réel** — ce qui bloque, ce qui gêne, et ce qui n'a pas pu être vérifié | rien de tout ce qui précède |

Les deux harnais Chromium veulent l'arbre tel qu'il est DÉPLOYÉ (`php-api`
renommé en `api`) et, surtout, une base **peuplée** :

```bash
# arbre au format déployé, servi sur 8093, avec les données de développement
cp -r mrszoko/backoffice/php-api/data <site>/backoffice/api/data
php -S localhost:8093 -t <site>
```

Sans ces données, tous les écrans s'ouvrent sur leur état vide, l'audit les
traverse en une seconde et les déclare bons — sans avoir regardé une seule
ligne de tableau. **Un vert obtenu sur une base vide ne prouve rien.**

Avant de toucher au déploiement : `./tools/check-workflow.sh`. GitHub rejette
un fichier de workflow dont une expression dépasse 21 000 caractères **en
silence** — zéro job, aucun message. Cinq déploiements y sont passés avant
qu'on le comprenne.
