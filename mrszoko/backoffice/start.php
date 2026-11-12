<?php
declare(strict_types=1);
/**
 * start.php — START SKLEPU : l'assistant de démarrage d'une boutique.
 *
 * De la boutique vide à la première vraie vente : les étapes dans l'ordre,
 * chacune avec son pourquoi, ses gestes et l'endroit où aller (un écran de
 * la console, une page de la boutique, ou le site extérieur où créer la page
 * Facebook, la Search Console…). Le moteur et les règles vivent dans
 * api/start.php ; cet écran ne fait que les montrer.
 *
 * Vert = PROUVÉ par les données, et ça ne se coche pas. On ne coche à la main
 * que ce que rien ne peut prouver (textes juridiques relus, logo qui
 * convient) — et seul l'administrateur coche.
 */
require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/start.php';

$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$flash = ''; $kind = 'ok';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    $cle = (string) ($_POST['krok'] ?? '');
    if (!$isAdmin) {
        $flash = 'Kroki zaznacza administrator sklepu.'; $kind = 'err';
    } elseif (wsm_start_cocher($pdo, $cle, ($_POST['zrobione'] ?? '') === '1', (string) ($me['nom'] ?? $me['email'] ?? ''))) {
        $flash = 'Zapisano: ' . (wsm_start_etapy()[$cle]['tytul'] ?? $cle) . '.';
    } else {
        $flash = 'Ten krok sprawdza się sam — z danych, nie z deklaracji.'; $kind = 'err';
    }
}

$etapy = wsm_start_etapy();
$etat  = wsm_start_etat($pdo);
$av    = wsm_start_avancement($pdo, $etat);
$pct   = $av['razem'] > 0 ? (int) round(100 * $av['fait'] / $av['razem']) : 0;

/** Un lien « où aller » : écran de la console (si le compte l'ouvre), page de la boutique, ou site extérieur. */
$gdzie = function (string $label, string $href) use ($me): string {
    if (preg_match('#^https://#', $href)) {
        return '<a class="gdzie zew" href="' . h($href) . '" target="_blank" rel="noopener noreferrer">' . h($label) . ' ↗</a>';
    }
    if (str_starts_with($href, '../shop/')) {
        return '<a class="gdzie" href="' . h($href) . '" target="_blank" rel="noopener">' . h($label) . ' ↗</a>';
    }
    return console_lien($me, $href, $label . ' →', 'gdzie');
};

console_head('Start sklepu', $me, <<<'CSS'
  .lead { font-size: 14px; line-height: 1.6; color: var(--text-muted); max-width: 80ch; margin: 0 0 18px; }
  .postep { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin: 0 0 24px; }
  .postep b { font-size: 22px; color: var(--text-strong); }
  .postep .pasek { flex: 1 1 240px; height: 8px; border-radius: 999px; background: var(--border-subtle); overflow: hidden; }
  .postep .pasek i { display: block; height: 100%; background: var(--success); border-radius: inherit; }
  .kroki { list-style: none; padding: 0; margin: 0; display: grid; gap: 14px; counter-reset: krok; }
  .krok { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: 14px;
          padding: 18px 20px; box-shadow: var(--shadow-xs); counter-increment: krok; scroll-margin-top: 80px; }
  .krok.nastepny { border-color: var(--brand); box-shadow: 0 0 0 1px var(--brand); }
  .krok-h { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 6px; }
  .krok-h::before { content: counter(krok); flex: none; width: 28px; height: 28px; border-radius: 50%;
          display: inline-flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700;
          background: var(--bo-fill); color: var(--text-strong); }
  .krok.ok .krok-h::before { content: "✓"; background: var(--success); color: var(--white); }
  .krok-h h2 { font-size: 16px; margin: 0; color: var(--text-strong); }
  .stan { margin-left: auto; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 999px;
          background: var(--bo-fill); color: var(--text-muted); white-space: nowrap; }
  .krok.ok .stan { background: color-mix(in srgb, var(--success) 14%, transparent); color: var(--success); }
  .krok.nastepny .stan { background: var(--brand); color: var(--on-brand); }
  .po-co { color: var(--text-body); font-size: 14px; line-height: 1.55; margin: 0 0 8px 40px; }
  .zrobic { margin: 0 0 10px 40px; padding-left: 18px; font-size: 13.5px; line-height: 1.6; color: var(--text-body); }
  .gdzie-lista { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 8px 40px; }
  a.gdzie { display: inline-flex; align-items: center; min-height: 36px; padding: 0 14px; border-radius: 999px;
          background: var(--bo-fill); color: var(--text-strong); font-size: 13px; font-weight: 600; text-decoration: none; }
  a.gdzie:hover { background: var(--bo-fill-hover); }
  .opis { margin: 0 0 0 40px; font-size: 13px; color: var(--text-muted); display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
  .opis form { margin: 0; }
  .opis button { min-height: 36px; font-size: 13px; }
  .opcja { font-weight: 500; font-size: 12px; color: var(--text-muted); }
  @media (max-width: 640px) { .po-co, .zrobic, .gdzie-lista, .opis { margin-left: 0; } }
CSS);
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Start sklepu' => null]);
?>

<p class="lead">
  Od pustego sklepu do pierwszej prawdziwej sprzedaży. Kroki idą po kolei: każdy mówi, po co jest, co zrobić
  i gdzie. Zielone sprawdza się samo — z danych sklepu, nie z deklaracji. Ręcznie zaznacza się tylko to,
  czego nic nie może sprawdzić.
</p>

<div class="postep">
  <b><?= $av['fait'] ?> z <?= $av['razem'] ?></b>
  <span class="pasek" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><i style="width: <?= $pct ?>%"></i></span>
  <?php if ($av['nastepny'] !== null): ?>
    <span>Następny krok: <a href="#<?= h($av['nastepny']) ?>"><?= h($etapy[$av['nastepny']]['tytul']) ?></a></span>
  <?php else: ?>
    <span>Sklep gotowy do sprzedaży.</span>
  <?php endif; ?>
</div>

<ol class="kroki">
<?php foreach ($etapy as $k => $e): $s = $etat[$k]; $nast = $k === $av['nastepny']; ?>
  <li class="krok<?= $s['fait'] ? ' ok' : '' ?><?= $nast ? ' nastepny' : '' ?>" id="<?= h($k) ?>">
    <div class="krok-h">
      <h2><?= h($e['tytul']) ?><?= !empty($e['opcja']) ? ' <span class="opcja">— opcjonalnie</span>' : '' ?></h2>
      <span class="stan"><?= $s['fait'] ? ($s['mode'] === 'reczne' ? 'Zaznaczone' : 'Gotowe') : ($nast ? 'Teraz' : 'Do zrobienia') ?></span>
    </div>
    <p class="po-co"><?= h($e['po_co']) ?></p>
    <?php if (!$s['fait']): ?>
    <ul class="zrobic"><?php foreach ($e['zrobic'] as $z): ?><li><?= h($z) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <div class="gdzie-lista"><?php foreach ($e['gdzie'] as [$l, $href]) echo $gdzie($l, $href); ?></div>
    <p class="opis">
      <span><?= h($s['opis']) ?></span>
      <?php if ($s['reczne'] && $isAdmin): ?>
      <form method="post" action="start.php#<?= h($k) ?>">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <input type="hidden" name="krok" value="<?= h($k) ?>">
        <input type="hidden" name="zrobione" value="<?= $s['fait'] ? '0' : '1' ?>">
        <button type="submit"<?= $s['fait'] ? ' class="ghost"' : ' class="primary"' ?>><?= $s['fait'] ? 'Cofnij' : 'Oznacz jako zrobione' ?></button>
      </form>
      <?php endif; ?>
    </p>
  </li>
<?php endforeach; ?>
</ol>

<?php console_foot();
