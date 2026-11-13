<?php
// ============================================================================
//  e2e_kod.php — le code du jour de l'écran plateforme.
//
//  CE QUE CE VERROU EST, ET CE QU'IL N'EST PAS.
//
//  L'écran de la plateforme est déjà derrière la connexion ET le rôle
//  Superadmin. Ce code ferme un cas de plus : la session laissée ouverte sur
//  un poste, d'où l'on tombe d'un clic distrait sur la facturation.
//
//  IL NE FAUT PAS SE MENTIR SUR SA FORCE. Six chiffres fixes plus un chiffre
//  qui suit le jour de la semaine, ce n'est pas un secret solide : la partie
//  qui tourne n'ajoute que sept possibilités, et qui voit le code un lundi
//  connaît celui du mardi. C'est un verrou contre la distraction, pas contre
//  quelqu'un qui cherche. Deux conséquences, toutes deux vérifiées ici :
//
//   1. LE COMPTAGE DES ESSAIS EST LA MOITIÉ DU VERROU. Sans lui, sept
//      chiffres se devinent en quelques secondes.
//   2. LE NOMBRE DE BASE NE VIT PAS DANS LE DÉPÔT. Il est PUBLIC : un code
//      écrit dans le code source serait lisible de tous dans la minute, et
//      ne verrouillerait donc rien. On le vérifie pour de bon, en relisant
//      les fichiers livrés.
//
//      LA BASE UTILISÉE ICI (481502) EST INVENTÉE POUR CE FICHIER. Le premier
//      jet reprenait le vrai nombre « parce que c'est un test » — et il
//      serait parti sur GitHub aussi sûrement que dans config.php. Un test
//      n'est pas un endroit privé : il est livré comme le reste.
//
//  ET LE CAS QU'ON OUBLIE TOUJOURS : sans code configuré, le verrou ne doit
//  PAS s'appliquer. Fermer par défaut rendrait l'écran inatteignable pour
//  toujours — l'impasse exacte qu'on vient de réparer sur superadmin_emails.
//
//  Usage :  php tests/e2e_kod.php
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
require_once dirname(__DIR__) . '/platform.php';

echo "webshop_mrszoko — end-to-end kod dnia\n\n";

// ---- 1. Sans code configuré, aucun verrou ---------------------------------
echo "-- bez skonfigurowanego kodu nie ma zamka --\n";
wsm_config_overlay(['superadmin_code' => '']);
ok('le verrou n\'est pas actif', wsm_super_code_actif() === false);
ok('et il n\'attend rien', wsm_super_code_attendu() === '');
// LE point : ne pas transformer l'absence de réglage en porte murée.
ok('n\'importe quelle saisie passe — sinon l\'écran serait perdu à jamais',
   wsm_super_code_ok('') === true && wsm_super_code_ok('0000000') === true);

// « xxxx » est la marque de « pas encore renseigné » dans tout ce projet.
wsm_config_overlay(['superadmin_code' => 'xxxx']);
ok('« xxxx » ne fabrique pas un verrou', wsm_super_code_actif() === false);
// Une base qui n'est pas un nombre est une base fausse : la refuser vaut
// mieux que fabriquer un code que personne ne pourra taper.
wsm_config_overlay(['superadmin_code' => 'ala ma kota']);
ok('une base non numérique est refusée', wsm_super_code_actif() === false);
wsm_config_overlay(['superadmin_code' => '12']);
ok('une base trop courte est refusée', wsm_super_code_actif() === false);

// ---- 2. Le code du jour ----------------------------------------------------
echo "\n-- kod zmienia się z dniem tygodnia --\n";
wsm_config_overlay(['superadmin_code' => '481502']);
ok('le verrou est actif', wsm_super_code_actif() === true);
$att = wsm_super_code_attendu();
ok('le code fait sept chiffres', preg_match('/^[0-9]{7}$/', $att) === 1, $att);
ok('il commence par la base', str_starts_with($att, '481502'));
ok('et finit par le jour ISO du jour', substr($att, -1) === date('N'), substr($att, -1));

// Sept jours, sept codes, tous différents du précédent — c'est toute la
// raison d'être de la partie qui tourne.
$vus = [];
for ($i = 0; $i < 7; $i++) {
    $t = mktime(12, 0, 0, 1, 5 + $i, 2026);      // 5 janvier 2026 = un lundi
    $vus[] = wsm_super_code_attendu($t);
}
ok('sept jours donnent sept codes distincts', count(array_unique($vus)) === 7, $vus);
ok('lundi finit par 1', str_ends_with($vus[0], '1'), $vus[0]);
ok('dimanche finit par 7', str_ends_with($vus[6], '7'), $vus[6]);

// ---- 3. La comparaison -----------------------------------------------------
echo "\n-- porównanie --\n";
ok('le bon code passe', wsm_super_code_ok($att) === true);
ok('un chiffre de travers ne passe pas',
   wsm_super_code_ok(substr($att, 0, 6) . (((int) substr($att, -1) % 7) + 1)) === false);
ok('la base seule ne suffit pas', wsm_super_code_ok('481502') === false);
ok('le code d\'hier ne passe pas aujourd\'hui',
   wsm_super_code_ok(wsm_super_code_attendu(time() - 86400)) === false);
ok('rien du tout ne passe pas', wsm_super_code_ok('') === false);
// Les espaces et tirets d'une saisie humaine ne doivent pas faire échouer un
// code juste : on tape « 481502 4 » sans y penser.
ok('les espaces et tirets d\'une saisie humaine sont tolérés',
   wsm_super_code_ok(substr($att, 0, 6) . ' - ' . substr($att, -1)) === true);
// Mais on ne tolère PAS des chiffres en trop : ce serait accepter un préfixe.
ok('un code plus long est refusé', wsm_super_code_ok($att . '0') === false);

// ---- 3 bis. LE CODE EST UNE PORTE, PAS SEULEMENT UN VERROU ----------------
//
//  Sans cette voie, l'entrée n'apparaissait pour PERSONNE : aucun compte ne
//  portait le rôle Superadmin, la liste du serveur était vide, et seul un
//  Superadmin peut en désigner un autre. Le rail était juste — et vide.
//
//  Elle ne s'ouvre qu'aux ADMINISTRATEURS : le code se voit par-dessus une
//  épaule, et cet écran chiffre ce que la boutique doit à qui la lui loue.
echo "\n-- kod jako droga wejścia, nie tylko zamek --\n";
$u = fn(string $r): array => ['email' => 'x@misterszoko.com', 'role' => $r, 'act' => 1];
wsm_config_overlay(['superadmin_code' => '481502']);
ok('un Administrator entre par le code', wsm_super_par_code($u(WSM_ROLE_ADMIN)) === true);
ok('un Superadmin aussi', wsm_super_par_code($u(WSM_ROLE_SUPERADMIN)) === true);
foreach (['Sprzedaż', 'Magazyn', 'Księgowość', 'Podgląd'] as $r) {
    ok("« $r » n'entre PAS par le code", wsm_super_par_code($u($r)) === false);
}
// Un jeton de service automatise ; il n'est personne, et il ne tape pas de code.
ok('un jeton de service n\'entre jamais par le code',
   wsm_super_par_code(['email' => '', 'service' => true, 'role' => WSM_ROLE_ADMIN]) === false);
ok('personne du tout non plus', wsm_super_par_code(null) === false);
// Sans code configuré, la voie n'existe pas : c'est ce qui garde l'écran
// fermé tant que le propriétaire n'a rien réglé.
wsm_config_overlay(['superadmin_code' => '']);
ok('sans code configuré, la voie est fermée', wsm_super_par_code($u(WSM_ROLE_ADMIN)) === false);
wsm_config_overlay(['superadmin_code' => '481502']);

// LE RAIL ET LA PORTE DOIVENT DIRE LA MÊME CHOSE. Le filtre du rail ne
// raisonnait que sur les rôles : il retirait l'entrée juste après qu'elle ait
// été ajoutée, et le rail restait vide alors que la page, elle, s'ouvrait.
require_once dirname(__DIR__, 2) . '/console.php';
$railAdm = [];
foreach (console_sections($u(WSM_ROLE_ADMIN)) as $i) foreach (array_keys($i) as $f) $railAdm[] = $f;
ok('le rail d\'un Administrator propose l\'écran', in_array('superadmin.php', $railAdm, true), $railAdm);
$railVte = [];
foreach (console_sections($u('Sprzedaż')) as $i) foreach (array_keys($i) as $f) $railVte[] = $f;
ok('celui de Sprzedaż ne le propose pas', !in_array('superadmin.php', $railVte, true));
wsm_config_overlay(['superadmin_code' => '']);
$railSans = [];
foreach (console_sections($u(WSM_ROLE_ADMIN)) as $i) foreach (array_keys($i) as $f) $railSans[] = $f;
ok('sans code configuré, l\'entrée disparaît du rail',
   !in_array('superadmin.php', $railSans, true), $railSans);
wsm_config_overlay(['superadmin_code' => '481502']);

// ---- 4. LE NOMBRE N'EST PAS DANS LE DÉPÔT ---------------------------------
//
//  Le contrôle qui compte vraiment. Ce dépôt est PUBLIC : un code écrit dans
//  un fichier livré serait lisible par n'importe qui sur GitHub dans la
//  minute, et ne verrouillerait donc absolument rien. On relit les fichiers.
echo "\n-- liczba bazowa NIE MOŻE być w repozytorium --\n";
$racine = dirname(__DIR__, 3);          // …/mrszoko
$fautifs = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine));
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $chemin = $f->getPathname();
    if (!preg_match('/\.(php|js|json|css|ya?ml)$/i', $chemin)) continue;
    if (str_contains($chemin, '/data/') || str_contains($chemin, 'config.local')) continue;
    if (str_contains($chemin, 'tests/e2e_kod.php')) continue;     // ce fichier-ci
    $txt = (string) file_get_contents($chemin);
    // On cherche une base plausible AFFECTÉE à la clé du code, pas un nombre
    // de passage : « superadmin_code » suivi d'un littéral chiffré.
    if (preg_match('/superadmin_code[^\n]{0,40}[\'"][0-9]{4,12}[\'"]/', $txt)) {
        $fautifs[] = str_replace($racine . '/', '', $chemin);
    }
}
ok('aucun fichier livré ne contient de code en dur', $fautifs === [], $fautifs);

// Et la configuration par défaut doit être VIDE : c'est elle qui part au
// dépôt, et un code par défaut serait un code public.
$cfg = require dirname(__DIR__) . '/config.php';
ok('config.php livre un code VIDE', ($cfg['superadmin_code'] ?? 'x') === '',
   $cfg['superadmin_code'] ?? null);

// ---- 5. Ce que l'écran fait de tout ça ------------------------------------
echo "\n-- ekran --\n";
$src = (string) file_get_contents(dirname(__DIR__, 2) . '/superadmin.php');
ok('l\'écran compte les essais', str_contains($src, 'WSM_SUPER_CODE_MAX'));
ok('et pose une pause après', str_contains($src, 'WSM_SUPER_CODE_LOCK'));
// Un refus qui dit « il manque un chiffre » aide celui qui cherche.
ok('le refus ne dit rien du code attendu',
   str_contains($src, 'Nieprawidłowy kod.') && !str_contains($src, 'wsm_super_code_attendu()'));
// Le code ouvre un privilège : l'identifiant de session ne doit pas survivre.
ok('la session est régénérée à l\'entrée', str_contains($src, 'session_regenerate_id(true)'));
// Une rafale d'essais sur CET écran mérite une trace nominative.
ok('le verrouillage est journalisé', str_contains($src, 'Kod dnia — zablokowany'));
ok('l\'entrée aussi', str_contains($src, 'Kod dnia — wejście'));

wsm_config_overlay(['superadmin_code' => '']);

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
