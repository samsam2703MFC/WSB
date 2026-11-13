# shop — le site public Mister Szoko

`https://<serveur>/mrszoko/shop/` — **une seule page publique**. La page de
marque et la boutique ont été fusionnées : l'accueil enchaîne le hero, les
promesses, le catalogue achetable, les formats dégressifs, la pracownia et le
panneau B2B. Viennent ensuite la fiche produit, le panier, la caisse et la
confirmation. Trois langues (pl / uk / en), aucun libellé en dur.

`/mrszoko/landing/` ne duplique plus rien : cette adresse a été partagée, elle
reste donc vivante mais se contente de rediriger ici. La racine `/mrszoko/`
pointe elle aussi sur la boutique.

## Pourquoi c'est du PHP et pas une application cliente

Parce qu'une boutique doit s'afficher, pas se charger.

Chaque page arrive **déjà remplie** : les produits, les prix et les textes sont
dans la première réponse HTML, lus en base par `index.php`. Il n'y a rien à
attendre — pas de fetch, pas de JSON, pas d'écran vide pendant un aller-retour.
Le déploiement le vérifie : il compte les fiches produit et les prix présents
dans le HTML initial et échoue s'il en manque.

Trois décisions vont dans le même sens :

- **Les jetons sont compilés, pas importés.** Une chaîne de `@import` oblige le
  navigateur à télécharger une feuille pour découvrir la suivante — quatre
  allers-retours avant le premier caractère à l'écran. Le fichier est régénéré
  au déploiement par `tools/build-ds.php` depuis le design system de la boutique
  (`webshops/<slug>/design-system/` → `ds/<slug>/tokens.css`) ; il ne peut donc pas
  diverger. La boutique charge celui que sa configuration désigne
  (`design_system`, défaut `szoko`) — voir `../../webshops/DESIGN-SYSTEMS.md`.
- **La police est demandée par un `<link>`**, précédé de deux `preconnect`, au
  lieu d'être cachée au fond d'une feuille de style.
- **`shop.js` est en `defer` et n'est que du confort.** Sans lui, tout marche :
  ajouter au panier, changer une quantité, commander. Ce sont de vrais
  formulaires POST. Le JavaScript évite les rechargements, il ne les remplace pas.

## Le panier

Un cookie `ms_cart`, contenant uniquement des identifiants et des quantités.
**Jamais de prix.** Un cookie est modifiable par celui qui le porte ; ce qu'on
en lit est borné (40 lignes, 99 unités) et tout le reste est relu en base à
chaque affichage. Le serveur reste seul juge du montant — voir
`../backoffice/php-api/shop.php` et la preuve dans `tests/e2e_shop.php`.

Le formulaire de commande porte un jeton anti-CSRF comparé avec `hash_equals`.

## Le contenu de la page de marque

Formats, pracownia et strefa pro vivent dans `wsm_shop_i18n` sous le préfixe
`story.*`, aux côtés du reste — une seule page, une seule table. Le panneau pro
a été réécrit au passage : il annonçait une boutique « bientôt disponible », ce
qui est devenu faux le jour où elle s'est retrouvée juste au-dessus. Il propose
maintenant l'ouverture d'un compte B2B. Un test le vérifie.

`wsm_landing_i18n` et l'API `/landing/content` restent en place (l'ancienne
adresse et son contenu ne sont pas détruits), mais plus aucune page ne les lit.

## Le contenu

`content_seed.json` est la **source unique** : il alimente `wsm_shop_i18n`,
`wsm_shipping_methods` et les colonnes vitrine de `wsm_products`. Ensuite la
base fait foi et la console peut tout éditer. Un déploiement ajoute les
libellés nouveaux (`migrate.php --sync-content`) sans écraser les retouches.

Le fichier n'est pas servi au public (`.htaccess`).

## Les photos produit

Elles s'envoient depuis la console — **Produkty i zdjęcia**
(`../backoffice/produkty.php`), réservé au rôle `Centrala`.

Un fichier n'est pas une image parce qu'il finit par `.jpg`. Il l'est parce
qu'on a réussi à le décoder : chaque envoi est décodé puis **ré-encodé** par GD
(`../backoffice/php-api/media.php`). Ce qui atterrit dans `media/` est donc une
image que nous avons fabriquée — métadonnées, commentaires et tout ce qui
aurait pu voyager dedans restent à la porte. Elle est aussi ramenée à 1400 px
et convertie en WebP, ce qui divise le poids par cinq ou dix.

Trois autres précautions :

- le nom du fichier est **tiré au sort**, jamais repris de l'envoi — un nom
  choisi par l'utilisateur est un chemin choisi par l'utilisateur ;
- l'extension vient du format ré-encodé, pas de ce qui était annoncé ;
- `media/.htaccess` **coupe l'exécution de script** : une image parfaitement
  valide contenant du PHP y serait téléchargée, jamais interprétée.

Remplacer une photo efface l'ancienne. Le dossier `media/` n'est pas versionné
(les photos vivent sur le serveur) et `rsync` tourne sans `--delete` : un
déploiement ne les emporte pas.

On peut aussi coller une adresse `https://` au lieu d'envoyer un fichier — en
`http` le navigateur bloquerait l'image pour contenu mixte, c'est refusé.

Preuve : les 19 assertions « zdjęcia produktów » de
`../backoffice/php-api/tests/e2e_shop.php`.

## En local

```bash
cd ../backoffice/php-api && php migrate.php --fresh   # base + seed
cd ../../shop && ./serve.sh                           # → http://localhost:8091/
```

`router.php` et `serve.sh` reproduisent ce que fait `.htaccess` en production
(le serveur intégré de PHP ignore les `.htaccess`). Ni l'un ni l'autre n'est
déployé.

## Ce qui n'y est pas encore

- **Les photos réelles.** Les vignettes restent des dégradés aux couleurs de
  chaque chocolat tant qu'aucune photo n'a été envoyée. Elles s'envoient depuis
  la console : `/mrszoko/backoffice/produkty.php`.
- **Le paiement Stripe et InPost** attendent des identifiants. Le code est écrit
  et testé ; sans identifiants il est fermé, pas approximatif : aucune
  session de paiement créée, aucun webhook accepté. Le paiement s'affiche
  intégré dans la page « Zapłać » (Stripe Checkout, mode embedded_page).
- **Le sélecteur de Paczkomat sur carte** (Geowidget InPost) : le code du point
  se saisit pour l'instant à la main, avec vérification du format.
