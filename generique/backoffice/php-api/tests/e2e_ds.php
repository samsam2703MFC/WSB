<?php
// ============================================================================
//  e2e_ds.php — UN CODE, PLUSIEURS DESIGN SYSTEMS.
//
//  Toutes les boutiques (Szoko Shop, MyCobotBuddy…) partagent le même code ;
//  chacune porte son design system (webshops/<slug>/design-system/), désigné par la
//  configuration (design_system). Ce test prouve :
//    1. le contrat : chaque design system le remplit, le code n'en sort pas ;
//    2. la compilation : chaque design system donne ses fichiers servis ;
//    3. le choix par la configuration, et le repli sur szoko ;
//    4. Wygląd : ses défauts sont ceux de la marque de la boutique ;
//    5. les teintes produit enregistrées sous les anciens noms s'affichent
//       partout ;
//    6. la boutique et la console lisent le design system, pas un fichier
//       en dur.
//
//  Hors ligne, sans serveur. Les variantes de configuration tournent dans un
//  processus à part (WSM_DESIGN_SYSTEM) : ds.php garde son choix en mémoire,
//  comme une requête en production.
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

$api    = dirname(__DIR__);
$racine = dirname(__DIR__, 4);
$lire   = fn(string $rel) => (string) @file_get_contents($racine . '/' . $rel);
$php    = escapeshellarg(PHP_BINARY);
$build  = escapeshellarg("$racine/tools/build-ds.php");

/** Exécute du PHP avec un design system choisi par l'environnement ; renvoie le JSON décodé. */
$avec = function (string $ds, string $code) use ($api, $php) {
    $pre = 'require ' . var_export("$api/db.php", true) . '; require_once ' . var_export("$api/theme.php", true) . ';';
    $cmd = 'WSM_DESIGN_SYSTEM=' . escapeshellarg($ds) . " $php -r " . escapeshellarg($pre . $code) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $j = json_decode($out, true);
    return $j ?? $out;
};

echo "webshop_mrszoko — end-to-end design systems\n\n";

// ---- 1. Le contrat ------------------------------------------------------------------------
echo "-- kontrakt: każdy design system go spełnia, kod z niego nie wychodzi --\n";
exec("$php $build --check 2>&1", $sortie, $rc);
ok('tools/build-ds.php --check : contrat respecté par les design systems ET par le code', $rc === 0, implode("\n", $sortie));
$contrat = json_decode($lire('generique/design-contract.json'), true);
$jetons = [];
foreach ($contrat['groupes'] ?? [] as $liste) $jetons += $liste;
ok('le contrat est lisible et non vide', count($jetons) > 100, count($jetons));
$systemes = array_map(fn($f) => basename(dirname($f, 2)), glob("$racine/webshops/*/design-system/ds.json"));
ok('au moins Szoko et MyCobotBuddy', in_array('szoko', $systemes, true) && in_array('mycobot', $systemes, true), $systemes);

// ---- 2. La compilation --------------------------------------------------------------------
echo "\n-- kompilacja: każdy design system daje swoje pliki --\n";
$tmp = sys_get_temp_dir() . '/wsm-ds-e2e-' . getmypid();
register_shutdown_function(fn() => shell_exec('rm -rf ' . escapeshellarg($tmp)));
exec("$php $build " . escapeshellarg($tmp) . ' 2>&1', $sortie, $rc);
ok('compilation réussie', $rc === 0);
foreach ($systemes as $s) {
    $man = json_decode((string) @file_get_contents("$tmp/$s/ds.json"), true) ?: [];
    $tok = (string) @file_get_contents("$tmp/$s/tokens.css");
    $manque = [];
    foreach (array_keys($jetons) as $n) if (!preg_match('/(?<![\w-])' . preg_quote($n, '/') . '\s*:/', $tok)) $manque[] = $n;
    ok("$s : tokens.css pose CHAQUE jeton du contrat (le sien ou le défaut)", $tok !== '' && !$manque, $manque);
    ok("$s : colors.css, fonts.css, theme.css, logo et manifeste livrés",
       is_file("$tmp/$s/colors.css") && is_file("$tmp/$s/fonts.css") && is_file("$tmp/$s/theme.css")
       && isset($man['logo']) && is_file("$tmp/$s/{$man['logo']}") && ($man['slug'] ?? '') === $s);
    ok("$s : les fichiers de police déclarés sont tous livrés", (function () use ($tmp, $s, $tok) {
        preg_match_all('~url\((fonts/[^)]+)\)~', $tok, $m);
        foreach ($m[1] as $f) if (!is_file("$tmp/$s/$f")) return false;
        return count($m[1]) > 0;
    })());
    // Hors commentaires : la feuille de Szoko EXPLIQUE pourquoi on ne passe
    // plus par fonts.googleapis.com — c'est de la prose, pas une requête.
    ok("$s : aucune police demandée à Google", !preg_match('~(@import|url\()\s*["\']?https?://fonts\.(googleapis|gstatic)\.com~', (string) preg_replace('~/\*.*?\*/~s', '', $tok)));
    $col = (string) @file_get_contents("$tmp/$s/colors.css");
    ok("$s : colors.css ne porte que des couleurs (la console n'en veut pas plus)", !str_contains($col, '@font-face') && !str_contains($col, '--space-'));
}
ok('Szoko : le chocolat du logo est --brand-700', str_contains((string) @file_get_contents("$tmp/szoko/tokens.css"), '--brand-700: #41281A'));
ok('MyCobotBuddy : la marine du logo, le bouton émeraude au texte emerald-900',
   (bool) preg_match('/--navy-800:\s*#0A1C33/', (string) @file_get_contents("$tmp/mycobot/tokens.css"))
   && str_contains((string) @file_get_contents("$tmp/mycobot/tokens.css"), '--on-action:      var(--emerald-900)'));
ok('MyCobotBuddy : ses retouches de composants sont livrées', str_contains((string) @file_get_contents("$tmp/mycobot/theme.css"), '.btn {'));

// ---- 3. Le choix par la configuration ----------------------------------------------------
echo "\n-- wybór przez konfigurację, z powrotem na szoko --\n";
$r = $avec('mycobot', 'echo json_encode([wsm_ds_slug(), wsm_ds()["nom"], wsm_ds_path("tokens.css"), wsm_ds_logo_path(), wsm_ds_theme_css() !== ""]);');
ok('design_system = mycobot : la boutique porte MyCobotBuddy', $r === ['mycobot', 'MyCobotBuddy', 'ds/mycobot/tokens.css', 'ds/mycobot/logo.svg', true], $r);
$r = $avec('szoko', 'echo json_encode([wsm_ds_slug(), wsm_ds_logo_path(), wsm_ds_theme_css()]);');
ok('design_system = szoko : le logo d\'origine, aucune retouche', $r === ['szoko', 'ds/szoko/logo.png', ''], $r);
$r = $avec('nieistniejacy', 'echo json_encode(wsm_ds_slug());');
ok('un design system inconnu retombe sur szoko — jamais une page blanche', $r === 'szoko', $r);
$r = $avec('../../etc', 'echo json_encode(wsm_ds_slug());');
ok('un chemin glissé dans la configuration est refusé', $r === 'szoko', $r);

// ---- 4. Wygląd suit la marque ------------------------------------------------------------
echo "\n-- Wygląd: wartości domyślne z design systemu sklepu --\n";
$code = '$d = wsm_theme_defaults(); echo json_encode([$d["brand"], $d["accent"], $d["bg"], $d["font_body"], $d["font_head"],'
      . ' array_keys(wsm_theme_fonts()), wsm_theme_css($d)]);';
$r = $avec('mycobot', $code);
ok('MyCobotBuddy : marine, émeraude, fond neutre, Inter + Archivo',
   is_array($r) && array_slice($r, 0, 5) === ['#0A1C33', '#02C9A0', '#F6F9F8', 'inter', 'archivo'], $r);
ok('… seules ses polices sont proposées (celles qu\'il héberge)', is_array($r) && $r[5] === ['inter', 'archivo'], $r[5] ?? $r);
ok('… et au défaut, la feuille Wygląd est VIDE : le design system fait foi', is_array($r) && $r[6] === '', $r[6] ?? $r);
$r = $avec('szoko', $code);
ok('Szoko : les défauts d\'aujourd\'hui, inchangés', is_array($r) && array_slice($r, 0, 5) === ['#41281A', '#C68A3C', '#FFFFFF', 'mulish', 'same'] && $r[6] === '', $r);
$r = $avec('mycobot', 'echo json_encode(wsm_theme_css(["font_body" => "inter", "font_head" => "same", "brand" => "#0A1C33", "accent" => "#02C9A0", "bg" => "#F6F9F8", "radius" => "lagodne", "hero_align" => "srodek"]));');
ok('MyCobotBuddy : « titres comme le texte » remplace Archivo par Inter, et rien d\'autre',
   $r === ":root{--font-display:'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;}", $r);

$code = 'echo json_encode([wsm_theme_valide("accent", "#0A3D2E"), wsm_theme_valide("accent", "#7DEACB"), wsm_theme_fields()["accent"][4]]);';
$r = $avec('mycobot', $code);
ok('MyCobotBuddy : l\'accent porte le texte foncé des boutons — trop sombre refusé, clair accepté',
   is_array($r) && $r[0] === null && $r[1] === '#7DEACB' && str_contains((string) $r[2], 'ciemny napis'), $r);
$r = $avec('szoko', $code);
ok('Szoko : l\'accent porte du blanc — sombre accepté, pastel refusé, comme avant',
   is_array($r) && $r[0] === '#0A3D2E' && $r[1] === null && str_contains((string) $r[2], 'kolor marki'), $r);

// ---- 5. Les anciennes teintes produit ----------------------------------------------------
echo "\n-- stare nazwy odcieni produktów --\n";
$r = $avec('szoko', 'echo json_encode(array_map("wsm_ds_teinte", ["--choco-500", "--cream-200", "--caramel-500", "--berry-600", "--berry-500", "--gold-500", "--brand-700", "--accent"]));');
ok('chocolat, crème, caramel, framboise et or → les noms du contrat ; le reste intact',
   $r === ['--brand-500', '--paper-200', '--accent-500', '--sale-strong', '--sale', '--highlight', '--brand-700', '--accent'], $r);

// ---- 6. Qui lit le design system ---------------------------------------------------------
echo "\n-- sklep i konsola czytają design system --\n";
$layout = $lire('generique/shop/layout.php');
ok('la boutique charge ds/<slug>/tokens.css, plus un tokens.css en dur', str_contains($layout, "asset(wsm_ds_path('tokens.css'))") && !str_contains($layout, "asset('tokens.css')"));
ok('… et imprime les retouches de la marque après shop.css', strpos($layout, 'wsm_ds_theme_css()') > strpos($layout, "asset('shop.css')"));
ok('plus de tokens.css ni de polices recopiées dans la boutique du dépôt', !is_file("$racine/generique/shop/tokens.css") && !is_dir("$racine/generique/shop/fonts"));
$console = $lire('generique/backoffice/console.php');
ok('la console pose les couleurs de la boutique APRÈS brand.css, avant console.css',
   ($a = strpos($console, 'console_ds_colors_href()')) !== false && $a > strpos($console, 'brand.css') && $a < strpos($console, "console_asset('console.css')"));
ok('la console ne garde aucune couleur de marque en dur dans ses jetons',
   !preg_match('/--(bg-page-alt|surface-raised|border-default|text-body|text-muted|bo-side|bo-fill)\s*:\s*#/', $lire('generique/backoffice/console.css')));

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
