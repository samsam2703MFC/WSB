<?php
// ============================================================================
//  magazyn.php — le magasin : ce qui entre, ce qui sort, et le détail article
//  par article.
//
//  Trois idées de fond :
//
//   1. UNE ENTRÉE EST UN DOCUMENT, PAS UN AJUSTEMENT. Une livraison arrive
//      d'un fournisseur, sur une facture d'achat, avec plusieurs articles.
//      La saisir article par article fait perdre exactement ce qui compte :
//      que ces douze sacs et ces trois cartons sont arrivés ENSEMBLE, ce
//      jour-là, sur ce bon. Le formulaire est donc multi-lignes, et
//      l'enregistrement est tout-ou-rien.
//
//   2. UNE SORTIE S'ACCOMPAGNE D'UN BON DE LIVRAISON. La marchandise quitte
//      le stock au moment de la commande — c'est ce qui empêche de vendre
//      deux fois le même sac. Le WZ ne rebouge donc rien : il nomme ce qui
//      part, lui donne un numéro, et rattache les mouvements déjà écrits.
//
//   3. LE DÉTAIL EST À UN CLIC. Chaque article se déplie sur son propre
//      historique : d'où viennent les entrées, où sont parties les sorties,
//      qui a corrigé et pourquoi.
//
//  Lecture : tout compte actif. Écriture : Centrala.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/stock.php';
require_once $API . '/zakupy.php';
wsm_zak_ensure($pdo);

$flash = ''; $kind = 'ok';
const WSM_PZ_ROWS = 8;                 // lignes offertes à la saisie d'un bon

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Sans ce jeton, une page tierce fait poster ce formulaire par le
    // navigateur de quelqu'un qui a sa session ouverte, et la console
    // execute la demande comme si elle venait de lui.
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać magazyn.'; $kind = 'err';
    } elseif (isset($_POST['przyjmij'])) {
        $lines = [];
        foreach ((array) ($_POST['line_product'] ?? []) as $i => $pidLine) {
            $lines[] = [
                'product_id' => (string) $pidLine,
                'qty'        => (int) (($_POST['line_qty'][$i] ?? 0)),
                'unit_cost'  => (int) round(((float) str_replace(',', '.', (string) ($_POST['line_cost'][$i] ?? 0))) * 100),
            ];
        }
        [$doc, $err] = wsm_stock_receive($pdo, [
            'partner'   => (string) ($_POST['partner'] ?? ''),
            'ref'       => (string) ($_POST['ref'] ?? ''),
            'issued_at' => (string) ($_POST['issued_at'] ?? ''),
            'note'      => (string) ($_POST['note'] ?? ''),
            'actor'     => (string) ($me['nom'] ?? ''),
        ], $lines);
        if ($err) { $flash = $err; $kind = 'err'; }
        else {
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Przyjęcie', $doc['number'] . ' · ' . $doc['units'] . ' szt.', 'Sieć');
            $flash = 'Zapisano ' . $doc['number'] . ' — ' . $doc['units'] . ' szt.';
        }
    } elseif (isset($_POST['koryguj'])) {
        $pid    = (string) ($_POST['product_id'] ?? '');
        $delta  = (int) ($_POST['delta'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $st = $pdo->prepare("SELECT nom FROM wsm_products WHERE id = ?");
        $st->execute([$pid]);
        $name = (string) $st->fetchColumn();
        if ($name === '')       { $flash = 'Nie znaleziono produktu.'; $kind = 'err'; }
        elseif ($delta === 0)   { $flash = 'Korekta zerowa nic nie zmienia.'; $kind = 'err'; }
        elseif ($reason === '') { $flash = 'Podaj powód korekty.'; $kind = 'err'; }
        else {
            $after = wsm_stock_apply($pdo, $pid, $delta, $delta > 0 ? 'zwrot' : 'korekta', [
                'reason' => $reason, 'note' => (string) ($_POST['note'] ?? ''),
                'actor'  => (string) ($me['nom'] ?? ''),
            ]);
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Korekta magazynu', $pid . ' ' . sprintf('%+d', $delta), 'Sieć');
            $flash = 'Zapisano korektę ' . sprintf('%+d', $delta) . ' — ' . $name . ' (stan: ' . $after . ').';
        }
    }
}

// Le message d'un écran voisin (przyjecie.php renvoie ici avec le PZ créé).
if ($flash === '' && isset($_GET['ok'])) {
    $flash = (string) $_GET['ok'];
    $kind = in_array($_GET['k'] ?? '', ['ok', 'err', 'warn'], true) ? (string) $_GET['k'] : 'ok';
}
$doc = isset($_GET['dok']) ? wsm_stock_doc_by_id($pdo, (int) $_GET['dok']) : null;
if ($doc && isset($_GET['druk'])) { include __DIR__ . '/magazyn_druk.php'; exit; }
// Le PZ né d'une facture : ses lignes de facture, par mouvement (TVA, net, brut).
$fz = $doc && $doc['kind'] === 'PZ' ? wsm_zak_by_doc($pdo, (int) $doc['id']) : null;
$fzLignes = $fz ? array_column(array_filter($fz['lines'], fn($l) => $l['move_id'] !== null), null, 'move_id') : [];
$szkice = wsm_zak_list($pdo, 'szkic', 20);

$ov    = wsm_stock_overview($pdo);
$kpis  = wsm_stock_kpis($pdo);
$docs  = wsm_stock_docs_list($pdo, ['kind' => (string) ($_GET['dk'] ?? ''), 'limit' => 40]);
$from  = (string) ($_GET['from'] ?? date('Y-m-d', time() - 30 * 86400));
$to    = (string) ($_GET['to'] ?? date('Y-m-d'));
$moves = wsm_stock_moves($pdo, [
    'from' => $from, 'to' => $to,
    'kind' => (string) ($_GET['kind'] ?? ''),
    'product_id' => (string) ($_GET['product_id'] ?? ''),
    'limit' => 200,
]);

// Le détail par article : les derniers mouvements de chacun, prêts à déplier.
$perProduct = [];
foreach (wsm_stock_moves($pdo, ['limit' => 500]) as $m) {
    $perProduct[(string) $m['product_id']][] = $m;
}

$statusLabel = ['brak' => 'Brak', 'dlug' => 'Dług wobec klientów', 'krytyczny' => 'Krytyczny',
                'niski' => 'Niski', 'ok' => 'OK'];
$statusTag   = ['brak' => 'bad', 'dlug' => 'no', 'krytyczny' => 'bad', 'niski' => 'wait', 'ok' => 'ok'];

console_head('Magazyn', $me, <<<'CSS'
  .why { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0 0 14px; }
  /* Le détail d'un article : replié par défaut, un clic pour l'ouvrir. */
  .art { border-bottom: 1px solid var(--border-subtle); }
  .art:last-child { border-bottom: 0; }
  .art > summary { display: grid; grid-template-columns: 1fr auto; gap: 4px 14px;
                   align-items: center; padding: 12px 4px; cursor: pointer; list-style: none; }
  .art > summary::-webkit-details-marker { display: none; }
  .art > summary::after { content: "▾"; color: var(--text-muted); font-size: 12px; }
  .art[open] > summary::after { content: "▴"; }
  .art .nm { font-weight: 600; color: var(--text-strong); }
  .art .fig { display: flex; flex-wrap: wrap; gap: 6px 16px; font-family: var(--font-mono);
              font-size: 12px; color: var(--text-muted); margin-top: 3px; }
  .art .fig b { color: var(--text-strong); }
  .art .inner { padding: 4px 4px 18px; }
  @media (min-width: 800px) {
    .art > summary { grid-template-columns: minmax(220px, 1fr) auto auto; }
  }
  .pz td, .pz th { padding: 6px 8px; }
  .pz select, .pz input { width: 100%; }
  .doc-actions { display: flex; gap: 10px; flex-wrap: wrap; }
CSS, $kpis['out'] ? $kpis['out'] . ' bez stanu' : '');
console_flash($flash, $kind);
console_crumbs($doc
    ? ['Pulpit' => 'pulpit.php', 'Magazyn' => 'magazyn.php', $doc['number'] => null]
    : ['Pulpit' => 'pulpit.php', 'Magazyn' => null]);
?>

<?php if ($doc): ?>
<div class="panel">
  <h2><?= h(WSM_STOCK_DOC_KINDS[$doc['kind']] ?? $doc['kind']) ?> <?= h($doc['number']) ?></h2>
  <dl class="kv">
    <dt><?= $doc['kind'] === 'PZ' ? 'Dostawca' : 'Odbiorca' ?></dt><dd><?= h($doc['partner']) ?: '—' ?></dd>
    <dt>Data</dt><dd><?= h($doc['issued_at']) ?></dd>
    <?php if ($doc['ref'] !== ''): ?><dt><?= $doc['kind'] === 'PZ' ? 'Faktura zakupu' : 'Zamówienie' ?></dt>
      <dd><?= $doc['order_id']
              ? '<a class="code" href="zamowienia.php?id=' . (int) $doc['order_id'] . '">' . h($doc['ref']) . '</a>'
              : h($doc['ref']) ?></dd><?php endif; ?>
    <dt>Pozycje</dt><dd><?= (int) $doc['units'] ?> szt. · <?= h(pln((int) $doc['value'])) ?><?= $doc['kind'] === 'PZ' ? ' netto' : '' ?></dd>
    <?php if ($fz): ?><dt>Faktura zakupu</dt><dd>netto <?= h(pln((int) $fz['net'])) ?> · VAT <?= h(pln((int) $fz['vat'])) ?> · brutto <b><?= h(pln((int) $fz['gross'])) ?></b>
      · <a class="code" href="przyjecie.php?id=<?= (int) $fz['id'] ?>">pozycje faktury</a><?php if (wsm_zak_file($fz)): ?>
      · <a class="code" href="przyjecie.php?id=<?= (int) $fz['id'] ?>&amp;plik=1" target="_blank" rel="noopener">plik ↗</a><?php endif; ?></dd><?php endif; ?>
    <?php if ($doc['note'] !== ''): ?><dt>Uwaga</dt><dd><?= h($doc['note']) ?></dd><?php endif; ?>
    <dt>Wystawił</dt><dd><?= h($doc['actor']) ?: '—' ?></dd>
  </dl>

  <div class="tablewrap" style="margin-top:16px">
    <table>
      <tr><th>Produkt</th><th class="num">Ilość</th><th class="num"><?= $doc['kind'] === 'PZ' ? 'Cena zakupu netto' : 'Wartość' ?></th>
        <?php if ($fz): ?><th class="num">VAT</th><th class="num">Wartość netto / brutto</th><?php endif; ?><th class="num">Stan po</th></tr>
      <?php foreach ($doc['lines'] as $l): $fl = $fzLignes[(int) $l['id']] ?? null; ?>
      <tr>
        <td><a href="magazyn_produkt.php?id=<?= h(urlencode((string) $l['product_id'])) ?>"><?= h((string) ($l['product_name'] ?: $l['product_id'])) ?></a>
          <?= (string) ($l['sku'] ?? '') !== '' ? '<br><small class="muted">' . h((string) $l['sku']) . '</small>' : '' ?>
          <?= $fl ? '<br><small class="muted">z faktury: ' . h((string) $fl['name']) . '</small>' : '' ?></td>
        <td class="num"><?= sprintf('%+d', (int) $l['delta']) ?></td>
        <td class="num"><?= (int) $l['unit_cost'] > 0 ? h(pln((int) $l['unit_cost'])) . ' / szt.' : '—' ?></td>
        <?php if ($fz): ?>
        <td class="num"><?= $fl ? h(ctype_digit((string) $fl['vat_rate']) ? $fl['vat_rate'] . '%' : (string) $fl['vat_rate']) : '—' ?></td>
        <td class="num"><?= $fl ? h(pln((int) $fl['net'])) . '<br><small class="muted">' . h(pln((int) $fl['gross'])) . '</small>' : '—' ?></td>
        <?php endif; ?>
        <td class="num"><?= (int) $l['stock_after'] ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$doc['lines']): ?><tr><td class="muted" colspan="6">Brak powiązanych ruchów.</td></tr><?php endif; ?>
    </table>
  </div>

  <?php if (!empty($doc['order_items'])): ?>
  <h3>Zamówione</h3>
  <p class="why">To, co klient zamówił. Pozycje „do wykonania” nie opuściły magazynu —
    nie ma ich na tym wydaniu, bo fizycznie ich nie było.</p>
  <div class="tablewrap">
    <table>
      <tr><th>Produkt</th><th class="num">Zamówiono</th><th class="num">Do wykonania</th></tr>
      <?php foreach ($doc['order_items'] as $l): ?>
      <tr><td><?= h((string) $l['name']) ?></td><td class="num"><?= (int) $l['qty'] ?></td>
          <td class="num"><?= (int) $l['backorder'] ?: '—' ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <div class="actions doc-actions">
    <a class="code" href="magazyn.php?dok=<?= (int) $doc['id'] ?>&amp;druk=1" target="_blank" rel="noopener">Otwórz do druku ↗</a>
    <a class="code" href="magazyn.php">← Magazyn</a>
  </div>
</div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><b><?= (int) $kpis['products'] ?></b><span>Produkty w sklepie</span></div>
  <div class="kpi"><b><?= (int) $kpis['units'] ?></b><span>Sztuk na stanie</span></div>
  <div class="kpi"><b><?= h(pln((int) $kpis['value'])) ?></b><span>Wartość w cenach sprzedaży</span></div>
  <div class="kpi<?= $kpis['out'] ? ' hot' : '' ?>"><b><?= (int) $kpis['out'] ?></b><span>Bez stanu</span></div>
  <div class="kpi<?= $kpis['low'] ? ' hot' : '' ?>"><b><?= (int) $kpis['low'] ?></b><span>Na wyczerpaniu</span></div>
  <div class="kpi<?= $kpis['owed'] ? ' hot' : '' ?>"><b><?= (int) $kpis['owed'] ?></b><span>Sztuk do wykonania</span></div>
</div>

<div class="panel">
  <h2>Stan magazynu — szczegóły po artykule</h2>
  <p class="why">
    Kliknij produkt, żeby zobaczyć jego historię: skąd przyszły przyjęcia, gdzie poszły wydania,
    kto i dlaczego korygował. „Zapas” to liczba <b>dni sprzedaży</b> pokrytych obecnym stanem
    (z ostatnich 30 dni) — pięć sztuk to komfort przy jednej sprzedaży miesięcznie i alarm przy
    jednej dziennie. „Do wykonania” to sztuki już sprzedane ponad stan.
  </p>

  <?php foreach ($ov as $r): if (!$r['visible'] && $r['stock'] === 0 && $r['sold'] === 0) continue;
    $hist = $perProduct[$r['id']] ?? []; ?>
  <details class="art"<?= ($_GET['product_id'] ?? '') === $r['id'] ? ' open' : '' ?>>
    <summary>
      <span>
        <span class="nm"><?= h($r['name']) ?></span>
        <a class="code" href="magazyn_produkt.php?id=<?= h(urlencode($r['id'])) ?>" style="margin-left:8px;font-size:12px">wykresy i historia →</a>
        <?php if (!$r['visible']): ?> <span class="tag off">ukryty</span><?php endif; ?>
        <span class="fig">
          <span>stan <b><?= (int) $r['stock'] ?></b></span>
          <span>sprzedaż 30 dni <b><?= (int) $r['sold'] ?></b></span>
          <span>zapas <b><?= $r['cover_days'] === null ? '—' : (int) $r['cover_days'] . ' dni' ?></b></span>
          <?php if ($r['owed']): ?><span>do wykonania <b><?= (int) $r['owed'] ?></b></span><?php endif; ?>
        </span>
      </span>
      <span class="tag <?= h($statusTag[$r['status']]) ?>"><?= h($statusLabel[$r['status']]) ?></span>
    </summary>
    <div class="inner">
      <?php if (!$hist): ?>
      <p class="muted small">Brak ruchów dla tego produktu.</p>
      <?php else: ?>
      <div class="tablewrap">
        <table class="rwd">
          <thead><tr><th>Kiedy</th><th>Rodzaj</th><th class="num">±</th><th class="num">Stan po</th><th>Dokument</th><th>Szczegóły</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($hist, 0, 12) as $m):
            $det = trim(implode(' · ', array_filter([
                (string) $m['reason'], (string) $m['supplier'],
                (int) $m['unit_cost'] > 0 ? 'zakup ' . pln((int) $m['unit_cost']) : '',
                (string) $m['note'], (string) $m['actor'],
            ]))); ?>
          <tr>
            <td data-l="Kiedy" class="num"><?= h(substr((string) $m['created_at'], 0, 16)) ?></td>
            <td data-l="Rodzaj"><span class="tag"><?= h(WSM_STOCK_KINDS[$m['kind']] ?? $m['kind']) ?></span></td>
            <td data-l="±" class="num"><b><?= sprintf('%+d', (int) $m['delta']) ?></b></td>
            <td data-l="Stan po" class="num"><?= (int) $m['stock_after'] ?></td>
            <td data-l="Dokument"><?= ($m['doc_id'] ?? null)
                ? '<a class="code" href="magazyn.php?dok=' . (int) $m['doc_id'] . '">' . h((string) $m['doc']) . '</a>'
                : (h((string) $m['doc']) ?: '—') ?></td>
            <td data-l="Szczegóły" class="wide"><?= $det !== '' ? h($det) : '<span class="muted">—</span>' ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p style="margin-top:10px"><a class="code" href="magazyn_produkt.php?id=<?= h(urlencode($r['id'])) ?>">Wykresy, dostawy i wydania tego produktu →</a></p>
      <?php endif; ?>
    </div>
  </details>
  <?php endforeach; ?>
</div>

<?php // Le formulaire poste vers przyjecie.php : seulement pour qui peut y écrire
      // (un profil personnalisé peut ouvrir le Magazyn sans les factures).
      if (wsm_droit_ecran($me, 'przyjecie.php') === 'w'): ?>
<div class="panel">
  <h2>Przyjęcie z faktury zakupu</h2>
  <p class="why">Najszybciej: wczytaj fakturę dostawcy — PDF albo XML z KSeF. Pozycje zostaną odczytane i dopasowane do
    produktów sklepu (z ceną netto, VAT i ilością); sprawdzasz, poprawiasz i jednym kliknięciem przyjmujesz na stan.</p>
  <form method="post" action="przyjecie.php" enctype="multipart/form-data" class="actions" style="align-items:center"
        onsubmit="var b=this.querySelector('button');b.disabled=true;b.textContent='Czytam fakturę…';">
    <?= console_csrf_field() ?>
    <input type="hidden" name="wczytaj" value="1">
    <input type="file" name="plik" accept="application/pdf,.pdf,.xml,application/xml,text/xml" required aria-label="Plik faktury zakupu">
    <button class="btn btn--brand" type="submit">Wczytaj fakturę</button>
    <a class="code" href="przyjecie.php">wszystkie faktury zakupu →</a>
  </form>
  <?php if ($szkice): ?>
  <h3>Do sprawdzenia</h3>
  <div class="tablewrap"><table class="rwd">
    <thead><tr><th>Faktura</th><th>Dostawca</th><th class="num">Netto</th><th>Pozycje</th></tr></thead>
    <tbody>
    <?php foreach ($szkice as $z): ?>
    <tr>
      <td data-l="Faktura"><a class="code" href="przyjecie.php?id=<?= (int) $z['id'] ?>"><?= h($z['number'] ?: '#' . $z['id']) ?></a></td>
      <td data-l="Dostawca"><?= h((string) $z['supplier_name']) ?: '—' ?></td>
      <td data-l="Netto" class="num"><?= h(pln((int) $z['net'])) ?></td>
      <td data-l="Pozycje"><?= (int) $z['pozycji'] ?><?= (int) $z['bez_produktu'] ? ' · <span class="tag wait">' . (int) $z['bez_produktu'] . ' bez produktu</span>' : '' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<div class="panel">
  <h2>Nowe przyjęcie ręczne (PZ)</h2>
  <p class="why">Jedna dostawa = jeden dokument, nawet na kilka artykułów. Cena zakupu jest
    zapisywana przy każdej pozycji — to ona pozwala później policzyć marżę na tym, co
    faktycznie leży w magazynie. Puste wiersze są pomijane; jeśli któraś pozycja jest błędna,
    nie zapisuje się żadna.</p>
  <form method="post">
      <?= console_csrf_field() ?>
    <input type="hidden" name="przyjmij" value="1">
    <div class="grid2">
      <label class="field"><span>Dostawca</span>
        <input type="text" name="partner" placeholder="np. Callebaut Polska" required></label>
      <label class="field"><span>Faktura zakupu / dokument</span>
        <input type="text" name="ref" placeholder="nr dokumentu dostawcy"></label>
      <label class="field"><span>Data przyjęcia</span>
        <input type="date" name="issued_at" value="<?= h(date('Y-m-d')) ?>"></label>
      <label class="field"><span>Uwaga</span><input type="text" name="note"></label>
    </div>

    <div class="tablewrap">
      <table class="pz">
        <tr><th>Produkt</th><th class="num" style="width:110px">Ilość</th><th class="num" style="width:150px">Cena zakupu / szt.</th></tr>
        <?php for ($i = 0; $i < WSM_PZ_ROWS; $i++): ?>
        <tr>
          <td>
            <?php // Cinq listes déroulantes identiques : sans numéro de ligne,
                  // on ne sait pas laquelle on remplit. Le tableau a un
                  // en-tête, mais un en-tête ne se lit pas à voix haute à
                  // chaque cellule. ?>
            <select name="line_product[<?= $i ?>]" aria-label="Produkt, wiersz <?= $i + 1 ?>">
              <option value="">— pusty wiersz —</option>
              <?php foreach ($ov as $r): ?>
              <option value="<?= h($r['id']) ?>"><?= h($r['name']) ?> (stan <?= (int) $r['stock'] ?>)</option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="num"><input type="number" name="line_qty[<?= $i ?>]" min="0" placeholder="0"
                 aria-label="Ilość, wiersz <?= $i + 1 ?>"></td>
          <td class="num"><input type="text" name="line_cost[<?= $i ?>]" inputmode="decimal" placeholder="0,00"
                 aria-label="Cena zakupu, wiersz <?= $i + 1 ?>"></td>
        </tr>
        <?php endfor; ?>
      </table>
    </div>
    <div class="actions"><button class="primary" type="submit">Zapisz przyjęcie</button></div>
  </form>
</div>

<div class="panel">
  <h2>Korekta pojedyncza</h2>
  <p class="why">Stłuczka, degustacja, inwentaryzacja, zwrot od klienta. Powód jest wymagany:
    korekta bez powodu to strata, której nikt później nie wyjaśni.</p>
  <form method="post">
      <?= console_csrf_field() ?>
    <input type="hidden" name="koryguj" value="1">
    <div class="grid2">
      <label class="field"><span>Produkt</span>
        <select name="product_id" required>
          <?php foreach ($ov as $r): ?>
          <option value="<?= h($r['id']) ?>"><?= h($r['name']) ?> — stan <?= (int) $r['stock'] ?></option>
          <?php endforeach; ?>
        </select></label>
      <label class="field"><span>Zmiana (± sztuk)</span>
        <input type="number" name="delta" value="-1" required>
        <span class="hint">Ujemna = ubytek, dodatnia = zwrot na stan.</span></label>
      <label class="field"><span>Powód</span>
        <input type="text" name="reason" placeholder="np. uszkodzone opakowanie" required></label>
      <label class="field"><span>Uwaga operatora</span><input type="text" name="note"></label>
    </div>
    <div class="actions"><button class="primary" type="submit">Zapisz korektę</button></div>
  </form>
</div>
<?php endif; ?>

<div class="panel">
  <h2>Dokumenty magazynowe</h2>
  <p class="why">PZ — przyjęcia od dostawców. WZ — wydania do klientów, wystawiane przy zamówieniu
    (przycisk „Utwórz WZ” na zamówieniu). Oba mają numer, który można podać przez telefon.</p>
  <form method="get" class="actions" style="margin-bottom:12px">
    <?php // « Toutes » quoi ? À l'oreille, une liste sans nom au milieu d'un
          // écran qui en compte plusieurs. ?>
    <select name="dk" aria-label="Filtruj dokumenty po rodzaju">
      <option value="">wszystkie rodzaje</option>
      <?php foreach (WSM_STOCK_DOC_KINDS as $k => $lbl): ?>
      <option value="<?= h($k) ?>"<?= ($_GET['dk'] ?? '') === $k ? ' selected' : '' ?>><?= h($k) ?> — <?= h($lbl) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit">Pokaż</button>
  </form>
  <div class="tablewrap">
  <table class="rwd">
    <thead><tr><th>Numer</th><th>Data</th><th>Kontrahent</th><th>Dokument</th><th class="num">Sztuk</th><th class="num">Wartość</th></tr></thead>
    <tbody>
    <?php if (!$docs): ?><tr><td class="muted">Brak dokumentów.</td></tr><?php endif; ?>
    <?php foreach ($docs as $d): ?>
    <tr>
      <td data-l="Numer"><a class="code" href="magazyn.php?dok=<?= (int) $d['id'] ?>"><?= h($d['number']) ?></a></td>
      <td data-l="Data" class="num"><?= h($d['issued_at']) ?></td>
      <td data-l="Kontrahent"><?= h($d['partner']) ?: '—' ?></td>
      <td data-l="Dokument"><?= h($d['ref']) ?: '—' ?></td>
      <td data-l="Sztuk" class="num"><?= (int) $d['units'] ?></td>
      <td data-l="Wartość" class="num"><?= h(pln((int) $d['value'])) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="panel">
  <h2>Wszystkie ruchy</h2>
  <form method="get" class="actions" style="margin-bottom:12px">
    <label class="field" style="margin:0"><span>Od</span><input type="date" name="from" value="<?= h($from) ?>"></label>
    <label class="field" style="margin:0"><span>Do</span><input type="date" name="to" value="<?= h($to) ?>"></label>
    <label class="field" style="margin:0"><span>Rodzaj</span>
      <select name="kind">
        <option value="">wszystkie</option>
        <?php foreach (WSM_STOCK_KINDS as $k => $lbl): ?>
        <option value="<?= h($k) ?>"<?= ($_GET['kind'] ?? '') === $k ? ' selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
      </select></label>
    <?php if (($_GET['product_id'] ?? '') !== ''): ?>
    <input type="hidden" name="product_id" value="<?= h((string) $_GET['product_id']) ?>">
    <?php endif; ?>
    <button type="submit">Pokaż</button>
    <?php if (($_GET['product_id'] ?? '') !== ''): ?><a class="code" href="magazyn.php">wszystkie produkty</a><?php endif; ?>
  </form>

  <div class="tablewrap">
  <table class="rwd">
    <thead><tr><th>Kiedy</th><th>Produkt</th><th>Rodzaj</th><th class="num">±</th>
               <th class="num">Stan po</th><th>Dokument</th><th>Kto</th></tr></thead>
    <tbody>
    <?php if (!$moves): ?><tr><td class="muted">Brak ruchów w tym zakresie.</td></tr><?php endif; ?>
    <?php foreach ($moves as $m): ?>
    <tr>
      <td data-l="Kiedy" class="num"><?= h(substr((string) $m['created_at'], 0, 16)) ?></td>
      <td data-l="Produkt"><?= h((string) ($m['product_name'] ?: $m['product_id'])) ?></td>
      <td data-l="Rodzaj"><span class="tag"><?= h(WSM_STOCK_KINDS[$m['kind']] ?? $m['kind']) ?></span></td>
      <td data-l="±" class="num"><b><?= sprintf('%+d', (int) $m['delta']) ?></b></td>
      <td data-l="Stan po" class="num"><?= (int) $m['stock_after'] ?></td>
      <td data-l="Dokument"><?= ($m['doc_id'] ?? null)
          ? '<a class="code" href="magazyn.php?dok=' . (int) $m['doc_id'] . '">' . h((string) $m['doc']) . '</a>'
          : (h((string) $m['doc']) ?: '—') ?></td>
      <td data-l="Kto"><?= h((string) $m['actor']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php console_foot();
