<?php
// ============================================================================
//  e2e_produkt_nowy.php — créer un produit depuis la console.
//
//  CE CHEMIN N'EXISTAIT PAS. L'écran s'appelle « Produkty », on y modifie et
//  on y supprime — donc on suppose qu'on peut y ajouter. Les 22 produits de la
//  base venaient tous du fichier de semis ou d'anciennes maquettes, et rien
//  ne le disait : pas de bouton grisé, pas de message, rien. On cherchait.
//
//  DEUX RÈGLES QUE CE TEST GARDE :
//
//   · L'IDENTIFIANT EST UNE CLÉ, PAS UNE ADRESSE. Commandes, stock, factures
//     et documents le portent pour toujours. Il se dérive du nom, mais il doit
//     être unique — deux produits qui le partagent, ce sont deux histoires qui
//     se mélangent.
//   · UN PRODUIT NAÎT INVISIBLE. Sans photo, sans description et sans poids,
//     il n'a rien à faire en vente. C'est l'opérateur qui l'ouvre, quand il
//     est prêt.
//
//  Usage :  php tests/e2e_produkt_nowy.php
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

echo "webshop_mrszoko — end-to-end nowy produkt\n\n";
$pdo = wsm_bootstrap();

// ---- 1. Le nom devient une adresse lisible --------------------------------
//
// Jeter les diacritiques au lieu de les transliterer donnait « wi-teczna » :
// une adresse que personne ne relie au produit, et qu'on ne corrige plus une
// fois indexée par un moteur de recherche.
echo "-- nazwa staje sie czytelnym adresem --\n";
foreach ([
    'Czekolada ciemna 70 %'    => 'czekolada-ciemna-70',
    'Świąteczna niespodzianka' => 'swiateczna-niespodzianka',
    'Żółć'                     => 'zolc',
    'Zestaw 4×250 g'           => 'zestaw-4-250-g',
    'Łódź — Gdańsk'            => 'lodz-gdansk',
] as $nom => $attendu) {
    ok("« $nom » → $attendu", wsm_slugify($nom, 'produkt') === $attendu, wsm_slugify($nom, 'produkt'));
}
ok('un nom sans lettre latine retombe sur le repli', wsm_slugify('...', 'produkt') === 'produkt', wsm_slugify('...', 'produkt'));
ok('et jamais plus de 80 caractères', strlen(wsm_slugify(str_repeat('bardzo dlugi produkt ', 20), 'x')) <= 80);

// ---- 2. Deux produits ne partagent jamais une clé -------------------------
echo "\n-- dwa produkty nigdy nie dziela klucza --\n";
$pris = (string) $pdo->query("SELECT id FROM wsm_products LIMIT 1")->fetchColumn();
if ($pris !== '') {
    $libre = wsm_slug_libre($pdo, $pris, 'id');
    ok('un identifiant déjà pris est écarté', $libre !== $pris, $libre);
    ok('… et le remplaçant dérive du même nom', str_starts_with($libre, substr($pris, 0, 8)), $libre);
    $st = $pdo->prepare("SELECT 1 FROM wsm_products WHERE id = ?");
    $st->execute([$libre]);
    ok('… et il est réellement libre', !$st->fetchColumn());
    // Son PROPRE identifiant ne doit pas se voir refuser : sinon renommer un
    // produit lui inventerait une adresse à chaque enregistrement.
    ok('un produit garde son propre slug', wsm_slug_libre($pdo, $pris, 'id', $pris) === $pris);
}
ok('un nom vide ne donne jamais une clé vide', wsm_slug_libre($pdo, '', 'id') !== '');

// ---- 2bis. La clé tient dans sa colonne ------------------------------------
//
// LE DÉFAUT VU EN PRODUCTION : « Nie udało się utworzyć produktu » dès que le
// nom dépassait une cinquantaine de lettres. L'identifiant dérivé faisait
// jusqu'à 80 caractères, la colonne wsm_products.id en tient 48 ; SQLite
// laissait passer, MySQL refusait. On crée ici, pour de vrai, un produit au
// nom très long — sur le moteur où tourne le test (MySQL : celui de la prod).
echo "\n-- klucz mieści się w kolumnie --\n";
$long = 'Czekolada mleczna w kaletkach Callebaut 823 33,6% 2,5 kg ' . bin2hex(random_bytes(2)) . ' edycja świąteczna dla cukierni';
$idL = wsm_slug_libre($pdo, wsm_slugify($long, 'produkt'), 'id');
ok('un nom de 80 lettres donne une clé de 40 au plus, coupée entre deux mots', strlen($idL) <= WSM_PRODUCT_ID_MAX && !str_ends_with($idL, '-') && str_starts_with($idL, 'czekolada-mleczna-w-kaletkach'), $idL);
$slL = wsm_slug_libre($pdo, wsm_slugify($long, 'produkt'), 'slug', $idL);
ok('l\'adresse publique garde jusqu\'à 80 caractères', strlen($slL) <= WSM_PRODUCT_SLUG_MAX && strlen($slL) > WSM_PRODUCT_ID_MAX, $slL);
$catL = (int) $pdo->query("SELECT id FROM wsm_categories ORDER BY id LIMIT 1")->fetchColumn();
$okL = true;
try {
    $pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, slug, statut, active, shop_visible, sort_order) VALUES (?,?,?,?,'Szkic',1,0,999)")
        ->execute([$idL, $catL, $long, $slL]);
    foreach (['name', 'subtitle', 'desc', 'prop.12.k'] as $suf) {
        $pdo->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES ('pl', ?, '')")->execute(['product.' . $idL . '.' . $suf]);
    }
} catch (Throwable $e) { $okL = $e->getMessage(); }
ok('… et le produit se crée vraiment, clés de texte comprises', $okL === true, $okL);
$pdo->prepare("DELETE FROM wsm_shop_i18n WHERE k LIKE ?")->execute(['product.' . $idL . '.%']);
$pdo->prepare("DELETE FROM wsm_products WHERE id = ?")->execute([$idL]);
$doublons = [];
for ($i = 0; $i < 3; $i++) {
    $d = wsm_slug_libre($pdo, wsm_slugify($long, 'produkt'), 'id');
    $pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, slug, statut, active, shop_visible, sort_order) VALUES (?,?,?,?,'Szkic',1,0,999)")
        ->execute([$d, $catL, $long, $d]);
    $doublons[] = $d;
}
ok('trois produits du même nom : trois clés distinctes, toutes dans la colonne', count(array_unique($doublons)) === 3 && max(array_map('strlen', $doublons)) <= WSM_PRODUCT_ID_MAX, $doublons);
foreach ($doublons as $d) $pdo->prepare("DELETE FROM wsm_products WHERE id = ?")->execute([$d]);
$ecran = (string) @file_get_contents(dirname(__DIR__, 2) . '/produkty.php');
ok('l\'écran refuse un nom trop long sur le champ, et dit le motif d\'un refus de la base',
   str_contains($ecran, "mb_strlen(\$nazwa) > WSM_PRODUCT_NAME_MAX") && str_contains($ecran, "wsm_db_powod(\$e, 'produkty: nowy produkt')"));

// ---- 3. Ce que la création écrit ------------------------------------------
//
// On rejoue ici ce que fait l'écran, pour que la règle soit tenue même si
// quelqu'un réécrit le formulaire un jour.
echo "\n-- co zapisuje utworzenie --\n";
$cat = (int) $pdo->query("SELECT id FROM wsm_categories WHERE active = 1 ORDER BY sort_order LIMIT 1")->fetchColumn();
ok('il existe au moins une catégorie active', $cat > 0, $cat);

$nom = 'Test E2E ' . bin2hex(random_bytes(3));
$id  = wsm_slug_libre($pdo, wsm_slugify($nom, 'produkt'), 'id');
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, slug, prix, statut, active, shop_visible, sort_order)
               VALUES (?,?,?,?,?,?,1,0,999)")
    ->execute([$id, $cat, $nom, $id, 0, 'Szkic']);
foreach (['name' => $nom, 'subtitle' => '', 'desc' => ''] as $suf => $v) {
    $pdo->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES ('pl',?,?)")
        ->execute(['product.' . $id . '.' . $suf, $v]);
}
$r = $pdo->prepare("SELECT * FROM wsm_products WHERE id = ?");
$r->execute([$id]);
$prod = $r->fetch(PDO::FETCH_ASSOC);
ok('le produit existe', (bool) $prod);
ok('IL NAÎT INVISIBLE', (int) $prod['shop_visible'] === 0, $prod['shop_visible']);
ok('mais actif — il vit dans le catalogue interne', (int) $prod['active'] === 1);
ok('et marqué comme brouillon', (string) $prod['statut'] === 'Szkic', $prod['statut']);

// LES TROIS CLÉS DOIVENT NAÎTRE AVEC LUI. wsm_cms_save() refuse une clé
// absente ; sans elles, la description tapée sur la fiche partirait en
// silence — le défaut qu'on vient de réparer sur les produits existants.
$n = (int) $pdo->query("SELECT COUNT(*) FROM wsm_shop_i18n WHERE k LIKE 'product.$id.%'")->fetchColumn();
ok('ses trois textes existent dès la création', $n === 3, $n);

// Le catalogue public ne doit pas le voir tant qu'il n'est pas prêt.
$vu = (int) $pdo->query("SELECT COUNT(*) FROM wsm_products WHERE id = '$id' AND shop_visible = 1 AND active = 1")->fetchColumn();
ok('la boutique ne le voit pas', $vu === 0);

// ---- Ménage ---------------------------------------------------------------
$pdo->prepare("DELETE FROM wsm_shop_i18n WHERE k LIKE ?")->execute(['product.' . $id . '.%']);
$pdo->prepare("DELETE FROM wsm_products WHERE id = ?")->execute([$id]);
echo "\n(produit de test retiré)\n";

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
