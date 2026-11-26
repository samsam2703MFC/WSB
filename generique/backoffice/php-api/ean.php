<?php
// ============================================================================
//  ean.php — le code EAN (GTIN) d'un produit : la clé par laquelle le magasin
//  le reconnaît au scanner.
//
//  Demande du magasin (Szoko, 26/11/2026) : « un code EAN pour chaque produit,
//  pour que le système sache à quel produit ajouter une quantité — à la
//  réception, quand on emballe une commande, quand on sort un produit ».
//
//  Trois règles :
//   1. UN CODE = UN PRODUIT. Deux fiches au même EAN, et le scanner ajoute au
//      hasard à l'une ou à l'autre : le stock ment sans que personne le voie.
//      Un EAN déjà pris est donc refusé, en nommant la fiche qui le porte.
//   2. LA CLÉ DE CONTRÔLE EST VÉRIFIÉE. Un chiffre mal recopié depuis
//      l'étiquette donne un code qui ne passera jamais au scanner — on le dit
//      au moment de la saisie, pas le jour de la réception.
//   3. 8, 12, 13 ou 14 CHIFFRES (EAN-8, UPC-A, EAN-13, GTIN-14 d'un carton).
//      Un UPC-A (12) et le même code précédé d'un 0 (13) désignent le même
//      article : la recherche les confond.
// ============================================================================
declare(strict_types=1);

/** Les chiffres seuls : le scanner, la main et l'étiquette ne mettent pas les mêmes espaces. */
function wsm_ean_norm(string $s): string {
    return preg_replace('/\D+/', '', $s) ?? '';
}

/** Longueur GTIN et clé de contrôle (modulo 10, poids 3-1 depuis la droite). */
function wsm_ean_valid(string $ean): bool {
    if (!preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $ean) || preg_match('/^0+$/', $ean)) return false;
    $sum = 0;
    foreach (array_reverse(str_split(substr($ean, 0, -1))) as $i => $d) $sum += (int) $d * ($i % 2 === 0 ? 3 : 1);
    return (10 - $sum % 10) % 10 === (int) substr($ean, -1);
}

/** Les écritures équivalentes d'un même code (UPC-A ↔ EAN-13 à zéro de tête). */
function wsm_ean_variantes(string $ean): array {
    $v = [$ean];
    if (strlen($ean) === 12) $v[] = '0' . $ean;
    if (strlen($ean) === 13 && $ean[0] === '0') $v[] = substr($ean, 1);
    return array_values(array_unique($v));
}

/**
 * Le produit qui porte ce code, ou null.
 * @return array{id:string,nom:string,ean:string,sku:string,stock:int,image_url:string}|null
 */
function wsm_ean_find(PDO $pdo, string $ean, string $sauf = ''): ?array {
    $ean = wsm_ean_norm($ean);
    if ($ean === '') return null;
    $v = wsm_ean_variantes($ean);
    $in = implode(',', array_fill(0, count($v), '?'));
    $st = $pdo->prepare("SELECT id, nom, ean, sku, stock, image_url FROM wsm_products
                          WHERE ean IN ($in)" . ($sauf !== '' ? " AND id <> ?" : '') . " ORDER BY active DESC LIMIT 1");
    $st->execute($sauf !== '' ? [...$v, $sauf] : $v);
    $r = $st->fetch();
    return $r ? ['id' => (string) $r['id'], 'nom' => (string) $r['nom'], 'ean' => (string) $r['ean'],
                 'sku' => (string) ($r['sku'] ?? ''), 'stock' => (int) $r['stock'], 'image_url' => (string) ($r['image_url'] ?? '')] : null;
}

/**
 * Le code saisi pour le produit $pid, contrôlé. Chaîne vide = « pas de code » (permis).
 * @return array{0:string,1:?string} [code normalisé, erreur en polonais]
 */
function wsm_ean_check(PDO $pdo, string $saisie, string $pid): array {
    $ean = wsm_ean_norm($saisie);
    if ($ean === '') return ['', null];
    if (!preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $ean)) return [$ean, 'EAN ma 8, 12, 13 albo 14 cyfr'];
    if (!wsm_ean_valid($ean)) return [$ean, 'zła cyfra kontrolna — sprawdź, czy kod jest dobrze przepisany'];
    $autre = wsm_ean_find($pdo, $ean, $pid);
    if ($autre) return [$ean, 'ten kod ma już produkt „' . $autre['nom'] . '”'];
    return [$ean, null];
}

/**
 * Pose le code sur un produit (depuis le scanner : « ce code inconnu, c'est ce produit »).
 * @return ?string l'erreur, ou null
 */
function wsm_ean_assign(PDO $pdo, string $pid, string $saisie, string $actor = ''): ?string {
    $st = $pdo->prepare("SELECT nom, ean FROM wsm_products WHERE id = ?");
    $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p) return 'nie ma takiego produktu';
    [$ean, $err] = wsm_ean_check($pdo, $saisie, $pid);
    if ($err !== null) return $err;
    if ($ean === '') return 'pusty kod';
    $pdo->prepare("UPDATE wsm_products SET ean = ? WHERE id = ?")->execute([$ean, $pid]);
    if (function_exists('wsm_audit')) {
        wsm_audit($pdo, $actor, 'EAN przypisany', $pid . ' ' . ($p['ean'] !== '' ? $p['ean'] . ' → ' : '') . $ean, 'Sieć');
    }
    return null;
}

/**
 * Un produit NOUVEAU né d'un scan : le code est sur l'étiquette, le produit pas
 * encore dans la base. Créé en brouillon (« Szkic », pas en boutique) avec son
 * EAN, son nom, sa catégorie et, s'il est donné, son prix brut : la photo et la
 * description se complètent ensuite dans Produkty. Même identifiant et même
 * adresse que la création depuis Produkty (wsm_slug_libre).
 * @return array{0:string,1:?string} [id du produit, erreur en polonais]
 */
function wsm_ean_nowy_produkt(PDO $pdo, string $nazwa, int $kat, string $cena, string $saisie, string $actor = ''): array {
    $nazwa = trim($nazwa);
    if ($nazwa === '') return ['', 'podaj nazwę produktu'];
    if (mb_strlen($nazwa) > WSM_PRODUCT_NAME_MAX) return ['', 'nazwa może mieć najwyżej ' . WSM_PRODUCT_NAME_MAX . ' znaków'];
    $st = $pdo->prepare("SELECT 1 FROM wsm_categories WHERE id = ?");
    $st->execute([$kat]);
    if ($kat <= 0 || !$st->fetchColumn()) return ['', 'wybierz kategorię'];
    $prix = 0.0;
    if (trim($cena) !== '') {
        $pr = wsm_parse_price($cena);
        if ($pr === null || $pr < 0) return ['', 'nie rozumiem tej ceny'];
        $prix = $pr;
    }
    [$ean, $err] = wsm_ean_check($pdo, $saisie, '');
    if ($err !== null) return ['', $err];
    if ($ean === '') return ['', 'pusty kod'];
    $id = wsm_slug_libre($pdo, wsm_slugify($nazwa, 'produkt'), 'id');
    $slug = wsm_slug_libre($pdo, wsm_slugify($nazwa, $id), 'slug', $id);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO wsm_products
                (id, category_id, nom, slug, statut, active, shop_visible, sort_order, rodzaj, ean, prix)
                VALUES (?,?,?,?,'Szkic',1,0,?,'towar',?,?)")
            ->execute([$id, $kat, $nazwa, $slug,
                       (int) $pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM wsm_products")->fetchColumn(), $ean, $prix]);
        $insT = $pdo->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES (?,?,?)");
        foreach (['name' => $nazwa, 'subtitle' => '', 'desc' => ''] as $suf => $v) {
            try { $insT->execute([defined('WSM_CMS_BASE_LANG') ? WSM_CMS_BASE_LANG : 'pl', 'product.' . $id . '.' . $suf, $v]); }
            catch (Throwable $e) { /* déjà là */ }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('ean: nowy produkt: ' . $e->getMessage());
        return ['', 'nie udało się utworzyć produktu'];
    }
    if (function_exists('wsm_audit')) wsm_audit($pdo, $actor, 'Dodanie produktu (skaner)', 'wsm_products ' . $id . ' EAN ' . $ean, 'Sieć');
    return [$id, null];
}

/** schema.org : gtin8, gtin12, gtin13 ou gtin14 selon la longueur. */
function wsm_ean_schema_key(string $ean): string {
    return 'gtin' . (in_array(strlen($ean), [8, 12, 13, 14], true) ? strlen($ean) : 13);
}
