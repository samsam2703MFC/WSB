<?php
// ============================================================================
//  e2e_role.php — qui peut ouvrir quoi, et surtout qui NE PEUT PAS.
//
//  POURQUOI CE FICHIER EXISTE. Une matrice de droits ne se relit pas : elle
//  s'écrit une fois, paraît juste, et dérive au premier écran livré. Le jour
//  où elle est fausse, personne ne s'en aperçoit — un droit EN TROP ne
//  provoque aucune erreur, aucune page blanche, aucune plainte. C'est
//  précisément ce qui la rend dangereuse : une intégration cassée se voit, un
//  droit ouvert par mégarde ne se voit qu'après.
//
//  On teste donc les DEUX sens, et le second compte plus que le premier :
//   1. chaque rôle ouvre bien ce qui est son métier ;
//   2. chaque rôle se heurte bien à ce qui ne l'est pas.
//
//  Les trois règles qui protègent vraiment quelque chose :
//
//   · SEUL UN SUPERADMIN FABRIQUE UN SUPERADMIN. Le rôle a été mis en base à
//     la demande, avec son risque nommé : un compte Administrator compromis
//     ne doit pas se hisser tout seul jusqu'à la facturation de la
//     plateforme. Cette règle est ce qui reste du garde-fou.
//   · UN RÔLE INCONNU RETOMBE SUR LE MOINS PERMISSIF. Une faute de frappe en
//     base, un rôle supprimé du code : le compte doit perdre ses droits, pas
//     les garder.
//   · L'ANCIEN VOCABULAIRE CONTINUE DE MARCHER. Une base non migrée, une
//     session déjà ouverte : « Centrala » doit encore travailler, sinon le
//     déploiement éjecte tout le monde à la seconde où il passe.
//
//  Usage :  php tests/e2e_role.php
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

echo "webshop_mrszoko — end-to-end rôles (ce que chacun ouvre, et ce qu'il ne peut pas)\n\n";

$u = fn(string $r): array => ['email' => 'x@y.pl', 'role' => $r];

// ---- 1. Les rôles existent et se tiennent ---------------------------------
echo "-- role istnieją i trzymają się kupy --\n";
$roles = wsm_roles();
ok('six rôles, pas un de plus', count($roles) === 6, array_keys($roles));
foreach (['Superadmin', 'Administrator', 'Sprzedaż', 'Magazyn', 'Księgowość', 'Podgląd'] as $r) {
    ok("« $r » existe", isset($roles[$r]));
}
$sansAide = array_keys(array_filter($roles, fn($d) => trim((string) ($d['aide'] ?? '')) === ''));
ok('chacun explique ce qu\'il donne — « Sprzedaż » tout court ne dit rien à qui attribue',
    $sansAide === [], $sansAide);

// ---- 2. Ce que chaque rôle OUVRE ------------------------------------------
echo "\n-- co każda rola otwiera --\n";
ok('Superadmin ouvre la facturation de la plateforme',
    wsm_droit_ecran($u('Superadmin'), 'superadmin.php') === 'w');
ok('Administrator écrit sur toute la boutique',
    wsm_droit_ecran($u('Administrator'), 'zamowienia.php') === 'w'
    && wsm_droit_ecran($u('Administrator'), 'ustawienia.php') === 'w');
ok('Sprzedaż écrit sur les commandes et les clients',
    wsm_droit_ecran($u('Sprzedaż'), 'zamowienia.php') === 'w'
    && wsm_droit_ecran($u('Sprzedaż'), 'klienci.php') === 'w');
ok('Magazyn écrit sur l\'expédition et le stock',
    wsm_droit_ecran($u('Magazyn'), 'wysylka.php') === 'w'
    && wsm_droit_ecran($u('Magazyn'), 'magazyn.php') === 'w');
ok('Księgowość écrit sur les factures et le KSeF',
    wsm_droit_ecran($u('Księgowość'), 'faktury.php') === 'w'
    && wsm_droit_ecran($u('Księgowość'), 'ksef.php') === 'w');

// Un rôle métier doit VOIR le contexte de son travail sans pouvoir le changer.
ok('Magazyn LIT les commandes — préparer un colis sans voir la commande est absurde',
    wsm_droit_ecran($u('Magazyn'), 'zamowienia.php') === 'r');
ok('Sprzedaż LIT les produits — répondre à un client sans voir le catalogue aussi',
    wsm_droit_ecran($u('Sprzedaż'), 'produkty.php') === 'r');

// ---- 3. CE QUE CHAQUE RÔLE NE PEUT PAS — la moitié qui compte -------------
echo "\n-- czego nie wolno: połowa, która naprawdę liczy --\n";
ok('Administrator N\'OUVRE PAS la facturation de la plateforme',
    wsm_droit_ecran($u('Administrator'), 'superadmin.php') === '');
ok('Magazyn n\'ouvre pas les factures', wsm_droit_ecran($u('Magazyn'), 'faktury.php') === '');
ok('Magazyn n\'ouvre pas la messagerie client', wsm_droit_ecran($u('Magazyn'), 'poczta.php') === '');
ok('Sprzedaż n\'ouvre pas le stock', wsm_droit_ecran($u('Sprzedaż'), 'magazyn.php') === '');
ok('Sprzedaż n\'écrit pas les factures — elle les lit seulement',
    wsm_droit_ecran($u('Sprzedaż'), 'faktury.php') === 'r');
ok('Księgowość n\'ouvre pas l\'expédition', wsm_droit_ecran($u('Księgowość'), 'wysylka.php') === '');

// Les deux écrans qui donnent les clés : comptes et réglages (jetons tpay,
// InPost, KSeF, mots de passe SMTP). « Juste pour voir » n'existe pas ici.
foreach (['Sprzedaż', 'Magazyn', 'Księgowość', 'Podgląd'] as $r) {
    ok("« $r » n'ouvre pas Użytkownicy", wsm_droit_ecran($u($r), 'uzytkownicy.php') === '');
    ok("« $r » n'ouvre pas Ustawienia (jetons et mots de passe)",
        wsm_droit_ecran($u($r), 'ustawienia.php') === '');
}

// Podgląd lit, et c'est TOUT. Un seul 'w' quelque part et le rôle ne vaut rien.
$ecrit = [];
foreach (['pulpit.php', 'zamowienia.php', 'faktury.php', 'ksef.php', 'wysylka.php', 'magazyn.php',
          'produkty.php', 'klienci.php', 'poczta.php', 'rabaty.php', 'kraje.php', 'audyt.php',
          'kampanie.php', 'zgloszenia.php', 'allegro.php'] as $e) {
    if (wsm_droit_ecran($u('Podgląd'), $e) === 'w') $ecrit[] = $e;
}
ok('Podgląd n\'écrit NULLE PART', $ecrit === [], $ecrit);
ok('mais lit bien quelque chose — un rôle qui n\'ouvre rien est un compte mort',
    wsm_droit_ecran($u('Podgląd'), 'zamowienia.php') === 'r');

// ---- 4. Qui peut attribuer quoi -------------------------------------------
echo "\n-- kto komu nadaje rolę --\n";
ok('un Superadmin peut nommer un Superadmin',
    wsm_peut_donner_role($u('Superadmin'), 'Superadmin') === true);
ok('un Administrator NE PEUT PAS nommer un Superadmin — c\'est tout le garde-fou',
    wsm_peut_donner_role($u('Administrator'), 'Superadmin') === false);
ok('un Administrator peut nommer les rôles métier',
    wsm_peut_donner_role($u('Administrator'), 'Sprzedaż') === true
    && wsm_peut_donner_role($u('Administrator'), 'Magazyn') === true);
foreach (['Sprzedaż', 'Magazyn', 'Księgowość', 'Podgląd'] as $r) {
    ok("« $r » ne nomme personne", wsm_peut_donner_role($u($r), 'Podgląd') === false);
}
ok('un rôle inventé ne s\'attribue pas',
    wsm_peut_donner_role($u('Administrator'), 'Bóg') === false);

// ---- 5. L'écran Superadmin ------------------------------------------------
echo "\n-- ekran platformy --\n";
wsm_config_overlay(['superadmin_emails' => '']);
ok('le rôle Superadmin ouvre la plateforme même sans liste côté serveur',
    wsm_is_superadmin($u('Superadmin')) === true);
ok('un Administrator ne l\'ouvre pas', wsm_is_superadmin($u('Administrator')) === false);
// La liste du serveur reste l'AMORÇAGE : sans elle, aucun compte ne porterait
// le rôle au premier démarrage et personne ne pourrait jamais entrer.
wsm_config_overlay(['superadmin_emails' => 'chef@misterszoko.com']);
ok('la liste du serveur ouvre toujours — c\'est elle qui désigne le premier',
    wsm_is_superadmin(['email' => 'chef@misterszoko.com', 'role' => 'Podgląd']) === true);
ok('et elle ne s\'applique qu\'à l\'adresse écrite',
    wsm_is_superadmin(['email' => 'autre@misterszoko.com', 'role' => 'Podgląd']) === false);
// Un jeton de service automatise ; il n'est pas quelqu'un.
ok('un jeton de service n\'est JAMAIS superadmin',
    wsm_is_superadmin(['email' => '', 'service' => true, 'role' => 'Superadmin']) === false);
wsm_config_overlay(['superadmin_emails' => '']);

// ---- 5 bis. Refuser, ou nier son existence --------------------------------
//
//  Ce ne sont pas deux façons de dire la même chose. « Cet écran n'est pas de
//  ton métier » est un 403 : il aide, on sait à qui demander. L'écran de la
//  plateforme, lui, chiffre ce que la boutique doit à qui la lui loue — un
//  403 confirmerait à un locataire curieux qu'il y a quelque chose derrière.
//
//  La page l'écrivait déjà et rendait un 404. Sauf qu'elle ne s'exécutait
//  plus : depuis que console_boot() garde les écrans en amont, le 403
//  générique partait avant elle. La règle était écrite, plus appliquée —
//  d'où ces lignes, qui la tiennent maintenant.
echo "\n-- odmowa, a zaprzeczenie istnieniu to nie to samo --\n";
ok('l\'écran de la plateforme nie son existence',
    wsm_ecran_cache('superadmin.php') === true);
ok('et il le fait quel que soit le chemin qui l\'a désigné',
    wsm_ecran_cache('/var/www/html/mrszoko/backoffice/superadmin.php') === true);
ok('un écran métier, lui, se dit franchement interdit',
    wsm_ecran_cache('faktury.php') === false && wsm_ecran_cache('ustawienia.php') === false);
// La règle du 404 ne remplace PAS le droit : elle décide seulement de la
// forme du refus. Un Administrator reste sans droit sur cet écran.
ok('nier son existence ne l\'ouvre à personne de plus',
    wsm_droit_ecran($u('Administrator'), 'superadmin.php') === ''
    && wsm_droit_ecran($u(WSM_ROLE_SUPERADMIN), 'superadmin.php') === 'w');

// ---- 6. Le vocabulaire d'avant --------------------------------------------
echo "\n-- stare nazwy ról nadal działają --\n";
ok('« Centrala » devient Administrator', wsm_role_de($u('Centrala')) === WSM_ROLE_ADMIN);
ok('« Franczyza » devient Podgląd', wsm_role_de($u('Franczyza')) === 'Podgląd');
ok('un compte encore en Centrala écrit toujours',
    wsm_droit_ecran($u('Centrala'), 'zamowienia.php') === 'w');
ok('un compte encore en Franczyza ne casse rien : il lit',
    wsm_droit_ecran($u('Franczyza'), 'zamowienia.php') === 'r');

// Le défaut sûr : ce qu'on ne reconnaît pas ne reçoit rien.
ok('un rôle inconnu retombe sur le moins permissif', wsm_role_de($u('Pirate')) === 'Podgląd');
ok('un rôle vide aussi', wsm_role_de($u('')) === 'Podgląd');
ok('et il n\'écrit nulle part', wsm_droit_ecran($u('Pirate'), 'ustawienia.php') === '');
ok('un jeton de service vaut Administrator', wsm_role_de(['service' => true]) === WSM_ROLE_ADMIN);

// ---- 7. Le rail ne montre que ce qui s'ouvre ------------------------------
echo "\n-- szyna pokazuje tylko to, co się otworzy --\n";
require_once dirname(dirname(__DIR__)) . '/console.php';
$vus = function (string $role): array {
    $out = [];
    foreach (console_sections(['email' => 'x@y.pl', 'role' => $role]) as $items) {
        foreach (array_keys($items) as $f) $out[] = $f;
    }
    return $out;
};
$mag = $vus('Magazyn');
ok('le rail du Magazyn contient Wysyłka', in_array('wysylka.php', $mag, true), $mag);
ok('et PAS Faktury — un lien qui répond 403 fait croire que l\'outil est cassé',
    !in_array('faktury.php', $mag, true), $mag);
ok('ni Użytkownicy', !in_array('uzytkownicy.php', $mag, true), $mag);
$adm = $vus('Administrator');
ok('le rail de l\'Administrator ne contient pas Superadmin',
    !in_array('superadmin.php', $adm, true), $adm);
ok('celui du Superadmin, si', in_array('superadmin.php', $vus('Superadmin'), true));

// Tout ce que le rail propose doit s'ouvrir : c'est le contrat de l'écran.
$incoherent = [];
foreach (array_keys(wsm_roles()) as $r) {
    foreach ($vus($r) as $f) if (wsm_droit_ecran($u($r), $f) === '') $incoherent[] = "$r/$f";
}
ok('aucun rôle ne se voit proposer un écran qu\'il ne peut pas ouvrir',
    $incoherent === [], $incoherent);

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
