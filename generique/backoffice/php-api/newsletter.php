<?php
// ============================================================================
//  newsletter.php — la newsletter en blocs, comme celle du CRM d'Atelier By.
//
//  UNE ÉDITION = des blocs (titre, texte, image, produits du catalogue,
//  bouton, code de réduction, séparateur), un public, et ses traductions.
//  Elle part dans la langue de chacun (pl / uk / en) ; une traduction absente
//  se DIT avant l'envoi (« 12 osób dostanie wersję polską »), elle ne se
//  découvre pas après.
//
//  CE QUI EST AJOUTÉ PAR LE CODE, JAMAIS LAISSÉ À LA RÉDACTION :
//   · le lien de désinscription, dans le pied ET dans l'en-tête
//     List-Unsubscribe (un clic, RFC 8058) ;
//   · l'identité de l'expéditeur (nom, adresse) — la loi l'exige ;
//   · le lien suivi (?l=nl12) sur chaque adresse de la boutique : clics et
//     commandes se comptent par édition, sans pister personne ;
//   · la version texte, pour les messageries qui n'affichent pas le HTML.
//
//  Le HTML est fait de tableaux et de styles en ligne : c'est ce que lisent
//  Outlook et Gmail. Une feuille de style externe y serait ignorée.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/marketing.php';

/** Les blocs, et le libellé que la console leur donne. */
const WSM_NL_BLOKI = [
    'naglowek' => 'Nagłówek',
    'tekst'    => 'Tekst',
    'obraz'    => 'Obraz',
    'produkty' => 'Produkty z katalogu',
    'przycisk' => 'Przycisk',
    'kod'      => 'Kod rabatowy',
    'odstep'   => 'Linia odstępu',
];

/** Les champs TEXTE de chaque bloc : ce qui se traduit. */
const WSM_NL_TEKSTY = ['naglowek' => ['tekst'], 'tekst' => ['tekst'], 'obraz' => ['alt'],
                       'przycisk' => ['etykieta'], 'kod' => ['opis']];

/** Les couleurs de la boutique, en dur : un e-mail n'a pas de feuille de style. */
const WSM_NL_KOLORY = ['tlo' => '#f4efe8', 'karta' => '#ffffff', 'tekst' => '#2b1d14', 'cichy' => '#7a6656',
                       'akcent' => '#5a2e1a', 'linia' => '#e6ddd2'];

function wsm_nl_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM wsm_newsletters WHERE id = ?");
    $st->execute([$id]);
    $n = $st->fetch();
    if (!$n) return null;
    $n['bloki'] = json_decode((string) ($n['bloki'] ?? ''), true) ?: [];
    $n['tlumaczenia'] = json_decode((string) ($n['tlumaczenia'] ?? ''), true) ?: [];
    return $n;
}

/** Une adresse de lien ou d'image : http(s) seulement, sinon rien. */
function wsm_nl_url(string $u): string {
    $u = trim($u);
    return preg_match('#^https?://[^\s"<>]+$#i', $u) ? $u : '';
}

/** Les blocs tels qu'ils arrivent du formulaire (ou de l'IA), rendus sûrs. */
function wsm_nl_bloki_propres($v): array {
    $out = [];
    foreach ((array) $v as $b) {
        if (!is_array($b)) continue;
        $typ = (string) ($b['typ'] ?? '');
        if (!isset(WSM_NL_BLOKI[$typ])) continue;
        $t = fn(string $k, int $max) => mb_substr(trim(str_replace("\r", '', (string) ($b[$k] ?? ''))), 0, $max);
        $x = ['typ' => $typ];
        switch ($typ) {
            case 'naglowek': $x['tekst'] = $t('tekst', 160); break;
            case 'tekst':    $x['tekst'] = $t('tekst', 4000); break;
            case 'obraz':    $x['url'] = wsm_nl_url((string) ($b['url'] ?? '')); $x['alt'] = $t('alt', 160);
                             $x['link'] = wsm_nl_url((string) ($b['link'] ?? '')); break;
            case 'produkty':
                $ids = is_array($b['ids'] ?? null) ? $b['ids'] : preg_split('/[\s,]+/', (string) ($b['ids'] ?? ''));
                $x['ids'] = array_values(array_unique(array_filter(array_map(
                    fn($i) => mb_substr(trim((string) $i), 0, 64), (array) $ids), fn($i) => $i !== '')));
                $x['ids'] = array_slice($x['ids'], 0, 6);
                break;
            case 'przycisk': $x['etykieta'] = $t('etykieta', 60); $x['url'] = wsm_nl_url((string) ($b['url'] ?? '')); break;
            case 'kod':      $x['kod'] = strtoupper($t('kod', 40)); $x['opis'] = $t('opis', 200); break;
        }
        $out[] = $x;
    }
    return array_slice($out, 0, 30);
}

/** Un bloc vide, prêt à remplir. */
function wsm_nl_blok_nowy(string $typ): array {
    return wsm_nl_bloki_propres([['typ' => $typ]])[0] ?? ['typ' => 'tekst', 'tekst' => ''];
}

/**
 * Enregistre une édition (création ou mise à jour). Une édition partie ne
 * se modifie plus : on la duplique.
 * @return array{0:int, 1:string}
 */
function wsm_nl_save(PDO $pdo, array $in, string $actor): array {
    $id = (int) ($in['id'] ?? 0);
    $seg = (string) ($in['segment'] ?? 'wszyscy');
    if (!isset(WSM_MKT_SEGMENTS[$seg])) $seg = 'wszyscy';
    $temat = mb_substr(trim((string) ($in['temat'] ?? '')), 0, 250);
    $nazwa = mb_substr(trim((string) ($in['nazwa'] ?? '')), 0, 190) ?: ($temat !== '' ? $temat : 'Newsletter ' . date('d.m.Y'));
    $bloki = wsm_nl_bloki_propres($in['bloki'] ?? []);
    $tl = [];
    foreach ((array) ($in['tlumaczenia'] ?? []) as $l => $x) {
        if (!in_array($l, ['uk', 'en'], true) || !is_array($x)) continue;
        $tl[$l] = ['temat' => mb_substr(trim((string) ($x['temat'] ?? '')), 0, 250),
                   'preheader' => mb_substr(trim((string) ($x['preheader'] ?? '')), 0, 250),
                   'bloki' => array_map(fn($b) => array_map(fn($v) => mb_substr(trim(str_replace("\r", '', (string) $v)), 0, 4000),
                                        array_filter((array) $b, 'is_scalar')), (array) ($x['bloki'] ?? []))];
    }
    $vals = [$nazwa, $temat, mb_substr(trim((string) ($in['preheader'] ?? '')), 0, 250),
             json_encode($bloki, JSON_UNESCAPED_UNICODE), json_encode($tl, JSON_UNESCAPED_UNICODE), $seg,
             mb_substr(strtoupper(trim((string) ($in['kod'] ?? ''))), 0, 40), date('Y-m-d H:i:s')];
    if ($id > 0) {
        $n = wsm_nl_get($pdo, $id);
        if (!$n) return [0, 'Nie znaleziono newslettera.'];
        if ((string) $n['status'] !== 'szkic') return [0, 'Ten newsletter już wyszedł — zduplikuj go, żeby wysłać nową wersję.'];
        $pdo->prepare("UPDATE wsm_newsletters SET nazwa = ?, temat = ?, preheader = ?, bloki = ?, tlumaczenia = ?, segment = ?,
                              kod = ?, updated_at = ? WHERE id = ?")->execute([...$vals, $id]);
        return [$id, 'Zapisano.'];
    }
    $pdo->prepare("INSERT INTO wsm_newsletters (nazwa, temat, preheader, bloki, tlumaczenia, segment, kod, updated_at,
                          created_by, created_at, status) VALUES (?,?,?,?,?,?,?,?,?,?,'szkic')")
        ->execute([...$vals, mb_substr($actor, 0, 120), date('Y-m-d H:i:s')]);
    $id = (int) $pdo->lastInsertId();
    if (function_exists('wsm_audit')) wsm_audit($pdo, $actor, 'Nowy newsletter', 'wsm_newsletters #' . $id, 'Sieć');
    return [$id, 'Utworzono szkic.'];
}

/** Copie d'une édition (partie ou non), en brouillon. */
function wsm_nl_duplicate(PDO $pdo, int $id, string $actor): int {
    $n = wsm_nl_get($pdo, $id);
    if (!$n) return 0;
    [$nid] = wsm_nl_save($pdo, ['nazwa' => $n['nazwa'] . ' (kopia)', 'temat' => $n['temat'], 'preheader' => $n['preheader'],
        'bloki' => $n['bloki'], 'tlumaczenia' => $n['tlumaczenia'], 'segment' => $n['segment'], 'kod' => $n['kod']], $actor);
    return $nid;
}

// ---------------------------------------------------------------------------
//  Le rendu
// ---------------------------------------------------------------------------

/** Une adresse d'image absolue : les messageries n'en lisent pas d'autre. */
function wsm_nl_abs(string $u): string {
    $u = trim($u);
    if ($u === '' || preg_match('#^https?://#i', $u)) return $u;
    $base = wsm_shop_base_url();
    if (str_starts_with($u, '/')) {
        $p = parse_url($base);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '') . $u;
    }
    return $base . '/' . ltrim($u, '/');
}

/** L'adresse porte-t-elle sur la boutique ? Alors on y pose le lien suivi. */
function wsm_nl_suivi(string $u, string $code): string {
    if ($u === '' || $code === '') return $u;
    $base = wsm_shop_base_url();
    if (!str_starts_with($u, $base)) return $u;
    return $u . (str_contains($u, '?') ? '&' : '?') . 'l=' . rawurlencode($code);
}

/** Texte d'un bloc : échappé, **gras**, paragraphes. */
function wsm_nl_html_tekst(string $t): string {
    $h = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $h = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $h) ?? $h;
    $paras = preg_split("/\n{2,}/", $h) ?: [$h];
    return implode('', array_map(fn($p) => '<p style="margin:0 0 14px">' . nl2br($p) . '</p>', $paras));
}

function wsm_nl_zl(int $grosze): string {
    return number_format($grosze / 100, 2, ',', ' ') . ' zł';
}

/**
 * L'édition rendue pour UN destinataire, dans SA langue.
 *
 * @param array $manques  rempli des textes sans traduction (qui partent en polonais)
 * @return array{temat:string, html:string, tekst:string}
 */
function wsm_nl_render(PDO $pdo, array $nl, string $lang, array $dest, string $code, array &$manques = []): array {
    $lang = wsm_mkt_lang($lang);
    // Les couleurs de l'e-mail : celles du design system de la boutique s'il
    // en donne (ds.json → « email »), sinon la palette d'origine. Un client
    // de messagerie ne lit pas les variables CSS : il faut des #RRGGBB.
    $K = array_replace(WSM_NL_KOLORY, array_intersect_key((array) (wsm_ds()['email'] ?? []), WSM_NL_KOLORY));
    $tr = $lang !== 'pl' ? (array) ($nl['tlumaczenia'][$lang] ?? []) : [];
    $T = function (string $pl, ?string $traduit, string $quoi) use ($lang, &$manques): string {
        if ($lang === 'pl' || trim($pl) === '') return $pl;
        if ($traduit !== null && trim($traduit) !== '') return $traduit;
        $manques[$quoi] = true;
        return $pl;
    };
    $imie = trim((string) (preg_split('/\s+/', trim((string) ($dest['name'] ?? ''))) ?: [''])[0]);
    $perso = fn(string $s): string => preg_replace('/ +([,.!?])/u', '$1', str_replace('{imie}', $imie, $s)) ?? $s;

    $temat = $perso($T((string) $nl['temat'], $tr['temat'] ?? null, 'temat'));
    $pre = $perso($T((string) $nl['preheader'], $tr['preheader'] ?? null, 'preheader'));
    $shop = wsm_nl_suivi(wsm_shop_base_url() . '/', $code);
    $produits = [];

    $h = []; $txt = [];
    foreach ((array) $nl['bloki'] as $i => $b) {
        $bt = (array) ($tr['bloki'][$i] ?? []);
        $champ = fn(string $k) => $perso($T((string) ($b[$k] ?? ''), isset($bt[$k]) ? (string) $bt[$k] : null, "blok$i.$k"));
        switch ((string) $b['typ']) {
            case 'naglowek':
                $t = $champ('tekst');
                if ($t === '') break;
                $h[] = '<h2 style="margin:0 0 14px;font-size:24px;line-height:1.25;color:' . $K['tekst'] . ';font-weight:700">'
                     . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</h2>';
                $txt[] = mb_strtoupper($t);
                break;
            case 'tekst':
                $t = $champ('tekst');
                if ($t === '') break;
                $h[] = '<div style="font-size:16px;line-height:1.6;color:' . $K['tekst'] . '">' . wsm_nl_html_tekst($t) . '</div>';
                $txt[] = str_replace('**', '', $t);
                break;
            case 'obraz':
                $src = wsm_nl_abs((string) ($b['url'] ?? ''));
                if ($src === '') break;
                $img = '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($champ('alt'), ENT_QUOTES, 'UTF-8')
                     . '" width="536" style="display:block;width:100%;max-width:536px;height:auto;border:0;border-radius:10px">';
                $lien = wsm_nl_suivi((string) ($b['link'] ?? ''), $code);
                $h[] = '<div style="margin:0 0 18px">' . ($lien !== '' ? '<a href="' . htmlspecialchars($lien, ENT_QUOTES, 'UTF-8') . '">' . $img . '</a>' : $img) . '</div>';
                break;
            case 'produkty':
                $cells = [];
                foreach ((array) ($b['ids'] ?? []) as $pid) {
                    $p = $produits[$pid] ??= wsm_shop_product($pdo, (string) $pid, $lang);
                    if (!$p) continue;
                    $u = wsm_nl_suivi(wsm_shop_base_url() . '/p/' . rawurlencode((string) $p['slug']), $code);
                    $img = (string) $p['image'] !== ''
                        ? '<img src="' . htmlspecialchars(wsm_nl_abs((string) $p['image']), ENT_QUOTES, 'UTF-8') . '" alt="" width="240" style="display:block;width:100%;height:auto;border:0;border-radius:8px;margin:0 0 10px">'
                        : '';
                    $cells[] = '<td width="50%" valign="top" style="padding:0 8px 16px"><a href="' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8')
                             . '" style="text-decoration:none;color:' . $K['tekst'] . '">' . $img
                             . '<div style="font-size:15px;font-weight:700;line-height:1.3">' . htmlspecialchars((string) $p['name'], ENT_QUOTES, 'UTF-8') . '</div>'
                             . '<div style="font-size:15px;color:' . $K['akcent'] . ';margin-top:4px">' . wsm_nl_zl((int) $p['price']) . '</div></a></td>';
                    $txt[] = '• ' . $p['name'] . ' — ' . wsm_nl_zl((int) $p['price']) . "\n  " . $u;
                }
                if ($cells) {
                    $rows = array_chunk($cells, 2);
                    $h[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 4px">'
                         . implode('', array_map(fn($r) => '<tr>' . implode('', $r) . (count($r) === 1 ? '<td width="50%"></td>' : '') . '</tr>', $rows))
                         . '</table>';
                }
                break;
            case 'przycisk':
                $lbl = $champ('etykieta');
                if ($lbl === '') break;
                $u = (string) ($b['url'] ?? '') !== '' ? wsm_nl_suivi((string) $b['url'], $code) : $shop;
                $h[] = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px 0 22px"><tr><td style="border-radius:999px;background:'
                     . $K['tekst'] . '"><a href="' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:13px 26px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none">'
                     . htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') . '</a></td></tr></table>';
                $txt[] = $lbl . ': ' . $u;
                break;
            case 'kod':
                $kod = (string) ($b['kod'] ?? '') ?: (string) ($nl['kod'] ?? '');
                if ($kod === '') break;
                $opis = $champ('opis');
                $h[] = '<div style="margin:4px 0 22px;padding:18px;border:2px dashed ' . $K['akcent'] . ';border-radius:12px;text-align:center">'
                     . ($opis !== '' ? '<div style="font-size:15px;color:' . $K['tekst'] . ';margin-bottom:8px">' . htmlspecialchars($opis, ENT_QUOTES, 'UTF-8') . '</div>' : '')
                     . '<div style="font-size:26px;font-weight:800;letter-spacing:2px;color:' . $K['akcent'] . ';font-family:Menlo,Consolas,monospace">'
                     . htmlspecialchars($kod, ENT_QUOTES, 'UTF-8') . '</div></div>';
                $txt[] = ($opis !== '' ? $opis . ' ' : '') . 'KOD: ' . $kod;
                break;
            case 'odstep':
                $h[] = '<div style="border-top:1px solid ' . $K['linia'] . ';margin:8px 0 22px"></div>';
                $txt[] = '—';
                break;
        }
    }

    // --- Le pied : qui écrit, pourquoi, comment partir ------------------------
    $inv = (array) (wsm_config()['invoice'] ?? []);
    $qui = trim((string) ($inv['seller_name'] ?? '')) ?: wsm_brand_name();
    $adr = trim((string) ($inv['seller_address'] ?? ''));
    $stop = wsm_mkt_unsub_page((string) ($dest['email'] ?? ''), 'nl:' . (int) ($nl['id'] ?? 0));
    $pourquoi = str_replace('{sklep}', wsm_brand_name(), wsm_mkt_t($pdo, $lang, 'nl.footer_why',
        'Ta wiadomość przychodzi do Ciebie z powodu zapisu do newslettera {sklep} lub wcześniejszych zakupów w naszym sklepie.'));
    $partir = wsm_mkt_t($pdo, $lang, 'nl.footer_unsub', 'Wypisz się jednym kliknięciem');
    $logo = wsm_nl_abs(wsm_shop_base_url() . '/' . wsm_ds_logo_path());

    $html = '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
          . '<title>' . htmlspecialchars($temat, ENT_QUOTES, 'UTF-8') . '</title></head>'
          . '<body style="margin:0;padding:0;background:' . $K['tlo'] . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif">'
          . '<span style="display:none;max-height:0;overflow:hidden;opacity:0">' . htmlspecialchars($pre, ENT_QUOTES, 'UTF-8') . '</span>'
          . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $K['tlo'] . '"><tr><td align="center" style="padding:24px 12px">'
          . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px">'
          . '<tr><td align="center" style="padding:6px 0 18px"><a href="' . htmlspecialchars($shop, ENT_QUOTES, 'UTF-8') . '"><img src="'
          . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars(wsm_brand_name(), ENT_QUOTES, 'UTF-8') . '" height="44" style="height:44px;border:0"></a></td></tr>'
          . '<tr><td style="background:' . $K['karta'] . ';border-radius:16px;padding:32px">' . implode('', $h) . '</td></tr>'
          . '<tr><td style="padding:20px 8px;font-size:12px;line-height:1.6;color:' . $K['cichy'] . ';text-align:center">'
          . htmlspecialchars($qui, ENT_QUOTES, 'UTF-8') . ($adr !== '' ? ' · ' . htmlspecialchars($adr, ENT_QUOTES, 'UTF-8') : '') . '<br>'
          . htmlspecialchars($pourquoi, ENT_QUOTES, 'UTF-8') . '<br><a href="' . htmlspecialchars($stop, ENT_QUOTES, 'UTF-8') . '" style="color:' . $K['cichy'] . '">'
          . htmlspecialchars($partir, ENT_QUOTES, 'UTF-8') . '</a></td></tr></table></td></tr></table></body></html>';

    $texte = implode("\n\n", $txt) . "\n\n—\n" . $qui . ($adr !== '' ? ', ' . $adr : '') . "\n" . $pourquoi . "\n" . $partir . ': ' . $stop . "\n";
    return ['temat' => $temat, 'html' => $html, 'tekst' => $texte];
}

/**
 * Ce que l'envoi va faire, AVANT : destinataires par langue, et ce qui
 * partira en polonais faute de traduction.
 */
function wsm_nl_preview(PDO $pdo, array $nl): array {
    $dest = wsm_mkt_audience_email($pdo, (string) $nl['segment']);
    $langs = []; $sans = [];
    foreach ($dest as $d) $langs[$d['lang']] = ($langs[$d['lang']] ?? 0) + 1;
    foreach (['uk', 'en'] as $l) {
        if (empty($langs[$l])) continue;
        $m = [];
        wsm_nl_render($pdo, $nl, $l, ['email' => 'x@example.com'], '', $m);
        if ($m) $sans[$l] = count($m);
    }
    return ['odbiorcow' => count($dest), 'jezyki' => $langs, 'bez_tlumaczenia' => $sans];
}

/** Un exemplaire de test, à une adresse (non idempotent : on se le renvoie à chaque correction). */
function wsm_nl_test(PDO $pdo, int $id, string $email, string $lang, string $actor): array {
    $nl = wsm_nl_get($pdo, $id);
    if (!$nl) return [false, 'Nie znaleziono newslettera.'];
    $email = wsm_mkt_email_norm($email);
    if ($email === '') return [false, 'Podaj poprawny adres do próbki.'];
    if (!$nl['bloki'] || trim((string) $nl['temat']) === '') return [false, 'Uzupełnij temat i dodaj przynajmniej jeden blok.'];
    $r = wsm_nl_render($pdo, $nl, $lang, ['email' => $email, 'name' => 'Anna'], (string) ($nl['link_code'] ?: ''));
    $mid = wsm_mail_queue($pdo, ['email' => $email, 'direction' => 'wyjscie', 'subject' => '[PRÓBKA] ' . $r['temat'],
        'body' => $r['tekst'], 'html' => $r['html'], 'template_code' => 'newsletter-probka', 'actor' => $actor ?: 'newsletter']);
    if ($mid > 0 && wsm_mail_enabled()) {
        [$ok, $err] = wsm_mail_send($pdo, $mid);
        return [$ok, $ok ? 'Próbka wysłana na ' . $email . '.' : 'Próbka w kolejce, nie wyszła: ' . $err];
    }
    return [$mid > 0, $mid > 0 ? 'Próbka zapisana w Poczcie — poczta nie jest jeszcze skonfigurowana (Integracje).' : 'Nie zapisano próbki.'];
}

/**
 * Envoie l'édition — c'est-à-dire : LA MET EN FILE, une ligne par adresse
 * (clé unique : rejouer ne renvoie rien). La pompe écoule.
 * @return array{0:int, 1:string}
 */
function wsm_nl_send(PDO $pdo, int $id, string $actor): array {
    $nl = wsm_nl_get($pdo, $id);
    if (!$nl) return [0, 'Nie znaleziono newslettera.'];
    if ((string) $nl['status'] !== 'szkic') return [0, 'Ten newsletter już wyszedł.'];
    if (!$nl['bloki'] || trim((string) $nl['temat']) === '') return [0, 'Uzupełnij temat i dodaj przynajmniej jeden blok.'];
    // PRISE de l'édition : un double clic ne la met pas en file deux fois.
    $prise = $pdo->prepare("UPDATE wsm_newsletters SET status = 'wysylanie' WHERE id = ? AND status = 'szkic'");
    $prise->execute([$id]);
    if ($prise->rowCount() !== 1) return [0, 'Ten newsletter właśnie wychodzi.'];
    try {
        return wsm_nl_enfiler($pdo, $nl, $id, $actor);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE wsm_newsletters SET status = 'szkic' WHERE id = ? AND status = 'wysylanie'")->execute([$id]);
        return [0, 'Wysyłka przerwana: ' . $e->getMessage()];
    }
}

/**
 * La clé d'un message de newsletter. wsm_messages.event_key fait 100
 * caractères : une adresse longue y entre par son empreinte (MySQL strict
 * aurait refusé la ligne, et la personne n'aurait rien reçu).
 */
function wsm_nl_event_key(int $id, string $email): string {
    $k = 'nl-' . $id . '-' . $email;
    return strlen($k) <= 100 ? $k : 'nl-' . $id . '-h' . sha1($email);
}

/** La mise en file elle-même, une fois l'édition prise. */
function wsm_nl_enfiler(PDO $pdo, array $nl, int $id, string $actor): array {
    [$code, $uwaga] = wsm_mkt_link($pdo, 'nl' . $id, 'Newsletter: ' . (string) $nl['nazwa'], (string) $nl['kod']);
    $pdo->prepare("UPDATE wsm_newsletters SET link_code = ? WHERE id = ?")->execute([$code, $id]);
    $n = 0;
    foreach (wsm_mkt_audience_email($pdo, (string) $nl['segment']) as $d) {
        $r = wsm_nl_render($pdo, $nl, $d['lang'], $d, $code);
        $mid = wsm_mail_queue($pdo, [
            'email' => $d['email'], 'direction' => 'wyjscie', 'subject' => $r['temat'], 'body' => $r['tekst'],
            'html' => $r['html'], 'unsub_url' => wsm_mkt_unsub_oneclick($d['email'], 'nl:' . $id),
            'template_code' => 'newsletter', 'event_key' => wsm_nl_event_key($id, $d['email']), 'actor' => $actor ?: 'newsletter',
        ]);
        if ($mid > 0) $n++;
    }
    $pdo->prepare("UPDATE wsm_newsletters SET status = 'wyslana', odbiorcow = ?, sent_at = ? WHERE id = ?")
        ->execute([$n, date('Y-m-d H:i:s'), $id]);
    if (function_exists('wsm_audit')) wsm_audit($pdo, $actor, 'Wysyłka newslettera', 'wsm_newsletters #' . $id . ' — ' . $n, 'Sieć');
    $m = 'W kolejce: ' . $n . ' wiadomości. Wychodzą partiami, żeby nie spalić reputacji domeny.';
    if (!wsm_mail_enabled()) $m .= ' Poczta nie jest jeszcze skonfigurowana — wiadomości poczekają (Integracje → Poczta).';
    if ($uwaga !== '') $m .= ' Uwaga: ' . $uwaga;
    return [$n, $m];
}

/** Les éditions, avec ce qu'elles ont fait. */
function wsm_nl_list(PDO $pdo): array {
    try { $rows = $pdo->query("SELECT * FROM wsm_newsletters ORDER BY id DESC")->fetchAll(); }
    catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $n) {
        $s = ['kolejka' => 0, 'wyslana' => 0, 'blad' => 0];
        $st = $pdo->prepare("SELECT status, COUNT(*) AS c FROM wsm_messages WHERE event_key LIKE ? GROUP BY status");
        $st->execute(['nl-' . (int) $n['id'] . '-%']);
        foreach ($st->fetchAll() as $r) $s[(string) $r['status']] = (int) $r['c'];
        $out[] = $n + ['stat' => $s, 'link' => wsm_mkt_link_stats($pdo, (string) $n['link_code']),
                       'wypisy' => wsm_mkt_wypisy($pdo, 'nl:' . (int) $n['id'])];
    }
    return $out;
}
