<?php
// ============================================================================
//  crm.php — les clients, enfin visibles.
//
//  LE TROU QUE CE FICHIER BOUCHE. La console connaissait six « kontrahenci »
//  saisis à la main pour la TVA intracommunautaire, et zéro des cent
//  quarante-quatre personnes qui ont réellement acheté. Leurs commandes
//  portaient une adresse e-mail et rien d'autre : impossible de savoir qui
//  revient, qui a dépensé combien, qui n'a plus rien commandé depuis six mois.
//
//  CINQ RÈGLES, DANS L'ORDRE D'IMPORTANCE :
//
//   1. LE CLIENT, C'EST L'ADRESSE E-MAIL. C'est la seule chose qui relie deux
//      commandes d'une même personne. Un nom se tape différemment à chaque
//      fois, une adresse postale change. Deux personnes qui partagent une
//      boîte sont donc un seul client — et c'est la bonne réponse : c'est
//      bien la même boîte qui reçoit les factures.
//
//   2. LES BADGES SE CALCULENT, ILS NE SE STOCKENT PAS. Un « VIP » écrit en
//      base reste VIP trois ans après sa dernière commande, et personne ne
//      s'en aperçoit. Ici ils se déduisent des chiffres à chaque affichage :
//      ils ne peuvent pas mentir.
//
//   3. ON NE COMPTE QUE L'ENCAISSÉ. Même règle que l'Audyt et que le décompte
//      de la plateforme. Un client dont trois commandes sont impayées n'est
//      pas un bon client, et l'afficher comme tel ferait prendre une décision
//      commerciale sur de l'argent qui n'est jamais arrivé.
//
//   4. UNE NOTE EST SIGNÉE ET DATÉE. « Client difficile » sans auteur ni date
//      est une rumeur ; avec, c'est une information.
//
//   5. RIEN N'EST INVENTÉ. Pas de score composite, pas de « probabilité de
//      rachat ». L'écran montre ce qui s'est passé, et laisse la personne qui
//      connaît le métier en tirer les conclusions.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Au-delà, un client n'a plus donné signe de vie : il « dort ». */
const WSM_CRM_DORMANT_JOURS = 120;

/** À partir de combien de commandes payées on parle d'un habitué. */
const WSM_CRM_FIDELE_MIN = 3;

/** La part du chiffre d'affaires qui définit le haut du panier. */
const WSM_CRM_VIP_TOP = 0.10;

function wsm_crm_ensure(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_client_notes')) wsm_apply_schema($pdo);
    wsm_ensure_columns($pdo, 'wsm_clients', [
        // La photo d'un contact professionnel : une tête sur un nom fait
        // gagner du temps au téléphone, et c'est tout ce qu'on lui demande.
        'photo_url' => ['VARCHAR(255) NOT NULL DEFAULT \'\'', 'TEXT NOT NULL DEFAULT \'\''],
    ]);
}

/**
 * Tous les clients, agrégés depuis les commandes.
 *
 * La requête tourne EN UNE PASSE. Boucler sur cent quarante-quatre adresses
 * pour compter leurs commandes rendrait l'écran inutilisable le jour où la
 * boutique marche vraiment — et c'est précisément ce jour-là qu'on en a besoin.
 *
 * @return array [['email','name','orders','paid_orders','revenue','first_at',
 *                 'last_at','lang','unpaid','nip','client_id','photo'], …]
 */
function wsm_crm_list(PDO $pdo): array {
    wsm_crm_ensure($pdo);
    $out = [];

    $sql = "SELECT LOWER(email) AS em, email, first_name, last_name, company, nip, lang,
                   status, payment_status, total_gross, created_at, paid_at
              FROM wsm_orders WHERE email <> ''";
    $cols = wsm_table_columns($pdo, 'wsm_orders');
    if (!in_array('company', $cols, true)) $sql = str_replace(', company,', ", '' AS company,", $sql);
    if (!in_array('paid_at', $cols, true)) $sql = str_replace(', paid_at', ", created_at AS paid_at", $sql);

    foreach ($pdo->query($sql)->fetchAll() ?: [] as $o) {
        $k = (string) $o['em'];
        if (!isset($out[$k])) {
            $out[$k] = [
                'email' => (string) $o['email'], 'name' => '', 'company' => '',
                'orders' => 0, 'paid_orders' => 0, 'revenue' => 0, 'unpaid' => 0, 'weight_g' => 0,
                'first_at' => '', 'last_at' => '', 'lang' => '', 'nip' => '',
                'client_id' => 0, 'photo' => '',
            ];
        }
        $c =& $out[$k];
        $c['orders']++;

        $annule = ($o['status'] ?? '') === 'anulowane';
        $paye   = ($o['payment_status'] ?? '') === 'oplacone';
        if (!$annule && $paye) {
            $c['paid_orders']++;
            $c['revenue'] += (int) $o['total_gross'];
        } elseif (!$annule) {
            $c['unpaid']++;
        }

        // Le nom le plus récent gagne : quelqu'un qui corrige son orthographe
        // à la dernière commande a raison contre celle d'il y a deux ans.
        $d = (string) $o['created_at'];
        if ($c['first_at'] === '' || $d < $c['first_at']) $c['first_at'] = $d;
        if ($d >= $c['last_at']) {
            $c['last_at'] = $d;
            $nom = trim((string) $o['first_name'] . ' ' . (string) $o['last_name']);
            if ($nom !== '') $c['name'] = $nom;
            if (($o['company'] ?? '') !== '') $c['company'] = (string) $o['company'];
            if (($o['nip'] ?? '') !== '')     $c['nip'] = (string) $o['nip'];
            if (($o['lang'] ?? '') !== '')    $c['lang'] = (string) $o['lang'];
        }
        unset($c);
    }

    // LES KILOS. Le seuil d'un compte professionnel se compte en kg, pas en
    // zlotys : 2 000 zł de pralines et 2 000 zł de tablettes n'ont pas la
    // même place sur l'écran des contrats. Le poids d'une ligne est celui
    // écrit à la commande ; une ligne ancienne qui n'en porte pas retombe sur
    // la fiche produit × quantité, comme le calcul du compte professionnel.
    try {
        $sqlG = "SELECT LOWER(o.email) AS em,
                        SUM(CASE WHEN i.weight_g > 0 THEN i.weight_g ELSE i.qty * COALESCE(p.weight_g, 0) END) AS g
                   FROM wsm_order_items i
                   JOIN wsm_orders o ON o.id = i.order_id
              LEFT JOIN wsm_products p ON p.id = i.product_id
                  WHERE o.email <> '' AND o.status <> 'anulowane' AND o.payment_status = 'oplacone'
               GROUP BY LOWER(o.email)";
        foreach ($pdo->query($sqlG)->fetchAll() ?: [] as $g) {
            if (isset($out[(string) $g['em']])) $out[(string) $g['em']]['weight_g'] = (int) $g['g'];
        }
    } catch (Throwable $e) { /* table absente : la colonne reste à zéro */ }

    // Le raccord avec les fiches B2B saisies à la main : même adresse, même
    // personne. Sans ce raccord, un contact professionnel apparaîtrait deux
    // fois — une fois avec ses achats, une fois avec son numéro de TVA.
    try {
        foreach ($pdo->query("SELECT * FROM wsm_clients")->fetchAll() ?: [] as $cl) {
            $k = strtolower(trim((string) ($cl['email'] ?? '')));
            if ($k === '') continue;
            if (!isset($out[$k])) {
                $out[$k] = [
                    'email' => (string) $cl['email'], 'name' => '', 'company' => '',
                    'orders' => 0, 'paid_orders' => 0, 'revenue' => 0, 'unpaid' => 0, 'weight_g' => 0,
                    'first_at' => '', 'last_at' => '', 'lang' => '', 'nip' => '',
                    'client_id' => 0, 'photo' => '',
                ];
            }
            $out[$k]['client_id'] = (int) $cl['id'];
            $out[$k]['photo']     = (string) ($cl['photo_url'] ?? '');
            if ($out[$k]['name'] === '') {
                $out[$k]['name'] = trim((string) ($cl['first_name'] ?? '') . ' ' . (string) ($cl['last_name'] ?? ''));
            }
            if ($out[$k]['company'] === '' && ($cl['raison'] ?? '') !== '') $out[$k]['company'] = (string) $cl['raison'];
            if ($out[$k]['nip'] === '' && ($cl['nip'] ?? '') !== '')        $out[$k]['nip'] = (string) $cl['nip'];
        }
    } catch (Throwable $e) { /* table absente : on se contente des commandes */ }

    uasort($out, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
    return array_values($out);
}

/**
 * Les badges d'un client. CALCULÉS, jamais rangés en base.
 *
 * @param array $c     la ligne agrégée
 * @param int   $seuil le chiffre d'affaires à partir duquel on est « en haut »
 * @return array [code => libellé polonais]
 */
function wsm_crm_badges(array $c, int $seuil = 0): array {
    $b = [];
    $payees = (int) $c['paid_orders'];

    if ($payees === 0 && (int) $c['orders'] > 0) $b['nowy'] = 'Bez zapłaconego zamówienia';
    if ($payees === 1)                            $b['pierwszy'] = 'Pierwszy zakup';
    if ($payees >= WSM_CRM_FIDELE_MIN)            $b['staly'] = 'Stały klient';
    // Le libellé dit le FAIT, pas une proportion. « Top 10 % » sur un jeu où
    // quarante clients partagent exactement le même montant s'affiche sur un
    // tiers de la base : la plaquette mentirait, et personne ne le vérifierait.
    if ($seuil > 0 && (int) $c['revenue'] >= $seuil) $b['vip'] = 'Wysoki obrót';
    if (($c['nip'] ?? '') !== '')                 $b['b2b'] = 'Firma (NIP)';
    if ((int) $c['unpaid'] > 0)                   $b['nieoplacone'] = (int) $c['unpaid'] . ' nieopłacone';

    // « Dort » ne s'applique qu'à quelqu'un qui a DÉJÀ acheté : un curieux qui
    // n'a jamais rien payé n'est pas un client perdu, il n'a jamais commencé.
    if ($payees > 0 && ($c['last_at'] ?? '') !== '') {
        $jours = (int) floor((time() - strtotime((string) $c['last_at'])) / 86400);
        if ($jours >= WSM_CRM_DORMANT_JOURS) $b['spiacy'] = 'Cisza od ' . $jours . ' dni';
    }
    return $b;
}

/** Le seuil du haut de panier : le chiffre du client au 10ᵉ centile supérieur. */
function wsm_crm_seuil_vip(array $liste): int {
    $ca = array_values(array_filter(array_map(fn($c) => (int) $c['revenue'], $liste), fn($v) => $v > 0));
    if (count($ca) < 10) return 0;          // trop peu de monde pour parler de « top »
    rsort($ca);
    return (int) $ca[(int) floor(count($ca) * WSM_CRM_VIP_TOP) - 1];
}

/**
 * La fiche complète d'un client : identité, achats, courrier, notes.
 *
 * @return ?array null si l'adresse n'a jamais rien fait chez nous
 */
function wsm_crm_client(PDO $pdo, string $email): ?array {
    wsm_crm_ensure($pdo);
    $email = strtolower(trim($email));
    if ($email === '') return null;

    $liste = wsm_crm_list($pdo);
    $moi = null;
    foreach ($liste as $c) if (strtolower($c['email']) === $email) { $moi = $c; break; }
    if (!$moi) return null;

    $moi['badges'] = wsm_crm_badges($moi, wsm_crm_seuil_vip($liste));
    $moi['basket'] = $moi['paid_orders'] > 0 ? (int) round($moi['revenue'] / $moi['paid_orders']) : 0;

    $st = $pdo->prepare("SELECT id, code, created_at, status, payment_status, total_gross,
                                delivery_method, lang
                           FROM wsm_orders WHERE LOWER(email) = ? ORDER BY id DESC");
    $st->execute([$email]);
    $moi['orders_list'] = $st->fetchAll() ?: [];

    $st2 = $pdo->prepare("SELECT id, direction, subject, status, created_at
                            FROM wsm_messages WHERE LOWER(email) = ? ORDER BY id DESC LIMIT 30");
    $st2->execute([$email]);
    $moi['messages'] = $st2->fetchAll() ?: [];

    try {
        $st3 = $pdo->prepare("SELECT id, number, kind, total_gross, issued_at
                                FROM wsm_invoices WHERE LOWER(buyer_email) = ? ORDER BY id DESC LIMIT 30");
        $st3->execute([$email]);
        $moi['invoices'] = $st3->fetchAll() ?: [];
    } catch (Throwable $e) { $moi['invoices'] = []; }

    $moi['notes'] = wsm_crm_notes($pdo, $email);

    // Ce qu'il achète vraiment : sans ça, « bon client » ne dit pas quoi lui
    // proposer. Quantités, kilos et valeur, produit par produit.
    $moi['top'] = wsm_crm_achats($pdo, $email);

    return $moi;
}

/**
 * Ce qu'une adresse a acheté, produit par produit : pièces, kilos, valeur.
 *
 * On compte les QUANTITÉS, pas les lignes — dix fois un kilo n'est pas la
 * même chose qu'une fois dix kilos. Et les KILOS, parce que l'équipe parle
 * en kilos : « ten kontrahent bierze 100 kg miesięcznie », jamais « 340
 * sztuk ». Seules les commandes payées comptent, comme partout sur l'écran.
 *
 * Regroupé par IDENTIFIANT de produit, pas par nom : un produit renommé en
 * console coupait son historique en deux lignes, et la meilleure vente de la
 * maison passait pour deux ventes moyennes. Le nom affiché est celui de la
 * commande la plus récente — la même règle que pour le nom du client. Une
 * ligne sans identifiant (import ancien) se regroupe par nom, faute de mieux.
 *
 * Rien n'est tronqué : un contractant qui prend trente références veut voir
 * les trente, c'est précisément pour lui que la liste existe.
 *
 * @return array<int, array{product_id:string,name:string,q:int,v:int,g:int}>
 *         triés par valeur décroissante
 */
function wsm_crm_achats(PDO $pdo, string $email): array {
    $email = strtolower(trim($email));
    if ($email === '') return [];
    try {
        $st = $pdo->prepare("SELECT i.product_id, i.name, i.qty, i.line_gross, i.weight_g, p.weight_g AS unit_g
                               FROM wsm_order_items i
                               JOIN wsm_orders o ON o.id = i.order_id
                          LEFT JOIN wsm_products p ON p.id = i.product_id
                              WHERE LOWER(o.email) = ? AND o.status <> 'anulowane'
                                AND o.payment_status = 'oplacone'
                           ORDER BY o.id DESC, i.id DESC");
        $st->execute([$email]);
        $lignes = $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }

    $out = [];
    foreach ($lignes as $l) {
        $pid = trim((string) ($l['product_id'] ?? ''));
        $k = $pid !== '' ? 'p:' . $pid : 'n:' . mb_strtolower(trim((string) $l['name']));
        if (!isset($out[$k])) {
            $out[$k] = ['product_id' => $pid, 'name' => (string) $l['name'], 'q' => 0, 'v' => 0, 'g' => 0];
        }
        $out[$k]['q'] += (int) $l['qty'];
        $out[$k]['v'] += (int) $l['line_gross'];
        $out[$k]['g'] += wsm_crm_ligne_g($l);
    }
    usort($out, fn($a, $b) => $b['v'] <=> $a['v']);
    return array_values($out);
}

/**
 * Le poids d'une ligne de commande, en grammes : celui écrit à la commande
 * (le poids de la ligne, quantité comprise), sinon la fiche produit × la
 * quantité — c'est ce que fait déjà le calcul du compte professionnel.
 */
function wsm_crm_ligne_g(array $l): int {
    $g = (int) ($l['weight_g'] ?? 0);
    if ($g > 0) return $g;
    return (int) ($l['qty'] ?? 0) * (int) ($l['unit_g'] ?? 0);
}

// ---------------------------------------------------------------------------
//  Les notes
// ---------------------------------------------------------------------------

function wsm_crm_notes(PDO $pdo, string $email): array {
    wsm_crm_ensure($pdo);
    try {
        $st = $pdo->prepare("SELECT * FROM wsm_client_notes WHERE LOWER(email) = ?
                              ORDER BY id DESC LIMIT 50");
        $st->execute([strtolower(trim($email))]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * Ajoute une note. Signée et datée — sans auteur, « client difficile » est
 * une rumeur ; avec, c'est une information dont quelqu'un répond.
 *
 * @return array [id|null, erreur]
 */
function wsm_crm_note_add(PDO $pdo, string $email, string $texte, string $actor): array {
    wsm_crm_ensure($pdo);
    $email = strtolower(trim($email));
    $texte = trim($texte);
    if ($email === '')                return [null, 'brak adresu'];
    if ($texte === '')                return [null, 'notatka nie może być pusta'];
    if (mb_strlen($texte) > 2000)     return [null, 'maks. 2000 znaków'];

    $pdo->prepare("INSERT INTO wsm_client_notes (email, note, actor, created_at) VALUES (?,?,?,?)")
        ->execute([$email, $texte, mb_substr($actor, 0, 120), date('Y-m-d H:i:s')]);
    return [(int) $pdo->lastInsertId(), ''];
}

function wsm_crm_note_delete(PDO $pdo, int $id): bool {
    wsm_crm_ensure($pdo);
    try {
        $pdo->prepare("DELETE FROM wsm_client_notes WHERE id = ?")->execute([$id]);
        return true;
    } catch (Throwable $e) { return false; }
}

// ---------------------------------------------------------------------------
//  Les totaux d'en-tête
// ---------------------------------------------------------------------------

/** Ce que l'écran affiche en haut : de quoi juger la base clients d'un coup. */
function wsm_crm_totaux(array $liste): array {
    $t = ['clients' => count($liste), 'acheteurs' => 0, 'fideles' => 0,
          'dormants' => 0, 'b2b' => 0, 'revenue' => 0, 'panier' => 0];
    $cmd = 0;
    foreach ($liste as $c) {
        $t['revenue'] += (int) $c['revenue'];
        $cmd += (int) $c['paid_orders'];
        if ((int) $c['paid_orders'] > 0)                    $t['acheteurs']++;
        if ((int) $c['paid_orders'] >= WSM_CRM_FIDELE_MIN)  $t['fideles']++;
        if (($c['nip'] ?? '') !== '')                       $t['b2b']++;
        if ((int) $c['paid_orders'] > 0 && ($c['last_at'] ?? '') !== ''
            && (time() - strtotime((string) $c['last_at'])) / 86400 >= WSM_CRM_DORMANT_JOURS) {
            $t['dormants']++;
        }
    }
    $t['panier'] = $cmd > 0 ? (int) round($t['revenue'] / $cmd) : 0;
    return $t;
}

/**
 * Filtre la liste. Le filtre est appliqué APRÈS l'agrégation, sur des données
 * déjà en mémoire : la requête ne tourne qu'une fois quoi qu'on demande.
 */
function wsm_crm_filtre(array $liste, string $q = '', string $seg = '', int $seuil = 0): array {
    $q = mb_strtolower(trim($q));
    $out = [];
    foreach ($liste as $c) {
        if ($q !== '') {
            $foin = mb_strtolower($c['email'] . ' ' . $c['name'] . ' ' . $c['company'] . ' ' . $c['nip']);
            if (!str_contains($foin, $q)) continue;
        }
        if ($seg !== '' && !isset(wsm_crm_badges($c, $seuil)[$seg])) continue;
        $out[] = $c;
    }
    return $out;
}

// ============================================================================
//  ANALYSE ET ALERTES (§7)
//
//  UNE ALERTE EST UNE CHOSE À FAIRE, PAS UNE STATISTIQUE. « 47 clients » n'est
//  pas une alerte ; « Anna Nowak achetait tous les mois et n'a rien commandé
//  depuis 140 jours » en est une, parce qu'on peut décrocher le téléphone.
//
//  Trois règles :
//
//   1. CHAQUE ALERTE PORTE UN NOM ET UN GESTE. Sans le nom du client, elle
//      oblige à chercher ; sans le geste, elle culpabilise sans servir.
//   2. AUCUN SCORE INVENTÉ. Pas de « probabilité de départ à 73 % ». Les
//      chiffres sont ceux qui se vérifient : combien d'achats, quand, combien.
//   3. UNE ALERTE QUI NE S'ÉTEINT JAMAIS EST DU BRUIT. Chacune a une
//      condition de sortie évidente — le client recommande, ou paie.
// ============================================================================

/** Un client qui achetait régulièrement et s'est tu : la perte la plus chère. */
const WSM_CRM_DECROCHE_JOURS = 90;

/** Fenêtre pendant laquelle un premier acheteur se transforme — ou pas. */
const WSM_CRM_SECOND_ACHAT_JOURS = 60;

/**
 * Ce qui attend quelqu'un, du plus coûteux au moins urgent.
 *
 * @return array [['type','severite','email','nom','texte','geste','href'], …]
 */
function wsm_crm_alerts(PDO $pdo, int $max = 40): array {
    $liste = wsm_crm_list($pdo);
    $seuil = wsm_crm_seuil_vip($liste);
    $out = [];

    foreach ($liste as $c) {
        $qui = $c['name'] !== '' ? $c['name'] : $c['email'];
        $lien = 'klienci.php?email=' . rawurlencode($c['email']);
        $jours = ($c['last_at'] ?? '') !== ''
            ? (int) floor((time() - strtotime((string) $c['last_at'])) / 86400) : 0;

        // 1. Un habitué qui décroche. C'est l'alerte qui vaut le plus : il a
        //    prouvé qu'il achète, et il s'arrête. On perd un client acquis.
        if ((int) $c['paid_orders'] >= WSM_CRM_FIDELE_MIN && $jours >= WSM_CRM_DECROCHE_JOURS) {
            $out[] = [
                'type' => 'decroche', 'severite' => 3, 'email' => $c['email'], 'nom' => $qui,
                'texte' => 'Kupował ' . (int) $c['paid_orders'] . ' razy (' . pln_crm((int) $c['revenue'])
                         . '), cisza od ' . $jours . ' dni.',
                'geste' => 'Napisz — zapytaj, czy czegoś zabrakło.',
                'href' => $lien,
            ];
        }

        // 2. Un client qui pèse et qui laisse des impayés. L'ordre compte :
        //    relancer un gros compte n'est pas la même conversation.
        if ((int) $c['unpaid'] > 0 && $seuil > 0 && (int) $c['revenue'] >= $seuil) {
            $out[] = [
                'type' => 'nieoplacone_duzy', 'severite' => 3, 'email' => $c['email'], 'nom' => $qui,
                'texte' => (int) $c['unpaid'] . ' nieopłaconych zamówień u klienta z obrotem '
                         . pln_crm((int) $c['revenue']) . '.',
                'geste' => 'Sprawdź zamówienia — może czeka na fakturę proforma.',
                'href' => $lien,
            ];
        }

        // 3. Un premier acheteur qui n'est pas revenu, dans la fenêtre où il
        //    peut encore le faire. Passé ce délai, il ne reviendra pas parce
        //    qu'on a écrit — il faudra une vraie raison.
        if ((int) $c['paid_orders'] === 1 && $jours >= 21 && $jours <= WSM_CRM_SECOND_ACHAT_JOURS) {
            $out[] = [
                'type' => 'drugi_zakup', 'severite' => 1, 'email' => $c['email'], 'nom' => $qui,
                'texte' => 'Kupił raz ' . $jours . ' dni temu i nie wrócił.',
                'geste' => 'Dobry moment na jedną wiadomość — potem będzie za późno.',
                'href' => $lien,
            ];
        }
    }

    // 4. Le risque de concentration : si un seul client fait le quart du
    //    chiffre, son départ n'est pas un incident, c'est une année.
    $conc = wsm_crm_concentration($liste, 1);
    if ($conc['part'] >= 25.0 && $conc['top'] !== []) {
        $p = $conc['top'][0];
        $out[] = [
            'type' => 'koncentracja', 'severite' => 2,
            'email' => $p['email'], 'nom' => $p['name'] !== '' ? $p['name'] : $p['email'],
            'texte' => 'Jeden klient to ' . number_format($conc['part'], 1, ',', ' ')
                     . ' % obrotu. Jego odejście nie byłoby incydentem, tylko rokiem.',
            'geste' => 'Warto wiedzieć — nie ma tu nic do naprawienia dziś.',
            'href' => 'klienci.php?email=' . rawurlencode($p['email']),
        ];
    }

    usort($out, fn($a, $b) => [$b['severite'], $b['type']] <=> [$a['severite'], $a['type']]);
    return array_slice($out, 0, $max);
}

/** Le formatage monétaire, local pour ne pas dépendre de la console. */
function pln_crm(int $g): string {
    return number_format($g / 100, 2, ',', "\u{202F}") . "\u{202F}zł";
}

/**
 * Quelle part du chiffre d'affaires tient à combien de clients.
 *
 * @return array ['part' => % du CA fait par les $n premiers, 'top' => [clients]]
 */
function wsm_crm_concentration(array $liste, int $n = 5): array {
    $total = array_sum(array_map(fn($c) => (int) $c['revenue'], $liste));
    if ($total <= 0) return ['part' => 0.0, 'top' => [], 'total' => 0];
    $tri = $liste;
    usort($tri, fn($a, $b) => (int) $b['revenue'] <=> (int) $a['revenue']);
    $top = array_slice($tri, 0, max(1, $n));
    $somme = array_sum(array_map(fn($c) => (int) $c['revenue'], $top));
    return ['part' => round($somme / $total * 100, 1), 'top' => $top, 'total' => $total];
}

/**
 * Rétention par cohorte : sur les clients dont le PREMIER achat est de tel
 * mois, combien ont racheté depuis.
 *
 * C'est la seule mesure qui dit si la boutique fidélise ou si elle recommence
 * chaque mois à zéro — un chiffre d'affaires en hausse porté uniquement par
 * des nouveaux venus est une fuite, pas une croissance.
 *
 * @return array [['ym','clients','revenus','pct'], …] du plus ancien au récent
 */
function wsm_crm_cohorts(PDO $pdo, int $months = 12): array {
    $liste = wsm_crm_list($pdo);
    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("first day of -$i month"));
        $out[$ym] = ['ym' => $ym, 'label' => date('m/y', strtotime($ym . '-01')),
                     'clients' => 0, 'revenus' => 0, 'pct' => 0.0];
    }
    foreach ($liste as $c) {
        if ((int) $c['paid_orders'] < 1 || ($c['first_at'] ?? '') === '') continue;
        $ym = substr((string) $c['first_at'], 0, 7);
        if (!isset($out[$ym])) continue;
        $out[$ym]['clients']++;
        if ((int) $c['paid_orders'] >= 2) $out[$ym]['revenus']++;
    }
    foreach ($out as $ym => $r) {
        $out[$ym]['pct'] = $r['clients'] > 0 ? round($r['revenus'] / $r['clients'] * 100, 1) : 0.0;
    }
    return array_values($out);
}

/**
 * Le chiffre d'affaires mois par mois, séparé entre NOUVEAUX clients et
 * clients qui reviennent.
 *
 * Deux boutiques peuvent afficher la même courbe de ventes : l'une garde ses
 * clients, l'autre les remplace. Ce n'est pas la même affaire, et ça ne se
 * voit sur aucun graphique de chiffre d'affaires.
 *
 * @return array [['label','ym','nouveau','fidele'], …]
 */
function wsm_crm_new_vs_returning(PDO $pdo, int $months = 12): array {
    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $t = strtotime("first day of -$i month");
        $out[date('Y-m', $t)] = ['ym' => date('Y-m', $t), 'label' => date('m/y', $t),
                                 'nouveau' => 0, 'fidele' => 0];
    }

    $cols = wsm_table_columns($pdo, 'wsm_orders');
    $quand = in_array('paid_at', $cols, true)
        ? 'COALESCE(' . wsm_sql_date($pdo, 'paid_at') . ', created_at)' : "created_at";
    $rows = $pdo->query("SELECT LOWER(email) AS em, total_gross, $quand AS q
                           FROM wsm_orders
                          WHERE status <> 'anulowane' AND payment_status = 'oplacone'
                            AND email <> '' ORDER BY q")->fetchAll() ?: [];

    $vus = [];
    foreach ($rows as $r) {
        $ym = substr((string) $r['q'], 0, 7);
        $em = (string) $r['em'];
        $premier = !isset($vus[$em]);
        $vus[$em] = true;
        if (!isset($out[$ym])) continue;
        $out[$ym][$premier ? 'nouveau' : 'fidele'] += (int) $r['total_gross'];
    }
    return array_values($out);
}
