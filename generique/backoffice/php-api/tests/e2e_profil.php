<?php
// ============================================================================
//  e2e_profil.php — les profils modifiables, et ce qu'ils ne peuvent PAS.
//
//  POURQUOI CE FICHIER PÈSE PLUS QUE L'ÉCRAN QU'IL COUVRE. Rendre les droits
//  modifiables depuis la console rend aussi modifiable la façon dont on se
//  ferme dehors. Trois manières de tout perdre, et elles ne se voient pas :
//
//   1. ON SE VERROUILLE SOI-MÊME. Une case décochée sur « Administrator » et
//      plus personne n'ouvre Użytkownicy — donc plus personne ne recoche la
//      case. La console devient irrécupérable sans accès à la base. Les deux
//      profils complets sont donc INTOUCHABLES, et le test le vérifie par les
//      deux bouts : le formulaire refuse, ET une ligne posée directement en
//      base est ignorée à la lecture.
//   2. ON S'OUVRE UNE PORTE QU'ON S'ÉTAIT FERMÉE. superadmin.php chiffre ce
//      que la boutique doit à qui la lui loue. Si un profil pouvait
//      l'accorder, un Administrator se le donnerait en trois clics — et tout
//      le soin mis dans wsm_peut_donner_role() n'aurait servi à rien.
//   3. ON CASSE UN GESTE SANS CASSER D'ÉCRAN. « Zamówienia » sans
//      « imprimer une commande » : l'écran s'ouvre, le bouton répond 403, et
//      on cherche la panne dans l'imprimante. Les satellites suivent donc
//      leur parent, toujours.
//
//  Et la raison d'être de tout l'écran : L'ÉCART ENTRE DE DROIT ET DE FAIT.
//  Un droit accordé et jamais exercé ne provoque aucune erreur et aucune
//  plainte. C'est ce qui le rend invisible — et c'est tout ce qu'un compte
//  volé emporte en plus.
//
//  Usage :  php tests/e2e_profil.php
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
require_once dirname(__DIR__) . '/roles.php';
require_once dirname(__DIR__) . '/usage.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end profile (co wolno zmienić, a czego nie)\n\n";

$net = function () use ($pdo) {
    $pdo->exec("DELETE FROM wsm_role_screens");
    $pdo->exec("DELETE FROM wsm_role_profiles");
    wsm_roles_oublie();
};
$net();

// Les écrans qu'on a le droit de citer : le rail, comme le passe l'écran.
$dispo = ['pulpit.php' => 'Pulpit', 'zamowienia.php' => 'Zamówienia', 'faktury.php' => 'Faktury',
          'ksef.php' => 'KSeF', 'wysylka.php' => 'Wysyłka', 'magazyn.php' => 'Magazyn',
          'klienci.php' => 'Klienci', 'produkty.php' => 'Produkty', 'poczta.php' => 'Poczta',
          'uzytkownicy.php' => 'Użytkownicy', 'ustawienia.php' => 'Ustawienia',
          'audyt.php' => 'Audyt'];
$u = fn(string $r): array => ['email' => 'x@y.pl', 'role' => $r];

// ---- 1. Sans rien en base, RIEN NE CHANGE ---------------------------------
echo "-- pusta nakładka nie zmienia niczego --\n";
ok('la surcouche vide ne redéfinit aucun profil', wsm_roles_overlay() === [], wsm_roles_overlay());
ok('les profils en vigueur sont ceux du code',
   array_keys(wsm_roles()) === array_keys(wsm_roles_base()));
ok('Sprzedaż garde ses droits du code',
   wsm_droit_ecran($u('Sprzedaż'), 'zamowienia.php') === 'w'
   && wsm_droit_ecran($u('Sprzedaż'), 'ustawienia.php') === '');
// L'oubli qui a motivé la règle des satellites : le magasin imprimait
// l'étiquette InPost et se heurtait à un 403 sur celle de DPD, même geste.
ok('Magazyn imprime AUSSI l\'étiquette DPD — l\'oubli qui a nommé la règle',
   wsm_droit_ecran($u('Magazyn'), 'etykieta_dpd.php') === 'w');

// ---- 2. Les deux profils complets sont intouchables -----------------------
echo "\n-- « Administrator » i « Superadmin » są nietykalne --\n";
foreach ([WSM_ROLE_ADMIN, WSM_ROLE_SUPERADMIN] as $fixe) {
    ok("« $fixe » n'est pas modifiable", !wsm_profil_modifiable($fixe));
    [$okS, $errS] = wsm_profil_save($pdo, $fixe, ['pulpit.php' => 'r'], 'Cokolwiek', $dispo);
    ok("le formulaire refuse d'enregistrer « $fixe »", $okS === false && $errS !== []);
    ok("« $fixe » ouvre toujours tout après la tentative",
       wsm_droit_ecran($u($fixe), 'uzytkownicy.php') === 'w');
}
// LE contrôle qui vaut le déplacement : une ligne posée à la MAIN en base,
// avec un client SQL, ne doit pas davantage y arriver. La règle est relue à
// la lecture, pas seulement appliquée à l'écriture.
$pdo->prepare("INSERT INTO wsm_role_profiles (rola, opis, maj) VALUES (?,?,?)")
    ->execute([WSM_ROLE_ADMIN, 'wstrzyknięte', date('Y-m-d H:i:s')]);
$pdo->prepare("INSERT INTO wsm_role_screens (rola, ekran, droit) VALUES (?,?,?)")
    ->execute([WSM_ROLE_ADMIN, 'pulpit.php', 'r']);
wsm_roles_oublie();
ok('une ligne posée à la main sur « Administrator » est ignorée à la lecture',
   !isset(wsm_roles_overlay()[WSM_ROLE_ADMIN]), array_keys(wsm_roles_overlay()));
ok('Administrator écrit toujours sur Użytkownicy — la console reste récupérable',
   wsm_droit_ecran($u(WSM_ROLE_ADMIN), 'uzytkownicy.php') === 'w');
$net();

// ---- 3. superadmin.php ne s'accorde jamais --------------------------------
echo "\n-- « superadmin.php » nie da się przyznać profilem --\n";
[$okS] = wsm_profil_save($pdo, 'Sprzedaż',
    ['zamowienia.php' => 'w', 'superadmin.php' => 'w'], 'Sprzedaż — zamówienia', $dispo);
ok('l\'enregistrement passe (l\'écran interdit est retiré, pas refusé)', $okS === true);
ok('Sprzedaż n\'ouvre pas la facturation de la plateforme',
   wsm_droit_ecran($u('Sprzedaż'), 'superadmin.php') === '');
// Et par la porte de service : une ligne posée à la main.
$pdo->prepare("INSERT INTO wsm_role_screens (rola, ekran, droit) VALUES (?,?,?)")
    ->execute(['Sprzedaż', 'superadmin.php', 'w']);
wsm_roles_oublie();
ok('même écrite à la main, la ligne « superadmin.php » ne donne rien',
   wsm_droit_ecran($u('Sprzedaż'), 'superadmin.php') === '');
$net();

// ---- 4. Un profil redéfini REMPLACE, il ne s'ajoute pas -------------------
echo "\n-- zapis zastępuje, nie dokłada --\n";
[$okS] = wsm_profil_save($pdo, 'Sprzedaż', ['zamowienia.php' => 'r'], 'Sprzedaż — tylko podgląd', $dispo);
ok('l\'enregistrement réussit', $okS === true);
ok('le droit décoché a bien DISPARU — sinon le formulaire mentirait',
   wsm_droit_ecran($u('Sprzedaż'), 'klienci.php') === '',
   wsm_droit_ecran($u('Sprzedaż'), 'klienci.php'));
ok('le droit gardé est passé en lecture seule',
   wsm_droit_ecran($u('Sprzedaż'), 'zamowienia.php') === 'r');
ok('la description remplace celle du code',
   (wsm_roles()['Sprzedaż']['aide'] ?? '') === 'Sprzedaż — tylko podgląd');
ok('les autres profils ne bougent pas',
   wsm_droit_ecran($u('Magazyn'), 'wysylka.php') === 'w');

// ---- 5. Les satellites suivent leur parent --------------------------------
echo "\n-- ekrany satelickie idą za swoim ekranem --\n";
[$okS] = wsm_profil_save($pdo, 'Magazyn',
    ['wysylka.php' => 'w', 'zamowienia.php' => 'r'], 'Magazyn — wysyłka', $dispo);
ok('l\'enregistrement réussit', $okS === true);
foreach (['etykieta_druk.php', 'etykieta_inpost.php', 'etykieta_dpd.php'] as $sat) {
    ok("« $sat » suit Wysyłka en écriture", wsm_droit_ecran($u('Magazyn'), $sat) === 'w');
}
ok('l\'impression d\'une commande suit Zamówienia — en LECTURE, comme son parent',
   wsm_droit_ecran($u('Magazyn'), 'zamowienie_druk.php') === 'r');
// Et l'inverse : le parent retiré emporte ses satellites.
[$okS] = wsm_profil_save($pdo, 'Magazyn', ['magazyn.php' => 'w'], 'Magazyn — tylko stany', $dispo);
ok('le parent retiré ferme ses satellites',
   wsm_droit_ecran($u('Magazyn'), 'etykieta_inpost.php') === ''
   && wsm_droit_ecran($u('Magazyn'), 'etykieta_dpd.php') === '');
ok('le satellite du parent gardé reste ouvert',
   wsm_droit_ecran($u('Magazyn'), 'magazyn_druk.php') === 'w');
$net();

// ---- 6. Ce que le formulaire refuse ---------------------------------------
echo "\n-- czego formularz nie przyjmie --\n";
[$okS, $e] = wsm_profil_save($pdo, '', ['pulpit.php' => 'r'], 'Opis', $dispo);
ok('un profil sans nom est refusé', $okS === false && isset($e['rola']));
[$okS, $e] = wsm_profil_save($pdo, 'Praktykant', ['pulpit.php' => 'r'], '', $dispo);
ok('un profil sans description est refusé — c\'est elle qu\'on lit en attribuant',
   $okS === false && isset($e['opis']));
[$okS, $e] = wsm_profil_save($pdo, 'administrator', ['pulpit.php' => 'r'], 'Podszywanie', $dispo);
ok('« administrator » en minuscules est refusé — deux entrées indiscernables dans une liste',
   $okS === false && isset($e['rola']));
[$okS, $e] = wsm_profil_save($pdo, str_repeat('a', WSM_PROFIL_NOM_MAX + 1), ['pulpit.php' => 'r'], 'Za długi', $dispo);
ok('un nom plus long que ' . WSM_PROFIL_NOM_MAX . ' est refusé — l\'enregistreur le tronquerait',
   $okS === false && isset($e['rola']));
[$okS, $e] = wsm_profil_save($pdo, 'Sprzedaż <b>', ['pulpit.php' => 'r'], 'Znaczniki', $dispo);
ok('un nom avec des chevrons est refusé', $okS === false && isset($e['rola']));
// Un écran qui n'est pas dans la liste proposée n'entre pas : sinon un
// formulaire trafiqué accorderait un fichier qui n'est pas un écran.
[$okS] = wsm_profil_save($pdo, 'Praktykant',
    ['pulpit.php' => 'r', 'config.php' => 'w', 'inexistant.php' => 'w'], 'Praktykant — pulpit', $dispo);
ok('un fichier hors du rail est ignoré, pas accordé',
   $okS === true && wsm_droit_ecran($u('Praktykant'), 'config.php') === '');
// Un droit inventé n'ouvre rien.
[$okS] = wsm_profil_save($pdo, 'Praktykant', ['pulpit.php' => 'x'], 'Praktykant — nic', $dispo);
ok('un droit qui n\'est ni « r » ni « w » n\'accorde rien',
   $okS === true && wsm_droit_ecran($u('Praktykant'), 'pulpit.php') === '');
$net();

// ---- 7. Créer, restaurer, supprimer ---------------------------------------
echo "\n-- tworzenie, przywracanie, usuwanie --\n";
[$okS] = wsm_profil_save($pdo, 'Praktykant', ['pulpit.php' => 'r', 'zamowienia.php' => 'r'],
                         'Praktykant — tylko podgląd zamówień', $dispo);
ok('un profil créé en console existe', $okS === true && isset(wsm_roles()['Praktykant']));
ok('il ouvre ce qu\'on lui a donné, et rien d\'autre',
   wsm_droit_ecran($u('Praktykant'), 'zamowienia.php') === 'r'
   && wsm_droit_ecran($u('Praktykant'), 'faktury.php') === '');
ok('un compte qui le porte n\'est plus renvoyé sur Podgląd',
   wsm_role_de(['role' => 'Praktykant']) === 'Praktykant');

// Restaurer un profil INTÉGRÉ le rend au code.
wsm_profil_save($pdo, 'Sprzedaż', ['pulpit.php' => 'r'], 'Sprzedaż — vidée', $dispo);
ok('Sprzedaż est bien vidée avant restauration',
   wsm_droit_ecran($u('Sprzedaż'), 'zamowienia.php') === '');
ok('la restauration réussit', wsm_profil_reset($pdo, 'Sprzedaż') === true);
ok('Sprzedaż a retrouvé exactement les droits du code',
   wsm_roles()['Sprzedaż']['ecrans'] === wsm_roles_base()['Sprzedaż']['ecrans']);

// Supprimer : seulement un profil créé ici, et seulement s'il est libre.
$code = wsm_roles_base();
[$okD, $msg] = wsm_profil_supprime($pdo, 'Sprzedaż', $code);
ok('un profil intégré ne se supprime pas — il se restaure', $okD === false && $msg !== '');
[$okD] = wsm_profil_supprime($pdo, WSM_ROLE_ADMIN, $code);
ok('« Administrator » ne se supprime pas non plus', $okD === false);

// LE cas qui perd des gens : un profil encore porté. Personne n'aurait
// d'erreur — les comptes retomberaient silencieusement sur « Podgląd ».
$pdo->prepare("INSERT INTO wsm_users (nom, email, role, portee, act) VALUES (?,?,?,?,1)")
    ->execute(['Test Profil', 'praktykant.test@mrszoko.local', 'Praktykant', '']);
[$okD, $msg] = wsm_profil_supprime($pdo, 'Praktykant', $code);
ok('un profil encore porté par un compte actif ne se supprime pas', $okD === false);
ok('et le refus DIT combien de comptes attendent', str_contains($msg, '1'), $msg);
$pdo->prepare("DELETE FROM wsm_users WHERE email = ?")->execute(['praktykant.test@mrszoko.local']);
[$okD] = wsm_profil_supprime($pdo, 'Praktykant', $code);
ok('libéré, il se supprime', $okD === true && !isset(wsm_roles()['Praktykant']));
ok('un compte resté dessus retombe sur le moins permissif',
   wsm_role_de(['role' => 'Praktykant']) === 'Podgląd');

// Un profil vidé est ACCEPTÉ : c'est ainsi qu'on suspend sans toucher aux comptes.
[$okS] = wsm_profil_save($pdo, 'Podgląd', [], 'Podgląd — zawieszony', $dispo);
ok('un profil sans aucun écran s\'enregistre — c\'est une suspension', $okS === true);
ok('et il n\'ouvre plus rien', wsm_droit_ecran($u('Podgląd'), 'pulpit.php') === '');
$net();

// ---- 8. De droit / de fait ------------------------------------------------
echo "\n-- z prawa / z faktu --\n";
$pdo->exec("DELETE FROM wsm_page_views");
$jour = date('Y-m-d');
$ins = $pdo->prepare("INSERT INTO wsm_page_views (ekran, dzien, rola, n, ms_sum, ms_max) VALUES (?,?,?,?,?,?)");
$ins->execute(['zamowienia.php', $jour, 'Sprzedaż', 40, 4000, 300]);
$ins->execute(['klienci.php',    $jour, 'Sprzedaż',  9,  900, 200]);
$ins->execute(['faktury.php',    $jour, 'Sprzedaż',  3,  300, 150]);

$fait = wsm_usage_par_role_ecran($pdo, 30);
ok('l\'enregistreur sait ce que Sprzedaż a ouvert, écran par écran',
   ($fait['Sprzedaż']['zamowienia.php'] ?? 0) === 40, $fait['Sprzedaż'] ?? []);
ok('un rôle qui n\'a rien ouvert n\'apparaît pas', !isset($fait['Magazyn']));

$droits = wsm_roles_base()['Sprzedaż']['ecrans'];
$ec = wsm_profil_ecarts($droits, $fait['Sprzedaż']);
ok('les écrans utilisés sont reconnus',
   isset($ec['uzywane']['zamowienia.php'], $ec['uzywane']['klienci.php']));
ok('les plus ouverts d\'abord', array_key_first($ec['uzywane']) === 'zamowienia.php');
// LE point de tout l'écran : un droit qu'on donne et que personne n'exerce.
ok('un droit accordé et jamais exercé est signalé',
   isset($ec['nieuzywane']['poczta.php'], $ec['nieuzywane']['rabaty.php']),
   array_keys($ec['nieuzywane']));
ok('un écran utilisé n\'est pas signalé comme dormant',
   !isset($ec['nieuzywane']['zamowienia.php']));
// Une impression ne s'ouvre pas tous les jours et ne se retire pas seule :
// la compter comme un droit dormant enverrait sur une fausse piste.
ok('les satellites ne sont pas comptés comme des droits dormants',
   !isset($ec['nieuzywane']['zamowienie_druk.php']), array_keys($ec['nieuzywane']));

// L'ÉCRAN DE LA PLATEFORME NE COMPTE JAMAIS COMME UN ÉCART. Il est exclu des
// profils par construction, mais l'enregistreur le mesure comme les autres :
// sans cette exclusion, le Superadmin qui vient de l'ouvrir se voit accuser
// d'avoir ouvert « un écran qui n'est plus sur sa liste », sur la page même
// qu'il est en train de lire. Vu au premier essai réel.
$ecS = wsm_profil_ecarts(['pulpit.php' => 'r'], ['pulpit.php' => 3, 'superadmin.php' => 2]);
ok('l\'écran de la plateforme n\'est jamais compté comme un écart',
   !isset($ecS['bez_prawa']['superadmin.php']), array_keys($ecS['bez_prawa']));

// L'autre sens : ouvert alors que le droit n'y est plus.
$ec2 = wsm_profil_ecarts(['zamowienia.php' => 'w'], $fait['Sprzedaż']);
ok('un écran ouvert sans droit actuel est signalé',
   isset($ec2['bez_prawa']['klienci.php'], $ec2['bez_prawa']['faktury.php']),
   array_keys($ec2['bez_prawa']));
ok('et l\'écran encore accordé n\'y figure pas', !isset($ec2['bez_prawa']['zamowienia.php']));

// Le tableau complet, tel que l'écran le lit.
$tab = wsm_profil_tableau($pdo, $dispo, 30);
ok('chaque profil a sa ligne', isset($tab['Sprzedaż'], $tab[WSM_ROLE_ADMIN], $tab['Podgląd']));
ok('« Administrator » est marqué non modifiable', $tab[WSM_ROLE_ADMIN]['modifiable'] === false);
ok('« Sprzedaż » est marquée modifiable et intégrée',
   $tab['Sprzedaż']['modifiable'] === true && $tab['Sprzedaż']['custom'] === false);
// « ouvre tout » se compte sur les écrans livrés : c'est ce que ça veut dire.
ok('un profil « tout » liste bien des écrans, pas une étoile',
   $tab[WSM_ROLE_ADMIN]['tout'] === true && count($tab[WSM_ROLE_ADMIN]['droits']) > 5);
// Et la règle 2 vue depuis le tableau : la ligne d'un Administrator ne
// contient pas superadmin.php, même en comptant sur les écrans livrés.
ok('le tableau ne prête à personne l\'écran de la plateforme',
   !isset($tab['Sprzedaż']['droits']['superadmin.php']));
ok('les ouvertures mesurées remontent dans la ligne', $tab['Sprzedaż']['odslon'] === 52,
   $tab['Sprzedaż']['odslon']);
$pdo->exec("DELETE FROM wsm_page_views");

// ---- 9. Une panne de base ne change pas les droits ------------------------
echo "\n-- awaria bazy nie zmienia praw --\n";
// La surcouche ne doit ni ouvrir ni fermer quoi que ce soit quand la lecture
// échoue : elle retombe sur le code, silencieusement.
$avant = wsm_roles();
$pdo->exec("DROP TABLE IF EXISTS wsm_role_screens");
$pdo->exec("DROP TABLE IF EXISTS wsm_role_profiles");
wsm_roles_oublie();
ok('sans les tables, la surcouche est vide', wsm_roles_overlay() === []);
ok('sans les tables, les profils du code s\'appliquent',
   wsm_roles() === wsm_roles_base());
ok('et personne ne gagne de droit au passage',
   wsm_droit_ecran($u('Podgląd'), 'ustawienia.php') === ''
   && wsm_droit_ecran($u('Sprzedaż'), 'superadmin.php') === '');
unset($avant);
wsm_bootstrap();                                   // les tables reviennent seules
ok('les tables se recréent au démarrage suivant',
   wsm_table_exists($pdo, 'wsm_role_profiles') && wsm_table_exists($pdo, 'wsm_role_screens'));
$net();

echo "\n" . ($fail === 0 ? "OK" : "FAILED") . " — $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
