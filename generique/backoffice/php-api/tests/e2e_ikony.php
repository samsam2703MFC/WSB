<?php
// ============================================================================
//  e2e_ikony.php — les icônes des trois promesses de la page d'accueil.
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. LES TROIS CHAMPS EXISTENT, du bon type (« ikona » : transparence
//      conservée), dans le bon groupe, et visent la configuration de la
//      vitrine — sans quoi Ustawienia enregistre dans le vide.
//   2. CONFIG.PHP CONNAÎT LES CLÉS, vides : une valeur en dur rendrait le
//      champ inopérant (wsm_settings_apply laisse la main au fichier).
//   3. ENREGISTRER SANS FICHIER NE DÉCROCHE PAS L'ICÔNE ; la case « Usuń »,
//      elle, est un geste explicite.
//   4. LA VALEUR REDESCEND jusqu'à la vitrine, et la page l'AFFICHE —
//      vérifié sur la page servie quand un serveur de boutique tourne.
//
//  Usage :  php tests/e2e_ikony.php
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
require_once dirname(__DIR__) . '/settings.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end ikony obietnic\n\n";

$racine = dirname(__DIR__, 4);   // la racine du dépôt : tests → php-api → backoffice → mrszoko → ici
$lire = fn(string $rel) => (string) @file_get_contents($racine . '/' . $rel);

// Ce qu'une base de développement porte déjà : on le remet en place à la fin.
$avant = [];
$st = $pdo->prepare("SELECT cle, val FROM wsm_settings WHERE cle LIKE 'promise_icon_%'");
$st->execute();
foreach ($st->fetchAll() ?: [] as $r) $avant[(string) $r['cle']] = (string) $r['val'];
$pose = function (string $cle, string $val) use ($pdo): void {
    $pdo->prepare("DELETE FROM wsm_settings WHERE cle = ?")->execute([$cle]);
    $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,0,?,?)")
        ->execute([$cle, $val, date('Y-m-d H:i:s'), 'test']);
};
$lu = fn(string $cle) => (string) ($pdo->query("SELECT val FROM wsm_settings WHERE cle = '$cle'")->fetchColumn() ?: '');

// ---- 1. Les champs ---------------------------------------------------------------------
echo "-- trzy pola, typ ikona, cel: konfiguracja sklepu --\n";
$champs = wsm_settings_fields();
for ($i = 1; $i <= 3; $i++) {
    $c = $champs["promise_icon_$i"] ?? null;
    ok("promise_icon_$i existe", $c !== null);
    ok("… de type « ikona » — pas « image »", ($c[4] ?? '') === 'ikona', $c[4] ?? null);
    ok("… dans le groupe de la vitrine", ($c[0] ?? '') === 'sklep', $c[0] ?? null);
    ok("… et vise shop.promise_icon_$i", ($c[2] ?? []) === ['shop', "promise_icon_$i"], $c[2] ?? null);
}
ok('« ikona » et « image » sont des champs fichier', wsm_setting_obraz('ikona') && wsm_setting_obraz('image'));
ok('seule l\'icône garde sa transparence — une photo a un fond', wsm_setting_alpha('ikona') && !wsm_setting_alpha('image'));
ok('un texte n\'est pas un fichier', !wsm_setting_obraz('text') && !wsm_setting_obraz('secret'));
ok('l\'écran Ustawienia sait afficher le type', str_contains($lire('generique/backoffice/ustawienia.php'), "'ikona'"));
ok('… sur damier, pour voir la transparence', str_contains($lire('generique/backoffice/console.css'), '.ust-ikona'));

// ---- 2. config.php ---------------------------------------------------------------------
echo "\n-- config.php zna klucze, puste --\n";
$cfgTxt = $lire('generique/backoffice/php-api/config.php');
for ($i = 1; $i <= 3; $i++) {
    ok("shop.promise_icon_$i déclaré VIDE dans config.php",
       str_contains($cfgTxt, "'promise_icon_$i' => getenv('WSM_SHOP_ICON_$i') ?: ''"));
}
ok('et présent dans la configuration chargée', array_key_exists('promise_icon_2', wsm_config()['shop'] ?? []));

// ---- 3. Enregistrer sans fichier -------------------------------------------------------
echo "\n-- zapis bez pliku nie kasuje; „usuń” kasuje --\n";
$pose('promise_icon_2', 'media/bbbbbbbbbbbbbbbbbbbbbbbb.webp');
$r = [];
$urlAvant = $lu('shop_url');
wsm_settings_save($pdo, ['shop_url' => $urlAvant], 'test', $r);
ok('un enregistrement sans fichier laisse l\'icône en place',
   $lu('promise_icon_2') === 'media/bbbbbbbbbbbbbbbbbbbbbbbb.webp', $lu('promise_icon_2'));
wsm_settings_save($pdo, ['promise_icon_2__usun' => '1'], 'test', $r);
ok('la case « Usuń » retire l\'icône', wsm_setting_blank($lu('promise_icon_2')), $lu('promise_icon_2'));

// ---- 4. La valeur redescend ------------------------------------------------------------
echo "\n-- wartość schodzi do sklepu --\n";
$pose('promise_icon_3', 'media/cccccccccccccccccccccccc.webp');
wsm_settings_apply($pdo);
ok('wsm_settings_apply() pose l\'icône dans la configuration de la vitrine',
   (wsm_config()['shop']['promise_icon_3'] ?? '') === 'media/cccccccccccccccccccccccc.webp',
   wsm_config()['shop']['promise_icon_3'] ?? null);
$pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'promise_icon_3'")->execute();   // la page servie ne doit voir que l'icône 1

$vitrine = $lire('generique/shop/index.php');
ok('la vitrine lit promise_icon_N', str_contains($vitrine, 'promise_icon_$i'));
ok('… et l\'affiche avec la classe que le style connaît',
   str_contains($vitrine, 'class="promise-ikona"') && str_contains($lire('generique/shop/shop.css'), '.promise-ikona'));
ok('… résolue depuis la racine de la boutique (media_src), pas relative à la page',
   str_contains($vitrine, 'media_src($ikona)'));
ok('… et « xxxx », le masque d\'un réglage vidé, n\'est pas une adresse',
   str_contains($vitrine, "preg_match('/^x{2,}$/i', \$ikona)"));

// La page SERVIE, quand un serveur de boutique tourne (serve: php -S localhost:8091).
$base = rtrim(getenv('WSM_SHOP_BASE') ?: 'http://localhost:8091', '/');
$ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
$home = @file_get_contents($base . '/', false, $ctx);
if ($home === false || $home === '') {
    echo "  (pominięte: brak serwera sklepu pod $base)\n";
} else {
    $pose('promise_icon_1', 'media/dddddddddddddddddddddddd.webp');
    $h1 = (string) @file_get_contents($base . '/?t=' . time(), false, $ctx);
    ok('la page d\'accueil servie porte l\'icône', str_contains($h1, 'class="promise-ikona"'), substr_count($h1, 'promise-ikona'));
    ok('… à son adresse absolue', str_contains($h1, 'src="/media/dddddddddddddddddddddddd.webp"'));
    ok('… une seule fois : les deux autres gardent l\'icône dessinée de la maison', substr_count($h1, 'class="promise-ikona"') === 1
       && substr_count($h1, 'promise-ikona--rys') === 2);
    $pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'promise_icon_1'")->execute();
    $h0 = (string) @file_get_contents($base . '/?t=' . (time() + 1), false, $ctx);
    // Sans icône déposée, le dessin en ligne (znaki.php) : la page ne dépend
    // toujours d'aucun fichier, et elle n'est plus un bloc de texte nu.
    ok('sans icône déposée, l\'icône dessinée revient — la page ne dépend d\'aucun fichier',
       !str_contains($h0, 'dddddddddddddddddddddddd') && substr_count($h0, 'promise-ikona--rys') === 3 && !str_contains($h0, '<img class="promise-ikona"'));
}

// ---- Remise en place -------------------------------------------------------------------
$pdo->prepare("DELETE FROM wsm_settings WHERE cle LIKE 'promise_icon_%'")->execute();
foreach ($avant as $cle => $val) $pose($cle, $val);

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
