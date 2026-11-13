<?php
// ============================================================================
//  e2e_strony.php — les pages et les blocs écrits depuis la console (pages.php).
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. LE CORPS NE LAISSE PASSER AUCUN HTML, et un lien n'est un lien que si
//      son adresse est sûre : <script> devient du texte, javascript: reste du
//      texte, un lien vers une page de la boutique passe par u().
//   2. UNE ADRESSE RÉSERVÉE EST REFUSÉE : une page « koszyk » masquerait la
//      caisse. L'adresse se fabrique proprement depuis un titre polonais ou
//      ukrainien, et deux pages ne partagent jamais une adresse.
//   3. LE POLONAIS EST LE FILET : une page traduite à moitié montre le
//      polonais là où la traduction manque, champ par champ.
//   4. UN BROUILLON N'EXISTE PAS POUR LE PUBLIC : non publié = 404, absent
//      du menu, du pied de page et du plan du site.
//   5. LA PAGE SERVIE (serveur de boutique sur 8091) porte le titre, le corps
//      rendu, le lien du menu ; le bloc apparaît à son emplacement de
//      l'accueil ; supprimée, l'adresse répond 404.
//
//  Usage :  php tests/e2e_strony.php
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
require_once dirname(__DIR__) . '/media.php';
require_once dirname(__DIR__) . '/pages.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end strony i bloki\n\n";

$sfx = bin2hex(random_bytes(3));
$racine = dirname(__DIR__, 4);
$lire = fn(string $rel) => (string) @file_get_contents($racine . '/' . $rel);
$u = fn(string $x) => '/shop/' . ltrim($x, '/');
$ids = [];

// ---- 1. Rendu sûr ---------------------------------------------------------------------
echo "-- treść: żaden HTML nie przechodzi, link tylko bezpieczny --\n";
$html = wsm_page_render("## Tytuł\n\nAkapit z **pogrubieniem** i <script>alert(1)</script>.\n\n- raz\n- dwa\n\n[kontakt](kontakt) [zły](javascript:alert(1)) [zew](https://example.com/x) [lok](/shop/#katalog)\n\n![Zdjęcie](media/aaaaaaaaaaaaaaaaaaaaaaaa.webp)\n\n![cudze](http://evil.test/x.png)\n\n---\n\n### Mniejszy", $u);
ok('un titre devient h2', str_contains($html, '<h2>Tytuł</h2>'));
ok('le gras devient strong', str_contains($html, '<strong>pogrubieniem</strong>'));
ok('<script> est du texte, pas une balise', str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($html, '<script>'));
ok('la liste devient ul/li', str_contains($html, '<ul><li>raz</li><li>dwa</li></ul>'));
ok('un lien vers une page de la boutique passe par u()', str_contains($html, '<a href="/shop/kontakt">kontakt</a>'));
ok('javascript: n\'est PAS un lien — il reste du texte', !str_contains($html, 'javascript:alert') || str_contains($html, '[zły](javascript:alert(1))'));
ok('… et aucun href javascript nulle part', !preg_match('/href="javascript/i', $html));
ok('un lien https s\'ouvre à part, sans fuite de referrer', str_contains($html, '<a href="https://example.com/x" target="_blank" rel="noopener">zew</a>'));
ok('un lien local garde son adresse', str_contains($html, '<a href="/shop/#katalog">lok</a>'));
ok('une image de la maison devient une figure', str_contains($html, '<figure class="page-fig"><img src="/shop/media/aaaaaaaaaaaaaaaaaaaaaaaa.webp" alt="Zdjęcie"'));
ok('une image http distante n\'est pas une image', !str_contains($html, 'evil.test/x.png"') && str_contains($html, '![cudze]'));
ok('le filet et le h3', str_contains($html, '<hr>') && str_contains($html, '<h3>Mniejszy</h3>'));
ok('un corps vide ne rend rien', wsm_page_render('') === '');
ok('les médias cités se lisent dans le corps', wsm_page_media_cites("x ![a](media/aaaaaaaaaaaaaaaaaaaaaaaa.webp) y media/bbbbbbbbbbbbbbbbbbbbbbbb.jpg") === ['media/aaaaaaaaaaaaaaaaaaaaaaaa.webp', 'media/bbbbbbbbbbbbbbbbbbbbbbbb.jpg']);

// ---- 2. Adresses ----------------------------------------------------------------------
echo "\n-- adres: z tytułu, bez znaków, nigdy zarezerwowany, nigdy podwójny --\n";
ok('un titre polonais donne une adresse propre', wsm_page_slug('Jak zamawiać? Łatwo — Wrocław!') === 'jak-zamawiac-latwo-wroclaw', wsm_page_slug('Jak zamawiać? Łatwo — Wrocław!'));
ok('un titre ukrainien aussi', wsm_page_slug('Про нас') === 'pro-nas', wsm_page_slug('Про нас'));
$why = null;
ok('« koszyk » est refusé — il masquerait la caisse', !wsm_page_slug_ok('koszyk', $why) && str_contains((string) $why, 'zarezerwowany'), $why);
ok('« p » aussi — les fiches produit', !wsm_page_slug_ok('p'));
ok('« index.php » aussi', !wsm_page_slog_ok_wrap('index.php'));
function wsm_page_slog_ok_wrap(string $s): bool { return wsm_page_slug_ok($s); }
ok('une majuscule ou un espace sont refusés', !wsm_page_slug_ok('O nas') && !wsm_page_slug_ok('Onas'));
ok('un vide est refusé', !wsm_page_slug_ok(''));
ok('« o-nas » passe', wsm_page_slug_ok('o-nas'));

$e = [];
$idA = wsm_page_save($pdo, null, ['kind' => 'strona', 'slug' => '', 'published' => 1, 'in_nav' => 1, 'sort_order' => 5,
    't' => ['pl' => ['title' => "O nas $sfx", 'lead' => 'Czekolada z Wrocławia', 'body' => "## Kim jesteśmy\n\nRobimy **czekoladę**. [Napisz](kontakt)", 'meta_desc' => 'Opis PL'],
            'en' => ['title' => "About us $sfx", 'body' => '']]], 'test', $e);
$ids[] = $idA;
ok('une page se crée, l\'adresse vient du titre polonais', $idA !== null && $e === [], $e);
$pA = wsm_page_get($pdo, (int) $idA);
ok('… « o-nas-<sfx> »', ($pA['slug'] ?? '') === "o-nas-$sfx", $pA['slug'] ?? null);
$e = [];
$dbl = wsm_page_save($pdo, null, ['kind' => 'strona', 'slug' => "o-nas-$sfx", 't' => ['pl' => ['title' => 'Dublet']]], 'test', $e);
ok('la même adresse pour une seconde page est refusée', $dbl === null && isset($e['slug']), $e);
$e = [];
$rez = wsm_page_save($pdo, null, ['kind' => 'strona', 'slug' => 'kasa', 't' => ['pl' => ['title' => 'Kasa']]], 'test', $e);
ok('une adresse réservée est refusée à l\'enregistrement', $rez === null && isset($e['slug']), $e);
$e = [];
$sans = wsm_page_save($pdo, null, ['kind' => 'strona', 'slug' => 'x', 't' => ['en' => ['title' => 'Only English']]], 'test', $e);
ok('sans titre polonais, rien n\'est écrit — c\'est le filet des autres langues', $sans === null && isset($e['title']), $e);
$e = [];
$cta = wsm_page_save($pdo, null, ['kind' => 'strona', 'slug' => "zly-$sfx", 't' => ['pl' => ['title' => 'Zły', 'cta_label' => 'x', 'cta_url' => 'javascript:alert(1)']]], 'test', $e);
ok('un bouton javascript: est refusé', $cta === null && isset($e['cta_url']), $e);
$e = [];
$blokStyl = wsm_page_save($pdo, null, ['kind' => 'blok', 'styl' => ['foto_prawo', 'nieznany', 'ciemny'], 'published' => 1, 't' => ['pl' => ['title' => "Styl $sfx"]]], 'test', $e);
$ids[] = $blokStyl;
ok('un bloc se crée sans emplacement — sa place se règle dans Układ', $blokStyl !== null, $e);
ok('ses styles sont filtrés sur la liste connue, dans l\'ordre posté',
   (wsm_page_blok($pdo, (int) $blokStyl, 'pl')['styl'] ?? null) === ['foto_prawo', 'ciemny'], wsm_page_blok($pdo, (int) $blokStyl, 'pl')['styl'] ?? null);

// ---- 3. Repli sur le polonais --------------------------------------------------------
echo "\n-- polski jest siatką: brakujące tłumaczenie spada na polski, pole po polu --\n";
$en = wsm_page_find($pdo, "o-nas-$sfx", 'en');
ok('la page se trouve en anglais', $en !== null);
ok('le titre anglais est le sien', ($en['title'] ?? '') === "About us $sfx", $en['title'] ?? null);
ok('le corps, non traduit, est le polonais', str_contains((string) ($en['body'] ?? ''), 'Kim jesteśmy'));
ok('la langue utilisée est dite', ($en['lang_used'] ?? '') === 'en');
$uk = wsm_page_find($pdo, "o-nas-$sfx", 'uk');
ok('en ukrainien, rien de traduit : tout est polonais, la page existe quand même', ($uk['title'] ?? '') === "O nas $sfx" && ($uk['lang_used'] ?? '') === 'pl');

// ---- 4. Brouillon ------------------------------------------------------------------------
echo "\n-- szkic nie istnieje dla świata --\n";
$e = [];
$idB = wsm_page_save($pdo, null, ['kind' => 'strona', 'slug' => "szkic-$sfx", 'published' => 0, 'in_nav' => 1, 'in_footer' => 1,
    't' => ['pl' => ['title' => "Szkic $sfx", 'body' => 'tajne']]], 'test', $e);
$ids[] = $idB;
ok('un brouillon s\'enregistre', $idB !== null, $e);
ok('… mais ne se trouve pas publiquement', wsm_page_find($pdo, "szkic-$sfx", 'pl') === null);
ok('… sauf en le demandant explicitement (l\'écran)', wsm_page_find($pdo, "szkic-$sfx", 'pl', false) !== null);
$nav = array_column(wsm_page_liens($pdo, 'pl', 'nav'), 'slug');
ok('la page publiée est dans le menu', in_array("o-nas-$sfx", $nav, true), $nav);
ok('le brouillon n\'y est pas, même coché', !in_array("szkic-$sfx", $nav, true));
ok('ni dans le pied de page', !in_array("szkic-$sfx", array_column(wsm_page_liens($pdo, 'pl', 'footer'), 'slug'), true));
ok('ni dans le plan du site', !in_array("szkic-$sfx", wsm_page_sitemap($pdo), true) && in_array("o-nas-$sfx", wsm_page_sitemap($pdo), true));
ok('un menu en anglais porte le titre anglais', in_array("About us $sfx", array_column(wsm_page_liens($pdo, 'en', 'nav'), 'title'), true));

$e = [];
$idC = wsm_page_save($pdo, null, ['kind' => 'blok', 'published' => 1, 'sort_order' => 1,
    't' => ['pl' => ['title' => "Nowość $sfx", 'body' => 'Spróbuj **nowej** tabliczki.', 'cta_label' => 'Zobacz', 'cta_url' => '#katalog']]], 'test', $e);
$ids[] = $idC;
$pC = $idC !== null ? wsm_page_get($pdo, (int) $idC) : [];
ok('un bloc se crée sans adresse (NULL, pas une chaîne vide — UNIQUE tolère plusieurs NULL)',
   $idC !== null && array_key_exists('slug', $pC) && $pC['slug'] === null, $e ?: ($pC['slug'] ?? 'absent'));
$e = [];
$idD = wsm_page_save($pdo, null, ['kind' => 'blok', 'published' => 1, 'sort_order' => 1,
    't' => ['pl' => ['title' => "Drugi blok $sfx"]]], 'test', $e);
$ids[] = $idD;
ok('un second bloc sans adresse ne heurte pas l\'unicité', $idD !== null, $e);
$bC = wsm_page_blok($pdo, (int) $idC, 'pl');
ok('un bloc publié se lit seul, textes et styles compris', $bC !== null && $bC['title'] === "Nowość $sfx" && $bC['styl'] === [] && $bC['cta_url'] === '#katalog');
$pdo->prepare("UPDATE wsm_pages SET published = 0 WHERE id = ?")->execute([$idD]);
ok('un bloc dépublié ne se lit pas', wsm_page_blok($pdo, (int) $idD, 'pl') === null);
ok('une page n\'est pas un bloc', wsm_page_blok($pdo, (int) $idA, 'pl') === null);
ok('un identifiant inconnu non plus', wsm_page_blok($pdo, 999999, 'pl') === null);

// Modification : l'adresse change, l'ancienne ne répond plus.
$e = [];
$idA2 = wsm_page_save($pdo, (int) $idA, ['kind' => 'strona', 'slug' => "o-firmie-$sfx", 'published' => 1, 'in_nav' => 1,
    't' => ['pl' => ['title' => "O firmie $sfx", 'body' => 'nowa treść'], 'en' => ['title' => '', 'body' => '']]], 'test', $e);
ok('une modification garde l\'identifiant', $idA2 === $idA, $e);
ok('la nouvelle adresse répond, l\'ancienne non',
   wsm_page_find($pdo, "o-firmie-$sfx", 'pl') !== null && wsm_page_find($pdo, "o-nas-$sfx", 'pl') === null);
ok('une langue vidée dans le formulaire disparaît', !isset(wsm_page_get($pdo, (int) $idA)['i18n']['en']), array_keys(wsm_page_get($pdo, (int) $idA)['i18n']));
$e = [];
wsm_page_save($pdo, (int) $idA, ['kind' => 'strona', 'slug' => "o-firmie-$sfx", 'published' => 1, 'in_nav' => 1,
    't' => ['pl' => ['title' => "O firmie $sfx", 'body' => 'nowa treść'], 'uk' => ['title' => "Про фірму $sfx"]]], 'test', $e);
wsm_page_save($pdo, (int) $idA, ['kind' => 'strona', 'slug' => "o-firmie-$sfx", 'published' => 1, 'in_nav' => 1,
    't' => ['pl' => ['title' => "O firmie $sfx", 'body' => 'nowa treść']]], 'test', $e);
ok('une langue ABSENTE du formulaire est gardée — on ne perd pas une traduction qu\'on n\'a pas vue',
   ($pdo->query("SELECT COUNT(*) FROM wsm_page_i18n WHERE page_id = " . (int) $idA . " AND lang = 'uk'")->fetchColumn() ?: 0) == 1);
wsm_page_save($pdo, (int) $idA, ['kind' => 'strona', 'slug' => "o-firmie-$sfx", 'published' => 1, 'in_nav' => 1,
    't' => ['pl' => ['title' => "O firmie $sfx", 'body' => 'nowa treść'], 'uk' => ['title' => '']]], 'test', $e);
$liste = wsm_page_list($pdo);
$moi = array_values(array_filter($liste, fn($p) => (int) $p['id'] === (int) $idA));
ok('la liste porte le titre polonais et les langues remplies', ($moi[0]['title'] ?? '') === "O firmie $sfx" && ($moi[0]['langs'] ?? []) === ['pl'], $moi[0] ?? null);

// ---- 5. Vitrine et console (fichiers) ----------------------------------------------
echo "\n-- witryna i konsola --\n";
$vit = $lire('generique/shop/index.php');
ok('la vitrine route les pages avant le 404', preg_match('/wsm_page_find\(\$pdo, \$page, \$lang\).*http_response_code\(404\)/s', $vit) === 1);
ok('… et pose chaque bloc à la place qu\'Układ lui donne', str_contains($vit, 'wsm_layout_home($pdo)') && str_contains($vit, 'blok_html($pdo, $lang, (int) $it[\'id\'])'));
ok('… et liste les pages dans le plan du site', str_contains($vit, 'wsm_page_sitemap($pdo)'));
$lay = $lire('generique/shop/layout.php');
ok('le menu et le pied de page lisent les pages par Układ', substr_count($lay, 'liens_uklad(wsm_pdo(), $S, $lang,') === 2);
ok('lib.php charge pages.php sans tomber s\'il manque', str_contains($lire('generique/shop/lib.php'), "is_file(\$WSM_API_DIR . '/pages.php')"));
ok('strony.php et media.php existent', is_file("$racine/generique/backoffice/strony.php") && is_file("$racine/generique/backoffice/media.php"));
ok('… dans le rail, à côté de Treści et Wygląd', preg_match("/'wyglad\.php'.*'strony\.php'.*'media\.php'/s", $lire('generique/backoffice/console.php')) === 1);
$scr = $lire('generique/backoffice/strony.php');
ok('… l\'écran poste avec le jeton CSRF et n\'écrit que pour Centrala', str_contains($scr, 'console_csrf_ok()') && str_contains($scr, 'if (!$isAdmin)'));
ok('… la suppression demande une confirmation', str_contains($scr, 'na_pewno'));
ok('… et l\'aperçu utilise la MÊME fonction de rendu que la boutique', str_contains($scr, 'wsm_page_render($pl[\'body\']'));
ok('le style de la vitrine connaît la page et le bloc', str_contains($lire('generique/shop/shop.css'), '.blok-in') && str_contains($lire('generique/shop/shop.css'), '.prose h2'));
ok('les deux schémas portent les tables', str_contains($lire('generique/backoffice/php-api/schema/webshop_mrszoko.mysql.sql'), '`wsm_page_i18n`')
   && str_contains($lire('generique/backoffice/php-api/schema/webshop_mrszoko.sqlite.sql'), 'wsm_page_i18n'));
ok('… et db.php les assure sur une base ancienne', str_contains($lire('generique/backoffice/php-api/db.php'), "'wsm_pages', 'wsm_page_i18n'"));

// La page SERVIE, quand un serveur de boutique tourne (php -S localhost:8091).
$base = rtrim(getenv('WSM_SHOP_BASE') ?: 'http://localhost:8091', '/');
$ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
$home = @file_get_contents($base . '/', false, $ctx);
if ($home === false || $home === '') {
    echo "  (pominięte: brak serwera sklepu pod $base)\n";
} else {
    echo "\n-- strona serwowana --\n";
    $code = fn(array $h) => (int) (preg_match('/ (\d{3}) /', $h[0] ?? '', $m) ? $m[1] : 0);
    $pg = @file_get_contents("$base/o-firmie-$sfx?lang=pl", false, $ctx);
    ok('la page publiée répond 200', $code($http_response_header) === 200, $http_response_header[0] ?? null);
    ok('… avec son titre en h1 et dans <title>', str_contains((string) $pg, "<h1>O firmie $sfx</h1>") && str_contains((string) $pg, "<title>O firmie $sfx"));
    ok('… son corps rendu', str_contains((string) $pg, '<div class="prose"><p>nowa treść</p></div>'));
    ok('… indexable (pas de noindex)', !str_contains((string) $pg, 'noindex'));
    ok('le menu du haut porte le lien', str_contains((string) $pg, "href=\"/o-firmie-$sfx\"") || str_contains((string) $pg, "/o-firmie-$sfx\">O firmie $sfx</a>"));
    $dr = @file_get_contents("$base/szkic-$sfx", false, $ctx);
    ok('le brouillon répond 404', $code($http_response_header) === 404, $http_response_header[0] ?? null);
    $h2 = (string) @file_get_contents("$base/?t=" . time(), false, $ctx);
    ok('le bloc est sur l\'accueil, sous le catalogue', preg_match('/id="katalog".*id="blok-' . (int) $idC . '".*id="pro"/s', $h2) === 1);
    ok('… avec son gras rendu et son bouton', str_contains($h2, 'Spróbuj <strong>nowej</strong> tabliczki.') && str_contains($h2, 'href="#katalog">Zobacz</a>'));
    $sm = (string) @file_get_contents("$base/sitemap.xml", false, $ctx);
    ok('le plan du site liste la page', str_contains($sm, "/o-firmie-$sfx"));
    wsm_page_delete($pdo, (int) $idA);
    @file_get_contents("$base/o-firmie-$sfx", false, $ctx);
    ok('supprimée, l\'adresse répond 404', $code($http_response_header) === 404, $http_response_header[0] ?? null);
}

// ---- Nettoyage ----------------------------------------------------------------------------
foreach ($ids as $id) if ($id) wsm_page_delete($pdo, (int) $id);
$pdo->prepare("DELETE FROM wsm_page_i18n WHERE page_id NOT IN (SELECT id FROM wsm_pages)")->execute();

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
