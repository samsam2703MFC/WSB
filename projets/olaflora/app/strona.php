<?php
// Public : ce que la page « Ola flora - wesela v2 » doit afficher à la place du
// texte d'origine — seulement les sections PUBLIÉES (jamais les brouillons).
declare(strict_types=1);
require __DIR__ . '/lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { of_json(['error' => 'metoda'], 405); exit; }
$pdo = of_pdo();
$t = []; $z = []; $alt = []; $kat = [];
$st = $pdo->prepare("SELECT s.skey, s.kind, s.published_value FROM of_page_sections s
                     JOIN of_pages p ON p.id = s.page_id JOIN of_sites w ON w.id = p.site_id
                     WHERE w.tenant_id = ? AND s.published_at IS NOT NULL AND s.skey LIKE '%:%'");
$st->execute([OF_TENANT]);
foreach ($st as $r) if (!of_cms_foto((string) $r['kind']) && $r['published_value'] !== null) $t[$r['skey']] = (string) $r['published_value'];
$z = of_cms_wszystkie_zdjecia($pdo, 'published');
if ($z) {
    $srcs = array_values(array_unique(array_merge(...array_values($z))));
    $in = implode(',', array_fill(0, count($srcs), '?'));
    $m = $pdo->prepare("SELECT path, alt, gallery_category FROM of_media WHERE tenant_id = ? AND path IN ($in)");
    $m->execute(array_merge([OF_TENANT], $srcs));
    foreach ($m as $r) {
        if ($r['alt'] !== null && $r['alt'] !== '') $alt[$r['path']] = $r['alt'];
        if ($r['gallery_category']) $kat[$r['path']] = $r['gallery_category'];
    }
}
header('Cache-Control: no-cache');
of_json(['t' => (object) $t, 'z' => (object) $z, 'alt' => (object) $alt, 'kat' => (object) $kat]);
