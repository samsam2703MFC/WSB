<?php
// ============================================================================
//  e2e_fiskal.php — le paragon fiscal : Fakturownia + Paragony.pl.
//
//  Une caisse « virtuelle » n'est pas permise à une boutique de chocolat : le
//  paragon fiscal passe par une imprimante online, pilotée par Fakturownia.
//  Ce test éprouve, sans réseau et sans vrai jeton (le dépôt est public) :
//
//   1. le réglage — le jeton ne part que vers *.fakturownia.pl ;
//   2. le diagnostic — chaque panne dite à part, et le nom des imprimantes ;
//   3. canal fermé — rien ne change pour la boutique ;
//   4. l'envoi — le reçu bâti depuis le document figé, au grosz près, avec
//      notre numéro comme `oid` unique, puis l'impression demandée ;
//   5. l'accord du client — e-paragon s'il a coché, papier sinon ;
//   6. le webhook — il désigne, on relit ; le client reçoit son lien une fois ;
//   7. les pannes — une création perdue se RETROUVE, une impression ratée ne
//      se rejoue qu'à la main ;
//   8. le document « à la paye » — le paiement Stripe émet enfin le paragon ;
//   9. la console, la caisse, la page de commande.
//
//  Usage :  php tests/e2e_fiskal.php
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
require_once dirname(__DIR__) . '/mail.php';
require_once dirname(__DIR__) . '/invoice.php';
require_once dirname(__DIR__) . '/fiskal.php';
$pdo = wsm_bootstrap();
$racine = dirname(__DIR__, 4);
$lire = fn(string $rel) => (string) @file_get_contents($racine . '/' . $rel);

echo "webshop_mrszoko — end-to-end kasa fiskalna (Fakturownia)\n\n";

$sfx = substr(bin2hex(random_bytes(4)), 0, 8);
$cfgAvant = wsm_config()['fakturownia'] ?? [];
$ordAvant = wsm_config()['orders'] ?? [];
// La surcouche FUSIONNE : rendre un tableau vide ne remet rien. On réécrit la valeur.
$resetOrd = fn() => wsm_config_overlay(['orders' => ['doc_status' => (string) ($ordAvant['doc_status'] ?? '')]]);
$pose = fn(array $c) => wsm_config_overlay(['fakturownia' => array_replace(
    ['domena' => '', 'api_token' => '', 'drukarka' => '', 'webhook_token' => ''], $c)]);

register_shutdown_function(function () use ($pdo, $sfx, $cfgAvant, $ordAvant) {
    try {
        $ids = "SELECT id FROM wsm_orders WHERE email LIKE '%$sfx@example.com'";
        $pdo->exec("DELETE FROM wsm_invoice_items WHERE invoice_id IN (SELECT id FROM wsm_invoices WHERE order_id IN ($ids))");
        $pdo->exec("DELETE FROM wsm_invoices WHERE order_id IN ($ids)");
        $pdo->exec("DELETE FROM wsm_messages WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_order_events WHERE order_id IN ($ids)");
        $pdo->exec("DELETE FROM wsm_payments WHERE order_id IN ($ids)");
        $pdo->exec("DELETE FROM wsm_shipments WHERE order_id IN ($ids)");
        $pdo->exec("DELETE FROM wsm_order_items WHERE order_id IN ($ids)");
        $pdo->exec("DELETE FROM wsm_orders WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_stock_moves WHERE product_id LIKE 'test-fk-$sfx%'");
        $pdo->exec("DELETE FROM wsm_products WHERE id LIKE 'test-fk-$sfx%'");
    } catch (Throwable $e) { /* le nettoyage ne masque jamais le résultat */ }
    wsm_config_overlay(['fakturownia' => array_replace(['domena' => '', 'api_token' => '', 'drukarka' => '', 'webhook_token' => ''], $cfgAvant),
                        'orders' => ['doc_status' => (string) ($ordAvant['doc_status'] ?? '')]]);
});

// Le transport enregistre ce qui part, et répond ce qu'on lui dit.
$appels = [];
$repond = function (callable $f) use (&$appels) {
    wsm_fiskal_transport(function ($m, $p, $q, $b) use (&$appels, $f) {
        $appels[] = ['m' => $m, 'p' => $p, 'q' => $q, 'b' => $b];
        return $f($m, $p, $q, $b);
    });
};
// Par référence : une fonction fléchée figerait la liste au moment de sa création.
$chemins = function () use (&$appels) { return array_map(fn($a) => $a['m'] . ' ' . $a['p'], $appels); };

// Deux produits, deux taux : 23 % et 5 %.
$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
foreach ([['a', 0.23, '45.90'], ['b', 0.05, '12.35']] as [$s, $rate, $prix]) {
    $pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible,
                        slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, base_cost)
                   VALUES (?,?,?,?,'Opublikowany',1,1,?,50,?,250,200,150,100,?,5.00)")
         ->execute(["test-fk-$sfx-$s", $cat, "Kasa $s $sfx", $prix, "test-fk-$sfx-$s", $rate, strtoupper("FK$s$sfx")]);
}
$commande = function (string $tag, $ePar) use ($pdo, $sfx) {
    $body = [
        'items' => [['id' => "test-fk-$sfx-a", 'qty' => 2], ['id' => "test-fk-$sfx-b", 'qty' => 3]], 'lang' => 'pl',
        'delivery_method' => 'inpost_courier', 'inpost_point' => 'KRA010',
        'email' => "$tag.$sfx@example.com", 'phone' => '600100200',
        'first_name' => 'Anna', 'last_name' => 'Nowak', 'client_type' => 'osoba',
        'ship_street' => 'Testowa', 'ship_building' => '1', 'ship_postcode' => '00-001',
        'ship_city' => 'Warszawa', 'ship_country' => 'PL', 'consent_terms' => true,
    ];
    if ($ePar !== 'absent') $body['e_paragon'] = $ePar;
    [$o, $e] = wsm_shop_create_order($pdo, $body);
    if (!$o) { echo "  ! commande $tag refusée : " . json_encode($e, JSON_UNESCAPED_UNICODE) . "\n"; exit(1); }
    return wsm_order_by_id($pdo, (int) $o['id']);
};
$docDe = fn(array $o) => wsm_invoice_for_order($pdo, (int) $o['id']);
$mails = function (string $email, string $prefixe) use ($pdo) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_messages WHERE email = ? AND event_key LIKE ?");
    $st->execute([$email, $prefixe . '%']);
    return (int) $st->fetchColumn();
};

// ---- 1. Le réglage ---------------------------------------------------------------------------
echo "-- ustawienia: token idzie tylko do Fakturowni --\n";
ok('« szoko » devient le compte szoko.fakturownia.pl', wsm_fiskal_host('szoko') === 'szoko.fakturownia.pl');
ok('l\'adresse complète collée telle quelle est comprise', wsm_fiskal_host('https://Szoko.fakturownia.pl/invoices') === 'szoko.fakturownia.pl');
ok('un autre domaine est refusé (le jeton n\'y partira pas)', wsm_fiskal_host('szoko.example.com') === '' && wsm_fiskal_host('fakturownia.pl.evil.io') === '');
$pose(['domena' => 'xxxx', 'api_token' => 'xxxx']);
ok('« xxxx » vaut non réglé : canal fermé', !wsm_fiskal_enabled());
$f = wsm_settings_fields();
ok('le jeton API et le jeton du webhook sont des secrets',
   ($f['fakturownia.api_token'][4] ?? '') === 'secret' && ($f['fakturownia.webhook_token'][4] ?? '') === 'secret');
ok('les quatre champs vivent dans le groupe « paragon »',
   count(array_filter($f, fn($x) => $x[0] === 'paragon')) === 4);

// ---- 2. Le diagnostic ------------------------------------------------------------------------
echo "\n-- diagnoza --\n";
$pose([]);
$appels = []; $repond(fn() => [200, []]);
[$k, $m] = wsm_fiskal_diag();
ok('sans réglage : « zle », sans appeler Fakturownia', $k === 'zle' && $appels === [] && str_contains($m, 'kod autoryzacyjny'), [$k, $m]);
$pose(['domena' => 'szoko', 'api_token' => 'test-token-fk']);
$repond(fn() => [401, ['code' => 'error', 'message' => 'unauthorized']]);
ok('jeton refusé : « zle »', wsm_fiskal_diag()[0] === 'zle');
$repond(fn() => [200, []]);
[$k, $m] = wsm_fiskal_diag();
ok('aucune imprimante : on dit d\'installer Paragony.pl', $k === 'uwaga' && str_contains($m, 'Paragony.pl'), [$k, $m]);
$appels = [];
$repond(fn() => [200, [['id' => 7, 'name' => 'POSNET-1']]]);
wsm_config_overlay(['orders' => array_replace($ordAvant, ['doc_status' => 'wyslane'])]);
[$k, $m] = wsm_fiskal_diag();
ok('document émis à « Wysłane » : « uwaga » — la prépaye s\'enregistre à la paye, et le papier doit entrer dans le colis',
   $k === 'uwaga' && str_contains($m, 'Opłacone') && str_contains($m, 'POSNET-1'), [$k, $m]);
wsm_config_overlay(['orders' => array_replace($ordAvant, ['doc_status' => 'oplacone'])]);
$appels = [];
[$k, $m] = wsm_fiskal_diag();
$resetOrd();
ok('imprimante vue, document à la paye : « ok » et son nom est donné', $k === 'ok' && str_contains($m, 'POSNET-1'), [$k, $m]);
ok('… en une seule lecture, celle des imprimantes', $chemins() === ['GET /printers.json'], $chemins());
$pose(['domena' => 'szoko', 'api_token' => 'test-token-fk', 'drukarka' => 'NOVITUS']);
[$k, $m] = wsm_fiskal_diag();
ok('imprimante réglée mais absente : « uwaga »', $k === 'uwaga' && str_contains($m, 'NOVITUS'), [$k, $m]);

// ---- 3. Canal fermé --------------------------------------------------------------------------
echo "\n-- kanał zamknięty: sklep działa jak wcześniej --\n";
$pose([]);
$appels = [];
$oF = $commande('ferme', true);
ok('l\'accord est noté même canal fermé', $oF['paragon_forma'] === 'e');
$r = wsm_order_document($pdo, $oF, 'test');
ok('paragon émis, sans un appel à Fakturownia', ($r['doc']['kind'] ?? '') === 'paragon' && $appels === [] && $r['fiskal'] === '');
ok('le client reçoit notre paragon par mail, comme avant', $mails($oF['email'], 'dok:') === 1);
$l = wsm_fiskal_linia($oF, $docDe($oF), 'paragon');
ok('la console dit « kasa niepodłączona », sans rouge', $l['etat'] === 'brak' && str_contains($l['val'], 'nie jest podłączona'));
[$c] = wsm_fiskal_webhook($pdo, '{"id":1}');
ok('webhook canal fermé : 503', $c === 503);

// ---- 4. L'envoi ------------------------------------------------------------------------------
echo "\n-- wysyłka: paragon z dokumentu, co do grosza --\n";
$pose(['domena' => 'szoko', 'api_token' => 'test-token-fk', 'drukarka' => 'POSNET-1', 'webhook_token' => 'tajne-' . $sfx]);
$appels = [];
$repond(function ($m, $p) {
    if ($m === 'POST' && $p === '/invoices.json') return [201, ['id' => 9001, 'number' => 'PA 1/2026']];
    if ($p === '/invoices/fiscal_print') return [200, ['code' => 'success']];
    return [404, null];
});
$oE = $commande('epar', true);
$r = wsm_order_document($pdo, $oE, 'test');
$doc = $docDe($oE);
ok('paragon parti : reçu créé puis impression demandée',
   $chemins() === ['POST /invoices.json', 'GET /invoices/fiscal_print'], $chemins());
$inv = $appels[0]['b']['invoice'] ?? [];
ok('c\'est un reçu (kind receipt), payé', ($inv['kind'] ?? '') === 'receipt' && ($inv['status'] ?? '') === 'paid');
ok('notre numéro part comme oid UNIQUE (pas de second reçu possible)',
   ($inv['oid'] ?? '') === $doc['number'] && ($inv['oid_unique'] ?? '') === 'yes', [$inv['oid'] ?? '', $doc['number']]);
$somme = array_sum(array_map(fn($p) => (int) round((float) $p['total_price_gross'] * 100), $inv['positions'] ?? []));
ok('la somme des positions = le total du document, au grosz', $somme === (int) $doc['total_gross'], [$somme, $doc['total_gross']]);
ok('le payé annoncé = le total', number_format((int) $doc['total_gross'] / 100, 2, '.', '') === ($inv['paid'] ?? ''));
$taux = array_map(fn($p) => $p['tax'], $inv['positions'] ?? []);
ok('les taux sont ceux que l\'imprimante connaît (23 et 5)', in_array(23, $taux, true) && in_array(5, $taux, true), $taux);
ok('la livraison a sa position', (bool) array_filter($inv['positions'] ?? [], fn($p) => str_starts_with($p['name'], 'Dostawa')));
ok('les montants ne sont pas recalculés depuis le HT', ($inv['calculating_strategy']['position'] ?? '') === 'keep_gross');
ok('l\'adresse du client part : c\'est là qu\'arrive l\'e-paragon', ($inv['buyer_email'] ?? '') === $oE['email']);
$q = $appels[1]['q'] ?? [];
ok('impression en mode e-paragon, sur l\'imprimante réglée',
   ($q['mode'] ?? '') === 'e-receipt' && ($q['fiskator_name'] ?? '') === 'POSNET-1' && (string) ($q['id'] ?? '') === '9001', $q);
ok('état « zlecony », reçu 9001, forme « e »',
   $doc['fiskal_status'] === 'zlecony' && $doc['fiskal_id'] === '9001' && $doc['fiskal_forma'] === 'e', [$doc['fiskal_status'], $doc['fiskal_id'], $doc['fiskal_forma']]);
ok('notre paragon interne n\'est PAS envoyé au client (un seul paragon dans sa boîte)', $mails($oE['email'], 'dok:') === 0);
ok('le journal de la commande garde l\'envoi', (int) $pdo->query("SELECT COUNT(*) FROM wsm_order_events WHERE order_id = " . (int) $oE['id'] . " AND event = 'fiskal'")->fetchColumn() === 1);
$appels = [];
wsm_order_document($pdo, $oE, 'test');
ok('rappeler le point unique ne renvoie RIEN (idempotent)', $appels === [], $chemins());

// ---- 5. Le papier ----------------------------------------------------------------------------
echo "\n-- zgoda klienta: e-paragon albo papier --\n";
$appels = [];
$oP = $commande('papier', false);
ok('case décochée : « papier »', $oP['paragon_forma'] === 'papier');
wsm_order_document($pdo, $oP, 'test');
ok('impression papier, sans adresse dans le reçu',
   ($appels[1]['q']['mode'] ?? '') === 'print' && !isset($appels[0]['b']['invoice']['buyer_email']), $appels[1]['q'] ?? null);
$l = wsm_fiskal_linia($oP, $docDe($oP), 'paragon');
ok('la console dit de glisser le papier dans le colis', str_contains($l['val'], 'włóż go do paczki'), $l);
$oA = $commande('absent', 'absent');
ok('case jamais montrée (API, commande ancienne) : rien d\'écrit…', $oA['paragon_forma'] === '');
ok('… donc papier : pas d\'e-paragon sans accord', wsm_fiskal_forma($oA) === 'papier');
$pf = wsm_order_preflight($pdo, $oA);
$kasa = array_values(array_filter($pf['lignes'], fn($x) => $x['co'] === 'Kasa'));
ok('le tiroir annonce le papier AVANT l\'expédition', $kasa && str_contains($kasa[0]['val'], 'PAPIEROWY'), $kasa);
$et = wsm_order_etapy($pdo, $oA);
ok('la question de l\'étape nomme la caisse et le papier',
   (bool) array_filter($et, fn($e) => str_contains($e['pyt'], 'kasę fiskalną') && str_contains($e['pyt'], 'PAPIEROWY')));

// ---- 6. Le webhook ---------------------------------------------------------------------------
echo "\n-- webhook: wskazuje, my sprawdzamy --\n";
$appels = [];
$lien = 'https://szoko.paragony.pl/iB000002Ef4cSVbaNyYupWFpwe33DDW';
$repond(fn($m, $p) => $p === '/invoices/9001.json' ? [200, ['id' => 9001, 'e_receipt_view_url' => $lien]] : [404, null]);
[$c] = wsm_fiskal_webhook($pdo, 'pas du json');
ok('corps illisible : 400', $c === 400);
[$c] = wsm_fiskal_webhook($pdo, json_encode(['id' => 9001, 'api_token' => 'faux']));
ok('mauvais jeton : 403, et rien relu', $c === 403 && $appels === []);
[$c, $b] = wsm_fiskal_webhook($pdo, json_encode(['id' => 123456, 'api_token' => 'tajne-' . $sfx]));
ok('reçu qui n\'est pas à nous : 200 « ignored » (sinon Fakturownia coupe le webhook)', $c === 200 && isset($b['ignored']) && $appels === []);
// Le corps prétend un lien : il n'est PAS cru — on relit chez Fakturownia.
[$c, $b] = wsm_fiskal_webhook($pdo, json_encode(['id' => 9001, 'api_token' => 'tajne-' . $sfx,
                                                 'deal' => ['e_receipt_view_url' => 'https://evil.example/x']]));
$doc = $docDe($oE);
ok('notre reçu : relu chez Fakturownia avec NOTRE jeton', $c === 200 && $chemins() === ['GET /invoices/9001.json'], $chemins());
ok('le lien rangé est celui de Fakturownia, pas celui du corps', $doc['fiskal_url'] === $lien && $doc['fiskal_status'] === 'gotowy');
ok('le client reçoit son e-paragon par mail', $mails($oE['email'], 'epar:') === 1);
wsm_fiskal_webhook($pdo, json_encode(['id' => 9001, 'api_token' => 'tajne-' . $sfx]));
ok('… une seule fois, même si le webhook revient', $mails($oE['email'], 'epar:') === 1);
$cl = wsm_fiskal_dla_klienta($pdo, $oE);
ok('la page de commande montre le lien', $cl['stan'] === 'gotowy' && $cl['url'] === $lien);
ok('… et « papier » pour la commande papier', wsm_fiskal_dla_klienta($pdo, $oP)['stan'] === 'papier');

// ---- 7. Les pannes ---------------------------------------------------------------------------
echo "\n-- awarie: zgubiona odpowiedź, odrzucony druk --\n";
// a) Fakturownia muette : rien n'est créé de notre côté, la commande vit.
$appels = [];
$repond(fn() => [0, null]);
$oN = $commande('muet', true);
$r = wsm_order_document($pdo, $oN, 'test');
$doc = $docDe($oN);
ok('silence du réseau : « blad », sans reçu, et le paragon existe quand même',
   $doc && $doc['fiskal_status'] === 'blad' && $doc['fiskal_id'] === '' && str_contains($doc['fiskal_note'], 'brak odpowiedzi'));
// b) Le reçu existait déjà (réponse perdue la fois d'avant) : on le RETROUVE.
$appels = [];
$repond(function ($m, $p, $q) use ($doc) {
    if ($m === 'POST') return [422, ['code' => 'error', 'message' => ['oid' => ['już istnieje']]]];
    if ($p === '/invoices.json') return [200, [['id' => 5, 'oid' => 'INNY'], ['id' => 9002, 'oid' => $doc['number']]]];
    if ($p === '/invoices/fiscal_print') return [200, ['code' => 'success']];
    return [404, null];
});
$run = wsm_fiskal_run($pdo);
$doc = $docDe($oN);
ok('la relance retrouve le reçu par son oid au lieu d\'en créer un second',
   $doc['fiskal_id'] === '9002' && $doc['fiskal_status'] === 'zlecony' && $run['ponowione'] >= 1, [$doc['fiskal_id'], $doc['fiskal_status'], $chemins()]);
ok('… une seule création tentée, puis la recherche, puis l\'impression',
   $chemins() === ['POST /invoices.json', 'GET /invoices.json', 'GET /invoices/fiscal_print'], $chemins());
$qs = $appels[1]['q'] ?? [];
ok('… la recherche lit les reçus du JOUR d\'émission, page par page (pas les 100 derniers)',
   ($qs['period'] ?? '') === 'more' && ($qs['date_from'] ?? '') === $doc['issued_at'] && ($qs['page'] ?? 0) === 1, $qs);
// c) Impression refusée : le reçu existe, on NE réimprime PAS tout seul.
$appels = [];
$repond(function ($m, $p) {
    if ($m === 'POST') return [201, ['id' => 9003]];
    if ($p === '/invoices/fiscal_print') return [422, ['code' => 'error', 'message' => 'drukarka offline']];
    return [404, null];
});
$oD = $commande('druk', true);
wsm_order_document($pdo, $oD, 'test');
$doc = $docDe($oD);
ok('impression refusée : « blad », reçu gardé, raison dite',
   $doc['fiskal_status'] === 'blad' && $doc['fiskal_id'] === '9003' && str_contains($doc['fiskal_note'], 'drukarka offline'), $doc['fiskal_note']);
$appels = [];
wsm_fiskal_run($pdo);
wsm_order_document($pdo, $oD, 'test');
ok('ni la relance automatique ni le point unique ne réimpriment', !in_array('GET /invoices/fiscal_print', $chemins(), true), $chemins());
$l = wsm_fiskal_linia($oD, $doc, 'paragon');
ok('la console propose « Ponów druk » et dit de vérifier l\'imprimante',
   ($l['agir'] ?? '') === 'fiskal' && $l['agirTxt'] === 'Ponów druk' && str_contains($l['val'], 'na drukarce'), $l);
// c bis) Silence sur l'impression : on ne sait pas si elle est en file.
$appels = [];
$repond(fn($m, $p) => $m === 'POST' ? [201, ['id' => 9010]] : [0, null]);
$oQ = $commande('silence', true);
wsm_order_document($pdo, $oQ, 'test');
$dq = $docDe($oQ);
ok('impression sans réponse : « blad », et la note dit de vérifier chez Fakturownia avant de relancer',
   $dq['fiskal_status'] === 'blad' && str_contains($dq['fiskal_note'], 'sprawdź w Fakturowni'), $dq['fiskal_note']);
// La relance à la main relit d'abord ; si la lecture échoue, on n'imprime PAS.
$appels = [];
$repond(fn($m, $p) => $p === '/invoices/9010.json' ? [502, null] : [200, ['code' => 'success']]);
[$etat] = wsm_fiskal_wyslij($pdo, $docDe($oQ), $oQ, 'test', true);
ok('relance à la main, lecture impossible : rien n\'est imprimé (on ne double pas sur un doute)',
   $etat === 'blad' && $chemins() === ['GET /invoices/9010.json'] && str_contains($docDe($oQ)['fiskal_note'], 'nic nie wydrukowano'), $chemins());
// Une réponse tardive n'efface pas un lien déjà arrivé.
wsm_fiskal_mark($pdo, (int) $dq['id'], 'gotowy', ['fiskal_url' => $lien]);
wsm_fiskal_mark($pdo, (int) $dq['id'], 'blad', ['fiskal_note' => 'réponse tardive']);
ok('« gotowy » est final : une écriture tardive ne le défait pas', $docDe($oQ)['fiskal_status'] === 'gotowy');

// c ter) Un processus mort en route : « wysyłanie » ne reste pas pour toujours.
$appels = [];
$repond(fn() => throw new RuntimeException('coupure'));
$oX = $commande('coupure', true);
wsm_order_document($pdo, $oX, 'test');
$dx = $docDe($oX);
ok('une exception en route est écrite « blad », avec sa raison', $dx['fiskal_status'] === 'blad' && str_contains($dx['fiskal_note'], 'coupure'), $dx['fiskal_note']);
$pdo->prepare("UPDATE wsm_invoices SET fiskal_status = 'wysylka', fiskal_at = ? WHERE id = ?")
    ->execute([date('Y-m-d H:i:s', time() - 3600), (int) $dx['id']]);
$l = wsm_fiskal_linia($oX, $docDe($oX), 'paragon');
ok('« wysyłanie » vieux de dix minutes : la console propose de reprendre', ($l['agir'] ?? '') === 'fiskal' && str_contains($l['val'], 'bezpieczne'), $l);
$appels = [];
$repond(fn($m, $p) => $m === 'POST' ? [201, ['id' => 9011]] : [200, ['code' => 'success']]);
wsm_fiskal_run($pdo, 20);
ok('… et la relance automatique le reprend (sans reçu, rien n\'a pu être imprimé)', $docDe($oX)['fiskal_status'] === 'zlecony' && $docDe($oX)['fiskal_id'] === '9011');

// d) La main relance : l'e-paragon existait déjà — on n'imprime rien.
$appels = [];
$repond(fn($m, $p) => $p === '/invoices/9003.json' ? [200, ['id' => 9003, 'e_receipt_view_url' => $lien]] : [200, ['code' => 'success']]);
[$etat] = wsm_fiskal_wyslij($pdo, $docDe($oD), $oD, 'test', true);
ok('relance à la main : relu d\'abord, déjà émis → aucune impression', $etat === 'gotowy' && $chemins() === ['GET /invoices/9003.json'], $chemins());
// e) Ce qu'une caisse refuse, dit avant de partir.
[$pl, $err] = wsm_fiskal_payload(['kind' => 'paragon', 'currency' => 'EUR', 'items' => []], []);
ok('pas de caisse en euros', $pl === null && str_contains((string) $err, 'złotówki'));
$item = ['name' => 'X', 'qty' => 1, 'vat_rate' => 0.07, 'line_gross' => 100];
[$pl, $err] = wsm_fiskal_payload(['kind' => 'paragon', 'currency' => 'PLN', 'total_gross' => 100, 'items' => [$item]], []);
ok('un taux inconnu de l\'imprimante est refusé, nommé', $pl === null && str_contains((string) $err, '7%'));
$item['vat_rate'] = 0.23;
[$pl, $err] = wsm_fiskal_payload(['kind' => 'paragon', 'currency' => 'PLN', 'total_gross' => 101, 'items' => [$item],
                                   'number' => 'PAR/1', 'sold_at' => '2026-10-30', 'issued_at' => '2026-10-30', 'buyer_name' => 'A'], []);
ok('une somme qui ne tombe pas juste ne part pas', $pl === null && str_contains((string) $err, 'suma'));
$item3 = ['name' => 'Trzy', 'qty' => 3, 'vat_rate' => 0.05, 'line_gross' => 3335];
[$pl] = wsm_fiskal_payload(['kind' => 'paragon', 'currency' => 'PLN', 'total_gross' => 3335, 'items' => [$item3],
                            'number' => 'PAR/2', 'sold_at' => '2026-10-30', 'issued_at' => '2026-10-30', 'buyer_name' => 'A'], []);
$ps = array_map(fn($p) => [$p['quantity'], $p['total_price_gross']], $pl['invoice']['positions'] ?? []);
ok('33,35 zł pour 3 : coupé en 2 × 11,11 + 1 × 11,13, le ticket tombe juste', $ps === [[2, '22.22'], [1, '11.13']], $ps);
[$e, $ph] = wsm_fiskal_wyslij($pdo, ['kind' => 'faktura', 'id' => 0], []);
ok('une facture ne va jamais sur la caisse', $e === '' && str_contains($ph, 'faktura'));

// ---- 8. Le document « à la paye » ------------------------------------------------------------
echo "\n-- dokument przy opłaceniu: płatność Stripe go wystawia --\n";
wsm_config_overlay(['orders' => array_replace($ordAvant, ['doc_status' => 'oplacone'])]);
$appels = [];
$repond(fn($m, $p) => $m === 'POST' ? [201, ['id' => 9004]] : [200, ['code' => 'success']]);
$oS = $commande('stripe', true);
ok('avant le paiement : pas de document', $docDe($oS) === null);
wsm_order_mark_paid($pdo, (int) $oS['id'], 'stripe');
$doc = $docDe($oS);
ok('le paiement émet le paragon ET le passe en caisse', $doc && $doc['kind'] === 'paragon' && $doc['fiskal_status'] === 'zlecony', $doc['fiskal_status'] ?? null);
$appels = [];
wsm_order_status_set($pdo, (int) $oS['id'], 'oplacone', 'test');
ok('repasser par l\'état ne refait rien', $appels === []);
ok('un second avis de paiement (webhook + retour client) ne passe pas : un seul document',
   wsm_order_mark_paid($pdo, (int) $oS['id'], 'stripe') === false
   && (int) $pdo->query("SELECT COUNT(*) FROM wsm_invoices WHERE order_id = " . (int) $oS['id'])->fetchColumn() === 1);
$oC = $commande('annulee', true);
$pdo->prepare("UPDATE wsm_orders SET status = 'anulowane' WHERE id = ?")->execute([(int) $oC['id']]);
$appels = [];
wsm_order_mark_paid($pdo, (int) $oC['id'], 'stripe');
ok('commande annulée payée par un vieux lien : pas de document, pas de caisse', $docDe($oC) === null && $appels === []);
$tr = wsm_status_triggers($pdo);
$op = array_values(array_filter($tr, fn($t) => $t['statut'] === 'oplacone'))[0];
ok('la table des déclencheurs suit le réglage : le document est sur « opłacone »',
   (bool) array_filter($op['wyzwala'], fn($w) => str_contains($w, 'kasę fiskalną')), $op['wyzwala']);
ok('… et ne nomme plus tpay', stripos(json_encode($tr, JSON_UNESCAPED_UNICODE), 'tpay') === false);
wsm_config_overlay(['orders' => array_replace($ordAvant, ['doc_status' => 'wyslane'])]);
$appels = [];
$oW = $commande('wyslane', true);
wsm_order_mark_paid($pdo, (int) $oW['id'], 'stripe');
ok('réglage par défaut (« wysłane ») : le paiement n\'émet rien, comme avant', $docDe($oW) === null && $appels === []);
$resetOrd();

// ---- 9. Console, caisse, page ----------------------------------------------------------------
echo "\n-- konsola, kasa sklepu, strona zamówienia --\n";
ok('l\'étiquette de la liste Faktury', wsm_fiskal_tag($docDe($oE))[1] === 'e-paragon' && wsm_fiskal_tag($docDe($oD))[0] === 'ok');
ok('… et rien pour une facture', wsm_fiskal_tag(['kind' => 'faktura']) === ['', '']);
$idx = $lire('generique/backoffice/php-api/index.php');
ok('route du webhook : shop/fakturownia/webhook', str_contains($idx, "'fakturownia/webhook'") && str_contains(wsm_fiskal_webhook_url(), '/shop/fakturownia/webhook'));
$ust = $lire('generique/backoffice/ustawienia.php');
ok('Integracje : panneau, test et adresse du webhook',
   str_contains($ust, "'paragon' => ['E-paragon fiskalny") && str_contains($ust, "'paragon' => 'wsm_fiskal_diag'") && str_contains($ust, 'wsm_fiskal_webhook_url()'));
ok('Zamówienia : le bouton de relance', str_contains($lire('generique/backoffice/zamowienia.php'), "isset(\$_POST['fiskal'])"));
$shop = $lire('generique/shop/index.php');
ok('la caisse propose l\'accord e-paragon (avec son champ témoin)',
   str_contains($shop, 'name="e_paragon_pole"') && str_contains($shop, 'name="e_paragon"') && str_contains($shop, 'kasa_fiskalna()'));
ok('la page de commande affiche le paragon', str_contains($shop, 'wsm_fiskal_dla_klienta('));
ok('la case e-paragon est DÉCOCHÉE d\'office : un accord se donne, il ne se présume pas',
   str_contains($shop, "<?= !empty(\$v['e_paragon']) ? ' checked' : '' ?>") && !str_contains($shop, "!isset(\$v['e_paragon_pole']) ||"));
$seed = json_decode($lire('generique/shop/content_seed.json'), true);
$cles = ['checkout.ereceipt', 'checkout.ereceipt_hint', 'order.receipt', 'order.receipt_open', 'order.receipt_wait', 'order.receipt_paper'];
$manque = [];
foreach (['pl', 'uk', 'en'] as $lg) foreach ($cles as $k) if (trim((string) ($seed['strings'][$lg][$k] ?? '')) === '') $manque[] = "$lg:$k";
ok('les textes existent en polonais, ukrainien et anglais', $manque === [], $manque);
// Le texte d'aide déjà en base (« décochez si… ») est remplacé une fois,
// sauf s'il a été réécrit dans Treści. Joué pour de vrai sur des lignes de test.
require_once dirname(__DIR__) . '/seed.php';
$zg = wsm_content_jednorazowe()['migracja.2026_10_eparagon_zgoda'] ?? [];
ok('la migration de l\'aide existe, pour les trois langues', count($zg) === 3);
$pdo->exec("DELETE FROM wsm_shop_i18n WHERE k IN ('e2e.fk.hint', 'e2e.fk.hint2')");
$pdo->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES ('pl', 'e2e.fk.hint', ?), ('pl', 'e2e.fk.hint2', ?)")
    ->execute([$zg[0][1][3] ?? '', 'Tekst przepisany ręcznie']);
foreach ($zg as [$sql, $args]) {
    foreach (['e2e.fk.hint', 'e2e.fk.hint2'] as $kk) { $a = $args; $a[1] = $kk; $pdo->prepare($sql)->execute($a); }
}
$lit = fn(string $k) => (string) $pdo->query("SELECT v FROM wsm_shop_i18n WHERE lang = 'pl' AND k = " . $pdo->quote($k))->fetchColumn();
ok('… l\'ancienne aide devient la nouvelle', $lit('e2e.fk.hint') === ($seed['strings']['pl']['checkout.ereceipt_hint'] ?? '?'), $lit('e2e.fk.hint'));
ok('… un texte réécrit à la main n\'est pas touché', $lit('e2e.fk.hint2') === 'Tekst przepisany ręcznie');
$pdo->exec("DELETE FROM wsm_shop_i18n WHERE k IN ('e2e.fk.hint', 'e2e.fk.hint2')");
$cible = [];
foreach ($zg as [, $args]) $cible[$args[2]] = $args[0];
ok('le fichier de contenu porte le même texte que la migration (pl, uk, en)',
   $cible !== [] && array_reduce(array_keys($cible), fn($c, $lg) => $c && ($seed['strings'][$lg]['checkout.ereceipt_hint'] ?? '') === $cible[$lg], true));
ok('l\'aide ne dit plus « décochez »', !str_contains($seed['strings']['pl']['checkout.ereceipt_hint'] ?? '', 'Odznacz'));
ok('aucun vrai jeton dans le dépôt (config lit l\'environnement)',
   str_contains($lire('generique/backoffice/php-api/config.php'), "getenv('WSM_FAKTUROWNIA_TOKEN')"));

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
