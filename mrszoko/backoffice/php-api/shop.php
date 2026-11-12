<?php
// ============================================================================
//  shop.php — moteur de la boutique en ligne Mister Szoko.
//
//  RÈGLE CENTRALE : le navigateur n'envoie que des identifiants de produit et
//  des quantités. Tous les prix, la TVA, les frais de port et le total sont
//  relus et recalculés ici, dans la base. Un panier trafiqué côté client ne
//  change donc rien au montant réellement facturé.
//
//  Les montants circulent en GROSZE (entiers). Les flottants n'ont pas leur
//  place sur de l'argent : 0.1 + 0.2 ne fait pas 0.3 en binaire, et une TVA
//  fausse d'un grosz est une facture fausse.
//
//  Convention de prix : wsm_products.prix est le prix TTC affiché au client
//  (usage B2C polonais). Le HT est déduit du TTC, et la TVA est le RESTE de
//  la soustraction — la somme des lignes retombe donc toujours juste.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/commerce.php';
require_once __DIR__ . '/vies.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/stock.php';

const WSM_SHOP_LANGS       = ['pl', 'uk', 'en'];
const WSM_SHOP_DEFAULT_LANG = 'pl';
const WSM_SHOP_MAX_QTY     = 99;              // garde-fou : pas de panier à 10 000 unités
const WSM_SHOP_HOME_COUNTRY = 'PL';   // marché intérieur : jamais d'autoliquidation
const WSM_ORDER_STATUSES   = ['nowe', 'oplacone', 'w_realizacji', 'wyslane', 'dostarczone', 'anulowane'];
// Les taux de TVA polonais. Ce n'est pas un réglage de confort : une stawka
// inventée passerait sur la facture et se réglerait à l'inspection. On
// n'accepte donc que les taux légaux — 23 % (standard), 8 %, 5 % (denrées),
// 0 %. Le taux vit sur le produit : un chocolat et un livre de recettes ne
// sont pas taxés pareil.
const WSM_VAT_RATES = [0.23, 0.08, 0.05, 0.0];

/** Origine de la requête en cours (schéma + hôte), proxy TLS compris. */
function wsm_request_origin(): string {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    return ($https ? 'https://' : 'http://') . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/**
 * URL publique de l'API — c'est elle qui compose l'adresse de notification
 * envoyée à tpay à CHAQUE transaction.
 *
 * ELLE ÉTAIT FAUSSE LÀ OÙ ELLE COMPTE. L'ancienne version coupait le chemin au
 * premier « /shop/ » et s'arrêtait là. Depuis l'API — /mrszoko/backoffice/api/
 * shop/catalog — elle tombait juste par accident. Mais la CAISSE, elle, vit
 * sous /mrszoko/shop/kasa : la coupure rendait « /mrszoko », et l'adresse
 * envoyée à tpay devenait /mrszoko/shop/tpay/notify — la boutique, qui ne
 * connaît pas cette route.
 *
 * CE QUE ÇA AURAIT COÛTÉ, et rien ne l'aurait signalé : le client paie, tpay
 * appelle cette adresse, reçoit une page 404, et réessaie. La commande reste
 * « oczekuje na płatność » sur de l'argent déjà encaissé. Aucune erreur dans
 * la console, aucune dans la boutique — juste un client qui a payé et une
 * commande que personne n'expédie.
 *
 * Jamais vu parce que tpay n'a jamais été branché. C'est le premier vrai
 * paiement qui l'aurait révélé.
 *
 * On repart donc de wsm_shop_base_url(), qui sait déjà retrouver la racine du
 * déploiement depuis N'IMPORTE QUELLE surface — et qui respecte l'adresse
 * publique configurée en console.
 */
function wsm_api_base_url(): string {
    $base = wsm_shop_base_url();
    // wsm_shop_base_url() rend « …/shop ». L'adresse publique saisie en console
    // peut ne pas le porter : on retire le suffixe s'il est là, jamais plus.
    $racine = preg_replace('#/shop/?$#', '', $base) ?? $base;
    return rtrim($racine, '/') . '/backoffice/api';
}

/**
 * URL publique de la boutique.
 *
 * ELLE DOIT ÊTRE JUSTE DEPUIS N'IMPORTE QUELLE SURFACE. La version
 * précédente ne réécrivait que « /backoffice/api » : appelée depuis un écran
 * de la console — /backoffice/zgloszenia.php — elle rendait l'adresse de
 * L'ÉCRAN LUI-MÊME. Le lien affiché à copier-coller dans une infolettre
 * envoyait donc les clients sur une page de connexion. Trouvé en regardant
 * l'écran, pas le code.
 *
 * La règle générale : la racine du déploiement est ce qui PRÉCÈDE
 * « /backoffice » ou « /shop ». On la retrouve, et on rajoute « /shop ».
 */
function wsm_shop_base_url(): string {
    $fixed = (string) (wsm_config()['shop_url'] ?? '');
    if ($fixed !== '') return rtrim($fixed, '/');

    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/';
    foreach (['/backoffice', '/shop', '/landing'] as $surface) {
        $cut = strpos($path, $surface . '/');
        if ($cut !== false) { $path = substr($path, 0, $cut); break; }
        if (str_ends_with(rtrim($path, '/'), $surface)) {
            $path = substr(rtrim($path, '/'), 0, -strlen($surface));
            break;
        }
    }
    // LA RACINE D'UN DÉPLOIEMENT N'EST JAMAIS UN FICHIER .php. Quand aucune
    // surface connue n'apparaît dans le chemin — console servie à la racine
    // d'un domaine, serveur de développement — le nom du script restait collé
    // à l'adresse : « /ustawienia.php/shop ». Une adresse qu'on affiche pour
    // être recopiée dans le panneau d'un opérateur de paiement n'a pas le
    // droit d'être approximative.
    $path = preg_replace('#/[^/]+\.php$#', '', rtrim($path, '/')) ?? $path;
    return wsm_request_origin() . rtrim($path, '/') . '/shop';
}

/** Langue demandée, ramenée à une langue réellement disponible. */
function wsm_shop_lang(PDO $pdo, ?string $want): string {
    $have = wsm_shop_available_langs($pdo);
    $want = strtolower(trim((string) $want));
    if ($want !== '' && in_array($want, $have, true)) return $want;
    return in_array(WSM_SHOP_DEFAULT_LANG, $have, true) ? WSM_SHOP_DEFAULT_LANG : ($have[0] ?? WSM_SHOP_DEFAULT_LANG);
}

/**
 * Les langues réellement proposées au visiteur.
 *
 * ELLES VIENNENT D'UNE DÉCISION, PAS D'UN EFFET DE BORD. Auparavant cette
 * fonction faisait « SELECT DISTINCT lang » : traduire une seule clé en
 * allemand suffisait à faire apparaître un drapeau DE menant à une boutique
 * polonaise à 99 %. Un visiteur qui clique dessus ne revient pas. La liste
 * publique est donc celle de wsm_langs, cochée à la main dans la console.
 *
 * Repli : si la table des langues manque — base neuve, migration en cours —
 * on retombe sur l'ancien comportement plutôt que de servir une boutique
 * monolingue par accident.
 */
function wsm_shop_available_langs(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $i18n = __DIR__ . '/i18n.php';
    if (is_file($i18n)) {
        require_once $i18n;
        try {
            if (wsm_table_exists($pdo, 'wsm_langs')) {
                $cache = wsm_lang_published($pdo);
                return $cache;
            }
        } catch (Throwable $e) { /* on retombe plus bas */ }
    }

    try {
        $rows = $pdo->query("SELECT DISTINCT lang FROM wsm_shop_i18n ORDER BY lang")->fetchAll();
        $cache = array_values(array_map(fn($r) => (string) $r['lang'], $rows));
    } catch (Throwable $e) { $cache = []; }
    if (!$cache) $cache = [WSM_SHOP_DEFAULT_LANG];
    return $cache;
}

/** Tous les libellés d'une langue. Aucun texte de la boutique n'est en dur. */
function wsm_shop_strings(PDO $pdo, string $lang): array {
    $st = $pdo->prepare("SELECT k, v FROM wsm_shop_i18n WHERE lang = ?");
    $st->execute([$lang]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        // Une valeur VIDE vaut « pas traduit ». La console permet d'effacer une
        // traduction, et c'est même la façon normale de dire « à refaire » :
        // il ne faut donc pas qu'un champ vidé blanchisse le libellé sur la
        // page. Absent et vide sont traités pareil.
        if (trim((string) $r['v']) === '') continue;
        $out[(string) $r['k']] = (string) $r['v'];
    }
    // Repli sur la langue par défaut pour toute clé pas encore traduite :
    // une chaîne manquante doit donner du polonais, pas un trou dans la page.
    if ($lang !== WSM_SHOP_DEFAULT_LANG) {
        $st = $pdo->prepare("SELECT k, v FROM wsm_shop_i18n WHERE lang = ?");
        $st->execute([WSM_SHOP_DEFAULT_LANG]);
        foreach ($st->fetchAll() as $r) {
            if (!isset($out[(string) $r['k']])) $out[(string) $r['k']] = (string) $r['v'];
        }
    }
    return $out;
}

/** Convertit un prix décimal de la base en grosze. */
function wsm_grosze($decimal): int { return (int) round(((float) $decimal) * 100); }

/**
 * Décompose un montant TTC. Le HT est arrondi, la TVA est la différence —
 * ainsi net + tva == brut, toujours, sans dérive d'arrondi.
 */
function wsm_split_vat(int $gross, float $vatRate): array {
    $net = (int) round($gross / (1 + $vatRate));
    return [$net, $gross - $net];
}

/** Un produit de la boutique, textes résolus dans la langue demandée. */
/**
 * Le prix au kilo, en groszy — ou null quand on ne peut pas le dire.
 *
 * POURQUOI IL COMPTE ICI. La boutique vend la même chocolat en 1 kg et en
 * 3 kg : 64,90 et 169,90 ne se comparent pas de tête, et c'est justement le
 * moment où l'on décide. Le prix au kilo répond à la question sans calculette.
 *
 * Calculé sur le BRUT, celui que le client paie — comparer des nets quand
 * l'étiquette affiche des bruts ferait deux chiffres qui ne se recoupent pas.
 *
 * Un poids à zéro rend null, jamais zéro : « 0,00 zł/kg » sur une carte se
 * lit comme une gratuité, et un produit dont on ignore le poids doit se taire
 * plutôt que mentir.
 */
function wsm_price_per_kg(int $brutGrosze, int $poidsG): ?int {
    if ($poidsG <= 0 || $brutGrosze <= 0) return null;
    return (int) round($brutGrosze * 1000 / $poidsG);
}

/**
 * LA BOUTIQUE VEND-ELLE AU POIDS ? Oui quand elle a des paliers de remise au
 * poids (Rabaty) : c'est sa politique commerciale (Szoko : du chocolat au
 * kilo). Alors le prix au kilo a un sens sur la fiche. Pour un chwytak ou une
 * licence, « 8 090 zł/kg » n'en a aucun — sans paliers, il ne s'affiche pas.
 */
function wsm_sprzedaz_na_wage(bool $fresh = false): bool {
    static $oui = null;
    if ($oui === null || $fresh) {
        try { $oui = (bool) wsm_discount_tiers(wsm_pdo()); } catch (Throwable $e) { $oui = true; }
    }
    return $oui;
}

/** Les types de produit, et ce qu'ils impliquent. Seul un 'towar' s'expédie. */
const WSM_RODZAJE = ['towar' => 'Towar (wysyłka)', 'usluga' => 'Usługa / szkolenie', 'subskrypcja' => 'Oprogramowanie / licencja'];

/** Un type de produit valide ; tout ce qui n'est pas connu est un objet à expédier. */
function wsm_rodzaj(string $r): string {
    return isset(WSM_RODZAJE[$r]) ? $r : 'towar';
}

function wsm_shop_row_to_product(array $r, array $S): array {
    $id    = (string) $r['id'];
    $gross = wsm_grosze($r['prix']);
    $vat   = (float) $r['vat_rate'];
    [$net, $vatAmt] = wsm_split_vat($gross, $vat);
    return [
        'id'        => $id,
        'slug'      => ((string) ($r['slug'] ?? '')) !== '' ? (string) $r['slug'] : $id,
        'name'      => $S['product.' . $id . '.name']     ?? (string) $r['nom'],
        'subtitle'  => $S['product.' . $id . '.subtitle'] ?? '',
        'desc'      => $S['product.' . $id . '.desc']     ?? '',
        'origin'    => (string) ($r['origin'] ?? ''),
        'cocoa'     => (string) ($r['cocoa'] ?? ''),
        'unit'      => (string) ($r['unit_label'] ?? ''),
        'badge'     => ((string) ($r['badge'] ?? '')) !== '' ? ($S['badge.' . $r['badge']] ?? (string) $r['badge']) : '',
        'image'     => (string) ($r['image_url'] ?? ''),
        'from'      => (string) ($r['swatch_from'] ?? '--brand-500'),
        'to'        => (string) ($r['swatch_to'] ?? '--brand-800'),
        'price'     => $gross,
        'price_net' => $net,
        'price_vat' => $vatAmt,
        'vat_rate'  => $vat,
        'stock'     => (int) ($r['stock'] ?? 0),
        // 'towar' (un colis), 'usluga' (formation, prestation) ou 'subskrypcja'
        // (logiciel, licence) — voir wsm_rodzaj().
        'rodzaj'    => wsm_rodzaj((string) ($r['rodzaj'] ?? 'towar')),
        // La marque telle que la vitrine l'affiche, apportée par la jointure du
        // catalogue : nom, logo, adresse. null quand le produit n'en porte pas,
        // ou quand elle est désactivée — la boutique n'affiche alors rien,
        // plutôt qu'un cadre vide en attente d'image.
        'brand'     => (($r['brand_name'] ?? '') !== '' && (int) ($r['brand_active'] ?? 0) === 1) ? [
            'name' => (string) $r['brand_name'],
            'slug' => (string) ($r['brand_slug'] ?? ''),
            'logo' => (string) ($r['brand_logo'] ?? ''),
            'site' => (string) ($r['brand_site'] ?? ''),
        ] : null,
        'weight_g'  => (int) ($r['weight_g'] ?? 0),
        'price_per_kg' => wsm_sprzedaz_na_wage() ? wsm_price_per_kg($gross, (int) ($r['weight_g'] ?? 0)) : null,
        'sku'       => (string) ($r['sku'] ?? ''),
        'ean'       => (string) ($r['ean'] ?? ''),
        'category'  => (string) ($r['category_name'] ?? ''),
        'category_id' => (int) ($r['category_id'] ?? 0),
        'nutrition' => wsm_nutrition_decode((string) ($r['nutrition'] ?? '')),
    ];
}

// ─── LES VALEURS NUTRITIVES ─────────────────────────────────────────────────
//
// L'équipe les tapait dans la description, au milieu du reste : « wszystko
// zlane w 1 tekst ». Ce sont des chiffres, rangés dans l'ordre que fixe le
// règlement européen (UE 1169/2011, annexe XV) — énergie, graisses dont
// saturées, glucides dont sucres, fibres, protéines, sel — pour 100 g. On
// stocke ce qui est tapé (« 32,5 », « <0,5 »), sans unité : l'unité est celle
// du règlement, ajoutée à l'affichage, et elle ne peut donc pas être fausse.
const WSM_NUTRITION_FIELDS = [
    'energy_kj'   => 'kJ',
    'energy_kcal' => 'kcal',
    'fat'         => 'g',
    'saturates'   => 'g',
    'carbs'       => 'g',
    'sugars'      => 'g',
    'fibre'       => 'g',
    'protein'     => 'g',
    'salt'        => 'g',
];

/** Le nombre de lignes « właściwości » qu'une fiche peut porter. */
const WSM_PRODUCT_PROPS_MAX = 12;

/**
 * Nettoie les valeurs saisies : un nombre (virgule ou point), précédé au plus
 * d'un « < » (« <0,5 g » est la façon légale d'écrire une trace). Vide = absent.
 * Un refus porte le nom du champ dans $errs.
 */
function wsm_nutrition_clean(array $in, array &$errs = []): array {
    $out = [];
    foreach (array_keys(WSM_NUTRITION_FIELDS) as $k) {
        $v = trim(str_replace(' ', '', (string) ($in[$k] ?? '')));
        if ($v === '') continue;
        if (!preg_match('/^<?\d{1,5}([.,]\d{1,3})?$/', $v)) { $errs[$k] = $v; continue; }
        $out[$k] = str_replace('.', ',', $v);
    }
    return $out;
}

function wsm_nutrition_decode(string $json): array {
    $j = $json !== '' ? json_decode($json, true) : null;
    if (!is_array($j)) return [];
    $e = [];
    return wsm_nutrition_clean($j, $e);
}

function wsm_nutrition_encode(array $n): string {
    return $n ? (string) json_encode($n, JSON_UNESCAPED_UNICODE) : '';
}

/**
 * Les lignes du tableau tel que la fiche l'affiche : [libellé, valeur, sous-ligne].
 * L'énergie tient sur une ligne, « 2475 kJ / 598 kcal ».
 */
function wsm_nutrition_rows(array $n, array $S): array {
    if (!$n) return [];
    $rows = [];
    $en = array_values(array_filter([
        isset($n['energy_kj']) ? $n['energy_kj'] . ' kJ' : '',
        isset($n['energy_kcal']) ? $n['energy_kcal'] . ' kcal' : '',
    ]));
    if ($en) $rows[] = [$S['nutri.energy'] ?? 'Wartość energetyczna', implode(' / ', $en), false];
    foreach (['fat' => false, 'saturates' => true, 'carbs' => false, 'sugars' => true, 'fibre' => false, 'protein' => false, 'salt' => false] as $k => $sous) {
        if (!isset($n[$k])) continue;
        $rows[] = [$S['nutri.' . $k] ?? $k, $n[$k] . ' g', $sous];
    }
    return $rows;
}

/**
 * Les « właściwości » d'un produit : des paires libellé/valeur écrites dans la
 * console (Opakowanie, Skład, Przechowywanie…). Ce sont des TEXTES traduits,
 * comme le nom et la description : product.<id>.prop.<n>.k / .v dans
 * wsm_shop_i18n, avec le même repli sur le polonais, et Treści pour les
 * traduire. Une ligne n'est montrée que si elle a les deux moitiés.
 */
function wsm_product_props(array $S, string $id): array {
    $out = [];
    for ($i = 1; $i <= WSM_PRODUCT_PROPS_MAX; $i++) {
        $k = trim((string) ($S["product.$id.prop.$i.k"] ?? ''));
        $v = trim((string) ($S["product.$id.prop.$i.v"] ?? ''));
        if ($k !== '' && $v !== '') $out[] = [$k, $v];
    }
    return $out;
}

/**
 * Les rayons de la boutique — LUS EN BASE, et seulement ceux qui tiennent
 * quelque chose.
 *
 * La catégorie est obligatoire sur chaque produit depuis toujours, mais la
 * vitrine l'ignorait complètement : pas de filtre, pas de menu, tout dans un
 * seul catalogue à plat. On rangeait donc les produits dans des tiroirs que
 * personne n'ouvrait jamais.
 *
 * Une catégorie VIDE n'apparaît pas : un rayon qui mène à « aucun produit »
 * est une impasse, et il s'en crée mécaniquement dès qu'on retire le dernier
 * article d'une gamme.
 *
 * @return list<array{id:int,name:string,ile:int}>
 */
function wsm_shop_categories(PDO $pdo): array {
    // DEUX NIVEAUX. Un rayon parent compte ses produits ET ceux de ses
    // sous-rayons : « Akcesoria (12) » doit dire ce qu'on trouvera en cliquant.
    // Un rayon vide — lui et ses enfants — ne s'affiche pas.
    // Une base qui n'a pas encore reçu la colonne parent_id (le premier
    // démarrage la pose) garde ses rayons, sur un seul niveau.
    $sql = "SELECT c.id, c.name, %s AS parent_id, c.sort_order,
                   (SELECT COUNT(*) FROM wsm_products p
                     WHERE p.category_id = c.id AND p.shop_visible = 1 AND p.active = 1) AS wlasne
              FROM wsm_categories c
             WHERE c.active = 1
             ORDER BY c.sort_order, c.name";
    try { $rows = $pdo->query(sprintf($sql, 'c.parent_id'))->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { $rows = $pdo->query(sprintf($sql, '0'))->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $out = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $parent = (int) ($r['parent_id'] ?? 0);
        // Un parent éteint ou inexistant : le sous-rayon remonte au premier
        // niveau plutôt que de disparaître avec ses produits.
        if ($parent === $id || !in_array($parent, $ids, true)) $parent = 0;
        $ile = (int) $r['wlasne'];
        foreach ($rows as $k) if ((int) ($k['parent_id'] ?? 0) === $id && (int) $k['id'] !== $id) $ile += (int) $k['wlasne'];
        if ($ile > 0) $out[] = ['id' => $id, 'name' => (string) $r['name'], 'parent_id' => $parent, 'ile' => $ile];
    }
    return $out;
}

/**
 * Le catalogue visible en boutique, dans l'ordre voulu par le back-office.
 *
 * @param int|null $cat filtre par catégorie. Une catégorie inconnue ou vide
 *                      ne renvoie RIEN plutôt que tout : afficher le catalogue
 *                      complet sous un titre de rayon ferait acheter à côté.
 */
function wsm_shop_products(PDO $pdo, string $lang, ?int $cat = null): array {
    $S = wsm_shop_strings($pdo, $lang);
    $sql =
        "SELECT p.*, c.name AS category_name,
                b.name AS brand_name, b.slug AS brand_slug, b.logo_url AS brand_logo,
                b.site_url AS brand_site, b.active AS brand_active
           FROM wsm_products p
           LEFT JOIN wsm_categories c ON c.id = p.category_id
           LEFT JOIN wsm_brands b ON b.id = p.brand_id
          WHERE p.shop_visible = 1 AND p.active = 1"
        // Un rayon parent montre aussi ses sous-rayons.
        . ($cat !== null ? " AND (p.category_id = ? OR p.category_id IN
                               (SELECT id FROM wsm_categories WHERE parent_id = ? AND active = 1))" : '')
        . " ORDER BY p.sort_order, p.nom";
    if ($cat !== null) {
        try { $st = $pdo->prepare($sql); $st->execute([$cat, $cat]); }
        catch (Throwable $e) {   // pas encore de parent_id : un seul niveau
            $st = $pdo->prepare(str_replace(
                " OR p.category_id IN
                               (SELECT id FROM wsm_categories WHERE parent_id = ? AND active = 1)", '', $sql));
            $st->execute([$cat]);
        }
    }
    else               { $st = $pdo->query($sql); }
    return array_map(fn($r) => wsm_shop_row_to_product($r, $S), $st->fetchAll());
}

/** Un produit par slug ou par identifiant. */
function wsm_shop_product(PDO $pdo, string $key, string $lang): ?array {
    $S = wsm_shop_strings($pdo, $lang);
    $st = $pdo->prepare(
        "SELECT p.*, c.name AS category_name,
                b.name AS brand_name, b.slug AS brand_slug, b.logo_url AS brand_logo,
                b.site_url AS brand_site, b.active AS brand_active
           FROM wsm_products p
           LEFT JOIN wsm_categories c ON c.id = p.category_id
           LEFT JOIN wsm_brands b ON b.id = p.brand_id
          WHERE p.shop_visible = 1 AND p.active = 1 AND (p.slug = ? OR p.id = ?)
          LIMIT 1"
    );
    $st->execute([$key, $key]);
    $r = $st->fetch();
    return $r ? wsm_shop_row_to_product($r, $S) : null;
}

/** Paliers de remise au poids, du plus élevé au plus faible. */
function wsm_discount_tiers(PDO $pdo): array {
    try {
        return $pdo->query("SELECT id, min_weight_g, percent, label FROM wsm_discount_tiers
                             WHERE active = 1 ORDER BY min_weight_g DESC")->fetchAll();
    } catch (Throwable $e) { return []; }
}

/**
 * Remise applicable à un poids. Le premier palier atteint en partant du plus
 * haut gagne — deux paliers ne se cumulent jamais.
 *
 * @return array [pourcentage, palier|null]
 */
function wsm_discount_for_weight(PDO $pdo, int $weightG): array {
    foreach (wsm_discount_tiers($pdo) as $t) {
        if ($weightG >= (int) $t['min_weight_g']) return [(float) $t['percent'], $t];
    }
    return [0.0, null];
}

/** Le palier suivant, pour dire à l'acheteur ce qu'il gagnerait à en ajouter. */
function wsm_discount_next(PDO $pdo, int $weightG): ?array {
    $next = null;
    foreach (wsm_discount_tiers($pdo) as $t) {
        if ($weightG < (int) $t['min_weight_g']) $next = $t;   // tri décroissant : le dernier vu est le plus proche
    }
    return $next ? ['min_weight_g' => (int) $next['min_weight_g'], 'percent' => (float) $next['percent'],
                    'missing_g' => (int) $next['min_weight_g'] - $weightG, 'label' => (string) $next['label']] : null;
}

/** Pays ouverts à la commande, nommés dans la langue du visiteur. */
function wsm_shop_countries(PDO $pdo, string $lang): array {
    $col = in_array($lang, ['uk', 'en'], true) ? 'name_' . $lang : 'name_pl';
    try {
        $rows = $pdo->query("SELECT code, is_eu, name_pl, $col AS label
                               FROM wsm_countries WHERE active = 1
                              ORDER BY sort_order, name_pl")->fetchAll();
    } catch (Throwable $e) { return []; }
    return array_map(fn($r) => [
        'code'  => (string) $r['code'],
        'label' => ((string) $r['label']) !== '' ? (string) $r['label'] : (string) $r['name_pl'],
        'is_eu' => (int) $r['is_eu'] === 1,
    ], $rows);
}

function wsm_shop_country(PDO $pdo, string $code): ?array {
    $st = $pdo->prepare("SELECT code, is_eu, active FROM wsm_countries WHERE code = ?");
    $st->execute([strtoupper($code)]);
    $r = $st->fetch();
    return $r ? ['code' => (string) $r['code'], 'is_eu' => (int) $r['is_eu'] === 1,
                 'active' => (int) $r['active'] === 1] : null;
}

/**
 * Autoliquidation : 0 % de TVA. Trois conditions, toutes nécessaires.
 *   · la livraison part vers un AUTRE État membre (la Pologne est le marché
 *     intérieur — jamais de 0 %) ;
 *   · l'acheteur a donné un numéro de TVA que VIES a confirmé VALIDE — un
 *     « service indisponible » ne suffit pas à exonérer ;
 *   · le pays est ouvert à la commande.
 *
 * Un particulier d'un autre État membre paie donc la TVA polonaise. C'est
 * correct sous le seuil OSS de 10 000 € de ventes à distance dans l'UE ;
 * au-delà, il faudrait facturer le taux du pays de destination. Le jour où
 * ce seuil approche, c'est ici qu'il faudra revenir.
 */
function wsm_shop_reverse_charge(PDO $pdo, string $country, array $vies): bool {
    $country = strtoupper(trim($country));
    if ($country === '' || $country === WSM_SHOP_HOME_COUNTRY) return false;
    if (($vies['status'] ?? '') !== 'valid') return false;
    $c = wsm_shop_country($pdo, $country);
    return $c !== null && $c['active'] && $c['is_eu'];
}

/**
 * LES CODES PAYS D'UN TRANSPORTEUR, VÉRIFIÉS CONTRE LA TABLE.
 *
 * « Deux lettres majuscules » n'est pas une vérification : elle laisse passer
 * XX, ZZ, et surtout la faute de frappe plausible — PK au lieu de PL. Le
 * transporteur se met alors à desservir un pays qui n'existe pas, sans erreur,
 * sans message, et avec une ligne qui n'a pas l'air fausse. On ne garde que ce
 * que wsm_countries connaît, et on rend ce qu'on a jeté pour que l'écran le
 * dise au lieu de l'avaler.
 *
 * Le troisième tas — « connu mais fermé à la vente » — n'est pas une faute :
 * on peut préparer la portée d'un transporteur avant d'ouvrir le pays. Mais
 * c'est une commande impossible tant que les deux ne concordent pas, et ça se
 * découvre d'ordinaire par un client qui n'arrive pas à payer.
 *
 * @return array{codes:string[], inconnus:string[], fermes:string[]}
 */
function wsm_ship_codes(PDO $pdo, string $saisie): array {
    $connus = [];
    try {
        foreach ($pdo->query("SELECT code, active FROM wsm_countries")->fetchAll() ?: [] as $c) {
            $connus[strtoupper((string) $c['code'])] = (int) $c['active'] === 1;
        }
    } catch (Throwable $e) { $connus = []; }

    $codes = []; $inconnus = []; $fermes = [];
    foreach (preg_split('/[,\s]+/', $saisie) ?: [] as $c) {
        $c = strtoupper(trim($c));
        if ($c === '') continue;
        // Table vide ou illisible : on retombe sur le contrôle de forme plutôt
        // que de tout refuser — sinon une base incomplète effacerait la portée
        // de tous les transporteurs d'un seul enregistrement.
        if (!$connus) {
            if (preg_match('/^[A-Z]{2}$/', $c)) $codes[$c] = true;
            continue;
        }
        if (!isset($connus[$c])) { $inconnus[$c] = true; continue; }
        if (!$connus[$c]) $fermes[$c] = true;
        $codes[$c] = true;
    }
    return ['codes' => array_keys($codes), 'inconnus' => array_keys($inconnus),
            'fermes' => array_keys($fermes)];
}

/**
 * Modes de livraison actifs, tarifs compris — pilotés par la base.
 *
 * @param int $poids  poids du panier en grammes, ou -1 pour ne pas filtrer.
 *                    Passer le poids RETIRE les modes qui ne peuvent pas le
 *                    prendre — voir la note sur les deux bornes plus bas.
 */
function wsm_shipping_methods(PDO $pdo, string $lang, string $country = '', int $poids = -1): array {
    $S = wsm_shop_strings($pdo, $lang);
    $rows = $pdo->query("SELECT * FROM wsm_shipping_methods WHERE active = 1 ORDER BY sort_order")->fetchAll();
    // Un transporteur ne dessert que les pays qu'on lui a déclarés : proposer
    // un Paczkomat pour une livraison en Allemagne serait promettre à faux.
    if ($country !== '') {
        $country = strtoupper($country);
        $rows = array_values(array_filter($rows, function ($r) use ($country) {
            $list = trim((string) ($r['countries'] ?? ''));
            if ($list === '' || $list === '*') return true;
            return in_array($country, array_map('trim', explode(',', strtoupper($list))), true);
        }));
    }
    // LES DEUX BORNES DE POIDS FILTRENT LA LISTE, elles ne se contentent pas de
    // refuser après coup.
    //
    // Avant, un panier de 30 kg voyait quand même « Paczkomat » (25 kg max) :
    // le client le choisissait, et la caisse refusait — sans lui dire que DPD,
    // juste au-dessus, l'aurait pris. Un aller-retour au moment le plus fragile
    // du parcours, sur une commande qui était pourtant livrable.
    //
    // Et depuis qu'un transporteur de palettes existe, l'inverse compte autant :
    // proposer « Fresh Logistic — à partir de 200 kg » pour une tablette de
    // chocolat n'est pas une option, c'est un piège.
    if ($poids >= 0) {
        $rows = array_values(array_filter($rows, function ($r) use ($poids) {
            $min = (int) ($r['min_weight_g'] ?? 0);
            $max = (int) ($r['max_weight_g'] ?? 0);
            if ($min > 0 && $poids < $min) return false;
            if ($max > 0 && $poids > $max) return false;
            return true;
        }));
    }

    return array_map(function ($r) use ($S) {
        $net   = (int) $r['price_net'];
        $vat   = (float) $r['vat_rate'];
        $gross = $net + (int) round($net * $vat);
        return [
            'id'        => (string) $r['id'],
            'carrier'   => (string) $r['carrier'],
            'kind'      => wsm_ship_kind_row($r),
            'label'     => $S['ship.' . $r['id'] . '.label'] ?? (string) $r['id'],
            'note'      => $S['ship.' . $r['id'] . '.note'] ?? '',
            'price_net' => $net,
            'vat_rate'  => $vat,
            'price'     => $gross,
            'free_from' => (int) $r['free_from'],
            'min_weight_g' => (int) ($r['min_weight_g'] ?? 0),
            'max_weight_g' => (int) $r['max_weight_g'],
        ];
    }, $rows);
}

/**
 * LE TYPE DE SERVICE D'UNE LIGNE DE MÉTHODE : 'punkt' ou 'adres'.
 *
 * La colonne fait foi. Le repli sur le nom n'est là que pour la fenêtre entre
 * le déploiement du code et le premier démarrage qui ajoute la colonne — sans
 * lui, pendant ces quelques secondes, un Paczkomat serait traité comme une
 * adresse et la caisse réclamerait une rue.
 */
function wsm_ship_kind_row(array $r): string {
    $k = strtolower(trim((string) ($r['kind'] ?? '')));
    if ($k === 'punkt' || $k === 'adres' || $k === 'brak') return $k;
    return str_contains(strtolower((string) ($r['id'] ?? '')), 'locker')
        || str_contains(strtolower((string) ($r['id'] ?? '')), 'point')
        || str_contains(strtolower((string) ($r['id'] ?? '')), 'pickup') ? 'punkt' : 'adres';
}

/**
 * Le type de service d'une COMMANDE — « faut-il un code de point, ou une
 * adresse ? ». C'est la question que quatorze endroits posaient en comparant
 * l'identifiant à « inpost_locker ». Elle se pose une fois, ici.
 *
 * Une méthode disparue de la table (retirée après coup) ne doit pas faire
 * changer d'avis une commande déjà passée : on retombe alors sur le nom, qui
 * est ce que la commande a gardé.
 */
function wsm_ship_kind(PDO $pdo, string $methodId): string {
    static $cache = [];
    if (isset($cache[$methodId])) return $cache[$methodId];
    try {
        $st = $pdo->prepare("SELECT id, kind FROM wsm_shipping_methods WHERE id = ?");
        $st->execute([$methodId]);
        $row = $st->fetch();
    } catch (Throwable $e) { $row = false; }
    return $cache[$methodId] = wsm_ship_kind_row($row ?: ['id' => $methodId]);
}

/** Comment part une commande, en une ligne de console : point, coursier, ou rien. */
function wsm_ship_opis(PDO $pdo, string $methodId, string $point = ''): string {
    $k = wsm_ship_kind($pdo, $methodId);
    if ($k === 'brak')  return 'Bez wysyłki';
    if ($k === 'punkt') return trim('Paczkomat ' . $point);
    return 'Kurier ' . strtoupper(wsm_ship_carrier($pdo, $methodId));
}

/** Le transporteur d'une commande, lu dans la table — jamais deviné du nom. */
function wsm_ship_carrier(PDO $pdo, string $methodId): string {
    static $cache = [];
    if (isset($cache[$methodId])) return $cache[$methodId];
    try {
        $st = $pdo->prepare("SELECT carrier FROM wsm_shipping_methods WHERE id = ?");
        $st->execute([$methodId]);
        $c = (string) $st->fetchColumn();
    } catch (Throwable $e) { $c = ''; }
    if ($c === '') {
        // Repli : le préfixe du nom. Il ne sert qu'aux commandes dont la
        // méthode a été supprimée de la table entre-temps.
        $c = explode('_', $methodId)[0] ?: 'inpost';
    }
    return $cache[$methodId] = strtolower($c);
}

/**
 * Chiffre un panier. `$items` = [['id' => 'p-…', 'qty' => 2], …] — rien d'autre
 * n'est lu : un « price » envoyé par le client est purement et simplement ignoré.
 *
 * Renvoie [devis, erreurs]. Les erreurs sont indexées par champ pour que le
 * formulaire les affiche au bon endroit.
 */
function wsm_shop_quote(PDO $pdo, array $items, string $methodId, string $lang, array $opts = []): array {
    $e = [];
    $lines = [];
    $itemsGross = 0; $itemsNet = 0; $itemsVat = 0; $weight = 0;

    // ---- Pays de livraison et régime de TVA --------------------------------
    $country = strtoupper(trim((string) ($opts['country'] ?? WSM_SHOP_HOME_COUNTRY))) ?: WSM_SHOP_HOME_COUNTRY;
    $cRow = wsm_shop_country($pdo, $country);
    if ($cRow === null || !$cRow['active']) {
        $e['ship_country'] = 'nie wysyłamy do tego kraju';
        $country = WSM_SHOP_HOME_COUNTRY;
    }
    // Le numéro de TVA n'exonère que si VIES l'a CONFIRMÉ. Un service
    // indisponible laisse passer la commande, mais avec la TVA polonaise :
    // exonérer sur une réponse qu'on n'a pas eue serait s'exposer seul.
    $vies = $opts['vies'] ?? (trim((string) ($opts['vat_eu'] ?? '')) !== ''
        ? wsm_vies_check($pdo, (string) $opts['vat_eu'])
        : ['status' => 'skipped']);
    $reverseCharge = wsm_shop_reverse_charge($pdo, $country, $vies);

    $wanted = [];
    foreach ($items as $it) {
        $id  = trim((string) ($it['id'] ?? ''));
        $qty = (int) ($it['qty'] ?? 0);
        if ($id === '' || $qty <= 0) continue;
        if ($qty > WSM_SHOP_MAX_QTY) $qty = WSM_SHOP_MAX_QTY;
        $wanted[$id] = ($wanted[$id] ?? 0) + $qty;   // le même produit deux fois = une seule ligne
    }
    if (!$wanted) $e['items'] = 'koszyk pusty';

    $S = wsm_shop_strings($pdo, $lang);

    // ---- Première passe : lire les produits et PESER le panier -------------
    // La remise dépend du poids total, donc rien ne peut être chiffré avant de
    // connaître le panier entier.
    $picked = [];
    $fizyczny = false;   // le panier contient-il au moins un objet à expédier ?
    foreach ($wanted as $id => $qty) {
        $st = $pdo->prepare("SELECT * FROM wsm_products WHERE id = ? AND shop_visible = 1 AND active = 1");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) { $e['items'] = 'produkt niedostępny: ' . $id; continue; }
        $p = wsm_shop_row_to_product($r, $S);
        $picked[] = ['p' => $p, 'qty' => $qty];
        $weight += $p['weight_g'] * $qty;
        if ($p['rodzaj'] === 'towar') $fizyczny = true;
    }

    [$discountPct, $tier] = wsm_discount_for_weight($pdo, $weight);

    // La remise du COMPTE PROFESSIONNEL, si l'acheteur en a un.
    //
    // LES DEUX NE S'EMPILENT PAS. Le palier au poids et le tarif pro répondent
    // à la même question — « combien vous prenez » — et cumuler 20 % et 12 %
    // donnerait 30 %, soit toute la marge, sur une commande que personne
    // n'aurait relue. On garde la MEILLEURE des deux, exactement comme deux
    // paliers entre eux ne se cumulent jamais.
    //
    // Ces colonnes existaient depuis le début et n'étaient lues nulle part :
    // un client « B2B » payait le prix de tout le monde.
    $b2bFile = __DIR__ . '/b2b.php';
    $b2b = ['remise' => 0.0, 'franco' => 0, 'b2b' => false, 'source' => ''];
    if (is_file($b2bFile) && trim((string) ($opts['email'] ?? '')) !== '') {
        require_once $b2bFile;
        $b2b = wsm_b2b_conditions($pdo, (string) $opts['email']);
        if ($b2b['remise'] > $discountPct) {
            $discountPct = $b2b['remise'];
            $tier = ['label' => $b2b['source'], 'percent' => $b2b['remise'], 'b2b' => true];
        }
    }

    // ---- Le bon de réduction -----------------------------------------------
    //
    // TROISIÈME PRÉTENDANT À LA MÊME QUESTION. Le palier au poids, le tarif
    // professionnel et un bon en pourcent disent tous « combien vous prenez ».
    // Le MEILLEUR DES TROIS s'applique. Empiler 20 % + 12 % + 10 % ferait
    // 42 % sur une commande que personne n'aurait relue.
    //
    // Un bon en MONTANT, lui, n'est pas un taux : il s'applique après, sur la
    // marchandise seule, et il est réparti au prorata plus bas — sans quoi la
    // TVA d'un panier à deux taux serait fausse.
    //
    // La validité est jugée sur la marchandise AU PRIX PLEIN : un seuil
    // « à partir de 200 zł » qu'une remise ferait manquer de trois grosze
    // serait incompréhensible pour l'acheteur qui voit 200 zł dans son panier.
    $promoFile = __DIR__ . '/promo.php';
    $codeSaisi = strtoupper(trim((string) ($opts['voucher'] ?? '')));
    $bon = null; $bonErr = ''; $bonMontant = 0; $bonPort = false;
    if ($codeSaisi !== '' && is_file($promoFile)) {
        require_once $promoFile;
        $brut = 0;
        foreach ($picked as $x) $brut += $x['p']['price'] * $x['qty'];
        $v = wsm_promo_check($pdo, $codeSaisi, $brut, (string) ($opts['email'] ?? ''));
        if (!$v['ok']) {
            $bonErr = $v['raison'];
        } else {
            $bon = $v['bon'];
            $kind = (string) ($bon['kind'] ?? 'procent');
            if ($kind === 'procent' && (float) $bon['pct'] > $discountPct) {
                $discountPct = (float) $bon['pct'];
                $tier = ['label' => 'Kod ' . $bon['code'], 'percent' => $discountPct, 'voucher' => true];
            } elseif ($kind === 'wysylka') {
                $bonPort = true;
            }
            // Le montant fixe est traité après le calcul des lignes.
        }
    }

    $backorder = false;

    // ---- Deuxième passe : les montants -------------------------------------
    foreach ($picked as $x) {
        $p = $x['p']; $qty = $x['qty'];

        // Le stock ne bloque plus la vente : ce qui manque est produit et
        // l'acheteur est prévenu. Refuser une commande faute de stock, c'est
        // perdre le client au lieu de lui donner une date.
        $missing = max(0, $qty - max(0, $p['stock']));
        if ($missing > 0) $backorder = true;

        // La remise s'applique au prix TTC affiché, ligne par ligne : la somme
        // des lignes reste égale au total, et la TVA se déduit ensuite.
        $lineGross = $p['price'] * $qty;
        if ($discountPct > 0) $lineGross = (int) round($lineGross * (1 - $discountPct / 100));
        [$lineNet, $lineVat] = wsm_split_vat($lineGross, $p['vat_rate']);
        // Autoliquidation : l'acheteur paie le HT, la TVA est due chez lui.
        if ($reverseCharge) { $lineVat = 0; $lineGross = $lineNet; }

        $lines[] = [
            'id' => $p['id'], 'slug' => $p['slug'], 'name' => $p['name'], 'unit' => $p['unit'],
            'sku' => $p['sku'], 'ean' => $p['ean'], 'image' => $p['image'],
            'from' => $p['from'], 'to' => $p['to'],
            'qty' => $qty, 'unit_gross' => $p['price'], 'unit_net' => $p['price_net'],
            'vat_rate' => $p['vat_rate'],
            'line_net' => $lineNet, 'line_vat' => $lineVat, 'line_gross' => $lineGross,
            'weight_g' => $p['weight_g'] * $qty,
            'stock' => max(0, $p['stock']), 'backorder' => $missing,
        ];
        $itemsGross += $lineGross; $itemsNet += $lineNet; $itemsVat += $lineVat;
    }

    // Ce que la remise a fait économiser, sur la base des prix pleins.
    $fullGross = 0;
    foreach ($picked as $x) $fullGross += $x['p']['price'] * $x['qty'];
    $discountAmount = $discountPct > 0 ? max(0, $fullGross - ($reverseCharge ? $fullGross : $itemsGross)) : 0;
    if ($discountPct > 0 && $reverseCharge) {
        // En autoliquidation on compare des HT : le TTC plein n'a plus cours.
        $discountAmount = 0;
        foreach ($picked as $x) {
            [$n, ] = wsm_split_vat($x['p']['price'] * $x['qty'], $x['p']['vat_rate']);
            $discountAmount += $n;
        }
        $discountAmount = max(0, $discountAmount - $itemsGross);
    }

    // ---- Le bon en MONTANT, réparti sur les lignes -------------------------
    //
    // Après la remise, jamais avant : un bon de 20 zł sur un panier déjà
    // remisé enlève 20 zł de ce qui reste à payer, ce que l'acheteur lit.
    // Réparti au prorata parce que les taux de TVA diffèrent d'une ligne à
    // l'autre — 5 % sur une denrée, 23 % ailleurs. Et plafonné à la
    // marchandise : un bon ne rend jamais d'argent (règle 1).
    if ($bon !== null && (string) ($bon['kind'] ?? '') === 'kwota') {
        $bonMontant = wsm_promo_spread($lines, (int) $bon['kwota'], $reverseCharge);
        $itemsGross = 0; $itemsNet = 0; $itemsVat = 0;
        foreach ($lines as $l) {
            $itemsGross += (int) $l['line_gross'];
            $itemsNet   += (int) $l['line_net'];
            $itemsVat   += (int) $l['line_vat'];
        }
    }

    // --- Livraison ----------------------------------------------------------
    // On demande DEUX listes, et ce n'est pas un gaspillage : leur différence
    // est ce qui permet de dire au client CE QUI cloche.
    //   · $tousPays  — ce qui dessert son pays, quel que soit le poids ;
    //   · $methods   — ce qui peut vraiment prendre SON panier.
    // Sans la première, un panier de 40 kg vers la Pologne lirait « nie
    // dowozimy do tego kraju », ce qui est faux et envoie chercher la panne à
    // l'autre bout.
    $tousPays = wsm_shipping_methods($pdo, $lang, $country);
    $methods  = wsm_shipping_methods($pdo, $lang, $country, $weight);
    // SANS EXPÉDITION, SEULEMENT SANS OBJET. Un panier de formations et de
    // licences n'a rien à mettre dans un colis : s'il existe un mode « brak »,
    // c'est le seul proposé — on ne fait pas payer un Paczkomat pour un
    // webinaire. Dès qu'un objet est dans le panier, « brak » disparaît : un
    // chwytak ne voyage pas par e-mail. Sans mode « brak » en base (Szoko),
    // rien ne change.
    $estBrak = fn(array $m): bool => ($m['kind'] ?? '') === 'brak';
    $aBrak = (bool) array_filter($tousPays, $estBrak);
    if ($fizyczny || !$aBrak) {
        $tousPays = array_values(array_filter($tousPays, fn($m) => !$estBrak($m)));
        $methods  = array_values(array_filter($methods,  fn($m) => !$estBrak($m)));
    } else {
        $tousPays = array_values(array_filter($tousPays, $estBrak));
        $methods  = array_values(array_filter($methods,  $estBrak));
    }
    $method = null;
    foreach ($methods as $m) if ($m['id'] === $methodId) $method = $m;

    // DEUX REFUS QUI SE RESSEMBLENT ET N'ONT RIEN À VOIR.
    //
    // « Aucun transporteur ne dessert ce pays » et « cette méthode n'existe
    // pas » aboutissent au même endroit, mais la personne devant l'écran ne
    // doit pas lire la même chose. Le second message était écrit par-dessus le
    // premier : un client à Berlin lisait « nieznana metoda dostawy » — alors
    // il essaie l'autre mode, puis recommence, parce que rien ne lui dit que
    // le problème est son PAYS et pas son clic.
    //
    // L'ordre compte donc : le pays d'abord, et on ne l'écrase plus.
    if (!$methods && !$tousPays) {
        $e['delivery_method'] = $country !== ''
            ? 'nie dowozimy jeszcze do tego kraju (' . $country . ')'
            : 'brak dostawy do tego kraju';
    } elseif (!$methods) {
        // TROISIÈME REFUS, ajouté avec le transporteur de palettes : le pays
        // est desservi, c'est le POIDS qui ne passe nulle part. On donne les
        // bornes, sinon la phrase ne dit pas quoi faire.
        $bornes = [];
        foreach ($tousPays as $m) {
            $bornes[] = ($m['min_weight_g'] > 0 ? (int) round($m['min_weight_g'] / 1000) . '–' : 'do ')
                      . (int) round($m['max_weight_g'] / 1000) . ' kg';
        }
        $e['delivery_method'] = 'żaden przewoźnik nie weźmie ' . (int) round($weight / 1000)
                              . ' kg — dostępne zakresy: ' . implode(', ', array_unique($bornes));
    } elseif ($methodId !== '' && !$method) {
        // La méthode existe peut-être, mais pas pour CE panier : le dire.
        $ailleurs = false;
        foreach ($tousPays as $m) if ($m['id'] === $methodId) $ailleurs = true;
        $e['delivery_method'] = $ailleurs
            ? 'ta metoda nie przyjmie ' . (int) round($weight / 1000) . ' kg'
            : 'nieznana metoda dostawy';
    }
    if (!$method && $methods) $method = $methods[0];

    $shipNet = 0; $shipVat = 0; $shipGross = 0; $freeShipping = false;
    if ($method) {
        // LA CEINTURE, après les bretelles. La liste est déjà filtrée par
        // poids ; ce contrôle reste parce qu'une méthode peut arriver ici par
        // un autre chemin — une commande rejouée, un identifiant forcé — et
        // qu'un colis hors gabarit se refuse au dépôt, pas à la caisse.
        if ($method['max_weight_g'] > 0 && $weight > $method['max_weight_g']) {
            $e['weight_g'] = 'przesyłka za ciężka: ' . $weight . ' g';
        }
        if ($method['min_weight_g'] > 0 && $weight < $method['min_weight_g']) {
            $e['weight_g'] = 'przesyłka za lekka dla tej metody: ' . $weight . ' g (od '
                           . (int) round($method['min_weight_g'] / 1000) . ' kg)';
        }
        // Le seuil de franco du COMPTE PROFESSIONNEL prime quand il est plus
        // bas : c'est une condition accordée à quelqu'un, elle ne peut pas
        // être moins bonne que le seuil public. Plus haut, on l'ignore —
        // sinon un réglage maladroit retirerait un avantage déjà donné.
        $seuil = (int) $method['free_from'];
        if ((int) $b2b['franco'] > 0 && ((int) $b2b['franco'] < $seuil || $seuil === 0)) {
            $seuil = (int) $b2b['franco'];
        }
        $freeShipping = $seuil > 0 && $itemsGross >= $seuil;
        // Le bon « livraison offerte » ne discute pas de seuil : c'est ce
        // qu'il promet, et c'est la seule réduction qui touche le port.
        if ($bonPort) $freeShipping = true;
        if (!$freeShipping) {
            $shipNet   = $method['price_net'];
            $shipGross = $shipNet + (int) round($shipNet * $method['vat_rate']);
            $shipVat   = $shipGross - $shipNet;
            if ($reverseCharge) { $shipVat = 0; $shipGross = $shipNet; }
        }
    }

    $tpl = wsm_shop_parcel_template($pdo, $lines);

    return [[
        'lang'      => $lang,
        'currency'  => 'PLN',
        'lines'     => $lines,
        'items_net' => $itemsNet, 'items_vat' => $itemsVat, 'items_gross' => $itemsGross,
        'shipping_net' => $shipNet, 'shipping_vat' => $shipVat, 'shipping_gross' => $shipGross,
        'shipping_free' => $freeShipping,
        'discount_percent' => $discountPct,
        'discount_amount'  => $discountAmount,
        'discount_label'   => $tier['label'] ?? '',
        // D'OÙ VIENT LA REMISE. Sans ça, l'écran affichait « Rabat ilościowy »
        // pour une remise venue d'un code ou d'un compte professionnel : le
        // libellé nommait la mauvaise raison, et l'acheteur qui retire son
        // code ne comprenait pas pourquoi le montant ne bougeait pas.
        'discount_source'  => ($tier['voucher'] ?? false) ? 'kod'
                            : (($tier['b2b'] ?? false) ? 'firma' : 'waga'),
        'discount_next'    => wsm_discount_next($pdo, $weight),
        // Le bon tel qu'il a AGI, pas tel qu'il a été tapé. `error` est en
        // polonais et dit quoi faire ; `applied` distingue « code accepté »
        // de « code accepté mais déjà battu par une meilleure remise ».
        'voucher' => $bon === null ? null : [
            'id'      => (int) $bon['id'],
            'code'    => (string) $bon['code'],
            'kind'    => (string) $bon['kind'],
            'label'   => wsm_promo_label($bon),
            'amount'  => $bonMontant,
            'free_shipping' => $bonPort,
            'applied' => $bonMontant > 0 || $bonPort
                      || (($tier['voucher'] ?? false) === true),
        ],
        'voucher_error' => $bonErr,
        'voucher_input' => $codeSaisi,
        'backorder'        => $backorder,
        'country'        => $country,
        'reverse_charge' => $reverseCharge,
        'vat_status'     => (string) ($vies['status'] ?? 'skipped'),
        'countries'      => wsm_shop_countries($pdo, $lang),
        'total_net'   => $itemsNet + $shipNet,
        'total_vat'   => $itemsVat + $shipVat,
        'total_gross' => $itemsGross + $shipGross,
        'weight_g'    => $weight,
        'parcel_template' => $tpl,
        'method'      => $method,
        'methods'     => $methods,
        'vat_breakdown' => $reverseCharge ? [] : wsm_vat_breakdown($lines, $shipNet, $shipVat, $method['vat_rate'] ?? 0.23),
    ], $e];
}

/** TVA ventilée par taux — ce que réclame une facture polonaise. */
function wsm_vat_breakdown(array $lines, int $shipNet, int $shipVat, float $shipRate): array {
    $by = [];
    foreach ($lines as $l) {
        $k = (string) $l['vat_rate'];
        $by[$k] ??= ['rate' => (float) $l['vat_rate'], 'net' => 0, 'vat' => 0];
        $by[$k]['net'] += $l['line_net'];
        $by[$k]['vat'] += $l['line_vat'];
    }
    if ($shipNet > 0) {
        $k = (string) $shipRate;
        $by[$k] ??= ['rate' => $shipRate, 'net' => 0, 'vat' => 0];
        $by[$k]['net'] += $shipNet;
        $by[$k]['vat'] += $shipVat;
    }
    $out = array_values($by);
    usort($out, fn($a, $b) => $a['rate'] <=> $b['rate']);
    return $out;
}

/** Marge de calage : un colis n'est jamais rempli à 100 % de son volume. */
const WSM_PACKING_FACTOR = 1.30;

/**
 * Gabarit InPost pour l'ENSEMBLE du panier. Deux conditions, pas une :
 *   · le plus gros article doit entrer dans le casier (contrainte de forme) ;
 *   · le volume total, majoré du calage, doit y tenir (contrainte de place).
 *
 * Prendre le gabarit du plus gros article serait faux : deux sacs de 6 cm
 * d'épaisseur passent chacun dans un casier A (8 cm) mais pas ensemble.
 *
 * Reste une ESTIMATION — seul un vrai calcul de calage trancherait. Le
 * back-office l'affiche comme telle et l'emballeur peut la corriger.
 * Renvoie '' quand rien ne convient : c'est un envoi par coursier.
 */
function wsm_shop_parcel_template(PDO $pdo, array $lines): string {
    if (!$lines) return '';
    $volume = 0;
    $fits = [];                                          // gabarits acceptables pour CHAQUE article
    foreach ($lines as $l) {
        $st = $pdo->prepare("SELECT length_mm, width_mm, height_mm FROM wsm_products WHERE id = ?");
        $st->execute([$l['id']]);
        $d = $st->fetch();
        if (!$d) return '';
        $L = (int) $d['length_mm']; $W = (int) $d['width_mm']; $H = (int) $d['height_mm'];
        if ($L <= 0 || $W <= 0 || $H <= 0) return '';    // dimensions inconnues : on n'invente pas
        $volume += $L * $W * $H * max(1, (int) $l['qty']);
        $t = wsm_inpost_template($L, $W, $H);
        if ($t === '') return '';                        // un article hors casier ⇒ coursier
        $fits[] = $t;
    }
    $needed = (int) ceil($volume * WSM_PACKING_FACTOR);

    $order = array_keys(WSM_INPOST_TEMPLATES);           // A < B < C
    $floor = 0;
    foreach ($fits as $t) { $i = (int) array_search($t, $order, true); if ($i > $floor) $floor = $i; }

    foreach ($order as $i => $key) {
        if ($i < $floor) continue;
        $m = WSM_INPOST_TEMPLATES[$key]['max'];
        if ($m[0] * $m[1] * $m[2] >= $needed) return $key;
    }
    return '';
}

/**
 * Valide les champs « vitrine » d'un produit (ce que la boutique affiche).
 * Seules les clés PRÉSENTES dans la requête sont touchées : la page produit
 * envoie ce qu'elle modifie, pas l'objet entier.
 *
 * @return array [colonnes à écrire, erreurs par champ]
 */
/**
 * Un prix tapé par un humain, en nombre.
 *
 * `(float) str_replace(',', '.', $v)` suffisait tant que personne n'écrivait
 * un millier. « 1 234,50 » donnait 1.0 — mille deux cent trente-quatre zlotys
 * vendus un zloty, sans un mot. PHP arrête la conversion à la première espace,
 * et l'espace fine insécable des claviers polonais compte comme telle.
 *
 * On retire donc tout ce qui n'est ni chiffre ni séparateur, puis on tranche :
 * le DERNIER séparateur est le décimal, les autres étaient des milliers.
 *
 * @return float|null null si rien d'exploitable — l'appelant ne touche alors
 *                    pas au prix, plutôt que d'écrire zéro.
 */
function wsm_parse_price(string $v): ?float {
    $v = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}", ' '], '', trim($v));
    $v = (string) preg_replace('/[^0-9.,-]/', '', $v);
    if ($v === '' || !preg_match('/[0-9]/', $v)) return null;
    $lastC = strrpos($v, ','); $lastD = strrpos($v, '.');
    $cut = max($lastC === false ? -1 : $lastC, $lastD === false ? -1 : $lastD);
    if ($cut >= 0) {
        $ent = preg_replace('/[.,]/', '', substr($v, 0, $cut));
        $dec = preg_replace('/[^0-9]/', '', substr($v, $cut + 1));
        // « 1.234 » sans décimales : trois chiffres après le point, c'est un
        // millier, pas des millièmes de zloty.
        $v = strlen($dec) === 3 && $lastC === false && substr_count($v, '.') === 1
             ? $ent . $dec
             : $ent . '.' . $dec;
    }
    return is_numeric($v) ? (float) $v : null;
}

/**
 * Un texte polonais rendu en adresse : « Czekolada ciemna 70 % » →
 * « czekolada-ciemna-70 ».
 *
 * Les diacritiques sont TRANSLITTÉRÉS, pas jetés. preg_replace() sur [^a-z0-9]
 * transformait « Świąteczna » en « wi-teczna » : une adresse que personne ne
 * relie au produit, et qu'on ne peut plus corriger une fois indexée.
 *
 * @param string $repli servi quand il ne reste rien de lisible.
 */
function wsm_slugify(string $texte, string $repli = ''): string {
    $tr = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
           'Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ź'=>'z','Ż'=>'z'];
    $v = mb_strtolower(strtr(trim($texte), $tr), 'UTF-8');
    $v = trim((string) preg_replace('/[^a-z0-9]+/', '-', $v), '-');
    if ($v === '') $v = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($repli, 'UTF-8')), '-');
    return substr($v, 0, 80);
}

/**
 * LA LONGUEUR DES COLONNES, que SQLite ignore et que MySQL refuse.
 *
 * wsm_products.id est un VARCHAR(48) — et toutes les tables qui le citent
 * (lignes de commande, stock, abonnements) aussi. L'identifiant dérivé d'un
 * nom pouvait faire 80 caractères : sous SQLite (développement, tests) ça
 * passait, sous MySQL strict (production) l'INSERT tombait — « Nie udało się
 * utworzyć produktu » dès qu'un nom dépassait une cinquantaine de lettres.
 * 40 laisse la place du suffixe « -2 » … « -50 » et des clés de texte
 * (« product.<id>.prop.12.k » tient dans les 80 de wsm_shop_i18n.k).
 */
const WSM_PRODUCT_ID_MAX   = 40;
const WSM_PRODUCT_SLUG_MAX = 80;
const WSM_PRODUCT_NAME_MAX = 160;   // wsm_products.nom VARCHAR(160)

/** Coupe une adresse à $max, entre deux mots quand c'est possible. */
function wsm_slug_coupe(string $s, int $max): string {
    if (strlen($s) <= $max) return $s;
    $c = substr($s, 0, $max);
    $p = strrpos($c, '-');
    if ($p !== false && $p >= (int) ($max * 0.6)) $c = substr($c, 0, $p);
    return rtrim($c, '-');
}

/**
 * La même chose, mais garantie libre dans une colonne : deux produits qui
 * partagent une adresse en rendent un inatteignable. Et jamais plus longue
 * que la colonne : voir WSM_PRODUCT_ID_MAX.
 */
function wsm_slug_libre(PDO $pdo, string $base, string $colonne, string $saufId = ''): string {
    $max = $colonne === 'id' ? WSM_PRODUCT_ID_MAX : WSM_PRODUCT_SLUG_MAX;
    $base = wsm_slug_coupe($base !== '' ? $base : 'produkt', $max);
    $st = $pdo->prepare("SELECT 1 FROM wsm_products WHERE $colonne = ? AND id <> ?");
    for ($i = 0; $i < 50; $i++) {
        $essai = $i === 0 ? $base : wsm_slug_coupe($base, $max - 3) . '-' . ($i + 1);
        $st->execute([$essai, $saufId]);
        if (!$st->fetchColumn()) return $essai;
    }
    return wsm_slug_coupe($base, $max - 9) . '-' . bin2hex(random_bytes(4));
}

function wsm_validate_product_shop(PDO $pdo, array $in, string $id): array {
    require_once __DIR__ . '/media.php';
    $e = []; $out = [];

    if (array_key_exists('slug', $in)) {
        $slug = strtolower(trim((string) $in['slug']));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $e['slug'] = 'wymagany';
        } elseif (strlen($slug) > 80) {
            $e['slug'] = 'maks. 80 znaków';
        } else {
            // Le slug est l'adresse publique du produit : deux produits ne
            // peuvent pas se la partager, sinon l'un devient inatteignable.
            $st = $pdo->prepare("SELECT id FROM wsm_products WHERE slug = ? AND id <> ?");
            $st->execute([$slug, $id]);
            if ($st->fetchColumn()) $e['slug'] = 'zajęty przez inny produkt';
        }
        $out['slug'] = $slug;
    }

    if (array_key_exists('image_url', $in)) {
        $url = trim((string) $in['image_url']);
        if (!wsm_media_valid_url($url)) $e['image_url'] = 'wgraj plik lub podaj adres https://';
        $out['image_url'] = $url;
    }

    if (array_key_exists('stock', $in)) {
        $s = (int) $in['stock'];
        if ($s < 0) $e['stock'] = 'nie może być ujemny';
        $out['stock'] = max(0, $s);
    }

    if (array_key_exists('shop_visible', $in)) $out['shop_visible'] = !empty($in['shop_visible']) ? 1 : 0;

    // TVA du produit. « 23 » et « 0,23 » désignent la même chose — l'écran
    // envoie l'un, un import pourrait envoyer l'autre.
    if (array_key_exists('vat_rate', $in)) {
        $r = (float) str_replace(',', '.', (string) $in['vat_rate']);
        if ($r > 1) $r /= 100;
        $match = null;
        foreach (WSM_VAT_RATES as $legal) if (abs($legal - $r) < 0.0005) $match = $legal;
        if ($match === null) $e['vat_rate'] = 'dozwolone stawki: ' . wsm_vat_rates_label();
        else $out['vat_rate'] = $match;
    }

    foreach (['origin' => 80, 'cocoa' => 16, 'unit_label' => 40, 'badge' => 40,
              'swatch_from' => 32, 'swatch_to' => 32] as $k => $max) {
        if (!array_key_exists($k, $in)) continue;
        $v = trim((string) $in[$k]);
        if (mb_strlen($v) > $max) $e[$k] = 'maks. ' . $max . ' znaków';
        $out[$k] = $v;
    }

    // Un produit sans slug ne peut pas être mis en vente : il n'aurait pas d'URL.
    if (($out['shop_visible'] ?? 0) === 1 && array_key_exists('slug', $out) && $out['slug'] === '') {
        $e['slug'] = 'wymagany, aby produkt był widoczny w sklepie';
    }

    return [$out, $e];
}

/** Code de commande lisible : MS-AAMMJJ-NNNN. */
function wsm_next_order_code(PDO $pdo): string {
    $prefix = 'MS-' . date('ymd') . '-';
    $st = $pdo->prepare("SELECT code FROM wsm_orders WHERE code LIKE ? ORDER BY code DESC LIMIT 1");
    $st->execute([$prefix . '%']);
    $last = (string) $st->fetchColumn();
    $n = $last !== '' ? ((int) substr($last, strlen($prefix))) + 1 : 1;
    return $prefix . sprintf('%04d', $n);
}

/**
 * Valide l'acheteur : e-mail et téléphone pour tpay et le transporteur,
 * adresse selon le TYPE de service, NIP si une facture d'entreprise est
 * demandée.
 *
 * $kind — 'punkt' ou 'adres'. Il vient de la table (wsm_ship_kind), parce
 * qu'il n'est pas devinable : ce validateur exigeait un code de Paczkomat sur
 * « delivery_method === 'inpost_locker' » et une rue sur
 * « === 'inpost_courier' ». Ajoutez un transporteur, et les DEUX tests sont
 * faux : on n'exige plus rien du tout, et une commande part sans destination.
 * Laissé à null, il se déduit du nom — uniquement pour les appels anciens.
 */
function wsm_validate_buyer(array $in, string $method, bool $invoice, ?string $kind = null): array {
    $kind = $kind ?? wsm_ship_kind_row(['id' => $method]);
    $e = [];
    $out = [];

    $email = trim((string) ($in['email'] ?? ''));
    if ($email === '') $e['email'] = 'wymagany';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $e['email'] = 'nieprawidłowy';
    $out['email'] = strtolower($email);

    $phone = (string) ($in['phone'] ?? '');
    if ($phone === '') $e['phone'] = 'wymagany (InPost)';
    elseif (!wsm_valid_phone($phone)) $e['phone'] = '9 cyfr';
    $out['phone'] = $phone === '' ? '' : wsm_normalize_phone($phone);

    foreach (['first_name' => 'imię', 'last_name' => 'nazwisko'] as $k => $lbl) {
        $v = trim((string) ($in[$k] ?? ''));
        if ($v === '') $e[$k] = $lbl . ' wymagane';
        $out[$k] = $v;
    }

    $out['client_type'] = $invoice && trim((string) ($in['nip'] ?? '')) !== '' ? 'firma' : 'osoba';
    $out['invoice'] = $invoice ? 1 : 0;
    $out['company'] = trim((string) ($in['company'] ?? ''));
    $out['vat_eu']  = '';
    $vat = trim((string) ($in['vat_eu'] ?? ''));
    if ($vat !== '') {
        if (!wsm_valid_vat_eu($vat)) $e['vat_eu'] = 'format VIES (np. PL1234567890)';
        else $out['vat_eu'] = strtoupper(preg_replace('/[\s.-]+/', '', $vat) ?? '');
    }

    $nip = trim((string) ($in['nip'] ?? ''));
    if ($out['client_type'] === 'firma') {
        if ($nip === '') $e['nip'] = 'wymagany dla firmy';
        elseif (!wsm_valid_nip($nip)) $e['nip'] = 'błędna suma kontrolna';
        if ($out['company'] === '') $e['company'] = 'nazwa firmy wymagana';
    }
    $out['nip'] = $nip !== '' && wsm_valid_nip($nip) ? wsm_normalize_nip($nip) : '';

    // Adresse de facturation : exigée dès qu'une facture est demandée.
    $pc = (string) ($in['bill_postcode'] ?? '');
    if ($invoice) {
        foreach (['bill_street' => 'ulica', 'bill_building' => 'numer', 'bill_city' => 'miasto'] as $k => $lbl) {
            if (trim((string) ($in[$k] ?? '')) === '') $e[$k] = $lbl . ' wymagane do faktury';
        }
        if ($pc === '') $e['bill_postcode'] = 'wymagany do faktury';
    }
    if ($pc !== '' && !wsm_valid_postcode($pc)) $e['bill_postcode'] = 'format NN-NNN';
    $out['bill_postcode'] = $pc === '' ? '' : wsm_normalize_postcode($pc);
    foreach (['bill_street', 'bill_building', 'bill_city'] as $k) $out[$k] = trim((string) ($in[$k] ?? ''));
    $cc = strtoupper(trim((string) ($in['bill_country'] ?? 'PL')));
    $out['bill_country'] = preg_match('/^[A-Z]{2}$/', $cc) ? $cc : 'PL';

    // Destination : un point (code) ou une adresse complète. C'est le TYPE de
    // service qui tranche, pas le nom de la méthode.
    $point = strtoupper(trim((string) ($in['inpost_point'] ?? '')));
    if ($kind === 'punkt') {
        if ($point === '') $e['inpost_point'] = 'wybierz punkt odbioru';
        elseif (!wsm_valid_inpost_point($point)) $e['inpost_point'] = 'np. KRA010';
    }
    $out['inpost_point'] = $point;

    $spc = (string) ($in['ship_postcode'] ?? '');
    if ($kind === 'adres') {
        foreach (['ship_street' => 'ulica', 'ship_building' => 'numer', 'ship_city' => 'miasto'] as $k => $lbl) {
            if (trim((string) ($in[$k] ?? '')) === '') $e[$k] = $lbl . ' wymagane dla kuriera';
        }
        if ($spc === '') $e['ship_postcode'] = 'wymagany dla kuriera';
    }
    if ($spc !== '' && !wsm_valid_postcode($spc)) $e['ship_postcode'] = 'format NN-NNN';
    $out['ship_postcode'] = $spc === '' ? '' : wsm_normalize_postcode($spc);
    foreach (['ship_street', 'ship_building', 'ship_city'] as $k) $out[$k] = trim((string) ($in[$k] ?? ''));
    $scc = strtoupper(trim((string) ($in['ship_country'] ?? 'PL')));
    $out['ship_country'] = preg_match('/^[A-Z]{2}$/', $scc) ? $scc : 'PL';

    if (empty($in['consent_terms'])) $e['consent_terms'] = 'akceptacja regulaminu wymagana';

    $out['note'] = mb_substr(trim((string) ($in['note'] ?? '')), 0, 500);

    // LA LONGUEUR DES CHAMPS, que MySQL impose et que SQLite ignorait. Un
    // numéro de rue « 12/4 lok. 5, klatka B, wejście od podwórza » (42 signes
    // pour une colonne de 30) faisait refuser la commande ENTIÈRE par la
    // base — et l'acheteur lisait « Popraw zaznaczone pola » sans qu'aucun
    // champ soit marqué. Le refus est maintenant sur le champ, avec la suite.
    foreach (WSM_BUYER_MAX as $k => $max) {
        if (isset($e[$k]) || mb_strlen((string) ($out[$k] ?? '')) <= $max) continue;
        $e[$k] = 'maks. ' . $max . ' znaków' . (str_ends_with($k, '_building') ? ', resztę wpisz w uwagach do zamówienia' : '');
    }

    return [$out, $e];
}

/** Longueur maximale des champs de l'acheteur : celle des colonnes de wsm_orders. */
const WSM_BUYER_MAX = [
    'email' => 200, 'first_name' => 120, 'last_name' => 120, 'company' => 200,
    'bill_street' => 200, 'bill_building' => 30, 'bill_city' => 120,
    'ship_street' => 200, 'ship_building' => 30, 'ship_city' => 120,
];

/**
 * Crée la commande : devis recalculé, acheteur validé, stock décrémenté,
 * lignes figées, expédition préparée. Tout dans une transaction — une
 * commande à moitié écrite serait pire qu'une commande refusée.
 *
 * @return array [commande|null, erreurs]
 */
function wsm_shop_create_order(PDO $pdo, array $body): array {
    $lang   = wsm_shop_lang($pdo, (string) ($body['lang'] ?? ''));
    $method = (string) ($body['delivery_method'] ?? 'inpost_locker');
    $invoice = !empty($body['invoice']);

    $code_bon = (string) ($body['voucher'] ?? '');
    [$quote, $qErr] = wsm_shop_quote($pdo, (array) ($body['items'] ?? []), $method, $lang,
                                     ['email' => (string) ($body['email'] ?? ''), 'voucher' => $code_bon]);
    [$buyer, $bErr] = wsm_validate_buyer($body, $method, $invoice, wsm_ship_kind($pdo, $method));
    $errors = $qErr + $bErr;
    if ($errors) return [null, $errors];
    if ($quote['total_gross'] <= 0) return [null, ['items' => 'koszyk pusty']];

    // ---- VIES : le numéro de TVA saisi à la caisse est-il réel ? ------------
    // Un numéro que VIES déclare inconnu ferait rejeter la facture : mieux vaut
    // le dire au client maintenant qu'après l'encaissement. En revanche, si le
    // service est en panne, la commande passe — on garde l'état pour plus tard.
    $vies = wsm_vies_check($pdo, (string) $buyer['vat_eu']);
    if (wsm_vies_blocks($vies)) return [null, ['vat_eu' => $vies['reason'] ?: 'nieznany w VIES']];

    // Le devis est REFAIT avec le pays et le régime de TVA : c'est lui qui fait
    // foi, pas celui qu'a vu le navigateur.
    $shipCountry = (string) ($buyer['ship_country'] ?: WSM_SHOP_HOME_COUNTRY);
    [$quote, $qErr2] = wsm_shop_quote($pdo, (array) ($body['items'] ?? []), $method, $lang,
        ['country' => $shipCountry, 'vies' => $vies, 'email' => (string) ($buyer['email'] ?? ''),
         'voucher' => $code_bon]);
    if ($qErr2) return [null, $qErr2];

    // Un code tapé et refusé ARRÊTE la commande. Laisser passer serait faire
    // payer le prix plein à quelqu'un qui croit avoir une réduction — il ne
    // s'en apercevrait qu'en lisant sa facture, et il aurait raison de se
    // plaindre. Le message dit ce qui cloche et ce qu'il faut faire.
    if ($code_bon !== '' && ($quote['voucher_error'] ?? '') !== '') {
        return [null, ['voucher' => $quote['voucher_error']]];
    }

    $pdo->beginTransaction();
    try {
        // Stock : relu et décrémenté DANS la transaction. Deux commandes
        // simultanées sur le dernier article ne peuvent pas passer toutes deux.
        // On prélève ce qui existe, jamais plus : le stock ne descend pas sous
        // zéro et le reste est noté comme à produire. Le CASE protège de la
        // course avec une commande simultanée sans faire échouer celle-ci.
        $dec = $pdo->prepare("UPDATE wsm_products
                                 SET stock = CASE WHEN stock >= ? THEN stock - ? ELSE 0 END
                               WHERE id = ?");
        $read = $pdo->prepare("SELECT stock FROM wsm_products WHERE id = ?");
        $taken = [];
        foreach ($quote['lines'] as $l) {
            $take = max(0, (int) $l['qty'] - (int) ($l['backorder'] ?? 0));
            if ($take > 0) {
                $dec->execute([$take, $take, $l['id']]);
                $read->execute([$l['id']]);
                $taken[] = [$l['id'], $take, (int) $read->fetchColumn(), (int) ($l['backorder'] ?? 0)];
            }
        }

        $code  = wsm_next_order_code($pdo);
        $token = bin2hex(random_bytes(16));

        $cols = [
            'code' => $code, 'access_token' => $token, 'lang' => $lang, 'currency' => 'PLN',
            'status' => 'nowe', 'payment_status' => 'oczekuje',
            'client_type' => $buyer['client_type'], 'email' => $buyer['email'], 'phone' => $buyer['phone'],
            'first_name' => $buyer['first_name'], 'last_name' => $buyer['last_name'],
            'company' => $buyer['company'], 'nip' => $buyer['nip'], 'vat_eu' => $buyer['vat_eu'],
            'invoice' => $buyer['invoice'],
            // L'accord pour un e-paragon (fiskal.php, règle 3). La case n'est
            // proposée que lorsqu'une caisse fiscale est branchée : absente,
            // on n'écrit rien plutôt qu'un « oui » qu'on n'a pas demandé.
            'paragon_forma' => !array_key_exists('e_paragon', $body) || $body['e_paragon'] === null ? ''
                             : (!empty($body['e_paragon']) ? 'e' : 'papier'),
            'bill_street' => $buyer['bill_street'], 'bill_building' => $buyer['bill_building'],
            'bill_postcode' => $buyer['bill_postcode'], 'bill_city' => $buyer['bill_city'],
            'bill_country' => $buyer['bill_country'],
            'delivery_method' => $method, 'inpost_point' => $buyer['inpost_point'],
            'ship_street' => $buyer['ship_street'], 'ship_building' => $buyer['ship_building'],
            'ship_postcode' => $buyer['ship_postcode'], 'ship_city' => $buyer['ship_city'],
            'ship_country' => $buyer['ship_country'],
            'items_net' => $quote['items_net'], 'items_vat' => $quote['items_vat'], 'items_gross' => $quote['items_gross'],
            'shipping_net' => $quote['shipping_net'], 'shipping_vat' => $quote['shipping_vat'],
            'shipping_gross' => $quote['shipping_gross'],
            'total_net' => $quote['total_net'], 'total_vat' => $quote['total_vat'], 'total_gross' => $quote['total_gross'],
            'weight_g' => $quote['weight_g'], 'parcel_template' => $quote['parcel_template'],
            'note' => $buyer['note'],
            'reverse_charge' => $quote['reverse_charge'] ? 1 : 0,
            'backorder' => !empty($quote['backorder']) ? 1 : 0,
            'discount_percent' => $quote['discount_percent'],
            'discount_amount'  => $quote['discount_amount'],
            // Gelé sur la commande : le bon peut changer, celle-ci ne doit pas.
            'voucher_code'   => (string) ($quote['voucher']['code'] ?? ''),
            'voucher_amount' => (int) ($quote['voucher']['amount'] ?? 0),
            // D'où vient cette commande. Figée : le lien peut être renommé ou
            // retiré demain, la commande doit rester attribuable.
            'source' => substr(preg_replace('/[^a-z0-9_.-]/', '',
                            strtolower(trim((string) ($body['source'] ?? '')))) ?? '', 0, 40),
        ] + wsm_vies_columns($vies);
        $names = array_keys($cols);
        $sql = 'INSERT INTO wsm_orders (' . implode(',', $names) . ') VALUES (' .
               implode(',', array_fill(0, count($names), '?')) . ')';
        $pdo->prepare($sql)->execute(array_values($cols));
        $orderId = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare(
            "INSERT INTO wsm_order_items
               (order_id, product_id, name, sku, ean, qty, unit_net, unit_gross, vat_rate,
                line_net, line_vat, line_gross, weight_g, backorder, unit_cost)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        // Le coût de revient est lu ici, au moment d'écrire la ligne, et jamais
        // porté par le devis : celui-ci part tel quel dans la réponse publique
        // de /shop/quote, et le prix d'achat n'a rien à y faire.
        $cost = $pdo->prepare("SELECT base_cost FROM wsm_products WHERE id = ?");
        foreach ($quote['lines'] as $l) {
            $cost->execute([$l['id']]);
            $unitCost = wsm_grosze($cost->fetchColumn() ?: 0);
            $ins->execute([$orderId, $l['id'], $l['name'], $l['sku'], $l['ean'], $l['qty'],
                $l['unit_net'], $l['unit_gross'], $l['vat_rate'],
                $l['line_net'], $l['line_vat'], $l['line_gross'], $l['weight_g'],
                (int) ($l['backorder'] ?? 0), $unitCost]);
        }

        $pdo->prepare(
            "INSERT INTO wsm_shipments (order_id, carrier, service, target_point, parcel_template, weight_g, status)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([$orderId, 'inpost', $method, $buyer['inpost_point'],
                    $quote['parcel_template'], $quote['weight_g'], 'do_utworzenia']);

        // Le magasin garde la trace de chaque sortie : un stock qui baisse
        // sans mouvement est un stock qu'on ne saura pas expliquer.
        foreach ($taken as [$prodId, $take, $after, $missing]) {
            wsm_stock_log($pdo, (string) $prodId, -$take, 'sprzedaz', $after, [
                'doc' => $code, 'actor' => 'sklep',
                'note' => $missing > 0 ? 'do wykonania: ' . $missing : '',
            ]);
        }

        // ---- Le bon est DÉCOMPTÉ ici, pas au devis -------------------------
        //
        // C'est le seul endroit où la double dépense peut être arbitrée : deux
        // onglets ouverts passent tous les deux la validation à la même
        // seconde, et seul un UPDATE conditionnel départage. Si le quota vient
        // d'être atteint, TOUTE la commande est annulée et l'acheteur
        // l'apprend — plutôt que de payer le prix plein sans le savoir.
        if (!empty($quote['voucher']['id']) && !empty($quote['voucher']['applied'])) {
            require_once __DIR__ . '/promo.php';
            [$okBon, $msgBon] = wsm_promo_redeem($pdo, (int) $quote['voucher']['id'], $orderId,
                                                 (string) $buyer['email'],
                                                 (int) $quote['voucher']['amount']);
            if (!$okBon) throw new RuntimeException('voucher:' . $msgBon);
            wsm_order_event($pdo, $orderId, 'kod_rabatowy',
                            (string) $quote['voucher']['code'] . ' · ' . (string) $quote['voucher']['label'], 'sklep');
        }

        wsm_order_event($pdo, $orderId, 'utworzone', $code . ' · ' . wsm_money($quote['total_gross']) . ' PLN', 'sklep');

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Le code épuisé n'est pas une panne : c'est une réponse, et elle se
        // dit dans la langue de l'acheteur, sur le bon champ du formulaire.
        if (str_starts_with($ex->getMessage(), 'voucher:')) {
            return [null, ['voucher' => 'Ten kod właśnie się wyczerpał. Usuń go, aby dokończyć zamówienie.']];
        }
        return [null, ['db' => $ex->getMessage()]];
    }

    $order = wsm_order_by_id($pdo, $orderId);

    // Accusé de réception. Une commande qui dépasse le stock reçoit le message
    // qui tient la promesse commerciale : elle passe, et on recontacte avec la
    // date. L'envoi ne peut pas faire échouer la commande — elle est déjà
    // écrite, et un serveur de mail muet laisse le message en file.
    if ($order) {
        wsm_mail_auto($pdo, 'zamowienie', $order);
        if (!empty($order['backorder'])) wsm_mail_auto($pdo, 'na_zamowienie', $order);
    }

    return [$order, []];
}

function wsm_order_event(PDO $pdo, int $orderId, string $event, string $detail = '', string $actor = ''): void {
    $pdo->prepare("INSERT INTO wsm_order_events (order_id, event, detail, actor) VALUES (?,?,?,?)")
        ->execute([$orderId, $event, mb_substr($detail, 0, 255), mb_substr($actor, 0, 120)]);
}

/** « 23, 8, 5, 0 % » — les taux autorisés, pour un message d'erreur lisible. */
function wsm_vat_rates_label(): string {
    return implode(', ', array_map(fn($r) => wsm_vat_percent($r), WSM_VAT_RATES)) . ' %';
}

/** 0.23 → « 23 », 0.08 → « 8 ». Pas de décimale inutile à l'écran. */
function wsm_vat_percent(float $rate): string {
    return rtrim(rtrim(number_format($rate * 100, 2, ',', ''), '0'), ',');
}

/** Montant en grosze → chaîne « 129,90 ». */
function wsm_money(int $grosze): string {
    return number_format($grosze / 100, 2, ',', ' ');
}

function wsm_order_by_id(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM wsm_orders WHERE id = ?");
    $st->execute([$id]);
    $o = $st->fetch();
    return $o ? wsm_order_hydrate($pdo, $o) : null;
}

/**
 * Relit une commande depuis son code + son jeton. Le jeton est comparé avec
 * hash_equals : sans lui, connaître un code de commande ne donne rien.
 */
function wsm_order_by_code(PDO $pdo, string $code, string $token): ?array {
    $st = $pdo->prepare("SELECT * FROM wsm_orders WHERE code = ?");
    $st->execute([$code]);
    $o = $st->fetch();
    if (!$o) return null;
    if ($token === '' || !hash_equals((string) $o['access_token'], $token)) return null;
    return wsm_order_hydrate($pdo, $o);
}

// ─── RETROUVER SA COMMANDE SANS COMPTE ──────────────────────────────────────
//
// LE LIEN SIGNÉ EXISTE DÉJÀ, et il est solide : 128 bits de jeton dans
// /zamowienie/<kod>?t=<jeton>. Mais il vit dans un mail, et un mail se perd, se
// range tout seul, part en indésirables. Le client qui veut juste savoir où est
// son colis écrit alors à la boutique, et quelqu'un ici répond à la main.
//
// LE NUMÉRO SEUL NE PROTÈGE RIEN. Il s'écrit MS-AAMMJJ-0001 : une date et un
// compteur. Qui connaît le jour d'une commande la trouve en quelques centaines
// d'essais. C'est le COUPLE numéro + adresse e-mail qui ouvre, et le plafond
// ci-dessous qui interdit d'essayer mille couples.
//
// PAS DE COMPTE CLIENT, et c'est volontaire : des comptes, ce sont des mots de
// passe à réinitialiser, des données à exporter et à effacer sur demande, et
// une surface à tenir pour toujours. Pour une boutique où l'on commande en
// invité, le couple numéro + e-mail donne le même service.
const WSM_SUIVI_MAX_ESSAIS = 8;        // par heure et par adresse IP
const WSM_SUIVI_GARDE_H    = 24;       // au-delà, les tentatives sont oubliées

/**
 * L'adresse de l'appelant, sans faire confiance aux en-têtes falsifiables.
 *
 * REMOTE_ADDR est posé par le serveur ; X-Forwarded-For vient du client et ne
 * vaut que derrière un proxy dont on est sûr. Un plafond contournable ne
 * plafonne rien.
 */
function wsm_ip_appelant(): string {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/**
 * Le numéro tel qu'on le retrouve, pas tel qu'il a été tapé.
 *
 * Il est recopié à la main depuis un mail ou une page : minuscules, espaces
 * parasites, et surtout le tiret cadratin qu'un traitement de texte substitue
 * tout seul au trait d'union. Refuser un numéro juste pour ça, c'est renvoyer
 * le client vers le formulaire de contact qu'on essaie précisément de lui
 * épargner.
 */
function wsm_suivi_code(string $s): string {
    $s = strtoupper(trim($s));
    // Les espaces deviennent des traits d'union, ils ne DISPARAISSENT pas :
    // « MS 260827 0001 » est ce qu'on obtient en recopiant depuis un téléphone,
    // et l'effacer donnerait « MS2608270001 », qui ne correspond à rien.
    $s = str_replace(["\u{2013}", "\u{2014}", "\u{2212}", ' ', "\t"], ['-', '-', '-', '-', '-'], $s);
    $s = (string) preg_replace('/[^A-Z0-9-]/', '', $s);
    $s = trim((string) preg_replace('/-+/', '-', $s), '-');
    return substr($s, 0, 40);
}

/**
 * La commande qui porte CE numéro ET CET e-mail, ou rien.
 *
 * @return array{code:string,token:string}|null
 */
function wsm_suivi_cherche(PDO $pdo, string $code, string $email): ?array {
    $code = wsm_suivi_code($code);
    $mail = strtolower(trim($email));
    if ($code === '' || $mail === '') return null;
    // LOWER() des deux côtés : une adresse se saisit comme elle se prononce,
    // et « Jan.Kowalski@ » doit ouvrir la commande de « jan.kowalski@ ».
    $st = $pdo->prepare("SELECT code, access_token FROM wsm_orders
                          WHERE code = ? AND LOWER(email) = ? LIMIT 1");
    $st->execute([$code, $mail]);
    $r = $st->fetch();
    return $r ? ['code' => (string) $r['code'], 'token' => (string) $r['access_token']] : null;
}

/** Trop de tentatives infructueuses depuis cette adresse dans la dernière heure ? */
function wsm_suivi_trop(PDO $pdo, string $ip): bool {
    if ($ip === '') return false;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_order_lookups
                              WHERE ip = ? AND created_at >= ?");
        $st->execute([$ip, date('Y-m-d H:i:s', time() - 3600)]);
        return (int) $st->fetchColumn() >= WSM_SUIVI_MAX_ESSAIS;
    } catch (Throwable $e) {
        // Table absente : on ne bloque pas la boutique pour un compteur. Le
        // couple numéro + e-mail reste exigé, il n'y a pas d'ouverture.
        return false;
    }
}

/**
 * Note une tentative ratée, et oublie les vieilles.
 *
 * L'HORODATAGE EST CALCULÉ EN PHP, pas laissé au défaut de la base. MySQL écrit
 * CURRENT_TIMESTAMP dans le fuseau de la session, SQLite en UTC : si les deux
 * bouts ne parlent pas du même fuseau, la fenêtre d'une heure glisse, le compte
 * revient à zéro, et le plafond ne plafonne plus — sans la moindre erreur nulle
 * part.
 */
function wsm_suivi_echec(PDO $pdo, string $ip, string $code): void {
    if ($ip === '') return;
    try {
        $st = $pdo->prepare("INSERT INTO wsm_order_lookups (ip, code, created_at) VALUES (?,?,?)");
        $st->execute([$ip, wsm_suivi_code($code), date('Y-m-d H:i:s')]);
        // On ne garde pas un journal de tentatives : ce qui a plus d'un jour ne
        // sert plus à rien et reste une adresse IP conservée sans raison.
        $pdo->prepare("DELETE FROM wsm_order_lookups WHERE created_at < ?")
            ->execute([date('Y-m-d H:i:s', time() - WSM_SUIVI_GARDE_H * 3600)]);
    } catch (Throwable $e) { /* le compteur n'est pas la boutique */ }
}

function wsm_order_hydrate(PDO $pdo, array $o): array {
    $id = (int) $o['id'];
    $st = $pdo->prepare("SELECT * FROM wsm_order_items WHERE order_id = ? ORDER BY id");
    $st->execute([$id]);
    $items = array_map(fn($r) => [
        'product_id' => $r['product_id'], 'name' => $r['name'], 'sku' => $r['sku'],
        'qty' => (int) $r['qty'], 'unit_gross' => (int) $r['unit_gross'], 'unit_net' => (int) $r['unit_net'],
        'vat_rate' => (float) $r['vat_rate'], 'line_net' => (int) $r['line_net'],
        'line_vat' => (int) $r['line_vat'], 'line_gross' => (int) $r['line_gross'],
        'backorder' => (int) ($r['backorder'] ?? 0),
    ], $st->fetchAll());

    $st = $pdo->prepare("SELECT * FROM wsm_shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$id]);
    $ship = $st->fetch() ?: null;

    $st = $pdo->prepare("SELECT * FROM wsm_payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$id]);
    $pay = $st->fetch() ?: null;

    return [
        'id' => $id, 'code' => (string) $o['code'], 'access_token' => (string) $o['access_token'],
        'lang' => (string) $o['lang'], 'currency' => (string) $o['currency'],
        'status' => (string) $o['status'], 'payment_status' => (string) $o['payment_status'],
        'email' => (string) $o['email'], 'phone' => (string) $o['phone'],
        'first_name' => (string) $o['first_name'], 'last_name' => (string) $o['last_name'],
        'company' => (string) $o['company'], 'nip' => (string) $o['nip'], 'invoice' => (int) $o['invoice'],
        'paragon_forma' => (string) ($o['paragon_forma'] ?? ''),
        'vat_eu' => (string) ($o['vat_eu'] ?? ''),
        'vat' => ['status' => (string) ($o['vat_status'] ?? ''), 'checked_at' => $o['vat_checked_at'] ?? null,
                  'name' => (string) ($o['vat_name'] ?? ''), 'consultation' => (string) ($o['vat_consultation'] ?? '')],
        'bill' => ['street' => $o['bill_street'], 'building' => $o['bill_building'],
                   'postcode' => $o['bill_postcode'], 'city' => $o['bill_city'], 'country' => $o['bill_country']],
        'delivery_method' => (string) $o['delivery_method'], 'inpost_point' => (string) $o['inpost_point'],
        'ship' => ['street' => $o['ship_street'], 'building' => $o['ship_building'],
                   'postcode' => $o['ship_postcode'], 'city' => $o['ship_city'], 'country' => $o['ship_country']],
        'items' => $items,
        'items_net' => (int) $o['items_net'], 'items_vat' => (int) $o['items_vat'], 'items_gross' => (int) $o['items_gross'],
        'shipping_net' => (int) $o['shipping_net'], 'shipping_vat' => (int) $o['shipping_vat'],
        'shipping_gross' => (int) $o['shipping_gross'],
        'total_net' => (int) $o['total_net'], 'total_vat' => (int) $o['total_vat'], 'total_gross' => (int) $o['total_gross'],
        'weight_g' => (int) $o['weight_g'], 'parcel_template' => (string) $o['parcel_template'],
        'reverse_charge' => (int) ($o['reverse_charge'] ?? 0) === 1,
        'backorder' => (int) ($o['backorder'] ?? 0) === 1,
        'discount_percent' => (float) ($o['discount_percent'] ?? 0),
        'discount_amount'  => (int) ($o['discount_amount'] ?? 0),
        // Le bon tel qu'il a agi sur CETTE commande. Il est relu d'ici et pas
        // de la table des bons : celui-ci peut avoir été retiré depuis, et la
        // facture doit rester explicable.
        'voucher_code'   => (string) ($o['voucher_code'] ?? ''),
        'voucher_amount' => (int) ($o['voucher_amount'] ?? 0),
        'note' => (string) ($o['note'] ?? ''),
        'created_at' => (string) $o['created_at'], 'paid_at' => $o['paid_at'],
        'shipment' => $ship ? ['service' => $ship['service'], 'target_point' => $ship['target_point'],
                               'parcel_template' => $ship['parcel_template'], 'status' => $ship['status'],
                               'tracking_number' => $ship['tracking_number'], 'label_url' => $ship['label_url']] : null,
        'payment' => $pay ? ['provider' => $pay['provider'], 'status' => $pay['status'],
                             'tr_id' => $pay['tr_id'], 'redirect_url' => $pay['redirect_url']] : null,
    ];
}

/** Marque une commande payée. Idempotent : rejouer la notification ne fait rien. */
function wsm_order_mark_paid(PDO $pdo, int $orderId, string $actor = 'stripe'): bool {
    // LE COLIS NE RECULE PAS. Cette ligne écrivait status='oplacone' sans
    // condition. Tant que l'argent arrivait avant la préparation — le cas de
    // tpay — ça ne se voyait pas. Mais un virement encaissé pendant que la
    // commande est « W realizacji », et le colis retournait en arrière : la
    // personne qui l'a préparé le voit réapparaître dans sa file, et le
    // prépare une seconde fois.
    //
    // UNE SEULE ÉCRITURE GAGNE. Le webhook de Stripe et la page de retour du
    // client arrivent souvent ensemble. Un « SELECT puis UPDATE » les laissait
    // passer tous les deux — sans gravité tant qu'il ne s'agissait que d'un
    // statut, mais chacun émet maintenant un document : deux paragons, deux
    // numéros, deux passages en caisse. La condition est donc DANS l'UPDATE,
    // et seul celui qui a vraiment changé la ligne continue.
    $u = $pdo->prepare("UPDATE wsm_orders
                      SET payment_status = 'oplacone',
                          status  = CASE WHEN status = 'nowe' THEN 'oplacone' ELSE status END,
                          paid_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND payment_status <> 'oplacone'");
    $u->execute([$orderId]);
    if ($u->rowCount() === 0) return false;                  // déjà encaissée (ou inconnue)
    // Les tentatives Stripe ne passent PAS en bloc à « opłacone » : seule la
    // session réellement payée l'est (wsm_stripe_apply_session). Une page
    // « Zapłać » rechargée trois fois ouvre trois sessions ; les marquer
    // toutes compterait trois fois la commission déjà prélevée (Platforma).
    $pdo->prepare("UPDATE wsm_payments SET status='oplacone' WHERE order_id=? AND provider <> 'stripe'")->execute([$orderId]);
    wsm_order_event($pdo, $orderId, 'oplacone', '', $actor);
    $paid = wsm_order_by_id($pdo, $orderId);
    if ($paid) wsm_mail_auto($pdo, 'platnosc', $paid);

    // LE DOCUMENT « À LA PAYE ». Le Superadmin propose d'émettre le document
    // quand la commande devient « opłacone » — mais seul wsm_order_status_set()
    // savait le faire, et le paiement ne passe pas par lui. Réglée ainsi, une
    // commande payée par Stripe n'avait JAMAIS de document, ni de paragon
    // fiscal : or c'est à la paye que la caisse doit enregistrer une vente
    // payée d'avance. wsm_order_document() est idempotente : passer ensuite
    // par wsm_order_status_set() ne refait rien.
    $inv = __DIR__ . '/invoice.php';
    if ($paid && is_file($inv)) {
        require_once $inv;
        $reg = wsm_orders_cfg();
        // Une commande ANNULÉE qu'un vieux lien de paiement fait encaisser
        // ne reçoit pas de document : c'est un remboursement à faire, pas
        // une vente à enregistrer.
        if ($reg['doc_status'] === 'oplacone' && (string) $paid['status'] !== 'anulowane') {
            try {
                // VIES seulement s'il y a un numéro à relire : appelé depuis le
                // webhook de Stripe, on n'ajoute pas un aller-retour inutile.
                if ($reg['vies_recheck'] && trim((string) ($paid['vat_eu'] ?? '')) !== '') {
                    $paid = wsm_order_vies_refresh($pdo, $paid);
                }
                wsm_order_document($pdo, $paid, $actor);
            } catch (Throwable $e) { /* le paiement reste écrit ; le document se refait à la main */ }
        }
    }
    // L'ACHAT ENVOYÉ À META PAR LE SERVEUR — seulement si la caisse a vu
    // l'accord « marketingowe », et sans jamais faire échouer l'encaissement
    // (reklama.php attrape tout).
    $rek = __DIR__ . '/reklama.php';
    if ($paid && is_file($rek)) {
        require_once $rek;
        wsm_capi_on_paid($pdo, $orderId);
    }
    return true;
}

/** Liste pour le back-office. */
/**
 * Les commandes qui ont dépassé le délai promis et ne sont pas parties.
 *
 * POURQUOI UNE REQUÊTE À PART. Filtrer le retour de wsm_orders_list() en PHP
 * paraît plus simple, et c'est faux : cette liste s'arrête aux 200 dernières
 * commandes. On y verrait donc les retards RÉCENTS, et jamais les anciens —
 * exactement ceux qu'on cherche quand on ouvre cette file.
 *
 * L'ORDRE EST INVERSÉ par rapport au reste de l'écran : le plus vieux retard
 * d'abord. Ailleurs on veut les nouveautés ; ici on veut ce qui pourrit.
 *
 * La comparaison se fait sur des chaînes « Y-m-d H:i:s », identiques chez
 * MySQL et chez SQLite, avec un seuil calculé sur l'horloge de la BASE — celle
 * qui a écrit paid_at.
 *
 * @param string $seuil paid_at antérieur à cette date = hors délai
 */
function wsm_orders_po_terminie(PDO $pdo, int $limit, array $statuts, string $seuil): array {
    $ok = array_values(array_intersect($statuts, WSM_ORDER_STATUSES));
    if (!$ok) return [];
    $st = $pdo->prepare("SELECT id FROM wsm_orders
                          WHERE status IN ('" . implode("','", $ok) . "')
                            AND " . wsm_sql_avec_date($pdo, 'paid_at') . " AND paid_at < ?
                            AND " . wsm_sql_sans_date($pdo, 'shipped_at') . "
                          ORDER BY paid_at ASC LIMIT " . max(1, min(1000, $limit)));
    $st->execute([$seuil]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) return [];
    // On repasse par wsm_orders_list pour l'hydratation : une deuxième
    // construction de ligne divergerait de la première au premier champ ajouté,
    // et c'est cette ligne-là que lisent les voyants.
    return wsm_orders_list($pdo, count($ids), $ok, $ids);
}

function wsm_orders_list(PDO $pdo, int $limit = 200, array $statuts = [], array $ids = []): array {
    // Le filtre est une LISTE BLANCHE d'états connus, jamais du texte reçu :
    // cette valeur vient d'un paramètre d'URL.
    $ok = array_values(array_intersect($statuts, WSM_ORDER_STATUSES));
    $ou = '';
    if ($ok) $ou = " WHERE status IN ('" . implode("','", $ok) . "')";
    if ($ids) {
        // DES IDENTIFIANTS PRÉCIS, DANS LEUR ORDRE. Appelée pour hydrater une
        // sélection déjà faite (les retards, triés du plus vieux), elle doit
        // rendre CES commandes-là et pas « les 200 dernières qui en font
        // partie » : un retard de trois mois est justement celui qui tombe
        // hors des 200 dernières.
        $ints = array_values(array_filter(array_map('intval', $ids)));
        if (!$ints) return [];
        $rows = $pdo->query("SELECT * FROM wsm_orders WHERE id IN ("
                            . implode(',', $ints) . ")")->fetchAll();
        $par = [];
        foreach ($rows as $r) $par[(int) $r['id']] = $r;
        $rows = [];
        foreach ($ints as $i) if (isset($par[$i])) $rows[] = $par[$i];
    } else {
        $rows = $pdo->query("SELECT * FROM wsm_orders$ou ORDER BY id DESC LIMIT "
                            . max(1, min(1000, $limit)))->fetchAll();
    }
    return array_map(function ($o) use ($pdo) {
        $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(qty),0) FROM wsm_order_items WHERE order_id = ?");
        $st->execute([(int) $o['id']]);
        [$lines, $units] = $st->fetch(PDO::FETCH_NUM);
        return [
            'id' => (int) $o['id'], 'code' => $o['code'], 'created_at' => $o['created_at'],
            // Le compte à rebours se lit sur ces deux dates. Absentes de la
            // liste, le voyant afficherait « brak wpłaty » sur toutes les
            // commandes, y compris payées : exactement la panne décrite
            // quelques lignes plus bas, avec un champ différent.
            'paid_at' => $o['paid_at'] ?? null, 'shipped_at' => $o['shipped_at'] ?? null,
            'client' => trim($o['first_name'] . ' ' . $o['last_name']) ?: $o['company'],
            'email' => $o['email'], 'phone' => $o['phone'],
            'status' => $o['status'], 'payment_status' => $o['payment_status'],
            'delivery_method' => $o['delivery_method'], 'inpost_point' => $o['inpost_point'],
            'lines' => (int) $lines, 'units' => (int) $units,
            'total_gross' => (int) $o['total_gross'], 'total_net' => (int) $o['total_net'],
            'weight_g' => (int) $o['weight_g'], 'parcel_template' => $o['parcel_template'],
            'invoice' => (int) $o['invoice'], 'nip' => $o['nip'],
            // ─── CE QUE LA LISTE DOIT PORTER POUR NE PAS MENTIR ───────────
            //
            // Ces trois champs manquaient, et les voyants de la liste lisent
            // exactement ceux-là. Résultat mesuré : une commande B2B au numéro
            // de TVA confirmé par VIES s'affichait « VIES — brak numeru VAT UE »
            // et annonçait un PARAGON… pendant que l'émission, elle, travaille
            // sur la commande complète et produisait une FACTURE déposée au
            // registre national. L'écran disait le contraire de ce qui allait
            // se passer, sans la moindre erreur nulle part.
            'company' => $o['company'] ?? '',
            'vat_eu' => $o['vat_eu'] ?? '', 'vat_status' => $o['vat_status'] ?? '',
            // Même leçon pour la caisse : sans lui, la ligne « Kasa » annonçait
            // un paragon papier à quelqu'un qui avait demandé l'e-paragon.
            'paragon_forma' => (string) ($o['paragon_forma'] ?? ''),
            'paid_at' => $o['paid_at'] ?? null,
            'backorder' => (int) ($o['backorder'] ?? 0) === 1,
            'discount_percent' => (float) ($o['discount_percent'] ?? 0),
        ];
    }, $rows);
}

/** Indicateurs de la boutique pour la console. */
function wsm_shop_kpis(PDO $pdo): array {
    $paid = "payment_status = 'oplacone'";
    $row = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(total_gross),0) s FROM wsm_orders")->fetch();
    $rowPaid = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(total_gross),0) s FROM wsm_orders WHERE $paid")->fetch();
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM wsm_orders WHERE payment_status = 'oczekuje'")->fetchColumn();
    $count = (int) $rowPaid['c'];
    return [
        'orders' => (int) $row['c'],
        'orders_paid' => $count,
        'orders_pending' => $pending,
        'revenue_gross' => (int) $rowPaid['s'],
        'basket_avg' => $count > 0 ? (int) round(((int) $rowPaid['s']) / $count) : 0,
    ];
}

// ---------------------------------------------------------------------------
//  LE CHANGEMENT D'ÉTAT D'UNE COMMANDE — À UN SEUL ENDROIT
//
//  Quatre endroits écrivaient « UPDATE wsm_orders SET status » : l'écran
//  Zamówienia, l'API, et les deux transporteurs après création du colis. Tant
//  que l'état n'était qu'un mot, ça allait. Il déclenche maintenant l'ÉMISSION
//  DU DOCUMENT — et une règle qui vit à quatre endroits est une règle qui ne
//  s'applique qu'à trois : la commande expédiée par le quatrième chemin
//  n'aurait jamais eu ni facture ni e-paragon, sans une erreur nulle part.
//
//  POURQUOI « wysłane » ET PAS LE PAIEMENT. Une commande payée peut encore
//  être annulée ou modifiée ; un document émis, non — il est figé, numéroté
//  dans une série continue, et déposé au registre national. On l'émet donc
//  quand la marchandise part, c'est-à-dire quand la vente est faite.
// ---------------------------------------------------------------------------

/**
 * Change l'état d'une commande, et fait ce que cet état entraîne.
 *
 * @return array{ok:bool, doc:?array, note:string}
 */
function wsm_order_status_set(PDO $pdo, int $id, string $new, string $actor = ''): array {
    $new = trim($new);
    $out = ['ok' => false, 'doc' => null, 'note' => ''];

    $st = $pdo->prepare("SELECT status FROM wsm_orders WHERE id = ?");
    $st->execute([$id]);
    $avant = (string) ($st->fetchColumn() ?: '');
    if ($avant === '') { $out['note'] = 'nie ma takiego zamówienia'; return $out; }
    // Une commande annulée ne repart pas : c'est la règle que les deux
    // transporteurs portaient chacun dans leur coin (« AND status <> 'anulowane' »).
    if ($avant === 'anulowane' && $new !== 'anulowane') {
        $out['note'] = 'zamówienie anulowane — stan nie zmieniony';
        return $out;
    }

    // ─── « PAYÉE » N'EST PAS UN MOT, C'EST DE L'ARGENT ───────────────────
    //
    // Écrire status = 'oplacone' ne faisait QUE ça : la commande se déclarait
    // payée, `payment_status` restait à « oczekuje » et `paid_at` à NULL. Une
    // commande qui ment sur son encaissement, c'est un colis qu'on expédie
    // sans avoir été payé, un compteur « en attente de paiement » faux sur le
    // tableau de bord, et une relance envoyée à quelqu'un qui a payé.
    //
    // Le webhook tpay, lui, a toujours bougé les deux champs ensemble
    // (wsm_order_mark_paid). On passe donc par la MÊME porte, quelle que soit
    // la main qui pousse : la liste, la fiche, ou un futur écran.
    //
    // ON NE SORT PAS D'ICI APRÈS L'ENCAISSEMENT. wsm_order_mark_paid() écrit
    // les deux champs mais n'émet aucun document — et « opłacone » est l'un
    // des états que le Superadmin peut choisir pour l'émission. Rendre la main
    // tout de suite aurait donc éteint la facture chez qui a réglé ce
    // paramètre-là, sans un mot. On encaisse, puis on continue.
    $paye = false;
    if ($new === 'oplacone') {
        $ps = $pdo->prepare("SELECT payment_status FROM wsm_orders WHERE id = ?");
        $ps->execute([$id]);
        if ((string) $ps->fetchColumn() !== 'oplacone') {
            wsm_order_mark_paid($pdo, $id, $actor !== '' ? $actor : 'ręcznie');
            $paye = true;
        }
    }

    $pdo->prepare("UPDATE wsm_orders SET status = ? WHERE id = ?")->execute([$new, $id]);
    // ─── LA DATE DE DÉPART, POSÉE UNE FOIS ────────────────────────────────
    //
    // C'est elle qui dit si le délai promis a été tenu. Sans elle, on sait
    // qu'une commande est partie, jamais si elle est partie à temps — et une
    // promesse dont on ne mesure pas la tenue n'en est pas une.
    //
    // `shipped_at IS NULL` dans la condition : une commande repassée par
    // « Wysłane » — parce qu'on a corrigé un statut, parce qu'un transporteur
    // a renvoyé son accusé deux fois — ne doit pas voir sa date de départ
    // avancer. Un retard effacé par une seconde manipulation, c'est un
    // indicateur qui s'auto-absout.
    if ($new === 'wyslane') {
        $pdo->prepare("UPDATE wsm_orders SET shipped_at = CURRENT_TIMESTAMP
                        WHERE id = ? AND " . wsm_sql_sans_date($pdo, 'shipped_at'))
            ->execute([$id]);
    }
    if ($paye) $out['note'] = 'zapłata odnotowana ręcznie';
    $out['ok'] = true;
    if ($avant === $new) return $out;              // rien de neuf : rien à émettre

    $inv = __DIR__ . '/invoice.php';
    if (is_file($inv)) require_once $inv;
    // QUEL ÉTAT ÉMET LE DOCUMENT est un réglage, avec « wysłane » pour défaut
    // et « nigdy » pour couper l'automat. Le défaut est lu dans le code : une
    // base vide doit se comporter comme hier.
    $reg = function_exists('wsm_orders_cfg') ? wsm_orders_cfg()
         : ['doc_status' => 'wyslane', 'vies_recheck' => true];
    if ($new === $reg['doc_status'] && $reg['doc_status'] !== 'nigdy') {
        if (is_file($inv)) {
            $order = wsm_order_by_id($pdo, $id);
            if ($order) {
                // VIES D'ABORD, DOCUMENT ENSUITE. Le numéro doit être vérifié
                // au moment de la LIVRAISON, pas à celui de la commande : il a
                // pu être révoqué entre les deux, et c'est le vendeur qui
                // paierait la TVA d'une facture en autoliquidation devenue
                // fausse. La consultation datée reste sur la commande.
                if ($reg['vies_recheck']) $order = wsm_order_vies_refresh($pdo, $order);
                // Elle ne lève jamais : une commande expédiée dont le document
                // échoue reste expédiée. Le colis est parti, on ne le rattrape
                // pas en refusant d'écrire un état.
                // On AJOUTE au message, on ne l'écrase pas : quand le
                // paiement vient d'être noté à la main ET que ce même état
                // émet le document, l'opérateur doit lire les deux.
                $deja = $out['note'] !== '' ? $out['note'] . ' · ' : '';
                try {
                    $d = wsm_order_document($pdo, $order, $actor);
                    $out['doc']  = $d['doc'];
                    $out['note'] = $deja . ($d['doc']
                        ? strtoupper((string) $d['kind']) . ' ' . (string) $d['doc']['number']
                          . ' — ' . $d['raison']
                          . ($d['mail'] ? ' · wysłano mailem' : '')
                          . ($d['ksef'] !== '' ? ' · KSeF: ' . $d['ksef'] : '')
                        : 'dokument nie powstał: ' . $d['raison']);
                } catch (Throwable $e) {
                    $out['note'] = $deja . 'stan zmieniony, dokument nie powstał';
                }
            }
        }
    }
    return $out;
}

/**
 * RECONSULTER VIES AVANT D'ÉMETTRE, et garder la preuve.
 *
 * Pour une livraison intracommunautaire en autoliquidation, le vendeur doit
 * avoir vérifié le numéro AU MOMENT DE LA LIVRAISON — et pouvoir le montrer.
 * Le numéro de consultation rendu par VIES est cette preuve. Un numéro valable
 * à la commande et révoqué avant l'expédition rend la facture fausse, et la
 * TVA reste due par le vendeur.
 *
 * ELLE NE BLOQUE JAMAIS L'EXPÉDITION. Si VIES ne répond pas, on garde ce qui
 * était écrit : arrêter la boutique parce qu'un service européen est en panne
 * serait lui faire porter une panne qui ne la regarde pas.
 *
 * @return array la commande, rechargée si quelque chose a changé
 */
function wsm_order_vies_refresh(PDO $pdo, array $order): array {
    $vat = trim((string) ($order['vat_eu'] ?? ''));
    if ($vat === '') return $order;                    // rien à vérifier

    $v = __DIR__ . '/vies.php';
    if (!is_file($v)) return $order;
    require_once $v;
    if (!function_exists('wsm_vies_check')) return $order;

    try {
        // `force` : on veut une consultation DATÉE D'AUJOURD'HUI, pas une
        // réponse en cache d'il y a trois semaines. C'est la date qui fait la
        // preuve, autant que le résultat.
        $r = wsm_vies_check($pdo, $vat, true);
        $st = (string) ($r['status'] ?? '');
        // Une panne ne doit pas EFFACER une réponse obtenue avant : on
        // n'écrit que si VIES a tranché.
        if ($st !== 'valid' && $st !== 'invalid') return $order;

        $cols = wsm_vies_columns($r);
        $pdo->prepare("UPDATE wsm_orders SET vat_status = ?, vat_checked_at = ?,
                              vat_name = ?, vat_consultation = ? WHERE id = ?")
            ->execute([$cols['vat_status'], $cols['vat_checked_at'],
                       $cols['vat_name'], $cols['vat_consultation'], (int) $order['id']]);
        return wsm_order_by_id($pdo, (int) $order['id']) ?: $order;
    } catch (Throwable $e) {
        return $order;
    }
}
