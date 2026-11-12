<?php
// ============================================================================
//  shipping.php — la file d'expédition : ce qui doit partir, et ce qui coince.
//
//  LE PROBLÈME QUE CE MODULE RÉSOUT. Une commande payée dont le colis n'est
//  jamais créé ne fait AUCUN bruit. Elle n'apparaît nulle part comme un
//  problème : elle est payée, elle est « nowe », et elle attend. Le client,
//  lui, attend aussi — puis écrit, puis se plaint, puis demande son argent.
//  Un numéro de téléphone manquant suffit à produire exactement ça.
//
//  D'où la règle qui tient tout le fichier :
//
//      CE QUI BLOQUE EST NOMMÉ, EN TOUTES LETTRES, À CÔTÉ DE LA COMMANDE.
//
//  « brakujace_dane: telefon » n'est pas un message pour un humain pressé.
//  « Brak telefonu — kurier nie ma jak zadzwonić » l'est : il dit ce qui
//  manque ET pourquoi ça compte.
//
//  QUATRE RÈGLES DE FOND :
//
//   1. ON N'EXPÉDIE PAS UNE COMMANDE IMPAYÉE. Créer l'étiquette d'un colis
//      qui n'a pas été réglé, c'est donner la marchandise. La file ne
//      propose que l'encaissé.
//   2. ON NE CRÉE JAMAIS DEUX FOIS. Une expédition qui porte déjà un numéro
//      de suivi n'est pas recréée : deux colis pour une commande, c'est deux
//      fois le port et un retour à gérer.
//   3. UN ÉCHEC N'ARRÊTE PAS LES AUTRES. Le traitement par lot continue et
//      rend le compte exact : combien créés, combien bloqués, et pourquoi.
//   4. SANS JETON INPOST, LA FILE RESTE LISIBLE. L'absence de configuration
//      est un ÉTAT D'ATTENTE affiché, pas une erreur silencieuse : les
//      colis partent alors à la main, et l'écran doit le dire.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Les états d'une expédition, dans l'ordre de sa vie. */
const WSM_SHIP_STATUSES = [
    'do_utworzenia'            => 'Do utworzenia',
    // Le libellé ne nomme plus InPost : il vaut pour n'importe quel
    // transporteur, et en nommer un seul faisait chercher la panne du mauvais
    // côté le jour où la commande partait par l'autre.
    'oczekuje_na_konfiguracje' => 'Czeka na konfigurację przewoźnika',
    'utworzona'                => 'Utworzona u przewoźnika',
    'blad'                     => 'Błąd u przewoźnika',
];

/**
 * Ce qui manque pour expédier, DIT EN POLONAIS ET AVEC LA RAISON.
 *
 * Le code interne (« adres.postcode ») sert au journal ; il n'a rien à faire
 * sur un écran. Quelqu'un doit pouvoir décrocher son téléphone en lisant
 * cette ligne.
 */
const WSM_SHIP_BLOCKERS = [
    'telefon'        => 'Brak telefonu — kurier nie ma jak zadzwonić',
    'e-mail'         => 'Brak e-maila — nie wyślemy numeru śledzenia',
    'odbiorca'       => 'Brak nazwiska odbiorcy',
    'waga'           => 'Waga zerowa — przewoźnik odrzuci przesyłkę',
    'paczkomat'      => 'Nie wybrano paczkomatu',
    // Une commande dont la méthode ne désigne aucun transporteur connu. Elle
    // ne doit surtout pas s'afficher comme prête : on la verrait échouer au
    // clic, sans que la file ait jamais prévenu.
    'przewoznik'     => 'Nieznany przewoźnik dla tej metody dostawy',
    'adres.street'   => 'Brak ulicy',
    'adres.building' => 'Brak numeru budynku',
    'adres.postcode' => 'Brak kodu pocztowego',
    'adres.city'     => 'Brak miejscowości',
];

/** Le libellé humain d'un blocage. */
function wsm_ship_blocker_label(string $code): string {
    return WSM_SHIP_BLOCKERS[$code] ?? $code;
}

// ---------------------------------------------------------------------------
//  LA RÉPARTITION PAR TRANSPORTEUR
//
//  Cette file appelait wsm_inpost_blockers() et wsm_inpost_create() en dur.
//  Tant qu'il n'y avait qu'InPost, ça se lisait comme une simplification ;
//  avec un second transporteur, ça devient un piège muet — une commande DPD
//  serait présentée à l'API d'InPost, qui la refuserait pour une raison sans
//  aucun rapport avec ce qui cloche vraiment.
//
//  Le transporteur se lit dans la TABLE des méthodes, jamais dans le nom :
//  une méthode renommée en console ne doit pas changer d'API.
//
//  AJOUTER UN TRANSPORTEUR se fait ici et à un seul endroit : une entrée dans
//  WSM_SHIP_ADAPTERS. Rien d'autre dans la file ne le connaît.
// ---------------------------------------------------------------------------
const WSM_SHIP_ADAPTERS = [
    'inpost' => ['fichier' => 'inpost.php', 'blockers' => 'wsm_inpost_blockers', 'create' => 'wsm_inpost_create'],
    'dpd'    => ['fichier' => 'dpd.php',    'blockers' => 'wsm_dpd_blockers',    'create' => 'wsm_dpd_create'],
];

// ---------------------------------------------------------------------------
//  LES TRANSPORTEURS QU'ON CONNAÎT ET QU'ON NE PILOTE PAS
//
//  Fresh Logistic prend des palettes à partir de 200 kg. On n'a pas son API —
//  et même avec, une palette ne s'expédie pas d'un clic : il y a un créneau à
//  convenir, un hayon à demander, une température à déclarer.
//
//  Sans cette liste, un tel transporteur retombait sur « przewoznik » —
//  « Nieznany przewoźnik ». C'est FAUX et ça envoie chercher la panne dans la
//  configuration : on croit à un identifiant mal saisi alors que la commande
//  est parfaitement normale et attend simplement un coup de téléphone.
//
//  Une commande chez eux passe donc les MÊMES contrôles de données que les
//  autres — un destinataire sans téléphone bloque une palette comme un colis —
//  et s'arrête juste avant l'envoi automatique, en le disant.
// ---------------------------------------------------------------------------
const WSM_SHIP_MANUELS = ['fresh' => 'Fresh Logistic'];

/**
 * Les contrôles de données communs à toute expédition vers une ADRESSE.
 *
 * Recopiés de wsm_dpd_blockers() une première fois, puis mis en commun : deux
 * listes identiques dérivent, et celle qu'on oublie de corriger est celle qui
 * laisse passer une commande incomplète.
 */
function wsm_ship_blockers_adresse(array $order): array {
    if (!function_exists('wsm_valid_phone')) require_once __DIR__ . '/shop.php';
    $out = [];
    if (($order['phone'] ?? '') === '' || !wsm_valid_phone((string) $order['phone'])) $out[] = 'telefon';
    if (($order['email'] ?? '') === '') $out[] = 'e-mail';
    if (($order['first_name'] ?? '') === '' && ($order['company'] ?? '') === '') $out[] = 'odbiorca';
    if (($order['weight_g'] ?? 0) <= 0) $out[] = 'waga';
    foreach (['street', 'building', 'postcode', 'city'] as $k) {
        if (($order['ship'][$k] ?? '') === '') $out[] = 'adres.' . $k;
    }
    return $out;
}

/** Charge les adaptateurs. Un fichier absent n'explose pas : il manque, c'est tout. */
function wsm_ship_adapters(): void {
    foreach (WSM_SHIP_ADAPTERS as $a) {
        $f = __DIR__ . '/' . $a['fichier'];
        if (is_file($f)) require_once $f;
    }
}

/** L'adaptateur d'une commande, ou null si son transporteur est inconnu. */
function wsm_ship_adapter(PDO $pdo, array $order): ?array {
    if (!function_exists('wsm_ship_carrier')) require_once __DIR__ . '/shop.php';
    $c = wsm_ship_carrier($pdo, (string) ($order['delivery_method'] ?? ''));
    return WSM_SHIP_ADAPTERS[$c] ?? null;
}

/**
 * Ce qui manque pour expédier — posé au BON transporteur.
 *
 * Un transporteur inconnu ne rend pas « rien ne manque » : ce serait afficher
 * la commande comme prête et la voir échouer au moment du clic, sans que la
 * file ait jamais prévenu.
 */
function wsm_ship_blockers(PDO $pdo, array $order): array {
    $a = wsm_ship_adapter($pdo, $order);
    if ($a && function_exists($a['blockers'])) return ($a['blockers'])($order);
    // Connu mais piloté à la main : les données se vérifient quand même, et
    // c'est l'ENVOI qui s'arrête, pas la commande.
    if (wsm_ship_manuel($pdo, $order) !== '') return wsm_ship_blockers_adresse($order);
    return ['przewoznik'];
}

/** Le nom du transporteur manuel de cette commande, ou '' si elle est pilotée. */
function wsm_ship_manuel(PDO $pdo, array $order): string {
    if (!function_exists('wsm_ship_carrier')) require_once __DIR__ . '/shop.php';
    $c = wsm_ship_carrier($pdo, (string) ($order['delivery_method'] ?? ''));
    return WSM_SHIP_MANUELS[$c] ?? '';
}

/** Crée l'expédition chez le bon transporteur. */
function wsm_ship_create(PDO $pdo, array $order): array {
    $a = wsm_ship_adapter($pdo, $order);
    if ($a && function_exists($a['create'])) return ($a['create'])($pdo, $order);
    // UN REFUS QUI DIT LA VÉRITÉ. « brak_przewoznika » pour Fresh Logistic
    // ferait chercher une erreur de configuration là où il n'y en a pas : la
    // commande est bonne, c'est l'expédition qui se fait au téléphone.
    $manuel = wsm_ship_manuel($pdo, $order);
    if ($manuel !== '') {
        return [null, 'nadanie_reczne: ' . $manuel . ' — umów odbiór i wpisz numer listu ręcznie'];
    }
    return [null, 'brak_przewoznika: ' . wsm_ship_carrier($pdo, (string) ($order['delivery_method'] ?? ''))];
}

/**
 * La file : ce qui est payé et n'est pas encore parti.
 *
 * Règle 1 — SEULEMENT L'ENCAISSÉ. Une commande impayée n'a rien à faire
 * dans une file d'expédition : la proposer, c'est inviter à donner la
 * marchandise un jour de rush.
 *
 * @return array [['order'=>array, 'shipment'=>array|null, 'blockers'=>string[], 'pret'=>bool], …]
 */
function wsm_ship_queue(PDO $pdo, int $limit = 200): array {
    if (!function_exists('wsm_order_by_id')) require_once __DIR__ . '/shop.php';
    wsm_ship_adapters();

    try {
        $st = $pdo->prepare("SELECT o.id
                               FROM wsm_orders o
                          LEFT JOIN wsm_shipments s ON s.order_id = o.id
                              WHERE o.payment_status = 'oplacone'
                                AND o.status <> 'anulowane'
                                AND (s.tracking_number IS NULL OR s.tracking_number = '')
                           ORDER BY o.id DESC
                              LIMIT " . max(1, min(500, $limit)));
        $st->execute();
        $ids = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) { return []; }

    $out = [];
    foreach ($ids as $id) {
        $o = wsm_order_by_id($pdo, (int) $id);
        if (!$o) continue;
        // Une commande sans expédition (formation, licence) n'a pas de colis :
        // elle n'a rien à faire dans la file des étiquettes.
        if (wsm_ship_kind($pdo, (string) $o['delivery_method']) === 'brak') continue;
        $b = wsm_ship_blockers($pdo, $o);
        $out[] = ['order' => $o, 'shipment' => $o['shipment'] ?? null,
                  'blockers' => $b, 'pret' => $b === []];
    }
    return $out;
}

/** Ce qui est déjà parti, le plus récent d'abord. */
function wsm_ship_sent(PDO $pdo, int $limit = 50): array {
    try {
        $st = $pdo->prepare("SELECT s.*, o.code, o.email, o.first_name, o.last_name, o.company
                               FROM wsm_shipments s
                               JOIN wsm_orders o ON o.id = s.order_id
                              WHERE s.tracking_number <> ''
                           ORDER BY s.id DESC
                              LIMIT " . max(1, min(200, $limit)));
        $st->execute();
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * De quoi tenir un compteur en tête d'écran.
 *
 * `bloquees` est LE chiffre de cet écran : ce sont des commandes payées qui
 * ne partiront jamais tant que personne ne regarde.
 *
 * LA FILE EST PASSÉE EN ARGUMENT, ET C'EST UNE RÈGLE, PAS UNE OPTIMISATION.
 * Auparavant cette fonction refaisait la file avec SA PROPRE borne (500)
 * pendant que l'écran en affichait 200 : au-delà de deux cents commandes en
 * attente, le compteur annonçait un nombre que la liste ne contenait pas, et
 * le bouton « nadaj wszystkie gotowe (300) » devenait un mensonge. Les
 * compteurs décrivent LA LISTE QU'ON REGARDE, jamais une autre.
 *
 * Accessoirement : trois passes sur la même file coûtaient trois fois le
 * même travail, et ce coût grandit avec chaque commande.
 */
function wsm_ship_kpis(PDO $pdo, ?array $file = null): array {
    $file ??= wsm_ship_queue($pdo);
    $k = ['do_wyslania' => 0, 'gotowe' => 0, 'bloquees' => 0, 'wyslane' => 0];
    foreach ($file as $x) {
        $k['do_wyslania']++;
        if ($x['pret']) $k['gotowe']++; else $k['bloquees']++;
    }
    try {
        $k['wyslane'] = (int) $pdo->query("SELECT COUNT(*) FROM wsm_shipments WHERE tracking_number <> ''")->fetchColumn();
    } catch (Throwable $e) { /* table neuve */ }
    return $k;
}

/**
 * Crée les expéditions d'un lot de commandes.
 *
 * Règle 2 : une commande qui porte déjà un numéro de suivi est SAUTÉE, pas
 * recréée — deux colis, c'est deux fois le port et un retour à gérer.
 * Règle 3 : un échec n'arrête pas les suivants, et le compte rendu nomme
 * chaque commande bloquée avec sa raison.
 *
 * @return array ['utworzone'=>int, 'pominiete'=>int, 'bledy'=>string[], 'message'=>string]
 */
function wsm_ship_batch(PDO $pdo, array $orderIds, string $actor = 'konsola'): array {
    if (!function_exists('wsm_order_by_id')) require_once __DIR__ . '/shop.php';
    wsm_ship_adapters();

    $faits = 0; $sautes = 0; $bledy = [];
    foreach ($orderIds as $id) {
        $id = (int) $id;
        if ($id <= 0) continue;
        $o = wsm_order_by_id($pdo, $id);
        if (!$o) { $bledy[] = "#$id — nie znaleziono zamówienia"; continue; }

        // Règle 1 : jamais un colis pour une commande impayée.
        if ((string) $o['payment_status'] !== 'oplacone') {
            $bledy[] = $o['code'] . ' — niezapłacone, nie wysyłamy';
            continue;
        }
        // Règle 2 : déjà parti ?
        if (trim((string) ($o['shipment']['tracking_number'] ?? '')) !== '') { $sautes++; continue; }

        [$ship, $err] = wsm_ship_create($pdo, $o);
        if ($ship) {
            $faits++;
            if (function_exists('wsm_audit')) {
                wsm_audit($pdo, $actor, 'Utworzenie przesyłki', 'wsm_shipments ' . $o['code'], 'Sieć');
            }
            continue;
        }

        // Le message d'erreur brut sert au journal ; l'écran veut du polonais.
        $bledy[] = $o['code'] . ' — ' . wsm_ship_erreur_humaine((string) $err);
    }

    $m = 'Utworzono ' . $faits . ' przesyłek.';
    if ($sautes > 0) $m .= ' Pominięto ' . $sautes . ' — miały już numer śledzenia.';
    if ($bledy) $m .= ' Zablokowanych: ' . count($bledy) . '.';
    return ['utworzone' => $faits, 'pominiete' => $sautes, 'bledy' => $bledy, 'message' => $m];
}

/**
 * Traduit une erreur de l'adaptateur en phrase utilisable.
 *
 * « inpost_nieskonfigurowany » ne dit à personne quoi faire. « Brak tokenu
 * InPost — uzupełnij w Ustawieniach » le dit.
 */
function wsm_ship_erreur_humaine(string $err): string {
    if ($err === 'inpost_nieskonfigurowany') {
        return 'Brak tokenu InPost — uzupełnij w Ustawieniach. Do tego czasu paczki nadajesz ręcznie.';
    }
    if ($err === 'dpd_nieskonfigurowany') {
        return 'Brak danych DPD (login, hasło, FID) — uzupełnij w Ustawieniach. Do tego czasu paczki nadajesz ręcznie.';
    }
    if (str_starts_with($err, 'brak_przewoznika: ')) {
        return 'Nieznany przewoźnik « ' . substr($err, strlen('brak_przewoznika: '))
             . ' » — sprawdź metodę dostawy tego zamówienia.';
    }
    if (str_starts_with($err, 'brakujace_dane: ')) {
        $codes = array_map('trim', explode(',', substr($err, strlen('brakujace_dane: '))));
        $mots = array_map('wsm_ship_blocker_label', array_filter($codes));
        return implode(' · ', $mots);
    }
    return $err !== '' ? $err : 'nieznany błąd przewoźnika';
}

/**
 * Le récapitulatif des blocages, groupé par cause.
 *
 * Voir « 12 × brak telefonu » fait corriger un formulaire ; voir douze
 * lignes séparées fait fermer l'écran.
 *
 * @return array [['code'=>…, 'label'=>…, 'n'=>int], …] du plus fréquent au moins
 */
function wsm_ship_blockers_summary(PDO $pdo, ?array $file = null): array {
    $file ??= wsm_ship_queue($pdo);
    $par = [];
    foreach ($file as $x) {
        foreach ($x['blockers'] as $b) $par[$b] = ($par[$b] ?? 0) + 1;
    }
    arsort($par);
    $out = [];
    foreach ($par as $code => $n) {
        $out[] = ['code' => $code, 'label' => wsm_ship_blocker_label($code), 'n' => $n];
    }
    return $out;
}
