<?php
// ============================================================================
//  commerce.php — données exigées par tpay.com (paiement) et InPost (envoi).
//
//  Ce fichier ne parle à aucune des deux API : il garantit que la BASE
//  contient, et dans le bon format, tout ce que ces intégrations réclameront.
//  Rien ne sert de câbler tpay si le client n'a pas d'e-mail, ni InPost si le
//  produit n'a ni poids ni dimensions.
//
//  ── tpay (transaction + facture) ──────────────────────────────────────────
//    payeur   : e-mail (OBLIGATOIRE), nom, téléphone
//    facture  : NIP (entreprise PL), raison sociale, adresse complète
//    montant  : TVA par produit (PL : 0 / 5 / 8 / 23 %)
//
//  ── InPost ShipX (Paczkomat ou coursier) ──────────────────────────────────
//    destinataire : prénom, nom, e-mail, téléphone à 9 chiffres (OBLIGATOIRE)
//    Paczkomat    : code du point (target_point, ex. KRA010)
//    coursier     : rue, numéro, code postal NN-NNN, ville, pays
//    colis        : poids + dimensions, ou gabarit A / B / C
// ============================================================================
declare(strict_types=1);

/** Gabarits de casier InPost, en millimètres (côté ouverture × profondeur). */
const WSM_INPOST_TEMPLATES = [
    'A' => ['max' => [80, 380, 640],  'label' => 'A — 8 × 38 × 64 cm'],
    'B' => ['max' => [190, 380, 640], 'label' => 'B — 19 × 38 × 64 cm'],
    'C' => ['max' => [410, 380, 640], 'label' => 'C — 41 × 38 × 64 cm'],
];
const WSM_INPOST_MAX_WEIGHT_G = 25000;          // 25 kg par colis
const WSM_VAT_RATES_PL = [0.0, 0.05, 0.08, 0.23];

/** Normalise un téléphone polonais en 9 chiffres (sans +48, espaces, tirets). */
function wsm_normalize_phone(string $raw): string {
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (str_starts_with($d, '0048')) $d = substr($d, 4);
    elseif (str_starts_with($d, '48') && strlen($d) === 11) $d = substr($d, 2);
    return $d;
}

/** InPost exige 9 chiffres pour un numéro polonais. */
function wsm_valid_phone(string $raw): bool {
    return preg_match('/^\d{9}$/', wsm_normalize_phone($raw)) === 1;
}

/** Code postal polonais : NN-NNN. Accepte « 00123 » et le reformate. */
function wsm_normalize_postcode(string $raw): string {
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    return strlen($d) === 5 ? substr($d, 0, 2) . '-' . substr($d, 2) : trim($raw);
}

function wsm_valid_postcode(string $raw): bool {
    // On teste la saisie TELLE QUELLE : normaliser d'abord « réparerait »
    // « 00026x » en « 00-026 » et laisserait passer une erreur de frappe.
    return preg_match('/^\d{2}-?\d{3}$/', trim($raw)) === 1;
}

/**
 * NIP polonais : 10 chiffres avec somme de contrôle pondérée.
 * Un NIP faux fait rejeter la facture — on le vérifie à la saisie, pas après.
 */
function wsm_valid_nip(string $raw): bool {
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($d) !== 10) return false;
    $w = [6, 5, 7, 2, 3, 4, 5, 6, 7];
    $sum = 0;
    for ($i = 0; $i < 9; $i++) $sum += ((int) $d[$i]) * $w[$i];
    $check = $sum % 11;
    return $check !== 10 && $check === (int) $d[9];
}

function wsm_normalize_nip(string $raw): string {
    return preg_replace('/\D+/', '', $raw) ?? '';
}

/**
 * Préfixes pays reconnus par VIES : les 27 États membres — la Grèce s'écrit EL
 * et non GR — plus XI pour l'Irlande du Nord depuis le Brexit.
 */
const WSM_VAT_EU_COUNTRIES = [
    'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
    'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
];

/**
 * Numéro de TVA intracommunautaire : 2 lettres pays + 2 à 12 caractères.
 *
 * Le code pays est vérifié contre la vraie liste, pas contre « deux lettres » :
 * « PASUNNUMERO » a beau ressembler à un numéro, PA n'est pas un État membre —
 * et une interrogation VIES pour un pays qui n'y participe pas ne peut rien
 * donner d'autre qu'une erreur.
 */
function wsm_valid_vat_eu(string $raw): bool {
    $v = strtoupper(preg_replace('/[\s.-]+/', '', $raw) ?? '');
    if (preg_match('/^[A-Z]{2}[A-Z0-9]{2,12}$/', $v) !== 1) return false;
    return in_array(substr($v, 0, 2), WSM_VAT_EU_COUNTRIES, true);
}

/** Code de Paczkomat : 3 lettres + chiffres/lettres (ex. KRA010, WAW01M). */
function wsm_valid_inpost_point(string $raw): bool {
    return preg_match('/^[A-Z]{3}[0-9A-Z]{2,6}$/', strtoupper(trim($raw))) === 1;
}

/**
 * Gabarit InPost déduit des dimensions (mm). Renvoie 'A', 'B', 'C' — ou ''
 * si le colis dépasse le plus grand casier (⇒ envoi par coursier).
 */
function wsm_inpost_template(int $l, int $w, int $h): string {
    if ($l <= 0 || $w <= 0 || $h <= 0) return '';
    $dims = [$l, $w, $h];
    sort($dims);                                   // plus petite arête = hauteur d'ouverture
    foreach (WSM_INPOST_TEMPLATES as $key => $t) {
        $max = $t['max']; sort($max);
        if ($dims[0] <= $max[0] && $dims[1] <= $max[1] && $dims[2] <= $max[2]) return $key;
    }
    return '';
}

/**
 * Valide et normalise un client. Renvoie [données, erreurs].
 * Les exigences dépendent du type : une entreprise doit avoir un NIP valide,
 * un particulier un prénom et un nom (InPost impose un destinataire nommé).
 */
function wsm_validate_client(array $in, bool $partial = false): array {
    $e = [];
    $out = [];

    $type = strtolower(trim((string) ($in['client_type'] ?? 'firma')));
    if (!in_array($type, ['firma', 'osoba'], true)) $e['client_type'] = 'firma|osoba';
    $out['client_type'] = $type;

    $email = trim((string) ($in['email'] ?? ''));
    if ($email === '' && !$partial) $e['email'] = 'wymagany (tpay + InPost)';
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $e['email'] = 'nieprawidłowy';
    $out['email'] = strtolower($email);

    $phone = (string) ($in['phone'] ?? '');
    if ($phone === '' && !$partial) $e['phone'] = 'wymagany (InPost)';
    elseif ($phone !== '' && !wsm_valid_phone($phone)) $e['phone'] = '9 cyfr';
    $out['phone'] = $phone === '' ? '' : wsm_normalize_phone($phone);

    if ($type === 'firma') {
        $nip = (string) ($in['nip'] ?? '');
        if ($nip === '' && !$partial) $e['nip'] = 'wymagany dla firmy';
        elseif ($nip !== '' && !wsm_valid_nip($nip)) $e['nip'] = 'błędna suma kontrolna';
        $out['nip'] = $nip === '' ? '' : wsm_normalize_nip($nip);
    } else {
        $out['nip'] = '';
        foreach (['first_name' => 'imię', 'last_name' => 'nazwisko'] as $k => $lbl) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '' && !$partial) $e[$k] = "$lbl wymagane (InPost)";
            $out[$k] = $v;
        }
    }
    $out['first_name'] = trim((string) ($in['first_name'] ?? ($out['first_name'] ?? '')));
    $out['last_name']  = trim((string) ($in['last_name']  ?? ($out['last_name']  ?? '')));

    $vat = trim((string) ($in['vat_eu'] ?? ''));
    if ($vat !== '' && !wsm_valid_vat_eu($vat)) $e['vat_eu'] = 'format VIES (np. PL1234567890)';
    $out['vat_eu'] = strtoupper(preg_replace('/[\s.-]+/', '', $vat) ?? '');

    $pc = (string) ($in['bill_postcode'] ?? '');
    if ($pc !== '' && !wsm_valid_postcode($pc)) $e['bill_postcode'] = 'format NN-NNN';
    $out['bill_postcode'] = $pc === '' ? '' : wsm_normalize_postcode($pc);

    foreach (['bill_street', 'bill_building', 'bill_city'] as $k) {
        $out[$k] = trim((string) ($in[$k] ?? ''));
    }
    $cc = strtoupper(trim((string) ($in['bill_country'] ?? 'PL')));
    $out['bill_country'] = preg_match('/^[A-Z]{2}$/', $cc) ? $cc : 'PL';

    return [$out, $e];
}

/** Valide et normalise un point de livraison (Paczkomat ou adresse coursier). */
function wsm_validate_point(array $in, bool $partial = false): array {
    $e = [];
    $out = [];

    $method = strtolower(trim((string) ($in['delivery_method'] ?? 'inpost_locker')));
    if (!in_array($method, ['inpost_locker', 'inpost_courier', 'own'], true)) {
        $e['delivery_method'] = 'inpost_locker|inpost_courier|own';
    }
    $out['delivery_method'] = $method;

    $point = strtoupper(trim((string) ($in['inpost_point'] ?? '')));
    if ($method === 'inpost_locker') {
        if ($point === '' && !$partial) $e['inpost_point'] = 'kod paczkomatu wymagany';
        elseif ($point !== '' && !wsm_valid_inpost_point($point)) $e['inpost_point'] = 'np. KRA010';
    }
    $out['inpost_point'] = $point;

    // Adresse structurée : indispensable au coursier, utile partout ailleurs.
    $pc = (string) ($in['postcode'] ?? '');
    if ($method === 'inpost_courier' && $pc === '' && !$partial) $e['postcode'] = 'wymagany dla kuriera';
    elseif ($pc !== '' && !wsm_valid_postcode($pc)) $e['postcode'] = 'format NN-NNN';
    $out['postcode'] = $pc === '' ? '' : wsm_normalize_postcode($pc);

    foreach (['street', 'building', 'city'] as $k) {
        $v = trim((string) ($in[$k] ?? ''));
        if ($method === 'inpost_courier' && $v === '' && !$partial) $e[$k] = 'wymagane dla kuriera';
        $out[$k] = $v;
    }
    $cc = strtoupper(trim((string) ($in['country'] ?? 'PL')));
    $out['country'] = preg_match('/^[A-Z]{2}$/', $cc) ? $cc : 'PL';

    $ph = (string) ($in['contact_phone'] ?? '');
    if ($ph !== '' && !wsm_valid_phone($ph)) $e['contact_phone'] = '9 cyfr';
    $out['contact_phone'] = $ph === '' ? '' : wsm_normalize_phone($ph);

    $em = trim((string) ($in['contact_email'] ?? ''));
    if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) $e['contact_email'] = 'nieprawidłowy';
    $out['contact_email'] = strtolower($em);

    return [$out, $e];
}

/** Valide et normalise les champs d'expédition / fiscalité d'un produit. */
function wsm_validate_product_logistics(array $in): array {
    $e = [];
    $out = [];

    $vat = $in['vat_rate'] ?? 0.23;
    $vat = is_numeric($vat) ? (float) $vat : -1;
    if ($vat > 1) $vat = $vat / 100;                 // « 23 » est accepté comme 23 %
    if (!in_array(round($vat, 2), WSM_VAT_RATES_PL, true)) $e['vat_rate'] = 'PL : 0 / 5 / 8 / 23 %';
    $out['vat_rate'] = round($vat, 2);

    $w = (int) ($in['weight_g'] ?? 0);
    if ($w < 0) $e['weight_g'] = 'dodatnia';
    if ($w > WSM_INPOST_MAX_WEIGHT_G) $e['weight_g'] = 'maks. 25 kg (InPost)';
    $out['weight_g'] = $w;

    foreach (['length_mm', 'width_mm', 'height_mm'] as $k) {
        $v = (int) ($in[$k] ?? 0);
        if ($v < 0) $e[$k] = 'dodatnia';
        $out[$k] = $v;
    }

    // Gabarit : imposé si fourni et cohérent, sinon déduit des dimensions.
    $tpl = strtoupper(trim((string) ($in['parcel_template'] ?? '')));
    if ($tpl !== '' && !isset(WSM_INPOST_TEMPLATES[$tpl])) $e['parcel_template'] = 'A|B|C';
    $out['parcel_template'] = $tpl !== ''
        ? $tpl
        : wsm_inpost_template($out['length_mm'], $out['width_mm'], $out['height_mm']);

    $out['sku'] = trim((string) ($in['sku'] ?? ''));
    $ean = preg_replace('/\D+/', '', (string) ($in['ean'] ?? '')) ?? '';
    if ($ean !== '' && !in_array(strlen($ean), [8, 13], true)) $e['ean'] = 'EAN-8 lub EAN-13';
    $out['ean'] = $ean;

    return [$out, $e];
}

/**
 * LE RAYON QUI PORTE CE NOM, quitte à le créer ou à le rallumer.
 *
 * POURQUOI CETTE FONCTION EXISTE. Le nom d'une catégorie arrive de deux
 * endroits — le panneau « Kategorie » et le champ « albo wpisz nową kategorię »
 * de la fiche produit — et les deux doivent traiter les mêmes trois cas de la
 * même façon. Écrite deux fois, la règle aurait divergé au premier correctif,
 * et la moitié des gens auraient eu l'ancienne.
 *
 * TROIS CAS, ET LE DEUXIÈME EST CELUI QU'ON OUBLIE :
 *
 *  · le nom est libre → on crée le rayon ;
 *  · le nom existe mais le rayon est ÉTEINT → on le rallume. C'est le cas de
 *    Pieczywo, Lody, Katering et des deux autres, désactivés au ménage :
 *    répondre « ce nom existe déjà » sans rien faire serait une impasse sur un
 *    rayon qu'on a sous les yeux et qu'on ne peut pas prendre ;
 *  · le nom existe et le rayon est allumé → on le rend tel quel, sans rien
 *    toucher.
 *
 * LA COMPARAISON SE FAIT EN PHP, PAS EN SQL. MySQL compare sans tenir compte
 * de la casse, SQLite en tient compte dès qu'il y a un accent : « Mąka » et
 * « mąka » créeraient deux rayons sur un serveur et un seul sur l'autre.
 *
 * On ne supprime jamais : wsm_products.category_id est NOT NULL.
 *
 * @return array{0:int,1:string} identifiant (0 si refus), et ce qui s'est
 *         passé : 'cree' | 'rallume' | 'trouve' | 'refuse'
 */
/**
 * Range une catégorie sous une autre (ou au premier niveau avec 0).
 *
 * UN SEUL NIVEAU D'IMBRICATION : le parent doit être lui-même de premier
 * niveau, et une catégorie qui range déjà des sous-catégories ne peut pas
 * devenir sous-catégorie à son tour. Sinon la vitrine, qui montre deux
 * étages, perdrait le troisième — et ses produits avec.
 */
function wsm_kat_rodzic(PDO $pdo, int $id, int $parent): bool {
    if ($parent === $id) return false;
    if ($parent > 0) {
        $st = $pdo->prepare("SELECT parent_id FROM wsm_categories WHERE id = ?");
        $st->execute([$parent]);
        $pp = $st->fetchColumn();
        if ($pp === false || (int) $pp !== 0) return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_categories WHERE parent_id = ?");
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) return false;
    }
    $pdo->prepare("UPDATE wsm_categories SET parent_id = ? WHERE id = ?")->execute([$parent, $id]);
    return true;
}

function wsm_categorie_assure(PDO $pdo, string $nom): array {
    $nom = trim($nom);
    if ($nom === '' || mb_strlen($nom) > 80) return [0, 'refuse'];

    foreach ($pdo->query("SELECT id, name, active FROM wsm_categories") as $r) {
        if (mb_strtolower(trim((string) $r['name'])) !== mb_strtolower($nom)) continue;
        $id = (int) $r['id'];
        if ((int) $r['active']) return [$id, 'trouve'];
        $pdo->prepare("UPDATE wsm_categories SET active = 1 WHERE id = ?")->execute([$id]);
        return [$id, 'rallume'];
    }
    try {
        $pdo->prepare("INSERT INTO wsm_categories (name, sort_order, active) VALUES (?,?,1)")
            ->execute([$nom, (int) $pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM wsm_categories")->fetchColumn()]);
        return [(int) $pdo->lastInsertId(), 'cree'];
    } catch (Throwable $e) {
        // Le nom est unique en base. Une exception qui remonte jusqu'à l'écran
        // rendrait une page 500 — la saisie du produit disparaîtrait avec.
        return [0, 'refuse'];
    }
}
