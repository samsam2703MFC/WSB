<?php
// ============================================================================
//  pulpit.php — le tableau de bord DU WEBSHOP.
//
//  La console héritée affiche un « Pulpit sieci » : chiffre d'affaires réseau,
//  boutiques de Bruxelles, adoption de la whitelist. C'est le tableau de bord
//  d'une franchise, pas d'une boutique en ligne — il vient de la démonstration
//  d'origine et ne dit rien de Mister Szoko.
//
//  Cet écran-ci ne montre que ce qui existe vraiment : les commandes, l'argent
//  encaissé, ce qui attend une action humaine, et l'état des intégrations.
//  Tout est lu en base ; aucune valeur n'est écrite en dur.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/mail.php';
require_once $API . '/stripe.php';
require_once $API . '/inpost.php';

$kpis   = wsm_shop_kpis($pdo);
$mail   = wsm_mail_kpis($pdo);
$orders = wsm_orders_list($pdo, 8);

// Ce qui attend quelqu'un DU CÔTÉ DES CLIENTS. Un tableau de bord qui ne
// montre que les commandes du jour laisse partir les habitués en silence :
// personne ne remarque une absence, on ne remarque qu'une présence.
require_once $API . '/crm.php';
$alertes = wsm_crm_alerts($pdo, 5);

// Ce qui attend quelqu'un : commandes hors stock non encore traitées, et
// paiements qui n'arrivent pas.
$toConfirm = (int) $pdo->query("SELECT COUNT(*) FROM wsm_orders WHERE backorder = 1 AND status IN ('nowe','oplacone')")->fetchColumn();

$lowStock = $pdo->query("SELECT id, nom, stock FROM wsm_products
                          WHERE shop_visible = 1 AND stock <= 3 ORDER BY stock, nom LIMIT 8")->fetchAll();
$noPhoto  = (int) $pdo->query("SELECT COUNT(*) FROM wsm_products WHERE shop_visible = 1 AND (image_url IS NULL OR image_url = '')")->fetchColumn();
$visible  = (int) $pdo->query("SELECT COUNT(*) FROM wsm_products WHERE shop_visible = 1")->fetchColumn();

$missing = [];
if (!wsm_stripe_ready())  $missing[] = 'Stripe (płatności online)';
if (!wsm_inpost_enabled()) $missing[] = 'InPost ShipX (etykiety)';
if (!wsm_mail_enabled())   $missing[] = 'poczta (wiadomości do klientów)';

// LE JOURNAL DES ERREURS (api/data/bledy.log). Chaque requête refusée par la
// base et chaque erreur imprévue d'une page y est écrite, avec la requête.
// Il n'était lisible qu'au déploiement ; le voici pour qui gère les réglages
// (mêmes droits qu'Integracje). « Przejrzane » archive ce qui a été lu, pour
// que le panneau ne montre plus que ce qui arrive ensuite.
$bledyWidac = console_ouvre($me, 'ustawienia.php');
$bledyNota = '';
if ($bledyWidac && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['bledy_przejrzane'])) {
    if (console_csrf_ok() && wsm_journal_archiwizuj()) {
        wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Przegląd', 'dziennik błędów (api/data/bledy.log)', 'Sieć');
        header('Location: pulpit.php?bledy=przejrzane#bledy', true, 303);
        exit;
    }
    $bledyNota = 'Nie udało się oznaczyć dziennika jako przejrzanego (odśwież stronę i spróbuj ponownie).';
}
$bledy = $bledyWidac ? wsm_journal_lire(15) : ['wpisy' => [], 'razem' => 0, 'tydzien' => 0, 'od' => null, 'zapis' => true];

$statusLabel = ['nowe' => 'Nowe', 'oplacone' => 'Opłacone', 'w_realizacji' => 'W realizacji',
                'wyslane' => 'Wysłane', 'dostarczone' => 'Dostarczone', 'anulowane' => 'Anulowane'];

// ─── LE PASSAGE DES COMMANDES, AUJOURD'HUI ──────────────────────────────────
//
// Huit tuiles de même poids ne disaient pas ce qui presse. Une seule bande
// suit le chemin d'une commande — payer, préparer, expédier — avec, en rouge,
// ce qui a dépassé l'échéance. Chaque étape ouvre la file de Zamówienia qui
// porte EXACTEMENT le même ensemble (mêmes statuts, même seuil d'échéance) :
// un chiffre qui annonce 7 et ouvre 5 lignes fait chercher les deux autres.
require_once $API . '/invoice.php';
$compte = function (array $sts) use ($pdo): int {
    return (int) $pdo->query("SELECT COUNT(*) FROM wsm_orders WHERE status IN ('" . implode("','", $sts) . "')")->fetchColumn();
};
$seuilTermin = date('Y-m-d H:i:s', wsm_db_now($pdo) - wsm_ship_delai_h() * 3600);
$stT = $pdo->prepare("SELECT COUNT(*) FROM wsm_orders WHERE status IN ('nowe','oplacone','w_realizacji') AND "
    . wsm_sql_avec_date($pdo, 'paid_at') . ' AND paid_at < ? AND ' . wsm_sql_sans_date($pdo, 'shipped_at'));
$stT->execute([$seuilTermin]);
$przeplyw = [
    ['nowe',     'Czeka na płatność', $compte(['nowe']),                   'przygotowanie', 'wait'],
    ['oplacone', 'Do spakowania',     $compte(['oplacone']),               'przygotowanie', ''],
    ['wysylka',  'Do wysłania',       $compte(['w_realizacji']),           'wysylka',       ''],
    ['wyslane',  'Wysłane',           $compte(['wyslane', 'dostarczone']), 'wyslane',       'mute'],
    ['termin',   'Po terminie',       (int) $stT->fetchColumn(),           'termin',        'hot'],
];
$zamLink = console_ouvre($me, 'zamowienia.php');
$kolor = ['nowe' => '#C99A45', 'oplacone' => 'var(--success)', 'wysylka' => 'var(--info)', 'wyslane' => 'var(--wk-rule)'];
$sumaBar = array_sum(array_map(fn($k) => $k[2], array_slice($przeplyw, 0, 4)));
$jours = ['niedziela','poniedziałek','wtorek','środa','czwartek','piątek','sobota'];
$mois  = ['','stycznia','lutego','marca','kwietnia','maja','czerwca','lipca','sierpnia','września','października','listopada','grudnia'];
$teraz = wsm_db_now($pdo);
$dzis  = $jours[(int) date('w', $teraz)] . ' ' . (int) date('j', $teraz) . ' ' . $mois[(int) date('n', $teraz)];

// ─── CE QUI ATTEND QUELQU'UN, DU PLUS PRESSANT AU MOINS PRESSANT ────────────
// Une seule liste au lieu de quatre panneaux : le retard, puis ce qu'un client
// attend, puis ce qui gêne la vente, puis ce qu'il est bon de savoir.
$zadania = [];
$poTerm = $przeplyw[4][2];
if ($poTerm > 0) $zadania[] = ['alert', $poTerm, 'Wyślij zaległe paczki', 'Obiecane klientom — termin minął', 'zamowienia.php?kolejka=termin', 'Pokaż', true];
if ($toConfirm > 0) $zadania[] = ['info', $toConfirm, 'Potwierdź zamówienia ponad stan', 'Klient czeka na wiadomość z terminem', 'zamowienia.php?kolejka=przygotowanie', 'Przejrzyj', false];
if ((int) $mail['failed'] > 0) $zadania[] = ['alert', (int) $mail['failed'], 'Wiadomości, które nie wyszły', 'Klient ich nie dostał — sprawdź pocztę', 'poczta.php', 'Poczta', false];
if ($noPhoto > 0) $zadania[] = ['wait', $noPhoto, 'Dodaj zdjęcia produktów', 'Klient widzi teraz tylko kolorowy kafelek', 'produkty.php', 'Dodaj', false];
if ($lowStock) $zadania[] = ['wait', count($lowStock), 'Produkty na wyczerpaniu', implode(', ', array_map(fn($p) => $p['nom'] . ' (' . (int) $p['stock'] . ')', array_slice($lowStock, 0, 3))), 'magazyn.php', 'Magazyn', false];
foreach ($alertes as $a) $zadania[] = ['mute', '', (string) $a['nom'], $a['texte'] . ' ' . $a['geste'], (string) $a['href'], 'Klient', false];

console_head('Pulpit', $me, <<<'CSS'
  .bledy td.co { font-family: var(--font-mono, monospace); font-size: 12.5px; line-height: 1.5; word-break: break-word; }
  .bledy td.kiedy { white-space: nowrap; font-size: 13px; }
  .bledy-ok { font-size: 13px; color: var(--text-muted); margin-top: 18px; }
CSS);
?>

<?php
require_once $API . '/start.php';
$startAv = wsm_start_avancement($pdo);
$startLien = $startAv['nastepny'] !== null
    ? console_lien($me, 'start.php#' . $startAv['nastepny'], wsm_start_etapy()[$startAv['nastepny']]['tytul'])
    : '';
if ($startLien !== ''): ?>
<p class="wk-note"><b>Start sklepu: <?= (int) $startAv['fait'] ?> z <?= (int) $startAv['razem'] ?></b>
  <span>Następny krok: <?= $startLien ?></span></p>
<?php endif; ?>

<?php if ($missing): ?>
<p class="wk-note"><b><?= count($missing) === 1 ? 'Integracja czeka' : count($missing) . ' integracje czekają' ?> na dane</b>
  <span><?= h(implode(' · ', $missing)) ?> — pola są wypełnione „xxxx”.</span>
  <?php $lienUst = console_lien($me, 'ustawienia.php', 'Uzupełnij'); ?>
  <?= $lienUst !== '' ? $lienUst : '<span>Uzupełnia je administrator.</span>' ?></p>
<?php endif; ?>

<section class="wk-flow">
  <div class="wk-flow-h">
    <h2>Dziś, <?= h($dzis) ?></h2>
    <span class="muted"><?= (int) $kpis['orders'] ?> zamówień w sklepie</span>
    <div class="wk-sum">
      <div><b><?= h(pln((int) $kpis['revenue_gross'])) ?></b>obrót brutto</div>
      <div><b><?= h(pln((int) $kpis['basket_avg'])) ?></b>średni koszyk</div>
      <div><b><?= $visible ?></b>produktów w sklepie</div>
    </div>
  </div>
  <?php if ($sumaBar > 0): ?>
  <div class="wk-bar" aria-hidden="true">
    <?php foreach (array_slice($przeplyw, 0, 4) as [$k, , $n]): if ($n <= 0) continue; ?>
    <i style="flex:<?= $n ?>;background:<?= $kolor[$k] ?>"></i>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="wk-steps">
    <?php foreach ($przeplyw as [$k, $nazwa, $n, $kol, $ton]):
      $cls = 'wk-step' . ($ton === 'hot' && $n > 0 ? ' hot' : '') . ($ton === 'wait' && $n > 0 ? ' wait' : '') . ($n === 0 ? ' zero' : '');
      $tag = $zamLink ? 'a href="zamowienia.php?kolejka=' . h($kol) . '"' : 'div'; ?>
    <<?= $tag ?> class="<?= $cls ?>"><b><?= $n ?></b><span><?= h($nazwa) ?></span></<?= $zamLink ? 'a' : 'div' ?>>
    <?php endforeach; ?>
  </div>
</section>

<div class="wk-cols">
  <section class="panel wk-panel">
    <header><h2>Do zrobienia teraz</h2><span class="muted small">od najpilniejszego</span></header>
    <?php if (!$zadania): ?>
    <p class="wk-pusto">Nic nie czeka. Nowe zamówienia i sprawy klientów pojawią się tutaj.</p>
    <?php else: ?>
    <ul class="wk-todo">
      <?php foreach ($zadania as [$ton, $n, $tyt, $opis, $href, $akcja, $pri]):
        $lien = console_ouvre($me, (string) strtok($href, '?')) ? $href : ''; ?>
      <li>
        <span class="wk-n t-<?= $ton ?>"><?= $n === '' ? '·' : (int) $n ?></span>
        <div><b><?= h($tyt) ?></b><small><?= h($opis) ?></small></div>
        <?php if ($lien !== ''): ?><a class="btn<?= $pri ? ' btn--brand' : '' ?>" href="<?= h($lien) ?>"><?= h($akcja) ?></a><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>

  <section class="panel wk-panel">
    <header><h2>Ostatnie zamówienia</h2><?= console_lien($me, 'zamowienia.php', 'Wszystkie') ?></header>
    <?php if (!$orders): ?>
    <p class="wk-pusto">Jeszcze żadnego zamówienia. Pierwsze pojawi się tutaj w chwili złożenia.</p>
    <?php else: ?>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Numer</th><th>Klient</th><th class="num">Brutto</th><th>Stan</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
      <tr>
        <td data-l="Numer"><?= console_lien($me, 'zamowienia.php?id=' . (int) $o['id'], (string) $o['code'], 'code')
                                 ?: '<span class="code">' . h((string) $o['code']) . '</span>' ?></td>
        <td data-l="Klient"><?= h($o['client']) ?></td>
        <td data-l="Brutto" class="num"><?= h(pln((int) $o['total_gross'])) ?></td>
        <td data-l="Stan"><span class="stan s-<?= h((string) $o['status']) ?>"><i class="kropka"></i><?= h($statusLabel[$o['status']] ?? $o['status']) ?></span>
          <?php if (!empty($o['backorder'])): ?> <span class="tag no">ponad stan</span><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </section>
</div>

<?php if ($bledyWidac && ($bledy['razem'] > 0 || $bledyNota !== '')): ?>
<div class="panel bledy" id="bledy">
  <h2>Dziennik błędów <span class="code"><?= (int) $bledy['razem'] ?></span></h2>
  <p class="muted small">Każde zapytanie odrzucone przez bazę i każdy nieprzewidziany błąd strony — od <?= h((string) $bledy['od']) ?>,
    w tym <b><?= (int) $bledy['tydzien'] ?></b> z ostatnich 7 dni. Jeśli coś się powtarza, prześlij te wiersze.</p>
  <?php if ($bledyNota !== ''): ?><p class="warnbox"><?= h($bledyNota) ?></p><?php endif; ?>
  <div class="tablewrap">
  <table class="rwd">
    <thead><tr><th>Kiedy</th><th>Gdzie</th><th>Co</th></tr></thead>
    <tbody>
    <?php foreach ($bledy['wpisy'] as $w): ?>
      <tr>
        <td data-l="Kiedy" class="kiedy"><?= h($w['kiedy']) ?></td>
        <td data-l="Gdzie"><?= h($w['gdzie']) ?></td>
        <td data-l="Co" class="co" title="<?= h($w['co']) ?>"><?= h(mb_strimwidth($w['co'], 0, 260, '…')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($bledy['razem'] > count($bledy['wpisy'])): ?>
  <p class="muted small">Pokazane ostatnie <?= count($bledy['wpisy']) ?> z <?= (int) $bledy['razem'] ?>; całość: api/data/bledy.log na serwerze.</p>
  <?php endif; ?>
  <form method="post" style="margin-top:12px">
    <?= console_csrf_field() ?>
    <button type="submit" name="bledy_przejrzane" value="1">Oznacz jako przejrzane</button>
    <span class="muted small">— lista zacznie się od nowa; te wpisy zostają w archiwum (bledy.log.1).</span>
  </form>
</div>
<?php endif; ?>

<?php if ($bledyWidac && !$bledy['zapis']): ?>
<p class="warnbox" id="bledy">Dziennik błędów: serwer <b>nie może zapisywać</b> do katalogu api/data —
  błędy nie są notowane, więc pusty dziennik nic nie znaczy. Trzeba nadać serwerowi WWW prawo zapisu do api/data.</p>
<?php elseif ($bledyWidac && $bledy['razem'] === 0): ?>
<p class="bledy-ok" id="bledy">Dziennik błędów: pusty<?= ($_GET['bledy'] ?? '') === 'przejrzane' ? ' — oznaczony jako przejrzany' : '' ?>.
  Każde odrzucone zapytanie albo błąd strony pojawi się tutaj.</p>
<?php endif; ?>
<?php console_foot();
