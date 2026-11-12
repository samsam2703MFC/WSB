<?php
// ============================================================================
//  db.php — PDO factory for webshop_mrszoko. Connects to MySQL in prod or the
//  local SQLite mirror in dev/CI, and can bootstrap the schema on demand.
// ============================================================================

// Le design system de la boutique (logo, couleurs, polices) : la boutique, la
// console et Wygląd en ont tous besoin, et tous passent par ici.
require_once __DIR__ . '/ds.php';

function wsm_config(): array {
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/config.php';
    $over = wsm_config_overlay();
    return $over ? array_replace_recursive($cfg, $over) : $cfg;
}

/**
 * Surcouche de configuration posée à l'exécution — c'est par là que les
 * réglages saisis dans le back-office (settings.php) entrent en vigueur.
 * Séparée de wsm_config() pour une raison mécanique : lire la base exige une
 * connexion, et ouvrir la connexion exige la configuration. La surcouche est
 * donc appliquée APRÈS, depuis wsm_bootstrap(), sans jamais rappeler la base.
 */
function wsm_config_overlay(?array $patch = null): array {
    static $over = [];
    if ($patch !== null) $over = array_replace_recursive($over, $patch);
    return $over;
}

/** Open a PDO connection to the configured engine. */
/**
 * LE NOM DE LA BOUTIQUE, là où il ne passe pas par la vitrine : titres de la
 * console, objets et signatures des e-mails, description du paiement. Il vit
 * dans Treści (clé « brand », en polonais) — un seul endroit à changer, et
 * pas un nom écrit en dur dans dix fichiers. Mister Szoko, puis Market
 * Levano, puis Szoko Shop : trois noms en deux semaines, ce n'est pas fini.
 */
function wsm_brand_name(): string {
    static $nom = null;
    if ($nom !== null) return $nom;
    $nom = 'Szoko Shop';
    try {
        $st = wsm_pdo()->prepare("SELECT v FROM wsm_shop_i18n WHERE lang = 'pl' AND k = 'brand'");
        $st->execute();
        $v = trim((string) $st->fetchColumn());
        if ($v !== '') $nom = $v;
    } catch (Throwable $e) { /* table absente : le nom par défaut */ }
    return $nom;
}

/**
 * LE JOURNAL DES ERREURS, côté serveur : data/bledy.log (hors du site — le
 * dossier data/ répond 404 et n'est pas touché par le déploiement).
 *
 * Pourquoi un journal à nous : en production, une requête refusée par MySQL
 * et rattrapée par un « catch » qui rend un tableau vide ne laisse AUCUNE
 * trace. C'est ainsi qu'une comparaison de date à '' a fait afficher 0 dans
 * le B2B et le CRM sans que personne le sache. Désormais chaque refus de la
 * base est écrit ici, avec la requête — et le déploiement en affiche la fin.
 *
 * Les tests (ligne de commande) n'écrivent pas, sauf WSM_JOURNAL=1 : ils
 * provoquent des erreurs exprès.
 */
function wsm_journal(string $ou, string $quoi): void {
    static $vus = [];
    if (PHP_SAPI === 'cli' && getenv('WSM_JOURNAL') !== '1') return;
    $cle = md5($ou . $quoi);
    if (isset($vus[$cle]) || count($vus) > 20) return;     // pas d'avalanche dans une requête
    $vus[$cle] = true;
    $f = wsm_journal_plik();
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0775, true);
    if (@filesize($f) > 512 * 1024) @rename($f, $f . '.1');      // une génération de réserve
    $ligne = date('Y-m-d H:i:s') . ' | ' . $ou . ' | ' . preg_replace('/\s+/', ' ', $quoi);
    @file_put_contents($f, mb_substr($ligne, 0, 1500) . "\n", FILE_APPEND | LOCK_EX);
}

/** Le fichier du journal ; sa génération précédente est « .1 ». */
function wsm_journal_plik(): string {
    return __DIR__ . '/data/bledy.log';
}

/**
 * Le journal LU, pour le Pulpit : les dernières entrées (la plus récente
 * d'abord) et les comptes. Une ligne = « date | où | quoi ».
 *
 * « zapis » dit si le serveur PEUT écrire le journal : un journal vide parce
 * que le dossier est en lecture seule mentirait en disant « rien à signaler ».
 *
 * @return array{wpisy: list<array{kiedy:string,gdzie:string,co:string}>, razem:int, tydzien:int, od:?string, zapis:bool}
 */
function wsm_journal_lire(int $n = 20): array {
    $f = wsm_journal_plik();
    $lignes = is_file($f) ? (@file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
    $semaine = date('Y-m-d H:i:s', time() - 7 * 86400);
    $tydzien = 0;
    foreach ($lignes as $l) if (substr($l, 0, 19) >= $semaine) $tydzien++;
    $wpisy = [];
    foreach (array_reverse(array_slice($lignes, -max(1, $n))) as $l) {
        $p = explode(' | ', $l, 3);
        $wpisy[] = ['kiedy' => (string) ($p[0] ?? ''), 'gdzie' => (string) ($p[1] ?? ''), 'co' => (string) ($p[2] ?? '')];
    }
    $zapis = is_file($f) ? is_writable($f) : is_writable(dirname($f));
    return ['wpisy' => $wpisy, 'razem' => count($lignes), 'tydzien' => $tydzien, 'od' => $lignes ? substr($lignes[0], 0, 19) : null, 'zapis' => $zapis];
}

/**
 * « Przejrzane » : les entrées lues passent en archive (« .1 », qui remplace
 * l'archive précédente) et la liste repart de zéro — le Pulpit ne montre
 * plus que ce qui est arrivé APRÈS. Rien n'est effacé tant qu'une nouvelle
 * revue n'a pas eu lieu.
 */
function wsm_journal_archiwizuj(): bool {
    $f = wsm_journal_plik();
    return !is_file($f) || @rename($f, $f . '.1');
}

/** Où l'erreur est arrivée : la page demandée, ou le script. */
function wsm_journal_ou(): string {
    return (string) ($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? PHP_SAPI));
}

/** Une requête refusée : le message de la base et le début de la requête. */
function wsm_sql_trace(PDOException $e, string $sql): void {
    wsm_journal(wsm_journal_ou(), $e->getMessage() . ' | SQL: ' . mb_substr(trim($sql), 0, 400));
}

/**
 * PDO, avec une seule différence : toute requête refusée est écrite au
 * journal AVANT de remonter. Le comportement ne change pas — l'exception
 * remonte telle quelle, les « catch » existants font ce qu'ils faisaient —
 * mais plus aucun refus n'est muet.
 */
class WsmPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        try { return parent::prepare($query, $options); }
        catch (PDOException $e) { wsm_sql_trace($e, $query); throw $e; }
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        try { return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs); }
        catch (PDOException $e) { wsm_sql_trace($e, $query); throw $e; }
    }
    public function exec(string $statement): int|false {
        try { return parent::exec($statement); }
        catch (PDOException $e) { wsm_sql_trace($e, $statement); throw $e; }
    }
}
class WsmStatement extends PDOStatement {
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        try { return parent::execute($params); }
        catch (PDOException $e) { wsm_sql_trace($e, $this->queryString); throw $e; }
    }
}

function wsm_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $cfg = wsm_config();
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STATEMENT_CLASS    => [WsmStatement::class],
    ];

    if ($cfg['engine'] === 'mysql') {
        $m = $cfg['mysql'];
        $dsn = "mysql:host={$m['host']};port={$m['port']};dbname={$m['name']};charset=utf8mb4";
        // rowCount() d'un UPDATE : les lignes TROUVÉES, comme SQLite — pas
        // seulement celles qui ont changé. Tout le code (et tous les tests,
        // joués sous SQLite) lit « > 0 » comme « la ligne existe et a été
        // traitée » ; sans ce drapeau, réécrire une valeur identique passait
        // pour un échec en production seulement.
        $opts[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
        $pdo = new WsmPDO($dsn, $m['user'], $m['pass'], $opts);
    } else {
        $path = $cfg['sqlite_path'];
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $pdo = new WsmPDO('sqlite:' . $path, null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

/** Whether the schema (wsm_deliveries as sentinel) already exists. */
function wsm_schema_exists(PDO $pdo): bool {
    $cfg = wsm_config();
    try {
        if ($cfg['engine'] === 'mysql') {
            $q = $pdo->query("SHOW TABLES LIKE 'wsm_deliveries'");
            return (bool) $q->fetchColumn();
        }
        $q = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='wsm_deliveries'");
        return (bool) $q->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** Run the engine-appropriate schema file (idempotent). */
function wsm_apply_schema(PDO $pdo): void {
    $cfg = wsm_config();
    $file = __DIR__ . '/schema/webshop_mrszoko.' . ($cfg['engine'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
    $sql = file_get_contents($file);
    if ($cfg['engine'] === 'mysql') {
        // Target the CONFIGURED database (the connection's dbname — e.g. the
        // server's `mrszoko`), which may differ from the canonical file's
        // webshop_mrszoko: drop the file's CREATE DATABASE / USE statements and
        // run everything else in the connected schema, statement by statement
        // (PDO multi-statement exec is unreliable across drivers).
        $sql = preg_replace('/^(CREATE DATABASE|USE)\b[^;]*;/mi', '', $sql);
    }
    foreach (wsm_split_sql($sql) as $stmt) {
        if (trim($stmt) !== '') $pdo->exec($stmt);
    }
}

/** Naive-but-sufficient SQL splitter for our comment-and-statement files. */
function wsm_split_sql(string $sql): array {
    $out = [];
    $buf = '';
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        $trim = ltrim($line);
        if (str_starts_with($trim, '--') || $trim === '') continue;
        $buf .= $line . "\n";
        if (str_ends_with(rtrim($line), ';')) { $out[] = $buf; $buf = ''; }
    }
    if (trim($buf) !== '') $out[] = $buf;
    return $out;
}

/** Ensure schema + seed exist; used by the API and the CLI so first hit works. */
function wsm_bootstrap(bool $seed = true): PDO {
    $pdo = wsm_pdo();
    if (!wsm_schema_exists($pdo)) {
        wsm_apply_schema($pdo);
        if ($seed) {
            require_once __DIR__ . '/seed.php';
            wsm_seed($pdo);
        }
    }
    wsm_ensure_auth_columns($pdo);
    wsm_ensure_commerce_columns($pdo);
    wsm_ensure_shop($pdo);
    wsm_ensure_vies($pdo);
    wsm_ensure_countries($pdo);
    wsm_ensure_trade($pdo);
    wsm_ensure_mail($pdo);
    wsm_ensure_invoices($pdo);
    wsm_ensure_stock($pdo);
    require_once __DIR__ . '/brand.php';
    wsm_ensure_brands($pdo);
    wsm_ensure_nouvelles_tables($pdo);
    // En dernier : les réglages saisis en console entrent en vigueur une fois
    // que leur table existe, et seulement là où le fichier serveur se tait.
    require_once __DIR__ . '/settings.php';
    wsm_settings_apply($pdo);
    // Les profils redéfinis en console (roles.php). On CHARGE le fichier ici et
    // pas dans chaque écran : un droit qui s'applique sur un chemin et pas sur
    // un autre est la panne la plus coûteuse de ce dépôt — deux listes qui
    // décident de la même chose et ne disent pas pareil. Le coût est nul tant
    // que personne ne consulte un profil : la surcouche ne lit la base qu'au
    // premier appel de wsm_roles(), et la boutique publique n'en fait aucun.
    $roles = __DIR__ . '/roles.php';
    if (is_file($roles)) require_once $roles;
    return $pdo;
}

/**
 * CE QUE LA BASE A REFUSÉ, DIT À LA PERSONNE DEVANT L'ÉCRAN — et écrit en
 * entier dans le journal PHP du serveur. « Nie udało się utworzyć produktu »,
 * sans motif, a laissé l'équipe devant un mur : la cause (un identifiant trop
 * long pour MySQL) n'était lisible nulle part. Le motif dit ici est court et
 * sans détail technique ; le message complet est dans le journal.
 */
function wsm_db_powod(Throwable $e, string $kontekst = ''): string {
    $m = $e->getMessage();
    error_log('[wsm] ' . ($kontekst !== '' ? $kontekst . ': ' : '') . get_class($e) . ': ' . $m);
    wsm_journal($kontekst !== '' ? $kontekst : wsm_journal_ou(), get_class($e) . ': ' . $m . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    if (preg_match("/Data too long for column '([^']+)'/", $m, $x)) return 'za długi tekst w polu „' . $x[1] . '”';
    if (str_contains($m, 'Duplicate entry') || str_contains($m, 'UNIQUE constraint failed')) return 'taki wpis już istnieje';
    if (stripos($m, 'foreign key constraint') !== false) return 'odwołanie do rekordu, którego nie ma';
    if (preg_match('/Incorrect (date|datetime)/i', $m)) return 'nieprawidłowa data';
    if (preg_match("/Field '([^']+)' doesn't have a default value|NOT NULL constraint failed: \\w+\\.(\\w+)/", $m, $x)) return 'brak wartości w polu „' . ($x[1] ?: ($x[2] ?? '')) . '”';
    $kod = $e instanceof PDOException ? (string) ($e->errorInfo[1] ?? $e->getCode()) : '';
    return 'błąd bazy danych' . ($kod !== '' && $kod !== '0' ? ' (kod ' . $kod . ')' : '') . ', szczegóły w dzienniku serwera';
}

/**
 * TOUT OU RIEN. Une page, c'est une ligne et ses textes : enregistrés en deux
 * temps hors transaction, une erreur au second laissait la ligne SANS textes
 * (les anciens déjà effacés) — et la liste de Strony tombait ensuite dessus.
 * Dans une transaction déjà ouverte, c'est elle qui décide.
 */
function wsm_tx(PDO $pdo, callable $fn): mixed {
    if ($pdo->inTransaction()) return $fn();
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * UNE DATE ABSENTE, DANS LE DIALECTE DE LA BASE.
 *
 * Sous SQLite une date est du texte : « pas encore expédiée » peut y valoir
 * NULL ou ''. Sous MySQL 8, en mode strict, comparer une colonne DATETIME à
 * '' est une ERREUR (1292/1525) — pas un faux, une exception. Le code écrit
 * pour les deux, « shipped_at IS NULL OR shipped_at = '' », passait donc
 * tous les tests (SQLite) et faisait tomber en production le passage d'une
 * commande en « Wysłane ». Ces trois fonctions disent la même chose dans la
 * langue de chaque base.
 */
function wsm_sql_mysql(PDO $pdo): bool {
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
}
/** « la date manque » */
function wsm_sql_sans_date(PDO $pdo, string $col): string {
    return wsm_sql_mysql($pdo) ? "$col IS NULL" : "($col IS NULL OR $col = '')";
}
/** « la date est là » */
function wsm_sql_avec_date(PDO $pdo, string $col): string {
    return wsm_sql_mysql($pdo) ? "$col IS NOT NULL" : "($col IS NOT NULL AND $col <> '')";
}
/** la date, ou NULL quand elle manque (pour COALESCE) */
function wsm_sql_date(PDO $pdo, string $col): string {
    return wsm_sql_mysql($pdo) ? $col : "NULLIF($col, '')";
}

/** Les colonnes d'une table existantes, en minuscules (MySQL + SQLite). */
function wsm_table_columns(PDO $pdo, string $table): array {
    $cfg = wsm_config();
    try {
        if ($cfg['engine'] === 'mysql') {
            $st = $pdo->prepare("SELECT LOWER(column_name) FROM information_schema.columns
                                 WHERE table_schema = DATABASE() AND table_name = ?");
            $st->execute([$table]);
            return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
        $rows = $pdo->query("PRAGMA table_info(" . $table . ")")->fetchAll();
        return array_map(fn($r) => strtolower((string) $r['name']), $rows);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Ajoute à une table les colonnes manquantes. Nécessaire car
 * CREATE TABLE IF NOT EXISTS ne touche pas une table déjà créée — c'est le cas
 * de la base MySQL de production. Idempotent.
 *
 * @param array $cols  colonne => [déclaration MySQL, déclaration SQLite]
 */
/**
 * L'HEURE DE LA BASE, pas celle de PHP.
 *
 * created_at, paid_at et shipped_at sont écrits par CURRENT_TIMESTAMP : c'est
 * l'horloge du serveur de base qui les pose, dans SON fuseau — UTC pour
 * SQLite, celui de la session pour MySQL. Les comparer à time() de PHP, c'est
 * comparer deux horloges qu'aucune règle n'oblige à être d'accord.
 *
 * Un décalage d'une heure ne casse rien visiblement : il affiche « po
 * terminie » sur des commandes payées il y a dix minutes, ou laisse un retard
 * de trois heures passer pour du temps restant. Personne ne cherche un bug
 * dans une pendule.
 *
 * @return int horodatage Unix lu dans la base ; l'heure de PHP en dernier
 *             recours, car un écran ne doit pas tomber pour une pendule.
 */
function wsm_db_now(PDO $pdo): int {
    try {
        $v = (string) $pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
        $t = strtotime($v);
        if ($t !== false) return $t;
    } catch (Throwable $e) { /* on retombe sur PHP */ }
    return time();
}

function wsm_ensure_columns(PDO $pdo, string $table, array $cols): void {
    $have = wsm_table_columns($pdo, $table);
    if (!$have) return;                                   // table absente : le schéma la créera complète
    $mysql = wsm_config()['engine'] === 'mysql';
    foreach ($cols as $col => $decl) {
        if (in_array(strtolower($col), $have, true)) continue;
        $sql = $mysql ? $decl[0] : $decl[1];
        try { $pdo->exec("ALTER TABLE $table ADD COLUMN " . ($mysql ? "`$col`" : $col) . " $sql"); }
        catch (Throwable $e) { /* concurrence : une autre requête vient de l'ajouter */ }
    }
}

/** Colonnes d'authentification (voir auth.php). */
/**
 * Les tables ajoutées APRÈS la mise en route.
 *
 * TROISIÈME FOIS QUE CETTE LEÇON SE PAIE. Les modèles de mail n'arrivaient
 * pas ; les libellés de la boutique non plus ; et wsm_langs n'existait pas
 * encore sur le serveur parce qu'elle n'est créée que par un écran
 * authentifié — que ni un visiteur ni le déploiement n'ouvrent jamais.
 *
 * Le schéma est idempotent (CREATE TABLE IF NOT EXISTS) : le rejouer quand
 * une table manque ne coûte qu'une fois et répare tout seul. Une base déjà
 * complète ne paie qu'un test d'existence.
 */
function wsm_ensure_nouvelles_tables(PDO $pdo): void {
    // Pas de garde statique : elle rendrait la fonction non idempotente dans
    // un même processus — une table supprimée entre deux appels ne reviendrait
    // pas, et c'est exactement ce qu'un test a attrapé. Six SHOW TABLES ne
    // valent pas ce risque, et les autres wsm_ensure_* n'en ont pas non plus.
    foreach (['wsm_langs', 'wsm_i18n_history', 'wsm_message_tr',
              'wsm_client_notes', 'wsm_platform_terms', 'wsm_platform_periods',
              'wsm_voucher_uses', 'wsm_subscriptions', 'wsm_subscription_items',
              'wsm_claims', 'wsm_links', 'wsm_campaigns',
              'wsm_page_views', 'wsm_page_paths', 'wsm_order_lookups',
              'wsm_role_profiles', 'wsm_role_screens',
              'wsm_pages', 'wsm_page_i18n', 'wsm_sections', 'wsm_section_i18n', 'wsm_media',
              'wsm_slides', 'wsm_slide_i18n',
              'wsm_subscribers', 'wsm_mkt_events', 'wsm_newsletters', 'wsm_sms_campaigns', 'wsm_sms',
              'wsm_purchase_invoices', 'wsm_purchase_lines', 'wsm_purchase_map',
              'wsm_ad_orders', 'wsm_capi_events', 'wsm_media_meta', 'wsm_social_posts',
              'wsm_forms', 'wsm_form_entries'] as $t) {
        if (!wsm_table_exists($pdo, $t)) { wsm_apply_schema($pdo); break; }
    }
    $links = __DIR__ . '/links.php';
    if (is_file($links)) { require_once $links; wsm_links_ensure($pdo); }
    $camp = __DIR__ . '/campaign.php';
    if (is_file($camp)) { require_once $camp; wsm_camp_ensure($pdo); }
    // Les colonnes qui font AGIR un bon. Sans elles la boutique interrogerait
    // des colonnes absentes et rendrait 500 : la table wsm_vouchers existe en
    // production depuis le premier jour, et CREATE TABLE IF NOT EXISTS ne
    // touche pas une table déjà créée.
    $promo = __DIR__ . '/promo.php';
    if (is_file($promo)) { require_once $promo; wsm_promo_ensure($pdo); }
    // Le registre des langues doit exister ET reprendre ce qui était servi
    // hier : sans cette reprise, la vitrine perdrait l'ukrainien et l'anglais.
    $i18n = __DIR__ . '/i18n.php';
    if (is_file($i18n)) { require_once $i18n; wsm_i18n_ensure($pdo); }
    // Les slajdy de la page d'accueil, et leur premier slajd posé une fois.
    $slajdy = __DIR__ . '/slajdy.php';
    if (is_file($slajdy)) { require_once $slajdy; wsm_slajdy_ensure($pdo); }
}

function wsm_ensure_auth_columns(PDO $pdo): void {
    wsm_ensure_columns($pdo, 'wsm_users', [
        'password_hash'   => ['VARCHAR(255) NULL DEFAULT NULL', 'TEXT DEFAULT NULL'],
        'last_login'      => ['DATETIME NULL DEFAULT NULL',     'TEXT DEFAULT NULL'],
        'failed_attempts' => ['INT NOT NULL DEFAULT 0',         'INTEGER NOT NULL DEFAULT 0'],
        'locked_until'    => ['DATETIME NULL DEFAULT NULL',     'TEXT DEFAULT NULL'],
    ]);
}

/**
 * Colonnes exigées par tpay (payeur, facture, TVA) et InPost (destinataire,
 * point de retrait, poids/dimensions du colis) — voir commerce.php.
 */
function wsm_ensure_commerce_columns(PDO $pdo): void {
    $txt = fn(int $n, string $d = "''") => ["VARCHAR($n) NOT NULL DEFAULT $d", "TEXT NOT NULL DEFAULT $d"];
    $int = ['INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'];

    wsm_ensure_columns($pdo, 'wsm_clients', [
        'client_type'   => $txt(10, "'firma'"),
        'email'         => $txt(200),
        'phone'         => $txt(20),
        'first_name'    => $txt(120),
        'last_name'     => $txt(120),
        'nip'           => $txt(15),
        'vat_eu'        => $txt(20),
        'bill_street'   => $txt(200),
        'bill_building' => $txt(30),
        'bill_postcode' => $txt(10),
        'bill_city'     => $txt(120),
        'bill_country'  => $txt(2, "'PL'"),
    ]);

    wsm_ensure_columns($pdo, 'wsm_client_points', [
        'delivery_method' => $txt(20, "'inpost_locker'"),
        'inpost_point'    => $txt(20),
        'street'          => $txt(200),
        'building'        => $txt(30),
        'postcode'        => $txt(10),
        'city'            => $txt(120),
        'country'         => $txt(2, "'PL'"),
        'contact_phone'   => $txt(20),
        'contact_email'   => $txt(200),
    ]);

    // LES SOUS-CATÉGORIES. Une catégorie peut en ranger d'autres (MyCobotBuddy :
    // Akcesoria › Chwytaki). 0 = rayon de premier niveau. Un seul niveau
    // d'imbrication est montré en vitrine : un menu à trois étages se perd.
    wsm_ensure_columns($pdo, 'wsm_categories', [
        'parent_id' => $int,
    ]);

    wsm_ensure_columns($pdo, 'wsm_products', [
        // CE QUE L'ON VEND : 'towar' (un objet qui part en colis), 'usluga'
        // (une formation, une prestation) ou 'subskrypcja' (un logiciel, une
        // licence, un accès). Seul un 'towar' appelle un transporteur : un
        // panier sans aucun objet ne demande ni Paczkomat ni adresse.
        'rodzaj'          => $txt(12, "'towar'"),
        'sku'             => $txt(60),
        'ean'             => $txt(14),
        'vat_rate'        => ['DECIMAL(4,2) NOT NULL DEFAULT 0.23', 'REAL NOT NULL DEFAULT 0.23'],
        'weight_g'        => $int,
        'length_mm'       => $int,
        'width_mm'        => $int,
        'height_mm'       => $int,
        'parcel_template' => $txt(1),
    ]);
}

/**
 * Contrôles VIES : la table d'historique, plus l'état du dernier contrôle
 * porté sur le client et sur la commande. Idempotent.
 */
function wsm_ensure_vies(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_vies_checks')) wsm_apply_schema($pdo);
    $cols = [
        'vat_status'       => ["VARCHAR(16) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        'vat_checked_at'   => ['DATETIME NULL DEFAULT NULL',      'TEXT DEFAULT NULL'],
        'vat_name'         => ["VARCHAR(200) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        'vat_consultation' => ["VARCHAR(64) NOT NULL DEFAULT ''",  "TEXT NOT NULL DEFAULT ''"],
    ];
    wsm_ensure_columns($pdo, 'wsm_clients', $cols);
    wsm_ensure_columns($pdo, 'wsm_orders',  $cols);
}

/**
 * Pays servis + périmètre de chaque mode de livraison. La liste est seedée une
 * seule fois : ensuite c'est le back-office qui décide où l'on vend.
 */
function wsm_ensure_countries(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_countries')) wsm_apply_schema($pdo);
    // Un mode de livraison ne dessert pas forcément toute l'Europe : InPost
    // Paczkomat, par exemple, est polonais.
    wsm_ensure_columns($pdo, 'wsm_shipping_methods', [
        'countries' => ["VARCHAR(255) NOT NULL DEFAULT 'PL'", "TEXT NOT NULL DEFAULT 'PL'"],
        // LE TYPE DE SERVICE, EN DONNÉE ET PLUS EN DEVINETTE.
        //
        // Quatorze endroits demandaient « delivery_method === 'inpost_locker' »
        // pour savoir s'il fallait un code de point relais ou une adresse. Ça
        // marchait tant qu'il n'existait que deux services. Le jour où l'on
        // ajoute un transporteur, chacun de ces quatorze tests répond « adresse »
        // par défaut — et la caisse réclame une rue pour un point relais, ou
        // l'inverse. On le range donc à côté de la méthode, une fois.
        //
        //   'punkt' = le client désigne un point (Paczkomat, DPD Pickup)
        //   'adres' = le colis va à une adresse
        //   'brak'  = rien ne part : formation, logiciel, accès en ligne. Proposé
        //             SEULEMENT quand le panier ne contient aucun objet.
        'kind'      => ["VARCHAR(12) NOT NULL DEFAULT 'adres'", "TEXT NOT NULL DEFAULT 'adres'"],
        // LE POIDS PLANCHER. Sans lui, un transporteur de palettes se propose
        // pour une commande d'une tablette : le client le choisit, et le
        // transporteur refuse le colis — après la vente.
        'min_weight_g' => ['INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
    ]);
    // Les deux services historiques n'ont jamais porté ce champ : on le pose
    // une fois, d'après ce qu'ils ont toujours été.
    try {
        $pdo->exec("UPDATE wsm_shipping_methods SET kind = 'punkt'
                     WHERE id = 'inpost_locker' AND (kind IS NULL OR kind = '' OR kind = 'adres')");
    } catch (Throwable $e) { /* colonne pas encore là : le prochain démarrage la posera */ }
    // La commande retient le pays de livraison et le régime appliqué.
    wsm_ensure_columns($pdo, 'wsm_orders', [
        'reverse_charge' => ['TINYINT(1) NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
        // QUAND LE COLIS EST PARTI. Sans cette date, on sait qu'une commande
        // est « Wysłane » mais pas si elle l'a été dans le délai promis — et
        // une promesse dont on ne mesure jamais la tenue n'est pas une
        // promesse. Elle est posée une fois et jamais réécrite : c'est un
        // fait, pas un état.
        'shipped_at'     => ['DATETIME NULL DEFAULT NULL', 'TEXT DEFAULT NULL'],
    ]);
    // DEUX REGISTRES, UNE TABLE, ET JAMAIS LE MEME SENS. wsm_vies_checks garde
    // maintenant aussi les reponses de la Biala lista du ministere. Sans cette
    // colonne, une reponse du ministere serait relue comme une reponse de
    // VIES — et l'autoliquidation serait accordee, ou refusee, sur une preuve
    // qui n'en est pas une.
    wsm_ensure_columns($pdo, 'wsm_vies_checks', [
        'source' => ["VARCHAR(8) NOT NULL DEFAULT 'vies'", "TEXT NOT NULL DEFAULT 'vies'"],
    ]);
    try {
        if (!(int) $pdo->query("SELECT COUNT(*) FROM wsm_countries")->fetchColumn()) {
            require_once __DIR__ . '/seed.php';
            wsm_seed_countries($pdo);
        }
    } catch (Throwable $e) { /* table absente : le schéma vient d'échouer */ }
}

/**
 * Deux règles commerciales : la remise au poids, et le fait qu'une commande
 * passe même si le stock ne suit pas (on recontacte l'acheteur). Idempotent.
 */
function wsm_ensure_trade(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_discount_tiers')) wsm_apply_schema($pdo);
    $int = ['INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'];
    wsm_ensure_columns($pdo, 'wsm_orders', [
        // Une commande peut dépasser le stock : on l'accepte et on prévient.
        'backorder'        => ['TINYINT(1) NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
        'discount_percent' => ['DECIMAL(5,2) NOT NULL DEFAULT 0', 'REAL NOT NULL DEFAULT 0'],
        'discount_amount'  => $int,
        // Le bon utilisé, GELÉ sur la commande. Le code peut être modifié ou
        // retiré demain ; ce que cette commande-là a obtenu doit rester
        // lisible dans un an, quand quelqu'un demandera pourquoi elle a coûté
        // 40 zł de moins. Même principe qu'une ligne de facture.
        'voucher_code'   => ["VARCHAR(60) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        'voucher_amount' => $int,
    ]);
    wsm_ensure_columns($pdo, 'wsm_order_items', [
        // Combien de cette ligne reste à produire : ce que l'atelier doit savoir.
        'backorder' => $int,
        // Le coût de revient AU MOMENT DE LA VENTE, en grosze. Recalculer une
        // marge d'il y a six mois avec le prix d'achat d'aujourd'hui donnerait
        // un chiffre faux : la matière première bouge, la vente est passée.
        // Comme les lignes de facture, la ligne de commande fige ce qu'elle
        // doit prouver.
        'unit_cost' => $int,
    ]);
    // Ce que le transporteur nous facture, à distinguer de ce que le client
    // paie. Sans les deux, « quelle part du port le client couvre-t-il ? » n'a
    // pas de réponse : le rapport vaudrait 100 % par construction.
    wsm_ensure_columns($pdo, 'wsm_shipping_methods', [
        'cost_net' => $int,
    ]);
    try {
        if (!(int) $pdo->query("SELECT COUNT(*) FROM wsm_discount_tiers")->fetchColumn()) {
            require_once __DIR__ . '/seed.php';
            wsm_seed_discounts($pdo);
        }
    } catch (Throwable $e) { /* table absente : le schéma vient d'échouer */ }
}

/**
 * Messagerie : modèles, file des messages, réglages d'intégration. Les modèles
 * ne sont semés qu'une fois — ensuite c'est la console qui les écrit, et un
 * déploiement ne doit jamais réécrire un texte que quelqu'un a corrigé.
 */
function wsm_ensure_mail(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_messages') || !wsm_table_exists($pdo, 'wsm_settings')
        || !wsm_table_exists($pdo, 'wsm_mail_templates')) {
        wsm_apply_schema($pdo);
    }
    // La newsletter part en HTML (avec sa version texte) et porte son lien de
    // désinscription dans l'en-tête List-Unsubscribe : deux colonnes de plus
    // sur la file, vides pour tous les messages d'avant.
    wsm_ensure_columns($pdo, 'wsm_messages', [
        'html'      => ['MEDIUMTEXT NULL', 'TEXT NULL'],
        'unsub_url' => ["VARCHAR(500) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
    ]);
    try {
        require_once __DIR__ . '/seed.php';
        if (!(int) $pdo->query("SELECT COUNT(*) FROM wsm_mail_templates")->fetchColumn()) {
            wsm_seed_mail_templates($pdo);
        } else {
            // Une base déjà en service : on n'ajoute QUE les modèles nouveaux.
            // Sans ça, une fonctionnalité livrée après la mise en route reste
            // muette pour toujours, faute du modèle qu'elle utilise.
            wsm_seed_mail_templates_topup($pdo);
        }
    } catch (Throwable $e) { /* table absente : le schéma vient d'échouer */ }
}

/** Facturation : les deux tables du document. Idempotent. */
function wsm_ensure_invoices(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_invoices') || !wsm_table_exists($pdo, 'wsm_invoice_items')) {
        wsm_apply_schema($pdo);
    }
    // La caisse fiscale (fiskal.php) : où en est le reçu de CE paragon chez
    // Fakturownia, et le lien de l'e-paragon quand l'imprimante l'a émis.
    $txt = fn(int $n) => ["VARCHAR($n) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"];
    wsm_ensure_columns($pdo, 'wsm_invoices', [
        'fiskal_status' => $txt(16),
        'fiskal_forma'  => $txt(8),
        'fiskal_id'     => $txt(32),
        'fiskal_url'    => $txt(250),
        'fiskal_note'   => $txt(250),
        'fiskal_proby'  => ['INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
        'fiskal_at'     => ['DATETIME NULL DEFAULT NULL', 'TEXT DEFAULT NULL'],
    ]);
}

/** Journal des mouvements de stock. Idempotent. */
function wsm_ensure_stock(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_stock_moves') || !wsm_table_exists($pdo, 'wsm_stock_docs')) {
        wsm_apply_schema($pdo);
    }
    // Le mouvement rattaché à son document : c'est ce lien qui permet de
    // rouvrir un bon et de retrouver exactement ce qui est entré ou sorti.
    wsm_ensure_columns($pdo, 'wsm_stock_moves', [
        'doc_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'INTEGER DEFAULT NULL'],
    ]);
}

/** Une table existe-t-elle ? (MySQL + SQLite) */
function wsm_table_exists(PDO $pdo, string $table): bool {
    try {
        $q = wsm_config()['engine'] === 'mysql'
            ? $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))
            : $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table));
        return (bool) $q->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Boutique en ligne : tables commande/paiement/expédition + colonnes vitrine
 * sur wsm_products. La base de production existe déjà, donc
 * CREATE TABLE IF NOT EXISTS suffit pour les nouvelles tables mais PAS pour
 * les nouvelles colonnes d'une table déjà créée — d'où wsm_ensure_columns.
 * Idempotent : appelé à chaque requête, ne fait rien quand tout est en place.
 */
function wsm_ensure_shop(PDO $pdo): void {
    if (!wsm_table_exists($pdo, 'wsm_orders')) {
        wsm_apply_schema($pdo);                       // idempotent : n'ajoute que ce qui manque
    }
    $txt = fn(int $n, string $d = "''") => ["VARCHAR($n) NOT NULL DEFAULT $d", "TEXT NOT NULL DEFAULT $d"];
    wsm_ensure_columns($pdo, 'wsm_products', [
        'slug'         => $txt(80),
        'shop_visible' => ['TINYINT(1) NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
        'stock'        => ['INT NOT NULL DEFAULT 0',        'INTEGER NOT NULL DEFAULT 0'],
        'image_url'    => $txt(255),
        'swatch_from'  => $txt(32, "'--brand-500'"),
        'swatch_to'    => $txt(32, "'--brand-800'"),
        'origin'       => $txt(80),
        'cocoa'        => $txt(16),
        'unit_label'   => $txt(40),
        'badge'        => $txt(40),
        // Les valeurs nutritives pour 100 g, en JSON (shop.php :
        // WSM_NUTRITION_FIELDS). Vide = la fiche n'affiche pas le tableau.
        'nutrition'    => ['TEXT', 'TEXT'],
    ]);
    // La commission de la plateforme prélevée par Stripe sur CE paiement
    // (application fee, en grosze) : le décompte mensuel de Platforma la
    // reprend comme « déjà payée ».
    wsm_ensure_columns($pdo, 'wsm_payments', [
        'app_fee' => ['INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
    ]);
    // L'accord du client pour un e-paragon (fiskal.php, règle 3) : 'e',
    // 'papier', ou '' quand la caisse ne le demandait pas encore.
    wsm_ensure_columns($pdo, 'wsm_orders', ['paragon_forma' => $txt(8)]);

    // Contenu de la boutique : modes de livraison + libellés traduits.
    try {
        if (!(int) $pdo->query("SELECT COUNT(*) FROM wsm_shipping_methods")->fetchColumn()
            || !(int) $pdo->query("SELECT COUNT(*) FROM wsm_shop_i18n")->fetchColumn()) {
            require_once __DIR__ . '/seed.php';
            wsm_seed_shop($pdo);           // base neuve : semis complet
        } else {
            // BASE DÉJÀ EN SERVICE : on ajoute UNIQUEMENT les libellés
            // nouveaux. Sans ça, une page livrée après la mise en route
            // s'afficherait en production avec des champs sans étiquette —
            // et ça marcherait parfaitement en développement, où la base est
            // refaite. C'est exactement l'omission qui avait rendu muets les
            // modèles de mail ajoutés après le démarrage.
            require_once __DIR__ . '/cms.php';
            wsm_cms_topup_all($pdo);
        }
    } catch (Throwable $e) { /* tables absentes : le schéma vient d'échouer, on n'aggrave pas */ }
}

/**
 * Upgrade path for databases created before the landing tables existed:
 * the schema files are idempotent (IF NOT EXISTS), so re-applying them adds
 * only what's missing, then the landing content is (re)seeded from
 * landing/content_seed.json. No-op when the table is already there.
 */
function wsm_ensure_landing(PDO $pdo): void {
    $cfg = wsm_config();
    try {
        $q = $cfg['engine'] === 'mysql'
            ? $pdo->query("SHOW TABLES LIKE 'wsm_landing_i18n'")
            : $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='wsm_landing_i18n'");
        if ($q->fetchColumn()) return;
    } catch (Throwable $e) { /* fall through and create */ }
    wsm_apply_schema($pdo);
    require_once __DIR__ . '/seed.php';
    wsm_seed_landing($pdo);
}
