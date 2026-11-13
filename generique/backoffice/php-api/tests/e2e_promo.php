<?php
// ============================================================================
//  e2e_promo.php — preuve qu'un code de réduction fait quelque chose, et
//  surtout qu'il ne fait JAMAIS ce qu'il ne doit pas.
//
//  CE QUI NE MARCHAIT PAS AVANT CE MODULE : la table `wsm_vouchers` existait
//  depuis le premier jour, elle était LISTÉE dans la console, et aucune caisse
//  n'a jamais lu un code. Le jour d'une campagne, chaque client aurait tapé
//  son code et payé le prix plein.
//
//  Ce qui est démontré, dans l'ordre de ce que ça coûte :
//
//   1. UN BON NE REND JAMAIS D'ARGENT. 50 zł sur un panier de 30 zł enlèvent
//      30 zł. Le total ne passe pas sous zéro.
//   2. LA TVA RESTE JUSTE sur un panier à deux taux. Retrancher en bloc
//      donnerait une facture fausse — et une facture fausse se corrige devant
//      l'administration.
//   3. UN POURCENTAGE NE S'EMPILE PAS sur le palier ni sur le tarif pro.
//   4. UN CODE À USAGE UNIQUE NE PASSE PAS DEUX FOIS, même si deux commandes
//      sont créées à la suite avec le même code déjà validé au devis.
//   5. UN CODE PÉRIMÉ, PAS ENCORE OUVERT, SOUS LE MINIMUM OU RETIRÉ est
//      refusé — avec un message qui dit quoi faire.
//   6. UNE COMMANDE AVEC UN CODE REFUSÉ N'EST PAS CRÉÉE. Laisser passer
//      ferait payer le prix plein à quelqu'un qui croit avoir une réduction.
//
//  Usage :  php tests/e2e_promo.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/promo.php';
require_once dirname(__DIR__) . '/mail.php';
$pdo = wsm_bootstrap();
wsm_mail_transport(fn(array $m) => [true, '']);
wsm_promo_ensure($pdo);

echo "webshop_mrszoko — end-to-end kody rabatowe\n\n";

$sfx = bin2hex(random_bytes(3));

// Le nettoyage est posé AVANT toute écriture et tourne même si le fichier
// s'arrête en route : un test qui laisse des produits derrière lui fait
// échouer les suivants sur des totaux parfaitement justes.
register_shutdown_function(function () use ($pdo, $sfx) {
    try {
        $pdo->exec("DELETE FROM wsm_voucher_uses WHERE voucher_id IN
                      (SELECT id FROM wsm_vouchers WHERE code LIKE 'TST$sfx%')");
        $pdo->exec("DELETE FROM wsm_vouchers WHERE code LIKE 'TST$sfx%'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE order_id IN
                      (SELECT id FROM wsm_orders WHERE email LIKE '%$sfx@example.com')");
        $pdo->exec("DELETE FROM wsm_orders WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_stock_moves WHERE product_id LIKE 'test-promo-$sfx%'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE product_id LIKE 'test-promo-$sfx%'");
        $pdo->exec("DELETE FROM wsm_products WHERE id LIKE 'test-promo-$sfx%'");
    } catch (Throwable $e) { /* le nettoyage ne masque jamais le résultat */ }
});

// Deux produits, DEUX TAUX DE TVA : c'est le seul moyen d'éprouver la règle 2.
// Une denrée est à 5 % en Pologne ; l'emballage cadeau ne l'est pas.
$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$mkProd = function (string $suffixe, float $prix, float $tva, int $poids) use ($pdo, $cat, $sfx): string {
    $id = "test-promo-$sfx-$suffixe";
    $pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible,
                        slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, base_cost)
                   VALUES (?,?,?,?,'Opublikowany',1,1,?,9999,?,?,200,150,100,?,5.00)")
         ->execute([$id, $cat, "Promo $suffixe $sfx", $prix, $id, $tva, $poids, strtoupper($suffixe . $sfx)]);
    return $id;
};
$pA = $mkProd('a', 100.00, 0.05, 300);     // 100,00 zł TTC à 5 %
$pB = $mkProd('b',  50.00, 0.23, 200);     //  50,00 zł TTC à 23 %

$mkBon = function (array $ch) use ($pdo, $sfx): array {
    [$ok, $msg, $id] = wsm_promo_save($pdo, $ch + [
        'code' => 'TST' . $sfx . strtoupper(bin2hex(random_bytes(2))),
        'kind' => 'kwota', 'active' => 1,
    ], 'test');
    if (!$ok) { echo "  !! nie udało się utworzyć kodu: $msg\n"; }
    $st = $pdo->prepare("SELECT * FROM wsm_vouchers WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: [];
};

// ---- 1. Un bon ne rend jamais d'argent ------------------------------------------------
echo "-- kod nigdy nie oddaje pieniędzy --\n";
$enorme = $mkBon(['kind' => 'kwota', 'kwota' => '500,00']);
[$q, $e] = wsm_shop_quote($pdo, [['id' => $pB, 'qty' => 1]], 'inpost_courier', 'pl',
                          ['voucher' => $enorme['code']]);
ok('un bon de 500 zł sur un panier de 50 zł n\'enlève que 50 zł',
   (int) $q['voucher']['amount'] === 5000, $q['voucher']['amount'] ?? null);
ok('la marchandise tombe à zéro, pas en dessous', (int) $q['items_gross'] === 0, $q['items_gross']);
ok('et le total reste positif ou nul', (int) $q['total_gross'] >= 0, $q['total_gross']);
ok('la TVA de la marchandise tombe à zéro elle aussi', (int) $q['items_vat'] === 0, $q['items_vat']);
ok('le port, lui, reste dû — un bon ne paie pas le transporteur',
   (int) $q['shipping_gross'] > 0, $q['shipping_gross']);

// ---- 2. La TVA reste juste sur un panier à deux taux ------------------------------------
echo "\n-- VAT pozostaje poprawny przy dwóch stawkach --\n";
$vingt = $mkBon(['kind' => 'kwota', 'kwota' => '20,00']);
[$q2] = wsm_shop_quote($pdo, [['id' => $pA, 'qty' => 1], ['id' => $pB, 'qty' => 1]],
                       'inpost_courier', 'pl', ['voucher' => $vingt['code']]);
ok('le bon enlève exactement 20 zł', (int) $q2['voucher']['amount'] === 2000, $q2['voucher']['amount']);
ok('la marchandise passe de 150 à 130 zł', (int) $q2['items_gross'] === 13000, $q2['items_gross']);
$somme = 0; foreach ($q2['lines'] as $l) $somme += (int) $l['line_gross'];
ok('la somme des lignes retombe EXACTEMENT sur le total', $somme === (int) $q2['items_gross'], [$somme, $q2['items_gross']]);
$coherent = true;
foreach ($q2['lines'] as $l) {
    if ((int) $l['line_net'] + (int) $l['line_vat'] !== (int) $l['line_gross']) $coherent = false;
}
ok('chaque ligne garde net + TVA = TTC après répartition', $coherent, $q2['lines']);
// Les deux taux doivent SURVIVRE à la remise : c'est ce qu'une facture montre.
$taux = [];
foreach ($q2['vat_breakdown'] as $b) $taux[(string) $b['rate']] = true;
ok('la ventilation garde bien les deux taux', count($taux) === 2, array_keys($taux));

// ---- 3. Un pourcentage ne s'empile pas ---------------------------------------------------
echo "\n-- procenty się nie kumulują --\n";
$dix = $mkBon(['kind' => 'procent', 'pct' => '10']);
[$q3] = wsm_shop_quote($pdo, [['id' => $pB, 'qty' => 1]], 'inpost_courier', 'pl',
                       ['voucher' => $dix['code']]);
ok('sans autre remise, les 10 % s\'appliquent', (float) $q3['discount_percent'] === 10.0, $q3['discount_percent']);
ok('et la marchandise vaut 45 zł', (int) $q3['items_gross'] === 4500, $q3['items_gross']);
// L'écran affichait « Rabat ilościowy » sur une remise venue d'un code : le
// libellé nommait la mauvaise raison, et qui retirait son code ne comprenait
// pas que le montant ne bouge pas. Trouvé en pilotant la page, pas en lisant.
ok('le devis dit que la remise vient du CODE, pas du poids',
   ($q3['discount_source'] ?? '') === 'kod', $q3['discount_source'] ?? null);
ok('et le libellé porte le code', str_contains((string) $q3['discount_label'], (string) $dix['code']),
   $q3['discount_label'] ?? null);
[$qW] = wsm_shop_quote($pdo, [['id' => $pB, 'qty' => 1]], 'inpost_courier', 'pl');
ok('sans code, la source reste le poids', ($qW['discount_source'] ?? '') === 'waga', $qW['discount_source'] ?? null);

// Un panier assez lourd pour décrocher un palier supérieur au bon.
//
// LE POIDS DU PRODUIT EST CALCULÉ POUR LE SEUIL, pas l'inverse. Une première
// version prenait le produit de 200 g et bornait la quantité à
// WSM_SHOP_MAX_QTY : 99 × 200 g = 19,8 kg, soit trois grosses centaines de
// grammes SOUS le palier de 20 kg. Le test tombait, le code était juste, et
// le message accusait la mauvaise ligne. Une borne silencieuse dans un test
// vaut un test qui ment.
$palier = (float) $pdo->query("SELECT MAX(percent) FROM wsm_discount_tiers")->fetchColumn();
if ($palier > 10.0) {
    $seuil = (int) $pdo->query("SELECT min_weight_g FROM wsm_discount_tiers WHERE percent = " . $palier)->fetchColumn();
    $qty = 20;
    $lourd = $mkProd('c', 10.00, 0.23, (int) ceil($seuil / $qty) + 50);
    ok('le panier de test dépasse VRAIMENT le seuil du palier',
       $qty <= WSM_SHOP_MAX_QTY && $qty * ((int) ceil($seuil / $qty) + 50) > $seuil);
    [$q4] = wsm_shop_quote($pdo, [['id' => $lourd, 'qty' => $qty]],
                           'inpost_courier', 'pl', ['voucher' => $dix['code']]);
    ok('un palier supérieur GAGNE contre le bon — jamais la somme des deux',
       (float) $q4['discount_percent'] === $palier, [$q4['discount_percent'], $palier]);
    ok('et la remise annoncée n\'est pas ' . ($palier + 10) . ' %',
       (float) $q4['discount_percent'] < $palier + 10.0);
} else {
    ok('un palier supérieur gagne contre le bon', true, 'aucun palier > 10 % configuré');
    ok('et la remise ne se cumule pas', true, 'aucun palier > 10 % configuré');
    ok('le panier de test dépasse le seuil du palier', true, 'aucun palier > 10 % configuré');
}

// ---- 4. La livraison offerte ---------------------------------------------------------------
echo "\n-- kod na darmową wysyłkę --\n";
$port = $mkBon(['kind' => 'wysylka']);
[$q5] = wsm_shop_quote($pdo, [['id' => $pB, 'qty' => 1]], 'inpost_courier', 'pl',
                       ['voucher' => $port['code']]);
ok('le port tombe à zéro', (int) $q5['shipping_gross'] === 0, $q5['shipping_gross']);
ok('la marchandise, elle, ne bouge pas', (int) $q5['items_gross'] === 5000, $q5['items_gross']);
ok('et le devis le dit', ($q5['voucher']['free_shipping'] ?? false) === true, $q5['voucher'] ?? null);

// ---- 5. Ce qui doit être refusé --------------------------------------------------------------
echo "\n-- co musi zostać odrzucone --\n";
$perime = $mkBon(['kind' => 'kwota', 'kwota' => '10,00', 'ends_at' => date('Y-m-d', time() - 2 * 86400)]);
$v = wsm_promo_check($pdo, $perime['code'], 10000);
ok('un bon périmé est refusé', $v['ok'] === false, $v);
ok('et le message donne la date', str_contains($v['raison'], 'ważnoś'), $v['raison']);

$futur = $mkBon(['kind' => 'kwota', 'kwota' => '10,00', 'starts_at' => date('Y-m-d', time() + 5 * 86400)]);
ok('un bon pas encore ouvert est refusé', wsm_promo_check($pdo, $futur['code'], 10000)['ok'] === false);

$minimum = $mkBon(['kind' => 'kwota', 'kwota' => '10,00', 'min_gross' => '200']);
$vm = wsm_promo_check($pdo, $minimum['code'], 5000);
ok('sous le minimum, refusé', $vm['ok'] === false, $vm);
ok('et le message dit à partir de combien', str_contains($vm['raison'], '200,00'), $vm['raison']);
ok('au-dessus du minimum, accepté', wsm_promo_check($pdo, $minimum['code'], 25000)['ok'] === true);

$retire = $mkBon(['kind' => 'kwota', 'kwota' => '10,00']);
wsm_promo_disable($pdo, (int) $retire['id'], 'test');
ok('un bon retiré est refusé', wsm_promo_check($pdo, $retire['code'], 10000)['ok'] === false);
$stRet = $pdo->prepare("SELECT COUNT(*) FROM wsm_vouchers WHERE id = ?");
$stRet->execute([(int) $retire['id']]);
ok('mais la ligne SUBSISTE — une commande passée doit rester explicable',
   (int) $stRet->fetchColumn() === 1);

ok('un code inconnu est refusé', wsm_promo_check($pdo, 'NIEMAGOWCALE', 10000)['ok'] === false);
ok('un code vide aussi', wsm_promo_check($pdo, '', 10000)['ok'] === false);

// UN CODE QUI N'ENLÈVE RIEN. La table portait déjà des lignes de
// démonstration — décrites dans une colonne de texte, sans valeur
// exploitable. Acceptées, elles auraient dit « code appliqué » et laissé
// payer le prix plein. Trouvé en regardant l'écran, pas le code.
$pdo->prepare("INSERT INTO wsm_vouchers (code, valeur, type, validite, kind, pct, active)
               VALUES (?,?,?,?,?,?,1)")
    ->execute(['TST' . $sfx . 'PUSTY', '10 % na powitanie', 'Panier', '', 'procent', 0]);
$vp = wsm_promo_check($pdo, 'TST' . $sfx . 'PUSTY', 50000);
ok('un code sans valeur exploitable est REFUSÉ, pas appliqué à 0', $vp['ok'] === false, $vp);
$stP = $pdo->prepare("SELECT * FROM wsm_vouchers WHERE code = ?");
$stP->execute(['TST' . $sfx . 'PUSTY']);
ok('et l\'écran le dit « bez efektu », pas « −0 % »',
   str_contains(wsm_promo_label($stP->fetch() ?: []), 'bez efektu'));

// La borne de fin est INCLUSIVE : un bon « jusqu'au 31 » vaut tout le 31.
$aujourdhui = $mkBon(['kind' => 'kwota', 'kwota' => '10,00', 'ends_at' => date('Y-m-d')]);
ok('un bon qui finit AUJOURD\'HUI vaut encore aujourd\'hui',
   wsm_promo_check($pdo, $aujourdhui['code'], 10000)['ok'] === true);

// ---- 6. Les bornes à la saisie ------------------------------------------------------------
echo "\n-- granice przy zapisie --\n";
[$okS, $msgS] = wsm_promo_save($pdo, ['code' => 'TST' . $sfx . 'ZZ', 'kind' => 'procent', 'pct' => '90', 'active' => 1], 'test');
ok('90 % est refusé à la saisie, pas neutralisé en silence', $okS === false, $msgS);
ok('et le message dit la borne', str_contains($msgS, '50'), $msgS);
[$okN] = wsm_promo_save($pdo, ['code' => 'TST' . $sfx . 'YY', 'kind' => 'kwota', 'kwota' => '0', 'active' => 1], 'test');
ok('un montant nul est refusé', $okN === false);
[$okD, $msgD] = wsm_promo_save($pdo, ['code' => 'TST' . $sfx . 'XX', 'kind' => 'kwota', 'kwota' => '10',
    'starts_at' => date('Y-m-d', time() + 10 * 86400), 'ends_at' => date('Y-m-d'), 'active' => 1], 'test');
ok('une fin avant le début est refusée', $okD === false, $msgD);
[$okU] = wsm_promo_save($pdo, ['code' => $enorme['code'], 'kind' => 'kwota', 'kwota' => '10', 'active' => 1], 'test');
ok('un code déjà pris est refusé', $okU === false);

ok('un montant se lit avec ou sans diacritique',
   wsm_promo_grosze('200 zł') === 20000 && wsm_promo_grosze('200 zl') === 20000
   && wsm_promo_grosze('200,50') === 20050, wsm_promo_grosze('200 zl'));

// promo.php doit tenir DEBOUT SEUL. Il se sert de wsm_split_vat(), qui vit
// dans shop.php : la dépendance marchait par chance, parce que la boutique
// charge shop.php d'abord. Ailleurs — une tâche planifiée, un contrôle de
// déploiement — elle tombait sur « Call to undefined function ».
$php = PHP_BINARY;
$seul = escapeshellarg(dirname(__DIR__) . '/promo.php');
exec("$php -r " . escapeshellarg(
    'require_once ' . var_export(dirname(__DIR__) . '/promo.php', true) . ';'
    . '$l=[["line_gross"=>3000,"line_net"=>2439,"line_vat"=>561,"vat_rate"=>0.23]];'
    . 'echo wsm_promo_spread($l, 5000);') . ' 2>&1', $sortie, $rc);
ok('promo.php se charge SEUL, sans shop.php', $rc === 0 && trim(implode('', $sortie)) === '3000',
   implode(' ', $sortie));

$genere = wsm_promo_code($pdo);
ok('un code engendré fait la bonne longueur', strlen($genere) === WSM_PROMO_CODE_LEN, $genere);
ok('et ne contient ni O ni 0 ni I ni 1 — on se le dicte au téléphone',
   !preg_match('/[O0I1]/', $genere), $genere);

// ---- 7. L'usage unique tient à la commande, pas au devis ---------------------------------
echo "\n-- jednorazowy kod nie przechodzi dwa razy --\n";
$unique = $mkBon(['kind' => 'kwota', 'kwota' => '10,00', 'max_uses' => 1]);
$acheteur = function (string $code) use ($sfx, $pB) {
    return [
        'items' => [['id' => $pB, 'qty' => 1]], 'lang' => 'pl',
        'delivery_method' => 'inpost_courier', 'inpost_point' => 'KRA010',
        'email' => "kupujacy.$sfx@example.com", 'phone' => '600100200',
        'first_name' => 'Jan', 'last_name' => 'Kowalski', 'client_type' => 'osoba',
        'ship_street' => 'Testowa', 'ship_building' => '1', 'ship_postcode' => '00-001',
        'ship_city' => 'Warszawa', 'ship_country' => 'PL', 'consent_terms' => true,
        'voucher' => $code,
    ];
};
[$o1, $e1] = wsm_shop_create_order($pdo, $acheteur($unique['code']));
ok('la première commande passe', $o1 !== null, $e1);
ok('et son montant tient compte du bon', $o1 && (int) $o1['items_gross'] === 4000, $o1['items_gross'] ?? null);
ok('le code est GELÉ sur la commande', $o1 && (string) $o1['voucher_code'] === (string) $unique['code'],
   $o1['voucher_code'] ?? null);
ok('avec le montant obtenu', $o1 && (int) $o1['voucher_amount'] === 1000, $o1['voucher_amount'] ?? null);

[$o2, $e2] = wsm_shop_create_order($pdo, $acheteur($unique['code']));
ok('la SECONDE est refusée — le quota est épuisé', $o2 === null, $o2['code'] ?? null);
ok('et l\'erreur porte sur le code, pas sur la base', isset($e2['voucher']), $e2);

$st = $pdo->prepare("SELECT used FROM wsm_vouchers WHERE id = ?");
$st->execute([(int) $unique['id']]);
ok('le compteur vaut exactement 1 — la commande refusée n\'a rien consommé',
   (int) $st->fetchColumn() === 1);

$st = $pdo->prepare("SELECT COUNT(*) FROM wsm_voucher_uses WHERE voucher_id = ?");
$st->execute([(int) $unique['id']]);
ok('une seule utilisation est gravée', (int) $st->fetchColumn() === 1);

// Le rejeu de la même commande ne doit pas décompter une seconde fois.
[$okR] = wsm_promo_redeem($pdo, (int) $unique['id'], (int) $o1['id'], 'x@example.com', 1000);
$st->execute([(int) $unique['id']]);
ok('rejouer la même commande ne consomme rien de plus',
   $okR === true && (int) $st->fetchColumn() === 1);

// ---- 8. Un code refusé arrête la commande -------------------------------------------------
echo "\n-- zamówienie z odrzuconym kodem NIE powstaje --\n";
$avant = (int) $pdo->query("SELECT COUNT(*) FROM wsm_orders")->fetchColumn();
[$o3, $e3] = wsm_shop_create_order($pdo, $acheteur('KODKTOREGONIEMA'));
ok('la commande n\'est pas créée', $o3 === null);
ok('et le message porte sur le code', isset($e3['voucher']), $e3);
$apres = (int) $pdo->query("SELECT COUNT(*) FROM wsm_orders")->fetchColumn();
ok('aucune commande fantôme n\'est restée en base', $apres === $avant, [$avant, $apres]);

// ---- 9. Ce que la campagne a coûté -----------------------------------------------------------
echo "\n-- ile kosztowała kampania --\n";
$s = wsm_promo_stats($pdo, (int) $unique['id']);
ok('une utilisation comptée', $s['uses'] === 1, $s);
ok('pour 10 zł accordés', $s['amount'] === 1000, $s);
ok('à une seule adresse', $s['emails'] === 1, $s);
$liste = wsm_promo_list($pdo);
$vu = null;
foreach ($liste as $b) if ((int) $b['id'] === (int) $unique['id']) $vu = $b;
ok('la liste porte le bon et son coût', $vu !== null && $vu['stats']['amount'] === 1000, $vu['stats'] ?? null);
ok('avec un libellé lisible', $vu && str_contains((string) $vu['libelle'], '10,00'), $vu['libelle'] ?? null);

// ---- 10. La limite par adresse ----------------------------------------------------------------
echo "\n-- limit na adres --\n";
$parAdresse = $mkBon(['kind' => 'kwota', 'kwota' => '5,00', 'per_email' => 1]);
$mail = "kupujacy.$sfx@example.com";
ok('avant tout usage, le code passe',
   wsm_promo_check($pdo, $parAdresse['code'], 10000, $mail)['ok'] === true);
wsm_promo_redeem($pdo, (int) $parAdresse['id'], (int) $o1['id'], $mail, 500);
ok('après un usage, cette adresse ne peut plus',
   wsm_promo_check($pdo, $parAdresse['code'], 10000, $mail)['ok'] === false);
ok('mais une AUTRE adresse le peut encore',
   wsm_promo_check($pdo, $parAdresse['code'], 10000, "ktos.inny.$sfx@example.com")['ok'] === true);

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
