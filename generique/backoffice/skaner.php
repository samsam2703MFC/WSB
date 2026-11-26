<?php
// ============================================================================
//  skaner.php — le magasin au lecteur de codes-barres (ou à l'appareil photo).
//
//  Demande du magasin (Szoko, 26/11/2026) : « que le système sache à quel
//  produit ajouter une quantité quand on scanne, et ce qui sort quand on prend
//  des produits ». Trois modes, un seul geste — scanner :
//
//    Przyjęcie   une livraison : chaque scan ajoute une pièce au bon (PZ) ;
//                les quantités et le prix d'achat se corrigent avant d'enregistrer.
//    Wydanie     ce qui sort du magasin hors commande (atelier, échantillons,
//                casse) : chaque scan ajoute une pièce ; un motif est exigé.
//    Sprawdź     le scan montre le produit et son stock, sans rien changer.
//
//  Les sorties d'une COMMANDE ne passent pas ici : le stock baisse déjà au
//  moment de la commande. Leur contrôle au scanner est l'emballage
//  (pakowanie.php), qui vérifie qu'on met les bons produits dans le colis.
//
//  Un code inconnu se rattache au produit sur place (« Przypisz kod ») : la
//  fois suivante, le scanner le reconnaît.
//
//  Un lecteur USB ou Bluetooth « tape » le code puis Entrée : le champ de scan
//  garde le focus. Sur téléphone, « Aparat » lit le code avec la caméra
//  (BarcodeDetector, Chrome / Android) quand le navigateur le permet.
//
//  Lecture : tout compte qui ouvre le Magazyn. Écriture : Centrala.
// ============================================================================
declare(strict_types=1);
require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/stock.php';
require_once $API . '/ean.php';

$tryby = ['przyjecie' => 'Przyjęcie (PZ)', 'wydanie' => 'Wydanie / ubytek', 'sprawdz' => 'Sprawdź produkt'];
$tryb = (string) ($_GET['tryb'] ?? $_POST['tryb'] ?? 'przyjecie');
if (!isset($tryby[$tryb])) $tryb = 'przyjecie';
$actor = (string) ($me['nom'] ?? '');

// ─── Les écritures ───────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $json = ($_POST['akcja'] ?? '') === 'przypisz';
    if (!console_csrf_ok()) {
        if ($json) { header('Content-Type: application/json'); echo json_encode(['err' => 'Formularz wygasł — odśwież stronę.']); exit; }
        http_response_code(400); exit('Bad request.');
    }
    if (!$isAdmin) {
        if ($json) { header('Content-Type: application/json'); echo json_encode(['err' => 'Tylko rola Centrala może zmieniać magazyn.']); exit; }
        header('Location: skaner.php?tryb=' . $tryb . '&k=err&ok=' . rawurlencode('Tylko rola Centrala może zmieniać magazyn.'), true, 303); exit;
    }
    $akcja = (string) ($_POST['akcja'] ?? '');

    // Un code inconnu, rattaché à un produit depuis le scanner.
    if ($akcja === 'przypisz') {
        header('Content-Type: application/json');
        $pid = (string) ($_POST['product_id'] ?? '');
        $err = wsm_ean_assign($pdo, $pid, (string) ($_POST['ean'] ?? ''), $actor);
        if ($err !== null) { echo json_encode(['err' => $err]); exit; }
        echo json_encode(['ok' => true, 'produkt' => wsm_ean_find($pdo, (string) $_POST['ean'])]);
        exit;
    }

    // Les lignes scannées : [{pid, qty, cost}] en JSON, recontrôlées ici.
    $lignes = [];
    foreach ((array) json_decode((string) ($_POST['lignes'] ?? '[]'), true) as $l) {
        $pid = (string) ($l['pid'] ?? '');
        $qty = (int) ($l['qty'] ?? 0);
        if ($pid === '' || $qty <= 0 || $qty > 100000) continue;
        $lignes[] = ['product_id' => $pid, 'qty' => $qty,
                     'unit_cost' => (int) round(((float) str_replace(',', '.', (string) ($l['cost'] ?? '0'))) * 100)];
    }
    $msg = ''; $k = 'ok';
    if (!$lignes) { $msg = 'Nic nie zeskanowano.'; $k = 'err'; }
    elseif ($akcja === 'przyjmij') {
        [$doc, $err] = wsm_stock_receive($pdo, [
            'partner' => trim((string) ($_POST['partner'] ?? '')), 'ref' => trim((string) ($_POST['ref'] ?? '')),
            'issued_at' => date('Y-m-d'), 'note' => trim((string) ($_POST['note'] ?? '')) ?: 'skaner', 'actor' => $actor,
        ], $lignes);
        if ($err !== null) { $msg = $err; $k = 'err'; }
        else {
            wsm_audit($pdo, $actor, 'Przyjęcie (skaner)', $doc['number'] . ' · ' . $doc['units'] . ' szt.', 'Sieć');
            $msg = 'Zapisano ' . $doc['number'] . ' — ' . $doc['units'] . ' szt. przyjęte na stan.';
        }
    } elseif ($akcja === 'wydaj') {
        $powod = trim((string) ($_POST['powod'] ?? ''));
        if ($powod === '') { $msg = 'Podaj powód wydania.'; $k = 'err'; }
        else {
            $pdo->beginTransaction();
            try {
                $szt = 0;
                foreach ($lignes as $l) {
                    $chk = $pdo->prepare("SELECT 1 FROM wsm_products WHERE id = ?");
                    $chk->execute([$l['product_id']]);
                    if (!$chk->fetchColumn()) throw new RuntimeException('nieznany produkt ' . $l['product_id']);
                    wsm_stock_apply($pdo, $l['product_id'], -$l['qty'], 'korekta', [
                        'reason' => 'Wydanie: ' . $powod, 'note' => 'skaner', 'actor' => $actor,
                    ]);
                    $szt += $l['qty'];
                }
                $pdo->commit();
                wsm_audit($pdo, $actor, 'Wydanie (skaner)', $szt . ' szt. · ' . $powod, 'Sieć');
                $msg = 'Zapisano wydanie — ' . $szt . ' szt. zeszło ze stanu (' . $powod . ').';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $msg = 'Nic nie zapisano: ' . $e->getMessage(); $k = 'err';
            }
        }
    }
    header('Location: skaner.php?tryb=' . $tryb . '&k=' . $k . '&ok=' . rawurlencode($msg), true, 303);
    exit;
}

$flash = (string) ($_GET['ok'] ?? '');
$kind = in_array($_GET['k'] ?? '', ['ok', 'err'], true) ? (string) $_GET['k'] : 'ok';

// Le catalogue, embarqué dans la page : le scan répond sans aller-retour serveur.
$produits = [];
foreach ($pdo->query("SELECT id, nom, ean, sku, stock, image_url, active FROM wsm_products ORDER BY active DESC, nom") as $r) {
    $produits[] = ['id' => (string) $r['id'], 'nom' => (string) $r['nom'], 'ean' => (string) ($r['ean'] ?? ''),
                   'sku' => (string) ($r['sku'] ?? ''), 'stock' => (int) $r['stock'], 'active' => (int) $r['active']];
}
$bezEan = count(array_filter($produits, fn($p) => $p['active'] && $p['ean'] === ''));

console_head('Skaner', $me, <<<'CSS'
  .tryby { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
  .tryby a { padding: 9px 14px; border: 1px solid var(--border-default); border-radius: 10px;
             color: var(--text-strong); text-decoration: none; font-weight: 600; font-size: 14px; }
  .tryby a.on { background: var(--brand); border-color: var(--brand); color: var(--paper-50); }
  .scan { display: flex; gap: 8px; align-items: stretch; }
  .scan input { flex: 1; font: 600 22px/1.2 var(--font-mono); padding: 14px 16px; letter-spacing: .04em; }
  .scan button { white-space: nowrap; }
  .karta { margin-top: 14px; padding: 14px 16px; border-radius: 12px; border: 2px solid var(--border-subtle);
           display: none; }
  .karta.ok { display: block; border-color: var(--success); background: color-mix(in srgb, var(--success) 8%, transparent); }
  .karta.err { display: block; border-color: var(--danger); background: color-mix(in srgb, var(--danger) 8%, transparent); }
  .karta .nm { font-weight: 700; font-size: 17px; color: var(--text-strong); }
  .karta .mt { font: 13px var(--font-mono); color: var(--text-muted); margin-top: 4px; }
  .karta .big { font: 700 28px/1 var(--font-display); margin-top: 8px; }
  .przyp { display: none; margin-top: 10px; gap: 8px; flex-wrap: wrap; align-items: center; }
  .przyp.on { display: flex; }
  .przyp select { min-width: 260px; flex: 1; }
  table.linie td, table.linie th { padding: 8px; vertical-align: middle; }
  table.linie input { width: 90px; text-align: right; }
  table.linie .code { font-size: 12px; color: var(--text-muted); }
  tr.flash td { animation: fl .8s ease-out; }
  @keyframes fl { from { background: color-mix(in srgb, var(--success) 30%, transparent); } to { background: transparent; } }
  .suma { font-weight: 700; }
  #wideo { width: 100%; max-height: 260px; border-radius: 12px; margin-top: 10px; display: none; background: #000; object-fit: cover; }
CSS);
?>
<div class="panel">
  <h2>Skaner magazynowy</h2>
  <?php console_flash($flash, $kind); ?>
  <nav class="tryby" aria-label="Tryb skanera">
    <?php foreach ($tryby as $kt => $lt): ?>
      <a href="skaner.php?tryb=<?= h($kt) ?>" class="<?= $kt === $tryb ? 'on' : '' ?>"><?= h($lt) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($bezEan > 0): ?>
  <p class="muted" style="font-size:13px"><b><?= $bezEan ?></b> aktywnych produktów nie ma jeszcze kodu EAN — zeskanuj go tutaj i przypisz, albo wpisz w <a href="produkty.php">Produkty</a>.</p>
  <?php endif; ?>

  <div class="scan">
    <input id="kod" type="text" inputmode="numeric" autocomplete="off" autofocus
           placeholder="Zeskanuj kod EAN…" aria-label="Kod EAN">
    <button type="button" id="aparat" hidden>📷 Aparat</button>
  </div>
  <video id="wideo" playsinline muted></video>

  <div class="karta" id="karta" aria-live="polite">
    <div class="nm" id="k-nm"></div>
    <div class="mt" id="k-mt"></div>
    <div class="big" id="k-big"></div>
    <div class="przyp" id="przyp">
      <select id="przyp-pid" aria-label="Produkt dla tego kodu">
        <option value="">— wybierz produkt dla tego kodu —</option>
        <?php foreach ($produits as $p): if (!$p['active']) continue; ?>
        <option value="<?= h($p['id']) ?>"><?= h($p['nom']) ?><?= $p['ean'] !== '' ? ' (ma już EAN ' . h($p['ean']) . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="primary" id="przyp-ok">Przypisz kod</button>
    </div>
  </div>
</div>

<?php if ($tryb !== 'sprawdz'): ?>
<div class="panel">
  <h2><?= $tryb === 'przyjecie' ? 'Przyjmowane na stan' : 'Schodzi ze stanu' ?></h2>
  <div class="tablewrap">
    <table class="linie">
      <thead><tr><th>Produkt</th><th class="num">Stan teraz</th><th class="num">Ilość</th>
        <?php if ($tryb === 'przyjecie'): ?><th class="num">Cena zakupu / szt.</th><?php endif; ?><th></th></tr></thead>
      <tbody id="linie"><tr id="pusto"><td colspan="5" class="muted">Zeskanuj pierwszy produkt — każdy skan dodaje 1 szt.</td></tr></tbody>
      <tfoot><tr><td class="suma">Razem</td><td></td><td class="num suma" id="razem">0</td><td colspan="2"></td></tr></tfoot>
    </table>
  </div>
  <form method="post" id="zapis">
    <?= console_csrf_field() ?>
    <input type="hidden" name="tryb" value="<?= h($tryb) ?>">
    <input type="hidden" name="akcja" value="<?= $tryb === 'przyjecie' ? 'przyjmij' : 'wydaj' ?>">
    <input type="hidden" name="lignes" id="lignes" value="[]">
    <div class="grid2" style="margin-top:12px">
      <?php if ($tryb === 'przyjecie'): ?>
      <label class="field"><span>Dostawca</span><input type="text" name="partner" placeholder="np. Callebaut Polska"></label>
      <label class="field"><span>Dokument dostawcy</span><input type="text" name="ref" placeholder="nr faktury / WZ dostawcy"></label>
      <?php else: ?>
      <label class="field"><span>Powód</span>
        <input type="text" name="powod" list="powody" required placeholder="np. do pracowni, próbki, stłuczka">
        <datalist id="powody"><option value="Do pracowni / produkcja"><option value="Próbki / degustacja">
          <option value="Uszkodzenie / stłuczka"><option value="Przeterminowane"><option value="Inwentaryzacja"></datalist></label>
      <?php endif; ?>
      <label class="field"><span>Uwaga</span><input type="text" name="note"></label>
    </div>
    <div class="actions">
      <button class="primary" type="submit" id="zapisz" disabled<?= $isAdmin ? '' : ' title="Tylko rola Centrala"' ?>>
        <?= $tryb === 'przyjecie' ? 'Zapisz przyjęcie (PZ)' : 'Zapisz wydanie' ?></button>
      <button type="button" id="wyczysc">Wyczyść listę</button>
    </div>
  </form>
</div>
<?php endif; ?>

<script>
(function () {
  var TRYB = <?= json_encode($tryb) ?>;
  var ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
  var CSRF = <?= json_encode(console_csrf()) ?>;
  var produkty = <?= json_encode($produits, JSON_UNESCAPED_UNICODE) ?>;
  var poKodzie = {};
  function warianty(e) { var v = [e]; if (e.length === 12) v.push('0' + e); if (e.length === 13 && e[0] === '0') v.push(e.slice(1)); return v; }
  function indeks() { poKodzie = {}; produkty.forEach(function (p) { if (p.ean) warianty(p.ean).forEach(function (w) { poKodzie[w] = p; }); }); }
  indeks();
  var linie = [];     // {p, qty, cost}
  var pole = document.getElementById('kod'), karta = document.getElementById('karta');
  var przyp = document.getElementById('przyp'), ostatniNieznany = '';

  // Le son et la vibration disent le résultat sans regarder l'écran.
  var ctx = null;
  function bip(ok) {
    try {
      ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
      var o = ctx.createOscillator(), g = ctx.createGain();
      o.frequency.value = ok ? 1250 : 220; o.type = ok ? 'sine' : 'square';
      g.gain.value = .08; o.connect(g); g.connect(ctx.destination);
      o.start(); o.stop(ctx.currentTime + (ok ? .09 : .35));
    } catch (e) {}
    if (navigator.vibrate) navigator.vibrate(ok ? 40 : [120, 60, 120]);
  }
  function pokaz(ok, nm, mt, big) {
    karta.className = 'karta ' + (ok ? 'ok' : 'err');
    document.getElementById('k-nm').textContent = nm;
    document.getElementById('k-mt').textContent = mt;
    document.getElementById('k-big').textContent = big || '';
  }
  function cyfry(s) { return (s || '').replace(/\D+/g, ''); }

  function skan(raw) {
    var kod = cyfry(raw);
    przyp.classList.remove('on');
    if (!kod) return;
    var p = poKodzie[kod];
    if (!p) {
      ostatniNieznany = kod; bip(false);
      pokaz(false, 'Nieznany kod ' + kod, 'Żaden produkt nie ma tego EAN.' + (ADMIN ? ' Wybierz produkt i przypisz kod — następnym razem skaner go rozpozna.' : ''), '');
      if (ADMIN) przyp.classList.add('on');
      return;
    }
    bip(true);
    if (TRYB === 'sprawdz') {
      pokaz(true, p.nom, 'EAN ' + p.ean + (p.sku ? ' · SKU ' + p.sku : '') + (p.active ? '' : ' · nieaktywny'), 'Stan: ' + p.stock + ' szt.');
      var a = document.getElementById('k-mt');
      var l = document.createElement('a'); l.href = 'magazyn_produkt.php?id=' + encodeURIComponent(p.id); l.textContent = ' · historia →';
      a.appendChild(l);
      return;
    }
    var ln = linie.find(function (x) { return x.p.id === p.id; });
    if (ln) ln.qty++; else { ln = { p: p, qty: 1, cost: '' }; linie.push(ln); }
    pokaz(true, p.nom, 'EAN ' + p.ean + ' · stan teraz ' + p.stock + ' szt.',
          (TRYB === 'przyjecie' ? '+' : '−') + ln.qty + ' szt.');
    rysuj(p.id);
  }

  function rysuj(swiezy) {
    var tb = document.getElementById('linie');
    if (!tb) return;
    tb.innerHTML = '';
    if (!linie.length) { tb.innerHTML = '<tr><td colspan="5" class="muted">Zeskanuj pierwszy produkt — każdy skan dodaje 1 szt.</td></tr>'; }
    linie.forEach(function (ln, i) {
      var tr = document.createElement('tr'); if (ln.p.id === swiezy) tr.className = 'flash';
      var td = document.createElement('td');
      td.innerHTML = '<b></b><br><span class="code"></span>';
      td.querySelector('b').textContent = ln.p.nom;
      td.querySelector('.code').textContent = 'EAN ' + ln.p.ean + (ln.p.sku ? ' · ' + ln.p.sku : '');
      tr.appendChild(td);
      var st = document.createElement('td'); st.className = 'num'; st.textContent = ln.p.stock; tr.appendChild(st);
      var q = document.createElement('td'); q.className = 'num';
      var iq = document.createElement('input'); iq.type = 'number'; iq.min = '0'; iq.value = ln.qty;
      iq.setAttribute('aria-label', 'Ilość — ' + ln.p.nom);
      iq.addEventListener('input', function () { ln.qty = Math.max(0, parseInt(iq.value || '0', 10)); suma(); });
      q.appendChild(iq); tr.appendChild(q);
      if (TRYB === 'przyjecie') {
        var c = document.createElement('td'); c.className = 'num';
        var ic = document.createElement('input'); ic.type = 'text'; ic.inputMode = 'decimal'; ic.placeholder = '0,00'; ic.value = ln.cost;
        ic.setAttribute('aria-label', 'Cena zakupu — ' + ln.p.nom);
        ic.addEventListener('input', function () { ln.cost = ic.value; suma(); });
        c.appendChild(ic); tr.appendChild(c);
      }
      var x = document.createElement('td'); x.className = 'num';
      var bx = document.createElement('button'); bx.type = 'button'; bx.textContent = '✕'; bx.title = 'Usuń z listy';
      bx.addEventListener('click', function () { linie.splice(i, 1); rysuj(); pole.focus(); });
      x.appendChild(bx); tr.appendChild(x);
      tb.appendChild(tr);
    });
    suma();
  }
  function suma() {
    var r = document.getElementById('razem'); if (!r) return;
    var n = linie.reduce(function (s, l) { return s + (l.qty || 0); }, 0);
    r.textContent = n + ' szt.';
    document.getElementById('lignes').value = JSON.stringify(linie.filter(function (l) { return l.qty > 0; })
      .map(function (l) { return { pid: l.p.id, qty: l.qty, cost: l.cost }; }));
    document.getElementById('zapisz').disabled = !ADMIN || n === 0;
  }

  // Le lecteur tape le code puis Entrée ; à la main, Entrée aussi.
  pole.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); skan(pole.value); pole.value = ''; }
  });
  // Le focus revient au champ de scan après un clic ailleurs (sauf dans un champ).
  document.addEventListener('click', function (e) {
    if (!e.target.closest('input, select, textarea, button, a')) pole.focus();
  });

  var wyczysc = document.getElementById('wyczysc');
  if (wyczysc) wyczysc.addEventListener('click', function () { if (!linie.length || confirm('Wyczyścić listę?')) { linie = []; rysuj(); pole.focus(); } });
  var zapis = document.getElementById('zapis');
  if (zapis) zapis.addEventListener('submit', function (e) {
    suma();
    var n = linie.reduce(function (s, l) { return s + (l.qty || 0); }, 0);
    if (!confirm((TRYB === 'przyjecie' ? 'Przyjąć na stan ' : 'Zdjąć ze stanu ') + n + ' szt. (' + linie.length + ' produktów)?')) e.preventDefault();
  });

  // Rattacher un code inconnu à un produit.
  document.getElementById('przyp-ok').addEventListener('click', function () {
    var pid = document.getElementById('przyp-pid').value;
    if (!pid || !ostatniNieznany) return;
    var fd = new FormData(); fd.append('_t', CSRF); fd.append('akcja', 'przypisz'); fd.append('tryb', TRYB);
    fd.append('product_id', pid); fd.append('ean', ostatniNieznany);
    fetch('skaner.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.err) { bip(false); pokaz(false, 'Nie przypisano', j.err, ''); przyp.classList.add('on'); return; }
        produkty.forEach(function (p) { if (p.id === pid) p.ean = j.produkt.ean; });
        indeks();
        var k = ostatniNieznany; ostatniNieznany = '';
        skan(k);
        pole.focus();
      })
      .catch(function () { bip(false); pokaz(false, 'Błąd połączenia', 'Spróbuj jeszcze raz.', ''); });
  });

  // L'appareil photo : seulement là où le navigateur sait lire un code-barres.
  if ('BarcodeDetector' in window && navigator.mediaDevices) {
    var bA = document.getElementById('aparat'), wid = document.getElementById('wideo'), strum = null, det, ostatni = '', kiedy = 0;
    bA.hidden = false;
    bA.addEventListener('click', function () {
      if (strum) { strum.getTracks().forEach(function (t) { t.stop(); }); strum = null; wid.style.display = 'none'; bA.textContent = '📷 Aparat'; return; }
      det = new BarcodeDetector({ formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'itf'] });
      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
        strum = s; wid.srcObject = s; wid.style.display = 'block'; wid.play(); bA.textContent = '■ Zatrzymaj';
        (function petla() {
          if (!strum) return;
          det.detect(wid).then(function (kody) {
            var k = kody.length ? cyfry(kody[0].rawValue) : '';
            // Le même code vu en continu ne compte qu'une fois par 1,5 s.
            if (k && (k !== ostatni || Date.now() - kiedy > 1500)) { ostatni = k; kiedy = Date.now(); skan(k); }
          }).catch(function () {}).finally(function () { setTimeout(petla, 200); });
        })();
      }).catch(function () { pokaz(false, 'Brak dostępu do aparatu', 'Zezwól przeglądarce na użycie aparatu.', ''); });
    });
  }
  rysuj();
})();
</script>
<?php console_foot();
