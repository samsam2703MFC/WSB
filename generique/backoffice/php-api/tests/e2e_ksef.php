<?php
// ============================================================================
//  e2e_ksef.php — le registre national, et les endroits où l'on se trompe
//  SANS QUE RIEN NE PROTESTE.
//
//  CE QU'ON NE PEUT PAS TESTER, ET QU'ON N'INVENTE PAS. Déposer une facture
//  au KSeF demande un jeton d'autorisation délivré au NIP du vendeur et la
//  clé publique du ministère. Nous n'avons ni l'un ni l'autre. Aucun appel
//  réseau n'est donc simulé ici.
//
//  CE QUI SE PROUVE, et pourquoi ça vaut la peine :
//
//   1. LA VENTILATION DE TVA. C'est LE danger du format : ranger un montant
//      dans le mauvais champ ne déclenche AUCUNE erreur de validation. Le
//      document passe, et la déclaration est fausse. On vérifie donc champ
//      par champ — et en particulier que le 0 % intracommunautaire va en
//      P_13_6_2 (livraison WDT) et pas en P_13_6_1 (vente intérieure) : c'est
//      la distinction qui décide de la ligne du récapitulatif VAT-UE.
//   2. L'IDEMPOTENCE. Un numéro KSeF écrit deux fois, c'est un DOUBLON au
//      registre de l'État, qu'on n'efface qu'avec une correction.
//   3. LES REFUS. Un e-paragon déposé, une vente à 0 % sans numéro de TVA de
//      l'acheteur : deux erreurs qui se paient au contrôle, des mois après.
//   4. LE DOCUMENT LUI-MÊME est bien formé et reste ATTACHÉ À LA FACTURE
//      FIGÉE — changer la raison sociale demain ne réécrit pas ce qui a été
//      déposé hier.
//
//  Usage :  php tests/e2e_ksef.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/ksef.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end KSeF (kanał zamknięty, dokument gotowy)\n\n";

/** Une facture de test, complète et cohérente, qu'on abîme ensuite au besoin. */
function fv(array $over = []): array {
    $base = [
        'id' => 0, 'kind' => 'faktura', 'kind_group' => 'faktura', 'corrects_id' => null,
        'number' => 'FV/001/08/26', 'issued_at' => '2026-08-06', 'sold_at' => '2026-08-05',
        'due_at' => '2026-08-20',
        'seller_name' => 'ATELIER WRO01 sp. z o.o.', 'seller_nip' => '897-190-26-20',
        'seller_address' => 'ul. Leszczyńskiego 4/29, 50-078 Wrocław, PL',
        'iban' => 'PL61 1090 1014 0000 0712 1981 2874', 'bank' => 'Bank Testowy',
        'buyer_name' => 'Cukiernia Testowa sp. z o.o.', 'buyer_nip' => '5252248481',
        'buyer_vat_eu' => '', 'buyer_address' => 'ul. Kwiatowa 7, 00-001 Warszawa, PL',
        'buyer_email' => 'biuro@example.com', 'currency' => 'PLN',
        'total_net' => 16260, 'total_vat' => 3740, 'total_gross' => 20000,
        'reverse_charge' => 0, 'paid' => 0, 'note' => '',
        'ksef_number' => '', 'ksef_status' => '', 'ksef_at' => null,
        'items' => [[
            'name' => 'Czekolada testowa', 'sku' => 'CZE-1', 'qty' => 2,
            'unit_net' => 8130, 'unit_gross' => 10000, 'vat_rate' => 0.23,
            'line_net' => 16260, 'line_vat' => 3740, 'line_gross' => 20000,
        ]],
    ];
    return array_replace($base, $over);
}

// ---- 1. Fermé sans identifiants -------------------------------------------
echo "-- bez danych dostępowych wszystko jest zamknięte --\n";
wsm_config_overlay(['ksef' => ['nip' => '', 'token' => '', 'public_key' => '', 'env' => '']]);
ok('le canal est fermé', wsm_ksef_enabled() === false);
$m = wsm_ksef_manquants();
ok('et ce qui manque est NOMMÉ', count($m) >= 2, $m);
ok('la clé du ministère est citée — sans elle aucune session ne s\'ouvre',
    str_contains(implode(' ', $m), 'klucza publicznego'), $m);

// « xxxx » est la valeur d'un champ de démonstration : elle ne doit PAS
// ouvrir le canal. Même règle que tpay, InPost et Allegro.
wsm_config_overlay(['ksef' => ['nip' => 'xxxx', 'token' => 'xxxx', 'public_key' => 'xxxx']]);
ok('« xxxx » compte pour VIDE — sinon on ouvre sur du vent', wsm_ksef_enabled() === false);
ok('et il reste signalé comme manquant', count(wsm_ksef_manquants()) >= 2);

// Le piège : avec un NIP et une clé VALIDES, seul le jeton reste à « xxxx ».
// Sans la règle, le canal s'ouvrirait sur un jeton de démonstration — et
// l'échec n'arriverait qu'au premier dépôt réel, en production.
$vraiKey = sys_get_temp_dir() . '/wsm-ksef-demo-' . bin2hex(random_bytes(3)) . '.pem';
file_put_contents($vraiKey, "-----BEGIN PUBLIC KEY-----\nfaux\n-----END PUBLIC KEY-----\n");
wsm_config_overlay(['ksef' => ['nip' => '8971902620', 'token' => 'xxxx', 'public_key' => $vraiKey]]);
ok('un jeton « xxxx » seul, tout le reste valide, N\'OUVRE PAS le canal', wsm_ksef_enabled() === false);
ok('et c\'est bien le jeton qui est nommé manquant',
    count(wsm_ksef_manquants()) === 1 && str_contains(wsm_ksef_manquants()[0], 'token autoryzacyjny'),
    wsm_ksef_manquants());
@unlink($vraiKey);

// Un chemin de clé qui ne mène à aucun fichier n'est PAS une configuration :
// la découverte au premier envoi réel coûte la journée de bascule.
wsm_config_overlay(['ksef' => ['nip' => '8971902620', 'token' => 'reel',
                               'public_key' => '/nie/ma/takiego/pliku.pem']]);
ok('un chemin de clé sans fichier ne suffit pas', wsm_ksef_enabled() === false);
ok('et le message dit que le fichier n\'existe pas',
    str_contains(implode(' ', wsm_ksef_manquants()), 'nie istnieje'), wsm_ksef_manquants());

$keyFile = sys_get_temp_dir() . '/wsm-ksef-test-' . bin2hex(random_bytes(3)) . '.pem';
file_put_contents($keyFile, "-----BEGIN PUBLIC KEY-----\nfaux\n-----END PUBLIC KEY-----\n");
wsm_config_overlay(['ksef' => ['nip' => '8971902620', 'token' => 'reel', 'public_key' => $keyFile]]);
ok('avec les trois éléments, le canal s\'ouvre', wsm_ksef_enabled() === true);
ok('et plus rien ne manque', wsm_ksef_manquants() === []);
ok('le bac à sable est le défaut — on n\'envoie pas en prod par distraction',
    wsm_ksef_cfg()['env'] === 'test' && str_contains(wsm_ksef_base(), 'ksef-test'));
wsm_config_overlay(['ksef' => ['env' => 'prod']]);
ok('« prod » change bien d\'adresse', wsm_ksef_base() === 'https://ksef.mf.gov.pl/api');
wsm_config_overlay(['ksef' => ['nip' => '', 'token' => '', 'public_key' => '', 'env' => '']]);
@unlink($keyFile);
ok('on referme proprement', wsm_ksef_enabled() === false);

// ---- 2. Les petites conversions -------------------------------------------
echo "\n-- konwersje, czyli miejsca gdzie się myli po cichu --\n";
ok('le NIP perd ses tirets', wsm_ksef_nip('897-190-26-20') === '8971902620');
ok('et son préfixe pays', wsm_ksef_nip('PL 897 190 26 20') === '8971902620');
ok('neuf chiffres ne sont pas un NIP', wsm_ksef_nip('897190262') === '');
ok('onze non plus', wsm_ksef_nip('89719026201') === '');
ok('un VAT-UE se coupe en pays + numéro', wsm_ksef_vat_eu('DE 811 569 869') === ['DE', '811569869']);
ok('un VAT-UE sans pays n\'en est pas un', wsm_ksef_vat_eu('123456789') === ['', '']);

ok('12 34 grosze → 12.34', wsm_ksef_kwota(1234) === '12.34');
ok('les zéros décimaux restent écrits', wsm_ksef_kwota(20000) === '200.00');
ok('5 grosze ne deviennent pas 0.5', wsm_ksef_kwota(5) === '0.05');
ok('pas de séparateur de milliers', wsm_ksef_kwota(123456789) === '1234567.89');
ok('un montant négatif garde son signe', wsm_ksef_kwota(-1234) === '-12.34');
ok('0.23 s\'écrit 23', wsm_ksef_stawka(0.23) === '23');
ok('0.05 s\'écrit 5 et pas 05', wsm_ksef_stawka(0.05) === '5');

[$kod, $l1, $l2] = wsm_ksef_adres('ul. Kwiatowa 7, 00-001 Warszawa, PL');
ok('l\'adresse rend son code pays', $kod === 'PL', $kod);
ok('la première ligne est la rue', $l1 === 'ul. Kwiatowa 7', $l1);
ok('le reste va en seconde ligne', $l2 === '00-001 Warszawa', $l2);
[$kod2, , ] = wsm_ksef_adres('Hauptstraße 3, 10115 Berlin, DE');
ok('un pays étranger est repris tel quel', $kod2 === 'DE', $kod2);
[$kod3, $l1b, ] = wsm_ksef_adres('ul. Polna 1, 00-002 Kraków');
ok('sans pays écrit, on suppose la Pologne plutôt que de refuser', $kod3 === 'PL');
ok('et la rue reste la rue', $l1b === 'ul. Polna 1', $l1b);

// ---- 3. LA VENTILATION DE TVA — le danger silencieux -----------------------
echo "\n-- rozbicie VAT: pomyłka tutaj nie zgłasza się sama --\n";
$p = wsm_ksef_pola_vat(fv());
ok('le 23 % va en P_13_1 / P_14_1', ($p['P_13_1'] ?? '') === '162.60' && ($p['P_14_1'] ?? '') === '37.40', $p);
ok('et rien ne se glisse dans les autres champs', count($p) === 2, $p);

$p8 = wsm_ksef_pola_vat(fv(['items' => [
    ['name' => 'A', 'qty' => 1, 'unit_net' => 10000, 'unit_gross' => 10800, 'vat_rate' => 0.08,
     'line_net' => 10000, 'line_vat' => 800, 'line_gross' => 10800]]]));
ok('le 8 % va en P_13_2 / P_14_2', ($p8['P_13_2'] ?? '') === '100.00' && ($p8['P_14_2'] ?? '') === '8.00', $p8);
$p5 = wsm_ksef_pola_vat(fv(['items' => [
    ['name' => 'A', 'qty' => 1, 'unit_net' => 10000, 'unit_gross' => 10500, 'vat_rate' => 0.05,
     'line_net' => 10000, 'line_vat' => 500, 'line_gross' => 10500]]]));
ok('le 5 % va en P_13_3 / P_14_3', ($p5['P_13_3'] ?? '') === '100.00' && ($p5['P_14_3'] ?? '') === '5.00', $p5);

// Deux taux sur la même facture : chacun dans son champ, et additionné avec
// ses semblables. C'est le cas de la livraison à 23 % sur des produits à 5 %.
$mix = wsm_ksef_pola_vat(fv(['items' => [
    ['name' => 'A', 'qty' => 1, 'unit_net' => 10000, 'unit_gross' => 10500, 'vat_rate' => 0.05,
     'line_net' => 10000, 'line_vat' => 500, 'line_gross' => 10500],
    ['name' => 'B', 'qty' => 1, 'unit_net' => 5000, 'unit_gross' => 5250, 'vat_rate' => 0.05,
     'line_net' => 5000, 'line_vat' => 250, 'line_gross' => 5250],
    ['name' => 'Dostawa', 'qty' => 1, 'unit_net' => 1500, 'unit_gross' => 1845, 'vat_rate' => 0.23,
     'line_net' => 1500, 'line_vat' => 345, 'line_gross' => 1845],
]]));
ok('deux lignes au même taux s\'additionnent', ($mix['P_13_3'] ?? '') === '150.00', $mix);
ok('et la livraison à 23 % ne les rejoint pas', ($mix['P_13_1'] ?? '') === '15.00', $mix);
ok('chaque TVA suit sa base', ($mix['P_14_3'] ?? '') === '7.50' && ($mix['P_14_1'] ?? '') === '3.45', $mix);

// LA distinction qui compte : 0 % intérieur ≠ 0 % intracommunautaire. Les
// deux s'écrivent « 0 » sur le papier et vont dans DEUX champs différents.
$wdt = fv(['reverse_charge' => 1, 'buyer_vat_eu' => 'DE811569869',
           'total_net' => 20000, 'total_vat' => 0, 'total_gross' => 20000,
           'items' => [['name' => 'A', 'qty' => 1, 'unit_net' => 20000, 'unit_gross' => 20000,
                        'vat_rate' => 0.0, 'line_net' => 20000, 'line_vat' => 0, 'line_gross' => 20000]]]);
$pw = wsm_ksef_pola_vat($wdt);
ok('la livraison intracommunautaire va en P_13_6_2', ($pw['P_13_6_2'] ?? '') === '200.00', $pw);
ok('et SURTOUT pas en P_13_6_1 (vente intérieure)', !isset($pw['P_13_6_1']), $pw);
ok('aucune TVA n\'est déclarée dessus', !isset($pw['P_14_1']) && count($pw) === 1, $pw);

$zero = fv(['items' => [['name' => 'A', 'qty' => 1, 'unit_net' => 20000, 'unit_gross' => 20000,
    'vat_rate' => 0.0, 'line_net' => 20000, 'line_vat' => 0, 'line_gross' => 20000]]]);
ok('le 0 % intérieur, lui, va bien en P_13_6_1',
    (wsm_ksef_pola_vat($zero)['P_13_6_1'] ?? '') === '200.00', wsm_ksef_pola_vat($zero));

// Un taux hors schéma ne se range pas en douce dans le 23 % : il reste
// visible, et l'écran le signale.
$obc = fv(['items' => [['name' => 'A', 'qty' => 1, 'unit_net' => 10000, 'unit_gross' => 10400,
    'vat_rate' => 0.04, 'line_net' => 10000, 'line_vat' => 400, 'line_gross' => 10400]]]);
ok('un taux inconnu ne se dilue PAS dans le 23 %', !isset(wsm_ksef_pola_vat($obc)['P_13_1']));
ok('il va en exonéré, où il se voit', (wsm_ksef_pola_vat($obc)['P_13_7'] ?? '') === '100.00');
ok('et il est signalé nommément', wsm_ksef_stawki_obce($obc) === [4], wsm_ksef_stawki_obce($obc));
ok('les taux connus ne sont jamais signalés', wsm_ksef_stawki_obce(fv()) === []);

// La somme des bases doit valoir le net de la facture : c'est le contrôle qui
// attrape un champ oublié.
$somme = 0;
foreach ($mix as $pole => $v) {
    if (str_starts_with($pole, 'P_13_')) $somme += (int) round(((float) $v) * 100);
}
ok('la somme des bases vaut le net des lignes', $somme === 16500, $somme);

// ---- 4. Ce qui empêche d'envoyer ------------------------------------------
echo "\n-- co blokuje wysyłkę --\n";
ok('une facture saine ne bloque sur rien', wsm_ksef_blockers($pdo, fv()) === [], wsm_ksef_blockers($pdo, fv()));

$par = wsm_ksef_blockers($pdo, fv(['kind' => 'paragon']));
ok('un e-paragon est refusé', count($par) === 1, $par);
ok('et la phrase dit que ce n\'est pas une facture', str_contains($par[0], 'nie jest fakturą'), $par);
$pro = wsm_ksef_blockers($pdo, fv(['kind' => 'proforma']));
ok('une proforma est refusée', count($pro) === 1 && str_contains($pro[0], 'proforma'), $pro);

$deja = wsm_ksef_blockers($pdo, fv(['ksef_number' => '1234-5678']));
ok('une facture déjà au registre ne repart pas', $deja !== []);
ok('et le mot « duplikat » est écrit', str_contains(implode(' ', $deja), 'duplikat'), $deja);

ok('un NIP vendeur incomplet bloque',
    in_array('NIP sprzedawcy nie jest dziesięciocyfrowy', wsm_ksef_blockers($pdo, fv(['seller_nip' => '123'])), true));
ok('une facture sans lignes bloque',
    in_array('faktura bez pozycji', wsm_ksef_blockers($pdo, fv(['items' => []])), true));
ok('une devise étrangère bloque, faute de cours',
    str_contains(implode(' ', wsm_ksef_blockers($pdo, fv(['currency' => 'EUR']))), 'kursu'));
ok('une facture à zéro bloque',
    in_array('faktura na kwotę zerową', wsm_ksef_blockers($pdo, fv(['total_gross' => 0])), true));

// LE contrôle qui vaut de l'argent : le 0 % intracommunautaire sans numéro de
// TVA de l'acheteur n'est pas justifié, et se reprend au taux polonais.
$sansVat = wsm_ksef_blockers($pdo, array_replace($wdt, ['buyer_vat_eu' => '']));
ok('une vente WDT sans numéro VAT-UE bloque',
    str_contains(implode(' ', $sansVat), 'bez numeru VAT-UE'), $sansVat);
ok('avec le numéro, elle passe', wsm_ksef_blockers($pdo, $wdt) === [], wsm_ksef_blockers($pdo, $wdt));
$incoh = wsm_ksef_blockers($pdo, array_replace($wdt, ['total_vat' => 100]));
ok('une vente WDT avec de la TVA se contredit et bloque',
    str_contains(implode(' ', $incoh), 'sam sobie przeczy'), $incoh);

$orph = wsm_ksef_blockers($pdo, fv(['kind' => 'korekta', 'corrects_id' => 0]));
ok('une correction orpheline bloque', in_array('korekta nie wskazuje faktury pierwotnej', $orph, true), $orph);

// Toutes les raisons, pas la première : sinon on répare en escalier.
$casse = wsm_ksef_blockers($pdo, fv(['seller_nip' => '1', 'seller_name' => '', 'items' => []]));
ok('les raisons sont rendues TOUTES ensemble', count($casse) >= 3, $casse);

// ---- 5. Le document -------------------------------------------------------
echo "\n-- dokument FA(2) --\n";
$xml = wsm_ksef_xml($pdo, fv(), '2026-08-06T10:00:00+02:00');
$prev = libxml_use_internal_errors(true);
$doc = simplexml_load_string($xml);
libxml_use_internal_errors($prev);
ok('le XML est BIEN FORMÉ', $doc !== false);
ok('la racine est Faktura', $doc !== false && $doc->getName() === 'Faktura');
ok('dans l\'espace de noms FA(2)', str_contains($xml, WSM_KSEF_NS));
ok('le numéro de facture est en P_2', str_contains($xml, '<P_2>FV/001/08/26</P_2>'));
ok('la date de vente est en P_6, distincte de l\'émission',
    str_contains($xml, '<P_6>2026-08-05</P_6>') && str_contains($xml, '<P_1>2026-08-06</P_1>'));
ok('le total dû est en P_15', str_contains($xml, '<P_15>200.00</P_15>'));
ok('le NIP vendeur est nu dans le document', str_contains($xml, '<NIP>8971902620</NIP>'));
ok('le genre est VAT', str_contains($xml, '<RodzajFaktury>VAT</RodzajFaktury>'));
ok('la ligne porte sa quantité et son taux',
    str_contains($xml, '<P_8B>2</P_8B>') && str_contains($xml, '<P_12>23</P_12>'));
ok('l\'IBAN part sans ses espaces', str_contains($xml, '<NrRB>PL61109010140000071219812874</NrRB>'));
ok('une facture impayée porte son échéance', str_contains($xml, '<Termin>2026-08-20</Termin>'));
ok('une facture payée porte sa date de paiement à la place',
    str_contains(wsm_ksef_xml($pdo, fv(['paid' => 1])), '<Zaplacono>1</Zaplacono>'));

// Les trois identités possibles de l'acheteur. Se tromper ici fait rejeter le
// document — ou pire, l'accepter au nom de quelqu'un d'autre.
ok('un professionnel polonais est identifié par son NIP',
    str_contains($xml, '<NIP>5252248481</NIP>'));
$part = wsm_ksef_xml($pdo, fv(['buyer_nip' => '', 'buyer_name' => 'Anna Nowak']));
ok('un particulier porte BrakID — ce n\'est pas un défaut de données',
    str_contains($part, '<BrakID>1</BrakID>'));
ok('et pas un NIP vide', !str_contains($part, '<NIP></NIP>'));
$xwdt = wsm_ksef_xml($pdo, $wdt);
ok('un acheteur intracommunautaire porte son pays et son numéro',
    str_contains($xwdt, '<KodUE>DE</KodUE>') && str_contains($xwdt, '<NrVatUE>811569869</NrVatUE>'));
ok('sa ligne est au taux 0', str_contains($xwdt, '<P_12>0</P_12>'));

// Un nom d'entreprise avec une esperluette casse un XML naïf. Le nôtre non.
$amp = wsm_ksef_xml($pdo, fv(['buyer_name' => 'Kowalski & Synowie <sp. z o.o.>']));
$prev = libxml_use_internal_errors(true);
ok('un nom avec & et < reste un XML valide', simplexml_load_string($amp) !== false);
libxml_use_internal_errors($prev);
ok('et l\'esperluette est échappée', str_contains($amp, 'Kowalski &amp; Synowie'));

// Les mentions obligatoires : le schéma exige un choix explicite. Un champ
// absent fait rejeter le document, il ne vaut pas « non ».
foreach (['P_16', 'P_17', 'P_18', 'P_18A', 'P_23'] as $adn) {
    ok("l'annotation $adn est prise explicitement", str_contains($xml, "<$adn>2</$adn>"));
}

// ---- 6. La correction ------------------------------------------------------
echo "\n-- korekta wskazuje pierwotną --\n";
$pdo->exec("DELETE FROM wsm_invoices WHERE number LIKE 'TST/KSEF/%'");
$ins = function (array $c) use ($pdo): int {
    $names = array_keys($c);
    $pdo->prepare('INSERT INTO wsm_invoices (' . implode(',', $names) . ') VALUES ('
                  . implode(',', array_fill(0, count($names), '?')) . ')')->execute(array_values($c));
    return (int) $pdo->lastInsertId();
};
$commun = ['series' => 'tst', 'issued_at' => '2026-08-01', 'sold_at' => '2026-08-01',
           'due_at' => '2026-08-15', 'seller_name' => 'ATELIER WRO01 sp. z o.o.',
           'seller_nip' => '8971902620', 'seller_address' => 'ul. Polna 1, 00-002 Wrocław, PL',
           'buyer_name' => 'Cukiernia Testowa', 'buyer_nip' => '5252248481',
           'buyer_address' => 'ul. Kwiatowa 7, 00-001 Warszawa, PL', 'currency' => 'PLN',
           'total_net' => 16260, 'total_vat' => 3740, 'total_gross' => 20000];

$origId = $ins($commun + ['kind' => 'faktura', 'kind_group' => 'faktura',
                          'number' => 'TST/KSEF/1', 'seq' => 9001]);
$pdo->prepare("INSERT INTO wsm_invoice_items (invoice_id, name, sku, qty, unit_net, unit_gross,
                                              vat_rate, line_net, line_vat, line_gross)
               VALUES (?,?,?,?,?,?,?,?,?,?)")
    ->execute([$origId, 'Czekolada', 'CZE-1', 2, 8130, 10000, 0.23, 16260, 3740, 20000]);

$korId = $ins($commun + ['kind' => 'korekta', 'kind_group' => 'faktura', 'number' => 'TST/KSEF/K1',
                         'seq' => 9002, 'corrects_id' => $origId, 'note' => 'Rabat po sprzedaży']);
$pdo->prepare("INSERT INTO wsm_invoice_items (invoice_id, name, sku, qty, unit_net, unit_gross,
                                              vat_rate, line_net, line_vat, line_gross)
               VALUES (?,?,?,?,?,?,?,?,?,?)")
    ->execute([$korId, 'Korekta do TST/KSEF/1', '', 1, 16260, 20000, 0.23, 16260, 3740, 20000]);

$kor = wsm_invoice_by_id($pdo, $korId);
ok('une correction dont l\'originale existe ne bloque pas', wsm_ksef_blockers($pdo, $kor) === [],
    wsm_ksef_blockers($pdo, $kor));
$xk = wsm_ksef_xml($pdo, $kor);
ok('son genre est KOR', str_contains($xk, '<RodzajFaktury>KOR</RodzajFaktury>'));
ok('elle désigne la facture d\'origine', str_contains($xk, '<NrFaKorygowanej>TST/KSEF/1</NrFaKorygowanej>'));
ok('la raison est reprise', str_contains($xk, '<PrzyczynaKorekty>Rabat po sprzedaży</PrzyczynaKorekty>'));
// L'originale n'est pas encore au registre : c'est NrKSeFN, pas NrKSeF. Se
// tromper de champ fait rejeter la correction.
ok('originale hors registre → NrKSeFN', str_contains($xk, '<NrKSeFN>1</NrKSeFN>'));
ok('et surtout pas NrKSeF', !str_contains($xk, '<NrKSeF>1</NrKSeF>'));

$pdo->prepare("UPDATE wsm_invoices SET ksef_number = ? WHERE id = ?")->execute(['REG-1', $origId]);
$xk2 = wsm_ksef_xml($pdo, wsm_invoice_by_id($pdo, $korId));
ok('originale au registre → NrKSeF + son numéro',
    str_contains($xk2, '<NrKSeF>1</NrKSeF>') && str_contains($xk2, '<NrKSeFFaKorygowanej>REG-1</NrKSeFFaKorygowanej>'));
ok('et plus de NrKSeFN', !str_contains($xk2, '<NrKSeFN>'));

// ---- 7. L'idempotence — un doublon au registre de l'État ne s'efface pas ----
echo "\n-- numer KSeF zapisuje się RAZ --\n";
$idemId = $ins($commun + ['kind' => 'faktura', 'kind_group' => 'faktura',
                          'number' => 'TST/KSEF/2', 'seq' => 9003]);
ok('le premier marquage écrit', wsm_ksef_mark($pdo, $idemId, 'przyjeta', 'KSEF-AAA') === true);
ok('le SECOND ne réécrit rien', wsm_ksef_mark($pdo, $idemId, 'przyjeta', 'KSEF-BBB') === false);
$after = $pdo->query("SELECT ksef_number, ksef_status FROM wsm_invoices WHERE id = $idemId")->fetch();
ok('et le numéro d\'origine tient', $after['ksef_number'] === 'KSEF-AAA', $after);
ok('un refus ne peut pas venir après une acceptation',
    wsm_ksef_mark($pdo, $idemId, 'odrzucona') === false);
ok('le statut reste « przyjeta »',
    (string) $pdo->query("SELECT ksef_status FROM wsm_invoices WHERE id = $idemId")->fetchColumn() === 'przyjeta');

$attId = $ins($commun + ['kind' => 'faktura', 'kind_group' => 'faktura',
                         'number' => 'TST/KSEF/3', 'seq' => 9004]);
ok('un état sans numéro se réécrit librement', wsm_ksef_mark($pdo, $attId, 'oczekuje') === true);
ok('et encore', wsm_ksef_mark($pdo, $attId, 'blad', '', 'awaria sieci') === true);
ok('un état inconnu retombe sur « blad » plutôt que d\'écrire n\'importe quoi',
    wsm_ksef_mark($pdo, $attId, 'wymyslony') === true
    && (string) $pdo->query("SELECT ksef_status FROM wsm_invoices WHERE id = $attId")->fetchColumn() === 'blad');

// ---- 8. La file et ses compteurs ------------------------------------------
echo "\n-- kolejka i liczniki --\n";
$file = wsm_ksef_queue($pdo);
$nums = array_map(fn($x) => (string) $x['inv']['number'], $file);
ok('une facture déjà au registre n\'est plus dans la file', !in_array('TST/KSEF/2', $nums, true), $nums);
ok('celle qui attend y est bien', in_array('TST/KSEF/3', $nums, true), $nums);

$rej = $ins($commun + ['kind' => 'faktura', 'kind_group' => 'faktura', 'number' => 'TST/KSEF/4',
                       'seq' => 9005, 'ksef_status' => 'odrzucona']);
$nums2 = array_map(fn($x) => (string) $x['inv']['number'], wsm_ksef_queue($pdo));
ok('une facture refusée ne revient pas en boucle — la relancer telle quelle la ferait refuser à l\'identique',
    !in_array('TST/KSEF/4', $nums2, true), $nums2);

// Les compteurs sont calculés SUR LA FILE, pas sur une seconde requête : deux
// requêtes avec deux limites donnent deux vérités, et c'est le bouton qui ment.
$k = wsm_ksef_kpis($pdo, $file);
ok('prêts + bloqués = la file affichée', $k['gotowe'] + $k['zablokowane'] === count($file), $k);
$mainKwota = 0;
foreach ($file as $x) if (!$x['blockers']) $mainKwota += (int) $x['inv']['total_gross'];
ok('et le montant ne compte que les prêts', $k['kwota'] === $mainKwota, $k);
ok('les acceptées sont comptées à part', $k['przyjete'] >= 1, $k);
ok('les refusées aussi', $k['odrzucone'] >= 1, $k);

$petite = array_slice($file, 0, 1);
$kp = wsm_ksef_kpis($pdo, $petite);
ok('une file réduite donne des compteurs réduits — ils décrivent CE QU\'ON VOIT',
    $kp['gotowe'] + $kp['zablokowane'] === 1, $kp);

// Une troncature MUETTE se lit comme une couverture complète : deux cents
// lignes affichées sur trois cent soixante, et le jour de la bascule il en
// manque cent soixante que personne n'a jamais vues.
ok('le total hors registre est rendu à part de la file affichée',
    $kp['wszystkie'] >= count($file), $kp);
ok('et il compte bien TOUT ce qui est hors registre',
    $kp['wszystkie'] === wsm_ksef_poza_rejestrem($pdo));
$plafond = wsm_ksef_queue($pdo, 1);
ok('une limite basse tronque vraiment la file', count($plafond) === 1, count($plafond));
ok('mais ne fait pas mentir le total',
    wsm_ksef_kpis($pdo, $plafond)['wszystkie'] === wsm_ksef_poza_rejestrem($pdo));

// ---- 9. L'envoi fermé, et qui explique --------------------------------------
echo "\n-- wysyłka przy zamkniętym kanale --\n";
$r = wsm_ksef_run($pdo, 'test', $file);
ok('rien ne part', $r['wyslane'] === 0, $r);
ok('et le message dit ce qui manque', str_contains($r['message'], 'zamknięty'), $r['message']);
ok('il nomme la clé du ministère', str_contains($r['message'], 'klucza publicznego'), $r['message']);
ok('et il rappelle qu\'on peut déposer à la main', str_contains($r['message'], 'ręcznie'), $r['message']);
$avant = (int) $pdo->query("SELECT COUNT(*) FROM wsm_invoices WHERE ksef_number <> ''")->fetchColumn();
wsm_ksef_run($pdo, 'test', $file);
ok('un envoi fermé n\'écrit AUCUN numéro',
    (int) $pdo->query("SELECT COUNT(*) FROM wsm_invoices WHERE ksef_number <> ''")->fetchColumn() === $avant);

// ---- 10. Le document reste attaché à la facture figée ----------------------
echo "\n-- dokument trzyma się faktury, nie dzisiejszych ustawień --\n";
$fig = fv();
$x1 = wsm_ksef_xml($pdo, $fig, '2026-08-06T10:00:00+02:00');
wsm_config_overlay(['invoice' => ['seller_name' => 'INNA NAZWA sp. z o.o.', 'seller_nip' => '1111111111']]);
$x2 = wsm_ksef_xml($pdo, $fig, '2026-08-06T10:00:00+02:00');
ok('changer la raison sociale AUJOURD\'HUI ne réécrit pas le document', $x1 === $x2);
ok('qui porte toujours le vendeur de la facture', str_contains($x2, 'ATELIER WRO01'));

$nom = wsm_ksef_nazwa_pliku(fv());
ok('le nom de fichier n\'a ni barre oblique ni espace',
    !str_contains($nom, '/') && !str_contains($nom, ' '), $nom);
ok('et garde le numéro reconnaissable', str_contains($nom, 'FV-001-08-26'), $nom);

$pdo->exec("DELETE FROM wsm_invoice_items WHERE invoice_id IN
              (SELECT id FROM wsm_invoices WHERE number LIKE 'TST/KSEF/%')");
$pdo->exec("DELETE FROM wsm_invoices WHERE number LIKE 'TST/KSEF/%'");

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
