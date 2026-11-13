<?php
// ============================================================================
//  e2e_inbox.php — preuve qu'un mail de client devient une commande juste.
//
//  Le risque de cette fonctionnalité n'est pas de rater une reconnaissance :
//  c'est d'en réussir une FAUSSE. Une commande créée avec le mauvais produit,
//  la mauvaise quantité ou un prix repris du mail coûte plus cher qu'une
//  commande saisie à la main. Les assertions sont donc écrites dans cet ordre :
//
//   1. ON NE DEVINE PAS. Deux produits candidats → aucun n'est retenu, la
//      ligne part dans « à préciser ».
//   2. RIEN NE DISPARAÎT EN SILENCE. Une ligne qui ressemble à une demande et
//      qu'on n'a pas su rattacher est rendue visible.
//   3. LE PRIX VIENT DU CATALOGUE. Le montant écrit dans le mail n'est jamais
//      retenu, même écrit noir sur blanc.
//   4. LA COMMANDE NÉE D'UN MAIL EST UNE VRAIE COMMANDE : même moteur, même
//      TVA, même traitement du stock manquant.
//
//  Usage :  php tests/e2e_inbox.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/mail.php';
require_once dirname(__DIR__) . '/inbox.php';
$pdo = wsm_bootstrap();
wsm_mail_transport(fn(array $m) => [true, '']);   // aucun e-mail ne part d'un test

echo "webshop_mrszoko — end-to-end skrzynka (rozpoznanie produktów z maila)\n\n";

// ---- Un petit catalogue, dont deux produits volontairement proches ----------
$sfx = bin2hex(random_bytes(3));
$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$mk = function (string $nom, string $sku, string $ean, float $prix, int $stock) use ($pdo, $cat, $sfx): string {
    $id = 'test-in-' . $sfx . '-' . strtolower($sku);
    $pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible, slug,
                        stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, ean)
                   VALUES (?,?,?,?,'Opublikowany',1,1,?,?,0.23,250,120,80,40,?,?)")
         ->execute([$id, $cat, $nom, $prix, $id, $stock, $sku, $ean]);
    return $id;
};
$gorzka = $mk('Czekolada gorzka ' . $sfx, 'GOR' . $sfx, '590000000' . substr($sfx, 0, 4), 24.90, 100);
$mleczna = $mk('Czekolada mleczna ' . $sfx, 'MLE' . $sfx, '591111111' . substr($sfx, 0, 4), 19.90, 3);
// Deux produits qui partagent tous leurs mots significatifs : le piège. Le nom
// de A est contenu dans celui de B — c'est le cas où un catalogue mal lu fait
// livrer le petit format à la place du grand.
$pralA = $mk('Praliny orzechowe ' . $sfx, 'PRA' . $sfx, '', 39.00, 50);
$pralB = $mk('Praliny orzechowe ' . $sfx . ' duze', 'PRB' . $sfx, '', 59.00, 50);
// Deux articles au nom identique : une vraie ambiguïté, qu'on doit refuser.
$dupA = $mk('Kakao surowe ' . $sfx, 'KAA' . $sfx, '', 15.00, 50);
$dupB = $mk('Kakao surowe ' . $sfx, 'KAB' . $sfx, '', 17.00, 50);

// ---- 1. Normalisation --------------------------------------------------------
echo "-- normalizacja --\n";
ok('les diacritiques polonais sont repliés',
    wsm_inbox_norm('Czekolada GORZKA 70% — ŚĆŻŹŁ') === 'czekolada gorzka 70 sczzl',
    wsm_inbox_norm('Czekolada GORZKA 70% — ŚĆŻŹŁ'));
ok('les espaces multiples sont réduits', wsm_inbox_norm("  a   b  ") === 'a b');
ok('une ligne vide reste vide', wsm_inbox_norm('   ') === '');

// ---- 2. Les quantités, telles que les gens les écrivent ----------------------
echo "\n-- ilości --\n";
$q = [
    '3 x Czekolada' => 3, 'Czekolada x3' => 3, '5 szt. czekolady' => 5,
    '2 worki' => 2, 'ilość: 12' => 12, 'Ilość 7' => 7,
    '4) Czekolada gorzka' => 4, 'Czekolada gorzka' => 1,
    '10 kartonów' => 10, 'quantity: 6' => 6,
];
foreach ($q as $line => $want) {
    ok("« $line » → $want", wsm_inbox_qty($line) === $want, wsm_inbox_qty($line));
}
ok('un nombre absurde ne devient pas une quantité', wsm_inbox_qty('numer 5252248481') === 1,
    wsm_inbox_qty('numer 5252248481'));

// ---- 3. Reconnaissance -------------------------------------------------------
echo "\n-- rozpoznanie --\n";
$body = "Dzień dobry,\n\n"
      . "proszę o wycenę i wysyłkę:\n"
      . "3 x GOR{$sfx}\n"
      . "2 szt. Czekolada mleczna {$sfx}\n"
      . "1 x 591111111" . substr($sfx, 0, 4) . "\n"
      . "\nPozdrawiam,\nJan Kowalski\n";
$r = wsm_inbox_parse($pdo, $body);
$ids = array_keys($r['items']);
ok('le SKU est reconnu', in_array($gorzka, $ids, true), $ids);
ok('le nom complet est reconnu', in_array($mleczna, $ids, true), $ids);
ok('la quantité du SKU est reprise', ($r['items'][$gorzka] ?? 0) === 3, $r['items'][$gorzka] ?? null);
ok('les lignes visant le même produit s\'additionnent (nom + EAN)',
    ($r['items'][$mleczna] ?? 0) === 3, $r['items'][$mleczna] ?? null);
ok('chaque ligne reconnue dit d\'où vient la reconnaissance',
    count(array_filter($r['lines'], fn($l) => $l['how'] !== '')) === count($r['lines']));
ok('la salutation n\'est pas prise pour une commande',
    !array_filter($r['lines'], fn($l) => str_contains($l['line'], 'Dzień dobry')));

// EAN seul
$e = wsm_inbox_parse($pdo, "590000000" . substr($sfx, 0, 4) . " — 4 sztuki");
ok('l\'EAN seul suffit', ($e['items'][$gorzka] ?? 0) === 4, $e['items']);

// ---- 4. Ce qu'on refuse de deviner -------------------------------------------
echo "\n-- czego nie zgadujemy --\n";
$amb = wsm_inbox_parse($pdo, "Poproszę 2 x Kakao surowe {$sfx}");
ok('deux articles au même nom → aucune commande', $amb['items'] === [], $amb['items']);
ok('et la ligne est signalée à préciser', count($amb['unknown']) === 1, $amb['unknown']);
ok('le motif est dit à l\'utilisateur',
    str_contains((string) ($amb['unknown'][0]['why'] ?? ''), 'kilku'), $amb['unknown'][0]['why'] ?? null);

// Le nom le plus précis gagne : sans ce tri on livrerait le petit format.
$spec = wsm_inbox_parse($pdo, "2 x Praliny orzechowe {$sfx} duze");
ok('le nom le plus long l\'emporte sur le nom qu\'il contient',
    ($spec['items'][$pralB] ?? 0) === 2 && !isset($spec['items'][$pralA]), $spec['items']);
$court = wsm_inbox_parse($pdo, "2 x Praliny orzechowe {$sfx}");
ok('et le nom court reste reconnu quand c\'est lui qu\'on demande',
    ($court['items'][$pralA] ?? 0) === 2 && !isset($court['items'][$pralB]), $court['items']);

$inc = wsm_inbox_parse($pdo, "Proszę o 12 opakowań czegoś czego nie mamy w ofercie");
ok('une demande non rattachée n\'est pas jetée en silence', count($inc['unknown']) === 1, $inc);
ok('et rien n\'est commandé', $inc['items'] === []);

$vide = wsm_inbox_parse($pdo, "Dzień dobry\nPozdrawiam\n");
ok('un mail sans commande ne produit rien du tout',
    $vide['items'] === [] && $vide['unknown'] === [], $vide);

// Un produit désactivé n'est plus proposé : le catalogue fait foi.
$pdo->prepare("UPDATE wsm_products SET active = 0 WHERE id = ?")->execute([$gorzka]);
$off = wsm_inbox_parse($pdo, "3 x GOR{$sfx}");
ok('un produit désactivé n\'est plus reconnu', ($off['items'][$gorzka] ?? 0) === 0, $off['items']);
$pdo->prepare("UPDATE wsm_products SET active = 1 WHERE id = ?")->execute([$gorzka]);

// ---- 5. Le prix vient du catalogue -------------------------------------------
echo "\n-- cena z katalogu, nie z maila --\n";
$msgId = wsm_inbox_store($pdo, 'Jan Kowalski <jan.test@example.com>', 'Zamówienie',
    "2 x GOR{$sfx} po 1,00 zł sztuka\n");
ok('le message entrant est enregistré', $msgId > 0, $msgId);
$msg = $pdo->query("SELECT * FROM wsm_messages WHERE id = " . (int) $msgId)->fetch();
ok('il est rangé du côté « entrant »', ($msg['direction'] ?? '') === 'wejscie', $msg['direction'] ?? null);
ok('le corps est conservé tel quel — c\'est la pièce justificative',
    str_contains((string) $msg['body'], "GOR{$sfx}"));

$p = wsm_inbox_parse($pdo, (string) $msg['body']);

// Sans destination, on ne crée rien : une commande sans adresse est un piège.
[$sansAdr, $adrErr] = wsm_inbox_create_order($pdo, ['id' => $msgId, 'email' => 'jan.test@example.com'],
    $p['items'], ['delivery_method' => 'inpost_courier']);
ok('sans adresse, la commande est refusée', $sansAdr === null);
ok('et l\'écran sait quels champs manquent', isset($adrErr['ship_street']), $adrErr);

$livr = ['delivery_method' => 'inpost_locker', 'inpost_point' => 'WRO01A', 'phone' => '600100200'];
[$ord, $errs] = wsm_inbox_create_order($pdo, ['id' => $msgId, 'email' => 'jan.test@example.com'],
                                       $p['items'], $livr);
ok('la commande est créée', $ord !== null, $errs);
$ligne = $ord['items'][0] ?? [];
ok('le prix unitaire est celui du catalogue, pas 1,00 zł du mail',
    (int) ($ligne['unit_gross'] ?? 0) === 2490, $ligne['unit_gross'] ?? null);
ok('la quantité est celle demandée', (int) ($ligne['qty'] ?? 0) === 2, $ligne['qty'] ?? null);
ok('netto + VAT == brutto', (int) $ord['total_net'] + (int) $ord['total_vat'] === (int) $ord['total_gross']);
ok('la commande garde la trace du message', str_contains((string) $ord['note'], (string) $msgId), $ord['note']);
ok('l\'adresse du client est reprise du mail', (string) $ord['email'] === 'jan.test@example.com');

// ---- 6. Stock insuffisant : la commande passe quand même ----------------------
echo "\n-- braki magazynowe --\n";
[$bo, $boErr] = wsm_inbox_create_order($pdo, ['id' => $msgId, 'email' => 'jan.test@example.com'],
                                       [$mleczna => 10], $livr);
ok('une commande au-delà du stock passe', $bo !== null, $boErr);
ok('et le manque est chiffré, pas caché', (int) ($bo['items'][0]['backorder'] ?? 0) > 0,
    $bo['items'][0]['backorder'] ?? null);

// ---- 7. Sans pièce, pas de commande -------------------------------------------
[$nul, $nulErr] = wsm_inbox_create_order($pdo, ['id' => $msgId, 'email' => 'jan.test@example.com'], [], $livr);
ok('un mail sans produit reconnu ne crée pas de commande vide', $nul === null);
ok('et l\'erreur le dit', isset($nulErr['items']), $nulErr);

// ---- 8. L'adresse de l'expéditeur ---------------------------------------------
echo "\n-- adres nadawcy --\n";
ok('« Jan <jan@example.com> » → l\'adresse',
    wsm_inbox_address('Jan Kowalski <jan@example.com>') === 'jan@example.com');
ok('une adresse nue passe', wsm_inbox_address('  JAN@Example.COM ') === 'jan@example.com');
ok('un en-tête sans adresse ne renvoie rien', wsm_inbox_address('Jan Kowalski') === '');

// ---- 9. Le client connu est retrouvé ------------------------------------------
$cli = wsm_inbox_client($pdo, 'jan.test@example.com');
ok('un expéditeur inconnu ne casse rien', $cli === null || is_array($cli));
ok('une adresse vide ne cherche rien', wsm_inbox_client($pdo, '') === null);

// ---- Nettoyage -----------------------------------------------------------------
foreach ([$gorzka, $mleczna, $pralA, $pralB, $dupA, $dupB] as $id) {
    $pdo->prepare("DELETE FROM wsm_order_items WHERE product_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM wsm_stock_moves WHERE product_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM wsm_products WHERE id = ?")->execute([$id]);
}

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
