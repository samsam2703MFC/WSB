#!/usr/bin/env bash
# Boutique Mister Szoko en local (dev). L'API et la base sont celles de
# ../backoffice/php-api ; lancez d'abord `php migrate.php --fresh` là-bas.
#   ./serve.sh [port]     (défaut 8091)
set -e
cd "$(dirname "$0")"
PORT="${1:-8091}"
# Les design systems sont COMPILÉS (jetons, polices, logo) dans ds/<slug>/ :
# sans cette étape, la page n'aurait aucun style. WSM_DESIGN_SYSTEM choisit
# celui de la boutique (défaut : szoko), comme config.local.php en production.
php ../../tools/build-ds.php ds >/dev/null
echo "Sklep Mister Szoko → http://localhost:${PORT}/"
exec php -S "localhost:${PORT}" router.php
