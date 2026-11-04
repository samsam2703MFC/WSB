<?php
// ============================================================================
//  e2e_reklama.php — Google et Meta : vérification, accord, balises, achats
//  envoyés par le serveur, flux produits, données structurées.
//
//   1. les réglages : chaque identifiant vérifié à sa forme, les jetons secrets ;
//   2. l'accord : cookie lu strictement, rien ne charge sans « oui » ;
//   3. la Conversions API : seulement payé ET accord marketing, haché,
//      dédoublonné, réclamé avant envoi, jeton jamais dans un message ;
//   4. le flux Google/Meta : prix = prix de la page, GTIN vérifié, produits
//      sans photo écartés, XML valide ;
//   5. la boutique en HTTP : bannière, codes de vérification, flux, JSON-LD.
//
//  Usage :  php tests/e2e_reklama.php [http://localhost:8091]
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/settings.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/mail.php';
require_once dirname(__DIR__) . '/reklama.php';
require_once dirname(__DIR__) . '/mediateka.php';
$pdo = wsm_bootstrap();
wsm_ads_ensure($pdo);
wsm_mail_transport(fn(array $m) => [true, '']);
$SHOP = $argv[1] ?? getenv('WSM_SHOP_URL') ?: 'http://localhost:8091';

echo "webshop_mrszoko — end-to-end Google i Meta (zgody, piksel, CAPI, plik produktów)\n\n";

$sfx = bin2hex(random_bytes(3));
$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$produits = []; $commandes = []; $fichiers = [];
$cles = ['google.ga4_id', 'google.site_verification', 'meta.pixel_id', 'meta.domain_verification', 'bing.site_verification'];
$avant = [];
foreach ($cles as $k) { $st = $pdo->prepare("SELECT * FROM wsm_settings WHERE cle = ?"); $st->execute([$k]); $avant[$k] = $st->fetch() ?: null; }
register_shutdown_function(function () use ($pdo, &$produits, &$commandes, &$fichiers, $avant) {
    try {
        foreach ($commandes as $id) {
            foreach (['wsm_order_items', 'wsm_order_events', 'wsm_payments', 'wsm_messages', 'wsm_shipments', 'wsm_ad_orders', 'wsm_capi_events'] as $t) {
                try { $pdo->prepare("DELETE FROM $t WHERE order_id = ?")->execute([$id]); } catch (Throwable $e) {}
            }
            $pdo->prepare("DELETE FROM wsm_orders WHERE id = ?")->execute([$id]);
        }
        foreach ($produits as $id) $pdo->prepare("DELETE FROM wsm_products WHERE id = ?")->execute([$id]);
        foreach ($fichiers as $f) @unlink($f);
        foreach ($avant as $k => $r) {
            $pdo->prepare("DELETE FROM wsm_settings WHERE cle = ?")->execute([$k]);
            if ($r) $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,?,?,?)")
                        ->execute([$k, $r['val'], $r['secret'], $r['updated_at'], $r['updated_by']]);
        }
    } catch (Throwable $e) { echo "  (nettoyage: " . $e->getMessage() . ")\n"; }
});

// Une image de test dans la médiathèque (nom au format des nôtres).
$img = function (int $w, int $h) use (&$fichiers): string {
    $name = bin2hex(random_bytes(12)) . '.png';
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 90, 50, 30));
    $p = wsm_media_dir() . '/' . $name;
    imagepng($im, $p); imagedestroy($im);
    $fichiers[] = $p;
    return 'media/' . $name;
};
$mk = function (string $nom, int $prixGr, string $ean, string $image, int $stock = 10, int $poids = 1000) use ($pdo, $cat, $sfx, &$produits): string {
    $id = 'test-rk-' . $sfx . '-' . substr(md5($nom), 0, 4);
    $pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, base_cost, statut, active, shop_visible, slug, stock,
                        vat_rate, weight_g, length_mm, width_mm, height_mm, sku, ean, image_url)
                   VALUES (?,?,?,?,0,'Opublikowany',1,1,?,?,0.23,?,100,100,100,?,?,?)")
        ->execute([$id, $cat, $nom, $prixGr / 100, $id, $stock, $poids, 'SKU-' . $sfx . substr(md5($nom), 0, 3), $ean, $image]);
    $produits[] = $id;
    return $id;
};

// ---- 1. Les réglages ---------------------------------------------------------------------------
echo "-- ustawienia Google i Meta --\n";
$f = wsm_settings_fields();
ok('Google : Search Console, GA4, Ads (id + étiquette), Merchant, Bing', isset($f['google.site_verification'], $f['google.ga4_id'], $f['google.ads_id'],
   $f['google.ads_label'], $f['google.merchant_id'], $f['bing.site_verification']));
ok('Meta : pixel, jeton CAPI (secret), code test, domaine, page, jeton page (secret), Instagram, version',
   ($f['meta.capi_token'][4] ?? '') === 'secret' && ($f['meta.page_token'][4] ?? '') === 'secret' && isset($f['meta.pixel_id'], $f['meta.ig_user_id'], $f['meta.graph_version']));
ok('les deux groupes sont dans la table des clés du superadmin', isset(WSM_SETTINGS_KLUCZE['google'], WSM_SETTINGS_KLUCZE['meta']));

wsm_config_overlay(['google' => ['ga4_id' => 'G- 12AB', 'site_verification' => 'abc"><script>', 'ads_id' => 'AW-123456789', 'ads_label' => 'AbC-12_x'],
                    'meta' => ['pixel_id' => '123456789012345', 'capi_token' => "test tok\nen", 'page_token' => '', 'graph_version' => 'latest']]);
$c = wsm_ads_cfg();
ok('un GA4 mal collé (espace) est REFUSÉ, pas chargé', $c['ga4'] === '' && isset($c['bledne']['ga4']), $c['ga4']);
ok('un code Search Console avec du HTML est refusé', $c['gsc'] === '' && isset($c['bledne']['gsc']));
ok('Ads et étiquette valides gardés', $c['ads'] === 'AW-123456789' && $c['ads_label'] === 'AbC-12_x');
ok('un jeton avec espace/retour à la ligne est refusé', $c['capi'] === '' && isset($c['bledne']['capi']));
ok('version Graph invalide → v23.0 par défaut', $c['graph'] === 'v23.0', $c['graph']);

$TOKEN = 'test-meta-capi-' . str_repeat('x', 30);
wsm_config_overlay(['google' => ['ga4_id' => 'G-TEST1234', 'site_verification' => 'gsc-Test_Code-123456', 'ads_id' => '', 'ads_label' => ''],
                    'bing' => ['site_verification' => '0123456789ABCDEF0123456789ABCDEF'],
                    'meta' => ['pixel_id' => '123456789012345', 'capi_token' => $TOKEN, 'test_event_code' => '', 'domain_verification' => 'abcdef0123456789abcdef', 'graph_version' => 'v23.0']]);
$c = wsm_ads_cfg();
$meta = wsm_ads_meta_html($c);
ok('balises de vérification : Google, Bing, Facebook', str_contains($meta, 'google-site-verification" content="gsc-Test_Code-123456"')
   && str_contains($meta, 'msvalidate.01') && str_contains($meta, 'facebook-domain-verification'));
ok('catégories d\'accord : analytique (GA4) et marketing (pixel)', wsm_ads_kategorie($c) === ['analityczne' => true, 'marketingowe' => true]);
$js = json_encode(wsm_ads_js_config($c, [['n' => 'view_item', 'value' => 1.5, 'items' => []]]));
ok('la config du navigateur n\'a AUCUN jeton', !str_contains($js, $TOKEN) && !str_contains($js, 'capi') && str_contains($js, 'G-TEST1234'), $js);

// ---- 2. L'accord ---------------------------------------------------------------------------------
echo "\n-- zgoda odwiedzającego --\n";
ok('« v1-a1-m0 » = analytique oui, marketing non', wsm_zgody_odczyt('v1-a1-m0') === ['analityczne' => true, 'marketingowe' => false]);
ok('absent = pas encore choisi (null)', wsm_zgody_odczyt('') === null);
ok('valeur abîmée = pas choisi, JAMAIS oui', wsm_zgody_odczyt('v1-a1-m1x') === null && wsm_zgody_odczyt('v2-a1-m1') === null && wsm_zgody_odczyt('1') === null);
$racine = dirname(__DIR__, 4);
$zjs = (string) @file_get_contents($racine . '/mrszoko/shop/zgody.js');
ok('zgody.js ne charge gtag/pixel qu\'après choix (start() sur cookie lu)', str_contains($zjs, "if (z) start(z); else show(false);") && str_contains($zjs, 'loadPixel'));
ok('retirer l\'accord efface les cookies de mesure et recharge', str_contains($zjs, 'forget(); location.reload()'));
ok('l\'achat porte le même eventID que le serveur', str_contains($zjs, "{ eventID: ev.id }"));
ok('les deux boutons principaux : accepter ET refuser au premier niveau', str_contains((string) @file_get_contents($racine . '/mrszoko/shop/lib.php'), 'data-zgody="none"'));

// ---- 3. La Conversions API --------------------------------------------------------------------------
echo "\n-- Conversions API (zakup z serwera) --\n";
$imgA = $img(800, 800);
$pA = $mk('Czekolada test reklama ' . $sfx, 2460, '5901234123457', $imgA);
$base = ['lang' => 'pl', 'delivery_method' => 'inpost_locker', 'inpost_point' => 'WRO01A', 'phone' => '600 100 200', 'consent_terms' => 1,
         'client_type' => 'osoba', 'first_name' => 'Anna', 'last_name' => 'Kowalska', 'ship_city' => 'Wrocław', 'ship_postcode' => '50-001'];
$cmd = function (string $mail) use ($pdo, $base, $pA, &$commandes): array {
    [$o, $e] = wsm_shop_create_order($pdo, $base + ['email' => $mail, 'items' => [['id' => $pA, 'qty' => 2]]]);
    if ($o) $commandes[] = (int) $o['id'];
    return [$o, $e];
};
$appels = [];
wsm_graph_transport(function (string $m, string $url, array $p, array $files) use (&$appels) {
    $appels[] = ['m' => $m, 'url' => $url, 'p' => $p];
    return [200, '{"events_received":1}'];
});
[$o1, $e1] = $cmd('anna.' . $sfx . '@example.com');
ok('commande créée', $o1 !== null, $e1);
wsm_ads_order_capture($pdo, (int) $o1['id'], ['analityczne' => true, 'marketingowe' => true],
                      ['_fbp' => 'fb.1.1700000000000.123456789', '_fbc' => 'fb.1.1700000000000.AbCdEf123456', 'autre' => 'x'], '203.0.113.9', 'Mozilla/5.0 test', 'https://sklep.example/kasa');
$cap = wsm_ads_order_row($pdo, (int) $o1['id']);
ok('accord marketing noté avec _fbp, _fbc, IP, navigateur', (int) $cap['marketingowe'] === 1 && $cap['fbp'] !== '' && $cap['fbc'] !== '' && $cap['ip'] === '203.0.113.9');
wsm_capi_on_paid($pdo, (int) $o1['id']);
ok('NON payée : rien ne part', $appels === [] && (int) $pdo->query("SELECT COUNT(*) FROM wsm_capi_events WHERE order_id = " . (int) $o1['id'])->fetchColumn() === 0);
wsm_order_mark_paid($pdo, (int) $o1['id'], 'test');
ok('payée + accord : un seul appel, vers {pixel}/events', count($appels) === 1 && str_ends_with($appels[0]['url'], '/v23.0/123456789012345/events'), array_column($appels, 'url'));
$body = $appels[0]['p'] ?? [];
$ev = $body['data'][0] ?? [];
ok('événement Purchase, identifiant « zam-CODE » (dédoublonnage avec le pixel)', ($ev['event_name'] ?? '') === 'Purchase' && ($ev['event_id'] ?? '') === 'zam-' . $o1['code']);
ok('valeur = total TTC de la commande, en PLN', abs(($ev['custom_data']['value'] ?? 0) - $o1['total_gross'] / 100) < 0.001 && ($ev['custom_data']['currency'] ?? '') === 'PLN');
ok('e-mail HACHÉ (sha256 des minuscules), jamais en clair', ($ev['user_data']['em'][0] ?? '') === hash('sha256', 'anna.' . $sfx . '@example.com')
   && !str_contains(json_encode($body), 'anna.' . $sfx));
ok('téléphone normalisé avec l\'indicatif 48 avant hachage', ($ev['user_data']['ph'][0] ?? '') === hash('sha256', '48600100200'));
ok('IP, navigateur, _fbp/_fbc transmis', ($ev['user_data']['client_ip_address'] ?? '') === '203.0.113.9' && ($ev['user_data']['fbp'] ?? '') !== '');
ok('produits et quantités', ($ev['custom_data']['contents'][0]['id'] ?? '') === $pA && ($ev['custom_data']['contents'][0]['quantity'] ?? 0) === 2);
ok('le jeton part en paramètre, jamais dans l\'adresse', ($body['access_token'] ?? '') === $TOKEN && !str_contains($appels[0]['url'], $TOKEN));
$row = $pdo->query("SELECT * FROM wsm_capi_events WHERE order_id = " . (int) $o1['id'])->fetch();
ok('file : « wyslane »', ($row['status'] ?? '') === 'wyslane', $row);
wsm_order_mark_paid($pdo, (int) $o1['id'], 'test');
wsm_capi_on_paid($pdo, (int) $o1['id']);
ok('webhook rejoué : aucun second envoi', count($appels) === 1, count($appels));

// Sans accord marketing.
[$o2] = $cmd('bez.' . $sfx . '@example.com');
wsm_ads_order_capture($pdo, (int) $o2['id'], ['analityczne' => true, 'marketingowe' => false], ['_fbp' => 'fb.1.1700000000000.987654321'], '203.0.113.10', 'UA', '');
$cap2 = wsm_ads_order_row($pdo, (int) $o2['id']);
ok('sans accord marketing : on ne garde ni IP, ni navigateur, ni _fbp', $cap2['ip'] === '' && $cap2['ua'] === '' && $cap2['fbp'] === '' && (int) $cap2['analityczne'] === 1);
wsm_order_mark_paid($pdo, (int) $o2['id'], 'test');
ok('… et RIEN ne part chez Meta', count($appels) === 1);

// Sans aucun choix (bannière ignorée).
[$o3] = $cmd('nic.' . $sfx . '@example.com');
wsm_ads_order_capture($pdo, (int) $o3['id'], null, [], '203.0.113.11', 'UA', '');
wsm_order_mark_paid($pdo, (int) $o3['id'], 'test');
ok('bannière ignorée = refus : rien ne part', count($appels) === 1);

// Erreur de Meta, jeton dans le message, puis relance.
[$o4] = $cmd('blad.' . $sfx . '@example.com');
wsm_ads_order_capture($pdo, (int) $o4['id'], ['analityczne' => false, 'marketingowe' => true], [], '203.0.113.12', 'UA', '');
wsm_graph_transport(fn($m, $u, $p, $f) => [400, json_encode(['error' => ['message' => 'Invalid OAuth access token ' . $TOKEN, 'code' => 190]])]);
wsm_order_mark_paid($pdo, (int) $o4['id'], 'test');
$r4 = $pdo->query("SELECT * FROM wsm_capi_events WHERE order_id = " . (int) $o4['id'])->fetch();
ok('erreur notée « blad » avec le message de Meta', ($r4['status'] ?? '') === 'blad' && str_contains((string) $r4['response'], 'kod 190'), $r4);
ok('le jeton n\'apparaît JAMAIS dans le message enregistré', !str_contains((string) $r4['response'], $TOKEN));
$p = wsm_capi_pump($pdo, 10);
ok('la pompe attend avant de retenter (2 min après un échec)', $p['wyslane'] === 0 && $p['bledy'] === 0, $p);
$pdo->prepare("UPDATE wsm_capi_events SET tried_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', time() - 600), (int) $r4['id']]);
wsm_graph_transport(fn($m, $u, $p, $f) => [200, '{"events_received":1}']);
$p = wsm_capi_pump($pdo, 10);
ok('passé le délai : renvoyé et « wyslane »', $p['wyslane'] === 1 && $pdo->query("SELECT status FROM wsm_capi_events WHERE id = " . (int) $r4['id'])->fetchColumn() === 'wyslane', $p);
ok('envoi réclamé avant : un événement déjà envoyé ne repart pas', wsm_capi_send($pdo, (int) $r4['id'])['ok'] === false);
wsm_config_overlay(['meta' => ['test_event_code' => 'TEST12345']]);
$cap1 = wsm_ads_order_row($pdo, (int) $o1['id']);
ok('code de test présent dans le corps quand il est réglé', (wsm_capi_payload(wsm_order_by_id($pdo, (int) $o1['id']), $cap1, wsm_ads_cfg(), 'https://x')['test_event_code'] ?? '') === 'TEST12345');
wsm_config_overlay(['meta' => ['test_event_code' => '']]);
$pe = wsm_ads_purchase_event(wsm_order_by_id($pdo, (int) $o1['id']));
ok('événement d\'achat côté navigateur : même identifiant, même valeur', $pe['id'] === 'zam-' . $o1['code'] && abs($pe['value'] - $o1['total_gross'] / 100) < 0.001);

// ---- 4. Le flux produits -------------------------------------------------------------------------
echo "\n-- plik produktów (Google Merchant + katalog Meta) --\n";
ok('GTIN : EAN-13 valide accepté', wsm_gtin_ok('5901234123457'));
ok('GTIN : mauvaise clé refusée', !wsm_gtin_ok('5901234123458'));
ok('GTIN : EAN-8 valide, zéros refusés', wsm_gtin_ok('96385074') && !wsm_gtin_ok('0000000000000'));
ok('catégorie Google : chocolat → Candy & Chocolate', str_ends_with(wsm_feed_google_category('Czekolada', 'Tabliczka'), 'Candy & Chocolate'));
ok('catégorie Google : farine → Cooking & Baking Ingredients', str_ends_with(wsm_feed_google_category('Mąki', 'Mąka tortowa'), 'Cooking & Baking Ingredients'));
ok('texte du flux : sans HTML, markdown ni caractère interdit', wsm_feed_text("**Gorzka** <b>70%</b> ![x](media/a.webp) [link](http://x)\x01", 100) === 'Gorzka 70% link');
$pSans = $mk('Bez zdjęcia ' . $sfx, 1500, '', '');
$pMauvais = $mk('Zły EAN ' . $sfx, 1999, '5901234123458', $img(600, 900), 0, 0);
$feed = wsm_feed_produkty($pdo, 'pl', 'https://sklep.example/shop');
$it = array_values(array_filter($feed['items'], fn($i) => $i['id'] === $pA))[0] ?? null;
$page = wsm_shop_product($pdo, $pA, 'pl');
ok('produit dans le flux', $it !== null);
ok('PRIX DU FLUX = PRIX DE LA PAGE', ($it['price'] ?? '') === number_format($page['price'] / 100, 2, '.', '') . ' PLN', [$it['price'] ?? '', $page['price']]);
ok('GTIN valide transmis, MPN absent', ($it['gtin'] ?? '') === '5901234123457' && !isset($it['mpn']));
ok('photo en JPEG (version des réseaux), adresse absolue', preg_match('#^https://sklep\.example/shop/media/jpg/[a-f0-9]{24}-feed\.jpg$#', (string) ($it['image_link'] ?? '')) === 1, $it['image_link'] ?? '');
$jpgF = wsm_media_dir() . '/jpg/' . substr(basename($imgA), 0, 24) . '-feed.jpg';
$fichiers[] = $jpgF;
ok('… et le fichier JPEG existe vraiment', is_file($jpgF) && (getimagesize($jpgF)[2] ?? 0) === IMAGETYPE_JPEG);
ok('prix au kilo (alimentaire au poids)', ($it['unit_pricing_measure'] ?? '') === '1000g' && ($it['unit_pricing_base_measure'] ?? '') === '1kg');
ok('disponibilité, état, lien produit', ($it['availability'] ?? '') === 'in_stock' && ($it['condition'] ?? '') === 'new' && str_ends_with((string) $it['link'], '/p/' . $pA));
$sans = array_values(array_filter($feed['pominiete'], fn($x) => $x['id'] === $pSans))[0] ?? null;
ok('sans photo : ÉCARTÉ, avec la raison', $sans !== null && in_array('brak zdjęcia', $sans['powody'], true));
$mv = array_values(array_filter($feed['items'], fn($i) => $i['id'] === $pMauvais))[0] ?? null;
ok('EAN faux : pas de gtin, MPN (SKU) à la place ; rupture = out_of_stock', $mv && !isset($mv['gtin']) && isset($mv['mpn']) && $mv['availability'] === 'out_of_stock');
$xml = wsm_feed_xml($feed['items'], 'Szoko & Co', 'https://sklep.example/shop/');
$sx = @simplexml_load_string($xml);
ok('XML bien formé, espace de noms g:', $sx !== false && isset($sx->getNamespaces(true)['g']));
ok('« & » échappé dans le titre', str_contains($xml, 'Szoko &amp; Co'));
$audit = wsm_seo_audit($pdo, ['pl', 'en']);
$aSans = array_values(array_filter($audit, fn($a) => $a['id'] === $pSans))[0] ?? null;
ok('audit SEO : sans photo ni description → note basse et constats', $aSans && $aSans['wynik'] < 60 && count($aSans['uwagi']) >= 2, $aSans);
$aMv = array_values(array_filter($audit, fn($a) => $a['id'] === $pMauvais))[0] ?? null;
ok('audit SEO : EAN faux signalé', $aMv && (bool) array_filter($aMv['uwagi'], fn($u) => str_contains($u[1], 'cyfrą kontrolną')));

// ---- 5. La boutique en HTTP -----------------------------------------------------------------------
echo "\n-- sklep (HTTP) --\n";
$get = function (string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $hl = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($raw, 0, $hl), substr($raw, $hl)];
};
[$c0, , $h0] = $get($SHOP . '/');
if ($c0 === 0) { echo "  (sklep nieosiągalny pod $SHOP — część HTTP pominięta)\n"; }
else {
    // Sans balise réglée : aucune bannière (rien à demander), aucun script tiers.
    foreach ($avant as $k => $r) $pdo->prepare("DELETE FROM wsm_settings WHERE cle = ?")->execute([$k]);
    [, , $h0] = $get($SHOP . '/');
    $cfgServeur = !str_contains($h0, 'id="zgody"');
    ok('aucune balise réglée : pas de bannière, pas de zgody.js', $cfgServeur && !str_contains($h0, 'zgody.js'));
    ok('aucun script Google/Meta dans la page, jamais', !str_contains($h0, 'googletagmanager.com') && !str_contains($h0, 'connect.facebook.net'));
    $put = $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,0,?, 'test')");
    foreach (['google.ga4_id' => 'G-TEST1234', 'google.site_verification' => 'gsc-Test_Code-123456', 'meta.pixel_id' => '123456789012345',
              'meta.domain_verification' => 'abcdef0123456789abcdef'] as $k => $v) $put->execute([$k, $v, date('Y-m-d H:i:s')]);
    [, , $h1] = $get($SHOP . '/');
    ok('GA4 + pixel réglés : bannière présente, masquée par défaut', str_contains($h1, 'id="zgody"') && str_contains($h1, 'role="dialog"') && preg_match('/id="zgody"[^>]*hidden/', $h1) === 1);
    ok('… avec « Akceptuję » ET « Tylko niezbędne » au même niveau', str_contains($h1, 'data-zgody="all"') && str_contains($h1, 'data-zgody="none"'));
    ok('codes de vérification Google et Facebook dans <head>', str_contains($h1, 'google-site-verification" content="gsc-Test_Code-123456"') && str_contains($h1, 'facebook-domain-verification'));
    ok('config publique (G-…, pixel) et zgody.js, toujours SANS script tiers', str_contains($h1, '"ga4":"G-TEST1234"') && str_contains($h1, 'zgody.js')
       && !str_contains($h1, 'googletagmanager.com/gtag') && !str_contains($h1, 'fbevents.js'));
    ok('lien « Ustawienia cookies » dans le pied de page', str_contains($h1, 'data-zgody="open"'));
    [$cp, , $hp] = $get($SHOP . '/p/' . $pA);
    ok('fiche produit : événement view_item avec le prix', $cp === 200 && str_contains($hp, '"n":"view_item"') && str_contains($hp, '"price":' . rtrim(rtrim(number_format($page['price'] / 100, 2, '.', ''), '0'), '.')), $cp);
    ok('JSON-LD : livraison (shippingDetails) et retours 14 jours', str_contains($hp, '"shippingDetails"') && str_contains($hp, '"merchantReturnDays":14'));
    [$cf, $hf, $bf] = $get($SHOP . '/feed/produkty.xml');
    ok('/feed/produkty.xml : 200, XML, noindex', $cf === 200 && stripos($hf, 'application/xml') !== false && stripos($hf, 'X-Robots-Tag: noindex') !== false, $cf);
    ok('… contient le produit au prix de la page', str_contains($bf, '<g:id>' . $pA . '</g:id>') && str_contains($bf, number_format($page['price'] / 100, 2, '.', '') . ' PLN'));
    ok('… valide pour un lecteur XML', @simplexml_load_string($bf) !== false);
}

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILED") . " — $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
