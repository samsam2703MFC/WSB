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
//  Un code inconnu se rattache sur place à un produit de la base (recherche
//  par nom ou SKU, ceux sans EAN d'abord), ou devient un produit NOUVEAU
//  (brouillon, pas en boutique, avec son EAN) : la fois suivante, le scanner
//  le reconnaît.
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
    $json = in_array($_POST['akcja'] ?? '', ['przypisz', 'nowy', 'przyjmij1'], true);
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

    // « Od razu na stan » : chaque scan entre tout de suite dans le stock, dans
    // le PZ de la séance (créé au premier scan) ; −1 = cofnij.
    if ($akcja === 'przyjmij1') {
        header('Content-Type: application/json');
        $q = (int) ($_POST['qty'] ?? 1);
        if ($q !== 1 && $q !== -1) { echo json_encode(['err' => 'ilość']); exit; }
        [$r, $err] = wsm_stock_receive_scan($pdo, (int) ($_POST['doc_id'] ?? 0) ?: null, [
            'partner' => trim((string) ($_POST['partner'] ?? '')), 'ref' => trim((string) ($_POST['ref'] ?? '')),
            'issued_at' => date('Y-m-d'), 'note' => 'skaner — od razu na stan', 'actor' => $actor,
        ], (string) ($_POST['product_id'] ?? ''), $q, (int) round(((float) str_replace(',', '.', (string) ($_POST['cost'] ?? '0'))) * 100));
        echo json_encode($err !== null ? ['err' => $err] : ['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Un code inconnu qui est un produit NOUVEAU : créé en brouillon avec son EAN.
    if ($akcja === 'nowy') {
        header('Content-Type: application/json');
        [$pid, $err] = wsm_ean_nowy_produkt($pdo, (string) ($_POST['nazwa'] ?? ''), (int) ($_POST['category_id'] ?? 0),
                                            (string) ($_POST['cena'] ?? ''), (string) ($_POST['ean'] ?? ''), $actor);
        if ($err !== null) { echo json_encode(['err' => $err]); exit; }
        $p = wsm_ean_find($pdo, (string) $_POST['ean']);
        echo json_encode(['ok' => true, 'produkt' => ['id' => $pid, 'nom' => $p['nom'] ?? '', 'ean' => $p['ean'] ?? '',
                          'sku' => '', 'stock' => 0, 'active' => 1]], JSON_UNESCAPED_UNICODE);
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
$kategorie = $pdo->query("SELECT id, name FROM wsm_categories WHERE active = 1 ORDER BY sort_order, name")->fetchAll();
$bezEan = count(array_filter($produits, fn($p) => $p['active'] && $p['ean'] === ''));

console_head('Skaner', $me, <<<'CSS'
  .tryby { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
  .tryby a { padding: 9px 14px; border: 1px solid var(--border-default); border-radius: 10px;
             color: var(--text-strong); text-decoration: none; font-weight: 600; font-size: 14px; }
  .tryby a.on { background: var(--brand); border-color: var(--brand); color: var(--paper-50); }
  .scan { display: flex; flex-wrap: wrap; gap: 8px; align-items: stretch; }
  .scan input { flex: 1 1 260px; min-width: 0; font: 600 22px/1.2 var(--font-mono); padding: 14px 16px; letter-spacing: .04em; }
  .scan button { white-space: nowrap; }
  .karta { margin-top: 14px; padding: 14px 16px; border-radius: 12px; border: 2px solid var(--border-subtle);
           display: none; }
  .karta.ok { display: block; border-color: var(--success); background: color-mix(in srgb, var(--success) 8%, transparent); }
  .karta.err { display: block; border-color: var(--danger); background: color-mix(in srgb, var(--danger) 8%, transparent); }
  .karta .nm { font-weight: 700; font-size: 17px; color: var(--text-strong); }
  .karta .mt { font: 13px var(--font-mono); color: var(--text-muted); margin-top: 4px; }
  .karta .big { font: 700 28px/1 var(--font-display); margin-top: 8px; }
  .przyp { display: none; margin-top: 12px; }
  .przyp.on { display: block; }
  .przyp .zakl { display: flex; gap: 6px; margin-bottom: 10px; flex-wrap: wrap; }
  .przyp .zakl button { flex: 1 1 160px; }
  .przyp .zakl button.on { background: var(--brand); border-color: var(--brand); color: var(--paper-50); }
  .przyp input[type=search], .przyp input[type=text], .przyp select { width: 100%; min-width: 0; box-sizing: border-box; }
  .wyniki { list-style: none; margin: 8px 0 0; padding: 0; max-height: 300px; overflow: auto;
            border: 1px solid var(--border-subtle); border-radius: 10px; background: var(--surface, #fff); }
  .wyniki li button { display: flex; width: 100%; justify-content: space-between; gap: 10px; text-align: left;
            border: 0; border-bottom: 1px solid var(--border-subtle); border-radius: 0; padding: 11px 12px;
            background: transparent; font-weight: 500; }
  .wyniki li:last-child button { border-bottom: 0; }
  .wyniki li button:hover, .wyniki li button:focus-visible { background: color-mix(in srgb, var(--brand) 8%, transparent); }
  .wyniki .wy-m { font: 12px var(--font-mono); color: var(--text-muted); white-space: nowrap; }
  .wyniki .wy-zero { padding: 11px 12px; color: var(--text-muted); font-size: 14px; }
  .nowy-f { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .nowy-f .cala { grid-column: 1 / -1; }
  @media (max-width: 560px) { .nowy-f { grid-template-columns: 1fr; } }
  table.linie td, table.linie th { padding: 8px; vertical-align: middle; }
  table.linie input { width: 90px; text-align: right; }
  table.linie .code { font-size: 12px; color: var(--text-muted); }
  tr.flash td { animation: fl .8s ease-out; }
  @keyframes fl { from { background: color-mix(in srgb, var(--success) 30%, transparent); } to { background: transparent; } }
  .suma { font-weight: 700; }
  .od-razu { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; margin: 0 0 12px; border-radius: 12px;
             background: var(--surface-sunken, #f3f3f3); cursor: pointer; font-size: 14px; }
  .od-razu input { width: 22px; height: 22px; margin-top: 1px; flex: none; }
  .od-razu-info { margin: 0 0 12px; font-size: 14px; color: var(--success); font-weight: 600; }
  body.tryb-od-razu #zapis .actions { display: none; }
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
    <button type="button" id="zdjecie" hidden>📷 Zdjęcie kodu</button>
    <button type="button" id="na-zywo" hidden>🎥 Skanuj na żywo</button>
  </div>
  <video id="wideo" playsinline muted></video>

  <div class="karta" id="karta" aria-live="polite">
    <div class="nm" id="k-nm"></div>
    <div class="mt" id="k-mt"></div>
    <div class="big" id="k-big"></div>
    <div class="przyp" id="przyp">
      <div class="zakl" role="tablist">
        <button type="button" id="zk-ist" class="on" role="tab">🔗 Istniejący produkt</button>
        <button type="button" id="zk-now" role="tab">＋ Nowy produkt</button>
      </div>
      <div id="pn-ist">
        <input type="search" id="szukaj" autocomplete="off" placeholder="Szukaj produktu: nazwa albo SKU…" aria-label="Szukaj produktu">
        <ul class="wyniki" id="wyniki"></ul>
      </div>
      <div id="pn-now" hidden>
        <div class="nowy-f">
          <label class="field cala"><span>Nazwa produktu</span><input type="text" id="nw-nazwa" maxlength="160" autocomplete="off"></label>
          <label class="field"><span>Kategoria</span>
            <select id="nw-kat"><option value="">— wybierz —</option>
              <?php foreach ($kategorie as $kt): ?><option value="<?= (int) $kt['id'] ?>"><?= h((string) $kt['name']) ?></option><?php endforeach; ?>
            </select></label>
          <label class="field"><span>Cena brutto (zł) — opcjonalnie</span><input type="text" id="nw-cena" inputmode="decimal" placeholder="0,00"></label>
        </div>
        <p class="muted" style="font-size:13px;margin:6px 0 8px">Produkt powstaje jako szkic (niewidoczny w sklepie) z tym kodem EAN. Zdjęcie i opis uzupełnisz w Produkty.</p>
        <button type="button" class="primary" id="nw-ok">Utwórz produkt z kodem <span id="nw-kod"></span></button>
      </div>
    </div>
  </div>
</div>

<?php if ($tryb !== 'sprawdz'): ?>
<div class="panel">
  <h2><?= $tryb === 'przyjecie' ? 'Przyjmowane na stan' : 'Schodzi ze stanu' ?></h2>
  <?php if ($tryb === 'przyjecie'): ?>
  <label class="od-razu"><input type="checkbox" id="od-razu"> <span><b>Od razu na stan</b> — każdy skan od razu dodaje 1 szt. w Magazynie (jeden dokument PZ na całe skanowanie)</span></label>
  <p class="od-razu-info" id="od-razu-info" hidden></p>
  <?php endif; ?>
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

<script src="<?= h(console_asset('kamera.js')) ?>"></script>
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
      pokaz(false, 'Nieznany kod ' + kod, 'Żaden produkt nie ma tego EAN.' + (ADMIN ? ' Przypisz go do produktu z bazy albo dodaj nowy produkt — następnym razem skaner go rozpozna.' : ' Poproś Centralę o przypisanie kodu.'), '');
      if (ADMIN) otworzPrzypisanie(kod);
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
    if (odRazu()) { naStan(p, 1); return; }
    if (ln) ln.qty++; else { ln = { p: p, qty: 1, cost: '' }; linie.push(ln); }
    pokaz(true, p.nom, 'EAN ' + p.ean + ' · stan teraz ' + p.stock + ' szt.',
          (TRYB === 'przyjecie' ? '+' : '−') + ln.qty + ' szt.');
    rysuj(p.id);
  }

  // « Od razu na stan » : chaque scan part tout de suite au serveur (PZ de la séance).
  var chk = document.getElementById('od-razu'), info = document.getElementById('od-razu-info');
  var dokument = 0;
  function odRazu() { return TRYB === 'przyjecie' && chk && chk.checked; }
  if (chk) {
    var zap = localStorage.getItem('wsm-skaner-od-razu');
    chk.checked = zap === null ? true : zap === '1';
    var ustaw = function () { document.body.classList.toggle('tryb-od-razu', chk.checked); rysuj(); };
    chk.addEventListener('change', function () { localStorage.setItem('wsm-skaner-od-razu', chk.checked ? '1' : '0'); ustaw(); pole.focus(); });
    document.body.classList.toggle('tryb-od-razu', chk.checked);
  }
  function naStan(p, q) {
    if (!ADMIN) { bip(false); pokaz(false, 'Brak uprawnień', 'Tylko rola Centrala może zmieniać magazyn.', ''); return; }
    var f = document.getElementById('zapis');
    var fd = new FormData(); fd.append('_t', CSRF); fd.append('akcja', 'przyjmij1'); fd.append('tryb', TRYB);
    fd.append('product_id', p.id); fd.append('qty', String(q)); fd.append('doc_id', String(dokument));
    fd.append('partner', f ? (f.elements.partner || {}).value || '' : ''); fd.append('ref', f ? (f.elements.ref || {}).value || '' : '');
    var ln = linie.find(function (x) { return x.p.id === p.id; });
    fd.append('cost', ln ? ln.cost : '');
    fetch('skaner.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.err) { bip(false); pokaz(false, 'Nie zapisano', j.err, ''); return; }
        dokument = j.doc_id;
        if (!ln) { ln = { p: p, qty: 0, cost: '' }; linie.push(ln); }
        ln.qty += q; p.stock = j.stock;
        if (ln.qty <= 0) linie.splice(linie.indexOf(ln), 1);
        if (q > 0) bip(true);
        pokaz(true, p.nom, 'EAN ' + p.ean + ' · zapisane w ' + j.number, 'Stan: ' + j.stock + ' szt.');
        info.hidden = false;
        info.textContent = '✓ Na stanie od razu — dokument ' + j.number + ', razem ' + j.units + ' szt.';
        rysuj(p.id);
      })
      .catch(function () { bip(false); pokaz(false, 'Błąd połączenia', 'Skan nie zapisany — spróbuj jeszcze raz.', ''); });
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
      if (odRazu()) {
        q.textContent = ln.qty + ' szt.';
      } else {
        var iq = document.createElement('input'); iq.type = 'number'; iq.min = '0'; iq.value = ln.qty;
        iq.setAttribute('aria-label', 'Ilość — ' + ln.p.nom);
        iq.addEventListener('input', function () { ln.qty = Math.max(0, parseInt(iq.value || '0', 10)); suma(); });
        q.appendChild(iq);
      }
      tr.appendChild(q);
      if (TRYB === 'przyjecie') {
        var c = document.createElement('td'); c.className = 'num';
        var ic = document.createElement('input'); ic.type = 'text'; ic.inputMode = 'decimal'; ic.placeholder = '0,00'; ic.value = ln.cost;
        ic.setAttribute('aria-label', 'Cena zakupu — ' + ln.p.nom);
        ic.addEventListener('input', function () { ln.cost = ic.value; suma(); });
        c.appendChild(ic); tr.appendChild(c);
      }
      var x = document.createElement('td'); x.className = 'num';
      var bx = document.createElement('button'); bx.type = 'button'; bx.textContent = '✕'; bx.title = 'Usuń z listy';
      if (odRazu()) { bx.textContent = '−1'; bx.title = 'Cofnij jeden skan (zdejmuje 1 szt. ze stanu)'; }
      bx.addEventListener('click', function () {
        if (odRazu()) { naStan(ln.p, -1); pole.focus(); return; }
        linie.splice(i, 1); rysuj(); pole.focus();
      });
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

  // Un code inconnu : le rattacher à un produit de la base, ou créer le produit.
  var szukaj = document.getElementById('szukaj'), wyniki = document.getElementById('wyniki');
  function zakladka(nowy) {
    document.getElementById('zk-ist').classList.toggle('on', !nowy);
    document.getElementById('zk-now').classList.toggle('on', nowy);
    document.getElementById('pn-ist').hidden = nowy;
    document.getElementById('pn-now').hidden = !nowy;
    (nowy ? document.getElementById('nw-nazwa') : szukaj).focus();
  }
  document.getElementById('zk-ist').addEventListener('click', function () { zakladka(false); });
  document.getElementById('zk-now').addEventListener('click', function () { zakladka(true); });
  function bezAkc(t) { return (t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/g, 'l'); }
  function listuj() {
    var q = bezAkc(szukaj.value.trim()).split(/\s+/).filter(Boolean);
    var lista = produkty.filter(function (p) {
      if (!p.active) return false;
      var t = bezAkc(p.nom + ' ' + p.sku + ' ' + p.ean);
      return q.every(function (w) { return t.indexOf(w) >= 0; });
    }).sort(function (a, b) { return (a.ean ? 1 : 0) - (b.ean ? 1 : 0) || a.nom.localeCompare(b.nom, 'pl'); });
    wyniki.innerHTML = '';
    if (!lista.length) {
      var z = document.createElement('li'); z.className = 'wy-zero';
      z.textContent = 'Nic nie znaleziono — dodaj go jako nowy produkt.';
      wyniki.appendChild(z); return;
    }
    lista.slice(0, 40).forEach(function (p) {
      var li = document.createElement('li'), bt = document.createElement('button');
      bt.type = 'button';
      var n = document.createElement('span'); n.textContent = p.nom;
      var m = document.createElement('span'); m.className = 'wy-m';
      m.textContent = p.ean ? 'ma EAN ' + p.ean : (p.sku || 'bez EAN');
      bt.appendChild(n); bt.appendChild(m);
      bt.addEventListener('click', function () {
        if (p.ean && !confirm('„' + p.nom + '” ma już kod ' + p.ean + '. Zastąpić go kodem ' + ostatniNieznany + '?')) return;
        wyslij({ akcja: 'przypisz', product_id: p.id });
      });
      li.appendChild(bt); wyniki.appendChild(li);
    });
  }
  szukaj.addEventListener('input', listuj);
  szukaj.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
  document.getElementById('nw-ok').addEventListener('click', function () {
    var nazwa = document.getElementById('nw-nazwa').value.trim(), kat = document.getElementById('nw-kat').value;
    if (!nazwa) { document.getElementById('nw-nazwa').focus(); return; }
    if (!kat) { document.getElementById('nw-kat').focus(); return; }
    wyslij({ akcja: 'nowy', nazwa: nazwa, category_id: kat, cena: document.getElementById('nw-cena').value });
  });
  function otworzPrzypisanie(kod) {
    document.getElementById('nw-kod').textContent = kod;
    document.getElementById('nw-nazwa').value = ''; document.getElementById('nw-cena').value = '';
    szukaj.value = ''; listuj();
    przyp.classList.add('on');
    zakladka(false);
  }
  function wyslij(pola) {
    if (!ostatniNieznany) return;
    var fd = new FormData(); fd.append('_t', CSRF); fd.append('tryb', TRYB); fd.append('ean', ostatniNieznany);
    Object.keys(pola).forEach(function (k) { fd.append(k, pola[k]); });
    fetch('skaner.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.err) { bip(false); document.getElementById('k-mt').textContent = '✕ ' + j.err; return; }
        if (pola.akcja === 'nowy') produkty.push(j.produkt);
        else produkty.forEach(function (p) { if (p.id === pola.product_id) p.ean = j.produkt.ean; });
        indeks();
        var k = ostatniNieznany; ostatniNieznany = '';
        skan(k);
        if (pola.akcja === 'nowy') {
          var mt = document.getElementById('k-mt');
          var l = document.createElement('a'); l.href = 'produkty.php?id=' + encodeURIComponent(j.produkt.id);
          l.textContent = ' · nowy szkic — uzupełnij zdjęcie i opis →'; mt.appendChild(l);
        }
        pole.focus();
      })
      .catch(function () { bip(false); pokaz(false, 'Błąd połączenia', 'Spróbuj jeszcze raz.', ''); });
  }

  // L'appareil photo du téléphone (kamera.js) : photo du code partout, vidéo en direct en https.
  wsmKamera({ zdjecie: document.getElementById('zdjecie'), naZywo: document.getElementById('na-zywo'),
              wideo: document.getElementById('wideo'),
              kod: function (k) { skan(k); pole.focus(); },
              blad: function (m) { bip(false); pokaz(false, 'Aparat', m, ''); } });
  rysuj();
})();
</script>
<?php console_foot();
