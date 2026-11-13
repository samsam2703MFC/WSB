#!/usr/bin/env bash
# =============================================================================
#  amorcer.sh — lancé PAR GITHUB (workflow « Nouveau serveur (wsm) ») quand il
#  se connecte avec les secrets SSH_HOST / SSH_USER / SSH_PASSWORD. En root.
#
#    1. diagnostic du serveur (affiché dans le journal GitHub)
#    2. wsm absent → installer.sh, MAIS seulement si le serveur est vierge
#       (pas de nginx, pas d'autre site Apache, rien d'autre dans /var/www) :
#       on n'installe jamais par-dessus un serveur qui sert déjà quelque chose
#    3. la version du code poussée, puis la boutique « sandbox » (créée au
#       premier passage sur sandbox.<ip>.sslip.io) et son déploiement
#    4. ou l'action demandée à la main (valider, deployer, retour, liste)
#
#  LE JOURNAL GITHUB EST PUBLIC : aucun mot de passe n'y est écrit. Celui de
#  l'administrateur de la sandbox reste dans boutiques/sandbox/ADMIN.txt.
# =============================================================================
set -euo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
BRANCHE=main; SHA=""; EMAIL=""; IP=""; ACTION=push; BOUTIQUE=""
while [ $# -gt 0 ]; do case "$1" in
  --branche) BRANCHE="$2"; shift 2;; --sha) SHA="$2"; shift 2;; --email) EMAIL="$2"; shift 2;;
  --ip) IP="$2"; shift 2;; --action) ACTION="$2"; shift 2;; --boutique) BOUTIQUE="$2"; shift 2;;
  *) echo "option inconnue : $1" >&2; exit 2;; esac; done
[ "$(id -u)" = 0 ] || { echo "amorcer : root nécessaire" >&2; exit 1; }

echo "== diagnostic"
. /etc/os-release 2>/dev/null || true
echo "  système   : ${PRETTY_NAME:-?} ($(uname -m)), $(nproc) cœurs, $(free -m | awk '/^Mem:/{print $2}') Mo de mémoire, $(df -h / | awk 'NR==2{print $4}') libres"
echo "  apache    : $(command -v apache2 >/dev/null && echo installé || echo absent)  nginx : $(command -v nginx >/dev/null && echo PRÉSENT || echo absent)"
echo "  wsm       : $([ -x /usr/local/sbin/wsm ] && echo installé || echo absent)"

if [ ! -x /usr/local/sbin/wsm ]; then
  # Un serveur vierge, ou rien.
  OCCUPE=""
  command -v nginx >/dev/null && OCCUPE="$OCCUPE nginx;"
  if [ -d /etc/apache2/sites-enabled ]; then
    autres=$(ls /etc/apache2/sites-enabled | grep -vxE '000-default\.conf|default-ssl\.conf' || true)
    [ -n "$autres" ] && OCCUPE="$OCCUPE sites Apache : $(echo $autres);"
  fi
  if [ -d /var/www ]; then
    autres=$(ls -A /var/www | grep -vx html || true)
    [ -n "$autres" ] && OCCUPE="$OCCUPE /var/www : $(echo $autres);"
    [ -d /var/www/html ] && [ -n "$(ls -A /var/www/html | grep -vx index.html || true)" ] && OCCUPE="$OCCUPE /var/www/html non vide;"
  fi
  if [ -n "$OCCUPE" ]; then
    echo
    echo "ARRÊT : ce serveur sert déjà quelque chose ($OCCUPE)."
    echo "Rien n'a été installé. Si c'est bien le nouveau serveur, lancer à la main :"
    echo "  sudo bash installer.sh --email … --branche $BRANCHE   (voir serveur/README.md)"
    exit 1
  fi
  [ -n "$EMAIL" ] || { echo "e-mail administrateur manquant" >&2; exit 1; }
  echo
  echo "== serveur vierge : installation (5 à 10 minutes)"
  bash "$ICI/installer.sh" --email "$EMAIL" --branche "$BRANCHE"
fi

W=/usr/local/sbin/wsm
case "$ACTION" in
  push)
    "$W" version "${SHA:-$BRANCHE}"
    if [ ! -d /srv/webshops/boutiques/sandbox ]; then
      [ -n "$IP" ] || { echo "adresse IP inconnue : sandbox non créée" >&2; exit 1; }
      DOM="sandbox.${IP//./-}.sslip.io"
      echo
      echo "== création de la boutique sandbox ($DOM)"
      # La sortie de « wsm creer » contient le mot de passe : elle reste ici.
      if "$W" creer sandbox "$DOM" --webshop szoko > /root/wsm-creation-sandbox.log 2>&1; then
        grep -v -i 'mot de passe' /root/wsm-creation-sandbox.log || true
        echo "  (mot de passe administrateur : sudo cat /srv/webshops/boutiques/sandbox/ADMIN.txt)"
      else
        grep -v -i 'mot de passe' /root/wsm-creation-sandbox.log || true
        exit 1
      fi
    fi
    "$W" deployer sandbox
    ;;
  valider)  "$W" valider ;;
  deployer) "$W" deployer "$BOUTIQUE" ;;
  retour)   "$W" retour "$BOUTIQUE" ;;
  liste)    : ;;
  *) echo "action inconnue : $ACTION" >&2; exit 2;;
esac
echo
"$W" liste
