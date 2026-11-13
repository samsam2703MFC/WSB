<?php
// ============================================================================
//  usage.php — l'enregistreur de pages de la console.
//
//  À QUOI IL SERT. On a rangé le rail par métier en se fiant à l'idée qu'on
//  se faisait du travail réel. Personne n'a jamais mesuré. Cet enregistreur
//  répond à trois questions qu'on ne peut pas deviner :
//
//    · quels écrans servent VRAIMENT, et lesquels n'ont jamais été ouverts —
//      six écrans livrés étaient morts en production sans que personne le
//      remarque, et c'est exactement ce genre de silence qu'on mesure ici ;
//    · dans quel ORDRE le travail se fait, donc ce qu'il faudrait mettre à
//      côté de quoi (wsm_page_paths) ;
//    · quels écrans sont lents, en millisecondes serveur — un écran qu'on
//      évite est souvent un écran qui fait attendre.
//
//  CINQ RÈGLES, ET AUCUNE N'EST DÉCORATIVE.
//
//   1. ON N'ENREGISTRE JAMAIS L'ADRESSE COMPLÈTE. « ?zamowienie=MS-2026-0412 »
//      ou « ?szukaj=Kowalski » : la query string d'un back-office porte des
//      numéros de commande et des noms de clients. On garde le nom de
//      l'écran, point. Sans cette règle, un journal d'ergonomie devient un
//      fichier de données personnelles que personne n'a déclaré.
//   2. ON N'ENREGISTRE JAMAIS QUI. Le rôle, pas la personne. La question est
//      « où passe le temps de l'équipe », pas « qu'a fait Anna à 14 h ». Le
//      rôle répond à la première et rend la seconde impossible à poser. Les
//      écritures restent tracées nominativement dans wsm_audit_log — c'est
//      leur métier, et ce n'est pas le même sujet.
//   3. ÇA NE DOIT JAMAIS CASSER UNE PAGE. Un enregistreur qui fait tomber
//      l'écran qu'il mesure est pire que pas d'enregistreur. Toute erreur est
//      avalée : au pire on perd une mesure, jamais une page.
//   4. ON AGRÈGE À L'ÉCRITURE. Une ligne par jour, par écran et par rôle —
//      pas une ligne par vue. Les tables sont donc bornées par construction :
//      pas de purge à programmer, donc pas de purge à oublier.
//   5. ON NE COMPTE QUE LES GET. Un POST est un geste, pas une visite ; il est
//      déjà au journal d'audit, et le compter ici doublerait chaque
//      enregistrement d'un formulaire.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Les écrans qui ne sont pas des écrans : impressions, étiquettes, exports. */
const WSM_USAGE_HORS = ['login.php', 'index.php'];

/**
 * Un nom d'écran sûr. Ce qui arrive ici vient de SCRIPT_NAME et d'un Referer
 * — le second est fourni par le navigateur, donc par n'importe qui. On ne
 * garde qu'un nom de fichier plausible, et rien d'autre n'entre en base.
 */
function wsm_usage_ecran(string $brut): string {
    $f = basename(trim($brut));
    if ($f === '' || !preg_match('/^[a-z0-9_-]{1,58}\.php$/i', $f)) return '';
    return strtolower($f);
}

/**
 * L'écran d'où l'on vient, lu dans le Referer — et UNIQUEMENT s'il désigne la
 * même console. Un Referer externe ne dit rien d'utile sur notre navigation,
 * et le recopier ferait entrer une adresse tierce dans nos tables.
 */
function wsm_usage_skad(string $referer, string $ici): string {
    if ($referer === '') return '';
    $p = parse_url($referer);
    if (!$p || !isset($p['path'])) return '';
    // Même hôte, sinon on ne sait pas de quoi on parle.
    //
    // ON COMPARE SANS LE PORT, et ce n'est pas de la coquetterie :
    // parse_url() rend « localhost » là où HTTP_HOST rend « localhost:8093 ».
    // Comparés tels quels, les deux ne sont jamais égaux dès que le site
    // n'écoute pas sur le port par défaut — et la table des enchaînements,
    // la plus utile des deux, reste vide sans que rien ne le signale. Elle
    // l'était au premier essai réel.
    $sansPort = static fn(string $h): string => strtolower((string) preg_replace('/:\d+$/', '', trim($h)));
    $hote = $sansPort((string) ($p['host'] ?? ''));
    if ($hote !== '' && $hote !== $sansPort((string) ($_SERVER['HTTP_HOST'] ?? ''))) return '';
    $f = wsm_usage_ecran((string) $p['path']);
    // Un rafraîchissement n'est pas un déplacement : il gonflerait la
    // « transition » la plus fréquente de chaque écran vers lui-même, et
    // noierait les vraies suites.
    return $f === $ici ? '' : $f;
}

/**
 * Enregistre une vue. Appelé à l'EXTINCTION de la requête (voir console.php) :
 * c'est le seul moment où la durée est connue, et le seul qui attrape aussi
 * les écrans qui se terminent par un exit — une impression, un téléchargement.
 */
function wsm_usage_record(PDO $pdo, string $ecran, string $rola, int $ms, string $skad = ''): bool {
    $ecran = wsm_usage_ecran($ecran);
    if ($ecran === '' || in_array($ecran, WSM_USAGE_HORS, true)) return false;
    $rola = substr(trim($rola), 0, 32);
    // Une durée négative ou absurde vient d'une horloge, pas d'un écran.
    if ($ms < 0 || $ms > 600000) $ms = 0;

    try {
        $mysql = (wsm_config()['engine'] ?? '') === 'mysql';
        $dzien = date('Y-m-d');

        // L'agrégat du jour. Les deux dialectes disent la même chose ; il n'y
        // a pas de syntaxe commune, d'où les deux branches et pas une de plus.
        $sql = $mysql
            ? "INSERT INTO wsm_page_views (ekran, dzien, rola, n, ms_sum, ms_max)
               VALUES (?, ?, ?, 1, ?, ?)
               ON DUPLICATE KEY UPDATE n = n + 1, ms_sum = ms_sum + VALUES(ms_sum),
                                       ms_max = GREATEST(ms_max, VALUES(ms_max))"
            : "INSERT INTO wsm_page_views (ekran, dzien, rola, n, ms_sum, ms_max)
               VALUES (?, ?, ?, 1, ?, ?)
               ON CONFLICT (ekran, dzien, rola) DO UPDATE
               SET n = n + 1, ms_sum = ms_sum + excluded.ms_sum,
                   ms_max = MAX(ms_max, excluded.ms_max)";
        $pdo->prepare($sql)->execute([$ecran, $dzien, $rola, $ms, $ms]);

        if ($skad !== '' && $skad !== $ecran) {
            $sql2 = $mysql
                ? "INSERT INTO wsm_page_paths (skad, dokad, n) VALUES (?, ?, 1)
                   ON DUPLICATE KEY UPDATE n = n + 1"
                : "INSERT INTO wsm_page_paths (skad, dokad, n) VALUES (?, ?, 1)
                   ON CONFLICT (skad, dokad) DO UPDATE SET n = n + 1";
            $pdo->prepare($sql2)->execute([$skad, $ecran]);
        }
        return true;
    } catch (Throwable $e) {
        // RÈGLE 3. La page est déjà rendue et partie chez le client ; une
        // exception ici ne servirait qu'à salir le journal d'erreurs.
        return false;
    }
}

/** Le total par écran sur les N derniers jours, le plus consulté d'abord. */
function wsm_usage_par_ecran(PDO $pdo, int $jours = 30): array {
    $depuis = date('Y-m-d', time() - max(1, $jours) * 86400);
    try {
        $st = $pdo->prepare(
            "SELECT ekran, SUM(n) AS n, SUM(ms_sum) AS ms_sum, MAX(ms_max) AS ms_max
             FROM wsm_page_views WHERE dzien >= ? GROUP BY ekran ORDER BY n DESC");
        $st->execute([$depuis]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $n = (int) $r['n'];
            $out[] = ['ekran' => (string) $r['ekran'], 'n' => $n,
                      'ms_moy' => $n > 0 ? (int) round((int) $r['ms_sum'] / $n) : 0,
                      'ms_max' => (int) $r['ms_max']];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/** Qui ouvre quoi — par rôle, pour savoir à qui l'on parle sur chaque écran. */
function wsm_usage_par_role(PDO $pdo, int $jours = 30): array {
    $depuis = date('Y-m-d', time() - max(1, $jours) * 86400);
    try {
        $st = $pdo->prepare("SELECT rola, SUM(n) AS n FROM wsm_page_views
                             WHERE dzien >= ? GROUP BY rola ORDER BY n DESC");
        $st->execute([$depuis]);
        return array_map(fn($r) => ['rola' => (string) $r['rola'], 'n' => (int) $r['n']],
                         $st->fetchAll());
    } catch (Throwable $e) { return []; }
}

/**
 * QUI OUVRE QUOI, écran par écran — la matière des « profils de fait ».
 *
 * wsm_usage_par_role() donne un total par rôle : utile pour savoir où passe le
 * temps de l'équipe, inutile pour juger un droit. C'est le croisement rôle ×
 * écran qui répond à la seule question qui compte quand on relit une matrice
 * de droits : « ce droit-là sert-il à quelqu'un ? »
 *
 * Un droit accordé et jamais exercé en trente jours ne provoque aucune erreur
 * et ne fait l'objet d'aucune plainte — c'est précisément ce qui le rend
 * invisible, et ce qu'un compte volé emporte en plus.
 *
 * @return array<string, array<string,int>>  rôle => écran => ouvertures
 */
function wsm_usage_par_role_ecran(PDO $pdo, int $jours = 30): array {
    $depuis = date('Y-m-d', time() - max(1, $jours) * 86400);
    try {
        $st = $pdo->prepare("SELECT rola, ekran, SUM(n) AS n FROM wsm_page_views
                             WHERE dzien >= ? GROUP BY rola, ekran");
        $st->execute([$depuis]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(string) $r['rola']][(string) $r['ekran']] = (int) $r['n'];
        }
        foreach ($out as &$par) arsort($par);
        unset($par);
        return $out;
    } catch (Throwable $e) { return []; }
}

/** Les enchaînements les plus fréquents. C'est la matière du futur rangement. */
function wsm_usage_chemins(PDO $pdo, int $limite = 12): array {
    try {
        $st = $pdo->prepare("SELECT skad, dokad, n FROM wsm_page_paths
                             ORDER BY n DESC LIMIT " . max(1, min(50, $limite)));
        $st->execute();
        return array_map(fn($r) => ['skad' => (string) $r['skad'],
                                    'dokad' => (string) $r['dokad'], 'n' => (int) $r['n']],
                         $st->fetchAll());
    } catch (Throwable $e) { return []; }
}

/** Le jour par jour, pour voir si l'usage monte, descend ou s'est arrêté. */
function wsm_usage_par_jour(PDO $pdo, int $jours = 30): array {
    $depuis = date('Y-m-d', time() - max(1, $jours) * 86400);
    try {
        $st = $pdo->prepare("SELECT dzien, SUM(n) AS n FROM wsm_page_views
                             WHERE dzien >= ? GROUP BY dzien ORDER BY dzien");
        $st->execute([$depuis]);
        return array_map(fn($r) => ['dzien' => (string) $r['dzien'], 'n' => (int) $r['n']],
                         $st->fetchAll());
    } catch (Throwable $e) { return []; }
}

/**
 * LES ÉCRANS QUE PERSONNE N'OUVRE.
 *
 * C'est la sortie la plus utile de tout ce fichier, et la seule qu'on
 * n'obtient pas en regardant un tableau de compteurs : un écran absent des
 * mesures ne s'affiche nulle part, justement parce qu'il n'a produit aucune
 * ligne. On part donc du rail — la liste de ce qui est LIVRÉ — et on retire
 * ce qui a été vu. Ce qui reste n'a jamais été ouvert par personne.
 *
 * @param array $livres  fichier => libellé (console_menu)
 */
function wsm_usage_morts(PDO $pdo, array $livres, int $jours = 30): array {
    $vus = [];
    foreach (wsm_usage_par_ecran($pdo, $jours) as $r) $vus[$r['ekran']] = true;
    $out = [];
    foreach ($livres as $f => $label) {
        $f = strtolower((string) $f);
        if (in_array($f, WSM_USAGE_HORS, true)) continue;
        if (!isset($vus[$f])) $out[$f] = (string) $label;
    }
    return $out;
}

/** Depuis quand mesure-t-on ? Sans cette date, un « 0 » ne veut rien dire. */
function wsm_usage_depuis(PDO $pdo): string {
    try {
        $v = $pdo->query("SELECT MIN(dzien) FROM wsm_page_views")->fetchColumn();
        return $v ? (string) $v : '';
    } catch (Throwable $e) { return ''; }
}
