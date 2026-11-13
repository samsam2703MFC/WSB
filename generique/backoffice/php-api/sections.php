<?php
declare(strict_types=1);
/**
 * sections.php — le KREATOR : des pages composées de sections, depuis la console.
 *
 * Strony donnait une page = un titre, un chapeau, un corps, une image. Un
 * site se compose autrement : un bandeau avec photo, puis un texte à deux
 * colonnes, puis une galerie, puis trois tuiles, puis une question-réponse,
 * puis un appel à l'action. Chacune de ces briques est ici une SECTION d'un
 * TYPE connu, avec ses champs, son image ou ses images, ses textes dans les
 * trois langues — et la vitrine sait la rendre (lib.php, sekcja_html).
 *
 * TROIS CIBLES pour une section : la page d'accueil (page_id = 0, où elle
 * prend place dans Układ strony au milieu des sections intégrées), une page
 * écrite dans Strony (page_id > 0, sous son corps), ou le site entier
 * (page_id = -1 : la barre d'annonce au-dessus de l'en-tête, sur toutes les
 * pages).
 *
 * LA PARTIE BOUTIQUE N'EST PAS UNE SECTION. Le catalogue, le panier, la
 * caisse, le suivi de commande sont le code de la maison, testé et gardé.
 * Le Kreator compose AUTOUR : il ne touche ni aux prix, ni au panier.
 *
 * MÊME GRAMMAIRE, MÊME SÛRETÉ que Strony : le texte est du texte (pages.php
 * rend les six signes, tout est échappé), un lien n'est un lien qu'avec une
 * adresse sûre, une image ne vient que de la médiathèque ou d'https. Les
 * listes (tuiles, questions, légendes) sont bornées : un formulaire sans
 * JavaScript propose des cases en nombre fixe, et c'est aussi la borne de
 * ce qu'une page peut porter sans devenir illisible.
 */

const WSM_SECTION_BASE_LANG = 'pl';
const WSM_SECTION_SITE = -1;     // page_id : tout le site
const WSM_SECTION_HOME = 0;      // page_id : la page d'accueil

/**
 * Les types de section. Clé → définition :
 *   label, opis       lus par l'équipe (Kreator) ;
 *   pola              champs de texte par langue parmi title, lead, body, cta ;
 *   obraz             true si la section porte UNE image principale ;
 *   items             ['max' => n, 'pola' => [clé => étiquette], 'obraz' => bool] pour une liste ;
 *   styl              jetons de style permis (cochés dans le Kreator) ;
 *   ustawienia        clé → [étiquette, [valeur => libellé]] (select) ;
 *   cel               cibles permises : home, strona, site.
 */
function wsm_section_types(): array {
    static $t = null;
    if ($t !== null) return $t;
    $cel = ['home', 'strona'];
    return $t = [
        'hero' => ['label' => 'Nagłówek ze zdjęciem', 'opis' => 'Duże zdjęcie w tle, tytuł, hasło, przycisk — jak u góry strony głównej.',
            'pola' => ['title', 'lead', 'cta'], 'obraz' => true, 'items' => null,
            'styl' => ['srodek'], 'ustawienia' => ['wysokosc' => ['Wysokość', ['normalny' => 'Normalna', 'wysoki' => 'Wysoka']]], 'cel' => $cel],
        'tekst' => ['label' => 'Tekst', 'opis' => 'Tytuł i treść — akapity, listy, pogrubienia, linki, obrazy w tekście.',
            'pola' => ['title', 'body'], 'obraz' => false, 'items' => null,
            'styl' => ['waski'], 'ustawienia' => ['kolumny' => ['Kolumny', ['1' => 'Jedna', '2' => 'Dwie']]], 'cel' => $cel],
        'tekst_foto' => ['label' => 'Tekst ze zdjęciem', 'opis' => 'Zdjęcie obok tekstu, z przyciskiem. Karta jasna albo ciemna.',
            'pola' => ['title', 'lead', 'body', 'cta'], 'obraz' => true, 'items' => null,
            'styl' => ['ciemny', 'foto_prawo', 'szeroki'], 'ustawienia' => [], 'cel' => $cel],
        'foto' => ['label' => 'Zdjęcie', 'opis' => 'Jedno duże zdjęcie z podpisem.',
            'pola' => ['lead'], 'obraz' => true, 'items' => null,
            'styl' => ['szeroki'], 'ustawienia' => [], 'cel' => $cel],
        'galeria' => ['label' => 'Galeria', 'opis' => 'Do dwunastu zdjęć w siatce, z podpisami.',
            'pola' => ['title'], 'obraz' => false,
            'items' => ['max' => 12, 'pola' => ['t' => 'Podpis'], 'obraz' => true],
            'styl' => [], 'ustawienia' => ['kolumny' => ['Kolumny', ['2' => 'Dwie', '3' => 'Trzy', '4' => 'Cztery']]], 'cel' => $cel],
        'kafelki' => ['label' => 'Kafelki', 'opis' => 'Do sześciu kart z ikoną, tytułem i opisem — jak trzy obietnice.',
            'pola' => ['title', 'lead'], 'obraz' => false,
            'items' => ['max' => 6, 'pola' => ['t' => 'Tytuł kafelka', 'd' => 'Opis'], 'obraz' => true],
            'styl' => [], 'ustawienia' => ['kolumny' => ['Kolumny', ['2' => 'Dwie', '3' => 'Trzy', '4' => 'Cztery']]], 'cel' => $cel],
        'baner' => ['label' => 'Baner z przyciskiem', 'opis' => 'Pasek z tytułem, zdaniem i przyciskiem — zaproszenie do działania.',
            'pola' => ['title', 'lead', 'cta'], 'obraz' => false, 'items' => null,
            'styl' => ['ciemny', 'akcent'], 'ustawienia' => [], 'cel' => $cel],
        'faq' => ['label' => 'Pytania i odpowiedzi', 'opis' => 'Do dziesięciu pytań, każde rozwijane kliknięciem.',
            'pola' => ['title', 'lead'], 'obraz' => false,
            'items' => ['max' => 10, 'pola' => ['t' => 'Pytanie', 'd' => 'Odpowiedź'], 'obraz' => false],
            'styl' => [], 'ustawienia' => [], 'cel' => $cel],
        'cytat' => ['label' => 'Cytat', 'opis' => 'Opinia klienta albo motto, z podpisem i zdjęciem.',
            'pola' => ['body', 'lead'], 'obraz' => true, 'items' => null,
            'styl' => ['ciemny'], 'ustawienia' => [], 'cel' => $cel],
        'odstep' => ['label' => 'Odstęp / linia', 'opis' => 'Powietrze między sekcjami, z linią albo bez.',
            'pola' => [], 'obraz' => false, 'items' => null,
            'styl' => ['linia'], 'ustawienia' => ['rozmiar' => ['Rozmiar', ['maly' => 'Mały', 'duzy' => 'Duży']]], 'cel' => $cel],
        'ogloszenie' => ['label' => 'Pasek ogłoszeń (cały sklep)', 'opis' => 'Jedno zdanie nad nagłówkiem, na każdej stronie — promocja, przerwa, nowość. Z linkiem.',
            'pola' => ['body', 'cta'], 'obraz' => false, 'items' => null,
            'styl' => ['ciemny', 'akcent'], 'ustawienia' => [], 'cel' => ['site']],
    ];
}

/** Les jetons de style connus, tous types confondus, avec leur étiquette. */
function wsm_section_style_labels(): array {
    return [
        'ciemny' => 'Ciemne tło (kolor marki)', 'akcent' => 'Tło w kolorze akcentu', 'foto_prawo' => 'Zdjęcie po prawej',
        'szeroki' => 'Na całą szerokość, bez karty', 'waski' => 'Wąska kolumna do czytania', 'srodek' => 'Tekst na środku',
        'linia' => 'Z linią',
    ];
}

function wsm_sections_ensure(PDO $pdo): void {
    static $done = [];
    $k = spl_object_id($pdo);
    if (isset($done[$k])) return;
    if (!wsm_table_exists($pdo, 'wsm_sections') || !wsm_table_exists($pdo, 'wsm_section_i18n')) wsm_apply_schema($pdo);
    $done[$k] = true;
}

/** La cible d'une page_id : 'home', 'site' ou 'strona'. */
function wsm_section_cel(int $pageId): string {
    return $pageId === WSM_SECTION_SITE ? 'site' : ($pageId === WSM_SECTION_HOME ? 'home' : 'strona');
}

/** Les sections d'une cible, dans l'ordre, textes bruts par langue (pour le Kreator). */
function wsm_section_list(PDO $pdo, int $pageId, bool $tylkoOpublikowane = false): array {
    wsm_sections_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM wsm_sections WHERE page_id = ?" . ($tylkoOpublikowane ? " AND published = 1" : "") . " ORDER BY sort_order, id");
    $st->execute([$pageId]);
    $rows = $st->fetchAll() ?: [];
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st2 = $pdo->prepare("SELECT * FROM wsm_section_i18n WHERE section_id IN ($in)");
    $st2->execute($ids);
    $tx = [];
    foreach ($st2->fetchAll() ?: [] as $t) $tx[(int) $t['section_id']][(string) $t['lang']] = $t;
    foreach ($rows as &$r) {
        $r['i18n'] = $tx[(int) $r['id']] ?? [];
        $r['title'] = (string) ($r['i18n'][WSM_SECTION_BASE_LANG]['title'] ?? '');
        if ($r['title'] === '') foreach ($r['i18n'] as $t) { if (trim((string) $t['title']) !== '') { $r['title'] = (string) $t['title']; break; } }
        $r['styl'] = wsm_section_styl((string) $r['type'], (string) ($r['styl'] ?? ''));
        $r['ustawienia'] = wsm_section_ustawienia((string) $r['type'], (string) ($r['settings'] ?? ''));
    }
    unset($r);
    return $rows;
}

/** Une section par identifiant, textes bruts par langue. */
function wsm_section_get(PDO $pdo, int $id): ?array {
    wsm_sections_ensure($pdo);
    $st = $pdo->prepare("SELECT page_id FROM wsm_sections WHERE id = ?");
    $st->execute([$id]);
    $pid = $st->fetchColumn();
    if ($pid === false) return null;
    foreach (wsm_section_list($pdo, (int) $pid) as $s) if ((int) $s['id'] === $id) return $s;
    return null;
}

/**
 * UNE section prête à rendre : textes repliés sur le polonais champ par
 * champ, items décodés (repli sur la liste polonaise entière si la langue
 * n'en a pas), images validées. Null si elle n'est pas à montrer.
 */
function wsm_section_view(PDO $pdo, array $s, string $lang): ?array {
    $def = wsm_section_types()[(string) $s['type']] ?? null;
    if (!$def) return null;
    $par = $s['i18n'] ?? [];
    $out = ['title' => '', 'lead' => '', 'body' => '', 'cta_label' => '', 'cta_url' => '', 'items' => []];
    foreach (['title', 'lead', 'body', 'cta_label', 'cta_url'] as $f) {
        $v = trim((string) ($par[$lang][$f] ?? ''));
        if ($v === '') $v = trim((string) ($par[WSM_SECTION_BASE_LANG][$f] ?? ''));
        $out[$f] = $v;
    }
    // Les items : la liste polonaise est la base ; une traduction remplit
    // la même case (3e photo = 3e légende), champ par champ, et une case
    // laissée vide montre le polonais. Les images se choisissent une fois.
    $base = wsm_section_items_decode((string) ($par[WSM_SECTION_BASE_LANG]['items'] ?? ''));
    $tr = $lang === WSM_SECTION_BASE_LANG ? [] : wsm_section_items_decode((string) ($par[$lang]['items'] ?? ''));
    $items = [];
    for ($i = 0, $n = max(count($base), count($tr)); $i < $n; $i++) {
        $b = $base[$i] ?? ['t' => '', 'd' => '', 'img' => ''];
        $x = $tr[$i] ?? $b;
        $it = ['t' => $x['t'] !== '' ? $x['t'] : $b['t'], 'd' => $x['d'] !== '' ? $x['d'] : $b['d'], 'img' => $x['img'] !== '' ? $x['img'] : $b['img']];
        if (implode('', $it) === '') continue;
        $items[] = $it;
    }
    $out['items'] = $items;
    if ($out['cta_url'] !== '' && !wsm_page_url_ok($out['cta_url'])) $out['cta_url'] = '';
    $img = (string) ($s['image_url'] ?? '');
    if ($img !== '' && function_exists('wsm_media_valid_url') && !wsm_media_valid_url($img)) $img = '';
    $view = array_merge($s, $out, ['image_url' => $img, 'def' => $def]);
    // Rien à montrer = rien d'imprimé, sauf l'odstęp, qui est fait de rien.
    $vide = $out['title'] === '' && $out['lead'] === '' && $out['body'] === '' && $img === '' && !$items;
    if ($vide && $s['type'] !== 'odstep') return null;
    return $view;
}

/**
 * Les items d'une langue, dans l'ordre des cases du formulaire :
 * [['t' => …, 'd' => …, 'img' => …], …]. Une case vide AU MILIEU reste
 * (sa position sert au repli d'une traduction) ; les vides de fin tombent.
 */
function wsm_section_items_decode(string $json): array {
    if (trim($json) === '') return [];
    $j = json_decode($json, true);
    if (!is_array($j)) return [];
    $out = [];
    foreach ($j as $it) {
        if (!is_array($it)) $it = [];
        $t = trim((string) ($it['t'] ?? '')); $d = trim((string) ($it['d'] ?? '')); $img = trim((string) ($it['img'] ?? ''));
        if ($img !== '' && !wsm_page_img_ok($img)) $img = '';
        $out[] = ['t' => $t, 'd' => $d, 'img' => $img];
    }
    while ($out && implode('', end($out)) === '') array_pop($out);
    return $out;
}

/** Les jetons de style d'une section, filtrés sur ce que son type permet. */
function wsm_section_styl(string $type, string|array $v): array {
    $permis = wsm_section_types()[$type]['styl'] ?? [];
    $tok = is_array($v) ? $v : explode(',', $v);
    $out = [];
    foreach ($tok as $t) { $t = trim((string) $t); if (in_array($t, $permis, true)) $out[$t] = $t; }
    return array_values($out);
}

/** Les réglages (select) d'une section : clé → valeur, chacune validée, défaut = première option. */
function wsm_section_ustawienia(string $type, string|array $v): array {
    $def = wsm_section_types()[$type]['ustawienia'] ?? [];
    $in = is_array($v) ? $v : (json_decode($v, true) ?: []);
    $out = [];
    foreach ($def as $k => [$lbl, $opts]) {
        $val = (string) (($in[$k] ?? '') !== '' ? $in[$k] : array_key_first($opts));
        $out[$k] = isset($opts[$val]) ? $val : (string) array_key_first($opts);
    }
    return $out;
}

/**
 * Enregistre une section (création si $id est null). Les refus sont rendus
 * dans $errs, en polonais ; rien n'est écrit tant qu'il y en a un.
 *
 * @param array $in  page_id, type, published, styl[], ustawienia[k], image_url,
 *                   t[lang][title|lead|body|cta_label|cta_url], t[lang][items][i][t|d|img]
 */
function wsm_section_save(PDO $pdo, ?int $id, array $in, string $actor = '', array &$errs = []): ?int {
    wsm_sections_ensure($pdo);
    $errs = [];
    $types = wsm_section_types();
    $type = (string) ($in['type'] ?? '');
    $def = $types[$type] ?? null;
    if (!$def) { $errs['type'] = 'Nieznany rodzaj sekcji'; return null; }
    $pageId = (int) ($in['page_id'] ?? WSM_SECTION_HOME);
    $cel = wsm_section_cel($pageId);
    if (!in_array($cel, $def['cel'], true)) $errs['page_id'] = 'Ta sekcja nie może stać w tym miejscu';
    if ($cel === 'strona' && function_exists('wsm_page_get')) {
        $pg = wsm_page_get($pdo, $pageId);
        if (!$pg || (string) ($pg['kind'] ?? '') !== 'strona') $errs['page_id'] = 'Takiej strony nie ma';
    }

    $img = trim((string) ($in['image_url'] ?? ''));
    if (!$def['obraz']) $img = '';
    if ($img !== '' && function_exists('wsm_media_valid_url') && !wsm_media_valid_url($img)) $errs['image_url'] = 'Nieprawidłowy adres obrazu';

    $t = [];
    $maxItems = (int) ($def['items']['max'] ?? 0);
    foreach ((array) ($in['t'] ?? []) as $lang => $champs) {
        $lang = strtolower(trim((string) $lang));
        if (!preg_match('/^[a-z]{2}$/', $lang)) continue;
        $champs = (array) $champs;
        $row = ['title' => '', 'lead' => '', 'body' => '', 'cta_label' => '', 'cta_url' => '', 'items' => ''];
        foreach (['title', 'lead', 'body'] as $f) {
            if (!in_array($f, $def['pola'], true)) continue;
            $v = str_replace("\r\n", "\n", trim((string) ($champs[$f] ?? '')));
            if ($f === 'title') $v = mb_substr($v, 0, 200);
            if ($f === 'body' && mb_strlen($v) > WSM_PAGE_BODY_MAX) $errs['body'] = 'Treść jest za długa';
            $row[$f] = $v;
        }
        if (in_array('cta', $def['pola'], true)) {
            $row['cta_label'] = mb_substr(trim((string) ($champs['cta_label'] ?? '')), 0, 120);
            $row['cta_url'] = trim((string) ($champs['cta_url'] ?? ''));
            if ($row['cta_url'] !== '' && !wsm_page_url_ok($row['cta_url'])) $errs['cta_url'] = 'Adres przycisku musi być https://, lokalny (/…) albo adresem strony sklepu';
        }
        if ($maxItems > 0) {
            $items = [];
            foreach (array_slice(array_values((array) ($champs['items'] ?? [])), 0, $maxItems) as $it) {
                $it = (array) $it;
                $x = ['t' => mb_substr(trim((string) ($it['t'] ?? '')), 0, 200), 'd' => str_replace("\r\n", "\n", mb_substr(trim((string) ($it['d'] ?? '')), 0, 2000)),
                      'img' => !empty($def['items']['obraz']) ? trim((string) ($it['img'] ?? '')) : ''];
                if ($x['img'] !== '' && !wsm_page_img_ok($x['img'])) { $errs['items'] = 'Obraz w pozycji listy musi być z medioteki (media/…) albo https'; }
                $items[] = $x;      // la case reste, même vide : sa position sert à la traduction
            }
            while ($items && implode('', end($items)) === '') array_pop($items);
            $row['items'] = $items ? json_encode($items, JSON_UNESCAPED_UNICODE) : '';
        }
        $t[$lang] = $row;
    }
    // Une section sans aucun texte polonais ni image ni items n'est pas une
    // section — sauf l'odstęp, qui n'a rien à dire.
    $pl = $t[WSM_SECTION_BASE_LANG] ?? null;
    $plVide = !$pl || implode('', $pl) === '';
    if ($type !== 'odstep' && $plVide && $img === '') $errs['title'] = 'Wpisz coś po polsku — to język bazowy, na który spadają pozostałe';

    if ($errs) return null;

    // Tout ou rien : la ligne et ses textes (wsm_tx, db.php).
    return wsm_tx($pdo, function () use ($pdo, $id, $pageId, $type, $in, $img, $actor, $t) {
        $now = date('Y-m-d H:i:s');
        $vals = [
            'page_id'    => $pageId,
            'type'       => $type,
            'published'  => !empty($in['published']) ? 1 : 0,
            'styl'       => implode(',', wsm_section_styl($type, (array) ($in['styl'] ?? []))),
            'image_url'  => $img,
            'settings'   => json_encode(wsm_section_ustawienia($type, (array) ($in['ustawienia'] ?? [])), JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
            'updated_by' => mb_substr($actor, 0, 120),
        ];
        if ($id === null) {
            $max = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM wsm_sections WHERE page_id = " . (int) $pageId)->fetchColumn();
            $vals['sort_order'] = $max + 10;
            $vals['created_at'] = $now;
            $cols = array_keys($vals);
            $pdo->prepare("INSERT INTO wsm_sections (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")
                ->execute(array_values($vals));
            $id = (int) $pdo->lastInsertId();
        } else {
            $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($vals)));
            $pdo->prepare("UPDATE wsm_sections SET $set WHERE id = ?")->execute([...array_values($vals), $id]);
        }
        // Ce qui est envoyé est ce qui reste : le formulaire porte toutes ses
        // langues, une langue absente de l'envoi est une langue effacée.
        $pdo->prepare("DELETE FROM wsm_section_i18n WHERE section_id = ?")->execute([$id]);
        // `lead` entre accents graves : mot réservé de MySQL 8 (voir pages.php).
        $ins = $pdo->prepare("INSERT INTO wsm_section_i18n (`section_id`, `lang`, `title`, `lead`, `body`, `cta_label`, `cta_url`, `items`) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($t as $lang => $row) {
            if (implode('', $row) === '') continue;
            $ins->execute([$id, $lang, $row['title'], $row['lead'], $row['body'], $row['cta_label'], $row['cta_url'], $row['items']]);
        }
        return $id;
    });
}

/** Supprime une section et ses textes ; renvoie la ligne (pour ranger son image). */
function wsm_section_delete(PDO $pdo, int $id): ?array {
    $s = wsm_section_get($pdo, $id);
    if (!$s) return null;
    $pdo->prepare("DELETE FROM wsm_section_i18n WHERE section_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM wsm_sections WHERE id = ?")->execute([$id]);
    return $s;
}

/** Toutes les sections d'une page partent avec elle. */
function wsm_section_delete_page(PDO $pdo, int $pageId): int {
    wsm_sections_ensure($pdo);
    $n = 0;
    foreach (wsm_section_list($pdo, $pageId) as $s) { wsm_section_delete($pdo, (int) $s['id']); $n++; }
    return $n;
}

/** Monte ou descend une section d'un cran parmi celles de sa cible. */
function wsm_section_move(PDO $pdo, int $id, string $dir, string $actor = ''): bool {
    $s = wsm_section_get($pdo, $id);
    if (!$s) return false;
    $liste = wsm_section_list($pdo, (int) $s['page_id']);
    $ids = array_map(fn($r) => (int) $r['id'], $liste);
    $i = array_search($id, $ids, true);
    $j = $dir === 'up' ? $i - 1 : $i + 1;
    if ($i === false || $j < 0 || $j >= count($ids)) return false;
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    $up = $pdo->prepare("UPDATE wsm_sections SET sort_order = ?, updated_at = ?, updated_by = ? WHERE id = ?");
    foreach ($ids as $pos => $sid) $up->execute([($pos + 1) * 10, date('Y-m-d H:i:s'), mb_substr($actor, 0, 120), $sid]);
    return true;
}

/** Montre ou cache une section. */
function wsm_section_toggle(PDO $pdo, int $id, string $actor = ''): bool {
    $s = wsm_section_get($pdo, $id);
    if (!$s) return false;
    $pdo->prepare("UPDATE wsm_sections SET published = ?, updated_at = ?, updated_by = ? WHERE id = ?")
        ->execute([(int) $s['published'] ? 0 : 1, date('Y-m-d H:i:s'), mb_substr($actor, 0, 120), $id]);
    return true;
}

/** Les sections publiées d'une cible, prêtes à rendre dans une langue. */
function wsm_section_views(PDO $pdo, int $pageId, string $lang): array {
    $out = [];
    foreach (wsm_section_list($pdo, $pageId, true) as $s) {
        $v = wsm_section_view($pdo, $s, $lang);
        if ($v) $out[] = $v;
    }
    return $out;
}

/** Les médias cités par les sections (image principale et images d'items). */
function wsm_section_media_cites(PDO $pdo): array {
    wsm_sections_ensure($pdo);
    $out = [];
    try {
        foreach ($pdo->query("SELECT s.id, s.type, s.image_url, i.lang, i.title, i.items FROM wsm_sections s LEFT JOIN wsm_section_i18n i ON i.section_id = s.id")->fetchAll() ?: [] as $r) {
            $etiq = 'sekcja (' . (wsm_section_types()[(string) $r['type']]['label'] ?? $r['type']) . '): ' . ((string) ($r['title'] ?? '') !== '' ? (string) $r['title'] : '#' . (int) $r['id']);
            if ((string) $r['image_url'] !== '') $out[(string) $r['image_url']][] = $etiq;
            foreach (wsm_section_items_decode((string) ($r['items'] ?? '')) as $it) if ($it['img'] !== '') $out[$it['img']][] = $etiq;
        }
    } catch (Throwable $e) {}
    return $out;
}
