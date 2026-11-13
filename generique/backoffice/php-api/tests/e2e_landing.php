<?php
// ============================================================================
//  e2e_landing.php — end-to-end proof of the multilingual landing content API.
//
//  Checks over live HTTP:
//    • GET /landing/content serves the 3 languages (pl default, uk, en)
//    • every language pack is complete (same keys) and non-empty
//    • products carry resolved texts per language + both currencies
//    • unknown lang falls back to the default
//    • admin write: POST /franchisor/landing-string upserts (and 401 without
//      token), and the change is visible on the public endpoint
//
//  Usage:  php tests/e2e_landing.php [baseUrl] [adminToken]
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

echo "webshop_mrszoko — end-to-end landing i18n test\n";
echo "base: $BASE\n\n";

[$code, $d] = http('GET', "$BASE/landing/content");
ok('API reachable (GET /landing/content → 200)', $code === 200, $code);
if ($code !== 200) { echo "\nAPI not reachable — start it with ./serve.sh\n"; exit(1); }

ok('default lang is pl', ($d['lang'] ?? '') === 'pl', $d['lang'] ?? null);
ok('3 langs available (pl, uk, en)', ($d['langs'] ?? []) === ['en', 'pl', 'uk'] || count(array_intersect(['pl','uk','en'], $d['langs'] ?? [])) === 3, $d['langs'] ?? null);

$packs = [];
foreach (['pl', 'uk', 'en'] as $lg) {
    [$c2, $p] = http('GET', "$BASE/landing/content?lang=$lg");
    $packs[$lg] = $p;
    ok("lang $lg → 200 and served as $lg", $c2 === 200 && ($p['lang'] ?? '') === $lg, $p['lang'] ?? $c2);
    ok("lang $lg has strings (≥ 50 keys)", count($p['strings'] ?? []) >= 50, count($p['strings'] ?? []));
    ok("lang $lg has 4 products", count($p['products'] ?? []) === 4, count($p['products'] ?? []));
}

$kPl = array_keys($packs['pl']['strings']); sort($kPl);
$kUk = array_keys($packs['uk']['strings']); sort($kUk);
$kEn = array_keys($packs['en']['strings']); sort($kEn);
ok('language packs are complete (same keys pl = uk = en)', $kPl === $kUk && $kUk === $kEn);

$blank = 0;
foreach ($packs as $p) foreach ($p['strings'] as $v) if (trim((string) $v) === '') $blank++;
ok('no empty string in any pack', $blank === 0, $blank);

$p0 = $packs['uk']['products'][0] ?? [];
ok('product texts resolved per lang (uk name is cyrillic)', (bool) preg_match('/\p{Cyrillic}/u', $p0['name'] ?? ''), $p0['name'] ?? null);
ok('product carries both currencies', isset($p0['price_from']['pln'], $p0['price_from']['eur']), $p0['price_from'] ?? null);
ok('hero title differs across langs',
    ($packs['pl']['strings']['hero.title'] ?? '') !== ($packs['en']['strings']['hero.title'] ?? ''));

[$c3, $p3] = http('GET', "$BASE/landing/content?lang=de");
ok('unknown lang falls back to default (de → pl)', $c3 === 200 && ($p3['lang'] ?? '') === 'pl', $p3['lang'] ?? $c3);

// ---- admin writes ----------------------------------------------------------
[$c4] = http('POST', "$BASE/franchisor/landing-string", ['lang' => 'pl', 'k' => 'test.key', 'v' => 'x']);
ok('write without token → 401', $c4 === 401, $c4);

[$c5, $r5] = http('POST', "$BASE/franchisor/landing-string", ['lang' => 'pl', 'k' => 'test.key', 'v' => 'Wartość testowa'], $TOKEN);
ok('landing-string upsert with token → 200', $c5 === 200 && !empty($r5['ok']), $c5);

[, $d6] = http('GET', "$BASE/landing/content?lang=pl");
ok('upserted key visible on the public endpoint', ($d6['strings']['test.key'] ?? '') === 'Wartość testowa', $d6['strings']['test.key'] ?? null);

[$c7] = http('POST', "$BASE/franchisor/landing-string", ['lang' => 'pl', 'k' => 'test.key', 'v' => null], $TOKEN);
[, $d8] = http('GET', "$BASE/landing/content?lang=pl");
ok('delete (v null) removes the key', $c7 === 200 && !isset($d8['strings']['test.key']));

echo "\n" . ($fail === 0 ? "ALL GREEN: $pass passed, 0 failed" : "FAILURES: $pass passed, $fail FAILED") . "\n";
exit($fail === 0 ? 0 : 1);
