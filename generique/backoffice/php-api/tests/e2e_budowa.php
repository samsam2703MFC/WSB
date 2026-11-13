<?php
// ============================================================================
//  e2e_budowa.php — le Kreator : la boutique composée en sections (sections.php,
//  budowa.php, sekcja_html dans la vitrine).
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. ONZE GABARITS, PAS UN ÉDITEUR LIBRE : chaque type dit ses champs, son
//      image, ses items, ses styles, et où il peut se poser. Le pasek ogłoszeń
//      ne va que sur tout le site ; les autres sur l'accueil et les pages.
//   2. LE REFUS AVANT L'ÉCRITURE : type inconnu, mauvaise cible, texte sans
//      polonais, image hors médiathèque, bouton javascript:, corps trop long —
//      rien n'est écrit tant qu'il y a un refus, et l'écran sait pourquoi.
//   3. LE POLONAIS EST LA GRILLE : une traduction remplit la même case (3e
//      photo = 3e légende), champ par champ ; une case vide montre le polonais.
//   4. L'ACCUEIL EST UNE SEULE FILE : Układ voit les sections, les cache, les
//      monte au-dessus du catalogue — jamais au-dessus du nagłówek — et le
//      nagłówek natif peut céder la place à celui du Kreator.
//   5. LA PAGE SERVIE (boutique sur 8091) porte les sections à leur place,
//      le HTML saisi devenu texte, le pasek sur toutes les pages avec un lien
//      qui ramène à l'accueil ; un brouillon n'existe pas pour le public.
//   6. UNE PAGE SUPPRIMÉE EMPORTE SES SECTIONS ; une section supprimée rend
//      son image pour qu'on la range.
//
//  Usage :  php tests/e2e_budowa.php
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
require_once dirname(__DIR__) . '/sections.php';
require_once dirname(__DIR__) . '/layout.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end Kreator strony (sekcje)\n\n";

$sfx = bin2hex(random_bytes(3));
$racine = dirname(__DIR__, 4);
$lire = fn(string $rel) => (string) @file_get_contents($racine . '/' . $rel);
$E = [];
$sv = function (array $in) use ($pdo, &$E) { $E = []; return wsm_section_save($pdo, null, $in, 'test', $E); };
wsm_layout_reset($pdo, 'home');
foreach ($pdo->query("SELECT id FROM wsm_sections WHERE updated_by = 'test'")->fetchAll() ?: [] as $r) wsm_section_delete($pdo, (int) $r['id']);

// ---- 1. Le registre -----------------------------------------------------------------------
echo "-- rejestr: jedenaście rodzajów, każdy z gabarytem i miejscem --\n";
$types = wsm_section_types();
ok('onze types', count($types) === 11, array_keys($types));
ok('chaque type dit label, opis, pola, obraz, items, styl, ustawienia, cel',
   !array_filter($types, fn($d) => array_diff(['label', 'opis', 'pola', 'obraz', 'items', 'styl', 'ustawienia', 'cel'], array_keys($d))));
ok('le pasek ogłoszeń ne va QUE sur tout le site', $types['ogloszenie']['cel'] === ['site']);
ok('les autres vont sur l\'accueil et les pages, jamais sur tout le site',
   !array_filter($types, fn($d, $k) => $k !== 'ogloszenie' && $d['cel'] !== ['home', 'strona'], ARRAY_FILTER_USE_BOTH));
ok('les cibles ont un nom', wsm_section_cel(0) === 'home' && wsm_section_cel(-1) === 'site' && wsm_section_cel(7) === 'strona');
ok('les styles connus ont une étiquette', !array_diff(array_unique(array_merge(...array_column($types, 'styl'))), array_keys(wsm_section_style_labels())));
wsm_sections_ensure($pdo);
ok('les deux tables existent après ensure', wsm_table_exists($pdo, 'wsm_sections') && wsm_table_exists($pdo, 'wsm_section_i18n'));

// ---- 2. Refus ------------------------------------------------------------------------------
echo "\n-- odmowy: zły rodzaj, złe miejsce, bez polskiego, zły obraz, zły adres — nic nie zapisane --\n";
ok('un type inconnu est refusé', $sv(['page_id' => 0, 'type' => 'wideo', 't' => ['pl' => ['title' => 'x']]]) === null && isset($E['type']), $E);
ok('le pasek ogłoszeń sur l\'accueil est refusé', $sv(['page_id' => 0, 'type' => 'ogloszenie', 't' => ['pl' => ['body' => 'x']]]) === null && isset($E['page_id']), $E);
ok('des kafelki sur tout le site sont refusés', $sv(['page_id' => -1, 'type' => 'kafelki', 't' => ['pl' => ['title' => 'x']]]) === null && isset($E['page_id']), $E);
ok('une page inexistante est refusée', $sv(['page_id' => 999999, 'type' => 'tekst', 't' => ['pl' => ['title' => 'x']]]) === null && isset($E['page_id']), $E);
ok('un texte seulement en anglais est refusé — le polonais est la base', $sv(['page_id' => 0, 'type' => 'tekst', 't' => ['en' => ['title' => 'Only EN']]]) === null && isset($E['title']), $E);
ok('une image hors médiathèque est refusée', $sv(['page_id' => 0, 'type' => 'foto', 'image_url' => 'http://evil.test/x.png', 't' => ['pl' => ['lead' => 'x']]]) === null && isset($E['image_url']), $E);
ok('une image d\'item hors médiathèque est refusée', $sv(['page_id' => 0, 'type' => 'galeria', 't' => ['pl' => ['title' => 'x', 'items' => [['t' => 'a', 'img' => 'http://evil.test/x.png']]]]]) === null && isset($E['items']), $E);
ok('un bouton javascript: est refusé', $sv(['page_id' => 0, 'type' => 'baner', 't' => ['pl' => ['title' => 'x', 'cta_label' => 'go', 'cta_url' => 'javascript:alert(1)']]]) === null && isset($E['cta_url']), $E);
ok('un corps trop long est refusé', $sv(['page_id' => 0, 'type' => 'tekst', 't' => ['pl' => ['title' => 'x', 'body' => str_repeat('a', WSM_PAGE_BODY_MAX + 1)]]]) === null && isset($E['body']), $E);
ok('rien n\'a été écrit par ces refus', (int) $pdo->query("SELECT COUNT(*) FROM wsm_sections WHERE updated_by = 'test'")->fetchColumn() === 0);
$idOd = $sv(['page_id' => 0, 'type' => 'odstep', 'published' => 1, 'styl' => ['linia', 'nieznany'], 'ustawienia' => ['rozmiar' => 'ogromny']]);
ok('un odstęp sans rien est accepté — il est fait de rien', $idOd !== null, $E);
$od = wsm_section_get($pdo, (int) $idOd);
ok('un style inconnu tombe, un réglage inconnu prend le défaut', $od['styl'] === ['linia'] && $od['ustawienia'] === ['rozmiar' => 'maly'], [$od['styl'], $od['ustawienia']]);

// ---- 3. Enregistrement et vue --------------------------------------------------------------
echo "\n-- zapis i widok: polski jest siatką, pozycja po pozycji --\n";
$idK = $sv(['page_id' => 0, 'type' => 'kafelki', 'published' => 1, 'styl' => ['ciemny'], 'ustawienia' => ['kolumny' => '4'],
    't' => ['pl' => ['title' => "Dlaczego my $sfx", 'lead' => 'Trzy powody', 'items' => [
                ['t' => 'Świeżość', 'd' => 'Co tydzień', 'img' => 'media/aaaaaaaaaaaaaaaaaaaaaaaa.webp'],
                ['t' => '', 'd' => '', 'img' => ''],                                     // une case vide au milieu
                ['t' => '<b>Kurier</b>', 'd' => '24 h', 'img' => 'https://cdn.test/k.png'],
                ['t' => '', 'd' => '', 'img' => ''], ['t' => '', 'd' => '', 'img' => '']]],  // des vides de fin
            'en' => ['title' => '', 'items' => [['t' => 'Freshness', 'd' => '', 'img' => '']]]]]);
ok('les kafelki sont enregistrés', $idK !== null, $E);
$s = wsm_section_get($pdo, (int) $idK);
ok('relus avec leur type, un style que le type ne permet pas tombe, les réglages validés',
   $s && $s['type'] === 'kafelki' && $s['styl'] === [] && $s['ustawienia'] === ['kolumny' => '4'], [$s['styl'] ?? null, $s['ustawienia'] ?? null]);
$dec = wsm_section_items_decode((string) $s['i18n']['pl']['items']);
ok('les cases gardent leur position, les vides de fin tombent', count($dec) === 3 && $dec[1] === ['t' => '', 'd' => '', 'img' => ''] && $dec[2]['img'] === 'https://cdn.test/k.png', $dec);
$v = wsm_section_view($pdo, $s, 'pl');
ok('la vue polonaise saute la case vide', count($v['items']) === 2 && $v['items'][1]['t'] === '<b>Kurier</b>', $v['items']);
$ve = wsm_section_view($pdo, $s, 'en');
ok('la vue anglaise : titre replié sur le polonais', $ve['title'] === "Dlaczego my $sfx", $ve['title']);
ok('… 1er item traduit, sa description et son image viennent du polonais',
   $ve['items'][0] === ['t' => 'Freshness', 'd' => 'Co tydzień', 'img' => 'media/aaaaaaaaaaaaaaaaaaaaaaaa.webp'], $ve['items'][0] ?? null);
ok('… 2e item entièrement polonais', count($ve['items']) === 2 && $ve['items'][1]['t'] === '<b>Kurier</b>', $ve['items']);
ok('un bouton valide passe, un bouton invalide est effacé à la vue', (function () use ($pdo, $sfx) {
    $s = ['type' => 'baner', 'image_url' => '', 'i18n' => ['pl' => ['title' => 'x', 'lead' => '', 'body' => '', 'cta_label' => 'Go', 'cta_url' => 'javascript:x', 'items' => '']]];
    return wsm_section_view($pdo, $s, 'pl')['cta_url'] === '' && wsm_section_view($pdo, array_replace_recursive($s, ['i18n' => ['pl' => ['cta_url' => 'kontakt']]]), 'pl')['cta_url'] === 'kontakt';
})());
$ord = (int) $s['sort_order'];
$idK2 = wsm_section_save($pdo, (int) $idK, ['page_id' => 0, 'type' => 'kafelki', 'published' => 1, 'ustawienia' => ['kolumny' => '3'],
    't' => ['pl' => ['title' => "Dlaczego my $sfx v2", 'items' => [['t' => 'A', 'd' => 'a', 'img' => 'media/aaaaaaaaaaaaaaaaaaaaaaaa.webp']]]]], 'test', $E);
$s2 = wsm_section_get($pdo, (int) $idK);
ok('une mise à jour garde l\'identifiant et la place', $idK2 === $idK && (int) $s2['sort_order'] === $ord && $s2['title'] === "Dlaczego my $sfx v2", [$idK2, $s2['sort_order'] ?? null]);
ok('… et efface la traduction qui n\'est plus envoyée', !isset($s2['i18n']['en']));
$idF = $sv(['page_id' => 0, 'type' => 'foto', 'published' => 0, 'image_url' => 'media/bbbbbbbbbbbbbbbbbbbbbbbb.webp', 't' => []]);
ok('une photo seule, sans texte, est acceptée (brouillon)', $idF !== null, $E);
$idTx = $sv(['page_id' => 0, 'type' => 'tekst', 'image_url' => 'media/cccccccccccccccccccccccc.webp', 't' => ['pl' => ['title' => str_repeat('ą', 300), 'body' => 'b']]]);
$tx = wsm_section_get($pdo, (int) $idTx);
ok('un type sans image ignore l\'image envoyée ; le titre est borné à 200', $tx['image_url'] === '' && mb_strlen($tx['title']) === 200, [$tx['image_url'], mb_strlen($tx['title'])]);
ok('une nouvelle section va en fin de file de sa cible', (int) $tx['sort_order'] > (int) $s2['sort_order']);

// ---- 4. L'accueil ---------------------------------------------------------------------------
echo "\n-- strona główna: Układ widzi sekcje, pokazuje, chowa, przesuwa --\n";
$cles = fn() => array_column(wsm_layout_get($pdo, 'home'), 'k');
$entree = function (string $k) use ($pdo): ?array { foreach (wsm_layout_get($pdo, 'home') as $it) if ($it['k'] === $k) return $it; return null; };
$home = $cles();
ok('une section publiée entre dans l\'accueil, après le catalogue',
   in_array("sekcja:$idK", $home, true) && array_search("sekcja:$idK", $home, true) > array_search('katalog', $home, true), $home);
$eF = $entree("sekcja:$idF");
ok('un brouillon y est aussi, marqué non publié', $eF && $eF['on'] === 0 && $eF['published'] === 0 && $eF['type'] === 'sekcja', $eF);
$eK = $entree("sekcja:$idK");
ok('l\'entrée dit son type et son titre', $eK && str_contains($eK['label'], 'Kafelki') && str_contains($eK['label'], "Dlaczego my $sfx v2"), $eK['label'] ?? null);
ok('Układ cache une section (= la dépublie)', wsm_layout_toggle($pdo, 'home', "sekcja:$idK", 'test') && (int) wsm_section_get($pdo, (int) $idK)['published'] === 0);
ok('… et la remontre', wsm_layout_toggle($pdo, 'home', "sekcja:$idK", 'test') && (int) wsm_section_get($pdo, (int) $idK)['published'] === 1);
$avant = array_search("sekcja:$idK", $cles(), true);
ok('elle monte d\'un cran', wsm_layout_move($pdo, 'home', "sekcja:$idK", 'up', 'test') && array_search("sekcja:$idK", $cles(), true) === $avant - 1, $cles());
for ($i = 0; $i < 20; $i++) wsm_layout_move($pdo, 'home', "sekcja:$idK", 'up', 'test');
ok('… jusqu\'au-dessus du catalogue', array_search("sekcja:$idK", $cles(), true) < array_search('katalog', $cles(), true), $cles());
$c = $cles();
ok('… jamais au-dessus du nagłówek ni du pasek', $c[0] === 'hero' && $c[1] === 'pasek' && $c[2] === "sekcja:$idK", $c);
ok('le nagłówek natif se cache — un nagłówek du Kreator peut prendre sa place', wsm_layout_toggle($pdo, 'home', 'hero', 'test') && wsm_layout_get($pdo, 'home')[0]['on'] === 0);
wsm_layout_toggle($pdo, 'home', 'hero', 'test');
ok('le catalogue, lui, reste', !wsm_layout_toggle($pdo, 'home', 'katalog', 'test'));

// ---- 5. Une page et ses sections ; tout le site -------------------------------------------
echo "\n-- strona: sekcje pod treścią; cały sklep: pasek ogłoszeń --\n";
$pg = wsm_page_save($pdo, null, ['kind' => 'strona', 'slug' => "kreator-$sfx", 'published' => 1, 'in_nav' => 0, 'sort_order' => 50,
    't' => ['pl' => ['title' => "Kreator $sfx", 'body' => 'Treść strony.']]], 'test', $E);
ok('une page d\'essai existe', $pg !== null, $E);
$blok = wsm_page_save($pdo, null, ['kind' => 'blok', 'published' => 1, 't' => ['pl' => ['title' => "Blok $sfx", 'body' => 'b']]], 'test', $E);
ok('une section sous un BLOC est refusée — un bloc n\'a pas de page', $sv(['page_id' => $blok, 'type' => 'tekst', 't' => ['pl' => ['title' => 'x']]]) === null && isset($E['page_id']), $E);
$idT = $sv(['page_id' => $pg, 'type' => 'tekst', 'published' => 1, 'ustawienia' => ['kolumny' => '2'],
    't' => ['pl' => ['title' => 'Tekst', 'body' => "Akapit z **pogrubieniem** i <script>alert(1)</script>.\n\n- raz\n- dwa"]]]);
$idQ = $sv(['page_id' => $pg, 'type' => 'faq', 'published' => 1,
    't' => ['pl' => ['title' => 'Pytania', 'items' => [['t' => 'Ile trwa wysyłka?', 'd' => 'Zwykle **24 h**.'], ['t' => 'Czy <i>x</i>?', 'd' => 'Tak.']]]]]);
$idH = $sv(['page_id' => $pg, 'type' => 'baner', 'published' => 0, 't' => ['pl' => ['title' => 'Szkic', 'cta_label' => 'Go', 'cta_url' => '#katalog']]]);
ok('trois sections sous la page', $idT && $idQ && $idH, $E);
$vs = fn() => array_column(wsm_section_views($pdo, (int) $pg, 'pl'), 'id');
ok('les vues publiées, dans l\'ordre, sans le brouillon', $vs() === [$idT, $idQ], $vs());
ok('la FAQ monte, puis redescend', wsm_section_move($pdo, (int) $idQ, 'up', 'test') && $vs() === [$idQ, $idT] && wsm_section_move($pdo, (int) $idQ, 'down', 'test') && $vs() === [$idT, $idQ], $vs());
ok('… pas au-delà des bords', !wsm_section_move($pdo, (int) $idT, 'up', 'test') && !wsm_section_move($pdo, (int) $idH, 'down', 'test'));
ok('une section inconnue ne bouge pas, ne se cache pas, ne se supprime pas', !wsm_section_move($pdo, 999999, 'up') && !wsm_section_toggle($pdo, 999999) && wsm_section_delete($pdo, 999999) === null);
ok('le brouillon se publie', wsm_section_toggle($pdo, (int) $idH, 'test') && count($vs()) === 3);
wsm_section_toggle($pdo, (int) $idH, 'test');
ok('les sections d\'une page ne sont pas dans l\'accueil', !array_intersect($cles(), ["sekcja:$idT", "sekcja:$idQ", "sekcja:$idH"]));
$idO = $sv(['page_id' => -1, 'type' => 'ogloszenie', 'published' => 1, 'styl' => ['akcent'],
    't' => ['pl' => ['body' => "Darmowa dostawa od **200 zł** $sfx", 'cta_label' => 'Sprawdź', 'cta_url' => '#katalog']]]);
ok('un pasek ogłoszeń pour tout le site', $idO !== null && in_array($idO, array_column(wsm_section_views($pdo, -1, 'pl'), 'id'), true), $E);

// ---- 6. Médias -------------------------------------------------------------------------------
echo "\n-- media: sekcje liczą się jako użycie --\n";
$cites = wsm_section_media_cites($pdo);
ok('l\'image d\'un item et l\'image principale sont citées', isset($cites['media/aaaaaaaaaaaaaaaaaaaaaaaa.webp']) && isset($cites['media/bbbbbbbbbbbbbbbbbbbbbbbb.webp']), array_keys($cites));
ok('… avec une étiquette lisible', str_contains(implode(' ', $cites['media/bbbbbbbbbbbbbbbbbbbbbbbb.webp'] ?? []), 'sekcja (Zdjęcie)'), $cites['media/bbbbbbbbbbbbbbbbbbbbbbbb.webp'] ?? null);
ok('la médiathèque les voit comme utilisées', isset(wsm_media_usages($pdo)['media/bbbbbbbbbbbbbbbbbbbbbbbb.webp']));

// ---- 7. La page servie -----------------------------------------------------------------------
echo "\n-- strona podana (8091): sekcje w sklepie, HTML jako tekst, szkic niewidoczny --\n";
$base = 'http://localhost:8091';
$get = function (string $path) use ($base): ?string {
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $h = @file_get_contents($base . $path, false, $ctx);
    return $h === false ? null : $h;
};
$h = $get('/');
if ($h === null) { echo "  (sklep na 8091 nie odpowiada — pominięte)\n"; }
else {
    ok('l\'accueil porte les kafelki, à leur place (avant le catalogue)', str_contains($h, 'id="sek-' . $idK . '"') && strpos($h, 'id="sek-' . $idK . '"') < strpos($h, 'id="katalog"'));
    ok('… l\'item avec son icône de la médiathèque, en trois colonnes', preg_match('/class="promise-ikona" src="[^"]*media\/aaaaaaaaaaaaaaaaaaaaaaaa\.webp"/', $h) === 1 && str_contains($h, 'kafelki kafelki--3'));
    ok('le brouillon (photo) n\'y est pas', !str_contains($h, 'id="sek-' . $idF . '"'));
    ok('l\'odstęp avec sa ligne, lui, y est', preg_match('/id="sek-' . $idOd . '"><hr><\/div>/', $h) === 1);
    ok('le pasek ogłoszeń est au-dessus du nagłówek', str_contains($h, 'id="sek-' . $idO . '"') && strpos($h, 'id="sek-' . $idO . '"') < strpos($h, '<header'));
    ok('… son gras rendu, son lien vers le catalogue', str_contains($h, '<strong>200 zł</strong>') && preg_match('/id="sek-' . $idO . '".*?href="[^"]*#katalog"/s', $h) === 1);
    $hp = $get("/kreator-$sfx");
    ok('la page porte ses sections sous son texte', $hp !== null && strpos($hp, 'Treść strony.') < strpos($hp, 'id="sek-' . $idT . '"') && str_contains($hp, 'id="sek-' . $idQ . '"'));
    ok('… le texte rendu par la grammaire, le script devenu texte', str_contains((string) $hp, '<strong>pogrubieniem</strong>') && str_contains((string) $hp, '&lt;script&gt;') && !str_contains((string) $hp, '<script>alert'));
    ok('… en deux colonnes', str_contains((string) $hp, 'prose prose--2kol'));
    ok('… la FAQ en <details>, sa question échappée, sa réponse en gras', str_contains((string) $hp, '<summary>Czy &lt;i&gt;x&lt;/i&gt;?</summary>') && str_contains((string) $hp, '<strong>24 h</strong>'));
    ok('… le brouillon absent', !str_contains((string) $hp, 'id="sek-' . $idH . '"'));
    ok('… le pasek ogłoszeń aussi sur cette page, son lien RAMÈNE à l\'accueil', preg_match('/id="sek-' . $idO . '".*?href="\/#katalog"/s', (string) $hp) === 1);
    $he = $get('/?lang=en');
    ok('en anglais, le pasek replié sur le polonais', $he !== null && str_contains($he, "200 zł</strong> $sfx"));
    // Le nagłówek natif caché, celui du Kreator porte le h1 de la page.
    wsm_layout_toggle($pdo, 'home', 'hero', 'test');
    $idN = $sv(['page_id' => 0, 'type' => 'hero', 'published' => 1, 'image_url' => 'media/bbbbbbbbbbbbbbbbbbbbbbbb.webp', 'styl' => ['srodek'],
        't' => ['pl' => ['title' => "Nowy nagłówek $sfx", 'cta_label' => 'Do katalogu', 'cta_url' => '#katalog']]]);
    $h2 = $get('/');
    ok('nagłówek natif caché : son marqueur reste, celui du Kreator devient le h1',
       $h2 !== null && str_contains($h2, 'hero: ukryte') && substr_count($h2, '<h1 class="sek-h1">') === 1 && !preg_match('/<section class="hero( hero--(foto|art|slajdy))?"[ >]/', $h2) && str_contains($h2, "Nowy nagłówek $sfx"),
       $h2 === null ? null : ['marker' => str_contains($h2, 'hero: ukryte'), 'h1' => substr_count($h2, '<h1 class="sek-h1">'), 'natif' => preg_match('/<section class="hero( hero--(foto|art|slajdy))?"[ >]/', $h2), 'tytul' => str_contains($h2, "Nowy nagłówek $sfx")]);
    ok('… avec sa photo en fond, centré', preg_match('/sek--hero sek--srodek[^>]*style="--hero-foto:url\([^)]*bbbbbbbbbbbbbbbbbbbbbbbb\.webp\)"/', (string) $h2) === 1);
    wsm_layout_toggle($pdo, 'home', 'hero', 'test');
    $h3 = $get('/');
    ok('nagłówek natif de retour : le Kreator repasse en h2', $h3 !== null && preg_match('/<section class="hero( hero--(foto|art|slajdy))?"[ >]/', $h3) === 1 && !str_contains($h3, '<h1 class="sek-h1">') && str_contains($h3, '<h2 class="sek-h1">'));
}

// ---- 8. Suppression en cascade -----------------------------------------------------------------
echo "\n-- usuwanie: strona zabiera swoje sekcje, sekcja oddaje obraz --\n";
wsm_page_delete($pdo, (int) $pg);
ok('la page supprimée emporte ses sections', wsm_section_get($pdo, (int) $idT) === null && wsm_section_get($pdo, (int) $idQ) === null && wsm_section_get($pdo, (int) $idH) === null);
$d = wsm_section_delete($pdo, (int) $idF);
ok('supprimer une section rend sa ligne (pour ranger son image)', $d && $d['image_url'] === 'media/bbbbbbbbbbbbbbbbbbbbbbbb.webp');
ok('… et l\'accueil l\'oublie', !in_array("sekcja:$idF", $cles(), true), $cles());

// ---- 9. Les pièces dans le dépôt ---------------------------------------------------------------
echo "\n-- kod: kreator w szynie, sklep renderuje, schematy mają tabele --\n";
$lib = $lire('generique/shop/lib.php');
ok('la vitrine charge sections.php sans en dépendre', str_contains($lib, "is_file(\$WSM_API_DIR . '/sections.php')") && str_contains($lib, 'function sekcja_html(') && str_contains($lib, "function_exists('wsm_section_views')"));
ok('l\'accueil rend les sections de son Układ', str_contains($lire('generique/shop/index.php'), "sekcja_id_html(\$pdo, \$lang, (int) \$it['id']"));
ok('une page rend ses sections sous son corps', str_contains($lire('generique/shop/index.php'), "sekcje_html(\$pdo, \$lang, (int) \$pg['id'])"));
ok('le pasek ogłoszeń est imprimé par layout.php, avant <header>', preg_match('/sekcje_html\(wsm_pdo\(\), \$lang, .*?\);.*?<header class="site-head">/s', $lire('generique/shop/layout.php')) === 1);
$css = $lire('generique/shop/shop.css');
ok('shop.css habille les types', !array_filter(['sek--hero', 'prose--2kol', 'galeria--3', 'kafelki--4', 'sek--baner', 'faq-q', 'cytat', 'sek--odstep', 'ogloszenie'], fn($c) => !str_contains($css, '.' . $c)));
$bud = $lire('generique/backoffice/budowa.php');
ok('budowa.php : CSRF, rôle, audit, trois cibles', str_contains($bud, 'console_csrf_ok()') && str_contains($bud, '$isAdmin') && str_contains($bud, 'wsm_audit(') && str_contains($bud, 'cel=site') && str_contains($bud, 'cel=home'));
ok('… dans le rail de la console, entre Układ et Strony', preg_match("/'uklad\.php'.*'budowa\.php'\s*=>\s*'Kreator strony'.*'strony\.php'/s", $lire('generique/backoffice/console.php')) === 1);
ok('Układ et Strony mènent au Kreator', str_contains($lire('generique/backoffice/uklad.php'), 'budowa.php') && str_contains($lire('generique/backoffice/strony.php'), 'budowa.php?cel='));
foreach (['mysql', 'sqlite'] as $m) {
    $sch = $lire("generique/backoffice/php-api/schema/webshop_mrszoko.$m.sql");
    ok("le schéma $m a les deux tables", preg_match('/CREATE TABLE IF NOT EXISTS `?wsm_sections`?\s*\(/', $sch) === 1 && preg_match('/CREATE TABLE IF NOT EXISTS `?wsm_section_i18n`?\s*\(/', $sch) === 1);
}
ok('db.php les crée au premier appel', preg_match("/'wsm_sections',\s*'wsm_section_i18n'/", $lire('generique/backoffice/php-api/db.php')) === 1);

// ---- nettoyage ------------------------------------------------------------------------------------
foreach ($pdo->query("SELECT id FROM wsm_sections WHERE updated_by = 'test'")->fetchAll() ?: [] as $r) wsm_section_delete($pdo, (int) $r['id']);
if ($blok) wsm_page_delete($pdo, (int) $blok);
wsm_layout_reset($pdo, 'home');

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
