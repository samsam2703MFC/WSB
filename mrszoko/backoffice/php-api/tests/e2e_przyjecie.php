<?php
// ============================================================================
//  e2e_przyjecie.php — la facture d'achat devient un PZ, et l'article se
//  raconte de bout en bout.
//
//   1. lire les nombres d'une facture polonaise (1 234,56 · 23% · zw · NIP) ;
//   2. le XML KSeF : exact, sans réseau, entités externes jamais résolues ;
//   3. le rapprochement : mémoire, EAN, code, nom, kilo → sacs ;
//   4. la réception : tout ou rien, liée ligne par ligne, apprise, jamais deux fois ;
//   5. le PDF : Claude remplit un outil, chaque chiffre est recompté ici ;
//   6. l'article : entrées (fournisseur, facture, TVA), sorties (client,
//      commande, WZ, colis), séries des graphiques ;
//   7. les écrans et les droits.
//
//  Usage :  php tests/e2e_przyjecie.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/zakupy.php';
$pdo = wsm_bootstrap();
wsm_zak_ensure($pdo);
$racine = dirname(__DIR__, 4);
$lire = fn(string $rel) => (string) @file_get_contents($racine . '/' . $rel);

echo "webshop_mrszoko — end-to-end przyjęcie z faktury zakupu\n\n";

$sfx = substr(bin2hex(random_bytes(4)), 0, 8);
$NIP_DOST = '5260001246';                                  // NIP de test (somme de contrôle sans importance ici)
$notreNip = (string) (wsm_config()['invoice']['seller_nip'] ?? '');
$avantCle = (string) (wsm_config()['anthropic_api_key'] ?? '');
$fichiers = [];
$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$mk = function (string $nom, string $ean, string $sku, int $weight, string $unite = '') use ($pdo, $cat, $sfx): string {
    $id = 'test-pz-' . $sfx . '-' . substr(md5($nom), 0, 4);
    $pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, base_cost, statut, active, shop_visible, slug, stock,
                        vat_rate, weight_g, length_mm, width_mm, height_mm, sku, ean, unit_label)
                   VALUES (?,?,?,99,0,'Opublikowany',1,1,?,5,0.23,?,100,100,100,?,?,?)")
        ->execute([$id, $cat, $nom, $id, $weight, $sku, $ean, $unite]);
    return $id;
};
// Un EAN propre au test : un EAN d'exemple (5901234123457) existe déjà dans la base de développement.
$EAN = '59' . substr(str_pad((string) crc32($sfx), 11, '7'), 0, 11);
$pA = $mk('Masło kakaowe pastylki 1 kg ' . $sfx, $EAN, 'MK-' . $sfx, 1000);
// 2 600 g d'expédition (emballage compris) : le facteur doit venir des « 2,5 kg » nets, pas de là.
$pB = $mk('Czekolada gorzka Callebaut 811 worek 2,5 kg ' . $sfx, '', 'CB811-' . $sfx, 2600, '2,5 kg');
$pC = $mk('Kakao alkalizowane 22-24% ' . $sfx, '', 'KAK-' . $sfx, 1000);

register_shutdown_function(function () use ($pdo, $sfx, $avantCle, &$fichiers) {
    try {
        $inv = "SELECT id FROM wsm_purchase_invoices WHERE supplier_name LIKE '%$sfx%' OR number LIKE '%$sfx%'";
        $docs = $pdo->query("SELECT doc_id FROM wsm_purchase_invoices WHERE (supplier_name LIKE '%$sfx%' OR number LIKE '%$sfx%') AND doc_id IS NOT NULL")
                    ->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec("DELETE FROM wsm_purchase_lines WHERE invoice_id IN ($inv)");
        $pdo->exec("DELETE FROM wsm_purchase_invoices WHERE supplier_name LIKE '%$sfx%' OR number LIKE '%$sfx%'");
        $pdo->exec("DELETE FROM wsm_purchase_map WHERE product_id LIKE 'test-pz-$sfx%'");
        foreach ($docs as $d) $pdo->exec("DELETE FROM wsm_stock_docs WHERE id = " . (int) $d);
        $pdo->exec("DELETE FROM wsm_stock_moves WHERE product_id LIKE 'test-pz-$sfx%'");
        $ids = "SELECT id FROM wsm_orders WHERE code LIKE 'PZT-$sfx%'";
        $pdo->exec("DELETE FROM wsm_shipments WHERE order_id IN ($ids)");
        $pdo->exec("DELETE FROM wsm_stock_docs WHERE kind = 'WZ' AND order_id IN ($ids)");
        $pdo->exec("DELETE FROM wsm_orders WHERE code LIKE 'PZT-$sfx%'");
        $pdo->exec("DELETE FROM wsm_products WHERE id LIKE 'test-pz-$sfx%'");
    } catch (Throwable $e) { /* le nettoyage ne masque jamais le résultat */ }
    foreach ($fichiers as $f) if (is_file($f)) @unlink($f);
    wsm_config_overlay(['anthropic_api_key' => $avantCle]);
    wsm_zak_pdf_transport(fn() => [null, 'test terminé']);
});
$suivre = function (int $id) use ($pdo, &$fichiers) {
    $inv = wsm_zak_get($pdo, $id);
    if ($inv && ($f = wsm_zak_file($inv))) $fichiers[] = $f;
    return $inv;
};

// ---- 1. Les nombres ----------------------------------------------------------------------------
echo "-- liczby z faktury --\n";
ok('« 1 234,56 » → 123456 grosze', wsm_zak_kwota('1 234,56') === 123456);
ok('« 1.234,56 », « 1234.56 », 12.5 → grosze', wsm_zak_kwota('1.234,56') === 123456 && wsm_zak_kwota('1234.56') === 123456 && wsm_zak_kwota(12.5) === 1250);
ok('vide = absent (null), pas zéro', wsm_zak_kwota('') === null && wsm_zak_kwota(null) === null);
ok('taux : 23% · 0.23 · 8 · 0 KR · zw · np I · oo', wsm_zak_stawka('23%') === '23' && wsm_zak_stawka(0.23) === '23' && wsm_zak_stawka('8')
   === '8' && wsm_zak_stawka('0 KR') === '0' && wsm_zak_stawka('zw') === 'zw' && wsm_zak_stawka('np I') === 'np' && wsm_zak_stawka('oo') === 'np');
ok('un taux inventé (17) n\'est pas un taux', wsm_zak_stawka('17') === '');
ok('NIP : « PL 526-000-12-46 » → 10 chiffres', wsm_zak_nip('PL 526-000-12-46') === '5260001246' && wsm_zak_nip('123') === '');
ok('date : 03.11.2026 → 2026-11-03 ; 31.02 refusé', wsm_zak_data('03.11.2026') === '2026-11-03' && wsm_zak_data('31.02.2026') === '');
$n = wsm_zak_normalize(['pozycje' => [['nazwa' => 'Od brutto', 'ilosc' => 2, 'cena_brutto' => '12,30', 'stawka_vat' => '23']]]);
ok('ligne calculée « od brutto » : le net se déduit (2 × 12,30 brutto → 20,00 netto)', $n['lines'][0]['net'] === 2000 && $n['lines'][0]['gross'] === 2460 && $n['gross'] === 2460, $n['lines'][0]);

// ---- 2. Le XML KSeF ----------------------------------------------------------------------------
echo "\n-- XML z KSeF --\n";
$numer = 'FV/' . $sfx . '/11/2026';
$ksef = function (string $numer, array $wiersze, string $nabywca) use ($NIP_DOST, $sfx): string {
    $w = '';
    foreach ($wiersze as $i => $r) {
        $w .= '<FaWiersz><NrWierszaFa>' . ($i + 1) . '</NrWierszaFa><P_7>' . htmlspecialchars($r[0]) . '</P_7>'
            . ($r[1] !== '' ? '<GTIN>' . $r[1] . '</GTIN>' : '') . '<P_8A>' . $r[2] . '</P_8A><P_8B>' . $r[3] . '</P_8B>'
            . '<P_9A>' . $r[4] . '</P_9A><P_11>' . $r[5] . '</P_11><P_12>' . $r[6] . '</P_12></FaWiersz>';
    }
    return '<?xml version="1.0" encoding="UTF-8"?>
<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/">
  <Naglowek><KodFormularza kodSystemowy="FA (3)" wersjaSchemy="1-0E">FA</KodFormularza></Naglowek>
  <Podmiot1><DaneIdentyfikacyjne><NIP>' . $NIP_DOST . '</NIP><Nazwa>Hurtownia Słodka ' . $sfx . ' Sp. z o.o.</Nazwa></DaneIdentyfikacyjne></Podmiot1>
  <Podmiot2><DaneIdentyfikacyjne><NIP>' . $nabywca . '</NIP><Nazwa>ATELIER WRO01</Nazwa></DaneIdentyfikacyjne></Podmiot2>
  <Fa><KodWaluty>PLN</KodWaluty><P_1>2026-11-02</P_1><P_2>' . $numer . '</P_2>
    <P_13_1>2610.00</P_13_1><P_14_1>600.30</P_14_1><P_15>3210.30</P_15>' . $w . '</Fa>
</Faktura>';
};
$lignesXml = [
    ['Masło kakaowe w pastylkach', $EAN, 'szt', '4', '60.00', '240.00', '23'],
    ['Callebaut 811 czekolada gorzka 54,5% worek', '', 'kg', '25', '84.40', '2110.00', '23'],
    ['Transport', '', 'usł', '1', '260.00', '260.00', '23'],
];
$xml = $ksef($numer, $lignesXml, $notreNip !== '' ? $notreNip : '8971902620');
[$raw, $err] = wsm_zak_from_ksef($xml);
$inv = $raw ? wsm_zak_normalize($raw) : null;
ok('FA(3) lu : vendeur, NIP, numéro, date, 3 lignes', $inv && str_contains($inv['supplier_name'], 'Hurtownia') && $inv['supplier_nip'] === $NIP_DOST
   && $inv['number'] === $numer && $inv['issued_at'] === '2026-11-02' && count($inv['lines']) === 3, $err);
ok('totaux de la facture (P_13 / P_14 / P_15) : 2 610,00 + 600,30 = 3 210,30', $inv && $inv['net'] === 261000 && $inv['vat'] === 60030 && $inv['gross'] === 321030);
ok('ligne : EAN, unité, quantité, prix net, taux, TVA calculée', $inv && $inv['lines'][0]['ean'] === $EAN && $inv['lines'][1]['unit'] === 'kg'
   && $inv['lines'][1]['qty'] === 25.0 && $inv['lines'][1]['unit_net'] === 8440 && $inv['lines'][1]['vat_rate'] === '23' && $inv['lines'][1]['vat'] === 48530);
ok('aucune alerte sur une facture juste', $inv && wsm_zak_checks($inv, $notreNip) === [], $inv ? wsm_zak_checks($inv, $notreNip) : null);
$autre = wsm_zak_normalize(wsm_zak_from_ksef($ksef('X', [$lignesXml[0]], '1234563218'))[0]);
ok('facture adressée à un autre NIP : signalée', (bool) array_filter(wsm_zak_checks($autre, '8971902620'), fn($u) => str_contains($u['tekst'], 'Nabywcą')));
ok('un XML qui n\'est pas une facture KSeF est refusé', wsm_zak_from_ksef('<?xml version="1.0"?><Zamowienie/>')[0] === null);
// XXE : une entité externe ne doit JAMAIS être résolue.
$xxe = '<?xml version="1.0"?><!DOCTYPE Faktura [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
     . '<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"><Fa><P_2>X</P_2><FaWiersz><P_7>A&x;B</P_7><P_8B>1</P_8B><P_11>1</P_11><P_12>23</P_12></FaWiersz></Fa></Faktura>';
[$rx] = wsm_zak_from_ksef($xxe);
ok('entité externe (XXE) jamais résolue : /etc/passwd n\'entre pas', !$rx || !str_contains((string) ($rx['pozycje'][0]['nazwa'] ?? ''), 'root:'));

// ---- 3. Import et rapprochement ----------------------------------------------------------------
echo "\n-- wczytanie i dopasowanie --\n";
[$id, $msg] = wsm_zak_import($pdo, $xml, 'faktura.xml', 'test');
$d = $suivre($id);
$l = $d ? array_column($d['lines'], null, 'lp') : [];
ok('le XML devient un brouillon (szkic), fichier rangé hors du web', $id > 0 && $d['status'] === 'szkic' && $d['source'] === 'ksef'
   && (bool) wsm_zak_file($d) && str_contains((string) wsm_zak_file($d), '/data/zakupy/'), $msg);
ok('ligne 1 : trouvée par l\'EAN', ($l[1]['product_id'] ?? '') === $pA && ($l[1]['dopasowanie'] ?? '') === 'ean');
ok('ligne 2 : trouvée par le nom, 25 kg → 10 sacs de 2,5 kg (facteur 0,4)', ($l[2]['product_id'] ?? '') === $pB && ($l[2]['dopasowanie'] ?? '') === 'nazwa'
   && abs((float) ($l[2]['factor'] ?? 0) - 0.4) < 0.0001 && (int) ($l[2]['stock_qty'] ?? 0) === 10, $l[2] ?? null);
ok('ligne 3 (Transport) : aucun produit proposé', ($l[3]['product_id'] ?? null) === null);
ok('contenu net lu dans « 1 kg », « 2,5 kg », « 4 × 250 g » ; jamais le poids d\'expédition',
   wsm_zak_netto_g(['unit_label' => '1 kg', 'weight_g' => 1050]) === 1000 && wsm_zak_netto_g(['unit_label' => '4 × 250 g']) === 1000
   && wsm_zak_netto_g(['nom' => 'Kakao 2,5 kg']) === 2500 && wsm_zak_netto_g(['nom' => 'Pudełko', 'weight_g' => 300]) === 0);
$deuxEan = ['x1' => ['nom' => 'Ekler czekoladowy', 'ean' => '5900000000017', 'sku' => '', 'weight_g' => 0],
            'x2' => ['nom' => 'Masło kakaowe pastylki', 'ean' => '5900000000017', 'sku' => '', 'weight_g' => 0]];
$sg = wsm_zak_suggest($pdo, 'nip:0', ['name' => 'Masło kakaowe w pastylkach', 'ean' => '5900000000017', 'unit' => 'szt'], $deuxEan);
ok('deux fiches au même EAN : celle au nom le plus proche, avec une confiance moindre', $sg['product_id'] === 'x2' && $sg['score'] < 100, $sg);
$b = wsm_zak_blocages($d);
ok('sans produit ni « pomiń », la ligne bloque la réception', (bool) array_filter($b, fn($t) => str_contains($t, 'Pozycja 3')), $b);
[$dup, $m2] = wsm_zak_import($pdo, $xml, 'encore.xml', 'test');
ok('le même fichier une deuxième fois : on rouvre le brouillon, pas un doublon', $dup === $id && str_contains((string) $m2, 'już wczytany'));
wsm_zak_save($pdo, $id, ['number' => $numer], [(int) $l[2]['id'] => ['product_id' => $pB, 'factor' => '0,3', 'skip' => 0],
                                               (int) $l[3]['id'] => ['product_id' => '', 'skip' => 1]]);
$d = wsm_zak_get($pdo, $id);
ok('un facteur qui donne 7,5 sacs bloque (une sztuka ne se coupe pas)', (bool) array_filter(wsm_zak_blocages($d), fn($t) => str_contains($t, 'Pozycja 2')));
wsm_zak_save($pdo, $id, [], [(int) $l[2]['id'] => ['product_id' => $pB, 'factor' => '0,4', 'skip' => 0]]);
$d = wsm_zak_get($pdo, $id);
ok('corrigé : plus rien ne bloque', wsm_zak_blocages($d) === [], wsm_zak_blocages($d));
ok('30 kg en sacs de 3 kg (facteur 0,3333) : 10 sacs, l\'arrondi n\'est pas une erreur', wsm_zak_stock_qty(30, 0.3333) === [10, false]
   && wsm_zak_stock_qty(25, 0.3)[1] === true);

// ---- 4. La réception ---------------------------------------------------------------------------
echo "\n-- przyjęcie na stan --\n";
$stock = fn(string $p) => (int) $pdo->query("SELECT stock FROM wsm_products WHERE id = " . $pdo->quote($p))->fetchColumn();
[$pz, $e] = wsm_zak_post($pdo, $id, 'test', '2026-11-03');
$d = wsm_zak_get($pdo, $id);
ok('un PZ est créé, au fournisseur et à la facture', $pz && str_starts_with((string) $pz['number'], 'PZ/') && str_contains((string) $pz['partner'], 'Hurtownia')
   && $pz['ref'] === $numer && $pz['issued_at'] === '2026-11-03', $e);
ok('le stock monte : +4 pastilles, +10 sacs (la ligne Transport n\'entre pas)', $stock($pA) === 9 && $stock($pB) === 15, [$stock($pA), $stock($pB)]);
ok('valeur du PZ = net des lignes reçues (240 + 2 110 = 2 350 zł)', (int) ($pz['value'] ?? 0) === 235000, $pz['value'] ?? null);
$lb = array_column($d['lines'], null, 'lp')[2];
$mv = $pdo->query("SELECT * FROM wsm_stock_moves WHERE id = " . (int) $lb['move_id'])->fetch();
ok('chaque ligne est reliée à SON mouvement', $mv && $mv['product_id'] === $pB && (int) $mv['delta'] === 10 && (int) $mv['doc_id'] === (int) $pz['id']);
ok('prix d\'achat net d\'un sac : 2 110,00 / 10 = 211,00 zł', $mv && (int) $mv['unit_cost'] === 21100);
ok('la fiche produit garde le dernier coût d\'achat (marges)', abs((float) $pdo->query("SELECT base_cost FROM wsm_products WHERE id = " . $pdo->quote($pB))->fetchColumn() - 211.0) < 0.001);
ok('la facture est « przyjęta » et pointe son PZ', $d['status'] === 'przyjeta' && (int) $d['doc_id'] === (int) $pz['id'] && $d['posted_at'] !== null);
ok('deuxième clic : refusé, rien ne bouge', wsm_zak_post($pdo, $id, 'test')[0] === null && $stock($pB) === 15);
ok('le PZ retrouve sa facture', (int) (wsm_zak_by_doc($pdo, (int) $pz['id'])['id'] ?? 0) === $id);

// Une autre facture, même numéro, même fournisseur (autre fichier) : doublon.
$xml2 = str_replace('<P_1>2026-11-02</P_1>', '<P_1>2026-11-02</P_1><!-- kopia -->', $xml);
[$id2] = wsm_zak_import($pdo, $xml2, 'kopia.xml', 'test');
$d2 = $suivre($id2);
ok('la même facture (autre fichier) : signalée comme déjà reçue, réception bloquée',
   $d2 && $d2['doublon'] && (bool) array_filter(wsm_zak_blocages($d2), fn($t) => str_contains($t, 'już przyjęta')));
ok('… et le brouillon se rejette (plus rien à recevoir)', wsm_zak_reject($pdo, $id2, 'test')[0] && wsm_zak_get($pdo, $id2)['status'] === 'odrzucona');

// La facture suivante du même fournisseur : le rapprochement est appris.
$xml3 = $ksef('FV/' . $sfx . '/12/2026', [$lignesXml[1]], $notreNip !== '' ? $notreNip : '8971902620');
[$id3] = wsm_zak_import($pdo, $xml3, 'grudzien.xml', 'test');
$d3 = $suivre($id3);
ok('facture suivante : ligne reconnue de mémoire, facteur 0,4 repris', ($d3['lines'][0]['dopasowanie'] ?? '') === 'pamiec'
   && ($d3['lines'][0]['product_id'] ?? '') === $pB && (int) $d3['lines'][0]['stock_qty'] === 10, $d3['lines'][0] ?? null);

// Prise par un autre onglet entre l'affichage et le clic.
$avant = $stock($pB);
$pdo->prepare("UPDATE wsm_purchase_invoices SET status = 'przyjeta' WHERE id = ?")->execute([$id3]);
ok('une facture prise ailleurs (autre onglet) ne se reçoit pas une seconde fois', wsm_zak_post($pdo, $id3, 'test')[0] === null && $stock($pB) === $avant);
$pdo->prepare("UPDATE wsm_purchase_invoices SET status = 'szkic' WHERE id = ?")->execute([$id3]);

// ---- 5. Le PDF ---------------------------------------------------------------------------------
echo "\n-- PDF przez Claude --\n";
$pdf = "%PDF-1.4\n% test " . $sfx . "\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF";
wsm_config_overlay(['anthropic_api_key' => '']);
[$z, $ez] = wsm_zak_import($pdo, $pdf, 'faktura.pdf', 'test');
ok('sans clé Claude : refus clair, qui propose le XML KSeF', $z === 0 && str_contains((string) $ez, 'klucza Claude') && str_contains((string) $ez, 'XML'));
wsm_config_overlay(['anthropic_api_key' => 'sk-test-' . $sfx]);
$appel = [];
wsm_zak_pdf_transport(function (string $bytes, array $tool, string $system) use (&$appel, $sfx) {
    $appel = ['bytes' => $bytes, 'tool' => $tool, 'system' => $system];
    return [[
        'czy_faktura' => true, 'sprzedawca_nazwa' => 'Pakowanie ' . $sfx, 'sprzedawca_nip' => '526-000-12-46', 'nabywca_nip' => '',
        'numer' => 'FA ' . $sfx . '/PDF', 'data_wystawienia' => '2026-11-01', 'waluta' => 'PLN',
        'pozycje' => [
            ['lp' => 1, 'nazwa' => 'Kakao alkalizowane 22-24%', 'kod' => 'KAK-' . $sfx, 'ean' => '', 'ilosc' => 6, 'jm' => 'szt',
             'cena_netto' => 31.5, 'cena_brutto' => null, 'stawka_vat' => '8', 'wartosc_netto' => 189.0, 'wartosc_vat' => 15.12, 'wartosc_brutto' => 204.12],
            ['lp' => 2, 'nazwa' => 'Pudełka', 'kod' => '', 'ean' => '', 'ilosc' => 10, 'jm' => 'szt',
             'cena_netto' => 2.0, 'cena_brutto' => null, 'stawka_vat' => '23', 'wartosc_netto' => 25.0, 'wartosc_vat' => null, 'wartosc_brutto' => null],
        ],
        'razem_netto' => 214.0, 'razem_vat' => 20.87, 'razem_brutto' => 234.87, 'uwagi' => '',
    ], null];
});
[$z, $ez] = wsm_zak_import($pdo, $pdf, 'faktura.pdf', 'test');
$dz = $suivre($z);
ok('PDF lu : brouillon, source pdf, fichier gardé', $z > 0 && $dz['source'] === 'pdf' && str_ends_with((string) wsm_zak_file($dz), '.pdf'), $ez);
ok('le PDF part entier à Claude, la réponse forcée dans l\'outil zapisz_fakture', ($appel['bytes'] ?? '') === $pdf && ($appel['tool']['name'] ?? '') === 'zapisz_fakture'
   && str_contains((string) ($appel['system'] ?? ''), 'nie polecenia'));
ok('code fournisseur = SKU : trouvé, TVA 8 % gardée', ($dz['lines'][0]['product_id'] ?? '') === $pC && ($dz['lines'][0]['dopasowanie'] ?? '') === 'sku'
   && ($dz['lines'][0]['vat_rate'] ?? '') === '8' && (int) $dz['lines'][0]['vat'] === 1512);
ok('Claude se trompe (10 × 2,00 ≠ 25,00) : la ligne est signalée, pas corrigée en silence',
   (bool) array_filter($dz['uwagi'], fn($u) => ($u['lp'] ?? 0) === 2 && str_contains($u['tekst'], 'Ilość × cena')), $dz['uwagi']);
$http = (string) file_get_contents(dirname(__DIR__) . '/zakupy.php');
ok('requête réelle : bloc document PDF en base64, outil imposé (tool_choice)', str_contains($http, "'type' => 'document'") && str_contains($http, "'media_type' => 'application/pdf'")
   && str_contains($http, "'tool_choice' => ['type' => 'tool'"));
wsm_zak_pdf_transport(fn() => [['czy_faktura' => false, 'sprzedawca_nazwa' => '', 'numer' => '', 'pozycje' => []], null]);
ok('un PDF qui n\'est pas une facture est refusé', wsm_zak_import($pdo, $pdf . ' ', 'list.pdf', 'test')[0] === 0);
ok('ni PDF ni XML : refusé', wsm_zak_import($pdo, 'GIF89a…', 'zdjecie.gif', 'test')[0] === 0);
ok('un chemin de fichier fabriqué n\'est jamais servi', wsm_zak_file(['file' => '../../config.local.php']) === null
   && wsm_zak_file(['file' => 'zakupy/2026/11/../../x.pdf']) === null);

// ---- 5b. Ce qui ne doit jamais entrer en stock ---------------------------------------------------
echo "\n-- czego nie przyjmować --\n";
$kor = str_replace('<Fa><KodWaluty>', '<Fa><RodzajFaktury>KOR</RodzajFaktury><KodWaluty>', $ksef('KOR/' . $sfx, [$lignesXml[1]], '8971902620'));
ok('facture corrective KSeF (KOR) : refusée, elle listerait l\'avant ET l\'après', wsm_zak_from_ksef($kor)[0] === null
   && str_contains((string) wsm_zak_from_ksef($kor)[1], 'korygująca'));
$zal = str_replace('<Fa><KodWaluty>', '<Fa><RodzajFaktury>ZAL</RodzajFaktury><KodWaluty>', $ksef('ZAL/' . $sfx, [$lignesXml[1]], '8971902620'));
ok('facture d\'acompte (ZAL) : refusée, rien n\'est livré', wsm_zak_from_ksef($zal)[0] === null);
$avantApres = str_replace('<FaWiersz><NrWierszaFa>1</NrWierszaFa>', '<FaWiersz><NrWierszaFa>1</NrWierszaFa><StanPrzed>1</StanPrzed>',
                          $ksef('SP/' . $sfx, [$lignesXml[0], $lignesXml[2]], '8971902620'));
ok('une ligne « StanPrzed » (état avant) n\'est jamais comptée', count(wsm_zak_from_ksef($avantApres)[0]['pozycje'] ?? []) === 1);
wsm_zak_pdf_transport(fn() => [['czy_faktura' => true, 'rodzaj' => 'korekta', 'sprzedawca_nazwa' => 'X', 'numer' => 'K/1', 'pozycje' => []], null]);
ok('PDF d\'une facture corrective : refusé aussi', wsm_zak_import($pdo, $pdf . 'kor', 'korekta.pdf', 'test')[0] === 0);

// Une ligne à quantité nulle (kaucja) ne bloque pas une fois « pomiń » ; sans numéro, pas de réception.
$xk = $ksef('', [$lignesXml[0], ['Kaucja za paletę', '', 'szt', '0', '0', '0', '23']], '8971902620');
[$ik] = wsm_zak_import($pdo, $xk, 'kaucja.xml', 'test');
$dk = $suivre($ik);
$lk = array_column($dk['lines'], null, 'lp');
ok('sans numéro de facture : réception bloquée (sinon la même facture scannée deux fois passerait)',
   (bool) array_filter(wsm_zak_blocages($dk), fn($t) => str_contains($t, 'numer faktury')));
wsm_zak_save($pdo, $ik, ['number' => 'KAU/' . $sfx], [(int) $lk[2]['id'] => ['product_id' => '', 'skip' => 1]]);
$dk = wsm_zak_get($pdo, $ik);
ok('quantité 0 marquée « pomiń » : plus rien ne bloque', wsm_zak_blocages($dk) === [], wsm_zak_blocages($dk));
[$pzk] = wsm_zak_post($pdo, $ik, 'test');
ok('… et la facture se reçoit', $pzk !== null);
ok('un onglet resté ouvert ne réécrit pas une facture déjà reçue', wsm_zak_save($pdo, $ik, [], [(int) $lk[1]['id'] => ['product_id' => $pC]])[0] === false
   && (string) array_column(wsm_zak_get($pdo, $ik)['lines'], null, 'lp')[1]['product_id'] === $pA);

// Doublon : deux fournisseurs SANS NIP, même numéro, ne se confondent pas.
$sansNip = fn(string $nom) => str_replace(['<NIP>' . $NIP_DOST . '</NIP>', 'Hurtownia Słodka ' . $sfx], ['', $nom . ' ' . $sfx],
                                          $ksef('1/2026', [$lignesXml[0]], '8971902620'));
[$iA] = wsm_zak_import($pdo, $sansNip('Piekarnia A'), 'a.xml', 'test'); $suivre($iA);
wsm_zak_post($pdo, $iA, 'test');
[$iB] = wsm_zak_import($pdo, $sansNip('Młyn B'), 'b.xml', 'test'); $dB = $suivre($iB);
ok('facture « 1/2026 » d\'un autre fournisseur sans NIP : pas prise pour un doublon', $dB && !$dB['doublon'], $dB['doublon'] ?? null);
wsm_zak_reject($pdo, $iB, 'test');

// Une facture en devise : bloquée (les coûts du magasin sont en złote).
$eur = str_replace('<KodWaluty>PLN</KodWaluty>', '<KodWaluty>EUR</KodWaluty>', $ksef('EUR/' . $sfx, [$lignesXml[0]], '8971902620'));
[$ie] = wsm_zak_import($pdo, $eur, 'eur.xml', 'test'); $de2 = $suivre($ie);
ok('facture en EUR : réception bloquée', (bool) array_filter(wsm_zak_blocages($de2), fn($t) => str_contains($t, 'EUR')));
wsm_zak_reject($pdo, $ie, 'test');

// Le facteur appris dépend de l'unité : « 25 kg » ≠ « 10 worek ».
$worek = $ksef('W/' . $sfx, [['Callebaut 811 czekolada gorzka 54,5% worek', '', 'worek', '10', '211.00', '2110.00', '23']], '8971902620');
[$iw] = wsm_zak_import($pdo, $worek, 'worek.xml', 'test'); $dw = $suivre($iw);
ok('même article facturé « 10 worek » : le facteur 0,4 appris pour « kg » n\'est PAS repris',
   ($dw['lines'][0]['dopasowanie'] ?? '') !== 'pamiec' && abs((float) ($dw['lines'][0]['factor'] ?? 0) - 1.0) < 0.0001, $dw['lines'][0] ?? null);
wsm_zak_reject($pdo, $iw, 'test');

// Le coût d'achat : une ligne gratuite (échantillon) ne ramène pas la fiche à 0.
$gratis = $ksef('G/' . $sfx, [['Kakao alkalizowane 22-24% ' . $sfx, '', 'szt', '4', '30.00', '120.00', '8'],
                              ['Kakao alkalizowane 22-24% ' . $sfx . ' próbka', '', 'szt', '1', '0', '0', '8']], '8971902620');
[$ig] = wsm_zak_import($pdo, $gratis, 'gratis.xml', 'test'); $dg = $suivre($ig);
$lg = array_column($dg['lines'], null, 'lp');
wsm_zak_save($pdo, $ig, [], [(int) $lg[1]['id'] => ['product_id' => $pC, 'factor' => '1'], (int) $lg[2]['id'] => ['product_id' => $pC, 'factor' => '1']]);
wsm_zak_post($pdo, $ig, 'test');
ok('coût d\'achat : 30,00 zł gardés, l\'échantillon gratuit ne le met pas à 0',
   abs((float) $pdo->query("SELECT base_cost FROM wsm_products WHERE id = " . $pdo->quote($pC))->fetchColumn() - 30.0) < 0.001);

// Prix au gramme pour un produit de 300 g : le facteur 1/300 tient (8 décimales).
$pD = $mk('Orzechy laskowe 300 g ' . $sfx, '', 'ORZ-' . $sfx, 320, '300 g');
$gram = $ksef('GR/' . $sfx, [['Orzechy laskowe prażone', '', 'g', '3000', '0.05', '150.00', '5']], '8971902620');
[$igr] = wsm_zak_import($pdo, $gram, 'gram.xml', 'test'); $dgr = $suivre($igr);
$lgr = $dgr['lines'][0];
wsm_zak_save($pdo, $igr, [], [(int) $lgr['id'] => ['product_id' => $pD, 'factor' => rtrim(number_format(1 / 300, 8, ',', ''), '0')]]);
$dgr = wsm_zak_get($pdo, $igr);
ok('3 000 g d\'un produit de 300 g : 10 sachets, le facteur 1/300 survit à l\'enregistrement',
   (int) $dgr['lines'][0]['stock_qty'] === 10 && wsm_zak_blocages($dgr) === [], [$dgr['lines'][0]['factor'], wsm_zak_blocages($dgr)]);
wsm_zak_reject($pdo, $igr, 'test');

// ---- 6. L'article de bout en bout ---------------------------------------------------------------
echo "\n-- produkt: skąd przyszedł, dokąd poszedł --\n";
$code = 'PZT-' . $sfx . '-1';
$pdo->prepare("INSERT INTO wsm_orders (code, access_token, email, first_name, last_name, company, lang, status, payment_status,
                    items_net, items_gross, shipping_net, shipping_gross, total_net, total_gross, delivery_method, created_at)
               VALUES (?,?,?,'Ola','Nowak','Cukiernia Ola','pl','wyslane','oplacone',0,0,0,0,0,0,'inpost_locker',?)")
    ->execute([$code, bin2hex(random_bytes(8)), 'ola.' . $sfx . '@example.com', date('Y-m-d H:i:s')]);
$oid = (int) $pdo->lastInsertId();
wsm_stock_apply($pdo, $pB, -3, 'sprzedaz', ['doc' => $code, 'actor' => 'test']);
$pdo->prepare("INSERT INTO wsm_shipments (order_id, carrier, service, tracking_number, status) VALUES (?,?,?,?,?)")
    ->execute([$oid, 'inpost', 'inpost_locker', '6200' . substr(preg_replace('/\D/', '', bin2hex(random_bytes(8))) . '1234567890123', 0, 20), 'w_drodze']);
$ord = ['id' => $oid, 'code' => $code, 'company' => 'Cukiernia Ola', 'first_name' => 'Ola', 'last_name' => 'Nowak',
        'items' => [['qty' => 3, 'line_gross' => 0]]];
[$wz] = wsm_stock_issue_wz($pdo, $ord, 'test');
$r = wsm_stock_product_report($pdo, $pB);
$e0 = $r['entrees'][0] ?? [];
ok('entrée : fournisseur, n° de facture, TVA, net et brutto de la ligne', ($e0['invoice_number'] ?? '') === $numer && ($e0['vat_rate'] ?? '') === '23'
   && (int) ($e0['line_net'] ?? 0) === 211000 && (int) ($e0['line_gross'] ?? 0) === 259530 && str_contains((string) ($e0['supplier'] ?? ''), 'Hurtownia'), $e0);
$s0 = $r['sorties'][0] ?? [];
ok('sortie : client, commande, WZ, numéro de colis et lien de suivi InPost', ($s0['client'] ?? '') === 'Cukiernia Ola' && ($s0['order_code'] ?? '') === $code
   && ($s0['wz'] ?? '') === ($wz['number'] ?? '-') && str_starts_with((string) ($s0['tracking'] ?? ''), '6200')
   && str_starts_with((string) ($s0['tracking_url'] ?? ''), 'https://inpost.pl/sledzenie-przesylek?number='), $s0);
$mois = $r['serie_mois'][date('Y-m')] ?? null;
ok('ce mois-ci : 10 entrés, 3 sortis', $mois && $mois['in'] === 10 && $mois['out'] === 3, $mois);
ok('niveau : départ, chaque mouvement, aujourd\'hui (12 sacs)', count($r['serie_niveau']) >= 3 && end($r['serie_niveau'])['v'] === 12);
ok('… le départ est le stock AVANT la première livraison (5), pas après', $r['serie_niveau'][0]['v'] === 5, $r['serie_niveau'][0]);
ok('prix par livraison : 211,00 zł', ($r['serie_prix'][count($r['serie_prix']) - 1]['v'] ?? 0) === 21100);
ok('KPI : stock, reçu, vendu, coût moyen, dernier fournisseur', $r['kpi']['stock'] === 12 && $r['kpi']['recu'] === 10 && $r['kpi']['vendu'] === 3
   && $r['kpi']['cout_moyen'] === 21100 && str_contains($r['kpi']['dernier_dostawca'], 'Hurtownia'), $r['kpi']);
ok('suivi DPD aussi ; un transporteur inconnu : pas de lien inventé', str_starts_with(wsm_stock_tracking_url('dpd', '0000 1234'), 'https://tracktrace.dpd.com.pl/')
   && wsm_stock_tracking_url('odbiorca', '123') === '');
ok('un produit inconnu : pas de rapport', wsm_stock_product_report($pdo, 'n-existe-pas-' . $sfx) === null);

// ---- 7. Écrans et droits -------------------------------------------------------------------------
echo "\n-- ekrany i uprawnienia --\n";
$auth = $lire('mrszoko/backoffice/php-api/auth.php');
ok('Magazyn reçoit et voit l\'article ; Księgowość lit les factures',
   str_contains($auth, "'przyjecie.php' => 'w', 'magazyn_produkt.php' => 'w'") && str_contains($auth, "'pulpit.php' => 'r', 'przyjecie.php' => 'r'];"));
require_once dirname(__DIR__) . '/roles.php';
ok('« Faktury zakupu » au menu (accordable dans les profils), la page d\'article suit le Magazyn',
   str_contains($lire('mrszoko/backoffice/console.php'), "'przyjecie.php' => 'Faktury zakupu'")
   && in_array('magazyn_produkt.php', wsm_profil_satellites()['magazyn.php'] ?? [], true));
ok('le formulaire de dépôt n\'apparaît qu\'à qui peut écrire sur les factures',
   str_contains($lire('mrszoko/backoffice/magazyn.php'), 'wsm_droit_ecran($me, \'przyjecie.php\') === \'w\''));
ok('schéma : trois tables, MySQL et SQLite', substr_count($lire('mrszoko/backoffice/php-api/schema/webshop_mrszoko.mysql.sql'), 'wsm_purchase_') >= 3
   && substr_count($lire('mrszoko/backoffice/php-api/schema/webshop_mrszoko.sqlite.sql'), 'CREATE TABLE IF NOT EXISTS wsm_purchase_') === 3);

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
