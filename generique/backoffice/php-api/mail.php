<?php
// ============================================================================
//  mail.php — la messagerie du back-office.
//
//  Trois idées, pas une de plus :
//
//   1. UN MODÈLE EST UNE DONNÉE. Sujet et corps vivent dans wsm_mail_templates,
//      par langue. Le back-office les réécrit sans redéploiement, et le code
//      ne contient aucun texte destiné au client.
//
//   2. UN MESSAGE ENVOYÉ EST UNE TRACE. Chaque envoi est écrit dans
//      wsm_messages AVANT de partir, avec son état (kolejka → wysłana | błąd).
//      Si le serveur de mail est muet, rien n'est perdu : le message reste en
//      file et se renvoie d'un clic. C'est le point qui compte pour une
//      commande hors stock — la promesse « on vous recontacte » doit être
//      vérifiable, pas espérée.
//
//   3. UNE RÉPONSE AUTOMATIQUE NE PART QU'UNE FOIS. La clé d'événement
//      (event_key) est UNIQUE en base : deux notifications tpay pour la même
//      commande ne produisent pas deux e-mails. C'est la base qui l'empêche,
//      pas une vérification applicative qui perdrait la course.
//
//  L'envoi lui-même passe par un transport injectable (wsm_mail_transport) :
//  mail() de PHP, SMTP authentifié, ou rien du tout. Les tests s'y branchent,
//  donc toute la logique se prouve hors ligne — ce qui est la seule façon de
//  prouver un système d'e-mails sans en envoyer.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Les événements qui peuvent déclencher une réponse automatique. */
const WSM_MAIL_EVENTS = [
    'zamowienie'       => 'Nowe zamówienie',
    'na_zamowienie'    => 'Zamówienie ponad stan magazynu',
    'platnosc'         => 'Płatność otrzymana',
    'wysylka'          => 'Przesyłka nadana',
    'zadanie_zaplaty'  => 'Prośba o płatność (proforma)',
    'przypomnienie'    => 'Przypomnienie o płatności',
    // Une commande abandonnée à la caisse n'est PAS une facture impayée. Les
    // deux modèles ci-dessus demandent un VIREMENT et donnent un numéro de
    // compte : c'est juste pour une proforma B2B. Un panier abandonné se
    // rattrape avec un LIEN, pas avec un RIB — demander un virement à
    // quelqu'un qui allait payer par carte le fait renoncer une seconde fois.
    'niedokonczone'    => 'Zamówienie bez zapłaty — przypomnienie 1',
    'niedokonczone2'   => 'Zamówienie bez zapłaty — przypomnienie ostatnie',
    // Le formulaire public. L'accusé part dans la langue du visiteur ;
    // sans modèle dans sa langue, on retombe sur le polonais (contact.php).
    'formularz'        => 'Formularz kontaktowy — potwierdzenie',
    // L'échéance d'un abonnement. RIEN N'EST PRÉLEVÉ : le message porte un
    // lien de paiement, pas un avis de débit. Écrire « pobraliśmy » ici
    // serait faux, et le premier renouvellement deviendrait un litige.
    // Les changements d'état saisis à la main dans la console. Ils portent le
    // nom du statut : le back-office n'a alors rien à traduire, il passe le
    // statut et le modèle correspondant est trouvé — ou il n'y en a pas, et
    // aucun message ne part. Un état sans modèle est silencieux, pas cassé.
    'w_realizacji'     => 'Zamówienie w realizacji',
    'wyslane'          => 'Zamówienie wysłane',
    'dostarczone'      => 'Zamówienie dostarczone',
    'anulowane'        => 'Zamówienie anulowane',
];

/** Les états dont un changement manuel peut prévenir le client. */
const WSM_MAIL_STATUS_EVENTS = ['w_realizacji', 'wyslane', 'dostarczone', 'anulowane'];

const WSM_MAIL_STATUSES = ['kolejka', 'wyslana', 'blad'];

// ---------------------------------------------------------------------------
//  Configuration
// ---------------------------------------------------------------------------

function wsm_mail_cfg(): array {
    $c = (array) (wsm_config()['mail'] ?? []);
    $c += [
        'transport' => '', 'from' => '', 'from_name' => '', 'reply_to' => '',
        'smtp_host' => '', 'smtp_port' => 0, 'smtp_user' => '', 'smtp_pass' => '',
        'smtp_secure' => '', 'bcc' => '',
    ];
    // Réglage vide = valeur de bon sens, pas panne : mail() est le transport
    // que tout serveur PHP sait faire.
    if (!in_array($c['transport'], ['mail', 'smtp'], true)) $c['transport'] = 'mail';
    if ((int) $c['smtp_port'] <= 0) $c['smtp_port'] = strtolower((string) $c['smtp_secure']) === 'ssl' ? 465 : 587;
    return $c;
}

/**
 * Peut-on réellement envoyer ? Sans expéditeur, non : un message sans From
 * part dans les indésirables ou pas du tout. Sans transport configuré non plus.
 * Fail-closed : les messages restent alors en file, visibles dans la console.
 */
function wsm_mail_enabled(): bool {
    $c = wsm_mail_cfg();
    if (!filter_var((string) $c['from'], FILTER_VALIDATE_EMAIL)) return false;
    if ($c['transport'] === 'smtp') return (string) $c['smtp_host'] !== '';
    return $c['transport'] === 'mail';
}

/** Ce qui manque pour envoyer, en clair, pour l'afficher dans la console. */
function wsm_mail_blockers(): array {
    $c = wsm_mail_cfg();
    $out = [];
    if (!filter_var((string) $c['from'], FILTER_VALIDATE_EMAIL)) $out[] = 'adres nadawcy';
    if ($c['transport'] === 'smtp' && (string) $c['smtp_host'] === '') $out[] = 'serwer SMTP';
    return $out;
}

// ---------------------------------------------------------------------------
//  Modèles
// ---------------------------------------------------------------------------

/** Tous les modèles, ordonnés pour l'écran. */
function wsm_mail_templates(PDO $pdo, bool $activeOnly = false): array {
    $sql = "SELECT * FROM wsm_mail_templates" . ($activeOnly ? " WHERE active = 1" : "") .
           " ORDER BY code, lang";
    return $pdo->query($sql)->fetchAll() ?: [];
}

/**
 * Le modèle d'un code dans une langue, avec repli sur le polonais : mieux vaut
 * écrire au client en polonais que ne pas lui écrire.
 */
function wsm_mail_template(PDO $pdo, string $code, string $lang = 'pl'): ?array {
    $st = $pdo->prepare("SELECT * FROM wsm_mail_templates WHERE code = ? AND lang = ? AND active = 1");
    $st->execute([$code, strtolower($lang)]);
    $t = $st->fetch();
    if ($t) return $t;
    if (strtolower($lang) === 'pl') return null;
    $st->execute([$code, 'pl']);
    return $st->fetch() ?: null;
}

/** Le modèle attaché à un événement (le premier actif dans la langue voulue). */
function wsm_mail_template_for_event(PDO $pdo, string $event, string $lang = 'pl'): ?array {
    $st = $pdo->prepare("SELECT code FROM wsm_mail_templates WHERE event = ? AND active = 1 ORDER BY id LIMIT 1");
    $st->execute([$event]);
    $code = (string) $st->fetchColumn();
    return $code === '' ? null : wsm_mail_template($pdo, $code, $lang);
}

// ---------------------------------------------------------------------------
//  Variables et rendu
// ---------------------------------------------------------------------------

/**
 * Montant et adresse de la boutique sans dépendre de shop.php : mail.php est
 * inclus PAR shop.php, l'inverse créerait un cycle. Si les fonctions sont là,
 * on les utilise ; sinon on se débrouille.
 */
function wsm_mail_money(int $grosze): string {
    return function_exists('wsm_money') ? wsm_money($grosze) : number_format($grosze / 100, 2, ',', ' ');
}

function wsm_mail_shop_url(): string {
    if (function_exists('wsm_shop_base_url')) return wsm_shop_base_url();
    return rtrim((string) (wsm_config()['shop_url'] ?? ''), '/');
}

/** Les variables offertes par une commande. Toujours des chaînes. */
function wsm_mail_vars(?array $order): array {
    if (!$order) {
        return ['numer' => '', 'imie' => '', 'nazwisko' => '', 'firma' => '', 'email' => '',
                'kwota' => '', 'pozycje' => '', 'brakujace' => '', 'dostawa' => '',
                'paczkomat' => '', 'link' => '', 'status' => '', 'data' => date('Y-m-d')];
    }
    $lines = [];
    $short = [];
    foreach ((array) ($order['items'] ?? []) as $l) {
        $lines[] = '· ' . $l['name'] . ' × ' . (int) $l['qty'] . ' — ' . wsm_mail_money((int) $l['line_gross']) . ' zł';
        if ((int) ($l['backorder'] ?? 0) > 0) {
            $short[] = '· ' . $l['name'] . ' — ' . (int) $l['backorder'] . ' szt. do wykonania';
        }
    }
    $link = '';
    if (($order['code'] ?? '') !== '' && ($order['access_token'] ?? '') !== '') {
        $link = wsm_mail_shop_url() . '/zamowienie/' . rawurlencode((string) $order['code']) .
                '?t=' . rawurlencode((string) $order['access_token']);
    }
    return [
        'numer'     => (string) ($order['code'] ?? ''),
        'imie'      => (string) ($order['first_name'] ?? ''),
        'nazwisko'  => (string) ($order['last_name'] ?? ''),
        'firma'     => (string) ($order['company'] ?? ''),
        'email'     => (string) ($order['email'] ?? ''),
        'kwota'     => wsm_mail_money((int) ($order['total_gross'] ?? 0)) . ' zł',
        'pozycje'   => implode("\n", $lines),
        'brakujace' => implode("\n", $short),
        'dostawa'   => (string) ($order['delivery_method'] ?? ''),
        'paczkomat' => (string) ($order['inpost_point'] ?? ''),
        'link'      => $link,
        'status'    => (string) ($order['status'] ?? ''),
        // Le numéro de suivi, quand il existe. Un « votre colis est parti »
        // sans numéro oblige le client à écrire pour demander lequel.
        'sledzenie' => (string) ($order['shipment']['tracking_number'] ?? ''),
        'data'      => substr((string) ($order['created_at'] ?? date('Y-m-d')), 0, 10),
    ];
}

/**
 * Remplace {{cle}} par sa valeur. Une variable inconnue est effacée plutôt que
 * laissée en place : un client ne doit jamais recevoir « {{imie}} ».
 */
function wsm_mail_render(string $text, array $vars): string {
    $out = (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function ($m) use ($vars) {
        return (string) ($vars[strtolower($m[1])] ?? '');
    }, $text);

    // Une ligne dont TOUT le contenu variable s'est révélé vide est retirée, et
    // pas laissée en suspens. Sans ça, un envoi sans numéro de suivi produit
    // « Numer przesyłki: » tout seul — le client comprend qu'il manque quelque
    // chose, et il a raison. La règle est étroite : la ligne doit avoir contenu
    // un {{jeton}} et ne plus contenir qu'un libellé suivi de deux points.
    $lignes = explode("\n", $out);
    foreach (explode("\n", $text) as $i => $src) {
        if (!str_contains($src, '{{')) continue;
        if (preg_match('/^\s*[^:{}]{1,40}:\s*$/u', $lignes[$i] ?? '')) $lignes[$i] = null;
    }
    return implode("\n", array_filter($lignes, fn($l) => $l !== null));
}

/**
 * Les variables d'un DOCUMENT (facture, proforma). Le document est autonome —
 * on lit ce qu'il porte, jamais la commande derrière : c'est ce qui garantit
 * qu'une relance cite exactement ce que le client a reçu.
 */
function wsm_mail_vars_invoice(array $inv): array {
    $lines = [];
    foreach ((array) ($inv['items'] ?? []) as $l) {
        $lines[] = '· ' . $l['name'] . ' × ' . (int) $l['qty'] . ' — '
                 . wsm_mail_money((int) $l['line_gross']) . ' zł';
    }
    $who = trim((string) ($inv['buyer_name'] ?? ''));
    $first = $who !== '' ? explode(' ', $who)[0] : '';
    $bank = trim((string) ($inv['iban'] ?? '') . ' ' . (string) ($inv['bank'] ?? ''));
    return [
        'numer'     => (string) ($inv['number'] ?? ''),
        'imie'      => $first,
        'firma'     => $who,
        'email'     => (string) ($inv['buyer_email'] ?? ''),
        'kwota'     => wsm_mail_money((int) ($inv['total_gross'] ?? 0)) . ' zł',
        'termin'    => (string) ($inv['due_at'] ?? ''),
        'rachunek'  => $bank,
        'pozycje'   => implode("\n", $lines),
        'brakujace' => '',
        'dostawa'   => '',
        'paczkomat' => '',
        'link'      => '',
        'status'    => (int) ($inv['paid'] ?? 0) ? 'oplacona' : 'do zapłaty',
        'data'      => (string) ($inv['issued_at'] ?? date('Y-m-d')),
    ];
}

/**
 * Écrit au client à propos d'un document. La clé d'événement contient la date
 * du jour pour les relances : on peut relancer une facture plusieurs fois,
 * mais jamais deux fois le même jour.
 *
 * @return int identifiant du message, 0 si déjà envoyé aujourd'hui
 */
/**
 * Le message qui accompagne un changement d'état saisi à la main.
 *
 * Deux garde-fous, parce que c'est un envoi déclenché par un clic :
 *  • un état sans modèle n'envoie RIEN et ne se plaint pas — on ne va pas
 *    empêcher quelqu'un de passer une commande en « w realizacji » sous
 *    prétexte que personne n'a écrit le texte correspondant ;
 *  • la clé d'événement porte l'état, donc repasser deux fois par le même
 *    état ne réexpédie pas le même message. Faire l'aller-retour
 *    « wysłane → w realizacji → wysłane » ne doit pas écrire deux fois au
 *    client.
 *
 * @return int identifiant du message, 0 si rien n'est parti
 */
function wsm_mail_for_status(PDO $pdo, array $order, string $status, string $actor = ''): int {
    if (!in_array($status, WSM_MAIL_STATUS_EVENTS, true)) return 0;
    try {
        $lang = (string) ($order['lang'] ?? 'pl');
        $tpl = wsm_mail_template_for_event($pdo, $status, $lang);
        if (!$tpl) return 0;
        $to = (string) ($order['email'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 0;
        $vars = wsm_mail_vars($order);
        $id = wsm_mail_queue($pdo, [
            'order_id'      => (int) ($order['id'] ?? 0) ?: null,
            'email'         => $to,
            'direction'     => 'wyjscie',
            'subject'       => wsm_mail_render((string) $tpl['subject'], $vars),
            'body'          => wsm_mail_render((string) $tpl['body'], $vars),
            'template_code' => (string) $tpl['code'],
            'event_key'     => 'status:' . (int) ($order['id'] ?? 0) . ':' . $status,
            'actor'         => $actor ?: 'konsola',
        ]);
        if ($id === 0) return 0;
        if (function_exists('wsm_order_event')) {
            wsm_order_event($pdo, (int) $order['id'], 'wiadomosc', $tpl['code'] . ' → ' . $to, $actor ?: 'konsola');
        }
        if (wsm_mail_enabled()) wsm_mail_send($pdo, $id);
        return $id;
    } catch (Throwable $e) {
        return 0;                          // le changement d'état prime sur son annonce
    }
}

function wsm_mail_for_invoice(PDO $pdo, array $inv, string $event, string $actor = ''): int {
    try {
        $tpl = wsm_mail_template_for_event($pdo, $event, 'pl');
        if (!$tpl) return 0;
        $to = (string) ($inv['buyer_email'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 0;
        $vars = wsm_mail_vars_invoice($inv);
        $key = $event === 'przypomnienie'
            ? 'przypomnienie:' . (int) $inv['id'] . ':' . date('Y-m-d')
            : $event . ':inv:' . (int) $inv['id'];
        $id = wsm_mail_queue($pdo, [
            'order_id'      => $inv['order_id'] ?: null,
            'email'         => $to,
            'direction'     => 'wyjscie',
            'subject'       => wsm_mail_render((string) $tpl['subject'], $vars),
            'body'          => wsm_mail_render((string) $tpl['body'], $vars),
            'template_code' => (string) $tpl['code'],
            'event_key'     => $key,
            'actor'         => $actor ?: 'automat',
        ]);
        if ($id && wsm_mail_enabled()) wsm_mail_send($pdo, $id);
        return $id;
    } catch (Throwable $e) {
        return 0;
    }
}

// ---------------------------------------------------------------------------
//  File d'envoi
// ---------------------------------------------------------------------------

/**
 * Écrit un message dans la file. Renvoie son identifiant, ou 0 si la clé
 * d'événement existe déjà (réponse automatique déjà partie pour ce fait).
 */
function wsm_mail_queue(PDO $pdo, array $m): int {
    $cols = [
        'order_id'      => $m['order_id'] ?? null,
        'email'         => mb_substr((string) ($m['email'] ?? ''), 0, 200),
        // Le défaut est lu DES DEUX CÔTÉS du test. Il ne l'était qu'à gauche :
        // un appelant qui omet « direction » — le cas normal pour un envoi —
        // passait le in_array grâce au défaut, puis relisait la clé absente.
        // Avertissement à chaque message, et un null rangé en base au lieu de
        // « wyjscie ». Trouvé en branchant l'envoi des documents.
        'direction'     => in_array($m['direction'] ?? 'wyjscie', ['wyjscie', 'wejscie', 'notatka'], true)
                           ? ($m['direction'] ?? 'wyjscie') : 'wyjscie',
        // Un saut de ligne dans un sujet deviendrait un EN-TÊTE de plus en SMTP.
        'subject'       => mb_substr(trim((string) preg_replace('/[\r\n\t]+/', ' ', (string) ($m['subject'] ?? ''))), 0, 250),
        'body'          => (string) ($m['body'] ?? ''),
        'template_code' => mb_substr((string) ($m['template_code'] ?? ''), 0, 60),
        'event_key'     => ($m['event_key'] ?? '') !== '' ? (string) $m['event_key'] : null,
        'status'        => ($m['direction'] ?? 'wyjscie') === 'wyjscie' ? 'kolejka' : 'wyslana',
        'actor'         => mb_substr((string) ($m['actor'] ?? 'system'), 0, 120),
    ];
    // La version HTML et le lien de désinscription (newsletter) : écrits
    // seulement s'ils existent — un message texte reste exactement ce qu'il était.
    if (trim((string) ($m['html'] ?? '')) !== '')      $cols['html'] = (string) $m['html'];
    if (trim((string) ($m['unsub_url'] ?? '')) !== '') $cols['unsub_url'] = mb_substr((string) $m['unsub_url'], 0, 500);
    $names = array_keys($cols);
    $sql = 'INSERT INTO wsm_messages (' . implode(',', $names) . ') VALUES (' .
           implode(',', array_fill(0, count($names), '?')) . ')';
    try {
        $pdo->prepare($sql)->execute(array_values($cols));
    } catch (Throwable $e) {
        return 0;                          // event_key déjà présent : rien à refaire
    }
    return (int) $pdo->lastInsertId();
}

/**
 * Le transport. Signature : fn(array $mail): array [bool ok, string erreur].
 * Injectable — les tests remplacent le réseau, la production ne le sait pas.
 */
function wsm_mail_transport(?callable $fn = null): callable {
    static $t = null;
    if ($fn !== null) $t = $fn;
    if ($t === null) $t = 'wsm_mail_transport_default';
    return $t;
}

function wsm_mail_transport_default(array $mail): array {
    $c = wsm_mail_cfg();
    if (!wsm_mail_enabled()) return [false, 'poczta nieskonfigurowana'];
    return $c['transport'] === 'smtp' ? wsm_mail_smtp($mail) : wsm_mail_php($mail);
}

/** En-têtes communs aux deux transports. */
function wsm_mail_headers(array $mail): array {
    $c = wsm_mail_cfg();
    $from = (string) $c['from'];
    $name = (string) $c['from_name'];
    $h = [];
    $h['From'] = $name !== '' ? wsm_mail_encode($name) . ' <' . $from . '>' : $from;
    if (filter_var((string) $c['reply_to'], FILTER_VALIDATE_EMAIL)) $h['Reply-To'] = (string) $c['reply_to'];
    if (filter_var((string) $c['bcc'], FILTER_VALIDATE_EMAIL)) $h['Bcc'] = (string) $c['bcc'];
    // LA DÉSINSCRIPTION EN UN CLIC (RFC 8058). Gmail et Yahoo l'exigent des
    // envois groupés : sans elle, la newsletter part en indésirables — et la
    // réputation du domaine avec elle, donc les confirmations de commande.
    $unsub = trim((string) ($mail['unsub_url'] ?? ''));
    if (preg_match('#^https?://#', $unsub)) {
        $h['List-Unsubscribe'] = '<' . $unsub . '>';
        $h['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
    }
    $h['MIME-Version'] = '1.0';
    if (trim((string) ($mail['html'] ?? '')) !== '') {
        $h['Content-Type'] = 'multipart/alternative; boundary="' . wsm_mail_boundary($mail) . '"';
    } else {
        $h['Content-Type'] = 'text/plain; charset=UTF-8';
        $h['Content-Transfer-Encoding'] = '8bit';
    }
    return $h;
}

/** La frontière des parties : stable pour un même message (en-tête et corps la partagent). */
function wsm_mail_boundary(array $mail): string {
    return '=_wsm_' . substr(hash('sha256', (string) ($mail['to'] ?? '') . '|' . (string) ($mail['subject'] ?? '')
                                    . '|' . (string) ($mail['html'] ?? '')), 0, 28);
}

/**
 * Le corps tel qu'il part. Texte seul : inchangé. Avec HTML : deux parties
 * (texte, puis HTML), en base64 — une ligne HTML de plus de 998 caractères
 * serait coupée par le serveur SMTP au milieu d'une balise.
 */
function wsm_mail_body(array $mail): string {
    $html = (string) ($mail['html'] ?? '');
    if (trim($html) === '') return (string) $mail['body'];
    $b = wsm_mail_boundary($mail);
    $part = fn(string $type, string $txt): string => "--$b\r\nContent-Type: $type; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n" . rtrim(chunk_split(base64_encode($txt), 76, "\r\n")) . "\r\n";
    return $part('text/plain', (string) $mail['body']) . $part('text/html', $html) . "--$b--\r\n";
}

/** Sujet non-ASCII : encodé, sinon il arrive en mojibake. */
function wsm_mail_encode(string $s): string {
    return preg_match('/[\x80-\xFF]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function wsm_mail_php(array $mail): array {
    if (!function_exists('mail')) return [false, 'funkcja mail() niedostępna'];
    $h = wsm_mail_headers($mail);
    $lines = [];
    foreach ($h as $k => $v) if ($k !== 'Subject') $lines[] = "$k: $v";
    $ok = @mail((string) $mail['to'], wsm_mail_encode((string) $mail['subject']),
                wsm_mail_body($mail), implode("\r\n", $lines));
    return $ok ? [true, ''] : [false, 'mail() odrzucił wiadomość'];
}

/**
 * Client SMTP minimal : EHLO, STARTTLS si demandé, AUTH LOGIN, MAIL/RCPT/DATA.
 * Volontairement court — il ne gère qu'un message texte à un destinataire,
 * ce qui est exactement ce que la boutique envoie.
 */
function wsm_mail_smtp(array $mail): array {
    $c = wsm_mail_cfg();
    $host = (string) $c['smtp_host'];
    $port = (int) $c['smtp_port'] ?: 587;
    $secure = strtolower((string) $c['smtp_secure']);
    $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

    $fp = @stream_socket_client($target, $errno, $errstr, 10);
    if (!$fp) return [false, "SMTP: $errstr"];
    stream_set_timeout($fp, 10);

    $read = function () use ($fp): array {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return [(int) substr($out, 0, 3), trim($out)];
    };
    $say = function (string $cmd) use ($fp, $read): array {
        fwrite($fp, $cmd . "\r\n");
        return $read();
    };

    try {
        [$code] = $read();
        if ($code !== 220) return [false, 'SMTP: serwer nie przywitał'];
        $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
        [$code] = $say($ehlo);
        if ($code !== 250) return [false, 'SMTP: EHLO odrzucone'];

        if ($secure === 'tls') {
            [$code] = $say('STARTTLS');
            if ($code !== 220) return [false, 'SMTP: STARTTLS niedostępne'];
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return [false, 'SMTP: szyfrowanie nieudane'];
            }
            [$code] = $say($ehlo);
            if ($code !== 250) return [false, 'SMTP: EHLO po TLS odrzucone'];
        }

        if ((string) $c['smtp_user'] !== '') {
            [$code] = $say('AUTH LOGIN');
            if ($code !== 334) return [false, 'SMTP: AUTH niedostępne'];
            [$code] = $say(base64_encode((string) $c['smtp_user']));
            if ($code !== 334) return [false, 'SMTP: login odrzucony'];
            [$code, $msg] = $say(base64_encode((string) $c['smtp_pass']));
            if ($code !== 235) return [false, 'SMTP: uwierzytelnienie nieudane'];
        }

        [$code] = $say('MAIL FROM:<' . $c['from'] . '>');
        if ($code !== 250) return [false, 'SMTP: nadawca odrzucony'];
        [$code, $msg] = $say('RCPT TO:<' . $mail['to'] . '>');
        if ($code !== 250 && $code !== 251) return [false, 'SMTP: odbiorca odrzucony — ' . $msg];
        [$code] = $say('DATA');
        if ($code !== 354) return [false, 'SMTP: DATA odrzucone'];

        $h = wsm_mail_headers($mail);
        $head = ['To: ' . $mail['to'], 'Subject: ' . wsm_mail_encode((string) $mail['subject'])];
        foreach ($h as $k => $v) if ($k !== 'Bcc') $head[] = "$k: $v";
        // Un point seul en début de ligne terminerait le message : on le double.
        $body = preg_replace('/^\./m', '..', wsm_mail_body($mail));
        [$code, $msg] = $say(implode("\r\n", $head) . "\r\n\r\n" . $body . "\r\n.");
        if ($code !== 250) return [false, 'SMTP: wiadomość odrzucona — ' . $msg];
        $say('QUIT');
        return [true, ''];
    } finally {
        @fclose($fp);
    }
}

/** Tente l'envoi d'un message en file. Met à jour son état dans tous les cas. */
function wsm_mail_send(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT * FROM wsm_messages WHERE id = ?");
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m) return [false, 'nie znaleziono wiadomości'];
    if (($m['direction'] ?? '') !== 'wyjscie') return [false, 'to nie jest wiadomość wychodząca'];
    if (!filter_var((string) $m['email'], FILTER_VALIDATE_EMAIL)) {
        wsm_mail_mark($pdo, $id, 'blad', 'adres odbiorcy nieprawidłowy');
        return [false, 'adres odbiorcy nieprawidłowy'];
    }

    [$ok, $err] = (wsm_mail_transport())([
        'to' => (string) $m['email'], 'subject' => (string) $m['subject'], 'body' => (string) $m['body'],
        'html' => (string) ($m['html'] ?? ''), 'unsub_url' => (string) ($m['unsub_url'] ?? ''),
    ]);
    wsm_mail_mark($pdo, $id, $ok ? 'wyslana' : 'blad', $ok ? '' : $err);
    return [$ok, $err];
}

function wsm_mail_mark(PDO $pdo, int $id, string $status, string $error = ''): void {
    $pdo->prepare("UPDATE wsm_messages SET status = ?, error = ?, sent_at = CASE WHEN ? = 'wyslana' THEN CURRENT_TIMESTAMP ELSE sent_at END WHERE id = ?")
        ->execute([$status, mb_substr($error, 0, 250), $status, $id]);
}

/**
 * Réponse automatique. Met le message en file, tente de l'envoyer, et n'échoue
 * JAMAIS bruyamment : une commande ne se perd pas parce qu'un serveur de mail
 * est indisponible — le message reste en file, l'écran Poczta le montre.
 *
 * @return int identifiant du message, 0 si rien à faire (déjà envoyé, ou pas
 *             de modèle attaché à cet événement).
 */
function wsm_mail_auto(PDO $pdo, string $event, array $order): int {
    try {
        $lang = (string) ($order['lang'] ?? 'pl');
        $tpl = wsm_mail_template_for_event($pdo, $event, $lang);
        if (!$tpl) return 0;
        $vars = wsm_mail_vars($order);
        $id = wsm_mail_queue($pdo, [
            'order_id'      => (int) ($order['id'] ?? 0) ?: null,
            'email'         => (string) ($order['email'] ?? ''),
            'direction'     => 'wyjscie',
            'subject'       => wsm_mail_render((string) $tpl['subject'], $vars),
            'body'          => wsm_mail_render((string) $tpl['body'], $vars),
            'template_code' => (string) $tpl['code'],
            'event_key'     => $event . ':' . (int) ($order['id'] ?? 0),
            'actor'         => 'automat',
        ]);
        if ($id === 0) return 0;
        if (($order['id'] ?? 0) && function_exists('wsm_order_event')) {
            wsm_order_event($pdo, (int) $order['id'], 'wiadomosc', $tpl['code'] . ' → ' . (string) ($order['email'] ?? ''), 'automat');
        }
        if (wsm_mail_enabled()) wsm_mail_send($pdo, $id);
        return $id;
    } catch (Throwable $e) {
        return 0;                          // la commande prime sur son accusé de réception
    }
}

// ---------------------------------------------------------------------------
//  Lectures pour la console
// ---------------------------------------------------------------------------

function wsm_messages_list(PDO $pdo, array $f = []): array {
    $where = [];
    $args = [];
    if (!empty($f['order_id'])) { $where[] = 'm.order_id = ?'; $args[] = (int) $f['order_id']; }
    if (!empty($f['status']))   { $where[] = 'm.status = ?';   $args[] = (string) $f['status']; }
    if (!empty($f['q'])) {
        $where[] = '(m.email LIKE ? OR m.subject LIKE ?)';
        $args[] = '%' . $f['q'] . '%';
        $args[] = '%' . $f['q'] . '%';
    }
    $sql = "SELECT m.*, o.code AS order_code FROM wsm_messages m
              LEFT JOIN wsm_orders o ON o.id = m.order_id"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY m.id DESC LIMIT ' . max(1, min(500, (int) ($f['limit'] ?? 200)));
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll() ?: [];
}

function wsm_message_by_id(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT m.*, o.code AS order_code FROM wsm_messages m
                           LEFT JOIN wsm_orders o ON o.id = m.order_id WHERE m.id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Compteurs de l'écran Poczta. */
function wsm_mail_kpis(PDO $pdo): array {
    $n = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    return [
        'total'   => $n("SELECT COUNT(*) FROM wsm_messages"),
        'queued'  => $n("SELECT COUNT(*) FROM wsm_messages WHERE status = 'kolejka'"),
        'sent'    => $n("SELECT COUNT(*) FROM wsm_messages WHERE status = 'wyslana'"),
        'failed'  => $n("SELECT COUNT(*) FROM wsm_messages WHERE status = 'blad'"),
    ];
}
