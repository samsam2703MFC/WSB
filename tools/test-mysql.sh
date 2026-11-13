#!/usr/bin/env bash
# ============================================================================
#  tools/test-mysql.sh — toute la suite e2e sur MySQL, le moteur de production.
#
#  POURQUOI. Les tests tournaient sous SQLite. La production tourne sous
#  MySQL 8 en mode strict, et SQLite laisse passer ce que MySQL refuse :
#  une chaîne plus longue que sa colonne (création de produit refusée), une
#  date comparée à '' (passage en « Wysłane » refusé), le mot réservé `lead`
#  (Strony et Kreator refusés), un GROUP BY incomplet (Audyt), un rowCount()
#  qui ne compte que les lignes modifiées. Tout cela passait les tests et
#  tombait en ligne. Ce script rejoue la suite sur un vrai MySQL.
#
#  CE QU'IL FAIT, dans l'ordre de la production :
#    1. une base neuve « wsm_e2e » (et son utilisateur) ;
#    2. le schéma et le semis (migrate.php), puis le premier démarrage
#       (wsm_bootstrap) qui sème la boutique ;
#    3. le SQL de contenu que le déploiement verse dans mysql ;
#    4. l'API (8090), la boutique (8091) et la racine (8093) sur cette base ;
#    5. tous les tests/e2e_*.php, et un résumé.
#
#  PRÉREQUIS : un serveur MySQL 8 local et un compte qui peut créer une base
#  (par défaut `mysql` tel quel, c.-à-d. root par socket). Les ports 8090,
#  8091 et 8093 doivent être libres. Rien ne touche la base SQLite de dev.
#
#  Usage :  tools/test-mysql.sh            (toute la suite)
#           tools/test-mysql.sh e2e_shop   (un ou plusieurs tests)
#           MYSQL_ADMIN="mysql -uroot -psecret" tools/test-mysql.sh
# ============================================================================
set -u
cd "$(dirname "$0")/.."
RACINE=$(pwd)
API=$RACINE/generique/backoffice/php-api
ADMIN=${MYSQL_ADMIN:-mysql}
OUT=${TMPDIR:-/tmp}/wsm-test-mysql
mkdir -p "$OUT"

export WSM_DB_ENGINE=mysql WSM_DB_HOST=127.0.0.1 WSM_DB_PORT=3306 WSM_DB_NAME=wsm_e2e
# Base jetable : son mot de passe est tiré au sort à chaque passage, il
# n'est écrit nulle part.
export WSM_DB_USER=wsm_e2e WSM_DB_PASS="e2e-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
export WSM_ADMIN_TOKEN=dev-admin-token

for p in 8090 8091 8093; do
  if curl -s -o /dev/null --max-time 2 "http://localhost:$p/"; then
    echo "Le port $p répond déjà : arrêtez le serveur de dev (il parle peut-être à SQLite)."; exit 1
  fi
done

echo "== 1. base neuve wsm_e2e"
$ADMIN -e "DROP DATABASE IF EXISTS wsm_e2e; CREATE DATABASE wsm_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
           CREATE USER IF NOT EXISTS 'wsm_e2e'@'localhost' IDENTIFIED BY '$WSM_DB_PASS';
           ALTER USER 'wsm_e2e'@'localhost' IDENTIFIED BY '$WSM_DB_PASS';
           GRANT ALL PRIVILEGES ON wsm_e2e.* TO 'wsm_e2e'@'localhost'; FLUSH PRIVILEGES;" || exit 1
echo "   mode : $($ADMIN -N -e 'SELECT @@GLOBAL.sql_mode')"

echo "== 2. schéma, semis, premier démarrage"
(cd "$API" && php migrate.php > "$OUT/migrate.log" 2>&1) || { tail -5 "$OUT/migrate.log"; exit 1; }
(cd "$API" && php -r 'require "db.php"; wsm_bootstrap();') || exit 1

echo "== 3. SQL de contenu (comme au déploiement)"
(cd "$API" && php migrate.php --sync-content-sql) \
  | mysql -u"$WSM_DB_USER" -p"$WSM_DB_PASS" --default-character-set=utf8mb4 wsm_e2e > /dev/null 2> "$OUT/content.err" \
  || { echo "   SQL de contenu REFUSÉ :"; head -3 "$OUT/content.err"; exit 1; }
echo "   ok"

echo "== 4. serveurs sur MySQL"
# « exec » : le PID retenu est celui de PHP, pas d'un sous-shell — sinon
# l'arrêt final laissait les serveurs tourner et occuper les ports.
PIDS=()
(cd "$RACINE" && exec php -d opcache.enable=0 -S localhost:8091 -t generique/shop generique/shop/router.php > "$OUT/shop.log" 2>&1) & PIDS+=($!)
(cd "$RACINE" && exec php -d opcache.enable=0 -S localhost:8093 -t mrszoko > "$OUT/root.log" 2>&1) & PIDS+=($!)
(cd "$API" && exec php -S localhost:8090 index.php > "$OUT/api.log" 2>&1) & PIDS+=($!)
trap 'kill "${PIDS[@]}" 2>/dev/null' EXIT
for i in $(seq 1 40); do curl -s -o /dev/null --max-time 1 http://localhost:8091/ && break; sleep 0.25; done

echo "== 5. tests"
cd "$API"
if [ $# -gt 0 ]; then LISTE=("$@"); else LISTE=(); for t in tests/e2e_*.php; do LISTE+=("$(basename "$t" .php)"); done; fi
ko=0
for n in "${LISTE[@]}"; do
  timeout 300 php "tests/$n.php" > "$OUT/$n.out" 2>&1; rc=$?
  nf=$(grep -c '✗' "$OUT/$n.out")
  if [ $rc -ne 0 ] || [ "$nf" -ne 0 ]; then ko=$((ko+1)); printf '  ✗ %-24s rc=%s, %s échec(s) — %s\n' "$n" "$rc" "$nf" "$OUT/$n.out"
  else printf '  ✓ %s\n' "$n"; fi
done
echo
[ $ko -eq 0 ] && echo "OK — ${#LISTE[@]} tests verts sur MySQL" || echo "ÉCHEC — $ko test(s) sur ${#LISTE[@]}"
exit $(( ko > 0 ))
