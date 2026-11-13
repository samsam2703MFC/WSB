<?php
// ============================================================================
//  e2e_media.php — la médiathèque : ce qui est déposé, et à quoi ça sert.
//
//   1. LA LISTE NE MONTRE QUE NOS FICHIERS : un nom qui n'a pas la forme que
//      nous produisons n'y entre pas, et ne se supprime pas d'ici.
//   2. LES USAGES SE LISENT DANS LA BASE, à l'affichage : produit, marque,
//      réglage, page (image ET image citée dans le corps), client.
//   3. UN FICHIER UTILISÉ NE SE SUPPRIME PAS depuis l'écran ; un orphelin, oui.
//
//  Usage :  php tests/e2e_media.php
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

echo "webshop_mrszoko — end-to-end medioteka\n\n";

$dir = wsm_media_dir();
if (!is_dir($dir)) mkdir($dir, 0775, true);
$sfx = bin2hex(random_bytes(3));

// Deux fichiers « à nous » (le nom que wsm_media_store produit) et un intrus.
$im = imagecreatetruecolor(8, 8);
$nomA = bin2hex(random_bytes(12)) . '.webp';
$nomB = bin2hex(random_bytes(12)) . '.png';
imagewebp($im, "$dir/$nomA"); imagepng($im, "$dir/$nomB");
file_put_contents("$dir/intruz-$sfx.txt", 'nie obraz');
imagedestroy($im);
$urlA = 'media/' . $nomA; $urlB = 'media/' . $nomB;

echo "-- lista: tylko nasze pliki --\n";
$liste = wsm_media_list();
$noms = array_column($liste, 'name');
ok('les deux fichiers sont listés', in_array($nomA, $noms, true) && in_array($nomB, $noms, true));
ok('l\'intrus n\'est pas listé', !in_array("intruz-$sfx.txt", $noms, true));
$a = array_values(array_filter($liste, fn($m) => $m['name'] === $nomA))[0] ?? null;
ok('avec sa taille et ses dimensions', ($a['w'] ?? 0) === 8 && ($a['h'] ?? 0) === 8 && ($a['bytes'] ?? 0) > 0, $a);
ok('et son adresse telle que la base la range', ($a['url'] ?? '') === $urlA);

echo "\n-- użycia: czytane z bazy --\n";
$e = [];
$pid = wsm_page_save($pdo, null, ['kind' => 'strona', 'slug' => "media-test-$sfx", 'image_url' => $urlA,
    't' => ['pl' => ['title' => "Test mediów $sfx", 'body' => "Obraz w treści: ![x]($urlB)"]]], 'test', $e);
ok('une page avec image et image citée', $pid !== null, $e);
$avantIk = (string) ($pdo->query("SELECT val FROM wsm_settings WHERE cle = 'promise_icon_3'")->fetchColumn() ?: '');
$pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'promise_icon_3'")->execute();
$pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,0,?,?)")
    ->execute(["promise_icon_3", $urlB, date('Y-m-d H:i:s'), 'test']);
$u = wsm_media_usages($pdo);
ok('l\'image de la page est vue comme utilisée par la page', in_array("strona: Test mediów $sfx", $u[$urlA] ?? [], true), $u[$urlA] ?? null);
ok('l\'image citée dans le corps aussi', in_array("w treści: Test mediów $sfx", $u[$urlB] ?? [], true), $u[$urlB] ?? null);
ok('… et par le réglage qui la porte', in_array('ustawienie: ikona obietnicy 3', $u[$urlB] ?? [], true), $u[$urlB] ?? null);
// Une photo produit et le logo de la boutique : deux usages que la médiathèque
// ne voyait pas (une colonne mal nommée, un réglage oublié) — et qu'elle
// proposait donc d'effacer.
$prod = $pdo->query("SELECT id, nom, image_url FROM wsm_products ORDER BY id LIMIT 1")->fetch() ?: null;
$avantLogo = (string) ($pdo->query("SELECT val FROM wsm_settings WHERE cle = 'logo_image'")->fetchColumn() ?: '');
if ($prod) $pdo->prepare("UPDATE wsm_products SET image_url = ? WHERE id = ?")->execute([$urlA, $prod['id']]);
$pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'logo_image'")->execute();
$pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,0,?,?)")
    ->execute(['logo_image', $urlB, date('Y-m-d H:i:s'), 'test']);
$u = wsm_media_usages($pdo);
ok('la photo d\'un produit est vue comme utilisée, sous le nom du produit',
   $prod && in_array('produkt: ' . $prod['nom'], $u[$urlA] ?? [], true), $u[$urlA] ?? null);
ok('… et le logo de la boutique aussi', in_array('ustawienie: logo sklepu', $u[$urlB] ?? [], true), $u[$urlB] ?? null);
if ($prod) $pdo->prepare("UPDATE wsm_products SET image_url = ? WHERE id = ?")->execute([(string) $prod['image_url'], $prod['id']]);
$pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'logo_image'")->execute();
if ($avantLogo !== '') $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,0,?,?)")
    ->execute(['logo_image', $avantLogo, date('Y-m-d H:i:s'), 'test']);
$pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'promise_icon_3'")->execute();
if ($avantIk !== '') $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,0,?,?)")
    ->execute(["promise_icon_3", $avantIk, date('Y-m-d H:i:s'), 'test']);

// ---- 3. Nazwy --------------------------------------------------------------------------
echo "\n-- nazwy: adres jest stały, nazwa jest dla ludzi --\n";
ok('un nom se tire du nom du fichier envoyé', wsm_media_title_from_filename('IMG_2031-czekolada_70.JPG') === 'IMG 2031 czekolada 70', wsm_media_title_from_filename('IMG_2031-czekolada_70.JPG'));
ok('… sans chemin, sans extension, borné à 120', wsm_media_title_from_filename('../../etc/passwd') === 'passwd' && mb_strlen(wsm_media_title_from_filename(str_repeat('ą', 300) . '.png')) === 120);
ok('… et vide reste vide', wsm_media_title_from_filename('.webp') === '' && wsm_media_title_from_filename('___') === '');
ok('un nom se pose et se relit', wsm_media_title_set($pdo, $urlA, "Logo firmy $sfx", 'test') && (wsm_media_titles($pdo)[$urlA] ?? '') === "Logo firmy $sfx", wsm_media_titles($pdo)[$urlA] ?? null);
ok('… se change, nettoyé (une ligne, sans caractère de contrôle)', wsm_media_title_set($pdo, $urlA, "  Logo\tfirmy\n v2 $sfx ", 'test') && (wsm_media_titles($pdo)[$urlA] ?? '') === "Logo firmy v2 $sfx", wsm_media_titles($pdo)[$urlA] ?? null);
$l = []; foreach (wsm_media_list($pdo) as $m) $l[$m['url']] = $m;
ok('la liste porte le nom et une étiquette', ($l[$urlA]['title'] ?? '') === "Logo firmy v2 $sfx" && ($l[$urlA]['label'] ?? '') === "Logo firmy v2 $sfx", $l[$urlA] ?? null);
ok('un fichier sans nom se présente par le début de son adresse', ($l[$urlB]['title'] ?? 'x') === '' && ($l[$urlB]['label'] ?? '') === 'plik ' . substr($nomB, 0, 8), $l[$urlB] ?? null);
ok('sans base, la liste vit sans nom', !array_filter(wsm_media_list(), fn($m) => $m['title'] !== '') && array_column(wsm_media_list(), 'label') !== []);
ok('un nom ne se pose que sur notre média', !wsm_media_title_set($pdo, 'media/../../api/config.php', 'x') && !wsm_media_title_set($pdo, 'https://x.test/a.png', 'x') && !isset(wsm_media_titles($pdo)['https://x.test/a.png']));
wsm_media_title_register($urlB, 'termo_izolacja-lato.png');
ok('l\'envoi enregistre le nom du fichier', (wsm_media_titles($pdo)[$urlB] ?? '') === 'termo izolacja lato', wsm_media_titles($pdo)[$urlB] ?? null);
ok('un nom vide efface le nom, pas le fichier', wsm_media_title_set($pdo, $urlB, '') && !isset(wsm_media_titles($pdo)[$urlB]) && is_file("$dir/$nomB"));
$ekran = (string) @file_get_contents(dirname(__DIR__, 2) . '/media.php');
ok('l\'écran Media : un champ Nazwa à l\'envoi, un renommage par fichier, une recherche', str_contains($ekran, 'name="nazwa"') && str_contains($ekran, 'name="nazwij"') && str_contains($ekran, 'name="szukaj"'));
$rac = dirname(__DIR__, 4);
ok('le Kreator et Strony montrent le nom, pas le hash', str_contains((string) @file_get_contents("$rac/generique/backoffice/budowa.php"), "h(\$m['label'])") && str_contains((string) @file_get_contents("$rac/generique/backoffice/strony.php"), "h(\$m['label'])"));
ok('les deux schémas et db.php connaissent wsm_media', str_contains((string) @file_get_contents("$rac/generique/backoffice/php-api/schema/webshop_mrszoko.mysql.sql"), '`wsm_media`') && str_contains((string) @file_get_contents("$rac/generique/backoffice/php-api/schema/webshop_mrszoko.sqlite.sql"), 'wsm_media (') && str_contains((string) @file_get_contents("$rac/generique/backoffice/php-api/db.php"), "'wsm_media'"));

echo "\n-- usuwanie: tylko sierota, tylko nasz plik --\n";
ok('un nom hors de notre forme ne se supprime pas d\'ici', !wsm_media_delete("media/intruz-$sfx.txt") && is_file("$dir/intruz-$sfx.txt"));
ok('un chemin tordu non plus', !wsm_media_delete('media/../../api/config.php'));
$scr = (string) @file_get_contents(dirname(__DIR__, 2) . '/media.php');
ok('l\'écran refuse de supprimer un fichier utilisé', str_contains($scr, 'isset(wsm_media_usages($pdo)[$url])'));
ok('… et demande une confirmation', str_contains($scr, 'na_pewno'));
ok('… et ne montre le bouton que sur un orphelin', str_contains($scr, '<?php else: ?>') && str_contains($scr, 'Usuń plik'));
wsm_page_delete($pdo, (int) $pid);
$u2 = wsm_media_usages($pdo);
ok('la page supprimée, les deux fichiers redeviennent orphelins', !isset($u2[$urlA]) && !isset($u2[$urlB]));
ok('un orphelin se supprime', wsm_media_delete($urlA) && !is_file("$dir/$nomA"));
ok('… et son nom part avec lui', !isset(wsm_media_titles($pdo)[$urlA]));
ok('… et disparaît de la liste', !in_array($nomA, array_column(wsm_media_list(), 'name'), true));

// ---- Nettoyage ------------------------------------------------------------------------
@unlink("$dir/$nomB"); @unlink("$dir/intruz-$sfx.txt");

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
