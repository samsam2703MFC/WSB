#!/bin/sh
# Le seul geste qu'une clé de déploiement peut accomplir sur ce serveur.
#
# Installé en /usr/local/bin/tfb-sync-trigger et imposé par la commande forcée de
# ~deploy/.ssh/authorized_keys, ce script est exécuté quoi que le client demande.
# La commande que le client a tenté d'envoyer est journalisée, jamais exécutée.
#
#   sudo cp deploy/tfb-sync-trigger.sh /usr/local/bin/tfb-sync-trigger
#   sudo chmod 755 /usr/local/bin/tfb-sync-trigger
#   sudo chown root: /usr/local/bin/tfb-sync-trigger
set -eu

# Ce que le client voulait dire — le dépôt et le commit à l'origine du
# déclenchement. Purement informatif, et traité comme du texte, jamais comme une
# commande.
origine=$(printf '%s' "${SSH_ORIGINAL_COMMAND:-inconnu}" | tr -cd '[:alnum:]@/._: -' | cut -c1-120)
logger -t tfb-sync-trigger "déclenchement par ${SSH_CLIENT%% *} — ${origine}"

# --no-block : la session SSH rend la main tout de suite. La CI n'a pas à
# attendre la fin de la sync, et une sync lente ne fait pas échouer un workflow.
exec sudo -n /bin/systemctl start --no-block tfb-sync.service
