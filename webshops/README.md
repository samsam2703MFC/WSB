# webshops — une boutique par dossier

Le code est commun (`generique/`). Ce qui est propre à une boutique — sa fiche,
son design, son catalogue de départ — vit ici, un dossier par boutique :

```
webshops/
  <boutique>/
    boutique.json     nom, design system, domaine, catalogue — AUCUN secret
    design-system/    sa marque (voir DESIGN-SYSTEMS.md)
  mycobot/katalog/
    katalog.json      catégories, sous-catégories, produits, modes de livraison,
                      textes de vitrine (pl / en), haut de page, icônes
    zdjecia/          images importées avec le catalogue (WebP / PNG)
      src/            leurs sources SVG
  mycobot/landing/    la landing MyCobotBuddy (MCB) : application Next.js,
                      reprise du dépôt landing_tfb — voir son README.md.
                      Elle ne fait PAS partie de la version assemblée des
                      boutiques (serveur/assembler.sh ne lit que generique/).
```

## Ajouter une boutique

1. Copier `webshops/mycobot/` vers `webshops/<nouvelle>/` ; adapter
   `boutique.json`, `design-system/` (et `ds.json`), le catalogue s'il y en a un.
2. `php tools/build-ds.php --check`, pousser sur GitHub.
3. Sur le serveur : `sudo wsm version` puis
   `sudo wsm creer <nouvelle> <domaine>` — voir `serveur/README.md`.

## Importer un catalogue à la main

```bash
cd generique/backoffice/php-api
php migrate.php --import-katalog ../../../webshops/mycobot/katalog/katalog.json
```

Rejouable : met à jour, ne duplique rien. Une photo ou une icône déposée
depuis la console n'est jamais écrasée ; les prix suivent le fichier. Un
fichier fautif (catégorie inconnue, objet sans poids, image absente…) est
refusé en bloc, sans rien écrire. Voir `generique/backoffice/php-api/katalog.php`.

## Les trois types de produit

| `rodzaj` | Exemple | À la caisse |
| --- | --- | --- |
| `towar` | chwytak, skaner | transporteur (InPost, DPD, palette) selon le poids — `waga_g` obligatoire |
| `usluga` | szkolenie, wdrożenie | — |
| `subskrypcja` | licence, abonnement SaaS | — |

Un panier sans aucun `towar` ne propose QUE le mode « bez wysyłki » (kind
`brak`, déclaré dans `dostawa`), sans adresse ni Paczkomat. Dès qu'un objet
est dans le panier, ce mode disparaît.

Le kind `odbior` (odbiór osobisty, en pracowni ou en magasin) ne demande ni
adresse ni point, et reste proposé avec des objets ; il n'entre pas dans la
file des étiquettes. `"dostawa_tylko": true` dans le catalogue éteint les
transporteurs posés d'office (InPost, DPD…) : la boutique ne livre que par ses
propres modes (la console peut les rallumer).

## Ola flora — sklep (`olaflora-sklep`)

Sklep sezonowy : dekoracje świąteczne et wianki, d'après les maquettes validées
(`docs/makiety-olaflora-sklep/`). Produits, prix et photos = EXEMPLES des
maquettes, à remplacer depuis la console. Deux modes : odbiór w pracowni
(`odbior`, 0 zł) et dowóz (`adres`, 25 zł). Taille, ruban et date : dans les
« Uwagi » de la commande (pas encore de variantes). Commandes `OF-AAMMJJ-NNNN`
(`prefiks_zamowien`). Sur l'adresse IP, la vitrine est aussi servie DANS le
projet Ola flora : `https://<IP>/olaflora/sklep/` (`projet_ip` +
`projet_dossier`, lien posé par `wsm ip`) ; sa console :
`https://<IP>/olaflora-sklep/backoffice/`.

## MyCobotBuddy — à valider

- **Prix indicatifs**, à revoir avant l'ouverture.
- **Images provisoires** : illustrations dessinées (`zdjecia/src/*.svg`), à
  remplacer par de vraies photos depuis la console (Produkty).
- **Abonnements** : vendus comme licences payées d'avance (mois, an). Le
  prélèvement récurrent automatique (Stripe Billing) n'est pas encore branché.
- **Ukrainien** : les textes produits sont en pl et en ; uk retombe sur le
  polonais tant qu'ils ne sont pas traduits (Treści).
