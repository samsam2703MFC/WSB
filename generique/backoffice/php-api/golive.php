<?php
// ============================================================================
//  golive.php — le grand ménage d'avant l'ouverture.
//
//  À QUOI ÇA SERT. Une boutique qu'on met au point accumule des centaines de
//  commandes de test, leurs documents, leurs mouvements de stock et leurs
//  courriers. Le jour de l'ouverture, ces chiffres ne sont pas seulement
//  inutiles : ils MENTENT. Le tableau de bord annonce un chiffre d'affaires
//  qui n'existe pas, les factures partent de FV/240 au lieu de FV/1, et le
//  stock affiche des quantités qui viennent d'un essai de septembre.
//
//  CE QUE ÇA EFFACE : ce qui raconte l'ACTIVITÉ. Commandes, paiements,
//  expéditions, documents, réclamations, mouvements de stock, courriers.
//
//  CE QUE ÇA NE TOUCHE JAMAIS : ce qui raconte la BOUTIQUE. Produits,
//  catégories, marques, prix, réglages, comptes, textes, modèles de courrier,
//  pays, modes de livraison, codes promo. Un ménage qui emporterait le
//  catalogue ne serait pas un ménage, ce serait un redémarrage à zéro.
//
//  LES STOCKS TOMBENT À ZÉRO, ils ne sont pas « conservés ». Le stock est le
//  résultat des mouvements ; effacer les mouvements en gardant les quantités
//  laisserait un nombre que plus rien ne justifie. On repart d'un magasin vide
//  et on compte pour de vrai — c'est le seul inventaire honnête.
//
//  IRRÉVERSIBLE, ET TRAITÉ COMME TEL : réservé au Superadmin, derrière le code
//  du jour, avec un mot à retaper et un décompte affiché AVANT.
// ============================================================================
declare(strict_types=1);

/** Le mot à retaper. Un bouton se clique par erreur ; un mot, non. */
const WSM_GOLIVE_MOT = 'ZERUJ';

/**
 * CE QUI SERA EFFACÉ, dans l'ordre où il faut l'effacer.
 *
 * L'ORDRE N'EST PAS DÉCORATIF : les enfants avant les parents. MySQL applique
 * les clés étrangères, et vider wsm_orders en premier ferait tomber la
 * transaction au milieu — on se retrouverait avec la moitié du ménage fait et
 * aucun moyen de savoir laquelle.
 *
 * @return list<string>
 */
function wsm_golive_tables(): array {
    return [
        // Les documents et leurs lignes
        'wsm_invoice_items', 'wsm_invoices',
        // Le parcours d'une commande
        'wsm_delivery_events', 'wsm_shipments',
        'wsm_payment_events', 'wsm_payments',
        'wsm_claims',
        'wsm_order_events', 'wsm_order_items', 'wsm_orders',
        // Le magasin
        'wsm_stock_moves', 'wsm_stock_docs',
        // Ce qui pend au bout : bons consommés, abonnements, courriers,
        // tentatives de recherche de commande.
        'wsm_voucher_uses',
        'wsm_subscription_items', 'wsm_subscriptions',
        'wsm_messages',
        'wsm_order_lookups',
        // Les indicateurs sont un résumé : recalculés, jamais saisis.
        'wsm_kpis',
    ];
}

/**
 * Combien de lignes le ménage emporterait, table par table.
 *
 * Affiché AVANT le geste. Un écran qui dit « ceci effacera 552 commandes et
 * 239 faktury » fait réfléchir ; un écran qui dit « êtes-vous sûr ? » fait
 * cliquer.
 *
 * @return array<string,int>
 */
function wsm_golive_compte(PDO $pdo): array {
    $out = [];
    foreach (wsm_golive_tables() as $t) {
        try { $out[$t] = (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(); }
        catch (Throwable $e) { /* table absente : rien à compter */ }
    }
    try { $out['stock_niezerowy'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM wsm_products WHERE stock <> 0")->fetchColumn(); }
    catch (Throwable $e) { }
    return $out;
}

/**
 * Le ménage lui-même.
 *
 * TOUT OU RIEN. Une transaction : si une table refuse, on remet tout en place.
 * Un ménage à moitié fait est pire que pas de ménage — il laisse des factures
 * qui nomment des commandes disparues, et personne ne sait où il s'est arrêté.
 *
 * @return array{0:bool,1:string,2:array<string,int>} [ok, message, lignes effacées]
 */
function wsm_golive_reset(PDO $pdo, string $actor): array {
    $avant = wsm_golive_compte($pdo);
    $fait  = [];
    $mysql = (wsm_config()['engine'] ?? '') === 'mysql';

    try {
        $pdo->beginTransaction();
        foreach (wsm_golive_tables() as $t) {
            try {
                $n = $pdo->exec("DELETE FROM $t");
                if ($n) $fait[$t] = (int) $n;
            } catch (Throwable $e) {
                // Table absente sur cette base : ce n'est pas une panne. Une
                // installation plus ancienne n'a pas forcément tout.
                if (!str_contains(strtolower($e->getMessage()), 'no such table')
                    && !str_contains(strtolower($e->getMessage()), "doesn't exist")) {
                    throw $e;
                }
            }
        }
        // LE STOCK EST UN RÉSULTAT, PAS UNE DONNÉE. On vient d'effacer les
        // mouvements qui le justifiaient : le laisser à 42 laisserait un
        // nombre que plus rien n'explique.
        $st = $pdo->prepare("UPDATE wsm_products SET stock = 0 WHERE stock <> 0");
        $st->execute();
        $fait['produkty_wyzerowane'] = $st->rowCount();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [false, 'Nie udało się wyczyścić: ' . $e->getMessage(), []];
    }

    // MySQL garde le compteur AUTO_INCREMENT après un DELETE : la première
    // vraie commande porterait l'identifiant 553. Les numéros visibles
    // (MS-…, FV/…) se dérivent des lignes existantes, donc eux repartent seuls
    // — mais un identifiant qui commence à 553 raconte une histoire fausse le
    // jour d'un audit.
    if ($mysql) {
        foreach (wsm_golive_tables() as $t) {
            try { $pdo->exec("ALTER TABLE `$t` AUTO_INCREMENT = 1"); }
            catch (Throwable $e) { /* pas d'auto-increment sur cette table */ }
        }
    }

    $resume = [];
    foreach ($fait as $t => $n) if ($n > 0) $resume[] = str_replace('wsm_', '', $t) . '=' . $n;
    if (function_exists('wsm_audit')) {
        wsm_audit($pdo, $actor, 'Zerowanie przed startem',
                  $resume ? implode(' · ', $resume) : 'nic do wyczyszczenia', 'Platforma');
    }
    return [true, $resume ? implode(' · ', $resume) : 'Nic nie było do wyczyszczenia.', $fait];
}
