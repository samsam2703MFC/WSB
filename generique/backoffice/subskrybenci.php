<?php
// ============================================================================
//  subskrybenci.php — qui a dit oui, à quoi, quand ; et qui a dit non.
//
//  C'est le REGISTRE DES CONSENTEMENTS : pour chaque adresse, la date, la
//  source (pied de page, caisse), le texte exact de la case cochée et
//  l'adresse IP. C'est lui qu'on montre si quelqu'un demande « d'où tenez-vous
//  mon adresse ? » — d'où l'export CSV.
//
//  « Usuń dane » (droit à l'effacement) supprime la fiche, mais GARDE le refus
//  sur la fiche client : sinon l'adresse reviendrait au prochain achat, et on
//  réécrirait à quelqu'un qui a demandé qu'on l'oublie.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/marketing.php';
wsm_mkt_ensure($pdo);

$flash = ''; $kind = 'ok';
$acteur = (string) ($me['nom'] ?? '');

$decide = wsm_mkt_decide($me);
// Une cellule qui commence par = + - @ serait une FORMULE dans Excel : le texte
// de la case vient de la boutique, on ne laisse pas une saisie devenir un calcul.
$cellule = fn($v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . (string) $v : (string) $v;

if (isset($_GET['csv']) && $decide) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="zgody-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['email', 'telefon', 'jezyk', 'zrodlo', 'email_status', 'email_zgoda', 'email_potwierdzenie', 'email_wypis',
                 'email_tekst_zgody', 'sms_status', 'sms_zgoda', 'sms_wypis', 'sms_tekst_zgody', 'ip', 'utworzono'], ';');
    foreach ($pdo->query("SELECT * FROM wsm_subscribers ORDER BY id") as $r) {
        fputcsv($o, array_map($cellule, [$r['email'], $r['phone'], $r['lang'], $r['source'], $r['email_status'], $r['email_zgoda_at'], $r['email_potw_at'],
                     $r['email_wypis_at'], $r['email_zgoda_tekst'], $r['sms_status'], $r['sms_zgoda_at'], $r['sms_wypis_at'],
                     $r['sms_zgoda_tekst'], $r['ip'], $r['created_at']]), ';');
    }
    if (function_exists('wsm_audit')) wsm_audit($pdo, $acteur, 'Eksport zgód marketingowych', 'wsm_subscribers', 'Sieć');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    $sid = (int) ($_POST['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM wsm_subscribers WHERE id = ?");
    $st->execute([$sid]);
    $r = $st->fetch();
    if (!$isAdmin) { $flash = 'Twoja rola może tylko przeglądać listę.'; $kind = 'err'; }
    elseif (!$r) { $flash = 'Nie znaleziono.'; $kind = 'err'; }
    elseif (isset($_POST['wypisz_email'])) { wsm_mkt_unsub_email($pdo, (string) $r['email'], 'konsola'); $flash = 'Wypisano ' . $r['email'] . ' z e-maili.'; }
    elseif (isset($_POST['wypisz_sms'])) { wsm_mkt_unsub_sms($pdo, (string) $r['phone'], 'konsola'); $flash = 'Wypisano ' . $r['phone'] . ' z SMS-ów.'; }
    elseif (isset($_POST['usun']) && !$decide) { $flash = 'Usunięcie danych zatwierdza administrator.'; $kind = 'err'; }
    elseif (isset($_POST['usun'])) {
        wsm_camp_stop($pdo, (string) $r['email'], $acteur);            // le refus survit à l'effacement
        $pdo->prepare("DELETE FROM wsm_subscribers WHERE id = ?")->execute([$sid]);
        if (function_exists('wsm_audit')) wsm_audit($pdo, $acteur, 'Usunięcie danych subskrybenta', '#' . $sid, 'Sieć');
        $flash = 'Dane usunięte. Adres zostaje na liście „nie wysyłać”, żeby nie wrócił przy następnym zakupie.';
    }
    header('Location: subskrybenci.php?ok=' . rawurlencode($flash) . '&k=' . $kind, true, 303); exit;
}
if (isset($_GET['ok'])) { $flash = (string) $_GET['ok']; $kind = ($_GET['k'] ?? '') === 'err' ? 'err' : 'ok'; }

$q = trim((string) ($_GET['q'] ?? ''));
$f = (string) ($_GET['f'] ?? '');
$where = []; $args = [];
if ($q !== '') { $where[] = '(email LIKE ? OR phone LIKE ?)'; $args[] = '%' . $q . '%'; $args[] = '%' . $q . '%'; }
$filtres = ['email' => "email_status = 'aktywny'", 'oczekuje' => "email_status = 'oczekuje'", 'sms' => "sms_status = 'aktywny'",
            'wypisani' => "(email_status = 'wypisany' OR sms_status = 'wypisany')"];
if (isset($filtres[$f])) $where[] = $filtres[$f];
$st = $pdo->prepare("SELECT * FROM wsm_subscribers" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY id DESC LIMIT 300");
$st->execute($args);
$rows = $st->fetchAll();
$kpi = wsm_mkt_kpis($pdo);
$etiq = ['aktywny' => ['ok', 'aktywny'], 'oczekuje' => ['wait', 'czeka na potwierdzenie'], 'wypisany' => ['bad', 'wypisany'], '' => ['', '—']];

console_head('Subskrybenci', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 18px; max-width: 86ch; line-height: 1.6; }
  .filtres { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; align-items: center; }
  .filtres a { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--border-subtle); text-decoration: none; font-size: 13px; }
  .filtres a.on { background: var(--text-strong); color: #fff; }
  td small { display: block; color: var(--text-muted); font-size: 12px; }
  td form { display: inline; }
  td button { font-size: 12.5px; padding: 4px 10px; margin: 2px 0; }
CSS);
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Marketing' => null, 'Subskrybenci' => null]);
?>
<div class="kpis">
  <div class="kpi"><b><?= (int) $kpi['email_aktywni'] ?></b><span>E-mail: potwierdzeni</span></div>
  <div class="kpi"><b><?= (int) $kpi['email_oczekuja'] ?></b><span>E-mail: czekają</span></div>
  <div class="kpi"><b><?= (int) $kpi['sms_aktywni'] ?></b><span>SMS: zgody</span></div>
  <div class="kpi"><b><?= (int) $kpi['email_wypisani'] + (int) $kpi['sms_wypisani'] ?></b><span>Wypisania</span></div>
</div>
<p class="hint">Rejestr zgód: data, źródło (stopka strony, kasa), dokładny tekst zaznaczonej zgody i adres IP.
  <?= wsm_mkt_klienci_bez_zgody()
      ? 'Włączone w Integracjach: newsletter dostają także kupujący spoza tej listy, chyba że się wypisali.'
      : 'Newsletter dostają tylko potwierdzeni z tej listy, SMS-y tylko osoby ze zgodą SMS.' ?></p>
<form method="get" class="filtres">
  <?php foreach (['' => 'Wszyscy', 'email' => 'E-mail', 'oczekuje' => 'Czekają', 'sms' => 'SMS', 'wypisani' => 'Wypisani'] as $k => $lbl): ?>
  <a class="<?= $f === $k ? 'on' : '' ?>" href="subskrybenci.php?f=<?= h($k) ?>"><?= h($lbl) ?></a>
  <?php endforeach; ?>
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="e-mail lub telefon"><input type="hidden" name="f" value="<?= h($f) ?>">
  <button type="submit">Szukaj</button>
  <?php if ($decide): ?><a href="subskrybenci.php?csv=1">Eksport zgód (CSV)</a><?php endif; ?>
</form>
<div class="panel"><div class="tablewrap"><table class="rwd">
  <thead><tr><th>Adres</th><th>Źródło</th><th>Newsletter</th><th>SMS</th><th>Zgoda</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">Nikogo — zapisy przychodzą ze stopki sklepu i z kasy.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): [$ce, $le] = $etiq[(string) $r['email_status']] ?? ['', (string) $r['email_status']];
        [$cs, $ls] = $etiq[(string) $r['sms_status']] ?? ['', (string) $r['sms_status']]; ?>
  <tr>
    <td data-l="Adres"><?= h((string) $r['email']) ?><small><?= h((string) $r['phone']) ?> · <?= h((string) $r['lang']) ?></small></td>
    <td data-l="Źródło"><?= h((string) $r['source']) ?><small><?= h(substr((string) $r['created_at'], 0, 16)) ?></small></td>
    <td data-l="Newsletter"><span class="tag <?= h($ce) ?>"><?= h($le) ?></span>
      <small><?= $r['email_potw_at'] ? 'potw. ' . h(substr((string) $r['email_potw_at'], 0, 16)) : '' ?><?= $r['email_wypis_at'] ? ' wypis ' . h(substr((string) $r['email_wypis_at'], 0, 16)) : '' ?></small></td>
    <td data-l="SMS"><span class="tag <?= h($cs) ?>"><?= h($ls) ?></span>
      <small><?= $r['sms_zgoda_at'] ? 'zgoda ' . h(substr((string) $r['sms_zgoda_at'], 0, 16)) : '' ?></small></td>
    <td data-l="Zgoda"><small title="<?= h((string) $r['email_zgoda_tekst'] . "\n" . (string) $r['sms_zgoda_tekst']) ?>"><?= h(mb_strimwidth((string) ($r['email_zgoda_tekst'] ?: $r['sms_zgoda_tekst']), 0, 70, '…')) ?></small><small>IP <?= h((string) $r['ip']) ?></small></td>
    <td><?php if ($isAdmin): ?>
      <form method="post"><?= console_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <?php if ($r['email_status'] !== 'wypisany'): ?><button type="submit" name="wypisz_email" value="1">Wypisz e-mail</button><?php endif; ?>
        <?php if ($r['sms_status'] === 'aktywny'): ?><button type="submit" name="wypisz_sms" value="1">Wypisz SMS</button><?php endif; ?>
        <?php if ($decide): ?><button type="submit" name="usun" value="1">Usuń dane</button><?php endif; ?></form>
    <?php endif; ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php console_foot();
