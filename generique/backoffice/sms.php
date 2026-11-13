<?php
// ============================================================================
//  sms.php — l'écran des campagnes SMS (SMSAPI, comme le CRM).
//
//  Un SMS se paie au morceau : l'écran montre, AVANT l'envoi, le texte exact
//  qui part (prénom, code, lien, ligne de désinscription), sa longueur, le
//  nombre de SMS facturés par message et le coût estimé de la campagne.
//  Les destinataires sont ceux qui ont coché la case SMS — et eux seuls.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/marketing_ai.php';
wsm_mkt_ensure($pdo);

$flash = ''; $kind = 'ok'; $warianty = [];
$acteur = (string) ($me['nom'] ?? '');
$id = (int) ($_GET['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Twoja rola może tylko przeglądać kampanie SMS.'; $kind = 'err';
    } elseif (isset($_POST['nowa'])) {
        [$nid, $m] = wsm_sms_camp_save($pdo, ['nazwa' => 'SMS ' . date('d.m'), 'segment' => 'wszyscy', 'ascii' => 1,
            'tresc' => wsm_brand_name() . ': {imie}, '], $acteur);
        header('Location: sms.php?id=' . $nid, true, 303); exit;
    } elseif (isset($_POST['pompa'])) {
        $r = wsm_mkt_pump($pdo, 0, 30, 10.0);
        $flash = 'Wysłano teraz SMS-ów: ' . $r['sms'] . '.' . ($r['uwagi'] ? ' ' . implode(' ', $r['uwagi']) : '');
        $kind = $r['uwagi'] ? 'warn' : 'ok';
    } elseif ($id > 0) {
        $c = wsm_sms_camp_get($pdo, $id);
        if (!$c) { $flash = 'Nie znaleziono kampanii.'; $kind = 'err'; }
        else {
            $szkic = (string) $c['status'] === 'szkic';
            if ($szkic && !isset($_POST['probka_tylko'])) {
                $in = ['id' => $id, 'nazwa' => (string) ($_POST['nazwa'] ?? $c['nazwa']), 'segment' => (string) ($_POST['segment'] ?? $c['segment']),
                       'kod' => (string) ($_POST['kod'] ?? $c['kod']), 'ascii' => !empty($_POST['ascii']),
                       'tresc' => isset($_POST['uzyj']) ? (string) $_POST['uzyj'] : (string) ($_POST['tresc'] ?? $c['tresc']),
                       'tresc_uk' => (string) ($_POST['tresc_uk'] ?? ($c['tlumaczenia']['uk'] ?? '')),
                       'tresc_en' => (string) ($_POST['tresc_en'] ?? ($c['tlumaczenia']['en'] ?? ''))];
                [$ok, $m] = wsm_sms_camp_save($pdo, $in, $acteur);
                $flash = $m; $kind = $ok ? 'ok' : 'err';
                $c = wsm_sms_camp_get($pdo, $id);
            }
            if (isset($_POST['ai_sms']) && $szkic) {
                [$warianty, $err] = wsm_ai_sms($pdo, (string) ($_POST['ai_brief'] ?? ''), (string) $c['kod'], !empty($c['ascii']));
                $flash = $err ?? 'Trzy propozycje poniżej — wybierz jedną („Użyj”), potem popraw.';
                $kind = $err ? 'err' : 'ok';
            } elseif (isset($_POST['ai_tlumacz']) && $szkic && in_array($_POST['ai_tlumacz'], ['uk', 'en'], true)) {
                $l = (string) $_POST['ai_tlumacz'];
                [$t, $err] = wsm_ai_translate_sms((string) $c['tresc'], $l, !empty($c['ascii']));
                if ($t !== '') {
                    wsm_sms_camp_save($pdo, ['id' => $id, 'nazwa' => $c['nazwa'], 'segment' => $c['segment'], 'kod' => $c['kod'],
                        'ascii' => (int) $c['ascii'], 'tresc' => $c['tresc'], 'tresc_uk' => $l === 'uk' ? $t : ($c['tlumaczenia']['uk'] ?? ''),
                        'tresc_en' => $l === 'en' ? $t : ($c['tlumaczenia']['en'] ?? '')], $acteur);
                    $flash = 'Przetłumaczono (' . $l . ').';
                } else { $flash = 'AI: ' . $err; $kind = 'err'; }
            } elseif (isset($_POST['probka']) || isset($_POST['probka_tylko'])) {
                [$ok, $m] = wsm_sms_camp_test($pdo, $id, (string) ($_POST['numer'] ?? ''), $acteur);
                $flash = $m; $kind = $ok ? 'ok' : 'err';
            } elseif (isset($_POST['wyslij']) && $szkic) {
                if (!wsm_mkt_decide($me)) { $flash = 'Wysyłkę do wszystkich zatwierdza administrator: przygotuj kampanię i próbkę, a administrator kliknie „Wyślij”.'; $kind = 'err'; }
                elseif (!wsm_mkt_adres_ok()) { $flash = 'Najpierw ustaw „Publiczny adres sklepu” (Integracje → Sklep): od niego zależą linki w wiadomościach, wypisania i raporty doręczeń.'; $kind = 'err'; }
                elseif (empty($_POST['potwierdzam'])) { $flash = 'Zaznacz, że próbka została sprawdzona i koszt jest zaakceptowany.'; $kind = 'err'; }
                else { [$n, $m] = wsm_sms_camp_send($pdo, $id, $acteur); $flash = $m; $kind = $n > 0 ? 'ok' : 'err'; }
            }
        }
    }
    // Les propositions de l'IA s'affichent dans la réponse ; le reste revient
    // par une redirection, pour qu'un rafraîchissement ne renvoie rien.
    if (!$warianty && $flash !== '' && $id > 0) {
        header('Location: sms.php?id=' . $id . '&ok=' . rawurlencode($flash) . '&k=' . $kind, true, 303); exit;
    }
}
if (isset($_GET['ok'])) { $flash = (string) $_GET['ok']; $kind = in_array($_GET['k'] ?? '', ['ok', 'err', 'warn'], true) ? (string) $_GET['k'] : 'ok'; }

$pompe = wsm_mkt_pump($pdo, 0, 5, 3.0);
$kpi = wsm_mkt_kpis($pdo);
$cfg = wsm_sms_cfg();
$c = $id > 0 ? wsm_sms_camp_get($pdo, $id) : null;
$zl = fn(int $g): string => number_format($g / 100, 2, ',', ' ') . ' zł';

console_head('SMS', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 18px; max-width: 86ch; line-height: 1.6; }
  .sms-ed { display: grid; gap: 18px; }
  @media (min-width: 1100px) { .sms-ed { grid-template-columns: minmax(0, 1fr) 380px; align-items: start; } }
  .tel { background: #f2f2f7; border-radius: 28px; padding: 22px 16px; min-height: 260px; }
  .bulle { background: #e5e5ea; color: #111; border-radius: 18px; padding: 10px 14px; font-size: 15px; line-height: 1.4;
           white-space: pre-wrap; overflow-wrap: anywhere; max-width: 92%; }
  .nad { font-size: 12px; color: #6b6b70; text-align: center; margin-bottom: 10px; font-weight: 600; }
  .licz { font-family: var(--font-mono); font-size: 13px; margin-top: 6px; }
  .licz.drogo { color: var(--danger); font-weight: 700; }
  textarea.sms { min-height: 120px; font-family: var(--font-mono); font-size: 14px; }
  .war { border: 1px solid var(--border-subtle); border-radius: 12px; padding: 10px 12px; margin-bottom: 8px; display: flex; gap: 10px; align-items: flex-start; }
  .war p { margin: 0; flex: 1; white-space: pre-wrap; font-family: var(--font-mono); font-size: 13px; }
  .ai { background: color-mix(in srgb, var(--accent-500) 8%, var(--surface-card)); }
CSS);
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Marketing' => null, 'SMS' => $c ? 'sms.php' : null] + ($c ? [(string) $c['nazwa'] => null] : []));
?>
<div class="kpis">
  <div class="kpi"><b><?= (int) $kpi['sms_aktywni'] ?></b><span>Zgody na SMS</span></div>
  <div class="kpi"><b><?= (int) $kpi['sms_wypisani'] ?></b><span>Wypisani z SMS</span></div>
  <div class="kpi"><b><?= (int) $kpi['kolejka_sms'] ?></b><span>SMS-y w kolejce</span></div>
  <div class="kpi"><b><?= h($zl($cfg['cena'])) ?></b><span>Cena 1 SMS-a</span></div>
</div>
<?php if (!wsm_sms_enabled()): ?>
<p class="warnbox">SMSAPI nie jest jeszcze podłączone (Integracje → SMS: token OAuth i nazwa nadawcy). Kampanie można przygotować — wyjdą po podłączeniu.</p>
<?php endif; ?>
<?php if (!wsm_mkt_adres_ok()): ?>
<p class="warnbox">Brak „Publicznego adresu sklepu” (Integracje → Sklep). Kampanie można przygotować i przetestować, ale wysyłka czeka na ten adres: od niego zależą linki, wypisania i raporty doręczeń.</p>
<?php endif; ?>
<?php if ($pompe['sms'] > 0): ?><p class="hint">Przy otwarciu ekranu wyszło <?= (int) $pompe['sms'] ?> SMS-ów z kolejki.</p><?php endif; ?>

<?php if (!$c): ?>
  <p class="hint">SMS-y wychodzą tylko do osób, które zaznaczyły zgodę na SMS (w kasie albo w formularzu newslettera), między 8:00 a 21:00.
    Każdy SMS kończy się krótkim linkiem do wypisania — dopisywanym automatycznie.</p>
  <?php if ($isAdmin): ?>
  <form method="post" class="actions" style="margin-bottom:18px"><?= console_csrf_field() ?>
    <button class="btn btn--brand" type="submit" name="nowa" value="1">+ Nowa kampania SMS</button>
    <button type="submit" name="pompa" value="1">Wyślij teraz partię z kolejki</button>
  </form>
  <?php endif; ?>
  <div class="panel"><h2>Kampanie</h2>
    <div class="tablewrap"><table class="rwd">
      <thead><tr><th>Nazwa</th><th>Status</th><th class="num">Wysłane</th><th class="num">Doręczone</th><th class="num">Niedoręczone / błędy</th>
        <th class="num">Koszt</th><th class="num">Kliknięcia</th><th class="num">Zamówienia</th><th class="num">Wypisy</th></tr></thead>
      <tbody>
      <?php $lista = wsm_sms_camp_list($pdo); if (!$lista): ?><tr><td colspan="9" class="muted">Jeszcze nic.</td></tr><?php endif; ?>
      <?php foreach ($lista as $r): $s = $r['stat']; ?>
      <tr>
        <td data-l="Nazwa"><a href="sms.php?id=<?= (int) $r['id'] ?>"><?= h((string) $r['nazwa']) ?></a></td>
        <td data-l="Status"><span class="tag <?= $r['status'] === 'szkic' ? 'wait' : 'ok' ?>"><?= $r['status'] === 'szkic' ? 'szkic' : h(substr((string) $r['sent_at'], 0, 16)) ?></span></td>
        <td data-l="Wysłane" class="num"><?= (int) $s['wyslany'] ?><?= $s['kolejka'] ? ' <small class="muted">(+' . (int) $s['kolejka'] . ' w kolejce)</small>' : '' ?></td>
        <td data-l="Doręczone" class="num"><?= (int) $s['dostarczone'] ?></td>
        <td data-l="Niedoręczone / błędy" class="num"><?= (int) $s['niedostarczone'] ?> / <?= (int) $s['blad'] ?></td>
        <td data-l="Koszt" class="num"><?= h($zl((int) $r['koszt'])) ?></td>
        <td data-l="Kliknięcia" class="num"><?= (int) $r['link']['klikniec'] ?></td>
        <td data-l="Zamówienia" class="num"><?= (int) $r['link']['zamowien'] ?> · <?= h($zl((int) $r['link']['obrot'])) ?></td>
        <td data-l="Wypisy" class="num"><?= (int) $r['wypisy'] ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

<?php else:
  $szkic = (string) $c['status'] === 'szkic' && $isAdmin;
  $dis = $szkic ? '' : ' disabled';
  $pv = wsm_sms_camp_preview($pdo, $c);
?>
<div class="sms-ed">
  <form method="post" action="sms.php?id=<?= (int) $id ?>">
    <?= console_csrf_field() ?>
    <?php if ($szkic && wsm_ai_enabled()): ?>
    <div class="panel ai"><h2>Napisz z AI</h2>
      <label class="field"><span>O czym ma być SMS?</span><input type="text" name="ai_brief" placeholder="Np. weekendowa promocja na czekoladę deserową, kod w treści"></label>
      <p class="actions" style="margin:8px 0 0"><button type="submit" name="ai_sms" value="1">Zaproponuj 3 wersje</button></p>
      <?php foreach ($warianty as $w): ?>
      <div class="war"><p><?= h($w['tekst']) ?></p><span class="tag <?= $w['czesci'] > 1 ? 'bad' : 'ok' ?>"><?= (int) $w['czesci'] ?> SMS</span>
        <button type="submit" name="uzyj" value="<?= h($w['tekst']) ?>">Użyj</button></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="panel">
      <h2>Kampania <span class="tag <?= $szkic ? 'wait' : 'ok' ?>"><?= $c['status'] === 'szkic' ? 'szkic' : 'wysłana' ?></span></h2>
      <div class="grid2">
        <label class="field"><span>Nazwa</span><input type="text" name="nazwa" value="<?= h((string) $c['nazwa']) ?>"<?= $dis ?>></label>
        <label class="field"><span>Odbiorcy (tylko ze zgodą SMS)</span><select name="segment"<?= $dis ?>>
          <?php foreach (WSM_MKT_SEGMENTS as $k => $lbl): ?><option value="<?= h($k) ?>"<?= $c['segment'] === $k ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
        </select></label>
        <label class="field"><span>Kod rabatowy ({kod})</span><input type="text" name="kod" value="<?= h((string) $c['kod']) ?>"<?= $dis ?>></label>
        <label class="chk" style="display:flex;gap:8px;align-items:center;margin-top:22px"><input type="checkbox" name="ascii" value="1"<?= !empty($c['ascii']) ? ' checked' : '' ?><?= $dis ?>> Bez polskich znaków (160 zamiast 70 znaków w SMS-ie)</label>
      </div>
      <label class="field"><span>Treść — {imie}, {kod}, {link} (adres sklepu z licznikiem kliknięć)</span>
        <textarea class="sms" name="tresc" maxlength="600" data-sms data-ascii="<?= !empty($c['ascii']) ? 1 : 0 ?>"<?= $dis ?>><?= h((string) $c['tresc']) ?></textarea></label>
      <div class="licz" data-licz></div>
      <?php foreach (['uk' => 'Ukraiński', 'en' => 'Angielski'] as $l => $lbl): ?>
      <label class="field"><span><?= h($lbl) ?> (dla odbiorców w tym języku; puste = wersja polska)</span>
        <textarea class="sms" name="tresc_<?= $l ?>" maxlength="600"<?= $dis ?>><?= h((string) ($c['tlumaczenia'][$l] ?? '')) ?></textarea></label>
      <?php if ($szkic && wsm_ai_enabled()): ?><p class="actions" style="margin:0 0 10px"><button type="submit" name="ai_tlumacz" value="<?= $l ?>">Przetłumacz z AI</button></p><?php endif; ?>
      <?php endforeach; ?>
      <?php if ($szkic): ?><p class="actions"><button class="btn btn--brand" type="submit" name="zapisz" value="1">Zapisz i przelicz</button></p><?php endif; ?>
    </div>

    <?php if ($isAdmin): ?>
    <div class="panel"><h2>Próbka i wysyłka</h2>
      <label class="field"><span>Próbka na numer</span><input type="tel" name="numer" placeholder="600 100 200"></label>
      <p class="actions"><button type="submit" name="<?= $szkic ? 'probka' : 'probka_tylko' ?>" value="1">Wyślij próbkę</button></p>
      <?php if ($szkic): ?>
      <p class="hint">Do wysłania: <b><?= (int) $pv['odbiorcow'] ?></b> SMS-ów · <?= (int) $pv['czesci'] ?> części do zapłaty ·
        koszt około <b><?= h($zl((int) $pv['koszt'])) ?></b><?php foreach ($pv['jezyki'] as $l => $n): ?> · <?= h($l) ?>: <?= (int) $n ?><?php endforeach; ?>.
        <?= wsm_sms_godziny_ok() ? '' : ' Teraz jest noc — kolejka ruszy o 8:00.' ?></p>
      <?php if (wsm_mkt_decide($me)): ?>
      <label class="chk" style="display:flex;gap:8px;align-items:center;margin:8px 0"><input type="checkbox" name="potwierdzam" value="1"> Próbka sprawdzona, koszt zaakceptowany.</label>
      <p class="actions"><button class="btn btn--brand" type="submit" name="wyslij" value="1">Wyślij do <?= (int) $pv['odbiorcow'] ?> osób</button></p>
      <?php else: ?><p class="hint">Wysyłkę do wszystkich zatwierdza administrator: przygotuj kampanię i próbkę, a administrator kliknie „Wyślij”.</p><?php endif; ?>
      <?php else: ?>
      <p class="hint">Wysłana <?= h((string) $c['sent_at']) ?>: <?= (int) $c['odbiorcow'] ?> SMS-ów, <?= (int) $c['czesci'] ?> części.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </form>

  <div>
    <div class="panel"><h2>Tak to zobaczy klient</h2>
      <div class="tel"><div class="nad"><?= h($cfg['nadawca'] !== '' ? $cfg['nadawca'] : 'NADAWCA') ?></div>
        <div class="bulle"><?= h($pv['przyklad']) ?></div></div>
      <p class="licz<?= $pv['licznik']['czesci'] > 1 ? ' drogo' : '' ?>"><?= (int) $pv['licznik']['znaki'] ?> znaków · <?= h($pv['licznik']['kodowanie']) ?> ·
        <?= (int) $pv['licznik']['czesci'] ?> SMS<?= $pv['licznik']['czesci'] > 1 ? ' — płacisz kilka razy za każdego odbiorcę' : '' ?></p>
      <p class="hint">Przykład dla „Anna”, z linkiem wypisania. Po zmianie treści kliknij „Zapisz i przelicz”.</p>
    </div>
  </div>
</div>
<script>
// Le compteur pendant la frappe. Le chiffre qui fait foi est celui du serveur
// (« Zapisz i przelicz ») ; celui-ci évite de découvrir le dépassement après.
(function () {
  var t = document.querySelector('[data-sms]'), out = document.querySelector('[data-licz]');
  if (!t || !out) return;
  var gsm = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
  var ext = "^{}\\[~]|€", pl = {'ą':'a','ć':'c','ę':'e','ł':'l','ń':'n','ó':'o','ś':'s','ź':'z','ż':'z','Ą':'A','Ć':'C','Ę':'E','Ł':'L','Ń':'N','Ó':'O','Ś':'S','Ź':'Z','Ż':'Z'};
  var stopka = <?= json_encode("\n" . str_replace('{stop}', wsm_mkt_sms_stop_url('abcd2345'), wsm_mkt_t($pdo, 'pl', 'nl.sms_footer', 'Rezygnacja: {stop}')), JSON_UNESCAPED_UNICODE) ?>;
  var asc = document.querySelector('input[name=ascii]');
  function calc() {
    var s = t.value + stopka;
    if (asc && asc.checked) s = s.replace(/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/g, function (c) { return pl[c]; });
    var u = 0, g = true;
    for (var ch of s) { if (gsm.indexOf(ch) >= 0) u++; else if (ext.indexOf(ch) >= 0) u += 2; else { g = false; break; } }
    var n, p;
    if (g) { n = u; p = n <= 160 ? 1 : Math.ceil(n / 153); }
    else { n = 0; for (var c2 of s) n += c2.codePointAt(0) > 0xFFFF ? 2 : 1; p = n <= 70 ? 1 : Math.ceil(n / 67); }
    out.textContent = n + ' znaków z dopiskiem wypisania · ' + (g ? 'GSM-7' : 'Unicode') + ' · ' + p + ' SMS (bez {imie}/{link})';
    out.className = 'licz' + (p > 1 ? ' drogo' : '');
  }
  t.addEventListener('input', calc); if (asc) asc.addEventListener('change', calc); calc();
})();
</script>
<?php endif; ?>
<?php console_foot();
