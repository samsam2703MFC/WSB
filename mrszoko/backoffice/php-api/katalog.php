<?php
declare(strict_types=1);
/**
 * katalog.php — LE CATALOGUE DE DÉPART D'UNE BOUTIQUE, depuis un fichier.
 *
 * Chaque boutique a sa base, et donc son catalogue. Celui de Szoko vient du
 * jeu de démonstration (seed.php) ; une boutique neuve part d'un fichier
 * qu'on relit, qu'on corrige et qu'on versionne : boutiques/<slug>/katalog.json.
 *
 *     php migrate.php --import-katalog ../../../boutiques/mycobot/katalog.json
 *
 * CE QU'IL POSE : les catégories et leurs sous-catégories, les modes de
 * livraison propres à la boutique (« bez wysyłki » pour les formations et
 * les licences), les produits, et leurs textes dans chaque langue.
 *
 * ON PEUT LE REJOUER. Tout s'aligne sur une clé stable : le NOM pour une
 * catégorie, l'IDENTIFIANT pour un produit et un mode de livraison, (langue,
 * clé) pour un texte. Rejouer le fichier met à jour, ne duplique rien.
 *
 * IL N'ÉCRASE PAS LE TRAVAIL DE LA CONSOLE SUR LA VITRINE. Une photo déposée
 * depuis la console (image_url) et un produit retiré de la vente à la main
 * restent ce qu'ils sont : le fichier pose un point de départ, la console
 * fait foi ensuite. Les prix, eux, suivent le fichier — c'est lui qu'on
 * corrige quand un tarif est revu avant l'ouverture.
 *
 * TOUT OU RIEN. Une transaction : un fichier à moitié importé laisserait des
 * produits rangés dans des catégories qui n'existent pas encore.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/commerce.php';   // wsm_categorie_assure()
require_once __DIR__ . '/shop.php';       // WSM_RODZAJE, wsm_slugify()
require_once __DIR__ . '/media.php';      // wsm_media_dir(), wsm_media_url()

/**
 * Une image du fichier de catalogue, DÉCODÉE puis RÉ-ENCODÉE en WebP dans
 * media/, sous un nom tiré au sort — la même règle que l'envoi depuis la
 * console (media.php) : ce qui atterrit dans media/ est une image que nous
 * avons fabriquée, pas un fichier recopié.
 *
 * @return string l'adresse « media/… », ou '' si ce n'est pas une image lisible
 */
function wsm_katalog_image(string $fichier): string {
    $info = @getimagesize($fichier);
    $lecteurs = wsm_media_readers();
    if (!$info || !isset($lecteurs[(int) $info[2]])) return '';
    $src = @$lecteurs[(int) $info[2]]($fichier);
    if (!$src) return '';
    // La transparence d'une icône PNG survit au passage en WebP.
    imagealphablending($src, false);
    imagesavealpha($src, true);
    $dir = wsm_media_dir();
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $nom = bin2hex(random_bytes(12)) . '.webp';
    $ok = @imagewebp($src, "$dir/$nom", 82);
    imagedestroy($src);
    return $ok ? wsm_media_url($nom) : '';
}

/**
 * Contrôle le fichier AVANT d'écrire quoi que ce soit.
 *
 * @return string[] les problèmes, en polonais (ils s'affichent tels quels)
 */
function wsm_katalog_check(array $k, string $base = ''): array {
    $e = [];
    $img = function (string $rel, string $ou) use ($base, &$e): void {
        if ($rel === '') return;
        $f = $base . '/' . $rel;
        if (str_contains($rel, '..') || !is_file($f) || !@getimagesize($f)) $e[] = "$ou: obraz „{$rel}” nie istnieje albo nie jest obrazem";
    };
    foreach ((array) ($k['slajdy'] ?? []) as $i => $sl) $img((string) ($sl['zdjecie'] ?? ''), "slajd #$i");
    foreach (array_values((array) ($k['ikony_obietnic'] ?? [])) as $i => $rel) $img((string) $rel, 'ikona obietnicy ' . ($i + 1));
    $noms = [];
    foreach ((array) ($k['kategorie'] ?? []) as $i => $c) {
        $n = trim((string) ($c['nazwa'] ?? ''));
        if ($n === '' || mb_strlen($n) > 80) $e[] = "kategoria #$i: nazwa 1–80 znaków";
        $noms[mb_strtolower($n)] = true;
        foreach ((array) ($c['pod'] ?? []) as $j => $p) {
            $pn = trim((string) ($p['nazwa'] ?? ''));
            if ($pn === '' || mb_strlen($pn) > 80) $e[] = "podkategoria $n #$j: nazwa 1–80 znaków";
            if (isset($noms[mb_strtolower($pn)])) $e[] = "kategoria „{$pn}” występuje dwa razy (nazwy są unikalne)";
            $noms[mb_strtolower($pn)] = true;
        }
    }
    foreach ((array) ($k['dostawa'] ?? []) as $i => $d) {
        if (!preg_match('/^[a-z0-9_]{2,40}$/', (string) ($d['id'] ?? ''))) $e[] = "dostawa #$i: id [a-z0-9_]";
        if (!in_array((string) ($d['kind'] ?? 'adres'), ['punkt', 'adres', 'brak'], true)) $e[] = "dostawa #$i: kind punkt | adres | brak";
    }
    $ids = [];
    foreach ((array) ($k['produkty'] ?? []) as $i => $p) {
        $id = (string) ($p['id'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,59}$/', $id)) $e[] = "produkt #$i: id [a-z0-9-]";
        if (isset($ids[$id])) $e[] = "produkt $id: id występuje dwa razy";
        $ids[$id] = true;
        if (!isset($noms[mb_strtolower(trim((string) ($p['kategoria'] ?? '')))])) $e[] = "produkt $id: nieznana kategoria „" . ($p['kategoria'] ?? '') . '”';
        if (!isset(WSM_RODZAJE[(string) ($p['rodzaj'] ?? 'towar')])) $e[] = "produkt $id: rodzaj " . implode(' | ', array_keys(WSM_RODZAJE));
        if (!is_numeric($p['cena'] ?? null) || (float) $p['cena'] <= 0) $e[] = "produkt $id: cena > 0";
        if (!in_array((float) ($p['vat'] ?? 0.23), [0.0, 0.05, 0.08, 0.23], true)) $e[] = "produkt $id: VAT 0 / 0.05 / 0.08 / 0.23";
        if (($p['rodzaj'] ?? 'towar') === 'towar' && (int) ($p['waga_g'] ?? 0) <= 0) $e[] = "produkt $id: towar musi mieć wagę (waga_g) — bez niej przewoźnik go nie wyceni";
        if (trim((string) ($p['teksty']['pl']['name'] ?? $p['nazwa'] ?? '')) === '') $e[] = "produkt $id: brak nazwy";
        $img((string) ($p['zdjecie'] ?? ''), "produkt $id");
    }
    return $e;
}

/** Un texte de la vitrine, posé ou remplacé. */
function wsm_katalog_texte(PDO $pdo, string $lang, string $k, string $v): void {
    $pdo->prepare("DELETE FROM wsm_shop_i18n WHERE lang = ? AND k = ?")->execute([$lang, $k]);
    $pdo->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES (?,?,?)")->execute([$lang, $k, $v]);
}

/**
 * Importe le catalogue. Refuse en bloc si le contrôle trouve quoi que ce soit.
 *
 * @return array{ok:bool, erreurs:string[], kategorie:int, produkty:int, dostawa:int, teksty:int}
 */
function wsm_katalog_import(PDO $pdo, array $k, string $base = ''): array {
    $bilan = ['ok' => false, 'erreurs' => wsm_katalog_check($k, $base), 'kategorie' => 0, 'produkty' => 0, 'dostawa' => 0,
              'teksty' => 0, 'zdjecia' => 0, 'slajdy' => 0];
    if ($bilan['erreurs']) return $bilan;

    $pdo->beginTransaction();
    try {
        // ---- Catégories, puis leurs sous-catégories ---------------------------
        $catId = [];
        $ordre = 0;
        $pose = function (string $nom, int $parent) use ($pdo, &$catId, &$ordre, &$bilan): void {
            [$id, ] = wsm_categorie_assure($pdo, $nom);
            if ($id <= 0) throw new RuntimeException("kategoria „{$nom}” nie dała się zapisać");
            $pdo->prepare("UPDATE wsm_categories SET parent_id = ?, sort_order = ?, active = 1 WHERE id = ?")
                ->execute([$parent, ++$ordre, $id]);
            $catId[mb_strtolower($nom)] = $id;
            $bilan['kategorie']++;
        };
        foreach ((array) ($k['kategorie'] ?? []) as $c) {
            $nom = trim((string) $c['nazwa']);
            $pose($nom, 0);
            foreach ((array) ($c['pod'] ?? []) as $p) $pose(trim((string) $p['nazwa']), $catId[mb_strtolower($nom)]);
        }

        // ---- Modes de livraison propres à la boutique ------------------------
        foreach ((array) ($k['dostawa'] ?? []) as $i => $d) {
            $id = (string) $d['id'];
            $vals = [(string) ($d['carrier'] ?? ''), (string) ($d['kind'] ?? 'adres'), (int) ($d['kolejnosc'] ?? (100 + $i)),
                     (int) round(((float) ($d['cena_netto'] ?? 0)) * 100), (float) ($d['vat'] ?? 0.23),
                     (string) ($d['kraje'] ?? '*'), (int) ($d['max_waga_g'] ?? 0)];
            $ex = $pdo->prepare("SELECT 1 FROM wsm_shipping_methods WHERE id = ?");
            $ex->execute([$id]);
            if ($ex->fetchColumn()) {
                $pdo->prepare("UPDATE wsm_shipping_methods SET carrier = ?, kind = ?, sort_order = ?, price_net = ?, vat_rate = ?,
                                      countries = ?, max_weight_g = ?, active = 1 WHERE id = ?")->execute([...$vals, $id]);
            } else {
                $pdo->prepare("INSERT INTO wsm_shipping_methods (carrier, kind, sort_order, price_net, vat_rate, countries, max_weight_g, active, id)
                               VALUES (?,?,?,?,?,?,?,1,?)")->execute([...$vals, $id]);
            }
            foreach ((array) ($d['teksty'] ?? []) as $lang => $t) {
                foreach (['label', 'note'] as $champ) {
                    if (isset($t[$champ])) { wsm_katalog_texte($pdo, (string) $lang, "ship.$id.$champ", (string) $t[$champ]); $bilan['teksty']++; }
                }
            }
            $bilan['dostawa']++;
        }

        // ---- Produits -----------------------------------------------------------
        $teinte = ['towar' => ['--brand-500', '--brand-800'], 'usluga' => ['--accent-600', '--brand-900'],
                   'subskrypcja' => ['--brand-700', '--accent-500']];
        foreach ((array) ($k['produkty'] ?? []) as $i => $p) {
            $id = (string) $p['id'];
            $rodzaj = (string) ($p['rodzaj'] ?? 'towar');
            $nom = trim((string) ($p['teksty']['pl']['name'] ?? $p['nazwa']));
            $slug = (string) ($p['slug'] ?? '') !== '' ? (string) $p['slug'] : wsm_slugify($nom);
            [$from, $to] = $teinte[$rodzaj];
            $commun = [
                'category_id' => $catId[mb_strtolower(trim((string) $p['kategoria']))],
                'nom' => $nom, 'slug' => $slug, 'rodzaj' => $rodzaj,
                'prix' => round((float) $p['cena'], 2), 'vat_rate' => (float) ($p['vat'] ?? 0.23),
                'weight_g' => $rodzaj === 'towar' ? (int) $p['waga_g'] : 0,
                'stock' => (int) ($p['stan'] ?? 0), 'sku' => (string) ($p['sku'] ?? ''),
                'unit_label' => (string) ($p['jednostka'] ?? ''), 'badge' => (string) ($p['badge'] ?? ''),
                'sort_order' => (int) ($p['kolejnosc'] ?? ($i + 1)),
            ];
            $ex = $pdo->prepare("SELECT 1 FROM wsm_products WHERE id = ?");
            $ex->execute([$id]);
            if ($ex->fetchColumn()) {
                $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($commun)));
                $pdo->prepare("UPDATE wsm_products SET $set WHERE id = ?")->execute([...array_values($commun), $id]);
            } else {
                $cols = array_merge(['id', 'statut', 'active', 'shop_visible', 'swatch_from', 'swatch_to'], array_keys($commun));
                $vals = array_merge([$id, 'Opublikowany', 1, 1, $from, $to], array_values($commun));
                $pdo->prepare("INSERT INTO wsm_products (" . implode(', ', $cols) . ") VALUES ("
                              . implode(',', array_fill(0, count($cols), '?')) . ")")->execute($vals);
            }
            // La photo : posée seulement si le produit n'en a pas — une photo
            // déposée depuis la console ne se fait pas écraser par un import.
            if (($p['zdjecie'] ?? '') !== '') {
                $act = $pdo->prepare("SELECT image_url FROM wsm_products WHERE id = ?");
                $act->execute([$id]);
                if ((string) $act->fetchColumn() === '') {
                    $url = wsm_katalog_image($base . '/' . $p['zdjecie']);
                    if ($url !== '') {
                        $pdo->prepare("UPDATE wsm_products SET image_url = ? WHERE id = ?")->execute([$url, $id]);
                        $bilan['zdjecia']++;
                    }
                }
            }
            foreach ((array) ($p['teksty'] ?? []) as $lang => $t) {
                foreach (['name', 'subtitle', 'desc'] as $champ) {
                    if (isset($t[$champ]) && trim((string) $t[$champ]) !== '') {
                        wsm_katalog_texte($pdo, (string) $lang, "product.$id.$champ", (string) $t[$champ]);
                        $bilan['teksty']++;
                    }
                }
            }
            $bilan['produkty']++;
        }
        // ---- Textes de la vitrine (nom, accroches, promesses) ------------------
        foreach ((array) ($k['teksty'] ?? []) as $lang => $t) {
            foreach ((array) $t as $cle => $v) { wsm_katalog_texte($pdo, (string) $lang, (string) $cle, (string) $v); $bilan['teksty']++; }
        }

        // ---- Vente au poids : les paliers de remise au kilo ---------------------
        // Une boutique de cobots ne vend pas au kilo : « rabaty_wagowe: false »
        // éteint les paliers (hérités d'un jeu de démonstration) — et avec eux
        // le prix au kilo et les cartes « 3,3 kg / rabat 5 % » de la fiche.
        if (array_key_exists('rabaty_wagowe', $k) && $k['rabaty_wagowe'] === false) {
            $pdo->exec("UPDATE wsm_discount_tiers SET active = 0");
            wsm_sprzedaz_na_wage(true);
        }

        // ---- Les icônes des trois promesses (comme Integracje → Sklep) ---------
        // Posées seulement là où la console n'en a pas déjà déposé une.
        foreach (array_values((array) ($k['ikony_obietnic'] ?? [])) as $i => $rel) {
            $cle = 'promise_icon_' . ($i + 1);
            $act = $pdo->prepare("SELECT val FROM wsm_settings WHERE cle = ?");
            $act->execute([$cle]);
            if (trim((string) $act->fetchColumn()) !== '') continue;
            $url = wsm_katalog_image($base . '/' . $rel);
            if ($url === '') continue;
            $pdo->prepare("DELETE FROM wsm_settings WHERE cle = ?")->execute([$cle]);
            $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,0,?,'katalog')")
                ->execute([$cle, $url, date('Y-m-d H:i:s')]);
            $bilan['zdjecia']++;
        }

        // ---- Le haut de page ---------------------------------------------------
        // Les slajdy du fichier REMPLACENT ceux de démonstration : les anciens
        // sont éteints (pas effacés) ; ceux d'un import précédent sont retirés,
        // pour qu'un second import ne les empile pas.
        if (!empty($k['slajdy'])) {
            $vieux = $pdo->query("SELECT id, image_url FROM wsm_slides WHERE updated_by = 'katalog'")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            foreach ($vieux as $v => $img) {
                if ($img !== '') wsm_media_delete((string) $img);   // pas d'image orpheline dans media/
                $pdo->prepare("DELETE FROM wsm_slide_i18n WHERE slide_id = ?")->execute([$v]);
                $pdo->prepare("DELETE FROM wsm_slides WHERE id = ?")->execute([$v]);
            }
            $pdo->exec("UPDATE wsm_slides SET active = 0");
            $now = date('Y-m-d H:i:s');
            foreach ((array) $k['slajdy'] as $i => $sl) {
                $url = ($sl['zdjecie'] ?? '') !== '' ? wsm_katalog_image($base . '/' . $sl['zdjecie']) : '';
                $pdo->prepare("INSERT INTO wsm_slides (sort_order, active, image_url, link, created_at, updated_at, updated_by)
                               VALUES (?, 1, ?, ?, ?, ?, 'katalog')")
                    ->execute([10 * ($i + 1), $url, (string) ($sl['link'] ?? ''), $now, $now]);
                $sid = (int) $pdo->lastInsertId();
                foreach ((array) ($sl['teksty'] ?? []) as $lang => $t) {
                    $pdo->prepare("INSERT INTO wsm_slide_i18n (slide_id, lang, eyebrow, title, tekst, cta_label) VALUES (?,?,?,?,?,?)")
                        ->execute([$sid, (string) $lang, (string) ($t['eyebrow'] ?? ''), (string) ($t['title'] ?? ''),
                                   (string) ($t['tekst'] ?? ''), (string) ($t['cta_label'] ?? '')]);
                }
                $bilan['slajdy']++;
            }
        }

        $pdo->commit();
        $bilan['ok'] = true;
        wsm_sprzedaz_na_wage(true);
    } catch (Throwable $t) {
        $pdo->rollBack();
        $bilan['erreurs'][] = $t->getMessage();
    }
    return $bilan;
}
