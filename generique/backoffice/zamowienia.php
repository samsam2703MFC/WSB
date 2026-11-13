<?php
// ============================================================================
//  zamowienia.php — écran Commandes de la console marque.
//
//  Volontairement séparé du fichier exporté par Claude Design (193 Ko générés,
//  qu'un patch à la main rendrait irrécupérables au prochain export). C'est une
//  page PHP autonome, rendue côté serveur, qui partage TOUT le reste avec la
//  console : la même session, les mêmes rôles, les mêmes jetons de marque.
//
//  Lecture : tout compte actif. Écriture (statut, étiquette InPost) : Centrala.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/stripe.php';
require_once $API . '/inpost.php';
require_once $API . '/mail.php';
require_once $API . '/stock.php';
// wsm_invoice_kind_for() : l'écran annonce QUEL document partira au passage à
// « wysłane ». Sans ce require, le function_exists() plus bas répondrait non et
// la ligne disparaîtrait en silence — le pire des deux mondes.
require_once $API . '/invoice.php';

$flash = ''; $flashKind = 'ok';

// ─── CSRF ────────────────────────────────────────────────────────────────
//
// CET ÉCRAN N'EN AVAIT AUCUN. Il change l'état d'une commande — et depuis
// aujourd'hui ce changement ÉMET UN DOCUMENT FISCAL, l'envoie au client et le
// dépose au registre national. Une image distante pointant sur un POST
// suffisait à faire expédier et facturer une commande à l'insu de la personne
// connectée. Les autres écrans (Ustawienia, Superadmin, Kraje) portaient déjà
// ce jeton ; celui-ci était passé au travers.
$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$statusLabel = ['nowe' => 'Nowe', 'oplacone' => 'Opłacone', 'w_realizacji' => 'W realizacji',
                'wyslane' => 'Wysłane', 'dostarczone' => 'Dostarczone', 'anulowane' => 'Anulowane'];
$payLabel = ['oczekuje' => 'Oczekuje', 'oplacone' => 'Opłacone', 'nieudane' => 'Nieudana',
             'niedostepne' => 'Niedostępna'];

// ---- Actions (réservées à Centrala) ---------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    // Lu AVANT le contrôle de rôle : la redirection qui suit s'en sert pour
    // ramener sur la bonne ligne, y compris quand l'action a été refusée.
    $id = (int) ($_POST['id'] ?? 0);
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać zamówienia.'; $flashKind = 'err';
    } elseif (isset($_POST['hurt'])) {
        // ─── L'EXPÉDITION PAR LOT ────────────────────────────────────
        //
        // Vingt colis à sortir, c'était vingt allers-retours. On coche, on
        // pousse une fois. Chaque commande passe par LA MÊME porte que le
        // bouton d'une ligne — wsm_order_status_set() — donc chacune reçoit
        // le document qui lui revient, son mail et son dépôt au registre.
        //
        // UNE COMMANDE BLOQUÉE NE PART PAS AVEC LE LOT. Sans les données
        // du vendeur la facture ne peut pas naître : elle serait expédiée
        // sans document, et on ne le verrait qu'au dépouillement. Le
        // contrôle est refait ICI, côté serveur — la case décochée dans la
        // page ne prouve rien.
        $ids = array_slice(array_map('intval', (array) ($_POST['ids'] ?? [])), 0, 100);
        $fait = 0; $refus = []; $docs = [];
        foreach ($ids as $oid) {
            $cmd = $oid > 0 ? wsm_order_by_id($pdo, $oid) : null;
            if (!$cmd) continue;
            $pf = wsm_order_preflight($pdo, $cmd);
            if (!$pf['gotowe']) { $refus[] = $cmd['code'] . ' (' . implode(', ', $pf['blok']) . ')'; continue; }
            $chg = wsm_order_status_set($pdo, $oid, 'wyslane', (string) ($me['nom'] ?? ''));
            wsm_order_event($pdo, $oid, 'status', 'wyslane', (string) ($me['nom'] ?? ''));
            wsm_mail_for_status($pdo, wsm_order_by_id($pdo, $oid) ?: $cmd, 'wyslane', (string) ($me['nom'] ?? ''));
            $fait++;
            if (($chg['doc'] ?? null)) $docs[] = (string) $chg['doc']['number'];
        }
        $flash = $fait . ' ' . ($fait === 1 ? 'zamówienie wysłane' : 'zamówień wysłanych')
               . ($docs ? ' · dokumenty: ' . implode(', ', array_slice($docs, 0, 6))
                          . (count($docs) > 6 ? ' …' : '') : '');
        if ($refus) {
            $flash .= ' · POMINIĘTE: ' . implode(' · ', array_slice($refus, 0, 4));
            $flashKind = 'err';
        }
        if (!$fait && !$refus) { $flash = 'Nic nie zaznaczono.'; $flashKind = 'err'; }

    } else {
        $order = wsm_order_by_id($pdo, $id);
        if (!$order) {
            $flash = 'Nie znaleziono zamówienia.'; $flashKind = 'err';
        } elseif (isset($_POST['vies'])) {
            // Reconsulter MAINTENANT. Le contrôle qui compte est celui du jour
            // de la livraison ; on veut pouvoir le refaire avant d'expédier,
            // sans attendre le passage automatique.
            $order = wsm_order_vies_refresh($pdo, $order);
            $vs = strtolower((string) ($order['vat']['status'] ?? ''));
            $flash = $order['code'] . ' · VIES: ' . ($vs !== '' ? $vs : 'brak odpowiedzi')
                   . ' — przy „Wysłane" powstanie: ' . wsm_invoice_kind_for($order)['kind'] . '.';
            $flashKind = $vs === 'invalid' ? 'err' : 'ok';

        } elseif (isset($_POST['oplac'])) {
            // L'ENCAISSEMENT À LA MAIN, pour les virements. Il ne passe PAS
            // par le chemin du colis : marquer payée une commande déjà en
            // préparation ne doit pas la ramener en arrière. wsm_order_mark_paid()
            // écrit les deux champs et la date, comme le fait le paiement Stripe.
            if ((string) $order['payment_status'] === 'oplacone') {
                $flash = $order['code'] . ' była już opłacona.';
            } else {
                wsm_order_mark_paid($pdo, $id, (string) ($me['nom'] ?? '') ?: 'ręcznie');
                $fresh = wsm_order_by_id($pdo, $id) ?: $order;
                $flash = $order['code'] . ' · zapłata odnotowana ręcznie — '
                       . ($statusLabel[$fresh['status']] ?? $fresh['status']);
            }

        } elseif (isset($_POST['ksef'])) {
            $doc = wsm_invoice_for_order($pdo, $id);
            if (!$doc) { $flash = 'Nie ma jeszcze dokumentu.'; $flashKind = 'err'; }
            else {
                require_once $API . '/ksef.php';
                [$num, $err] = wsm_ksef_wyslij($pdo, wsm_invoice_hydrate($pdo, $doc),
                                               (string) ($me['nom'] ?? ''));
                $flash = $num ? 'Zgłoszono do KSeF: ' . $num : 'KSeF: ' . $err;
                $flashKind = $num ? 'ok' : 'err';
            }

        } elseif (isset($_POST['fiskal'])) {
            // LA CAISSE À LA MAIN : un paragon qui n'est pas passé (canal
            // branché après coup, Fakturownia muette, impression refusée).
            // C'est le seul endroit qui relance une IMPRESSION — la ligne
            // « Kasa » a demandé de vérifier l'imprimante avant ce clic.
            $doc = wsm_invoice_for_order($pdo, $id);
            if (!$doc || (string) $doc['kind'] !== 'paragon') { $flash = 'Nie ma paragonu do wysłania.'; $flashKind = 'err'; }
            else {
                require_once $API . '/fiskal.php';
                [$etat, $phrase] = wsm_fiskal_wyslij($pdo, $doc, $order, (string) ($me['nom'] ?? ''), true);
                $flash = $order['code'] . ' · kasa: ' . $phrase;
                $flashKind = $etat === 'blad' || $etat === '' ? 'err' : 'ok';
            }

        } elseif (isset($_POST['status'])) {
            // UNE SEULE PORTE. « do_wysylki » et « wyslane » étaient deux
            // branches de plus, ajoutées pour les deux boutons d'expédition de
            // la liste. Ces boutons sont devenus des étapes, qui postent un
            // statut comme la fiche : les branches ne servaient plus rien, et
            // un chemin que personne n'emprunte est un chemin que personne ne
            // corrige — celui qui n'émettra pas de document au prochain
            // changement de règle.
            $new = (string) $_POST['status'];
            if (!in_array($new, WSM_ORDER_STATUSES, true)) {
                $flash = 'Nieznany status.'; $flashKind = 'err';
            } elseif ($new === (string) $order['status']) {
                $flash = 'Zamówienie już ma ten status — nic nie zmieniono.';
            } else {
                // Le point unique : passer à « wysłane » émet la facture ou
                // l'e-paragon, l'envoie et le dépose au KSeF.
                $chg = wsm_order_status_set($pdo, (int) $id, $new, (string) ($me['nom'] ?? ''));
                wsm_order_event($pdo, $id, 'status', $new, (string) ($me['nom'] ?? ''));
                $flash = $order['code'] . ' → ' . ($statusLabel[$new] ?? $new);
                // CE QUI VIENT D'ÊTRE ÉMIS, DIT TOUT DE SUITE. Un document part
                // au client et au registre national sur ce clic : le passer sous
                // silence, c'est le découvrir un mois plus tard dans Faktury.
                if (($chg['note'] ?? '') !== '') $flash .= ' · ' . $chg['note'];

                // Le client apprend le changement s'il y a un modèle pour cet
                // état ET si l'opérateur ne l'a pas décoché. Un changement de
                // rangement interne ne mérite pas toujours un e-mail.
                if (!empty($_POST['powiadom'])) {
                    $fresh = wsm_order_by_id($pdo, $id) ?: $order;
                    $mid = wsm_mail_for_status($pdo, $fresh, $new, (string) ($me['nom'] ?? ''));
                    if ($mid > 0) {
                        $flash .= ' · powiadomiono ' . $fresh['email'];
                    } elseif (in_array($new, WSM_MAIL_STATUS_EVENTS, true)) {
                        // Distinguer « déjà envoyé » de « pas de modèle » évite
                        // de chercher une panne là où il n'y en a pas.
                        $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_messages WHERE event_key = ?");
                        $st->execute(['status:' . $id . ':' . $new]);
                        $flash .= (int) $st->fetchColumn() > 0
                            ? ' · maila o tym statusie już wcześniej wysłano'
                            : ' · bez maila (brak szablonu, adresu lub poczta wyłączona)';
                    }
                }
            }
        } elseif (isset($_POST['wz'])) {
            [$d, $err] = wsm_stock_issue_wz($pdo, $order, (string) ($me['nom'] ?? ''));
            if ($err !== null) { $flash = $err; $flashKind = 'err'; }
            else $flash = 'Wystawiono ' . $d['number'] . ' — dokument wydania.';
        } elseif (isset($_POST['ship'])) {
            [$sh, $err] = wsm_inpost_create($pdo, $order);
            if ($err !== null) { $flash = 'InPost: ' . $err; $flashKind = 'err'; }
            else { $flash = 'Utworzono przesyłkę ' . ($sh['tracking_number'] ?? ''); }
        }
    }

    // ─── ON RÉPOND PAR UNE REDIRECTION, ET ON REVIENT SUR LA LIGNE ────────
    //
    // Cet écran répondait au POST en réaffichant la page. Deux conséquences,
    // les deux payées par la personne qui s'en sert toute la journée :
    //
    //  · Rafraîchir la page rejouait l'action. Sur un écran qui émet des
    //    documents fiscaux, « voulez-vous renvoyer le formulaire ? » est une
    //    question qu'on ne devrait jamais avoir à se poser.
    //  · On repartait EN HAUT d'une liste de deux cents commandes. Un geste
    //    au milieu de la liste, et il faut re-dérouler jusqu'à sa place —
    //    quarante fois par jour, au téléphone, une main prise par un colis.
    //
    // L'ancre ramène à la ligne touchée, et le message survit dans la
    // session le temps d'un aller-retour.
    $_SESSION['zam_flash'] = [$flash, $flashKind];
    // La file survit à l'action : sans elle, chaque geste renverrait sur la
    // liste complète et il faudrait retrouver son tas à chaque colis.
    $q = [];
    if (isset($_GET['id']))      $q['id'] = (int) $_GET['id'];
    if (isset($_GET['kolejka'])) $q['kolejka'] = (string) $_GET['kolejka'];
    // La vue survit elle aussi : un geste depuis la tablica qui renverrait
    // sur la liste ferait perdre sa place à chaque colis, exactement comme
    // la file avant elle.
    if (isset($_GET['widok']))   $q['widok'] = (string) $_GET['widok'];
    $vers = 'zamowienia.php'
          . ($q ? '?' . http_build_query($q) : '')
          . ($id > 0 ? '#z' . $id : '');
    header('Location: ' . $vers, true, 303);
    exit;
}
if (isset($_SESSION['zam_flash'])) {
    [$flash, $flashKind] = $_SESSION['zam_flash'];
    unset($_SESSION['zam_flash']);
}

$detail = isset($_GET['id']) ? wsm_order_by_id($pdo, (int) $_GET['id']) : null;

// Vues imprimables : la feuille de préparation et l'étiquette. Sorties avant
// tout rendu de la console — ce sont des documents, pas des écrans.
if ($detail && isset($_GET['druk']))     { $o = $detail; include __DIR__ . '/zamowienie_druk.php'; exit; }
if ($detail && isset($_GET['etykieta'])) { $o = $detail; include __DIR__ . '/etykieta_druk.php'; exit; }
// ─── LES FILES DE TRAVAIL ────────────────────────────────────────────────
//
// Deux mille commandes dans une seule liste, c'est une archive, pas un plan de
// travail. Les gestes de la journée se rangent en trois tas : ce qu'il faut
// préparer, ce qu'il faut sortir, et ce qui est parti. On ouvre le sien.
//
// La file « wysyłka » est celle où l'on agit par lot : toutes ses commandes
// attendent exactement le même geste.
$KOLEJKI = [
    // Noms COURTS : « Do przygotowania » tombait sur trois lignes dans un
    // onglet de 88 px, et trois lignes de titre au-dessus d'un chiffre, ça ne
    // se lit plus d'un coup d'œil.
    'przygotowanie' => ['Przygotowanie', ['nowe', 'oplacone']],
    'wysylka'       => ['Do wysłania',      ['w_realizacji']],
    // LA FILE QUI RÉPOND À « QU'EST-CE QUI TRAÎNE ». Ce n'est pas un statut :
    // c'est une date dépassée. Elle croise donc les trois états où un colis
    // peut encore partir — payé, en préparation — avec l'échéance promise.
    // Sans elle, trouver les retards demandait de lire 200 lignes une par une,
    // c'est-à-dire de ne jamais les chercher.
    'termin'        => ['Po terminie',      ['nowe', 'oplacone', 'w_realizacji']],
    'wyslane'       => ['Wysłane',          ['wyslane', 'dostarczone']],
    'wszystkie'     => ['Wszystkie',        []],
];
// Le seuil : payée avant cette heure-là et pas encore partie = en retard. Il
// se calcule sur l'horloge de la BASE, la même qui a écrit paid_at.
$seuilTermin = date('Y-m-d H:i:s', wsm_db_now($pdo) - wsm_ship_delai_h() * 3600);
$kolejka = (string) ($_GET['kolejka'] ?? 'wszystkie');
if (!isset($KOLEJKI[$kolejka])) $kolejka = 'wszystkie';
$hurtowa = $kolejka === 'wysylka';        // la seule où le lot a un sens

// La condition « en retard », écrite UNE fois : le compteur de l'onglet et la
// liste qu'il ouvre doivent porter sur exactement le même ensemble. Un onglet
// qui annonce 7 et ouvre 5 lignes fait chercher les deux qui manquent.
$ouTermin = ' AND ' . wsm_sql_avec_date($pdo, 'paid_at') . ' AND paid_at < ?'
          . ' AND ' . wsm_sql_sans_date($pdo, 'shipped_at');

$licznik = [];
foreach ($KOLEJKI as $k => [$nazwa, $sts]) {
    if ($k === 'termin') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_orders
                              WHERE status IN ('" . implode("','", $sts) . "')" . $ouTermin);
        $st->execute([$seuilTermin]);
        $licznik[$k] = (int) $st->fetchColumn();
        continue;
    }
    $licznik[$k] = $sts
        ? (int) $pdo->query("SELECT COUNT(*) FROM wsm_orders WHERE status IN ('" . implode("','", $sts) . "')")->fetchColumn()
        : (int) $pdo->query("SELECT COUNT(*) FROM wsm_orders")->fetchColumn();
}

// ─── DEUX VUES POUR UN MÊME TRAVAIL ─────────────────────────────────────────
//
// Elles ne répondent pas à la même question, et c'est pour ça qu'elles
// coexistent au lieu de se remplacer :
//
//  · LISTA — une commande par ligne, 46 px. Pour chercher, comparer des
//    montants, trier, cocher un lot. C'est la vue de bureau.
//  · TABLICA — trois colonnes, une par étape. La POSITION dit l'état, donc
//    plus rien ne le répète, et l'on voit LA CHARGE : trois à préparer, une
//    coincée à l'expédition. Aucune liste ne montre ça. C'est la vue de
//    l'atelier — au prix du tri et de la recherche, qu'elle n'a pas.
//
// Le même calcul nourrit les deux. Un seul wsm_order_preflight() et un seul
// wsm_order_etapy() par commande, dans $dane() : deux vues qui calculeraient
// chacune de leur côté finiraient par ne plus dire la même chose du même
// colis, et c'est exactement le bug qu'on vient de payer sur cette page.
$WIDOKI = ['lista' => 'Lista', 'tablica' => 'Tablica'];
$widok = (string) ($_GET['widok'] ?? 'lista');
if (!isset($WIDOKI[$widok])) $widok = 'lista';

// La tablica montre les trois files À LA FOIS : la file choisie n'a plus de
// sens pour elle, et le lot non plus — on ne coche pas dans un tableau dont
// les colonnes sont déjà le tri.
$TABL = ['przygotowanie', 'wysylka', 'wyslane'];
if ($widok === 'tablica') $hurtowa = false;

// LE RETARD SE FILTRE EN SQL, PAS APRÈS COUP. Filtrer les 200 dernières
// commandes en PHP montrerait les retards RÉCENTS et cacherait les vieux —
// exactement ceux qu'on cherche. Ce sont les plus anciens qui remontent en
// premier ici, à l'inverse de tout le reste de l'écran : un retard de trois
// jours passe avant un retard de deux heures.
$orders = $widok === 'tablica'
    ? wsm_orders_list($pdo, 200, array_merge(...array_map(fn($k) => $KOLEJKI[$k][1], $TABL)))
    : ($kolejka === 'termin'
        ? wsm_orders_po_terminie($pdo, 200, $KOLEJKI['termin'][1], $seuilTermin)
        : wsm_orders_list($pdo, 200, $KOLEJKI[$kolejka][1]));

$kubelki = [];
if ($widok === 'tablica') {
    foreach ($TABL as $k) $kubelki[$k] = [];
    foreach ($orders as $o) {
        foreach ($TABL as $k) {
            if (in_array($o['status'], $KOLEJKI[$k][1], true)) { $kubelki[$k][] = $o; break; }
        }
    }
}

/**
 * Tout ce qu'une ligne — ou une carte — doit savoir, calculé UNE fois.
 *
 * Le verdict se lit sur $pf['blok'], pas sur un comptage refait ici : c'est
 * la même liste qui décide si la case du lot est cochable et si le serveur
 * acceptera l'envoi. Un deuxième comptage, écrit à côté, aurait dérivé au
 * premier point ajouté au contrôle — et l'écran aurait annoncé « gotowe »
 * sur une commande que l'envoi refuse.
 */
// L'HEURE DE LA BASE, LUE UNE FOIS. paid_at et shipped_at sont écrits par
// CURRENT_TIMESTAMP : c'est l'horloge du serveur de base qui les pose. Les
// comparer à celle de PHP, c'est comparer deux pendules que rien n'oblige à
// être d'accord — et un décalage d'une heure afficherait « po terminie » sur
// des commandes payées il y a dix minutes. Une requête, pas deux cents.
$teraz = wsm_db_now($pdo);

$dane = function (array $o) use ($pdo, $teraz): array {
    $pf    = wsm_order_preflight($pdo, $o);
    $etapy = wsm_order_etapy($pdo, $o);
    // « wyjdzie z uwagą » ne dit rien : on garde le texte du PREMIER
    // avertissement, qui lui dit quelque chose — « brak wpłaty », et non un
    // adjectif. Une alerte qu'on ne peut pas lire est une alerte qu'on cesse
    // de lire, et elle apparaît sur presque chaque commande.
    $uwagi = 0; $uwagaTxt = '';
    foreach ($pf['lignes'] as $lg) {
        if (!in_array($lg['etat'], ['wa', 'brak'], true)) continue;
        $uwagi++;
        if ($uwagaTxt === '') $uwagaTxt = (string) $lg['val'];
    }
    // Le compte à rebours de la promesse « wysyłka w 24 h ». Calculé ici avec
    // le reste : deux vues qui le calculeraient chacune de leur côté
    // finiraient par ne pas dire la même heure du même colis.
    $termin = wsm_order_termin($o, $teraz);
    return [
        'uwagaTxt' => $uwagaTxt,
        'pf'     => $pf,
        'termin' => $termin,
        'tEt'    => wsm_termin_etykieta($termin),
        'teraz'  => current(array_filter($etapy, fn($e) => $e['etat'] === 'teraz')) ?: null,
        'suiv'   => current(array_filter($etapy, fn($e) => $e['etat'] === 'nastepny')) ?: null,
        'autres' => array_values(array_filter($etapy,
                        fn($e) => !in_array($e['etat'], ['teraz', 'nastepny'], true))),
        'blok'   => count($pf['blok']),
        'uwagi'  => $uwagi,
    ];
};

/** L'adresse de cet écran, en gardant vue et file. */
$lien = function (array $zmiana = []) use ($kolejka, $widok): string {
    $q = array_merge(['kolejka' => $kolejka, 'widok' => $widok], $zmiana);
    return '?' . http_build_query($q);
};

$kpis   = wsm_shop_kpis($pdo);
$cfg    = ['stripe' => wsm_stripe_ready(), 'inpost' => wsm_inpost_enabled()];


console_head('Zamówienia', $me, '', $kpis['orders_pending'] ? $kpis['orders_pending'] . ' czeka na płatność' : '');
console_flash($flash, $flashKind);
console_crumbs($detail
    ? ['Pulpit' => 'pulpit.php', 'Zamówienia' => 'zamowienia.php', $detail['code'] => null]
    : ['Pulpit' => 'pulpit.php', 'Zamówienia' => null]);
?>
  <?php if (!$cfg['stripe'] || !$cfg['inpost']): ?>
  <p class="warnbox">
    <?php if (!$cfg['stripe']): ?>Stripe nie przyjmuje jeszcze płatności — zamówienia powstają, ale nie da się ich opłacić online (ekran <a href="platnosci.php">Płatności</a>).<?php endif; ?>
    <?php if (!$cfg['stripe'] && !$cfg['inpost']): ?><br><?php endif; ?>
    <?php if (!$cfg['inpost']): ?>InPost ShipX nie jest skonfigurowany — etykiet nie można utworzyć automatycznie.<?php endif; ?>
  </p>
  <?php endif; ?>

  <div class="kpis">
    <div class="kpi"><b><?= (int) $kpis['orders'] ?></b><span>Zamówienia</span></div>
    <div class="kpi"><b><?= (int) $kpis['orders_paid'] ?></b><span>Opłacone</span></div>
    <div class="kpi"><b><?= (int) $kpis['orders_pending'] ?></b><span>Oczekuje płatności</span></div>
    <div class="kpi"><b><?= h(pln((int) $kpis['revenue_gross'])) ?></b><span>Obrót brutto</span></div>
    <div class="kpi"><b><?= h(pln((int) $kpis['basket_avg'])) ?></b><span>Średni koszyk</span></div>
  </div>

<?php if ($detail): $o = $detail;
  $st = $pdo->prepare("SELECT event, detail, actor, created_at FROM wsm_order_events WHERE order_id = ? ORDER BY id");
  $st->execute([(int) $o['id']]);
  $events = $st->fetchAll();
  $blockers = wsm_inpost_blockers($o);
  // Le WZ est cherché ici, pas dans le bloc réservé à Centrala : un préparateur
  // doit pouvoir IMPRIMER le bon de sortie même s'il n'a pas le droit de le
  // créer. Lire n'est pas écrire.
  $wzq = $pdo->prepare("SELECT id, number FROM wsm_stock_docs WHERE kind='WZ' AND order_id = ?");
  $wzq->execute([(int) $o['id']]);
  $wzRow = $wzq->fetch() ?: null; ?>
  <div class="panel">
    <h2><?= h($o['code']) ?> · <?= h(pln($o['total_gross'])) ?></h2>
    <div class="cols">
      <dl class="kv">
        <dt>Klient</dt><dd><?= h(trim($o['first_name'] . ' ' . $o['last_name'])) ?><?= $o['company'] !== '' ? ' · ' . h($o['company']) : '' ?></dd>
        <dt>E-mail</dt><dd><?= h($o['email']) ?></dd>
        <dt>Telefon</dt><dd><?= h($o['phone']) ?></dd>
        <?php if ($o['invoice']): ?><dt>Faktura</dt><dd>NIP <?= h($o['nip']) ?><br><?= h($o['bill']['street'] . ' ' . $o['bill']['building']) ?>, <?= h($o['bill']['postcode'] . ' ' . $o['bill']['city']) ?></dd><?php endif; ?>
        <?php
        // ─── VIES : L'ÉTAT DU NUMÉRO, ET CE QU'IL ENTRAÎNE ────────────────
        //
        // Sans cette ligne, on ne savait pas — avant l'expédition — si la
        // commande partirait avec une facture ou un e-paragon. On le
        // découvrait après, dans Faktury. Or c'est AVANT que ça se corrige :
        // un numéro refusé se discute avec le client pendant qu'on prépare le
        // colis, pas une fois le document déposé au registre national.
        //
        // Le numéro de CONSULTATION est affiché parce que c'est lui la preuve
        // en contrôle fiscal — pas la date, pas le nom.
        $vs = strtolower((string) ($o['vat_status'] ?? ($o['vat']['status'] ?? '')));
        $ve = trim((string) ($o['vat_eu'] ?? ''));
        if ($ve !== '' || $vs !== ''):
          $et = ['valid' => ['ok',  'VIES: potwierdzony'],
                 'invalid' => ['no', 'VIES: ODRZUCONY — będzie paragon'],
                 'unavailable' => ['', 'VIES: niedostępny'],
                 'skipped' => ['',    'VIES: niesprawdzony']][$vs] ?? ['', 'VIES: niesprawdzony'];
          $doc = function_exists('wsm_invoice_kind_for') ? wsm_invoice_kind_for($o) : null; ?>
        <dt>VIES</dt>
        <dd><span class="tag <?= h($et[0]) ?>"><?= h($et[1]) ?></span>
          <?php if ($ve !== ''): ?><br><span class="code"><?= h($ve) ?></span><?php endif; ?>
          <?php if (($o['vat']['checked_at'] ?? $o['vat_checked_at'] ?? '') !== ''): ?>
            <br><small class="muted">sprawdzono <?= h((string) ($o['vat']['checked_at'] ?? $o['vat_checked_at'])) ?></small>
          <?php endif; ?>
          <?php if (($o['vat']['consultation'] ?? $o['vat_consultation'] ?? '') !== ''): ?>
            <br><small class="muted">nr konsultacji <span class="code"><?= h((string) ($o['vat']['consultation'] ?? $o['vat_consultation'])) ?></span></small>
          <?php endif; ?>
          <?php if ($doc): ?>
            <br><small class="muted">Przy „wysłane": <b><?= h($doc['kind']) ?></b> — <?= h($doc['raison']) ?>.
              VIES zostanie sprawdzony ponownie tuż przed wystawieniem.</small>
          <?php endif; ?>
        </dd>
        <?php endif; ?>
        <dt>Dostawa</dt><dd><?= h($o['delivery_method']) ?><?= $o['inpost_point'] !== '' ? ' · ' . h($o['inpost_point']) : '' ?>
          <?php if ($o['delivery_method'] === 'inpost_courier'): ?><br><?= h($o['ship']['street'] . ' ' . $o['ship']['building']) ?>, <?= h($o['ship']['postcode'] . ' ' . $o['ship']['city']) ?><?php endif; ?></dd>
        <dt>Paczka</dt><dd><?= number_format($o['weight_g'] / 1000, 2, ',', ' ') ?> kg · gabaryt <?= h($o['parcel_template'] ?: '—') ?>
          <small class="muted"> (szacunek z wymiarów)</small></dd>
        <?php if (($o['note'] ?? '') !== ''): ?><dt>Uwagi</dt><dd><?= h($o['note']) ?></dd><?php endif; ?>
      </dl>
      <div class="tablewrap">
        <table>
          <tr><th>Produkt</th><th class="num">Il.</th><th class="num">Brutto</th></tr>
          <?php foreach ($o['items'] as $l): ?>
          <tr><td><?= h($l['name']) ?></td><td class="num"><?= (int) $l['qty'] ?></td><td class="num"><?= h(pln($l['line_gross'])) ?></td></tr>
          <?php endforeach; ?>
          <tr><td>Dostawa</td><td class="num"></td><td class="num"><?= h(pln($o['shipping_gross'])) ?></td></tr>
          <tr><td><b>Razem</b> <small class="muted">(netto <?= h(pln($o['total_net'])) ?> + VAT <?= h(pln($o['total_vat'])) ?>)</small></td>
              <td class="num"></td><td class="num"><b><?= h(pln($o['total_gross'])) ?></b></td></tr>
        </table>
      </div>
    </div>

    <?php if ($isAdmin): ?>
    <div class="actions">
      <form method="post" style="align-items:center">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
        <select name="status">
          <?php foreach (WSM_ORDER_STATUSES as $s): ?>
          <option value="<?= h($s) ?>"<?= $s === $o['status'] ? ' selected' : '' ?>><?= h($statusLabel[$s] ?? $s) ?></option>
          <?php endforeach; ?>
        </select>
        <label style="display:flex;align-items:center;gap:7px;font-size:13.5px;white-space:nowrap">
          <input type="checkbox" name="powiadom" value="1" checked>
          Powiadom klienta
        </label>
        <button type="submit">Zmień status</button>
      </form>
      <form method="post">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
        <?php if ($wzRow): ?>
        <a class="code" href="magazyn.php?dok=<?= (int) $wzRow['id'] ?>">WZ <?= h((string) $wzRow['number']) ?> →</a>
        <?php else: ?>
        <button type="submit" name="wz" value="1">Utwórz WZ (wydanie)</button>
        <?php endif; ?>
      </form>
      <form method="post">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
        <button class="primary" type="submit" name="ship" value="1"
          <?= $blockers || $o['payment_status'] !== 'oplacone' ? 'disabled title="' . h($blockers ? 'Brak danych: ' . implode(', ', $blockers) : 'Zamówienie nieopłacone') . '"' : '' ?>>
          Utwórz przesyłkę InPost
        </button>
      </form>
    </div>
    <?php endif; ?>

    <?php
    // Ce qu'on imprime réellement pour un colis : le bon de préparation, le WZ
    // qui part avec la marchandise et se signe à la réception, et l'étiquette.
    // L'étiquette du transporteur est celle qui fait foi dès qu'elle existe —
    // la nôtre ne sert qu'à défaut, et le dit sur la feuille.
    $shipRow = $pdo->prepare("SELECT shipment_id, tracking_number FROM wsm_shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $shipRow->execute([(int) $o['id']]);
    $shipNow = $shipRow->fetch() ?: [];
    $maPrzesylke = trim((string) ($shipNow['shipment_id'] ?? '')) !== '';
    ?>
    <h2 style="margin-top:22px">Wydruki</h2>
    <p class="actions" style="margin-top:8px">
      <a class="code" href="zamowienia.php?id=<?= (int) $o['id'] ?>&amp;druk=1" target="_blank" rel="noopener">Bon zamówienia (A4) ↗</a>
      <?php if ($wzRow): ?>
      <a class="code" href="magazyn.php?dok=<?= (int) $wzRow['id'] ?>&amp;druk=1" target="_blank" rel="noopener">Drukuj WZ <?= h((string) $wzRow['number']) ?> (A4) ↗</a>
      <?php endif; ?>
      <?php
      // L'ÉTIQUETTE SUIT LE TRANSPORTEUR. Le lien pointait sur InPost quel que
      // soit le colis : une commande DPD envoyait chercher son étiquette chez
      // un transporteur qui ne la connaît pas, et l'on cherchait la panne du
      // mauvais côté. Le transporteur se lit dans la table des méthodes.
      $carrier = wsm_ship_carrier($pdo, (string) $o['delivery_method']);
      $ecranEt = $carrier === 'dpd' ? 'etykieta_dpd.php' : 'etykieta_inpost.php';
      $nomEt   = $carrier === 'dpd' ? 'DPD' : 'InPost';
      ?>
      <?php if ($maPrzesylke): ?>
      <a class="code" href="<?= h($ecranEt) ?>?id=<?= (int) $o['id'] ?>" target="_blank" rel="noopener">Etykieta <?= h($nomEt) ?> — A6 ↗</a>
      <a class="code" href="<?= h($ecranEt) ?>?id=<?= (int) $o['id'] ?>&amp;format=a4" target="_blank" rel="noopener">Etykieta <?= h($nomEt) ?> — A4 ↗</a>
      <?php endif; ?>
      <a class="code" href="zamowienia.php?id=<?= (int) $o['id'] ?>&amp;etykieta=1" target="_blank" rel="noopener">Etykieta wewnętrzna ↗</a>
    </p>
    <p class="muted small" style="margin-top:4px">
      <?php if ($maPrzesylke): ?>
        Na paczkę naklejamy <b>etykietę <?= h($nomEt) ?></b> — to ona ma kod kreskowy i tylko ona jest
        listem przewozowym. A6 na drukarkę etykiet, A4 na zwykłą kartkę.
        Etykieta wewnętrzna to opis pomocniczy, nie przewozowy.
      <?php else: ?>
        Przesyłka nie została jeszcze utworzona w <?= h($nomEt) ?>, więc etykiety przewoźnika nie ma.
        Do tego czasu można wydrukować <b>etykietę wewnętrzną</b> — nie zastępuje listu przewozowego.
      <?php endif; ?>
      <?php if (!$wzRow): ?>
        <br>WZ pojawi się tu do druku po utworzeniu dokumentu wydania.
      <?php endif; ?>
    </p>

    <h2 style="margin-top:26px">Ładunek ShipX</h2>
    <p style="font-size:13px;color:var(--text-muted);margin:0 0 10px">
      Dokładnie to, co poleci do InPost. Widoczne także zanim integracja zostanie włączona — braki widać od razu.
    </p>
    <?php if ($blockers): ?><p class="flash err">Brakuje: <?= h(implode(', ', $blockers)) ?></p><?php endif; ?>
    <pre><?= h(json_encode(wsm_inpost_payload($o), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>

    <h2 style="margin-top:26px">Wiadomości do klienta</h2>
    <?php $msgs = wsm_messages_list($pdo, ['order_id' => (int) $o['id'], 'limit' => 50]); ?>
    <?php if (!$msgs): ?>
    <p class="muted small">Nic jeszcze nie wysłano.</p>
    <?php else: ?>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Kiedy</th><th>Temat</th><th>Stan</th></tr></thead>
      <tbody>
      <?php foreach ($msgs as $m): ?>
      <tr>
        <td data-l="Kiedy" class="num"><?= h(substr((string) $m['created_at'], 0, 16)) ?></td>
        <td data-l="Temat" class="wide"><a class="code" href="poczta.php?id=<?= (int) $m['id'] ?>"><?= h($m['subject']) ?></a></td>
        <td data-l="Stan"><span class="tag <?= $m['status'] === 'wyslana' ? 'ok' : ($m['status'] === 'blad' ? 'bad' : 'wait') ?>"><?= h($m['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <p class="actions"><a class="code" href="poczta.php?order_id=<?= (int) $o['id'] ?>&amp;tpl=kontakt">Napisz do klienta →</a></p>

    <h2 style="margin-top:26px">Historia</h2>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Kiedy</th><th>Zdarzenie</th><th>Szczegóły</th><th>Kto</th></tr></thead>
      <tbody>
      <?php foreach ($events as $ev): ?>
      <tr><td data-l="Kiedy" class="num"><?= h((string) $ev['created_at']) ?></td><td data-l="Zdarzenie"><?= h((string) $ev['event']) ?></td>
          <td data-l="Szczegóły" class="wide"><?= h((string) $ev['detail']) ?></td><td data-l="Kto"><?= h((string) $ev['actor']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p style="margin-top:18px"><a class="code" href="zamowienia.php">← Wszystkie zamówienia</a></p>
  </div>
<?php endif; ?>

  <?php // ─── LES FILES ────────────────────────────────────────────────────
        // Trois tas, et leur compte. Le compte n'est pas décoratif : c'est lui
        // qui dit s'il reste du travail, et il évite d'ouvrir une file vide. ?>
  <div class="pasek">
    <?php // La tablica porte ses files EN COLONNES : garder les onglets à côté
          // donnerait deux commandes du même tri, dont une sans effet. ?>
    <?php if ($widok === 'lista'): ?>
    <nav class="kolejki" aria-label="Kolejki zamówień">
      <?php foreach ($KOLEJKI as $k => [$nazwa, $sts]): ?>
      <a href="<?= h($lien(['kolejka' => $k])) ?>"<?= $k === $kolejka ? ' class="on" aria-current="page"' : '' ?>>
        <b><?= (int) $licznik[$k] ?></b><span><?= h($nazwa) ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
    <?php else: ?>
    <p class="kolejki-zast">Trzy kolumny, trzy etapy — <b><?= (int) $licznik['wszystkie'] ?></b> zamówień w bazie.</p>
    <?php endif; ?>

    <?php // DEUX VUES, PAS DEUX ÉCRANS. Le choix vit dans l'adresse : un
          // signet, un lien envoyé à quelqu'un, un retour arrière — tout
          // retombe sur la vue qu'on avait. ?>
    <nav class="widoki" aria-label="Widok listy">
      <?php foreach ($WIDOKI as $w => $nazwa): ?>
      <a href="<?= h($lien(['widok' => $w])) ?>"<?= $w === $widok ? ' class="on" aria-current="true"' : '' ?>><?= h($nazwa) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>

  <?php if ($hurtowa && $isAdmin): ?>
  <?php // LE FORMULAIRE DU LOT VIT HORS DU TABLEAU, et les cases s'y rattachent
        // par leur attribut « form ». Un formulaire qui envelopperait les
        // lignes serait imbriqué dans ceux des étapes — interdit en HTML, et le
        // navigateur en avale un des deux sans rien dire. ?>
  <form method="post" id="hurt" class="hurt">
    <input type="hidden" name="_t" value="<?= h($csrf) ?>">
    <input type="hidden" name="hurt" value="1">
  </form>
  <?php endif; ?>

  <?php
  // ─── UN BOUTON D'ÉTAPE, ÉCRIT UNE FOIS ────────────────────────────────────
  //
  // Il sert aux deux vues et aux deux endroits de chaque vue (le geste du
  // jour, en grand ; les autres étapes, repliées). Écrit deux fois, il aurait
  // divergé sur la confirmation — celle qui empêche d'expédier par erreur.
  $przycisk = function (array $e, array $o, string $extra = '') use ($csrf) { ?>
    <button class="etap <?= h($e['etat']) ?><?= $extra !== '' ? ' ' . $extra : '' ?>"
            name="status" value="<?= h($e['code']) ?>"
            <?= $e['pyt'] !== '' ? 'data-pyt="' . h($e['pyt']) . '" onclick="return confirm(this.dataset.pyt)"' : '' ?>
            title="<?= h($o['code']) ?> → <?= h($e['txt']) ?><?= $e['doc'] ? ' · wystawi dokument' : '' ?>">
      <?= h($e['txt']) ?><?= $e['doc'] ? '<span class="doc" aria-hidden="true">•</span>' : '' ?>
    </button>
  <?php };

  // ─── LE VERDICT D'AVANT-EXPÉDITION, EN UN JETON ───────────────────────────
  //
  // C'est LE changement. Ces cinq points dépliés en permanence dans une
  // colonne trop étroite donnaient quatre lignes de texte orange par
  // commande — 420 px de haut pour un colis, deux colis par écran, sur la
  // vue la plus utilisée de la maison. Or ce sont des informations de
  // DIAGNOSTIC (« pourquoi celui-là ne part pas »), posées au milieu d'un
  // outil de BALAYAGE (« qu'est-ce qu'il y a à faire aujourd'hui »).
  //
  // Réduites à trois caractères, elles disent la seule chose qu'on lit en
  // balayant : est-ce que ça part, oui ou non. Le reste s'ouvre à la demande.
  $werdykt = function (array $d) {
      if ($d['blok'] > 0) return ['no', '✗ ' . $d['blok'], 'nie wyjdzie'];
      if ($d['uwagi'] > 0) return ['wa', '! ' . $d['uwagi'], 'wyjdzie z uwagą'];
      return ['ok', '✓ gotowe', 'gotowe do wysyłki'];
  };

  // ─── LE TIROIR ────────────────────────────────────────────────────────────
  //
  // Ouvert par :target — donc par le navigateur, sans une ligne de
  // JavaScript, et sur le téléphone de la réserve comme ailleurs. Il porte
  // les cinq points AVEC leurs gestes, et le reste du chemin : la ligne
  // au-dessus n'a plus qu'un seul bouton, toujours à la même place.
  $tiroir = function (array $o, array $d) use ($przycisk, $csrf, $isAdmin) { ?>
    <div class="szuf-in">
      <?php // L'HEURE EXACTE, pas seulement « za 6 godz. ». On organise une
            // journée avec des heures : « do 14:30 » se range dans un planning,
            // « za six heures » demande un calcul mental à chaque lecture. ?>
      <?php $t = $d['termin']; if ($t['terme']): ?>
      <p class="termin-ligne t-<?= h($d['tEt'][0]) ?>">
        <b><?= h($d['tEt'][2]) ?></b>
        <span>Obiecany czas: <?= (int) $t['delai_h'] ?> godz. od zapłaty — zmienia się w Ustawieniach.</span>
      </p>
      <?php endif; ?>
      <ul class="przed-lista">
        <?php foreach ($d['pf']['lignes'] as $lg): ?>
        <li class="<?= h($lg['etat']) ?>">
          <span class="co"><i aria-hidden="true"><?= ['ok' => '✓', 'no' => '✗', 'wa' => '!', 'brak' => '—'][$lg['etat']] ?? '·' ?></i><?= h($lg['co']) ?></span>
          <span class="val"><?= h($lg['val']) ?></span>
          <?php if ($isAdmin && ($lg['agir'] ?? '') !== ''): ?>
          <button class="etap kasa" name="<?= h($lg['agir']) ?>" value="1"><?= h($lg['agirTxt'] ?? 'Zrób') ?></button>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($isAdmin && $d['autres']): ?>
      <div class="inne">
        <span class="inne-l">Inny status:</span>
        <?php foreach ($d['autres'] as $e): ?>
          <?php if ($e['etat'] === 'niemozliwy'): ?>
          <span class="etap niemozliwy" title="Zamówienie anulowane — stanu już nie zmienisz"><?= h($e['txt']) ?></span>
          <?php else: $przycisk($e, $o); endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  <?php }; ?>

<?php if ($widok === 'lista'): ?>
  <?php // ═══ VUE « LISTA » ═══════════════════════════════════════════════════
        // Une commande, une ligne, 46 px. Le tableau garde ce que seul un
        // tableau sait faire : comparer des montants alignés, cocher un lot,
        // retrouver un numéro. ?>
  <div class="tablewrap">
  <table class="rwd dense zam2">
    <thead><tr>
      <?php if ($hurtowa && $isAdmin): ?><th class="zazn"><span class="sr">Zaznacz</span></th><?php endif; ?>
      <th>Numer</th><th>Klient</th><th>Dostawa</th><th class="num">Brutto</th>
      <th>Stan</th><th>Płatność</th><th>Przed wysyłką</th><th class="num">Ruch</th>
    </tr></thead>
    <tbody>
    <?php if (!$orders): ?>
    <tr><td class="muted" colspan="9">Brak zamówień w tej kolejce.</td></tr>
    <?php endif; ?>
    <?php foreach ($orders as $o):
      $d = $dane($o);
      [$wKl, $wTxt, $wTyt] = $werdykt($d);
      $payCls = $o['payment_status'] === 'oplacone' ? 'ok' : ($o['payment_status'] === 'oczekuje' ? 'wait' : 'bad'); ?>
    <tr id="z<?= (int) $o['id'] ?>" class="wiersz<?= $hurtowa && $d['blok'] ? ' zablok' : '' ?>">
      <?php if ($hurtowa && $isAdmin): ?>
      <td data-l="Wyślij" class="zazn">
        <input type="checkbox" name="ids[]" form="hurt" value="<?= (int) $o['id'] ?>"
               <?= $d['blok'] ? 'disabled' : '' ?>
               aria-label="Wyślij <?= h($o['code']) ?>"
               title="<?= $d['blok'] ? h(implode(' · ', $d['pf']['blok'])) : 'Zaznacz do wysyłki hurtem' ?>">
      </td>
      <?php endif; ?>
      <?php // Numéro et date dans UNE colonne : deux données qu'on lit ensemble
            // n'ont pas besoin de deux colonnes, et c'est 90 px rendus au client. ?>
      <td data-l="Numer" class="ident">
        <a class="code" href="<?= h($lien(['id' => (int) $o['id']])) ?>"><?= h($o['code']) ?></a>
        <span class="dt"><?= h(substr((string) $o['created_at'], 0, 10)) ?> · <?= h(substr((string) $o['created_at'], 11, 5)) ?></span>
      </td>
      <td data-l="Klient" class="kli"><span class="im"><?= h($o['client']) ?></span><span class="ml"><?= h($o['email']) ?></span></td>
      <td data-l="Dostawa" class="dost"><?= h(wsm_ship_opis($pdo, (string) $o['delivery_method'])) ?>
        <?= $o['inpost_point'] !== '' ? '<span class="pkt">' . h($o['inpost_point']) . '</span>' : '' ?></td>
      <td data-l="Brutto" class="num kasa2"><span class="kw"><?= h(pln($o['total_gross'])) ?></span>
        <span class="dt"><?= (int) $o['units'] ?> poz.</span></td>
      <?php // L'ÉTAT SE LIT, IL NE SE CLIQUE PAS. La pastille noire pleine se
            // lisait comme un bouton et n'en était pas un : on cliquait dessus,
            // il ne se passait rien, on cherchait la panne. ?>
      <td data-l="Stan"><span class="stan s-<?= h($o['status']) ?>"><i class="kropka"></i><?= h($statusLabel[$o['status']] ?? $o['status']) ?></span>
        <?php // LE TEMPS QUI RESTE, DANS LA MÊME CASE QUE L'ÉTAT. Une neuvième
              // colonne aurait coûté 80 px sur un écran déjà serré, pour une
              // donnée qu'on lit avec l'état, pas à côté. ?>
        <?php [$tKl, $tTxt, $tTyt] = $d['tEt']; if ($tTxt !== ''): ?>
        <span class="termin t-<?= h($tKl) ?>" title="<?= h($tTyt) ?>"><?= h($tTxt) ?></span>
        <?php endif; ?>
        <?php if (!empty($o['backorder'])): ?><span class="tag no">do potwierdzenia</span><?php endif; ?>
        <?php if (($o['discount_percent'] ?? 0) > 0): ?><span class="tag">−<?= (int) $o['discount_percent'] ?> %</span><?php endif; ?></td>
      <td data-l="Płatność"><span class="stan p-<?= h($payCls) ?>"><i class="kropka"></i><?= h($payLabel[$o['payment_status']] ?? $o['payment_status']) ?></span></td>
      <?php // Le jeton EST le lien qui ouvre le tiroir : une cible de plus à
            // viser au pouce serait une cible de trop. ?>
      <td data-l="Przed wysyłką">
        <a class="werd w-<?= h($wKl) ?>" href="#s<?= (int) $o['id'] ?>" title="<?= h($wTyt) ?> — pokaż szczegóły">
          <span class="dokchip <?= $d['pf']['kind'] === 'faktura' ? 'f' : 'p' ?>"><?= h(strtoupper($d['pf']['kind'])) ?></span>
          <b><?= h($wTxt) ?></b><i aria-hidden="true">▾</i>
        </a>
      </td>
      <?php // UN SEUL bouton par ligne, toujours au même endroit : le pouce et
            // l'œil apprennent une position, pas six. ?>
      <td data-l="Ruch" class="num ruch">
        <?php if ($isAdmin && $d['suiv'] && $d['blok'] && !empty($d['suiv']['doc'])): ?>
        <?php // UN BOUTON QUI VA ÉCHOUER EST PIRE QU'AUCUN BOUTON : il fait
              // cliquer, attendre, puis lire une erreur. L'étape qui émet le
              // document, sur une commande que le contrôle bloque, part en
              // erreur — et l'envoi par lot refuse DÉJÀ ces commandes côté
              // serveur. La ligne disait le contraire du lot. ?>
        <a class="etap zablokowany" href="#s<?= (int) $o['id'] ?>"
           title="<?= h(implode(' · ', $d['pf']['blok'])) ?>">Nie wyjdzie</a>
        <?php elseif ($isAdmin && $d['suiv']): ?>
        <form method="post">
          <input type="hidden" name="_t" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
          <input type="hidden" name="powiadom" value="1">
          <?php $przycisk($d['suiv'], $o, 'glowny'); ?>
        </form>
        <?php else: ?><span class="dt">—</span><?php endif; ?>
      </td>
    </tr>
    <?php // Le tiroir : une ligne de tableau à part, pleine largeur. C'est
          // toute la différence — cinq points côte à côte au lieu de cinq
          // paragraphes empilés dans une colonne de 180 px. ?>
    <tr id="s<?= (int) $o['id'] ?>" class="szuflada"><td colspan="9">
      <form method="post">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
        <input type="hidden" name="powiadom" value="1">
        <?php $tiroir($o, $d); ?>
      </form>
      <a class="zwin" href="#z<?= (int) $o['id'] ?>">Zwiń ▴</a>
    </td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

<?php else: ?>
  <?php // ═══ VUE « TABLICA » ═════════════════════════════════════════════════
        // La position dit l'état : plus une seule pastille ne le répète. Et
        // l'on voit LA CHARGE — trois à préparer, une coincée à l'expédition —
        // ce qu'aucune liste ne montre. En échange, pas de tri et pas de lot :
        // c'est l'écran de l'atelier, pas celui de la recherche. ?>
  <div class="tablica">
    <?php foreach ($TABL as $k): [$nazwa, ] = $KOLEJKI[$k]; ?>
    <section class="kol<?= $k === 'przygotowanie' ? ' pilne' : '' ?>">
      <?php // PAS DE PLAFOND SILENCIEUX. La tablica lit 200 commandes : une
            // colonne qui en compte 2264 en montre 186, et « 186 » se lisait
            // comme le total. On annonce le vrai nombre, et ce qu'on montre. ?>
      <header><b><?= h($nazwa) ?></b>
        <span class="ile"><?= (int) $licznik[$k] ?><?= count($kubelki[$k]) < (int) $licznik[$k]
              ? '<em> · widać ' . count($kubelki[$k]) . '</em>' : '' ?></span></header>
      <div class="tresc">
        <?php if (!$kubelki[$k]): ?><p class="pusto">Nic tutaj.</p><?php endif; ?>
        <?php foreach ($kubelki[$k] as $o):
          $d = $dane($o);
          [$wKl, $wTxt, $wTyt] = $werdykt($d); ?>
        <article id="z<?= (int) $o['id'] ?>" class="karta<?= $d['blok'] ? ' blok' : '' ?>">
          <div class="gora">
            <a class="code" href="<?= h($lien(['id' => (int) $o['id']])) ?>"><?= h($o['code']) ?></a>
            <?php // Le temps restant EN HAUT DE LA CARTE, à côté du numéro : la
                  // tablica sert à voir la charge, et une charge se lit dans le
                  // temps qui reste avant qu'elle brûle. ?>
            <?php [$tKl, $tTxt, $tTyt] = $d['tEt']; if ($tTxt !== ''): ?>
            <span class="termin t-<?= h($tKl) ?>" title="<?= h($tTyt) ?>"><?= h($tTxt) ?></span>
            <?php endif; ?>
            <span class="kw"><?= h(pln($o['total_gross'])) ?></span>
          </div>
          <p class="im"><?= h($o['client']) ?></p>
          <div class="sz">
            <span class="dokchip <?= $d['pf']['kind'] === 'faktura' ? 'f' : 'p' ?>"><?= h(strtoupper($d['pf']['kind'])) ?></span>
            <?= h(wsm_ship_opis($pdo, (string) $o['delivery_method'])) ?>
            · <?= (int) $o['units'] ?> poz.
          </div>
          <?php // Le blocage tient en UNE ligne sur la carte, et nomme le trou.
                // Le détail complet reste à un clic, comme dans la liste. ?>
          <?php if ($d['blok'] || $d['uwagi']): ?>
          <a class="stop w-<?= h($wKl) ?>" href="#s<?= (int) $o['id'] ?>">
            <i class="kropka"></i><?= h($d['pf']['blok'] ? implode(' · ', $d['pf']['blok']) : $d['uwagaTxt']) ?>
          </a>
          <?php endif; ?>
          <?php if ($isAdmin && $d['suiv'] && $d['blok'] && !empty($d['suiv']['doc'])): ?>
          <a class="etap zablokowany" href="#s<?= (int) $o['id'] ?>"
             title="<?= h(implode(' · ', $d['pf']['blok'])) ?>">Nie wyjdzie</a>
          <?php elseif ($isAdmin && $d['suiv']): ?>
          <form method="post">
            <input type="hidden" name="_t" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
            <input type="hidden" name="powiadom" value="1">
            <?php $przycisk($d['suiv'], $o, 'glowny'); ?>
          </form>
          <?php endif; ?>
          <div id="s<?= (int) $o['id'] ?>" class="szuflada">
            <form method="post">
              <input type="hidden" name="_t" value="<?= h($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
              <input type="hidden" name="powiadom" value="1">
              <?php $tiroir($o, $d); ?>
            </form>
            <a class="zwin" href="#z<?= (int) $o['id'] ?>">Zwiń ▴</a>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($hurtowa && $isAdmin && $orders): ?>
  <?php // LA BARRE DU LOT. Le libellé se met à jour par un script minuscule ;
        // sans lui il reste générique et le bouton marche quand même —
        // l'envoi, lui, ne dépend d'aucun JavaScript. ?>
  <div class="lot">
    <div class="lot-in">
      <button type="submit" form="hurt" id="lotbtn"
              onclick="return confirm(this.dataset.pyt || 'Wysłać zaznaczone zamówienia? Wystawi to dokumenty i wyśle je do klientów.')"
              data-pyt="Wysłać zaznaczone zamówienia? Wystawi to dokumenty i wyśle je do klientów.">
        Wyślij zaznaczone
      </button>
      <p id="lotinfo">Zaznacz zamówienia, które wychodzą — dokument, mail i KSeF pójdą za każdym.</p>
    </div>
  </div>
  <script>
  (function () {
    var f = document.getElementById('hurt'), b = document.getElementById('lotbtn'),
        i = document.getElementById('lotinfo');
    if (!f || !b) return;
    function maj() {
      var c = document.querySelectorAll('input[name="ids[]"]:checked').length;
      b.textContent = c ? 'Wyślij ' + c + ' zaznaczone' : 'Wyślij zaznaczone';
      b.disabled = !c;
      b.dataset.pyt = 'Wysłać ' + c + ' zamówień? Wystawi to dokumenty i wyśle je do klientów.';
      if (i) i.textContent = c ? c + ' zaznaczonych · dokument, mail i KSeF pójdą za każdym'
                              : 'Zaznacz zamówienia, które wychodzą.';
    }
    document.addEventListener('change', function (e) {
      if (e.target && e.target.name === 'ids[]') maj();
    });
    maj();
  })();
  </script>
<?php endif; ?>
<?php console_foot();
