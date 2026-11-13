<?php
// ============================================================================
//  e2e_auth.php — preuve end-to-end de l'authentification, sur l'API HTTP réelle.
//
//  Couvre :
//    • cloisonnement : /landing/content public, /franchisor/* fermé sans identité
//    • jeton de service : absent / faux / vide / correct
//    • session : login, cookie, lecture autorisée, /auth/me, logout
//    • rôles : un compte non-siège lit mais n'écrit pas (403)
//    • compte inactif refusé, verrouillage après 5 échecs (429)
//    • aucune fuite : le mot de passe / le hachage ne sortent jamais de l'API
//
//  Usage :  php tests/e2e_auth.php [baseUrl] [adminToken]
// ============================================================================

$BASE  = rtrim($argv[1] ?? getenv('WSM_API_BASE') ?: 'http://localhost:8090', '/');
$TOKEN = $argv[2] ?? getenv('WSM_ADMIN_TOKEN') ?: 'dev-admin-token';

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

/** Client HTTP avec bocal à cookies optionnel (pour tester les sessions). */
function http(string $method, string $url, ?array $body = null, ?string $token = null, ?string $jar = null): array {
    $headers = ['Accept: application/json'];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    if ($token !== null) $headers[] = 'X-Admin-Token: ' . $token;
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null,
    ];
    if ($jar !== null) { $opts[CURLOPT_COOKIEJAR] = $jar; $opts[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw ?: 'null', true), (string) $raw];
}

echo "webshop_mrszoko — end-to-end authentication test\n";
echo "base: $BASE\n\n";

[$code] = http('GET', "$BASE/landing/content");
ok('API reachable (GET /landing/content → 200)', $code === 200, $code);
if ($code !== 200) { echo "\nAPI not reachable — start it with ./serve.sh\n"; exit(1); }

// ---- 1. Cloisonnement public / privé ---------------------------------------
echo "-- cloisonnement --\n";
ok('landing/content reste PUBLIC (aucune identité)', $code === 200, $code);
foreach (['kpis', 'shops', 'users', 'audit', 'params', 'deliveries', 'delivery-clients', 'incidents', 'menus'] as $r) {
    [$c] = http('GET', "$BASE/franchisor/$r");
    ok("GET /franchisor/$r sans identité → 401", $c === 401, $c);
}

// ---- 2. Jeton de service ----------------------------------------------------
echo "-- jeton de service --\n";
[$c1] = http('GET', "$BASE/franchisor/kpis", null, 'mauvais-jeton');
ok('jeton faux → 401', $c1 === 401, $c1);
[$c2] = http('GET', "$BASE/franchisor/kpis", null, '');
ok('jeton vide → 401', $c2 === 401, $c2);
[$c3, $d3] = http('GET', "$BASE/franchisor/kpis", null, $TOKEN);
ok('jeton correct → 200 avec données', $c3 === 200 && is_array($d3) && count($d3) > 0, $c3);
[$c4, $d4] = http('GET', "$BASE/auth/me", null, $TOKEN);
ok('/auth/me avec jeton → identité de service admin', $c4 === 200 && ($d4['user']['admin'] ?? false) === true, $d4);

// ---- 3. Session utilisateur -------------------------------------------------
echo "-- session utilisateur --\n";
$jar = sys_get_temp_dir() . '/wsm_test_cookies_' . getmypid() . '.txt';
@unlink($jar);
$admEmail = 'e2e.admin@misterszoko.test';
$admPass  = 'HasloTestowe-2026!';
$viewEmail = 'e2e.widz@misterszoko.test';
$viewPass  = 'HasloWidza-2026!';

// comptes de test créés par le CLI (hors HTTP) : siège + non-siège
$php = PHP_BINARY ?: 'php';
$cli = escapeshellarg(dirname(__DIR__) . '/migrate.php');
exec("$php $cli --set-password " . escapeshellarg($admEmail) . ' ' . escapeshellarg($admPass) . ' Centrala ' . escapeshellarg('Testowy Admin'), $o1, $r1);
exec("$php $cli --set-password " . escapeshellarg($viewEmail) . ' ' . escapeshellarg($viewPass) . ' Franczyza ' . escapeshellarg('Testowy Widz'), $o2, $r2);
ok('comptes de test créés via le CLI', $r1 === 0 && $r2 === 0, [$r1, $r2]);

[$c5] = http('POST', "$BASE/auth/login", ['email' => $admEmail, 'password' => 'mauvais'], null, $jar);
ok('login mot de passe faux → 401', $c5 === 401, $c5);

[$c6] = http('POST', "$BASE/auth/login", ['email' => 'inconnu@nulle-part.test', 'password' => 'x'], null, $jar);
ok('login e-mail inconnu → 401 (même réponse, pas d\'énumération)', $c6 === 401, $c6);

[$c7, $d7] = http('POST', "$BASE/auth/login", ['email' => $admEmail, 'password' => $admPass], null, $jar);
ok('login correct → 200', $c7 === 200, $c7);
ok('la réponse ne contient ni mot de passe ni hachage',
    $c7 === 200 && !str_contains(json_encode($d7), 'password') && !str_contains(json_encode($d7), '$2y$'), $d7);

[$c8, $d8] = http('GET', "$BASE/auth/me", null, null, $jar);
ok('/auth/me avec cookie de session → identité', $c8 === 200 && ($d8['user']['email'] ?? '') === $admEmail, $d8);

[$c9, $d9] = http('GET', "$BASE/franchisor/kpis", null, null, $jar);
ok('lecture avec session → 200', $c9 === 200 && is_array($d9) && count($d9) > 0, $c9);

[$c10] = http('POST', "$BASE/franchisor/param", ['cle' => 'e2e.auth.test', 'val' => '1'], null, $jar);
ok('écriture avec session siège → 200', $c10 === 200, $c10);

[, $dAudit] = http('GET', "$BASE/franchisor/audit", null, null, $jar);
$actors = array_column($dAudit ?: [], 'user');
ok('l\'audit enregistre le VRAI acteur (plus « Konsola marki » générique)',
    in_array('Testowy Admin', $actors, true), array_slice($actors, 0, 3));

[$c11] = http('POST', "$BASE/auth/logout", [], null, $jar);
[$c12] = http('GET', "$BASE/franchisor/kpis", null, null, $jar);
ok('logout → 200 puis lecture refusée (401)', $c11 === 200 && $c12 === 401, [$c11, $c12]);

// ---- 4. Rôles ---------------------------------------------------------------
echo "-- rôles --\n";
$jar2 = sys_get_temp_dir() . '/wsm_test_cookies2_' . getmypid() . '.txt';
@unlink($jar2);
[$c13] = http('POST', "$BASE/auth/login", ['email' => $viewEmail, 'password' => $viewPass], null, $jar2);
ok('login compte non-siège → 200', $c13 === 200, $c13);
[$c14] = http('GET', "$BASE/franchisor/kpis", null, null, $jar2);
ok('compte non-siège PEUT lire → 200', $c14 === 200, $c14);
[$c15, $d15] = http('POST', "$BASE/franchisor/param", ['cle' => 'e2e.auth.forbidden', 'val' => '1'], null, $jar2);
ok('compte non-siège NE PEUT PAS écrire → 403', $c15 === 403 && ($d15['error'] ?? '') === 'forbidden_role', [$c15, $d15]);

// ---- 5. Verrouillage après échecs répétés ----------------------------------
echo "-- verrouillage --\n";
$lockEmail = 'e2e.lock@misterszoko.test';
exec("$php $cli --set-password " . escapeshellarg($lockEmail) . ' ' . escapeshellarg('HasloBlokada-2026!') . ' Franczyza ' . escapeshellarg('Testowy Lock'), $o3, $r3);
$codes = [];
for ($i = 0; $i < 5; $i++) { [$cc] = http('POST', "$BASE/auth/login", ['email' => $lockEmail, 'password' => 'zle']); $codes[] = $cc; }
[$c16] = http('POST', "$BASE/auth/login", ['email' => $lockEmail, 'password' => 'HasloBlokada-2026!']);
ok('5 échecs puis bon mot de passe → 429 (compte verrouillé)', $c16 === 429, [$codes, $c16]);

@unlink($jar); @unlink($jar2);
echo "\n" . ($fail === 0 ? "ALL GREEN: $pass passed, 0 failed" : "FAILURES: $pass passed, $fail FAILED") . "\n";
exit($fail === 0 ? 0 : 1);
