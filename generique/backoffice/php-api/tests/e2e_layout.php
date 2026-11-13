<?php
// ============================================================================
//  e2e_layout.php — la boutique telle qu'elle est DÉPLOYÉE, pas telle qu'elle
//  est rangée dans le dépôt.
//
//  CE QUI EST ARRIVÉ. Le formulaire de contact rendait 500 sur le serveur,
//  avec un corps VIDE — pas de message, pas de trace visible, une page morte.
//  En local il marchait parfaitement, et tous les tests étaient verts. La
//  cause tenait en un chemin :
//
//      require_once dirname(__DIR__) . '/backoffice/php-api/contact.php';
//
//  Le déploiement copie `php-api/` vers `backoffice/api/`. Ce dossier
//  `php-api` n'existe QUE dans le dépôt. La ligne ne pouvait pas échouer ici
//  et ne pouvait pas réussir là-bas.
//
//  C'est la quatrième fois dans ce chantier qu'une chose livrée marche en
//  développement et manque en production (modèles de courrier, libellés de
//  la boutique, tables nouvelles, et maintenant un chemin). Le remède n'est
//  pas de relire mieux : c'est de TESTER DANS LA FORME DÉPLOYÉE.
//
//  Ce que fait ce fichier :
//    1. reconstruit l'arborescence du serveur (php-api → backoffice/api) ;
//    2. y lance la boutique ;
//    3. demande CHAQUE page publique et refuse le moindre 500 ;
//    4. interdit, dans tout le dépôt hors de l'API, un chemin qui nomme
//       « php-api » — le dossier qui n'existe pas sur le serveur.
//
//  Usage :  php tests/e2e_layout.php
// ============================================================================
declare(strict_types=1);

$RACINE = dirname(__DIR__, 3);            // …/mrszoko
$API    = dirname(__DIR__);               // …/mrszoko/backoffice/php-api
$PORT   = (int) (getenv('WSM_LAYOUT_PORT') ?: 8097);

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

echo "webshop_mrszoko — la boutique dans sa forme DÉPLOYÉE\n\n";

// ---------------------------------------------------------------------------
//  1. Aucun chemin ne nomme un dossier absent du serveur
// ---------------------------------------------------------------------------
//  Cette lecture est statique et coûte une milliseconde. Elle attrape la
//  faute AVANT de monter quoi que ce soit — et elle la nomme, ce qu'un 500
//  au corps vide ne fait jamais.
echo "-- aucun require ne nomme php-api hors de l'API --\n";
$coupables = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($RACINE, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $p = $f->getPathname();
    if (str_starts_with($p, $API . DIRECTORY_SEPARATOR)) continue;   // l'API a le droit de se nommer
    foreach (file($p) as $n => $ligne) {
        if (!preg_match('/\b(require|require_once|include|include_once)\b/', $ligne)) continue;
        if (!str_contains($ligne, 'php-api')) continue;
        // Une résolution qui accepte LES DEUX formes est correcte.
        if (str_contains($ligne, 'is_dir')) continue;
        $coupables[] = str_replace($RACINE . '/', '', $p) . ':' . ($n + 1);
    }
}
ok('aucun chemin en dur vers php-api', $coupables === [], $coupables);

// ---------------------------------------------------------------------------
//  2. On rebâtit l'arborescence du serveur
// ---------------------------------------------------------------------------
$SITE = sys_get_temp_dir() . '/wsm-layout-' . getmypid();
function rmrf(string $d): void {
    if (!is_dir($d)) return;
    foreach (scandir($d) as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = "$d/$e";
        is_dir($p) && !is_link($p) ? rmrf($p) : @unlink($p);
    }
    @rmdir($d);
}
register_shutdown_function(function () use ($SITE) { rmrf($SITE); });

@mkdir($SITE . '/backoffice', 0777, true);
// Exactement le geste du déploiement — sauf `data`, qu'on garde : sur le
// serveur la base est MySQL, ici c'est ce dossier qui EN TIENT LIEU. Ce qu'on
// éprouve, ce sont les CHEMINS, pas le moteur de base.
exec('cp -a ' . escapeshellarg($API) . ' ' . escapeshellarg($SITE . '/backoffice/api'));
exec('cp -a ' . escapeshellarg($RACINE . '/shop') . ' ' . escapeshellarg($SITE . '/shop'));
rmrf($SITE . '/backoffice/api/tests');

ok('l\'arborescence déployée est montée',
   is_dir($SITE . '/backoffice/api') && is_file($SITE . '/shop/index.php'));
ok('et le dossier php-api n\'y existe PAS — comme sur le serveur',
   !is_dir($SITE . '/backoffice/php-api'));

// ---------------------------------------------------------------------------
//  3. On sert cette arborescence-là
// ---------------------------------------------------------------------------
$log = $SITE . '/serveur.log';
$cmd = 'php -S localhost:' . $PORT . ' -t ' . escapeshellarg($SITE . '/shop')
     . ' ' . escapeshellarg($SITE . '/shop/router.php') . ' > ' . escapeshellarg($log) . ' 2>&1 & echo $!';
$pid = (int) trim((string) shell_exec($cmd));
register_shutdown_function(function () use ($pid) { if ($pid > 0) @exec("kill $pid 2>/dev/null"); });

function get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                            CURLOPT_FOLLOWLOCATION => true]);
    $b = curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, (string) $b];
}

$base = "http://localhost:$PORT";
$vivant = false;
for ($i = 0; $i < 40; $i++) {
    [$c] = get($base . '/');
    if ($c > 0) { $vivant = true; break; }
    usleep(150000);
}
if (!$vivant) {
    echo "  ✗ le serveur de test ne démarre pas\n";
    echo file_exists($log) ? file_get_contents($log) : '';
    exit(1);
}

// ---------------------------------------------------------------------------
//  4. Pas un seul 500 sur les pages publiques
// ---------------------------------------------------------------------------
//  Un 500 arrive AVEC UN CORPS VIDE quand display_errors est coupé — c'est
//  le cas sur le serveur. Le code HTTP est donc le seul signal disponible :
//  on le regarde pour chaque page, sans exception.
echo "\n-- aucune page publique ne rend 500 --\n";
$pages = [
    '/'                     => 'accueil',
    '/?lang=en'             => 'accueil en anglais',
    '/?lang=uk'             => 'accueil en ukrainien',
    '/koszyk'               => 'panier',
    '/kasa'                 => 'caisse',
    '/kontakt'              => 'formulaire de contact',
    '/zamowienie/MS-TEST'   => 'suivi de commande',
    '/robots.txt'           => 'robots.txt',
    '/sitemap.xml'          => 'sitemap',
];
$corps = [];
foreach ($pages as $p => $nom) {
    [$c, $h] = get($base . $p);
    $corps[$p] = $h;
    ok("$nom ne rend pas 500", $c < 500, $c);
}

// La page produit : on prend la première du catalogue, telle que la boutique
// la nomme elle-même. Un lien fabriqué à la main testerait notre supposition.
if (preg_match('~href="[^"]*/p/([^"/?#]+)~', $corps['/'] ?? '', $m)) {
    [$c, $fiche] = get($base . '/p/' . $m[1]);
    ok('la fiche produit ne rend pas 500', $c < 500, $c);
    ok('et elle porte bien un prix', (bool) preg_match('~\d+[,.]\d\d~', $fiche));
} else {
    ok('la fiche produit est atteignable depuis l\'accueil', false, 'aucun lien /p/ sur la page');
}

// ---------------------------------------------------------------------------
//  5. Le contact ne doit pas seulement répondre : il doit être ARMÉ
// ---------------------------------------------------------------------------
//  Une page 200 mais sans ses filtres anti-robot est une page qui collecte du
//  spam. C'est cette différence-là que le déploiement signalait, sous le 500.
echo "\n-- le formulaire de contact est complet et armé --\n";
$k = $corps['/kontakt'] ?? '';
ok('la page rend bien 200 avec du contenu', strlen($k) > 500, strlen($k));
ok('le piège à robots est posé', str_contains($k, 'firma_www'));
ok('l\'horodatage signé est posé', str_contains($k, 'name="_ts"'));
ok('la signature de l\'horodatage est posée', str_contains($k, 'name="_t"'));
ok('le champ message existe', str_contains($k, 'name="message"'));
ok('les libellés sont traduits — pas de champ sans nom',
   !preg_match('~<label[^>]*>\s*</label>~', $k));

// ---------------------------------------------------------------------------
//  6. Rien n'a été écrit dans le journal du serveur
// ---------------------------------------------------------------------------
//  Un « PHP Fatal error » ou un « Warning: require » dans le journal signale
//  un chemin qui ne tient pas, même quand la page a fini par s'afficher.
$journal = file_exists($log) ? (string) file_get_contents($log) : '';
$graves = [];
foreach (explode("\n", $journal) as $l) {
    if (preg_match('/(Fatal error|Parse error|Warning: (require|include)|Failed opening)/', $l)) $graves[] = trim($l);
}
echo "\n-- le journal du serveur est propre --\n";
ok('aucune erreur fatale ni require manquant', $graves === [], array_slice($graves, 0, 3));

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
