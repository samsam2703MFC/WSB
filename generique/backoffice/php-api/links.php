<?php
// ============================================================================
//  links.php — les liens directs, et ce qu'ils rapportent VRAIMENT.
//
//  LE PROBLÈME QU'ILS RÉSOLVENT. On partage un lien — dans un courriel, sur
//  Facebook, au dos d'une carte glissée dans un colis — et trois mois plus
//  tard personne ne sait s'il a vendu quoi que ce soit. On reconduit alors
//  la campagne « parce qu'elle marchait bien », ce qui est une opinion.
//
//  UN LIEN FAIT TROIS CHOSES, ET C'EST TOUT :
//
//   1. il emmène quelque part (un produit, le panier, la boutique) ;
//   2. il peut poser un code de réduction, pour que l'offre annoncée dans le
//      message soit celle vue à l'écran — sinon le visiteur cherche où taper
//      son code et referme l'onglet ;
//   3. il se COMPTE : clics, puis commandes et chiffre d'affaires réellement
//      encaissés. Un clic n'est pas une vente, et les deux nombres sont
//      affichés séparément.
//
//  CE QU'IL NE FAIT PAS : suivre les gens. Aucun cookie de pistage, aucun
//  identifiant personnel. On enregistre la SOURCE sur la commande, comme on
//  noterait « vu en vitrine » sur un ticket — pas le trajet d'un individu.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Où un lien peut mener. Rien d'autre n'est accepté. */
const WSM_LINK_CIBLES = [
    'sklep'   => 'Sklep (katalog)',
    'produkt' => 'Karta produktu',
    'koszyk'  => 'Koszyk z produktem',
];

/** Les caractères du code : lisibles à voix haute et dans une adresse. */
const WSM_LINK_ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789';

/** Le paramètre porté par l'adresse. Court : il se recopie à la main. */
const WSM_LINK_PARAM = 'l';

/** La source retenue au plus sur une commande, en caractères. */
const WSM_LINK_SOURCE_MAX = 40;

/** Tables et colonnes. Idempotent. */
function wsm_links_ensure(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_links')) wsm_apply_schema($pdo);
    wsm_ensure_columns($pdo, 'wsm_orders', [
        // D'où vient cette commande. FIGÉE : le lien peut être renommé ou
        // retiré demain, la commande doit rester attribuable.
        'source' => ["VARCHAR(40) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
    ]);
}

/** Un code neuf, absent de la base. */
function wsm_link_code(PDO $pdo, int $len = 6): string {
    for ($essai = 0; $essai < 40; $essai++) {
        $c = '';
        for ($i = 0; $i < $len; $i++) $c .= WSM_LINK_ALPHABET[random_int(0, strlen(WSM_LINK_ALPHABET) - 1)];
        $st = $pdo->prepare("SELECT 1 FROM wsm_links WHERE code = ?");
        $st->execute([$c]);
        if (!$st->fetchColumn()) return $c;
    }
    return substr(bin2hex(random_bytes(6)), 0, $len);
}

/** Normalise une source venue de l'extérieur : elle finira dans une colonne. */
function wsm_link_norm(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9_.-]/', '', $s) ?? '';
    return substr($s, 0, WSM_LINK_SOURCE_MAX);
}

/**
 * Crée un lien.
 *
 * @return array [code|'', message]
 */
function wsm_link_create(PDO $pdo, array $in, string $actor): array {
    $cible = (string) ($in['cible'] ?? 'sklep');
    if (!isset(WSM_LINK_CIBLES[$cible])) return ['', 'Nieznany cel linku.'];

    $nom = trim((string) ($in['nom'] ?? ''));
    if ($nom === '') return ['', 'Nazwij link — inaczej za miesiąc nikt nie będzie wiedział, co to było.'];

    $produkt = trim((string) ($in['produkt'] ?? ''));
    if (in_array($cible, ['produkt', 'koszyk'], true)) {
        if ($produkt === '') return ['', 'Wybierz produkt.'];
        $st = $pdo->prepare("SELECT 1 FROM wsm_products WHERE id = ? AND active = 1 AND shop_visible = 1");
        $st->execute([$produkt]);
        if (!$st->fetchColumn()) return ['', 'Ten produkt nie jest widoczny w sklepie — link prowadziłby donikąd.'];
    } else {
        $produkt = '';
    }

    // Le code de réduction est VÉRIFIÉ maintenant : un lien qui promet une
    // remise inexistante fait passer la boutique pour cassée.
    $kod = strtoupper(trim((string) ($in['kod'] ?? '')));
    if ($kod !== '') {
        $promo = __DIR__ . '/promo.php';
        if (is_file($promo)) {
            require_once $promo;
            $b = wsm_promo_find($pdo, $kod);
            if (!$b) return ['', 'Nie ma kodu ' . $kod . '. Najpierw utwórz go w Rabatach.'];
            if (!(int) ($b['active'] ?? 1)) return ['', 'Kod ' . $kod . ' jest wycofany.'];
            $kod = (string) $b['code'];
        }
    }

    $code = wsm_link_norm((string) ($in['code'] ?? '')) ?: wsm_link_code($pdo);
    try {
        $pdo->prepare("INSERT INTO wsm_links (code, nom, cible, produkt, kod, klikniec, active, created_at)
                       VALUES (?,?,?,?,?,0,1,?)")
            ->execute([$code, mb_substr($nom, 0, 190), $cible, $produkt, $kod, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        $m = $e->getMessage();
        if (str_contains($m, 'UNIQUE') || str_contains($m, 'Duplicate')) return ['', 'Ten adres jest już zajęty.'];
        return ['', 'Nie udało się zapisać: ' . $m];
    }
    if (function_exists('wsm_audit')) wsm_audit($pdo, $actor, 'Nowy link', 'wsm_links ' . $code, 'Sieć');
    return [$code, 'Link gotowy.'];
}

/** Le lien portant ce code, s'il est actif. */
function wsm_link_find(PDO $pdo, string $code): ?array {
    $c = wsm_link_norm($code);
    if ($c === '') return null;
    try {
        $st = $pdo->prepare("SELECT * FROM wsm_links WHERE code = ? AND active = 1");
        $st->execute([$c]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * Compte un clic. Le compteur monte, rien d'autre n'est retenu.
 *
 * Aucun cookie de pistage, aucune adresse IP, aucun identifiant : on veut
 * savoir si UN LIEN a servi, pas qui l'a cliqué.
 */
function wsm_link_hit(PDO $pdo, string $code): void {
    try {
        $pdo->prepare("UPDATE wsm_links SET klikniec = klikniec + 1 WHERE code = ?")
            ->execute([wsm_link_norm($code)]);
    } catch (Throwable $e) { /* un compteur ne fait jamais échouer une page */ }
}

/**
 * Ce que chaque lien a rapporté.
 *
 * CLICS ET VENTES SONT DEUX COLONNES, jamais un taux unique : mille clics
 * sans vente et dix clics avec deux ventes racontent deux histoires
 * différentes, et un pourcentage les confond.
 */
function wsm_links_list(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT * FROM wsm_links ORDER BY active DESC, id DESC")->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }

    $out = [];
    foreach ($rows as $l) {
        $st = $pdo->prepare("SELECT COUNT(*) AS n,
                                    COALESCE(SUM(CASE WHEN payment_status = 'oplacone'
                                                 THEN total_gross ELSE 0 END),0) AS ca
                               FROM wsm_orders WHERE source = ?");
        try { $st->execute([(string) $l['code']]); $k = $st->fetch() ?: ['n' => 0, 'ca' => 0]; }
        catch (Throwable $e) { $k = ['n' => 0, 'ca' => 0]; }
        $out[] = $l + [
            'zamowien' => (int) $k['n'], 'obrot' => (int) $k['ca'],
            'cible_label' => WSM_LINK_CIBLES[(string) $l['cible']] ?? (string) $l['cible'],
        ];
    }
    return $out;
}

/** Retire un lien de la circulation. Les commandes gardent leur source. */
function wsm_link_disable(PDO $pdo, int $id, string $actor): array {
    $st = $pdo->prepare("SELECT code FROM wsm_links WHERE id = ?");
    $st->execute([$id]);
    $code = (string) $st->fetchColumn();
    if ($code === '') return [false, 'Nie znaleziono linku.'];
    $pdo->prepare("UPDATE wsm_links SET active = 0 WHERE id = ?")->execute([$id]);
    if (function_exists('wsm_audit')) wsm_audit($pdo, $actor, 'Wycofanie linku', 'wsm_links ' . $code, 'Sieć');
    return [true, 'Wycofano link. Zamówienia, które z niego przyszły, zachowują swoje źródło.'];
}

/** L'adresse complète à copier-coller. */
function wsm_link_url(string $base, string $code): string {
    return rtrim($base, '/') . '/?' . WSM_LINK_PARAM . '=' . rawurlencode($code);
}
