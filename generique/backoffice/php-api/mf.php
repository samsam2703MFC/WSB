<?php
// ============================================================================
//  mf.php — la Biała lista podatników VAT du ministère des Finances.
//
//  POURQUOI CE FICHIER EXISTE À CÔTÉ DE vies.php. On nous a demandé de remplir
//  les champs d'une facture « depuis VIES » à partir du NIP. VIES ne peut pas
//  le faire, et c'est le genre de détail qui se découvre en production :
//
//    VIES NE CONNAÎT QUE LES NUMÉROS ENREGISTRÉS POUR LE COMMERCE
//    INTRACOMMUNAUTAIRE. Une société polonaise qui vend uniquement en Pologne
//    n'y figure pas. Interroger VIES avec son NIP répond « numer nieznany » —
//    ce qui est vrai pour VIES et FAUX pour le client, dont le NIP est
//    parfaitement valide. On aurait livré un bouton qui accuse d'erreur des
//    clients irréprochables.
//
//  Le registre qui répond pour un NIP polonais, c'est la Biała lista du
//  ministère : gratuite, sans clé, elle rend la raison sociale, l'adresse et
//  le statut TVA. Les deux registres coexistent donc, et chacun répond à ce
//  qu'il sait :
//
//    · NIP polonais          → Biała lista (ici)
//    · numéro de TVA UE      → VIES (vies.php), qui décide en plus de
//                              l'autoliquidation
//
//  CE QUE ÇA N'EST PAS. Ce n'est pas la vérification qui protège une facture :
//  celle-là reste VIES, au moment de la livraison, avec son numéro de
//  consultation opposable. Ici on remplit un formulaire, rien de plus — et
//  c'est déjà beaucoup, parce qu'une adresse recopiée à la main sur une
//  facture est une correction de facture la semaine suivante.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/commerce.php';           // wsm_valid_nip()

const WSM_MF_ENDPOINT = 'https://wl-api.mf.gov.pl/api/search/nip/';
const WSM_MF_TIMEOUT  = 6;
const WSM_MF_TTL      = 86400 * 7;                // une semaine : une raison
                                                  // sociale ne change pas le mardi

function wsm_mf_cfg(): array {
    $c = wsm_config()['mf'] ?? [];
    return [
        'enabled'  => !array_key_exists('enabled', $c) || (bool) $c['enabled'],
        'endpoint' => (string) ($c['endpoint'] ?? WSM_MF_ENDPOINT),
        'timeout'  => (int) ($c['timeout'] ?? WSM_MF_TIMEOUT),
        'ttl'      => (int) ($c['ttl'] ?? WSM_MF_TTL),
    ];
}

function wsm_mf_enabled(): bool { return wsm_mf_cfg()['enabled']; }

/** Le NIP réduit à ses dix chiffres. « PL 897-190-26-20 » → « 8971902620 ». */
function wsm_mf_nip(string $raw): string {
    $n = preg_replace('/[^0-9]/', '', strtoupper(trim($raw))) ?? '';
    return strlen($n) === 10 ? $n : '';
}

/**
 * Le transport, remplaçable — comme pour VIES.
 *
 * Un test ne doit jamais appeler le ministère : ce serait lent, dépendant du
 * réseau, et impoli. C'est aussi le seul moyen d'éprouver les réponses qu'on
 * ne peut pas provoquer à volonté — un 429, un service en panne.
 */
function wsm_mf_transport(?callable $set = null): callable {
    static $fn = null;
    if ($set !== null) { $fn = $set; }
    return $fn ?? 'wsm_mf_http';
}

/** @return array{0:int,1:mixed} [code HTTP, corps décodé] */
function wsm_mf_http(string $nip, string $date): array {
    $cfg = wsm_mf_cfg();
    $url = $cfg['endpoint'] . rawurlencode($nip) . '?date=' . rawurlencode($date);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $cfg['timeout'],
        CURLOPT_CONNECTTIMEOUT => min(4, $cfg['timeout']),
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $raw, true)];
}

/**
 * Traduit une réponse du ministère en un état à nous.
 *
 * MÊME DISCIPLINE QUE POUR VIES : « ce NIP n'existe pas » et « je n'ai pas pu
 * demander » ne sont pas la même chose et ne doivent jamais se confondre. Le
 * premier se dit au client ; le second se tait et laisse le formulaire tel
 * quel, parce qu'un service en panne n'est pas une faute du client.
 */
function wsm_mf_interpret(int $httpCode, $res): array {
    if ($httpCode === 0 || $httpCode >= 500 || !is_array($res)) {
        return ['status' => 'unavailable', 'reason' => 'rejestr MF nie odpowiada (' . $httpCode . ')'];
    }
    if ($httpCode === 429) {
        return ['status' => 'unavailable', 'reason' => 'zbyt wiele zapytań do rejestru — spróbuj za chwilę'];
    }
    $suj = $res['result']['subject'] ?? null;
    if ($httpCode === 200 && is_array($suj)) {
        // L'ADRESSE VIENT DE DEUX CHAMPS, et jamais des deux en même temps :
        // une société a une adresse d'activité, un entrepreneur individuel une
        // adresse de résidence. N'en lire qu'un laissait la moitié des clients
        // avec un formulaire à moitié rempli — pire qu'un formulaire vide,
        // parce qu'on croit qu'il est complet.
        $adr = trim((string) ($suj['workingAddress'] ?? ''));
        if ($adr === '') $adr = trim((string) ($suj['residenceAddress'] ?? ''));
        return [
            'status'       => 'valid',
            'reason'       => '',
            'name'         => trim((string) ($suj['name'] ?? '')),
            'address'      => $adr,
            'vat_status'   => trim((string) ($suj['statusVat'] ?? '')),
            'consultation' => (string) ($res['result']['requestId'] ?? ''),
        ];
    }
    if ($httpCode === 200 || $httpCode === 404) {
        return ['status' => 'invalid', 'reason' => 'nie znaleziono tego NIP w rejestrze'];
    }
    if ($httpCode === 400) {
        return ['status' => 'invalid', 'reason' => 'nieprawidłowy NIP'];
    }
    $msg = trim((string) ($res['message'] ?? ''));
    return ['status' => 'unavailable', 'reason' => $msg !== '' ? $msg : ('MF: ' . $httpCode)];
}

/**
 * Le dernier résultat CONCLUANT encore frais pour ce NIP.
 *
 * `source = 'mf'` n'est pas une décoration : la table sert aux deux registres,
 * et une réponse du ministère prise pour une réponse de VIES ferait appliquer
 * — ou refuser — l'autoliquidation sur une preuve qui n'en est pas une.
 */
function wsm_mf_cached(PDO $pdo, string $nip, int $ttl): ?array {
    try {
        $st = $pdo->prepare("SELECT status, name, address, consultation, checked_at
                               FROM wsm_vies_checks
                              WHERE vat_eu = ? AND source = 'mf' AND status IN ('valid','invalid')
                              ORDER BY id DESC LIMIT 1");
        $st->execute(['PL' . $nip]);
        $r = $st->fetch();
    } catch (Throwable $e) { return null; }
    if (!$r) return null;
    $age = time() - (strtotime((string) $r['checked_at']) ?: 0);
    if ($age > $ttl) return null;
    return ['status' => (string) $r['status'], 'reason' => '',
            'name' => (string) $r['name'], 'address' => (string) $r['address'],
            'consultation' => (string) $r['consultation'], 'cached' => true];
}

/**
 * Cherche un NIP dans la Biała lista, avec cache.
 *
 * @return array{status:string,reason:string,name?:string,address?:string,...}
 */
function wsm_mf_check(PDO $pdo, string $nipRaw, bool $force = false): array {
    $nip = wsm_mf_nip($nipRaw);
    if ($nip === '')            return ['status' => 'invalid', 'reason' => 'NIP ma 10 cyfr'];
    // LE CHIFFRE DE CONTRÔLE SE VÉRIFIE ICI, PAS LÀ-BAS. Une faute de frappe
    // se détecte sans appeler personne, et le ministère limite le nombre de
    // questions : les gaspiller sur des numéros qu'on sait faux, c'est se
    // retrouver bloqué au moment où l'on a une vraie question.
    if (!wsm_valid_nip($nip))   return ['status' => 'invalid', 'reason' => 'błędna suma kontrolna NIP'];
    if (!wsm_mf_enabled())      return ['status' => 'skipped', 'reason' => 'rejestr MF wyłączony'];

    $cfg = wsm_mf_cfg();
    if (!$force) {
        $hit = wsm_mf_cached($pdo, $nip, $cfg['ttl']);
        if ($hit) return $hit;
    }

    [$code, $body] = (wsm_mf_transport())($nip, date('Y-m-d'));
    $out = wsm_mf_interpret($code, $body);

    // ON N'ÉCRIT QUE CE QUI TRANCHE. Un « je ne sais pas » mis en cache ferait
    // taire le registre pendant une semaine, sur un numéro parfaitement bon.
    if (in_array($out['status'], ['valid', 'invalid'], true)) {
        try {
            $pdo->prepare("INSERT INTO wsm_vies_checks
                             (vat_eu, country, number, status, reason, name, address, consultation, source, raw)
                           VALUES (?,?,?,?,?,?,?,?, 'mf', ?)")
                ->execute(['PL' . $nip, 'PL', $nip, $out['status'], (string) ($out['reason'] ?? ''),
                           (string) ($out['name'] ?? ''), (string) ($out['address'] ?? ''),
                           (string) ($out['consultation'] ?? ''),
                           json_encode($body, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) { /* le cache n'est pas la réponse */ }
    }
    return $out;
}

/**
 * L'adresse du ministère, découpée comme la boutique la range.
 *
 * Elle arrive en une ligne : « ul. Polna 1 lok. 3, 00-002 Wrocław ». Les
 * formulaires, eux, ont quatre champs. Un découpage qui échoue rend des champs
 * VIDES plutôt que faux : une rue posée dans la case du code postal se corrige
 * moins vite qu'une case qu'on remplit soi-même.
 *
 * @return array{street:string,building:string,postcode:string,city:string}
 */
function wsm_mf_adresse(string $adr): array {
    $out = ['street' => '', 'building' => '', 'postcode' => '', 'city' => ''];
    $adr = trim(preg_replace('/\s+/', ' ', $adr) ?? '');
    if ($adr === '') return $out;

    // Le code postal polonais est le seul repère sûr de la chaîne.
    if (!preg_match('/(\d{2}-\d{3})/', $adr, $m)) return $out;
    $out['postcode'] = $m[1];
    $pos = strpos($adr, $m[1]);
    $gauche = trim(rtrim(substr($adr, 0, (int) $pos), ' ,'));
    $out['city'] = trim(substr($adr, (int) $pos + strlen($m[1])), ' ,');

    // À gauche : la rue et le numéro. Le numéro est ce qui commence par un
    // chiffre à la fin — « ul. Polna 1 lok. 3 » comme « Rynek 12A ».
    if (preg_match('/^(.*?)[\s,]+(\d[0-9A-Za-z\/\.\- ]*)$/u', $gauche, $mm)) {
        $out['street']   = trim($mm[1], ' ,');
        $out['building'] = trim($mm[2], ' ,');
    } else {
        $out['street'] = $gauche;
    }
    return $out;
}
