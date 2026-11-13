<?php
// ============================================================================
//  relance.php — les commandes créées et jamais payées.
//
//  LE TROU QUE CE FICHIER BOUCHE. Les relances existaient pour les FACTURES.
//  Une facture n'existe qu'après le paiement : une commande abandonnée à la
//  caisse n'en a donc jamais, et personne ne la relançait. Le rapport d'état
//  en comptait presque mille dans la base de développement, et la production
//  n'a AUCUNE commande payée. Ce sont des ventes déjà à moitié faites — le
//  client a choisi, rempli son adresse, et s'est arrêté au paiement.
//
//  CINQ RÈGLES, ET LA PREMIÈRE EST LA PLUS IMPORTANTE :
//
//   1. ON NE RELANCE PAS INDÉFINIMENT. Deux messages, puis on se tait. Le
//      troisième ne récupère personne et fait perdre le client pour de bon,
//      plus l'adresse qui part en indésirable. Le silence est une décision,
//      pas un oubli.
//
//   2. ON NE RELANCE QUE CE QU'ON PEUT ENCAISSER. Sans Stripe prêt,
//      aucun lien de paiement ne fonctionne : envoyer « payez ici » vers une
//      page morte est pire que ne rien envoyer. Fail-closed.
//
//   3. UNE SEULE RELANCE PAR ÉTAPE. La clé d'événement porte la commande ET
//      l'étape : rouvrir l'écran ne renvoie rien.
//
//   4. JAMAIS UNE COMMANDE PAYÉE OU ANNULÉE. Relancer quelqu'un qui a déjà
//      payé est la faute qui coûte le plus de crédibilité d'un coup.
//
//   5. LE MESSAGE PORTE LE LIEN, pas une instruction. « Zaloguj się i
//      dokończ » fait abandonner une seconde fois.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';

/**
 * Les deux étapes, en heures depuis la création.
 *
 * La première est courte — un panier abandonné se rattrape le jour même,
 * pas la semaine suivante. La seconde laisse le temps d'une paie.
 */
const WSM_RELANCE_ETAPES = [
    1 => ['heures' => 24,  'label' => 'Pierwsze przypomnienie (po 24 h)'],
    2 => ['heures' => 96,  'label' => 'Drugie i ostatnie (po 4 dniach)'],
];

/** Au-delà, on ne relance plus : la commande est abandonnée, pas en attente. */
const WSM_RELANCE_ABANDON_JOURS = 30;

/**
 * L'ÉCART MINIMUM ENTRE DEUX RELANCES, en heures.
 *
 * Sans lui, une commande déjà vieille de cinq jours franchit les DEUX seuils
 * d'un coup : le premier passage du travailleur envoie le rappel, le second
 * envoie le « dernier rappel » — deux messages à la même personne dans la
 * même minute. C'est exactement le harcèlement que la règle 1 interdit, et
 * ça se produisait parce que les seuils se comptaient depuis la COMMANDE et
 * jamais depuis le MESSAGE PRÉCÉDENT.
 */
const WSM_RELANCE_ODSTEP_H = 72;

/** Le préfixe de la clé d'idempotence. */
const WSM_RELANCE_CLE = 'relance';

/**
 * Peut-on relancer ? Il faut pouvoir ENCAISSER.
 *
 * Règle 2 : sans Stripe prêt, le lien de paiement ne mène nulle part. Envoyer
 * « payez ici » vers une page morte fait passer la boutique pour cassée et
 * brûle la seule chance de rattraper la vente.
 */
function wsm_relance_possible(): bool {
    $f = __DIR__ . '/stripe.php';
    if (!is_file($f)) return false;
    require_once $f;
    return function_exists('wsm_stripe_ready') && wsm_stripe_ready();
}

/** Ce qui empêche de relancer, dit en clair. */
function wsm_relance_blocage(): string {
    if (wsm_relance_possible()) return '';
    return 'Stripe nie przyjmuje jeszcze płatności — link do zapłaty prowadziłby donikąd. '
         . 'Dokończ konfigurację na ekranie Płatności; do tego czasu nie wysyłamy przypomnień.';
}

/**
 * Les commandes qui attendent un paiement, avec l'étape qui leur revient.
 *
 * @return array [['order'=>array, 'etape'=>int, 'heures'=>int, 'deja'=>int], …]
 */
function wsm_relance_queue(PDO $pdo, int $limit = 200): array {
    if (!function_exists('wsm_order_by_id')) require_once __DIR__ . '/shop.php';

    try {
        $st = $pdo->prepare("SELECT id, created_at FROM wsm_orders
                              WHERE payment_status <> 'oplacone'
                                AND status <> 'anulowane'
                                AND email <> ''
                           ORDER BY id DESC
                              LIMIT " . max(1, min(500, $limit)));
        $st->execute();
        $rows = $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }

    $out = [];
    foreach ($rows as $r) {
        $age = time() - (strtotime((string) $r['created_at']) ?: time());
        $heures = (int) floor($age / 3600);
        // Règle 1 : passé un mois, ce n'est plus une attente, c'est un abandon.
        if ($heures > WSM_RELANCE_ABANDON_JOURS * 24) continue;

        $deja = wsm_relance_deja($pdo, (int) $r['id']);
        $etape = 0;
        foreach (WSM_RELANCE_ETAPES as $n => $e) {
            if ($heures >= $e['heures'] && $deja < $n) { $etape = $n; break; }
        }
        if ($etape === 0) continue;                 // trop tôt, ou tout envoyé

        // L'écart depuis le message PRÉCÉDENT, pas seulement depuis la
        // commande : une commande de cinq jours franchit les deux seuils
        // d'un coup, et enverrait ses deux messages dans la même minute.
        if ($deja > 0) {
            $depuis = wsm_relance_dernier($pdo, (int) $r['id']);
            if ($depuis !== null && (time() - $depuis) < WSM_RELANCE_ODSTEP_H * 3600) continue;
        }

        $o = wsm_order_by_id($pdo, (int) $r['id']);
        if (!$o) continue;
        $out[] = ['order' => $o, 'etape' => $etape, 'heures' => $heures, 'deja' => $deja];
    }
    return $out;
}

/**
 * Combien de relances cette commande a DÉJÀ reçues.
 *
 * On compte les clés d'événement exactes, pas un motif : c'est la même
 * unicité qui empêche le second envoi, donc le compteur et le garde-fou ne
 * peuvent pas diverger.
 */
function wsm_relance_deja(PDO $pdo, int $orderId): int {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_messages WHERE event_key IN (?, ?)");
        $st->execute([WSM_RELANCE_CLE . '-' . $orderId . '-1',
                      WSM_RELANCE_CLE . '-' . $orderId . '-2']);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/**
 * Quand la dernière relance est partie, en horodatage — ou null s'il n'y en
 * a jamais eu.
 */
function wsm_relance_dernier(PDO $pdo, int $orderId): ?int {
    try {
        $st = $pdo->prepare("SELECT MAX(created_at) FROM wsm_messages WHERE event_key IN (?, ?)");
        $st->execute([WSM_RELANCE_CLE . '-' . $orderId . '-1',
                      WSM_RELANCE_CLE . '-' . $orderId . '-2']);
        $d = (string) ($st->fetchColumn() ?: '');
        if ($d === '') return null;
        $t = strtotime($d);
        return $t === false ? null : $t;
    } catch (Throwable $e) { return null; }
}

/**
 * Envoie — c'est-à-dire MET EN FILE — les relances dues.
 *
 * Rien ne part vers SMTP d'ici : les messages entrent en `kolejka` comme
 * tous les autres, et le travailleur de fond les écoule. C'est ce qui évite
 * qu'une centaine de relances d'un coup coûte la réputation du domaine — et
 * avec elle les confirmations de commande.
 *
 * @return array ['wyslane'=>int, 'pominiete'=>int, 'message'=>string]
 */
function wsm_relance_run(PDO $pdo, string $actor = 'automat', int $limit = 200): array {
    if (!wsm_relance_possible()) {
        return ['wyslane' => 0, 'pominiete' => 0, 'message' => wsm_relance_blocage()];
    }

    $n = 0; $skip = 0;
    foreach (wsm_relance_queue($pdo, $limit) as $x) {
        $o = $x['order'];
        // Règle 4, revérifiée AU MOMENT D'ENVOYER : la file a pu être
        // calculée il y a une minute, et le client vient peut-être de payer.
        $st = $pdo->prepare("SELECT payment_status, status FROM wsm_orders WHERE id = ?");
        $st->execute([(int) $o['id']]);
        $etat = $st->fetch() ?: [];
        if (($etat['payment_status'] ?? '') === 'oplacone' || ($etat['status'] ?? '') === 'anulowane') {
            $skip++;
            continue;
        }

        // ON MET EN FILE, ON N'ENVOIE PAS. wsm_mail_auto() remet le message à
        // SMTP dans la seconde — c'est juste pour UNE confirmation déclenchée
        // par UN client. Ici c'est un LOT : deux cents messages poussés d'un
        // coup depuis une IP qui n'en envoie jamais coûtent la réputation du
        // domaine, et avec elle les confirmations de commande, qui n'ont rien
        // demandé. Le travailleur de fond les écoule à son rythme.
        //
        // Règle 3 : la clé porte la commande ET l'étape. Rejouer ne renvoie rien.
        $cle = WSM_RELANCE_CLE . '-' . (int) $o['id'] . '-' . (int) $x['etape'];
        // PAS « zadanie_zaplaty » NI « przypomnienie » : ces deux-là demandent
        // un VIREMENT et donnent un numéro de compte — c'est le bon texte
        // pour une proforma B2B, et le mauvais pour un panier abandonné.
        // Demander un RIB à quelqu'un qui allait payer par carte le fait
        // renoncer une seconde fois.
        $evt = $x['etape'] === 1 ? 'niedokonczone' : 'niedokonczone2';
        $tpl = wsm_mail_template_for_event($pdo, $evt, (string) ($o['lang'] ?? 'pl'));
        if (!$tpl) { $skip++; continue; }        // pas de modèle : rien de muet ne part

        $vars = wsm_mail_vars($o);
        $mis = wsm_mail_queue($pdo, [
            'order_id'      => (int) $o['id'],
            'email'         => (string) $o['email'],
            'direction'     => 'wyjscie',
            'subject'       => wsm_mail_render((string) $tpl['subject'], $vars),
            'body'          => wsm_mail_render((string) $tpl['body'], $vars),
            'template_code' => (string) $tpl['code'],
            'event_key'     => $cle,
            'actor'         => $actor ?: 'automat',
        ]);
        if ($mis > 0) {
            $n++;
            if (function_exists('wsm_order_event')) {
                wsm_order_event($pdo, (int) $o['id'], 'przypomnienie',
                                'etap ' . (int) $x['etape'] . ' → ' . (string) $o['email'],
                                $actor ?: 'automat');
            }
        } else { $skip++; }
    }

    $m = 'W kolejce: ' . $n . ' przypomnień.';
    if ($skip > 0) $m .= ' Pominięto ' . $skip . ' — już wysłane albo opłacone w międzyczasie.';
    if ($n > 0) $m .= ' Nic nie poszło jeszcze do SMTP: kolejka wypuszcza je stopniowo.';
    return ['wyslane' => $n, 'pominiete' => $skip, 'message' => $m];
}

/** De quoi tenir un compteur : combien attendent, combien sont relançables. */
function wsm_relance_kpis(PDO $pdo, ?array $file = null): array {
    $file ??= wsm_relance_queue($pdo);
    $k = ['do_przypomnienia' => count($file), 'etap1' => 0, 'etap2' => 0, 'nieoplacone' => 0, 'kwota' => 0];
    foreach ($file as $x) {
        if ($x['etape'] === 1) $k['etap1']++; else $k['etap2']++;
    }
    try {
        $r = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(total_gross),0) AS s
                            FROM wsm_orders
                           WHERE payment_status <> 'oplacone' AND status <> 'anulowane'")->fetch() ?: [];
        $k['nieoplacone'] = (int) ($r['n'] ?? 0);
        // Ce que ces commandes VAUDRAIENT si elles étaient payées. Ce n'est
        // pas un chiffre d'affaires : c'est ce qu'on laisse sur la table.
        $k['kwota'] = (int) ($r['s'] ?? 0);
    } catch (Throwable $e) { /* base neuve */ }
    return $k;
}
