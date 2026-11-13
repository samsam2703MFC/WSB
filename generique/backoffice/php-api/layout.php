<?php
declare(strict_types=1);
/**
 * layout.php — l'ORDRE des choses : les sections de l'accueil, les liens du
 * menu et du pied de page, réglés depuis la console (écran Układ strony).
 *
 * Treści dit ce que la boutique écrit, Wygląd comment elle s'habille, Strony
 * ce qu'on y ajoute. Restait la STRUCTURE : quelle section vient avant quelle
 * autre, laquelle se cache, dans quel ordre le menu propose ses liens. C'est
 * la dernière raison d'ouvrir le code pour un changement d'apparence.
 *
 * TROIS LISTES, une par surface : « home » (sections de l'accueil), « nav »
 * (barre du haut), « footer » (pied de page). Chaque liste mêle des entrées
 * INTÉGRÉES (le catalogue, le bloc B2B, le lien Kontakt…) et des entrées
 * ÉCRITES dans Strony (blok:12, strona:7). Pour celles-ci, la liste ne porte
 * que l'ORDRE : « visible » reste le drapeau de la page elle-même (publié,
 * dans le menu, dans la stopka) — une seule vérité, pas deux cases à cocher
 * qui se contredisent.
 *
 * CE QUI NE SE CACHE PAS. Le catalogue : une boutique sans rayon n'est plus
 * une boutique. Le règlement et la politique de confidentialité dans le pied
 * de page : la loi les y veut, et l'opérateur de paiement les y a cherchés.
 * L'écran le dit au lieu de proposer un interrupteur qui mettrait la maison
 * en faute.
 *
 * UN ÉLÉMENT NOUVEAU TROUVE SA PLACE TOUT SEUL. Un bloc créé dans Strony
 * entre dans l'accueil avant le panneau B2B, une page cochée « w menu »
 * à la fin du menu — sans qu'on ait à ouvrir Układ. Un bloc supprimé sort de
 * la liste. La liste rangée en base n'est qu'une préférence d'ordre ; la
 * vérité est recomposée à chaque lecture.
 */

const WSM_LAYOUT_PREFIX = 'layout.';
const WSM_LAYOUT_LISTES = ['home', 'nav', 'footer'];

/** Les sections intégrées de l'accueil, dans l'ordre d'origine. */
function wsm_layout_home_builtins(): array {
    return [
        // Le hero intégré peut se cacher depuis que le Kreator sait en poser
        // un autre (section « Nagłówek ze zdjęciem ») — mais s'il est là, il
        // reste premier.
        'hero'      => ['label' => 'Nagłówek — zdjęcie, tytuł, przycisk', 'fixed' => false, 'pin' => 'first'],
        'pasek'     => ['label' => 'Pasek u góry — darmowa dostawa i wysyłka tego samego dnia', 'fixed' => false, 'pin' => 'hero'],
        'obietnice' => ['label' => 'Trzy obietnice z ikonami',             'fixed' => false],
        'katalog'   => ['label' => 'Katalog produktów',                    'fixed' => true],
        'pro'       => ['label' => 'Blok dla firm (B2B)',                  'fixed' => false],
        // Le téléphone et l'e-mail sur la page d'accueil — demandés par
        // l'équipe. Les deux viennent de Treści (footer.phone, footer.email) :
        // un seul endroit à corriger le jour où le numéro change.
        'kontakt'   => ['label' => 'Kontakt — telefon i e-mail (z Treści: footer.phone, footer.email)', 'fixed' => false],
    ];
}

/** Les liens intégrés de la barre du haut. */
function wsm_layout_nav_builtins(): array {
    return [
        'sklep'      => ['label' => 'Sklep — do katalogu'],
        'b2b'        => ['label' => 'B2B — do bloku dla firm'],
        'zamowienie' => ['label' => 'Moje zamówienie'],
        'zwroty'     => ['label' => 'Zwroty — adres do odsyłania paczek'],
        'kontakt'    => ['label' => 'Kontakt'],
    ];
}

/** Les liens intégrés du pied de page. */
function wsm_layout_footer_builtins(): array {
    return [
        'email'      => ['label' => 'Adres e-mail (z Treści: footer.email)'],
        'telefon'    => ['label' => 'Telefon (z Treści: footer.phone)'],
        // Le pied de page est le seul endroit présent sur TOUTES les pages,
        // téléphone compris : le suivi de commande y reste, sinon il
        // n'existerait que sur l'écran où on en a le moins besoin.
        'zamowienie' => ['label' => 'Moje zamówienie — zawsze w stopce (telefon)', 'fixed' => true],
        'regulamin'  => ['label' => 'Regulamin sklepu — wymagany prawem', 'fixed' => true],
        'prywatnosc' => ['label' => 'Polityka prywatności — wymagana prawem', 'fixed' => true],
        // L'adresse des retours, demandée par tpay « dans un endroit
        // facilement accessible » : comme le règlement, sans interrupteur.
        'zwroty'     => ['label' => 'Zwroty — adres do odsyłania paczek (wymaga tpay)', 'fixed' => true],
        'kontakt'    => ['label' => 'Kontakt'],
        'konsola'    => ['label' => 'Panel marki — link do konsoli'],
    ];
}

function wsm_layout_builtins(string $which): array {
    return match ($which) {
        'home'   => wsm_layout_home_builtins(),
        'nav'    => wsm_layout_nav_builtins(),
        'footer' => wsm_layout_footer_builtins(),
        default  => [],
    };
}

/** La liste rangée en base : [['k' => clé, 'on' => 0|1], …] — ou null si jamais réglée. */
function wsm_layout_raw(PDO $pdo, string $which): ?array {
    try {
        $st = $pdo->prepare("SELECT val FROM wsm_settings WHERE cle = ?");
        $st->execute([WSM_LAYOUT_PREFIX . $which]);
        $v = $st->fetchColumn();
    } catch (Throwable $e) { return null; }
    if ($v === false || $v === null || $v === '') return null;
    $j = json_decode((string) $v, true);
    if (!is_array($j)) return null;
    $out = [];
    foreach ($j as $it) {
        if (!is_array($it) || !isset($it['k'])) continue;
        $k = (string) $it['k'];
        // « b2b » porte un chiffre : une clé est une lettre puis des lettres,
        // chiffres ou tirets bas — pas seulement des lettres. Le premier jet
        // laissait tomber b2b à chaque lecture, et le menu se réordonnait seul.
        if (!preg_match('/^([a-z][a-z0-9_]*|blok:\d+|strona:\d+|sekcja:\d+)$/', $k)) continue;
        $out[$k] = ['k' => $k, 'on' => !empty($it['on']) ? 1 : 0];
    }
    return array_values($out);
}

function wsm_layout_write(PDO $pdo, string $which, array $liste, string $actor = ''): void {
    $now = date('Y-m-d H:i:s');
    $val = json_encode(array_values(array_map(fn($it) => ['k' => (string) $it['k'], 'on' => (int) $it['on']], $liste)), JSON_UNESCAPED_UNICODE);
    $up = $pdo->prepare("UPDATE wsm_settings SET val = ?, secret = 0, updated_at = ?, updated_by = ? WHERE cle = ?");
    $up->execute([$val, $now, mb_substr($actor, 0, 120), WSM_LAYOUT_PREFIX . $which]);
    if ($up->rowCount() === 0) {
        // MySQL compte zéro ligne quand la valeur ne change pas : la ligne
        // existe pourtant, et l'INSERT heurterait la clé. On essaie, et un
        // refus veut dire « déjà là » — pas une panne.
        try {
            $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,0,?,?)")
                ->execute([WSM_LAYOUT_PREFIX . $which, $val, $now, mb_substr($actor, 0, 120)]);
        } catch (Throwable $e) { /* la ligne existait déjà avec la même valeur */ }
    }
}

/** Retour à l'ordre d'origine : la préférence disparaît, la vérité se recompose. */
function wsm_layout_reset(PDO $pdo, string $which): void {
    $pdo->prepare("DELETE FROM wsm_settings WHERE cle = ?")->execute([WSM_LAYOUT_PREFIX . $which]);
}

/** Les sections du Kreator posées sur l'accueil : id → ligne (type, title, published, sort_order). */
function wsm_layout_sections(PDO $pdo): array {
    if (!function_exists('wsm_section_list')) {
        $f = __DIR__ . '/sections.php';
        if (!is_file($f)) return [];
        require_once $f;
    }
    try { $rows = wsm_section_list($pdo, WSM_SECTION_HOME); } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) $out[(int) $r['id']] = $r;
    return $out;
}

/** Les pages écrites dans Strony : id → [slug, title (pl), published, in_nav, in_footer, kind, placement]. */
function wsm_layout_pages(PDO $pdo): array {
    if (!function_exists('wsm_page_list')) {
        $f = __DIR__ . '/pages.php';
        if (!is_file($f)) return [];
        require_once $f;
    }
    try { $rows = wsm_page_list($pdo); } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) $out[(int) $r['id']] = $r;
    return $out;
}

/**
 * LA LISTE EN VIGUEUR d'une surface : la préférence rangée, recomposée avec
 * ce qui existe vraiment. Chaque entrée : k, on, type (builtin|blok|strona),
 * id, label, fixed, pin, published (pour un bloc ou une page).
 */
function wsm_layout_get(PDO $pdo, string $which): array {
    if (!in_array($which, WSM_LAYOUT_LISTES, true)) return [];
    $builtins = wsm_layout_builtins($which);
    $pages = wsm_layout_pages($pdo);
    $sekcje = $which === 'home' ? wsm_layout_sections($pdo) : [];
    $raw = wsm_layout_raw($pdo, $which) ?? [];

    // 1. Ce que la préférence connaît encore, dans son ordre.
    $liste = [];
    foreach ($raw as $it) {
        $k = $it['k'];
        if (isset($builtins[$k])) {
            $liste[$k] = wsm_layout_item($which, $k, $builtins[$k], $it['on'], null);
        } elseif (preg_match('/^sekcja:(\d+)$/', $k, $m)) {
            $s = $sekcje[(int) $m[1]] ?? null;
            if (!$s || $which !== 'home') continue;                              // supprimée depuis
            $liste[$k] = wsm_layout_item_sekcja($k, $s);
        } elseif (preg_match('/^(blok|strona):(\d+)$/', $k, $m)) {
            $p = $pages[(int) $m[2]] ?? null;
            if (!$p) continue;                                                   // supprimée depuis
            if ($which === 'home' && ($p['kind'] !== 'blok' || $m[1] !== 'blok')) continue;
            if ($which !== 'home' && ($p['kind'] !== 'strona' || $m[1] !== 'strona')) continue;
            $liste[$k] = wsm_layout_item($which, $k, [], 1, $p);
        }
    }
    // 2. Les intégrées oubliées reprennent leur place d'origine : après la
    //    précédente intégrée connue, sinon en tête.
    $ordre = array_keys($builtins);
    foreach ($ordre as $i => $k) {
        if (isset($liste[$k])) continue;
        $item = wsm_layout_item($which, $k, $builtins[$k], 1, null);
        $apres = null;
        for ($j = $i - 1; $j >= 0; $j--) if (isset($liste[$ordre[$j]])) { $apres = $ordre[$j]; break; }
        $liste = wsm_layout_inserer($liste, $k, $item, $apres);
    }
    // 3. Les sections du Kreator que la préférence ne connaît pas encore :
    //    après le dernier élément du Kreator déjà placé, sinon avant le B2B —
    //    dans l'ordre où elles ont été créées (sort_order).
    if ($which === 'home') {
        uasort($sekcje, fn($a, $b) => [(int) $a['sort_order'], (int) $a['id']] <=> [(int) $b['sort_order'], (int) $b['id']]);
        foreach ($sekcje as $id => $s) {
            $k = 'sekcja:' . $id;
            if (isset($liste[$k])) continue;
            $cles = array_keys($liste);
            $apres = null;
            foreach (array_reverse($cles) as $c) if (str_starts_with($c, 'sekcja:') || str_starts_with($c, 'blok:')) { $apres = $c; break; }
            if ($apres === null) { $pos = array_search('katalog', $cles, true); $apres = $pos !== false ? $cles[$pos] : array_key_last($liste); }
            $liste = wsm_layout_inserer($liste, $k, wsm_layout_item_sekcja($k, $s), $apres);
        }
    }
    // 3bis. Les blocs et pages que la préférence ne connaît pas encore.
    foreach ($pages as $id => $p) {
        if ($which === 'home') {
            if ($p['kind'] !== 'blok') continue;
            $k = 'blok:' . $id;
            if (isset($liste[$k])) continue;
            // L'ancien « emplacement » (première version de Strony) sert de
            // position de départ ; sans lui, avant le panneau B2B.
            $apres = match ((string) ($p['placement'] ?? '')) {
                'po_obietnicach' => isset($liste['obietnice']) ? 'obietnice' : 'hero',
                'przed_stopka'   => array_key_last($liste),
                default          => isset($liste['katalog']) ? 'katalog' : array_key_last($liste),
            };
            // Après le catalogue mais AVANT les blocs déjà posés là ? Non :
            // après eux — un bloc nouveau se range derrière ses aînés.
            if ($apres !== null && $apres !== array_key_last($liste)) {
                $cles = array_keys($liste);
                $pos = array_search($apres, $cles, true);
                while ($pos !== false && isset($cles[$pos + 1]) && str_starts_with($cles[$pos + 1], 'blok:')) { $pos++; $apres = $cles[$pos]; }
            }
            $liste = wsm_layout_inserer($liste, $k, wsm_layout_item($which, $k, [], 1, $p), $apres);
        } else {
            if ($p['kind'] !== 'strona') continue;
            $k = 'strona:' . $id;
            if (isset($liste[$k])) continue;
            // Une page entre dans la liste dès qu'elle est cochée pour cette
            // surface ; décochée, elle n'encombre pas l'écran.
            $flag = $which === 'nav' ? 'in_nav' : 'in_footer';
            if (!(int) ($p[$flag] ?? 0)) continue;
            $apres = $which === 'footer' && isset($liste['kontakt']) ? 'kontakt' : array_key_last($liste);
            if ($which === 'footer' && isset($liste['konsola'])) {
                // Avant le lien vers la console, qui ferme la liste.
                $cles = array_keys($liste); $pos = array_search('konsola', $cles, true);
                $apres = $pos > 0 ? $cles[$pos - 1] : null;
            }
            $liste = wsm_layout_inserer($liste, $k, wsm_layout_item($which, $k, [], 1, $p), $apres);
        }
    }
    // 4. Les épingles : le hero reste premier, le pasek juste derrière lui.
    if ($which === 'home') {
        $hero = $liste['hero'] ?? null; $pasek = $liste['pasek'] ?? null;
        unset($liste['hero'], $liste['pasek']);
        $liste = array_merge(array_filter(['hero' => $hero, 'pasek' => $pasek]), $liste);
    }
    return array_values($liste);
}

/** Une entrée « sekcja » du Kreator, pour la liste de l'accueil. */
function wsm_layout_item_sekcja(string $k, array $s): array {
    $typ = function_exists('wsm_section_types') ? (wsm_section_types()[(string) $s['type']]['label'] ?? (string) $s['type']) : (string) $s['type'];
    $titre = (string) ($s['title'] ?? '');
    return [
        'k' => $k, 'type' => 'sekcja', 'id' => (int) $s['id'],
        'on' => (int) ($s['published'] ?? 0) ? 1 : 0, 'published' => (int) ($s['published'] ?? 0),
        'label' => 'Sekcja — ' . $typ . ($titre !== '' ? ': ' . $titre : ''),
        'fixed' => false, 'pin' => null,
    ];
}

/** Une entrée de liste, normalisée. */
function wsm_layout_item(string $which, string $k, array $def, int $on, ?array $page): array {
    if ($page !== null) {
        $flag = $which === 'home' ? 'published' : ($which === 'nav' ? 'in_nav' : 'in_footer');
        $titre = (string) ($page['title'] ?? '');
        return [
            'k' => $k, 'type' => $page['kind'] === 'blok' ? 'blok' : 'strona', 'id' => (int) $page['id'],
            'on' => (int) ($page[$flag] ?? 0) && (int) ($page['published'] ?? 0) ? 1 : 0,
            'published' => (int) ($page['published'] ?? 0),
            'label' => ($page['kind'] === 'blok' ? 'Blok: ' : 'Strona: ') . ($titre !== '' ? $titre : '#' . (int) $page['id']),
            'fixed' => false, 'pin' => null,
        ];
    }
    return [
        'k' => $k, 'type' => 'builtin', 'id' => 0,
        'on' => !empty($def['fixed']) ? 1 : ($on ? 1 : 0),
        'published' => 1,
        'label' => (string) ($def['label'] ?? $k),
        'fixed' => !empty($def['fixed']), 'pin' => $def['pin'] ?? null,
    ];
}

/** Insère $item sous la clé $k juste après $apres (null = en tête). */
function wsm_layout_inserer(array $liste, string $k, array $item, ?string $apres): array {
    if ($apres === null || !isset($liste[$apres])) return [$k => $item] + $liste;
    $out = [];
    foreach ($liste as $kk => $v) { $out[$kk] = $v; if ($kk === $apres) $out[$k] = $item; }
    return $out;
}

/**
 * Déplace une entrée d'un cran. Les épinglées ne bougent pas, et rien ne
 * passe devant le hero. Renvoie false si rien n'a bougé.
 */
function wsm_layout_move(PDO $pdo, string $which, string $k, string $dir, string $actor = ''): bool {
    $liste = wsm_layout_get($pdo, $which);
    $cles = array_column($liste, 'k');
    $i = array_search($k, $cles, true);
    if ($i === false) return false;
    if (!empty($liste[$i]['pin'])) return false;
    $j = $dir === 'up' ? $i - 1 : $i + 1;
    if ($j < 0 || $j >= count($liste)) return false;
    if (!empty($liste[$j]['pin'])) return false;
    [$liste[$i], $liste[$j]] = [$liste[$j], $liste[$i]];
    wsm_layout_write($pdo, $which, $liste, $actor);
    return true;
}

/**
 * Montre ou cache une entrée. Pour une intégrée, c'est la préférence ; pour
 * un bloc, c'est « publié » ; pour une page, c'est « dans le menu » / « dans
 * la stopka » — la vérité reste chez la page. Une entrée fixe ne se cache
 * pas, et la fonction le dit (false).
 */
function wsm_layout_toggle(PDO $pdo, string $which, string $k, string $actor = ''): bool {
    $liste = wsm_layout_get($pdo, $which);
    foreach ($liste as &$it) {
        if ($it['k'] !== $k) continue;
        if ($it['fixed']) return false;
        if ($it['type'] === 'builtin') {
            $it['on'] = $it['on'] ? 0 : 1;
            wsm_layout_write($pdo, $which, $liste, $actor);
            return true;
        }
        if ($it['type'] === 'sekcja') {
            if (function_exists('wsm_section_toggle')) wsm_section_toggle($pdo, $it['id'], $actor);
            wsm_layout_write($pdo, $which, $liste, $actor);
            return true;
        }
        $col = $which === 'home' ? 'published' : ($which === 'nav' ? 'in_nav' : 'in_footer');
        $st = $pdo->prepare("SELECT $col FROM wsm_pages WHERE id = ?");
        $st->execute([$it['id']]);
        $cur = (int) $st->fetchColumn();
        $pdo->prepare("UPDATE wsm_pages SET $col = ?, updated_at = ?, updated_by = ? WHERE id = ?")
            ->execute([$cur ? 0 : 1, date('Y-m-d H:i:s'), mb_substr($actor, 0, 120), $it['id']]);
        // L'ordre est conservé même quand on décoche : la page revient à sa place.
        wsm_layout_write($pdo, $which, $liste, $actor);
        return true;
    }
    return false;
}

/** Les sections VISIBLES de l'accueil, dans l'ordre, pour la vitrine. */
function wsm_layout_home(PDO $pdo): array {
    return wsm_layout_get($pdo, 'home');
}

/**
 * Les liens d'une surface pour la vitrine : clés intégrées visibles et pages
 * publiées cochées, dans l'ordre. La vitrine met les adresses et les libellés
 * (elle seule connaît u() et la langue) ; ici, seulement QUOI et dans quel ordre.
 *
 * @return array<int, array{k:string, type:string, id:int, slug:string}>
 */
function wsm_layout_liens(PDO $pdo, string $which): array {
    $out = [];
    $pages = null;
    foreach (wsm_layout_get($pdo, $which) as $it) {
        if (!$it['on']) continue;
        if ($it['type'] === 'builtin') { $out[] = ['k' => $it['k'], 'type' => 'builtin', 'id' => 0, 'slug' => '']; continue; }
        $pages ??= wsm_layout_pages($pdo);
        $p = $pages[$it['id']] ?? null;
        if (!$p || !(int) $p['published'] || (string) ($p['slug'] ?? '') === '') continue;
        $out[] = ['k' => $it['k'], 'type' => 'strona', 'id' => $it['id'], 'slug' => (string) $p['slug']];
    }
    return $out;
}

/** Le marqueur que la page imprime à la place d'une section cachée : les contrôles le lisent. */
function wsm_layout_marker(string $k): string {
    return match ($k) {
        'pro'       => '<!-- sekcja pro: ukryta w Układzie strony -->',
        'kontakt'   => '<!-- kontakt: ukryty w Układzie strony -->',
        'obietnice' => '<!-- obietnice: ukryte w Układzie strony -->',
        'pasek'     => '<!-- pasek: ukryty w Układzie strony -->',
        default     => '<!-- ' . $k . ': ukryte w Układzie strony -->',
    };
}
