<?php
// Privé (mot de passe du projet) : les données d'un écran, dans la FORME des
// exemples de la maquette (mêmes champs, mêmes textes), pour remplacer ceux-ci.
declare(strict_types=1);
// Dépôt : app/prive/ → app/lib.php ; serveur : _wsb/prive/app/ → _wsb/app/lib.php.
require is_file(dirname(__DIR__) . '/lib.php') ? dirname(__DIR__) . '/lib.php' : dirname(__DIR__, 2) . '/app/lib.php';
$ekran = (string) ($_GET['ekran'] ?? '');
$pdo = of_pdo();
$out = [];

if ($ekran === 'Back office') {
    $STAN = ['nowe' => 'nowe', 'oferta_w_robocie' => 'oferta w robocie', 'wycena_wyslana' => 'wycena wysłana', 'odmowa' => 'odmowa'];
    $zap = []; $doss = [];
    $st = $pdo->prepare("SELECT i.*, c.display_name FROM of_inquiries i LEFT JOIN of_clients c ON c.id = i.client_id
                         WHERE i.tenant_id = ? ORDER BY i.received_at DESC LIMIT 500");
    $st->execute([OF_TENANT]);
    foreach ($st as $r) {
        $ex = json_decode((string) ($r['extras_json'] ?? ''), true) ?: [];
        $who = (string) ($r['display_name'] ?: ($r['email'] ?: $r['number']));
        $zap[] = ['id' => 'z' . $r['id'], 'iso' => (string) ($r['event_date'] ?? ''), 'who' => $who, 'when' => of_data_pl($r['event_date']),
                  'venue' => (string) ($r['venue_text'] ?? ''), 'ceremony' => (string) ($r['ceremony_text'] ?? ''), 'guests' => (string) ($r['guests_range'] ?? ''),
                  'budget' => (string) ($r['budget_range'] ?? ''), 'style' => (string) ($r['style'] ?? ''), 'palette' => (string) ($r['palette_label'] ?? ''),
                  'tables' => (string) ($r['tables_kind'] ?? ''), 'head' => (string) ($r['head_table'] ?? ''), 'extras' => implode(', ', $ex),
                  'mail' => (string) ($r['email'] ?? ''), 'tel' => (string) ($r['phone'] ?? ''), 'ago' => of_temu($r['received_at']),
                  'state' => $STAN[$r['status']] ?? (string) $r['status'], 'day' => []];
        if ($r['status'] !== 'odmowa' && empty($r['event_id'])) {
            $czeka = (int) floor((time() - strtotime((string) $r['received_at'])) / 86400);
            $doss[] = ['no' => $r['number'], 'mode' => 'zapytanie', 'who' => $who, 'when' => of_data_pl($r['event_date']),
                       'where' => (string) ($r['venue_text'] ?? ''), 'sum' => $r['estimated_total_gr'] !== null ? '≈ ' . of_zl((int) $r['estimated_total_gr']) : '',
                       'stage' => 'zapytanie', 'next' => 'Odpowiedzieć — czeka ' . $czeka . ' ' . ($czeka === 1 ? 'dzień' : 'dni'), 'brand' => 'of', 'go' => 'zap'];
        }
    }
    $out['ZAP'] = $zap;
    $out['DOSS'] = $doss;

    // CMS de la page publique : ce qui est publié, les brouillons et la photothèque.
    of_cms_porzadki($pdo);
    $pub = []; $szkic = []; $stamp = [];
    $st = $pdo->prepare("SELECT s.skey, s.kind, s.draft_value, s.published_value, s.published_at FROM of_page_sections s
                         JOIN of_pages p ON p.id = s.page_id JOIN of_sites w ON w.id = p.site_id WHERE w.tenant_id = ?");
    $st->execute([OF_TENANT]);
    foreach ($st as $r) {
        if ($r['published_at'] !== null) {
            $stamp[$r['skey']] = of_cms_stamp($r['published_at']);
            if (!of_cms_foto((string) $r['kind']) && $r['published_value'] !== null) $pub[$r['skey']] = (string) $r['published_value'];
        }
        if ($r['draft_value'] !== null) $szkic[$r['skey']] = (string) $r['draft_value'];
    }
    $out['__cms'] = ['pub' => (object) $pub, 'szkic' => (object) $szkic, 'stamp' => (object) $stamp,
                     'zdjecia' => (object) of_cms_wszystkie_zdjecia($pdo, 'published'),
                     'zdjeciaSzkic' => (object) of_cms_wszystkie_zdjecia($pdo, 'draft'),
                     'biblioteka' => of_cms_biblioteka($pdo)];
}

of_json((object) $out);
