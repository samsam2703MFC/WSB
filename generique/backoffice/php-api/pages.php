<?php
declare(strict_types=1);
/**
 * pages.php — les PAGES et les BLOCS de la boutique, écrits depuis la console.
 *
 * Treści corrige les textes que la boutique porte déjà ; Wygląd change son
 * habit. Il manquait le troisième geste d'un CMS : AJOUTER. Une page « O nas »,
 * une « FAQ », une actualité, un bandeau avec une photo et un bouton au milieu
 * de l'accueil — sans toucher au code, sans redéploiement, dans les trois
 * langues.
 *
 * DEUX SORTES, UNE TABLE. Une « strona » a une adresse (/o-nas) et peut entrer
 * dans la barre du haut ou dans le pied de page. Un « blok » n'a pas d'adresse :
 * il s'insère dans la page d'accueil, à l'un des trois emplacements prévus.
 * Les deux portent un titre, un chapeau, un corps, une image et, pour le bloc,
 * un bouton. Les textes vivent dans wsm_page_i18n, une ligne par langue, avec
 * REPLI SUR LE POLONAIS champ par champ : une page traduite à moitié montre le
 * polonais là où la traduction manque, jamais un trou.
 *
 * LE CORPS EST DU TEXTE, PAS DU HTML. Un champ qui accepterait des balises
 * ferait de l'écran Strony une porte d'entrée pour du script sur la vitrine.
 * On échappe tout, puis on rend une grammaire minimale et lisible par un
 * humain : titres (## / ###), paragraphes, listes (- ), **gras**, liens
 * [texte](adresse), images ![légende](media/fichier.webp), filet (---).
 * Une adresse de lien qui n'est ni https, ni locale, ni une page de la
 * boutique n'est pas un lien : elle reste du texte.
 *
 * UNE ADRESSE RÉSERVÉE EST REFUSÉE. « koszyk », « kasa », « p »… sont les
 * routes de la boutique : une page qui les prendrait masquerait la caisse.
 */

const WSM_PAGE_BASE_LANG = 'pl';
const WSM_PAGE_SLUG_MAX  = 80;
const WSM_PAGE_BODY_MAX  = 20000;

const WSM_PAGE_KINDS = ['strona', 'blok'];

/**
 * Où un bloc s'insère dans l'accueil — PREMIÈRE version de Strony. L'ordre
 * se règle désormais dans Układ strony (layout.php) ; la colonne ne sert
 * plus qu'à placer un bloc ancien la première fois qu'Układ le découvre.
 */
const WSM_PAGE_PLACEMENTS = [
    'po_obietnicach' => 'Pod obietnicami, nad katalogiem',
    'po_katalogu'    => 'Pod katalogiem, nad blokiem B2B',
    'przed_stopka'   => 'Na dole, nad stopką',
];

/** Les styles d'un bloc : des jetons, cochés dans Strony, stylés dans shop.css. */
const WSM_PAGE_STYLE = [
    'ciemny'     => 'Ciemne tło (kolor marki, jasny tekst)',
    'foto_prawo' => 'Zdjęcie po prawej stronie tekstu',
    'szeroki'    => 'Bez karty — na całą szerokość',
];

/** Les routes de la boutique et les dossiers servis : aucune page ne peut les prendre. */
const WSM_PAGE_RESERVED = [
    'p', 'koszyk', 'kasa', 'zamowienie', 'kontakt', 'regulamin', 'prywatnosc', 'zwroty',
    'moje-zamowienie', 'catalog', 'robots.txt', 'sitemap.xml', 'assets', 'media',
    'fonts', 'img', 'api', 'backoffice', 'shop', 'landing', 'index.php', 'strona',
    'strony', 'blok', 'bloki', 'admin', 'login', 'logout', 'tpay', 'stripe', 'zaplac', 'inpost',
    'newsletter', 's',
    // Les formulaires publics (/f/…), le flux produits (/feed/…) et le script
    // de la bannière d'accord : une page Strony à ces adresses les masquerait.
    'f', 'feed', 'zgody.js',
];

function wsm_pages_ensure(PDO $pdo): void {
    static $done = [];
    $k = spl_object_id($pdo);
    if (isset($done[$k])) return;
    if (!wsm_table_exists($pdo, 'wsm_pages') || !wsm_table_exists($pdo, 'wsm_page_i18n')) wsm_apply_schema($pdo);
    // Le style d'un bloc est arrivé après la table : une base qui l'a créée
    // sans lui le reçoit ici, sans migration à jouer à la main.
    if (function_exists('wsm_ensure_columns')) {
        wsm_ensure_columns($pdo, 'wsm_pages', ['styl' => ["VARCHAR(60) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"]]);
    }
    $done[$k] = true;
}

/** Les jetons de style d'un bloc, filtrés sur la liste connue. */
function wsm_page_styl(string|array $v): array {
    $tok = is_array($v) ? $v : explode(',', $v);
    $out = [];
    foreach ($tok as $t) { $t = trim((string) $t); if (isset(WSM_PAGE_STYLE[$t])) $out[$t] = $t; }
    return array_values($out);
}

/**
 * Une adresse propre à partir d'un titre : minuscules, sans diacritiques,
 * tirets. « O nas — Mister Szoko » devient « o-nas-mister-szoko ». Le
 * polonais ET l'ukrainien sont translittérés : « Про нас » donne « pro-nas »,
 * pas une chaîne vide qu'il faudrait inventer à la main.
 */
function wsm_page_slug(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $map = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ç' => 'c', 'ü' => 'u', 'ö' => 'o',
        'ä' => 'a', 'ß' => 'ss', 'ô' => 'o', 'î' => 'i', 'ï' => 'i', 'û' => 'u', 'ù' => 'u',
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ye', 'ж' => 'zh',
        'з' => 'z', 'и' => 'y', 'і' => 'i', 'ї' => 'yi', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ь' => '', 'ю' => 'yu', 'я' => 'ya', 'ы' => 'y', 'э' => 'e', 'ё' => 'yo',
        'ъ' => '',
    ];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return substr($s, 0, WSM_PAGE_SLUG_MAX);
}

/** L'adresse est-elle acceptable ? Sinon, $why dit pourquoi, en polonais. */
function wsm_page_slug_ok(string $slug, ?string &$why = null): bool {
    if ($slug === '') { $why = 'Adres (slug) jest pusty'; return false; }
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) { $why = 'Adres może mieć tylko małe litery, cyfry i myślniki'; return false; }
    if (strlen($slug) > WSM_PAGE_SLUG_MAX) { $why = 'Adres jest za długi'; return false; }
    if (in_array($slug, WSM_PAGE_RESERVED, true) || str_ends_with($slug, '.php')) {
        $why = 'Adres „' . $slug . '” jest zarezerwowany dla sklepu'; return false;
    }
    return true;
}

/** Les champs de texte d'une page, par langue. */
function wsm_page_text_fields(): array {
    return ['title', 'lead', 'body', 'meta_desc', 'cta_label', 'cta_url'];
}

function wsm_page_text_vide(): array {
    return array_fill_keys(wsm_page_text_fields(), '');
}

/** Toutes les pages (ou d'une sorte), avec le titre polonais et les langues remplies. */
function wsm_page_list(PDO $pdo, ?string $kind = null): array {
    wsm_pages_ensure($pdo);
    $sql = "SELECT * FROM wsm_pages" . ($kind !== null ? " WHERE kind = ?" : "")
         . " ORDER BY kind, placement, sort_order, id";
    $st = $pdo->prepare($sql);
    $st->execute($kind !== null ? [$kind] : []);
    $rows = $st->fetchAll() ?: [];
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st2 = $pdo->prepare("SELECT page_id, lang, title FROM wsm_page_i18n WHERE page_id IN ($in)");
    $st2->execute($ids);
    $titres = [];
    foreach ($st2->fetchAll() ?: [] as $t) {
        if (trim((string) $t['title']) === '') continue;
        $titres[(int) $t['page_id']][(string) $t['lang']] = (string) $t['title'];
    }
    foreach ($rows as &$r) {
        $id = (int) $r['id'];
        // Une page sans aucun titre (ses textes perdus, ou jamais écrits) ne
        // doit pas faire tomber la liste : reset() sur null est une erreur fatale.
        $tous = $titres[$id] ?? [];
        $r['title'] = (string) ($tous[WSM_PAGE_BASE_LANG] ?? ($tous ? reset($tous) : ''));
        $r['langs'] = array_keys($titres[$id] ?? []);
        sort($r['langs']);
    }
    unset($r);
    return $rows;
}

/** Une page avec ses textes dans TOUTES les langues saisies (pour l'écran). */
function wsm_page_get(PDO $pdo, int $id): ?array {
    wsm_pages_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM wsm_pages WHERE id = ?");
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) return null;
    $p['i18n'] = [];
    $st2 = $pdo->prepare("SELECT * FROM wsm_page_i18n WHERE page_id = ?");
    $st2->execute([$id]);
    foreach ($st2->fetchAll() ?: [] as $t) {
        $row = [];
        foreach (wsm_page_text_fields() as $f) $row[$f] = (string) ($t[$f] ?? '');
        $p['i18n'][(string) $t['lang']] = $row;
    }
    return $p;
}

/**
 * Les textes d'une page dans UNE langue, avec repli sur le polonais champ par
 * champ. `lang_used` dit si au moins le titre venait de la langue demandée.
 */
function wsm_page_textes(PDO $pdo, int $id, string $lang): array {
    $st = $pdo->prepare("SELECT * FROM wsm_page_i18n WHERE page_id = ? AND lang IN (?, ?)");
    $st->execute([$id, $lang, WSM_PAGE_BASE_LANG]);
    $par = [];
    foreach ($st->fetchAll() ?: [] as $t) $par[(string) $t['lang']] = $t;
    $out = wsm_page_text_vide();
    foreach (wsm_page_text_fields() as $f) {
        $v = trim((string) ($par[$lang][$f] ?? ''));
        if ($v === '') $v = trim((string) ($par[WSM_PAGE_BASE_LANG][$f] ?? ''));
        $out[$f] = $v;
    }
    $out['lang_used'] = trim((string) ($par[$lang]['title'] ?? '')) !== '' ? $lang : WSM_PAGE_BASE_LANG;
    return $out;
}

/** La page publique derrière une adresse — null si elle n'existe pas ou n'est pas publiée. */
function wsm_page_find(PDO $pdo, string $slug, string $lang, bool $tylkoOpublikowane = true): ?array {
    wsm_pages_ensure($pdo);
    if ($slug === '' || in_array($slug, WSM_PAGE_RESERVED, true)) return null;
    $st = $pdo->prepare("SELECT * FROM wsm_pages WHERE slug = ? AND kind = 'strona'" . ($tylkoOpublikowane ? " AND published = 1" : ""));
    $st->execute([$slug]);
    $p = $st->fetch();
    if (!$p) return null;
    return array_merge($p, wsm_page_textes($pdo, (int) $p['id'], $lang));
}

/** Les pages publiées à montrer dans la barre du haut / le pied de page. */
function wsm_page_liens(PDO $pdo, string $lang, string $ou = 'nav'): array {
    wsm_pages_ensure($pdo);
    $col = $ou === 'footer' ? 'in_footer' : 'in_nav';
    $rows = $pdo->query("SELECT id, slug FROM wsm_pages WHERE kind = 'strona' AND published = 1 AND $col = 1
                          ORDER BY sort_order, id")->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $r) {
        $t = wsm_page_textes($pdo, (int) $r['id'], $lang);
        if ($t['title'] === '') continue;
        $out[] = ['slug' => (string) $r['slug'], 'title' => $t['title']];
    }
    return $out;
}

/** UN bloc publié, textes repliés, pour la vitrine — null s'il n'est pas à montrer. */
function wsm_page_blok(PDO $pdo, int $id, string $lang): ?array {
    wsm_pages_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM wsm_pages WHERE id = ? AND kind = 'blok' AND published = 1");
    $st->execute([$id]);
    $b = $st->fetch();
    if (!$b) return null;
    $t = wsm_page_textes($pdo, (int) $b['id'], $lang);
    if ($t['title'] === '' && $t['body'] === '' && (string) $b['image_url'] === '') return null;
    $b['styl'] = wsm_page_styl((string) ($b['styl'] ?? ''));
    return array_merge($b, $t);
}

/** Les adresses des pages publiées, pour le plan du site. */
function wsm_page_sitemap(PDO $pdo): array {
    wsm_pages_ensure($pdo);
    $rows = $pdo->query("SELECT slug FROM wsm_pages WHERE kind = 'strona' AND published = 1 AND slug IS NOT NULL ORDER BY sort_order, id")->fetchAll() ?: [];
    return array_values(array_filter(array_map(fn($r) => (string) $r['slug'], $rows)));
}

/**
 * Enregistre une page (création si $id est null). Les erreurs sont rendues
 * dans $errs, champ par champ, en polonais ; rien n'est écrit tant qu'il y en
 * a une. Renvoie l'identifiant.
 *
 * @param array $in  kind, slug, placement, published, in_nav, in_footer,
 *                   sort_order, image_url, t[lang][champ]
 */
function wsm_page_save(PDO $pdo, ?int $id, array $in, string $actor = '', array &$errs = []): ?int {
    wsm_pages_ensure($pdo);
    $errs = [];
    $kind = (string) ($in['kind'] ?? 'strona');
    if (!in_array($kind, WSM_PAGE_KINDS, true)) $errs['kind'] = 'Nieznany rodzaj';

    $t = [];
    foreach ((array) ($in['t'] ?? []) as $lang => $champs) {
        $lang = strtolower(trim((string) $lang));
        if (!preg_match('/^[a-z]{2}$/', $lang)) continue;
        $row = wsm_page_text_vide();
        foreach (wsm_page_text_fields() as $f) {
            $v = str_replace("\r\n", "\n", trim((string) (((array) $champs)[$f] ?? '')));
            if ($f === 'body' && mb_strlen($v) > WSM_PAGE_BODY_MAX) { $errs['body'] = 'Treść jest za długa (maks. ' . WSM_PAGE_BODY_MAX . ' znaków)'; }
            if ($f === 'title') $v = mb_substr($v, 0, 200);
            if ($f === 'meta_desc') $v = mb_substr($v, 0, 300);
            if ($f === 'cta_label') $v = mb_substr($v, 0, 120);
            if ($f === 'cta_url' && $v !== '' && !wsm_page_url_ok($v)) { $errs['cta_url'] = 'Adres przycisku musi być https://, lokalny (/…) albo adresem strony sklepu'; }
            $row[$f] = $v;
        }
        $t[$lang] = $row;
    }
    $titrePl = trim((string) ($t[WSM_PAGE_BASE_LANG]['title'] ?? ''));
    if ($titrePl === '') $errs['title'] = 'Tytuł po polsku jest wymagany — to język bazowy, na który spadają pozostałe';

    $slug = null;
    if ($kind === 'strona') {
        $slug = wsm_page_slug((string) ($in['slug'] ?? ''));
        if ($slug === '' && $titrePl !== '') $slug = wsm_page_slug($titrePl);
        $why = null;
        if (!wsm_page_slug_ok($slug, $why)) $errs['slug'] = (string) $why;
        else {
            $st = $pdo->prepare("SELECT id FROM wsm_pages WHERE slug = ?" . ($id !== null ? " AND id <> ?" : ""));
            $st->execute($id !== null ? [$slug, $id] : [$slug]);
            if ($st->fetchColumn()) $errs['slug'] = 'Adres „' . $slug . '” jest już zajęty przez inną stronę';
        }
    }
    // L'emplacement n'est plus demandé : l'ordre vit dans Układ strony. On
    // garde ce qui est posté s'il est connu, pour les blocs d'avant.
    $placement = (string) ($in['placement'] ?? '');
    if (!isset(WSM_PAGE_PLACEMENTS[$placement])) $placement = '';
    $styl = $kind === 'blok' ? implode(',', wsm_page_styl((array) ($in['styl'] ?? []))) : '';
    $img = trim((string) ($in['image_url'] ?? ''));
    if (function_exists('wsm_media_valid_url') && !wsm_media_valid_url($img)) $errs['image_url'] = 'Nieprawidłowy adres obrazu';

    if ($errs) return null;

    // Tout ou rien : la ligne et ses textes (wsm_tx, db.php).
    return wsm_tx($pdo, function () use ($pdo, $id, $kind, $slug, $placement, $in, $img, $styl, $actor, $t) {
        $now = date('Y-m-d H:i:s');
        $vals = [
            'slug'       => $kind === 'strona' ? $slug : null,
            'kind'       => $kind,
            'placement'  => $placement,
            'published'  => !empty($in['published']) ? 1 : 0,
            'in_nav'     => $kind === 'strona' && !empty($in['in_nav']) ? 1 : 0,
            'in_footer'  => $kind === 'strona' && !empty($in['in_footer']) ? 1 : 0,
            'sort_order' => max(0, min(9999, (int) ($in['sort_order'] ?? 100))),
            'image_url'  => $img,
            'styl'       => $styl,
            'updated_at' => $now,
            'updated_by' => mb_substr($actor, 0, 120),
        ];
        if ($id === null) {
            $vals['created_at'] = $now;
            $cols = array_keys($vals);
            $pdo->prepare("INSERT INTO wsm_pages (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")
                ->execute(array_values($vals));
            $id = (int) $pdo->lastInsertId();
        } else {
            $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($vals)));
            $pdo->prepare("UPDATE wsm_pages SET $set WHERE id = ?")->execute([...array_values($vals), $id]);
        }

        // Les textes : une ligne par langue saisie ; une langue vidée disparaît.
        $del = $pdo->prepare("DELETE FROM wsm_page_i18n WHERE page_id = ? AND lang = ?");
        // `lead` ENTRE ACCENTS GRAVES : c'est un mot réservé de MySQL 8 (la
        // fonction de fenêtre LEAD). Nu, l'ordre passait sous SQLite et tombait
        // en production — aucune page de Strony ne s'enregistrait.
        $ins = $pdo->prepare("INSERT INTO wsm_page_i18n (`page_id`, `lang`, `title`, `lead`, `body`, `meta_desc`, `cta_label`, `cta_url`)
                              VALUES (?,?,?,?,?,?,?,?)");
        foreach ($t as $lang => $row) {
            $del->execute([$id, $lang]);
            if (implode('', $row) === '') continue;
            $ins->execute([$id, $lang, $row['title'], $row['lead'], $row['body'], $row['meta_desc'], $row['cta_label'], $row['cta_url']]);
        }
        return $id;
    });
}

/** Supprime une page et ses textes ; renvoie la ligne supprimée (pour ranger son image). */
function wsm_page_delete(PDO $pdo, int $id): ?array {
    wsm_pages_ensure($pdo);
    $p = wsm_page_get($pdo, $id);
    if (!$p) return null;
    $pdo->prepare("DELETE FROM wsm_page_i18n WHERE page_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM wsm_pages WHERE id = ?")->execute([$id]);
    // Ses sections du Kreator partent avec elle : une section orpheline ne
    // se verrait nulle part et garderait ses images « utilisées ».
    if (!function_exists('wsm_section_delete_page')) { $f = __DIR__ . '/sections.php'; if (is_file($f)) require_once $f; }
    if (function_exists('wsm_section_delete_page')) { try { wsm_section_delete_page($pdo, $id); } catch (Throwable $e) {} }
    return $p;
}

/**
 * Une adresse acceptable pour un lien ou un bouton : https, locale (/…, #…),
 * ou l'adresse d'une page de la boutique (lettres, chiffres, tirets). Tout
 * le reste — javascript:, data:, http: en clair — n'est pas un lien.
 */
function wsm_page_url_ok(string $url): bool {
    $url = trim($url);
    if ($url === '') return false;
    // La colonne cta_url est un VARCHAR(255) : plus long, MySQL refuserait
    // l'enregistrement entier de la page (SQLite, lui, laissait passer).
    if (strlen($url) > 255) return false;
    if (preg_match('#^https://[^\s<>"\']+$#i', $url)) return true;
    if (preg_match('#^(/|\#)[^\s<>"\']*$#', $url)) return true;
    if (preg_match('/^[a-z0-9][a-z0-9-]*(#[a-z0-9-]*)?$/', $url)) return true;
    return false;
}

/** Une adresse d'image acceptable dans un corps : notre média ou https. */
function wsm_page_img_ok(string $src): bool {
    return (bool) preg_match('#^media/[a-f0-9]{24}\.(webp|jpg|png)$#', $src)
        || (bool) preg_match('#^https://[^\s<>"\']+\.(webp|jpg|jpeg|png|gif|svg)$#i', $src);
}

/**
 * Le corps rendu en HTML. TOUT est échappé d'abord ; la grammaire est posée
 * ensuite sur le texte échappé, et seules des adresses validées deviennent
 * des liens ou des images.
 *
 * @param callable|null $url  résout une adresse relative (« o-nas »,
 *                            « media/x.webp ») vers la boutique — u() côté
 *                            vitrine. Sans lui, l'adresse reste telle quelle.
 */
function wsm_page_render(string $body, ?callable $url = null): string {
    $body = str_replace("\r\n", "\n", $body);
    $out = '';
    foreach (preg_split('/\n\s*\n/', trim($body)) ?: [] as $bloc) {
        $bloc = trim($bloc);
        if ($bloc === '') continue;
        if ($bloc === '---') { $out .= '<hr>'; continue; }
        if (preg_match('/^###\s+(.+)$/s', $bloc, $m)) { $out .= '<h3>' . wsm_page_inline($m[1], $url) . '</h3>'; continue; }
        if (preg_match('/^##\s+(.+)$/s', $bloc, $m))  { $out .= '<h2>' . wsm_page_inline($m[1], $url) . '</h2>'; continue; }
        if (preg_match('/^!\[([^\]]*)\]\(([^)\s]+)\)$/', $bloc, $m)) {
            if (wsm_page_img_ok($m[2])) {
                $src = $url && !str_starts_with($m[2], 'https://') ? $url($m[2]) : $m[2];
                $alt = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                $out .= '<figure class="page-fig"><img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" loading="lazy" decoding="async">'
                      . ($m[1] !== '' ? '<figcaption>' . $alt . '</figcaption>' : '') . '</figure>';
            } else {
                $out .= '<p>' . wsm_page_inline($bloc, $url) . '</p>';
            }
            continue;
        }
        $lignes = preg_split('/\n/', $bloc) ?: [];
        $liste = true;
        foreach ($lignes as $l) if (!str_starts_with(trim($l), '- ')) { $liste = false; break; }
        if ($liste) {
            $out .= '<ul>';
            foreach ($lignes as $l) $out .= '<li>' . wsm_page_inline(substr(trim($l), 2), $url) . '</li>';
            $out .= '</ul>';
            continue;
        }
        $out .= '<p>' . wsm_page_inline(implode(' ', array_map('trim', $lignes)), $url) . '</p>';
    }
    return $out;
}

/** Le texte en ligne : échappé, puis **gras**, liens et images posés dessus. */
function wsm_page_inline(string $s, ?callable $url = null): string {
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    // Les images d'abord : leur syntaxe contient celle des liens.
    $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) use ($url) {
        $src = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        if (!wsm_page_img_ok($src)) return $m[0];
        $src = $url && !str_starts_with($src, 'https://') ? $url($src) : $src;
        return '<img class="page-img" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . $m[1] . '" loading="lazy" decoding="async">';
    }, $s) ?? $s;
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) use ($url) {
        $href = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        if (!wsm_page_url_ok($href)) return $m[0];                   // pas un lien : reste du texte
        $ext = str_starts_with($href, 'https://');
        if (!$ext && !str_starts_with($href, '/') && !str_starts_with($href, '#') && $url) $href = $url($href);
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . ($ext ? ' target="_blank" rel="noopener"' : '') . '>' . $m[1] . '</a>';
    }, $s) ?? $s;
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s) ?? $s;
    return $s;
}

/** Les médias de la maison cités dans un corps (pour savoir qu'un fichier sert). */
function wsm_page_media_cites(string $body): array {
    preg_match_all('#media/[a-f0-9]{24}\.(?:webp|jpg|png)#', $body, $m);
    return array_values(array_unique($m[0] ?? []));
}
