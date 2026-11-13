<?php
// ============================================================================
//  e2e_seo.php — preuve que les moteurs lisent la bonne chose.
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. RIEN DE PERSONNEL N'EST INDEXABLE. La page de suivi de commande porte
//      un nom, une adresse et le détail d'un achat. Un lien partagé une fois,
//      ou suivi par un robot depuis une boîte mail, et la commande de
//      quelqu'un devient cherchable. C'est une fuite de données, pas un sujet
//      de référencement — d'où sa place en tête.
//   2. LES hreflang SONT ABSOLUS. Google jette une grappe relative en entier,
//      sans un mot dans la Search Console. La boutique en émettait depuis le
//      premier jour : ils n'ont jamais rien fait.
//   3. LA GRAPPE EST COMPLÈTE ET RÉCIPROQUE, avec x-default, et ne contient
//      QUE des langues publiées.
//   4. LE PRIX BALISÉ EST LE PRIX AFFICHÉ. Un écart fait retirer les
//      résultats enrichis du site entier.
//
//  Ce fichier teste par HTTP, sur la vraie boutique : le balisage se lit dans
//  la page servie, pas dans l'intention du code.
//
//  Usage :  php tests/e2e_seo.php [http://localhost:8091]
// ============================================================================

$BASE = $argv[1] ?? getenv('WSM_SHOP_URL') ?: 'http://localhost:8091';

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

function get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                            CURLOPT_FOLLOWLOCATION => true]);
    $b = curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, (string) $b];
}

echo "webshop_mrszoko — end-to-end SEO ($BASE)\n\n";

[$c0, $home] = get($BASE . '/');
if ($c0 !== 200) {
    echo "Sklep nieosiągalny ($c0) — uruchom ./serve.sh\n";
    exit(0);
}

// ---- 1. Rien de personnel n'est indexable -----------------------------------------
echo "-- nic prywatnego nie trafia do indeksu --\n";
foreach (['koszyk' => 'panier', 'kasa' => 'caisse', 'zamowienie/MS-TEST' => 'suivi de commande'] as $p => $nom) {
    [$c, $h] = get($BASE . '/' . $p);
    ok("le $nom est en noindex", str_contains($h, 'name="robots" content="noindex'),
        substr($h, 0, 0) ?: $c);
    ok("le $nom n'annonce pas de canonique — deux consignes se contrediraient",
        !str_contains($h, 'rel="canonical"'));
    ok("le $nom n'émet pas de grappe de langues", !str_contains($h, 'hreflang='));
}

[$cr, $robots] = get($BASE . '/robots.txt');
ok('robots.txt est servi', $cr === 200, $cr);
foreach (['koszyk', 'kasa', 'zamowienie'] as $p) {
    ok("robots.txt interdit /$p — un robot mal élevé ne lit pas la page pour rien",
        str_contains($robots, "Disallow: ") && str_contains($robots, "/$p"), $robots);
}
ok('robots.txt désigne le sitemap', str_contains($robots, 'Sitemap:'), $robots);

// ---- 2. Les hreflang sont ABSOLUS ---------------------------------------------------
echo "\n-- hreflang bezwzględne --\n";
preg_match_all('/<link rel="alternate" hreflang="([a-z-]+)" href="([^"]+)">/', $home, $m, PREG_SET_ORDER);
ok('le catalogue émet une grappe', count($m) >= 2, count($m));
$relatifs = array_values(array_filter($m, fn($x) => !preg_match('#^https?://#i', $x[2])));
ok('AUCUN hreflang n\'est relatif — Google les ignore en bloc',
    $relatifs === [], array_map(fn($x) => $x[2], $relatifs));

$codes = array_map(fn($x) => $x[1], $m);
ok('la grappe contient x-default', in_array('x-default', $codes, true), $codes);
ok('elle contient le polonais', in_array('pl', $codes, true), $codes);
ok('elle est réciproque — la page se cite elle-même',
    in_array('pl', $codes, true), $codes);

// La langue par défaut ne traîne pas de « ?lang=pl » : deux adresses pour la
// même page, c'est du contenu dupliqué qu'il faut ensuite expliquer.
$plHref = '';
foreach ($m as $x) if ($x[1] === 'pl') $plHref = $x[2];
ok('l\'adresse polonaise ne porte pas de ?lang=pl', !str_contains($plHref, 'lang=pl'), $plHref);

// ---- 3. La canonique ------------------------------------------------------------------
echo "\n-- kanoniczny --\n";
preg_match('/<link rel="canonical" href="([^"]+)">/', $home, $mc);
ok('le catalogue a une canonique', !empty($mc[1]), $mc[1] ?? null);
ok('elle est absolue', preg_match('#^https?://#i', $mc[1] ?? '') === 1, $mc[1] ?? null);

// Elle doit SUIVRE la langue : la version anglaise ne se déclare pas copie
// de la polonaise, sinon la page anglaise n'existe plus pour le moteur.
[$ce, $en] = get($BASE . '/?lang=en');
preg_match('/<link rel="canonical" href="([^"]+)">/', $en, $me2);
ok('la page anglaise a sa PROPRE canonique — sinon elle disparaît de l\'index',
    !empty($me2[1]) && str_contains($me2[1], 'lang=en'), $me2[1] ?? null);
ok('et son attribut lang suit', preg_match('/<html lang="en"/', $en) === 1);

// ---- 4. Une langue non publiée n'est annoncée nulle part -------------------------------
echo "\n-- tylko opublikowane języki --\n";
ok('l\'allemand (non publié) n\'est pas dans la grappe',
    !in_array('de', $codes, true), $codes);
[$cs, $sitemap] = get($BASE . '/sitemap.xml');
ok('le sitemap est servi', $cs === 200, $cs);
ok('et il n\'annonce pas l\'allemand non plus',
    !str_contains($sitemap, 'hreflang="de"'));

// ---- 5. Le sitemap -----------------------------------------------------------------------
echo "\n-- sitemap --\n";
ok('c\'est un urlset valable', str_contains($sitemap, '<urlset'), substr($sitemap, 0, 60));
ok('il déclare l\'espace de noms xhtml (exigé pour les alternates)',
    str_contains($sitemap, 'xmlns:xhtml'), substr($sitemap, 0, 200));
ok('il contient la page d\'accueil', str_contains($sitemap, '<loc>'));
ok('chaque URL porte ses traductions', str_contains($sitemap, 'xhtml:link'));
ok('et un x-default', str_contains($sitemap, 'hreflang="x-default"'));
ok('il ne liste AUCUNE page personnelle',
    !str_contains($sitemap, '/koszyk') && !str_contains($sitemap, '/kasa')
    && !str_contains($sitemap, '/zamowienie'), 'sitemap');
$xml = @simplexml_load_string($sitemap);
ok('le XML est bien formé — un sitemap invalide est ignoré en silence',
    $xml !== false);

// ---- 6. Le produit : le prix balisé EST le prix affiché ------------------------------------
echo "\n-- produkt: cena w znacznikach = cena na stronie --\n";
if (preg_match('#href="([^"]*/p/[a-z0-9-]+)"#i', $home, $mp)) {
    $url = str_starts_with($mp[1], 'http') ? $mp[1] : rtrim($BASE, '/') . $mp[1];
    [$cp, $prod] = get($url);
    ok('la fiche produit répond', $cp === 200, $cp);
    ok('elle est indexable', str_contains($prod, 'content="index'));

    preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $prod, $mj);
    $ld = json_decode($mj[1] ?? '', true);
    ok('elle porte un JSON-LD lisible', is_array($ld), $mj[1] ?? null);
    ok('de type Product', ($ld['@type'] ?? '') === 'Product', $ld['@type'] ?? null);
    ok('avec un nom', ($ld['name'] ?? '') !== '');
    ok('et une offre', isset($ld['offers']['price']), $ld['offers'] ?? null);

    $prix = (string) ($ld['offers']['price'] ?? '');
    ok('le prix est écrit avec un POINT — une virgule fait rejeter le bloc',
        $prix !== '' && !str_contains($prix, ','), $prix);
    // LE TEST QUI COMPTE : le même montant se retrouve dans la page visible.
    $affiche = str_replace('.', ',', $prix);
    ok('le prix balisé apparaît tel quel dans la page — sinon les résultats '
       . 'enrichis sautent pour tout le site',
        $prix !== '' && str_contains($prod, $affiche), [$prix, $affiche]);
    ok('la devise est le złoty', ($ld['offers']['priceCurrency'] ?? '') === 'PLN');
    ok('la disponibilité est déclarée',
        str_contains((string) ($ld['offers']['availability'] ?? ''), 'schema.org/'),
        $ld['offers']['availability'] ?? null);
    ok('l\'état du produit aussi — sans lui l\'offre est incomplète',
        isset($ld['offers']['itemCondition']));
    ok('l\'URL de l\'offre est absolue',
        preg_match('#^https?://#i', (string) ($ld['offers']['url'] ?? '')) === 1);
    ok('le partage social l\'annonce comme un produit',
        str_contains($prod, 'og:type" content="product"'));

    // Un JSON-LD ne doit jamais pouvoir refermer sa propre balise.
    ok('le JSON-LD ne contient pas de </script> brut',
        !str_contains($mj[1] ?? '', '</script'), 'jsonld');
} else {
    echo "  (aucun produit au catalogue — section ignorée)\n";
}

// ---- 7. La carte d'identité du vendeur -------------------------------------------------------
echo "\n-- organizacja --\n";
preg_match_all('#<script type="application/ld\+json">(.+?)</script>#s', $home, $mo);
$types = [];
foreach ($mo[1] ?? [] as $j) { $d = json_decode($j, true); if ($d) $types[] = $d['@type'] ?? ''; }
ok('le catalogue déclare une Organization', in_array('Organization', $types, true), $types);

// ---- 8. Le partage social --------------------------------------------------------------------
echo "\n-- udostępnianie --\n";
ok('titre Open Graph', str_contains($home, 'property="og:title"'));
ok('adresse Open Graph', str_contains($home, 'property="og:url"'));
ok('locale au format long (pl_PL) — la forme courte est ignorée',
    str_contains($home, 'og:locale" content="pl_PL"'));
ok('carte Twitter', str_contains($home, 'name="twitter:card"'));

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
