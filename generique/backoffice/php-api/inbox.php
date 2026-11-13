<?php
// ============================================================================
//  inbox.php — lire un message entrant et y reconnaître une commande.
//
//  Un mail n'est pas un bon de commande. Il est écrit à la main, en trois
//  langues, avec des fautes, des abréviations et des quantités exprimées de
//  dix façons. Toute la conception part de là :
//
//   1. ON PROPOSE, ON NE DÉCIDE PAS. Chaque produit reconnu revient avec sa
//      confiance, la ligne exacte d'où il vient et la quantité devinée. Rien
//      n'est commandé sans qu'un humain ait cliqué.
//   2. CE QUI N'EST PAS RECONNU EST MONTRÉ. Une ligne qui ressemble à une
//      demande mais qu'on n'a pas su rattacher est signalée telle quelle —
//      l'ignorer en silence ferait perdre des commandes sans qu'on le sache.
//   3. LE PRIX RESTE CELUI DU CATALOGUE. Le mail peut proposer un montant :
//      il n'est jamais retenu. C'est le moteur de prix qui décide, comme pour
//      une commande passée en ligne.
//
//  L'analyse est volontairement simple et lisible : SKU, EAN, puis nom
//  normalisé. Pas de devinette floue qui produirait des commandes fausses —
//  mieux vaut ne pas reconnaître que reconnaître de travers.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/shop.php';

/** Casse, accents polonais et ponctuation retirés : « Czekolada  70% » → « czekolada 70 ». */
function wsm_inbox_norm(string $s): string {
    $s = mb_strtolower(trim($s));
    $from = ['ą','ć','ę','ł','ń','ó','ś','ź','ż'];
    $to   = ['a','c','e','l','n','o','s','z','z'];
    $s = str_replace($from, $to, $s);
    $s = preg_replace('/[^a-z0-9 ]+/u', ' ', $s) ?? '';
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
}

/**
 * Le catalogue vu comme un index de recherche : par SKU, par EAN et par nom
 * normalisé, avec les mots significatifs du nom.
 */
function wsm_inbox_index(PDO $pdo): array {
    $rows = $pdo->query("SELECT id, nom, sku, ean, prix, stock, shop_visible
                           FROM wsm_products WHERE active = 1")->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $r) {
        $norm = wsm_inbox_norm((string) $r['nom']);
        $out[] = [
            'id' => (string) $r['id'], 'name' => (string) $r['nom'],
            'sku' => strtoupper(trim((string) ($r['sku'] ?? ''))),
            'ean' => trim((string) ($r['ean'] ?? '')),
            'norm' => $norm,
            'words' => array_values(array_filter(explode(' ', $norm), fn($w) => mb_strlen($w) >= 3)),
            'stock' => (int) $r['stock'], 'visible' => (int) $r['shop_visible'] === 1,
        ];
    }
    return $out;
}

/**
 * La quantité citée dans une ligne. On accepte les formes réellement
 * rencontrées : « 3 x », « x3 », « 3 szt », « 3 worki », « ilość: 3 ».
 * En l'absence de nombre, 1 — c'est la lecture la plus prudente.
 */
function wsm_inbox_qty(string $line): int {
    $l = mb_strtolower($line);
    $pats = [
        '/(\d{1,3})\s*(?:x|szt|sztuk|worki|worków|worek|kartony|kartonów|karton|opak)/u',
        '/(?:x|ilosc|ilość|qty|quantity)\s*[:=]?\s*(\d{1,3})/u',
        '/^\s*(\d{1,3})\s*[.)-]?\s+/u',
    ];
    foreach ($pats as $p) {
        if (preg_match($p, $l, $m)) {
            $n = (int) $m[1];
            if ($n >= 1 && $n <= 999) return $n;
        }
    }
    return 1;
}

/**
 * Analyse le corps d'un message et renvoie ce qu'on y reconnaît.
 *
 * @return array ['lines' => [ligne, produit|null, qty, comment], 'items' => [id=>qty], 'unknown' => [lignes]]
 */
function wsm_inbox_parse(PDO $pdo, string $body): array {
    $index = wsm_inbox_index($pdo);
    $found = [];
    $unknown = [];
    $items = [];

    foreach (preg_split('/\r?\n/', $body) ?: [] as $raw) {
        $line = trim($raw);
        if ($line === '' || mb_strlen($line) > 300) continue;
        $norm = wsm_inbox_norm($line);
        if ($norm === '') continue;

        $hit = null; $how = '';
        // 1. Le SKU ou l'EAN : sans ambiguïté, on s'arrête là.
        foreach ($index as $p) {
            if ($p['sku'] !== '' && str_contains(strtoupper($line), $p['sku'])) { $hit = $p; $how = 'SKU ' . $p['sku']; break; }
            if ($p['ean'] !== '' && str_contains($line, $p['ean']))             { $hit = $p; $how = 'EAN ' . $p['ean']; break; }
        }
        // 2. Le nom complet tel quel — et parmi les noms contenus dans la
        //    ligne, LE PLUS LONG. Sans ce tri, « Praliny orzechowe duże »
        //    tomberait sur « Praliny orzechowe » simplement parce qu'il vient
        //    en premier dans le catalogue : on livrerait le mauvais article
        //    en croyant avoir bien lu. À longueur égale, c'est une vraie
        //    ambiguïté et on refuse.
        if (!$hit) {
            $best = []; $bestLen = 0;
            foreach ($index as $p) {
                if ($p['norm'] === '' || !str_contains($norm, $p['norm'])) continue;
                $len = mb_strlen($p['norm']);
                if ($len > $bestLen) { $best = [$p]; $bestLen = $len; }
                elseif ($len === $bestLen) $best[] = $p;
            }
            if (count($best) === 1) { $hit = $best[0]; $how = 'pełna nazwa'; }
            elseif (count($best) > 1) {
                $unknown[] = ['line' => $line, 'why' => 'pasuje do kilku produktów — doprecyzuj'];
                continue;
            }
        }
        // 3. Tous les mots significatifs du nom présents dans la ligne — et
        //    un seul produit qui corresponde. Deux candidats : on renonce,
        //    parce qu'un mauvais produit coûte plus cher qu'un non-reconnu.
        if (!$hit) {
            $cands = [];
            foreach ($index as $p) {
                if (!$p['words']) continue;
                $ok = true;
                foreach ($p['words'] as $w) if (!str_contains($norm, $w)) { $ok = false; break; }
                if ($ok) $cands[] = $p;
            }
            if (count($cands) === 1) { $hit = $cands[0]; $how = 'słowa nazwy'; }
            elseif (count($cands) > 1) {
                $unknown[] = ['line' => $line, 'why' => 'pasuje do kilku produktów — doprecyzuj'];
                continue;
            }
        }

        if ($hit) {
            $qty = wsm_inbox_qty($line);
            $found[] = ['line' => $line, 'product' => $hit, 'qty' => $qty, 'how' => $how];
            $items[$hit['id']] = ($items[$hit['id']] ?? 0) + $qty;
        } elseif (preg_match('/\d/', $line) && mb_strlen($norm) > 8) {
            // Une ligne avec un nombre, assez longue pour être une demande :
            // on la montre plutôt que de la laisser disparaître.
            $unknown[] = ['line' => $line, 'why' => 'nie rozpoznano produktu'];
        }
    }
    return ['lines' => $found, 'items' => $items, 'unknown' => $unknown];
}

/** L'adresse d'un message entrant → le client connu, s'il existe. */
function wsm_inbox_client(PDO $pdo, string $email): ?array {
    $email = strtolower(trim($email));
    if ($email === '') return null;
    $st = $pdo->prepare("SELECT * FROM wsm_clients WHERE LOWER(email) = ? LIMIT 1");
    $st->execute([$email]);
    return $st->fetch() ?: null;
}

/**
 * Crée la commande proposée par un message. Elle passe par le MÊME moteur que
 * la boutique : mêmes prix, même TVA, même remise au poids, même traitement du
 * stock insuffisant. Une commande née d'un mail n'est pas une commande de
 * seconde classe.
 *
 * @return array [commande|null, erreurs]
 */
function wsm_inbox_create_order(PDO $pdo, array $msg, array $items, array $extra = []): array {
    $email = (string) ($msg['email'] ?? '');
    $client = wsm_inbox_client($pdo, $email);

    $lines = [];
    foreach ($items as $pid => $qty) {
        $q = (int) $qty;
        if ($q > 0) $lines[] = ['id' => (string) $pid, 'qty' => $q];
    }
    if (!$lines) return [null, ['items' => 'brak pozycji do zamówienia']];

    $nom = trim((string) ($extra['first_name'] ?? ($client['first_name'] ?? '')));
    $naz = trim((string) ($extra['last_name'] ?? ($client['last_name'] ?? '')));
    if ($nom === '' && $naz === '') { $nom = 'Klient'; $naz = 'z maila'; }

    $body = [
        'lang' => 'pl',
        // Paczkomat par défaut : c'est le mode qui exige le moins de données
        // qu'un mail ne contient pas, et le plus courant en Pologne.
        'delivery_method' => (string) ($extra['delivery_method'] ?? 'inpost_locker'),
        'inpost_point' => (string) ($extra['inpost_point'] ?? ($client['inpost_point'] ?? '')),
        'items' => $lines,
        'client_type' => (string) ($client['client_type'] ?? 'firma'),
        'email' => $email,
        'phone' => (string) ($extra['phone'] ?? ($client['phone'] ?? '')),
        'first_name' => $nom, 'last_name' => $naz,
        'company' => (string) ($extra['company'] ?? ($client['raison'] ?? '')),
        'nip' => (string) ($client['nip'] ?? ''),
        'vat_eu' => (string) ($client['vat_eu'] ?? ''),
        'invoice' => ($client['nip'] ?? '') !== '' ? 1 : 0,
        'consent_terms' => 1,                      // la commande vient d'une demande écrite du client
        'note' => 'Zamówienie z wiadomości e-mail #' . (int) ($msg['id'] ?? 0),
        'ship_street'   => (string) ($extra['ship_street'] ?? ($client['bill_street'] ?? '')),
        'ship_building' => (string) ($extra['ship_building'] ?? ($client['bill_building'] ?? '')),
        'ship_postcode' => (string) ($extra['ship_postcode'] ?? ($client['bill_postcode'] ?? '')),
        'ship_city'     => (string) ($extra['ship_city'] ?? ($client['bill_city'] ?? '')),
        'ship_country'  => (string) ($extra['ship_country'] ?? ($client['bill_country'] ?? 'PL')),
        'bill_street'   => (string) ($client['bill_street'] ?? ''),
        'bill_building' => (string) ($client['bill_building'] ?? ''),
        'bill_postcode' => (string) ($client['bill_postcode'] ?? ''),
        'bill_city'     => (string) ($client['bill_city'] ?? ''),
        'bill_country'  => (string) ($client['bill_country'] ?? 'PL'),
    ];
    return wsm_shop_create_order($pdo, $body);
}

/**
 * Enregistre un message entrant (collé à la main, ou relevé plus tard sur une
 * boîte IMAP). Le corps est conservé tel quel : c'est la pièce qui prouve ce
 * que le client a demandé.
 */
function wsm_inbox_store(PDO $pdo, string $from, string $subject, string $body, string $actor = ''): int {
    require_once __DIR__ . '/mail.php';
    return wsm_mail_queue($pdo, [
        'email'     => $from,
        'direction' => 'wejscie',
        'subject'   => $subject !== '' ? $subject : '(bez tematu)',
        'body'      => $body,
        'actor'     => $actor ?: 'skrzynka',
    ]);
}

/** Extrait une adresse d'un en-tête « Jan Kowalski <jan@example.com> ». */
function wsm_inbox_address(string $s): string {
    if (preg_match('/<([^>]+)>/', $s, $m)) $s = $m[1];
    $s = trim($s);
    return filter_var($s, FILTER_VALIDATE_EMAIL) ? strtolower($s) : '';
}
