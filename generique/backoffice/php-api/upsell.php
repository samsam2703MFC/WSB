<?php
// ============================================================================
//  upsell.php — « ça pourrait aussi vous plaire », sans mentir sur le pourquoi.
//
//  UNE SUGGESTION EST UNE AFFIRMATION. Écrire « souvent acheté ensemble »
//  sous deux produits que personne n'a jamais commandés ensemble est un
//  mensonge — petit, invisible, et qui décrédibilise tout le reste de la page
//  le jour où quelqu'un s'en aperçoit. Cette boutique a 144 acheteurs :
//  assez pour que certains couples soient réels, pas assez pour que tous le
//  soient.
//
//  D'où la règle qui tient tout le fichier :
//
//      CHAQUE SUGGESTION PORTE SA SOURCE, ET L'ÉCRAN DIT LA VÉRITÉ.
//
//   • `razem`     — vraiment vus dans les mêmes commandes payées. Le libellé
//                   peut dire « często kupowane razem ».
//   • `kategoria` — même famille, prix voisin. Le libellé dit « z tej samej
//                   półki », ce qui est exact et n'invente aucune statistique.
//
//  QUATRE AUTRES RÈGLES :
//
//   1. JAMAIS CE QUI EST DÉJÀ DANS LE PANIER. Proposer ce qu'on vient
//      d'ajouter donne l'impression que la boutique ne suit pas.
//   2. JAMAIS CE QU'ON NE PEUT PAS VENDRE : produit retiré, invisible.
//      Le stock à zéro, lui, reste proposable — la commande passe et l'on
//      prévient, c'est déjà la règle de la caisse.
//   3. TROIS AU PLUS. Une liste de dix suggestions n'est plus une
//      suggestion, c'est un second catalogue, et elle ne fait rien vendre.
//   4. LE COMPTE SE FAIT SUR L'ENCAISSÉ. Une commande impayée ne prouve rien
//      sur ce que les gens achètent ensemble.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Combien de suggestions au plus. Trois : au-delà on ne choisit plus. */
const WSM_UPSELL_MAX = 3;

/** À partir de combien de commandes communes un couple est « réel ». */
const WSM_UPSELL_MIN_PAR = 2;

/**
 * Les produits réellement achetés dans les mêmes commandes payées.
 *
 * @return array<string,int>  id du produit => nombre de commandes communes
 */
function wsm_upsell_pairs(PDO $pdo, array $ids): array {
    $ids = array_values(array_filter(array_map('strval', $ids), fn($x) => $x !== ''));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare("SELECT b.product_id AS pid, COUNT(DISTINCT o.id) AS n
                               FROM wsm_order_items a
                               JOIN wsm_orders o ON o.id = a.order_id
                               JOIN wsm_order_items b ON b.order_id = a.order_id
                              WHERE a.product_id IN ($in)
                                AND b.product_id NOT IN ($in)
                                AND o.status <> 'anulowane'
                                AND o.payment_status = 'oplacone'
                           GROUP BY b.product_id
                           ORDER BY n DESC");
        $st->execute([...$ids, ...$ids]);
    } catch (Throwable $e) { return []; }

    $out = [];
    foreach ($st->fetchAll() ?: [] as $r) {
        $n = (int) $r['n'];
        if ($n < WSM_UPSELL_MIN_PAR) continue;      // un seul cas n'est pas une habitude
        $out[(string) $r['pid']] = $n;
    }
    return $out;
}

/**
 * Le repli : la même famille, au prix le plus voisin.
 *
 * Ce n'est PAS une statistique et ne doit jamais être présenté comme telle.
 * C'est un rayon : « à côté de celui-ci, il y a ceux-là ».
 *
 * @return string[] identifiants
 */
function wsm_upsell_voisins(PDO $pdo, array $ids, int $combien): array {
    if ($combien <= 0 || !$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        // Le prix de référence : celui du panier, par article. MIN() et non la
        // colonne nue : MySQL 8 (ONLY_FULL_GROUP_BY) refuse une colonne non
        // agrégée à côté d'AVG() — la requête tombait, et le repli avec elle.
        $st = $pdo->prepare("SELECT MIN(category_id) AS category_id, AVG(prix) AS p FROM wsm_products WHERE id IN ($in)");
        $st->execute($ids);
        $ref = $st->fetch() ?: null;
        if (!$ref) return [];

        $st = $pdo->prepare("SELECT id FROM wsm_products
                              WHERE active = 1 AND shop_visible = 1
                                AND id NOT IN ($in)
                                AND (category_id = ? OR ? = '')
                           ORDER BY ABS(prix - ?) ASC
                              LIMIT " . max(1, $combien));
        $st->execute([...$ids, (string) ($ref['category_id'] ?? ''),
                      (string) ($ref['category_id'] ?? ''), (float) ($ref['p'] ?? 0)]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) { return []; }
}

/**
 * Les suggestions pour un panier ou une fiche produit.
 *
 * @param array  $ids   ce que l'acheteur regarde ou a déjà pris
 * @param string $lang  la langue de la boutique
 * @return array [['product'=>array, 'source'=>'razem'|'kategoria', 'n'=>int], …]
 */
function wsm_upsell_for(PDO $pdo, array $ids, string $lang, int $max = WSM_UPSELL_MAX): array {
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn($x) => $x !== '')));
    if (!$ids) return [];
    if (!function_exists('wsm_shop_product')) require_once __DIR__ . '/shop.php';

    $out = []; $pris = $ids;

    // 1. Ce qui a VRAIMENT été acheté avec.
    foreach (wsm_upsell_pairs($pdo, $ids) as $pid => $n) {
        if (count($out) >= $max) break;
        if (in_array($pid, $pris, true)) continue;
        $p = wsm_upsell_produit($pdo, $pid, $lang);
        if (!$p) continue;                          // règle 2 : invendable, on saute
        $out[] = ['product' => $p, 'source' => 'razem', 'n' => $n];
        $pris[] = $pid;
    }

    // 2. Le repli, s'il reste de la place. Étiqueté autrement, parce que
    //    c'en est une autre.
    foreach (wsm_upsell_voisins($pdo, $pris, $max - count($out)) as $pid) {
        if (count($out) >= $max) break;
        if (in_array($pid, $pris, true)) continue;
        $p = wsm_upsell_produit($pdo, $pid, $lang);
        if (!$p) continue;
        $out[] = ['product' => $p, 'source' => 'kategoria', 'n' => 0];
        $pris[] = $pid;
    }
    return $out;
}

/** Le produit tel que la boutique le rend, ou null s'il n'est pas vendable. */
function wsm_upsell_produit(PDO $pdo, string $id, string $lang): ?array {
    try {
        $st = $pdo->prepare("SELECT * FROM wsm_products WHERE id = ? AND active = 1 AND shop_visible = 1");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) return null;
        return wsm_shop_row_to_product($r, wsm_shop_strings($pdo, $lang));
    } catch (Throwable $e) { return null; }
}

/**
 * La clé de libellé à afficher pour une source.
 *
 * On rend une CLÉ, pas un texte : les mots vivent dans wsm_shop_i18n, comme
 * tout le reste de la boutique, et se traduisent dans les huit langues.
 */
function wsm_upsell_cle(string $source): string {
    return $source === 'razem' ? 'upsell.together' : 'upsell.shelf';
}

/**
 * Ce que le back-office montre : les couples réels du catalogue.
 *
 * Utile pour décider d'un lot ou d'une mise en avant — et pour VOIR quand il
 * n'y a pas encore de données, plutôt que de croire à des suggestions qui
 * n'en sont pas.
 *
 * @return array [['a'=>id, 'b'=>id, 'nom_a'=>…, 'nom_b'=>…, 'n'=>int], …]
 */
function wsm_upsell_couples(PDO $pdo, int $limit = 20): array {
    try {
        $rows = $pdo->query("SELECT a.product_id AS pa, b.product_id AS pb,
                                    COUNT(DISTINCT o.id) AS n
                               FROM wsm_order_items a
                               JOIN wsm_orders o ON o.id = a.order_id
                               JOIN wsm_order_items b ON b.order_id = a.order_id
                              WHERE a.product_id < b.product_id
                                AND o.status <> 'anulowane' AND o.payment_status = 'oplacone'
                           GROUP BY a.product_id, b.product_id
                             HAVING n >= " . WSM_UPSELL_MIN_PAR . "
                           ORDER BY n DESC
                              LIMIT " . max(1, min(100, $limit)))->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }

    $nom = function (string $id) use ($pdo): string {
        $st = $pdo->prepare("SELECT nom FROM wsm_products WHERE id = ?");
        $st->execute([$id]);
        return (string) ($st->fetchColumn() ?: $id);
    };
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['a' => (string) $r['pa'], 'b' => (string) $r['pb'], 'n' => (int) $r['n'],
                  'nom_a' => $nom((string) $r['pa']), 'nom_b' => $nom((string) $r['pb'])];
    }
    return $out;
}
