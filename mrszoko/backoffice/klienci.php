<?php
// ============================================================================
//  klienci.php — les gens qui achètent, enfin visibles.
//
//  Jusqu'ici la console montrait six « kontrahenci » saisis à la main pour la
//  TVA intracommunautaire, et pas une seule des personnes qui ont réellement
//  passé commande. Leurs achats existaient, elles non.
//
//  Deux écrans en un :
//   • LA LISTE répond à « qui sont mes clients » : chiffre d'affaires,
//     nombre d'achats, dernière fois, et des badges qui se calculent au
//     moment de l'affichage — un « VIP » rangé en base reste VIP trois ans
//     après sa dernière commande.
//   • LA FICHE répond à « que s'est-il passé avec celui-là » : ses commandes,
//     ses factures, son courrier, ce qu'il achète, et les notes qu'on a
//     prises. Tout ce qu'il faut avant de décrocher le téléphone.
//
//  Lecture : tout compte actif. Écriture (notes) : Centrala.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/crm.php';
require_once $API . '/i18n.php';
require_once $API . '/b2b.php';

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
        $flash = 'Tylko rola Centrala może pisać notatki.'; $kind = 'err';
    } elseif (isset($_POST['notatka'])) {
        [$id, $err] = wsm_crm_note_add($pdo, (string) ($_POST['email'] ?? ''),
                                       (string) $_POST['notatka'], (string) ($me['nom'] ?? ''));
        if ($id) {
            $flash = 'Zapisano notatkę.';
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Notatka o kliencie',
                      (string) ($_POST['email'] ?? ''), 'Sieć');
        } else { $flash = $err; $kind = 'err'; }
    } elseif (isset($_POST['otworz_b2b'])) {
        [$okB, $msgB] = wsm_b2b_activer($pdo, (string) $_POST['otworz_b2b'], (string) ($me['nom'] ?? ''));
        $flash = $msgB; $kind = $okB ? 'ok' : 'err';
    } elseif (isset($_POST['zamknij_b2b'])) {
        [$okB, $msgB] = wsm_b2b_fermer($pdo, (string) $_POST['zamknij_b2b'], (string) ($me['nom'] ?? ''));
        $flash = $msgB; $kind = $okB ? 'ok' : 'err';
    } elseif (isset($_POST['skan_b2b'])) {
        $r = wsm_b2b_scan($pdo, (string) ($me['nom'] ?? ''));
        $flash = $r['ouverts'] > 0
            ? 'Otwarto ' . $r['ouverts'] . ' kont firmowych.'
            : 'Nikt nowy nie przekroczył progu.';
    } elseif (isset($_POST['usun_notatke'])) {
        wsm_crm_note_delete($pdo, (int) $_POST['usun_notatke']);
        $flash = 'Usunięto notatkę.';
    }
}

$liste = wsm_crm_list($pdo);
$seuil = wsm_crm_seuil_vip($liste);
$tot   = wsm_crm_totaux($liste);

/** Des grammes en kilos lisibles : « 12,5 kg », « 12 kg », « 0 kg ». */
function kg_pl(int $g): string {
    $kg = $g / 1000;
    return number_format($kg, ($g % 1000) === 0 ? 0 : 1, ',', "\u{202F}") . "\u{202F}kg";
}

$q    = trim((string) ($_GET['q'] ?? ''));
$seg  = (string) ($_GET['seg'] ?? '');
$mail = strtolower(trim((string) ($_GET['email'] ?? '')));
$fiche = $mail !== '' ? wsm_crm_client($pdo, $mail) : null;

$vus = wsm_crm_filtre($liste, $q, $seg, $seuil);
$onglet = in_array($_GET['widok'] ?? '', ['analiza', 'b2b'], true) ? (string) $_GET['widok'] : 'lista';

/** Les segments proposés, avec leur compte réel — un filtre vide se voit. */
$segments = ['staly' => 'Stali', 'vip' => 'Wysoki obrót', 'spiacy' => 'Śpiący',
             'b2b' => 'Firmy', 'nieoplacone' => 'Z nieopłaconymi',
             'pierwszy' => 'Po pierwszym zakupie', 'nowy' => 'Bez zakupu'];
$compte = array_fill_keys(array_keys($segments), 0);
foreach ($liste as $c) foreach (wsm_crm_badges($c, $seuil) as $b => $_) {
    if (isset($compte[$b])) $compte[$b]++;
}

/** Depuis combien de jours, en clair. */
function depuis(string $d): string {
    if ($d === '') return '—';
    $j = (int) floor((time() - strtotime($d)) / 86400);
    if ($j <= 0) return 'dziś';
    if ($j === 1) return 'wczoraj';
    if ($j < 31) return $j . ' dni temu';
    $m = (int) round($j / 30.4);
    return $m . ($m === 1 ? ' miesiąc temu' : ($m < 5 ? ' miesiące temu' : ' miesięcy temu'));
}

console_head('Klienci', $me, <<<'CSS'
  .why { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0 0 14px; }
  .segs { display: flex; gap: 8px; flex-wrap: wrap; margin: 0 0 14px; }
  /* 33 px, collés les uns aux autres : sur un pouce on vise « Stali » et on
     touche « Wysoki obrót ». La pastille garde sa taille visuelle, c'est la
     zone touchable qui grandit. */
  .segs a { font-size: 12.5px; padding: 5px 12px; border-radius: 999px; text-decoration: none;
            min-height: 38px; display: inline-flex; align-items: center;
            border: 1px solid var(--border-default); color: var(--text-muted); }
  .segs a.on { border-color: var(--brand); color: var(--brand); font-weight: 600; }
  .segs a b { font-family: var(--font-mono); font-size: 11px; margin-left: 5px; opacity: .75; }
  /* Un badge dit un fait, pas un jugement : la couleur ne sert qu'à séparer
     ce qui appelle une action (impayé, silence) de ce qui rassure. */
  .bdg { display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 999px;
         white-space: nowrap; border: 1px solid var(--border-default); color: var(--text-muted);
         margin: 0 4px 4px 0; }
  .bdg.vip         { color: var(--success);  border-color: var(--success); }
  .bdg.staly       { color: var(--brand);    border-color: var(--brand); }
  .bdg.b2b         { color: var(--info);     border-color: var(--info); }
  .bdg.spiacy      { color: var(--warning);  border-color: var(--warning); }
  .bdg.nieoplacone { color: var(--danger);   border-color: var(--danger); }
  .fiche { display: grid; grid-template-columns: minmax(0, 1fr); gap: 20px; }
  @media (min-width: 1100px) { .fiche { grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); } }
  .idc { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 12px; }
  .idc .av { width: 62px; height: 62px; border-radius: 50%; flex: 0 0 auto;
             background: var(--surface-sunken); border: 1px solid var(--border-subtle);
             display: grid; place-items: center; font-family: var(--font-display);
             font-size: 22px; color: var(--text-muted); overflow: hidden; }
  .idc .av img { width: 100%; height: 100%; object-fit: cover; }
  .idc h2 { margin: 0 0 2px; }
  .idc .em { font-family: var(--font-mono); font-size: 12.5px; color: var(--text-muted); }
  .mini { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 10px; margin: 14px 0; }
  @media (min-width: 720px) { .mini { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); } }
  .mini .m { border: 1px solid var(--border-subtle); border-radius: 10px; padding: 10px 12px; }
  .mini .m b { display: block; font-family: var(--font-display); font-size: 19px; color: var(--text-strong); }
  .mini .m span { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .08em; }
  .notes li { border-left: 2px solid var(--border-default); padding: 4px 0 8px 12px;
              margin-bottom: 10px; list-style: none; }
  .notes .meta { font-family: var(--font-mono); font-size: 11px; color: var(--text-muted); }
  .notes { padding: 0; margin: 12px 0 0; }
  /* Deux barres empilées côte à côte : la hauteur totale est le mois, la
     séparation dit d'où vient l'argent. Une seule barre colorée n'aurait pas
     répondu à la question. */
  .chart { position: relative; padding-right: 70px; min-width: 0; margin-top: 12px; }
  .chart .bars { display: flex; align-items: flex-end; gap: 3px; height: 150px; min-width: 0; }
  .chart .bar { display: flex; flex-direction: column; justify-content: flex-end;
                align-items: center; height: 100%; flex: 1 1 0; min-width: 0; }
  .chart .fill { display: block; width: 70%; min-height: 0; }
  .chart .fill.nou { background: var(--accent-400); border-radius: 3px 3px 0 0; }
  .chart .fill.fid { background: var(--brand); }
  .chart .lbl { font-family: var(--font-mono); font-size: 9.5px; color: var(--text-muted);
                margin-top: 5px; white-space: nowrap; }
  @media (max-width: 900px) {
    .chart .lbl { display: none; }
    .chart .bar:nth-child(2n+1) .lbl { display: block; }
  }
  .chart .scale { position: absolute; right: 0; top: 0; height: 150px;
                  display: flex; flex-direction: column; justify-content: space-between;
                  font-family: var(--font-mono); font-size: 10px; color: var(--text-muted); text-align: right; }
  .legend { display: flex; gap: 14px; flex-wrap: wrap; font-size: 12px;
            color: var(--text-muted); margin: 10px 0 0; }
  .legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px;
              margin-right: 5px; vertical-align: -1px; }
CSS);
console_crumbs($fiche
    ? ['Pulpit' => 'pulpit.php', 'Klienci' => 'klienci.php',
       ($fiche['name'] ?: $fiche['email']) => null]
    : ['Pulpit' => 'pulpit.php', 'Klienci' => null]);
?>

<?php if ($flash !== '') console_flash($flash, $kind); ?>

<?php if (!$fiche): ?>

<div class="tabs">
  <a href="klienci.php"<?= $onglet === 'lista' ? ' class="on"' : '' ?>>Lista</a>
  <a href="klienci.php?widok=analiza"<?= $onglet === 'analiza' ? ' class="on"' : '' ?>>Analiza i alerty</a>
  <a href="klienci.php?widok=b2b"<?= $onglet === 'b2b' ? ' class="on"' : '' ?>>Konta firmowe</a>
</div>

<?php if ($onglet === 'b2b'):
  $cands = wsm_b2b_candidats($pdo);
  $nbElig = 0;
  foreach ($cands as $c) if ($c['eligible'] && !$c['actif']) $nbElig++;
?>
<div class="panel">
  <h2>Konta firmowe <span class="code"><?= count($cands) ?></span></h2>
  <p class="why">
    Konto firmowe otwiera się po przekroczeniu <b><?= WSM_B2B_KG ?> kg</b> w ciągu
    <b><?= WSM_B2B_MOIS ?> miesięcy</b>, liczonych z <b>zapłaconych</b> zamówień: niezapłacone
    nie dowodzi niczego o zdolności zakupowej.
  </p>
  <p class="why">
    <b>Otwarcie daje CENĘ, nigdy KREDYTU.</b> Rabat <?= h(number_format(WSM_B2B_REMISE, 0, ',', ' ')) ?> %
    wchodzi od razu; termin płatności i limit kredytowy zostają puste, dopóki człowiek ich nie
    ustawi. Rabat kosztuje część marży — linia kredytowa kosztuje całą fakturę.
  </p>
  <p class="why">
    Rabaty się <b>nie sumują</b>: próg wagowy i rabat firmowy odpowiadają na to samo pytanie,
    a 20 % + 12 % dałoby 30 %, czyli całą marżę. Stosuje się <b>lepszy z dwóch</b>.
  </p>

  <?php if ($isAdmin): ?>
  <form method="post" style="margin-bottom:14px">
    <input type="hidden" name="_t" value="<?= h($csrf) ?>">
    <button class="btn" name="skan_b2b" value="1">Otwórz konta wszystkim uprawnionym
      <?= $nbElig > 0 ? '(' . $nbElig . ')' : '' ?></button>
  </form>
  <?php endif; ?>

  <?php if (!$cands): ?>
  <p class="why">Nikt nie zbliżył się do progu w ostatnich <?= WSM_B2B_MOIS ?> miesiącach.</p>
  <?php else: ?>
  <table class="rwd">
    <thead><tr>
      <th>Klient</th><th class="num">Waga (<?= WSM_B2B_MOIS ?> mies.)</th>
      <th class="num">Rabat</th><th>Stan</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($cands as $c): ?>
      <tr>
        <td data-l="Klient">
          <a href="klienci.php?email=<?= rawurlencode($c['email']) ?>"><b><?= h($c['name'] ?: $c['email']) ?></b></a>
          <?php if ($c['company'] !== '' && $c['company'] !== $c['name']): ?><br><span style="font-size:12px;color:var(--text-muted)"><?= h($c['company']) ?></span><?php endif; ?>
        </td>
        <td data-l="Waga" class="num"><b><?= h(number_format((float) $c['kg'], 1, ',', ' ')) ?> kg</b></td>
        <td data-l="Rabat" class="num"><?= $c['remise'] > 0 ? h(number_format((float) $c['remise'], 1, ',', ' ')) . ' %' : '—' ?></td>
        <td data-l="Stan">
          <?php if ($c['actif']): ?><span class="bdg b2b">konto firmowe</span>
          <?php elseif ($c['eligible']): ?><span class="bdg spiacy">uprawniony</span>
          <?php else: ?><span class="bdg">poniżej progu</span><?php endif; ?>
        </td>
        <td data-l="">
          <?php if ($isAdmin): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="_t" value="<?= h($csrf) ?>">
            <?php if (!$c['actif']): ?>
              <button class="btn sm" name="otworz_b2b" value="<?= h($c['email']) ?>">Otwórz</button>
            <?php else: ?>
              <button class="btn sm ghost" name="zamknij_b2b" value="<?= h($c['email']) ?>">Zamknij</button>
            <?php endif; ?>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="why" style="margin-top:12px">
    Konto zamyka się <b>ręcznie</b>. Gdy wolumen spadnie, ekran to pokaże — ale odcięcie
    ceny bez uprzedzenia to najlepszy sposób, żeby stracić klienta.
  </p>
  <?php endif; ?>
</div>

<?php elseif ($onglet === 'analiza'):
  $alertes = wsm_crm_alerts($pdo);
  $conc    = wsm_crm_concentration($liste, 5);
  $coh     = wsm_crm_cohorts($pdo, 12);
  $nvr     = wsm_crm_new_vs_returning($pdo, 12);
  $maxNvr  = 0;
  foreach ($nvr as $m) $maxNvr = max($maxNvr, (int) $m['nouveau'] + (int) $m['fidele']);
?>

<div class="panel">
  <h2>Co czeka na człowieka <span class="code"><?= count($alertes) ?></span></h2>
  <p class="why">
    Alert to <b>rzecz do zrobienia</b>, nie statystyka. „47 klientów" nie jest alertem;
    „Anna Nowak kupowała co miesiąc i milczy od 140 dni" jest — bo można podnieść słuchawkę.
    Żadnych wymyślonych wskaźników: tylko liczby, które da się sprawdzić.
  </p>
  <?php if (!$alertes): ?>
  <p class="why">Nic nie wymaga dziś uwagi.</p>
  <?php else: ?>
  <table class="rwd">
    <thead><tr><th>Klient</th><th>Co się dzieje</th><th>Co zrobić</th></tr></thead>
    <tbody>
    <?php foreach ($alertes as $a): ?>
      <tr>
        <td data-l="Klient">
          <a href="<?= h($a['href']) ?>"><b><?= h($a['nom']) ?></b></a><br>
          <span class="bdg <?= $a['severite'] >= 3 ? 'nieoplacone' : ($a['severite'] === 2 ? 'spiacy' : '') ?>">
            <?= h($a['type']) ?></span>
        </td>
        <td data-l="Co się dzieje"><?= h($a['texte']) ?></td>
        <td data-l="Co zrobić" style="color:var(--text-muted)"><?= h($a['geste']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Skąd bierze się obrót</h2>
  <p class="why">
    Dwa sklepy mogą mieć tę samą krzywą sprzedaży: jeden zatrzymuje klientów, drugi ich
    wymienia. <b>To nie jest ten sam interes</b> — i nie widać tego na żadnym wykresie obrotu.
    Ciemny słupek to klienci, którzy wrócili; jasny to pierwszy zakup.
  </p>
  <?php if ($maxNvr <= 0): ?>
  <p class="why">Brak zapłaconych zamówień w tym okresie.</p>
  <?php else: ?>
  <div class="chart"><div class="bars">
    <?php foreach ($nvr as $m): $tot2 = (int) $m['nouveau'] + (int) $m['fidele']; ?>
    <div class="bar" title="<?= h($m['label'] . ' · nowi ' . pln((int) $m['nouveau']) . ' · wracający ' . pln((int) $m['fidele'])) ?>">
      <?php if ($tot2 > 0): ?>
      <i class="fill fid" style="height:<?= max(1, (int) round((int) $m['fidele'] / $maxNvr * 100)) ?>%"></i>
      <i class="fill nou" style="height:<?= max(1, (int) round((int) $m['nouveau'] / $maxNvr * 100)) ?>%"></i>
      <?php endif; ?>
      <span class="lbl"><?= h($m['label']) ?></span>
    </div>
    <?php endforeach; ?>
  </div><div class="scale"><span><?= h(pln($maxNvr)) ?></span><span>0</span></div></div>
  <p class="legend">
    <span><i style="background:var(--brand)"></i>Wracający klienci</span>
    <span><i style="background:var(--accent-400)"></i>Pierwszy zakup</span>
  </p>
  <?php endif; ?>

  <h3 style="margin-top:22px;font-size:15px">Koncentracja</h3>
  <p class="why">
    Pięciu największych klientów to <b><?= h(number_format((float) $conc['part'], 1, ',', ' ')) ?> %</b>
    całego obrotu.
    <?php if ($conc['part'] >= 50): ?>
    To dużo: odejście jednego z nich nie byłoby incydentem, tylko rokiem.
    <?php endif; ?>
  </p>
  <?php if ($conc['top']): ?>
  <table class="rwd">
    <thead><tr><th>Klient</th><th class="num">Obrót</th><th class="num">Udział</th></tr></thead>
    <tbody>
    <?php foreach ($conc['top'] as $c): ?>
      <tr>
        <td data-l="Klient"><a href="klienci.php?email=<?= rawurlencode($c['email']) ?>"><?= h($c['name'] ?: $c['email']) ?></a></td>
        <td data-l="Obrót" class="num"><?= h(pln((int) $c['revenue'])) ?></td>
        <td data-l="Udział" class="num"><?= h(number_format((int) $c['revenue'] / max(1, (int) $conc['total']) * 100, 1, ',', ' ')) ?> %</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Czy klienci wracają</h2>
  <p class="why">
    Kohorty: z klientów, których <b>pierwszy</b> zakup przypadł na dany miesiąc, ilu kupiło
    ponownie. To jedyna miara, która mówi, czy sklep buduje bazę, czy co miesiąc zaczyna
    od zera. Ostatnie miesiące zawsze wyglądają gorzej — ci klienci po prostu nie mieli
    jeszcze czasu wrócić.
  </p>
  <table class="rwd">
    <thead><tr><th>Pierwszy zakup</th><th class="num">Klientów</th><th class="num">Wróciło</th><th class="num">Odsetek</th></tr></thead>
    <tbody>
    <?php foreach (array_reverse($coh) as $r): if ((int) $r['clients'] === 0) continue; ?>
      <tr>
        <td data-l="Pierwszy zakup"><?= h($r['label']) ?></td>
        <td data-l="Klientów" class="num"><?= (int) $r['clients'] ?></td>
        <td data-l="Wróciło" class="num"><?= (int) $r['revenus'] ?></td>
        <td data-l="Odsetek" class="num"><b><?= h(number_format((float) $r['pct'], 1, ',', ' ')) ?> %</b></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php else: ?>

<div class="kpis">
  <div class="kpi"><b><?= (int) $tot['acheteurs'] ?></b><span>Kupili choć raz</span></div>
  <div class="kpi"><b><?= (int) $tot['fideles'] ?></b><span>Stali (od <?= WSM_CRM_FIDELE_MIN ?> zakupów)</span></div>
  <div class="kpi<?= $tot['dormants'] > 0 ? ' hot' : '' ?>"><b><?= (int) $tot['dormants'] ?></b><span>Śpiący</span></div>
  <div class="kpi"><b><?= h(pln((int) $tot['panier'])) ?></b><span>Średni koszyk</span></div>
  <div class="kpi"><b><?= h(pln((int) $tot['revenue'])) ?></b><span>Obrót brutto</span></div>
</div>

<div class="panel">
  <h2>Klienci <span class="code"><?= count($vus) ?> z <?= count($liste) ?></span></h2>
  <p class="why">
    Klientem jest <b>adres e-mail</b> — to jedyne, co łączy dwa zamówienia tej samej osoby.
    Nazwisko wpisuje się za każdym razem inaczej, adres się zmienia. Liczy się tylko to,
    co <b>naprawdę zapłacone</b>: klient z trzema nieopłaconymi zamówieniami nie jest dobrym
    klientem, a pokazanie go jako takiego kazałoby podjąć decyzję handlową na pieniądzach,
    które nigdy nie przyszły.
  </p>
  <p class="why">
    Plakietki <b>liczą się przy wyświetlaniu</b>, nie leżą w bazie. „VIP" zapisany na stałe
    zostaje VIP-em trzy lata po ostatnim zakupie i nikt tego nie zauważa.
  </p>

  <?php if ($seuil > 0): ?>
  <p class="why" style="margin-bottom:8px">
    „Wysoki obrót" zaczyna się od <b><?= h(pln($seuil)) ?></b> — to próg dziesiątego percentyla.
    Klientów z tą plakietką bywa więcej niż 10 %, bo wielu ma dokładnie tę samą kwotę;
    dlatego plakietka mówi <b>fakt</b>, a nie odsetek.
  </p>
  <?php endif; ?>
  <div class="segs">
    <a href="klienci.php<?= $q !== '' ? '?q=' . rawurlencode($q) : '' ?>"<?= $seg === '' ? ' class="on"' : '' ?>>Wszyscy <b><?= count($liste) ?></b></a>
    <?php foreach ($segments as $code => $lbl): ?>
    <a href="klienci.php?seg=<?= h($code) ?><?= $q !== '' ? '&amp;q=' . rawurlencode($q) : '' ?>"<?= $seg === $code ? ' class="on"' : '' ?>>
      <?= h($lbl) ?> <b><?= (int) $compte[$code] ?></b></a>
    <?php endforeach; ?>
  </div>

  <form method="get" class="filters" style="margin-bottom:12px">
    <?php if ($seg !== ''): ?><input type="hidden" name="seg" value="<?= h($seg) ?>"><?php endif; ?>
    <label class="field" style="flex:1 1 260px;margin:0"><span>Szukaj (e-mail, nazwisko, firma, NIP)</span>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="np. kowalski, example.com, 897"></label>
    <div class="actions" style="margin:0"><button type="submit">Szukaj</button>
      <?php if ($q !== '' || $seg !== ''): ?><a class="code" href="klienci.php">Wyczyść</a><?php endif; ?>
    </div>
  </form>

  <?php if (!$vus): ?>
  <p class="why">Nikt nie pasuje do tego filtru.</p>
  <?php else: ?>
  <table class="rwd">
    <thead><tr>
      <th>Klient</th><th class="num">Zakupy</th><th class="num">Obrót</th><th class="num">Waga</th>
      <th>Ostatnio</th><th>Plakietki</th>
    </tr></thead>
    <tbody>
    <?php foreach (array_slice($vus, 0, 300) as $c): $bd = wsm_crm_badges($c, $seuil); ?>
      <tr>
        <td data-l="Klient">
          <a href="klienci.php?email=<?= rawurlencode($c['email']) ?>"><b><?= h($c['name'] ?: $c['email']) ?></b></a>
          <?php if ($c['company'] !== ''): ?><br><span style="font-size:12px;color:var(--text-muted)"><?= h($c['company']) ?></span><?php endif; ?>
          <?php if ($c['name'] !== ''): ?><br><span class="code" style="font-size:11px"><?= h($c['email']) ?></span><?php endif; ?>
        </td>
        <td data-l="Zakupy" class="num"><?= (int) $c['paid_orders'] ?>
          <?php if ((int) $c['unpaid'] > 0): ?><br><small style="color:var(--danger)">+<?= (int) $c['unpaid'] ?> nieopł.</small><?php endif; ?>
        </td>
        <td data-l="Obrót" class="num"><b><?= h(pln((int) $c['revenue'])) ?></b></td>
        <td data-l="Waga" class="num"><?= h(kg_pl((int) ($c['weight_g'] ?? 0))) ?></td>
        <td data-l="Ostatnio"><?= h(depuis((string) $c['last_at'])) ?></td>
        <td data-l="Plakietki">
          <?php foreach ($bd as $code => $lbl): ?>
          <span class="bdg <?= h($code) ?>" title="<?= h($lbl) ?>"><?= h($lbl) ?></span>
          <?php endforeach; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (count($vus) > 300): ?>
  <p class="why" style="margin-top:10px">Pokazano 300 z <?= count($vus) ?> — zawęź wyszukiwanie.</p>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php endif; /* fin onglet */ ?>

<?php else: /* ---------------------------- LA FICHE ---------------------------- */ ?>

<div class="panel">
  <div class="idc">
    <div class="av">
      <?php if (($fiche['photo'] ?? '') !== ''): ?>
        <img src="<?= h((string) $fiche['photo']) ?>" alt="">
      <?php else: ?>
        <?= h(mb_strtoupper(mb_substr($fiche['name'] ?: $fiche['email'], 0, 1))) ?>
      <?php endif; ?>
    </div>
    <div style="min-width:0">
      <h2><?= h($fiche['name'] ?: $fiche['email']) ?></h2>
      <?php if ($fiche['company'] !== ''): ?><p style="margin:2px 0"><?= h($fiche['company']) ?><?= $fiche['nip'] !== '' ? ' · NIP ' . h($fiche['nip']) : '' ?></p><?php endif; ?>
      <p class="em"><a href="mailto:<?= h($fiche['email']) ?>"><?= h($fiche['email']) ?></a>
        <?php if (($fiche['lang'] ?? '') !== ''): ?> · pisze <?= h(wsm_lang_po((string) $fiche['lang'])) ?><?php endif; ?></p>
      <p style="margin:8px 0 0">
        <?php foreach ($fiche['badges'] as $code => $lbl): ?>
        <span class="bdg <?= h($code) ?>"><?= h($lbl) ?></span>
        <?php endforeach; ?>
      </p>
    </div>
  </div>

  <div class="mini">
    <div class="m"><b><?= (int) $fiche['paid_orders'] ?></b><span>Zapłacone zamówienia</span></div>
    <div class="m"><b><?= h(pln((int) $fiche['revenue'])) ?></b><span>Obrót brutto</span></div>
    <div class="m"><b><?= h(pln((int) $fiche['basket'])) ?></b><span>Średni koszyk</span></div>
    <div class="m"><b><?= h(kg_pl((int) ($fiche['weight_g'] ?? 0))) ?></b><span>Kupione kilogramy</span></div>
    <div class="m"><b><?= h(depuis((string) $fiche['last_at'])) ?></b><span>Ostatnie zamówienie</span></div>
  </div>
  <p class="why">
    Pierwsze zamówienie: <b><?= h(substr((string) $fiche['first_at'], 0, 10) ?: '—') ?></b>.
    <?php if ((int) $fiche['unpaid'] > 0): ?>
    Ma <b style="color:var(--danger)"><?= (int) $fiche['unpaid'] ?></b> nieopłaconych zamówień —
    nie liczą się do obrotu.
    <?php endif; ?>
  </p>
</div>

<div class="fiche">
  <div class="panel">
    <h2>Zamówienia <span class="code"><?= count($fiche['orders_list']) ?></span></h2>
    <?php if (!$fiche['orders_list']): ?>
    <p class="why">Jeszcze nic nie zamówił.</p>
    <?php else: ?>
    <table class="rwd">
      <thead><tr><th>Numer</th><th>Data</th><th>Stan</th><th class="num">Kwota</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($fiche['orders_list'], 0, 40) as $o): ?>
        <tr>
          <td data-l="Numer"><a class="code" href="zamowienia.php?id=<?= (int) $o['id'] ?>"><?= h((string) $o['code']) ?></a></td>
          <td data-l="Data"><?= h(substr((string) $o['created_at'], 0, 10)) ?></td>
          <td data-l="Stan"><span class="bdg<?= $o['payment_status'] === 'oplacone' ? ' vip' : ($o['status'] === 'anulowane' ? '' : ' nieoplacone') ?>"><?= h((string) $o['status']) ?></span></td>
          <td data-l="Kwota" class="num"><?= h(pln((int) $o['total_gross'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($fiche['top']): ?>
    <h3 style="margin-top:20px;font-size:15px">Co kupuje <span class="code"><?= count($fiche['top']) ?></span></h3>
    <p class="why">
      Ilości, nie linie: dziesięć razy kilogram to nie to samo co raz dziesięć kilogramów.
      Liczone tylko z zapłaconych zamówień. Produkt, któremu zmieniono nazwę, zostaje
      jednym wierszem — liczy się to, co kupiono, nie jak się wtedy nazywało.
    </p>
    <table class="rwd">
      <thead><tr><th>Produkt</th><th class="num">Sztuk</th><th class="num">Waga</th><th class="num">Wartość</th></tr></thead>
      <tbody>
      <?php foreach ($fiche['top'] as $t): ?>
        <tr>
          <td data-l="Produkt"><?= h((string) $t['name']) ?></td>
          <td data-l="Sztuk" class="num"><?= (int) $t['q'] ?></td>
          <td data-l="Waga" class="num"><?= h(kg_pl((int) $t['g'])) ?></td>
          <td data-l="Wartość" class="num"><?= h(pln((int) $t['v'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($fiche['invoices']): ?>
    <h3 style="margin-top:20px;font-size:15px">Faktury</h3>
    <table class="rwd">
      <thead><tr><th>Numer</th><th>Rodzaj</th><th>Data</th><th class="num">Kwota</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($fiche['invoices'], 0, 20) as $f): ?>
        <tr>
          <td data-l="Numer"><a class="code" href="faktury.php?id=<?= (int) $f['id'] ?>"><?= h((string) $f['number']) ?></a></td>
          <td data-l="Rodzaj"><?= h((string) $f['kind']) ?></td>
          <td data-l="Data"><?= h(substr((string) $f['issued_at'], 0, 10)) ?></td>
          <td data-l="Kwota" class="num"><?= h(pln((int) $f['total_gross'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div>
    <div class="panel">
      <h2>Notatki</h2>
      <p class="why">
        Podpisane i datowane. „Trudny klient" bez autora jest plotką; z autorem
        i datą jest informacją, za którą ktoś odpowiada.
      </p>
      <?php if ($isAdmin): ?>
      <form method="post">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <input type="hidden" name="email" value="<?= h($fiche['email']) ?>">
        <label class="field"><span>Nowa notatka</span>
          <textarea name="notatka" rows="4" maxlength="2000"
                    placeholder="np. woli odbiór w paczkomacie przy pracy; dzwonić po 16:00"></textarea></label>
        <div class="actions"><button class="primary" type="submit">Zapisz notatkę</button></div>
      </form>
      <?php endif; ?>
      <?php if ($fiche['notes']): ?>
      <ul class="notes">
        <?php foreach ($fiche['notes'] as $n): ?>
        <li>
          <div><?= nl2br(h((string) $n['note'])) ?></div>
          <div class="meta"><?= h(substr((string) $n['created_at'], 0, 16)) ?> · <?= h((string) $n['actor']) ?>
            <?php if ($isAdmin): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="_t" value="<?= h($csrf) ?>">
              <button class="btn sm ghost" name="usun_notatke" value="<?= (int) $n['id'] ?>">Usuń</button>
            </form>
            <?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p class="why">Brak notatek.</p>
      <?php endif; ?>
    </div>

    <div class="panel" style="margin-top:20px">
      <h2>Korespondencja</h2>
      <?php if (!$fiche['messages']): ?>
      <p class="why">Nic nie wysłano ani nie otrzymano.</p>
      <?php else: ?>
      <table class="rwd">
        <thead><tr><th>Kiedy</th><th>Temat</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($fiche['messages'], 0, 15) as $m): ?>
          <tr>
            <td data-l="Kiedy"><span class="code"><?= h(substr((string) $m['created_at'], 0, 10)) ?></span><br>
              <small style="color:var(--text-muted)"><?= $m['direction'] === 'wejscie' ? 'od klienta' : 'do klienta' ?></small></td>
            <td data-l="Temat"><a href="poczta.php?id=<?= (int) $m['id'] ?>"><?= h((string) $m['subject']) ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
      <p style="margin-top:12px">
        <a class="btn sm" href="poczta.php?email=<?= rawurlencode($fiche['email']) ?>">Napisz do klienta</a>
      </p>
    </div>
  </div>
</div>

<p style="margin-top:16px"><a class="code" href="klienci.php">← Wszyscy klienci</a></p>

<?php endif; ?>

<?php console_foot(); ?>
