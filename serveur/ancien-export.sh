#!/usr/bin/env bash
# =============================================================================
#  ancien-export.sh [<dossier>] — lancé SUR L'ANCIEN SERVEUR par le workflow
#  « Nouveau serveur (wsm) », action « demenager ». Écrit sur sa SORTIE une
#  archive tar.gz de ce que la boutique possède :
#
#    base.sql.gz        la base (mysqldump), ou base.sqlite
#    media/             les photos (shop/media)
#    data/              journal, factures reçues (backoffice/api/data)
#    config.local.php   ses clés (Stripe, InPost…) — pour la table centrale
#
#  Rien n'est modifié ni effacé ici. Les messages vont sur la sortie d'erreur
#  (le journal) : des NOMBRES seulement, jamais une donnée.
# =============================================================================
set -euo pipefail
D="${1:-/var/www/html/mrszoko}"
API="$D/backoffice/api"
[ -f "$API/config.local.php" ] || { echo "ancien : $API/config.local.php introuvable" >&2; exit 1; }
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
umask 077

# La configuration complète, telle que l'ancienne application la lit.
eval "$(php -r '
  $c = require $argv[1];
  $m = (array) ($c["mysql"] ?? []);
  foreach (["ENGINE" => $c["engine"] ?? "", "SQLITE" => $c["sqlite_path"] ?? "",
            "H" => $m["host"] ?? "localhost", "P" => $m["port"] ?? "3306", "N" => $m["name"] ?? "",
            "U" => $m["user"] ?? "", "W" => $m["pass"] ?? ""] as $k => $v) echo $k, "=", escapeshellarg((string) $v), "\n";
' "$API/config.php")"

if [ "$ENGINE" = mysql ]; then
  command -v mysqldump >/dev/null || { echo "ancien : mysqldump absent" >&2; exit 1; }
  MYSQL_PWD="$W" mysqldump -h "$H" -P "$P" -u "$U" --single-transaction --no-tablespaces \
    --default-character-set=utf8mb4 "$N" | gzip -9 > "$T/base.sql.gz"
  echo "ancien : base « $N » exportée ($(du -h "$T/base.sql.gz" | cut -f1))" >&2
else
  [ -f "$SQLITE" ] || { echo "ancien : base SQLite introuvable" >&2; exit 1; }
  cp "$SQLITE" "$T/base.sqlite"
  echo "ancien : base SQLite copiée ($(du -h "$T/base.sqlite" | cut -f1))" >&2
fi

mkdir -p "$T/media" "$T/data"
[ -d "$D/shop/media" ] && cp -a "$D/shop/media/." "$T/media/"
[ -d "$API/data" ] && cp -a "$API/data/." "$T/data/"
rm -f "$T/data/"*.sqlite 2>/dev/null || true          # une base SQLite de travail n'a rien à faire là
cp "$API/config.local.php" "$T/config.local.php"
echo "ancien : $(find "$T/media" -type f | wc -l) photos, $(find "$T/data" -type f | wc -l) fichiers de données" >&2

tar -czf - -C "$T" .
