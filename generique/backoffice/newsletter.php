<?php
// ============================================================================
//  newsletter.php — l'écran de la newsletter (éditions en blocs).
//
//  Le chemin, toujours dans cet ordre, comme pour les campagnes :
//
//      écrire (ou faire écrire par l'IA)  →  traduire  →  próbka  →  envoyer
//
//  L'aperçu à droite est le message EXACT qui partira (le même rendu que
//  l'envoi), dans la langue choisie. Le bouton d'envoi demande la case « j'ai
//  lu la próbka » : une coquille dans l'objet part à tout le monde et ne se
//  rattrape pas.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/marketing_ai.php';
wsm_mkt_ensure($pdo);

$flash = ''; $kind = 'ok';
$acteur = (string) ($me['nom'] ?? '');
$id = (int) ($_GET['id'] ?? 0);
$jezyk = in_array($_GET['jezyk'] ?? '', WSM_MKT_LANGS, true) ? (string) $_GET['jezyk'] : 'pl';

/** Le formulaire de l'éditeur → l'entrée de wsm_nl_save(). */
$lireFormulaire = function (array $p) use ($id): array {
    $bloki = [];
    foreach ((array) ($p['b'] ?? []) as $b) if (is_array($b)) $bloki[] = $b;
    $tl = [];
    foreach (['uk', 'en'] as $l) {
        $x = (array) ($p['tl'][$l] ?? []);
        $tl[$l] = ['temat' => (string) ($x['temat'] ?? ''), 'preheader' => (string) ($x['preheader'] ?? ''),
                   'bloki' => (array) ($x['b'] ?? [])];
    }
    return ['id' => $id, 'nazwa' => (string) ($p['nazwa'] ?? ''), 'temat' => (string) ($p['temat'] ?? ''),
            'preheader' => (string) ($p['preheader'] ?? ''), 'segment' => (string) ($p['segment'] ?? 'wszyscy'),
            'kod' => (string) ($p['kod'] ?? ''), 'bloki' => $bloki, 'tlumaczenia' => $tl];
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Twoja rola może tylko przeglądać newslettery.'; $kind = 'err';
    } elseif (isset($_POST['nowy'])) {
        [$nid] = wsm_nl_save($pdo, ['nazwa' => '', 'temat' => '', 'bloki' => [wsm_nl_blok_nowy('naglowek'), wsm_nl_blok_nowy('tekst'),
                                    ['typ' => 'przycisk', 'etykieta' => 'Zobacz w sklepie']]], $acteur);
        header('Location: newsletter.php?id=' . $nid, true, 303); exit;
    } elseif (isset($_POST['duplikuj'])) {
        $nid = wsm_nl_duplicate($pdo, (int) $_POST['duplikuj'], $acteur);
        header('Location: newsletter.php?id=' . $nid, true, 303); exit;
    } elseif (isset($_POST['pompa'])) {
        $r = wsm_mkt_pump($pdo, 20, 20, 8.0);
        $flash = 'Wysłano teraz: e-maile ' . $r['mail'] . ', SMS-y ' . $r['sms'] . '.' . ($r['uwagi'] ? ' ' . implode(' ', $r['uwagi']) : '');
        $kind = $r['uwagi'] ? 'warn' : 'ok';
    } elseif ($id > 0) {
        $nl = wsm_nl_get($pdo, $id);
        if (!$nl) { $flash = 'Nie znaleziono newslettera.'; $kind = 'err'; }
        elseif ((string) $nl['status'] !== 'szkic' && !isset($_POST['probka'])) {
            $flash = 'Ten newsletter już wyszedł — zduplikuj go, żeby przygotować nową wersję.'; $kind = 'err';
        } else {
            $in = $lireFormulaire($_POST);
            // Les gestes sur les blocs : le formulaire est enregistré d'abord,
            // pour qu'aucun clic ne fasse perdre ce qui vient d'être tapé.
            if (isset($_POST['dodaj']) && isset(WSM_NL_BLOKI[(string) $_POST['dodaj']])) $in['bloki'][] = wsm_nl_blok_nowy((string) $_POST['dodaj']);
            foreach (['usun', 'gora', 'dol'] as $g) {
                if (!isset($_POST[$g])) continue;
                $i = (int) $_POST[$g]; $b = array_values($in['bloki']);
                if ($g === 'usun' && isset($b[$i])) array_splice($b, $i, 1);
                if ($g === 'gora' && $i > 0 && isset($b[$i])) [$b[$i - 1], $b[$i]] = [$b[$i], $b[$i - 1]];
                if ($g === 'dol' && isset($b[$i + 1])) [$b[$i + 1], $b[$i]] = [$b[$i], $b[$i + 1]];
                $in['bloki'] = $b;
                // Les traductions suivent les blocs : on les retire plutôt que de les décaler de travers.
                $in['tlumaczenia'] = array_map(fn($t) => ['temat' => $t['temat'], 'preheader' => $t['preheader'], 'bloki' => []], $in['tlumaczenia']);
            }
            if ((string) $nl['status'] === 'szkic') {
                [$ok, $m] = wsm_nl_save($pdo, $in, $acteur);
                $flash = $m; $kind = $ok ? 'ok' : 'err';
            }
            $nl = wsm_nl_get($pdo, $id);
            if (isset($_POST['ai_napisz'])) {
                [$d, $err] = wsm_ai_newsletter($pdo, (string) ($_POST['ai_brief'] ?? ''), (array) ($_POST['ai_ids'] ?? []), (string) $nl['kod']);
                if ($d) {
                    wsm_nl_save($pdo, ['id' => $id, 'nazwa' => $nl['nazwa'], 'segment' => $nl['segment'], 'kod' => $nl['kod'],
                                       'temat' => $d['temat'], 'preheader' => $d['preheader'], 'bloki' => $d['bloki'], 'tlumaczenia' => []], $acteur);
                    $flash = 'Propozycja AI wstawiona. Przeczytaj ją i popraw — nic jeszcze nie wyszło.';
                } else { $flash = 'AI: ' . $err; $kind = 'err'; }
            } elseif (isset($_POST['ai_tlumacz']) && in_array($_POST['ai_tlumacz'], ['uk', 'en'], true)) {
                $l = (string) $_POST['ai_tlumacz'];
                [$t, $err] = wsm_ai_translate_nl($nl, $l);
                if ($t) {
                    $tl = $nl['tlumaczenia']; $tl[$l] = $t;
                    wsm_nl_save($pdo, ['id' => $id] + array_intersect_key($nl, array_flip(['nazwa', 'temat', 'preheader', 'segment', 'kod', 'bloki'])) + ['tlumaczenia' => $tl], $acteur);
                    $flash = 'Przetłumaczono (' . $l . '). Sprawdź w podglądzie.' . ($err ? ' ' . $err : '');
                    $kind = $err ? 'warn' : 'ok';
                    $jezyk = $l;
                } else { $flash = 'AI: ' . $err; $kind = 'err'; }
            } elseif (isset($_POST['probka'])) {
                [$ok, $m] = wsm_nl_test($pdo, $id, (string) ($_POST['adres'] ?? ''), (string) ($_POST['probka_jezyk'] ?? 'pl'), $acteur);
                $flash = $m; $kind = $ok ? 'ok' : 'err';
            } elseif (isset($_POST['wyslij'])) {
                if (!wsm_mkt_decide($me)) { $flash = 'Wysyłkę do wszystkich zatwierdza administrator: przygotuj kampanię i próbkę, a administrator kliknie „Wyślij”.'; $kind = 'err'; }
                elseif (!wsm_mkt_adres_ok()) { $flash = 'Najpierw ustaw „Publiczny adres sklepu” (Integracje → Sklep): od niego zależą linki w wiadomościach, wypisania i raporty doręczeń.'; $kind = 'err'; }
                elseif (empty($_POST['potwierdzam'])) { $flash = 'Zaznacz, że próbka została sprawdzona.'; $kind = 'err'; }
                else { [$n, $m] = wsm_nl_send($pdo, $id, $acteur); $flash = $m; $kind = $n > 0 ? 'ok' : 'err'; }
            }
        }
    }
    if ($flash !== '' && $id > 0) {
        header('Location: newsletter.php?id=' . $id . '&jezyk=' . $jezyk . '&ok=' . rawurlencode($flash) . '&k=' . $kind, true, 303); exit;
    }
}
if (isset($_GET['ok'])) { $flash = (string) $_GET['ok']; $kind = in_array($_GET['k'] ?? '', ['ok', 'err', 'warn'], true) ? (string) $_GET['k'] : 'ok'; }

// La file avance aussi quand on ouvre l'écran (petite quantité) : sans tâche
// planifiée sur le serveur, c'est ce qui la fait vivre.
$pompe = wsm_mkt_pump($pdo, 5, 5, 3.0);
$kpi = wsm_mkt_kpis($pdo);
$nl = $id > 0 ? wsm_nl_get($pdo, $id) : null;

console_head('Newsletter', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 18px; max-width: 86ch; line-height: 1.6; }
  .nl-ed { display: grid; gap: 18px; }
  @media (min-width: 1200px) { .nl-ed { grid-template-columns: minmax(0, 1fr) 640px; align-items: start; } .nl-ap { position: sticky; top: 16px; } }
  .nl-ap iframe { width: 100%; height: 78vh; border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); background: #f4efe8; }
  .blok { border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 12px 14px; margin-bottom: 10px; background: var(--surface-card); }
  .blok-tete { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; font-size: 13px; font-weight: 700; color: var(--text-strong); }
  .blok-tete .sp { flex: 1; }
  .blok-tete button { padding: 4px 10px; font-size: 13px; }
  .blok textarea { min-height: 110px; }
  .blok label { display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; font-weight: 600; margin-bottom: 8px; }
  .prods { max-height: 220px; overflow: auto; border: 1px solid var(--border-subtle); border-radius: 10px; padding: 6px 10px; }
  .prods label { flex-direction: row; align-items: center; gap: 8px; font-weight: 400; margin: 4px 0; }
  .dodaj { display: flex; flex-wrap: wrap; gap: 6px; margin: 6px 0 16px; }
  .dodaj button { font-size: 13px; padding: 6px 12px; }
  .ai { background: color-mix(in srgb, var(--accent-500) 8%, var(--surface-card)); }
  .lista td, .lista th { font-size: 13.5px; }
  .tl-src { font-size: 12px; color: var(--text-muted); margin: 0 0 4px; white-space: pre-wrap; }
  .zaklad { display: flex; gap: 6px; margin-bottom: 8px; }
  .zaklad a { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--border-subtle); text-decoration: none; font-size: 13px; }
  .zaklad a.on { background: var(--text-strong); color: #fff; border-color: var(--text-strong); }
CSS);
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Marketing' => null, 'Newsletter' => $nl ? 'newsletter.php' : null] + ($nl ? [(string) $nl['nazwa'] => null] : []));
?>

<div class="kpis">
  <div class="kpi"><b><?= (int) $kpi['email_aktywni'] ?></b><span>Subskrybenci (potwierdzeni)</span></div>
  <div class="kpi"><b><?= (int) $kpi['email_oczekuja'] ?></b><span>Czekają na potwierdzenie</span></div>
  <div class="kpi"><b><?= (int) $kpi['email_wypisani'] ?></b><span>Wypisani</span></div>
  <div class="kpi"><b><?= (int) $kpi['kolejka_mail'] ?></b><span>E-maile w kolejce</span></div>
</div>
<?php if (!wsm_mail_enabled()): ?>
<p class="warnbox">Poczta wychodząca nie jest jeszcze skonfigurowana (Integracje → Poczta). Newslettery można przygotować i zaplanować — wyjdą, gdy poczta zacznie działać.</p>
<?php endif; ?>
<?php if (!wsm_mkt_adres_ok()): ?>
<p class="warnbox">Brak „Publicznego adresu sklepu” (Integracje → Sklep). Kampanie można przygotować i przetestować, ale wysyłka czeka na ten adres: od niego zależą linki, wypisania i raporty doręczeń.</p>
<?php endif; ?>
<?php if ($pompe['mail'] > 0): ?><p class="hint">Przy otwarciu ekranu wyszło <?= (int) $pompe['mail'] ?> wiadomości z kolejki.</p><?php endif; ?>

<?php if (!$nl): ?>
  <?php // ═══ LA LISTE ═══════════════════════════════════════════════════════ ?>
  <p class="hint">Newsletter w blokach: nagłówek, tekst, zdjęcie, produkty z katalogu, kod rabatowy, przycisk. Wychodzi w języku każdego
    odbiorcy (pl / uk / en) do klientów i potwierdzonych subskrybentów — nigdy do osób wypisanych. Link wypisania dopisuje się sam.</p>
  <?php if ($isAdmin): ?>
  <form method="post" class="actions" style="margin-bottom:18px">
    <?= console_csrf_field() ?>
    <button class="btn btn--brand" type="submit" name="nowy" value="1">+ Nowy newsletter</button>
    <button type="submit" name="pompa" value="1">Wyślij teraz partię z kolejki</button>
    <a class="btn" href="subskrybenci.php">Subskrybenci</a>
  </form>
  <?php endif; ?>
  <div class="panel">
    <h2>Wydania</h2>
    <div class="tablewrap"><table class="rwd lista">
      <thead><tr><th>Nazwa</th><th>Status</th><th>Odbiorcy</th><th class="num">Wysłane</th><th class="num">W kolejce / błędy</th>
        <th class="num">Kliknięcia</th><th class="num">Zamówienia</th><th class="num">Obrót</th><th class="num">Wypisy</th><th></th></tr></thead>
      <tbody>
      <?php $lista = wsm_nl_list($pdo); if (!$lista): ?><tr><td colspan="10" class="muted">Jeszcze nic — zacznij od „Nowy newsletter”.</td></tr><?php endif; ?>
      <?php foreach ($lista as $r): ?>
      <tr>
        <td data-l="Nazwa"><a href="newsletter.php?id=<?= (int) $r['id'] ?>"><?= h((string) $r['nazwa']) ?></a><br><small class="muted"><?= h((string) $r['temat']) ?></small></td>
        <td data-l="Status"><span class="tag <?= $r['status'] === 'szkic' ? 'wait' : 'ok' ?>"><?= $r['status'] === 'szkic' ? 'szkic' : 'wysłany ' . h(substr((string) $r['sent_at'], 0, 16)) ?></span></td>
        <td data-l="Odbiorcy"><?= h(WSM_MKT_SEGMENTS[(string) $r['segment']] ?? (string) $r['segment']) ?></td>
        <td data-l="Wysłane" class="num"><?= (int) $r['stat']['wyslana'] ?></td>
        <td data-l="W kolejce / błędy" class="num"><?= (int) $r['stat']['kolejka'] ?> / <?= (int) $r['stat']['blad'] ?></td>
        <td data-l="Kliknięcia" class="num"><?= (int) $r['link']['klikniec'] ?></td>
        <td data-l="Zamówienia" class="num"><?= (int) $r['link']['zamowien'] ?></td>
        <td data-l="Obrót" class="num"><?= h(wsm_nl_zl((int) $r['link']['obrot'])) ?></td>
        <td data-l="Wypisy" class="num"><?= (int) $r['wypisy'] ?></td>
        <td><?php if ($isAdmin): ?><form method="post"><?= console_csrf_field() ?><button type="submit" name="duplikuj" value="<?= (int) $r['id'] ?>">Duplikuj</button></form><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="hint" style="margin-top:12px">Kliknięcia i zamówienia liczy link śledzący wydania (<code>?l=nl…</code>) — bez śledzenia osób.</p>
  </div>

<?php else:
  // ═══ L'ÉDITEUR ═══════════════════════════════════════════════════════════
  $szkic = (string) $nl['status'] === 'szkic' && $isAdmin;
  $dis = $szkic ? '' : ' disabled';
  $prev = wsm_nl_preview($pdo, $nl);
  $apercu = wsm_nl_render($pdo, $nl, $jezyk, ['email' => 'podglad@example.com', 'name' => 'Anna'], (string) ($nl['link_code'] ?: 'nl' . $id));
  $produits = wsm_shop_products($pdo, 'pl');
  $tlActif = wsm_ai_enabled();
?>
<div class="nl-ed">
  <form method="post" action="newsletter.php?id=<?= (int) $id ?>&amp;jezyk=<?= h($jezyk) ?>">
    <?= console_csrf_field() ?>

    <?php if ($szkic && $tlActif): ?>
    <div class="panel ai">
      <h2>Napisz z AI</h2>
      <p class="hint">Opisz temat i wskaż produkty. AI zaproponuje temat, nagłówek, tekst i przycisk — <b>zastąpi obecne bloki</b>. Nic nie wychodzi bez Twojej próbki.</p>
      <label class="field"><span>O czym ma być newsletter?</span>
        <textarea name="ai_brief" rows="3" placeholder="Np. Nowa czekolada deserowa 70% na święta, przepis na trufle, darmowa dostawa od 700 zł"></textarea></label>
      <div class="prods"><?php foreach ($produits as $p): ?>
        <label><input type="checkbox" name="ai_ids[]" value="<?= h((string) $p['id']) ?>"> <?= h((string) $p['name']) ?> <small class="muted"><?= h(wsm_nl_zl((int) $p['price'])) ?></small></label>
      <?php endforeach; ?></div>
      <p class="actions" style="margin:10px 0 0"><button class="btn btn--brand" type="submit" name="ai_napisz" value="1">Napisz propozycję</button></p>
    </div>
    <?php elseif ($szkic): ?>
    <p class="hint">Pisanie z AI włączy się po dodaniu klucza Claude w konfiguracji serwera (ten sam co tłumaczenia).</p>
    <?php endif; ?>

    <div class="panel">
      <h2>Wydanie <span class="tag <?= $szkic ? 'wait' : 'ok' ?>"><?= $nl['status'] === 'szkic' ? 'szkic' : 'wysłany' ?></span></h2>
      <div class="grid2">
        <label class="field"><span>Nazwa (tylko dla Ciebie)</span><input type="text" name="nazwa" value="<?= h((string) $nl['nazwa']) ?>"<?= $dis ?>></label>
        <label class="field"><span>Odbiorcy</span><select name="segment"<?= $dis ?>>
          <?php foreach (WSM_MKT_SEGMENTS as $k => $lbl): ?><option value="<?= h($k) ?>"<?= $nl['segment'] === $k ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
        </select></label>
        <label class="field"><span>Temat (z {imie} — imię)</span><input type="text" name="temat" maxlength="250" value="<?= h((string) $nl['temat']) ?>"<?= $dis ?>></label>
        <label class="field"><span>Zajawka (podgląd w skrzynce)</span><input type="text" name="preheader" maxlength="250" value="<?= h((string) $nl['preheader']) ?>"<?= $dis ?>></label>
        <label class="field"><span>Kod rabatowy (z Rabatów, opcjonalnie)</span><input type="text" name="kod" maxlength="40" value="<?= h((string) $nl['kod']) ?>"<?= $dis ?>></label>
      </div>
      <p class="hint" style="margin:6px 0 0">Odbiorców teraz: <b><?= (int) $prev['odbiorcow'] ?></b>
        <?php foreach ($prev['jezyki'] as $l => $n): ?> · <?= h($l) ?>: <?= (int) $n ?><?php endforeach; ?>
        <?php foreach ($prev['bez_tlumaczenia'] as $l => $n): ?><br><b>Uwaga:</b> odbiorcy „<?= h($l) ?>” dostaną <?= (int) $n ?> tekst(y) po polsku — przetłumacz poniżej.<?php endforeach; ?>
        <br><?= wsm_mkt_klienci_bez_zgody()
            ? '<b>Także kupujący bez zgody</b> (włączone w Integracjach). Wymaga opinii prawnika: PKE art. 398.'
            : 'Tylko osoby, które zaznaczyły zgodę i potwierdziły ją mailem. Segmenty klientów zawężają tę listę.' ?></p>
    </div>

    <div class="panel">
      <h2>Bloki</h2>
      <?php foreach ($nl['bloki'] as $i => $b): $t = (string) $b['typ']; ?>
      <div class="blok">
        <div class="blok-tete"><span><?= h(WSM_NL_BLOKI[$t] ?? $t) ?></span><span class="sp"></span>
          <?php if ($szkic): ?>
          <button type="submit" name="gora" value="<?= $i ?>" title="Wyżej" aria-label="Wyżej">↑</button>
          <button type="submit" name="dol" value="<?= $i ?>" title="Niżej" aria-label="Niżej">↓</button>
          <button type="submit" name="usun" value="<?= $i ?>" title="Usuń" aria-label="Usuń blok">✕</button>
          <?php endif; ?></div>
        <input type="hidden" name="b[<?= $i ?>][typ]" value="<?= h($t) ?>">
        <?php if ($t === 'naglowek'): ?>
          <label>Tekst<input type="text" name="b[<?= $i ?>][tekst]" value="<?= h((string) $b['tekst']) ?>"<?= $dis ?>></label>
        <?php elseif ($t === 'tekst'): ?>
          <label>Tekst (pusta linia = nowy akapit, **pogrubienie**)<textarea name="b[<?= $i ?>][tekst]"<?= $dis ?>><?= h((string) $b['tekst']) ?></textarea></label>
        <?php elseif ($t === 'obraz'): ?>
          <label>Adres obrazu (https://… — z Mediów)<input type="url" name="b[<?= $i ?>][url]" value="<?= h((string) $b['url']) ?>"<?= $dis ?>></label>
          <label>Opis obrazu<input type="text" name="b[<?= $i ?>][alt]" value="<?= h((string) $b['alt']) ?>"<?= $dis ?>></label>
          <label>Link po kliknięciu (opcjonalnie)<input type="url" name="b[<?= $i ?>][link]" value="<?= h((string) $b['link']) ?>"<?= $dis ?>></label>
        <?php elseif ($t === 'produkty'): ?>
          <div class="prods"><?php foreach ($produits as $p): ?>
            <label><input type="checkbox" name="b[<?= $i ?>][ids][]" value="<?= h((string) $p['id']) ?>"<?= in_array((string) $p['id'], (array) $b['ids'], true) ? ' checked' : '' ?><?= $dis ?>> <?= h((string) $p['name']) ?></label>
          <?php endforeach; ?></div>
          <small class="muted">Do 6 produktów, po dwa w rzędzie, z ceną i linkiem.</small>
        <?php elseif ($t === 'przycisk'): ?>
          <label>Napis<input type="text" name="b[<?= $i ?>][etykieta]" value="<?= h((string) $b['etykieta']) ?>"<?= $dis ?>></label>
          <label>Adres (puste = strona sklepu)<input type="url" name="b[<?= $i ?>][url]" value="<?= h((string) $b['url']) ?>"<?= $dis ?>></label>
        <?php elseif ($t === 'kod'): ?>
          <label>Kod (puste = kod wydania)<input type="text" name="b[<?= $i ?>][kod]" value="<?= h((string) $b['kod']) ?>"<?= $dis ?>></label>
          <label>Opis<input type="text" name="b[<?= $i ?>][opis]" value="<?= h((string) $b['opis']) ?>"<?= $dis ?>></label>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if ($szkic): ?>
      <div class="dodaj"><?php foreach (WSM_NL_BLOKI as $k => $lbl): ?><button type="submit" name="dodaj" value="<?= h($k) ?>">+ <?= h($lbl) ?></button><?php endforeach; ?></div>
      <p class="actions" style="margin:0"><button class="btn btn--brand" type="submit" name="zapisz" value="1">Zapisz</button></p>
      <?php endif; ?>
    </div>

    <?php foreach (['uk' => 'Ukraiński', 'en' => 'Angielski'] as $l => $lbl): $tr = (array) ($nl['tlumaczenia'][$l] ?? []); ?>
    <details class="panel"<?= $jezyk === $l ? ' open' : '' ?>>
      <summary><b><?= h($lbl) ?></b> — <?= empty($prev['bez_tlumaczenia'][$l]) && !empty($tr) ? 'gotowe' : 'do uzupełnienia' ?></summary>
      <?php if ($szkic && $tlActif): ?><p class="actions"><button type="submit" name="ai_tlumacz" value="<?= h($l) ?>">Przetłumacz z AI (zastąpi poniższe)</button></p><?php endif; ?>
      <label class="field"><span>Temat</span><input type="text" name="tl[<?= $l ?>][temat]" value="<?= h((string) ($tr['temat'] ?? '')) ?>" placeholder="<?= h((string) $nl['temat']) ?>"<?= $dis ?>></label>
      <label class="field"><span>Zajawka</span><input type="text" name="tl[<?= $l ?>][preheader]" value="<?= h((string) ($tr['preheader'] ?? '')) ?>" placeholder="<?= h((string) $nl['preheader']) ?>"<?= $dis ?>></label>
      <?php foreach ($nl['bloki'] as $i => $b): foreach (WSM_NL_TEKSTY[(string) $b['typ']] ?? [] as $k): if (trim((string) ($b[$k] ?? '')) === '') continue; ?>
        <p class="tl-src"><?= h(mb_strimwidth((string) $b[$k], 0, 160, '…')) ?></p>
        <?php if ((string) $b['typ'] === 'tekst'): ?>
          <textarea name="tl[<?= $l ?>][b][<?= $i ?>][<?= h($k) ?>]" rows="4"<?= $dis ?>><?= h((string) ($tr['bloki'][$i][$k] ?? '')) ?></textarea>
        <?php else: ?>
          <input type="text" name="tl[<?= $l ?>][b][<?= $i ?>][<?= h($k) ?>]" value="<?= h((string) ($tr['bloki'][$i][$k] ?? '')) ?>"<?= $dis ?>>
        <?php endif; ?>
      <?php endforeach; endforeach; ?>
      <?php if ($szkic): ?><p class="actions"><button type="submit" name="zapisz" value="1">Zapisz tłumaczenie</button></p><?php endif; ?>
    </details>
    <?php endforeach; ?>

    <?php if ($isAdmin): ?>
    <div class="panel">
      <h2>Próbka i wysyłka</h2>
      <div class="grid2">
        <label class="field"><span>Próbka na adres</span><input type="email" name="adres" value="<?= h((string) ($me['email'] ?? '')) ?>"></label>
        <label class="field"><span>Język próbki</span><select name="probka_jezyk"><?php foreach (WSM_MKT_LANGS as $l): ?><option<?= $l === $jezyk ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
      </div>
      <p class="actions"><button type="submit" name="probka" value="1">Wyślij próbkę</button></p>
      <?php if ($szkic && !wsm_mkt_decide($me)): ?>
      <p class="hint">Wysyłkę do wszystkich zatwierdza administrator: przygotuj kampanię i próbkę, a administrator kliknie „Wyślij”.</p>
      <?php elseif ($szkic): ?>
      <label class="chk" style="display:flex;gap:8px;align-items:center;margin:12px 0"><input type="checkbox" name="potwierdzam" value="1"> Próbka sprawdzona: temat, linki, ceny i kod są poprawne.</label>
      <p class="actions"><button class="btn btn--brand" type="submit" name="wyslij" value="1">Wyślij do <?= (int) $prev['odbiorcow'] ?> odbiorców</button></p>
      <?php else: ?>
      <p class="hint">Wysłany <?= h((string) $nl['sent_at']) ?> do <?= (int) $nl['odbiorcow'] ?> odbiorców. Żeby wysłać poprawioną wersję — wróć do listy i „Duplikuj”.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </form>

  <div class="nl-ap">
    <div class="zaklad"><?php foreach (WSM_MKT_LANGS as $l): ?><a class="<?= $l === $jezyk ? 'on' : '' ?>" href="newsletter.php?id=<?= (int) $id ?>&amp;jezyk=<?= h($l) ?>"><?= h(strtoupper($l)) ?></a><?php endforeach; ?></div>
    <p class="hint" style="margin:0 0 6px">Temat: <b><?= h($apercu['temat']) ?></b></p>
    <iframe sandbox title="Podgląd newslettera" srcdoc="<?= h($apercu['html']) ?>"></iframe>
  </div>
</div>
<?php endif; ?>
<?php console_foot();
