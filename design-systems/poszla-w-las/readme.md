# Poszła w las — design system

*Pracownia roślinna* à Varsovie : boutique en ligne, bouquets, plantes en pot,
compositions séchées, entretien de plantes en entreprise, compositions
funéraires, bons cadeaux, ateliers.

**Source** : « Poszła w las · Ola flora — design system » (fourni le 12/11/2026),
partie visuelle. Seul le document est arrivé : **ni logos, ni polices, ni
fichiers de tokens**. Les valeurs ci-dessous sont celles que le document écrit ;
les échelles qu'il ne donne pas sont calculées à partir de l'olive.

## Ce que la boutique reçoit

| Rôle (contrat) | Poszła w las | Spécification |
| --- | --- | --- |
| `--brand-700`, `--text-strong`, `--action` | `#465803` olive | l'encre partagée avec Ola flora |
| `--paper` (fond) | `#F5F4EC` | « paper » |
| `--paper-soft` (bandes pleine largeur) | `#CDCB9A` sauge | le champ de la marque |
| `--brand-900` (bandes d'encre inversée, pied) | `#273002` | olive assombri (calculé) |
| `--border-default`, `--field-line` | `#465803` | « le bord de la maison est un trait d'encre de 2 px » |
| `--on-action` | `#F5F4EC` | bouton plein : papier sur olive |
| `--success` / `--warning` / `--danger` | `#5B7A2A` / `#A86F12` / `#9A3B2A` | « sourds et terreux : olive, ocre, brique » |
| `--font-display` / `--font-sans` / `--font-mono` | Baloo 2 / Work Sans / Space Mono | substitutions proposées par la spécification |
| `--radius-*` | 4 / 8 / 14 / 22 / 34 px, pilule | idem |
| `--container` | 1180 px | idem |
| `--ease-out`, durées | `cubic-bezier(.2,.7,.3,1)`, 120 / 200 / 420 ms | idem |

`theme.css` applique le reste : titres lourds en **minuscules**, interligne
0,92 ; étiquettes (sur-titres, badges, prix) en Space Mono, capitales, approche
0,12em ; cartes 22 px à trait d'encre de 2 px **sans ombre** ; champs 14 px à
trait de 2 px ; bouton au trait qui se remplit d'encre au survol ; appui qui
descend d'1 px ; rien ne s'agrandit ; en-tête à plat, sans flou ; 96 px entre
les sections.

## Écarts et choix

- **Étiquettes en capitales.** La spécification se contredit : « Eyebrows and
  metadata are lowercase mono » (contenu) et « mono … always uppercase with
  0.12em tracking » (fondations visuelles). Les fondations visuelles l'emportent
  ici ; c'est une ligne à changer dans `theme.css` (`text-transform`).
- **Aucun dégradé** — sauf les vignettes de couleur des produits SANS photo
  (style historique du code, en ligne). Elles disparaissent dès qu'une vraie
  photo est déposée.
- **Ukrainien** : aucune des trois polices n'a de cyrillique ; la boutique
  retombe sur la police système.
- **Ola flora** (décoration de mariage) partage le système : même encre, même
  texte, champ rose `#F1ACC0`. Si elle devient une boutique, c'est un second
  design system copié de celui-ci (champ, polices d'affichage, logo).

## À fournir

- **Le logo.** `assets/logo.svg` est un signe PROVISOIRE (cabane dans les pins,
  olive sur sauge). La spécification cite un kit `assets/poszla-w-las/`
  (logo, logotyp, sygnet × 5 couleurs) qui n'est pas arrivé.
- **Les vraies polices**, si la marque en a.
- **La sauge** : échantillonnée sur une photo du logo, à confirmer.

## Polices

Hébergées ici (`fonts/`) : Baloo 2 et Work Sans variables, Space Mono 400 et
700, sous-ensembles latin et latin-ext. Source : paquets npm
`@fontsource-variable/baloo-2`, `@fontsource-variable/work-sans`,
`@fontsource/space-mono` 5.3.0. Licence SIL Open Font License 1.1
(`fonts/LICENSE-*.txt`).
