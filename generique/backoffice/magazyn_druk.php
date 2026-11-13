<?php
// ============================================================================
//  magazyn_druk.php — le bon de magasin, tel qu'il s'imprime.
//
//  Inclus par magazyn.php avec $doc. Un PZ se classe avec la facture d'achat ;
//  un WZ part avec le colis et se signe à la réception. Les deux tiennent sur
//  une feuille et sortent en PDF par l'impression du navigateur.
// ============================================================================
declare(strict_types=1);

$d = $doc;
$title = ['PZ' => 'Przyjęcie zewnętrzne (PZ)', 'WZ' => 'Wydanie zewnętrzne (WZ)'][$d['kind']] ?? 'Dokument magazynowy';
$zl = fn(int $g) => number_format($g / 100, 2, ',', ' ');
$cfg = (array) (wsm_config()['invoice'] ?? []);
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($d['number']) ?></title>
<link rel="stylesheet" href="_ds/mister-szoko/global.css">
<link rel="stylesheet" href="_ds/mister-szoko/brand.css">
<style>
  body { margin: 0; background: var(--bg-page-alt); font-family: var(--font-sans); color: var(--text-strong); }
  .sheet { max-width: 820px; margin: 24px auto; background: #fff; padding: 40px 44px;
           box-shadow: 0 8px 30px rgba(0,0,0,.08); border-radius: 6px; }
  .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; margin-bottom: 26px; }
  .top h1 { font-family: var(--font-display); font-size: 24px; margin: 0 0 4px; }
  .top .no { font-family: var(--font-mono); font-size: 15px; color: var(--text-body); }
  .top img { height: 50px !important; width: auto; max-width: none; }
  .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; margin-bottom: 22px; }
  .parties h2 { font-size: 10.5px; text-transform: uppercase; letter-spacing: .12em;
                color: var(--text-muted); margin: 0 0 6px; font-family: var(--font-mono); }
  .parties p { margin: 0; font-size: 13.5px; line-height: 1.6; }
  .dates { display: flex; gap: 26px; flex-wrap: wrap; font-size: 12.5px;
           border-top: 1px solid var(--border-subtle); border-bottom: 1px solid var(--border-subtle);
           padding: 10px 0; margin-bottom: 20px; color: var(--text-body); }
  .dates b { font-family: var(--font-mono); color: var(--text-strong); }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .1em;
       color: var(--text-muted); border-bottom: 1px solid var(--border-default); padding: 0 8px 7px; }
  td { padding: 8px; border-bottom: 1px solid var(--border-subtle); }
  td.num, th.num { text-align: right; font-family: var(--font-mono); white-space: nowrap; }
  tfoot td { font-weight: 700; border-bottom: 0; border-top: 2px solid var(--text-strong); }
  .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 48px; font-size: 12px; color: var(--text-muted); }
  .sign div { border-top: 1px solid var(--border-default); padding-top: 6px; text-align: center; }
  .note { margin-top: 16px; font-size: 12.5px; color: var(--text-body); }
  .noprint { text-align: center; margin: 18px; }
  .noprint button { font: 600 14px var(--font-sans); padding: 11px 20px; border-radius: 999px;
                    border: none; background: var(--brand); color: #fff; cursor: pointer; }
  @media print {
    body { background: #fff; }
    .sheet { margin: 0; padding: 0; box-shadow: none; max-width: none; }
    .noprint { display: none; }
    @page { size: A4; margin: 16mm; }
  }
</style>
</head>
<body>
<div class="noprint"><button type="button" onclick="window.print()">Drukuj / zapisz PDF</button></div>

<div class="sheet">
  <div class="top">
    <div>
      <h1><?= h($title) ?></h1>
      <div class="no"><?= h($d['number']) ?></div>
    </div>
    <img src="<?= h(console_logo_src()) ?>" alt="">
  </div>

  <div class="parties">
    <div>
      <h2><?= $d['kind'] === 'PZ' ? 'Odbiorca (magazyn)' : 'Wydający' ?></h2>
      <p><b><?= h((string) ($cfg['seller_name'] ?? wsm_brand_name())) ?></b><br>
         <?= h((string) ($cfg['seller_address'] ?? '')) ?><br>
         <?= ($cfg['seller_nip'] ?? '') !== '' ? 'NIP ' . h((string) $cfg['seller_nip']) : '' ?></p>
    </div>
    <div>
      <h2><?= $d['kind'] === 'PZ' ? 'Dostawca' : 'Odbiorca' ?></h2>
      <p><b><?= h($d['partner']) ?: '—' ?></b>
        <?= $d['ref'] !== '' ? '<br>' . ($d['kind'] === 'PZ' ? 'Faktura zakupu: ' : 'Zamówienie: ') . h($d['ref']) : '' ?></p>
    </div>
  </div>

  <div class="dates">
    <span>Data <b><?= h($d['issued_at']) ?></b></span>
    <span>Pozycji <b><?= count($d['lines']) ?></b></span>
    <span>Sztuk <b><?= (int) $d['units'] ?></b></span>
    <?php if ($d['actor'] !== ''): ?><span>Wystawił <b><?= h($d['actor']) ?></b></span><?php endif; ?>
  </div>

  <table>
    <thead>
      <tr><th>Lp.</th><th>Produkt</th><th>Symbol</th><th class="num">Ilość</th>
          <?php if ($d['kind'] === 'PZ'): ?><th class="num">Cena zakupu</th><th class="num">Wartość</th><?php endif; ?></tr>
    </thead>
    <tbody>
      <?php $i = 0; $sum = 0; foreach ($d['lines'] as $l): $i++;
        $q = abs((int) $l['delta']); $v = $q * (int) $l['unit_cost']; $sum += $v; ?>
      <tr>
        <td><?= $i ?></td>
        <td><?= h((string) ($l['product_name'] ?: $l['product_id'])) ?></td>
        <td><?= h((string) ($l['sku'] ?? '')) ?: '—' ?></td>
        <td class="num"><?= $q ?></td>
        <?php if ($d['kind'] === 'PZ'): ?>
        <td class="num"><?= (int) $l['unit_cost'] > 0 ? $zl((int) $l['unit_cost']) : '—' ?></td>
        <td class="num"><?= $v > 0 ? $zl($v) : '—' ?></td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if ($d['kind'] === 'PZ' && $sum > 0): ?>
    <tfoot><tr><td colspan="3">Razem</td><td class="num"><?= (int) $d['units'] ?></td>
               <td class="num"></td><td class="num"><?= $zl($sum) ?> PLN</td></tr></tfoot>
    <?php endif; ?>
  </table>

  <?php if ($d['note'] !== ''): ?><p class="note">Uwaga: <?= h($d['note']) ?></p><?php endif; ?>

  <div class="sign">
    <div><?= $d['kind'] === 'PZ' ? 'Podpis przyjmującego' : 'Podpis wydającego' ?></div>
    <div><?= $d['kind'] === 'PZ' ? 'Podpis dostawcy' : 'Podpis odbiorcy' ?></div>
  </div>
</div>
</body>
</html>
