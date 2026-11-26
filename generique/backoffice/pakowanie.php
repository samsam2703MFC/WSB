<?php
// ============================================================================
//  pakowanie.php — emballer une commande au scanner.
//
//  Demande du magasin (Szoko, 26/11/2026) : « scanner aussi en emballant les
//  commandes, pour que le système sache ce qui part et combien ».
//
//  Le stock a déjà baissé au moment de la commande (c'est ce qui empêche de
//  vendre deux fois le même sac) : l'emballage ne le rebouge donc pas. Il
//  CONTRÔLE : chaque produit posé dans le colis est scanné, et l'écran dit
//  tout de suite si ce n'est pas le bon, ou s'il y en a un de trop. Un article
//  sans EAN se compte à la main (« +1 »).
//
//  « Spakowane » écrit l'emballage dans l'historique de la commande (qui,
//  quand, ce qui manquait le cas échéant) et émet le WZ s'il n'existe pas
//  encore. Le statut de la commande ne change pas : il envoie un mail au
//  client, et ce geste reste celui de l'écran Zamówienia.
// ============================================================================
declare(strict_types=1);
require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/stock.php';
require_once $API . '/ean.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$order = $id > 0 ? wsm_order_by_id($pdo, $id) : null;
$actor = (string) ($me['nom'] ?? '');

if ($order && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['zakoncz'])) {
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) { header('Location: pakowanie.php?id=' . $id . '&k=err&ok=' . rawurlencode('Brak uprawnień do zapisu pakowania.'), true, 303); exit; }
    $spak = (array) json_decode((string) ($_POST['spakowane'] ?? '{}'), true);
    $uwaga = trim((string) ($_POST['uwaga'] ?? ''));
    $braki = []; $razem = 0; $zamowione = 0;
    $chce = [];
    foreach ($order['items'] as $it) $chce[(string) $it['product_id']] = ($chce[(string) $it['product_id']] ?? 0) + (int) $it['qty'];
    $nomy = [];
    foreach ($order['items'] as $it) $nomy[(string) $it['product_id']] = (string) $it['name'];
    foreach ($chce as $pid => $q) {
        $n = max(0, (int) ($spak[$pid] ?? 0));
        $razem += min($n, $q); $zamowione += $q;
        if ($n < $q) $braki[] = $nomy[$pid] . ' (brak ' . ($q - $n) . ')';
        if ($n > $q) $braki[] = $nomy[$pid] . ' (+' . ($n - $q) . ' ponad)';
    }
    if ($braki && $uwaga === '') {
        header('Location: pakowanie.php?id=' . $id . '&k=err&ok=' . rawurlencode('Paczka niezgodna z zamówieniem — dopisz uwagę, dlaczego wysyłasz z różnicą.'), true, 303);
        exit;
    }
    $opis = $razem . '/' . $zamowione . ' szt. zeskanowane' . ($braki ? ' · różnice: ' . implode(', ', $braki) : '') . ($uwaga !== '' ? ' · ' . $uwaga : '');
    wsm_order_event($pdo, $id, 'spakowane', $opis, $actor);
    [$wz, $err] = wsm_stock_issue_wz($pdo, $order, $actor);
    wsm_audit($pdo, $actor, 'Pakowanie', $order['code'] . ' · ' . $opis, 'Sieć');
    $msg = 'Spakowane: ' . $order['code'] . ' (' . $razem . '/' . $zamowione . ' szt.)'
         . ($err === null ? ' · ' . $wz['number'] : ' · WZ: ' . $err);
    header('Location: pakowanie.php?id=' . $id . '&k=' . ($braki ? 'err' : 'ok') . '&ok=' . rawurlencode($msg), true, 303);
    exit;
}

$flash = (string) ($_GET['ok'] ?? '');
$kind = in_array($_GET['k'] ?? '', ['ok', 'err'], true) ? (string) $_GET['k'] : 'ok';

$pozycje = [];
$juz = null;
if ($order) {
    // Une ligne par produit (une commande peut porter deux fois le même article).
    foreach ($order['items'] as $it) {
        $pid = (string) $it['product_id'];
        if (!isset($pozycje[$pid])) {
            $st = $pdo->prepare("SELECT ean, sku, image_url, rodzaj FROM wsm_products WHERE id = ?");
            $st->execute([$pid]);
            $pr = $st->fetch() ?: [];
            $pozycje[$pid] = ['pid' => $pid, 'nom' => (string) $it['name'], 'sku' => (string) ($it['sku'] ?: ($pr['sku'] ?? '')),
                              'ean' => (string) ($pr['ean'] ?? ''), 'qty' => 0, 'towar' => ($pr['rodzaj'] ?? 'towar') === 'towar'];
        }
        $pozycje[$pid]['qty'] += (int) $it['qty'];
    }
    $st = $pdo->prepare("SELECT detail, actor, created_at FROM wsm_order_events WHERE order_id = ? AND event = 'spakowane' ORDER BY id DESC LIMIT 1");
    $st->execute([$id]);
    $juz = $st->fetch() ?: null;
}

console_head('Pakowanie', $me, <<<'CSS'
  .scan { display: flex; flex-wrap: wrap; gap: 8px; }
  .scan input { flex: 1 1 260px; min-width: 0; font: 600 22px/1.2 var(--font-mono); padding: 14px 16px; letter-spacing: .04em; }
  .glowa { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px 20px; align-items: baseline; }
  .glowa .code { font-size: 20px; font-weight: 700; }
  .pasek { height: 10px; border-radius: 6px; background: var(--border-subtle); overflow: hidden; margin: 14px 0 4px; }
  .pasek i { display: block; height: 100%; width: 0; background: var(--success); transition: width .2s; }
  .poz { display: grid; grid-template-columns: 1fr auto auto; gap: 6px 14px; align-items: center;
         padding: 12px 10px; border-bottom: 1px solid var(--border-subtle); border-radius: 8px; }
  .poz .nm { font-weight: 600; color: var(--text-strong); }
  .poz .mt { font: 12px var(--font-mono); color: var(--text-muted); }
  .poz .ile { font: 700 22px/1 var(--font-display); min-width: 74px; text-align: right; }
  .poz.gotowe { background: color-mix(in srgb, var(--success) 10%, transparent); }
  .poz.gotowe .ile { color: var(--success); }
  .poz.nadmiar { background: color-mix(in srgb, var(--danger) 12%, transparent); }
  .poz.nadmiar .ile { color: var(--danger); }
  .poz.swiezy { outline: 2px solid var(--success); }
  .poz .reka { display: flex; gap: 4px; }
  .poz .reka button { min-width: 38px; }
  .komunikat { margin-top: 12px; padding: 12px 14px; border-radius: 10px; font-weight: 600; display: none; }
  .komunikat.ok { display: block; background: color-mix(in srgb, var(--success) 12%, transparent); color: var(--success); }
  .komunikat.err { display: block; background: color-mix(in srgb, var(--danger) 13%, transparent); color: var(--danger); }
  #wideo { width: 100%; max-height: 240px; border-radius: 12px; margin-top: 10px; display: none; background: #000; object-fit: cover; }
CSS);
?>
<?php if (!$order): ?>
<div class="panel">
  <h2>Pakowanie</h2>
  <p>Nie znaleziono zamówienia. Otwórz pakowanie z listy <a href="wysylka.php">Wysyłka</a> albo z karty zamówienia.</p>
</div>
<?php else: ?>
<div class="panel">
  <?php console_flash($flash, $kind); ?>
  <div class="glowa">
    <div><span class="muted">Pakowanie zamówienia</span><br><span class="code"><?= h($order['code']) ?></span></div>
    <div class="muted"><?= h(trim($order['first_name'] . ' ' . $order['last_name']) ?: $order['company']) ?>
      · <a href="zamowienia.php?id=<?= (int) $order['id'] ?>">karta zamówienia →</a></div>
  </div>
  <?php if ($juz): ?>
  <p class="flash ok" style="margin-top:12px">Już spakowane <?= h((string) $juz['created_at']) ?> (<?= h((string) $juz['actor']) ?>): <?= h((string) $juz['detail']) ?>. Możesz zeskanować jeszcze raz, żeby sprawdzić paczkę.</p>
  <?php endif; ?>

  <div class="scan" style="margin-top:14px">
    <input id="kod" type="text" inputmode="numeric" autocomplete="off" autofocus placeholder="Zeskanuj produkt wkładany do paczki…" aria-label="Kod EAN">
    <button type="button" id="zdjecie" hidden>📷 Zdjęcie kodu</button>
    <button type="button" id="na-zywo" hidden>🎥 Skanuj na żywo</button>
  </div>
  <video id="wideo" playsinline muted></video>
  <div class="komunikat" id="kom" aria-live="assertive"></div>
  <div class="pasek"><i id="pasek"></i></div>
  <div class="muted" id="stan" style="font-size:13px"></div>

  <div id="pozycje" style="margin-top:10px">
    <?php foreach ($pozycje as $p): ?>
    <div class="poz" data-pid="<?= h($p['pid']) ?>">
      <div><div class="nm"><?= h($p['nom']) ?></div>
        <div class="mt"><?= $p['ean'] !== '' ? 'EAN ' . h($p['ean']) : '<span style="color:var(--warning,#b77500)">bez EAN — policz ręcznie</span>' ?><?= $p['sku'] !== '' ? ' · ' . h($p['sku']) : '' ?></div></div>
      <div class="reka"><button type="button" data-m="-1" aria-label="Odejmij 1 — <?= h($p['nom']) ?>">−1</button><button type="button" data-m="1" aria-label="Dodaj 1 — <?= h($p['nom']) ?>">+1</button></div>
      <div class="ile"><span data-n>0</span>/<?= (int) $p['qty'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <form method="post" id="koniec" style="margin-top:16px">
    <?= console_csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
    <input type="hidden" name="zakoncz" value="1">
    <input type="hidden" name="spakowane" id="spakowane" value="{}">
    <label class="field" id="uwaga-pole" style="display:none"><span>Uwaga — dlaczego paczka różni się od zamówienia</span>
      <input type="text" name="uwaga" id="uwaga" placeholder="np. brak na stanie, klient zgodził się na zamiennik"></label>
    <div class="actions">
      <button class="primary" type="submit" id="gotowe" disabled>Spakowane ✓</button>
      <span class="muted" style="font-size:13px">Zapisuje pakowanie w historii zamówienia i wystawia WZ.</span>
    </div>
  </form>
</div>

<script src="<?= h(console_asset('kamera.js')) ?>"></script>
<script>
(function () {
  var poz = <?= json_encode(array_values($pozycje), JSON_UNESCAPED_UNICODE) ?>;
  var n = {}, poKodzie = {};
  function warianty(e) { var v = [e]; if (e.length === 12) v.push('0' + e); if (e.length === 13 && e[0] === '0') v.push(e.slice(1)); return v; }
  poz.forEach(function (p) { n[p.pid] = 0; if (p.ean) warianty(p.ean).forEach(function (w) { poKodzie[w] = p; }); });
  var pole = document.getElementById('kod'), kom = document.getElementById('kom');
  var ctx = null;
  function bip(ok) {
    try {
      ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
      var o = ctx.createOscillator(), g = ctx.createGain();
      o.frequency.value = ok ? 1250 : 220; o.type = ok ? 'sine' : 'square';
      g.gain.value = .08; o.connect(g); g.connect(ctx.destination);
      o.start(); o.stop(ctx.currentTime + (ok ? .09 : .4));
    } catch (e) {}
    if (navigator.vibrate) navigator.vibrate(ok ? 40 : [150, 70, 150]);
  }
  function mow(ok, t) { kom.className = 'komunikat ' + (ok ? 'ok' : 'err'); kom.textContent = t; }

  function rysuj(swiezy) {
    var razem = 0, ile = 0, rozne = false;
    poz.forEach(function (p) {
      var el = document.querySelector('.poz[data-pid="' + CSS.escape(p.pid) + '"]');
      el.querySelector('[data-n]').textContent = n[p.pid];
      el.classList.toggle('gotowe', n[p.pid] === p.qty);
      el.classList.toggle('nadmiar', n[p.pid] > p.qty);
      el.classList.toggle('swiezy', p.pid === swiezy);
      razem += p.qty; ile += Math.min(n[p.pid], p.qty);
      if (n[p.pid] !== p.qty) rozne = true;
    });
    document.getElementById('pasek').style.width = (razem ? 100 * ile / razem : 0) + '%';
    document.getElementById('stan').textContent = ile + ' z ' + razem + ' szt. w paczce';
    document.getElementById('spakowane').value = JSON.stringify(n);
    var wszystko = !rozne;
    // .field impose display:block : l'attribut hidden seul ne le cache pas.
    document.getElementById('uwaga-pole').style.display = (wszystko || ile === 0) ? 'none' : '';
    document.getElementById('gotowe').disabled = ile === 0;
    document.getElementById('gotowe').textContent = wszystko ? 'Spakowane ✓' : 'Spakowane z różnicą…';
  }

  function skan(raw) {
    var kod = (raw || '').replace(/\D+/g, '');
    if (!kod) return;
    var p = poKodzie[kod];
    if (!p) { bip(false); mow(false, '✕ Kod ' + kod + ' — tego produktu NIE MA w zamówieniu. Odłóż go.'); return; }
    if (n[p.pid] >= p.qty) { n[p.pid]++; bip(false); mow(false, '✕ ' + p.nom + ': już jest ' + p.qty + ' szt. — to o jeden za dużo.'); rysuj(p.pid); return; }
    n[p.pid]++; bip(true);
    mow(true, '✓ ' + p.nom + ' — ' + n[p.pid] + '/' + p.qty + (n[p.pid] === p.qty ? ' (komplet)' : ''));
    rysuj(p.pid);
  }

  pole.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); skan(pole.value); pole.value = ''; } });
  document.addEventListener('click', function (e) { if (!e.target.closest('input, select, textarea, button, a')) pole.focus(); });
  document.querySelectorAll('.poz [data-m]').forEach(function (b) {
    b.addEventListener('click', function () {
      var pid = b.closest('.poz').dataset.pid;
      n[pid] = Math.max(0, n[pid] + parseInt(b.dataset.m, 10)); rysuj(pid); pole.focus();
    });
  });
  document.getElementById('koniec').addEventListener('submit', function (e) {
    var rozne = poz.some(function (p) { return n[p.pid] !== p.qty; });
    if (rozne && !document.getElementById('uwaga').value.trim()) { e.preventDefault(); mow(false, 'Paczka różni się od zamówienia — dopisz uwagę.'); document.getElementById('uwaga').focus(); return; }
    if (rozne && !confirm('Paczka różni się od zamówienia. Zapisać mimo to?')) e.preventDefault();
  });

  // L'appareil photo du téléphone (kamera.js) : photo du code partout, vidéo en direct en https.
  wsmKamera({ zdjecie: document.getElementById('zdjecie'), naZywo: document.getElementById('na-zywo'),
              wideo: document.getElementById('wideo'),
              kod: function (k) { skan(k); pole.focus(); },
              blad: function (m) { bip(false); mow(false, m); } });
  rysuj();
})();
</script>
<?php endif; ?>
<?php console_foot();
