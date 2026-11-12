<?php
declare(strict_types=1);
/**
 * posty.php — les posts Facebook et Instagram : écrire, choisir les photos,
 * planifier, publier, et voir ce que chacun a rapporté.
 *
 * TROIS VUES : la liste (ce qui est prévu et ce qui est parti), le
 * calendrier du mois (avec les dates qui font vendre du chocolat : Tłusty
 * czwartek, Walentynki, Wielkanoc, Mikołajki…), et l'éditeur.
 *
 * L'ÉDITEUR NE PUBLIE RIEN EN DOUCE. Trois boutons, trois gestes :
 * « Zapisz szkic » garde, « Zaplanuj » met en file pour la date, « Opublikuj
 * teraz » part sur-le-champ. Un post publié ne se réécrit plus — il se
 * duplique : Meta ne permet pas de changer la photo, et la console ne doit
 * pas raconter autre chose que la page.
 */
require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/social.php';
require_once $API . '/shop.php';
require_once $API . '/marketing.php';
require_once $API . '/marketing_ai.php';
require_once $API . '/delivery.php';   // wsm_audit
wsm_social_ensure($pdo);

$qui = (string) ($me['nom'] ?? '');
$shop = wsm_shop_base_url();
$flash = ''; $kind = 'ok'; $err = []; $propozycje = []; $brouillon = null;
$id = (int) ($_GET['id'] ?? 0);
$widok = (string) ($_GET['widok'] ?? ($id > 0 || isset($_GET['nowy']) ? 'edycja' : 'lista'));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    $pid = (int) ($_POST['id'] ?? 0);
    $in = ['tekst' => (string) ($_POST['tekst'] ?? ''), 'media' => (array) ($_POST['media'] ?? []),
           'kanaly' => (array) ($_POST['kanaly'] ?? []), 'product_id' => (string) ($_POST['product_id'] ?? ''),
           'termin' => (string) ($_POST['termin'] ?? '')];
    if (!$isAdmin) { $flash = 'Tylko do odczytu.'; $kind = 'err'; }
    elseif (isset($_POST['ai'])) {
        [$propozycje, $e] = wsm_social_ai($pdo, (string) ($_POST['ai_brief'] ?? ''), $in['product_id'], $in['kanaly'] ?: ['fb', 'ig']);
        if ($e !== null) { $flash = $e; $kind = 'err'; }
        $brouillon = $in; $id = $pid; $widok = 'edycja';
    } elseif (isset($_POST['uzyj'])) {
        $in['tekst'] = (string) $_POST['uzyj'];
        $brouillon = $in; $id = $pid; $widok = 'edycja';
        $flash = 'Wstawiono propozycję — przeczytaj, popraw i zapisz.'; $kind = 'warn';
    } elseif (isset($_POST['akcja'])) {
        $akcja = in_array($_POST['akcja'], ['szkic', 'zaplanuj', 'teraz'], true) ? (string) $_POST['akcja'] : 'szkic';
        [$nid, $err] = wsm_social_save($pdo, $pid ?: null, $in, $akcja, $qui);
        if ($err) {
            $brouillon = $in; $id = $pid; $widok = 'edycja';
            $flash = 'Nie zapisano — popraw zaznaczone pola.'; $kind = 'err';
        } else {
            wsm_audit($pdo, $qui, 'Posty FB/IG', ($akcja === 'szkic' ? 'szkic' : ($akcja === 'teraz' ? 'publikacja' : 'zaplanowano')) . ' #' . $nid, 'Sieć');
            $msg = 'Zapisano szkic.';
            if ($akcja === 'zaplanuj') $msg = 'Zaplanowano — post wyjdzie o wskazanej godzinie.';
            if ($akcja === 'teraz') {
                $etat = wsm_social_publish($pdo, $nid, $shop, true);
                $p = wsm_social_get($pdo, $nid);
                $msg = $etat === 'opublikowany' ? 'Opublikowano.' : ('Publikacja: ' . (WSM_SOCIAL_STATUSY[$etat] ?? $etat)
                     . ' — ' . trim(($p['fb_error'] !== '' ? 'Facebook: ' . $p['fb_error'] . ' ' : '') . ($p['ig_error'] !== '' ? 'Instagram: ' . $p['ig_error'] : '')));
            }
            header('Location: posty.php?id=' . $nid . '&ok=' . rawurlencode($msg) . '&k=' . (str_starts_with($msg, 'Publikacja') ? 'err' : 'ok'), true, 303);
            exit;
        }
    } elseif (isset($_POST['duplikuj'])) {
        $nid = wsm_social_duplicate($pdo, (int) $_POST['duplikuj'], $qui);
        header('Location: posty.php?id=' . $nid . '&ok=' . rawurlencode('Kopia gotowa do edycji.'), true, 303); exit;
    } elseif (isset($_POST['ponow'])) {
        $rid = (int) $_POST['ponow'];
        if (wsm_social_retry($pdo, $rid)) {
            $etat = wsm_social_publish($pdo, $rid, $shop, true);
            $flash = 'Ponowiono: ' . (WSM_SOCIAL_STATUSY[$etat] ?? $etat) . '.'; $kind = $etat === 'opublikowany' ? 'ok' : 'err';
        } else { $flash = 'Tego posta nie da się ponowić.'; $kind = 'err'; }
    } elseif (isset($_POST['usun'])) {
        $flash = wsm_social_delete($pdo, (int) $_POST['usun']) ? 'Usunięto.' : 'Opublikowanego posta nie usuwa się stąd — usuń go na Facebooku/Instagramie.';
        $kind = str_starts_with($flash, 'Usunięto') ? 'ok' : 'err';
        $widok = 'lista'; $id = 0;
    }
}
if (isset($_GET['ok'])) { $flash = (string) $_GET['ok']; $kind = in_array($_GET['k'] ?? '', ['ok', 'err', 'warn'], true) ? (string) $_GET['k'] : 'ok'; }

// La file : à chaque ouverture, au plus un post dû part (la tâche de fond fait
// le reste). Seulement pour qui a le droit de publier : un rôle en lecture ne
// déclenche rien en ouvrant l'écran.
$pompe = $isAdmin ? wsm_social_pump($pdo, $shop, 1) : ['opublikowane' => 0, 'bledy' => 0];
$cfg = wsm_social_cfg();
$igAdres = wsm_social_ig_adres($shop);
$post = $id > 0 ? wsm_social_get($pdo, $id) : null;
if ($id > 0 && !$post) { $widok = 'lista'; }
$produits = wsm_shop_products($pdo, 'pl');
$nomProd = array_column($produits, 'name', 'id');

console_head('Posty Facebook i Instagram', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 14px; margin: 0 0 16px; max-width: 88ch; line-height: 1.6; }
  .zakl { display: flex; gap: 6px; margin: 0 0 16px; flex-wrap: wrap; }
  .zakl a { padding: 7px 14px; border-radius: 999px; border: 1px solid var(--border-default); text-decoration: none; color: var(--text-body); }
  .zakl a[aria-current="page"] { background: var(--text-strong); color: var(--surface-card); border-color: var(--text-strong); }
  .ed { display: grid; gap: 18px; }
  @media (min-width: 1150px) { .ed { grid-template-columns: minmax(0, 1fr) 360px; align-items: start; } .ed .podglad { position: sticky; top: 16px; } }
  .kan { display: flex; gap: 16px; flex-wrap: wrap; margin: 0 0 12px; }
  .kan label { display: flex; gap: 8px; align-items: center; cursor: pointer; }
  textarea.post { min-height: 200px; font-size: 15px; line-height: 1.5; }
  .licz { font-family: var(--font-mono); font-size: 13px; color: var(--text-muted); margin-top: 4px; }
  .licz.zle { color: var(--danger); font-weight: 700; }
  .galeria-filtr { display: flex; gap: 8px; flex-wrap: wrap; margin: 0 0 10px; }
  .galeria { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 8px; max-height: 360px; overflow: auto; padding: 2px; }
  .galeria label { position: relative; display: block; border-radius: 10px; overflow: hidden; border: 2px solid transparent; cursor: pointer; aspect-ratio: 1; background: var(--surface-raised); }
  .galeria label:has(input:checked) { border-color: var(--brand, var(--brand-700)); }
  .galeria img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .galeria input { position: absolute; top: 6px; left: 6px; width: 18px; height: 18px; accent-color: var(--brand-700); }
  .galeria .nr { position: absolute; right: 6px; top: 6px; background: var(--brand-700); color: #fff; font-size: 12px; font-weight: 700; border-radius: 999px; padding: 1px 7px; display: none; }
  .galeria label:has(input:checked) .nr { display: block; }
  .tel { background: #fff; border: 1px solid var(--border-default); border-radius: 28px; padding: 14px; box-shadow: var(--shadow-sm); color: #111; }
  .tel .naglowek { display: flex; gap: 8px; align-items: center; font-weight: 600; font-size: 14px; margin-bottom: 10px; }
  .tel .avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--brand-700); }
  .tel .foto { aspect-ratio: 4 / 5; background: #f2f2f2 center / cover no-repeat; border-radius: 6px; display: grid; place-items: center; color: #999; font-size: 13px; }
  .tel .opis { font-size: 14px; line-height: 1.45; white-space: pre-wrap; overflow-wrap: anywhere; margin-top: 10px; max-height: 280px; overflow: auto; }
  .war { border: 1px solid var(--border-subtle); border-radius: 12px; padding: 10px 12px; margin-bottom: 8px; }
  .war p { margin: 0 0 8px; white-space: pre-wrap; font-size: 14px; }
  .ai { background: color-mix(in srgb, var(--accent-500) 8%, var(--surface-card)); }
  .mini { width: 52px; height: 52px; object-fit: cover; border-radius: 8px; display: block; }
  .kal { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
  .kal .dz { min-height: 108px; border: 1px solid var(--border-subtle); border-radius: 10px; padding: 6px; background: var(--surface-card); font-size: 13px; }
  .kal .dz.inny { background: transparent; border-style: dashed; opacity: .55; }
  .kal .dz.dzis { outline: 2px solid var(--accent-500); }
  .kal .nr { font-weight: 700; color: var(--text-strong); }
  .kal .okazja { display: block; color: var(--sale-strong, #8E2A45); font-weight: 600; font-size: 12px; margin: 2px 0; }
  .kal .p { display: flex; gap: 6px; align-items: center; margin-top: 4px; text-decoration: none; color: var(--text-body); }
  .kal .p img { width: 26px; height: 26px; object-fit: cover; border-radius: 5px; }
  .kal .tyg { font-size: 12px; font-weight: 700; color: var(--text-muted); text-align: center; text-transform: uppercase; }
  @media (max-width: 760px) { .kal { grid-template-columns: 1fr; } .kal .tyg, .kal .dz.inny { display: none; } }
  .err-pole { color: var(--danger); font-size: 13px; }
CSS);
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Marketing' => null, 'Posty FB / IG' => $widok === 'lista' ? null : 'posty.php']);
?>
<?php if (!$cfg['fb'] && !$cfg['ig_ok']): ?>
<p class="warnbox">Facebook i Instagram nie są jeszcze połączone (Marketing → <a href="meta.php">Meta</a>: ID strony, token strony, ID Instagrama). Posty można pisać i planować — wyjdą po połączeniu.</p>
<?php elseif ($cfg['ig_ok'] && $igAdres !== ''): ?>
<p class="warnbox">Instagram: <?= h($igAdres) ?>. Facebook działa normalnie.</p>
<?php endif; ?>
<?php if ($pompe['opublikowane'] || $pompe['bledy']): ?><p class="hint">Przy otwarciu ekranu: opublikowane <?= (int) $pompe['opublikowane'] ?>, błędy <?= (int) $pompe['bledy'] ?>.</p><?php endif; ?>

<nav class="zakl" aria-label="Widok">
  <a href="posty.php"<?= $widok === 'lista' ? ' aria-current="page"' : '' ?>>Lista</a>
  <a href="posty.php?widok=kalendarz"<?= $widok === 'kalendarz' ? ' aria-current="page"' : '' ?>>Kalendarz</a>
  <?php if ($isAdmin): ?><a href="posty.php?nowy=1"<?= $widok === 'edycja' && !$post ? ' aria-current="page"' : '' ?>>+ Nowy post</a><?php endif; ?>
</nav>

<?php if ($widok === 'lista'):
  $lista = wsm_social_list($pdo, 60); ?>
<div class="panel"><h2>Posty</h2>
  <div class="tablewrap"><table class="rwd">
    <thead><tr><th></th><th>Kiedy</th><th>Treść</th><th>Facebook</th><th>Instagram</th><th class="num">Kliknięcia</th><th class="num">Zamówienia</th><th></th></tr></thead>
    <tbody>
    <?php if (!$lista): ?><tr><td colspan="8" class="muted">Jeszcze żadnego posta. Zacznij od „+ Nowy post”.</td></tr><?php endif; ?>
    <?php foreach ($lista as $r):
      $st = wsm_mkt_link_stats($pdo, (string) $r['link_code']);
      $kan = array_filter(explode(',', (string) $r['kanaly']));
      $tagK = function (string $k) use ($r, $kan): string {
          if (!in_array($k, $kan, true)) return '<span class="muted">—</span>';
          $s = (string) $r[$k . '_status'];
          if ($s === 'ok') return '<span class="tag ok">opublikowany</span>' . ($r[$k . '_url'] !== '' ? ' <a href="' . h((string) $r[$k . '_url']) . '" target="_blank" rel="noopener">zobacz ↗</a>' : '');
          if ($s === 'blad') return '<span class="tag bad" title="' . h((string) $r[$k . '_error']) . '">błąd</span><br><small class="muted">' . h(mb_substr((string) $r[$k . '_error'], 0, 90)) . '</small>';
          return '<span class="tag wait">' . h(WSM_SOCIAL_STATUSY[(string) $r['status']] ?? (string) $r['status']) . '</span>';
      }; ?>
      <tr>
        <td><?php if ($r['media_list']): ?><img class="mini" src="<?= h(img_src((string) $r['media_list'][0])) ?>" alt=""><?php endif; ?></td>
        <td data-l="Kiedy"><?= h(substr((string) ($r['published_at'] ?: ($r['termin'] ?: $r['created_at'])), 0, 16)) ?><br><small class="muted"><?= h(WSM_SOCIAL_STATUSY[(string) $r['status']] ?? '') ?></small></td>
        <td data-l="Treść"><a href="posty.php?id=<?= (int) $r['id'] ?>"><?= h(mb_strimwidth((string) $r['tekst'], 0, 90, '…')) ?: '<i>(bez tekstu)</i>' ?></a>
          <?php if ($r['product_id'] !== ''): ?><br><small class="muted">Produkt: <?= h((string) ($nomProd[$r['product_id']] ?? $r['product_id'])) ?></small><?php endif; ?></td>
        <td data-l="Facebook"><?= $tagK('fb') ?></td>
        <td data-l="Instagram"><?= $tagK('ig') ?></td>
        <td data-l="Kliknięcia" class="num"><?= $r['link_code'] !== '' ? (int) $st['klikniec'] : '—' ?></td>
        <td data-l="Zamówienia" class="num"><?= $r['link_code'] !== '' ? (int) $st['zamowien'] . ' · ' . h(number_format($st['obrot'] / 100, 2, ',', ' ')) . ' zł' : '—' ?></td>
        <td><?php if ($isAdmin): ?><form method="post" class="actions" style="margin:0"><?= console_csrf_field() ?>
          <?php if (in_array($r['status'], ['blad', 'czesciowo'], true)): ?><button type="submit" name="ponow" value="<?= (int) $r['id'] ?>">Ponów</button><?php endif; ?>
          <button type="submit" name="duplikuj" value="<?= (int) $r['id'] ?>">Duplikuj</button>
        </form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="hint" style="margin-top:12px">Kliknięcia i zamówienia liczy link do produktu dodawany do postów na Facebooku (na Instagramie link w opisie się nie klika — tam działa „link w bio”).</p>
</div>

<?php elseif ($widok === 'kalendarz'):
  $m = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? '')) ? (string) $_GET['m'] : date('Y-m');
  $t0 = strtotime($m . '-01');
  $parMois = wsm_social_month($pdo, $m);
  $ok = wsm_social_okazje((int) date('Y', $t0));
  $debut = strtotime('-' . ((int) date('N', $t0) - 1) . ' days', $t0);
  $prev = date('Y-m', strtotime('-1 month', $t0)); $next = date('Y-m', strtotime('+1 month', $t0));
  $moisPl = ['', 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień']; ?>
<div class="panel">
  <h2 style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <span><?= h($moisPl[(int) date('n', $t0)] . ' ' . date('Y', $t0)) ?></span>
    <span class="actions" style="margin:0"><a class="btn" href="posty.php?widok=kalendarz&amp;m=<?= h($prev) ?>">‹ poprzedni</a>
      <a class="btn" href="posty.php?widok=kalendarz">dziś</a>
      <a class="btn" href="posty.php?widok=kalendarz&amp;m=<?= h($next) ?>">następny ›</a></span>
  </h2>
  <p class="hint">Na czerwono — okazje, które sprzedają czekoladę. Zaplanuj post na kilka dni przed nimi: wtedy klienci szukają prezentu.</p>
  <div class="kal">
    <?php foreach (['pon', 'wt', 'śr', 'czw', 'pt', 'sob', 'nd'] as $d): ?><div class="tyg"><?= $d ?></div><?php endforeach; ?>
    <?php for ($i = 0; $i < 42; $i++): $t = strtotime("+$i days", $debut); $dk = date('Y-m-d', $t);
      if ($i >= 35 && date('Y-m', $t) !== $m) break; ?>
      <div class="dz<?= date('Y-m', $t) !== $m ? ' inny' : '' ?><?= $dk === date('Y-m-d') ? ' dzis' : '' ?>">
        <span class="nr"><?= (int) date('j', $t) ?></span>
        <?php if (isset($ok[$dk])): ?><span class="okazja"><?= h($ok[$dk]) ?></span><?php endif; ?>
        <?php foreach ($parMois[$dk] ?? [] as $p): ?>
        <a class="p" href="posty.php?id=<?= (int) $p['id'] ?>" title="<?= h(mb_strimwidth((string) $p['tekst'], 0, 120, '…')) ?>">
          <?php if ($p['media_list']): ?><img src="<?= h(img_src((string) $p['media_list'][0])) ?>" alt=""><?php endif; ?>
          <span><?= h(substr((string) ($p['published_at'] ?: $p['termin']), 11, 5)) ?> · <?= h(WSM_SOCIAL_STATUSY[(string) $p['status']] ?? '') ?></span></a>
        <?php endforeach; ?>
        <?php if ($isAdmin && date('Y-m', $t) === $m && $dk >= date('Y-m-d')): ?>
        <a class="p muted" href="posty.php?nowy=1&amp;d=<?= h($dk) ?>">+ post</a>
        <?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>

<?php else:
  // L'éditeur. Les valeurs : brouillon renvoyé (erreur, IA) > post en base > nouveau.
  $v = $brouillon ?? ($post ? ['tekst' => (string) $post['tekst'], 'media' => $post['media_list'], 'kanaly' => $post['kanaly_list'],
                               'product_id' => (string) $post['product_id'],
                               'termin' => $post['termin'] ? str_replace(' ', 'T', substr((string) $post['termin'], 0, 16)) : '']
                            : ['tekst' => '', 'media' => [], 'kanaly' => ['fb', 'ig'], 'product_id' => '',
                               'termin' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['d'] ?? '')) ? $_GET['d'] . 'T10:00' : '']);
  if (isset($v['termin']) && str_contains((string) $v['termin'], ' ')) $v['termin'] = str_replace(' ', 'T', substr((string) $v['termin'], 0, 16));
  $edit = $isAdmin && (!$post || in_array((string) $post['status'], ['szkic', 'zaplanowany', 'blad'], true));
  $dis = $edit ? '' : ' disabled';
  require_once $API . '/mediateka.php';
  $media = wsm_mediateka_list($pdo);
  $wybrane = array_values(array_filter((array) $v['media'], 'is_string'));
?>
<?php if ($post && !$edit): ?>
<p class="warnbox">Ten post jest <?= h(mb_strtolower(WSM_SOCIAL_STATUSY[(string) $post['status']] ?? '')) ?> — nie da się go zmienić. „Duplikuj” tworzy kopię do edycji.</p>
<?php endif; ?>
<form method="post" action="posty.php<?= $post ? '?id=' . (int) $post['id'] : '' ?>" class="ed">
  <?= console_csrf_field() ?>
  <input type="hidden" name="id" value="<?= $post ? (int) $post['id'] : 0 ?>">
  <div>
    <?php if ($edit && wsm_ai_enabled()): ?>
    <div class="panel ai"><h2>Napisz z Claude</h2>
      <label class="field"><span>O czym ma być post?</span><input type="text" name="ai_brief" maxlength="400"
        placeholder="Np. nowa kuwertura 70% do pralin, dla cukierników; albo: prezent na Walentynki"></label>
      <p class="actions" style="margin:8px 0 0"><button type="submit" name="ai" value="1">Zaproponuj 3 wersje</button></p>
      <?php foreach ($propozycje as $w): ?>
      <div class="war"><p><?= h($w) ?></p><button type="submit" name="uzyj" value="<?= h($w) ?>">Użyj tej wersji</button></div>
      <?php endforeach; ?>
      <p class="hint" style="margin:8px 0 0">Claude pisze tylko z faktów podanych w poleceniu i z karty produktu — bez wymyślonych cen i promocji. Zawsze przeczytaj przed publikacją.</p>
    </div>
    <?php endif; ?>

    <div class="panel"><h2>Post<?php if ($post): ?> <span class="tag <?= $post['status'] === 'opublikowany' ? 'ok' : ($post['status'] === 'blad' ? 'bad' : 'wait') ?>"><?= h(WSM_SOCIAL_STATUSY[(string) $post['status']] ?? '') ?></span><?php endif; ?></h2>
      <div class="kan">
        <label><input type="checkbox" name="kanaly[]" value="fb"<?= in_array('fb', (array) $v['kanaly'], true) ? ' checked' : '' ?><?= $dis ?>> Facebook <span class="tag <?= $cfg['fb'] ? 'ok' : 'bad' ?>"><?= $cfg['fb'] ? 'połączony' : 'niepołączony' ?></span></label>
        <label><input type="checkbox" name="kanaly[]" value="ig"<?= in_array('ig', (array) $v['kanaly'], true) ? ' checked' : '' ?><?= $dis ?>> Instagram <span class="tag <?= $cfg['ig_ok'] && $igAdres === '' ? 'ok' : 'bad' ?>"><?= $cfg['ig_ok'] ? ($igAdres === '' ? 'połączony' : 'brak domeny') : 'niepołączony' ?></span></label>
      </div>
      <?php if (isset($err['kanaly'])): ?><p class="err-pole"><?= h($err['kanaly']) ?></p><?php endif; ?>
      <label class="field"><span>Treść (Instagram: do 2200 znaków i 30 hashtagów)</span>
        <textarea class="post" name="tekst" maxlength="<?= WSM_SOCIAL_MAX_ZNAKOW ?>" data-tekst<?= $dis ?>><?= h((string) $v['tekst']) ?></textarea></label>
      <div class="licz" data-licz></div>
      <?php if (isset($err['tekst'])): ?><p class="err-pole"><?= h($err['tekst']) ?></p><?php endif; ?>
      <div class="grid2">
        <label class="field"><span>Produkt (na Facebooku dopisze link z licznikiem kliknięć)</span>
          <select name="product_id"<?= $dis ?>><option value="">— bez produktu —</option>
            <?php foreach ($produits as $p): ?><option value="<?= h((string) $p['id']) ?>"<?= (string) $v['product_id'] === (string) $p['id'] ? ' selected' : '' ?>><?= h((string) $p['name']) ?></option><?php endforeach; ?>
          </select></label>
        <label class="field"><span>Data i godzina publikacji</span>
          <input type="datetime-local" name="termin" value="<?= h((string) ($v['termin'] ?? '')) ?>"<?= $dis ?>>
          <?php if (isset($err['termin'])): ?><span class="err-pole"><?= h($err['termin']) ?></span><?php endif; ?></label>
      </div>
    </div>

    <div class="panel"><h2>Zdjęcia <small class="muted" data-ile>(<?= count($wybrane) ?> z <?= WSM_SOCIAL_MAX_ZDJEC ?>)</small></h2>
      <p class="hint">Z <a href="media.php">Mediateki</a>. Kolejność = kolejność zaznaczania (pierwsze jest okładką). Kilka zdjęć = karuzela.
        Instagram dostaje kopię JPEG w kadrze 4:5–1,91:1 (białe marginesy, nic nie jest ucinane).</p>
      <?php if (isset($err['media'])): ?><p class="err-pole"><?= h($err['media']) ?></p><?php endif; ?>
      <div class="galeria-filtr">
        <select data-filtr="kat" aria-label="Kategoria"><option value="">Wszystkie kategorie</option>
          <?php foreach (WSM_MEDIATEKA_KATEGORIE as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select>
        <select data-filtr="ok" aria-label="Okazja"><option value="">Każda okazja</option>
          <?php foreach (WSM_MEDIATEKA_OKAZJE as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select>
        <input type="search" data-filtr="q" placeholder="Szukaj (nazwa, opis, tagi)" aria-label="Szukaj">
      </div>
      <div class="galeria" data-galeria>
        <?php
        // Les photos choisies d'abord, dans leur ordre ; le reste ensuite.
        $parUrl = array_column($media, null, 'url');
        $ordre = array_merge(array_values(array_filter($wybrane, fn($u) => isset($parUrl[$u]))), array_diff(array_keys($parUrl), $wybrane));
        foreach ($ordre as $u): $m = $parUrl[$u]; $pos = array_search($u, $wybrane, true); ?>
        <label data-kat="<?= h((string) $m['meta']['kategoria']) ?>" data-ok="<?= h((string) $m['meta']['okazja']) ?>"
               data-q="<?= h(mb_strtolower($m['title'] . ' ' . $m['meta']['alt'] . ' ' . $m['meta']['tagi'], 'UTF-8')) ?>" title="<?= h($m['label']) ?>">
          <img src="<?= h(img_src($u)) ?>" alt="<?= h((string) ($m['meta']['alt'] ?: $m['title'])) ?>" loading="lazy">
          <input type="checkbox" name="media[]" value="<?= h($u) ?>"<?= $pos !== false ? ' checked' : '' ?><?= $dis ?> data-kolejnosc="<?= $pos !== false ? $pos + 1 : '' ?>">
          <span class="nr"><?= $pos !== false ? $pos + 1 : '' ?></span>
        </label>
        <?php endforeach; ?>
        <?php if (!$media): ?><p class="muted">Mediateka jest pusta — <a href="media.php">dodaj zdjęcia</a>.</p><?php endif; ?>
      </div>
    </div>

    <?php if ($edit): ?>
    <p class="actions">
      <button type="submit" name="akcja" value="szkic">Zapisz szkic</button>
      <button type="submit" name="akcja" value="zaplanuj">Zaplanuj</button>
      <button class="btn btn--brand" type="submit" name="akcja" value="teraz" data-teraz>Opublikuj teraz</button>
    </p>
    <?php endif; ?>
    <?php if ($post): ?>
      <?php foreach (['fb' => 'Facebook', 'ig' => 'Instagram'] as $k => $lbl): if (!in_array($k, $post['kanaly_list'], true)) continue; ?>
      <p class="hint" style="margin:4px 0"><b><?= $lbl ?>:</b>
        <?= $post[$k . '_status'] === 'ok' ? 'opublikowany' . ($post[$k . '_url'] !== '' ? ' — <a href="' . h((string) $post[$k . '_url']) . '" target="_blank" rel="noopener">zobacz ↗</a>' : '')
           : ($post[$k . '_status'] === 'blad' ? '<span class="err-pole">' . h((string) $post[$k . '_error']) . '</span>' : 'czeka') ?></p>
      <?php endforeach; ?>
      <?php if ($isAdmin): ?>
      <div class="actions"><button type="submit" name="duplikuj" value="<?= (int) $post['id'] ?>" formnovalidate>Duplikuj</button>
        <?php if (in_array($post['status'], ['blad', 'czesciowo'], true)): ?><button type="submit" name="ponow" value="<?= (int) $post['id'] ?>" formnovalidate>Ponów nieudane</button><?php endif; ?>
        <?php if ($edit): ?><button type="submit" name="usun" value="<?= (int) $post['id'] ?>" formnovalidate data-usun>Usuń</button><?php endif; ?></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <aside class="podglad panel" aria-label="Podgląd">
    <h2>Podgląd</h2>
    <div class="tel">
      <div class="naglowek"><span class="avatar" aria-hidden="true"></span><span><?= h(wsm_brand_name()) ?></span></div>
      <div class="foto" data-foto>brak zdjęcia</div>
      <div class="opis" data-opis></div>
    </div>
    <p class="hint" style="margin-top:10px">Na Facebooku pod treścią pojawi się jeszcze link do wybranego produktu.</p>
  </aside>
</form>
<script>
(function () {
  var t = document.querySelector('[data-tekst]'), out = document.querySelector('[data-licz]');
  var opis = document.querySelector('[data-opis]'), foto = document.querySelector('[data-foto]'), ile = document.querySelector('[data-ile]');
  var gal = document.querySelector('[data-galeria]');
  function licz() {
    if (!t) return;
    var n = Array.from(t.value).length, h = (t.value.match(/#[\p{L}\p{N}_]+/gu) || []).length;
    out.textContent = n + ' / 2200 znaków · ' + h + ' / 30 hashtagów';
    out.className = 'licz' + (n > 2200 || h > 30 ? ' zle' : '');
    opis.textContent = t.value;
  }
  // L'ORDRE DE SÉLECTION fait l'ordre du carrousel : on renumérote à chaque clic.
  var ordre = [];
  if (gal) gal.querySelectorAll('input[type=checkbox]').forEach(function (c) {
    var k = parseInt(c.getAttribute('data-kolejnosc'), 10);
    if (c.checked) ordre[k ? k - 1 : ordre.length] = c;
  });
  ordre = ordre.filter(Boolean);
  function maluj() {
    ordre.forEach(function (c, i) { c.parentNode.querySelector('.nr').textContent = i + 1; });
    if (ile) ile.textContent = '(' + ordre.length + ' z 10)';
    var first = ordre[0];
    if (foto) {
      if (first) { foto.style.backgroundImage = 'url("' + first.parentNode.querySelector('img').src + '")'; foto.textContent = ''; }
      else { foto.style.backgroundImage = ''; foto.textContent = 'brak zdjęcia'; }
    }
    // Le formulaire envoie les cases dans l'ordre du DOM : on déplace les choisies en tête.
    ordre.slice().reverse().forEach(function (c) { gal.insertBefore(c.parentNode, gal.firstChild); });
  }
  if (gal) gal.addEventListener('change', function (e) {
    var c = e.target;
    if (!c.matches('input[type=checkbox]')) return;
    if (c.checked) { if (ordre.length >= 10) { c.checked = false; return; } ordre.push(c); }
    else ordre = ordre.filter(function (x) { return x !== c; });
    maluj();
  });
  var f = { kat: '', ok: '', q: '' };
  document.querySelectorAll('[data-filtr]').forEach(function (el) {
    el.addEventListener('input', function () {
      f[el.getAttribute('data-filtr')] = el.value.toLowerCase();
      gal.querySelectorAll('label').forEach(function (l) {
        var vis = (!f.kat || l.dataset.kat === f.kat) && (!f.ok || l.dataset.ok === f.ok) && (!f.q || (l.dataset.q || '').indexOf(f.q) >= 0);
        l.hidden = !vis && !l.querySelector('input').checked;
      });
    });
  });
  var teraz = document.querySelector('[data-teraz]');
  if (teraz) teraz.addEventListener('click', function (e) { if (!confirm('Opublikować teraz na wybranych kanałach?')) e.preventDefault(); });
  var usun = document.querySelector('[data-usun]');
  if (usun) usun.addEventListener('click', function (e) { if (!confirm('Usunąć ten post?')) e.preventDefault(); });
  if (t) { t.addEventListener('input', licz); licz(); }
  if (gal) maluj();
})();
</script>
<?php endif; ?>
<?php console_foot();
