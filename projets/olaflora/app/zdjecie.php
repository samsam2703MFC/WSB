<?php
// Public : une photo envoyée depuis le CMS (gardée en base, of_media_blobs).
declare(strict_types=1);
require __DIR__ . '/lib.php';
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit; }
$st = of_pdo()->prepare("SELECT m.mime, b.data FROM of_media m JOIN of_media_blobs b ON b.media_id = m.id
                         WHERE m.id = ? AND m.tenant_id = ? AND m.kind = 'photo'");
$st->execute([$id, OF_TENANT]);
$r = $st->fetch();
if (!$r) { http_response_code(404); exit; }
header('Content-Type: ' . (in_array($r['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) ? $r['mime'] : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');   // une adresse = une photo, jamais remplacée
header('Content-Length: ' . strlen((string) $r['data']));
echo $r['data'];
