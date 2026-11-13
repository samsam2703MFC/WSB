<?php
// ============================================================================
//  e2e_suivi.php — retrouver sa commande sans compte.
//
//  CE QUE CETTE PAGE REMPLACE. Le lien signé du mail de confirmation reste la
//  voie normale, et il est solide : 128 bits de jeton. Mais un mail se perd,
//  se range tout seul, part en indésirables — et le client écrit alors à la
//  boutique pour savoir où est son colis. Quelqu'un ici répondait à la main.
//
//  CE QUE CE TEST GARDE, ET POURQUOI CHAQUE POINT COMPTE :
//
//  1. LE NUMÉRO SEUL N'OUVRE RIEN. Il s'écrit MS-AAMMJJ-0001 : une date et un
//     compteur. Qui connaît le jour d'une commande la trouve en quelques
//     centaines d'essais. Seul le COUPLE numéro + e-mail ouvre.
//
//  2. LES DEUX ÉCHECS SE RESSEMBLENT. Un message différent selon que le numéro
//     existe ou non serait un détecteur de numéros valides : on essaie jusqu'à
//     voir le message changer, puis on s'attaque à l'adresse.
//
//  3. LE PLAFOND PLAFONNE VRAIMENT. Y compris pour un couple juste — un
//     plafond qu'on franchit en tombant juste au bon moment ne protège rien.
//
//  4. L'HORODATAGE EST CALCULÉ EN PHP. MySQL écrit CURRENT_TIMESTAMP dans le
//     fuseau de la session, SQLite en UTC : si les deux bouts ne parlent pas
//     du même fuseau, la fenêtre d'une heure glisse et le compteur retombe à
//     zéro, sans la moindre erreur nulle part.
//
//  Usage :  php tests/e2e_suivi.php
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
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end śledzenie zamówienia\n\n";

$sfx = bin2hex(random_bytes(3));
register_shutdown_function(function () use ($pdo, $sfx) {
    try {
        $pdo->exec("DELETE FROM wsm_order_items WHERE order_id IN
                      (SELECT id FROM wsm_orders WHERE email LIKE '%$sfx@example.com')");
        $pdo->exec("DELETE FROM wsm_orders WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_stock_moves WHERE product_id LIKE 'test-sv-$sfx%'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE product_id LIKE 'test-sv-$sfx%'");
        $pdo->exec("DELETE FROM wsm_products WHERE id LIKE 'test-sv-$sfx%'");
        $pdo->exec("DELETE FROM wsm_order_lookups WHERE ip LIKE '203.0.113.%'");
    } catch (Throwable $e) { /* le nettoyage ne masque jamais le résultat */ }
});

// ---- 1. Le numéro, tel qu'on le recopie -----------------------------------
echo "-- numer zamowienia: przepisany reka --\n";
ok('les minuscules passent',        wsm_suivi_code('ms-260827-0001') === 'MS-260827-0001');
ok('les espaces autour aussi',      wsm_suivi_code('  MS-260827-0001 ') === 'MS-260827-0001');
// Un traitement de texte remplace tout seul le trait d'union par un tiret
// cadratin. Refuser le numéro pour ça, c'est renvoyer le client au formulaire
// de contact qu'on essaie précisément de lui épargner.
ok('le tiret cadratin devient un trait d\'union', wsm_suivi_code("MS\u{2014}260827\u{2014}0001") === 'MS-260827-0001');
// Recopié d'un téléphone, le numéro arrive souvent avec des espaces à la place
// des tirets. Les EFFACER donnerait MS2608270001, qui ne correspond à rien.
ok('les espaces intérieurs deviennent des tirets', wsm_suivi_code('MS 260827 0001') === 'MS-260827-0001');
ok('et les tirets ne s\'empilent pas',             wsm_suivi_code('MS - 260827 - 0001') === 'MS-260827-0001');
ok('ce qui n\'est pas un numéro tombe',            wsm_suivi_code('<script>alert(1)</script>') === 'SCRIPTALERT1SCRIPT');
ok('vide reste vide',                              wsm_suivi_code('   ') === '');

// ---- 2. Une vraie commande -------------------------------------------------
echo "\n-- para: numer + adres --\n";
$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$pid = 'test-sv-' . $sfx;
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible,
                    slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, base_cost)
               VALUES (?,?,?,100.00,'Opublikowany',1,1,?,50,0.23,250,200,150,100,?,5.00)")
     ->execute([$pid, $cat, 'Suivi ' . $sfx, $pid, strtoupper($sfx)]);

$mail = "sv.$sfx@example.com";
[$o, $eo] = wsm_shop_create_order($pdo, [
    'items' => [['id' => $pid, 'qty' => 1]], 'lang' => 'pl',
    'delivery_method' => 'inpost_courier', 'inpost_point' => 'KRA010',
    'email' => $mail, 'phone' => '600100200',
    'first_name' => 'Jan', 'last_name' => 'Kowalski', 'client_type' => 'osoba',
    'ship_street' => 'Testowa', 'ship_building' => '1', 'ship_postcode' => '00-001',
    'ship_city' => 'Warszawa', 'ship_country' => 'PL', 'consent_terms' => true,
]);
ok('la commande de départ passe', $o !== null, $eo);
$code = (string) $o['code'];

$t = wsm_suivi_cherche($pdo, $code, $mail);
ok('le bon couple rend la commande', $t !== null && $t['code'] === $code, $t);
// LE JETON RENDU EST CELUI DE LA COMMANDE : c'est lui qui ouvre la page de
// suivi. Un jeton faux enverrait le client sur un 404 après une recherche
// pourtant réussie.
ok('… avec le jeton qui ouvre la page', $t !== null && $t['token'] === (string) $o['access_token']);

ok('la casse de l\'adresse ne compte pas', wsm_suivi_cherche($pdo, $code, strtoupper($mail)) !== null);
ok('le numéro sale passe aussi',           wsm_suivi_cherche($pdo, str_replace('-', ' ', strtolower($code)), $mail) !== null);

// LE POINT CENTRAL. Le numéro est devinable, l'adresse ne l'est pas : ni l'un
// ni l'autre ne suffit seul.
ok('le numéro seul n\'ouvre rien',   wsm_suivi_cherche($pdo, $code, 'inny@example.com') === null);
ok('l\'adresse seule n\'ouvre rien', wsm_suivi_cherche($pdo, 'MS-200101-0001', $mail) === null);
ok('un numéro vide n\'ouvre rien',   wsm_suivi_cherche($pdo, '', $mail) === null);
ok('une adresse vide non plus',      wsm_suivi_cherche($pdo, $code, '  ') === null);

// ---- 3. Le plafond ---------------------------------------------------------
echo "\n-- limit prob: numer da sie zgadnac --\n";
$ip  = '203.0.113.7';
$ip2 = '203.0.113.8';
$pdo->prepare('DELETE FROM wsm_order_lookups WHERE ip IN (?,?)')->execute([$ip, $ip2]);

ok('au départ, rien ne bloque', !wsm_suivi_trop($pdo, $ip));
for ($i = 0; $i < WSM_SUIVI_MAX_ESSAIS - 1; $i++) wsm_suivi_echec($pdo, $ip, 'MS-200101-000' . $i);
ok('un essai avant le plafond, ça passe encore', !wsm_suivi_trop($pdo, $ip));
wsm_suivi_echec($pdo, $ip, 'MS-200101-9999');
ok('au plafond, ça bloque', wsm_suivi_trop($pdo, $ip));

// Le plafond est PAR ADRESSE : bloquer tout le monde parce qu'un visiteur
// s'acharne fermerait la boutique à ses clients.
ok('une autre adresse n\'est pas punie', !wsm_suivi_trop($pdo, $ip2));
// Sans adresse lisible (proxy exotique, CLI), on ne bloque pas : un plafond
// qui refuse tout le monde n'est pas un plafond.
ok('sans adresse IP, on ne bloque personne', !wsm_suivi_trop($pdo, ''));

// LES VIEILLES TENTATIVES SONT OUBLIÉES. Sans fenêtre glissante, une personne
// qui s'est trompée huit fois hier ne pourrait plus jamais voir sa commande.
$pdo->prepare('UPDATE wsm_order_lookups SET created_at = ? WHERE ip = ?')
    ->execute([date('Y-m-d H:i:s', time() - 7200), $ip]);
ok('deux heures plus tard, le compteur est retombé', !wsm_suivi_trop($pdo, $ip));

// L'HORODATAGE VIENT DE PHP, PAS DU DÉFAUT DE LA BASE. C'est ce qui rend la
// ligne au-dessus vraie sur MySQL comme sur SQLite : leurs CURRENT_TIMESTAMP
// ne sont pas dans le même fuseau, et la fenêtre d'une heure glisserait.
wsm_suivi_echec($pdo, $ip2, 'MS-200101-0001');
$ecart = abs(time() - strtotime((string) $pdo->query(
    "SELECT created_at FROM wsm_order_lookups WHERE ip = '$ip2' ORDER BY id DESC LIMIT 1")->fetchColumn()));
ok('l\'horodatage écrit est bien l\'heure de PHP', $ecart < 120, $ecart);

// ---- 4. La page est branchée ----------------------------------------------
echo "\n-- strona jest podlaczona --\n";
$vitrine = (string) @file_get_contents(dirname(__DIR__, 3) . '/shop/index.php');
$layout  = (string) @file_get_contents(dirname(__DIR__, 3) . '/shop/layout.php');
ok('la route existe', str_contains($vitrine, "\$page === 'moje-zamowienie'"));
// SANS JETON CSRF, le formulaire s'affiche parfaitement et le bouton ne fait
// rien : la boutique refuse tout POST sans lui, avec un « Bad request » nu.
ok('… et le formulaire porte le jeton CSRF', str_contains($vitrine, 'csrf_field()'));
ok('… et un couple juste REDIRIGE vers la page signée',
   str_contains($vitrine, "redirect(u('zamowienie/' . rawurlencode(\$trouve['code'])"));
// Les liens viennent d'Układ strony (layout.php) : la barre et le pied de
// page lisent la même fonction, et « zamowienie » est dans les deux listes
// d'origine. L'adresse elle-même vit dans liens_uklad() (lib.php).
require_once dirname(__DIR__) . '/layout.php';
$libShop = (string) @file_get_contents(dirname(__DIR__, 3) . '/shop/lib.php');
ok('le lien est dans la barre du haut', isset(wsm_layout_nav_builtins()['zamowienie'])
   && str_contains($layout, "liens_uklad(wsm_pdo(), \$S, \$lang, 'nav')"));
// La barre du haut disparaît sous 900 px, et on suit son colis DEPUIS SON
// TÉLÉPHONE : sans le lien du pied de page, le suivi n'existerait que sur
// l'écran où on en a le moins besoin.
ok('… et aussi dans le pied de page, seul visible sur téléphone',
   isset(wsm_layout_footer_builtins()['zamowienie']) && str_contains($layout, "liens_uklad(wsm_pdo(), \$S, \$lang, 'footer')")
   && str_contains($libShop, "case 'zamowienie': \$out[] = ['href' => u('moje-zamowienie')"));

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
