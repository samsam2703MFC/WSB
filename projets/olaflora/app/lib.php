<?php
// Ola flora — accès à la base du projet et petits formats polonais partagés par
// les points d'entrée (zapytanie.php public, prive/dane.php).
declare(strict_types=1);
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

const OF_TENANT = 1;   // une seule pracownia pour l'instant ; la structure en accepte plusieurs

function of_pdo(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $f = (string) (getenv('PROJET_CONFIG') ?: ($_SERVER['PROJET_CONFIG'] ?? ''));
    $c = $f !== '' && is_file($f) ? (include $f) : null;
    if (!is_array($c)) { http_response_code(500); exit('{"error":"config"}'); }
    $pdo = new PDO((string) $c['dsn'], (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $pdo;
}

function of_json($v, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($v, JSON_UNESCAPED_UNICODE);
}

/** Le numéro suivant d'une série (ZAP-2026-104), sans doublon (FOR UPDATE). */
function of_numer(PDO $pdo, string $prefix): string {
    $rok = (int) date('Y');
    $pdo->prepare("INSERT IGNORE INTO of_number_sequences (tenant_id, prefix, year, last_no) VALUES (?,?,?,0)")->execute([OF_TENANT, $prefix, $rok]);
    $st = $pdo->prepare("SELECT id, last_no FROM of_number_sequences WHERE tenant_id = ? AND prefix = ? AND year = ? FOR UPDATE");
    $st->execute([OF_TENANT, $prefix, $rok]);
    $r = $st->fetch();
    $n = (int) $r['last_no'] + 1;
    $pdo->prepare("UPDATE of_number_sequences SET last_no = ? WHERE id = ?")->execute([$n, $r['id']]);
    return sprintf('%s-%d-%03d', $prefix, $rok, $n);
}

const OF_MIES = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
const OF_DNI  = ['niedz.', 'pon.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.'];

/** « sob. 13 czerwca 2026 » */
function of_data_pl(?string $iso): string {
    if (!$iso || !($t = strtotime($iso))) return '';
    return OF_DNI[(int) date('w', $t)] . ' ' . (int) date('j', $t) . ' ' . OF_MIES[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
}
/** « 24 180 zł » (grosze → złote, espace fine des milliers) */
function of_zl(?int $gr): string {
    return $gr === null ? '' : number_format(intdiv($gr, 100), 0, ',', ' ') . ' zł';
}
/** « 2 dni temu » */
function of_temu(?string $kiedy): string {
    if (!$kiedy || !($t = strtotime($kiedy))) return '';
    $d = (int) floor((time() - $t) / 86400);
    if ($d <= 0) return 'dziś';
    if ($d === 1) return 'wczoraj';
    return $d . ' dni temu';
}
