<?php
// ============================================================================
//  allegro.php — le second canal, vu d'ici.
//
//  L'ÉCRAN EST UTILE ALORS MÊME QUE LE CANAL EST FERMÉ, et c'est tout son
//  intérêt. Sans compte vendeur, il montre le travail de préparation qui
//  peut se faire AUJOURD'HUI : combien de fiches partiraient, combien sont
//  bloquées, et pourquoi. « 40 produits sans EAN » est une après-midi de
//  saisie — la découvrir le jour de l'ouverture en coûte une de plus, sous
//  pression.
//
//  Le tableau ne promet rien : il dit « voici ce qui PARTIRAIT ». Tant que
//  les identifiants manquent, aucun bouton n'envoie quoi que ce soit, et le
//  bandeau le dit avant tout le reste.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/allegro.php';

$reserve = (float) str_replace(',', '.', (string) ($_GET['rezerwa'] ?? WSM_ALLEGRO_RESERVE_PCT));
if ($reserve < 0 || $reserve > 90) $reserve = WSM_ALLEGRO_RESERVE_PCT;

$ouvert = wsm_allegro_enabled();
$manque = wsm_allegro_manquants();
$plan = wsm_allegro_plan($pdo, $reserve);
$k = wsm_allegro_kpis($plan);

console_head('Allegro', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 20px; max-width: 82ch; line-height: 1.6; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 22px; }
  .kpi { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 14px 16px; }
  .kpi b { display: block; font-family: var(--font-mono); font-size: 22px; color: var(--text-strong); }
  .kpi span { font-size: 12.5px; color: var(--text-muted); }
  .kpi.alarme b { color: var(--danger); }
  .kpi.pret b { color: var(--ok, #1a7f4b); }
  .causes { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
  .cause { font-size: 12.5px; padding: 7px 13px; border-radius: 999px; min-height: 38px;
           display: inline-flex; align-items: center; gap: 7px;
           border: 1px solid color-mix(in srgb, var(--danger) 35%, transparent); color: var(--danger); }
  .cause b { font-family: var(--font-mono); }
  .manque { margin: 0; padding-left: 20px; line-height: 1.9; font-size: 13.5px; }
  .manque code { font-family: var(--font-mono); font-size: 12.5px; }
  .rez { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; margin-bottom: 16px; }
  .rez label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; font-weight: 600; color: var(--text-strong); }
  .rez input { font-family: var(--font-mono); width: 110px; }
  .stop { color: var(--danger); font-size: 12.5px; line-height: 1.5; }
CSS, '');
console_crumbs(['Pulpit' => 'pulpit.php', 'Allegro' => null]);
?>

  <p class="hint">
    Ten ekran działa, <b>zanim</b> kanał zostanie otwarty — i po to istnieje. Pokazuje pracę,
    którą można wykonać <b>dziś</b>: ile ofert by poszło, ile jest zablokowanych i dlaczego.
    „40 produktów bez EAN” to jedno popołudnie uzupełniania; odkryte w dniu startu kosztuje
    drugie, pod presją. <b>Nic stąd nie wysyła.</b>
  </p>

  <?php if (!$ouvert): ?>
  <div class="panel" style="border-color: color-mix(in srgb, var(--warn, #9a6a00) 40%, transparent)">
    <h2>Kanał Allegro jest zamknięty</h2>
    <p class="hint" style="margin-bottom:10px">
      Brakuje danych dostępowych. Trafiają wyłącznie na serwer —
      do <code>config.local.php</code> lub zmiennych środowiskowych.
      <b>Nigdy do repozytorium</b>, które jest publiczne.
    </p>
    <ul class="manque">
      <?php foreach ($manque as $x): ?><li><?= h($x) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php else: ?>
  <div class="panel">
    <h2>Kanał Allegro jest otwarty</h2>
    <p class="hint" style="margin:0">Dane dostępowe są kompletne. Publikacja ofert nie jest jeszcze
      uruchomiona z tego ekranu — najpierw uzgodnij kategorie i parametry po stronie Allegro.</p>
  </div>
  <?php endif; ?>

  <div class="kpis">
    <div class="kpi"><b><?= (int) $k['produktow'] ?></b><span>produktów w sklepie</span></div>
    <div class="kpi pret"><b><?= (int) $k['gotowych'] ?></b><span>gotowych do wystawienia</span></div>
    <div class="kpi<?= $k['zablokowanych'] > 0 ? ' alarme' : '' ?>"><b><?= (int) $k['zablokowanych'] ?></b><span>zablokowanych</span></div>
    <div class="kpi"><b><?= (int) $k['sztuk'] ?></b><span>sztuk do wystawienia</span></div>
  </div>

  <?php if ($k['przyczyny']): ?>
  <div class="causes">
    <?php foreach ($k['przyczyny'] as $lib => $n): ?>
    <span class="cause"><b><?= (int) $n ?>×</b> <?= h((string) $lib) ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="panel">
    <h2>Co by poszło</h2>
    <p class="hint" style="margin-bottom:12px">
      <b>Nigdy nie wystawiamy całego stanu.</b> Te same tabliczki widoczne tu i tam sprzedają się
      <b>dwa razy</b>: drugi kupujący dostaje przeprosiny, a Allegro karze sprzedawcę za anulowanie.
      Rezerwa zostaje w sklepie — kanale, na którym marża jest cała.
      <b>Cena nie schodzi poniżej progu prowizji</b> (<?= (int) WSM_ALLEGRO_PROWIZJA_PCT ?> %):
      wystawienie po cenie sklepu to sprzedaż taniej niż u siebie.
    </p>
    <form class="rez" method="get">
      <label>Rezerwa (%)
        <input type="text" name="rezerwa" inputmode="decimal" value="<?= h(rtrim(rtrim(number_format($reserve, 1, ',', ''), '0'), ',')) ?>"></label>
      <button type="submit">Przelicz</button>
    </form>

    <?php if (!$plan): ?><p class="muted">Brak produktów w sklepie.</p><?php else: ?>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Tytuł oferty</th><th>EAN</th><th class="num">Cena sklep</th>
                 <th class="num">Cena Allegro</th><th class="num">Stan</th><th class="num">Wystawiamy</th><th>Stan oferty</th></tr></thead>
      <tbody>
      <?php foreach ($plan as $x): $o = $x['offer']; ?>
      <tr<?= $x['pret'] ? '' : ' style="opacity:.72"' ?>>
        <td data-l="Tytuł oferty"><b><?= h((string) $o['name']) ?></b>
          <?php if (!$x['pret']): ?>
          <br><span class="stop">✕ <?= h(implode(' · ', $o['_blockers'])) ?></span>
          <?php endif; ?></td>
        <td data-l="EAN"><span class="code"><?= h((string) $o['ean']) ?: '—' ?></span></td>
        <td data-l="Cena sklep" class="num"><?= h(pln((int) $o['_prix_sklep'])) ?></td>
        <td data-l="Cena Allegro" class="num"><b><?= h((string) $o['sellingMode']['price']['amount']) ?> zł</b></td>
        <td data-l="Stan" class="num"><?= (int) ($o['_plan']['publikowalne'] + $o['_plan']['rezerwa']) ?></td>
        <td data-l="Wystawiamy" class="num"><?= (int) $o['stock']['available'] ?></td>
        <td data-l="Stan oferty"><?= $o['publication']['status'] === 'ACTIVE' ? 'aktywna' : 'nieaktywna' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
<?php console_foot();
