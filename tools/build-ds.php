<?php
// ============================================================================
//  build-ds.php — compile et contrôle les design systems des boutiques.
//
//    php tools/build-ds.php --check        contrôle seul (CI, avant tout déploiement)
//    php tools/build-ds.php <dossier>      contrôle, puis compile chaque design
//                                          system dans <dossier>/<slug>/
//
//  UNE BOUTIQUE = UN DESIGN SYSTEM, UN SEUL CODE. Le code (boutique, console)
//  n'emploie que les jetons de design-systems/contract.json ; chaque design
//  system (design-systems/<slug>/) leur donne sa valeur. La boutique lit
//  ensuite celui que sa configuration désigne (design_system = '<slug>').
//
//  CE QUI EST REFUSÉ, ET POURQUOI C'EST BLOQUANT :
//   · un design system qui oublie un jeton OBLIGATOIRE du contrat. Le jeton
//     vaudrait « rien » : un bouton sans fond, un texte sans couleur — sur
//     cette boutique-là seulement, donc invisible depuis les autres.
//   · un var(--x) du code qui n'est ni dans le contrat, ni défini par le code
//     lui-même. Typiquement --choco-700 : il marche chez Szoko (alias) et rend
//     un trou chez MyCobotBuddy. Une fonction développée sur une boutique doit
//     s'afficher sur TOUTES — c'est ici qu'on le garantit, pas en production.
//   · un ancien nom de marque (--choco-*, --cream-*, --caramel-*…) dans le
//     code, même avec une valeur de secours.
//
//  CE QUE PRODUIT LA COMPILATION, par design system :
//   tokens.css   défauts du contrat (pour les jetons que le design system ne
//                pose pas) + ses fichiers de jetons, polices comprises. UN seul
//                fichier : pas de cascade d'@import avant le premier affichage.
//   colors.css   les seules couleurs — ce que la console, commune à toutes les
//                boutiques, emprunte au design system (avec le logo).
//   theme.css    retouches de composants propres à la marque (facultatif) :
//                la boutique les imprime APRÈS shop.css.
//   fonts.css    les seuls @font-face — les échantillons de Wygląd.
//   fonts/       les fichiers de police, servis depuis notre domaine.
//   logo.<ext>   le logo par défaut de la boutique.
//   ds.json      le manifeste (nom, polices proposées dans Wygląd, défauts).
// ============================================================================
declare(strict_types=1);

$RACINE  = dirname(__DIR__);
$DS_DIR  = $RACINE . '/design-systems';
$args    = array_slice($argv, 1);
$checkOnly = in_array('--check', $args, true);
$sortie  = null;
foreach ($args as $a) if ($a !== '--check') $sortie = $a;
if (!$checkOnly && $sortie === null) {
    fwrite(STDERR, "usage : php tools/build-ds.php --check | <dossier de sortie>\n");
    exit(2);
}

/** Les anciens noms propres à Szoko : interdits dans le code, quel que soit le contexte. */
const ANCIENS = '/^--(choco|cream|caramel|gold|berry)-\d+$/';

/** Les fichiers de code qui consomment des jetons. */
function fichiers_code(string $r): array {
    $f = array_merge(
        glob("$r/mrszoko/shop/*.css"), glob("$r/mrszoko/shop/*.php"), glob("$r/mrszoko/shop/*.js"),
        glob("$r/mrszoko/backoffice/*.php"), glob("$r/mrszoko/backoffice/*.css"),
        glob("$r/mrszoko/backoffice/_ds/mister-szoko/*.css"),
        glob("$r/mrszoko/backoffice/php-api/*.php"),
    );
    return array_values(array_filter($f, fn($x) => basename($x) !== 'tokens.css'));
}

/** Retire les commentaires en gardant leurs sauts de ligne : les numéros de ligne restent justes. */
function sans_commentaires(string $s): string {
    return (string) preg_replace_callback('~/\*.*?\*/~s', fn($m) => str_repeat("\n", substr_count($m[0], "\n")), $s);
}

/** Les noms déclarés (--x: …) dans un texte. */
function declares(string $s): array {
    preg_match_all('/(?<![\w-])(--[a-z0-9-]+)\s*:/i', sans_commentaires($s), $m);
    return array_fill_keys($m[1], true);
}

/** Les var(--x) d'un texte : [nom, a_un_secours, ligne]. */
function references(string $s): array {
    $s = sans_commentaires($s);
    preg_match_all('/var\(\s*(--[a-z0-9-]+)\s*(,)?/i', $s, $m, PREG_OFFSET_CAPTURE);
    $out = [];
    foreach ($m[1] as $i => [$nom, $pos]) {
        $out[] = [$nom, ($m[2][$i][0] ?? '') === ',', substr_count(substr($s, 0, $pos), "\n") + 1];
    }
    return $out;
}

// ---- Le contrat --------------------------------------------------------------
$contrat = json_decode((string) file_get_contents("$DS_DIR/contract.json"), true);
if (!is_array($contrat) || empty($contrat['groupes'])) {
    fwrite(STDERR, "design-systems/contract.json illisible\n");
    exit(1);
}
$jetons = [];   // nom → [groupe, defaut|null]
foreach ($contrat['groupes'] as $g => $liste) {
    foreach ($liste as $nom => $d) $jetons[$nom] = [$g, $d['defaut'] ?? null];
}

$erreurs = [];
$err = function (string $ou, string $quoi) use (&$erreurs) { $erreurs[] = "$ou : $quoi"; };

// ---- Les design systems ---------------------------------------------------------
$systemes = [];
foreach (glob("$DS_DIR/*/ds.json") as $man) {
    $dir  = dirname($man);
    $slug = basename($dir);
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) { $err("design-systems/$slug", 'nom de dossier invalide (minuscules, chiffres, tirets)'); continue; }
    $ds = json_decode((string) file_get_contents($man), true);
    if (!is_array($ds)) { $err("$slug/ds.json", 'JSON illisible'); continue; }

    foreach (['nom', 'logo', 'fichiers', 'polices', 'wyglad'] as $k) {
        if (empty($ds[$k])) $err("$slug/ds.json", "« $k » manquant");
    }
    if (!empty($ds['logo']) && !is_file("$dir/{$ds['logo']}")) $err("$slug/ds.json", "logo introuvable : {$ds['logo']}");
    $w = $ds['wyglad'] ?? [];
    foreach (['brand', 'accent', 'bg'] as $k) {
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($w[$k] ?? ''))) $err("$slug/ds.json", "wyglad.$k doit être une couleur #RRGGBB");
    }
    $pol = $ds['polices'] ?? [];
    foreach (['font_body', 'font_head_baza'] as $k) {
        if (!isset($pol[$w[$k] ?? ''])) $err("$slug/ds.json", "wyglad.$k doit nommer une des « polices »");
    }
    if (($w['font_head'] ?? '') !== 'same' && !isset($pol[$w['font_head'] ?? ''])) $err("$slug/ds.json", 'wyglad.font_head : « same » ou une des « polices »');
    if (!in_array($w['przyciski'] ?? 'marka', ['marka', 'akcent'], true)) $err("$slug/ds.json", 'wyglad.przyciski : « marka » (boutons couleur de marque) ou « akcent »');
    foreach (['tokens/colors.css' => 'les couleurs (la console les lit seules)', 'tokens/fonts.css' => 'les @font-face (Wygląd les lit seuls)'] as $f => $pourquoi) {
        if (!in_array($f, (array) ($ds['fichiers'] ?? []), true)) $err("$slug/ds.json", "$f est obligatoire : $pourquoi");
    }
    // Les couleurs des e-mails (facultatives) partent telles quelles dans un
    // attribut style : rien d'autre qu'un #RRGGBB n'a le droit d'y entrer.
    foreach ((array) ($ds['email'] ?? []) as $k => $v) {
        if (!in_array($k, ['tlo', 'karta', 'tekst', 'cichy', 'akcent', 'linia'], true)) $err("$slug/ds.json", "email.$k : clé inconnue");
        elseif (!preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $v)) $err("$slug/ds.json", "email.$k doit être une couleur #RRGGBB");
    }

    $css = []; $defs = []; $refs = [];
    foreach ((array) ($ds['fichiers'] ?? []) as $f) {
        if (!is_file("$dir/$f")) { $err($slug, "fichier absent : $f"); continue; }
        $txt = (string) file_get_contents("$dir/$f");
        $txt = (string) preg_replace('/^\s*@import[^;]*;\s*$/m', '', $txt);
        $css[$f] = $txt;
        $defs += declares($txt);
        foreach (references($txt) as $r) $refs[] = [$f, ...$r];
    }
    $theme = is_file("$dir/theme.css") ? (string) file_get_contents("$dir/theme.css") : '';
    if ($theme !== '') {
        $defs += declares($theme);
        foreach (references($theme) as $r) $refs[] = ['theme.css', ...$r];
    }

    foreach ($jetons as $nom => [$g, $defaut]) {
        if ($defaut === null && !isset($defs[$nom])) $err($slug, "jeton OBLIGATOIRE absent : $nom ($g)");
    }
    // Chaque fichier qu'une feuille réclame (police, image) doit être là : un
    // url() vers un fichier absent ne casse rien de visible au déploiement —
    // il donne une police de secours sur la boutique, des mois durant.
    foreach (array_merge($css, ['theme.css' => $theme]) as $f => $txt) {
        preg_match_all('~url\(\s*[\'"]?(?!data:|https?:|/)([^\'")]+)~i', sans_commentaires($txt), $m);
        foreach (array_unique($m[1]) as $u) {
            // Compilées, toutes les feuilles vivent à la racine de ds/<slug>/ :
            // un chemin relatif se lit donc depuis la racine du design system.
            if (!is_file("$dir/$u")) $err("$slug/$f", "url($u) : fichier absent (attendu : design-systems/$slug/$u)");
        }
    }
    foreach ($refs as [$f, $nom, $secours, $ligne]) {
        if (!$secours && !isset($defs[$nom]) && !isset($jetons[$nom])) $err("$slug/$f:$ligne", "var($nom) n'est défini nulle part");
    }
    $systemes[$slug] = compact('dir', 'ds', 'css', 'defs', 'theme');
}
if (!isset($systemes['szoko'])) $err('design-systems', 'le design system « szoko » (défaut de la configuration) est absent');

// ---- Le code ----------------------------------------------------------------
$code = fichiers_code($RACINE);
$defsCode = [];
foreach ($code as $f) $defsCode += declares((string) file_get_contents($f));
$nRefs = 0;
foreach ($code as $f) {
    $rel = substr($f, strlen($RACINE) + 1);
    foreach (references((string) file_get_contents($f)) as [$nom, $secours, $ligne]) {
        $nRefs++;
        if (preg_match(ANCIENS, $nom)) { $err("$rel:$ligne", "ancien nom de marque $nom — employer le nom du contrat"); continue; }
        if ($secours || isset($jetons[$nom]) || isset($defsCode[$nom])) continue;
        $err("$rel:$ligne", "var($nom) hors contrat : l'ajouter à design-systems/contract.json, ou le définir dans le code");
    }
}

if ($erreurs) {
    fwrite(STDERR, "DESIGN SYSTEMS REFUSÉS — " . count($erreurs) . " problème(s) :\n  " . implode("\n  ", $erreurs) . "\n");
    exit(1);
}
printf("design systems : %s — contrat de %d jetons respecté ; code : %d références, toutes au contrat\n",
    implode(', ', array_keys($systemes)), count($jetons), $nRefs);
if ($checkOnly) exit(0);

// ---- Compilation ---------------------------------------------------------------
$defauts = function (array $defs, ?string $groupe = null) use ($jetons): string {
    $l = [];
    foreach ($jetons as $nom => [$g, $d]) {
        if ($d === null || isset($defs[$nom])) continue;
        if ($groupe !== null && $g !== $groupe) continue;
        $l[] = "  $nom: $d;";
    }
    return $l ? "/* ---- défauts du contrat (jetons que ce design system ne pose pas) ---- */\n:root {\n" . implode("\n", $l) . "\n}\n" : '';
};
$copier = function (string $de, string $vers) use (&$copier): void {
    if (is_dir($de)) {
        @mkdir($vers, 0775, true);
        foreach (scandir($de) as $e) if ($e !== '.' && $e !== '..') $copier("$de/$e", "$vers/$e");
    } else {
        copy($de, $vers);
    }
};

foreach ($systemes as $slug => $s) {
    $out = rtrim($sortie, '/') . "/$slug";
    @mkdir($out, 0775, true);
    $tete = "/* Généré par tools/build-ds.php depuis design-systems/$slug — ne pas éditer. */\n";

    $tokens = $tete . $defauts($s['defs']);
    foreach ($s['css'] as $f => $txt) $tokens .= "\n/* ---- $f ---- */\n" . trim($txt) . "\n";
    file_put_contents("$out/tokens.css", $tokens);

    file_put_contents("$out/colors.css", $tete . $defauts($s['defs'], 'couleurs') . "\n" . trim($s['css']['tokens/colors.css']) . "\n");
    file_put_contents("$out/theme.css", $s['theme'] !== '' ? $tete . $s['theme'] : '');
    // Les seules polices : Wygląd (console) montre ses échantillons avec elles.
    file_put_contents("$out/fonts.css", $tete . trim($s['css']['tokens/fonts.css'] ?? '') . "\n");

    if (is_dir("$s[dir]/fonts")) $copier("$s[dir]/fonts", "$out/fonts");
    $ext = strtolower(pathinfo($s['ds']['logo'], PATHINFO_EXTENSION));
    foreach (glob("$out/logo.*") as $vieux) unlink($vieux);
    copy("$s[dir]/{$s['ds']['logo']}", "$out/logo.$ext");

    $man = $s['ds'];
    $man['slug'] = $slug;
    $man['logo'] = "logo.$ext";
    $man['contrat'] = (int) ($contrat['version'] ?? 1);
    unset($man['fichiers']);
    file_put_contents("$out/ds.json", json_encode($man, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    printf("  %-10s → %s (tokens.css %d o)\n", $slug, $out, strlen($tokens));
}
