<?php
// ============================================================================
//  vies.php — vérification des numéros de TVA intracommunautaire auprès de
//  VIES (VAT Information Exchange System, Commission européenne).
//
//  Jusqu'ici on ne vérifiait que la FORME : « PL5252248481 » ressemblait à un
//  numéro de TVA, donc il passait. Ressembler ne suffit pas — c'est VIES qui
//  dit si le numéro existe, et à quelle entreprise il appartient.
//
//  ── La règle qui compte ───────────────────────────────────────────────────
//  VIES interroge chaque administration nationale en direct. Ces
//  administrations tombent, régulièrement et pour de vrai. Il faut donc
//  distinguer deux choses que rien n'oblige à confondre :
//
//    · « INVALID »       → le numéro n'existe pas. On refuse la saisie.
//    · « indisponible »  → on ne SAIT pas. On enregistre, on marque le client
//                          « à revérifier », et la vente continue.
//
//  Refuser une commande parce qu'un service de la Commission est en panne
//  serait un dégât plus grand que celui qu'on cherche à éviter.
//
//  ── La preuve ────────────────────────────────────────────────────────────
//  Quand on fournit NOTRE propre numéro de TVA, VIES renvoie un numéro de
//  consultation. C'est lui qui prouve, en cas de contrôle, qu'on a vérifié le
//  client à une date donnée. Il est conservé avec chaque contrôle.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/commerce.php';

const WSM_VIES_ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';
const WSM_VIES_TTL      = 2592000;   // 30 jours : un contrôle ne se refait pas à chaque écran
const WSM_VIES_TIMEOUT  = 6;         // s — au-delà, un formulaire paraît planté

/** États que NOUS produisons, indépendants du vocabulaire de VIES. */
const WSM_VIES_STATUSES = ['valid', 'invalid', 'unavailable', 'skipped'];

function wsm_vies_cfg(): array {
    $c = wsm_config()['vies'] ?? [];
    return [
        'enabled'   => !array_key_exists('enabled', $c) || (bool) $c['enabled'],
        'endpoint'  => (string) ($c['endpoint'] ?? WSM_VIES_ENDPOINT),
        // Notre propre numéro de TVA : sans lui VIES ne délivre pas de numéro
        // de consultation, donc pas de preuve opposable.
        'requester' => strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($c['requester'] ?? ''))),
        'timeout'   => (int) ($c['timeout'] ?? WSM_VIES_TIMEOUT),
        'ttl'       => (int) ($c['ttl'] ?? WSM_VIES_TTL),
    ];
}

/** Le numéro de consultation n'est délivré que si l'on s'identifie. */
function wsm_vies_can_prove(): bool { return wsm_vies_cfg()['requester'] !== ''; }

/** « pl 525-224-84-81 » → ['PL', '5252248481']. */
function wsm_vies_split(string $vat): array {
    $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vat) ?? '');
    if (strlen($v) < 3 || !preg_match('/^[A-Z]{2}/', $v)) return ['', ''];
    return [substr($v, 0, 2), substr($v, 2)];
}

function wsm_vies_normalize(string $vat): string {
    [$c, $n] = wsm_vies_split($vat);
    return $c === '' ? '' : $c . $n;
}

/**
 * Transport HTTP, remplaçable. Les tests injectent une fonction pour éprouver
 * la logique de décision sans dépendre d'un service public qui, par nature,
 * n'est pas disponible à la demande.
 */
function wsm_vies_transport(?callable $set = null): callable {
    static $fn = null;
    if ($set !== null) $fn = $set;
    if ($fn === null) $fn = 'wsm_vies_http';
    return $fn;
}

/** Appel réel. Renvoie [code HTTP, corps décodé|null]. */
function wsm_vies_http(string $country, string $number): array {
    $cfg = wsm_vies_cfg();
    $body = ['countryCode' => $country, 'vatNumber' => $number];
    if ($cfg['requester'] !== '') {
        [$rc, $rn] = wsm_vies_split($cfg['requester']);
        if ($rc !== '') { $body['requesterMemberStateCode'] = $rc; $body['requesterNumber'] = $rn; }
    }
    $ch = curl_init($cfg['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $cfg['timeout'],
        CURLOPT_CONNECTTIMEOUT => min(4, $cfg['timeout']),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body),
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $raw, true)];
}

/**
 * Traduit une réponse VIES en un état à nous.
 *
 * Le vocabulaire de VIES mélange « ce numéro n'existe pas » et « je n'ai pas
 * pu demander » dans le même champ. C'est ici que les deux sont séparés, une
 * fois pour toutes.
 */
function wsm_vies_interpret(int $httpCode, $res): array {
    if ($httpCode === 0 || $httpCode >= 500 || !is_array($res)) {
        return ['status' => 'unavailable', 'reason' => 'brak odpowiedzi VIES (' . $httpCode . ')'];
    }
    $err = strtoupper((string) ($res['userError'] ?? ($res['actionSucceed'] ?? '') ?: ''));

    // Réponses qui tranchent.
    if (!empty($res['isValid'])) {
        return [
            'status'       => 'valid',
            'reason'       => '',
            'name'         => trim((string) ($res['name'] ?? '')),
            'address'      => trim(preg_replace('/\s*\n\s*/', ', ', (string) ($res['address'] ?? '')) ?? ''),
            'consultation' => (string) ($res['requestIdentifier'] ?? ''),
        ];
    }
    if ($err === 'INVALID' || ($httpCode === 200 && array_key_exists('isValid', $res) && $res['isValid'] === false && $err === '')) {
        // LE NUMÉRO DE CONSULTATION COMPTE AUSSI POUR UN REFUS — et sans doute
        // plus. Il prouve qu'on a vérifié le jour de la livraison, donc qu'on
        // avait raison de NE PAS appliquer l'autoliquidation. Il n'était gardé
        // que sur la réponse positive : la preuve manquait exactement dans le
        // cas où l'on aurait à s'expliquer.
        return ['status' => 'invalid', 'reason' => 'numer nieznany w VIES',
                'consultation' => (string) ($res['requestIdentifier'] ?? '')];
    }
    if ($err === 'INVALID_INPUT') {
        return ['status' => 'invalid', 'reason' => 'nieprawidłowy format numeru'];
    }

    // Tout le reste veut dire « je ne sais pas », pas « c'est faux ».
    $unknown = ['MS_UNAVAILABLE' => 'administracja kraju niedostępna',
                'SERVICE_UNAVAILABLE' => 'VIES niedostępny',
                'TIMEOUT' => 'przekroczono czas oczekiwania',
                'VAT_BLOCKED' => 'zapytanie zablokowane',
                'IP_BLOCKED' => 'zapytanie zablokowane',
                'GLOBAL_MAX_CONCURRENT_REQ' => 'VIES przeciążony',
                'MS_MAX_CONCURRENT_REQ' => 'VIES przeciążony'];
    return ['status' => 'unavailable', 'reason' => $unknown[$err] ?? ('VIES: ' . ($err ?: $httpCode))];
}

/** Dernier contrôle CONCLUANT encore valable pour ce numéro. */
function wsm_vies_cached(PDO $pdo, string $vat, int $ttl): ?array {
    // Un « indisponible » n'est jamais mis en cache : ce serait figer une panne
    // et empêcher le contrôle de réussir plus tard.
    // `source` : la table sert desormais aussi a la Biala lista du ministere.
    // Sans ce filtre, une reponse du ministere serait relue comme une reponse
    // de VIES, et l'autoliquidation serait accordee — ou refusee — sur une
    // preuve qui n'en est pas une. Les lignes d'avant la colonne sont NULL,
    // et elles viennent toutes de VIES : elles restent lisibles.
    $st = $pdo->prepare("SELECT * FROM wsm_vies_checks
                          WHERE vat_eu = ? AND status IN ('valid','invalid')
                            AND (source IS NULL OR source = 'vies')
                          ORDER BY id DESC LIMIT 1");
    $st->execute([$vat]);
    $r = $st->fetch();
    if (!$r) return null;
    if (strtotime((string) $r['checked_at']) < time() - $ttl) return null;
    return [
        'status' => (string) $r['status'], 'reason' => (string) $r['reason'],
        'name' => (string) $r['name'], 'address' => (string) $r['address'],
        'consultation' => (string) $r['consultation'],
        'checked_at' => (string) $r['checked_at'], 'cached' => true,
        'vat_eu' => $vat, 'country' => (string) $r['country'],
    ];
}

/**
 * Vérifie un numéro. Renvoie toujours un tableau — jamais d'exception : un
 * contrôle de TVA ne doit pas pouvoir faire tomber une commande.
 *
 * @param bool $force ignore le cache (bouton « Sprawdź ponownie »)
 */
function wsm_vies_check(PDO $pdo, string $vat, bool $force = false): array {
    $cfg = wsm_vies_cfg();
    $norm = wsm_vies_normalize($vat);

    if (trim($vat) === '') {
        return ['status' => 'skipped', 'reason' => 'brak numeru', 'vat_eu' => '', 'country' => ''];
    }
    if ($norm === '' || !wsm_valid_vat_eu($norm)) {
        return ['status' => 'invalid', 'reason' => 'format VIES (np. PL5252248481)', 'vat_eu' => $norm, 'country' => ''];
    }
    if (!$cfg['enabled']) {
        return ['status' => 'skipped', 'reason' => 'weryfikacja VIES wyłączona', 'vat_eu' => $norm,
                'country' => substr($norm, 0, 2)];
    }

    if (!$force) {
        $hit = wsm_vies_cached($pdo, $norm, $cfg['ttl']);
        if ($hit) return $hit;
    }

    [$country, $number] = wsm_vies_split($norm);
    try {
        [$code, $res] = (wsm_vies_transport())($country, $number);
    } catch (Throwable $e) {
        $code = 0; $res = null;
    }
    $out = wsm_vies_interpret((int) $code, $res) + [
        'name' => '', 'address' => '', 'consultation' => '',
    ];
    $out['vat_eu']  = $norm;
    $out['country'] = $country;
    $out['cached']  = false;
    $out['checked_at'] = date('Y-m-d H:i:s');

    // Toute consultation est journalisée, y compris celles qui n'ont rien pu
    // dire : c'est l'historique qui fait la preuve, pas le dernier état.
    try {
        $pdo->prepare("INSERT INTO wsm_vies_checks
                        (vat_eu, country, number, status, reason, name, address, consultation, raw)
                       VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$norm, $country, $number, $out['status'], $out['reason'],
                       mb_substr($out['name'], 0, 250), mb_substr($out['address'], 0, 500),
                       $out['consultation'], mb_substr(json_encode($res, JSON_UNESCAPED_UNICODE) ?: '', 0, 2000)]);
    } catch (Throwable $e) { /* la table manque : on ne fait pas échouer le contrôle pour autant */ }

    return $out;
}

/** Un état qui doit REFUSER la saisie. L'indisponibilité n'en fait pas partie. */
function wsm_vies_blocks(array $result): bool {
    return ($result['status'] ?? '') === 'invalid';
}

/**
 * Le client est-il éligible à l'autoliquidation (reverse charge) ?
 *
 * Numéro valide ET délivré par un autre État membre que le nôtre. Ce n'est
 * qu'une CONSTATATION : la boutique facture toujours la TVA polonaise, parce
 * qu'elle ne livre aujourd'hui qu'en Pologne (InPost). Appliquer 0 % supposerait
 * une livraison intracommunautaire, qui n'existe pas encore ici.
 */
function wsm_vies_reverse_charge(array $result, string $homeCountry = 'PL'): bool {
    return ($result['status'] ?? '') === 'valid'
        && ($result['country'] ?? '') !== ''
        && ($result['country'] ?? '') !== strtoupper($homeCountry);
}

/** Ce qu'on écrit sur un client ou une commande après contrôle. */
function wsm_vies_columns(array $r): array {
    return [
        'vat_status'       => (string) ($r['status'] ?? 'skipped'),
        'vat_checked_at'   => ($r['status'] ?? '') === 'skipped' ? null : ($r['checked_at'] ?? date('Y-m-d H:i:s')),
        'vat_name'         => mb_substr((string) ($r['name'] ?? ''), 0, 200),
        'vat_consultation' => mb_substr((string) ($r['consultation'] ?? ''), 0, 60),
    ];
}
