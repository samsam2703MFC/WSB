<?php
// ============================================================================
//  kampanie.php — les envois groupés.
//
//  L'ÉCRAN EST CONSTRUIT AUTOUR D'UNE PEUR : qu'on envoie trop vite. Trois
//  gestes séparés, dans cet ordre, et jamais un bouton unique :
//
//      préparer  →  s'envoyer une PRÓBKA  →  envoyer à tout le monde
//
//  Une coquille dans l'objet part à cent cinquante personnes et ne se
//  rattrape pas. Le bouton d'envoi n'apparaît donc qu'après la préparation,
//  et le nombre de destinataires est écrit à côté, en toutes lettres.
//
//  RIEN NE PART D'ICI VERS SMTP. Les messages entrent en file et le
//  travailleur de fond les écoule. Cent messages d'un coup depuis une IP qui
//  n'en envoie jamais coûtent la réputation du domaine — donc les
//  confirmations de commande, qui n'ont rien demandé.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/campaign.php';
wsm_camp_ensure($pdo);

$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$flash = ''; $kind = 'ok'; $post = [];
$baseShop = wsm_shop_base_url();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może wysyłać kampanie.'; $kind = 'err';
    } elseif (isset($_POST['przygotuj'])) {
        [$id, $m] = wsm_camp_create($pdo, $_POST, (string) ($me['nom'] ?? ''));
        $flash = $m; $kind = $id > 0 ? 'ok' : 'err';
        if ($id === 0) $post = $_POST;
    } elseif (isset($_POST['probka'])) {
        [$ok, $m] = wsm_camp_test($pdo, (int) $_POST['id'], (string) ($_POST['adres'] ?? ''),
                                  $baseShop, (string) ($me['nom'] ?? ''));
        $flash = $m; $kind = $ok ? 'ok' : 'err';
    } elseif (isset($_POST['wyslij'])) {
        $r = wsm_camp_send($pdo, (int) $_POST['wyslij'], $baseShop, (string) ($me['nom'] ?? ''));
        $flash = $r['message']; $kind = $r['files'] > 0 ? 'ok' : 'err';
    }
}

$camps = wsm_camp_list($pdo);
$tailles = [];
foreach (WSM_CAMP_SEGMENTS as $s => $lib) $tailles[$s] = count(wsm_camp_audience($pdo, $s));
$refus = count(wsm_camp_refus($pdo));

console_head('Kampanie', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 20px; max-width: 82ch; line-height: 1.6; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 22px; }
  .kpi { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 14px 16px; }
  .kpi b { display: block; font-family: var(--font-mono); font-size: 22px; color: var(--text-strong); }
  .kpi span { font-size: 12.5px; color: var(--text-muted); }
  .form { display: grid; grid-template-columns: 1fr; gap: 14px; }
  @media (min-width: 860px) { .form { grid-template-columns: 1fr 1fr; } .form .large { grid-column: 1 / -1; } }
  .form label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; font-weight: 600; color: var(--text-strong); }
  input[type=text], input[type=email], select, textarea { font-family: var(--font-sans); width: 100%; }
  textarea { resize: vertical; min-height: 150px; line-height: 1.6; }
  .camp { border: 1px solid var(--border-subtle); border-radius: var(--radius-lg);
          padding: 16px; margin-bottom: 14px; background: var(--surface-card); }
  .camp-tete { display: flex; gap: 12px; align-items: baseline; flex-wrap: wrap; margin-bottom: 8px; }
  .camp-tete b { font-size: 15px; color: var(--text-strong); }
  .etat { font-size: 12px; padding: 2px 10px; border-radius: 999px; border: 1px solid var(--border-subtle);
          margin-left: auto; white-space: nowrap; }
  .etat.wyslana { color: var(--ok, #1a7f4b); border-color: color-mix(in srgb, var(--ok, #1a7f4b) 40%, transparent); }
  .camp-corps { white-space: pre-wrap; font-size: 13.5px; line-height: 1.6; color: var(--text-body);
                background: var(--bg-page); border-radius: var(--radius-md); padding: 12px 14px; margin-bottom: 12px;
                max-height: 190px; overflow: auto; }
  .gestes { display: flex; gap: 10px; flex-wrap: wrap; align-items: end; }
  .gestes label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; font-weight: 600; }
  .gestes input { min-width: 230px; }
CSS, '');
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Kampanie' => null]);
?>

  <p class="hint">
    <b>Nic nie leci prosto do SMTP.</b> Wysyłka wkłada listy do <b>kolejki</b>, a wypuszcza je
    stopniowo pracownik w tle — sto listów naraz z adresu, który zwykle nic nie wysyła,
    kosztuje reputację domeny, a razem z nią <b>potwierdzenia zamówień</b>, które o nic nie prosiły.
    Piszemy <b>tylko do tych, którzy kupili</b>. Każdy list niesie <b>link wypisu</b> —
    dokłada go kod, nie redakcja, bo człowiek w pośpiechu zapomni raz, a raz wystarczy.
    <b>Najpierw wyślij próbkę do siebie.</b> Literówka w temacie leci do wszystkich i nie da się jej cofnąć.
  </p>

  <div class="kpis">
    <?php foreach (WSM_CAMP_SEGMENTS as $s => $lib): ?>
    <div class="kpi"><b><?= (int) $tailles[$s] ?></b><span><?= h($lib) ?></span></div>
    <?php endforeach; ?>
    <div class="kpi"><b><?= (int) $refus ?></b><span>rezygnacji — nie piszemy do nich</span></div>
  </div>

  <?php if ($isAdmin): ?>
  <div class="panel">
    <h2>Nowa kampania</h2>
    <form class="form" method="post">
      <input type="hidden" name="_t" value="<?= h($csrf) ?>">
      <input type="hidden" name="przygotuj" value="1">
      <label>Nazwa wewnętrzna
        <input type="text" name="nom" placeholder="np. listopad — nowa tabliczka"
               value="<?= h((string) ($post['nom'] ?? '')) ?>"></label>
      <label>Do kogo
        <select name="segment">
          <?php foreach (WSM_CAMP_SEGMENTS as $s => $lib): ?>
          <option value="<?= h($s) ?>"<?= ($post['segment'] ?? '') === $s ? ' selected' : '' ?>>
            <?= h($lib) ?> — <?= (int) $tailles[$s] ?> osób</option>
          <?php endforeach; ?>
        </select></label>
      <label class="large">Temat
        <input type="text" name="sujet" placeholder="Co zobaczy klient w skrzynce"
               value="<?= h((string) ($post['sujet'] ?? '')) ?>"></label>
      <label class="large">Treść — <code>{{imie}}</code> wstawi imię odbiorcy
        <textarea name="corps" rows="8" placeholder="Dzień dobry {{imie}},&#10;&#10;…"><?= h((string) ($post['corps'] ?? '')) ?></textarea></label>
      <div class="large"><button class="primary" type="submit">Przygotuj (nic jeszcze nie wyśle)</button></div>
    </form>
  </div>
  <?php endif; ?>

  <div class="panel">
    <h2>Kampanie</h2>
    <?php if (!$camps): ?><p class="muted">Brak kampanii.</p><?php endif; ?>
    <?php foreach ($camps as $c): $wyslana = (string) $c['statut'] === 'wyslana'; ?>
    <div class="camp">
      <div class="camp-tete">
        <b><?= h((string) $c['nom']) ?></b>
        <span class="muted" style="font-size:13px"><?= h((string) $c['segment_label']) ?></span>
        <span class="etat <?= h((string) $c['statut']) ?>">
          <?= $wyslana ? 'wysłana ' . h(substr((string) $c['sent_at'], 0, 10)) . ' · ' . (int) $c['wyslane'] . ' listów' : 'przygotowana' ?>
        </span>
      </div>
      <p class="hint" style="margin:0 0 8px"><b>Temat:</b> <?= h((string) $c['sujet']) ?></p>
      <div class="camp-corps"><?= h((string) $c['corps']) ?></div>
      <?php if ($wyslana): ?>
      <p class="hint" style="margin:0">
        W 30 dni po wysyłce odbiorcy złożyli <b><?= (int) $c['zamowien'] ?></b> zamówień
        na <b><?= h(pln((int) $c['obrot'])) ?></b>.
        <i>To rząd wielkości, nie dowód przyczyny — ludzie kupują też bez naszych listów.</i>
      </p>
      <?php elseif ($isAdmin): ?>
      <div class="gestes">
        <form method="post" class="gestes">
          <input type="hidden" name="_t" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <label>Próbka na adres
            <input type="email" name="adres" value="<?= h((string) ($me['email'] ?? '')) ?>"></label>
          <button type="submit" name="probka" value="1">Wyślij próbkę</button>
        </form>
        <form method="post" onsubmit="return confirm('Wysłać do wszystkich? Tego nie da się cofnąć.');">
          <input type="hidden" name="_t" value="<?= h($csrf) ?>">
          <button class="primary" type="submit" name="wyslij" value="<?= (int) $c['id'] ?>">
            Wyślij do wszystkich (<?= (int) ($tailles[(string) $c['segment']] ?? 0) ?>)</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
<?php console_foot();
