#!/bin/bash
# Met le CODE de la landing à jour. La sync (tfb-sync.service) met le CONTENU à
# jour ; les deux sont séparés parce qu'ils n'échouent pas de la même façon : une
# fiche module cassée ne doit pas empêcher un correctif de partir, et un build raté
# ne doit pas figer le contenu.
#
#   sudo cp deploy/tfb-update.sh /usr/local/bin/tfb-update
#   sudo chmod 755 /usr/local/bin/tfb-update
#   sudo chown root: /usr/local/bin/tfb-update
#
# À la main :   sudo systemctl start tfb-update.service && journalctl -u tfb-update -f
#
# L'ordre des étapes n'est pas anodin :
#
#   1. build AVANT migration. Le build ne touche pas la base (aucune requête n'y
#      est faite), donc un build raté doit laisser la base exactement où elle est.
#      Migrer d'abord, c'est se retrouver avec un schéma en avance sur le code qui
#      tourne encore — la panne la plus pénible à défaire.
#   2. l'ancien .next est conservé jusqu'à ce que le nouveau build réussisse. Si
#      le build échoue, on le remet et on NE redémarre PAS : le site continue de
#      servir la version précédente, et personne n'est réveillé.
#   3. redémarrage, puis contrôle de santé. Un redémarrage qui ne répond pas est
#      signalé en code 1, parce qu'un déploiement silencieusement cassé est pire
#      qu'un déploiement bruyamment raté.
set -euo pipefail

APP_DIR="${TFB_APP_DIR:-/var/www/landing_tfb}"
BRANCH="${TFB_BRANCH:-feat/landing-and-back-office}"
SERVICE="${TFB_APP_SERVICE:-tfb-landing.service}"
HEALTH_URL="${TFB_HEALTH_URL:-http://127.0.0.1:3000/api/languages}"
ENV_FILE="${TFB_ENV_FILE:-/etc/tfb/landing.env}"

log() { echo "[tfb-update] $*"; }
fail() { echo "[tfb-update] ÉCHEC — $*" >&2; exit 1; }

# DATABASE_URL vit dans un fichier à part, en 600 : il n'a rien à faire dans un
# script versionné, ni dans la ligne de commande où `ps` le montrerait.
if [ -r "$ENV_FILE" ]; then
  set -a
  # shellcheck disable=SC1090
  . "$ENV_FILE"
  set +a
fi

cd "$APP_DIR" || fail "dépôt introuvable dans $APP_DIR"

# Un fichier suivi modifié à la main sur le serveur signifie que quelqu'un a
# corrigé quelque chose en urgence. On s'arrête plutôt que d'écraser son travail.
#
# --untracked-files=no volontairement : un notes.txt oublié dans le dossier n'est
# pas une raison de refuser un correctif de sécurité.
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  fail "des modifications non commitées traînent dans $APP_DIR — rien n'a été touché.
       Regardez « git -C $APP_DIR status » : quelqu'un a probablement corrigé quelque chose ici."
fi

log "récupération de origin/$BRANCH"
git fetch --quiet origin "$BRANCH"
BEFORE=$(git rev-parse HEAD)
AFTER=$(git rev-parse "origin/$BRANCH")

if [ "$BEFORE" = "$AFTER" ]; then
  log "déjà à jour ($BEFORE) — rien à faire."
  exit 0
fi

# --ff-only : si l'historique a divergé, on veut le savoir, pas fabriquer un
# commit de fusion sur un serveur de production.
git merge --ff-only "origin/$BRANCH" || fail "l'historique local a divergé de origin/$BRANCH."
log "$BEFORE → $AFTER"

log "dépendances (npm ci)"
npm ci --no-audit --no-fund || fail "npm ci"

log "client Prisma"
npx prisma generate || fail "prisma generate"

# Le nouveau build est fabriqué à côté ; l'ancien reste en place tant qu'il n'a
# pas abouti.
if [ -d .next ]; then
  rm -rf .next.previous
  mv .next .next.previous
fi

log "build"
if ! npm run build; then
  if [ -d .next.previous ]; then
    rm -rf .next
    mv .next.previous .next
  fi
  # Le dépôt repart aussi en arrière. Sans ça il resterait sur le commit cassé
  # alors que le build servi est l'ancien : `git log` mentirait sur ce qui tourne,
  # et un relancement répondrait « déjà à jour » sans jamais réessayer.
  git reset --hard --quiet "$BEFORE"
  log "build raté — revenu à $BEFORE, l'ancienne version reste en ligne, aucun redémarrage."
  fail "npm run build"
fi
rm -rf .next.previous

# Après le build seulement : à ce stade le code qui va tourner est prêt, donc un
# schéma en avance ne le sera que quelques secondes.
log "migrations"
npx prisma migrate deploy || fail "prisma migrate deploy"

log "redémarrage de $SERVICE"
# Le script tourne sous le compte qui possède le dépôt — pas root, sinon npm ci
# sèmerait des fichiers root que ce compte ne pourrait plus réécrire. Seul le
# redémarrage a besoin de privilèges, et il passe par une ligne sudoers qui ne
# permet que celui-là.
if [ "$(id -u)" -eq 0 ]; then
  systemctl restart "$SERVICE" || fail "redémarrage de $SERVICE"
else
  sudo -n systemctl restart "$SERVICE" || fail "redémarrage de $SERVICE (sudoers manquant ?)"
fi

# Le service revient avant d'écouter ; on laisse à Next le temps de démarrer avant
# de conclure quoi que ce soit.
for attempt in $(seq 1 30); do
  if curl -fsS --max-time 3 "$HEALTH_URL" >/dev/null 2>&1; then
    log "en ligne — $AFTER"
    exit 0
  fi
  sleep 2
done

fail "le service ne répond pas sur $HEALTH_URL après 60 s. Le code est déployé et migré : regardez « journalctl -u $SERVICE -n 50 »."
