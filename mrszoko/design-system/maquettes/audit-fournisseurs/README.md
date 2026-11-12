# Maquettes : audit des fournisseurs et prestataires (suivi franchisé)

Spécification de développement : `SPEC.md` (cockpit consultant › Pilotage).

Maquettes statiques, **non déployées** (le dossier `design-system/` n'est pas
copié sur le serveur). Chaque écran existe en `.png` (capture) et en `.html`
(s'ouvre depuis le dépôt ; il réutilise les feuilles de style de la console).

| # | Écran | Ce qu'il montre |
|---|-------|-----------------|
| 1 | `1-formulaire` | Le formulaire : tronc commun posé à chaque fournisseur + questions propres à chacun, type de réponse, points, règles, aperçu téléphone |
| 2 | `2-envoi-et-suivi` | L'envoi aux franchisés (e-mail + notification push), les relances, le suivi des réponses boutique par boutique |
| 3 | `3-cote-franchise` | Ce que voit le franchisé en ouvrant son lien : un écran par fournisseur, reprise possible, confirmation |
| 4 | `4-resultats` | Les résultats du réseau par fournisseur : classement, réponses par boutique, moyennes, évolution, actions |
| 5 | `5-copie-fournisseur` | La copie envoyée à chaque fournisseur, avec la note du franchiseur |

## Décisions prises

- **Un seul audit** pour tous les fournisseurs (maquette A), avec un tronc commun
  de 3 questions (continuer ? / prix / délais et réactivité, 15 pts) et 10 pts
  de questions propres à chaque fournisseur. Chaque fournisseur est noté sur 25.
- **Tous les fournisseurs référencés** sont inclus automatiquement : Glace
  Rawette (glaces), TFBuddy (IT et développement numérique), SLFood
  (marchandise), Bruyerre (matières premières), Collectif marketing.
- **Le franchisé répond seul**, via un lien personnel reçu par e-mail ou par
  notification push.
- **Chaque fournisseur reçoit une copie** de ses résultats, avec la note du
  franchiseur.

## Choix par défaut à confirmer

- La copie au fournisseur masque le nom des boutiques (interrupteur).
- Le franchisé ne voit pas les points, seulement les questions.
- Aucune copie ne part sans validation du franchiseur.

Les noms de boutiques, notes et commentaires sont des données d'exemple.
