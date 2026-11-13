<?php
// ============================================================================
//  e2e_platform.php — preuve que le décompte du propriétaire tient debout.
//
//  Ce module chiffre ce que la boutique doit à celui qui la loue. Deux choses
//  peuvent mal tourner, et elles n'ont pas la même gravité :
//
//   1. LA PORTE. Si le droit d'entrer se donnait depuis la console, le
//      locataire pourrait ouvrir — et réécrire — la page qui calcule son
//      loyer. On vérifie donc qu'aucun rôle, aucun jeton de service et aucune
//      valeur de remplissage n'ouvre le module, et qu'une liste vide le ferme
//      entièrement au lieu de le laisser entrebâillé.
//   2. L'ARITHMÉTIQUE ET LE GEL. Un décompte émis doit garder ses chiffres
//      même quand le contrat change ensuite. C'est la différence entre une
//      note à payer et une estimation.
//
//  Usage :  php tests/e2e_platform.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
// On NE charge PAS shop.php : l'écran superadmin ne charge que platform.php,
// et un test qui pré-charge une dépendance que la page n'a pas masque
// exactement le genre de « fonction indéfinie » qui ne se voit qu'en
// production. Ce fichier doit donc suffire à lui-même.
require_once dirname(__DIR__) . '/platform.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end platforma (najem · prowizja · rozliczenie)\n\n";

// ---- 1. La porte -------------------------------------------------------------
echo "-- brama superadmina --\n";

// Rien de configuré : le module n'existe pas. Fermé, pas entrouvert.
wsm_config_overlay(['superadmin_emails' => '']);
ok('sans liste configurée le module est fermé', wsm_platform_enabled() === false);
ok('et personne n\'est superadmin, pas même une Centrala',
    wsm_is_superadmin(['email' => 'szef@misterszoko.com', 'role' => 'Centrala']) === false);

// « xxxx » est la marque de « pas encore renseigné » dans tout ce projet :
// elle ne doit jamais ouvrir une porte par inadvertance.
wsm_config_overlay(['superadmin_emails' => 'xxxx']);
ok('« xxxx » ne vaut pas une configuration', wsm_platform_enabled() === false);

wsm_config_overlay(['superadmin_emails' => 'wlasciciel@example.com, drugi@example.com']);
ok('la liste configurée ouvre le module', wsm_platform_enabled() === true);
ok('le propriétaire entre',
    wsm_is_superadmin(['email' => 'wlasciciel@example.com', 'role' => 'Franczyza']) === true);
ok('la casse et les espaces ne changent rien',
    wsm_is_superadmin(['email' => '  WLASCICIEL@Example.COM ', 'role' => '']) === true);
ok('le second de la liste aussi',
    wsm_is_superadmin(['email' => 'drugi@example.com']) === true);

// LE POINT CENTRAL : le rôle le plus élevé de la console n'ouvre pas la porte.
ok('un compte Centrala N\'EST PAS superadmin — le locataire ne lit pas son loyer',
    wsm_is_superadmin(['email' => 'szef@misterszoko.com', 'role' => 'Centrala']) === false);
// Le jeton de service vaut Centrala : il ne doit pas hériter du superadmin,
// sinon tout script qui connaît le jeton lirait ce module.
ok('le jeton de service n\'hérite pas du superadmin',
    wsm_is_superadmin(['email' => 'wlasciciel@example.com', 'service' => true]) === false);
ok('un utilisateur absent ne passe pas', wsm_is_superadmin(null) === false);
ok('une adresse vide non plus', wsm_is_superadmin(['email' => '']) === false);

// ---- 2. Le schéma --------------------------------------------------------------
echo "\n-- schemat --\n";
wsm_ensure_platform($pdo);
ok('la table des conditions existe', wsm_table_exists($pdo, 'wsm_platform_terms'));
ok('la table des décomptes aussi', wsm_table_exists($pdo, 'wsm_platform_periods'));

// ---- 2 bis. La saisie d'un montant ------------------------------------------------
//  Un clavier polonais écrit « 450,00 ». `(float) '450,00'` vaut 450.0 en PHP :
//  les grosze partiraient à la poubelle sans un mot, sur un montant qui finit
//  en facture. C'est le genre d'écart qu'on découvre au rapprochement bancaire.
echo "\n-- kwota z przecinkiem --\n";
ok('le point décimal marche', wsm_plat_grosze('450.50') === 45_050, wsm_plat_grosze('450.50'));
ok('la VIRGULE aussi — sinon on perdrait les grosze en silence',
    wsm_plat_grosze('450,50') === 45_050, wsm_plat_grosze('450,50'));
ok('les espaces de séparation des milliers sont ignorés',
    wsm_plat_grosze("1 250,00") === 125_000, wsm_plat_grosze("1 250,00"));
ok('l\'espace insécable étroit du copier-coller aussi',
    wsm_plat_grosze("1\u{202F}250,00") === 125_000, wsm_plat_grosze("1\u{202F}250,00"));
ok('une saisie vide vaut zéro, pas une erreur', wsm_plat_grosze('') === 0);

// ---- 3. L'arithmétique, sans base ------------------------------------------------
echo "\n-- rachunek --\n";
$vol = ['gross' => 100_000, 'goods_gross' => 90_000, 'shipping_gross' => 10_000,
        'net' => 81_300, 'orders' => 7];
$t15 = ['rent_net' => 20_000, 'rate' => 0.15, 'basis' => 'brutto', 'vat_rate' => 0.23];

$c = wsm_platform_compute('2026-01', $vol, $t15);
ok('la base « brutto » prend tout, dostawa comprise', $c['base_amount'] === 100_000, $c['base_amount']);
ok('prowizja = podstawa × 15 %', $c['commission_net'] === 15_000, $c['commission_net']);
ok('razem netto = prowizja + czynsz', $c['total_net'] === 35_000, $c['total_net']);
ok('VAT liczony od całości netto, nie pozycja po pozycji',
    $c['total_vat'] === (int) round(35_000 * 0.23), $c['total_vat']);
ok('brutto = netto + VAT', $c['total_gross'] === $c['total_net'] + $c['total_vat'], $c);

// La base « towar » retire le port : 15 % de moins sur 10 000 gr = 1 500 gr.
$cT = wsm_platform_compute('2026-01', $vol, ['rent_net' => 20_000, 'rate' => 0.15,
                                             'basis' => 'towar', 'vat_rate' => 0.23]);
ok('la base « towar » exclut la dostawa', $cT['base_amount'] === 90_000, $cT['base_amount']);
ok('et la prowizja baisse d\'exactement 15 % du port',
    $c['commission_net'] - $cT['commission_net'] === 1_500,
    [$c['commission_net'], $cT['commission_net']]);
ok('le port reste TOUJOURS affiché, quelle que soit la base',
    $cT['shipping_gross'] === 10_000, $cT['shipping_gross']);

// Un mois sans vente : le loyer reste dû, la commission tombe à zéro. C'est
// toute la différence entre les deux lignes de revenu.
$vide = wsm_platform_compute('2026-01', ['gross' => 0, 'goods_gross' => 0,
                                         'shipping_gross' => 0, 'net' => 0, 'orders' => 0], $t15);
ok('sans vente la prowizja est nulle', $vide['commission_net'] === 0);
ok('mais le czynsz reste dû — c\'est un loyer, pas une commission',
    $vide['total_net'] === 20_000, $vide['total_net']);

// Une base inconnue ne doit pas faire tomber le calcul dans le vide : on
// retombe sur « brutto », jamais sur zéro (qui effacerait la commission).
$flou = wsm_platform_compute('2026-01', $vol, ['rate' => 0.15, 'basis' => 'wymyslona']);
ok('une base inconnue retombe sur brutto, jamais sur zéro',
    $flou['base_amount'] === 100_000 && $flou['commission_net'] === 15_000, $flou);

// ---- 4. Les conditions : ajout seul ------------------------------------------------
echo "\n-- warunki: dopisujemy, nie nadpisujemy --\n";
$pdo->exec("DELETE FROM wsm_platform_terms");
$pdo->exec("DELETE FROM wsm_platform_periods");

[$t1, $e1] = wsm_platform_terms_save($pdo, [
    'rent_net' => '500.00', 'rate' => '15', 'basis' => 'brutto',
    'vat_rate' => '23', 'from_ym' => '2026-01', 'note' => 'start',
], 'test');
ok('les premières conditions s\'enregistrent', $t1 !== null, $e1);
ok('le loyer est converti en grosze', ($t1['rent_net'] ?? 0) === 50_000, $t1['rent_net'] ?? null);
ok('le taux saisi en pourcent est stocké en fraction',
    abs(($t1['rate'] ?? 0) - 0.15) < 1e-9, $t1['rate'] ?? null);

[$t2] = wsm_platform_terms_save($pdo, [
    'rent_net' => '700.00', 'rate' => '18', 'basis' => 'towar',
    'vat_rate' => '23', 'from_ym' => '2026-06',
], 'test');
ok('les nouvelles conditions s\'ajoutent', $t2 !== null);
ok('un mois antérieur garde les anciennes',
    abs(wsm_platform_terms($pdo, '2026-03')['rate'] - 0.15) < 1e-9,
    wsm_platform_terms($pdo, '2026-03'));
ok('un mois postérieur prend les nouvelles',
    abs(wsm_platform_terms($pdo, '2026-08')['rate'] - 0.18) < 1e-9,
    wsm_platform_terms($pdo, '2026-08'));
ok('l\'historique garde les deux lignes', count(wsm_platform_terms_history($pdo)) === 2,
    count(wsm_platform_terms_history($pdo)));

// Validation
[$n1, $er1] = wsm_platform_terms_save($pdo, ['rate' => '250', 'from_ym' => '2026-09']);
ok('une prowizja de 250 % est refusée', $n1 === null && isset($er1['rate']), $er1);
[$n2, $er2] = wsm_platform_terms_save($pdo, ['rate' => '15', 'from_ym' => 'lipiec']);
ok('un mois mal écrit est refusé', $n2 === null && isset($er2['from_ym']), $er2);

// ---- 5. Le gel -----------------------------------------------------------------------
echo "\n-- zamrożenie rozliczenia --\n";
$moisPasse = date('Y-m', strtotime('first day of -1 month'));
$moisCourant = date('Y-m');

[$rien, $errCourant] = wsm_platform_issue($pdo, $moisCourant, 'test');
ok('on refuse de facturer le mois en cours', $rien === null, $errCourant);
ok('et on dit pourquoi', str_contains($errCourant, 'skończył'), $errCourant);

[$p1, $err1] = wsm_platform_issue($pdo, $moisPasse, 'test');
ok('un mois terminé se facture', $p1 !== null, $err1);
ok('le décompte se présente comme figé', ($p1['frozen'] ?? false) === true);
ok('et comme émis', ($p1['status'] ?? '') === 'wystawione', $p1['status'] ?? null);

$avant = (int) $p1['total_gross'];
$tauxAvant = (float) $p1['rate'];

// LE TEST QUI COMPTE : on change le contrat APRÈS l'émission. Le décompte ne
// doit pas bouger d'un grosz — sinon ce n'est pas une note, c'est un calcul
// qui se refait dans le dos de celui qui l'a reçue.
[$t3, $e3] = wsm_platform_terms_save($pdo, [
    'rent_net' => '9999.00', 'rate' => '90', 'basis' => 'brutto',
    'vat_rate' => '23', 'from_ym' => date('Y-m', strtotime('first day of +1 month')),
], 'test');
ok('on peut changer le contrat pour l\'avenir', $t3 !== null, $e3);
$p1bis = wsm_platform_period($pdo, $moisPasse);
ok('le décompte émis garde son montant', (int) $p1bis['total_gross'] === $avant,
    [$avant, $p1bis['total_gross']]);
ok('et son taux d\'origine', abs((float) $p1bis['rate'] - $tauxAvant) < 1e-9,
    [$tauxAvant, $p1bis['rate']]);

// On refuse aussi de dater de nouvelles conditions d'un mois déjà facturé :
// l'écran afficherait sinon un contrat contredisant la note déjà envoyée.
[$n3, $er3] = wsm_platform_terms_save($pdo, ['rate' => '20', 'from_ym' => $moisPasse]);
ok('on refuse de rétro-dater sur un mois déjà émis', $n3 === null && isset($er3['from_ym']), $er3);

[$n4, $er4] = wsm_platform_issue($pdo, $moisPasse, 'test');
ok('un mois déjà émis ne se réémet pas', $n4 === null, $er4);

// ---- 6. Le règlement -------------------------------------------------------------------
echo "\n-- opłacenie --\n";
[$p2, $e2b] = wsm_platform_mark_paid($pdo, $moisPasse, true);
ok('on marque le décompte réglé', ($p2['status'] ?? '') === 'oplacone', $e2b);
ok('la date de règlement est posée', ($p2['paid_at'] ?? '') !== '', $p2['paid_at'] ?? null);
[$p3] = wsm_platform_mark_paid($pdo, $moisPasse, false);
ok('et l\'on peut revenir en arrière', ($p3['status'] ?? '') === 'wystawione', $p3['status'] ?? null);

$moisJamais = date('Y-m', strtotime('first day of -11 month'));
[$n5, $er5] = wsm_platform_mark_paid($pdo, $moisJamais, true);
ok('un mois jamais émis ne peut pas être réglé', $n5 === null, $er5);

// ---- 7. Les totaux ------------------------------------------------------------------
echo "\n-- podsumowanie --\n";
$serie = wsm_platform_series($pdo, 12);
ok('la série couvre douze mois', count($serie) === 12, count($serie));
ok('elle commence par le mois en cours', $serie[0]['ym'] === $moisCourant, $serie[0]['ym']);

$tot = wsm_platform_totals($serie);
ok('le mois en cours est hors du « dû » — il n\'est pas facturable',
    $tot['running'] === (int) $serie[0]['total_gross'], [$tot['running'], $serie[0]['total_gross']]);
ok('le décompte émis compte dans le dû', $tot['due'] >= $avant, [$tot['due'], $avant]);
ok('non réglé, il reste en attente', $tot['outstanding'] === $tot['due'] - $tot['paid'], $tot);
ok('aucun total n\'est négatif par construction',
    $tot['due'] >= 0 && $tot['paid'] >= 0 && $tot['pending'] >= 0, $tot);

// ---- 8. Le volume ne compte que l'encaissé -----------------------------------------------
echo "\n-- tylko zapłacone liczy się --\n";
$v = wsm_platform_volume($pdo, $moisCourant);
$q = $pdo->prepare("SELECT COUNT(*) FROM wsm_orders
                     WHERE status <> 'anulowane' AND payment_status = 'oplacone'
                       AND SUBSTR(COALESCE(" . wsm_sql_date($pdo, 'paid_at') . ", created_at), 1, 7) = ?");
$q->execute([$moisCourant]);
ok('le nombre de commandes correspond aux encaissées du mois',
    $v['orders'] === (int) $q->fetchColumn(), $v['orders']);
ok('towar + dostawa = obrót brutto',
    $v['goods_gross'] + $v['shipping_gross'] === $v['gross'], $v);

// Une commande impayée ne doit rien ajouter.
$q2 = $pdo->prepare("SELECT COUNT(*) FROM wsm_orders WHERE payment_status <> 'oplacone'
                       AND SUBSTR(created_at, 1, 7) = ?");
$q2->execute([$moisCourant]);
$impayees = (int) $q2->fetchColumn();
echo "  (informacyjnie : $impayees nieopłaconych zamówień w tym miesiącu, poza rozliczeniem)\n";

// ---- Nettoyage --------------------------------------------------------------------------
$pdo->exec("DELETE FROM wsm_platform_terms");
$pdo->exec("DELETE FROM wsm_platform_periods");
wsm_config_overlay(['superadmin_emails' => '']);

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
