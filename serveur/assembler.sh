#!/usr/bin/env bash
# =============================================================================
#  assembler.sh <dépôt> <dossier-site> — construit UNE version déployable du
#  code commun (generique/), partagée par toutes les boutiques du serveur.
#
#    <site>/shop/               la vitrine (+ ds/<slug>/ : chaque design system compilé)
#    <site>/backoffice/         la console
#    <site>/backoffice/api/     l'API PHP (sans data/, sans base, sans config locale)
#    <site>/.htaccess           en-têtes de sécurité
#    <site>/index.html          « / » → la boutique
#
#  CE QUI N'Y EST JAMAIS : la configuration d'une boutique (mots de passe,
#  clés), ses photos, ses données. Elles vivent à côté, dans
#  /srv/webshops/boutiques/<boutique>/, et le serveur web les donne au code par
#  WSM_CONFIG_FILE, WSM_MEDIA_DIR et WSM_DATA_DIR (voir serveur/apache-boutique.conf).
#  C'est ce qui permet à une version de servir dix boutiques, et de revenir à
#  la précédente en changeant un lien.
#
#  S'ARRÊTE au premier problème (design system hors contrat, écran du rail
#  absent, fichier PHP invalide) : une version cassée n'est jamais produite.
# =============================================================================
set -euo pipefail

DEPOT="${1:?usage : assembler.sh <dépôt> <dossier-site>}"
SITE="${2:?usage : assembler.sh <dépôt> <dossier-site>}"
G="$DEPOT/generique"
[ -d "$G/shop" ] && [ -d "$G/backoffice/php-api" ] || { echo "assembler : $DEPOT n'a pas de generique/ — mauvais dépôt ?" >&2; exit 1; }

rm -rf "$SITE"
mkdir -p "$SITE/shop" "$SITE/backoffice/api"

# -- La vitrine : sans les outils du serveur de développement, sans les
#    design systems compilés d'un poste de dev, sans les photos de test.
( cd "$G/shop" && tar -cf - --exclude=./router.php --exclude=./serve.sh --exclude=./ds --exclude=./media . ) \
  | ( cd "$SITE/shop" && tar -xf - )
mkdir -p "$SITE/shop/media"
cp "$G/shop/media/.htaccess" "$SITE/shop/media/.htaccess"

# -- La console : tout le PHP du dossier (jamais une liste à la main : sept
#    écrans y ont déjà été oubliés), ses feuilles et son logo d'origine.
cp "$G/backoffice/"*.php "$G/backoffice/console.css" "$SITE/backoffice/"
cp -r "$G/backoffice/_ds" "$SITE/backoffice/"
mkdir -p "$SITE/backoffice/img" && cp "$G/backoffice/img/logo.png" "$SITE/backoffice/img/"
cat > "$SITE/backoffice/index.html" <<'HTML'
<!doctype html><html lang="pl"><head><meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="refresh" content="0; url=login.php">
<title>Konsola</title></head>
<body><p><a href="login.php">Przejdź do logowania</a></p></body></html>
HTML

# -- L'API : ni données, ni base, ni configuration locale.
( cd "$G/backoffice/php-api" && tar -cf - --exclude=./data --exclude='*.sqlite' --exclude=./config.local.php . ) \
  | ( cd "$SITE/backoffice/api" && tar -xf - )

# -- siege/ : le superadmin BuddyCorp. Présent dans chaque version, mais il ne
#    répond (sinon 404) que sur le site du siège : lui seul a, dans sa
#    configuration, la clé maîtresse et la liste des téléphones.
cp -r "$G/siege" "$SITE/siege"

# -- landing/ : l'ancienne page d'accueil, qui ne fait plus que rediriger —
#    mais son content_seed.json nourrit le jeu de départ (seed.php le lit en
#    ../../landing depuis l'API) : sans lui, une base neuve ne se crée pas.
cp -r "$G/landing" "$SITE/landing"

# -- La racine
cp "$G/.htaccess" "$SITE/.htaccess"
cat > "$SITE/index.html" <<'HTML'
<!doctype html><html lang="pl"><head><meta charset="utf-8">
<meta http-equiv="refresh" content="0; url=shop/"><link rel="canonical" href="shop/">
<title>Sklep</title></head><body><p><a href="shop/">Sklep</a></p></body></html>
HTML

# -- Les design systems de TOUTES les boutiques, compilés et contrôlés.
php "$DEPOT/tools/build-ds.php" "$SITE/shop/ds"

# -- Contrôles : rien de cassé ne sort d'ici.
php -r '
  require_once $argv[1] . "/backoffice/console.php";
  $manque = [];
  foreach (console_sections(["role" => "Administrator"]) as $items)
    foreach (array_keys($items) as $f) if (!is_file($argv[1] . "/backoffice/" . $f)) $manque[] = $f;
  if ($manque) { fwrite(STDERR, "écrans du rail absents : " . implode(", ", $manque) . "\n"); exit(1); }
' "$SITE"
ERR=0
while IFS= read -r -d '' f; do
  php -l "$f" >/dev/null 2>&1 || { echo "PHP invalide : ${f#$SITE/}" >&2; ERR=1; }
done < <(find "$SITE" -name '*.php' -not -path '*/tests/*' -print0)
[ "$ERR" = 0 ] || exit 1
echo "version assemblée : $SITE"
