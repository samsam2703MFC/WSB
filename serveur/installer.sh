#!/usr/bin/env bash
# =============================================================================
#  installer.sh — prépare un serveur NEUF (Ubuntu 22.04 / 24.04) pour héberger
#  toutes les boutiques. À lancer UNE fois, en root :
#
#    curl -fsSL https://raw.githubusercontent.com/samsam2703MFC/WSB/main/serveur/installer.sh \
#      | sudo bash -s -- --email vous@example.pl --cle "ssh-ed25519 AAAA… github-deploy"
#
#  Options :
#    --email <adresse>     administrateur (comptes des consoles, Let's Encrypt) — obligatoire
#    --cle "<clé publique>"  clé SSH publique que GitHub utilisera pour déployer
#                          (sa moitié PRIVÉE va dans les secrets GitHub, jamais ici)
#    --branche <nom>       branche du dépôt à suivre (défaut : main)
#    --depot <url>         dépôt git (défaut : le dépôt public du projet)
#
#  CE QU'IL FAIT : Apache + PHP 8 + MariaDB + certbot ; /srv/webshops ; l'outil
#  « wsm » ; un utilisateur « wsm-deploy » qui ne peut lancer QUE wsm ; une
#  sauvegarde des bases chaque nuit ; le pare-feu (SSH, HTTP, HTTPS) ; la
#  première version du code. Il ne crée aucune boutique : « wsm creer » le fait.
#
#  REJOUABLE : relancé, il complète ce qui manque et ne casse rien.
# =============================================================================
set -euo pipefail

EMAIL=""; CLE=""; BRANCHE="main"; DEPOT_URL="https://github.com/samsam2703MFC/WSB.git"
while [ $# -gt 0 ]; do case "$1" in
  --email) EMAIL="$2"; shift 2;; --cle) CLE="$2"; shift 2;;
  --branche) BRANCHE="$2"; shift 2;; --depot) DEPOT_URL="$2"; shift 2;;
  *) echo "option inconnue : $1" >&2; exit 2;; esac; done

dire()  { printf '\n== %s\n' "$*"; }
[ "$(id -u)" = 0 ] || { echo "à lancer en root (sudo)" >&2; exit 1; }
[ -n "$EMAIL" ] || { echo "--email vous@example.pl est obligatoire" >&2; exit 2; }
. /etc/os-release
[ "${ID:-}" = ubuntu ] || echo "ATTENTION : testé sur Ubuntu 22.04 / 24.04, ici ${PRETTY_NAME:-?}"
RACINE=/srv/webshops
SYSTEMD=0; [ -d /run/systemd/system ] && SYSTEMD=1

dire "paquets"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq apache2 libapache2-mod-php php-cli php-mysql php-gd php-intl php-mbstring php-curl \
  php-xml php-zip php-sqlite3 php-bcmath mariadb-server mariadb-client certbot python3-certbot-apache \
  git rsync curl openssl unzip ufw cron >/dev/null
echo "  $(php -v | head -1)"

dire "Apache"
a2enmod -q rewrite headers deflate expires php"$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" >/dev/null 2>&1 || a2enmod -q rewrite headers deflate expires
a2dissite -q 000-default >/dev/null 2>&1 || true
cat > /etc/apache2/conf-available/wsm.conf <<'CONF'
# Les boutiques (wsm) : rien sous /srv/webshops n'est servi, sauf ce que le
# site d'une boutique ouvre explicitement (sa version, ses photos).
<Directory /srv/webshops>
    Options -Indexes
    AllowOverride None
    Require all denied
</Directory>
ServerTokens Prod
ServerSignature Off
CONF
a2enconf -q wsm >/dev/null
PHPINI=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
for ini in /etc/php/"$PHPINI"/apache2/conf.d /etc/php/"$PHPINI"/cli/conf.d; do
  [ -d "$ini" ] && cat > "$ini/90-wsm.ini" <<'INI'
; Boutiques (wsm) : photos jusqu'à 8 Mo, factures PDF, pas de version PHP affichée.
upload_max_filesize = 16M
post_max_size = 20M
memory_limit = 256M
max_execution_time = 60
expose_php = Off
date.timezone = Europe/Warsaw
INI
done

dire "MariaDB"
if [ "$SYSTEMD" = 1 ]; then systemctl enable --now mariadb >/dev/null 2>&1 || true; else service mariadb start >/dev/null 2>&1 || true; fi
for i in 1 2 3 4 5 6 7 8 9 10; do mysql -uroot -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
mysql -uroot -e "DELETE FROM mysql.global_priv WHERE User=''; DROP DATABASE IF EXISTS test; FLUSH PRIVILEGES;" 2>/dev/null || true
echo "  root par socket uniquement (pas de mot de passe réseau)"

dire "dossiers et outil wsm"
mkdir -p "$RACINE"/{releases,boutiques,sauvegardes}
chmod 750 "$RACINE/sauvegardes"
if [ ! -f "$RACINE/wsm.conf" ]; then
  cat > "$RACINE/wsm.conf" <<CONF
# Réglages de wsm (sans secret).
DEPOT_URL="$DEPOT_URL"
BRANCHE="$BRANCHE"
EMAIL_ADMIN="$EMAIL"
# Les comptes qui voient l'écran Superadmin (taux, commission) de chaque console :
SUPERADMIN_EMAILS="$EMAIL"
CONF
fi
if [ ! -d "$RACINE/depot/.git" ]; then git clone --quiet --branch "$BRANCHE" "$DEPOT_URL" "$RACINE/depot"; fi
install -m 0755 "$RACINE/depot/serveur/wsm" /usr/local/sbin/wsm

dire "utilisateur de déploiement wsm-deploy (GitHub)"
id wsm-deploy >/dev/null 2>&1 || useradd --create-home --shell /bin/bash wsm-deploy
install -d -m 700 -o wsm-deploy -g wsm-deploy /home/wsm-deploy/.ssh
touch /home/wsm-deploy/.ssh/authorized_keys
if [ -n "$CLE" ] && ! grep -qF "$CLE" /home/wsm-deploy/.ssh/authorized_keys; then
  echo "$CLE" >> /home/wsm-deploy/.ssh/authorized_keys
  echo "  clé publique ajoutée"
fi
chown wsm-deploy:wsm-deploy /home/wsm-deploy/.ssh/authorized_keys; chmod 600 /home/wsm-deploy/.ssh/authorized_keys
# Il ne peut lancer QUE wsm, et seulement les commandes de déploiement.
cat > /etc/sudoers.d/wsm-deploy <<'SUDO'
wsm-deploy ALL=(root) NOPASSWD: /usr/local/sbin/wsm version *, /usr/local/sbin/wsm deployer *, /usr/local/sbin/wsm valider, /usr/local/sbin/wsm liste, /usr/local/sbin/wsm sante *, /usr/local/sbin/wsm retour *
SUDO
chmod 440 /etc/sudoers.d/wsm-deploy
visudo -cf /etc/sudoers.d/wsm-deploy >/dev/null

dire "sauvegardes chaque nuit (03:17)"
cat > /etc/cron.d/wsm-sauvegardes <<'CRON'
17 3 * * * root /usr/local/sbin/wsm sauvegarde --toutes >> /var/log/wsm-sauvegardes.log 2>&1
CRON

dire "pare-feu"
if command -v ufw >/dev/null && [ "$SYSTEMD" = 1 ]; then
  ufw allow OpenSSH >/dev/null; ufw allow 'Apache Full' >/dev/null
  # Le port SSH réel, s'il n'est pas 22 : ne jamais fermer la porte derrière soi.
  for p in $( (sshd -T 2>/dev/null || true) | awk '$1=="port"{print $2}'); do
    ufw allow "$p"/tcp >/dev/null
  done
  ufw --force enable >/dev/null && echo "  SSH, HTTP et HTTPS ouverts ; le reste fermé"
else
  echo "  ufw non activé (pas de systemd ici) — à faire à la main si besoin"
fi

dire "Apache démarré"
if [ "$SYSTEMD" = 1 ]; then systemctl enable --now apache2 >/dev/null; systemctl reload apache2; else apache2ctl start 2>/dev/null || apache2ctl graceful; fi

dire "première version du code"
/usr/local/sbin/wsm version "$BRANCHE"

cat <<FIN

== Serveur prêt.
   Prochaine étape — créer les boutiques, par exemple :
     sudo wsm creer sandbox  sandbox.VOTRE-DOMAINE.pl --webshop szoko
     sudo wsm creer mycobot  mycobotbuddy.pl
     sudo wsm creer poszla-w-las  poszlawlas.pl
   puis, quand le DNS de chaque domaine pointe ici :  sudo wsm https <boutique>
   Tout voir :  sudo wsm liste
FIN
