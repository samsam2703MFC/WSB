<?php
// ============================================================================
//  allegro.php — vendre AUSSI sur Allegro, sans se faire mal.
//
//  CE QUE CE FICHIER PEUT ET NE PEUT PAS FAIRE, DIT TOUT DE SUITE.
//
//  Allegro exige un compte vendeur et une application OAuth : identifiant
//  client, secret, et un jeton obtenu par redirection. Nous n'avons ni l'un
//  ni l'autre. Ce module est donc écrit FERMÉ : sans identifiants, rien ne
//  part, rien ne se crée, et l'écran le dit. C'est la même règle que tpay et
//  InPost — une intégration à moitié branchée est pire qu'une absente, parce
//  qu'on croit vendre.
//
//  CE QUI EST QUAND MÊME UTILE AUJOURD'HUI, et qui se prouve hors ligne :
//
//   1. LE PLAN DE STOCK. C'est le vrai danger d'un second canal : les mêmes
//      cinquante tablettes affichées ici ET là-bas se vendent CENT fois. On
//      ne publie donc jamais tout le stock — une réserve reste au magasin, et
//      la quantité publiable se calcule ici, avant tout appel réseau.
//   2. LA CORRESPONDANCE DES FICHES. Ce qu'Allegro attend (titre borné à
//      50 caractères, prix en zlotys avec deux décimales, EAN, quantité) se
//      construit et se vérifie sans compte : un titre coupé au mauvais
//      endroit ou un prix arrondi de travers se voient ici, pas après la
//      première vente.
//   3. CE QU'IL MANQUE POUR OUVRIR, écrit en toutes lettres.
//
//  QUATRE RÈGLES :
//
//   1. SANS IDENTIFIANTS, TOUT EST FERMÉ. `xxxx` compte pour non configuré.
//   2. ON NE PUBLIE JAMAIS TOUT LE STOCK. La réserve protège la boutique,
//      qui est le canal où la marge est entière.
//   3. UN PRODUIT SANS EAN NE PART PAS. Allegro le refuse, et découvrir ça
//      article par article après coup coûte une journée.
//   4. LE PRIX PUBLIÉ N'EST JAMAIS INFÉRIEUR AU PRIX BOUTIQUE. Allegro
//      prélève une commission ; publier moins cher qu'à la maison, c'est
//      payer pour vendre moins bien.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Ce qu'on garde au magasin, en pourcent du stock, jamais publié ailleurs. */
const WSM_ALLEGRO_RESERVE_PCT = 20.0;

/** En dessous de ce stock, on ne publie rien du tout. */
const WSM_ALLEGRO_MIN_STOCK = 3;

/** Allegro coupe les titres. On coupe nous-mêmes, proprement. */
const WSM_ALLEGRO_TITRE_MAX = 50;

/** La commission Allegro, pour le calcul du prix plancher. Indicative. */
const WSM_ALLEGRO_PROWIZJA_PCT = 12.0;

/** L'adresse de l'API, bac à sable ou production. */
function wsm_allegro_base(): string {
    return wsm_allegro_cfg()['sandbox']
        ? 'https://api.allegro.pl.allegrosandbox.pl'
        : 'https://api.allegro.pl';
}

/**
 * La configuration. `xxxx` est traité comme VIDE : c'est la valeur que porte
 * un champ de démonstration, et la prendre pour un identifiant ouvrirait une
 * intégration sur du vent.
 */
function wsm_allegro_cfg(): array {
    $c = wsm_config()['allegro'] ?? [];
    $net = function ($v): string {
        $v = trim((string) $v);
        return ($v === '' || strtolower($v) === 'xxxx') ? '' : $v;
    };
    return [
        'client_id'     => $net($c['client_id'] ?? ''),
        'client_secret' => $net($c['client_secret'] ?? ''),
        'refresh_token' => $net($c['refresh_token'] ?? ''),
        'seller_id'     => $net($c['seller_id'] ?? ''),
        'sandbox'       => !empty($c['sandbox']),
    ];
}

/** Peut-on parler à Allegro ? */
function wsm_allegro_enabled(): bool {
    $c = wsm_allegro_cfg();
    return $c['client_id'] !== '' && $c['client_secret'] !== '' && $c['refresh_token'] !== '';
}

/**
 * Ce qu'il manque pour ouvrir le canal, nommé.
 *
 * « Nie skonfigurowano » ne dit pas quoi faire. Une liste de trois champs,
 * si.
 */
function wsm_allegro_manquants(): array {
    $c = wsm_allegro_cfg();
    $out = [];
    if ($c['client_id'] === '')     $out[] = 'client_id — z panelu deweloperskiego Allegro';
    if ($c['client_secret'] === '') $out[] = 'client_secret — tamże, trzymany wyłącznie po stronie serwera';
    if ($c['refresh_token'] === '') $out[] = 'refresh_token — z jednorazowej autoryzacji konta sprzedawcy';
    if ($c['seller_id'] === '')     $out[] = 'seller_id — identyfikator konta sprzedawcy';
    return $out;
}

/**
 * COMBIEN ON PEUT PUBLIER SANS SE METTRE EN DANGER.
 *
 * C'est LE calcul de ce fichier. Les mêmes cinquante tablettes affichées
 * dans la boutique ET sur Allegro se vendent cent fois : le second acheteur
 * reçoit une excuse, et Allegro sanctionne les annulations du vendeur.
 *
 * On garde donc une réserve au magasin, et on ne publie rien sous un plancher
 * — trois unités qui partent en une heure ne valent pas le risque.
 *
 * @return array ['publikowalne'=>int, 'rezerwa'=>int, 'powod'=>string]
 */
function wsm_allegro_stock_plan(int $stock, float $reservePct = WSM_ALLEGRO_RESERVE_PCT): array {
    if ($stock <= 0) {
        return ['publikowalne' => 0, 'rezerwa' => 0, 'powod' => 'brak stanu magazynowego'];
    }
    if ($stock < WSM_ALLEGRO_MIN_STOCK) {
        return ['publikowalne' => 0, 'rezerwa' => $stock,
                'powod' => 'stan poniżej ' . WSM_ALLEGRO_MIN_STOCK . ' szt. — nie wystawiamy'];
    }
    // Une réserve absurde ne doit pas vider la publication ni la remplir.
    if ($reservePct < 0 || $reservePct > 90) $reservePct = WSM_ALLEGRO_RESERVE_PCT;

    $rezerwa = (int) ceil($stock * $reservePct / 100);
    $pub = max(0, $stock - $rezerwa);
    if ($pub <= 0) {
        return ['publikowalne' => 0, 'rezerwa' => $stock, 'powod' => 'cały stan zostaje w sklepie'];
    }
    return ['publikowalne' => $pub, 'rezerwa' => $rezerwa,
            'powod' => 'rezerwa ' . (int) $reservePct . ' % zostaje w sklepie'];
}

/**
 * Le prix plancher : ce en dessous de quoi publier fait perdre de l'argent.
 *
 * Allegro prélève une commission. Publier au prix de la boutique revient donc
 * à vendre moins cher qu'à la maison, sur le canal où l'on garde le client
 * en moins. Le plancher remonte le prix de la commission.
 */
function wsm_allegro_prix_plancher(int $prixBoutiqueGrosze, float $prowizja = WSM_ALLEGRO_PROWIZJA_PCT): int {
    if ($prixBoutiqueGrosze <= 0) return 0;
    if ($prowizja < 0 || $prowizja >= 100) $prowizja = WSM_ALLEGRO_PROWIZJA_PCT;
    return (int) ceil($prixBoutiqueGrosze / (1 - $prowizja / 100));
}

/**
 * Un titre d'annonce : borné, coupé sur un mot, jamais au milieu.
 *
 * « Czekolada ciemna 70 % — tabliczka rzemieślnicza 1 k » est une coupure qui
 * fait amateur. On coupe au dernier espace.
 */
function wsm_allegro_titre(string $nom): string {
    $t = trim(preg_replace('/\s+/u', ' ', $nom) ?? '');
    if (mb_strlen($t) <= WSM_ALLEGRO_TITRE_MAX) return $t;
    $coupe = mb_substr($t, 0, WSM_ALLEGRO_TITRE_MAX);
    $esp = mb_strrpos($coupe, ' ');
    // Une coupure trop tôt vaut moins qu'une coupure nette un peu plus loin.
    if ($esp !== false && $esp > WSM_ALLEGRO_TITRE_MAX * 0.6) $coupe = mb_substr($coupe, 0, $esp);
    return rtrim($coupe, " -–—,;:");
}

/**
 * Ce qui empêche un produit de partir sur Allegro, nommé.
 *
 * Règle 3 : un produit sans EAN est refusé par Allegro. Le découvrir article
 * par article après coup coûte une journée ; le voir en liste coûte une
 * minute.
 */
function wsm_allegro_blockers(array $p, array $plan): array {
    $out = [];
    if (trim((string) ($p['ean'] ?? '')) === '') $out[] = 'brak EAN — Allegro odrzuci ofertę';
    if (trim((string) ($p['nom'] ?? '')) === '') $out[] = 'brak nazwy';
    if ((int) ($p['prix_grosze'] ?? 0) <= 0) $out[] = 'brak ceny';
    if ((int) $plan['publikowalne'] <= 0) $out[] = $plan['powod'];
    return $out;
}

/**
 * La fiche telle qu'elle partirait chez Allegro.
 *
 * Construite MÊME QUAND L'INTÉGRATION EST FERMÉE : c'est ce qui permet de la
 * relire, de voir un titre coupé de travers ou un prix plancher au-dessus du
 * prix de vente, avant d'avoir un compte.
 */
function wsm_allegro_offer(array $p, float $reservePct = WSM_ALLEGRO_RESERVE_PCT): array {
    $stock = (int) ($p['stock'] ?? 0);
    $plan = wsm_allegro_stock_plan($stock, $reservePct);
    $prix = (int) ($p['prix_grosze'] ?? 0);
    $plancher = wsm_allegro_prix_plancher($prix);

    return [
        'productId'  => (string) ($p['id'] ?? ''),
        'name'       => wsm_allegro_titre((string) ($p['nom'] ?? '')),
        'ean'        => trim((string) ($p['ean'] ?? '')),
        // Allegro veut des zlotys en chaîne, deux décimales, point décimal.
        'sellingMode' => ['price' => ['amount' => number_format(max($prix, $plancher) / 100, 2, '.', ''),
                                      'currency' => 'PLN']],
        'stock'      => ['available' => $plan['publikowalne'], 'unit' => 'UNIT'],
        'publication' => ['status' => $plan['publikowalne'] > 0 ? 'ACTIVE' : 'INACTIVE'],
        // Ce qui ne part pas chez Allegro mais sert à l'écran.
        '_plan'      => $plan,
        '_plancher'  => $plancher,
        '_prix_sklep' => $prix,
        '_blockers'  => wsm_allegro_blockers($p, $plan),
    ];
}

/**
 * Le catalogue tel qu'il partirait, avec ce qui bloque.
 *
 * @return array [['offer'=>array, 'pret'=>bool], …]
 */
function wsm_allegro_plan(PDO $pdo, float $reservePct = WSM_ALLEGRO_RESERVE_PCT): array {
    try {
        $rows = $pdo->query("SELECT id, nom, ean, prix, stock
                               FROM wsm_products
                              WHERE active = 1 AND shop_visible = 1
                           ORDER BY nom")->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }

    $out = [];
    foreach ($rows as $r) {
        $p = ['id' => (string) $r['id'], 'nom' => (string) $r['nom'], 'ean' => (string) ($r['ean'] ?? ''),
              'prix_grosze' => (int) round(((float) $r['prix']) * 100), 'stock' => (int) $r['stock']];
        $o = wsm_allegro_offer($p, $reservePct);
        $out[] = ['offer' => $o, 'pret' => $o['_blockers'] === []];
    }
    return $out;
}

/** Combien seraient publiables, combien bloqués, et pourquoi. */
function wsm_allegro_kpis(array $plan): array {
    $k = ['produktow' => 0, 'gotowych' => 0, 'zablokowanych' => 0, 'sztuk' => 0, 'przyczyny' => []];
    foreach ($plan as $x) {
        $k['produktow']++;
        if ($x['pret']) { $k['gotowych']++; $k['sztuk'] += (int) $x['offer']['stock']['available']; }
        else {
            $k['zablokowanych']++;
            foreach ($x['offer']['_blockers'] as $b) {
                $k['przyczyny'][$b] = ($k['przyczyny'][$b] ?? 0) + 1;
            }
        }
    }
    arsort($k['przyczyny']);
    return $k;
}

/**
 * Le jeton d'accès, échangé contre le refresh_token.
 *
 * FERMÉ SANS IDENTIFIANTS : on ne tente même pas l'appel. Un échec réseau et
 * une absence de configuration ne se ressemblent pas, et les confondre fait
 * chercher une panne là où il n'y a qu'un champ vide.
 *
 * @return array [jeton|'', message]
 */
function wsm_allegro_token(): array {
    if (!wsm_allegro_enabled()) {
        return ['', 'Allegro nie jest skonfigurowane: ' . implode(' · ', wsm_allegro_manquants())];
    }
    $c = wsm_allegro_cfg();
    $ch = curl_init('https://allegro.pl/auth/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERPWD => $c['client_id'] . ':' . $c['client_secret'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token', 'refresh_token' => $c['refresh_token'],
        ]),
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code >= 400) {
        return ['', 'Allegro odrzuciło autoryzację (' . $code . ') ' . $err];
    }
    $j = json_decode((string) $body, true);
    $t = (string) ($j['access_token'] ?? '');
    return $t !== '' ? [$t, 'ok'] : ['', 'Allegro nie zwróciło tokenu'];
}
