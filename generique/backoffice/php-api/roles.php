<?php
// ============================================================================
//  roles.php — LES PROFILS : ce qu'ils PEUVENT, et ce qu'ils FONT VRAIMENT.
//
//  Jusqu'ici les profils vivaient uniquement dans auth.php, en dur. Les
//  changer voulait dire modifier du PHP, ouvrir une pull request et attendre
//  un déploiement — pour une phrase qui tient en trois clics : « le magasin
//  n'a pas à voir les factures ». Résultat prévisible : on ne les changeait
//  pas. On donnait « Administrator » à quelqu'un qui devait imprimer des
//  étiquettes, parce que c'était plus rapide que d'avoir raison.
//
//  Ce fichier pose une SURCOUCHE en base par-dessus les profils du code. Le
//  code reste la référence : tant que personne n'a rien touché, la surcouche
//  est vide et wsm_roles() rend exactement ce qu'il rendait hier.
//
//  ---------------------------------------------------------------------
//  ET SURTOUT : « DE FAIT » N'EST PAS « DE DROIT ».
//
//  Un profil déclare ce qu'il OUVRE. L'enregistreur de pages, lui, sait ce
//  qui a été ouvert. Les deux ne se ressemblent pas, et c'est l'écart qui est
//  intéressant :
//
//   · un écran ACCORDÉ ET JAMAIS OUVERT en trente jours est un droit qu'on
//     donne sans raison — le retirer ne gêne personne et réduit d'autant ce
//     qu'un compte volé emporte ;
//   · un écran OUVERT SANS DROIT ACTUEL veut dire que le profil a changé
//     depuis. C'est normal après une modification, et anormal si personne
//     n'a rien modifié.
//
//  C'est là toute la différence entre une matrice de droits qu'on relit et
//  une matrice qu'on vérifie. Personne ne relit une matrice.
//  ---------------------------------------------------------------------
//
//  QUATRE RÈGLES QUI NE SE NÉGOCIENT PAS.
//
//   1. « Administrator » ET « Superadmin » NE SE MODIFIENT PAS. Ce sont les
//      deux profils qui ouvrent les comptes ; s'ils étaient modifiables, une
//      seule case décochée fermerait la console à tout le monde, y compris à
//      celui qui vient de la décocher. Ils restent la porte de secours.
//   2. « superadmin.php » NE S'ACCORDE JAMAIS par un profil. Sinon un
//      Administrator se fabriquerait l'accès à sa propre facture de
//      plateforme en trois clics — exactement le risque que
//      wsm_peut_donner_role() nomme et empêche par ailleurs.
//   3. LES ÉCRANS SATELLITES SUIVENT LEUR PARENT. Une impression n'est pas
//      un écran : c'est un bouton d'un écran. Les lister à part garantit
//      qu'un jour quelqu'un accordera « Zamówienia » sans « imprimer une
//      commande », et cherchera la panne ailleurs.
//   4. TOUT ÉCHEC RETOMBE SUR LE CODE. Table absente, base injoignable,
//      ligne illisible : la surcouche est vide et les profils du code
//      s'appliquent. Une panne de base ne doit jamais OUVRIR quoi que ce
//      soit, et ne doit pas non plus tout fermer.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
// auth.php porte les constantes de rôle, wsm_roles_base() et wsm_droit_ecran().
// Il ne connaît PAS ce fichier en retour : il teste function_exists() avant
// d'appliquer la surcouche, pour que la boutique publique et migrate.php
// continuent de marcher sans lui.
require_once __DIR__ . '/auth.php';

/** Longueur maximale d'un nom de profil. */
// 32 et pas 40 (la largeur de wsm_users.role) POUR UNE RAISON PRÉCISE :
// l'enregistreur range le rôle dans wsm_page_views.rola, en VARCHAR(32), et
// tronque au-delà. Un profil plus long existerait donc sous deux noms — le
// vrai et le tronqué — et la confrontation « de droit / de fait » ne
// rapprocherait plus rien, sans rien signaler.
const WSM_PROFIL_NOM_MAX = 32;

/** Les profils que la console ne modifie pas. Voir règle 1. */
function wsm_profil_fixes(): array {
    return [WSM_ROLE_ADMIN, WSM_ROLE_SUPERADMIN];
}

/** Les écrans qu'aucun profil ne peut accorder. Voir règle 2. */
function wsm_profil_interdits(): array {
    return ['superadmin.php'];
}

/**
 * Les écrans qui suivent un autre écran, et lequel. Voir règle 3.
 *
 * Une impression et une étiquette ne se rangent pas dans un menu : on y
 * arrive depuis la commande, la facture ou l'expédition. Accorder le parent
 * accorde donc le satellite, avec le même droit.
 *
 * @return array<string, string[]>  parent => satellites
 */
function wsm_profil_satellites(): array {
    return [
        'zamowienia.php' => ['zamowienie_druk.php'],
        'faktury.php'    => ['faktura_druk.php'],
        // La page d'un article s'ouvre depuis le Magazyn, avec ses droits.
        'magazyn.php'    => ['magazyn_druk.php', 'magazyn_produkt.php'],
        'wysylka.php'    => ['etykieta_druk.php', 'etykieta_inpost.php', 'etykieta_dpd.php'],
    ];
}

/** Ce profil se modifie-t-il depuis la console ? */
function wsm_profil_modifiable(string $rola): bool {
    return !in_array($rola, wsm_profil_fixes(), true);
}

// ---------------------------------------------------------------------------
//  LA SURCOUCHE
// ---------------------------------------------------------------------------

/**
 * Les profils redéfinis en base, chargés une fois par requête.
 *
 * Chargement PARESSEUX, et pas depuis wsm_bootstrap() : la boutique publique
 * ne consulte jamais un profil, et lui faire payer deux requêtes par page
 * pour un tableau qu'elle n'ouvrira pas serait du gaspillage silencieux. Le
 * premier appel à wsm_roles() paie, les suivants lisent le cache.
 *
 * @param bool $oublie  vide le cache — à appeler après une écriture, sinon la
 *                      page qui vient d'enregistrer réaffiche l'état d'avant.
 */
function wsm_roles_overlay(bool $oublie = false): array {
    static $over = null;
    if ($oublie) { $over = null; return []; }
    if ($over !== null) return $over;

    $over = [];
    try {
        $pdo = wsm_pdo();
        $profils = $pdo->query("SELECT rola, opis FROM wsm_role_profiles")->fetchAll() ?: [];
        if (!$profils) return $over;                       // rien de redéfini : le code fait foi

        $droits = [];
        foreach ($pdo->query("SELECT rola, ekran, droit FROM wsm_role_screens")->fetchAll() ?: [] as $r) {
            $d = (string) $r['droit'];
            if ($d !== 'r' && $d !== 'w') continue;
            $droits[(string) $r['rola']][(string) $r['ekran']] = $d;
        }

        foreach ($profils as $p) {
            $rola = (string) $p['rola'];
            // RÈGLE 1, appliquée à la LECTURE et pas seulement à l'écriture :
            // une ligne posée à la main dans la base ne doit pas davantage
            // toucher aux deux profils complets qu'un formulaire.
            if (!wsm_profil_modifiable($rola)) continue;
            $over[$rola] = [
                'ecrans' => wsm_profil_nettoie($droits[$rola] ?? []),
                'aide'   => (string) $p['opis'],
            ];
        }
    } catch (Throwable $e) {
        // RÈGLE 4. Table absente (déploiement en cours), base injoignable :
        // on rend une surcouche vide, donc les profils du code.
        $over = [];
    }
    return $over;
}

/** Vide le cache de la surcouche. */
function wsm_roles_oublie(): void { wsm_roles_overlay(true); }

/**
 * Applique la surcouche aux profils du code. Appelée par wsm_roles().
 *
 * Un profil redéfini est REMPLACÉ, pas fusionné. Fusionner voudrait dire
 * qu'une case décochée ne retire rien — le formulaire dirait le contraire de
 * ce qu'il fait, et c'est la pire chose qu'un écran de droits puisse faire.
 */
function wsm_roles_surcouche(array $base): array {
    foreach (wsm_roles_overlay() as $rola => $def) {
        if (!wsm_profil_modifiable($rola)) continue;
        $base[$rola] = [
            'ecrans' => $def['ecrans'],
            'aide'   => $def['aide'] !== '' ? $def['aide'] : $rola,
        ];
    }
    return $base;
}

/**
 * Une liste d'écrans propre : droits valides, écrans interdits retirés,
 * satellites accordés avec leur parent.
 *
 * Passe à la LECTURE comme à l'écriture. Ce qui est écrit en base a déjà été
 * nettoyé, mais une base se modifie aussi avec un client SQL.
 */
function wsm_profil_nettoie(array $ecrans): array {
    $out = [];
    $interdits = wsm_profil_interdits();
    foreach ($ecrans as $f => $d) {
        $f = strtolower(trim((string) $f));
        $d = (string) $d;
        if ($f === '' || in_array($f, $interdits, true)) continue;
        if ($d !== 'r' && $d !== 'w') continue;
        $out[$f] = $d;
    }
    // RÈGLE 3 : le satellite prend le droit de son parent, et disparaît si le
    // parent n'est pas accordé.
    foreach (wsm_profil_satellites() as $parent => $sats) {
        foreach ($sats as $s) {
            unset($out[$s]);
            if (isset($out[$parent])) $out[$s] = $out[$parent];
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
//  ÉCRITURE
// ---------------------------------------------------------------------------

/**
 * Vérifie un profil avant de l'écrire.
 *
 * @param array $dispo  fichier => libellé, les écrans qu'on a le droit de citer
 * @return array [nom propre, écrans propres, description, erreurs]
 */
function wsm_profil_valide(string $rola, array $ecrans, string $opis, array $dispo): array {
    $err  = [];
    $rola = trim(preg_replace('/\s+/u', ' ', $rola) ?? '');
    $opis = trim(preg_replace('/\s+/u', ' ', $opis) ?? '');

    if ($rola === '') {
        $err['rola'] = 'Podaj nazwę profilu.';
    } elseif (mb_strlen($rola) > WSM_PROFIL_NOM_MAX) {
        $err['rola'] = 'Najwyżej ' . WSM_PROFIL_NOM_MAX . ' znaków.';
    } elseif (mb_strlen($rola) < 2) {
        $err['rola'] = 'Za krótka nazwa.';
    } elseif (!preg_match('/^[\p{L}\p{N} ()\/-]+$/u', $rola)) {
        $err['rola'] = 'Litery, cyfry, spacja i myślnik.';
    } else {
        foreach (wsm_profil_fixes() as $f) {
            // Comparaison insensible à la casse : « administrator » créerait
            // un second profil que personne ne distinguerait du premier dans
            // une liste déroulante.
            if (mb_strtolower($rola) === mb_strtolower($f)) {
                $err['rola'] = 'Tej nazwy nie można użyć — « ' . $f . ' » jest wbudowany.';
            }
        }
    }

    // La description n'est pas décorative : c'est ELLE qu'on lit dans la liste
    // déroulante de l'écran Użytkownicy au moment d'attribuer le profil.
    // « Sprzedaż » tout court n'aide personne à choisir.
    if ($opis === '') $err['opis'] = 'Napisz w jednym zdaniu, co ten profil otwiera.';
    elseif (mb_strlen($opis) > 160) $err['opis'] = 'Najwyżej 160 znaków.';

    $propres = [];
    foreach ($ecrans as $f => $d) {
        $f = strtolower(trim((string) $f));
        if ($f === '' || $d === '' || $d === null) continue;
        if (!isset($dispo[$f])) continue;                  // écran qui n'existe pas : ignoré
        if ($d !== 'r' && $d !== 'w') continue;
        $propres[$f] = $d;
    }
    $propres = wsm_profil_nettoie($propres);

    return [$rola, $propres, $opis, $err];
}

/**
 * Enregistre un profil. Renvoie [ok, erreurs].
 *
 * Un profil SANS AUCUN ÉCRAN est accepté, et c'est voulu : c'est ainsi qu'on
 * suspend un profil sans toucher aux comptes qui le portent. Le formulaire le
 * dit, pour que personne ne croie à un enregistrement raté.
 */
function wsm_profil_save(PDO $pdo, string $rola, array $ecrans, string $opis, array $dispo): array {
    [$rola, $propres, $opis, $err] = wsm_profil_valide($rola, $ecrans, $opis, $dispo);
    if ($err) return [false, $err];
    if (!wsm_profil_modifiable($rola)) {
        return [false, ['rola' => 'Tego profilu nie można zmieniać.']];
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM wsm_role_screens  WHERE rola = ?")->execute([$rola]);
        $pdo->prepare("DELETE FROM wsm_role_profiles WHERE rola = ?")->execute([$rola]);
        $pdo->prepare("INSERT INTO wsm_role_profiles (rola, opis, maj) VALUES (?,?,?)")
            ->execute([$rola, $opis, date('Y-m-d H:i:s')]);
        $ins = $pdo->prepare("INSERT INTO wsm_role_screens (rola, ekran, droit) VALUES (?,?,?)");
        foreach ($propres as $f => $d) $ins->execute([$rola, $f, $d]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [false, ['rola' => 'Nie udało się zapisać.']];
    }
    wsm_roles_oublie();
    return [true, []];
}

/**
 * Rend un profil à sa définition du code. Pour un profil créé en console,
 * c'est une suppression : le code n'en a jamais entendu parler.
 */
function wsm_profil_reset(PDO $pdo, string $rola): bool {
    if (!wsm_profil_modifiable($rola)) return false;
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM wsm_role_screens  WHERE rola = ?")->execute([$rola]);
        $pdo->prepare("DELETE FROM wsm_role_profiles WHERE rola = ?")->execute([$rola]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
    wsm_roles_oublie();
    return true;
}

/**
 * Un profil créé en console se supprime — SI personne ne le porte.
 *
 * Supprimer un profil encore attribué ne provoque aucune erreur : les comptes
 * concernés retombent silencieusement sur « Podgląd » (wsm_role_de). Trois
 * personnes perdraient leur travail du matin sans qu'un message n'apparaisse
 * nulle part. On refuse donc, et on dit combien de comptes attendent.
 *
 * @return array [ok, message]
 */
function wsm_profil_supprime(PDO $pdo, string $rola, array $codeRoles): array {
    if (!wsm_profil_modifiable($rola)) return [false, 'Tego profilu nie można usunąć.'];
    if (isset($codeRoles[$rola])) {
        return [false, 'Ten profil jest wbudowany — można go przywrócić, nie usunąć.'];
    }
    $n = wsm_profil_comptes($pdo)[$rola] ?? 0;
    if ($n > 0) {
        return [false, 'Profil ma jeszcze ' . $n . ' ' . ($n === 1 ? 'konto' : 'kont')
                     . '. Najpierw przenieś je gdzie indziej.'];
    }
    return [wsm_profil_reset($pdo, $rola), ''];
}

/** Combien de comptes ACTIFS portent chaque profil. */
function wsm_profil_comptes(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT role, COUNT(*) AS n FROM wsm_users WHERE act = 1 GROUP BY role")
                    ->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $r) $out[(string) $r['role']] = (int) $r['n'];
        return $out;
    } catch (Throwable $e) { return []; }
}

// ---------------------------------------------------------------------------
//  DE DROIT / DE FAIT
// ---------------------------------------------------------------------------

/**
 * Ce que chaque profil a RÉELLEMENT ouvert, par écran.
 *
 * Lu dans l'enregistreur de pages, qui note l'écran et le rôle — jamais la
 * personne, jamais l'adresse complète (les raisons sont en tête de usage.php).
 * La question posée ici est « ce droit sert-il », pas « qu'a fait untel ».
 *
 * @return array<string, array<string,int>>  rôle => écran => nombre d'ouvertures
 */
function wsm_profil_de_fait(PDO $pdo, int $jours = 30): array {
    $u = __DIR__ . '/usage.php';
    if (!is_file($u)) return [];
    require_once $u;
    if (!function_exists('wsm_usage_par_role_ecran')) return [];
    return wsm_usage_par_role_ecran($pdo, $jours);
}

/**
 * L'écart entre ce qu'un profil peut et ce qu'il fait.
 *
 * @param array $droits  écran => 'r'|'w' (ce que le profil ouvre)
 * @param array $fait    écran => n       (ce qu'il a ouvert)
 * @return array ['uzywane' => [écran => n], 'nieuzywane' => [écran => droit],
 *                'bez_prawa' => [écran => n]]
 */
function wsm_profil_ecarts(array $droits, array $fait): array {
    $sat = [];
    foreach (wsm_profil_satellites() as $sats) foreach ($sats as $s) $sat[$s] = true;
    // UN ÉCRAN INTERDIT DE PROFIL N'EST PAS UN ÉCART, et le premier essai réel
    // l'a montré du doigt : l'écran de la plateforme est exclu des droits par
    // construction, mais l'enregistreur le mesure comme les autres. Le Superadmin
    // qui venait de l'ouvrir se voyait donc accusé d'avoir ouvert « un écran qui
    // n'est plus sur sa liste » — sur la page même qu'il était en train de lire.
    foreach (wsm_profil_interdits() as $i) $sat[$i] = true;

    $uzywane = $nieuzywane = $bezPrawa = [];
    foreach ($droits as $f => $d) {
        // Un satellite ne se compte pas comme un droit dormant : on n'ouvre
        // pas « imprimer une facture » tous les jours, et le retirer n'a
        // aucun sens puisqu'il suit son parent.
        if (isset($sat[$f])) continue;
        $n = (int) ($fait[$f] ?? 0);
        if ($n > 0) $uzywane[$f] = $n; else $nieuzywane[$f] = $d;
    }
    foreach ($fait as $f => $n) {
        if (isset($sat[$f]) || isset($droits[$f]) || (int) $n <= 0) continue;
        $bezPrawa[$f] = (int) $n;
    }
    arsort($uzywane);
    arsort($bezPrawa);
    return ['uzywane' => $uzywane, 'nieuzywane' => $nieuzywane, 'bez_prawa' => $bezPrawa];
}

/**
 * Le tableau de bord des profils : une ligne par profil, prête à afficher.
 *
 * @param array $dispo  fichier => libellé (les écrans du rail)
 */
function wsm_profil_tableau(PDO $pdo, array $dispo, int $jours = 30): array {
    $over    = wsm_roles_overlay();
    $comptes = wsm_profil_comptes($pdo);
    $fait    = wsm_profil_de_fait($pdo, $jours);
    $out     = [];

    foreach (wsm_roles() as $rola => $def) {
        $tout = ($def['ecrans'] ?? null) === '*';
        if ($tout) {
            // « ouvre tout » se compte sur les écrans livrés, pas sur une
            // liste : c'est bien ce que ça veut dire.
            $droits = [];
            foreach ($dispo as $f => $_) {
                $d = wsm_droit_ecran(['role' => $rola], (string) $f);
                if ($d !== '') $droits[(string) $f] = $d;
            }
        } else {
            $droits = (array) ($def['ecrans'] ?? []);
        }
        $ec = wsm_profil_ecarts($droits, $fait[$rola] ?? []);
        $out[$rola] = [
            'rola'       => $rola,
            'aide'       => (string) ($def['aide'] ?? $rola),
            'tout'       => $tout,
            'modifiable' => wsm_profil_modifiable($rola),
            'change'     => isset($over[$rola]),
            'comptes'    => (int) ($comptes[$rola] ?? 0),
            'droits'     => $droits,
            'uzywane'    => $ec['uzywane'],
            'nieuzywane' => $ec['nieuzywane'],
            'bez_prawa'  => $ec['bez_prawa'],
            'odslon'     => array_sum($fait[$rola] ?? []),
        ];
    }

    // Les profils que la base connaît et que le code ignore sont des profils
    // créés ici. Ils apparaissent déjà par wsm_roles() — la surcouche les y a
    // mis ; ce marquage n'existe que pour les distinguer à l'écran : un profil
    // intégré se RESTAURE, un profil créé ici se SUPPRIME.
    $code = wsm_roles_base();
    foreach ($out as $rola => &$l) $l['custom'] = !isset($code[$rola]);
    unset($l);

    return $out;
}
