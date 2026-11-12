#!/usr/bin/env bash
# Recopie la source unique (le design system de Szoko) vers les surfaces qui en
# gardent une COPIE : la console (sa base commune) et la landing. Appelé par
# fetch-fonts.sh. La boutique n'en a plus besoin : tools/build-ds.php compile
# chaque design system, polices comprises, dans shop/ds/<slug>/.
set -euo pipefail
cd "$(dirname "$0")/.."
DS="design-systems/szoko"
# La landing n'est plus qu'une redirection vers la boutique : elle ne charge
# aucune feuille de style. Y copier 268 Ko de polices serait du poids mort dans
# le dépôt ET dans le site déployé. Sa copie de fonts.css reste synchronisée —
# c'est le miroir du design system — mais sans les binaires.
# --delete par la main : `cp -f` ajoute et écrase, il n'ENLÈVE jamais. Le jour
# où une famille quitte la marque — Plus Jakarta Sans aujourd'hui, DM Serif
# Display avant elle — ses fichiers restaient dans les trois copies, et le
# dépôt continuait de les porter alors que plus une feuille ne les nommait.
for dest in "mrszoko/backoffice/_ds/mister-szoko/tokens"; do
  mkdir -p "$dest/fonts"
  for f in "$dest"/fonts/*.woff2; do
    [ -e "$f" ] || continue
    [ -e "$DS/fonts/$(basename "$f")" ] || { rm -f "$f"; echo "  retiré (plus dans la marque) : $f"; }
  done
  cp -f "$DS"/fonts/*.woff2 "$dest/fonts/"
done
cp -f "$DS/tokens/fonts.css" "mrszoko/landing/tokens/fonts.css"
cp -f "$DS/tokens/fonts.css" "mrszoko/backoffice/_ds/mister-szoko/tokens/fonts.css"

# typography.css SUIT LE MÊME CHEMIN, et ne le suivait pas. Trois copies
# identiques vivaient côte à côte sans que rien ne les tienne ensemble :
# changer la famille dans le design system laissait la console et la landing
# sur l'ancienne, et le site portait deux typographies sans que personne ne
# sache laquelle était la bonne.
cp -f "$DS/tokens/typography.css" "mrszoko/landing/tokens/typography.css"
cp -f "$DS/tokens/typography.css" "mrszoko/backoffice/_ds/mister-szoko/tokens/typography.css"
# Les couleurs de la console : sa base est celle de Szoko ; chaque boutique
# pose ensuite les siennes par-dessus (console.php → ds/<slug>/colors.css).
cp -f "$DS/tokens/colors.css" "mrszoko/backoffice/_ds/mister-szoko/tokens/colors.css"
echo "typographie, couleurs et polices : copies alignées sur $DS"
