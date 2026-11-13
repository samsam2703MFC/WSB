<?php
// ============================================================================
//  platform.php — ce que la boutique doit au propriétaire de la plateforme.
//
//  Deux lignes de revenu, et rien d'autre :
//
//    CZYNSZ      — la location. Un montant fixe par mois, dû que la boutique
//                  vende ou non. C'est le loyer de l'outil.
//    PROWIZJA    — 15 % du volume brut encaissé dans le mois. Elle suit
//                  l'activité : pas de vente, pas de commission.
//
//  QUATRE RÈGLES, DANS L'ORDRE D'IMPORTANCE :
//
//   1. LE SUPERADMIN N'EST PAS UN RÔLE DE LA BASE. Ce module chiffre ce que
//      la boutique doit à son propriétaire. Si le droit d'y entrer était une
//      colonne ou une case à cocher, un compte Centrala compromis — ou
//      simplement curieux — pourrait se l'attribuer et réécrire sa propre
//      facture. L'identité vient du fichier de configuration du serveur, que
//      la console ne sait pas écrire. Sans liste, le module n'existe pas :
//      absent du rail, 404 sur la page.
//
//   2. UN DÉCOMPTE ÉMIS EST FIGÉ. Il recopie le volume, le taux, le loyer et
//      la TVA au moment de l'émission. Changer le taux en mars ne doit pas
//      réécrire la note de février — c'est la même règle que les factures et
//      que le coût figé sur une ligne de commande. Un décompte qu'on peut
//      recalculer après coup n'est pas un décompte, c'est une estimation.
//
//   3. ON NE COMPTE QUE L'ENCAISSÉ. Une commande facturée mais impayée n'a
//      rapporté d'argent à personne. Les commandes annulées sortent, les
//      remboursements aussi. C'est exactement la règle du chiffre d'affaires
//      dans l'Audyt : deux écrans qui compteraient différemment seraient pires
//      que pas d'écran du tout.
//
//   4. LE PORT EST MONTRÉ À PART. « Volume brut » pris au pied de la lettre
//      inclut les frais de livraison, qui sont un coût qui transite : les
//      facturer à 15 % prend de l'argent qui n'a jamais été une marge. On ne
//      tranche pas à la place du propriétaire — on applique la base choisie,
//      on affiche TOUJOURS la part du port, et on laisse basculer d'un clic.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Le taux par défaut : 15 % du volume brut. */
const WSM_PLAT_RATE = 0.15;

/** La TVA appliquée par défaut au décompte (le propriétaire facture la boutique). */
const WSM_PLAT_VAT = 0.23;

/** Les deux assiettes possibles pour la commission. */
const WSM_PLAT_BASES = [
    'brutto' => 'Cały obrót brutto (towar + dostawa)',
    'towar'  => 'Sam towar brutto (bez dostawy)',
];

/** Les trois états d'un décompte. */
const WSM_PLAT_STATUSES = ['szkic', 'wystawione', 'oplacone'];

/**
 * Un montant saisi à l'écran → des grosze.
 *
 * Local et non emprunté à shop.php : ce module ne dépend que de la base, et
 * charger le moteur de boutique pour une conversion serait une dépendance
 * qu'on paierait à chaque page.
 *
 * SURTOUT, la virgule est acceptée. Un clavier polonais écrit « 450,00 », et
 * `(float) '450,00'` vaut 450.0 en PHP : les grosze disparaîtraient sans un
 * mot d'avertissement, sur un montant qui part en facture.
 */
function wsm_plat_grosze($saisi): int {
    $s = str_replace([' ', "\u{202F}", "\u{00A0}"], '', (string) $saisi);
    $s = str_replace(',', '.', $s);
    return (int) round(((float) $s) * 100);
}

// ---------------------------------------------------------------------------
//  Qui a le droit d'entrer
// ---------------------------------------------------------------------------

/**
 * Les adresses superadmin, lues UNIQUEMENT dans la configuration du serveur.
 *
 * Jamais depuis la base : voir la règle 1 en tête de fichier. Une liste vide
 * ferme le module — c'est le comportement voulu, pas une panne.
 *
 * @return string[] adresses en minuscules
 */
function wsm_superadmin_emails(): array {
    $cfg = wsm_config();
    $raw = trim((string) ($cfg['superadmin_emails'] ?? ''));
    if ($raw === '') return [];
    $out = [];
    foreach (preg_split('/[,;\s]+/', $raw) as $e) {
        $e = strtolower(trim($e));
        // « xxxx » est la marque de « pas encore renseigné » partout dans ce
        // projet : elle ne doit pas ouvrir une porte par accident.
        if ($e === '' || $e === 'xxxx' || !str_contains($e, '@')) continue;
        $out[] = $e;
    }
    return $out;
}

// ---------------------------------------------------------------------------
//  LE CODE DU JOUR — un second verrou sur l'écran de la plateforme
//
//  L'écran est déjà derrière la connexion ET le rôle Superadmin. Ce code est
//  un verrou de PLUS, demandé pour que la facturation de la plateforme ne
//  s'ouvre pas d'un simple clic sur une session restée ouverte.
//
//  LE NOMBRE DE BASE NE VIT PAS DANS CE DÉPÔT, et ce n'est pas négociable :
//  le dépôt est PUBLIC. Un code écrit ici serait lisible par n'importe qui
//  sur GitHub dans la minute — il ne verrouillerait donc rien du tout. Il se
//  règle par WSM_SUPERADMIN_CODE, comme tous les autres secrets.
//
//  CE QUE CE CODE VAUT, DIT FRANCHEMENT. Six chiffres fixes plus un chiffre
//  qui suit le jour de la semaine, ce n'est pas un secret fort : la partie
//  qui tourne ne rajoute que sept possibilités, et qui voit le code un lundi
//  connaît celui du mardi. C'est un verrou contre l'ouverture distraite et
//  contre l'écran laissé sans surveillance — pas contre quelqu'un qui
//  cherche. D'où le comptage des essais : sans lui, sept chiffres se
//  devinent en quelques secondes.
//
//  SANS CODE CONFIGURÉ, LE VERROU NE S'APPLIQUE PAS. Le fermer par défaut
//  rendrait l'écran inatteignable pour toujours — exactement l'impasse qu'on
//  vient de réparer sur superadmin_emails.
// ---------------------------------------------------------------------------
const WSM_SUPER_CODE_MAX  = 5;      // essais avant pause
const WSM_SUPER_CODE_LOCK = 900;    // 15 min, comme la page de connexion

/**
 * Ce compte peut-il entrer PAR LE CODE ?
 *
 * Le rôle Superadmin et la liste du serveur restent les deux voies nobles.
 * Celle-ci est la troisième, et c'est la seule qui n'exige rien d'autre que
 * de connaître le code du jour — donc la seule qui rende l'entrée visible
 * quand aucun compte ne porte encore le rôle.
 *
 * ELLE NE S'OUVRE QU'AUX ADMINISTRATEURS. Pas à la vente, pas au magasin,
 * pas à la lecture seule : le code se voit par-dessus une épaule, et cet
 * écran chiffre ce que la boutique doit à qui la lui loue. Restreindre à
 * l'administrateur ne rend pas le code fort — ça réduit le nombre de gens à
 * qui il suffit de le voir passer.
 */
function wsm_super_par_code(?array $u): bool {
    if (!$u || !empty($u['service'])) return false;      // jamais un jeton
    if (!wsm_super_code_actif()) return false;           // pas de code = pas de voie
    if (!function_exists('wsm_role_de')) return false;
    return in_array(wsm_role_de($u), [WSM_ROLE_SUPERADMIN, WSM_ROLE_ADMIN], true);
}

/** Le code a-t-il été donné AUJOURD'HUI dans cette session ? */
function wsm_super_code_donne(): bool {
    return ($_SESSION['wsm_super_code_jour'] ?? '') === date('Y-m-d');
}

/** Le nombre de base, ou '' s'il n'est pas configuré. */
function wsm_super_code_base(): string {
    $v = trim((string) (wsm_config()['superadmin_code'] ?? ''));
    if ($v === '' || strtolower($v) === 'xxxx') return '';
    return preg_match('/^[0-9]{4,12}$/', $v) ? $v : '';
}

/** Le verrou est-il en service ? */
function wsm_super_code_actif(): bool { return wsm_super_code_base() !== ''; }

/**
 * Le code attendu AUJOURD'HUI : la base, suivie du jour de la semaine.
 * Lundi = 1 … dimanche = 7 (norme ISO, celle de `date('N')`).
 */
function wsm_super_code_attendu(?int $quand = null): string {
    $base = wsm_super_code_base();
    return $base === '' ? '' : $base . date('N', $quand ?? time());
}

/**
 * La comparaison. hash_equals plutôt que === : un test qui s'arrête au
 * premier chiffre faux laisse mesurer combien de chiffres sont bons.
 */
function wsm_super_code_ok(string $saisi): bool {
    $attendu = wsm_super_code_attendu();
    if ($attendu === '') return true;                 // verrou non configuré
    return hash_equals($attendu, preg_replace('/[^0-9]/', '', $saisi) ?? '');
}

/** Le module est-il configuré du tout ? */
function wsm_platform_enabled(): bool {
    return wsm_superadmin_emails() !== [];
}

/**
 * Cet utilisateur est-il le propriétaire de la plateforme ?
 *
 * Un jeton de service ne suffit PAS. Le jeton sert à l'automatisation et il
 * vaut « Centrala » : lui donner le superadmin reviendrait à rendre le module
 * lisible par tout script qui connaît le jeton, alors que c'est précisément
 * ce qu'on veut éviter.
 */
function wsm_is_superadmin(?array $user): bool {
    if (!$user || !empty($user['service'])) return false;

    // DEUX PORTES, ET LA SECONDE N'EST PAS UN CONFORT.
    //
    // La liste du serveur (superadmin_emails) désigne le PREMIER superadmin :
    // sans elle, aucun compte ne porterait le rôle et personne ne pourrait
    // jamais entrer — ni l'attribuer, puisque seul un Superadmin fabrique un
    // Superadmin. Elle reste donc l'amorçage, et le recours si le dernier
    // compte superadmin est perdu.
    //
    // Le rôle en base, lui, a été demandé pour pouvoir gérer ça depuis la
    // console. Le risque assumé est nommé à côté de wsm_peut_donner_role() :
    // seul un Superadmin peut en désigner un autre, de sorte qu'un compte
    // Administrator compromis ne se hisse pas tout seul jusqu'à la
    // facturation de la plateforme.
    if (function_exists('wsm_role_de') && wsm_role_de($user) === WSM_ROLE_SUPERADMIN) return true;

    $mails = wsm_superadmin_emails();
    if (!$mails) return false;
    return in_array(strtolower(trim((string) ($user['email'] ?? ''))), $mails, true);
}

// ---------------------------------------------------------------------------
//  Le contrat : loyer, taux, assiette
// ---------------------------------------------------------------------------

function wsm_ensure_platform(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_platform_terms')
        || !wsm_table_exists($pdo, 'wsm_platform_periods')) {
        wsm_apply_schema($pdo);
    }
}

/**
 * Les conditions applicables à un mois donné.
 *
 * L'historique est en AJOUT SEUL : modifier le contrat écrit une nouvelle
 * ligne valable à partir d'un mois, il n'écrase jamais la précédente. On peut
 * donc relire, deux ans plus tard, à quelles conditions un décompte a été
 * établi — et un décompte déjà émis n'a de toute façon plus besoin de cette
 * table, puisqu'il a figé ses valeurs.
 *
 * @param ?string $ym  'YYYY-MM' ; null = le mois courant
 */
function wsm_platform_terms(PDO $pdo, ?string $ym = null): array {
    wsm_ensure_platform($pdo);
    $ym = $ym ?: date('Y-m');
    $defaut = [
        'rent_net' => 0, 'rate' => WSM_PLAT_RATE, 'basis' => 'brutto',
        'vat_rate' => WSM_PLAT_VAT, 'from_ym' => $ym, 'note' => '', 'id' => 0,
    ];
    try {
        $st = $pdo->prepare("SELECT * FROM wsm_platform_terms WHERE from_ym <= ?
                              ORDER BY from_ym DESC, id DESC LIMIT 1");
        $st->execute([$ym]);
        $r = $st->fetch();
    } catch (Throwable $e) { return $defaut; }
    if (!$r) return $defaut;
    return [
        'id'       => (int) $r['id'],
        'rent_net' => (int) $r['rent_net'],
        'rate'     => (float) $r['rate'],
        'basis'    => isset(WSM_PLAT_BASES[$r['basis']]) ? (string) $r['basis'] : 'brutto',
        'vat_rate' => (float) $r['vat_rate'],
        'from_ym'  => (string) $r['from_ym'],
        'note'     => (string) ($r['note'] ?? ''),
    ];
}

/**
 * Écrit de nouvelles conditions, valables à partir d'un mois.
 *
 * On REFUSE de dater l'entrée en vigueur d'un mois déjà décompté : le
 * décompte est figé, la nouvelle condition ne le changerait pas, et l'écran
 * afficherait alors un contrat qui contredit la note déjà envoyée. Mieux vaut
 * dire non que produire deux vérités.
 *
 * @return array [conditions|null, erreurs par champ]
 */
function wsm_platform_terms_save(PDO $pdo, array $in, string $actor = ''): array {
    wsm_ensure_platform($pdo);
    $e = [];

    $rent = wsm_plat_grosze($in['rent_net'] ?? 0);
    if ($rent < 0) $e['rent_net'] = 'czynsz nie może być ujemny';

    $rate = (float) str_replace(',', '.', (string) ($in['rate'] ?? ''));
    if ($rate < 0 || $rate > 100) $e['rate'] = 'prowizja musi mieścić się w 0–100 %';
    $rate = $rate / 100;                                   // l'écran saisit des pourcents

    $basis = (string) ($in['basis'] ?? 'brutto');
    if (!isset(WSM_PLAT_BASES[$basis])) $e['basis'] = 'nieznana podstawa';

    $vat = (float) str_replace(',', '.', (string) ($in['vat_rate'] ?? '23'));
    if ($vat < 0 || $vat > 100) $e['vat_rate'] = 'VAT musi mieścić się w 0–100 %';
    $vat = $vat / 100;

    $from = (string) ($in['from_ym'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $from)) $e['from_ym'] = 'format YYYY-MM';

    if (!$e) {
        $st = $pdo->prepare("SELECT ym FROM wsm_platform_periods
                              WHERE ym >= ? AND status <> 'szkic' ORDER BY ym LIMIT 1");
        $st->execute([$from]);
        if ($fige = $st->fetchColumn()) {
            $e['from_ym'] = 'rozliczenie za ' . $fige . ' jest już wystawione — '
                          . 'wybierz miesiąc późniejszy';
        }
    }

    if ($e) return [null, $e];

    $pdo->prepare("INSERT INTO wsm_platform_terms
                     (rent_net, rate, basis, vat_rate, from_ym, note, created_by, created_at)
                   VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$rent, $rate, $basis, $vat, $from,
                   mb_substr(trim((string) ($in['note'] ?? '')), 0, 255),
                   mb_substr($actor, 0, 120), date('Y-m-d H:i:s')]);

    return [wsm_platform_terms($pdo, $from), []];
}

/** L'historique complet du contrat, du plus récent au plus ancien. */
function wsm_platform_terms_history(PDO $pdo): array {
    wsm_ensure_platform($pdo);
    try {
        return $pdo->query("SELECT * FROM wsm_platform_terms
                             ORDER BY from_ym DESC, id DESC")->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

// ---------------------------------------------------------------------------
//  Le volume, et ce qu'on en tire
// ---------------------------------------------------------------------------

/**
 * Ce que la boutique a RÉELLEMENT encaissé dans le mois.
 *
 * Payé et non annulé — rien d'autre. Une commande facturée mais impayée n'a
 * rapporté d'argent à personne, et la commission sur une vente qui n'existe
 * pas se réclame une fois, puis se rembourse.
 *
 * On date sur `paid_at` et non sur `created_at` : une commande de fin janvier
 * réglée le 2 février a apporté son argent en février. À défaut de `paid_at`
 * — l'historique d'avant la colonne — on retombe sur la création.
 *
 * @return array ['gross','goods_gross','shipping_gross','net','orders']
 */
function wsm_platform_volume(PDO $pdo, string $ym): array {
    $out = ['gross' => 0, 'goods_gross' => 0, 'shipping_gross' => 0, 'net' => 0, 'orders' => 0];
    $cols = wsm_table_columns($pdo, 'wsm_orders');
    $quand = in_array('paid_at', $cols, true)
        ? 'COALESCE(' . wsm_sql_date($pdo, 'paid_at') . ', created_at)'
        : "created_at";
    try {
        $st = $pdo->prepare("SELECT items_gross, shipping_gross, total_gross, total_net
                               FROM wsm_orders
                              WHERE status <> 'anulowane' AND payment_status = 'oplacone'
                                AND SUBSTR($quand, 1, 7) = ?");
        $st->execute([$ym]);
        $rows = $st->fetchAll() ?: [];
    } catch (Throwable $e) { return $out; }

    foreach ($rows as $r) {
        $out['orders']++;
        $out['gross']          += (int) $r['total_gross'];
        $out['goods_gross']    += (int) $r['items_gross'];
        $out['shipping_gross'] += (int) $r['shipping_gross'];
        $out['net']            += (int) $r['total_net'];
    }
    $out['fees_collected'] = wsm_platform_fees_collected($pdo, $ym);
    return $out;
}

/**
 * LA COMMISSION DÉJÀ ENCAISSÉE PAR STRIPE (application fee) sur les mêmes
 * commandes que le volume du mois. Le décompte la montre et la déduit de ce
 * qui reste à payer : sans cela, la boutique paierait deux fois la même
 * commission — une fois à chaque paiement, une fois en fin de mois.
 */
function wsm_platform_fees_collected(PDO $pdo, string $ym): int {
    $cols = wsm_table_columns($pdo, 'wsm_orders');
    $quand = in_array('paid_at', $cols, true)
        ? 'COALESCE(' . wsm_sql_date($pdo, 'o.paid_at') . ', o.created_at)'
        : 'o.created_at';
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(p.app_fee), 0) FROM wsm_payments p
                               JOIN wsm_orders o ON o.id = p.order_id
                              WHERE p.provider = 'stripe' AND p.status = 'oplacone'
                                AND o.status <> 'anulowane' AND o.payment_status = 'oplacone'
                                AND SUBSTR($quand, 1, 7) = ?");
        $st->execute([$ym]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) { return 0; }   // base d'avant app_fee : rien n'a été prélevé
}

/**
 * Le décompte d'un mois : celui qui est figé s'il existe, sinon le calcul en
 * cours d'après les conditions du moment.
 *
 * Le drapeau `frozen` dit lequel des deux on regarde. L'écran s'en sert pour
 * ne pas laisser croire qu'un chiffre encore mouvant est une note à payer.
 */
function wsm_platform_period(PDO $pdo, string $ym): array {
    wsm_ensure_platform($pdo);

    try {
        $st = $pdo->prepare("SELECT * FROM wsm_platform_periods WHERE ym = ?");
        $st->execute([$ym]);
        $fige = $st->fetch();
    } catch (Throwable $e) { $fige = null; }

    if ($fige && ($fige['status'] ?? 'szkic') !== 'szkic') {
        return [
            'ym' => $ym, 'frozen' => true, 'status' => (string) $fige['status'],
            'gross' => (int) $fige['gross_volume'], 'goods_gross' => (int) $fige['goods_gross'],
            'shipping_gross' => (int) $fige['shipping_gross'], 'orders' => (int) $fige['orders_count'],
            'basis' => (string) $fige['basis'], 'rate' => (float) $fige['rate'],
            'base_amount' => (int) $fige['base_amount'],
            'commission_net' => (int) $fige['commission_net'], 'rent_net' => (int) $fige['rent_net'],
            'total_net' => (int) $fige['total_net'], 'vat_rate' => (float) $fige['vat_rate'],
            'total_vat' => (int) $fige['total_vat'], 'total_gross' => (int) $fige['total_gross'],
            'issued_at' => (string) ($fige['issued_at'] ?? ''), 'paid_at' => (string) ($fige['paid_at'] ?? ''),
            'id' => (int) $fige['id'], 'note' => (string) ($fige['note'] ?? ''),
            // Lu en direct, même sur un mois figé : ce que Stripe a déjà versé
            // à la plateforme ne change pas après l'émission du décompte.
            'fees_collected' => ($fc = wsm_platform_fees_collected($pdo, $ym)),
            'to_pay_gross' => max(0, (int) $fige['total_gross'] - $fc),
        ];
    }

    $t = wsm_platform_terms($pdo, $ym);
    $v = wsm_platform_volume($pdo, $ym);
    return wsm_platform_compute($ym, $v, $t) + [
        'frozen' => false, 'status' => 'szkic', 'issued_at' => '', 'paid_at' => '',
        'id' => (int) ($fige['id'] ?? 0), 'note' => '',
    ];
}

/**
 * L'arithmétique, isolée pour être testable sans base.
 *
 * La TVA se calcule sur le total HT en une fois, pas ligne à ligne : deux
 * arrondis séparés donneraient un total qui ne tombe pas juste, et c'est
 * exactement ce qui fait rejeter une facture.
 */
function wsm_platform_compute(string $ym, array $volume, array $terms): array {
    $basis = isset(WSM_PLAT_BASES[$terms['basis'] ?? '']) ? (string) $terms['basis'] : 'brutto';
    $base  = $basis === 'towar' ? (int) $volume['goods_gross'] : (int) $volume['gross'];

    $rate = (float) ($terms['rate'] ?? WSM_PLAT_RATE);
    $rent = (int) ($terms['rent_net'] ?? 0);
    $vatR = (float) ($terms['vat_rate'] ?? WSM_PLAT_VAT);

    $commission = (int) round($base * $rate);
    $totalNet   = $commission + $rent;
    $vat        = (int) round($totalNet * $vatR);

    $pobrane = (int) ($volume['fees_collected'] ?? 0);
    return [
        'ym' => $ym,
        'fees_collected' => $pobrane, 'to_pay_gross' => max(0, $totalNet + $vat - $pobrane),
        'gross' => (int) $volume['gross'], 'goods_gross' => (int) $volume['goods_gross'],
        'shipping_gross' => (int) $volume['shipping_gross'], 'orders' => (int) $volume['orders'],
        'basis' => $basis, 'rate' => $rate, 'base_amount' => $base,
        'commission_net' => $commission, 'rent_net' => $rent,
        'total_net' => $totalNet, 'vat_rate' => $vatR,
        'total_vat' => $vat, 'total_gross' => $totalNet + $vat,
    ];
}

/**
 * Fige le décompte d'un mois.
 *
 * DEUX REFUS DÉLIBÉRÉS :
 *  • le mois en cours, parce qu'il n'est pas fini et que la note serait
 *    partielle — on ne facture pas un mois avant son dernier jour ;
 *  • un mois déjà émis, parce qu'un décompte figé ne se refige pas.
 *
 * @return array [décompte|null, message d'erreur]
 */
function wsm_platform_issue(PDO $pdo, string $ym, string $actor = ''): array {
    wsm_ensure_platform($pdo);
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return [null, 'nieprawidłowy miesiąc'];
    if ($ym >= date('Y-m')) return [null, 'miesiąc jeszcze się nie skończył — rozliczenie byłoby częściowe'];

    $st = $pdo->prepare("SELECT * FROM wsm_platform_periods WHERE ym = ?");
    $st->execute([$ym]);
    if ($old = $st->fetch()) {
        if (($old['status'] ?? 'szkic') !== 'szkic') {
            return [null, 'rozliczenie za ' . $ym . ' jest już wystawione'];
        }
        $pdo->prepare("DELETE FROM wsm_platform_periods WHERE id = ?")->execute([(int) $old['id']]);
    }

    $c = wsm_platform_compute($ym, wsm_platform_volume($pdo, $ym), wsm_platform_terms($pdo, $ym));
    $pdo->prepare("INSERT INTO wsm_platform_periods
                     (ym, status, gross_volume, goods_gross, shipping_gross, orders_count,
                      basis, rate, base_amount, commission_net, rent_net, total_net,
                      vat_rate, total_vat, total_gross, issued_at, issued_by, created_at)
                   VALUES (?,'wystawione',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$ym, $c['gross'], $c['goods_gross'], $c['shipping_gross'], $c['orders'],
                   $c['basis'], $c['rate'], $c['base_amount'], $c['commission_net'],
                   $c['rent_net'], $c['total_net'], $c['vat_rate'], $c['total_vat'],
                   $c['total_gross'], date('Y-m-d H:i:s'), mb_substr($actor, 0, 120),
                   date('Y-m-d H:i:s')]);

    return [wsm_platform_period($pdo, $ym), ''];
}

/** Marque un décompte réglé. Un décompte jamais émis ne peut pas l'être. */
function wsm_platform_mark_paid(PDO $pdo, string $ym, bool $paid = true): array {
    wsm_ensure_platform($pdo);
    $st = $pdo->prepare("SELECT * FROM wsm_platform_periods WHERE ym = ?");
    $st->execute([$ym]);
    $r = $st->fetch();
    if (!$r) return [null, 'nie znaleziono rozliczenia'];
    if (($r['status'] ?? '') === 'szkic') return [null, 'rozliczenie nie zostało wystawione'];

    $pdo->prepare("UPDATE wsm_platform_periods SET status = ?, paid_at = ? WHERE id = ?")
        ->execute([$paid ? 'oplacone' : 'wystawione',
                   $paid ? date('Y-m-d H:i:s') : null, (int) $r['id']]);
    return [wsm_platform_period($pdo, $ym), ''];
}

/**
 * Les N derniers mois, du plus récent au plus ancien — figés et calculés
 * mélangés, chacun disant ce qu'il est.
 */
function wsm_platform_series(PDO $pdo, int $months = 12): array {
    $out = [];
    for ($i = 0; $i < $months; $i++) {
        $ym = date('Y-m', strtotime("first day of -$i month"));
        $out[] = wsm_platform_period($pdo, $ym);
    }
    return $out;
}

/**
 * Les totaux : ce qui est dû, ce qui est encaissé, ce qui traîne.
 *
 * Le mois en cours est EXCLU du « dû » : il n'est pas facturable tant qu'il
 * n'est pas fini, et l'y compter donnerait un impayé qui n'en est pas un.
 */
function wsm_platform_totals(array $series): array {
    $cur = date('Y-m');
    $t = ['due' => 0, 'paid' => 0, 'pending' => 0, 'running' => 0,
          'volume' => 0, 'commission' => 0, 'rent' => 0];
    foreach ($series as $p) {
        $t['volume']     += (int) $p['gross'];
        $t['commission'] += (int) $p['commission_net'];
        $t['rent']       += (int) $p['rent_net'];
        if ($p['ym'] === $cur) { $t['running'] = (int) $p['total_gross']; continue; }
        if (!$p['frozen']) { $t['pending'] += (int) $p['total_gross']; continue; }
        $t['due'] += (int) $p['total_gross'];
        if ($p['status'] === 'oplacone') $t['paid'] += (int) $p['total_gross'];
    }
    $t['outstanding'] = $t['due'] - $t['paid'];
    return $t;
}
