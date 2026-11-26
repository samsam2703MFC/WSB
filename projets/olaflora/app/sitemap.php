<?php
// Public : sitemap.xml (à déclarer dans la Search Console) — chaque page publique dans
// chaque langue, avec ses versions sœurs (hreflang).
declare(strict_types=1);
require __DIR__ . '/ustawienia_lib.php';
try { $seo = of_seo(of_pdo()); } catch (Throwable $e) { $seo = of_seo_domyslne(); }
$adres = of_adres($seo);
$root = of_site_root();
$x = fn($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">', "\n";
if ($seo['indeksuj']) foreach (OF_SEO_STRONY as $k => $p) {
    $mod = $root !== '' && is_file($root . '/' . $p['plik']) ? date('Y-m-d', (int) filemtime($root . '/' . $p['plik'])) : date('Y-m-d');
    foreach (OF_JEZYKI as $j) {
        echo "  <url>\n    <loc>", $x(of_url_strony($adres, $k, $j)), "</loc>\n    <lastmod>$mod</lastmod>\n";
        foreach (OF_JEZYKI as $jj) echo '    <xhtml:link rel="alternate" hreflang="', $jj, '" href="', $x(of_url_strony($adres, $k, $jj)), '"/>', "\n";
        echo "    <priority>", $k === 'home' ? '1.0' : '0.6', "</priority>\n  </url>\n";
    }
}
echo "</urlset>\n";
