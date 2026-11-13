<?php
// ============================================================================
//  e2e_b2b.php — preuve que « compte professionnel » change quelque chose.
//
//  CE QUI NE MARCHAIT PAS AVANT CE MODULE : les colonnes `remise` et `franco`
//  existaient sur la fiche client et n'étaient lues NULLE PART. Un client
//  marqué B2B payait exactement le prix de tout le monde. Une étiquette sans
//  effet est pire qu'une étiquette absente : on croit avoir accordé quelque
//  chose, et le client aussi.
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. LES REMISES NE S'EMPILENT PAS. 20 % de palier + 12 % de tarif pro
//      feraient 30 % — toute la marge, sur une commande que personne n'a
//      relue. La MEILLEURE des deux s'applique.
//   2. LE TARIF PRO S'APPLIQUE VRAIMENT au montant facturé.
//   3. L'ACTIVATION AUTOMATIQUE DONNE UN PRIX, JAMAIS UN CRÉDIT.
//   4. ELLE N'ÉCRASE PAS une remise saisie à la main.
//   5. LE POIDS SE COMPTE SUR L'ENCAISSÉ.
//
//  Usage :  php tests/e2e_b2b.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/b2b.php';
require_once dirname(__DIR__) . '/mail.php';
$pdo = wsm_bootstrap();
wsm_mail_transport(fn(array $m) => [true, '']);

echo "webshop_mrszoko — end-to-end konto firmowe (próg, rabat, franco)\n\n";

$sfx  = bin2hex(random_bytes(3));

// LE NETTOYAGE EST ENREGISTRÉ TOUT DE SUITE, et il tourne même si le fichier
// s'arrête en cours de route. Deux plantages de ce test ont laissé derrière
// eux trois produits à 100 zł ; l'un est devenu le premier du catalogue, et la
// suite boutique s'est mise à échouer sur un total parfaitement juste.
register_shutdown_function(function () use ($pdo, $sfx) {
    try {
        $pdo->exec("DELETE FROM wsm_order_items WHERE product_id LIKE 'test-b2b-$sfx'");
        $pdo->exec("DELETE FROM wsm_stock_moves WHERE product_id LIKE 'test-b2b-$sfx'");
        $pdo->exec("DELETE FROM wsm_products WHERE id LIKE 'test-b2b-$sfx'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE order_id IN
                      (SELECT id FROM wsm_orders WHERE email LIKE '%$sfx@example.com')");
        $pdo->exec("DELETE FROM wsm_orders WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_clients WHERE email LIKE '%$sfx@example.com'");
    } catch (Throwable $e) { /* le nettoyage ne doit jamais masquer le résultat */ }
});
$pro  = "pro.$sfx@example.com";
$zwy  = "zwykly.$sfx@example.com";

// Un produit lourd et bon marché : franchir 100 kg sans vider la caisse.
$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$pid = 'test-b2b-' . $sfx;
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible,
                    slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, base_cost)
               VALUES (?,?,?,?,'Opublikowany',1,1,?,9999,0.23,1000,200,150,100,?,10.00)")
     ->execute([$pid, $cat, 'Blok testowy ' . $sfx, 100.00, $pid, strtoupper($sfx)]);

// ---- 1. Les conditions par défaut : rien ---------------------------------------------
echo "-- bez karty klienta nic się nie zmienia --\n";
$c0 = wsm_b2b_conditions($pdo, $zwy);
ok('une adresse inconnue n\'a aucune remise', $c0['remise'] === 0.0 && $c0['b2b'] === false, $c0);
ok('ni seuil de franco propre', (int) $c0['franco'] === 0);
ok('une adresse vide non plus', wsm_b2b_conditions($pdo, '')['remise'] === 0.0);

// ---- 2. Le poids se compte sur l'encaissé ----------------------------------------------
echo "\n-- waga liczy się z zapłaconych --\n";
$mk = function (string $email, string $paiement, int $qty, string $quand) use ($pdo, $pid): int {
    $pdo->prepare("INSERT INTO wsm_orders (code, access_token, email, first_name, last_name, lang,
                        status, payment_status, items_net, items_gross, shipping_net, shipping_gross,
                        total_net, total_gross, delivery_method, created_at)
                   VALUES (?,?,?,'Firma','Testowa','pl','dostarczone',?,0,0,0,0,0,0,'inpost_courier',?)")
         ->execute(['MS-B2B-' . strtoupper(bin2hex(random_bytes(3))), bin2hex(random_bytes(8)),
                    $email, $paiement, $quand]);
    $oid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO wsm_order_items (order_id, product_id, name, qty, unit_gross, unit_net,
                        vat_rate, line_net, line_vat, line_gross)
                   VALUES (?,?,?,?,10000,8130,0.23,?,?,?)")
         ->execute([$oid, $pid, 'Blok testowy', $qty, 8130 * $qty, 1870 * $qty, 10000 * $qty]);
    return $oid;
};

$recent = date('Y-m-d H:i:s', time() - 10 * 86400);
$mk($zwy, 'oczekuje', 150, $recent);        // 150 kg — mais IMPAYÉS
ok('une commande impayée ne pèse rien', (int) wsm_b2b_poids($pdo, $zwy) === 0, wsm_b2b_poids($pdo, $zwy));

$mk($pro, 'oplacone', 60, $recent);
$mk($pro, 'oplacone', 45, $recent);         // 105 kg payés
ok('les commandes payées comptent',
    wsm_b2b_poids($pdo, $pro) === 105000, wsm_b2b_poids($pdo, $pro));

// Hors de la fenêtre : un volume d'il y a un an ne donne pas de droit aujourd'hui.
$vieux = "vieux.$sfx@example.com";
$mk($vieux, 'oplacone', 200, date('Y-m-d H:i:s', time() - 300 * 86400));
ok('un volume hors fenêtre ne compte pas', (int) wsm_b2b_poids($pdo, $vieux) === 0,
    wsm_b2b_poids($pdo, $vieux));

// ---- 3. L'éligibilité ---------------------------------------------------------------------
echo "\n-- kto ma prawo --\n";
$cand = wsm_b2b_candidats($pdo);
$vuPro = null; $vuZwy = null;
foreach ($cand as $c) {
    if (strtolower($c['email']) === $pro) $vuPro = $c;
    if (strtolower($c['email']) === $zwy) $vuZwy = $c;
}
ok('celui qui a franchi le seuil est proposé', $vuPro !== null && $vuPro['eligible'] === true, $vuPro);
ok('avec son tonnage en clair', $vuPro && (float) $vuPro['kg'] === 105.0, $vuPro['kg'] ?? null);
ok('celui qui n\'a rien payé n\'est pas proposé', $vuZwy === null);

// ---- 4. L'activation ------------------------------------------------------------------------
echo "\n-- otwarcie konta --\n";
[$okA, $msgA] = wsm_b2b_activer($pdo, $pro, 'test');
ok('le compte s\'ouvre', $okA === true, $msgA);
ok('et le message dit que le crédit reste fermé',
    str_contains($msgA, 'limit') || str_contains($msgA, 'Termin'), $msgA);
ok('le compte est actif', wsm_b2b_actif($pdo, $pro) === true);

$f = wsm_b2b_fiche($pdo, $pro);
ok('la remise professionnelle est posée',
    abs((float) $f['remise'] - WSM_B2B_REMISE) < 0.01, $f['remise'] ?? null);

// LE POINT QUI COMPTE : aucune condition de crédit n'a été accordée.
ok('la fiche existe bien', $f !== null);
ok('AUCUN paiement différé n\'a été accordé automatiquement',
    $f !== null && trim((string) ($f['paiement'] ?? '')) === '', $f['paiement'] ?? null);
ok('AUCUN plafond d\'encours non plus — une remise coûte une marge, '
   . 'un crédit coûte la facture',
    $f !== null && (float) str_replace(',', '.', (string) ($f['plafond'] ?? 0)) == 0.0,
    $f['plafond'] ?? null);

[$okB, $msgB] = wsm_b2b_activer($pdo, $pro, 'test');
ok('rouvrir un compte déjà ouvert ne fait rien', $okB === false, $msgB);

// ---- 5. On n'écrase pas une remise saisie à la main --------------------------------------------
echo "\n-- ręczny rabat przeżywa --\n";
$main = "main.$sfx@example.com";
$mk($main, 'oplacone', 120, $recent);
$pdo->prepare("INSERT INTO wsm_clients (code, email, raison, client_type, remise, statut)
               VALUES (?,?,?,'firma',?, 'aktywny')")
    ->execute([wsm_b2b_code($pdo), $main, 'Ręczna sp. z o.o.', 25.0]);
[$okC] = wsm_b2b_activer($pdo, $main, 'test', true);
ok('le compte s\'ouvre sur une fiche existante', $okC === true);
$fm = wsm_b2b_fiche($pdo, $main);
ok('la remise de 25 % saisie à la main N\'EST PAS ramenée à 12 % — '
   . 'quelqu\'un l\'avait mise là pour une raison',
    abs((float) $fm['remise'] - 25.0) < 0.01, $fm['remise'] ?? null);

// ---- 6. LE TARIF S'APPLIQUE VRAIMENT ------------------------------------------------------------
echo "\n-- rabat naprawdę działa na koszyku --\n";
/** Un devis, avec la signature réelle : ($pdo, items, méthode, langue, opts). */
$devis = fn(int $qty, string $email) => wsm_shop_quote(
    $pdo, [['id' => $pid, 'qty' => $qty]], 'inpost_courier', 'pl', ['email' => $email]);
[$qZwy] = $devis(2, "anonim.$sfx@example.com");
[$qPro] = $devis(2, $pro);

ok('le devis anonyme n\'a aucune remise', (float) ($qZwy['discount_percent'] ?? 0) === 0.0,
    $qZwy['discount_percent'] ?? null);
ok('le devis professionnel EN A UNE',
    abs((float) ($qPro['discount_percent'] ?? 0) - WSM_B2B_REMISE) < 0.01, $qPro['discount_percent'] ?? null);
ok('et le montant facturé est réellement plus bas',
    (int) $qPro['items_gross'] < (int) $qZwy['items_gross'],
    [$qZwy['items_gross'], $qPro['items_gross']]);
ok('la baisse correspond au taux annoncé, au grosz près',
    abs((int) $qPro['items_gross'] - (int) round((int) $qZwy['items_gross'] * (1 - WSM_B2B_REMISE / 100))) <= 2,
    [$qZwy['items_gross'], $qPro['items_gross']]);

// ---- 7. LES REMISES NE S'EMPILENT PAS ------------------------------------------------------------
echo "\n-- rabaty się NIE sumują --\n";
// 20 kg : le palier au poids donne 20 %, mieux que les 12 % du compte pro.
[$qGros] = $devis(20, $pro);
$attendu = (float) 20.0;
ok('sur un gros volume, c\'est le palier au poids qui gagne',
    abs((float) ($qGros['discount_percent'] ?? 0) - $attendu) < 0.01, $qGros['discount_percent'] ?? null);
ok('les deux ne se cumulent PAS — 20 % + 12 % feraient 30 %, soit toute la marge',
    (float) ($qGros['discount_percent'] ?? 0) < 30.0, $qGros['discount_percent'] ?? null);

// À l'inverse, un compte à remise forte l'emporte sur un petit palier.
[$qMain] = $devis(4, $main);
ok('une remise pro supérieure au palier gagne, elle',
    abs((float) ($qMain['discount_percent'] ?? 0) - 25.0) < 0.01, $qMain['discount_percent'] ?? null);

// LE PRIX VU DOIT ÊTRE LE PRIX FACTURÉ. Si le devis ignore l'adresse et que la
// commande la prend, le client voit un montant à l'écran et en paie un autre.
// Dans un sens c'est un cadeau, dans l'autre c'est un litige.
echo "\n-- cena widziana = cena zapłacona --\n";
[$devisPro] = wsm_shop_quote($pdo, [['id' => $pid, 'qty' => 2]], 'inpost_courier', 'pl',
                             ['email' => $pro]);
[$cmdPro, $errCmd] = wsm_shop_create_order($pdo, [
    'lang' => 'pl', 'delivery_method' => 'inpost_courier',
    'items' => [['id' => $pid, 'qty' => 2]],
    'client_type' => 'osoba', 'first_name' => 'Jan', 'last_name' => 'Testowy',
    'email' => $pro, 'phone' => '600100200', 'consent_terms' => 1,
    'ship_street' => 'Testowa', 'ship_building' => '1',
    'ship_postcode' => '00-001', 'ship_city' => 'Warszawa',
]);
ok('la commande professionnelle passe', $cmdPro !== null, $errCmd);
ok('et son montant est EXACTEMENT celui du devis',
    $cmdPro && (int) $cmdPro['total_gross'] === (int) $devisPro['total_gross'],
    [$devisPro['total_gross'] ?? null, $cmdPro['total_gross'] ?? null]);

// ---- 8. Le franco professionnel ---------------------------------------------------------------------
echo "\n-- franco firmowe --\n";
$pdo->prepare("UPDATE wsm_clients SET franco = ? WHERE LOWER(email) = ?")->execute([100.00, $pro]);
[$qF] = $devis(2, $pro);
ok('un seuil de franco plus bas donne la livraison gratuite',
    (int) ($qF['shipping_gross'] ?? -1) === 0, $qF['shipping_gross'] ?? null);

// Un seuil PLUS HAUT que le seuil public ne doit pas retirer un avantage déjà
// acquis : ce serait punir le client pour une saisie maladroite.
$pdo->prepare("UPDATE wsm_clients SET franco = ? WHERE LOWER(email) = ?")->execute([99999.00, $pro]);
[$qF2] = $devis(2, $pro);
[$qF3] = $devis(2, "anonim2.$sfx@example.com");
ok('un seuil pro ABSURDE ne rend pas la livraison plus chère qu\'au public',
    (int) ($qF2['shipping_gross'] ?? 0) <= (int) ($qF3['shipping_gross'] ?? 0),
    [$qF2['shipping_gross'] ?? null, $qF3['shipping_gross'] ?? null]);

// ---- 9. Une remise aberrante en base ne vide pas la caisse ---------------------------------------------
echo "\n-- brzegi --\n";
$pdo->prepare("UPDATE wsm_clients SET remise = ? WHERE LOWER(email) = ?")->execute([300.0, $pro]);
$c9 = wsm_b2b_conditions($pdo, $pro);
ok('une remise de 300 % est ignorée, pas appliquée', $c9['remise'] === 0.0, $c9['remise']);
$pdo->prepare("UPDATE wsm_clients SET remise = ? WHERE LOWER(email) = ?")->execute([-5.0, $pro]);
ok('une remise négative aussi', wsm_b2b_conditions($pdo, $pro)['remise'] === 0.0);

// ---- 10. Le passage automatique ---------------------------------------------------------------------------
echo "\n-- automat --\n";
$auto = "auto.$sfx@example.com";
$mk($auto, 'oplacone', 130, $recent);
ok('avant le passage, pas de compte', wsm_b2b_actif($pdo, $auto) === false);
$r = wsm_b2b_scan($pdo, 'test');
ok('le passage ouvre le compte', wsm_b2b_actif($pdo, $auto) === true, $r);
ok('et il le compte', (int) $r['ouverts'] >= 1, $r);
$r2 = wsm_b2b_scan($pdo, 'test');
ok('rejouer le passage n\'ouvre rien de plus — il est idempotent',
    !in_array($auto, $r2['emails'], true), $r2);

// La fermeture est manuelle, jamais automatique (règle 5).
[$okF] = wsm_b2b_fermer($pdo, $auto, 'test');
ok('un compte se ferme à la main', $okF === true && wsm_b2b_actif($pdo, $auto) === false);
$r3 = wsm_b2b_scan($pdo, 'test');
ok('mais le volume étant toujours là, le passage le rouvre — '
   . 'la règle est le volume, pas l\'humeur',
    wsm_b2b_actif($pdo, $auto) === true, $r3);

// ---- Nettoyage ---------------------------------------------------------------------------------------------
foreach ([$pro, $zwy, $vieux, $main, $auto] as $m) {
    $pdo->prepare("DELETE FROM wsm_order_items WHERE order_id IN
                     (SELECT id FROM wsm_orders WHERE LOWER(email) = ?)")->execute([strtolower($m)]);
    $pdo->prepare("DELETE FROM wsm_orders WHERE LOWER(email) = ?")->execute([strtolower($m)]);
    $pdo->prepare("DELETE FROM wsm_clients WHERE LOWER(email) = ?")->execute([strtolower($m)]);
}
$pdo->prepare("DELETE FROM wsm_order_items WHERE product_id = ?")->execute([$pid]);
$pdo->prepare("DELETE FROM wsm_stock_moves WHERE product_id = ?")->execute([$pid]);
$pdo->prepare("DELETE FROM wsm_products WHERE id = ?")->execute([$pid]);

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
