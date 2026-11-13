<?php
// ============================================================================
//  index.php — webshop_mrszoko API router (Console marque · franchisor).
//  Serves /franchisor/* endpoints. Reads shape data exactly as the front-end
//  expects; writes require the admin token (X-Admin-Token). Every response is
//  JSON. This is the single wiring point between the UI and the wsm_ tables.
// ============================================================================
declare(strict_types=1);

// require_once et pas require : ces modules se requièrent aussi entre eux
// (shop.php charge vies.php, stripe.php charge shop.php…). Un simple require
// rechargerait le fichier et PHP refuserait de redéclarer ses fonctions.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/delivery.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/commerce.php';
require_once __DIR__ . '/shop.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/inpost.php';
require_once __DIR__ . '/vies.php';

$cfg = wsm_config();

// ---- En-têtes ---------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');                 // réponses authentifiées : jamais en cache
// CORS : émis UNIQUEMENT si une origine précise est configurée. Par défaut le
// front est same-origin (…/backoffice → ./api) et aucun en-tête n'est requis.
// Jamais '*' : les requêtes portent un cookie de session.
$corsOrigin = (string) ($cfg['cors_origin'] ?? '');
if ($corsOrigin !== '' && $corsOrigin !== '*') {
    $sent = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $allowed = array_map('trim', explode(',', $corsOrigin));
    if ($sent !== '' && in_array($sent, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $sent);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function wsm_send($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function wsm_fail(string $msg, int $code = 400): void { wsm_send(['error' => $msg], $code); }
/** Erreurs de validation champ par champ — le formulaire les affiche en place. */
function wsm_fail_fields(array $errors): void { wsm_send(['error' => 'validation', 'fields' => $errors], 422); }

/** Prochain code client libre (CL-0001, CL-0002…). */
function wsm_next_client_code(PDO $pdo): string {
    $max = $pdo->query("SELECT code FROM wsm_clients WHERE code LIKE 'CL-%' ORDER BY code DESC LIMIT 1")->fetchColumn();
    $n = $max ? ((int) substr((string) $max, 3)) + 1 : 1;
    return sprintf('CL-%04d', $n);
}

function wsm_body(): array { $raw = file_get_contents('php://input'); $j = json_decode($raw ?: 'null', true); return is_array($j) ? $j : []; }

// ---- Routes : /franchisor/* (protégé), /landing/* (public), /auth/* --------
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$route = '';
$lroute = '';
$aroute = '';
$sroute = '';
if (preg_match('#/franchisor/(.*)$#', $path, $m)) $route = rtrim($m[1], '/');
elseif (preg_match('#/landing/(.*)$#', $path, $m)) $lroute = rtrim($m[1], '/');
elseif (preg_match('#/auth/(.*)$#', $path, $m)) $aroute = rtrim($m[1], '/');
elseif (preg_match('#/shop/(.*)$#', $path, $m)) $sroute = rtrim($m[1], '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $pdo = wsm_bootstrap();  // ensure schema + seed on first hit
} catch (Throwable $e) {
    wsm_fail('db_unavailable: ' . $e->getMessage(), 500);
}

// ============================ AUTHENTIFICATION ==============================
// POST /auth/login {email, password} · POST /auth/logout · GET /auth/me
if ($aroute !== '') {
    if ($aroute === 'login' && $method === 'POST') {
        $b = wsm_body();
        $email = (string) ($b['email'] ?? '');
        $pass  = (string) ($b['password'] ?? '');
        if ($email === '' || $pass === '') wsm_fail('email_and_password_required', 422);
        wsm_send(['ok' => true, 'user' => wsm_login($pdo, $email, $pass)]);
    }
    if ($aroute === 'logout' && $method === 'POST') {
        wsm_logout();
        wsm_send(['ok' => true]);
    }
    if ($aroute === 'me' && $method === 'GET') {
        if (wsm_service_token_ok()) {
            wsm_send(['user' => wsm_public_user(['nom' => 'Konsola marki', 'role' => WSM_ROLE_ADMIN, 'service' => true])]);
        }
        $u = wsm_current_user($pdo);
        if (!$u) wsm_fail('unauthenticated', 401);
        wsm_send(['user' => wsm_public_user($u)]);
    }
    wsm_fail('unknown_route: auth/' . $aroute, 404);
}

// ======================= LANDING (public, read-only) ========================
// GET /landing/content?lang=pl|uk|en — everything the landing page renders:
// UI strings for the language + the product cards (texts resolved server-side
// from wsm_landing_i18n). Public: the landing is the public site.
if ($method === 'GET' && $lroute === 'content') {
    wsm_ensure_landing($pdo);
    $langs = array_map(fn($r) => $r['lang'],
        $pdo->query("SELECT DISTINCT lang FROM wsm_landing_i18n ORDER BY lang")->fetchAll());
    if (!$langs) wsm_fail('landing_content_empty', 503);
    $default = in_array('pl', $langs, true) ? 'pl' : $langs[0];
    $lang = $_GET['lang'] ?? $default;
    if (!in_array($lang, $langs, true)) $lang = $default;

    // Comme pour la boutique : une valeur vide vaut « pas traduit » et retombe
    // sur la langue par défaut. Sans ce repli, vider un champ dans la console
    // effacerait le texte sur la page publique au lieu de revenir au polonais.
    $st = $pdo->prepare("SELECT k, v FROM wsm_landing_i18n WHERE lang=?");
    $st->execute([$lang]);
    $strings = [];
    foreach ($st->fetchAll() as $r) {
        if (trim((string) $r['v']) === '') continue;
        $strings[$r['k']] = $r['v'];
    }
    if ($lang !== $default) {
        $st = $pdo->prepare("SELECT k, v FROM wsm_landing_i18n WHERE lang=?");
        $st->execute([$default]);
        foreach ($st->fetchAll() as $r) {
            if (!isset($strings[$r['k']])) $strings[$r['k']] = $r['v'];
        }
    }

    $products = array_map(fn($r) => [
        'id' => $r['id'], 'fluidity' => (int) $r['fluidity'],
        'swatch_from' => $r['swatch_from'], 'swatch_to' => $r['swatch_to'],
        'price_from' => ['pln' => $r['price_from_pln'] !== null ? (float) $r['price_from_pln'] : null,
                         'eur' => $r['price_from_eur'] !== null ? (float) $r['price_from_eur'] : null],
        'price_perkg' => ['pln' => $r['price_perkg_pln'] !== null ? (float) $r['price_perkg_pln'] : null,
                          'eur' => $r['price_perkg_eur'] !== null ? (float) $r['price_perkg_eur'] : null],
        'name' => $strings['product.' . $r['id'] . '.name'] ?? $r['id'],
        'meta' => $strings['product.' . $r['id'] . '.meta'] ?? '',
        'specs' => $strings['product.' . $r['id'] . '.specs'] ?? '',
    ], $pdo->query("SELECT * FROM wsm_landing_products WHERE active=1 ORDER BY sort_order")->fetchAll());

    wsm_send(['lang' => $lang, 'default' => $default, 'langs' => $langs,
        'strings' => $strings, 'products' => $products]);
}

// ======================= BOUTIQUE EN LIGNE (public) =========================
// La boutique est le site public : catalogue, devis, commande et suivi sont
// ouverts. Ce qui est verrouillé, c'est ce qui coûte de l'argent — les prix
// sont recalculés en base à chaque étape et la notification de paiement est
// signée. Voir shop.php et stripe.php.
if ($sroute !== '') {
    $lang = wsm_shop_lang($pdo, $_GET['lang'] ?? ($_SERVER['HTTP_X_LANG'] ?? null));

    if ($method === 'GET' && $sroute === 'catalog') {
        wsm_send([
            'lang' => $lang, 'langs' => wsm_shop_available_langs($pdo), 'currency' => 'PLN',
            'strings'  => wsm_shop_strings($pdo, $lang),
            'products' => wsm_shop_products($pdo, $lang),
            'shipping' => wsm_shipping_methods($pdo, $lang),
            'countries' => wsm_shop_countries($pdo, $lang),
        ]);
    }

    if ($method === 'GET' && preg_match('#^product/(.+)$#', $sroute, $mm)) {
        $p = wsm_shop_product($pdo, urldecode($mm[1]), $lang);
        if (!$p) wsm_fail('product_not_found', 404);
        wsm_send(['lang' => $lang, 'product' => $p]);
    }

    // Devis : le seul chiffrage qui fasse foi. Le panier envoie des id et des
    // quantités, rien d'autre — un « price » dans le corps est ignoré.
    if ($method === 'POST' && $sroute === 'quote') {
        $b = wsm_body();
        [$q, $e] = wsm_shop_quote($pdo, (array) ($b['items'] ?? []),
            (string) ($b['delivery_method'] ?? ''), $lang,
            // L'adresse compte : sans elle, un client professionnel verrait le
            // prix public à l'écran et un autre montant sur sa facture. Un prix
            // qui change entre le devis et la caisse fait abandonner le panier.
            ['country' => (string) ($b['country'] ?? ''), 'vat_eu' => (string) ($b['vat_eu'] ?? ''),
             'email' => (string) ($b['email'] ?? ''),
             // Le code de réduction est jugé ICI, pas à la caisse : l'acheteur
             // doit voir ce qu'il enlève AVANT de saisir son adresse. Un code
             // refusé revient en `voucher_error`, en polonais, avec le geste
             // à faire — et ne fait pas échouer le devis.
             'voucher' => (string) ($b['voucher'] ?? '')]);
        if ($e) wsm_send(['error' => 'validation', 'fields' => $e, 'quote' => $q], 422);
        wsm_send($q);
    }

    if ($method === 'POST' && $sroute === 'order') {
        $b = wsm_body();
        $b['lang'] = $b['lang'] ?? $lang;

        // Garde-fou anti-boucle : au-delà de 5 commandes par heure pour la même
        // adresse, on arrête. Une vraie personne n'en passe pas six d'affilée.
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        if ($email !== '') {
            $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_orders
                                  WHERE email = ? AND created_at >= ?");
            $st->execute([$email, date('Y-m-d H:i:s', time() - 3600)]);
            if ((int) $st->fetchColumn() >= 5) wsm_fail('too_many_orders', 429);
        }

        [$order, $errors] = wsm_shop_create_order($pdo, $b);
        if ($errors) wsm_send(['error' => 'validation', 'fields' => $errors], 422);

        // Le paiement se fait dans la page « Zapłać » de la boutique, où le
        // formulaire Stripe est intégré : on rend son adresse. La session
        // Stripe s'ouvre là-bas, à l'affichage — pas ici, où personne ne
        // paierait dans la minute.
        $base = wsm_shop_base_url();
        $q = '?t=' . $order['access_token'];
        wsm_send([
            'ok' => true, 'code' => $order['code'], 'token' => $order['access_token'],
            'total_gross' => $order['total_gross'],
            'url' => $base . '/zamowienie/' . rawurlencode($order['code']) . $q,
            'payment' => ['provider' => 'stripe', 'ready' => wsm_stripe_ready(),
                          'pay_url' => $base . '/zaplac/' . rawurlencode($order['code']) . $q],
        ], 201);
    }

    if ($method === 'GET' && preg_match('#^order/(.+)$#', $sroute, $mm)) {
        $o = wsm_order_by_code($pdo, urldecode($mm[1]), (string) ($_GET['t'] ?? ''));
        if (!$o) wsm_fail('order_not_found', 404);
        unset($o['access_token']);
        wsm_send($o);
    }

    // Webhook Stripe (événements des comptes connectés). Le corps BRUT est
    // signé : il se lit tel quel, avant tout décodage. Stripe réémet tant
    // qu'il ne reçoit pas 2xx.
    if ($method === 'POST' && $sroute === 'stripe/webhook') {
        [$bodyTxt, $code] = wsm_stripe_webhook($pdo, file_get_contents('php://input') ?: '',
            (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $bodyTxt;
        exit;
    }

    // Désinscription « un clic » (RFC 8058) : l'adresse de l'en-tête
    // List-Unsubscribe. La messagerie POSTe sans formulaire ni cookie — le
    // jeton signé suffit. Un GET (quelqu'un a collé l'adresse) mène à la page
    // de la boutique, qui demande confirmation : les antivirus de messagerie
    // ouvrent les liens tout seuls, et ne doivent pas désinscrire à la place
    // du client.
    if ($sroute === 'newsletter/wypisz') {
        require_once __DIR__ . '/marketing.php';
        $e = (string) ($_GET['e'] ?? ''); $t = (string) ($_GET['t'] ?? ''); $r = (string) ($_GET['r'] ?? '');
        if ($method === 'POST') {
            $ok = wsm_mkt_token_email_ok($e, $t) && wsm_mkt_unsub_email($pdo, $e, preg_replace('/[^a-z0-9:]/', '', $r) ?? '');
            wsm_send(['ok' => $ok], $ok ? 200 : 400);
        }
        // Le jeton REÇU, tel quel : en recalculer un ici ouvrirait la porte
        // à quiconque connaît une adresse.
        header('Location: ' . wsm_shop_base_url() . '/newsletter/wypisz?e=' . rawurlencode($e) . '&t=' . rawurlencode($t)
               . ($r !== '' ? '&r=' . rawurlencode($r) : ''), true, 302);
        exit;
    }

    // Accusés de l'opérateur (SMSAPI, notify_url) : GET ou POST selon le
    // panneau ; la clé de l'adresse fait foi. SMSAPI attend « OK ».
    if ($sroute === 'sms/raport') {
        require_once __DIR__ . '/smsapi.php';
        $n = wsm_sms_raport($pdo, $_POST + $_GET, (string) ($_GET['k'] ?? ''));
        http_response_code($n < 0 ? 403 : 200);
        header('Content-Type: text/plain; charset=utf-8');
        echo $n < 0 ? 'NO' : 'OK';
        exit;
    }

    // Webhook Fakturownia (`invoice:update`) : il DÉSIGNE un reçu, que
    // fiskal.php relit chez Fakturownia avec notre propre jeton — le corps
    // n'est jamais cru. 200 aussi pour ce qui ne nous concerne pas : 25
    // échecs de suite et Fakturownia coupe le webhook.
    if ($method === 'POST' && $sroute === 'fakturownia/webhook') {
        require_once __DIR__ . '/fiskal.php';
        [$code, $res] = wsm_fiskal_webhook($pdo, file_get_contents('php://input') ?: '');
        wsm_send($res, $code);
    }

    wsm_fail('unknown_route: shop/' . $sroute, 404);
}

// ============================ READ ENDPOINTS ================================
// Toute lecture /franchisor/* exige une identité : session utilisateur active
// ou jeton de service. Seul /landing/content (traité plus haut) est public.
if ($method === 'GET') {
    wsm_require_read($pdo);
    switch ($route) {
        case 'kpis':
            // CES TUILES SONT LA PREMIÈRE CHOSE QU'ON VOIT EN SE CONNECTANT.
            //
            // Elles lisaient wsm_kpis, une table de chaînes écrites à la main
            // par la maquette : « Obrót sieci 428 k€ », « Aktywne sklepy
            // 14 / 15 », « Adopcja whitelisty 82 % ». En euros, pour une
            // boutique qui facture en złoty ; pour un réseau de quinze points
            // de vente qui n'existe pas ; avec des flèches de progression
            // inventées. Le vrai chiffre d'affaires est cent fois plus petit.
            //
            // C'est le même défaut que les boutiques belges, en pire : sur un
            // tableau de bord, un nombre plausible ne se met pas en doute, il
            // se retient et sert à décider. On les calcule donc, et depuis la
            // MÊME fonction que notre écran Pulpit — deux tableaux de bord qui
            // se contredisent sont pires qu'un seul qui manque.
            $k = wsm_shop_kpis($pdo);
            $zl = fn(int $g): string => number_format($g / 100, 2, ',', "\u{202F}") . "\u{202F}zł";
            $tuiles = [
                ['Zamówienia', (string) $k['orders'], ''],
                ['Opłacone', (string) $k['orders_paid'], ''],
                ['Czeka na płatność', (string) $k['orders_pending'],
                 $k['orders_pending'] > 0 ? 'do przypomnienia' : ''],
                ['Obrót brutto', $zl((int) $k['revenue_gross']), ''],
                ['Średni koszyk', $zl((int) $k['basket_avg']), ''],
            ];
            // Une seule tuile porte une couleur, et seulement quand elle a une
            // raison : de l'argent encaissé dont le colis n'est jamais parti.
            try {
                $bloque = (int) $pdo->query("SELECT COUNT(*) FROM wsm_orders o
                                        LEFT JOIN wsm_shipments s ON s.order_id = o.id
                                            WHERE o.payment_status = 'oplacone'
                                              AND o.status <> 'anulowane'
                                              AND (s.tracking_number IS NULL OR s.tracking_number = '')")->fetchColumn();
                $tuiles[] = ['Do nadania', (string) $bloque, $bloque > 0 ? 'zapłacone, nie wysłane' : ''];
            } catch (Throwable $e) { /* table de la boutique pas encore née */ }

            wsm_send(array_map(fn(array $t) => [
                'label' => $t[0], 'value' => $t[1], 'valColor' => '',
                'delta' => $t[2], 'deltaColor' => $t[2] !== '' ? 'var(--danger)' : '',
            ], $tuiles));

        case 'shops':
            wsm_send(array_map(fn($r) => [
                'id' => $r['id'], 'nom' => $r['nom'], 'ville' => $r['ville'], 'web' => (bool) $r['web'],
                'contrat' => $r['contrat'], 'act' => (bool) $r['act'], 'caShop' => (int) $r['ca_shop'],
                'caOffice' => (int) $r['ca_office'], 'adoption' => (int) $r['adoption'], 'accent' => $r['accent'],
            ], $pdo->query("SELECT * FROM wsm_shops ORDER BY sort_order")->fetchAll()));

        case 'catalog':
            wsm_send(wsm_catalog($pdo));

        case 'vouchers':
            wsm_send(array_map(fn($r) => ['code' => $r['code'], 'valeur' => $r['valeur'], 'type' => $r['type'], 'validite' => $r['validite']],
                $pdo->query("SELECT * FROM wsm_vouchers ORDER BY id")->fetchAll()));

        case 'pricing-rules':
            wsm_send(array_map(fn($r) => ['nom' => $r['nom'], 'cible' => $r['cible'], 'effet' => $r['effet']],
                $pdo->query("SELECT * FROM wsm_pricing_rules WHERE shop_id IS NULL ORDER BY id")->fetchAll()));

        case 'params':
            wsm_send(array_map(fn($r) => ['cle' => $r['cle'], 'type' => $r['type'], 'val' => $r['val']],
                $pdo->query("SELECT * FROM wsm_params ORDER BY cle")->fetchAll()));

        case 'email-templates':
            wsm_send(array_map(fn($r) => ['cle' => $r['cle'], 'langue' => $r['langue'], 'sujet' => $r['sujet']],
                $pdo->query("SELECT * FROM wsm_email_templates ORDER BY id")->fetchAll()));

        case 'users':
            wsm_send(array_map(fn($r) => ['nom' => $r['nom'], 'email' => $r['email'], 'role' => $r['role'], 'portee' => $r['portee'], 'act' => (bool) $r['act']],
                $pdo->query("SELECT * FROM wsm_users ORDER BY id")->fetchAll()));

        case 'audit':
            wsm_send(array_map(fn($r) => ['ts' => $r['ts'], 'user' => $r['user'], 'verb' => $r['verb'], 'entity' => $r['entity'], 'shop' => $r['shop']],
                $pdo->query("SELECT * FROM wsm_audit ORDER BY id DESC")->fetchAll()));

        case 'catchment':
            wsm_send(array_map(fn($r) => [
                'id' => (int) $r['id'], 'name' => $r['name'], 'postcodes' => $r['postcodes'],
                'exclusive' => (bool) $r['exclusive'], 'active' => (bool) $r['active'],
                'shop_id' => $r['shop_id'], 'shop_name' => wsm_shop_name($pdo, $r['shop_id']),
            ], $pdo->query("SELECT * FROM wsm_catchment ORDER BY id")->fetchAll()));

        case 'menus':
            wsm_send(wsm_menus($pdo));

        // ---- delivery module ----
        case 'deliveries':      wsm_send(wsm_delivery_list($pdo));
        case 'delivery-kpis':   wsm_send(wsm_delivery_kpis($pdo));
        case 'drivers':         wsm_send(wsm_drivers($pdo));
        case 'rounds':          wsm_send(wsm_rounds($pdo));
        case 'delivery-clients':wsm_send(wsm_delivery_clients($pdo));
        case 'incidents':       wsm_send(wsm_incidents($pdo));
        case 'delivery-events':
            $id = (int) ($_GET['delivery_id'] ?? 0);
            wsm_send(wsm_delivery_events($pdo, $id));

        // ---- Boutique : ce que la console doit voir des ventes -------------
        case 'orders':     wsm_send(wsm_orders_list($pdo, (int) ($_GET['limit'] ?? 200)));
        case 'shop-kpis':  wsm_send(wsm_shop_kpis($pdo));
        case 'shop-config':
            // Aucun secret ici : uniquement l'état des intégrations, pour que
            // la console dise « Stripe non configuré » au lieu de rester muette.
            wsm_send([
                'stripe'  => ['enabled' => wsm_stripe_enabled(), 'can_verify' => wsm_stripe_can_verify(),
                              'ready' => wsm_stripe_ready(), 'test' => wsm_stripe_cfg()['test']],
                'inpost'  => ['enabled' => wsm_inpost_enabled(), 'sandbox' => wsm_inpost_cfg()['sandbox'],
                              'geowidget' => wsm_inpost_geowidget_token() !== ''],
                'shop_url' => wsm_shop_base_url(),
            ]);
    }
    // /franchisor/orders/{id} — la commande complète, avec sa charge InPost
    if (preg_match('#^orders/(\d+)$#', $route, $mm)) {
        $o = wsm_order_by_id($pdo, (int) $mm[1]);
        if (!$o) wsm_fail('order_not_found', 404);
        $st = $pdo->prepare("SELECT event, detail, actor, created_at FROM wsm_order_events WHERE order_id = ? ORDER BY id");
        $st->execute([(int) $mm[1]]);
        $o['events'] = $st->fetchAll();
        $o['inpost_payload']  = wsm_inpost_payload($o);
        $o['inpost_blockers'] = wsm_inpost_blockers($o);
        wsm_send($o);
    }
    // /franchisor/deliveries/{id}  and  /franchisor/deliveries/{id}/events
    if (preg_match('#^deliveries/(\d+)(/events)?$#', $route, $mm)) {
        $id = (int) $mm[1];
        if (!empty($mm[2])) wsm_send(wsm_delivery_events($pdo, $id));
        $d = wsm_delivery_get($pdo, $id);
        $d ? wsm_send($d) : wsm_fail('delivery_not_found', 404);
    }
    wsm_fail('unknown_route: ' . $route, 404);
}

// ============================ WRITE ENDPOINTS ===============================
if ($method === 'POST') {
    // Écriture : rôle siège (Centrala) ou jeton de service. L'acteur réel est
    // journalisé dans l'audit — plus de « Konsola marki » générique.
    $actor = wsm_require_write($pdo);
    $actorName = (string) ($actor['nom'] ?: 'Konsola marki');
    $body = wsm_body();

    switch ($route) {
        // ---- photo produit : envoi multipart, ré-encodée par le serveur -----
        // Le fichier reçu est décodé puis réécrit par GD (media.php) : ce qui
        // est stocké est une image que NOUS avons fabriquée.
        case 'product-photo': {
            require_once __DIR__ . '/media.php';
            $id = (string) ($_POST['id'] ?? '');
            $st = $pdo->prepare("SELECT image_url FROM wsm_products WHERE id=?");
            $st->execute([$id]);
            $old = $st->fetch();
            if ($old === false) wsm_fail('product_not_found', 404);

            [$url, $err] = wsm_media_store($_FILES['photo'] ?? []);
            if ($err !== null) wsm_fail_fields(['photo' => $err]);

            $pdo->prepare("UPDATE wsm_products SET image_url=? WHERE id=?")->execute([$url, $id]);
            $prev = (string) $old['image_url'];
            if ($prev !== '' && $prev !== $url) wsm_media_delete($prev);
            wsm_audit($pdo, $actorName, 'Zmiana', 'wsm_products ' . $id . ' zdjęcie', 'Sieć');
            wsm_send(['ok' => true, 'id' => $id, 'image_url' => $url]);
        }

        // ---- VIES : vérifier un numéro sans rien enregistrer -----------------
        // Ce que la console appelle derrière le bouton « Sprawdź ». Renvoie
        // toujours 200 : « indisponible » est une réponse, pas une erreur.
        case 'vies': {
            $r = wsm_vies_check($pdo, (string) ($body['vat_eu'] ?? ''), !empty($body['force']));
            $r['blocks'] = wsm_vies_blocks($r);
            $r['reverse_charge'] = wsm_vies_reverse_charge($r);
            $r['provable'] = wsm_vies_can_prove();
            wsm_send($r);
        }

        // ---- client B2B : payeur + destinataire InPost -----------------
        case 'client': {
            if (!empty($body['delete'])) {
                $pdo->prepare("DELETE FROM wsm_clients WHERE id=?")->execute([(int) $body['delete']]);
                wsm_audit($pdo, $actorName, 'Usunięcie', 'wsm_clients #' . (int) $body['delete'], 'Sieć');
                wsm_send(['ok' => true]);
            }
            $isUpdate = !empty($body['id']);
            [$c, $errors] = wsm_validate_client($body, $isUpdate);
            if ($errors) wsm_fail_fields($errors);

            $base = [
                'raison'   => trim((string) ($body['raison'] ?? '')),
                'seg'      => (string) ($body['seg'] ?? 'horeca'),
                'statut'   => (string) ($body['statut'] ?? 'aktywny'),
                'paiement' => (string) ($body['paiement'] ?? ''),
                'plafond'  => (int) ($body['plafond'] ?? 0),
                'franco'   => (string) ($body['franco'] ?? ''),
                'remise'   => (string) ($body['remise'] ?? ''),
                'fact'     => (string) ($body['fact'] ?? ''),
            ];
            if (!$isUpdate && $base['raison'] === '') wsm_fail_fields(['raison' => 'wymagana']);
            $row = array_merge($base, $c);

            // ---- VIES : le numéro de TVA est-il RÉEL, pas seulement bien formé ?
            // Un numéro que VIES déclare inconnu est refusé ici. Un service
            // indisponible ne bloque rien : on enregistre l'état « unavailable »
            // et l'écran Kontrahenci propose de revérifier.
            if (($c['vat_eu'] ?? '') !== '') {
                $vr = wsm_vies_check($pdo, $c['vat_eu'], !empty($body['vies_force']));
                if (wsm_vies_blocks($vr)) wsm_fail_fields(['vat_eu' => $vr['reason'] ?: 'nieznany w VIES']);
                $row = array_merge($row, wsm_vies_columns($vr));
            } else {
                $row = array_merge($row, wsm_vies_columns(['status' => 'skipped']));
            }

            if ($isUpdate) {
                $set = implode(',', array_map(fn($k) => "$k=?", array_keys($row)));
                $pdo->prepare("UPDATE wsm_clients SET $set WHERE id=?")
                    ->execute([...array_values($row), (int) $body['id']]);
                $id = (int) $body['id'];
            } else {
                $row['code'] = trim((string) ($body['code'] ?? '')) ?: wsm_next_client_code($pdo);
                $cols = implode(',', array_keys($row));
                $ph = rtrim(str_repeat('?,', count($row)), ',');
                $pdo->prepare("INSERT INTO wsm_clients ($cols) VALUES ($ph)")->execute(array_values($row));
                $id = (int) $pdo->lastInsertId();
            }
            wsm_audit($pdo, $actorName, $isUpdate ? 'Zmiana' : 'Utworzenie', 'wsm_clients #' . $id, 'Sieć');
            wsm_send(['ok' => true, 'id' => $id]);
        }

        // ---- point de livraison : Paczkomat ou adresse coursier -------------
        case 'client-point': {
            if (!empty($body['delete'])) {
                $pdo->prepare("DELETE FROM wsm_client_points WHERE id=?")->execute([(int) $body['delete']]);
                wsm_send(['ok' => true]);
            }
            $isUpdate = !empty($body['id']);
            if (!$isUpdate && empty($body['client_id'])) wsm_fail_fields(['client_id' => 'wymagany']);
            [$p, $errors] = wsm_validate_point($body, $isUpdate);
            if ($errors) wsm_fail_fields($errors);

            $row = array_merge([
                'libelle'    => trim((string) ($body['libelle'] ?? '')),
                'fenetre'    => (string) ($body['fenetre'] ?? ''),
                'jours'      => (string) ($body['jours'] ?? ''),
                'validation' => (string) ($body['validation'] ?? 'QR'),
            ], $p);
            if (!$isUpdate && $row['libelle'] === '') wsm_fail_fields(['libelle' => 'wymagana']);

            if ($isUpdate) {
                $set = implode(',', array_map(fn($k) => "$k=?", array_keys($row)));
                $pdo->prepare("UPDATE wsm_client_points SET $set WHERE id=?")
                    ->execute([...array_values($row), (int) $body['id']]);
                $id = (int) $body['id'];
            } else {
                $row['client_id'] = (int) $body['client_id'];
                $cols = implode(',', array_keys($row));
                $ph = rtrim(str_repeat('?,', count($row)), ',');
                $pdo->prepare("INSERT INTO wsm_client_points ($cols) VALUES ($ph)")->execute(array_values($row));
                $id = (int) $pdo->lastInsertId();
            }
            wsm_audit($pdo, $actorName, $isUpdate ? 'Zmiana' : 'Utworzenie', 'wsm_client_points #' . $id, 'Sieć');
            wsm_send(['ok' => true, 'id' => $id]);
        }

        // ---- produit : gouvernance + logistique InPost + TVA -----------
        case 'product': {
            if (empty($body['id'])) wsm_fail_fields(['id' => 'wymagany']);
            $id = (string) $body['id'];
            $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_products WHERE id=?");
            $st->execute([$id]);
            if (!(int) $st->fetchColumn()) wsm_fail('product_not_found', 404);

            $set = []; $vals = [];
            // Champs de gouvernance déjà pilotés par les interrupteurs du catalogue.
            foreach (['active', 'brand_whitelist', 'brand_mandatory'] as $f) {
                if (array_key_exists($f, $body)) { $set[] = "$f=?"; $vals[] = (int) $body[$f]; }
            }
            if (array_key_exists('price', $body) || array_key_exists('prix', $body)) {
                $set[] = 'prix=?'; $vals[] = (float) ($body['price'] ?? $body['prix']);
            }
            if (array_key_exists('menu_override', $body)) {
                $set[] = 'menu_override=?'; $vals[] = $body['menu_override'] !== null ? (string) $body['menu_override'] : null;
            }
            if (array_key_exists('nom', $body)) { $set[] = 'nom=?'; $vals[] = (string) $body['nom']; }

            // Logistique / fiscalité : validées ensemble si l'un des champs est fourni.
            $logisticKeys = ['sku', 'ean', 'vat_rate', 'weight_g', 'length_mm', 'width_mm', 'height_mm', 'parcel_template'];
            if (array_intersect($logisticKeys, array_keys($body))) {
                $cur = $pdo->prepare("SELECT " . implode(',', $logisticKeys) . " FROM wsm_products WHERE id=?");
                $cur->execute([$id]);
                $merged = array_merge($cur->fetch() ?: [], array_intersect_key($body, array_flip($logisticKeys)));
                // Le gabarit n'est repris de la base que s'il est explicitement
                // imposé dans la requête : sinon il doit être recalculé, faute
                // de quoi un changement de dimensions garderait l'ancien casier.
                if (!array_key_exists('parcel_template', $body)) unset($merged['parcel_template']);
                [$log, $errors] = wsm_validate_product_logistics($merged);
                if ($errors) wsm_fail_fields($errors);
                foreach ($log as $k => $v) { $set[] = "$k=?"; $vals[] = $v; }
            }

            // Vitrine : photo, slug, stock, mise en vente, mentions de la carte.
            $shopKeys = ['slug', 'image_url', 'stock', 'shop_visible', 'origin', 'cocoa',
                         'unit_label', 'badge', 'swatch_from', 'swatch_to'];
            if (array_intersect($shopKeys, array_keys($body))) {
                [$shop, $errors] = wsm_validate_product_shop($pdo, array_intersect_key($body, array_flip($shopKeys)), $id);
                if ($errors) wsm_fail_fields($errors);
                foreach ($shop as $k => $v) { $set[] = "$k=?"; $vals[] = $v; }
            }

            if (!$set) wsm_fail('no_fields');
            $vals[] = $id;
            $pdo->prepare("UPDATE wsm_products SET " . implode(',', $set) . " WHERE id=?")->execute($vals);
            wsm_audit($pdo, $actorName, 'Zmiana', 'wsm_products ' . $id, 'Sieć');
            wsm_send(['ok' => true, 'id' => $id]);
        }

        // ---- landing content (Mister Szoko public site) --------------------
        case 'landing-string': {
            // Upsert {lang,k,v} — or delete the key when v is null/absent.
            if (empty($body['lang']) || empty($body['k'])) wsm_fail('lang_and_k_required');
            wsm_ensure_landing($pdo);
            if (!array_key_exists('v', $body) || $body['v'] === null) {
                $pdo->prepare("DELETE FROM wsm_landing_i18n WHERE lang=? AND k=?")->execute([$body['lang'], $body['k']]);
            } else {
                $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_landing_i18n WHERE lang=? AND k=?");
                $st->execute([$body['lang'], $body['k']]);
                if ((int) $st->fetchColumn()) {
                    $pdo->prepare("UPDATE wsm_landing_i18n SET v=? WHERE lang=? AND k=?")->execute([(string) $body['v'], $body['lang'], $body['k']]);
                } else {
                    $pdo->prepare("INSERT INTO wsm_landing_i18n (lang,k,v) VALUES (?,?,?)")->execute([$body['lang'], $body['k'], (string) $body['v']]);
                }
            }
            wsm_audit($pdo, $actorName, 'Zmiana', 'wsm_landing_i18n ' . $body['lang'] . ':' . $body['k'], 'Landing');
            wsm_send(['ok' => true]);
        }

        case 'landing-product': {
            // Upsert one product card — or delete it with {delete: id}.
            wsm_ensure_landing($pdo);
            if (!empty($body['delete'])) {
                $pdo->prepare("DELETE FROM wsm_landing_products WHERE id=?")->execute([(string) $body['delete']]);
                wsm_send(['ok' => true]);
            }
            if (empty($body['id'])) wsm_fail('id_required');
            $row = [
                'sort_order' => (int) ($body['sort_order'] ?? 0),
                'swatch_from' => $body['swatch_from'] ?? '--brand-900',
                'swatch_to' => $body['swatch_to'] ?? '--brand-700',
                'fluidity' => (int) ($body['fluidity'] ?? 3),
                'active' => (int) ($body['active'] ?? 1),
                'price_from_pln' => $body['price_from_pln'] ?? null,
                'price_perkg_pln' => $body['price_perkg_pln'] ?? null,
                'price_from_eur' => $body['price_from_eur'] ?? null,
                'price_perkg_eur' => $body['price_perkg_eur'] ?? null,
            ];
            $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_landing_products WHERE id=?");
            $st->execute([(string) $body['id']]);
            if ((int) $st->fetchColumn()) {
                $set = implode(',', array_map(fn($c) => "$c=?", array_keys($row)));
                $pdo->prepare("UPDATE wsm_landing_products SET $set WHERE id=?")
                    ->execute([...array_values($row), (string) $body['id']]);
            } else {
                $cols = 'id,' . implode(',', array_keys($row));
                $ph = rtrim(str_repeat('?,', count($row) + 1), ',');
                $pdo->prepare("INSERT INTO wsm_landing_products ($cols) VALUES ($ph)")
                    ->execute([(string) $body['id'], ...array_values($row)]);
            }
            wsm_audit($pdo, $actorName, 'Zmiana', 'wsm_landing_products ' . $body['id'], 'Landing');
            wsm_send(['ok' => true]);
        }

        case 'param': {
            if (empty($body['cle'])) wsm_fail('cle_required');
            $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_params WHERE cle=?"); $st->execute([$body['cle']]);
            if ((int) $st->fetchColumn()) {
                $pdo->prepare("UPDATE wsm_params SET val=? WHERE cle=?")->execute([(string) ($body['val'] ?? ''), $body['cle']]);
            } else {
                $pdo->prepare("INSERT INTO wsm_params (cle,type,val) VALUES (?,?,?)")->execute([$body['cle'], $body['type'] ?? 'text', (string) ($body['val'] ?? '')]);
            }
            wsm_audit($pdo, $actorName, 'Zmiana', 'wsm_params ' . $body['cle'], 'Sieć');
            wsm_send(['ok' => true]);
        }

        case 'category': {
            if (empty($body['id'])) wsm_fail('id_required');
            $fields = ['active', 'office_delivery', 'brand_mandatory', 'menu_default', 'brand_whitelist'];
            $set = []; $vals = [];
            foreach ($fields as $f) if (array_key_exists($f, $body)) { $set[] = "$f=?"; $vals[] = (int) $body[$f]; }
            if (!$set) wsm_fail('no_fields');
            $vals[] = (int) $body['id'];
            $pdo->prepare("UPDATE wsm_categories SET " . implode(',', $set) . " WHERE id=?")->execute($vals);
            wsm_send(['ok' => true]);
        }

        case 'catchment': {
            if (!empty($body['delete'])) {
                $pdo->prepare("DELETE FROM wsm_catchment WHERE id=?")->execute([(int) $body['delete']]);
                wsm_send(['ok' => true]);
            }
            $row = ['name' => $body['name'] ?? '', 'postcodes' => $body['postcodes'] ?? '',
                'exclusive' => (int) (!empty($body['exclusive'])), 'active' => (int) ($body['active'] ?? 1),
                'shop_id' => $body['shop_id'] ?? null];
            if (!empty($body['id'])) {
                $pdo->prepare("UPDATE wsm_catchment SET name=?,postcodes=?,exclusive=?,active=?,shop_id=? WHERE id=?")
                    ->execute([$row['name'], $row['postcodes'], $row['exclusive'], $row['active'], $row['shop_id'], (int) $body['id']]);
                wsm_send(['ok' => true, 'id' => (int) $body['id']]);
            }
            $pdo->prepare("INSERT INTO wsm_catchment (name,postcodes,exclusive,active,shop_id) VALUES (?,?,?,?,?)")
                ->execute([$row['name'], $row['postcodes'], $row['exclusive'], $row['active'], $row['shop_id']]);
            wsm_send(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
        }

        // ---- delivery mutations ----
        case 'deliveries': {
            try { wsm_send(wsm_delivery_create($pdo, $body, $actorName), 201); }
            catch (InvalidArgumentException $e) { wsm_fail($e->getMessage(), 422); }
        }
    }

    // /franchisor/deliveries/{id}/{action}
    if (preg_match('#^deliveries/(\d+)/(assign|confirm|status)$#', $route, $mm)) {
        $id = (int) $mm[1]; $action = $mm[2];
        try {
            if ($action === 'assign') {
                wsm_send(wsm_delivery_assign($pdo, $id,
                    isset($body['driver_id']) ? (int) $body['driver_id'] : null,
                    isset($body['round_id']) ? (int) $body['round_id'] : null, $actorName));
            }
            if ($action === 'confirm') {
                wsm_send(wsm_delivery_confirm($pdo, $id, (string) ($body['code'] ?? ''), $actorName));
            }
            if ($action === 'status') {
                wsm_send(wsm_delivery_status($pdo, $id, (string) ($body['status'] ?? ''), $actorName));
            }
        } catch (InvalidArgumentException $e) { wsm_fail($e->getMessage(), 422); }
        catch (RuntimeException $e) { wsm_fail($e->getMessage(), 409); }
    }

    // ---- Boutique : suivi d'une commande depuis la console ----------------
    // /franchisor/orders/{id}/status  ·  /franchisor/orders/{id}/ship
    if (preg_match('#^orders/(\d+)/(status|ship)$#', $route, $mm)) {
        $id = (int) $mm[1];
        $order = wsm_order_by_id($pdo, $id);
        if (!$order) wsm_fail('order_not_found', 404);

        if ($mm[2] === 'status') {
            $new = (string) ($body['status'] ?? '');
            if (!in_array($new, WSM_ORDER_STATUSES, true)) {
                wsm_fail_fields(['status' => implode('|', WSM_ORDER_STATUSES)]);
            }
            // Le point unique : l'état de « wysłane » émet le document.
            $chg = wsm_order_status_set($pdo, (int) $id, $new, (string) ($actor['nom'] ?? ''));
            wsm_order_event($pdo, $id, 'status', $new, $actorName);
            wsm_send(wsm_order_by_id($pdo, $id));
        }

        // Création de l'étiquette InPost. Refusée tant que la commande n'est
        // pas payée : on n'expédie pas ce qui n'est pas encaissé.
        if ($order['payment_status'] !== 'oplacone' && empty($body['force'])) {
            wsm_fail('order_not_paid', 409);
        }
        [$shipment, $err] = wsm_inpost_create($pdo, $order);
        if ($err !== null) wsm_send(['error' => $err, 'blockers' => wsm_inpost_blockers($order)], 409);
        wsm_send(['ok' => true, 'shipment' => $shipment]);
    }

    wsm_fail('unknown_route: ' . $route, 404);
}

wsm_fail('method_not_allowed', 405);

// ============================ SHAPERS =======================================
function wsm_shop_name(PDO $pdo, $shopId): string {
    if (!$shopId) return '';
    static $cache = [];
    if (!isset($cache[$shopId])) {
        $st = $pdo->prepare("SELECT nom FROM wsm_shops WHERE id=?"); $st->execute([$shopId]);
        $cache[$shopId] = $st->fetchColumn() ?: '';
    }
    return $cache[$shopId];
}

function wsm_catalog(PDO $pdo): array {
    $cats = $pdo->query("SELECT * FROM wsm_categories ORDER BY sort_order")->fetchAll();
    $prods = $pdo->query("SELECT * FROM wsm_products ORDER BY category_id, sort_order")->fetchAll();
    $byCat = [];
    foreach ($prods as $p) {
        $byCat[$p['category_id']][] = [
            'id' => $p['id'], 'nom' => $p['nom'], 'prix' => (float) $p['prix'], 'statut' => $p['statut'],
            'bw' => (bool) $p['brand_whitelist'], 'bm' => (bool) $p['brand_mandatory'],
            'ad' => (int) $p['adoption'], 'saison' => $p['saison'],
            // Logistique InPost + TVA (voir commerce.php)
            'sku' => $p['sku'] ?? '', 'ean' => $p['ean'] ?? '',
            'vat_rate' => isset($p['vat_rate']) ? (float) $p['vat_rate'] : 0.23,
            'weight_g' => (int) ($p['weight_g'] ?? 0),
            'length_mm' => (int) ($p['length_mm'] ?? 0),
            'width_mm' => (int) ($p['width_mm'] ?? 0),
            'height_mm' => (int) ($p['height_mm'] ?? 0),
            'parcel_template' => $p['parcel_template'] ?? '',
        ];
    }
    $out = [];
    foreach ($cats as $c) {
        // The dashboard catalogue screen shows the retail categories, not the pure menu group.
        if ($c['name'] === 'Menus & formules') continue;
        $out[] = ['id' => (int) $c['id'], 'cat' => $c['name'], 'prods' => $byCat[$c['id']] ?? []];
    }
    return $out;
}

function wsm_menus(PDO $pdo): array {
    $out = ['_categories' => []];
    foreach ($pdo->query("SELECT name, menu_default FROM wsm_categories")->fetchAll() as $c) {
        $out['_categories'][$c['name']] = ['menu_default' => (int) $c['menu_default']];
    }
    $cats = [];
    foreach ($pdo->query("SELECT id, name FROM wsm_categories")->fetchAll() as $c) $cats[$c['id']] = $c['name'];

    $prods = $pdo->query("SELECT * FROM wsm_products WHERE menu_override IS NOT NULL OR category_id IN
        (SELECT id FROM wsm_categories WHERE name='Menus & formules') ORDER BY sort_order")->fetchAll();
    // Bundle tree, loaded once and indexed.
    $bundles = $pdo->query("SELECT * FROM wsm_bundles ORDER BY product_id, sort_order")->fetchAll();
    $slots = $pdo->query("SELECT * FROM wsm_bundle_slots ORDER BY bundle_id, sort_order")->fetchAll();
    $choices = $pdo->query("SELECT * FROM wsm_bundle_slot_choices ORDER BY slot_id, sort_order")->fetchAll();
    $slotChoices = []; foreach ($choices as $ch) $slotChoices[$ch['slot_id']][] = $ch;
    $bundleSlots = []; foreach ($slots as $s) $bundleSlots[$s['bundle_id']][] = $s;
    $prodBundles = []; foreach ($bundles as $b) $prodBundles[$b['product_id']][] = $b;

    foreach ($prods as $p) {
        $blist = [];
        foreach ($prodBundles[$p['id']] ?? [] as $b) {
            $sl = [];
            foreach ($bundleSlots[$b['id']] ?? [] as $s) {
                $chs = [];
                foreach ($slotChoices[$s['id']] ?? [] as $c) {
                    $chs[] = ['id' => $c['id'], 'label' => $c['label'], 'img' => $c['img'],
                        'delta' => (float) $c['delta'], 'cost' => (float) $c['cost'],
                        'sort_order' => (int) $c['sort_order'], 'active' => (bool) $c['active']];
                }
                $sl[] = ['id' => $s['id'], 'label' => $s['label'], 'required' => (bool) $s['required'],
                    'kind' => $s['kind'], 'min_select' => (int) $s['min_select'], 'max_select' => (int) $s['max_select'],
                    'sort_order' => (int) $s['sort_order'], 'active' => (bool) $s['active'], 'choices' => $chs];
            }
            $blist[] = ['id' => $b['id'], 'name' => $b['name'], 'description' => $b['description'],
                'price_modifier' => (float) $b['price_modifier'], 'sort_order' => (int) $b['sort_order'],
                'active' => (bool) $b['active'], 'slots' => $sl];
        }
        $out[$p['id']] = [
            'productName' => $p['nom'], 'category' => $cats[$p['category_id']] ?? '',
            'menuOverride' => $p['menu_override'], 'basePrice' => (float) $p['prix'],
            'baseCost' => (float) $p['base_cost'], 'bundles' => $blist,
        ];
    }
    return $out;
}
