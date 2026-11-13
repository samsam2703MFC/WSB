<?php
// ============================================================================
//  b2b.php — le compte professionnel : qui y a droit, et ce que ça change.
//
//  CE QUI NE MARCHAIT PAS. Les colonnes `remise` et `franco` existaient sur la
//  fiche client depuis le début — et n'étaient lues NULLE PART. Un client
//  professionnel payait exactement le même prix que tout le monde. « B2B »
//  était une étiquette sans effet, ce qui est pire qu'une étiquette absente :
//  on croit avoir accordé quelque chose.
//
//  CINQ RÈGLES, DANS L'ORDRE D'IMPORTANCE :
//
//   1. LES REMISES NE S'EMPILENT PAS. Le palier au poids et la remise pro
//      répondent à la même question — « combien vous prenez » — et cumuler
//      20 % + 15 % donne 32 %, soit toute la marge. LA MEILLEURE DES DEUX
//      s'applique, jamais les deux. C'est déjà la règle des paliers entre eux.
//
//   2. L'ACTIVATION AUTOMATIQUE DONNE UN PRIX, JAMAIS UN CRÉDIT. Franchir
//      100 kg sur trois mois ouvre le tarif professionnel. Le paiement
//      différé et le plafond d'encours restent à zéro tant qu'une personne
//      ne les a pas posés : une remise coûte une part de marge, une ligne de
//      crédit coûte la facture entière.
//
//   3. LE POIDS SE COMPTE SUR L'ENCAISSÉ. Une commande impayée ne prouve
//      rien sur la capacité d'achat de qui que ce soit.
//
//   4. ON N'ACTIVE JAMAIS DEUX FOIS. L'activation est idempotente et laisse
//      une trace : sinon un passage quotidien réécrirait la remise saisie à
//      la main par quelqu'un qui avait de bonnes raisons.
//
//   5. UNE CONDITION ACCORDÉE NE SE RETIRE PAS TOUTE SEULE. Si le volume
//      redescend, l'écran le signale — mais couper le tarif d'un client sans
//      le prévenir est la meilleure façon de le perdre.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Le seuil d'accès au tarif professionnel. */
const WSM_B2B_KG = 100;

/** Sur combien de mois glissants on mesure. */
const WSM_B2B_MOIS = 3;

/** La remise accordée à l'ouverture du compte, en pourcent. */
const WSM_B2B_REMISE = 12.0;

/** Le segment porté par la fiche client. */
const WSM_B2B_SEG = 'B2B';

/**
 * Le poids acheté par une adresse sur la fenêtre, en grammes.
 *
 * Seul l'encaissé compte (règle 3). Le poids vient de la ligne de commande si
 * elle le porte, sinon du produit : une commande passée avant que le poids
 * soit renseigné ne doit pas valoir zéro kilo et faire perdre un droit.
 */
function wsm_b2b_poids(PDO $pdo, string $email, int $mois = WSM_B2B_MOIS): int {
    $depuis = date('Y-m-d H:i:s', strtotime("-$mois months"));
    $cols = wsm_table_columns($pdo, 'wsm_orders');
    $quand = in_array('paid_at', $cols, true)
        ? 'COALESCE(' . wsm_sql_date($pdo, 'o.paid_at') . ', o.created_at)' : "o.created_at";
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(i.qty * COALESCE(p.weight_g, 0)), 0)
                               FROM wsm_order_items i
                               JOIN wsm_orders o ON o.id = i.order_id
                          LEFT JOIN wsm_products p ON p.id = i.product_id
                              WHERE LOWER(o.email) = ?
                                AND o.status <> 'anulowane' AND o.payment_status = 'oplacone'
                                AND $quand >= ?");
        $st->execute([strtolower(trim($email)), $depuis]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** La fiche professionnelle d'une adresse, si elle existe. */
function wsm_b2b_fiche(PDO $pdo, string $email): ?array {
    try {
        $st = $pdo->prepare("SELECT * FROM wsm_clients WHERE LOWER(email) = ?");
        $st->execute([strtolower(trim($email))]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/** Ce compte est-il professionnel actif ? */
function wsm_b2b_actif(PDO $pdo, string $email): bool {
    $f = wsm_b2b_fiche($pdo, $email);
    return $f !== null && strtoupper(trim((string) ($f['seg'] ?? ''))) === WSM_B2B_SEG;
}

/**
 * Les conditions qui s'appliquent RÉELLEMENT à une commande de cette adresse.
 *
 * C'est la fonction que la boutique appelle. Sans elle, les colonnes de la
 * fiche client ne servaient à rien.
 *
 * @return array ['remise'=>float %, 'franco'=>int grosze (0 = pas de seuil propre),
 *                'b2b'=>bool, 'source'=>string]
 */
function wsm_b2b_conditions(PDO $pdo, string $email): array {
    $vide = ['remise' => 0.0, 'franco' => 0, 'b2b' => false, 'source' => ''];
    if (trim($email) === '') return $vide;
    $f = wsm_b2b_fiche($pdo, $email);
    if (!$f) return $vide;

    $b2b = strtoupper(trim((string) ($f['seg'] ?? ''))) === WSM_B2B_SEG;
    $remise = (float) str_replace(',', '.', (string) ($f['remise'] ?? 0));
    // Une remise absurde en base ne doit pas vider la caisse : au-delà de la
    // moitié du prix, c'est une saisie fautive, pas une politique commerciale.
    if ($remise < 0 || $remise > 50) $remise = 0.0;

    $franco = wsm_b2b_grosze($f['franco'] ?? 0);
    return [
        'remise' => $remise, 'franco' => $franco, 'b2b' => $b2b,
        'source' => $b2b ? 'konto firmowe' : ($remise > 0 ? 'rabat indywidualny' : ''),
    ];
}

/** Un montant de fiche (« 400 » ou « 400,00 ») en grosze. */
function wsm_b2b_grosze($v): int {
    $s = str_replace([' ', "\u{202F}", "\u{00A0}"], '', (string) $v);
    $s = str_replace(',', '.', $s);
    if ($s === '' || !is_numeric($s)) return 0;
    return (int) round(((float) $s) * 100);
}

/**
 * Qui a franchi le seuil, et qui l'a déjà.
 *
 * @return array [['email','name','kg','actif','remise','depuis'], …]
 */
function wsm_b2b_candidats(PDO $pdo, int $mois = WSM_B2B_MOIS): array {
    $depuis = date('Y-m-d H:i:s', strtotime("-$mois months"));
    $cols = wsm_table_columns($pdo, 'wsm_orders');
    $quand = in_array('paid_at', $cols, true)
        ? 'COALESCE(' . wsm_sql_date($pdo, 'o.paid_at') . ', o.created_at)' : "o.created_at";

    try {
        $rows = $pdo->query("SELECT LOWER(o.email) AS em, MIN(o.email) AS email,
                                    MAX(o.first_name) AS fn, MAX(o.last_name) AS ln,
                                    MAX(o.company) AS co,
                                    COALESCE(SUM(i.qty * COALESCE(p.weight_g, 0)), 0) AS g
                               FROM wsm_orders o
                               JOIN wsm_order_items i ON i.order_id = o.id
                          LEFT JOIN wsm_products p ON p.id = i.product_id
                              WHERE o.status <> 'anulowane' AND o.payment_status = 'oplacone'
                                AND o.email <> '' AND $quand >= " . $pdo->quote($depuis) . "
                           GROUP BY LOWER(o.email)")->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }

    $out = [];
    foreach ($rows as $r) {
        $kg = (int) $r['g'] / 1000;
        $f = wsm_b2b_fiche($pdo, (string) $r['em']);
        $actif = $f !== null && strtoupper(trim((string) ($f['seg'] ?? ''))) === WSM_B2B_SEG;
        if ($kg < WSM_B2B_KG && !$actif) continue;      // ni éligible ni déjà pro
        $nom = trim((string) $r['fn'] . ' ' . (string) $r['ln']);
        $out[] = [
            'email' => (string) $r['email'],
            'name'  => $nom !== '' ? $nom : (string) ($r['co'] ?? ''),
            'company' => (string) ($r['co'] ?? ''),
            'kg' => round($kg, 1), 'actif' => $actif,
            'remise' => $f ? (float) str_replace(',', '.', (string) ($f['remise'] ?? 0)) : 0.0,
            'eligible' => $kg >= WSM_B2B_KG,
            'client_id' => $f ? (int) $f['id'] : 0,
        ];
    }
    usort($out, fn($a, $b) => $b['kg'] <=> $a['kg']);
    return $out;
}

/**
 * Ouvre le compte professionnel d'une adresse.
 *
 * IDEMPOTENT et non destructif : si une remise a déjà été saisie à la main,
 * elle est conservée. Quelqu'un l'a mise là pour une raison, et un passage
 * automatique n'a pas à la réécrire (règle 4).
 *
 * NE POSE JAMAIS de conditions de crédit (règle 2).
 *
 * @return array [ok, message]
 */
function wsm_b2b_activer(PDO $pdo, string $email, string $actor, bool $auto = false): array {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, 'nieprawidłowy adres'];

    $f = wsm_b2b_fiche($pdo, $email);
    if ($f && strtoupper(trim((string) ($f['seg'] ?? ''))) === WSM_B2B_SEG) {
        return [false, 'konto firmowe już otwarte'];
    }

    // Le nom et la société les plus récents, pour ne pas créer une fiche vide.
    $st = $pdo->prepare("SELECT first_name, last_name, company, nip, phone
                           FROM wsm_orders WHERE LOWER(email) = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$email]);
    $o = $st->fetch() ?: [];
    $raison = trim((string) ($o['company'] ?? '')) ?: trim((string) ($o['first_name'] ?? '') . ' ' . (string) ($o['last_name'] ?? ''));

    try {
        if ($f) {
            // Fiche existante : on n'écrase QUE ce qui est vide.
            $remise = (float) str_replace(',', '.', (string) ($f['remise'] ?? 0));
            $pdo->prepare("UPDATE wsm_clients SET seg = ?, remise = ? WHERE id = ?")
                ->execute([WSM_B2B_SEG, $remise > 0 ? $remise : WSM_B2B_REMISE, (int) $f['id']]);
        } else {
            $pdo->prepare("INSERT INTO wsm_clients (code, email, raison, client_type, nip, phone,
                                seg, remise, statut)
                           VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([wsm_b2b_code($pdo), $email, mb_substr($raison, 0, 190), 'firma',
                           (string) ($o['nip'] ?? ''), (string) ($o['phone'] ?? ''),
                           WSM_B2B_SEG, WSM_B2B_REMISE, 'aktywny']);
        }
    } catch (Throwable $e) { return [false, 'nie udało się zapisać: ' . $e->getMessage()]; }

    if (function_exists('wsm_audit')) {
        wsm_audit($pdo, $actor ?: 'system', $auto ? 'Automatyczne otwarcie konta B2B' : 'Otwarcie konta B2B',
                  $email, 'Sieć');
    }
    return [true, 'Otwarto konto firmowe dla ' . $email . ' — rabat '
                 . number_format(WSM_B2B_REMISE, 0, ',', ' ') . ' %. '
                 . 'Termin płatności i limit kredytowy pozostają puste: to decyzja człowieka.'];
}

/**
 * Le prochain code de fiche, au format déjà en usage (« CL-0061 »).
 *
 * On repart du plus grand existant plutôt que de compter les lignes : une
 * fiche supprimée ferait sinon renaître un code déjà utilisé ailleurs — sur
 * une facture, par exemple.
 */
function wsm_b2b_code(PDO $pdo): string {
    try {
        $rows = $pdo->query("SELECT code FROM wsm_clients WHERE code LIKE 'CL-%'")->fetchAll() ?: [];
    } catch (Throwable $e) { $rows = []; }
    $max = 0;
    foreach ($rows as $r) {
        if (preg_match('/^CL-(\d+)$/', (string) $r['code'], $m)) $max = max($max, (int) $m[1]);
    }
    return 'CL-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * Le passage automatique : ouvre le compte de tous ceux qui ont franchi le
 * seuil et ne l'ont pas encore.
 *
 * @return array ['ouverts'=>int, 'emails'=>string[]]
 */
function wsm_b2b_scan(PDO $pdo, string $actor = 'system'): array {
    $n = 0; $faits = [];
    foreach (wsm_b2b_candidats($pdo) as $c) {
        if (!$c['eligible'] || $c['actif']) continue;
        [$ok] = wsm_b2b_activer($pdo, $c['email'], $actor, true);
        if ($ok) { $n++; $faits[] = $c['email']; }
    }
    return ['ouverts' => $n, 'emails' => $faits];
}

/**
 * Retire le compte professionnel. Manuel uniquement — voir la règle 5 : un
 * tarif qu'on coupe sans prévenir est un client qu'on perd.
 */
function wsm_b2b_fermer(PDO $pdo, string $email, string $actor): array {
    $f = wsm_b2b_fiche($pdo, $email);
    if (!$f) return [false, 'nie znaleziono karty klienta'];
    $pdo->prepare("UPDATE wsm_clients SET seg = '' WHERE id = ?")->execute([(int) $f['id']]);
    if (function_exists('wsm_audit')) {
        wsm_audit($pdo, $actor, 'Zamknięcie konta B2B', $email, 'Sieć');
    }
    return [true, 'Zamknięto konto firmowe. Rabat pozostaje na karcie — usuń go ręcznie, jeśli ma zniknąć.'];
}
