<?php
// ============================================================================
//  przyjecie.php — recevoir une livraison à partir de la facture du fournisseur.
//
//  On dépose le PDF (ou le XML KSeF) ; la facture est lue, chaque ligne est
//  rapprochée d'un produit du site, et l'écran montre côte à côte ce que dit
//  la facture et ce qui va entrer en stock. On corrige ce qui doit l'être
//  (le produit, le facteur « 1 kg = ? sztuk », une ligne « Transport » à
//  ignorer), puis UN clic écrit le PZ — tout ou rien.
//
//  Ce que la facture dit n'est jamais réécrit : les chiffres affichés sont
//  ceux du document ; seuls l'en-tête (numéro, date, fournisseur, si la
//  lecture s'est trompée) et le rapprochement se corrigent.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/zakupy.php';
wsm_zak_ensure($pdo);

$acteur = (string) ($me['nom'] ?? '');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$flash = ''; $kind = 'ok';

// Le fichier de la facture, tel qu'il a été reçu (lecture : tout rôle qui voit l'écran).
if ($id > 0 && isset($_GET['plik'])) {
    $inv = wsm_zak_get($pdo, $id);
    $f = $inv ? wsm_zak_file($inv) : null;
    if (!$f) { http_response_code(404); exit('Brak pliku.'); }
    $pdf = str_ends_with($f, '.pdf');
    header('X-Content-Type-Options: nosniff');
    if ($pdf) {
        // En ligne, SANS « sandbox » : Chrome et Edge refusent d'ouvrir leur
        // lecteur PDF dans un document sandboxé (page blanche).
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="faktura-' . (int) $inv['id'] . '.pdf"');
    } else {
        // Un XML s'enregistre : affiché, une feuille de style XSL qu'il
        // déclarerait s'exécuterait sur l'origine de la console.
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="faktura-' . (int) $inv['id'] . '.xml"');
        header("Content-Security-Policy: default-src 'none'; sandbox");
    }
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    $cible = 'przyjecie.php' . ($id > 0 ? '?id=' . $id : '');
    if (!$isAdmin) {
        $flash = 'Twoja rola może tylko przeglądać faktury zakupu.'; $kind = 'err';
    } elseif (isset($_POST['wczytaj'])) {
        $up = $_FILES['plik'] ?? [];
        $err = (int) ($up['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) { $flash = 'Wybierz plik faktury (PDF albo XML z KSeF).'; $kind = 'err'; }
        elseif ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            $flash = 'Plik za duży dla serwera (limit ' . ini_get('upload_max_filesize') . ').'; $kind = 'err';
        } elseif ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($up['tmp_name'] ?? ''))) {
            $flash = 'Błąd przesyłania pliku (' . $err . ').'; $kind = 'err';
        } else {
            [$nid, $m] = wsm_zak_import($pdo, (string) file_get_contents((string) $up['tmp_name']), (string) ($up['name'] ?? ''), $acteur);
            if ($nid > 0) {
                $flash = $m ?? 'Faktura wczytana. Sprawdź dopasowania i przyjmij na stan.';
                $kind = $m ? 'warn' : 'ok';
                $cible = 'przyjecie.php?id=' . $nid;
            } else { $flash = (string) $m; $kind = 'err'; }
        }
    } elseif ($id > 0 && (isset($_POST['zapisz']) || isset($_POST['przyjmij']))) {
        [$ok, $m] = wsm_zak_save($pdo, $id, [
            'supplier_name' => (string) ($_POST['supplier_name'] ?? ''), 'supplier_nip' => (string) ($_POST['supplier_nip'] ?? ''),
            'number' => (string) ($_POST['number'] ?? ''), 'issued_at' => (string) ($_POST['issued_at'] ?? ''),
        ], (array) ($_POST['l'] ?? []));
        $flash = $m; $kind = $ok ? 'ok' : 'err';
        if ($ok && isset($_POST['przyjmij'])) {
            [$doc, $e] = wsm_zak_post($pdo, $id, $acteur, (string) ($_POST['data_przyjecia'] ?? ''), !empty($_POST['koszt']));
            if ($doc) {
                $flash = 'Przyjęto na stan: ' . $doc['number'] . ' — ' . (int) $doc['units'] . ' szt.';
                $cible = 'magazyn.php?dok=' . (int) $doc['id'];
            } else { $flash = (string) $e; $kind = 'err'; }
        }
    } elseif ($id > 0 && isset($_POST['odrzuc'])) {
        [$ok, $m] = wsm_zak_reject($pdo, $id, $acteur);
        $flash = $m; $kind = $ok ? 'ok' : 'err';
    }
    $sep = str_contains($cible, '?') ? '&' : '?';
    header('Location: ' . $cible . $sep . 'ok=' . rawurlencode($flash) . '&k=' . $kind, true, 303);
    exit;
}
if (isset($_GET['ok'])) { $flash = (string) $_GET['ok']; $kind = in_array($_GET['k'] ?? '', ['ok', 'err', 'warn'], true) ? (string) $_GET['k'] : 'ok'; }

$inv = $id > 0 ? wsm_zak_get($pdo, $id) : null;
$produits = wsm_zak_produits($pdo);
$szkic = $inv && $inv['status'] === 'szkic' && $isAdmin;
$dopLbl = ['pamiec' => ['ok', 'zapamiętane'], 'ean' => ['ok', 'EAN'], 'sku' => ['ok', 'kod = SKU'],
           'nazwa' => ['wait', 'podobna nazwa'], 'reczne' => ['', 'wybrane ręcznie'], '' => ['bad', 'brak']];
$ilosc = fn(float $q): string => rtrim(rtrim(number_format($q, 3, ',', ' '), '0'), ',');

console_head($inv ? 'Faktura ' . ($inv['number'] ?: '#' . $inv['id']) : 'Faktury zakupu', $me, <<<'CSS'
  .why { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0 0 14px; max-width: 90ch; }
  .map td { vertical-align: top; padding: 8px 6px; }
  .map td small { display: block; color: var(--text-muted); font-size: 12px; margin-top: 2px; }
  .map select { width: 100%; min-width: 200px; }
  .map input.f { width: 84px; text-align: right; }
  .map tr.pomin td:not(.keep) { opacity: .45; }
  .map .nst { font-family: var(--font-mono); font-weight: 600; white-space: nowrap; }
  .map .nst.zle { color: var(--danger, #b42318); }
  .map tfoot td { font-weight: 600; border-top: 2px solid var(--border-subtle); }
  .uwagi { margin: 0 0 16px; padding: 0; list-style: none; }
  .uwagi li { padding: 8px 12px; border-radius: 10px; margin-bottom: 6px; font-size: 13.5px; }
  .uwagi li.blad { background: #fdecec; color: #8a1c1c; }
  .uwagi li.uwaga { background: #fff4e0; color: #7a4b00; }
  .drop { border: 2px dashed var(--border-subtle); border-radius: 14px; padding: 18px; display: grid; gap: 10px; }
  .num { text-align: right; }
CSS);
console_flash($flash, $kind);
console_crumbs($inv
    ? ['Pulpit' => 'pulpit.php', 'Magazyn' => 'magazyn.php', 'Faktury zakupu' => 'przyjecie.php', ($inv['number'] ?: '#' . $inv['id']) => null]
    : ['Pulpit' => 'pulpit.php', 'Magazyn' => 'magazyn.php', 'Faktury zakupu' => null]);

if (!$inv): ?>
<?php if ($isAdmin): ?>
<div class="panel">
  <h2>Wczytaj fakturę zakupu</h2>
  <p class="why">PDF od dostawcy albo plik XML z KSeF. Faktura zostanie odczytana pozycja po pozycji i dopasowana do produktów
    sklepu; nic nie wejdzie na stan, dopóki nie sprawdzisz i nie klikniesz „Przyjmij”. XML z KSeF czyta się dokładnie i bez
    internetu; PDF odczytuje Claude<?= wsm_tr_enabled() ? '' : ' — <b>potrzebny klucz Claude</b> (Integracje), bez niego wczytasz tylko XML' ?>.</p>
  <form method="post" enctype="multipart/form-data" class="drop" onsubmit="var b=this.querySelector('button');b.disabled=true;b.textContent='Czytam fakturę…';">
    <?= console_csrf_field() ?>
    <input type="hidden" name="wczytaj" value="1">
    <input type="file" name="plik" accept="application/pdf,.pdf,.xml,application/xml,text/xml" required>
    <div class="actions"><button class="btn btn--brand" type="submit">Wczytaj i dopasuj</button></div>
  </form>
</div>
<?php endif; ?>
<div class="panel">
  <h2>Faktury zakupu</h2>
  <div class="tablewrap"><table class="rwd">
    <thead><tr><th>Faktura</th><th>Dostawca</th><th>Data</th><th class="num">Netto</th><th class="num">Brutto</th><th>Stan</th><th>PZ</th></tr></thead>
    <tbody>
    <?php $lista = wsm_zak_list($pdo); if (!$lista): ?><tr><td colspan="7" class="muted">Jeszcze żadnej — wczytaj pierwszą fakturę powyżej.</td></tr><?php endif; ?>
    <?php foreach ($lista as $r): ?>
    <tr>
      <td data-l="Faktura"><a class="code" href="przyjecie.php?id=<?= (int) $r['id'] ?>"><?= h($r['number'] ?: '#' . $r['id']) ?></a>
        <small class="muted"><?= h(strtoupper((string) $r['source'])) ?> · <?= (int) $r['pozycji'] ?> poz.</small></td>
      <td data-l="Dostawca"><?= h((string) $r['supplier_name']) ?: '—' ?></td>
      <td data-l="Data" class="num"><?= h((string) $r['issued_at']) ?: '—' ?></td>
      <td data-l="Netto" class="num"><?= h(pln((int) $r['net'])) ?></td>
      <td data-l="Brutto" class="num"><?= h(pln((int) $r['gross'])) ?></td>
      <td data-l="Stan"><span class="tag <?= $r['status'] === 'przyjeta' ? 'ok' : ($r['status'] === 'szkic' ? 'wait' : '') ?>"><?= h(WSM_ZAK_STATUTS[$r['status']] ?? $r['status']) ?></span>
        <?php if ($r['status'] === 'szkic' && (int) $r['bez_produktu']): ?><small class="muted"><?= (int) $r['bez_produktu'] ?> bez produktu</small><?php endif; ?></td>
      <td data-l="PZ"><?= $r['doc_id'] ? '<a class="code" href="magazyn.php?dok=' . (int) $r['doc_id'] . '">' . h((string) $r['pz']) . '</a>' : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php console_foot(); exit; endif;

$blocages = $inv['status'] === 'szkic' ? wsm_zak_blocages($inv) : [];
$sumN = array_sum(array_map('intval', array_column($inv['lines'], 'net')));
$sumV = array_sum(array_map('intval', array_column($inv['lines'], 'vat')));
$sumB = array_sum(array_map('intval', array_column($inv['lines'], 'gross')));
$dis = $szkic ? '' : ' disabled';
?>
<form method="post" id="fz">
  <?= console_csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
  <div class="panel">
    <h2>Faktura <?= h($inv['number'] ?: 'bez numeru') ?>
      <span class="tag <?= $inv['status'] === 'przyjeta' ? 'ok' : ($inv['status'] === 'szkic' ? 'wait' : '') ?>"><?= h(WSM_ZAK_STATUTS[$inv['status']] ?? $inv['status']) ?></span></h2>
    <div class="grid2">
      <label class="field"><span>Dostawca</span><input type="text" name="supplier_name" maxlength="160" value="<?= h((string) $inv['supplier_name']) ?>"<?= $dis ?>></label>
      <label class="field"><span>NIP dostawcy</span><input type="text" name="supplier_nip" maxlength="20" value="<?= h((string) $inv['supplier_nip']) ?>"<?= $dis ?>></label>
      <label class="field"><span>Numer faktury</span><input type="text" name="number" maxlength="60" value="<?= h((string) $inv['number']) ?>"<?= $dis ?>></label>
      <label class="field"><span>Data wystawienia</span><input type="date" name="issued_at" value="<?= h((string) $inv['issued_at']) ?>"<?= $dis ?>></label>
    </div>
    <dl class="kv">
      <dt>Razem z faktury</dt><dd>netto <b><?= h(pln((int) $inv['net'])) ?></b> · VAT <?= h(pln((int) $inv['vat'])) ?> · brutto <b><?= h(pln((int) $inv['gross'])) ?></b>
        <?= $inv['currency'] !== 'PLN' ? ' · <span class="tag wait">' . h((string) $inv['currency']) . '</span>' : '' ?></dd>
      <dt>Źródło</dt><dd><?= $inv['source'] === 'ksef' ? 'XML z KSeF (odczyt dokładny)' : 'PDF odczytany przez Claude — porównaj z dokumentem' ?>
        <?php if (wsm_zak_file($inv)): ?> · <a class="code" href="przyjecie.php?id=<?= (int) $inv['id'] ?>&amp;plik=1" target="_blank" rel="noopener">otwórz plik ↗</a><?php endif; ?></dd>
      <?php if ($inv['doc_id']): ?><dt>Przyjęcie</dt><dd><a class="code" href="magazyn.php?dok=<?= (int) $inv['doc_id'] ?>">PZ — zobacz dokument</a> · <?= h((string) $inv['posted_at']) ?></dd><?php endif; ?>
    </dl>
  </div>

  <?php if ($inv['uwagi'] || $blocages): ?>
  <ul class="uwagi">
    <?php foreach ($blocages as $t): ?><li class="blad"><?= h($t) ?></li><?php endforeach; ?>
    <?php foreach ($inv['uwagi'] as $u): if (($u['poziom'] ?? '') === 'blad') continue; ?>
    <li class="uwaga"><?= !empty($u['lp']) ? 'Pozycja ' . (int) $u['lp'] . ': ' : '' ?><?= h((string) $u['tekst']) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <div class="panel">
    <h2>Pozycje → produkty sklepu</h2>
    <p class="why">Po lewej to, co mówi faktura; po prawej to, co wejdzie na stan. <b>Przelicznik</b> mówi, ile sztuk sklepu
      to jedna jednostka z faktury (25 kg czekolady w workach po 2,5 kg = przelicznik 0,4 → 10 worków). Transport,
      opakowania i kaucje zaznacz „pomiń”. Dopasowanie zapamiętuje się dla tego dostawcy — następna faktura dopasuje się sama.</p>
    <div class="tablewrap"><table class="map">
      <thead><tr><th>Lp</th><th>Pozycja z faktury</th><th class="num">Ilość</th><th class="num">Cena netto</th><th class="num">VAT</th>
        <th class="num">Netto / brutto</th><th>Produkt w sklepie</th><th class="num">Przelicznik</th><th class="num">Na stan</th><th>Pomiń</th></tr></thead>
      <tbody>
      <?php foreach ($inv['lines'] as $l): $lid = (int) $l['id']; [$cl, $txt] = $dopLbl[(string) $l['dopasowanie']] ?? ['', (string) $l['dopasowanie']]; ?>
      <tr data-q="<?= h((string) (float) $l['qty']) ?>"<?= (int) $l['skip'] ? ' class="pomin"' : '' ?>>
        <td><?= (int) $l['lp'] ?></td>
        <td><?= h((string) $l['name']) ?>
          <?php if ($l['code'] !== '' || $l['ean'] !== ''): ?><small><?= h(trim(($l['code'] !== '' ? 'kod ' . $l['code'] : '') . ($l['ean'] !== '' ? ' · EAN ' . $l['ean'] : ''), ' ·')) ?></small><?php endif; ?></td>
        <td class="num"><?= h($ilosc((float) $l['qty'])) ?> <?= h((string) $l['unit']) ?></td>
        <td class="num"><?= h(pln((int) $l['unit_net'])) ?></td>
        <td class="num"><?= h(ctype_digit((string) $l['vat_rate']) ? $l['vat_rate'] . '%' : ((string) $l['vat_rate'] ?: '?')) ?></td>
        <td class="num"><?= h(pln((int) $l['net'])) ?><small><?= h(pln((int) $l['gross'])) ?></small></td>
        <td class="keep">
          <select name="l[<?= $lid ?>][product_id]" aria-label="Produkt dla pozycji <?= (int) $l['lp'] ?>"<?= $dis ?>>
            <option value="">— wybierz produkt —</option>
            <?php foreach ($produits as $pid => $p): ?>
            <option value="<?= h((string) $pid) ?>"<?= (string) $l['product_id'] === (string) $pid ? ' selected' : '' ?>><?= h((string) $p['nom']) ?></option>
            <?php endforeach; ?>
          </select>
          <small><span class="tag <?= h($cl) ?>"><?= h($txt) ?></span><?= $l['dopasowanie'] === 'nazwa' ? ' ' . (int) $l['score'] . '% — sprawdź' : '' ?>
            <?= (string) $l['product_id'] !== '' && isset($produits[(string) $l['product_id']]) ? ' · stan ' . (int) $produits[(string) $l['product_id']]['stock'] : '' ?></small>
        </td>
        <td class="num keep"><input class="f" type="text" inputmode="decimal" name="l[<?= $lid ?>][factor]"
              value="<?= h(rtrim(rtrim(number_format((float) $l['factor'], 8, ',', ''), '0'), ',')) ?>" aria-label="Przelicznik dla pozycji <?= (int) $l['lp'] ?>"<?= $dis ?>></td>
        <td class="num"><span class="nst"><?= (int) $l['skip'] ? '—' : ((string) $l['product_id'] !== '' ? (int) $l['stock_qty'] . ' szt.' : '—') ?></span></td>
        <td class="keep"><input type="checkbox" name="l[<?= $lid ?>][skip]" value="1"<?= (int) $l['skip'] ? ' checked' : '' ?> aria-label="Pomiń pozycję <?= (int) $l['lp'] ?>"<?= $dis ?>></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="5">Suma pozycji</td><td class="num"><?= h(pln($sumN)) ?><small><?= h(pln($sumB)) ?></small></td>
        <td colspan="4"><?= abs($sumN - (int) $inv['net']) > 2 * max(1, count($inv['lines'])) ? '<span class="tag bad">≠ razem z faktury ' . h(pln((int) $inv['net'])) . '</span>'
                                                                                              : '<span class="tag ok">zgadza się z fakturą</span>' ?> · VAT <?= h(pln($sumV)) ?></td></tr></tfoot>
    </table></div>

    <?php if ($szkic): ?>
    <div class="grid2" style="margin-top:14px">
      <label class="field"><span>Data przyjęcia na magazyn</span><input type="date" name="data_przyjecia" value="<?= h(date('Y-m-d')) ?>"></label>
      <label class="field" style="align-self:end"><span><input type="checkbox" name="koszt" value="1" checked> Zapisz cenę zakupu netto w kartotece produktu (do marż)</span></label>
    </div>
    <div class="actions">
      <button type="submit" name="zapisz" value="1">Zapisz zmiany</button>
      <button class="btn btn--brand" type="submit" name="przyjmij" value="1">Przyjmij na stan (PZ)</button>
      <button type="submit" name="odrzuc" value="1" formnovalidate onclick="return confirm('Odrzucić tę fakturę? Nic nie wejdzie na stan.')">Odrzuć</button>
    </div>
    <?php elseif ($inv['status'] === 'szkic'): ?>
    <p class="hint">Twoja rola może tylko przeglądać. Przyjęcie na stan robi Magazyn albo Administrator.</p>
    <?php endif; ?>
  </div>
</form>

<?php if ($szkic): ?>
<script>
// La quantité en sztuk suit le facteur et le produit, sans recharger la page.
(function () {
  document.querySelectorAll('#fz tbody tr').forEach(function (tr) {
    var q = parseFloat(tr.dataset.q || '0'), f = tr.querySelector('input.f'), s = tr.querySelector('select'),
        skip = tr.querySelector('input[type=checkbox]'), out = tr.querySelector('.nst');
    function maj() {
      tr.classList.toggle('pomin', skip.checked);
      if (skip.checked || !s.value) { out.textContent = '—'; out.classList.remove('zle'); return; }
      var x = q * parseFloat((f.value || '1').replace(',', '.')), n = Math.round(x),
          zle = Math.abs(x - n) > Math.max(0.001, 0.005 * Math.abs(n));
      out.textContent = (zle ? x.toFixed(2).replace('.', ',') : n) + ' szt.';
      out.classList.toggle('zle', zle || n <= 0);
    }
    [f, s, skip].forEach(function (el) { el.addEventListener('input', maj); el.addEventListener('change', maj); });
  });
})();
</script>
<?php endif; ?>
<?php console_foot();
