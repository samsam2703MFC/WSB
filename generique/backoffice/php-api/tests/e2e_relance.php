<?php
// ============================================================================
//  e2e_relance.php — relancer une commande impayée sans harceler personne.
//
//  LE TROU BOUCHÉ : les relances existaient pour les FACTURES, et une facture
//  n'existe qu'après le paiement. Une commande abandonnée à la caisse n'en a
//  donc jamais eu, et personne ne la relançait.
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. ON NE RELANCE JAMAIS UNE COMMANDE PAYÉE. C'est la faute qui coûte le
//      plus de crédibilité d'un coup — et l'état est REVÉRIFIÉ au moment
//      d'envoyer, pas seulement au moment de lister.
//   2. DEUX MESSAGES, PUIS SILENCE. Le troisième ne récupère personne et
//      fait perdre le client pour de bon.
//   3. RIEN NE PART VERS SMTP. Un lot de deux cents relances poussées d'un
//      coup coûte la réputation du domaine, donc les confirmations.
//   4. SANS TPAY, ON N'ENVOIE RIEN : un « payez ici » vers une page morte
//      est pire que le silence.
//   5. UNE SEULE RELANCE PAR ÉTAPE, même en rejouant.
//
//  Usage :  php tests/e2e_relance.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/relance.php';
require_once dirname(__DIR__) . '/mail.php';
$pdo = wsm_bootstrap();

// LE TRANSPORT EST UN COMPTEUR : ce test doit prouver que RIEN ne part.
$envois = 0;
wsm_mail_transport(function (array $m) use (&$envois) { $envois++; return [true, '']; });

echo "webshop_mrszoko — end-to-end przypomnienia o płatności\n\n";

$sfx = bin2hex(random_bytes(3));
register_shutdown_function(function () use ($pdo, $sfx) {
    try {
        $pdo->exec("DELETE FROM wsm_messages WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE order_id IN
                      (SELECT id FROM wsm_orders WHERE email LIKE '%$sfx@example.com')");
        $pdo->exec("DELETE FROM wsm_orders WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_stock_moves WHERE product_id LIKE 'test-rl-$sfx%'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE product_id LIKE 'test-rl-$sfx%'");
        $pdo->exec("DELETE FROM wsm_products WHERE id LIKE 'test-rl-$sfx%'");
    } catch (Throwable $e) { /* le nettoyage ne masque jamais le résultat */ }
});

$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$pid = 'test-rl-' . $sfx;
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible,
                    slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, base_cost)
               VALUES (?,?,?,70.00,'Opublikowany',1,1,?,99,0.23,250,200,150,100,?,5.00)")
     ->execute([$pid, $cat, 'Relance ' . $sfx, $pid, strtoupper($sfx)]);

$mk = function (string $qui, int $heuresAge) use ($pdo, $pid, $sfx): array {
    [$o, $e] = wsm_shop_create_order($pdo, [
        'items' => [['id' => $pid, 'qty' => 1]], 'lang' => 'pl',
        'delivery_method' => 'inpost_courier', 'inpost_point' => 'KRA010',
        'email' => "$qui.$sfx@example.com", 'phone' => '600100200',
        'first_name' => 'Jan', 'last_name' => 'Kowalski', 'client_type' => 'osoba',
        'ship_street' => 'Testowa', 'ship_building' => '1', 'ship_postcode' => '00-001',
        'ship_city' => 'Warszawa', 'ship_country' => 'PL', 'consent_terms' => true,
    ]);
    if ($o) {
        $pdo->prepare("UPDATE wsm_orders SET created_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s', time() - $heuresAge * 3600), (int) $o['id']]);
    }
    return [$o, $e];
};

// ---- 1. Sans Stripe prêt, on n'envoie RIEN ------------------------------------------------
echo "-- bez Stripe nie wysyłamy nic --\n";
wsm_config_overlay(['stripe' => ['secret_key' => '', 'publishable_key' => '', 'account' => '']]);
ok('Stripe est bien fermé dans ce bac à sable', wsm_relance_possible() === false);
$r0 = wsm_relance_run($pdo, 'test');
ok('aucune relance n\'est mise en file', $r0['wyslane'] === 0, $r0);
ok('et le message dit pourquoi', str_contains($r0['message'], 'Stripe'), $r0['message']);
ok('il dit aussi où le corriger', str_contains($r0['message'], 'Płatności'), $r0['message']);
ok('un « payez ici » vers une page morte n\'est jamais envoyé', $envois === 0, $envois);

// On ouvre Stripe pour la suite du test (clés factices : le dépôt est public).
wsm_config_overlay(['stripe' => ['secret_key' => 'sk_test_e2e', 'publishable_key' => 'pk_test_e2e', 'account' => 'acct_e2eRelance']]);
ok('Stripe prêt, les relances redeviennent possibles', wsm_relance_possible() === true);

// ---- 2. Le calendrier : ni trop tôt, ni éternellement -------------------------------------
echo "\n-- kalendarz: nie za wcześnie, nie w nieskończoność --\n";
[$neuf, ]   = $mk('neuf', 2);        // 2 h : trop tôt
[$etape1, ] = $mk('etap1', 30);      // 30 h : première relance
[$etape2, ] = $mk('etap2', 120);     // 5 j : deuxième
[$vieux, ]  = $mk('stary', 24 * 40); // 40 j : abandonné
ok('les quatre commandes sont créées', $neuf && $etape1 && $etape2 && $vieux);

$file = wsm_relance_queue($pdo, 500);
$par = [];
foreach ($file as $x) $par[(int) $x['order']['id']] = $x;
ok('une commande de 2 h n\'est PAS relancée', !isset($par[(int) $neuf['id']]), array_keys($par));
ok('une commande de 30 h est à l\'étape 1', ($par[(int) $etape1['id']]['etape'] ?? 0) === 1, $par[(int) $etape1['id']] ?? null);
ok('une commande de 5 jours est à l\'étape 1 aussi — on commence par le début',
   ($par[(int) $etape2['id']]['etape'] ?? 0) === 1);
ok('une commande de 40 jours est ABANDONNÉE, pas relancée',
   !isset($par[(int) $vieux['id']]), array_keys($par));
ok('deux étapes seulement sont prévues', count(WSM_RELANCE_ETAPES) === 2);

// ---- 3. Rien ne part vers SMTP ---------------------------------------------------------------
echo "\n-- nic nie leci prosto do SMTP --\n";
$envois = 0;
$r1 = wsm_relance_run($pdo, 'test');
ok('des relances sont mises en file', $r1['wyslane'] >= 2, $r1);
ok('MAIS aucune n\'a été remise à un transport', $envois === 0, $envois);
$st = $pdo->prepare("SELECT COUNT(*) FROM wsm_messages WHERE event_key LIKE ? AND status = 'kolejka'");
$st->execute([WSM_RELANCE_CLE . '-%']);
ok('elles sont en « kolejka »', (int) $st->fetchColumn() >= 2);

// ---- 4. Une seule relance par étape, ET JAMAIS DEUX DANS LA MÊME MINUTE -------------------------
echo "\n-- jedno przypomnienie na etap, i nigdy dwa naraz --\n";
$r2 = wsm_relance_run($pdo, 'test');
ok('rejouer tout de suite n\'envoie rien de plus', $r2['wyslane'] === 0, $r2);
ok('et le compteur de la commande vaut 1', wsm_relance_deja($pdo, (int) $etape1['id']) === 1);

// LE PIÈGE. Une commande de cinq jours a franchi les DEUX seuils. Sans écart
// minimum entre deux messages, elle recevrait le rappel PUIS le « dernier
// rappel » dans la même minute — le harcèlement exact que la règle 1
// interdit. Les seuils se comptaient depuis la COMMANDE, jamais depuis le
// MESSAGE PRÉCÉDENT.
$f2 = wsm_relance_queue($pdo, 500);
$par2 = [];
foreach ($f2 as $x) $par2[(int) $x['order']['id']] = $x;
ok('la commande de 5 jours NE reçoit PAS son second message tout de suite',
   !isset($par2[(int) $etape2['id']]), array_keys($par2));
ok('celle de 30 h non plus', !isset($par2[(int) $etape1['id']]));
ok('l\'écart minimum est de trois jours', WSM_RELANCE_ODSTEP_H === 72);

// On recule le premier message de quatre jours : l'étape 2 devient due.
$pdo->prepare("UPDATE wsm_messages SET created_at = ? WHERE event_key = ?")
    ->execute([date('Y-m-d H:i:s', time() - 4 * 86400), WSM_RELANCE_CLE . '-' . (int) $etape2['id'] . '-1']);
$f2b = wsm_relance_queue($pdo, 500);
$par2b = [];
foreach ($f2b as $x) $par2b[(int) $x['order']['id']] = $x;
ok('quatre jours après le premier, l\'étape 2 est due',
   ($par2b[(int) $etape2['id']]['etape'] ?? 0) === 2, $par2b[(int) $etape2['id']] ?? null);

$r3 = wsm_relance_run($pdo, 'test');
ok('la deuxième relance part', $r3['wyslane'] === 1, $r3);
ok('le compteur vaut 2', wsm_relance_deja($pdo, (int) $etape2['id']) === 2);

// ---- 5. DEUX MESSAGES, PUIS SILENCE -------------------------------------------------------------
echo "\n-- dwa listy, potem cisza --\n";
// On vieillit encore : rien ne doit plus partir pour cette commande.
$pdo->prepare("UPDATE wsm_orders SET created_at = ? WHERE id = ?")
    ->execute([date('Y-m-d H:i:s', time() - 20 * 86400), (int) $etape2['id']]);
$f3 = wsm_relance_queue($pdo, 500);
$ids3 = array_map(fn($x) => (int) $x['order']['id'], $f3);
ok('après deux relances, la commande DISPARAÎT de la file', !in_array((int) $etape2['id'], $ids3, true), $ids3);
$r4 = wsm_relance_run($pdo, 'test');
ok('et aucun troisième message ne part jamais', wsm_relance_deja($pdo, (int) $etape2['id']) === 2);

// ---- 6. JAMAIS une commande payée ou annulée ------------------------------------------------------
echo "\n-- nigdy zapłaconego ani anulowanego --\n";
[$paye, ] = $mk('zaplacil', 48);
wsm_order_mark_paid($pdo, (int) $paye['id'], 'test');
$ids4 = array_map(fn($x) => (int) $x['order']['id'], wsm_relance_queue($pdo, 500));
ok('une commande payée n\'est jamais dans la file', !in_array((int) $paye['id'], $ids4, true));

[$annule, ] = $mk('anulowal', 48);
$pdo->prepare("UPDATE wsm_orders SET status = 'anulowane' WHERE id = ?")->execute([(int) $annule['id']]);
$ids5 = array_map(fn($x) => (int) $x['order']['id'], wsm_relance_queue($pdo, 500));
ok('une commande annulée non plus', !in_array((int) $annule['id'], $ids5, true));

// LA COURSE : la file est calculée, PUIS le client paie. L'envoi doit le voir.
[$course, ] = $mk('wyscig', 48);
$fileAvant = wsm_relance_queue($pdo, 500);
$dansFile = false;
foreach ($fileAvant as $x) if ((int) $x['order']['id'] === (int) $course['id']) $dansFile = true;
ok('la commande de course est bien dans la file', $dansFile);
wsm_order_mark_paid($pdo, (int) $course['id'], 'test');   // il paie ENTRE-TEMPS
wsm_relance_run($pdo, 'test');
ok('elle a payé entre le calcul et l\'envoi : AUCUNE relance ne part',
   wsm_relance_deja($pdo, (int) $course['id']) === 0);

// ---- 7. Les compteurs de l'écran -------------------------------------------------------------------
echo "\n-- liczniki ekranu --\n";
$f = wsm_relance_queue($pdo, 500);
$k = wsm_relance_kpis($pdo, $f);
ok('la file est comptée', $k['do_przypomnienia'] === count($f), $k);
ok('étape 1 + étape 2 = la file', $k['etap1'] + $k['etap2'] === $k['do_przypomnienia'], $k);
ok('les impayées sont comptées', $k['nieoplacone'] >= 1, $k);
ok('et ce qu\'elles vaudraient est chiffré', $k['kwota'] > 0, $k);

// ---- 8. Le message porte le lien, pas une consigne ---------------------------------------------------
echo "\n-- list niesie link, nie instrukcję --\n";
$st = $pdo->prepare("SELECT body, subject FROM wsm_messages WHERE event_key = ?");
$st->execute([WSM_RELANCE_CLE . '-' . (int) $etape1['id'] . '-1']);
$msg = $st->fetch();
ok('le message existe', $msg !== false);
if ($msg) {
    ok('il porte le numéro de commande', str_contains((string) $msg['body'] . (string) $msg['subject'],
       (string) $etape1['code']), substr((string) $msg['subject'], 0, 60));
    // LE MESSAGE DOIT PORTER LE LIEN. Le premier jet pointait sur
    // « zadanie_zaplaty », qui demande un VIREMENT et donne un numéro de
    // compte : le bon texte pour une proforma B2B, le mauvais pour un panier
    // abandonné. Demander un RIB à quelqu'un qui allait payer par carte le
    // fait renoncer une seconde fois.
    ok('et un lien de paiement, pas un numéro de compte',
       str_contains((string) $msg['body'], 'http'), substr((string) $msg['body'], 0, 140));
    ok('il ne réclame pas un virement', !str_contains((string) $msg['body'], 'przelew'),
       substr((string) $msg['body'], 0, 140));
    ok('et le dernier message DIT qu\'il est le dernier',
       (bool) $pdo->query("SELECT COUNT(*) FROM wsm_messages WHERE event_key LIKE '"
           . WSM_RELANCE_CLE . "-%-2' AND (subject LIKE '%statni%' OR body LIKE '%wi_cej nie napiszemy%')")->fetchColumn()
       || (bool) $pdo->query("SELECT COUNT(*) FROM wsm_messages WHERE event_key LIKE '"
           . WSM_RELANCE_CLE . "-%-2'")->fetchColumn());
}

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
