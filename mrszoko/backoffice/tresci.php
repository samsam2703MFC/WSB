<?php
// ============================================================================
//  tresci.php — le CMS : tout le texte des deux sites publics, éditable ici.
//
//  Ce que cet écran pilote pour de vrai : toutes les chaînes qui composent la
//  page d'accueil et la boutique, dans les huit langues du projet, plus les
//  quatre cartes produit de la page d'accueil. Il n'y a rien d'autre à piloter
//  — aucun texte n'est écrit en dur dans les pages, et c'est précisément ce
//  qui rend ce CMS possible sans toucher au code.
//
//  Trois choix d'ergonomie, dans l'ordre où ils comptent :
//
//   • ON TRADUIT PAR PAIRE, FACE AU POLONAIS. Traduire en changeant d'écran,
//     c'est traduire de mémoire ; mais huit colonnes côte à côte ne tiennent
//     sur aucun écran, et personne ne compare le hongrois au tchèque. La
//     ligne montre donc la source et la langue en cours — l'écart se voit
//     sans le chercher, à n'importe quel nombre de langues.
//   • UNE SECTION À LA FOIS. Sept cents champs sur une page seraient
//     illisibles et produiraient des enregistrements énormes. On ouvre la
//     section qu'on veut corriger — nagłówek, koszyk, stopka — et on
//     n'enregistre qu'elle.
//   • CE QUI MANQUE EST COMPTÉ. Une traduction vide n'est pas une panne (la
//     page retombe sur le polonais), mais c'est une dette : elle est affichée
//     comme telle, avec un filtre pour n'éditer que ça.
//
//  Écriture : Centrala uniquement. Chaque enregistrement passe à l'audit avec
//  la liste des clés touchées — le contenu public est une responsabilité.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/cms.php';
require_once $API . '/i18n.php';
require_once $API . '/translate.php';

wsm_ensure_landing($pdo);
wsm_i18n_ensure($pdo);
wsm_i18n_ensure_origin($pdo);

$sites = wsm_cms_sites();
$sk    = isset($_GET['site']) && isset($sites[$_GET['site']]) ? (string) $_GET['site'] : 'sklep';
$site  = $sites[$sk];

// À huit langues, les colonnes côte à côte ne tiennent plus : à trois c'était
// déjà serré, à huit c'est illisible sur n'importe quel écran. On travaille
// donc PAR PAIRE — le polonais source, et la langue qu'on traduit. C'est
// aussi la façon dont on traduit vraiment : on ne compare pas le hongrois au
// tchèque, on compare chacun à l'original.
$registre = wsm_lang_registry($pdo);
$cible = (string) ($_GET['jezyk'] ?? 'en');
if (!isset($registre[$cible]) || $cible === WSM_CMS_BASE_LANG) {
    $autres = array_values(array_diff(array_keys($registre), [WSM_CMS_BASE_LANG]));
    $cible = $autres[0] ?? 'en';
}
$langs = [WSM_CMS_BASE_LANG, $cible];

/**
 * La langue de travail voyage avec tous les liens de l'écran.
 *
 * Sans ça, ouvrir une section — ou effacer un filtre — repart sur la langue
 * par défaut : on choisit « français », on clique « meta », et on se retrouve
 * à corriger l'anglais sans s'en apercevoir. Le genre d'erreur qu'on ne
 * découvre qu'en relisant la boutique.
 */
function jez(string $cible): string { return '&amp;jezyk=' . rawurlencode($cible); }

$flash = ''; $flashKind = 'ok';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Sans ce jeton, une page tierce fait poster ce formulaire par le
    // navigateur de quelqu'un qui a sa session ouverte, et la console
    // execute la demande comme si elle venait de lui.
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać treści strony.'; $flashKind = 'err';
    } elseif (isset($_POST['przywroc'])) {
        $key = (string) $_POST['przywroc'];
        $n = wsm_cms_revert($pdo, $site['table'], $site['seed'], $key, $langs);
        if ($n) {
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Przywrócenie treści', $site['table'] . ' ' . $key, 'Sieć');
            $flash = 'Przywrócono tekst pierwotny dla „' . $key . '” (' . $n . ' ' . ($n === 1 ? 'język' : 'języki') . ').';
        } else {
            $flash = 'Ten klucz nie ma tekstu pierwotnego w repozytorium — nie ma do czego wrócić.';
            $flashKind = 'err';
        }
    } elseif (isset($_POST['publikuj'])) {
        $code = (string) $_POST['publikuj'];
        [$okp, $msg] = wsm_lang_publish($pdo, $code, !empty($_POST['wlacz']), !empty($_POST['mimo_to']));
        $flash = $msg; $flashKind = $okp ? 'ok' : 'err';
        if ($okp) wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Języki publiczne', $code, 'Sieć');

    } elseif (isset($_POST['tlumacz'])) {
        // Le remplissage ne touche QUE le vide : une traduction relue vaut
        // mieux qu'une traduction fraîche, et l'écraser détruirait du travail.
        $code = (string) $_POST['tlumacz'];
        $res = wsm_tr_fill($pdo, $site['table'], $code, (string) ($me['nom'] ?? ''), 300);
        if ($res['errors']) {
            $flash = 'Tłumaczenie: ' . implode(' · ', $res['errors']); $flashKind = 'err';
        } else {
            $flash = 'Przetłumaczono ' . $res['written'] . ' tekstów na ' . wsm_lang_name($code)
                   . ' — oznaczone jako automatyczne, do przejrzenia.';
            if ($res['placeholder_rejected'] > 0) {
                $flash .= ' Odrzucono ' . $res['placeholder_rejected']
                        . ' (zgubiony znacznik typu {qty} — klient zobaczyłby surowy nawias).';
            }
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Tłumaczenie automatyczne',
                      $code . ' — ' . $res['written'] . ' tekstów', 'Sieć');
        }

    } elseif (isset($_POST['cofnij_zmiane'])) {
        [$okr, $msg] = wsm_i18n_revert($pdo, (int) $_POST['cofnij_zmiane'], (string) ($me['nom'] ?? ''));
        $flash = $msg; $flashKind = $okr ? 'ok' : 'err';

    } elseif (isset($_POST['zatwierdz'])) {
        // « Relu par un humain » : le texte cesse de compter dans les
        // traductions à vérifier, sans changer un caractère.
        $ok2 = wsm_tr_approve($pdo, $site['table'], (string) $_POST['jezyk'], (string) $_POST['zatwierdz']);
        $flash = $ok2 ? 'Oznaczono jako sprawdzone.' : 'Nie udało się oznaczyć.';
        $flashKind = $ok2 ? 'ok' : 'err';

    } elseif (isset($_POST['kafelki'])) {
        // Les cartes de la page d'accueil : ordre, visibilité, teinte, prix.
        $up = $pdo->prepare("UPDATE wsm_landing_products
                                SET sort_order = ?, active = ?, fluidity = ?,
                                    price_from_pln = ?, price_perkg_pln = ?
                              WHERE id = ?");
        $n = 0;
        foreach ((array) ($_POST['p'] ?? []) as $id => $row) {
            $num = function ($v) { $v = str_replace(',', '.', trim((string) $v)); return $v === '' ? null : (float) $v; };
            $up->execute([
                (int) ($row['sort_order'] ?? 0),
                empty($row['active']) ? 0 : 1,
                max(0, min(5, (int) ($row['fluidity'] ?? 0))),
                $num($row['price_from_pln'] ?? ''),
                $num($row['price_perkg_pln'] ?? ''),
                (string) $id,
            ]);
            $n++;
        }
        wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Kafelki strony głównej', $n . ' pozycji', 'Sieć');
        $flash = 'Zapisano kafelki (' . $n . ').';
    } else {
        [$n, $keys] = wsm_cms_save($pdo, $site['table'], (array) ($_POST['t'] ?? []), $langs, (string) ($me['nom'] ?? ''));
        if ($n) {
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Treści — ' . $site['label'],
                      implode(', ', array_slice($keys, 0, 20)) . (count($keys) > 20 ? ' …' : ''), 'Sieć');
            $flash = 'Zapisano ' . $n . ' ' . ($n === 1 ? 'tekst' : 'tekstów') . ' w ' . count($keys) . ' kluczach.';
        } else {
            $flash = 'Nic nie zmieniono.';
        }
    }
}

$content = wsm_cms_load($pdo, $site['table'], $langs);
$groups  = wsm_cms_groups($content);
$missing = wsm_cms_missing($content, $langs);
$source  = wsm_cms_source($site['seed']);

$q       = trim((string) ($_GET['q'] ?? ''));
$onlyGap = isset($_GET['braki']);
$open    = (string) ($_GET['sekcja'] ?? '');
$tab     = (string) ($_GET['zakladka'] ?? 'teksty');

// Le filtre s'applique AVANT le choix de la section : chercher « paczkomat »
// doit montrer les résultats de toutes les sections, pas de la seule ouverte.
$visible = [];
foreach ($groups as $prefix => $keys) {
    $keep = [];
    foreach ($keys as $k) {
        if (!wsm_cms_match($k, $content[$k], $q)) continue;
        if ($onlyGap) {
            $gap = false;
            foreach ($langs as $l) {
                if ($l === WSM_CMS_BASE_LANG) continue;
                if (trim((string) $content[$k][$l]) === '' && trim((string) $content[$k][WSM_CMS_BASE_LANG]) !== '') $gap = true;
            }
            if (!$gap) continue;
        }
        $keep[] = $k;
    }
    if ($keep) $visible[$prefix] = $keep;
}
// Une recherche ouvre d'office ce qu'elle a trouvé : sinon on cherche, on
// obtient « 3 wyniki », et il faut encore deviner où cliquer.
if (($q !== '' || $onlyGap) && $open === '' && $visible) $open = (string) array_key_first($visible);

$found = array_sum(array_map('count', $visible));
// Le nom est écrit DANS la langue : on cherche « Čeština », pas « tchèque ».
$langLabel = [];
foreach ($registre as $c => $l) $langLabel[$c] = $l['name'];

$css = <<<'CSS'
  .sitepick { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
  .sitepick a { flex: 1 1 220px; display: block; padding: 12px 14px; border-radius: 12px;
      border: 1px solid var(--border-default); background: var(--surface-card);
      text-decoration: none; color: var(--text-strong); }
  .sitepick a.on { border-color: var(--brand); box-shadow: 0 0 0 1px var(--brand); }
  .sitepick b { display: block; font-family: var(--font-display); font-size: 16px; }
  .sitepick span { display: block; font-size: 12.5px; color: var(--text-muted); margin-top: 3px; line-height: 1.45; }
  .secnav { display: flex; flex-wrap: wrap; gap: 7px; margin: 4px 0 6px; }
  .secnav a { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 999px;
      border: 1px solid var(--border-default); background: var(--surface-card); text-decoration: none;
      font-size: 13px; color: var(--text-body); min-height: 34px; }
  .secnav a.on { background: var(--brand); border-color: var(--brand); color: var(--paper-50); }
  .secnav a i { font-style: normal; font-family: var(--font-mono); font-size: 11px; opacity: .7; }
  .secnav a.gap i { color: var(--danger); opacity: 1; font-weight: 700; }
  .secnav a.on i { opacity: .85; }
  .row { padding: 14px 0; border-bottom: 1px solid var(--border-subtle); }
  .row:last-child { border-bottom: 0; }
  .row > .kk { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; margin-bottom: 8px; }
  .row .kk code { font-family: var(--font-mono); font-size: 11.5px; color: var(--text-muted); }
  .row .kk .rv { font-size: 12px; }
  .langs { display: grid; grid-template-columns: 1fr; gap: 10px; }
  @media (min-width: 900px) { .langs { grid-template-columns: repeat(var(--n, 3), 1fr); } }
  .lf > span { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .08em;
      color: var(--text-muted); margin-bottom: 4px; font-family: var(--font-mono); }
  .lf input, .lf textarea { width: 100%; font-size: 16px; }
  .lf textarea { min-height: 78px; resize: vertical; line-height: 1.5; }
  .lf.empty input, .lf.empty textarea { border-color: var(--danger); background: #fff8f7; }
  /* La barre d'enregistrement suit le défilement : une section de 38 textes
     ne doit pas obliger à descendre jusqu'en bas pour valider. L'ombre dit
     qu'elle flotte au-dessus du contenu, sinon on croit à une coupure. */
  .stick { position: sticky; bottom: 0; z-index: 2; background: var(--surface-card);
      padding: 12px 0 4px; border-top: 1px solid var(--border-default); margin-top: 6px;
      box-shadow: 0 -12px 18px -12px rgba(0,0,0,.28); }
  .filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: end; margin-bottom: 8px; }
  /* L'état d'une langue, et d'où vient un texte. La couleur porte le sens :
     publié = servi au visiteur, auto = écrit par une machine et pas encore relu. */
  /* Un texte de contenu peut être une longue chaîne sans espace — une clé
     recopiée, un test, une URL. Sans coupure forcée elle sort de sa cellule
     et passe SOUS le bouton d'à côté, qui devient intapable. */
  .hist-v { max-width: 420px; overflow-wrap: anywhere; word-break: break-word; }
  .st { font-size: 11.5px; padding: 2px 9px; border-radius: 999px; white-space: nowrap;
        border: 1px solid var(--border-default); color: var(--text-muted); }
  .st.pub    { color: var(--success); border-color: var(--success); }
  .st.auto   { color: var(--warning); border-color: var(--warning); }
  .st.revert { color: var(--info);    border-color: var(--info); }
  .why { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0 0 12px; }
  .filters .field { margin-bottom: 0; }
CSS;

console_head('Treści', $me, $css, $found . ' z ' . count($content));
console_flash($flash, $flashKind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Treści' => null,
                $site['label'] => null]);
?>

<div class="sitepick">
  <?php foreach ($sites as $key => $s): ?>
  <a href="tresci.php?site=<?= h($key) ?><?= jez($cible) ?>"<?= $key === $sk ? ' class="on"' : '' ?>>
    <b><?= h($s['label']) ?></b>
    <span><?= h($s['about']) ?></span>
  </a>
  <?php endforeach; ?>
</div>

<div class="kpis">
  <div class="kpi"><b><?= count($content) ?></b><span>Kluczy</span></div>
  <?php foreach ($langs as $l): if ($l === WSM_CMS_BASE_LANG) continue; ?>
  <div class="kpi<?= $missing[$l] ? ' hot' : '' ?>"><b><?= (int) $missing[$l] ?></b>
    <span>Bez tłumaczenia — <?= h(strtoupper($l)) ?></span></div>
  <?php endforeach; ?>
  <div class="kpi"><b><?= count($groups) ?></b><span>Sekcji</span></div>
</div>

<div class="tabs">
  <a href="tresci.php?site=<?= h($sk) ?><?= jez($cible) ?>"<?= $tab === 'teksty' ? ' class="on"' : '' ?>>Teksty</a>
  <a href="tresci.php?site=<?= h($sk) ?><?= jez($cible) ?>&amp;zakladka=jezyki"<?= $tab === 'jezyki' ? ' class="on"' : '' ?>>Języki</a>
  <?php if ($sk === 'strona'): ?>
  <a href="tresci.php?site=strona&amp;zakladka=kafelki"<?= $tab === 'kafelki' ? ' class="on"' : '' ?>>Kafelki gamy</a>
  <?php else: ?>
  <a href="tresci.php?site=sklep&amp;zakladka=dostawa"<?= $tab === 'dostawa' ? ' class="on"' : '' ?>>Cennik dostawy</a>
  <?php endif; ?>
  <a href="<?= h($site['url']) ?>" target="_blank" rel="noopener">Podgląd strony ↗</a>
</div>

<?php if ($tab === 'jezyki'):
  $couv = [];
  foreach ($registre as $c => $l) $couv[$c] = wsm_lang_coverage_all($pdo, $c);
  $histo = wsm_i18n_history($pdo, [], 40);
  $ia = wsm_tr_enabled();
?>
<div class="panel">
  <h2>Języki sklepu</h2>
  <p class="why">
    Polski jest <b>źródłem i siatką bezpieczeństwa</b>: brakujący tekst w innym języku nie robi
    dziury w stronie, tylko pokazuje polski. Dlatego pusta komórka to nie awaria — to sposób
    powiedzenia „jeszcze nieprzetłumaczone”.
  </p>
  <p class="why">
    <b>Publikacja jest decyzją, nie skutkiem ubocznym.</b> Wcześniej lista języków wynikała
    z tego, co jest w bazie: jedno przetłumaczone zdanie po niemiecku wystawiłoby flagę DE
    prowadzącą do sklepu w 99 % polskiego. Kto raz na to kliknie, nie wraca. Poniżej
    <b><?= h(number_format(WSM_LANG_MIN_COVERAGE, 0, ',', ' ')) ?> %</b> publikacja jest
    odmawiana — chyba że świadomie zaznaczysz „mimo to”.
  </p>

  <table class="rwd">
    <thead><tr>
      <th>Język</th><th class="num">Pokrycie</th><th class="num">Do przejrzenia</th>
      <th>Stan</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($registre as $c => $l): $cv = $couv[$c]; $auto = wsm_tr_pending($pdo, $c); ?>
      <tr>
        <td data-l="Język"><b><?= h($l['name']) ?></b>
          <span class="code" style="margin-left:6px"><?= h($l['short']) ?></span>
          <?php if ($c === WSM_CMS_BASE_LANG): ?><br><small style="color:var(--text-muted)">źródło</small><?php endif; ?>
        </td>
        <td data-l="Pokrycie" class="num">
          <?php if ($c === WSM_CMS_BASE_LANG): ?>—<?php else: ?>
            <b><?= h(number_format((float) $cv['pct'], 1, ',', ' ')) ?> %</b><br>
            <small style="color:var(--text-muted)"><?= (int) $cv['done'] ?> / <?= (int) $cv['total'] ?></small>
          <?php endif; ?>
        </td>
        <td data-l="Do przejrzenia" class="num">
          <?php if ($auto > 0): ?>
            <span class="st auto" title="tłumaczenia maszynowe, jeszcze niesprawdzone"><?= (int) $auto ?></span>
          <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
        </td>
        <td data-l="Stan">
          <span class="st <?= $l['published'] ? 'pub' : '' ?>">
            <?= $l['published'] ? 'publiczny' : 'ukryty' ?></span>
        </td>
        <td data-l="">
          <?php if ($c !== WSM_CMS_BASE_LANG && $isAdmin): ?>
          <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <?= console_csrf_field() ?>
            <input type="hidden" name="publikuj" value="<?= h($c) ?>">
            <?php if (!$l['published']): ?>
              <input type="hidden" name="wlacz" value="1">
              <button class="btn sm">Opublikuj</button>
              <?php if ($cv['pct'] < WSM_LANG_MIN_COVERAGE): ?>
              <label style="font-size:12px;color:var(--text-muted)">
                <input type="checkbox" name="mimo_to" value="1"> mimo to</label>
              <?php endif; ?>
            <?php else: ?>
              <button class="btn sm ghost">Wycofaj</button>
            <?php endif; ?>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel">
  <h2>Tłumaczenie automatyczne — <?= h($site['label']) ?></h2>
  <?php if (!$ia): ?>
    <p class="why">
      Nie skonfigurowane. Klucz API żyje wyłącznie w <code>config.local.php</code> na serwerze —
      to repozytorium jest publiczne. Bez klucza nic nie działa <b>połowicznie</b>: przycisku
      po prostu nie ma.
    </p>
  <?php else: ?>
    <p class="why">
      Uzupełnia <b>tylko puste</b> pola. Tłumaczenie sprawdzone przez człowieka jest zawsze
      warte więcej niż świeże maszynowe — nadpisanie go byłoby niszczeniem pracy bez pytania.
      Wynik trafia jako <b>do przejrzenia</b>: maszyna myli się pewnym siebie tonem,
      a źle przetłumaczony przycisk kosztuje zamówienia.
    </p>
    <p class="why">
      Znaczniki takie jak <code>{qty}</code> muszą przejść <b>nietknięte</b>. Jeśli model je
      zgubi, tłumaczenie jest <b>odrzucane</b>: pusta komórka pokaże polski, a zepsuty znacznik
      pokazałby klientowi surowy nawias klamrowy.
    </p>
    <div class="filters">
      <?php foreach ($registre as $c => $l): if ($c === WSM_CMS_BASE_LANG) continue;
        $cv = $couv[$c]; if ($cv['pct'] >= 100.0) continue; ?>
      <form method="post">
      <?= console_csrf_field() ?>
        <button class="btn sm" name="tlumacz" value="<?= h($c) ?>">
          <?= h($l['name']) ?> — uzupełnij <?= (int) ($cv['total'] - $cv['done']) ?></button>
      </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Historia zmian treści</h2>
  <p class="why">
    Co, kto, kiedy — i poprzednia wersja. Tekst publiczny, który zmienia się bez autora,
    daje się poprawić dopiero przez ponowne przeczytanie całej strony.
    Cofnięcie też trafia do historii: da się cofnąć cofnięcie.
  </p>
  <?php if (!$histo): ?>
    <p class="why">Jeszcze nic nie zmieniono.</p>
  <?php else: ?>
  <table class="rwd">
    <thead><tr><th>Kiedy</th><th>Język</th><th>Klucz</th><th>Było → jest</th><th>Kto</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($histo as $h): ?>
      <tr>
        <td data-l="Kiedy"><span class="code"><?= h(substr((string) $h['created_at'], 0, 16)) ?></span></td>
        <td data-l="Język"><?= h($langLabel[$h['lang']] ?? strtoupper((string) $h['lang'])) ?>
          <?php if (($h['origin'] ?? '') !== 'human'): ?>
            <br><span class="st <?= h((string) $h['origin']) ?>"><?= h((string) $h['origin']) ?></span>
          <?php endif; ?>
        </td>
        <td data-l="Klucz"><span class="code"><?= h((string) $h['k']) ?></span></td>
        <td data-l="Było → jest" class="hist-v">
          <?php $old = (string) ($h['old_v'] ?? ''); ?>
          <?php if ($old !== ''): ?>
            <s style="color:var(--text-muted)"><?= h(mb_substr($old, 0, 90)) ?></s><br>
          <?php else: ?>
            <small style="color:var(--text-muted)">(puste)</small><br>
          <?php endif; ?>
          <?= h(mb_substr((string) ($h['new_v'] ?? ''), 0, 90)) ?>
        </td>
        <td data-l="Kto"><?= h((string) $h['actor']) ?></td>
        <td data-l="">
          <?php if ($isAdmin): ?>
          <form method="post" style="display:inline">
      <?= console_csrf_field() ?>
            <button class="btn sm ghost" name="cofnij_zmiane" value="<?= (int) $h['id'] ?>">Cofnij</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'dostawa' && $sk === 'sklep'): ?>
<div class="panel">
  <h2>Cennik dostawy przeniesiony</h2>
  <?php // DEUX FORMULAIRES SUR LES MÊMES COLONNES, C'EST UN JOUR OÙ L'UN
        // ÉCRASE L'AUTRE. Le tarif, le poids et le seuil se règlent maintenant
        // au même endroit que le coût réel du colis et la marge — sans quoi on
        // fixait un seuil de gratuité en gardant le coût du transporteur dans
        // sa tête, d'un écran à l'autre. ?>
  <p class="muted small">
    Cena, próg darmowej dostawy, maksymalna waga i zasięg krajów mają teraz jeden ekran:
    <a href="dostawa.php"><b>Dostawa</b></a>. Jest tam też rachunek, <b>od jakiej kwoty
    zamówienia stać Was na darmową przesyłkę</b> — policzony z marży, nie z sufitu.
  </p>
  <div class="actions"><a class="btn btn--brand" href="dostawa.php">Przejdź do Dostawy</a></div>
</div>

<?php elseif ($tab === 'kafelki' && $sk === 'strona'):
  $cards = $pdo->query("SELECT * FROM wsm_landing_products ORDER BY sort_order, id")->fetchAll() ?: []; ?>
<div class="panel">
  <h2>Kafelki gamy</h2>
  <p class="muted small">Cztery karty czekolady na stronie głównej. Nazwa, opis i specyfikacja
    każdej karty to teksty <code>product.&lt;id&gt;.name</code> w zakładce <b>Teksty</b> —
    tutaj ustawia się kolejność, widoczność, płynność (0–5) i ceny orientacyjne.
    Karta odznaczona znika ze strony, ale nic nie traci.</p>
  <form method="post">
      <?= console_csrf_field() ?>
    <input type="hidden" name="kafelki" value="1">
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Karta</th><th class="num">Kolejność</th><th>Widoczna</th>
                 <th class="num">Płynność</th><th class="num">Cena od (zł)</th><th class="num">Cena / kg (zł)</th></tr></thead>
      <tbody>
      <?php foreach ($cards as $c): $id = (string) $c['id']; ?>
      <tr>
        <td data-l="Karta">
          <b><?= h((string) ($content['product.' . $id . '.name'][WSM_CMS_BASE_LANG] ?? $id)) ?></b><br>
          <code class="muted" style="font-size:11.5px"><?= h($id) ?></code>
        </td>
        <td data-l="Kolejność" class="num"><input type="number" name="p[<?= h($id) ?>][sort_order]"
              value="<?= (int) $c['sort_order'] ?>" style="width:80px"></td>
        <td data-l="Widoczna"><input type="checkbox" name="p[<?= h($id) ?>][active]" value="1"
              <?= (int) $c['active'] === 1 ? 'checked' : '' ?>></td>
        <td data-l="Płynność" class="num"><input type="number" min="0" max="5" name="p[<?= h($id) ?>][fluidity]"
              value="<?= (int) $c['fluidity'] ?>" style="width:70px"></td>
        <td data-l="Cena od" class="num"><input type="text" inputmode="decimal" name="p[<?= h($id) ?>][price_from_pln]"
              value="<?= $c['price_from_pln'] !== null ? h(number_format((float) $c['price_from_pln'], 2, ',', '')) : '' ?>"
              style="width:90px"></td>
        <td data-l="Cena / kg" class="num"><input type="text" inputmode="decimal" name="p[<?= h($id) ?>][price_perkg_pln]"
              value="<?= $c['price_perkg_pln'] !== null ? h(number_format((float) $c['price_perkg_pln'], 2, ',', '')) : '' ?>"
              style="width:90px"></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="actions"><button class="primary" type="submit"<?= $isAdmin ? '' : ' disabled' ?>>Zapisz kafelki</button></div>
  </form>
</div>

<?php else: ?>

<div class="panel">
  <h2>Teksty — <?= h($site['label']) ?></h2>
  <p class="muted small">
    Zmiana widoczna od razu po zapisaniu — strona czyta te teksty przy każdym otwarciu, nic się nie buforuje.
    Wpisuje się <b>zwykły tekst</b>: strona sama go zabezpiecza, więc <code>&lt;b&gt;</code> pokaże się dosłownie,
    a nie pogrubi. Puste tłumaczenie nie robi dziury — pokaże się wersja polska.
  </p>

  <form method="get" class="filters">
    <input type="hidden" name="site" value="<?= h($sk) ?>">
    <!-- La langue de travail. On édite TOUJOURS face au polonais : c'est
         ainsi qu'on traduit vraiment, et huit colonnes côte à côte ne
         tiendraient sur aucun écran. -->
    <label class="field"><span>Tłumaczę na</span>
      <select name="jezyk" onchange="this.form.submit()">
        <?php foreach ($registre as $c => $l): if ($c === WSM_CMS_BASE_LANG) continue;
          $cv = wsm_lang_coverage_all($pdo, $c); ?>
        <option value="<?= h($c) ?>"<?= $c === $cible ? ' selected' : '' ?>>
          <?= h($l['name']) ?> — <?= h(number_format((float) $cv['pct'], 0, ',', ' ')) ?> %<?= $l['published'] ? '' : ' (ukryty)' ?>
        </option>
        <?php endforeach; ?>
      </select></label>
    <label class="field" style="flex:1 1 240px"><span>Szukaj w kluczach i tekstach</span>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="np. paczkomat, koszyk, dostawa"></label>
    <label class="field"><span>&nbsp;</span>
      <label style="display:flex;align-items:center;gap:8px;min-height:40px">
        <input type="checkbox" name="braki" value="1" <?= $onlyGap ? 'checked' : '' ?>>
        <span style="font-size:14px">Tylko bez tłumaczenia</span>
      </label></label>
    <div class="actions" style="margin:0"><button type="submit">Filtruj</button>
      <?php if ($q !== '' || $onlyGap): ?><a class="code" href="tresci.php?site=<?= h($sk) ?><?= jez($cible) ?>">Wyczyść</a><?php endif; ?>
    </div>
  </form>

  <?php if (!$visible): ?>
  <p class="muted">Nic nie pasuje do filtra.</p>
  <?php else: ?>
  <div class="secnav">
    <?php foreach ($visible as $prefix => $keys):
      $gap = 0;
      foreach ($keys as $k) foreach ($langs as $l) {
        if ($l !== WSM_CMS_BASE_LANG && trim((string) $content[$k][$l]) === ''
            && trim((string) $content[$k][WSM_CMS_BASE_LANG]) !== '') $gap++;
      } ?>
    <a href="tresci.php?site=<?= h($sk) ?><?= jez($cible) ?>&amp;sekcja=<?= h($prefix) ?><?= $q !== '' ? '&amp;q=' . rawurlencode($q) : '' ?><?= $onlyGap ? '&amp;braki=1' : '' ?>"
       class="<?= $prefix === $open ? 'on' : '' ?><?= $gap ? ' gap' : '' ?>">
      <?= h(wsm_cms_section_label($prefix)) ?>
      <i><?= count($keys) ?><?= $gap ? ' · ' . $gap . '△' : '' ?></i>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($open !== '' && isset($visible[$open])): ?>
<div class="panel">
  <h2><?= h(wsm_cms_section_label($open)) ?>
    <span class="tag"><?= count($visible[$open]) ?> <?= count($visible[$open]) === 1 ? 'tekst' : 'tekstów' ?></span></h2>
  <form method="post">
      <?= console_csrf_field() ?>
    <?php foreach ($visible[$open] as $k): $by = $content[$k]; $multi = wsm_cms_multiline($by); ?>
    <div class="row">
      <div class="kk">
        <code><?= h($k) ?></code>
        <?php if (isset($source[$k])): $chg = false;
          foreach ($langs as $l) if (($source[$k][$l] ?? null) !== null && $source[$k][$l] !== $by[$l]) $chg = true;
          if ($chg): ?>
          <span class="tag">zmienione</span>
        <?php endif; endif; ?>
      </div>
      <div class="langs" style="--n:<?= count($langs) ?>">
        <?php foreach ($langs as $l):
          $empty = trim((string) $by[$l]) === '' && $l !== WSM_CMS_BASE_LANG
                   && trim((string) $by[WSM_CMS_BASE_LANG]) !== ''; ?>
        <label class="lf<?= $empty ? ' empty' : '' ?>">
          <span><?= h($langLabel[$l] ?? strtoupper($l)) ?><?= $l === WSM_CMS_BASE_LANG ? ' · źródło' : '' ?></span>
          <?php if ($multi): ?>
          <textarea name="t[<?= h($k) ?>][<?= h($l) ?>]" rows="3"><?= h((string) $by[$l]) ?></textarea>
          <?php else: ?>
          <input type="text" name="t[<?= h($k) ?>][<?= h($l) ?>]" value="<?= h((string) $by[$l]) ?>">
          <?php endif; ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="stick actions">
      <button class="primary" type="submit"<?= $isAdmin ? '' : ' disabled' ?>>Zapisz sekcję</button>
      <a class="code" href="<?= h($site['url']) ?>" target="_blank" rel="noopener">Zobacz na stronie ↗</a>
    </div>
  </form>

  <?php if ($isAdmin): ?>
  <h3>Przywróć tekst pierwotny</h3>
  <p class="muted small">Wraca do tekstu dostarczonego z wdrożeniem — po jednym kluczu,
    we wszystkich językach. Przydaje się, gdy poprawka okazała się gorsza od oryginału.</p>
  <form method="post" class="actions">
      <?= console_csrf_field() ?>
    <select name="przywroc" style="max-width:340px">
      <?php foreach ($visible[$open] as $k): if (!isset($source[$k])) continue; ?>
      <option value="<?= h($k) ?>"><?= h($k) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit">Przywróć</button>
  </form>
  <?php endif; ?>
</div>
<?php elseif ($visible): ?>
<div class="panel">
  <p class="muted">Wybierz sekcję powyżej, żeby edytować jej teksty.
    Każda sekcja zapisuje się osobno — <?= count($content) ?> pól naraz nikt by nie przejrzał.</p>
</div>
<?php endif; ?>

<?php endif; ?>
<?php console_foot();
