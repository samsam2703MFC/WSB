<?php
// ============================================================================
//  fiskal.php — le paragon FISCAL : Fakturownia + Paragony.pl + l'imprimante.
//
//  Le paragon que la boutique émet (invoice.php, série PAR/…) est un document
//  INTERNE. Pour le fisc, une vente à un particulier n'existe que si elle est
//  passée par une caisse enregistreuse — et une caisse « virtuelle » (pur
//  logiciel) est réservée par le règlement du 29 mai 2020 au transport, à
//  l'hôtellerie, à la restauration et au charbon. Une boutique de chocolat n'y
//  a pas droit : il lui faut une IMPRIMANTE FISCALE « online », physique, chez
//  elle.
//
//  Le chemin retenu :
//
//      boutique ──API──► Fakturownia ──► module Paragony.pl ──► imprimante
//                         (reçu)          (programme sur un      fiscale online
//                                          ordinateur allumé)    ─► HUB du MF
//                                                                ─► e-paragon
//
//  La boutique crée le reçu par l'API publique de Fakturownia, puis demande
//  sa fiscalisation : l'imprimante l'enregistre, et pour un e-paragon renvoie
//  un lien que Fakturownia range sur le reçu (`e_receipt_view_url`). Le lien
//  arrive quand l'imprimante l'a émis — tout de suite si elle est allumée,
//  le lendemain sinon. On l'apprend par le webhook `invoice:update`, et par
//  une relecture bornée depuis la console.
//
//  QUATRE RÈGLES, parce qu'une erreur ici est un faux en écriture fiscale :
//
//   1. UN REÇU PAR DOCUMENT, JAMAIS DEUX. Le numéro de notre paragon part
//      comme `oid` avec `oid_unique: yes` : Fakturownia refuse un second reçu
//      au même numéro. Une réponse perdue en route se rattrape donc en
//      RETROUVANT le reçu, pas en le recréant.
//   2. LA FISCALISATION NE SE REJOUE PAS À L'AVEUGLE. Créer un reçu est sans
//      risque (règle 1). L'imprimer deux fois, c'est deux ventes au registre.
//      Seule la console relance une impression, et pour l'e-paragon on relit
//      d'abord le reçu : s'il porte déjà son lien, on n'imprime rien.
//   3. LE PAPIER EST LE DÉFAUT. L'e-paragon demande l'ACCORD du client
//      (art. 111 ust. 3a pkt 1 lit. a ustawy o VAT) et son adresse. Sans case
//      cochée à la caisse — commandes d'avant ce module, ou passées par l'API
//      — c'est un paragon papier, à glisser dans le colis.
//   4. RIEN NE BLOQUE LA COMMANDE. Comme le KSeF : un reçu qui échoue se
//      relance, un colis retenu parce que Fakturownia ne répond pas, non.
//
//  Le webhook n'est pas cru sur parole : il ne fait que DÉSIGNER un reçu, que
//  l'on relit chez Fakturownia avec notre propre jeton. Un faux webhook peut
//  au pire provoquer une lecture inutile.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice.php';

/** Les états que peut porter `fiskal_status`. Vide = jamais envoyé. */
const WSM_FISKAL_STANY = [
    'wysylka' => 'Wysyłanie do Fakturowni',
    'zlecony' => 'Zlecony — czeka na drukarkę',
    'gotowy'  => 'Paragon fiskalny wystawiony',
    'blad'    => 'Błąd — do ponowienia',
];

/** Les taux qu'une imprimante fiscale sait enregistrer (A, B, C, D). */
const WSM_FISKAL_STAWKI = [23, 8, 5, 0];

/** Combien de reçus relire à chaque passage de la console. */
const WSM_FISKAL_LOT = 3;

/** Au-delà, une création en échec attend la main de quelqu'un. */
const WSM_FISKAL_PROBY = 5;

function wsm_fiskal_cfg(): array {
    $c = (array) (wsm_config()['fakturownia'] ?? []);
    $v = static function (string $k) use ($c): string {
        $s = trim((string) ($c[$k] ?? ''));
        return preg_match('/^x{2,}$/i', $s) ? '' : $s;
    };
    return [
        'host'          => wsm_fiskal_host($v('domena')),
        'api_token'     => $v('api_token'),
        'drukarka'      => $v('drukarka'),
        'webhook_token' => $v('webhook_token'),
    ];
}

/**
 * Le domaine du compte, tel qu'on le colle : « szoko », « szoko.fakturownia.pl »
 * ou l'adresse complète. Seuls les comptes *.fakturownia.pl sont acceptés —
 * c'est là que part le jeton, et un réglage mal tapé ne doit pas l'envoyer
 * ailleurs.
 */
function wsm_fiskal_host(string $raw): string {
    $h = strtolower(trim($raw));
    $h = (string) preg_replace('#^https?://#', '', $h);
    $h = explode('/', $h)[0];
    if ($h === '') return '';
    if (!str_contains($h, '.')) $h .= '.fakturownia.pl';
    return preg_match('/^[a-z0-9][a-z0-9-]{0,62}\.fakturownia\.pl$/', $h) ? $h : '';
}

/** Le canal est-il ouvert ? Domaine du compte + jeton d'API. */
function wsm_fiskal_enabled(): bool {
    $c = wsm_fiskal_cfg();
    return $c['host'] !== '' && $c['api_token'] !== '';
}

/** L'adresse à coller dans Fakturownia → Integracja → Webhooki. */
function wsm_fiskal_webhook_url(): string {
    return wsm_api_base_url() . '/shop/fakturownia/webhook';
}

function wsm_fiskal_http(string $method, string $path, array $query = [], ?array $body = null): array {
    $c = wsm_fiskal_cfg();
    $url = 'https://' . $c['host'] . $path . ($query ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $c['api_token'],
                                   'Accept: application/json', 'Content-Type: application/json'],
        // Court : la console attend la réponse, et une imprimante éteinte ne
        // rallonge pas l'appel — Fakturownia met la demande en file.
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null,
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $raw, true)];
}

/** Le transport, remplaçable — comme Stripe, InPost et le KSeF. */
function wsm_fiskal_transport(?callable $set = null): callable {
    static $fn = null;
    if ($set !== null) { $fn = $set; }
    return $fn ?? 'wsm_fiskal_http';
}

function wsm_fiskal_call(string $method, string $path, array $query = [], ?array $body = null): array {
    return (wsm_fiskal_transport())($method, $path, $query, $body);
}

/** Le message de Fakturownia, en clair (texte, ou erreurs par champ). */
function wsm_fiskal_err($res, int $code): string {
    if ($code === 0) return 'brak odpowiedzi z Fakturowni';
    $m = is_array($res) ? ($res['message'] ?? '') : '';
    if (is_array($m)) {
        $parts = [];
        foreach ($m as $k => $v) $parts[] = (is_string($k) ? $k . ': ' : '') . implode(', ', array_map('strval', (array) $v));
        $m = implode('; ', $parts);
    }
    $m = trim((string) $m);
    return $m !== '' ? mb_substr($m, 0, 200) : ('HTTP ' . $code);
}

function wsm_fiskal_ok(int $code, $res): bool {
    return $code >= 200 && $code < 300 && !(is_array($res) && ($res['code'] ?? '') === 'error');
}

/**
 * E-paragon ou papier, pour CETTE commande (règle 3). Le client a coché la
 * case ET a laissé une adresse valable — sinon, papier.
 */
function wsm_fiskal_forma(array $order): string {
    $ok = (string) ($order['paragon_forma'] ?? '') === 'e'
       && filter_var((string) ($order['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    return $ok ? 'e' : 'papier';
}

/**
 * Le reçu, construit depuis le document FIGÉ — jamais depuis la commande,
 * pour la même raison que la facture : le produit peut changer de nom demain.
 *
 * @return array [payload|null, erreur|null]
 */
function wsm_fiskal_payload(array $inv, array $order): array {
    if (($inv['kind'] ?? '') !== 'paragon') return [null, 'to nie jest paragon'];
    if (strtoupper((string) ($inv['currency'] ?? 'PLN')) !== 'PLN') {
        return [null, 'kasa fiskalna rejestruje tylko złotówki'];
    }
    $pos = []; $suma = 0;
    foreach ((array) ($inv['items'] ?? []) as $l) {
        $rate = (int) round((float) $l['vat_rate'] * 100);
        if (!in_array($rate, WSM_FISKAL_STAWKI, true)) {
            return [null, 'stawka ' . $rate . '% w „' . $l['name'] . '” — drukarka zna tylko 23, 8, 5 i 0%'];
        }
        $gross = (int) $l['line_gross'];
        if ($gross <= 0 || (int) $l['qty'] <= 0) {
            return [null, 'pozycja „' . $l['name'] . '” bez kwoty — paragon nie przyjmie zera'];
        }
        $suma += $gross;
        // L'imprimante calcule prix × quantité. Une remise répartie donne
        // parfois une ligne qui ne se divise pas au grosz (33,35 zł pour 3) :
        // on la coupe en deux — n−1 au prix plancher, 1 avec le reste — pour
        // que le ticket tombe juste sur ce que le client a payé.
        $qty  = (int) $l['qty'];
        $nom  = mb_substr(trim((string) $l['name']), 0, 120);
        $unit = intdiv($gross, $qty);
        $parts = ($gross % $qty === 0 || $qty === 1)
            ? [[$qty, $gross]]
            : [[$qty - 1, $unit * ($qty - 1)], [1, $gross - $unit * ($qty - 1)]];
        foreach ($parts as [$n, $g]) {
            $pos[] = [
                'name'              => $nom,
                'quantity'          => $n,
                'quantity_unit'     => 'szt',
                'tax'               => $rate,
                'total_price_gross' => number_format($g / 100, 2, '.', ''),
            ];
        }
    }
    if (!$pos) return [null, 'paragon bez pozycji'];
    // La somme des lignes EST le total : les remises sont déjà réparties sur
    // les lignes (shop.php), et une ligne négative n'existe pas sur une caisse.
    if ($suma !== (int) $inv['total_gross']) {
        return [null, 'suma pozycji (' . $suma . ' gr) ≠ suma dokumentu (' . (int) $inv['total_gross'] . ' gr)'];
    }
    $forma = wsm_fiskal_forma($order);
    $p = [
        'kind'         => 'receipt',
        'number'       => null,
        // Règle 1 : notre numéro, unique chez eux aussi.
        'oid'          => (string) $inv['number'],
        'oid_unique'   => 'yes',
        'sell_date'    => (string) $inv['sold_at'],
        'issue_date'   => (string) $inv['issued_at'],
        // Payé en ligne, d'avance, par Stripe : ni espèces ni terminal.
        'payment_type' => 'transfer',
        'status'       => 'paid',
        'paid'         => number_format((int) $inv['total_gross'] / 100, 2, '.', ''),
        'currency'     => 'PLN',
        'buyer_name'   => (string) $inv['buyer_name'],
        'description'  => 'Zamówienie ' . (string) ($order['code'] ?? ''),
        // On envoie des montants TTC justes au grosz : qu'ils ne soient pas
        // recalculés depuis le HT, sinon un arrondi déplacerait un grosz.
        'calculating_strategy' => ['position' => 'keep_gross', 'sum' => 'keep_gross'],
        'positions'    => $pos,
    ];
    if ($forma === 'e') $p['buyer_email'] = (string) $order['email'];
    return [['invoice' => $p], null];
}

/**
 * Écrit l'état fiscal d'un document. « gotowy » est FINAL : un webhook arrivé
 * pendant que l'impression était demandée a pu l'écrire, et la réponse qui
 * revient ensuite ne doit pas le défaire.
 */
function wsm_fiskal_mark(PDO $pdo, int $id, string $status, array $set = []): void {
    $set = ['fiskal_status' => $status, 'fiskal_at' => date('Y-m-d H:i:s')] + $set;
    if (isset($set['fiskal_note'])) $set['fiskal_note'] = mb_substr((string) $set['fiskal_note'], 0, 250);
    $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
    $garde = $status === 'gotowy' ? '' : " AND fiskal_status <> 'gotowy'";
    $pdo->prepare("UPDATE wsm_invoices SET $cols WHERE id = ?$garde")->execute([...array_values($set), $id]);
}

/**
 * Retrouve chez Fakturownia le reçu portant notre numéro (règle 1). L'API ne
 * filtre pas par `oid` : on lit les reçus DU JOUR d'émission (celui qu'on a
 * envoyé en `issue_date`), page par page — un compte qui vend aussi en
 * boutique en a vite plus de cent.
 */
function wsm_fiskal_szukaj(string $oid, string $date = ''): string {
    $q = ['kind' => 'receipt', 'per_page' => 100];
    $q += preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        ? ['period' => 'more', 'date_from' => $date, 'date_to' => $date]
        : ['period' => 'last_30_days'];
    for ($page = 1; $page <= 10; $page++) {
        [$code, $res] = wsm_fiskal_call('GET', '/invoices.json', $q + ['page' => $page]);
        if (!wsm_fiskal_ok($code, $res) || !is_array($res) || $res === []) return '';
        foreach ($res as $r) {
            if (is_array($r) && (string) ($r['oid'] ?? '') === $oid && (string) ($r['id'] ?? '') !== '') {
                return (string) $r['id'];
            }
        }
        if (count($res) < 100) return '';
    }
    return '';
}

/**
 * ENVOIE UN PARAGON À LA CAISSE : crée le reçu, puis demande l'impression
 * fiscale. Idempotente — un document déjà parti n'y retourne pas.
 *
 * $ponow : la console relance une impression qui a échoué (règle 2).
 *
 * @return array{0:string, 1:string} [état, phrase]
 */
function wsm_fiskal_wyslij(PDO $pdo, array $inv, array $order, string $actor = '', bool $ponow = false): array {
    if (($inv['kind'] ?? '') !== 'paragon') return ['', 'faktura nie idzie na kasę fiskalną'];
    if (!wsm_fiskal_enabled()) return ['', 'kasa fiskalna nie jest podłączona'];
    $id  = (int) $inv['id'];
    $cur = wsm_invoice_by_id($pdo, $id) ?? $inv;
    $st  = (string) ($cur['fiskal_status'] ?? '');
    $fid = (string) ($cur['fiskal_id'] ?? '');
    if (in_array($st, ['zlecony', 'gotowy'], true)) return [$st, WSM_FISKAL_STANY[$st]];
    if ($st === 'blad' && $fid !== '' && !$ponow) {
        return ['blad', 'czeka na ponowienie z konsoli: ' . (string) ($cur['fiskal_note'] ?? '')];
    }
    // Un processus mort APRÈS la création du reçu a pu mourir aussi après
    // l'impression : seule la console reprend ce cas-là (règle 2).
    if ($st === 'wysylka' && $fid !== '' && !$ponow) return ['wysylka', WSM_FISKAL_STANY['wysylka']];

    // LA PRISE. Une seule requête passe : deux clics simultanés, ou le
    // webhook pendant la console, ne créent pas deux reçus. Une prise vieille
    // de dix minutes est celle d'un processus mort en route.
    $vieux = date('Y-m-d H:i:s', time() - 600);
    $q = $pdo->prepare("UPDATE wsm_invoices SET fiskal_status = 'wysylka', fiskal_at = ?
                         WHERE id = ? AND (fiskal_status IN ('', 'blad')
                               OR (fiskal_status = 'wysylka' AND (fiskal_at IS NULL OR fiskal_at < ?)))");
    $q->execute([date('Y-m-d H:i:s'), $id, $vieux]);
    if ($q->rowCount() === 0) return ['wysylka', WSM_FISKAL_STANY['wysylka']];
    $pdo->prepare("UPDATE wsm_invoices SET fiskal_proby = fiskal_proby + 1 WHERE id = ?")->execute([$id]);

    // Rien ne reste « wysyłanie » pour toujours : ce qui lève ici est écrit
    // en erreur, avec sa raison, et la console propose de reprendre.
    try {
        return wsm_fiskal_etapy($pdo, $cur, $order, $fid, $actor);
    } catch (Throwable $e) {
        wsm_fiskal_mark($pdo, $id, 'blad', ['fiskal_note' => 'błąd: ' . $e->getMessage()]);
        return ['blad', 'błąd: ' . $e->getMessage()];
    }
}

/** Le travail de wsm_fiskal_wyslij(), une fois la prise obtenue. */
function wsm_fiskal_etapy(PDO $pdo, array $cur, array $order, string $fid, string $actor): array {
    $id = (int) $cur['id'];
    // --- 1. Le reçu --------------------------------------------------------
    if ($fid === '') {
        [$payload, $err] = wsm_fiskal_payload(wsm_invoice_hydrate($pdo, $cur), $order);
        if ($payload === null) {
            wsm_fiskal_mark($pdo, $id, 'blad', ['fiskal_note' => $err]);
            return ['blad', (string) $err];
        }
        [$code, $res] = wsm_fiskal_call('POST', '/invoices.json', [], $payload);
        $fid = wsm_fiskal_ok($code, $res) ? (string) ($res['id'] ?? '') : '';
        if ($fid === '' && $code !== 0 && $code !== 401 && $code !== 403) {
            // Refusé — peut-être parce qu'il existe déjà (réponse perdue la
            // fois d'avant). On le cherche avant de déclarer une erreur.
            $fid = wsm_fiskal_szukaj((string) $cur['number'], (string) ($cur['issued_at'] ?? ''));
        }
        if ($fid === '') {
            wsm_fiskal_mark($pdo, $id, 'blad', ['fiskal_note' => 'Fakturownia: ' . wsm_fiskal_err($res, $code)]);
            return ['blad', 'Fakturownia: ' . wsm_fiskal_err($res, $code)];
        }
        wsm_fiskal_mark($pdo, $id, 'wysylka', ['fiskal_id' => mb_substr($fid, 0, 32), 'fiskal_note' => '']);
    } else {
        // Règle 2 : avant de réimprimer, on relit le reçu. Si la lecture
        // échoue, on N'IMPRIME PAS : ne pas savoir n'autorise pas à doubler.
        [$code, $res] = wsm_fiskal_call('GET', '/invoices/' . rawurlencode($fid) . '.json');
        if (!wsm_fiskal_ok($code, $res)) {
            $m = 'nie udało się sprawdzić paragonu ' . $fid . ' w Fakturowni (' . wsm_fiskal_err($res, $code)
               . ') — nic nie wydrukowano, spróbuj za chwilę';
            wsm_fiskal_mark($pdo, $id, 'blad', ['fiskal_note' => $m]);
            return ['blad', $m];
        }
        $url = trim((string) ($res['e_receipt_view_url'] ?? ''));
        if ($url !== '') {
            wsm_fiskal_gotowy($pdo, $cur, $order, $url);
            return ['gotowy', WSM_FISKAL_STANY['gotowy']];
        }
    }

    // --- 2. L'impression fiscale -------------------------------------------
    $forma = wsm_fiskal_forma($order);
    $q = ['id' => $fid, 'mode' => $forma === 'e' ? 'e-receipt' : 'print'];
    $dr = wsm_fiskal_cfg()['drukarka'];
    if ($dr !== '') $q['fiskator_name'] = $dr;
    [$code, $res] = wsm_fiskal_call('GET', '/invoices/fiscal_print', $q);
    if (!wsm_fiskal_ok($code, $res)) {
        // DEUX ÉCHECS QUI NE SE RESSEMBLENT PAS. Un refus motivé (4xx) dit
        // que rien n'est parti. Un silence ou une panne (0, 5xx) ne dit rien :
        // l'ordre a pu être mis en file chez Fakturownia — le relancer sans
        // regarder, ce serait peut-être deux ventes au registre.
        $m = ($code === 0 || $code >= 500)
            ? 'brak pewnej odpowiedzi na zlecenie druku paragonu ' . $fid . ' (' . wsm_fiskal_err($res, $code)
              . ') — zanim ponowisz, sprawdź w Fakturowni, czy nie został już zafiskalizowany'
            : 'paragon ' . $fid . ' jest w Fakturowni, ale zlecenie druku odrzucone: ' . wsm_fiskal_err($res, $code);
        wsm_fiskal_mark($pdo, $id, 'blad', ['fiskal_note' => $m]);
        return ['blad', $m];
    }
    wsm_fiskal_mark($pdo, $id, 'zlecony', ['fiskal_forma' => $forma, 'fiskal_note' => '']);
    if (isset($order['id'])) {
        wsm_order_event($pdo, (int) $order['id'], 'fiskal', ($forma === 'e' ? 'e-paragon' : 'paragon papierowy')
                        . ' zlecony (' . $fid . ')', $actor !== '' ? $actor : 'system');
    }
    return ['zlecony', WSM_FISKAL_STANY['zlecony'] . ($forma === 'e' ? '' : ' — papierowy, włóż do paczki')];
}

/** Le lien est arrivé : on le range, et le client le reçoit — une fois. */
function wsm_fiskal_gotowy(PDO $pdo, array $inv, array $order, string $url): void {
    if (!preg_match('#^https://#i', $url)) return;
    wsm_fiskal_mark($pdo, (int) $inv['id'], 'gotowy', ['fiskal_url' => mb_substr($url, 0, 250), 'fiskal_note' => '']);
    $mail = trim((string) ($order['email'] ?? ''));
    if ($mail === '' || !wsm_orders_cfg()['doc_mail']) return;
    try {
        wsm_mail_queue($pdo, [
            'order_id'      => (int) ($order['id'] ?? 0) ?: null,
            'email'         => $mail,
            'subject'       => 'E-paragon do zamówienia ' . (string) ($order['code'] ?? '') . ' — ' . wsm_brand_name(),
            'body'          => "Paragon fiskalny do zamówienia " . (string) ($order['code'] ?? '') . ":\n\n"
                             . $url . "\n\nDziękujemy za zakupy.\n" . wsm_brand_name(),
            'template_code' => 'e-paragon',
            'event_key'     => 'epar:' . (string) $inv['number'],
            'actor'         => 'system',
        ]);
    } catch (Throwable $e) { /* le lien reste sur la page de la commande */ }
}

/**
 * RELIT UN REÇU chez Fakturownia et range son lien s'il est arrivé.
 * @return string l'état après lecture
 */
function wsm_fiskal_sprawdz(PDO $pdo, array $inv, ?int &$code = null): string {
    $code = null;
    $fid = (string) ($inv['fiskal_id'] ?? '');
    $st  = (string) ($inv['fiskal_status'] ?? '');
    if ($fid === '' || $st === 'gotowy' || !wsm_fiskal_enabled()) return $st;
    [$code, $res] = wsm_fiskal_call('GET', '/invoices/' . rawurlencode($fid) . '.json');
    $url = wsm_fiskal_ok($code, $res) ? trim((string) ($res['e_receipt_view_url'] ?? '')) : '';
    if ($url === '') {
        // Relu sans lien : il passe en fin de file, sinon trois reçus
        // muets occuperaient le lot pour toujours et cacheraient les autres.
        $pdo->prepare("UPDATE wsm_invoices SET fiskal_at = ? WHERE id = ? AND fiskal_status = ?")
            ->execute([date('Y-m-d H:i:s'), (int) $inv['id'], $st]);
        return $st;
    }
    $order = wsm_order_by_id($pdo, (int) $inv['order_id']) ?? [];
    wsm_fiskal_gotowy($pdo, $inv, $order, $url);
    return 'gotowy';
}

/**
 * LE PASSAGE BORNÉ, sans tâche planifiée — le serveur n'a pas de cron à nous.
 * Appelé quand la console s'ouvre : il relit quelques e-paragons en attente
 * de leur lien, et refait les CRÉATIONS échouées (sans risque, règle 1).
 * Jamais une impression : celle-là se relance à la main (règle 2).
 * S'arrête au premier silence du réseau, pour ne pas faire attendre l'écran.
 *
 * @return array{sprawdzone:int, gotowe:int, ponowione:int}
 */
function wsm_fiskal_run(PDO $pdo, int $limit = WSM_FISKAL_LOT): array {
    $out = ['sprawdzone' => 0, 'gotowe' => 0, 'ponowione' => 0];
    if (!wsm_fiskal_enabled()) return $out;
    $lim = max(1, min(20, $limit));
    $st = $pdo->query("SELECT * FROM wsm_invoices WHERE kind = 'paragon' AND fiskal_status = 'zlecony'
                        AND fiskal_forma = 'e' ORDER BY fiskal_at ASC LIMIT $lim");
    foreach ($st->fetchAll() as $inv) {
        $out['sprawdzone']++;
        if (wsm_fiskal_sprawdz($pdo, $inv, $code) === 'gotowy') $out['gotowe']++;
        if ($code === 0) return $out;            // Fakturownia muette : l'écran n'attend pas
    }
    // Les créations à refaire : en erreur, ou restées « wysyłanie » depuis
    // dix minutes (processus mort). Sans reçu chez nous, rien n'a pu être
    // imprimé : on ne fait que créer — ou retrouver — le reçu (règle 1).
    $st = $pdo->prepare("SELECT * FROM wsm_invoices WHERE kind = 'paragon' AND fiskal_id = ''
                          AND (fiskal_status = 'blad' OR (fiskal_status = 'wysylka' AND fiskal_at < ?))
                          AND fiskal_proby < ? ORDER BY fiskal_at ASC LIMIT $lim");
    $st->execute([date('Y-m-d H:i:s', time() - 600), WSM_FISKAL_PROBY]);
    foreach ($st->fetchAll() as $inv) {
        $order = wsm_order_by_id($pdo, (int) $inv['order_id']);
        if (!$order) continue;
        [$etat, $phrase] = wsm_fiskal_wyslij($pdo, $inv, $order, 'system');
        $out['ponowione']++;
        if ($etat === 'blad' && str_contains($phrase, 'brak odpowiedzi')) break;
    }
    return $out;
}

/**
 * Le webhook `invoice:update` de Fakturownia.
 * @return array{0:int, 1:array} [code HTTP, corps]
 */
function wsm_fiskal_webhook(PDO $pdo, string $raw): array {
    if (!wsm_fiskal_enabled()) return [503, ['error' => 'kasa fiskalna nie jest podłączona']];
    $d = json_decode($raw, true);
    if (!is_array($d)) return [400, ['error' => 'zły JSON']];
    $tok = wsm_fiskal_cfg()['webhook_token'];
    if ($tok !== '' && !hash_equals($tok, (string) ($d['api_token'] ?? ''))) {
        return [403, ['error' => 'zły token webhooka']];
    }
    $fid = (string) ($d['id'] ?? ($d['deal']['id'] ?? ''));
    if (!preg_match('/^\d{1,20}$/', $fid)) return [200, ['ok' => true, 'ignored' => 'brak id']];
    $q = $pdo->prepare("SELECT * FROM wsm_invoices WHERE fiskal_id = ? AND kind = 'paragon' LIMIT 1");
    $q->execute([$fid]);
    $inv = $q->fetch();
    // 200 aussi pour ce qui ne nous concerne pas : les factures saisies à la
    // main chez Fakturownia déclenchent le même webhook, et 25 échecs de
    // suite le feraient désactiver.
    if (!$inv) return [200, ['ok' => true, 'ignored' => 'nie nasz paragon']];
    return [200, ['ok' => true, 'status' => wsm_fiskal_sprawdz($pdo, $inv)]];
}

/**
 * LE DIAGNOSTIC du bouton « Sprawdź połączenie ». Il liste les imprimantes
 * que Fakturownia voit : c'est le nom à recopier dans « Drukarka ».
 * @return array{0:string, 1:string} [ok|uwaga|zle, phrase]
 */
function wsm_fiskal_diag(): array {
    $c = wsm_fiskal_cfg();
    $brak = [];
    if ($c['host'] === '')      $brak[] = 'adres konta (np. szoko.fakturownia.pl)';
    if ($c['api_token'] === '') $brak[] = 'kod autoryzacyjny API';
    if ($brak) return ['zle', 'Brak: ' . implode(' i ', $brak) . '. Paragony fiskalne nie powstają.'];
    [$code, $res] = wsm_fiskal_call('GET', '/printers.json');
    if ($code === 401 || $code === 403) return ['zle', 'Fakturownia odrzuciła kod API (' . $code . ').'];
    if ($code === 404) return ['zle', 'Nie ma konta ' . $c['host'] . ' albo moduł Paragony.pl nie jest włączony.'];
    if (!wsm_fiskal_ok($code, $res)) return ['uwaga', 'Fakturownia odpowiedziała: ' . wsm_fiskal_err($res, $code)];
    $nazwy = [];
    foreach ((array) $res as $p) {
        if (!is_array($p)) continue;
        $n = (string) ($p['name'] ?? ($p['fiskator_name'] ?? ($p['id'] ?? '')));
        if ($n !== '') $nazwy[] = $n;
    }
    if (!$nazwy) {
        return ['uwaga', 'Połączenie działa, ale Fakturownia nie widzi żadnej drukarki fiskalnej — '
                       . 'zainstaluj moduł Paragony.pl na komputerze przy drukarce.'];
    }
    if ($c['drukarka'] !== '' && !in_array($c['drukarka'], $nazwy, true)) {
        return ['uwaga', 'Drukarki „' . $c['drukarka'] . '” nie ma na koncie. Widoczne: ' . implode(', ', $nazwy) . '.'];
    }
    // LE MOMENT. Une vente payée d'avance s'enregistre à la PAYE, et le
    // paragon papier doit sortir avant que le colis parte — pas au clic
    // « Wysłane », quand il est déjà chez le transporteur.
    $moment = wsm_orders_cfg()['doc_status'];
    if ($moment !== 'oplacone') {
        return ['uwaga', 'Połączenie działa (drukarki: ' . implode(', ', $nazwy) . '), ale paragon powstaje '
                       . ($moment === 'nigdy' ? 'tylko ręcznie' : 'dopiero przy „' . $moment . '”')
                       . '. Ustaw w Superadminie wystawianie dokumentu przy „Opłacone”: kasa rejestruje przedpłatę '
                       . 'w chwili wpłaty, a papierowy paragon zdążysz włożyć do paczki.'];
    }
    return ['ok', 'Połączenie działa. Drukarki: ' . implode(', ', $nazwy) . '.'];
}

/**
 * LA LIGNE « Paragon fiskalny » d'une commande, pour la console (tiroir) :
 * ce qui partira, ou ce qui est parti, avec le geste qui débloque.
 *
 * @return ?array{co:string, etat:string, val:string, agir?:string, agirTxt?:string}
 *         null quand il n'y a rien à dire (facture)
 */
function wsm_fiskal_linia(array $o, ?array $doc, string $kind): ?array {
    if ($kind !== 'paragon') return null;
    if (!wsm_fiskal_enabled()) {
        return ['co' => 'Kasa', 'etat' => 'brak', 'val' => 'kasa fiskalna nie jest podłączona (Integracje → E-paragon fiskalny)'];
    }
    $forma = wsm_fiskal_forma($o) === 'e'
        ? 'e-paragon na ' . (string) $o['email'] : 'PAPIEROWY — wydrukuje się, włóż do paczki';
    if (!$doc) return ['co' => 'Kasa', 'etat' => 'ok', 'val' => 'powstanie z paragonem: ' . $forma];
    $st = (string) ($doc['fiskal_status'] ?? '');
    $note = trim((string) ($doc['fiskal_note'] ?? ''));
    return match ($st) {
        'gotowy'  => ['co' => 'Kasa', 'etat' => 'ok', 'val' => 'e-paragon wystawiony: ' . (string) $doc['fiskal_url']],
        'zlecony' => ['co' => 'Kasa', 'etat' => (string) ($doc['fiskal_forma'] ?? '') === 'e' ? 'wa' : 'ok',
                      'val' => (string) ($doc['fiskal_forma'] ?? '') === 'e'
                          ? 'zlecony — czeka na drukarkę (link przyjdzie sam)'
                          : 'PAPIEROWY zlecony do druku — włóż go do paczki'],
        'wysylka' => (string) ($doc['fiskal_at'] ?? '') !== '' && (string) $doc['fiskal_at'] < date('Y-m-d H:i:s', time() - 600)
            ? ['co' => 'Kasa', 'etat' => 'no', 'agir' => 'fiskal', 'agirTxt' => 'Sprawdź i ponów',
               'val' => 'wysyłka przerwana' . ((string) ($doc['fiskal_id'] ?? '') !== ''
                   ? ' po utworzeniu paragonu ' . $doc['fiskal_id'] . ' — sprawdź w Fakturowni, czy nie został zafiskalizowany'
                   : ' przed utworzeniem paragonu — ponowienie jest bezpieczne')]
            : ['co' => 'Kasa', 'etat' => 'wa', 'val' => 'wysyłanie do Fakturowni…'],
        'blad'    => ['co' => 'Kasa', 'etat' => 'no', 'agir' => 'fiskal',
                      'agirTxt' => (string) ($doc['fiskal_id'] ?? '') !== '' ? 'Ponów druk' : 'Wyślij ponownie',
                      'val' => $note . ((string) ($doc['fiskal_id'] ?? '') !== '' && !str_contains($note, 'sprawdź')
                          ? ' — przed ponowieniem sprawdź w Fakturowni lub na drukarce, czy paragon nie wyszedł' : '')],
        default   => ['co' => 'Kasa', 'etat' => 'no', 'agir' => 'fiskal', 'agirTxt' => 'Wyślij na kasę',
                      'val' => 'paragon ' . (string) $doc['number'] . ' nie przeszedł przez kasę fiskalną'],
    };
}

/**
 * Ce que voit le CLIENT sur la page de sa commande.
 * @return array{stan:string, url:string} stan : '' rien · 'e' en attente ·
 *         'gotowy' lien prêt · 'papier' dans le colis
 */
function wsm_fiskal_dla_klienta(PDO $pdo, array $o): array {
    if (!wsm_fiskal_enabled()) return ['stan' => '', 'url' => ''];
    $doc = wsm_invoice_for_order($pdo, (int) ($o['id'] ?? 0));
    if ($doc && (string) $doc['kind'] !== 'paragon') return ['stan' => '', 'url' => ''];
    if (!$doc && wsm_invoice_kind_for($o)['kind'] !== 'paragon') return ['stan' => '', 'url' => ''];
    if ($doc && (string) ($doc['fiskal_status'] ?? '') === 'gotowy' && (string) ($doc['fiskal_url'] ?? '') !== '') {
        return ['stan' => 'gotowy', 'url' => (string) $doc['fiskal_url']];
    }
    // Une fois l'impression demandée, c'est ce qui a été DEMANDÉ qui compte.
    $f = $doc ? (string) ($doc['fiskal_forma'] ?? '') : '';
    return ['stan' => $f !== '' ? $f : wsm_fiskal_forma($o), 'url' => ''];
}

/**
 * L'étiquette « kasa » d'un paragon dans la liste Faktury.
 * @return array{0:string, 1:string} [classe : ok|wait|bad|'' , texte] ; ['', ''] = rien à dire
 */
function wsm_fiskal_tag(array $inv): array {
    if (($inv['kind'] ?? '') !== 'paragon') return ['', ''];
    $st = (string) ($inv['fiskal_status'] ?? '');
    $papier = (string) ($inv['fiskal_forma'] ?? '') === 'papier';
    return match ($st) {
        'gotowy'  => ['ok', 'e-paragon'],
        'zlecony' => [$papier ? 'ok' : 'wait', $papier ? 'kasa: papierowy' : 'kasa: czeka na drukarkę'],
        'wysylka' => ['wait', 'kasa: wysyłanie'],
        'blad'    => ['bad', 'kasa: błąd'],
        default   => wsm_fiskal_enabled() ? ['wait', 'kasa: nie wysłano'] : ['', ''],
    };
}
