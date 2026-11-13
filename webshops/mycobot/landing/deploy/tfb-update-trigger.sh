#!/bin/sh
# Le second geste qu'une clé de déploiement peut accomplir — mettre le code à
# jour. Même montage que tfb-sync-trigger : commande forcée dans authorized_keys,
# ce qui est envoyé par le client est journalisé, jamais exécuté.
#
#   sudo cp deploy/tfb-update-trigger.sh /usr/local/bin/tfb-update-trigger
#   sudo chmod 755 /usr/local/bin/tfb-update-trigger
#   sudo chown root: /usr/local/bin/tfb-update-trigger
#
# Une clé par geste : la clé qui déploie ne doit pas être celle qui synchronise,
# sinon le compromis de l'une donne l'autre.
set -eu

origine=$(printf '%s' "${SSH_ORIGINAL_COMMAND:-inconnu}" | tr -cd '[:alnum:]@/._: -' | cut -c1-120)
logger -t tfb-update-trigger "déclenchement par ${SSH_CLIENT%% *} — ${origine}"

# --no-block ici aussi : un build dure des minutes, et un workflow qui attend
# finirait par expirer sur un déploiement parfaitement réussi.
exec sudo -n /bin/systemctl start --no-block tfb-update.service
