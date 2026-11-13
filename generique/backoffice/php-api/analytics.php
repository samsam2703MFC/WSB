<?php
// ============================================================================
//  analytics.php — la marge, le port, et ce que le mois va donner.
//
//  Un chiffre d'affaires ne dit pas si l'affaire gagne de l'argent. Trois
//  questions y répondent, et ce fichier ne calcule qu'elles :
//
//   1. MARŻA BRUTTO — ce qui reste du prix de vente HT une fois la matière
//      payée. Le coût est celui FIGÉ SUR LA LIGNE au moment de la vente
//      (wsm_order_items.unit_cost) : recalculer une marge de mars avec le prix
//      d'achat de juillet donnerait un chiffre faux et flatteur.
//   2. WYNIK PO DOSTAWIE — la même marge, diminuée de ce que la livraison a
//      coûté EN PLUS de ce que le client a payé pour elle. C'est là que part
//      l'argent d'une boutique en ligne, et c'est invisible sur un compte de
//      résultat mensuel.
//   3. POKRYCIE KOSZTU DOSTAWY — quel pourcentage du coût de transport le
//      client paie. 100 % : le port est neutre. 60 % : quatre złoty sur dix
//      sortent de la marge, à chaque colis.
//
//  DEUX HONNÊTETÉS QUI COMPTENT PLUS QUE LES CHIFFRES :
//
//   • On ne calcule une marge QUE sur les lignes dont le coût est connu. Un
//      produit sans prix de revient n'est pas compté comme une marge de 100 %
//      — il serait le seul à « rapporter » et fausserait tout. Chaque série
//      rapporte donc sa COUVERTURE : la part du chiffre d'affaires sur
//      laquelle le calcul a réellement pu se faire.
//   • Une prévision est une extrapolation, pas une promesse. Elle vaut ce que
//      vaut l'hypothèse « le reste du mois ressemblera au début », et l'écran
//      doit le dire. En début de mois, deux jours de données extrapolés sur
//      trente ne prédisent rien : la fonction renvoie sa fiabilité avec le
//      chiffre, pour que l'écran puisse se taire quand elle est faible.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
// wsm_grosze() vit dans shop.php. Ce fichier s'en servait DÉJÀ sans le dire,
// en comptant sur l'écran appelant pour l'avoir chargé — ce qui marche jusqu'au
// jour où on appelle une de ces fonctions depuis une vérification de
// déploiement, où rien d'autre n'est chargé. On le déclare.
require_once __DIR__ . '/shop.php';

/** Le taux de marge nette visé — la cible du plan, pas une observation. */
const WSM_VAL_TARGET_RATE = 0.15;

/** Le multiple appliqué au résultat annuel. Convention de place pour une
 *  petite boutique en ligne rentable : à discuter, jamais à croire sur parole. */
const WSM_VAL_MULTIPLE = 4.5;

/**
 * Ce que le transporteur nous facture pour chaque mode, en grosze HT.
 * À défaut de coût saisi, on prend le tarif vendu : le rapport vaut alors
 * 100 % par construction, ce que l'écran signale au lieu de le maquiller.
 *
 * @return array [id => ['cost' => int, 'price' => int, 'known' => bool]]
 */
function wsm_ship_costs(PDO $pdo): array {
    $out = [];
    try {
        $rows = $pdo->query("SELECT id, price_net, cost_net FROM wsm_shipping_methods")->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
    foreach ($rows as $r) {
        $cost = (int) ($r['cost_net'] ?? 0);
        $out[(string) $r['id']] = [
            'cost'  => $cost > 0 ? $cost : (int) $r['price_net'],
            'price' => (int) $r['price_net'],
            'known' => $cost > 0,
        ];
    }
    return $out;
}

/**
 * Marge, port et couverture, mois par mois.
 *
 * Seules les commandes ENCAISSÉES comptent : une marge sur une facture impayée
 * est une marge qu'on n'a pas. C'est la même règle que le graphique du chiffre
 * d'affaires — deux écrans qui compteraient différemment seraient pires que
 * pas d'écran du tout.
 *
 * @return array [['label','ym','revenue','cogs','margin','ship_paid','ship_cost',
 *                 'ship_gap','result','coverage_pct','cost_known_pct','orders'], …]
 */
function wsm_margin_series(PDO $pdo, int $months = 12): array {
    $ship = wsm_ship_costs($pdo);

    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $t = strtotime("first day of -$i month");
        $out[date('Y-m', $t)] = [
            'label' => date('m/y', $t), 'ym' => date('Y-m', $t),
            'revenue' => 0, 'cogs' => 0, 'margin' => 0,
            'ship_paid' => 0, 'ship_cost' => 0, 'ship_gap' => 0, 'result' => 0,
            'coverage_pct' => 0.0, 'cost_known_pct' => 0.0, 'orders' => 0,
            'revenue_costed' => 0,
        ];
    }

    $orders = $pdo->query("SELECT id, created_at, delivery_method, shipping_net, items_net
                             FROM wsm_orders
                            WHERE status <> 'anulowane' AND payment_status = 'oplacone'")->fetchAll() ?: [];
    if (!$orders) return array_values($out);

    // Les lignes en une passe : une requête par commande sur douze mois de
    // ventes rendrait cet écran inutilisable le jour où la boutique marche.
    $lines = [];
    $hasCost = in_array('unit_cost', wsm_table_columns($pdo, 'wsm_order_items'), true);
    $sql = $hasCost
        ? "SELECT order_id, product_id, qty, line_net, unit_cost FROM wsm_order_items"
        : "SELECT order_id, product_id, qty, line_net, 0 AS unit_cost FROM wsm_order_items";
    foreach ($pdo->query($sql)->fetchAll() ?: [] as $l) {
        $lines[(int) $l['order_id']][] = $l;
    }

    // Repli pour l'historique : les commandes passées AVANT que la colonne
    // existe n'ont pas de coût figé. Plutôt que de les exclure — ce qui
    // viderait le graphique — on retombe sur le prix de revient courant du
    // produit, et la couverture affichée dit sur quelle part on a pu calculer.
    $base = [];
    foreach ($pdo->query("SELECT id, base_cost FROM wsm_products")->fetchAll() ?: [] as $p) {
        $base[(string) $p['id']] = wsm_grosze($p['base_cost'] ?? 0);
    }

    foreach ($orders as $o) {
        $k = substr((string) $o['created_at'], 0, 7);
        if (!isset($out[$k])) continue;
        $out[$k]['orders']++;

        foreach ($lines[(int) $o['id']] ?? [] as $l) {
            $net = (int) $l['line_net'];
            $out[$k]['revenue'] += $net;
            $unit = (int) $l['unit_cost'];
            if ($unit <= 0) $unit = $base[(string) $l['product_id']] ?? 0;
            if ($unit <= 0) continue;                      // coût inconnu : hors du calcul de marge
            $out[$k]['cogs'] += $unit * (int) $l['qty'];
            $out[$k]['revenue_costed'] += $net;
        }

        $m = (string) $o['delivery_method'];
        $paid = (int) $o['shipping_net'];
        $cost = $ship[$m]['cost'] ?? $paid;
        $out[$k]['ship_paid'] += $paid;
        $out[$k]['ship_cost'] += $cost;
    }

    foreach ($out as $k => $r) {
        $out[$k]['margin']   = $r['revenue_costed'] - $r['cogs'];
        $out[$k]['ship_gap'] = $r['ship_cost'] - $r['ship_paid'];
        $out[$k]['result']   = $out[$k]['margin'] - $out[$k]['ship_gap'];
        $out[$k]['coverage_pct'] = $r['ship_cost'] > 0
            ? round($r['ship_paid'] / $r['ship_cost'] * 100, 1) : 0.0;
        $out[$k]['cost_known_pct'] = $r['revenue'] > 0
            ? round($r['revenue_costed'] / $r['revenue'] * 100, 1) : 0.0;
    }
    return array_values($out);
}

/**
 * La prévision du mois en cours, et le mois précédent pour la comparer.
 *
 * La méthode est délibérément simple et explicable : on prend ce qui est déjà
 * réalisé, on le divise par les jours écoulés, on multiplie par la longueur du
 * mois. Une régression sur douze points serait plus savante et pas plus juste
 * sur une boutique jeune — et personne ne pourrait vérifier le chiffre de
 * tête, ce qui est le meilleur moyen de ne jamais s'apercevoir qu'il est faux.
 *
 * La fiabilité n'est pas décorative : à deux jours écoulés, l'extrapolation
 * multiplie par quinze le hasard du week-end. L'écran s'en sert pour se taire.
 *
 * @return array ['prev'=>…, 'curr'=>…, 'forecast'=>…, 'elapsed','days','trust','delta_pct']
 */
function wsm_forecast(PDO $pdo, array $series): array {
    $ymCurr = date('Y-m');
    $ymPrev = date('Y-m', strtotime('first day of -1 month'));

    $find = function (string $ym) use ($series) {
        foreach ($series as $r) if ($r['ym'] === $ym) return $r;
        return null;
    };
    $curr = $find($ymCurr);
    $prev = $find($ymPrev);

    $days    = (int) date('t');
    $elapsed = max(1, (int) date('j'));
    $ratio   = $days / $elapsed;

    $mk = fn(?array $r, float $k) => [
        'revenue' => (int) round((int) ($r['revenue'] ?? 0) * $k),
        'margin'  => (int) round((int) ($r['margin'] ?? 0) * $k),
        'result'  => (int) round((int) ($r['result'] ?? 0) * $k),
        'orders'  => (int) round((int) ($r['orders'] ?? 0) * $k),
    ];

    $forecast = $mk($curr, $ratio);
    $realise  = $mk($curr, 1.0);
    $passe    = $mk($prev, 1.0);

    // Un mois entier extrapolé sur moins d'un quart de sa durée n'est pas une
    // prévision, c'est un pari. On le dit plutôt que d'afficher un chiffre net.
    $part = $elapsed / $days;
    $trust = $part >= 0.5 ? 'wysoka' : ($part >= 0.25 ? 'średnia' : 'niska');
    if (($curr['orders'] ?? 0) < 3) $trust = 'niska';

    $delta = ($passe['result'] ?? 0) != 0
        ? round(($forecast['result'] - $passe['result']) / abs($passe['result']) * 100, 1)
        : 0.0;

    return [
        'prev' => $passe, 'curr' => $realise, 'forecast' => $forecast,
        'elapsed' => $elapsed, 'days' => $days, 'trust' => $trust,
        'delta_pct' => $delta,
        'label_prev' => date('m/y', strtotime($ymPrev . '-01')),
        'label_curr' => date('m/y', strtotime($ymCurr . '-01')),
    ];
}

/**
 * Ce que vaut l'affaire, selon deux lectures — et le détail du calcul.
 *
 * Deux chiffres, une seule formule :
 *
 *   Objectif = CA annuel × 15 %  × 4,5      ← ce que ça vaudrait à la cible
 *   Actuelle = CA annuel × taux × 4,5      ← ce que ça vaut au taux observé
 *
 * Le taux « actuel » est la marge nette moyenne des douze mois : le résultat
 * après coût de la marchandise ET après la part du port non couverte par le
 * client, rapporté à la vente sur laquelle on a pu le mesurer. C'est le même
 * chiffre que le cartouche « wynik po dostawie », pas une variante maison.
 *
 * L'HONNÊTETÉ QUI COMPTE. Tant que le prix de revient manque sur la moitié du
 * catalogue, cette marge n'est pas mesurée, elle est devinée. Dans ce cas on
 * ne bricole pas une moyenne sur un échantillon trop mince : on prend le taux
 * cible de 15 % et on MARQUE le chiffre comme théorique. Une valorisation
 * présentée comme mesurée alors qu'elle est postulée est pire qu'une absence
 * de valorisation — elle se retrouve dans un business plan, puis en face
 * d'un investisseur.
 *
 * Le multiple de 4,5 est une convention de place pour une petite boutique en
 * ligne rentable ; il est ici pour être discuté, pas pour être cru.
 *
 * @return array ['revenue','target_rate','actual_rate','multiple',
 *                'target_value','actual_value','theoretical','coverage']
 */
function wsm_valuation(array $series, array $totals): array {
    $revenue = (int) ($totals['revenue'] ?? 0);          // CA net sur 12 mois

    // La part du CA sur laquelle la marge a réellement pu se calculer.
    $costed = 0;
    foreach ($series as $r) $costed += (int) ($r['revenue_costed'] ?? 0);
    $coverage = $revenue > 0 ? $costed / $revenue : 0.0;

    // Sous la moitié du chiffre d'affaires, la moyenne ne moyenne rien.
    $mesurable = $coverage >= 0.5 && $costed > 0;
    $rate = $mesurable ? ((int) ($totals['result'] ?? 0)) / $costed : WSM_VAL_TARGET_RATE;

    return [
        'revenue'      => $revenue,
        'target_rate'  => WSM_VAL_TARGET_RATE,
        'actual_rate'  => $rate,
        'multiple'     => WSM_VAL_MULTIPLE,
        'target_value' => (int) round($revenue * WSM_VAL_TARGET_RATE * WSM_VAL_MULTIPLE),
        'actual_value' => (int) round($revenue * $rate * WSM_VAL_MULTIPLE),
        'theoretical'  => !$mesurable,
        'coverage'     => round($coverage * 100, 1),
    ];
}

/** Les totaux des douze mois, pour les cartouches en haut d'écran. */
function wsm_margin_totals(array $series): array {
    $sum = fn(string $k) => array_sum(array_map(fn($r) => (int) $r[$k], $series));
    $rev  = $sum('revenue');
    $cost = $sum('ship_cost');
    $rc   = $sum('revenue_costed');
    return [
        'revenue'   => $rev,
        'margin'    => $sum('margin'),
        'ship_paid' => $sum('ship_paid'),
        'ship_cost' => $cost,
        'ship_gap'  => $sum('ship_gap'),
        'result'    => $sum('result'),
        'coverage_pct'   => $cost > 0 ? round($sum('ship_paid') / $cost * 100, 1) : 0.0,
        'margin_pct'     => $rc > 0 ? round($sum('margin') / $rc * 100, 1) : 0.0,
        'cost_known_pct' => $rev > 0 ? round($rc / $rev * 100, 1) : 0.0,
    ];
}

// ---------------------------------------------------------------------------
//  À PARTIR DE QUEL PANIER PEUT-ON OFFRIR LE PORT ?
//
//  La question se pose tous les jours et se répond au doigt mouillé : on met
//  « darmowa dostawa od 200 zł » parce que le voisin le fait. Or elle a une
//  réponse arithmétique, et une seule donnée manque pour la calculer — le taux
//  de marge. Il est déjà mesuré ici, mois par mois, sur les commandes
//  ENCAISSÉES et sur les seules lignes dont le prix de revient est connu.
//
//  LA FORMULE, ET POURQUOI ELLE EST CELLE-LÀ.
//
//    marchandise nette × taux de marge  =  ce que la commande rapporte
//    on veut que ça couvre le colis     =  ce que le transporteur nous facture
//
//    d'où :  seuil net = coût du colis ÷ taux
//
//  À ce seuil exact, la commande ne rapporte RIEN : toute la marge part dans
//  le colis. C'est rarement ce qu'on veut, d'où la « part gardée » : garder
//  30 % de la marge revient à diviser par 0,7 de plus.
//
//    seuil net = coût ÷ (taux × (1 − part gardée))
//
//  LE SEUIL SE COMPARE À UN MONTANT BRUT. wsm_shop_quote() teste
//  « itemsGross >= free_from » : rendre un seuil net le ferait atteindre trop
//  tôt de tout le montant de la TVA, et l'on offrirait le port en dessous du
//  point d'équilibre en croyant l'avoir calculé. On convertit donc, avec le
//  taux de TVA moyen du catalogue — et l'écran affiche les deux nombres et le
//  taux utilisé, pour que la conversion se vérifie de tête.
// ---------------------------------------------------------------------------

/** Sous cette couverture, une moyenne ne moyenne rien. Même règle que la valorisation. */
const WSM_FRANCO_COUVERTURE_MIN = 0.5;

/**
 * Le taux de marge à utiliser, et D'OÙ IL VIENT.
 *
 * Trois sources, de la plus solide à la plus faible, et l'écran doit pouvoir
 * dire laquelle a servi. Un seuil calculé sur une marge devinée est un seuil
 * deviné : le présenter comme calculé est exactement l'erreur que ce fichier
 * refuse ailleurs.
 *
 *   1. « sprzedaz » — mesurée sur les commandes encaissées. La seule qui vaut.
 *   2. « katalog »  — la marge théorique du catalogue (prix − prix de revient).
 *      Vraie sur le papier, muette sur ce qui se vend vraiment.
 *   3. « brak »     — rien à mesurer. On ne renvoie AUCUN taux, et l'écran
 *      demande à la place de le saisir. On ne comble pas un trou par 15 %.
 *
 * @return array{taux:float, source:string, couverture:float, base:int, produits:int}
 */
function wsm_marge_taux(PDO $pdo, array $series): array {
    $vide = ['taux' => 0.0, 'source' => 'brak', 'couverture' => 0.0, 'base' => 0, 'produits' => 0];

    // 1. Ce qui s'est vendu.
    $costed = 0; $marge = 0; $rev = 0;
    foreach ($series as $r) {
        $costed += (int) ($r['revenue_costed'] ?? 0);
        $marge  += (int) ($r['margin'] ?? 0);
        $rev    += (int) ($r['revenue'] ?? 0);
    }
    $couv = $rev > 0 ? $costed / $rev : 0.0;
    if ($costed > 0 && $couv >= WSM_FRANCO_COUVERTURE_MIN) {
        return ['taux' => $marge / $costed, 'source' => 'sprzedaz',
                'couverture' => $couv, 'base' => $costed, 'produits' => 0];
    }

    // 2. Ce qui est en rayon. On ne compte que les produits dont le prix de
    //    revient est renseigné — un produit sans coût n'est pas un produit à
    //    100 % de marge, c'est un produit qu'on ne sait pas juger.
    try {
        $rows = $pdo->query("SELECT prix, base_cost FROM wsm_products
                             WHERE active = 1 AND base_cost > 0 AND prix > 0")->fetchAll() ?: [];
    } catch (Throwable $e) { return $vide; }
    $prix = 0; $cout = 0;
    foreach ($rows as $p) {
        $prix += wsm_grosze($p['prix']);
        $cout += wsm_grosze($p['base_cost']);
    }
    if ($prix > 0 && $cout < $prix) {
        return ['taux' => ($prix - $cout) / $prix, 'source' => 'katalog',
                'couverture' => $couv, 'base' => $prix, 'produits' => count($rows)];
    }
    return $vide;
}

/** Le taux de TVA moyen du catalogue, pondéré par le prix. 0,23 à défaut. */
function wsm_vat_moyen(PDO $pdo): float {
    try {
        $rows = $pdo->query("SELECT prix, vat_rate FROM wsm_products WHERE active = 1 AND prix > 0")->fetchAll() ?: [];
    } catch (Throwable $e) { return 0.23; }
    $poids = 0; $somme = 0.0;
    foreach ($rows as $p) {
        $g = wsm_grosze($p['prix']);
        $poids += $g;
        $somme += $g * (float) $p['vat_rate'];
    }
    return $poids > 0 ? $somme / $poids : 0.23;
}

/**
 * Le panier minimum qui paie son propre colis.
 *
 * PURE : aucun accès base. C'est elle qu'on teste, et c'est elle qui décide
 * d'un chiffre qui partira sur la boutique.
 *
 * @param int   $coutNet  ce que le transporteur nous facture, en grosze HT
 * @param float $taux     marge, entre 0 et 1
 * @param float $garde    part de la marge qu'on veut CONSERVER (0 = équilibre)
 * @param float $vat      taux de TVA moyen, pour rendre un seuil brut
 * @return array{net:int, brut:int, possible:bool, raison:string}
 */
function wsm_franco_seuil(int $coutNet, float $taux, float $garde = 0.0, float $vat = 0.23): array {
    $non = fn(string $r) => ['net' => 0, 'brut' => 0, 'possible' => false, 'raison' => $r];
    if ($coutNet <= 0)                 return $non('koszt przesyłki nieznany');
    if ($taux <= 0)                    return $non('marża nieznana albo zerowa');
    if ($taux >= 1)                    return $non('marża powyżej 100 % — sprawdź ceny zakupu');
    if ($garde < 0 || $garde >= 1)     return $non('zatrzymywana część marży poza zakresem');

    $eff = $taux * (1 - $garde);
    if ($eff <= 0) return $non('nic nie zostaje na przesyłkę');

    // On ARRONDIT VERS LE HAUT : un seuil arrondi vers le bas offre le port un
    // grosz avant de l'avoir gagné, sur chaque commande, pour toujours.
    $net = (int) ceil($coutNet / $eff);
    return ['net' => $net, 'brut' => (int) ceil($net * (1 + $vat)),
            'possible' => true, 'raison' => ''];
}

/**
 * Ce qu'une commande AU SEUIL laisse réellement dans la caisse.
 *
 * Sert de contre-épreuve à l'écran : on affiche le seuil ET ce qu'il rapporte,
 * parce qu'un seuil sans son résultat se lit comme un gain alors qu'à part
 * gardée nulle il vaut exactement zéro.
 */
function wsm_franco_reste(int $seuilNet, float $taux, int $coutNet): int {
    return (int) round($seuilNet * $taux) - $coutNet;
}
