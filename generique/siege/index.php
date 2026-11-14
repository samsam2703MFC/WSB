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
      <div class="karta waska">
        <h1>BuddyCorp — superadmin</h1>
        <?php if ($msg) echo '<p class="ok">' . $h($msg) . '</p>'; if ($err) echo '<p class="blad">' . $h($err) . '</p>'; ?>
        <?php if (!$etap2): ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="etap1">
          <label>Numer telefonu<input name="tel" type="tel" required placeholder="+48 600 000 000"></label>
          <label>Kod dnia<input name="kod" inputmode="numeric" required maxlength="8"></label>
          <button>Wyślij kod SMS</button>
        </form>
        <?php else: ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="etap2">
          <label>Kod z SMS-a<input name="sms" inputmode="numeric" required maxlength="10" autofocus></label>
          <button>Zaloguj (12 h)</button>
        </form>
        <p><a href="?od-nowa=1">Zacznij od nowa</a></p>
        <?php endif; ?>
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
        $sum = ['n' => 0, 'g' => 0, 'pn' => 0, 'pg' => 0, 'd' => 0, 'pd' => 0];
        foreach ($dane as $d) if (!empty($d['dostepny'])) {
            $sum['n'] += $d['teraz']['orders']; $sum['g'] += $d['teraz']['gross']; $sum['d'] += $d['rozl_teraz']['do_zaplaty'];
            $sum['pn'] += $d['poprz']['orders']; $sum['pg'] += $d['poprz']['gross']; $sum['pd'] += $d['rozl_poprz']['do_zaplaty'];
        }
        $mies = fn(string $ym) => $ym; ?>
      <div class="karta">
        <h1>Przegląd sieci</h1>
        <div class="kafle">
          <div class="kafel"><span>Sklepy</span><b><?= count($dane) ?></b></div>
          <div class="kafel"><span>Zamówienia · <?= $h(date('m.Y')) ?></span><b><?= $sum['n'] ?></b><small>poprz. miesiąc: <?= $sum['pn'] ?></small></div>
          <div class="kafel"><span>Obrót brutto · <?= $h(date('m.Y')) ?></span><b><?= $h($zl($sum['g'])) ?></b><small>poprz. miesiąc: <?= $h($zl($sum['pg'])) ?></small></div>
          <div class="kafel"><span>Należne BuddyCorp · <?= $h(date('m.Y')) ?></span><b><?= $h($zl($sum['d'])) ?></b><small>poprz. miesiąc: <?= $h($zl($sum['pd'])) ?></small></div>
        </div>
        <table class="przeglad">
          <tr><th>Sklep</th><th>Wersja</th><th class="l">Zamówienia</th><th class="l">Obrót brutto</th><th class="l">Prowizja · czynsz</th><th class="l">Do zapłaty</th><th>Ostatnie zam.</th><th class="l">Produkty</th><th class="l">Klucze</th><th></th></tr>
        <?php if (!$dane): ?><tr><td colspan="10">Brak sklepów na serwerze.</td></tr><?php endif; ?>
        <?php foreach ($dane as $d): ?>
          <tr>
            <td><b><?php $nz = (string) ($d['sprzedawca'] ?? ''); echo $h($nz !== '' && stripos($nz, 'UZUPE') === false ? $nz : $d['slug']); ?></b>
                <small><code><?= $h($d['slug']) ?></code> · <a href="https://<?= $h($d['domena']) ?>/shop/" target="_blank" rel="noopener"><?= $h($d['domena']) ?> ↗</a></small></td>
            <td><small><?= $h(substr($d['wersja'], 0, 15)) ?></small><small><?= $h(substr($d['commit'], 0, 7)) ?></small></td>
          <?php if (empty($d['dostepny'])): ?>
            <td colspan="7" class="blad">Baza sklepu niedostępna — dane nieczytelne.</td>
          <?php else: $r = $d['rozl_teraz']; $w = $d['warunki']; ?>
            <td class="l"><?= (int) $d['teraz']['orders'] ?><small>poprz. <?= (int) $d['poprz']['orders'] ?></small></td>
            <td class="l"><?= $h($zl($d['teraz']['gross'])) ?><small>poprz. <?= $h($zl($d['poprz']['gross'])) ?></small></td>
            <td class="l"><?= $h($zl($r['prowizja'])) ?> · <?= $h($zl($r['czynsz'])) ?>
                <small><?= $h(rtrim(rtrim(number_format($w['rate'] * 100, 2, ',', ''), '0'), ',')) ?> % <?= $w['basis'] === 'towar' ? 'od towaru' : 'od brutto' ?><?= $w['ustalone'] ? '' : ' · domyślne' ?></small></td>
            <td class="l"><?= $h($zl($r['do_zaplaty'])) ?><small>pobrane przez Stripe: <?= $h($zl($r['pobrane'])) ?></small></td>
            <td><?= $h($d['ostatnie'] !== '' ? substr($d['ostatnie'], 0, 10) : '—') ?></td>
            <td class="l"><?= (int) $d['produkty'] ?></td>
            <td class="l"><a href="?p=klucze&amp;b=<?= $h($d['slug']) ?>"><?= (int) $d['klucze'] ?> ›</a></td>
          <?php endif; ?>
            <td>
              <form method="post" action="?p=sso" target="_blank" style="margin:0">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="b" value="<?= $h($d['slug']) ?>">
                <button class="maly" title="Wejście bez hasła — link ważny 1 minutę, jednorazowy">Konsola ↗</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </table>
        <p class="pomoc">Kwoty w złotych, z płatnych zamówień (bez anulowanych) bieżącego miesiąca; prowizja i czynsz według warunków ustawionych w konsoli sklepu (Superadmin). Tylko odczyt.</p>
      </div>
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
      <div class="karta">
        <h1>Sklepy</h1>
        <?php if ($err) echo '<p class="blad">' . $h($err) . '</p>'; ?>
        <table><tr><th>Sklep</th><th>Nazwa</th><th>Domena</th><th>Od</th><th></th></tr>
        <?php foreach ($sklepy as $s): ?>
          <tr><td><code><?= $h($s['slug']) ?></code></td><td><?= $h($s['nom']) ?></td><td><?= $h($s['domaine']) ?></td>
              <td><?= $h(substr($s['creee'], 0, 10)) ?></td><td><a href="?p=klucze&amp;b=<?= $h($s['slug']) ?>">Klucze →</a></td></tr>
        <?php endforeach; ?>
        </table>
        <h2>Dodaj do rejestru</h2>
        <p class="pomoc">Zwykle robi to <code>wsm creer</code>. Tu — dla sklepu spoza serwera.</p>
        <form method="post" class="w-linii">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="dodaj">
          <input name="slug" required pattern="[a-z0-9][a-z0-9-]{1,39}" placeholder="slug">
          <input name="nom" required placeholder="Nazwa"><input name="domaine" placeholder="domena.pl">
          <button>Dodaj</button>
        </form>
      </div>
    <?php });
    exit;
}

if ($p === 'dziennik') {
    $rows = $pdo->query("SELECT kiedy, kto, boutique, akcja, szczegoly FROM wsb_dziennik ORDER BY id DESC LIMIT 300")->fetchAll();
    strona('Dziennik', function () use ($h, $rows) { ?>
      <div class="karta"><h1>Dziennik</h1>
        <table><tr><th>Kiedy</th><th>Kto</th><th>Sklep</th><th>Co</th><th></th></tr>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= $h($r['kiedy']) ?></td><td><?= $h($r['kto']) ?></td><td><?= $h($r['boutique']) ?></td><td><?= $h($r['akcja']) ?></td><td><code><?= $h($r['szczegoly']) ?></code></td></tr>
        <?php endforeach; ?>
        </table></div>
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

strona('Klucze integracji', function () use ($h, $csrf, $b, $slugi, $pola, $wart, $meta, $msg, $err, $siege) { ?>
  <div class="karta">
    <h1>Klucze integracji</h1>
    <form method="get" class="w-linii"><input type="hidden" name="p" value="klucze">
      <label>Sklep <select name="b" onchange="this.form.submit()">
      <?php foreach ($slugi as $s) echo '<option value="' . $h($s) . '"' . ($s === $b ? ' selected' : '') . '>' . $h($s) . ($s === $siege ? ' (siedziba)' : '') . '</option>'; ?>
      </select></label><noscript><button>Pokaż</button></noscript></form>
    <?php if ($msg) echo '<p class="ok">' . $h($msg) . '</p>'; if ($err) echo '<p class="blad">' . $h($err) . '</p>'; ?>
    <p class="pomoc">Puste pole = bez zmian. Sekrety nie są nigdy wyświetlane. Każda wartość jest szyfrowana kluczem tego sklepu.</p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="zapisz">
      <?php foreach (WSM_SETTINGS_KLUCZE as $g => $tytul):
        $grupa = array_filter($pola, fn($d) => $d[0] === $g); if (!$grupa) continue; ?>
        <fieldset><legend><?= $h($tytul) ?></legend>
        <?php foreach ($grupa as $k => $d):
          [$grp, $label, $path, $env, $type, $help] = $d;
          $f = str_replace('.', '__', $k); $jest = isset($wart[$k]); $m = $meta[$k] ?? null; ?>
          <div class="pole">
            <label for="<?= $h($f) ?>"><?= $h($label) ?> <code><?= $h($k) ?></code></label>
            <?php if (str_starts_with($type, 'select:')): ?>
              <select id="<?= $h($f) ?>" name="<?= $h($f) ?>"><option value="">— bez zmian —</option>
              <?php foreach (explode('|', substr($type, 7)) as $o) echo '<option' . (($wart[$k] ?? null) === $o ? ' selected' : '') . '>' . $h($o) . '</option>'; ?>
              </select>
            <?php elseif ($type === 'pem'): ?>
              <textarea id="<?= $h($f) ?>" name="<?= $h($f) ?>" rows="3" placeholder="<?= $jest ? 'ustawiony — wklej nowy, aby zmienić' : '-----BEGIN PUBLIC KEY-----' ?>"></textarea>
            <?php elseif ($type === 'secret'): ?>
              <input id="<?= $h($f) ?>" name="<?= $h($f) ?>" type="password" autocomplete="new-password" placeholder="<?= $jest ? '•••••••• ustawiony' : 'brak' ?>">
            <?php else: ?>
              <input id="<?= $h($f) ?>" name="<?= $h($f) ?>" placeholder="<?= $h($jest ? $wart[$k] : 'brak') ?>">
            <?php endif; ?>
            <span class="stan <?= $jest ? 'tak' : 'nie' ?>"><?= $jest ? 'ustawiony' . ($m ? ' ' . $h(substr($m['zmieniono'], 0, 16)) . ' · ' . $h($m['przez']) : '') : 'brak' ?></span>
            <?php if ($jest): ?><label class="usun"><input type="checkbox" name="<?= $h($f) ?>__usun" value="1"> usuń</label><?php endif; ?>
            <?php if ($help): ?><small><?= $h($help) ?></small><?php endif; ?>
          </div>
        <?php endforeach; ?>
        </fieldset>
      <?php endforeach; ?>
      <button>Zapisz klucze sklepu <?= $h($b) ?></button>
    </form>
  </div>
<?php });

// ---- Gabarit -----------------------------------------------------------------
function strona(string $tytul, callable $tresc): void {
    global $kto, $csrf, $p;
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); ?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?= $h($tytul) ?> · BuddyCorp</title>
<link rel="stylesheet" href="tfb.css">
<style>
  /* BuddyCorp sur le design system TFB (tfb.css : jetons, polices, base). */
  body{font:var(--type-body);background:var(--surface-page)}
  header{position:sticky;top:0;z-index:5;display:flex;gap:6px;align-items:center;height:var(--topbar-height);padding:0 var(--space-6);background:var(--gradient-ink);color:var(--text-inverse);box-shadow:var(--shadow-md)}
  header .marque{display:flex;align-items:center;gap:10px;margin-right:auto;font:var(--weight-bold) var(--text-md)/1 var(--font-display);letter-spacing:var(--tracking-tight)}
  header .marque i{width:26px;height:26px;border-radius:50%;background:var(--gradient-plum);box-shadow:var(--shadow-brand)}
  header .marque small{display:inline;color:var(--text-inverse-secondary);font:var(--type-caption);letter-spacing:var(--tracking-caps);text-transform:uppercase}
  header a{color:var(--text-inverse-secondary);padding:8px 12px;border-radius:var(--radius-md);font:var(--type-label);transition:var(--transition-control)}
  header a:hover{color:var(--text-inverse);background:var(--alpha-white-08);text-decoration:none}
  header a.on{color:var(--text-inverse);background:var(--alpha-white-16)}
  header button.wyloguj{margin:0 0 0 8px;background:transparent;color:var(--text-inverse);border:1px solid var(--border-inverse);box-shadow:none}
  header button.wyloguj:hover{background:var(--alpha-white-08)}
  main{max-width:1280px;margin:var(--space-8) auto;padding:0 var(--space-6)}
  .karta{background:var(--surface-card);border:1px solid var(--border-subtle);border-radius:var(--radius-card);padding:var(--space-6) var(--space-8);box-shadow:var(--shadow-sm)}
  .waska{max-width:440px;margin:12vh auto}
  .waska h1::before{content:"";display:block;width:44px;height:44px;margin-bottom:var(--space-4);border-radius:50%;background:var(--gradient-plum);box-shadow:var(--shadow-brand)}
  h1{font:var(--type-heading-3);letter-spacing:var(--tracking-tight);margin:0 0 var(--space-5)}
  h2{font:var(--type-heading-4);margin:var(--space-8) 0 var(--space-3)}
  label{display:block;font:var(--type-label);color:var(--text-secondary);margin:var(--space-4) 0 var(--space-1)}
  input,select,textarea{width:100%;padding:10px 12px;border:1px solid var(--border-default);border-radius:var(--radius-control);background:var(--surface-card);font:var(--type-body);transition:var(--transition-control)}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--border-brand);box-shadow:var(--shadow-focus)}
  button{margin-top:var(--space-5);padding:10px 18px;border:0;border-radius:var(--radius-control);background:var(--brand);color:var(--brand-on);font:var(--weight-semibold) var(--text-base)/1.2 var(--font-sans);cursor:pointer;box-shadow:var(--shadow-xs);transition:var(--transition-control)}
  button:hover{background:var(--brand-hover)} button:active{background:var(--brand-press)}
  button.maly{margin:0;padding:7px 12px;font-size:var(--text-sm);white-space:nowrap}
  .w-linii{display:flex;gap:var(--space-2);align-items:end;flex-wrap:wrap} .w-linii input,.w-linii select{width:auto}
  .w-linii label{margin-top:0}
  .kafle{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:var(--space-4);margin-bottom:var(--space-6)}
  .kafel{background:var(--surface-card);border:1px solid var(--border-subtle);border-radius:var(--radius-card);padding:var(--space-4) var(--space-5);box-shadow:var(--shadow-xs)}
  .kafel span{display:block;font:var(--weight-semibold) var(--text-xs)/1.2 var(--font-sans);letter-spacing:var(--tracking-caps);text-transform:uppercase;color:var(--text-brand)}
  .kafel b{display:block;font:var(--type-metric);margin:var(--space-2) 0 var(--space-1);font-variant-numeric:tabular-nums}
  .kafel small{color:var(--text-secondary);font:var(--type-caption)}
  table{width:100%;border-collapse:separate;border-spacing:0;font:var(--type-body-sm)}
  th{font:var(--weight-semibold) var(--text-2xs)/1.2 var(--font-sans);letter-spacing:var(--tracking-caps);text-transform:uppercase;color:var(--text-secondary);background:var(--slate-25)}
  th,td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--border-subtle);vertical-align:top}
  tr:hover td{background:var(--surface-hover)}
  table.przeglad td small{display:block;color:var(--text-secondary);font:var(--type-caption)}
  table.przeglad .l{text-align:right;white-space:nowrap;font-family:var(--font-mono);font-variant-numeric:tabular-nums}
  table.przeglad td b{font:var(--weight-semibold) var(--text-base)/1.3 var(--font-display)}
  fieldset{border:1px solid var(--border-subtle);border-radius:var(--radius-card);margin:var(--space-5) 0;padding:var(--space-2) var(--space-5) var(--space-4)}
  legend{font:var(--weight-semibold) var(--text-md)/1.2 var(--font-display);padding:0 var(--space-2);color:var(--text-primary)}
  .pole{padding:var(--space-3) 0;border-bottom:1px dashed var(--border-default)} .pole:last-child{border:0}
  .pole code{font:var(--type-data);color:var(--text-tertiary)}
  .stan{display:inline-block;margin-top:var(--space-1);font:var(--type-caption);padding:3px 10px;border-radius:var(--radius-sm)}
  .stan.tak{background:var(--surface-success-subtle);color:var(--text-success)} .stan.nie{background:var(--surface-sunken);color:var(--text-secondary)}
  .usun{display:inline-flex;gap:6px;font:var(--type-caption);color:var(--text-danger);margin-left:var(--space-3)} .usun input{width:auto}
  small,.pomoc{display:block;color:var(--text-secondary);font:var(--type-body-sm)} .pomoc{margin:var(--space-3) 0}
  .ok{color:var(--text-success)} .blad{color:var(--text-danger)}
  code{font:var(--type-data)}
  @media (max-width:760px){ header{flex-wrap:wrap;height:auto;padding:var(--space-2) var(--space-4)} .karta{padding:var(--space-5)} main{padding:0 var(--space-3)} table.przeglad{display:block;overflow-x:auto} }
</style></head><body>
<?php if ($kto !== null): ?>
<header><span class="marque"><i></i>BuddyCorp <small>superadmin</small></span>
  <a href="?p=przeglad" class="<?= $p === 'przeglad' ? 'on' : '' ?>">Przegląd</a>
  <a href="?p=klucze" class="<?= $p === 'klucze' ? 'on' : '' ?>">Klucze</a>
  <a href="?p=sklepy" class="<?= $p === 'sklepy' ? 'on' : '' ?>">Sklepy</a>
  <a href="?p=dziennik" class="<?= $p === 'dziennik' ? 'on' : '' ?>">Dziennik</a>
  <form method="post" action="?p=wyloguj" style="margin:0"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><button class="wyloguj">Wyloguj</button></form>
</header>
<?php endif; ?>
<main><?php $tresc(); ?></main></body></html>
<?php }
