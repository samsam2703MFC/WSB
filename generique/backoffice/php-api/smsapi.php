<?php
// ============================================================================
//  smsapi.php — les SMS marketing par SMSAPI (LINK Mobility), comme le CRM.
//
//  Le client est celui du CRM d'Atelier By (lib/sms.php), adapté à une
//  boutique polonaise : jeton OAuth, plateforme .pl par défaut, adresse de
//  secours api2 seulement sur une panne de transport, refus de SMSAPI lu dans
//  le corps (il répond 200 même quand il refuse), accusés de l'opérateur.
//
//  CE QUI COÛTE DE L'ARGENT SE VOIT AVANT :
//
//   1. LE NOMBRE DE SMS FACTURÉS. Un caractère polonais (ą, ł, ż…) n'existe
//      pas en GSM-7 : le message passe en Unicode et tombe de 160 à 70
//      caractères par SMS — le même texte coûte deux à trois fois plus.
//      L'option « bez polskich znaków » (cochée d'office) remplace ą par a ;
//      le compteur est celui de ce qui part VRAIMENT.
//   2. LE COÛT ESTIMÉ, avant l'envoi : SMS × prix × destinataires.
//   3. LA SORTIE. Chaque SMS marketing porte un lien court de désinscription
//      (« Rezygnacja: …/s/abcd2345 »), compté dans la longueur.
//   4. LES HEURES. Pas de SMS marketing la nuit : la file attend 8 h
//      (heure de Varsovie) et s'arrête à 21 h.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/marketing.php';

/** Prix d'un SMS si personne ne l'a réglé (grosze, SMSAPI PRO, ordre de grandeur). */
const WSM_SMS_CENA_DOMYSLNA = 16;

/** Fenêtre d'envoi des SMS marketing, heure de Varsovie : [8 h, 21 h[. */
const WSM_SMS_GODZINY = [8, 21];

/** Un SMS en file plus vieux que ça ne part plus tout seul. */
const WSM_SMS_AGE_J = 3;

/** Statuts de l'opérateur (code SMSAPI => nom ; le rappel porte aussi status_name). */
const WSM_SMS_STATUTS = [401 => 'NOT_FOUND', 402 => 'EXPIRED', 403 => 'SENT', 404 => 'DELIVERED', 405 => 'UNDELIVERED',
    406 => 'FAILED', 407 => 'REJECTED', 408 => 'UNKNOWN', 409 => 'QUEUE', 410 => 'ACCEPTED', 411 => 'RENEWAL', 412 => 'STOP'];
/** Statuts définitifs : un statut d'attente arrivé en retard ne les remplace pas. */
const WSM_SMS_FINAUX = ['DELIVERED', 'UNDELIVERED', 'EXPIRED', 'FAILED', 'REJECTED', 'NOT_FOUND', 'STOP'];

function wsm_sms_cfg(): array {
    $c = (array) (wsm_config()['smsapi'] ?? []);
    $v = static function (string $k) use ($c): string {
        $s = trim((string) ($c[$k] ?? ''));
        return preg_match('/^x{2,}$/i', $s) ? '' : $s;
    };
    $cena = str_replace(',', '.', $v('cena'));
    return [
        'token'   => $v('token'),
        'nadawca' => $v('nadawca'),
        'region'  => strtolower($v('region')) === 'com' ? 'com' : 'pl',
        // Saisi en złotys (« 0,16 ») ; gardé en grosze.
        'cena'    => is_numeric($cena) && (float) $cena > 0 ? (int) round((float) $cena * 100) : WSM_SMS_CENA_DOMYSLNA,
    ];
}

/** Le canal est-il ouvert ? Jeton + nom d'expéditeur validé chez SMSAPI. */
function wsm_sms_enabled(): bool {
    $c = wsm_sms_cfg();
    return $c['token'] !== '' && $c['nadawca'] !== '';
}

/** .pl et .com sont deux plateformes : un jeton n'est valable que sur la sienne. */
function wsm_sms_hosts(): array {
    return wsm_sms_cfg()['region'] === 'com'
        ? ['https://api.smsapi.com', 'https://api2.smsapi.com']
        : ['https://api.smsapi.pl', 'https://api2.smsapi.pl'];
}

/**
 * L'appel HTTP. Repli sur api2 UNIQUEMENT sur une panne de transport ou un
 * 5xx : un refus métier (crédit épuisé, expéditeur non validé) répond 200 et
 * serait refusé pareil ailleurs — le rejouer ferait deux SMS.
 *
 * @return array{0:int, 1:mixed, 2:string} [code, réponse décodée, hôte appelé]
 */
function wsm_sms_http(string $method, string $path, array $params = []): array {
    $c = wsm_sms_cfg();
    $hosts = wsm_sms_hosts();
    $res = false; $code = 0; $hote = '';
    foreach ($hosts as $i => $hote) {
        $url = $hote . $path . ($method === 'GET' && $params ? '?' . http_build_query($params) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $c['token']],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => $method === 'GET' ? null : http_build_query($params),
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $joint = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME) > 0;
        curl_close($ch);
        // Le repli ne rejoue QUE ce qui n'a pas pu arriver : serveur injoignable,
        // ou passerelle qui dit ne pas avoir transmis (502/503/504). Une réponse
        // perdue APRÈS la connexion peut cacher un SMS déjà parti (et payé).
        $rejouable = ($res === false && !$joint) || in_array($code, [502, 503, 504], true) || ($method === 'GET' && $res === false);
        if (!$rejouable || $i === count($hosts) - 1) break;
    }
    return [$res === false ? 0 : $code, json_decode((string) $res, true), $hote];
}

/** Le transport, remplaçable — les tests n'envoient jamais un vrai SMS. */
function wsm_sms_transport(?callable $set = null): callable {
    static $fn = null;
    if ($set !== null) { $fn = $set; }
    return $fn ?? 'wsm_sms_http';
}

function wsm_sms_call(string $method, string $path, array $params = []): array {
    $r = (wsm_sms_transport())($method, $path, $params);
    return [(int) ($r[0] ?? 0), $r[1] ?? null, (string) ($r[2] ?? '')];
}

/** Le refus de SMSAPI, en clair, avec ce qu'il faut faire. */
function wsm_sms_err($res, int $code, string $hote = ''): string {
    if ($code === 0) return 'brak odpowiedzi z SMSAPI';
    if (is_array($res) && isset($res['error'])) {
        $msg = trim((string) ($res['message'] ?? '')) ?: 'błąd SMSAPI';
        $m = $msg . ' (kod ' . $res['error'] . ')';
        if ((int) $res['error'] === 101) {
            $m .= ' — sprawdź, czy to token OAuth z uprawnieniem „sms” i czy wybrana platforma (.pl / .com) jest tą, na której założono konto';
        } elseif ((int) $res['error'] === 103) {
            $m .= ' — brak środków na koncie SMSAPI';
        } elseif ((int) $res['error'] === 14) {
            $m .= ' — nazwa nadawcy nie jest jeszcze zatwierdzona w panelu SMSAPI';
        }
        return $m . ($hote !== '' ? ' [' . preg_replace('#^https?://#', '', $hote) . ']' : '');
    }
    return 'HTTP ' . $code;
}

// ---------------------------------------------------------------------------
//  Ce que coûte un texte
// ---------------------------------------------------------------------------

/** L'alphabet GSM-7 de base, et son extension (deux unités chacun). */
const WSM_SMS_GSM7 = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
const WSM_SMS_GSM7_EXT = "^{}\\[~]|€";

/** Sans accents polonais ni typographie : le texte reste en GSM-7. */
function wsm_sms_ascii(string $t): string {
    $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
            '„' => '"', '”' => '"', '“' => '"', '‘' => "'", '’' => "'", '–' => '-', '—' => '-', '…' => '...',
            "\u{00A0}" => ' ', "\u{202F}" => ' '];
    return strtr($t, $map);
}

/**
 * Longueur et nombre de SMS facturés.
 * @return array{znaki:int, czesci:int, kodowanie:string, limit:int}
 */
function wsm_sms_parts(string $t): array {
    $gsm = true; $unites = 0;
    foreach (mb_str_split($t) as $ch) {
        if (mb_strpos(WSM_SMS_GSM7, $ch) !== false) { $unites++; continue; }
        if (mb_strpos(WSM_SMS_GSM7_EXT, $ch) !== false) { $unites += 2; continue; }
        $gsm = false; break;
    }
    if ($gsm) {
        return ['znaki' => $unites, 'czesci' => $unites === 0 ? 0 : ($unites <= 160 ? 1 : (int) ceil($unites / 153)),
                'kodowanie' => 'GSM-7', 'limit' => $unites <= 160 ? 160 : 153];
    }
    // UCS-2 : un caractère hors du plan de base (emoji) compte double.
    $n = strlen((string) mb_convert_encoding($t, 'UTF-16BE', 'UTF-8')) / 2;
    return ['znaki' => (int) $n, 'czesci' => $n <= 70 ? 1 : (int) ceil($n / 67),
            'kodowanie' => 'Unicode', 'limit' => $n <= 70 ? 70 : 67];
}

/** L'heure de la pompe — remplaçable par les tests (la nuit ne s'attend pas). */
function wsm_sms_teraz(?int $set = null): int {
    static $t = null;
    if ($set !== null) $t = $set > 0 ? $set : null;
    return $t ?? time();
}

/** Envoyer la nuit ? Non (heure de Varsovie). */
function wsm_sms_godziny_ok(?int $ts = null): bool {
    $d = new DateTime('@' . ($ts ?? wsm_sms_teraz()));
    $d->setTimezone(new DateTimeZone('Europe/Warsaw'));
    $h = (int) $d->format('G');
    return $h >= WSM_SMS_GODZINY[0] && $h < WSM_SMS_GODZINY[1];
}

// ---------------------------------------------------------------------------
//  L'envoi d'un SMS
// ---------------------------------------------------------------------------

/** La clé de l'adresse des accusés (jamais devinable, jamais stockée en clair ailleurs). */
function wsm_sms_raport_key(): string {
    return substr(hash_hmac('sha256', 'sms-raport', wsm_mkt_secret()), 0, 24);
}

function wsm_sms_raport_url(): string {
    return wsm_api_base_url() . '/shop/sms/raport?k=' . wsm_sms_raport_key();
}

/**
 * Envoie UN SMS. Aucune exception ne remonte.
 *
 * $idx : l'identifiant de NOTRE ligne. Avec check_idx, SMSAPI refuse un second
 * SMS portant le même dans les 24 h : un renvoi après une réponse perdue ne
 * se paie pas deux fois.
 *
 * @return array{ok:bool, sid:string, error:string, panne:bool}
 *         panne = le compte ou le réseau, pas ce SMS (crédit, jeton, nadawca,
 *         pas de réponse) : la ligne doit attendre, pas échouer.
 */
function wsm_sms_send_raw(string $to, string $text, bool $raport = true, string $idx = ''): array {
    if (!wsm_sms_enabled()) return ['ok' => false, 'sid' => '', 'error' => 'SMSAPI nieskonfigurowane', 'panne' => true];
    $num = wsm_mkt_phone($to);
    if ($num === '') return ['ok' => false, 'sid' => '', 'error' => 'nieprawidłowy numer', 'panne' => false];
    if (trim($text) === '') return ['ok' => false, 'sid' => '', 'error' => 'pusta treść', 'panne' => false];
    $p = [
        'to'       => ltrim($num, '+'),            // SMSAPI : sans le « + »
        'from'     => wsm_sms_cfg()['nadawca'],
        'message'  => $text,
        'format'   => 'json',
        // Sans ce paramètre, SMSAPI lit en windows-1250 : « ż » arriverait en « ?? ».
        'encoding' => 'utf-8',
    ];
    if ($raport && preg_match('#^https://#', wsm_sms_raport_url())) $p['notify_url'] = wsm_sms_raport_url();
    if ($idx !== '') { $p['idx'] = $idx; $p['check_idx'] = 1; }
    try {
        [$code, $res, $hote] = wsm_sms_call('POST', '/sms.do', $p);
    } catch (Throwable $e) {
        return ['ok' => false, 'sid' => '', 'error' => 'błąd: ' . $e->getMessage(), 'panne' => true];
    }
    // SMSAPI répond 200 même pour un refus : c'est « error » qui fait foi.
    if (!is_array($res) || isset($res['error']) || $code < 200 || $code >= 300) {
        $kod = is_array($res) ? (int) ($res['error'] ?? 0) : 0;
        // 101/102 jeton, 103 crédit, 14 expéditeur non validé : tout le compte est arrêté.
        $panne = $code === 0 || $code >= 500 || in_array($kod, [101, 102, 103, 14], true);
        return ['ok' => false, 'sid' => '', 'error' => wsm_sms_err($res, $code, $hote), 'panne' => $panne];
    }
    return ['ok' => true, 'sid' => (string) ($res['list'][0]['id'] ?? ''), 'error' => '', 'panne' => false];
}

/**
 * Le diagnostic du bouton « Sprawdź połączenie » : le jeton, la plateforme,
 * le crédit restant.
 * @return array{0:string, 1:string} [ok|uwaga|zle, phrase]
 */
function wsm_sms_diag(): array {
    $c = wsm_sms_cfg();
    $brak = [];
    if ($c['token'] === '')   $brak[] = 'token OAuth';
    if ($c['nadawca'] === '') $brak[] = 'nazwa nadawcy';
    if ($brak) return ['zle', 'Brak: ' . implode(' i ', $brak) . '. SMS-y nie wychodzą.'];
    [$code, $res, $hote] = wsm_sms_call('GET', '/profile', []);
    if (is_array($res) && isset($res['error'])) return ['zle', 'SMSAPI odrzuciło: ' . wsm_sms_err($res, $code, $hote)];
    if ($code === 401 || $code === 403) return ['zle', 'SMSAPI odrzuciło token (' . $code . ') — sprawdź platformę ' . $c['region'] . '.'];
    if ($code < 200 || $code >= 300) return ['uwaga', 'SMSAPI odpowiedziało ' . $code . ' — spróbuj za chwilę.'];
    $pkt = is_array($res) && isset($res['points']) ? ' Środki na koncie: ' . number_format((float) $res['points'], 2, ',', ' ') . '.' : '';
    return ['ok', 'Połączenie z SMSAPI.' . $c['region'] . ' działa. Nadawca: ' . $c['nadawca'] . '.' . $pkt];
}

// ---------------------------------------------------------------------------
//  Les campagnes
// ---------------------------------------------------------------------------

function wsm_sms_camp_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM wsm_sms_campaigns WHERE id = ?");
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) return null;
    $c['tlumaczenia'] = json_decode((string) ($c['tlumaczenia'] ?? ''), true) ?: [];
    return $c;
}

/**
 * Crée ou met à jour un brouillon. Une campagne partie ne se modifie plus.
 * @return array{0:int, 1:string} [id, message]
 */
function wsm_sms_camp_save(PDO $pdo, array $in, string $actor): array {
    $id = (int) ($in['id'] ?? 0);
    $tresc = trim(str_replace("\r", '', (string) ($in['tresc'] ?? '')));
    $seg = (string) ($in['segment'] ?? 'wszyscy');
    if (!isset(WSM_MKT_SEGMENTS[$seg])) return [0, 'Nieznana grupa odbiorców.'];
    if (mb_strlen($tresc) < 5) return [0, 'Treść SMS-a jest za krótka.'];
    if (mb_strlen($tresc) > 600) return [0, 'Treść jest za długa (maks. 600 znaków — to już 4 SMS-y).'];
    $tl = [];
    foreach (['uk', 'en'] as $l) {
        $t = trim(str_replace("\r", '', (string) ($in['tresc_' . $l] ?? '')));
        if ($t !== '') $tl[$l] = mb_substr($t, 0, 600);
    }
    $kod = strtoupper(trim((string) ($in['kod'] ?? '')));
    $nazwa = trim((string) ($in['nazwa'] ?? '')) ?: mb_substr($tresc, 0, 40);
    $now = date('Y-m-d H:i:s');
    $vals = [mb_substr($nazwa, 0, 190), $tresc, json_encode($tl, JSON_UNESCAPED_UNICODE), $seg,
             !empty($in['ascii']) ? 1 : 0, mb_substr($kod, 0, 40), $now];
    if ($id > 0) {
        $c = wsm_sms_camp_get($pdo, $id);
        if (!$c) return [0, 'Nie znaleziono kampanii.'];
        if ((string) $c['status'] !== 'szkic') return [0, 'Ta kampania już wyszła — utwórz nową.'];
        $pdo->prepare("UPDATE wsm_sms_campaigns SET nazwa = ?, tresc = ?, tlumaczenia = ?, segment = ?, ascii = ?, kod = ?, updated_at = ?
                        WHERE id = ?")->execute([...$vals, $id]);
        return [$id, 'Zapisano.'];
    }
    $pdo->prepare("INSERT INTO wsm_sms_campaigns (nazwa, tresc, tlumaczenia, segment, ascii, kod, updated_at, created_by, created_at, status)
                   VALUES (?,?,?,?,?,?,?,?,?,'szkic')")->execute([...$vals, mb_substr($actor, 0, 120), $now]);
    $id = (int) $pdo->lastInsertId();
    if (function_exists('wsm_audit')) wsm_audit($pdo, $actor, 'Nowa kampania SMS', 'wsm_sms_campaigns #' . $id, 'Sieć');
    return [$id, 'Kampania zapisana. Wyślij najpierw próbkę na swój numer.'];
}

/**
 * LE TEXTE QUI PART pour un destinataire : langue, prénom, code, lien suivi,
 * et la ligne de désinscription — ajoutée ICI, jamais laissée à la rédaction.
 */
function wsm_sms_texte(PDO $pdo, array $camp, array $dest, string $linkCode = ''): string {
    $lang = wsm_mkt_lang((string) ($dest['lang'] ?? 'pl'));
    $t = $lang !== 'pl' && !empty($camp['tlumaczenia'][$lang]) ? (string) $camp['tlumaczenia'][$lang] : (string) $camp['tresc'];
    $imie = trim((string) (preg_split('/\s+/', trim((string) ($dest['name'] ?? ''))) ?: [''])[0]);
    $lien = preg_replace('#^https?://(www\.)?#', '', wsm_mkt_url('', $linkCode)) ?? '';
    $t = str_replace(['{imie}', '{kod}', '{link}'], [$imie, (string) ($camp['kod'] ?? ''), $lien], $t);
    $t = preg_replace('/ +([,.!?])/u', '$1', $t) ?? $t;          // « Cześć , » → « Cześć, »
    $stop = (string) ($dest['token'] ?? '') !== '' ? wsm_mkt_sms_stop_url((string) $dest['token']) : '';
    if ($stop !== '') {
        $t = rtrim($t) . "\n" . str_replace('{stop}', $stop, wsm_mkt_t($pdo, $lang, 'nl.sms_footer', 'Rezygnacja: {stop}'));
    }
    return !empty($camp['ascii']) ? wsm_sms_ascii($t) : $t;
}

/**
 * Ce que coûtera la campagne, AVANT l'envoi : destinataires par langue, SMS
 * par message, coût estimé, et un exemple exact de ce qui part.
 */
function wsm_sms_camp_preview(PDO $pdo, array $camp): array {
    $dest = wsm_mkt_audience_sms($pdo, (string) $camp['segment']);
    $code = (string) ($camp['link_code'] ?: 's' . (int) $camp['id']);
    $parts = 0; $langs = []; $max = 0;
    foreach ($dest as $d) {
        $p = wsm_sms_parts(wsm_sms_texte($pdo, $camp, $d, $code))['czesci'];
        $parts += $p; $max = max($max, $p);
        $langs[$d['lang']] = ($langs[$d['lang']] ?? 0) + 1;
    }
    $exemple = wsm_sms_texte($pdo, $camp, ['name' => 'Anna', 'lang' => 'pl', 'token' => 'abcd2345'], $code);
    return ['odbiorcow' => count($dest), 'czesci' => $parts, 'max_czesci' => $max, 'jezyki' => $langs,
            'koszt' => $parts * wsm_sms_cfg()['cena'], 'przyklad' => $exemple, 'licznik' => wsm_sms_parts($exemple)];
}

/** Un SMS de test, à un numéro, tout de suite (même la nuit : c'est le sien). */
function wsm_sms_camp_test(PDO $pdo, int $id, string $phone, string $actor): array {
    $c = wsm_sms_camp_get($pdo, $id);
    if (!$c) return [false, 'Nie znaleziono kampanii.'];
    $tel = wsm_mkt_phone($phone);
    if ($tel === '') return [false, 'Podaj poprawny numer komórkowy.'];
    $texte = wsm_sms_texte($pdo, $c, ['name' => 'Test', 'lang' => 'pl', 'token' => 'test0000'],
                           (string) ($c['link_code'] ?: 's' . $id));
    $pdo->prepare("INSERT INTO wsm_sms (campaign_id, phone, tresc, czesci, status, test, actor, created_at)
                   VALUES (?,?,?,?,'kolejka',1,?,?)")
        ->execute([$id, $tel, $texte, wsm_sms_parts($texte)['czesci'], mb_substr($actor, 0, 120), date('Y-m-d H:i:s')]);
    $rid = (int) $pdo->lastInsertId();
    $r = wsm_sms_envoyer_ligne($pdo, $rid);
    return [$r['ok'], $r['ok'] ? 'Próbka wysłana na ' . $tel . '. Sprawdź, jak wygląda na telefonie.' : 'Nie wysłano: ' . $r['error']];
}

/**
 * Envoie la campagne — c'est-à-dire : LA MET EN FILE. La pompe l'écoule aux
 * heures permises. Une ligne par numéro (clé unique) : rejouer ne renvoie rien.
 */
function wsm_sms_camp_send(PDO $pdo, int $id, string $actor): array {
    $c = wsm_sms_camp_get($pdo, $id);
    if (!$c) return [0, 'Nie znaleziono kampanii.'];
    if ((string) $c['status'] !== 'szkic') return [0, 'Ta kampania już wyszła.'];
    // PRISE de la campagne : un double clic ou deux onglets ne la mettent
    // pas en file deux fois, et le second n'écrase pas les chiffres du premier.
    $prise = $pdo->prepare("UPDATE wsm_sms_campaigns SET status = 'wysylanie' WHERE id = ? AND status = 'szkic'");
    $prise->execute([$id]);
    if ($prise->rowCount() !== 1) return [0, 'Ta kampania właśnie wychodzi.'];
    try {
        return wsm_sms_camp_enfiler($pdo, $c, $id, $actor);
    } catch (Throwable $e) {
        // Rien n'est perdu : les lignes déjà en file ont une clé unique, un
        // nouvel envoi mettra seulement les autres.
        $pdo->prepare("UPDATE wsm_sms_campaigns SET status = 'szkic' WHERE id = ? AND status = 'wysylanie'")->execute([$id]);
        return [0, 'Wysyłka przerwana: ' . $e->getMessage()];
    }
}

/** La mise en file elle-même, une fois la campagne prise. */
function wsm_sms_camp_enfiler(PDO $pdo, array $c, int $id, string $actor): array {
    [$code, $uwaga] = wsm_mkt_link($pdo, 's' . $id, 'SMS: ' . (string) $c['nazwa'], (string) $c['kod']);
    $pdo->prepare("UPDATE wsm_sms_campaigns SET link_code = ? WHERE id = ?")->execute([$code, $id]);
    $c['link_code'] = $code;
    $n = 0; $parts = 0;
    $ins = $pdo->prepare("INSERT INTO wsm_sms (campaign_id, phone, tresc, czesci, status, event_key, actor, created_at)
                          VALUES (?,?,?,?,'kolejka',?,?,?)");
    foreach (wsm_mkt_audience_sms($pdo, (string) $c['segment']) as $d) {
        $texte = wsm_sms_texte($pdo, $c, $d, $code);
        $p = wsm_sms_parts($texte)['czesci'];
        try {
            $ins->execute([$id, $d['phone'], $texte, $p, 'sms-' . $id . '-' . $d['phone'], mb_substr($actor, 0, 120), date('Y-m-d H:i:s')]);
            $n++; $parts += $p;
        } catch (Throwable $e) { /* déjà en file */ }
    }
    $pdo->prepare("UPDATE wsm_sms_campaigns SET status = 'wyslana', odbiorcow = ?, czesci = ?, sent_at = ? WHERE id = ?")
        ->execute([$n, $parts, date('Y-m-d H:i:s'), $id]);
    if (function_exists('wsm_audit')) wsm_audit($pdo, $actor, 'Wysyłka kampanii SMS', 'wsm_sms_campaigns #' . $id . ' — ' . $n, 'Sieć');
    $m = 'W kolejce: ' . $n . ' SMS-ów (' . $parts . ' części do zapłaty).';
    $m .= wsm_sms_godziny_ok() ? ' Wychodzą partiami.' : ' Teraz jest noc — wyjdą od 8:00.';
    if ($uwaga !== '') $m .= ' Uwaga: ' . $uwaga;
    return [$n, $m];
}

/** Envoie une ligne de la file. */
function wsm_sms_envoyer_ligne(PDO $pdo, int $rid): array {
    $st = $pdo->prepare("SELECT * FROM wsm_sms WHERE id = ?");
    $st->execute([$rid]);
    $r = $st->fetch();
    if (!$r || (string) $r['status'] !== 'kolejka') return ['ok' => false, 'error' => 'nie ma w kolejce'];
    // PRISE atomique : un SMS se paie, deux pompes simultanées ne doivent
    // jamais l'envoyer deux fois.
    $prise = $pdo->prepare("UPDATE wsm_sms SET status = 'wysylanie' WHERE id = ? AND status = 'kolejka'");
    $prise->execute([$rid]);
    if ($prise->rowCount() !== 1) return ['ok' => false, 'error' => 'nie ma w kolejce'];
    try { $res = wsm_sms_send_raw((string) $r['phone'], (string) $r['tresc'], !(int) $r['test'], 'wsm' . $rid); }
    catch (Throwable $e) { $res = ['ok' => false, 'sid' => '', 'error' => $e->getMessage(), 'panne' => true]; }
    // Une panne du compte ou du réseau n'est pas la faute de CE SMS : il
    // retourne en file (campagne) au lieu d'échouer pour toujours.
    $statut = $res['ok'] ? 'wyslany' : (!empty($res['panne']) && !(int) $r['test'] ? 'kolejka' : 'blad');
    $pdo->prepare("UPDATE wsm_sms SET status = ?, sms_id = ?, error = ?, sent_at = ?, raport = ? WHERE id = ?")
        ->execute([$statut, mb_substr($res['sid'], 0, 40), mb_substr($res['error'], 0, 250),
                   $res['ok'] ? date('Y-m-d H:i:s') : null, $res['ok'] && $res['sid'] !== '' ? 'QUEUE' : '', $rid]);
    return $res;
}

/**
 * ÉCOULE la file des SMS : canal ouvert, heures permises, pas plus vieux que
 * trois jours, jamais à quelqu'un qui s'est désinscrit entre-temps.
 * @return array{wyslane:int, uwaga:string}
 */
function wsm_sms_pump(PDO $pdo, int $max = 20, float $budget = 8.0): array {
    if (!wsm_sms_enabled()) return ['wyslane' => 0, 'uwaga' => ''];
    try {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM wsm_sms WHERE status = 'kolejka' AND test = 0")->fetchColumn();
    } catch (Throwable $e) { return ['wyslane' => 0, 'uwaga' => '']; }
    if ($n === 0) return ['wyslane' => 0, 'uwaga' => ''];
    if (!wsm_sms_godziny_ok()) return ['wyslane' => 0, 'uwaga' => 'SMS-y czekają do 8:00 (' . $n . ' w kolejce)'];
    $debut = microtime(true); $ok = 0; $suite = 0;
    $st = $pdo->prepare("SELECT id, phone FROM wsm_sms WHERE status = 'kolejka' AND test = 0 AND created_at >= ?
                          ORDER BY id LIMIT " . max(1, min(200, $max)));
    $st->execute([date('Y-m-d H:i:s', time() - WSM_SMS_AGE_J * 86400)]);
    $actif = $pdo->prepare("SELECT 1 FROM wsm_subscribers WHERE phone = ? AND sms_status = 'aktywny'");
    foreach ($st->fetchAll() as $r) {
        if (microtime(true) - $debut > $budget) break;
        $actif->execute([(string) $r['phone']]);
        if (!$actif->fetchColumn()) {
            $pdo->prepare("UPDATE wsm_sms SET status = 'pominiety', error = 'wypisany przed wysyłką' WHERE id = ?")->execute([(int) $r['id']]);
            continue;
        }
        $res = wsm_sms_envoyer_ligne($pdo, (int) $r['id']);
        if ($res['ok']) { $ok++; $suite = 0; }
        elseif (!empty($res['panne'])) return ['wyslane' => $ok, 'uwaga' => 'SMSAPI nie przyjmuje (SMS-y czekają w kolejce): ' . $res['error']];
        elseif (++$suite >= 3) return ['wyslane' => $ok, 'uwaga' => 'SMSAPI nie przyjmuje: ' . $res['error']];
    }
    return ['wyslane' => $ok, 'uwaga' => ''];
}

/**
 * L'accusé de l'opérateur (rappel SMSAPI) : MsgId, status, status_name,
 * donedate — plusieurs d'un coup, séparés par des virgules. Un statut
 * définitif n'est jamais remplacé par un statut d'attente arrivé en retard.
 * @return int lignes mises à jour ; -1 si la clé est fausse
 */
function wsm_sms_raport(PDO $pdo, array $src, string $k): int {
    if (!hash_equals(wsm_sms_raport_key(), $k)) return -1;
    $liste = fn(string $c): array => array_map('trim', explode(',', (string) ($src[$c] ?? '')));
    $ids = $liste('MsgId'); $codes = $liste('status'); $noms = $liste('status_name'); $dates = $liste('donedate');
    $n = 0;
    foreach ($ids as $i => $id) {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? '';
        if ($id === '') continue;
        $nom = strtoupper(preg_replace('/[^A-Za-z_]/', '', (string) ($noms[$i] ?? '')) ?? '');
        if ($nom === '') $nom = WSM_SMS_STATUTS[(int) ($codes[$i] ?? 0)] ?? '';
        if ($nom === '') continue;
        $ts = (int) ($dates[$i] ?? 0);
        if ($ts <= 1000000000 || $ts > time() + 3600) $ts = time();
        $st = $pdo->prepare("SELECT id, raport, phone FROM wsm_sms WHERE sms_id = ? LIMIT 1");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) continue;
        if (in_array((string) $r['raport'], WSM_SMS_FINAUX, true) && !in_array($nom, WSM_SMS_FINAUX, true)) continue;
        $pdo->prepare("UPDATE wsm_sms SET raport = ?, raport_at = ? WHERE id = ?")
            ->execute([mb_substr($nom, 0, 20), date('Y-m-d H:i:s', $ts), (int) $r['id']]);
        // « STOP » : la personne a refusé chez l'opérateur — on respecte.
        if ($nom === 'STOP') wsm_mkt_unsub_sms($pdo, (string) $r['phone'], 'operator');
        $n++;
    }
    return $n;
}

/** Les campagnes, avec ce qu'elles ont fait. */
function wsm_sms_camp_list(PDO $pdo): array {
    try { $rows = $pdo->query("SELECT * FROM wsm_sms_campaigns ORDER BY id DESC")->fetchAll(); }
    catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $c) {
        $s = ['kolejka' => 0, 'wyslany' => 0, 'blad' => 0, 'pominiety' => 0, 'dostarczone' => 0, 'niedostarczone' => 0];
        $st = $pdo->prepare("SELECT status, raport, COUNT(*) AS n FROM wsm_sms WHERE campaign_id = ? AND test = 0 GROUP BY status, raport");
        $st->execute([(int) $c['id']]);
        foreach ($st->fetchAll() as $r) {
            $s[(string) $r['status']] = ($s[(string) $r['status']] ?? 0) + (int) $r['n'];
            if ((string) $r['raport'] === 'DELIVERED') $s['dostarczone'] += (int) $r['n'];
            elseif (in_array((string) $r['raport'], WSM_SMS_FINAUX, true)) $s['niedostarczone'] += (int) $r['n'];
        }
        // Le coût, c'est ce qui est PARTI (pas les ignorés, les échecs, la file).
        $q = $pdo->prepare("SELECT COALESCE(SUM(czesci), 0) FROM wsm_sms WHERE campaign_id = ? AND test = 0 AND status = 'wyslany'");
        $q->execute([(int) $c['id']]);
        $out[] = $c + ['stat' => $s, 'koszt' => (int) $q->fetchColumn() * wsm_sms_cfg()['cena'],
                       'link' => wsm_mkt_link_stats($pdo, (string) $c['link_code']),
                       'wypisy' => wsm_mkt_wypisy($pdo, 'sms:' . (int) $c['id'])];
    }
    return $out;
}
