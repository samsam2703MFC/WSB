<?php
// ============================================================================
//  poczta.php — la messagerie de la console.
//
//  Ce que l'écran garantit, et qui n'existait pas avant :
//   • une commande qui dépasse le stock part avec un message au client, et ce
//     message est VISIBLE ici — envoyé, en file, ou en erreur ;
//   • une réponse s'écrit depuis un modèle, avec les variables de la commande
//     déjà remplacées, pour ne pas retaper le numéro et le montant ;
//   • les modèles s'éditent en trois langues sans redéploiement.
//
//  Lecture : tout compte actif. Écriture (envoi, modèles) : Centrala.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/mail.php';
require_once $API . '/inbox.php';
require_once $API . '/invoice.php';
require_once $API . '/translate.php';

$flash = ''; $flashKind = 'ok';
$view  = ($_GET['widok'] ?? '') === 'szablony' ? 'szablony' : 'poczta';

// ---- Actions (réservées à Centrala) ---------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Sans ce jeton, une page tierce fait poster ce formulaire par le
    // navigateur de quelqu'un qui a sa session ouverte, et la console
    // execute la demande comme si elle venait de lui.
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może wysyłać wiadomości.'; $flashKind = 'err';
    } elseif (isset($_POST['wyslij'])) {
        $id = (int) $_POST['wyslij'];
        [$ok, $err] = wsm_mail_send($pdo, $id);
        $flash = $ok ? 'Wiadomość wysłana.' : ('Nie wysłano: ' . ($err ?: 'nieznany błąd'));
        $flashKind = $ok ? 'ok' : 'err';
    } elseif (isset($_POST['tlumacz_wiadomosc'])) {
        // On traduit VERS le polonais pour lire. L'original ne bouge pas :
        // c'est la pièce, et la traduction n'est qu'une aide à la lecture.
        [$tr, $errT] = wsm_tr_message($pdo, (int) $_POST['tlumacz_wiadomosc'],
                                      WSM_LANG_BASE, (string) ($me['nom'] ?? ''));
        if ($tr) { $flash = 'Przetłumaczono — oryginał zostaje nietknięty.'; }
        else     { $flash = 'Nie przetłumaczono: ' . $errT; $flashKind = 'err'; }

    } elseif (isset($_POST['tlumacz_odpowiedz'])) {
        // L'opérateur écrit en polonais, on propose la traduction DANS LE
        // CHAMP. Rien ne part : il relit, corrige, puis envoie. Expédier une
        // phrase que personne n'a lue est exactement ce qu'il ne faut pas faire.
        $cible = (string) ($_POST['lang_klienta'] ?? WSM_LANG_BASE);
        [$suj, $eS] = wsm_tr_text((string) ($_POST['subject'] ?? ''), WSM_LANG_BASE, $cible);
        [$cor, $eC] = wsm_tr_text((string) ($_POST['body'] ?? ''), WSM_LANG_BASE, $cible);
        if ($eS !== null || $eC !== null) {
            $flash = 'Nie przetłumaczono: ' . ($eS ?? $eC); $flashKind = 'err';
            $trDraft = ['email' => (string) ($_POST['email'] ?? ''),
                        'subject' => (string) ($_POST['subject'] ?? ''),
                        'body' => (string) ($_POST['body'] ?? '')];
        } else {
            $flash = 'Przetłumaczono ' . wsm_lang_na($cible)
                   . ' — przeczytaj i popraw przed wysłaniem.';
            $trDraft = ['email' => (string) ($_POST['email'] ?? ''),
                        'subject' => (string) $suj, 'body' => (string) $cor];
        }

    } elseif (isset($_POST['nowa'])) {
        $email = trim((string) ($_POST['email'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = (string) ($_POST['body'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash = 'Adres odbiorcy jest nieprawidłowy.'; $flashKind = 'err';
        } elseif ($subject === '' || trim($body) === '') {
            $flash = 'Temat i treść nie mogą być puste.'; $flashKind = 'err';
        } else {
            $id = wsm_mail_queue($pdo, [
                'order_id'      => ((int) ($_POST['order_id'] ?? 0)) ?: null,
                'email'         => $email,
                'direction'     => 'wyjscie',
                'subject'       => $subject,
                'body'          => $body,
                'template_code' => (string) ($_POST['tpl'] ?? ''),
                'actor'         => (string) ($me['nom'] ?? ''),
            ]);
            if ($id && (int) ($_POST['order_id'] ?? 0)) {
                wsm_order_event($pdo, (int) $_POST['order_id'], 'wiadomosc', $subject, (string) ($me['nom'] ?? ''));
            }
            if ($id && !empty($_POST['teraz'])) {
                [$ok, $err] = wsm_mail_send($pdo, $id);
                $flash = $ok ? 'Wysłano do ' . $email : ('W kolejce — nie udało się wysłać: ' . $err);
                $flashKind = $ok ? 'ok' : 'err';
            } else {
                $flash = $id ? 'Wiadomość zapisana w kolejce.' : 'Nie zapisano wiadomości.';
                $flashKind = $id ? 'ok' : 'err';
            }
        }
    } elseif (isset($_POST['przychodzaca'])) {
        // Un message reçu, collé à la main. Tant que la boîte IMAP n'est pas
        // branchée, c'est ainsi qu'une demande entre dans le système — et le
        // reste du mécanisme est déjà celui qui servira à la relève.
        $from = wsm_inbox_address((string) ($_POST['from'] ?? ''));
        $body = (string) ($_POST['body'] ?? '');
        if ($from === '')            { $flash = 'Podaj adres nadawcy.'; $flashKind = 'err'; }
        elseif (trim($body) === '')  { $flash = 'Treść jest pusta.'; $flashKind = 'err'; }
        else {
            $id = wsm_inbox_store($pdo, $from, (string) ($_POST['subject'] ?? ''), $body, (string) ($me['nom'] ?? ''));
            $flash = $id ? 'Zapisano wiadomość przychodzącą.' : 'Nie zapisano wiadomości.';
            $flashKind = $id ? 'ok' : 'err';
            if ($id) { header('Location: poczta.php?id=' . $id, true, 303); exit; }
        }
    } elseif (isset($_POST['zamow'])) {
        // Les tuiles validées deviennent une commande — par le moteur de la
        // boutique, pas par un chemin parallèle.
        $msg = wsm_message_by_id($pdo, (int) $_POST['zamow']);
        $items = [];
        foreach ((array) ($_POST['qty'] ?? []) as $pid => $q) {
            if (!empty($_POST['take'][$pid])) $items[(string) $pid] = (int) $q;
        }
        if (!$msg)        { $flash = 'Nie znaleziono wiadomości.'; $flashKind = 'err'; }
        elseif (!$items)  { $flash = 'Nie zaznaczono żadnej pozycji.'; $flashKind = 'err'; }
        else {
            $extra = [];
            foreach (['delivery_method', 'inpost_point', 'phone', 'company',
                      'ship_street', 'ship_building', 'ship_postcode', 'ship_city'] as $k) {
                if (trim((string) ($_POST[$k] ?? '')) !== '') $extra[$k] = trim((string) $_POST[$k]);
            }
            [$ord, $errs] = wsm_inbox_create_order($pdo, $msg, $items, $extra);
            if (!$ord) {
                $flash = 'Nie utworzono zamówienia: ' . implode(' · ', array_map(
                    fn($k, $v) => $k . ' — ' . $v, array_keys($errs), $errs));
                $flashKind = 'err';
            } else {
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Zamówienie z maila', $ord['code'], 'Sieć');
                $pdo->prepare("UPDATE wsm_messages SET order_id = ? WHERE id = ?")
                    ->execute([(int) $ord['id'], (int) $msg['id']]);
                $flash = 'Utworzono zamówienie ' . $ord['code'] . ' — sprawdź je przed potwierdzeniem.';
            }
        }
    } elseif (isset($_POST['szablon'])) {
        $id = (int) $_POST['szablon'];
        $ev = (string) ($_POST['event'] ?? '');
        if ($ev !== '' && !isset(WSM_MAIL_EVENTS[$ev])) $ev = '';
        $pdo->prepare("UPDATE wsm_mail_templates SET name = ?, subject = ?, body = ?, event = ?, active = ? WHERE id = ?")
            ->execute([
                mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120),
                mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, 250),
                (string) ($_POST['body'] ?? ''),
                $ev,
                empty($_POST['active']) ? 0 : 1,
                $id,
            ]);
        wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Szablon poczty', 'wsm_mail_templates #' . $id, 'Sieć');
        $flash = 'Szablon zapisany.';
        $view = 'szablony';
    }
}

// ---- Lectures --------------------------------------------------------------
$kpis     = wsm_mail_kpis($pdo);
$blockers = wsm_mail_blockers();
$detail   = isset($_GET['id']) ? wsm_message_by_id($pdo, (int) $_GET['id']) : null;
$edit     = isset($_GET['szablon']) ? (function (PDO $pdo, int $id) {
                $st = $pdo->prepare("SELECT * FROM wsm_mail_templates WHERE id = ?");
                $st->execute([$id]);
                return $st->fetch() ?: null;
            })($pdo, (int) $_GET['szablon']) : null;

$messages  = wsm_messages_list($pdo, ['status' => (string) ($_GET['status'] ?? ''), 'q' => (string) ($_GET['q'] ?? '')]);
$templates = wsm_mail_templates($pdo);
$orders    = wsm_orders_list($pdo, 60);

// Rédaction : commande et modèle choisis en amont, variables déjà remplacées.
$pickOrder = (int) ($_GET['order_id'] ?? 0);
$pickTpl   = (string) ($_GET['tpl'] ?? '');
$draft = $trDraft ?? ['email' => '', 'subject' => '', 'body' => ''];
$draftOrder = $pickOrder ? wsm_order_by_id($pdo, $pickOrder) : null;
if ($draftOrder) $draft['email'] = (string) $draftOrder['email'];
if ($pickTpl !== '') {
    $tpl = wsm_mail_template($pdo, $pickTpl, (string) ($draftOrder['lang'] ?? 'pl'));
    if ($tpl) {
        $vars = wsm_mail_vars($draftOrder);
        $draft['subject'] = wsm_mail_render((string) $tpl['subject'], $vars);
        $draft['body']    = wsm_mail_render((string) $tpl['body'], $vars);
    }
}

// La langue dans laquelle écrire à ce client : celle qu'il a choisie sur la
// boutique si on l'a, sinon celle détectée dans son dernier message.
$langClient = WSM_LANG_BASE;
if (($draft['email'] ?? '') !== '')          $langClient = wsm_tr_lang_client($pdo, (string) $draft['email']);
elseif ($detail && ($detail['email'] ?? '')) $langClient = wsm_tr_lang_client($pdo, (string) $detail['email']);
$traduction = $detail ? wsm_tr_cached($pdo, (int) $detail['id'], WSM_LANG_BASE) : null;
$iaPrete = wsm_tr_enabled();

$statusTag = ['wyslana' => 'ok', 'kolejka' => 'wait', 'wysylanie' => 'wait', 'pominieta' => '', 'blad' => 'bad'];
$statusLbl = ['wyslana' => 'Wysłana', 'kolejka' => 'W kolejce', 'wysylanie' => 'Wysyłanie', 'pominieta' => 'Pominięta (wypisany)', 'blad' => 'Błąd'];

console_head('Poczta', $me, <<<'CSS'
  /* La traduction se lit comme une note en marge, pas comme le message :
     le cadre et la teinte disent qu'on n'est plus dans les mots du client. */
  .tradu { margin-top: 14px; padding: 12px 14px; border: 1px solid var(--border-subtle);
           border-left: 3px solid var(--accent); border-radius: 10px;
           background: var(--surface-sunken); }
  .tradu h3 { margin: 0 0 6px; font-size: 14px; }
  .tradu pre { margin: 6px 0 0; }
  .tiles { display: grid; grid-template-columns: 1fr; gap: 10px; margin: 12px 0 4px; }
  @media (min-width: 760px) { .tiles { grid-template-columns: 1fr 1fr; } }
  .tile { display: grid; grid-template-columns: auto 1fr auto; gap: 12px; align-items: start;
          padding: 12px 14px; border: 1px solid var(--border-subtle); border-radius: 12px;
          background: var(--surface-card); cursor: pointer; }
  .tile:has(input[type=checkbox]:checked) { border-color: var(--brand); box-shadow: 0 0 0 1px var(--brand); }
  .tile .tb { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
  .tile .src { font-size: 12px; color: var(--text-body); font-style: italic; overflow-wrap: anywhere; }
  .tile .meta { font-family: var(--font-mono); font-size: 11px; color: var(--text-muted); }
  .tile .tq input { width: 74px; }
CSS, $kpis['queued'] ? $kpis['queued'] . ' w kolejce' : '');
console_flash($flash, $flashKind);
console_crumbs($detail
    ? ['Pulpit' => 'pulpit.php', 'Poczta' => 'poczta.php', $detail['subject'] => null]
    : ($view === 'szablony'
        ? ['Pulpit' => 'pulpit.php', 'Poczta' => 'poczta.php', 'Szablony' => null]
        : ['Pulpit' => 'pulpit.php', 'Poczta' => null]));
?>

<?php if ($blockers): ?>
<p class="warnbox">
  Poczta jeszcze nie wysyła — brakuje: <?= h(implode(', ', $blockers)) ?>.
  Wiadomości są zapisywane w kolejce i nic nie ginie; uzupełnij dane w
  <a href="ustawienia.php">Integracjach</a>, potem wyślij je stąd jednym kliknięciem.
</p>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><b><?= (int) $kpis['total'] ?></b><span>Wiadomości</span></div>
  <div class="kpi"><b><?= (int) $kpis['sent'] ?></b><span>Wysłane</span></div>
  <div class="kpi"><b><?= (int) $kpis['queued'] ?></b><span>W kolejce</span></div>
  <div class="kpi"><b><?= (int) $kpis['failed'] ?></b><span>Błędy</span></div>
</div>

<p class="tabs">
  <a href="poczta.php"<?= $view === 'poczta' ? ' class="on"' : '' ?>>Skrzynka</a>
  <a href="poczta.php?widok=szablony"<?= $view === 'szablony' ? ' class="on"' : '' ?>>Szablony i automaty</a>
</p>

<?php if ($view === 'szablony'): ?>

  <?php if ($edit): ?>
  <div class="panel">
    <h2>Szablon <?= h($edit['code']) ?> · <?= h(strtoupper((string) $edit['lang'])) ?></h2>
    <form method="post">
      <?= console_csrf_field() ?>
      <input type="hidden" name="szablon" value="<?= (int) $edit['id'] ?>">
      <div class="grid2">
        <label class="field"><span>Nazwa robocza</span>
          <input type="text" name="name" value="<?= h($edit['name']) ?>"></label>
        <label class="field"><span>Wysyłaj automatycznie przy</span>
          <select name="event">
            <option value="">— nie wysyłaj automatycznie —</option>
            <?php foreach (WSM_MAIL_EVENTS as $ev => $lbl): ?>
            <option value="<?= h($ev) ?>"<?= $ev === $edit['event'] ? ' selected' : '' ?>><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select></label>
      </div>
      <label class="field"><span>Temat</span>
        <input type="text" name="subject" value="<?= h($edit['subject']) ?>"></label>
      <label class="field"><span>Treść</span>
        <textarea name="body" rows="14"><?= h($edit['body']) ?></textarea>
        <span class="hint">Zmienne: {{numer}} {{imie}} {{nazwisko}} {{firma}} {{kwota}} {{pozycje}}
          {{brakujace}} {{paczkomat}} {{link}} {{data}} — wstawiane przy wysyłce.</span></label>
      <label class="field" style="display:flex;gap:10px;align-items:center">
        <input type="checkbox" name="active" value="1"<?= $edit['active'] ? ' checked' : '' ?>>
        <span style="margin:0;text-transform:none;letter-spacing:0;font-size:14px;color:var(--text-strong)">Szablon aktywny</span>
      </label>
      <div class="actions">
        <button class="primary" type="submit"<?= $isAdmin ? '' : ' disabled' ?>>Zapisz szablon</button>
        <a class="code" href="poczta.php?widok=szablony">Anuluj</a>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="panel">
    <h2>Szablony</h2>
    <p class="muted small">
      Modele wiadomości, po jednym na język. „Wysyłaj automatycznie przy” decyduje,
      co klient dostaje bez udziału człowieka — w szczególności wtedy, gdy zamówienie
      przekracza stan magazynu: zamówienie przechodzi, a klient od razu wie, że się odezwiemy.
    </p>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Kod</th><th>Język</th><th>Temat</th><th>Automat</th><th>Stan</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($templates as $t): ?>
      <tr>
        <td data-l="Kod"><?= h($t['code']) ?></td>
        <td data-l="Język"><?= h(strtoupper((string) $t['lang'])) ?></td>
        <td data-l="Temat" class="wide"><?= h($t['subject']) ?></td>
        <td data-l="Automat"><?= $t['event'] !== '' ? '<span class="tag">' . h(WSM_MAIL_EVENTS[$t['event']] ?? $t['event']) . '</span>' : '<span class="muted">ręcznie</span>' ?></td>
        <td data-l="Stan"><span class="tag <?= $t['active'] ? 'ok' : 'bad' ?>"><?= $t['active'] ? 'aktywny' : 'wyłączony' ?></span></td>
        <td data-l=""><a class="code" href="poczta.php?widok=szablony&amp;szablon=<?= (int) $t['id'] ?>">Edytuj</a></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

<?php else: ?>

  <?php if ($detail): ?>
  <div class="panel">
    <h2><?= h($detail['subject']) ?></h2>
    <dl class="kv">
      <dt>Do</dt><dd><?= h($detail['email']) ?></dd>
      <dt>Stan</dt><dd><span class="tag <?= h($statusTag[$detail['status']] ?? '') ?>"><?= h($statusLbl[$detail['status']] ?? $detail['status']) ?></span>
        <?= $detail['error'] !== '' ? ' · ' . h($detail['error']) : '' ?></dd>
      <dt>Utworzono</dt><dd><?= h((string) $detail['created_at']) ?><?= $detail['sent_at'] ? ' · wysłano ' . h((string) $detail['sent_at']) : '' ?></dd>
      <dt>Autor</dt><dd><?= h($detail['actor']) ?><?= $detail['template_code'] !== '' ? ' · szablon ' . h($detail['template_code']) : '' ?></dd>
      <?php if ($detail['order_code']): ?>
      <dt>Zamówienie</dt><dd><a class="code" href="zamowienia.php?id=<?= (int) $detail['order_id'] ?>"><?= h($detail['order_code']) ?></a></dd>
      <?php endif; ?>
    </dl>
    <pre style="white-space:pre-wrap"><?= h($detail['body']) ?></pre>

    <?php
    // La traduction est présentée À CÔTÉ de l'original, jamais à sa place :
    // ce que le client a écrit est la pièce, et une machine se trompe. On
    // affiche donc la langue détectée, puis la lecture polonaise en dessous.
    [$langDetectee, $confiance] = wsm_tr_detect((string) $detail['subject'] . "\n" . (string) $detail['body']);
    ?>
    <?php if ($detail['direction'] === 'wejscie' && $langDetectee !== WSM_LANG_BASE): ?>
      <?php if ($traduction): ?>
      <div class="tradu">
        <h3>Po polsku <span class="tag">tłumaczenie maszynowe</span></h3>
        <p class="muted small">
          Oryginał powyżej zostaje nietknięty — to on jest dokumentem.
          Wykryty język: <b><?= h(wsm_lang_name((string) ($traduction['src_lang'] ?: $langDetectee))) ?></b>.
        </p>
        <?php if (($traduction['subject'] ?? '') !== ''): ?>
        <p><b><?= h((string) $traduction['subject']) ?></b></p>
        <?php endif; ?>
        <pre style="white-space:pre-wrap"><?= h((string) $traduction['body']) ?></pre>
      </div>
      <?php elseif ($iaPrete && $isAdmin): ?>
      <form method="post" style="margin-top:12px">
      <?= console_csrf_field() ?>
        <p class="muted small">
          Wiadomość wygląda na napisaną <b><?= h(wsm_lang_po($langDetectee)) ?></b><?php
          if ($confiance < 0.3): ?> <span class="tag">niepewne</span><?php endif; ?>.
          Tłumaczenie zostanie zapisane obok — nie zastąpi oryginału, i płaci się za nie raz.
        </p>
        <button class="btn sm" name="tlumacz_wiadomosc" value="<?= (int) $detail['id'] ?>">
          Przetłumacz na polski</button>
      </form>
      <?php elseif (!$iaPrete): ?>
      <p class="muted small" style="margin-top:12px">
        Wiadomość wygląda na napisaną <b><?= h(wsm_lang_po($langDetectee)) ?></b>.
        Tłumaczenie automatyczne nie jest skonfigurowane (klucz API żyje w <code>config.local.php</code>).
      </p>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($detail['direction'] === 'wejscie'):
      $an = wsm_inbox_parse($pdo, (string) $detail['body']);
      $cli = wsm_inbox_client($pdo, (string) $detail['email']); ?>
    <h3>Rozpoznane pozycje</h3>
    <p class="muted small">
      Propozycja, nie decyzja. Każdy kafelek pokazuje linię, z której pochodzi, i zgadniętą ilość —
      popraw, odznacz, dopiero potem twórz zamówienie. Ceny biorą się <b>z katalogu</b>, nigdy z treści maila.
      <?= $cli ? 'Nadawca rozpoznany jako <b>' . h((string) ($cli['raison'] ?? $cli['email'])) . '</b> — dane do wysyłki uzupełnią się same.'
               : 'Nadawcy nie ma w kontrahentach — adres wysyłki trzeba będzie uzupełnić.' ?>
    </p>

    <?php if (!$an['lines']): ?>
    <p class="muted small">Nie rozpoznano żadnego produktu.</p>
    <?php else: ?>
    <form method="post">
      <?= console_csrf_field() ?>
      <input type="hidden" name="zamow" value="<?= (int) $detail['id'] ?>">
      <div class="tiles">
        <?php foreach ($an['lines'] as $ln): $p = $ln['product']; ?>
        <label class="tile">
          <span class="tk"><input type="checkbox" name="take[<?= h($p['id']) ?>]" value="1" checked></span>
          <span class="tb">
            <b><?= h($p['name']) ?></b>
            <span class="src">„<?= h($ln['line']) ?>”</span>
            <span class="meta"><?= h($ln['how']) ?> · stan <?= (int) $p['stock'] ?>
              <?= $p['visible'] ? '' : ' · <span class="tag off">ukryty w sklepie</span>' ?></span>
          </span>
          <span class="tq">
            <input type="number" name="qty[<?= h($p['id']) ?>]" min="1" value="<?= (int) $ln['qty'] ?>">
          </span>
        </label>
        <?php endforeach; ?>
      </div>

      <?php
      // Une commande ne peut pas naître sans destination. Pour un client connu
      // on préremplit ; pour un inconnu, l'opérateur recopie ce qu'il lit dans
      // le mail — c'est le seul endroit où l'humain doit taper.
      $pre = fn(string $k, string $d = '') => h((string) ($cli[$k] ?? $d));
      ?>
      <h3>Wysyłka</h3>
      <p class="muted small">Bez adresu zamówienia nie da się utworzyć. Sprawdź, co klient napisał w mailu.</p>
      <div class="grid2">
        <label class="field"><span>Sposób dostawy</span>
          <?php // LA LISTE VIENT DE LA BASE. Écrite en dur, elle ne proposait
                // que les deux services d'origine : une commande saisie ici à
                // la main ne pouvait donc jamais partir par un transporteur
                // ajouté ensuite, sans que rien ne l'explique. ?>
          <select name="delivery_method" id="dm">
            <?php foreach (wsm_shipping_methods($pdo, 'pl') as $sm): ?>
            <option value="<?= h((string) $sm['id']) ?>"><?= h((string) $sm['label']) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label class="field"><span>Telefon</span>
          <input type="text" name="phone" value="<?= $pre('phone') ?>" placeholder="600 100 200"></label>
        <label class="field"><span>Paczkomat (kod)</span>
          <input type="text" name="inpost_point" value="<?= $pre('inpost_point') ?>" placeholder="WRO01A"></label>
        <label class="field"><span>Firma / nabywca</span>
          <input type="text" name="company" value="<?= $pre('raison') ?>"></label>
        <label class="field"><span>Ulica</span>
          <input type="text" name="ship_street" value="<?= $pre('bill_street') ?>"></label>
        <label class="field"><span>Numer</span>
          <input type="text" name="ship_building" value="<?= $pre('bill_building') ?>"></label>
        <label class="field"><span>Kod pocztowy</span>
          <input type="text" name="ship_postcode" value="<?= $pre('bill_postcode') ?>" placeholder="50-078"></label>
        <label class="field"><span>Miasto</span>
          <input type="text" name="ship_city" value="<?= $pre('bill_city') ?>"></label>
      </div>

      <div class="actions">
        <button class="primary" type="submit"<?= $isAdmin ? '' : ' disabled' ?>>Utwórz zamówienie do zatwierdzenia</button>
      </div>
    </form>
    <?php endif; ?>

    <?php if ($an['unknown']): ?>
    <h3>Nie rozpoznano</h3>
    <p class="muted small">Te linie wyglądają na prośbę, ale nie dało się ich przypisać.
      Pokazujemy je, zamiast po cichu pominąć — inaczej gubiłoby się zamówienia.</p>
    <ul class="small">
      <?php foreach ($an['unknown'] as $u): ?>
      <li>„<?= h($u['line']) ?>” — <span class="muted"><?= h($u['why']) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($isAdmin && $detail['direction'] === 'wyjscie' && $detail['status'] !== 'wyslana'): ?>
    <form method="post" class="actions">
      <?= console_csrf_field() ?>
      <button class="primary" type="submit" name="wyslij" value="<?= (int) $detail['id'] ?>">Wyślij teraz</button>
    </form>
    <?php endif; ?>
    <a class="code back" href="poczta.php">← Skrzynka</a>
  </div>
  <?php endif; ?>

  <div class="panel">
    <h2>Nowa wiadomość</h2>
    <form method="get">
      <input type="hidden" name="widok" value="poczta">
      <div class="grid2">
        <label class="field"><span>Zamówienie</span>
          <select name="order_id">
            <option value="0">— bez zamówienia —</option>
            <?php foreach ($orders as $o): ?>
            <option value="<?= (int) $o['id'] ?>"<?= (int) $o['id'] === $pickOrder ? ' selected' : '' ?>>
              <?= h($o['code']) ?> · <?= h($o['client']) ?><?= !empty($o['backorder']) ? ' · do potwierdzenia' : '' ?>
            </option>
            <?php endforeach; ?>
          </select></label>
        <label class="field"><span>Szablon</span>
          <select name="tpl">
            <option value="">— pusty —</option>
            <?php foreach ($templates as $t): if (!$t['active']) continue; ?>
            <option value="<?= h($t['code']) ?>"<?= $t['code'] === $pickTpl ? ' selected' : '' ?>>
              <?= h($t['code']) ?> · <?= h($t['name']) ?>
            </option>
            <?php endforeach; ?>
          </select></label>
      </div>
      <div class="actions"><button type="submit">Wczytaj szablon</button></div>
    </form>

    <form method="post" style="margin-top:12px">
      <?= console_csrf_field() ?>
      <input type="hidden" name="nowa" value="1">
      <input type="hidden" name="order_id" value="<?= $pickOrder ?>">
      <input type="hidden" name="tpl" value="<?= h($pickTpl) ?>">
      <label class="field"><span>Do</span>
        <input type="email" name="email" value="<?= h($draft['email']) ?>" required></label>
      <label class="field"><span>Temat</span>
        <input type="text" name="subject" value="<?= h($draft['subject']) ?>" required></label>
      <label class="field"><span>Treść</span>
        <textarea name="body" rows="12" required><?= h($draft['body']) ?></textarea></label>
      <label class="field" style="display:flex;gap:10px;align-items:center">
        <input type="checkbox" name="teraz" value="1" checked>
        <span style="margin:0;text-transform:none;letter-spacing:0;font-size:14px;color:var(--text-strong)">Wyślij od razu</span>
      </label>
      <div class="actions">
        <button class="primary" type="submit"<?= $isAdmin ? '' : ' disabled' ?>>Zapisz i wyślij</button>
        <?php if ($iaPrete && $langClient !== WSM_LANG_BASE): ?>
        <?php // Le bouton porte le NOM du bouton d'envoi voisin : on veut que
              // la différence soit lisible d'un coup d'œil, parce que l'un
              // écrit au client et l'autre non. ?>
        <input type="hidden" name="lang_klienta" value="<?= h($langClient) ?>">
        <button class="ghost" type="submit" name="tlumacz_odpowiedz" value="1"<?= $isAdmin ? '' : ' disabled' ?>>
          Przetłumacz <?= h(wsm_lang_na($langClient)) ?></button>
        <?php endif; ?>
      </div>
      <?php if ($langClient !== WSM_LANG_BASE): ?>
      <p class="muted small">
        Ten klient pisze <b><?= h(wsm_lang_po($langClient)) ?></b>.
        <?php if ($iaPrete): ?>
          Napisz po polsku i kliknij „Przetłumacz” — tekst wróci <b>do tego pola</b>, do przeczytania
          i poprawienia. Nic nie wychodzi, dopóki go nie wyślesz: wysłanie zdania, którego nikt nie
          przeczytał, jest dokładnie tym, czego robić nie należy.
        <?php else: ?>
          Tłumaczenie automatyczne nie jest skonfigurowane.
        <?php endif; ?>
      </p>
      <?php endif; ?>
    </form>
  </div>

  <div class="panel">
    <h2>Wiadomość przychodząca</h2>
    <p class="muted small">
      Wklej maila od klienta. System rozpozna produkty i zaproponuje zamówienie do zatwierdzenia.
      Gdy podłączymy skrzynkę IMAP (Ustawienia), wiadomości będą tu trafiać same — mechanizm jest ten sam.
    </p>
    <form method="post">
      <?= console_csrf_field() ?>
      <input type="hidden" name="przychodzaca" value="1">
      <div class="grid2">
        <label class="field"><span>Od (adres)</span>
          <input type="text" name="from" placeholder="jan@cukiernia.pl" required></label>
        <label class="field"><span>Temat</span>
          <input type="text" name="subject" placeholder="Zamówienie"></label>
      </div>
      <label class="field"><span>Treść wiadomości</span>
        <textarea name="body" rows="8" required placeholder="Dzień dobry, poproszę 3 x Czekolada ciemna 70%..."></textarea></label>
      <div class="actions"><button class="primary" type="submit"<?= $isAdmin ? '' : ' disabled' ?>>Zapisz i rozpoznaj</button></div>
    </form>
  </div>

  <div class="panel">
    <h2>Skrzynka</h2>
    <form method="get" class="actions" style="margin-bottom:12px">
      <input type="search" name="q" value="<?= h((string) ($_GET['q'] ?? '')) ?>" placeholder="adres lub temat">
      <?php // Un lecteur d'écran annonce « liste déroulante » et rien d'autre :
            // sans nom, on ne sait pas si l'on filtre un état, une langue ou
            // un client. L'étiquette est visuellement inutile, pas sonore. ?>
      <select name="status" aria-label="Filtruj po stanie wiadomości">
        <option value="">wszystkie stany</option>
        <?php foreach (WSM_MAIL_STATUSES as $s): ?>
        <option value="<?= h($s) ?>"<?= ($_GET['status'] ?? '') === $s ? ' selected' : '' ?>><?= h($statusLbl[$s] ?? $s) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Szukaj</button>
    </form>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Kiedy</th><th>Do</th><th>Temat</th><th>Zamówienie</th><th>Stan</th><th></th></tr></thead>
      <tbody>
      <?php if (!$messages): ?>
      <tr><td colspan="6" class="muted">Brak wiadomości.</td></tr>
      <?php endif; ?>
      <?php foreach ($messages as $m): ?>
      <tr>
        <td data-l="Kiedy" class="num"><?= h(substr((string) $m['created_at'], 0, 16)) ?></td>
        <td data-l="Do"><?= h($m['email']) ?></td>
        <td data-l="Temat" class="wide"><a class="code" href="poczta.php?id=<?= (int) $m['id'] ?>"><?= h($m['subject']) ?></a></td>
        <td data-l="Zamówienie"><?= $m['order_code'] ? '<a class="code" href="zamowienia.php?id=' . (int) $m['order_id'] . '">' . h($m['order_code']) . '</a>' : '—' ?></td>
        <td data-l="Stan"><span class="tag <?= h($statusTag[$m['status']] ?? '') ?>"><?= h($statusLbl[$m['status']] ?? $m['status']) ?></span></td>
        <td data-l="">
          <?php if ($isAdmin && $m['status'] !== 'wyslana' && $m['direction'] === 'wyjscie'): ?>
          <form method="post">
      <?= console_csrf_field() ?><button type="submit" name="wyslij" value="<?= (int) $m['id'] ?>">Wyślij</button></form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

<?php endif; ?>
<?php console_foot();
