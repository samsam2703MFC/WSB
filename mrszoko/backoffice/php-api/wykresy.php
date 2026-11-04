<?php
// ============================================================================
//  wykresy.php — de petits graphiques rendus côté serveur, sans bibliothèque.
//
//  Les traits vivent dans un SVG étiré sur la zone du graphique (2 px qui ne
//  s'épaississent pas, vector-effect) ; le texte, les points et les zones de
//  survol sont du HTML placé en pourcentages — le texte garde sa taille sur
//  un téléphone comme sur un écran large, et un point reste rond.
//
//  Règles tenues : un seul axe ; traits de 2 px ; colonnes ≤ 24 px, bout
//  arrondi de 4 px, carrées à la base, 2 px d'air entre voisines ; grille en
//  filets pleins, discrète ; une légende dès deux séries ; le texte ne prend
//  jamais la couleur de la série ; chaque valeur est aussi dans un tableau
//  (le graphique n'est jamais le seul chemin) ; survol ET clavier.
//
//  Couleurs : bleu = entrées / série 1, orange = sorties / série 2 (palette
//  validée : écart daltonien ΔE 24,7, contraste ≥ 3:1 sur la surface claire).
// ============================================================================
declare(strict_types=1);

const WSM_VIZ_MOIS = ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];

function wsm_viz_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** Un maximum « rond » et son pas : 0 · 5 · 10 · 15, jamais 0 · 3,7 · 7,4. */
function wsm_viz_nice(float $max, int $ticks = 4): array {
    if ($max <= 0) return [1.0, 1.0];
    $brut = $max / $ticks;
    $p = 10 ** floor(log10($brut));
    foreach ([1, 2, 2.5, 5, 10] as $m) if ($m * $p >= $brut) { $pas = $m * $p; break; }
    $pas = max($pas ?? $p * 10, 1e-9);
    return [ceil($max / $pas) * $pas, $pas];
}

/** Le nom court d'un mois : « lis », et l'année quand elle change (« sty 27 »). */
function wsm_viz_mois(string $ym, bool $annee = false): string {
    $m = (int) substr($ym, 5, 2);
    return (WSM_VIZ_MOIS[$m - 1] ?? $ym) . ($annee ? ' ' . substr($ym, 2, 2) : '');
}

/** La grille horizontale et ses étiquettes (HTML, à gauche). */
function wsm_viz_grille(float $max, float $pas, callable $fmt): string {
    $o = '';
    for ($v = 0.0; $v <= $max + 1e-9; $v += $pas) {
        $top = 100 - ($v / $max) * 100;
        $o .= '<span class="viz-gl" style="top:' . round($top, 3) . '%"><i>' . wsm_viz_h($fmt($v)) . '</i></span>';
    }
    return $o;
}

/** Les étiquettes de l'axe du temps : le début de chaque mois dans la fenêtre. */
function wsm_viz_axe_temps(int $de, int $a): string {
    $o = ''; $span = max(1, $a - $de);
    $mois = (int) round($span / (30 * 86400));
    $saut = $mois > 18 ? 3 : ($mois > 9 ? 2 : 1);
    $t = strtotime(date('Y-m-01', $de)); $i = 0;
    if ($t < $de) $t = strtotime('+1 month', $t);
    while ($t <= $a) {
        if ($i++ % $saut === 0) {
            $x = ($t - $de) / $span * 100;
            if ($x <= 96) $o .= '<span style="left:' . round($x, 3) . '%">' . wsm_viz_h(wsm_viz_mois(date('Y-m', $t), date('m', $t) === '01')) . '</span>';
        }
        $t = strtotime('+1 month', $t);
    }
    return $o;
}

/**
 * Une ligne dans le temps.
 * @param list<array{t:string, v:float|int, tip:string}> $serie  dans l'ordre du temps
 * @param array $o  de, a (timestamps), etape (bool : ligne en escalier, pour un stock),
 *                  points (bool), aire (bool), couleur (1|2), fmt (callable), aria, fin (étiquette du dernier point)
 */
function wsm_viz_ligne(array $serie, array $o): string {
    $de = (int) $o['de']; $a = max($de + 1, (int) $o['a']);
    $fmt = $o['fmt'] ?? fn($v) => (string) $v;
    $c = (int) ($o['couleur'] ?? 1);
    if (!$serie) return '<p class="viz-vide">' . wsm_viz_h((string) ($o['vide'] ?? 'Brak danych w tym okresie.')) . '</p>';
    $vmax = max(array_map(fn($p) => (float) $p['v'], $serie));
    [$max, $pas] = wsm_viz_nice($vmax > 0 ? $vmax : 1);
    $X = fn(string $t) => max(0.0, min(1000.0, (strtotime($t) - $de) / ($a - $de) * 1000));
    $Y = fn(float $v) => 200 - ($v / $max) * 200;

    $d = ''; $pts = [];
    foreach ($serie as $i => $p) {
        $x = $X((string) $p['t']); $y = $Y((float) $p['v']);
        $pts[] = [$x, $y, (string) ($p['tip'] ?? '')];
        if ($i === 0) $d = 'M' . round($x, 2) . ' ' . round($y, 2);
        elseif (!empty($o['etape'])) $d .= ' H' . round($x, 2) . ' V' . round($y, 2);
        else $d .= ' L' . round($x, 2) . ' ' . round($y, 2);
    }
    $aire = !empty($o['aire']) ? '<path class="viz-aire c' . $c . '" d="' . $d . ' V200 H' . round($pts[0][0], 2) . ' Z"/>' : '';

    // Les zones de survol : une bande par point, de mi-chemin à mi-chemin.
    $zones = ''; $n = count($pts);
    foreach ($pts as $i => [$x, $y, $tip]) {
        $g = $i === 0 ? 0 : ($pts[$i - 1][0] + $x) / 2;
        $dr = $i === $n - 1 ? 1000 : ($x + $pts[$i + 1][0]) / 2;
        if ($tip === '') continue;
        $zones .= '<span class="viz-hit" tabindex="0" style="left:' . round($g / 10, 3) . '%;width:' . round(max(0.6, ($dr - $g) / 10), 3) . '%"'
                . ' data-x="' . round($x / 10, 3) . '" data-y="' . round($y / 2, 3) . '" data-tip="' . wsm_viz_h($tip) . '" aria-label="' . wsm_viz_h(str_replace("\n", ', ', $tip)) . '"></span>';
    }
    $points = '';
    if (!empty($o['points'])) foreach ($pts as [$x, $y]) $points .= '<span class="viz-dot c' . $c . '" style="left:' . round($x / 10, 3) . '%;top:' . round($y / 2, 3) . '%"></span>';
    [$xf, $yf] = end($pts);
    $fin = (string) ($o['fin'] ?? '');
    return '<figure class="viz" role="group" aria-label="' . wsm_viz_h((string) ($o['aria'] ?? '')) . '">'
         . '<div class="viz-plot">' . wsm_viz_grille($max, $pas, $fmt)
         . '<svg viewBox="0 0 1000 200" preserveAspectRatio="none" aria-hidden="true">' . $aire
         . '<path class="viz-trait c' . $c . '" d="' . $d . '"/></svg>'
         . $points . '<span class="viz-dot c' . $c . ' fin" style="left:' . round($xf / 10, 3) . '%;top:' . round($yf / 2, 3) . '%"></span>'
         . ($fin !== '' ? '<span class="viz-fin" style="top:' . round($yf / 2, 3) . '%">' . wsm_viz_h($fin) . '</span>' : '')
         . '<span class="viz-croix" hidden></span>' . $zones . '<span class="viz-tip" hidden></span></div>'
         . '<div class="viz-x">' . wsm_viz_axe_temps($de, $a) . '</div></figure>';
}

/**
 * Des colonnes groupées : un groupe par période, une colonne par série.
 * @param list<array{label:string, tip:string, valeurs:list<int>}> $groupes
 * @param list<array{nom:string, couleur:int}> $series
 */
function wsm_viz_colonnes(array $groupes, array $series, array $o = []): string {
    $fmt = $o['fmt'] ?? fn($v) => (string) $v;
    $vmax = 0;
    foreach ($groupes as $g) foreach ($g['valeurs'] as $v) $vmax = max($vmax, (float) $v);
    if ($vmax <= 0) return '<p class="viz-vide">' . wsm_viz_h((string) ($o['vide'] ?? 'Brak ruchów w tym okresie.')) . '</p>';
    [$max, $pas] = wsm_viz_nice($vmax);
    $leg = '<div class="viz-leg">';
    foreach ($series as $s) $leg .= '<span><i class="c' . (int) $s['couleur'] . '"></i>' . wsm_viz_h($s['nom']) . '</span>';
    $leg .= '</div>';
    $cols = ''; $x = '';
    $n = count($groupes);
    foreach ($groupes as $i => $g) {
        $barres = '';
        foreach ($g['valeurs'] as $k => $v) {
            $h = $v > 0 ? max(1.5, $v / $max * 100) : 0;
            $barres .= '<span class="viz-col c' . (int) ($series[$k]['couleur'] ?? 1) . '" style="height:' . round($h, 3) . '%"></span>';
        }
        $cols .= '<span class="viz-grp viz-hit" tabindex="0" data-tip="' . wsm_viz_h($g['tip']) . '" aria-label="' . wsm_viz_h(str_replace("\n", ', ', $g['tip'])) . '">' . $barres . '</span>';
        $x .= '<span' . ($n > 12 && $i % 2 ? ' class="viz-skip"' : '') . '>' . wsm_viz_h($g['label']) . '</span>';
    }
    return '<figure class="viz" role="group" aria-label="' . wsm_viz_h((string) ($o['aria'] ?? '')) . '">' . $leg
         . '<div class="viz-plot">' . wsm_viz_grille($max, $pas, $fmt)
         . '<div class="viz-cols">' . $cols . '</div><span class="viz-tip" hidden></span></div>'
         . '<div class="viz-x viz-x-cols">' . $x . '</div></figure>';
}

/** Le style des graphiques, une fois par page. */
function wsm_viz_css(): string {
    return <<<'CSS'
  .viz { margin: 0; --s1: #2a78d6; --s2: #eb6834; --grid: #ece7e0; }
  .viz-plot { position: relative; height: 190px; margin: 8px 44px 0 46px; }
  .viz-plot svg { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; }
  .viz-gl { position: absolute; left: 0; right: 0; height: 0; border-top: 1px solid var(--grid); }
  .viz-gl i { position: absolute; right: calc(100% + 8px); top: -8px; font-style: normal; font-size: 11px;
              color: var(--text-muted); font-variant-numeric: tabular-nums; white-space: nowrap; }
  .viz-trait { fill: none; stroke-width: 2; vector-effect: non-scaling-stroke; stroke-linejoin: round; stroke-linecap: round; }
  .viz-trait.c1 { stroke: var(--s1); } .viz-trait.c2 { stroke: var(--s2); }
  .viz-aire.c1 { fill: var(--s1); opacity: .10; } .viz-aire.c2 { fill: var(--s2); opacity: .10; }
  .viz-dot { position: absolute; width: 8px; height: 8px; margin: -4px 0 0 -4px; border-radius: 50%;
             box-shadow: 0 0 0 2px #fff; pointer-events: none; }
  .viz-dot.c1 { background: var(--s1); } .viz-dot.c2 { background: var(--s2); }
  .viz-fin { position: absolute; left: calc(100% + 8px); transform: translateY(-50%); font-size: 12px;
             font-weight: 600; color: var(--text-strong); white-space: nowrap; }
  .viz-x { position: relative; height: 20px; margin: 6px 44px 0 46px; font-size: 11px; color: var(--text-muted); }
  .viz-x span { position: absolute; top: 0; transform: translateX(-50%); white-space: nowrap; }
  .viz-x-cols { display: flex; position: static; }
  .viz-x-cols span { position: static; transform: none; flex: 1 1 0; text-align: center; min-width: 0; }
  .viz-x-cols .viz-skip { visibility: hidden; }
  .viz-cols { position: absolute; inset: 0; display: flex; align-items: stretch; }
  .viz-grp { flex: 1 1 0; display: flex; align-items: flex-end; justify-content: center; gap: 2px; min-width: 0; outline-offset: 2px; }
  .viz-col { width: min(24px, 34%); border-radius: 4px 4px 0 0; }
  .viz-col.c1 { background: var(--s1); } .viz-col.c2 { background: var(--s2); }
  .viz-grp:hover .viz-col, .viz-grp:focus-visible .viz-col { filter: brightness(1.12); }
  .viz-hit { position: absolute; top: 0; bottom: 0; cursor: crosshair; }
  .viz-grp.viz-hit { position: relative; cursor: default; }
  .viz-hit:focus-visible { outline: 2px solid var(--text-strong); }
  .viz-croix { position: absolute; top: 0; bottom: 0; width: 0; border-left: 1px solid var(--text-muted); pointer-events: none; }
  .viz-tip { position: absolute; z-index: 5; min-width: 120px; max-width: 240px; padding: 8px 10px; border-radius: 10px;
             background: #fff; box-shadow: 0 6px 24px rgba(40, 25, 15, .18); font-size: 12px; line-height: 1.45;
             color: var(--text-muted); pointer-events: none; white-space: pre-line; }
  .viz-tip b { display: block; font-size: 14px; color: var(--text-strong); }
  .viz-leg { display: flex; gap: 16px; flex-wrap: wrap; font-size: 12.5px; color: var(--text-strong); margin: 0 0 4px 46px; }
  .viz-leg i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 6px; vertical-align: -1px; }
  .viz-leg i.c1 { background: var(--s1); } .viz-leg i.c2 { background: var(--s2); }
  .viz-vide { color: var(--text-muted); font-size: 13px; margin: 8px 0; }
  @media (max-width: 560px) { .viz-plot, .viz-x { margin-left: 40px; margin-right: 8px; } .viz-fin { display: none; }
                              .viz-x-cols span:nth-child(even) { visibility: hidden; } }
CSS;
}

/**
 * Le survol : une infobulle (première ligne = la valeur, en gras) et, sur
 * une ligne, un réticule qui suit la date. Même chose au clavier (focus).
 * Le texte passe par textContent : les noms de fournisseurs viennent des factures.
 */
function wsm_viz_js(): string {
    return <<<'JS'
(function () {
  function montre(hit) {
    var plot = hit.closest('.viz-plot'), tip = plot.querySelector('.viz-tip'), croix = plot.querySelector('.viz-croix');
    var lignes = (hit.dataset.tip || '').split('\n');
    tip.textContent = '';
    var b = document.createElement('b'); b.textContent = lignes.shift(); tip.appendChild(b);
    if (lignes.length) tip.appendChild(document.createTextNode(lignes.join('\n')));
    tip.hidden = false;
    var pr = plot.getBoundingClientRect(), hr = hit.getBoundingClientRect();
    var x = hit.dataset.x !== undefined ? pr.width * parseFloat(hit.dataset.x) / 100 : (hr.left - pr.left) + hr.width / 2;
    if (croix && hit.dataset.x !== undefined) { croix.hidden = false; croix.style.left = x + 'px'; }
    var tw = tip.offsetWidth, left = x + 12;
    if (left + tw > pr.width) left = Math.max(0, x - tw - 12);
    tip.style.left = left + 'px';
    tip.style.top = (hit.dataset.y !== undefined ? Math.max(0, pr.height * parseFloat(hit.dataset.y) / 100 - 30) : 8) + 'px';
  }
  function cache(hit) {
    var plot = hit.closest('.viz-plot');
    plot.querySelector('.viz-tip').hidden = true;
    var c = plot.querySelector('.viz-croix'); if (c) c.hidden = true;
  }
  document.querySelectorAll('.viz .viz-hit').forEach(function (h) {
    h.addEventListener('pointerenter', function () { montre(h); });
    h.addEventListener('focus', function () { montre(h); });
    h.addEventListener('pointerleave', function () { cache(h); });
    h.addEventListener('blur', function () { cache(h); });
  });
})();
JS;
}
