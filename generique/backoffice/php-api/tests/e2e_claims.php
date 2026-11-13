<?php
// ============================================================================
//  e2e_claims.php — réclamations, rétractations, et liens tracés.
//
//  CE QUI COÛTE LE PLUS CHER ICI : le silence. Le droit polonais donne
//  QUATORZE JOURS pour répondre à une réclamation ; passé ce délai sans
//  réponse, elle est réputée ACCEPTÉE. Ne rien faire coûte donc le produit —
//  et c'est la seule règle du logiciel où l'inaction a un prix.
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. UN REMBOURSEMENT NE DÉPASSE JAMAIS CE QUI A ÉTÉ PAYÉ, même sur une
//      faute de frappe. Sans cette borne, on rend de l'argent jamais reçu.
//   2. LE COMPTEUR DE JOURS EXISTE, ET IL DEVIENT NÉGATIF. Un retard masqué
//      n'est pas un retard annulé.
//   3. L'URGENCE COMMANDE LE TRI, pas la date d'ouverture.
//   4. LE DÉLAI DE RÉTRACTATION SE COMPTE DEPUIS LA DATE LA PLUS TARDIVE
//      DONT ON EST SÛR — compter depuis la commande volerait des jours.
//   5. DEUX DOSSIERS OUVERTS SUR LE MÊME SUJET, JAMAIS.
//   6. UNE DEMANDE NE SE SUPPRIME PAS.
//   7. UN LIEN COMPTE SES CLICS ET SES VENTES SÉPARÉMENT, et ne promet
//      jamais un code de réduction qui n'existe pas.
//
//  Usage :  php tests/e2e_claims.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/claims.php';
require_once dirname(__DIR__) . '/links.php';
require_once dirname(__DIR__) . '/promo.php';
require_once dirname(__DIR__) . '/mail.php';
$pdo = wsm_bootstrap();
wsm_mail_transport(fn(array $m) => [true, '']);
wsm_claims_ensure($pdo);
wsm_links_ensure($pdo);

echo "webshop_mrszoko — end-to-end zgłoszenia i linki\n\n";

$sfx = bin2hex(random_bytes(3));
register_shutdown_function(function () use ($pdo, $sfx) {
    try {
        $pdo->exec("DELETE FROM wsm_claims WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_links WHERE nom LIKE 'test-$sfx%'");
        $pdo->exec("DELETE FROM wsm_vouchers WHERE code LIKE 'LNK$sfx%'");
        $pdo->exec("DELETE FROM wsm_messages WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE order_id IN
                      (SELECT id FROM wsm_orders WHERE email LIKE '%$sfx@example.com')");
        $pdo->exec("DELETE FROM wsm_orders WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_stock_moves WHERE product_id LIKE 'test-cl-$sfx%'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE product_id LIKE 'test-cl-$sfx%'");
        $pdo->exec("DELETE FROM wsm_products WHERE id LIKE 'test-cl-$sfx%'");
    } catch (Throwable $e) { /* le nettoyage ne masque jamais le résultat */ }
});

$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$pid = 'test-cl-' . $sfx;
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible,
                    slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, base_cost)
               VALUES (?,?,?,100.00,'Opublikowany',1,1,?,50,0.23,250,200,150,100,?,5.00)")
     ->execute([$pid, $cat, 'Claim ' . $sfx, $pid, strtoupper($sfx)]);

$mail = "cl.$sfx@example.com";
[$o, $eo] = wsm_shop_create_order($pdo, [
    'items' => [['id' => $pid, 'qty' => 1]], 'lang' => 'pl',
    'delivery_method' => 'inpost_courier', 'inpost_point' => 'KRA010',
    'email' => $mail, 'phone' => '600100200',
    'first_name' => 'Jan', 'last_name' => 'Kowalski', 'client_type' => 'osoba',
    'ship_street' => 'Testowa', 'ship_building' => '1', 'ship_postcode' => '00-001',
    'ship_city' => 'Warszawa', 'ship_country' => 'PL', 'consent_terms' => true,
]);
ok('la commande de départ passe', $o !== null, $eo);
$paye = (int) $o['total_gross'];

// ---- 1. L'ouverture ---------------------------------------------------------------------
echo "-- otwarcie zgłoszenia --\n";
[$idZero, $mZero] = wsm_claim_open($pdo, (int) $o['id'], 'reklamacja', 'krótko');
ok('un motif de trois mots est refusé', $idZero === 0, $mZero);
ok('et le message dit combien il en faut', str_contains($mZero, '10'), $mZero);
ok('un type inconnu est refusé', wsm_claim_open($pdo, (int) $o['id'], 'cokolwiek', 'Opis wystarczająco długi tutaj')[0] === 0);
ok('une commande inconnue est refusée', wsm_claim_open($pdo, 999999, 'reklamacja', 'Opis wystarczająco długi tutaj')[0] === 0);

[$cid, $mOpen] = wsm_claim_open($pdo, (int) $o['id'], 'reklamacja',
    'Tabliczka dotarła stopiona i posklejana, opakowanie było rozerwane w dwóch miejscach.');
ok('une demande valide est ouverte', $cid > 0, $mOpen);
$c = wsm_claim_get($pdo, $cid);
ok('elle porte un numéro RK-AAAA-NNN', (bool) preg_match('/^RK-\d{4}-\d{3}$/', (string) $c['numer']), $c['numer']);
ok('le montant payé est FIGÉ dessus', (int) $c['paid_gross'] === $paye, [$c['paid_gross'], $paye]);
ok('elle est reliée à la commande', (string) $c['order_code'] === (string) $o['code']);
ok('et elle est « nowa »', (string) $c['statut'] === 'nowa');

// Règle 5 : jamais deux dossiers ouverts sur le même sujet.
[$cid2, $m2] = wsm_claim_open($pdo, (int) $o['id'], 'reklamacja', 'Piszę drugi raz o tej samej sprawie.');
ok('un second dossier du même type renvoie le premier', $cid2 === $cid, [$cid, $cid2]);
ok('et le dit', str_contains($m2, 'już'), $m2);
// Un type DIFFÉRENT, en revanche, est un autre sujet.
[$cid3] = wsm_claim_open($pdo, (int) $o['id'], 'zwrot', 'Chcę odstąpić od umowy, towar nieużywany.');
ok('un dossier d\'un AUTRE type est bien un autre dossier', $cid3 > 0 && $cid3 !== $cid, [$cid, $cid3]);

// Le dossier doit atterrir dans la messagerie : c'est là qu'on répond.
$st = $pdo->prepare("SELECT COUNT(*) FROM wsm_messages WHERE email = ? AND direction = 'wejscie'");
$st->execute([$mail]);
ok('le dossier entre dans la messagerie', (int) $st->fetchColumn() >= 1);

// ---- 2. Le compteur de jours ------------------------------------------------------------
echo "\n-- licznik dni --\n";
ok('quatorze jours pour répondre', WSM_CLAIM_ODPOWIEDZ_DNI === 14);
ok('le compteur est présent et positif à l\'ouverture',
   (int) $c['reponse_reste'] >= 13 && (int) $c['reponse_reste'] <= 14, $c['reponse_reste']);
ok('et la date limite est écrite', (string) $c['reponse_limite'] !== '', $c['reponse_limite']);
ok('le silence ne vaut pas encore acceptation', $c['milczenie_zgoda'] === false);

// On vieillit le dossier de vingt jours : le compteur doit passer NÉGATIF.
$pdo->prepare("UPDATE wsm_claims SET created_at = ? WHERE id = ?")
    ->execute([date('Y-m-d H:i:s', time() - 20 * 86400), $cid]);
$vieux = wsm_claim_get($pdo, $cid);
ok('vingt jours plus tard le compteur est NÉGATIF', (int) $vieux['reponse_reste'] < 0, $vieux['reponse_reste']);
ok('et le silence vaut désormais acceptation — c\'est la loi', $vieux['milczenie_zgoda'] === true);

// ---- 3. Le tri met l'urgence devant --------------------------------------------------------
echo "\n-- pilne idą pierwsze --\n";
$liste = wsm_claims_list($pdo, 'otwarte');
$rangs = [];
foreach ($liste as $i => $x) $rangs[(int) $x['id']] = $i;
ok('le dossier en retard passe devant le dossier neuf',
   ($rangs[$cid] ?? 99) < ($rangs[$cid3] ?? -1), $rangs);
$restes = array_map(fn($x) => (int) $x['reponse_reste'], $liste);
$trie = $restes; sort($trie);
ok('les restes sont bien croissants — le plus urgent d\'abord', $restes === $trie, $restes);

// ---- 4. Le remboursement est BORNÉ ----------------------------------------------------------
echo "\n-- zwrot nigdy nie przekracza zapłaconego --\n";
[$okU, $mU] = wsm_claim_update($pdo, $cid, 'uznana', 'Wysyłamy nową tabliczkę.', $paye * 3, 'test');
ok('la mise à jour réussit', $okU === true, $mU);
$c = wsm_claim_get($pdo, $cid);
ok('le remboursement est rogné au montant payé', (int) $c['refund_gross'] === $paye, [$c['refund_gross'], $paye]);
ok('et le message AVERTIT que la somme a été limitée', str_contains($mU, 'ograniczona'), $mU);
ok('il ne reste plus rien à rembourser', (int) $c['remboursable'] === 0, $c['remboursable']);

// Un second remboursement ne peut plus rien rendre.
[$ok2, $m2b] = wsm_claim_update($pdo, $cid, 'zakonczona', 'Sprawa zamknięta.', 5000, 'test');
$c = wsm_claim_get($pdo, $cid);
ok('un second remboursement ne rend rien de plus', (int) $c['refund_gross'] === $paye, $c['refund_gross']);
ok('la clôture est datée', trim((string) $c['resolved_at']) !== '', $c['resolved_at']);
ok('un montant négatif est traité comme zéro',
   wsm_claim_update($pdo, $cid3, 'w_toku', 'Sprawdzamy.', -5000, 'test')[0] === true);
ok('et n\'a rien rendu', (int) wsm_claim_get($pdo, $cid3)['refund_gross'] === 0);

// Règle 6 : la ligne SUBSISTE.
$st = $pdo->prepare("SELECT COUNT(*) FROM wsm_claims WHERE id = ?");
$st->execute([$cid]);
ok('le dossier clos SUBSISTE — une preuve ne se détruit pas', (int) $st->fetchColumn() === 1);

// ---- 5. Le délai de rétractation part de la date la plus tardive ------------------------------
echo "\n-- termin odstąpienia liczy się od najpóźniejszej pewnej daty --\n";
ok('quatorze jours de rétractation', WSM_CLAIM_ZWROT_DNI === 14);
$r = wsm_claim_zwrot_reste($pdo, $o);
ok('sans livraison connue, on part du paiement ou de la création',
   in_array($r['base'], ['zapłata', 'utworzenie'], true), $r);
ok('et il reste des jours', (int) $r['jours'] > 0, $r['jours']);

// Une livraison notée AUJOURD'HUI doit RALLONGER le délai, jamais le raccourcir.
$vieilleCmd = $o;
$vieilleCmd['created_at'] = date('Y-m-d H:i:s', time() - 10 * 86400);
$avant = wsm_claim_zwrot_reste($pdo, $vieilleCmd);
wsm_order_event($pdo, (int) $o['id'], 'dostarczone', 'test', 'test');
$apres = wsm_claim_zwrot_reste($pdo, $vieilleCmd);
ok('une livraison notée aujourd\'hui RALLONGE le délai',
   (int) $apres['jours'] >= (int) $avant['jours'], [$avant['jours'], $apres['jours']]);
ok('et la base devient « dostawa »', $apres['base'] === 'dostawa', $apres['base']);

// ---- 6. Les compteurs d'écran -----------------------------------------------------------------
echo "\n-- liczniki ekranu --\n";
$k = wsm_claims_kpis($pdo);
ok('les ouverts sont comptés', $k['otwarte'] >= 1, $k);
ok('les remboursements sont additionnés', $k['zwroty'] >= $paye, $k);

// ---- 7. Les liens tracés -----------------------------------------------------------------------
echo "\n-- linki bezpośrednie --\n";
[$lc0, $lm0] = wsm_link_create($pdo, ['nom' => '', 'cible' => 'sklep'], 'test');
ok('un lien sans nom est refusé', $lc0 === '', $lm0);
ok('et le message explique pourquoi', str_contains($lm0, 'Nazwij'), $lm0);
ok('une cible inconnue est refusée',
   wsm_link_create($pdo, ['nom' => "test-$sfx", 'cible' => 'nigdzie'], 'test')[0] === '');
ok('une cible « produkt » sans produit est refusée',
   wsm_link_create($pdo, ['nom' => "test-$sfx", 'cible' => 'produkt'], 'test')[0] === '');

// Un code de réduction inexistant ne doit JAMAIS être promis.
[$lcBad, $lmBad] = wsm_link_create($pdo, ['nom' => "test-$sfx-bad", 'cible' => 'sklep',
                                          'kod' => 'NIEMATAKIEGOKODU'], 'test');
ok('un lien ne promet pas un code inexistant', $lcBad === '', $lmBad);
ok('et le message nomme le code manquant', str_contains($lmBad, 'NIEMATAKIEGOKODU'), $lmBad);

// Avec un vrai code, ça passe.
wsm_promo_save($pdo, ['code' => 'LNK' . $sfx, 'kind' => 'procent', 'pct' => '10', 'active' => 1], 'test');
[$lc, $lm] = wsm_link_create($pdo, ['nom' => "test-$sfx-ok", 'cible' => 'koszyk',
                                    'produkt' => $pid, 'kod' => 'LNK' . $sfx], 'test');
ok('un lien complet est créé', $lc !== '', $lm);
ok('son code est court et lisible', strlen($lc) >= 4 && strlen($lc) <= 12, $lc);
ok('sans O/0/I/1/l — on le dicte au téléphone', !preg_match('/[O0I1]/', $lc), $lc);

$l = wsm_link_find($pdo, $lc);
ok('on le retrouve par son code', $l !== null && (string) $l['produkt'] === $pid);
// Le code est stocké NORMALISÉ (majuscules) : c'est ce que la caisse
// comparera, et le lien doit porter exactement cette forme-là — sinon il
// promettrait un code que le panier ne reconnaîtrait pas.
ok('et il porte le code de réduction, sous sa forme normalisée',
   (string) $l['kod'] === strtoupper('LNK' . $sfx), $l['kod']);
ok('un code inconnu ne retrouve rien', wsm_link_find($pdo, 'nieistnieje') === null);

// Clics et ventes : DEUX colonnes, jamais un taux.
wsm_link_hit($pdo, $lc);
wsm_link_hit($pdo, $lc);
$vu = null;
foreach (wsm_links_list($pdo) as $x) if ((string) $x['code'] === $lc) $vu = $x;
ok('les clics sont comptés', $vu && (int) $vu['klikniec'] === 2, $vu['klikniec'] ?? null);
ok('les commandes sont comptées à part', $vu && (int) $vu['zamowien'] === 0, $vu['zamowien'] ?? null);

// Une commande venue du lien doit lui être attribuée.
[$oL] = wsm_shop_create_order($pdo, [
    'items' => [['id' => $pid, 'qty' => 1]], 'lang' => 'pl',
    'delivery_method' => 'inpost_courier', 'inpost_point' => 'KRA010',
    'email' => "src.$sfx@example.com", 'phone' => '600100200',
    'first_name' => 'Anna', 'last_name' => 'Nowak', 'client_type' => 'osoba',
    'ship_street' => 'Testowa', 'ship_building' => '2', 'ship_postcode' => '00-001',
    'ship_city' => 'Warszawa', 'ship_country' => 'PL', 'consent_terms' => true,
    'source' => $lc,
]);
ok('la commande passe avec sa source', $oL !== null);
$st = $pdo->prepare("SELECT source FROM wsm_orders WHERE id = ?");
$st->execute([(int) $oL['id']]);
ok('la source est FIGÉE sur la commande', (string) $st->fetchColumn() === $lc);
$vu = null;
foreach (wsm_links_list($pdo) as $x) if ((string) $x['code'] === $lc) $vu = $x;
ok('le lien compte désormais une commande', $vu && (int) $vu['zamowien'] === 1, $vu['zamowien'] ?? null);
ok('mais zéro de chiffre d\'affaires — elle n\'est pas payée',
   $vu && (int) $vu['obrot'] === 0, $vu['obrot'] ?? null);
wsm_order_mark_paid($pdo, (int) $oL['id'], 'test');
$vu = null;
foreach (wsm_links_list($pdo) as $x) if ((string) $x['code'] === $lc) $vu = $x;
ok('une fois payée, le chiffre d\'affaires apparaît', $vu && (int) $vu['obrot'] > 0, $vu['obrot'] ?? null);

// Une source injectée à la main ne doit pas polluer la colonne.
[$oX] = wsm_shop_create_order($pdo, [
    'items' => [['id' => $pid, 'qty' => 1]], 'lang' => 'pl',
    'delivery_method' => 'inpost_courier', 'inpost_point' => 'KRA010',
    'email' => "inj.$sfx@example.com", 'phone' => '600100200',
    'first_name' => 'Ewa', 'last_name' => 'Test', 'client_type' => 'osoba',
    'ship_street' => 'Testowa', 'ship_building' => '3', 'ship_postcode' => '00-001',
    'ship_city' => 'Warszawa', 'ship_country' => 'PL', 'consent_terms' => true,
    'source' => "<script>alert(1)</script>' OR 1=1 --",
]);
$st->execute([(int) $oX['id']]);
$sale = (string) $st->fetchColumn();
ok('une source trafiquée est nettoyée', !preg_match('/[^a-z0-9_.-]/', $sale), $sale);
ok('et bornée en longueur', strlen($sale) <= 40, strlen($sale));

// Le retrait garde l'histoire.
$id = (int) $l['id'];
[$okD, $mD] = wsm_link_disable($pdo, $id, 'test');
ok('le retrait réussit', $okD === true, $mD);
ok('le lien retiré ne répond plus', wsm_link_find($pdo, $lc) === null);
$st2 = $pdo->prepare("SELECT source FROM wsm_orders WHERE id = ?");
$st2->execute([(int) $oL['id']]);
ok('mais la commande GARDE sa source', (string) $st2->fetchColumn() === $lc);

// L'adresse à copier-coller.
ok('l\'adresse porte le paramètre attendu',
   str_contains(wsm_link_url('https://x.pl/mrszoko/shop', 'abc123'), '?l=abc123'),
   wsm_link_url('https://x.pl/mrszoko/shop', 'abc123'));

// ---- 8. L'adresse de la boutique est juste DEPUIS N'IMPORTE OÙ ------------------------------
//  Le lien affiché dans la console pointait sur /backoffice/zgloszenia.php :
//  copié-collé dans une infolettre, il envoyait les clients sur une page de
//  connexion. La règle : la racine du déploiement est ce qui PRÉCÈDE
//  « /backoffice » ou « /shop ».
echo "\n-- adres sklepu jest poprawny z każdej strony konsoli --\n";
$hote = $_SERVER['HTTP_HOST'] ?? null;
$uri  = $_SERVER['REQUEST_URI'] ?? null;
$_SERVER['HTTP_HOST'] = 'sklep.example.pl';
foreach ([
    '/mrszoko/backoffice/api/shop/quote' => 'depuis l\'API',
    '/mrszoko/backoffice/zgloszenia.php' => 'depuis un écran de la console',
    '/mrszoko/backoffice/'               => 'depuis la racine de la console',
    '/mrszoko/shop/koszyk'               => 'depuis la boutique elle-même',
] as $chemin => $quoi) {
    $_SERVER['REQUEST_URI'] = $chemin;
    ok("l'adresse de la boutique est juste $quoi",
       wsm_shop_base_url() === 'http://sklep.example.pl/mrszoko/shop', wsm_shop_base_url());
}
if ($hote === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $hote;
if ($uri === null) unset($_SERVER['REQUEST_URI']); else $_SERVER['REQUEST_URI'] = $uri;

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
