<?php
declare(strict_types=1);
/**
 * slajdy.php — les slajdy du haut de la page d'accueil.
 *
 * Ce que l'équipe a demandé : « 4-5 zdjęć, które co jakiś czas się
 * zmieniają, np. co 5 sekund », le choix de laisser tourner ou non, et des
 * NOUVELLES datées — « od … do 25 grudnia rabaty świąteczne ». Ici : la
 * liste, l'ordre, ce qui est en ligne aujourd'hui, et le formulaire d'un
 * slajd (photo, textes en trois langues, lien, période).
 *
 * L'état se lit d'un coup d'œil : « teraz » (en ligne), « od 01.12 »
 * (programmé), « zakończony » (période finie), « ukryty ». Un slajd programmé
 * ou fini n'a pas besoin d'être caché à la main : la date fait le travail.
 * Centrala change ; les autres rôles regardent.
 */
require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/slajdy.php';
require_once $API . '/media.php';
require_once $API . '/delivery.php';   // wsm_audit

/** Une photo de slajd vue depuis la console : « media/… » et « assets/… » sont relatifs à la boutique. */
function slajd_img(string $url): string {
    if ($url === '' || preg_match('~^(https?:)?//~i', $url) || str_starts_with($url, '/')) return $url;
    return '../shop/' . $url;
}

/** « 2026-12-01 » → « 01.12.2026 », comme on l'écrit en Pologne. */
function slajd_data_pl(string $d): string {
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) ? "$m[3].$m[2].$m[1]" : $d;
}

$flash = ''; $kind = 'ok'; $errs = []; $form = null;
$qui = (string) ($me['nom'] ?? '');
$editId = (int) ($_GET['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    $co = (string) ($_POST['co'] ?? '');
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać slajdy.'; $kind = 'err';
    } elseif ($co === 'ustawienia') {
        $err = wsm_slajdy_ustawienia_zapisz($pdo, !empty($_POST['auto']), (int) ($_POST['sekundy'] ?? 0), $qui);
        if ($err === '') {
            wsm_audit($pdo, $qui, 'Slajdy', 'przewijanie: ' . (!empty($_POST['auto']) ? 'automatyczne co ' . (int) $_POST['sekundy'] . ' s' : 'ręczne'), 'Sklep');
            $flash = 'Zapisano. Strona główna już przewija w ten sposób.';
        } else { $flash = $err; $kind = 'err'; }
    } elseif ($co === 'przesun') {
        $id = (int) ($_POST['id'] ?? 0);
        if (wsm_slajd_przesun($pdo, $id, ($_POST['kierunek'] ?? '') === 'up' ? 'up' : 'down')) {
            wsm_audit($pdo, $qui, 'Slajdy', "slajd #$id przesunięty", 'Sklep');
            $flash = 'Przesunięto.';
        } else { $flash = 'Tego slajdu nie da się przesunąć w tę stronę.'; $kind = 'err'; }
    } elseif ($co === 'przelacz') {
        $id = (int) ($_POST['id'] ?? 0);
        if (wsm_slajd_przelacz($pdo, $id, $qui)) {
            wsm_audit($pdo, $qui, 'Slajdy', "slajd #$id pokaż/ukryj", 'Sklep');
            $flash = 'Zmieniono widoczność. Sklep już to pokazuje.';
        } else { $flash = 'Tego slajdu już nie ma.'; $kind = 'err'; }
    } elseif ($co === 'usun') {
        $id = (int) ($_POST['id'] ?? 0);
        if (empty($_POST['na_pewno'])) { $flash = 'Zaznacz „tak, usuń”, żeby usunąć slajd.'; $kind = 'err'; }
        elseif (wsm_slajd_usun($pdo, $id)) {
            wsm_audit($pdo, $qui, 'Slajdy', "slajd #$id usunięty", 'Sklep');
            $flash = 'Slajd usunięty. Zdjęcie zostaje w Mediach.';
            $editId = 0;
        } else { $flash = 'Tego slajdu już nie ma.'; $kind = 'err'; }
    } elseif ($co === 'zapisz') {
        $id = (int) ($_POST['id'] ?? 0);
        $in = [
            'image_url' => (string) ($_POST['image_url'] ?? ''),
            'link'      => (string) ($_POST['link'] ?? ''),
            'date_from' => (string) ($_POST['date_from'] ?? ''),
            'date_to'   => (string) ($_POST['date_to'] ?? ''),
            'active'    => !empty($_POST['active']),
            'teksty'    => (array) ($_POST['t'] ?? []),
        ];
        // La photo : un fichier envoyé, sinon un choix dans la médiathèque,
        // sinon celle d'avant. « Usuń » la retire — un slajd sans photo reste
        // possible s'il a un titre (fond chocolat).
        if (!empty($_POST['obraz__usun'])) {
            $in['image_url'] = '';
        } elseif (!empty($_FILES['obraz']['name'] ?? '')) {
            [$url, $err] = wsm_media_store($_FILES['obraz']);
            if ($err !== null) $errs['image_url'] = 'Zdjęcie: ' . $err;
            else $in['image_url'] = (string) $url;
        } elseif ((string) ($_POST['obraz__z_medioteki'] ?? '') !== '') {
            $in['image_url'] = (string) $_POST['obraz__z_medioteki'];
        }
        if (!$errs) {
            [$nid, $errs] = wsm_slajd_zapisz($pdo, $in, $id, $qui);
            if (!$errs) {
                wsm_audit($pdo, $qui, 'Slajdy', ($id > 0 ? "slajd #$nid zmieniony" : "slajd #$nid dodany"), 'Sklep');
                header('Location: slajdy.php?ok=' . ($id > 0 ? 'zmieniony' : 'dodany') . '#lista', true, 303);
                exit;
            }
        }
        $flash = 'Nie zapisano — popraw pola oznaczone na czerwono.'; $kind = 'err';
        $form = ['id' => $id] + $in;
        $editId = $id;
    }
}
if (isset($_GET['ok'])) $flash = $_GET['ok'] === 'zmieniony' ? 'Zapisano slajd. Strona główna już go pokazuje.' : 'Dodano slajd. Strona główna już go pokazuje (jeśli jest widoczny i w swoim okresie).';

$lista = wsm_slajdy_lista($pdo);
$ust = wsm_slajdy_ustawienia($pdo);
$dzis = wsm_slajdy_dzis();
$teraz = array_values(array_filter($lista, fn($s) => wsm_slajd_stan($s, $dzis) === 'teraz'));

// Le formulaire : celui qu'on vient d'envoyer (refusé), un slajd existant, ou un nouveau.
if ($form === null) {
    $form = ['id' => 0, 'image_url' => '', 'link' => '#katalog', 'date_from' => '', 'date_to' => '', 'active' => true, 'teksty' => []];
    foreach ($lista as $s) {
        if ($s['id'] === $editId) {
            $form = ['id' => $s['id'], 'image_url' => (string) $s['image_url'], 'link' => (string) $s['link'],
                     'date_from' => $s['date_from'], 'date_to' => $s['date_to'], 'active' => (bool) $s['active'], 'teksty' => $s['teksty']];
        }
    }
}
$media = wsm_media_list($pdo);
$jezyki = ['pl' => 'Polski', 'uk' => 'Українська', 'en' => 'English'];
$pola = ['eyebrow' => ['Nadtytuł (mały, nad tytułem)', 'np. MANUFAKTURA · MAŁE PARTIE'],
         'title' => ['Tytuł', 'np. Pewne składniki. Udane wypieki.'],
         'tekst' => ['Zdanie pod tytułem', ''],
         'cta_label' => ['Napis na przycisku', 'np. Zobacz produkty — puste = bez przycisku']];
$stany = ['teraz' => ['on', 'widoczny teraz'], 'plan' => ['plan', 'zaplanowany'], 'koniec' => ['off', 'zakończony'], 'ukryty' => ['off', 'ukryty']];

$css = <<<CSS
  .sl table td { vertical-align: middle; }
  .sl .strzalki form, .sl .inline-form { display: inline; } .sl .strzalki button { min-width: 36px; }
  .sl .mini { width: 120px; aspect-ratio: 16 / 7; object-fit: cover; border-radius: 8px; background: var(--brand-800, #2e160c); display: block; }
  .sl .mini--pusty { display: flex; align-items: center; justify-content: center; color: #f4e3c8; font-size: 11px; }
  .sl .bdg { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 13px; white-space: nowrap; }
  .sl .bdg.on { background: #e3f1e3; color: #2f5d2f; } .sl .bdg.off { background: var(--surface-sunken); color: var(--text-muted); }
  .sl .bdg.plan { background: #fff3d6; color: #7a5410; }
  .sl tr.off td:first-child { color: var(--text-muted); }
  .sl-form .grid2 { display: grid; gap: 0 18px; } @media (min-width: 760px) { .sl-form .grid2 { grid-template-columns: 1fr 1fr; } }
  .sl-form .foto { max-width: 360px; width: 100%; aspect-ratio: 16 / 7; object-fit: cover; border-radius: 10px; display: block; margin: 4px 0 8px; }
  .sl-form details { border: 1px solid var(--border-subtle); border-radius: 10px; padding: 10px 14px; margin: 10px 0; }
  .sl-form details > summary { cursor: pointer; font-weight: 600; }
  .sl-form .err-txt { color: var(--danger); font-size: 13px; display: block; margin-top: 4px; }
  .sl-teraz { font-size: 14px; }
  .podglad { width: 100%; height: 64vh; border: 1px solid var(--border-subtle); border-radius: 12px; background: #fff; }
CSS;

console_head('Slajdy', $me, $css);
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Układ strony' => 'uklad.php', 'Slajdy' => null]);
?>

<p class="why">
  Zdjęcia na górze strony głównej, które zmieniają się same (albo strzałkami). Każdy slajd ma zdjęcie, teksty w trzech językach,
  link przycisku i — jeśli to nowość — <b>okres</b>: np. od 1 do 24 grudnia. Poza okresem slajd znika sam, nie trzeba go chować.
  Gdy żaden slajd nie jest widoczny, strona pokazuje zwykły nagłówek z <a href="tresci.php">Treści</a>.
  <?php if (!$isAdmin): ?><em>Twoja rola tylko ogląda — zmienia Centrala.</em><?php endif; ?>
</p>

<div class="panel sl" id="przewijanie">
  <h2>Przewijanie</h2>
  <p class="sl-teraz">Dziś (<?= h(slajd_data_pl($dzis)) ?>) na stronie: <b><?= count($teraz) ?></b> <?= count($teraz) === 1 ? 'slajd' : (count($teraz) >= 2 && count($teraz) <= 4 ? 'slajdy' : 'slajdów') ?>.
    <?php if (count($teraz) < 2): ?><span class="muted">Przewijanie zaczyna się od dwóch widocznych slajdów.</span><?php endif; ?></p>
  <form method="post"><?= console_csrf_field() ?><input type="hidden" name="co" value="ustawienia">
    <label class="chk"><input type="checkbox" name="auto" value="1"<?= $ust['auto'] ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>><span><b>Zmieniaj slajdy automatycznie</b> — odznacz, a klient przełącza je sam strzałkami</span></label>
    <label class="field" style="max-width:260px;margin-top:10px"><span>Co ile sekund (<?= WSM_SLAJDY_CO_MIN ?>–<?= WSM_SLAJDY_CO_MAX ?>)</span>
      <input type="number" name="sekundy" min="<?= WSM_SLAJDY_CO_MIN ?>" max="<?= WSM_SLAJDY_CO_MAX ?>" value="<?= (int) $ust['co'] ?>"<?= $isAdmin ? '' : ' disabled' ?>>
    </label>
    <p class="hint muted" style="font-size:13px">Przewijanie zatrzymuje się, gdy klient najedzie na slajd myszką, i nie rusza u osób, które w systemie wyłączyły animacje.</p>
    <?php if ($isAdmin): ?><div class="actions"><button type="submit">Zapisz przewijanie</button></div><?php endif; ?>
  </form>
</div>

<div class="panel sl" id="lista" style="margin-top:20px">
  <h2>Slajdy <span class="code"><?= count($lista) ?></span></h2>
  <?php if (!$lista): ?>
  <p class="muted">Brak slajdów — strona pokazuje nagłówek z Treści. Dodaj pierwszy poniżej.</p>
  <?php else: ?>
  <table class="rwd">
    <thead><tr><th>#</th><th>Zdjęcie</th><th>Tytuł</th><th>Okres</th><th>Stan</th><th>Kolejność</th><th></th></tr></thead>
    <tbody>
    <?php $n = count($lista); foreach ($lista as $i => $s): $st = wsm_slajd_stan($s, $dzis); [$cls, $lbl] = $stany[$st]; ?>
      <tr class="<?= $st === 'teraz' ? 'on' : 'off' ?>">
        <td data-l="#" class="num"><?= $i + 1 ?></td>
        <td data-l="Zdjęcie"><?php if ($s['image_url'] !== ''): ?><img class="mini" src="<?= h(slajd_img((string) $s['image_url'])) ?>" alt="" loading="lazy"><?php else: ?><span class="mini mini--pusty">bez zdjęcia</span><?php endif; ?></td>
        <td data-l="Tytuł">
          <?= h(($s['teksty']['pl']['title'] ?? '') !== '' ? $s['teksty']['pl']['title'] : '(bez tytułu)') ?>
          <?php if ($s['link'] !== ''): ?><br><small class="code"><?= h((string) $s['link']) ?></small><?php endif; ?>
        </td>
        <td data-l="Okres">
          <?php if ($s['date_from'] === '' && $s['date_to'] === ''): ?><span class="muted">zawsze</span>
          <?php else: ?><?= $s['date_from'] !== '' ? 'od ' . h(slajd_data_pl($s['date_from'])) : '' ?> <?= $s['date_to'] !== '' ? 'do ' . h(slajd_data_pl($s['date_to'])) : '' ?><?php endif; ?>
        </td>
        <td data-l="Stan"><span class="bdg <?= h($cls) ?>"><?= h($lbl) ?></span></td>
        <td data-l="Kolejność" class="strzalki">
          <?php if ($isAdmin): ?>
          <form method="post" class="inline-form"><?= console_csrf_field() ?><input type="hidden" name="co" value="przesun"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button class="btn sm ghost" name="kierunek" value="up" title="wyżej"<?= $i === 0 ? ' disabled' : '' ?>>↑</button>
            <button class="btn sm ghost" name="kierunek" value="down" title="niżej"<?= $i === $n - 1 ? ' disabled' : '' ?>>↓</button>
          </form>
          <?php endif; ?>
        </td>
        <td>
          <a class="btn sm ghost" href="slajdy.php?id=<?= (int) $s['id'] ?>#formularz"><?= $isAdmin ? 'Edytuj' : 'Zobacz' ?></a>
          <?php if ($isAdmin): ?>
          <form method="post" class="inline-form"><?= console_csrf_field() ?><input type="hidden" name="co" value="przelacz"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button class="btn sm ghost" type="submit"><?= $s['active'] ? 'Ukryj' : 'Pokaż' ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="panel sl sl-form" id="formularz" style="margin-top:20px">
  <h2><?= $form['id'] > 0 ? 'Slajd #' . (int) $form['id'] : 'Nowy slajd' ?>
    <?php if ($form['id'] > 0): ?><a class="code" href="slajdy.php#formularz" style="font-weight:400">+ nowy slajd</a><?php endif; ?></h2>
  <?php if (isset($errs['id'])): ?><p class="err-txt"><?= h($errs['id']) ?></p><?php endif; ?>
  <form method="post" enctype="multipart/form-data"><?= console_csrf_field() ?>
    <input type="hidden" name="co" value="zapisz"><input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
    <input type="hidden" name="image_url" value="<?= h((string) $form['image_url']) ?>">

    <div class="field"><span>Zdjęcie</span>
      <?php if ($form['image_url'] !== ''): ?><img class="foto" src="<?= h(slajd_img((string) $form['image_url'])) ?>" alt=""><small class="code"><?= h((string) $form['image_url']) ?></small><?php endif; ?>
      <input type="file" name="obraz" accept="image/jpeg,image/png,image/webp"<?= $isAdmin ? '' : ' disabled' ?>>
      <small class="hint">Z komputera: JPEG · PNG · WebP, maks. 8 MB. Najlepiej poziome, ok. 1600 × 700 px — tekst stoi po lewej, zdjęcie widać po prawej. Albo z medioteki:</small>
      <select name="obraz__z_medioteki"<?= $isAdmin ? '' : ' disabled' ?>>
        <option value="">— bez zmian —</option>
        <?php foreach ($media as $m): ?><option value="<?= h($m['url']) ?>"><?= h($m['label']) ?></option><?php endforeach; ?>
      </select>
      <?php if ($form['image_url'] !== ''): ?><label class="chk"><input type="checkbox" name="obraz__usun" value="1"<?= $isAdmin ? '' : ' disabled' ?>><span>Usuń zdjęcie przy zapisie</span></label><?php endif; ?>
      <?php if (isset($errs['image_url'])): ?><span class="err-txt"><?= h($errs['image_url']) ?></span><?php endif; ?>
    </div>

    <div class="grid2">
      <label class="field"><span>Od (pierwszy dzień) — puste = od zaraz</span>
        <input type="date" name="date_from" value="<?= h((string) $form['date_from']) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
        <?php if (isset($errs['date_from'])): ?><span class="err-txt"><?= h($errs['date_from']) ?></span><?php endif; ?>
      </label>
      <label class="field"><span>Do (ostatni dzień) — puste = bez końca</span>
        <input type="date" name="date_to" value="<?= h((string) $form['date_to']) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
        <?php if (isset($errs['date_to'])): ?><span class="err-txt"><?= h($errs['date_to']) ?></span><?php endif; ?>
      </label>
    </div>
    <label class="field"><span>Link przycisku — #katalog, ścieżka sklepu (np. p/czekolada, kontakt) albo https://…</span>
      <input type="text" name="link" value="<?= h((string) $form['link']) ?>" maxlength="255"<?= $isAdmin ? '' : ' disabled' ?>>
      <?php if (isset($errs['link'])): ?><span class="err-txt"><?= h($errs['link']) ?></span><?php endif; ?>
    </label>
    <label class="chk"><input type="checkbox" name="active" value="1"<?= $form['active'] ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>><span><b>Widoczny</b> (w swoim okresie)</span></label>

    <?php foreach ($jezyki as $l => $nazwa): $t = (array) ($form['teksty'][$l] ?? []); $otwarte = $l === 'pl'
            || array_filter(array_keys($errs), fn($k) => str_starts_with((string) $k, "$l.")); ?>
    <details<?= $otwarte ? ' open' : '' ?>>
      <summary><?= h($nazwa) ?><?= $l === 'pl' ? '' : ' — puste pola biorą tekst polski' ?></summary>
      <?php foreach ($pola as $k => [$etykieta, $ph]): ?>
      <label class="field"><span><?= h($etykieta) ?> <small class="muted">(maks. <?= WSM_SLAJDY_MAX[$k] ?>)</small></span>
        <?php if ($k === 'tekst'): ?>
        <textarea name="t[<?= h($l) ?>][<?= h($k) ?>]" rows="2" maxlength="<?= WSM_SLAJDY_MAX[$k] ?>"<?= $isAdmin ? '' : ' disabled' ?>><?= h((string) ($t[$k] ?? '')) ?></textarea>
        <?php else: ?>
        <input type="text" name="t[<?= h($l) ?>][<?= h($k) ?>]" value="<?= h((string) ($t[$k] ?? '')) ?>" maxlength="<?= WSM_SLAJDY_MAX[$k] ?>" placeholder="<?= h($l === 'pl' ? $ph : '') ?>"<?= $isAdmin ? '' : ' disabled' ?>>
        <?php endif; ?>
        <?php if (isset($errs["$l.$k"])): ?><span class="err-txt"><?= h($errs["$l.$k"]) ?></span><?php endif; ?>
      </label>
      <?php endforeach; ?>
    </details>
    <?php endforeach; ?>
    <p class="hint muted" style="font-size:13px">Bez tytułu i nadtytułu slajd pokazuje samo zdjęcie na całą szerokość — dobre dla gotowej grafiki z napisem (np. baner świąteczny).</p>
    <?php if ($isAdmin): ?><div class="actions"><button type="submit"><?= $form['id'] > 0 ? 'Zapisz slajd' : 'Dodaj slajd' ?></button></div><?php endif; ?>
  </form>

  <?php if ($isAdmin && $form['id'] > 0): ?>
  <details style="margin-top:14px">
    <summary>Usuń ten slajd</summary>
    <form method="post"><?= console_csrf_field() ?><input type="hidden" name="co" value="usun"><input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
      <label class="chk"><input type="checkbox" name="na_pewno" value="1"><span>Tak, usuń (zdjęcie zostaje w Mediach)</span></label>
      <div class="actions"><button type="submit" formnovalidate>Usuń slajd</button></div>
    </form>
  </details>
  <?php endif; ?>
</div>

<div class="panel" style="margin-top:20px">
  <h2>Podgląd <a class="code" href="../shop/" target="_blank" rel="noopener" style="font-weight:400">otwórz w nowej karcie ↗</a></h2>
  <p class="why">Prawdziwa strona główna, odświeżona po każdej zmianie.</p>
  <iframe class="podglad" src="../shop/?w=<?= time() ?>" title="Podgląd sklepu" loading="lazy"></iframe>
</div>

<?php console_foot();
