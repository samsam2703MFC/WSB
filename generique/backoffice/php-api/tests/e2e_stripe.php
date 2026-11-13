<?php
// ============================================================================
//  e2e_stripe.php — tous les paiements par Stripe Connect, intégrés.
//
//  Ce que l'équipe a décidé (octobre 2026) : Stripe remplace tpay ET le
//  virement ; la plateforme prélève sa commission à chaque paiement
//  (application fee) ; le paiement s'affiche DANS la caisse, et le compte de
//  la boutique se gère DANS la console. Ce test éprouve, sans réseau et sans
//  vraie clé (le dépôt est public) :
//
//   1. le diagnostic — chaque panne dite à part, avant le premier client ;
//   2. la création du compte connecté — direct charge, dashboard complet ;
//   3. la session de paiement — au nom du compte connecté, mode intégré,
//      montant exact, commission calculée depuis Platforma, retour signé ;
//   4. le retour client — une session relue CHEZ STRIPE, jamais crue ;
//   5. le décompte Platforma — la commission déjà prélevée n'est pas
//      facturée une seconde fois ;
//   6. les réglages — les clés de la plateforme ne s'écrivent pas depuis
//      Integracje ;
//   7. l'écran, la page « Zapłać », et la disparition de tpay.
//
//  Usage :  php tests/e2e_stripe.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/delivery.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/settings.php';
require_once dirname(__DIR__) . '/stripe.php';
require_once dirname(__DIR__) . '/platform.php';
$pdo = wsm_bootstrap();
$racine = dirname(__DIR__, 4);
$lire = fn(string $rel) => (string) @file_get_contents($racine . '/' . $rel);

echo "webshop_mrszoko — end-to-end Stripe Connect\n\n";

$cfgAvant = wsm_config()['stripe'] ?? [];
$pose = fn(array $c) => wsm_config_overlay(['stripe' => array_replace(
    ['secret_key' => '', 'publishable_key' => '', 'webhook_secret' => '', 'account' => ''], $c)]);
// Le transport enregistre ce qui part, et répond ce qu'on lui dit.
$appels = [];
$repond = function (callable $f) use (&$appels) {
    wsm_stripe_transport(function ($m, $p, $params, $acct, $idem) use (&$appels, $f) {
        $appels[] = ['m' => $m, 'p' => $p, 'params' => $params, 'acct' => $acct, 'idem' => $idem];
        return $f($m, $p, $params, $acct);
    });
};

// ---- 1. Le diagnostic ------------------------------------------------------------------------
echo "-- diagnoza: każda awaria osobno --\n";
$pose([]);
$appels = []; $repond(fn() => [200, []]);
[$k, $m] = wsm_stripe_diag();
ok('sans clés : « zle », et sans appeler Stripe', $k === 'zle' && $appels === [] && str_contains($m, 'klucz tajny'), [$k, $m]);
ok('fermé : ni prêt, ni vérifiable', !wsm_stripe_enabled() && !wsm_stripe_ready() && !wsm_stripe_can_verify());

$pose(['secret_key' => 'sk_test_A', 'publishable_key' => 'pk_live_B']);
[$k, $m] = wsm_stripe_diag();
ok('clés de deux modes différents : la pomyłka la plus courante', $k === 'zle' && str_contains($m, 'różnych trybów'), [$k, $m]);

$pose(['secret_key' => 'sk_test_A', 'publishable_key' => 'pk_test_B']);
$repond(fn() => [401, ['error' => ['message' => 'Invalid API Key']]]);
ok('clé refusée (401) : « zle »', wsm_stripe_diag()[0] === 'zle');
$repond(fn() => [503, null]);
ok('panne passagère : « uwaga », pas « c\'est faux »', wsm_stripe_diag()[0] === 'uwaga');
$repond(fn() => [200, ['object' => 'balance']]);
[$k, $m] = wsm_stripe_diag();
ok('clés bonnes, pas de compte boutique : on dit où le créer', $k === 'uwaga' && str_contains($m, 'Płatności'), [$k, $m]);

$pose(['secret_key' => 'sk_test_A', 'publishable_key' => 'pk_test_B', 'account' => 'acct_1Sklep']);
$repond(fn($m, $p) => $p === '/v1/balance' ? [200, []] : [200, ['charges_enabled' => false, 'details_submitted' => false]]);
[$k, $m] = wsm_stripe_diag();
ok('compte pas fini : « uwaga » — c\'est à la boutique d\'agir', $k === 'uwaga' && str_contains($m, 'dokończ'), [$k, $m]);
$repond(fn($m, $p) => $p === '/v1/balance' ? [200, []] : [200, ['charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true]]);
[$k, $m] = wsm_stripe_diag();
ok('sans secret de webhook : la panne invisible est NOMMÉE', $k === 'uwaga' && str_contains($m, 'webhooka'), [$k, $m]);
$pose(['secret_key' => 'sk_test_A', 'publishable_key' => 'pk_test_B', 'account' => 'acct_1Sklep', 'webhook_secret' => 'whsec_X']);
[$k, $m] = wsm_stripe_diag();
ok('tout est là : « ok », et le mode test est dit', $k === 'ok' && str_contains($m, 'NIE są pobierane'), [$k, $m]);
ok('un identifiant de compte mal formé n\'est pas un compte', (function () use ($pose) {
    $pose(['secret_key' => 'sk_test_A', 'publishable_key' => 'pk_test_B', 'account' => 'acct_1"><script>']);
    return wsm_stripe_account() === '' && !wsm_stripe_ready();
})());

// ---- 2. Le compte connecté ---------------------------------------------------------------------
echo "\n-- konto sklepu: direct charge, pełny dashboard --\n";
$pose(['secret_key' => 'sk_test_A', 'publishable_key' => 'pk_test_B']);
$pdo->exec("DELETE FROM wsm_settings WHERE cle = 'stripe.account'");
$appels = [];
$repond(fn() => [200, ['id' => 'acct_1NowySklep']]);
[$acct, $err] = wsm_stripe_account_create($pdo, 'biuro@example.pl', 'test');
$c = $appels[0] ?? [];
ok('le compte est créé et rangé', $acct === 'acct_1NowySklep' && wsm_stripe_account() === 'acct_1NowySklep', [$acct, $err]);
ok('… rangé en base, pas seulement en mémoire',
   $pdo->query("SELECT val FROM wsm_settings WHERE cle = 'stripe.account'")->fetchColumn() === 'acct_1NowySklep');
ok('… au nom de la plateforme (pas d\'en-tête Stripe-Account)', ($c['p'] ?? '') === '/v1/accounts' && ($c['acct'] ?? 'x') === '');
ok('… la boutique paie ses frais, Stripe porte le risque, dashboard complet',
   ($c['params']['controller']['fees']['payer'] ?? '') === 'account'
   && ($c['params']['controller']['losses']['payments'] ?? '') === 'stripe'
   && ($c['params']['controller']['stripe_dashboard']['type'] ?? '') === 'full', $c['params']['controller'] ?? null);
ok('… Pologne, cartes, BLIK et Przelewy24 demandés',
   ($c['params']['country'] ?? '') === 'PL' && isset($c['params']['capabilities']['blik_payments'], $c['params']['capabilities']['p24_payments']));
ok('… avec une clé d\'idempotence (un double clic ne crée pas deux comptes)', ($c['idem'] ?? '') !== '');
$appels = [];
[$acct2] = wsm_stripe_account_create($pdo, 'biuro@example.pl', 'test');
ok('un second appel ne recrée rien', $acct2 === 'acct_1NowySklep' && $appels === []);
$repond(fn() => [200, ['client_secret' => 'accs_secret_1']]);
$appels = [];
[$cs] = wsm_stripe_account_session();
$c = $appels[0] ?? [];
ok('session de compte : onboarding, paiements, virements, bandeau',
   $cs === 'accs_secret_1' && ($c['params']['account'] ?? '') === 'acct_1NowySklep'
   && isset($c['params']['components']['account_onboarding'], $c['params']['components']['payments'],
            $c['params']['components']['payouts'], $c['params']['components']['notification_banner']), $c['params'] ?? null);
ok('… remboursements et litiges gérables depuis la console',
   ($c['params']['components']['payments']['features']['refund_management'] ?? '') === 'true');

// ---- 3. La session de paiement ------------------------------------------------------------------
echo "\n-- sesja płatności: osadzona, w imieniu konta sklepu, z prowizją --\n";
$pose(['secret_key' => 'sk_test_A', 'publishable_key' => 'pk_test_B', 'account' => 'acct_1NowySklep', 'webhook_secret' => 'whsec_X']);
// Une commande réelle, en base, pour que la ligne de paiement ait un parent.
$sfx = bin2hex(random_bytes(3));
$code = 'MS-E2ESTR-' . strtoupper($sfx);
$pdo->prepare("INSERT INTO wsm_orders (code, access_token, email, items_gross, shipping_gross, total_gross, total_net, lang, status, payment_status)
               VALUES (?,?,?,?,?,?,?,?,?,?)")
    ->execute([$code, str_repeat('a', 32), 'klient@example.pl', 10000, 1390, 11390, 9260, 'pl', 'nowe', 'oczekuje']);
$oid = (int) $pdo->lastInsertId();
$order = ['id' => $oid, 'code' => $code, 'access_token' => str_repeat('a', 32), 'email' => 'klient@example.pl',
          'items_gross' => 10000, 'total_gross' => 11390, 'lang' => 'pl'];
$terms = wsm_platform_terms($pdo);
$baseFee = ($terms['basis'] ?? 'brutto') === 'towar' ? 10000 : 11390;
$fee = (int) round(round($baseFee * (float) $terms['rate']) * (1 + (float) $terms['vat_rate']));
ok('la commission = taux × base de Platforma, TVA de la plateforme comprise', wsm_stripe_fee($pdo, $order) === $fee, [wsm_stripe_fee($pdo, $order), $fee]);
$appels = [];
$repond(fn() => [200, ['id' => 'cs_test_1', 'client_secret' => 'cs_test_1_secret_x']]);
$ret = 'https://sklep.example/zamowienie/' . $code . '?t=x&session_id={CHECKOUT_SESSION_ID}';
$r = wsm_stripe_checkout($pdo, $order, $ret);
$c = $appels[0] ?? [];
ok('le navigateur reçoit le secret de session, rien d\'autre', $r['client_secret'] === 'cs_test_1_secret_x' && $r['status'] === 'oczekuje', $r);
ok('… session ouverte AU NOM du compte de la boutique (direct charge)', ($c['acct'] ?? '') === 'acct_1NowySklep');
ok('… en mode intégré « embedded_page » (version endive)', ($c['params']['ui_mode'] ?? '') === 'embedded_page' && WSM_STRIPE_VERSION === '2026-09-30.endive');
ok('… au montant EXACT de la commande, en PLN, en une ligne',
   ($c['params']['line_items'][0]['price_data']['unit_amount'] ?? 0) === 11390
   && ($c['params']['line_items'][0]['price_data']['currency'] ?? '') === 'pln'
   && count($c['params']['line_items'] ?? []) === 1);
ok('… la commande est reconnaissable chez Stripe', ($c['params']['client_reference_id'] ?? '') === $code
   && ($c['params']['metadata']['order_code'] ?? '') === $code);
ok('… la commission part dans application_fee_amount', ($c['params']['payment_intent_data']['application_fee_amount'] ?? null) === $fee);
ok('… le retour porte {CHECKOUT_SESSION_ID}', str_contains((string) ($c['params']['return_url'] ?? ''), '{CHECKOUT_SESSION_ID}'));
ok('… idempotence posée', ($c['idem'] ?? '') !== '');
$p = $pdo->prepare("SELECT * FROM wsm_payments WHERE order_id = ? AND tr_id = 'cs_test_1'");
$p->execute([$oid]); $pay = $p->fetch();
ok('la tentative est tracée, avec la commission', $pay && $pay['provider'] === 'stripe' && (int) $pay['app_fee'] === $fee
   && (int) $pay['amount'] === 11390, $pay);
$repond(fn() => [400, ['error' => ['message' => 'No such account']]]);
$r = wsm_stripe_checkout($pdo, $order, $ret);
ok('Stripe refuse : pas de secret, la raison est gardée', $r['client_secret'] === '' && str_contains($r['error'], 'No such account'), $r);

// ---- 4. Le retour client ----------------------------------------------------------------------
echo "\n-- powrót klienta: sesja czytana u Stripe, nie z przeglądarki --\n";
$appels = [];
$repond(fn() => [200, []]);
ok('une session qui n\'est pas celle de la commande est ignorée — sans appeler Stripe',
   wsm_stripe_session_sync($pdo, $order, 'cs_test_inconnue') === 'nieznana' && $appels === []);
$repond(fn() => [200, ['id' => 'cs_test_1', 'client_reference_id' => $code, 'status' => 'open']]);
ok('session encore ouverte : rien ne change', wsm_stripe_session_sync($pdo, $order, 'cs_test_1') === 'otwarta');
$repond(fn() => [200, ['id' => 'cs_test_1', 'client_reference_id' => $code, 'status' => 'complete',
                      'payment_status' => 'paid', 'amount_total' => 11390, 'currency' => 'pln', 'payment_intent' => 'pi_1']]);
ok('session payée, relue chez Stripe : commande opłacone', wsm_stripe_session_sync($pdo, $order, 'cs_test_1') === 'oplacone');
$st = $pdo->prepare("SELECT payment_status FROM wsm_orders WHERE id = ?"); $st->execute([$oid]);
ok('… en base', $st->fetchColumn() === 'oplacone');
$st = $pdo->prepare("SELECT status FROM wsm_payments WHERE order_id = ? AND tr_id = 'cs_test_1'"); $st->execute([$oid]);
ok('… et la ligne de paiement aussi', $st->fetchColumn() === 'oplacone');

// Une page « Zapłać » rechargée ouvre une autre session : elle ne doit pas
// passer « opłacone », ni compter une seconde commission.
$code2 = $code . 'B';
$pdo->prepare("INSERT INTO wsm_orders (code, access_token, email, items_gross, shipping_gross, total_gross, total_net, lang, status, payment_status)
               VALUES (?,?,?,?,?,?,?,?,?,?)")
    ->execute([$code2, str_repeat('b', 32), 'klient@example.pl', 10000, 1390, 11390, 9260, 'pl', 'nowe', 'oczekuje']);
$oid2 = (int) $pdo->lastInsertId();
foreach (['cs_test_A', 'cs_test_B'] as $sid) {
    $pdo->prepare("INSERT INTO wsm_payments (order_id, provider, tr_id, tr_title, amount, currency, status, app_fee) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$oid2, 'stripe', $sid, $code2, 11390, 'PLN', 'oczekuje', $fee]);
}
wsm_stripe_apply_session($pdo, ['id' => 'cs_test_B', 'client_reference_id' => $code2, 'payment_status' => 'paid',
    'amount_total' => 11390, 'currency' => 'pln'], 'acct_1NowySklep', 'test');
$st = $pdo->prepare("SELECT tr_id FROM wsm_payments WHERE order_id = ? AND provider = 'stripe' AND status = 'oplacone'"); $st->execute([$oid2]);
$payees = $st->fetchAll(PDO::FETCH_COLUMN);
ok('deux sessions ouvertes, une payée : seule celle-là compte — pas de commission en double', $payees === ['cs_test_B'], $payees);
$pdo->prepare("DELETE FROM wsm_payments WHERE order_id = ?")->execute([$oid2]);
$pdo->prepare("DELETE FROM wsm_order_events WHERE order_id = ?")->execute([$oid2]);
$pdo->prepare("DELETE FROM wsm_orders WHERE id = ?")->execute([$oid2]);

// ---- 5. Platforma : pas de double commission ---------------------------------------------------
echo "\n-- rozliczenie platformy: prowizja pobrana przez Stripe odejmowana --\n";
$ym = date('Y-m');
$v = wsm_platform_volume($pdo, $ym);
ok('le mois voit la commission déjà prélevée', (int) ($v['fees_collected'] ?? 0) >= $fee, $v['fees_collected'] ?? null);
$cpt = wsm_platform_compute($ym, ['gross' => 100000, 'goods_gross' => 90000, 'shipping_gross' => 10000, 'orders' => 3, 'fees_collected' => 5000],
    ['basis' => 'brutto', 'rate' => 0.15, 'rent_net' => 0, 'vat_rate' => 0.23]);
ok('reste à payer = total brut − déjà prélevé', $cpt['to_pay_gross'] === $cpt['total_gross'] - 5000, $cpt);
$cpt = wsm_platform_compute($ym, ['gross' => 0, 'goods_gross' => 0, 'shipping_gross' => 0, 'orders' => 0, 'fees_collected' => 999],
    ['basis' => 'brutto', 'rate' => 0.15, 'rent_net' => 0, 'vat_rate' => 0.23]);
ok('… jamais négatif', $cpt['to_pay_gross'] === 0);
ok('l\'écran Superadmin montre la déduction', str_contains($lire('generique/backoffice/superadmin.php'), 'Pobrane już przez Stripe'));

// ---- 6. Les réglages ---------------------------------------------------------------------------
echo "\n-- klucze platformy: nie z ekranu Integracje --\n";
$pdo->exec("DELETE FROM wsm_settings WHERE cle = 'stripe.secret_key'");
$grupy = array_values(array_diff(array_unique(array_column(wsm_settings_fields(), 0)), ['stripe']));
$r6 = [];
$zm = wsm_settings_save($pdo, ['stripe__secret_key' => 'sk_test_PODRZUCONY', 'mail__from' => ''], 'test', $r6, $grupy);
ok('un formulaire forgé sur Integracje n\'écrit PAS la clé de la plateforme',
   !in_array('stripe.secret_key', $zm, true) && !$pdo->query("SELECT COUNT(*) FROM wsm_settings WHERE cle = 'stripe.secret_key'")->fetchColumn());
$u = $lire('generique/backoffice/ustawienia.php');
ok('Integracje filtre bien ses groupes, « stripe » exclu', str_contains($u, "array_diff(array_unique(array_column(wsm_settings_fields(), 0)), ['stripe'])"));
$pl = $lire('generique/backoffice/platnosci.php');
ok('Płatności n\'écrit les clés que pour le superadmin, et seulement le groupe stripe',
   str_contains($pl, "if (!\$super)") && str_contains($pl, "\$refus, ['stripe']"));
ok('la configuration lit les secrets WSM_STRIPE_* de l\'environnement', str_contains($lire('generique/backoffice/php-api/config.php'), "getenv('WSM_STRIPE_SECRET_KEY')"));

// ---- 7. Écrans, page, routes, et plus de tpay ---------------------------------------------------
echo "\n-- ekrany i sklep --\n";
ok('Płatności est dans le rail, section Pieniądze', preg_match("/'Pieniądze' => \\[\\s*'platnosci\\.php' => 'Płatności'/u", $lire('generique/backoffice/console.php')) === 1);
ok('… avec les composants intégrés de Stripe Connect (ouverture, paiements, virements)', str_contains($pl, 'WSM_STRIPE_CONNECT_JS')
   && str_contains($pl, 'data-skladnik="account-onboarding"') && str_contains($pl, 'data-skladnik="payments"')
   && str_contains($pl, 'data-skladnik="payouts"'));
ok('… et Podgląd n\'y entre pas (remboursements)', wsm_droit_ecran(['role' => 'Podgląd'], 'platnosci.php') === '');
$idx = $lire('generique/shop/index.php');
ok('la caisse mène à « Zapłać » dans la boutique, pas chez un opérateur', str_contains($idx, "redirect(u('zaplac/' . rawurlencode(\$order['code']), \$qZ))"));
ok('« Zapłać » monte le formulaire Stripe (Stripe.js endive)', str_contains($idx, 'data-stripe-checkout') && str_contains($idx, 'WSM_STRIPE_JS')
   && WSM_STRIPE_JS === 'https://js.stripe.com/endive/stripe.js');
ok('… la session se demande en POST, avec le jeton du formulaire', str_contains($idx, "\$page === 'zaplac' && (\$seg[2] ?? '') === 'sesja'"));
ok('… le retour relit la session chez Stripe', str_contains($idx, 'wsm_stripe_session_sync($pdo, $o, (string) $_GET[\'session_id\'])'));
ok('… « Zapłać » n\'est pas indexé', str_contains($lire('generique/shop/seo.php'), "'zaplac'"));
$js = $lire('generique/shop/shop.js');
ok('shop.js monte le Checkout intégré (nouveau nom d\'abord)', str_contains($js, 'createEmbeddedCheckoutPage || stripe.initEmbeddedCheckout')
   && str_contains($js, 'stripeAccount'));
$api = $lire('generique/backoffice/php-api/index.php');
ok('le webhook vit dans l\'API et lit le corps BRUT', str_contains($api, "'stripe/webhook'") && str_contains($api, "HTTP_STRIPE_SIGNATURE"));
ok('l\'adresse du webhook désigne l\'API', str_ends_with(wsm_stripe_webhook_url(), '/shop/stripe/webhook'));
require_once dirname(__DIR__) . '/seed.php';
$mig = wsm_content_jednorazowe()['migracja.2026_10_stripe'] ?? [];
ok('une migration ponctuelle change les textes qui nommaient tpay, phrase par phrase',
   count($mig) >= 10 && !array_filter($mig, fn($op) => !str_contains($op[0], 'REPLACE(v, ?, ?)')));
ok('… le règlement nomme Stripe, et plus Tpay', (bool) array_filter($mig, fn($op) => $op[1][3] === 'terms.s6.b'
   && str_contains($op[1][0], 'Tpay') && str_contains($op[1][1], 'Stripe Payments Europe')));
$przec = wsm_content_jednorazowe()['migracja.2026_10_stripe_przecinek'] ?? [];
ok('… et la politique, même après la virgule qui remplace le tiret à chaque déploiement',
   (bool) array_filter($przec, fn($op) => str_contains($op[1][0], '**operator płatności**, Krajowy Integrator')));
// Joué pour de vrai sur une ligne de test : la graphie « virgule » de la base.
$pdo->prepare("DELETE FROM wsm_shop_i18n WHERE lang = 'pl' AND k = 'e2e.stripe.priv'")->execute();
$pdo->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES ('pl', 'e2e.stripe.priv', ?)")
    ->execute(["x\n- **operator płatności**, Krajowy Integrator Płatności S.A. (Tpay), w zakresie niezbędnym do rozliczenia transakcji,\ny"]);
foreach ($przec as [$sql, $args]) { $args[3] = 'e2e.stripe.priv'; $pdo->prepare($sql)->execute($args); }
$v = (string) $pdo->query("SELECT v FROM wsm_shop_i18n WHERE lang = 'pl' AND k = 'e2e.stripe.priv'")->fetchColumn();
ok('… la ligne en base nomme Stripe, et plus Tpay', str_contains($v, 'Stripe Payments Europe') && stripos($v, 'tpay') === false, $v);
$pdo->prepare("DELETE FROM wsm_shop_i18n WHERE lang = 'pl' AND k = 'e2e.stripe.priv'")->execute();
$seedTxt = $lire('generique/shop/content_seed.json');
ok('le fichier de contenu ne nomme plus tpay', stripos($seedTxt, 'tpay') === false);
ok('tpay.php a quitté le dépôt', !is_file($racine . '/generique/backoffice/php-api/tpay.php'));
$restes = [];
foreach (array_merge(glob($racine . '/generique/backoffice/*.php'), glob($racine . '/generique/backoffice/php-api/*.php'), glob($racine . '/generique/shop/*.php')) as $f) {
    if (preg_match('/wsm_tpay_\w+\(/', (string) file_get_contents($f))) $restes[] = basename($f);
}
ok('aucun code n\'appelle plus tpay', $restes === [], $restes);

// ---- Remise en place -------------------------------------------------------------------------
$pdo->prepare("DELETE FROM wsm_payments WHERE order_id = ?")->execute([$oid]);
$pdo->prepare("DELETE FROM wsm_order_events WHERE order_id = ?")->execute([$oid]);
$pdo->prepare("DELETE FROM wsm_orders WHERE id = ?")->execute([$oid]);
$pdo->exec("DELETE FROM wsm_settings WHERE cle = 'stripe.account'");
wsm_config_overlay(['stripe' => array_replace(['secret_key' => '', 'publishable_key' => '', 'webhook_secret' => '', 'account' => ''], $cfgAvant)]);

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
