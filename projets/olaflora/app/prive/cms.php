<?php
// Privé (mot de passe du projet) : l'écran « strony » du back office —
// brouillon, publication, retour à la version d'origine, envoi d'une photo.
declare(strict_types=1);
require is_file(dirname(__DIR__) . '/lib.php') ? dirname(__DIR__) . '/lib.php' : dirname(__DIR__, 2) . '/app/lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { of_json(['error' => 'metoda'], 405); exit; }
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) { of_json(['error' => 'json'], 400); exit; }
$pdo = of_pdo();
$akcja = (string) ($in['akcja'] ?? '');

if ($akcja === 'wgraj') {
    if (!preg_match('~^data:image/(jpeg|png|webp);base64,(.+)$~s', (string) ($in['data'] ?? ''), $m)) { of_json(['error' => 'format'], 422); exit; }
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) < 100 || strlen($bin) > 8 * 1024 * 1024) { of_json(['error' => 'rozmiar'], 422); exit; }
    $info = @getimagesizefromstring($bin);
    if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) { of_json(['error' => 'to nie jest zdjęcie'], 422); exit; }
    $plik = preg_replace('~[^A-Za-z0-9._ -]+~', '', basename((string) ($in['nazwa'] ?? 'zdjecie.jpg'))) ?: 'zdjecie.jpg';
    $alt = trim(str_replace(['-', '_'], ' ', preg_replace('~\.[a-z0-9]+$~i', '', $plik)));
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO of_media (tenant_id, kind, path, filename, mime, bytes, width, height, alt, sha1) VALUES (?, 'photo', ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([OF_TENANT, 'wgrywane:' . bin2hex(random_bytes(8)), $plik, $info['mime'], strlen($bin), $info[0], $info[1], mb_substr($alt, 0, 190), sha1($bin)]);
    $id = (int) $pdo->lastInsertId();
    $src = '_wsb/app/zdjecie.php?id=' . $id;
    $pdo->prepare("UPDATE of_media SET path = ? WHERE id = ?")->execute([$src, $id]);
    $pdo->prepare("INSERT INTO of_media_blobs (media_id, data) VALUES (?, ?)")->execute([$id, $bin]);
    $pdo->commit();
    of_json(['ok' => true, 'src' => $src, 'alt' => $alt]);
    exit;
}

$k = (string) ($in['k'] ?? '');
$slug = (string) ($in['strona'] ?? '');
$rodzaj = (string) ($in['rodzaj'] ?? '');
if (!preg_match('~^[a-z]+:[a-z0-9.]{1,32}$~', $k) || strpos($k, $slug . ':') !== 0 || !isset(OF_CMS_STRONY[$slug]) || !in_array($rodzaj, OF_CMS_RODZAJE, true)) {
    of_json(['error' => 'sekcja'], 422); exit;
}
$dom = $in['domyslna'] ?? '';
$dom = is_array($dom) ? json_encode(array_values($dom), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $dom;
$wart = mb_substr((string) ($in['wartosc'] ?? ''), 0, 20000);
$zdj = array_values(array_filter(array_map('strval', is_array($in['zdjecia'] ?? null) ? $in['zdjecia'] : []), 'of_cms_src_ok'));
$foto = of_cms_foto($rodzaj);
$kto = (string) ($_SERVER['PHP_AUTH_USER'] ?? $_SERVER['REMOTE_USER'] ?? '');
$uid = null;
if ($kto !== '') {
    $u = $pdo->prepare("SELECT id FROM of_users WHERE tenant_id = ? AND (email = ? OR name = ?) LIMIT 1");
    $u->execute([OF_TENANT, $kto, $kto]);
    $uid = $u->fetchColumn() ?: null;
}

$pdo->beginTransaction();
$sek = of_cms_sekcja($pdo, $slug, $k, $rodzaj, (string) ($in['tytul'] ?? $k), $dom);
if ($akcja === 'szkic') {
    if ($foto) of_cms_zdjecia($pdo, $sek, 'draft', $zdj);
    else $pdo->prepare("UPDATE of_page_sections SET draft_value = ? WHERE id = ?")->execute([$wart, $sek]);
} elseif ($akcja === 'publikuj') {
    if ($foto) {
        if (!$zdj) { $pdo->rollBack(); of_json(['error' => 'brak zdjęć'], 422); exit; }
        of_cms_zdjecia($pdo, $sek, 'published', $zdj);
        $pdo->prepare("DELETE FROM of_page_section_photos WHERE section_id = ? AND state = 'draft'")->execute([$sek]);
        $pdo->prepare("UPDATE of_page_sections SET published_value = NULL, draft_value = NULL, published_at = NOW(), published_by_user_id = ? WHERE id = ?")->execute([$uid, $sek]);
    } else {
        if (trim($wart) === '') { $pdo->rollBack(); of_json(['error' => 'pusty tekst'], 422); exit; }
        if ($rodzaj === 'link' && !preg_match('~^(https?://|mailto:|tel:)\S+$~i', trim($wart))) {
            $pdo->rollBack(); of_json(['error' => 'adres musi zaczynać się od https://, mailto: lub tel:'], 422); exit;
        }
        $pdo->prepare("UPDATE of_page_sections SET published_value = ?, draft_value = NULL, published_at = NOW(), published_by_user_id = ? WHERE id = ?")->execute([$wart, $uid, $sek]);
    }
} elseif ($akcja === 'przywroc') {
    $pdo->prepare("DELETE FROM of_page_section_photos WHERE section_id = ?")->execute([$sek]);
    $pdo->prepare("UPDATE of_page_sections SET published_value = NULL, draft_value = NULL, published_at = NULL, published_by_user_id = NULL WHERE id = ?")->execute([$sek]);
} else {
    $pdo->rollBack(); of_json(['error' => 'akcja'], 422); exit;
}
$pdo->commit();
of_json(['ok' => true]);
