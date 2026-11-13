<?php
// ============================================================================
//  kraje.php — écran « Kraje i VAT » de la console marque.
//
//  Deux décisions se prennent ici, et elles ne se ressemblent pas :
//    · OÙ l'on vend — cocher un pays l'ouvre à la commande ;
//    · QUI l'on livre — quels pays chaque transporteur dessert réellement.
//
//  La TVA en découle, elle ne se règle pas à la main : Pologne = TVA polonaise ;
//  autre État membre + numéro de TVA confirmé par VIES = 0 % (autoliquidation).
//  Un particulier d'un autre pays paie la TVA polonaise — voir l'avertissement
//  sur le seuil OSS en bas de page.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';

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
        $flash = 'Tylko rola Centrala może zmieniać ustawienia.'; $kind = 'err';
    } elseif (isset($_POST['save_countries'])) {
        $on = array_map('strtoupper', (array) ($_POST['active'] ?? []));
        // La Pologne reste toujours ouverte : c'est le marché domestique et le
        // pays d'établissement. La décocher fermerait la boutique.
        if (!in_array('PL', $on, true)) $on[] = 'PL';
        $pdo->prepare("UPDATE wsm_countries SET active = 0")->execute();
        $up = $pdo->prepare("UPDATE wsm_countries SET active = 1 WHERE code = ?");
        foreach ($on as $c) if (preg_match('/^[A-Z]{2}$/', $c)) $up->execute([$c]);
        wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Zmiana', 'wsm_countries (' . count($on) . ' aktywnych)', 'Sieć');
        $flash = 'Zapisano kraje sprzedaży: ' . count($on) . '.';
    }
    // La portée d'un transporteur ne s'écrit plus ici : deux formulaires sur
    // la même colonne, c'est un jour où l'un écrase l'autre. Elle est passée
    // sur « Dostawa », où l'on voit en même temps le coût du colis et le poids
    // maximum — les deux nombres sans lesquels on la réglait à l'aveugle.
}

$countries = $pdo->query("SELECT * FROM wsm_countries ORDER BY sort_order, name_pl")->fetchAll();
$methods   = $pdo->query("SELECT * FROM wsm_shipping_methods ORDER BY sort_order")->fetchAll();
$nActive   = count(array_filter($countries, fn($c) => (int) $c['active'] === 1));
$nOrdersRc = 0;
try { $nOrdersRc = (int) $pdo->query("SELECT COUNT(*) FROM wsm_orders WHERE reverse_charge = 1")->fetchColumn(); }
catch (Throwable $e) {}

console_head('Kraje i VAT', $me, <<<'CSS'
  .rule { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: 14px;
          padding: 18px 16px; margin-bottom: 20px; box-shadow: var(--shadow-xs); font-size: 13.5px; line-height: 1.6; }
  .rule h2 { font-family: var(--font-display); font-size: 18px; margin: 0 0 10px; color: var(--text-strong); }
  .rule table { border: 0; margin-top: 10px; }
  .rule td, .rule th { padding: 7px 10px; }
  .panel p.sub { color: var(--text-muted); font-size: 13px; margin: 0 0 16px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 4px 14px; }
  label.c { display: flex; gap: 10px; align-items: center; font-size: 14px; cursor: pointer;
            padding: 9px 10px; border-radius: 9px; border: 1px solid transparent; min-height: 44px; }
  label.c:hover { border-color: var(--border-subtle); background: var(--surface-raised); }
  label.c input { accent-color: var(--brand); }
  label.c .code { font-family: var(--font-mono); font-size: 11.5px; color: var(--text-muted); }
  label.c.home { background: var(--brand-quiet); border-color: var(--border-default); }
  .ship { display: grid; grid-template-columns: 1fr; gap: 10px 16px; align-items: center; }
  @media (min-width: 700px) { .ship { grid-template-columns: 220px 1fr; } }
  .ship input { font-family: var(--font-mono); width: 100%; }
CSS, '');
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Kraje i VAT' => null]);
?>

  <div class="rule">
    <h2>Jak liczony jest VAT</h2>
    <p>Stawka nie jest ustawiana ręcznie — wynika z kraju dostawy i z numeru VAT UE potwierdzonego w VIES.</p>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Kraj dostawy</th><th>Numer VAT UE</th><th>Stawka</th></tr></thead>
      <tbody>
      <tr><td data-l="Kraj dostawy"><b>Polska</b> — rynek krajowy</td>
          <td data-l="Numer VAT UE">obojętne</td>
          <td data-l="Stawka"><b>polski VAT (23 %)</b></td></tr>
      <tr><td data-l="Kraj dostawy">Inny kraj UE</td>
          <td data-l="Numer VAT UE">potwierdzony w VIES jako <b>ważny</b></td>
          <td data-l="Stawka"><b>0 % — odwrotne obciążenie</b></td></tr>
      <tr><td data-l="Kraj dostawy">Inny kraj UE</td>
          <td data-l="Numer VAT UE">brak, błędny lub VIES nie odpowiedział</td>
          <td data-l="Stawka">polski VAT</td></tr>
      </tbody>
    </table>
    </div>
    <div class="warnbox">
      <b>Uwaga na próg OSS.</b> Klient prywatny z innego kraju UE płaci u nas polski VAT. Jest to poprawne
      dopiero do progu 10 000 € rocznej sprzedaży wysyłkowej do UE. Po jego przekroczeniu trzeba naliczać
      stawkę kraju odbiorcy (procedura OSS) — wtedy ten ekran wymaga rozbudowy o stawki krajowe.
      <br><b>Zwolnienie 0 % wymaga też dowodu wywozu towaru</b> do innego kraju UE — sam numer VAT nie wystarczy.
    </div>
  </div>

  <div class="kpis">
    <div class="kpi"><b><?= $nActive ?></b><span>Kraje otwarte</span></div>
    <div class="kpi"><b><?= count($countries) ?></b><span>Kraje UE w bazie</span></div>
    <div class="kpi"><b><?= $nOrdersRc ?></b><span>Zamówienia 0 %</span></div>
  </div>

  <form method="post">
    <input type="hidden" name="_t" value="<?= h($csrf) ?>">
    <div class="panel">
      <h2>Gdzie sprzedajemy</h2>
      <p class="sub">Zaznaczone kraje pojawiają się w kasie sklepu. Polska jest zawsze otwarta — to kraj siedziby.</p>
      <div class="grid">
        <?php foreach ($countries as $c): $home = $c['code'] === 'PL'; ?>
        <label class="c<?= $home ? ' home' : '' ?>">
          <input type="checkbox" name="active[]" value="<?= h((string) $c['code']) ?>"
                 <?= (int) $c['active'] === 1 ? 'checked' : '' ?>
                 <?= $home || !$isAdmin ? 'disabled' : '' ?>>
          <?php if ($home): ?><input type="hidden" name="active[]" value="PL"><?php endif; ?>
          <span><?= h((string) $c['name_pl']) ?> <span class="code"><?= h((string) $c['code']) ?></span></span>
        </label>
        <?php endforeach; ?>
      </div>
      <?php if ($isAdmin): ?><button class="primary" type="submit" name="save_countries" value="1">Zapisz kraje</button><?php endif; ?>
    </div>
  </form>

  <div class="panel">
    <h2>Zasięg przewoźników</h2>
    <?php // OUVRIR UN PAYS ET LE DESSERVIR SONT DEUX DÉCISIONS. La première
          // engage la TVA, l'OSS et les mentions légales ; la seconde un tarif,
          // un poids et un coût de colis. Elles étaient sur le même écran par
          // commodité, et l'on réglait la portée sans voir aucun des nombres
          // qui la rendent tenable. La portée a donc suivi le tarif. ?>
    <p class="sub">Który przewoźnik dokąd jeździ — razem z ceną, kosztem paczki i maksymalną
      wagą — ustawia się na ekranie <a href="dostawa.php"><b>Dostawa</b></a>.
      Kraj otwarty tutaj, ale <b>bez przewoźnika</b>, nie pozwoli złożyć zamówienia —
      i kasa powie to wprost.</p>
    <div class="ship">
      <?php foreach ($methods as $m): ?>
      <span><b><?= h((string) $m['id']) ?></b><br>
        <small style="color:var(--text-muted)"><?= h((string) $m['carrier']) ?></small></span>
      <span class="code"><?= h(((string) ($m['countries'] ?? '')) !== ''
            ? (string) $m['countries'] : 'wszędzie') ?></span>
      <?php endforeach; ?>
    </div>
    <div class="actions"><a class="btn btn--brand" href="dostawa.php">Przejdź do Dostawy</a></div>
  </div>
<?php console_foot();
