<?php
// ============================================================================
//  e2e_prawne.php — Regulamin i Polityka Prywatności.
//
//  POURQUOI CES PAGES EXISTENT MAINTENANT. L'opérateur de paiement a refusé
//  d'activer le compte : ni règlement ni politique de confidentialité publiés.
//  Il avait raison deux fois, parce que la case de la caisse disait
//  « Akceptuję regulamin i politykę prywatności » en ne pointant sur RIEN.
//  On faisait accepter un document inexistant, à chaque commande.
//
//  CE QUE CE TEST GARDE — et chaque point protège d'une façon différente de
//  publier un document faux, ce qui est pire que de n'en publier aucun :
//
//  1. LES CHIFFRES VIENNENT DE LA BOUTIQUE, PAS DU TEXTE. Un règlement qui
//     promet 48 h pendant que la caisse annonce 24 h n'est pas approximatif,
//     il est opposable. Délai d'expédition, prix de port, seuils de gratuité :
//     tous lus dans la configuration réelle.
//  2. AUCUNE ACCOLADE NE SURVIT. Un « {wysylka_h} » resté dans la page est un
//     engagement que le document ne prend pas.
//  3. LE TEXTE EST ÉCHAPPÉ. Il vient d'un champ éditable en console : y
//     laisser passer du HTML ferait de l'écran Treści une porte d'entrée pour
//     du script sur la vitrine.
//  4. LA CASE POINTE SUR LES DEUX DOCUMENTS, et ils répondent.
//
//  Usage :  php tests/e2e_prawne.php
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
$pdo = wsm_bootstrap();

// lib.php de la vitrine : il porte le rendu. Il attend d'être chargé depuis le
// dossier de la boutique (shop_base, seo_origin).
$shop = dirname(__DIR__, 3) . '/shop';
$cwd = getcwd(); chdir($shop);
require_once $shop . '/lib.php';
chdir($cwd);

echo "webshop_mrszoko — end-to-end regulamin i polityka\n\n";

$pl = wsm_shop_strings($pdo, 'pl');

echo "-- oba dokumenty istnieja i maja tresc --\n";
$vals = legal_valeurs($pdo, $pl);
$terms = legal_sections($pl, 'terms', $vals);
$priv  = legal_sections($pl, 'privacy', $vals);
ok('le Regulamin a ses sections', count($terms) >= 10, count($terms));
ok('la Polityka a les siennes',   count($priv) >= 8,  count($priv));
ok('aucune section vide', !array_filter($terms, fn($s) => trim($s[1]) === ''));

echo "\n-- liczby pochodza ze sklepu, nie z tekstu --\n";
$tout = implode(' ', array_column($terms, 1));
// UN RÈGLEMENT QUI CONTREDIT LA CAISSE EST OPPOSABLE. Le délai affiché doit
// être celui que la boutique tient réellement.
ok('le délai d\'expédition est celui réglé dans la boutique',
   str_contains($tout, (string) wsm_ship_delai_h() . ' godzin'), wsm_ship_delai_h());
// Les prix de port aussi : ils changent en console, le document suit.
$m = $pdo->query("SELECT id, price_net, vat_rate FROM wsm_shipping_methods WHERE active = 1 ORDER BY sort_order LIMIT 1")->fetch();
$brut = number_format((int) round((int) $m['price_net'] * (1 + (float) $m['vat_rate'])) / 100, 2, ',', ' ');
ok('… et le prix du premier transporteur actif', str_contains($tout, $brut . ' zł'), $brut);
ok('le vendeur est nommé', str_contains($tout, (string) $pl['seller.name']));
ok('… avec ses numéros d\'immatriculation', str_contains($tout, 'KRS'));

// UNE ACCOLADE RESTÉE DANS LA PAGE est un engagement que le document ne prend
// pas — et personne ne relit un règlement une fois publié.
$reste = [];
preg_match_all('/\{[a-z_]+\}/', $tout . ' ' . implode(' ', array_column($priv, 1)), $reste);
ok('aucune accolade non remplie', $reste[0] === [], $reste[0]);

echo "\n-- tekst z konsoli nie wpuszcza HTML-a --\n";
// Le corps vient d'un champ éditable dans Treści. Y accepter du HTML ferait de
// cet écran une porte d'entrée pour du script sur la vitrine.
$sale = legal_corps('Zwykły <script>alert(1)</script> i **pogrubienie**.', []);
ok('le script est neutralisé', !str_contains($sale, '<script>') && str_contains($sale, '&lt;script&gt;'), $sale);
ok('… mais le gras passe', str_contains($sale, '<strong>pogrubienie</strong>'));
$liste = legal_corps("- jeden <img src=x onerror=alert(1)>\n- dwa", []);
ok('une liste reste une liste', str_contains($liste, '<ul><li>') && substr_count($liste, '<li>') === 2);
ok('… et l\'image piégée aussi est neutralisée', !str_contains($liste, '<img'));

echo "\n-- wylaczenia zwrotu: zywnosc --\n";
// LE POINT QUI PROTÈGE VRAIMENT LA BOUTIQUE. Le chocolat est une denrée
// alimentaire : la loi permet d'exclure le retour d'un produit descellé, mais
// SEULEMENT si le règlement le dit, en nommant l'article.
ok('le règlement cite l\'article 38 de la loi sur les droits du consommateur',
   str_contains($tout, 'art. 38'), null);
ok('… et distingue le point 4 du point 5',
   str_contains($tout, 'pkt 4') && str_contains($tout, 'pkt 5'));
// Et il ne doit pas laisser croire que la garantie légale disparaît avec lui.
ok('… sans laisser croire que la garantie disparaît',
   str_contains($tout, 'Wyłączenie to nie ogranicza'), null);
ok('la garantie de conformité est bien annoncée à deux ans', str_contains($tout, 'dwóch lat'));

echo "\n-- strony i linki --\n";
$idx = (string) @file_get_contents($shop . '/index.php');
$lay = (string) @file_get_contents($shop . '/layout.php');
ok('la route /regulamin existe',  str_contains($idx, "\$page === 'regulamin'"));
ok('la route /prywatnosc existe', str_contains($idx, "\$page === 'prywatnosc'"));
// « W ŁATWO DOSTĘPNYM MIEJSCU » : le pied de page est le seul endroit présent
// sur toutes les pages, y compris sur téléphone où la barre du haut disparaît.
// Le pied de page vient d'Układ strony (layout.php) : les deux documents y
// sont des entrées FIXES — pas d'interrupteur pour les cacher — et leurs
// adresses vivent dans liens_uklad() (lib.php).
require_once dirname(__DIR__) . '/layout.php';
$libShop = (string) @file_get_contents($shop . '/lib.php');
ok('les deux sont dans le pied de page',
   str_contains($lay, "liens_uklad(wsm_pdo(), \$S, \$lang, 'footer')")
   && str_contains($libShop, "u('regulamin')") && str_contains($libShop, "u('prywatnosc')"));
ok('… et personne ne peut les en retirer depuis Układ',
   !empty(wsm_layout_footer_builtins()['regulamin']['fixed']) && !empty(wsm_layout_footer_builtins()['prywatnosc']['fixed']));
// LA CASE POINTAIT SUR RIEN : c'est exactement ce qu'a relevé l'opérateur.
ok('la case de la caisse porte les deux liens',
   str_contains($idx, "u('regulamin')") && str_contains($idx, "u('prywatnosc')"));
ok('… et ils s\'ouvrent dans un autre onglet',
   preg_match("/u\('regulamin'\).*?target=\"_blank\"/s", $idx) === 1);
// Le titre d'une page est au nominatif ; dans une phrase, le polonais le
// décline. Réutiliser le titre donnait « zapoznałem się z Polityka ».
ok('la phrase de la case a ses propres libellés fléchis',
   str_contains($idx, "checkout.terms_l2") && ($pl['checkout.terms_l2'] ?? '') === 'Politykę prywatności',
   $pl['checkout.terms_l2'] ?? null);
ok('l\'ancienne phrase sans lien a disparu de la base', !isset($pl['checkout.terms']));

// Ces pages sont PUBLIQUES : un moteur doit pouvoir les trouver, et un client
// doit pouvoir les lire sans commander.
require_once $shop . '/seo.php';
ok('les pages juridiques sont indexables',
   seo_indexable('regulamin') && seo_indexable('prywatnosc'));

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
