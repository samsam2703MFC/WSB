<?php
// ============================================================================
//  etykieta_druk.php — l'étiquette d'expédition, format A6.
//
//  Deux cas, et il ne faut pas les confondre :
//
//   • InPost a créé l'envoi → l'étiquette qui fait foi est CELLE D'INPOST,
//     avec son code-barres et son numéro de suivi. La nôtre ne la remplace
//     pas : l'écran renvoie alors vers le PDF du transporteur, et cette page
//     ne sert que de pense-bête interne.
//   • Rien n'a encore été créé (intégration pas branchée, ou envoi manuel) →
//     cette étiquette est celle qu'on colle : expéditeur, destinataire,
//     numéro de commande, poids, gabarit. Elle ne prétend pas être un
//     bordereau transporteur, et le dit.
//
//  Inclus par zamowienia.php avec $o.
// ============================================================================
declare(strict_types=1);

$cfg = (array) (wsm_config()['invoice'] ?? []);
$st = $pdo->prepare("SELECT tracking_number, label_url, status FROM wsm_shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
$st->execute([(int) $o['id']]);
$ship = $st->fetch() ?: [];
$tracking = (string) ($ship['tracking_number'] ?? '');
$labelUrl = (string) ($ship['label_url'] ?? '');
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($o['code']) ?> — etykieta</title>
<link rel="stylesheet" href="_ds/mister-szoko/global.css">
<link rel="stylesheet" href="_ds/mister-szoko/brand.css">
<style>
  body { margin: 0; background: var(--bg-page-alt); font-family: var(--font-sans); color: #000; }
  .label {
    width: 105mm; min-height: 148mm;                 /* A6 */
    margin: 24px auto; background: #fff; padding: 10mm;
    box-shadow: 0 8px 30px rgba(0,0,0,.08); box-sizing: border-box;
    display: flex; flex-direction: column;
  }
  .from { font-size: 9pt; line-height: 1.35; border-bottom: 1.5px solid #000; padding-bottom: 4mm; }
  .from b { font-size: 10pt; }
  .to { padding: 6mm 0; flex: 1 1 auto; }
  .to .cap { font: 700 8pt var(--font-mono); letter-spacing: .12em; text-transform: uppercase; }
  .to .name { font-size: 16pt; font-weight: 700; line-height: 1.25; margin-top: 2mm; }
  .to .addr { font-size: 12pt; line-height: 1.45; margin-top: 2mm; }
  .to .locker { font-size: 20pt; font-weight: 700; font-family: var(--font-mono); margin-top: 3mm; }
  .facts { display: flex; justify-content: space-between; gap: 4mm; font-size: 9pt;
           border-top: 1.5px solid #000; padding-top: 3mm; }
  .facts b { font-family: var(--font-mono); font-size: 11pt; display: block; }
  .code { text-align: center; margin-top: 4mm; }
  .code .big { font-family: var(--font-mono); font-size: 15pt; font-weight: 700; letter-spacing: .04em; }
  .code small { display: block; font-size: 8pt; color: #444; margin-top: 1mm; }
  .warn { font-size: 8.5pt; color: #555; margin-top: 3mm; line-height: 1.4; }
  .noprint { text-align: center; margin: 18px; }
  .noprint button, .noprint a { font: 600 14px var(--font-sans); padding: 11px 20px; border-radius: 999px;
       border: none; background: var(--brand); color: #fff; cursor: pointer; text-decoration: none;
       display: inline-block; margin: 0 4px; }
  .noprint .ghost { background: transparent; color: var(--brand); border: 1px solid var(--brand); }
  @media print {
    body { background: #fff; }
    .label { margin: 0; box-shadow: none; width: auto; min-height: auto; padding: 0; }
    .noprint { display: none; }
    @page { size: A6; margin: 6mm; }
  }
</style>
</head>
<body>
<div class="noprint">
  <button type="button" onclick="window.print()">Drukuj etykietę</button>
  <a class="ghost" href="zamowienia.php?id=<?= (int) $o['id'] ?>&amp;druk=1">Bon zamówienia →</a>
  <?php if ($labelUrl !== ''): ?>
  <a href="<?= h($labelUrl) ?>" target="_blank" rel="noopener">Etykieta InPost (obowiązująca) ↗</a>
  <?php endif; ?>
</div>

<div class="label">
  <div class="from">
    <b><?= h((string) ($cfg['seller_name'] ?? wsm_brand_name())) ?></b><br>
    <?= h((string) ($cfg['seller_address'] ?? '')) ?>
    <?php if (($cfg['seller_nip'] ?? '') !== ''): ?><br>NIP <?= h((string) $cfg['seller_nip']) ?><?php endif; ?>
  </div>

  <div class="to">
    <div class="cap">Odbiorca</div>
    <div class="name"><?= h(trim(($o['company'] ?: $o['first_name'] . ' ' . $o['last_name']))) ?></div>
    <?php // Le TYPE de service, pas le nom : « ce n'est pas inpost_locker »
          // ne veut pas dire « c'est une adresse ». ?>
    <?php if (wsm_ship_kind($pdo, (string) $o['delivery_method']) === 'punkt'): ?>
      <div class="addr">Punkt odbioru</div>
      <div class="locker"><?= h($o['inpost_point']) ?></div>
      <div class="addr"><?= h($o['phone']) ?></div>
    <?php else: ?>
      <div class="addr">
        <?= h($o['ship']['street'] . ' ' . $o['ship']['building']) ?><br>
        <b><?= h($o['ship']['postcode']) ?></b> <?= h($o['ship']['city']) ?><br>
        <?= h($o['ship']['country']) ?><br>
        tel. <?= h($o['phone']) ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="facts">
    <span>Waga<b><?= number_format($o['weight_g'] / 1000, 2, ',', ' ') ?> kg</b></span>
    <span>Gabaryt<b><?= h($o['parcel_template'] ?: '—') ?></b></span>
    <span>Pozycji<b><?= count($o['items']) ?></b></span>
  </div>

  <div class="code">
    <div class="big"><?= h($tracking !== '' ? $tracking : $o['code']) ?></div>
    <small><?= $tracking !== '' ? 'Numer przesyłki InPost' : 'Numer zamówienia' ?></small>
  </div>

  <?php if ($tracking === ''): ?>
  <p class="warn">
    Etykieta wewnętrzna — nie zastępuje listu przewozowego. Po utworzeniu przesyłki w InPost
    obowiązuje etykieta przewoźnika, z kodem kreskowym.
  </p>
  <?php else: ?>
  <p class="warn">
    Przesyłka utworzona w InPost. Do naklejenia na paczkę obowiązuje <b>etykieta przewoźnika</b>
    (kod kreskowy); ta kartka służy jako opis wewnętrzny.
  </p>
  <?php endif; ?>
</div>
</body>
</html>
