#!/usr/bin/env bash
# =============================================================================
#  auto-deploy.sh — le serveur va CHERCHER GitHub, au lieu d'attendre qu'on le
#  pousse.
#
#  POURQUOI. Le 6 août, six exécutions du workflow sont mortes en file sans
#  jamais démarrer : GitHub n'attribuait plus de runner à ce compte, et ni un
#  workflow neuf ni une image épinglée n'y ont rien changé. Trois changements
#  finis sont restés dehors une soirée entière. Une boutique ne peut pas
#  dépendre d'une file d'attente chez un fournisseur.
#
#  Ici, personne ne pousse : cette machine demande à GitHub, une fois par
#  minute, si `main` a bougé. Si oui, elle déploie. Le dépôt est public, donc
#  la question ne coûte ni identifiant ni secret.
#
#  CE QUI EST FAIT POUR NE RIEN CASSER :
#   · LA QUESTION EST BON MARCHÉ. `git ls-remote` demande UNE référence, pas
#     un clone. Tant que rien ne bouge, il ne se passe rien : pas de disque
#     écrit, pas de ligne de journal, pas de site touché. Cette machine est à
#     93 % de son disque.
#   · UN SEUL À LA FOIS. flock : un déploiement lent ne peut pas être doublé
#     par la minute suivante — deux copies concurrentes dans le même dossier,
#     c'est un site à moitié à jour.
#   · LE JOURNAL NE GONFLE PAS. Il est tronqué au-delà de 256 Ko. Un
#     déploiement qui remplit le disque casse la boutique qu'il met à jour.
#   · ON N'AVANCE LE REPÈRE QUE SI ÇA A MARCHÉ. Un déploiement en échec sera
#     retenté à la minute suivante, au lieu d'être oublié parce qu'on a noté
#     le commit trop tôt.
#
#  Installation, une seule fois, en root sur le serveur :
#      bash <(curl -fsSL https://raw.githubusercontent.com/samsam2703MFC/WSB/main/tools/auto-deploy.sh) --install
#
#  Ensuite : `git push` → en ligne dans la minute. Sans runner, sans rien
#  lancer à la main.
#
#  Pour arrêter :   rm /etc/cron.d/wsm-deploy
#  Pour regarder :  tail -f /var/log/wsm-deploy.log
#  Pour forcer :    /usr/local/sbin/wsm-deploy --force
# =============================================================================
set -euo pipefail

DEPOT="${DEPOT:-https://github.com/samsam2703MFC/WSB.git}"
BRANCHE="${BRANCHE:-main}"
BRUT="${BRUT:-https://raw.githubusercontent.com/samsam2703MFC/WSB/main/tools}"
ETAT="${ETAT:-/var/lib/wsm-deploy}"
JOURNAL="${JOURNAL:-/var/log/wsm-deploy.log}"
CIBLE="${CIBLE:-/usr/local/sbin/wsm-deploy}"
VERROU="/var/lock/wsm-deploy.lock"
MAX_JOURNAL=$((256 * 1024))

dire() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }

# ---- Installation ----------------------------------------------------------
if [ "${1:-}" = "--install" ]; then
    [ "$(id -u)" = 0 ] || { echo "à lancer en root"; exit 1; }
    command -v git   >/dev/null || { echo "git absent : apt install -y git"; exit 1; }
    command -v flock >/dev/null || { echo "flock absent : apt install -y util-linux"; exit 1; }

    mkdir -p "$ETAT"
    # On se copie soi-même quand on vient d'un fichier ; sinon (curl | bash) on
    # se retéléchargera — le script doit exister sur disque pour que cron
    # puisse l'appeler.
    if [ -f "${BASH_SOURCE[0]:-}" ] && [ -s "${BASH_SOURCE[0]}" ]; then
        cp "${BASH_SOURCE[0]}" "$CIBLE"
    else
        curl -fsSL "$BRUT/auto-deploy.sh" -o "$CIBLE"
    fi
    curl -fsSL "$BRUT/deploy-serwer.sh" -o "$ETAT/deploy-serwer.sh"
    chmod 750 "$CIBLE" "$ETAT/deploy-serwer.sh"

    # PATH explicite : cron démarre avec un environnement quasi vide, et le
    # script appelle git, curl, php, mysql. Sans cette ligne, ça marche à la
    # main et pas la nuit — la panne la plus pénible à comprendre.
    cat > /etc/cron.d/wsm-deploy <<CRON
# Mister Szoko — déploiement automatique depuis GitHub (voir tools/auto-deploy.sh)
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * root $CIBLE >> $JOURNAL 2>&1
CRON
    chmod 644 /etc/cron.d/wsm-deploy
    touch "$JOURNAL"; chmod 640 "$JOURNAL"
    dire "installé — $CIBLE, cron toutes les minutes, journal $JOURNAL" | tee -a "$JOURNAL"
    dire "premier passage maintenant :" | tee -a "$JOURNAL"
    exec "$CIBLE" --force
fi

# ---- Un seul déploiement à la fois -----------------------------------------
exec 9>"$VERROU"
flock -n 9 || exit 0                     # déjà en cours : la minute suivante réessaiera

# ---- Le journal ne gonfle pas ----------------------------------------------
if [ -f "$JOURNAL" ] && [ "$(stat -c %s "$JOURNAL" 2>/dev/null || echo 0)" -gt "$MAX_JOURNAL" ]; then
    tail -c $((MAX_JOURNAL / 2)) "$JOURNAL" > "$JOURNAL.tmp" && mv "$JOURNAL.tmp" "$JOURNAL"
    dire "journal tronqué"
fi

mkdir -p "$ETAT"
VU="$ETAT/derniere.sha"
FORCE="${1:-}"

# ---- La question, une référence, rien de plus ------------------------------
# Un échec réseau n'est PAS un événement : GitHub tousse, on repassera dans une
# minute. Écrire une ligne à chaque hoquet noierait le journal utile.
DISTANT=$(git ls-remote "$DEPOT" "refs/heads/$BRANCHE" 2>/dev/null | awk '{print $1}') || DISTANT=""
[ -n "$DISTANT" ] || exit 0

LOCAL=$(cat "$VU" 2>/dev/null || echo "")
if [ "$DISTANT" = "$LOCAL" ] && [ "$FORCE" != "--force" ]; then
    exit 0                                # rien de neuf : on ne touche à rien
fi

dire "main = ${DISTANT:0:8} (avant : ${LOCAL:0:8}${LOCAL:+…}) — déploiement"

# ---- Le déploiement, celui qui existe déjà ---------------------------------
# On le retélécharge à chaque fois : c'est LUI qui est la référence, et une
# copie figée sur le serveur aurait divergé au premier correctif.
if ! curl -fsSL "$BRUT/deploy-serwer.sh" -o "$ETAT/deploy-serwer.sh"; then
    dire "^ impossible de récupérer deploy-serwer.sh — on réessaiera"
    exit 0
fi
chmod 750 "$ETAT/deploy-serwer.sh"

# SANS --haslo, et sans ADM_PASS : une tâche planifiée ne repose pas un mot de
# passe. Il n'y a ici aucun secret à lire, et un cron qui réécrit les comptes
# toutes les minutes est une porte, pas un déploiement. deploy-serwer.sh dira
# quand même, à chaque passage, si quelqu'un peut encore entrer dans la console.
if bash "$ETAT/deploy-serwer.sh"; then
    # LE REPÈRE N'AVANCE QU'EN CAS DE SUCCÈS. Noté trop tôt, un déploiement
    # raté serait oublié jusqu'au prochain commit — c'est-à-dire jusqu'à ce
    # qu'un client s'en aperçoive.
    printf '%s' "$DISTANT" > "$VU"
    dire "déploiement réussi — ${DISTANT:0:8}"
else
    dire "^ DÉPLOIEMENT EN ÉCHEC — on réessaiera à la minute suivante"
fi
