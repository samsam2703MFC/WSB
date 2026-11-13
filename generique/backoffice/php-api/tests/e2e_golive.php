<?php
// ============================================================================
//  e2e_golive.php — le grand ménage d'avant l'ouverture.
//
//  POURQUOI IL EXISTE. Une boutique qu'on met au point accumule des centaines
//  de commandes de test. Le jour de l'ouverture, ces chiffres ne sont pas
//  seulement inutiles : ils MENTENT. Le pulpit annonce un chiffre d'affaires
//  qui n'a jamais eu lieu, les factures partent de FV/240 au lieu de FV/1, et
//  le magasin affiche des stocks venus d'un essai de septembre.
//
//  CE QUE CE TEST GARDE — et chaque point est une façon de tout casser :
//
//  1. LE CATALOGUE SURVIT. Un ménage qui emporte les produits, les réglages ou
//     les comptes n'est pas un ménage, c'est une réinstallation. Et il
//     s'exécute d'un clic, sur la vraie boutique.
//  2. LE STOCK TOMBE À ZÉRO. Le stock est le RÉSULTAT des mouvements : les
//     effacer en gardant les quantités laisse un nombre que plus rien ne
//     justifie.
//  3. TOUT OU RIEN. Une transaction. Un ménage à moitié fait laisse des
//     factures qui nomment des commandes disparues, et personne ne sait où il
//     s'est arrêté.
//  4. LE MOT EST EXIGÉ. Un bouton se clique par erreur.
//  5. ÇA LAISSE UNE TRACE. Sept mille lignes qui disparaissent sans un mot au
//     journal, c'est la panne qu'on ne pourra jamais expliquer.
//
//  Usage :  php tests/e2e_golive.php
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
require_once dirname(__DIR__) . '/golive.php';

echo "webshop_mrszoko — end-to-end zerowanie przed startem\n\n";

// ON TRAVAILLE SUR UNE COPIE. Un test qui vide la vraie base de développement
// emporterait le travail de tout le monde — et il suffirait de le lancer par
// distraction.
$src = dirname(__DIR__) . '/data/webshop_mrszoko.sqlite';
$tmp = sys_get_temp_dir() . '/wsm_golive_' . bin2hex(random_bytes(4)) . '.sqlite';
if (!is_file($src)) { echo "  (base de développement absente — rien à copier)\n"; exit(0); }
copy($src, $tmp);
register_shutdown_function(fn() => @unlink($tmp));
$pdo = new PDO('sqlite:' . $tmp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');

// De quoi avoir quelque chose à effacer, même sur une base fraîche.
$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$pid = 'test-gl-' . bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible,
                    slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, base_cost)
               VALUES (?,?,?,100.00,'Opublikowany',1,1,?,42,0.23,250,200,150,100,?,5.00)")
     ->execute([$pid, $cat, 'GoLive', $pid, 'GL1']);
wsm_shop_create_order($pdo, [
    'items' => [['id' => $pid, 'qty' => 1]], 'lang' => 'pl',
    'delivery_method' => 'inpost_courier', 'inpost_point' => 'KRA010',
    'email' => 'gl@example.com', 'phone' => '600100200',
    'first_name' => 'Jan', 'last_name' => 'Kowalski', 'client_type' => 'osoba',
    'ship_street' => 'Testowa', 'ship_building' => '1', 'ship_postcode' => '00-001',
    'ship_city' => 'Warszawa', 'ship_country' => 'PL', 'consent_terms' => true,
]);

echo "-- co zniknie, policzone PRZED gestem --\n";
$avant = wsm_golive_compte($pdo);
ok('le décompte annonce des commandes', ($avant['wsm_orders'] ?? 0) > 0, $avant['wsm_orders'] ?? null);
// LE DÉCOMPTE EST AFFICHÉ AVANT. « To skasuje 552 zamówienia » fait réfléchir ;
// « czy na pewno? » fait cliquer.
ok('… et du stock non nul', ($avant['stock_niezerowy'] ?? 0) > 0, $avant['stock_niezerowy'] ?? null);

// Ce qui doit survivre, mesuré avant pour être comparé après.
$survit = ['wsm_products', 'wsm_categories', 'wsm_users', 'wsm_settings',
           'wsm_shop_i18n', 'wsm_mail_templates', 'wsm_shipping_methods',
           'wsm_countries', 'wsm_vouchers'];
$ref = [];
foreach ($survit as $t) $ref[$t] = (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();

echo "\n-- gest --\n";
[$okG, $msgG] = wsm_golive_reset($pdo, 'test');
ok('le ménage passe', $okG, $msgG);

$apres = wsm_golive_compte($pdo);
unset($apres['stock_niezerowy']);
ok('plus une ligne d\'activité', array_sum($apres) === 0, array_filter($apres));

echo "\n-- co MIALO przetrwac --\n";
$perdu = [];
foreach ($survit as $t) {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    if ($n !== $ref[$t]) $perdu[$t] = $ref[$t] . ' → ' . $n;
}
// LA LIGNE QUI COMPTE LE PLUS DE TOUT CE FICHIER. Ce geste s'exécute d'un clic
// sur la vraie boutique : s'il emporte le catalogue, les réglages ou les
// comptes, il n'y a pas de retour en arrière.
ok('le catalogue, les réglages et les comptes sont intacts', $perdu === [], $perdu);

echo "\n-- magazyn --\n";
// Le stock est un RÉSULTAT : on vient d'effacer les mouvements qui le
// justifiaient. Le laisser à 42 laisserait un nombre que plus rien n'explique.
ok('plus aucun stock non nul',
   (int) $pdo->query("SELECT COUNT(*) FROM wsm_products WHERE stock <> 0")->fetchColumn() === 0);
ok('… mais les produits sont toujours là',
   (int) $pdo->query("SELECT COUNT(*) FROM wsm_products")->fetchColumn() === $ref['wsm_products']);

echo "\n-- slad i powtarzalnosc --\n";
$v = (string) $pdo->query("SELECT verb FROM wsm_audit ORDER BY id DESC LIMIT 1")->fetchColumn();
// Sept mille lignes qui disparaissent sans un mot au journal, c'est la panne
// qu'on ne pourra jamais expliquer.
ok('le journal d\'audit porte le geste', $v === 'Zerowanie przed startem', $v);
$e = (string) $pdo->query("SELECT entity FROM wsm_audit ORDER BY id DESC LIMIT 1")->fetchColumn();
ok('… avec le détail de ce qui est parti', str_contains($e, 'orders='), $e);

[$ok2, $msg2] = wsm_golive_reset($pdo, 'test');
ok('rejoué, il ne trouve plus rien', $ok2 && str_contains($msg2, 'Nic'), $msg2);

echo "\n-- ekran: slowo do przepisania --\n";
$sa = (string) @file_get_contents(dirname(__DIR__, 2) . '/superadmin.php');
ok('le mot est exigé avant le geste', str_contains($sa, "!== WSM_GOLIVE_MOT"));
// Le panneau vit derrière le code du jour, dans l'écran Superadmin : c'est la
// seule porte de la console qui demande deux choses.
ok('le panneau est dans l\'écran Superadmin', str_contains($sa, 'id="zerowanie"'));
ok('… et le décompte s\'affiche avant', str_contains($sa, 'wsm_golive_compte($pdo)'));

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
