<?php
// ============================================================================
//  e2e_katalog.php — sous-catégories, types de produit, « bez wysyłki », et
//  l'import du catalogue de départ d'une boutique (MyCobotBuddy).
//
//  Ce qu'il tient :
//    1. le fichier boutiques/mycobot/katalog.json passe le contrôle, s'importe,
//       et se REJOUE sans rien dupliquer ni écraser une photo de la console ;
//    2. un fichier fautif est refusé en bloc, sans rien écrire ;
//    3. la vitrine range en deux étages : un rayon parent montre ses
//       sous-rayons, et ses produits avec ;
//    4. la caisse : un panier sans objet n'a QUE « bez wysyłki », sans
//       adresse ni point ; un panier avec un objet ne l'a JAMAIS ;
//    5. Szoko (aucun mode « brak », paliers au poids) ne voit rien changer.
//
//  Sur une base SQLite TEMPORAIRE, et un dossier media/ temporaire.
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

$tmp = sys_get_temp_dir() . '/wsm-katalog-' . getmypid();
@mkdir("$tmp/media", 0775, true);
putenv("WSM_SQLITE_PATH=$tmp/base.sqlite");
putenv('WSM_DB_ENGINE=sqlite');
putenv("WSM_MEDIA_DIR=$tmp/media");
register_shutdown_function(fn() => shell_exec('rm -rf ' . escapeshellarg($tmp)));

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/delivery.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/katalog.php';
$pdo = wsm_bootstrap();
$racine = dirname(__DIR__, 4);
$fichier = "$racine/boutiques/mycobot/katalog.json";
$k = json_decode((string) file_get_contents($fichier), true);
$base = dirname($fichier);

echo "webshop_mrszoko — end-to-end katalog\n\n";

// ---- 0. Szoko d'abord : rien ne change ------------------------------------------------
echo "-- Szoko bez zmian --\n";
$szoko = wsm_shop_products($pdo, 'pl');
$avecKg = array_filter($szoko, fn($p) => $p['weight_g'] > 0);
ok('Szoko vend au poids : le prix au kilo reste affiché', $avecKg && array_filter($avecKg, fn($p) => $p['price_per_kg'] !== null));
$visible = $szoko ? $szoko[0]['id'] : '';
[$q, ] = wsm_shop_quote($pdo, [['id' => $visible, 'qty' => 1]], 'inpost_locker', 'pl');
ok('Szoko : aucun mode « brak », les transporteurs habituels', $q['methods'] && !array_filter($q['methods'], fn($m) => $m['kind'] === 'brak'), array_column($q['methods'], 'id'));
ok('Szoko : un produit sans type est un objet à expédier', $szoko && $szoko[0]['rodzaj'] === 'towar');
ok('Szoko : catégories à un seul niveau, comme avant', !array_filter(wsm_shop_categories($pdo), fn($c) => $c['parent_id'] > 0));

// ---- 1. Le fichier MyCobotBuddy ---------------------------------------------------------
echo "\n-- katalog MyCobotBuddy --\n";
ok('le fichier passe le contrôle', wsm_katalog_check($k, $base) === [], wsm_katalog_check($k, $base));
$pdo->exec("UPDATE wsm_products SET shop_visible = 0");   // une boutique neuve ne vend pas de chocolat
$b = wsm_katalog_import($pdo, $k, $base);
ok('import réussi : 13 catégories, 22 produits, 22 photos + 3 icônes, 1 mode, 1 slajd',
   $b['ok'] && $b['kategorie'] === 13 && $b['produkty'] === 22 && $b['zdjecia'] === 25 && $b['dostawa'] === 1 && $b['slajdy'] === 1, $b);
$nb = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
ok('les trois rodzaje sont là : towar, usługa, subskrypcja',
   $nb("SELECT COUNT(DISTINCT rodzaj) FROM wsm_products WHERE id LIKE 'mcb-%'") === 3);
ok('les services n\'ont pas de poids (aucun transporteur ne les pèse)',
   $nb("SELECT COUNT(*) FROM wsm_products WHERE id LIKE 'mcb-%' AND rodzaj <> 'towar' AND weight_g <> 0") === 0);
ok('chaque produit a sa photo, ré-encodée en WebP dans media/',
   $nb("SELECT COUNT(*) FROM wsm_products WHERE id LIKE 'mcb-%' AND image_url LIKE 'media/%.webp'") === 22
   && count(glob("$tmp/media/*.webp")) === 26);   // 22 photos + 3 icônes + 1 haut de page
ok('les paliers au poids sont éteints : plus de prix au kilo',
   $nb("SELECT COUNT(*) FROM wsm_discount_tiers WHERE active = 1") === 0
   && !array_filter(wsm_shop_products($pdo, 'pl'), fn($p) => $p['price_per_kg'] !== null));
ok('le nom de la boutique et ses promesses suivent', wsm_shop_strings($pdo, 'pl')['brand'] === 'MyCobotBuddy'
   && str_contains((string) wsm_shop_strings($pdo, 'pl')['promise.3.t'], 'inżynier'));

// Une photo déposée depuis la console, puis un second import
$pdo->exec("UPDATE wsm_products SET image_url = 'media/aaaaaaaaaaaaaaaaaaaaaaaa.webp', prix = 1 WHERE id = 'mcb-acc-plyta'");
$b2 = wsm_katalog_import($pdo, $k, $base);
ok('second import : rien de dupliqué', $b2['ok'] && $nb("SELECT COUNT(*) FROM wsm_products WHERE id LIKE 'mcb-%'") === 22
   && $nb("SELECT COUNT(*) FROM wsm_categories WHERE parent_id > 0") === 10 && $nb("SELECT COUNT(*) FROM wsm_slides WHERE active = 1") === 1, $b2);
ok('… aucune nouvelle photo — celles en place restent, celle de la console aussi',
   $b2['zdjecia'] === 0 && $nb("SELECT COUNT(*) FROM wsm_products WHERE image_url = 'media/aaaaaaaaaaaaaaaaaaaaaaaa.webp'") === 1);
ok('… le prix, lui, suit le fichier', (float) $pdo->query("SELECT prix FROM wsm_products WHERE id = 'mcb-acc-plyta'")->fetchColumn() === 590.0);
ok('… et l\'ancienne image du haut de page ne reste pas orpheline dans media/', count(glob("$tmp/media/*.webp")) === 26, count(glob("$tmp/media/*.webp")));

// ---- 2. Un fichier fautif ----------------------------------------------------------------
echo "\n-- zły plik --\n";
$avant = $nb("SELECT COUNT(*) FROM wsm_products");
$mauvais = $k;
$mauvais['produkty'][] = ['id' => 'mcb-x', 'kategoria' => 'Nie ma takiej', 'rodzaj' => 'towar', 'cena' => 10, 'teksty' => ['pl' => ['name' => 'X']]];
$mauvais['produkty'][] = ['id' => 'mcb-y', 'kategoria' => 'Akcesoria', 'rodzaj' => 'towar', 'cena' => 10, 'teksty' => ['pl' => ['name' => 'Y']]];
$r = wsm_katalog_import($pdo, $mauvais, $base);
ok('catégorie inconnue et objet sans poids : refusés, avec la raison',
   !$r['ok'] && (bool) array_filter($r['erreurs'], fn($e) => str_contains($e, 'mcb-x: nieznana kategoria'))
   && (bool) array_filter($r['erreurs'], fn($e) => str_contains($e, 'mcb-y: towar musi mieć wagę')), $r['erreurs']);
ok('… et RIEN n\'a été écrit', $nb("SELECT COUNT(*) FROM wsm_products") === $avant);

// ---- 3. La vitrine en deux étages ----------------------------------------------------------
echo "\n-- sklep: dwa poziomy kategorii --\n";
$cats = wsm_shop_categories($pdo);
$parId = []; foreach ($cats as $c) $parId[$c['name']] = $c;
ok('trois rayons de premier niveau', count(array_filter($cats, fn($c) => $c['parent_id'] === 0)) === 3, array_column(array_filter($cats, fn($c) => $c['parent_id'] === 0), 'name'));
ok('« Akcesoria » compte les produits de ses quatre sous-rayons (9)', ($parId['Akcesoria']['ile'] ?? 0) === 9, $parId['Akcesoria'] ?? null);
ok('« Chwytaki i efektory » est rangé sous « Akcesoria »', ($parId['Chwytaki i efektory']['parent_id'] ?? -1) === $parId['Akcesoria']['id']);
ok('ouvrir « Akcesoria » montre les 9 produits de ses sous-rayons', count(wsm_shop_products($pdo, 'pl', $parId['Akcesoria']['id'])) === 9);
ok('ouvrir un sous-rayon ne montre que lui', count(wsm_shop_products($pdo, 'pl', $parId['Chwytaki i efektory']['id'])) === 3);
ok('un seul niveau d\'imbrication : on ne range pas un rayon sous un sous-rayon',
   !wsm_kat_rodzic($pdo, $parId['Szkolenia']['id'], $parId['Chwytaki i efektory']['id'])
   && !wsm_kat_rodzic($pdo, $parId['Akcesoria']['id'], $parId['Szkolenia']['id']));

// ---- 4. La caisse ----------------------------------------------------------------------------
echo "\n-- kasa: bez wysyłki tylko bez towaru --\n";
[$q, ] = wsm_shop_quote($pdo, [['id' => 'mcb-szk-start', 'qty' => 2], ['id' => 'mcb-saas-monitoring-r', 'qty' => 1]], 'inpost_locker', 'pl');
ok('formation + licence : SEUL « bez wysyłki » est proposé', array_column($q['methods'], 'id') === ['bez_wysylki'], array_column($q['methods'], 'id'));
ok('… gratuit, et choisi d\'office', ($q['method']['id'] ?? '') === 'bez_wysylki' && (int) $q['shipping_gross'] === 0, [$q['method']['id'] ?? null, $q['shipping_gross'] ?? null]);
[$q2, ] = wsm_shop_quote($pdo, [['id' => 'mcb-szk-start', 'qty' => 1], ['id' => 'mcb-acc-kabel', 'qty' => 1]], 'inpost_locker', 'pl');
ok('formation + câble : « bez wysyłki » disparaît, les transporteurs reviennent',
   $q2['methods'] && !in_array('bez_wysylki', array_column($q2['methods'], 'id'), true), array_column($q2['methods'], 'id'));
$buyer = ['email' => 'a@example.com', 'phone' => '600700800', 'first_name' => 'Ola', 'last_name' => 'Nowak', 'consent_terms' => '1'];
[, $err] = wsm_validate_buyer($buyer, 'bez_wysylki', false, wsm_ship_kind($pdo, 'bez_wysylki'));
ok('sans expédition : ni point ni adresse exigés', !array_intersect(array_keys($err), ['inpost_point', 'ship_street', 'ship_building', 'ship_city', 'ship_postcode']), $err);
ok('la console dit « Bez wysyłki »', wsm_ship_opis($pdo, 'bez_wysylki') === 'Bez wysyłki');

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
