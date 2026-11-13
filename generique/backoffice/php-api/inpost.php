<?php
// ============================================================================
//  inpost.php — expédition des commandes par InPost (ShipX).
//
//  Deux services : Paczkomat (le colis part vers un casier, identifié par son
//  code — KRA010) et coursier (adresse complète). Le reste est commun : un
//  destinataire nommé, un téléphone à 9 chiffres, un poids et un gabarit.
//
//  La charge utile ShipX est construite ici et exposée telle quelle au
//  back-office. Sans jeton configuré, aucune expédition n'est créée : la
//  commande reste `do_utworzenia` et le colis se prépare à la main. Une
//  boutique qui vend sans pouvoir expédier reste une boutique qui vend.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/shop.php';

function wsm_inpost_cfg(): array {
    $c = wsm_config()['inpost'] ?? [];
    return [
        'token'           => (string) ($c['token'] ?? ''),
        'organization_id' => (string) ($c['organization_id'] ?? ''),
        'geowidget_token' => (string) ($c['geowidget_token'] ?? ''),
        'sending_method'  => (string) ($c['sending_method'] ?? 'parcel_locker'),
        'sandbox'         => !empty($c['sandbox']),
    ];
}

/** Peut-on créer une expédition ? (jeton ShipX + organisation) */
function wsm_inpost_enabled(): bool {
    $c = wsm_inpost_cfg();
    return $c['token'] !== '' && $c['organization_id'] !== '';
}

/** Le sélecteur de Paczkomat n'est affiché que si son jeton public existe. */
function wsm_inpost_geowidget_token(): string {
    return wsm_inpost_cfg()['geowidget_token'];
}

// ---------------------------------------------------------------------------
//  CE QUE LE JETON DU SÉLECTEUR DIT DE LUI-MÊME
//
//  « Brak dostępu, sprawdź czy token został wygenerowany dla odpowiedniej
//  witryny. » — c'est le sélecteur d'InPost qui écrit ça, dans la caisse, à la
//  place de la carte. Le client ne peut plus choisir son Paczkomat.
//
//  La phrase ne dit pas CE QUI cloche, et c'est là tout le problème : la clé
//  du sélecteur est délivrée POUR UN SITE, et elle a aussi une date de fin.
//  Trois causes donnent donc exactement le même écran :
//
//   · la clé a été créée pour un autre domaine que celui qui sert la boutique ;
//   · la clé a expiré ;
//   · on a collé la clé SERVEUR (ShipX) à la place de la clé du sélecteur.
//
//  On ne peut pas la vérifier auprès d'InPost — mais on n'en a pas besoin :
//  c'est un JWT, et un JWT PORTE SES PROPRES DÉCLARATIONS EN CLAIR. On les
//  lit, on les met à côté de l'adresse réelle de la boutique, et la console
//  dit laquelle des trois causes s'applique, au lieu de renvoyer l'exploitant
//  vers un message qui ne nomme rien.
//
//  ON NE VÉRIFIE PAS LA SIGNATURE, et ce n'est pas un oubli : on ne se sert de
//  rien de ce qu'on lit pour autoriser quoi que ce soit. On l'affiche à celui
//  qui a collé la clé, pour qu'il voie ce qu'il a collé.
// ---------------------------------------------------------------------------

/** Le corps d'un JWT, décodé. [] si ce n'en est pas un. */
function wsm_jwt_charge(string $jeton): array {
    $p = explode('.', trim($jeton));
    if (count($p) !== 3) return [];
    $b = strtr($p[1], '-_', '+/');                     // base64url → base64
    $b = str_pad($b, (int) (ceil(strlen($b) / 4) * 4), '=');
    $json = base64_decode($b, true);
    if ($json === false) return [];
    $d = json_decode($json, true);
    return is_array($d) ? $d : [];
}

/**
 * Ce qu'on peut dire du jeton du sélecteur sans appeler personne.
 *
 * @return array{present:bool, jwt:bool, exp:?int, wygasl:bool, domeny:string[]}
 */
function wsm_inpost_geo_diag(): array {
    $t = trim(wsm_inpost_geowidget_token());
    $vide = ['present' => false, 'jwt' => false, 'exp' => null, 'wygasl' => false, 'domeny' => []];
    if ($t === '' || strtolower($t) === 'xxxx') return $vide;

    $charge = wsm_jwt_charge($t);
    if (!$charge) return ['present' => true, 'jwt' => false, 'exp' => null,
                          'wygasl' => false, 'domeny' => []];

    $exp = isset($charge['exp']) && is_numeric($charge['exp']) ? (int) $charge['exp'] : null;

    // LES DOMAINES : on ne devine pas le nom du champ. InPost peut l'appeler
    // comme il veut, et le nom peut changer sans nous prévenir. On parcourt
    // donc toute la charge et on retient ce qui RESSEMBLE à un hôte. Mieux
    // vaut montrer un champ de trop que rater le seul qui comptait.
    $hotes = [];
    $visite = function ($v) use (&$visite, &$hotes): void {
        if (is_array($v)) { foreach ($v as $x) $visite($x); return; }
        if (!is_string($v)) return;
        foreach (preg_split('/[\s,;]+/', $v) ?: [] as $mot) {
            $mot = trim($mot);
            if ($mot === '') continue;
            $h = parse_url($mot, PHP_URL_HOST) ?: $mot;
            $h = strtolower(preg_replace('/:\d+$/', '', (string) $h));
            // Un hôte : un nom pointé, ou une adresse IP. Le reste (« RS256 »,
            // un identifiant, une date) n'en est pas un.
            if (preg_match('/^(\*\.)?([a-z0-9-]+\.)+[a-z]{2,}$/', $h)
                || filter_var($h, FILTER_VALIDATE_IP)) {
                $hotes[$h] = true;
            }
        }
    };
    $visite($charge);

    return ['present' => true, 'jwt' => true, 'exp' => $exp,
            'wygasl' => $exp !== null && $exp < time(), 'domeny' => array_keys($hotes)];
}

/**
 * Le verdict, en une phrase polonaise destinée à l'exploitant.
 *
 * @param string $hote  l'hôte qui sert réellement la boutique (HTTP_HOST)
 * @return array{0:string, 1:string}  [état 'ok'|'uwaga'|'zle', phrase]
 */
function wsm_inpost_geo_verdict(string $hote): array {
    $d = wsm_inpost_geo_diag();
    $hote = strtolower(preg_replace('/:\d+$/', '', trim($hote)));

    if (!$d['present']) return ['uwaga', 'Brak tokenu — mapa się nie pokazuje, klient wpisuje kod ręcznie.'];
    if (!$d['jwt']) {
        return ['zle', 'To nie wygląda na token Geowidgetu (nie jest to JWT). '
                     . 'Czy nie wkleiłeś tu tokenu serwerowego ShipX?'];
    }
    if ($d['wygasl']) {
        return ['zle', 'Token wygasł ' . date('Y-m-d', (int) $d['exp'])
                     . ' — to dlatego mapa odpowiada „Brak dostępu”. Wygeneruj nowy.'];
    }
    if ($d['domeny']) {
        // La comparaison qui tranche : le jeton nomme des sites, la boutique
        // en sert un. On accepte le joker « *.exemple.pl ».
        foreach ($d['domeny'] as $dm) {
            $ok = $dm === $hote
                || (str_starts_with($dm, '*.') && str_ends_with($hote, substr($dm, 1)));
            if ($ok) {
                return ['ok', 'Token ważny dla ' . $dm . ' — zgadza się z adresem sklepu.'
                            . ($d['exp'] ? ' Wygasa ' . date('Y-m-d', (int) $d['exp']) . '.' : '')];
            }
        }
        return ['zle', 'Token wygenerowano dla: ' . implode(', ', $d['domeny'])
                     . '. Sklep działa pod adresem ' . $hote
                     . ' — dlatego mapa odpowiada „Brak dostępu”. '
                     . 'Wygeneruj token dla tego adresu w panelu InPost.'];
    }
    // Un JWT valide qui ne nomme aucun site : on ne prétend pas savoir.
    return ['uwaga', 'Token wygląda poprawnie, ale nie wymienia żadnej witryny.'
                   . ($d['exp'] ? ' Wygasa ' . date('Y-m-d', (int) $d['exp']) . '.' : '')
                   . ' Jeśli mapa pisze „Brak dostępu”, sprawdź w panelu InPost, '
                   . 'dla jakiego adresu klucz został wydany (' . $hote . ').'];
}

function wsm_inpost_base(): string {
    return wsm_inpost_cfg()['sandbox'] ? 'https://sandbox-api-shipx-pl.easypack24.net' : 'https://api-shipx-pl.easypack24.net';
}

/**
 * Charge utile ShipX pour une commande. Construite même quand l'intégration
 * est éteinte : le back-office l'affiche, et on voit tout de suite ce qui
 * manquerait avant d'avoir payé un jeton.
 */
function wsm_inpost_payload(array $order): array {
    $c = wsm_inpost_cfg();
    $locker = $order['delivery_method'] === 'inpost_locker';

    $receiver = [
        'first_name'   => $order['first_name'] ?: '—',
        'last_name'    => $order['last_name'] ?: '—',
        'email'        => $order['email'],
        'phone'        => $order['phone'],
    ];
    if (!$locker) {
        $receiver['address'] = [
            'street'       => $order['ship']['street'] ?? '',
            'building_number' => $order['ship']['building'] ?? '',
            'city'         => $order['ship']['city'] ?? '',
            'post_code'    => $order['ship']['postcode'] ?? '',
            'country_code' => $order['ship']['country'] ?? 'PL',
        ];
    }
    if (($order['company'] ?? '') !== '') $receiver['company_name'] = $order['company'];

    $parcel = ['id' => 'small'];
    $tpl = strtoupper((string) ($order['parcel_template'] ?? ''));
    if ($tpl !== '') {
        $parcel = ['template' => strtolower($tpl)];               // 'a' | 'b' | 'c'
    }
    if (($order['weight_g'] ?? 0) > 0) {
        $parcel['weight'] = ['amount' => round($order['weight_g'] / 1000, 3), 'unit' => 'kg'];
    }

    $payload = [
        'receiver'       => $receiver,
        'parcels'        => [$parcel],
        'service'        => $locker ? 'inpost_locker_standard' : 'inpost_courier_standard',
        'reference'      => $order['code'],
        'comments'       => mb_substr((string) ($order['note'] ?? ''), 0, 100),
        'custom_attributes' => [],
    ];
    if ($locker) {
        $payload['custom_attributes'] = [
            'target_point'  => $order['inpost_point'],
            'sending_method' => $c['sending_method'],
        ];
    }
    // Paiement à la livraison non utilisé : tout est encaissé par tpay avant
    // expédition. On l'écrit noir sur blanc pour qu'aucun COD ne s'invite.
    $payload['cod'] = null;
    return $payload;
}

/** Ce qui manque encore pour que ShipX accepte l'expédition. */
function wsm_inpost_blockers(array $order): array {
    $out = [];
    if (($order['phone'] ?? '') === '' || !wsm_valid_phone((string) $order['phone'])) $out[] = 'telefon';
    if (($order['email'] ?? '') === '') $out[] = 'e-mail';
    if (($order['first_name'] ?? '') === '' && ($order['company'] ?? '') === '') $out[] = 'odbiorca';
    if (($order['weight_g'] ?? 0) <= 0) $out[] = 'waga';
    if ($order['delivery_method'] === 'inpost_locker') {
        if (($order['inpost_point'] ?? '') === '') $out[] = 'paczkomat';
    } else {
        foreach (['street', 'building', 'postcode', 'city'] as $k) {
            if (($order['ship'][$k] ?? '') === '') $out[] = 'adres.' . $k;
        }
    }
    return $out;
}

/**
 * Crée l'expédition chez InPost. Renvoie [ligne d'expédition, erreur|null].
 * Sans jeton, la ligne reste `do_utworzenia` — c'est un état d'attente, pas
 * un échec : la commande est payée et le colis part à la main.
 */
/**
 * LE TRANSPORT SHIPX, REMPLACABLE.
 *
 * Sans lui, aucun test ne peut eprouver ce qui compte : un jeton perime, une
 * organisation qui n'est pas la notre, un colis passe en « doreczona ». Ce sont
 * exactement les cas qu'on ne peut pas provoquer a volonte — et exactement ceux
 * qui arrivent un mardi matin.
 *
 * @return callable(string $methode, string $chemin, ?array $corps): array{0:int,1:mixed}
 */
function wsm_inpost_transport(?callable $set = null): callable {
    static $fn = null;
    if ($set !== null) { $fn = $set; }
    return $fn ?? 'wsm_inpost_http';
}

/** @return array{0:int,1:mixed} [code HTTP, corps decode] */
function wsm_inpost_http(string $methode, string $chemin, ?array $corps = null): array {
    $c  = wsm_inpost_cfg();
    $ch = curl_init(wsm_inpost_base() . $chemin);
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CUSTOMREQUEST  => $methode,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $c['token'],
            'Content-Type: application/json', 'Accept: application/json',
        ],
    ];
    if ($corps !== null) $opt[CURLOPT_POSTFIELDS] = json_encode($corps, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opt);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $raw, true)];
}

/**
 * « EST-CE QUE CA MARCHE ? », repondu AVANT la premiere vraie commande.
 *
 * C'est la piece qui manquait pour finir l'integration. Jusqu'ici, coller un
 * jeton dans Ustawienia ne donnait aucun retour : on l'apprenait le jour ou un
 * client avait paye, quand la creation du colis echouait — commande encaissee,
 * colis inexistant, et personne devant l'ecran a ce moment-la.
 *
 * ON INTERROGE L'ORGANISATION, pas une route quelconque : c'est le seul appel
 * qui eprouve LES DEUX reglages a la fois. Un jeton juste avec un mauvais
 * numero d'organisation passe tous les tests de jeton et ne cree aucun colis.
 *
 * @return array{0:string,1:string} [etat 'ok'|'uwaga'|'zle', phrase polonaise]
 */
function wsm_inpost_diag(): array {
    $c = wsm_inpost_cfg();
    if ($c['token'] === '' && $c['organization_id'] === '') {
        return ['zle', 'Brak tokenu i numeru organizacji — wpisz je w Ustawieniach.'];
    }
    if ($c['token'] === '')           return ['zle', 'Brak tokenu ShipX.'];
    if ($c['organization_id'] === '') return ['zle', 'Brak numeru organizacji.'];

    [$code, $res] = (wsm_inpost_transport())(
        'GET', '/v1/organizations/' . rawurlencode($c['organization_id']), null);
    $ou = $c['sandbox'] ? 'sandbox' : 'produkcja';

    if ($code === 401 || $code === 403) {
        return ['zle', 'Token odrzucony przez ShipX (' . $code . '). Sprawdź, czy nie wygasł '
                     . 'i czy pochodzi ze środowiska „' . $ou . '".'];
    }
    if ($code === 404) {
        // LE PIEGE LE PLUS COURANT : un jeton de production avec un numero
        // d'organisation de sandbox, ou l'inverse. Les deux sont justes
        // separement, et ensemble ils ne font rien.
        return ['zle', 'ShipX nie zna organizacji ' . $c['organization_id'] . ' w środowisku „' . $ou
                     . '". Token i numer organizacji muszą pochodzić z tego samego środowiska.'];
    }
    if ($code < 200 || $code >= 300) {
        return ['uwaga', 'ShipX odpowiedział ' . $code . ' — spróbuj ponownie za chwilę.'];
    }
    $nom = trim((string) ($res['name'] ?? ''));
    return ['ok', 'Połączenie działa: ' . ($nom !== '' ? $nom . ' ' : '')
                . '(organizacja ' . $c['organization_id'] . ', środowisko „' . $ou . '").'];
}

/**
 * LA VIE DU COLIS APRES SON DEPART, rapatriee depuis ShipX.
 *
 * Sans ca, une commande restait « Wysłane » pour toujours : rien ne la faisait
 * passer a « Dostarczone », et rien ne signalait un retour. Le client, lui, le
 * voit sur le site du transporteur — la boutique etait le dernier endroit ou
 * l'information arrivait.
 *
 * ON NE FAIT AVANCER QUE CE QUI AVANCE. Un colis « doreczona » met la commande
 * a « dostarczone » ; tout le reste met a jour l'etat de l'expedition et ne
 * touche pas a la commande. Faire reculer une commande sur un statut mal
 * interprete serait pire que ne rien savoir.
 *
 * @return array{0:int,1:int} [expeditions regardees, commandes avancees]
 */
function wsm_inpost_sync(PDO $pdo, int $limite = 50): array {
    if (!wsm_inpost_enabled()) return [0, 0];
    $st = $pdo->prepare("SELECT s.*, o.status AS order_status
                           FROM wsm_shipments s
                           JOIN wsm_orders o ON o.id = s.order_id
                          WHERE s.shipment_id <> '' AND s.shipment_id IS NOT NULL
                            AND o.status IN ('wyslane', 'w_realizacji')
                          ORDER BY s.id DESC LIMIT " . max(1, min(200, $limite)));
    $st->execute();
    $vus = 0; $avances = 0;
    $maj = $pdo->prepare("UPDATE wsm_shipments SET status = ? WHERE id = ?");

    foreach ($st->fetchAll() as $sh) {
        [$code, $res] = (wsm_inpost_transport())(
            'GET', '/v1/shipments/' . rawurlencode((string) $sh['shipment_id']), null);
        $vus++;
        if ($code < 200 || $code >= 300 || !is_array($res)) continue;
        $etat = strtolower(trim((string) ($res['status'] ?? '')));
        if ($etat === '') continue;
        if ($etat !== (string) $sh['status']) {
            $maj->execute([$etat, (int) $sh['id']]);
            wsm_order_event($pdo, (int) $sh['order_id'], 'wysylka_status', $etat, 'inpost');
        }
        // « delivered » est le vocabulaire de ShipX ; on garde les deux
        // orthographes vues sur le terrain plutot que de parier sur une seule.
        if (in_array($etat, ['delivered', 'doreczona', 'doręczona'], true)
            && (string) $sh['order_status'] !== 'dostarczone') {
            wsm_order_status_set($pdo, (int) $sh['order_id'], 'dostarczone', 'inpost');
            $avances++;
        }
    }
    return [$vus, $avances];
}

function wsm_inpost_create(PDO $pdo, array $order): array {
    $blockers = wsm_inpost_blockers($order);
    if ($blockers) {
        return [null, 'brakujace_dane: ' . implode(', ', $blockers)];
    }
    if (!wsm_inpost_enabled()) {
        $pdo->prepare("UPDATE wsm_shipments SET status = 'oczekuje_na_konfiguracje' WHERE order_id = ?")
            ->execute([$order['id']]);
        return [null, 'inpost_nieskonfigurowany'];
    }

    $c = wsm_inpost_cfg();
    $ch = curl_init(wsm_inpost_base() . '/v1/organizations/' . rawurlencode($c['organization_id']) . '/shipments');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $c['token'],
            'Content-Type: application/json', 'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(wsm_inpost_payload($order), JSON_UNESCAPED_UNICODE),
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $res = json_decode((string) $raw, true);

    if ($code < 200 || $code >= 300 || empty($res['id'])) {
        $msg = 'shipx_' . $code . ': ' . mb_substr((string) ($res['message'] ?? $raw), 0, 180);
        $pdo->prepare("UPDATE wsm_shipments SET status = 'blad' WHERE order_id = ?")->execute([$order['id']]);
        wsm_order_event($pdo, (int) $order['id'], 'wysylka_blad', $msg, 'inpost');
        return [null, $msg];
    }

    $tracking = (string) ($res['tracking_number'] ?? '');
    $pdo->prepare("UPDATE wsm_shipments
                      SET shipment_id = ?, tracking_number = ?, status = 'utworzona'
                    WHERE order_id = ?")
        ->execute([(string) $res['id'], $tracking, $order['id']]);
    // Passe par le point unique : c'est LUI qui émet la facture ou l'e-paragon,
    // les envoie et les dépose au KSeF. Écrire l'état à la main ici ferait
    // partir le colis sans document, et sans une erreur nulle part.
    wsm_order_status_set($pdo, (int) $order['id'], 'wyslane', 'system');
    wsm_order_event($pdo, (int) $order['id'], 'wysylka_utworzona', $tracking, 'inpost');

    // Le client apprend le départ de sa paczka par la messagerie, pas en
    // regardant l'écran d'un back-office qu'il ne voit pas.
    if (function_exists('wsm_mail_auto')) {
        $fresh = wsm_order_by_id($pdo, (int) $order['id']);
        if ($fresh) wsm_mail_auto($pdo, 'wysylka', $fresh);
    }

    $st = $pdo->prepare("SELECT * FROM wsm_shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$order['id']]);
    return [$st->fetch() ?: null, null];
}

/**
 * L'étiquette du transporteur, en PDF.
 *
 * Elle ne s'ouvre pas d'un simple lien : ShipX exige le jeton porteur, qu'un
 * navigateur n'a pas et ne doit pas avoir. La console la récupère donc
 * elle-même et la relaie — c'est le seul moyen d'imprimer l'étiquette
 * OBLIGATOIRE (celle qui porte le code-barres) sans publier le jeton.
 *
 * On ne met pas le PDF en cache : une étiquette réimprimée doit être celle que
 * le transporteur reconnaît aujourd'hui, pas celle d'avant une modification
 * d'adresse.
 *
 * @param string $format 'pdf' ou 'zpl' · $size 'A6' ou 'A4'
 * @return array [contenu binaire|null, type MIME, erreur|null]
 */
function wsm_inpost_label(array $shipment, string $format = 'pdf', string $size = 'A6'): array {
    $sid = trim((string) ($shipment['shipment_id'] ?? ''));
    if ($sid === '') return [null, '', 'przesyłka nie została jeszcze utworzona w InPost'];
    if (!wsm_inpost_enabled()) return [null, '', 'inpost_nieskonfigurowany'];

    $format = in_array($format, ['pdf', 'zpl', 'epl'], true) ? $format : 'pdf';
    $size   = in_array($size, ['A6', 'A4'], true) ? $size : 'A6';

    $c  = wsm_inpost_cfg();
    $url = wsm_inpost_base() . '/v1/shipments/' . rawurlencode($sid) . '/label'
         . '?format=' . $format . '&type=' . ($size === 'A4' ? 'A6P' : 'normal');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $c['token'], 'Accept: application/pdf'],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($code < 200 || $code >= 300 || $raw === false || $raw === '') {
        // Le corps d'erreur de ShipX est du JSON ; on le remonte tel quel plutôt
        // que d'afficher « échec » et de laisser chercher.
        $j = json_decode((string) $raw, true);
        return [null, '', 'shipx_' . $code . ': ' . mb_substr((string) ($j['message'] ?? $raw), 0, 180)];
    }
    return [(string) $raw, $mime !== '' ? $mime : 'application/pdf', null];
}

/**
 * Le lien public de l'étiquette, tel que ShipX le publie parfois sur l'envoi.
 * Rangé sur l'expédition quand on le voit passer : il évite un aller-retour,
 * mais ne remplace pas wsm_inpost_label() — tous les comptes n'en ont pas.
 */
function wsm_inpost_remember_label(PDO $pdo, int $orderId, string $url): void {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return;
    $pdo->prepare("UPDATE wsm_shipments SET label_url = ? WHERE order_id = ?")->execute([$url, $orderId]);
}
