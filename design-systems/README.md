# design-systems — une marque par boutique, un seul code

Toutes les boutiques (Szoko Shop, MyCobotBuddy, Candle in the Wind…) partagent
le même code : boutique, console, API. Ce qui les distingue visuellement vit
ici, un dossier par marque :

```
design-systems/
  contract.json     la SEULE interface entre le code et une marque
  szoko/            Szoko Shop (ex-mrszoko/design-system)
  mycobot/          MyCobotBuddy
```

## Le principe : un contrat de jetons

Le code n'emploie que des noms **génériques** : `--brand-700`, `--paper`,
`--action`, `--on-action`, `--font-display`, `--radius-md`… Chaque design
system leur donne sa valeur. Chez Szoko, `--brand-700` est le chocolat du logo ;
chez MyCobotBuddy, la marine. Le code ne sait pas lequel il habille.

`contract.json` liste ces noms (125), groupés, avec leur rôle. Ceux **sans
défaut** sont l'identité de la marque : obligatoires. Les autres ont un défaut
calculé à partir des premiers — une nouvelle marque peut ne poser que ses
couleurs, ses polices et la teinte de ses ombres.

## Ce que chaque boutique reçoit

| | Boutique | Console |
| --- | --- | --- |
| Couleurs | toutes | **toutes** (fonds, filets, boutons, texte) |
| Polices, espacements, rayons, ombres | oui | non — la console est commune |
| Logo | oui (sauf logo déposé dans Ustawienia) | oui, au-dessus du rail |
| Retouches de composants (`theme.css`) | oui | non |
| Défauts de Wygląd | oui | — |

La console est la **même** pour toutes les boutiques : seuls le logo au-dessus
du rail et les couleurs changent.

## Choisir le design system d'une boutique

Dans la configuration du serveur (`config.local.php`), jamais depuis la
console :

```php
'design_system' => 'mycobot',
```

(ou `WSM_DESIGN_SYSTEM=mycobot`). Inconnu ou absent : `szoko`. Voir
`mrszoko/backoffice/php-api/ds.php`.

## Compiler et contrôler

```bash
php tools/build-ds.php --check
```

```bash
php tools/build-ds.php mrszoko/shop/ds
```

La première commande contrôle sans rien écrire ; la seconde contrôle puis
compile chaque design system dans `mrszoko/shop/ds/<slug>/` (`tokens.css`,
`colors.css`, `fonts.css`, `theme.css`, `fonts/`, `logo.*`, `ds.json`). Les
trois voies de déploiement la lancent ; `mrszoko/shop/serve.sh` aussi. Le
dossier compilé n'est pas versionné.

**Le contrôle arrête le déploiement si :**
- un design system oublie un jeton obligatoire ;
- une feuille réclame un fichier absent (police, image) ;
- le code emploie un nom hors contrat, ou un ancien nom de marque
  (`--choco-*`, `--cream-*`, `--caramel-*`…), même avec une valeur de secours.

Une fonction développée et testée sur une boutique s'affiche donc sur toutes.

## Ajouter la marque d'une nouvelle boutique

1. Copier `mycobot/` (le plus récent) vers `design-systems/<slug>/`.
2. Remplacer les valeurs dans `tokens/colors.css`, `tokens/typography.css`…
   Garder les noms à gauche : ce sont ceux du contrat.
3. Polices : déposer les `.woff2` dans `fonts/` et les déclarer dans
   `tokens/fonts.css` (jamais de lien vers Google Fonts).
4. `ds.json` : nom, logo, polices proposées dans Wygląd, défauts de Wygląd
   (`wyglad.przyciski` : `marka` si les boutons sont de la couleur de marque,
   `akcent` s'ils sont de la couleur d'accent), couleurs des e-mails.
5. `theme.css` (facultatif) : retouches de composants propres à la marque.
6. `php tools/build-ds.php --check`, puis
   `php mrszoko/backoffice/php-api/tests/e2e_ds.php`.
