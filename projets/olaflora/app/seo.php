<?php
// Public : sert une page du site AVEC ses balises de référencement dans le <head> —
// titre, description, adresse canonique, versions PL/EN/DE (hreflang), aperçu de partage
// (Open Graph : Facebook, Instagram, WhatsApp ne lisent PAS le JavaScript), la fiche de la
// pracownia pour Google (JSON-LD « Florist ») et la vérification de la Search Console.
// Apache y envoie l'accueil et les pages publiques (wsm, règles du projet) ; la page
// elle-même ne change pas d'un caractère en dehors du <head>.
declare(strict_types=1);
require __DIR__ . '/ustawienia_lib.php';
$klucz = (string) ($_GET['f'] ?? 'home');
if ($klucz === 'index' || $klucz === '' || $klucz === 'wesela v2') $klucz = 'home';
if ($klucz === 'oferta live') $klucz = 'oferta';
if (!isset(OF_SEO_STRONY[$klucz])) { http_response_code(404); exit; }
$root = of_site_root();
$plik = $root . '/' . OF_SEO_STRONY[$klucz]['plik'];
if ($root === '' || !is_file($plik)) { http_response_code(404); exit; }
$html = (string) file_get_contents($plik);

$jezyk = (string) ($_GET['lang'] ?? '');
if (!in_array($jezyk, OF_JEZYKI, true)) $jezyk = in_array($_COOKIE['olaflora_lang'] ?? '', OF_JEZYKI, true) ? (string) $_COOKIE['olaflora_lang'] : 'pl';

try { $pdo = of_pdo(); $seo = of_seo($pdo); $g = of_int($pdo, 'google')['config']; $ig = of_int($pdo, 'instagram')['config']; $gp = of_google_publiczne($pdo); }
catch (Throwable $e) { $seo = of_seo_domyslne(); $g = []; $ig = []; $gp = ['ga' => '', 'ads' => '', 'adsLabel' => '']; }

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$s = $seo['strony'][$klucz][$jezyk] ?? [];
$pl = $seo['strony'][$klucz]['pl'] ?? [];
$tytul = trim((string) ($s['title'] ?? '')) ?: trim((string) ($pl['title'] ?? '')) ?: 'Ola flora';
$opis = trim((string) ($s['description'] ?? '')) ?: trim((string) ($pl['description'] ?? ''));
$adres = of_adres($seo);
$url = of_url_strony($adres, $klucz, $jezyk);
$og = trim((string) ($s['og'] ?? '')) ?: trim((string) ($pl['og'] ?? '')) ?: 'assets/photos/ola/kalie-jaskry-limonki.jpg';
if (!preg_match('~^https?://~', $og)) $og = $adres . str_replace('%2F', '/', rawurlencode(ltrim($og, '/')));
$locale = ['pl' => 'pl_PL', 'en' => 'en_GB', 'de' => 'de_DE'];

$t = [];
$t[] = '<title>' . $h($tytul) . '</title>';
if ($opis !== '') $t[] = '<meta name="description" content="' . $h($opis) . '">';
$t[] = '<meta name="robots" content="' . ($seo['indeksuj'] ? 'index, follow, max-image-preview:large' : 'noindex, nofollow') . '">';
$t[] = '<link rel="canonical" href="' . $h($url) . '">';
foreach (OF_JEZYKI as $j) $t[] = '<link rel="alternate" hreflang="' . $j . '" href="' . $h(of_url_strony($adres, $klucz, $j)) . '">';
$t[] = '<link rel="alternate" hreflang="x-default" href="' . $h(of_url_strony($adres, $klucz, 'pl')) . '">';
$t[] = '<meta property="og:type" content="website"><meta property="og:site_name" content="' . $h($seo['firma']['nazwa'] ?: 'Ola flora') . '">';
$t[] = '<meta property="og:title" content="' . $h($tytul) . '"><meta property="og:url" content="' . $h($url) . '">';
if ($opis !== '') $t[] = '<meta property="og:description" content="' . $h($opis) . '">';
$t[] = '<meta property="og:image" content="' . $h($og) . '"><meta property="og:locale" content="' . $locale[$jezyk] . '">';
$t[] = '<meta name="twitter:card" content="summary_large_image">';
if (preg_match('~^[A-Za-z0-9_\-]{10,100}$~', (string) ($g['gsc'] ?? ''))) $t[] = '<meta name="google-site-verification" content="' . $h($g['gsc']) . '">';

// La fiche de la pracownia : un fleuriste, avec ce qui est renseigné — rien d'inventé.
$f = $seo['firma'];
$ld = ['@context' => 'https://schema.org', '@type' => 'Florist', 'name' => $f['nazwa'] ?: 'Ola flora', 'url' => $adres, 'image' => $og];
if ($f['opis'] !== '') $ld['description'] = $f['opis'];
if ($f['logo'] !== '') $ld['logo'] = preg_match('~^https?://~', $f['logo']) ? $f['logo'] : $adres . str_replace('%2F', '/', rawurlencode($f['logo']));
if ($f['telefon'] !== '') $ld['telephone'] = $f['telefon'];
if ($f['email'] !== '') $ld['email'] = $f['email'];
if ($f['ceny'] !== '') $ld['priceRange'] = $f['ceny'];
if ($f['miasto'] !== '') $ld['address'] = array_filter(['@type' => 'PostalAddress', 'streetAddress' => $f['ulica'], 'postalCode' => $f['kod'],
    'addressLocality' => $f['miasto'], 'addressRegion' => $f['region'], 'addressCountry' => $f['kraj'] ?: 'PL']);
if (is_numeric($f['lat']) && is_numeric($f['lng'])) $ld['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float) $f['lat'], 'longitude' => (float) $f['lng']];
if ($f['obszar'] !== '') $ld['areaServed'] = array_values(array_filter(array_map('trim', explode(',', $f['obszar']))));
if ($f['godziny'] !== '') $ld['openingHours'] = $f['godziny'];
$same = array_values(array_filter([(string) ($ig['profil'] ?? ''), (string) ($g['gbp'] ?? ''), (string) ($ig['facebook'] ?? '')], fn($u) => (bool) preg_match('~^https://~', $u)));
if ($same) $ld['sameAs'] = $same;
$t[] = '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';

// Google (Analytics, Ads) : seulement l'identifiant — adapt.js ne charge rien sans l'accord du visiteur.
// Il doit précéder adapt.js (en tête du <head>) ; le reste va à la fin du <head>, après le charset.
$google = "\n<script>window.__ofGoogle=" . json_encode($gp, JSON_HEX_TAG) . ";</script>";
$blok = "<!-- SEO : back office → Integracje i SEO -->\n" . implode("\n", $t) . "\n";
$html = preg_replace('~<title>.*?</title>~is', '', $html, 1) ?? $html;
$html = preg_replace('~<html(?![^>]*\blang=)([^>]*)>~i', '<html lang="' . $jezyk . '"$1>', $html, 1) ?? $html;
$html = preg_replace('~(<html[^>]*\blang=")[a-z-]+(")~i', '${1}' . $jezyk . '$2', $html, 1) ?? $html;
$html = preg_replace('~<head(\s[^>]*)?>~i', '$0' . str_replace('$', '\\$', $google), $html, 1) ?? $html;
$i = stripos($html, '</head>');
$html = $i === false ? $blok . $html : substr($html, 0, $i) . $blok . substr($html, $i);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header('Content-Language: ' . $jezyk);
echo $html;
