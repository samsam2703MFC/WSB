<?php
// ============================================================================
//  audyt.php — ce que la boutique a fait, en chiffres et en journal.
//
//  Les graphiques sont du SVG écrit à la main. Pas de bibliothèque : une page
//  de console n'a pas besoin de trois cents kilo-octets de JavaScript pour
//  tracer douze barres, et un graphique servi depuis un CDN est un graphique
//  qui disparaît le jour où le CDN tombe.
//
//  Toutes les séries sont lues en base, jamais inventées : quand il n'y a pas
//  de données, le graphique le dit au lieu d'afficher une jolie courbe fausse.
//
//  Lecture : tout compte actif.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/analytics.php';

// ---------------------------------------------------------------------------
//  Séries
// ---------------------------------------------------------------------------

/** Douze mois glissants : chiffre d'affaires encaissé et nombre de commandes. */
function serie_mois(PDO $pdo, int $months = 12): array {
    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $t = strtotime("first day of -$i month");
        $out[date('Y-m', $t)] = ['label' => date('m/y', $t), 'gross' => 0, 'orders' => 0];
    }
    $rows = $pdo->query("SELECT created_at, total_gross, payment_status FROM wsm_orders
                          WHERE status <> 'anulowane'")->fetchAll() ?: [];
    foreach ($rows as $r) {
        $k = substr((string) $r['created_at'], 0, 7);
        if (!isset($out[$k])) continue;
        $out[$k]['orders']++;
        if ((string) $r['payment_status'] === 'oplacone') $out[$k]['gross'] += (int) $r['total_gross'];
    }
    return array_values($out);
}

/** Trente jours glissants : commandes par jour. */
function serie_jours(PDO $pdo, int $days = 30): array {
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', time() - $i * 86400);
        $out[$d] = ['label' => date('d/m', strtotime($d)), 'n' => 0];
    }
    $rows = $pdo->query("SELECT created_at FROM wsm_orders WHERE status <> 'anulowane'")->fetchAll() ?: [];
    foreach ($rows as $r) {
        $k = substr((string) $r['created_at'], 0, 10);
        if (isset($out[$k])) $out[$k]['n']++;
    }
    return array_values($out);
}

/** Clients cumulés : chaque adresse compte une fois, au mois de sa première commande. */
function serie_clients(PDO $pdo, int $months = 12): array {
    $first = [];
    // LOWER(email) dans la liste ET dans le GROUP BY : MySQL 8 (ONLY_FULL_GROUP_BY)
    // refuse une colonne nue qui n'est pas celle du regroupement — l'écran tombait.
    $rows = $pdo->query("SELECT LOWER(email) AS email, MIN(created_at) AS d FROM wsm_orders
                          WHERE email <> '' GROUP BY LOWER(email)")->fetchAll() ?: [];
    foreach ($rows as $r) $first[] = substr((string) $r['d'], 0, 7);

    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $t = strtotime("first day of -$i month");
        $k = date('Y-m', $t);
        $out[] = ['label' => date('m/y', $t),
                  'n' => count(array_filter($first, fn($m) => $m <= $k)),
                  'new' => count(array_filter($first, fn($m) => $m === $k))];
    }
    return $out;
}

function top_produits(PDO $pdo, int $limit = 6): array {
    try {
        return $pdo->query("SELECT i.name, SUM(i.qty) AS n, SUM(i.line_gross) AS v
                              FROM wsm_order_items i JOIN wsm_orders o ON o.id = i.order_id
                             WHERE o.status <> 'anulowane'
                             GROUP BY i.name ORDER BY n DESC LIMIT " . $limit)->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

// ---------------------------------------------------------------------------
//  Tracés — du SVG, rien d'autre
// ---------------------------------------------------------------------------

/**
 * Un graphique « dense » : trop de points pour afficher toutes les etiquettes
 * sur un telephone. La feuille de style n'en garde alors qu'une sur cinq.
 */
function chart_dense(int $n): string { return $n > 14 ? ' dense' : ''; }

/**
 * Un graphique trop long ne se partage pas une rangee : il la prend entiere.
 * Huit points est la limite au-dela de laquelle une demi-largeur rend l'axe
 * illisible — douze mois serres dans 470 px se chevauchent.
 */
function chart_wide(int $n): string { return $n > 8 ? ' wide' : ''; }

/** Histogramme. $vals : [['label'=>…, 'v'=>int], …]. $fmt met en forme la valeur. */
function graph_bars(array $vals, callable $fmt, string $color = 'var(--brand)'): string {
    if (!$vals) return '<p class="muted small">Brak danych.</p>';
    $fmt = fn($v) => (string) $fmt($v);
    $max = max(1, max(array_map(fn($r) => $r['v'], $vals)));
    $n = count($vals);
    $w = 100 / $n;                                     // largeur d'une colonne, en %
    $out = '<div class="chart' . chart_dense($n) . '"><div class="bars">';
    foreach ($vals as $r) {
        $h = $max > 0 ? round($r['v'] / $max * 100, 1) : 0;
        $out .= '<div class="bar" style="width:' . round($w, 3) . '%" title="' . h($r['label'] . ' — ' . $fmt($r['v'])) . '">'
              . '<span class="fill" style="height:' . $h . '%;background:' . $color . '"></span>'
              . '<span class="lbl">' . h($r['label']) . '</span></div>';
    }
    $out .= '</div><div class="scale"><span>' . h($fmt($max)) . '</span><span>0</span></div></div>';
    return $out;
}

/** Courbe cumulée, en SVG : une polyligne et des points. */
function graph_line(array $vals, callable $fmt): string {
    if (!$vals) return '<p class="muted small">Brak danych.</p>';
    $fmt = fn($v) => (string) $fmt($v);
    $max = max(1, max(array_map(fn($r) => $r['v'], $vals)));
    $n = count($vals);
    $pts = [];
    foreach (array_values($vals) as $i => $r) {
        $x = $n > 1 ? round($i / ($n - 1) * 100, 2) : 50;
        $y = round(100 - ($r['v'] / $max * 100), 2);
        $pts[] = $x . ',' . $y;
    }
    $poly = implode(' ', $pts);
    $area = '0,100 ' . $poly . ' 100,100';
    $out  = '<div class="chart' . chart_dense($n) . '"><svg viewBox="0 0 100 100" preserveAspectRatio="none" class="line" aria-hidden="true">'
          . '<polygon points="' . h($area) . '" fill="var(--brand-quiet, rgba(120,70,40,.12))"></polygon>'
          . '<polyline points="' . h($poly) . '" fill="none" stroke="var(--brand)" stroke-width="1.5"'
          . ' vector-effect="non-scaling-stroke" stroke-linejoin="round"></polyline></svg>';
    $out .= '<div class="bars ticks">';
    foreach ($vals as $r) {
        $out .= '<div class="bar" style="width:' . round(100 / $n, 3) . '%" title="' . h($r['label'] . ' — ' . $fmt($r['v'])) . '">'
              . '<span class="lbl">' . h($r['label']) . '</span></div>';
    }
    $out .= '</div><div class="scale"><span>' . h($fmt($max)) . '</span><span>0</span></div></div>';
    return $out;
}

/**
 * Deux séries superposées, une claire et une foncée : marge brute et résultat
 * après livraison. L'écart entre les deux EST le coût du port — le montrer sur
 * un seul graphique évite d'avoir à soustraire deux chiffres de tête.
 * Les valeurs négatives sont dessinées sous la ligne zéro : un mois à perte
 * doit se voir comme une perte, pas comme une petite barre.
 */
function graph_paire(array $vals, callable $fmt): string {
    if (!$vals) return '<p class="muted small">Brak danych.</p>';
    $fmt = fn($v) => (string) $fmt($v);
    $hi = max(0, max(array_map(fn($r) => max($r['a'], $r['b']), $vals)));
    $lo = min(0, min(array_map(fn($r) => min($r['a'], $r['b']), $vals)));
    $span = max(1, $hi - $lo);
    $zero = round(($hi / $span) * 100, 2);            // position du zéro, en % depuis le haut
    $n = count($vals);
    $out = '<div class="chart' . chart_dense($n) . '"><div class="bars pair">';
    foreach ($vals as $r) {
        $out .= '<div class="bar" style="width:' . round(100 / $n, 3) . '%" title="'
              . h($r['label'] . ' — marża ' . $fmt($r['a']) . ' · po dostawie ' . $fmt($r['b'])) . '">'
              . '<span class="plot"><span class="zero" style="top:' . $zero . '%"></span>';
        foreach ([['a', 'ma'], ['b', 'mb']] as [$k, $cls]) {
            $v = (int) $r[$k];
            $hgt = round(abs($v) / $span * 100, 2);
            $top = $v >= 0 ? $zero - $hgt : $zero;
            $out .= '<span class="seg ' . $cls . '" style="top:' . $top . '%;height:' . max(0.6, $hgt) . '%"></span>';
        }
        $out .= '</span><span class="lbl">' . h($r['label']) . '</span></div>';
    }
    $out .= '</div><div class="scale"><span>' . h($fmt($hi)) . '</span>'
          . ($lo < 0 ? '<span>' . h($fmt($lo)) . '</span>' : '<span>0</span>') . '</div></div>';
    return $out;
}

/**
 * Le pourcentage du coût de livraison payé par le client. La ligne des 100 %
 * est tracée : au-dessus le port se finance, en dessous il mange la marge.
 * C'est le seul repère qui compte, donc il est dessiné, pas expliqué.
 */
function graph_pourcent(array $vals): string {
    if (!$vals) return '<p class="muted small">Brak danych.</p>';
    $hi = max(120, (float) max(array_map(fn($r) => (float) $r['v'], $vals)) + 10);
    $n = count($vals);
    $cent = round(100 - (100 / $hi * 100), 2);
    $out = '<div class="chart' . chart_dense($n) . '"><div class="bars pct">';
    $out .= '<div class="cent" style="top:' . $cent . '%"><span>100 %</span></div>';
    foreach ($vals as $r) {
        $v = (float) $r['v'];
        // Un mois SANS expédition n'a pas une couverture de 0 % : il n'a pas de
        // couverture du tout. Dessiner un moignon rouge ferait lire « très
        // mauvais mois » là où il ne s'est simplement rien passé.
        $vide = empty($r['has']);
        $hgt = round($v / $hi * 100, 2);
        $cls = $v >= 100 ? 'ok' : ($v >= 70 ? 'warn' : 'bad');
        $out .= '<div class="bar" style="width:' . round(100 / $n, 3) . '%" title="'
              . h($r['label'] . ' — ' . ($vide ? 'brak wysyłek' : number_format($v, 1, ',', ' ') . ' %')) . '">'
              . '<span class="plot">'
              . ($vide ? '' : '<span class="fill ' . $cls . '" style="height:' . max(0.6, $hgt) . '%"></span>')
              . '</span><span class="lbl">' . h($r['label']) . '</span></div>';
    }
    $out .= '</div><div class="scale"><span>' . (int) $hi . ' %</span><span>0</span></div></div>';
    return $out;
}

$mois    = serie_mois($pdo);
$jours   = serie_jours($pdo);
$clients = serie_clients($pdo);
$tops    = top_produits($pdo);
$kpis    = wsm_shop_kpis($pdo);
$marge   = wsm_margin_series($pdo);
$mtot    = wsm_margin_totals($marge);
$prev    = wsm_forecast($pdo, $marge);
$wyc     = wsm_valuation($marge, $mtot);

$clientsTotal = (int) ($clients[count($clients) - 1]['n'] ?? 0);
$repeat = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT LOWER(email) e FROM wsm_orders
                              WHERE email <> '' GROUP BY LOWER(email) HAVING COUNT(*) > 1) t")->fetchColumn();

$audit = $pdo->query("SELECT * FROM wsm_audit ORDER BY id DESC LIMIT 150")->fetchAll() ?: [];

console_head('Audyt', $me, <<<'CSS'
  .why { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0 0 14px; }
  /* minmax(0, 1fr) et non 1fr : « 1fr » vaut « minmax(auto, 1fr) », dont le
     minimum est le min-content de la colonne. Douze étiquettes de mois en
     nowrap suffisaient donc à imposer 761 px au panneau — à TOUTES les
     largeurs, y compris 390. Le zéro autorise la colonne à descendre sous la
     taille de son contenu, et la chaîne de min-width: 0 fait le reste. */
  .charts { display: grid; grid-template-columns: minmax(0, 1fr); gap: 20px; }
  @media (min-width: 900px) { .charts { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  /* Un graphique trop long ne se partage pas une rangée : il la prend entière.
     Au-delà de huit points, serrer douze mois dans une demi-largeur rend l'axe
     illisible — autant lui donner toute la place. */
  .charts .wide { grid-column: 1 / -1; }
  .chart { position: relative; padding-right: 54px; min-width: 0; }
  .chart .bars { display: flex; align-items: flex-end; gap: 2px; height: 160px; min-width: 0; }
  .chart .bar { display: flex; flex-direction: column; justify-content: flex-end;
                align-items: center; height: 100%; min-width: 0; }
  .chart .fill { display: block; width: 72%; min-height: 2px; border-radius: 3px 3px 0 0; }
  .chart .lbl { font-family: var(--font-mono); font-size: 9.5px; color: var(--text-muted);
                margin-top: 5px; white-space: nowrap; overflow: hidden; text-overflow: clip;
                min-width: 0; max-width: 100%; }
  /* Trente jours ne tiennent pas côte à côte sur un téléphone. Plutôt que de
     rogner les étiquettes jusqu'à l'illisible, on n'en garde qu'une sur cinq :
     l'axe reste lisible et les barres gardent leur largeur. */
  @media (max-width: 700px) {
    .chart.dense .lbl { display: none; }
    .chart.dense .bar:nth-child(5n+1) .lbl { display: block; }
    .chart .bars { gap: 1px; }
  }
  .chart .ticks { height: auto; align-items: flex-start; }
  .chart .scale { position: absolute; right: 0; top: 0; height: 160px;
                  display: flex; flex-direction: column; justify-content: space-between;
                  font-family: var(--font-mono); font-size: 10px; color: var(--text-muted); text-align: right; }
  .chart svg.line { width: 100%; height: 160px; display: block; }
  .rank { display: grid; gap: 8px; }
  .rank .row { display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: center; font-size: 13.5px; }
  .rank .track { grid-column: 1 / -1; height: 6px; border-radius: 999px; background: var(--surface-sunken); }
  .rank .track i { display: block; height: 100%; border-radius: 999px; background: var(--brand); }
  /* Les segments sont posés dans un cadre à eux : sans ce cadre, une barre
     descendant sous zéro recouvrirait l'étiquette du mois — le graphique
     paraîtrait juste, avec l'axe illisible. */
  .chart .pair .plot, .chart .pct .plot { position: relative; display: block;
                                          width: 100%; flex: 1 1 auto; min-height: 0; }
  .chart .pct .plot { display: flex; align-items: flex-end; justify-content: center; }
  .chart .pct .fill { width: 72%; }
  .chart .seg { position: absolute; display: block; width: 34%; border-radius: 3px; }
  .chart .seg.ma { left: 8%;  background: var(--accent-400); }
  .chart .seg.mb { left: 47%; background: var(--brand); }
  .chart .zero { position: absolute; left: 0; right: 0; height: 1px; background: var(--border-default); }
  .chart .pct { position: relative; }
  .chart .cent { position: absolute; left: 0; right: 0; height: 0; border-top: 1px dashed var(--text-muted); }
  .chart .cent span { position: absolute; right: 0; top: -14px; font-family: var(--font-mono);
                      font-size: 9.5px; color: var(--text-muted); }
  .chart .fill.ok   { background: var(--success); }
  .chart .fill.warn { background: var(--warning); }
  .chart .fill.bad  { background: var(--danger); }
  .legend { display: flex; gap: 14px; flex-wrap: wrap; font-size: 12px; color: var(--text-muted);
            margin: 10px 0 0; }
  .legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px;
              margin-right: 5px; vertical-align: -1px; }
  .fc { display: grid; grid-template-columns: 1fr; gap: 12px; }
  @media (min-width: 620px) { .fc { grid-template-columns: repeat(3, 1fr); } }
  .fc .col { border: 1px solid var(--border-subtle); border-radius: 12px; padding: 12px 14px; }
  .fc .col.now { border-color: var(--brand); box-shadow: 0 0 0 1px var(--brand); }
  .fc .col h4 { margin: 0 0 8px; font-family: var(--font-mono); font-size: 11px;
                text-transform: uppercase; letter-spacing: .1em; color: var(--text-muted); }
  .fc .col b { display: block; font-family: var(--font-display); font-size: 21px; color: var(--text-strong); }
  .fc .col dl { margin: 8px 0 0; display: grid; grid-template-columns: 1fr auto; gap: 3px 10px; font-size: 12.5px; }
  .fc .col dt { color: var(--text-muted); margin: 0; }
  .fc .col dd { margin: 0; font-family: var(--font-mono); }
  /* Valorisation : deux colonnes, et sous chacune le calcul en toutes lettres.
     Un chiffre de valorisation sans sa formule sous les yeux finit recopié
     dans un business plan sans que personne ne sache d'où il sort. */
  .wyc { display: grid; grid-template-columns: 1fr; gap: 14px; }
  @media (min-width: 700px) { .wyc { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  .wyc .box { border: 1px solid var(--border-subtle); border-radius: 12px; padding: 14px 16px; min-width: 0; }
  .wyc .box.cel { border-color: var(--accent); }
  .wyc .box h4 { margin: 0 0 6px; font-family: var(--font-mono); font-size: 11px;
                 text-transform: uppercase; letter-spacing: .1em; color: var(--text-muted); }
  /* `> b` et non ` b` : le montant est un enfant direct du cadre. Sans le
     chevron, chaque mot en gras des explications héritait de ces 26 px en
     display:block et découpait la phrase en une pile de titres. */
  .wyc .box > b { display: block; font-family: var(--font-display); font-size: 26px;
                  color: var(--text-strong); line-height: 1.15; }
  /* La formule peut être longue : elle défile dans son cadre plutôt que de
     pousser la page — même raison que les tableaux larges ailleurs. */
  .wyc .calc { margin: 10px 0 0; font-family: var(--font-mono); font-size: 12px;
               color: var(--text-muted); background: var(--surface-sunken);
               border-radius: 8px; padding: 8px 10px; overflow-x: auto; white-space: nowrap; }
  .wyc .note { margin: 8px 0 0; font-size: 12.5px; color: var(--text-muted); line-height: 1.55; }
  .tag { font-size: 12px; padding: 2px 9px; border-radius: 999px;
         border: 1px solid var(--warning); color: var(--warning); white-space: nowrap; }
  .trust { font-size: 12px; padding: 2px 9px; border-radius: 999px; border: 1px solid var(--border-default); }
  .trust.niska  { color: var(--danger);  border-color: var(--danger); }
  .trust.srednia{ color: var(--warning); border-color: var(--warning); }
  .trust.wysoka { color: var(--success); border-color: var(--success); }
CSS);
console_crumbs(['Pulpit' => 'pulpit.php', 'Audyt' => null]);
?>

<div class="kpis">
  <div class="kpi"><b><?= $clientsTotal ?></b><span>Klienci</span></div>
  <div class="kpi"><b><?= $repeat ?></b><span>Wracający</span></div>
  <div class="kpi"><b><?= (int) $kpis['orders'] ?></b><span>Zamówienia</span></div>
  <div class="kpi"><b><?= h(pln((int) $kpis['revenue_gross'])) ?></b><span>Obrót brutto</span></div>
  <div class="kpi"><b><?= h(pln((int) $kpis['basket_avg'])) ?></b><span>Średni koszyk</span></div>
</div>

<?php
// Sans prix de revient, la marge vaut zéro par construction — et « wynik po
// dostawie » devient un négatif spectaculaire qui ne reflète aucune perte.
// Afficher ce chiffre comme un résultat serait mentir par omission : tant que
// la couverture est faible, on montre un tiret et on explique.
$fiable = $mtot['cost_known_pct'] >= 50.0;
?>
<div class="kpis">
  <div class="kpi"><b><?= $fiable ? h(pln((int) $mtot['margin'])) : '—' ?></b><span>Marża brutto — 12 mies.</span></div>
  <div class="kpi"><b><?= $fiable ? h(number_format((float) $mtot['margin_pct'], 1, ',', ' ')) . ' %' : '—' ?></b><span>Marża / sprzedaż netto</span></div>
  <div class="kpi<?= $mtot['ship_gap'] > 0 ? ' hot' : '' ?>"><b><?= h(pln((int) $mtot['ship_gap'])) ?></b><span>Dopłata do dostawy</span></div>
  <div class="kpi"><b><?= h(number_format((float) $mtot['coverage_pct'], 1, ',', ' ')) ?> %</b><span>Koszt dostawy pokryty</span></div>
  <div class="kpi"><b><?= $fiable ? h(pln((int) $mtot['result'])) : '—' ?></b><span>Wynik po dostawie</span></div>
</div>

<?php if ($mtot['cost_known_pct'] < 99.5): ?>
<p class="warnbox">
  Marżę policzono na <b><?= h(number_format((float) $mtot['cost_known_pct'], 1, ',', ' ')) ?> %</b>
  sprzedaży — reszta to pozycje bez ceny zakupu. Produkt bez kosztu własnego nie jest liczony
  jako marża 100 %, bo wtedy jako jedyny „zarabiałby” i zawyżał cały wykres.
  <?php if (!$fiable): ?>
  Poniżej połowy sprzedaży wynik nie znaczy nic sensownego, więc zamiast liczby pokazujemy
  <b>—</b>: ujemny „wynik po dostawie” byłby tu artefaktem braku danych, a nie stratą.
  <?php endif; ?>
  Uzupełnij <b>koszt własny</b> w <a href="produkty.php">Produktach</a> — liczby pojawią się same,
  a nowe zamówienia zapamiętają koszt z chwili sprzedaży.
</p>
<?php endif; ?>

<?php
// Le taux affiché : en pourcentage, avec une décimale, comme partout ailleurs.
$pc = fn(float $r) => number_format($r * 100, 1, ',', ' ') . ' %';
// Le multiple s'écrit « 4,5 » et non « 4.5 » : la console est polonaise.
$mult = number_format((float) $wyc['multiple'], 1, ',', ' ');
?>
<div class="panel">
  <h2>Wycena firmy
    <?php if ($wyc['theoretical']): ?><span class="tag">teoretyczna — 15 %</span><?php endif; ?>
  </h2>
  <p class="why">
    Wycena to obrót roczny pomnożony przez marżę netto i przez mnożnik <b><?= h($mult) ?></b>
    — umowny dla małego, rentownego sklepu internetowego. Jest tu po to, żeby o niej
    dyskutować, nie żeby w nią wierzyć. Obrót roczny to sprzedaż netto z ostatnich
    dwunastu miesięcy: <b><?= h(pln((int) $wyc['revenue'])) ?></b>.
  </p>

  <div class="wyc">
    <div class="box cel">
      <h4>Wycena docelowa</h4>
      <b><?= h(pln((int) $wyc['target_value'])) ?></b>
      <p class="calc">CA roczny <?= h(pln((int) $wyc['revenue'])) ?>
        × <?= h($pc((float) $wyc['target_rate'])) ?> × <?= h($mult) ?></p>
      <p class="note">Ile firma byłaby warta, gdyby osiągała założoną marżę netto
        <b><?= h($pc((float) $wyc['target_rate'])) ?></b>. To cel planu, nie pomiar.</p>
    </div>

    <div class="box">
      <h4>Wycena aktualna</h4>
      <b><?= h(pln((int) $wyc['actual_value'])) ?></b>
      <p class="calc">CA roczny <?= h(pln((int) $wyc['revenue'])) ?>
        × <?= h($pc((float) $wyc['actual_rate'])) ?> × <?= h($mult) ?></p>
      <p class="note">
        <?php if ($wyc['theoretical']): ?>
          Średniej marży netto <b>nie da się dziś zmierzyć</b>: koszt własny znamy tylko na
          <b><?= h(number_format((float) $wyc['coverage'], 1, ',', ' ')) ?> %</b> sprzedaży,
          a średnia z tak wąskiej próbki nie jest średnią. Zamiast liczyć ją z niczego
          przyjmujemy stawkę docelową <b>15 %</b> — dlatego obie kwoty są tu równe,
          i dlatego ta jest oznaczona jako <b>teoretyczna</b>.
        <?php else: ?>
          Ta sama formuła, ale ze <b>zmierzoną</b> średnią marżą netto z dwunastu miesięcy:
          wynik po koszcie towaru i po niepokrytej części dostawy, podzielony przez sprzedaż,
          na której dało się go policzyć (<b><?= h(number_format((float) $wyc['coverage'], 1, ',', ' ')) ?> %</b>
          obrotu). To ten sam wynik co w kafelku „Wynik po dostawie” — nie inny sposób liczenia.
        <?php endif; ?>
      </p>
    </div>
  </div>

  <?php if ($wyc['theoretical']): ?>
  <p class="why" style="margin:14px 0 0">
    Żeby wycena aktualna zaczęła znaczyć cokolwiek, wystarczy uzupełnić
    <b>koszt własny</b> w <a href="produkty.php">Produktach</a>. Powyżej połowy sprzedaży
    liczba policzy się sama, znacznik „teoretyczna” zniknie, a obie kwoty się rozejdą —
    i wtedy różnica między nimi będzie tym, co realnie zostało do odrobienia.
  </p>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Prognoza — <?= h($prev['label_curr']) ?> wobec <?= h($prev['label_prev']) ?>
    <span class="trust <?= $prev['trust'] === 'średnia' ? 'srednia' : h($prev['trust']) ?>">wiarygodność: <?= h($prev['trust']) ?></span></h2>
  <p class="why">
    Metoda jest prosta na tyle, żeby dała się sprawdzić w pamięci: to, co już wpłynęło,
    podzielone przez <b><?= (int) $prev['elapsed'] ?></b> dni, pomnożone przez <b><?= (int) $prev['days'] ?></b>.
    Zakłada, że reszta miesiąca będzie podobna do początku — na początku miesiąca to założenie
    jest słabe i dlatego wiarygodność jest wtedy <b>niska</b>. To ekstrapolacja, nie obietnica.
  </p>
  <div class="fc">
    <div class="col">
      <h4>Miesiąc poprzedni (<?= h($prev['label_prev']) ?>)</h4>
      <b><?= h(pln((int) $prev['prev']['result'])) ?></b>
      <dl>
        <dt>Sprzedaż netto</dt><dd><?= h(pln((int) $prev['prev']['revenue'])) ?></dd>
        <dt>Marża brutto</dt><dd><?= h(pln((int) $prev['prev']['margin'])) ?></dd>
        <dt>Zamówienia</dt><dd><?= (int) $prev['prev']['orders'] ?></dd>
      </dl>
    </div>
    <div class="col now">
      <h4>Bieżący — już zrealizowane</h4>
      <b><?= h(pln((int) $prev['curr']['result'])) ?></b>
      <dl>
        <dt>Sprzedaż netto</dt><dd><?= h(pln((int) $prev['curr']['revenue'])) ?></dd>
        <dt>Marża brutto</dt><dd><?= h(pln((int) $prev['curr']['margin'])) ?></dd>
        <dt>Zamówienia</dt><dd><?= (int) $prev['curr']['orders'] ?></dd>
      </dl>
    </div>
    <div class="col">
      <h4>Bieżący — prognoza na koniec</h4>
      <b><?= h(pln((int) $prev['forecast']['result'])) ?></b>
      <dl>
        <dt>Sprzedaż netto</dt><dd><?= h(pln((int) $prev['forecast']['revenue'])) ?></dd>
        <dt>Marża brutto</dt><dd><?= h(pln((int) $prev['forecast']['margin'])) ?></dd>
        <dt>Zamówienia</dt><dd>~<?= (int) $prev['forecast']['orders'] ?></dd>
      </dl>
    </div>
  </div>
  <?= graph_paire([
        ['label' => $prev['label_prev'] . ' (fakt)', 'a' => (int) $prev['prev']['margin'], 'b' => (int) $prev['prev']['result']],
        ['label' => $prev['label_curr'] . ' (dziś)', 'a' => (int) $prev['curr']['margin'], 'b' => (int) $prev['curr']['result']],
        ['label' => $prev['label_curr'] . ' (progn.)', 'a' => (int) $prev['forecast']['margin'], 'b' => (int) $prev['forecast']['result']],
      ], fn($v) => pln((int) $v)) ?>
  <p class="legend">
    <span><i style="background:var(--accent-400)"></i>Marża brutto</span>
    <span><i style="background:var(--brand)"></i>Wynik po kosztach dostawy</span>
  </p>
  <?php if ($prev['prev']['result'] != 0): ?>
  <p class="why" style="margin-top:10px">
    Prognoza jest o <b><?= h(number_format(abs((float) $prev['delta_pct']), 1, ',', ' ')) ?> %</b>
    <?= $prev['delta_pct'] >= 0 ? 'wyższa' : 'niższa' ?> od wyniku miesiąca poprzedniego.
  </p>
  <?php endif; ?>
</div>

<div class="charts">
  <div class="panel<?= chart_wide(count($marge)) ?>">
    <h2>Marża po kosztach dostawy — 12 miesięcy</h2>
    <p class="why">Jasny słupek to <b>marża brutto</b>: sprzedaż netto minus koszt własny towaru,
      zamrożony w chwili sprzedaży. Ciemny to ta sama marża <b>po dopłacie do przesyłek</b>.
      Odstęp między nimi to dokładnie tyle, ile kosztuje wysyłanie paczek ponad to, co płaci klient.</p>
    <?= graph_paire(array_map(fn($m) => ['label' => $m['label'], 'a' => $m['margin'], 'b' => $m['result']], $marge),
                    fn($v) => pln((int) $v)) ?>
    <p class="legend">
      <span><i style="background:var(--accent-400)"></i>Marża brutto</span>
      <span><i style="background:var(--brand)"></i>Po kosztach dostawy</span>
    </p>
  </div>

  <div class="panel<?= chart_wide(count($marge)) ?>">
    <h2>Koszt dostawy pokryty przez klienta — 12 miesięcy</h2>
    <p class="why">Ile procent rachunku od przewoźnika płaci klient. <b>100 %</b> znaczy, że wysyłka
      jest neutralna. Poniżej — każda paczka zjada marżę, a próg darmowej dostawy jest za nisko.
      <?php $unknown = array_filter(wsm_ship_costs($pdo), fn($s) => !$s['known']);
      if ($unknown): ?>
      <br><b>Uwaga:</b> dla <?= count($unknown) ?> metod nie wpisano rzeczywistego kosztu przewoźnika,
      więc za koszt przyjęto cenę sprzedaży — wykres pokaże wtedy 100 % z definicji.
      Wpisz koszt w <a href="tresci.php?site=sklep&amp;zakladka=dostawa">Treściach → Cennik dostawy</a>.
      <?php endif; ?></p>
    <?= graph_pourcent(array_map(fn($m) => ['label' => $m['label'], 'v' => $m['coverage_pct'],
                                            'has' => $m['ship_cost'] > 0], $marge)) ?>
  </div>
</div>

<div class="charts">
  <div class="panel<?= chart_wide(count($mois)) ?>">
    <h2>Obrót — 12 miesięcy</h2>
    <p class="why">Liczy się tylko to, co <b>zapłacone</b>. Zamówienie oczekujące na płatność
      nie jest obrotem — dopóki pieniądze nie wpłynęły, to obietnica.</p>
    <?= graph_bars(array_map(fn($m) => ['label' => $m['label'], 'v' => $m['gross']], $mois),
                   fn($v) => pln((int) $v)) ?>
  </div>

  <div class="panel<?= chart_wide(count($mois)) ?>">
    <h2>Zamówienia — 12 miesięcy</h2>
    <p class="why">Wszystkie zamówienia poza anulowanymi, także te czekające na płatność:
      tu mierzymy zainteresowanie, nie kasę.</p>
    <?= graph_bars(array_map(fn($m) => ['label' => $m['label'], 'v' => $m['orders']], $mois),
                   fn($v) => (int) $v . ' szt.', 'var(--accent-400)') ?>
  </div>

  <div class="panel<?= chart_wide(count($clients)) ?>">
    <h2>Klienci — narastająco</h2>
    <p class="why">Każdy adres liczony raz, w miesiącu pierwszego zamówienia. Krzywa, która
      się wypłaszcza, znaczy, że nowi klienci przestali przychodzić — nawet jeśli obrót rośnie.</p>
    <?= graph_line(array_map(fn($c) => ['label' => $c['label'], 'v' => $c['n']], $clients),
                   fn($v) => (int) $v) ?>
  </div>

  <div class="panel<?= chart_wide(count($jours)) ?>">
    <h2>Zamówienia dzień po dniu — 30 dni</h2>
    <p class="why">Rytm bieżący. Dziura w tym wykresie to dzień, w którym nikt nie kupił.</p>
    <?= graph_bars(array_map(fn($d) => ['label' => $d['label'], 'v' => $d['n']], $jours),
                   fn($v) => (int) $v . ' szt.', 'var(--brand-500)') ?>
  </div>
</div>

<div class="panel">
  <h2>Najczęściej kupowane</h2>
  <?php if (!$tops): ?>
  <p class="muted small">Brak sprzedaży.</p>
  <?php else: $maxTop = max(array_map(fn($t) => (int) $t['n'], $tops)); ?>
  <div class="rank">
    <?php foreach ($tops as $t): ?>
    <div class="row"><span><?= h((string) $t['name']) ?></span>
      <span class="mono"><?= (int) $t['n'] ?> szt. · <?= h(pln((int) $t['v'])) ?></span>
      <span class="track"><i style="width:<?= max(2, round((int) $t['n'] / max(1, $maxTop) * 100)) ?>%"></i></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Dziennik audytu</h2>
  <p class="why">Kto, co i kiedy. Każda zmiana danych w konsoli zostawia tu ślad —
    to jedyne miejsce, gdzie widać, kto zmienił cenę albo wyłączył konto.</p>
  <div class="tablewrap">
  <table class="rwd">
    <thead><tr><th>Kiedy</th><th>Kto</th><th>Co</th><th>Czego dotyczy</th><th>Zakres</th></tr></thead>
    <tbody>
    <?php if (!$audit): ?><tr><td class="muted">Dziennik jest pusty.</td></tr><?php endif; ?>
    <?php foreach ($audit as $a): ?>
    <tr>
      <td data-l="Kiedy" class="num"><?= h((string) $a['ts']) ?></td>
      <td data-l="Kto"><?= h((string) $a['user']) ?></td>
      <td data-l="Co"><span class="tag"><?= h((string) $a['verb']) ?></span></td>
      <td data-l="Czego dotyczy" class="wide"><?= h((string) $a['entity']) ?></td>
      <td data-l="Zakres"><?= h((string) $a['shop']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php console_foot();
