<?php
declare(strict_types=1);
/**
 * uklad.php — l'ORDRE des choses, réglé sans ouvrir le code.
 *
 * Trois listes : les sections de la page d'accueil, les liens de la barre du
 * haut, ceux du pied de page. Chaque entrée monte, descend, se montre ou se
 * cache — d'un clic, sans JavaScript, et la boutique suit à la seconde. Les
 * blocs écrits dans Strony et les pages cochées « w menu » / « w stopce »
 * entrent dans ces listes tout seuls ; ici on ne règle que leur place.
 *
 * CE QUI NE SE CACHE PAS LE DIT. Le catalogue, le règlement et la politique
 * de confidentialité n'ont pas d'interrupteur : une boutique sans rayon n'est
 * plus une boutique, et les deux documents sont exigés dans le pied de page
 * par la loi et par l'opérateur de paiement. Plutôt qu'une case qui mettrait
 * la maison en faute, une mention « stałe ».
 *
 * « Przywróć » ramène une liste à son ordre d'origine : la préférence
 * s'efface, la vérité se recompose (layout.php). Centrala change ; les autres
 * rôles regardent.
 */
require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/pages.php';
require_once $API . '/layout.php';
require_once $API . '/delivery.php';   // wsm_audit

$flash = ''; $kind = 'ok';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    $qui = (string) ($me['nom'] ?? '');
    $co = (string) ($_POST['co'] ?? '');
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać układ sklepu.'; $kind = 'err';
    } elseif (!in_array($co, WSM_LAYOUT_LISTES, true)) {
        $flash = 'Nieznana lista.'; $kind = 'err';
    } elseif (isset($_POST['przesun'])) {
        $k = (string) $_POST['k'];
        $dir = $_POST['przesun'] === 'up' ? 'up' : 'down';
        if (wsm_layout_move($pdo, $co, $k, $dir, $qui)) {
            wsm_audit($pdo, $qui, 'Układ strony', "$co: $k " . ($dir === 'up' ? 'wyżej' : 'niżej'), 'Sklep');
            $flash = 'Przesunięto. Sklep już to pokazuje.';
        } else { $flash = 'Tego elementu nie da się przesunąć w tę stronę.'; $kind = 'err'; }
    } elseif (isset($_POST['przelacz'])) {
        $k = (string) $_POST['przelacz'];
        if (wsm_layout_toggle($pdo, $co, $k, $qui)) {
            wsm_audit($pdo, $qui, 'Układ strony', "$co: $k pokaż/ukryj", 'Sklep');
            $flash = 'Zmieniono widoczność. Sklep już to pokazuje.';
        } else { $flash = 'Ten element jest stały — nie da się go ukryć.'; $kind = 'err'; }
    } elseif (isset($_POST['przywroc'])) {
        if (empty($_POST['na_pewno'])) { $flash = 'Zaznacz „tak, przywróć”, żeby wrócić do kolejności domyślnej.'; $kind = 'err'; }
        else {
            wsm_layout_reset($pdo, $co);
            wsm_audit($pdo, $qui, 'Układ strony', "$co: przywrócono domyślny", 'Sklep');
            $flash = 'Przywrócono kolejność domyślną. Bloki i strony zostały — tylko ich kolejność wróciła do początkowej.';
        }
    }
}

$listy = [
    'home'   => ['Strona główna — sekcje od góry do dołu', 'Nagłówek jest zawsze pierwszy; pasek należy do nagłówka. Katalogu nie da się ukryć. Blok ukryty to blok nieopublikowany — w Stronach widać go jako szkic.'],
    'nav'    => ['Górne menu — linki od lewej do prawej', 'Na telefonie górne menu znika; to, co ma być dostępne zawsze, musi być też w stopce.'],
    'footer' => ['Stopka — linki', 'Regulamin i polityka prywatności są stałe: wymaga ich prawo i operator płatności. Strona ukryta tu to strona bez haczyka „w stopce”.'],
];

$css = <<<CSS
  .ukl table td { vertical-align: middle; }
  .ukl .strzalki form { display: inline; } .ukl .strzalki button { min-width: 36px; }
  .ukl .off td:first-child { color: var(--text-muted); }
  .ukl .bdg.on { background: #e3f1e3; color: #2f5d2f; } .ukl .bdg.off { background: var(--surface-sunken); color: var(--text-muted); }
  .ukl .bdg.stale { background: var(--accent-quiet); color: var(--text-strong); }
  .ukl .bdg.szkic { background: #fbe9e0; color: #8a3b1f; }
  .podglad { width: 100%; height: 64vh; border: 1px solid var(--border-subtle); border-radius: 12px; background: #fff; }
  .inline-form { display: inline; }
CSS;

console_head('Układ strony', $me, $css);
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Układ strony' => null]);
?>

<p class="why">
  Tu ustawia się <b>kolejność</b> sekcji strony głównej oraz linków w górnym menu i w stopce — i to, co widać, a co nie.
  Nowe sekcje (zdjęcia, galerie, kafelki, pytania, banery…) dodaje się w <a href="budowa.php">Kreatorze strony</a>;
  teksty są w <a href="tresci.php">Treściach</a>, kolory i czcionki w <a href="wyglad.php">Wyglądzie</a>,
  nowe strony w <a href="strony.php">Stronach</a>. Każda zmiana jest widoczna od razu i odwracalna.
  <?php if (!$isAdmin): ?><em>Twoja rola tylko ogląda — zmienia Centrala.</em><?php endif; ?>
</p>

<?php foreach ($listy as $co => [$tytul, $why]): $liste = wsm_layout_get($pdo, $co); $n = count($liste); ?>
<div class="panel ukl" id="<?= h($co) ?>">
  <h2><?= h($tytul) ?> <span class="code"><?= $n ?></span></h2>
  <p class="why"><?= h($why) ?></p>
  <table class="rwd">
    <thead><tr><th>#</th><th>Element</th><th>Stan</th><th>Przesuń</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($liste as $i => $it): $pin = !empty($it['pin']); ?>
      <tr class="<?= $it['on'] ? 'on' : 'off' ?>">
        <td data-l="#" class="num"><?= $i + 1 ?></td>
        <td data-l="Element">
          <?= h($it['label']) ?>
          <?php if ($it['type'] === 'sekcja'): ?> <a class="code" href="budowa.php?cel=home&amp;id=<?= (int) $it['id'] ?>">edytuj</a>
          <?php elseif ($it['type'] !== 'builtin'): ?> <a class="code" href="strony.php?id=<?= (int) $it['id'] ?>">edytuj</a><?php endif; ?>
          <?php if ($it['k'] === 'pasek'): ?><br><small class="muted">w nagłówku — nie przesuwa się osobno</small><?php endif; ?>
          <?php if ($it['k'] === 'hero'): ?> <a class="code" href="slajdy.php">slajdy</a><?php endif; ?>
        </td>
        <td data-l="Stan">
          <?php if ($it['fixed']): ?><span class="bdg stale">stałe</span>
          <?php elseif ($it['type'] !== 'builtin' && !$it['published']): ?><span class="bdg szkic">szkic</span>
          <?php else: ?><span class="bdg <?= $it['on'] ? 'on' : 'off' ?>"><?= $it['on'] ? 'widoczne' : 'ukryte' ?></span><?php endif; ?>
        </td>
        <td data-l="Przesuń" class="strzalki">
          <?php if ($isAdmin && !$pin): ?>
          <form method="post" class="inline-form"><?= console_csrf_field() ?><input type="hidden" name="co" value="<?= h($co) ?>"><input type="hidden" name="k" value="<?= h($it['k']) ?>">
            <button class="btn sm ghost" name="przesun" value="up" title="wyżej"<?= $i === 0 || !empty($liste[$i - 1]['pin']) ? ' disabled' : '' ?>>↑</button>
            <button class="btn sm ghost" name="przesun" value="down" title="niżej"<?= $i === $n - 1 ? ' disabled' : '' ?>>↓</button>
          </form>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($isAdmin && !$it['fixed'] && ($it['type'] === 'builtin' || $it['published'])): ?>
          <form method="post" class="inline-form"><?= console_csrf_field() ?><input type="hidden" name="co" value="<?= h($co) ?>">
            <button class="btn sm ghost" name="przelacz" value="<?= h($it['k']) ?>"><?= $it['on'] ? 'Ukryj' : 'Pokaż' ?></button>
          </form>
          <?php elseif ($it['type'] !== 'builtin' && !$it['published']): ?>
          <small class="muted">opublikuj w <?= $it['type'] === 'sekcja' ? 'Kreatorze' : 'Stronach' ?></small>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($isAdmin): ?>
  <details style="margin-top:12px">
    <summary>Przywróć kolejność domyślną</summary>
    <form method="post"><?= console_csrf_field() ?><input type="hidden" name="co" value="<?= h($co) ?>">
      <label class="chk"><input type="checkbox" name="na_pewno" value="1"><span>Tak, przywróć</span></label>
      <div class="actions"><button type="submit" name="przywroc" value="1" formnovalidate>Przywróć</button></div>
    </form>
  </details>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="panel" style="margin-top:20px">
  <h2>Podgląd <a class="code" href="../shop/" target="_blank" rel="noopener" style="font-weight:400">otwórz w nowej karcie ↗</a></h2>
  <p class="why">Prawdziwa strona główna, odświeżona po każdej zmianie.</p>
  <iframe class="podglad" src="../shop/?w=<?= time() ?>" title="Podgląd sklepu" loading="lazy"></iframe>
</div>

<?php console_foot();
