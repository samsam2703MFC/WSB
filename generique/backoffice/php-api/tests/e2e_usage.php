<?php
// ============================================================================
//  e2e_usage.php — l'enregistreur de pages.
//
//  UN ENREGISTREUR EST UNE RESPONSABILITÉ, PAS UNE COMMODITÉ. Il tourne sur
//  chaque page, il écrit en base à chaque vue, et il note ce que fait une
//  équipe toute la journée. Les trois façons de le rater sont connues :
//
//   1. IL RAMASSE DES DONNÉES PERSONNELLES SANS QU'ON L'AIT VOULU. L'adresse
//      d'un écran de back-office porte des numéros de commande et des noms de
//      clients (« ?szukaj=Kowalski »). Enregistrer l'URL complète — le geste
//      naturel — transforme un journal d'ergonomie en fichier nominatif.
//   2. IL DEVIENT UNE SURVEILLANCE DU PERSONNEL. « Qui a ouvert quoi à
//      quelle heure » ne sert à aucune décision d'ergonomie, et se retourne
//      contre l'équipe. On note le RÔLE. La question « où passe le temps »
//      trouve sa réponse ; la question « qu'a fait Anna » devient impossible.
//   3. IL CASSE LA PAGE QU'IL MESURE. Un enregistreur qui lève une exception
//      fait tomber l'écran — et il tourne sur TOUS les écrans.
//
//  Le reste — agrégation, écrans morts, enchaînements — est ce pour quoi on
//  l'a écrit : savoir quoi ranger, et ne pas ranger au jugé.
//
//  Usage :  php tests/e2e_usage.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/usage.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end rejestrator stron\n\n";

$pdo->exec("DELETE FROM wsm_page_views");
$pdo->exec("DELETE FROM wsm_page_paths");

// ---- 1. Ce qui NE doit jamais entrer en base -------------------------------
echo "-- czego nigdy nie zapisujemy --\n";
// L'adresse avec ses paramètres. C'est LA règle : la query string porte le
// métier, donc les gens.
ok('une adresse avec paramètres ne donne pas d\'écran',
   wsm_usage_ecran('zamowienia.php?zamowienie=MS-2026-0412') === '',
   wsm_usage_ecran('zamowienia.php?zamowienie=MS-2026-0412'));
// Et par le chemin réel : un Referer complet est nettoyé de sa query.
$_SERVER['HTTP_HOST'] = 'sklep.example.pl';
ok('un Referer avec paramètres ne garde que le nom de l\'écran',
   wsm_usage_skad('https://sklep.example.pl/mrszoko/backoffice/klienci.php?szukaj=Kowalski', 'faktury.php') === 'klienci.php',
   wsm_usage_skad('https://sklep.example.pl/mrszoko/backoffice/klienci.php?szukaj=Kowalski', 'faktury.php'));
// Un Referer étranger ne dit rien de notre navigation, et le recopier ferait
// entrer une adresse tierce dans nos tables.
ok('un Referer d\'un autre site est ignoré',
   wsm_usage_skad('https://google.com/search?q=mister+szoko', 'pulpit.php') === '');
// Un rafraîchissement n'est pas un déplacement.
ok('un rafraîchissement ne compte pas comme une transition',
   wsm_usage_skad('https://sklep.example.pl/mrszoko/backoffice/pulpit.php', 'pulpit.php') === '');
// Ce qui n'est pas un nom de fichier plausible n'entre pas.
foreach (['../../etc/passwd', 'zamowienia.php/../x', '<script>.php', '', 'x.html'] as $sale) {
    ok('refusé : ' . ($sale === '' ? '(vide)' : $sale), wsm_usage_ecran($sale) === '', wsm_usage_ecran($sale));
}
// La porte d'entrée ne se mesure pas : elle n'a rien à nous apprendre sur
// l'organisation du travail, et compter les tentatives de connexion serait
// un tout autre sujet.
ok('login.php n\'est pas enregistré', wsm_usage_record($pdo, 'login.php', 'Administrator', 10) === false);

// ---- 2. On agrège, on n'empile pas ----------------------------------------
echo "\n-- zapisujemy zbiorczo, nie po jednym --\n";
wsm_usage_record($pdo, 'zamowienia.php', 'Sprzedaż', 100);
wsm_usage_record($pdo, 'zamowienia.php', 'Sprzedaż', 300);
wsm_usage_record($pdo, 'zamowienia.php', 'Sprzedaż', 200, 'pulpit.php');
$n = (int) $pdo->query("SELECT COUNT(*) FROM wsm_page_views")->fetchColumn();
ok('trois vues du même écran = UNE ligne', $n === 1, $n);
$r = $pdo->query("SELECT n, ms_sum, ms_max FROM wsm_page_views")->fetch();
ok('le compteur vaut 3', (int) $r['n'] === 3, $r['n']);
ok('la somme des durées est juste', (int) $r['ms_sum'] === 600, $r['ms_sum']);
ok('et le pire temps est gardé', (int) $r['ms_max'] === 300, $r['ms_max']);

// Deux rôles sur le même écran restent distincts : c'est ce qui dit à QUI
// l'on parle sur un écran, donc pour qui on le range.
wsm_usage_record($pdo, 'zamowienia.php', 'Magazyn', 50);
$n = (int) $pdo->query("SELECT COUNT(*) FROM wsm_page_views")->fetchColumn();
ok('deux rôles sur un écran = deux lignes', $n === 2, $n);

$lect = wsm_usage_par_ecran($pdo);
ok('la lecture regroupe les rôles', count($lect) === 1 && $lect[0]['n'] === 4, $lect);
ok('et la moyenne est celle de toutes les vues', $lect[0]['ms_moy'] === (int) round(650 / 4), $lect[0]['ms_moy']);

// ---- 3. Les enchaînements --------------------------------------------------
echo "\n-- co po czym --\n";
wsm_usage_record($pdo, 'faktury.php', 'Księgowość', 90, 'zamowienia.php');
wsm_usage_record($pdo, 'faktury.php', 'Księgowość', 90, 'zamowienia.php');
$ch = wsm_usage_chemins($pdo);
$paire = null;
foreach ($ch as $c) if ($c['skad'] === 'zamowienia.php' && $c['dokad'] === 'faktury.php') $paire = $c;
ok('la transition la plus fréquente est comptée', $paire && $paire['n'] === 2, $paire);
ok('et elle arrive en tête', $ch && $ch[0]['n'] === 2, $ch[0] ?? null);

// ---- 4. Les écrans que personne n'ouvre ------------------------------------
//  C'est la seule sortie qu'un tableau de compteurs ne donne pas : un écran
//  jamais ouvert ne produit AUCUNE ligne, donc n'apparaît nulle part. Il faut
//  partir de ce qui est livré et soustraire ce qui a été vu.
echo "\n-- czego nikt nie otwiera --\n";
$livres = ['pulpit.php' => 'Pulpit', 'zamowienia.php' => 'Zamówienia',
           'faktury.php' => 'Faktury', 'allegro.php' => 'Allegro', 'kraje.php' => 'Kraje'];
$morts = wsm_usage_morts($pdo, $livres);
ok('un écran livré et jamais ouvert est nommé', isset($morts['allegro.php']), array_keys($morts));
ok('et il l\'est avec son libellé, pas son fichier', ($morts['allegro.php'] ?? '') === 'Allegro');
ok('un écran ouvert n\'y figure pas', !isset($morts['zamowienia.php']), array_keys($morts));
ok('pulpit jamais ouvert y figure aussi — aucun passe-droit', isset($morts['pulpit.php']));

// ---- 5. Ça ne casse JAMAIS la page ----------------------------------------
//  Le contrôle qui compte le plus. L'enregistreur tourne sur tous les écrans :
//  s'il lève une exception, il fait tomber toute la console. On lui retire ses
//  tables sous les pieds et on vérifie qu'il se contente de renvoyer false.
echo "\n-- nigdy nie psuje strony --\n";
$pdo->exec("DROP TABLE IF EXISTS wsm_page_views");
$pdo->exec("DROP TABLE IF EXISTS wsm_page_paths");
$casse = false;
try {
    $ecrit = wsm_usage_record($pdo, 'pulpit.php', 'Administrator', 42, 'zamowienia.php');
} catch (Throwable $e) { $casse = true; }
ok('sans ses tables, l\'enregistreur ne lève RIEN', !$casse);
ok('et il le dit en renvoyant false', isset($ecrit) && $ecrit === false, $ecrit ?? null);
// Les lectures aussi : l'écran Superadmin doit s'afficher même sans mesures.
$casseL = false;
try {
    $a = wsm_usage_par_ecran($pdo); $b = wsm_usage_chemins($pdo);
    $c = wsm_usage_par_role($pdo);  $d = wsm_usage_par_jour($pdo);
    $e = wsm_usage_morts($pdo, $livres); $f = wsm_usage_depuis($pdo);
} catch (Throwable $ex) { $casseL = true; }
ok('les lectures non plus', !$casseL);
ok('elles rendent du vide, pas une erreur', ($a ?? null) === [] && ($f ?? null) === '');
ok('et « personne n\'ouvre rien » reste vrai — tous les écrans sont nommés',
   count($e ?? []) === count($livres), count($e ?? []));

// On remet la base d'aplomb pour les suites suivantes.
wsm_apply_schema($pdo);
ok('les tables se recréent au démarrage suivant',
   wsm_table_exists($pdo, 'wsm_page_views') && wsm_table_exists($pdo, 'wsm_page_paths'));

// ---- 6. Une durée aberrante ne pollue pas la moyenne -----------------------
echo "\n-- czas nie do wiary --\n";
$pdo->exec("DELETE FROM wsm_page_views");
wsm_usage_record($pdo, 'pulpit.php', 'Administrator', -5);
wsm_usage_record($pdo, 'pulpit.php', 'Administrator', 99999999);
$r = $pdo->query("SELECT n, ms_sum FROM wsm_page_views")->fetch();
ok('les deux vues comptent quand même', (int) $r['n'] === 2, $r['n']);
ok('mais une durée impossible vaut zéro, pas une moyenne fausse',
   (int) $r['ms_sum'] === 0, $r['ms_sum']);

$pdo->exec("DELETE FROM wsm_page_views");
$pdo->exec("DELETE FROM wsm_page_paths");

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
