<?php
// ============================================================================
//  e2e_delivery.php — end-to-end proof that the delivery module is 100%
//  functional against the LIVE HTTP API (not just the DB layer).
//
//  It drives the full lifecycle over HTTP:
//    create (test delivery) → assign driver+round → mark en_cours
//    → confirm with the issued code → verify status/events/audit/KPIs
//  and checks the negative paths (bad token, bad confirm code, double confirm).
//
//  Usage:  php tests/e2e_delivery.php [baseUrl] [adminToken]
//          (defaults: http://localhost:8090  dev-admin-token)
// ============================================================================

$BASE  = rtrim($argv[1] ?? getenv('WSM_API_BASE') ?: 'http://localhost:8090', '/');
$TOKEN = $argv[2] ?? getenv('WSM_ADMIN_TOKEN') ?: 'dev-admin-token';

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

/** Minimal HTTP client returning [status, decodedBody]. */
function http(string $method, string $url, ?array $body = null, ?string $token = null): array {
    $headers = ['Accept: application/json'];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    if ($token !== null) $headers[] = 'X-Admin-Token: ' . $token;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw ?: 'null', true)];
}

echo "webshop_mrszoko — end-to-end delivery test\n";
echo "base: $BASE\n\n";

// --- 0. sanity: API up ------------------------------------------------------
[$c, $kpis] = http('GET', "$BASE/franchisor/kpis", null, $TOKEN);
ok('API reachable (GET /franchisor/kpis → 200)', $c === 200 && is_array($kpis), $c);
if ($c !== 200) { echo "\nAPI not reachable — start it with ./serve.sh\n"; exit(1); }

// --- 1. baseline KPIs -------------------------------------------------------
[, $k0] = http('GET', "$BASE/franchisor/delivery-kpis", null, $TOKEN);
$total0 = $k0['total']; $delivered0 = $k0['delivered'];
echo "baseline: {$total0} deliveries, {$delivered0} delivered\n";

// --- 2. pick a real delivery point -----------------------------------------
[, $clients] = http('GET', "$BASE/franchisor/delivery-clients", null, $TOKEN);
$point = null; $clientCode = null;
foreach ($clients as $cl) {
    if (!empty($cl['points'])) { $point = $cl['points'][0]; $clientCode = $cl['code']; break; }
}
ok('has at least one delivery point', $point !== null);

// --- 3. write requires the admin token -------------------------------------
[$c, $b] = http('POST', "$BASE/franchisor/deliveries", ['point_id' => $point['id']]);         // no token
ok('create without token → 401', $c === 401, $c);
[$c, $b] = http('POST', "$BASE/franchisor/deliveries", ['point_id' => $point['id']], 'wrong'); // bad token
ok('create with bad token → 401', $c === 401, $c);

// --- 4. create a TEST delivery ---------------------------------------------
[$c, $d] = http('POST', "$BASE/franchisor/deliveries",
    ['point_id' => $point['id'], 'ca' => 420, 'couts' => 180, 'notes' => 'Livraison test e2e'], $TOKEN);
ok('create test delivery → 201', $c === 201, $c);
ok('delivery has a ref (LIV-…)', isset($d['ref']) && str_starts_with($d['ref'], 'LIV-'), $d['ref'] ?? null);
ok('status is planifiée', ($d['status'] ?? '') === 'planifiée', $d['status'] ?? null);
ok('confirm code issued', !empty($d['confirm_code']), $d['confirm_code'] ?? null);
ok('marge computed (420-180=240)', (float) ($d['marge'] ?? 0) === 240.0, $d['marge'] ?? null);
$id = $d['id']; $code = $d['confirm_code'];

// --- 5. assign a driver + round --------------------------------------------
[, $drivers] = http('GET', "$BASE/franchisor/drivers", null, $TOKEN);
[, $rounds]  = http('GET', "$BASE/franchisor/rounds", null, $TOKEN);
$driverId = $drivers[0]['id']; $roundId = $rounds[0]['id'];
[$c, $d] = http('POST', "$BASE/franchisor/deliveries/$id/assign", ['driver_id' => $driverId, 'round_id' => $roundId], $TOKEN);
ok('assign → 200', $c === 200, $c);
ok('status now assignée', ($d['status'] ?? '') === 'assignée', $d['status'] ?? null);
ok('driver attached', !empty($d['driver']), $d['driver'] ?? null);

// --- 6. move to en_cours ----------------------------------------------------
[$c, $d] = http('POST', "$BASE/franchisor/deliveries/$id/status", ['status' => 'en_cours'], $TOKEN);
ok('status → en_cours', ($d['status'] ?? '') === 'en_cours', $d['status'] ?? null);

// --- 7. confirm with a WRONG code is rejected ------------------------------
[$c, $b] = http('POST', "$BASE/franchisor/deliveries/$id/confirm", ['code' => 'NOPE-0000'], $TOKEN);
ok('confirm with wrong code → 409 bad_code', $c === 409 && ($b['error'] ?? '') === 'bad_code', [$c, $b]);

// --- 8. confirm with the RIGHT code -----------------------------------------
[$c, $d] = http('POST', "$BASE/franchisor/deliveries/$id/confirm", ['code' => $code], $TOKEN);
ok('confirm with issued code → 200', $c === 200, $c);
ok('status now livrée', ($d['status'] ?? '') === 'livrée', $d['status'] ?? null);
ok('confirmed flag set', ((int) ($d['confirmed'] ?? 0)) === 1, $d['confirmed'] ?? null);
ok('confirmed_at stamped', !empty($d['confirmed_at']), $d['confirmed_at'] ?? null);

// --- 9. double confirm is rejected -----------------------------------------
[$c, $b] = http('POST', "$BASE/franchisor/deliveries/$id/confirm", ['code' => $code], $TOKEN);
ok('re-confirm → 409 already_confirmed', $c === 409 && ($b['error'] ?? '') === 'already_confirmed', [$c, $b]);

// --- 10. event trail --------------------------------------------------------
[, $events] = http('GET', "$BASE/franchisor/deliveries/$id/events", null, $TOKEN);
$evNames = array_column($events, 'event');
ok('event trail has créée→assignée→en_cours→livrée',
    $evNames === ['créée', 'assignée', 'en_cours', 'livrée'], $evNames);

// --- 11. KPIs moved ---------------------------------------------------------
[, $k1] = http('GET', "$BASE/franchisor/delivery-kpis", null, $TOKEN);
ok('total deliveries +1', $k1['total'] === $total0 + 1, [$total0, $k1['total']]);
ok('delivered +1', $k1['delivered'] === $delivered0 + 1, [$delivered0, $k1['delivered']]);

// --- 12. audit trail captured create + confirm ------------------------------
[, $audit] = http('GET', "$BASE/franchisor/audit", null, $TOKEN);
$auditEntities = array_column($audit, 'entity');
$refSeen = array_filter($auditEntities, fn($e) => str_contains($e, $d['ref']));
ok('audit log references the delivery ref', count($refSeen) >= 1, $d['ref']);

echo "\n" . ($fail === 0 ? "ALL GREEN" : "FAILURES") . ": $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
