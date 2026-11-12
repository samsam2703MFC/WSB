<?php
declare(strict_types=1);
/**
 * meta.php — Facebook et Instagram : le pixel, les achats envoyés par le
 * serveur, le catalogue, et la page qui publie.
 *
 * Quatre branchements, chacun avec son test ici même :
 *   · domaine vérifié (sans lui, Meta limite la mesure des achats) ;
 *   · pixel + Conversions API (l'achat compté même quand le navigateur bloque
 *     le pixel — mais seulement pour qui a dit oui aux cookies marketing) ;
 *   · catalogue (le même fichier que Google : boutique Facebook/Instagram,
 *     publicités catalogue, produits identifiés dans les posts) ;
 *   · page + compte Instagram (Marketing → Posty FB / IG).
 */
require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/settings.php';
require_once $API . '/reklama.php';
require_once $API . '/social.php';
require_once $API . '/shop.php';
require_once $API . '/delivery.php';   // wsm_audit

$flash = ''; $kind = 'ok'; $check = null;
$qui = (string) ($me['nom'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) { $flash = 'Tylko do odczytu.'; $kind = 'err'; }
    elseif (isset($_POST['zapisz']) || isset($_POST['zapisz_ig'])) {
        $refus = [];
        $post = $_POST;
        if (isset($_POST['zapisz_ig'])) $post = ['meta__ig_user_id' => preg_replace('/\D/', '', (string) $_POST['zapisz_ig'])];
        $changed = wsm_settings_save($pdo, $post, $qui, $refus, ['meta']);
        wsm_settings_apply($pdo);
        if ($changed) wsm_audit($pdo, $qui, 'Meta', implode(', ', $changed), 'Sieć');
        $msg = $refus ? 'Odrzucone: ' . implode(', ', array_keys($refus)) : ($changed ? 'Zapisano: ' . count($changed) . '.' : 'Nic nie zmieniono.');
        header('Location: meta.php?ok=' . rawurlencode($msg) . '&k=' . ($refus ? 'err' : 'ok'), true, 303);
        exit;
    } elseif (isset($_POST['sprawdz'])) {
        $check = wsm_social_check();
        if (!$check['ok']) { $flash = 'Połączenie ze stroną nie działa: ' . $check['error']; $kind = 'err'; }
    } elseif (isset($_POST['ponow'])) {
        $n = wsm_capi_retry_all($pdo);
        $r = wsm_capi_pump($pdo, 20);
        $flash = 'Ponowiono ' . $n . ' · wysłane teraz: ' . $r['wyslane'] . ($r['bledy'] ? ' · nadal błędy: ' . $r['bledy'] : '');
        $kind = $r['bledy'] ? 'warn' : 'ok';
    } elseif (isset($_POST['test_capi'])) {
        $c = wsm_ads_cfg();
        if (!wsm_capi_ready($c) || $c['test_code'] === '') { $flash = 'Do testu potrzebne: ID piksela, token Conversions API i kod zdarzeń testowych.'; $kind = 'err'; }
        else {
            // Un PageView de test, avec le navigateur de la personne qui clique :
            // il apparaît dans « Zdarzenia testowe » du Menedżer zdarzeń, et nulle
            // part dans les statistiques réelles.
            $r = wsm_graph_call('POST', $c['pixel'] . '/events', ['data' => [[
                'event_name' => 'PageView', 'event_time' => time(), 'action_source' => 'website',
                'event_source_url' => wsm_shop_base_url() . '/', 'event_id' => 'test-' . bin2hex(random_bytes(4)),
                'user_data' => ['client_ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'client_user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'konsola')],
            ]], 'test_event_code' => $c['test_code']], $c['capi'], [], $c['graph']);
            $flash = $r['ok'] ? 'Zdarzenie testowe przyjęte — sprawdź Menedżer zdarzeń → Zdarzenia testowe.' : 'Meta odrzuciła test: ' . $r['error'];
            $kind = $r['ok'] ? 'ok' : 'err';
        }
    }
}
if (isset($_GET['ok'])) { $flash = (string) $_GET['ok']; $kind = ($_GET['k'] ?? '') === 'err' ? 'err' : 'ok'; }

$pompe = $isAdmin ? wsm_capi_pump($pdo, 5) : ['wyslane' => 0, 'bledy' => 0];   // un rôle en lecture ne déclenche rien
$cfg = wsm_ads_cfg();
$view = wsm_settings_view($pdo);
$shop = wsm_shop_base_url();
$zgody = wsm_ads_consent_stats($pdo);
$log = wsm_capi_log($pdo, 30);
$wyslane = count(array_filter($log, fn($r) => $r['status'] === 'wyslane'));
$igAdres = wsm_social_ig_adres($shop);

console_head('Meta — Facebook i Instagram', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 14px; margin: 0 0 16px; max-width: 88ch; line-height: 1.6; }
  .lista { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
  .lista li { display: grid; grid-template-columns: 28px minmax(0, 1fr); gap: 10px; align-items: start; }
  .lista .ico { width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: 13px; }
  .ico.ok { background: color-mix(in srgb, var(--success) 18%, transparent); color: var(--success); }
  .ico.bad { background: color-mix(in srgb, var(--danger) 14%, transparent); color: var(--danger); }
  .ico.wait { background: color-mix(in srgb, var(--warning) 18%, transparent); color: var(--accent-600); }
  .lista b { color: var(--text-strong); }
  .lista p { margin: 2px 0 0; color: var(--text-muted); font-size: 14px; }
  code.url { word-break: break-all; }
CSS);
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Marketing' => null, 'Meta' => null]);
$etap = function (bool $ok, string $titre, string $txt, bool $attente = false): void {
    $c = $ok ? 'ok' : ($attente ? 'wait' : 'bad');
    echo '<li><span class="ico ' . $c . '" aria-hidden="true">' . ($ok ? '✓' : ($attente ? '…' : '!')) . '</span><div><b>' . h($titre) . '</b>'
       . ' <span class="sr-only">' . ($ok ? 'gotowe' : 'do zrobienia') . '</span><p>' . $txt . '</p></div></li>';
};
?>
<div class="kpis">
  <div class="kpi"><b><?= $cfg['pixel'] !== '' ? 'tak' : 'nie' ?></b><span>Piksel Meta</span></div>
  <div class="kpi"><b><?= wsm_capi_ready($cfg) ? 'tak' : 'nie' ?></b><span>Zakupy z serwera (Conversions API)</span></div>
  <div class="kpi"><b><?= $zgody['n'] ? (int) round(100 * $zgody['marketingowe'] / $zgody['n']) . ' %' : '—' ?></b><span>Kupujący ze zgodą marketingową (30 dni)</span></div>
  <div class="kpi"><b><?= $wyslane ?></b><span>Zakupy wysłane do Meta (ostatnie 30 zdarzeń)</span></div>
</div>
<?php if ($pompe['wyslane'] > 0): ?><p class="hint">Przy otwarciu ekranu wysłano <?= (int) $pompe['wyslane'] ?> zaległych zakupów.</p><?php endif; ?>
<?php if ($cfg['bledne']): $b = array_intersect_key($cfg['bledne'], array_flip(['pixel', 'capi', 'test_code', 'fb_domain', 'page_id', 'page_token', 'ig', 'graph'])); if ($b): ?>
<p class="warnbox">Te wartości mają zły format i <b>nie są używane</b>: <?= h(implode(', ', $b)) ?>.</p>
<?php endif; endif; ?>

<div class="panel"><h2>Krok po kroku</h2>
  <ul class="lista">
  <?php
  $etap($cfg['fb_domain'] !== '', 'Weryfikacja domeny',
      $cfg['fb_domain'] !== '' ? 'Kod jest na każdej stronie. W Business Manager kliknij „Zweryfikuj domenę”.'
                               : 'business.facebook.com → Ustawienia firmy → Bezpieczeństwo marki → Domeny → „Meta-tag”. Wymaga domeny (nie adresu IP).');
  $etap($cfg['pixel'] !== '', 'Piksel Meta',
      $cfg['pixel'] !== '' ? 'Ładuje się po zgodzie „marketingowe”. Zdarzenia: PageView, ViewContent, AddToCart, InitiateCheckout, Purchase (z wartością i produktami).'
                           : 'Menedżer zdarzeń → Połącz źródła danych → Internet → ID zbioru danych (same cyfry).');
  $etap(wsm_capi_ready($cfg), 'Conversions API — zakup z serwera',
      wsm_capi_ready($cfg) ? 'Każde opłacone zamówienie klienta ze zgodą marketingową idzie do Meta z serwera, z tym samym identyfikatorem co piksel — Meta liczy je raz. E-mail i telefon wysyłane tylko jako skrót SHA-256.'
                           : 'Menedżer zdarzeń → zbiór danych → Ustawienia → Conversions API → „Wygeneruj token dostępu”. Wklej w polu niżej.');
  $etap(true, 'Katalog produktów (sklep na Facebooku i Instagramie)',
      'Commerce Manager → Katalog → Źródła danych → Dodaj produkty → Lista danych → „Zaplanowane pobieranie” (codziennie): <code class="url">' . h($shop) . '/feed/produkty.xml</code>. Ten sam plik co dla Google.');
  $etap($cfg['page_id'] !== '' && $cfg['page_token'] !== '', 'Strona Facebook — publikowanie postów',
      $cfg['page_id'] !== '' && $cfg['page_token'] !== '' ? 'Posty z Marketing → Posty FB / IG wychodzą na stronę. Sprawdź połączenie przyciskiem poniżej.'
                                                         : 'Potrzebne: ID strony i bezterminowy token strony (aplikacja Meta z uprawnieniami publikowania).');
  $etap($cfg['ig'] !== '' && $igAdres === '', 'Instagram — publikowanie postów',
      $cfg['ig'] === '' ? 'Konto firmowe Instagram połączone ze stroną. „Sprawdź połączenie” odczyta jego ID.'
                        : ($igAdres === '' ? 'Gotowe — zdjęcia pobierane przez Instagram z ' . h($shop) . '/media/jpg/.' : 'Połączone, ale: ' . h($igAdres) . '.'), $cfg['ig'] !== '');
  ?>
  </ul>
  <?php if ($isAdmin): ?>
  <form method="post" class="actions" style="margin-top:16px"><?= console_csrf_field() ?>
    <button type="submit" name="sprawdz" value="1">Sprawdź połączenie ze stroną</button>
    <button type="submit" name="test_capi" value="1"<?= $cfg['test_code'] === '' ? ' disabled title="Najpierw kod zdarzeń testowych"' : '' ?>>Wyślij zdarzenie testowe (CAPI)</button>
  </form>
  <?php endif; ?>
  <?php if ($check && $check['ok']): ?>
  <p class="warnbox ok" style="margin-top:12px">Strona: <b><?= h($check['page']) ?></b>.
    <?php if ($check['ig_id'] !== ''): ?>Instagram: <b>@<?= h($check['ig_user']) ?></b> (ID <?= h($check['ig_id']) ?>).
      <?php if ($check['ig_id'] !== $cfg['ig'] && $isAdmin): ?>
      <form method="post" style="display:inline"><?= console_csrf_field() ?><button type="submit" name="zapisz_ig" value="<?= h($check['ig_id']) ?>">Zapisz to ID Instagrama</button></form>
      <?php endif; ?>
    <?php else: ?>Do strony nie jest podłączone konto firmowe Instagram.<?php endif; ?></p>
  <?php endif; ?>
</div>

<form method="post" class="panel">
  <?= console_csrf_field() ?>
  <h2>Identyfikatory i tokeny Meta</h2>
  <p class="hint">Piksel i kod domeny są publiczne. Dwa tokeny (Conversions API i strony) nigdy nie opuszczają serwera i nie są tu ponownie pokazywane — wklej nowy, żeby podmienić. Można je też wkleić hurtem w Superadmin → Klucze.</p>
  <?php console_reglages($view, 'meta', $isAdmin); ?>
  <?php if ($isAdmin): ?><p class="actions"><button class="btn btn--brand" type="submit" name="zapisz" value="1">Zapisz</button></p><?php endif; ?>
</form>

<div class="panel"><h2>Zakupy wysłane do Meta</h2>
  <p class="hint">Tylko zamówienia opłacone, złożone ze zgodą „marketingowe”. Błędy są ponawiane automatycznie (2, 4, 8, 16 min), potem czekają na przycisk.</p>
  <?php if ($isAdmin && array_filter($log, fn($r) => $r['status'] === 'blad')): ?>
  <form method="post" class="actions"><?= console_csrf_field() ?><button type="submit" name="ponow" value="1">Ponów nieudane</button></form>
  <?php endif; ?>
  <div class="tablewrap"><table class="rwd">
    <thead><tr><th>Zamówienie</th><th>Status</th><th class="num">Próby</th><th>Odpowiedź Meta</th><th>Kiedy</th></tr></thead>
    <tbody>
    <?php if (!$log): ?><tr><td colspan="5" class="muted">Jeszcze nic — pojawi się po pierwszym opłaconym zamówieniu ze zgodą.</td></tr><?php endif; ?>
    <?php foreach ($log as $r): $t = ['wyslane' => 'ok', 'blad' => 'bad', 'pominiete' => 'wait'][$r['status']] ?? 'wait'; ?>
      <tr><td data-l="Zamówienie"><a href="zamowienia.php?id=<?= (int) $r['order_id'] ?>"><?= h((string) $r['code']) ?></a></td>
        <td data-l="Status"><span class="tag <?= $t ?>"><?= h((string) $r['status']) ?></span></td>
        <td data-l="Próby" class="num"><?= (int) $r['attempts'] ?></td>
        <td data-l="Odpowiedź"><?= h((string) $r['response']) ?></td>
        <td data-l="Kiedy"><?= h(substr((string) ($r['sent_at'] ?: ($r['tried_at'] ?: $r['created_at'])), 0, 16)) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php console_foot();
