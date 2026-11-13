<?php
declare(strict_types=1);
/**
 * wyglad.php — l'habit de la boutique, réglé sans toucher au code.
 *
 * L'équipe demandait un « panneau de modernisation » : changer la police, les
 * couleurs, la mise en page. Cet écran en fait la part sûre : cinq polices,
 * trois couleurs dont les nuances se calculent, la forme des coins, l'alignement
 * du haut de page, et deux blocs de l'accueil à montrer ou cacher. Chaque
 * réglage est un jeton du design system (theme.php) : la boutique change
 * d'habit, pas de structure, et tout ce qui a été testé reste testé.
 *
 * ON VOIT AVANT DE CROIRE. L'aperçu en bas est la VRAIE page d'accueil,
 * rechargée après chaque enregistrement — pas une maquette qui ressemblerait
 * au site. Et « Przywróć domyślne » ramène au design system en un geste :
 * rien de ce qu'on essaie ici n'est irréversible.
 *
 * Centrala écrit ; les autres regardent. Une couleur illisible est refusée
 * (voir theme.php) et l'écran dit pourquoi, au lieu de l'appliquer.
 */
require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/theme.php';
require_once $API . '/delivery.php';   // wsm_audit

$flash = ''; $kind = 'ok'; $refus = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać wygląd sklepu.'; $kind = 'err';
    } elseif (isset($_POST['przywroc'])) {
        if (empty($_POST['na_pewno'])) {
            $flash = 'Zaznacz „tak, przywróć”, żeby wrócić do wyglądu domyślnego.'; $kind = 'err';
        } else {
            $n = wsm_theme_reset($pdo);
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Wygląd sklepu', 'przywrócono domyślny (' . $n . ' ustawień)', 'Sklep');
            $flash = 'Przywrócono wygląd domyślny. Sklep wygląda jak w dniu startu.';
        }
    } elseif (isset($_POST['zapisz'])) {
        $zm = wsm_theme_save($pdo, $_POST, (string) ($me['nom'] ?? ''), $refus);
        if ($zm) {
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Wygląd sklepu', implode(', ', $zm), 'Sklep');
        }
        if ($refus) {
            $flash = ($zm ? 'Zapisano: ' . implode(', ', $zm) . '. ' : '')
                   . 'NIE zapisano — ' . implode('; ', $refus) . '.';
            $kind = 'err';
        } elseif ($zm) {
            $flash = 'Zapisano: ' . implode(', ', $zm) . '. Podgląd poniżej pokazuje sklep po zmianie.';
        } else {
            $flash = 'Nic się nie zmieniło.';
        }
    }
}

$t      = wsm_theme_get($pdo, true);
$pola   = wsm_theme_fields();
$fonts  = wsm_theme_fonts();
$dom    = wsm_theme_is_default($t);
$zmien  = 0;
foreach (wsm_theme_defaults() as $k => $d) if (strcasecmp((string) $t[$k], $d) !== 0) $zmien++;

// Les échantillons de police sont RÉELS : les @font-face du design system de
// la boutique (ds.php), pas seulement ceux de la console — chez MyCobotBuddy,
// Inter et Archivo ne sont déclarées que là.
$dsFonts = '../shop/' . wsm_ds_path('fonts.css');
$css = "@import url('" . $dsFonts . "');\n" . <<<CSS
  .wyg-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 20px; }
  @media (min-width: 1100px) { .wyg-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); } }
  .wyg-fonty { display: grid; gap: 8px; margin: 6px 0 14px; }
  .wyg-font { display: flex; align-items: center; gap: 12px; border: 1px solid var(--border-subtle);
              border-radius: 10px; padding: 8px 12px; cursor: pointer; }
  .wyg-font:has(input:checked) { border-color: var(--accent); background: var(--accent-quiet); }
  .wyg-font input { margin: 0; }
  .wyg-font .probka { font-size: 20px; line-height: 1.2; color: var(--text-strong); }
  .wyg-font small { display: block; color: var(--text-muted); font-size: 12px; }
  .wyg-kolor { display: flex; align-items: center; gap: 10px; }
  .wyg-kolor input[type=color] { width: 56px; height: 36px; padding: 2px; border: 1px solid var(--border-subtle);
                                 border-radius: 8px; background: #fff; cursor: pointer; }
  .wyg-kolor code { font-size: 12px; }
  .podglad { width: 100%; height: 72vh; border: 1px solid var(--border-subtle); border-radius: 12px; background: #fff; }
  .wyg-stan { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
  .wyg-stan.dom { background: var(--surface-sunken); color: var(--text-muted); }
  .wyg-stan.zm  { background: var(--accent-quiet); color: var(--text-strong); }
CSS;

console_head('Wygląd sklepu', $me, $css);
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Wygląd sklepu' => null]);
?>

<p class="why">
  Tu zmienia się <b>wygląd</b> sklepu bez dotykania kodu: czcionka, kolory, kształt narożników,
  układ nagłówka i to, które bloki strony głównej są widoczne. Teksty są w <a href="tresci.php">Treściach</a>,
  zdjęcie nagłówka i ikony obietnic w <a href="ustawienia.php">Integracjach → Sklep</a>.
  Każda zmiana jest widoczna od razu i odwracalna: „Przywróć domyślne” wraca do projektu graficznego
  z dnia startu.
</p>
<p class="why">
  Stan: <?php if ($dom): ?><span class="wyg-stan dom">wygląd domyślny</span>
  <?php else: ?><span class="wyg-stan zm">zmieniony — <?= $zmien ?> <?= $zmien === 1 ? 'ustawienie' : ($zmien < 5 ? 'ustawienia' : 'ustawień') ?></span><?php endif; ?>
  <?php if (!$isAdmin): ?> · <em>Twoja rola tylko ogląda — zmienia Centrala.</em><?php endif; ?>
</p>

<form method="post" class="wyg-grid">
  <?= console_csrf_field() ?>

  <div class="panel">
    <h2>Czcionka</h2>
    <p class="why">Próbki poniżej są pisane prawdziwymi czcionkami — tak będzie wyglądał sklep.
      Domyślnie (styl Apple): Mulish wszędzie, nagłówki pogrubione. Lora i Playfair Display to poprzedni wygląd. Druga rodzina do nagłówków ma sens
      tylko wtedy, gdy wyraźnie kontrastuje z tekstem.</p>

    <label class="field"><span><?= h($pola['font_body'][0]) ?></span></label>
    <div class="wyg-fonty">
      <?php foreach ($fonts as $k => $f): ?>
      <label class="wyg-font">
        <input type="radio" name="font_body" value="<?= h($k) ?>"<?= $t['font_body'] === $k ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>>
        <span><span class="probka" style="font-family: <?= h($f[1]) ?>"><?= h(wsm_brand_name()) ?> — czekolada, cukry i mąki dla cukierni</span>
          <small><?= h($f[2]) ?></small></span>
      </label>
      <?php endforeach; ?>
    </div>
    <small class="muted"><?= h($pola['font_body'][4]) ?></small>

    <label class="field" style="margin-top:16px"><span><?= h($pola['font_head'][0]) ?></span></label>
    <div class="wyg-fonty">
      <label class="wyg-font">
        <input type="radio" name="font_head" value="same"<?= $t['font_head'] === 'same' ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>>
        <span><span class="probka">Taka sama jak tekst</span><small>Jedna rodzina do wszystkiego — spokojnie, bez kontrastu.</small></span>
      </label>
      <?php foreach ($fonts as $k => $f): ?>
      <label class="wyg-font">
        <input type="radio" name="font_head" value="<?= h($k) ?>"<?= $t['font_head'] === $k ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>>
        <span><span class="probka" style="font-family: <?= h($f[1]) ?>; font-weight: 700">Nasze czekolady</span>
          <small><?= h($f[2]) ?></small></span>
      </label>
      <?php endforeach; ?>
    </div>
    <small class="muted"><?= h($pola['font_head'][4]) ?></small>
  </div>

  <div>
    <div class="panel">
      <h2>Kolory</h2>
      <p class="why">Trzy kolory, reszta odcieni liczy się z nich. Kolor nieczytelny zostaje odrzucony,
        a nie „zastosowany i poprawiony”: marka musi być ciemna, tło jasne, akcent dość mocny, żeby unieść biały napis.</p>
      <div class="grid2">
        <?php foreach (['brand', 'accent', 'bg'] as $k): $f = $pola[$k]; ?>
        <label class="field">
          <span><?= h($f[0]) ?><?php if (isset($refus[$k])): ?> <em class="tag err">odrzucone</em><?php endif; ?></span>
          <span class="wyg-kolor">
            <input type="color" name="<?= h($k) ?>" value="<?= h($t[$k]) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
            <code><?= h($t[$k]) ?></code>
          </span>
          <small><?= h($f[4]) ?></small>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="panel" style="margin-top:20px">
      <h2>Kształt i nagłówek</h2>
      <p class="why">Kolejność sekcji, pokazywanie i ukrywanie bloków, menu i stopka: <a href="uklad.php">Układ strony</a>.</p>
      <div class="grid2">
        <?php foreach (['radius', 'hero_align'] as $k): $f = $pola[$k]; ?>
        <label class="field">
          <span><?= h($f[0]) ?></span>
          <select name="<?= h($k) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php foreach ($f[3] as $v => $lbl): ?>
            <option value="<?= h((string) $v) ?>"<?= (string) $t[$k] === (string) $v ? ' selected' : '' ?>><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
          <small><?= h($f[4]) ?></small>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($isAdmin): ?>
    <div class="actions" style="margin-top:16px">
      <button class="primary" type="submit" name="zapisz" value="1">Zapisz i pokaż</button>
    </div>
    <details style="margin-top:14px">
      <summary>Przywróć wygląd domyślny</summary>
      <p class="why">Usuwa wszystkie powyższe zmiany. Sklep wraca do projektu z dnia startu — teksty, zdjęcia i produkty zostają.</p>
      <label class="chk"><input type="checkbox" name="na_pewno" value="1"><span>Tak, przywróć</span></label>
      <div class="actions"><button type="submit" name="przywroc" value="1" formnovalidate>Przywróć domyślne</button></div>
    </details>
    <?php endif; ?>
  </div>
</form>

<div class="panel" style="margin-top:20px">
  <h2>Podgląd <a class="code" href="../shop/" target="_blank" rel="noopener" style="font-weight:400">otwórz w nowej karcie ↗</a></h2>
  <p class="why">To prawdziwa strona główna sklepu, nie makieta. Po „Zapisz i pokaż” ładuje się na nowo.</p>
  <iframe class="podglad" src="../shop/?w=<?= time() ?>" title="Podgląd sklepu" loading="lazy"></iframe>
</div>

<?php console_foot();
