<?php
// stockage_lib.php — la base MySQL d'un projet (wsm projet) : ce que ses pages
// rangeaient dans le navigateur. Configuration : PROJET_CONFIG (posée par
// Apache) → un fichier PHP qui rend ['dsn' => …, 'user' => …, 'pass' => …].
declare(strict_types=1);

function st_pdo(): PDO {
    $f = (string) (getenv('PROJET_CONFIG') ?: ($_SERVER['PROJET_CONFIG'] ?? ''));
    $c = $f !== '' && is_file($f) ? (include $f) : null;
    if (!is_array($c)) { http_response_code(500); exit('{"error":"config"}'); }
    $pdo = new PDO((string) $c['dsn'], (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $pdo->exec("CREATE TABLE IF NOT EXISTS of_store (k VARCHAR(190) NOT NULL PRIMARY KEY, v LONGTEXT NOT NULL, maj DATETIME NOT NULL)"
        . ($mysql ? " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" : ''));
    $pdo->exec("CREATE TABLE IF NOT EXISTS of_store_historia (id " . ($mysql ? "BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY" : "INTEGER PRIMARY KEY AUTOINCREMENT")
        . ", k VARCHAR(190) NOT NULL, v LONGTEXT NULL, maj DATETIME NOT NULL, kto VARCHAR(60) NOT NULL DEFAULT '')"
        . ($mysql ? " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" : ''));
    return $pdo;
}

/**
 * @param array $lire   préfixes de clés lisibles (vide = toutes)
 * @param array $ecrire préfixes de clés modifiables (vide = toutes)
 */
function st_servir(array $lire, array $ecrire, string $kto): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $ok = fn(string $k, array $pref): bool => !$pref || (bool) array_filter($pref, fn($p) => str_starts_with($k, $p));
    $pdo = st_pdo();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $cles = [];
        foreach ($pdo->query("SELECT k, v FROM of_store") as $r) if ($ok($r['k'], $lire)) $cles[$r['k']] = $r['v'];
        echo json_encode(['cles' => (object) $cles, 'gere' => $lire ?: ['olaflora-', 'bo-']], JSON_UNESCAPED_UNICODE);
        return;
    }
    $lot = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($lot) || count($lot) > 200) { http_response_code(400); echo '{"error":"lot"}'; return; }
    $maintenant = date('Y-m-d H:i:s'); $n = 0;
    foreach ($lot as $k => $v) {
        $k = (string) $k;
        if ($k === '' || strlen($k) > 190 || !$ok($k, $ecrire)) continue;
        if ($v !== null && (!is_string($v) || strlen($v) > 16 * 1024 * 1024)) continue;
        if ($v === null) $pdo->prepare("DELETE FROM of_store WHERE k = ?")->execute([$k]);
        else {
            $pdo->prepare("DELETE FROM of_store WHERE k = ?")->execute([$k]);
            $pdo->prepare("INSERT INTO of_store (k, v, maj) VALUES (?,?,?)")->execute([$k, $v, $maintenant]);
        }
        $pdo->prepare("INSERT INTO of_store_historia (k, v, maj, kto) VALUES (?,?,?,?)")->execute([$k, $v, $maintenant, $kto]);
        $n++;
    }
    echo json_encode(['ok' => $n]);
}
