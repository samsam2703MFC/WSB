<?php
// ============================================================================
//  kontrahenci.php — écran « Kontrahenci i VAT UE » de la console marque.
//
//  Ce que cet écran sert à voir d'un coup d'œil : quels numéros de TVA sont
//  vérifiés, lesquels n'ont pas pu l'être, et lesquels doivent l'être à nouveau.
//
//  Le bouton « Sprawdź » relance une consultation VIES. Le numéro de
//  consultation renvoyé par la Commission est affiché : c'est lui qu'on
//  produit en cas de contrôle, pas une capture d'écran.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/vies.php';

$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$flash = ''; $kind = 'ok'; $checked = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może uruchamiać weryfikację.'; $kind = 'err';
    } else {
        $vat = (string) ($_POST['vat_eu'] ?? '');
        $cid = (int) ($_POST['client_id'] ?? 0);
        $checked = wsm_vies_check($pdo, $vat, true);          // toujours forcé : c'est un bouton

        if ($cid > 0) {
            $cols = wsm_vies_columns($checked);
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($cols)));
            $pdo->prepare("UPDATE wsm_clients SET $set WHERE id = ?")
                ->execute([...array_values($cols), $cid]);
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Weryfikacja', 'VIES ' . $checked['vat_eu'], 'Sieć');
        }
        $labels = ['valid' => 'Numer prawidłowy', 'invalid' => 'Numer nieznany w VIES',
                   'unavailable' => 'VIES nie odpowiedział — spróbuj później',
                   'skipped' => 'Weryfikacja pominięta'];
        $flash = ($labels[$checked['status']] ?? $checked['status'])
               . ($checked['reason'] !== '' ? ' · ' . $checked['reason'] : '');
        $kind = $checked['status'] === 'valid' ? 'ok' : ($checked['status'] === 'invalid' ? 'err' : 'warn');
    }
}

$rows = $pdo->query(
    "SELECT id, code, raison, client_type, nip, vat_eu, vat_status, vat_checked_at, vat_name, vat_consultation
       FROM wsm_clients ORDER BY (vat_eu = '') , raison"
)->fetchAll();

$withVat  = array_values(array_filter($rows, fn($r) => (string) $r['vat_eu'] !== ''));
$nValid   = count(array_filter($withVat, fn($r) => $r['vat_status'] === 'valid'));
$nUnknown = count(array_filter($withVat, fn($r) => in_array((string) $r['vat_status'], ['unavailable', ''], true)));
$cfg = wsm_vies_cfg();

console_head('Kontrahenci i VAT UE', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 20px; max-width: 82ch; line-height: 1.55; }
  .flash.warn { background: color-mix(in srgb, var(--warning) 18%, transparent); color: var(--accent-600); }
  .adhoc { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
  .adhoc label { display: flex; flex-direction: column; gap: 5px; font-size: 13px; font-weight: 600; color: var(--text-strong); }
  .adhoc input { font-family: var(--font-mono); min-width: 220px; }
  th, td { vertical-align: top; }
  .mono { font-family: var(--font-mono); font-size: 12.5px; }
  .tag.err  { background: color-mix(in srgb, var(--danger) 15%, transparent); color: var(--danger); }
  .tag.warn { background: color-mix(in srgb, var(--warning) 22%, transparent); color: var(--accent-600); }
  .tag.rc   { background: color-mix(in srgb, var(--sale) 16%, transparent); color: var(--sale-strong); }
  small.muted { color: var(--text-muted); }
CSS, '');
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Kontrahenci' => null]);
?>

  <p class="hint">
    Numery VAT UE są sprawdzane w <b>VIES</b> (Komisja Europejska) przy zapisie kontrahenta i w kasie sklepu.
    Numer, którego VIES <b>nie zna</b>, jest odrzucany. Gdy VIES <b>nie odpowiada</b> — a zdarza się to
    regularnie, bo pyta administracje krajowe na żywo — zapis przechodzi i wpis trafia tutaj do ponownego
    sprawdzenia. Blokowanie sprzedaży z powodu awarii po stronie Komisji byłoby gorsze od problemu,
    który chcemy uniknąć.
    <?php if (!wsm_vies_can_prove()): ?>
      <br><b>Uwaga:</b> nie podano naszego numeru VAT (<code>WSM_VIES_REQUESTER</code>), więc VIES nie wydaje
      numeru konsultacji — nie ma dowodu na potrzeby kontroli.
    <?php endif; ?>
    <?php if (!$cfg['enabled']): ?>
      <br><b>Weryfikacja VIES jest wyłączona</b> — numery są sprawdzane tylko pod kątem formatu.
    <?php endif; ?>
  </p>

  <div class="kpis">
    <div class="kpi"><b><?= count($withVat) ?></b><span>Z numerem VAT UE</span></div>
    <div class="kpi"><b><?= $nValid ?></b><span>Potwierdzone</span></div>
    <div class="kpi"><b><?= $nUnknown ?></b><span>Do sprawdzenia</span></div>
    <div class="kpi"><b><?= count($rows) ?></b><span>Kontrahenci</span></div>
  </div>

  <?php if ($isAdmin): ?>
  <div class="panel">
    <h2>Sprawdź dowolny numer</h2>
    <form class="adhoc" method="post">
      <input type="hidden" name="_t" value="<?= h($csrf) ?>">
      <label>Numer VAT UE
        <input type="text" name="vat_eu" placeholder="PL5252248481"
               value="<?= h((string) ($_POST['vat_eu'] ?? '')) ?>" required>
      </label>
      <button class="primary" type="submit">Sprawdź w VIES</button>
    </form>
    <?php if ($checked && ($checked['status'] ?? '') === 'valid'): ?>
    <p style="margin:14px 0 0;font-size:13.5px;line-height:1.6">
      <b><?= h($checked['name'] ?: '—') ?></b><br>
      <span class="muted"><?= h($checked['address'] ?? '') ?></span><br>
      <span class="mono muted">Nr konsultacji: <?= h($checked['consultation'] ?: '— (brak naszego numeru VAT)') ?></span>
      <?php if (wsm_vies_reverse_charge($checked)): ?>
        <br><span class="tag rc">Kwalifikuje się do odwrotnego obciążenia</span>
        <small class="muted"> — sklep nadal nalicza polski VAT: wysyłamy wyłącznie do Polski.</small>
      <?php endif; ?>
    </p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="tablewrap">
  <table class="rwd">
    <thead><tr><th>Kontrahent</th><th>NIP</th><th>VAT UE</th><th>Status</th><th>Sprawdzono</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $vat = (string) $r['vat_eu'];
      $st  = (string) $r['vat_status'];
      $cls = ['valid' => 'ok', 'invalid' => 'err', 'unavailable' => 'warn'][$st] ?? '';
      $lbl = ['valid' => 'Potwierdzony', 'invalid' => 'Nieznany', 'unavailable' => 'Brak odpowiedzi',
              'skipped' => 'Pominięty'][$st] ?? '—'; ?>
    <tr>
      <td data-l="Kontrahent"><?= h((string) $r['raison']) ?><br><small class="muted mono"><?= h((string) $r['code']) ?> · <?= h((string) $r['client_type']) ?></small></td>
      <td data-l="NIP" class="mono"><?= h((string) $r['nip']) ?: '—' ?></td>
      <td data-l="VAT UE" class="mono"><?= $vat !== '' ? h($vat) : '<span class="muted">—</span>' ?>
        <?php if ($vat !== '' && wsm_vies_reverse_charge(['status' => $st, 'country' => substr($vat, 0, 2)])): ?>
          <br><span class="tag rc">odwrotne obciążenie</span>
        <?php endif; ?>
      </td>
      <td data-l="Status"><?php if ($vat !== ''): ?><span class="tag <?= h($cls) ?>"><?= h($lbl) ?></span>
            <?php if (($r['vat_name'] ?? '') !== ''): ?><br><small class="muted"><?= h((string) $r['vat_name']) ?></small><?php endif; ?>
          <?php else: ?><span class="muted">—</span><?php endif; ?></td>
      <td data-l="Sprawdzono" class="mono"><?= h(substr((string) ($r['vat_checked_at'] ?? ''), 0, 16)) ?: '—' ?>
        <?php if (($r['vat_consultation'] ?? '') !== ''): ?><br><small class="muted"><?= h((string) $r['vat_consultation']) ?></small><?php endif; ?>
      </td>
      <td data-l="">
        <?php if ($isAdmin && $vat !== ''): ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="_t" value="<?= h($csrf) ?>">
          <input type="hidden" name="vat_eu" value="<?= h($vat) ?>">
          <input type="hidden" name="client_id" value="<?= (int) $r['id'] ?>">
          <button type="submit">Sprawdź</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php console_foot();
