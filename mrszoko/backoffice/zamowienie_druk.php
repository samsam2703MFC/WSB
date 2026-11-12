<?php
// ============================================================================
//  zamowienie_druk.php — le bon de commande imprimable.
//
//  Inclus par zamowienia.php avec $o (la commande hydratée). Deux usages :
//  la feuille qui accompagne la préparation en atelier — ce qu'il faut sortir,
//  dans quel ordre, avec ce qui manque signalé — et l'exemplaire qu'on glisse
//  dans le colis.
//
//  Ce n'est PAS une facture : aucun numéro fiscal, aucune mention de TVA
//  opposable. Confondre les deux documents est une erreur qui se paie à
//  l'inspection ; le mot « faktura » n'apparaît donc nulle part ici.
// ============================================================================
declare(strict_types=1);

$zl = fn(int $g) => number_format($g / 100, 2, ',', ' ');
$cfg = (array) (wsm_config()['invoice'] ?? []);
$manque = array_filter($o['items'], fn($l) => (int) ($l['backorder'] ?? 0) > 0);
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($o['code']) ?> — zamówienie</title>
<link rel="stylesheet" href="_ds/mister-szoko/global.css">
<link rel="stylesheet" href="_ds/mister-szoko/brand.css">
<style>
  body { margin: 0; background: var(--bg-page-alt); font-family: var(--font-sans); color: var(--text-strong); }
  .sheet { max-width: 820px; margin: 24px auto; background: #fff; padding: 40px 44px;
           box-shadow: 0 8px 30px rgba(0,0,0,.08); border-radius: 6px; }
  .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; margin-bottom: 24px; }
  .top h1 { font-family: var(--font-display); font-size: 25px; margin: 0 0 4px; }
  .top .no { font-family: var(--font-mono); font-size: 16px; }
  .top img { height: 50px !important; width: auto; max-width: none; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 26px; margin-bottom: 20px; }
  .grid h2 { font-size: 10.5px; text-transform: uppercase; letter-spacing: .12em;
             color: var(--text-muted); margin: 0 0 6px; font-family: var(--font-mono); }
  .grid p { margin: 0; font-size: 13.5px; line-height: 1.6; }
  .bar { display: flex; gap: 24px; flex-wrap: wrap; font-size: 12.5px; color: var(--text-body);
         border-top: 1px solid var(--border-subtle); border-bottom: 1px solid var(--border-subtle);
         padding: 10px 0; margin-bottom: 20px; }
  .bar b { font-family: var(--font-mono); color: var(--text-strong); }
  table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
  th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .1em;
       color: var(--text-muted); border-bottom: 1px solid var(--border-default); padding: 0 8px 7px; }
  td { padding: 9px 8px; border-bottom: 1px solid var(--border-subtle); vertical-align: top; }
  td.num, th.num { text-align: right; font-family: var(--font-mono); white-space: nowrap; }
  .tick { width: 26px; }
  .tick span { display: block; width: 15px; height: 15px; border: 1.5px solid var(--text-muted); border-radius: 3px; }
  tfoot td { border-bottom: 0; border-top: 2px solid var(--text-strong); font-weight: 700; }
  .warn { margin-top: 16px; padding: 10px 13px; border-radius: 8px; font-size: 12.5px; line-height: 1.6;
          background: var(--paper-200); color: var(--brand-700); }
  .note { margin-top: 14px; font-size: 12.5px; }
  .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 44px; font-size: 12px; color: var(--text-muted); }
  .sign div { border-top: 1px solid var(--border-default); padding-top: 6px; text-align: center; }
  .noprint { text-align: center; margin: 18px; }
  .noprint button, .noprint a { font: 600 14px var(--font-sans); padding: 11px 20px; border-radius: 999px;
                    border: none; background: var(--brand); color: #fff; cursor: pointer;
                    text-decoration: none; display: inline-block; }
  @media print {
    body { background: #fff; }
    .sheet { margin: 0; padding: 0; box-shadow: none; max-width: none; }
    .noprint { display: none; }
    @page { size: A4; margin: 16mm; }
  }
</style>
</head>
<body>
<div class="noprint">
  <button type="button" onclick="window.print()">Drukuj / zapisz PDF</button>
  <a href="zamowienia.php?id=<?= (int) $o['id'] ?>&amp;etykieta=1">Etykieta wysyłkowa →</a>
</div>

<div class="sheet">
  <div class="top">
    <div>
      <h1>Zamówienie</h1>
      <div class="no"><?= h($o['code']) ?></div>
    </div>
    <img src="<?= h(console_logo_src()) ?>" alt="">
  </div>

  <div class="grid">
    <div>
      <h2>Sprzedawca</h2>
      <p><b><?= h((string) ($cfg['seller_name'] ?? wsm_brand_name())) ?></b><br>
         <?= h((string) ($cfg['seller_address'] ?? '')) ?></p>
    </div>
    <div>
      <h2>Odbiorca</h2>
      <p><b><?= h(trim(($o['company'] ?: $o['first_name'] . ' ' . $o['last_name']))) ?></b><br>
         <?= h($o['email']) ?><?= $o['phone'] !== '' ? ' · ' . h($o['phone']) : '' ?><br>
         <?php if (wsm_ship_kind($pdo, (string) $o['delivery_method']) === 'punkt'): ?>
           Punkt odbioru <b><?= h($o['inpost_point']) ?></b>
         <?php else: ?>
           <?= h($o['ship']['street'] . ' ' . $o['ship']['building']) ?><br>
           <?= h($o['ship']['postcode'] . ' ' . $o['ship']['city']) ?>, <?= h($o['ship']['country']) ?>
         <?php endif; ?></p>
    </div>
  </div>

  <div class="bar">
    <span>Data <b><?= h(substr((string) $o['created_at'], 0, 16)) ?></b></span>
    <span>Płatność <b><?= h($o['payment_status']) ?></b></span>
    <span>Waga <b><?= number_format($o['weight_g'] / 1000, 2, ',', ' ') ?> kg</b></span>
    <span>Gabaryt <b><?= h($o['parcel_template'] ?: '—') ?></b></span>
    <?php if (($o['discount_percent'] ?? 0) > 0): ?><span>Rabat <b>−<?= (int) $o['discount_percent'] ?> %</b></span><?php endif; ?>
  </div>

  <table>
    <thead>
      <tr><th class="tick">✓</th><th>Produkt</th><th>Symbol</th><th class="num">Ilość</th>
          <th class="num">Do wykonania</th><th class="num">Wartość</th></tr>
    </thead>
    <tbody>
      <?php foreach ($o['items'] as $l): ?>
      <tr>
        <td class="tick"><span></span></td>
        <td><?= h((string) $l['name']) ?></td>
        <td><?= h((string) ($l['sku'] ?? '')) ?: '—' ?></td>
        <td class="num"><?= (int) $l['qty'] ?></td>
        <td class="num"><?= (int) ($l['backorder'] ?? 0) ?: '—' ?></td>
        <td class="num"><?= $zl((int) $l['line_gross']) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr>
        <td class="tick"></td><td>Dostawa — <?= h($o['delivery_method']) ?></td><td></td>
        <td class="num"></td><td class="num"></td><td class="num"><?= $zl((int) $o['shipping_gross']) ?></td>
      </tr>
    </tbody>
    <tfoot>
      <tr><td class="tick"></td><td colspan="4">Razem brutto</td>
          <td class="num"><?= $zl((int) $o['total_gross']) ?> <?= h($o['currency']) ?></td></tr>
    </tfoot>
  </table>

  <?php if ($manque): ?>
  <div class="warn">
    <b>Uwaga — pozycje do wykonania.</b> Część towaru nie była na stanie w chwili zamówienia.
    Klient został o tym powiadomiony mailem. Nie pakuj tego zamówienia jako kompletnego:
    <?php foreach ($manque as $l): ?>
      <br>· <?= h((string) $l['name']) ?> — brakuje <?= (int) $l['backorder'] ?> szt.
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (($o['note'] ?? '') !== ''): ?>
  <p class="note"><b>Uwagi klienta:</b> <?= h((string) $o['note']) ?></p>
  <?php endif; ?>

  <p class="note" style="color:var(--text-muted)">
    Dokument roboczy zamówienia — nie jest fakturą ani paragonem.
  </p>

  <div class="sign">
    <div>Skompletował</div>
    <div>Sprawdził</div>
  </div>
</div>
</body>
</html>
