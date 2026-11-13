<?php
// ============================================================================
//  faktury.php — les factures : émission, consultation, correction, envoi.
//
//  Trois choses qu'on ne peut pas faire ici, volontairement :
//   • modifier une facture émise — on émet une correction, qui référence
//     l'originale ; c'est la seule voie légale ;
//   • sauter un numéro — le rang est attribué en base, dans la transaction ;
//   • émettre sans les données du vendeur — l'écran refuse et dit lesquelles
//     manquent, plutôt que de produire un document inutilisable.
//
//  Le document lui-même s'imprime depuis le navigateur (Ctrl+P → PDF) : la
//  feuille d'impression est dans console.css. Pas de bibliothèque PDF à
//  maintenir pour un document d'une page.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/invoice.php';
require_once $API . '/mail.php';

$flash = ''; $kind = 'ok';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Sans ce jeton, une page tierce fait poster ce formulaire par le
    // navigateur de quelqu'un qui a sa session ouverte, et la console
    // execute la demande comme si elle venait de lui.
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może wystawiać dokumenty.'; $kind = 'err';
    } elseif (isset($_POST['wystaw'])) {
        $order = wsm_order_by_id($pdo, (int) $_POST['wystaw']);
        if (!$order) { $flash = 'Nie znaleziono zamówienia.'; $kind = 'err'; }
        else {
            [$inv, $err] = wsm_invoice_issue($pdo, $order, (string) ($me['nom'] ?? ''));
            if ($err) { $flash = $err; $kind = 'err'; }
            else {
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Wystawienie', 'wsm_invoices ' . $inv['number'], 'Sieć');
                $flash = 'Wystawiono ' . $inv['number'] . '.';
            }
        }
    } elseif (isset($_POST['koryguj'])) {
        $src = wsm_invoice_by_id($pdo, (int) $_POST['koryguj']);
        if (!$src) { $flash = 'Nie znaleziono dokumentu.'; $kind = 'err'; }
        else {
            [$inv, $err] = wsm_invoice_correct($pdo, $src, $_POST, (string) ($me['nom'] ?? ''));
            if ($err) { $flash = $err; $kind = 'err'; }
            else {
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Korekta', 'wsm_invoices ' . $inv['number'], 'Sieć');
                $flash = 'Wystawiono korektę ' . $inv['number'] . '.';
            }
        }
    } elseif (isset($_POST['proforma'])) {
        // Proforma d'une facture existante : c'est le cas légitime de « refaire »
        // un document — la proforma n'étant pas fiscale, on peut la réémettre.
        $src = wsm_invoice_by_id($pdo, (int) $_POST['proforma']);
        if (!$src) { $flash = 'Nie znaleziono dokumentu.'; $kind = 'err'; }
        else {
            [$pf, $err] = wsm_invoice_proforma($pdo, $src, (string) ($me['nom'] ?? ''), (string) ($_POST['note'] ?? ''));
            if ($err) { $flash = $err; $kind = 'err'; }
            else {
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Proforma', 'wsm_invoices ' . $pf['number'], 'Sieć');
                $flash = 'Wystawiono proformę ' . $pf['number'] . '.';
            }
        }
    } elseif (isset($_POST['proforma_zam'])) {
        $order = wsm_order_by_id($pdo, (int) $_POST['proforma_zam']);
        if (!$order) { $flash = 'Nie znaleziono zamówienia.'; $kind = 'err'; }
        else {
            [$pf, $err] = wsm_invoice_proforma($pdo, $order, (string) ($me['nom'] ?? ''), (string) ($_POST['note'] ?? ''));
            if ($err) { $flash = $err; $kind = 'err'; }
            else {
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Proforma', 'wsm_invoices ' . $pf['number'], 'Sieć');
                $flash = 'Wystawiono proformę ' . $pf['number'] . '.';
            }
        }
    } elseif (isset($_POST['monit'])) {
        // Une seule mécanique pour les deux relances : le modèle décide du ton,
        // la clé d'événement décide de la répétition (une par jour, pas plus).
        $ev  = ($_POST['monit'] === 'przypomnienie') ? 'przypomnienie' : 'zadanie_zaplaty';
        $inv = wsm_invoice_by_id($pdo, (int) ($_POST['dokument'] ?? 0));
        if (!$inv) { $flash = 'Nie znaleziono dokumentu.'; $kind = 'err'; }
        else {
            $id = wsm_mail_for_invoice($pdo, $inv, $ev, (string) ($me['nom'] ?? ''));
            if ($id === 0) {
                $flash = $ev === 'przypomnienie'
                    ? 'Przypomnienie do tego dokumentu już dziś wysłano.'
                    : 'Nie wysłano — brak szablonu lub adresu odbiorcy.';
                $kind = 'err';
            } else {
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Monit', 'wsm_invoices ' . $inv['number'] . ' / ' . $ev, 'Sieć');
                $flash = ($ev === 'przypomnienie' ? 'Wysłano przypomnienie do ' : 'Wysłano żądanie zapłaty do ')
                       . $inv['buyer_email'] . '.';
            }
        }
    } elseif (isset($_POST['wyslij'])) {
        $inv = wsm_invoice_by_id($pdo, (int) $_POST['wyslij']);
        if (!$inv) { $flash = 'Nie znaleziono dokumentu.'; $kind = 'err'; }
        elseif (!filter_var((string) $inv['buyer_email'], FILTER_VALIDATE_EMAIL)) {
            $flash = 'Ten dokument nie ma adresu odbiorcy.'; $kind = 'err';
        } else {
            $link = wsm_mail_shop_url();
            $id = wsm_mail_queue($pdo, [
                'order_id' => $inv['order_id'] ?: null,
                'email'    => (string) $inv['buyer_email'],
                'direction' => 'wyjscie',
                'subject'  => ($inv['kind'] === 'paragon' ? 'E-paragon ' : 'Faktura ') . $inv['number'],
                'body'     => "Dzień dobry,\n\nw załączeniu dane dokumentu " . $inv['number'] . " na kwotę "
                            . wsm_money((int) $inv['total_gross']) . " zł.\n\nTermin płatności: " . $inv['due_at']
                            . "\nRachunek: " . $inv['iban'] . "\n\n" . wsm_brand_name(),
                'template_code' => 'faktura',
                'event_key' => 'faktura:' . (int) $inv['id'],
                'actor'    => (string) ($me['nom'] ?? ''),
            ]);
            if ($id === 0) { $flash = 'Ten dokument już wysłano.'; $kind = 'err'; }
            else {
                [$ok, $err] = wsm_mail_send($pdo, $id);
                $pdo->prepare("UPDATE wsm_invoices SET sent_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int) $inv['id']]);
                $flash = $ok ? 'Wysłano na ' . $inv['buyer_email'] : ('W kolejce — ' . $err);
                $kind  = $ok ? 'ok' : 'err';
            }
        }
    }
}

// Faute de tâche planifiée sur le serveur, les relances partent à l'ouverture
// de cet écran. La clé d'événement porte la date : une facture ne peut être
// relancée qu'une fois par jour, quel que soit le nombre de rafraîchissements.
$auto = wsm_invoice_reminders_run($pdo);
// Même raison pour la caisse : quelques e-paragons relus à l'ouverture, au
// cas où un webhook de Fakturownia se serait perdu (fiskal.php).
if (wsm_invoice_kasa()) wsm_fiskal_run($pdo);

$detail  = isset($_GET['id']) ? wsm_invoice_by_id($pdo, (int) $_GET['id']) : null;
$kpis    = wsm_invoice_kpis($pdo);
$pending = wsm_invoice_pending_orders($pdo, 25);
$overdue = wsm_invoices_overdue($pdo, 0);
$rows    = wsm_invoices_list($pdo, ['q' => (string) ($_GET['q'] ?? '')]);
$missing = wsm_invoice_blockers();

$kindLabel = ['faktura' => 'Faktura', 'korekta' => 'Korekta', 'paragon' => 'E-paragon', 'proforma' => 'Proforma'];

// Vue « document » : la page imprimable, sans le reste de la console.
if ($detail && isset($_GET['druk'])) {
    include __DIR__ . '/faktura_druk.php';
    exit;
}

console_head('Faktury', $me, '', $kpis['to_issue'] ? $kpis['to_issue'] . ' do wystawienia' : '');
console_flash($flash !== '' ? $flash : ($auto ? 'Wysłano automatycznie przypomnień: ' . $auto . '.' : ''), $kind);
console_crumbs($detail
    ? ['Pulpit' => 'pulpit.php', 'Faktury' => 'faktury.php', $detail['number'] => null]
    : ['Pulpit' => 'pulpit.php', 'Faktury' => null]);
?>

<?php if ($missing): ?>
<p class="warnbox">
  Nie da się wystawić faktury — brakuje: <b><?= h(implode(', ', $missing)) ?></b>.
  Uzupełnij w <a href="ustawienia.php">Integracjach</a>, sekcja „faktura”.
  E-paragony dla klientów bez NIP działają mimo to.
</p>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><b><?= (int) $kpis['count'] ?></b><span>Faktury</span></div>
  <div class="kpi"><b><?= (int) $kpis['paragons'] ?></b><span>E-paragony</span></div>
  <div class="kpi"><b><?= (int) $kpis['korekty'] ?></b><span>Korekty</span></div>
  <div class="kpi"><b><?= h(pln((int) $kpis['gross'])) ?></b><span>Wartość brutto</span></div>
  <div class="kpi<?= $kpis['to_issue'] ? ' hot' : '' ?>"><b><?= (int) $kpis['to_issue'] ?></b><span>Do wystawienia</span></div>
</div>

<?php if ($detail): $i = $detail; ?>
<div class="panel">
  <h2><?= h($kindLabel[$i['kind']] ?? $i['kind']) ?> <?= h($i['number']) ?>
    <?php if ($i['reverse_charge']): ?><span class="tag rc">odwrotne obciążenie</span><?php endif; ?>
    <?php if ($i['ksef_number'] !== ''): ?><span class="tag ok">KSeF <?= h($i['ksef_number']) ?></span>
    <?php elseif ($i['kind'] !== 'paragon'): ?><span class="tag wait">KSeF: nie wysłano</span><?php endif; ?>
    <?php [$kc, $kt] = function_exists('wsm_fiskal_tag') ? wsm_fiskal_tag($i) : ['', ''];
          if ($kt !== ''): ?><span class="tag <?= h($kc) ?>"><?= h($kt) ?></span><?php endif; ?>
    <?php if (($i['fiskal_url'] ?? '') !== ''): ?><a class="tag ok" href="<?= h($i['fiskal_url']) ?>" target="_blank" rel="noopener">otwórz e-paragon</a><?php endif; ?>
  </h2>
  <?php if (($i['fiskal_note'] ?? '') !== ''): ?><p class="warnbox">Kasa fiskalna: <?= h($i['fiskal_note']) ?> — ponowisz z ekranu Zamówienia.</p><?php endif; ?>
  <div class="cols">
    <dl class="kv">
      <dt>Nabywca</dt><dd><?= h($i['buyer_name']) ?><?= $i['buyer_nip'] !== '' ? '<br>NIP ' . h($i['buyer_nip']) : '' ?>
        <?= $i['buyer_vat_eu'] !== '' ? '<br>VAT UE ' . h($i['buyer_vat_eu']) : '' ?><br><?= h($i['buyer_address']) ?></dd>
      <dt>Wystawiono</dt><dd><?= h($i['issued_at']) ?> · <?= h($i['place']) ?></dd>
      <dt>Data sprzedaży</dt><dd><?= h($i['sold_at']) ?></dd>
      <dt>Termin</dt><dd><?= h($i['due_at']) ?><?= $i['paid'] ? ' · <span class="tag ok">opłacona</span>' : '' ?></dd>
      <?php if ($i['note'] !== ''): ?><dt>Uwaga</dt><dd><?= h($i['note']) ?></dd><?php endif; ?>
      <?php if ($i['order_id']): ?><dt>Zamówienie</dt><dd><a class="code" href="zamowienia.php?id=<?= (int) $i['order_id'] ?>">#<?= (int) $i['order_id'] ?></a></dd><?php endif; ?>
    </dl>
    <div class="tablewrap">
      <table>
        <tr><th>Pozycja</th><th class="num">Il.</th><th class="num">VAT</th><th class="num">Brutto</th></tr>
        <?php foreach ($i['items'] as $l): ?>
        <tr><td><?= h((string) $l['name']) ?></td><td class="num"><?= (int) $l['qty'] ?></td>
            <td class="num"><?= h(wsm_vat_percent((float) $l['vat_rate'])) ?> %</td>
            <td class="num"><?= h(pln((int) $l['line_gross'])) ?></td></tr>
        <?php endforeach; ?>
        <tr><td><b>Razem</b> <small class="muted">(netto <?= h(pln((int) $i['total_net'])) ?> + VAT <?= h(pln((int) $i['total_vat'])) ?>)</small></td>
            <td class="num"></td><td class="num"></td><td class="num"><b><?= h(pln((int) $i['total_gross'])) ?></b></td></tr>
      </table>
    </div>
  </div>

  <div class="actions">
    <a class="code" href="faktury.php?id=<?= (int) $i['id'] ?>&amp;druk=1" target="_blank" rel="noopener">Otwórz do druku / PDF ↗</a>
    <?php if ($isAdmin): ?>
    <form method="post">
      <?= console_csrf_field() ?><button type="submit" name="wyslij" value="<?= (int) $i['id'] ?>">Wyślij mailem</button></form>
    <?php if ($i['kind'] !== 'proforma'): ?>
    <form method="post">
      <?= console_csrf_field() ?><button type="submit" name="proforma" value="<?= (int) $i['id'] ?>">Wystaw proformę</button></form>
    <?php endif; ?>
    <?php if (!$i['paid'] && $i['kind'] !== 'paragon'): ?>
    <form method="post">
      <?= console_csrf_field() ?>
      <input type="hidden" name="dokument" value="<?= (int) $i['id'] ?>">
      <button type="submit" name="monit" value="zadanie_zaplaty">Prośba o płatność</button>
    </form>
    <form method="post">
      <?= console_csrf_field() ?>
      <input type="hidden" name="dokument" value="<?= (int) $i['id'] ?>">
      <button type="submit" name="monit" value="przypomnienie">Wyślij przypomnienie</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if ($i['kind'] === 'proforma'): ?>
  <p class="muted small">Proforma nie jest fakturą VAT: nie odlicza się z niej podatku i nie trafia do
    ewidencji. Służy do zapłaty z góry — po wpłacie wystawia się właściwy dokument.</p>
  <?php endif; ?>

  <?php if ($isAdmin && $i['kind'] === 'faktura'): ?>
  <h3>Korekta</h3>
  <p class="muted small">Faktury nie zmienia się — wystawia się korektę, która wskazuje oryginał.
    Podaj kwotę brutto <b>po korekcie</b> i przyczynę.</p>
  <form method="post" class="grid2">
      <?= console_csrf_field() ?>
    <input type="hidden" name="koryguj" value="<?= (int) $i['id'] ?>">
    <label class="field"><span>Kwota brutto po korekcie (zł)</span>
      <input type="text" name="total_gross" inputmode="decimal" value="<?= h(number_format($i['total_gross'] / 100, 2, ',', '')) ?>"></label>
    <label class="field"><span>Przyczyna korekty</span>
      <input type="text" name="reason" placeholder="np. zwrot części towaru" required></label>
    <div class="actions" style="grid-column:1/-1"><button class="primary" type="submit">Wystaw korektę</button></div>
  </form>
  <?php endif; ?>
  <a class="code back" href="faktury.php">← Wszystkie dokumenty</a>
</div>
<?php endif; ?>

<?php if ($pending): ?>
<div class="panel">
  <h2>Do wystawienia</h2>
  <p class="muted small">Zamówienia opłacone, które nie mają jeszcze dokumentu.
    Klient z NIP-em dostaje fakturę, klient bez NIP-u — e-paragon.</p>
  <div class="tablewrap">
  <table class="rwd">
    <thead><tr><th>Zamówienie</th><th>Nabywca</th><th>NIP</th><th class="num">Brutto</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pending as $o): ?>
    <tr>
      <td data-l="Zamówienie"><a class="code" href="zamowienia.php?id=<?= (int) $o['id'] ?>"><?= h($o['code']) ?></a></td>
      <td data-l="Nabywca"><?= h(trim(($o['company'] ?: $o['first_name'] . ' ' . $o['last_name']))) ?></td>
      <td data-l="NIP"><?= $o['nip'] !== '' ? h($o['nip']) : '<span class="muted">— e-paragon</span>' ?></td>
      <td data-l="Brutto" class="num"><?= h(pln((int) $o['total_gross'])) ?></td>
      <td data-l="">
        <?php if ($isAdmin): ?>
        <div class="actions">
          <form method="post">
      <?= console_csrf_field() ?><button class="primary" type="submit" name="wystaw" value="<?= (int) $o['id'] ?>">Wystaw</button></form>
          <form method="post">
      <?= console_csrf_field() ?><button type="submit" name="proforma_zam" value="<?= (int) $o['id'] ?>">Proforma</button></form>
        </div>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if ($overdue): ?>
<div class="panel">
  <h2>Po terminie — <?= count($overdue) ?></h2>
  <p class="muted small">Faktury niezapłacone, których termin minął. Przypomnienie wychodzi
    automatycznie po <b><?= (int) wsm_invoice_cfg()['reminder_days'] ?></b> dniach zwłoki
    (ustawienia → faktura); tutaj można je wysłać ręcznie, raz dziennie na dokument.</p>
  <div class="tablewrap">
  <table class="rwd">
    <thead><tr><th>Numer</th><th>Nabywca</th><th>Termin</th><th class="num">Zwłoka</th><th class="num">Brutto</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($overdue as $i2):
        $late = (int) floor((time() - (int) strtotime((string) $i2['due_at'])) / 86400); ?>
    <tr>
      <td data-l="Numer"><a class="code" href="faktury.php?id=<?= (int) $i2['id'] ?>"><?= h($i2['number']) ?></a></td>
      <td data-l="Nabywca"><?= h($i2['buyer_name']) ?></td>
      <td data-l="Termin" class="num"><?= h($i2['due_at']) ?></td>
      <td data-l="Zwłoka" class="num"><?= $late > 0 ? $late . ' dni' : 'dziś' ?></td>
      <td data-l="Brutto" class="num"><?= h(pln((int) $i2['total_gross'])) ?></td>
      <td data-l="">
        <?php if ($isAdmin): ?>
        <form method="post">
      <?= console_csrf_field() ?>
          <input type="hidden" name="dokument" value="<?= (int) $i2['id'] ?>">
          <button type="submit" name="monit" value="przypomnienie">Przypomnij</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <h2>Dokumenty</h2>
  <form method="get" class="actions" style="margin-bottom:12px">
    <input type="search" name="q" value="<?= h((string) ($_GET['q'] ?? '')) ?>" placeholder="numer, nabywca lub NIP">
    <button type="submit">Szukaj</button>
  </form>
  <div class="tablewrap">
  <table class="rwd">
    <thead><tr><th>Numer</th><th>Rodzaj</th><th>Data</th><th>Nabywca</th><th class="num">Brutto</th><th>KSeF / kasa</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td class="muted">Brak dokumentów.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td data-l="Numer"><a class="code" href="faktury.php?id=<?= (int) $r['id'] ?>"><?= h($r['number']) ?></a></td>
      <td data-l="Rodzaj"><span class="tag"><?= h($kindLabel[$r['kind']] ?? $r['kind']) ?></span></td>
      <td data-l="Data" class="num"><?= h($r['issued_at']) ?></td>
      <td data-l="Nabywca"><?= h($r['buyer_name']) ?><?= $r['buyer_nip'] !== '' ? '<br><small class="muted">NIP ' . h($r['buyer_nip']) . '</small>' : '' ?></td>
      <td data-l="Brutto" class="num"><?= h(pln((int) $r['total_gross'])) ?></td>
      <?php [$kc, $kt] = function_exists('wsm_fiskal_tag') ? wsm_fiskal_tag($r) : ['', '']; ?>
      <td data-l="KSeF / kasa"><?= $r['ksef_number'] !== '' ? '<span class="tag ok">' . h($r['ksef_number']) . '</span>'
                          : ($r['kind'] === 'paragon'
                              ? ($kt !== '' ? '<span class="tag ' . h($kc) . '">' . h($kt) . '</span>' : '<span class="muted">n/d</span>')
                              : '<span class="tag wait">nie wysłano</span>') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php console_foot();
