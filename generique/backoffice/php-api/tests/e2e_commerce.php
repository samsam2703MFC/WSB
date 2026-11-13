<?php
// ============================================================================
//  e2e_commerce.php — preuve que la base capte tout ce que tpay.com et InPost
//  exigeront, et qu'elle REFUSE les données qui les feraient échouer.
//
//  Un NIP faux fait rejeter la facture, un téléphone à 8 chiffres fait rejeter
//  l'envoi InPost : mieux vaut bloquer à la saisie que découvrir l'erreur au
//  moment de la commande.
//
//  Usage :  php tests/e2e_commerce.php [baseUrl] [adminToken]
// ============================================================================

$BASE  = rtrim($argv[1] ?? getenv('WSM_API_BASE') ?: 'http://localhost:8090', '/');
$TOKEN = $argv[2] ?? getenv('WSM_ADMIN_TOKEN') ?: 'dev-admin-token';

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}
function http(string $method, string $url, ?array $body = null, ?string $token = null): array {
    $headers = ['Accept: application/json'];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    if ($token !== null) $headers[] = 'X-Admin-Token: ' . $token;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw ?: 'null', true)];
}
/** Le champ attendu est-il signalé en erreur ? */
function badField(array $r, string $field): bool {
    return ($r[0] ?? 0) === 422 && isset($r[1]['fields'][$field]);
}

require_once dirname(__DIR__) . '/commerce.php';

echo "webshop_mrszoko — end-to-end tpay + InPost data test\n";
echo "base: $BASE\n\n";

// ---- 1. Validateurs (hors HTTP) --------------------------------------------
echo "-- validateurs métier --\n";
ok('NIP valide accepté (5252248481)', wsm_valid_nip('525-224-84-81'));
ok('NIP à somme de contrôle fausse refusé', !wsm_valid_nip('5252248482'));
ok('NIP trop court refusé', !wsm_valid_nip('52522448'));
ok('téléphone +48 normalisé en 9 chiffres', wsm_normalize_phone('+48 512 340 011') === '512340011');
ok('téléphone à 8 chiffres refusé', !wsm_valid_phone('51234001'));
ok('code postal 00026 reformaté en 00-026', wsm_normalize_postcode('00026') === '00-026');
ok('code postal invalide refusé', !wsm_valid_postcode('0002'));
ok('code Paczkomat valide (KRA010)', wsm_valid_inpost_point('kra010'));
ok('code Paczkomat invalide refusé', !wsm_valid_inpost_point('12'));
ok('VAT UE au format VIES', wsm_valid_vat_eu('PL5252248481') && !wsm_valid_vat_eu('5252248481'));
ok('gabarit A pour un petit colis', wsm_inpost_template(140, 90, 60) === 'A');
ok('gabarit C pour un colis épais', wsm_inpost_template(380, 380, 400) === 'C');
ok('aucun gabarit si trop long (baguette 650 mm)', wsm_inpost_template(650, 90, 90) === '');

[$code] = http('GET', "$BASE/landing/content");
ok('API joignable', $code === 200, $code);
if ($code !== 200) { echo "\nAPI injoignable — lancez ./serve.sh\n"; exit(1); }

// ---- 2. Client : refus des données que tpay/InPost rejetteraient ------------
echo "-- client (tpay payeur + facture) --\n";
$base = ['raison' => 'Cukiernia Testowa', 'client_type' => 'firma',
         'email' => 'test@cukiernia.pl', 'phone' => '512340099', 'nip' => '5252248481',
         'bill_street' => 'Marszałkowska', 'bill_building' => '104',
         'bill_postcode' => '00-026', 'bill_city' => 'Warszawa'];

$r = http('POST', "$BASE/franchisor/client", array_merge($base, ['email' => '']), $TOKEN);
ok('sans e-mail → 422 (tpay l\'exige)', badField($r, 'email'), $r);

$r = http('POST', "$BASE/franchisor/client", array_merge($base, ['email' => 'pas-un-email']), $TOKEN);
ok('e-mail malformé → 422', badField($r, 'email'), $r);

$r = http('POST', "$BASE/franchisor/client", array_merge($base, ['phone' => '51234']), $TOKEN);
ok('téléphone hors format → 422 (InPost l\'exige)', badField($r, 'phone'), $r);

$r = http('POST', "$BASE/franchisor/client", array_merge($base, ['nip' => '5252248482']), $TOKEN);
ok('NIP à somme fausse → 422', badField($r, 'nip'), $r);

$r = http('POST', "$BASE/franchisor/client", array_merge($base, ['bill_postcode' => '00026x']), $TOKEN);
ok('code postal invalide → 422', badField($r, 'bill_postcode'), $r);

$r = http('POST', "$BASE/franchisor/client", array_merge($base, ['client_type' => 'osoba', 'nip' => '', 'first_name' => '', 'last_name' => '']), $TOKEN);
ok('personne privée sans prénom/nom → 422 (destinataire InPost)', badField($r, 'first_name'), $r);

$r = http('POST', "$BASE/franchisor/client", $base, $TOKEN);
ok('client complet → 200', $r[0] === 200 && !empty($r[1]['id']), $r);
$clientId = $r[1]['id'] ?? 0;

[, $list] = http('GET', "$BASE/franchisor/delivery-clients", null, $TOKEN);
$created = null;
foreach ($list ?: [] as $c) if ((int) ($c['id'] ?? 0) === (int) $clientId) $created = $c;
ok('le client est relu avec ses champs tpay/InPost',
    $created && $created['email'] === 'test@cukiernia.pl' && $created['nip'] === '5252248481'
    && $created['bill_postcode'] === '00-026', $created);
ok('un code client est attribué automatiquement (CL-…)',
    $created && preg_match('/^CL-\d{4}$/', (string) $created['code']) === 1, $created['code'] ?? null);

$r = http('POST', "$BASE/franchisor/client", ['id' => $clientId, 'phone' => '+48 600 100 200', 'raison' => 'Cukiernia Testowa'], $TOKEN);
ok('mise à jour partielle acceptée', $r[0] === 200, $r);
[, $list2] = http('GET', "$BASE/franchisor/delivery-clients", null, $TOKEN);
$upd = null; foreach ($list2 ?: [] as $c) if ((int) $c['id'] === (int) $clientId) $upd = $c;
ok('téléphone normalisé à l\'enregistrement (+48 retiré)', ($upd['phone'] ?? '') === '600100200', $upd['phone'] ?? null);

// ---- 3. Point de livraison : Paczkomat ou coursier --------------------------
echo "-- point de livraison (InPost) --\n";
$pt = ['client_id' => $clientId, 'libelle' => 'Punkt testowy', 'delivery_method' => 'inpost_locker'];

$r = http('POST', "$BASE/franchisor/client-point", $pt, $TOKEN);
ok('Paczkomat sans code → 422', badField($r, 'inpost_point'), $r);

$r = http('POST', "$BASE/franchisor/client-point", array_merge($pt, ['inpost_point' => '12']), $TOKEN);
ok('code de Paczkomat invalide → 422', badField($r, 'inpost_point'), $r);

$r = http('POST', "$BASE/franchisor/client-point", array_merge($pt, ['delivery_method' => 'inpost_courier']), $TOKEN);
ok('coursier sans adresse → 422 (rue, code postal, ville)',
    badField($r, 'postcode') && badField($r, 'street') && badField($r, 'city'), $r);

$r = http('POST', "$BASE/franchisor/client-point", array_merge($pt, ['inpost_point' => 'kra010', 'contact_phone' => '512340099']), $TOKEN);
ok('Paczkomat valide → 200', $r[0] === 200 && !empty($r[1]['id']), $r);

[, $list3] = http('GET', "$BASE/franchisor/delivery-clients", null, $TOKEN);
$pts = [];
foreach ($list3 ?: [] as $c) if ((int) $c['id'] === (int) $clientId) $pts = $c['points'] ?? [];
ok('le code de Paczkomat est stocké en majuscules (KRA010)',
    count($pts) === 1 && $pts[0]['inpost_point'] === 'KRA010', $pts[0]['inpost_point'] ?? null);

// ---- 4. Produit : logistique InPost + TVA tpay ------------------------------
echo "-- produit (colis InPost + TVA tpay) --\n";
$r = http('POST', "$BASE/franchisor/product", ['id' => 'p-eclair', 'vat_rate' => 0.19], $TOKEN);
ok('taux de TVA hors barème polonais → 422', badField($r, 'vat_rate'), $r);

$r = http('POST', "$BASE/franchisor/product", ['id' => 'p-eclair', 'weight_g' => 30000], $TOKEN);
ok('colis de plus de 25 kg → 422 (limite InPost)', badField($r, 'weight_g'), $r);

$r = http('POST', "$BASE/franchisor/product", ['id' => 'p-eclair', 'ean' => '123'], $TOKEN);
ok('EAN de longueur invalide → 422', badField($r, 'ean'), $r);

$r = http('POST', "$BASE/franchisor/product", ['id' => 'p-inconnu-xyz', 'weight_g' => 100], $TOKEN);
ok('produit inexistant → 404', $r[0] === 404, $r);

$r = http('POST', "$BASE/franchisor/product", ['id' => 'p-eclair', 'weight_g' => 250,
    'length_mm' => 500, 'width_mm' => 300, 'height_mm' => 150, 'vat_rate' => 23, 'ean' => '5901234123457'], $TOKEN);
ok('logistique valide → 200 (« 23 » accepté comme 23 %)', $r[0] === 200, $r);

[, $cat] = http('GET', "$BASE/franchisor/catalog", null, $TOKEN);
$prod = null;
foreach ($cat ?: [] as $c) foreach ($c['prods'] ?? [] as $p) if ($p['id'] === 'p-eclair') $prod = $p;
ok('le catalogue expose poids, dimensions et TVA',
    $prod && $prod['weight_g'] === 250 && $prod['vat_rate'] === 0.23, $prod);
ok('le gabarit InPost est déduit des dimensions (500×300×150 → B)',
    $prod && $prod['parcel_template'] === 'B', $prod['parcel_template'] ?? null);

$r = http('POST', "$BASE/franchisor/product", ['id' => 'p-eclair', 'length_mm' => 300, 'width_mm' => 300, 'height_mm' => 300], $TOKEN);
[, $cat2] = http('GET', "$BASE/franchisor/catalog", null, $TOKEN);
$prod2 = null;
foreach ($cat2 ?: [] as $c) foreach ($c['prods'] ?? [] as $p) if ($p['id'] === 'p-eclair') $prod2 = $p;
ok('changer les dimensions RECALCULE le gabarit (300³ → C, ouverture B trop basse)',
    $prod2 && $prod2['parcel_template'] === 'C', $prod2['parcel_template'] ?? null);

// ---- 5. Cloisonnement : ces écritures restent protégées ---------------------
echo "-- protection --\n";
[$c1] = http('POST', "$BASE/franchisor/client", $base);
ok('écriture client sans identité → 401', $c1 === 401, $c1);
[$c2] = http('POST', "$BASE/franchisor/product", ['id' => 'p-eclair', 'weight_g' => 1], null);
ok('écriture produit sans identité → 401', $c2 === 401, $c2);

// nettoyage
http('POST', "$BASE/franchisor/client", ['delete' => $clientId], $TOKEN);

echo "\n" . ($fail === 0 ? "ALL GREEN: $pass passed, 0 failed" : "FAILURES: $pass passed, $fail FAILED") . "\n";
exit($fail === 0 ? 0 : 1);
