<?php
// ============================================================================
//  e2e_kategorie.php — les rayons de la boutique, et le ménage.
//
//  DEUX CHOSES QUE CE TEST GARDE.
//
//  1. LA VITRINE LIT LES CATÉGORIES EN BASE. Elle les ignorait complètement :
//     pas de filtre, pas de menu, tout dans un catalogue à plat, alors que le
//     champ est obligatoire sur chaque produit depuis toujours. On rangeait
//     donc les articles dans des tiroirs que personne n'ouvrait.
//
//     Une catégorie VIDE ne s'affiche pas : un rayon qui mène à « aucun
//     produit » est une impasse, et il s'en crée un dès qu'on retire le
//     dernier article d'une gamme.
//
//  2. LE MÉNAGE NE DÉTRUIT RIEN. Cinq catégories venaient d'une maquette de
//     boulangerie — Pieczywo, Lody, Katering — pour une chocolaterie. On les
//     DÉSACTIVE : category_id est NOT NULL, donc une catégorie effacée
//     laisserait ses produits avec une référence morte. Et une catégorie qui
//     tient encore un produit VISIBLE n'est pas touchée : si quelqu'un s'en
//     sert pour de vrai, ce n'est plus une maquette.
//
//  Usage :  php tests/e2e_kategorie.php
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
require_once dirname(__DIR__) . '/seed.php';

echo "webshop_mrszoko — end-to-end kategorie\n\n";

// Base isolée : le ménage touche des lignes réelles, on ne le joue pas sur la
// base de travail.
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE wsm_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE,
           sort_order INT DEFAULT 0, active INT DEFAULT 1)");
$db->exec("CREATE TABLE wsm_products (id TEXT PRIMARY KEY, category_id INT, nom TEXT,
           shop_visible INT DEFAULT 0, active INT DEFAULT 1)");
$cat = function (string $n) use ($db): int {
    $db->prepare("INSERT INTO wsm_categories (name) VALUES (?)")->execute([$n]);
    return (int) $db->lastInsertId();
};
$prod = function (string $id, int $c, int $vis) use ($db) {
    $db->prepare("INSERT INTO wsm_products (id, category_id, nom, shop_visible, active) VALUES (?,?,?,?,1)")
       ->execute([$id, $c, $id, $vis]);
};

$czek = $cat('Czekolada');
$piec = $cat('Pieczywo');          // maquette, produits masqués
$lody = $cat('Lody');              // maquette, mais un produit EN VENTE
$vide = $cat('Pralinki');          // active, aucun produit
$prod('s-dark70', $czek, 1);
$prod('s-ruby',   $czek, 1);
$prod('p-chleb',  $piec, 0);       // dort déjà
$prod('l-sorbet', $lody, 1);       // vendu pour de vrai
$prod('test-dpd-abc', $czek, 1);   // artefact de mise au point

// ---- 1. Le ménage, joué sur cette base --------------------------------------
echo "-- sprzatanie nie niszczy --\n";
$n = 0;
foreach (wsm_content_menage() as [$sql, $args]) {
    // Comme wsm_sync_content() : une opération sur une table que cette base
    // n'a pas (wsm_shop_i18n, ici) se tait au lieu de tout arrêter.
    try { $st = $db->prepare($sql); $st->execute($args); $n += $st->rowCount(); }
    catch (Throwable $e) { /* table absente : rien à nettoyer */ }
}
ok('il a touché quelque chose', $n > 0, $n);

$act = fn(string $nom) => (int) $db->query("SELECT active FROM wsm_categories WHERE name = '$nom'")->fetchColumn();
ok('« Pieczywo » désactivée — plus rien de visible dedans', $act('Pieczywo') === 0);
ok('« Lody » INTACTE — elle tient encore un produit en vente', $act('Lody') === 1);
ok('« Czekolada » intacte', $act('Czekolada') === 1);
// Une catégorie vide qui n'est PAS dans la liste nommée reste active : le
// ménage ne devine pas, il retire ce qu'on a désigné.
ok('« Pralinki », vide mais non nommée, reste active', $act('Pralinki') === 1);

$vis = (int) $db->query("SELECT shop_visible FROM wsm_products WHERE id = 'test-dpd-abc'")->fetchColumn();
ok('le produit de test DPD est masqué', $vis === 0);
ok('… mais toujours en base — les documents qui le nomment restent lisibles',
   (int) $db->query("SELECT COUNT(*) FROM wsm_products WHERE id = 'test-dpd-abc'")->fetchColumn() === 1);
ok('aucune catégorie supprimée', (int) $db->query("SELECT COUNT(*) FROM wsm_categories")->fetchColumn() === 4);
ok('aucun produit supprimé', (int) $db->query("SELECT COUNT(*) FROM wsm_products")->fetchColumn() === 5);

// Idempotence : rejoué, il ne trouve plus rien.
$n2 = 0;
foreach (wsm_content_menage() as [$sql, $args]) {
    try { $st = $db->prepare($sql); $st->execute($args); $n2 += $st->rowCount(); }
    catch (Throwable $e) { /* table absente, comme au premier passage */ }
}
ok('rejoué, il ne change plus rien', $n2 === 0, $n2);

// ---- 2. Les rayons que la boutique montre -----------------------------------
echo "\n-- witryna czyta kategorie z bazy --\n";
$rayons = wsm_shop_categories($db);
$noms = array_column($rayons, 'name');
ok('« Czekolada » est un rayon', in_array('Czekolada', $noms, true), $noms);
ok('« Lody » aussi — son produit est en vente', in_array('Lody', $noms, true), $noms);
// LE POINT : une catégorie sans produit visible n'est PAS un rayon. Un lien
// vers un rayon vide est une impasse, et le client la découvre après le clic.
ok('« Pieczywo » n\'est PAS un rayon — rien de visible dedans', !in_array('Pieczywo', $noms, true), $noms);
ok('« Pralinki » non plus — aucune ligne dedans', !in_array('Pralinki', $noms, true), $noms);

$ile = array_column($rayons, 'ile', 'name');
// Le produit de test vient d'être masqué : il ne doit plus être compté.
ok('le compte de « Czekolada » ignore le produit masqué', (int) ($ile['Czekolada'] ?? 0) === 2, $ile);

// ── LE RAYON QU'ON CRÉE EN RANGEANT UN PRODUIT ──────────────────────────────
//
// LA PANNE RAPPORTÉE PAR LA MAISON : « je crée un produit, j'ouvre la liste des
// catégories, et je ne peux choisir que Czekolada ». C'était exact. Le ménage
// avait éteint les cinq rayons de la maquette de boulangerie, il n'en restait
// qu'un allumé, et le champ est obligatoire en base : une liste à un seul
// choix, c'est-à-dire un cul-de-sac.
//
// La fiche produit porte maintenant « albo wpisz nową kategorię », et le
// panneau Kategorie suit la MÊME règle — celle qui est testée ici. Écrite deux
// fois, elle aurait divergé au premier correctif.
echo "\n-- kategoria z nazwy: tworzy, wskrzesza, nie dubluje --\n";
require_once dirname(__DIR__) . '/commerce.php';

$nomTest = 'Test rayon zz';
$db->prepare('DELETE FROM wsm_categories WHERE name LIKE ?')->execute(['Test rayon%']);

[$id1, $q1] = wsm_categorie_assure($db, $nomTest);
ok('un nom libre crée le rayon', $id1 > 0 && $q1 === 'cree', [$id1, $q1]);

// La casse ne doit PAS créer un deuxième rayon : MySQL compare sans en tenir
// compte, SQLite en tient compte dès qu'il y a un accent. Sans comparaison en
// PHP, la boutique aurait deux rayons en production et un seul ici.
[$id2, $q2] = wsm_categorie_assure($db, mb_strtoupper($nomTest));
ok('… et le même nom en majuscules retombe dessus', $id2 === $id1 && $q2 === 'trouve', [$id2, $q2]);
ok('… sans créer de doublon',
   (int) $db->query("SELECT COUNT(*) FROM wsm_categories WHERE name LIKE 'Test rayon%'")->fetchColumn() === 1);

// LE CAS QU'ON OUBLIE : le rayon existe, mais il est éteint. Répondre « ce nom
// existe déjà » serait vrai et parfaitement inutile — c'est ce que faisait le
// panneau, sur des rayons visibles juste au-dessus avec leur bouton « Włącz ».
$db->prepare('UPDATE wsm_categories SET active = 0 WHERE id = ?')->execute([$id1]);
[$id3, $q3] = wsm_categorie_assure($db, $nomTest);
ok('un rayon éteint se rallume au lieu de refuser', $id3 === $id1 && $q3 === 'rallume', [$id3, $q3]);
ok('… et il est bien rallumé en base',
   (int) $db->query("SELECT active FROM wsm_categories WHERE id = " . (int) $id1)->fetchColumn() === 1);

ok('un nom vide ne crée rien', wsm_categorie_assure($db, '   ')[0] === 0);
ok('un nom trop long non plus', wsm_categorie_assure($db, str_repeat('a', 81))[0] === 0);
ok('… et rien ne s\'est glissé en base au passage',
   (int) $db->query("SELECT COUNT(*) FROM wsm_categories WHERE name LIKE 'Test rayon%' OR name LIKE 'aaaa%'")->fetchColumn() === 1);

$db->prepare('DELETE FROM wsm_categories WHERE id = ?')->execute([$id1]);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
