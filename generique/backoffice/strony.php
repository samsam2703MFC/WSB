<?php
declare(strict_types=1);
/**
 * strony.php — les PAGES et les BLOCS de la boutique : le troisième geste du CMS.
 *
 * Treści corrige ce que la boutique dit déjà, Wygląd change son habit. Ici on
 * AJOUTE : une page « O nas », une FAQ, une actualité — avec son adresse, dans
 * la barre du haut ou le pied de page si on veut ; ou un bloc avec une photo,
 * un texte et un bouton, posé à l'un des trois emplacements de l'accueil.
 * Dans les trois langues, avec repli sur le polonais là où une traduction
 * manque (pages.php).
 *
 * PAS D'ÉDITEUR RICHE, ET C'EST VOULU. Un champ qui accepte du HTML est une
 * porte d'entrée pour du script sur la vitrine, et un éditeur visuel produit
 * du HTML qu'on ne relit jamais. On écrit du texte avec six signes — ##, -,
 * **, [lien](adresse), ![image](media/…), --- — et l'aperçu à droite montre le
 * résultat tel que la boutique le rendra, avec la même fonction.
 *
 * L'image se dépose ici comme sur une fiche produit ; les autres images du
 * texte viennent de la médiathèque (Media), par leur adresse.
 */
require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/pages.php';
require_once $API . '/media.php';
require_once $API . '/delivery.php';   // wsm_audit
require_once $API . '/shop.php';       // wsm_shop_available_langs : les langues PUBLIÉES
require_once $API . '/layout.php';     // la position d'un bloc dans l'accueil

// Les langues du formulaire : celles que la boutique publie, plus celles
// où cette page a déjà un texte. Le registre en connaît huit ; en proposer
// huit pour un sklep qui en parle trois, c'est cinq panneaux vides à passer
// à chaque page.
$langs = wsm_shop_available_langs($pdo);
$editIdPourLangs = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($editIdPourLangs > 0) {
    $st = $pdo->prepare("SELECT lang FROM wsm_page_i18n WHERE page_id = ? AND title <> ''");
    try { $st->execute([$editIdPourLangs]); foreach ($st->fetchAll() ?: [] as $r) $langs[] = (string) $r['lang']; } catch (Throwable $e) {}
}
$langs = array_values(array_unique(array_merge([WSM_PAGE_BASE_LANG], $langs)));
usort($langs, fn($a, $b) => ($a === WSM_PAGE_BASE_LANG ? -1 : ($b === WSM_PAGE_BASE_LANG ? 1 : strcmp($a, $b))));
$nomLang = ['pl' => 'Polski', 'uk' => 'Українська', 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français'];

$flash = ''; $kind = 'ok'; $errs = [];
$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$nowa   = isset($_GET['nowa']) && in_array($_GET['nowa'], WSM_PAGE_KINDS, true) ? (string) $_GET['nowa'] : '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    $qui = (string) ($me['nom'] ?? '');
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać strony sklepu.'; $kind = 'err';
    } elseif (isset($_POST['zapisz'])) {
        $id = (int) ($_POST['id'] ?? 0) ?: null;
        $avant = $id ? wsm_page_get($pdo, $id) : null;
        if ($id && !$avant) { $flash = 'Takiej strony już nie ma.'; $kind = 'err'; }
        else {
            $in = $_POST;
            // L'IMAGE : comme sur une fiche produit — vide + aucun fichier =
            // on ne touche à rien ; la case « usuń » est un geste explicite.
            $in['image_url'] = (string) ($avant['image_url'] ?? '');
            $nouvelle = null;
            if (!empty($_POST['obraz__usun'])) {
                $in['image_url'] = '';
            } elseif (!empty($_FILES['obraz']['name'] ?? '')) {
                [$url, $err] = wsm_media_store($_FILES['obraz']);
                if ($err !== null) { $errs['image_url'] = 'Obraz: ' . $err; }
                else { $in['image_url'] = (string) $url; $nouvelle = (string) $url; }
            }
            if (!$errs) {
                $e2 = [];
                $sid = wsm_page_save($pdo, $id, $in, $qui, $e2);
                $errs = $e2;
                if ($sid !== null) {
                    $editId = $sid; $nowa = '';
                    wsm_audit($pdo, $qui, $id ? 'Zmiana strony' : 'Nowa strona',
                              (string) ($in['kind'] ?? 'strona') . ' #' . $sid . ' ' . (string) ($in['t'][WSM_PAGE_BASE_LANG]['title'] ?? ''), 'Sklep');
                    // L'ancienne image, remplacée ou retirée, est rangée si plus rien ne la cite.
                    $ancienne = (string) ($avant['image_url'] ?? '');
                    if ($ancienne !== '' && $ancienne !== $in['image_url'] && !isset(wsm_media_usages($pdo)[$ancienne])) wsm_media_delete($ancienne);
                    $flash = ($id ? 'Zapisano. ' : 'Utworzono. ')
                           . (!empty($in['published']) ? 'Strona jest opublikowana — widać ją w sklepie.' : 'To szkic: w sklepie jeszcze jej nie widać.');
                } elseif ($nouvelle !== null) {
                    wsm_media_delete($nouvelle);      // un refus ne laisse pas un fichier orphelin
                }
            }
            if ($errs) { $flash = 'Nie zapisano — ' . implode('; ', $errs) . '.'; $kind = 'err'; if ($id) $editId = $id; else $nowa = (string) ($in['kind'] ?? 'strona'); }
        }
    } elseif (isset($_POST['publikuj'])) {
        $id = (int) $_POST['publikuj'];
        $p = wsm_page_get($pdo, $id);
        if ($p) {
            $na = !empty($_POST['na']) ? 1 : 0;
            $pdo->prepare("UPDATE wsm_pages SET published = ?, updated_at = ?, updated_by = ? WHERE id = ?")
                ->execute([$na, date('Y-m-d H:i:s'), mb_substr($qui, 0, 120), $id]);
            wsm_audit($pdo, $qui, $na ? 'Publikacja strony' : 'Ukrycie strony', '#' . $id, 'Sklep');
            $flash = $na ? 'Opublikowano.' : 'Ukryto — strona została jako szkic.';
        }
    } elseif (isset($_POST['usun'])) {
        $id = (int) $_POST['usun'];
        if (empty($_POST['na_pewno'])) { $flash = 'Zaznacz „tak, usuń”, żeby usunąć stronę.'; $kind = 'err'; $editId = $id; }
        else {
            $p = wsm_page_delete($pdo, $id);
            if ($p) {
                wsm_audit($pdo, $qui, 'Usunięcie strony', '#' . $id . ' ' . (string) ($p['i18n'][WSM_PAGE_BASE_LANG]['title'] ?? ''), 'Sklep');
                $img = (string) ($p['image_url'] ?? '');
                if ($img !== '' && !isset(wsm_media_usages($pdo)[$img])) wsm_media_delete($img);
                $flash = 'Usunięto. Adres strony odpowiada teraz 404.';
            }
            $editId = 0;
        }
    }
}

$edit = $editId ? wsm_page_get($pdo, $editId) : null;
if ($editId && !$edit) { $flash = $flash ?: 'Takiej strony nie ma.'; $kind = 'err'; $editId = 0; }
$formulaire = $edit !== null || $nowa !== '';
$kindForm = $edit ? (string) $edit['kind'] : ($nowa ?: 'strona');

// Les valeurs du formulaire : ce qui vient d'être posté gagne sur la base EN CAS
// DE REFUS seulement ; après un enregistrement réussi, c'est la base qui parle
// (l'adresse fabriquée depuis le titre, par exemple, doit se voir tout de suite).
$refuse = isset($_POST['zapisz']) && $errs;
$val = function (string $k, $defaut = '') use ($edit, $refuse) {
    if ($refuse && array_key_exists($k, $_POST)) return (string) $_POST[$k];
    return $edit ? (string) ($edit[$k] ?? $defaut) : (string) $defaut;
};
$txt = function (string $lang, string $f) use ($edit, $refuse) {
    if ($refuse) return (string) ($_POST['t'][$lang][$f] ?? '');
    return (string) ($edit['i18n'][$lang][$f] ?? '');
};
$coche = function (string $k, bool $defaut = false) use ($edit, $refuse): bool {
    if ($refuse) return !empty($_POST[$k]);
    return $edit ? !empty($edit[$k]) : $defaut;
};

$liste = wsm_page_list($pdo);
$strony = array_values(array_filter($liste, fn($p) => $p['kind'] === 'strona'));
$bloki  = array_values(array_filter($liste, fn($p) => $p['kind'] === 'blok'));
$media  = wsm_media_list($pdo);
$sklep  = '../shop/';

$css = <<<CSS
  .str-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 20px; }
  @media (min-width: 1200px) { .str-grid { grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); } }
  .str-lang > summary { cursor: pointer; font-weight: 600; padding: 8px 0; }
  .str-lang textarea { font-family: var(--font-mono); font-size: 13px; line-height: 1.5; }
  .podglad-tresci { border: 1px dashed var(--border-subtle); border-radius: 12px; padding: 18px 20px; background: #fff; }
  .podglad-tresci h2 { font-size: 20px; margin: 14px 0 8px; } .podglad-tresci h3 { font-size: 16px; margin: 12px 0 6px; }
  .podglad-tresci p, .podglad-tresci li { line-height: 1.6; } .podglad-tresci img { max-width: 100%; height: auto; border-radius: 8px; }
  .podglad-tresci hr { border: 0; border-top: 1px solid var(--border-subtle); margin: 14px 0; }
  .gram { font-size: 13px; color: var(--text-muted); } .gram code { font-size: 12px; }
  .media-pick { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 10px; margin-top: 8px; }
  .media-pick figure { margin: 0; text-align: center; } .media-pick img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 8px; background: var(--paper-200); }
  .media-pick figcaption { font-size: 11px; line-height: 1.3; overflow-wrap: anywhere; margin-top: 4px; }
  .media-pick figcaption .code { font-size: 10px; word-break: break-all; }
  .str-foto { display: block; max-width: 100%; max-height: 180px; border-radius: 10px; margin-bottom: 8px; }
  .bdg.pub { background: #e3f1e3; color: #2f5d2f; } .bdg.szkic { background: var(--surface-sunken); color: var(--text-muted); }
  .inline-form { display: inline; }
CSS;

console_head('Strony i bloki', $me, $css, count($strony) . ' + ' . count($bloki));
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Strony i bloki' => $formulaire ? 'strony.php' : null]
               + ($formulaire ? [($edit ? ($edit['i18n'][WSM_PAGE_BASE_LANG]['title'] ?? '#' . $editId) : ($nowa === 'blok' ? 'Nowy blok' : 'Nowa strona')) => null] : []));
?>

<?php if (!$formulaire): ?>
<p class="why">
  <b>Strona</b> ma własny adres (np. <code>/o-nas</code>) i może wejść do górnego menu lub stopki.
  <b>Blok</b> nie ma adresu: wstawia się na stronę główną — zdjęcie, tekst, przycisk. Jego miejsce
  (i kolejność sekcji) ustawia się w <a href="uklad.php">Układzie strony</a>.
  Oba piszą się tak samo, w trzech językach; tam, gdzie brakuje tłumaczenia, sklep pokazuje polski.
  Szkic nie jest widoczny w sklepie, dopóki go nie opublikujesz.
</p>
<p class="actions" style="margin:0 0 16px">
  <?php if ($isAdmin): ?>
  <a class="btn" href="strony.php?nowa=strona">+ Nowa strona</a>
  <a class="btn" href="strony.php?nowa=blok">+ Nowy blok</a>
  <?php endif; ?>
  <a class="btn ghost" href="budowa.php">Kreator strony (sekcje)</a>
  <a class="btn ghost" href="media.php">Media (zdjęcia do treści)</a>
</p>

<div class="panel">
  <h2>Strony <span class="code"><?= count($strony) ?></span></h2>
  <?php if (!$strony): ?>
  <p class="why">Jeszcze żadnej. Dobre pierwsze strony: „O nas”, „Jak zamawiać”, „Dla firm”, „FAQ”.</p>
  <?php else: ?>
  <table class="rwd">
    <thead><tr><th>Tytuł</th><th>Adres</th><th>Stan</th><th>Gdzie</th><th>Języki</th><th>Zmieniono</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($strony as $p): $pub = (int) $p['published'] === 1; ?>
      <tr>
        <td data-l="Tytuł"><a href="strony.php?id=<?= (int) $p['id'] ?>"><b><?= h($p['title'] !== '' ? $p['title'] : '(bez tytułu)') ?></b></a></td>
        <td data-l="Adres"><?php if ($pub): ?><a class="code" href="<?= h($sklep . $p['slug']) ?>" target="_blank" rel="noopener">/<?= h((string) $p['slug']) ?> ↗</a>
          <?php else: ?><span class="code">/<?= h((string) $p['slug']) ?></span><?php endif; ?></td>
        <td data-l="Stan"><span class="bdg <?= $pub ? 'pub' : 'szkic' ?>"><?= $pub ? 'opublikowana' : 'szkic' ?></span></td>
        <td data-l="Gdzie"><?= (int) $p['in_nav'] ? 'menu ' : '' ?><?= (int) $p['in_footer'] ? 'stopka' : '' ?><?= !(int) $p['in_nav'] && !(int) $p['in_footer'] ? '<span class="muted">tylko z linku</span>' : '' ?></td>
        <td data-l="Języki"><span class="code"><?= h(implode(' ', $p['langs'])) ?></span></td>
        <td data-l="Zmieniono"><?= h(substr((string) $p['updated_at'], 0, 16)) ?></td>
        <td>
          <?php if ($isAdmin): ?>
          <form method="post" class="inline-form"><?= console_csrf_field() ?>
            <input type="hidden" name="na" value="<?= $pub ? '0' : '1' ?>">
            <button class="btn sm ghost" name="publikuj" value="<?= (int) $p['id'] ?>"><?= $pub ? 'Ukryj' : 'Opublikuj' ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="panel" style="margin-top:20px">
  <h2>Bloki na stronie głównej <span class="code"><?= count($bloki) ?></span></h2>
  <?php if (!$bloki): ?>
  <p class="why">Jeszcze żadnego. Blok to np. „Nowość sezonu” ze zdjęciem i przyciskiem do produktu, albo „Odwiedź nas we Wrocławiu”.</p>
  <?php else: ?>
  <table class="rwd">
    <thead><tr><th>Tytuł</th><th>Pozycja na stronie głównej</th><th>Stan</th><th>Styl</th><th>Języki</th><th></th></tr></thead>
    <tbody>
    <?php $pozycja = []; $home = wsm_layout_get($pdo, 'home');
          foreach ($home as $i => $it) if ($it['type'] === 'blok') $pozycja[$it['id']] = $i + 1;
          $poprzednik = function (int $id) use ($home): string {
              $prev = '';
              foreach ($home as $it) { if ($it['type'] === 'blok' && $it['id'] === $id) return $prev; $prev = $it['label']; }
              return $prev;
          }; ?>
    <?php foreach ($bloki as $p): $pub = (int) $p['published'] === 1; ?>
      <tr>
        <td data-l="Tytuł"><a href="strony.php?id=<?= (int) $p['id'] ?>"><b><?= h($p['title'] !== '' ? $p['title'] : '(bez tytułu)') ?></b></a></td>
        <td data-l="Pozycja"><?php if (isset($pozycja[(int) $p['id']])): ?>po: <?= h($poprzednik((int) $p['id'])) ?> <a class="code" href="uklad.php">zmień</a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td data-l="Stan"><span class="bdg <?= $pub ? 'pub' : 'szkic' ?>"><?= $pub ? 'widoczny' : 'szkic' ?></span></td>
        <td data-l="Styl"><span class="code"><?= h(str_replace(',', ' ', (string) ($p['styl'] ?? ''))) ?: '—' ?></span></td>
        <td data-l="Języki"><span class="code"><?= h(implode(' ', $p['langs'])) ?></span></td>
        <td>
          <?php if ($isAdmin): ?>
          <form method="post" class="inline-form"><?= console_csrf_field() ?>
            <input type="hidden" name="na" value="<?= $pub ? '0' : '1' ?>">
            <button class="btn sm ghost" name="publikuj" value="<?= (int) $p['id'] ?>"><?= $pub ? 'Ukryj' : 'Pokaż' ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php else: ?>
<?php $slugAktualny = $val('slug'); $img = $val('image_url'); $aImg = $img !== ''; $pub = $coche('published'); ?>
<p class="actions" style="margin:0 0 14px">
  <a class="btn ghost" href="strony.php">← Wszystkie strony i bloki</a>
  <?php if ($edit && (int) $edit['published'] === 1): ?>
  <a class="btn ghost" href="<?= h($kindForm === 'blok' ? $sklep . '#blok-' . (int) $edit['id'] : $sklep . (string) $edit['slug']) ?>" target="_blank" rel="noopener">Zobacz w sklepie ↗</a>
  <?php endif; ?>
  <?php if ($edit && $kindForm === 'strona'): ?>
  <a class="btn ghost" href="budowa.php?cel=<?= (int) $edit['id'] ?>">Sekcje tej strony (Kreator)</a>
  <?php endif; ?>
</p>

<form method="post" enctype="multipart/form-data" class="str-grid">
  <?= console_csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $editId ?>">
  <div>
    <div class="panel">
      <h2><?= $kindForm === 'blok' ? 'Blok' : 'Strona' ?><?= $edit ? ' <span class="code">#' . (int) $edit['id'] . '</span>' : '' ?></h2>
      <div class="grid2">
        <label class="field"><span>Rodzaj</span>
          <select name="kind"<?= $isAdmin ? '' : ' disabled' ?>>
            <option value="strona"<?= $kindForm === 'strona' ? ' selected' : '' ?>>Strona z własnym adresem</option>
            <option value="blok"<?= $kindForm === 'blok' ? ' selected' : '' ?>>Blok na stronie głównej</option>
          </select>
          <small>Zmiana rodzaju wymaga zapisu — pola poniżej dotyczą obu.</small>
        </label>
        <label class="field"><span>Kolejność</span>
          <input type="number" name="sort_order" min="0" max="9999" value="<?= h($val('sort_order', '100')) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
          <small>Mniejsza liczba = wyżej w menu / wcześniej na stronie.</small>
        </label>
        <label class="field"><span>Adres (slug)<?php if (isset($errs['slug'])): ?> <em class="tag err">odrzucony</em><?php endif; ?></span>
          <input type="text" name="slug" value="<?= h($slugAktualny) ?>" placeholder="np. o-nas — puste: z polskiego tytułu"<?= $isAdmin ? '' : ' disabled' ?>>
          <small>Tylko strona. Małe litery, cyfry, myślniki. Strona będzie pod <code>/sklep/<?= h($slugAktualny !== '' ? $slugAktualny : '…') ?></code>.</small>
        </label>
        <div class="field"><span>Styl bloku</span>
          <?php $stylAkt = $refuse ? (array) ($_POST['styl'] ?? []) : explode(',', (string) ($edit['styl'] ?? '')); ?>
          <?php foreach (WSM_PAGE_STYLE as $k => $lbl): ?>
          <label class="chk"><input type="checkbox" name="styl[]" value="<?= h($k) ?>"<?= in_array($k, $stylAkt, true) ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>><span><?= h($lbl) ?></span></label>
          <?php endforeach; ?>
          <small>Tylko blok. Miejsce bloku na stronie głównej: <a href="uklad.php">Układ strony</a>.</small>
        </div>
      </div>
      <div class="grid2" style="margin-top:6px">
        <label class="chk"><input type="checkbox" name="published" value="1"<?= $pub ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>><span><b>Opublikowane</b> — widoczne w sklepie</span></label>
        <span></span>
        <label class="chk"><input type="checkbox" name="in_nav" value="1"<?= $coche('in_nav') ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>><span>W górnym menu (tylko strona)</span></label>
        <label class="chk"><input type="checkbox" name="in_footer" value="1"<?= $coche('in_footer') ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>><span>W stopce (tylko strona)</span></label>
      </div>
      <label class="field" style="margin-top:12px"><span>Obraz<?php if (isset($errs['image_url'])): ?> <em class="tag err">odrzucony</em><?php endif; ?></span>
        <?php if ($aImg): ?><img src="<?= h(img_src($img)) ?>" alt="" class="str-foto"><?php endif; ?>
        <input type="file" name="obraz" accept="image/jpeg,image/png,image/webp"<?= $isAdmin ? '' : ' disabled' ?>>
        <?php if ($aImg): ?><label class="chk"><input type="checkbox" name="obraz__usun" value="1"><span>Usuń obraz przy zapisie</span></label><?php endif; ?>
        <small>Strona: duże zdjęcie pod tytułem. Blok: zdjęcie obok tekstu. JPEG · PNG · WebP, maks. 8 MB; puste pole = bez zmian.</small>
      </label>
    </div>

    <div class="panel" style="margin-top:20px">
      <h2>Treść</h2>
      <p class="gram">
        Sześć znaków i nic więcej: <code>## Nagłówek</code>, <code>### Mniejszy</code>, <code>- punkt listy</code>,
        <code>**pogrubienie**</code>, <code>[tekst linku](adres)</code>, <code>![opis](media/plik.webp)</code>, <code>---</code> jako linia.
        Akapity oddzielaj pustą linią. Adres linku: <code>https://…</code>, <code>/sklep/…</code> albo adres innej strony sklepu, np. <code>kontakt</code>.
        HTML nie przechodzi — wyświetli się jako tekst.
      </p>
      <?php foreach ($langs as $l): $brak = $l !== WSM_PAGE_BASE_LANG && trim($txt($l, 'title')) === ''; ?>
      <details class="str-lang"<?= $l === WSM_PAGE_BASE_LANG || !$brak ? ' open' : '' ?>>
        <summary><?= h($nomLang[$l] ?? strtoupper($l)) ?> <span class="code"><?= h($l) ?></span>
          <?php if ($l === WSM_PAGE_BASE_LANG): ?><small class="muted">— język bazowy, wymagany</small>
          <?php elseif ($brak): ?><small class="muted">— brak tłumaczenia: sklep pokaże polski</small><?php endif; ?></summary>
        <div class="grid2">
          <label class="field"><span>Tytuł<?php if ($l === WSM_PAGE_BASE_LANG && isset($errs['title'])): ?> <em class="tag err">wymagany</em><?php endif; ?></span>
            <input type="text" name="t[<?= h($l) ?>][title]" value="<?= h($txt($l, 'title')) ?>" maxlength="200"<?= $isAdmin ? '' : ' disabled' ?>></label>
          <label class="field"><span>Opis dla wyszukiwarek</span>
            <input type="text" name="t[<?= h($l) ?>][meta_desc]" value="<?= h($txt($l, 'meta_desc')) ?>" maxlength="300" placeholder="1–2 zdania; puste = zajawka"<?= $isAdmin ? '' : ' disabled' ?>></label>
        </div>
        <label class="field"><span>Zajawka (pod tytułem)</span>
          <textarea name="t[<?= h($l) ?>][lead]" rows="2"<?= $isAdmin ? '' : ' disabled' ?>><?= h($txt($l, 'lead')) ?></textarea></label>
        <label class="field"><span>Treść<?php if ($l === WSM_PAGE_BASE_LANG && isset($errs['body'])): ?> <em class="tag err"><?= h($errs['body']) ?></em><?php endif; ?></span>
          <textarea name="t[<?= h($l) ?>][body]" rows="14" spellcheck="true"<?= $isAdmin ? '' : ' disabled' ?>><?= h($txt($l, 'body')) ?></textarea></label>
        <div class="grid2">
          <label class="field"><span>Przycisk — napis</span>
            <input type="text" name="t[<?= h($l) ?>][cta_label]" value="<?= h($txt($l, 'cta_label')) ?>" maxlength="120" placeholder="np. Zobacz produkty"<?= $isAdmin ? '' : ' disabled' ?>></label>
          <label class="field"><span>Przycisk — adres<?php if (isset($errs['cta_url'])): ?> <em class="tag err">odrzucony</em><?php endif; ?></span>
            <input type="text" name="t[<?= h($l) ?>][cta_url]" value="<?= h($txt($l, 'cta_url')) ?>" placeholder="np. /sklep/#katalog albo kontakt"<?= $isAdmin ? '' : ' disabled' ?>></label>
        </div>
      </details>
      <?php endforeach; ?>
      <?php if ($isAdmin): ?>
      <div class="actions" style="margin-top:14px">
        <button class="primary" type="submit" name="zapisz" value="1"><?= $edit ? 'Zapisz' : 'Utwórz' ?></button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="panel">
      <h2>Podgląd <small class="muted">(po polsku, po zapisie)</small></h2>
      <?php $pl = $edit['i18n'][WSM_PAGE_BASE_LANG] ?? wsm_page_text_vide(); ?>
      <?php if (!$edit): ?>
      <p class="why">Zapisz, a tu pojawi się treść tak, jak wyświetli ją sklep — tą samą funkcją, nie makietą.</p>
      <?php else: ?>
      <div class="podglad-tresci">
        <?php if ($aImg): ?><img src="<?= h(img_src($img)) ?>" alt=""><?php endif; ?>
        <h1 style="font-size:24px;margin:10px 0 6px"><?= h($pl['title']) ?></h1>
        <?php if ($pl['lead'] !== ''): ?><p class="muted"><?= h($pl['lead']) ?></p><?php endif; ?>
        <?= wsm_page_render($pl['body'], fn(string $x) => $sklep . ltrim($x, '/')) ?>
        <?php if ($pl['cta_label'] !== '' && $pl['cta_url'] !== ''): ?>
        <p><span class="btn">→ <?= h($pl['cta_label']) ?></span> <small class="muted code"><?= h($pl['cta_url']) ?></small></p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="panel" style="margin-top:20px">
      <h2>Zdjęcia do treści</h2>
      <p class="why">Skopiuj adres i wstaw w treści: <code>![opis](media/plik.webp)</code>. Nowe pliki dodaje się w <a href="media.php">Media</a>.</p>
      <?php if (!$media): ?><p class="muted">Medioteka jest pusta.</p>
      <?php else: ?>
      <div class="media-pick">
        <?php foreach (array_slice($media, 0, 24) as $m): ?>
        <figure><img src="<?= h(img_src($m['url'])) ?>" alt="" loading="lazy"><figcaption><b><?= h($m['label']) ?></b><br><span class="code"><?= h($m['url']) ?></span></figcaption></figure>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($edit && $isAdmin): ?>
    <details class="panel danger" style="margin-top:20px">
      <summary>Usuń <?= $kindForm === 'blok' ? 'blok' : 'stronę' ?></summary>
      <p class="why">Nieodwracalne. Adres strony zacznie odpowiadać 404 — jeśli ktoś ją zalinkował, lepiej ukryć niż usuwać.</p>
      <label class="chk"><input type="checkbox" name="na_pewno" value="1"><span>Tak, usuń</span></label>
      <div class="actions"><button type="submit" class="niebezpieczny" name="usun" value="<?= (int) $editId ?>" formnovalidate>Usuń</button></div>
    </details>
    <?php endif; ?>
  </div>
</form>
<?php endif; ?>

<?php console_foot();
