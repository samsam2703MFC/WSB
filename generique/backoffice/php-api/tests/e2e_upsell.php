<?php
// ============================================================================
//  e2e_upsell.php — preuve que les suggestions ne mentent pas.
//
//  UNE SUGGESTION EST UNE AFFIRMATION. « Souvent acheté ensemble » sous deux
//  produits que personne n'a jamais commandés ensemble est un mensonge —
//  petit, invisible, et qui décrédibilise le reste de la page le jour où
//  quelqu'un le remarque.
//
//  Ce qui est démontré :
//
//   1. « RAZEM » N'EST ÉCRIT QUE SUR DU RÉEL. Deux commandes payées au moins,
//      sinon ce n'est pas une habitude, c'est un hasard.
//   2. LE REPLI S'ANNONCE COMME UN REPLI : « z tej samej półki », jamais
//      « często kupowane razem ».
//   3. UNE COMMANDE IMPAYÉE NE PROUVE RIEN.
//   4. ON NE PROPOSE JAMAIS CE QUI EST DÉJÀ DANS LE PANIER.
//   5. NI CE QU'ON NE PEUT PAS VENDRE.
//   6. TROIS AU PLUS.
//
//  Usage :  php tests/e2e_upsell.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/upsell.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end propozycje produktów\n\n";

$sfx = bin2hex(random_bytes(3));
register_shutdown_function(function () use ($pdo, $sfx) {
    try {
        $pdo->exec("DELETE FROM wsm_order_items WHERE order_id IN
                      (SELECT id FROM wsm_orders WHERE email LIKE '%$sfx@example.com')");
        $pdo->exec("DELETE FROM wsm_orders WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE product_id LIKE 'test-ups-$sfx%'");
        $pdo->exec("DELETE FROM wsm_stock_moves WHERE product_id LIKE 'test-ups-$sfx%'");
        $pdo->exec("DELETE FROM wsm_products WHERE id LIKE 'test-ups-$sfx%'");
        $pdo->exec("DELETE FROM wsm_categories WHERE name LIKE 'Test upsell $sfx'");
    } catch (Throwable $e) { /* le nettoyage ne masque jamais le résultat */ }
});

// Une catégorie à nous : sinon le repli irait chercher tout le catalogue réel
// et le test dépendrait de données qu'il ne maîtrise pas.
// L'identifiant de catégorie est un ENTIER auto-incrémenté : on le laisse
// faire et on relit la clé, plutôt que de lui imposer une chaîne.
$pdo->prepare("INSERT INTO wsm_categories (name, sort_order, active) VALUES (?,999,1)")
    ->execute(['Test upsell ' . $sfx]);
$catId = (string) $pdo->lastInsertId();

$mk = function (string $s, float $prix, int $visible = 1) use ($pdo, $catId, $sfx): string {
    $id = "test-ups-$sfx-$s";
    $pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible,
                        slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, base_cost)
                   VALUES (?,?,?,?,'Opublikowany',1,?,?,50,0.23,250,200,150,100,?,5.00)")
         ->execute([$id, $catId, "Ups $s $sfx", $prix, $visible, $id, strtoupper($s . $sfx)]);
    return $id;
};
$A = $mk('a', 50.00);       // celui qu'on regarde
$B = $mk('b', 55.00);       // acheté avec, deux fois → « razem »
$C = $mk('c', 52.00);       // acheté avec, une seule fois → PAS « razem »
$D = $mk('d', 51.00);       // jamais acheté → repli possible
$E = $mk('e', 53.00, 0);    // invisible : ne doit JAMAIS sortir

$cmd = function (array $prods, string $paiement) use ($pdo, $sfx): int {
    $pdo->prepare("INSERT INTO wsm_orders (code, access_token, email, first_name, last_name, lang,
                        status, payment_status, items_net, items_gross, shipping_net, shipping_gross,
                        total_net, total_gross, delivery_method, created_at)
                   VALUES (?,?,?,'Test','Ups','pl','dostarczone',?,0,0,0,0,0,0,'inpost_courier',?)")
         ->execute(['MS-UPS-' . strtoupper(bin2hex(random_bytes(3))), bin2hex(random_bytes(8)),
                    "ups.$sfx@example.com", $paiement, date('Y-m-d H:i:s')]);
    $oid = (int) $pdo->lastInsertId();
    foreach ($prods as $pid) {
        $pdo->prepare("INSERT INTO wsm_order_items (order_id, product_id, name, qty, unit_gross,
                            unit_net, vat_rate, line_net, line_vat, line_gross)
                       VALUES (?,?,?,1,5000,4065,0.23,4065,935,5000)")
             ->execute([$oid, $pid, 'Ups']);
    }
    return $oid;
};

// ---- 1. « Razem » ne s'écrit que sur du réel ------------------------------------------
echo "-- « razem » tylko na prawdziwych parach --\n";
$cmd([$A, $C], 'oplacone');                    // A+C : une seule fois
$paires = wsm_upsell_pairs($pdo, [$A]);
ok('un seul achat commun ne fait pas une habitude', !isset($paires[$C]), $paires);

$cmd([$A, $B], 'oplacone');
$cmd([$A, $B], 'oplacone');                    // A+B : deux fois
$paires = wsm_upsell_pairs($pdo, [$A]);
ok('deux achats communs, oui', ($paires[$B] ?? 0) === 2, $paires);
ok('et le produit regardé ne se propose pas lui-même', !isset($paires[$A]), $paires);

// ---- 2. Une commande impayée ne prouve rien --------------------------------------------
echo "\n-- niezapłacone zamówienie niczego nie dowodzi --\n";
$cmd([$A, $D], 'oczekuje');
$cmd([$A, $D], 'oczekuje');                    // deux fois, mais IMPAYÉES
$paires = wsm_upsell_pairs($pdo, [$A]);
ok('deux commandes impayées ne créent pas un couple', !isset($paires[$D]), $paires);

// ---- 3. Chaque suggestion porte sa source ----------------------------------------------
echo "\n-- każda propozycja niesie swoje źródło --\n";
$sug = wsm_upsell_for($pdo, [$A], 'pl');
ok('il y a des suggestions', count($sug) > 0, count($sug));
$parId = [];
foreach ($sug as $x) $parId[$x['product']['id']] = $x;
ok('B est proposé', isset($parId[$B]));
ok('et marqué « razem » — c\'est vrai', ($parId[$B]['source'] ?? '') === 'razem', $parId[$B]['source'] ?? null);
ok('avec le nombre de commandes communes', (int) ($parId[$B]['n'] ?? 0) === 2, $parId[$B]['n'] ?? null);

foreach ($sug as $x) {
    if ($x['source'] === 'kategoria') {
        ok('un repli ne prétend JAMAIS à une statistique', (int) $x['n'] === 0, $x['n']);
        break;
    }
}
// Le repli lui-même, appelé seul : sur MySQL 8, sa première requête (AVG et
// une colonne nue) tombait sous ONLY_FULL_GROUP_BY, le try l'avalait, et la
// boutique n'en proposait plus aucun — sans qu'aucun test ne le voie.
ok('le repli « même rayon » répond — MySQL compris', count(wsm_upsell_voisins($pdo, [$A], 3)) > 0);
ok('la clé de libellé distingue les deux',
   wsm_upsell_cle('razem') !== wsm_upsell_cle('kategoria'));
ok('et « razem » a bien sa propre clé', wsm_upsell_cle('razem') === 'upsell.together');

// ---- 4. Jamais ce qui est déjà dans le panier -------------------------------------------
echo "\n-- nigdy tego, co już jest w koszyku --\n";
$sug2 = wsm_upsell_for($pdo, [$A, $B], 'pl');
$ids2 = array_map(fn($x) => $x['product']['id'], $sug2);
ok('A n\'est pas proposé', !in_array($A, $ids2, true), $ids2);
ok('B non plus — il est déjà pris', !in_array($B, $ids2, true), $ids2);

// ---- 5. Jamais ce qu'on ne peut pas vendre -----------------------------------------------
echo "\n-- nigdy tego, czego nie da się sprzedać --\n";
$cmd([$A, $E], 'oplacone');
$cmd([$A, $E], 'oplacone');                    // E est un vrai couple… mais invisible
$sug3 = wsm_upsell_for($pdo, [$A], 'pl');
$ids3 = array_map(fn($x) => $x['product']['id'], $sug3);
ok('un produit invisible n\'est jamais proposé, même s\'il forme un vrai couple',
   !in_array($E, $ids3, true), $ids3);

// ---- 6. Trois au plus -----------------------------------------------------------------------
echo "\n-- najwyżej trzy --\n";
ok('jamais plus de trois suggestions', count($sug3) <= WSM_UPSELL_MAX, count($sug3));
ok('et la borne vaut bien 3', WSM_UPSELL_MAX === 3);
$sug4 = wsm_upsell_for($pdo, [$A], 'pl', 1);
ok('la borne demandée est respectée', count($sug4) <= 1, count($sug4));

// ---- 7. Un panier vide ne propose rien ------------------------------------------------------
echo "\n-- pusty koszyk niczego nie proponuje --\n";
ok('aucune suggestion sur un panier vide', wsm_upsell_for($pdo, [], 'pl') === []);
ok('ni sur un identifiant inconnu',
   count(wsm_upsell_for($pdo, ['nie-ma-takiego-produktu'], 'pl')) >= 0);   // ne doit pas planter

// ---- 8. Les couples pour le back-office -----------------------------------------------------
echo "\n-- pary dla konsoli --\n";
$couples = wsm_upsell_couples($pdo, 50);
$vu = null;
foreach ($couples as $c) {
    if (($c['a'] === $A && $c['b'] === $B) || ($c['a'] === $B && $c['b'] === $A)) $vu = $c;
}
ok('le couple A+B apparaît', $vu !== null, count($couples));
ok('avec son compte', $vu && (int) $vu['n'] === 2, $vu['n'] ?? null);
ok('et les noms en clair, pas les identifiants',
   $vu && str_contains((string) $vu['nom_a'], 'Ups'), $vu['nom_a'] ?? null);

// ---- 9. Les libellés existent dans les trois langues servies ---------------------------------
echo "\n-- etykiety istnieją we wszystkich serwowanych językach --\n";
foreach (['pl', 'en', 'uk'] as $l) {
    $S = wsm_shop_strings($pdo, $l);
    ok("« upsell.title » existe en $l", trim((string) ($S['upsell.title'] ?? '')) !== '', $l);
    ok("« upsell.together » existe en $l", trim((string) ($S['upsell.together'] ?? '')) !== '', $l);
    ok("« upsell.shelf » existe en $l", trim((string) ($S['upsell.shelf'] ?? '')) !== '', $l);
}

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
