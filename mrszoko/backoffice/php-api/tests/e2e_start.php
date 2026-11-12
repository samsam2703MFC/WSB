<?php
// ============================================================================
//  e2e_start.php — L'ASSISTANT DE DÉMARRAGE (start.php, écran Start sklepu).
//
//  Ce qu'il doit tenir :
//    1. chaque étape dit pourquoi, quoi faire, et OÙ — et chaque « où » mène
//       quelque part : un écran qui existe, une page de la boutique, un site
//       en https ;
//    2. ce que les données prouvent se détecte seul, et NE SE COCHE PAS à la
//       main — une case cochée par erreur éteindrait la dernière alerte ;
//    3. ce que rien ne prouve se coche, se décoche, et dit qui et quand ;
//    4. l'avancement compte les étapes nécessaires, pas les optionnelles ;
//    5. le ménage d'ouverture n'efface pas la preuve que les tests ont eu lieu.
//
//  Sur une base SQLite TEMPORAIRE : rien n'est écrit dans la base de travail.
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

$base = sys_get_temp_dir() . '/wsm-start-' . getmypid() . '.sqlite';
@unlink($base);
putenv('WSM_SQLITE_PATH=' . $base);
putenv('WSM_DB_ENGINE=sqlite');
register_shutdown_function(fn() => @unlink($base));

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/delivery.php';
require_once dirname(__DIR__) . '/auth.php';      // le jeu de départ crée des comptes (WSM_ROLE_ADMIN)
require_once dirname(__DIR__) . '/start.php';
$pdo = wsm_bootstrap();
$bo = dirname(__DIR__, 2);

echo "webshop_mrszoko — end-to-end start sklepu\n\n";

// ---- 1. Les étapes et leurs « où » ---------------------------------------------------
echo "-- kroki: po co, co zrobić, gdzie --\n";
$etapy = wsm_start_etapy();
ok('quatorze étapes, dans l\'ordre de l\'ouverture', count($etapy) === 14 && array_key_first($etapy) === 'tozsamosc' && array_key_last($etapy) === 'otwarcie', array_keys($etapy));
$mal = [];
foreach ($etapy as $k => $e) {
    if (trim((string) $e['tytul']) === '' || trim((string) $e['po_co']) === '' || !$e['zrobic'] || !$e['gdzie']) $mal[] = "$k : incomplète";
    foreach ($e['gdzie'] as [$l, $href]) {
        if (preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,}/#', $href)) continue;
        if (preg_match('#^\.\./shop/[a-z/]*$#', $href)) continue;
        if (preg_match('/^[a-z_]+\.php$/', $href) && is_file("$bo/$href")) continue;
        $mal[] = "$k : $l → $href";
    }
}
ok('chaque étape est complète, et chaque lien mène à un écran existant, une page de la boutique ou un site https', !$mal, $mal);
ok('Facebook : on dit OÙ créer la page, pas seulement où la brancher',
   in_array('https://www.facebook.com/pages/create', array_column($etapy['meta']['gdzie'], 1), true));
ok('Google : Search Console et Merchant Center, liens directs',
   count(array_filter(array_column($etapy['google']['gdzie'], 1), fn($h) => str_starts_with($h, 'https://'))) === 2);

// ---- 2. Base neuve : rien n'est prêt, et l'écran le dit ------------------------------
echo "\n-- pusty sklep --\n";
$etat = wsm_start_etat($pdo);
$av = wsm_start_avancement($pdo, $etat);
ok('treize étapes nécessaires (la newsletter est optionnelle)', $av['razem'] === 13, $av);
ok('les étapes prouvables ne sont pas faites sur une boutique neuve',
   !$etat['poczta']['fait'] && !$etat['platnosci']['fait'] && !$etat['meta']['fait'] && !$etat['zerowanie']['fait'] && !$etat['otwarcie']['fait'], $etat);
ok('la prochaine étape est la première non faite', $av['nastepny'] === 'tozsamosc', $av);
ok('chaque étape non faite dit ce qui manque', !array_filter($etat, fn($s) => !$s['fait'] && trim($s['opis']) === ''));

// ---- 3. Ce que les données prouvent ---------------------------------------------------
echo "\n-- wykrywanie z danych --\n";
wsm_config_overlay(['mail' => ['transport' => 'mail', 'from' => 'sklep@example.com']]);
ok('poczta : un expéditeur valide suffit à la prouver', wsm_start_etat($pdo)['poczta']['fait']);
wsm_config_overlay(['invoice' => ['seller_name' => 'ATELIER sp. z o.o.', 'seller_nip' => '8971234567',
                                  'seller_address' => 'ul. Leśna 1, Warszawa', 'iban' => 'xxxx']]);
$s = wsm_start_etat($pdo)['firma'];
ok('firma : « xxxx » n\'est pas un compte bancaire', !$s['fait'] && str_contains($s['opis'], 'rachunek'), $s);
wsm_config_overlay(['invoice' => ['iban' => 'PL61109010140000071219812874']]);
ok('firma : tout est là, l\'étape se valide seule', wsm_start_etat($pdo)['firma']['fait']);

wsm_config_overlay(['stripe' => ['secret_key' => 'sk_test_abc', 'publishable_key' => 'pk_test_abc', 'account' => 'acct_1Test', 'webhook_secret' => 'whsec_x']]);
$e = wsm_start_etat($pdo);
ok('Stripe en mode test : les paiements sont prêts…', $e['platnosci']['fait'], $e['platnosci']);
ok('… mais la boutique n\'est PAS ouverte', !$e['otwarcie']['fait'] && str_contains($e['otwarcie']['opis'], 'testow'), $e['otwarcie']);
wsm_config_overlay(['stripe' => ['secret_key' => 'sk_live_abc', 'publishable_key' => 'pk_live_abc']]);
ok('clés de production : l\'ouverture est prouvée', wsm_start_etat($pdo)['otwarcie']['fait']);

wsm_config_overlay(['meta' => ['page_id' => '1234567890', 'ig_user_id' => 'xxxx']]);
$s = wsm_start_etat($pdo)['meta'];
ok('Meta : la page sans Instagram ne suffit pas', !$s['fait'] && str_contains($s['opis'], 'IG'), $s);
wsm_config_overlay(['meta' => ['ig_user_id' => '17841400000000000']]);
ok('Meta : page et Instagram branchés', wsm_start_etat($pdo)['meta']['fait']);

$visibles = (int) $pdo->query("SELECT COUNT(*) FROM wsm_products WHERE shop_visible = 1")->fetchColumn();
if ($visibles > 0) {
    ok('catalogue : des produits sans photo bloquent l\'étape', !wsm_start_etat($pdo)['katalog']['fait']);
    $pdo->exec("UPDATE wsm_products SET image_url = 'media/aaaaaaaaaaaaaaaaaaaaaaaa.webp' WHERE shop_visible = 1");
    ok('catalogue : toutes les photos là, l\'étape se valide', wsm_start_etat($pdo)['katalog']['fait']);
} else {
    ok('catalogue : sans produit en vente, l\'étape n\'est pas faite', !wsm_start_etat($pdo)['katalog']['fait']);
}

// ---- 4. Les coches manuelles ----------------------------------------------------------
echo "\n-- zaznaczanie ręczne --\n";
ok('une étape PROUVABLE refuse la coche manuelle', !wsm_start_cocher($pdo, 'platnosci', true, 'test') && !wsm_start_cocher($pdo, 'poczta', true, 'test'));
ok('une étape inconnue aussi', !wsm_start_cocher($pdo, 'nieistniejacy', true, 'test'));
$avant = wsm_start_avancement($pdo)['fait'];
ok('les textes juridiques se cochent à la main', wsm_start_cocher($pdo, 'prawne', true, 'Ola'));
$s = wsm_start_etat($pdo)['prawne'];
ok('… et l\'écran dit qui et quand', $s['fait'] && $s['mode'] === 'reczne' && str_contains($s['opis'], 'Ola'), $s);
ok('… et l\'avancement monte d\'un', wsm_start_avancement($pdo)['fait'] === $avant + 1);
ok('la coche est tracée au journal', (int) $pdo->query("SELECT COUNT(*) FROM wsm_audit WHERE verb = 'Start: zrobione' AND entity = 'prawne'")->fetchColumn() === 1);
wsm_start_cocher($pdo, 'prawne', false, 'Ola');
ok('… et se décoche', !wsm_start_etat($pdo)['prawne']['fait'] && wsm_start_avancement($pdo)['fait'] === $avant);

// ---- 5. Le test, puis le ménage --------------------------------------------------------
echo "\n-- test i zerowanie --\n";
ok('sans commande payée, le test n\'est pas fait', !wsm_start_etat($pdo)['test']['fait']);
wsm_audit($pdo, 'test', 'Zerowanie przed startem', 'orders=3', 'Platforma');
$e = wsm_start_etat($pdo);
ok('après le ménage d\'ouverture, le test reste fait — les commandes effacées en étaient la preuve',
   $e['zerowanie']['fait'] && $e['test']['fait'], [$e['zerowanie'], $e['test']]);

// ---- 6. L'optionnel ne bloque pas -----------------------------------------------------
echo "\n-- opcjonalne --\n";
foreach (array_keys($etapy) as $k) if (empty($etapy[$k]['opcja']) && $etapy[$k]['wykryj'] === null) wsm_start_cocher($pdo, $k, true, 'test');
wsm_config_overlay(['google' => ['site_verification' => 'abc123', 'merchant_id' => '1234567'],
                    'inpost' => ['token' => 'tok', 'organization_id' => '123']]);
$av = wsm_start_avancement($pdo);
$reste = array_keys(array_filter(wsm_start_etat($pdo), fn($s) => !$s['fait']));
ok('il ne reste que ce que la base de test ne peut pas prouver (KSeF, livraison)…',
   !array_diff($reste, ['faktury', 'dostawa', 'newsletter', 'katalog']), $reste);
ok('… et la newsletter, optionnelle, n\'est jamais « la prochaine étape »', $av['nastepny'] !== 'newsletter', $av);

// ---- 7. L'écran ------------------------------------------------------------------------
echo "\n-- ekran --\n";
$console = (string) file_get_contents("$bo/console.php");
ok('Start sklepu est dans le rail, juste après le Pulpit', str_contains($console, "'' => ['pulpit.php' => 'Pulpit', 'start.php' => 'Start sklepu']"));
ok('un lien vers « start.php#étape » est reconnu comme l\'écran start.php', str_contains($console, "preg_split('/[?#]/', \$href)[0]"));
$ecran = (string) file_get_contents("$bo/start.php");
ok('l\'écran ne propose la coche qu\'aux étapes manuelles, et à l\'administrateur', str_contains($ecran, "\$s['reczne'] && \$isAdmin"));
ok('les liens extérieurs s\'ouvrent à part, sans transmettre l\'origine', str_contains($ecran, 'rel="noopener noreferrer"'));
ok('le Pulpit résume l\'avancement', str_contains((string) file_get_contents("$bo/pulpit.php"), 'wsm_start_avancement($pdo)'));

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
