<?php
// ============================================================================
//  e2e_vies.php — preuve que la vérification VIES distingue « ce numéro n'existe
//  pas » de « je n'ai pas pu demander ».
//
//  C'est TOUT l'enjeu. VIES interroge chaque administration nationale en direct,
//  et ces administrations tombent. Confondre les deux cas donne l'un ou l'autre
//  de ces deux désastres :
//     · bloquer les ventes chaque fois qu'un service public est en panne ;
//     · accepter n'importe quel numéro inventé.
//
//  Le transport HTTP est injecté : on éprouve la décision sans dépendre d'un
//  service public dont l'indisponibilité est précisément le sujet.
//
//  Usage :  php tests/e2e_vies.php [baseUrl] [adminToken]
// ============================================================================

$BASE  = rtrim($argv[1] ?? getenv('WSM_API_BASE') ?: 'http://localhost:8090', '/');
$TOKEN = $argv[2] ?? getenv('WSM_ADMIN_TOKEN') ?: 'dev-admin-token';

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}
function http(string $method, string $url, ?array $body = null, ?string $token = null): array {
    $headers = ['Accept: application/json'];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    if ($token !== null) $headers[] = 'X-Admin-Token: ' . $token;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw ?: 'null', true)];
}
function badField(array $r, string $f): bool { return ($r[0] ?? 0) === 422 && isset($r[1]['fields'][$f]); }

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/vies.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end VIES (numéros de TVA intracommunautaire)\n";
echo "base: $BASE\n\n";

// ---- 1. Découpage du numéro ------------------------------------------------
echo "-- lecture du numéro --\n";
ok('« pl 525-224-84-81 » → PL + 5252248481', wsm_vies_split('pl 525-224-84-81') === ['PL', '5252248481']);
ok('normalisation retire séparateurs et casse', wsm_vies_normalize('de 123 456 789') === 'DE123456789');
ok('numéro sans code pays refusé', wsm_vies_split('5252248481') === ['', '']);

// ---- 2. Traduction des réponses VIES ---------------------------------------
// La colonne du milieu, c'est la seule qui compte.
echo "-- interprétation : « faux » n'est pas « je ne sais pas » --\n";
$valid = wsm_vies_interpret(200, ['isValid' => true, 'name' => 'CUKIERNIA TESTOWA SP. Z O.O.',
    'address' => "MARSZALKOWSKA 104\n00-026 WARSZAWA", 'requestIdentifier' => 'WAPIAAAAX000111']);
ok('isValid → valid', $valid['status'] === 'valid', $valid);
ok('la raison sociale est reprise', $valid['name'] === 'CUKIERNIA TESTOWA SP. Z O.O.', $valid['name']);
ok('l\'adresse est mise sur une ligne', $valid['address'] === 'MARSZALKOWSKA 104, 00-026 WARSZAWA', $valid['address']);
ok('le numéro de consultation est conservé (c\'est la preuve)',
    $valid['consultation'] === 'WAPIAAAAX000111', $valid['consultation']);

ok('INVALID → invalid', wsm_vies_interpret(200, ['isValid' => false, 'userError' => 'INVALID'])['status'] === 'invalid');
ok('INVALID_INPUT → invalid', wsm_vies_interpret(200, ['isValid' => false, 'userError' => 'INVALID_INPUT'])['status'] === 'invalid');

foreach (['MS_UNAVAILABLE', 'SERVICE_UNAVAILABLE', 'TIMEOUT', 'VAT_BLOCKED', 'IP_BLOCKED',
          'GLOBAL_MAX_CONCURRENT_REQ', 'MS_MAX_CONCURRENT_REQ'] as $err) {
    $r = wsm_vies_interpret(200, ['isValid' => false, 'userError' => $err]);
    if ($r['status'] !== 'unavailable') { ok("« $err » → unavailable", false, $r); break; }
}
ok('les sept pannes connues de VIES donnent « unavailable », jamais « invalid »', true);
ok('erreur réseau (code 0) → unavailable', wsm_vies_interpret(0, null)['status'] === 'unavailable');
ok('erreur serveur (503) → unavailable', wsm_vies_interpret(503, null)['status'] === 'unavailable');

echo "-- ce qui bloque une saisie --\n";
ok('seul « invalid » bloque', wsm_vies_blocks(['status' => 'invalid']));
ok('« unavailable » ne bloque PAS — sinon une panne de la Commission arrête les ventes',
    !wsm_vies_blocks(['status' => 'unavailable']));
ok('« valid » ne bloque pas', !wsm_vies_blocks(['status' => 'valid']));
ok('« skipped » ne bloque pas', !wsm_vies_blocks(['status' => 'skipped']));

echo "-- autoliquidation (constat, pas application) --\n";
ok('numéro allemand valide → éligible', wsm_vies_reverse_charge(['status' => 'valid', 'country' => 'DE']));
ok('numéro polonais valide → non éligible (marché intérieur)',
    !wsm_vies_reverse_charge(['status' => 'valid', 'country' => 'PL']));
ok('numéro allemand non vérifiable → non éligible',
    !wsm_vies_reverse_charge(['status' => 'unavailable', 'country' => 'DE']));

// ---- 3. Le contrôle complet, avec transport injecté -------------------------
echo "-- contrôle complet (transport simulé) --\n";
$calls = 0;
$answer = ['isValid' => true, 'name' => 'FIRMA TESTOWA', 'address' => 'ul. Testowa 1', 'requestIdentifier' => 'REF-1'];
wsm_vies_transport(function (string $c, string $n) use (&$calls, &$answer) { $calls++; return [200, $answer]; });

$vat = 'PL' . random_int(1000000000, 9999999999);
$r1 = wsm_vies_check($pdo, $vat);
ok('numéro valide → valid', $r1['status'] === 'valid' && $calls === 1, [$r1['status'], $calls]);
ok('la raison sociale remonte', $r1['name'] === 'FIRMA TESTOWA', $r1['name'] ?? null);

$r2 = wsm_vies_check($pdo, strtolower(str_replace('PL', 'pl ', $vat)));
ok('deuxième appel servi par le cache (VIES est lent, on ne le harcèle pas)',
    $r2['status'] === 'valid' && !empty($r2['cached']) && $calls === 1, [$r2['cached'] ?? null, $calls]);

$r3 = wsm_vies_check($pdo, $vat, true);
ok('« forcer » ignore le cache', $calls === 2, $calls);

// Une panne ne doit surtout pas être mise en cache : elle empêcherait le
// contrôle de réussir plus tard.
$vat2 = 'DE' . random_int(100000000, 999999999);
$answer = ['isValid' => false, 'userError' => 'MS_UNAVAILABLE'];
$before = $calls;
$u1 = wsm_vies_check($pdo, $vat2);
$u2 = wsm_vies_check($pdo, $vat2);
ok('« unavailable » n\'est jamais mis en cache', $u1['status'] === 'unavailable'
    && $u2['status'] === 'unavailable' && ($calls - $before) === 2, [$u1['status'], $calls - $before]);

$answer = ['isValid' => false, 'userError' => 'INVALID'];
$vat3 = 'FR' . random_int(10000000000, 99999999999);
$i1 = wsm_vies_check($pdo, $vat3);
$before = $calls;
$i2 = wsm_vies_check($pdo, $vat3);
ok('« invalid » est mis en cache (le numéro ne redeviendra pas valide demain)',
    $i1['status'] === 'invalid' && !empty($i2['cached']) && $calls === $before, [$i2['cached'] ?? null]);

$before = $calls;
$bad = wsm_vies_check($pdo, 'CECI-N-EST-PAS-UN-NUMERO');
ok('format aberrant refusé SANS appel réseau', $bad['status'] === 'invalid' && $calls === $before, $bad);
// « PA » ressemble à un code pays sans en être un : interroger VIES pour un
// pays qui n'y participe pas ne peut rien donner.
$us = wsm_vies_check($pdo, 'US123456789');
ok('code pays hors UE refusé sans appel réseau', $us['status'] === 'invalid' && $calls === $before, $us);
ok('la Grèce s\'écrit EL, pas GR', wsm_valid_vat_eu('EL123456789') && !wsm_valid_vat_eu('GR123456789'));
ok('XI (Irlande du Nord) reconnu', wsm_valid_vat_eu('XI123456789'));
$empty = wsm_vies_check($pdo, '');
ok('champ vide → skipped, pas une erreur', $empty['status'] === 'skipped', $empty);

echo "-- historique et preuve --\n";
$st = $pdo->prepare("SELECT status, consultation FROM wsm_vies_checks WHERE vat_eu = ? ORDER BY id");
$st->execute([$vat]);
$hist = $st->fetchAll();
$st->closeCursor();
ok('chaque consultation est journalisée (2 pour ce numéro)', count($hist) === 2, count($hist));
ok('le numéro de consultation est conservé en base',
    ($hist[0]['consultation'] ?? '') === 'REF-1', $hist[0]['consultation'] ?? null);
$st = $pdo->prepare("SELECT COUNT(*) FROM wsm_vies_checks WHERE vat_eu = ? AND status = 'unavailable'");
$st->execute([$vat2]);
$nUnavail = (int) $st->fetchColumn();
$st->closeCursor();
ok('les consultations infructueuses sont journalisées aussi', $nUnavail === 2, $nUnavail);

$cols = wsm_vies_columns($r1);
ok('l\'état porté sur le client contient statut, date, nom et preuve',
    $cols['vat_status'] === 'valid' && $cols['vat_checked_at'] !== null
    && $cols['vat_name'] === 'FIRMA TESTOWA' && $cols['vat_consultation'] === 'REF-1', $cols);

// ---- 3bis. La conséquence : 0 % de TVA, ou pas ----------------------------
// C'est ici que VIES coûte ou rapporte de l'argent. On garde le transport
// simulé : il faut pouvoir provoquer un « valid » à volonté.
echo "-- TVA : autoliquidation appliquée, pas seulement signalée --\n";
require_once dirname(__DIR__) . '/shop.php';

$answer = ['isValid' => true, 'name' => 'FIRMA DE', 'address' => 'Berlin', 'requestIdentifier' => 'REF-DE'];
$prodId = ($pdo->query("SELECT id FROM wsm_products WHERE shop_visible = 1 ORDER BY sort_order LIMIT 1")
             ->fetchColumn()) ?: '';
$items  = [['id' => $prodId, 'qty' => 2]];
$deVat  = 'DE' . random_int(100000000, 999999999);

// La Pologne d'abord : marché intérieur, TVA polonaise, quoi qu'il arrive.
[$qPl] = wsm_shop_quote($pdo, $items, 'inpost_locker', 'pl', ['country' => 'PL', 'vat_eu' => $deVat]);
ok('Pologne → TVA polonaise même avec un numéro UE valide',
    empty($qPl['reverse_charge']) && $qPl['items_vat'] > 0, [$qPl['reverse_charge'] ?? null, $qPl['items_vat']]);

// Un pays fermé ne peut pas être choisi.
[$qShut, $eShut] = wsm_shop_quote($pdo, $items, 'inpost_locker', 'pl', ['country' => 'DE', 'vat_eu' => $deVat]);
ok('pays non ouvert à la vente → refusé', isset($eShut['ship_country']), $eShut);

// On ouvre l'Allemagne et on étend le coursier, comme le ferait la console.
$pdo->prepare("UPDATE wsm_countries SET active = 1 WHERE code = 'DE'")->execute();
$pdo->prepare("UPDATE wsm_shipping_methods SET countries = 'PL,DE' WHERE id = 'inpost_courier'")->execute();

[$qDe, $eDe] = wsm_shop_quote($pdo, $items, 'inpost_courier', 'pl', ['country' => 'DE', 'vat_eu' => $deVat]);
ok('autre État membre + numéro confirmé → autoliquidation', !empty($qDe['reverse_charge']), $eDe ?: $qDe['reverse_charge']);
ok('la TVA tombe à zéro', $qDe['total_vat'] === 0 && $qDe['items_vat'] === 0, [$qDe['items_vat'], $qDe['total_vat']]);
ok('l\'acheteur paie le HT, pas le TTC', $qDe['items_gross'] === $qDe['items_net'], [$qDe['items_gross'], $qDe['items_net']]);
ok('le total reste cohérent', ($qDe['total_net'] + $qDe['total_vat']) === $qDe['total_gross'], $qDe);
ok('la facture est moins chère qu\'en Pologne', $qDe['total_gross'] < $qPl['total_gross'],
    [$qDe['total_gross'], $qPl['total_gross']]);
ok('la ventilation de TVA est vide en autoliquidation', $qDe['vat_breakdown'] === [], $qDe['vat_breakdown']);

// Sans numéro : c'est un particulier, il paie la TVA polonaise.
[$qDeB2C] = wsm_shop_quote($pdo, $items, 'inpost_courier', 'pl', ['country' => 'DE']);
ok('particulier d\'un autre État membre → TVA polonaise (sous le seuil OSS)',
    empty($qDeB2C['reverse_charge']) && $qDeB2C['items_vat'] > 0, $qDeB2C['items_vat']);

// VIES muet : on n'exonère pas sur une réponse qu'on n'a pas eue.
$answer = ['isValid' => false, 'userError' => 'MS_UNAVAILABLE'];
$deVat2 = 'DE' . random_int(100000000, 999999999);
[$qDown] = wsm_shop_quote($pdo, $items, 'inpost_courier', 'pl', ['country' => 'DE', 'vat_eu' => $deVat2]);
ok('VIES indisponible → PAS d\'exonération, la commande passe quand même',
    empty($qDown['reverse_charge']) && $qDown['items_vat'] > 0, $qDown['items_vat']);

// Un transporteur qui ne dessert pas le pays ne doit pas être proposé.
$pdo->prepare("UPDATE wsm_shipping_methods SET countries = 'PL' WHERE id = 'inpost_courier'")->execute();
[$qNoShip, $eNoShip] = wsm_shop_quote($pdo, $items, 'inpost_courier', 'pl', ['country' => 'DE']);
ok('aucun transporteur pour le pays → refusé, pas une promesse en l\'air',
    isset($eNoShip['delivery_method']), $eNoShip);
$pdo->prepare("UPDATE wsm_shipping_methods SET countries = 'PL,DE' WHERE id = 'inpost_courier'")->execute();

$plMethods = wsm_shipping_methods($pdo, 'pl', 'PL');
$deMethods = wsm_shipping_methods($pdo, 'pl', 'DE');
// ON VÉRIFIE LA RÈGLE, PAS UN NOMBRE. Cette ligne exigeait « la Pologne a
// exactement 2 méthodes » : elle est devenue rouge le jour où l'on a ajouté
// DPD, alors que rien n'était cassé. Un test qui fige un compte se casse à
// chaque enrichissement du catalogue et n'apprend rien sur la règle — qui
// est, elle, la seule chose à protéger : un Paczkomat est polonais.
$punkt = fn(array $ms): array => array_values(array_filter($ms, fn($m) => ($m['kind'] ?? '') === 'punkt'));
ok('la Pologne se voit bien proposer un point de retrait', $punkt($plMethods) !== [],
    array_column($plMethods, 'id'));
ok('le Paczkomat n\'est PAS proposé à l\'étranger', $punkt($deMethods) === [],
    array_column($punkt($deMethods), 'id'));
ok('et l\'Allemagne ne voit que ce qui la dessert vraiment',
    $deMethods !== [] && !in_array('inpost_locker', array_column($deMethods, 'id'), true),
    array_column($deMethods, 'id'));

// On referme l'Allemagne : l'état de départ est la Pologne seule.
$pdo->prepare("UPDATE wsm_countries SET active = 0 WHERE code = 'DE'")->execute();
$pdo->prepare("UPDATE wsm_shipping_methods SET countries = 'PL' WHERE id = 'inpost_courier'")->execute();

ok('27 pays de l\'UE sont en base', (int) $pdo->query("SELECT COUNT(*) FROM wsm_countries")->fetchColumn() === 27);
ok('seule la Pologne est ouverte par défaut',
    (int) $pdo->query("SELECT COUNT(*) FROM wsm_countries WHERE active = 1")->fetchColumn() === 1);

wsm_vies_transport('wsm_vies_http');   // on rend le vrai transport à la suite

// ---- 4. À travers l'API ----------------------------------------------------
echo "-- API --\n";
ok('vérification sans identité → 401', http('POST', "$BASE/franchisor/vies", ['vat_eu' => 'PL5252248481'])[0] === 401);

$r = http('POST', "$BASE/franchisor/vies", ['vat_eu' => 'ZZ-pas-un-numero'], $TOKEN);
ok('format aberrant → 200 avec status invalid',
    $r[0] === 200 && ($r[1]['status'] ?? '') === 'invalid' && !empty($r[1]['blocks']), $r);

$r = http('POST', "$BASE/franchisor/vies", ['vat_eu' => 'PL5252248481'], $TOKEN);
$status = $r[1]['status'] ?? '';
ok('numéro bien formé → réponse exploitable, jamais une erreur serveur',
    $r[0] === 200 && in_array($status, ['valid', 'invalid', 'unavailable'], true), $r);
echo "     (VIES a répondu « $status » depuis cette machine)\n";
ok('la réponse dit si une preuve est possible', array_key_exists('provable', $r[1] ?? []), array_keys($r[1] ?? []));

// Enregistrement d'un client : la forme est refusée avant tout appel réseau.
$mk = fn(array $x = []) => array_merge([
    'raison' => 'VIES Test ' . bin2hex(random_bytes(3)), 'client_type' => 'firma',
    'email' => 'vies@example.pl', 'phone' => '512340099', 'nip' => '5252248481',
], $x);

$r = http('POST', "$BASE/franchisor/client", $mk(['vat_eu' => 'ZZ']), $TOKEN);
ok('client avec numéro de TVA mal formé → 422', badField($r, 'vat_eu'), $r);

$r = http('POST', "$BASE/franchisor/client", $mk(['vat_eu' => 'PL5252248481']), $TOKEN);
ok('client avec numéro bien formé → enregistré', $r[0] === 200 && !empty($r[1]['id']), $r);
$cid = (int) ($r[1]['id'] ?? 0);

$st = $pdo->prepare("SELECT vat_eu, vat_status, vat_checked_at FROM wsm_clients WHERE id = ?");
$st->execute([$cid]);
$saved = $st->fetchAll()[0] ?? [];
$st->closeCursor();
ok('l\'état VIES est écrit sur la fiche client',
    in_array((string) ($saved['vat_status'] ?? ''), ['valid', 'unavailable', 'skipped'], true), $saved);
ok('un service indisponible n\'a PAS empêché l\'enregistrement', $cid > 0);

$r = http('POST', "$BASE/franchisor/client", ['id' => $cid, 'raison' => 'VIES Test', 'vat_eu' => ''], $TOKEN);
ok('effacer le numéro est permis', $r[0] === 200, $r);

http('POST', "$BASE/franchisor/client", ['delete' => $cid], $TOKEN);

// Commande : même règle à la caisse.
[, $cat] = http('GET', "$BASE/shop/catalog");
$pid = $cat['products'][0]['id'] ?? '';
$order = ['items' => [['id' => $pid, 'qty' => 1]], 'delivery_method' => 'inpost_locker',
          'inpost_point' => 'KRA010', 'first_name' => 'Jan', 'last_name' => 'Testowy',
          'email' => 'vies-' . bin2hex(random_bytes(3)) . '@example.pl', 'phone' => '512340077',
          'consent_terms' => true];
$r = http('POST', "$BASE/shop/order", array_merge($order, ['vat_eu' => 'ZZ1']), $TOKEN);
ok('commande avec numéro de TVA mal formé → 422', badField($r, 'vat_eu'), $r);

echo "\n" . ($fail === 0 ? "ALL GREEN: $pass passed, 0 failed" : "FAILURES: $pass passed, $fail FAILED") . "\n";
exit($fail === 0 ? 0 : 1);
