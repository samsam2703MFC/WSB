<?php
// ============================================================================
//  brand.php — les marques, et leur logo.
//
//  Une marque est une entité à part entière, pas une chaîne recopiée sur
//  chaque produit. C'est ce qui permet de corriger une orthographe, de
//  remplacer un logo ou d'ajouter l'adresse du site UNE fois, et de le voir
//  partout — plutôt que de repasser sur quarante fiches.
//
//  Trois règles, dans l'ordre :
//
//   1. LE LOGO GARDE SA TRANSPARENCE. Un logo aplati sur un fond crème
//      ressort en rectangle sale dès qu'on le pose sur autre chose que la
//      couleur d'origine. Il passe donc par une variante du stockage média
//      qui préserve le canal alpha — contrairement aux photos de produit,
//      qu'on a raison d'aplatir.
//   2. ON NE SUPPRIME PAS UNE MARQUE PORTÉE PAR DES PRODUITS. Même règle que
//      pour un produit référencé par une commande : on la désactive. Elle
//      disparaît de la boutique, les fiches gardent leur histoire.
//   3. LE SLUG EST STABLE. Il sert d'adresse publique (filtre du catalogue) :
//      il se calcule à la création et ne se réécrit pas parce que quelqu'un a
//      corrigé une majuscule.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/media.php';

/** La table existe-t-elle ? Créée avec le schéma, complétée au besoin. */
function wsm_ensure_brands(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_brands')) wsm_apply_schema($pdo);
    wsm_ensure_columns($pdo, 'wsm_products', [
        // Une référence, pas une copie du nom.
        'brand_id' => ['INT UNSIGNED NULL', 'INTEGER NULL'],
    ]);
}

/** « Mister Szoko & Fils » → « mister-szoko-fils ». */
function wsm_brand_slugify(string $s): string {
    $s = mb_strtolower(trim($s));
    $from = ['ą','ć','ę','ł','ń','ó','ś','ź','ż','á','à','â','ä','é','è','ê','ë','í','î','ï','ö','ô','ú','ù','û','ü','ç'];
    $to   = ['a','c','e','l','n','o','s','z','z','a','a','a','a','e','e','e','e','i','i','i','o','o','u','u','u','u','c'];
    $s = str_replace($from, $to, $s);
    $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? '';
    return trim($s, '-');
}

/** Un slug qu'aucune autre marque ne porte déjà. */
function wsm_brand_free_slug(PDO $pdo, string $base, ?int $exceptId = null): string {
    $base = wsm_brand_slugify($base) ?: 'marka';
    $slug = $base;
    for ($i = 2; $i < 200; $i++) {
        $sql = "SELECT COUNT(*) FROM wsm_brands WHERE slug = ?" . ($exceptId ? " AND id <> ?" : '');
        $st = $pdo->prepare($sql);
        $st->execute($exceptId ? [$slug, $exceptId] : [$slug]);
        if (!(int) $st->fetchColumn()) return $slug;
        $slug = $base . '-' . $i;
    }
    return $base . '-' . bin2hex(random_bytes(2));
}

/**
 * @param bool $activeOnly true pour la boutique, false pour la console
 */
function wsm_brands_all(PDO $pdo, bool $activeOnly = false): array {
    try {
        $sql = "SELECT * FROM wsm_brands" . ($activeOnly ? " WHERE active = 1" : '')
             . " ORDER BY sort_order, name";
        return $pdo->query($sql)->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

function wsm_brand(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM wsm_brands WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Combien de produits portent chaque marque — pour la console, et pour savoir
 *  si une suppression est encore permise. */
function wsm_brand_counts(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT brand_id, COUNT(*) AS n FROM wsm_products
                              WHERE brand_id IS NOT NULL GROUP BY brand_id")->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) $out[(int) $r['brand_id']] = (int) $r['n'];
    return $out;
}

/**
 * Enregistre une marque, avec son logo s'il est fourni.
 *
 * @param array $in    champs du formulaire
 * @param array $file  $_FILES['logo'] éventuel
 * @param ?int  $id    null pour créer
 * @return array [marque|null, erreurs par champ]
 */
function wsm_brand_save(PDO $pdo, array $in, array $file = [], ?int $id = null): array {
    $e = [];
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '')            $e['name'] = 'nazwa wymagana';
    if (mb_strlen($name) > 120)  $e['name'] = 'maks. 120 znaków';

    $site = trim((string) ($in['site_url'] ?? ''));
    if ($site !== '' && !preg_match('#^https://#i', $site)) {
        // http ferait basculer la page en contenu mixte : le navigateur
        // bloquerait le lien sans rien dire à personne.
        $e['site_url'] = 'adres musi zaczynać się od https://';
    }

    $cur = $id ? wsm_brand($pdo, $id) : null;
    if ($id && !$cur) return [null, ['id' => 'nie znaleziono marki']];

    $logo = $cur['logo_url'] ?? '';
    $fresh = null;
    if (!empty($in['remove_logo'])) {
        $logo = '';
    } elseif (($file['name'] ?? '') !== '') {
        [$url, $err] = wsm_media_store($file, true);       // true : on garde l'alpha
        if ($err !== null) $e['logo'] = $err;
        else { $logo = $url; $fresh = $url; }
    } elseif (isset($in['logo_url'])) {
        $u = trim((string) $in['logo_url']);
        if (!wsm_media_valid_url($u)) $e['logo_url'] = 'nieprawidłowy adres obrazu';
        else $logo = $u;
    }

    if ($e) {
        if ($fresh !== null) wsm_media_delete($fresh);     // pas de fichier orphelin
        return [null, $e];
    }

    $cols = ['name' => $name, 'logo_url' => $logo];
    // Sur une modification, on ne touche QU'aux champs réellement envoyés. Un
    // appel partiel ne doit pas effacer en silence l'adresse du site ou la
    // note : c'est le genre de perte qu'on ne remarque que des semaines plus
    // tard, quand le lien a disparu de la boutique. Le formulaire de la
    // console envoie tout, donc rien ne change pour lui.
    $has = fn(string $k) => !$id || array_key_exists($k, $in);
    if ($has('site_url'))   $cols['site_url']   = mb_substr($site, 0, 255);
    if ($has('note'))       $cols['note']       = mb_substr(trim((string) ($in['note'] ?? '')), 0, 255);
    if ($has('sort_order')) $cols['sort_order'] = (int) ($in['sort_order'] ?? 0);
    // La case à cocher absente d'un POST veut dire « décochée » : on ne peut
    // donc s'y fier que si le formulaire s'annonce complet.
    if (!$id || array_key_exists('active', $in) || array_key_exists('name', $in)) {
        $cols['active'] = empty($in['active']) ? 0 : 1;
    }

    if ($id) {
        // Le slug ne bouge pas : c'est une adresse publique.
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($cols)));
        $pdo->prepare("UPDATE wsm_brands SET $set WHERE id = ?")
            ->execute([...array_values($cols), $id]);
        $old = (string) ($cur['logo_url'] ?? '');
        if ($fresh !== null && $old !== '' && $old !== $fresh) wsm_media_delete($old);
        if (!empty($in['remove_logo']) && $old !== '') wsm_media_delete($old);
        return [wsm_brand($pdo, $id), []];
    }

    $cols['slug'] = wsm_brand_free_slug($pdo, (string) ($in['slug'] ?? '') ?: $name);
    $names = array_keys($cols);
    $pdo->prepare('INSERT INTO wsm_brands (' . implode(',', $names) . ') VALUES ('
                  . implode(',', array_fill(0, count($names), '?')) . ')')
        ->execute(array_values($cols));
    return [wsm_brand($pdo, (int) $pdo->lastInsertId()), []];
}

/**
 * Supprime une marque — seulement si aucun produit ne la porte. Sinon on
 * refuse et on dit combien : désactiver est le bon geste, parce qu'effacer
 * laisserait des fiches pointant dans le vide.
 *
 * @return array [succès, message]
 */
function wsm_brand_delete(PDO $pdo, int $id): array {
    $b = wsm_brand($pdo, $id);
    if (!$b) return [false, 'nie znaleziono marki'];
    $n = wsm_brand_counts($pdo)[$id] ?? 0;
    if ($n > 0) {
        return [false, 'marka jest przypisana do ' . $n . ' ' . ($n === 1 ? 'produktu' : 'produktów')
                     . ' — wyłącz ją zamiast usuwać'];
    }
    $pdo->prepare("DELETE FROM wsm_brands WHERE id = ?")->execute([$id]);
    if (($b['logo_url'] ?? '') !== '') wsm_media_delete((string) $b['logo_url']);
    return [true, 'usunięto markę ' . $b['name']];
}
