<?php
// ============================================================================
//  magazyn_produkt.php — un article, de bout en bout.
//
//  D'où il vient : chaque livraison, son fournisseur, sa facture, son prix
//  net, sa TVA. Où il est parti : chaque commande, le client, le bon de
//  livraison (WZ), le colis et son lien de suivi. Et trois graphiques —
//  le stock dans le temps, les entrées et sorties par mois, le prix d'achat
//  livraison après livraison — dont chaque valeur est aussi dans un tableau.
//
//  Un seul filtre, la période, en haut : il règle tout ce qui est dessous.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/stock.php';
require_once $API . '/zakupy.php';
require_once $API . '/wykresy.php';
wsm_zak_ensure($pdo);

$pid = (string) ($_GET['id'] ?? '');
$okresy = [3 => '3 mies.', 6 => '6 mies.', 12 => '12 mies.', 24 => '24 mies.'];
$m = (int) ($_GET['m'] ?? 12);
if (!isset($okresy[$m])) $m = 12;
$r = $pid !== '' ? wsm_stock_product_report($pdo, $pid, $m) : null;

if (!$r) {
    http_response_code(404);
    console_head('Produkt', $me);
    console_crumbs(['Pulpit' => 'pulpit.php', 'Magazyn' => 'magazyn.php', 'Produkt' => null]);
    echo '<div class="panel"><p>Nie znaleziono produktu. <a class="code" href="magazyn.php">← Magazyn</a></p></div>';
    console_foot();
    exit;
}

$p = $r['product'];
$k = $r['kpi'];
$since = $r['since'];
$dans = fn(array $x) => (string) $x['created_at'] >= $since;
$entrees = array_values(array_filter($r['entrees'], $dans));
$sorties = array_values(array_filter($r['sorties'], $dans));
$korekty = array_values(array_filter($r['korekty'], $dans));
$data = fn(string $t) => date('d.m.Y', strtotime($t));
$kinds = WSM_STOCK_KINDS + ['start' => 'Stan na początek okresu', 'teraz' => 'Stan dziś'];
$stawka = fn(string $v) => ctype_digit($v) ? $v . '%' : ($v !== '' ? $v : '—');
$statusColis = ['w_drodze' => 'w drodze', 'dostarczona' => 'doręczona', 'utworzona' => 'nadana', 'do_utworzenia' => 'do nadania'];

// --- Graphique 1 : le stock dans le temps (en escalier : un stock ne glisse pas, il saute).
$serieNiveau = [];
foreach ($r['serie_niveau'] as $pt) {
    $info = $kinds[$pt['kind']] ?? $pt['kind'];
    if ($pt['delta'] !== 0) $info .= ' ' . sprintf('%+d', $pt['delta']);
    if ($pt['doc'] !== '') $info .= ' · ' . $pt['doc'];
    $serieNiveau[] = ['t' => $pt['t'], 'v' => $pt['v'], 'tip' => $pt['v'] . ' szt.' . "\n" . $data($pt['t']) . ' · ' . $info];
}
$de = strtotime($since); $a = time();

// --- Graphique 2 : par mois, entrées (fournisseurs) et sorties (clients).
$groupes = []; $tableMois = [];
foreach ($r['serie_mois'] as $ym => $v) {
    $groupes[] = ['label' => wsm_viz_mois($ym, substr($ym, 5, 2) === '01'),
                  'tip' => 'Przyjęto ' . $v['in'] . ' · wydano ' . $v['out'] . ' szt.' . "\n" . wsm_viz_mois($ym) . ' ' . substr($ym, 0, 4),
                  'valeurs' => [$v['in'], $v['out']]];
    $tableMois[] = [$ym, $v['in'], $v['out']];
}

// --- Graphique 3 : le prix d'achat net d'une sztuka, livraison par livraison.
$seriePrix = [];
foreach ($r['serie_prix'] as $pt) {
    if ($pt['t'] < substr($since, 0, 10)) continue;
    $seriePrix[] = ['t' => $pt['t'] . ' 12:00:00', 'v' => $pt['v'] / 100,
                    'tip' => pln($pt['v']) . ' / szt.' . "\n" . $data($pt['t']) . ' · ' . $pt['dostawca'] . ($pt['doc'] !== '' ? ' · ' . $pt['doc'] : '')];
}
$zl = fn($v) => number_format((float) $v, (float) $v === floor((float) $v) ? 0 : 2, ',', ' ') . ' zł';

console_head((string) $p['nom'], $me, <<<'CSS'
  .why { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0 0 14px; max-width: 90ch; }
  .okres { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0 0 16px; }
  .okres a { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--border-subtle); text-decoration: none; font-size: 13px; }
  .okres a.on { background: var(--text-strong); color: #fff; border-color: var(--text-strong); }
  .wykresy { display: grid; gap: 16px; grid-template-columns: 1fr; margin-bottom: 20px; }
  @media (min-width: 1100px) { .wykresy { grid-template-columns: 1fr 1fr; } .wykresy .szeroki { grid-column: 1 / -1; } }
  .wykresy .panel { margin: 0; min-width: 0; }
  .wykresy h2 { margin-bottom: 2px; }
  .wykresy details { margin-top: 10px; font-size: 13px; }
  td small { display: block; color: var(--text-muted); font-size: 12px; }
  .kpi small { display: block; font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }
  .kpi b { font-variant-numeric: normal; }
CSS . wsm_viz_css());
console_crumbs(['Pulpit' => 'pulpit.php', 'Magazyn' => 'magazyn.php', (string) $p['nom'] => null]);
?>
<div class="okres" role="group" aria-label="Okres">
  <span class="muted small">Okres:</span>
  <?php foreach ($okresy as $mm => $lbl): ?>
  <a class="<?= $mm === $m ? 'on' : '' ?>" href="magazyn_produkt.php?id=<?= h(urlencode($pid)) ?>&amp;m=<?= $mm ?>"<?= $mm === $m ? ' aria-current="true"' : '' ?>><?= h($lbl) ?></a>
  <?php endforeach; ?>
  <span class="muted small">· <?= h((string) ($p['sku'] ?: '')) ?><?= $p['ean'] ? ' · EAN ' . h((string) $p['ean']) : '' ?>
    · <a class="code" href="produkty.php?id=<?= h(urlencode($pid)) ?>">karta produktu</a></span>
</div>

<div class="kpis">
  <div class="kpi"><b><?= (int) $k['stock'] ?></b><span>Na stanie</span></div>
  <div class="kpi"><b><?= (int) $k['recu'] ?></b><span>Przyjęto · <?= h($okresy[$m]) ?></span></div>
  <div class="kpi"><b><?= (int) $k['vendu'] ?></b><span>Wydano klientom · <?= h($okresy[$m]) ?></span></div>
  <div class="kpi"><b><?= $k['cout_moyen'] ? h(pln((int) $k['cout_moyen'])) : '—' ?></b><span>Średni koszt netto / szt.</span></div>
  <div class="kpi"><b><?= $k['cout_moyen'] ? h(pln((int) $k['cout_moyen'] * (int) $k['stock'])) : '—' ?></b><span>Wartość stanu (zakup)</span></div>
  <div class="kpi"><b><?= $k['derniere_date'] !== '' ? h($data($k['derniere_date'])) : '—' ?></b><span>Ostatnia dostawa</span>
    <?php if ($k['dernier_dostawca'] !== ''): ?><small><?= h($k['dernier_dostawca']) ?></small><?php endif; ?></div>
</div>

<div class="wykresy">
  <div class="panel szeroki">
    <h2>Stan magazynu</h2>
    <p class="why">Sztuki na stanie dzień po dniu: każdy skok to dostawa, sprzedaż albo korekta. Najedź na wykres, żeby zobaczyć ruch.</p>
    <?= wsm_viz_ligne($serieNiveau, ['de' => $de, 'a' => $a, 'etape' => true, 'aire' => true, 'couleur' => 1,
        'fmt' => fn($v) => (string) (int) $v, 'fin' => (int) $k['stock'] . ' szt.', 'aria' => 'Stan magazynu w czasie']) ?>
  </div>

  <div class="panel">
    <h2>Przyjęcia i wydania, miesięcznie</h2>
    <p class="why">Sztuki od dostawców i sztuki wydane klientom w każdym miesiącu.</p>
    <?= wsm_viz_colonnes($groupes, [['nom' => 'Przyjęcia od dostawców', 'couleur' => 1], ['nom' => 'Wydania do klientów', 'couleur' => 2]],
        ['fmt' => fn($v) => (string) (int) $v, 'aria' => 'Przyjęcia i wydania w miesiącach']) ?>
    <details><summary>Tabela</summary>
      <div class="tablewrap"><table><tr><th>Miesiąc</th><th class="num">Przyjęto</th><th class="num">Wydano</th></tr>
      <?php foreach (array_reverse($tableMois) as [$ym, $in, $out]): ?>
      <tr><td><?= h(wsm_viz_mois($ym) . ' ' . substr($ym, 0, 4)) ?></td><td class="num"><?= (int) $in ?></td><td class="num"><?= (int) $out ?></td></tr>
      <?php endforeach; ?></table></div>
    </details>
  </div>

  <div class="panel">
    <h2>Cena zakupu netto za sztukę</h2>
    <p class="why">Każdy punkt to jedna dostawa: wartość netto pozycji z faktury podzielona przez przyjęte sztuki.</p>
    <?= wsm_viz_ligne($seriePrix, ['de' => $de, 'a' => $a, 'points' => true, 'couleur' => 1, 'fmt' => $zl,
        'fin' => $seriePrix ? pln((int) round(end($seriePrix)['v'] * 100)) : '', 'aria' => 'Cena zakupu netto w kolejnych dostawach',
        'vide' => 'Brak dostaw z ceną zakupu w tym okresie.']) ?>
  </div>
</div>

<div class="panel">
  <h2>Dostawy — skąd przyszedł</h2>
  <div class="tablewrap"><table class="rwd">
    <thead><tr><th>Data</th><th>PZ</th><th>Dostawca</th><th>Faktura</th><th class="num">Ilość</th>
      <th class="num">Cena netto / szt.</th><th class="num">VAT</th><th class="num">Netto</th><th class="num">Brutto</th></tr></thead>
    <tbody>
    <?php if (!$entrees): ?><tr><td colspan="9" class="muted">Brak dostaw w tym okresie.</td></tr><?php endif; ?>
    <?php foreach ($entrees as $e): $fact = (string) ($e['invoice_number'] ?? '') ?: (string) $e['invoice_ref']; ?>
    <tr>
      <td data-l="Data" class="num"><?= h($data((string) ($e['pz_date'] ?: $e['created_at']))) ?></td>
      <td data-l="PZ"><?= $e['doc_id'] ? '<a class="code" href="magazyn.php?dok=' . (int) $e['doc_id'] . '">' . h((string) $e['doc']) . '</a>' : (h((string) $e['doc']) ?: '—') ?></td>
      <td data-l="Dostawca"><?= h((string) $e['supplier']) ?: '—' ?></td>
      <td data-l="Faktura"><?php if (!empty($e['invoice_id'])): ?><a class="code" href="przyjecie.php?id=<?= (int) $e['invoice_id'] ?>"><?= h($fact) ?></a>
        <?php if (!empty($e['invoice_file'])): ?><small><a href="przyjecie.php?id=<?= (int) $e['invoice_id'] ?>&amp;plik=1" target="_blank" rel="noopener">plik ↗</a></small><?php endif; ?>
        <?php else: ?><?= h($fact) ?: '—' ?><?php endif; ?></td>
      <td data-l="Ilość" class="num"><?= sprintf('%+d', (int) $e['delta']) ?>
        <?php if (!empty($e['inv_unit']) && (string) $e['inv_unit'] !== 'szt'): ?><small><?= h(rtrim(rtrim(number_format((float) $e['inv_qty'], 3, ',', ' '), '0'), ',') . ' ' . $e['inv_unit']) ?> na fakturze</small><?php endif; ?></td>
      <td data-l="Cena netto / szt." class="num"><?= (int) $e['unit_cost'] > 0 ? h(pln((int) $e['unit_cost'])) : '—' ?></td>
      <td data-l="VAT" class="num"><?= h($stawka((string) ($e['vat_rate'] ?? ''))) ?></td>
      <td data-l="Netto" class="num"><?= isset($e['line_net']) && $e['line_net'] !== null ? h(pln((int) $e['line_net'])) : ((int) $e['unit_cost'] > 0 ? h(pln((int) $e['unit_cost'] * (int) $e['delta'])) : '—') ?></td>
      <td data-l="Brutto" class="num"><?= isset($e['line_gross']) && $e['line_gross'] !== null ? h(pln((int) $e['line_gross'])) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="panel">
  <h2>Wydania — dokąd poszedł</h2>
  <div class="tablewrap"><table class="rwd">
    <thead><tr><th>Data</th><th>Zamówienie</th><th>Klient</th><th class="num">Ilość</th><th>WZ</th><th>Przesyłka</th></tr></thead>
    <tbody>
    <?php if (!$sorties): ?><tr><td colspan="6" class="muted">Brak wydań w tym okresie.</td></tr><?php endif; ?>
    <?php foreach ($sorties as $s): ?>
    <tr>
      <td data-l="Data" class="num"><?= h($data((string) $s['created_at'])) ?></td>
      <td data-l="Zamówienie"><?= $s['order_id'] ? '<a class="code" href="zamowienia.php?id=' . (int) $s['order_id'] . '">' . h((string) $s['order_code']) . '</a>' : h((string) $s['doc']) ?></td>
      <td data-l="Klient"><?= h((string) $s['client']) ?: '—' ?><?php if ((string) $s['email'] !== ''): ?><small><?= h((string) $s['email']) ?></small><?php endif; ?></td>
      <td data-l="Ilość" class="num"><?= (int) -$s['delta'] ?></td>
      <td data-l="WZ"><?= $s['wz'] ? '<a class="code" href="magazyn.php?dok=' . (int) $s['wz_id'] . '">' . h((string) $s['wz']) . '</a>' : '<span class="muted">—</span>' ?></td>
      <td data-l="Przesyłka"><?php if ($s['tracking'] !== ''): ?>
          <?= h(strtoupper(explode('_', $s['carrier'])[0])) ?>
          <?= $s['tracking_url'] !== '' ? '<a class="code" href="' . h($s['tracking_url']) . '" target="_blank" rel="noopener noreferrer">' . h($s['tracking']) . ' ↗</a>' : '<span class="code">' . h($s['tracking']) . '</span>' ?>
          <?php if ($s['shipment_status'] !== ''): ?><small><?= h($statusColis[$s['shipment_status']] ?? str_replace('_', ' ', $s['shipment_status'])) ?></small><?php endif; ?>
        <?php else: ?><span class="muted">bez numeru przesyłki</span><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php if ($korekty): ?>
<div class="panel">
  <h2>Korekty i zwroty</h2>
  <div class="tablewrap"><table class="rwd">
    <thead><tr><th>Data</th><th>Rodzaj</th><th class="num">±</th><th>Powód</th><th>Kto</th></tr></thead>
    <tbody>
    <?php foreach ($korekty as $c): ?>
    <tr><td data-l="Data" class="num"><?= h($data((string) $c['created_at'])) ?></td>
      <td data-l="Rodzaj"><span class="tag"><?= h(WSM_STOCK_KINDS[$c['kind']] ?? $c['kind']) ?></span></td>
      <td data-l="±" class="num"><b><?= sprintf('%+d', (int) $c['delta']) ?></b></td>
      <td data-l="Powód"><?= h(trim((string) $c['reason'] . ((string) $c['note'] !== '' ? ' · ' . $c['note'] : ''))) ?: '—' ?></td>
      <td data-l="Kto"><?= h((string) $c['actor']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<script><?= wsm_viz_js() ?></script>
<?php console_foot();
