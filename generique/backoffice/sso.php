<?php
// ============================================================================
//  sso.php — l'entrée DIRECTE du superadmin BuddyCorp dans cette console.
//
//  Le site BuddyCorp (siege/) signe un lien d'une minute avec la clé de cette
//  boutique ; on le vérifie ici (centrala.php, wsb_sso_verifier), on le
//  « consomme » (un lien ne sert qu'une fois), puis on ouvre la session du
//  compte « BuddyCorp — superadmin » de la boutique (créé au premier passage,
//  sans mot de passe : il n'entre QUE par ce chemin) et on va au pulpit.
//
//  Le compte peut être désactivé par la boutique (Użytkownicy) : le lien est
//  alors refusé — c'est son choix, et on le dit.
// ============================================================================
declare(strict_types=1);

$api = is_dir(__DIR__ . '/api') ? __DIR__ . '/api' : __DIR__ . '/php-api';
require_once $api . '/db.php';
require_once $api . '/auth.php';
require_once $api . '/delivery.php';      // wsm_audit()
require_once $api . '/centrala.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

const WSB_SSO_EMAIL = 'buddycorp@siege.local';

function sso_refus(string $powod): never {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $h = htmlspecialchars($powod, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="pl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>BuddyCorp — wejście</title><body style="font:16px/1.5 system-ui,sans-serif;max-width:560px;margin:12vh auto;padding:0 16px;color:#14161a">'
       . '<h1 style="font-size:1.3rem">Nie udało się wejść do konsoli</h1>'
       . '<p>Powód: ' . $h . '.</p><p>Wróć do panelu BuddyCorp i kliknij jeszcze raz „Konsola”. '
       . 'Link jest ważny jedną minutę i działa tylko raz.</p><p><a href="login.php">Logowanie do konsoli</a></p></body></html>';
    exit;
}

$v = wsb_sso_verifier((string) ($_GET['t'] ?? ''));
if (is_string($v)) sso_refus($v);

$pdo = wsm_bootstrap();

// Un lien ne sert qu'une fois.
$pdo->exec("CREATE TABLE IF NOT EXISTS wsm_sso_nonces (n VARCHAR(64) NOT NULL PRIMARY KEY, exp INTEGER NOT NULL)");
$pdo->prepare("DELETE FROM wsm_sso_nonces WHERE exp < ?")->execute([time() - 3600]);
try {
    $pdo->prepare("INSERT INTO wsm_sso_nonces (n, exp) VALUES (?, ?)")->execute([$v['n'], $v['exp']]);
} catch (Throwable $e) {
    sso_refus('ten link został już użyty');
}

// Le compte du superadmin dans CETTE boutique.
$st = $pdo->prepare("SELECT id, act, role FROM wsm_users WHERE LOWER(email) = ?");
$st->execute([WSB_SSO_EMAIL]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) {
    $pdo->prepare("INSERT INTO wsm_users (nom, email, role, portee, act) VALUES (?, ?, ?, '', 1)")
        ->execute(['BuddyCorp — superadmin', WSB_SSO_EMAIL, WSM_ROLE_SUPERADMIN]);
    $st->execute([WSB_SSO_EMAIL]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$u) sso_refus('nie można utworzyć konta BuddyCorp');
if (!(int) $u['act']) sso_refus('konto BuddyCorp zostało wyłączone w tej konsoli (Użytkownicy)');
if ((string) $u['role'] !== WSM_ROLE_SUPERADMIN) {
    $pdo->prepare("UPDATE wsm_users SET role = ? WHERE id = ?")->execute([WSM_ROLE_SUPERADMIN, $u['id']]);
}
$pdo->prepare("UPDATE wsm_users SET last_login = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $u['id']]);

wsm_session_start();
session_regenerate_id(true);
$_SESSION['uid']  = (int) $u['id'];
$_SESSION['seen'] = time();
wsm_audit($pdo, 'BuddyCorp — superadmin', 'Logowanie z panelu BuddyCorp', 'wsm_users ' . WSB_SSO_EMAIL . ($v['k'] !== '' ? ' (' . $v['k'] . ')' : ''), 'Sieć');

header('Location: pulpit.php', true, 303);
