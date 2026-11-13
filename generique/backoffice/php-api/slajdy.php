<?php
// ============================================================================
//  slajdy.php — les slajdy du haut de la page d'accueil.
//
//  L'équipe voulait « 4-5 zdjęć, które co jakiś czas się zmieniają », la main
//  sur le défilement (automatique ou non), et des NOUVELLES datées : « od … do
//  25 grudnia rabaty świąteczne ». Un slajd = une photo, des textes par langue
//  (sur-titre, titre, phrase, bouton), un lien, et une période facultative.
//
//  QUATRE RÈGLES :
//
//   1. LA DATE EST CELLE DE VARSOVIE. Le serveur tourne en UTC : entre minuit
//      et une heure du matin (deux en été), « dziś » serait encore hier, et la
//      promotion de Noël partirait une heure trop tard.
//   2. UNE DATE ABSENTE EST NULL, jamais '' — MySQL strict refuse une date
//      vide (c'est ce qui avait cassé « Wysłane » en production).
//   3. « 31 listopada » N'EXISTE PAS. Une date se vérifie au calendrier
//      (checkdate) : une date impossible est refusée avec le motif, pas
//      corrigée en silence au 1er décembre.
//   4. SANS SLAJD EN LIGNE, la page garde son haut de page d'origine (les
//      textes home.* de Treści) : une période terminée ne laisse jamais un
//      trou noir en haut de la boutique.
//
//  Le premier slajd — le boulanger, « Pewne składniki. Udane wypieki. » — est
//  posé UNE fois, à la création des tables (marqueur slajdy.zalozone) : s'il
//  est supprimé ensuite, il ne revient pas.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Le défilement automatique : intervalle par défaut, bornes. */
const WSM_SLAJDY_CO_DOMYSLNIE = 5;
const WSM_SLAJDY_CO_MIN = 3;
const WSM_SLAJDY_CO_MAX = 30;
/** Les langues des textes ; les autres retombent sur le polonais. */
const WSM_SLAJDY_JEZYKI = ['pl', 'uk', 'en'];
/** Longueurs = colonnes (schéma) : au-delà, MySQL refuserait la ligne. */
const WSM_SLAJDY_MAX = ['eyebrow' => 120, 'title' => 200, 'tekst' => 400, 'cta_label' => 80];
/** La photo livrée avec le premier slajd (shop/assets/). */
const WSM_SLAJD_PIERWSZY_FOTO = 'assets/hero-piekarz.webp';

/** « Dziś » à Varsovie, AAAA-MM-JJ. */
function wsm_slajdy_dzis(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
}

/**
 * Les tables, et le premier slajd une seule fois. Appelé au démarrage
 * (wsm_ensure_nouvelles_tables) : une base qui a déjà son marqueur ne paie
 * qu'une lecture.
 */
function wsm_slajdy_ensure(PDO $pdo): void {
    try {
        $st = $pdo->prepare("SELECT 1 FROM wsm_settings WHERE cle = 'slajdy.zalozone'");
        $st->execute();
        if ($st->fetchColumn()) return;
    } catch (Throwable $e) { return; }                 // pas de table de réglages : trop tôt
    if (!wsm_table_exists($pdo, 'wsm_slides') || !wsm_table_exists($pdo, 'wsm_slide_i18n')) wsm_apply_schema($pdo);
    try {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM wsm_slides")->fetchColumn();
        if ($n === 0) wsm_slajd_pierwszy($pdo);
        $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES ('slajdy.zalozone', '1', 0, ?, 'wdrozenie')")
            ->execute([date('Y-m-d H:i:s')]);
    } catch (Throwable $e) { /* posé par un passage concurrent : c'est fait */ }
}

/**
 * Le premier slajd : la photo du boulanger et les textes home.* livrés dans
 * shop/content_seed.json — le fichier, pas la base : au premier passage
 * après un déploiement, la base peut encore porter les anciens textes.
 */
function wsm_slajd_pierwszy(PDO $pdo): void {
    $teksty = [];
    $plik = __DIR__ . '/../../shop/content_seed.json';
    $j = is_file($plik) ? json_decode((string) file_get_contents($plik), true) : null;
    foreach (WSM_SLAJDY_JEZYKI as $l) {
        $s = (array) ($j['strings'][$l] ?? []);
        $teksty[$l] = ['eyebrow' => (string) ($s['home.eyebrow'] ?? ''), 'title' => (string) ($s['home.title'] ?? ''),
                       'tekst' => (string) ($s['home.lead'] ?? ''), 'cta_label' => (string) ($s['home.cta'] ?? '')];
    }
    if (($teksty['pl']['title'] ?? '') === '') {
        $teksty['pl'] = ['eyebrow' => 'Manufaktura · małe partie', 'title' => 'Pewne składniki. Udane wypieki.',
                         'tekst' => 'Zaopatrz piekarnię lub cukiernię w produkty do codziennej pracy.', 'cta_label' => 'Zobacz produkty'];
    }
    wsm_tx($pdo, function () use ($pdo, $teksty): void {
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO wsm_slides (sort_order, active, image_url, link, date_from, date_to, created_at, updated_at, updated_by)
                       VALUES (10, 1, ?, '#katalog', NULL, NULL, ?, ?, 'wdrozenie')")
            ->execute([WSM_SLAJD_PIERWSZY_FOTO, $now, $now]);
        $id = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO wsm_slide_i18n (slide_id, lang, eyebrow, title, tekst, cta_label) VALUES (?,?,?,?,?,?)");
        foreach ($teksty as $l => $t) {
            if (implode('', $t) === '') continue;
            $ins->execute([$id, $l, mb_substr($t['eyebrow'], 0, WSM_SLAJDY_MAX['eyebrow']), mb_substr($t['title'], 0, WSM_SLAJDY_MAX['title']),
                           mb_substr($t['tekst'], 0, WSM_SLAJDY_MAX['tekst']), mb_substr($t['cta_label'], 0, WSM_SLAJDY_MAX['cta_label'])]);
        }
    });
}

/** Les réglages du défilement : automatique ou non, et toutes les combien de secondes. */
function wsm_slajdy_ustawienia(PDO $pdo): array {
    $auto = true; $co = WSM_SLAJDY_CO_DOMYSLNIE;
    try {
        foreach ($pdo->query("SELECT cle, val FROM wsm_settings WHERE cle IN ('slajdy.auto', 'slajdy.co')")->fetchAll() ?: [] as $r) {
            if ($r['cle'] === 'slajdy.auto') $auto = (string) $r['val'] !== '0';
            if ($r['cle'] === 'slajdy.co') $co = (int) $r['val'];
        }
    } catch (Throwable $e) {}
    return ['auto' => $auto, 'co' => max(WSM_SLAJDY_CO_MIN, min(WSM_SLAJDY_CO_MAX, $co ?: WSM_SLAJDY_CO_DOMYSLNIE))];
}

/** @return string '' si enregistré, sinon le motif du refus */
function wsm_slajdy_ustawienia_zapisz(PDO $pdo, bool $auto, int $co, string $actor): string {
    if ($co < WSM_SLAJDY_CO_MIN || $co > WSM_SLAJDY_CO_MAX) {
        return 'Czas wyświetlania: od ' . WSM_SLAJDY_CO_MIN . ' do ' . WSM_SLAJDY_CO_MAX . ' sekund.';
    }
    wsm_tx($pdo, function () use ($pdo, $auto, $co, $actor): void {
        $now = date('Y-m-d H:i:s');
        foreach (['slajdy.auto' => $auto ? '1' : '0', 'slajdy.co' => (string) $co] as $k => $v) {
            $pdo->prepare("DELETE FROM wsm_settings WHERE cle = ?")->execute([$k]);
            $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?, ?, 0, ?, ?)")
                ->execute([$k, $v, $now, mb_substr($actor, 0, 120)]);
        }
    });
    return '';
}

/** Les textes d'un slajd par langue : ['pl' => ['eyebrow' => …, …], …]. */
function wsm_slajd_teksty(PDO $pdo, int $id): array {
    $out = [];
    $st = $pdo->prepare("SELECT lang, eyebrow, title, tekst, cta_label FROM wsm_slide_i18n WHERE slide_id = ?");
    $st->execute([$id]);
    foreach ($st->fetchAll() as $r) {
        $out[(string) $r['lang']] = ['eyebrow' => (string) $r['eyebrow'], 'title' => (string) $r['title'],
                                     'tekst' => (string) $r['tekst'], 'cta_label' => (string) $r['cta_label']];
    }
    return $out;
}

/** Tous les slajdy, dans l'ordre, avec leurs textes — pour la console. */
function wsm_slajdy_lista(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT * FROM wsm_slides ORDER BY sort_order, id")->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['active'] = (int) $r['active'];
        $r['date_from'] = $r['date_from'] !== null ? substr((string) $r['date_from'], 0, 10) : '';
        $r['date_to'] = $r['date_to'] !== null ? substr((string) $r['date_to'], 0, 10) : '';
        $r['teksty'] = wsm_slajd_teksty($pdo, $r['id']);
    }
    unset($r);
    return $rows;
}

/**
 * Où en est un slajd aujourd'hui : 'teraz' (en ligne), 'plan' (commence plus
 * tard), 'koniec' (période finie), 'ukryty' (désactivé à la main).
 */
function wsm_slajd_stan(array $s, string $dzis): string {
    if (!(int) $s['active']) return 'ukryty';
    if (($s['date_from'] ?? '') !== '' && $s['date_from'] > $dzis) return 'plan';
    if (($s['date_to'] ?? '') !== '' && $s['date_to'] < $dzis) return 'koniec';
    return 'teraz';
}

/**
 * Les slajdy EN LIGNE aujourd'hui, textes résolus dans la langue du visiteur
 * (repli : polonais). La comparaison de dates se fait en PHP, sur la date de
 * Varsovie : même résultat sur MySQL et SQLite, et pas de date '' possible.
 */
function wsm_slajdy_widoczne(PDO $pdo, string $lang, ?string $dzis = null): array {
    $dzis ??= wsm_slajdy_dzis();
    $out = [];
    foreach (wsm_slajdy_lista($pdo) as $s) {
        if (wsm_slajd_stan($s, $dzis) !== 'teraz') continue;
        $t = $s['teksty'][$lang] ?? [];
        $pl = $s['teksty']['pl'] ?? [];
        $pick = fn(string $k) => trim((string) ($t[$k] ?? '')) !== '' ? (string) $t[$k] : (string) ($pl[$k] ?? '');
        $out[] = ['id' => $s['id'], 'image' => (string) $s['image_url'], 'link' => (string) $s['link'],
                  'eyebrow' => $pick('eyebrow'), 'title' => $pick('title'), 'tekst' => $pick('tekst'),
                  'cta' => $pick('cta_label')];
    }
    return $out;
}

/** Une date du formulaire : '' (aucune), 'AAAA-MM-JJ' valide, ou null (impossible). */
function wsm_slajd_data(string $v): ?string {
    $v = trim($v);
    if ($v === '') return '';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return null;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
}

/** Un lien de slajd : ancre, chemin de la boutique, ou adresse http(s). */
function wsm_slajd_link_ok(string $l): bool {
    if ($l === '') return true;
    if (strlen($l) > 255 || preg_match('/[\s"\'<>\\\\`]/', $l) || str_starts_with($l, '//')) return false;
    // Un schéma (« xxx: ») n'est permis que s'il est http ou https :
    // « javascript: » dans un lien de slajd s'exécuterait chez chaque visiteur.
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $l)) return (bool) preg_match('~^https?://[^/]+~i', $l);
    return true;
}

/**
 * Enregistre un slajd (création si $id = 0). $in : image_url, link,
 * date_from, date_to, active, teksty[lang][champ].
 *
 * @return array{0:int,1:array<string,string>} [id, erreurs par champ]
 */
function wsm_slajd_zapisz(PDO $pdo, array $in, int $id, string $actor): array {
    $e = [];
    $img  = trim((string) ($in['image_url'] ?? ''));
    $link = trim((string) ($in['link'] ?? ''));
    $od = wsm_slajd_data((string) ($in['date_from'] ?? ''));
    $do = wsm_slajd_data((string) ($in['date_to'] ?? ''));
    if ($od === null) $e['date_from'] = 'Taka data nie istnieje w kalendarzu.';
    if ($do === null) $e['date_to'] = 'Taka data nie istnieje w kalendarzu.';
    if ($od && $do && $do < $od) $e['date_to'] = 'Koniec nie może być przed początkiem.';
    if (strlen($img) > 255) $e['image_url'] = 'Adres zdjęcia jest za długi.';
    elseif ($img !== '' && (preg_match('~["\'<>\s`]~', $img) || (preg_match('~^[a-z][a-z0-9+.-]*:~i', $img) && !preg_match('~^https?://~i', $img)))) {
        $e['image_url'] = 'Nieprawidłowy adres zdjęcia.';
    }
    if (!wsm_slajd_link_ok($link)) $e['link'] = 'Link: #kotwica, ścieżka sklepu (np. p/czekolada) albo adres https://…';

    $teksty = [];
    foreach (WSM_SLAJDY_JEZYKI as $l) {
        foreach (WSM_SLAJDY_MAX as $k => $max) {
            $v = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['teksty'][$l][$k] ?? '')));
            if ($k !== 'tekst') $v = str_replace("\n", ' ', $v);
            if (mb_strlen($v) > $max) $e["$l.$k"] = 'Najwyżej ' . $max . ' znaków.';
            $teksty[$l][$k] = $v;
        }
    }
    if ($img === '' && implode('', $teksty['pl']) === '') {
        $e['image_url'] = 'Slajd potrzebuje zdjęcia albo tytułu.';
    }
    if ($id > 0) {
        $st = $pdo->prepare("SELECT 1 FROM wsm_slides WHERE id = ?");
        $st->execute([$id]);
        if (!$st->fetchColumn()) $e['id'] = 'Tego slajdu już nie ma.';
    }
    if ($e) return [$id, $e];

    $actor = mb_substr($actor, 0, 120);
    $id = wsm_tx($pdo, function () use ($pdo, $id, $img, $link, $od, $do, $in, $teksty, $actor): int {
        $now = date('Y-m-d H:i:s');
        $vals = [$img, $link, $od !== '' ? $od : null, $do !== '' ? $do : null, !empty($in['active']) ? 1 : 0];
        if ($id > 0) {
            $pdo->prepare("UPDATE wsm_slides SET image_url = ?, link = ?, date_from = ?, date_to = ?, active = ?, updated_at = ?, updated_by = ? WHERE id = ?")
                ->execute([...$vals, $now, $actor, $id]);
        } else {
            $max = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM wsm_slides")->fetchColumn();
            $pdo->prepare("INSERT INTO wsm_slides (image_url, link, date_from, date_to, active, sort_order, created_at, updated_at, updated_by)
                           VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([...$vals, $max + 10, $now, $now, $actor]);
            $id = (int) $pdo->lastInsertId();
        }
        $pdo->prepare("DELETE FROM wsm_slide_i18n WHERE slide_id = ?")->execute([$id]);
        $ins = $pdo->prepare("INSERT INTO wsm_slide_i18n (slide_id, lang, eyebrow, title, tekst, cta_label) VALUES (?,?,?,?,?,?)");
        foreach ($teksty as $l => $t) {
            if (implode('', $t) === '') continue;
            $ins->execute([$id, $l, $t['eyebrow'], $t['title'], $t['tekst'], $t['cta_label']]);
        }
        return $id;
    });
    return [$id, []];
}

function wsm_slajd_usun(PDO $pdo, int $id): bool {
    return (bool) wsm_tx($pdo, function () use ($pdo, $id): bool {
        $pdo->prepare("DELETE FROM wsm_slide_i18n WHERE slide_id = ?")->execute([$id]);
        $st = $pdo->prepare("DELETE FROM wsm_slides WHERE id = ?");
        $st->execute([$id]);
        return $st->rowCount() > 0;
    });
}

/** Montre ou cache un slajd. */
function wsm_slajd_przelacz(PDO $pdo, int $id, string $actor): bool {
    $st = $pdo->prepare("UPDATE wsm_slides SET active = 1 - active, updated_at = ?, updated_by = ? WHERE id = ?");
    $st->execute([date('Y-m-d H:i:s'), mb_substr($actor, 0, 120), $id]);
    return $st->rowCount() > 0;
}

/** Monte ou descend un slajd d'un cran ; l'ordre est renuméroté 10, 20, 30… */
function wsm_slajd_przesun(PDO $pdo, int $id, string $dir): bool {
    $ids = array_map('intval', $pdo->query("SELECT id FROM wsm_slides ORDER BY sort_order, id")->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $i = array_search($id, $ids, true);
    if ($i === false) return false;
    $j = $dir === 'up' ? $i - 1 : $i + 1;
    if ($j < 0 || $j >= count($ids)) return false;
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    wsm_tx($pdo, function () use ($pdo, $ids): void {
        $up = $pdo->prepare("UPDATE wsm_slides SET sort_order = ? WHERE id = ?");
        foreach ($ids as $k => $sid) $up->execute([($k + 1) * 10, $sid]);
    });
    return true;
}

/** Les photos des slajdy, pour la médiathèque (« où cette image sert-elle ? »). */
function wsm_slajdy_media_cites(PDO $pdo): array {
    $u = [];
    try {
        foreach ($pdo->query("SELECT s.id, s.image_url, i.title FROM wsm_slides s
                               LEFT JOIN wsm_slide_i18n i ON i.slide_id = s.id AND i.lang = 'pl'
                              WHERE s.image_url <> ''")->fetchAll() ?: [] as $r) {
            $u[(string) $r['image_url']][] = 'slajd: ' . ((string) ($r['title'] ?? '') !== '' ? (string) $r['title'] : '#' . (int) $r['id']);
        }
    } catch (Throwable $e) {}
    return $u;
}
