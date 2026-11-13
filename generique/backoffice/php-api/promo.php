<?php
// ============================================================================
//  promo.php — les bons de réduction : ce qu'ils enlèvent, et ce qu'ils ne
//  peuvent jamais enlever.
//
//  CE QUI NE MARCHAIT PAS. La table `wsm_vouchers` existait depuis le premier
//  jour. Elle était LISTÉE dans la console et LUE nulle part ailleurs. Aucune
//  caisse n'a jamais accepté un code. C'est le même défaut que la remise
//  professionnelle : un objet qui a l'air d'agir et n'agit pas — et celui-là
//  se serait vu le jour où un client aurait tapé le code d'une campagne.
//
//  SIX RÈGLES, DANS L'ORDRE DE CE QU'ELLES COÛTENT SI ON LES OUBLIE :
//
//   1. UN BON NE REND JAMAIS D'ARGENT. Un bon de 50 zł sur un panier de 30 zł
//      enlève 30 zł, pas 50. Le total ne descend pas sous zéro et aucun avoir
//      n'est créé : sinon on doit de l'argent à quelqu'un qui n'a rien payé.
//
//   2. UN POURCENTAGE NE S'EMPILE PAS. Le palier au poids, le tarif
//      professionnel et un bon en pourcent répondent tous à « combien vous
//      prenez ». LE MEILLEUR DES TROIS s'applique. C'est déjà la règle entre
//      paliers, et entre palier et tarif pro.
//
//   3. UN MONTANT FIXE, LUI, S'APPLIQUE APRÈS — mais sur la MARCHANDISE
//      seule. Un bon ne paie pas le transporteur : le port est un débours,
//      pas une marge. Sauf le bon « livraison offerte », dont c'est l'objet.
//
//   4. LA TVA RESTE JUSTE. Une remise en montant se répartit sur les lignes
//      AU PRORATA et chaque ligne se re-ventile. Un panier mêlant 5 % (denrée)
//      et 23 % n'a pas un seul taux : retrancher en bloc fausserait la
//      facture, et une facture fausse se corrige devant l'administration.
//
//   5. UN BON À USAGE UNIQUE NE PEUT PAS ÊTRE UTILISÉ DEUX FOIS — et la
//      preuve ne se fait pas au devis. Deux onglets ouverts en même temps
//      passent tous les deux la validation. Le compteur se prend À LA
//      CRÉATION DE LA COMMANDE, par un UPDATE conditionnel dont on lit le
//      nombre de lignes touchées : c'est la base qui arbitre, pas nous.
//
//   6. UNE UTILISATION SE GRAVE. Quel bon, quelle commande, quel montant,
//      quand. Sans cette trace, personne ne peut dire ce qu'une campagne a
//      coûté — et un bon révoqué effacerait l'histoire des commandes qui
//      l'avaient légitimement utilisé.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

// wsm_split_vat() vit dans shop.php. Ce fichier s'en sert pour reventiler une
// ligne après remise, et ne peut PAS s'en passer : recopier la règle « net +
// TVA = TTC » ici en ferait deux versions, qui divergeraient un jour sur un
// arrondi. On la charge donc explicitement quand elle manque.
//
// Sans cette ligne, promo.php marchait dans la boutique — où shop.php est
// toujours chargé d'abord — et TOMBAIT partout ailleurs : la vérification de
// déploiement l'a fait tomber du premier coup, avec « Call to undefined
// function wsm_split_vat() ». Une dépendance accidentelle n'est pas une
// dépendance : c'est un ordre de chargement qu'on a eu de la chance d'avoir.
if (!function_exists('wsm_split_vat')) require_once __DIR__ . '/shop.php';

/** Les trois natures de bon. Rien d'autre n'est accepté. */
const WSM_PROMO_KINDS = ['procent', 'kwota', 'wysylka'];

/** Un pourcentage au-delà est une saisie fautive, pas une campagne. */
const WSM_PROMO_MAX_PCT = 50.0;

/** Longueur du code engendré. */
const WSM_PROMO_CODE_LEN = 8;

/** Les caractères du code : ni O/0 ni I/1, qu'on se dicte mal au téléphone. */
const WSM_PROMO_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/**
 * Les colonnes ajoutées à une table qui existait déjà en production.
 *
 * CREATE TABLE IF NOT EXISTS ne touche pas une table déjà créée : sans ceci,
 * la boutique du serveur chercherait des colonnes absentes et rendrait 500.
 */
function wsm_promo_ensure(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_vouchers')) { wsm_apply_schema($pdo); }
    wsm_ensure_columns($pdo, 'wsm_vouchers', [
        'kind'       => ["VARCHAR(20) NOT NULL DEFAULT 'procent'", "TEXT NOT NULL DEFAULT 'procent'"],
        'pct'        => ['DECIMAL(5,2) NOT NULL DEFAULT 0',        'REAL NOT NULL DEFAULT 0'],
        'kwota'      => ['INT NOT NULL DEFAULT 0',                 'INTEGER NOT NULL DEFAULT 0'],
        'min_gross'  => ['INT NOT NULL DEFAULT 0',                 'INTEGER NOT NULL DEFAULT 0'],
        'starts_at'  => ['DATETIME NULL DEFAULT NULL',             'TEXT DEFAULT NULL'],
        'ends_at'    => ['DATETIME NULL DEFAULT NULL',             'TEXT DEFAULT NULL'],
        'max_uses'   => ['INT NOT NULL DEFAULT 0',                 'INTEGER NOT NULL DEFAULT 0'],
        'per_email'  => ['INT NOT NULL DEFAULT 0',                 'INTEGER NOT NULL DEFAULT 0'],
        'used'       => ['INT NOT NULL DEFAULT 0',                 'INTEGER NOT NULL DEFAULT 0'],
        'active'     => ['TINYINT(1) NOT NULL DEFAULT 1',          'INTEGER NOT NULL DEFAULT 1'],
        'note'       => ["VARCHAR(190) NOT NULL DEFAULT ''",       "TEXT NOT NULL DEFAULT ''"],
        'created_at' => ['DATETIME NULL DEFAULT NULL',             'TEXT DEFAULT NULL'],
    ]);
    if (!wsm_table_exists($pdo, 'wsm_voucher_uses')) { wsm_apply_schema($pdo); }
}

/** Normalise un code saisi : majuscules, sans espaces ni tirets décoratifs. */
function wsm_promo_norm(string $code): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($code)) ?? '');
}

/** Un code neuf, lisible au téléphone et absent de la base. */
function wsm_promo_code(PDO $pdo, string $prefix = ''): string {
    $prefix = wsm_promo_norm($prefix);
    for ($essai = 0; $essai < 40; $essai++) {
        $c = $prefix;
        for ($i = 0; $i < WSM_PROMO_CODE_LEN; $i++) {
            $c .= WSM_PROMO_ALPHABET[random_int(0, strlen(WSM_PROMO_ALPHABET) - 1)];
        }
        $st = $pdo->prepare("SELECT 1 FROM wsm_vouchers WHERE code = ?");
        $st->execute([$c]);
        if (!$st->fetchColumn()) return $c;
    }
    return $prefix . strtoupper(bin2hex(random_bytes(5)));
}

/** Le bon portant ce code, ou null. */
function wsm_promo_find(PDO $pdo, string $code): ?array {
    $c = wsm_promo_norm($code);
    if ($c === '') return null;
    try {
        $st = $pdo->prepare("SELECT * FROM wsm_vouchers WHERE UPPER(code) = ?");
        $st->execute([$c]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/** Combien de fois cette adresse a déjà utilisé ce bon. */
function wsm_promo_uses_by_email(PDO $pdo, int $voucherId, string $email): int {
    $email = strtolower(trim($email));
    if ($email === '') return 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_voucher_uses WHERE voucher_id = ? AND LOWER(email) = ?");
        $st->execute([$voucherId, $email]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/**
 * Ce bon est-il utilisable, ici, maintenant, par cette personne ?
 *
 * Le message est en polonais et DIT CE QU'IL FAUT FAIRE : « ważny od 200 zł »
 * fait ajouter un article ; « kod nieprawidłowy » fait fermer l'onglet.
 *
 * @return array ['ok'=>bool, 'raison'=>string, 'bon'=>array|null]
 */
function wsm_promo_check(PDO $pdo, string $code, int $itemsGross, string $email = '', ?string $quand = null): array {
    $non = fn(string $r) => ['ok' => false, 'raison' => $r, 'bon' => null];
    $b = wsm_promo_find($pdo, $code);
    if (!$b) return $non('Nie znamy tego kodu.');
    if (!(int) ($b['active'] ?? 1)) return $non('Ten kod został wycofany.');

    // UN CODE QUI N'ENLÈVE RIEN N'EST PAS UN CODE. La table portait déjà des
    // lignes de démonstration — BIENVENUE, MARQUE15 — décrites en toutes
    // lettres dans une colonne de texte et sans aucune valeur exploitable.
    // Acceptées, elles auraient répondu « code appliqué » et laissé payer le
    // prix plein : pire qu'un refus, parce que l'acheteur ne vérifie plus.
    $kind = (string) ($b['kind'] ?? 'procent');
    $rien = ($kind === 'procent' && (float) ($b['pct'] ?? 0) <= 0)
         || ($kind === 'kwota'   && (int) ($b['kwota'] ?? 0) <= 0);
    if ($rien) return $non('Ten kod nie jest już aktywny.');

    $quand ??= date('Y-m-d H:i:s');
    $debut = trim((string) ($b['starts_at'] ?? ''));
    $fin   = trim((string) ($b['ends_at'] ?? ''));
    if ($debut !== '' && $quand < $debut) return $non('Ten kod zacznie działać ' . substr($debut, 0, 10) . '.');
    // La fin est INCLUSIVE : un bon « jusqu'au 31 » vaut tout le 31. Comparer
    // une date nue à un horodatage la lirait comme minuit et volerait un jour.
    if ($fin !== '') {
        // MySQL range la date nue en DATETIME : « 2026-10-31 » revient
        // « 2026-10-31 00:00:00 », et le bon mourait à minuit le premier
        // instant de son dernier jour. Minuit pile vaut donc la journée.
        $borne = (strlen($fin) <= 10 || substr($fin, 11) === '00:00:00') ? substr($fin, 0, 10) . ' 23:59:59' : $fin;
        if ($quand > $borne) return $non('Ten kod stracił ważność ' . substr($fin, 0, 10) . '.');
    }

    $min = (int) ($b['min_gross'] ?? 0);
    if ($min > 0 && $itemsGross < $min) {
        return $non('Kod działa od ' . wsm_promo_zl($min) . ' zakupów.');
    }

    $max = (int) ($b['max_uses'] ?? 0);
    if ($max > 0 && (int) ($b['used'] ?? 0) >= $max) return $non('Ten kod został już wykorzystany.');

    $parAdresse = (int) ($b['per_email'] ?? 0);
    if ($parAdresse > 0 && trim($email) !== ''
        && wsm_promo_uses_by_email($pdo, (int) $b['id'], $email) >= $parAdresse) {
        return $non('Ten kod został już użyty na tym adresie.');
    }

    return ['ok' => true, 'raison' => '', 'bon' => $b];
}

/** Un montant en grosze, écrit comme sur une étiquette polonaise. */
function wsm_promo_zl(int $grosze): string {
    return number_format($grosze / 100, 2, ',', ' ') . ' zł';
}

/**
 * Répartit une réduction en montant sur les lignes, AU PRORATA du TTC.
 *
 * Pourquoi pas « retrancher du total » : les taux diffèrent d'une ligne à
 * l'autre (5 % sur une denrée, 23 % ailleurs). Une remise en bloc laisserait
 * la TVA calculée sur des montants que le client n'a pas payés.
 *
 * Le reste de la division entière va à la ligne la plus grosse : la somme des
 * lignes doit retomber EXACTEMENT sur le montant enlevé, au grosz près.
 *
 * @param array $lines  lignes du devis, modifiées en place
 * @return int          ce qui a réellement été enlevé
 */
function wsm_promo_spread(array &$lines, int $montant, bool $reverseCharge = false): int {
    $total = 0;
    foreach ($lines as $l) $total += (int) $l['line_gross'];
    if ($total <= 0 || $montant <= 0) return 0;
    if ($montant > $total) $montant = $total;      // règle 1 : jamais d'avoir

    $parts = []; $donne = 0;
    foreach ($lines as $i => $l) {
        $part = (int) floor($montant * ((int) $l['line_gross']) / $total);
        $parts[$i] = $part;
        $donne += $part;
    }
    // Le reliquat sur la plus grosse ligne — celle qui l'absorbe sans changer
    // de sens, et jamais au-delà de son propre montant.
    $reste = $montant - $donne;
    if ($reste > 0) {
        $plusGrosse = 0; $max = -1;
        foreach ($lines as $i => $l) {
            if ((int) $l['line_gross'] - $parts[$i] > $max) { $max = (int) $l['line_gross'] - $parts[$i]; $plusGrosse = $i; }
        }
        $parts[$plusGrosse] += min($reste, $max);
    }

    $enleve = 0;
    foreach ($lines as $i => &$l) {
        $p = $parts[$i];
        if ($p <= 0) continue;
        $l['line_gross'] = (int) $l['line_gross'] - $p;
        if ($reverseCharge) {
            // En autoliquidation la ligne est un HT : pas de TVA à reventiler.
            $l['line_net'] = $l['line_gross'];
            $l['line_vat'] = 0;
        } else {
            [$n, $v] = wsm_split_vat((int) $l['line_gross'], (float) $l['vat_rate']);
            $l['line_net'] = $n; $l['line_vat'] = $v;
        }
        $enleve += $p;
    }
    unset($l);
    return $enleve;
}

/**
 * Grave l'utilisation d'un bon, et LA REFUSE si le quota vient d'être atteint.
 *
 * C'EST ICI QUE LA DOUBLE DÉPENSE SE JOUE — pas au devis. Deux onglets
 * ouverts passent tous les deux la validation à la même seconde ; seul un
 * UPDATE conditionnel départage, et c'est la base qui compte les lignes
 * touchées, pas nous.
 *
 * L'insertion porte une contrainte d'unicité (voucher_id, order_id) : un
 * webhook rejoué ne peut pas décompter deux fois la même commande.
 *
 * @return array [ok, message]
 */
function wsm_promo_redeem(PDO $pdo, int $voucherId, int $orderId, string $email, int $montant): array {
    if ($voucherId <= 0 || $orderId <= 0) return [false, 'brak danych'];
    try {
        $st = $pdo->prepare("SELECT * FROM wsm_vouchers WHERE id = ?");
        $st->execute([$voucherId]);
        $b = $st->fetch();
        if (!$b) return [false, 'kod nie istnieje'];

        // Déjà comptée ? Alors c'est un rejeu : succès, sans rien décompter.
        $st = $pdo->prepare("SELECT 1 FROM wsm_voucher_uses WHERE voucher_id = ? AND order_id = ?");
        $st->execute([$voucherId, $orderId]);
        if ($st->fetchColumn()) return [true, 'już zapisane'];

        $max = (int) ($b['max_uses'] ?? 0);
        $sql = $max > 0
            ? "UPDATE wsm_vouchers SET used = used + 1 WHERE id = ? AND used < $max"
            : "UPDATE wsm_vouchers SET used = used + 1 WHERE id = ?";
        $up = $pdo->prepare($sql);
        $up->execute([$voucherId]);
        if ($up->rowCount() < 1) return [false, 'kod wyczerpany'];

        $pdo->prepare("INSERT INTO wsm_voucher_uses (voucher_id, order_id, email, amount, created_at)
                       VALUES (?,?,?,?,?)")
            ->execute([$voucherId, $orderId, strtolower(trim($email)), max(0, $montant),
                       date('Y-m-d H:i:s')]);
        return [true, 'zapisano'];
    } catch (Throwable $e) {
        return [false, 'nie udało się zapisać: ' . $e->getMessage()];
    }
}

/**
 * Ce qu'une campagne a coûté, et à combien de personnes elle a servi.
 *
 * Un bon dont personne ne sait ce qu'il coûte finit par être reconduit sans
 * qu'on l'ait jamais mesuré.
 */
function wsm_promo_stats(PDO $pdo, int $voucherId): array {
    $vide = ['uses' => 0, 'amount' => 0, 'emails' => 0, 'last_at' => ''];
    try {
        $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS s,
                                    COUNT(DISTINCT LOWER(email)) AS e, MAX(created_at) AS d
                               FROM wsm_voucher_uses WHERE voucher_id = ?");
        $st->execute([$voucherId]);
        $r = $st->fetch();
        if (!$r) return $vide;
        return ['uses' => (int) $r['n'], 'amount' => (int) $r['s'],
                'emails' => (int) $r['e'], 'last_at' => (string) ($r['d'] ?? '')];
    } catch (Throwable $e) { return $vide; }
}

/** Tous les bons, avec ce que chacun a coûté. */
function wsm_promo_list(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT * FROM wsm_vouchers ORDER BY active DESC, id DESC")->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) {
        $s = wsm_promo_stats($pdo, (int) $r['id']);
        $out[] = $r + ['stats' => $s, 'libelle' => wsm_promo_label($r)];
    }
    return $out;
}

/**
 * Ce que le bon fait, en une ligne lisible par un humain.
 *
 * « Bez efektu » plutôt que « −0 % » : un zéro se lit comme une valeur, et
 * l'écran affichait ainsi trois codes de démonstration comme s'ils marchaient.
 */
function wsm_promo_label(array $b): string {
    $kind = (string) ($b['kind'] ?? 'procent');
    if ($kind === 'wysylka') return 'Darmowa wysyłka';
    if ($kind === 'kwota') {
        $k = (int) ($b['kwota'] ?? 0);
        return $k > 0 ? '−' . wsm_promo_zl($k) : 'bez efektu — popraw lub wycofaj';
    }
    $p = (float) ($b['pct'] ?? 0);
    if ($p <= 0) return 'bez efektu — popraw lub wycofaj';
    return '−' . rtrim(rtrim(number_format($p, 2, ',', ' '), '0'), ',') . ' %';
}

/**
 * Enregistre ou met à jour un bon depuis l'écran.
 *
 * Les bornes ne sont pas des suggestions : un pourcentage au-delà de 50 % ou
 * un montant négatif sont refusés à la saisie, pas neutralisés en silence à
 * la caisse. Quelqu'un doit apprendre que son chiffre n'a pas été pris.
 *
 * @return array [ok, message, id]
 */
function wsm_promo_save(PDO $pdo, array $in, string $actor): array {
    $kind = (string) ($in['kind'] ?? 'procent');
    if (!in_array($kind, WSM_PROMO_KINDS, true)) return [false, 'Nieznany rodzaj kodu.', 0];

    $code = wsm_promo_norm((string) ($in['code'] ?? ''));
    if ($code === '') $code = wsm_promo_code($pdo);
    if (strlen($code) < 4) return [false, 'Kod musi mieć co najmniej 4 znaki.', 0];
    if (strlen($code) > 60) return [false, 'Kod może mieć najwyżej 60 znaków.', 0];     // wsm_vouchers.code VARCHAR(60)

    $pct = (float) str_replace(',', '.', (string) ($in['pct'] ?? 0));
    $kwota = wsm_promo_grosze($in['kwota'] ?? 0);
    if ($kind === 'procent' && ($pct <= 0 || $pct > WSM_PROMO_MAX_PCT)) {
        return [false, 'Rabat procentowy musi mieścić się między 0 a ' . (int) WSM_PROMO_MAX_PCT . ' %.', 0];
    }
    if ($kind === 'kwota' && $kwota <= 0) return [false, 'Podaj kwotę rabatu.', 0];

    $debut = wsm_promo_date((string) ($in['starts_at'] ?? ''));
    $fin   = wsm_promo_date((string) ($in['ends_at'] ?? ''));
    if ($debut !== null && $fin !== null && $fin < $debut) {
        return [false, 'Koniec ważności wypada przed początkiem.', 0];
    }

    $champs = [
        'code' => $code, 'kind' => $kind,
        'pct' => $kind === 'procent' ? $pct : 0,
        'kwota' => $kind === 'kwota' ? $kwota : 0,
        'min_gross' => wsm_promo_grosze($in['min_gross'] ?? 0),
        'starts_at' => $debut, 'ends_at' => $fin,
        'max_uses' => max(0, (int) ($in['max_uses'] ?? 0)),
        'per_email' => max(0, (int) ($in['per_email'] ?? 0)),
        'active' => !empty($in['active']) ? 1 : 0,
        'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 190),
        // Les colonnes d'origine restent alimentées : la console les affiche
        // encore, et une valeur vide y passerait pour un bon vide.
        'valeur' => wsm_promo_label(['kind' => $kind, 'pct' => $pct, 'kwota' => $kwota]),
        'type' => $kind === 'wysylka' ? 'Wysyłka' : 'Panier',
        'validite' => $fin !== null ? substr($fin, 0, 10) : 'bezterminowo',
    ];

    $id = (int) ($in['id'] ?? 0);
    try {
        if ($id > 0) {
            $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($champs)));
            $pdo->prepare("UPDATE wsm_vouchers SET $sets WHERE id = ?")
                ->execute([...array_values($champs), $id]);
        } else {
            $champs['created_at'] = date('Y-m-d H:i:s');
            $cols = implode(', ', array_keys($champs));
            $qs = implode(', ', array_fill(0, count($champs), '?'));
            $pdo->prepare("INSERT INTO wsm_vouchers ($cols) VALUES ($qs)")->execute(array_values($champs));
            $id = (int) $pdo->lastInsertId();
        }
    } catch (Throwable $e) {
        $m = $e->getMessage();
        if (str_contains($m, 'UNIQUE') || str_contains($m, 'Duplicate')) {
            return [false, 'Kod ' . $code . ' już istnieje.', 0];
        }
        return [false, 'Nie udało się zapisać: ' . $m, 0];
    }

    if (function_exists('wsm_audit')) {
        wsm_audit($pdo, $actor, $id > 0 ? 'Zapis kodu rabatowego' : 'Nowy kod rabatowy',
                  'wsm_vouchers ' . $code, 'Sieć');
    }
    return [true, 'Zapisano kod ' . $code . '.', $id];
}

/**
 * « 200 », « 200,00 », « 200 zł » — ou « 200 zl » en grosze.
 *
 * Le « zl » sans diacritique est accepté : c'est ce qu'on tape sur un clavier
 * pressé, et refuser un montant pour un accent manquant ferait croire à un
 * champ cassé.
 */
function wsm_promo_grosze($v): int {
    $s = str_ireplace(['zł', 'zl', 'PLN'], '', (string) $v);
    $s = str_replace([' ', "\u{202F}", "\u{00A0}"], '', $s);
    $s = str_replace(',', '.', trim($s));
    if ($s === '' || !is_numeric($s)) return 0;
    return max(0, (int) round(((float) $s) * 100));
}

/** Une date d'écran en horodatage, ou null si le champ est vide. */
function wsm_promo_date(string $v): ?string {
    $v = trim($v);
    if ($v === '') return null;
    $t = strtotime($v);
    if ($t === false) return null;
    // Une date nue vaut la journée entière ; wsm_promo_check pose la borne.
    return strlen($v) <= 10 ? date('Y-m-d', $t) : date('Y-m-d H:i:s', $t);
}

/**
 * Retire un bon de la circulation.
 *
 * ON NE SUPPRIME PAS : les utilisations passées pointent dessus, et une
 * commande dont le bon a disparu devient inexplicable un an plus tard, quand
 * quelqu'un demande pourquoi elle a coûté 40 zł de moins.
 */
function wsm_promo_disable(PDO $pdo, int $id, string $actor): array {
    $st = $pdo->prepare("SELECT code FROM wsm_vouchers WHERE id = ?");
    $st->execute([$id]);
    $code = (string) $st->fetchColumn();
    if ($code === '') return [false, 'Nie znaleziono kodu.'];
    $pdo->prepare("UPDATE wsm_vouchers SET active = 0 WHERE id = ?")->execute([$id]);
    if (function_exists('wsm_audit')) {
        wsm_audit($pdo, $actor, 'Wycofanie kodu rabatowego', 'wsm_vouchers ' . $code, 'Sieć');
    }
    return [true, 'Wycofano kod ' . $code . '. Historia użyć pozostaje.'];
}
