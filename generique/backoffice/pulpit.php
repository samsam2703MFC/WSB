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

console_head('Pulpit', $me, <<<'CSS'
  .lead { font-size: 14px; line-height: 1.6; color: var(--text-muted); max-width: 80ch; margin: 0 0 20px; }
  .kpi.hot b { color: var(--danger); }
  .quick { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 4px; }
  .bledy td.co { font-family: var(--font-mono, monospace); font-size: 12.5px; line-height: 1.5; word-break: break-word; }
  .bledy td.kiedy { white-space: nowrap; font-size: 13px; }
  .bledy-ok { font-size: 13px; color: var(--text-muted); margin-top: 18px; }
  .quick a {
    display: inline-flex; align-items: center; min-height: 44px; padding: 0 16px;
    border-radius: 999px; text-decoration: none; font-size: 14px; font-weight: 600;
    background: var(--surface-card); border: 1px solid var(--border-subtle); color: var(--text-strong);
  }
CSS);
?>

<?php if ($alertes): ?>
<div class="panel">
  <h2>Klienci — co czeka na człowieka <span class="code"><?= count($alertes) ?></span></h2>
  <p class="lead" style="margin-bottom:12px">
    Nikt nie zauważa nieobecności — zauważa się tylko obecność. Dlatego stały klient,
    który przestał kupować, znika po cichu, a jest droższy od nowego.
  </p>
  <table class="rwd">
    <thead><tr><th>Klient</th><th>Co się dzieje</th><th>Co zrobić</th></tr></thead>
    <tbody>
    <?php foreach ($alertes as $a): ?>
      <tr>
        <?php // Le lien ne s'affiche que si l'écran s'ouvre : sinon on
              // envoie contre une porte fermée quelqu'un qui lisait juste
              // son tableau de bord. ?>
        <td data-l="Klient"><?= console_lien($me, (string) $a['href'], $a['nom']) ?: '<b>' . h($a['nom']) . '</b>' ?></td>
        <td data-l="Co się dzieje"><?= h($a['texte']) ?></td>
        <td data-l="Co zrobić" style="color:var(--text-muted)"><?= h($a['geste']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php $lienAnalyse = console_lien($me, 'klienci.php?widok=analiza', 'Cała analiza klientów →', 'code');
        if ($lienAnalyse !== ''): ?>
  <p style="margin-top:10px"><?= $lienAnalyse ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php
// L'ASSISTANT DE DÉMARRAGE, résumé : tant que la boutique n'est pas prête,
// le tableau de bord dit où on en est et quelle est la prochaine étape.
// Rien quand tout est fait — un bandeau qui reste après l'ouverture n'est
// plus lu par personne.
require_once $API . '/start.php';
$startAv = wsm_start_avancement($pdo);
$startLien = $startAv['nastepny'] !== null
    ? console_lien($me, 'start.php#' . $startAv['nastepny'], wsm_start_etapy()[$startAv['nastepny']]['tytul'] . ' →')
    : '';
if ($startLien !== ''): ?>
<p class="warnbox">
  Start sklepu: <b><?= (int) $startAv['fait'] ?> z <?= (int) $startAv['razem'] ?></b> kroków gotowe.
  Następny: <?= $startLien ?>
</p>
<?php endif; ?>

<?php if ($missing): ?>
<p class="warnbox">
  Sklep działa, ale te integracje czekają na dane:
  <b><?= h(implode(' · ', $missing)) ?></b>.
  <?php $lienUst = console_lien($me, 'ustawienia.php', 'Integracjach'); ?>
  <?= $lienUst !== '' ? 'Uzupełnij je w ' . $lienUst . ' — pola są wypełnione „xxxx”.'
                      : 'Uzupełnia je administrator w Ustawieniach — pola są wypełnione „xxxx”.' ?>
</p>
<?php endif; ?>

<?php if ($bledyWidac && ($bledy['razem'] > 0 || $bledyNota !== '')): ?>
<div class="panel bledy" id="bledy">
  <h2>Dziennik błędów <span class="code"><?= (int) $bledy['razem'] ?></span></h2>
  <p class="lead" style="margin-bottom:12px">
    Każde zapytanie odrzucone przez bazę i każdy nieprzewidziany błąd strony — od <?= h((string) $bledy['od']) ?>,
    w tym <b><?= (int) $bledy['tydzien'] ?></b> z ostatnich 7 dni. Jeśli coś się powtarza, prześlij te wiersze —
    mówią, co i gdzie nie zadziałało.
  </p>
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

<div class="kpis">
  <div class="kpi"><b><?= (int) $kpis['orders'] ?></b><span>Zamówienia</span></div>
  <div class="kpi"><b><?= (int) $kpis['orders_paid'] ?></b><span>Opłacone</span></div>
  <div class="kpi<?= $kpis['orders_pending'] ? ' hot' : '' ?>"><b><?= (int) $kpis['orders_pending'] ?></b><span>Czeka na płatność</span></div>
  <div class="kpi<?= $toConfirm ? ' hot' : '' ?>"><b><?= $toConfirm ?></b><span>Do potwierdzenia</span></div>
  <div class="kpi"><b><?= h(pln((int) $kpis['revenue_gross'])) ?></b><span>Obrót brutto</span></div>
  <div class="kpi"><b><?= h(pln((int) $kpis['basket_avg'])) ?></b><span>Średni koszyk</span></div>
  <div class="kpi<?= $mail['failed'] ? ' hot' : '' ?>"><b><?= (int) $mail['queued'] + (int) $mail['failed'] ?></b><span>Poczta do wysłania</span></div>
  <div class="kpi"><b><?= $visible ?></b><span>Produkty w sklepie</span></div>
</div>

<?php // LES RACCOURCIS SUIVENT LE PROFIL. Ils étaient écrits en dur : un
      // compte « Sprzedaż » y trouvait « Poczta », « Produkty » et
      // « Ustawienia », et recevait 403 sur les trois. Le rail, lui,
      // filtrait déjà — deux endroits décidaient du même accès. ?>
<p class="quick">
  <?= console_lien($me, 'zamowienia.php', 'Zamówienia →') ?>
  <?= console_lien($me, 'poczta.php', 'Poczta →') ?>
  <?= console_lien($me, 'produkty.php', 'Produkty →') ?>
  <a href="../shop/" target="_blank" rel="noopener">Zobacz sklep ↗</a>
</p>

<div class="cols" style="margin-top:22px">
  <div class="panel">
    <h2>Ostatnie zamówienia</h2>
    <?php if (!$orders): ?>
    <p class="muted small">Jeszcze żadnego zamówienia. Pierwsze pojawi się tutaj w chwili złożenia.</p>
    <?php else: ?>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Numer</th><th>Klient</th><th>Status</th><th class="num">Brutto</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
      <tr>
        <td data-l="Numer"><?= console_lien($me, 'zamowienia.php?id=' . (int) $o['id'], (string) $o['code'], 'code')
                                 ?: '<span class="code">' . h((string) $o['code']) . '</span>' ?></td>
        <td data-l="Klient"><?= h($o['client']) ?></td>
        <td data-l="Status"><span class="tag"><?= h($statusLabel[$o['status']] ?? $o['status']) ?></span>
          <?php if (!empty($o['backorder'])): ?> <span class="tag no">do potwierdzenia</span><?php endif; ?></td>
        <td data-l="Brutto" class="num"><?= h(pln((int) $o['total_gross'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Magazyn i witryna</h2>
    <?php if ($noPhoto): ?>
    <p class="small"><b><?= $noPhoto ?></b> produkt(ów) w sprzedaży <b>bez zdjęcia</b> — klient widzi tylko kolorowy kafelek.
      <?= console_lien($me, 'produkty.php', 'Dodaj zdjęcia →', 'code') ?></p>
    <?php endif; ?>
    <?php if (!$lowStock): ?>
    <p class="muted small">Żaden produkt nie schodzi poniżej 3 sztuk.</p>
    <?php else: ?>
    <p class="muted small">Produkty na wyczerpaniu. Zamówienie ponad stan nadal przechodzi —
      klient dostaje wiadomość „skontaktujemy się mailowo”.</p>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Produkt</th><th class="num">Stan</th></tr></thead>
      <tbody>
      <?php foreach ($lowStock as $p): ?>
      <tr>
        <td data-l="Produkt"><?= h((string) $p['nom']) ?></td>
        <td data-l="Stan" class="num"><span class="tag <?= (int) $p['stock'] <= 0 ? 'bad' : 'wait' ?>"><?= (int) $p['stock'] ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php if ($bledyWidac && !$bledy['zapis']): ?>
<p class="warnbox" id="bledy">Dziennik błędów: serwer <b>nie może zapisywać</b> do katalogu api/data —
  błędy nie są notowane, więc pusty dziennik nic nie znaczy. Trzeba nadać serwerowi WWW prawo zapisu do api/data.</p>
<?php elseif ($bledyWidac && $bledy['razem'] === 0): ?>
<p class="bledy-ok" id="bledy">Dziennik błędów: pusty<?= ($_GET['bledy'] ?? '') === 'przejrzane' ? ' — oznaczony jako przejrzany' : '' ?>.
  Każde odrzucone zapytanie albo błąd strony pojawi się tutaj.</p>
<?php endif; ?>
<?php console_foot();
