<?php
// ============================================================================
//  e2e_slajdy.php — les slajdy du haut de la page d'accueil.
//
//  L'équipe : « 4-5 zdjęć, które co jakiś czas się zmieniają, np. co 5
//  sekund », la main sur le défilement, et des NOUVELLES datées (« od … do
//  25 grudnia rabaty świąteczne »). Ce qui est démontré :
//
//   1. LE PREMIER SLAJD est posé une fois — le boulanger et « Pewne
//      składniki. Udane wypieki. » — et ne revient pas s'il est supprimé.
//   2. LES DATES : « 31 listopada » est refusée avec son motif, une fin avant
//      le début aussi ; une date absente est NULL (jamais '', que MySQL strict
//      refuse) ; « dziś » est la date de Varsovie.
//   3. CE QUI EST EN LIGNE : selon la date, la case « widoczny », la langue
//      (repli sur le polonais).
//   4. LES LIENS : pas de « javascript: », pas de « //ailleurs ».
//   5. LE DÉFILEMENT se règle (marche/arrêt, 3–30 s) et la page le reçoit.
//   6. LA PAGE SERVIE : un seul h1, le slajd programmé absent, et sans slajd
//      en ligne le haut de page d'origine revient.
//   7. La console : droits, jeton, trace d'audit ; la médiathèque sait où
//      sert la photo.
//
//  Tout ce que le test crée est retiré à la fin ; les slajdy d'avant reviennent.
//
//  Usage :  php tests/e2e_slajdy.php      (boutique attendue sur :8091)
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
$pdo = wsm_bootstrap();
require_once dirname(__DIR__) . '/slajdy.php';
require_once dirname(__DIR__) . '/media.php';
echo "webshop_mrszoko — end-to-end slajdy\n\n";

$root = dirname(__DIR__, 4);
$lire = fn(string $rel) => (string) @file_get_contents($root . '/' . $rel);
$sfx = bin2hex(random_bytes(3));

// La base telle qu'on l'a trouvée : remise en place à la fin.
$avantSlajdy = $pdo->query("SELECT * FROM wsm_slides")->fetchAll();
$avantI18n   = $pdo->query("SELECT * FROM wsm_slide_i18n")->fetchAll();
$avantUst    = $pdo->query("SELECT * FROM wsm_settings WHERE cle IN ('slajdy.auto', 'slajdy.co', 'slajdy.zalozone')")->fetchAll();
$remettre = function () use ($pdo, $avantSlajdy, $avantI18n, $avantUst): void {
    $pdo->exec("DELETE FROM wsm_slide_i18n");
    $pdo->exec("DELETE FROM wsm_slides");
    foreach ($avantSlajdy as $r) {
        $pdo->prepare("INSERT INTO wsm_slides (id, sort_order, active, image_url, link, date_from, date_to, created_at, updated_at, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$r['id'], $r['sort_order'], $r['active'], $r['image_url'], $r['link'], $r['date_from'], $r['date_to'], $r['created_at'], $r['updated_at'], $r['updated_by']]);
    }
    foreach ($avantI18n as $r) {
        $pdo->prepare("INSERT INTO wsm_slide_i18n (slide_id, lang, eyebrow, title, tekst, cta_label) VALUES (?,?,?,?,?,?)")
            ->execute([$r['slide_id'], $r['lang'], $r['eyebrow'], $r['title'], $r['tekst'], $r['cta_label']]);
    }
    $pdo->exec("DELETE FROM wsm_settings WHERE cle IN ('slajdy.auto', 'slajdy.co', 'slajdy.zalozone')");
    foreach ($avantUst as $r) {
        $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,?,?,?)")
            ->execute([$r['cle'], $r['val'], $r['secret'], $r['updated_at'], $r['updated_by']]);
    }
};

// ---- 1. Le premier slajd ---------------------------------------------------------------
echo "-- pierwszy slajd: raz, z pliku treści --\n";
ok('les deux tables existent', wsm_table_exists($pdo, 'wsm_slides') && wsm_table_exists($pdo, 'wsm_slide_i18n'));
$seed = json_decode($lire('generique/shop/content_seed.json'), true);
ok('le fichier de contenu porte les mots de la maquette', ($seed['strings']['pl']['home.title'] ?? '') === 'Pewne składniki. Udane wypieki.'
   && ($seed['strings']['pl']['home.lead'] ?? '') === 'Zaopatrz piekarnię lub cukiernię w produkty do codziennej pracy.'
   && ($seed['strings']['pl']['home.cta'] ?? '') === 'Zobacz produkty' && ($seed['strings']['pl']['home.eyebrow'] ?? '') === 'Manufaktura · małe partie');
$pdo->exec("DELETE FROM wsm_slide_i18n"); $pdo->exec("DELETE FROM wsm_slides");
$pdo->exec("DELETE FROM wsm_settings WHERE cle = 'slajdy.zalozone'");
wsm_slajdy_ensure($pdo);
$l = wsm_slajdy_lista($pdo);
ok('sur une base neuve : un slajd, la photo du boulanger', count($l) === 1 && $l[0]['image_url'] === 'assets/hero-piekarz.webp' && $l[0]['active'] === 1 && $l[0]['link'] === '#katalog', $l);
ok('… ses textes dans les trois langues, ceux du fichier', ($l[0]['teksty']['pl']['title'] ?? '') === $seed['strings']['pl']['home.title']
   && ($l[0]['teksty']['uk']['title'] ?? '') === $seed['strings']['uk']['home.title'] && ($l[0]['teksty']['en']['cta_label'] ?? '') === $seed['strings']['en']['home.cta']);
ok('… sans période : dates NULL, pas \'\'', $pdo->query("SELECT COUNT(*) FROM wsm_slides WHERE date_from IS NULL AND date_to IS NULL")->fetchColumn() == 1);
ok('la photo est livrée avec la boutique', is_file($root . '/generique/shop/assets/hero-piekarz.webp') && @getimagesize($root . '/generique/shop/assets/hero-piekarz.webp')[0] >= 1000);
wsm_slajd_usun($pdo, $l[0]['id']);
wsm_slajdy_ensure($pdo);
ok('supprimé, il ne revient pas (marqueur slajdy.zalozone)', count(wsm_slajdy_lista($pdo)) === 0);

// ---- 2. Les dates ---------------------------------------------------------------------
echo "\n-- daty --\n";
ok('« 31 listopada » n\'existe pas', wsm_slajd_data('2026-11-31') === null && wsm_slajd_data('2026-02-30') === null && wsm_slajd_data('31.11.2026') === null);
ok('une vraie date passe, une date vide reste vide', wsm_slajd_data('2026-12-25') === '2026-12-25' && wsm_slajd_data(' ') === '' && wsm_slajd_data('2028-02-29') === '2028-02-29');
$t = ['pl' => ['title' => "Rabaty świąteczne $sfx", 'eyebrow' => 'Nowość', 'tekst' => "Do −15 %\nna czekolady", 'cta_label' => 'Zobacz']];
[, $e] = wsm_slajd_zapisz($pdo, ['image_url' => 'media/aaaaaaaaaaaaaaaaaaaaaaaa.webp', 'date_from' => '2026-11-31', 'date_to' => '2026-12-25', 'active' => 1, 'teksty' => $t], 0, 'test');
ok('enregistrer « 31 listopada » : refusé, avec le motif', ($e['date_from'] ?? '') === 'Taka data nie istnieje w kalendarzu.', $e);
[, $e] = wsm_slajd_zapisz($pdo, ['image_url' => 'media/aaaaaaaaaaaaaaaaaaaaaaaa.webp', 'date_from' => '2026-12-25', 'date_to' => '2026-12-01', 'active' => 1, 'teksty' => $t], 0, 'test');
ok('une fin avant le début : refusée', ($e['date_to'] ?? '') === 'Koniec nie może być przed początkiem.', $e);
$src = $lire('generique/backoffice/php-api/slajdy.php');
ok('« dziś » est la date de Varsovie, pas celle du serveur (UTC)', str_contains($src, "new DateTimeZone('Europe/Warsaw')")
   && wsm_slajdy_dzis() === (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d'));

// ---- 3. Les liens et les textes ------------------------------------------------------
echo "\n-- linki i teksty --\n";
foreach (['javascript:alert(1)', 'JaVaScRiPt:x', 'data:text/html,x', '//zly.example', 'a b', '"onmouseover="x'] as $zly) {
    [, $e] = wsm_slajd_zapisz($pdo, ['image_url' => '', 'link' => $zly, 'active' => 1, 'teksty' => $t], 0, 'test');
    ok("lien refusé : $zly", isset($e['link']), $e);
}
foreach (['#katalog', 'p/czekolada-ciemna', 'kontakt', '/mrszoko/shop/zwroty', 'https://misterszoko.com/', ''] as $dobry) {
    ok("lien accepté : " . ($dobry === '' ? '(vide)' : $dobry), wsm_slajd_link_ok($dobry));
}
[, $e] = wsm_slajd_zapisz($pdo, ['image_url' => 'javascript:x', 'active' => 1, 'teksty' => $t], 0, 'test');
ok('photo : pas d\'autre schéma que http(s)', isset($e['image_url']), $e);
[, $e] = wsm_slajd_zapisz($pdo, ['image_url' => '', 'active' => 1, 'teksty' => ['pl' => ['title' => '']]], 0, 'test');
ok('ni photo ni titre : refusé', ($e['image_url'] ?? '') === 'Slajd potrzebuje zdjęcia albo tytułu.', $e);
[, $e] = wsm_slajd_zapisz($pdo, ['image_url' => 'media/aaaaaaaaaaaaaaaaaaaaaaaa.webp', 'active' => 1, 'teksty' => ['pl' => ['title' => str_repeat('ą', 201)]]], 0, 'test');
ok('un titre plus long que la colonne : refusé (et pas tronqué en silence)', isset($e['pl.title']), $e);

// ---- 4. Ce qui est en ligne -----------------------------------------------------------
echo "\n-- co jest dziś na stronie --\n";
$pdo->exec("DELETE FROM wsm_settings WHERE cle = 'slajdy.zalozone'");
wsm_slajdy_ensure($pdo);                                     // le boulanger revient en tête
[$idTeraz, $e1] = wsm_slajd_zapisz($pdo, ['image_url' => 'media/aaaaaaaaaaaaaaaaaaaaaaaa.webp', 'link' => 'kontakt', 'date_from' => '2026-12-01', 'date_to' => '2026-12-25', 'active' => 1,
    'teksty' => $t + ['en' => ['title' => "Christmas $sfx"]]], 0, 'test');
[$idBez, $e2] = wsm_slajd_zapisz($pdo, ['image_url' => 'media/bbbbbbbbbbbbbbbbbbbbbbbb.webp', 'link' => '', 'date_from' => '', 'date_to' => '', 'active' => 0,
    'teksty' => ['pl' => ['title' => "Ukryty $sfx"]]], 0, 'test');
ok('deux slajdy enregistrés', !$e1 && !$e2 && $idTeraz > 0 && $idBez > 0, [$e1, $e2]);
$r = $pdo->query("SELECT date_from, date_to FROM wsm_slides WHERE id = " . (int) $idBez)->fetch();
ok('sans dates : NULL en base', $r['date_from'] === null && $r['date_to'] === null, $r);
$ids = fn(array $w) => array_map(fn($s) => $s['id'], $w);
ok('le 30 novembre : la nouvelle de décembre n\'est pas encore là', !in_array($idTeraz, $ids(wsm_slajdy_widoczne($pdo, 'pl', '2026-11-30')), true));
ok('le 1er et le 25 décembre : elle y est (bornes comprises)', in_array($idTeraz, $ids(wsm_slajdy_widoczne($pdo, 'pl', '2026-12-01')), true)
   && in_array($idTeraz, $ids(wsm_slajdy_widoczne($pdo, 'pl', '2026-12-25')), true));
ok('le 26 décembre : partie toute seule', !in_array($idTeraz, $ids(wsm_slajdy_widoczne($pdo, 'pl', '2026-12-26')), true));
ok('décoché « widoczny » : jamais en ligne', !in_array($idBez, $ids(wsm_slajdy_widoczne($pdo, 'pl', '2026-12-10')), true));
$listaS = wsm_slajdy_lista($pdo); $parId = []; foreach ($listaS as $s) $parId[$s['id']] = $s;
ok('l\'état lisible : plan / teraz / koniec / ukryty', wsm_slajd_stan($parId[$idTeraz], '2026-11-30') === 'plan' && wsm_slajd_stan($parId[$idTeraz], '2026-12-24') === 'teraz'
   && wsm_slajd_stan($parId[$idTeraz], '2027-01-01') === 'koniec' && wsm_slajd_stan($parId[$idBez], '2026-12-10') === 'ukryty');
$en = array_values(array_filter(wsm_slajdy_widoczne($pdo, 'en', '2026-12-10'), fn($s) => $s['id'] === $idTeraz))[0] ?? [];
ok('en anglais : le titre anglais, et le reste repris du polonais', ($en['title'] ?? '') === "Christmas $sfx" && ($en['cta'] ?? '') === 'Zobacz' && ($en['eyebrow'] ?? '') === 'Nowość', $en);
$w = wsm_slajdy_widoczne($pdo, 'pl', '2026-12-10');
ok('l\'ordre : le boulanger d\'abord, la nouvelle ensuite', ($w[0]['image'] ?? '') === 'assets/hero-piekarz.webp' && ($w[1]['id'] ?? 0) === $idTeraz, $ids($w));
ok('monter la nouvelle : elle passe en tête', wsm_slajd_przesun($pdo, $idTeraz, 'up') && wsm_slajdy_widoczne($pdo, 'pl', '2026-12-10')[0]['id'] === $idTeraz);
ok('… et redescendre', wsm_slajd_przesun($pdo, $idTeraz, 'down') && wsm_slajdy_widoczne($pdo, 'pl', '2026-12-10')[1]['id'] === $idTeraz);
ok('le premier ne monte pas plus haut', !wsm_slajd_przesun($pdo, $ids(wsm_slajdy_lista($pdo))[0], 'up'));
ok('montrer / cacher', wsm_slajd_przelacz($pdo, $idBez, 'test') && in_array($idBez, $ids(wsm_slajdy_widoczne($pdo, 'pl', '2026-12-10')), true)
   && wsm_slajd_przelacz($pdo, $idBez, 'test') && !in_array($idBez, $ids(wsm_slajdy_widoczne($pdo, 'pl', '2026-12-10')), true));

// ---- 5. Le défilement -----------------------------------------------------------------
echo "\n-- przewijanie --\n";
ok('par défaut : automatique, toutes les 5 secondes', (function () use ($pdo) {
    $pdo->exec("DELETE FROM wsm_settings WHERE cle IN ('slajdy.auto', 'slajdy.co')");
    return wsm_slajdy_ustawienia($pdo) === ['auto' => true, 'co' => 5];
})());
ok('2 secondes : refusé (trop vite pour lire)', wsm_slajdy_ustawienia_zapisz($pdo, true, 2, 'test') !== '' && wsm_slajdy_ustawienia($pdo)['co'] === 5);
ok('arrêté, 7 secondes : enregistré', wsm_slajdy_ustawienia_zapisz($pdo, false, 7, 'test') === '' && wsm_slajdy_ustawienia($pdo) === ['auto' => false, 'co' => 7]);
ok('enregistré deux fois : une seule ligne par réglage', wsm_slajdy_ustawienia_zapisz($pdo, true, 4, 'test') === ''
   && (int) $pdo->query("SELECT COUNT(*) FROM wsm_settings WHERE cle = 'slajdy.co'")->fetchColumn() === 1 && wsm_slajdy_ustawienia($pdo) === ['auto' => true, 'co' => 4]);

// ---- 6. La page servie ----------------------------------------------------------------
echo "\n-- strona główna (8091) --\n";
$ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true, 'header' => "Accept-Language: pl\r\n"]]);
$get = fn(string $p) => @file_get_contents('http://localhost:8091' . $p, false, $ctx);
// Une nouvelle en ligne AUJOURD'HUI, une autre programmée l'an prochain.
$dzis = wsm_slajdy_dzis();
[$idDzis] = wsm_slajd_zapisz($pdo, ['image_url' => 'media/aaaaaaaaaaaaaaaaaaaaaaaa.webp', 'link' => 'kontakt', 'date_from' => $dzis, 'date_to' => $dzis, 'active' => 1,
    'teksty' => ['pl' => ['title' => "Dziś <b>$sfx</b>", 'cta_label' => 'Napisz']]], 0, 'test');
$rok = (string) ((int) substr($dzis, 0, 4) + 1);
[$idPlan] = wsm_slajd_zapisz($pdo, ['image_url' => '', 'link' => '', 'date_from' => "$rok-12-01", 'date_to' => "$rok-12-24", 'active' => 1,
    'teksty' => ['pl' => ['title' => "Za rok $sfx"]]], 0, 'test');
$h = $get('/?lang=pl&t=' . microtime(true));
if ($h === false) { echo "  (sklep na 8091 nie odpowiada — pominięte)\n"; }
else {
    ok('le haut de page est un carrousel', str_contains($h, '<section class="hero hero--slajdy"') && str_contains($h, 'aria-roledescription="carousel"'));
    ok('le premier slajd : la photo du boulanger, chargée en priorité', (bool) preg_match('#<img class="slajd-foto" src="[^"]*assets/hero-piekarz\.webp" alt="" fetchpriority="high"#', $h));
    ok('… et le seul h1 de la page, « Pewne składniki. Udane wypieki. »', substr_count($h, '<h1') === 1 && str_contains($h, '<h1 class="slajd-h">Pewne składniki. Udane wypieki.</h1>'));
    ok('la nouvelle du jour : un h2, son texte échappé, son lien', str_contains($h, '<h2 class="slajd-h">Dziś &lt;b&gt;' . $sfx . '&lt;/b&gt;</h2>') && (bool) preg_match('#href="[^"]*/kontakt">Napisz</a>#', $h));
    ok('les suivants restent cachés sans JavaScript', (bool) preg_match('#data-slajd hidden>#', $h));
    ok('la programmée n\'est pas dans la page', !str_contains($h, "Za rok $sfx"));
    ok('le réglage arrive dans la page : auto, 4 s', str_contains($h, 'data-auto="1" data-co="4000"'));
    ok('les points, les flèches et la pause (cachés jusqu\'au script)', str_contains($h, 'data-slajdy-nav hidden') && substr_count($h, 'data-slajdy-idz=') >= 2
       && str_contains($h, 'data-slajdy-pauza') && str_contains($h, 'aria-label="Następny slajd"'));
    wsm_slajdy_ustawienia_zapisz($pdo, false, 4, 'test');
    $h2 = (string) $get('/?lang=pl&t=' . microtime(true));
    ok('arrêté dans la console : data-auto="0", pas de bouton pause', str_contains($h2, 'data-auto="0"') && !str_contains($h2, 'data-slajdy-pauza'));
    // Aucun slajd en ligne : le haut de page d'origine.
    $pdo->exec("UPDATE wsm_slides SET active = 0");
    $h3 = (string) $get('/?lang=pl&t=' . microtime(true));
    $tytul = (string) $pdo->query("SELECT v FROM wsm_shop_i18n WHERE k = 'home.title' AND lang = 'pl'")->fetchColumn();
    ok('sans slajd en ligne : le haut de page d\'origine et son h1 (Treści)', !str_contains($h3, 'hero--slajdy') && (bool) preg_match('#<section class="hero hero--(art|foto)"#', $h3)
       && str_contains($h3, '<h1>' . htmlspecialchars($tytul) . '</h1>'));
    ok('la photo et les styles sont servis', str_contains($lire('generique/shop/shop.css'), '.slajdy.is-zywe .slajd.is-on') && str_contains($lire('generique/shop/shop.js'), "querySelectorAll('[data-slajdy]')"));
}

// ---- 7. La console et la médiathèque --------------------------------------------------
echo "\n-- konsola --\n";
$bo = $lire('generique/backoffice/slajdy.php');
ok('l\'écran est dans le rail, sous Oferta', str_contains($lire('generique/backoffice/console.php'), "'slajdy.php'   => 'Slajdy'"));
ok('seule Centrala change ; le jeton du formulaire est exigé', str_contains($bo, "if (!console_csrf_ok())") && str_contains($bo, "if (!\$isAdmin) {"));
ok('chaque changement laisse une trace d\'audit', substr_count($bo, "wsm_audit(\$pdo, \$qui, 'Slajdy'") >= 5);
ok('les champs de date sont de vraies dates (calendrier du navigateur)', substr_count($bo, 'type="date"') === 2);
ok('la suppression demande « tak, usuń »', str_contains($bo, "empty(\$_POST['na_pewno'])"));
ok('Układ strony mène aux slajdy', str_contains($lire('generique/backoffice/uklad.php'), 'href="slajdy.php"'));
$us = wsm_media_usages($pdo);
ok('Media sait que la photo sert à un slajd', in_array('slajd: Dziś <b>' . $sfx . '</b>', $us['media/aaaaaaaaaaaaaaaaaaaaaaaa.webp'] ?? [], true), $us['media/aaaaaaaaaaaaaaaaaaaaaaaa.webp'] ?? null);
$mig = wsm_content_jednorazowe_test();
ok('migration à un seul coup : les textes de la maquette dans les trois langues', $mig);

// ---- Remise en place ------------------------------------------------------------------
$remettre();

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);

/** La migration « migracja.2026_10_hero_piekarz » existe et pose les trois langues. */
function wsm_content_jednorazowe_test(): bool {
    require_once dirname(__DIR__) . '/seed.php';
    $ops = wsm_content_jednorazowe()['migracja.2026_10_hero_piekarz'] ?? [];
    $vu = [];
    foreach ($ops as [$sql, $args]) if (str_starts_with($sql, 'UPDATE wsm_shop_i18n SET v = ?')) $vu[$args[2] . ':' . $args[1]] = $args[0];
    return count($ops) === 12 && ($vu['pl:home.title'] ?? '') === 'Pewne składniki. Udane wypieki.'
        && ($vu['pl:home.cta'] ?? '') === 'Zobacz produkty' && isset($vu['uk:home.lead'], $vu['en:home.eyebrow']);
}
