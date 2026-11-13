<?php
// ============================================================================
//  delivery.php — server-authoritative delivery (livraison) module.
//  Owns the status machine and every mutation; the front-end calls these as an
//  API and never mutates a delivery directly.
//
//  status flow:  planifiée → assignée → en_cours → livrée
//                                              ↘ échouée
// ============================================================================

const WSM_DELIVERY_STATUSES = ['brouillon', 'planifiée', 'assignée', 'en_cours', 'livrée', 'échouée'];

/** List deliveries, joined for display (client / point / driver / round / marge). */
function wsm_delivery_list(PDO $pdo): array {
    $sql = "SELECT d.*, c.raison AS client, c.code AS client_code,
                   p.libelle AS point, p.adresse AS adresse,
                   dr.nom AS driver, dr.color AS driver_color,
                   r.name AS round
            FROM wsm_deliveries d
            LEFT JOIN wsm_clients c        ON c.id = d.client_id
            LEFT JOIN wsm_client_points p  ON p.id = d.point_id
            LEFT JOIN wsm_drivers dr       ON dr.id = d.driver_id
            LEFT JOIN wsm_rounds r         ON r.id = d.round_id
            ORDER BY d.id DESC";
    $rows = $pdo->query($sql)->fetchAll();
    return array_map('wsm_delivery_shape', $rows);
}

function wsm_delivery_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT d.*, c.raison AS client, c.code AS client_code,
                   p.libelle AS point, p.adresse AS adresse,
                   dr.nom AS driver, dr.color AS driver_color, r.name AS round
            FROM wsm_deliveries d
            LEFT JOIN wsm_clients c       ON c.id = d.client_id
            LEFT JOIN wsm_client_points p ON p.id = d.point_id
            LEFT JOIN wsm_drivers dr      ON dr.id = d.driver_id
            LEFT JOIN wsm_rounds r        ON r.id = d.round_id
            WHERE d.id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ? wsm_delivery_shape($row) : null;
}

/** Normalise a joined row into the JSON shape the front-end consumes. */
function wsm_delivery_shape(array $r): array {
    $ca = (float) $r['ca']; $couts = (float) $r['couts'];
    return [
        'id'         => (int) $r['id'],
        'ref'        => $r['ref'],
        'client'     => $r['client'] ?? '—',
        'client_code'=> $r['client_code'] ?? '',
        'point'      => $r['point'] ?? '—',
        'adresse'    => $r['adresse'] ?? '',
        'driver'     => $r['driver'] ?? '',
        'driver_color'=> $r['driver_color'] ?? '#8D1D2C',
        'round'      => $r['round'] ?? '',
        'status'     => $r['status'],
        'window'     => $r['window_label'],
        'validation' => $r['validation_method'],
        'confirm_code' => $r['confirm_code'],
        'confirmed'  => (int) $r['confirmed'],
        'ca'         => $ca,
        'couts'      => $couts,
        'marge'      => round($ca - $couts, 2),
        'scheduled_date' => $r['scheduled_date'],
        'notes'      => $r['notes'],
        'created_at' => $r['created_at'],
        'confirmed_at' => $r['confirmed_at'],
    ];
}

function wsm_delivery_events(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT event, detail, actor, created_at
                         FROM wsm_delivery_events WHERE delivery_id = ? ORDER BY id ASC");
    $st->execute([$id]);
    return $st->fetchAll();
}

/** KPIs computed live from wsm_deliveries — proof the module is data-driven. */
function wsm_delivery_kpis(PDO $pdo): array {
    $rows = $pdo->query("SELECT status, COUNT(*) n, COALESCE(SUM(ca),0) ca, COALESCE(SUM(couts),0) couts
                         FROM wsm_deliveries GROUP BY status")->fetchAll();
    $byStatus = []; $total = 0; $ca = 0; $couts = 0;
    foreach ($rows as $r) {
        $byStatus[$r['status']] = (int) $r['n'];
        $total += (int) $r['n']; $ca += (float) $r['ca']; $couts += (float) $r['couts'];
    }
    $delivered = $byStatus['livrée'] ?? 0;
    return [
        'total'      => $total,
        'by_status'  => $byStatus,
        'delivered'  => $delivered,
        'in_progress'=> $byStatus['en_cours'] ?? 0,
        'planned'    => ($byStatus['planifiée'] ?? 0) + ($byStatus['assignée'] ?? 0),
        'failed'     => $byStatus['échouée'] ?? 0,
        'ca'         => round($ca, 2),
        'couts'      => round($couts, 2),
        'marge'      => round($ca - $couts, 2),
        'completion' => $total ? round($delivered * 100 / $total) : 0,
    ];
}

/** Next reference LIV-YYYY-NNNN (per-year sequence). */
function wsm_delivery_next_ref(PDO $pdo): string {
    $year = date('Y');
    $st = $pdo->prepare("SELECT ref FROM wsm_deliveries WHERE ref LIKE ? ORDER BY ref DESC LIMIT 1");
    $st->execute(["LIV-$year-%"]);
    $last = $st->fetchColumn();
    $seq = $last ? ((int) substr($last, -4)) + 1 : 1;
    return sprintf('LIV-%s-%04d', $year, $seq);
}

/**
 * Create a delivery. Resolves the delivery point (and its client, window and
 * validation method), generates a ref and a confirmation code, logs the event.
 * Accepts either point_id, or a client_code/client_id (uses that client's first point).
 */
function wsm_delivery_create(PDO $pdo, array $in, string $actor = 'Konsola marki'): array {
    // Resolve the delivery point.
    $point = null;
    if (!empty($in['point_id'])) {
        $st = $pdo->prepare("SELECT p.*, c.id AS cid FROM wsm_client_points p JOIN wsm_clients c ON c.id=p.client_id WHERE p.id=?");
        $st->execute([(int) $in['point_id']]);
        $point = $st->fetch();
    } elseif (!empty($in['client_code']) || !empty($in['client_id'])) {
        if (!empty($in['client_id'])) {
            $st = $pdo->prepare("SELECT id FROM wsm_clients WHERE id=?"); $st->execute([(int) $in['client_id']]);
        } else {
            $st = $pdo->prepare("SELECT id FROM wsm_clients WHERE code=?"); $st->execute([$in['client_code']]);
        }
        $cid = $st->fetchColumn();
        if ($cid) {
            $st = $pdo->prepare("SELECT p.*, c.id AS cid FROM wsm_client_points p JOIN wsm_clients c ON c.id=p.client_id WHERE p.client_id=? ORDER BY p.id ASC LIMIT 1");
            $st->execute([(int) $cid]);
            $point = $st->fetch();
        }
    }
    if (!$point) throw new InvalidArgumentException('client_or_point_required');

    $ref = wsm_delivery_next_ref($pdo);
    $method = $point['validation'] ?: 'QR';
    $prefix = ['QR' => 'QR', 'PIN' => 'PIN', 'Podpis' => 'SIG', 'Zostawienie' => 'DEP'][$method] ?? 'QR';
    $code = $prefix . '-' . random_int(1000, 9999);

    $st = $pdo->prepare("INSERT INTO wsm_deliveries
        (ref, client_id, point_id, status, window_label, validation_method, confirm_code, ca, couts, scheduled_date, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([
        $ref, (int) $point['cid'], (int) $point['id'], 'planifiée', $point['fenetre'],
        $method, $code, (float) ($in['ca'] ?? 0), (float) ($in['couts'] ?? 0),
        $in['scheduled_date'] ?? null, $in['notes'] ?? '',
    ]);
    $id = (int) $pdo->lastInsertId();
    wsm_delivery_log($pdo, $id, 'créée', "Dostawa $ref utworzona", $actor);
    wsm_audit($pdo, $actor, 'Utworzenie', "wsm_deliveries $ref", 'Sieć');
    return wsm_delivery_get($pdo, $id);
}

function wsm_delivery_assign(PDO $pdo, int $id, ?int $driverId, ?int $roundId, string $actor = 'Konsola marki'): array {
    $d = wsm_delivery_get($pdo, $id);
    if (!$d) throw new InvalidArgumentException('delivery_not_found');
    $driverName = '';
    if ($driverId) {
        $st = $pdo->prepare("SELECT nom FROM wsm_drivers WHERE id=?"); $st->execute([$driverId]);
        $driverName = $st->fetchColumn() ?: '';
    }
    $st = $pdo->prepare("UPDATE wsm_deliveries SET driver_id=?, round_id=?, status=? WHERE id=?");
    $st->execute([$driverId, $roundId, 'assignée', $id]);
    wsm_delivery_log($pdo, $id, 'assignée', 'Kierowca ' . ($driverName ?: '—'), $actor);
    return wsm_delivery_get($pdo, $id);
}

function wsm_delivery_status(PDO $pdo, int $id, string $status, string $actor = 'Konsola marki'): array {
    if (!in_array($status, WSM_DELIVERY_STATUSES, true)) throw new InvalidArgumentException('bad_status');
    $d = wsm_delivery_get($pdo, $id);
    if (!$d) throw new InvalidArgumentException('delivery_not_found');
    $st = $pdo->prepare("UPDATE wsm_deliveries SET status=? WHERE id=?");
    $st->execute([$status, $id]);
    wsm_delivery_log($pdo, $id, $status, 'Status → ' . $status, $actor);
    return wsm_delivery_get($pdo, $id);
}

/**
 * Confirm a delivery. The driver presents the confirmation code (QR/PIN/…). It
 * must match the code issued at creation; on success the delivery is marked
 * livrée + confirmed and an audit row is written.
 */
function wsm_delivery_confirm(PDO $pdo, int $id, string $code, string $actor = 'Konsola marki'): array {
    $d = wsm_delivery_get($pdo, $id);
    if (!$d) throw new InvalidArgumentException('delivery_not_found');
    if ($d['confirmed']) throw new RuntimeException('already_confirmed');
    if ($d['confirm_code'] !== '' && !hash_equals($d['confirm_code'], $code)) {
        throw new RuntimeException('bad_code');
    }
    $st = $pdo->prepare("UPDATE wsm_deliveries SET status='livrée', confirmed=1, confirmed_at=? WHERE id=?");
    $st->execute([date('Y-m-d H:i:s'), $id]);
    wsm_delivery_log($pdo, $id, 'livrée', "Potwierdzona przez {$d['validation']} ($code)", $actor);
    wsm_audit($pdo, $actor, 'Dostawa', "wsm_deliveries {$d['ref']} potwierdzona", 'Sieć');
    return wsm_delivery_get($pdo, $id);
}

function wsm_delivery_log(PDO $pdo, int $id, string $event, string $detail, string $actor): void {
    $st = $pdo->prepare("INSERT INTO wsm_delivery_events (delivery_id, event, detail, actor) VALUES (?,?,?,?)");
    $st->execute([$id, $event, $detail, $actor]);
}

function wsm_audit(PDO $pdo, string $user, string $verb, string $entity, string $shop): void {
    $st = $pdo->prepare("INSERT INTO wsm_audit (ts, user, verb, entity, shop) VALUES (?,?,?,?,?)");
    $st->execute([date('d/m H:i'), $user, $verb, $entity, $shop]);
}

/** Drivers / rounds / clients-with-points for the delivery screen selectors. */
function wsm_drivers(PDO $pdo): array {
    return $pdo->query("SELECT id, nom, info, color, vehicule, zone, active FROM wsm_drivers ORDER BY id")->fetchAll();
}
function wsm_rounds(PDO $pdo): array {
    return $pdo->query("SELECT r.id, r.name, r.status, r.driver_id, dr.nom AS driver
                        FROM wsm_rounds r LEFT JOIN wsm_drivers dr ON dr.id=r.driver_id ORDER BY r.id")->fetchAll();
}
function wsm_delivery_clients(PDO $pdo): array {
    // Champs tpay (payeur/facture) et InPost (destinataire/point) inclus :
    // l'écran client doit pouvoir les afficher et les éditer.
    $clients = $pdo->query("SELECT id, code, raison, seg, statut, client_type, email, phone,
                                   first_name, last_name, nip, vat_eu, tva,
                                   bill_street, bill_building, bill_postcode, bill_city, bill_country,
                                   paiement, plafond, encours, franco, remise, fact
                            FROM wsm_clients ORDER BY raison")->fetchAll();
    $pts = $pdo->query("SELECT id, client_id, libelle, adresse, fenetre, jours, validation, marge,
                               delivery_method, inpost_point, street, building, postcode, city, country,
                               contact_phone, contact_email
                        FROM wsm_client_points ORDER BY id")->fetchAll();
    $byClient = [];
    foreach ($pts as $p) { $byClient[$p['client_id']][] = $p; }
    foreach ($clients as &$c) { $c['points'] = $byClient[$c['id']] ?? []; }
    return $clients;
}
function wsm_incidents(PDO $pdo): array {
    return $pdo->query("SELECT i.*, d.ref AS delivery_ref FROM wsm_incidents i
                        LEFT JOIN wsm_deliveries d ON d.id=i.delivery_id ORDER BY i.id DESC")->fetchAll();
}
