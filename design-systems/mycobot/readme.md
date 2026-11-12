# MyCobotBuddy — design system

Boutique d'accessoires et de formations pour cobots, avec une offre SaaS.

**Source** : « Franchise Generation — Design System » (fourni le 12/11/2026),
partie visuelle seulement — §3 couleurs, §4 typographie, §5 espacements,
rayons, ombres, mouvement, §6 composants, §7 fonds. Le contexte du groupe
(portefeuille food, méthode Hexa, pastilles de marques filles) ne concerne pas
une boutique de cobots et n'est pas repris.

## Ce que la boutique reçoit

| Rôle (contrat) | MyCobotBuddy | Spécification |
| --- | --- | --- |
| `--brand-700` (couleur du logo) | `#0A1C33` | navy-800, logo |
| `--brand-900` (bandes sombres) | `#0A1C33` | « bandes navy pour hero / footer » |
| `--brand-800` (cartes sombres) | `#142C46` | `--bg-dark-card` navy-700 |
| `--action` / `--on-action` (bouton principal) | `#02C9A0` / `#022B25` | Button primary emerald-400, texte emerald-900 |
| `--accent-600` (sur-titres sur clair) | `#05666F` | eyebrow teal-500 |
| `--accent-400` (sur-titres sur sombre) | `#02C9A0` | « emerald-400 sur fond sombre » |
| `--link` / survol | `#0284B2` / `#056B90` | cyan-500 / cyan-600 |
| `--paper` (fond de page) | `#F6F9F8` | `--bg-page` neutral-50 |
| `--paper-soft` (bandes) | `#E3EEEC` | `--bg-surface-alt` neutral-100 |
| `--surface-card` | `#FFFFFF` | `--bg-surface` neutral-0 |
| `--border-default` / `--field-line` | `#C8D7D4` | `--border` neutral-200 |
| `--text-strong` / `--text-muted` | `#0A1C33` / `#5C6B7A` | text-primary / text-secondary |
| `--shadow-tint` | `10, 28, 51` | « teintées navy, jamais noir » |
| `--font-display` / `--font-sans` / `--font-mono` | Archivo / Inter / JetBrains Mono | §4 |
| `--radius-sm/md/lg/pill` | 6 / 10 / 16 / **8** px | §5 — sauf « pill » : pas de pilules, des rectangles aux coins arrondis (décision du 12/11/2026) |
| `--container` | 1120 px | §5 |

Les retouches de composants (`theme.css`) appliquent §6 : boutons à rayon
10 px, bouton secondaire transparent à bordure, cartes blanches bordées sans
agrandissement au survol, sur-titres en capitales teal, barres de progression
au dégradé signature, contour de focus cyan, filet dégradé de 3 px sous
l'en-tête.

## Écarts assumés

- **`--success` = emerald-600 (`#038870`)**, pas `#02C9A0` : en texte sur blanc,
  emerald-400 tombe à 2:1. `#02C9A0` reste la couleur d'action et des pastilles.
- **Espacements** : la grille du code est de 4 px ; les pas de 8 px de la
  spécification (8 / 16 / 24 / 40 / 64) y tombent juste — elle n'est pas
  redéfinie.
- **Ukrainien** : Archivo n'existe pas en cyrillique ; les titres ukrainiens
  prennent la police système (Inter en relais).

## À fournir

- **Le logo.** `assets/logo.svg` est un monogramme PROVISOIRE dessiné pour
  essayer le design system (la spécification cite `assets/logo-mark.png` et
  `assets/logo-full.png`, non fournis). Déposer le vrai fichier ici et changer
  `logo` dans `ds.json` — ou le déposer depuis la console (Ustawienia → Sklep),
  qui l'emporte sur celui du design system.

## Polices

Hébergées ici (`fonts/`), jamais chez Google : Inter et Archivo variables
(100–900), JetBrains Mono 400 et 500, sous-ensembles latin, latin-ext,
cyrillic, cyrillic-ext. Source : paquets npm `@fontsource-variable/inter`,
`@fontsource-variable/archivo`, `@fontsource/jetbrains-mono` 5.3.0. Licence
SIL Open Font License 1.1 (`fonts/LICENSE-*.txt`).
