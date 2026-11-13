<?php
// ============================================================================
//  e2e_haslo.php — le mot de passe de la console, posé PAR LE DÉPLOIEMENT.
//
//  CE QUI ÉTAIT CASSÉ, ET DEPUIS QUAND. Le déploiement appelait, sur le
//  serveur, `php migrate.php --set-password "$EMAIL" "$PASS"`. Ce serveur n'a
//  pas pdo_mysql en ligne de commande : l'appel levait « could not find
//  driver » avant la première requête. L'échec était rattrapé par un `if`, qui
//  écrivait « WSM_ADMIN_PASSWORD refusé », passait à la suite, et laissait le
//  déploiement vert. Autrement dit : le secret pouvait être posé, changé,
//  refait — RIEN n'atteignait jamais la base. C'est la panne de
//  `--sync-content`, sur un autre objet, découverte de la même façon.
//
//  LA VOIE QUI MARCHE : émettre le SQL. password_hash() n'a besoin d'aucune
//  base, le client mysql du serveur en a une. Ce qui voyage est un hachage
//  bcrypt, jamais le mot de passe — c'est le point que cette suite garde.
//
//  Elle ne peut pas jouer le SQL (il est MySQL, la boucle locale est SQLite),
//  alors elle vérifie ce qui se vérifie sans base : la forme du SQL, ce qu'il
//  ne contient PAS, et surtout que le hachage émis ouvre bien la porte au mot
//  de passe demandé — password_verify() sur ce qui sort du SQL.
//
//  Usage :  php tests/e2e_haslo.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/delivery.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/seed.php';

/** Relit un littéral `_utf8mb4 0x…` — c'est ainsi qu'on inspecte le SQL émis. */
function dehex(string $lit): string {
    return preg_match('/_utf8mb4 0x([0-9a-f]+)/i', $lit, $m) ? hex2bin($m[1]) : '';
}
/** Tous les littéraux d'un SQL, dans l'ordre. */
function tous_hex(string $sql): array {
    preg_match_all('/_utf8mb4 0x([0-9a-f]+)/i', $sql, $m);
    return array_map('hex2bin', $m[1]);
}

echo "webshop_mrszoko — end-to-end hasło konsoli (SQL)\n\n";

$MDP = 'Zażółć-gęślą-jaźń-2026!';
$SQL = wsm_set_password_sql('Admin@MisterSzoko.com', $MDP);

// ---- 1. Le hachage émis OUVRE la porte -------------------------------------
//
// C'est la seule question qui compte : le mot de passe demandé passe-t-il
// contre ce que le SQL va poser ? Tout le reste est de la plomberie.
echo "-- hasło otwiera drzwi --\n";
$vals = tous_hex($SQL);
$hash = '';
foreach ($vals as $v) if (str_starts_with($v, '$2y$')) { $hash = $v; break; }
ok('le SQL porte un hachage bcrypt', $hash !== '', substr($hash, 0, 4));
ok('password_verify() accepte le mot de passe demandé', $hash !== '' && password_verify($MDP, $hash));
ok('et refuse un autre mot de passe', $hash !== '' && !password_verify($MDP . 'x', $hash));
// Un mot de passe non-ASCII doit survivre au voyage : c'est un shop polonais.
ok('les diacritiques survivent (żółć)', str_contains($MDP, 'ż') && password_verify($MDP, $hash));

// ---- 2. LE MOT DE PASSE NE VOYAGE PAS --------------------------------------
//
// Ce SQL traverse un scp, dort dans /tmp et peut finir dans un journal. La
// règle du dépôt public s'applique au fichier produit, pas seulement au dépôt.
echo "\n-- hasło nie podróżuje --\n";
ok('le mot de passe n\'est pas en clair dans le SQL', !str_contains($SQL, $MDP));
ok('ni sous forme hexadécimale', !str_contains(strtolower($SQL), bin2hex($MDP)));
foreach (tous_hex($SQL) as $v) {
    if ($v === $MDP) { ok('aucun littéral ne décode vers le mot de passe', false, $v); break; }
}
ok('aucun littéral ne décode vers le mot de passe', !in_array($MDP, tous_hex($SQL), true));
// Deux appels, deux sels : deux SQL différents pour le même mot de passe, et
// les deux ouvrent. Un hachage constant trahirait un sel figé.
$SQL2 = wsm_set_password_sql('admin@misterszoko.com', $MDP);
$h2 = '';
foreach (tous_hex($SQL2) as $v) if (str_starts_with($v, '$2y$')) { $h2 = $v; break; }
ok('deux émissions donnent deux hachages (sel aléatoire)', $h2 !== $hash, [$hash === $h2]);
ok('et le second ouvre aussi', password_verify($MDP, $h2));

// ---- 3. La forme du SQL ----------------------------------------------------
echo "\n-- kształt SQL-a --\n";
// Compte présent → UPDATE ; compte absent → INSERT, et le tri se fait sur un
// comptage, pas sur la clé unique. RIEN ne vérifie, sur ce serveur, que
// uq_wsm_users_email existe : un ON DUPLICATE KEY sans clé n'échouerait pas,
// il créerait un SECOND compte avec la même adresse.
ok('les deux cas sont couverts (UPDATE + INSERT conditionnel)',
   str_contains($SQL, 'UPDATE wsm_users') && str_contains($SQL, 'INSERT INTO wsm_users'));
ok('l\'écriture ne dépend d\'AUCUNE clé unique', !str_contains($SQL, 'ON DUPLICATE KEY'));
ok('l\'insertion est gardée par un comptage préalable',
   str_contains($SQL, 'SELECT COUNT(*) INTO @wsm_konto') && str_contains($SQL, 'WHERE q.istnieje = 0'));
// L'insertion lit une table dérivée, jamais wsm_users : c'est ce qui la met
// hors d'atteinte des restrictions MySQL sur la table qu'on écrit.
$ins = substr($SQL, strpos($SQL, 'INSERT INTO wsm_users'));
$ins = substr($ins, 0, strpos($ins, ';') + 1);
ok('… et ce comptage ne relit pas la table qu\'on écrit',
   substr_count($ins, 'wsm_users') === 1, $ins);
$maj = substr($SQL, strpos($SQL, 'UPDATE wsm_users'));
$maj = substr($maj, 0, strpos($maj, ';') + 1);
ok('elle déverrouille le compte (failed_attempts, locked_until)',
   str_contains($maj, 'failed_attempts = 0') && str_contains($maj, 'locked_until = NULL'));
ok('elle réactive un compte désactivé (act = 1)', str_contains($maj, 'act = 1'));
// Ne PAS réécrire nom et rôle d'un compte existant : le déploiement pose de
// quoi entrer, il ne renomme pas les gens.
ok('elle ne réécrit ni le nom ni le rôle d\'un compte existant',
   !str_contains($maj, 'nom =') && !str_contains($maj, 'role ='));
// La preuve, pas la promesse : le SELECT final dit si la ligne porte CE hachage.
ok('un SELECT final prouve que la ligne porte ce hachage',
   str_contains($SQL, 'SELECT CONCAT(') && substr_count($SQL, bin2hex($hash)) === 3);
ok('l\'e-mail est mis en minuscules', in_array('admin@misterszoko.com', tous_hex($SQL), true));
ok('la portée par défaut est « Cała sieć »', in_array('Cała sieć', tous_hex($SQL), true));
ok('le rôle par défaut est administrateur', in_array(WSM_ROLE_ADMIN, tous_hex($SQL), true));

// ---- 4. Ce qui est refusé ne s'émet pas à moitié ---------------------------
//
// Un `> fichier.sql` sur une émission refusée laisserait un fichier vide qu'on
// jouerait ensuite en croyant avoir posé quelque chose.
echo "\n-- odmowa jest odmową --\n";
foreach ([
    ['pas un e-mail', 'admin', 'MotDePasse-Assez-Long'],
    ['e-mail vide',   '',      'MotDePasse-Assez-Long'],
    ['mot de passe trop court', 'admin@misterszoko.com', 'court'],
    ['mot de passe vide',       'admin@misterszoko.com', ''],
] as [$quoi, $mail, $mdp]) {
    $leve = false;
    try { wsm_set_password_sql($mail, $mdp); } catch (InvalidArgumentException $e) { $leve = true; }
    ok("refusé : $quoi", $leve);
}
ok('la longueur minimale est celle de la connexion (WSM_PASSWORD_MIN)', WSM_PASSWORD_MIN === 10);

// ---- 5. La ligne de commande, telle que le déploiement l'appelle -----------
echo "\n-- wiersz poleceń wdrożenia --\n";
$mig = escapeshellarg(dirname(__DIR__) . '/migrate.php');
$run = function(string $mdp, string $args) use ($mig): array {
    $cmd = 'printf %s ' . escapeshellarg($mdp) . " | php $mig --set-password-sql $args 2>/dev/null";
    $out = shell_exec($cmd . '; echo "::$?"');
    $out = (string) $out;
    $at = strrpos($out, '::');
    return [substr($out, 0, $at), (int) substr($out, $at + 2)];
};
[$o, $c] = $run($MDP, escapeshellarg('admin@misterszoko.com'));
ok('le mot de passe entre par stdin, pas par argv', $c === 0 && str_contains($o, 'INSERT INTO wsm_users'), $c);
$hCli = '';
foreach (tous_hex($o) as $v) if (str_starts_with($v, '$2y$')) { $hCli = $v; break; }
ok('et le SQL produit ouvre la porte', $hCli !== '' && password_verify($MDP, $hCli));
ok('rien du mot de passe ne sort sur la sortie standard',
   !str_contains($o, $MDP) && !str_contains(strtolower($o), bin2hex($MDP)));
// Refus : code 2 ET sortie standard vide. Les deux, sinon `> f.sql` ment.
[$o, $c] = $run('court', escapeshellarg('admin@misterszoko.com'));
ok('mot de passe trop court : code 2', $c === 2, $c);
ok('… et RIEN sur la sortie standard (le fichier .sql reste vide)', trim($o) === '', $o);
[$o, $c] = $run($MDP, escapeshellarg('pas-un-email'));
ok('e-mail invalide : code 2, sortie standard vide', $c === 2 && trim($o) === '', [$c, $o]);
// Rôle et nom passent en argument — eux ne sont pas des secrets.
[$o, $c] = $run($MDP, escapeshellarg('chef@misterszoko.com') . ' ' . escapeshellarg(WSM_ROLE_SUPERADMIN) . ' ' . escapeshellarg('Sam'));
ok('rôle et nom se passent en argument', $c === 0
   && in_array(WSM_ROLE_SUPERADMIN, tous_hex($o), true) && in_array('Sam', tous_hex($o), true), $c);

// ---- 6. Le même travail que la voie « base ouverte » -----------------------
//
// Deux chemins pour un seul effet : celui-ci doit poser exactement ce que
// wsm_set_password() pose. On le vérifie sur SQLite, où la voie normale
// tourne, en comparant ce qui compte — la porte s'ouvre-t-elle.
echo "\n-- ta sama robota co droga z bazą --\n";
$tmp = sys_get_temp_dir() . '/wsm-haslo-' . getmypid() . '.sqlite';
@unlink($tmp);
$pdo = new PDO('sqlite:' . $tmp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE wsm_users (id INTEGER PRIMARY KEY AUTOINCREMENT, nom TEXT, email TEXT,
            role TEXT, portee TEXT, act INTEGER DEFAULT 1, password_hash TEXT,
            failed_attempts INTEGER DEFAULT 0, locked_until TEXT)");
wsm_set_password($pdo, 'admin@misterszoko.com', $MDP);
$ref = $pdo->query("SELECT password_hash FROM wsm_users WHERE email = 'admin@misterszoko.com'")->fetchColumn();
ok('la voie « base ouverte » pose un hachage qui ouvre', password_verify($MDP, (string) $ref));
ok('la voie « SQL » pose un hachage qui ouvre le même mot de passe', password_verify($MDP, $hash));
ok('les deux hachages sont du même algorithme', substr((string) $ref, 0, 4) === substr($hash, 0, 4));
// Le compte verrouillé : les deux voies le rouvrent.
$pdo->exec("UPDATE wsm_users SET failed_attempts = 5, act = 0");
wsm_set_password($pdo, 'admin@misterszoko.com', $MDP);
$r = $pdo->query("SELECT act, failed_attempts FROM wsm_users WHERE email = 'admin@misterszoko.com'")->fetch(PDO::FETCH_ASSOC);
ok('la voie « base ouverte » déverrouille', (int) $r['act'] === 1 && (int) $r['failed_attempts'] === 0, $r);
ok('la voie « SQL » écrit la même chose',
   str_contains($maj, 'act = 1') && str_contains($maj, 'failed_attempts = 0'));
@unlink($tmp);

echo "\n" . str_repeat('─', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
