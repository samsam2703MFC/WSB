<?php
// ============================================================================
//  e2e_inpost_kanal.php — le canal InPost, de bout en bout.
//
//  CE QUI MANQUAIT POUR « FINIR » L'INTÉGRATION. La création du colis et
//  l'étiquette existaient depuis longtemps. Deux morceaux manquaient, et ce
//  sont les deux qu'on ne voit pas tant qu'on n'a pas de vrai client :
//
//  1. « EST-CE QUE ÇA MARCHE ? » Coller un jeton dans Ustawienia ne donnait
//     AUCUN retour. On l'apprenait le jour où quelqu'un avait payé, quand la
//     création échouait : commande encaissée, colis inexistant, et personne
//     devant l'écran à ce moment-là.
//
//  2. LA VIE DU COLIS APRÈS SON DÉPART. Une commande restait « Wysłane » pour
//     toujours. Le client voyait « doręczona » sur le site du transporteur ;
//     la boutique était le dernier endroit où l'information arrivait.
//
//  Le transport est remplaçable : un test ne dérange jamais InPost, et c'est
//  le seul moyen d'éprouver un jeton périmé ou une organisation de sandbox
//  avec un jeton de production — les deux pannes qui arrivent vraiment.
//
//  Usage :  php tests/e2e_inpost_kanal.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/delivery.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/inpost.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end kanal InPost\n\n";

// On force une configuration en mémoire : le dépôt est public, aucun vrai
// jeton n'y vit, et le test ne doit rien exiger du serveur.
wsm_config_overlay(['inpost' => ['token' => 'jeton-de-test',
                                 'organization_id' => '4242', 'sandbox' => true]]);

echo "-- czy to w ogole dziala: odpowiedz PRZED pierwszym zamowieniem --\n";
wsm_inpost_transport(fn() => [200, ['id' => 4242, 'name' => 'Mister Szoko sp. z o.o.']]);
[$k, $m] = wsm_inpost_diag();
ok('un canal en ordre le dit', $k === 'ok', [$k, $m]);
ok('… et nomme l\'organisation', str_contains($m, 'Mister Szoko'), $m);
// L'environnement est dans la phrase : c'est LE piège de ShipX.
ok('… et l\'environnement', str_contains($m, 'sandbox'), $m);

wsm_inpost_transport(fn() => [401, ['message' => 'unauthorized']]);
[$k, $m] = wsm_inpost_diag();
ok('un jeton refusé est nommé comme tel', $k === 'zle' && str_contains($m, '401'), [$k, $m]);
ok('… et le message parle d\'expiration, pas d\'un bug', str_contains($m, 'wygasł'), $m);

// LE PIÈGE LE PLUS COURANT, et il est invisible autrement : un jeton de
// production avec un numéro d'organisation de sandbox. Les deux sont justes
// séparément ; ensemble ils ne créent aucun colis.
wsm_inpost_transport(fn() => [404, []]);
[$k, $m] = wsm_inpost_diag();
ok('une organisation inconnue est nommée', $k === 'zle' && str_contains($m, '4242'), [$k, $m]);
ok('… et le message dit que les deux doivent venir du même endroit',
   str_contains($m, 'tego samego środowiska'), $m);

// Une panne passagère n'est pas une erreur de configuration : dire « twój
// token jest zły » sur un 502 enverrait quelqu'un chercher un jeton neuf
// pendant une heure.
wsm_inpost_transport(fn() => [502, []]);
ok('une panne passagère dit « réessaie », pas « c\'est faux »', wsm_inpost_diag()[0] === 'uwaga');

echo "\n-- czego brakuje, powiedziane bez pytania InPostu --\n";
$appels = 0;
wsm_inpost_transport(function () use (&$appels) { $appels++; return [200, []]; });
wsm_config_overlay(['inpost' => ['token' => '', 'organization_id' => '4242']]);
[$k, $m] = wsm_inpost_diag();
ok('sans jeton, on ne dérange pas InPost', $k === 'zle' && $appels === 0, [$k, $appels]);
wsm_config_overlay(['inpost' => ['token' => 'jeton-de-test', 'organization_id' => '']]);
ok('sans organisation non plus', wsm_inpost_diag()[0] === 'zle' && $appels === 0);

echo "\n-- zycie paczki po wyjsciu --\n";
wsm_config_overlay(['inpost' => ['token' => 'jeton-de-test',
                                 'organization_id' => '4242', 'sandbox' => true]]);
$sfx = bin2hex(random_bytes(3));
register_shutdown_function(function () use ($pdo, $sfx) {
    try {
        $pdo->exec("DELETE FROM wsm_shipments WHERE order_id IN
                      (SELECT id FROM wsm_orders WHERE email LIKE '%$sfx@example.com')");
        $pdo->exec("DELETE FROM wsm_order_items WHERE order_id IN
                      (SELECT id FROM wsm_orders WHERE email LIKE '%$sfx@example.com')");
        $pdo->exec("DELETE FROM wsm_orders WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_stock_moves WHERE product_id LIKE 'test-ip-$sfx%'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE product_id LIKE 'test-ip-$sfx%'");
        $pdo->exec("DELETE FROM wsm_products WHERE id LIKE 'test-ip-$sfx%'");
    } catch (Throwable $e) { }
});

$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$pid = 'test-ip-' . $sfx;
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible,
                    slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, base_cost)
               VALUES (?,?,?,100.00,'Opublikowany',1,1,?,50,0.23,250,200,150,100,?,5.00)")
     ->execute([$pid, $cat, 'InPost ' . $sfx, $pid, strtoupper($sfx)]);
[$o] = wsm_shop_create_order($pdo, [
    'items' => [['id' => $pid, 'qty' => 1]], 'lang' => 'pl',
    'delivery_method' => 'inpost_courier', 'inpost_point' => 'KRA010',
    'email' => "ip.$sfx@example.com", 'phone' => '600100200',
    'first_name' => 'Jan', 'last_name' => 'Kowalski', 'client_type' => 'osoba',
    'ship_street' => 'Testowa', 'ship_building' => '1', 'ship_postcode' => '00-001',
    'ship_city' => 'Warszawa', 'ship_country' => 'PL', 'consent_terms' => true,
]);
$oid = (int) $o['id'];
$pdo->prepare("UPDATE wsm_orders SET status = 'wyslane', payment_status = 'oplacone' WHERE id = ?")->execute([$oid]);
$pdo->prepare("UPDATE wsm_shipments SET shipment_id = ?, status = 'utworzona' WHERE order_id = ?")
    ->execute(['shipx-' . $sfx, $oid]);

// Un colis en route : l'état de l'expédition suit, la commande ne bouge pas.
wsm_inpost_transport(fn() => [200, ['status' => 'sent_from_source_branch']]);
[$vus, $av] = wsm_inpost_sync($pdo, 200);
$st = (string) $pdo->query("SELECT status FROM wsm_orders WHERE id = $oid")->fetchColumn();
ok('un colis en route met à jour l\'expédition', $vus > 0);
ok('… et ne fait pas avancer la commande', $st === 'wyslane' && $av === 0, [$st, $av]);
$sh = (string) $pdo->query("SELECT status FROM wsm_shipments WHERE order_id = $oid")->fetchColumn();
ok('… mais l\'état du colis est écrit', $sh === 'sent_from_source_branch', $sh);

// Livré : LA seule transition automatique. Le reste ne touche pas la commande —
// faire reculer une commande sur un statut mal interprété serait pire que de
// ne rien savoir.
wsm_inpost_transport(fn() => [200, ['status' => 'delivered']]);
[, $av2] = wsm_inpost_sync($pdo, 200);
$st2 = (string) $pdo->query("SELECT status FROM wsm_orders WHERE id = $oid")->fetchColumn();
ok('« delivered » ferme la commande', $st2 === 'dostarczone' && $av2 >= 1, [$st2, $av2]);

// Rejoué, il ne doit plus rien faire : une commande déjà livrée ne se
// re-livre pas, et un compteur qui gonfle à chaque passage ne dit plus rien.
[, $av3] = wsm_inpost_sync($pdo, 200);
ok('rejoué, plus aucune commande avancée', $av3 === 0, $av3);

echo "\n-- ekran: przyciski sa podlaczone --\n";
$w = (string) @file_get_contents(dirname(__DIR__, 2) . '/wysylka.php');
ok('le test de connexion est posté', str_contains($w, "isset(\$_POST['sprawdz'])"));
// IL DOIT MARCHER CANAL FERMÉ : le ranger derrière « InPost est-il
// configuré ? » l'aurait rendu inaccessible exactement quand on en a besoin.
$posSprawdz = strpos($w, "isset(\$_POST['sprawdz'])");
$posFerme   = strpos($w, "} elseif (!wsm_inpost_enabled()) {");
ok('… et il est testé AVANT le contrôle « canal fermé »',
   $posSprawdz !== false && $posFerme !== false && $posSprawdz < $posFerme, [$posSprawdz, $posFerme]);
ok('le rafraîchissement des statuts est posté', str_contains($w, "isset(\$_POST['odswiez'])"));

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
