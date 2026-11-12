# Audit fournisseurs & prestataires — spécification

**Où :** cockpit consultant, section **Pilotage**.
**Maquettes :** les 5 écrans de ce dossier (voir `README.md`).

Spécification indépendante de la technique du cockpit : les noms de tables et
de routes sont indicatifs, à aligner sur les conventions existantes.

---

## 1. Écrans

| Écran | Où | Maquette |
|-------|----|----------|
| Formulaire d'audit (questions, types, points, règles) | Pilotage › Audits fournisseurs | `1-formulaire` |
| Envoi d'une campagne + suivi des réponses | Pilotage › Audits fournisseurs › Envoi | `2-envoi-et-suivi` |
| Formulaire du franchisé (page publique, lien personnel) | `/audit/{token}`, hors connexion | `3-cote-franchise` |
| Résultats par fournisseur | Pilotage › Résultats fournisseurs | `4-resultats` |
| Copie au fournisseur avec la note du franchiseur | Pilotage › Résultats fournisseurs › Copies | `5-copie-fournisseur` |

## 2. Décisions

- **Un seul audit** pour tous les fournisseurs, organisé en sections.
- **Tronc commun** de 3 questions posé à chaque fournisseur (15 pts) +
  **questions propres** à chaque fournisseur (10 pts). Note sur **25** par fournisseur.
- **Tous les fournisseurs référencés** sont inclus automatiquement ; un nouveau
  fournisseur référencé reçoit le tronc commun sans action.
- **Le franchisé répond seul**, via un lien personnel reçu par **e-mail** et/ou
  **notification push web**.
- **Chaque fournisseur reçoit une copie** de ses résultats avec la **note du franchiseur**.

Choix par défaut (à confirmer) : noms des boutiques masqués dans la copie ;
le franchisé ne voit pas les points ; aucune copie sans validation.

## 3. Données

```
fournisseurs                (existant si possible : la liste des fournisseurs référencés)
  id, nom, categorie, genre ('fournisseur' | 'prestataire'), email_contact, reference (bool)

audit_formulaires
  id, titre, frequence_mois, seuil_bon (20), seuil_surveiller (15), statut

audit_questions
  id, formulaire_id,
  fournisseur_id NULL         -- NULL = tronc commun, posé pour chaque fournisseur
  ordre, libelle              -- peut contenir {fournisseur}
  type                        -- note_1_5 | oui_non | choix_unique | choix_multiple
                              -- | nombre | texte | photo | date
  options JSON                -- [{libelle, points}] pour oui_non / choix_unique
  calcul JSON                 -- {mode:'par_valeur'} | {mode:'note', coef:1}
                              -- | {mode:'paliers', paliers:[{max:0,pts:5},{max:2,pts:3},{pts:0}]}
  points_max, obligatoire, libelle_min, libelle_max (échelle)
  parent_id, condition JSON   -- sous-question affichée si la réponse parente correspond
  regle JSON                  -- {si:{op:'<=',valeur:2}, faire:['commentaire','action','alerte'],
                              --  titre_action:'Renégocier les prix'}
  vers_boite_idees (bool)     -- texte libre envoyé dans la Boîte à idées

audit_campagnes
  id, formulaire_id, nom ('S2 2026'), envoi_le, cloture_le,
  relances JSON ([5, 10, -1] = J+5, J+10, veille), canaux JSON (['email','push']),
  statut ('brouillon' | 'envoyee' | 'cloturee')

audit_invitations
  id, campagne_id, boutique_id, franchise_id,
  token_hash                  -- jeton aléatoire (≥ 32 octets), seul le hash est stocké
  envoye_le, ouvert_le, termine_le, derniere_relance_le

audit_reponses
  id, invitation_id, question_id, fournisseur_id,
  valeur JSON, points, commentaire, photo_url, maj_le
  UNIQUE (invitation_id, question_id, fournisseur_id)

audit_copies_fournisseur
  id, campagne_id, fournisseur_id, note_franchiseur,
  blocs JSON                  -- score, moyennes, continuer, commentaires choisis, détail
  masquer_boutiques (bool, défaut vrai), joindre_pdf (bool),
  statut ('a_ecrire' | 'brouillon' | 'envoyee'), envoyee_le

push_abonnements
  id, franchise_id, endpoint, p256dh, auth, cree_le
```

## 4. Calcul des points

- Chaque réponse notée reçoit ses `points` à l'enregistrement, selon `calcul` :
  - `par_valeur` : points de l'option choisie (Oui 5 / Réserves 2 / Non 0) ;
  - `note` : note × coef (note 1–5 → 1–5 pts) ;
  - `paliers` : nombre → points (ruptures 0 → 5, 1–2 → 3, 3+ → 0).
- Texte, choix multiple, photo, date : **non notés**, hors score.
- **Score d'une boutique pour un fournisseur** = somme des points de ses questions
  (tronc commun + propres), sur 25.
- **Score réseau d'un fournisseur** = moyenne des boutiques ayant terminé ; affiché en %.
- **Statut** : ≥ 20 Bon · 15–19 À surveiller · < 15 Revoir le contrat (crée une action).
- **Évolution** : comparaison avec la campagne précédente du même formulaire.

## 5. Règles déclenchées à la soumission

- Condition remplie (ex. note ≤ 2, réponse « Non ») :
  - `commentaire` → commentaire obligatoire côté franchisé ;
  - `action` → entrée dans **Plans d'action**, liée au fournisseur (et à la boutique) ;
  - `alerte` → notification au siège (« Fournisseur remis en question »).
- `vers_boite_idees` → le texte crée une idée dans la **Boîte à idées**.
- Côté résultats, les actions sont regroupées : « Renégocier les tarifs — 7 boutiques sur 11 ».

## 6. Envoi aux franchisés

- À l'envoi : une invitation par boutique active, avec un **lien personnel**
  `/audit/{token}` — sans compte, valable jusqu'à la clôture.
- **E-mail** à chaque franchisé ; **notification push web** à ceux qui l'ont acceptée.
  - Push web (VAPID) : proposé à la première ouverture du lien ; sur iPhone il faut
    d'abord ajouter la page à l'écran d'accueil → l'e-mail reste le canal principal.
- **Relances automatiques** (tâche planifiée) : J+5, J+10, veille de clôture,
  uniquement aux invitations non terminées. Bouton « Relancer » à l'unité.
- Suivi : pas ouvert / en cours (x fournisseurs sur 5) / répondu, avec la date.

## 7. Formulaire du franchisé

- Un écran par fournisseur ; sous-questions conditionnelles affichées à la volée.
- Enregistrement à chaque écran : on peut fermer et reprendre avec le même lien.
- Les points ne sont pas affichés.
- Fin : confirmation, réponses modifiables jusqu'à la clôture.
- Sécurité : jeton comparé par son hash, page en `noindex`, jeton expiré après clôture.

## 8. Copie au fournisseur

- À la clôture : un brouillon par fournisseur, statut « note à écrire ».
- Le franchiseur écrit sa note, coche les blocs et choisit les commentaires
  (noms des boutiques retirés si `masquer_boutiques`).
- Aperçu, « m'envoyer un test », puis envoi validé à la main : e-mail + PDF.
- Le statut de chaque copie est visible (brouillon, envoyée le…).

## 9. Questions ouvertes

1. Confirmer les trois choix par défaut (anonymat, points masqués, validation).
2. Les fournisseurs référencés existent-ils déjà dans le cockpit (table à réutiliser) ?
3. Le cockpit a-t-il déjà un envoi d'e-mails et une tâche planifiée à réutiliser
   pour les relances ?
