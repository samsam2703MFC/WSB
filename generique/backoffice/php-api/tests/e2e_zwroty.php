<?php
// ============================================================================
//  e2e_zwroty.php — l'adresse où le client renvoie un colis, là où tpay
//  l'exige : dans le règlement, en bas de chaque page à côté des données de
//  la société, et sur une page à part (« Zwroty »).
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. UNE SEULE ADRESSE, JAMAIS À MOITIÉ. Rue, code et ville se posent
//      ensemble dans Integracje → Sklep ; tant qu'il en manque un, c'est
//      l'adresse du siège (Treści) — pas une rue sans ville. Le masque d'un
//      réglage vidé (« xxxx ») n'est pas une adresse.
//   2. LE RÈGLEMENT LA DONNE, Y COMPRIS EN PRODUCTION. Le fichier de contenu
//      ne réécrit jamais un texte déjà en base : une migration à un seul coup
//      insère la phrase devant une phrase d'ancrage livrée. Jouée par les
//      deux voies (PHP, et le SQL de production traduit en SQLite) sur une
//      COPIE de la base, deux fois : pas de doublon, et un paragraphe réécrit
//      dans Treści n'est pas touché.
//   3. LA PAGE SERVIE (8091) : « Zwroty » dans les trois langues, l'adresse
//      complète, le lien en haut et en pied de page, le règlement qui la
//      cite, le plan du site — et la même adresse partout quand elle change.
//
//  Usage :  php tests/e2e_zwroty.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/settings.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/seed.php';
require_once dirname(__DIR__) . '/layout.php';
require_once dirname(__DIR__) . '/pages.php';
require_once dirname(__DIR__) . '/cms.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end zwroty (adres do zwrotów)\n\n";

$sfx = bin2hex(random_bytes(3));
$racine = dirname(__DIR__, 4);
$lire = fn(string $rel) => (string) @file_get_contents($racine . '/' . $rel);
$val = function (string $k, string $lang = 'pl') use ($pdo): ?string {
    $st = $pdo->prepare("SELECT v FROM wsm_shop_i18n WHERE lang = ? AND k = ?");
    $st->execute([$lang, $k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
};

// ---- 1. L'adresse : une source, jamais à moitié -------------------------------------------
echo "-- adres: jedno źródło, nigdy w połowie --\n";
$champs = wsm_settings_fields();
ok('quatre champs dans Integracje → Sklep, en texte', !array_filter(['zwrot_odbiorca', 'zwrot_ulica', 'zwrot_kod', 'zwrot_miasto'],
   fn($k) => ($champs[$k][0] ?? '') !== 'sklep' || ($champs[$k][4] ?? '') !== 'text' || ($champs[$k][2] ?? []) !== ['shop', $k]));
$cfg = require dirname(__DIR__) . '/config.php';
ok('… vides par défaut dans config.php (la console décide)', ($cfg['shop']['zwrot_ulica'] ?? 'x') === '' && ($cfg['shop']['zwrot_kod'] ?? 'x') === ''
   && ($cfg['shop']['zwrot_miasto'] ?? 'x') === '' && ($cfg['shop']['zwrot_odbiorca'] ?? 'x') === '');
// La fonction de la vitrine, appelée comme la vitrine l'appelle : dans un
// processus à part, avec lib.php chargé, et une surcouche de réglages.
$appel = function (array $shop, array $S) use ($racine): array {
    $code = 'require ' . var_export($racine . '/generique/shop/lib.php', true) . ';'
          . ' wsm_config_overlay(["shop" => ' . var_export($shop, true) . ']);'
          . ' $S = ' . var_export($S, true) . ';'
          . ' echo json_encode([zwrot_adres($S), zwrot_adres_linia($S), legal_valeurs(wsm_pdo(), $S)["{adres_zwrotu}"] ?? null], JSON_UNESCAPED_UNICODE);';
    return json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1'), true) ?: [];
};
$siege = ['seller.name' => 'ATELIER X sp. z o.o.', 'seller.address' => 'ul. Prosta 1/2, 00-001 Warszawa, Polska'];
$r = $appel(['zwrot_odbiorca' => '', 'zwrot_ulica' => '', 'zwrot_kod' => '', 'zwrot_miasto' => ''], $siege);
ok('rien de posé : le siège, une ligne par virgule', ($r[0] ?? null) === ['odbiorca' => 'ATELIER X sp. z o.o.', 'linie' => ['ul. Prosta 1/2', '00-001 Warszawa', 'Polska'], 'wlasny' => false], $r[0] ?? $r);
ok('… et sur une ligne pour le règlement, la même', ($r[1] ?? '') === 'ATELIER X sp. z o.o., ul. Prosta 1/2, 00-001 Warszawa, Polska' && ($r[2] ?? '') === ($r[1] ?? '-'), $r);
$r = $appel(['zwrot_odbiorca' => 'Szoko Shop, zwroty', 'zwrot_ulica' => 'ul. Magazynowa 7', 'zwrot_kod' => '55-040', 'zwrot_miasto' => 'Kobierzyce'], $siege);
ok('rue, code et ville posés : l\'adresse des retours, et le pays', ($r[0] ?? null) === ['odbiorca' => 'Szoko Shop, zwroty', 'linie' => ['ul. Magazynowa 7', '55-040 Kobierzyce', 'Polska'], 'wlasny' => true], $r[0] ?? $r);
ok('… le règlement cite celle-là', ($r[2] ?? '') === 'Szoko Shop, zwroty, ul. Magazynowa 7, 55-040 Kobierzyce, Polska', $r[2] ?? null);
$r = $appel(['zwrot_odbiorca' => '', 'zwrot_ulica' => 'ul. Magazynowa 7', 'zwrot_kod' => '', 'zwrot_miasto' => 'Kobierzyce'], $siege);
ok('une rue sans code : jamais une adresse à moitié, le siège', ($r[0]['wlasny'] ?? true) === false && ($r[0]['linie'][0] ?? '') === 'ul. Prosta 1/2', $r[0] ?? $r);
$r = $appel(['zwrot_odbiorca' => 'xxxx', 'zwrot_ulica' => 'xxxx', 'zwrot_kod' => 'xxxx', 'zwrot_miasto' => 'xxxx'], $siege);
ok('le masque d\'un réglage vidé n\'est pas une adresse', ($r[0]['odbiorca'] ?? '') === 'ATELIER X sp. z o.o.' && ($r[0]['wlasny'] ?? true) === false, $r[0] ?? $r);

// ---- 2. Les textes ----------------------------------------------------------------------------
echo "\n-- teksty: trzy języki, regulamin, Treści --\n";
$seed = json_decode($lire('generique/shop/content_seed.json'), true)['strings'];
$cles = array_values(array_filter(array_keys($seed['pl']), fn($k) => str_starts_with($k, 'returns.')));
ok('les textes de la page existent en pl, uk et en', count($cles) >= 15 && !array_filter($cles, fn($k) => trim($seed['uk'][$k] ?? '') === '' || trim($seed['en'][$k] ?? '') === ''), $cles);
ok('… traduits, pas recopiés', ($seed['uk']['returns.title'] ?? '') !== ($seed['pl']['returns.title'] ?? '') && ($seed['en']['returns.title'] ?? '') !== ($seed['pl']['returns.title'] ?? ''));
ok('… sans tiret cadratin (le déploiement les changerait en virgules)', !array_filter($cles, fn($k) => str_contains($seed['pl'][$k] . $seed['uk'][$k] . $seed['en'][$k], ' — ')));
ok('Treści les range sous un titre qui dit où se change l\'adresse', str_contains((string) (WSM_CMS_SECTIONS['returns'] ?? ''), 'Integracje → Sklep'), WSM_CMS_SECTIONS['returns'] ?? null);
$reg = wsm_regulamin_zwroty();
ok('le règlement livré cite l\'adresse au retrait et à la réclamation, une fois chacun',
   substr_count($seed['pl']['terms.s8.b'], '{adres_zwrotu}') === 1 && substr_count($seed['pl']['terms.s9.b'], '{adres_zwrotu}') === 1);
ok('… et c\'est mot pour mot la phrase de la migration', str_contains($seed['pl']['terms.s8.b'], $reg['terms.s8.b'][1]) && str_contains($seed['pl']['terms.s9.b'], $reg['terms.s9.b'][1]));

// ---- 3. La migration à un seul coup, sur une copie de la base ------------------------------
echo "\n-- migracja: adres w regulaminie, raz na bazę --\n";
$sql = wsm_sync_content_sql();
$garde = 'AND NOT EXISTS (SELECT 1 FROM wsm_settings WHERE cle = ' . wsm_sql_txt('migracja.2026_10_adres_zwrotu') . ')';
$lignesZ = array_values(array_filter(explode("\n", $sql), fn($l) => str_contains($l, $garde)));
ok('la voie SQL porte deux opérations gardées par le marqueur', count($lignesZ) === 2 && !array_filter($lignesZ, fn($l) => !str_contains($l, 'NOT LIKE')), count($lignesZ));
$pose = strpos($sql, 'INSERT IGNORE INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (' . wsm_sql_txt('migracja.2026_10_adres_zwrotu'));
ok('… et le marqueur se pose après elles', $pose !== false && $pose > strrpos($sql, $garde));

// Une COPIE de la base : sous SQLite, un fichier (VACUUM INTO) ; sous MySQL,
// le moteur de production, la même base dans une transaction annulée à la
// fin. Chaque voie se joue ainsi sur le moteur qui l'exécutera vraiment.
$mysql = wsm_sql_mysql($pdo);
$copie = function () use ($pdo, $mysql): array {
    if ($mysql) { $pdo->beginTransaction(); return [$pdo, null]; }
    $f = sys_get_temp_dir() . '/wsm-zwroty-' . bin2hex(random_bytes(4)) . '.sqlite';
    $pdo->exec('VACUUM INTO ' . $pdo->quote($f));
    return [new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]), $f];
};
$jeter = function (PDO $c, ?string $f): void {
    if ($f === null) { if ($c->inTransaction()) $c->rollBack(); return; }
    @unlink($f);
};
$un = function (PDO $c, string $q, array $a = []) { $st = $c->prepare($q); $st->execute($a); return $st->fetchColumn(); };
$scenario = function (string $voie, callable $jouer) use ($copie, $jeter, $un, $reg): void {
    [$c, $f] = $copie();
    $c->exec("DELETE FROM wsm_settings WHERE cle = 'migracja.2026_10_adres_zwrotu'");
    // La base de production : le texte d'avant (tirets déjà changés en
    // virgules par la règle de chaque déploiement), et un s9 réécrit à la main.
    $s8 = "Konsument może odstąpić od umowy, w terminie 14 dni.\n\n" . $reg['terms.s8.b'][0] . "\n\n**WYŁĄCZENIA, prosimy o uważne przeczytanie.**";
    $s9 = 'Reklamacje przyjmujemy mailowo, odpowiadamy w tydzień.';
    $c->prepare("UPDATE wsm_shop_i18n SET v = ? WHERE lang = 'pl' AND k = 'terms.s8.b'")->execute([$s8]);
    $c->prepare("UPDATE wsm_shop_i18n SET v = ? WHERE lang = 'pl' AND k = 'terms.s9.b'")->execute([$s9]);
    $jouer($c);
    $v = fn(string $k) => (string) $un($c, "SELECT v FROM wsm_shop_i18n WHERE lang = 'pl' AND k = ?", [$k]);
    ok("$voie : la phrase entre devant l'ancre, le reste du paragraphe reste", $v('terms.s8.b') === str_replace($reg['terms.s8.b'][0], $reg['terms.s8.b'][1], $s8), $v('terms.s8.b'));
    ok("$voie : un paragraphe réécrit dans Treści n'est pas touché", $v('terms.s9.b') === $s9);
    ok("$voie : le marqueur posé, au nom du déploiement", $un($c, "SELECT updated_by FROM wsm_settings WHERE cle = 'migracja.2026_10_adres_zwrotu'") === 'wdrozenie');
    $c->exec("DELETE FROM wsm_settings WHERE cle = 'migracja.2026_10_adres_zwrotu'");      // même sans marqueur…
    $jouer($c);
    ok("$voie : rejouée, pas de doublon", substr_count($v('terms.s8.b'), '{adres_zwrotu}') === 1);
    $jeter($c, $f);
};
$scenario('voie PHP', fn(PDO $c) => wsm_sync_content($c));
$migSql = array_map(fn($l) => $mysql ? $l : str_replace(['INSERT IGNORE INTO', 'NOW()'], ['INSERT OR IGNORE INTO', "datetime('now')"],
    preg_replace('/_utf8mb4 0x([0-9a-f]+)/', 'CAST(X\'$1\' AS TEXT)', $l)),
    array_values(array_filter(explode("\n", $sql), fn($l) => str_contains($l, bin2hex('migracja.2026_10_adres_zwrotu')))));
$scenario('voie SQL', function (PDO $c) use ($migSql) { foreach ($migSql as $q) $c->exec($q); });

// ---- 4. Les liens : en haut, en pied de page, route réservée ----------------------------------
echo "\n-- linki: menu, stopka, adres strony zarezerwowany --\n";
ok('« Zwroty » dans le menu du haut, après « Moje zamówienie »', array_slice(array_keys(wsm_layout_nav_builtins()), 2, 2) === ['zamowienie', 'zwroty']);
$fb = wsm_layout_footer_builtins();
ok('… et en pied de page, sans interrupteur, comme le règlement', !empty($fb['zwroty']['fixed']) && array_search('zwroty', array_keys($fb), true) === array_search('prywatnosc', array_keys($fb), true) + 1);
ok('aucune page de Strony ne peut prendre l\'adresse /zwroty', in_array('zwroty', WSM_PAGE_RESERVED, true));

// ---- 5. La page servie ------------------------------------------------------------------------
echo "\n-- strona podana (8091) --\n";
$base = 'http://localhost:8091';
$ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true, 'header' => "Accept-Language: pl\r\n"]]);
$get = fn(string $p) => @file_get_contents($base . $p, false, $ctx);
$h = @file_get_contents($base . '/zwroty?lang=pl&t=' . microtime(true), false, $ctx);     // ici : $http_response_header vit dans CETTE portée
if ($h === false) { echo "  (sklep na 8091 nie odpowiada — pominięte)\n"; }
else {
    $code = (int) (preg_match('#^HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m) ? $m[1] : 0);
    $siegeAdr = array_values(array_filter(array_map('trim', explode(',', (string) $val('seller.address'))), fn($l) => $l !== ''));
    ok('/zwroty répond 200, avec son titre', $code === 200 && str_contains($h, '<h1>' . htmlspecialchars((string) $val('returns.title')) . '</h1>'), $code);
    ok('l\'adresse complète, une ligne par ligne, le destinataire en gras',
       preg_match('#<address>\s*<strong>' . preg_quote(htmlspecialchars((string) $val('seller.name')), '#') . '</strong><br>\s*'
                  . implode('<br>', array_map(fn($l) => preg_quote(htmlspecialchars($l), '#'), $siegeAdr)) . '\s*</address>#u', $h) === 1);
    ok('les étapes, avec l\'e-mail et les 14 jours remplis', str_contains($h, htmlspecialchars((string) $val('footer.email'))) && !str_contains($h, '{email}') && !str_contains($h, '{dni}')
       && substr_count($h, '<li>') >= 4);
    ok('… et le chemin vers la commande et le règlement', str_contains($h, 'href="/moje-zamowienie"') && str_contains($h, 'href="/regulamin"'));
    foreach (['uk', 'en'] as $l) {
        $hl = (string) $get("/zwroty?lang=$l&t=" . microtime(true));
        ok("/zwroty en « $l » : le titre traduit, la même adresse", str_contains($hl, '<h1>' . htmlspecialchars((string) $val('returns.title', $l)) . '</h1>')
           && str_contains($hl, htmlspecialchars($siegeAdr[0] ?? '-')));
    }
    $hh = (string) $get('/?lang=pl&t=' . microtime(true));
    ok('en pied de page, sous les données de la société, avec le lien', preg_match('#<p class="seller">.*?</p>\s*(<\?php.*?\?>\s*)?<p class="seller seller--zwrot">\s*<span class="seller-label">'
       . preg_quote(htmlspecialchars((string) $val('returns.address')), '#') . '</span>.*?' . preg_quote(htmlspecialchars($siegeAdr[0] ?? '-'), '#') . '.*?href="/zwroty"#s', $hh) === 1);
    ok('le lien « Zwroty » en haut et en pied de page', preg_match('#<header.*href="/zwroty">' . preg_quote(htmlspecialchars((string) $val('returns.nav'))) . '</a>.*</header>#s', $hh) === 1
       && preg_match('#<nav class="foot-links"[^>]*>.*href="/zwroty">#s', $hh) === 1);
    $hr = (string) $get('/regulamin?lang=pl&t=' . microtime(true));
    $ligne = htmlspecialchars(implode(', ', array_merge([(string) $val('seller.name')], $siegeAdr)));
    ok('le règlement donne l\'adresse, en gras, au retrait et à la réclamation', substr_count($hr, '<strong>' . $ligne . '</strong>') === 2 && !str_contains($hr, '{adres_zwrotu}'), substr_count($hr, $ligne));
    ok('le plan du site liste la page', str_contains((string) $get('/sitemap.xml'), '/zwroty</loc>'));
    // L'adresse des retours posée dans la console : partout la même, tout de suite.
    $avant = $pdo->query("SELECT cle, val FROM wsm_settings WHERE cle LIKE 'zwrot_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pose = $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?, ?, 0, ?, 'test')");
    $pdo->exec("DELETE FROM wsm_settings WHERE cle LIKE 'zwrot_%'");
    foreach (['zwrot_odbiorca' => "Szoko Shop $sfx", 'zwrot_ulica' => 'ul. Magazynowa 7', 'zwrot_kod' => '55-040', 'zwrot_miasto' => 'Kobierzyce'] as $k => $v) $pose->execute([$k, $v, date('Y-m-d H:i:s')]);
    $h2 = (string) $get('/zwroty?lang=pl&t=' . microtime(true));
    $hh2 = (string) $get('/?lang=pl&t=' . microtime(true));
    $hr2 = (string) $get('/regulamin?lang=pl&t=' . microtime(true));
    $pdo->exec("DELETE FROM wsm_settings WHERE cle LIKE 'zwrot_%'");
    foreach ($avant as $k => $v) $pose->execute([$k, $v, date('Y-m-d H:i:s')]);
    ok('posée dans la console : la page la montre', str_contains($h2, "<strong>Szoko Shop $sfx</strong><br>") && str_contains($h2, 'ul. Magazynowa 7<br>55-040 Kobierzyce<br>Polska'));
    ok('… le pied de page aussi', str_contains($hh2, 'ul. Magazynowa 7, 55-040 Kobierzyce, Polska'));
    ok('… et le règlement', substr_count($hr2, "<strong>Szoko Shop $sfx, ul. Magazynowa 7, 55-040 Kobierzyce, Polska</strong>") === 2);
    ok('… sans toucher aux données de la société', str_contains($hh2, '<p class="seller">') && str_contains($hh2, htmlspecialchars((string) $val('seller.address'))));
    ok('la commande : l\'adresse sous le formulaire de retrait', str_contains($lire('generique/shop/index.php'), '<p class="muted claim-adres"><?= e($S[\'returns.address\'] ?? \'\') ?>: <?= e(zwrot_adres_linia($S)) ?>'));
}

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
