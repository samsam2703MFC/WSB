<?php
declare(strict_types=1);
/**
 * budowa.php — le KREATOR : composer la boutique en sections, sans code.
 *
 * L'équipe voulait « un CMS de type site builder » : poser une photo ici, une
 * galerie là, un bandeau en haut de toutes les pages — et ne rien toucher à la
 * partie boutique. C'est exactement le partage de cet écran : tout ce qui
 * n'est pas le catalogue, le panier et la caisse se compose ici, section par
 * section, dans l'ordre qu'on veut, dans les trois langues. Le catalogue
 * reste à sa place ; on compose AUTOUR.
 *
 * ONZE TYPES, PAS UN ÉDITEUR LIBRE. Chaque section a un gabarit (sections.php,
 * rendu par sekcja_html dans la vitrine) : nagłówek ze zdjęciem, tekst, tekst
 * ze zdjęciem, zdjęcie, galeria, kafelki, baner, pytania, cytat, odstęp,
 * pasek ogłoszeń. Un gabarit fait par la maison garde la vitrine dans son
 * design system quoi qu'on y mette ; un éditeur libre produit en une semaine
 * une page que personne n'a dessinée. Le texte suit la grammaire de Strony
 * (six signes, zéro HTML).
 *
 * TROIS CIBLES : la page d'accueil (sections mêlées au nagłówek, aux
 * obietnice, au catalogue — leur ordre est celui d'Układ strony, qu'on règle
 * aussi ici), chaque page écrite dans Strony (ses sections viennent sous son
 * texte), et « cały sklep » — le pasek ogłoszeń, au-dessus du nagłówek de
 * toutes les pages.
 *
 * L'aperçu à droite est la VRAIE page, rechargée après chaque enregistrement.
 * Centrala écrit ; les autres rôles regardent.
 */
require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/pages.php';
require_once $API . '/sections.php';
require_once $API . '/layout.php';
require_once $API . '/media.php';
require_once $API . '/delivery.php';   // wsm_audit
require_once $API . '/shop.php';       // wsm_shop_available_langs

$types     = wsm_section_types();
$etStylu   = wsm_section_style_labels();
$nomLang   = ['pl' => 'Polski', 'uk' => 'Українська', 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français'];
$strony    = array_values(array_filter(wsm_page_list($pdo, 'strona'), fn($p) => (string) $p['kind'] === 'strona'));
$sklep     = '../shop/';

// ---- La cible : home | site | <id d'une page> ----------------------------------------
// Une section éditée impose sa cible ; sinon le paramètre, et l'accueil par défaut.
$celParam = (string) ($_GET['cel'] ?? ($_POST['cel'] ?? 'home'));
$editId   = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));
$edit     = $editId > 0 ? wsm_section_get($pdo, $editId) : null;
if ($editId > 0 && !$edit) $editId = 0;
if ($edit) $celParam = wsm_section_cel((int) $edit['page_id']) === 'strona' ? (string) (int) $edit['page_id'] : wsm_section_cel((int) $edit['page_id']);

$celId = null; $celNom = ''; $celUrl = $sklep; $celPage = null;
if ($celParam === 'home')      { $celId = WSM_SECTION_HOME; $celNom = 'Strona główna'; }
elseif ($celParam === 'site')  { $celId = WSM_SECTION_SITE; $celNom = 'Cały sklep — pasek ogłoszeń'; }
elseif (ctype_digit($celParam)) {
    foreach ($strony as $p) if ((int) $p['id'] === (int) $celParam) { $celPage = $p; break; }
    if ($celPage) { $celId = (int) $celPage['id']; $celNom = 'Strona: ' . ($celPage['title'] !== '' ? $celPage['title'] : '#' . $celId); $celUrl = $sklep . (string) $celPage['slug']; }
}
if ($celId === null) { $celParam = 'home'; $celId = WSM_SECTION_HOME; $celNom = 'Strona główna'; }
$celKlucz = wsm_section_cel($celId);
$nowa = (string) ($_GET['nowa'] ?? '');
if ($nowa !== '' && (!isset($types[$nowa]) || !in_array($celKlucz, $types[$nowa]['cel'], true))) $nowa = '';

// ---- Les langues du formulaire : publiées + celles où la section a déjà un texte ------
$langs = wsm_shop_available_langs($pdo);
if ($edit) foreach (array_keys($edit['i18n']) as $l) $langs[] = (string) $l;
$langs = array_values(array_unique(array_merge([WSM_SECTION_BASE_LANG], $langs)));
usort($langs, fn($a, $b) => ($a === WSM_SECTION_BASE_LANG ? -1 : ($b === WSM_SECTION_BASE_LANG ? 1 : strcmp($a, $b))));

$flash = ''; $kind = 'ok'; $errs = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    $qui = (string) ($me['nom'] ?? '');
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać sekcje sklepu.'; $kind = 'err';
    } elseif (isset($_POST['zapisz'])) {
        $id = $editId > 0 ? $editId : null;
        $in = $_POST;
        $in['page_id'] = $celId;
        $in['type'] = $edit ? (string) $edit['type'] : (string) ($_POST['type'] ?? '');
        // L'IMAGE : un fichier déposé gagne ; sinon un choix dans la médiathèque ;
        // sinon rien ne bouge. La case « usuń » est un geste explicite.
        $in['image_url'] = (string) ($edit['image_url'] ?? '');
        $nouvelle = null;
        if (!empty($_POST['obraz__usun'])) {
            $in['image_url'] = '';
        } elseif (!empty($_FILES['obraz']['name'] ?? '')) {
            [$url, $err] = wsm_media_store($_FILES['obraz']);
            if ($err !== null) { $errs['image_url'] = 'Obraz: ' . $err; }
            else { $in['image_url'] = (string) $url; $nouvelle = (string) $url; }
        } elseif ((string) ($_POST['obraz__z_medioteki'] ?? '') !== '') {
            $in['image_url'] = (string) $_POST['obraz__z_medioteki'];
        }
        if (!$errs) {
            $e2 = [];
            $sid = wsm_section_save($pdo, $id, $in, $qui, $e2);
            $errs = $e2;
            if ($sid !== null) {
                $editId = $sid; $edit = wsm_section_get($pdo, $sid); $nowa = '';
                wsm_audit($pdo, $qui, $id ? 'Zmiana sekcji' : 'Nowa sekcja',
                          ($types[$in['type']]['label'] ?? $in['type']) . ' #' . $sid . ' (' . $celNom . ')', 'Sklep');
                $flash = ($id ? 'Zapisano. ' : 'Dodano. ')
                       . (!empty($in['published']) ? 'Sekcja jest widoczna w sklepie — podgląd obok.' : 'To szkic: w sklepie jeszcze jej nie widać. Zaznacz „Widoczna”, gdy będzie gotowa.');
            } elseif ($nouvelle !== null) {
                wsm_media_delete($nouvelle);      // un refus ne laisse pas un fichier orphelin
            }
        }
        if ($errs) { $flash = 'Nie zapisano — ' . implode('; ', $errs) . '.'; $kind = 'err'; if (!$id) $nowa = (string) $in['type']; }
    } elseif (isset($_POST['przesun'])) {
        $k = (string) ($_POST['k'] ?? '');
        $dir = $_POST['przesun'] === 'up' ? 'up' : 'down';
        $ok = $celId === WSM_SECTION_HOME ? wsm_layout_move($pdo, 'home', $k, $dir, $qui) : wsm_section_move($pdo, (int) $k, $dir, $qui);
        if ($ok) { wsm_audit($pdo, $qui, 'Kreator strony', "$celNom: $k " . ($dir === 'up' ? 'wyżej' : 'niżej'), 'Sklep'); $flash = 'Przesunięto. Sklep już to pokazuje.'; }
        else { $flash = 'Tego elementu nie da się przesunąć w tę stronę.'; $kind = 'err'; }
    } elseif (isset($_POST['przelacz'])) {
        $k = (string) $_POST['przelacz'];
        $ok = $celId === WSM_SECTION_HOME ? wsm_layout_toggle($pdo, 'home', $k, $qui) : wsm_section_toggle($pdo, (int) $k, $qui);
        if ($ok) { wsm_audit($pdo, $qui, 'Kreator strony', "$celNom: $k pokaż/ukryj", 'Sklep'); $flash = 'Zmieniono widoczność. Sklep już to pokazuje.'; }
        else { $flash = 'Ten element jest stały — nie da się go ukryć.'; $kind = 'err'; }
    } elseif (isset($_POST['usun'])) {
        $id = (int) $_POST['usun'];
        if (empty($_POST['na_pewno'])) { $flash = 'Zaznacz „tak, usuń”, żeby usunąć sekcję.'; $kind = 'err'; }
        else {
            $s = wsm_section_delete($pdo, $id);
            if ($s) {
                wsm_audit($pdo, $qui, 'Usunięcie sekcji', ($types[$s['type']]['label'] ?? $s['type']) . ' #' . $id . ' (' . $celNom . ')', 'Sklep');
                $flash = 'Usunięto sekcję. Zdjęcia zostały w Mediach — tam można je usunąć, jeśli nic już ich nie używa.';
            }
            $editId = 0; $edit = null;
        }
    }
}

$formulaire = $edit !== null || $nowa !== '';
$typ = $edit ? (string) $edit['type'] : $nowa;
$def = $types[$typ] ?? null;

// Les valeurs du formulaire : ce qui vient d'être posté (en cas de refus) gagne sur la base.
$posted = isset($_POST['zapisz']) && $errs;
$txt = function (string $lang, string $f) use ($edit, $posted) {
    if ($posted) return (string) ($_POST['t'][$lang][$f] ?? '');
    return (string) ($edit['i18n'][$lang][$f] ?? '');
};
$poz = function (string $lang, int $i, string $f) use ($edit, $posted) {
    if ($posted) return (string) ($_POST['t'][$lang]['items'][$i][$f] ?? '');
    static $cache = [];
    $ck = $lang;
    if (!isset($cache[$ck])) $cache[$ck] = wsm_section_items_decode((string) ($edit['i18n'][$lang]['items'] ?? ''));
    return (string) ($cache[$ck][$i][$f] ?? '');
};
$stylAkt = $posted ? (array) ($_POST['styl'] ?? []) : (array) ($edit['styl'] ?? []);
$ustAkt  = $posted ? (array) ($_POST['ustawienia'] ?? []) : (array) ($edit['ustawienia'] ?? []);
$pub     = $posted ? !empty($_POST['published']) : ($edit ? (int) $edit['published'] === 1 : true);
$img     = (string) ($edit['image_url'] ?? '');
$media   = wsm_media_list($pdo);
// Un choix se fait par nom : les fichiers nommés d'abord, par ordre naturel, les sans-nom à la fin.
usort($media, fn($a, $b) => [($a['title'] === '' ? 1 : 0), strnatcasecmp($a['label'], $b['label'])] <=> [($b['title'] === '' ? 1 : 0), 0] ?: strnatcasecmp($a['label'], $b['label']));

// La liste : l'accueil vient d'Układ (sections mêlées aux blocs natifs) ; les autres cibles, du Kreator.
$liste = [];
if (!$formulaire) {
    if ($celId === WSM_SECTION_HOME) $liste = wsm_layout_get($pdo, 'home');
    else foreach (wsm_section_list($pdo, $celId) as $s) $liste[] = [
        'k' => (string) (int) $s['id'], 'type' => 'sekcja', 'id' => (int) $s['id'], 'on' => (int) $s['published'] ? 1 : 0, 'published' => (int) $s['published'],
        'label' => ($types[$s['type']]['label'] ?? $s['type']) . ($s['title'] !== '' ? ': ' . $s['title'] : ''), 'fixed' => false, 'pin' => null,
        'styl' => $s['styl'], 'langs' => array_keys($s['i18n']),
    ];
}
$nSekcji = count(wsm_section_list($pdo, $celId));
$dostepne = array_filter($types, fn($d) => in_array($celKlucz, $d['cel'], true));

$css = <<<CSS
  .bud-cele { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 16px; }
  .bud-cele a { display: inline-block; padding: 6px 12px; border: 1px solid var(--border-subtle); border-radius: 999px; text-decoration: none; }
  .bud-cele a.akt { background: var(--accent-quiet); border-color: var(--accent); color: var(--text-strong); font-weight: 600; }
  .bud-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 20px; }
  @media (min-width: 1200px) { .bud-grid { grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); } }
  .bud-typy { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
  .bud-typ { display: block; border: 1px solid var(--border-subtle); border-radius: 10px; padding: 10px 12px; text-decoration: none; color: inherit; }
  .bud-typ:hover { border-color: var(--accent); background: var(--accent-quiet); }
  .bud-typ b { display: block; margin-bottom: 2px; } .bud-typ small { color: var(--text-muted); }
  .bud table td { vertical-align: middle; } .bud .strzalki form { display: inline; } .bud .strzalki button { min-width: 36px; }
  .bud .off td:first-child { color: var(--text-muted); } .bud .natywna td { color: var(--text-muted); }
  .bdg.on { background: #e3f1e3; color: #2f5d2f; } .bdg.off { background: var(--surface-sunken); color: var(--text-muted); }
  .bdg.stale { background: var(--accent-quiet); color: var(--text-strong); } .bdg.szkic { background: #fbe9e0; color: #8a3b1f; }
  .str-lang > summary { cursor: pointer; font-weight: 600; padding: 8px 0; }
  .str-lang textarea { font-family: var(--font-mono); font-size: 13px; line-height: 1.5; }
  .gram { font-size: 13px; color: var(--text-muted); } .gram code { font-size: 12px; }
  .poz { display: grid; grid-template-columns: 28px minmax(0, 1fr); gap: 6px 10px; align-items: start; padding: 8px 0; border-top: 1px dashed var(--border-subtle); }
  .poz .num { font-family: var(--font-mono); color: var(--text-muted); padding-top: 8px; }
  .poz .field { margin: 0; } .poz-pola { display: grid; gap: 6px; }
  @media (min-width: 900px) { .poz-pola.z-obrazem { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); } }
  .media-pick { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 10px; margin-top: 8px; }
  .media-pick figure { margin: 0; text-align: center; } .media-pick img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 8px; background: var(--paper-200); }
  .media-pick figcaption { font-size: 11px; line-height: 1.3; overflow-wrap: anywhere; margin-top: 4px; }
  .str-foto { display: block; max-width: 100%; max-height: 180px; border-radius: 10px; margin-bottom: 8px; }
  .podglad { width: 100%; height: 64vh; border: 1px solid var(--border-subtle); border-radius: 12px; background: #fff; }
  .inline-form { display: inline; }
CSS;

console_head('Kreator strony', $me, $css, (string) $nSekcji);
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Kreator strony' => ($formulaire || $celParam !== 'home') ? 'budowa.php' : null]
               + ($celParam !== 'home' || $formulaire ? [$celNom => $formulaire ? 'budowa.php?cel=' . h($celParam) : null] : [])
               + ($formulaire ? [($edit ? ($def['label'] ?? '') . ' #' . (int) $edit['id'] : 'Nowa: ' . ($def['label'] ?? '')) => null] : []));
?>

<p class="why">
  Tu <b>składa się</b> sklep z sekcji: zdjęcia, galerie, kafelki, teksty, banery z przyciskiem, pytania i odpowiedzi,
  cytaty, pasek ogłoszeń nad nagłówkiem. Każda sekcja ma gotowy, dopasowany do sklepu szablon — wpisuje się
  treść i wybiera zdjęcia, a wygląd (kolory, czcionki) idzie z <a href="wyglad.php">Wyglądu</a>.
  Katalog, koszyk i zamówienie zostają, jak są — buduje się <b>wokół</b> nich.
  <?php if (!$isAdmin): ?><em>Twoja rola tylko ogląda — zmienia Centrala.</em><?php endif; ?>
</p>

<nav class="bud-cele" aria-label="Gdzie">
  <a href="budowa.php?cel=home"<?= $celParam === 'home' ? ' class="akt"' : '' ?>>Strona główna</a>
  <a href="budowa.php?cel=site"<?= $celParam === 'site' ? ' class="akt"' : '' ?>>Cały sklep (pasek ogłoszeń)</a>
  <?php foreach ($strony as $p): ?>
  <a href="budowa.php?cel=<?= (int) $p['id'] ?>"<?= $celParam === (string) (int) $p['id'] ? ' class="akt"' : '' ?>><?= h($p['title'] !== '' ? $p['title'] : '#' . (int) $p['id']) ?><?= (int) $p['published'] ? '' : ' <small>(szkic)</small>' ?></a>
  <?php endforeach; ?>
  <a href="strony.php?nowa=strona" class="code">+ nowa strona</a>
</nav>

<?php if (!$formulaire): ?>
<div class="bud-grid">
  <div>
    <div class="panel bud">
      <h2><?= h($celNom) ?> <span class="code"><?= count($liste) ?></span></h2>
      <p class="why">
        <?php if ($celId === WSM_SECTION_HOME): ?>Od góry do dołu, dokładnie jak w sklepie. Elementy natywne (nagłówek, obietnice, katalog, oferta dla firm) i bloki ze Stron
          stoją w tej samej kolejce co sekcje — każdą można przesunąć, prawie każdą ukryć. Katalogu nie da się ukryć.
        <?php elseif ($celId === WSM_SECTION_SITE): ?>Pasek ogłoszeń pokazuje się nad nagłówkiem na każdej stronie sklepu — jedno zdanie i link. Kilka pasków stanie jeden pod drugim; zwykle wystarczy jeden.
        <?php else: ?>Sekcje tej strony pokazują się <b>pod</b> jej treścią ze Stron. Sama treść (tytuł, zajawka, tekst, zdjęcie) jest w <a href="strony.php?id=<?= (int) $celId ?>">Stronach</a>.<?php endif; ?>
      </p>
      <?php if (!$liste): ?>
      <p class="muted">Jeszcze nic. Dodaj pierwszą sekcję poniżej.</p>
      <?php else: ?>
      <table class="rwd">
        <thead><tr><th>#</th><th>Element</th><th>Stan</th><th>Przesuń</th><th></th></tr></thead>
        <tbody>
        <?php $n = count($liste); foreach ($liste as $i => $it): $pin = !empty($it['pin']); $sek = $it['type'] === 'sekcja'; ?>
          <tr class="<?= $it['on'] ? 'on' : 'off' ?><?= $sek ? '' : ' natywna' ?>">
            <td data-l="#" class="num"><?= $i + 1 ?></td>
            <td data-l="Element">
              <?php if ($sek): ?><a href="budowa.php?cel=<?= h($celParam) ?>&amp;id=<?= (int) $it['id'] ?>"><b><?= h(str_replace('Sekcja — ', '', $it['label'])) ?></b></a>
              <?php elseif ($it['type'] === 'blok'): ?><?= h($it['label']) ?> <a class="code" href="strony.php?id=<?= (int) $it['id'] ?>">edytuj</a>
              <?php else: ?><?= h($it['label']) ?> <small class="muted">— natywny</small><?php endif; ?>
              <?php if (($it['k'] ?? '') === 'pasek'): ?><br><small class="muted">w nagłówku — nie przesuwa się osobno</small><?php endif; ?>
              <?php if (!empty($it['langs'])): ?><br><small class="code"><?= h(implode(' ', $it['langs'])) ?></small><?php endif; ?>
            </td>
            <td data-l="Stan">
              <?php if (!empty($it['fixed'])): ?><span class="bdg stale">stałe</span>
              <?php elseif ($it['type'] !== 'builtin' && !$it['published']): ?><span class="bdg szkic">szkic</span>
              <?php else: ?><span class="bdg <?= $it['on'] ? 'on' : 'off' ?>"><?= $it['on'] ? 'widoczne' : 'ukryte' ?></span><?php endif; ?>
            </td>
            <td data-l="Przesuń" class="strzalki">
              <?php if ($isAdmin && !$pin): ?>
              <form method="post" class="inline-form"><?= console_csrf_field() ?><input type="hidden" name="cel" value="<?= h($celParam) ?>"><input type="hidden" name="k" value="<?= h($it['k']) ?>">
                <button class="btn sm ghost" name="przesun" value="up" title="wyżej"<?= $i === 0 || !empty($liste[$i - 1]['pin']) ? ' disabled' : '' ?>>↑</button>
                <button class="btn sm ghost" name="przesun" value="down" title="niżej"<?= $i === $n - 1 ? ' disabled' : '' ?>>↓</button>
              </form>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isAdmin && empty($it['fixed']) && ($it['type'] === 'builtin' || $sek || $it['published'])): ?>
              <form method="post" class="inline-form"><?= console_csrf_field() ?><input type="hidden" name="cel" value="<?= h($celParam) ?>">
                <button class="btn sm ghost" name="przelacz" value="<?= h($it['k']) ?>"><?= $it['on'] ? 'Ukryj' : 'Pokaż' ?></button>
              </form>
              <?php elseif ($it['type'] === 'blok' && !$it['published']): ?><small class="muted">opublikuj w Stronach</small><?php endif; ?>
              <?php if ($sek): ?> <a class="btn sm ghost" href="budowa.php?cel=<?= h($celParam) ?>&amp;id=<?= (int) $it['id'] ?>">Edytuj</a><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <?php if ($isAdmin): ?>
    <div class="panel" style="margin-top:20px">
      <h2>Dodaj sekcję</h2>
      <p class="why">Wybierz rodzaj — otworzy się formularz. Nowa sekcja staje na końcu<?= $celId === WSM_SECTION_HOME ? ' (za katalogiem)' : '' ?>; potem można ją przesunąć strzałkami.</p>
      <div class="bud-typy">
        <?php foreach ($dostepne as $k => $d): ?>
        <a class="bud-typ" href="budowa.php?cel=<?= h($celParam) ?>&amp;nowa=<?= h($k) ?>"><b>+ <?= h($d['label']) ?></b><small><?= h($d['opis']) ?></small></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Podgląd <a class="code" href="<?= h($celUrl) ?>" target="_blank" rel="noopener" style="font-weight:400">otwórz w nowej karcie ↗</a></h2>
    <p class="why">Prawdziwa strona sklepu, odświeżona po każdej zmianie.<?= $celPage && !(int) $celPage['published'] ? ' Ta strona jest szkicem — w sklepie odpowie 404, dopóki nie zostanie opublikowana w Stronach.' : '' ?></p>
    <iframe class="podglad" src="<?= h($celUrl) ?>?w=<?= time() ?>" title="Podgląd sklepu" loading="lazy"></iframe>
  </div>
</div>

<?php else: ?>
<p class="actions" style="margin:0 0 14px">
  <a class="btn ghost" href="budowa.php?cel=<?= h($celParam) ?>">← <?= h($celNom) ?></a>
  <?php if ($edit && (int) $edit['published'] === 1): ?>
  <a class="btn ghost" href="<?= h($celUrl) ?>#sek-<?= (int) $edit['id'] ?>" target="_blank" rel="noopener">Zobacz w sklepie ↗</a>
  <?php endif; ?>
</p>

<form method="post" enctype="multipart/form-data" class="bud-grid">
  <?= console_csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $editId ?>">
  <input type="hidden" name="cel" value="<?= h($celParam) ?>">
  <input type="hidden" name="type" value="<?= h($typ) ?>">
  <div>
    <div class="panel">
      <h2><?= h($def['label']) ?><?= $edit ? ' <span class="code">#' . (int) $edit['id'] . '</span>' : '' ?> <small class="muted">— <?= h($celNom) ?></small></h2>
      <p class="why"><?= h($def['opis']) ?></p>
      <div class="grid2">
        <label class="chk"><input type="checkbox" name="published" value="1"<?= $pub ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>><span><b>Widoczna</b> w sklepie (odznacz = szkic)</span></label>
        <span></span>
        <?php if ($def['styl']): ?>
        <div class="field"><span>Styl</span>
          <?php foreach ($def['styl'] as $s): ?>
          <label class="chk"><input type="checkbox" name="styl[]" value="<?= h($s) ?>"<?= in_array($s, $stylAkt, true) ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>><span><?= h($etStylu[$s] ?? $s) ?></span></label>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php foreach ($def['ustawienia'] as $uk => [$ulbl, $uopts]): ?>
        <label class="field"><span><?= h($ulbl) ?></span>
          <select name="ustawienia[<?= h($uk) ?>]"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php foreach ($uopts as $ov => $olbl): ?>
            <option value="<?= h((string) $ov) ?>"<?= (string) ($ustAkt[$uk] ?? array_key_first($uopts)) === (string) $ov ? ' selected' : '' ?>><?= h($olbl) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php endforeach; ?>
      </div>
      <?php if ($def['obraz']): ?>
      <label class="field" style="margin-top:12px"><span>Zdjęcie<?php if (isset($errs['image_url'])): ?> <em class="tag err">odrzucone</em><?php endif; ?></span>
        <?php if ($img !== ''): ?><img src="<?= h(img_src($img)) ?>" alt="" class="str-foto"><small class="code"><?= h($img) ?></small><?php endif; ?>
        <input type="file" name="obraz" accept="image/jpeg,image/png,image/webp"<?= $isAdmin ? '' : ' disabled' ?>>
        <small>Z komputera: JPEG · PNG · WebP, maks. 8 MB. Albo z medioteki:</small>
        <select name="obraz__z_medioteki"<?= $isAdmin ? '' : ' disabled' ?>>
          <option value="">— bez zmian —</option>
          <?php foreach ($media as $m): ?><option value="<?= h($m['url']) ?>"><?= h($m['label']) ?></option><?php endforeach; ?>
        </select>
        <?php if ($img !== ''): ?><label class="chk"><input type="checkbox" name="obraz__usun" value="1"><span>Usuń zdjęcie przy zapisie</span></label><?php endif; ?>
      </label>
      <?php endif; ?>
    </div>

    <div class="panel" style="margin-top:20px">
      <h2>Treść</h2>
      <?php if (in_array('body', $def['pola'], true) || ($def['items'] && isset($def['items']['pola']['d']))): ?>
      <p class="gram">
        W treści: <code>## Nagłówek</code>, <code>- punkt listy</code>, <code>**pogrubienie**</code>, <code>[tekst linku](adres)</code>,
        <code>![opis](media/plik.webp)</code>, <code>---</code> jako linia. Akapity oddzielaj pustą linią. HTML nie przechodzi — wyświetli się jako tekst.
      </p>
      <?php endif; ?>
      <?php if (!$def['pola'] && !$def['items']): ?><p class="muted">Ta sekcja nie ma tekstu — tylko styl i rozmiar powyżej.</p><?php endif; ?>
      <?php foreach ($langs as $l): $baza = $l === WSM_SECTION_BASE_LANG;
            $brak = !$baza && trim($txt($l, 'title') . $txt($l, 'lead') . $txt($l, 'body') . $poz($l, 0, 't') . $poz($l, 0, 'd')) === '';
            if (!$def['pola'] && !$def['items']) break; ?>
      <details class="str-lang"<?= $baza || !$brak ? ' open' : '' ?>>
        <summary><?= h($nomLang[$l] ?? strtoupper($l)) ?> <span class="code"><?= h($l) ?></span>
          <?php if ($baza): ?><small class="muted">— język bazowy, wymagany</small>
          <?php elseif ($brak): ?><small class="muted">— brak tłumaczenia: sklep pokaże polski</small><?php endif; ?></summary>
        <?php if (in_array('title', $def['pola'], true)): ?>
        <label class="field"><span>Tytuł<?php if ($baza && isset($errs['title'])): ?> <em class="tag err">wymagany</em><?php endif; ?></span>
          <input type="text" name="t[<?= h($l) ?>][title]" value="<?= h($txt($l, 'title')) ?>" maxlength="200"<?= $isAdmin ? '' : ' disabled' ?>></label>
        <?php endif; ?>
        <?php if (in_array('lead', $def['pola'], true)): ?>
        <label class="field"><span><?= $typ === 'cytat' ? 'Podpis (kto to powiedział)' : ($typ === 'foto' ? 'Podpis pod zdjęciem' : 'Zdanie pod tytułem') ?></span>
          <textarea name="t[<?= h($l) ?>][lead]" rows="2"<?= $isAdmin ? '' : ' disabled' ?>><?= h($txt($l, 'lead')) ?></textarea></label>
        <?php endif; ?>
        <?php if (in_array('body', $def['pola'], true)): ?>
        <label class="field"><span><?= $typ === 'cytat' ? 'Cytat' : ($typ === 'ogloszenie' ? 'Zdanie na pasku' : 'Treść') ?><?php if ($baza && isset($errs['body'])): ?> <em class="tag err"><?= h($errs['body']) ?></em><?php endif; ?></span>
          <textarea name="t[<?= h($l) ?>][body]" rows="<?= in_array($typ, ['cytat', 'ogloszenie'], true) ? 3 : 12 ?>" spellcheck="true"<?= $isAdmin ? '' : ' disabled' ?>><?= h($txt($l, 'body')) ?></textarea></label>
        <?php endif; ?>
        <?php if (in_array('cta', $def['pola'], true)): ?>
        <div class="grid2">
          <label class="field"><span><?= $typ === 'ogloszenie' ? 'Link — napis' : 'Przycisk — napis' ?></span>
            <input type="text" name="t[<?= h($l) ?>][cta_label]" value="<?= h($txt($l, 'cta_label')) ?>" maxlength="120" placeholder="np. Zobacz produkty"<?= $isAdmin ? '' : ' disabled' ?>></label>
          <label class="field"><span><?= $typ === 'ogloszenie' ? 'Link — adres' : 'Przycisk — adres' ?><?php if (isset($errs['cta_url'])): ?> <em class="tag err">odrzucony</em><?php endif; ?></span>
            <input type="text" name="t[<?= h($l) ?>][cta_url]" value="<?= h($txt($l, 'cta_url')) ?>" placeholder="np. #katalog, kontakt albo https://…"<?= $isAdmin ? '' : ' disabled' ?>></label>
        </div>
        <?php endif; ?>
        <?php if ($def['items']): $maxI = (int) $def['items']['max']; $zObrazem = !empty($def['items']['obraz']) && $baza; ?>
        <h3 style="margin:14px 0 4px"><?= $typ === 'faq' ? 'Pytania' : ($typ === 'galeria' ? 'Zdjęcia' : 'Kafelki') ?> <small class="muted">— do <?= $maxI ?>; puste pozycje się nie pokazują<?= $baza ? '' : '; puste pole = jak po polsku' ?></small>
          <?php if ($baza && isset($errs['items'])): ?> <em class="tag err"><?= h($errs['items']) ?></em><?php endif; ?></h3>
        <?php for ($i = 0; $i < $maxI; $i++): ?>
        <div class="poz">
          <span class="num"><?= $i + 1 ?></span>
          <div class="poz-pola<?= $zObrazem ? ' z-obrazem' : '' ?>">
            <div>
              <?php foreach ($def['items']['pola'] as $pk => $plbl): ?>
              <?php if ($pk === 'd'): ?>
              <label class="field"><span><?= h($plbl) ?></span><textarea name="t[<?= h($l) ?>][items][<?= $i ?>][d]" rows="<?= $typ === 'faq' ? 3 : 2 ?>"<?= $isAdmin ? '' : ' disabled' ?>><?= h($poz($l, $i, 'd')) ?></textarea></label>
              <?php else: ?>
              <label class="field"><span><?= h($plbl) ?></span><input type="text" name="t[<?= h($l) ?>][items][<?= $i ?>][<?= h($pk) ?>]" value="<?= h($poz($l, $i, $pk)) ?>" maxlength="200"<?= $isAdmin ? '' : ' disabled' ?>></label>
              <?php endif; ?>
              <?php endforeach; ?>
            </div>
            <?php if ($zObrazem): $akt = $poz($l, $i, 'img'); ?>
            <label class="field"><span><?= $typ === 'galeria' ? 'Zdjęcie' : 'Ikona / zdjęcie' ?></span>
              <?php if ($akt !== ''): ?><img src="<?= h(img_src($akt)) ?>" alt="" class="str-foto" style="max-height:72px"><?php endif; ?>
              <select name="t[<?= h($l) ?>][items][<?= $i ?>][img]"<?= $isAdmin ? '' : ' disabled' ?>>
                <option value="">— bez zdjęcia —</option>
                <?php foreach ($media as $m): ?><option value="<?= h($m['url']) ?>"<?= $m['url'] === $akt ? ' selected' : '' ?>><?= h($m['label']) ?></option><?php endforeach; ?>
                <?php if ($akt !== '' && !in_array($akt, array_column($media, 'url'), true)): ?><option value="<?= h($akt) ?>" selected><?= h($akt) ?></option><?php endif; ?>
              </select>
            </label>
            <?php endif; ?>
          </div>
        </div>
        <?php endfor; ?>
        <?php endif; ?>
      </details>
      <?php endforeach; ?>
      <?php if ($isAdmin): ?>
      <div class="actions" style="margin-top:14px">
        <button class="primary" type="submit" name="zapisz" value="1"><?= $edit ? 'Zapisz i pokaż' : 'Dodaj i pokaż' ?></button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="panel">
      <h2>Podgląd <a class="code" href="<?= h($celUrl) ?>" target="_blank" rel="noopener" style="font-weight:400">otwórz w nowej karcie ↗</a></h2>
      <?php if (!$edit): ?>
      <p class="why">Dodaj sekcję, a tu pojawi się prawdziwa strona sklepu przewinięta do niej.</p>
      <?php elseif ((int) $edit['published'] !== 1): ?>
      <p class="why">To szkic — sklep jeszcze jej nie pokazuje. Zaznacz „Widoczna” i zapisz, żeby zobaczyć ją w podglądzie.</p>
      <?php else: ?>
      <p class="why">Prawdziwa strona sklepu, nie makieta. Po zapisie ładuje się na nowo, przewinięta do tej sekcji.</p>
      <?php endif; ?>
      <iframe class="podglad" src="<?= h($celUrl) ?>?w=<?= time() ?><?= $edit ? '#sek-' . (int) $edit['id'] : '' ?>" title="Podgląd sklepu" loading="lazy"></iframe>
    </div>

    <?php if ($def['obraz'] || ($def['items'] && !empty($def['items']['obraz'])) || in_array('body', $def['pola'], true)): ?>
    <div class="panel" style="margin-top:20px">
      <h2>Medioteka</h2>
      <p class="why">Zdjęcia do wyboru w polach obok, po nazwie. Nowe pliki i nazwy: <a href="media.php">Media</a>; w treści wstawia się je przez <code>![opis](media/plik.webp)</code>.</p>
      <?php if (!$media): ?><p class="muted">Medioteka jest pusta — dodaj zdjęcia w <a href="media.php">Media</a>.</p>
      <?php else: ?>
      <div class="media-pick">
        <?php foreach (array_slice($media, 0, 24) as $m): ?>
        <figure><img src="<?= h(img_src($m['url'])) ?>" alt="" loading="lazy"><figcaption><b><?= h($m['label']) ?></b></figcaption></figure>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($edit && $isAdmin): ?>
    <details class="panel danger" style="margin-top:20px">
      <summary>Usuń sekcję</summary>
      <p class="why">Nieodwracalne. Żeby tylko schować ją na jakiś czas, odznacz „Widoczna” i zapisz.</p>
      <label class="chk"><input type="checkbox" name="na_pewno" value="1"><span>Tak, usuń</span></label>
      <div class="actions"><button type="submit" class="niebezpieczny" name="usun" value="<?= (int) $editId ?>" formnovalidate>Usuń</button></div>
    </details>
    <?php endif; ?>
  </div>
</form>
<?php endif; ?>

<?php console_foot();
