<?php
// ============================================================================
//  siege/ — le superadmin BuddyCorp. UN site, à part des boutiques
//  (admin.<domaine>), le seul qui détienne la clé maîtresse de la table
//  centrale : il lit et écrit les clés d'intégration de TOUTES les boutiques.
//
//  Sur le site d'une boutique, cette page n'existe pas (404) : sa
//  configuration n'a ni clé maîtresse ni liste de téléphones.
//
//    ?            entrée : téléphone + code du jour, puis code SMS → 12 h
//    ?p=przeglad  les données générales de chaque boutique + « Konsola ↗ » (entrée directe)
//    ?p=klucze    les clés d'une boutique (lecture : état ; écriture : nouvelle valeur)
//    ?p=sklepy    le registre des boutiques
//    ?p=dziennik  qui a changé quoi (des noms de clés, jamais une valeur)
//    ?p=wyloguj   fin de session
//
//  Habillage (client, 14 nov. 2026 : « moderne, minimaliste, type Apple, en
//  gardant les couleurs de TF Buddy ») : siege.css, sur les jetons de tfb.css.
// ============================================================================
declare(strict_types=1);

$API = is_dir(__DIR__ . '/../backoffice/api') ? __DIR__ . '/../backoffice/api' : __DIR__ . '/../backoffice/php-api';
require_once $API . '/db.php';
require_once $API . '/settings.php';
require_once $API . '/siege_auth.php';
require_once $API . '/siege_dane.php';

if (!wsb_siege_actif()) { http_response_code(404); exit; }

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
// Polices du design system TFB (Google Fonts) ; « Konsola ↗ » poste ici puis
// redirige vers le domaine de la boutique : form-action doit l'accepter (https:).
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; form-action 'self' https:; frame-ancestors 'none'; base-uri 'none'");
header('X-Robots-Tag: noindex, nofollow');

$pdo = wsb_pdo();
if (!$pdo) { http_response_code(503); exit('Centrala niedostępna.'); }
wsb_schema($pdo);

session_name('wsb_etap');
session_set_cookie_params(['path' => '/', 'secure' => wsb_https(), 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['csrf'];
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Formularz wygasł — odśwież stronę.'); }

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$p = (string) ($_GET['p'] ?? '');
$kto = wsb_sesja($pdo);
$msg = ''; $err = '';

// ---- Petits rendus partagés -------------------------------------------------
/** Un pictogramme au trait (SVG, couleur du texte). */
function ikona(string $n): string {
    $d = [
        'przeglad' => '<rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/>',
        'klucze'   => '<circle cx="8" cy="15" r="4"/><path d="M10.8 12.2 20 3m-4 4 3 3m-6 0 2 2"/>',
        'sklepy'   => '<path d="M4 9h16l-1.2-4.2A1 1 0 0 0 17.8 4H6.2a1 1 0 0 0-1 .8z"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9"/><path d="M10 20v-5h4v5"/>',
        'dziennik' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'wyjscie'  => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 16l-4-4 4-4M6 12h10"/>',
        'strzalka' => '<path d="M7 17 17 7M9 7h8v8"/>',
        'ok'       => '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.6 2.5L16 9.5"/>',
        'blad'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/>',
        'szewron'  => '<path d="m9 6 6 6-6 6"/>',
    ][$n] ?? '';
    return '<svg class="ik" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

/** Un message en bandeau (succès ou erreur). */
function bandeau(string $ok, string $blad): string {
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $o = '';
    if ($ok !== '')   $o .= '<div class="baner ok">' . ikona('ok') . '<span>' . $h($ok) . '</span></div>';
    if ($blad !== '') $o .= '<div class="baner blad">' . ikona('blad') . '<span>' . $h($blad) . '</span></div>';
    return $o;
}

/** Les initiales d'un nom, pour la pastille d'une boutique. */
function inicjaly(string $s): string {
    $w = preg_split('/[\s\-_.]+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    $i = mb_strtoupper(mb_substr($w[0], 0, 1) . (isset($w[1]) ? mb_substr($w[1], 0, 1) : mb_substr($w[0], 1, 1)));
    return $i !== '' ? $i : '?';
}

// ---- Entrée ------------------------------------------------------------------
if ($kto === null) {
    $etap2 = is_array($_SESSION['wsb_etap'] ?? null) && ($_SESSION['wsb_etap']['do'] ?? 0) > time();
    if ($post && ($_POST['a'] ?? '') === 'etap1') {
        $r = wsb_etap1($pdo, (string) ($_POST['tel'] ?? ''), (string) ($_POST['kod'] ?? ''), $ip);
        if ($r === 'ok') { $etap2 = true; $msg = 'Jeśli numer jest uprawniony, wysłaliśmy SMS z kodem (ważny 3 minuty).'; }
        elseif ($r === 'kod') $err = 'Kod dnia jest nieprawidłowy.';
        elseif ($r === 'limit') $err = 'Zbyt wiele prób. Spróbuj ponownie za 15 minut.';
        else $err = 'Nie udało się wysłać SMS-a. Sprawdź konfigurację SMSAPI siedziby.';
        // Numéro hors liste : on montre l'étape 2 quand même (rien ne trahit la liste).
        if ($r === 'ok' && !isset($_SESSION['wsb_etap'])) $_SESSION['wsb_etap'] = ['tel' => '', 'do' => time() + WSB_ETAP_SEKUNDY];
    } elseif ($post && ($_POST['a'] ?? '') === 'etap2') {
        $r = (($_SESSION['wsb_etap']['tel'] ?? '') === '') ? 'zly' : wsb_etap2($pdo, (string) ($_POST['sms'] ?? ''), $ip);
        if ($r === 'ok') { header('Location: ?p=przeglad', true, 303); exit; }
        $err = ['zly' => 'Kod SMS jest nieprawidłowy.', 'stary' => 'Kod SMS wygasł — zacznij od nowa.',
                'brak' => 'Sesja logowania wygasła — zacznij od nowa.', 'limit' => 'Zbyt wiele prób — zacznij od nowa za 15 minut.'][$r]
              ?? 'SMSAPI nie odpowiada. Spróbuj ponownie.';
        $etap2 = is_array($_SESSION['wsb_etap'] ?? null);
    } elseif (isset($_GET['od-nowa'])) {
        unset($_SESSION['wsb_etap']); $etap2 = false;
    }
    strona('Logowanie', function () use ($h, $csrf, $etap2, $msg, $err) { ?>
      <div class="wejscie">
        <div class="wejscie-karta">
          <div class="logo-duze">B</div>
          <h1>BuddyCorp</h1>
          <p class="pod">Panel superadministratora</p>
          <div class="kroki" aria-label="Krok <?= $etap2 ? 2 : 1 ?> z 2"><span class="<?= $etap2 ? 'zrob' : 'teraz' ?>"></span><span class="<?= $etap2 ? 'teraz' : '' ?>"></span></div>
          <?= bandeau($msg, $err) ?>
          <?php if (!$etap2): ?>
          <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="etap1">
            <div class="grupa">
              <label class="wiersz"><span>Telefon</span><input name="tel" type="tel" required placeholder="+48 600 000 000" autofocus></label>
              <label class="wiersz"><span>Kod dnia</span><input name="kod" inputmode="numeric" required maxlength="8" placeholder="••••••"></label>
            </div>
            <button class="glowny pelny">Wyślij kod SMS</button>
          </form>
          <?php else: ?>
          <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="etap2">
            <input class="kod-sms" name="sms" inputmode="numeric" autocomplete="one-time-code" required maxlength="10" placeholder="Kod z SMS-a" autofocus>
            <button class="glowny pelny">Zaloguj na 12 godzin</button>
          </form>
          <a class="cichy" href="?od-nowa=1">Zacznij od nowa</a>
          <?php endif; ?>
        </div>
        <p class="stopka">Dostęp tylko dla uprawnionych numerów · sesja 12 h</p>
      </div>
    <?php });
    exit;
}

// ---- Connecté ----------------------------------------------------------------
if ($p === 'wyloguj') {
    if ($post) { wsb_sesja_zamknij($pdo); session_destroy(); header('Location: ./', true, 303); exit; }
    $p = 'klucze';
}

if ($p === '') $p = 'przeglad';

// Entrée directe dans la console d'une boutique : un lien signé d'une minute.
if ($p === 'sso') {
    $b = (string) ($_POST['b'] ?? '');
    $serw = wsb_sklepy_serwera();
    if (!$post || !isset($serw[$b]) || $serw[$b]['domena'] === '') { http_response_code(404); exit('Nieznany sklep.'); }
    $jeton = wsb_sso_jeton($b, wsb_tel_maska($kto));
    wsb_dziennik($pdo, wsb_tel_maska($kto), $b, 'wejście do konsoli sklepu');
    header('Location: https://' . $serw[$b]['domena'] . '/backoffice/sso.php?t=' . rawurlencode($jeton), true, 303);
    exit;
}

if ($p === 'przeglad') {
    $dane = array_map('wsb_dane_sklepu', wsb_sklepy_serwera());
    strona('Przegląd', function () use ($h, $csrf, $dane) {
        $zl = fn(int $g) => number_format($g / 100, 2, ',', ' ') . ' zł';
        $zl0 = fn(int $g) => number_format($g / 100, 0, ',', ' ') . ' zł';
        // Évolution face au mois précédent : une pastille verte ou rouge.
        $zmiana = function (int|float $a, int|float $b) use ($h): string {
            if ($b == 0) return $a > 0 ? '<span class="delta up">nowe</span>' : '<span class="delta">—</span>';
            $x = ($a - $b) / $b * 100;
            return '<span class="delta ' . ($x >= 0 ? 'up' : 'down') . '">' . ($x >= 0 ? '↑ ' : '↓ ') . $h(number_format(abs($x), 0, ',', ' ')) . ' %</span>';
        };
        $sum = ['n' => 0, 'g' => 0, 'pn' => 0, 'pg' => 0, 'd' => 0, 'pd' => 0];
        foreach ($dane as $d) if (!empty($d['dostepny'])) {
            $sum['n'] += $d['teraz']['orders']; $sum['g'] += $d['teraz']['gross']; $sum['d'] += $d['rozl_teraz']['do_zaplaty'];
            $sum['pn'] += $d['poprz']['orders']; $sum['pg'] += $d['poprz']['gross']; $sum['pd'] += $d['rozl_poprz']['do_zaplaty'];
        }
        $miesiace = ['', 'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec', 'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'];
        $miesiac = $miesiace[(int) date('n')] . ' ' . date('Y'); ?>
      <div class="naglowek">
        <div><p class="nadtytul"><?= $h($miesiac) ?></p><h1>Przegląd</h1></div>
      </div>

      <section class="kafle">
        <div class="kafel"><span>Sklepy</span><b><?= count($dane) ?></b><small><?= count(array_filter($dane, fn($d) => !empty($d['dostepny']))) ?> aktywne</small></div>
        <div class="kafel"><span>Zamówienia</span><b><?= $sum['n'] ?></b><small><?= $zmiana($sum['n'], $sum['pn']) ?> vs <?= $sum['pn'] ?></small></div>
        <div class="kafel"><span>Obrót brutto</span><b><?= $h($zl0($sum['g'])) ?></b><small><?= $zmiana($sum['g'], $sum['pg']) ?> vs <?= $h($zl0($sum['pg'])) ?></small></div>
        <div class="kafel akcent"><span>Należne BuddyCorp</span><b><?= $h($zl0($sum['d'])) ?></b><small><?= $zmiana($sum['d'], $sum['pd']) ?> vs <?= $h($zl0($sum['pd'])) ?></small></div>
      </section>

      <h2 class="sekcja">Sklepy</h2>
      <section class="lista">
        <?php if (!$dane): ?><div class="pusto">Brak sklepów na serwerze.</div><?php endif; ?>
        <?php foreach ($dane as $d):
          $nz = (string) ($d['sprzedawca'] ?? ''); $nazwa = $nz !== '' && stripos($nz, 'UZUPE') === false ? $nz : $d['slug']; ?>
        <div class="sklep">
          <div class="sklep-kto">
            <span class="awatar"><?= $h(inicjaly($nazwa)) ?></span>
            <div>
              <b><?= $h($nazwa) ?></b>
              <a class="domena" href="https://<?= $h($d['domena']) ?>/shop/" target="_blank" rel="noopener"><?= $h($d['domena']) ?></a>
            </div>
          </div>
          <?php if (empty($d['dostepny'])): ?>
            <div class="sklep-blad"><?= ikona('blad') ?> Baza sklepu niedostępna</div>
          <?php else: $r = $d['rozl_teraz']; $w = $d['warunki']; ?>
          <dl class="sklep-liczby">
            <div><dt>Zamówienia</dt><dd><?= (int) $d['teraz']['orders'] ?></dd><small><?= $zmiana($d['teraz']['orders'], $d['poprz']['orders']) ?></small></div>
            <div><dt>Obrót</dt><dd><?= $h($zl0($d['teraz']['gross'])) ?></dd><small><?= $zmiana($d['teraz']['gross'], $d['poprz']['gross']) ?></small></div>
            <div title="Prowizja <?= $h($zl($r['prowizja'])) ?> · czynsz <?= $h($zl($r['czynsz'])) ?> · pobrane przez Stripe <?= $h($zl($r['pobrane'])) ?>">
              <dt>Do zapłaty</dt><dd><?= $h($zl0($r['do_zaplaty'])) ?></dd>
              <small><?= $h(rtrim(rtrim(number_format($w['rate'] * 100, 2, ',', ''), '0'), ',')) ?> % <?= $w['basis'] === 'towar' ? 'od towaru' : 'od brutto' ?><?= $w['ustalone'] ? '' : ' · domyślne' ?></small></div>
            <div><dt>Produkty</dt><dd><?= (int) $d['produkty'] ?></dd><small>ost. zam. <?= $h($d['ostatnie'] !== '' ? date('d.m', strtotime($d['ostatnie'])) : '—') ?></small></div>
          </dl>
          <?php endif; ?>
          <div class="sklep-akcje">
            <a class="pigulka" href="?p=klucze&amp;b=<?= $h($d['slug']) ?>" title="Klucze integracji"><?= ikona('klucze') ?><?= (int) ($d['klucze'] ?? 0) ?></a>
            <form method="post" action="?p=sso" target="_blank">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="b" value="<?= $h($d['slug']) ?>">
              <button class="glowny maly" title="Wejście bez hasła — link ważny 1 minutę, jednorazowy">Konsola <?= ikona('strzalka') ?></button>
            </form>
          </div>
          <div class="sklep-wersja"><?= $h(substr((string) $d['wersja'], 0, 15)) ?> · <?= $h(substr((string) $d['commit'], 0, 7)) ?></div>
        </div>
        <?php endforeach; ?>
      </section>
      <p class="przypis">Kwoty z płatnych zamówień bieżącego miesiąca (bez anulowanych), porównane z poprzednim. Prowizja i czynsz według warunków ustawionych w konsoli sklepu. Tylko odczyt.</p>
    <?php });
    exit;
}

$sklepy = wsb_boutiques($pdo);
$siege = (string) (wsb_siege_cfg()['boutique'] ?? 'buddycorp');
$slugi = array_values(array_unique(array_merge([$siege], array_column($sklepy, 'slug'))));

if ($p === 'sklepy') {
    if ($post && ($_POST['a'] ?? '') === 'dodaj') {
        try {
            wsb_boutique_enregistrer($pdo, strtolower(trim((string) $_POST['slug'])), trim((string) $_POST['nom']), strtolower(trim((string) $_POST['domaine'])));
            wsb_dziennik($pdo, wsb_tel_maska($kto), (string) $_POST['slug'], 'sklep dodany do rejestru');
            header('Location: ?p=sklepy', true, 303); exit;
        } catch (Throwable $e) { $err = $e->getMessage(); }
    }
    strona('Sklepy', function () use ($h, $csrf, $sklepy, $err) { ?>
      <div class="naglowek"><div><p class="nadtytul">Rejestr centrali</p><h1>Sklepy</h1></div></div>
      <?= bandeau('', $err) ?>
      <section class="lista">
        <?php if (!$sklepy): ?><div class="pusto">Rejestr jest pusty.</div><?php endif; ?>
        <?php foreach ($sklepy as $s): ?>
        <a class="wiersz-lista" href="?p=klucze&amp;b=<?= $h($s['slug']) ?>">
          <span class="awatar"><?= $h(inicjaly((string) ($s['nom'] ?: $s['slug']))) ?></span>
          <span class="rozciag"><b><?= $h($s['nom']) ?></b><small><?= $h($s['slug']) ?><?= $s['domaine'] ? ' · ' . $h($s['domaine']) : '' ?></small></span>
          <span class="szary">od <?= $h(date('d.m.Y', strtotime((string) $s['creee']))) ?></span>
          <?= ikona('szewron') ?>
        </a>
        <?php endforeach; ?>
      </section>

      <h2 class="sekcja">Dodaj sklep spoza serwera</h2>
      <form method="post" class="lista formularz">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="dodaj">
        <label class="wiersz"><span>Identyfikator</span><input name="slug" required pattern="[a-z0-9][a-z0-9-]{1,39}" placeholder="moj-sklep"></label>
        <label class="wiersz"><span>Nazwa</span><input name="nom" required placeholder="Mój sklep"></label>
        <label class="wiersz"><span>Domena</span><input name="domaine" placeholder="sklep.pl"></label>
        <div class="stopka-formularza"><span class="szary">Sklepy z serwera dodaje <code>wsm creer</code>.</span><button class="glowny">Dodaj</button></div>
      </form>
    <?php });
    exit;
}

if ($p === 'dziennik') {
    $rows = $pdo->query("SELECT kiedy, kto, boutique, akcja, szczegoly FROM wsb_dziennik ORDER BY id DESC LIMIT 300")->fetchAll();
    strona('Dziennik', function () use ($h, $rows) {
        $dzien = ''; ?>
      <div class="naglowek"><div><p class="nadtytul">Ostatnie 300 zdarzeń</p><h1>Dziennik</h1></div></div>
      <?php if (!$rows): ?><section class="lista"><div class="pusto">Brak zdarzeń.</div></section><?php endif; ?>
      <?php foreach ($rows as $r):
        $d = substr((string) $r['kiedy'], 0, 10);
        if ($d !== $dzien): if ($dzien !== '') echo '</section>'; $dzien = $d; ?>
          <h2 class="sekcja"><?= $h(date('d.m.Y', strtotime($d))) ?></h2><section class="lista">
        <?php endif; ?>
        <div class="zdarzenie">
          <span class="godzina"><?= $h(substr((string) $r['kiedy'], 11, 5)) ?></span>
          <span class="rozciag"><b><?= $h($r['akcja']) ?></b><?php if ((string) $r['szczegoly'] !== ''): ?> <code><?= $h($r['szczegoly']) ?></code><?php endif; ?>
            <small><?= $h($r['kto']) ?></small></span>
          <span class="etykieta"><?= $h($r['boutique']) ?></span>
        </div>
      <?php endforeach; if ($dzien !== '') echo '</section>'; ?>
    <?php });
    exit;
}

// ---- Klucze (défaut) ---------------------------------------------------------
$b = (string) ($_GET['b'] ?? $slugi[0]);
if (!in_array($b, $slugi, true)) $b = $slugi[0];
$pola = wsm_settings_klucze_fields();
if ($post && ($_POST['a'] ?? '') === 'zapisz') {
    $zmiany = 0;
    $kto_m = wsb_tel_maska($kto);
    foreach ($pola as $k => $d) {
        $f = str_replace('.', '__', $k);
        if (!empty($_POST[$f . '__usun'])) { wsb_zapisz($pdo, $b, $k, '', $kto_m); $zmiany++; continue; }
        $v = trim((string) ($_POST[$f] ?? ''));
        if ($v === '' || str_starts_with($v, '•')) continue;            // inchangé
        if (str_starts_with($d[4], 'select:') && !in_array($v, explode('|', substr($d[4], 7)), true)) continue;
        if ($d[4] === 'pem' && !str_contains($v, '-----BEGIN')) { $err = $d[1] . ' : to nie jest klucz PEM.'; continue; }
        wsb_zapisz($pdo, $b, $k, $v, $kto_m); $zmiany++;
    }
    if (!$err) { header('Location: ?p=klucze&b=' . rawurlencode($b) . '&ok=' . $zmiany, true, 303); exit; }
}
$wart = wsb_klucze($b, true);
$meta = wsb_klucze_meta($pdo, $b);
if (isset($_GET['ok'])) $msg = 'Zapisano zmian: ' . (int) $_GET['ok'] . '.';

strona('Klucze integracji', function () use ($h, $csrf, $b, $slugi, $pola, $wart, $meta, $msg, $err, $siege) {
    $razem = count($pola); $ust = count(array_intersect_key($wart, $pola)); ?>
  <div class="naglowek">
    <div><p class="nadtytul"><?= $ust ?> z <?= $razem ?> ustawionych</p><h1>Klucze integracji</h1></div>
  </div>
  <nav class="segment" aria-label="Sklep">
    <?php foreach ($slugi as $s): ?>
      <a href="?p=klucze&amp;b=<?= $h($s) ?>" class="<?= $s === $b ? 'on' : '' ?>"><?= $h($s === $siege ? 'Siedziba' : $s) ?></a>
    <?php endforeach; ?>
  </nav>
  <?= bandeau($msg, $err) ?>
  <form method="post" autocomplete="off" class="klucze">
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="zapisz">
    <?php foreach (WSM_SETTINGS_KLUCZE as $g => $tytul):
      $grupa = array_filter($pola, fn($d) => $d[0] === $g); if (!$grupa) continue;
      $n = count(array_intersect_key($wart, $grupa)); ?>
      <details class="lista grupa-kluczy"<?= $n ? ' open' : '' ?>>
        <summary>
          <span class="rozciag"><b><?= $h($tytul) ?></b></span>
          <span class="licznik <?= $n === count($grupa) ? 'pelny' : ($n ? 'czesc' : '') ?>"><?= $n ?>/<?= count($grupa) ?></span>
          <?= ikona('szewron') ?>
        </summary>
        <?php foreach ($grupa as $k => $d):
          [$grp, $label, $path, $env, $type, $help] = $d;
          $f = str_replace('.', '__', $k); $jest = isset($wart[$k]); $m = $meta[$k] ?? null; ?>
        <div class="pole">
          <div class="pole-gora">
            <label for="<?= $h($f) ?>"><?= $h($label) ?></label>
            <span class="stan <?= $jest ? 'tak' : 'nie' ?>"<?= $m ? ' title="' . $h(substr($m['zmieniono'], 0, 16) . ' · ' . $m['przez']) . '"' : '' ?>><i></i><?= $jest ? 'Ustawiony' : 'Brak' ?></span>
          </div>
          <?php if (str_starts_with($type, 'select:')): ?>
            <select id="<?= $h($f) ?>" name="<?= $h($f) ?>"><option value="">— bez zmian —</option>
            <?php foreach (explode('|', substr($type, 7)) as $o) echo '<option' . (($wart[$k] ?? null) === $o ? ' selected' : '') . '>' . $h($o) . '</option>'; ?>
            </select>
          <?php elseif ($type === 'pem'): ?>
            <textarea id="<?= $h($f) ?>" name="<?= $h($f) ?>" rows="3" placeholder="<?= $jest ? 'Ustawiony — wklej nowy, aby zmienić' : '-----BEGIN PUBLIC KEY-----' ?>"></textarea>
          <?php elseif ($type === 'secret'): ?>
            <input id="<?= $h($f) ?>" name="<?= $h($f) ?>" type="password" autocomplete="new-password" placeholder="<?= $jest ? '••••••••••••' : 'Wklej klucz' ?>">
          <?php else: ?>
            <input id="<?= $h($f) ?>" name="<?= $h($f) ?>" placeholder="<?= $h($jest ? $wart[$k] : '—') ?>">
          <?php endif; ?>
          <div class="pole-dol">
            <code><?= $h($k) ?></code>
            <?php if ($help): ?><span class="pomoc"><?= $h($help) ?></span><?php endif; ?>
            <?php if ($jest): ?><label class="usun"><input type="checkbox" name="<?= $h($f) ?>__usun" value="1"> Usuń</label><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </details>
    <?php endforeach; ?>
    <div class="pasek-zapisu">
      <span class="szary">Puste pole = bez zmian · każda wartość szyfrowana kluczem sklepu</span>
      <button class="glowny">Zapisz · <?= $h($b === $siege ? 'Siedziba' : $b) ?></button>
    </div>
  </form>
<?php });

// ---- Gabarit -----------------------------------------------------------------
function strona(string $tytul, callable $tresc): void {
    global $kto, $csrf, $p;
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $v = (string) @filemtime(__DIR__ . '/siege.css'); ?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#f5f6f9"><title><?= $h($tytul) ?> · BuddyCorp</title>
<link rel="stylesheet" href="tfb.css"><link rel="stylesheet" href="siege.css?v=<?= $h($v) ?>">
</head><body class="<?= $kto === null ? 'bez-menu' : '' ?>">
<?php if ($kto !== null): ?>
<aside class="menu">
  <div class="marka"><span class="logo">B</span><span>BuddyCorp<small>Superadmin</small></span></div>
  <nav>
    <?php foreach (['przeglad' => 'Przegląd', 'klucze' => 'Klucze', 'sklepy' => 'Sklepy', 'dziennik' => 'Dziennik'] as $k => $l): ?>
      <a href="?p=<?= $k ?>" class="<?= $p === $k ? 'on' : '' ?>"><?= ikona($k) ?><span><?= $l ?></span></a>
    <?php endforeach; ?>
  </nav>
  <div class="ja">
    <span class="ja-tel"><?= $h(wsb_tel_maska($kto)) ?></span>
    <form method="post" action="?p=wyloguj"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><button class="ikonowy" title="Wyloguj"><?= ikona('wyjscie') ?><span>Wyloguj</span></button></form>
  </div>
</aside>
<?php endif; ?>
<main><?php $tresc(); ?></main></body></html>
<?php }
