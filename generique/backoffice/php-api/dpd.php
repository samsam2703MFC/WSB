<?php
// ============================================================================
//  dpd.php — expédition par DPD Polska. Intégration complète.
//
//  POURQUOI UN SECOND TRANSPORTEUR. InPost couvre la Pologne et s'arrête là.
//  La boutique a vingt-six pays ouverts à la vente et un seul transporteur :
//  chaque visiteur non polonais arrive sur « nie dowozimy jeszcze do tego
//  kraju ». DPD dessert le pays ET l'Europe, et c'est la seule façon de
//  rendre ces vingt-cinq autres pays autre chose qu'une promesse.
//
//  L'API DE DPD POLSKA EST DU SOAP, PAS DU REST. Ce n'est pas un détail de
//  goût : un client JSON envoyé sur ces adresses ne reçoit rien
//  d'exploitable. Le service s'appelle DPDPackageObjServicesService et prend
//  trois méthodes, qui sont les trois moments d'un colis :
//
//    generatePackagesNumbersV4 → le colis existe, il a un numéro de suivi
//    generateSpedLabelsV4      → l'étiquette PDF, celle que DPD scanne
//    packagesPickupCallV4      → l'enlèvement, et le protocole à signer
//
//  L'EXTENSION soap PEUT MANQUER, ET IL FAUT LE DIRE. Le php de ce serveur
//  n'a déjà pas pdo_mysql en ligne de commande ; on a perdu trois
//  déploiements avant de le mesurer. On ne refait pas l'erreur : si soap
//  manque, wsm_dpd_enabled() rend false et wsm_dpd_manquants() NOMME
//  l'extension. Un écran qui dit « brak rozszerzenia soap » se répare en une
//  ligne de shell ; un colis qui ne part jamais sans raison affichée, non.
//
//  CE FICHIER NE DÉCIDE PAS DES PRIX. Le tarif d'un pays est une décision
//  commerciale, pas une constante technique : un prix inventé ici se paierait
//  sur chaque colis, en silence, jusqu'à ce que quelqu'un fasse les comptes.
//  Les prix vivent dans wsm_shipping_methods, le pays desservi dans sa colonne
//  `countries` — les deux se règlent sans toucher au code.
//
//  LES MÊMES RÈGLES QUE POUR INPOST, PARCE QU'ELLES NE DÉPENDENT PAS DU
//  TRANSPORTEUR :
//
//   1. SANS IDENTIFIANTS, RIEN NE PART. Pas de demi-configuration : ou bien
//      le canal est ouvert, ou bien la commande reste en attente et le colis
//      se prépare à la main. « xxxx » vaut « pas renseigné ».
//   2. UNE BOUTIQUE QUI VEND SANS POUVOIR EXPÉDIER RESTE UNE BOUTIQUE QUI
//      VEND. Le mode de livraison s'offre au client même sans identifiants ;
//      c'est la file d'expédition qui dit ce qui manque, à qui peut le régler.
//   3. LA CHARGE UTILE SE CONSTRUIT MÊME ÉTEINTE. Le back-office l'affiche,
//      et l'on voit ce qui manquerait AVANT d'avoir signé un contrat.
//   4. JAMAIS DE PAIEMENT À LA LIVRAISON. Tout est encaissé par tpay avant
//      l'expédition. On l'écrit, pour qu'aucun COD ne s'invite.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/shop.php';

const WSM_DPD_WSDL_PROD = 'https://dpdservices.dpd.com.pl/DPDPackageObjServicesService/DPDPackageObjServices?wsdl';
const WSM_DPD_WSDL_TEST = 'https://dpdservicesdemo.dpd.com.pl/DPDPackageObjServicesService/DPDPackageObjServices?wsdl';

/**
 * La configuration. `xxxx` vaut VIDE : c'est ce que porte un champ de
 * démonstration, et le prendre pour un identifiant ouvrirait l'intégration
 * sur du vent. Même convention que tpay, InPost, Allegro et KSeF.
 */
function wsm_dpd_cfg(): array {
    $c = wsm_config()['dpd'] ?? [];
    $net = function ($v): string {
        $v = trim((string) $v);
        return ($v === '' || strtolower($v) === 'xxxx') ? '' : $v;
    };
    return [
        'login'    => $net($c['login'] ?? ''),
        'password' => $net($c['password'] ?? ''),
        // Le numéro de client DPD (FID / masterFid). Sans lui l'API ne sait
        // pas à quel compte rattacher le colis, donc à qui facturer le port.
        'fid'      => $net($c['fid'] ?? ''),
        'sandbox'  => !empty($c['sandbox']),
        // L'expéditeur imprimé sur l'étiquette. DPD l'exige : un colis sans
        // adresse de retour part quand même et ne revient jamais.
        'sender_name'     => $net($c['sender_name'] ?? ''),
        'sender_address'  => $net($c['sender_address'] ?? ''),
        'sender_city'     => $net($c['sender_city'] ?? ''),
        'sender_postcode' => $net($c['sender_postcode'] ?? ''),
        'sender_country'  => strtoupper($net($c['sender_country'] ?? '') ?: 'PL'),
        'sender_phone'    => $net($c['sender_phone'] ?? ''),
    ];
}

/** L'extension SOAP est-elle là ? Mesurée, jamais supposée. */
function wsm_dpd_soap_ok(): bool { return class_exists('SoapClient'); }

/** Peut-on créer une expédition ? Tout, ou rien. */
function wsm_dpd_enabled(): bool {
    return wsm_dpd_manquants() === [];
}

/**
 * Ce qui manque — pour l'écrire à l'écran plutôt que de le laisser deviner.
 * L'extension PHP figure dans la même liste que les identifiants : pour qui
 * regarde l'écran, « il manque le mot de passe » et « il manque soap » sont
 * la même phrase — « ça ne partira pas tant que… ».
 */
function wsm_dpd_manquants(): array {
    $c = wsm_dpd_cfg();
    $out = [];
    if (!wsm_dpd_soap_ok())    $out[] = 'rozszerzenie PHP soap';
    if ($c['login'] === '')    $out[] = 'login';
    if ($c['password'] === '') $out[] = 'hasło';
    if ($c['fid'] === '')      $out[] = 'numer klienta (FID)';
    if ($c['sender_name'] === '' || $c['sender_address'] === ''
        || $c['sender_city'] === '' || $c['sender_postcode'] === '') {
        // Sans expéditeur, DPD accepte parfois le colis — et il n'a alors
        // aucune adresse de retour. Un colis refusé à l'arrivée disparaît.
        $out[] = 'adres nadawcy';
    }
    return $out;
}

function wsm_dpd_wsdl(): string {
    return wsm_dpd_cfg()['sandbox'] ? WSM_DPD_WSDL_TEST : WSM_DPD_WSDL_PROD;
}

/** Le lien de suivi public. Il part au client, donc il ne porte aucun secret. */
function wsm_dpd_tracking_url(string $numer): string {
    $n = preg_replace('/[^0-9A-Za-z]/', '', $numer) ?? '';
    return $n === '' ? '' : 'https://tracktrace.dpd.com.pl/parcelDetails?p1=' . $n;
}

/** Le bloc d'authentification, exigé par CHAQUE appel du service. */
function wsm_dpd_auth(): array {
    $c = wsm_dpd_cfg();
    return ['login' => $c['login'], 'password' => $c['password'], 'masterFid' => $c['fid']];
}

/**
 * Le client SOAP. Fabriqué à chaque appel plutôt que gardé : une connexion
 * conservée entre deux requêtes PHP n'existe pas, et le WSDL est mis en cache
 * par l'extension elle-même.
 *
 * `exceptions => true` : on veut l'erreur, pas un objet muet à interpréter.
 */
function wsm_dpd_client(): ?SoapClient {
    if (!wsm_dpd_soap_ok()) return null;
    try {
        return new SoapClient(wsm_dpd_wsdl(), [
            'trace' => false, 'exceptions' => true,
            'connection_timeout' => 20,
            'cache_wsdl' => WSDL_CACHE_MEMORY,
        ]);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Charge utile DPD pour une commande. Construite même quand l'intégration est
 * éteinte (règle 3) : l'écran d'expédition l'affiche, et l'on voit ce qui
 * manquerait avant d'avoir signé quoi que ce soit.
 *
 * DPD LIVRE À UNE ADRESSE. Les points DPD Pickup existent, mais ils exigent
 * un sélecteur sur carte et un champ de code à eux ; les offrir sans ça
 * reviendrait à demander au client de retenir un identifiant qu'il n'a nulle
 * part — exactement le défaut qu'on vient de réparer côté Paczkomat. Tant que
 * ce sélecteur n'existe pas, DPD est un service à l'adresse, et rien d'autre.
 */
function wsm_dpd_payload(array $order): array {
    $c = wsm_dpd_cfg();
    $ship = $order['ship'] ?? [];
    $cp = static fn($v): string => preg_replace('/[^0-9A-Za-z]/', '', (string) $v) ?? '';

    $destinataire = [
        'company'     => (string) ($order['company'] ?? ''),
        'name'        => trim((string) (($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''))) ?: '—',
        'address'     => trim((string) (($ship['street'] ?? '') . ' ' . ($ship['building'] ?? ''))),
        'city'        => (string) ($ship['city'] ?? ''),
        'postalCode'  => $cp($ship['postcode'] ?? ''),
        'countryCode' => strtoupper((string) ($ship['country'] ?? 'PL')),
        'email'       => (string) ($order['email'] ?? ''),
        'phone'       => (string) ($order['phone'] ?? ''),
    ];

    $expediteur = [
        'company'     => $c['sender_name'],
        'name'        => $c['sender_name'],
        'address'     => $c['sender_address'],
        'city'        => $c['sender_city'],
        'postalCode'  => $cp($c['sender_postcode']),
        'countryCode' => $c['sender_country'],
        'phone'       => $c['sender_phone'],
        'fid'         => $c['fid'],
    ];

    $colis = [
        // DPD refuse un poids nul. On ne l'invente pas pour autant : la file
        // bloque déjà une commande sans poids (voir wsm_dpd_blockers), donc
        // ce plancher ne sert que de garde-fou de format.
        'weight'    => max(0.1, round(((int) ($order['weight_g'] ?? 0)) / 1000, 3)),
        'content'   => 'Czekolada',
        'reference' => (string) $order['code'],
    ];

    return [
        'openUMLFeV3' => [
            'packages' => [[
                'parcels'   => [$colis],
                'payerType' => 'SENDER',        // le transport est à notre charge
                'receiver'  => $destinataire,
                'sender'    => $expediteur,
                'ref1'      => (string) $order['code'],
                // Règle 4, écrite plutôt que sous-entendue : aucun COD.
                'services'  => ['cod' => null],
            ]],
        ],
        'pkgNumsGenerationPolicyV1' => 'STOP_ON_FIRST_ERROR',
        'langCode'   => 'PL',
        'authDataV1' => wsm_dpd_auth(),
    ];
}

/**
 * Ce qui manque pour que DPD accepte l'expédition.
 *
 * Les codes rendus sont les MÊMES que ceux d'InPost (`telefon`, `adres.city`…)
 * pour que l'écran d'expédition les traduise sans savoir de quel transporteur
 * il parle. Un vocabulaire par transporteur obligerait la file à connaître
 * chacun d'eux, et c'est précisément ce qu'on vient de lui retirer.
 */
function wsm_dpd_blockers(array $order): array {
    // La liste vivait ici en double de celle de shipping.php. Deux listes
    // identiques dérivent, et celle qu'on oublie de corriger est celle qui
    // laisse passer une commande incomplète.
    require_once __DIR__ . '/shipping.php';
    return wsm_ship_blockers_adresse($order);
}

/** Un objet SOAP en tableau, récursivement — la réponse arrive en stdClass. */
function wsm_dpd_tab($v): array {
    return json_decode(json_encode($v, JSON_UNESCAPED_UNICODE) ?: '[]', true) ?: [];
}

/**
 * Le numéro de suivi dans une réponse DPD, quel que soit l'étage où il se
 * trouve. La forme varie d'une version du service à l'autre, et une réponse
 * bien reçue dont on ne sait pas lire le numéro produirait une expédition
 * « créée » sans suivi — ce qui est pire qu'une erreur franche.
 */
function wsm_dpd_waybill(array $res): string {
    $trouve = '';
    array_walk_recursive($res, function ($v, $k) use (&$trouve) {
        if ($trouve !== '') return;
        if (in_array((string) $k, ['waybill', 'parcelId', 'packageId', 'sessionId'], true)
            && trim((string) $v) !== '') $trouve = trim((string) $v);
    });
    return $trouve;
}

/**
 * Crée l'expédition chez DPD. Renvoie [ligne d'expédition, erreur|null].
 *
 * Sans identifiants — ou sans l'extension soap — la ligne reste
 * `oczekuje_na_konfiguracje` : un état d'attente annoncé, pas un échec
 * silencieux. La commande est payée, et le colis part à la main en attendant.
 */
function wsm_dpd_create(PDO $pdo, array $order): array {
    $blockers = wsm_dpd_blockers($order);
    if ($blockers) return [null, 'brakujace_dane: ' . implode(', ', $blockers)];

    if (!wsm_dpd_enabled()) {
        $pdo->prepare("UPDATE wsm_shipments SET status = 'oczekuje_na_konfiguracje' WHERE order_id = ?")
            ->execute([$order['id']]);
        return [null, 'dpd_nieskonfigurowany'];
    }

    $cli = wsm_dpd_client();
    if (!$cli) {
        $pdo->prepare("UPDATE wsm_shipments SET status = 'oczekuje_na_konfiguracje' WHERE order_id = ?")
            ->execute([$order['id']]);
        return [null, 'dpd_niedostepny'];
    }

    try {
        $res = wsm_dpd_tab($cli->generatePackagesNumbersV4(wsm_dpd_payload($order)));
    } catch (Throwable $e) {
        $msg = 'dpd_soap: ' . mb_substr($e->getMessage(), 0, 180);
        $pdo->prepare("UPDATE wsm_shipments SET status = 'blad' WHERE order_id = ?")->execute([$order['id']]);
        wsm_order_event($pdo, (int) $order['id'], 'wysylka_blad', $msg, 'dpd');
        return [null, $msg];
    }

    $tracking = wsm_dpd_waybill($res);
    if ($tracking === '') {
        $msg = 'dpd_bez_numeru: ' . mb_substr(json_encode($res, JSON_UNESCAPED_UNICODE) ?: '', 0, 180);
        $pdo->prepare("UPDATE wsm_shipments SET status = 'blad' WHERE order_id = ?")->execute([$order['id']]);
        wsm_order_event($pdo, (int) $order['id'], 'wysylka_blad', $msg, 'dpd');
        return [null, $msg];
    }

    $pdo->prepare("UPDATE wsm_shipments
                      SET shipment_id = ?, tracking_number = ?, status = 'utworzona'
                    WHERE order_id = ?")
        ->execute([$tracking, $tracking, $order['id']]);
    // Passe par le point unique : c'est LUI qui émet la facture ou l'e-paragon,
    // les envoie et les dépose au KSeF. Écrire l'état à la main ici ferait
    // partir le colis sans document, et sans une erreur nulle part.
    wsm_order_status_set($pdo, (int) $order['id'], 'wyslane', 'system');
    wsm_order_event($pdo, (int) $order['id'], 'wysylka_utworzona', $tracking, 'dpd');

    // Le client apprend le départ par la messagerie, pas en regardant un
    // back-office qu'il ne voit pas.
    if (function_exists('wsm_mail_auto')) {
        $fresh = wsm_order_by_id($pdo, (int) $order['id']);
        if ($fresh) wsm_mail_auto($pdo, 'wysylka', $fresh);
    }

    $st = $pdo->prepare("SELECT * FROM wsm_shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$order['id']]);
    return [$st->fetch() ?: null, null];
}

/**
 * L'ÉTIQUETTE — celle que DPD scanne, en PDF.
 *
 * Elle ne s'ouvre pas d'un simple lien : le service exige le login et le mot
 * de passe du compte. Les donner au navigateur reviendrait à les publier —
 * n'importe qui ouvrant les outils de développement pourrait expédier sur
 * notre compte. La console va donc chercher le PDF côté serveur et ne relaie
 * que le document.
 *
 * @return array [contenu binaire|null, type MIME, erreur|null]
 */
function wsm_dpd_label(array $order, string $format = 'A6'): array {
    $numer = trim((string) ($order['shipment']['tracking_number'] ?? ''));
    if ($numer === '') return [null, '', 'brak_numeru'];
    if (!wsm_dpd_enabled()) return [null, '', 'dpd_nieskonfigurowany'];

    $cli = wsm_dpd_client();
    if (!$cli) return [null, '', 'dpd_niedostepny'];

    // A6 est l'étiquette qui se colle ; A4 sert quand on n'a qu'une
    // imprimante de bureau et qu'on découpe.
    $page = strtoupper($format) === 'A4' ? 'A4' : 'LBL_PRINTER';

    try {
        $res = wsm_dpd_tab($cli->generateSpedLabelsV4([
            'dpdServicesParamsV1' => [
                'policy'  => 'STOP_ON_FIRST_ERROR',
                'session' => [
                    'sessionType' => 'DOMESTIC',
                    'packages'    => [['parcels' => [['waybill' => $numer]]]],
                ],
            ],
            'outputDocFormatV1'     => 'PDF',
            'outputDocPageFormatV1' => $page,
            'authDataV1'            => wsm_dpd_auth(),
        ]));
    } catch (Throwable $e) {
        return [null, '', 'dpd_soap: ' . mb_substr($e->getMessage(), 0, 180)];
    }

    // Le document arrive en base64, à une profondeur qui varie.
    $doc = '';
    array_walk_recursive($res, function ($v, $k) use (&$doc) {
        if ($doc === '' && in_array((string) $k, ['documentData', 'filedata', 'fileContent'], true)) {
            $doc = (string) $v;
        }
    });
    if ($doc === '') return [null, '', 'dpd_bez_etykiety'];

    $bin = base64_decode($doc, true);
    // Un PDF commence par %PDF. Si ce n'est pas le cas, DPD a renvoyé autre
    // chose — un message d'erreur, le plus souvent — et l'ouvrir dans un
    // lecteur donnerait une page blanche sans explication.
    if ($bin === false || !str_starts_with($bin, '%PDF')) return [null, '', 'dpd_zla_etykieta'];
    return [$bin, 'application/pdf', null];
}

/**
 * L'ENLÈVEMENT. Commande le passage du chauffeur et rend le protocole —
 * le papier que le chauffeur signe, et la seule preuve que les colis lui ont
 * bien été remis le jour où l'un d'eux manque à l'arrivée.
 *
 * @param string[] $numery numéros de suivi à faire enlever
 * @return array [protocole PDF|null, erreur|null]
 */
function wsm_dpd_pickup(array $numery, string $date = '', string $de = '09:00', string $a = '17:00'): array {
    $numery = array_values(array_filter(array_map('trim', $numery)));
    if (!$numery) return [null, 'brak_paczek'];
    if (!wsm_dpd_enabled()) return [null, 'dpd_nieskonfigurowany'];

    $cli = wsm_dpd_client();
    if (!$cli) return [null, 'dpd_niedostepny'];

    $c = wsm_dpd_cfg();
    try {
        $res = wsm_dpd_tab($cli->packagesPickupCallV4([
            'dpdPickupCallParamsV3' => [
                'pickupDate'      => $date !== '' ? $date : date('Y-m-d'),
                'pickupTimeFrom'  => $de,
                'pickupTimeTo'    => $a,
                'orderType'       => 'DOMESTIC',
                'operationType'   => 'INSERT',
                'pickupCustomer'  => [
                    'customerName' => $c['sender_name'],
                    'customerPhone' => $c['sender_phone'],
                    'customerFullAddress' => $c['sender_address'] . ', '
                        . $c['sender_postcode'] . ' ' . $c['sender_city'],
                ],
                'waybills' => $numery,
            ],
            'authDataV1' => wsm_dpd_auth(),
        ]));
    } catch (Throwable $e) {
        return [null, 'dpd_soap: ' . mb_substr($e->getMessage(), 0, 180)];
    }

    $doc = '';
    array_walk_recursive($res, function ($v, $k) use (&$doc) {
        if ($doc === '' && in_array((string) $k, ['documentData', 'protocol', 'filedata'], true)) {
            $doc = (string) $v;
        }
    });
    if ($doc === '') {
        // L'enlèvement peut être accepté SANS protocole renvoyé. Ce n'est pas
        // une erreur : le chauffeur viendra. On le dit tel quel.
        return [null, ''];
    }
    $bin = base64_decode($doc, true);
    return [$bin !== false && str_starts_with($bin, '%PDF') ? $bin : null, ''];
}
