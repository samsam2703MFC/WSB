<?php
// ============================================================================
//  e2e_uklad.php — l'ordre des choses, réglé depuis la console (layout.php).
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. CE QUI NE SE CACHE PAS NE SE CACHE PAS : le catalogue, le règlement et
//      la politique de confidentialité restent, quoi qu'on poste.
//   2. LA VÉRITÉ EST CHEZ LA PAGE : cacher un bloc le dépublie, cacher une
//      page du menu décoche « w menu » — pas une seconde case qui contredit
//      la première. Un bloc nouveau trouve sa place tout seul, avant le B2B ;
//      un bloc supprimé sort de la liste.
//   3. LE HERO RESTE PREMIER, le pasek derrière lui ; tout le reste monte et
//      descend d'un cran. Une préférence qui cite un bloc disparu ne casse rien.
//   4. LA PAGE SERVIE suit l'ordre : catalogue avant B2B, puis B2B avant
//      catalogue après un déplacement ; une section cachée laisse son
//      marqueur ; le menu et le pied de page suivent leur liste ; le logo
//      déposé prend la barre du haut et l'icône d'onglet.
//
//  Usage :  php tests/e2e_uklad.php
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
require_once dirname(__DIR__) . '/pages.php';
require_once dirname(__DIR__) . '/layout.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end układ strony\n\n";

$sfx = bin2hex(random_bytes(3));
$racine = dirname(__DIR__, 4);
$lire = fn(string $rel) => (string) @file_get_contents($racine . '/' . $rel);
$cles = fn(string $w) => array_column(wsm_layout_get($pdo, $w), 'k');

// Ce qu'une base de développement porte déjà : remis en place à la fin.
$avant = [];
foreach (WSM_LAYOUT_LISTES as $w) $avant[$w] = $pdo->query("SELECT val FROM wsm_settings WHERE cle = 'layout.$w'")->fetchColumn();
$avantLogo = (string) ($pdo->query("SELECT val FROM wsm_settings WHERE cle = 'logo_image'")->fetchColumn() ?: '');
foreach (WSM_LAYOUT_LISTES as $w) wsm_layout_reset($pdo, $w);
$ids = [];

// ---- 1. Défauts et éléments fixes ----------------------------------------------------
echo "-- domyślnie: kolejność z kodu; stałych nie da się ukryć --\n";
ok('l\'accueil dans son ordre d\'origine', $cles('home') === ['hero', 'pasek', 'obietnice', 'katalog', 'pro', 'kontakt'], $cles('home'));
ok('le menu aussi', $cles('nav') === ['sklep', 'b2b', 'zamowienie', 'zwroty', 'kontakt'], $cles('nav'));
ok('et le pied de page', $cles('footer') === ['email', 'telefon', 'zamowienie', 'regulamin', 'prywatnosc', 'zwroty', 'kontakt', 'konsola'], $cles('footer'));
// Le téléphone et l'e-mail sur l'accueil — demandés par l'équipe — se cachent
// et se déplacent comme le reste ; le téléphone a aussi sa ligne en pied de page.
ok('le bloc contact se cache', wsm_layout_toggle($pdo, 'home', 'kontakt') && wsm_layout_get($pdo, 'home')[5]['on'] === 0);
ok('… et se remontre', wsm_layout_toggle($pdo, 'home', 'kontakt') && wsm_layout_get($pdo, 'home')[5]['on'] === 1);
ok('le téléphone du pied de page aussi', wsm_layout_toggle($pdo, 'footer', 'telefon') && wsm_layout_toggle($pdo, 'footer', 'telefon'));
ok('tout est visible', !array_filter(wsm_layout_get($pdo, 'home'), fn($it) => !$it['on']));
ok('le catalogue ne se cache pas', !wsm_layout_toggle($pdo, 'home', 'katalog') && wsm_layout_get($pdo, 'home')[3]['on'] === 1);
ok('le règlement et la politique non plus', !wsm_layout_toggle($pdo, 'footer', 'regulamin') && !wsm_layout_toggle($pdo, 'footer', 'prywatnosc'));
ok('ni le suivi de commande — le pied de page est le seul endroit présent sur téléphone', !wsm_layout_toggle($pdo, 'footer', 'zamowienie'));
ok('… et l\'écran peut le dire : ils sont marqués fixes',
   wsm_layout_get($pdo, 'footer')[2]['fixed'] && wsm_layout_get($pdo, 'home')[3]['fixed']);
// Le hero natif, lui, se cache depuis que le Kreator sait en composer un autre
// (une photo, un titre, un bouton) — il reste épinglé en tête, mais pas fixe.
ok('le hero natif n\'est pas fixe : le Kreator peut le remplacer', !wsm_layout_get($pdo, 'home')[0]['fixed'] && wsm_layout_get($pdo, 'home')[0]['pin'] === 'first');
ok('… il se cache', wsm_layout_toggle($pdo, 'home', 'hero') && wsm_layout_get($pdo, 'home')[0]['on'] === 0);
ok('… et se remontre', wsm_layout_toggle($pdo, 'home', 'hero') && wsm_layout_get($pdo, 'home')[0]['on'] === 1);
ok('le B2B, lui, se cache', wsm_layout_toggle($pdo, 'home', 'pro') && wsm_layout_get($pdo, 'home')[4]['on'] === 0);
ok('… et se remontre', wsm_layout_toggle($pdo, 'home', 'pro') && wsm_layout_get($pdo, 'home')[4]['on'] === 1);
ok('une clé inconnue ne bouge rien', !wsm_layout_toggle($pdo, 'home', 'nieznane') && !wsm_layout_move($pdo, 'home', 'nieznane', 'up'));
ok('une liste inconnue est vide', wsm_layout_get($pdo, 'nigdzie') === []);

// ---- 2. Déplacements ---------------------------------------------------------------------
echo "\n-- przesuwanie: nagłówek zawsze pierwszy, pasek tuż za nim --\n";
ok('le hero ne descend pas', !wsm_layout_move($pdo, 'home', 'hero', 'down'));
ok('le pasek ne bouge pas seul', !wsm_layout_move($pdo, 'home', 'pasek', 'down') && !wsm_layout_move($pdo, 'home', 'pasek', 'up'));
ok('les promesses ne passent pas devant le pasek', !wsm_layout_move($pdo, 'home', 'obietnice', 'up'));
ok('le B2B monte au-dessus du catalogue', wsm_layout_move($pdo, 'home', 'pro', 'up') && $cles('home') === ['hero', 'pasek', 'obietnice', 'pro', 'katalog', 'kontakt'], $cles('home'));
ok('… et redescend', wsm_layout_move($pdo, 'home', 'pro', 'down') && $cles('home') === ['hero', 'pasek', 'obietnice', 'katalog', 'pro', 'kontakt']);
ok('le dernier ne descend pas', !wsm_layout_move($pdo, 'home', 'kontakt', 'down'));
ok('Kontakt passe devant Sklep en quatre crans', wsm_layout_move($pdo, 'nav', 'kontakt', 'up') && wsm_layout_move($pdo, 'nav', 'kontakt', 'up')
   && wsm_layout_move($pdo, 'nav', 'kontakt', 'up') && wsm_layout_move($pdo, 'nav', 'kontakt', 'up')
   && $cles('nav') === ['kontakt', 'sklep', 'b2b', 'zamowienie', 'zwroty'], $cles('nav'));
wsm_layout_reset($pdo, 'nav');
ok('Przywróć ramène l\'ordre d\'origine', $cles('nav') === ['sklep', 'b2b', 'zamowienie', 'zwroty', 'kontakt']);

// Une préférence qui cite un bloc disparu, et oublie une section intégrée.
wsm_layout_write($pdo, 'home', [['k' => 'hero', 'on' => 1], ['k' => 'blok:999999', 'on' => 1], ['k' => 'pro', 'on' => 1], ['k' => 'katalog', 'on' => 1]], 'test');
ok('un bloc disparu sort, une section oubliée reprend sa place, l\'ordre voulu reste',
   $cles('home') === ['hero', 'pasek', 'obietnice', 'pro', 'kontakt', 'katalog'], $cles('home'));
wsm_layout_reset($pdo, 'home');

// ---- 3. Blocs et pages ----------------------------------------------------------------
echo "\n-- bloki i strony: prawda jest w stronie; nowy blok sam znajduje miejsce --\n";
$e = [];
$idB = wsm_page_save($pdo, null, ['kind' => 'blok', 'published' => 1, 'styl' => ['ciemny', 'xxx'],
    't' => ['pl' => ['title' => "Blok $sfx", 'body' => 'Treść **bloku**.']]], 'test', $e);
$ids[] = $idB;
ok('un bloc publié entre dans l\'accueil, avant le B2B', $cles('home') === ['hero', 'pasek', 'obietnice', 'katalog', "blok:$idB", 'pro', 'kontakt'], $cles('home'));
ok('ses styles sont filtrés sur la liste connue', (wsm_page_blok($pdo, (int) $idB, 'pl')['styl'] ?? null) === ['ciemny']);
$e = [];
$idB2 = wsm_page_save($pdo, null, ['kind' => 'blok', 'published' => 1, 'placement' => 'po_obietnicach',
    't' => ['pl' => ['title' => "Blok wyżej $sfx"]]], 'test', $e);
$ids[] = $idB2;
ok('un bloc d\'avant (emplacement « pod obietnicami ») se place là la première fois',
   $cles('home') === ['hero', 'pasek', 'obietnice', "blok:$idB2", 'katalog', "blok:$idB", 'pro', 'kontakt'], $cles('home'));
ok('cacher un bloc le dépublie — une seule vérité', wsm_layout_toggle($pdo, 'home', "blok:$idB")
   && (int) wsm_page_get($pdo, (int) $idB)['published'] === 0 && wsm_page_blok($pdo, (int) $idB, 'pl') === null);
ok('… il reste dans la liste, marqué caché, à sa place', in_array("blok:$idB", $cles('home'), true)
   && array_values(array_filter(wsm_layout_get($pdo, 'home'), fn($it) => $it['k'] === "blok:$idB"))[0]['on'] === 0);
wsm_layout_toggle($pdo, 'home', "blok:$idB");
ok('un bloc monte au-dessus du catalogue', wsm_layout_move($pdo, 'home', "blok:$idB", 'up')
   && array_search("blok:$idB", $cles('home'), true) === array_search('katalog', $cles('home'), true) - 1, $cles('home'));
ok('une clé avec un chiffre (b2b) survit à la lecture — le premier jet la perdait',
   (function () use ($pdo) { wsm_layout_write($pdo, 'nav', [['k' => 'kontakt', 'on' => 1], ['k' => 'b2b', 'on' => 0], ['k' => 'sklep', 'on' => 1], ['k' => 'zamowienie', 'on' => 1]], 'test');
       $l = wsm_layout_get($pdo, 'nav'); wsm_layout_reset($pdo, 'nav');
       // « zwroty », arrivé après cette préférence, reprend sa place d'origine.
       return array_column($l, 'k') === ['kontakt', 'b2b', 'sklep', 'zamowienie', 'zwroty'] && $l[1]['on'] === 0; })());
$e = [];
$idS = wsm_page_save($pdo, null, ['kind' => 'strona', 'slug' => "menu-$sfx", 'published' => 1, 'in_nav' => 1, 'in_footer' => 1,
    't' => ['pl' => ['title' => "Strona $sfx"]]], 'test', $e);
$ids[] = $idS;
ok('une page cochée « w menu » entre à la fin du menu', end($cles) === null || $cles('nav') === ['sklep', 'b2b', 'zamowienie', 'zwroty', 'kontakt', "strona:$idS"], $cles('nav'));
ok('… et avant le lien console dans le pied de page', $cles('footer') === ['email', 'telefon', 'zamowienie', 'regulamin', 'prywatnosc', 'zwroty', 'kontakt', "strona:$idS", 'konsola'], $cles('footer'));
ok('la page monte devant Kontakt', wsm_layout_move($pdo, 'nav', "strona:$idS", 'up') && $cles('nav')[4] === "strona:$idS", $cles('nav'));
ok('la cacher du menu décoche « w menu » — et la garde dans la stopka',
   wsm_layout_toggle($pdo, 'nav', "strona:$idS") && (int) wsm_page_get($pdo, (int) $idS)['in_nav'] === 0 && (int) wsm_page_get($pdo, (int) $idS)['in_footer'] === 1);
ok('les liens visibles du menu ne la portent plus', !in_array("strona:$idS", array_column(wsm_layout_liens($pdo, 'nav'), 'k'), true));
ok('ceux de la stopka, si', in_array("strona:$idS", array_column(wsm_layout_liens($pdo, 'footer'), 'k'), true));
wsm_layout_toggle($pdo, 'nav', "strona:$idS");
$pdo->prepare("UPDATE wsm_pages SET published = 0 WHERE id = ?")->execute([$idS]);
ok('un brouillon n\'est jamais un lien, même coché', !in_array("strona:$idS", array_column(wsm_layout_liens($pdo, 'nav'), 'k'), true));
$pdo->prepare("UPDATE wsm_pages SET published = 1 WHERE id = ?")->execute([$idS]);
wsm_page_delete($pdo, (int) $idB2);
ok('un bloc supprimé sort de la liste', !in_array("blok:$idB2", $cles('home'), true), $cles('home'));

// ---- 4. Le logo -------------------------------------------------------------------------
echo "\n-- logo z Ustawień --\n";
$champs = wsm_settings_fields();
ok('le logo est un réglage image à transparence, dans le groupe de la vitrine',
   ($champs['logo_image'][4] ?? '') === 'ikona' && ($champs['logo_image'][0] ?? '') === 'sklep' && ($champs['logo_image'][2] ?? []) === ['shop', 'logo_image']);
ok('config.php le déclare vide', str_contains($lire('mrszoko/backoffice/php-api/config.php'), "'logo_image'     => getenv('WSM_SHOP_LOGO') ?: ''"));
ok('la vitrine le lit par logo_src() dans la barre, le pied de page et l\'icône',
   substr_count($lire('mrszoko/shop/layout.php'), 'logo_src()') === 3 && str_contains($lire('mrszoko/shop/seo.php'), 'logo_src()'));

// ---- 5. Vitrine et console (fichiers) ----------------------------------------------
echo "\n-- witryna i konsola --\n";
$vit = $lire('mrszoko/shop/index.php');
ok('l\'accueil se lit dans l\'ordre d\'Układ', str_contains($vit, 'wsm_layout_home($pdo)') && str_contains($vit, 'blok_html($pdo, $lang, (int) $it[\'id\'])'));
ok('… avec un repli sur l\'ordre d\'origine si layout.php manque', str_contains($vit, "['hero', 'pasek', 'obietnice', 'katalog', 'pro', 'kontakt']"));
ok('… et un marqueur à la place d\'une section cachée', str_contains($vit, 'wsm_layout_marker($typ)') && str_contains($vit, "wsm_layout_marker('pasek')"));
ok('les marqueurs gardent les mots que les contrôles cherchent',
   str_contains(wsm_layout_marker('pro'), 'sekcja pro: ukryta') && str_contains(wsm_layout_marker('obietnice'), 'obietnice: ukryte') && str_contains(wsm_layout_marker('pasek'), 'pasek: ukryty'));
$lay = $lire('mrszoko/shop/layout.php');
ok('le menu et le pied de page viennent d\'une seule fonction', substr_count($lay, 'liens_uklad(wsm_pdo(), $S, $lang,') === 2);
ok('lib.php charge layout.php sans tomber s\'il manque', str_contains($lire('mrszoko/shop/lib.php'), "is_file(\$WSM_API_DIR . '/layout.php')"));
ok('uklad.php existe, dans le rail après Wygląd', is_file("$racine/mrszoko/backoffice/uklad.php")
   && preg_match("/'wyglad\.php'.*'uklad\.php'.*'strony\.php'/s", $lire('mrszoko/backoffice/console.php')) === 1);
$scr = $lire('mrszoko/backoffice/uklad.php');
ok('… poste avec le jeton CSRF et n\'écrit que pour Centrala', str_contains($scr, 'console_csrf_ok()') && str_contains($scr, 'if (!$isAdmin)'));
ok('… et « Przywróć » demande une confirmation', str_contains($scr, 'na_pewno'));
ok('Wygląd ne porte plus les interrupteurs de section', !str_contains($lire('mrszoko/backoffice/wyglad.php'), 'show_pro') && !isset(wsm_theme_fields_safe()['show_pro']));
function wsm_theme_fields_safe(): array { require_once dirname(__DIR__) . '/theme.php'; return wsm_theme_fields(); }

// ---- 6. La page servie -----------------------------------------------------------------
$base = rtrim(getenv('WSM_SHOP_BASE') ?: 'http://localhost:8091', '/');
$ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
$home = @file_get_contents($base . '/', false, $ctx);
if ($home === false || $home === '') {
    echo "  (pominięte: brak serwera sklepu pod $base)\n";
} else {
    echo "\n-- strona serwowana --\n";
    $get = fn() => (string) @file_get_contents($base . '/?t=' . microtime(true), false, $ctx);
    $h = $get();
    $sekwencja = fn(string $html) => array_map(fn($m) => $m[1] !== '' ? $m[1] : 'obietnice',
        (function () use ($html) { preg_match_all('/id="(blok-\d+|katalog|pro)"|class="(obietnice)-pas"/', $html, $m, PREG_SET_ORDER); return $m; })());
    ok('l\'ordre d\'origine : promesses, bloc, catalogue, B2B',
       preg_match('/class="obietnice-pas".*id="blok-' . (int) $idB . '".*id="katalog".*id="pro"/s', $h) === 1, [$sekwencja($h), array_column(wsm_layout_get($pdo, 'home'), 'k')]);
    ok('le pasek est dans le hero', str_contains($h, 'hero-strip'));
    wsm_layout_move($pdo, 'home', 'pro', 'up'); wsm_layout_move($pdo, 'home', 'pro', 'up');
    $h = $get();
    ok('déplacé deux fois, le B2B précède le bloc et le catalogue', preg_match('/id="pro".*id="blok-' . (int) $idB . '".*id="katalog"/s', $h) === 1);
    wsm_layout_toggle($pdo, 'home', 'obietnice'); wsm_layout_toggle($pdo, 'home', 'pasek'); wsm_layout_toggle($pdo, 'home', 'pro');
    $h = $get();
    ok('cachés, les promesses, le pasek et le B2B laissent leurs marqueurs',
       !str_contains($h, 'class="obietnice-pas"') && str_contains($h, 'obietnice: ukryte')
       && !str_contains($h, 'hero-strip') && str_contains($h, 'pasek: ukryty')
       && !str_contains($h, 'id="pro"') && str_contains($h, 'sekcja pro: ukryta'));
    ok('le catalogue, lui, est toujours là', str_contains($h, 'id="katalog"'));
    ok('le menu du haut porte la page, dans son ordre (devant Kontakt)',
       preg_match('/navlink" href="[^"]*\/menu-' . $sfx . '">Strona ' . $sfx . '<\/a>\s*<a class="navlink" href="[^"]*kontakt">/s', $h) === 1);
    for ($i = 0; $i < 5; $i++) wsm_layout_move($pdo, 'nav', 'kontakt', 'up');
    $h = $get();
    ok('Kontakt en tête du menu après cinq crans', preg_match('/<nav class="head-nav"[^>]*>\s*<a class="navlink" href="[^"]*kontakt">/s', $h) === 1);
    wsm_layout_toggle($pdo, 'footer', 'konsola');
    $h = $get();
    ok('le lien console disparaît du pied de page, le règlement y reste',
       !str_contains($h, '/../backoffice/') && str_contains($h, 'href="/regulamin"'));
    $pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'logo_image'")->execute();
    $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES ('logo_image', 'media/abcdefabcdefabcdefabcdef.webp', 0, ?, 'test')")->execute([date('Y-m-d H:i:s')]);
    $h = $get();
    ok('le logo déposé prend la barre du haut et l\'icône d\'onglet',
       substr_count($h, '/media/abcdefabcdefabcdefabcdef.webp') >= 2 && str_contains($h, 'rel="icon" href="/media/abcdefabcdefabcdefabcdef.webp"'));
    $pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'logo_image'")->execute();
    $h = $get();
    ok('sans logo déposé, le logo du design system (Szoko : celui d\'origine)', str_contains($h, 'ds/szoko/logo.png') && !str_contains($h, 'logo.svg'));
}

// ---- Remise en place ----------------------------------------------------------------------
foreach ($ids as $id) if ($id) wsm_page_delete($pdo, (int) $id);
foreach (WSM_LAYOUT_LISTES as $w) {
    wsm_layout_reset($pdo, $w);
    if ($avant[$w]) $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,0,?,'test')")->execute(["layout.$w", $avant[$w], date('Y-m-d H:i:s')]);
}
$pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'logo_image'")->execute();
if ($avantLogo !== '') $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES ('logo_image', ?, 0, ?, 'test')")->execute([$avantLogo, date('Y-m-d H:i:s')]);

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
