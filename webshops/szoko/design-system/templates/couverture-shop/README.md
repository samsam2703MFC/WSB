# Webshop couverture (B2B) — prototype cliquable

Recréation haute-fidélité du webshop **Mister Szoko Pro** : chocolat de couverture
en callets pour les pros de la pâtisserie (marché polonais). Front boutique +
back-office admin, entièrement composé avec le design system.

**Ouvrir :** `index.html` · Prototype de design, pas de code de production.

## Parcours
- **Boutique** : hero, réassurances pro, catalogue filtrable (recherche live),
  fiches, packs & bundles avec économie affichée.
- **Fiche produit** : sélecteur de conditionnement (2,5 / 10 / 20 kg) qui met à
  jour prix + prix/kg, **prix HT en avant, TTC dessous**, fluidité, origine,
  cacao, ingrédients, allergènes, paliers de remise volume, cross-sell.
- **Panier (drawer)** : barre franco de port, **upsell de format** (4×2,5 kg →
  10 kg), **suggestion de bundle**, cross-sell 1 clic, sous-total HT.
- **Checkout** en 4 étapes : adresse → livraison → **TVA & VIES** (n° intracom
  validé, reverse charge auto hors PL) → paiement (**BLIK en PLN**, carte),
  récap HT/TVA/TTC dynamique → **confirmation** avec facture PDF + création compte.
- **Connexion** : espace pro (connexion / inscription / invité) + accès admin.
- **Back-office** (`Accès administrateur` depuis le login) : tableau de bord (CA,
  commandes, part bundles, top réfs), produits & variantes, commandes, factures
  (PDF / avoir / reverse charge), clients, paramètres (vendeur, TVA/OSS, livraison).

## Sélecteurs
Langue **PL / EN / CS / UK** et devise **PLN / EUR** dans le header (PLN par
défaut ; BLIK proposé uniquement en PLN). L'interface de démo est en français.

## Fichiers
`data.js` (catalogue, variantes, bundles, cross-sell, config) · `Bits.jsx`
(ProCard, Fluidity, PriceHT) · `Header.jsx` · `Home.jsx` · `ProductPage.jsx` ·
`Cart.jsx` · `Checkout.jsx` · `Login.jsx` · `Admin.jsx` · `App.jsx`.

## Limites (prototype)
Aucune vraie photo produit (dégradés chocolat labellisés « Photo produit »).
VIES, Stripe/BLIK, génération PDF et calcul TVA sont **simulés côté client** pour
la démo — la vraie logique (PHP/MySQL, API VIES, webhooks Stripe) reste à
implémenter côté serveur. Ce prototype sert de spécification visuelle.
