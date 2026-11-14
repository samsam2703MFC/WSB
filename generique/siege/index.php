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
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
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
<style>
  :root{--ink:#14161a;--mut:#5d6470;--line:#e2e5ea;--bg:#f5f6f8;--acc:#1f4fd8;--ok:#1d7a46;--err:#b42318}
  *{box-sizing:border-box} body{margin:0;font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--ink);background:var(--bg)}
  header{display:flex;gap:18px;align-items:center;padding:12px 24px;background:#fff;border-bottom:1px solid var(--line)}
  header b{margin-right:auto} header a{color:var(--ink);text-decoration:none;padding:6px 10px;border-radius:8px} header a.on{background:var(--bg);font-weight:600}
  main{max-width:1240px;margin:24px auto;padding:0 16px}
  .kafle{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:18px} .kafel{flex:1 1 200px;border:1px solid var(--line);border-radius:10px;padding:12px 14px}
  .kafel span{display:block;color:var(--mut);font-size:13px} .kafel b{display:block;font-size:22px;margin:2px 0} .kafel small{color:var(--mut);font-size:12px}
  table.przeglad td small{display:block;color:var(--mut);font-size:12px} table.przeglad .l{text-align:right;white-space:nowrap}
  button.maly{margin:0;padding:6px 12px;font-size:13px;white-space:nowrap}
  .karta{background:#fff;border:1px solid var(--line);border-radius:12px;padding:24px} .waska{max-width:420px;margin:60px auto}
  h1{font-size:22px;margin:0 0 16px} h2{font-size:17px;margin:24px 0 8px}
  label{display:block;font-weight:600;margin:10px 0 4px} input,select,textarea{width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font:inherit}
  button{margin-top:14px;padding:10px 16px;border:0;border-radius:8px;background:var(--acc);color:#fff;font:inherit;font-weight:600;cursor:pointer}
  .w-linii{display:flex;gap:8px;align-items:end;flex-wrap:wrap} .w-linii input,.w-linii select{width:auto}
  fieldset{border:1px solid var(--line);border-radius:10px;margin:16px 0;padding:8px 16px 14px} legend{font-weight:700;padding:0 6px}
  .pole{padding:8px 0;border-bottom:1px dashed var(--line)} .pole:last-child{border:0} .pole code{font-weight:400;color:var(--mut);font-size:12px}
  .stan{display:inline-block;margin-top:4px;font-size:13px;padding:2px 10px;border-radius:6px} .stan.tak{background:#e7f5ec;color:var(--ok)} .stan.nie{background:#f1f2f4;color:var(--mut)}
  .usun{display:inline-flex;gap:6px;font-weight:400;margin-left:12px} .usun input{width:auto}
  small,.pomoc{display:block;color:var(--mut);font-size:13px} .ok{color:var(--ok)} .blad{color:var(--err)}
  table{width:100%;border-collapse:collapse;font-size:14px} th,td{text-align:left;padding:7px 8px;border-bottom:1px solid var(--line)}
</style></head><body>
<?php if ($kto !== null): ?>
<header><b>BuddyCorp · superadmin</b>
  <a href="?p=przeglad" class="<?= $p === 'przeglad' ? 'on' : '' ?>">Przegląd</a>
  <a href="?p=klucze" class="<?= $p === 'klucze' ? 'on' : '' ?>">Klucze</a>
  <a href="?p=sklepy" class="<?= $p === 'sklepy' ? 'on' : '' ?>">Sklepy</a>
  <a href="?p=dziennik" class="<?= $p === 'dziennik' ? 'on' : '' ?>">Dziennik</a>
  <form method="post" action="?p=wyloguj" style="margin:0"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><button style="margin:0;background:#fff;color:var(--ink);border:1px solid var(--line)">Wyloguj</button></form>
</header>
<?php endif; ?>
<main><?php $tresc(); ?></main></body></html>
<?php }
