<?php
// ============================================================================
//  e2e_analytics.php — preuve que la marge affichée est la vraie marge.
//
//  Un graphique faux est pire qu'un graphique absent : on le croit. Ce qui est
//  démontré ici, dans l'ordre du danger :
//
//   1. UN PRODUIT SANS COÛT N'EST PAS UNE MARGE DE 100 %. C'est l'erreur qui
//      transforme un catalogue à moitié renseigné en affaire très rentable.
//      Il est EXCLU du calcul, et la couverture dit sur quelle part on a pu
//      compter.
//   2. LE COÛT EST CELUI DE LA VENTE, PAS D'AUJOURD'HUI. Changer le prix
//      d'achat d'un produit ne doit pas réécrire la marge du mois dernier.
//   3. LE PORT SE COMPTE DANS LES DEUX SENS. Ce que le client paie et ce que
//      le transporteur facture sont deux nombres distincts ; leur écart sort
//      de la marge.
//   4. UNE PRÉVISION DIT SA FIABILITÉ. Trois jours extrapolés sur trente-et-un
//      n'ont pas la même valeur que vingt.
//
//  Usage :  php tests/e2e_analytics.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/analytics.php';
require_once dirname(__DIR__) . '/mail.php';
$pdo = wsm_bootstrap();
wsm_mail_transport(fn(array $m) => [true, '']);   // aucun e-mail ne part d'un test

echo "webshop_mrszoko — end-to-end analityka (marża · dostawa · prognoza)\n\n";

// ---- Un jeu d'essai isolé ----------------------------------------------------
//  Deux produits : l'un avec un prix de revient, l'autre SANS. C'est tout le
//  piège de cet écran.
$sfx = bin2hex(random_bytes(3));
$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$mk = function (string $nom, float $prix, $cost) use ($pdo, $cat, $sfx): string {
    $id = 'test-an-' . $sfx . '-' . substr(md5($nom), 0, 4);
    $pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, base_cost, statut, active,
                        shop_visible, slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku)
                   VALUES (?,?,?,?,?,'Opublikowany',1,1,?,500,0.23,200,120,80,40,?)")
         ->execute([$id, $cat, $nom, $prix, $cost, $id, strtoupper(substr($id, -6))]);
    return $id;
};
// Prix TTC 123,00 → net 100,00 exactement à 23 %. Coût 40,00 → marge 60,00.
$avec = $mk('Analiza z kosztem ' . $sfx, 123.00, 40.00);
$sans = $mk('Analiza bez kosztu ' . $sfx, 123.00, 0);

$base = [
    'lang' => 'pl', 'delivery_method' => 'inpost_locker', 'inpost_point' => 'WRO01A',
    'email' => 'analiza.' . $sfx . '@example.com', 'phone' => '600100200', 'consent_terms' => 1,
    'client_type' => 'osoba', 'first_name' => 'Jan', 'last_name' => 'Testowy',
];

// ---- 1. Le coût est figé sur la ligne ----------------------------------------
echo "-- koszt zamrożony na pozycji --\n";
$cols = wsm_table_columns($pdo, 'wsm_order_items');
ok('la colonne du coût existe', in_array('unit_cost', $cols, true), $cols);

[$o1, $e1] = wsm_shop_create_order($pdo, $base + ['items' => [['id' => $avec, 'qty' => 2]]]);
ok('commande créée', $o1 !== null, $e1);
$st = $pdo->prepare("SELECT unit_cost, qty, line_net FROM wsm_order_items WHERE order_id = ?");
$st->execute([(int) $o1['id']]);
$l1 = $st->fetch();
ok('le coût est recopié sur la ligne, en grosze', (int) $l1['unit_cost'] === 4000, $l1['unit_cost'] ?? null);

// On change le prix d'achat APRÈS la vente : la ligne ne doit pas bouger.
$pdo->prepare("UPDATE wsm_products SET base_cost = ? WHERE id = ?")->execute([99.00, $avec]);
$st->execute([(int) $o1['id']]);
$l1b = $st->fetch();
ok('changer le prix d\'achat ne réécrit pas la vente passée',
    (int) $l1b['unit_cost'] === 4000, $l1b['unit_cost'] ?? null);
$pdo->prepare("UPDATE wsm_products SET base_cost = ? WHERE id = ?")->execute([40.00, $avec]);

// Le coût ne doit JAMAIS partir vers la boutique publique.
[$q] = wsm_shop_quote($pdo, [['id' => $avec, 'qty' => 1]], 'inpost_locker', 'pl');
$json = json_encode($q, JSON_UNESCAPED_UNICODE);
ok('le devis public ne contient pas de prix d\'achat',
    !str_contains($json, 'unit_cost') && !str_contains($json, 'base_cost'));
$pub = json_encode(wsm_shop_products($pdo, 'pl'), JSON_UNESCAPED_UNICODE);
ok('le catalogue public non plus', !str_contains($pub, 'base_cost'));

// ---- 2. La marge se calcule, et seulement là où on sait ----------------------
echo "\n-- marża liczona tylko tam, gdzie znamy koszt --\n";

// Mesuré en DELTA autour de l'encaissement : sur une base partagée, comparer
// un total mensuel à une constante ne prouve rien — ce qui compte est ce que
// CETTE vente ajoute.
$m0 = wsm_margin_series($pdo, 1)[0];
$pdo->prepare("UPDATE wsm_orders SET payment_status = 'oplacone' WHERE id = ?")->execute([(int) $o1['id']]);
$m1 = wsm_margin_series($pdo, 1)[0];

// 2 × (100,00 net − 40,00 coût) = 120,00 zł, soit 12 000 grosze.
ok('la marge d\'une ligne connue vaut net moins coût, à l\'unité près',
    $m1['margin'] - $m0['margin'] === 12000, $m1['margin'] - $m0['margin']);
ok('une commande impayée ne comptait pas encore',
    $m1['orders'] - $m0['orders'] === 1, [$m0['orders'], $m1['orders']]);

$avant = wsm_margin_series($pdo, 1)[0];
[$o2] = wsm_shop_create_order($pdo, $base + ['items' => [['id' => $sans, 'qty' => 2]]]);
$pdo->prepare("UPDATE wsm_orders SET payment_status = 'oplacone' WHERE id = ?")->execute([(int) $o2['id']]);
$apres = wsm_margin_series($pdo, 1)[0];

ok('la vente sans coût augmente bien le chiffre d\'affaires',
    $apres['revenue'] > $avant['revenue'], [$avant['revenue'], $apres['revenue']]);
ok('mais elle n\'ajoute AUCUNE marge — pas 100 %',
    $apres['margin'] === $avant['margin'], [$avant['margin'], $apres['margin']]);
// On compare les montants bruts, pas le pourcentage arrondi : sur une base de
// développement déjà chargée, deux ventes ne déplacent pas la deuxième décimale.
ok('la part réellement costée, elle, ne bouge pas',
    $apres['revenue_costed'] === $avant['revenue_costed'],
    [$avant['revenue_costed'], $apres['revenue_costed']]);
ok('donc la couverture du calcul se dégrade (plus de CA, autant de costé)',
    $apres['revenue'] > $apres['revenue_costed'],
    [$apres['revenue'], $apres['revenue_costed']]);
ok('la couverture est un pourcentage sensé',
    $apres['cost_known_pct'] >= 0 && $apres['cost_known_pct'] <= 100, $apres['cost_known_pct']);


// ---- 3. Le port : deux nombres, pas un -----------------------------------------
echo "\n-- dostawa liczona w obie strony --\n";
$saveCost = $pdo->query("SELECT cost_net FROM wsm_shipping_methods WHERE id = 'inpost_locker'")->fetchColumn();
$savePrix = (int) $pdo->query("SELECT price_net FROM wsm_shipping_methods WHERE id = 'inpost_locker'")->fetchColumn();
// Le seuil aussi, tel qu'il était : le remettre « à 200 zł » de mémoire
// effaçait celui que l'équipe a choisi (700 zł depuis octobre 2026).
$saveFree = (int) $pdo->query("SELECT free_from FROM wsm_shipping_methods WHERE id = 'inpost_locker'")->fetchColumn();

// On vend le port 10,00 et il nous coûte 20,00 : le client en couvre la moitié.
$pdo->prepare("UPDATE wsm_shipping_methods SET price_net = 1000, cost_net = 2000, free_from = 0 WHERE id = ?")
    ->execute(['inpost_locker']);
$c = wsm_ship_costs($pdo);
ok('le coût transporteur est lu', ($c['inpost_locker']['cost'] ?? 0) === 2000, $c['inpost_locker'] ?? null);
ok('et il est signalé comme saisi', ($c['inpost_locker']['known'] ?? false) === true);

[$o3] = wsm_shop_create_order($pdo, $base + ['items' => [['id' => $avec, 'qty' => 1]]]);
$pdo->prepare("UPDATE wsm_orders SET payment_status = 'oplacone' WHERE id = ?")->execute([(int) $o3['id']]);
$m = wsm_margin_series($pdo, 1)[0];
ok('le port facturé au client est compté', $m['ship_paid'] > 0, $m['ship_paid']);
ok('le coût transporteur aussi, et il est plus élevé',
    $m['ship_cost'] > $m['ship_paid'], [$m['ship_paid'], $m['ship_cost']]);
ok('l\'écart est exactement coût moins payé',
    $m['ship_gap'] === $m['ship_cost'] - $m['ship_paid'], $m['ship_gap']);
ok('le résultat est la marge diminuée de cet écart',
    $m['result'] === $m['margin'] - $m['ship_gap'], [$m['margin'], $m['ship_gap'], $m['result']]);
ok('la couverture tombe sous 100 %', $m['coverage_pct'] < 100, $m['coverage_pct']);
ok('et elle vaut payé / coût', abs($m['coverage_pct'] - round($m['ship_paid'] / $m['ship_cost'] * 100, 1)) < 0.05,
    $m['coverage_pct']);

// Sans coût saisi, on retombe sur le tarif — et on le dit.
$pdo->prepare("UPDATE wsm_shipping_methods SET cost_net = 0 WHERE id = ?")->execute(['inpost_locker']);
$c2 = wsm_ship_costs($pdo);
ok('sans coût saisi, on prend le tarif de vente', ($c2['inpost_locker']['cost'] ?? 0) === 1000, $c2['inpost_locker'] ?? null);
ok('et l\'écran est averti que ce n\'est pas un vrai coût',
    ($c2['inpost_locker']['known'] ?? true) === false);
// Sans coût saisi, le rapport compare ce que le client a payé au TARIF du
// mode — pas à un coût inventé. (« Il ne peut jamais dépasser 100 % » était
// faux : une commande payée avant une baisse de tarif le dépasse, et la
// suite jouée sous MySQL l'a montré.) Le chiffre utile est ailleurs : une
// livraison offerte le fait BAISSER, la franchise se paie sur la marge.
$m2 = wsm_margin_series($pdo, 1)[0];
$paye = 0; $tarif = 0;
foreach ($pdo->query("SELECT delivery_method, shipping_net FROM wsm_orders
                       WHERE status <> 'anulowane' AND payment_status = 'oplacone'")->fetchAll() as $o) {
    // Le mois courant seulement — wsm_margin_series($pdo, 1) n'en voit pas d'autre.
    $paye += (int) $o['shipping_net'];
    $tarif += $c2[(string) $o['delivery_method']]['cost'] ?? (int) $o['shipping_net'];
}
$moisSeul = (int) $pdo->query("SELECT COUNT(*) FROM wsm_orders WHERE status <> 'anulowane' AND payment_status = 'oplacone'
                                 AND created_at < '" . date('Y-m') . "-01'")->fetchColumn() === 0;
ok('sans coût saisi, la couverture vaut payé / tarif', !$moisSeul || $m2['coverage_pct'] === ($tarif > 0 ? round($paye / $tarif * 100, 1) : 0.0),
   [$m2['coverage_pct'], $paye, $tarif]);
[$o4] = wsm_shop_create_order($pdo, $base + ['items' => [['id' => $avec, 'qty' => 1]]]);
$pdo->prepare("UPDATE wsm_orders SET payment_status = 'oplacone', shipping_net = 0 WHERE id = ?")->execute([(int) $o4['id']]);
$m3 = wsm_margin_series($pdo, 1)[0];
ok('une livraison offerte fait baisser la couverture', $m3['coverage_pct'] < $m2['coverage_pct'], [$m2['coverage_pct'], $m3['coverage_pct']]);

$pdo->prepare("UPDATE wsm_shipping_methods SET price_net = ?, cost_net = ?, free_from = ? WHERE id = ?")
    ->execute([$savePrix, (int) $saveCost, $saveFree, 'inpost_locker']);

// ---- 4. La série des douze mois -------------------------------------------------
echo "\n-- seria dwunastu miesięcy --\n";
$s = wsm_margin_series($pdo, 12);
ok('douze points, un par mois', count($s) === 12, count($s));
ok('le dernier point est le mois en cours', $s[11]['ym'] === date('Y-m'), $s[11]['ym']);
ok('le premier est bien onze mois plus tôt',
    $s[0]['ym'] === date('Y-m', strtotime('first day of -11 month')), $s[0]['ym']);
ok('chaque point porte toutes les mesures',
    !array_diff(['revenue','cogs','margin','ship_paid','ship_cost','ship_gap','result',
                 'coverage_pct','cost_known_pct','orders'], array_keys($s[0])));
ok('résultat = marge − écart de port, sur tous les mois',
    !array_filter($s, fn($r) => $r['result'] !== $r['margin'] - $r['ship_gap']));
ok('aucune couverture aberrante', !array_filter($s, fn($r) => $r['coverage_pct'] < 0));

$t = wsm_margin_totals($s);
ok('le total de marge est la somme des mois',
    $t['margin'] === array_sum(array_map(fn($r) => $r['margin'], $s)), $t['margin']);
ok('le taux de marge est rapporté au chiffre RÉELLEMENT costé',
    $t['margin_pct'] >= 0 && $t['margin_pct'] <= 100, $t['margin_pct']);

// ---- 5. La prévision dit sa fiabilité --------------------------------------------
echo "\n-- prognoza --\n";
$f = wsm_forecast($pdo, $s);
ok('elle donne le mois précédent et le mois en cours', isset($f['prev'], $f['curr'], $f['forecast']));
ok('les jours écoulés sont ceux d\'aujourd\'hui', $f['elapsed'] === (int) date('j'), $f['elapsed']);
ok('la longueur du mois est la vraie', $f['days'] === (int) date('t'), $f['days']);
ok('la prévision n\'est jamais inférieure au réalisé (extrapolation croissante)',
    $f['forecast']['revenue'] >= $f['curr']['revenue'], [$f['curr']['revenue'], $f['forecast']['revenue']]);
ok('la fiabilité est nommée', in_array($f['trust'], ['niska', 'średnia', 'wysoka'], true), $f['trust']);

// La règle de fiabilité, vérifiée sans dépendre de la date du jour.
$part = (int) date('j') / (int) date('t');
$attendu = ($s[11]['orders'] < 3) ? 'niska' : ($part >= 0.5 ? 'wysoka' : ($part >= 0.25 ? 'średnia' : 'niska'));
ok('elle suit la part du mois écoulée et le nombre de commandes',
    $f['trust'] === $attendu, [$f['trust'], $attendu, round($part, 2), $s[11]['orders']]);

// Un mois vide ne doit pas faire exploser le calcul.
$vide = wsm_forecast($pdo, [['ym' => '1999-01', 'label' => '01/99', 'revenue' => 0, 'margin' => 0,
                             'result' => 0, 'orders' => 0]]);
ok('un mois sans données donne zéro, pas une division par zéro',
    $vide['forecast']['revenue'] === 0 && $vide['delta_pct'] === 0.0, $vide['forecast']);
ok('et sa fiabilité est basse', $vide['trust'] === 'niska');

// ---- La valorisation : la formule, et le mot « théorique » -------------------------
//  Le vrai risque de cet écran n'est pas une erreur d'arithmétique — c'est un
//  chiffre postulé qui se présente comme mesuré. Il finit dans un business
//  plan, puis en face de quelqu'un qui le prend au sérieux.
echo "\n-- wycena --\n";

// a) Couverture insuffisante : on retombe sur 15 %, et on le DIT.
$vTheo = wsm_valuation(
    [['revenue_costed' => 10_000]],                        // 10 % du CA seulement
    ['revenue' => 100_000, 'result' => 9_000]              // un « taux » de 90 % si on l'écoutait
);
ok('sous 50 % de couverture la valorisation est marquée théorique', $vTheo['theoretical'] === true, $vTheo);
ok('et le taux retenu est la cible, pas la moyenne du maigre échantillon',
    abs($vTheo['actual_rate'] - 0.15) < 1e-9, $vTheo['actual_rate']);
ok('les deux montants sont alors identiques — c\'est la même formule',
    $vTheo['actual_value'] === $vTheo['target_value'], [$vTheo['actual_value'], $vTheo['target_value']]);
ok('objectif = CA × 15 % × 4,5', $vTheo['target_value'] === (int) round(100_000 * 0.15 * 4.5),
    $vTheo['target_value']);

// b) Couverture suffisante : le taux observé prend la main, le drapeau tombe.
$vReel = wsm_valuation(
    [['revenue_costed' => 80_000]],                        // 80 % du CA
    ['revenue' => 100_000, 'result' => 16_000]             // 20 % de marge nette mesurée
);
ok('au-dessus de 50 % la valorisation n\'est plus théorique', $vReel['theoretical'] === false, $vReel);
ok('le taux est le résultat rapporté à la vente MESURÉE, pas au CA total',
    abs($vReel['actual_rate'] - 0.20) < 1e-9, $vReel['actual_rate']);
ok('actuelle = CA × taux observé × 4,5',
    $vReel['actual_value'] === (int) round(100_000 * 0.20 * 4.5), $vReel['actual_value']);
ok('mieux que la cible ⇒ actuelle au-dessus de l\'objectif',
    $vReel['actual_value'] > $vReel['target_value'], [$vReel['actual_value'], $vReel['target_value']]);
ok('la couverture est rendue en pourcentage lisible', $vReel['coverage'] === 80.0, $vReel['coverage']);

// c) Une marge nette négative n'est pas ramenée à zéro en douce : une affaire
//    qui perd de l'argent vaut moins que rien selon cette formule, et l'écran
//    doit pouvoir le montrer plutôt que d'afficher un plancher rassurant.
$vNeg = wsm_valuation([['revenue_costed' => 90_000]], ['revenue' => 100_000, 'result' => -9_000]);
ok('une perte donne une valorisation négative, pas un zéro poli',
    $vNeg['actual_value'] < 0, $vNeg['actual_value']);

// d) Boutique vide : aucune division par zéro, aucun NaN.
$vNul = wsm_valuation([], ['revenue' => 0, 'result' => 0]);
ok('sans chiffre d\'affaires tout vaut zéro',
    $vNul['target_value'] === 0 && $vNul['actual_value'] === 0, $vNul);
ok('et c\'est déclaré théorique', $vNul['theoretical'] === true, $vNul);

// e) Les constantes sont exposées : l'écran affiche « × 4,5 » depuis la source,
//    il ne le réécrit pas à la main.
ok('le multiple est celui de la source', abs($vNul['multiple'] - WSM_VAL_MULTIPLE) < 1e-9);
ok('la cible aussi', abs($vNul['target_rate'] - WSM_VAL_TARGET_RATE) < 1e-9);

// f) Sur les vraies données de la base, le calcul ne casse pas.
$vLive = wsm_valuation($s, wsm_margin_totals($s));
ok('la valorisation se calcule sur les séries réelles', is_int($vLive['actual_value']), $vLive);

// ---- Nettoyage ---------------------------------------------------------------------
foreach ([$avec, $sans] as $id) {
    $pdo->prepare("DELETE FROM wsm_order_items WHERE product_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM wsm_stock_moves WHERE product_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM wsm_products WHERE id = ?")->execute([$id]);
}
foreach ([$o1, $o2 ?? null, $o3 ?? null, $o4 ?? null] as $o) {
    if ($o) $pdo->prepare("DELETE FROM wsm_orders WHERE id = ?")->execute([(int) $o['id']]);
}

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
