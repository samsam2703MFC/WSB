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
BRANCHE=main; SHA=""; EMAIL=""; IP=""; ACTION=push; BOUTIQUE=""; SIEGE_DOM=""; DOMAINE=""
while [ $# -gt 0 ]; do case "$1" in
  --branche) BRANCHE="$2"; shift 2;; --sha) SHA="$2"; shift 2;; --email) EMAIL="$2"; shift 2;;
  --ip) IP="$2"; shift 2;; --action) ACTION="$2"; shift 2;; --boutique) BOUTIQUE="$2"; shift 2;;
  --siege-domaine) SIEGE_DOM="$2"; shift 2;;
  --domaine) DOMAINE="$2"; shift 2;;
  *) echo "option inconnue : $1" >&2; exit 2;; esac; done
[ "$(id -u)" = 0 ] || { echo "amorcer : root nécessaire" >&2; exit 1; }

echo "== diagnostic"
. /etc/os-release 2>/dev/null || true
echo "  système   : ${PRETTY_NAME:-?} ($(uname -m)), $(nproc) cœurs, $(free -m | awk '/^Mem:/{print $2}') Mo de mémoire, $(df -h / | awk 'NR==2{print $4}') libres"
echo "  apache    : $(command -v apache2 >/dev/null && echo installé || echo absent)  nginx : $(command -v nginx >/dev/null && echo PRÉSENT || echo absent)"
echo "  wsm       : $([ -x /usr/local/sbin/wsm ] && echo installé || echo absent)"

# Lecture seule : qui écoute, quels conteneurs, l'état d'Apache et du pare-feu.
etat() {
  echo "  ports ouverts (TCP) :"
  ss -ltnpH 2>/dev/null | awk '{p=$4; sub(/.*:/,"",p); u=$6; sub(/^users:\(\("/,"",u); sub(/".*/,"",u); print "    " p "  " u}' | sort -n -u
  if command -v docker >/dev/null; then
    echo "  conteneurs Docker :"
    docker ps --format '    {{.Names}}  {{.Image}}  {{.Ports}}' 2>/dev/null || echo "    (illisibles)"
  fi
  echo "  apache2   : $(systemctl is-active apache2 2>/dev/null || true)"
  journalctl -u apache2 -n 12 --no-pager 2>/dev/null | sed 's/^/    /' || true
  echo "  pare-feu  :"; ufw status 2>/dev/null | sed 's/^/    /' || true
}

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
  command -v docker >/dev/null && [ -n "$(docker ps -q 2>/dev/null)" ] && OCCUPE="$OCCUPE conteneurs Docker actifs;"
  web=$(ss -ltnpH 2>/dev/null | awk '$4 ~ /:(80|443)$/' | grep -v apache2 || true)
  [ -n "$web" ] && OCCUPE="$OCCUPE ports 80/443 déjà pris;"
  if [ -n "$OCCUPE" ]; then
    etat
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
CONF=/srv/webshops/wsm.conf

# Les secrets que GitHub a déposés pour ce passage (téléphones du siège,
# jeton SMSAPI) : lus, puis effacés tout de suite. Jamais affichés.
SEC=/tmp/wsb-secrets.env
sec() { [ -f "$SEC" ] && sed -n "s/^$1=//p" "$SEC" | head -1 || true; }
TELS=$(sec SIEGE_TELEFONY); SMSTOK=$(sec SMSAPI_TOKEN); SMSNAD=$(sec SMSAPI_NADAWCA)
[ -f "$SEC" ] && { shred -u "$SEC" 2>/dev/null || rm -f "$SEC"; }

# wsm d'abord, celui de ce commit (déposé par GitHub à côté de ce script) :
# l'ancien pouvait s'arrêter en chemin.
[ -x /usr/local/sbin/wsm ] && [ -f "$ICI/wsm" ] && install -m 0755 "$ICI/wsm" /usr/local/sbin/wsm
# Le code ensuite : la version poussée apporte aussi le wsm qui va avec.
case "$ACTION" in push|creer) "$W" version "${SHA:-$BRANCHE}" ;; esac

# Ports 80/443 tenus par autre chose (ici Traefik, dans Docker) : Apache de la
# machine ne doit SURTOUT PAS démarrer au boot avant lui — il prendrait le
# port 80 et couperait les sites existants. Les boutiques passent alors en
# conteneur, derrière ce Traefik (wsm docker-init).
if ss -ltnpH 2>/dev/null | awk '$4 ~ /:(80|443)$/' | grep -qv apache2; then
  systemctl disable --now apache2 >/dev/null 2>&1 || true
  if command -v docker >/dev/null && docker ps --format '{{.Image}}' | grep -q traefik; then
    grep -q '^MODE="docker"' "$CONF" 2>/dev/null || "$W" docker-init
  else
    echo; echo "ARRÊT : les ports 80/443 appartiennent à un autre service, qui n'est pas Traefik."
    echo "  Rien n'a été créé ni déployé."; exit 1
  fi
elif [ "$ACTION" != liste ] && ! systemctl is-active --quiet apache2; then
  # Mode machine : Apache doit tourner, sinon on ne crée ni ne déploie rien.
  systemctl start apache2 2>/dev/null || true
  if ! systemctl is-active --quiet apache2; then
    echo; echo "ARRÊT : Apache ne démarre pas — rien n'a été créé ni déployé."; etat; exit 1
  fi
fi

# Le siège BuddyCorp : créé au premier passage, réécrit quand la liste des
# téléphones change (on compare une empreinte, pas les numéros).
siege() {
  local dom="${SIEGE_DOM:-}"
  [ -n "$dom" ] || { [ -n "$IP" ] && dom="admin.${IP//./-}.sslip.io"; }
  [ -n "$dom" ] || { echo "adresse IP inconnue : siège non créé" >&2; return 1; }
  local emp; emp=$(printf '%s' "$TELS" | sha256sum | cut -c1-16)
  if [ ! -f /srv/webshops/boutiques/siege/boutique.conf ] || { [ -n "$TELS" ] && [ "$(cat /etc/wsm/telefony.emp 2>/dev/null)" != "$emp" ]; }; then
    "$W" siege "$dom" --telefony "$TELS"
    [ -n "$TELS" ] && { install -d -m 700 /etc/wsm; echo "$emp" > /etc/wsm/telefony.emp; }
  fi
  [ -n "$SMSTOK" ] && printf '%s' "$SMSTOK" | "$W" siege-cle smsapi.token
  [ -n "$SMSNAD" ] && printf '%s' "$SMSNAD" | "$W" siege-cle smsapi.nadawca
  return 0
}
case "$ACTION" in
  push)
    siege
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
    "$W" deployer sandbox siege
    # La console de la sandbox aussi par l'adresse IP du serveur (client : « je veux
    # voir <IP>/backoffice ») : /backoffice et /shop seulement, la racine de l'IP
    # reste à ce qui la sert déjà.
    if [ -n "$IP" ]; then
      cur=$(sed -n 's/^ALIAS=//p' /srv/webshops/boutiques/sandbox/boutique.conf)
      if printf ',%s,' "$cur" | grep -qF ",$IP,"; then
        [ -f "/srv/webshops/apache/wsm-ip-${IP//./-}.conf" ] || "$W" apache sandbox
      else
        "$W" alias sandbox "${cur:+$cur,}$IP"
      fi
    fi
    ;;
  creer)
    # Une boutique de webshops/<boutique>/ (design, catalogue). Sans domaine :
    # une adresse provisoire <boutique>.<ip>.sslip.io (certificat compris).
    [[ "$BOUTIQUE" =~ ^[a-z0-9][a-z0-9-]{1,30}$ ]] || { echo "boutique invalide" >&2; exit 2; }
    if [ -d "/srv/webshops/boutiques/$BOUTIQUE" ]; then
      echo "La boutique « $BOUTIQUE » existe déjà : rien n'est recréé."
    else
      [ -n "$DOMAINE" ] || { [ -n "$IP" ] && DOMAINE="$BOUTIQUE.${IP//./-}.sslip.io"; }
      [ -n "$DOMAINE" ] || { echo "domaine inconnu" >&2; exit 1; }
      echo; echo "== création de la boutique $BOUTIQUE ($DOMAINE)"
      # La sortie de « wsm creer » contient le mot de passe : elle reste ici.
      if "$W" creer "$BOUTIQUE" "$DOMAINE" --webshop "$BOUTIQUE" > "/root/wsm-creation-$BOUTIQUE.log" 2>&1; then
        grep -v -i 'mot de passe' "/root/wsm-creation-$BOUTIQUE.log" || true
        echo "  (mot de passe administrateur : sudo cat /srv/webshops/boutiques/$BOUTIQUE/ADMIN.txt)"
      else
        grep -v -i 'mot de passe' "/root/wsm-creation-$BOUTIQUE.log" || true
        exit 1
      fi
    fi
    ;;
  demenager)
    # L'archive de l'ancien serveur (ancien-export.sh), déposée par GitHub.
    [[ "$BOUTIQUE" =~ ^[a-z0-9][a-z0-9-]{1,30}$ ]] || { echo "boutique invalide" >&2; exit 2; }
    [ -s /tmp/wsb-demenagement.tgz ] || { echo "archive de l'ancien serveur absente ou vide" >&2; exit 1; }
    "$W" demenager "$BOUTIQUE" /tmp/wsb-demenagement.tgz
    ;;
  valider)  "$W" valider ;;
  deployer) "$W" deployer "$BOUTIQUE" ;;
  retour)   "$W" retour "$BOUTIQUE" ;;
  liste)    etat ;;
  *) echo "action inconnue : $ACTION" >&2; exit 2;;
esac
echo
"$W" liste
