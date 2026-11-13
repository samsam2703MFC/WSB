<?php
// ============================================================================
//  e2e_produkt_zapis.php — ce qu'un « Zapisz » enregistre vraiment.
//
//  L'AUDIT QUI A MENÉ ICI. Rapport depuis la boutique : « nazwa się zmienia
//  ale cena, gramatura, adres w sklepie się nie zmienia ». On a changé chaque
//  champ dans un vrai navigateur, enregistré, rechargé, et comparé. Trois
//  causes, toutes silencieuses :
//
//   1. LE PRIX. (float) str_replace(',', '.', $v) s'arrête à la première
//      espace : « 1 234,50 » devenait 1.0. Mille deux cent trente-quatre
//      zlotys vendus un zloty, sans un mot, et le chiffre s'affichait ensuite
//      comme s'il avait toujours été là.
//   2. LA GRAMATURE. Aucun champ, sur aucun écran — alors qu'elle décide du
//      gabarit InPost, donc du prix payé pour expédier.
//   3. LE REFUS DESTRUCTEUR. Un seul champ invalide et la fiche se rechargeait
//      DEPUIS LA BASE : les douze autres saisies partaient avec. Vu de
//      l'extérieur, ça se lit « zmiany nie chcą wejść ».
//
//  Usage :  php tests/e2e_produkt_zapis.php
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

echo "webshop_mrszoko — end-to-end zapis produktu\n\n";

// ---- 1. Le prix tel qu'un humain l'écrit ----------------------------------
echo "-- cena tak, jak pisze ja czlowiek --\n";
foreach ([
    '64,90'      => 64.90,
    '64.90'      => 64.90,
    '1 234,50'   => 1234.50,   // espace ordinaire — l'ancien code rendait 1.0
    "1\u{00A0}234,50" => 1234.50,   // espace insécable
    "1\u{202F}234,50" => 1234.50,   // espace fine, clavier polonais
    '1.234,50'   => 1234.50,   // point de milliers, virgule décimale
    '1.234'      => 1234.0,    // trois chiffres après le point : un millier
    '64,90 zł'   => 64.90,
    '0,01'       => 0.01,
] as $saisi => $attendu) {
    $v = wsm_parse_price((string) $saisi);
    ok(sprintf('« %s » vaut %s', $saisi, $attendu), $v !== null && abs($v - $attendu) < 0.0001, $v);
}
// RIEN D'EXPLOITABLE NE DOIT DONNER ZÉRO. Écrire 0 sur une saisie qu'on n'a
// pas comprise, c'est offrir le produit — l'appelant doit pouvoir refuser.
foreach (['', '   ', 'abc', 'zł', '-'] as $rien) {
    ok('« ' . $rien . ' » ne donne pas 0 mais null', wsm_parse_price($rien) === null, wsm_parse_price($rien));
}
ok('un prix négatif reste négatif (l\'écran le refuse)', (wsm_parse_price('-5,00') ?? 0) < 0);

// ---- 2. Le gabarit du colis suit les dimensions ---------------------------
//
// Il se DÉDUIT : laissé en arrière, il ferait payer le tarif d'un colis qui
// n'existe plus.
echo "\n-- gabaryt idzie za wymiarami --\n";
if (function_exists('wsm_inpost_template')) {
    $a = wsm_inpost_template(80, 380, 640);
    $c = wsm_inpost_template(410, 380, 640);
    ok('un colis plat et un colis épais ne donnent pas le même gabarit', $a !== $c, [$a, $c]);
    ok('… et chacun en donne un', $a !== '' && $c !== '', [$a, $c]);
} else {
    ok('wsm_inpost_template() est chargeable', false);
}

// ---- 3. Le validateur rend TOUT ce qu'on lui donne ------------------------
//
// L'UPDATE est construit à partir de sa sortie : une clé qu'il oublie est une
// saisie perdue en silence, pas un refus.
echo "\n-- walidator oddaje wszystko, co dostal --\n";
$pdo = wsm_bootstrap();
$in = ['slug' => 'test-audyt-xyz', 'origin' => 'Ghana', 'cocoa' => '70%',
       'unit_label' => '1 kg', 'badge' => 'Nowość', 'vat_rate' => '0.23',
       'shop_visible' => 1, 'stock' => 5];
[$cols, $errs] = wsm_validate_product_shop($pdo, $in, 'produkt-ktory-nie-istnieje');
ok('aucune erreur sur une saisie propre', $errs === [], $errs);
foreach (array_keys($in) as $k) {
    ok("« $k » ressort du validateur", array_key_exists($k, $cols), array_keys($cols));
}

// ---- 4. Ce qui est refusé l'est pour une raison nommée --------------------
echo "\n-- odmowa ma powod --\n";
[, $e1] = wsm_validate_product_shop($pdo, ['slug' => ''], 'x');
ok('un slug vide est refusé', isset($e1['slug']), $e1);
[, $e2] = wsm_validate_product_shop($pdo, ['vat_rate' => '0.37'], 'x');
ok('une TVA hors barème est refusée', isset($e2['vat_rate']), $e2);
[, $e3] = wsm_validate_product_shop($pdo, ['stock' => '-4'], 'x');
ok('un stock négatif est refusé', isset($e3['stock']), $e3);

// Le slug d'un autre produit : deux produits ne peuvent pas partager une
// adresse, sinon l'un devient inatteignable.
$autre = $pdo->query("SELECT id, slug FROM wsm_products WHERE slug <> '' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($autre) {
    [, $e4] = wsm_validate_product_shop($pdo, ['slug' => $autre['slug']], 'un-autre-produit');
    ok('un slug déjà pris est refusé', isset($e4['slug']), $e4);
    [, $e5] = wsm_validate_product_shop($pdo, ['slug' => $autre['slug']], (string) $autre['id']);
    ok('… mais son propre slug ne l\'est pas', !isset($e5['slug']), $e5);
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
