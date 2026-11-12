#!/usr/bin/env bash
# ============================================================================
#  fetch-fonts.sh — régénère les polices hébergées en local.
#
#  Les fichiers .woff2 sont dans le dépôt : le site ne doit dépendre d'aucun
#  tiers pour s'afficher. Mais du binaire commité sans moyen de le refaire est
#  une impasse — le jour où il faut ajouter une graisse ou une langue, plus
#  personne ne sait d'où il venait. Ce script est ce moyen.
#
#  Usage :  tools/fetch-fonts.sh
#  Effet  :  réécrit design-systems/szoko/fonts/*.woff2 et design-systems/szoko/tokens/fonts.css
#            puis recopie le tout vers la boutique, la landing et la console.
# ============================================================================
set -euo pipefail
cd "$(dirname "$0")/.."

DS="design-systems/szoko"
UA="Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
# Les trois familles que typography.css utilise réellement. DM Serif Display a
# été retirée de la marque : ne pas la remettre sans changer typography.css.
# LES FAMILLES AU CHOIX dans Wygląd (theme.php) suivent : sans italique, pour
# ne pas tripler le poids du dépôt. Seule la famille choisie est téléchargée
# par le navigateur — un @font-face non utilisé ne coûte rien au visiteur.
URL="https://fonts.googleapis.com/css2?family=Mulish:ital,wght@0,300..800;1,400&family=DM+Mono:wght@400;500&family=Nunito:wght@300..800&family=Jost:wght@300..700&family=Lora:wght@400..700&family=Playfair+Display:wght@400..800&display=swap"

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
echo "== feuille Google Fonts (UA moderne → woff2)"
curl -sS -A "$UA" --max-time 30 "$URL" -o "$TMP/gf.css"
grep -q '@font-face' "$TMP/gf.css" || { echo "réponse inattendue de Google Fonts"; exit 1; }

echo "== extraction des sous-ensembles utiles"
python3 - "$TMP" "$DS" <<'PY'
import re, sys, os, urllib.request
tmp, ds = sys.argv[1], sys.argv[2]
css = open(tmp + '/gf.css', encoding='utf-8').read()
# pl → latin-ext, uk → cyrillic(-ext), en → latin. Le vietnamien ne sert à rien ici.
KEEP = {'latin', 'latin-ext', 'cyrillic', 'cyrillic-ext'}
out, dl = [], []
for b in re.split(r'(?=/\* [a-z-]+ \*/)', css):
    m = re.match(r'/\* ([a-z-]+) \*/', b.strip())
    if not m or m.group(1) not in KEEP:
        continue
    sub = m.group(1)
    fam = re.search(r"font-family: '([^']+)'", b).group(1)
    sty = re.search(r'font-style: (\w+)', b).group(1)
    wgt = re.search(r'font-weight: ([^;]+)', b).group(1).strip()
    url = re.search(r'url\((https://[^)]+)\)', b).group(1)
    name = f"{fam.lower().replace(' ','-')}-{sty}-{wgt.replace(' ','_')}-{sub}.woff2"
    dl.append((url, name))
    out.append(b.replace(url, 'fonts/' + name).rstrip())
os.makedirs(ds + '/fonts', exist_ok=True)
for url, name in dl:
    with urllib.request.urlopen(url, timeout=30) as r, open(f'{ds}/fonts/{name}', 'wb') as f:
        f.write(r.read())
open(tmp + '/faces.css', 'w', encoding='utf-8').write('\n'.join(out) + '\n')
print(f"   {len(dl)} fichiers")
PY

echo "== en-tête + @font-face → tokens/fonts.css"
sed -n '/^\/\* Mister Szoko webfonts/,/fetch-fonts.sh \*\//p' "$DS/tokens/fonts.css" > "$TMP/head.css" \
  || { echo "en-tête introuvable dans tokens/fonts.css"; exit 1; }
cat "$TMP/head.css" "$TMP/faces.css" > "$DS/tokens/fonts.css"

echo "== recopie vers les trois surfaces"
tools/sync-fonts.sh
echo "OK — $(ls -1 "$DS/fonts" | wc -l) fichiers, $(du -sh "$DS/fonts" | cut -f1)"
