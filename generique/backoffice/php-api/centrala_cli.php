<?php
// ============================================================================
//  centrala_cli.php — la centrale BuddyCorp en ligne de commande, pour wsm.
//  Lancé avec la configuration du SIÈGE (WSM_CONFIG_FILE) : lui seul a la
//  clé maîtresse.
//
//    php centrala_cli.php schema                         crée les tables
//    php centrala_cli.php enregistrer <slug> <nom> <domaine>
//    php centrala_cli.php cle <slug>                     clé de la boutique (hex), pour SA config
//    php centrala_cli.php reprendre-cles <slug> [ancienne-config.php]
//                                                        clés d'une boutique déménagée → centrale
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
    case 'reprendre-cles':
        // Les clés d'intégration d'une boutique DÉMÉNAGÉE : celles de sa base
        // (wsm_settings) et celles de son ancien fichier de configuration
        // passent dans la table centrale — sinon, reliée à la centrale, la
        // boutique les ignorerait. Une clé déjà posée au siège n'est pas
        // remplacée. N'affiche que des NOMS.
        require_once __DIR__ . '/settings.php';
        require_once __DIR__ . '/siege_dane.php';
        $b = (string) ($argv[2] ?? '');
        $ancienne = (string) ($argv[3] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $b)) sortir('slug invalide');
        $shop = wsb_sklep_pdo(wsb_boutiques_dir() . '/' . $b . '/config.php');
        if (!$shop) sortir('base de la boutique injoignable');
        $base = [];
        try {
            foreach ($shop->query("SELECT cle, val FROM wsm_settings")->fetchAll() as $r) $base[(string) $r['cle']] = (string) $r['val'];
        } catch (Throwable $e) {}
        $fichier = $ancienne !== '' && is_file($ancienne) ? (@include $ancienne) : [];
        if (!is_array($fichier)) $fichier = [];
        $deja = wsb_klucze($b, true);
        $poses = []; $ignores = [];
        foreach (wsm_settings_klucze_fields() as $cle => $d) {
            if (isset($deja[$cle]) && $deja[$cle] !== '') continue;
            $v = $base[$cle] ?? '';
            if (wsm_setting_blank($v)) {
                $n = $fichier;
                foreach ((array) $d[2] as $k) { if (!is_array($n) || !array_key_exists($k, $n)) { $n = null; break; } $n = $n[$k]; }
                $v = is_bool($n) ? ($n ? '1' : '0') : (is_scalar($n) ? (string) $n : '');
            }
            if (wsm_setting_blank($v)) continue;
            if ($d[4] === 'pem' && !str_contains($v, '-----BEGIN')) { $ignores[] = $cle; continue; }   // un chemin de l'ancien serveur
            wsb_zapisz($pdo, $b, $cle, $v, 'déménagement');
            $poses[] = $cle;
        }
        echo 'clés reprises : ', count($poses), $poses ? ' (' . implode(', ', $poses) . ')' : '', "\n";
        if ($ignores) echo 'à reposer à la main (fichier de l’ancien serveur) : ', implode(', ', $ignores), "\n";
        break;
    case 'zapisz':
        $v = rtrim((string) stream_get_contents(STDIN), "\r\n");
        wsb_zapisz($pdo, (string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''), $v, 'wsm');
        echo "ok\n";
        break;
    default:
        sortir('usage : centrala_cli.php schema | enregistrer <slug> <nom> <domaine> | cle <slug> | zapisz <slug> <cle> (< valeur)', 2);
}
