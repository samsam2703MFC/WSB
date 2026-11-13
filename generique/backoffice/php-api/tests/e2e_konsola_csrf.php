<?php
// ============================================================================
//  e2e_konsola_csrf.php — aucun écran de la console n'accepte un POST nu.
//
//  CE QU'ON A TROUVÉ. Sept écrans fabriquaient le jeton chacun dans leur coin,
//  six lignes identiques recopiées. Et SIX AUTRES acceptaient des POST sans
//  jeton du tout : Ustawienia — où l'on colle un mot de passe SMTP —,
//  Użytkownicy — où l'on crée des comptes —, Treści, Poczta, Magazyn, Faktury.
//
//  CE QUE ÇA COÛTE. Une page piégée, ouverte dans un onglet par quelqu'un dont
//  la session de console est ouverte dans un autre, poste vers l'écran des
//  comptes. Le navigateur joint le cookie de session tout seul : la console
//  exécute la demande comme si l'administrateur l'avait faite. Rien à l'écran
//  ne le dit, et le journal d'audit note le geste au nom de la victime.
//
//  CE TEST EST STATIQUE, ET C'EST VOULU. Il lit les fichiers d'écran plutôt que
//  d'ouvrir des sessions : ce qu'on veut garder, ce n'est pas « ça marche
//  aujourd'hui », c'est « le prochain écran ajouté ne pourra pas oublier ».
//  Un test qui exerce ne dit rien sur un écran qui n'existe pas encore.
//
//  Usage :  php tests/e2e_konsola_csrf.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

echo "webshop_mrszoko — end-to-end token CSRF konsoli\n\n";

$dir = dirname(__DIR__, 2);                       // generique/backoffice
$src = (string) @file_get_contents($dir . '/console.php');

echo "-- jeden token, w jednym miejscu --\n";
ok('console_csrf() existe',        str_contains($src, 'function console_csrf('));
ok('console_csrf_field() existe',  str_contains($src, 'function console_csrf_field('));
ok('console_csrf_ok() existe',     str_contains($src, 'function console_csrf_ok('));
// hash_equals, pas « === » : la comparaison ne doit pas révéler par sa durée
// combien de caractères sont justes.
ok('la comparaison est à temps constant', str_contains($src, 'hash_equals'));
// HttpOnly : une page tierce peut faire POSTER le navigateur, elle ne doit
// jamais pouvoir LIRE le cookie pour en recopier la valeur. C'est toute la
// protection — sans ce drapeau, le jeton ne protège plus rien.
ok('le cookie est HttpOnly', preg_match("/console_csrf\(\).*?'httponly' => true/s", $src) === 1);

echo "\n-- kazdy ekran, ktory przyjmuje POST, sprawdza token --\n";
$sans = [];
$vus  = 0;
foreach (glob($dir . '/*.php') as $f) {
    $nom = basename($f);
    if ($nom === 'console.php') continue;         // la boîte à outils, pas un écran
    $c = (string) file_get_contents($f);
    // Un écran qui LIT un POST : soit il teste la méthode, soit il lit $_POST.
    $prend = str_contains($c, "REQUEST_METHOD") && str_contains($c, "'POST'");
    if (!$prend) continue;
    $vus++;
    // login.php a son propre jeton depuis toujours (formulaire hors session).
    if ($nom === 'login.php') { if (!str_contains($c, 'ms_bo_csrf') && !str_contains($c, '_t')) $sans[] = $nom; continue; }
    if (!str_contains($c, 'console_csrf_ok()') && !str_contains($c, 'ms_bo_csrf')) $sans[] = $nom;
}
ok("les $vus écrans qui prennent un POST vérifient un jeton", $sans === [], $sans);

echo "\n-- ekran kont: usuwanie ma swoje zabezpieczenia --\n";
$u = (string) @file_get_contents($dir . '/uzytkownicy.php');
ok('la suppression existe', str_contains($u, "isset(\$_POST['usun'])"));
// LES QUATRE GARDES, et chacune ferme une porte différente.
ok('… on ne se supprime pas soi-même',
   str_contains($u, "Nie możesz usunąć własnego konta"));
ok('… on ne supprime pas un rôle qu\'on ne pourrait pas donner',
   preg_match("/usun.*?wsm_peut_donner_role/s", $u) === 1);
// Sans elle, supprimer le dernier administrateur ferme la console à tout le
// monde et il faut un accès SSH pour rentrer.
ok('… on ne supprime pas le dernier compte à plein accès',
   preg_match("/usun.*?admins_restants/s", $u) === 1);
// Un bouton « Usuń » à côté d'un bouton « Zapisz » se clique par erreur ;
// une adresse ne se retape pas par erreur. C'est la garde qui compte le plus,
// parce que la suppression, elle, ne se rattrape pas.
ok('… et l\'adresse doit être retapée',
   str_contains($u, "\$_POST['potwierdz']"));
// L'AUDIT EST ÉCRIT AVANT LA SUPPRESSION : écrit après, il pourrait manquer si
// celle-ci échoue à mi-chemin — et un compte disparu sans trace est exactement
// ce qu'un journal doit empêcher.
$posAudit = strpos($u, "'Usunięcie konta'");
$posDel   = strpos($u, 'DELETE FROM wsm_users');
ok('le journal est écrit AVANT la suppression',
   $posAudit !== false && $posDel !== false && $posAudit < $posDel, [$posAudit, $posDel]);

ok('désactiver a son propre bouton', str_contains($u, "isset(\$_POST['przelacz'])"));
ok('… et il ne peut pas fermer la porte à tout le monde',
   preg_match("/przelacz.*?admins_restants/s", $u) === 1);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
