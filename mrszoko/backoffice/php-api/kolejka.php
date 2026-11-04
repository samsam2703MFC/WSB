<?php
// ============================================================================
//  kolejka.php — la pompe de la file, lancée par la tâche planifiée.
//
//  Chaque minute (tâche planifiée du serveur) : quelques e-mails de
//  campagne et quelques SMS partent, jamais plus — la réputation du domaine
//  se perd en une minute et se regagne en un mois. Sans poste ni SMSAPI
//  configurés, rien ne bouge et rien n'est marqué en erreur.
//
//  LIGNE DE COMMANDE UNIQUEMENT. Ce dossier est servi par le serveur web :
//  appelé depuis un navigateur, le fichier répond 404 et ne fait rien.
//  Deux passages ne se chevauchent jamais (verrou de fichier).
//
//  Usage :  php kolejka.php [e-mails] [sms]
// ============================================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/marketing.php';

// Un verrou par utilisateur : l'essai à vide du déploiement tourne sous le
// compte SSH ; s'il laissait SON fichier dans /tmp, la tâche planifiée
// (www-data) ne pourrait plus l'ouvrir et se croirait bloquée pour toujours.
$uid = function_exists('posix_geteuid') ? (string) posix_geteuid() : get_current_user();
$verrou = @fopen(sys_get_temp_dir() . '/wsm-kolejka-' . md5(__DIR__) . '-' . $uid . '.lock', 'c');
if (!$verrou) { echo "kolejka: nie można utworzyć pliku blokady w " . sys_get_temp_dir() . "\n"; exit(1); }
if (!flock($verrou, LOCK_EX | LOCK_NB)) { echo "kolejka: poprzedni przebieg jeszcze trwa\n"; exit(0); }

$pdo = wsm_bootstrap();
$r = wsm_mkt_pump($pdo, max(0, (int) ($argv[1] ?? 30)), max(0, (int) ($argv[2] ?? 30)), 50.0);
echo 'kolejka: e-mail ' . $r['mail'] . ($r['mail_bledy'] ? ' (błędy ' . $r['mail_bledy'] . ')' : '')
   . ', sms ' . $r['sms'] . ($r['uwagi'] ? ' — ' . implode('; ', $r['uwagi']) : '') . "\n";

// LES POSTS PLANIFIÉS ET LES ACHATS POUR META. Chacun dans son try : une
// panne de Meta ne doit pas empêcher la newsletter de partir, ni l'inverse.
// L'adresse publique vient de shop_url (la tâche tourne hors requête : sans
// elle, Instagram ne saurait pas où chercher les photos).
try {
    require_once __DIR__ . '/social.php';
    require_once __DIR__ . '/shop.php';
    $url = (string) (wsm_config()['shop_url'] ?? '');
    if ($url !== '' && !preg_match('/^x{2,}$/i', $url)) {
        $s = wsm_social_pump($pdo, rtrim($url, '/'), 5);
        if ($s['opublikowane'] || $s['bledy']) echo 'kolejka: posty ' . $s['opublikowane'] . ($s['bledy'] ? ' (błędy ' . $s['bledy'] . ')' : '') . "\n";
    }
    // Les versions JPEG du flux produits, fabriquées ici plutôt qu'à la
    // première lecture publique du flux (25 au plus par passage).
    if ($url !== '' && !preg_match('/^x{2,}$/i', $url)) { require_once __DIR__ . '/mediateka.php'; wsm_feed_produkty($pdo, 'pl', rtrim($url, '/')); }
    $c = wsm_capi_pump($pdo, 20);
    if ($c['wyslane'] || $c['bledy']) echo 'kolejka: meta zakupy ' . $c['wyslane'] . ($c['bledy'] ? ' (błędy ' . $c['bledy'] . ')' : '') . "\n";
} catch (Throwable $e) {
    echo 'kolejka: posty/meta — ' . $e->getMessage() . "\n";
}
flock($verrou, LOCK_UN);
