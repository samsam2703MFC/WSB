<?php
// ============================================================================
//  metody.php — ce que la boutique MONTRE de ses modes de livraison et de
//  paiement : les logos du pied de page et de la fiche produit.
//
//  L'équipe voulait, comme sur les boutiques qu'elle connaît, « jakie formy ma
//  klient dostępne » : InPost, DPD, BLIK, cartes… sous les yeux avant d'acheter.
//
//  UN LOGO EST UNE PROMESSE. Montrer « DPD Pickup » quand la caisse ne le
//  propose pas, c'est faire chercher au client une option qui n'existe pas. Par
//  défaut, la liste suit donc la réalité : les modes de livraison ACTIFS
//  (Dostawa), et pour le paiement, ce que Stripe encaisse quand la boutique
//  peut encaisser — sinon rien : sans Stripe prêt, aucun moyen ne marche, et
//  il n'y a plus de virement (tout passe par Stripe). Integracje → Sklep
//  permet de choisir à la main ; ce choix-là fait foi, y compris « rien ».
// ============================================================================
declare(strict_types=1);

const WSM_METODY_WYSYLKI = [
    'inpost_paczkomat' => 'Paczkomat InPost',
    'inpost_kurier'    => 'Kurier InPost',
    'dpd_kurier'       => 'Kurier DPD',
    'dpd_pickup'       => 'DPD Pickup',
];

const WSM_METODY_PLATNOSCI = [
    'blik'           => 'BLIK',
    'visa'           => 'Visa',
    'mastercard'     => 'Mastercard',
    'applepay'       => 'Apple Pay',
    'googlepay'      => 'Google Pay',
    'przelew_online' => 'Przelewy24',
];

/** Le mode de livraison de la base → le logo qui le représente. */
const WSM_METODY_Z_DOSTAWY = [
    'inpost_locker'  => 'inpost_paczkomat',
    'inpost_courier' => 'inpost_kurier',
    'dpd_courier'    => 'dpd_kurier',
    'dpd_pickup'     => 'dpd_pickup',
];

/** La liste des codes d'un côté : 'wysylka' ou 'platnosc'. */
function wsm_metody_slownik(string $co): array {
    return $co === 'wysylka' ? WSM_METODY_WYSYLKI : WSM_METODY_PLATNOSCI;
}

/** Le type de champ de la console : « multi:code:Libellé|… ». */
function wsm_metody_typ(string $co): string {
    $out = [];
    foreach (wsm_metody_slownik($co) as $k => $l) $out[] = $k . ':' . $l;
    return 'multi:' . implode('|', $out);
}

/**
 * Ce qui s'affiche, par défaut, quand personne n'a choisi.
 *  · livraison : les modes actifs de la base, dans leur ordre ;
 *  · paiement  : Stripe prêt → ses moyens courants en Pologne ; sinon rien.
 */
function wsm_metody_auto(PDO $pdo, string $co): array {
    if ($co === 'wysylka') {
        $out = [];
        try {
            foreach ($pdo->query("SELECT id FROM wsm_shipping_methods WHERE active = 1 ORDER BY sort_order, id")->fetchAll() ?: [] as $r) {
                $c = WSM_METODY_Z_DOSTAWY[(string) $r['id']] ?? null;
                if ($c !== null && !in_array($c, $out, true)) $out[] = $c;
            }
        } catch (Throwable $e) {}
        return $out;
    }
    if (!function_exists('wsm_stripe_ready')) { $f = __DIR__ . '/stripe.php'; if (is_file($f)) require_once $f; }
    $stripe = function_exists('wsm_stripe_ready') && wsm_stripe_ready();
    return $stripe ? ['blik', 'visa', 'mastercard', 'googlepay', 'applepay', 'przelew_online'] : [];
}

/**
 * Les codes à montrer, dans l'ordre du dictionnaire. Le réglage de la console
 * gagne : une liste de codes, ou « brak » pour ne rien montrer. Vide ou masque
 * (« xxxx ») : le défaut ci-dessus.
 */
function wsm_metody(PDO $pdo, string $co): array {
    $cle = $co === 'wysylka' ? 'metody_wysylki' : 'metody_platnosci';
    $v = trim((string) (wsm_config()['shop'][$cle] ?? ''));
    if ($v === 'brak') return [];
    if ($v === '' || preg_match('/^x{2,}$/i', $v)) return wsm_metody_auto($pdo, $co);
    $choisis = array_map('trim', explode(',', $v));
    return array_values(array_filter(array_keys(wsm_metody_slownik($co)), fn($k) => in_array($k, $choisis, true)));
}
