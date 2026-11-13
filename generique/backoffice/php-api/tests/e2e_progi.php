<?php
// ============================================================================
//  e2e_progi.php — la fiche produit et le panier des maquettes de l'équipe.
//
//  « Kup 15 kg, aby otrzymać rabat 10 % … na poziomie kupowania danych
//  produktów, a nie na końcu zakupów », puis le panier : DPD, InPost,
//  Paczkomat en image, la livraison gratuite, les kilos qui manquent —
//  « zintegrowane pod względem ilości kg i cen danego koszyka ». Ce qui est
//  démontré :
//
//   1. LES ÉCRITURES : « 10 kg », « 5,85 kg », « rabat 5 % », les crochets
//      en gras — et le texte de Treści échappé.
//   2. LE CALCUL DU PALIER compte le panier déjà rempli plus la quantité ;
//      les paliers sont ceux de Rabaty, que la caisse applique.
//   3. LA FICHE SERVIE porte la boîte, les paliers et le poids du panier pour
//      que shop.js recalcule ; un produit sans poids n'en a pas.
//   4. LE PANIER SERVI : cartes avec « − / + » et corbeille, transporteurs
//      dessinés, « Gratis » sur chaque mode que le panier rend gratuit, la
//      bannière verte au seuil, le palier suivant en kilos.
//   5. LES GESTES : « + » et « − » ajoutent ou retirent UN, le mode de
//      livraison choisi survit à un changement de quantité, un « + » sur un
//      produit absent ne l'ajoute pas.
//
//  Usage :  php tests/e2e_progi.php      (boutique attendue sur :8091)
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
$pdo = wsm_bootstrap();
$root = dirname(__DIR__, 4);
$lire = fn(string $rel) => (string) @file_get_contents($root . '/' . $rel);
// Les aides de la boutique, sans exécuter la page : lib.php seul.
if (!function_exists('kg_txt')) {
    $code = $lire('generique/shop/lib.php');
    foreach (['e', 'zl', 'kg_txt', 'procent_txt', 'zdanie_html', 'progi_rabatu', 'stan_progu', 'waga_koszyka'] as $f) {
        if (preg_match('/^function ' . $f . '\(.*?^}\n/sm', $code, $m)) eval($m[0]);
    }
}
echo "webshop_mrszoko — end-to-end progi rabatowe i koszyk\n\n";
$nb = "\u{00A0}";

// ---- 1. Les écritures -------------------------------------------------------------
echo "-- zapis kilogramów i procentów --\n";
ok('« 10 kg », « 5,85 kg », « 2,5 kg », « 0,05 kg »', kg_txt(10000) === "10{$nb}kg" && kg_txt(5850) === "5,85{$nb}kg" && kg_txt(2500) === "2,5{$nb}kg" && kg_txt(50) === "0,05{$nb}kg" && kg_txt(100000) === "100{$nb}kg");
ok('« 5 », « 2,5 » pour cent', procent_txt(5.0) === '5' && procent_txt(2.5) === '2,5' && procent_txt(12.0) === '12');
ok('les crochets passent en gras, {x} et {p} remplis',
   zdanie_html('Dodaj kolejne [{x}], aby uzyskać [rabat {p} %].', ['{x}' => '10 kg', '{p}' => '5']) === 'Dodaj kolejne <b>10 kg</b>, aby uzyskać <b>rabat 5 %</b>.');
ok('le texte de Treści est échappé (pas de HTML qui passe)', zdanie_html('<img src=x> [{x}]', ['{x}' => '<b>']) === '&lt;img src=x&gt; <b>&lt;b&gt;</b>');
$js = $lire('generique/shop/shop.js');
ok('shop.js écrit pareil : même règle pour les kilos et les crochets', str_contains($js, "toFixed(2).replace(/\\.?0+$/, '').replace('.', ',') + '\\u00a0kg'")
   && str_contains($js, ".split(/\\[([^\\[\\]]+)\\]/)") && str_contains($js, 'document.createTextNode(part)'));

// ---- 2. Le calcul -------------------------------------------------------------------
echo "\n-- próg: koszyk + ilość --\n";
$P = [['g' => 3000, 'p' => 5.0], ['g' => 10000, 'p' => 12.0], ['g' => 20000, 'p' => 20.0]];
$s = stan_progu($P, 1000);
ok('1 kg : il manque 2 kg pour 5 %, barre au tiers', $s['have'] == 0 && $s['next']['p'] == 5 && $s['missing'] === 2000 && $s['pct'] === 33, $s);
$s = stan_progu($P, 4150);
ok('4,15 kg : 5 % acquis, 5,85 kg pour 12 %', $s['have'] == 5 && $s['next']['p'] == 12 && $s['missing'] === 5850 && $s['pct'] === 41, $s);
$s = stan_progu($P, 10000);
ok('pile 10 kg : 12 % acquis (seuil compris)', $s['have'] == 12 && $s['next']['p'] == 20, $s);
$s = stan_progu($P, 25000);
ok('25 kg : le plus haut, plus rien à promettre, barre pleine', $s['have'] == 20 && $s['next'] === null && $s['pct'] === 100, $s);
$tiers = wsm_discount_tiers($pdo);
$progi = progi_rabatu($pdo);
ok('les paliers de la fiche sont ceux de Rabaty (la caisse)', count($progi) === count(array_filter($tiers, fn($t) => (int) $t['min_weight_g'] > 0 && (float) $t['percent'] > 0))
   && ($progi[0]['g'] ?? 0) === min(array_map(fn($t) => (int) $t['min_weight_g'], $tiers ?: [['min_weight_g' => 0]])));
$prod = $pdo->query("SELECT id, slug, weight_g FROM wsm_products WHERE shop_visible = 1 AND active = 1 AND weight_g > 0 ORDER BY weight_g LIMIT 2")->fetchAll();
ok('deux produits pesés pour la suite', count($prod) === 2, $prod);
[$A, $B] = $prod + [null, null];
ok('le poids du panier : ce que la caisse pèse', waga_koszyka($pdo, [$A['id'] => 2, $B['id'] => 1, 'nie-ma-takiego' => 5]) === 2 * (int) $A['weight_g'] + (int) $B['weight_g']);
[$q] = wsm_shop_quote($pdo, [['id' => $A['id'], 'qty' => 2], ['id' => $B['id'], 'qty' => 1]], 'inpost_locker', 'pl');
ok('… le même que le devis', $q['weight_g'] === waga_koszyka($pdo, [$A['id'] => 2, $B['id'] => 1]));

// ---- 3 à 5. Les pages servies ---------------------------------------------------------
$base = 'http://localhost:8091';
$csrf = bin2hex(random_bytes(16));
$req = function (string $method, string $path, array $cart = [], array $post = []) use ($base, $csrf): array {
    $cookie = 'ms_csrf=' . $csrf . ($cart ? '; ms_cart=' . rawurlencode(json_encode($cart)) : '');
    $opts = ['http' => ['method' => $method, 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0,
             'header' => "Accept-Language: pl\r\nCookie: $cookie\r\n" . ($method === 'POST' ? "Content-Type: application/x-www-form-urlencoded\r\n" : ''),
             'content' => $method === 'POST' ? http_build_query($post + ['_t' => $csrf]) : null]];
    $body = @file_get_contents($base . $path, false, stream_context_create($opts));
    $hdr = $http_response_header ?? [];
    $loc = ''; $newCart = null;
    foreach ($hdr as $h) {
        if (stripos($h, 'Location:') === 0) $loc = trim(substr($h, 9));
        if (preg_match('/^Set-Cookie:\s*ms_cart=([^;]*)/i', $h, $m)) $newCart = (array) json_decode(rawurldecode($m[1]), true);
    }
    return ['body' => $body === false ? null : $body, 'loc' => $loc, 'cart' => $newCart, 'code' => (int) (explode(' ', $hdr[0] ?? '')[1] ?? 0)];
};

echo "\n-- karta produktu (8091) --\n";
$r = $req('GET', '/p/' . $A['slug'], [$B['id'] => 1]);
$h = (string) $r['body'];
if ($r['body'] === null) { echo "  (sklep na 8091 nie odpowiada — pominięte)\n"; }
else {
    // L'habit « Apple » : la page porte sa classe, le bloc d'achat ses cartes
    // de quantité (une par palier encore à atteindre, plus « inna ilość ») et
    // la barre d'achat qui suit le défilement.
    ok('la fiche dans l\'habit « Apple »', str_contains($h, '<body data-added="') && str_contains($h, 'class="strona-p"')
       && str_contains($h, '<form class="buy-form" id="kup-form"') && str_contains($h, 'data-kup-pasek'));
    // La carte « 1 », AU MOINS une carte de palier, et « inna ilość » : sans
    // carte de palier, trois cartes ne se compteraient pas.
    ok('… les cartes de quantité : « 1 », au moins un palier, « inna ilość »',
       preg_match_all('/<label class="ile-opcja" data-n="(\d+)">/', $h, $mN) >= 2 && max(array_map('intval', $mN[1])) > 1
       && str_contains($h, 'ile-opcja--inna') && str_contains($h, '<input type="radio" name="ile" value="inna">'), $mN[1] ?? null);
    ok('… le champ « inna ilość » porte son propre nom', (bool) preg_match('/name="qty"[^>]*aria-label="[^"]+"/s', $h));
    ok('la boîte des paliers, avec le poids du produit et celui du panier', (bool) preg_match('#<div class="prog-rabat" data-prog data-waga="' . (int) $A['weight_g'] . '" data-w-koszyku="' . (int) $B['weight_g'] . '"#', $h));
    // Le nom data-koszyk est RÉSERVÉ au panier vivant : porté par la fiche, il
    // la faisait remplacer après chaque ajout (shop.js), écouteurs perdus.
    ok('… et la fiche ne porte aucun marqueur du panier vivant', !preg_match('/data-koszyk[ >=]/', $h));
    ok('… les paliers de Rabaty pour le calcul du navigateur', str_contains($h, 'data-progi="' . htmlspecialchars(json_encode($progi)) . '"'));
    $st = stan_progu($progi, (int) $B['weight_g'] + (int) $A['weight_g']);
    $attendu = $st['next']
        ? 'Dodaj kolejne <b>' . kg_txt($st['missing']) . '</b>, aby uzyskać <b>rabat ' . procent_txt($st['next']['p']) . ' %</b>.'
        : 'Masz najwyższy rabat ilościowy: <b>' . procent_txt($st['have']) . ' %</b>.';
    ok('la phrase de la maquette, calculée panier compris', str_contains($h, $attendu), $attendu);
    ok('… avec la barre et le cadeau', (bool) preg_match('#<i data-prog-pasek style="width:' . $st['pct'] . '%"></i>#', $h) && str_contains($h, '<svg class="prezent"'));
    ok('… et la note « liczone razem z koszykiem »', str_contains($h, 'Liczone razem z koszykiem (' . kg_txt((int) $B['weight_g']) . ').'));
    // Un produit sans poids ne promet rien.
    $pdo->prepare("UPDATE wsm_products SET weight_g = 0 WHERE id = ?")->execute([$A['id']]);
    $h0 = (string) $req('GET', '/p/' . $A['slug'])['body'];
    $pdo->prepare("UPDATE wsm_products SET weight_g = ? WHERE id = ?")->execute([(int) $A['weight_g'], $A['id']]);
    ok('un produit sans poids : pas de boîte (il ne fait pas avancer la remise)', !str_contains($h0, 'data-prog ') && str_contains($h0, 'buy-form'));
}

echo "\n-- koszyk (8091) --\n";
$cart = [$A['id'] => 1, $B['id'] => 1];
$k = $req('GET', '/koszyk?dostawa=dpd_courier', $cart);
$h = (string) $k['body'];
if ($k['body'] === null) { echo "  (sklep na 8091 nie odpowiada — pominięte)\n"; }
else {
    [$q] = wsm_shop_quote($pdo, [['id' => $A['id'], 'qty' => 1], ['id' => $B['id'], 'qty' => 1]], 'dpd_courier', 'pl');
    ok('le bloc vivant que shop.js remplace', str_contains($h, '<div class="koszyk-zywy" data-koszyk>') && substr_count($h, 'data-koszyk-form') === 3);
    ok('chaque ligne : « − », le champ, « + », la corbeille', substr_count($h, 'class="stepper-btn"') === 4 && substr_count($h, 'class="cart-kosz"') === 2
       && str_contains($h, 'name="krok[' . htmlspecialchars($A['id']) . ']" value="-1"') && str_contains($h, 'aria-label="Usuń: '));
    ok('« Entrée » dans un champ ne déclenche pas le « − » de la première ligne', strpos($h, '<button class="sr-only" type="submit"') < strpos($h, 'class="stepper-btn"'));
    ok('le mode choisi part avec chaque geste', substr_count($h, '<input type="hidden" name="dostawa" value="dpd_courier">') === 2);
    $n = count($q['methods']);
    ok('un dessin par transporteur', substr_count($h, '<svg class="dostawa-rys"') === $n && str_contains($h, '#FFCD00') && str_contains($h, '#DC0032'));
    ok('DPD coché, sa carte en évidence', (bool) preg_match('#<label class="dostawa-opcja is-on">\s*<input type="radio" name="dostawa" value="dpd_courier" checked#', $h));
    $prog = (int) $q['method']['free_from'];
    if (!$q['shipping_free'] && $prog > 0) {
        ok('sous le seuil : ce qui manque, en zł, et la barre', str_contains($h, '<div class="darmowa">') && str_contains($h, 'Do darmowej dostawy brakuje <b>' . htmlspecialchars(zl($prog - $q['items_gross'])) . '</b>.'));
    } else {
        ok('(panier déjà au seuil — bannière verte)', str_contains($h, 'darmowa--ok'));
    }
    $st = stan_progu($progi, (int) $q['weight_g']);
    if ($st['next'] && $st['next']['p'] > (float) $q['discount_percent']) {
        ok('le palier suivant, en kilos, avec le cadeau', str_contains($h, 'Dodaj jeszcze <b>' . kg_txt($st['missing']) . '</b>, aby uzyskać rabat <b>' . procent_txt($st['next']['p']) . ' %</b>.')
           && str_contains($h, 'prog-rabat--koszyk'));
    }
    // Au seuil : la bannière verte et « Gratis » sur chaque mode gratuit.
    $gros = [$A['id'] => 40];
    [$q2] = wsm_shop_quote($pdo, [['id' => $A['id'], 'qty' => 40]], 'inpost_locker', 'pl');
    $h2 = (string) $req('GET', '/koszyk', $gros)['body'];
    if ($q2['shipping_free']) {
        ok('au seuil : « Dostawa gratis, próg osiągnięty. », la coche et le colis', str_contains($h2, 'darmowa darmowa--ok') && str_contains($h2, 'Dostawa gratis, próg osiągnięty.') && str_contains($h2, '<svg class="paczka"'));
        $gratuits = count(array_filter($q2['methods'], fn($m) => $m['free_from'] > 0 && $q2['items_gross'] >= $m['free_from']));
        ok('« Gratis » sur chaque mode que le panier rend gratuit, pas seulement le coché', substr_count($h2, 'class="dostawa-cena is-gratis"') === $gratuits && $gratuits >= 1, [$gratuits, substr_count($h2, 'is-gratis')]);
        ok('la ligne « Dostawa » dit Gratis', (bool) preg_match('#<dt>Dostawa</dt>\s*<dd class="disc">Gratis</dd>#', $h2));
    }

    echo "\n-- gesty: −/+, kosz, dostawa --\n";
    $p = $req('POST', '/koszyk', $cart, ['dostawa' => 'dpd_courier', 'set' => [$A['id'] => 1, $B['id'] => 1], 'krok' => [$A['id'] => 1]]);
    ok('« + » : une pièce de plus', ($p['cart'][$A['id']] ?? 0) === 2 && ($p['cart'][$B['id']] ?? 0) === 1, $p['cart']);
    ok('… et le mode de livraison reste DPD', str_contains($p['loc'], '/koszyk?dostawa=dpd_courier'), $p['loc']);
    $p = $req('POST', '/koszyk', [$A['id'] => 2], ['set' => [$A['id'] => 2], 'krok' => [$A['id'] => -1]]);
    ok('« − » : une de moins', ($p['cart'][$A['id']] ?? 0) === 1, $p['cart']);
    $p = $req('POST', '/koszyk', [$A['id'] => 1], ['set' => [$A['id'] => 1], 'krok' => [$A['id'] => -1]]);
    ok('« − » sur la dernière : la ligne part', $p['cart'] === [] || !isset($p['cart'][$A['id']]), $p['cart']);
    $p = $req('POST', '/koszyk', [$A['id'] => 1], ['set' => [$A['id'] => 1], 'krok' => [$B['id'] => 1]]);
    ok('« + » sur un produit absent : rien n\'entre par là', !isset($p['cart'][$B['id']]), $p['cart']);
    $p = $req('POST', '/koszyk', [$A['id'] => 99], ['set' => [$A['id'] => 99], 'krok' => [$A['id'] => 1]]);
    ok('« + » au plafond : reste à ' . WSM_SHOP_MAX_QTY, ($p['cart'][$A['id']] ?? 0) === WSM_SHOP_MAX_QTY, $p['cart']);
    $p = $req('POST', '/koszyk', $cart, ['dostawa' => 'inpost_courier', 'remove' => $B['id']]);
    ok('la corbeille retire la ligne, le mode reste', !isset($p['cart'][$B['id']]) && str_contains($p['loc'], 'dostawa=inpost_courier'), [$p['cart'], $p['loc']]);
    $p = $req('POST', '/koszyk', $cart, ['dostawa' => 'x"><script>', 'set' => [$A['id'] => 3]]);
    ok('un mode de livraison bricolé n\'entre pas dans l\'adresse', !str_contains($p['loc'], 'script') && !str_contains($p['loc'], 'dostawa='), $p['loc']);
    $p = $req('POST', '/koszyk', $cart, ['krok' => [$A['id'] => 1]]);
    ok('sans le jeton du formulaire : refusé', (function () use ($base, $cart, $A) {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'follow_location' => 0,
            'header' => "Cookie: ms_cart=" . rawurlencode(json_encode($cart)) . "\r\nContent-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(['krok' => [$A['id'] => 1]])]]);
        @file_get_contents($base . '/koszyk', false, $ctx);
        return str_contains($http_response_header[0] ?? '', '400');
    })());
}

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
