<?php
// ============================================================================
//  cms.php — le contenu des deux sites publics, éditable depuis la console.
//
//  Il n'y a pas une ligne de texte en dur dans la vitrine ni dans la page
//  d'accueil : tout vient de deux tables (lang, k, v), servies telles quelles.
//  Ce fichier est donc simplement la face « écriture » de ce que les pages
//  lisent déjà — pas un moteur de plus.
//
//  Trois partis pris, qui expliquent tout le reste :
//
//   1. LE TEXTE EST DU TEXTE. Les deux vitrines échappent ce qu'elles
//      affichent (htmlspecialchars côté boutique, textContent côté accueil).
//      Écrire <b>gras</b> afficherait donc « <b>gras</b> ». L'écran le dit,
//      plutôt que de laisser quelqu'un découvrir ça en production.
//   2. LE POLONAIS EST LA LANGUE DE REPLI. Une clé non traduite n'ouvre pas un
//      trou dans la page : elle retombe sur le polonais. Effacer une
//      traduction est donc sans danger — et c'est même la bonne manière de
//      dire « pas encore traduit ».
//   3. LE FICHIER SOURCE RESTE LA RÉFÉRENCE. content_seed.json ne réécrit
//      jamais un texte corrigé à la main, mais il permet de revenir au texte
//      d'origine, clé par clé, quand une modification s'avère malheureuse.
//
//  Aucune notion de « page » ou de « bloc » n'est inventée ici : les sites
//  n'en ont pas. Inventer une hiérarchie que le rendu ignore donnerait un CMS
//  qui ment sur ce qu'il pilote.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Les deux sites publics et où vit leur contenu.
 * `url` sert au lien « podgląd » : on l'ouvre pour vérifier, en vrai.
 */
function wsm_cms_sites(): array {
    return [
        'sklep' => [
            'label' => 'Sklep',
            'table' => 'wsm_shop_i18n',
            'seed'  => __DIR__ . '/../../shop/content_seed.json',
            'url'   => '../shop/',
            'about' => 'Katalog, karta produktu, koszyk, zamówienie — wszystko, co klient czyta w sklepie.',
        ],
        'strona' => [
            'label' => 'Strona główna',
            'table' => 'wsm_landing_i18n',
            'seed'  => __DIR__ . '/../../landing/content_seed.json',
            'url'   => '../landing/',
            'about' => 'Witryna: nagłówek, gama, formaty, atelier, panel B2B, stopka.',
        ],
    ];
}

function wsm_cms_site(string $key): ?array {
    $s = wsm_cms_sites();
    return $s[$key] ?? null;
}

/**
 * Les sections, dans l'ordre où le visiteur les rencontre, avec un nom lisible.
 * Le préfixe de la clé (« checkout.city » → « checkout ») EST la section :
 * c'est la convention du contenu depuis le premier jour, autant s'en servir
 * plutôt que d'ajouter une colonne qui pourrait mentir.
 */
const WSM_CMS_SECTIONS = [
    // Boutique
    'meta' => 'Tytuł strony i opis (SEO)',
    'brand' => 'Nazwa marki',
    'nav' => 'Nawigacja',
    'a11y' => 'Etykiety dostępności (czytniki ekranu)',
    'home' => 'Strona sklepu — nagłówek',
    'promise' => 'Obietnice (trzy filary)',
    'catalog' => 'Katalog',
    'product' => 'Karta produktu',
    'price' => 'Ceny',
    'badge' => 'Plakietki',
    'cart' => 'Koszyk',
    'checkout' => 'Zamawianie',
    'order' => 'Potwierdzenie zamówienia',
    // Sans cette ligne, l'écran Treści afficherait un groupe intitulé « track »
    // à quelqu'un qui vient corriger une phrase — et c'est exactement ce que
    // le test attrape : une section sans nom lisible.
    'track' => 'Wyszukiwanie zamówienia',
    'reg' => 'Pobieranie danych z rejestru',
    // Les deux documents que tpay exige, et que la loi exige avant tpay.
    'legal' => 'Strony prawne — nagłówki',
    'terms' => 'Regulamin sklepu',
    'privacy' => 'Polityka prywatności',
    'status' => 'Statusy zamówienia',
    'pay' => 'Statusy płatności',
    'ship' => 'Sposoby dostawy',
    'info' => 'Pasek u góry strony głównej ({prog} = próg darmowej dostawy z Dostawy)',
    'returns' => 'Strona „Zwroty” i stopka ({email}, {dni}); sam adres: Integracje → Sklep',
    'metody' => 'Logotypy wysyłki i płatności — nagłówki',
    'metoda' => 'Logotypy wysyłki i płatności — podpisy',
    'nutri' => 'Wartości odżywcze — nazwy wierszy',
    'story' => 'O nas',
    'contact' => 'Formularz kontaktowy',
    'sub' => 'Subskrypcja na stronie potwierdzenia',
    'upsell' => 'Propozycje produktów',
    'claim' => 'Reklamacje i zwroty',
    'stop' => 'Wypisanie z wiadomości',
    'nl' => 'Newsletter i SMS: zapis, potwierdzenie, wypisanie, stopka wiadomości',
    'cookies' => 'Baner cookies: zgody na Google Analytics, Google Ads i Meta',
    'form' => 'Formularze (/f/…): zgoda, przycisk, podziękowanie',
    'seller' => 'Dane sprzedawcy w stopce',
    'footer' => 'Stopka',
    // Page d'accueil
    'lang' => 'Wybór języka',
    'locale' => 'Format liczb i dat',
    'currency' => 'Waluta',
    'hero' => 'Nagłówek strony',
    'strip' => 'Pasek pod nagłówkiem',
    'range' => 'Gama',
    'placeholder' => 'Teksty zastępcze',
    'fluidity' => 'Skala płynności',
    'formats' => 'Formaty',
    'format' => 'Formaty — pozycje',
    'atelier' => 'Atelier',
    'pro' => 'Panel B2B',
];

function wsm_cms_section_label(string $prefix): string {
    return WSM_CMS_SECTIONS[$prefix] ?? $prefix;
}

/** La langue de repli : ce qui s'affiche quand une traduction manque. */
const WSM_CMS_BASE_LANG = 'pl';

/** Les langues réellement présentes, polonais d'abord. */
function wsm_cms_langs(PDO $pdo, string $table): array {
    $rows = $pdo->query("SELECT DISTINCT lang FROM $table ORDER BY lang")->fetchAll() ?: [];
    $out = array_map(fn($r) => (string) $r['lang'], $rows);
    usort($out, fn($a, $b) => ($a === WSM_CMS_BASE_LANG ? -1 : ($b === WSM_CMS_BASE_LANG ? 1 : strcmp($a, $b))));
    return $out ?: [WSM_CMS_BASE_LANG];
}

/**
 * Verse dans la base les clés du fichier source QUI N'Y SONT PAS ENCORE.
 *
 * C'EST LA MÊME OMISSION QUI A DÉJÀ COÛTÉ CHER. Les modèles de mail n'étaient
 * semés que sur table vide : tout ce qui était livré après la mise en route
 * restait muet pour toujours. Les libellés de la boutique avaient exactement
 * le même défaut — une page ajoutée aujourd'hui s'afficherait en production
 * avec des champs sans étiquette, et personne ne verrait pourquoi, parce que
 * ça marche en développement où la base a été refaite.
 *
 * On ne touche JAMAIS à une valeur existante : quelqu'un a pu la corriger
 * depuis le CMS, et le fichier du dépôt n'a pas à écraser ce travail.
 *
 * @return int nombre de textes ajoutés
 */
function wsm_cms_topup(PDO $pdo, string $table, string $seedFile): int {
    if (!wsm_table_exists($pdo, $table)) return 0;
    $src = wsm_cms_source($seedFile);
    if (!$src) return 0;

    $present = [];
    try {
        foreach ($pdo->query("SELECT lang, k FROM $table")->fetchAll() ?: [] as $r) {
            $present[$r['lang'] . '|' . $r['k']] = true;
        }
    } catch (Throwable $e) { return 0; }

    $ins = $pdo->prepare("INSERT INTO $table (lang, k, v) VALUES (?,?,?)");
    $n = 0;
    foreach ($src as $k => $byLang) {
        foreach ($byLang as $lang => $v) {
            if (isset($present[$lang . '|' . $k])) continue;
            try { $ins->execute([$lang, $k, $v]); $n++; } catch (Throwable $e) { /* course : déjà posée */ }
        }
    }
    return $n;
}

/** Complète les deux sites publics d'un coup. Appelé au démarrage. */
function wsm_cms_topup_all(PDO $pdo): int {
    $n = 0;
    foreach (wsm_cms_sites() as $s) {
        $n += wsm_cms_topup($pdo, $s['table'], $s['seed']);
    }
    return $n;
}

/**
 * Tout le contenu d'un site, rangé par clé puis par langue :
 *   ['checkout.city' => ['pl' => 'Miasto', 'en' => 'City', 'uk' => '']]
 * Une langue absente donne une chaîne vide — c'est le signal « à traduire ».
 */
function wsm_cms_load(PDO $pdo, string $table, array $langs): array {
    $out = [];
    foreach ($pdo->query("SELECT lang, k, v FROM $table")->fetchAll() ?: [] as $r) {
        $out[(string) $r['k']][(string) $r['lang']] = (string) $r['v'];
    }
    foreach ($out as $k => $v) {
        foreach ($langs as $l) if (!isset($out[$k][$l])) $out[$k][$l] = '';
        ksort($out[$k]);
    }
    ksort($out);
    return $out;
}

/** Le préfixe d'une clé — sa section. */
function wsm_cms_prefix(string $key): string {
    $p = strpos($key, '.');
    return $p === false ? $key : substr($key, 0, $p);
}

/**
 * Les clés rangées par section. L'ordre suit WSM_CMS_SECTIONS (celui du
 * parcours du visiteur), puis l'alphabet pour tout ce qui n'y figure pas —
 * une section ajoutée demain apparaît donc quand même, à la fin.
 */
function wsm_cms_groups(array $content): array {
    $g = [];
    foreach (array_keys($content) as $k) $g[wsm_cms_prefix($k)][] = $k;
    $order = array_keys(WSM_CMS_SECTIONS);
    uksort($g, function ($a, $b) use ($order) {
        $ia = array_search($a, $order, true);
        $ib = array_search($b, $order, true);
        if ($ia === false && $ib === false) return strcmp($a, $b);
        if ($ia === false) return 1;
        if ($ib === false) return -1;
        return $ia <=> $ib;
    });
    return $g;
}

/**
 * Ce que le dépôt livre comme texte d'origine — la référence pour revenir en
 * arrière. Lue à la demande : c'est un fichier, pas une table.
 */
function wsm_cms_source(string $seedFile): array {
    if (!is_file($seedFile)) return [];
    $doc = json_decode((string) file_get_contents($seedFile), true);
    if (!is_array($doc) || empty($doc['strings'])) return [];
    $out = [];
    foreach ($doc['strings'] as $lang => $pairs) {
        foreach ((array) $pairs as $k => $v) $out[(string) $k][(string) $lang] = (string) $v;
    }
    return $out;
}

/**
 * Écrit les valeurs modifiées. On ne réécrit QUE ce qui change réellement :
 * une page de 200 champs renvoyée telle quelle ne doit pas produire 200
 * écritures ni 200 lignes de journal.
 *
 * @param array $vals ['clé' => ['pl' => '…', 'en' => '…']]
 * @return array [nombre de valeurs changées, liste des clés touchées]
 */
function wsm_cms_save(PDO $pdo, string $table, array $vals, array $langs, string $actor = ''): array {
    $now = wsm_cms_load($pdo, $table, $langs);
    // Ce qui part sur un site public a un auteur et une date. On les note ici
    // parce que c'est le seul endroit qui connaît l'AVANT et l'APRÈS ; le
    // reconstituer plus tard demanderait de relire la page pour s'apercevoir
    // qu'un texte a changé.
    $journal = is_file(__DIR__ . '/i18n.php');
    if ($journal) require_once __DIR__ . '/i18n.php';
    $avants = [];
    $upd = $pdo->prepare("UPDATE $table SET v = ? WHERE lang = ? AND k = ?");
    $ins = $pdo->prepare("INSERT INTO $table (lang, k, v) VALUES (?,?,?)");
    $n = 0; $touched = [];

    $pdo->beginTransaction();
    try {
        foreach ($vals as $k => $byLang) {
            $k = (string) $k;
            if (!isset($now[$k])) continue;                 // pas de clé inventée par un POST
            foreach ((array) $byLang as $lang => $v) {
                $lang = (string) $lang;
                if (!in_array($lang, $langs, true)) continue;
                $v = str_replace(["\r\n", "\r"], "\n", (string) $v);
                $v = mb_substr(trim($v), 0, 4000);
                if (($now[$k][$lang] ?? '') === $v) continue;
                // Une ligne peut manquer : la langue n'avait jamais été remplie.
                $st = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE lang = ? AND k = ?");
                $st->execute([$lang, $k]);
                if ((int) $st->fetchColumn()) $upd->execute([$v, $lang, $k]);
                else                          $ins->execute([$lang, $k, $v]);
                // Une saisie humaine cesse d'être « à relire » : quelqu'un
                // vient précisément de la relire, en la tapant.
                if ($journal && function_exists('wsm_i18n_put')) {
                    $cols = wsm_table_columns($pdo, $table);
                    if (in_array('origin', $cols, true)) {
                        $pdo->prepare("UPDATE $table SET origin = 'human' WHERE lang = ? AND k = ?")
                            ->execute([$lang, $k]);
                    }
                }
                $avants[] = [$lang, $k, (string) ($now[$k][$lang] ?? ''), $v];
                $n++;
                $touched[$k] = true;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [0, []];
    }
    // Après le commit seulement : un historique qui garderait la trace d'une
    // transaction annulée raconterait un changement qui n'a pas eu lieu.
    if ($journal && function_exists('wsm_i18n_log')) {
        foreach ($avants as [$lang, $k, $old, $new]) {
            wsm_i18n_log($pdo, $table, $lang, $k, $old, $new, $actor, 'human');
        }
    }
    return [$n, array_keys($touched)];
}

/**
 * Remet une clé au texte livré par le dépôt, dans toutes les langues connues.
 * Le filet de sécurité : une modification malheureuse n'oblige pas à retrouver
 * l'ancien texte de mémoire.
 *
 * @return int nombre de langues remises
 */
function wsm_cms_revert(PDO $pdo, string $table, string $seedFile, string $key, array $langs): int {
    $src = wsm_cms_source($seedFile);
    if (!isset($src[$key])) return 0;
    $vals = [];
    foreach ($langs as $l) {
        if (isset($src[$key][$l])) $vals[$key][$l] = $src[$key][$l];
    }
    if (!$vals) return 0;
    [$n] = wsm_cms_save($pdo, $table, $vals, $langs);
    return $n;
}

/**
 * Ce qui manque, par langue : les clés vides alors que le polonais est rempli.
 * Ce n'est pas une erreur (le repli fait son travail), mais c'est la seule
 * mesure honnête de « où en est la traduction ».
 */
function wsm_cms_missing(array $content, array $langs): array {
    $out = [];
    foreach ($langs as $l) $out[$l] = 0;
    foreach ($content as $byLang) {
        if (trim((string) ($byLang[WSM_CMS_BASE_LANG] ?? '')) === '') continue;
        foreach ($langs as $l) {
            if ($l === WSM_CMS_BASE_LANG) continue;
            if (trim((string) ($byLang[$l] ?? '')) === '') $out[$l]++;
        }
    }
    return $out;
}

/** Filtre de recherche : sur la clé ET sur les textes, sans casse ni accents. */
function wsm_cms_match(string $key, array $byLang, string $q): bool {
    if ($q === '') return true;
    $q = mb_strtolower($q);
    if (str_contains(mb_strtolower($key), $q)) return true;
    foreach ($byLang as $v) if (str_contains(mb_strtolower((string) $v), $q)) return true;
    return false;
}

/**
 * « 13,50 » → 1350 grosze. Le séparateur décimal polonais est la VIRGULE, et
 * c'est ce que les gens tapent ; accepter aussi le point évite un message
 * d'erreur pour une saisie parfaitement claire. Les espaces (y compris ceux
 * que colle un copier-coller depuis un tableur) sont ignorés.
 *
 * Jamais de négatif : un port négatif paierait le client pour commander.
 */
function wsm_cms_grosze($v): int {
    $s = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) $v));
    if ($s === '' || !is_numeric($s)) return 0;
    return max(0, (int) round(((float) $s) * 100));
}

/**
 * Un champ court se saisit sur une ligne, un paragraphe dans une zone de
 * texte. Décidé sur le texte le plus long des trois langues : c'est la seule
 * façon d'éviter qu'une traduction longue se retrouve dans un champ étroit.
 */
function wsm_cms_multiline(array $byLang): bool {
    foreach ($byLang as $v) {
        if (mb_strlen((string) $v) > 90 || str_contains((string) $v, "\n")) return true;
    }
    return false;
}
