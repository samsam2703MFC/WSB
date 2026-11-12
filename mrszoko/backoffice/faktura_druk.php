<?php
// ============================================================================
//  faktura_druk.php — le document lui-même, tel qu'il s'imprime.
//
//  Inclus par faktury.php avec $i (la facture hydratée). Volontairement sans
//  la console autour : c'est une page qui doit tenir sur une feuille A4 et
//  produire un PDF correct par l'impression du navigateur. Pas de
//  bibliothèque PDF à maintenir pour un document d'une page — et le résultat
//  est identique sur tous les postes, parce que le rendu est du HTML.
//
//  Rien n'est recalculé ici : tout vient de la facture, figée à l'émission.
// ============================================================================
declare(strict_types=1);

$i = $detail;
$kindTitle = ['faktura' => 'Faktura VAT', 'korekta' => 'Faktura korygująca',
              'paragon' => 'E-paragon', 'proforma' => 'Faktura proforma'];
$zl = fn(int $g) => number_format($g / 100, 2, ',', ' ');
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($i['number']) ?> — <?= h($i['seller_name']) ?></title>
<link rel="stylesheet" href="_ds/mister-szoko/global.css">
<link rel="stylesheet" href="_ds/mister-szoko/brand.css">
<style>
  body { margin: 0; background: var(--bg-page-alt); font-family: var(--font-sans); color: var(--text-strong); }
  .sheet { max-width: 820px; margin: 24px auto; background: #fff; padding: 40px 44px;
           box-shadow: var(--shadow-sm, 0 8px 30px rgba(0,0,0,.08)); border-radius: 6px; }
  .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; margin-bottom: 28px; }
  .top h1 { font-family: var(--font-display); font-size: 26px; margin: 0 0 4px; }
  .top .no { font-family: var(--font-mono); font-size: 15px; color: var(--text-body); }
  .top img { height: 54px !important; width: auto; max-width: none; }
  .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; margin-bottom: 26px; }
  .parties h2 { font-size: 10.5px; text-transform: uppercase; letter-spacing: .12em;
                color: var(--text-muted); margin: 0 0 6px; font-family: var(--font-mono); }
  .parties p { margin: 0; font-size: 13.5px; line-height: 1.6; }
  .dates { display: flex; gap: 26px; flex-wrap: wrap; font-size: 12.5px; color: var(--text-body);
           border-top: 1px solid var(--border-subtle); border-bottom: 1px solid var(--border-subtle);
           padding: 10px 0; margin-bottom: 22px; }
  .dates b { font-family: var(--font-mono); color: var(--text-strong); }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .1em;
       color: var(--text-muted); border-bottom: 1px solid var(--border-default); padding: 0 8px 7px; }
  td { padding: 8px; border-bottom: 1px solid var(--border-subtle); vertical-align: top; }
  td.num, th.num { text-align: right; font-family: var(--font-mono); white-space: nowrap; }
  tfoot td { border-bottom: 0; font-weight: 600; }
  .sum { margin-top: 18px; display: flex; justify-content: flex-end; }
  .sum table { width: auto; min-width: 300px; }
  .sum td { border-bottom: 0; padding: 4px 8px; }
  .total td { border-top: 2px solid var(--text-strong); font-size: 16px; font-weight: 700; padding-top: 8px; }
  .pay { margin-top: 28px; font-size: 13px; line-height: 1.7; }
  .pay b { font-family: var(--font-mono); }
  .note { margin-top: 18px; font-size: 12.5px; color: var(--text-body); }
  .rc { margin-top: 14px; padding: 9px 12px; border-radius: 6px; font-size: 12.5px;
        background: var(--paper-200); color: var(--brand-700); }
  .foot { margin-top: 34px; font-size: 11px; color: var(--text-muted); line-height: 1.6; }
  .noprint { text-align: center; margin: 18px; }
  .noprint button { font: 600 14px var(--font-sans); padding: 11px 20px; border-radius: 999px;
                    border: none; background: var(--brand); color: #fff; cursor: pointer; }
  /* ── LE PARAGON S'IMPRIME EN TICKET, PAS EN A4 ──────────────────────────
     Il sortait dans la mise en page d'une facture : une pleine feuille pour
     deux lignes d'achat, avec des colonnes de facture et un cadre de paiement
     qui ne le concernent pas. Un ticket se lit en colonne étroite, chiffres
     alignés à droite, et se plie dans un colis. */
  .ticket { max-width: 340px; margin: 20px auto; background: #fff; padding: 26px 24px;
            box-shadow: var(--shadow-sm, 0 8px 30px rgba(0,0,0,.08)); border-radius: 6px;
            font-family: var(--font-mono); font-size: 12px; line-height: 1.55; color: #111; }
  .t-head { text-align: center; margin-bottom: 14px; }
  .t-head .nom { font-family: var(--font-sans); font-weight: 700; font-size: 14px; }
  .t-meta { display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 12px; }
  .t-tit { text-align: center; font-family: var(--font-sans); font-weight: 700; letter-spacing: .04em;
           font-size: 14px; margin: 14px 0; }
  .t-l { display: grid; grid-template-columns: 1fr auto; gap: 0 10px; }
  .t-l .r { text-align: right; white-space: nowrap; }
  .t-art { margin: 3px 0 7px; }
  .t-art .nazwa { font-family: var(--font-sans); }
  .t-art .det { font-size: 11px; color: #555; }
  .t-sep { border-top: 1px dashed #999; margin: 10px 0; }
  .t-suma { font-family: var(--font-sans); font-weight: 700; font-size: 15px; }
  .t-note { margin-top: 14px; font-family: var(--font-sans); font-size: 10.5px;
            line-height: 1.5; color: #444; text-align: center; }

  @media print {
    body { background: #fff; }
    .ticket { margin: 0; padding: 0; box-shadow: none; max-width: 78mm; }
    .sheet { margin: 0; padding: 0; box-shadow: none; max-width: none; }
    .noprint { display: none; }
    @page { size: A4; margin: 16mm; }
  }
</style>
</head>
<body>
<div class="noprint"><button type="button" onclick="window.print()">Drukuj / zapisz PDF</button></div>

<?php if ($i['kind'] === 'paragon'):
  // La ventilation par TAUX, pas par ligne : c'est ce que porte un ticket, et
  // c'est ce qu'un comptable y cherche. Les lettres A/B/C/D sont la convention
  // polonaise — A = 23 %, B = 8 %, C = 5 %, D = 0 %.
  $lettre = fn(float $t): string => [23 => 'A', 8 => 'B', 5 => 'C', 0 => 'D'][(int) round($t * 100)] ?? 'A';
  $parTaux = wsm_paragon_ptu($i['items']); ?>
<div class="ticket">
  <div class="t-head">
    <div class="nom"><?= h($i['seller_name']) ?></div>
    <div><?= h($i['seller_address']) ?></div>
  </div>
  <div class="t-meta">
    <span>NIP <?= h($i['seller_nip']) ?></span>
    <span>nr dok. <?= h($i['number']) ?></span>
  </div>

  <div class="t-tit"><?= h($kindTitle[$i['kind']]) ?></div>

  <div class="t-sep"></div>
  <?php foreach ($i['items'] as $l): ?>
  <div class="t-art">
    <div class="nazwa"><?= h($l['name']) ?></div>
    <div class="t-l det">
      <span><?= (int) $l['qty'] ?> × <?= $zl((int) $l['unit_gross']) ?></span>
      <span class="r"><?= $zl((int) $l['line_gross']) ?> <?= h($lettre((float) $l['vat_rate'])) ?></span>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="t-sep"></div>
  <?php foreach ($parTaux as $k => $t): ?>
  <div class="t-l"><span>Sprzedaż opodatkowana <?= h($k) ?></span><span class="r"><?= $zl($t['brut']) ?></span></div>
  <?php endforeach; ?>
  <?php foreach ($parTaux as $k => $t): ?>
  <div class="t-l"><span>PTU <?= h($k) ?> <?= h(wsm_vat_percent($t['taux'])) ?> %</span><span class="r"><?= $zl($t['vat']) ?></span></div>
  <?php endforeach; ?>
  <div class="t-l"><span>Suma PTU</span><span class="r"><?= $zl((int) $i['total_vat']) ?></span></div>

  <div class="t-sep"></div>
  <div class="t-l t-suma"><span>SUMA <?= h($i['currency']) ?></span><span class="r"><?= $zl((int) $i['total_gross']) ?></span></div>

  <div class="t-sep"></div>
  <div class="t-l"><span><?= $i['paid'] ? 'Zapłacono' : 'Do zapłaty' ?></span><span class="r"><?= $zl((int) $i['total_gross']) ?></span></div>
  <div class="t-l det"><span><?= h($i['issued_at']) ?></span><span class="r"><?= h($i['buyer_name']) ?></span></div>

  <?php // CE QUE CE DOCUMENT N'EST PAS. Il ressemble maintenant à un ticket ;
        // il faut donc dire, sur le papier, qu'il ne sort pas d'une caisse
        // enregistreuse et qu'il ne porte aucun numéro fiscal. Un document qui
        // en a l'air sans l'être se retrouve produit à l'administration. ?>
  <div class="t-note">
    Dokument sprzedaży dla klienta indywidualnego bez NIP.<br>
    <b>Nie jest paragonem fiskalnym</b> — nie pochodzi z kasy rejestrującej.
    <?php if ($i['note'] !== ''): ?><br><?= h($i['note']) ?><?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="sheet">
  <div class="top">
    <div>
      <h1><?= h($kindTitle[$i['kind']] ?? 'Dokument') ?></h1>
      <div class="no"><?= h($i['number']) ?></div>
      <?php if (in_array($i['kind'], ['korekta', 'proforma'], true) && $i['corrects_id']): ?>
        <?php $src = wsm_invoice_by_id($pdo, (int) $i['corrects_id']); ?>
        <div class="no">do faktury <?= h($src['number'] ?? '') ?></div>
      <?php endif; ?>
    </div>
    <img src="<?= h(console_logo_src()) ?>" alt="<?= h($i['seller_name']) ?>">
  </div>

  <div class="parties">
    <div>
      <h2>Sprzedawca</h2>
      <p><b><?= h($i['seller_name']) ?></b><br>
         <?= h($i['seller_address']) ?><br>
         NIP <?= h($i['seller_nip']) ?></p>
    </div>
    <div>
      <h2>Nabywca</h2>
      <p><b><?= h($i['buyer_name']) ?></b><br>
         <?= h($i['buyer_address']) ?>
         <?php if ($i['buyer_nip'] !== ''): ?><br>NIP <?= h($i['buyer_nip']) ?><?php endif; ?>
         <?php if ($i['buyer_vat_eu'] !== ''): ?><br>VAT UE <?= h($i['buyer_vat_eu']) ?><?php endif; ?></p>
    </div>
  </div>

  <div class="dates">
    <span>Data wystawienia <b><?= h($i['issued_at']) ?></b></span>
    <span>Data sprzedaży <b><?= h($i['sold_at']) ?></b></span>
    <span>Miejsce <b><?= h($i['place']) ?></b></span>
    <?php if ($i['kind'] !== 'paragon'): ?><span>Termin płatności <b><?= h($i['due_at']) ?></b></span><?php endif; ?>
  </div>

  <table>
    <thead>
      <tr><th>Nazwa</th><th class="num">Ilość</th><th class="num">Cena netto</th>
          <th class="num">VAT</th><th class="num">Netto</th><th class="num">Brutto</th></tr>
    </thead>
    <tbody>
      <?php foreach ($i['items'] as $l): ?>
      <tr>
        <td><?= h((string) $l['name']) ?><?= (string) $l['sku'] !== '' ? '<br><small style="color:var(--text-muted)">' . h((string) $l['sku']) . '</small>' : '' ?></td>
        <td class="num"><?= (int) $l['qty'] ?></td>
        <td class="num"><?= $zl((int) $l['unit_net']) ?></td>
        <td class="num"><?= h(wsm_vat_percent((float) $l['vat_rate'])) ?> %</td>
        <td class="num"><?= $zl((int) $l['line_net']) ?></td>
        <td class="num"><?= $zl((int) $l['line_gross']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="sum">
    <table>
      <?php foreach ($i['vat_breakdown'] as $b): ?>
      <tr>
        <td>Stawka <?= h(wsm_vat_percent((float) $b['rate'])) ?> %</td>
        <td class="num">netto <?= $zl((int) $b['net']) ?></td>
        <td class="num">VAT <?= $zl((int) $b['vat']) ?></td>
        <td class="num">brutto <?= $zl((int) $b['gross']) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="total">
        <td>Do zapłaty</td><td class="num"></td><td class="num"></td>
        <td class="num"><?= $zl((int) $i['total_gross']) ?> <?= h($i['currency']) ?></td>
      </tr>
    </table>
  </div>

  <?php if ($i['reverse_charge']): ?>
  <div class="rc"><b>Odwrotne obciążenie</b> — podatek rozlicza nabywca
    (art. 28b ustawy o VAT / mechanizm odwrotnego obciążenia w obrocie wewnątrzwspólnotowym).</div>
  <?php endif; ?>

  <?php if ($i['kind'] !== 'paragon'): ?>
  <div class="pay">
    Płatność przelewem na rachunek:<br>
    <b><?= h($i['iban']) ?></b><?= $i['bank'] !== '' ? ' · ' . h($i['bank']) : '' ?><br>
    W tytule prosimy podać numer <b><?= h($i['number']) ?></b>.
    <?php if ($i['paid']): ?><br><b>Zapłacono</b> — dokument nie wymaga przelewu.<?php endif; ?>
  </div>
  <?php else: ?>
  <div class="pay">Dokument dla klienta indywidualnego bez NIP — e-paragon, nie faktura.</div>
  <?php endif; ?>

  <?php if ($i['kind'] === 'proforma'): ?>
  <div class="rc"><b>Dokument nie jest fakturą VAT.</b> Faktura proforma jest propozycją zapłaty:
    nie stanowi podstawy do odliczenia podatku naliczonego i nie jest ujmowana w ewidencji VAT.
    Właściwa faktura zostanie wystawiona po otrzymaniu płatności.</div>
  <?php endif; ?>

  <?php if ($i['note'] !== ''): ?><div class="note">Uwaga: <?= h($i['note']) ?></div><?php endif; ?>

  <div class="foot">
    <?php if ($i['ksef_number'] !== ''): ?>KSeF: <?= h($i['ksef_number']) ?><br><?php endif; ?>
    Dokument wystawiony elektronicznie w sklepie <?= h($i['seller_name']) ?>.
  </div>
</div>
<?php endif; ?>
</body>
</html>
