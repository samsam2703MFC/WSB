<?php
// ============================================================================
//  rapport.php — l'état de la boutique, en une page, avant d'ouvrir.
//
//  À QUOI IL SERT. Chaque module sait dire s'il va bien. Personne ne sait
//  dire si LA BOUTIQUE va bien — et c'est la seule question qu'on se pose la
//  veille d'une ouverture, ou le lundi matin. Ce fichier compile les réponses
//  des modules, lance la suite hors ligne, et rend UN verdict.
//
//  TROIS RÈGLES, PARCE QU'UN RAPPORT QUI MENT EST PIRE QUE PAS DE RAPPORT :
//
//   1. IL DIT CE QU'IL N'A PAS PU VÉRIFIER. Un contrôle sauté est écrit
//      « pominięto », jamais compté comme réussi. Un rapport tout vert
//      obtenu en sautant la moitié des contrôles fait ouvrir une boutique
//      cassée.
//   2. IL SÉPARE CE QUI BLOQUE DE CE QUI GÊNE. « Aucune facture ne peut
//      s'émettre » et « cinq langues non traduites » ne se lisent pas au
//      même moment de la journée.
//   3. IL NOMME LE GESTE. Pas « IBAN manquant » mais « IBAN manquant —
//      Ustawienia › Dane sprzedawcy ; sans lui aucune facture ne part ».
//
//  Usage :
//    php tools/rapport.php              état + tests
//    php tools/rapport.php --sans-tests  état seul (rapide)
//    php tools/rapport.php --md          en Markdown, pour coller quelque part
// ============================================================================
declare(strict_types=1);

$API = dirname(__DIR__) . '/generique/backoffice/php-api';
$MD  = in_array('--md', $argv, true);
$SANS_TESTS = in_array('--sans-tests', $argv, true);

require_once $API . '/db.php';

$BLOQUE = [];     // ce qui empêche de vendre ou de facturer
$GENE   = [];     // ce qui coûte, sans arrêter
$OK     = [];     // ce qui va bien, et qu'on veut voir aller bien
$SAUTE  = [];     // ce qu'on n'a PAS pu vérifier — jamais compté comme vert

function bloque(string $quoi, string $geste): void { global $BLOQUE; $BLOQUE[] = [$quoi, $geste]; }
function gene(string $quoi, string $geste): void   { global $GENE;   $GENE[]   = [$quoi, $geste]; }
function bien(string $quoi): void                  { global $OK;     $OK[]     = $quoi; }
function saute(string $quoi, string $pourquoi): void { global $SAUTE; $SAUTE[] = [$quoi, $pourquoi]; }

// ---------------------------------------------------------------------------
//  1. La base répond-elle ?
// ---------------------------------------------------------------------------
try {
    $pdo = wsm_bootstrap();
    $moteur = wsm_config()['engine'] === 'mysql' ? 'MySQL' : 'SQLite';
    bien("Baza odpowiada ($moteur)");
} catch (Throwable $e) {
    bloque('Baza danych nie odpowiada', 'sprawdź config.local.php na serwerze — bez bazy nic nie działa');
    echo "PRZERWANO: bez bazy nie ma czego raportować.\n";
    exit(2);
}

// ---------------------------------------------------------------------------
//  2. Ce sans quoi on ne peut pas vendre / facturer
// ---------------------------------------------------------------------------
$cfg = wsm_config();

// L'IBAN : sans lui, AUCUNE facture ne part. C'est le blocage le plus cher
// du lot, parce qu'il ne se voit qu'au moment d'émettre.
$iban = trim((string) ($cfg['seller']['iban'] ?? ($cfg['firma']['iban'] ?? '')));
if ($iban === '' || strtolower($iban) === 'xxxx') {
    bloque('Brak numeru IBAN', 'Ustawienia › dane sprzedawcy — bez niego ŻADNA faktura nie wyjdzie');
} else {
    bien('IBAN uzupełniony');
}

// Stripe : sans lui la boutique prend des commandes que personne ne paie.
if (is_file($API . '/stripe.php')) {
    require_once $API . '/stripe.php';
    if (function_exists('wsm_stripe_ready') && wsm_stripe_ready()) bien('Stripe gotowy — klient może zapłacić');
    else bloque('Stripe nie przyjmuje płatności', 'Płatności — klucze platformy i konto Stripe sklepu; sklep przyjmuje zamówienia, których nikt nie opłaci');
} else { saute('Stripe', 'brak pliku stripe.php'); }

// InPost : sans lui les colis partent à la main. Gênant, pas bloquant.
if (is_file($API . '/inpost.php')) {
    require_once $API . '/inpost.php';
    if (wsm_inpost_enabled()) bien('InPost skonfigurowany — etykiety powstają same');
    else gene('InPost nieskonfigurowany', 'Ustawienia › wysyłka — paczki nadajesz ręcznie, ekran Wysyłka pokazuje które');
} else { saute('InPost', 'brak pliku inpost.php'); }

// KSeF : gênant, pas bloquant — et il faut le dire dans cet ordre. Le
// commerce fonctionne sans lui aujourd'hui : les factures s'émettent, se
// numérotent et s'envoient. Ce qui manque, c'est le dépôt AUTOMATIQUE au
// registre ; l'écran KSeF construit le XML FA(2) et on le dépose à la main.
// Le classer bloquant noierait les vrais blocages ; le taire ferait
// découvrir le sujet le jour de la bascule, avec des centaines de factures
// en retard.
if (is_file($API . '/ksef.php')) {
    require_once $API . '/ksef.php';
    if (wsm_ksef_enabled()) {
        bien('KSeF skonfigurowany — faktury idą do rejestru same');
    } else {
        $n = wsm_ksef_poza_rejestrem($pdo);
        gene('KSeF nieskonfigurowany' . ($n > 0 ? " — $n faktur poza rejestrem" : ''),
             'ekran KSeF — XML FA(2) pobierzesz i złożysz ręcznie; brakuje: '
             . implode(', ', array_map(fn($s) => explode(' —', $s)[0], wsm_ksef_manquants())));
    }
} else { saute('KSeF', 'brak pliku ksef.php'); }

// La caisse fiscale : gênante, pas bloquante, et peut-être inutile — si le
// comptable confirme la dispense (vente en ligne payée par un intermédiaire),
// il n'y a rien à brancher. On dit donc où en sont les paragons, sans rouge.
if (is_file($API . '/fiskal.php')) {
    require_once $API . '/fiskal.php';
    if (wsm_fiskal_enabled()) {
        $bl = (int) $pdo->query("SELECT COUNT(*) FROM wsm_invoices WHERE kind = 'paragon' AND fiskal_status = 'blad'")->fetchColumn();
        $bl > 0 ? gene("Kasa fiskalna: $bl paragon(ów) z błędem", 'ekran Zamówienia — przycisk przy linii „Kasa”')
                : bien('Kasa fiskalna podłączona — paragony idą przez drukarkę fiskalną');
    } else {
        gene('Kasa fiskalna niepodłączona — paragony są dokumentami wewnętrznymi',
             'Integracje → E-paragon fiskalny (Fakturownia), jeśli księgowa potwierdzi, że kasa jest wymagana');
    }
} else { saute('Kasa fiskalna', 'brak pliku fiskal.php'); }

// La poste : sans elle, aucun message ne sort de la file.
if (is_file($API . '/mail.php')) {
    require_once $API . '/mail.php';
    $smtp = $cfg['mail']['host'] ?? '';
    if (trim((string) $smtp) !== '' && strtolower((string) $smtp) !== 'xxxx') bien('Poczta wychodząca skonfigurowana');
    else bloque('Poczta wychodząca nieskonfigurowana',
                'Ustawienia › poczta — potwierdzenia zamówień zostają w kolejce i nikt ich nie dostaje');
} else { saute('poczta', 'brak pliku mail.php'); }

// ---------------------------------------------------------------------------
//  3. Ce qui attend quelqu'un, maintenant
// ---------------------------------------------------------------------------
if (is_file($API . '/shipping.php')) {
    require_once $API . '/shop.php';
    require_once $API . '/shipping.php';
    $file = wsm_ship_queue($pdo, 500);
    $k = wsm_ship_kpis($pdo, $file);
    if ($k['do_wyslania'] > 0) {
        $quoi = $k['do_wyslania'] . ' zapłaconych zamówień czeka na nadanie'
              . ($k['bloquees'] > 0 ? ', w tym ' . $k['bloquees'] . ' zablokowanych' : '');
        // Une commande payée non expédiée ne fait aucun bruit : c'est le
        // silence le plus cher de la boutique.
        ($k['bloquees'] > 0 ? 'bloque' : 'gene')($quoi, 'ekran Wysyłka');
    } else {
        bien('Nic nie czeka na nadanie');
    }
} else { saute('kolejka wysyłki', 'brak pliku shipping.php'); }

if (is_file($API . '/claims.php')) {
    require_once $API . '/claims.php';
    wsm_claims_ensure($pdo);
    $kc = wsm_claims_kpis($pdo);
    if ($kc['po_terminie'] > 0) {
        // Le silence de quatorze jours vaut acceptation : c'est la loi.
        bloque($kc['po_terminie'] . ' reklamacji PO TERMINIE odpowiedzi',
               'ekran Zgłoszenia — milczenie ponad 14 dni znaczy „uznane”, i kosztuje towar');
    } elseif ($kc['pilne'] > 0) {
        gene($kc['pilne'] . ' reklamacji z terminem ≤ 3 dni', 'ekran Zgłoszenia');
    } elseif ($kc['otwarte'] > 0) {
        bien($kc['otwarte'] . ' otwartych zgłoszeń, żadne nie pali się');
    } else {
        bien('Brak otwartych zgłoszeń');
    }
} else { saute('zgłoszenia', 'brak pliku claims.php'); }


// ---------------------------------------------------------------------------
//  4. Le catalogue : ce qui empêche de vendre un article
// ---------------------------------------------------------------------------
try {
    $sans = $pdo->query("SELECT
        SUM(CASE WHEN COALESCE(ean,'') = '' THEN 1 ELSE 0 END) AS bez_ean,
        SUM(CASE WHEN COALESCE(weight_g,0) <= 0 THEN 1 ELSE 0 END) AS bez_wagi,
        COUNT(*) AS wszystkie
        FROM wsm_products WHERE active = 1 AND shop_visible = 1")->fetch() ?: [];
    $n = (int) ($sans['wszystkie'] ?? 0);
    if ((int) ($sans['bez_wagi'] ?? 0) > 0) {
        // Sans poids, InPost refuse le colis : l'article se vend et ne part pas.
        bloque((int) $sans['bez_wagi'] . ' z ' . $n . ' produktów bez wagi',
               'ekran Produkty — przewoźnik odrzuci przesyłkę, towar sprzeda się i nie wyjdzie');
    }
    if ((int) ($sans['bez_ean'] ?? 0) > 0) {
        gene((int) $sans['bez_ean'] . ' z ' . $n . ' produktów bez EAN',
             'ekran Produkty — Allegro odrzuci ofertę, sklep działa');
    }
    if ($n === 0) bloque('Brak widocznych produktów', 'ekran Produkty — sklep nie ma czego sprzedać');
    elseif ((int) ($sans['bez_wagi'] ?? 0) === 0 && (int) ($sans['bez_ean'] ?? 0) === 0) {
        bien("Katalog kompletny ($n produktów: waga i EAN wszędzie)");
    }
} catch (Throwable $e) { saute('katalog', 'nie udało się odczytać produktów'); }

// ---------------------------------------------------------------------------
//  5. Les langues : servies mais vides ?
// ---------------------------------------------------------------------------
if (is_file($API . '/i18n.php')) {
    require_once $API . '/i18n.php';
    try {
        // On ne regarde QUE les langues PUBLIÉES : une langue préparée mais
        // non servie ne trompe personne. C'est le drapeau cliquable qui
        // promet quelque chose.
        $vides = [];
        foreach (wsm_lang_registry($pdo) as $code => $l) {
            if ($code === WSM_LANG_BASE || empty($l['published'])) continue;
            // La clé est « pct », pas « percent ». Lue de travers, la
            // couverture valait 0 pour TOUTES les langues et le rapport
            // accusait des traductions qui existaient. Un rapport qui se
            // trompe dans ce sens-là fait refaire du travail déjà fait.
            $c = wsm_lang_coverage_all($pdo, $code);
            $cov = (float) ($c['pct'] ?? 0);
            if ($cov < WSM_LANG_MIN_COVERAGE) $vides[] = $code . ' (' . (int) $cov . ' %)';
        }
        if ($vides) {
            // Une langue servie et vide affiche du polonais à quelqu'un qui a
            // cliqué sur son drapeau : c'est pire que ne pas la proposer.
            gene('Języki serwowane, a puste: ' . implode(', ', $vides),
                 'ekran Treści — klient klika swoją flagę i widzi polski');
        } else {
            bien('Każdy serwowany język jest przetłumaczony');
        }
    } catch (Throwable $e) { saute('języki', 'nie udało się odczytać rejestru języków'); }
} else { saute('języki', 'brak pliku i18n.php'); }

// ---------------------------------------------------------------------------
//  6. L'argent, en une ligne
// ---------------------------------------------------------------------------
try {
    $m = $pdo->query("SELECT COUNT(*) AS n,
                             COALESCE(SUM(CASE WHEN payment_status='oplacone' THEN total_gross ELSE 0 END),0) AS ca,
                             SUM(CASE WHEN payment_status<>'oplacone' AND status<>'anulowane' THEN 1 ELSE 0 END) AS nieopl
                        FROM wsm_orders")->fetch() ?: [];
    $ca = number_format(((int) ($m['ca'] ?? 0)) / 100, 2, ',', ' ');
    bien('Zamówień: ' . (int) ($m['n'] ?? 0) . ' · obrót zapłacony: ' . $ca . ' zł');
    if ((int) ($m['nieopl'] ?? 0) > 0) {
        gene((int) $m['nieopl'] . ' zamówień czeka na płatność', 'ekran Zamówienia › przypomnienie');
    }
} catch (Throwable $e) { saute('obrót', 'nie udało się policzyć zamówień'); }

// ---------------------------------------------------------------------------
//  7. La suite hors ligne
// ---------------------------------------------------------------------------
$tests = null;
if ($SANS_TESTS) {
    saute('Testy offline', 'pominięte na życzenie (--sans-tests)');
} else {
    $dir = $API . '/tests';
    $fichiers = glob($dir . '/e2e_*.php') ?: [];
    $verts = 0; $rouges = []; $assertions = 0;
    foreach ($fichiers as $f) {
        $sortie = [];
        $rc = 0;
        exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($f) . ' 2>&1', $sortie, $rc);
        $der = trim((string) end($sortie));
        if (preg_match('/(\d+)\s+assertions?|(\d+)\s+passed/', $der, $mm)) {
            $assertions += (int) ($mm[1] ?: $mm[2]);
        }
        if ($rc === 0) $verts++; else $rouges[] = basename($f) . ' — ' . $der;
    }
    $tests = ['plikow' => count($fichiers), 'zielonych' => $verts,
              'czerwonych' => $rouges, 'assertions' => $assertions];
    if ($rouges) {
        bloque(count($rouges) . ' z ' . count($fichiers) . ' zestawów testów CZERWONYCH',
               'uruchom je pojedynczo: ' . implode(' · ', array_slice($rouges, 0, 3)));
    } else {
        bien('Testy offline: ' . $verts . ' zestawów, ~' . $assertions . ' asercji, wszystkie zielone');
    }
}

// ---------------------------------------------------------------------------
//  Rendu
// ---------------------------------------------------------------------------
$verdict = $BLOQUE ? 'NIE GOTOWE' : ($GENE ? 'GOTOWE Z ZASTRZEŻENIAMI' : 'GOTOWE');

if ($MD) {
    echo "# Raport stanu — Mister Szoko\n\n";
    echo "**Werdykt: $verdict**\n\n";
    if ($BLOQUE) { echo "## Blokuje\n\n"; foreach ($BLOQUE as [$q, $g]) echo "- **$q** — $g\n"; echo "\n"; }
    if ($GENE)   { echo "## Uwiera\n\n";  foreach ($GENE as [$q, $g])   echo "- $q — $g\n"; echo "\n"; }
    if ($OK)     { echo "## Działa\n\n";  foreach ($OK as $q)           echo "- $q\n"; echo "\n"; }
    if ($SAUTE)  { echo "## Nie sprawdzono\n\n"; foreach ($SAUTE as [$q, $p]) echo "- $q — $p\n"; echo "\n"; }
    exit($BLOQUE ? 1 : 0);
}

$L = str_repeat('─', 74);
echo "\n$L\n  RAPORT STANU — Mister Szoko            " . date('Y-m-d H:i') . "\n$L\n\n";
echo "  WERDYKT: $verdict\n\n";
if ($BLOQUE) {
    echo "  ── BLOKUJE " . str_repeat('─', 62) . "\n";
    foreach ($BLOQUE as [$q, $g]) echo "   ✕ $q\n     → $g\n";
    echo "\n";
}
if ($GENE) {
    echo "  ── UWIERA " . str_repeat('─', 63) . "\n";
    foreach ($GENE as [$q, $g]) echo "   ! $q\n     → $g\n";
    echo "\n";
}
if ($OK) {
    echo "  ── DZIAŁA " . str_repeat('─', 63) . "\n";
    foreach ($OK as $q) echo "   ✓ $q\n";
    echo "\n";
}
if ($SAUTE) {
    // Règle 1 : un contrôle sauté n'est JAMAIS un contrôle réussi.
    echo "  ── NIE SPRAWDZONO " . str_repeat('─', 55) . "\n";
    foreach ($SAUTE as [$q, $p]) echo "   ? $q — $p\n";
    echo "\n";
}
echo "$L\n";
exit($BLOQUE ? 1 : 0);
