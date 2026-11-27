<?php
// Privé (mot de passe du projet) : « Integracje i SEO » du back office Ola flora.
//
//  · Integracje : formularze reklamowe Meta (Facebook + Instagram → zapytania), profil
//    Instagram, Google (Analytics 4, Search Console, Google Ads, Profil Firmy).
//  · SEO i pozycjonowanie : titre / description / image de partage de chaque page dans
//    chaque langue, fiche de la pracownia pour Google, frazy à suivre, liste de contrôle.
//
// Formulaires classiques (POST + redirection), jeton anti-CSRF : le mot de passe Basic est
// renvoyé par le navigateur tout seul, il ne prouve pas que la demande vient d'ici.
declare(strict_types=1);
require is_file(dirname(__DIR__) . '/ustawienia_lib.php') ? dirname(__DIR__) . '/ustawienia_lib.php' : dirname(__DIR__, 2) . '/app/ustawienia_lib.php';
$pdo = of_pdo();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$csrf = (string) ($_COOKIE['of_ust_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('of_ust_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict',
        'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')]);
}
$zakladka = ($_GET['z'] ?? '') === 'seo' ? 'seo' : 'integracje';
$komunikat = (string) ($_GET['ok'] ?? ''); $blad = (string) ($_GET['blad'] ?? '');
$wroc = function (string $z, string $ok = '', string $bl = '', string $kotwica = ''): void {
    header('Location: ?' . http_build_query(array_filter(['z' => $z, 'ok' => $ok, 'blad' => $bl])) . ($kotwica !== '' ? '#' . $kotwica : ''), true, 303);
    exit;
};
$tekst = fn(string $k, int $max = 300) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);

// ─── ZAPIS ───────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) $wroc($zakladka, '', 'Formularz wygasł — odśwież stronę i spróbuj ponownie.');
    $a = (string) ($_POST['akcja'] ?? '');
    $meta = of_int($pdo, 'meta');

    if ($a === 'meta_zapisz' || $a === 'meta_test' || $a === 'meta_subskrybuj' || $a === 'meta_pobierz') {
        if ($a === 'meta_zapisz') {
            $cfg = ['app_id' => preg_replace('~\D~', '', $tekst('app_id', 30)), 'page_id' => preg_replace('~\D~', '', $tekst('page_id', 30))];
            $sek = [];
            foreach (['app_secret' => 'app_secret', 'page_token' => 'page_token'] as $pole => $k) { $v = $tekst($pole, 600); if ($v !== '') $sek[$k] = $v; }
            if (!empty($_POST['app_secret_usun'])) $sek['app_secret'] = '';
            if (!empty($_POST['page_token_usun'])) $sek['page_token'] = '';
            if (empty($meta['sekrety']['verify_token'])) $sek['verify_token'] = bin2hex(random_bytes(12));
            of_int_zapisz($pdo, 'meta', $cfg, $sek);
            $wroc('integracje', 'Zapisano ustawienia Meta.', '', 'meta');
        }
        $meta = of_int($pdo, 'meta');
        $page = (string) ($meta['config']['page_id'] ?? ''); $tok = (string) ($meta['sekrety']['page_token'] ?? '');
        if ($page === '' || $tok === '') $wroc('integracje', '', 'Najpierw wpisz ID strony i token strony, potem „Zapisz”.', 'meta');
        if ($a === 'meta_test') {
            $r = of_graph($page, ['access_token' => $tok, 'fields' => 'name,instagram_business_account{username}']);
            if (isset($r['_blad'])) { of_int_zapisz($pdo, 'meta', [], null, 'blad', 'Test: ' . $r['_blad']); $wroc('integracje', '', 'Meta odpowiada: ' . $r['_blad'], 'meta'); }
            $f = of_graph($page . '/leadgen_forms', ['access_token' => $tok, 'fields' => 'name,status', 'limit' => 50]);
            $ile = isset($f['_blad']) ? null : count((array) ($f['data'] ?? []));
            $opis = 'Strona „' . ($r['name'] ?? $page) . '”' . (!empty($r['instagram_business_account']['username']) ? ' · Instagram @' . $r['instagram_business_account']['username'] : '')
                  . ($ile === null ? ' · formularze: ' . $f['_blad'] : ' · formularze reklamowe: ' . $ile);
            of_int_zapisz($pdo, 'meta', ['strona' => (string) ($r['name'] ?? ''), 'ig_user' => (string) ($r['instagram_business_account']['username'] ?? '')], null, $ile === null ? 'blad' : 'ok', 'Test: ' . $opis);
            $wroc('integracje', 'Połączenie działa — ' . $opis . '.', '', 'meta');
        }
        if ($a === 'meta_subskrybuj') {
            $r = of_graph($page . '/subscribed_apps', ['access_token' => $tok, 'subscribed_fields' => 'leadgen'], 'POST');
            if (isset($r['_blad']) || empty($r['success'])) { of_int_zapisz($pdo, 'meta', [], null, 'blad', 'Subskrypcja: ' . ($r['_blad'] ?? 'brak potwierdzenia')); $wroc('integracje', '', 'Subskrypcja nie powiodła się: ' . ($r['_blad'] ?? 'brak potwierdzenia od Meta') . '.', 'meta'); }
            of_int_zapisz($pdo, 'meta', ['subskrypcja' => date('Y-m-d H:i:s')], null, 'ok', 'Strona subskrybuje leady (leadgen).');
            $wroc('integracje', 'Strona wysyła teraz leady do tego adresu (pole leadgen).', '', 'meta');
        }
        if ($a === 'meta_pobierz') {
            $od = time() - 30 * 86400; $nowe = 0; $juz = 0; $bl = [];
            $f = of_graph($page . '/leadgen_forms', ['access_token' => $tok, 'fields' => 'id,name', 'limit' => 100]);
            if (isset($f['_blad'])) $wroc('integracje', '', 'Meta odpowiada: ' . $f['_blad'], 'meta');
            foreach ((array) ($f['data'] ?? []) as $form) {
                $po = ''; $strony = 0;
                do {
                    $p = ['access_token' => $tok, 'fields' => 'id,created_time,field_data,platform,ad_name,campaign_name', 'limit' => 100,
                          'filtering' => json_encode([['field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $od]])];
                    if ($po !== '') $p['after'] = $po;
                    $l = of_graph((string) $form['id'] . '/leads', $p);
                    if (isset($l['_blad'])) { $bl[] = $form['name'] . ': ' . $l['_blad']; break; }
                    foreach ((array) ($l['data'] ?? []) as $lead) {
                        try { of_meta_lead_zapisz($pdo, $lead, (string) $form['name']) !== '' ? $nowe++ : $juz++; } catch (Throwable $x) { $bl[] = 'zapis'; }
                    }
                    $po = (string) ($l['paging']['cursors']['after'] ?? '');
                } while ($po !== '' && !empty($l['paging']['next']) && ++$strony < 10);
            }
            of_int_zapisz($pdo, 'meta', ['pobrano' => date('Y-m-d H:i:s'), 'leady' => (int) ($meta['config']['leady'] ?? 0) + $nowe], null, $bl ? 'blad' : 'ok',
                'Pobrano: ' . $nowe . ' nowe, ' . $juz . ' już były' . ($bl ? ' · ' . implode('; ', array_unique($bl)) : ''));
            $wroc('integracje', 'Leady z 30 dni: ' . $nowe . ' nowe zapytania, ' . $juz . ' już było w back office.', $bl ? 'Niektóre formularze nie odpowiedziały: ' . implode('; ', array_unique($bl)) : '', 'meta');
        }
    }
    if ($a === 'instagram_zapisz') {
        $u = fn($k) => preg_match('~^https://[^\s]+$~', $tekst($k, 300)) ? $tekst($k, 300) : '';
        $user = ltrim(preg_replace('~[^A-Za-z0-9._@]~', '', $tekst('ig_user', 60)) ?? '', '@');
        $profil = $u('ig_profil') ?: ($user !== '' ? 'https://www.instagram.com/' . $user . '/' : '');
        of_int_zapisz($pdo, 'instagram', ['user' => $user, 'profil' => $profil, 'facebook' => $u('fb_profil'), 'posty' => empty($_POST['posty']) ? 0 : 1], null, $profil !== '' ? 'ok' : 'nieskonfig');
        if (!empty($_POST['posty'])) { try { of_ig_odswiez($pdo, true); } catch (Throwable $x) { /* dit dans l'état ci-dessous */ } }
        $wroc('integracje', 'Zapisano profile.', '', 'instagram');
    }
    if ($a === 'google_zapisz') {
        $g = ['ga4' => strtoupper($tekst('ga4', 20)), 'gsc' => trim(preg_replace('~.*content="([^"]+)".*~s', '$1', $tekst('gsc', 300)) ?? ''),
              'ads_id' => strtoupper($tekst('ads_id', 20)), 'ads_label' => $tekst('ads_label', 40), 'gbp' => $tekst('gbp', 300), 'opinie' => $tekst('opinie', 300)];
        $zle = [];
        if ($g['ga4'] !== '' && !preg_match('~^G-[A-Z0-9]{4,16}$~', $g['ga4'])) $zle[] = 'identyfikator GA4 ma postać G-XXXXXXX';
        if ($g['gsc'] !== '' && !preg_match('~^[A-Za-z0-9_\-]{10,100}$~', $g['gsc'])) $zle[] = 'kod Search Console to sam ciąg z content="…"';
        if ($g['ads_id'] !== '' && !preg_match('~^AW-\d{6,14}$~', $g['ads_id'])) $zle[] = 'identyfikator Google Ads ma postać AW-123456789';
        foreach (['gbp', 'opinie'] as $k) if ($g[$k] !== '' && !preg_match('~^https://[^\s]+$~', $g[$k])) $zle[] = 'adresy zaczynają się od https://';
        if ($zle) $wroc('integracje', '', 'Nie zapisano: ' . implode('; ', array_unique($zle)) . '.', 'google');
        of_int_zapisz($pdo, 'google', $g, null, ($g['ga4'] . $g['gsc'] . $g['ads_id'] . $g['gbp']) !== '' ? 'ok' : 'nieskonfig');
        $wroc('integracje', 'Zapisano ustawienia Google.', '', 'google');
    }
    if ($a === 'seo_zapisz') {
        $s = of_seo($pdo);
        foreach (OF_SEO_STRONY as $k => $_) foreach (OF_JEZYKI as $j) {
            $s['strony'][$k][$j]['title'] = mb_substr(trim((string) ($_POST['s'][$k][$j]['title'] ?? $s['strony'][$k][$j]['title'])), 0, 120);
            $s['strony'][$k][$j]['description'] = mb_substr(trim((string) ($_POST['s'][$k][$j]['description'] ?? $s['strony'][$k][$j]['description'])), 0, 320);
            $og = trim((string) ($_POST['s'][$k][$j]['og'] ?? $s['strony'][$k][$j]['og']));
            $s['strony'][$k][$j]['og'] = $og === '' || of_cms_src_ok($og) || preg_match('~^https://[^\s"<>]+$~', $og) ? mb_substr($og, 0, 300) : $s['strony'][$k][$j]['og'];
        }
        foreach (array_keys($s['firma']) as $k) if (isset($_POST['f'][$k])) $s['firma'][$k] = mb_substr(trim((string) $_POST['f'][$k]), 0, $k === 'opis' ? 600 : 200);
        $adr = $tekst('adres', 300);
        $s['adres'] = preg_match('~^https?://[^\s]+$~', $adr) ? $adr : '';
        $s['indeksuj'] = !empty($_POST['indeksuj']);
        $fr = [];
        foreach ((array) ($_POST['fr'] ?? []) as $row) {
            $fraza = mb_substr(trim((string) ($row['fraza'] ?? '')), 0, 120);
            if ($fraza === '') continue;
            $poz = trim((string) ($row['pozycja'] ?? ''));
            $stara = array_values(array_filter($s['frazy'], fn($x) => ($x['fraza'] ?? '') === $fraza))[0] ?? [];
            $fr[] = ['fraza' => $fraza, 'strona' => isset(OF_SEO_STRONY[$row['strona'] ?? '']) ? $row['strona'] : 'home',
                     'lang' => in_array($row['lang'] ?? '', OF_JEZYKI, true) ? $row['lang'] : 'pl',
                     'pozycja' => preg_match('~^\d{1,3}$~', $poz) ? $poz : ($poz === '' ? '' : '>100'),
                     'poprzednia' => ($stara['pozycja'] ?? '') !== $poz ? (string) ($stara['pozycja'] ?? '') : (string) ($stara['poprzednia'] ?? ''),
                     'sprawdzono' => ($stara['pozycja'] ?? '') !== $poz && $poz !== '' ? date('Y-m-d') : (string) ($stara['sprawdzono'] ?? '')];
            if (count($fr) >= 40) break;
        }
        $s['frazy'] = $fr;
        of_seo_zapisz($pdo, $s);
        $wroc('seo', 'Zapisano — strona pokazuje nowe znaczniki od razu.', '', (string) ($_POST['kotwica'] ?? ''));
    }
    $wroc($zakladka);
}

// ─── ODCZYT ──────────────────────────────────────────────────────────────────
$meta = of_int($pdo, 'meta'); $ig = of_int($pdo, 'instagram'); $go = of_int($pdo, 'google');
$seo = of_seo($pdo);
$adres = of_adres($seo);
$webhook = $adres . '_wsb/app/meta.php';
$biblioteka = of_cms_biblioteka($pdo);
$brakAlt = (int) $pdo->query("SELECT COUNT(*) FROM of_media WHERE tenant_id = " . OF_TENANT . " AND kind = 'photo' AND (alt IS NULL OR alt = '')")->fetchColumn();
$leadyMeta = (int) $pdo->query("SELECT COUNT(*) FROM of_inquiries WHERE tenant_id = " . OF_TENANT . " AND source LIKE 'meta-%'")->fetchColumn();
$leadyIg = (int) $pdo->query("SELECT COUNT(*) FROM of_inquiries WHERE tenant_id = " . OF_TENANT . " AND source = 'meta-instagram'")->fetchColumn();
$root = of_site_root();
$ds = ''; foreach (glob($root . '/_ds/*/styles.css') ?: [] as $f) { $ds = '../../../' . substr($f, strlen($root) + 1); break; }

// La liste de contrôle du référencement : chaque point se lit, aucun ne se coche à la main.
$kontrola = function (string $k, string $j) use ($seo): array {
    $s = $seo['strony'][$k][$j]; $t = $s['title']; $d = $s['description'];
    $frazy = array_values(array_filter($seo['frazy'], fn($f) => $f['strona'] === $k && $f['lang'] === $j));
    $w = [];
    $lt = mb_strlen($t); $ld = mb_strlen($d);
    $w[] = [$lt >= 30 && $lt <= 60, $lt === 0 ? 'Brak tytułu' : "Tytuł: $lt znaków" . ($lt < 30 ? ' — za krótki (30–60)' : ($lt > 60 ? ' — Google go utnie (30–60)' : ''))];
    $w[] = [$ld >= 70 && $ld <= 160, $ld === 0 ? 'Brak opisu' : "Opis: $ld znaków" . ($ld < 70 ? ' — za krótki (70–160)' : ($ld > 160 ? ' — Google go utnie (70–160)' : ''))];
    $w[] = [$s['og'] !== '' || $j !== 'pl' || $k !== 'home', $s['og'] !== '' ? 'Zdjęcie do udostępniania wybrane' : 'Zdjęcie do udostępniania: domyślne'];
    foreach ($frazy as $f) {
        $fl = mb_strtolower($f['fraza']);
        $w[] = [str_contains(mb_strtolower($t), $fl) || str_contains(mb_strtolower($d), $fl), '„' . $f['fraza'] . '” ' . (str_contains(mb_strtolower($t), $fl) ? 'w tytule' : (str_contains(mb_strtolower($d), $fl) ? 'w opisie' : 'nie ma w tytule ani w opisie'))];
    }
    $ok = count(array_filter($w, fn($x) => $x[0]));
    return ['punkty' => $w, 'wynik' => (int) round(100 * $ok / max(1, count($w)))];
};
$lokalne = [
    [!empty($go['config']['gbp']), 'Profil Firmy w Google (wizytówka w Mapach)', 'Załóż albo odbierz wizytówkę: business.google.com — to ona daje miejsce w „mapce” wyników lokalnych.'],
    [!empty($go['config']['opinie']), 'Link do wystawiania opinii', 'W Profilu Firmy: „Poproś o opinie” → skopiuj link. Wysyłaj go parom po weselu.'],
    [!empty($go['config']['gsc']), 'Search Console zweryfikowana', 'search.google.com/search-console → „Prefiks URL” → metoda „Tag HTML” → wklej tu kod.'],
    [$seo['firma']['miasto'] !== '' && $seo['firma']['telefon'] !== '', 'Nazwa, adres i telefon (NAP) wpisane', 'Te same dane co w Profilu Firmy i na Instagramie — Google porównuje je między sobą.'],
    [$seo['firma']['obszar'] !== '', 'Obszar działania', 'Miasta i regiony, w których pracujesz: Google łączy je z wyszukiwaniami „florystka ślubna + miasto”.'],
    [!empty($ig['config']['profil']), 'Instagram powiązany ze stroną', 'Profil trafia do karty firmy (sameAs) — Google wie, że to ta sama pracownia.'],
    [(bool) $seo['frazy'], 'Frazy do pozycjonowania', 'Kilka fraz, po których klienci szukają — każda przypisana do strony i języka.'],
    [$brakAlt === 0, $brakAlt === 0 ? 'Wszystkie zdjęcia mają opis (alt)' : $brakAlt . ' zdjęć bez opisu (alt)', 'Opis zdjęcia czyta Google Grafika — uzupełnij go w back office → strony.'],
    [$seo['indeksuj'], 'Strona widoczna dla Google', 'Wyłączone = noindex: Google nie pokaże strony. Włącz, gdy strona jest gotowa.'],
];
$kolorWyniku = fn(int $w) => $w >= 80 ? '#465802' : ($w >= 50 ? '#9a6a12' : '#9b2c1f');
$adresAbs = fn(string $src) => preg_match('~^https?://~', $src) ? $src : '../../../' . $src;
?><!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Integracje i SEO · Ola flora</title>
<?php if ($ds !== ''): ?><link rel="stylesheet" href="<?= $h($ds) ?>"><?php endif; ?>
<style>
:root { --len:#f4efe7; --papier:#fbf8f3; --umbra:#2e2924; --umbra2:#5d544b; --szary:#8b8177; --filet:#d8cdbd; --oliwka:#465802; --oliwka-j:#eef0e0; --rdza:#9b2c1f; --rdza-j:#f6e3df; --miod:#9a6a12; --miod-j:#f6ecd6; }
* { box-sizing: border-box; }
body { margin: 0; background: var(--len); color: var(--umbra); font: 400 15px/1.55 "Work Sans", system-ui, sans-serif; }
a { color: var(--umbra); }
header.top { display: flex; align-items: center; gap: 20px; padding: 16px clamp(16px, 4vw, 40px); border-bottom: 1px solid var(--filet); background: var(--len); position: sticky; top: 0; z-index: 5; }
header.top img { height: 26px; opacity: .86; }
header.top .sp { flex: 1; }
header.top a.wroc { font-size: 13px; text-decoration: none; border-bottom: 1px solid var(--umbra); padding-bottom: 2px; }
.tabs { display: flex; gap: 28px; padding: 0 clamp(16px, 4vw, 40px); border-bottom: 1px solid var(--filet); }
.tabs a { text-decoration: none; font-size: 12px; letter-spacing: .18em; text-transform: uppercase; padding: 16px 0 12px; border-bottom: 1px solid transparent; margin-bottom: -1px; color: var(--umbra2); }
.tabs a.on { color: var(--umbra); border-color: var(--umbra); }
main { max-width: 1120px; padding: 28px clamp(16px, 4vw, 40px) 120px; }
h1 { font-weight: 300; font-size: clamp(28px, 4vw, 40px); line-height: 1.1; margin: 4px 0 6px; letter-spacing: -.01em; }
.lead { color: var(--umbra2); max-width: 70ch; margin: 0 0 24px; }
section.blok { background: var(--papier); border: 1px solid var(--filet); padding: 22px clamp(16px, 3vw, 28px); margin: 0 0 20px; scroll-margin-top: 80px; }
section.blok > header { display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap; margin-bottom: 14px; }
section.blok h2 { margin: 0; font-weight: 400; font-size: 21px; }
.stan { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; color: var(--umbra2); }
.stan::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: var(--szary); }
.stan.ok::before { background: var(--oliwka); } .stan.blad::before { background: var(--rdza); }
.siatka { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px 22px; }
label.pole { display: flex; flex-direction: column; gap: 5px; font-size: 13px; color: var(--umbra2); }
label.pole input, label.pole textarea, label.pole select { font: inherit; color: var(--umbra); background: #fff; border: 1px solid var(--filet); padding: 10px 12px; border-radius: 0; width: 100%; }
label.pole textarea { min-height: 76px; resize: vertical; }
label.pole small { color: var(--szary); font-size: 12px; }
input:focus-visible, textarea:focus-visible, select:focus-visible, button:focus-visible, a:focus-visible { outline: 2px solid var(--oliwka); outline-offset: 2px; }
.przyciski { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
button, .btn { font: inherit; font-size: 12px; letter-spacing: .16em; text-transform: uppercase; padding: 11px 18px; border: 1px solid var(--umbra); background: transparent; color: var(--umbra); cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
button.glowny { background: var(--umbra); color: var(--len); }
button.glowny:hover { background: var(--oliwka); border-color: var(--oliwka); }
.kopiuj { display: flex; gap: 0; } .kopiuj input { flex: 1; min-width: 0; font: 13px "Space Mono", ui-monospace, monospace; background: var(--len); border: 1px solid var(--filet); padding: 9px 10px; }
.kopiuj button { border-left: 0; padding: 9px 14px; }
.kroki { margin: 6px 0 0; padding-left: 20px; color: var(--umbra2); font-size: 14px; } .kroki li { margin: 3px 0; }
.info { font-size: 14px; padding: 10px 14px; border-left: 2px solid var(--oliwka); background: var(--oliwka-j); margin: 0 0 18px; }
.info.blad { border-color: var(--rdza); background: var(--rdza-j); }
.dane { display: grid; grid-template-columns: max-content 1fr; gap: 4px 18px; font-size: 14px; margin: 0 0 6px; } .dane dt { color: var(--szary); } .dane dd { margin: 0; }
.jezyki { display: flex; gap: 0; border-bottom: 1px solid var(--filet); margin: 0 0 14px; }
.jezyki button { border: 0; border-bottom: 1px solid transparent; margin-bottom: -1px; padding: 8px 14px; letter-spacing: .14em; }
.jezyki button.on { border-color: var(--umbra); }
.podglad { background: #fff; border: 1px solid var(--filet); padding: 14px 16px; font-family: Arial, sans-serif; max-width: 600px; }
.podglad .u { color: #202124; font-size: 13px; } .podglad .t { color: #1a0dab; font-size: 19px; line-height: 1.3; margin: 3px 0; } .podglad .d { color: #4d5156; font-size: 13.5px; line-height: 1.45; }
.licznik { font-variant-numeric: tabular-nums; } .licznik.zle { color: var(--rdza); }
.wynik { display: inline-flex; align-items: baseline; gap: 4px; font-size: 13px; padding: 2px 8px; border: 1px solid currentColor; font-variant-numeric: tabular-nums; }
.lista { list-style: none; margin: 0; padding: 0; }
.lista li { display: grid; grid-template-columns: 20px 1fr; gap: 8px; padding: 9px 0; border-top: 1px solid var(--filet); font-size: 14px; }
.lista li:first-child { border-top: 0; }
.lista li i { font-style: normal; font-weight: 600; } .lista li.tak i { color: var(--oliwka); } .lista li.nie i { color: var(--rdza); }
.lista li small { display: block; color: var(--szary); font-size: 13px; }
table.frazy { width: 100%; border-collapse: collapse; font-size: 14px; }
table.frazy th { text-align: left; font-weight: 400; font-size: 12px; color: var(--szary); padding: 6px 8px; border-bottom: 1px solid var(--filet); }
table.frazy td { padding: 6px 8px; border-bottom: 1px solid var(--filet); vertical-align: middle; }
table.frazy input, table.frazy select { font: inherit; border: 1px solid var(--filet); background: #fff; padding: 7px 8px; width: 100%; }
table.frazy td.poz { width: 110px; } table.frazy td.kier { width: 54px; text-align: center; font-variant-numeric: tabular-nums; }
.w-gore { color: var(--oliwka); } .w-dol { color: var(--rdza); }
.galeria { display: grid; grid-template-columns: repeat(auto-fill, minmax(84px, 1fr)); gap: 8px; max-height: 210px; overflow: auto; padding: 2px; }
.galeria label { position: relative; cursor: pointer; } .galeria img { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; border: 2px solid transparent; }
.galeria input { position: absolute; opacity: 0; } .galeria input:checked + img { border-color: var(--oliwka); }
.przelacznik { display: inline-flex; align-items: center; gap: 10px; font-size: 14px; cursor: pointer; }
.przelacznik input { width: 18px; height: 18px; accent-color: var(--oliwka); }
@media (max-width: 760px) { .tabs { gap: 18px; overflow-x: auto; } table.frazy thead { display: none; } table.frazy tr { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; padding: 8px 0; border-bottom: 1px solid var(--filet); } table.frazy td { border: 0; padding: 0; } table.frazy td:first-child { grid-column: 1 / -1; } }
</style></head>
<body>
<header class="top">
  <img src="../../../assets/ola-flora/logotyp-poziom-black.png" alt="Ola flora">
  <span class="sp"></span>
  <a class="wroc" href="../../../Back%20office.dc.html">Wróć do back office</a>
</header>
<nav class="tabs" aria-label="Sekcje">
  <a href="?z=integracje" class="<?= $zakladka === 'integracje' ? 'on' : '' ?>">Integracje</a>
  <a href="?z=seo" class="<?= $zakladka === 'seo' ? 'on' : '' ?>">SEO i pozycjonowanie</a>
</nav>
<main>
<?php if ($komunikat !== ''): ?><p class="info" role="status"><?= $h($komunikat) ?></p><?php endif; ?>
<?php if ($blad !== ''): ?><p class="info blad" role="alert"><?= $h($blad) ?></p><?php endif; ?>

<?php if ($zakladka === 'integracje'): ?>
<h1>Integracje</h1>
<p class="lead">Zapytania z reklam na Facebooku i Instagramie trafiają prosto do „Zapytań” w back office — z każdą odpowiedzią z formularza. Google mierzy ruch i zgłoszenia, a Search Console pokazuje, po czym ludzie Cię znajdują.</p>

<section class="blok" id="meta">
  <header><h2>Formularze reklamowe Meta — Facebook i Instagram</h2>
    <span class="stan <?= $h($meta['status']) ?>"><?= $meta['status'] === 'ok' ? 'połączono' : ($meta['status'] === 'blad' ? 'błąd' : 'nie skonfigurowano') ?></span></header>
  <dl class="dane">
    <dt>Zapytania z Meta</dt><dd><?= $leadyMeta ?> (w tym Instagram: <?= $leadyIg ?>)</dd>
    <?php if (!empty($meta['config']['strona'])): ?><dt>Strona</dt><dd><?= $h($meta['config']['strona']) ?><?= !empty($meta['config']['ig_user']) ? ' · Instagram @' . $h($meta['config']['ig_user']) : '' ?></dd><?php endif; ?>
    <dt>Ostatni lead</dt><dd><?= $h(of_cms_stamp($meta['config']['ostatni_webhook'] ?? null) ?: 'jeszcze żaden') ?></dd>
    <?php if ($meta['last_test_result'] !== ''): ?><dt>Ostatnia operacja</dt><dd><?= $h($meta['last_test_result']) ?></dd><?php endif; ?>
  </dl>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <div class="siatka">
      <label class="pole">ID aplikacji Meta<input name="app_id" inputmode="numeric" value="<?= $h($meta['config']['app_id'] ?? '') ?>"></label>
      <label class="pole">Klucz tajny aplikacji<input name="app_secret" type="password" autocomplete="off" placeholder="<?= !empty($meta['sekrety']['app_secret']) ? '•••••••• zapisany — puste = bez zmian' : '' ?>">
        <?php if (!empty($meta['sekrety']['app_secret'])): ?><small><label><input type="checkbox" name="app_secret_usun" value="1"> usuń zapisany</label></small><?php endif; ?></label>
      <label class="pole">ID strony na Facebooku<input name="page_id" inputmode="numeric" value="<?= $h($meta['config']['page_id'] ?? '') ?>"></label>
      <label class="pole">Token strony (dostęp do leadów)<input name="page_token" type="password" autocomplete="off" placeholder="<?= !empty($meta['sekrety']['page_token']) ? '•••••••• zapisany — puste = bez zmian' : '' ?>">
        <?php if (!empty($meta['sekrety']['page_token'])): ?><small><label><input type="checkbox" name="page_token_usun" value="1"> usuń zapisany</label></small><?php endif; ?></label>
    </div>
    <div class="przyciski">
      <button class="glowny" name="akcja" value="meta_zapisz">Zapisz</button>
      <button name="akcja" value="meta_test">Sprawdź połączenie</button>
      <button name="akcja" value="meta_subskrybuj">Włącz wysyłanie leadów</button>
      <button name="akcja" value="meta_pobierz">Pobierz leady z 30 dni</button>
    </div>
  </form>
  <div class="siatka" style="margin-top:18px">
    <label class="pole">Adres wywołania zwrotnego (Callback URL)
      <span class="kopiuj"><input readonly value="<?= $h($webhook) ?>" id="wh"><button type="button" data-kopiuj="wh">Kopiuj</button></span></label>
    <label class="pole">Token weryfikacyjny (Verify token)
      <span class="kopiuj"><input readonly value="<?= $h($meta['sekrety']['verify_token'] ?? 'powstanie po pierwszym „Zapisz”') ?>" id="vt"><button type="button" data-kopiuj="vt">Kopiuj</button></span></label>
  </div>
  <ol class="kroki">
    <li>developers.facebook.com → Twoja aplikacja → Ustawienia → Podstawowe: skopiuj <b>ID aplikacji</b> i <b>Klucz tajny</b>.</li>
    <li>Narzędzie Graph API Explorer: wybierz stronę Ola flora i uprawnienia <code>leads_retrieval</code>, <code>pages_manage_metadata</code>, <code>pages_show_list</code>, <code>pages_read_engagement</code> → skopiuj <b>token strony</b> (najlepiej długoterminowy, z użytkownika systemowego).</li>
    <li>Aplikacja → Webhooks → Page: wklej adres i token weryfikacyjny z tej strony, zasubskrybuj pole <code>leadgen</code>.</li>
    <li>Tutaj: „Zapisz”, „Sprawdź połączenie”, „Włącz wysyłanie leadów”. Formularze z reklam na Instagramie przychodzą tą samą drogą (źródło „meta-instagram”).</li>
    <li>Business Suite → Ustawienia firmy → Integracje → Dostęp do leadów: aplikacja musi być na liście, inaczej Meta ich nie wyda.</li>
  </ol>
</section>

<section class="blok" id="instagram">
  <header><h2>Instagram i Facebook — profile</h2><span class="stan <?= $h($ig['status']) ?>"><?= !empty($ig['config']['profil']) ? 'powiązano' : 'nie powiązano' ?></span></header>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <div class="siatka">
      <label class="pole">Nazwa na Instagramie<input name="ig_user" placeholder="olaflora" value="<?= $h($ig['config']['user'] ?? '') ?>"><small>Profil łączy się z kartą firmy w Google (sameAs).</small></label>
      <label class="pole">Adres profilu Instagram<input name="ig_profil" placeholder="https://www.instagram.com/…/" value="<?= $h($ig['config']['profil'] ?? '') ?>"></label>
      <label class="pole">Adres strony na Facebooku<input name="fb_profil" placeholder="https://www.facebook.com/…" value="<?= $h($ig['config']['facebook'] ?? '') ?>"></label>
    </div>
    <?php $igc = of_ig_cache($pdo); $igPosty = !isset($ig['config']['posty']) || (int) $ig['config']['posty'] === 1;
          $igRelie = ($meta['config']['page_id'] ?? '') !== '' && ($meta['sekrety']['page_token'] ?? '') !== ''; ?>
    <label class="pole" style="flex-direction:row;align-items:center;gap:10px;margin-top:14px"><input type="checkbox" name="posty" value="1" <?= $igPosty ? 'checked' : '' ?> style="width:auto">
      Pokaż ostatnie posty z Instagrama na stronie (nad stopką)</label>
    <p class="pomoc" style="margin:6px 0 0;font-size:13px;color:#6a6157">
      <?php if (!$igRelie): ?>Posty wymagają połączenia Meta (ID strony i token strony powyżej) — strona na Facebooku musi mieć połączone konto Instagram (firmowe).
      <?php elseif (($igc['erreur'] ?? '') !== ''): ?>Ostatni odczyt: <?= $h($igc['erreur']) ?> — sprawdź token strony (uprawnienie instagram_basic).
      <?php elseif (!empty($igc['at'])): ?>Na stronie: <?= count((array) ($igc['items'] ?? [])) ?> postów<?= !empty($igc['user']) ? ' z @' . $h($igc['user']) : '' ?> · odczytano <?= $h(date('Y-m-d H:i', (int) $igc['at'])) ?> (odświeżane co godzinę).
      <?php else: ?>Posty zostaną pobrane po zapisaniu.<?php endif; ?></p>
    <div class="przyciski"><button class="glowny" name="akcja" value="instagram_zapisz">Zapisz</button></div>
  </form>
</section>

<section class="blok" id="google">
  <header><h2>Google</h2><span class="stan <?= $h($go['status']) ?>"><?= $go['status'] === 'ok' ? 'skonfigurowano' : 'nie skonfigurowano' ?></span></header>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <div class="siatka">
      <label class="pole">Google Analytics 4 — identyfikator<input name="ga4" placeholder="G-XXXXXXXXXX" value="<?= $h($go['config']['ga4'] ?? '') ?>"><small>Ładuje się dopiero po zgodzie odwiedzającego (pasek cookies).</small></label>
      <label class="pole">Search Console — kod weryfikacji<input name="gsc" placeholder="cały znacznik meta albo sam kod" value="<?= $h($go['config']['gsc'] ?? '') ?>"></label>
      <label class="pole">Google Ads — identyfikator<input name="ads_id" placeholder="AW-123456789" value="<?= $h($go['config']['ads_id'] ?? '') ?>"></label>
      <label class="pole">Google Ads — etykieta konwersji<input name="ads_label" placeholder="AbCdEf123" value="<?= $h($go['config']['ads_label'] ?? '') ?>"><small>Konwersja = wysłany formularz zapytania.</small></label>
      <label class="pole">Profil Firmy w Google (wizytówka)<input name="gbp" placeholder="https://maps.app.goo.gl/…" value="<?= $h($go['config']['gbp'] ?? '') ?>"></label>
      <label class="pole">Link do wystawienia opinii<input name="opinie" placeholder="https://g.page/r/…/review" value="<?= $h($go['config']['opinie'] ?? '') ?>"></label>
    </div>
    <div class="przyciski"><button class="glowny" name="akcja" value="google_zapisz">Zapisz</button>
      <a class="btn" href="https://search.google.com/search-console" target="_blank" rel="noopener">Search Console ↗</a>
      <a class="btn" href="https://business.google.com/" target="_blank" rel="noopener">Profil Firmy ↗</a></div>
  </form>
</section>

<?php else: ?>
<h1>SEO i pozycjonowanie</h1>
<p class="lead">Jak strona wygląda w Google i przy udostępnianiu, za jakie frazy ma się pojawiać i co jeszcze zrobić, żeby pracownia była widoczna lokalnie.</p>
<form method="post" id="seoform">
<input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="kotwica" value="" id="kotwica">

<section class="blok" id="przeglad">
  <header><h2>Przegląd</h2></header>
  <ul class="lista">
  <?php foreach (OF_SEO_STRONY as $k => $p): foreach (OF_JEZYKI as $j): $kw = $kontrola($k, $j); ?>
    <li class="<?= $kw['wynik'] >= 80 ? 'tak' : 'nie' ?>"><i><?= $kw['wynik'] >= 80 ? '✓' : '!' ?></i>
      <div><b style="font-weight:500"><?= $h($p['nazwa']) ?> · <?= strtoupper($j) ?></b>
        <span class="wynik" style="color:<?= $kolorWyniku($kw['wynik']) ?>;margin-left:8px"><?= $kw['wynik'] ?> / 100</span>
        <small><?= $h(implode(' · ', array_map(fn($x) => $x[1], array_filter($kw['punkty'], fn($x) => !$x[0])))) ?: 'Wszystko w porządku.' ?></small></div></li>
  <?php endforeach; endforeach; ?>
  </ul>
</section>

<?php foreach (OF_SEO_STRONY as $k => $p): ?>
<section class="blok" id="s-<?= $k ?>">
  <header><h2><?= $h($p['nazwa']) ?></h2><a href="<?= $h(of_url_strony($adres, $k, 'pl')) ?>" target="_blank" rel="noopener" style="font-size:13px">otwórz stronę ↗</a></header>
  <div class="jezyki" role="tablist">
    <?php foreach (OF_JEZYKI as $i => $j): ?><button type="button" role="tab" class="<?= $i === 0 ? 'on' : '' ?>" data-jez="<?= $k ?>" data-j="<?= $j ?>"><?= strtoupper($j) ?></button><?php endforeach; ?>
  </div>
  <?php foreach (OF_JEZYKI as $i => $j): $s = $seo['strony'][$k][$j]; ?>
  <div data-panel="<?= $k ?>-<?= $j ?>" <?= $i === 0 ? '' : 'hidden' ?>>
    <div class="siatka">
      <div style="display:flex;flex-direction:column;gap:14px">
        <label class="pole">Tytuł w Google <span class="licznik" data-licz="t-<?= $k ?>-<?= $j ?>" data-min="30" data-max="60"></span>
          <input name="s[<?= $k ?>][<?= $j ?>][title]" id="t-<?= $k ?>-<?= $j ?>" maxlength="120" value="<?= $h($s['title']) ?>" data-pod="<?= $k ?>-<?= $j ?>"></label>
        <label class="pole">Opis w Google <span class="licznik" data-licz="d-<?= $k ?>-<?= $j ?>" data-min="70" data-max="160"></span>
          <textarea name="s[<?= $k ?>][<?= $j ?>][description]" id="d-<?= $k ?>-<?= $j ?>" maxlength="320" data-pod="<?= $k ?>-<?= $j ?>"><?= $h($s['description']) ?></textarea></label>
      </div>
      <div>
        <span style="font-size:13px;color:var(--umbra2)">Podgląd wyniku w Google</span>
        <div class="podglad" data-podglad="<?= $k ?>-<?= $j ?>"><div class="u"><?= $h(preg_replace('~^https?://~', '', of_url_strony($adres, $k, $j))) ?></div><div class="t"></div><div class="d"></div></div>
      </div>
    </div>
    <div style="margin-top:14px">
      <span style="font-size:13px;color:var(--umbra2)">Zdjęcie przy udostępnianiu (Facebook, Instagram, WhatsApp) — 1200 × 630 wygląda najlepiej</span>
      <div class="galeria">
        <label title="domyślne"><input type="radio" name="s[<?= $k ?>][<?= $j ?>][og]" value="" <?= $s['og'] === '' ? 'checked' : '' ?>><img src="<?= $h($adresAbs('assets/photos/ola/kalie-jaskry-limonki.jpg')) ?>" alt="domyślne" loading="lazy"></label>
        <?php foreach (array_slice($biblioteka, 0, 60) as $zd): ?>
        <label title="<?= $h($zd['alt']) ?>"><input type="radio" name="s[<?= $k ?>][<?= $j ?>][og]" value="<?= $h($zd['src']) ?>" <?= $s['og'] === $zd['src'] ? 'checked' : '' ?>><img src="<?= $h($adresAbs($zd['src'])) ?>" alt="<?= $h($zd['alt']) ?>" loading="lazy"></label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="przyciski"><button class="glowny" name="akcja" value="seo_zapisz" data-kotwica="s-<?= $k ?>">Zapisz</button></div>
</section>
<?php endforeach; ?>

<section class="blok" id="frazy">
  <header><h2>Pozycjonowanie — frazy</h2><span style="font-size:13px;color:var(--umbra2)">po czym klienci mają Cię znaleźć</span></header>
  <table class="frazy">
    <thead><tr><th>Fraza</th><th>Strona</th><th>Język</th><th>Pozycja w Google</th><th>Zmiana</th><th></th></tr></thead>
    <tbody id="fr-body">
    <?php $wiersze = $seo['frazy'] ?: [['fraza' => '', 'strona' => 'home', 'lang' => 'pl', 'pozycja' => '', 'poprzednia' => '', 'sprawdzono' => '']];
    foreach ($wiersze as $i => $f):
        $zm = (is_numeric($f['pozycja'] ?? '') && is_numeric($f['poprzednia'] ?? '')) ? (int) $f['poprzednia'] - (int) $f['pozycja'] : null; ?>
      <tr>
        <td><input name="fr[<?= $i ?>][fraza]" value="<?= $h($f['fraza']) ?>" placeholder="np. florystka ślubna Wrocław"></td>
        <td><select name="fr[<?= $i ?>][strona]"><?php foreach (OF_SEO_STRONY as $k => $p): ?><option value="<?= $k ?>" <?= ($f['strona'] ?? '') === $k ? 'selected' : '' ?>><?= $k === 'home' ? 'Strona główna' : 'Oferta' ?></option><?php endforeach; ?></select></td>
        <td><select name="fr[<?= $i ?>][lang]"><?php foreach (OF_JEZYKI as $j): ?><option value="<?= $j ?>" <?= ($f['lang'] ?? '') === $j ? 'selected' : '' ?>><?= strtoupper($j) ?></option><?php endforeach; ?></select></td>
        <td class="poz"><input name="fr[<?= $i ?>][pozycja]" inputmode="numeric" value="<?= $h($f['pozycja'] ?? '') ?>" placeholder="—" title="<?= $h(($f['sprawdzono'] ?? '') !== '' ? 'sprawdzono ' . $f['sprawdzono'] : 'wpisz pozycję po sprawdzeniu') ?>"></td>
        <td class="kier"><?= $zm === null ? '—' : ($zm > 0 ? '<span class="w-gore">↑' . $zm . '</span>' : ($zm < 0 ? '<span class="w-dol">↓' . -$zm . '</span>' : '=')) ?></td>
        <td><?php if (($f['fraza'] ?? '') !== ''): ?><a href="https://www.google.com/search?<?= $h(http_build_query(['q' => $f['fraza'], 'hl' => $f['lang'] ?? 'pl', 'gl' => 'pl', 'pws' => 0])) ?>" target="_blank" rel="noopener">sprawdź ↗</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="przyciski"><button type="button" id="fr-dodaj">Dodaj frazę</button><button class="glowny" name="akcja" value="seo_zapisz" data-kotwica="frazy">Zapisz</button></div>
  <p style="font-size:13px;color:var(--umbra2);margin:12px 0 0">„Sprawdź” otwiera Google bez personalizacji. Dokładne pozycje i liczbę wyświetleń pokazuje Search Console → Skuteczność.</p>
</section>

<section class="blok" id="firma">
  <header><h2>Karta pracowni dla Google</h2><span style="font-size:13px;color:var(--umbra2)">dane strukturalne „Florist” — Google pokazuje je w wynikach i w Mapach</span></header>
  <div class="siatka">
    <?php foreach (['nazwa' => 'Nazwa', 'telefon' => 'Telefon', 'email' => 'E-mail', 'ulica' => 'Ulica i numer', 'kod' => 'Kod pocztowy', 'miasto' => 'Miasto', 'region' => 'Województwo',
                    'obszar' => 'Obszar działania (miasta, po przecinku)', 'godziny' => 'Godziny (np. Mo-Fr 09:00-17:00)', 'ceny' => 'Przedział cen ($ – $$$$)', 'lat' => 'Szerokość geogr.', 'lng' => 'Długość geogr.'] as $k => $lbl): ?>
    <label class="pole"><?= $h($lbl) ?><input name="f[<?= $k ?>]" value="<?= $h($seo['firma'][$k]) ?>"></label>
    <?php endforeach; ?>
    <label class="pole" style="grid-column:1/-1">Krótki opis pracowni<textarea name="f[opis]" maxlength="600"><?= $h($seo['firma']['opis']) ?></textarea></label>
  </div>
  <div class="przyciski"><button class="glowny" name="akcja" value="seo_zapisz" data-kotwica="firma">Zapisz</button>
    <a class="btn" href="https://search.google.com/test/rich-results?<?= $h(http_build_query(['url' => $adres])) ?>" target="_blank" rel="noopener">Test wyników z elementami rozszerzonymi ↗</a></div>
</section>

<section class="blok" id="lokalne">
  <header><h2>Widoczność lokalna — lista kontrolna</h2></header>
  <ul class="lista">
    <?php foreach ($lokalne as [$ok, $co, $jak]): ?><li class="<?= $ok ? 'tak' : 'nie' ?>"><i><?= $ok ? '✓' : '!' ?></i><div><?= $h($co) ?><?php if (!$ok): ?><small><?= $h($jak) ?></small><?php endif; ?></div></li><?php endforeach; ?>
  </ul>
</section>

<section class="blok" id="techniczne">
  <header><h2>Ustawienia techniczne</h2></header>
  <div class="siatka">
    <label class="pole">Adres strony (z https://, kończy się „/”)<input name="adres" value="<?= $h($seo['adres']) ?>" placeholder="<?= $h($adres) ?>"><small>Puste = adres, pod którym otwierasz back office. Wpisz docelową domenę, gdy będzie gotowa.</small></label>
    <label class="pole">Mapa strony do Search Console
      <span class="kopiuj"><input readonly value="<?= $h($adres . 'sitemap.xml') ?>" id="sm"><button type="button" data-kopiuj="sm">Kopiuj</button></span></label>
  </div>
  <p style="margin:14px 0 0"><label class="przelacznik"><input type="checkbox" name="indeksuj" value="1" <?= $seo['indeksuj'] ? 'checked' : '' ?>> Strona widoczna dla Google (indeksowanie)</label></p>
  <div class="przyciski"><button class="glowny" name="akcja" value="seo_zapisz" data-kotwica="techniczne">Zapisz</button>
    <a class="btn" href="https://pagespeed.web.dev/analysis?<?= $h(http_build_query(['url' => $adres])) ?>" target="_blank" rel="noopener">PageSpeed ↗</a></div>
</section>
</form>
<?php endif; ?>
</main>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-kopiuj]');
  if (b) { var i = document.getElementById(b.getAttribute('data-kopiuj')); i.select(); (navigator.clipboard ? navigator.clipboard.writeText(i.value) : Promise.resolve(document.execCommand('copy'))).then(function () { b.textContent = 'Skopiowano'; setTimeout(function () { b.textContent = 'Kopiuj'; }, 1500); }); return; }
  var j = e.target.closest('[data-jez]');
  if (j) { var k = j.getAttribute('data-jez');
    document.querySelectorAll('[data-jez="' + k + '"]').forEach(function (x) { x.classList.toggle('on', x === j); });
    document.querySelectorAll('[data-panel^="' + k + '-"]').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== k + '-' + j.getAttribute('data-j'); }); return; }
  var s = e.target.closest('[data-kotwica]'); if (s) document.getElementById('kotwica').value = s.getAttribute('data-kotwica');
});
function odswiez(klucz) {
  var t = document.getElementById('t-' + klucz), d = document.getElementById('d-' + klucz), p = document.querySelector('[data-podglad="' + klucz + '"]');
  if (!t || !p) return;
  var tt = t.value || '(brak tytułu)', dd = d.value || '(brak opisu)';
  p.querySelector('.t').textContent = tt.length > 60 ? tt.slice(0, 58) + ' …' : tt;
  p.querySelector('.d').textContent = dd.length > 160 ? dd.slice(0, 157) + ' …' : dd;
  [t, d].forEach(function (el) { var l = document.querySelector('[data-licz="' + el.id + '"]'); if (!l) return;
    var n = el.value.length, mi = +l.getAttribute('data-min'), ma = +l.getAttribute('data-max');
    l.textContent = '— ' + n + ' / ' + mi + '–' + ma; l.classList.toggle('zle', n < mi || n > ma); });
}
document.querySelectorAll('[data-pod]').forEach(function (el) { el.addEventListener('input', function () { odswiez(el.getAttribute('data-pod')); }); odswiez(el.getAttribute('data-pod')); });
var dod = document.getElementById('fr-dodaj');
if (dod) dod.addEventListener('click', function () {
  var tb = document.getElementById('fr-body'), n = tb.rows.length, r = tb.rows[0].cloneNode(true);
  r.querySelectorAll('input,select').forEach(function (el) { el.name = el.name.replace(/fr\[\d+\]/, 'fr[' + n + ']'); if (el.tagName === 'INPUT') el.value = ''; });
  r.cells[4].textContent = '—'; r.cells[5].textContent = '';
  tb.appendChild(r); r.querySelector('input').focus();
});
</script>
</body></html>
