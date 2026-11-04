<?php
// ============================================================================
//  reklama.php — Google et Meta : être trouvé, mesurer, vendre ailleurs.
//
//  QUATRE CHOSES, ET UNE RÈGLE QUI LES TIENT :
//
//   · les codes de vérification (Search Console, Bing, domaine Facebook) :
//     des balises <meta> sans effet sur le visiteur ;
//   · les balises de mesure (GA4, Google Ads, pixel Meta) ;
//   · la Conversions API : le ZAKUP envoyé par le serveur à Meta ;
//   · le flux produits /feed/produkty.xml, lu par Google Merchant Center
//     (onglet « Zakupy ») et par le catalogue Meta (boutique Facebook et
//     Instagram).
//
//  LA RÈGLE : RIEN NE MESURE QUELQU'UN QUI N'A PAS DIT OUI. En Pologne, un
//  cookie qui n'est pas indispensable demande un accord préalable (art. 399
//  Prawo komunikacji elektronicznej, et le RGPD derrière). Les scripts de
//  Google et de Meta ne sont donc PAS dans la page : la bannière les charge
//  après le « oui », catégorie par catégorie. Et le serveur n'envoie un achat
//  à Meta que si la caisse a vu l'accord « marketingowe » — sinon ce serait
//  contourner, par le serveur, le refus donné dans le navigateur.
//
//  CE QUI EST PUBLIC ET CE QUI NE L'EST PAS. Un identifiant de balise (G-…,
//  AW-…, l'ID du pixel) se lit dans le code de n'importe quelle page : il va
//  au navigateur. Les jetons (Conversions API, page Facebook) ouvrent la
//  publicité et la page de la marque : ils ne quittent jamais le serveur, ne
//  s'écrivent dans aucun message d'erreur ni aucun journal.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const WSM_ZGODY_COOKIE   = 'wsm_zgody';
const WSM_ZGODY_DNI      = 365;          // un an : la durée qu'annonce la bannière
const WSM_GRAPH_DOMYSLNA = 'v23.0';
const WSM_CAPI_PROBY     = 5;            // au-delà, l'événement reste « błąd » et se relance à la main

// ---------------------------------------------------------------------------
//  LES RÉGLAGES, LUS ET VÉRIFIÉS
// ---------------------------------------------------------------------------

/**
 * Les réglages Google et Meta, chacun vérifié à sa forme.
 *
 * UN IDENTIFIANT MAL COLLÉ NE PART PAS DANS LA PAGE. « G- 12AB » avec une
 * espace, ou l'ID du pixel collé dans le champ du jeton, et la balise se
 * chargerait pour rien — ou pire, enverrait les achats sur le compte d'un
 * autre. La valeur refusée est vidée ici et nommée dans 'bledne' : l'écran
 * le dit, la page ne charge rien.
 */
function wsm_ads_cfg(): array {
    $c = wsm_config();
    $v = static function (string $sect, string $k) use ($c): string {
        $s = trim((string) (($c[$sect] ?? [])[$k] ?? ''));
        return preg_match('/^x{2,}$/i', $s) ? '' : $s;
    };
    $formes = [
        // cle      => [section, champ, motif, libellé]
        'gsc'       => ['google', 'site_verification', '/^[A-Za-z0-9_\-]{10,100}$/', 'kod Search Console'],
        'ga4'       => ['google', 'ga4_id', '/^G-[A-Z0-9]{4,20}$/', 'identyfikator GA4 (G-…)'],
        'ads'       => ['google', 'ads_id', '/^AW-\d{6,15}$/', 'identyfikator Google Ads (AW-…)'],
        'ads_label' => ['google', 'ads_label', '/^[A-Za-z0-9_\-]{4,60}$/', 'etykieta konwersji Google Ads'],
        'merchant'  => ['google', 'merchant_id', '/^\d{5,15}$/', 'ID Merchant Center'],
        'bing'      => ['bing', 'site_verification', '/^[A-Za-z0-9]{16,64}$/', 'kod Bing'],
        'pixel'     => ['meta', 'pixel_id', '/^\d{8,20}$/', 'ID piksela Meta (same cyfry)'],
        'test_code' => ['meta', 'test_event_code', '/^TEST[A-Z0-9]{2,20}$/i', 'kod zdarzeń testowych (TEST…)'],
        'fb_domain' => ['meta', 'domain_verification', '/^[a-z0-9]{16,64}$/', 'kod weryfikacji domeny Facebook'],
        'page_id'   => ['meta', 'page_id', '/^\d{5,25}$/', 'ID strony Facebook'],
        'ig'        => ['meta', 'ig_user_id', '/^\d{5,25}$/', 'ID konta Instagram'],
        'graph'     => ['meta', 'graph_version', '/^v\d{1,2}\.\d$/', 'wersja Graph API (np. v23.0)'],
    ];
    $out = ['bledne' => []];
    foreach ($formes as $cle => [$sect, $k, $re, $lib]) {
        $val = $v($sect, $k);
        if ($val !== '' && !preg_match($re, $val)) { $out['bledne'][$cle] = $lib; $val = ''; }
        $out[$cle] = $val;
    }
    if ($out['graph'] === '') $out['graph'] = WSM_GRAPH_DOMYSLNA;
    // Les deux jetons : aucune forme fixe à vérifier (Meta en change), mais
    // jamais d'espace ni de retour à la ligne — un copier-coller raté.
    foreach (['capi' => 'capi_token', 'page_token' => 'page_token'] as $cle => $k) {
        $val = $v('meta', $k);
        if ($val !== '' && !preg_match('/^[A-Za-z0-9_\-|.]{20,600}$/', $val)) { $out['bledne'][$cle] = 'token Meta'; $val = ''; }
        $out[$cle] = $val;
    }
    // Une étiquette Ads sans identifiant ne sert à rien, et inversement :
    // la conversion se compte avec les deux, jamais avec un seul.
    return $out;
}

/** Ce qui se charge, par catégorie d'accord. Vide = pas de bannière du tout. */
function wsm_ads_kategorie(array $cfg): array {
    $k = [];
    if ($cfg['ga4'] !== '') $k['analityczne'] = true;
    if ($cfg['pixel'] !== '' || $cfg['ads'] !== '') $k['marketingowe'] = true;
    return $k;
}

/**
 * Les balises de vérification. Aucun effet sur le visiteur, aucun cookie :
 * elles peuvent être là avant tout accord.
 */
function wsm_ads_meta_html(array $cfg): string {
    $h = '';
    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    if ($cfg['gsc'] !== '')       $h .= '<meta name="google-site-verification" content="' . $e($cfg['gsc']) . '">' . "\n";
    if ($cfg['bing'] !== '')      $h .= '<meta name="msvalidate.01" content="' . $e($cfg['bing']) . '">' . "\n";
    if ($cfg['fb_domain'] !== '') $h .= '<meta name="facebook-domain-verification" content="' . $e($cfg['fb_domain']) . '">' . "\n";
    return $h;
}

// ---------------------------------------------------------------------------
//  L'ACCORD DU VISITEUR
// ---------------------------------------------------------------------------

/**
 * Lit le cookie d'accord : « v1-a1-m0 » = analityczne oui, marketingowe non.
 * Null = pas encore choisi (la bannière s'affiche). Une valeur abîmée vaut
 * « pas choisi », jamais « oui ».
 */
function wsm_zgody_odczyt(?string $raw = null): ?array {
    $raw ??= (string) ($_COOKIE[WSM_ZGODY_COOKIE] ?? '');
    if (!preg_match('/^v1-a([01])-m([01])$/', $raw, $m)) return null;
    return ['analityczne' => $m[1] === '1', 'marketingowe' => $m[2] === '1'];
}

/**
 * La configuration que la page passe à zgody.js : identifiants PUBLICS et
 * événements de la page, jamais un jeton.
 *
 * @param array $zdarzenia  [['n' => 'view_item'|'add_to_cart'|'begin_checkout'|'purchase',
 *                            'id' => identifiant d'événement, 'value' => zł, 'items' => […]], …]
 */
function wsm_ads_js_config(array $cfg, array $zdarzenia = []): array {
    return [
        'ga4'     => $cfg['ga4'],
        'ads'     => $cfg['ads'],
        'adsLabel'=> $cfg['ads'] !== '' ? $cfg['ads_label'] : '',
        'pixel'   => $cfg['pixel'],
        'cookie'  => WSM_ZGODY_COOKIE,
        'days'    => WSM_ZGODY_DNI,
        'events'  => array_values($zdarzenia),
    ];
}

/** Un produit tel que les événements le décrivent (GA4 comme Meta). */
function wsm_ads_item(array $p, int $qty = 1): array {
    return ['id' => (string) ($p['id'] ?? ''), 'name' => (string) ($p['name'] ?? ''),
            'price' => round(((int) ($p['price'] ?? 0)) / 100, 2), 'qty' => max(1, $qty),
            'cat' => (string) ($p['category'] ?? '')];
}

/** L'événement d'achat d'une commande — même identifiant que celui envoyé par le serveur. */
function wsm_ads_purchase_event(array $order): array {
    $items = [];
    foreach ((array) ($order['items'] ?? []) as $it) {
        $items[] = ['id' => (string) ($it['product_id'] ?? ''), 'name' => (string) ($it['name'] ?? ''),
                    'price' => round(((int) ($it['unit_gross'] ?? 0)) / 100, 2), 'qty' => (int) ($it['qty'] ?? 1)];
    }
    return ['n' => 'purchase', 'id' => wsm_capi_event_id($order), 'tx' => (string) ($order['code'] ?? ''),
            'value' => round(((int) ($order['total_gross'] ?? 0)) / 100, 2),
            'shipping' => round(((int) ($order['shipping_gross'] ?? 0)) / 100, 2),
            'items' => $items];
}

// ---------------------------------------------------------------------------
//  LA COMMANDE SE SOUVIENT DE L'ACCORD
// ---------------------------------------------------------------------------

function wsm_ads_ensure(PDO $pdo): void {
    foreach (['wsm_ad_orders', 'wsm_capi_events'] as $t) {
        if (!wsm_table_exists($pdo, $t)) { wsm_apply_schema($pdo); return; }
    }
}

/**
 * À la caisse : l'accord tel qu'il était au moment de commander.
 *
 * MOINS ON GARDE, MIEUX C'EST. Sans accord marketing, on ne retient que le
 * « non » — ni adresse IP, ni navigateur, ni identifiant Facebook : il n'y a
 * rien à envoyer, donc rien à garder. Avec l'accord, on garde ce que Meta
 * demande pour reconnaître l'achat (_fbp, _fbc, IP, navigateur).
 */
function wsm_ads_order_capture(PDO $pdo, int $orderId, ?array $zgody, array $cookies, string $ip, string $ua, string $url = ''): void {
    if ($orderId <= 0) return;
    wsm_ads_ensure($pdo);
    $m = !empty($zgody['marketingowe']);
    $a = !empty($zgody['analityczne']);
    $fbp = $m && preg_match('/^fb\.\d\.\d{10,16}\.\d{3,30}$/', (string) ($cookies['_fbp'] ?? '')) ? (string) $cookies['_fbp'] : '';
    $fbc = $m && preg_match('/^fb\.\d\.\d{10,16}\.[A-Za-z0-9_\-]{10,500}$/', (string) ($cookies['_fbc'] ?? '')) ? (string) $cookies['_fbc'] : '';
    try {
        $pdo->prepare("INSERT INTO wsm_ad_orders (order_id, analityczne, marketingowe, fbp, fbc, ip, ua, page_url, created_at)
                       VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$orderId, $a ? 1 : 0, $m ? 1 : 0, $fbp, mb_substr($fbc, 0, 255),
                       $m ? mb_substr($ip, 0, 45) : '', $m ? mb_substr($ua, 0, 255) : '',
                       $m ? mb_substr($url, 0, 255) : '', date('Y-m-d H:i:s')]);
    } catch (Throwable $e) { /* déjà notée : la première fait foi */ }
}

function wsm_ads_order_row(PDO $pdo, int $orderId): ?array {
    try {
        $st = $pdo->prepare("SELECT * FROM wsm_ad_orders WHERE order_id = ?");
        $st->execute([$orderId]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

// ---------------------------------------------------------------------------
//  GRAPH API (Meta) — un seul client pour la Conversions API et les posts
// ---------------------------------------------------------------------------

/**
 * Le transport HTTP, remplaçable par les tests.
 *   fn(string $method, string $url, array $params, array $files): [int $code, string $body]
 * Les paramètres tableau partent en JSON (attached_media, data…).
 */
function wsm_graph_transport(?callable $set = null): callable {
    static $t = null;
    if ($set !== null) { $t = $set; return $t; }
    return $t ?? 'wsm_graph_http';
}

function wsm_graph_http(string $method, string $url, array $params, array $files = []): array {
    if (!function_exists('curl_init')) return [0, '{"error":{"message":"brak rozszerzenia curl"}}'];
    $flat = [];
    foreach ($params as $k => $v) $flat[$k] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $v;
    $ch = curl_init();
    if ($method === 'GET') {
        curl_setopt($ch, CURLOPT_URL, $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($flat));
    } else {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        if ($files) {
            foreach ($files as $k => $path) $flat[$k] = new CURLFile($path, 'image/jpeg', basename($path));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $flat);           // multipart
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($flat));
        }
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
                            CURLOPT_TIMEOUT => $files ? 60 : 15, CURLOPT_USERAGENT => 'SzokoShop/1.0']);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) return [0, json_encode(['error' => ['message' => 'połączenie: ' . $err]])];
    return [$code, (string) $body];
}

/**
 * Un appel Graph. Renvoie ['ok', 'code', 'data', 'error'].
 *
 * LE JETON NE SORT JAMAIS DANS UN MESSAGE. Une erreur de Meta peut citer
 * l'adresse appelée ; on remplace toute occurrence du jeton avant de la
 * rendre, parce que ce message finit à l'écran et dans le journal.
 */
function wsm_graph_call(string $method, string $path, array $params, string $token, array $files = [], ?string $version = null): array {
    $version ??= wsm_ads_cfg()['graph'];
    $url = 'https://graph.facebook.com/' . $version . '/' . ltrim($path, '/');
    $params['access_token'] = $token;
    [$code, $body] = (wsm_graph_transport())($method, $url, $params, $files);
    $data = json_decode($body, true);
    $data = is_array($data) ? $data : [];
    $ok = $code >= 200 && $code < 300 && !isset($data['error']);
    $msg = '';
    if (!$ok) {
        $e = (array) ($data['error'] ?? []);
        $msg = trim((string) ($e['error_user_msg'] ?? '')) !== '' ? (string) $e['error_user_msg'] : (string) ($e['message'] ?? ('HTTP ' . $code));
        if (isset($e['code'])) $msg .= ' (kod ' . (int) $e['code'] . ')';
        if ($token !== '') $msg = str_replace($token, '•••', $msg);
        $msg = mb_substr($msg, 0, 400);
    }
    return ['ok' => $ok, 'code' => $code, 'data' => $data, 'error' => $msg];
}

// ---------------------------------------------------------------------------
//  CONVERSIONS API — l'achat, envoyé par le serveur
// ---------------------------------------------------------------------------

/**
 * L'identifiant d'événement : le même dans le navigateur (pixel) et ici.
 * Meta dédoublonne sur (event_name, event_id) — sans lui, chaque achat
 * compterait deux fois et la publicité paraîtrait deux fois plus rentable.
 */
function wsm_capi_event_id(array $order): string {
    return 'zam-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($order['code'] ?? ''));
}

/** SHA-256 d'une valeur normalisée — Meta ne reçoit jamais l'adresse en clair. */
function wsm_capi_hash(string $v): string {
    return $v === '' ? '' : hash('sha256', $v);
}

/** Téléphone au format Meta : chiffres seuls, indicatif compris (48 par défaut). */
function wsm_capi_phone(string $p): string {
    $d = preg_replace('/\D+/', '', $p) ?? '';
    if ($d === '') return '';
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    if (strlen($d) === 9) $d = '48' . $d;
    return strlen($d) >= 10 ? $d : '';
}

/**
 * Le corps envoyé à Meta pour une commande payée.
 *
 * Les données de la personne sont normalisées PUIS hachées (règles de Meta :
 * minuscules, sans espaces, pays en deux lettres). L'adresse IP et le
 * navigateur restent en clair : c'est ce que l'API exige, et ils n'ont été
 * gardés que parce que la personne a dit oui.
 */
function wsm_capi_payload(array $order, array $cap, array $cfg, string $shopUrl): array {
    $low = static fn(string $s): string => mb_strtolower(trim($s), 'UTF-8');
    $nospace = static fn(string $s): string => preg_replace('/[\s\-.,]+/u', '', $s) ?? '';
    $ship = (array) ($order['ship'] ?? []);
    $bill = (array) ($order['bill'] ?? []);
    $ville = (string) (($ship['city'] ?? '') !== '' ? $ship['city'] : ($bill['city'] ?? ''));
    $cp    = (string) (($ship['postcode'] ?? '') !== '' ? $ship['postcode'] : ($bill['postcode'] ?? ''));
    $pays  = (string) (($ship['country'] ?? '') !== '' ? $ship['country'] : ($bill['country'] ?? 'PL'));
    $u = array_filter([
        'em' => [wsm_capi_hash($low((string) ($order['email'] ?? '')))],
        'ph' => [wsm_capi_hash(wsm_capi_phone((string) ($order['phone'] ?? '')))],
        'fn' => [wsm_capi_hash($low((string) ($order['first_name'] ?? '')))],
        'ln' => [wsm_capi_hash($low((string) ($order['last_name'] ?? '')))],
        'ct' => [wsm_capi_hash($nospace($low($ville)))],
        'zp' => [wsm_capi_hash($nospace($low($cp)))],
        'country' => [wsm_capi_hash($low($pays))],
        'external_id' => [wsm_capi_hash($low((string) ($order['email'] ?? '')))],
        'client_ip_address' => (string) ($cap['ip'] ?? ''),
        'client_user_agent' => (string) ($cap['ua'] ?? ''),
        'fbp' => (string) ($cap['fbp'] ?? ''),
        'fbc' => (string) ($cap['fbc'] ?? ''),
    ], static fn($v) => is_array($v) ? ($v[0] ?? '') !== '' : $v !== '');
    $contents = []; $ids = []; $n = 0;
    foreach ((array) ($order['items'] ?? []) as $it) {
        $id = (string) ($it['product_id'] ?? '');
        if ($id === '') continue;
        $ids[] = $id;
        $q = (int) ($it['qty'] ?? 1);
        $n += $q;
        $contents[] = ['id' => $id, 'quantity' => $q, 'item_price' => round(((int) ($it['unit_gross'] ?? 0)) / 100, 2)];
    }
    $t = strtotime((string) ($order['paid_at'] ?? '')) ?: time();
    // Meta refuse un événement de plus de sept jours : un webhook rejoué
    // tard part avec l'heure d'envoi, pas avec une heure trop ancienne.
    if ($t < time() - 6 * 86400 || $t > time() + 600) $t = time();
    $source = trim((string) ($cap['page_url'] ?? ''));
    if (!preg_match('#^https?://#', $source)) $source = rtrim($shopUrl, '/') . '/zamowienie';
    $ev = [
        'event_name' => 'Purchase',
        'event_time' => $t,
        'event_id' => wsm_capi_event_id($order),
        'action_source' => 'website',
        'event_source_url' => $source,
        'user_data' => $u,
        'custom_data' => [
            'currency' => 'PLN',
            'value' => round(((int) ($order['total_gross'] ?? 0)) / 100, 2),
            'content_type' => 'product',
            'content_ids' => array_values(array_unique($ids)),
            'contents' => $contents,
            'num_items' => $n,
            'order_id' => (string) ($order['code'] ?? ''),
        ],
    ];
    $body = ['data' => [$ev]];
    if ($cfg['test_code'] !== '') $body['test_event_code'] = $cfg['test_code'];
    return $body;
}

/** Prêt à envoyer des achats ? Le pixel ET le jeton, sinon rien ne part. */
function wsm_capi_ready(?array $cfg = null): bool {
    $cfg ??= wsm_ads_cfg();
    return $cfg['pixel'] !== '' && $cfg['capi'] !== '';
}

/**
 * Appelé quand une commande devient payée (wsm_order_mark_paid).
 *
 * NE FAIT JAMAIS ÉCHOUER LE PAIEMENT. Tout est sous try : un Meta en panne,
 * une table absente, et l'encaissement reste écrit. L'événement est noté
 * puis tenté une fois ; s'il échoue, la file (kolejka.php, l'écran Meta) le
 * relance.
 */
function wsm_capi_on_paid(PDO $pdo, int $orderId): void {
    try {
        $cfg = wsm_ads_cfg();
        if (!wsm_capi_ready($cfg)) return;
        $cap = wsm_ads_order_row($pdo, $orderId);
        if (!$cap || (int) $cap['marketingowe'] !== 1) return;          // pas d'accord : rien ne part
        // PAYÉE, SINON RIEN — et surtout pas de ligne en file : une ligne
        // « pominiete » posée trop tôt bloquerait (clé unique) l'envoi du vrai
        // paiement, qui arrive après.
        $st = $pdo->prepare("SELECT payment_status FROM wsm_orders WHERE id = ?");
        $st->execute([$orderId]);
        if ((string) $st->fetchColumn() !== 'oplacone') return;
        $ins = $pdo->prepare("INSERT INTO wsm_capi_events (order_id, event_name, status, attempts, created_at)
                              VALUES (?, 'Purchase', 'nowe', 0, ?)");
        try { $ins->execute([$orderId, date('Y-m-d H:i:s')]); }
        catch (Throwable $e) { return; }                                // déjà notée : le webhook rejoué ne renvoie pas
        wsm_capi_send($pdo, (int) $pdo->lastInsertId());
    } catch (Throwable $e) { /* le paiement d'abord */ }
}

/**
 * Envoie un événement de la file. On le RÉCLAME d'abord (nowe/błąd →
 * wysyłanie) : deux pompes simultanées ne l'envoient pas deux fois.
 */
function wsm_capi_send(PDO $pdo, int $eventId): array {
    $claim = $pdo->prepare("UPDATE wsm_capi_events SET status = 'wysylanie', attempts = attempts + 1, tried_at = ?
                             WHERE id = ? AND status IN ('nowe','blad')");
    $claim->execute([date('Y-m-d H:i:s'), $eventId]);
    if ($claim->rowCount() === 0) return ['ok' => false, 'error' => 'już wysyłane albo wysłane'];
    $st = $pdo->prepare("SELECT * FROM wsm_capi_events WHERE id = ?");
    $st->execute([$eventId]);
    $ev = $st->fetch();
    $fin = function (string $status, string $msg) use ($pdo, $eventId): void {
        $pdo->prepare("UPDATE wsm_capi_events SET status = ?, response = ?, sent_at = ? WHERE id = ?")
            ->execute([$status, mb_substr($msg, 0, 500), $status === 'wyslane' ? date('Y-m-d H:i:s') : null, $eventId]);
    };
    $cfg = wsm_ads_cfg();
    if (!wsm_capi_ready($cfg)) { $fin('blad', 'brak piksela albo tokenu Conversions API'); return ['ok' => false, 'error' => 'brak konfiguracji']; }
    require_once __DIR__ . '/shop.php';
    $order = wsm_order_by_id($pdo, (int) $ev['order_id']);
    $cap = wsm_ads_order_row($pdo, (int) $ev['order_id']);
    if (!$order || !$cap || (int) $cap['marketingowe'] !== 1 || (string) $order['payment_status'] !== 'oplacone') {
        $fin('pominiete', 'zamówienie nieopłacone albo bez zgody marketingowej');
        return ['ok' => false, 'error' => 'pominięte'];
    }
    $body = wsm_capi_payload($order, $cap, $cfg, wsm_shop_base_url());
    $r = wsm_graph_call('POST', $cfg['pixel'] . '/events', $body, $cfg['capi'], [], $cfg['graph']);
    if ($r['ok']) {
        $fin('wyslane', 'przyjęte: ' . (int) ($r['data']['events_received'] ?? 0) . ($cfg['test_code'] !== '' ? ' (test)' : ''));
        return ['ok' => true, 'error' => ''];
    }
    $fin('blad', $r['error']);
    return ['ok' => false, 'error' => $r['error']];
}

/**
 * La file : nouvelles, puis erreurs à retenter avec un délai qui double
 * (2, 4, 8, 16 minutes). Au-delà de WSM_CAPI_PROBY, on s'arrête : c'est une
 * configuration à corriger, pas une panne passagère.
 */
function wsm_capi_pump(PDO $pdo, int $max = 10): array {
    $out = ['wyslane' => 0, 'bledy' => 0];
    try {
        wsm_ads_ensure($pdo);
        if (!wsm_capi_ready()) return $out;
        $rows = $pdo->query("SELECT id, status, attempts, tried_at FROM wsm_capi_events
                              WHERE status IN ('nowe','blad') AND attempts < " . WSM_CAPI_PROBY . "
                              ORDER BY id LIMIT 50")->fetchAll();
        foreach ($rows as $r) {
            if ($max-- <= 0) break;
            if ((string) $r['status'] === 'blad') {
                $wait = 60 * (2 ** max(1, (int) $r['attempts']));
                if ((strtotime((string) $r['tried_at']) ?: 0) > time() - $wait) continue;
            }
            $res = wsm_capi_send($pdo, (int) $r['id']);
            $res['ok'] ? $out['wyslane']++ : $out['bledy']++;
        }
    } catch (Throwable $e) {}
    return $out;
}

/** Relance à la main (écran Meta) : remet les erreurs dans la file. */
function wsm_capi_retry_all(PDO $pdo): int {
    $u = $pdo->prepare("UPDATE wsm_capi_events SET status = 'blad', attempts = 0, tried_at = NULL WHERE status = 'blad'");
    $u->execute();
    return $u->rowCount();
}

function wsm_capi_log(PDO $pdo, int $n = 30): array {
    try {
        wsm_ads_ensure($pdo);
        $st = $pdo->prepare("SELECT e.*, o.code FROM wsm_capi_events e LEFT JOIN wsm_orders o ON o.id = e.order_id
                              ORDER BY e.id DESC LIMIT " . max(1, min(200, $n)));
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Combien d'acheteurs disent oui — pour savoir ce que les chiffres de Meta ne verront jamais. */
function wsm_ads_consent_stats(PDO $pdo, int $jours = 30): array {
    try {
        wsm_ads_ensure($pdo);
        $st = $pdo->prepare("SELECT COUNT(*) n, SUM(marketingowe) m, SUM(analityczne) a FROM wsm_ad_orders WHERE created_at >= ?");
        $st->execute([date('Y-m-d H:i:s', time() - $jours * 86400)]);
        $r = $st->fetch() ?: [];
        return ['n' => (int) ($r['n'] ?? 0), 'marketingowe' => (int) ($r['m'] ?? 0), 'analityczne' => (int) ($r['a'] ?? 0)];
    } catch (Throwable $e) { return ['n' => 0, 'marketingowe' => 0, 'analityczne' => 0]; }
}

// ---------------------------------------------------------------------------
//  LE FLUX PRODUITS — Google Merchant Center et catalogue Meta
// ---------------------------------------------------------------------------

/** Un GTIN (EAN-8, UPC-12, EAN-13, GTIN-14) avec la bonne clé de contrôle. */
function wsm_gtin_ok(string $g): bool {
    $g = trim($g);
    if (!preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $g)) return false;
    if (preg_match('/^0+$/', $g)) return false;
    $sum = 0;
    $digits = array_map('intval', str_split(substr($g, 0, -1)));
    $digits = array_reverse($digits);
    foreach ($digits as $i => $d) $sum += $d * ($i % 2 === 0 ? 3 : 1);
    return (10 - $sum % 10) % 10 === (int) substr($g, -1);
}

/**
 * La catégorie Google, déduite de la catégorie de la boutique.
 * Écrite en toutes lettres (anglais) : Google l'accepte ainsi, et un chiffre
 * de taxonomie se trompe sans que personne ne puisse le relire.
 */
function wsm_feed_google_category(string $cat, string $nom = ''): string {
    $t = mb_strtolower($cat . ' ' . $nom, 'UTF-8');
    if (preg_match('/czekol|kakao|pralin|trufl|bombon|cukierk|chocolat/u', $t)) return 'Food, Beverages & Tobacco > Food Items > Candy & Chocolate';
    if (preg_match('/mąk|mak[ai]|cukr|polew|dekor|posyp|barwnik|aromat|żelatyn|drożdż|masa|nadzien|wypiek|bak/u', $t)) {
        return 'Food, Beverages & Tobacco > Food Items > Cooking & Baking Ingredients';
    }
    return 'Food, Beverages & Tobacco > Food Items';
}

/** Texte brut pour un flux : sans balises, sans markdown, sans caractères interdits en XML. */
function wsm_feed_text(string $s, int $max): string {
    $s = strip_tags($s);
    $s = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $s) ?? $s;          // images markdown
    $s = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $s) ?? $s;       // liens markdown
    $s = preg_replace('/[#*_`>]+/u', '', $s) ?? $s;
    $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}

/** Le transporteur le moins cher vers la Pologne (prix TTC en grosze), ou null. */
function wsm_ads_ship_min(PDO $pdo): ?array {
    try {
        require_once __DIR__ . '/shop.php';
        $best = null;
        foreach (wsm_shipping_methods($pdo, 'pl', 'PL') as $m) {
            if ((int) $m['min_weight_g'] > 0) continue;                    // palettes : pas un tarif « à partir de »
            if ($best === null || $m['price'] < $best['price']) $best = $m;
        }
        return $best ? ['price' => (int) $best['price'], 'free_from' => (int) $best['free_from']] : null;
    } catch (Throwable $e) { return null; }
}

/** Délai de préparation promis, en jours (Superadmin → obiecany czas wysyłki). */
function wsm_ads_handling_days(): int {
    $h = (int) trim((string) (wsm_config()['orders']['ship_h'] ?? ''));
    if ($h < 1 || $h > 720) $h = 24;
    return max(1, (int) ceil($h / 24));
}

/**
 * Les produits du flux, et ceux qui en sont écartés avec la raison.
 *
 * ON N'ENVOIE QUE CE QUI SERA ACCEPTÉ. Un produit sans photo ou sans prix
 * est refusé par Google — et des refus répétés font suspendre le compte
 * Merchant entier. Il est donc écarté ici, et l'écran « Google i SEO » dit
 * pourquoi, au lieu que Google le dise trois jours plus tard en anglais.
 *
 * LE PRIX DU FLUX EST LE PRIX DE LA PAGE : même source (wsm_shop_products),
 * aucun recalcul. Un écart entre les deux est la première cause de
 * suspension dans Merchant Center.
 */
function wsm_feed_produkty(PDO $pdo, string $lang, string $shopUrl, bool $jpg = true): array {
    require_once __DIR__ . '/shop.php';
    $shopUrl = rtrim($shopUrl, '/');
    $marque = wsm_brand_name();
    $ship = wsm_ads_ship_min($pdo);
    $items = []; $out = [];
    foreach (wsm_shop_products($pdo, $lang) as $p) {
        $pow = [];
        $title = wsm_feed_text((string) $p['name'], 150);
        if ($title === '') $pow[] = 'brak nazwy';
        if ((int) $p['price'] <= 0) $pow[] = 'brak ceny';
        $img = trim((string) $p['image']);
        if ($img === '') $pow[] = 'brak zdjęcia';
        if ($pow) { $out[] = ['id' => $p['id'], 'name' => $p['name'], 'powody' => $pow]; continue; }

        $link = $shopUrl . '/p/' . rawurlencode((string) $p['slug']) . ($lang !== 'pl' ? '?lang=' . rawurlencode($lang) : '');
        if (preg_match('#^https://#i', $img)) {
            $imgUrl = $img;
        } else {
            $j = $jpg && function_exists('wsm_media_jpg') ? wsm_media_jpg($img, 'feed') : null;
            $imgUrl = $shopUrl . '/' . ltrim($j ?? $img, '/');
        }
        $desc = wsm_feed_text(trim((string) $p['desc']) !== '' ? (string) $p['desc'] : trim($p['subtitle'] . ' ' . $p['name']), 5000);
        $ean = preg_replace('/\D/', '', (string) $p['ean']) ?? '';
        $brand = !empty($p['brand']['name']) ? (string) $p['brand']['name'] : $marque;
        $it = [
            'id' => (string) $p['id'], 'title' => $title, 'description' => $desc, 'link' => $link,
            'image_link' => $imgUrl,
            'availability' => (int) $p['stock'] > 0 ? 'in_stock' : 'out_of_stock',
            'price' => number_format(((int) $p['price']) / 100, 2, '.', '') . ' PLN',
            'condition' => 'new', 'brand' => wsm_feed_text($brand, 70),
            'google_product_category' => wsm_feed_google_category((string) $p['category'], (string) $p['name']),
            'product_type' => wsm_feed_text((string) $p['category'], 750),
        ];
        if (wsm_gtin_ok($ean)) $it['gtin'] = $ean;
        elseif (trim((string) $p['sku']) !== '') $it['mpn'] = wsm_feed_text((string) $p['sku'], 70);
        if (!isset($it['gtin']) && !isset($it['mpn'])) $it['identifier_exists'] = 'no';
        $w = (int) $p['weight_g'];
        if ($w > 0) {
            $it['shipping_weight'] = $w . ' g';
            // Le prix au kilo : la page l'affiche, Google le demande pour
            // l'alimentaire vendu au poids dans l'UE.
            $it['unit_pricing_measure'] = $w . 'g';
            $it['unit_pricing_base_measure'] = '1kg';
        }
        if ($ship !== null) {
            $prix = ($ship['free_from'] > 0 && (int) $p['price'] >= $ship['free_from']) ? 0 : $ship['price'];
            $it['shipping'] = ['country' => 'PL', 'service' => 'Standard', 'price' => number_format($prix / 100, 2, '.', '') . ' PLN'];
        }
        $items[] = $it;
    }
    return ['items' => $items, 'pominiete' => $out];
}

/** Le flux au format RSS 2.0 + espace de noms g: — lu tel quel par Google ET par Meta. */
function wsm_feed_xml(array $items, string $titre, string $lien): string {
    $x = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $o = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
       . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n<channel>\n"
       . '<title>' . $x($titre) . "</title>\n<link>" . $x($lien) . "</link>\n"
       . '<description>' . $x($titre) . "</description>\n";
    foreach ($items as $it) {
        $o .= "<item>\n";
        foreach ($it as $k => $v) {
            if ($k === 'title' || $k === 'link' || $k === 'description') {
                $o .= '  <' . $k . '>' . $x((string) $v) . '</' . $k . ">\n";
            } elseif (is_array($v)) {
                $o .= '  <g:' . $k . '>';
                foreach ($v as $sk => $sv) $o .= '<g:' . $sk . '>' . $x((string) $sv) . '</g:' . $sk . '>';
                $o .= '</g:' . $k . ">\n";
            } else {
                $o .= '  <g:' . $k . '>' . $x((string) $v) . '</g:' . $k . ">\n";
            }
        }
        $o .= "</item>\n";
    }
    return $o . "</channel>\n</rss>\n";
}

// ---------------------------------------------------------------------------
//  L'AUDIT SEO, produit par produit
// ---------------------------------------------------------------------------

/**
 * Ce qui empêche chaque produit d'être bien trouvé, du plus grave au moins
 * grave. Pas de note magique : des constats, chacun avec son geste.
 */
function wsm_seo_audit(PDO $pdo, array $langs): array {
    require_once __DIR__ . '/shop.php';
    $pl = wsm_shop_products($pdo, 'pl');
    $traduit = [];
    foreach ($langs as $l) {
        if ($l === 'pl') continue;
        $st = $pdo->prepare("SELECT k FROM wsm_shop_i18n WHERE lang = ? AND k LIKE 'product.%.name' AND TRIM(v) <> ''");
        $st->execute([$l]);
        $traduit[$l] = array_flip(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)));
    }
    $vus = [];
    foreach ($pl as $p) $vus[mb_strtolower(trim((string) $p['name']), 'UTF-8')][] = $p['id'];
    $out = [];
    foreach ($pl as $p) {
        $u = []; $pts = 100;
        $add = static function (string $msg, int $poids, string $niv) use (&$u, &$pts): void { $u[] = [$niv, $msg]; $pts -= $poids; };
        $nl = mb_strlen(trim((string) $p['name']));
        $dl = mb_strlen(wsm_feed_text((string) $p['desc'], 10000));
        if ((int) $p['price'] <= 0) $add('brak ceny — poza Google Zakupy', 30, 'blad');
        if (trim((string) $p['image']) === '') $add('brak zdjęcia — poza Google Zakupy i katalogiem Meta', 30, 'blad');
        if ($nl < 10) $add('nazwa za krótka (' . $nl . ' zn.) — dopisz rodzaj i gramaturę', 10, 'uwaga');
        if ($nl > 70) $add('nazwa za długa (' . $nl . ' zn.) — Google utnie po ~70', 5, 'uwaga');
        if ($dl === 0) $add('brak opisu — Google pokaże przypadkowy fragment strony', 20, 'blad');
        elseif ($dl < 150) $add('opis krótki (' . $dl . ' zn.) — celuj w 150+: skład, smak, zastosowanie', 10, 'uwaga');
        $ean = preg_replace('/\D/', '', (string) $p['ean']) ?? '';
        if ($ean === '') $add('brak EAN — w Google Zakupy produkt dostaje mniej wyświetleń', 8, 'uwaga');
        elseif (!wsm_gtin_ok($ean)) $add('EAN z błędną cyfrą kontrolną — Google go odrzuci', 15, 'blad');
        if ((int) $p['weight_g'] <= 0) $add('brak wagi — brak ceny za kg i kosztu wysyłki w Google', 8, 'uwaga');
        if ((int) $p['stock'] <= 0) $add('brak w magazynie — w pliku jako „niedostępny”', 0, 'info');
        if (count($vus[mb_strtolower(trim((string) $p['name']), 'UTF-8')] ?? []) > 1) $add('ta sama nazwa co inny produkt — Google nie odróżni stron', 10, 'uwaga');
        $brak = [];
        foreach ($traduit as $l => $set) if (!isset($set['product.' . $p['id'] . '.name'])) $brak[] = strtoupper($l);
        if ($brak) $add('bez tłumaczenia: ' . implode(', ', $brak) . ' — strona w tym języku pokazuje polski', 3 * count($brak), 'info');
        $out[] = ['id' => (string) $p['id'], 'slug' => (string) $p['slug'], 'name' => (string) $p['name'],
                  'wynik' => max(0, $pts), 'uwagi' => $u];
    }
    usort($out, fn($a, $b) => $a['wynik'] <=> $b['wynik'] ?: strcmp($a['name'], $b['name']));
    return $out;
}
