<?php
// ============================================================================
//  social.php — les posts Facebook et Instagram, écrits ici, publiés par Meta.
//
//  UN POST, DEUX CANAUX, DEUX RÉSULTATS. Facebook peut accepter ce
//  qu'Instagram refuse (une photo trop étroite, un lien d'image injoignable).
//  Chaque canal garde donc son état, son identifiant, son lien et son erreur ;
//  « Ponów » ne republie QUE le canal qui a échoué — sinon la page Facebook
//  recevrait le même post deux fois.
//
//  PUBLIER, C'EST RÉCLAMER D'ABORD. Le post passe de « zaplanowany » à
//  « publikowanie » par un UPDATE conditionnel : la pompe de fond et le
//  bouton « Opublikuj teraz », lancés ensemble, ne publient pas deux fois.
//  Un post resté « publikowanie » plus d'un quart d'heure n'est PAS relancé
//  tout seul : on ne sait pas si Meta l'a reçu. Il passe en « błąd » avec
//  la consigne de vérifier la page avant de relancer.
//
//  INSTAGRAM VA CHERCHER LA PHOTO LUI-MÊME, à l'adresse qu'on lui donne :
//  il faut une adresse https publique, avec un vrai certificat. Une boutique
//  servie sur une adresse IP ne le permet pas ; l'écran le dit avant qu'on
//  essaie. Facebook, lui, reçoit le fichier envoyé directement.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/reklama.php';
require_once __DIR__ . '/mediateka.php';

const WSM_SOCIAL_MAX_ZDJEC   = 10;
const WSM_SOCIAL_MAX_ZNAKOW  = 2200;   // la limite d'Instagram ; Facebook en accepte plus
const WSM_SOCIAL_MAX_HASHTAG = 30;     // au-delà, Instagram refuse le post entier
const WSM_SOCIAL_ZAWIESZONY  = 900;    // secondes avant qu'un « publikowanie » soit déclaré interrompu

const WSM_SOCIAL_STATUSY = [
    'szkic' => 'Szkic', 'zaplanowany' => 'Zaplanowany', 'publikowanie' => 'Publikowanie…',
    'opublikowany' => 'Opublikowany', 'czesciowo' => 'Częściowo', 'blad' => 'Błąd',
];

function wsm_social_ensure(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_social_posts')) wsm_apply_schema($pdo);
}

/** Ce que Meta permet aujourd'hui, canal par canal. */
function wsm_social_cfg(): array {
    $c = wsm_ads_cfg();
    return ['page_id' => $c['page_id'], 'token' => $c['page_token'], 'ig' => $c['ig'], 'graph' => $c['graph'],
            'fb' => $c['page_id'] !== '' && $c['page_token'] !== '',
            'ig_ok' => $c['ig'] !== '' && $c['page_token'] !== ''];
}

/**
 * Instagram peut-il venir chercher nos photos ? Il faut une adresse https
 * sur un NOM de domaine : une adresse IP n'a pas de certificat reconnu.
 * @return string '' si oui, sinon la raison
 */
function wsm_social_ig_adres(string $shopUrl): string {
    $p = parse_url($shopUrl);
    $host = (string) ($p['host'] ?? '');
    if (($p['scheme'] ?? '') !== 'https') return 'sklep nie jest pod adresem https — Instagram nie pobierze zdjęć';
    if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
        return 'sklep działa pod adresem IP (' . $host . ') — Instagram pobiera zdjęcia tylko z domeny z certyfikatem; podłącz domenę';
    }
    return '';
}

/** Les hashtags d'un texte, sans doublon, dans l'ordre. */
function wsm_social_hashtagi(string $t): array {
    preg_match_all('/#([\p{L}\p{N}_]+)/u', $t, $m);
    return array_values(array_unique(array_map(fn($x) => mb_strtolower($x, 'UTF-8'), $m[1] ?? [])));
}

/**
 * Vérifie et nettoie ce que l'écran envoie.
 * @return array{0:array, 1:array} [post propre, erreurs champ => message]
 */
function wsm_social_clean(PDO $pdo, array $in): array {
    $err = [];
    $tekst = str_replace(["\r\n", "\r"], "\n", (string) ($in['tekst'] ?? ''));
    // Seuls les caractères de contrôle partent : les jointures invisibles
    // (U+200D) qui composent un emoji restent, sinon 👩‍🍳 deviendrait deux images.
    $tekst = trim(preg_replace('/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{7F}]/u', '', $tekst) ?? '');
    if (mb_strlen($tekst) > WSM_SOCIAL_MAX_ZNAKOW) $err['tekst'] = 'Najwyżej ' . WSM_SOCIAL_MAX_ZNAKOW . ' znaków (limit Instagrama) — jest ' . mb_strlen($tekst) . '.';
    if (count(wsm_social_hashtagi($tekst)) > WSM_SOCIAL_MAX_HASHTAG) $err['tekst'] = 'Najwyżej ' . WSM_SOCIAL_MAX_HASHTAG . ' hashtagów — Instagram odrzuci cały post.';
    $media = [];
    foreach ((array) ($in['media'] ?? []) as $u) {
        $u = (string) $u;
        if (!wsm_media_own_url($u) || in_array($u, $media, true)) continue;
        if (!is_file(wsm_media_dir() . '/' . substr($u, 6))) continue;
        $media[] = $u;
    }
    if (count($media) > WSM_SOCIAL_MAX_ZDJEC) $err['media'] = 'Najwyżej ' . WSM_SOCIAL_MAX_ZDJEC . ' zdjęć w jednym poście.';
    $kanaly = array_values(array_intersect(['fb', 'ig'], array_map('strval', (array) ($in['kanaly'] ?? []))));
    if (!$kanaly) $err['kanaly'] = 'Zaznacz Facebook, Instagram albo oba.';
    if (in_array('ig', $kanaly, true) && !$media) $err['media'] = 'Instagram wymaga co najmniej jednego zdjęcia.';
    if ($tekst === '' && !$media) $err['tekst'] = 'Post bez tekstu i bez zdjęcia nie ma czego pokazać.';
    $pid = trim((string) ($in['product_id'] ?? ''));
    if ($pid !== '') {
        $st = $pdo->prepare("SELECT id FROM wsm_products WHERE id = ?");
        $st->execute([$pid]);
        if ($st->fetchColumn() === false) { $err['product_id'] = 'Nieznany produkt.'; $pid = ''; }
    }
    $termin = null;
    $raw = trim((string) ($in['termin'] ?? ''));
    if ($raw !== '') {
        $d = DateTime::createFromFormat('Y-m-d\TH:i', $raw) ?: DateTime::createFromFormat('Y-m-d H:i', $raw) ?: DateTime::createFromFormat('Y-m-d H:i:s', $raw);
        if (!$d) $err['termin'] = 'Data w formacie RRRR-MM-DD GG:MM.';
        else $termin = $d->format('Y-m-d H:i:00');
    }
    return [['tekst' => $tekst, 'media' => $media, 'kanaly' => $kanaly, 'product_id' => $pid, 'termin' => $termin], $err];
}

function wsm_social_get(PDO $pdo, int $id): ?array {
    wsm_social_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM wsm_social_posts WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['media_list'] = json_decode((string) $r['media'], true) ?: [];
    $r['kanaly_list'] = array_values(array_filter(explode(',', (string) $r['kanaly'])));
    return $r;
}

/**
 * Enregistre un post.
 *   $akcja : 'szkic' (garder), 'zaplanuj' (publier à la date), 'teraz' (publier maintenant)
 *
 * UN POST PUBLIÉ NE SE RÉÉCRIT PAS. Meta n'offre pas de modification de la
 * photo, et changer le texte ici laisserait la console dire autre chose que
 * la page. On duplique, on ne réécrit pas.
 *
 * @return array{0:int, 1:array} [id, erreurs]
 */
function wsm_social_save(PDO $pdo, ?int $id, array $in, string $akcja, string $actor): array {
    wsm_social_ensure($pdo);
    [$p, $err] = wsm_social_clean($pdo, $in);
    if ($id) {
        $old = wsm_social_get($pdo, $id);
        if (!$old) return [0, ['_' => 'Nie ma takiego posta.']];
        if (!in_array((string) $old['status'], ['szkic', 'zaplanowany', 'blad'], true)) return [$id, ['_' => 'Ten post jest już opublikowany (albo w trakcie) — zduplikuj go, zamiast zmieniać.']];
    }
    if ($akcja === 'zaplanuj') {
        if ($p['termin'] === null) $err['termin'] = 'Podaj datę i godzinę publikacji.';
        elseif (strtotime($p['termin']) < time() - 300) $err['termin'] = 'Ta data już minęła — wybierz „Opublikuj teraz” albo późniejszą godzinę.';
    }
    if ($akcja !== 'szkic') {
        $cfg = wsm_social_cfg();
        if (in_array('fb', $p['kanaly'], true) && !$cfg['fb']) $err['kanaly'] = 'Facebook nie jest połączony (Marketing → Meta: ID strony i token).';
        if (in_array('ig', $p['kanaly'], true) && !$cfg['ig_ok']) $err['kanaly'] = 'Instagram nie jest połączony (Marketing → Meta: ID konta Instagram).';
    }
    if ($err) return [$id ?? 0, $err];
    $status = $akcja === 'szkic' ? 'szkic' : 'zaplanowany';
    $termin = $akcja === 'teraz' ? date('Y-m-d H:i:s') : $p['termin'];
    $now = date('Y-m-d H:i:s');
    $vals = [$p['tekst'], json_encode($p['media'], JSON_UNESCAPED_SLASHES), $p['product_id'], implode(',', $p['kanaly']), $termin, $status, $now];
    if ($id) {
        // Un canal déjà publié (post « błąd » à moitié passé) garde son état :
        // le republier ferait un doublon.
        $pdo->prepare("UPDATE wsm_social_posts SET tekst=?, media=?, product_id=?, kanaly=?, termin=?, status=?, updated_at=?,
                              fb_status = CASE WHEN fb_status = 'ok' THEN 'ok' ELSE '' END,
                              ig_status = CASE WHEN ig_status = 'ok' THEN 'ok' ELSE '' END,
                              fb_error = '', ig_error = ''
                        WHERE id = ? AND status IN ('szkic','zaplanowany','blad')")
            ->execute(array_merge($vals, [$id]));
    } else {
        $pdo->prepare("INSERT INTO wsm_social_posts (tekst, media, product_id, kanaly, termin, status, updated_at, created_by, created_at)
                       VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute(array_merge($vals, [mb_substr($actor, 0, 120), $now]));
        $id = (int) $pdo->lastInsertId();
    }
    // Le lien suivi vers le produit : il dira combien de clics et de
    // commandes le post a apportés (même mécanique que la newsletter).
    if ($p['product_id'] !== '' && in_array('fb', $p['kanaly'], true)) {
        try {
            require_once __DIR__ . '/marketing.php';
            [$code] = wsm_mkt_link($pdo, 'fb' . $id, 'Post Facebook #' . $id);
            if ($code !== '') $pdo->prepare("UPDATE wsm_social_posts SET link_code = ? WHERE id = ?")->execute([$code, $id]);
        } catch (Throwable $e) { /* le post vaut sans lien suivi */ }
    }
    return [$id, []];
}

/** Duplique un post (publié ou non) en un nouveau brouillon. */
function wsm_social_duplicate(PDO $pdo, int $id, string $actor): int {
    $p = wsm_social_get($pdo, $id);
    if (!$p) return 0;
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO wsm_social_posts (tekst, media, product_id, kanaly, status, created_by, created_at, updated_at)
                   VALUES (?,?,?,?, 'szkic', ?,?,?)")
        ->execute([$p['tekst'], $p['media'], $p['product_id'], $p['kanaly'], mb_substr($actor, 0, 120), $now, $now]);
    return (int) $pdo->lastInsertId();
}

function wsm_social_delete(PDO $pdo, int $id): bool {
    $d = $pdo->prepare("DELETE FROM wsm_social_posts WHERE id = ? AND status IN ('szkic','zaplanowany','blad')");
    $d->execute([$id]);
    return $d->rowCount() > 0;
}

/** Le texte tel qu'il part sur Facebook : avec le lien suivi du produit, s'il y en a un. */
function wsm_social_fb_text(PDO $pdo, array $post): string {
    $t = (string) $post['tekst'];
    if ((string) $post['product_id'] !== '') {
        require_once __DIR__ . '/shop.php';
        require_once __DIR__ . '/marketing.php';
        $p = wsm_shop_product($pdo, (string) $post['product_id'], 'pl');
        if ($p) {
            $url = wsm_mkt_url('p/' . $p['slug'], (string) $post['link_code']);
            if (!str_contains($t, $url)) $t = rtrim($t) . ($t !== '' ? "\n\n" : '') . '👉 ' . $url;
        }
    }
    return $t;
}

/** Facebook : un texte seul, une photo, ou plusieurs photos dans un même post. */
function wsm_social_publish_fb(PDO $pdo, array $post, array $cfg): array {
    $msg = wsm_social_fb_text($pdo, $post);
    $media = $post['media_list'];
    $call = fn(string $path, array $params, array $files = []) => wsm_graph_call('POST', $path, $params, $cfg['token'], $files, $cfg['graph']);
    $fichier = function (string $u): ?string {
        $j = wsm_media_jpg($u, 'feed');
        return $j ? wsm_media_dir() . '/' . substr($j, 6) : null;
    };
    if (!$media) {
        $r = $call($cfg['page_id'] . '/feed', ['message' => $msg]);
        $pid = (string) ($r['data']['id'] ?? '');
    } elseif (count($media) === 1) {
        $f = $fichier($media[0]);
        if (!$f) return ['ok' => false, 'error' => 'nie udało się przygotować zdjęcia (JPEG)'];
        $r = $call($cfg['page_id'] . '/photos', ['message' => $msg, 'published' => 'true'], ['source' => $f]);
        $pid = (string) ($r['data']['post_id'] ?? ($r['data']['id'] ?? ''));
    } else {
        // Plusieurs photos : chacune déposée NON publiée, puis un seul post
        // qui les rassemble. Les publier une à une ferait dix posts.
        $ids = [];
        foreach ($media as $u) {
            $f = $fichier($u);
            if (!$f) return ['ok' => false, 'error' => 'nie udało się przygotować zdjęcia (JPEG)'];
            $up = $call($cfg['page_id'] . '/photos', ['published' => 'false'], ['source' => $f]);
            if (!$up['ok']) return ['ok' => false, 'error' => 'zdjęcie: ' . $up['error']];
            $ids[] = ['media_fbid' => (string) ($up['data']['id'] ?? '')];
        }
        $r = $call($cfg['page_id'] . '/feed', ['message' => $msg, 'attached_media' => $ids]);
        $pid = (string) ($r['data']['id'] ?? '');
    }
    if (!$r['ok'] || $pid === '') return ['ok' => false, 'error' => $r['error'] !== '' ? $r['error'] : 'brak identyfikatora posta'];
    return ['ok' => true, 'id' => $pid, 'url' => 'https://www.facebook.com/' . rawurlencode($pid)];
}

/** Une petite pause, remplaçable par les tests (Instagram prépare l'image avant publication). */
function wsm_social_sleep(?callable $set = null): callable {
    static $f = null;
    if ($set !== null) { $f = $set; return $f; }
    return $f ?? static function (int $s): void { sleep($s); };
}

/** Instagram : un conteneur par photo (carrousel au-delà d'une), puis la publication. */
function wsm_social_publish_ig(PDO $pdo, array $post, array $cfg, string $shopUrl): array {
    $why = wsm_social_ig_adres($shopUrl);
    if ($why !== '') return ['ok' => false, 'error' => $why];
    $media = $post['media_list'];
    if (!$media) return ['ok' => false, 'error' => 'Instagram wymaga zdjęcia'];
    $call = fn(string $m, string $path, array $params) => wsm_graph_call($m, $path, $params, $cfg['token'], [], $cfg['graph']);
    $urls = [];
    foreach ($media as $u) {
        $j = wsm_media_jpg($u, 'ig');
        if (!$j) return ['ok' => false, 'error' => 'nie udało się przygotować zdjęcia (JPEG 4:5–1,91:1)'];
        $urls[] = rtrim($shopUrl, '/') . '/' . $j;
    }
    $caption = (string) $post['tekst'];
    if (count($urls) === 1) {
        $c = $call('POST', $cfg['ig'] . '/media', ['image_url' => $urls[0], 'caption' => $caption]);
    } else {
        $kids = [];
        foreach ($urls as $u) {
            $k = $call('POST', $cfg['ig'] . '/media', ['image_url' => $u, 'is_carousel_item' => 'true']);
            if (!$k['ok']) return ['ok' => false, 'error' => 'zdjęcie: ' . $k['error']];
            $kids[] = (string) ($k['data']['id'] ?? '');
        }
        $c = $call('POST', $cfg['ig'] . '/media', ['media_type' => 'CAROUSEL', 'children' => implode(',', $kids), 'caption' => $caption]);
    }
    if (!$c['ok']) return ['ok' => false, 'error' => $c['error']];
    $cid = (string) ($c['data']['id'] ?? '');
    // Instagram télécharge et prépare l'image : on attend « FINISHED »
    // (quelques secondes au plus pour une photo) avant de publier.
    for ($i = 0; $i < 6; $i++) {
        $s = $call('GET', $cid, ['fields' => 'status_code']);
        $code = (string) ($s['data']['status_code'] ?? '');
        if ($code === 'FINISHED') break;
        if ($code === 'ERROR' || $code === 'EXPIRED') return ['ok' => false, 'error' => 'Instagram nie przyjął zdjęcia (' . $code . ') — sprawdź, czy adres zdjęcia otwiera się z internetu'];
        (wsm_social_sleep())(2);
    }
    $pub = $call('POST', $cfg['ig'] . '/media_publish', ['creation_id' => $cid]);
    if (!$pub['ok']) return ['ok' => false, 'error' => $pub['error']];
    $mid = (string) ($pub['data']['id'] ?? '');
    $perma = $call('GET', $mid, ['fields' => 'permalink']);
    $url = (string) ($perma['data']['permalink'] ?? '');
    return ['ok' => true, 'id' => $mid, 'url' => preg_match('#^https://(www\.)?instagram\.com/#', $url) ? $url : ''];
}

/**
 * Publie un post dû. Renvoie l'état final ('' si le post n'a pas pu être réclamé).
 *
 * @param bool $force  publier même si la date n'est pas encore là (« Opublikuj teraz »)
 */
function wsm_social_publish(PDO $pdo, int $id, string $shopUrl, bool $force = false): string {
    wsm_social_ensure($pdo);
    $now = date('Y-m-d H:i:s');
    $sql = "UPDATE wsm_social_posts SET status = 'publikowanie', claimed_at = ? WHERE id = ? AND status = 'zaplanowany'"
         . ($force ? '' : " AND termin IS NOT NULL AND termin <= ?");
    $claim = $pdo->prepare($sql);
    $claim->execute($force ? [$now, $id] : [$now, $id, $now]);
    if ($claim->rowCount() === 0) return '';
    $post = wsm_social_get($pdo, $id);
    $cfg = wsm_social_cfg();
    $res = [];
    foreach (array_intersect(['fb', 'ig'], $post['kanaly_list']) as $k) {
        if ((string) $post[$k . '_status'] === 'ok') { $res[$k] = true; continue; }   // déjà passé : pas de doublon
        $ready = $k === 'fb' ? $cfg['fb'] : $cfg['ig_ok'];
        try {
            $r = !$ready ? ['ok' => false, 'error' => 'kanał nie jest połączony']
               : ($k === 'fb' ? wsm_social_publish_fb($pdo, $post, $cfg) : wsm_social_publish_ig($pdo, $post, $cfg, $shopUrl));
        } catch (Throwable $e) {
            $r = ['ok' => false, 'error' => 'błąd: ' . mb_substr($e->getMessage(), 0, 200)];
        }
        $res[$k] = $r['ok'];
        if ($r['ok']) {
            $pdo->prepare("UPDATE wsm_social_posts SET {$k}_status = 'ok', {$k}_id = ?, {$k}_url = ?, {$k}_error = '' WHERE id = ?")
                ->execute([mb_substr((string) $r['id'], 0, 80), mb_substr((string) $r['url'], 0, 255), $id]);
        } else {
            $pdo->prepare("UPDATE wsm_social_posts SET {$k}_status = 'blad', {$k}_error = ? WHERE id = ?")
                ->execute([mb_substr((string) $r['error'], 0, 500), $id]);
        }
    }
    $ok = count(array_filter($res));
    $etat = $ok === count($res) ? 'opublikowany' : ($ok > 0 ? 'czesciowo' : 'blad');
    $pdo->prepare("UPDATE wsm_social_posts SET status = ?, published_at = ?, updated_at = ? WHERE id = ?")
        ->execute([$etat, $ok > 0 ? date('Y-m-d H:i:s') : null, date('Y-m-d H:i:s'), $id]);
    return $etat;
}

/**
 * « Ponów » : remet un post en file pour ses canaux en échec seulement.
 * Un post « częściowo » repasse « zaplanowany » ; son canal réussi est sauté.
 */
function wsm_social_retry(PDO $pdo, int $id): bool {
    $u = $pdo->prepare("UPDATE wsm_social_posts SET status = 'zaplanowany', termin = ?, updated_at = ?
                         WHERE id = ? AND status IN ('blad','czesciowo')");
    $u->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
    return $u->rowCount() > 0;
}

/** Les posts restés « publikowanie » trop longtemps : déclarés interrompus, pas relancés. */
function wsm_social_stale(PDO $pdo): int {
    $u = $pdo->prepare("UPDATE wsm_social_posts SET status = 'blad',
                               fb_error = CASE WHEN fb_status = 'ok' THEN fb_error ELSE 'przerwane — sprawdź stronę na Facebooku, zanim ponowisz' END,
                               ig_error = CASE WHEN ig_status = 'ok' THEN ig_error ELSE 'przerwane — sprawdź profil na Instagramie, zanim ponowisz' END
                         WHERE status = 'publikowanie' AND claimed_at < ?");
    $u->execute([date('Y-m-d H:i:s', time() - WSM_SOCIAL_ZAWIESZONY)]);
    return $u->rowCount();
}

/** La pompe : publie les posts dont l'heure est venue. */
function wsm_social_pump(PDO $pdo, string $shopUrl, int $max = 3): array {
    $out = ['opublikowane' => 0, 'bledy' => 0];
    try {
        wsm_social_ensure($pdo);
        wsm_social_stale($pdo);
        $st = $pdo->prepare("SELECT id FROM wsm_social_posts WHERE status = 'zaplanowany' AND termin IS NOT NULL AND termin <= ?
                              ORDER BY termin, id LIMIT " . max(1, min(20, $max)));
        $st->execute([date('Y-m-d H:i:s')]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $e = wsm_social_publish($pdo, (int) $id, $shopUrl);
            if ($e === 'opublikowany') $out['opublikowane']++;
            elseif ($e !== '') $out['bledy']++;
        }
    } catch (Throwable $e) {}
    return $out;
}

/** Les posts d'un mois (par date prévue ou de publication), pour le calendrier. */
function wsm_social_month(PDO $pdo, string $ym): array {
    wsm_social_ensure($pdo);
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
    $a = $ym . '-01 00:00:00';
    $b = date('Y-m-d H:i:s', strtotime($a . ' +1 month'));
    $st = $pdo->prepare("SELECT * FROM wsm_social_posts
                          WHERE (termin >= ? AND termin < ?) OR (published_at >= ? AND published_at < ?)
                          ORDER BY COALESCE(published_at, termin), id");
    $st->execute([$a, $b, $a, $b]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $d = substr((string) ($r['published_at'] ?: $r['termin']), 0, 10);
        $r['media_list'] = json_decode((string) $r['media'], true) ?: [];
        $out[$d][] = $r;
    }
    return $out;
}

/** Les derniers posts, tous états confondus. */
function wsm_social_list(PDO $pdo, int $n = 50): array {
    wsm_social_ensure($pdo);
    $rows = $pdo->query("SELECT * FROM wsm_social_posts ORDER BY COALESCE(termin, created_at) DESC, id DESC LIMIT " . max(1, min(200, $n)))->fetchAll();
    foreach ($rows as &$r) $r['media_list'] = json_decode((string) $r['media'], true) ?: [];
    return $rows;
}

/**
 * Trois propositions de texte par Claude.
 *
 * MÊMES GARDE-FOUS QUE LA NEWSLETTER : aucun prix, aucune promotion, aucune
 * vertu de santé qui ne soit dans la consigne. Une phrase inventée sur une
 * page publique de la marque se lit par des milliers de personnes et ne se
 * rattrape pas.
 *
 * @return array{0:list<string>, 1:?string}
 */
function wsm_social_ai(PDO $pdo, string $brief, string $productId = '', array $kanaly = ['fb', 'ig']): array {
    require_once __DIR__ . '/marketing_ai.php';
    $brief = trim($brief);
    if (mb_strlen($brief) < 5) return [[], 'Opisz w jednym-dwóch zdaniach, o czym ma być post.'];
    $faits = '';
    if ($productId !== '') {
        require_once __DIR__ . '/shop.php';
        $p = wsm_shop_product($pdo, $productId, 'pl');
        if ($p) {
            $faits = "Produkt: " . $p['name'] . ($p['subtitle'] !== '' ? ' — ' . $p['subtitle'] : '')
                   . ', cena ' . number_format($p['price'] / 100, 2, ',', ' ') . ' zł'
                   . ($p['cocoa'] !== '' ? ', kakao ' . $p['cocoa'] : '') . ($p['origin'] !== '' ? ', pochodzenie ' . $p['origin'] : '')
                   . ($p['unit'] !== '' ? ', ' . $p['unit'] : '') . ".\n";
            if (trim((string) $p['desc']) !== '') $faits .= 'Opis ze sklepu: ' . mb_substr(wsm_feed_text((string) $p['desc'], 900), 0, 900) . "\n";
        }
    }
    $ig = in_array('ig', $kanaly, true);
    $sys = str_replace('bez emoji', 'z najwyżej dwoma emoji w całym poście', wsm_ai_ton())
         . "\n\nPiszesz posty na " . ($ig ? 'Facebooka i Instagram' : 'Facebooka') . " marki. Zwracasz WYŁĄCZNIE obiekt JSON "
         . "{\"propozycje\":[\"…\",\"…\",\"…\"]} — trzy różne wersje (krótka, opowieść, praktyczna/przepis).\n"
         . "Każda: pierwsze zdanie przyciąga uwagę (widać je przed „więcej”), 300–900 znaków, akapity rozdzielone pustą linią, "
         . "na końcu wezwanie do działania" . ($ig ? ' („link w bio” zamiast linku — na Instagramie link się nie klika)' : '')
         . " i 5–10 trafnych polskich hashtagów w ostatniej linii (np. #czekolada #cukiernia #wypieki). "
         . "Bez linków. Bez cen, jeśli nie podano ich w poleceniu ani w danych produktu.";
    $user = "Polecenie: $brief\n\n" . ($faits !== '' ? $faits : "Bez konkretnego produktu.\n");
    [$t, $err] = wsm_ai_call($sys, $user, 2000);
    if ($err !== null) return [[], $err];
    $d = wsm_ai_json($t);
    $out = [];
    foreach ((array) ($d['propozycje'] ?? []) as $x) {
        $x = trim(str_replace(["\r\n", "\r"], "\n", (string) $x));
        if ($x !== '') $out[] = mb_substr($x, 0, WSM_SOCIAL_MAX_ZNAKOW);
        if (count($out) >= 3) break;
    }
    return $out ? [$out, null] : [[], 'Odpowiedź nie dała się odczytać — spróbuj jeszcze raz.'];
}

/** Sprawdza połączenie ze stroną i odczytuje konto Instagram powiązane ze stroną. */
function wsm_social_check(): array {
    $c = wsm_ads_cfg();
    if ($c['page_id'] === '' || $c['page_token'] === '') return ['ok' => false, 'error' => 'brak ID strony albo tokenu'];
    $r = wsm_graph_call('GET', $c['page_id'], ['fields' => 'name,instagram_business_account{id,username}'], $c['page_token'], [], $c['graph']);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
    $ig = (array) ($r['data']['instagram_business_account'] ?? []);
    return ['ok' => true, 'page' => (string) ($r['data']['name'] ?? ''), 'ig_id' => (string) ($ig['id'] ?? ''),
            'ig_user' => (string) ($ig['username'] ?? '')];
}

/**
 * Les dates qui font vendre du chocolat en Pologne, pour le calendrier.
 * Pâques et le Tłusty czwartek se calculent (calendrier grégorien) ; Black
 * Friday est le quatrième vendredi de novembre.
 *
 * @return array<string,string> 'Y-m-d' => libellé
 */
function wsm_social_okazje(int $rok): array {
    // Pâques : algorithme anonyme (Meeus/Jones/Butcher), sans l'extension calendar.
    $a = $rok % 19; $b = intdiv($rok, 100); $c = $rok % 100; $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mois = intdiv($h + $l - 7 * $m + 114, 31); $jour = (($h + $l - 7 * $m + 114) % 31) + 1;
    $wielkanoc = mktime(12, 0, 0, $mois, $jour, $rok);
    $bf = strtotime("fourth friday of november $rok");
    $o = [
        "$rok-01-21" => 'Dzień Babci', "$rok-01-22" => 'Dzień Dziadka',
        date('Y-m-d', $wielkanoc - 52 * 86400) => 'Tłusty czwartek',
        "$rok-02-14" => 'Walentynki', "$rok-03-08" => 'Dzień Kobiet',
        date('Y-m-d', $wielkanoc) => 'Wielkanoc',
        "$rok-05-26" => 'Dzień Matki', "$rok-06-01" => 'Dzień Dziecka', "$rok-06-23" => 'Dzień Ojca',
        "$rok-07-07" => 'Światowy Dzień Czekolady', "$rok-10-14" => 'Dzień Nauczyciela',
        "$rok-10-31" => 'Halloween', date('Y-m-d', $bf) => 'Black Friday',
        "$rok-12-06" => 'Mikołajki', "$rok-12-24" => 'Wigilia',
    ];
    ksort($o);
    return $o;
}
