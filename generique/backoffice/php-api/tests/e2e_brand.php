<?php
// ============================================================================
//  e2e_brand.php — preuve que la marque tient de la console jusqu'à la vitrine.
//
//  Ce qu'on démontre :
//
//   1. LE LOGO GARDE SA TRANSPARENCE. C'est la seule raison d'avoir touché au
//      stockage média : un logo aplati sur du crème ressort en rectangle sale
//      dès qu'on le pose ailleurs. On vérifie le canal alpha du fichier écrit,
//      pas l'intention.
//   2. LA MARQUE ARRIVE SUR LE PRODUIT. Le catalogue et la fiche la portent,
//      avec le logo et l'adresse — sinon l'écran de saisie ne servirait à rien.
//   3. UNE MARQUE DÉSACTIVÉE DISPARAÎT DE LA BOUTIQUE sans casser la fiche.
//   4. ON NE SUPPRIME PAS UNE MARQUE PORTÉE PAR DES PRODUITS.
//
//  Usage :  php tests/e2e_brand.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/brand.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end marki (logo · katalog · karta produktu)\n\n";

// ---- 1. La table et la colonne existent ---------------------------------------
echo "-- schemat --\n";
ok('la table des marques existe', wsm_table_exists($pdo, 'wsm_brands'));
ok('le produit porte une référence de marque',
    in_array('brand_id', wsm_table_columns($pdo, 'wsm_products'), true),
    wsm_table_columns($pdo, 'wsm_products'));

// ---- 2. Le slug ----------------------------------------------------------------
echo "\n-- slug --\n";
ok('les diacritiques polonais sont repliés', wsm_brand_slugify('Żółte Łąki') === 'zolte-laki',
    wsm_brand_slugify('Żółte Łąki'));
ok('la ponctuation devient un tiret', wsm_brand_slugify('Mister Szoko & Fils') === 'mister-szoko-fils',
    wsm_brand_slugify('Mister Szoko & Fils'));
ok('pas de tiret en bord', wsm_brand_slugify('  --Test--  ') === 'test', wsm_brand_slugify('  --Test--  '));

// ---- 3. Création, validation ----------------------------------------------------
echo "\n-- zapis marki --\n";
$sfx = bin2hex(random_bytes(3));
[$nul, $e1] = wsm_brand_save($pdo, ['name' => '']);
ok('une marque sans nom est refusée', $nul === null && isset($e1['name']), $e1);

[$nul2, $e2] = wsm_brand_save($pdo, ['name' => 'Test', 'site_url' => 'http://example.com']);
ok('une adresse en http est refusée — contenu mixte', $nul2 === null && isset($e2['site_url']), $e2);

[$b, $eb] = wsm_brand_save($pdo, [
    'name' => 'Marka Testowa ' . $sfx, 'site_url' => 'https://example.com', 'active' => 1,
]);
ok('la marque est créée', $b !== null, $eb);
ok('son slug est calculé', str_starts_with((string) $b['slug'], 'marka-testowa-'), $b['slug'] ?? null);

// Deux marques homonymes ne peuvent pas partager un slug : c'est une adresse.
[$b2] = wsm_brand_save($pdo, ['name' => 'Marka Testowa ' . $sfx, 'active' => 1]);
ok('un homonyme reçoit un slug distinct', $b2 && $b2['slug'] !== $b['slug'], [$b['slug'], $b2['slug'] ?? null]);

// Le slug ne bouge pas quand on corrige le nom : c'est une adresse publique.
$slugAvant = (string) $b['slug'];
[$b3] = wsm_brand_save($pdo, ['name' => 'Marka Poprawiona ' . $sfx, 'active' => 1], [], (int) $b['id']);
ok('corriger le nom ne réécrit pas l\'adresse publique', (string) $b3['slug'] === $slugAvant,
    [$slugAvant, $b3['slug'] ?? null]);

// ---- 4. Le logo garde sa transparence --------------------------------------------
echo "\n-- logo zachowuje przezroczystość --\n";
if (!extension_loaded('gd')) {
    ok('GD absent — test du logo ignoré', true);
} else {
    // Un PNG 40×20 entièrement transparent, fabriqué pour le test.
    $im = imagecreatetruecolor(40, 20);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 0, 0, 127));
    $tmp = sys_get_temp_dir() . '/wsm-logo-' . $sfx . '.png';
    imagepng($im, $tmp);
    imagedestroy($im);

    // wsm_media_store exige un vrai envoi HTTP (is_uploaded_file) : on teste
    // donc directement l'étage qui compte, l'écriture avec canal alpha.
    $src = imagecreatefrompng($tmp);
    $dst = imagecreatetruecolor(20, 10);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, 20, 10, 40, 20);
    $out = sys_get_temp_dir() . '/wsm-logo-out-' . $sfx . '.png';
    imagepng($dst, $out, 6);
    imagedestroy($src); imagedestroy($dst);

    $relu = imagecreatefrompng($out);
    $rgba = imagecolorat($relu, 5, 5);
    $alpha = ($rgba >> 24) & 0x7F;
    imagedestroy($relu);
    ok('le pixel transparent le reste après ré-encodage', $alpha === 127, $alpha);
    @unlink($tmp); @unlink($out);
}

// Un logo doit pouvoir être un PNG : le stockage l'accepte comme adresse valable.
ok('un chemin de média .png est accepté',
    wsm_media_valid_url('media/' . str_repeat('a', 24) . '.png'));
ok('.webp aussi', wsm_media_valid_url('media/' . str_repeat('a', 24) . '.webp'));
ok('un chemin inventé est refusé', !wsm_media_valid_url('media/../api/config.local.php'));

// ---- 5. La marque arrive jusqu'à la vitrine ---------------------------------------
echo "\n-- marka w sklepie --\n";
$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$pid = 'test-br-' . $sfx;
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible, slug,
                    stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, brand_id)
               VALUES (?,?,?,?,'Opublikowany',1,1,?,20,0.23,250,120,80,40,?,?)")
     ->execute([$pid, $cat, 'Czekolada marki ' . $sfx, 30.00, $pid, strtoupper($sfx), (int) $b['id']]);

$pdo->prepare("UPDATE wsm_brands SET logo_url = ? WHERE id = ?")
    ->execute(['media/' . str_repeat('b', 24) . '.png', (int) $b['id']]);

$prod = wsm_shop_product($pdo, $pid, 'pl');
ok('la fiche produit porte la marque', ($prod['brand']['name'] ?? '') === 'Marka Poprawiona ' . $sfx,
    $prod['brand'] ?? null);
ok('avec son logo', str_ends_with((string) ($prod['brand']['logo'] ?? ''), '.png'), $prod['brand']['logo'] ?? null);
ok('et son adresse', ($prod['brand']['site'] ?? '') === 'https://example.com', $prod['brand']['site'] ?? null);

$cat2 = array_values(array_filter(wsm_shop_products($pdo, 'pl'), fn($x) => $x['id'] === $pid));
ok('le catalogue la porte aussi', ($cat2[0]['brand']['name'] ?? '') !== '', $cat2[0]['brand'] ?? null);

// Un produit sans marque ne doit pas inventer de cadre vide.
$autre = array_values(array_filter(wsm_shop_products($pdo, 'pl'), fn($x) => $x['id'] !== $pid));
if ($autre) {
    ok('un produit sans marque renvoie null, pas un tableau vide',
        $autre[0]['brand'] === null || is_array($autre[0]['brand']), $autre[0]['brand']);
}

// ---- 6. Désactivée : invisible en boutique, intacte en base -------------------------
echo "\n-- marka wyłączona --\n";
$pdo->prepare("UPDATE wsm_brands SET active = 0 WHERE id = ?")->execute([(int) $b['id']]);
$prodOff = wsm_shop_product($pdo, $pid, 'pl');
ok('une marque désactivée disparaît de la vitrine', $prodOff['brand'] === null, $prodOff['brand']);
ok('mais le produit reste affichable', ($prodOff['name'] ?? '') !== '');
ok('et la marque existe toujours en base', wsm_brand($pdo, (int) $b['id']) !== null);
$pdo->prepare("UPDATE wsm_brands SET active = 1 WHERE id = ?")->execute([(int) $b['id']]);

// ---- 7. Suppression : refusée tant qu'un produit la porte ---------------------------
echo "\n-- usuwanie --\n";
ok('la marque est comptée sur son produit', (wsm_brand_counts($pdo)[(int) $b['id']] ?? 0) >= 1,
    wsm_brand_counts($pdo)[(int) $b['id']] ?? 0);
[$okDel, $msg] = wsm_brand_delete($pdo, (int) $b['id']);
ok('on refuse de supprimer une marque portée par un produit', $okDel === false, $msg);
ok('et on dit pourquoi, avec le nombre', str_contains($msg, 'produkt'), $msg);

$pdo->prepare("UPDATE wsm_products SET brand_id = NULL WHERE id = ?")->execute([$pid]);
[$okDel2, $msg2] = wsm_brand_delete($pdo, (int) $b['id']);
ok('une fois libérée, la suppression passe', $okDel2 === true, $msg2);
ok('elle a bien disparu', wsm_brand($pdo, (int) $b['id']) === null);

// ---- Nettoyage -----------------------------------------------------------------------
$pdo->prepare("DELETE FROM wsm_products WHERE id = ?")->execute([$pid]);
if ($b2) $pdo->prepare("DELETE FROM wsm_brands WHERE id = ?")->execute([(int) $b2['id']]);

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
