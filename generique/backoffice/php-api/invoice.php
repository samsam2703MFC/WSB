<?php
// ============================================================================
//  invoice.php — la facturation.
//
//  Une facture n'est pas un affichage de la commande : c'est un DOCUMENT
//  autonome, qui doit rester identique dix ans après, même si le produit
//  change de nom, si le vendeur déménage ou si le client disparaît. Trois
//  conséquences, appliquées ici :
//
//   1. TOUT EST FIGÉ À L'ÉMISSION. Nom et adresse du vendeur, coordonnées de
//      l'acheteur, libellés et prix des lignes : recopiés dans la facture, pas
//      lus par jointure. Corriger l'adresse du siège demain ne réécrit pas les
//      factures d'hier.
//
//   2. LA NUMÉROTATION EST CONTINUE ET SANS TROU. Le numéro est attribué DANS
//      la transaction qui écrit la facture, et l'unicité est garantie par un
//      index UNIQUE : deux émissions simultanées ne peuvent pas obtenir le
//      même numéro, et une transaction annulée ne consomme pas de numéro.
//      La série est déduite du format lui-même — un format qui contient le
//      mois se remet à 1 chaque mois, sinon chaque année.
//
//   3. UNE FACTURE ÉMISE NE SE MODIFIE PAS. On corrige par une facture de
//      correction (faktura korygująca) qui référence l'originale. C'est la
//      seule voie ; il n'existe pas de fonction de mise à jour.
//
//  Le client particulier sans NIP ne reçoit pas de facture mais un e-paragon
//  (document interne, numéroté à part) — c'est ce que prévoit le parcours de
//  vente et ce que la maquette annonçait.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/shop.php';

const WSM_INVOICE_KINDS = ['faktura', 'korekta', 'paragon', 'proforma'];

/** Format par défaut si personne n'en a réglé : numéro, mois, année. */
const WSM_INVOICE_FORMAT_DEFAULT = 'xxx/mm/yy';

function wsm_invoice_cfg(): array {
    $c = (array) (wsm_config()['invoice'] ?? []);
    $c += ['seller_name' => '', 'seller_nip' => '', 'seller_address' => '', 'place' => '',
           'iban' => '', 'bank' => '', 'payment_days' => '', 'number_format' => '',
           'reminder_days' => ''];
    $fmt = trim((string) $c['number_format']);
    if ($fmt === '' || !preg_match('/x{2,6}/i', $fmt)) $fmt = WSM_INVOICE_FORMAT_DEFAULT;
    $c['number_format'] = $fmt;
    $c['payment_days'] = is_numeric($c['payment_days']) ? max(0, (int) $c['payment_days']) : 0;
    $c['reminder_days'] = is_numeric($c['reminder_days'] ?? '') ? max(0, (int) $c['reminder_days']) : 0;
    return $c;
}

/** Ce qui manque pour émettre une facture opposable. */
/**
 * La ventilation d'un ticket PAR TAUX de TVA, pas par ligne.
 *
 * C'est ce que porte un paragon, et ce qu'un comptable y cherche : combien de
 * ventes à 23 %, combien de TVA dessus. Les lettres sont la convention
 * polonaise — A = 23 %, B = 8 %, C = 5 %, D = 0 % — et un taux inconnu
 * retombe sur A plutôt que de disparaître : une ligne sans groupe serait une
 * vente absente du total des taux, donc un ticket qui ne s'additionne plus.
 *
 * Les montants sont SOMMÉS depuis les lignes figées du document, jamais
 * recalculés : le document dit ce qu'il disait le jour de son émission.
 *
 * @return array<string, array{taux:float, brut:int, vat:int}> trié par lettre
 */
function wsm_paragon_ptu(array $items): array {
    $lettres = [23 => 'A', 8 => 'B', 5 => 'C', 0 => 'D'];
    $out = [];
    foreach ($items as $l) {
        $taux = (float) ($l['vat_rate'] ?? 0);
        $k = $lettres[(int) round($taux * 100)] ?? 'A';
        $out[$k] ??= ['taux' => $taux, 'brut' => 0, 'vat' => 0];
        $out[$k]['brut'] += (int) ($l['line_gross'] ?? 0);
        $out[$k]['vat']  += (int) ($l['line_vat'] ?? 0);
    }
    ksort($out);
    return $out;
}

function wsm_invoice_blockers(): array {
    $c = wsm_invoice_cfg();
    $out = [];
    if (trim((string) $c['seller_name']) === '') $out[] = 'nazwa sprzedawcy';
    if (trim((string) $c['seller_nip']) === '')  $out[] = 'NIP sprzedawcy';
    if (trim((string) $c['seller_address']) === '') $out[] = 'adres sprzedawcy';
    if (trim((string) $c['iban']) === '')        $out[] = 'numer rachunku';
    return $out;
}

// ---------------------------------------------------------------------------
//  Numérotation
// ---------------------------------------------------------------------------

/**
 * La clé de série portée par le format. Un format qui contient « mm » impose
 * une remise à zéro mensuelle : sans elle, deux mois pourraient produire le
 * même numéro. Idem pour l'année. Un format sans date ne se remet jamais à
 * zéro — la suite est alors continue depuis toujours.
 */
function wsm_invoice_series(string $format, string $date): string {
    [$y, $m] = [substr($date, 0, 4), substr($date, 5, 2)];
    if (preg_match('/mm/i', $format)) return $y . '-' . $m;
    if (preg_match('/yy/i', $format)) return $y;
    return 'all';
}

/**
 * Compose le numéro. Jetons : x… (rang, complété de zéros à la largeur écrite),
 * yyyy, yy, mm, dd. Tout le reste est repris tel quel.
 */
function wsm_invoice_format_number(string $format, int $seq, string $date): string {
    return (string) preg_replace_callback('/x{2,6}|yyyy|yy|mm|dd/i', function ($m) use ($seq, $date) {
        $t = strtolower($m[0]);
        if ($t[0] === 'x')      return str_pad((string) $seq, strlen($t), '0', STR_PAD_LEFT);
        if ($t === 'yyyy')      return substr($date, 0, 4);
        if ($t === 'yy')        return substr($date, 2, 2);
        if ($t === 'mm')        return substr($date, 5, 2);
        return substr($date, 8, 2);                      // dd
    }, $format);
}

/**
 * Le rang suivant dans la série. Appelé DANS la transaction d'écriture : c'est
 * ce qui permet à l'index UNIQUE de trancher entre deux émissions simultanées
 * plutôt que de produire deux fois le même numéro.
 */
function wsm_invoice_next_seq(PDO $pdo, string $series, string $kind): int {
    $st = $pdo->prepare("SELECT COALESCE(MAX(seq), 0) FROM wsm_invoices WHERE series = ? AND kind_group = ?");
    $st->execute([$series, wsm_invoice_kind_group($kind)]);
    return ((int) $st->fetchColumn()) + 1;
}

/**
 * Les e-paragons ont leur propre suite : mélanger les deux ferait des trous
 * dans la numérotation des factures, ce qu'un contrôle fiscal remarque.
 */
function wsm_invoice_kind_group(string $kind): string {
    if ($kind === 'paragon')  return 'paragon';
    // La proforma n'est pas un document fiscal : elle ne consomme aucun numéro
    // de la suite des factures. Mélanger les deux ferait des trous dans une
    // numérotation qui doit être continue.
    if ($kind === 'proforma') return 'proforma';
    return 'faktura';
}

/**
 * Facture proforma. Ce n'est PAS une facture : c'est une demande de paiement,
 * qui n'ouvre aucun droit à déduction de TVA et n'entre dans aucune
 * déclaration. Elle se refait autant de fois qu'il le faut — d'où la
 * possibilité d'en émettre une nouvelle à partir d'une facture existante,
 * ce qu'on ne s'autoriserait jamais sur un document fiscal.
 *
 * @param array $order  la commande, ou la facture dont on repart
 * @return array [proforma|null, erreur|null]
 */
function wsm_invoice_proforma(PDO $pdo, array $src, string $actor = '', string $note = ''): array {
    $fromInvoice = isset($src['number']);          // on repart d'une facture
    $orderId = (int) ($src['order_id'] ?? $src['id'] ?? 0);
    if ($fromInvoice) {
        $lines = array_map(fn($l) => [
            'name' => (string) $l['name'], 'sku' => (string) $l['sku'], 'qty' => (int) $l['qty'],
            'unit_net' => (int) $l['unit_net'], 'unit_gross' => (int) $l['unit_gross'],
            'vat_rate' => (float) $l['vat_rate'], 'line_net' => (int) $l['line_net'],
            'line_vat' => (int) $l['line_vat'], 'line_gross' => (int) $l['line_gross'],
        ], (array) ($src['items'] ?? []));
        $buyer = ['name' => (string) $src['buyer_name'], 'nip' => (string) $src['buyer_nip'],
                  'vat_eu' => (string) $src['buyer_vat_eu'], 'address' => (string) $src['buyer_address'],
                  'email' => (string) $src['buyer_email']];
        $tot = [(int) $src['total_net'], (int) $src['total_vat'], (int) $src['total_gross']];
        $rc  = (int) $src['reverse_charge'];
    } else {
        $lines = array_map(fn($l) => [
            'name' => (string) $l['name'], 'sku' => (string) ($l['sku'] ?? ''), 'qty' => (int) $l['qty'],
            'unit_net' => (int) $l['unit_net'], 'unit_gross' => (int) $l['unit_gross'],
            'vat_rate' => (float) $l['vat_rate'], 'line_net' => (int) $l['line_net'],
            'line_vat' => (int) $l['line_vat'], 'line_gross' => (int) $l['line_gross'],
        ], (array) ($src['items'] ?? []));
        if ((int) ($src['shipping_gross'] ?? 0) > 0) {
            $lines[] = ['name' => 'Dostawa — ' . (string) ($src['delivery_method'] ?? ''), 'sku' => '', 'qty' => 1,
                'unit_net' => (int) $src['shipping_net'], 'unit_gross' => (int) $src['shipping_gross'],
                'vat_rate' => (int) $src['shipping_net'] > 0 ? round((int) $src['shipping_vat'] / (int) $src['shipping_net'], 2) : 0.0,
                'line_net' => (int) $src['shipping_net'], 'line_vat' => (int) $src['shipping_vat'],
                'line_gross' => (int) $src['shipping_gross']];
        }
        $b = $src['bill'] ?? [];
        $addr = trim(trim(($b['street'] ?? '') . ' ' . ($b['building'] ?? '') . ', ' .
                          ($b['postcode'] ?? '') . ' ' . ($b['city'] ?? '') . ', ' . ($b['country'] ?? '')), ' ,');
        $buyer = [
            'name' => trim((string) ($src['company'] ?? '')) !== '' ? (string) $src['company']
                      : trim(($src['first_name'] ?? '') . ' ' . ($src['last_name'] ?? '')),
            'nip' => (string) ($src['nip'] ?? ''), 'vat_eu' => (string) ($src['vat_eu'] ?? ''),
            'address' => $addr, 'email' => (string) ($src['email'] ?? ''),
        ];
        $tot = [(int) $src['total_net'], (int) $src['total_vat'], (int) $src['total_gross']];
        $rc  = !empty($src['reverse_charge']) ? 1 : 0;
    }
    if (!$lines) return [null, 'dokument bez pozycji'];

    $c = wsm_invoice_cfg();
    $date = date('Y-m-d');
    $due  = date('Y-m-d', strtotime($date . ' +' . (int) $c['payment_days'] . ' days'));

    $pdo->beginTransaction();
    try {
        $series = wsm_invoice_series($c['number_format'], $date);
        $seq    = wsm_invoice_next_seq($pdo, $series, 'proforma');
        $number = 'PRO/' . wsm_invoice_format_number($c['number_format'], $seq, $date);

        $cols = [
            'order_id' => $orderId ?: null, 'kind' => 'proforma', 'kind_group' => 'proforma',
            'corrects_id' => $fromInvoice ? (int) $src['id'] : null,
            'number' => $number, 'series' => $series, 'seq' => $seq,
            'issued_at' => $date, 'sold_at' => $date, 'due_at' => $due, 'place' => (string) $c['place'],
            'seller_name' => (string) $c['seller_name'], 'seller_nip' => (string) $c['seller_nip'],
            'seller_address' => (string) $c['seller_address'],
            'iban' => (string) $c['iban'], 'bank' => (string) $c['bank'],
            'buyer_name' => $buyer['name'], 'buyer_nip' => $buyer['nip'],
            'buyer_vat_eu' => $buyer['vat_eu'], 'buyer_address' => $buyer['address'],
            'buyer_email' => $buyer['email'], 'currency' => 'PLN',
            'total_net' => $tot[0], 'total_vat' => $tot[1], 'total_gross' => $tot[2],
            'reverse_charge' => $rc, 'paid' => 0,
            'note' => mb_substr($note, 0, 250), 'created_by' => mb_substr($actor, 0, 120),
        ];
        $names = array_keys($cols);
        $pdo->prepare('INSERT INTO wsm_invoices (' . implode(',', $names) . ') VALUES (' .
                      implode(',', array_fill(0, count($names), '?')) . ')')
            ->execute(array_values($cols));
        $id = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare("INSERT INTO wsm_invoice_items
              (invoice_id, name, sku, qty, unit_net, unit_gross, vat_rate, line_net, line_vat, line_gross)
              VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach ($lines as $l) {
            $ins->execute([$id, $l['name'], $l['sku'], $l['qty'], $l['unit_net'], $l['unit_gross'],
                           $l['vat_rate'], $l['line_net'], $l['line_vat'], $l['line_gross']]);
        }
        if ($orderId) wsm_order_event($pdo, $orderId, 'proforma', $number, $actor ?: 'system');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [null, 'nie udało się wystawić proformy: ' . $e->getMessage()];
    }
    return [wsm_invoice_by_id($pdo, $id), null];
}

/**
 * Les factures impayées dont l'échéance est dépassée d'au moins $days jours.
 * Sert aux relances — et rien d'autre : on ne relance jamais un document
 * déjà payé, ni une proforma (qui EST déjà une demande de paiement).
 */
function wsm_invoices_overdue(PDO $pdo, int $days = 0): array {
    $limit = date('Y-m-d', time() - $days * 86400);
    $st = $pdo->prepare("SELECT * FROM wsm_invoices
                          WHERE kind = 'faktura' AND paid = 0 AND due_at <= ?
                          ORDER BY due_at LIMIT 100");
    $st->execute([$limit]);
    return array_map(fn($i) => wsm_invoice_hydrate($pdo, $i), $st->fetchAll() ?: []);
}

// ---------------------------------------------------------------------------
//  Émission
// ---------------------------------------------------------------------------

/**
 * Émet le document d'une commande. Une commande n'a qu'un document : rappeler
 * cette fonction renvoie celui qui existe déjà, jamais un second.
 *
 * @return array [facture|null, erreur|null]
 */
function wsm_invoice_issue(PDO $pdo, array $order, string $actor = ''): array {
    $existing = wsm_invoice_for_order($pdo, (int) $order['id']);
    if ($existing) return [$existing, null];

    // LA RÈGLE VIT DANS UNE SEULE FONCTION. Elle était écrite ici en une ligne
    // — « facture si la case est cochée et qu'un NIP est saisi » — ce qui
    // donnait une facture à un numéro de TVA que VIES venait de REFUSER.
    $kind = wsm_invoice_kind_for($order)['kind'];

    if ($kind === 'faktura') {
        $miss = wsm_invoice_blockers();
        if ($miss) return [null, 'brakuje danych sprzedawcy: ' . implode(', ', $miss)];
    }

    $c    = wsm_invoice_cfg();
    $date = date('Y-m-d');
    $due  = date('Y-m-d', strtotime($date . ' +' . (int) $c['payment_days'] . ' days'));

    // Le vendeur et l'acheteur sont RECOPIÉS : le document doit se relire seul.
    $buyerName = trim((string) ($order['company'] ?? '')) !== ''
        ? (string) $order['company']
        : trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
    $b = $order['bill'] ?? [];
    $buyerAddr = trim(($b['street'] ?? '') . ' ' . ($b['building'] ?? '') . ', ' .
                      ($b['postcode'] ?? '') . ' ' . ($b['city'] ?? '') . ', ' . ($b['country'] ?? ''));
    if (trim($buyerAddr, ' ,') === '') {
        $s = $order['ship'] ?? [];
        $buyerAddr = trim(($s['street'] ?? '') . ' ' . ($s['building'] ?? '') . ', ' .
                          ($s['postcode'] ?? '') . ' ' . ($s['city'] ?? '') . ', ' . ($s['country'] ?? ''));
    }

    $pdo->beginTransaction();
    try {
        // DEUX ÉMISSIONS SIMULTANÉES, UN SEUL DOCUMENT. Le paiement et un clic
        // de la console peuvent arriver ensemble : la ligne de la commande est
        // verrouillée, puis on regarde de nouveau. Le second attend le premier
        // et rend son document au lieu d'en numéroter un autre — qui, pour un
        // paragon, serait passé une seconde fois en caisse. (SQLite sérialise
        // déjà les écritures ; FOR UPDATE n'y existe pas.)
        if (wsm_config()['engine'] === 'mysql') {
            $pdo->prepare("SELECT id FROM wsm_orders WHERE id = ? FOR UPDATE")->execute([(int) $order['id']]);
            $deja = wsm_invoice_for_order($pdo, (int) $order['id']);
            if ($deja) { $pdo->commit(); return [$deja, null]; }
        }
        $series = wsm_invoice_series($c['number_format'], $date);
        $seq    = wsm_invoice_next_seq($pdo, $series, $kind);
        $number = wsm_invoice_format_number($c['number_format'], $seq, $date);
        if ($kind === 'paragon') $number = 'PAR/' . $number;

        $cols = [
            'order_id'       => (int) $order['id'],
            'kind'           => $kind,
            'kind_group'     => wsm_invoice_kind_group($kind),
            'corrects_id'    => null,
            'number'         => $number,
            'series'         => $series,
            'seq'            => $seq,
            'issued_at'      => $date,
            'sold_at'        => substr((string) ($order['created_at'] ?? $date), 0, 10),
            'due_at'         => $due,
            'place'          => (string) $c['place'],
            'seller_name'    => (string) $c['seller_name'],
            'seller_nip'     => (string) $c['seller_nip'],
            'seller_address' => (string) $c['seller_address'],
            'iban'           => (string) $c['iban'],
            'bank'           => (string) $c['bank'],
            'buyer_name'     => $buyerName,
            'buyer_nip'      => (string) ($order['nip'] ?? ''),
            'buyer_vat_eu'   => (string) ($order['vat_eu'] ?? ''),
            'buyer_address'  => trim($buyerAddr, ' ,'),
            'buyer_email'    => (string) ($order['email'] ?? ''),
            'currency'       => (string) ($order['currency'] ?? 'PLN'),
            'total_net'      => (int) $order['total_net'],
            'total_vat'      => (int) $order['total_vat'],
            'total_gross'    => (int) $order['total_gross'],
            'reverse_charge' => !empty($order['reverse_charge']) ? 1 : 0,
            'paid'           => ($order['payment_status'] ?? '') === 'oplacone' ? 1 : 0,
            'created_by'     => mb_substr($actor, 0, 120),
        ];
        $names = array_keys($cols);
        $pdo->prepare('INSERT INTO wsm_invoices (' . implode(',', $names) . ') VALUES (' .
                      implode(',', array_fill(0, count($names), '?')) . ')')
            ->execute(array_values($cols));
        $id = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare("INSERT INTO wsm_invoice_items
              (invoice_id, name, sku, qty, unit_net, unit_gross, vat_rate, line_net, line_vat, line_gross)
              VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach ((array) ($order['items'] ?? []) as $l) {
            $ins->execute([$id, (string) $l['name'], (string) ($l['sku'] ?? ''), (int) $l['qty'],
                (int) $l['unit_net'], (int) $l['unit_gross'], (float) $l['vat_rate'],
                (int) $l['line_net'], (int) $l['line_vat'], (int) $l['line_gross']]);
        }
        // La livraison est une prestation facturée : elle a sa ligne, sinon le
        // total du document ne retomberait pas sur celui de la commande.
        if ((int) ($order['shipping_gross'] ?? 0) > 0) {
            $shipRate = (int) $order['shipping_net'] > 0
                ? round((int) $order['shipping_vat'] / (int) $order['shipping_net'], 2) : 0.0;
            $ins->execute([$id, 'Dostawa — ' . (string) ($order['delivery_method'] ?? ''), '', 1,
                (int) $order['shipping_net'], (int) $order['shipping_gross'], $shipRate,
                (int) $order['shipping_net'], (int) $order['shipping_vat'], (int) $order['shipping_gross']]);
        }

        wsm_order_event($pdo, (int) $order['id'], 'faktura', $number, $actor ?: 'system');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [null, 'nie udało się wystawić: ' . $e->getMessage()];
    }

    return [wsm_invoice_by_id($pdo, $id), null];
}

/**
 * Facture de correction. Elle ne modifie rien : elle porte les montants de
 * remplacement et référence l'originale, qui reste telle quelle.
 */
function wsm_invoice_correct(PDO $pdo, array $src, array $in, string $actor = ''): array {
    if (($src['kind'] ?? '') === 'korekta') return [null, 'nie koryguje się korekty'];
    $reason = trim((string) ($in['reason'] ?? ''));
    if ($reason === '') return [null, 'przyczyna korekty jest wymagana'];

    $gross = (int) round(((float) str_replace(',', '.', (string) ($in['total_gross'] ?? 0))) * 100);
    if ($gross < 0) return [null, 'kwota nie może być ujemna'];

    // La TVA de la correction suit le taux dominant de la facture d'origine :
    // une correction globale ne peut pas inventer une ventilation.
    $rate = (float) ($src['items'][0]['vat_rate'] ?? 0.23);
    if (!empty($src['reverse_charge'])) $rate = 0.0;
    [$net, $vat] = wsm_split_vat($gross, $rate);

    $c    = wsm_invoice_cfg();
    $date = date('Y-m-d');

    $pdo->beginTransaction();
    try {
        $series = wsm_invoice_series($c['number_format'], $date);
        $seq    = wsm_invoice_next_seq($pdo, $series, 'korekta');
        $number = 'KOR/' . wsm_invoice_format_number($c['number_format'], $seq, $date);

        $cols = [
            'order_id' => $src['order_id'], 'kind' => 'korekta', 'kind_group' => 'faktura',
            'corrects_id' => (int) $src['id'], 'number' => $number, 'series' => $series, 'seq' => $seq,
            'issued_at' => $date, 'sold_at' => (string) $src['sold_at'], 'due_at' => $date,
            'place' => (string) $c['place'],
            'seller_name' => (string) $src['seller_name'], 'seller_nip' => (string) $src['seller_nip'],
            'seller_address' => (string) $src['seller_address'],
            'iban' => (string) $src['iban'], 'bank' => (string) $src['bank'],
            'buyer_name' => (string) $src['buyer_name'], 'buyer_nip' => (string) $src['buyer_nip'],
            'buyer_vat_eu' => (string) $src['buyer_vat_eu'], 'buyer_address' => (string) $src['buyer_address'],
            'buyer_email' => (string) $src['buyer_email'], 'currency' => (string) $src['currency'],
            'total_net' => $net, 'total_vat' => $vat, 'total_gross' => $gross,
            'reverse_charge' => (int) $src['reverse_charge'], 'paid' => 0,
            'note' => $reason, 'created_by' => mb_substr($actor, 0, 120),
        ];
        $names = array_keys($cols);
        $pdo->prepare('INSERT INTO wsm_invoices (' . implode(',', $names) . ') VALUES (' .
                      implode(',', array_fill(0, count($names), '?')) . ')')
            ->execute(array_values($cols));
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO wsm_invoice_items
              (invoice_id, name, sku, qty, unit_net, unit_gross, vat_rate, line_net, line_vat, line_gross)
              VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id, 'Korekta do ' . $src['number'] . ' — ' . $reason, '', 1,
                       $net, $gross, $rate, $net, $vat, $gross]);
        if ($src['order_id']) {
            wsm_order_event($pdo, (int) $src['order_id'], 'korekta', $number, $actor ?: 'system');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [null, 'nie udało się wystawić korekty: ' . $e->getMessage()];
    }
    return [wsm_invoice_by_id($pdo, $id), null];
}

// ---------------------------------------------------------------------------
//  Lectures
// ---------------------------------------------------------------------------

function wsm_invoice_by_id(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM wsm_invoices WHERE id = ?");
    $st->execute([$id]);
    $inv = $st->fetch();
    return $inv ? wsm_invoice_hydrate($pdo, $inv) : null;
}

function wsm_invoice_for_order(PDO $pdo, int $orderId): ?array {
    $st = $pdo->prepare("SELECT * FROM wsm_invoices WHERE order_id = ? AND kind <> 'korekta' ORDER BY id LIMIT 1");
    $st->execute([$orderId]);
    $inv = $st->fetch();
    return $inv ? wsm_invoice_hydrate($pdo, $inv) : null;
}

function wsm_invoice_hydrate(PDO $pdo, array $inv): array {
    $st = $pdo->prepare("SELECT * FROM wsm_invoice_items WHERE invoice_id = ? ORDER BY id");
    $st->execute([(int) $inv['id']]);
    $inv['items'] = $st->fetchAll() ?: [];

    // Ventilation par taux : c'est ce que la loi demande d'afficher, et c'est
    // aussi ce qui permet de relire le document sans refaire le calcul.
    $by = [];
    foreach ($inv['items'] as $l) {
        $k = (string) $l['vat_rate'];
        $by[$k] ??= ['rate' => (float) $l['vat_rate'], 'net' => 0, 'vat' => 0, 'gross' => 0];
        $by[$k]['net']   += (int) $l['line_net'];
        $by[$k]['vat']   += (int) $l['line_vat'];
        $by[$k]['gross'] += (int) $l['line_gross'];
    }
    krsort($by);
    $inv['vat_breakdown'] = array_values($by);
    return $inv;
}

function wsm_invoices_list(PDO $pdo, array $f = []): array {
    $where = []; $args = [];
    if (!empty($f['q'])) {
        $where[] = '(number LIKE ? OR buyer_name LIKE ? OR buyer_nip LIKE ?)';
        array_push($args, '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . $f['q'] . '%');
    }
    if (!empty($f['kind'])) { $where[] = 'kind = ?'; $args[] = (string) $f['kind']; }
    $sql = "SELECT * FROM wsm_invoices" . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY id DESC LIMIT ' . max(1, min(500, (int) ($f['limit'] ?? 200)));
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll() ?: [];
}

function wsm_invoice_kpis(PDO $pdo): array {
    $n = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    return [
        'count'     => $n("SELECT COUNT(*) FROM wsm_invoices WHERE kind = 'faktura'"),
        'paragons'  => $n("SELECT COUNT(*) FROM wsm_invoices WHERE kind = 'paragon'"),
        'korekty'   => $n("SELECT COUNT(*) FROM wsm_invoices WHERE kind = 'korekta'"),
        'gross'     => $n("SELECT COALESCE(SUM(total_gross), 0) FROM wsm_invoices WHERE kind = 'faktura'"),
        'unpaid'    => $n("SELECT COUNT(*) FROM wsm_invoices WHERE kind = 'faktura' AND paid = 0"),
        'to_issue'  => $n("SELECT COUNT(*) FROM wsm_orders o WHERE o.payment_status = 'oplacone'
                             AND NOT EXISTS (SELECT 1 FROM wsm_invoices i WHERE i.order_id = o.id)"),
    ];
}

/** Les commandes encaissées qui n'ont pas encore de document. */
function wsm_invoice_pending_orders(PDO $pdo, int $limit = 50): array {
    $rows = $pdo->query("SELECT o.id FROM wsm_orders o
                          WHERE o.payment_status = 'oplacone'
                            AND NOT EXISTS (SELECT 1 FROM wsm_invoices i WHERE i.order_id = o.id)
                          ORDER BY o.id DESC LIMIT " . max(1, min(200, $limit)))->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $r) {
        $o = wsm_order_by_id($pdo, (int) $r['id']);
        if ($o) $out[] = $o;
    }
    return $out;
}

/**
 * Les relances, sans tâche planifiée. Le serveur n'a pas de cron à nous : on
 * profite donc de l'ouverture d'un écran de la console pour regarder si des
 * factures ont dépassé leur échéance du nombre de jours réglé.
 *
 * C'est sans danger parce que la clé d'événement contient la date : une
 * facture ne peut recevoir qu'UNE relance par jour, quel que soit le nombre
 * de fois où quelqu'un ouvre la page.
 *
 * @return int nombre de relances mises en file
 */
function wsm_invoice_reminders_run(PDO $pdo): int {
    $days = wsm_invoice_cfg()['reminder_days'];
    if ($days <= 0) return 0;
    require_once __DIR__ . '/mail.php';
    $n = 0;
    foreach (wsm_invoices_overdue($pdo, $days) as $inv) {
        if (wsm_mail_for_invoice($pdo, $inv, 'przypomnienie', 'automat')) $n++;
    }
    return $n;
}

// ---------------------------------------------------------------------------
//  QUEL DOCUMENT POUR CETTE COMMANDE — ET LE PIÈGE QU'IL Y A DERRIÈRE
//
//  La règle demandée est simple à dire : facture si le numéro de TVA est
//  confirmé par VIES, e-paragon sinon. Elle l'est moins à écrire, parce que
//  « pas confirmé par VIES » recouvre deux situations qui n'ont rien à voir :
//
//   · un numéro REFUSÉ par VIES — le client n'est pas l'entreprise qu'il dit
//     être. Facturer en autoliquidation serait une erreur qui coûte la TVA ;
//   · une entreprise POLONAISE avec son NIP. VIES ne sert à rien ici : la
//     vente est domestique, la TVA polonaise s'applique, et une société qui
//     demande une facture avec son NIP y a droit. Lui rendre un e-paragon
//     parce qu'aucun appel VIES n'a eu lieu, c'est refuser un document dû.
//
//  Le repli sur l'e-paragon est donc le DÉFAUT, pas la punition : il vaut
//  pour le particulier, pour le numéro refusé, et pour tout ce qu'on ne sait
//  pas. La facture demande une preuve — VIES pour l'étranger, un NIP valide
//  pour la Pologne.
//
//  QUAND VIES EST CONSULTÉ — CORRECTION D'UNE ERREUR DE RAISONNEMENT.
//
//  J'avais d'abord écrit que le statut restait FIGÉ à la vente, et qu'on ne
//  relisait jamais VIES. C'est faux là où ça compte : pour une livraison
//  intracommunautaire en autoliquidation, le vendeur doit avoir vérifié le
//  numéro AU MOMENT DE LA LIVRAISON, et pouvoir le prouver — c'est le numéro
//  de consultation qui sert de preuve en contrôle. Un numéro valable au
//  moment de la commande et révoqué avant l'expédition rend la facture
//  fausse, et la TVA reste due par le vendeur.
//
//  VIES est donc reconsulté au passage à « wysłane », juste avant d'émettre,
//  et le résultat frais est écrit sur la commande avec sa date et son numéro
//  de consultation. Ce qui reste figé, c'est le DOCUMENT une fois émis : lui
//  ne se réécrit pas, il se corrige par un avoir.
//
//  SI VIES NE RÉPOND PAS, on garde ce qu'on avait : refuser d'expédier parce
//  qu'un service européen est en panne arrêterait la boutique pour une raison
//  qui ne la regarde pas.
// ---------------------------------------------------------------------------

/**
 * Le document dû, et pourquoi. Fonction PURE : c'est elle qu'on teste, et
 * c'est elle qui décide de ce qui part au fisc.
 *
 * @return array{kind:string, raison:string}
 */
function wsm_invoice_kind_for(array $order): array {
    // DEUX FORMES POUR LA MÊME COMMANDE, et c'est un piège qui coûte cher.
    // La ligne brute de la base porte « vat_status » ; la commande HYDRATÉE
    // par wsm_order_by_id() range la même chose sous « vat.status ». Ne lire
    // que la première faisait retomber un numéro REFUSÉ par VIES sur la règle
    // du NIP — donc sur une facture. Les tests passaient : ils lisaient des
    // tableaux bruts. C'est en faisant l'aller-retour par la base que ça se voit.
    $vat = strtolower(trim((string) ($order['vat_status'] ?? ($order['vat']['status'] ?? ''))));
    $vatEu  = trim((string) ($order['vat_eu'] ?? ''));
    $nip    = trim((string) ($order['nip'] ?? ''));
    $veut   = !empty($order['invoice']);

    // 1. La preuve la plus forte : VIES a confirmé le numéro, et il est écrit.
    if ($vat === 'valid' && $vatEu !== '') {
        return ['kind' => 'faktura', 'raison' => 'numer VAT UE potwierdzony w VIES'];
    }
    // 2. Un numéro EXPLICITEMENT refusé ne donne pas de facture, même si le
    //    client en a coché la case : le repli est là pour ça.
    if ($vat === 'invalid') {
        return ['kind' => 'paragon', 'raison' => 'numer VAT odrzucony przez VIES — paragon'];
    }
    // 3. La Pologne. VIES ne prouve rien d'utile pour une vente domestique ;
    //    le NIP se vérifie par sa clé de contrôle, ce que fait wsm_valid_nip().
    if ($veut && $nip !== '') {
        if (!function_exists('wsm_valid_nip')) require_once __DIR__ . '/commerce.php';
        if (wsm_valid_nip($nip)) {
            return ['kind' => 'faktura', 'raison' => 'NIP poprawny — faktura krajowa'];
        }
        return ['kind' => 'paragon', 'raison' => 'NIP niepoprawny — paragon'];
    }
    // 4. Tout le reste : le particulier, et ce qu'on ne sait pas.
    return ['kind' => 'paragon', 'raison' => $veut
        ? 'brak numeru do faktury — paragon'
        : 'klient nie prosił o fakturę — paragon'];
}

/**
 * La caisse fiscale est-elle branchée ? Charge fiskal.php à la demande, comme
 * ksef.php : la facturation fonctionne sans elle.
 */
function wsm_invoice_kasa(): bool {
    $f = __DIR__ . '/fiskal.php';
    if (!function_exists('wsm_fiskal_enabled') && is_file($f)) require_once $f;
    return function_exists('wsm_fiskal_enabled') && wsm_fiskal_enabled();
}

/**
 * LE DOCUMENT D'UNE COMMANDE, DE BOUT EN BOUT : émis, envoyé, déposé.
 *
 * Appelée quand la commande passe à « wysłane », et par le bouton de l'écran
 * Zamówienia. IDEMPOTENTE dans les trois étages — c'est la seule façon de
 * pouvoir l'appeler depuis quatre endroits sans compter les documents :
 *
 *   · l'émission : wsm_invoice_issue() rend le document existant s'il y en a ;
 *   · le courrier : la file refuse une clé d'événement déjà vue ;
 *   · KSeF : un document qui porte déjà un numéro n'y retourne pas.
 *
 * ELLE NE LÈVE JAMAIS. Une commande expédiée dont le mail échoue reste une
 * commande expédiée : faire échouer l'expédition parce que le SMTP est en
 * panne mettrait le colis en attente pour une raison sans rapport.
 *
 *   · la caisse : un paragon déjà parti chez Fakturownia n'y retourne pas.
 *
 * @return array{doc:?array, kind:string, raison:string, mail:bool, ksef:string, fiskal:string}
 */
function wsm_order_document(PDO $pdo, array $order, string $actor = ''): array {
    $r = wsm_invoice_kind_for($order);
    $out = ['doc' => null, 'kind' => $r['kind'], 'raison' => $r['raison'],
            'mail' => false, 'ksef' => '', 'fiskal' => ''];

    [$doc, $err] = wsm_invoice_issue($pdo, $order, $actor);
    if (!$doc) { $out['raison'] = (string) $err; return $out; }
    $out['doc']  = $doc;
    $out['kind'] = (string) $doc['kind'];

    // --- La caisse fiscale ---------------------------------------------------
    // Un paragon, quand une imprimante fiscale est branchée, part tout de
    // suite chez Fakturownia. Le client reçoit alors l'e-paragon FISCAL (son
    // lien arrive avec le webhook), pas notre paragon interne : deux
    // « paragons » dans sa boîte, dont un seul vaut pour le fisc, c'est la
    // question au service client garantie.
    $fiskal = $out['kind'] === 'paragon' && wsm_invoice_kasa();
    if ($fiskal) {
        try {
            [, $out['fiskal']] = wsm_fiskal_wyslij($pdo, $doc, $order, $actor);
        } catch (Throwable $e) { $out['fiskal'] = 'błąd wysyłki na kasę'; }
    }

    // --- Le courrier ---------------------------------------------------------
    // La clé d'événement porte le NUMÉRO du document, pas l'identifiant de la
    // commande : un avoir émis plus tard doit pouvoir partir à son tour.
    $reg  = wsm_orders_cfg();
    $mail = trim((string) ($order['email'] ?? ''));
    if ($mail !== '' && $reg['doc_mail'] && !$fiskal) {
        try {
            $lien = wsm_invoice_lien_public($pdo, $order, $doc);
            $est  = $out['kind'] === 'faktura' ? 'Faktura' : 'Paragon';
            $id = wsm_mail_queue($pdo, [
                'order_id'      => (int) $order['id'],
                'email'         => $mail,
                'subject'       => $est . ' ' . $doc['number'] . ' — ' . wsm_brand_name(),
                'body'          => $est . " do zamówienia " . (string) $order['code'] . ".\n\n"
                                 . ($lien !== '' ? "Dokument: " . $lien . "\n\n" : '')
                                 . "Dziękujemy za zakupy.\n" . wsm_brand_name(),
                'template_code' => 'dokument',
                'event_key'     => 'dok:' . $doc['number'],
                'actor'         => $actor !== '' ? $actor : 'system',
            ]);
            $out['mail'] = $id > 0;
        } catch (Throwable $e) { /* règle : le colis part quand même */ }
    }

    // --- KSeF ----------------------------------------------------------------
    // Un e-paragon N'EST PAS une facture : le déposer au registre national y
    // inscrirait un document qui n'existe pas pour le fisc. wsm_ksef_blockers()
    // le refuse déjà ; on ne l'appelle même pas.
    if ($out['kind'] === 'faktura' && !$reg['doc_ksef']) {
        // Éteint à dessein : la facture existe et attend dans la file du
        // KSeF, où quelqu'un la déposera à la main. Ce n'est pas une panne,
        // et l'écran ne doit pas le présenter comme telle.
        $out['ksef'] = 'automat wyłączony — faktura czeka w kolejce KSeF';
    } elseif ($out['kind'] === 'faktura') {
        $k = __DIR__ . '/ksef.php';
        if (is_file($k)) {
            require_once $k;
            if (function_exists('wsm_ksef_enabled') && wsm_ksef_enabled()) {
                try {
                    [$num, $kerr] = wsm_ksef_wyslij($pdo, wsm_invoice_hydrate($pdo, $doc), $actor);
                    $out['ksef'] = $num !== null && $num !== '' ? (string) $num : (string) $kerr;
                } catch (Throwable $e) { $out['ksef'] = 'błąd wysyłki do KSeF'; }
            } else {
                // Pas une panne : le canal n'est pas ouvert. L'écran KSeF tient
                // la file, et le document y attendra son tour.
                $out['ksef'] = 'kanał KSeF zamknięty — dokument czeka w kolejce';
            }
        }
    }
    return $out;
}

/** Le lien public du document, s'il en existe un. Jamais de secret dedans. */
function wsm_invoice_lien_public(PDO $pdo, array $order, array $doc): string {
    $base = trim((string) (wsm_config()['shop_url'] ?? ''));
    $tok  = trim((string) ($order['access_token'] ?? ''));
    if ($base === '' || $tok === '') return '';
    return rtrim($base, '/') . '/zamowienie/' . rawurlencode((string) $order['code'])
         . '?t=' . rawurlencode($tok);
}

// ---------------------------------------------------------------------------
//  L'ÉTAT D'UNE COMMANDE, VU DEPUIS LA LISTE
//
//  La liste disait le statut et rien d'autre. Pour savoir si une commande
//  partirait avec une facture ou un e-paragon, si son numéro de TVA tenait
//  toujours, ou si son document était bien arrivé au registre national, il
//  fallait ouvrir la fiche — une par une. On ne le faisait donc pas, et on
//  découvrait le problème après coup, quand le document était déjà figé.
//
//  Ces quatre voyants se lisent d'un coup d'œil sur la ligne, et chacun porte
//  le geste qui le débloque. La couleur ne décore pas : elle dit s'il y a
//  quelque chose à faire.
// ---------------------------------------------------------------------------

/**
 * LE CHEMIN D'UNE COMMANDE, EN BOUTONS.
 *
 * Changer un statut était le geste le plus fréquent de la maison et le plus
 * cher à faire : ouvrir la commande, descendre jusqu'à une liste déroulante,
 * choisir, valider, revenir à la liste. Cinq écrans pour un mot, répétés
 * quarante fois par jour, au téléphone, debout dans la réserve.
 *
 * Le statut devient donc l'étape elle-même, et l'étape est un bouton. La
 * suite EST le chemin : Nowe · Opłacone · W realizacji · Wysłane ·
 * Dostarczone. On lit où en est la commande, et on avance d'un doigt.
 *
 * TROIS RÈGLES QUI FONT LA DIFFÉRENCE ENTRE UN OUTIL ET UN PIÈGE :
 *
 *  1. L'ÉTAPE SUIVANTE EST MISE EN AVANT. C'est le geste de 95 % des cas ;
 *     il doit se toucher sans viser.
 *  2. ON PEUT REVENIR EN ARRIÈRE. Une étape déjà passée reste touchable —
 *     un doigt qui glisse doit pouvoir se corriger sur place, sinon on
 *     appelle le patron.
 *  3. CE QUI ÉMET UN DOCUMENT SE DIT AVANT, PAS APRÈS. L'étape configurée
 *     dans le Superadmin écrit une facture ou un e-paragon, l'envoie au
 *     client et le dépose au registre national. Elle porte donc un repère
 *     et une question qui NOMME le document — pas un « Czy na pewno? »
 *     que personne ne lit.
 *
 * « Anulowane » est mis à l'écart, en rouge : c'est une sortie, pas une
 * étape. Et une commande annulée ne repart pas — wsm_order_status_set()
 * refuse, donc on n'affiche pas des boutons qui ne feraient rien.
 *
 * @return list<array{code:string, txt:string, etat:string, doc:bool, pyt:string}>
 *         etat : 'przeszly' passée · 'teraz' actuelle · 'nastepny' la suivante
 *                · 'dalszy' plus loin · 'zly' l'annulation · 'niemozliwy' figée
 *         pyt  : la question à poser avant d'agir, ou '' s'il n'y a rien à
 *                confirmer
 */
function wsm_order_etapy(PDO $pdo, array $o): array {
    $st   = (string) ($o['status'] ?? '');
    $reg  = wsm_orders_cfg();

    // ─── « OPŁACONE » N'EST PAS UNE ÉTAPE DE L'OPÉRATEUR ──────────────────
    //
    // Il était au milieu du chemin, donc il ressortait en GRAND BOUTON sur
    // presque toutes les lignes — puisque la plupart des commandes sont
    // « nowe ». Le geste le plus visible de l'écran le plus utilisé de la
    // maison proposait donc, quarante fois par jour, quelque chose que
    // personne ne devait faire : l'encaissement est écrit par Stripe, pas au
    // doigt. Pire, il le faisait mal (voir wsm_order_status_set).
    //
    // Le chemin que conduit l'opérateur est celui du COLIS : on prépare, on
    // expédie, ça arrive. Le paiement est un autre axe, avec sa colonne et
    // son propre bouton — « Oznacz opłacone », pour les virements.
    //
    // « Opłacone » reste un état où l'on PEUT être : la commande y est mise
    // par Stripe. On l'affiche alors comme état courant, à sa place dans le
    // chemin, et l'étape suivante est « W realizacji ».
    $flux = ['nowe' => 'Nowe', 'w_realizacji' => 'W realizacji',
             'wyslane' => 'Wysłane', 'dostarczone' => 'Dostarczone'];
    $ordre  = array_keys($flux);
    // Payée mais pas encore préparée : on est au même point du chemin qu'une
    // commande neuve — le colis, lui, n'a pas bougé.
    $rang   = $st === 'oplacone' ? 0 : array_search($st, $ordre, true);
    if ($st === 'oplacone') $flux['nowe'] = 'Opłacone';
    $annule = $st === 'anulowane';
    $code   = (string) ($o['code'] ?? '');

    // Le document n'est promis qu'une fois : s'il existe déjà, ce clic ne
    // fabrique plus rien et il n'y a rien à confirmer. Demander quand même
    // apprendrait à cliquer « oui » sans lire.
    $dejaDoc = wsm_invoice_for_order($pdo, (int) ($o['id'] ?? 0)) !== null;

    $out = [];
    // « Opłacone » occupe la case de « Nowe » : c'est là que le colis en est.
    $stEff = $st === 'oplacone' ? 'nowe' : $st;
    foreach ($ordre as $k => $c) {
        if ($annule)                              $etat = 'niemozliwy';
        elseif ($c === $stEff)                    $etat = 'teraz';
        elseif ($rang !== false && $k === $rang + 1) $etat = 'nastepny';
        elseif ($rang !== false && $k < $rang)    $etat = 'przeszly';
        else                                      $etat = 'dalszy';

        $emet = ($c === $reg['doc_status'] && $reg['doc_status'] !== 'nigdy');
        $pyt  = '';
        if ($emet && !$dejaDoc && $etat !== 'teraz' && $etat !== 'niemozliwy') {
            $kind = wsm_invoice_kind_for($o);
            $quoi = ($kind['kind'] ?? '') === 'faktura' ? 'FAKTURĘ' : 'PARAGON';
            $ou   = [];
            if (($kind['kind'] ?? '') === 'paragon' && wsm_invoice_kasa()) {
                // Le papier se dit ICI, avant le clic : c'est l'opérateur qui
                // doit le glisser dans le colis.
                $ou[] = wsm_fiskal_forma($o) === 'e' ? 'na kasę fiskalną jako e-paragon'
                                                     : 'na kasę fiskalną — PAPIEROWY, włóż go do paczki';
            } elseif ($reg['doc_mail']) {
                $ou[] = 'mailem do klienta';
            }
            if ($reg['doc_ksef'] && ($kind['kind'] ?? '') === 'faktura') $ou[] = 'do KSeF';
            $pyt = $code . ' → ' . $flux[$c] . '. Wystawi to ' . $quoi
                 . ($ou ? ' i wyśle ' . implode(' oraz ', $ou) : '') . '. Kontynuować?';
        }
        $out[] = ['code' => $c, 'txt' => $flux[$c], 'etat' => $etat, 'doc' => $emet, 'pyt' => $pyt];
    }

    // « Anuluj » est un ordre, « Anulowane » est un état. Écrit à l'impératif
    // sur une commande DÉJÀ annulée, le mot se lit comme un bouton qu'on aurait
    // désactivé par erreur — on cherche pourquoi il ne répond pas.
    $out[] = ['code' => 'anulowane', 'txt' => $annule ? 'Anulowane' : 'Anuluj',
              'etat' => $annule ? 'teraz' : 'zly', 'doc' => false,
              'pyt'  => $annule ? '' : 'Anulować ' . $code . '? Anulowanego zamówienia nie da się cofnąć.'];
    return $out;
}

/**
 * CE QUI VA PARTIR, LIGNE PAR LIGNE, AVANT QUE ÇA PARTE.
 *
 * Passer une commande à « wysłane » déclenche quatre choses d'un coup : un
 * document fiscal numéroté dans une série continue, un e-mail au client, un
 * dépôt au registre national, et un colis qui s'en va. Aucune n'est
 * rattrapable — un doublon de facture se corrige par un avoir, un e-mail parti
 * ne revient pas, et le colis est chez le transporteur.
 *
 * On montre donc la liste AVANT. Pas un « Czy na pewno? » que personne ne lit :
 * les cinq lignes qui décident, chacune avec son état et sa raison.
 *
 * ET ELLE BLOQUE. Sans les données du vendeur — le numéro de compte en
 * particulier — la facture ne peut pas naître : `wsm_order_document()` refuse
 * et rend « dokument nie powstał ». Aujourd'hui ça se découvre APRÈS, dans le
 * message qui suit le clic, colis déjà expédié. Ici la commande n'est pas
 * expédiable tant que le trou n'est pas bouché, et le trou est nommé.
 *
 * @return array{
 *   lignes: list<array{co:string, etat:string, val:string}>,
 *   kind: string, blok: list<string>, gotowe: bool
 * }  etat : 'ok' vert · 'no' rouge · 'wa' orange · 'brak' neutre
 */
function wsm_order_preflight(PDO $pdo, array $o): array {
    $reg  = wsm_orders_cfg();
    $kind = wsm_invoice_kind_for($o);
    $doc  = wsm_invoice_for_order($pdo, (int) ($o['id'] ?? 0));
    $vs   = strtolower((string) ($o['vat_status'] ?? ($o['vat']['status'] ?? '')));
    $ve   = trim((string) ($o['vat_eu'] ?? ''));
    $nip  = trim((string) ($o['nip'] ?? ''));
    $mail = trim((string) ($o['email'] ?? ''));
    $fak  = ($kind['kind'] ?? '') === 'faktura';
    $l = []; $blok = [];

    // 1. L'argent. Expédier avant d'être payé est une décision, pas un oubli :
    //    on ne bloque pas, on le dit en orange.
    $paye = (string) ($o['payment_status'] ?? '') === 'oplacone';
    $l[] = ['co' => 'Zapłata', 'etat' => $paye ? 'ok' : 'wa',
            // Formaté ici, pas emprunté à la console : cette fonction sert
            // aussi à l'API, qui n'a pas ses aides d'affichage.
            'val' => $paye
                ? number_format(((int) ($o['total_gross'] ?? 0)) / 100, 2, ',', "\u{202F}") . "\u{202F}zł"
                  . (($o['paid_at'] ?? '') ? ' · ' . substr((string) $o['paid_at'], 0, 16) : '')
                : 'brak wpłaty — wyślesz na własne ryzyko',
            // Le geste qui répare, SUR la ligne qui le motive — comme VIES et
            // KSeF juste en dessous. Il vivait dans une colonne à lui, ce qui
            // obligeait à relier du regard « brak wpłaty » ici et un bouton
            // trois colonnes plus loin. L'écran décide qui a le droit de
            // l'afficher ; cette fonction dit seulement ce qu'il y a à faire.
            'agir' => $paye ? '' : 'oplac', 'agirTxt' => 'Opłacone ✓'];

    // 2. Le numéro qui décide du document.
    if ($ve !== '') {
        // Le geste vit SUR la ligne qui le motive : on relit VIES là où on lit
        // son verdict, pas dans une colonne à côté. Le contrôle qui compte est
        // celui du jour de la livraison.
        $l[] = ['co' => 'VIES', 'etat' => $vs === 'valid' ? 'ok' : ($vs === 'invalid' ? 'no' : 'wa'),
                'agir' => 'vies', 'agirTxt' => 'Sprawdź teraz',
                'val' => $ve . ' — ' . match ($vs) {
                    'valid'   => 'potwierdzony',
                    'invalid' => 'ODRZUCONY, faktura z odwrotnym obciążeniem byłaby błędna',
                    default   => 'niesprawdzony albo VIES nie odpowiada',
                }];
    } elseif ($nip !== '') {
        $l[] = ['co' => 'NIP', 'etat' => wsm_valid_nip($nip) ? 'ok' : 'no',
                'val' => $nip . (wsm_valid_nip($nip) ? ' — sprzedaż krajowa' : ' — suma kontrolna się nie zgadza')];
    }

    // 3. Le document. Déjà émis, il ne se refait pas — et c'est une bonne
    //    nouvelle, pas un blocage.
    if ($doc) {
        $l[] = ['co' => 'Dokument', 'etat' => 'ok',
                'val' => strtoupper((string) $doc['kind']) . ' ' . (string) $doc['number'] . ' — już wystawiony'];
        // Facture émise et pas encore au registre : c'est LE cas où l'on veut
        // pousser à la main, et c'est ici qu'on l'apprend.
        if ((string) $doc['kind'] === 'faktura' && trim((string) ($doc['ksef_number'] ?? '')) === '') {
            $ksefFait = false;
        } elseif ((string) $doc['kind'] === 'faktura') {
            $ksefFait = (string) $doc['ksef_number'];
        }
    } else {
        $manque = $fak ? wsm_invoice_blockers() : [];
        if ($manque) {
            $blok[] = 'brak danych sprzedawcy: ' . implode(', ', $manque);
            $l[] = ['co' => 'Dokument', 'etat' => 'no',
                    'val' => 'FAKTURA nie powstanie — brak: ' . implode(', ', $manque)];
        } elseif ($reg['doc_status'] === 'nigdy') {
            $l[] = ['co' => 'Dokument', 'etat' => 'wa',
                    'val' => 'automat wyłączony w Superadminie — wystawisz ręcznie'];
        } else {
            $l[] = ['co' => 'Dokument', 'etat' => 'ok',
                    'val' => strtoupper((string) $kind['kind']) . ' — ' . $kind['raison']];
        }
    }

    // 4. Le courrier.
    $l[] = ['co' => 'Mail', 'etat' => !$reg['doc_mail'] ? 'wa' : ($mail !== '' ? 'ok' : 'no'),
            'val' => !$reg['doc_mail'] ? 'wysyłka dokumentu wyłączona' : ($mail !== '' ? $mail : 'brak adresu — dokument nigdzie nie pójdzie')];

    // 5. Le registre national — un e-paragon n'y va pas, et ce n'est pas un
    //    manque : c'est la règle.
    if ($fak) {
        $ks = function_exists('wsm_ksef_enabled') && wsm_ksef_enabled();
        if (isset($ksefFait) && $ksefFait !== false) {
            $l[] = ['co' => 'KSeF', 'etat' => 'ok', 'val' => 'w rejestrze: ' . $ksefFait];
        } elseif (isset($ksefFait)) {
            $l[] = ['co' => 'KSeF', 'etat' => 'no', 'agir' => 'ksef', 'agirTxt' => 'Zgłoś teraz',
                    'val' => 'faktura NIE jest jeszcze w rejestrze'];
        } else {
            $l[] = ['co' => 'KSeF', 'etat' => !$reg['doc_ksef'] ? 'wa' : ($ks ? 'ok' : 'wa'),
                    'val' => !$reg['doc_ksef'] ? 'zgłaszanie wyłączone' : ($ks ? 'kanał otwarty' : 'kanał zamknięty — faktura poczeka w kolejce')];
        }
    } else {
        $l[] = ['co' => 'KSeF', 'etat' => 'brak', 'val' => 'paragon nie idzie do rejestru — tak ma być'];
        // 6. La caisse fiscale : ce qui part sur l'imprimante, et sous quelle
        //    forme — l'e-paragon, ou le papier qu'il faut glisser dans le colis.
        wsm_invoice_kasa();                  // charge fiskal.php ; la ligne dit aussi « niepodłączona »
        if (function_exists('wsm_fiskal_linia')) {
            $kasa = wsm_fiskal_linia($o, $doc && (string) $doc['kind'] === 'paragon' ? $doc : null,
                                     $doc ? (string) $doc['kind'] : (string) ($kind['kind'] ?? ''));
            if ($kasa) $l[] = $kasa;
        }
    }

    return ['lignes' => $l, 'kind' => (string) ($kind['kind'] ?? 'paragon'),
            'blok' => $blok, 'gotowe' => !$blok];
}

/**
 * Les deux voyants d'une commande : VIES et le document.
 *
 * Les deux gestes d'expédition vivaient ici aussi — « Do wysyłki » et
 * « Wysłane » étaient des boutons de cette colonne. Ils faisaient exactement
 * ce que font maintenant les étapes, à côté, sur la même ligne : le même
 * geste offert deux fois, sous deux noms, dont un seul dit où l'on va. On
 * garde les voyants qui INFORMENT, et le chemin qui AGIT.
 *
 * @return array<string, array{etat:string, txt:string, quoi:string, agir:string}>
 *         etat : '' neutre · 'ok' vert · 'no' rouge · 'wait' orange
 *         agir : le nom du bouton à poster, ou '' s'il n'y a rien à faire
 */
function wsm_order_voyants(PDO $pdo, array $o): array {
    $vs  = strtolower((string) ($o['vat_status'] ?? ($o['vat']['status'] ?? '')));
    $ve  = trim((string) ($o['vat_eu'] ?? ''));
    $doc = wsm_invoice_for_order($pdo, (int) $o['id']);
    $va  = wsm_invoice_kind_for($o);

    // --- VIES ---------------------------------------------------------------
    // Le bouton reste proposé même quand c'est vert : c'est le contrôle du
    // JOUR qui compte, et on peut vouloir le refaire avant d'expédier.
    if ($ve === '') {
        $vies = ['etat' => '', 'txt' => 'VIES —', 'quoi' => 'Brak numeru VAT UE', 'agir' => ''];
    } else {
        $vies = match ($vs) {
            'valid'   => ['etat' => 'ok',   'txt' => 'VIES ✓', 'quoi' => 'Numer potwierdzony w VIES'],
            'invalid' => ['etat' => 'no',   'txt' => 'VIES ✗', 'quoi' => 'Numer odrzucony — będzie paragon'],
            default   => ['etat' => 'wait', 'txt' => 'VIES ?', 'quoi' => 'Niesprawdzony albo usługa niedostępna'],
        };
        $vies['agir'] = 'vies';
    }

    // --- Le document --------------------------------------------------------
    if (!$doc) {
        $dk = $va['kind'] === 'faktura' ? 'Faktura' : 'Paragon';
        $d = ['etat' => '', 'txt' => $dk . ' →', 'quoi' => 'Powstanie przy „Wysłane": ' . $va['raison'],
              'agir' => ''];
    } elseif ((string) $doc['kind'] !== 'faktura') {
        // Un e-paragon n'a rien à faire au registre national : ce n'est pas un
        // manque, c'est le bon état. Vert — sauf si la caisse fiscale, elle,
        // attend quelque chose.
        $d = ['etat' => 'ok', 'txt' => 'Paragon ' . (string) $doc['number'],
              'quoi' => 'Paragon nie idzie do KSeF — tak ma być', 'agir' => ''];
        if (wsm_invoice_kasa()) {
            $kasa = wsm_fiskal_linia($o, $doc, (string) $doc['kind']);
            if ($kasa) {
                $d = ['etat' => ['ok' => 'ok', 'wa' => 'wait', 'no' => 'no'][$kasa['etat']] ?? '',
                      'txt' => 'Kasa ' . ($kasa['etat'] === 'ok' ? '✓' : ($kasa['etat'] === 'no' ? '✗' : '…')),
                      'quoi' => $kasa['val'], 'agir' => (string) ($kasa['agir'] ?? '')];
            }
        }
    } else {
        $ks = trim((string) ($doc['ksef_number'] ?? ''));
        $d = $ks !== ''
            ? ['etat' => 'ok',   'txt' => 'KSeF ✓', 'quoi' => 'W rejestrze: ' . $ks, 'agir' => '']
            : ['etat' => 'no',   'txt' => 'KSeF ✗',
               'quoi' => 'Faktura ' . (string) $doc['number'] . ' nie jest jeszcze w rejestrze',
               'agir' => 'ksef'];
    }

    return ['vies' => $vies, 'dok' => $d];
}

/**
 * CE QUE CHAQUE ÉTAT DÉCLENCHE — DÉRIVÉ DU CODE, PAS RECOPIÉ.
 *
 * Un état de commande n'est plus un mot : « wysłane » émet un document
 * fiscal, l'envoie et le dépose au registre national. Ce qui se déclenche
 * n'était écrit nulle part — il fallait lire quatre fichiers pour le savoir,
 * et personne ne le faisait.
 *
 * CETTE TABLE NE SE MAINTIENT PAS À LA MAIN. Chaque ligne est calculée à
 * partir des sources qui décident vraiment : les modèles de courrier en base,
 * la liste des états qui préviennent le client, l'état des canaux KSeF et
 * transporteur. Une seconde liste écrite à côté aurait divergé au premier
 * changement — et une table de déclencheurs fausse est pire qu'aucune.
 *
 * @return array<int, array{statut:string, kto:string, wyzwala:string[], mail:string}>
 */
function wsm_status_triggers(PDO $pdo): array {
    $modeles = [];
    if (function_exists('wsm_mail_templates')) {
        foreach (wsm_mail_templates($pdo) as $t) {
            $modeles[(string) ($t['code'] ?? '')] = !empty($t['active']);
        }
    }
    $ksefOuvert = function_exists('wsm_ksef_enabled') && wsm_ksef_enabled();
    $manqueVendeur = wsm_invoice_blockers();

    $mail = function (string $code) use ($modeles): string {
        if (!array_key_exists($code, $modeles)) return 'brak szablonu — nic nie wychodzi';
        return $modeles[$code] ? 'szablon czynny — klient dostaje wiadomość'
                               : 'szablon wyłączony — nic nie wychodzi';
    };

    $kasa = wsm_invoice_kasa();
    $doc = ['Wystawia FAKTURĘ albo PARAGON (reguła VIES/NIP)',
            $kasa ? 'Wysyła fakturę mailem; e-paragon idzie do klienta sam, gdy drukarka go wystawi'
                  : 'Wysyła dokument mailem do klienta'];
    $doc[] = $kasa
        ? 'Paragon idzie na kasę fiskalną (Fakturownia): e-paragon za zgodą klienta, inaczej papierowy do paczki'
        : 'Kasa fiskalna niepodłączona — paragon zostaje dokumentem wewnętrznym';
    $doc[] = $ksefOuvert
        ? 'Zgłasza fakturę do KSeF (paragon nigdy)'
        : 'KSeF: kanał zamknięty — faktura czeka w kolejce';
    if ($manqueVendeur) {
        // LE point qu'il faut voir avant de découvrir qu'aucune facture n'est
        // sortie depuis trois semaines.
        $doc[] = 'UWAGA: brak danych sprzedawcy (' . implode(', ', $manqueVendeur)
               . ') — żaden dokument nie powstanie';
    }
    $doc[] = 'Sprawdza VIES na nowo, tuż przed wystawieniem';

    // LE DOCUMENT SUIT LE RÉGLAGE. Cette table l'accrochait toujours à
    // « wysłane », même quand le Superadmin l'avait mis à « opłacone » : elle
    // décrivait alors une règle qui n'était plus celle de la maison.
    $docSt = wsm_orders_cfg()['doc_status'];
    $rows = [
        ['statut' => 'nowe', 'kto' => 'Złożenie zamówienia w sklepie',
         'wyzwala' => ['Rezerwuje stan magazynowy', 'Potwierdzenie do klienta'],
         'mail' => $mail('zamowienie')],
        ['statut' => 'oplacone', 'kto' => 'Płatność potwierdzona przez Stripe albo ręcznie',
         'wyzwala' => ['Zamówienie wchodzi do kolejki wysyłki'],
         'mail' => $mail('platnosc')],
        ['statut' => 'w_realizacji', 'kto' => 'Przycisk „Do wysyłki" albo lista rozwijana',
         'wyzwala' => ['Nic poza powiadomieniem — to stan przygotowania'],
         'mail' => $mail('w_realizacji')],
        ['statut' => 'wyslane', 'kto' => 'Przycisk „Wysłane", lista rozwijana, albo nadanie u przewoźnika',
         'wyzwala' => [], 'mail' => $mail('wyslane')],
        ['statut' => 'dostarczone', 'kto' => 'Ręcznie',
         'wyzwala' => ['Otwiera bieg terminu na reklamację'],
         'mail' => $mail('dostarczone')],
        ['statut' => 'anulowane', 'kto' => 'Ręcznie',
         'wyzwala' => ['Zamyka zamówienie — nie da się go już wysłać'],
         'mail' => $mail('anulowane')],
    ];
    foreach ($rows as &$r) {
        if ($r['statut'] === $docSt) $r['wyzwala'] = array_merge($r['wyzwala'], $doc);
        if ($r['wyzwala'] === []) $r['wyzwala'] = ['Nic poza powiadomieniem'];
    }
    unset($r);
    return $rows;
}

/**
 * LES QUATRE INTERRUPTEURS DU CHANGEMENT D'ÉTAT.
 *
 * Ils décident de ce qui part au fisc et au client. D'où trois précautions :
 *
 *  · LE DÉFAUT EST « TOUT ACTIVÉ », et il est lu ICI, pas dans config.php.
 *    Une base vide, une table absente, un déploiement à moitié fait : le
 *    comportement doit rester celui d'hier. Un réglage manquant ne doit
 *    jamais éteindre une obligation légale.
 *  · ON N'ÉTEINT QUE CE QUI EST EXPLICITEMENT ÉTEINT. Seule la chaîne « 0 »
 *    ou false coupe ; tout le reste — vide, null, « xxxx » — vaut activé.
 *  · CE N'EST PAS UN RÉGLAGE DE CONFORT. Couper l'émission ou la
 *    reconsultation VIES a des conséquences fiscales ; l'écran qui les
 *    propose doit le dire, et l'audit doit garder qui a coupé quoi.
 *
 * @return array{doc_status:string, doc_mail:bool, doc_ksef:bool, vies_recheck:bool}
 */
// ═══ LE DÉLAI PROMIS, ET CE QU'IL RESTE ═════════════════════════════════════
//
// La vitrine promet « Wysyłka w 24 h ». Jusqu'ici cette phrase ne vivait que
// dans un texte : rien dans la console ne disait quelle commande approchait de
// l'échéance, ni laquelle l'avait dépassée. On tenait la promesse de mémoire,
// commande par commande, et on la ratait sans le savoir.
//
// LE COMPTEUR DÉMARRE À LA PAYE, PAS À LA COMMANDE. Un panier « oczekuje na
// płatność » ne peut pas partir : faire clignoter un retard sur une commande
// qu'on n'a pas le droit d'expédier apprendrait, en une semaine, à ignorer le
// voyant. Il s'arrête au DÉPART du colis, pas à la livraison : ce qu'on promet,
// c'est d'emballer sous 24 h — ce qu'InPost fait ensuite ne dépend pas d'ici.
const WSM_SHIP_DELAI_DEFAUT = 24;

/**
 * Le délai d'expédition promis, en heures.
 *
 * Réglable dans Ustawienia. Le défaut est lu ICI et non dans config.php : une
 * valeur en dur dans le fichier de configuration rendrait le champ de l'écran
 * inopérant à jamais — il accepterait la saisie, dirait « Zapisano », et rien
 * ne changerait. C'est la panne qu'on a déjà payée sur ksef.env.
 */
function wsm_ship_delai_h(): int {
    $v = trim((string) (wsm_config()['orders']['ship_h'] ?? ''));
    // CE QUI N'EST PAS UN NOMBRE RETOMBE SUR LE DÉFAUT, pas sur zéro. Écrit
    // « (int) $v », un « 24h » ou un « jeden dzień » tapé dans le champ aurait
    // donné 0, borné à 1 heure — et TOUTES les commandes seraient passées en
    // retard le lendemain, sans que rien n'ait l'air cassé.
    $n = ($v === '' || !ctype_digit($v)) ? WSM_SHIP_DELAI_DEFAUT : (int) $v;
    // Bornes : zéro rendrait tout en retard dès la seconde du paiement, et un
    // délai d'un mois ne mesure plus rien.
    return max(1, min(720, $n));
}

/**
 * Où en est cette commande par rapport au délai promis.
 *
 * @param array $o      la commande — il lui faut paid_at et shipped_at
 * @param int   $now    l'heure de la BASE (wsm_db_now), pas celle de PHP :
 *                      les trois dates sont écrites par CURRENT_TIMESTAMP
 * @return array{etat:string,terme:?int,ecart:int,delai_h:int}
 *   etat : 'brak'      rien à compter (annulée)
 *          'czeka'     pas encore payée — le compteur n'a pas démarré
 *          'bieg'      en cours ; ecart = secondes restantes
 *          'po_czasie' dépassé ; ecart = secondes de retard
 *          'w_czasie'  partie dans les temps ; ecart = marge
 *          'spoznione' partie en retard ; ecart = retard
 *          'wyslane'   partie, date inconnue (commandes d'avant cette colonne)
 */
function wsm_order_termin(array $o, int $now, ?int $delaiH = null): array {
    $delai = $delaiH ?? wsm_ship_delai_h();
    $vide  = ['etat' => 'brak', 'terme' => null, 'ecart' => 0, 'delai_h' => $delai];

    $st = (string) ($o['status'] ?? '');
    if ($st === 'anulowane') return $vide;

    $paid = trim((string) ($o['paid_at'] ?? ''));
    $paye = $paid !== '' ? strtotime($paid) : false;
    // Payée sans date : les commandes d'avant paid_at, et celles encaissées
    // par une voie qui l'aurait oubliée. On repart de la date de commande
    // plutôt que de ne rien afficher — c'est le pire estimé, jamais le
    // meilleur, donc il ne fabrique pas de fausse tranquillité.
    if ($paye === false && (string) ($o['payment_status'] ?? '') === 'oplacone') {
        $paye = strtotime((string) ($o['created_at'] ?? '')) ?: false;
    }
    if ($paye === false) return ['etat' => 'czeka', 'terme' => null, 'ecart' => 0, 'delai_h' => $delai];

    $terme = $paye + $delai * 3600;

    $sh = trim((string) ($o['shipped_at'] ?? ''));
    $parti = $sh !== '' ? strtotime($sh) : false;
    if ($parti === false && in_array($st, ['wyslane', 'dostarczone'], true)) {
        return ['etat' => 'wyslane', 'terme' => $terme, 'ecart' => 0, 'delai_h' => $delai];
    }
    if ($parti !== false) {
        return $parti <= $terme
            ? ['etat' => 'w_czasie',  'terme' => $terme, 'ecart' => $terme - $parti, 'delai_h' => $delai]
            : ['etat' => 'spoznione', 'terme' => $terme, 'ecart' => $parti - $terme, 'delai_h' => $delai];
    }
    return $now <= $terme
        ? ['etat' => 'bieg',      'terme' => $terme, 'ecart' => $terme - $now, 'delai_h' => $delai]
        : ['etat' => 'po_czasie', 'terme' => $terme, 'ecart' => $now - $terme, 'delai_h' => $delai];
}

/**
 * Une durée en polonais, courte, sans piège de déclinaison.
 *
 * « 2 godziny », « 5 godzin », « 22 godziny » : trois formes pour un mot, et
 * une faute de grammaire dans un tableau qu'on regarde quarante fois par jour
 * se remarque. L'abréviation « godz. » est correcte à tous les nombres.
 */
function wsm_duree_pl(int $sec): string {
    $sec = max(0, $sec);
    if ($sec < 3600)      return max(1, (int) round($sec / 60)) . ' min';
    if ($sec < 48 * 3600) return (int) round($sec / 3600) . ' godz.';
    return (int) round($sec / 86400) . ' dni';
}

/**
 * Le voyant : une classe, trois caractères, et la phrase complète au survol.
 *
 * @return array{0:string,1:string,2:string} [classe, texte court, titre]
 */
function wsm_termin_etykieta(array $t): array {
    $quand = $t['terme'] ? date('d.m H:i', $t['terme']) : '';
    switch ($t['etat']) {
        case 'czeka':
            return ['czeka', '—', 'Odliczanie startuje przy zapłacie'];
        case 'bieg':
            return ['bieg', wsm_duree_pl($t['ecart']),
                    'Wysyłka do ' . $quand . ' (' . $t['delai_h'] . ' godz. od zapłaty)'];
        case 'po_czasie':
            return ['po', '+' . wsm_duree_pl($t['ecart']),
                    'Po terminie o ' . wsm_duree_pl($t['ecart']) . ' — termin był ' . $quand];
        case 'w_czasie':
            return ['ok', '✓', 'Wysłane w terminie, z zapasem ' . wsm_duree_pl($t['ecart'])];
        case 'spoznione':
            return ['po', '✓+' . wsm_duree_pl($t['ecart']),
                    'Wysłane po terminie o ' . wsm_duree_pl($t['ecart'])];
        case 'wyslane':
            return ['ok', '✓', 'Wysłane — bez daty wysyłki, sprzed wprowadzenia licznika'];
    }
    return ['', '', ''];
}

function wsm_orders_cfg(): array {
    $c = wsm_config()['orders'] ?? [];
    $on = static function ($v): bool {
        if ($v === null) return true;
        if (is_bool($v)) return $v;
        $s = strtolower(trim((string) $v));
        return !($s === '0' || $s === 'nie' || $s === 'false' || $s === 'off');
    };
    $st = strtolower(trim((string) ($c['doc_status'] ?? '')));
    if (!in_array($st, ['wyslane', 'oplacone', 'dostarczone', 'nigdy'], true)) $st = 'wyslane';
    return [
        'doc_status'   => $st,
        'doc_mail'     => $on($c['doc_mail'] ?? null),
        'doc_ksef'     => $on($c['doc_ksef'] ?? null),
        'vies_recheck' => $on($c['vies_recheck'] ?? null),
    ];
}
