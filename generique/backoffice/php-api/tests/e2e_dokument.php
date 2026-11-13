<?php
// ============================================================================
//  e2e_dokument.php — facture ou e-paragon, et QUI décide.
//
//  CE CHOIX PART AU FISC. Il décide de ce qui est déposé au registre national,
//  de ce que le client reçoit, et de qui doit la TVA. Trois façons de le
//  rater, et aucune ne provoque d'erreur visible :
//
//   1. FACTURER UN NUMÉRO REFUSÉ PAR VIES. Le client dit être une entreprise
//      européenne, VIES dit non, et on émet quand même une facture en
//      autoliquidation : la TVA n'est réclamée à personne, et c'est le vendeur
//      qui la paiera au contrôle. La ligne d'origine — « facture si la case
//      est cochée et qu'un NIP est saisi » — faisait exactement ça.
//   2. REFUSER UNE FACTURE DUE. Une société polonaise avec son NIP y a droit ;
//      VIES ne sert à rien pour une vente domestique. Lui rendre un e-paragon
//      « parce qu'aucun appel VIES n'a eu lieu » est un document manquant, et
//      le client le réclamera — en ayant raison.
//   3. ÉMETTRE DEUX FOIS. Le document est numéroté dans une série continue et
//      déposé au registre : un doublon ne se retire pas, il se corrige par un
//      avoir. L'émission doit donc survivre à quatre appels.
//
//  Et la règle de fond : ON NE RELIT JAMAIS VIES AU MOMENT DU DOCUMENT. Le
//  statut est FIGÉ sur la commande à la vente. Un numéro valable en mars et
//  révoqué en juin ne doit pas changer le document de mars.
//
//  Usage :  php tests/e2e_dokument.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/commerce.php';
require_once dirname(__DIR__) . '/invoice.php';
require_once dirname(__DIR__) . '/mail.php';
$pdo = wsm_bootstrap();
wsm_mail_transport(fn(array $m) => [true, '']);

// LE VENDEUR DOIT ÊTRE RENSEIGNÉ, sinon AUCUNE facture ne s'émet — et ce
// n'est pas un détail de test : en production, tant que le numéro de compte
// manque, une commande passée à « wysłane » repart avec « dokument nie
// powstał ». C'est le blocage réel, et la suite le montre plus bas.
wsm_config_overlay(['invoice' => [
    'seller_name'    => 'ATELIER WRO01 sp. z o.o.',
    'seller_nip'     => '8971902620',
    'seller_address' => 'ul. Polna 1, 00-002 Wrocław, PL',
    'iban'           => 'PL61 1090 1014 0000 0712 1981 2874',
]]);

echo "webshop_mrszoko — end-to-end dokument zamówienia (faktura albo paragon)\n\n";

// Un NIP polonais réellement valide (clé de contrôle juste) et un faux.
$NIP_OK  = '5252248481';
$NIP_KO  = '5252248480';
ok('le NIP de référence est bien valide', wsm_valid_nip($NIP_OK));
ok('et le faux est bien refusé', !wsm_valid_nip($NIP_KO));

// ---- 1. La règle, cas par cas ---------------------------------------------
echo "\n-- kto dostaje fakturę, a kto paragon --\n";
$cas = [
    ['VIES a confirmé le numéro',
     ['vat_status' => 'valid', 'vat_eu' => 'DE811569869', 'invoice' => 1], 'faktura'],
    // LE cas qui coûte de l'argent : VIES a dit non.
    ['VIES a REFUSÉ le numéro — repli sur le paragon',
     ['vat_status' => 'invalid', 'vat_eu' => 'DE000000000', 'nip' => $NIP_OK, 'invoice' => 1], 'paragon'],
    ['société polonaise avec un NIP valide',
     ['vat_status' => '', 'nip' => $NIP_OK, 'invoice' => 1], 'faktura'],
    ['NIP polonais invalide — repli',
     ['vat_status' => '', 'nip' => $NIP_KO, 'invoice' => 1], 'paragon'],
    ['facture demandée sans aucun numéro',
     ['vat_status' => '', 'invoice' => 1], 'paragon'],
    ['particulier qui ne demande rien',
     ['vat_status' => '', 'invoice' => 0], 'paragon'],
    // VIES injoignable : on ne devine pas, on retombe sur le NIP.
    ['VIES injoignable, NIP valide — la vente domestique reste facturable',
     ['vat_status' => 'unavailable', 'nip' => $NIP_OK, 'invoice' => 1], 'faktura'],
    // Statut « valid » mais AUCUN numéro écrit : rien à mettre sur le document.
    ['statut « valid » sans numéro écrit ne fabrique pas de facture',
     ['vat_status' => 'valid', 'vat_eu' => '', 'invoice' => 0], 'paragon'],
];
foreach ($cas as [$quoi, $cmd, $attendu]) {
    $r = wsm_invoice_kind_for($cmd);
    ok("$quoi → $attendu", $r['kind'] === $attendu, $r);
    ok("… et la raison est écrite", trim($r['raison']) !== '');
    // LA MÊME COMMANDE, SOUS SA FORME HYDRATÉE. wsm_order_by_id() range le
    // statut VIES sous « vat.status » et non « vat_status » : une règle qui ne
    // lit que la seconde forme marche dans ce test et rate en production.
    // C'est exactement ce qui est arrivé — un numéro refusé par VIES repassait
    // sur la règle du NIP, donc sur une facture.
    $hydrate = $cmd;
    unset($hydrate['vat_status']);
    $hydrate['vat'] = ['status' => $cmd['vat_status'] ?? ''];
    ok("… et pareil sur la commande hydratée", wsm_invoice_kind_for($hydrate)['kind'] === $attendu,
       wsm_invoice_kind_for($hydrate));
}

// ---- 2. La même règle vue par l'émission ----------------------------------
echo "\n-- ta sama zasada, ale przez wystawienie --\n";
$sfx = 'test-dok-' . bin2hex(random_bytes(3));
$mk = function (array $extra) use ($pdo, $sfx): array {
    static $n = 0; $n++;
    $code = strtoupper($sfx) . '-' . $n;
    $pdo->prepare("INSERT INTO wsm_orders (code, access_token, status, paid_at, email, phone,
                     first_name, last_name, company, nip, vat_eu, vat_status, invoice,
                     delivery_method, items_net, items_vat, total_net, total_vat, total_gross,
                     shipping_net, shipping_vat, currency, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$code, bin2hex(random_bytes(8)), 'oplacone', date('Y-m-d H:i:s'),
                   'dok@example.com', '600100200', 'Jan', 'Kowalski',
                   $extra['company'] ?? '', $extra['nip'] ?? '', $extra['vat_eu'] ?? '',
                   $extra['vat_status'] ?? '', $extra['invoice'] ?? 0,
                   'inpost_courier', 10000, 2300, 10000, 2300, 12300, 0, 0, 'PLN',
                   date('Y-m-d H:i:s')]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO wsm_order_items (order_id, product_id, name, qty, unit_net,
                     unit_gross, vat_rate, line_net, line_vat, line_gross)
                   VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$id, 'test-dok', 'Czekolada', 1, 10000, 12300, 0.23, 10000, 2300, 12300]);
    return wsm_order_by_id($pdo, $id);
};

$o1 = $mk(['company' => 'Kowalski sp. z o.o.', 'nip' => $NIP_OK, 'invoice' => 1]);
$d1 = wsm_order_document($pdo, $o1, 'test');
ok('une société polonaise reçoit une FACTURE', ($d1['kind'] ?? '') === 'faktura', $d1['raison'] ?? '');
ok('le document porte un numéro', ($d1['doc']['number'] ?? '') !== '');

$o2 = $mk(['invoice' => 0]);
$d2 = wsm_order_document($pdo, $o2, 'test');
ok('un particulier reçoit un PARAGON', ($d2['kind'] ?? '') === 'paragon', $d2['raison'] ?? '');

$o3 = $mk(['vat_eu' => 'DE000000000', 'vat_status' => 'invalid', 'nip' => $NIP_OK, 'invoice' => 1]);
$d3 = wsm_order_document($pdo, $o3, 'test');
ok('un numéro refusé par VIES retombe sur le PARAGON',
   ($d3['kind'] ?? '') === 'paragon', $d3['raison'] ?? '');

// ---- 3. Deux fois, c'est une fois -----------------------------------------
echo "\n-- dwa razy to jeden raz --\n";
$avant = (int) $pdo->query("SELECT COUNT(*) FROM wsm_invoices")->fetchColumn();
for ($i = 0; $i < 3; $i++) wsm_order_document($pdo, $o1, 'test');
$apres = (int) $pdo->query("SELECT COUNT(*) FROM wsm_invoices")->fetchColumn();
ok('trois appels de plus n\'émettent RIEN de nouveau', $apres === $avant, [$avant, $apres]);
ok('et c\'est toujours le même numéro',
   (wsm_order_document($pdo, $o1, 'test')['doc']['number'] ?? '') === ($d1['doc']['number'] ?? ''));

// Le courrier non plus ne part pas deux fois : la clé d'événement porte le
// numéro du document.
$mails = (int) $pdo->query("SELECT COUNT(*) FROM wsm_messages
                            WHERE event_key = " . $pdo->quote('dok:' . ($d1['doc']['number'] ?? '')))->fetchColumn();
ok('un seul courrier pour ce document, malgré quatre appels', $mails === 1, $mails);

// ---- 4. Le passage à « wysłane » déclenche tout ----------------------------
echo "\n-- przejście na « wysłane » wystawia dokument --\n";
$o4 = $mk(['company' => 'Nowak sp. z o.o.', 'nip' => $NIP_OK, 'invoice' => 1]);
ok('avant l\'expédition, aucun document', wsm_invoice_for_order($pdo, (int) $o4['id']) === null);
$chg = wsm_order_status_set($pdo, (int) $o4['id'], 'wyslane', 'test');
ok('le changement d\'état réussit', $chg['ok'] === true, $chg);
ok('et il a émis le document', ($chg['doc']['number'] ?? '') !== '', $chg['note']);
ok('la note dit ce qui a été fait', str_contains($chg['note'], 'FAKTURA'), $chg['note']);
ok('le document est bien rattaché à la commande',
   (wsm_invoice_for_order($pdo, (int) $o4['id'])['number'] ?? '') === ($chg['doc']['number'] ?? ''));

// Repasser au même état ne réémet rien, et un second passage non plus.
$c2 = wsm_order_status_set($pdo, (int) $o4['id'], 'wyslane', 'test');
ok('repasser à « wysłane » n\'émet pas un second document',
   ($c2['doc']['number'] ?? '') === ($chg['doc']['number'] ?? '') || $c2['doc'] === null, $c2['note']);

// Un état qui n'est pas « wysłane » n'émet rien.
$o5 = $mk(['invoice' => 1, 'nip' => $NIP_OK]);
wsm_order_status_set($pdo, (int) $o5['id'], 'w_realizacji', 'test');
ok('« w realizacji » n\'émet aucun document',
   wsm_invoice_for_order($pdo, (int) $o5['id']) === null);

// ---- 5. Ce que l'état protège ---------------------------------------------
echo "\n-- czego stan pilnuje --\n";
// Une commande annulée ne repart pas — la règle que les deux transporteurs
// portaient chacun dans leur coin, et qui vit maintenant à un seul endroit.
$o6 = $mk(['invoice' => 0]);
wsm_order_status_set($pdo, (int) $o6['id'], 'anulowane', 'test');
$c6 = wsm_order_status_set($pdo, (int) $o6['id'], 'wyslane', 'test');
ok('une commande annulée ne passe pas à « wysłane »', $c6['ok'] === false, $c6);
ok('et rien n\'a été émis pour elle', wsm_invoice_for_order($pdo, (int) $o6['id']) === null);
$st = $pdo->prepare("SELECT status FROM wsm_orders WHERE id = ?");
$st->execute([(int) $o6['id']]);
ok('son état n\'a pas bougé', $st->fetchColumn() === 'anulowane');
// Une commande inexistante ne fabrique rien.
ok('un identifiant inconnu ne fait rien', wsm_order_status_set($pdo, 999999999, 'wyslane')['ok'] === false);

// ---- 6. VIES est RECONSULTÉ avant d'émettre --------------------------------
echo "\n-- VIES sprawdzany ponownie tuż przed wystawieniem --\n";
// LE CAS QUI COÛTE. Un numéro valable à la commande, révoqué avant
// l'expédition : facturer en autoliquidation laisse la TVA à la charge du
// vendeur. Le contrôle doit donc dater de la LIVRAISON, pas de la commande.
require_once dirname(__DIR__) . '/vies.php';
$o7 = $mk(['company' => 'Müller GmbH', 'vat_eu' => 'DE811569869',
           'vat_status' => 'valid', 'invoice' => 1]);
ok('à la commande, le numéro est marqué valide',
   strtolower((string) ($o7['vat']['status'] ?? '')) === 'valid', $o7['vat'] ?? []);

// VIES répond « révoqué » au moment de l'expédition.
wsm_vies_transport(fn(string $c, string $n) => [200, ['isValid' => false,
    'name' => '', 'address' => '', 'requestIdentifier' => 'WAPIAAAAX0000']]);
$chg7 = wsm_order_status_set($pdo, (int) $o7['id'], 'wyslane', 'test');
$apres7 = wsm_order_by_id($pdo, (int) $o7['id']);
ok('le statut de la commande a été RÉÉCRIT par la consultation du jour',
   strtolower((string) ($apres7['vat']['status'] ?? '')) === 'invalid', $apres7['vat'] ?? []);
ok('et le document émis est un PARAGON, pas une facture',
   (wsm_invoice_for_order($pdo, (int) $o7['id'])['kind'] ?? '') === 'paragon', $chg7['note']);
ok('la date de contrôle est celle du jour',
   str_starts_with((string) ($apres7['vat']['checked_at'] ?? ''), date('Y-m-d')),
   $apres7['vat']['checked_at'] ?? '');
ok('le numéro de consultation est gardé — c\'est LUI la preuve en contrôle',
   trim((string) ($apres7['vat']['consultation'] ?? '')) !== '', $apres7['vat'] ?? []);

// Une panne de VIES ne doit RIEN effacer ni bloquer l'expédition.
$o8 = $mk(['company' => 'Müller GmbH', 'vat_eu' => 'DE811569869',
           'vat_status' => 'valid', 'invoice' => 1]);
wsm_vies_transport(fn(string $c, string $n) => [503, null]);
$chg8 = wsm_order_status_set($pdo, (int) $o8['id'], 'wyslane', 'test');
$apres8 = wsm_order_by_id($pdo, (int) $o8['id']);
ok('VIES en panne : l\'expédition passe quand même', $chg8['ok'] === true, $chg8);
ok('et le statut d\'avant n\'est PAS effacé',
   strtolower((string) ($apres8['vat']['status'] ?? '')) === 'valid', $apres8['vat'] ?? []);
ok('le document reste une facture', (wsm_invoice_for_order($pdo, (int) $o8['id'])['kind'] ?? '') === 'faktura',
   $chg8['note']);

// Une commande sans numéro UE ne déclenche aucune consultation.
$appels = 0;
wsm_vies_transport(function (string $c, string $n) use (&$appels) { $appels++; return [503, null]; });
$o9 = $mk(['invoice' => 0]);
wsm_order_status_set($pdo, (int) $o9['id'], 'wyslane', 'test');
ok('sans numéro UE, VIES n\'est pas dérangé', $appels === 0, $appels);

// ---- 7. Un e-paragon ne va JAMAIS au registre national ---------------------
echo "\n-- paragon nie idzie do KSeF --\n";
// Déposer un e-paragon inscrirait au registre un document qui n'existe pas
// pour le fisc. On ne s'en remet pas au refus de KSeF : on n'y va pas.
$d2b = wsm_order_document($pdo, $o2, 'test');
ok('aucune tentative KSeF pour un paragon', ($d2b['ksef'] ?? '') === '', $d2b['ksef'] ?? '');
$d1b = wsm_order_document($pdo, $o1, 'test');
ok('pour une facture, le canal est nommé (ouvert ou fermé)',
   ($d1b['ksef'] ?? '') !== '', $d1b['ksef'] ?? '');

// ---- 8. Les quatre interrupteurs -------------------------------------------
echo "\n-- cztery wyzwalacze --\n";
// LE DÉFAUT DOIT ÊTRE « TOUT ACTIVÉ », et lu dans le code. Une base vide, une
// table absente, un déploiement à moitié fait : le comportement doit rester
// celui d'hier. Un réglage manquant ne peut pas éteindre une obligation légale.
wsm_config_overlay(['orders' => []]);
$d0 = wsm_orders_cfg();
ok('sans rien de configuré, tout est activé',
   $d0['doc_status'] === 'wyslane' && $d0['doc_mail'] && $d0['doc_ksef'] && $d0['vies_recheck'], $d0);
// Seul un « 0 » explicite coupe : ni le vide, ni « xxxx », ni un mot inconnu.
foreach (['', 'xxxx', 'tak', 'zzz'] as $v) {
    wsm_config_overlay(['orders' => ['doc_ksef' => $v]]);
    ok("« $v » ne coupe PAS le KSeF — seul un refus explicite coupe", wsm_orders_cfg()['doc_ksef'], $v);
}
foreach (['0', 'nie', 'false', 'off'] as $v) {
    wsm_config_overlay(['orders' => ['doc_ksef' => $v]]);
    ok("« $v » coupe bien", !wsm_orders_cfg()['doc_ksef'], $v);
}
// Un état inconnu retombe sur « wysłane », jamais sur « rien ».
wsm_config_overlay(['orders' => ['doc_status' => 'jakiś-stan']]);
ok('un état inconnu retombe sur « wysłane », pas sur le silence',
   wsm_orders_cfg()['doc_status'] === 'wyslane', wsm_orders_cfg()['doc_status']);

// L'ÉTAT QUI ÉMET SE DÉPLACE VRAIMENT.
wsm_config_overlay(['orders' => ['doc_status' => 'dostarczone']]);
$oA = $mk(['invoice' => 0]);
wsm_order_status_set($pdo, (int) $oA['id'], 'wyslane', 'test');
ok('avec « dostarczone » réglé, « wysłane » n\'émet plus rien',
   wsm_invoice_for_order($pdo, (int) $oA['id']) === null);
wsm_order_status_set($pdo, (int) $oA['id'], 'dostarczone', 'test');
ok('… et c\'est « dostarczone » qui émet',
   (wsm_invoice_for_order($pdo, (int) $oA['id'])['number'] ?? '') !== '');

// « nigdy » coupe l'automat entièrement.
wsm_config_overlay(['orders' => ['doc_status' => 'nigdy']]);
$oB = $mk(['invoice' => 0]);
foreach (['wyslane', 'dostarczone', 'oplacone'] as $st2) wsm_order_status_set($pdo, (int) $oB['id'], $st2, 'test');
ok('« nigdy » n\'émet sur AUCUN état', wsm_invoice_for_order($pdo, (int) $oB['id']) === null);

// Le courrier coupé n'empêche pas le document — il ne l'envoie pas.
wsm_config_overlay(['orders' => ['doc_status' => 'wyslane', 'doc_mail' => '0']]);
$oC = $mk(['invoice' => 0]);
$dC = wsm_order_document($pdo, $oC, 'test');
ok('courrier coupé : le document existe quand même', ($dC['doc']['number'] ?? '') !== '');
ok('… mais rien n\'est parti', $dC['mail'] === false);

// KSeF coupé : la facture existe, et l'écran DIT que c'est volontaire.
wsm_config_overlay(['orders' => ['doc_status' => 'wyslane', 'doc_ksef' => '0']]);
$oD = $mk(['company' => 'Kowalski sp. z o.o.', 'nip' => $NIP_OK, 'invoice' => 1]);
$dD = wsm_order_document($pdo, $oD, 'test');
ok('KSeF coupé : la facture est bien émise', ($dD['doc']['number'] ?? '') !== '');
ok('… et le message dit que l\'automat est éteint, pas qu\'il est cassé',
   str_contains((string) $dD['ksef'], 'wyłączony'), $dD['ksef']);

// REMETTRE LES RÉGLAGES D'APLOMB — et pas avec « orders => [] ».
//
// wsm_config_overlay() fusionne par array_replace_recursive : un tableau VIDE
// ne remplace rien du tout. La ligne qui prétendait rendre la main aux valeurs
// par défaut laissait donc le courrier et le KSeF coupés pour tout ce qui
// suit — un test écrit après elle aurait mesuré une machine à moitié éteinte
// en croyant la mesurer au repos.
$domyslne = fn() => wsm_config_overlay(['orders' => [
    'doc_status' => 'wyslane', 'doc_mail' => 1, 'doc_ksef' => 1, 'vies_recheck' => 1]]);
$domyslne();

// ---- 5. LE CHEMIN EN BOUTONS, SUR LA LIGNE --------------------------------
//
// Le statut est devenu touchable dans la liste : c'est le geste le plus
// fréquent de la maison, et l'un de ces boutons ÉMET UN DOCUMENT FISCAL. Ce
// qui doit tenir, dans l'ordre du danger :
//
//  · le bouton qui émet PRÉVIENT, et il nomme le document — pas un « Czy na
//    pewno? » que personne ne lit ;
//  · l'étape actuelle n'est pas une action (un bouton qui ne fait rien envoie
//    chercher une panne inexistante) ;
//  · une commande annulée ne propose plus rien : wsm_order_status_set() la
//    refuse, l'écran ne doit pas prétendre le contraire ;
//  · on peut revenir en arrière — un doigt qui glisse se corrige sur place.
echo "\n-- droga zamówienia w przyciskach --\n";

$oE = $mk(['invoice' => 0]);                      // paragon : ni NIP ni TVA UE
$et = wsm_order_etapy($pdo, $oE);
$par = fn(array $l, string $c) => current(array_filter($l, fn($e) => $e['code'] === $c)) ?: [];

// Quatre étapes de colis — préparer, expédier, livrer, plus le point de
// départ — et la sortie. « Opłacone » n'en fait plus partie : c'est l'autre
// axe, celui de l'argent.
ok('les quatre étapes du colis, plus la sortie', count($et) === 5, count($et));
ok('l\'étape actuelle est marquée « teraz », une seule fois',
   count(array_filter($et, fn($e) => $e['etat'] === 'teraz')) === 1,
   array_column($et, 'etat'));
// La commande de test est « opłacone » : l'état s'affiche sous son propre
// nom, à la place du colis — qui n'a pas bougé.
ok('… et il porte le nom de l\'état réel de la commande',
   (current(array_filter($et, fn($e) => $e['etat'] === 'teraz'))['txt'] ?? '') === 'Opłacone',
   array_column($et, 'txt'));
ok('la suivante est mise en avant', ($par($et, 'w_realizacji')['etat'] ?? '') === 'nastepny');
// Revenir en arrière reste possible : un doigt qui glisse se corrige sur
// place. Vu depuis « wysłane », la préparation est une étape passée, donc
// toujours proposée.
$pdo->prepare('UPDATE wsm_orders SET status = ? WHERE id = ?')->execute(['wyslane', (int) $oE['id']]);
$etW = wsm_order_etapy($pdo, wsm_order_by_id($pdo, (int) $oE['id']));
ok('la précédente reste proposée — on doit pouvoir se corriger',
   ($par($etW, 'w_realizacji')['etat'] ?? '') === 'przeszly', array_column($etW, 'etat'));
$pdo->prepare('UPDATE wsm_orders SET status = ? WHERE id = ?')->execute(['oplacone', (int) $oE['id']]);

// Le repère et la question ne se recopient pas : ils suivent le réglage.
$wys = $par($et, 'wyslane');
ok('l\'étape qui émet le document porte son repère', ($wys['doc'] ?? false) === true);
ok('… et elle DEMANDE avant, en nommant le document',
   str_contains($wys['pyt'] ?? '', 'PARAGON') && str_contains($wys['pyt'] ?? '', $oE['code']),
   $wys['pyt'] ?? '');
ok('les autres étapes ne demandent rien',
   ($par($et, 'w_realizacji')['pyt'] ?? 'x') === '' && ($par($et, 'nowe')['pyt'] ?? 'x') === '');

// Une facture : la question doit nommer la facture ET le registre.
$oF = $mk(['company' => 'Kowalski sp. z o.o.', 'nip' => $NIP_OK, 'invoice' => 1]);
$qF = $par(wsm_order_etapy($pdo, $oF), 'wyslane')['pyt'] ?? '';
ok('une facture s\'annonce comme une facture, et dit qu\'elle part au KSeF',
   str_contains($qF, 'FAKTURĘ') && str_contains($qF, 'KSeF'), $qF);

// LE RÉGLAGE COMMANDE, PAS LE CODE. Si le Superadmin déplace l'émission sur
// « dostarczone », c'est CETTE étape-là qui doit prévenir.
wsm_config_overlay(['orders' => ['doc_status' => 'dostarczone']]);
$et2 = wsm_order_etapy($pdo, $oE);
ok('le repère suit le réglage du Superadmin',
   ($par($et2, 'dostarczone')['doc'] ?? false) === true
   && ($par($et2, 'wyslane')['doc'] ?? true) === false,
   array_column($et2, 'doc'));
wsm_config_overlay(['orders' => ['doc_status' => 'nigdy']]);
ok('… et « nigdy » n\'arme plus aucune étape',
   array_filter(wsm_order_etapy($pdo, $oE), fn($e) => $e['doc']) === []);
$domyslne();

// Le document déjà émis : plus rien à confirmer, ce clic ne fabrique rien.
wsm_order_document($pdo, $oE, 'test');
ok('une fois le document émis, on ne demande plus rien',
   ($par(wsm_order_etapy($pdo, wsm_order_by_id($pdo, (int) $oE['id'])), 'wyslane')['pyt'] ?? 'x') === '');

// Une commande annulée est FIGÉE — et l'écran doit le dire, pas l'inventer.
$oG = $mk(['invoice' => 0]);
$pdo->prepare('UPDATE wsm_orders SET status = ? WHERE id = ?')->execute(['anulowane', (int) $oG['id']]);
$etG = wsm_order_etapy($pdo, wsm_order_by_id($pdo, (int) $oG['id']));
ok('une commande annulée ne propose plus aucune étape',
   count(array_filter($etG, fn($e) => in_array($e['etat'], ['nastepny', 'przeszly', 'dalszy', 'zly'], true))) === 0,
   array_column($etG, 'etat'));
ok('… et le mot affiché est un état, pas un ordre',
   ($par($etG, 'anulowane')['txt'] ?? '') === 'Anulowane');
// La preuve que le verrou n'est pas décoratif : le moteur refuse aussi.
$avant = (string) wsm_order_by_id($pdo, (int) $oG['id'])['status'];
wsm_order_status_set($pdo, (int) $oG['id'], 'wyslane', 'test');
ok('et le moteur refuse pour de bon — l\'écran ne ment pas',
   (string) wsm_order_by_id($pdo, (int) $oG['id'])['status'] === $avant);

// L'ÉCRAN COUPE LA LISTE EN TROIS — l'étape actuelle, la suivante en grand,
// le reste replié sous « Inny status ». Une étape qui tomberait entre deux
// paquets disparaîtrait de l'interface SANS ERREUR : le bouton n'existerait
// plus, et on croirait à une règle métier. On vérifie donc que les trois
// paquets recouvrent exactement le chemin, depuis chaque statut possible.
$oH = $mk(['invoice' => 0]);
$trous = [];
foreach (WSM_ORDER_STATUSES as $s) {
    $pdo->prepare('UPDATE wsm_orders SET status = ? WHERE id = ?')->execute([$s, (int) $oH['id']]);
    $l = wsm_order_etapy($pdo, wsm_order_by_id($pdo, (int) $oH['id']));
    $t = array_filter($l, fn($e) => $e['etat'] === 'teraz');
    $n = array_filter($l, fn($e) => $e['etat'] === 'nastepny');
    $r = array_filter($l, fn($e) => !in_array($e['etat'], ['teraz', 'nastepny'], true));
    if (count($t) + count($n) + count($r) !== count($l) || count($t) > 1 || count($n) > 1) {
        $trous[] = $s . ':' . count($t) . '/' . count($n) . '/' . count($r);
    }
}
ok('les trois paquets de l\'écran recouvrent le chemin, quel que soit le statut',
   $trous === [], $trous);
// Depuis le dernier état il n'y a plus de « suivante » : le grand bouton
// disparaît au lieu de proposer un pas dans le vide.
$pdo->prepare('UPDATE wsm_orders SET status = ? WHERE id = ?')->execute(['dostarczone', (int) $oH['id']]);
ok('arrivé au bout, plus aucune étape n\'est mise en avant',
   array_filter(wsm_order_etapy($pdo, wsm_order_by_id($pdo, (int) $oH['id'])),
                fn($e) => $e['etat'] === 'nastepny') === []);

// ---- 6. L'ARGENT ET LE COLIS SONT DEUX AXES -------------------------------
//
// « Opłacone » était au milieu du chemin des étapes. Comme la plupart des
// commandes sont « nowe », il ressortait en GRAND BOUTON sur presque chaque
// ligne : le geste le plus visible de l'écran le plus utilisé proposait,
// quarante fois par jour, quelque chose que personne ne devait faire —
// l'encaissement est écrit par tpay. Et il le faisait FAUX : la commande se
// déclarait payée pendant que payment_status restait « oczekuje ».
echo "\n-- pieniądze i paczka to dwie osie --\n";

$oP = $mk(['invoice' => 0]);
$pdo->prepare('UPDATE wsm_orders SET status = ?, payment_status = ?, paid_at = NULL WHERE id = ?')
    ->execute(['nowe', 'oczekuje', (int) $oP['id']]);
$et = wsm_order_etapy($pdo, wsm_order_by_id($pdo, (int) $oP['id']));
ok('le chemin ne propose plus « Opłacone » comme étape',
   !in_array('oplacone', array_column($et, 'code'), true), array_column($et, 'code'));
ok('… et l\'étape suivante d\'une commande neuve est la préparation',
   (current(array_filter($et, fn($e) => $e['etat'] === 'nastepny'))['code'] ?? '') === 'w_realizacji');

// Une commande payée par tpay est un ÉTAT où l'on peut être : elle s'affiche,
// à sa place, et la suite reste la préparation.
$pdo->prepare('UPDATE wsm_orders SET status = ? WHERE id = ?')->execute(['oplacone', (int) $oP['id']]);
$etO = wsm_order_etapy($pdo, wsm_order_by_id($pdo, (int) $oP['id']));
$terazO = current(array_filter($etO, fn($e) => $e['etat'] === 'teraz')) ?: [];
ok('une commande payée montre « Opłacone » comme état courant',
   ($terazO['txt'] ?? '') === 'Opłacone', $terazO);
ok('… et sa suite est toujours la préparation',
   (current(array_filter($etO, fn($e) => $e['etat'] === 'nastepny'))['code'] ?? '') === 'w_realizacji');

// LE FOND : « payée » n'est pas un mot, c'est de l'argent. Quelle que soit la
// porte, les deux champs et la date bougent ensemble — sinon on expédie sans
// avoir encaissé, le compteur « en attente de paiement » ment, et on relance
// quelqu'un qui a payé.
$oQ = $mk(['invoice' => 0]);
$pdo->prepare('UPDATE wsm_orders SET status = ?, payment_status = ?, paid_at = NULL WHERE id = ?')
    ->execute(['nowe', 'oczekuje', (int) $oQ['id']]);
wsm_order_status_set($pdo, (int) $oQ['id'], 'oplacone', 'test');
$rQ = $pdo->query('SELECT status, payment_status, paid_at FROM wsm_orders WHERE id = ' . (int) $oQ['id'])->fetch();
ok('mettre le statut « opłacone » encaisse VRAIMENT la commande',
   $rQ['payment_status'] === 'oplacone' && !empty($rQ['paid_at']), $rQ);

// Et le colis ne recule pas : un virement encaissé pendant la préparation ne
// doit pas renvoyer la commande dans la file de celui qui vient de la faire.
$oR = $mk(['invoice' => 0]);
$pdo->prepare('UPDATE wsm_orders SET status = ?, payment_status = ?, paid_at = NULL WHERE id = ?')
    ->execute(['w_realizacji', 'oczekuje', (int) $oR['id']]);
wsm_order_mark_paid($pdo, (int) $oR['id'], 'test');
$rR = $pdo->query('SELECT status, payment_status FROM wsm_orders WHERE id = ' . (int) $oR['id'])->fetch();
ok('encaisser une commande en préparation ne la fait pas reculer',
   $rR['status'] === 'w_realizacji' && $rR['payment_status'] === 'oplacone', $rR);

// Le cas ordinaire de tpay ne change pas : payée avant préparation, la
// commande passe bien à « opłacone ».
$oS = $mk(['invoice' => 0]);
$pdo->prepare('UPDATE wsm_orders SET status = ?, payment_status = ?, paid_at = NULL WHERE id = ?')
    ->execute(['nowe', 'oczekuje', (int) $oS['id']]);
wsm_order_mark_paid($pdo, (int) $oS['id'], 'stripe');
$rS = $pdo->query('SELECT status, payment_status FROM wsm_orders WHERE id = ' . (int) $oS['id'])->fetch();
ok('… mais une commande neuve payée passe bien à « opłacone »',
   $rS['status'] === 'oplacone' && $rS['payment_status'] === 'oplacone', $rS);

// ---- 7. CE QUE LA LISTE MONTRE DOIT ÊTRE CE QUI VA SE PASSER --------------
//
// LE DÉFAUT LE PLUS COÛTEUX DE CET ÉCRAN, et il ne provoquait aucune erreur :
// wsm_orders_list() ne chargeait ni vat_eu ni vat_status. Les voyants et
// l'annonce du document lisent EXACTEMENT ces champs-là. Une commande B2B au
// numéro confirmé par VIES s'affichait donc « VIES — brak numeru VAT UE » et
// annonçait un PARAGON… pendant que l'émission, qui travaille sur la commande
// complète, produisait une FACTURE déposée au registre national. L'écran
// disait le contraire de ce qu'il allait faire.
echo "\n-- lista pokazuje to, co naprawdę się stanie --\n";

$oL = $mk(['company' => 'Müller GmbH', 'vat_eu' => 'DE811569869', 'vat_status' => 'valid', 'invoice' => 1]);
$ligne = null;
foreach (wsm_orders_list($pdo, 400) as $r) if ($r['code'] === $oL['code']) $ligne = $r;
ok('la commande est bien dans la liste', $ligne !== null);
ok('la liste porte le numéro de TVA et son statut VIES',
   ($ligne['vat_eu'] ?? '') === 'DE811569869' && ($ligne['vat_status'] ?? '') === 'valid', $ligne);
ok('… donc la liste annonce le MÊME document que l\'émission',
   wsm_invoice_kind_for($ligne)['kind'] === wsm_invoice_kind_for($oL)['kind'],
   [wsm_invoice_kind_for($ligne)['kind'], wsm_invoice_kind_for($oL)['kind']]);
ok('… et le voyant VIES dit vrai depuis la liste',
   wsm_order_voyants($pdo, $ligne)['vies']['txt'] === wsm_order_voyants($pdo, $oL)['vies']['txt'],
   wsm_order_voyants($pdo, $ligne)['vies']);

// Les files : un filtre qui laisserait passer autre chose que des états connus
// serait une injection dans un ORDER BY. La liste blanche est le contrôle.
$pdo->prepare('UPDATE wsm_orders SET status = ? WHERE id = ?')->execute(['w_realizacji', (int) $oL['id']]);
$file = wsm_orders_list($pdo, 400, ['w_realizacji']);
ok('la file ne rend que son propre état',
   $file && !array_filter($file, fn($r) => $r['status'] !== 'w_realizacji'), count($file));
ok('un état inventé ne filtre rien plutôt que de casser',
   count(wsm_orders_list($pdo, 5, ["' OR 1=1 --"])) === count(wsm_orders_list($pdo, 5)));

// ---- 8. LE CONTRÔLE D'AVANT-EXPÉDITION ------------------------------------
//
// Il décide ce qui part et ce qui reste. Les deux cas qui comptent : celui où
// tout est là, et celui où la facture ne peut pas naître — aujourd'hui ça se
// découvre APRÈS le clic, colis déjà expédié.
echo "\n-- lista kontrolna przed wysyłką --\n";

$pf = wsm_order_preflight($pdo, $oL);
ok('le contrôle nomme le document qui sera émis', $pf['kind'] === 'faktura', $pf['kind']);
ok('… et il est expédiable quand les données du vendeur sont là', $pf['gotowe'], $pf['blok']);
$vus = array_column($pf['lignes'], 'co');
ok('les points qui décident y sont tous',
   in_array('Zapłata', $vus, true) && in_array('VIES', $vus, true)
   && in_array('Dokument', $vus, true) && in_array('Mail', $vus, true) && in_array('KSeF', $vus, true), $vus);
ok('la ligne VIES porte le geste qui la relit',
   ($pf['lignes'][1]['agir'] ?? '') === 'vies', $pf['lignes'][1] ?? null);

// LE BLOCAGE. Sans numéro de compte, la facture ne peut pas naître : la
// commande ne doit pas être expédiable, et le trou doit être NOMMÉ.
wsm_config_overlay(['invoice' => ['iban' => '']]);
$pfB = wsm_order_preflight($pdo, $oL);
ok('sans numéro de compte, la commande n\'est pas expédiable', !$pfB['gotowe']);
ok('… et le manque est nommé, pas seulement signalé',
   str_contains(implode(' ', $pfB['blok']), 'rachunku'), $pfB['blok']);
// La preuve que le blocage n'est pas décoratif : l'émission échoue vraiment.
$dB = wsm_order_document($pdo, $mk(['company' => 'Zorza', 'nip' => $NIP_OK, 'invoice' => 1]), 'test');
ok('et l\'émission refuse pour de bon — l\'écran ne bloque pas dans le vide',
   ($dB['doc'] ?? null) === null, $dB['raison'] ?? '');
wsm_config_overlay(['invoice' => ['iban' => 'PL61 1090 1014 0000 0712 1981 2874']]);

// Un e-paragon n'a pas besoin des données du vendeur pour partir.
$oPar = $mk(['invoice' => 0]);
wsm_config_overlay(['invoice' => ['iban' => '']]);
$pfP = wsm_order_preflight($pdo, $oPar);
ok('un paragon reste expédiable sans les données de facturation',
   $pfP['gotowe'] && $pfP['kind'] === 'paragon', [$pfP['kind'], $pfP['blok']]);
wsm_config_overlay(['invoice' => ['iban' => 'PL61 1090 1014 0000 0712 1981 2874']]);

// Les voyants ne refont plus le travail des étapes : « Do wysyłki » et
// « Wysłane » y étaient les mêmes gestes sous d'autres noms.
$vy = wsm_order_voyants($pdo, $oF);
ok('les voyants informent, ils ne doublent plus les étapes',
   array_keys($vy) === ['vies', 'dok'], array_keys($vy));

// ---- ménage ---------------------------------------------------------------
$ids = $pdo->query("SELECT id FROM wsm_orders WHERE code LIKE '" . strtoupper($sfx) . "%'")
           ->fetchAll(PDO::FETCH_COLUMN) ?: [];
foreach ($ids as $i) {
    $pdo->prepare("DELETE FROM wsm_invoice_items WHERE invoice_id IN
                   (SELECT id FROM wsm_invoices WHERE order_id = ?)")->execute([(int) $i]);
    $pdo->prepare("DELETE FROM wsm_invoices WHERE order_id = ?")->execute([(int) $i]);
    $pdo->prepare("DELETE FROM wsm_messages WHERE order_id = ?")->execute([(int) $i]);
    $pdo->prepare("DELETE FROM wsm_order_items WHERE order_id = ?")->execute([(int) $i]);
    $pdo->prepare("DELETE FROM wsm_orders WHERE id = ?")->execute([(int) $i]);
}


// ── LE TICKET : ventilation par taux ────────────────────────────────────────
//
// Un paragon s'imprimait dans la mise en page d'une FACTURE : une pleine
// feuille A4 pour deux lignes d'achat, avec des colonnes et un cadre de
// paiement qui ne le concernent pas. Il a maintenant sa forme de ticket, et
// la seule vraie logique de cette page est le regroupement par taux — ce
// qu'un ticket porte, et ce qu'un comptable y cherche.
echo "\n-- paragon: rozbicie PTU wedlug stawek --\n";
$art = fn(float $taux, int $brut, int $vat) => ['vat_rate' => $taux, 'line_gross' => $brut, 'line_vat' => $vat];

$g = wsm_paragon_ptu([$art(0.23, 12300, 2300), $art(0.23, 5000, 935), $art(0.05, 1000, 48)]);
ok('deux taux donnent deux groupes', count($g) === 2, array_keys($g));
ok('23 % porte la lettre A', isset($g['A']) && abs($g['A']['taux'] - 0.23) < 1e-9);
ok('5 % porte la lettre C', isset($g['C']) && abs($g['C']['taux'] - 0.05) < 1e-9);
ok('les lignes d\'un même taux sont ADDITIONNÉES', $g['A']['brut'] === 17300, $g['A']['brut']);
ok('… la TVA aussi', $g['A']['vat'] === 3235, $g['A']['vat']);
ok('les groupes sortent triés', array_keys($g) === ['A', 'C'], array_keys($g));

// Les quatre lettres de la convention polonaise.
//
// EN PAIRES, PAS EN TABLEAU ASSOCIATIF : PHP convertit une clé flottante en
// entier, donc [0.23 => 'A', 0.08 => 'B', …] s'effondre sur la clé 0 et seule
// la dernière survit. Écrit ainsi, ce test n'en vérifiait qu'un sur quatre —
// et il passait au vert.
foreach ([[0.23, 'A'], [0.08, 'B'], [0.05, 'C'], [0.0, 'D']] as [$t, $lettre]) {
    $u = wsm_paragon_ptu([$art((float) $t, 1000, 0)]);
    ok(sprintf('%s %% → lettre %s', wsm_vat_percent((float) $t), $lettre), isset($u[$lettre]), array_keys($u));
}
// UN TAUX INCONNU NE DISPARAÎT PAS. Une ligne sans groupe serait une vente
// absente du total des taux : le ticket cesserait de s'additionner, et rien
// ne le dirait.
$x = wsm_paragon_ptu([$art(0.17, 5000, 727)]);
ok('un taux hors barème est rangé, pas perdu', count($x) === 1 && reset($x)['brut'] === 5000, $x);
// Le total des groupes doit égaler le total du document, sinon le pied ment.
$tout = wsm_paragon_ptu([$art(0.23, 12300, 2300), $art(0.08, 5400, 400), $art(0.0, 900, 0)]);
ok('la somme des groupes fait le brut total',
   array_sum(array_column($tout, 'brut')) === 12300 + 5400 + 900);
ok('… et la somme des TVA fait la TVA totale',
   array_sum(array_column($tout, 'vat')) === 2300 + 400);
ok('un document sans ligne ne produit aucun groupe', wsm_paragon_ptu([]) === []);

echo "\n" . ($fail === 0 ? "OK" : "FAILED") . " — $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
