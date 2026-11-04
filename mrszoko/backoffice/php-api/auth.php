<?php
// ============================================================================
//  auth.php — authentification de l'API webshop_mrszoko.
//
//  Deux voies, aucune valeur par défaut (fail-closed) :
//   1. SESSION UTILISATEUR — e-mail + mot de passe (wsm_users.password_hash,
//      hachage password_hash/PASSWORD_DEFAULT). Cookie de session HttpOnly,
//      SameSite=Lax, Secure dès que la requête est en HTTPS. C'est la voie des
//      humains ; les rôles de wsm_users sont RÉELLEMENT appliqués.
//   2. JETON DE SERVICE — en-tête X-Admin-Token, pour l'automatisation et les
//      tests. Doit être configuré explicitement (config.local.php ou
//      WSM_ADMIN_TOKEN) : si aucun jeton n'est configuré, cette voie est
//      entièrement désactivée — jamais de secret « par défaut ».
//
//  Règles appliquées :
//   • /landing/content        → public (c'est le site vitrine)
//   • GET  /franchisor/*      → tout utilisateur authentifié et actif
//   • POST /franchisor/*      → rôle « Centrala » (siège) uniquement
// ============================================================================
declare(strict_types=1);

/**
 * L'échec d'authentification, quand personne ne rend du JSON.
 *
 * wsm_login() signale ses refus par wsm_fail(), qui est défini par le ROUTEUR
 * d'API : il écrit un corps JSON et sort. Une page HTML qui appellerait
 * wsm_login() recevrait donc `{"error":"bad_credentials"}` au milieu de son
 * formulaire. Plutôt que de recopier la logique de connexion — verrouillage,
 * compteur d'échecs, régénération de session, réhachage — dans un second
 * endroit qui aurait dérivé, on donne ici un défaut qui LÈVE. Le routeur
 * déclare la sienne avant d'inclure ce fichier (PHP déclare les fonctions de
 * premier niveau à la compilation), donc l'API garde son comportement exact.
 */
if (!function_exists('wsm_fail')) {
    function wsm_fail(string $msg, int $code = 400): void {
        throw new RuntimeException($msg, $code);
    }
}

const WSM_SESSION_NAME     = 'WSMSESS';
const WSM_SESSION_IDLE     = 28800;  // 8 h sans activité → session expirée
const WSM_LOGIN_MAX_TRIES  = 5;      // échecs avant verrouillage du compte
const WSM_LOGIN_LOCK       = 900;    // 15 min de verrouillage
const WSM_PASSWORD_MIN     = 10;
// ---------------------------------------------------------------------------
//  LES RÔLES DE LA CONSOLE
//
//  Ils décrivent une boutique en ligne qui vend du chocolat, pas un réseau de
//  franchise : « Centrala » et « Franczyza » venaient de la maquette d'origine
//  et ne nommaient le métier de personne. Surtout, l'ancien modèle était
//  BINAIRE — Centrala écrivait tout, Franczyza ne lisait que tout — ce qui
//  oblige à donner les clés de la facturation à quelqu'un qui doit seulement
//  imprimer des étiquettes.
//
//  Chaque rôle porte donc la liste des écrans qu'il peut OUVRIR, et pour
//  chacun s'il peut y ÉCRIRE. Un écran hors de la liste disparaît du rail et
//  répond 403 : cacher un lien n'est pas une protection, la page se garde
//  elle-même.
//
//  'w' = ouvrir et modifier · 'r' = ouvrir en lecture seule · '*' = tous.
// ---------------------------------------------------------------------------
const WSM_ROLE_ADMIN      = 'Administrator';
const WSM_ROLE_SUPERADMIN = 'Superadmin';

/**
 * L'ancien vocabulaire, et ce qu'il devient. Sert à la migration des comptes
 * existants et à ne pas éjecter quelqu'un dont la ligne n'a pas encore été
 * réécrite : une session ouverte sur « Centrala » doit continuer de marcher.
 */
const WSM_ROLES_ANCIENS = ['Centrala' => WSM_ROLE_ADMIN, 'Franczyza' => 'Podgląd'];

/**
 * LES PROFILS TELS QUE LE CODE LES DÉFINIT — le défaut, et le recours.
 *
 * Séparée de wsm_roles() depuis que la console peut les redéfinir (roles.php) :
 * il faut pouvoir montrer « ce que dit le code » à côté de « ce qui a été
 * changé », et pouvoir revenir au premier d'un clic. Sans cette séparation, le
 * défaut n'existerait plus nulle part une fois la surcouche posée.
 */
function wsm_roles_base(): array {
    // Les écrans « métier » de chaque rôle. Les impressions (…_druk.php) et
    // les étiquettes suivent l'écran qui les ouvre — la liste de ces
    // satellites, et la règle, sont dans roles.php (wsm_profil_satellites).
    $vente  = ['zamowienia.php' => 'w', 'zamowienie_druk.php' => 'w',
               'klienci.php' => 'w', 'kontrahenci.php' => 'w', 'poczta.php' => 'w',
               'przypomnienia.php' => 'w', 'kampanie.php' => 'w', 'zgloszenia.php' => 'w',
               'newsletter.php' => 'w', 'sms.php' => 'w', 'subskrybenci.php' => 'w',
               'rabaty.php' => 'w', 'produkty.php' => 'r', 'faktury.php' => 'r', 'pulpit.php' => 'r',
               'magazyn_produkt.php' => 'r'];
    // etykieta_dpd.php manquait ici, et personne ne l'a vu : le magasin
    // imprimait l'étiquette InPost et se heurtait à un 403 sur celle de DPD,
    // pour le même geste. C'est exactement le genre d'oubli que la règle des
    // satellites empêche de refaire.
    $stock  = ['wysylka.php' => 'w', 'magazyn.php' => 'w', 'magazyn_druk.php' => 'w',
               'etykieta_druk.php' => 'w', 'etykieta_inpost.php' => 'w', 'etykieta_dpd.php' => 'w',
               'produkty.php' => 'w', 'przyjecie.php' => 'w', 'magazyn_produkt.php' => 'w',
               'zamowienia.php' => 'r', 'zamowienie_druk.php' => 'r', 'pulpit.php' => 'r'];
    $compta = ['faktury.php' => 'w', 'faktura_druk.php' => 'w', 'ksef.php' => 'w', 'platnosci.php' => 'w',
               'kontrahenci.php' => 'w', 'zamowienia.php' => 'r', 'klienci.php' => 'r',
               'zgloszenia.php' => 'r', 'pulpit.php' => 'r', 'przyjecie.php' => 'r', 'magazyn_produkt.php' => 'r'];

    return [
        // Le propriétaire de la plateforme. Seul rôle qui ouvre l'écran de
        // facturation de la plateforme — et seul rôle qui peut l'attribuer
        // (voir wsm_peut_donner_role) : sinon un Administrator compromis se
        // hisserait tout seul jusqu'à sa propre facture.
        WSM_ROLE_SUPERADMIN => ['ecrans' => '*', 'ecrit' => true, 'super' => true,
            // CES PHRASES SONT LUES PAR L'UTILISATEUR, pas par nous : elles
            // s'affichent dans la liste déroulante de Użytkownicy au moment
            // d'attribuer un rôle, et maintenant dans le tableau des profils.
            // Les deux premières étaient restées en français — la console est
            // polonaise, et personne ne l'avait vu tant qu'on ne les avait pas
            // mises côte à côte.
            'aide' => 'Superadmin — wszystko, łącznie z rozliczeniem platformy'],
        WSM_ROLE_ADMIN => ['ecrans' => '*', 'ecrit' => true,
            'aide' => 'Administrator — cały sklep, bez rozliczenia platformy'],
        'Sprzedaż' => ['ecrans' => $vente,
            'aide' => 'Sprzedaż — zamówienia, klienci, poczta, zgłoszenia, rabaty'],
        'Magazyn' => ['ecrans' => $stock,
            'aide' => 'Magazyn — wysyłka, stany, produkty i etykiety'],
        'Księgowość' => ['ecrans' => $compta,
            'aide' => 'Księgowość — faktury, KSeF, dane kontrahentów'],
        // Lecture seule PARTOUT sauf les écrans qui donnent les clés : les
        // comptes et les réglages (jetons, mots de passe) ne se regardent pas
        // « juste pour voir ».
        // Płatności aussi : ses composants Stripe remboursent et contestent —
        // ce ne sont pas des écrans « à regarder ».
        'Podgląd' => ['ecrans' => '*', 'ecrit' => false, 'sauf' => ['uzytkownicy.php', 'ustawienia.php', 'platnosci.php'],
            'aide' => 'Podgląd — wszystko do czytania, nic do zmiany'],
    ];
}

/**
 * Les profils EN VIGUEUR : ceux du code, redéfinis par la console s'il y a
 * lieu (roles.php).
 *
 * Le test function_exists() n'est pas une précaution de style : auth.php est
 * chargé par des chemins qui ne connaissent pas roles.php — la boutique
 * publique, migrate.php, une suite de tests. Sans lui, ajouter la surcouche
 * aurait fait tomber la vitrine en « undefined function », c'est-à-dire tout
 * casser pour un écran d'administration que le client ne voit jamais.
 */
function wsm_roles(): array {
    $base = wsm_roles_base();
    return function_exists('wsm_roles_surcouche') ? wsm_roles_surcouche($base) : $base;
}

/** Le rôle d'un compte, ancien vocabulaire compris. */
function wsm_role_de(?array $u): string {
    $r = trim((string) ($u['role'] ?? ''));
    if (!empty($u['service'])) return WSM_ROLE_ADMIN;      // jeton de service
    $r = WSM_ROLES_ANCIENS[$r] ?? $r;
    return isset(wsm_roles()[$r]) ? $r : 'Podgląd';        // rôle inconnu = le moins permissif
}

/**
 * Le droit d'un compte sur un écran : 'w', 'r' ou '' (interdit).
 *
 * Un écran absent de la liste d'un rôle n'est pas « à voir plus tard » : il
 * est fermé. C'est le sens de la liste — on énumère ce qu'on ouvre, jamais ce
 * qu'on ferme, sinon chaque écran livré serait ouvert à tous par défaut.
 */
/**
 * Cet écran doit-il NIER SON EXISTENCE plutôt que se dire interdit ?
 *
 * Un refus ordinaire se dit : « cet écran n'est pas de ton métier » est un
 * 403, il aide, et on sait à qui demander. L'écran de la plateforme est d'une
 * autre nature — il chiffre ce que la boutique doit à qui la lui loue. Un 403
 * confirmerait à un locataire curieux qu'il y a quelque chose derrière, et
 * c'est déjà la moitié du travail de qui cherche.
 *
 * La règle vit ici, et pas dans la page : la page ne s'exécute jamais, le
 * garde de console_boot() répond avant elle. Elle y était écrite en
 * commentaire et n'était plus appliquée depuis que les écrans sont gardés en
 * amont — un 403 partait à sa place.
 */
function wsm_ecran_cache(string $ecran): bool {
    return strtolower(basename($ecran)) === 'superadmin.php';
}

function wsm_droit_ecran(?array $u, string $ecran): string {
    $role = wsm_role_de($u);
    $def  = wsm_roles()[$role] ?? [];
    if (in_array($ecran, (array) ($def['sauf'] ?? []), true)) return '';
    if (($def['ecrans'] ?? null) === '*') {
        if ($ecran === 'superadmin.php' && empty($def['super'])) return '';
        return !empty($def['ecrit']) ? 'w' : 'r';
    }
    $d = (array) ($def['ecrans'] ?? []);
    return (string) ($d[$ecran] ?? '');
}

/** Qui peut attribuer quel rôle. Seul un Superadmin fabrique un Superadmin. */
function wsm_peut_donner_role(?array $u, string $role): bool {
    if (wsm_droit_ecran($u, 'uzytkownicy.php') !== 'w') return false;
    if ($role === WSM_ROLE_SUPERADMIN) return wsm_role_de($u) === WSM_ROLE_SUPERADMIN;
    return isset(wsm_roles()[$role]);
}

/** La requête courante arrive-t-elle en HTTPS (y compris derrière un proxy) ? */
function wsm_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    return ((int) ($_SERVER['SERVER_PORT'] ?? 0)) === 443;
}

function wsm_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (PHP_SAPI === 'cli') return;                 // migrate.php / tests hors HTTP
    ini_set('session.use_strict_mode', '1');        // refuse un identifiant de session non émis par nous
    session_name(WSM_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,                          // inaccessible au JavaScript
        'secure'   => wsm_is_https(),
        'samesite' => 'Lax',
    ]);
    session_start();
}

function wsm_logout(): void {
    if (PHP_SAPI === 'cli') return;
    wsm_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'] ?: '/',
            'domain'   => $p['domain'] ?? '',
            'secure'   => (bool) ($p['secure'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

/** L'utilisateur de la session courante, ou null. Applique l'expiration d'inactivité. */
function wsm_current_user(PDO $pdo): ?array {
    if (PHP_SAPI === 'cli') return null;
    wsm_session_start();
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return null;
    $seen = (int) ($_SESSION['seen'] ?? 0);
    if ($seen > 0 && (time() - $seen) > WSM_SESSION_IDLE) { wsm_logout(); return null; }
    $_SESSION['seen'] = time();

    $st = $pdo->prepare("SELECT id, nom, email, role, portee, act FROM wsm_users WHERE id = ?");
    $st->execute([(int) $uid]);
    $u = $st->fetch();
    if (!$u || !$u['act']) { wsm_logout(); return null; }   // compte supprimé ou désactivé → session coupée
    return $u;
}

/** Le jeton de service est-il configuré ET correctement présenté ? */
function wsm_service_token_ok(): bool {
    $cfg = wsm_config();
    $expected = (string) ($cfg['admin_token'] ?? '');
    if ($expected === '') return false;                       // non configuré → voie fermée
    $sent = (string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
    if ($sent === '') return false;
    return hash_equals($expected, $sent);                     // comparaison à temps constant
}

/**
 * Exige une identité pour lire. Renvoie l'acteur :
 *   ['nom','role','service'=>bool].
 * Coupe la requête en 401 si personne n'est authentifié.
 */
function wsm_require_read(PDO $pdo): array {
    if (wsm_service_token_ok()) {
        return ['nom' => 'Konsola marki', 'email' => '', 'role' => WSM_ROLE_ADMIN, 'portee' => '', 'service' => true];
    }
    $u = wsm_current_user($pdo);
    if (!$u) wsm_fail('unauthenticated', 401);
    $u['service'] = false;
    return $u;
}

/**
 * Exige un rôle qui écrit PARTOUT. 403 si simplement authentifié.
 *
 * L'API n'a pas d'écran, donc pas de périmètre à appliquer : elle ne peut pas
 * dire « cette route appartient au magasin ». Elle reste donc réservée aux
 * deux rôles complets, et les rôles métier passent par les écrans, qui
 * connaissent, eux, le leur.
 *
 * Le rôle est lu par wsm_role_de() et pas comparé à la main : un compte encore
 * en « Centrala » — base non migrée, session déjà ouverte — doit continuer de
 * travailler. Comparer la chaîne brute l'aurait éjecté au premier
 * déploiement, et c'est exactement ce qui est arrivé au test d'authentification
 * avant cette ligne.
 */
function wsm_require_write(PDO $pdo): array {
    $actor = wsm_require_read($pdo);
    if (empty($actor['service'])
        && !in_array(wsm_role_de($actor), [WSM_ROLE_ADMIN, WSM_ROLE_SUPERADMIN], true)) {
        wsm_fail('forbidden_role', 403);
    }
    return $actor;
}

/**
 * Connexion. Message d'erreur identique que l'e-mail existe ou non, délai
 * aléatoire, et verrouillage temporaire après WSM_LOGIN_MAX_TRIES échecs.
 */
function wsm_login(PDO $pdo, string $email, string $password): array {
    $email = strtolower(trim($email));
    $st = $pdo->prepare("SELECT * FROM wsm_users WHERE LOWER(email) = ?");
    $st->execute([$email]);
    $u = $st->fetch();
    $now = time();

    if ($u && !empty($u['locked_until']) && strtotime((string) $u['locked_until']) > $now) {
        wsm_fail('account_locked', 429);
    }

    $hash = (string) ($u['password_hash'] ?? '');
    $ok = $u && $u['act'] && $hash !== '' && password_verify($password, $hash);

    if (!$ok) {
        if ($u) {
            $n = (int) $u['failed_attempts'] + 1;
            $lock = $n >= WSM_LOGIN_MAX_TRIES ? date('Y-m-d H:i:s', $now + WSM_LOGIN_LOCK) : null;
            $pdo->prepare("UPDATE wsm_users SET failed_attempts = ?, locked_until = ? WHERE id = ?")
                ->execute([$n, $lock, $u['id']]);
        }
        usleep(random_int(150000, 400000));   // brouille la mesure du temps de réponse
        wsm_fail('bad_credentials', 401);
    }

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $pdo->prepare("UPDATE wsm_users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    $pdo->prepare("UPDATE wsm_users SET failed_attempts = 0, locked_until = NULL, last_login = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), $u['id']]);

    wsm_session_start();
    session_regenerate_id(true);              // pare la fixation de session
    $_SESSION['uid']  = (int) $u['id'];
    $_SESSION['seen'] = $now;

    wsm_audit($pdo, (string) $u['nom'], 'Logowanie', 'wsm_users ' . $u['email'], 'Sieć');
    return wsm_public_user($u);
}

/** Projection sans secret, sûre à renvoyer au navigateur. */
function wsm_public_user(array $u): array {
    return [
        'nom'    => $u['nom'] ?? '',
        'email'  => $u['email'] ?? '',
        'role'   => $u['role'] ?? '',
        'portee' => $u['portee'] ?? '',
        // L'API n'a pas d'écran : « admin » y veut dire « peut écrire ».
        // Seuls les deux rôles qui écrivent partout l'obtiennent — un rôle
        // métier passe par les écrans, qui appliquent SON périmètre.
        'admin'  => !empty($u['service'])
                    || in_array(wsm_role_de($u), [WSM_ROLE_ADMIN, WSM_ROLE_SUPERADMIN], true),
    ];
}

/**
 * Crée ou met à jour le mot de passe d'un compte (CLI : migrate.php).
 * Renvoie un message destiné à l'opérateur ; ne journalise jamais le secret.
 */
function wsm_set_password(PDO $pdo, string $email, string $password, string $role = WSM_ROLE_ADMIN, string $nom = ''): string {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('e-mail invalide');
    if (strlen($password) < WSM_PASSWORD_MIN) throw new InvalidArgumentException('mot de passe trop court (min ' . WSM_PASSWORD_MIN . ')');

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $st = $pdo->prepare("SELECT id FROM wsm_users WHERE LOWER(email) = ?");
    $st->execute([$email]);
    $id = $st->fetchColumn();

    if ($id) {
        $pdo->prepare("UPDATE wsm_users SET password_hash = ?, act = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?")
            ->execute([$hash, (int) $id]);
        return "mot de passe mis à jour pour $email";
    }
    $pdo->prepare("INSERT INTO wsm_users (nom, email, role, portee, act, password_hash) VALUES (?,?,?,?,1,?)")
        ->execute([$nom !== '' ? $nom : $email, $email, $role, 'Cała sieć', $hash]);
    return "compte créé : $email (rôle $role)";
}

/**
 * LE MÊME TRAVAIL, ÉMIS EN SQL AU LIEU D'ÊTRE EXÉCUTÉ.
 *
 * POURQUOI CETTE VOIE EXISTE — et pourquoi le mot de passe administrateur
 * n'est JAMAIS arrivé en production jusqu'ici. Le php en ligne de commande du
 * serveur n'a pas pdo_mysql : `wsm_pdo()` y lève « could not find driver »
 * avant la première requête. Le déploiement appelait pourtant
 * `php migrate.php --set-password` sur cette machine, dans un `if` dont
 * l'échec était rattrapé — il affichait « WSM_ADMIN_PASSWORD refusé », passait
 * au vert, et le compte gardait le mot de passe d'avant. Le secret pouvait
 * être posé, changé, refait : rien n'atteignait la base. C'est exactement la
 * panne de `--sync-content`, sur un autre objet.
 *
 * Émettre du SQL ne demande aucune connexion. password_hash() non plus. La
 * machine qui déploie peut donc calculer le hachage, et le client mysql — qui,
 * lui, marche là-bas — pose le résultat.
 *
 * CE QUI VOYAGE EST UN HACHAGE, JAMAIS LE MOT DE PASSE. Le SQL produit ici
 * peut traverser un scp, dormir dans /tmp et finir dans un journal sans rien
 * livrer : bcrypt ne se relit pas. Le mot de passe en clair, lui, ne quitte
 * pas la mémoire du processus qui appelle cette fonction.
 *
 * POURQUOI PAS `ON DUPLICATE KEY UPDATE`, qui tiendrait en une instruction :
 * il repose ENTIÈREMENT sur la clé unique uq_wsm_users_email. Elle est bien
 * dans le fichier de schéma — mais rien, dans aucun contrôle de déploiement,
 * ne vérifie qu'elle est en base sur ce serveur-là, dont les tables ont
 * plusieurs générations. Si elle manquait, l'instruction n'échouerait pas :
 * elle créerait un SECOND compte avec la même adresse, et la connexion
 * prendrait l'un des deux au hasard. Un doublon silencieux vaut moins qu'une
 * dépendance en moins. On compte d'abord, on écrit ensuite, et l'insertion se
 * lit dans une table dérivée — elle ne touche donc jamais wsm_users.
 *
 * On ne réécrit ni le nom ni le rôle d'un compte existant : seulement de quoi
 * entrer, et le déverrouillage qui va avec — reprendre la main sur un compte
 * verrouillé par cinq essais ratés est justement le jour où l'on se sert de
 * ceci.
 */
function wsm_set_password_sql(string $email, string $password, string $role = WSM_ROLE_ADMIN, string $nom = ''): string {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('e-mail invalide');
    if (strlen($password) < WSM_PASSWORD_MIN) throw new InvalidArgumentException('mot de passe trop court (min ' . WSM_PASSWORD_MIN . ')');
    if (!function_exists('wsm_sql_txt')) require_once __DIR__ . '/seed.php';

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $e = wsm_sql_txt($email);
    $h = wsm_sql_txt($hash);
    $n = wsm_sql_txt($nom !== '' ? $nom : $email);
    $r = wsm_sql_txt($role);
    $p = wsm_sql_txt('Cała sieć');          // portée par défaut, comme wsm_set_password()

    // Le SELECT final n'est pas décoratif : c'est la PREUVE que la ligne porte
    // bien ce hachage-là. Sans lui on saurait que le SQL a tourné, pas qu'il a
    // touché quelque chose — et c'est précisément la nuance qui a laissé trois
    // déploiements se dire verts. Il ne sort ni le mot de passe ni le hachage.
    return <<<SQL
-- Mister Szoko — mot de passe d'un compte de la console.
-- Produit par migrate.php --set-password-sql. Ne contient AUCUN mot de passe
-- en clair : seul le hachage bcrypt voyage, et il ne se relit pas.
SELECT COUNT(*) INTO @wsm_konto FROM wsm_users WHERE LOWER(email) = $e;
UPDATE wsm_users
   SET password_hash = $h, act = 1, failed_attempts = 0, locked_until = NULL
 WHERE LOWER(email) = $e;
INSERT INTO wsm_users (nom, email, role, portee, act, password_hash)
SELECT $n, $e, $r, $p, 1, $h
  FROM (SELECT @wsm_konto AS istnieje) AS q
 WHERE q.istnieje = 0;
SELECT CONCAT('  konto ', email, ' (', role, ') : hasło ustawione, konto odblokowane') AS wynik
  FROM wsm_users
 WHERE LOWER(email) = $e AND password_hash = $h AND act = 1;

SQL;
}

/** Existe-t-il au moins un compte actif capable de se connecter ? */
function wsm_has_login_account(PDO $pdo): bool {
    try {
        $n = $pdo->query("SELECT COUNT(*) FROM wsm_users WHERE act = 1 AND password_hash IS NOT NULL AND password_hash <> ''")->fetchColumn();
        return ((int) $n) > 0;
    } catch (Throwable $e) {
        return false;
    }
}
