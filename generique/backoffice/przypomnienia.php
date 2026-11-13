<?php
// ============================================================================
//  przypomnienia.php — les commandes créées et jamais payées.
//
//  CE QUE CET ÉCRAN MONTRE : de l'argent déjà à moitié gagné. Le client a
//  choisi ses articles, rempli son adresse, et s'est arrêté au paiement. Il
//  n'a pas dit non — il a été interrompu.
//
//  ET CE QU'IL EMPÊCHE : le harcèlement. Deux messages, puis silence. Le
//  troisième ne récupère personne, fait perdre le client pour de bon, et
//  envoie l'adresse d'expédition dans les indésirables. L'écran affiche donc
//  l'étape de chacun, et rien ne permet d'en envoyer un de plus.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/relance.php';

$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$flash = ''; $kind = 'ok';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może wysyłać przypomnienia.'; $kind = 'err';
    } elseif (isset($_POST['wyslij'])) {
        $r = wsm_relance_run($pdo, (string) ($me['nom'] ?? ''));
        $flash = $r['message'];
        $kind = $r['wyslane'] > 0 ? 'ok' : 'err';
    }
}

$file = wsm_relance_queue($pdo);
$k = wsm_relance_kpis($pdo, $file);
$blocage = wsm_relance_blocage();

console_head('Przypomnienia', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 20px; max-width: 82ch; line-height: 1.6; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 22px; }
  .kpi { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 14px 16px; }
  .kpi b { display: block; font-family: var(--font-mono); font-size: 22px; color: var(--text-strong); }
  .kpi span { font-size: 12.5px; color: var(--text-muted); }
  .kpi.somme b { color: var(--price, var(--brand)); }
  .l { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: start;
       padding: 12px 14px; border: 1px solid var(--border-subtle); border-radius: var(--radius-md);
       background: var(--surface-card); margin-bottom: 10px; }
  .l b { font-family: var(--font-mono); font-size: 14px; color: var(--text-strong); }
  .l small { display: block; color: var(--text-muted); font-size: 12.5px; margin-top: 2px; }
  .l .droite { text-align: right; font-family: var(--font-mono); font-size: 12.5px; color: var(--text-muted); white-space: nowrap; }
  .etap { font-size: 12px; padding: 2px 10px; border-radius: 999px; border: 1px solid var(--border-subtle);
          display: inline-block; margin-top: 6px; }
  .etap.deux { color: var(--warn, #9a6a00); border-color: color-mix(in srgb, var(--warn, #9a6a00) 45%, transparent); }
CSS, '');
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Przypomnienia' => null]);
?>

  <p class="hint">
    To pieniądze <b>w połowie zarobione</b>: klient wybrał towar, wpisał adres i zatrzymał się
    na płatności. Nie powiedział „nie” — został przerwany.
    <b>Dwa listy, potem cisza.</b> Trzeci nikogo nie odzyskuje, traci klienta na dobre
    i wysyła nasz adres nadawcy do spamu. Odstęp między listami to
    <b><?= (int) (WSM_RELANCE_ODSTEP_H / 24) ?> dni</b> — bez niego stare zamówienie dostałoby
    oba listy w tej samej minucie. Po <?= (int) WSM_RELANCE_ABANDON_JOURS ?> dniach zamówienie
    uznajemy za porzucone i milkniemy.
    <b>Nic nie leci prosto do SMTP</b>: listy wchodzą do kolejki.
  </p>

  <?php if ($blocage !== ''): ?>
  <div class="panel" style="border-color: color-mix(in srgb, var(--warn, #9a6a00) 40%, transparent)">
    <h2>Nie wysyłamy przypomnień</h2>
    <p class="hint" style="margin:0"><?= h($blocage) ?></p>
  </div>
  <?php endif; ?>

  <div class="kpis">
    <div class="kpi"><b><?= (int) $k['nieoplacone'] ?></b><span>zamówień bez zapłaty</span></div>
    <div class="kpi somme"><b><?= h(pln((int) $k['kwota'])) ?></b><span>tyle zostaje na stole</span></div>
    <div class="kpi"><b><?= (int) $k['etap1'] ?></b><span>czeka na 1. przypomnienie</span></div>
    <div class="kpi"><b><?= (int) $k['etap2'] ?></b><span>czeka na ostatnie</span></div>
  </div>

  <div class="panel">
    <h2>Do przypomnienia</h2>
    <?php if (!$file): ?>
      <p class="muted">Nic nie czeka. Albo wszystko opłacone, albo przypomnienia już poszły.</p>
    <?php else: ?>
      <?php foreach ($file as $x): $o = $x['order']; ?>
      <div class="l">
        <span>
          <b><?= h((string) $o['code']) ?></b>
          <small><?= h(trim((string) $o['first_name'] . ' ' . (string) $o['last_name']) ?: (string) $o['company']) ?>
            · <?= h((string) $o['email']) ?></small>
          <span class="etap<?= $x['etape'] === 2 ? ' deux' : '' ?>">
            <?= h((string) (WSM_RELANCE_ETAPES[$x['etape']]['label'] ?? '')) ?></span>
        </span>
        <span class="droite">
          <?= h(pln((int) $o['total_gross'])) ?><br>
          <?= (int) floor($x['heures'] / 24) ?> dni temu
        </span>
      </div>
      <?php endforeach; ?>
      <?php if ($isAdmin && $blocage === ''): ?>
      <form method="post" style="margin-top:16px">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <button class="primary" type="submit" name="wyslij" value="1">
          Wyślij <?= count($file) ?> przypomnień do kolejki</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php console_foot();
