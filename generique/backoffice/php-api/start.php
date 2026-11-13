<?php
declare(strict_types=1);
/**
 * start.php — L'ASSISTANT DE DÉMARRAGE : quoi faire, dans quel ordre, et où.
 *
 * Créer une boutique (base, domaine, design system) prend quelques minutes.
 * La rendre prête à vendre prend des jours, et dépend surtout de choses
 * qui ne sont pas dans le code : la page Facebook, le compte Stripe de la
 * société, les clés InPost, la Search Console. Le propriétaire d'une
 * boutique neuve ne sait pas par où commencer ; celui qui l'aide non plus,
 * à la quatrième boutique. Cet écran le dit.
 *
 * UNE ÉTAPE = pourquoi, quoi faire, où aller (un écran de la console ou un
 * lien extérieur), et un ÉTAT.
 *
 * L'ÉTAT EST DÉTECTÉ QUAND LE CODE PEUT LE PROUVER, et alors il ne se coche
 * pas à la main. « Stripe : fait » coché par quelqu'un qui croit l'avoir
 * fait, sur une boutique qui ne peut pas encaisser, est pire qu'une case
 * vide : il éteint la seule alerte qui restait. On ne coche à la main que ce
 * que rien ne peut prouver — des textes juridiques relus, un logo qui
 * convient.
 *
 * Les coches manuelles vivent dans wsm_settings sous « start.* », avec qui
 * et quand. Le Siège lira l'avancement (wsm_start_avancement) pour afficher
 * « MyCobotBuddy : 9/13 ».
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/inpost.php';
require_once __DIR__ . '/dpd.php';
require_once __DIR__ . '/ksef.php';

const WSM_START_PREFIX = 'start.';

/** Une valeur de configuration réellement renseignée (ni vide, ni « xxxx »). */
function wsm_start_rempli(array $chemin): bool {
    return !wsm_setting_blank((string) (wsm_config_at($chemin) ?? ''));
}

/** Un nombre lu en base, 0 si la table manque (boutique neuve, schéma partiel). */
function wsm_start_compte(PDO $pdo, string $sql): int {
    try { return (int) $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
}

/** Le ménage d'ouverture a-t-il eu lieu ? (golive.php l'écrit dans le journal d'audit) */
function wsm_start_zerowanie(PDO $pdo): bool {
    return wsm_start_compte($pdo, "SELECT COUNT(*) FROM wsm_audit WHERE verb = 'Zerowanie przed startem'") > 0;
}

/**
 * Les étapes, dans l'ordre où on les fait.
 *
 * clé → [
 *   'tytul'  => titre,
 *   'po_co'  => pourquoi, en une phrase,
 *   'zrobic' => ce qu'il y a à faire, en gestes,
 *   'gdzie'  => [[libellé, adresse], …] — un écran de la console (« x.php »),
 *               une page de la boutique (« ../shop/… ») ou un site (https://…),
 *   'wykryj' => fn(PDO): ?array — [fait, ce qu'on a constaté], ou null si
 *               rien ne peut le prouver (étape cochée à la main),
 *   'opcja'  => true si l'étape n'est pas nécessaire pour ouvrir,
 * ]
 */
function wsm_start_etapy(): array {
    return [
        'tozsamosc' => [
            'tytul'  => 'Nazwa, logo i kolory',
            'po_co'  => 'To pierwsze, co widzi klient — i to, co trafia na faktury, e-maile i do Google.',
            'zrobic' => ['Wpisz nazwę sklepu (klucz „brand”) w Treściach.',
                         'Wgraj logo (Integracje → Sklep) — bez niego sklep pokazuje logo z design systemu.',
                         'Sprawdź kolory i czcionki w Wyglądzie.'],
            'gdzie'  => [['Treści', 'tresci.php'], ['Integracje → Sklep', 'ustawienia.php'], ['Wygląd', 'wyglad.php']],
            'wykryj' => null,
        ],
        'firma' => [
            'tytul'  => 'Dane firmy',
            'po_co'  => 'Każdy sklep to osobna firma: jej nazwa, NIP, adres i konto trafiają na faktury i do regulaminu.',
            'zrobic' => ['Uzupełnij sprzedawcę: nazwa, NIP, adres, rachunek bankowy.'],
            'gdzie'  => [['Integracje → Faktury', 'ustawienia.php']],
            'wykryj' => function (PDO $pdo): array {
                $brak = [];
                foreach (['seller_name' => 'nazwa', 'seller_nip' => 'NIP', 'seller_address' => 'adres', 'iban' => 'rachunek'] as $k => $l) {
                    if (!wsm_start_rempli(['invoice', $k])) $brak[] = $l;
                }
                return [!$brak, $brak ? 'Brakuje: ' . implode(', ', $brak) . '.' : 'Nazwa, NIP, adres i rachunek są wpisane.'];
            },
        ],
        'prawne' => [
            'tytul'  => 'Regulamin, prywatność, zwroty',
            'po_co'  => 'Bez nich nie wolno sprzedawać — i Stripe ich wymaga. Teksty muszą mówić o TEJ firmie.',
            'zrobic' => ['Przeczytaj regulamin i politykę prywatności — nazwa firmy, adres, NIP, terminy.',
                         'Wpisz adres do zwrotów (Integracje → Sklep).',
                         'Zaznacz, gdy teksty są sprawdzone przez Ciebie lub prawnika.'],
            'gdzie'  => [['Treści', 'tresci.php'], ['Regulamin w sklepie', '../shop/regulamin'], ['Prywatność w sklepie', '../shop/prywatnosc'], ['Zwroty w sklepie', '../shop/zwroty']],
            'wykryj' => null,
        ],
        'poczta' => [
            'tytul'  => 'Poczta sklepu',
            'po_co'  => 'Potwierdzenia zamówień, płatności i wysyłki idą z tego adresu. Bez niego klient nie dostaje nic.',
            'zrobic' => ['Załóż skrzynkę (np. sklep@twojadomena.pl) u dostawcy domeny.',
                         'Wpisz nadawcę i serwer SMTP w Integracjach.'],
            'gdzie'  => [['Integracje → Poczta', 'ustawienia.php']],
            'wykryj' => fn(PDO $pdo): array => wsm_mail_enabled()
                ? [true, 'Wysyłka poczty skonfigurowana.']
                : [false, 'Brak nadawcy albo serwera poczty.'],
        ],
        'platnosci' => [
            'tytul'  => 'Płatności (Stripe)',
            'po_co'  => 'Konto Stripe firmy, podpięte do platformy: klient płaci, prowizja platformy pobiera się sama.',
            'zrobic' => ['Na ekranie Płatności załóż konto Stripe sklepu i dokończ weryfikację firmy.',
                         'Sprawdź, czy diagnoza na tym ekranie świeci na zielono.'],
            'gdzie'  => [['Płatności', 'platnosci.php']],
            'wykryj' => function (PDO $pdo): array {
                if (!wsm_stripe_enabled()) return [false, 'Platforma nie ma jeszcze kluczy Stripe — to zadanie Superadmina.'];
                if (wsm_stripe_account() === '') return [false, 'Sklep nie ma jeszcze konta Stripe.'];
                if (!wsm_stripe_can_verify()) return [false, 'Brakuje sekretu webhooka — płatności nie zostaną potwierdzone.'];
                return [true, 'Konto ' . wsm_stripe_account() . ' podpięte' . (wsm_stripe_cfg()['test'] ? ' (tryb testowy).' : '.')];
            },
        ],
        'dostawa' => [
            'tytul'  => 'Dostawa',
            'po_co'  => 'Etykiety InPost / DPD drukowane z zamówienia, ceny i progi darmowej dostawy w koszyku.',
            'zrobic' => ['Załóż konto InPost ShipX (lub DPD) dla firmy i wpisz klucze w Integracjach.',
                         'Ustaw metody, ceny i próg darmowej dostawy.'],
            'gdzie'  => [['Integracje → InPost / DPD', 'ustawienia.php'], ['Dostawa', 'dostawa.php'], ['Kraje', 'kraje.php']],
            'wykryj' => function (PDO $pdo): array {
                $przewoznik = wsm_inpost_enabled() || wsm_dpd_enabled();
                $metody = wsm_start_compte($pdo, "SELECT COUNT(*) FROM wsm_shipping_methods WHERE active = 1");
                if (!$przewoznik) return [false, 'Brak kluczy InPost i DPD — etykiety trzeba by robić ręcznie.'];
                if ($metody === 0) return [false, 'Żadna metoda dostawy nie jest włączona.'];
                return [true, $metody . ' aktywn' . ($metody === 1 ? 'a metoda' : 'e metody') . ' dostawy, przewoźnik podpięty.'];
            },
        ],
        'katalog' => [
            'tytul'  => 'Produkty i zdjęcia',
            'po_co'  => 'Produkt bez zdjęcia sprzedaje się źle i nie przejdzie do Google Shopping ani do katalogu Meta.',
            'zrobic' => ['Dodaj produkty z ceną, wagą i stanem.', 'Do każdego wgraj zdjęcie.'],
            'gdzie'  => [['Produkty', 'produkty.php'], ['Magazyn', 'magazyn.php']],
            'wykryj' => function (PDO $pdo): array {
                $n = wsm_start_compte($pdo, "SELECT COUNT(*) FROM wsm_products WHERE shop_visible = 1");
                $bez = wsm_start_compte($pdo, "SELECT COUNT(*) FROM wsm_products WHERE shop_visible = 1 AND (image_url IS NULL OR image_url = '')");
                if ($n === 0) return [false, 'Żaden produkt nie jest jeszcze w sprzedaży.'];
                if ($bez > 0) return [false, $bez . ' z ' . $n . ' produktów w sprzedaży nie ma zdjęcia.'];
                return [true, $n . ' produktów w sprzedaży, wszystkie ze zdjęciem.'];
            },
        ],
        'faktury' => [
            'tytul'  => 'Faktury i KSeF',
            'po_co'  => 'Od 2026 faktury B2B idą przez KSeF. Każda firma ma własny token.',
            'zrobic' => ['Wygeneruj token KSeF firmy (aplikacja podatnika KSeF).', 'Wpisz go w Integracjach → KSeF.'],
            'gdzie'  => [['Integracje → KSeF', 'ustawienia.php'], ['KSeF', 'ksef.php'], ['Aplikacja podatnika KSeF', 'https://ksef.mf.gov.pl/']],
            'wykryj' => fn(PDO $pdo): array => wsm_ksef_enabled()
                ? [true, 'KSeF skonfigurowany.']
                : [false, 'Brak NIP-u, tokenu albo klucza publicznego KSeF.'],
        ],
        'meta' => [
            'tytul'  => 'Facebook i Instagram',
            'po_co'  => 'Posty z konsoli, katalog produktów, piksel i reklamy — wszystko wisi na stronie FB i koncie IG firmy.',
            'zrobic' => ['Załóż stronę na Facebooku dla sklepu.',
                         'Załóż konto Instagram, przełącz je na konto firmowe i połącz ze stroną.',
                         'W Meta Business Suite zweryfikuj domenę sklepu i utwórz piksel.',
                         'Wpisz identyfikatory strony, IG i piksela na ekranie Meta.'],
            'gdzie'  => [['Utwórz stronę na Facebooku', 'https://www.facebook.com/pages/create'],
                         ['Meta Business Suite', 'https://business.facebook.com/'],
                         ['Meta (FB / IG)', 'meta.php'], ['Posty FB / IG', 'posty.php']],
            'wykryj' => function (PDO $pdo): array {
                $brak = [];
                if (!wsm_start_rempli(['meta', 'page_id']))    $brak[] = 'strona FB';
                if (!wsm_start_rempli(['meta', 'ig_user_id'])) $brak[] = 'konto IG';
                if ($brak) return [false, 'Niepodpięte: ' . implode(', ', $brak) . '.'];
                return [true, 'Strona FB i konto IG podpięte' . (wsm_start_rempli(['meta', 'pixel_id']) ? ', piksel też.' : ' — piksel jeszcze nie.')];
            },
        ],
        'google' => [
            'tytul'  => 'Google',
            'po_co'  => 'Search Console: sklep w wynikach wyszukiwania. Merchant Center: produkty w Google Shopping, za darmo.',
            'zrobic' => ['Dodaj domenę w Search Console i wpisz kod weryfikacji.',
                         'Załóż Merchant Center i podaj mu adres pliku produktów.'],
            'gdzie'  => [['Search Console', 'https://search.google.com/search-console'],
                         ['Merchant Center', 'https://merchants.google.com/'], ['Google i SEO', 'widocznosc.php']],
            'wykryj' => function (PDO $pdo): array {
                $brak = [];
                if (!wsm_start_rempli(['google', 'site_verification'])) $brak[] = 'Search Console';
                if (!wsm_start_rempli(['google', 'merchant_id']))       $brak[] = 'Merchant Center';
                return [!$brak, $brak ? 'Brakuje: ' . implode(', ', $brak) . '.' : 'Search Console i Merchant Center podpięte.'];
            },
        ],
        'newsletter' => [
            'tytul'  => 'Newsletter i SMS',
            'po_co'  => 'Najtańszy powrót klienta. Opcjonalne na start — ale zgody zbierają się od pierwszego dnia.',
            'zrobic' => ['Sprawdź formularz zapisu w stopce.', 'Jeśli chcesz SMS-y: załóż konto SMSAPI i wpisz token.'],
            'gdzie'  => [['Newsletter', 'newsletter.php'], ['SMS', 'sms.php'], ['Integracje → SMS', 'ustawienia.php']],
            'wykryj' => fn(PDO $pdo): array => wsm_start_rempli(['smsapi', 'token'])
                ? [true, 'SMSAPI podpięte.']
                : [false, 'SMS-y jeszcze niepodpięte (newsletter działa na samej poczcie).'],
            'opcja'  => true,
        ],
        'test' => [
            'tytul'  => 'Zamówienie testowe od A do Z',
            'po_co'  => 'Jedyny dowód, że całość działa: koszyk, płatność, e-maile, etykieta, faktura.',
            'zrobic' => ['Złóż zamówienie w sklepie i zapłać kartą testową Stripe (4242 4242 4242 4242).',
                         'Sprawdź e-maile, wydrukuj etykietę, wystaw fakturę.'],
            'gdzie'  => [['Otwórz sklep', '../shop/'], ['Zamówienia', 'zamowienia.php']],
            'wykryj' => function (PDO $pdo): array {
                if (wsm_start_zerowanie($pdo)) return [true, 'Testy zrobione — dane testowe już wyczyszczone.'];
                $n = wsm_start_compte($pdo, "SELECT COUNT(*) FROM wsm_orders WHERE payment_status = 'oplacone'");
                return [$n > 0, $n > 0 ? $n . ' opłacon' . ($n === 1 ? 'e zamówienie' : 'e zamówienia') . ' — przejdź je do końca.' : 'Jeszcze żadne zamówienie nie zostało opłacone.'];
            },
        ],
        'zerowanie' => [
            'tytul'  => 'Wyczyszczenie danych testowych',
            'po_co'  => 'Testowe zamówienia kłamią: obrót, numeracja faktur (FV/240 zamiast FV/1), stany magazynu.',
            'zrobic' => ['Superadmin czyści aktywność testową jednym przyciskiem (produkty i ustawienia zostają).'],
            'gdzie'  => [['Superadmin', 'superadmin.php']],
            'wykryj' => fn(PDO $pdo): array => wsm_start_zerowanie($pdo)
                ? [true, 'Wyczyszczone.']
                : [false, 'Jeszcze nie.'],
        ],
        'otwarcie' => [
            'tytul'  => 'Otwarcie',
            'po_co'  => 'Klucze Stripe produkcyjne: od tej chwili płatności są prawdziwe.',
            'zrobic' => ['Superadmin przełącza klucze Stripe z testowych na produkcyjne.', 'Ogłoś otwarcie: post FB / IG, newsletter.'],
            'gdzie'  => [['Płatności', 'platnosci.php'], ['Posty FB / IG', 'posty.php'], ['Newsletter', 'newsletter.php']],
            'wykryj' => function (PDO $pdo): array {
                if (!wsm_stripe_ready()) return [false, 'Płatności jeszcze nie działają.'];
                return wsm_stripe_cfg()['test'] ? [false, 'Stripe jest jeszcze w trybie testowym.'] : [true, 'Stripe produkcyjny — sklep przyjmuje prawdziwe płatności.'];
            },
        ],
    ];
}

/** Les coches manuelles : clé d'étape → ['kiedy' => …, 'kto' => …]. */
function wsm_start_coches(PDO $pdo): array {
    $out = [];
    try {
        $st = $pdo->prepare("SELECT cle, val, updated_at, updated_by FROM wsm_settings WHERE cle LIKE ?");
        $st->execute([WSM_START_PREFIX . '%']);
        foreach ($st->fetchAll() ?: [] as $r) {
            if ((string) $r['val'] !== 'tak') continue;
            $out[substr((string) $r['cle'], strlen(WSM_START_PREFIX))] = ['kiedy' => (string) $r['updated_at'], 'kto' => (string) $r['updated_by']];
        }
    } catch (Throwable $e) { /* table absente : rien de coché */ }
    return $out;
}

/**
 * L'état de chaque étape : [
 *   'fait'   => bool,
 *   'mode'   => 'auto' (prouvé par les données) | 'reczne' (coché) | 'brak',
 *   'opis'   => ce qu'on a constaté, ou qui a coché et quand,
 *   'reczne' => true si l'étape se coche à la main,
 * ]
 */
function wsm_start_etat(PDO $pdo): array {
    $coches = wsm_start_coches($pdo);
    $out = [];
    foreach (wsm_start_etapy() as $k => $e) {
        if ($e['wykryj'] === null) {
            $c = $coches[$k] ?? null;
            $out[$k] = ['fait' => $c !== null, 'mode' => $c !== null ? 'reczne' : 'brak', 'reczne' => true,
                        'opis' => $c !== null ? 'Zaznaczone przez ' . ($c['kto'] ?: '—') . ', ' . substr($c['kiedy'], 0, 16) . '.' : 'Do zaznaczenia ręcznie, gdy zrobione.'];
            continue;
        }
        try { [$ok, $opis] = ($e['wykryj'])($pdo); }
        catch (Throwable $t) { [$ok, $opis] = [false, 'Nie udało się sprawdzić.']; }
        $out[$k] = ['fait' => (bool) $ok, 'mode' => $ok ? 'auto' : 'brak', 'reczne' => false, 'opis' => (string) $opis];
    }
    return $out;
}

/** Coche ou décoche une étape MANUELLE. Une étape détectée refuse : les données font foi. */
function wsm_start_cocher(PDO $pdo, string $cle, bool $fait, string $actor): bool {
    $e = wsm_start_etapy()[$cle] ?? null;
    if ($e === null || $e['wykryj'] !== null) return false;
    $k = WSM_START_PREFIX . $cle;
    $pdo->prepare("DELETE FROM wsm_settings WHERE cle = ?")->execute([$k]);
    if ($fait) {
        $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?, 'tak', 0, ?, ?)")
            ->execute([$k, date('Y-m-d H:i:s'), mb_substr($actor, 0, 120)]);
    }
    if (function_exists('wsm_audit')) wsm_audit($pdo, $actor, $fait ? 'Start: zrobione' : 'Start: cofnięte', $cle, 'Sieć');
    return true;
}

/**
 * L'avancement : ce que le Siège affichera, et ce que le pulpit résume.
 * Les étapes optionnelles ne comptent pas — une boutique peut ouvrir sans SMS.
 *
 * @return array{fait:int, razem:int, nastepny:?string}
 */
function wsm_start_avancement(PDO $pdo, ?array $etat = null): array {
    $etat ??= wsm_start_etat($pdo);
    $etapy = wsm_start_etapy();
    $fait = 0; $razem = 0; $nastepny = null;
    foreach ($etapy as $k => $e) {
        if (!empty($e['opcja'])) continue;
        $razem++;
        if ($etat[$k]['fait']) $fait++;
        elseif ($nastepny === null) $nastepny = $k;
    }
    return ['fait' => $fait, 'razem' => $razem, 'nastepny' => $nastepny];
}
