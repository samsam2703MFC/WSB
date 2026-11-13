<?php
// ============================================================================
//  i18n.php — les langues de la boutique : lesquelles existent, lesquelles
//  sont publiées, et ce qui reste à traduire.
//
//  QUATRE RÈGLES, DANS L'ORDRE D'IMPORTANCE :
//
//   1. UNE LANGUE SE PUBLIE SUR DÉCISION, PAS PAR EFFET DE BORD. Avant, la
//      liste publique se déduisait de « SELECT DISTINCT lang » : traduire une
//      seule clé en allemand faisait apparaître un drapeau DE menant à une
//      boutique polonaise à 99 %. Un visiteur qui clique dessus ne revient
//      pas. La publication est donc une case à cocher, et elle est REFUSÉE
//      sous un seuil de couverture — sauf passage en force explicite, parce
//      qu'il y a des cas légitimes (une langue qu'on veut relire en ligne).
//
//   2. LE POLONAIS EST LA SOURCE ET LE FILET. Il ne se dépublie pas : c'est
//      la langue sur laquelle tout le reste retombe. Une clé vide ailleurs
//      n'ouvre pas un trou dans la page, elle affiche le polonais — c'est
//      d'ailleurs la bonne façon de dire « pas encore traduit ».
//
//   3. UNE TRADUCTION AUTOMATIQUE S'ANNONCE COMME TELLE. Une machine traduit
//      vite et se trompe avec aplomb : « pralina » n'est pas « praline » dans
//      tous les contextes, et un bouton mal traduit coûte des commandes. Elle
//      est donc marquée `auto` jusqu'à ce qu'un humain la valide. Confondre
//      les deux, c'est perdre la seule information qui permet de relire.
//
//   4. TOUT ÉCRIT LAISSE UNE TRACE. Qui, quand, avant → après. Sans ça, un
//      texte public qui change n'a pas d'auteur, et une bêtise ne se retrouve
//      qu'en relisant le site.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Les langues que le projet sait servir, dans l'ordre d'affichage.
 *
 * Le nom est écrit DANS la langue : un visiteur tchèque cherche « Čeština »,
 * pas « tchèque ». Le code court est l'étiquette du sélecteur.
 */
const WSM_LANGS = [
    'pl' => ['Polski',     'PL', 'polonais'],
    'en' => ['English',    'EN', 'anglais'],
    'uk' => ['Українська', 'UA', 'ukrainien'],
    'de' => ['Deutsch',    'DE', 'allemand'],
    'fr' => ['Français',   'FR', 'français'],
    'cs' => ['Čeština',    'CS', 'tchèque'],
    'sk' => ['Slovenčina', 'SK', 'slovaque'],
    'hu' => ['Magyar',     'HU', 'hongrois'],
];

/**
 * Le nom d'une langue DANS LES PHRASES POLONAISES de la console.
 *
 * Le nom natif (« Українська ») est ce qu'un visiteur cherche dans un
 * sélecteur, mais il ne se décline pas : « napisaną po Українська » ne se dit
 * pas. Le polonais demande l'adverbe (« po ukraińsku ») et l'accusatif
 * (« na ukraiński ») — deux formes, donc deux colonnes, plutôt qu'un nom
 * recollé de force au milieu d'une phrase.
 */
const WSM_LANG_PL = [
    'pl' => ['po polsku',     'na polski'],
    'en' => ['po angielsku',  'na angielski'],
    'uk' => ['po ukraińsku',  'na ukraiński'],
    'de' => ['po niemiecku',  'na niemiecki'],
    'fr' => ['po francusku',  'na francuski'],
    'cs' => ['po czesku',     'na czeski'],
    'sk' => ['po słowacku',   'na słowacki'],
    'hu' => ['po węgiersku',  'na węgierski'],
];

/** « po ukraińsku » — pour « wiadomość napisana … ». */
function wsm_lang_po(string $code): string {
    return WSM_LANG_PL[$code][0] ?? ('po ' . strtoupper($code));
}

/** « na ukraiński » — pour « przetłumacz … ». */
function wsm_lang_na(string $code): string {
    return WSM_LANG_PL[$code][1] ?? ('na ' . strtoupper($code));
}

/** La langue source : elle ne se dépublie pas et sert de repli partout. */
const WSM_LANG_BASE = 'pl';

/**
 * Sous ce taux de couverture, publier une langue dessert la boutique plus
 * qu'elle ne la sert. Franchissable, mais jamais par inadvertance.
 */
const WSM_LANG_MIN_COVERAGE = 85.0;

/** Les tables de contenu traduit, et leur nom à l'écran. */
const WSM_LANG_TABLES = [
    'wsm_shop_i18n'    => 'Sklep',
    'wsm_landing_i18n' => 'Strona główna',
];

/**
 * Crée les tables au besoin, et REPREND L'EXISTANT au premier passage.
 *
 * Ce détail vaut tout le reste du fichier. La boutique servait jusqu'ici les
 * langues présentes en base (pl, uk, en). En basculant la liste publique sur
 * wsm_langs, une table vide ferait disparaître l'ukrainien et l'anglais de la
 * vitrine à la seconde du déploiement — une régression silencieuse, sur des
 * pages déjà indexées. Le premier remplissage recopie donc ce qui était servi
 * hier : la bascule ne change RIEN de visible, et seules les langues ajoutées
 * ensuite demandent une décision.
 */
function wsm_i18n_ensure(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_langs') || !wsm_table_exists($pdo, 'wsm_i18n_history')) {
        wsm_apply_schema($pdo);
    }
    try {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM wsm_langs")->fetchColumn();
    } catch (Throwable $e) { return; }          // la table viendra au prochain passage
    if ($n > 0) return;

    // Ce que la boutique servait avant cette bascule : toute langue ayant du
    // contenu. On le fige tel quel.
    $avant = [];
    foreach (array_keys(WSM_LANG_TABLES) as $t) {
        if (!wsm_table_exists($pdo, $t)) continue;
        try {
            foreach ($pdo->query("SELECT DISTINCT lang FROM $t")->fetchAll() ?: [] as $r) {
                $c = (string) $r['lang'];
                if (isset(WSM_LANGS[$c])) $avant[$c] = true;
            }
        } catch (Throwable $e) { /* table absente : rien à reprendre */ }
    }
    $avant[WSM_LANG_BASE] = true;               // le socle, toujours

    $ordre = array_keys(WSM_LANGS);
    $ins = $pdo->prepare("INSERT INTO wsm_langs (code, published, sort_order) VALUES (?,?,?)");
    foreach ($ordre as $i => $code) {
        $ins->execute([$code, isset($avant[$code]) ? 1 : 0, $i]);
    }
}

/** Le nom natif d'une langue — « Čeština », pas « tchèque ». */
function wsm_lang_name(string $code): string {
    return WSM_LANGS[$code][0] ?? strtoupper($code);
}

/** L'étiquette courte du sélecteur. */
function wsm_lang_short(string $code): string {
    return WSM_LANGS[$code][1] ?? strtoupper($code);
}

/**
 * L'état de chaque langue connue : publiée ou non, dans l'ordre d'affichage.
 *
 * @return array [code => ['code','name','short','published'=>bool,'sort_order'=>int]]
 */
function wsm_lang_registry(PDO $pdo): array {
    wsm_i18n_ensure($pdo);
    $rows = [];
    try {
        foreach ($pdo->query("SELECT * FROM wsm_langs")->fetchAll() ?: [] as $r) {
            $rows[(string) $r['code']] = $r;
        }
    } catch (Throwable $e) { /* base neuve */ }

    $out = []; $i = 0;
    foreach (WSM_LANGS as $code => [$name, $short, $fr]) {
        $r = $rows[$code] ?? null;
        $out[$code] = [
            'code' => $code, 'name' => $name, 'short' => $short, 'fr' => $fr,
            'published' => $code === WSM_LANG_BASE ? true : (bool) (int) ($r['published'] ?? 0),
            'sort_order' => (int) ($r['sort_order'] ?? $i),
            'known' => $r !== null,
        ];
        $i++;
    }
    uasort($out, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);
    return $out;
}

/**
 * Les langues réellement servies au public.
 *
 * Le polonais y figure toujours, même si la table venait à disparaître : une
 * boutique sans aucune langue n'affiche rien du tout, et ce serait une panne
 * bien pire que celle qu'on essaie d'éviter.
 *
 * @return string[]
 */
function wsm_lang_published(PDO $pdo): array {
    $out = [];
    foreach (wsm_lang_registry($pdo) as $code => $l) {
        if ($l['published']) $out[] = $code;
    }
    if (!in_array(WSM_LANG_BASE, $out, true)) array_unshift($out, WSM_LANG_BASE);
    return $out;
}

/**
 * La couverture d'une langue sur une table : combien de clés du polonais
 * ont un texte non vide dans cette langue.
 *
 * On compte par rapport au POLONAIS et non au total des lignes : une langue
 * qui aurait des clés obsolètes en trop paraîtrait sinon mieux traduite
 * qu'elle ne l'est.
 *
 * @return array ['total','done','missing'=>string[],'pct'=>float,'auto'=>int]
 */
function wsm_lang_coverage(PDO $pdo, string $table, string $lang): array {
    $vide = ['total' => 0, 'done' => 0, 'missing' => [], 'pct' => 0.0, 'auto' => 0];
    if (!isset(WSM_LANG_TABLES[$table])) return $vide;
    try {
        $st = $pdo->prepare("SELECT k, v FROM $table WHERE lang = ?");
        $st->execute([WSM_LANG_BASE]);
        $base = $st->fetchAll() ?: [];
        $st->execute([$lang]);
        $cible = [];
        foreach ($st->fetchAll() ?: [] as $r) $cible[(string) $r['k']] = (string) $r['v'];
    } catch (Throwable $e) { return $vide; }

    $auto = [];
    if (in_array('origin', wsm_table_columns($pdo, $table), true)) {
        try {
            $q = $pdo->prepare("SELECT k FROM $table WHERE lang = ? AND origin = 'auto'");
            $q->execute([$lang]);
            foreach ($q->fetchAll() ?: [] as $r) $auto[(string) $r['k']] = true;
        } catch (Throwable $e) { /* colonne absente : pas de marquage */ }
    }

    $total = 0; $done = 0; $manquantes = [];
    foreach ($base as $r) {
        $k = (string) $r['k'];
        // Une clé vide EN POLONAIS n'est pas à traduire : elle n'existe pas.
        if (trim((string) $r['v']) === '') continue;
        $total++;
        if (trim($cible[$k] ?? '') !== '') $done++;
        else $manquantes[] = $k;
    }
    return [
        'total' => $total, 'done' => $done, 'missing' => $manquantes,
        'pct' => $total > 0 ? round($done / $total * 100, 1) : 0.0,
        'auto' => count(array_intersect_key($auto, array_flip(array_keys($cible)))),
    ];
}

/** La couverture d'une langue sur TOUTES les tables de contenu, agrégée. */
function wsm_lang_coverage_all(PDO $pdo, string $lang): array {
    $total = 0; $done = 0; $auto = 0;
    foreach (array_keys(WSM_LANG_TABLES) as $t) {
        $c = wsm_lang_coverage($pdo, $t, $lang);
        $total += $c['total']; $done += $c['done']; $auto += $c['auto'];
    }
    return ['total' => $total, 'done' => $done, 'auto' => $auto,
            'pct' => $total > 0 ? round($done / $total * 100, 1) : 0.0];
}

/**
 * Publie ou dépublie une langue.
 *
 * Le refus sous le seuil n'est pas une politesse : une langue à 30 % affiche
 * un menu allemand au-dessus d'un texte polonais, ce qui se lit comme un site
 * cassé. `$force` existe parce qu'on peut vouloir relire une traduction en
 * ligne avant de la finir — mais il faut le dire.
 *
 * @return array [ok, message]
 */
function wsm_lang_publish(PDO $pdo, string $code, bool $published, bool $force = false): array {
    wsm_i18n_ensure($pdo);
    if (!isset(WSM_LANGS[$code])) return [false, 'nieznany język'];
    if ($code === WSM_LANG_BASE && !$published) {
        return [false, 'polski jest językiem źródłowym — nie da się go wyłączyć'];
    }

    if ($published && !$force) {
        $c = wsm_lang_coverage_all($pdo, $code);
        if ($c['pct'] < WSM_LANG_MIN_COVERAGE) {
            return [false, sprintf(
                '%s przetłumaczony w %s %% — poniżej %s %% strona wygląda na zepsutą. '
                . 'Uzupełnij tłumaczenia albo zaznacz „opublikuj mimo to”.',
                wsm_lang_name($code),
                number_format($c['pct'], 1, ',', ' '),
                number_format(WSM_LANG_MIN_COVERAGE, 0, ',', ' ')
            )];
        }
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_langs WHERE code = ?");
    $st->execute([$code]);
    if ((int) $st->fetchColumn()) {
        $pdo->prepare("UPDATE wsm_langs SET published = ? WHERE code = ?")
            ->execute([$published ? 1 : 0, $code]);
    } else {
        $ordre = array_search($code, array_keys(WSM_LANGS), true);
        $pdo->prepare("INSERT INTO wsm_langs (code, published, sort_order) VALUES (?,?,?)")
            ->execute([$code, $published ? 1 : 0, (int) $ordre]);
    }
    return [true, wsm_lang_name($code) . ($published ? ' — opublikowany' : ' — wycofany')];
}

// ---------------------------------------------------------------------------
//  L'historique
// ---------------------------------------------------------------------------

/**
 * Note un changement de texte. Appelé par le CMS à chaque écriture réelle.
 *
 * On n'écrit RIEN quand la valeur ne change pas : un historique rempli de
 * lignes identiques est un historique qu'on cesse de lire.
 */
function wsm_i18n_log(PDO $pdo, string $table, string $lang, string $key,
                      string $avant, string $apres, string $actor, string $origin = 'human'): void {
    if ($avant === $apres) return;
    wsm_i18n_ensure($pdo);
    try {
        $pdo->prepare("INSERT INTO wsm_i18n_history
                         (tbl, lang, k, old_v, new_v, origin, actor, created_at)
                       VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$table, $lang, $key, $avant, $apres, $origin,
                       mb_substr($actor, 0, 120), date('Y-m-d H:i:s')]);
    } catch (Throwable $e) { /* l'historique ne doit jamais bloquer une écriture */ }
}

/**
 * Les derniers changements, filtrables.
 *
 * @param array $f ['lang'=>…, 'tbl'=>…, 'k'=>…, 'origin'=>…]
 */
function wsm_i18n_history(PDO $pdo, array $f = [], int $limit = 100): array {
    wsm_i18n_ensure($pdo);
    $w = []; $p = [];
    foreach (['lang', 'tbl', 'k', 'origin'] as $col) {
        if (!empty($f[$col])) { $w[] = "$col = ?"; $p[] = (string) $f[$col]; }
    }
    $sql = "SELECT * FROM wsm_i18n_history"
         . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
         . " ORDER BY id DESC LIMIT " . max(1, min(500, $limit));
    try {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * Rétablit la valeur d'AVANT un changement donné.
 *
 * Le retour est lui-même journalisé : revenir en arrière est un changement
 * comme un autre, et l'effacer de l'historique serait mentir sur ce qui s'est
 * passé. On peut donc annuler l'annulation.
 *
 * @return array [ok, message]
 */
function wsm_i18n_revert(PDO $pdo, int $id, string $actor): array {
    wsm_i18n_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM wsm_i18n_history WHERE id = ?");
    $st->execute([$id]);
    $h = $st->fetch();
    if (!$h) return [false, 'nie znaleziono wpisu'];

    $tbl = (string) $h['tbl'];
    if (!isset(WSM_LANG_TABLES[$tbl])) return [false, 'nieznana tabela'];

    $actuel = wsm_i18n_get($pdo, $tbl, (string) $h['lang'], (string) $h['k']);
    $cible  = (string) $h['old_v'];
    if ($actuel === $cible) return [false, 'tekst jest już w tej wersji'];

    wsm_i18n_put($pdo, $tbl, (string) $h['lang'], (string) $h['k'], $cible, 'human');
    wsm_i18n_log($pdo, $tbl, (string) $h['lang'], (string) $h['k'], $actuel, $cible, $actor, 'revert');
    return [true, 'przywrócono „' . mb_substr($cible, 0, 40) . '”'];
}

// ---------------------------------------------------------------------------
//  Lecture / écriture d'un texte
// ---------------------------------------------------------------------------

function wsm_i18n_get(PDO $pdo, string $table, string $lang, string $key): string {
    if (!isset(WSM_LANG_TABLES[$table])) return '';
    try {
        $st = $pdo->prepare("SELECT v FROM $table WHERE lang = ? AND k = ?");
        $st->execute([$lang, $key]);
        $v = $st->fetchColumn();
        return $v === false ? '' : (string) $v;
    } catch (Throwable $e) { return ''; }
}

/**
 * Écrit un texte, en notant s'il vient d'une machine ou d'une personne.
 *
 * `origin` n'est pas décoratif : c'est ce qui permet à l'écran de montrer
 * « 42 traductions automatiques à relire » au lieu de laisser croire que tout
 * a été vu par quelqu'un.
 */
function wsm_i18n_put(PDO $pdo, string $table, string $lang, string $key,
                      string $value, string $origin = 'human'): bool {
    if (!isset(WSM_LANG_TABLES[$table])) return false;
    $aOrigin = in_array('origin', wsm_table_columns($pdo, $table), true);
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE lang = ? AND k = ?");
        $st->execute([$lang, $key]);
        if ((int) $st->fetchColumn()) {
            $sql = $aOrigin ? "UPDATE $table SET v = ?, origin = ? WHERE lang = ? AND k = ?"
                            : "UPDATE $table SET v = ? WHERE lang = ? AND k = ?";
            $p = $aOrigin ? [$value, $origin, $lang, $key] : [$value, $lang, $key];
        } else {
            $sql = $aOrigin ? "INSERT INTO $table (lang, k, v, origin) VALUES (?,?,?,?)"
                            : "INSERT INTO $table (lang, k, v) VALUES (?,?,?)";
            $p = $aOrigin ? [$lang, $key, $value, $origin] : [$lang, $key, $value];
        }
        $pdo->prepare($sql)->execute($p);
        return true;
    } catch (Throwable $e) { return false; }
}

/** Ajoute la colonne `origin` aux tables de contenu si elle manque. */
function wsm_i18n_ensure_origin(PDO $pdo): void {
    foreach (array_keys(WSM_LANG_TABLES) as $t) {
        if (!wsm_table_exists($pdo, $t)) continue;
        wsm_ensure_columns($pdo, $t, [
            // 'human' | 'auto' — d'où vient ce texte.
            'origin' => ["VARCHAR(8) NOT NULL DEFAULT 'human'", "TEXT NOT NULL DEFAULT 'human'"],
        ]);
    }
}
