<?php
// ============================================================================
//  contact.php — le formulaire de contact de la boutique.
//
//  Un formulaire public est une porte ouverte sur la boîte mail de la
//  maison. Ce fichier existe surtout pour la tenir.
//
//  CINQ RÈGLES, DANS L'ORDRE D'IMPORTANCE :
//
//   1. LE MESSAGE VA DANS LA MESSAGERIE, PAS DANS UN MAIL. Un formulaire qui
//      se contente d'envoyer un e-mail perd tout ce qu'on n'a pas lu tout de
//      suite : pas de statut, pas de recherche, pas de trace si le SMTP
//      tombe. Il entre donc dans wsm_messages en `wejscie`, exactement comme
//      un courrier reçu — et l'écran Poczta le traite comme le reste.
//
//   2. TROIS FILTRES ANTI-ROBOT, AUCUN CAPTCHA. Un piège invisible, un délai
//      minimum signé, et un plafond par adresse IP. Un captcha ferait porter
//      le coût de la défense au client — et les plus gênés seraient ceux qui
//      voient mal. Ces trois-là ne coûtent rien à personne d'honnête.
//
//   3. LE DÉLAI EST SIGNÉ. Un champ caché « instant d'ouverture » que le
//      client renverrait tel quel se falsifie en une ligne. Il porte donc une
//      signature HMAC : on peut vérifier qu'il vient de nous sans rien
//      stocker côté serveur.
//
//   4. ON ACCUSE RÉCEPTION DANS LA LANGUE DU VISITEUR. Quelqu'un qui écrit en
//      ukrainien et reçoit un accusé en polonais se demande si le message est
//      parti. Faute de modèle dans sa langue, on retombe sur le polonais —
//      jamais sur rien.
//
//   5. L'ADRESSE DU VISITEUR N'EST PAS UN EXPÉDITEUR. On ne renvoie jamais un
//      mail « de la part de » l'adresse saisie : ce serait signer nos
//      en-têtes avec un domaine qui ne nous appartient pas, et se faire
//      classer en indésirable pour de bon.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Combien de messages une même adresse IP peut déposer par heure. */
const WSM_CONTACT_MAX_PAR_IP = 5;

/** Sous ce délai, c'est un robot : personne ne lit et remplit en 3 secondes. */
const WSM_CONTACT_DELAI_MIN = 3;

/** Au-delà, le formulaire est périmé : on refuse plutôt que d'accepter un
 *  jeton vieux d'une journée, qui aurait pu être récolté puis rejoué. */
const WSM_CONTACT_DELAI_MAX = 7200;

/** Les sujets proposés. La valeur stockée est la clé, jamais le libellé
 *  traduit : sinon le même sujet s'écrirait de huit façons en base. */
const WSM_CONTACT_SUJETS = ['pytanie', 'zamowienie', 'wspolpraca', 'reklamacja', 'inne'];

/**
 * La clé qui signe l'horodatage du formulaire.
 *
 * Dérivée du jeton d'administration — déjà secret, déjà présent, et qui ne
 * sort jamais du serveur. Sans lui, on tombe sur une clé propre à la machine
 * plutôt que sur une constante écrite dans un dépôt public.
 */
function wsm_contact_secret(): string {
    $cfg = wsm_config();
    $base = (string) ($cfg['admin_token'] ?? '');
    if ($base === '') $base = (string) ($cfg['sqlite_path'] ?? __DIR__) . php_uname('n');
    return hash('sha256', 'wsm-contact|' . $base);
}

/** Le jeton horodaté à poser dans le formulaire. */
function wsm_contact_stamp(?int $t = null): string {
    $t = $t ?? time();
    return $t . '.' . substr(hash_hmac('sha256', (string) $t, wsm_contact_secret()), 0, 24);
}

/**
 * Le jeton est-il authentique, et le délai plausible ?
 *
 * @return array [ok, raison] — la raison sert au journal, jamais à l'écran :
 *               dire « trop rapide » à un robot lui apprend à attendre.
 */
function wsm_contact_stamp_ok(string $jeton, ?int $now = null): array {
    $now = $now ?? time();
    $p = explode('.', $jeton, 2);
    if (count($p) !== 2 || !ctype_digit($p[0])) return [false, 'malforme'];
    $attendu = substr(hash_hmac('sha256', $p[0], wsm_contact_secret()), 0, 24);
    if (!hash_equals($attendu, $p[1])) return [false, 'signature'];

    $age = $now - (int) $p[0];
    if ($age < 0)                        return [false, 'futur'];
    if ($age < WSM_CONTACT_DELAI_MIN)    return [false, 'trop_rapide'];
    if ($age > WSM_CONTACT_DELAI_MAX)    return [false, 'perime'];
    return [true, ''];
}

/**
 * Cette adresse IP a-t-elle déjà trop écrit dans l'heure ?
 *
 * Le compte se fait sur les messages déjà enregistrés : pas de table de
 * compteurs à entretenir, et le plafond survit à un redémarrage.
 */
function wsm_contact_trop(PDO $pdo, string $ip): bool {
    if ($ip === '') return false;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_messages
                              WHERE direction = 'wejscie' AND actor = ?
                                AND created_at >= ?");
        $st->execute(['formularz|' . $ip, date('Y-m-d H:i:s', time() - 3600)]);
        return (int) $st->fetchColumn() >= WSM_CONTACT_MAX_PAR_IP;
    } catch (Throwable $e) { return false; }
}

/** L'adresse IP de l'appelant, sans faire confiance aux en-têtes falsifiables. */
function wsm_contact_ip(): string {
    // REMOTE_ADDR est posé par le serveur ; X-Forwarded-For vient du client et
    // ne vaut que derrière un proxy dont on est sûr. On garde donc le premier
    // et on ignore le second : un plafond contournable ne plafonne rien.
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/**
 * Valide et enregistre un message de contact.
 *
 * @param array $in  champs du formulaire
 * @return array [id|null, erreurs par champ]
 */
function wsm_contact_submit(PDO $pdo, array $in, string $lang = 'pl'): array {
    require_once __DIR__ . '/mail.php';
    $e = [];

    // --- Les robots d'abord : inutile de valider un message qu'on refuse ---
    // Le piège. Un champ que le style masque et qu'aucun humain ne remplit ;
    // un robot qui parcourt le HTML le remplit presque toujours.
    if (trim((string) ($in['firma_www'] ?? '')) !== '') {
        return [null, ['_bot' => 'piege']];
    }
    [$okStamp, $pourquoi] = wsm_contact_stamp_ok((string) ($in['_ts'] ?? ''));
    if (!$okStamp) return [null, ['_bot' => $pourquoi]];

    $ip = wsm_contact_ip();
    if (wsm_contact_trop($pdo, $ip)) {
        // Celui-ci se dit à l'écran : c'est peut-être un vrai client insistant.
        return [null, ['_limit' => 'zbyt wiele wiadomości — spróbuj za godzinę']];
    }

    // --- Le contenu ---
    $nom = trim((string) ($in['name'] ?? ''));
    if ($nom === '')             $e['name'] = 'imię wymagane';
    elseif (mb_strlen($nom) > 120) $e['name'] = 'maks. 120 znaków';

    $mail = strtolower(trim((string) ($in['email'] ?? '')));
    if ($mail === '')                                        $e['email'] = 'adres wymagany';
    elseif (!filter_var($mail, FILTER_VALIDATE_EMAIL))       $e['email'] = 'nieprawidłowy adres';
    elseif (mb_strlen($mail) > 190)                          $e['email'] = 'adres zbyt długi';

    $sujet = (string) ($in['topic'] ?? 'pytanie');
    if (!in_array($sujet, WSM_CONTACT_SUJETS, true)) $sujet = 'inne';

    $corps = trim((string) ($in['message'] ?? ''));
    if ($corps === '')                 $e['message'] = 'wiadomość wymagana';
    elseif (mb_strlen($corps) < 10)    $e['message'] = 'napisz choć jedno zdanie';
    elseif (mb_strlen($corps) > 5000)  $e['message'] = 'maks. 5000 znaków';

    // Le consentement est une obligation, pas une case de confort : sans lui
    // on n'a pas le droit de garder l'adresse pour répondre.
    if (empty($in['consent'])) $e['consent'] = 'zgoda wymagana, żeby odpowiedzieć';

    $tel = trim((string) ($in['phone'] ?? ''));
    if ($tel !== '' && !preg_match('/^[0-9 +()\-]{6,24}$/', $tel)) $e['phone'] = 'nieprawidłowy numer';

    if ($e) return [null, $e];

    // --- L'enregistrement ---
    // Le message arrive dans la messagerie comme un courrier reçu : même
    // écran, même recherche, même statuts. `actor` porte l'IP pour le
    // plafond — c'est aussi ce qui permet de retrouver une salve d'un coup.
    $entete = "Formularz kontaktowy · temat: $sujet · język: $lang";
    if ($tel !== '') $entete .= " · tel.: $tel";
    $texte = $entete . "\n\n" . $corps;

    $id = wsm_mail_queue($pdo, [
        'email'     => $mail,
        'direction' => 'wejscie',
        'subject'   => wsm_contact_sujet_libelle($sujet) . ' — ' . $nom,
        'body'      => $texte,
        'actor'     => 'formularz|' . $ip,
    ]);
    if ($id === 0) return [null, ['_db' => 'nie udało się zapisać wiadomości']];

    wsm_contact_accuse($pdo, $mail, $nom, $sujet, $lang, $id);
    return [$id, []];
}

/**
 * L'accusé de réception, dans la langue du visiteur.
 *
 * Silencieux à la moindre difficulté — modèle absent, messagerie non
 * configurée, SMTP en panne : le message du client est DÉJÀ enregistré, et
 * échouer ici perdrait un contact pour un accusé. Le repli sur le polonais
 * vaut mieux que rien : quelqu'un qui écrit et ne reçoit aucune confirmation
 * se demande si son message est parti, et écrit une seconde fois.
 *
 * @return int id du message d'accusé, 0 si rien n'est parti
 */
function wsm_contact_accuse(PDO $pdo, string $to, string $nom, string $sujet,
                            string $lang, int $refId): int {
    try {
        $tpl = wsm_mail_template_for_event($pdo, 'formularz', $lang);
        if (!$tpl) $tpl = wsm_mail_template_for_event($pdo, 'formularz', 'pl');
        if (!$tpl) return 0;

        $vars = ['imie' => $nom, 'temat' => wsm_contact_sujet_libelle($sujet),
                 'sklep' => wsm_mail_shop_url()];
        $id = wsm_mail_queue($pdo, [
            'email'         => $to,
            'direction'     => 'wyjscie',
            'subject'       => wsm_mail_render((string) $tpl['subject'], $vars),
            'body'          => wsm_mail_render((string) $tpl['body'], $vars),
            'template_code' => (string) $tpl['code'],
            // Une clé par message reçu : un double envoi du formulaire ne
            // produit pas deux accusés pour le même message.
            'event_key'     => 'formularz:' . $refId,
            'actor'         => 'formularz',
        ]);
        if ($id === 0) return 0;
        if (wsm_mail_enabled()) wsm_mail_send($pdo, $id);
        return $id;
    } catch (Throwable $e) {
        return 0;
    }
}

/** Le libellé polonais d'un sujet — celui qui s'affiche dans Poczta. */
function wsm_contact_sujet_libelle(string $code): string {
    return [
        'pytanie'    => 'Pytanie',
        'zamowienie' => 'Zamówienie',
        'wspolpraca' => 'Współpraca',
        'reklamacja' => 'Reklamacja',
        'inne'       => 'Inne',
    ][$code] ?? 'Inne';
}
