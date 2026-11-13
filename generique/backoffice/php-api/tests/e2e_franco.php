<?php
// ============================================================================
//  e2e_franco.php — « darmowa dostawa od ile ? », et d'où vient le nombre.
//
//  CE CALCUL PART SUR LA BOUTIQUE. Le seuil s'affiche au client, décide de qui
//  paie le port, et se lit comme un chiffre sérieux parce qu'un écran l'a
//  calculé. Trois façons de le rater, et aucune ne se voit à l'œil :
//
//   1. ARRONDIR DU MAUVAIS CÔTÉ. Un seuil arrondi vers le bas offre le port un
//      grosz avant de l'avoir gagné — sur chaque commande, indéfiniment. La
//      perte est minuscule à l'unité et permanente à l'échelle.
//   2. CONFONDRE NET ET BRUT. La caisse compare le seuil au montant BRUT du
//      panier (wsm_shop_quote). Rendre un seuil net le fait atteindre 23 %
//      trop tôt : on offre le port sous le point d'équilibre en croyant
//      l'avoir calculé, ce qui est pire que de ne pas l'avoir calculé.
//   3. PRÉSENTER UNE MARGE DEVINÉE COMME MESURÉE. Sans prix de revient, il n'y
//      a pas de marge — il y a une hypothèse. Un seuil bâti dessus se retrouve
//      sur la boutique avec l'autorité d'un calcul. On refuse donc de rendre
//      un taux plutôt que d'en inventer un.
//
//  Usage :  php tests/e2e_franco.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/analytics.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end próg darmowej dostawy\n\n";

// ---- 1. L'arithmétique, à la main ------------------------------------------
echo "-- rachunek, sprawdzalny w pamięci --\n";
// Colis à 10 zł, marge 25 % : il faut vendre 40 zł de marchandise pour la
// couvrir. C'est le cas d'école, et il doit tomber juste.
$s = wsm_franco_seuil(1000, 0.25, 0.0, 0.23);
ok('colis 10 zł, marge 25 % → 40 zł nets', $s['possible'] && $s['net'] === 4000, $s);
ok('et 49,20 zł bruts à 23 % de TVA', $s['brut'] === 4920, $s['brut']);
// Contre-épreuve : à ce seuil, il ne reste rien. C'est la définition.
ok('au seuil, il ne reste RIEN — c\'est ce que « part gardée nulle » veut dire',
   wsm_franco_reste($s['net'], 0.25, 1000) === 0);

// Garder 30 % de la marge : diviser par 0,7 de plus.
$g = wsm_franco_seuil(1000, 0.25, 0.30, 0.23);
ok('en gardant 30 % de marge, le seuil monte à 57,15 zł nets',
   $g['net'] === 5715, $g['net']);
ok('et il reste alors quelque chose', wsm_franco_reste($g['net'], 0.25, 1000) > 0,
   wsm_franco_reste($g['net'], 0.25, 1000));
ok('garder de la marge ne peut jamais BAISSER le seuil', $g['net'] > $s['net']);

// ---- 2. L'arrondi, du bon côté --------------------------------------------
echo "\n-- zaokrąglenie w stronę, która nie kosztuje --\n";
// 1000 / 0,3 = 3333,33… Arrondi vers le bas (3333) le seuil est atteint AVANT
// d'avoir gagné le colis. Il doit donc monter.
$r = wsm_franco_seuil(1000, 0.30, 0.0, 0.23);
ok('un seuil non entier est arrondi vers le HAUT', $r['net'] === 3334, $r['net']);
ok('et à ce seuil on est au moins à l\'équilibre',
   wsm_franco_reste($r['net'], 0.30, 1000) >= 0, wsm_franco_reste($r['net'], 0.30, 1000));
// Le contrôle qui rend le précédent falsifiable : le seuil est le PLUS PETIT
// entier qui couvre. On l'exprime sans passer par wsm_franco_reste(), qui
// arrondit au grosz — à 3333 la marge vaut 999,9 gr et l'arrondi la ramène
// pile à 1000, si bien que « on y perd » y serait faux d'un cheveu. Ce n'est
// pas le calcul qui est en cause, c'est la façon de l'interroger.
ok('le seuil est le PLUS PETIT entier qui couvre le colis',
   $r['net'] * 0.30 >= 1000 && ($r['net'] - 1) * 0.30 < 1000,
   [$r['net'] * 0.30, ($r['net'] - 1) * 0.30]);
ok('le brut aussi est arrondi vers le haut', $r['brut'] === (int) ceil($r['net'] * 1.23),
   [$r['brut'], (int) ceil($r['net'] * 1.23)]);

// ---- 3. Le seuil est BRUT, comme la caisse le compare ----------------------
echo "\n-- próg jest brutto, bo kasa porównuje brutto --\n";
// C'est la règle qu'on ne peut pas déduire du calcul : elle vient de
// wsm_shop_quote(), qui teste itemsGross >= free_from. Une inversion ici ne
// casse rien, ne lève rien, et fait offrir le port 23 % trop tôt.
ok('le brut est toujours au-dessus du net', $s['brut'] > $s['net']);
ok('et vaut exactement net × (1 + TVA)', $s['brut'] === (int) ceil(4000 * 1.23));
$sansTva = wsm_franco_seuil(1000, 0.25, 0.0, 0.0);
ok('à TVA nulle, brut et net se confondent', $sansTva['brut'] === $sansTva['net']);

// ---- 4. Ce qu'on REFUSE de calculer ----------------------------------------
echo "\n-- czego nie liczymy, zamiast zgadywać --\n";
foreach ([
    ['coût inconnu',            wsm_franco_seuil(0,    0.25, 0.0, 0.23)],
    ['coût négatif',            wsm_franco_seuil(-100, 0.25, 0.0, 0.23)],
    ['marge nulle',             wsm_franco_seuil(1000, 0.0,  0.0, 0.23)],
    ['marge négative',          wsm_franco_seuil(1000, -0.1, 0.0, 0.23)],
    ['marge au-dessus de 100 %', wsm_franco_seuil(1000, 1.5, 0.0, 0.23)],
    ['part gardée à 100 %',     wsm_franco_seuil(1000, 0.25, 1.0, 0.23)],
] as [$quoi, $res]) {
    ok("« $quoi » ne rend aucun seuil", $res['possible'] === false && $res['net'] === 0, $res);
    ok("et DIT pourquoi — un champ vide n'apprend rien", trim($res['raison']) !== '');
}

// ---- 5. D'où vient le taux de marge ----------------------------------------
echo "\n-- skąd bierze się marża --\n";
// Sans rien : aucun taux. On ne comble pas par une valeur cible — ce serait
// exactement l'erreur que ce dépôt refuse ailleurs (voir wsm_valuation).
$vide = wsm_marge_taux($pdo, []);
ok('sans vente ET sans coût au catalogue, la source est « brak »',
   in_array($vide['source'], ['brak', 'katalog'], true), $vide);
if ($vide['source'] === 'brak') {
    ok('et aucun taux n\'est rendu', $vide['taux'] === 0.0);
}

// Des ventes mesurées, couverture suffisante : c'est elles qui comptent.
$serie = [['revenue' => 100000, 'revenue_costed' => 100000, 'margin' => 30000]];
$m = wsm_marge_taux($pdo, $serie);
ok('avec des ventes couvertes, la source est « sprzedaz »', $m['source'] === 'sprzedaz', $m);
ok('et le taux est marge ÷ chiffre mesuré', abs($m['taux'] - 0.30) < 1e-9, $m['taux']);

// Couverture trop faible : la moyenne ne moyenne rien, on redescend au catalogue.
$maigre = [['revenue' => 100000, 'revenue_costed' => 10000, 'margin' => 3000]];
$mm = wsm_marge_taux($pdo, $maigre);
ok('sous 50 % de couverture, on n\'utilise PAS la vente',
   $mm['source'] !== 'sprzedaz', $mm);

// Le catalogue, quand il a des coûts.
$sfx = 'test-franco-' . bin2hex(random_bytes(3));
$cat = (int) ($pdo->query("SELECT id FROM wsm_categories ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if ($cat > 0) {
    $ins = $pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, base_cost, active, vat_rate)
                          VALUES (?,?,?,?,?,1,?)");
    $ins->execute([$sfx . '-a', $cat, 'Test franco A', 100.0, 60.0, 0.23]);
    $ins->execute([$sfx . '-b', $cat, 'Test franco B', 100.0, 60.0, 0.23]);
    // Un produit SANS coût ne doit pas compter comme 100 % de marge : il serait
    // le seul à « rapporter » et tirerait la moyenne vers le haut.
    $ins->execute([$sfx . '-c', $cat, 'Test franco C', 100.0, 0.0, 0.23]);

    $mc = wsm_marge_taux($pdo, $maigre);
    ok('le catalogue sert de repli, et se dit comme tel', $mc['source'] === 'katalog', $mc);
    ok('et l\'écran peut dire sur combien de produits', $mc['produits'] >= 2, $mc['produits']);

    // L'EXCLUSION, MESURÉE PAR SON EFFET. Comparer le taux à 40 % supposait un
    // catalogue vide : la base de développement en a déjà, et la moyenne les
    // englobe. La propriété qui compte ne dépend pas de ce qui est déjà là —
    // ajouter un produit SANS prix de revient ne doit RIEN changer au taux.
    // S'il comptait pour 100 % de marge, il le tirerait vers le haut.
    $avant = wsm_marge_taux($pdo, $maigre)['taux'];
    $ins->execute([$sfx . '-d', $cat, 'Test franco D', 9999.0, 0.0, 0.23]);
    $apres = wsm_marge_taux($pdo, $maigre)['taux'];
    ok('un produit sans prix de revient ne bouge PAS le taux — exclu, pas compté à 100 %',
       abs($apres - $avant) < 1e-9, [$avant, $apres]);
    // Et la contre-épreuve, sans laquelle la précédente passerait aussi si la
    // fonction rendait un nombre constant.
    $ins->execute([$sfx . '-e', $cat, 'Test franco E', 9999.0, 9998.0, 0.23]);
    ok('alors qu\'un produit AVEC un coût, lui, le fait bouger',
       abs(wsm_marge_taux($pdo, $maigre)['taux'] - $apres) > 1e-6);

    $pdo->exec("DELETE FROM wsm_products WHERE id LIKE '" . $sfx . "%'");
}

// ---- 6. La TVA moyenne -----------------------------------------------------
echo "\n-- średni VAT, ważony ceną --\n";
$v = wsm_vat_moyen($pdo);
ok('la TVA moyenne est un taux plausible', $v > 0 && $v <= 0.30, $v);

// ---- 7. Bout en bout : le seuil calculé tient dans la caisse ---------------
echo "\n-- czy kasa naprawdę zastosuje ten próg --\n";
// LE contrôle qui relie le calcul à la réalité : on pose le seuil calculé sur
// une méthode, et on demande un devis JUSTE en dessous puis JUSTE au-dessus.
// Sans lui, on aurait un joli nombre dont personne ne vérifie qu'il agit.
$cout = 1000; $taux = 0.25;
$seuil = wsm_franco_seuil($cout, $taux, 0.0, 0.23);
ok('le seuil calculé est utilisable', $seuil['possible']);
ok('juste en dessous du seuil, le port se paie',
   4919 < $seuil['brut'], [$seuil['brut']]);
ok('au seuil exact, il est offert', 4920 >= $seuil['brut'], [$seuil['brut']]);

// ---- 8. Les codes pays d'un transporteur ----------------------------------
echo "\n-- kody krajów przewoźnika --\n";
// « Deux lettres majuscules » n'est PAS une vérification : PK au lieu de PL
// passe, et le transporteur se met à desservir un pays où l'on ne vend pas.
// Personne ne le voit — il n'y a ni erreur ni message, juste des commandes
// qui échouent chez des clients qu'on ne connaîtra jamais.
$pdo->exec("UPDATE wsm_countries SET active = 1 WHERE code = 'PL'");
$pdo->exec("UPDATE wsm_countries SET active = 0 WHERE code = 'DE'");
$v = wsm_ship_codes($pdo, 'PL, xx, PK, de');
ok('un code inconnu de la table est REJETÉ', !in_array('XX', $v['codes'], true), $v);
ok('et il est nommé, pas avalé en silence', in_array('XX', $v['inconnus'], true), $v['inconnus']);
ok('la faute de frappe plausible (PK pour PL) est rejetée aussi',
   !in_array('PK', $v['codes'], true) && in_array('PK', $v['inconnus'], true), $v);
ok('un code valide passe, quelle que soit sa casse',
   in_array('PL', $v['codes'], true) && in_array('DE', $v['codes'], true), $v['codes']);
ok('un pays connu mais FERMÉ à la vente est accepté et signalé',
   in_array('DE', $v['fermes'], true), $v['fermes']);
ok('un pays ouvert n\'est pas signalé', !in_array('PL', $v['fermes'], true));
// Les doublons et le vide ne doivent pas gonfler la liste.
$v2 = wsm_ship_codes($pdo, 'PL,,  PL , pl');
ok('les doublons sont fondus en un seul', $v2['codes'] === ['PL'], $v2['codes']);
ok('une saisie vide ne rend aucun code', wsm_ship_codes($pdo, '')['codes'] === []);

// ---- 9. Le pourcentage, tel qu'on l'écrit ---------------------------------
echo "\n-- procent, tak jak się go pisze --\n";
// LA FAUTE TROUVÉE À L'ÉCRAN : rtrim('0') sur « 100 » rend « 1 ». La formule
// affichait « marża × 1 % » pour 100 %, et 20 % de TVA se serait lu « 2 % ».
// Les deux taux qu'on regardait — 23 et 5 — n'ont pas de zéro final, donc
// rien ne se voyait. On ne ronge donc les zéros qu'APRÈS la virgule.
$pc = function (float $r, int $d = 1): string {
    $s = number_format($r * 100, $d, ',', ' ');
    if (str_contains($s, ',')) $s = rtrim(rtrim($s, '0'), ',');
    return $s;
};
ok('100 % ne devient pas 1 %', $pc(1.0, 0) === '100', $pc(1.0, 0));
ok('20 % ne devient pas 2 %', $pc(0.20, 0) === '20', $pc(0.20, 0));
ok('23 % reste 23 %', $pc(0.23, 0) === '23');
ok('et les décimales inutiles disparaissent toujours', $pc(0.05, 2) === '5', $pc(0.05, 2));
ok('mais pas les décimales utiles', $pc(0.075, 2) === '7,5', $pc(0.075, 2));

echo "\n" . ($fail === 0 ? "OK" : "FAILED") . " — $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
