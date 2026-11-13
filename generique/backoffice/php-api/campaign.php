<?php
// ============================================================================
//  campaign.php — les envois groupés, et les cinq garde-fous sans lesquels
//  ils font plus de mal qu'une année de silence.
//
//  CE QU'UN ENVOI GROUPÉ PEUT CASSER, dans l'ordre :
//
//   1. LA RÉPUTATION DU DOMAINE. Cent messages en une minute depuis une IP
//      qui n'en envoie jamais, et le domaine part en liste noire — y compris
//      pour les confirmations de commande. On ne perd pas une campagne, on
//      perd la BOUTIQUE. D'où la file : les messages entrent en `kolejka` et
//      partent au rythme du travailleur de fond, comme tous les autres.
//
//   2. LE CONSENTEMENT. On n'écrit qu'à ceux qui ont ACHETÉ — une relation
//      commerciale existante, ce que le RODO reconnaît — et jamais à une
//      adresse récoltée autrement. Toute personne peut sortir d'un clic, et
//      le lien de désabonnement est dans CHAQUE message : sans lui, l'envoi
//      est illégal, pas seulement impoli.
//
//   3. LA CONFIANCE. Un envoi part une seule fois. La clé d'idempotence
//      (campagne + adresse) est portée par la table des messages : rejouer
//      l'écran ne renvoie rien. Recevoir deux fois la même offre fait se
//      désabonner ceux qui allaient acheter.
//
//   4. LE TEST QU'ON OUBLIE. On s'envoie TOUJOURS le message d'abord. Une
//      coquille dans l'objet part à cent cinquante personnes et ne se
//      rattrape pas.
//
//   5. LE COMPTE. Une campagne dit combien elle a touché, et combien elle a
//      rapporté — sinon on la reconduit sur une impression.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';

/** Les publics possibles. Chacun se calcule, aucun ne se saisit à la main. */
const WSM_CAMP_SEGMENTS = [
    'klienci'  => 'Wszyscy, którzy kupili',
    'stali'    => 'Stali klienci (3+ zamówienia)',
    'spiacy'   => 'Śpiący (bez zakupu od 120 dni)',
    'firmy'    => 'Konta firmowe',
    'nowi'     => 'Nowi (pierwszy zakup w ostatnich 60 dniach)',
];

/** Jamais plus d'un envoi par personne et par campagne. */
const WSM_CAMP_PREFIXE = 'camp';

/** Le paramètre du lien de désabonnement. */
const WSM_CAMP_STOP_PARAM = 'stop';

/** Tables et colonnes. Idempotent. */
function wsm_camp_ensure(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_campaigns')) wsm_apply_schema($pdo);
    wsm_ensure_columns($pdo, 'wsm_clients', [
        // Le refus est porté par la fiche client : il survit à la campagne
        // qui l'a provoqué, et à toutes les suivantes.
        'no_mailing' => ['TINYINT(1) NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
    ]);
}

/**
 * Les adresses d'un segment.
 *
 * ON NE LIT QUE DES ACHETEURS. Une adresse qui n'a jamais commandé n'a pas
 * de relation commerciale avec nous, et lui écrire serait de la prospection
 * — un autre régime juridique, et un autre métier.
 *
 * @return array [['email','nom','commandes','obrot'], …]
 */
function wsm_camp_audience(PDO $pdo, string $segment): array {
    if (!isset(WSM_CAMP_SEGMENTS[$segment])) return [];
    if (!function_exists('wsm_crm_list')) require_once __DIR__ . '/crm.php';

    $out = [];
    $refus = wsm_camp_refus($pdo);
    foreach (wsm_crm_list($pdo) as $c) {
        $mail = strtolower(trim((string) ($c['email'] ?? '')));
        if ($mail === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) continue;
        if (isset($refus[$mail])) continue;                   // règle 2 : le refus prime

        $paid = (int) ($c['paid_orders'] ?? 0);
        if ($paid < 1) continue;                              // jamais acheté : jamais écrit

        $dernier = strtotime((string) ($c['last_at'] ?? '')) ?: 0;
        $premier = strtotime((string) ($c['first_at'] ?? $c['last_at'] ?? '')) ?: $dernier;
        $garde = match ($segment) {
            'klienci' => true,
            'stali'   => $paid >= 3,
            'spiacy'  => $dernier > 0 && $dernier < time() - 120 * 86400,
            'firmy'   => trim((string) ($c['nip'] ?? '')) !== ''
                      || strtoupper(trim((string) ($c['seg'] ?? ''))) === 'B2B',
            'nowi'    => $premier > 0 && $premier > time() - 60 * 86400,
            default   => false,
        };
        if (!$garde) continue;

        $out[] = ['email' => $mail, 'nom' => (string) ($c['name'] ?? ''),
                  'commandes' => $paid, 'obrot' => (int) ($c['revenue'] ?? 0)];
    }
    return $out;
}

/** Les adresses qui ont dit non. La clé est l'adresse, en minuscules. */
function wsm_camp_refus(PDO $pdo): array {
    $out = [];
    try {
        foreach ($pdo->query("SELECT LOWER(email) AS e FROM wsm_clients WHERE no_mailing = 1")->fetchAll() ?: [] as $r) {
            $out[(string) $r['e']] = true;
        }
    } catch (Throwable $e) { /* colonne absente sur une base ancienne */ }
    return $out;
}

/**
 * Retire une adresse de tous les envois à venir.
 *
 * ON NE SUPPRIME RIEN. Le refus est une donnée : effacer la fiche ferait
 * réapparaître l'adresse au prochain achat, et on réécrirait à quelqu'un qui
 * avait dit non.
 */
function wsm_camp_stop(PDO $pdo, string $email, string $actor = 'klient'): array {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, 'nieprawidłowy adres'];
    wsm_camp_ensure($pdo);
    try {
        $st = $pdo->prepare("SELECT id FROM wsm_clients WHERE LOWER(email) = ?");
        $st->execute([$email]);
        $id = (int) $st->fetchColumn();
        if ($id > 0) {
            $pdo->prepare("UPDATE wsm_clients SET no_mailing = 1 WHERE id = ?")->execute([$id]);
        } else {
            // Pas de fiche : on en crée une, uniquement pour porter le refus.
            $code = 'CL-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            $pdo->prepare("INSERT INTO wsm_clients (code, email, raison, statut, no_mailing)
                           VALUES (?,?,?,'aktywny',1)")
                ->execute([$code, $email, $email]);
        }
    } catch (Throwable $e) { return [false, 'nie udało się zapisać']; }

    if (function_exists('wsm_audit')) {
        wsm_audit($pdo, $actor, 'Rezygnacja z wiadomości', $email, 'Sieć');
    }
    return [true, 'Nie będziemy już wysyłać wiadomości na ten adres.'];
}

/** Le jeton de désabonnement d'une adresse : il n'ouvre que ce refus-là. */
function wsm_camp_stop_token(string $email): string {
    return substr(hash_hmac('sha256', strtolower(trim($email)), wsm_camp_secret()), 0, 32);
}

function wsm_camp_stop_ok(string $email, string $token): bool {
    return hash_equals(wsm_camp_stop_token($email), $token);
}

/** Le secret de signature. Sans lui configuré, on retombe sur la base. */
function wsm_camp_secret(): string {
    $c = wsm_config();
    $s = (string) ($c['admin_token'] ?? '');
    return $s !== '' && $s !== 'xxxx' ? $s : 'wsm-camp-fallback';
}

/**
 * Prépare une campagne — SANS RIEN ENVOYER.
 *
 * On sépare volontairement la préparation de l'envoi : c'est ce qui permet
 * de voir le nombre de destinataires et de s'envoyer un test avant que
 * quoi que ce soit ne parte (règle 4).
 *
 * @return array [id|0, message]
 */
function wsm_camp_create(PDO $pdo, array $in, string $actor): array {
    wsm_camp_ensure($pdo);
    $segment = (string) ($in['segment'] ?? '');
    if (!isset(WSM_CAMP_SEGMENTS[$segment])) return [0, 'Nieznana grupa odbiorców.'];

    $sujet = trim((string) ($in['sujet'] ?? ''));
    $corps = trim((string) ($in['corps'] ?? ''));
    if (mb_strlen($sujet) < 3)   return [0, 'Podaj temat wiadomości.'];
    if (mb_strlen($corps) < 20)  return [0, 'Treść jest za krótka — napisz przynajmniej kilka zdań.'];

    $nom = trim((string) ($in['nom'] ?? '')) ?: $sujet;
    try {
        $pdo->prepare("INSERT INTO wsm_campaigns (nom, segment, sujet, corps, statut, wyslane, created_at)
                       VALUES (?,?,?,?,'przygotowana',0,?)")
            ->execute([mb_substr($nom, 0, 190), $segment, mb_substr($sujet, 0, 250),
                       $corps, date('Y-m-d H:i:s')]);
        $id = (int) $pdo->lastInsertId();
    } catch (Throwable $e) { return [0, 'Nie udało się zapisać: ' . $e->getMessage()]; }

    if (function_exists('wsm_audit')) {
        wsm_audit($pdo, $actor, 'Nowa kampania', 'wsm_campaigns #' . $id, 'Sieć');
    }
    $n = count(wsm_camp_audience($pdo, $segment));
    return [$id, 'Kampania przygotowana. Odbiorców: ' . $n . '. Wyślij najpierw próbkę do siebie.'];
}

/** Une campagne. */
function wsm_camp_get(PDO $pdo, int $id): ?array {
    try {
        $st = $pdo->prepare("SELECT * FROM wsm_campaigns WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * Le corps personnalisé pour un destinataire, avec le lien de sortie.
 *
 * LE LIEN DE DÉSABONNEMENT EST AJOUTÉ ICI, PAS LAISSÉ À LA RÉDACTION. Un
 * humain pressé l'oublierait une fois — et une fois suffit à rendre l'envoi
 * illégal.
 */
function wsm_camp_body(array $camp, array $dest, string $baseShop): string {
    $corps = str_replace(['{{imie}}', '{{nom}}'], [$dest['nom'] ?? '', $dest['nom'] ?? ''],
                         (string) $camp['corps']);
    $stop = rtrim($baseShop, '/') . '/?' . WSM_CAMP_STOP_PARAM . '=' . rawurlencode((string) $dest['email'])
          . '&t=' . wsm_camp_stop_token((string) $dest['email']);
    return $corps . "\n\n—\n"
         . "Nie chcesz takich wiadomości? Wypisz się jednym kliknięciem:\n" . $stop . "\n";
}

/**
 * Envoie la campagne — c'est-à-dire : LA MET EN FILE.
 *
 * Rien ne part d'ici vers un serveur SMTP. Les messages entrent en `kolejka`
 * et le travailleur de fond les écoule à son rythme, exactement comme une
 * confirmation de commande. Cent messages poussés d'un coup depuis une IP
 * qui n'en envoie jamais coûteraient la réputation du domaine — et avec
 * elle, les confirmations de commande.
 *
 * L'idempotence est portée par event_key (campagne + adresse) : rejouer
 * l'écran ne renvoie rien.
 *
 * @return array ['files'=>int, 'ignores'=>int, 'message'=>string]
 */
function wsm_camp_send(PDO $pdo, int $id, string $baseShop, string $actor): array {
    $camp = wsm_camp_get($pdo, $id);
    if (!$camp) return ['files' => 0, 'ignores' => 0, 'message' => 'Nie znaleziono kampanii.'];
    if ((string) $camp['statut'] === 'wyslana') {
        return ['files' => 0, 'ignores' => 0, 'message' => 'Ta kampania została już wysłana.'];
    }

    $files = 0; $ignores = 0;
    foreach (wsm_camp_audience($pdo, (string) $camp['segment']) as $d) {
        $cle = WSM_CAMP_PREFIXE . '-' . $id . '-' . strtolower($d['email']);
        $n = wsm_mail_queue($pdo, [
            'email' => $d['email'],
            'direction' => 'wyjscie',
            'subject' => (string) $camp['sujet'],
            'body' => wsm_camp_body($camp, $d, $baseShop),
            'template_code' => 'kampania',
            'event_key' => $cle,          // UNIQUE : le rejeu ne renvoie rien
            'actor' => $actor ?: 'kampania',
        ]);
        if ($n > 0) $files++; else $ignores++;
    }

    $pdo->prepare("UPDATE wsm_campaigns SET statut = 'wyslana', wyslane = ?, sent_at = ? WHERE id = ?")
        ->execute([$files, date('Y-m-d H:i:s'), $id]);
    if (function_exists('wsm_audit')) {
        wsm_audit($pdo, $actor, 'Wysyłka kampanii', 'wsm_campaigns #' . $id . ' — ' . $files, 'Sieć');
    }

    $m = 'W kolejce: ' . $files . ' wiadomości.';
    if ($ignores > 0) $m .= ' Pominięto ' . $ignores . ' — już wysłane wcześniej.';
    $m .= ' Nic nie poszło jeszcze do SMTP: kolejka wypuszcza je stopniowo,'
        . ' żeby nie spalić reputacji domeny.';
    return ['files' => $files, 'ignores' => $ignores, 'message' => $m];
}

/**
 * Un exemplaire de test, à une seule adresse.
 *
 * Une coquille dans l'objet part à cent cinquante personnes et ne se
 * rattrape pas. Ce message-ci n'est PAS idempotent : on veut pouvoir se le
 * renvoyer autant de fois qu'on corrige.
 */
function wsm_camp_test(PDO $pdo, int $id, string $email, string $baseShop, string $actor): array {
    $camp = wsm_camp_get($pdo, $id);
    if (!$camp) return [false, 'Nie znaleziono kampanii.'];
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, 'Podaj poprawny adres do próbki.'];

    wsm_mail_queue($pdo, [
        'email' => $email, 'direction' => 'wyjscie',
        'subject' => '[PRÓBKA] ' . (string) $camp['sujet'],
        'body' => wsm_camp_body($camp, ['email' => $email, 'nom' => 'Testowy odbiorca'], $baseShop),
        'template_code' => 'kampania',
        'actor' => $actor ?: 'kampania',
    ]);
    return [true, 'Próbka w kolejce do ' . $email . '. Przeczytaj ją w Poczcie, zanim wyślesz do wszystkich.'];
}

/**
 * Ce que chaque campagne a touché et rapporté.
 *
 * LE CHIFFRE D'AFFAIRES EST CELUI DES TRENTE JOURS QUI SUIVENT L'ENVOI, chez
 * les gens qui l'ont reçu. Ce n'est pas une preuve de causalité et l'écran
 * le dit : c'est un ordre de grandeur, pas une attribution.
 */
function wsm_camp_list(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT * FROM wsm_campaigns ORDER BY id DESC")->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }

    $out = [];
    foreach ($rows as $c) {
        $ca = 0; $n = 0;
        if ((string) $c['statut'] === 'wyslana' && trim((string) $c['sent_at']) !== '') {
            $depuis = (string) $c['sent_at'];
            $jusqu = date('Y-m-d H:i:s', (strtotime($depuis) ?: time()) + 30 * 86400);
            try {
                $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(o.total_gross),0) AS ca
                                       FROM wsm_orders o
                                      WHERE o.payment_status = 'oplacone'
                                        AND o.created_at BETWEEN ? AND ?
                                        AND LOWER(o.email) IN (
                                            SELECT LOWER(email) FROM wsm_messages
                                             WHERE event_key LIKE ?)");
                $st->execute([$depuis, $jusqu, WSM_CAMP_PREFIXE . '-' . (int) $c['id'] . '-%']);
                $r = $st->fetch() ?: ['n' => 0, 'ca' => 0];
                $n = (int) $r['n']; $ca = (int) $r['ca'];
            } catch (Throwable $e) { /* la requête peut échouer sur une base ancienne */ }
        }
        $out[] = $c + ['segment_label' => WSM_CAMP_SEGMENTS[(string) $c['segment']] ?? (string) $c['segment'],
                       'zamowien' => $n, 'obrot' => $ca];
    }
    return $out;
}
