<?php
// ============================================================================
//  e2e_centrala.php — la table centrale des clés (BuddyCorp) et l'entrée du
//  superadmin (code du jour + SMS + 12 h).
//
//   1. chiffrement : aller-retour, ligne déplacée / renommée / altérée refusée
//   2. cloisonnement : une boutique lit SES clés, pas celles d'une autre
//   3. la boutique : ses clés d'intégration viennent de la centrale, une copie
//      locale est ignorée, l'écran ne les écrit plus
//   4. l'entrée : code du jour JJMM+N, numéro hors liste muet, SMS jamais
//      journalisé, freins, session de 12 h
//
//  Usage :  php tests/e2e_centrala.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/settings.php';
require_once dirname(__DIR__) . '/siege_auth.php';
$pdo = wsm_bootstrap();

echo "webshop — end-to-end centrala (klucze BuddyCorp + wejście superadmina)\n\n";

$tmp = sys_get_temp_dir() . '/wsb-test-' . getmypid() . '.sqlite';
@unlink($tmp);
register_shutdown_function(fn() => @unlink($tmp));
$master = bin2hex(random_bytes(32));
$c = new PDO('sqlite:' . $tmp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
wsb_pdo($c);
wsb_schema($c);

// Le siège : la clé maîtresse.
wsm_config_overlay(['centrala' => ['engine' => 'sqlite', 'sqlite_path' => $tmp, 'boutique' => '', 'master' => $master, 'klucz' => ''],
                    'siege' => ['telefony' => '+48 600 100 200, +32470111222', 'boutique' => 'buddycorp']]);

// ---- 1. Chiffrement ----------------------------------------------------------------------------
echo "-- szyfrowanie --\n";
$kA = wsb_cle_boutique('mycobot'); $kB = wsb_cle_boutique('szoko');
ok('une clé par boutique, dérivée de la maîtresse', strlen($kA) === 32 && $kA !== $kB && $kA === hex2bin(wsb_cle_hex($master, 'mycobot')));
$ct = wsb_chiffrer($kA, 'mycobot', 'stripe.secret_key', 'sk_test_123');
ok('aller-retour', wsb_dechiffrer($kA, 'mycobot', 'stripe.secret_key', $ct) === 'sk_test_123');
ok('la valeur stockée n\'est pas en clair', !str_contains($ct, 'sk_test_123') && str_starts_with($ct, 'v1:'));
ok('déplacée dans une autre boutique : refusée', wsb_dechiffrer($kA, 'szoko', 'stripe.secret_key', $ct) === null);
ok('renommée : refusée', wsb_dechiffrer($kA, 'mycobot', 'stripe.publishable_key', $ct) === null);
ok('avec la clé d\'une autre boutique : refusée', wsb_dechiffrer($kB, 'mycobot', 'stripe.secret_key', $ct) === null);
$alt = 'v1:' . base64_encode(substr_replace(base64_decode(substr($ct, 3)), 'X', -1, 1));
ok('altérée : refusée', wsb_dechiffrer($kA, 'mycobot', 'stripe.secret_key', $alt) === null);

// ---- 2. Écriture au siège, lecture cloisonnée ---------------------------------------------------
echo "-- zapis w siedzibie, odczyt w sklepie --\n";
wsb_boutique_enregistrer($c, 'mycobot', 'MyCobotBuddy', 'mycobotbuddy.pl');
wsb_zapisz($c, 'mycobot', 'stripe.secret_key', 'sk_test_MCB', '+48 ••• ••200');
wsb_zapisz($c, 'mycobot', 'stripe.publishable_key', 'pk_test_MCB', '+48 ••• ••200');
wsb_zapisz($c, 'mycobot', 'inpost.sandbox', '1', '+48 ••• ••200');
wsb_zapisz($c, 'szoko', 'stripe.secret_key', 'sk_test_SZOKO', '+48 ••• ••200');
ok('une ligne par clé, chiffrée', (int) $c->query("SELECT COUNT(*) FROM wsb_integracje")->fetchColumn() === 4
   && !$c->query("SELECT 1 FROM wsb_integracje WHERE val LIKE '%sk_test%'")->fetchColumn());
$j = $c->query("SELECT akcja, szczegoly FROM wsb_dziennik ORDER BY id DESC LIMIT 1")->fetch();
ok('le journal garde le NOM, pas la valeur', $j['szczegoly'] === 'stripe.secret_key' && !str_contains(json_encode($c->query("SELECT * FROM wsb_dziennik")->fetchAll()), 'sk_test'));
ok('le siège lit toutes les boutiques', (wsb_klucze('szoko', true)['stripe.secret_key'] ?? '') === 'sk_test_SZOKO');

// La boutique mycobot : SA clé seulement, plus de maîtresse.
wsm_config_overlay(['centrala' => ['boutique' => 'mycobot', 'master' => '', 'klucz' => wsb_cle_hex($master, 'mycobot')]]);
ok('la boutique n\'est pas le siège', !wsb_est_siege() && wsb_actif());
$k = wsb_klucze(null, true);
ok('elle lit ses clés', ($k['stripe.secret_key'] ?? '') === 'sk_test_MCB' && ($k['inpost.sandbox'] ?? '') === '1');
ok('elle ne lit pas celles de szoko', wsb_klucze('szoko', true) === [] && wsb_cle_boutique('szoko') === '');
$threw = false; try { wsb_zapisz($c, 'mycobot', 'stripe.secret_key', 'x', 'pirate'); } catch (Throwable $e) { $threw = true; }
ok('elle n\'écrit rien', $threw);
// Une ligne recopiée d'une autre boutique ne passe pas.
$c->exec("INSERT INTO wsb_integracje (boutique, cle, val) SELECT 'mycobot', 'meta.app_secret', val FROM wsb_integracje WHERE boutique='szoko' AND cle='stripe.secret_key'");
ok('une valeur recopiée d\'ailleurs est ignorée', !isset(wsb_klucze(null, true)['meta.app_secret']));

// ---- 3. La boutique : réglages ------------------------------------------------------------------
echo "-- ustawienia sklepu --\n";
$pdo->prepare("DELETE FROM wsm_settings WHERE cle IN ('stripe.secret_key','stripe.publishable_key')")->execute();
$pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES ('stripe.publishable_key','pk_LOCAL_ancien',0,'','test')")->execute();
register_shutdown_function(function () use ($pdo) { try { $pdo->exec("DELETE FROM wsm_settings WHERE cle IN ('stripe.publishable_key','stripe.secret_key')"); } catch (Throwable $e) {} });
$vals = wsm_settings_all($pdo);
ok('la clé vient de la centrale', ($vals['stripe.secret_key'] ?? '') === 'sk_test_MCB');
ok('la copie locale est ignorée', ($vals['stripe.publishable_key'] ?? '') === 'pk_test_MCB');
ok('une clé absente au centre reste absente (pas de repli local)', !isset($vals['meta.app_secret']));
$refus = [];
wsm_settings_save($pdo, ['stripe__secret_key' => 'sk_live_PIRATE'], 'test', $refus);
ok('l\'écran de la boutique refuse d\'écrire une clé centrale', isset($refus['stripe.secret_key'])
   && !$pdo->query("SELECT 1 FROM wsm_settings WHERE val = 'sk_live_PIRATE'")->fetchColumn());
$v = wsm_settings_view($pdo);
ok('l\'écran la montre verrouillée, source « centrala »', ($v['stripe.secret_key']['locked'] ?? false) === true && ($v['stripe.secret_key']['source'] ?? '') === 'centrala');

// ---- 4. L'entrée du superadmin -----------------------------------------------------------------
echo "-- wejście superadmina --\n";
wsm_config_overlay(['centrala' => ['boutique' => '', 'master' => $master, 'klucz' => '']]);
ok('siège actif (maîtresse + téléphones)', wsb_siege_actif() && wsb_telefony() === ['+48600100200', '+32470111222']);
$ven13 = mktime(12, 0, 0, 11, 13, 2026);      // vendredi 13 novembre 2026
ok('code du jour : vendredi 13/11 → 13115', wsb_kod_dnia($ven13) === '13115');
ok('lundi 2 mars → 02031', wsb_kod_dnia(mktime(9, 0, 0, 3, 2, 2026)) === '02031');
ok('dimanche → 7', substr(wsb_kod_dnia(mktime(9, 0, 0, 11, 15, 2026)), -1) === '7');
ok('espaces tolérés, veille refusée', wsb_kod_ok('13 11 5', $ven13) && !wsb_kod_ok('12114', $ven13));

// La clé SMSAPI du siège, dans la centrale.
wsb_zapisz($c, 'buddycorp', 'smsapi.token', 'TOKEN-SIEGE', 'amorçage');
wsb_zapisz($c, 'buddycorp', 'smsapi.nadawca', 'BuddyCorp', 'amorçage');
$appels = [];
wsm_sms_transport(function ($m, $path, $params) use (&$appels) {
    $appels[] = [$path, $params];
    if ($path === '/mfa/codes') return [201, ['id' => 'x', 'code' => '987654', 'phone_number' => $params['phone_number']], 'test'];
    return [($params['code'] ?? '') === '987654' ? 204 : 404, null, 'test'];
});
$_SESSION = [];
$kod = wsb_kod_dnia();
ok('code du jour faux : pas de SMS', wsb_etap1($c, '+48600100200', '0000', '10.0.0.1') === 'kod' && $appels === []);
ok('numéro hors liste : même réponse, aucun SMS', wsb_etap1($c, '+48999999999', $kod, '10.0.0.2') === 'ok' && $appels === [] && !isset($_SESSION['wsb_etap']));
ok('numéro autorisé : SMS par SMSAPI Authenticator', wsb_etap1($c, '0048 600 100 200', $kod, '10.0.0.3') === 'ok'
   && ($appels[0][0] ?? '') === '/mfa/codes' && ($appels[0][1]['phone_number'] ?? '') === '48600100200'
   && str_contains($appels[0][1]['content'] ?? '', '[%code%]') && ($appels[0][1]['from'] ?? '') === 'BuddyCorp');
ok('avec le jeton du siège, lu dans la centrale', (wsm_sms_cfg()['token'] ?? '') === 'TOKEN-SIEGE');
ok('le code SMS n\'est écrit nulle part', !str_contains(json_encode($c->query("SELECT * FROM wsb_dziennik")->fetchAll()) . json_encode($_SESSION), '987654'));
ok('mauvais code SMS', wsb_etap2($c, '111111', '10.0.0.3') === 'zly' && isset($_SESSION['wsb_etap']));
ok('bon code SMS : session ouverte', wsb_etap2($c, '987 654', '10.0.0.3') === 'ok' && !isset($_SESSION['wsb_etap']));
$s = $c->query("SELECT * FROM wsb_sesje")->fetch();
ok('12 heures, empreinte seulement', $s && abs((int) $s['wygasa'] - time() - 12 * 3600) < 5 && strlen($s['id']) === 64);
$jeton = wsb_sesja_otworz($c, '+48600100200', '10.0.0.3');
ok('le jeton ouvre la session', wsb_sesja($c, $jeton) === '+48600100200');
ok('un jeton inventé non', wsb_sesja($c, str_repeat('a', 64)) === null && wsb_sesja($c, 'x') === null);
$c->prepare("UPDATE wsb_sesje SET wygasa = ? WHERE id = ?")->execute([time() - 1, hash('sha256', $jeton)]);
ok('après 12 h : fermée', wsb_sesja($c, $jeton) === null);
$j2 = wsb_sesja_otworz($c, '+48600100200', '10.0.0.3'); wsb_sesja_zamknij($c, $j2);
ok('déconnexion : fermée', wsb_sesja($c, $j2) === null);
ok('étape 2 sans étape 1 : refusée', wsb_etap2($c, '987654', '10.0.0.3') === 'brak');

// Freins
for ($i = 0; $i < 5; $i++) wsb_etap1($c, '+48600100200', '0', '10.9.9.9');
ok('5 codes du jour faux → pause (même avec le bon)', wsb_etap1($c, '+48600100200', $kod, '10.9.9.9') === 'limit');
$c->exec("DELETE FROM wsb_proby");
$n0 = count($appels);
for ($i = 0; $i < 3; $i++) wsb_etap1($c, '+32470111222', $kod, '10.1.1.' . $i);
ok('3 SMS par numéro et par quart d\'heure', wsb_etap1($c, '+32470111222', $kod, '10.1.1.9') === 'limit' && count($appels) - $n0 === 3);
for ($i = 0; $i < 5; $i++) wsb_etap2($c, '000000', '10.1.1.9');
ok('5 codes SMS faux → l\'étape est annulée', wsb_etap2($c, '987654', '10.1.1.9') !== 'ok');

// La page : 404 hors siège.
$siege = (string) file_get_contents(dirname(__DIR__, 3) . '/siege/index.php');
ok('la page répond 404 sans siège configuré', str_contains($siege, "if (!wsb_siege_actif()) { http_response_code(404); exit; }"));
ok('la page ne réaffiche jamais un secret', str_contains($siege, "type=\"password\"") && !preg_match('/value="<\?= \$h\(\$wart/', $siege));

echo "\n" . ($fail ? "ÉCHEC" : "OK") . " — $pass réussis, $fail en échec\n";
exit($fail ? 1 : 0);
