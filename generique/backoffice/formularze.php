<?php
declare(strict_types=1);
/**
 * formularze.php — construire des formulaires publics et lire ce qu'ils
 * reçoivent.
 *
 * On part d'un modèle (hurt B2B, próbki, warsztaty, prezenty firmowe), on
 * l'ajuste, on l'active : il vit à /f/<adresse> sur la boutique, et chaque
 * réponse arrive ici ET dans Poczta. Un modèle créé est INACTIF : on le
 * relit avant qu'un client ne le voie.
 */
require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/formularze.php';
require_once $API . '/shop.php';
require_once $API . '/delivery.php';   // wsm_audit
wsm_forms_ensure($pdo);

$qui = (string) ($me['nom'] ?? '');
$flash = ''; $kind = 'ok'; $err = []; $brouillon = null;
$id = (int) ($_GET['id'] ?? 0);
$tryb = (string) ($_GET['tryb'] ?? 'odpowiedzi');

// L'export CSV : en GET, sans gabarit autour.
if ($id > 0 && isset($_GET['csv'])) {
    $f = wsm_form_get($pdo, $id);
    if (!$f) { http_response_code(404); exit('Nie ma takiego formularza.'); }
    wsm_audit($pdo, $qui, 'Formularze', 'eksport CSV „' . $f['nazwa'] . '”', 'Sieć');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="formularz-' . $f['slug'] . '-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    echo wsm_form_csv($f, wsm_form_entries($pdo, $id, '', 2000));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) { $flash = 'Tylko do odczytu.'; $kind = 'err'; }
    elseif (isset($_POST['szablon'])) {
        [$nid, $e] = wsm_form_from_szablon($pdo, (string) $_POST['szablon']);
        if ($e) { $flash = implode(' ', $e); $kind = 'err'; }
        else {
            wsm_audit($pdo, $qui, 'Formularze', 'nowy z szablonu #' . $nid, 'Sieć');
            header('Location: formularze.php?id=' . $nid . '&tryb=edycja&ok=' . rawurlencode('Formularz utworzony — jest NIEAKTYWNY. Przeczytaj, popraw i zaznacz „Aktywny”.'), true, 303); exit;
        }
    } elseif (isset($_POST['zapisz'])) {
        $pid = (int) ($_POST['id'] ?? 0);
        $in = $_POST;
        $in['pola'] = array_values((array) ($_POST['pola'] ?? []));
        [$nid, $err] = wsm_form_save($pdo, $pid ?: null, $in);
        if ($err) { $brouillon = $in; $id = $pid; $tryb = 'edycja'; $flash = implode(' ', $err); $kind = 'err'; }
        else {
            wsm_audit($pdo, $qui, 'Formularze', 'zapisano #' . $nid, 'Sieć');
            header('Location: formularze.php?id=' . $nid . '&tryb=edycja&ok=' . rawurlencode('Zapisano.'), true, 303); exit;
        }
    } elseif (isset($_POST['status'])) {
        // Un formulaire PAR LIGNE : Entrée dans la note d'une ligne enregistre
        // cette ligne-là, pas la première du tableau.
        $eid = (int) $_POST['status'];
        wsm_form_entry_status($pdo, $eid, (string) ($_POST['nowy_status'] ?? 'nowe'), (string) ($_POST['notatka'] ?? ''));
        $flash = 'Zapisano status.';
    } elseif (isset($_POST['usun_wpis'])) {
        $flash = wsm_form_entry_delete($pdo, (int) $_POST['usun_wpis']) ? 'Zgłoszenie usunięte (wiadomość w Poczcie zostaje).' : 'Nie ma takiego zgłoszenia.';
        wsm_audit($pdo, $qui, 'Formularze', 'usunięto zgłoszenie #' . (int) $_POST['usun_wpis'], 'Sieć');
    } elseif (isset($_POST['usun_formularz'])) {
        if (empty($_POST['na_pewno'])) { $flash = 'Zaznacz „tak, usuń”, żeby usunąć formularz razem z odpowiedziami.'; $kind = 'err'; $tryb = 'edycja'; }
        else {
            wsm_form_delete($pdo, (int) $_POST['usun_formularz']);
            wsm_audit($pdo, $qui, 'Formularze', 'usunięto formularz #' . (int) $_POST['usun_formularz'], 'Sieć');
            header('Location: formularze.php?ok=' . rawurlencode('Formularz usunięty.'), true, 303); exit;
        }
    }
}
if (isset($_GET['ok'])) $flash = (string) $_GET['ok'];

$shop = wsm_shop_base_url();
$form = $id > 0 ? wsm_form_get($pdo, $id) : null;

console_head('Formularze', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 14px; margin: 0 0 16px; max-width: 88ch; line-height: 1.6; }
  .szablony { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 12px; }
  .szablon { border: 1px solid var(--border-subtle); border-radius: 14px; padding: 14px; background: var(--surface-card); display: flex; flex-direction: column; gap: 8px; }
  .szablon b { color: var(--text-strong); }
  .szablon p { margin: 0; color: var(--text-muted); font-size: 14px; flex: 1; }
  .zakl { display: flex; gap: 6px; margin: 0 0 16px; flex-wrap: wrap; }
  .zakl a { padding: 7px 14px; border-radius: 999px; border: 1px solid var(--border-default); text-decoration: none; color: var(--text-body); }
  .zakl a[aria-current="page"] { background: var(--text-strong); color: var(--surface-card); border-color: var(--text-strong); }
  table.pola input[type=text], table.pola select { width: 100%; }
  table.pola td { vertical-align: top; }
  .wpis dl { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 2px 12px; margin: 0; font-size: 14px; }
  .wpis dt { color: var(--text-muted); } .wpis dd { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
  code.url { word-break: break-all; }
CSS);
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Marketing' => null, 'Formularze' => $form ? 'formularze.php' : null] + ($form ? [(string) $form['nazwa'] => null] : []));

if (!$form && $tryb !== 'edycja'):
  $lista = wsm_forms_list($pdo); ?>
<p class="hint">Formularze na stronie sklepu: współpraca hurtowa, próbki dla cukierni, zapisy na warsztaty, prezenty firmowe. Każde zgłoszenie trafia tutaj
  i do <a href="poczta.php">Poczty</a>; klient może przy okazji zapisać się do newslettera (z potwierdzeniem mailowym). Chronione przed spamem tak samo jak formularz kontaktowy.</p>
<div class="panel"><h2>Formularze</h2>
  <div class="tablewrap"><table class="rwd">
    <thead><tr><th>Nazwa</th><th>Adres</th><th>Stan</th><th class="num">Nowe</th><th class="num">Wszystkie</th><th></th></tr></thead>
    <tbody>
    <?php if (!$lista): ?><tr><td colspan="6" class="muted">Jeszcze żadnego — zacznij od szablonu poniżej.</td></tr><?php endif; ?>
    <?php foreach ($lista as $f): ?>
      <tr><td data-l="Nazwa"><a href="formularze.php?id=<?= (int) $f['id'] ?>"><?= h((string) $f['nazwa']) ?></a></td>
        <td data-l="Adres"><a href="<?= h($shop . '/f/' . $f['slug']) ?>" target="_blank" rel="noopener"><code>/f/<?= h((string) $f['slug']) ?></code> ↗</a></td>
        <td data-l="Stan"><span class="tag <?= (int) $f['aktywny'] ? 'ok' : 'wait' ?>"><?= (int) $f['aktywny'] ? 'aktywny' : 'nieaktywny' ?></span></td>
        <td data-l="Nowe" class="num"><?= (int) $f['nowe'] ? '<b>' . (int) $f['nowe'] . '</b>' : '0' ?></td>
        <td data-l="Wszystkie" class="num"><?= (int) $f['razem'] ?></td>
        <td><a href="formularze.php?id=<?= (int) $f['id'] ?>&amp;tryb=edycja">Edytuj ›</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php if ($isAdmin): ?>
<div class="panel"><h2>Nowy formularz z szablonu</h2>
  <form method="post" class="szablony"><?= console_csrf_field() ?>
    <?php foreach (wsm_form_szablony() as $k => $t): ?>
    <div class="szablon"><b><?= h($t['nazwa']) ?></b><p><?= h($t['wstep']) ?></p>
      <button type="submit" name="szablon" value="<?= h($k) ?>">Utwórz</button></div>
    <?php endforeach; ?>
  </form>
</div>
<?php endif; ?>

<?php elseif ($tryb === 'edycja' || !$form):
  $v = $brouillon ?? ($form ? ['id' => $form['id'], 'nazwa' => $form['nazwa'], 'slug' => $form['slug'], 'wstep' => $form['wstep'],
        'podziekowanie' => $form['podziekowanie'], 'powiadom' => $form['powiadom'], 'newsletter' => (int) $form['newsletter'],
        'aktywny' => (int) $form['aktywny'], 'pola' => $form['pola_list']] : ['id' => 0, 'nazwa' => '', 'slug' => '', 'wstep' => '',
        'podziekowanie' => '', 'powiadom' => '', 'newsletter' => 1, 'aktywny' => 0, 'pola' => []]);
  $pola = array_values((array) $v['pola']);
  for ($i = 0; $i < 3; $i++) $pola[] = ['id' => '', 'typ' => 'text', 'etykieta' => '', 'wymagane' => false, 'opcje' => []];
  $dis = $isAdmin ? '' : ' disabled'; ?>
<?php if ($form): ?>
<nav class="zakl" aria-label="Widok"><a href="formularze.php?id=<?= (int) $form['id'] ?>">Zgłoszenia</a><a href="formularze.php?id=<?= (int) $form['id'] ?>&amp;tryb=edycja" aria-current="page">Edycja</a>
  <a href="<?= h($shop . '/f/' . $form['slug']) ?>" target="_blank" rel="noopener">Zobacz na stronie ↗</a></nav>
<?php endif; ?>
<form method="post" action="formularze.php?id=<?= (int) $v['id'] ?>&amp;tryb=edycja">
  <?= console_csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
  <div class="panel"><h2>Ustawienia</h2>
    <div class="grid2">
      <label class="field"><span>Nazwa (tytuł na stronie)</span><input type="text" name="nazwa" maxlength="120" value="<?= h((string) $v['nazwa']) ?>"<?= $dis ?>></label>
      <label class="field"><span>Adres: <?= h($shop) ?>/f/…</span><input type="text" name="slug" maxlength="60" value="<?= h((string) $v['slug']) ?>"<?= $dis ?>></label>
    </div>
    <label class="field"><span>Wstęp (nad formularzem)</span><textarea name="wstep" rows="3"<?= $dis ?>><?= h((string) $v['wstep']) ?></textarea></label>
    <div class="grid2">
      <label class="field"><span>Podziękowanie po wysłaniu</span><input type="text" name="podziekowanie" maxlength="500" value="<?= h((string) $v['podziekowanie']) ?>"<?= $dis ?>></label>
      <label class="field"><span>Powiadomienie o nowym zgłoszeniu na adres (opcjonalnie)</span><input type="email" name="powiadom" maxlength="200" value="<?= h((string) $v['powiadom']) ?>" placeholder="pro@misterszoko.com"<?= $dis ?>></label>
    </div>
    <label class="chk" style="display:flex;gap:8px;align-items:center;margin:6px 0"><input type="checkbox" name="newsletter" value="1"<?= !empty($v['newsletter']) ? ' checked' : '' ?><?= $dis ?>> Pokaż zgodę na newsletter (niezaznaczoną — klient sam decyduje)</label>
    <label class="chk" style="display:flex;gap:8px;align-items:center;margin:6px 0"><input type="checkbox" name="aktywny" value="1"<?= !empty($v['aktywny']) ? ' checked' : '' ?><?= $dis ?>> <b>Aktywny</b> — widoczny na stronie i w mapie witryny</label>
  </div>
  <div class="panel"><h2>Pola</h2>
    <p class="hint">Wymagane jest pole typu „E-mail”. Zgoda na przetwarzanie danych (RODO) jest dodawana automatycznie. Pustą linię pomija się przy zapisie.
      Opcje listy wyboru rozdziel znakiem |.</p>
    <div class="tablewrap"><table class="pola">
      <thead><tr><th>Etykieta</th><th>Typ</th><th>Opcje (lista)</th><th>Wymagane</th></tr></thead>
      <tbody>
      <?php foreach ($pola as $i => $p): ?>
        <tr><td><input type="hidden" name="pola[<?= $i ?>][id]" value="<?= h((string) $p['id']) ?>">
              <input type="text" name="pola[<?= $i ?>][etykieta]" maxlength="120" value="<?= h((string) $p['etykieta']) ?>" aria-label="Etykieta pola <?= $i + 1 ?>"<?= $dis ?>></td>
          <td><select name="pola[<?= $i ?>][typ]" aria-label="Typ pola <?= $i + 1 ?>"<?= $dis ?>>
            <?php foreach (WSM_FORM_TYPY as $k => $l): ?><option value="<?= h($k) ?>"<?= $p['typ'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></td>
          <td><input type="text" name="pola[<?= $i ?>][opcje]" value="<?= h(implode(' | ', (array) $p['opcje'])) ?>" aria-label="Opcje pola <?= $i + 1 ?>"<?= $dis ?>></td>
          <td><input type="checkbox" name="pola[<?= $i ?>][wymagane]" value="1"<?= !empty($p['wymagane']) ? ' checked' : '' ?> aria-label="Pole <?= $i + 1 ?> wymagane"<?= $dis ?>></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($isAdmin): ?><p class="actions"><button class="btn btn--brand" type="submit" name="zapisz" value="1">Zapisz</button> <small class="muted">Po zapisie pojawią się 3 nowe puste linie na kolejne pola.</small></p><?php endif; ?>
  </div>
</form>
<?php if ($form && $isAdmin): ?>
<form method="post" class="panel"><?= console_csrf_field() ?>
  <h2>Usuń formularz</h2>
  <p class="hint">Usuwa formularz i wszystkie jego zgłoszenia (wiadomości w Poczcie zostają). Adres /f/<?= h((string) $form['slug']) ?> przestanie działać.</p>
  <label class="chk" style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="na_pewno" value="1"> tak, usuń</label>
  <p class="actions"><button type="submit" name="usun_formularz" value="<?= (int) $form['id'] ?>">Usuń formularz</button></p>
</form>
<?php endif; ?>

<?php else:
  $filtr = isset(WSM_FORM_STATUSY[(string) ($_GET['status'] ?? '')]) ? (string) $_GET['status'] : '';
  $wpisy = wsm_form_entries($pdo, (int) $form['id'], $filtr, 300); ?>
<nav class="zakl" aria-label="Widok"><a href="formularze.php?id=<?= (int) $form['id'] ?>" aria-current="page">Zgłoszenia</a><a href="formularze.php?id=<?= (int) $form['id'] ?>&amp;tryb=edycja">Edycja</a>
  <a href="<?= h($shop . '/f/' . $form['slug']) ?>" target="_blank" rel="noopener">Zobacz na stronie ↗</a>
  <a href="formularze.php?id=<?= (int) $form['id'] ?>&amp;csv=1">Pobierz CSV</a></nav>
<?php if (!(int) $form['aktywny']): ?><p class="warnbox">Formularz jest nieaktywny — klienci go nie widzą. Włącz go w zakładce Edycja.</p><?php endif; ?>
<p class="hint">Adres do wklejenia w post, mail albo stronę: <code class="url"><?= h($shop . '/f/' . $form['slug']) ?></code></p>
<form method="get" class="actions" style="margin-bottom:12px"><input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
  <label>Status: <select name="status" onchange="this.form.submit()"><option value="">wszystkie (bez spamu)</option>
    <?php foreach (WSM_FORM_STATUSY as $k => $l): ?><option value="<?= h($k) ?>"<?= $filtr === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
  <noscript><button type="submit">Filtruj</button></noscript></form>
<div class="panel">
  <h2>Zgłoszenia (<?= count($wpisy) ?>)</h2>
  <div class="tablewrap"><table class="rwd">
    <thead><tr><th>Kiedy</th><th>Dane</th><th>Status i notatka</th><th></th></tr></thead>
    <tbody>
    <?php if (!$wpisy): ?><tr><td colspan="4" class="muted">Brak zgłoszeń.</td></tr><?php endif; ?>
    <?php foreach ($wpisy as $e): $eid = (int) $e['id']; ?>
      <tr class="wpis"><td data-l="Kiedy"><?= h(substr((string) $e['created_at'], 0, 16)) ?><br><small class="muted"><?= h(strtoupper((string) $e['lang'])) ?><?= (int) $e['newsletter'] ? ' · newsletter' : '' ?></small></td>
        <td data-l="Dane"><dl><?php foreach ($form['pola_list'] as $p): $val = (string) ($e['dane_list'][$p['id']] ?? ''); if ($val === '') continue; ?>
          <dt><?= h((string) $p['etykieta']) ?></dt><dd><?= $p['typ'] === 'email' ? '<a href="mailto:' . h($val) . '">' . h($val) . '</a>' : ($p['typ'] === 'tel' ? '<a href="tel:' . h(preg_replace('/[^0-9+]/', '', $val)) . '">' . h($val) . '</a>' : h($val)) ?></dd>
        <?php endforeach; ?></dl></td>
        <td data-l="Status"><select name="nowy_status" form="w<?= $eid ?>" aria-label="Status"<?= $isAdmin ? '' : ' disabled' ?>>
          <?php foreach (WSM_FORM_STATUSY as $k => $l): ?><option value="<?= h($k) ?>"<?= $e['status'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
          <input type="text" name="notatka" form="w<?= $eid ?>" value="<?= h((string) $e['notatka']) ?>" maxlength="500" placeholder="Notatka" aria-label="Notatka"<?= $isAdmin ? '' : ' disabled' ?>></td>
        <td><?php if ($isAdmin): ?><form method="post" id="w<?= $eid ?>" class="actions" style="margin:0"><?= console_csrf_field() ?>
          <button type="submit" name="status" value="<?= $eid ?>">Zapisz</button>
          <button type="submit" name="usun_wpis" value="<?= $eid ?>" onclick="return confirm('Usunąć to zgłoszenie (RODO)?')">Usuń</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php console_foot();
