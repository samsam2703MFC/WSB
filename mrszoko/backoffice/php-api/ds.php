<?php
declare(strict_types=1);
/**
 * ds.php — LE DESIGN SYSTEM DE CETTE BOUTIQUE.
 *
 * Toutes les boutiques partagent le même code ; chacune porte son design
 * system (design-systems/<slug>/), désigné par la configuration :
 *
 *     'design_system' => 'mycobot'      (config.local.php, ou WSM_DESIGN_SYSTEM)
 *
 * Ce choix appartient au SERVEUR, pas à la console — comme le taux de
 * commission : le propriétaire d'une boutique règle ses couleurs dans
 * Wygląd, il ne change pas de marque d'un clic.
 *
 * OÙ SONT LES FICHIERS. tools/build-ds.php compile chaque design system dans
 * shop/ds/<slug>/ (tokens.css, colors.css, theme.css, fonts/, logo, ds.json).
 * C'est ce que le visiteur télécharge. En développement, si la compilation
 * n'a pas tourné, on lit le manifeste à la source (design-systems/<slug>/)
 * pour que Wygląd et les tests gardent leurs défauts — la page, elle,
 * n'aura pas de styles : `php tools/build-ds.php mrszoko/shop/ds`.
 *
 * UN SLUG INCONNU RETOMBE SUR « szoko », il ne casse pas la boutique : une
 * faute de frappe dans la configuration ne doit pas servir une page blanche.
 */

const WSM_DS_DEFAUT = 'szoko';

/** Les anciens noms de teinte (données produit) → les noms du contrat. */
const WSM_DS_ANCIENS = ['--choco-' => '--brand-', '--cream-' => '--paper-', '--caramel-' => '--accent-'];

/** Les anciens noms qui ne suivent pas une échelle : traduits mot pour mot. */
const WSM_DS_ANCIENS_EXACTS = ['--berry-500' => '--sale', '--berry-600' => '--sale-strong', '--gold-500' => '--highlight'];

function wsm_ds_compile_dir(string $slug): string {
    return dirname(__DIR__, 2) . '/shop/ds/' . $slug;
}

function wsm_ds_source_dir(string $slug): string {
    return dirname(__DIR__, 3) . '/design-systems/' . $slug;
}

/** Le slug du design system en vigueur. */
function wsm_ds_slug(): string {
    static $slug = null;
    if ($slug !== null) return $slug;
    $s = strtolower(trim((string) (wsm_config()['design_system'] ?? '')));
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $s)
        || (!is_file(wsm_ds_compile_dir($s) . '/ds.json') && !is_file(wsm_ds_source_dir($s) . '/ds.json'))) {
        $s = WSM_DS_DEFAUT;
    }
    return $slug = $s;
}

/** Le dossier compilé est-il là ? (sinon : manifeste lu à la source) */
function wsm_ds_compile(): bool {
    return is_file(wsm_ds_compile_dir(wsm_ds_slug()) . '/ds.json');
}

/**
 * Le manifeste : nom, logo, polices proposées dans Wygląd, défauts de Wygląd.
 * Toujours un tableau complet — un manifeste abîmé ne doit pas faire tomber
 * l'écran Wygląd, il retombe sur les valeurs historiques de la boutique.
 */
function wsm_ds(): array {
    static $ds = null;
    if ($ds !== null) return $ds;
    $slug = wsm_ds_slug();
    $f = wsm_ds_compile() ? wsm_ds_compile_dir($slug) . '/ds.json' : wsm_ds_source_dir($slug) . '/ds.json';
    $m = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    $m = is_array($m) ? $m : [];
    $repli = [
        'nom'     => 'Szoko Shop',
        'logo'    => 'logo.png',
        'polices' => ['mulish' => ['Mulish', "'Mulish', system-ui, sans-serif", 'Mulish']],
        'wyglad'  => ['font_body' => 'mulish', 'font_head' => 'same', 'font_head_baza' => 'mulish',
                      'brand' => '#41281A', 'accent' => '#C68A3C', 'bg' => '#FFFFFF'],
    ];
    $m += $repli;
    $m['wyglad'] = (array) $m['wyglad'] + $repli['wyglad'];
    if (!is_array($m['polices']) || !$m['polices']) $m['polices'] = $repli['polices'];
    // Le logo compilé s'appelle logo.<ext> ; à la source il garde son chemin.
    if (!wsm_ds_compile()) $m['logo'] = 'logo.' . strtolower(pathinfo((string) $m['logo'], PATHINFO_EXTENSION));
    $m['slug'] = $slug;
    return $ds = $m;
}

/** Chemin d'un fichier compilé, relatif à la racine de la boutique : « ds/<slug>/tokens.css ». */
function wsm_ds_path(string $file): string {
    return 'ds/' . wsm_ds_slug() . '/' . ltrim($file, '/');
}

/** Le logo par défaut de la boutique, relatif à sa racine. */
function wsm_ds_logo_path(): string {
    return wsm_ds_path((string) wsm_ds()['logo']);
}

/** Les retouches de composants de la marque, à imprimer APRÈS shop.css. */
function wsm_ds_theme_css(): string {
    $slug = wsm_ds_slug();
    $f = wsm_ds_compile() ? wsm_ds_compile_dir($slug) . '/theme.css' : wsm_ds_source_dir($slug) . '/theme.css';
    return is_file($f) ? trim((string) file_get_contents($f)) : '';
}

/**
 * Une teinte produit enregistrée en base (swatch_from / swatch_to) : les
 * anciennes portent les noms du chocolat (--choco-500). On les traduit au
 * vol vers le contrat, pour qu'elles s'affichent aussi sur une boutique dont
 * le design system n'a jamais connu le chocolat.
 */
function wsm_ds_teinte(string $jeton): string {
    if (isset(WSM_DS_ANCIENS_EXACTS[$jeton])) return WSM_DS_ANCIENS_EXACTS[$jeton];
    foreach (WSM_DS_ANCIENS as $avant => $apres) {
        if (str_starts_with($jeton, $avant)) return $apres . substr($jeton, strlen($avant));
    }
    return $jeton;
}
