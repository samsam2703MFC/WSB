<?php
// Ola flora — accès à la base du projet et petits formats polonais partagés par
// les points d'entrée (zapytanie.php public, prive/dane.php).
declare(strict_types=1);
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

const OF_TENANT = 1;   // une seule pracownia pour l'instant ; la structure en accepte plusieurs

function of_pdo(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $f = (string) (getenv('PROJET_CONFIG') ?: ($_SERVER['PROJET_CONFIG'] ?? ''));
    $c = $f !== '' && is_file($f) ? (include $f) : null;
    if (!is_array($c)) { http_response_code(500); exit('{"error":"config"}'); }
    $pdo = new PDO((string) $c['dsn'], (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $pdo;
}

function of_json($v, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($v, JSON_UNESCAPED_UNICODE);
}

/** Le numéro suivant d'une série (ZAP-2026-104), sans doublon (FOR UPDATE). */
function of_numer(PDO $pdo, string $prefix): string {
    $rok = (int) date('Y');
    $pdo->prepare("INSERT IGNORE INTO of_number_sequences (tenant_id, prefix, year, last_no) VALUES (?,?,?,0)")->execute([OF_TENANT, $prefix, $rok]);
    $st = $pdo->prepare("SELECT id, last_no FROM of_number_sequences WHERE tenant_id = ? AND prefix = ? AND year = ? FOR UPDATE");
    $st->execute([OF_TENANT, $prefix, $rok]);
    $r = $st->fetch();
    $n = (int) $r['last_no'] + 1;
    $pdo->prepare("UPDATE of_number_sequences SET last_no = ? WHERE id = ?")->execute([$n, $r['id']]);
    return sprintf('%s-%d-%03d', $prefix, $rok, $n);
}

const OF_MIES = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
const OF_DNI  = ['niedz.', 'pon.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.'];

/** « sob. 13 czerwca 2026 » */
function of_data_pl(?string $iso): string {
    if (!$iso || !($t = strtotime($iso))) return '';
    return OF_DNI[(int) date('w', $t)] . ' ' . (int) date('j', $t) . ' ' . OF_MIES[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
}
/** « 24 180 zł » (grosze → złote, espace fine des milliers) */
function of_zl(?int $gr): string {
    return $gr === null ? '' : number_format(intdiv($gr, 100), 0, ',', ' ') . ' zł';
}
/** « 2 dni temu » */
function of_temu(?string $kiedy): string {
    if (!$kiedy || !($t = strtotime($kiedy))) return '';
    $d = (int) floor((time() - $t) / 86400);
    if ($d <= 0) return 'dziś';
    if ($d === 1) return 'wczoraj';
    return $d . ' dni temu';
}

/* ---------------------------------------------------------------------
   CMS de la page publique (of_sites → of_pages → of_page_sections
   → of_page_section_photos → of_media / of_media_blobs).
   La clé d'une section (skey) est celle que calcule adapt.js dans le gabarit
   de la page publique : « home:t1a2b3c4d », « portfolio:galeria »…
   --------------------------------------------------------------------- */

const OF_CMS_STRONY = ['wspolne' => ['wspólne · menu i stopka', 0], 'home' => ['strona główna', 1], 'oferta' => ['oferta', 2],
                       'portfolio' => ['realizacje', 3], 'omnie' => ['o mnie', 4], 'kontakt' => ['kontakt', 5]];
const OF_CMS_RODZAJE = ['tekst', 'link', 'lista', 'zdjęcie', 'galeria'];

function of_cms_foto(string $rodzaj): bool { return $rodzaj === 'zdjęcie' || $rodzaj === 'galeria'; }

/** La witryna de la pracownia (créée si la base n'en a pas). */
function of_cms_site(PDO $pdo): int {
    $st = $pdo->prepare("SELECT id FROM of_sites WHERE tenant_id = ? ORDER BY id LIMIT 1");
    $st->execute([OF_TENANT]);
    $id = (int) $st->fetchColumn();
    if ($id) return $id;
    $b = (int) $pdo->query("SELECT id FROM of_brands WHERE tenant_id = " . OF_TENANT . " ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO of_sites (tenant_id, brand_id, label, live) VALUES (?, ?, 'ola flora', 1)")->execute([OF_TENANT, $b]);
    return (int) $pdo->lastInsertId();
}

/** La page d'un onglet (créée au besoin ; « wspolne » = menu et pied de page). */
function of_cms_strona(PDO $pdo, string $slug): int {
    $site = of_cms_site($pdo);
    $st = $pdo->prepare("SELECT id FROM of_pages WHERE site_id = ? AND slug = ?");
    $st->execute([$site, $slug]);
    $id = (int) $st->fetchColumn();
    if ($id) return $id;
    [$label, $sort] = OF_CMS_STRONY[$slug];
    $pdo->prepare("INSERT INTO of_pages (site_id, slug, label, tab_anchor, visible, sort) VALUES (?,?,?,?,1,?)")
        ->execute([$site, $slug, $label, $slug === 'wspolne' ? '' : $slug, $sort]);
    return (int) $pdo->lastInsertId();
}

/** La section d'une clé (créée au premier geste, avec sa version d'origine). */
function of_cms_sekcja(PDO $pdo, string $slug, string $k, string $rodzaj, string $tytul, string $domyslna): int {
    $page = of_cms_strona($pdo, $slug);
    $pdo->prepare("INSERT INTO of_page_sections (page_id, sort, skey, title, kind, default_value) VALUES (?, 0, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE title = VALUES(title), kind = VALUES(kind), default_value = VALUES(default_value)")
        ->execute([$page, $k, mb_substr($tytul, 0, 80), $rodzaj, $domyslna]);
    $st = $pdo->prepare("SELECT id FROM of_page_sections WHERE page_id = ? AND skey = ?");
    $st->execute([$page, $k]);
    return (int) $st->fetchColumn();
}

/** Adresse d'une photo acceptée par le CMS : une photo du site ou une photo envoyée (en base). */
function of_cms_src_ok(string $src): bool {
    return (bool) preg_match('~^(assets/photos/[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)?\.(jpe?g|png|webp)|_wsb/app/zdjecie\.php\?id=\d+)$~i', $src)
        && strpos($src, '..') === false;
}

/** L'id of_media d'une adresse (la fiche est créée pour une photo du site qui n'en a pas). */
function of_cms_media(PDO $pdo, string $src): int {
    $st = $pdo->prepare("SELECT id FROM of_media WHERE tenant_id = ? AND path = ?");
    $st->execute([OF_TENANT, $src]);
    $id = (int) $st->fetchColumn();
    if ($id) return $id;
    if (preg_match('~zdjecie\.php\?id=(\d+)~', $src, $m)) return 0;   // envoyée puis supprimée
    $plik = basename($src);
    $mime = preg_match('~\.png$~i', $plik) ? 'image/png' : (preg_match('~\.webp$~i', $plik) ? 'image/webp' : 'image/jpeg');
    $pdo->prepare("INSERT INTO of_media (tenant_id, kind, path, filename, mime, alt) VALUES (?, 'photo', ?, ?, ?, ?)")
        ->execute([OF_TENANT, $src, $plik, $mime, str_replace('-', ' ', preg_replace('~\.[a-z0-9]+$~i', '', $plik))]);
    return (int) $pdo->lastInsertId();
}

/** Remplace les photos d'une section pour un état (draft | published). */
function of_cms_zdjecia(PDO $pdo, int $sek, string $stan, array $srcs): void {
    $pdo->prepare("DELETE FROM of_page_section_photos WHERE section_id = ? AND state = ?")->execute([$sek, $stan]);
    $ins = $pdo->prepare("INSERT IGNORE INTO of_page_section_photos (section_id, media_id, sort, state) VALUES (?,?,?,?)");
    $n = 0;
    foreach ($srcs as $src) {
        $src = (string) $src;
        if (!of_cms_src_ok($src) || !($mid = of_cms_media($pdo, $src))) continue;
        $ins->execute([$sek, $mid, ++$n, $stan]);
    }
}

/** Les sections des maquettes (résumés « 3 pakiety… » posés par seed.sql) ne sont pas des textes de la page : retirées. */
function of_cms_porzadki(PDO $pdo): void {
    $pdo->prepare("DELETE s FROM of_page_sections s JOIN of_pages p ON p.id = s.page_id JOIN of_sites w ON w.id = p.site_id
                   WHERE w.tenant_id = ? AND s.skey NOT LIKE '%:%'")->execute([OF_TENANT]);
}

/** Toutes les photos des sections, par clé : [skey => [src, …]] pour un état. */
function of_cms_wszystkie_zdjecia(PDO $pdo, string $stan): array {
    $st = $pdo->prepare("SELECT s.skey, m.path FROM of_page_section_photos f
                         JOIN of_page_sections s ON s.id = f.section_id JOIN of_pages p ON p.id = s.page_id
                         JOIN of_sites w ON w.id = p.site_id JOIN of_media m ON m.id = f.media_id
                         WHERE w.tenant_id = ? AND f.state = ? ORDER BY s.id, f.sort");
    $st->execute([OF_TENANT, $stan]);
    $o = [];
    foreach ($st as $r) $o[$r['skey']][] = $r['path'];
    return $o;
}

/** « dziś 14:03 » / « 12.11.2026 14:03 » */
function of_cms_stamp(?string $kiedy): string {
    if (!$kiedy || !($t = strtotime($kiedy))) return '';
    return (date('Y-m-d', $t) === date('Y-m-d') ? 'dziś ' : date('d.m.Y ', $t)) . date('H:i', $t);
}

/** Racine du site (le zip tel quel) : _wsb/app/ ou _wsb/prive/app/ sur le serveur ; OF_SITE pour les essais. */
function of_site_root(): string {
    $e = (string) (getenv('OF_SITE') ?: '');
    if ($e !== '' && is_dir($e)) return rtrim($e, '/');
    foreach ([2, 3] as $n) { $d = dirname(__DIR__, $n); if (is_dir($d . '/assets/photos')) return $d; }
    return '';
}

/** La photothèque du CMS : les photos du site (assets/photos/ola) puis celles envoyées. [['src','alt']] */
function of_cms_biblioteka(PDO $pdo): array {
    $alt = [];
    $st = $pdo->prepare("SELECT path, alt FROM of_media WHERE tenant_id = ? AND kind = 'photo' ORDER BY sort, id");
    $st->execute([OF_TENANT]);
    $wgrane = [];
    foreach ($st as $r) {
        $alt[$r['path']] = (string) $r['alt'];
        if (strpos($r['path'], '_wsb/app/zdjecie.php') === 0) $wgrane[] = ['src' => $r['path'], 'alt' => (string) $r['alt']];
    }
    $o = [];
    $root = of_site_root();
    if ($root !== '' && is_dir($root . '/assets/photos/ola')) {
        $pliki = array_filter(scandir($root . '/assets/photos/ola') ?: [], fn($f) => (bool) preg_match('~^[A-Za-z0-9._-]+\.(jpe?g|png|webp)$~i', $f));
        sort($pliki);
        foreach ($pliki as $f) {
            $src = 'assets/photos/ola/' . $f;
            $o[] = ['src' => $src, 'alt' => $alt[$src] ?? str_replace('-', ' ', preg_replace('~\.[a-z0-9]+$~i', '', $f))];
        }
    } else {
        foreach ($alt as $src => $a) if (strpos($src, 'assets/photos/ola/') === 0) $o[] = ['src' => $src, 'alt' => $a];
    }
    return array_merge(array_reverse($wgrane), $o);
}
