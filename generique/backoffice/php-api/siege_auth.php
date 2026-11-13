<?php
// ============================================================================
//  siege_auth.php — l'entrée du superadmin BuddyCorp (site siege/).
//
//  DEUX ÉTAPES, PUIS 12 HEURES :
//    1. le numéro de téléphone + le CODE DU JOUR : JJMM suivi du jour de la
//       semaine (lundi = 1 … dimanche = 7). Vendredi 13 novembre → 13115.
//    2. un code aléatoire envoyé par SMS (SMSAPI « SMS Authenticator » : il
//       génère, envoie, garde 180 s et vérifie — nous ne voyons jamais le
//       code), au numéro donné, s'il figure dans la liste du serveur.
//    → un cookie de session de 12 heures (HttpOnly, Secure, SameSite=Strict).
//
//  LE CODE DU JOUR N'EST PAS UN SECRET : il se calcule depuis la date. C'est
//  un filtre contre les envois de SMS au hasard, pas une serrure. La serrure,
//  c'est le téléphone : sans lui, pas de second code.
//
//  Un numéro absent de la liste reçoit EXACTEMENT la même réponse qu'un
//  numéro présent (« si ce numéro est autorisé, un SMS est parti ») : la page
//  ne dit pas qui est superadmin.
//
//  CONFIGURATION (fichier serveur du siège) :
//    'siege' => ['telefony' => '+48600000000, +32470000000', 'boutique' => 'buddycorp']
//  Les clés SMSAPI du siège sont dans la table centrale, sous sa boutique.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/centrala.php';
require_once __DIR__ . '/smsapi.php';

const WSB_SESJA_SEKUNDY = 12 * 3600;
const WSB_COOKIE        = 'wsb_siege';
const WSB_ETAP_SEKUNDY  = 300;            // entre l'étape 1 et le code SMS
// Freins : [essais, fenêtre en secondes]
const WSB_LIMIT_KOD     = [5, 900];       // code du jour, par adresse IP
const WSB_LIMIT_SMS     = [3, 900];       // SMS envoyés, par numéro
const WSB_LIMIT_SPRAWDZ = [5, 900];       // codes SMS essayés, par numéro

function wsb_siege_cfg(): array {
    $c = wsm_config()['siege'] ?? null;
    return is_array($c) ? $c : [];
}

/** Le siège est-il configuré (clé maîtresse + au moins un téléphone) ? Sinon : 404. */
function wsb_siege_actif(): bool {
    return wsb_est_siege() && wsb_telefony() !== [];
}

/** « +48 600-000-000 » → « +48600000000 » ; '' si ce n'est pas un numéro. */
function wsb_tel(string $t): string {
    $d = preg_replace('/\D+/', '', $t) ?? '';
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    return (strlen($d) >= 9 && strlen($d) <= 15) ? '+' . $d : '';
}

/** @return list<string> les numéros autorisés, normalisés */
function wsb_telefony(): array {
    $raw = (string) (wsb_siege_cfg()['telefony'] ?? '');
    $out = [];
    foreach (preg_split('/[,;\n]+/', $raw) ?: [] as $p) {
        $n = wsb_tel($p);
        if ($n !== '') $out[] = $n;
    }
    return array_values(array_unique($out));
}

function wsb_tel_maska(string $e164): string {
    return strlen($e164) < 7 ? '•••' : substr($e164, 0, 4) . ' ••• ••' . substr($e164, -3);
}

/** Le code du jour : JJMM + jour de la semaine ISO. */
function wsb_kod_dnia(?int $quand = null): string {
    $t = $quand ?? time();
    return date('dm', $t) . date('N', $t);
}

function wsb_kod_ok(string $saisi, ?int $quand = null): bool {
    return hash_equals(wsb_kod_dnia($quand), preg_replace('/\D+/', '', $saisi) ?? '');
}

/** Le frein : true si encore permis (et l'essai est compté). */
function wsb_hamulec(PDO $pdo, string $rodzaj, string $klucz, array $limit, bool $compter = true): bool {
    [$max, $okno] = $limit;
    $now = time();
    $pdo->prepare("DELETE FROM wsb_proby WHERE kiedy < ?")->execute([$now - 86400]);
    $st = $pdo->prepare("SELECT COUNT(*) FROM wsb_proby WHERE rodzaj = ? AND klucz = ? AND kiedy > ?");
    $st->execute([$rodzaj, $klucz, $now - $okno]);
    if ((int) $st->fetchColumn() >= $max) return false;
    if ($compter) $pdo->prepare("INSERT INTO wsb_proby (rodzaj, klucz, kiedy) VALUES (?,?,?)")->execute([$rodzaj, $klucz, $now]);
    return true;
}

/** Les réglages SMSAPI du siège, lus dans la table centrale et posés en configuration. */
function wsb_siege_sms_config(): void {
    $b = (string) (wsb_siege_cfg()['boutique'] ?? 'buddycorp');
    $k = wsb_klucze($b);
    $patch = [];
    foreach (['token', 'nadawca', 'region'] as $f) {
        if (($k['smsapi.' . $f] ?? '') !== '') $patch[$f] = $k['smsapi.' . $f];
    }
    if ($patch) wsm_config_overlay(['smsapi' => $patch]);
}

/**
 * Étape 1 → envoie le SMS. Rend 'ok' | 'kod' (code du jour faux) | 'limit' | 'sms'.
 * 'ok' AUSSI pour un numéro non autorisé (rien n'est envoyé) : voir l'en-tête.
 */
function wsb_etap1(PDO $pdo, string $tel, string $kod, string $ip): string {
    if (!wsb_hamulec($pdo, 'kod', $ip, WSB_LIMIT_KOD)) return 'limit';
    if (!wsb_kod_ok($kod)) return 'kod';
    $n = wsb_tel($tel);
    if ($n === '' || !in_array($n, wsb_telefony(), true)) {
        wsb_dziennik($pdo, $ip, '', 'wejście: numer spoza listy', wsb_tel_maska($n));
        return 'ok';
    }
    if (!wsb_hamulec($pdo, 'sms', $n, WSB_LIMIT_SMS)) return 'limit';
    wsb_siege_sms_config();
    if (!wsm_sms_cfg()['token']) { wsb_dziennik($pdo, $ip, '', 'wejście: brak tokenu SMSAPI'); return 'sms'; }
    $p = ['phone_number' => ltrim($n, '+'), 'content' => 'BuddyCorp - kod logowania: [%code%]', 'fast' => '1'];
    if (wsm_sms_cfg()['nadawca'] !== '') $p['from'] = wsm_sms_cfg()['nadawca'];
    [$code] = wsm_sms_call('POST', '/mfa/codes', $p);
    // La réponse contient le code en clair : elle n'est NI rendue NI journalisée.
    if ($code < 200 || $code >= 300) { wsb_dziennik($pdo, $ip, '', 'wejście: SMS nie wysłany', 'HTTP ' . $code); return 'sms'; }
    $_SESSION['wsb_etap'] = ['tel' => $n, 'do' => time() + WSB_ETAP_SEKUNDY];
    wsb_dziennik($pdo, wsb_tel_maska($n), '', 'wejście: SMS wysłany');
    return 'ok';
}

/** Étape 2 → 'ok' (session ouverte) | 'brak' (pas d'étape 1 valable) | 'zly' | 'stary' | 'limit' | 'sms'. */
function wsb_etap2(PDO $pdo, string $kod, string $ip): string {
    $e = $_SESSION['wsb_etap'] ?? null;
    if (!is_array($e) || ($e['do'] ?? 0) < time()) { unset($_SESSION['wsb_etap']); return 'brak'; }
    $n = (string) $e['tel'];
    if (!wsb_hamulec($pdo, 'sprawdz', $n, WSB_LIMIT_SPRAWDZ)) { unset($_SESSION['wsb_etap']); return 'limit'; }
    $kod = preg_replace('/\D+/', '', $kod) ?? '';
    if ($kod === '') return 'zly';
    wsb_siege_sms_config();
    [$code] = wsm_sms_call('POST', '/mfa/codes/verifications', ['phone_number' => ltrim($n, '+'), 'code' => $kod]);
    if ($code === 404) return 'zly';
    if ($code === 408) { unset($_SESSION['wsb_etap']); return 'stary'; }
    if ($code !== 204) return 'sms';
    unset($_SESSION['wsb_etap']);
    wsb_sesja_otworz($pdo, $n, $ip);
    wsb_dziennik($pdo, wsb_tel_maska($n), '', 'wejście: zalogowano (12 h)');
    return 'ok';
}

function wsb_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Ouvre la session de 12 h. Le navigateur garde le jeton ; la base, son empreinte. */
function wsb_sesja_otworz(PDO $pdo, string $tel, string $ip): string {
    $jeton = bin2hex(random_bytes(32));
    $pdo->prepare("DELETE FROM wsb_sesje WHERE wygasa < ?")->execute([time()]);
    $pdo->prepare("INSERT INTO wsb_sesje (id, telefon, wygasa, ip, utworzono) VALUES (?,?,?,?,?)")
        ->execute([hash('sha256', $jeton), $tel, time() + WSB_SESJA_SEKUNDY, $ip, date('Y-m-d H:i:s')]);
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        setcookie(WSB_COOKIE, $jeton, ['expires' => time() + WSB_SESJA_SEKUNDY, 'path' => '/',
            'secure' => wsb_https(), 'httponly' => true, 'samesite' => 'Strict']);
        session_regenerate_id(true);
    }
    return $jeton;
}

/** Le téléphone de la session en cours, ou null. */
function wsb_sesja(PDO $pdo, ?string $jeton = null): ?string {
    $j = $jeton ?? (string) ($_COOKIE[WSB_COOKIE] ?? '');
    if (!preg_match('/^[0-9a-f]{64}$/', $j)) return null;
    $st = $pdo->prepare("SELECT telefon, wygasa FROM wsb_sesje WHERE id = ?");
    $st->execute([hash('sha256', $j)]);
    $r = $st->fetch();
    if (!$r || (int) $r['wygasa'] < time()) return null;
    return (string) $r['telefon'];
}

function wsb_sesja_zamknij(PDO $pdo, ?string $jeton = null): void {
    $j = $jeton ?? (string) ($_COOKIE[WSB_COOKIE] ?? '');
    if ($j !== '') $pdo->prepare("DELETE FROM wsb_sesje WHERE id = ?")->execute([hash('sha256', $j)]);
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        setcookie(WSB_COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => wsb_https(), 'httponly' => true, 'samesite' => 'Strict']);
    }
}
