#!/usr/bin/env bash
# Start the webshop_mrszoko API on PHP's built-in server (dev / test).
# The router (index.php) handles every /franchisor/* path.
#   ./serve.sh [port]   (default 8090)
set -e
cd "$(dirname "$0")"
PORT="${1:-8090}"
# Jeton de service pour le développement local. En production il vient de
# config.local.php ; sans valeur, la voie jeton est fermée (fail-closed).
export WSM_ADMIN_TOKEN="${WSM_ADMIN_TOKEN:-dev-admin-token}"
echo "webshop_mrszoko API → http://localhost:${PORT}/franchisor/kpis"
exec php -S "localhost:${PORT}" index.php
