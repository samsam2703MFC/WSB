<?php
// ============================================================================
//  stripe.php — encaissement des commandes par Stripe Connect.
//
//  QUI EST QUI. La plateforme (le propriétaire de l'outil) détient le compte
//  Stripe principal et ses clés. La boutique est un COMPTE CONNECTÉ : c'est
//  elle qui vend, qui encaisse et qui apparaît sur le relevé du client
//  (« direct charge »). À chaque paiement, Stripe verse à la plateforme sa
//  commission (application fee) — le taux de Platforma, TVA comprise — et
//  le reste à la boutique. Le loyer, lui, reste au décompte mensuel.
//
//  INTÉGRÉ, PAS REDIRIGÉ. Le formulaire de paiement (BLIK, Przelewy24, carte,
//  Apple Pay, Google Pay — ce que le tableau de bord Stripe active) s'affiche
//  DANS la page « Zapłać » de la boutique (Checkout ui_mode embedded_page) ;
//  l'ouverture du compte, ses paiements et ses virements s'affichent DANS la
//  console (Connect embedded components). Le navigateur ne voit jamais que
//  des secrets de session à usage unique ; les clés restent ici.
//
//  CE QUI FAIT FOI. Comme pour tpay avant : jamais le retour navigateur. Une
//  commande ne passe « opłacone » que sur (1) un webhook dont la signature
//  HMAC est vérifiée, ou (2) une session relue CHEZ STRIPE avec la clé
//  secrète — et dans les deux cas le montant, la devise, la commande et le
//  compte connecté sont recomparés. L'idempotence tient à la base (event_key
//  UNIQUE), pas à un test applicatif qui perdrait une course.
//
//  Sans clés, tout est FERMÉ : aucune session n'est créée, aucun webhook n'est
//  accepté, et l'écran Płatności le dit. Pas de mode « on verra ».
//
//  VERSION ÉPINGLÉE. Le serveur parle la version d'API 2026-09-30.endive et
//  la page charge Stripe.js « endive » : les deux moitiés parlent la même
//  langue quel que soit le réglage par défaut du compte (le mode intégré
//  s'appelle `embedded_page` depuis dahlia ; `embedded` n'existe plus).
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/shop.php';

const WSM_STRIPE_API        = 'https://api.stripe.com';
const WSM_STRIPE_VERSION    = '2026-09-30.endive';
const WSM_STRIPE_JS         = 'https://js.stripe.com/endive/stripe.js';
const WSM_STRIPE_CONNECT_JS = 'https://connect-js.stripe.com/v1.0/connect.js';
/** Un webhook signé il y a plus de 5 minutes est refusé (rejeu). */
const WSM_STRIPE_TOLERANCE  = 300;

function wsm_stripe_cfg(): array {
    $c = wsm_config()['stripe'] ?? [];
    $sk = trim((string) ($c['secret_key'] ?? ''));
    return [
        'secret_key'      => $sk,
        'publishable_key' => trim((string) ($c['publishable_key'] ?? '')),
        'webhook_secret'  => trim((string) ($c['webhook_secret'] ?? '')),
        'account'         => trim((string) ($c['account'] ?? '')),
        // Le mode se lit sur la clé : sk_test_… / sk_live_… (rk_ : clé restreinte).
        'test'            => (bool) preg_match('/^(sk|rk)_test_/', $sk),
    ];
}

/** Les clés de la plateforme sont-elles là ? (sans elles, rien ne s'ouvre) */
function wsm_stripe_enabled(): bool {
    $c = wsm_stripe_cfg();
    return $c['secret_key'] !== '' && $c['publishable_key'] !== '';
}

/** Un webhook peut-il être cru ? (secret de signature présent) */
function wsm_stripe_can_verify(): bool {
    return wsm_stripe_cfg()['webhook_secret'] !== '';
}

/** Le compte connecté de la boutique (acct_…), ou ''. */
function wsm_stripe_account(): string {
    $a = wsm_stripe_cfg()['account'];
    return preg_match('/^acct_[A-Za-z0-9]+$/', $a) ? $a : '';
}

/** La boutique peut-elle encaisser ? Clés + compte connecté. */
function wsm_stripe_ready(): bool {
    return wsm_stripe_enabled() && wsm_stripe_account() !== '';
}

/**
 * Appel HTTP vers l'API Stripe (form-encoded). Renvoie [code, corps décodé].
 * $account : l'en-tête Stripe-Account — l'appel agit AU NOM du compte
 * connecté (direct charge). $idem : clé d'idempotence pour une création.
 */
function wsm_stripe_http(string $method, string $path, array $params = [], string $account = '', string $idem = ''): array {
    $c = wsm_stripe_cfg();
    $url = WSM_STRIPE_API . $path;
    $body = $params ? http_build_query($params, '', '&', PHP_QUERY_RFC1738) : '';
    if ($method === 'GET' && $body !== '') { $url .= '?' . $body; $body = ''; }
    $headers = ['Authorization: Bearer ' . $c['secret_key'], 'Stripe-Version: ' . WSM_STRIPE_VERSION,
                'Content-Type: application/x-www-form-urlencoded'];
    if ($account !== '') $headers[] = 'Stripe-Account: ' . $account;
    if ($idem !== '')    $headers[] = 'Idempotency-Key: ' . $idem;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => $method === 'GET' ? null : $body,
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $raw, true)];
}

/**
 * LE TRANSPORT, REMPLAÇABLE — comme pour tpay, InPost, VIES et le registre MF.
 * Sans lui, aucun test ne pourrait éprouver ce qui compte : une clé de test en
 * production, un compte connecté qui n'a pas fini son ouverture, un webhook
 * qui arrive avant le retour du client.
 */
function wsm_stripe_transport(?callable $set = null): callable {
    static $fn = null;
    if ($set !== null) { $fn = $set; }
    return $fn ?? 'wsm_stripe_http';
}

function wsm_stripe_call(string $method, string $path, array $params = [], string $account = '', string $idem = ''): array {
    return (wsm_stripe_transport())($method, $path, $params, $account, $idem);
}

/** Le message d'erreur de Stripe, en clair, ou le code HTTP. */
function wsm_stripe_err($res, int $code): string {
    $m = is_array($res) ? (string) ($res['error']['message'] ?? '') : '';
    return $m !== '' ? $m : ('HTTP ' . $code);
}

/** L'adresse que Stripe doit appeler (webhook) — à coller dans Workbench. */
function wsm_stripe_webhook_url(): string {
    return wsm_api_base_url() . '/shop/stripe/webhook';
}

// ---------------------------------------------------------------------------
//  Le compte connecté de la boutique
// ---------------------------------------------------------------------------

/** Range l'identifiant du compte connecté (réglage non secret). */
function wsm_stripe_account_store(PDO $pdo, string $acct, string $actor = ''): void {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'stripe.account'")->execute();
    if ($acct !== '') {
        $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES ('stripe.account', ?, 0, ?, ?)")
            ->execute([$acct, $now, $actor]);
    }
    // La surcouche de la requête en cours : la page qui vient de créer le
    // compte s'en sert tout de suite, sans attendre le prochain démarrage.
    wsm_config_overlay(['stripe' => ['account' => $acct]]);
}

/**
 * Crée le compte connecté de la boutique. La boutique paie ses frais Stripe
 * et Stripe porte le risque des paiements (losses) : c'est le schéma du
 * « direct charge » — la boutique est le vendeur aux yeux du client. Elle
 * garde aussi l'accès complet au tableau de bord Stripe.
 *
 * @return array [acct_…|null, erreur|null]
 */
function wsm_stripe_account_create(PDO $pdo, string $email, string $actor = ''): array {
    if (!wsm_stripe_enabled()) return [null, 'Brak kluczy Stripe platformy.'];
    if (wsm_stripe_account() !== '') return [wsm_stripe_account(), null];
    $params = [
        'country' => 'PL',
        'controller' => [
            'fees'             => ['payer' => 'account'],
            'losses'           => ['payments' => 'stripe'],
            'stripe_dashboard' => ['type' => 'full'],
        ],
        'capabilities' => [
            'card_payments' => ['requested' => 'true'],
            'blik_payments' => ['requested' => 'true'],
            'p24_payments'  => ['requested' => 'true'],
        ],
        'metadata' => ['sklep' => wsm_brand_name()],
    ];
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) $params['email'] = $email;
    // Idempotence À LA MINUTE : un double clic ne crée pas deux comptes, mais
    // un nouvel essai après correction n'hérite pas d'une erreur que Stripe
    // garderait en mémoire 24 h sous la même clé.
    [$code, $res] = wsm_stripe_call('POST', '/v1/accounts', $params, '',
        'acct-' . hash('sha256', wsm_brand_name() . '|' . $email . '|' . date('YmdHi')));
    $acct = (string) ($res['id'] ?? '');
    if ($code < 200 || $code >= 300 || !preg_match('/^acct_/', $acct)) {
        return [null, 'Stripe odrzucił utworzenie konta: ' . wsm_stripe_err($res, $code)];
    }
    wsm_stripe_account_store($pdo, $acct, $actor);
    return [$acct, null];
}

/** L'état du compte connecté, tel que Stripe le voit. */
function wsm_stripe_account_state(): array {
    $acct = wsm_stripe_account();
    if (!wsm_stripe_enabled() || $acct === '') return ['ok' => false];
    [$code, $res] = wsm_stripe_call('GET', '/v1/accounts/' . rawurlencode($acct));
    if ($code < 200 || $code >= 300 || !is_array($res)) return ['ok' => false, 'error' => wsm_stripe_err($res, $code)];
    return [
        'ok'                => true,
        'charges_enabled'   => !empty($res['charges_enabled']),
        'payouts_enabled'   => !empty($res['payouts_enabled']),
        'details_submitted' => !empty($res['details_submitted']),
        'due'               => array_values((array) ($res['requirements']['currently_due'] ?? [])),
        'name'              => (string) ($res['business_profile']['name'] ?? ($res['settings']['dashboard']['display_name'] ?? '')),
    ];
}

/**
 * Une session de compte pour les composants intégrés de la console :
 * ouverture du compte, paiements (remboursements et litiges compris),
 * virements, bandeau des demandes de Stripe.
 *
 * @return array [client_secret|null, erreur|null]
 */
function wsm_stripe_account_session(): array {
    $acct = wsm_stripe_account();
    if (!wsm_stripe_enabled() || $acct === '') return [null, 'Konto Stripe sklepu nie jest jeszcze utworzone.'];
    [$code, $res] = wsm_stripe_call('POST', '/v1/account_sessions', [
        'account' => $acct,
        'components' => [
            'account_onboarding'  => ['enabled' => 'true'],
            'notification_banner' => ['enabled' => 'true'],
            'payments' => ['enabled' => 'true', 'features' => [
                'refund_management' => 'true', 'dispute_management' => 'true', 'capture_payments' => 'false',
            ]],
            'payouts'  => ['enabled' => 'true'],
        ],
    ]);
    $cs = (string) ($res['client_secret'] ?? '');
    if ($code < 200 || $code >= 300 || $cs === '') return [null, 'Stripe: ' . wsm_stripe_err($res, $code)];
    return [$cs, null];
}

/**
 * « EST-CE QUE L'ENCAISSEMENT MARCHE ? », répondu AVANT le premier client.
 * Trois pannes distinctes, et chacune se dit à part :
 *   · clés absentes ou refusées : personne ne peut payer ;
 *   · compte connecté absent ou pas fini : personne ne peut payer non plus,
 *     et c'est la boutique qui doit agir (écran Płatności) ;
 *   · secret du webhook absent : le client paie, Stripe confirme, la boutique
 *     refuse la confirmation — la commande reste impayée sur de l'argent
 *     encaissé. La panne la plus chère, parce qu'invisible des deux côtés.
 *
 * @return array{0:string,1:string} [état 'ok'|'uwaga'|'zle', phrase polonaise]
 */
function wsm_stripe_diag(): array {
    $c = wsm_stripe_cfg();
    $brak = [];
    if ($c['secret_key'] === '')      $brak[] = 'klucz tajny (sk_…)';
    if ($c['publishable_key'] === '') $brak[] = 'klucz publiczny (pk_…)';
    if ($brak) return ['zle', 'Brak: ' . implode(' i ', $brak) . '. Bez tego klient nie zapłaci.'];
    $tryb = $c['test'] ? 'testowy' : 'produkcyjny';
    if ($c['test'] !== str_starts_with($c['publishable_key'], 'pk_test_')) {
        return ['zle', 'Klucz tajny i publiczny pochodzą z różnych trybów (test / produkcja) — to najczęstsza pomyłka.'];
    }
    [$code, $res] = wsm_stripe_call('GET', '/v1/balance');
    if ($code === 401 || $code === 403) return ['zle', 'Stripe odrzucił klucz tajny (' . $code . ').'];
    if ($code < 200 || $code >= 300)   return ['uwaga', 'Stripe odpowiedział ' . $code . ' — spróbuj ponownie za chwilę.'];
    if (wsm_stripe_account() === '') {
        return ['uwaga', 'Klucze działają (tryb ' . $tryb . '), ale sklep nie ma jeszcze konta Stripe — załóż je na ekranie Płatności.'];
    }
    $st = wsm_stripe_account_state();
    if (empty($st['ok']))              return ['zle', 'Konto sklepu ' . wsm_stripe_account() . ' nie odpowiada: ' . ($st['error'] ?? '')];
    if (empty($st['charges_enabled'])) return ['uwaga', 'Konto sklepu nie przyjmuje jeszcze płatności — dokończ zakładanie konta na ekranie Płatności.'];
    if (!wsm_stripe_can_verify()) {
        return ['uwaga', 'Płatności działają, ale BRAKUJE sekretu webhooka. Klient zapłaci, a sklep odrzuci potwierdzenie — '
                       . 'zamówienie zostanie jako nieopłacone mimo pobranych pieniędzy.'];
    }
    return ['ok', 'Płatności działają (tryb ' . $tryb . ').' . ($c['test'] ? ' To tryb testowy: prawdziwe pieniądze NIE są pobierane.' : '')];
}

// ---------------------------------------------------------------------------
//  La commission de la plateforme
// ---------------------------------------------------------------------------

/**
 * La commission prélevée par Stripe sur CETTE commande : le taux et la base de
 * Platforma (brut, ou marchandise seule), TVA de la plateforme comprise — le
 * décompte mensuel la reprend ensuite comme « déjà payée ». Sans module
 * Platforma, rien n'est prélevé : on ne prend pas d'argent sur une règle
 * qu'on ne sait pas lire.
 */
function wsm_stripe_fee(PDO $pdo, array $order): int {
    $f = __DIR__ . '/platform.php';
    if (!function_exists('wsm_platform_terms') && is_file($f)) require_once $f;
    if (!function_exists('wsm_platform_terms')) return 0;
    try { $t = wsm_platform_terms($pdo); } catch (Throwable $e) { return 0; }
    $rate = (float) ($t['rate'] ?? 0);
    $vat  = (float) ($t['vat_rate'] ?? 0);
    $base = (($t['basis'] ?? 'brutto') === 'towar') ? (int) $order['items_gross'] : (int) $order['total_gross'];
    if ($rate <= 0 || $base <= 0) return 0;
    $fee = (int) round(round($base * $rate) * (1 + $vat));
    return max(0, min($fee, (int) $order['total_gross']));
}

// ---------------------------------------------------------------------------
//  La session de paiement (intégrée dans la page « Zapłać »)
// ---------------------------------------------------------------------------

/**
 * Ouvre une session Checkout INTÉGRÉE pour une commande, au nom du compte
 * connecté, et enregistre la tentative. Une seule ligne, au montant exact de
 * la commande : les remises (paliers, code) sont déjà dedans, et Stripe ne
 * doit jamais recalculer un total que la facture affirme autrement.
 *
 * @return array ['status', 'client_secret', 'session_id', 'error']
 */
function wsm_stripe_checkout(PDO $pdo, array $order, string $returnUrl): array {
    $total = (int) $order['total_gross'];
    if (!wsm_stripe_ready()) {
        $pdo->prepare("INSERT INTO wsm_payments (order_id, provider, tr_title, amount, currency, status) VALUES (?,?,?,?,?,?)")
            ->execute([$order['id'], 'stripe', $order['code'], $total, 'PLN', 'niedostepne']);
        wsm_order_event($pdo, (int) $order['id'], 'platnosc_niedostepna', 'Stripe nieskonfigurowany', 'system');
        return ['status' => 'niedostepne', 'client_secret' => '', 'session_id' => '', 'error' => ''];
    }
    $fee = wsm_stripe_fee($pdo, $order);
    $n = 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_order_items WHERE order_id = ?");
        $st->execute([(int) $order['id']]);
        $n = (int) $st->fetchColumn();
    } catch (Throwable $e) {}
    $lang = (string) ($order['lang'] ?? 'pl');
    $params = [
        'ui_mode'    => 'embedded_page',
        'mode'       => 'payment',
        'return_url' => $returnUrl,
        'client_reference_id' => (string) $order['code'],
        'locale'     => $lang === 'en' ? 'en' : ($lang === 'pl' ? 'pl' : 'auto'),
        'line_items' => [[
            'quantity'   => 1,
            'price_data' => [
                'currency'     => 'pln',
                'unit_amount'  => $total,
                'product_data' => [
                    'name'        => wsm_brand_name() . ' — ' . $order['code'],
                    'description' => ($n > 0 ? $n . ' poz. · ' : '') . 'z dostawą, VAT wliczony',
                ],
            ],
        ]],
        'metadata' => ['order_code' => (string) $order['code']],
        'payment_intent_data' => [
            'description' => wsm_brand_name() . ' ' . $order['code'],
            'metadata'    => ['order_code' => (string) $order['code']],
        ],
    ];
    if (filter_var((string) ($order['email'] ?? ''), FILTER_VALIDATE_EMAIL)) $params['customer_email'] = (string) $order['email'];
    if ($fee > 0) $params['payment_intent_data']['application_fee_amount'] = $fee;

    [$code, $res] = wsm_stripe_call('POST', '/v1/checkout/sessions', $params, wsm_stripe_account(),
        'cs-' . $order['code'] . '-' . bin2hex(random_bytes(6)));
    $sid = (string) ($res['id'] ?? '');
    $cs  = (string) ($res['client_secret'] ?? '');
    $ok  = $code >= 200 && $code < 300 && $sid !== '' && $cs !== '';
    $pdo->prepare("INSERT INTO wsm_payments (order_id, provider, tr_id, tr_title, amount, currency, status, app_fee)
                   VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$order['id'], 'stripe', $sid, $order['code'], $total, 'PLN', $ok ? 'oczekuje' : 'blad_utworzenia', $fee]);
    wsm_order_event($pdo, (int) $order['id'], $ok ? 'platnosc_utworzona' : 'platnosc_blad',
        'Stripe ' . ($ok ? $sid : wsm_stripe_err($res, $code)), 'system');
    return ['status' => $ok ? 'oczekuje' : 'blad_utworzenia', 'client_secret' => $ok ? $cs : '',
            'session_id' => $sid, 'error' => $ok ? '' : wsm_stripe_err($res, $code)];
}

/**
 * Applique à la commande ce qu'une session Stripe AUTHENTIQUE dit d'elle (lue
 * chez Stripe ou reçue par webhook signé). Recompare tout : la commande, le
 * compte connecté, la devise, le montant. Renvoie un mot d'état.
 */
function wsm_stripe_apply_session(PDO $pdo, array $s, string $account, string $source): string {
    $code = (string) ($s['client_reference_id'] ?? ($s['metadata']['order_code'] ?? ''));
    if ($code === '') return 'brak_zamowienia';
    $st = $pdo->prepare("SELECT * FROM wsm_orders WHERE code = ?");
    $st->execute([$code]);
    $order = $st->fetch();
    if (!$order) return 'brak_zamowienia';
    if ($account !== wsm_stripe_account()) {
        wsm_order_event($pdo, (int) $order['id'], 'platnosc_obce_konto', $account, $source);
        return 'obce_konto';
    }
    $paidAmount = (int) ($s['amount_total'] ?? -1);
    $cur = strtolower((string) ($s['currency'] ?? ''));
    if ($cur !== 'pln' || $paidAmount !== (int) $order['total_gross']) {
        wsm_order_event($pdo, (int) $order['id'], 'platnosc_kwota_niezgodna',
            wsm_money(max(0, $paidAmount)) . ' ' . strtoupper($cur) . ' ≠ ' . wsm_money((int) $order['total_gross']), $source);
        return 'kwota';
    }
    $sid = (string) ($s['id'] ?? '');
    $status = (string) ($s['payment_status'] ?? '');
    if ($status === 'paid' || $status === 'no_payment_required') {
        if ($sid !== '') {
            $pdo->prepare("UPDATE wsm_payments SET status = 'oplacone' WHERE provider = 'stripe' AND tr_id = ?")
                ->execute([$sid]);
        }
        wsm_order_mark_paid($pdo, (int) $order['id'], 'stripe');
        return 'oplacone';
    }
    return 'oczekuje';
}

/**
 * Relit une session CHEZ STRIPE (clé secrète) — pour la page de retour, qui
 * n'attend pas le webhook pour dire « payé ». Le navigateur ne donne qu'un
 * identifiant ; tout le reste vient de Stripe et se recompare.
 */
function wsm_stripe_session_sync(PDO $pdo, array $order, string $sessionId): string {
    if (!wsm_stripe_ready() || !preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) return 'nieznana';
    $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_payments WHERE order_id = ? AND provider = 'stripe' AND tr_id = ?");
    $st->execute([(int) $order['id'], $sessionId]);
    if (!(int) $st->fetchColumn()) return 'nieznana';         // une session qui n'est pas celle de cette commande
    [$code, $res] = wsm_stripe_call('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId), [], wsm_stripe_account());
    if ($code < 200 || $code >= 300 || !is_array($res)) return 'blad';
    if (($res['client_reference_id'] ?? '') !== $order['code']) return 'nieznana';
    if (($res['status'] ?? '') === 'open') return 'otwarta';
    return wsm_stripe_apply_session($pdo, $res, wsm_stripe_account(), 'stripe');
}

// ---------------------------------------------------------------------------
//  Le webhook
// ---------------------------------------------------------------------------

/**
 * L'en-tête Stripe-Signature : « t=1730000000,v1=hex[,v1=hex] ».
 * HMAC-SHA256( secret, t . '.' . corps brut ), comparé avec hash_equals, et
 * pas plus vieux que la tolérance — sinon un webhook capté se rejouerait.
 */
function wsm_stripe_signature_ok(string $raw, string $header, ?int $now = null): bool {
    $secret = wsm_stripe_cfg()['webhook_secret'];
    if ($secret === '' || $header === '') return false;             // fail-closed
    $t = 0; $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't' && ctype_digit($v)) $t = (int) $v;
        if ($k === 'v1' && $v !== '') $sigs[] = strtolower($v);
    }
    if ($t <= 0 || !$sigs) return false;
    if (abs(($now ?? time()) - $t) > WSM_STRIPE_TOLERANCE) return false;
    $expected = hash_hmac('sha256', $t . '.' . $raw, $secret);
    foreach ($sigs as $s) if (hash_equals($expected, $s)) return true;
    return false;
}

/**
 * Traite un webhook Stripe (événements des comptes connectés). Renvoie
 * [corps de réponse, code HTTP]. Stripe réémet tant qu'il ne reçoit pas 2xx.
 */
function wsm_stripe_webhook(PDO $pdo, string $raw, string $sigHeader, ?int $now = null): array {
    if (!wsm_stripe_can_verify()) return ['webhook not configured', 503];
    $ev = json_decode($raw, true);
    $evId = is_array($ev) ? (string) ($ev['id'] ?? '') : '';
    $type = is_array($ev) ? (string) ($ev['type'] ?? '') : '';
    $insert = $pdo->prepare("INSERT INTO wsm_payment_events (provider, event_key, status, amount, signature_ok, raw)
                             VALUES (?,?,?,?,?,?)");
    // La signature AVANT tout. Un webhook non signé est archivé sous une clé
    // qui ne peut entrer en collision avec rien : s'il occupait la clé de
    // l'événement authentique, il suffirait de connaître un evt_… pour
    // empêcher une commande d'être encaissée.
    if (!wsm_stripe_signature_ok($raw, $sigHeader, $now) || $evId === '') {
        $insert->execute(['stripe', 'rej:' . bin2hex(random_bytes(12)), substr($type, 0, 24), 0, 0, mb_substr($raw, 0, 4000)]);
        return ['bad signature', 400];
    }
    $obj = (array) ($ev['data']['object'] ?? []);
    try {
        $insert->execute(['stripe', 'stripe:' . $evId, substr($type, 0, 24), (int) ($obj['amount_total'] ?? 0), 1, mb_substr($raw, 0, 4000)]);
    } catch (Throwable $e) {
        return ['duplicate', 200];                                   // déjà traité : on acquitte sans rejouer
    }
    $account = (string) ($ev['account'] ?? '');
    switch ($type) {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            $r = wsm_stripe_apply_session($pdo, $obj, $account, 'stripe');
            if (in_array($r, ['kwota', 'obce_konto'], true)) return [$r, 409];
            return [$r, 200];
        case 'checkout.session.async_payment_failed':
            $code = (string) ($obj['client_reference_id'] ?? '');
            if ($code !== '' && $account === wsm_stripe_account()) {
                $st = $pdo->prepare("SELECT id FROM wsm_orders WHERE code = ?");
                $st->execute([$code]);
                $oid = (int) $st->fetchColumn();
                if ($oid) {
                    $pdo->prepare("UPDATE wsm_orders SET payment_status = 'nieudane' WHERE id = ? AND payment_status <> 'oplacone'")->execute([$oid]);
                    wsm_order_event($pdo, $oid, 'platnosc_nieudana', 'Stripe', 'stripe');
                }
            }
            return ['failed noted', 200];
        default:
            return ['ignored', 200];
    }
}
