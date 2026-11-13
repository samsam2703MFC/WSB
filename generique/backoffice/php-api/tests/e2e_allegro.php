<?php
// ============================================================================
//  e2e_allegro.php — le second canal, et les deux façons de s'y blesser.
//
//  CE QU'ON NE PEUT PAS TESTER, ET QU'ON N'INVENTE PAS. Allegro exige un
//  compte vendeur et une application OAuth. Nous n'en avons pas. Aucun appel
//  réseau n'est donc simulé ici : ce qui se prouve, c'est ce qui se décide
//  AVANT l'appel — et c'est là que sont les deux vrais dangers.
//
//   1. LE STOCK PARTAGÉ. Les mêmes cinquante tablettes affichées ici ET
//      là-bas se vendent CENT fois. Le second acheteur reçoit une excuse, et
//      Allegro sanctionne les annulations du vendeur. On ne publie donc
//      jamais tout le stock.
//   2. LE PRIX. Allegro prélève une commission : publier au prix de la
//      boutique, c'est vendre moins cher qu'à la maison sur le canal où l'on
//      perd le client en plus.
//
//  Et deux ennuis qui coûtent une journée chacun :
//   3. un produit sans EAN — Allegro refuse l'offre ;
//   4. un titre coupé au milieu d'un mot.
//
//  Usage :  php tests/e2e_allegro.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/allegro.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end Allegro (kanał zamknięty, reguły otwarte)\n\n";

// ---- 1. Fermé sans identifiants ------------------------------------------------------
echo "-- bez danych dostępowych wszystko jest zamknięte --\n";
ok('le canal est fermé', wsm_allegro_enabled() === false);
$m = wsm_allegro_manquants();
ok('et ce qui manque est NOMMÉ', count($m) === 4, $m);
ok('avec l\'endroit où le trouver', str_contains(implode(' ', $m), 'deweloperskiego'), $m);
[$t, $msg] = wsm_allegro_token();
ok('aucun jeton n\'est demandé au réseau', $t === '', $t);
ok('et le message dit pourquoi', str_contains($msg, 'nie jest skonfigurowane'), $msg);

// « xxxx » est la valeur d'un champ de démonstration : elle ne doit PAS
// ouvrir l'intégration. C'est la même règle que tpay et InPost.
wsm_config_overlay(['allegro' => ['client_id' => 'xxxx', 'client_secret' => 'xxxx',
                                  'refresh_token' => 'xxxx', 'seller_id' => 'xxxx']]);
ok('« xxxx » compte pour VIDE — sinon on ouvre sur du vent', wsm_allegro_enabled() === false);
ok('et il reste signalé comme manquant', count(wsm_allegro_manquants()) === 4);
wsm_config_overlay(['allegro' => ['client_id' => 'reel', 'client_secret' => 'reel',
                                  'refresh_token' => 'reel', 'seller_id' => 'S1']]);
ok('avec de vraies valeurs, le canal s\'ouvre', wsm_allegro_enabled() === true);
ok('et plus rien ne manque', wsm_allegro_manquants() === []);
wsm_config_overlay(['allegro' => ['client_id' => '', 'client_secret' => '',
                                  'refresh_token' => '', 'seller_id' => '']]);
ok('on referme proprement', wsm_allegro_enabled() === false);

// ---- 2. LE STOCK PARTAGÉ — le vrai danger --------------------------------------------
echo "\n-- nigdy nie wystawiamy całego stanu --\n";
$p = wsm_allegro_stock_plan(100);
ok('sur 100 en stock, on ne publie pas 100', (int) $p['publikowalne'] < 100, $p);
ok('la réserve de 20 % reste au magasin', (int) $p['rezerwa'] === 20 && (int) $p['publikowalne'] === 80, $p);
ok('publiable + réserve = stock', $p['publikowalne'] + $p['rezerwa'] === 100, $p);
ok('et la raison est écrite', str_contains((string) $p['powod'], 'rezerwa'), $p['powod']);

$z = wsm_allegro_stock_plan(0);
ok('stock nul : rien à publier', (int) $z['publikowalne'] === 0, $z);
ok('et on le dit', str_contains((string) $z['powod'], 'brak stanu'), $z['powod']);

$petit = wsm_allegro_stock_plan(2);
ok('sous le plancher de 3, on ne publie RIEN', (int) $petit['publikowalne'] === 0, $petit);
ok('trois unités qui partent en une heure ne valent pas le risque',
   str_contains((string) $petit['powod'], 'poniżej'), $petit['powod']);
ok('mais elles restent comptées en réserve', (int) $petit['rezerwa'] === 2, $petit);

// Une réserve absurde ne doit ni vider ni remplir la publication.
$abs = wsm_allegro_stock_plan(100, 999);
ok('une réserve de 999 % retombe sur la valeur par défaut', (int) $abs['publikowalne'] === 80, $abs);
$neg = wsm_allegro_stock_plan(100, -50);
ok('une réserve négative aussi', (int) $neg['publikowalne'] === 80, $neg);
$haut = wsm_allegro_stock_plan(100, 90);
ok('une réserve de 90 % est acceptée telle quelle', (int) $haut['publikowalne'] === 10, $haut);
ok('publiable + réserve reste égal au stock, quelle que soit la réserve',
   $haut['publikowalne'] + $haut['rezerwa'] === 100, $haut);

// ---- 3. LE PRIX ne descend jamais sous le plancher -------------------------------------
echo "\n-- cena nigdy poniżej progu prowizji --\n";
$plancher = wsm_allegro_prix_plancher(10000);          // 100 zł en boutique
ok('le plancher est AU-DESSUS du prix boutique', $plancher > 10000, $plancher);
ok('et couvre la commission de 12 %', $plancher >= (int) ceil(10000 / 0.88), $plancher);
ok('un prix nul donne un plancher nul', wsm_allegro_prix_plancher(0) === 0);
ok('une commission aberrante retombe sur la valeur par défaut',
   wsm_allegro_prix_plancher(10000, 150) === $plancher, wsm_allegro_prix_plancher(10000, 150));

$offre = wsm_allegro_offer(['id' => 'x', 'nom' => 'Czekolada', 'ean' => '5901234123457',
                            'prix_grosze' => 10000, 'stock' => 50]);
$publie = (int) round(((float) $offre['sellingMode']['price']['amount']) * 100);
ok('le prix publié n\'est JAMAIS inférieur au prix boutique', $publie >= 10000, $publie);
ok('il est remonté au plancher', $publie === $plancher, [$publie, $plancher]);
ok('et écrit avec deux décimales et un point — ce qu\'Allegro attend',
   (bool) preg_match('/^\d+\.\d{2}$/', (string) $offre['sellingMode']['price']['amount']),
   $offre['sellingMode']['price']['amount']);
ok('la devise est PLN', $offre['sellingMode']['price']['currency'] === 'PLN');

// ---- 4. Le titre se coupe sur un mot ------------------------------------------------------
echo "\n-- tytuł ucinany na słowie, nigdy w środku --\n";
$long = 'Czekolada ciemna 70 % — tabliczka rzemieślnicza z Ghany, 1 kg, pakowana ręcznie';
$t = wsm_allegro_titre($long);
ok('le titre respecte la borne d\'Allegro', mb_strlen($t) <= WSM_ALLEGRO_TITRE_MAX, [mb_strlen($t), $t]);
ok('il ne finit pas au milieu d\'un mot',
   !str_contains($long, $t . 'x') && mb_substr($long, mb_strlen($t), 1) === ' ' || mb_strlen($t) < mb_strlen($long),
   $t);
ok('ni sur un tiret ou une virgule orpheline', !preg_match('/[\s,;:\-–—]$/u', $t), $t);
$court = wsm_allegro_titre('Czekolada biała');
ok('un titre court passe intact', $court === 'Czekolada biała', $court);
ok('les espaces multiples sont normalisés',
   wsm_allegro_titre("Czekolada   biała\n 31 %") === 'Czekolada biała 31 %',
   wsm_allegro_titre("Czekolada   biała\n 31 %"));

// ---- 5. Un produit sans EAN ne part pas ----------------------------------------------------
echo "\n-- bez EAN oferta nie powstaje --\n";
$sansEan = wsm_allegro_offer(['id' => 'y', 'nom' => 'Bez kodu', 'ean' => '',
                              'prix_grosze' => 5000, 'stock' => 50]);
ok('le blocage est signalé', $sansEan['_blockers'] !== [], $sansEan['_blockers']);
ok('et il NOMME Allegro comme raison', str_contains(implode(' ', $sansEan['_blockers']), 'EAN'),
   $sansEan['_blockers']);
$sansPrix = wsm_allegro_offer(['id' => 'z', 'nom' => 'Bez ceny', 'ean' => '5901234123457',
                               'prix_grosze' => 0, 'stock' => 50]);
ok('un produit sans prix est bloqué aussi', $sansPrix['_blockers'] !== [], $sansPrix['_blockers']);
$sansStock = wsm_allegro_offer(['id' => 'w', 'nom' => 'Bez stanu', 'ean' => '5901234123457',
                                'prix_grosze' => 5000, 'stock' => 0]);
ok('un produit sans stock est bloqué, avec la raison du plan',
   in_array('brak stanu magazynowego', $sansStock['_blockers'], true), $sansStock['_blockers']);
ok('et son offre est INACTIVE, pas active-avec-zéro',
   $sansStock['publication']['status'] === 'INACTIVE', $sansStock['publication']);

$bon = wsm_allegro_offer(['id' => 'ok', 'nom' => 'Dobra', 'ean' => '5901234123457',
                          'prix_grosze' => 5000, 'stock' => 50]);
ok('un produit complet ne bloque rien', $bon['_blockers'] === [], $bon['_blockers']);
ok('et son offre est ACTIVE', $bon['publication']['status'] === 'ACTIVE');
ok('avec la quantité PUBLIABLE, pas le stock entier',
   (int) $bon['stock']['available'] === 40, $bon['stock']);

// ---- 6. Le plan sur le vrai catalogue --------------------------------------------------------
echo "\n-- plan na prawdziwym katalogu --\n";
$plan = wsm_allegro_plan($pdo);
ok('le catalogue produit un plan', is_array($plan) && count($plan) > 0, count($plan));
$k = wsm_allegro_kpis($plan);
ok('les produits sont comptés', $k['produktow'] === count($plan), $k);
ok('prêts + bloqués = total', $k['gotowych'] + $k['zablokowanych'] === $k['produktow'], $k);
$coherent = true;
foreach ($plan as $x) {
    $o = $x['offer'];
    // Règle : jamais plus que le stock, jamais moins que zéro.
    if ((int) $o['stock']['available'] < 0) $coherent = false;
    if ((int) $o['stock']['available'] > (int) $o['_plan']['publikowalne']) $coherent = false;
    // Règle : jamais sous le prix boutique.
    $pub = (int) round(((float) $o['sellingMode']['price']['amount']) * 100);
    if ($o['_prix_sklep'] > 0 && $pub < $o['_prix_sklep']) $coherent = false;
}
ok('aucune offre ne publie plus que le publiable, ni sous le prix boutique', $coherent);
ok('les causes de blocage sont comptées', is_array($k['przyczyny']), $k['przyczyny']);
$n = array_values($k['przyczyny']);
$trie = $n; rsort($trie);
ok('et triées de la plus fréquente à la moins', $n === $trie, $n);

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
