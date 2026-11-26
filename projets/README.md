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
