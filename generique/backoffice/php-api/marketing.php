<?php
// ============================================================================
//  marketing.php — le socle de la newsletter et des SMS : QUI a dit oui, à
//  QUOI, QUAND, et comment on le laisse partir.
//
//  Ce que le CRM d'Atelier By fait déjà (newsletter en blocs, SMS par SMSAPI)
//  arrive dans la boutique, avec ce qu'une boutique doit en plus : des
//  inscrits qui ne sont pas encore clients, et la preuve de leur accord.
//
//  CINQ RÈGLES :
//
//   1. LE CONSENTEMENT EST UNE PREUVE. On garde la date, l'adresse IP et le
//      TEXTE EXACT de la case cochée. « Il avait dit oui » ne vaut rien devant
//      l'UODO ; « il a coché ceci, tel jour, depuis cette adresse », si.
//   2. DOUBLE OPT-IN POUR L'E-MAIL. Une adresse tapée sur le site reçoit un
//      lien de confirmation ; tant qu'il n'est pas cliqué, rien ne part. Sans
//      ça, n'importe qui inscrit n'importe qui — et les plaintes pour
//      indésirables tombent sur le domaine, donc sur les confirmations de
//      commande.
//   3. LES SMS NE PARTENT QU'AVEC UNE CASE SMS. Acheter ne vaut pas accord
//      pour un SMS (Prawo komunikacji elektronicznej, art. 398) : il faut
//      la case, cochée exprès, avec le numéro.
//   4. UN REFUS NE S'EFFACE JAMAIS. Désinscrit un jour, désinscrit pour
//      toutes les campagnes suivantes, y compris celles des acheteurs
//      (wsm_clients.no_mailing). Se réinscrire passe par une nouvelle
//      confirmation.
//   5. RIEN NE PART D'UN COUP. Tout entre en file ; la pompe (wsm_mkt_pump,
//      appelée par la tâche planifiée kolejka.php et par la console) écoule
//      par petites quantités, et jamais un message vieux de plus d'une
//      semaine : une offre périmée qui arrive est pire qu'aucune.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/shop.php';
require_once __DIR__ . '/campaign.php';

/** Les publics. Chacun se calcule ; aucun ne se saisit à la main. */
const WSM_MKT_SEGMENTS = [
    'wszyscy'      => 'Wszyscy ze zgodą',
    'subskrybenci' => 'Subskrybenci zapisani na stronie',
    'klienci'      => 'Wszyscy, którzy kupili',
    'stali'        => 'Stali klienci (3+ zamówienia)',
    'spiacy'       => 'Śpiący (bez zakupu od 120 dni)',
    'firmy'        => 'Konta firmowe',
    'nowi'         => 'Nowi (pierwszy zakup w ostatnich 60 dniach)',
];

/** Les langues dans lesquelles une campagne peut partir. */
const WSM_MKT_LANGS = ['pl', 'uk', 'en'];

/** Les clés de file que la pompe a le droit d'écouler (les autres restent à la main). */
const WSM_MKT_POMPE_CLES = ['nl-', 'nlpotw-', 'camp-', 'relance-'];

/** Âge maximal d'un message en file pour partir tout seul. */
const WSM_MKT_POMPE_AGE_J = 7;

/** Un lien de confirmation vaut sept jours. */
const WSM_MKT_POTW_J = 7;

/** Pas plus d'un e-mail de confirmation toutes les dix minutes pour une adresse. */
const WSM_MKT_POTW_PAUSE = 600;

function wsm_mkt_ensure(PDO $pdo): void {
    // Les tables viennent du schéma (wsm_ensure_nouvelles_tables) ; le refus
    // des acheteurs vit sur wsm_clients (campaign.php).
    wsm_camp_ensure($pdo);
}

/**
 * La clé qui signe les liens de désinscription. Dérivée du jeton
 * d'administration, comme celle du formulaire de contact — jamais une
 * constante écrite dans ce dépôt public.
 */
function wsm_mkt_secret(): string {
    $cfg = wsm_config();
    $base = (string) ($cfg['admin_token'] ?? '');
    if ($base === '' || $base === 'xxxx') $base = (string) ($cfg['sqlite_path'] ?? __DIR__) . php_uname('n');
    return hash('sha256', 'wsm-marketing|' . $base);
}

function wsm_mkt_email_norm(string $e): string {
    $e = strtolower(trim($e));
    // 200 : la largeur de wsm_subscribers.email. MySQL strict refuserait plus
    // long par une erreur 500 ; on refuse ici, poliment.
    return strlen($e) <= 200 && filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}

function wsm_mkt_lang(string $l): string {
    $l = strtolower(trim($l));
    return in_array($l, WSM_MKT_LANGS, true) ? $l : 'pl';
}

/**
 * Le numéro en E.164. « 600 100 200 » et « +48 600 100 200 » sont la même
 * personne ; un numéro qu'on ne sait pas lire rend '' — on n'invente pas un
 * destinataire.
 */
function wsm_mkt_phone(string $raw): string {
    $t = preg_replace('/[^\d+]/', '', trim($raw)) ?? '';
    if ($t === '') return '';
    if (str_starts_with($t, '00')) $t = '+' . substr($t, 2);
    if (!str_starts_with($t, '+')) {
        $t = ltrim($t, '0');
        if (strlen($t) === 9) $t = '+48' . $t;          // numéro polonais sans indicatif
        elseif (str_starts_with($t, '48') && strlen($t) === 11) $t = '+' . $t;
        else return '';
    }
    if (!preg_match('/^\+[1-9]\d{7,14}$/', $t)) return '';
    // Pologne : seuls les mobiles reçoivent un SMS (un fixe le facture et ne
    // le remet jamais).
    if (str_starts_with($t, '+48') && !preg_match('/^\+48[4-8]\d{8}$/', $t)) return '';
    return $t;
}

/** Les textes de la boutique dans une langue (repli : polonais). */
function wsm_mkt_textes(PDO $pdo, string $lang): array {
    static $cache = [];
    $lang = wsm_mkt_lang($lang);
    if (!isset($cache[$lang])) {
        try { $cache[$lang] = wsm_shop_strings($pdo, $lang); } catch (Throwable $e) { $cache[$lang] = []; }
    }
    return $cache[$lang];
}

function wsm_mkt_t(PDO $pdo, string $lang, string $cle, string $defaut = ''): string {
    $v = (string) (wsm_mkt_textes($pdo, $lang)[$cle] ?? '');
    if ($v === '' && $lang !== 'pl') $v = (string) (wsm_mkt_textes($pdo, 'pl')[$cle] ?? '');
    return $v !== '' ? $v : $defaut;
}

function wsm_mkt_event(PDO $pdo, string $typ, string $ref, string $kto): void {
    try {
        $pdo->prepare("INSERT INTO wsm_mkt_events (typ, ref, kto, created_at) VALUES (?,?,?,?)")
            ->execute([mb_substr($typ, 0, 20), mb_substr($ref, 0, 40), mb_substr($kto, 0, 200), date('Y-m-d H:i:s')]);
    } catch (Throwable $e) { /* une statistique ne bloque jamais un geste */ }
}

function wsm_mkt_sub_by_email(PDO $pdo, string $email): ?array {
    $st = $pdo->prepare("SELECT * FROM wsm_subscribers WHERE email = ?");
    $st->execute([strtolower(trim($email))]);
    return $st->fetch() ?: null;
}

// ---------------------------------------------------------------------------
//  Les liens signés
// ---------------------------------------------------------------------------

function wsm_mkt_token_email(string $email): string {
    return substr(hash_hmac('sha256', 'stop|' . strtolower(trim($email)), wsm_mkt_secret()), 0, 32);
}

function wsm_mkt_token_email_ok(string $email, string $token): bool {
    $email = strtolower(trim($email));
    if ($email === '' || $token === '') return false;
    // Les liens des anciennes campagnes texte (campaign.php) restent valables.
    return hash_equals(wsm_mkt_token_email($email), $token) || wsm_camp_stop_ok($email, $token);
}

/** La page de désinscription (lien visible dans le message). */
function wsm_mkt_unsub_page(string $email, string $ref = ''): string {
    return wsm_shop_base_url() . '/newsletter/wypisz?e=' . rawurlencode(strtolower(trim($email)))
         . '&t=' . wsm_mkt_token_email($email) . ($ref !== '' ? '&r=' . rawurlencode($ref) : '');
}

/** L'adresse « un clic » de l'en-tête List-Unsubscribe : l'API, qui accepte le POST sans formulaire. */
function wsm_mkt_unsub_oneclick(string $email, string $ref = ''): string {
    return wsm_api_base_url() . '/shop/newsletter/wypisz?e=' . rawurlencode(strtolower(trim($email)))
         . '&t=' . wsm_mkt_token_email($email) . ($ref !== '' ? '&r=' . rawurlencode($ref) : '');
}

/** Le lien court de désinscription SMS : chaque caractère d'un SMS se paie. */
function wsm_mkt_sms_stop_url(string $smsToken): string {
    $u = wsm_shop_base_url() . '/s/' . $smsToken;
    return preg_replace('#^https?://(www\.)?#', '', $u) ?? $u;
}

// ---------------------------------------------------------------------------
//  L'inscription
// ---------------------------------------------------------------------------

/**
 * Inscrit une personne — à la newsletter, aux SMS, ou aux deux.
 *
 * @param array $in email, phone, name, lang, source, newsletter (bool), sms (bool),
 *                  ip, tekst_email, tekst_sms (le texte exact des cases)
 * @return array{ok:bool, blad:string, potwierdzenie:bool, sms:bool, sms_potwierdzenie:bool}
 *         sms = consentement SMS actif ; sms_potwierdzenie = SMS de confirmation parti
 */
function wsm_mkt_subscribe(PDO $pdo, array $in): array {
    $out = ['ok' => false, 'blad' => '', 'potwierdzenie' => false, 'sms' => false, 'sms_potwierdzenie' => false];
    $email = wsm_mkt_email_norm((string) ($in['email'] ?? ''));
    if ($email === '') { $out['blad'] = 'email'; return $out; }
    $chceNl  = !empty($in['newsletter']);
    $chceSms = !empty($in['sms']);
    if (!$chceNl && !$chceSms) { $out['blad'] = 'zgoda'; return $out; }
    $tel = wsm_mkt_phone((string) ($in['phone'] ?? ''));
    if ($chceSms && $tel === '') { $out['blad'] = 'telefon'; return $out; }

    $now  = date('Y-m-d H:i:s');
    $lang = wsm_mkt_lang((string) ($in['lang'] ?? 'pl'));
    $src  = mb_substr((string) ($in['source'] ?? 'strona'), 0, 20);
    $ip   = mb_substr((string) ($in['ip'] ?? ''), 0, 45);
    $cur  = wsm_mkt_sub_by_email($pdo, $email);
    if (!$cur) {
        $pdo->prepare("INSERT INTO wsm_subscribers (email, lang, source, ip, created_at) VALUES (?,?,?,?,?)")
            ->execute([$email, $lang, $src, $ip, $now]);
        $cur = wsm_mkt_sub_by_email($pdo, $email);
    }
    $id = (int) $cur['id'];
    $set = ['lang' => $lang, 'updated_at' => $now];
    $nom = trim((string) ($in['name'] ?? ''));
    if ($nom !== '' && trim((string) $cur['name']) === '') $set['name'] = mb_substr($nom, 0, 120);
    // Le numéro ne change QU'AVEC la case SMS : sinon n'importe qui, en tapant
    // l'adresse d'un autre, déplacerait son consentement SMS vers un numéro.
    if ($chceSms) $set['phone'] = $tel;

    // --- E-mail : double opt-in ---------------------------------------------
    $envoyer = false;
    if ($chceNl && (string) $cur['email_status'] !== 'aktywny') {
        $frais = (string) $cur['email_status'] === 'oczekuje' && (string) $cur['token'] !== ''
              && strtotime((string) $cur['token_at']) > time() - WSM_MKT_POTW_PAUSE;
        $set += ['email_status' => 'oczekuje', 'email_zgoda_at' => $now, 'ip' => $ip,
                 'email_zgoda_tekst' => mb_substr((string) ($in['tekst_email'] ?? ''), 0, 500)];
        if (!$frais) {
            $set['token'] = bin2hex(random_bytes(16));
            $set['token_at'] = $now;
            $envoyer = true;
        }
    }
    // --- SMS ------------------------------------------------------------------
    //  À la caisse, le numéro est celui de la livraison : la case suffit. Au
    //  pied de page, n'importe qui peut taper n'importe quel numéro : le
    //  consentement attend que le TÉLÉPHONE ouvre le lien reçu par SMS.
    $smsPotw = false;
    if ($chceSms) {
        $deja = (string) $cur['sms_status'] === 'aktywny' && (string) $cur['phone'] === $tel;
        $direct = $src === 'kasa' || $deja;
        $set += ['sms_status' => $direct ? 'aktywny' : 'oczekuje', 'sms_zgoda_at' => $now, 'sms_wypis_at' => null,
                 'sms_zgoda_tekst' => mb_substr((string) ($in['tekst_sms'] ?? ''), 0, 500)];
        if ((string) $cur['sms_token'] === '') $set['sms_token'] = wsm_mkt_sms_token($pdo);
        $out['sms'] = $direct;
        $smsPotw = !$direct;
    }
    $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
    $pdo->prepare("UPDATE wsm_subscribers SET $cols WHERE id = ?")->execute([...array_values($set), $id]);
    wsm_mkt_event($pdo, 'zapis', $src, $email);

    if ($envoyer) $out['potwierdzenie'] = wsm_mkt_send_confirmation($pdo, $email, $lang, (string) $set['token']);
    if ($smsPotw) $out['sms_potwierdzenie'] = wsm_mkt_send_sms_confirmation($pdo, $tel,
        (string) ($set['sms_token'] ?? $cur['sms_token']), $lang);
    $out['ok'] = true;
    return $out;
}

/**
 * Le SMS « confirmez » du pied de page : un lien court, ouvert sur le
 * téléphone, prouve que le numéro est bien à la personne. Un seul par numéro
 * et par jour (un SMS se paie, et personne ne doit pouvoir en faire pleuvoir
 * sur un tiers). Il part tout de suite : la personne l'attend.
 */
function wsm_mkt_send_sms_confirmation(PDO $pdo, string $tel, string $smsToken, string $lang): bool {
    $f = __DIR__ . '/smsapi.php';
    if (!is_file($f)) return false;
    require_once $f;
    if (!wsm_sms_enabled() || $smsToken === '' || !wsm_mkt_hote_ok()) return false;
    $q = $pdo->prepare("SELECT COUNT(*) FROM wsm_sms WHERE phone = ? AND event_key LIKE 'smspotw-%' AND created_at >= ?");
    $q->execute([$tel, date('Y-m-d H:i:s', time() - 86400)]);
    if ((int) $q->fetchColumn() > 0) return false;
    $texte = wsm_sms_ascii(str_replace(['{sklep}', '{link}'], [wsm_brand_name(), wsm_mkt_sms_stop_url($smsToken)],
        wsm_mkt_t($pdo, $lang, 'nl.sms_confirm_sms', '{sklep}: potwierdz zgode na SMS-y z ofertami: {link}')));
    // test = 1 : un message de service, hors campagnes et hors pompe (la pompe
    // l'écarterait : le numéro n'a pas encore de consentement actif).
    $pdo->prepare("INSERT INTO wsm_sms (campaign_id, phone, tresc, czesci, status, test, actor, event_key, created_at)
                   VALUES (NULL,?,?,?,'kolejka',1,'zapis',?,?)")
        ->execute([$tel, $texte, wsm_sms_parts($texte)['czesci'], 'smspotw-' . bin2hex(random_bytes(8)), date('Y-m-d H:i:s')]);
    return wsm_sms_envoyer_ligne($pdo, (int) $pdo->lastInsertId())['ok'];
}

/**
 * Le lien du SMS de confirmation a été ouvert, et le bouton pressé.
 * @return bool le numéro a maintenant un consentement actif
 */
function wsm_mkt_sms_confirm(PDO $pdo, string $smsToken): bool {
    $st = $pdo->prepare("SELECT * FROM wsm_subscribers WHERE sms_token = ? AND sms_token <> ''");
    $st->execute([$smsToken]);
    $r = $st->fetch();
    if (!$r || (string) $r['sms_status'] !== 'oczekuje' || (string) $r['phone'] === '') return false;
    if (strtotime((string) $r['sms_zgoda_at']) < time() - WSM_MKT_POTW_J * 86400) return false;
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE wsm_subscribers SET sms_status = 'aktywny', sms_zgoda_at = ?, updated_at = ? WHERE id = ?")
        ->execute([$now, $now, (int) $r['id']]);
    wsm_mkt_event($pdo, 'potwierdzenie_sms', (string) $r['source'], (string) $r['phone']);
    return true;
}

/**
 * L'adresse du lien envoyé est-elle sûre ? Sans « Publiczny adres sklepu », les
 * liens prennent l'hôte de la requête ; un en-tête Host forgé ferait alors
 * partir de NOTRE domaine un message pointant ailleurs. On n'accepte que
 * l'adresse configurée, l'adresse IP du serveur ou la machine locale.
 */
function wsm_mkt_hote_ok(): bool {
    if ((string) (wsm_config()['shop_url'] ?? '') !== '') return true;
    $hote = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    $hote = trim($hote, '[]');
    return $hote !== '' && in_array($hote, array_filter(['localhost', '127.0.0.1', '::1',
        strtolower((string) ($_SERVER['SERVER_ADDR'] ?? ''))]), true);
}

/** « Publiczny adres sklepu » est-il réglé ? Sans lui, pas de campagne : ses liens et ses accusés en dépendent. */
function wsm_mkt_adres_ok(): bool {
    return (string) (wsm_config()['shop_url'] ?? '') !== '';
}

/** Envoyer une campagne, supprimer une fiche, exporter le registre : l'administration seulement. */
function wsm_mkt_decide(?array $me): bool {
    return function_exists('wsm_role_de') && in_array(wsm_role_de($me), [WSM_ROLE_SUPERADMIN, WSM_ROLE_ADMIN], true);
}

/** Un jeton court et unique pour le lien SMS (8 caractères sans ambiguïté). */
function wsm_mkt_sms_token(PDO $pdo): string {
    $alpha = 'abcdefghjkmnpqrstuvwxyz23456789';
    for ($i = 0; $i < 20; $i++) {
        $t = '';
        for ($j = 0; $j < 8; $j++) $t .= $alpha[random_int(0, strlen($alpha) - 1)];
        $st = $pdo->prepare("SELECT 1 FROM wsm_subscribers WHERE sms_token = ?");
        $st->execute([$t]);
        if (!$st->fetchColumn()) return $t;
    }
    return bin2hex(random_bytes(6));
}

/** L'e-mail « confirmez votre inscription » — en file, et tenté tout de suite. */
function wsm_mkt_send_confirmation(PDO $pdo, string $email, string $lang, string $token): bool {
    if (!wsm_mkt_hote_ok()) return false;
    $lien = wsm_shop_base_url() . '/newsletter/potwierdz?t=' . $token;
    $sujet = wsm_mkt_t($pdo, $lang, 'nl.confirm_subject', 'Potwierdź zapis do newslettera');
    $corps = str_replace(['{link}', '{sklep}'], [$lien, wsm_brand_name()],
        wsm_mkt_t($pdo, $lang, 'nl.confirm_body',
            "Dziękujemy za zapis do newslettera {sklep}.\n\nPotwierdź adres, klikając w link:\n{link}\n\n"
          . "Jeśli to nie Ty, zignoruj tę wiadomość — bez potwierdzenia nic nie wyślemy."));
    $id = wsm_mail_queue($pdo, [
        'email' => $email, 'direction' => 'wyjscie',
        'subject' => $sujet . ' — ' . wsm_brand_name(), 'body' => $corps,
        'template_code' => 'newsletter-potwierdzenie',
        'event_key' => 'nlpotw-' . substr(hash('sha256', $email . '|' . $token), 0, 40),
        'actor' => 'newsletter',
    ]);
    if ($id > 0 && wsm_mail_enabled()) {
        try { wsm_mail_send($pdo, $id); } catch (Throwable $e) { /* reste en file : la pompe la reprendra */ }
    }
    return $id > 0;
}

/**
 * Le lien de confirmation a été cliqué.
 * @return array{0:bool, 1:string} [confirmé, adresse]
 */
function wsm_mkt_confirm(PDO $pdo, string $token): array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return [false, ''];
    $st = $pdo->prepare("SELECT * FROM wsm_subscribers WHERE token = ?");
    $st->execute([$token]);
    $r = $st->fetch();
    if (!$r || (string) $r['email_status'] !== 'oczekuje') return [false, ''];
    if (strtotime((string) $r['token_at']) < time() - WSM_MKT_POTW_J * 86400) return [false, ''];
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE wsm_subscribers SET email_status = 'aktywny', email_potw_at = ?, email_wypis_at = NULL,
                          token = '', updated_at = ? WHERE id = ?")->execute([$now, $now, (int) $r['id']]);
    // S'il avait dit non comme acheteur, son nouveau « oui » confirmé l'emporte.
    try { $pdo->prepare("UPDATE wsm_clients SET no_mailing = 0 WHERE LOWER(email) = ?")->execute([(string) $r['email']]); }
    catch (Throwable $e) { /* colonne absente : rien à lever */ }
    wsm_mkt_event($pdo, 'potwierdzenie', (string) $r['source'], (string) $r['email']);
    return [true, (string) $r['email']];
}

// ---------------------------------------------------------------------------
//  Le départ
// ---------------------------------------------------------------------------

/** Ne plus écrire à cette adresse (newsletter ET campagnes des acheteurs). */
function wsm_mkt_unsub_email(PDO $pdo, string $email, string $ref = ''): bool {
    $email = wsm_mkt_email_norm($email);
    if ($email === '') return false;
    $now = date('Y-m-d H:i:s');
    $cur = wsm_mkt_sub_by_email($pdo, $email);
    if ($cur) {
        if ((string) $cur['email_status'] !== 'wypisany') {
            $pdo->prepare("UPDATE wsm_subscribers SET email_status = 'wypisany', email_wypis_at = ?, token = '', updated_at = ?
                            WHERE id = ?")->execute([$now, $now, (int) $cur['id']]);
            wsm_mkt_event($pdo, 'wypis_email', $ref, $email);
        }
    } else {
        $pdo->prepare("INSERT INTO wsm_subscribers (email, email_status, email_wypis_at, source, created_at)
                       VALUES (?, 'wypisany', ?, 'wypis', ?)")->execute([$email, $now, $now]);
        wsm_mkt_event($pdo, 'wypis_email', $ref, $email);
    }
    wsm_camp_stop($pdo, $email, 'klient');
    return true;
}

/** Ne plus envoyer de SMS à ce jeton (lien court) ou à ce numéro. */
function wsm_mkt_unsub_sms(PDO $pdo, string $tokenOuNumero, string $ref = ''): bool {
    $tel = wsm_mkt_phone($tokenOuNumero);
    if ($tel === '') {
        // Un jeton : on retrouve SON numéro, puis toutes les fiches qui le
        // portent — le public dédoublonne par numéro, une seule fiche oubliée
        // suffirait à faire repartir le SMS.
        $q = $pdo->prepare("SELECT phone FROM wsm_subscribers WHERE sms_token = ? AND sms_token <> ''");
        $q->execute([$tokenOuNumero]);
        $tel = (string) $q->fetchColumn();
        if ($tel === '') return false;
    }
    $st = $pdo->prepare("SELECT * FROM wsm_subscribers WHERE phone = ?");
    $st->execute([$tel]);
    $lignes = $st->fetchAll();
    if (!$lignes) return false;
    $now = date('Y-m-d H:i:s');
    // Le lien court ne dit pas de quelle campagne il vient (chaque caractère
    // d'un SMS se paie) : on l'attribue au dernier SMS reçu par ce numéro.
    if ($ref === '') {
        try {
            $q = $pdo->prepare("SELECT campaign_id FROM wsm_sms WHERE phone = ? AND test = 0 AND campaign_id IS NOT NULL
                                 ORDER BY id DESC LIMIT 1");
            $q->execute([(string) $lignes[0]['phone']]);
            $cid = (int) $q->fetchColumn();
            if ($cid > 0) $ref = 'sms:' . $cid;
        } catch (Throwable $e) { /* pas de SMS : désinscription sans campagne */ }
    }
    foreach ($lignes as $r) {
        if ((string) $r['sms_status'] === 'wypisany') continue;
        $pdo->prepare("UPDATE wsm_subscribers SET sms_status = 'wypisany', sms_wypis_at = ?, updated_at = ? WHERE id = ?")
            ->execute([$now, $now, (int) $r['id']]);
        wsm_mkt_event($pdo, 'wypis_sms', $ref, (string) $r['phone']);
    }
    return true;
}

// ---------------------------------------------------------------------------
//  Les publics
// ---------------------------------------------------------------------------

/** Les adresses qui ont dit non, toutes origines confondues. */
function wsm_mkt_refus(PDO $pdo): array {
    $out = wsm_camp_refus($pdo);
    try {
        foreach ($pdo->query("SELECT email FROM wsm_subscribers WHERE email_status = 'wypisany'")->fetchAll() as $r) {
            $out[strtolower((string) $r['email'])] = true;
        }
    } catch (Throwable $e) { /* table absente */ }
    return $out;
}

/** La langue de chaque acheteur : celle de sa dernière commande. */
function wsm_mkt_langs_acheteurs(PDO $pdo): array {
    $out = [];
    try {
        foreach ($pdo->query("SELECT LOWER(email) AS e, lang FROM wsm_orders ORDER BY id ASC")->fetchAll() as $r) {
            $out[(string) $r['e']] = wsm_mkt_lang((string) $r['lang']);
        }
    } catch (Throwable $e) { /* pas de commandes */ }
    return $out;
}

/**
 * Les acheteurs sans case cochée reçoivent-ils la newsletter ? NON par défaut
 * (config « marketing.klienci_bez_zgody », voir config.php : PKE art. 398).
 */
function wsm_mkt_klienci_bez_zgody(): bool {
    return !empty(wsm_config()['marketing']['klienci_bez_zgody']);
}

/**
 * Qui reçoit la newsletter d'un public. Acheteurs (relation commerciale,
 * campaign.php) et inscrits CONFIRMÉS ; jamais un refus.
 *
 * @return list<array{email:string, name:string, lang:string, kto:string}>
 */
function wsm_mkt_audience_email(PDO $pdo, string $segment): array {
    if (!isset(WSM_MKT_SEGMENTS[$segment])) return [];
    $refus = wsm_mkt_refus($pdo);
    $langs = wsm_mkt_langs_acheteurs($pdo);
    // Interrupteur fermé (par défaut) : un acheteur ne reçoit la newsletter que
    // s'il a AUSSI coché la case et confirmé — les segments d'acheteurs
    // (fidèles, endormis, firmes…) deviennent des filtres sur les inscrits.
    $sansZgody = wsm_mkt_klienci_bez_zgody();
    $zgody = [];
    if (!$sansZgody) {
        foreach ($pdo->query("SELECT email FROM wsm_subscribers WHERE email_status = 'aktywny'")->fetchAll(PDO::FETCH_COLUMN) as $e)
            $zgody[strtolower((string) $e)] = true;
    }
    $out = [];
    if ($segment !== 'subskrybenci') {
        foreach (wsm_camp_audience($pdo, $segment === 'wszyscy' ? 'klienci' : $segment) as $d) {
            $e = strtolower($d['email']);
            if (isset($refus[$e])) continue;
            if (!$sansZgody && !isset($zgody[$e])) continue;
            $out[$e] = ['email' => $e, 'name' => (string) $d['nom'], 'lang' => $langs[$e] ?? 'pl', 'kto' => 'klient'];
        }
    }
    if ($segment === 'wszyscy' || $segment === 'subskrybenci') {
        foreach ($pdo->query("SELECT email, name, lang FROM wsm_subscribers WHERE email_status = 'aktywny'")->fetchAll() as $r) {
            $e = strtolower((string) $r['email']);
            if ($e === '' || isset($refus[$e])) continue;
            // Un inscrit qui est aussi client garde sa langue choisie à l'inscription.
            $out[$e] = ['email' => $e, 'name' => (string) ($r['name'] ?: ($out[$e]['name'] ?? '')),
                        'lang' => wsm_mkt_lang((string) $r['lang']), 'kto' => isset($out[$e]) ? 'klient' : 'subskrybent'];
        }
    }
    return array_values($out);
}

/**
 * Qui reçoit les SMS d'un public : SEULEMENT ceux qui ont coché la case SMS
 * (règle 3), un numéro par personne.
 *
 * @return list<array{phone:string, email:string, name:string, lang:string, token:string}>
 */
function wsm_mkt_audience_sms(PDO $pdo, string $segment): array {
    if (!isset(WSM_MKT_SEGMENTS[$segment])) return [];
    $garde = null;
    if (!in_array($segment, ['wszyscy', 'subskrybenci'], true)) {
        $garde = [];
        foreach (wsm_camp_audience($pdo, $segment) as $d) $garde[strtolower($d['email'])] = true;
    }
    $out = [];
    $sql = "SELECT email, phone, name, lang, sms_token, source FROM wsm_subscribers
             WHERE sms_status = 'aktywny' AND phone <> '' ORDER BY id";
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        $tel = wsm_mkt_phone((string) $r['phone']);
        if ($tel === '' || isset($out[$tel])) continue;
        $e = strtolower((string) $r['email']);
        if ($garde !== null && !isset($garde[$e])) continue;
        if ($segment === 'subskrybenci' && (string) $r['source'] === 'kasa') continue;
        $out[$tel] = ['phone' => $tel, 'email' => $e, 'name' => (string) $r['name'],
                      'lang' => wsm_mkt_lang((string) $r['lang']), 'token' => (string) $r['sms_token']];
    }
    return array_values($out);
}

/** Les chiffres du tableau de bord marketing. */
function wsm_mkt_kpis(PDO $pdo): array {
    $n = function (string $sql) use ($pdo): int {
        try { return (int) $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
    };
    return [
        'email_aktywni'   => $n("SELECT COUNT(*) FROM wsm_subscribers WHERE email_status = 'aktywny'"),
        'email_oczekuja'  => $n("SELECT COUNT(*) FROM wsm_subscribers WHERE email_status = 'oczekuje'"),
        'email_wypisani'  => $n("SELECT COUNT(*) FROM wsm_subscribers WHERE email_status = 'wypisany'"),
        'sms_aktywni'     => $n("SELECT COUNT(*) FROM wsm_subscribers WHERE sms_status = 'aktywny' AND phone <> ''"),
        'sms_wypisani'    => $n("SELECT COUNT(*) FROM wsm_subscribers WHERE sms_status = 'wypisany'"),
        'kolejka_mail'    => $n("SELECT COUNT(*) FROM wsm_messages WHERE status = 'kolejka' AND direction = 'wyjscie'
                                   AND (event_key LIKE 'nl-%' OR event_key LIKE 'nlpotw-%' OR event_key LIKE 'camp-%' OR event_key LIKE 'relance-%')"),
        'kolejka_sms'     => $n("SELECT COUNT(*) FROM wsm_sms WHERE status = 'kolejka'"),
    ];
}

// ---------------------------------------------------------------------------
//  La pompe
// ---------------------------------------------------------------------------

/**
 * ÉCOULE LA FILE, par petites quantités.
 *
 * E-mails : seulement ceux des campagnes, confirmations et relances (clés
 * WSM_MKT_POMPE_CLES) — un message préparé à la main dans Poczta attend la
 * main. Jamais plus vieux qu'une semaine. Sans poste configurée, rien ne
 * bouge (un échec marquerait toute la file en erreur pour rien). Trois échecs
 * de suite : le serveur ne répond pas, on s'arrête.
 *
 * SMS : smsapi.php décide (heures autorisées, canal ouvert).
 *
 * @return array{mail:int, mail_bledy:int, sms:int, uwagi:list<string>}
 */
function wsm_mkt_pump(PDO $pdo, int $maxMail = 20, int $maxSms = 20, float $budget = 8.0): array {
    $out = ['mail' => 0, 'mail_bledy' => 0, 'sms' => 0, 'uwagi' => []];
    $debut = microtime(true);
    if ($maxMail > 0) {
        if (!wsm_mail_enabled()) {
            $out['uwagi'][] = 'poczta nieskonfigurowana — wiadomości czekają w kolejce';
        } else {
            $ou = implode(' OR ', array_map(fn($p) => 'event_key LIKE ' . $pdo->quote($p . '%'), WSM_MKT_POMPE_CLES));
            $st = $pdo->prepare("SELECT id, email, event_key FROM wsm_messages WHERE direction = 'wyjscie' AND status = 'kolejka'
                                    AND ($ou) AND created_at >= ? ORDER BY id LIMIT " . max(1, min(200, $maxMail)));
            $st->execute([date('Y-m-d H:i:s', time() - WSM_MKT_POMPE_AGE_J * 86400)]);
            // Le refus se relit AU DÉPART : une newsletter peut attendre des
            // jours en file, et qui s'est désinscrit (ou a fait effacer ses
            // données) entre-temps ne doit plus la recevoir. Seule la
            // confirmation d'inscription passe : elle répond à une demande.
            $refus = wsm_mkt_refus($pdo);
            $saute = $pdo->prepare("UPDATE wsm_messages SET status = 'pominieta', error = 'wypisany przed wysyłką'
                                     WHERE id = ? AND status = 'kolejka'");
            // Chaque ligne est PRISE avant l'envoi : deux pompes en même temps
            // (deux écrans ouverts, l'écran et la tâche planifiée) ne
            // l'enverront jamais deux fois. Une ligne restée « wysylanie »
            // après une panne n'est pas renvoyée : un doublon vaut moins
            // qu'un oubli visible dans Poczta.
            $prise = $pdo->prepare("UPDATE wsm_messages SET status = 'wysylanie' WHERE id = ? AND status = 'kolejka'");
            $serie = [];
            foreach ($st->fetchAll() as $m) {
                if (microtime(true) - $debut > $budget) break;
                $id = (int) $m['id'];
                if (!str_starts_with((string) $m['event_key'], 'nlpotw-') && isset($refus[strtolower((string) $m['email'])])) {
                    $saute->execute([$id]);
                    continue;
                }
                $prise->execute([$id]);
                if ($prise->rowCount() !== 1) continue;
                try { [$ok, $err] = wsm_mail_send($pdo, $id); }
                catch (Throwable $e) { $err = $e->getMessage(); wsm_mail_mark($pdo, $id, 'blad', $err); $ok = false; }
                if ($ok) { $out['mail']++; $serie = []; continue; }
                $out['mail_bledy']++;
                // Une adresse refusée est l'affaire de CE message : elle reste
                // en erreur et ne compte pas comme une panne du serveur.
                if (preg_match('/odbiorca odrzucony|adres odbiorcy|wiadomość odrzucona/u', (string) $err)) continue;
                $serie[] = $id;
                if (count($serie) >= 3) {
                    // Trois échecs de suite : c'est le serveur, pas les
                    // adresses. Ces trois-là retournent en file (sinon une
                    // panne marquerait toute la campagne en erreur, trois
                    // lignes par minute) et la pompe s'arrête.
                    $ph = implode(',', array_fill(0, count($serie), '?'));
                    $pdo->prepare("UPDATE wsm_messages SET status = 'kolejka' WHERE status = 'blad' AND id IN ($ph)")->execute($serie);
                    $out['uwagi'][] = 'serwer poczty nie odpowiada — wstrzymano, wiadomości czekają w kolejce';
                    break;
                }
            }
        }
    }
    if ($maxSms > 0) {
        $f = __DIR__ . '/smsapi.php';
        if (is_file($f)) {
            require_once $f;
            $r = wsm_sms_pump($pdo, $maxSms, max(1.0, $budget - (microtime(true) - $debut)));
            $out['sms'] = $r['wyslane'];
            if ($r['uwaga'] !== '') $out['uwagi'][] = $r['uwaga'];
        }
    }
    return $out;
}

/**
 * Le lien suivi d'une campagne (links.php) : ses clics et les commandes
 * qu'il apporte se comptent sur l'écran de la campagne. Créé une fois ; un
 * code de réduction refusé n'empêche pas le lien d'exister.
 *
 * @return array{0:string, 1:string} [code, avertissement]
 */
function wsm_mkt_link(PDO $pdo, string $code, string $nom, string $kod = ''): array {
    require_once __DIR__ . '/links.php';
    wsm_links_ensure($pdo);
    if (wsm_link_find($pdo, $code)) return [$code, ''];
    [$c, $m] = wsm_link_create($pdo, ['nom' => $nom, 'cible' => 'sklep', 'kod' => $kod, 'code' => $code], 'marketing');
    if ($c !== '') return [$c, ''];
    if ($kod !== '') {
        [$c] = wsm_link_create($pdo, ['nom' => $nom, 'cible' => 'sklep', 'code' => $code], 'marketing');
        if ($c !== '') return [$c, $m];
    }
    return ['', $m];
}

/** L'adresse d'une page de la boutique portant le lien suivi. */
function wsm_mkt_url(string $chemin, string $code): string {
    $u = wsm_shop_base_url() . '/' . ltrim($chemin, '/');
    return $code === '' ? $u : $u . (str_contains($u, '?') ? '&' : '?') . 'l=' . rawurlencode($code);
}

/** Clics et commandes d'un lien suivi. */
function wsm_mkt_link_stats(PDO $pdo, string $code): array {
    $out = ['klikniec' => 0, 'zamowien' => 0, 'obrot' => 0];
    if ($code === '') return $out;
    try {
        $st = $pdo->prepare("SELECT klikniec FROM wsm_links WHERE code = ?");
        $st->execute([$code]);
        $out['klikniec'] = (int) $st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(total_gross), 0) AS ca FROM wsm_orders
                              WHERE source = ? AND payment_status = 'oplacone'");
        $st->execute([$code]);
        $r = $st->fetch() ?: [];
        $out['zamowien'] = (int) ($r['n'] ?? 0);
        $out['obrot'] = (int) ($r['ca'] ?? 0);
    } catch (Throwable $e) { /* tables absentes */ }
    return $out;
}

/** Combien de désinscriptions une campagne a provoquées. */
function wsm_mkt_wypisy(PDO $pdo, string $ref): int {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_mkt_events WHERE ref = ? AND typ IN ('wypis_email', 'wypis_sms')");
        $st->execute([$ref]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}
