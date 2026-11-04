<?php
// ============================================================================
//  seo.php — ce que les moteurs lisent de la boutique, en huit langues.
//
//  CINQ RÈGLES, DANS L'ORDRE D'IMPORTANCE :
//
//   1. TOUT CE QUI EST PERSONNEL EST `noindex`. Le panier, la caisse et
//      surtout la page de suivi de commande — qui porte un nom, une adresse
//      et le détail d'un achat. Un lien de suivi partagé une fois, ou suivi
//      par un robot depuis un e-mail, et la commande de quelqu'un devient
//      cherchable. Ce n'est pas une question de référencement, c'est une
//      fuite de données. Elle passe d'abord.
//
//   2. LES hreflang SONT ABSOLUS. Google ignore purement et simplement un
//      hreflang relatif : la grappe entière est jetée sans un mot dans la
//      Search Console. La boutique en émettait depuis le début — et ils
//      n'ont jamais rien fait.
//
//   3. LA GRAPPE EST COMPLÈTE ET RÉCIPROQUE. Chaque page liste TOUTES les
//      langues publiées, y compris elle-même, plus un `x-default` qui dit où
//      envoyer un visiteur dont la langue n'est pas servie. Une grappe où il
//      manque un membre est ignorée en entier.
//
//   4. ON N'ANNONCE QUE CE QUI EST PUBLIÉ. Une langue décochée dans la
//      console ne doit apparaître ni en hreflang ni au sitemap : ce serait
//      envoyer un moteur sur une page qui retombe en polonais, et lui
//      apprendre que le site ment.
//
//   5. LE PRIX BALISÉ EST LE PRIX PAYÉ. Un JSON-LD qui annonce un montant
//      différent de la page fait retirer les résultats enrichis — et c'est
//      mérité. On lit donc la même source que l'affichage, jamais une copie.
// ============================================================================

/**
 * L'origine publique (schéma + hôte), proxy TLS compris.
 *
 * Sans en-tête Host — une exécution en ligne de commande, un sitemap généré
 * hors requête — on ne devine pas : on renvoie une chaîne vide et l'appelant
 * n'émet rien, plutôt que de publier « http://localhost » à des moteurs.
 */
function seo_origin(): string {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') return '';
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    return ($https ? 'https://' : 'http://') . $host;
}

/**
 * L'adresse absolue d'un chemin de la boutique, dans une langue donnée.
 *
 * La langue par défaut n'emporte PAS de « ?lang=pl » : deux adresses pour la
 * même page, c'est du contenu dupliqué qu'il faut ensuite expliquer au moteur
 * à coups de canonique. Autant ne pas le créer.
 */
function seo_url(string $path, string $lang, string $defaut = 'pl'): string {
    $o = seo_origin();
    if ($o === '') return '';
    $p = shop_base() . '/' . ltrim($path, '/');
    if ($p !== shop_base() . '/') $p = rtrim($p, '/');
    return $o . $p . ($lang === $defaut ? '' : '?lang=' . rawurlencode($lang));
}

/** Le chemin de la page en cours, sans la chaîne de requête. */
function seo_path(): string {
    $p = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
    $base = shop_base();
    if ($base !== '' && str_starts_with((string) $p, $base)) $p = substr((string) $p, strlen($base));
    return '/' . ltrim((string) $p, '/');
}

/**
 * Les pages qui ne doivent jamais entrer dans un index.
 *
 * `zamowienie` porte le nom, l'adresse et le contenu d'une commande. Les deux
 * autres n'ont aucun sens hors session — et une caisse indexée envoie des
 * visiteurs sur un panier vide, ce qui se paie en taux de rebond.
 */
const SEO_PRIVE = ['koszyk', 'kasa', 'zamowienie', 'zaplac', 'newsletter'];

function seo_indexable(string $page): bool {
    return !in_array($page, SEO_PRIVE, true);
}

/**
 * Les balises de tête : robots, canonique, grappe de langues, partage social.
 *
 * @param string   $page   premier segment de route ('' = catalogue)
 * @param string[] $langs  langues PUBLIÉES, dans l'ordre d'affichage
 */
function seo_head(string $page, string $lang, array $langs, string $title,
                  string $desc, string $defaut = 'pl', string $image = ''): void {
    $index = seo_indexable($page);
    $path  = seo_path();
    $canon = seo_url($path, $lang, $defaut);

    // 1. Les robots d'abord : c'est la ligne qui protège une commande.
    if (!$index) {
        // `noarchive` en plus : sans lui, un moteur peut servir une copie en
        // cache d'une page qu'on vient de retirer de l'index.
        echo '<meta name="robots" content="noindex, nofollow, noarchive">' . "\n";
        // Une page non indexée n'a pas besoin de canonique ni de grappe : les
        // émettre enverrait deux consignes contradictoires au même robot.
        return;
    }

    echo '<meta name="robots" content="index, follow, max-image-preview:large">' . "\n";
    if ($canon !== '') echo '<link rel="canonical" href="' . e($canon) . '">' . "\n";

    // 2. La grappe : absolue, complète, réciproque, avec x-default.
    if (seo_origin() !== '') {
        foreach ($langs as $l) {
            $href = seo_url($path, $l, $defaut);
            if ($href !== '') {
                echo '<link rel="alternate" hreflang="' . e($l) . '" href="' . e($href) . '">' . "\n";
            }
        }
        // Où envoyer quelqu'un dont la langue n'est pas servie. Sans cette
        // ligne, la grappe est incomplète et Google la traite comme telle.
        $x = seo_url($path, $defaut, $defaut);
        if ($x !== '') echo '<link rel="alternate" hreflang="x-default" href="' . e($x) . '">' . "\n";
    }

    // 3. Le partage social. Un lien collé dans un message sans vignette ni
    //    titre se clique deux fois moins — c'est du référencement au sens
    //    large, celui qui amène vraiment des gens.
    echo '<meta property="og:type" content="' . ($page === 'p' ? 'product' : 'website') . '">' . "\n";
    echo '<meta property="og:title" content="' . e($title) . '">' . "\n";
    if ($desc !== '')  echo '<meta property="og:description" content="' . e($desc) . '">' . "\n";
    if ($canon !== '') echo '<meta property="og:url" content="' . e($canon) . '">' . "\n";
    echo '<meta property="og:locale" content="' . e(seo_og_locale($lang)) . '">' . "\n";
    if ($image !== '') {
        echo '<meta property="og:image" content="' . e($image) . '">' . "\n";
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    } else {
        echo '<meta name="twitter:card" content="summary">' . "\n";
    }
}

/** « pl » → « pl_PL ». Facebook et LinkedIn attendent la forme longue. */
function seo_og_locale(string $lang): string {
    $m = ['pl' => 'pl_PL', 'en' => 'en_GB', 'uk' => 'uk_UA', 'de' => 'de_DE',
          'fr' => 'fr_FR', 'cs' => 'cs_CZ', 'sk' => 'sk_SK', 'hu' => 'hu_HU'];
    return $m[$lang] ?? $lang;
}

/** Un bloc JSON-LD, échappé pour ne pas pouvoir refermer le <script>. */
function seo_jsonld(array $data): void {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return;
    // « </script> » dans une valeur fermerait la balise et le reste passerait
    // en HTML : la barre oblique est donc neutralisée.
    echo '<script type="application/ld+json">' . str_replace('</', '<\/', $json) . "</script>\n";
}

/** Qui vend. Émis une fois, sur le catalogue : c'est la carte d'identité. */
function seo_org(array $S, string $lang, string $defaut = 'pl'): void {
    $url = seo_url('/', $defaut, $defaut);
    if ($url === '') return;
    $d = [
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        'name'     => (string) ($S['brand'] ?? 'Szoko Shop'),
        'url'      => $url,
    ];
    // Le logo déposé dans Ustawienia, sinon celui du dépôt — le même que la page montre.
    $logo = function_exists('logo_src') && !str_starts_with(logo_src(), 'http') ? seo_origin() . logo_src()
          : (function_exists('logo_src') ? logo_src() : seo_origin() . shop_base() . '/assets/logo.png');
    if (seo_origin() !== '') $d['logo'] = $logo;
    if (($S['meta.desc'] ?? '') !== '') $d['description'] = (string) $S['meta.desc'];
    seo_jsonld($d);
}

/**
 * Un produit, avec son offre.
 *
 * LE PRIX VIENT DE LA MÊME SOURCE QUE L'AFFICHAGE. Un balisage qui annonce
 * 39 zł quand la page en montre 42 fait retirer les résultats enrichis du
 * site entier — et ce serait mérité. On ne recalcule rien ici.
 *
 * @param array $p produit tel que wsm_shop_product() le rend
 */
function seo_product(array $p, string $lang, string $defaut = 'pl', array $extra = []): void {
    $url = seo_url('/p/' . (string) ($p['slug'] ?? ''), $lang, $defaut);
    if ($url === '') return;

    $d = [
        '@context' => 'https://schema.org',
        '@type'    => 'Product',
        'name'     => (string) ($p['name'] ?? ''),
        'url'      => $url,
    ];
    if (($p['desc'] ?? '') !== '')  $d['description'] = (string) $p['desc'];
    if (($p['sku'] ?? '') !== '')   $d['sku'] = (string) $p['sku'];
    if (($p['ean'] ?? '') !== '')   $d['gtin13'] = (string) $p['ean'];
    if (!empty($p['brand']['name'])) {
        $d['brand'] = ['@type' => 'Brand', 'name' => (string) $p['brand']['name']];
    }
    if (($p['image'] ?? '') !== '') {
        $img = (string) $p['image'];
        if (!preg_match('#^https?://#i', $img)) $img = seo_origin() . shop_base() . '/' . ltrim($img, '/');
        $d['image'] = $img;
    }

    // L'offre. `price` est en złoty avec deux décimales et un POINT : c'est
    // le format attendu, une virgule fait rejeter le bloc entier.
    $gr = (int) ($p['price'] ?? 0);
    if ($gr > 0) {
        $stock = (int) ($p['stock'] ?? 0);
        $d['offers'] = [
            '@type'         => 'Offer',
            'url'           => $url,
            'price'         => number_format($gr / 100, 2, '.', ''),
            'priceCurrency' => 'PLN',
            'availability'  => $stock > 0
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
            // Sans cette ligne, Google marque l'offre comme incomplète.
            'itemCondition' => 'https://schema.org/NewCondition',
        ];
        // LA LIVRAISON ET LE RETOUR, que Google affiche sous le prix
        // (« Dostawa 12,99 zł · Zwrot 14 dni »). Le tarif est celui du
        // transporteur le moins cher de la caisse — le même que le flux
        // Merchant (reklama.php) ; gratuit au-delà du seuil réglé.
        $ship = $extra['ship'] ?? null;
        if (is_array($ship)) {
            $tarif = ((int) $ship['free_from'] > 0 && $gr >= (int) $ship['free_from']) ? 0 : (int) $ship['price'];
            $d['offers']['shippingDetails'] = [
                '@type' => 'OfferShippingDetails',
                'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => number_format($tarif / 100, 2, '.', ''), 'currency' => 'PLN'],
                'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => 'PL'],
                'deliveryTime' => [
                    '@type' => 'ShippingDeliveryTime',
                    'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => max(1, (int) ($extra['handling'] ?? 1)), 'unitCode' => 'DAY'],
                    'transitTime'  => ['@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => 3, 'unitCode' => 'DAY'],
                ],
            ];
        }
        // Le droit de retrait du consommateur : 14 jours, par la poste, à ses
        // frais — la règle légale par défaut, celle que décrit la page Zwroty.
        $zwroty = seo_url('/zwroty', $lang, $defaut);
        $d['offers']['hasMerchantReturnPolicy'] = array_filter([
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => 'PL',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => 14,
            'returnMethod' => 'https://schema.org/ReturnByMail',
            'returnFees' => 'https://schema.org/ReturnFeesCustomerResponsibility',
            'merchantReturnLink' => $zwroty,
        ], fn($v) => $v !== '');
    }
    seo_jsonld($d);
}

/**
 * Le sitemap : toutes les pages indexables, dans toutes les langues publiées.
 *
 * Chaque URL porte la grappe complète de ses traductions (xhtml:link). C'est
 * la forme que Google demande pour un site multilingue, et elle évite d'avoir
 * à recouper les hreflang page par page.
 *
 * @param array $urls  [['path'=>…, 'lastmod'=>…|null, 'priority'=>…|null], …]
 */
function seo_sitemap(array $urls, array $langs, string $defaut = 'pl'): string {
    $o = seo_origin();
    if ($o === '') return '';
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
       . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
       . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

    foreach ($urls as $u) {
        $path = (string) ($u['path'] ?? '/');
        foreach ($langs as $l) {
            $loc = seo_url($path, $l, $defaut);
            if ($loc === '') continue;
            $x .= "  <url>\n    <loc>" . e($loc) . "</loc>\n";
            foreach ($langs as $alt) {
                $h = seo_url($path, $alt, $defaut);
                if ($h !== '') {
                    $x .= '    <xhtml:link rel="alternate" hreflang="' . e($alt)
                        . '" href="' . e($h) . "\"/>\n";
                }
            }
            $xd = seo_url($path, $defaut, $defaut);
            if ($xd !== '') {
                $x .= '    <xhtml:link rel="alternate" hreflang="x-default" href="' . e($xd) . "\"/>\n";
            }
            if (!empty($u['lastmod']))  $x .= '    <lastmod>' . e(substr((string) $u['lastmod'], 0, 10)) . "</lastmod>\n";
            if (!empty($u['priority'])) $x .= '    <priority>' . e((string) $u['priority']) . "</priority>\n";
            $x .= "  </url>\n";
        }
    }
    return $x . "</urlset>\n";
}

/**
 * robots.txt.
 *
 * On interdit explicitement les chemins personnels. Le `noindex` de la page
 * suffirait en théorie — mais il faut avoir chargé la page pour le lire, et
 * un robot mal élevé ne la charge pas pour rien.
 */
function seo_robots_txt(): string {
    $base = shop_base();
    $o = seo_origin();
    $t  = "User-agent: *\n";
    foreach (SEO_PRIVE as $p) $t .= "Disallow: $base/$p\n";
    $t .= "Allow: /\n\n";
    if ($o !== '') $t .= "Sitemap: " . $o . $base . "/sitemap.xml\n";
    return $t;
}
