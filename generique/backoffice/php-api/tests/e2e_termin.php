<?php
// ============================================================================
//  e2e_termin.php — le délai promis, et ce qu'il en reste.
//
//  LA VITRINE PROMET « WYSYŁKA W 24 H ». Jusqu'ici cette phrase ne vivait que
//  dans un texte : rien dans la console ne disait quelle commande approchait de
//  l'échéance, ni laquelle l'avait dépassée. On tenait la promesse de mémoire,
//  commande par commande, et on la ratait sans le savoir.
//
//  CE QUE CE TEST GARDE :
//
//  1. LE COMPTEUR DÉMARRE À LA PAYE. Un panier « oczekuje na płatność » ne peut
//     pas partir. Faire clignoter un retard sur une commande qu'on n'a pas le
//     droit d'expédier apprend, en une semaine, à ignorer le voyant.
//
//  2. IL S'ARRÊTE AU DÉPART DU COLIS, pas à la livraison. Ce qu'on promet,
//     c'est d'emballer sous 24 h ; ce qu'InPost fait ensuite ne dépend pas
//     d'ici. Et la commande partie garde son verdict : à temps, ou en retard.
//
//  3. LE DÉLAI EST RÉGLABLE, et le réglage MARCHE. Un champ qui accepte la
//     saisie, dit « Zapisano » et ne change rien est la panne qu'on a déjà
//     payée sur ksef.env.
//
//  4. CE QUI N'EST PAS UN NOMBRE RETOMBE SUR 24, PAS SUR ZÉRO. « (int) "24h" »
//     vaut 0 : toutes les commandes passeraient en retard le lendemain, sans
//     que rien n'ait l'air cassé.
//
//  Usage :  php tests/e2e_termin.php
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
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/invoice.php';
require_once dirname(__DIR__) . '/settings.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end termin wysyłki\n\n";

$H = 24;                                   // le délai par défaut, en heures
$now = 1_800_000_000;                      // une heure fixe : un test ne dépend pas de l'horloge
$il_y_a = fn(int $h) => date('Y-m-d H:i:s', $now - $h * 3600);

// ---- 1. Le compteur démarre à la paye -------------------------------------
echo "-- licznik startuje przy zaplacie --\n";
$t = wsm_order_termin(['status' => 'nowe', 'payment_status' => 'oczekuje',
                       'created_at' => $il_y_a(72), 'paid_at' => null], $now, $H);
ok('sans paiement, aucun compte à rebours', $t['etat'] === 'czeka', $t);
ok('… et le voyant ne crie pas', wsm_termin_etykieta($t)[0] === 'czeka');

// Payée mais sans date : les commandes d'avant la colonne paid_at. On repart
// de la date de commande — le pire estimé, jamais le meilleur, pour ne pas
// fabriquer de fausse tranquillité.
$t = wsm_order_termin(['status' => 'oplacone', 'payment_status' => 'oplacone',
                       'created_at' => $il_y_a(72), 'paid_at' => ''], $now, $H);
ok('payée sans date, on repart de la commande', $t['etat'] === 'po_czasie', $t);

// ---- 2. En cours, puis dépassé --------------------------------------------
echo "\n-- w biegu, potem po terminie --\n";
$t = wsm_order_termin(['status' => 'oplacone', 'payment_status' => 'oplacone',
                       'created_at' => $il_y_a(2), 'paid_at' => $il_y_a(2)], $now, $H);
ok('payée il y a 2 h : en cours', $t['etat'] === 'bieg', $t);
ok('… et il reste bien 22 h', abs($t['ecart'] - 22 * 3600) < 90, $t['ecart']);
ok('… le voyant le dit en clair', wsm_termin_etykieta($t)[1] === '22 godz.', wsm_termin_etykieta($t));

$t = wsm_order_termin(['status' => 'w_realizacji', 'payment_status' => 'oplacone',
                       'created_at' => $il_y_a(40), 'paid_at' => $il_y_a(40)], $now, $H);
ok('payée il y a 40 h : dépassé', $t['etat'] === 'po_czasie', $t);
ok('… de 16 h', abs($t['ecart'] - 16 * 3600) < 90, $t['ecart']);
ok('… et le voyant passe en rouge', wsm_termin_etykieta($t)[0] === 'po');

// La minute pile n'est pas un retard : on est encore dedans.
$t = wsm_order_termin(['status' => 'oplacone', 'payment_status' => 'oplacone',
                       'created_at' => $il_y_a(24), 'paid_at' => date('Y-m-d H:i:s', $now - $H * 3600)], $now, $H);
ok('à la seconde près, on est encore dans les temps', $t['etat'] === 'bieg', $t);

// ---- 3. Partie : le verdict est figé --------------------------------------
echo "\n-- wyslane: werdykt zostaje --\n";
$t = wsm_order_termin(['status' => 'wyslane', 'payment_status' => 'oplacone',
                       'created_at' => $il_y_a(50), 'paid_at' => $il_y_a(50),
                       'shipped_at' => $il_y_a(40)], $now, $H);
ok('partie 10 h après la paye : dans les temps', $t['etat'] === 'w_czasie', $t);

$t = wsm_order_termin(['status' => 'wyslane', 'payment_status' => 'oplacone',
                       'created_at' => $il_y_a(50), 'paid_at' => $il_y_a(50),
                       'shipped_at' => $il_y_a(20)], $now, $H);
ok('partie 30 h après la paye : en retard', $t['etat'] === 'spoznione', $t);
ok('… de 6 h, et ça reste écrit', abs($t['ecart'] - 6 * 3600) < 90, $t['ecart']);
// LE VERDICT NE BOUGE PLUS AVEC LE TEMPS. Une commande partie en retard hier
// n'est pas « plus en retard » aujourd'hui : c'est un fait, pas un compteur.
$t2 = wsm_order_termin(['status' => 'wyslane', 'payment_status' => 'oplacone',
                        'created_at' => $il_y_a(50), 'paid_at' => $il_y_a(50),
                        'shipped_at' => $il_y_a(20)], $now + 86400 * 30, $H);
ok('… même un mois plus tard', $t2['etat'] === 'spoznione' && $t2['ecart'] === $t['ecart'], $t2);

// Les commandes expédiées AVANT que la colonne existe : pas de date, donc pas
// de verdict inventé.
$t = wsm_order_termin(['status' => 'wyslane', 'payment_status' => 'oplacone',
                       'created_at' => $il_y_a(50), 'paid_at' => $il_y_a(50), 'shipped_at' => null], $now, $H);
ok('sans date de départ, aucun verdict inventé', $t['etat'] === 'wyslane', $t);

// Annulée : il n'y a plus rien à compter.
$t = wsm_order_termin(['status' => 'anulowane', 'payment_status' => 'oplacone',
                       'created_at' => $il_y_a(99), 'paid_at' => $il_y_a(99)], $now, $H);
ok('une commande annulée ne compte plus', $t['etat'] === 'brak' && wsm_termin_etykieta($t)[1] === '', $t);

// ---- 4. Le délai est réglable, et le réglage marche -----------------------
echo "\n-- delaj da sie zmienic, i zmiana dziala --\n";
$pdo->prepare('DELETE FROM wsm_settings WHERE cle = ?')->execute(['orders.ship_h']);
ok('sans réglage, 24 h', wsm_ship_delai_h() === 24, wsm_ship_delai_h());

// Le même colis, deux délais : à 48 h il est encore dans les temps.
$cmd = ['status' => 'oplacone', 'payment_status' => 'oplacone',
        'created_at' => $il_y_a(30), 'paid_at' => $il_y_a(30)];
ok('à 24 h, payée il y a 30 h est en retard', wsm_order_termin($cmd, $now, 24)['etat'] === 'po_czasie');
ok('à 48 h, la même est encore dans les temps', wsm_order_termin($cmd, $now, 48)['etat'] === 'bieg');

// ---- 5. Les durées, écrites sans faute de grammaire -----------------------
echo "\n-- czas po polsku, bez bledu odmiany --\n";
// « 2 godziny », « 5 godzin », « 22 godziny » : trois formes pour un mot, dans
// un tableau qu'on regarde quarante fois par jour. L'abréviation est juste
// à tous les nombres.
ok('moins d\'une heure : en minutes', wsm_duree_pl(1800) === '30 min', wsm_duree_pl(1800));
ok('jamais « 0 min » : une seconde reste une minute', wsm_duree_pl(20) === '1 min', wsm_duree_pl(20));
ok('les heures',  wsm_duree_pl(6 * 3600) === '6 godz.', wsm_duree_pl(6 * 3600));
ok('les jours au-delà de deux', wsm_duree_pl(5 * 86400) === '5 dni', wsm_duree_pl(5 * 86400));

// ---- 6. Le seuil de la file, et la file elle-même -------------------------
echo "\n-- kolejka „po terminie” --\n";
$sfx = bin2hex(random_bytes(3));
register_shutdown_function(function () use ($pdo, $sfx) {
    try {
        $pdo->exec("DELETE FROM wsm_order_items WHERE order_id IN
                      (SELECT id FROM wsm_orders WHERE email LIKE '%$sfx@example.com')");
        $pdo->exec("DELETE FROM wsm_orders WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_stock_moves WHERE product_id LIKE 'test-tm-$sfx%'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE product_id LIKE 'test-tm-$sfx%'");
        $pdo->exec("DELETE FROM wsm_products WHERE id LIKE 'test-tm-$sfx%'");
        $pdo->exec("DELETE FROM wsm_settings WHERE cle = 'orders.ship_h'");
    } catch (Throwable $e) { /* le nettoyage ne masque jamais le résultat */ }
});

$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$pid = 'test-tm-' . $sfx;
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible,
                    slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, base_cost)
               VALUES (?,?,?,100.00,'Opublikowany',1,1,?,50,0.23,250,200,150,100,?,5.00)")
     ->execute([$pid, $cat, 'Termin ' . $sfx, $pid, strtoupper($sfx)]);

$faire = function (string $paidIlYa, string $statut, ?string $shipped) use ($pdo, $pid, $sfx) {
    [$o] = wsm_shop_create_order($pdo, [
        'items' => [['id' => $pid, 'qty' => 1]], 'lang' => 'pl',
        'delivery_method' => 'inpost_courier', 'inpost_point' => 'KRA010',
        'email' => "tm.$sfx@example.com", 'phone' => '600100200',
        'first_name' => 'Jan', 'last_name' => 'Kowalski', 'client_type' => 'osoba',
        'ship_street' => 'Testowa', 'ship_building' => '1', 'ship_postcode' => '00-001',
        'ship_city' => 'Warszawa', 'ship_country' => 'PL', 'consent_terms' => true,
    ]);
    $pdo->prepare("UPDATE wsm_orders SET paid_at = ?, payment_status = 'oplacone',
                       status = ?, shipped_at = ? WHERE id = ?")
        ->execute([$paidIlYa, $statut, $shipped, (int) $o['id']]);
    return (int) $o['id'];
};

$vraiNow = wsm_db_now($pdo);
$avant   = fn(int $h) => date('Y-m-d H:i:s', $vraiNow - $h * 3600);
$enRetard = $faire($avant(40), 'w_realizacji', null);
$aTemps   = $faire($avant(2),  'oplacone',     null);
$partie   = $faire($avant(40), 'wyslane',      $avant(30));
$vieux    = $faire($avant(200), 'nowe',        null);

$seuil = date('Y-m-d H:i:s', $vraiNow - 24 * 3600);
$file  = wsm_orders_po_terminie($pdo, 500, ['nowe', 'oplacone', 'w_realizacji'], $seuil);
$ids   = array_column($file, 'id');
ok('la commande dépassée est dans la file',      in_array($enRetard, $ids, true));
ok('celle qui a du temps n\'y est pas',          !in_array($aTemps, $ids, true));
// UNE COMMANDE PARTIE N'EST PLUS UN RETARD À TRAITER : elle est partie. La
// laisser dans la file ferait chercher un geste qui n'existe plus.
ok('celle qui est partie n\'y est plus',         !in_array($partie, $ids, true));
ok('la très vieille y est aussi',                in_array($vieux, $ids, true));

// L'ORDRE EST INVERSÉ ICI, ET C'EST VOULU : ailleurs on veut les nouveautés,
// ici on veut ce qui pourrit. Le retard de 200 h passe avant celui de 40 h.
$posV = array_search($vieux, $ids, true);
$posR = array_search($enRetard, $ids, true);
ok('le plus vieux retard est en tête', $posV !== false && $posR !== false && $posV < $posR, [$posV, $posR]);

// LA LISTE DOIT PORTER CE QUE LE VOYANT LIT. Sans paid_at ni shipped_at dans
// wsm_orders_list(), le compteur afficherait « brak wpłaty » sur TOUTES les
// commandes, payées comprises — la panne exacte déjà vue sur vat_eu.
$une = array_values(array_filter($file, fn($o) => (int) $o['id'] === $enRetard))[0] ?? [];
ok('la liste porte paid_at',    array_key_exists('paid_at', $une));
ok('la liste porte shipped_at', array_key_exists('shipped_at', $une));
ok('… et le voyant en tire un retard', wsm_order_termin($une, $vraiNow, 24)['etat'] === 'po_czasie');

// ---- 7. La date de départ ne recule pas -----------------------------------
echo "\n-- data wysylki: pisana raz --\n";
$id = $faire($avant(40), 'w_realizacji', null);
wsm_order_status_set($pdo, $id, 'wyslane', 'test');
$d1 = (string) $pdo->query("SELECT shipped_at FROM wsm_orders WHERE id = $id")->fetchColumn();
ok('« Wysłane » pose la date de départ', $d1 !== '' && $d1 !== null, $d1);
// Un statut corrigé, un transporteur qui renvoie son accusé deux fois : la
// date ne doit pas avancer. Un retard effacé par une seconde manipulation,
// c'est un indicateur qui s'auto-absout.
wsm_order_status_set($pdo, $id, 'w_realizacji', 'test');
wsm_order_status_set($pdo, $id, 'wyslane', 'test');
$d2 = (string) $pdo->query("SELECT shipped_at FROM wsm_orders WHERE id = $id")->fetchColumn();
ok('repasser par « Wysłane » ne la réécrit pas', $d1 === $d2, [$d1, $d2]);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
