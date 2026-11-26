# Projets à part (pas des boutiques)

Un projet = un site livré tel quel (par exemple un projet Claude Design exporté en
zip : pages `.dc.html`, `support.js`, design system, photos), installé sur le
serveur par `wsm projet <nom> <lien du zip>` sous `http://<IP>/<nom>/`, avec SA
base MySQL `<nom>`.

Les pages ne sont PAS modifiées, à une ligne près : `_wsb/stockage.js`, glissé en
tête de chaque page, fait aller ce que la page range dans le navigateur
(`localStorage`) dans la base MySQL du projet (table `of_store`, une ligne par
clé, chaque version gardée dans `of_store_historia`). Les mêmes données sur tous
les appareils, sauvegardées avec le serveur.

- `_wsb/stockage.js` — chargé en premier : lit les données du serveur
  (synchrone, avant le code de la page), puis envoie chaque `setItem` /
  `removeItem` au serveur.
- `_wsb/stockage.php` — public : seulement les clés du devis en ligne (le couple
  confirme, réagit) ; rien d'autre ne se lit ni ne s'écrit.
- `_wsb/prive/stockage.php` — toutes les clés, derrière le mot de passe du
  projet (Apache, Basic, en https).

Les écrans internes (Back office, Poczta, Super admin, Asystent rozmowy,
Retroplan, Karta misji) demandent le mot de passe ; le site, le formulaire et le
devis en ligne restent ouverts.

Réglages par projet : `projets/<nom>.conf` — `INDEX=<page>` : la page servie à
`/<nom>/` (sinon la liste des écrans, toujours à `/<nom>/ekrany.html`).

## Ola flora — CMS de la page publique

Back office → STRONA → « strony » édite la page publique (`Ola flora - wesela v2`)
telle qu'elle est dans le zip : chaque texte, chaque photo, chaque adresse de
lien (mail, téléphone, Instagram), le menu, la galerie des réalisations (photos,
ordre, légendes, catégories, noms des filtres) et le bandeau de la page d'accueil.

- La liste des sections n'est écrite nulle part : `adapt.js` (`ofCms`) la lit dans
  le gabarit de la page publique, au chargement du back office ; chaque élément a
  une clé stable (`home:t1a2b3c4d` = onglet + empreinte du texte d'origine).
  La page publique applique la MÊME règle : un nouveau zip garde ce qui a été
  publié, tant que le texte d'origine ne change pas.
- « edytuj » → brouillon enregistré en base (`draft_value`, photos `state=draft`),
  « Publikuj na żywo » → en ligne (`published_value`, `published_at`),
  « Przywróć » → retour au texte du zip. Une section n'existe en base
  (`of_page_sections`) qu'à partir du premier geste.
- « ＋ dodaj zdjęcie z dysku » : la photo est réduite dans le navigateur (2000 px,
  JPEG), gardée en base (`of_media` + `of_media_blobs`) — elle survit à un nouveau
  zip — et servie par `_wsb/app/zdjecie.php?id=…`.
- Points d'entrée : `_wsb/app/strona.php` (public, seulement le publié),
  `_wsb/app/zdjecie.php` (public, photos envoyées), `_wsb/prive/app/cms.php`
  (mot de passe : brouillon, publication, retour, envoi d'une photo),
  `_wsb/prive/app/dane.php` (ce que le back office affiche au chargement).
- Un lien n'accepte que `https://`, `mailto:` ou `tel:`.
- **Sezonowo w sklepie** (oferta, maquette B validée) : un bandeau glissé après
  « 05 Kwiaty ». Une ligne par saison dans la section « Sezonowo w sklepie » de
  la page oferta : `nazwa | od (dd.mm) | do (dd.mm) | link do sklepu` — dates
  valables chaque année, une saison n'apparaît qu'entre ses dates ET avec un
  lien ; sans saison visible, pas de bandeau. Titre, texte et photos des deux
  cartes s'éditent comme le reste de la page. D'office : Dekoracje świąteczne
  01.11–20.12 et Wianki 01.12–20.12, vers `sklep/#katalog` (la boutique
  `olaflora-sklep`, servie à /olaflora/sklep/).
