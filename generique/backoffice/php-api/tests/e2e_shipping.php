<?php
// ============================================================================
//  e2e_shipping.php — la file d'expédition.
//
//  CE QU'ELLE EXISTE POUR EMPÊCHER : une commande payée dont le colis n'est
//  jamais créé. Elle ne fait AUCUN bruit — elle est payée, elle attend, et
//  personne ne la cherche. Un numéro de téléphone manquant suffit.
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. ON N'EXPÉDIE PAS UNE COMMANDE IMPAYÉE. Un colis pour une commande
//      non réglée, c'est de la marchandise donnée.
//   2. ON NE CRÉE JAMAIS DEUX FOIS. Deux colis, c'est deux fois le port et
//      un retour à gérer.
//   3. CE QUI BLOQUE EST NOMMÉ EN POLONAIS, avec la raison. « telefon » ne
//      dit rien ; « Brak telefonu — kurier nie ma jak zadzwonić » fait
//      décrocher le téléphone.
//   4. UN ÉCHEC N'ARRÊTE PAS LES AUTRES.
//   5. SANS JETON INPOST, L'ÉCRAN RESTE LISIBLE : c'est un état d'attente
//      annoncé, pas une erreur silencieuse.
//
//  Usage :  php tests/e2e_shipping.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/inpost.php';
require_once dirname(__DIR__) . '/shipping.php';
require_once dirname(__DIR__) . '/mail.php';
$pdo = wsm_bootstrap();
wsm_mail_transport(fn(array $m) => [true, '']);

echo "webshop_mrszoko — end-to-end kolejka wysyłki\n\n";

$sfx = bin2hex(random_bytes(3));
register_shutdown_function(function () use ($pdo, $sfx) {
    try {
        $pdo->exec("DELETE FROM wsm_shipments WHERE order_id IN
                      (SELECT id FROM wsm_orders WHERE email LIKE '%$sfx@example.com')");
        $pdo->exec("DELETE FROM wsm_order_items WHERE order_id IN
                      (SELECT id FROM wsm_orders WHERE email LIKE '%$sfx@example.com')");
        $pdo->exec("DELETE FROM wsm_orders WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_stock_moves WHERE product_id LIKE 'test-sh-$sfx%'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE product_id LIKE 'test-sh-$sfx%'");
        $pdo->exec("DELETE FROM wsm_products WHERE id LIKE 'test-sh-$sfx%'");
    } catch (Throwable $e) { /* le nettoyage ne masque jamais le résultat */ }
});

$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$pid = 'test-sh-' . $sfx;
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible,
                    slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, base_cost)
               VALUES (?,?,?,90.00,'Opublikowany',1,1,?,99,0.23,300,200,150,100,?,5.00)")
     ->execute([$pid, $cat, 'Ship ' . $sfx, $pid, strtoupper($sfx)]);

$base = [
    'items' => [['id' => $pid, 'qty' => 1]], 'lang' => 'pl',
    'delivery_method' => 'inpost_courier', 'inpost_point' => 'KRA010',
    'phone' => '600100200', 'first_name' => 'Jan', 'last_name' => 'Kowalski',
    'client_type' => 'osoba', 'ship_street' => 'Testowa', 'ship_building' => '1',
    'ship_postcode' => '00-001', 'ship_city' => 'Warszawa', 'ship_country' => 'PL',
    'consent_terms' => true,
];
$mk = function (string $qui) use ($pdo, $base, $sfx): array {
    [$o, $e] = wsm_shop_create_order($pdo, $base + ['email' => "$qui.$sfx@example.com"]);
    return [$o, $e];
};

// ---- 1. Seul l'encaissé entre dans la file ---------------------------------------------
echo "-- w kolejce tylko zapłacone --\n";
[$impaye, $e1] = $mk('niezaplacil');
ok('la commande impayée est créée', $impaye !== null, $e1);
[$paye, $e2] = $mk('zaplacil');
ok('la commande payée aussi', $paye !== null, $e2);
wsm_order_mark_paid($pdo, (int) $paye['id'], 'test');

$file = wsm_ship_queue($pdo, 500);
$idsFile = array_map(fn($x) => (int) $x['order']['id'], $file);
ok('la commande PAYÉE est dans la file', in_array((int) $paye['id'], $idsFile, true), count($file));
ok('la commande IMPAYÉE n\'y est PAS — un colis non réglé, c\'est du don',
   !in_array((int) $impaye['id'], $idsFile, true));

// Une commande annulée non plus.
$pdo->prepare("UPDATE wsm_orders SET status = 'anulowane' WHERE id = ?")->execute([(int) $paye['id']]);
$ids2 = array_map(fn($x) => (int) $x['order']['id'], wsm_ship_queue($pdo, 500));
ok('une commande annulée sort de la file', !in_array((int) $paye['id'], $ids2, true));
$pdo->prepare("UPDATE wsm_orders SET status = 'oplacone' WHERE id = ?")->execute([(int) $paye['id']]);

// ---- 2. Ce qui bloque est nommé, en polonais -------------------------------------------
echo "\n-- to, co blokuje, jest nazwane --\n";
$vu = null;
foreach (wsm_ship_queue($pdo, 500) as $x) if ((int) $x['order']['id'] === (int) $paye['id']) $vu = $x;
ok('la commande complète est PRÊTE', $vu !== null && $vu['pret'] === true, $vu['blockers'] ?? null);

// On lui retire le téléphone : elle doit basculer en « bloquée », avec le motif.
$pdo->prepare("UPDATE wsm_orders SET phone = '' WHERE id = ?")->execute([(int) $paye['id']]);
$vu = null;
foreach (wsm_ship_queue($pdo, 500) as $x) if ((int) $x['order']['id'] === (int) $paye['id']) $vu = $x;
ok('sans téléphone, elle est bloquée', $vu !== null && $vu['pret'] === false, $vu['blockers'] ?? null);
ok('et le motif est « telefon »', in_array('telefon', $vu['blockers'] ?? [], true), $vu['blockers'] ?? null);
$phrase = wsm_ship_blocker_label('telefon');
ok('traduit en phrase utilisable', str_contains($phrase, 'telefon') && mb_strlen($phrase) > 20, $phrase);
ok('et elle dit POURQUOI ça compte', str_contains($phrase, 'kurier'), $phrase);

foreach (['e-mail', 'waga', 'paczkomat', 'adres.postcode'] as $code) {
    ok("« $code » a une phrase, pas un code", wsm_ship_blocker_label($code) !== $code, wsm_ship_blocker_label($code));
}

// ---- 3. Le récapitulatif groupe par cause -------------------------------------------------
echo "\n-- podsumowanie grupuje po przyczynie --\n";
$causes = wsm_ship_blockers_summary($pdo);
$tel = null;
foreach ($causes as $c) if ($c['code'] === 'telefon') $tel = $c;
ok('la cause « telefon » est comptée', $tel !== null && (int) $tel['n'] >= 1, $causes);
ok('avec sa phrase', $tel && str_contains((string) $tel['label'], 'kurier'), $tel['label'] ?? null);
$n = array_map(fn($c) => (int) $c['n'], $causes);
$trie = $n; rsort($trie);
ok('les causes sont triées de la plus fréquente à la moins', $n === $trie, $n);

// ---- 4. Le lot : un échec n'arrête pas les autres -------------------------------------------
echo "\n-- partia: jedna porażka nie zatrzymuje reszty --\n";
[$bon, ] = $mk('dobry');
wsm_order_mark_paid($pdo, (int) $bon['id'], 'test');

$r = wsm_ship_batch($pdo, [(int) $paye['id'], (int) $bon['id'], 999999], 'test');
ok('le lot rend un compte rendu', isset($r['utworzone'], $r['bledy']), $r);
ok('la commande sans téléphone est signalée', count($r['bledy']) >= 1, $r['bledy']);
$txt = implode(' | ', $r['bledy']);
ok('et le message nomme la commande', str_contains($txt, (string) $paye['code']), $txt);
ok('une commande inexistante est signalée aussi', str_contains($txt, '999999'), $txt);
ok('sans faire échouer l\'appel', is_array($r['bledy']));

// L'impayée passée de force au lot doit être REFUSÉE.
$r2 = wsm_ship_batch($pdo, [(int) $impaye['id']], 'test');
ok('une commande impayée forcée dans le lot est refusée', $r2['utworzone'] === 0, $r2);
ok('et le message le dit', str_contains(implode(' ', $r2['bledy']), 'niezapłacone'), $r2['bledy']);

// ---- 5. Sans jeton InPost, c'est un ÉTAT, pas un plantage ------------------------------------
echo "\n-- bez tokenu InPost: stan oczekiwania, nie awaria --\n";
ok('InPost est bien non configuré dans ce bac à sable', wsm_inpost_enabled() === false);
$phrase = wsm_ship_erreur_humaine('inpost_nieskonfigurowany');
ok('le message dit quoi faire', str_contains($phrase, 'Ustawieni'), $phrase);
ok('et que les colis partent à la main en attendant', str_contains($phrase, 'ręcznie'), $phrase);
$m = wsm_ship_erreur_humaine('brakujace_dane: telefon, waga');
ok('un blocage multiple est traduit en entier',
   str_contains($m, 'telefon') && str_contains($m, 'Waga'), $m);
ok('une erreur inconnue passe telle quelle', wsm_ship_erreur_humaine('cokolwiek') === 'cokolwiek');
ok('une erreur vide devient une phrase', wsm_ship_erreur_humaine('') !== '');

// ---- 6. On ne crée jamais deux fois -----------------------------------------------------------
echo "\n-- nigdy dwa razy --\n";
// On simule une expédition déjà partie : elle doit être SAUTÉE, pas recréée.
$pdo->prepare("UPDATE wsm_shipments SET tracking_number = ?, status = 'utworzona' WHERE order_id = ?")
    ->execute(['6200000000' . substr($sfx, 0, 4), (int) $bon['id']]);
$r3 = wsm_ship_batch($pdo, [(int) $bon['id']], 'test');
ok('une commande déjà suivie est sautée', $r3['pominiete'] === 1, $r3);
ok('et rien n\'est créé', $r3['utworzone'] === 0, $r3);
ok('le message le dit', str_contains($r3['message'], 'Pominięto'), $r3['message']);

$ids3 = array_map(fn($x) => (int) $x['order']['id'], wsm_ship_queue($pdo, 500));
ok('elle disparaît aussi de la file', !in_array((int) $bon['id'], $ids3, true));

// ---- 7. Les compteurs DÉCRIVENT LA LISTE AFFICHÉE ------------------------------------------------
echo "\n-- liczniki opisują tę listę, nie inną --\n";
$file = wsm_ship_queue($pdo);
$k = wsm_ship_kpis($pdo, $file);
ok('les bloquées sont comptées', $k['bloquees'] >= 1, $k);
ok('prêtes + bloquées = à envoyer', $k['gotowe'] + $k['bloquees'] === $k['do_wyslania'], $k);
ok('les nadanych sont comptées à part', $k['wyslane'] >= 1, $k);

// LE PIÈGE : les compteurs se calculaient sur LEUR propre borne (500) pendant
// que l'écran affichait la sienne (200). Au-delà de deux cents commandes en
// attente, la page annonçait un nombre que la liste ne contenait pas, et le
// bouton « nadaj wszystkie gotowe (300) » promettait ce qu'il ne ferait pas.
$court = wsm_ship_queue($pdo, 3);
$kc = wsm_ship_kpis($pdo, $court);
ok('le compteur suit la borne de la liste qu\'on lui donne',
   $kc['do_wyslania'] === count($court) && count($court) <= 3, [$kc['do_wyslania'], count($court)]);
ok('et ne va PAS rechercher plus loin tout seul', $kc['do_wyslania'] <= 3, $kc);
$cc = wsm_ship_blockers_summary($pdo, $court);
$somme = array_sum(array_map(fn($c) => (int) $c['n'], $cc));
$attendu = array_sum(array_map(fn($x) => count($x['blockers']), $court));
ok('le récapitulatif des causes décrit la même liste', $somme === $attendu, [$somme, $attendu]);

// ---- 8. Ce qui est parti se relit ---------------------------------------------------------------
echo "\n-- co poszło, da się odczytać --\n";
$partis = wsm_ship_sent($pdo, 50);
$vuP = null;
foreach ($partis as $s) if ((int) $s['order_id'] === (int) $bon['id']) $vuP = $s;
ok('l\'expédition partie est listée', $vuP !== null, count($partis));
ok('avec son numéro de suivi', $vuP && trim((string) $vuP['tracking_number']) !== '');
ok('et le code de sa commande', $vuP && (string) $vuP['code'] === (string) $bon['code']);
ok('les états ont tous un libellé',
   count(WSM_SHIP_STATUSES) >= 4 && !in_array('', array_values(WSM_SHIP_STATUSES), true));

// ---- Le pays commande la livraison ----------------------------------------
//
// C'EST LA RÈGLE QU'ON OUBLIE. Un Paczkomat est polonais : le proposer pour
// une adresse allemande, c'est promettre un colis qu'aucun transporteur ne
// prendra. Le formulaire filtre déjà, mais un formulaire n'est pas un
// contrôle — il se modifie dans le navigateur, et le pays peut changer APRÈS
// le choix du mode.
echo "\n-- kraj rządzi dostawą --\n";
require_once dirname(__DIR__) . '/shop.php';
$pidS = $pdo->query("SELECT id FROM wsm_products WHERE shop_visible = 1 LIMIT 1")->fetchColumn();
if ($pidS) {
    $pdo->exec("UPDATE wsm_countries SET active = 1 WHERE code = 'DE'");
    $panier = [['id' => $pidS, 'qty' => 1]];

    [$qPL, $ePL] = wsm_shop_quote($pdo, $panier, 'inpost_locker', 'pl', ['country' => 'PL']);
    ok('un Paczkomat pour la Pologne passe', !isset($ePL['delivery_method']), $ePL);

    [$qDE, $eDE] = wsm_shop_quote($pdo, $panier, 'inpost_locker', 'pl', ['country' => 'DE']);
    ok('LE MÊME Paczkomat pour l\'Allemagne est REFUSÉ',
        isset($eDE['delivery_method']), $eDE);
    // Et le refus doit nommer le PAYS, pas la méthode : « nieznana metoda »
    // envoie le client essayer l'autre mode, puis recommencer, sans jamais
    // comprendre que le problème est son adresse.
    ok('et le refus nomme le pays, pas la méthode',
        str_contains((string) ($eDE['delivery_method'] ?? ''), 'kraju'), $eDE['delivery_method'] ?? '');

    [, $eF] = wsm_shop_quote($pdo, $panier, 'dhl_express', 'pl', ['country' => 'PL']);
    ok('une méthode inventée par le client est refusée', isset($eF['delivery_method']), $eF);

    // Le filtrage lui-même, sans le devis.
    ok('la Pologne a des transporteurs', count(wsm_shipping_methods($pdo, 'pl', 'PL')) > 0);
    ok('l\'Allemagne n\'en a aucun', wsm_shipping_methods($pdo, 'pl', 'DE') === []);
    ok('sans pays, on ne filtre pas — la vitrine montre l\'offre',
        count(wsm_shipping_methods($pdo, 'pl', '')) > 0);
    $pdo->exec("UPDATE wsm_countries SET active = 0 WHERE code = 'DE'");
} else {
    echo "  · pas de produit visible — règle pays non exercée, ne compte PAS pour vert\n";
}

// ---- La même règle, mais TELLE QU'ELLE S'AFFICHE ---------------------------
//
// Tout ce qui précède interroge l'adaptateur. L'adaptateur avait raison, et
// la caisse affichait quand même un champ « Paczkomat — KRA010 » sous la
// phrase « nous ne livrons pas encore dans ce pays ». Les deux ne peuvent pas
// être vrais en même temps, et c'est le champ qu'on croit : on remplit donc
// un panier comme un client et on LIT la page.
echo "\n-- ta sama zasada, ale tak jak ją widzi klient --\n";
$SHOP = rtrim(getenv('WSM_SHOP_URL') ?: 'http://localhost:8091', '/');
$jarS = tempnam(sys_get_temp_dir(), 'wsmshp');
$get = function (string $url, array $post = []) use ($jarS): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15, CURLOPT_COOKIEJAR => $jarS, CURLOPT_COOKIEFILE => $jarS]);
    if ($post) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
};
[$cAcc, $acc] = $get("$SHOP/");
if ($cAcc !== 200) {
    echo "  · boutique injoignable ($SHOP) — rendu non exercé, ne compte PAS pour vert\n";
} else {
    preg_match('/name="add" value="([^"]+)"/', $acc, $mA);
    preg_match('/name="_t" value="([^"]+)"/', $acc, $mT);
    if (!isset($mA[1], $mT[1])) {
        echo "  · aucun produit en vitrine — rendu non exercé, ne compte PAS pour vert\n";
    } else {
        $get("$SHOP/koszyk", ['_t' => $mT[1], 'add' => $mA[1], 'qty' => 1]);
        [, $kPL] = $get("$SHOP/kasa");
        // 1. Là où l'on livre, le client doit POUVOIR désigner son Paczkomat.
        ok('Pologne : le champ Paczkomat est rendu', str_contains($kPL, 'id="f-inpost_point"'));

        // 2. Là où l'on ne livre pas, il ne doit RIEN y avoir à remplir.
        $pdo->exec("UPDATE wsm_countries SET active = 1 WHERE code = 'DE'");
        [, $kDE] = $get("$SHOP/kasa?kraj=DE");
        ok('Allemagne : AUCUN champ Paczkomat', !str_contains($kDE, 'id="f-inpost_point"'));
        // Et l'absence doit être EXPLIQUÉE : une caisse qui se contente de
        // retirer les champs ressemble à une page cassée. On exige la phrase
        // réelle — pas « la page contient un encadré », qui serait vrai de
        // toute façon et ne prouverait rien.
        $phrase = (string) (wsm_shop_strings($pdo, 'pl')['checkout.no_shipping'] ?? '');
        ok('Allemagne : l\'absence de livraison est écrite en toutes lettres',
           $phrase !== '' && str_contains($kDE, htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8')), $phrase);
        // Et la CLÉ ne doit jamais s'afficher à la place du texte : c'est ce
        // qui arrive quand la table i18n n'a pas été synchronisée.
        ok('Allemagne : c\'est la phrase qui s\'affiche, pas la clé i18n',
           !str_contains($kDE, 'checkout.no_shipping'));
        $pdo->exec("UPDATE wsm_countries SET active = 0 WHERE code = 'DE'");

        // 3. Le sélecteur sur carte est un ENRICHISSEMENT. Sans jeton il ne
        //    s'affiche pas — et surtout, le champ texte reste, sinon on aurait
        //    remplacé un champ qui marche par un bouton mort.
        //
        //    ON JUGE LA PAGE SUR ELLE-MÊME. Comparer avec la configuration lue
        //    ICI n'aurait aucun sens : la boutique tourne dans un AUTRE
        //    processus, qui peut très bien ne pas voir le même jeton. Ce qui
        //    doit être vrai quoi qu'il arrive, c'est que le sélecteur ne
        //    s'affiche jamais SANS jeton utilisable — la règle fail-closed.
        if (preg_match('/<inpost-geowidget[^>]*token="([^"]*)"/', $kPL, $mG)) {
            ok('le sélecteur affiché porte un jeton utilisable',
               $mG[1] !== '' && strtolower($mG[1]) !== 'xxxx', $mG[1]);
            ok('et le script du sélecteur est chargé avec lui',
               str_contains($kPL, 'geowidget.inpost.pl/inpost-geowidget.js'));
        } else {
            ok('sans sélecteur, aucun appel au domaine tiers n\'est fait',
               !str_contains($kPL, 'geowidget.inpost.pl'));
        }
        ok('avec ou sans carte, le champ texte reste — jamais de bouton seul',
           str_contains($kPL, 'id="f-inpost_point"'));
        // Le domaine tiers n'est chargé QUE sur la caisse, et QUE s'il sert.
        [, $accueil] = $get("$SHOP/");
        ok('geowidget.inpost.pl n\'est pas chargé sur la page d\'accueil',
           !str_contains($accueil, 'geowidget.inpost.pl'));
    }
}
@unlink($jarS);

// ---------------------------------------------------------------------------
//  LE JETON DU SÉLECTEUR DE PACZKOMAT — CE QU'IL DIT DE LUI-MÊME
//
//  « Brak dostępu, sprawdź czy token został wygenerowany dla odpowiedniej
//  witryny. » Cette phrase est arrivée en production, dans la caisse, à la
//  place de la carte. Elle ne nomme NI le site pour lequel la clé a été faite,
//  NI celui qui sert la boutique — et elle recouvre trois causes distinctes.
//
//  On ne peut rien demander à InPost depuis ici. On n'en a pas besoin : la clé
//  est un JWT, elle porte ses déclarations en clair. Les lire et les mettre en
//  face de l'adresse réelle transforme un refus muet en une phrase qui dit
//  quoi faire. Ce qui suit vérifie que chaque cause reçoit SA phrase — se
//  tromper de cause enverrait régénérer une clé qui n'a rien à se reprocher.
// ---------------------------------------------------------------------------
echo "\n-- token mapy paczkomatów : co sam o sobie mówi --\n";

$jwt = function (array $charge): string {
    $b = fn($x) => rtrim(strtr(base64_encode((string) json_encode($x)), '+/', '-_'), '=');
    return $b(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $b($charge) . '.podpis';
};
$pose = function (string $t) { wsm_config_overlay(['inpost' => ['geowidget_token' => $t]]); };

// 1. Aucun jeton : la carte ne s'affiche pas, ce n'est pas une panne.
$pose('');
[$e1] = wsm_inpost_geo_verdict('sklep.mrszoko.pl');
ok('sans jeton, on avertit sans crier à la panne', $e1 === 'uwaga', $e1);
$pose('xxxx');
[$e1b] = wsm_inpost_geo_verdict('sklep.mrszoko.pl');
ok('« xxxx » compte pour un jeton absent', $e1b === 'uwaga', $e1b);

// 2. La confusion la plus facile : coller la clé SERVEUR ShipX à la place.
$pose('a1b2c3d4e5f6a7b8c9d0');
[$e2, $p2] = wsm_inpost_geo_verdict('sklep.mrszoko.pl');
ok('un jeton qui n\'est pas un JWT est signalé comme mauvais', $e2 === 'zle');
ok('et la phrase nomme la confusion probable (ShipX)', str_contains($p2, 'ShipX'), $p2);

// 3. Le jeton nomme un AUTRE site que celui qui sert la boutique. C'est le cas
//    rencontré : une clé faite pour un domaine, une boutique servie sous un
//    autre. La phrase doit citer LES DEUX, sinon elle n'aide pas.
$pose($jwt(['exp' => time() + 86400, 'aud' => 'https://sklep.mrszoko.pl']));
[$e3, $p3] = wsm_inpost_geo_verdict('185.180.206.46');
ok('un jeton fait pour un autre site est signalé comme mauvais', $e3 === 'zle');
ok('la phrase cite le site du jeton', str_contains($p3, 'sklep.mrszoko.pl'), $p3);
ok('ET l\'adresse réelle de la boutique', str_contains($p3, '185.180.206.46'), $p3);

// 4. Le même jeton, sur le bon site : rien à signaler.
[$e4, $p4] = wsm_inpost_geo_verdict('sklep.mrszoko.pl');
ok('sur le bon site, le jeton est déclaré valable', $e4 === 'ok', $p4);
ok('le port ne fait pas échouer la comparaison — HTTP_HOST le porte, pas le jeton',
   wsm_inpost_geo_verdict('sklep.mrszoko.pl:8093')[0] === 'ok');

// Un joker de domaine doit être accepté, sinon on ferait régénérer une clé
// parfaitement bonne.
$pose($jwt(['exp' => time() + 86400, 'aud' => '*.mrszoko.pl']));
ok('un joker « *.mrszoko.pl » couvre un sous-domaine',
   wsm_inpost_geo_verdict('sklep.mrszoko.pl')[0] === 'ok');
ok('mais pas un domaine étranger',
   wsm_inpost_geo_verdict('sklep.autre.pl')[0] === 'zle');

// 5. Expiré : même écran chez InPost, cause tout autre. Se tromper ici enverrait
//    changer un domaine qui est déjà le bon.
$pose($jwt(['exp' => time() - 86400, 'aud' => 'https://sklep.mrszoko.pl']));
[$e5, $p5] = wsm_inpost_geo_verdict('sklep.mrszoko.pl');
ok('un jeton expiré est signalé comme tel, pas comme un problème de domaine',
   $e5 === 'zle' && str_contains($p5, 'wygasł'), $p5);

// 6. Un JWT valide qui ne nomme aucun site : on ne prétend pas savoir.
$pose($jwt(['exp' => time() + 86400, 'sub' => 'abc-123']));
[$e6, $p6] = wsm_inpost_geo_verdict('sklep.mrszoko.pl');
ok('sans site déclaré, on n\'affirme rien — on dit où regarder', $e6 === 'uwaga', $p6);
ok('et on rappelle l\'adresse à vérifier', str_contains($p6, 'sklep.mrszoko.pl'), $p6);

// 7. Ce qu'on lit n'autorise RIEN : un jeton illisible ne doit pas faire tomber
//    la page qui l'affiche.
ok('une chaîne quelconque ne casse pas la lecture', wsm_jwt_charge('nawet.nie.jwt') === []);
ok('une chaîne vide non plus', wsm_jwt_charge('') === []);
$pose('');

// ---------------------------------------------------------------------------
//  LES DEUX BORNES DE POIDS, ET LE TRANSPORTEUR QU'ON NE PILOTE PAS
//
//  Un transporteur de palettes commence à 200 kg. Deux erreurs symétriques, et
//  aucune ne lève quoi que ce soit :
//
//   · proposer « Fresh Logistic — à partir de 200 kg » pour une tablette de
//     chocolat : le client le choisit, et la palette est refusée APRÈS la
//     vente ;
//   · continuer à proposer un Paczkomat pour 30 kg — ce que la caisse faisait
//     jusqu'ici — alors que DPD, juste au-dessus, l'aurait pris. Un
//     aller-retour au moment le plus fragile du parcours, sur une commande
//     pourtant livrable.
//
//  La liste se filtre donc AVANT d'être montrée, et le refus dit laquelle des
//  trois choses cloche : le pays, le poids, ou la méthode.
// ---------------------------------------------------------------------------
echo "\n-- dwie granice wagi --\n";
require_once dirname(__DIR__) . '/shipping.php';
$sfxW = 'test-wg-' . bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO wsm_shipping_methods
   (id, carrier, kind, sort_order, active, price_net, vat_rate, free_from,
    min_weight_g, max_weight_g, countries)
   VALUES (?,?,?,?,?,?,?,?,?,?,?)")
   ->execute([$sfxW . '-pal', 'fresh', 'adres', 90, 1, 89000, 0.23, 0, 200000, 1500000, 'PL']);
$pdo->prepare("INSERT INTO wsm_shipping_methods
   (id, carrier, kind, sort_order, active, price_net, vat_rate, free_from,
    min_weight_g, max_weight_g, countries)
   VALUES (?,?,?,?,?,?,?,?,?,?,?)")
   ->execute([$sfxW . '-mal', 'inpost', 'punkt', 91, 1, 1130, 0.23, 0, 0, 25000, 'PL']);

$ids = fn(int $g) => array_column(wsm_shipping_methods($pdo, 'pl', 'PL', $g), 'id');
ok('un panier léger ne voit PAS le transport de palettes',
   !in_array($sfxW . '-pal', $ids(500), true), $ids(500));
ok('mais voit bien le petit colis', in_array($sfxW . '-mal', $ids(500), true));
ok('un panier de 300 kg voit le transport de palettes',
   in_array($sfxW . '-pal', $ids(300000), true), $ids(300000));
ok('et ne voit plus le petit colis — il ne le prendrait pas',
   !in_array($sfxW . '-mal', $ids(300000), true));
ok('au-dessus du plafond du palettier, plus rien de lui',
   !in_array($sfxW . '-pal', $ids(2000000), true));
// Sans poids, la liste n'est PAS filtrée : c'est ce dont l'écran Dostawa et
// la page d'accueil ont besoin — montrer ce qui existe, pas ce qui s'applique.
ok('sans poids, rien n\'est filtré',
   in_array($sfxW . '-pal', array_column(wsm_shipping_methods($pdo, 'pl', 'PL'), 'id'), true));
// La borne est INCLUSIVE des deux côtés : à 200 kg pile, la palette passe.
ok('à la borne exacte, la méthode est proposée',
   in_array($sfxW . '-pal', $ids(200000), true));
ok('un gramme en dessous, elle ne l\'est pas',
   !in_array($sfxW . '-pal', $ids(199999), true));

// Le transporteur connu mais NON PILOTÉ ne doit pas passer pour inconnu.
echo "\n-- przewoźnik znany, ale nadawany ręcznie --\n";
$cmd = ['delivery_method' => $sfxW . '-pal', 'phone' => '600100200', 'email' => 'a@b.pl',
        'first_name' => 'Jan', 'company' => '', 'weight_g' => 250000,
        'ship' => ['street' => 'Polna', 'building' => '1', 'postcode' => '00-001', 'city' => 'Wrocław']];
ok('il est reconnu comme manuel', wsm_ship_manuel($pdo, $cmd) === 'Fresh Logistic',
   wsm_ship_manuel($pdo, $cmd));
ok('une commande complète ne bloque sur RIEN', wsm_ship_blockers($pdo, $cmd) === [],
   wsm_ship_blockers($pdo, $cmd));
// LE point : il ne doit surtout pas répondre « Nieznany przewoźnik », qui
// enverrait chercher une faute de configuration là où il n'y en a pas.
ok('et surtout pas « przewoznik » — la commande est bonne, pas la config',
   !in_array('przewoznik', wsm_ship_blockers($pdo, $cmd), true));
$sansTel = $cmd; $sansTel['phone'] = '';
ok('les contrôles de données s\'appliquent quand même',
   in_array('telefon', wsm_ship_blockers($pdo, $sansTel), true));
[$exp, $err] = wsm_ship_create($pdo, $cmd);
ok('la création s\'arrête, et le dit en clair', $exp === null && str_contains($err, 'nadanie_reczne'), $err);
ok('le message NOMME le transporteur', str_contains($err, 'Fresh Logistic'), $err);

$pdo->exec("DELETE FROM wsm_shipping_methods WHERE id LIKE '" . $sfxW . "%'");

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
