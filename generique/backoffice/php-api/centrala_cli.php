<?php
// ============================================================================
//  centrala_cli.php — la centrale BuddyCorp en ligne de commande, pour wsm.
//  Lancé avec la configuration du SIÈGE (WSM_CONFIG_FILE) : lui seul a la
//  clé maîtresse.
//
//    php centrala_cli.php schema                         crée les tables
//    php centrala_cli.php enregistrer <slug> <nom> <domaine>
//    php centrala_cli.php cle <slug>                     clé de la boutique (hex), pour SA config
//    php centrala_cli.php zapisz <slug> <cle>            valeur lue sur l'entrée standard
//                                                        (jamais en argument : visible dans ps)
// ============================================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/centrala.php';

function sortir(string $m, int $code = 1): never { fwrite(STDERR, $m . "\n"); exit($code); }

if (!wsb_est_siege()) sortir('centrala_cli : pas de clé maîtresse dans cette configuration (siège seulement)');
$pdo = wsb_pdo();
if (!$pdo) sortir('centrala_cli : base centrale injoignable');

$cmd = $argv[1] ?? '';
switch ($cmd) {
    case 'schema':
        wsb_schema($pdo);
        echo "ok\n";
        break;
    case 'enregistrer':
        wsb_boutique_enregistrer($pdo, (string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''), (string) ($argv[4] ?? ''));
        wsb_dziennik($pdo, 'wsm', (string) $argv[2], 'sklep zarejestrowany');
        echo "ok\n";
        break;
    case 'cle':
        $b = (string) ($argv[2] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $b)) sortir('slug invalide');
        echo bin2hex(wsb_cle_boutique($b)), "\n";
        break;
    case 'zapisz':
        $v = rtrim((string) stream_get_contents(STDIN), "\r\n");
        wsb_zapisz($pdo, (string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''), $v, 'wsm');
        echo "ok\n";
        break;
    default:
        sortir('usage : centrala_cli.php schema | enregistrer <slug> <nom> <domaine> | cle <slug> | zapisz <slug> <cle> (< valeur)', 2);
}
