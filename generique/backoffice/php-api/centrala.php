<?php
// ============================================================================
//  centrala.php — LA base de BuddyCorp : les clés d'intégration de TOUTES les
//  boutiques dans une seule table, éditée par le superadmin BuddyCorp (siege/).
//
//  UNE TABLE, MAIS PAS UNE PASSOIRE. Mettre toutes les clés au même endroit
//  ferait d'une boutique piratée la clé de toutes les autres. D'où trois
//  verrous, chacun suffisant à lui seul pour la lecture croisée :
//
//    1. CHIFFRÉ. Chaque valeur est chiffrée (AES-256-GCM) avec la clé de SA
//       boutique ; la clé d'une boutique est dérivée de la clé maîtresse
//       (HMAC-SHA256 « wsb:<boutique> »). Une boutique ne connaît que la
//       sienne ; seul le siège a la maîtresse.
//    2. CLOISONNÉ. En MySQL, une boutique ne lit pas la table : elle lit une
//       VUE qui ne montre que ses lignes, et c'est le seul droit de son
//       compte (wsm creer le pose). Elle n'écrit rien.
//    3. LIÉ. La boutique et le nom de la clé entrent dans les données
//       authentifiées du chiffrement : une valeur recopiée dans la ligne
//       d'une autre boutique, ou sous un autre nom, ne se déchiffre pas.
//
//  CONFIGURATION (fichier serveur de la boutique, jamais le dépôt) :
//    'centrala' => [
//        'engine' => 'mysql', 'mysql' => [host, port, name, user, pass],
//        //   ou 'engine' => 'sqlite', 'sqlite_path' => '…'
//        'boutique' => 'mycobot',
//        'klucz'    => '<64 hex : la clé de CETTE boutique>',   // boutiques
//        'master'   => '<64 hex : la clé maîtresse>',            // siège seul
//        'tabela'   => 'wsb_integracje',   // ou la vue de la boutique
//    ]
//  Sans bloc 'centrala', rien ne change : la boutique garde ses clés dans
//  wsm_settings, comme une installation seule.
// ============================================================================
declare(strict_types=1);

const WSB_TABLES_SQL = [
    // Le registre des boutiques connues du siège.
    "CREATE TABLE IF NOT EXISTS wsb_boutiques (
        slug VARCHAR(40) NOT NULL PRIMARY KEY,
        nom VARCHAR(120) NOT NULL DEFAULT '',
        domaine VARCHAR(190) NOT NULL DEFAULT '',
        active INTEGER NOT NULL DEFAULT 1,
        creee VARCHAR(19) NOT NULL DEFAULT ''
    )",
    // Les clés : une ligne par (boutique, clé), la valeur chiffrée.
    "CREATE TABLE IF NOT EXISTS wsb_integracje (
        boutique VARCHAR(40) NOT NULL,
        cle VARCHAR(80) NOT NULL,
        val TEXT NOT NULL,
        zmieniono VARCHAR(19) NOT NULL DEFAULT '',
        przez VARCHAR(120) NOT NULL DEFAULT '',
        PRIMARY KEY (boutique, cle)
    )",
    // Qui a changé quoi — des NOMS de clés, jamais une valeur.
    "CREATE TABLE IF NOT EXISTS wsb_dziennik (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        kiedy VARCHAR(19) NOT NULL,
        kto VARCHAR(120) NOT NULL DEFAULT '',
        boutique VARCHAR(40) NOT NULL DEFAULT '',
        akcja VARCHAR(60) NOT NULL DEFAULT '',
        szczegoly TEXT
    )",
    // Les sessions du superadmin (12 h). On garde l'empreinte, pas le jeton.
    "CREATE TABLE IF NOT EXISTS wsb_sesje (
        id CHAR(64) NOT NULL PRIMARY KEY,
        telefon VARCHAR(32) NOT NULL,
        wygasa INTEGER NOT NULL,
        ip VARCHAR(64) NOT NULL DEFAULT '',
        utworzono VARCHAR(19) NOT NULL DEFAULT ''
    )",
    // Les essais (code du jour, envois de SMS, vérifications) — le frein.
    "CREATE TABLE IF NOT EXISTS wsb_proby (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        rodzaj VARCHAR(20) NOT NULL,
        klucz VARCHAR(120) NOT NULL,
        kiedy INTEGER NOT NULL
    )",
];

/** Le bloc de configuration, ou [] s'il n'y en a pas. */
function wsb_cfg(): array {
    $c = wsm_config()['centrala'] ?? null;
    return is_array($c) ? $c : [];
}

/** La boutique est-elle reliée à la centrale ? */
function wsb_actif(): bool {
    $c = wsb_cfg();
    return $c !== [] && (string) ($c['boutique'] ?? '') !== '' && wsb_cle_boutique() !== '';
}

/** Ce processus est-il le siège (il détient la clé maîtresse) ? */
function wsb_est_siege(): bool {
    return wsb_master() !== '';
}

function wsb_hex32(string $v): string {
    $v = trim($v);
    return preg_match('/^[0-9a-f]{64}$/i', $v) ? (string) hex2bin($v) : '';
}

function wsb_master(): string { return wsb_hex32((string) (wsb_cfg()['master'] ?? '')); }

/** La clé d'UNE boutique, dérivée de la maîtresse. */
function wsb_derive(string $master, string $boutique): string {
    return hash_hmac('sha256', 'wsb:' . $boutique, $master, true);
}

/** La clé de la boutique courante : la sienne, ou dérivée si l'on est le siège. */
function wsb_cle_boutique(?string $boutique = null): string {
    $c = wsb_cfg();
    $b = $boutique ?? (string) ($c['boutique'] ?? '');
    $m = wsb_master();
    if ($m !== '') return $b === '' ? '' : wsb_derive($m, $b);
    // Une boutique ne connaît que SA clé, et seulement pour elle-même.
    if ($b !== (string) ($c['boutique'] ?? '')) return '';
    return wsb_hex32((string) ($c['klucz'] ?? ''));
}

/** La clé d'une boutique en hexadécimal — pour l'écrire dans sa config (wsm creer). */
function wsb_cle_hex(string $master_hex, string $boutique): string {
    $m = wsb_hex32($master_hex);
    if ($m === '') throw new InvalidArgumentException('clé maîtresse invalide (64 caractères hexadécimaux)');
    return bin2hex(wsb_derive($m, $boutique));
}

function wsb_chiffrer(string $cle, string $boutique, string $nom, string $clair): string {
    $iv = random_bytes(12);
    $tag = '';
    $c = openssl_encrypt($clair, 'aes-256-gcm', $cle, OPENSSL_RAW_DATA, $iv, $tag, $boutique . '|' . $nom);
    if ($c === false) throw new RuntimeException('chiffrement impossible');
    return 'v1:' . base64_encode($iv . $tag . $c);
}

/** null si la valeur ne se déchiffre pas (mauvaise clé, ligne déplacée, altérée). */
function wsb_dechiffrer(string $cle, string $boutique, string $nom, string $val): ?string {
    if (!str_starts_with($val, 'v1:')) return null;
    $b = base64_decode(substr($val, 3), true);
    if ($b === false || strlen($b) < 29) return null;
    $p = openssl_decrypt(substr($b, 28), 'aes-256-gcm', $cle, OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16), $boutique . '|' . $nom);
    return $p === false ? null : $p;
}

/** La connexion à la centrale (une par requête), ou null. */
function wsb_pdo(?PDO $set = null): ?PDO {
    static $pdo = null;
    if ($set !== null) { $pdo = $set; return $pdo; }
    if ($pdo instanceof PDO) return $pdo;
    $c = wsb_cfg();
    if ($c === []) return null;
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false];
    try {
        if (($c['engine'] ?? 'mysql') === 'mysql') {
            $m = (array) ($c['mysql'] ?? []);
            $dsn = 'mysql:host=' . ($m['host'] ?? 'localhost') . ';port=' . ($m['port'] ?? '3306')
                 . ';dbname=' . ($m['name'] ?? 'wsb') . ';charset=utf8mb4';
            $pdo = new PDO($dsn, (string) ($m['user'] ?? ''), (string) ($m['pass'] ?? ''), $opts);
        } else {
            $path = (string) ($c['sqlite_path'] ?? '');
            if ($path === '') return null;
            if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
            $pdo = new PDO('sqlite:' . $path, null, null, $opts);
        }
    } catch (Throwable $e) {
        if (function_exists('wsm_journal')) wsm_journal('centrala', 'connexion impossible : ' . $e->getMessage());
        return null;
    }
    return $pdo;
}

function wsb_mysql(PDO $pdo): bool { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }

/** Crée les tables (siège). */
function wsb_schema(PDO $pdo): void {
    foreach (WSB_TABLES_SQL as $sql) {
        if (!wsb_mysql($pdo)) $sql = str_replace('INTEGER PRIMARY KEY AUTO_INCREMENT', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        else $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $pdo->exec($sql);
    }
}

/** Le nom de la table (ou de la vue) que lit la boutique. */
function wsb_tabela(): string {
    $t = (string) (wsb_cfg()['tabela'] ?? 'wsb_integracje');
    return preg_match('/^[a-z0-9_]{1,64}$/', $t) ? $t : 'wsb_integracje';
}

/**
 * Les clés de la boutique courante, déchiffrées : [nom => valeur].
 * Une valeur qui ne se déchiffre pas est ABSENTE (et notée) — jamais
 * remplacée par du texte illisible qui partirait vers Stripe.
 */
function wsb_klucze(?string $boutique = null, bool $rafraichir = false): array {
    static $cache = [];
    $c = wsb_cfg();
    $b = $boutique ?? (string) ($c['boutique'] ?? '');
    if ($b === '') return [];
    if (!$rafraichir && isset($cache[$b])) return $cache[$b];
    $cle = wsb_cle_boutique($b);
    $pdo = wsb_pdo();
    if ($cle === '' || !$pdo) return $cache[$b] = [];
    $table = wsb_est_siege() ? 'wsb_integracje' : wsb_tabela();
    try {
        $st = $pdo->prepare("SELECT cle, val FROM $table WHERE boutique = ?");
        $st->execute([$b]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        if (function_exists('wsm_journal')) wsm_journal('centrala', 'lecture impossible : ' . $e->getMessage());
        return $cache[$b] = [];
    }
    $out = [];
    foreach ($rows as $r) {
        $v = wsb_dechiffrer($cle, $b, (string) $r['cle'], (string) $r['val']);
        if ($v === null) {
            if (function_exists('wsm_journal')) wsm_journal('centrala', "clé « {$r['cle']} » de « $b » indéchiffrable — ignorée");
            continue;
        }
        $out[(string) $r['cle']] = $v;
    }
    return $cache[$b] = $out;
}

/** Les métadonnées (sans valeur) : [nom => [zmieniono, przez]]. Siège. */
function wsb_klucze_meta(PDO $pdo, string $boutique): array {
    $st = $pdo->prepare("SELECT cle, zmieniono, przez FROM wsb_integracje WHERE boutique = ?");
    $st->execute([$boutique]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(string) $r['cle']] = ['zmieniono' => (string) $r['zmieniono'], 'przez' => (string) $r['przez']];
    return $out;
}

/** Écrit (ou efface, valeur '') une clé d'une boutique. Siège seulement. */
function wsb_zapisz(PDO $pdo, string $boutique, string $nom, string $val, string $kto): void {
    if (!wsb_est_siege()) throw new RuntimeException('écriture réservée au siège');
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $boutique) || !preg_match('/^[a-z0-9_.]{1,80}$/', $nom)) {
        throw new InvalidArgumentException('nom de boutique ou de clé invalide');
    }
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("DELETE FROM wsb_integracje WHERE boutique = ? AND cle = ?")->execute([$boutique, $nom]);
    if ($val !== '') {
        $pdo->prepare("INSERT INTO wsb_integracje (boutique, cle, val, zmieniono, przez) VALUES (?,?,?,?,?)")
            ->execute([$boutique, $nom, wsb_chiffrer(wsb_cle_boutique($boutique), $boutique, $nom, $val), $now, $kto]);
    }
    wsb_dziennik($pdo, $kto, $boutique, $val === '' ? 'klucz usunięty' : 'klucz zmieniony', $nom);
}

function wsb_dziennik(PDO $pdo, string $kto, string $boutique, string $akcja, string $szczegoly = ''): void {
    try {
        $pdo->prepare("INSERT INTO wsb_dziennik (kiedy, kto, boutique, akcja, szczegoly) VALUES (?,?,?,?,?)")
            ->execute([date('Y-m-d H:i:s'), $kto, $boutique, $akcja, $szczegoly]);
    } catch (Throwable $e) { /* le journal ne bloque jamais l'action */ }
}

/** Les boutiques du registre. */
function wsb_boutiques(PDO $pdo): array {
    return $pdo->query("SELECT slug, nom, domaine, active, creee FROM wsb_boutiques ORDER BY slug")->fetchAll() ?: [];
}

function wsb_boutique_enregistrer(PDO $pdo, string $slug, string $nom, string $domaine): void {
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $slug)) throw new InvalidArgumentException('slug invalide');
    $pdo->prepare("DELETE FROM wsb_boutiques WHERE slug = ?")->execute([$slug]);
    $pdo->prepare("INSERT INTO wsb_boutiques (slug, nom, domaine, active, creee) VALUES (?,?,?,1,?)")
        ->execute([$slug, $nom, $domaine, date('Y-m-d H:i:s')]);
}

// ---------------------------------------------------------------------------
//  CONNEXION DIRECTE du superadmin BuddyCorp à la console d'une boutique.
//
//  Le siège signe un lien d'UNE minute avec la clé de CETTE boutique (dérivée
//  de la maîtresse) ; la boutique le vérifie avec la sienne. Un lien ne sert
//  qu'une fois (nonce gardé côté boutique). Une boutique ne peut pas en
//  fabriquer pour une autre : elle n'a que sa clé.
// ---------------------------------------------------------------------------
const WSB_SSO_DUREE = 60;

function wsb_b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function wsb_b64u_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/'), true); }

/** Siège : le jeton pour entrer dans la console de $boutique. */
function wsb_sso_jeton(string $boutique, string $kto): string {
    if (!wsb_est_siege()) throw new RuntimeException('réservé au siège');
    $cle = wsb_cle_boutique($boutique);
    $p = wsb_b64u((string) json_encode(['b' => $boutique, 'exp' => time() + WSB_SSO_DUREE,
        'n' => bin2hex(random_bytes(16)), 'k' => $kto], JSON_UNESCAPED_UNICODE));
    return $p . '.' . wsb_b64u(hash_hmac('sha256', $p, $cle, true));
}

/** Boutique : le jeton est-il valable pour MOI ? Rend ['n','k','exp'] ou une erreur (string). */
function wsb_sso_verifier(string $jeton): array|string {
    if (!wsb_actif() || wsb_est_siege()) return 'boutique non reliée à la centrale';
    if (!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $jeton)) return 'lien illisible';
    [$p, $sig] = explode('.', $jeton, 2);
    $attendu = wsb_b64u(hash_hmac('sha256', $p, wsb_cle_boutique(), true));
    if (!hash_equals($attendu, $sig)) return 'signature refusée';
    $d = json_decode(wsb_b64u_dec($p), true);
    if (!is_array($d) || ($d['b'] ?? '') !== (string) (wsb_cfg()['boutique'] ?? '')) return 'lien d’une autre boutique';
    $exp = (int) ($d['exp'] ?? 0);
    if ($exp < time()) return 'lien expiré';
    if ($exp > time() + WSB_SSO_DUREE + 30) return 'lien invalide';
    if (!preg_match('/^[0-9a-f]{32}$/', (string) ($d['n'] ?? ''))) return 'lien invalide';
    return ['n' => (string) $d['n'], 'k' => (string) ($d['k'] ?? ''), 'exp' => $exp];
}
