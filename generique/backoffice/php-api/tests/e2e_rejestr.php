<?php
// ============================================================================
//  e2e_rejestr.php — remplir une facture depuis le registre.
//
//  LA DEMANDE ÉTAIT « remplir les champs depuis VIES ». VIES ne peut pas le
//  faire, et c'est le genre de détail qui se découvre en production :
//
//    VIES NE CONNAÎT QUE LES NUMÉROS ENREGISTRÉS POUR LE COMMERCE
//    INTRACOMMUNAUTAIRE. Une société polonaise qui vend uniquement en Pologne
//    n'y figure pas. Interroger VIES avec son NIP répond « numer nieznany » —
//    vrai pour VIES, FAUX pour le client, dont le NIP est irréprochable. On
//    aurait livré un bouton qui accuse d'erreur des clients corrects.
//
//  D'où deux registres, chacun sur ce qu'il sait :
//    · NIP polonais      → Biała lista du ministère (mf.php)
//    · numéro de TVA UE  → VIES (vies.php)
//
//  Usage :  php tests/e2e_rejestr.php
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
require_once dirname(__DIR__) . '/mf.php';
require_once dirname(__DIR__) . '/vies.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end pobieranie danych z rejestru\n\n";

$nip = '7532447395';
register_shutdown_function(function () use ($pdo, $nip) {
    try { $pdo->prepare("DELETE FROM wsm_vies_checks WHERE number = ? OR vat_eu LIKE 'PL999%'")->execute([$nip]); }
    catch (Throwable $e) { }
});
$pdo->prepare("DELETE FROM wsm_vies_checks WHERE number = ?")->execute([$nip]);

// ---- 1. Le NIP, avant d'appeler qui que ce soit ---------------------------
echo "-- NIP: sprawdzamy zanim zapytamy --\n";
ok('« PL 753-244-73-95 » se réduit à ses dix chiffres', wsm_mf_nip('PL 753-244-73-95') === $nip);
ok('neuf chiffres ne sont pas un NIP', wsm_mf_nip('753244739') === '');
// LE CHIFFRE DE CONTRÔLE SE VÉRIFIE ICI, PAS LÀ-BAS. Le ministère limite le
// nombre de questions : les gaspiller sur des numéros qu'on sait faux, c'est
// se retrouver bloqué au moment où l'on a une vraie question.
$appels = 0;
wsm_mf_transport(function () use (&$appels) { $appels++; return [200, []]; });
$r = wsm_mf_check($pdo, '1234567890', true);
ok('une somme de contrôle fausse est refusée SANS appeler le ministère',
   $r['status'] === 'invalid' && $appels === 0, [$r, $appels]);

// ---- 2. Une réponse qui trouve --------------------------------------------
echo "\n-- odpowiedz rejestru --\n";
wsm_mf_transport(fn(string $n, string $d) => [200, ['result' => [
    'requestId' => 'req-1', 'subject' => [
        'name' => 'MISTER SZOKO SP. Z O.O.', 'nip' => $n, 'statusVat' => 'Czynny',
        'workingAddress' => 'ul. Stanisława Leszczyńskiego 4/29, 50-078 Wrocław']]]]);
$r = wsm_mf_check($pdo, $nip, true);
ok('le registre rend la raison sociale', ($r['name'] ?? '') === 'MISTER SZOKO SP. Z O.O.', $r);
ok('… et le numéro de consultation', ($r['consultation'] ?? '') === 'req-1');

// L'ADRESSE VIENT DE DEUX CHAMPS SELON LA FORME JURIDIQUE : une société a une
// adresse d'activité, un entrepreneur individuel une adresse de résidence.
// N'en lire qu'un laissait la moitié des clients avec un formulaire à moitié
// rempli — pire qu'un formulaire vide, parce qu'on le croit complet.
wsm_mf_transport(fn(string $n, string $d) => [200, ['result' => [
    'requestId' => 'req-2', 'subject' => [
        'name' => 'JAN KOWALSKI', 'nip' => $n, 'statusVat' => 'Czynny',
        'workingAddress' => '', 'residenceAddress' => 'Rynek 12A, 00-001 Warszawa']]]]);
$r2 = wsm_mf_check($pdo, $nip, true);
ok('l\'entrepreneur individuel est trouvé par son adresse de résidence',
   ($r2['address'] ?? '') === 'Rynek 12A, 00-001 Warszawa', $r2);

echo "\n-- adres: rozbity na pola formularza --\n";
$a = wsm_mf_adresse('ul. Stanisława Leszczyńskiego 4/29, 50-078 Wrocław');
ok('la rue',        $a['street'] === 'ul. Stanisława Leszczyńskiego', $a);
ok('le numéro',     $a['building'] === '4/29', $a);
ok('le code',       $a['postcode'] === '50-078', $a);
ok('la ville',      $a['city'] === 'Wrocław', $a);
$b = wsm_mf_adresse('Rynek 12A, 00-001 Warszawa');
ok('une adresse sans « ul. » marche aussi', $b['street'] === 'Rynek' && $b['building'] === '12A', $b);
// UN DÉCOUPAGE QUI ÉCHOUE REND DES CHAMPS VIDES, JAMAIS FAUX. Une rue posée
// dans la case du code postal se corrige moins vite qu'une case qu'on remplit
// soi-même — et le client ne la relit pas, parce qu'elle a l'air remplie.
$c = wsm_mf_adresse('quelque chose sans code postal');
ok('sans code postal, on ne devine rien', $c === ['street' => '', 'building' => '', 'postcode' => '', 'city' => ''], $c);

echo "\n-- panne rejestru to nie wina klienta --\n";
wsm_mf_transport(fn() => [503, null]);
$r = wsm_mf_check($pdo, $nip, true);
ok('un service en panne dit « indisponible », pas « faux »', $r['status'] === 'unavailable', $r);
wsm_mf_transport(fn() => [429, ['message' => 'too many']]);
ok('trop de questions aussi', wsm_mf_check($pdo, $nip, true)['status'] === 'unavailable');
wsm_mf_transport(fn() => [404, []]);
ok('un NIP absent du registre dit « nieznany »', wsm_mf_check($pdo, $nip, true)['status'] === 'invalid');

echo "\n-- pamiec podreczna: dwa rejestry, jedna tabela --\n";
// « valid » et « invalid » se gardent ; « unavailable » JAMAIS — le mettre en
// cache figerait une panne et empêcherait la question de réussir plus tard.
$pdo->prepare("DELETE FROM wsm_vies_checks WHERE number = ?")->execute([$nip]);
wsm_mf_transport(fn() => [503, null]);
wsm_mf_check($pdo, $nip, true);
ok('une panne n\'est pas mise en cache',
   (int) $pdo->query("SELECT COUNT(*) FROM wsm_vies_checks WHERE number = '$nip'")->fetchColumn() === 0);

wsm_mf_transport(fn(string $n, string $d) => [200, ['result' => ['requestId' => 'req-3',
    'subject' => ['name' => 'W CACHE', 'nip' => $n, 'workingAddress' => 'Rynek 1, 00-001 Warszawa']]]]);
wsm_mf_check($pdo, $nip, true);
$appels = 0;
wsm_mf_transport(function () use (&$appels) { $appels++; return [503, null]; });
$hit = wsm_mf_check($pdo, $nip);          // sans forcer : doit venir du cache
ok('la deuxième question ne dérange pas le ministère', $appels === 0 && ($hit['name'] ?? '') === 'W CACHE', [$appels, $hit]);

// LA LIGNE QUI COMPTE LE PLUS DE CE FICHIER. Les deux registres partagent une
// table. Une réponse du ministère relue comme une réponse de VIES ferait
// accorder — ou refuser — l'autoliquidation sur une preuve qui n'en est pas
// une, et personne ne s'en apercevrait avant un contrôle.
$viesHit = wsm_vies_cached($pdo, 'PL' . $nip, 86400);
ok('VIES ne relit JAMAIS une réponse du ministère', $viesHit === null, $viesHit);
$src = (string) $pdo->query("SELECT source FROM wsm_vies_checks WHERE number = '$nip' ORDER BY id DESC LIMIT 1")->fetchColumn();
ok('… parce que la ligne dit d\'où elle vient', $src === 'mf', $src);

echo "\n-- kasa: przycisk jest podlaczony --\n";
$kasa = (string) @file_get_contents(dirname(__DIR__, 3) . '/shop/index.php');
ok('le bouton existe', str_contains($kasa, "name=\"pobierz_dane\""));
// SANS CE « ! », le bouton passerait la commande au lieu de remplir le
// formulaire : le client cliquerait « remplir » et recevrait une confirmation.
ok('… et il ne passe pas la commande',
   str_contains($kasa, "\$page === 'kasa' && !isset(\$_POST['pobierz_dane'])"));
// ON NE REMPLIT QUE CE QUI EST VIDE : écraser ce que quelqu'un vient de taper,
// c'est lui reprendre son travail sans le prévenir — et le registre a du
// retard sur un déménagement.
ok('… et il n\'écrase pas ce qui est déjà tapé', str_contains($kasa, "=== '' && (\$trouve['name']"));
ok('un NIP part au ministère, un VAT UE part à VIES',
   str_contains($kasa, 'wsm_mf_check($pdo, $nipPl)') && str_contains($kasa, 'wsm_vies_check($pdo, $vatUE)'));

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
