<?php
// ============================================================================
//  claims.php — réclamations et rétractations.
//
//  CE QUE LA LOI POLONAISE IMPOSE, ET QUE LE CODE DOIT REFLÉTER :
//
//   • ODSTĄPIENIE OD UMOWY — le consommateur qui achète à distance a
//     QUATORZE JOURS pour se rétracter, sans avoir à se justifier. Le délai
//     court à partir de la RÉCEPTION, pas de la commande.
//   • REKLAMACJA (rękojmia) — DEUX ANS pour un défaut. Le vendeur a
//     QUATORZE JOURS pour répondre ; passé ce délai sans réponse, la
//     demande est réputée ACCEPTÉE. Ce dernier point est la raison d'être
//     de l'écran : un silence de deux semaines coûte le produit.
//
//  LE CODE NE DÉCIDE JAMAIS À LA PLACE DE LA LOI NI DU COMMERÇANT. Il ne
//  refuse pas une demande hors délai — il AFFICHE le délai, et laisse un
//  humain trancher. Un refus automatique sur une date mal saisie ferait
//  perdre un client à qui on devait quelque chose.
//
//  QUATRE RÈGLES DE FOND :
//
//   1. UN REMBOURSEMENT NE DÉPASSE JAMAIS CE QUI A ÉTÉ PAYÉ. Le montant est
//      borné par le total encaissé de la commande, moins ce qui a déjà été
//      remboursé. Sans cette borne, une faute de frappe rend de l'argent
//      qu'on n'a jamais reçu.
//   2. LA DEMANDE EST FIGÉE SUR LA COMMANDE. Le prix peut changer demain ;
//      ce qui a été payé ce jour-là ne bouge plus.
//   3. UNE DEMANDE NE SE SUPPRIME PAS. Elle se clôt, avec sa raison. Une
//      réclamation effacée est une preuve détruite — et le client, lui, a
//      gardé son courriel.
//   4. LE COMPTEUR DE JOURS EST TOUJOURS VISIBLE, y compris quand il est
//      dépassé. C'est ce chiffre qui fait agir.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Les deux natures de demande. */
const WSM_CLAIM_TYPES = [
    'zwrot'      => 'Odstąpienie od umowy (14 dni)',
    'reklamacja' => 'Reklamacja — wada towaru (rękojmia)',
];

/** Les états, dans l'ordre de la vie d'un dossier. */
const WSM_CLAIM_STATUSES = [
    'nowa'        => 'Nowa',
    'w_toku'      => 'W toku',
    'uznana'      => 'Uznana',
    'odrzucona'   => 'Odrzucona',
    'zakonczona'  => 'Zakończona',
];

/** Le délai de rétractation, en jours, à compter de la réception. */
const WSM_CLAIM_ZWROT_DNI = 14;

/** Le délai de RÉPONSE du vendeur à une réclamation. Passé, elle est acquise. */
const WSM_CLAIM_ODPOWIEDZ_DNI = 14;

/** La garantie légale, en mois. */
const WSM_CLAIM_REKOJMIA_MIES = 24;

/** Tables et colonnes. Idempotent. */
function wsm_claims_ensure(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_claims')) wsm_apply_schema($pdo);
}

/**
 * Ouvre une demande.
 *
 * On n'exige PAS que la commande soit livrée : un colis jamais arrivé est
 * précisément le motif le plus fréquent, et le refuser fermerait la porte à
 * celui qui en a le plus besoin.
 *
 * @return array [id|0, message]
 */
function wsm_claim_open(PDO $pdo, int $orderId, string $type, string $raison,
                        string $actor = 'klient', array $extra = []): array {
    if (!isset(WSM_CLAIM_TYPES[$type])) return [0, 'Nieznany rodzaj zgłoszenia.'];
    $raison = trim($raison);
    if (mb_strlen($raison) < 10) return [0, 'Opisz proszę sprawę w kilku zdaniach (min. 10 znaków).'];

    if (!function_exists('wsm_order_by_id')) require_once __DIR__ . '/shop.php';
    $o = wsm_order_by_id($pdo, $orderId);
    if (!$o) return [0, 'Nie znaleziono zamówienia.'];

    // Une seule demande OUVERTE par commande et par type : deux dossiers sur
    // le même problème se répondent en double et se contredisent.
    try {
        $st = $pdo->prepare("SELECT id FROM wsm_claims
                              WHERE order_id = ? AND type = ? AND statut IN ('nowa','w_toku')");
        $st->execute([$orderId, $type]);
        if ($dejaId = (int) $st->fetchColumn()) {
            return [$dejaId, 'Zgłoszenie w tej sprawie jest już otwarte.'];
        }
    } catch (Throwable $e) { /* table neuve : on continue */ }

    $numero = wsm_claim_numero($pdo);
    try {
        $pdo->prepare("INSERT INTO wsm_claims
                         (numer, order_id, order_code, email, type, statut, raison,
                          paid_gross, refund_gross, created_at)
                       VALUES (?,?,?,?,?,'nowa',?,?,0,?)")
            ->execute([$numero, $orderId, (string) $o['code'], (string) $o['email'], $type,
                       mb_substr($raison, 0, 2000),
                       // Règle 2 : ce qui a été payé ce jour-là est figé ici.
                       (int) $o['total_gross'], date('Y-m-d H:i:s')]);
        $id = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        return [0, 'Nie udało się zapisać: ' . $e->getMessage()];
    }

    if (function_exists('wsm_order_event')) {
        wsm_order_event($pdo, $orderId, 'zgloszenie', $numero . ' · ' . WSM_CLAIM_TYPES[$type], $actor);
    }
    if (function_exists('wsm_audit')) {
        wsm_audit($pdo, $actor, 'Nowe zgłoszenie', 'wsm_claims ' . $numero, 'Sieć');
    }

    // Le dossier entre AUSSI dans la messagerie : c'est là qu'on répond, et
    // un dossier qu'on ne voit pas dans sa boîte n'obtient pas de réponse.
    // L'envoi ne peut pas faire échouer l'ouverture — la demande est déjà
    // écrite, et un serveur de courrier muet ne doit pas priver quelqu'un de
    // son droit de rétractation.
    try {
        require_once __DIR__ . '/inbox.php';
        wsm_inbox_store($pdo, (string) $o['email'],
            $numero . ' — ' . WSM_CLAIM_TYPES[$type] . ' — ' . (string) $o['code'],
            $raison, 'zgloszenie');
    } catch (Throwable $e) { /* la boîte peut être indisponible */ }
    return [$id, 'Zgłoszenie ' . $numero . ' zostało przyjęte. Odpowiemy w ciągu '
                 . WSM_CLAIM_ODPOWIEDZ_DNI . ' dni.'];
}

/** Le prochain numéro, au format RK-AAAA-NNN. */
function wsm_claim_numero(PDO $pdo): string {
    $an = date('Y');
    try {
        $rows = $pdo->query("SELECT numer FROM wsm_claims WHERE numer LIKE 'RK-$an-%'")->fetchAll() ?: [];
    } catch (Throwable $e) { $rows = []; }
    $max = 0;
    foreach ($rows as $r) {
        if (preg_match('/^RK-\d{4}-(\d+)$/', (string) $r['numer'], $m)) $max = max($max, (int) $m[1]);
    }
    return sprintf('RK-%s-%03d', $an, $max + 1);
}

/** Une demande, avec sa commande. */
function wsm_claim_get(PDO $pdo, int $id): ?array {
    try {
        $st = $pdo->prepare("SELECT * FROM wsm_claims WHERE id = ?");
        $st->execute([$id]);
        $c = $st->fetch();
        return $c ? wsm_claim_hydrate($pdo, $c) : null;
    } catch (Throwable $e) { return null; }
}

/**
 * Ajoute à une demande ce qui se calcule : les délais.
 *
 * `reponse_reste` est le chiffre qui fait agir. Il est NÉGATIF quand le
 * délai est dépassé, et l'écran l'affiche quand même : masquer un retard ne
 * l'annule pas, ça empêche seulement de le rattraper.
 */
function wsm_claim_hydrate(PDO $pdo, array $c): array {
    $ouvert = strtotime((string) $c['created_at']) ?: time();
    $limite = $ouvert + WSM_CLAIM_ODPOWIEDZ_DNI * 86400;
    $encours = in_array((string) $c['statut'], ['nowa', 'w_toku'], true);

    $c['reponse_limite'] = date('Y-m-d', $limite);
    $c['reponse_reste']  = (int) floor(($limite - time()) / 86400);
    // Le silence de quatorze jours vaut acceptation : c'est la loi, et c'est
    // le seul endroit du logiciel où ne rien faire coûte le produit.
    $c['milczenie_zgoda'] = $encours && $c['reponse_reste'] < 0;
    $c['type_label'] = WSM_CLAIM_TYPES[(string) $c['type']] ?? (string) $c['type'];
    $c['statut_label'] = WSM_CLAIM_STATUSES[(string) $c['statut']] ?? (string) $c['statut'];
    $c['remboursable'] = max(0, (int) $c['paid_gross'] - (int) $c['refund_gross']);
    return $c;
}

/**
 * Les jours restants pour se rétracter, pour une commande donnée.
 *
 * Compté depuis la LIVRAISON quand on la connaît, sinon depuis le paiement,
 * sinon depuis la création. On prend toujours la date la plus TARDIVE dont on
 * est sûr : compter depuis la commande volerait des jours au client.
 *
 * @return array ['jours'=>int, 'depuis'=>string, 'base'=>string]
 */
function wsm_claim_zwrot_reste(PDO $pdo, array $order): array {
    $base = 'utworzenie';
    $t = strtotime((string) ($order['created_at'] ?? '')) ?: time();
    if (!empty($order['paid_at'])) {
        $p = strtotime((string) $order['paid_at']);
        if ($p) { $t = $p; $base = 'zapłata'; }
    }
    try {
        $st = $pdo->prepare("SELECT created_at FROM wsm_order_events
                              WHERE order_id = ? AND event IN ('dostarczone','wyslane')
                           ORDER BY id DESC LIMIT 1");
        $st->execute([(int) ($order['id'] ?? 0)]);
        $d = (string) ($st->fetchColumn() ?: '');
        if ($d !== '' && ($dt = strtotime($d))) { $t = $dt; $base = 'dostawa'; }
    } catch (Throwable $e) { /* la table peut manquer */ }

    $fin = $t + WSM_CLAIM_ZWROT_DNI * 86400;
    return ['jours' => (int) ceil(($fin - time()) / 86400),
            'depuis' => date('Y-m-d', $t), 'base' => $base];
}

/**
 * Fait avancer un dossier.
 *
 * LE REMBOURSEMENT EST BORNÉ (règle 1) : jamais plus que ce qui reste de ce
 * qui a été payé. Une faute de frappe ne peut pas rendre de l'argent qu'on
 * n'a jamais encaissé.
 *
 * @return array [ok, message]
 */
function wsm_claim_update(PDO $pdo, int $id, string $statut, string $decision,
                          int $remboursement, string $actor): array {
    if (!isset(WSM_CLAIM_STATUSES[$statut])) return [false, 'Nieznany stan.'];
    $c = wsm_claim_get($pdo, $id);
    if (!$c) return [false, 'Nie znaleziono zgłoszenia.'];

    $borne = max(0, (int) $c['paid_gross'] - (int) $c['refund_gross']);
    $rembourse = max(0, min($remboursement, $borne));
    $rogne = $remboursement > $borne;

    $clos = in_array($statut, ['uznana', 'odrzucona', 'zakonczona'], true);
    try {
        $pdo->prepare("UPDATE wsm_claims SET statut = ?, decision = ?,
                              refund_gross = refund_gross + ?, resolved_at = ?
                        WHERE id = ?")
            ->execute([$statut, mb_substr(trim($decision), 0, 2000), $rembourse,
                       $clos ? date('Y-m-d H:i:s') : null, $id]);
    } catch (Throwable $e) {
        return [false, 'Nie udało się zapisać: ' . $e->getMessage()];
    }

    if (function_exists('wsm_order_event')) {
        wsm_order_event($pdo, (int) $c['order_id'], 'zgloszenie_' . $statut,
                        (string) $c['numer'] . ($rembourse > 0 ? ' · zwrot ' . wsm_claim_zl($rembourse) : ''),
                        $actor);
    }
    if (function_exists('wsm_audit')) {
        wsm_audit($pdo, $actor, 'Zgłoszenie: ' . $statut, 'wsm_claims ' . (string) $c['numer'], 'Sieć');
    }

    $m = 'Zapisano: ' . (WSM_CLAIM_STATUSES[$statut] ?? $statut) . '.';
    if ($rembourse > 0) $m .= ' Zwrot ' . wsm_claim_zl($rembourse) . '.';
    if ($rogne) {
        $m .= ' UWAGA: kwota została ograniczona do ' . wsm_claim_zl($borne)
            . ' — tyle pozostało z zapłaconych ' . wsm_claim_zl((int) $c['paid_gross']) . '.';
    }
    return [true, $m];
}

/** Un montant en grosze, écrit comme sur une étiquette polonaise. */
function wsm_claim_zl(int $g): string { return number_format($g / 100, 2, ',', ' ') . ' zł'; }

/**
 * Les dossiers, les plus urgents d'abord.
 *
 * L'URGENCE N'EST PAS LA DATE D'OUVERTURE : c'est ce qu'il reste avant que
 * le silence vaille acceptation. Trier par date ferait remonter un dossier
 * neuf au-dessus d'un dossier qui expire demain.
 */
function wsm_claims_list(PDO $pdo, string $filtre = ''): array {
    $sql = "SELECT * FROM wsm_claims";
    $args = [];
    if ($filtre === 'otwarte') { $sql .= " WHERE statut IN ('nowa','w_toku')"; }
    elseif ($filtre !== '' && isset(WSM_CLAIM_STATUSES[$filtre])) { $sql .= " WHERE statut = ?"; $args[] = $filtre; }
    $sql .= " ORDER BY id DESC";
    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }

    $out = array_map(fn($c) => wsm_claim_hydrate($pdo, $c), $rows);
    usort($out, function ($a, $b) {
        $ao = in_array($a['statut'], ['nowa', 'w_toku'], true);
        $bo = in_array($b['statut'], ['nowa', 'w_toku'], true);
        if ($ao !== $bo) return $ao ? -1 : 1;            // l'ouvert avant le clos
        if ($ao) return $a['reponse_reste'] <=> $b['reponse_reste'];
        return $b['id'] <=> $a['id'];
    });
    return $out;
}

/** De quoi tenir un compteur en tête d'écran. */
function wsm_claims_kpis(PDO $pdo): array {
    $k = ['otwarte' => 0, 'pilne' => 0, 'po_terminie' => 0, 'zwroty' => 0];
    foreach (wsm_claims_list($pdo) as $c) {
        if (in_array($c['statut'], ['nowa', 'w_toku'], true)) {
            $k['otwarte']++;
            if ($c['reponse_reste'] <= 3 && $c['reponse_reste'] >= 0) $k['pilne']++;
            if ($c['reponse_reste'] < 0) $k['po_terminie']++;
        }
        $k['zwroty'] += (int) $c['refund_gross'];
    }
    return $k;
}
