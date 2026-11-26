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

/** schema.org : gtin8, gtin12, gtin13 ou gtin14 selon la longueur. */
function wsm_ean_schema_key(string $ean): string {
    return 'gtin' . (in_array(strlen($ean), [8, 12, 13, 14], true) ? strlen($ean) : 13);
}
