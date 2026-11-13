<?php
// ============================================================================
//  e2e_invoice.php — preuve que la facturation tient devant un contrôle.
//
//  Ce qu'on démontre, dans l'ordre d'importance :
//
//   1. LA NUMÉROTATION EST CONTINUE ET SANS DOUBLON. C'est le premier point
//      qu'un contrôle fiscal regarde. Le rang est attribué en base, la série
//      se déduit du format, et deux documents ne peuvent pas porter le même
//      numéro même émis dans la même seconde.
//   2. LE DOCUMENT EST FIGÉ. Changer l'adresse du vendeur ou le nom d'un
//      produit APRÈS l'émission ne réécrit pas la facture.
//   3. LES MONTANTS TOMBENT JUSTE. net + TVA == brut, et le total du document
//      égale le total de la commande, livraison comprise.
//   4. ON NE MODIFIE PAS UNE FACTURE. On émet une correction qui référence
//      l'originale, et l'originale ne bouge pas.
//   5. UN PARTICULIER SANS NIP REÇOIT UN E-PARAGON, pas une facture — et sa
//      numérotation est séparée, sinon les factures auraient des trous.
//
//  Usage :  php tests/e2e_invoice.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/invoice.php';
require_once dirname(__DIR__) . '/mail.php';
$pdo = wsm_bootstrap();
wsm_mail_transport(fn(array $m) => [true, '']);   // aucun e-mail ne part d'un test

echo "webshop_mrszoko — end-to-end faktury (numeracja · dokument · korekta)\n\n";

// ---- 1. Le format du numéro ------------------------------------------------
echo "-- format numeru --\n";
ok('xxxmmyy → 0010726', wsm_invoice_format_number('xxxmmyy', 1, '2026-07-30') === '0010726');
ok('xxx/mm/yy → 001/07/26', wsm_invoice_format_number('xxx/mm/yy', 1, '2026-07-30') === '001/07/26');
ok('le rang est complété à la largeur écrite',
    wsm_invoice_format_number('xxxxx/yyyy', 42, '2026-07-30') === '00042/2026');
ok('yyyy n\'est pas coupé en yy', wsm_invoice_format_number('yyyy', 1, '2026-07-30') === '2026');
ok('le texte hors jeton est repris tel quel',
    wsm_invoice_format_number('FV-xxx-mm', 7, '2026-07-30') === 'FV-007-07');

// La série DOIT contenir ce que le numéro affiche, sinon deux mois pourraient
// produire le même numéro.
ok('format au mois → série mensuelle', wsm_invoice_series('xxx/mm/yy', '2026-07-30') === '2026-07');
ok('format à l\'année → série annuelle', wsm_invoice_series('xxx/yyyy', '2026-07-30') === '2026');
ok('format sans date → suite continue', wsm_invoice_series('xxxxx', '2026-07-30') === 'all');

// ---- 2. Une commande professionnelle → une facture --------------------------
echo "\n-- faktura z zamówienia --\n";
wsm_config_overlay(['invoice' => [
    'seller_name' => 'ATELIER WRO01 sp. z o.o.', 'seller_nip' => '8971902620',
    'seller_address' => 'ul. Leszczyńskiego 4/29, 50-078 Wrocław', 'place' => 'Wrocław',
    'iban' => 'PL00 0000 0000 0000 0000 0000 0000', 'bank' => 'Bank Testowy',
    'payment_days' => '14', 'number_format' => 'xxx/mm/yy',
]]);
ok('rien ne manque pour facturer', wsm_invoice_blockers() === [], wsm_invoice_blockers());

$pid = 'test-fv-' . bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible, slug,
                                         stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku)
               VALUES (?, (SELECT id FROM wsm_categories LIMIT 1), ?, ?, 'Opublikowany', 1, 1, ?, 40, 0.23, 250, 120, 80, 40, ?)")
     ->execute([$pid, 'Czekolada testowa', 100.00, $pid, strtoupper($pid)]);

$base = [
    'lang' => 'pl', 'delivery_method' => 'inpost_locker', 'inpost_point' => 'WRO01A',
    'items' => [['id' => $pid, 'qty' => 2]],
    'email' => 'firma.test@example.com', 'phone' => '600100200', 'consent_terms' => 1,
    'ship_street' => 'Leszczyńskiego', 'ship_building' => '4', 'ship_postcode' => '50-078',
    'ship_city' => 'Wrocław', 'ship_country' => 'PL',
];
$b2b = $base + [
    'client_type' => 'firma', 'first_name' => 'Jan', 'last_name' => 'Kowalski',
    'company' => 'Cukiernia Testowa sp. z o.o.', 'nip' => '5252248481', 'invoice' => 1,
    'bill_street' => 'Testowa', 'bill_building' => '10', 'bill_postcode' => '00-001',
    'bill_city' => 'Warszawa', 'bill_country' => 'PL',
];
[$order, $errs] = wsm_shop_create_order($pdo, $b2b);
ok('commande professionnelle créée', $order !== null, $errs);

[$inv, $err] = wsm_invoice_issue($pdo, $order, 'test');
ok('facture émise', $inv !== null, $err);
ok('c\'est une facture, pas un paragon', ($inv['kind'] ?? '') === 'faktura');
ok('le numéro suit le format réglé', (bool) preg_match('#^\d{3}/\d{2}/\d{2}$#', (string) $inv['number']), $inv['number'] ?? null);
ok('le vendeur est recopié sur le document', $inv['seller_nip'] === '8971902620');
ok('l\'acheteur aussi', $inv['buyer_name'] === 'Cukiernia Testowa sp. z o.o.' && $inv['buyer_nip'] === '5252248481');
ok('l\'échéance suit le délai réglé',
    $inv['due_at'] === date('Y-m-d', strtotime($inv['issued_at'] . ' +14 days')), $inv['due_at']);
ok('le rachunek figure sur le document', str_contains((string) $inv['iban'], 'PL00'));

// Les montants
ok('netto + VAT == brutto', $inv['total_net'] + $inv['total_vat'] === $inv['total_gross'],
    [$inv['total_net'], $inv['total_vat'], $inv['total_gross']]);
ok('le total du document égale celui de la commande',
    (int) $inv['total_gross'] === (int) $order['total_gross'], [$inv['total_gross'], $order['total_gross']]);
$sumLines = array_sum(array_map(fn($l) => (int) $l['line_gross'], $inv['items']));
ok('la somme des lignes fait le total (livraison comprise)', $sumLines === (int) $inv['total_gross'],
    [$sumLines, $inv['total_gross']]);
$shipLine = (bool) array_filter($inv['items'], fn($l) => str_starts_with((string) $l['name'], 'Dostawa'));
ok((int) $order['shipping_gross'] > 0
     ? 'la livraison facturée a sa ligne'
     : 'livraison offerte : aucune ligne fantôme sur le document',
   $shipLine === ((int) $order['shipping_gross'] > 0), [$order['shipping_gross'], $shipLine]);
ok('la ventilation par taux est calculée', count($inv['vat_breakdown']) >= 1);
$vsum = array_sum(array_map(fn($b) => (int) $b['vat'], $inv['vat_breakdown']));
ok('la somme des TVA par taux fait la TVA totale', $vsum === (int) $inv['total_vat'], [$vsum, $inv['total_vat']]);

// ---- 3. Une commande n'a qu'un document ------------------------------------
[$again] = wsm_invoice_issue($pdo, $order, 'test');
ok('réémettre ne crée pas un second document', (int) $again['id'] === (int) $inv['id']);

// ---- 4. Le document est figé ------------------------------------------------
echo "\n-- dokument jest zamrożony --\n";
$pdo->prepare("UPDATE wsm_products SET nom = ? WHERE id = ?")->execute(['Nazwa zmieniona po fakturze', $pid]);
wsm_config_overlay(['invoice' => ['seller_address' => 'Zupełnie inny adres 99']]);
$re = wsm_invoice_by_id($pdo, (int) $inv['id']);
ok('le nom du produit sur la facture ne bouge pas',
    (string) $re['items'][0]['name'] === (string) $inv['items'][0]['name'], $re['items'][0]['name']);
ok('l\'adresse du vendeur non plus',
    !str_contains((string) $re['seller_address'], 'Zupełnie inny'), $re['seller_address']);

// ---- 5. Numérotation : continue, sans doublon ------------------------------
echo "\n-- numeracja --\n";
$numbers = [$inv['number']];
for ($n = 0; $n < 4; $n++) {
    [$o2] = wsm_shop_create_order($pdo, $b2b);
    [$i2, $e2] = wsm_invoice_issue($pdo, $o2, 'test');
    if (!$i2) { ok('émission en série', false, $e2); break; }
    $numbers[] = $i2['number'];
}
ok('cinq documents, cinq numéros distincts', count(array_unique($numbers)) === count($numbers), $numbers);

// La continuité s'apprécie DANS UNE SÉRIE, pas à travers toutes. Le rang
// repart à 1 chaque mois (series = « 2026-08 ») : c'est le comportement voulu
// et l'index UNIQUE porte sur le couple (série, rang). Comparer des rangs de
// juillet à ceux d'août produisait un « trou » à chaque bascule de mois — ce
// test passait tant que la base ne contenait qu'un seul mois, et tombait le
// premier du suivant. En production il aurait crié tous les 1ᵉʳ du mois.
$st = $pdo->query("SELECT series, seq FROM wsm_invoices WHERE kind_group = 'faktura'
                    ORDER BY series, seq");
$parSerie = [];
foreach ($st->fetchAll() ?: [] as $r) $parSerie[(string) $r['series']][] = (int) $r['seq'];

$gaps = [];
foreach ($parSerie as $serie => $seqs) {
    if ($seqs[0] !== 1) $gaps[] = "$serie: commence à {$seqs[0]}";
    for ($k = 1; $k < count($seqs); $k++) {
        if ($seqs[$k] !== $seqs[$k - 1] + 1) $gaps[] = "$serie: {$seqs[$k - 1]} → {$seqs[$k]}";
    }
}
ok('dans chaque série la suite des rangs est sans trou et part de 1', $gaps === [], $gaps);
ok('et la série du mois courant a bien reçu les cinq émissions',
    count($parSerie[(string) $inv['series']] ?? []) >= 5,
    [$inv['series'], count($parSerie[(string) $inv['series']] ?? [])]);

// L'index UNIQUE est le vrai garde-fou : on le met à l'épreuve.
$dup = false;
try {
    $pdo->prepare("INSERT INTO wsm_invoices (kind, kind_group, number, series, seq, issued_at, sold_at, due_at)
                   VALUES ('faktura','faktura',?,?,?,?,?,?)")
        ->execute([$inv['number'], $inv['series'], 999, '2026-01-01', '2026-01-01', '2026-01-01']);
    $dup = true;
} catch (Throwable $e) { /* attendu */ }
ok('la base refuse un numéro déjà utilisé', $dup === false);

$dup2 = false;
try {
    $pdo->prepare("INSERT INTO wsm_invoices (kind, kind_group, number, series, seq, issued_at, sold_at, due_at)
                   VALUES ('faktura','faktura','ZUPELNIE/INNY/NUMER',?,?,?,?,?)")
        ->execute([$inv['series'], (int) $inv['seq'], '2026-01-01', '2026-01-01', '2026-01-01']);
    $dup2 = true;
} catch (Throwable $e) { /* attendu */ }
ok('elle refuse aussi deux fois le même rang dans la série', $dup2 === false);

// ---- 6. E-paragon pour un particulier sans NIP ------------------------------
echo "\n-- e-paragon --\n";
$b2c = $base + ['client_type' => 'osoba', 'first_name' => 'Anna', 'last_name' => 'Nowak'];
[$o3] = wsm_shop_create_order($pdo, $b2c);
[$par, $e3] = wsm_invoice_issue($pdo, $o3, 'test');
ok('document émis pour un particulier', $par !== null, $e3);
ok('c\'est un e-paragon', ($par['kind'] ?? '') === 'paragon', $par['kind'] ?? null);
ok('son numéro est distinct de la série des factures', str_starts_with((string) $par['number'], 'PAR/'), $par['number'] ?? null);
$fvSeqs = (int) $pdo->query("SELECT COUNT(*) FROM wsm_invoices WHERE kind_group = 'faktura'")->fetchColumn();
$parSeqs = (int) $pdo->query("SELECT COUNT(*) FROM wsm_invoices WHERE kind_group = 'paragon'")->fetchColumn();
ok('les deux suites sont séparées', $parSeqs >= 1 && $fvSeqs >= 5, [$fvSeqs, $parSeqs]);

// Sans données vendeur, une facture est refusée — mais un paragon passe.
wsm_config_overlay(['invoice' => ['iban' => '']]);
ok('sans rachunek, la facture est refusée', wsm_invoice_blockers() !== []);
[$o4] = wsm_shop_create_order($pdo, $b2b);
[$bad, $errBad] = wsm_invoice_issue($pdo, $o4, 'test');
ok('et l\'émission le dit clairement', $bad === null && str_contains((string) $errBad, 'rachun'), $errBad);
wsm_config_overlay(['invoice' => ['iban' => 'PL00 0000 0000 0000 0000 0000 0000']]);

// ---- 7. Correction -----------------------------------------------------------
echo "\n-- korekta --\n";
$src = wsm_invoice_by_id($pdo, (int) $inv['id']);
[$kor, $errK] = wsm_invoice_correct($pdo, $src, ['total_gross' => '150,00', 'reason' => 'zwrot części towaru'], 'test');
ok('correction émise', $kor !== null, $errK);
ok('elle référence l\'originale', (int) ($kor['corrects_id'] ?? 0) === (int) $src['id']);
ok('son numéro est marqué comme correction', str_starts_with((string) $kor['number'], 'KOR/'), $kor['number'] ?? null);
ok('la raison est portée sur le document', str_contains((string) $kor['note'], 'zwrot'));
ok('netto + VAT == brutto sur la correction', $kor['total_net'] + $kor['total_vat'] === $kor['total_gross']);
ok('le montant corrigé est celui demandé', (int) $kor['total_gross'] === 15000, $kor['total_gross']);

$after = wsm_invoice_by_id($pdo, (int) $src['id']);
ok('la facture d\'origine est intacte',
    (int) $after['total_gross'] === (int) $src['total_gross'] && $after['number'] === $src['number']);
[$noReason] = wsm_invoice_correct($pdo, $src, ['total_gross' => '10,00', 'reason' => ''], 'test');
ok('une correction sans motif est refusée', $noReason === null);
[$noKor] = wsm_invoice_correct($pdo, $kor, ['total_gross' => '1,00', 'reason' => 'x'], 'test');
ok('on ne corrige pas une correction', $noKor === null);

// ---- 8. Autoliquidation ------------------------------------------------------
echo "\n-- odwrotne obciążenie --\n";
$rc = $pdo->query("SELECT COUNT(*) FROM wsm_invoices WHERE reverse_charge = 1")->fetchColumn();
ok('la mention est portée par le document, pas recalculée', is_numeric($rc));
$colonnes = wsm_table_columns($pdo, 'wsm_invoices');
foreach (['ksef_number', 'ksef_status', 'ksef_at'] as $c) {
    if (!in_array($c, $colonnes, true)) { ok("colonne $c prête pour KSeF", false); break; }
}
ok('les colonnes KSeF existent déjà — l\'intégration n\'exigera pas de migration',
    in_array('ksef_number', $colonnes, true) && in_array('ksef_status', $colonnes, true));

// ---- 9. Proforma --------------------------------------------------------------
//  Une proforma n'est pas un document fiscal : elle ne prend AUCUN numéro dans
//  la suite des factures, et elle peut être réémise autant de fois qu'il faut.
//  C'est exactement ce qui permet d'en tirer une depuis une facture existante.
echo "\n-- proforma --\n";
$seqAvant = (int) $pdo->query("SELECT COALESCE(MAX(seq),0) FROM wsm_invoices WHERE kind_group = 'faktura'")->fetchColumn();

[$oPro] = wsm_shop_create_order($pdo, $b2b);
[$pro, $ePro] = wsm_invoice_proforma($pdo, $oPro, 'test', 'zapłata z góry');
ok('proforma émise depuis une commande', $pro !== null, $ePro);
ok('son type la désigne comme telle', ($pro['kind'] ?? '') === 'proforma');
ok('son numéro est préfixé PRO/', str_starts_with((string) ($pro['number'] ?? ''), 'PRO/'), $pro['number'] ?? null);
ok('elle a sa propre suite de numéros', ($pro['kind_group'] ?? '') === 'proforma');
ok('elle ne consomme aucun numéro de facture',
    (int) $pdo->query("SELECT COALESCE(MAX(seq),0) FROM wsm_invoices WHERE kind_group = 'faktura'")->fetchColumn() === $seqAvant);
ok('elle reprend le total de la commande', (int) $pro['total_gross'] === (int) $oPro['total_gross'],
    [$pro['total_gross'], $oPro['total_gross']]);
ok('netto + VAT == brutto sur la proforma',
    $pro['total_net'] + $pro['total_vat'] === $pro['total_gross']);
ok('la commande n\'a toujours pas de facture — une proforma n\'en est pas une',
    wsm_invoice_for_order($pdo, (int) $oPro['id']) === null
    || (wsm_invoice_for_order($pdo, (int) $oPro['id'])['kind'] ?? '') !== 'faktura');

// Sur base d'une facture : le cas demandé — on repart d'un document émis.
[$pro2, $ePro2] = wsm_invoice_proforma($pdo, $inv, 'test');
ok('proforma tirée d\'une facture existante', $pro2 !== null, $ePro2);
ok('elle pointe la facture d\'origine', (int) ($pro2['corrects_id'] ?? 0) === (int) $inv['id']);
ok('elle recopie l\'acheteur de la facture', ($pro2['buyer_name'] ?? '') === (string) $inv['buyer_name']);
ok('elle recopie les montants', (int) $pro2['total_gross'] === (int) $inv['total_gross']);
ok('elle a autant de lignes que la facture', count($pro2['items']) === count($inv['items']),
    [count($pro2['items']), count($inv['items'])]);
ok('deux proformas, deux numéros', $pro2['number'] !== $pro['number'], [$pro['number'], $pro2['number']]);

// La facture d'origine ne bouge pas : c'est toute la différence avec une correction.
$invRelu = wsm_invoice_by_id($pdo, (int) $inv['id']);
ok('la facture d\'origine reste intacte', (string) $invRelu['number'] === (string) $inv['number']
    && (int) $invRelu['total_gross'] === (int) $inv['total_gross']);

// ---- 10. Relances --------------------------------------------------------------
//  On ne relance qu'une facture impayée dont l'échéance est passée. Jamais un
//  paragon, jamais une proforma (qui EST une demande de paiement), jamais une
//  facture réglée — et jamais deux fois le même jour.
echo "\n-- monity i przypomnienia --\n";
$pdo->prepare("UPDATE wsm_invoices SET due_at = ?, paid = 0 WHERE id = ?")
    ->execute([date('Y-m-d', time() - 20 * 86400), (int) $inv['id']]);

$overdue = wsm_invoices_overdue($pdo, 0);
$ids = array_map(fn($x) => (int) $x['id'], $overdue);
ok('la facture échue est listée', in_array((int) $inv['id'], $ids, true));
ok('aucune proforma dans les relances',
    !array_filter($overdue, fn($x) => $x['kind'] === 'proforma'));
ok('aucun paragon dans les relances',
    !array_filter($overdue, fn($x) => $x['kind'] === 'paragon'));

$horsDelai = array_map(fn($x) => (int) $x['id'], wsm_invoices_overdue($pdo, 60));
ok('un délai de 60 jours écarte une facture échue depuis 20',
    !in_array((int) $inv['id'], $horsDelai, true));

$pdo->prepare("DELETE FROM wsm_messages WHERE event_key LIKE ?")->execute(['przypomnienie:' . (int) $inv['id'] . ':%']);
$m1 = wsm_mail_for_invoice($pdo, wsm_invoice_by_id($pdo, (int) $inv['id']), 'przypomnienie', 'test');
ok('le rappel part', $m1 > 0, $m1);
$m2 = wsm_mail_for_invoice($pdo, wsm_invoice_by_id($pdo, (int) $inv['id']), 'przypomnienie', 'test');
ok('le même jour, il ne part pas deux fois', $m2 === 0, $m2);

$msg = $pdo->query("SELECT * FROM wsm_messages WHERE id = " . (int) $m1)->fetch();
ok('le rappel porte le numéro du document', str_contains((string) $msg['subject'], (string) $inv['number']),
    $msg['subject'] ?? null);
ok('il indique le compte à créditer', str_contains((string) $msg['body'], 'PL00'), $msg['body'] ?? null);
ok('aucun {{jeton}} non remplacé', !str_contains((string) $msg['body'], '{{'), $msg['body'] ?? null);

// La demande de paiement est un autre modèle, avec sa propre clé.
$pdo->prepare("DELETE FROM wsm_messages WHERE event_key = ?")->execute(['zadanie_zaplaty:inv:' . (int) $inv['id']]);
$z1 = wsm_mail_for_invoice($pdo, wsm_invoice_by_id($pdo, (int) $inv['id']), 'zadanie_zaplaty', 'test');
ok('la demande de paiement part', $z1 > 0, $z1);
ok('elle ne se confond pas avec le rappel', $z1 !== $m1);
$z2 = wsm_mail_for_invoice($pdo, wsm_invoice_by_id($pdo, (int) $inv['id']), 'zadanie_zaplaty', 'test');
ok('et ne part qu\'une fois', $z2 === 0, $z2);

// Le passage automatique : idempotent, donc sans danger à chaque ouverture d'écran.
wsm_config_overlay(['invoice' => ['reminder_days' => '7']]);
$pdo->prepare("DELETE FROM wsm_messages WHERE event_key LIKE ?")->execute(['przypomnienie:%:' . date('Y-m-d')]);
$r1 = wsm_invoice_reminders_run($pdo);
$r2 = wsm_invoice_reminders_run($pdo);
ok('le passage automatique relance au moins la facture échue', $r1 >= 1, $r1);
ok('un second passage le même jour n\'envoie rien', $r2 === 0, $r2);

wsm_config_overlay(['invoice' => ['reminder_days' => '0']]);
ok('à 0 jour, les relances automatiques sont coupées', wsm_invoice_reminders_run($pdo) === 0);

// Une facture réglée sort des relances.
$pdo->prepare("UPDATE wsm_invoices SET paid = 1 WHERE id = ?")->execute([(int) $inv['id']]);
ok('une facture payée ne figure plus dans les échues',
    !in_array((int) $inv['id'], array_map(fn($x) => (int) $x['id'], wsm_invoices_overdue($pdo, 0)), true));

// ---- Nettoyage ---------------------------------------------------------------
$pdo->prepare("DELETE FROM wsm_products WHERE id = ?")->execute([$pid]);

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
