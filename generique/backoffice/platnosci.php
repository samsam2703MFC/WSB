<?php
// ============================================================================
//  Płatności — Stripe Connect, DANS la console.
//
//  Tout l'argent du sklep passe par Stripe. Cet écran réunit ce qu'il faut
//  pour qu'il arrive :
//    · l'état, en une phrase (wsm_stripe_diag) et en chiffres ;
//    · le compte Stripe de la boutique : créé d'un bouton, puis ouvert,
//      complété, et consulté ICI — paiements, remboursements, litiges,
//      virements — grâce aux composants intégrés de Stripe Connect. On ne
//      quitte pas la console pour aller sur un autre site ;
//    · les clés de la PLATEFORME, et elles seules, réservées au superadmin :
//      la clé secrète ouvre tous les comptes connectés, la boutique ne la
//      détient pas. Integracje ne l'écrit pas non plus (filtre de groupe).
//
//  Le navigateur ne reçoit qu'un secret de session de compte, à usage court,
//  demandé à chaque montage — jamais une clé.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/settings.php';
require_once $API . '/stripe.php';
require_once $API . '/platform.php';

$super = function_exists('wsm_is_superadmin') && wsm_is_superadmin($me);
$flash = ''; $flashKind = 'ok';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }

    // La session de compte des composants intégrés : demandée par la page
    // elle-même (fetch), à chaque montage et à chaque expiration.
    if (isset($_POST['sesja'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Brak uprawnień.']); exit; }
        [$cs, $err] = wsm_stripe_account_session();
        if ($cs === null) { http_response_code(409); echo json_encode(['error' => $err]); exit; }
        echo json_encode(['client_secret' => $cs]);
        exit;
    }

    if (!$isAdmin) {
        $flash = 'Tylko osoba z prawem zapisu na tym ekranie może to zrobić.'; $flashKind = 'err';
    } elseif (isset($_POST['zaloz'])) {
        $email = trim((string) ($_POST['email'] ?? ''));
        [$acct, $err] = wsm_stripe_account_create($pdo, $email, (string) ($me['nom'] ?? ''));
        if ($acct === null) { $flash = $err; $flashKind = 'err'; }
        else {
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Konto Stripe sklepu', $acct, 'Płatności');
            $flash = 'Konto Stripe sklepu założone (' . $acct . '). Uzupełnij dane poniżej — bez tego Stripe nie przyjmie płatności.';
        }
    } elseif (isset($_POST['test'])) {
        [$etat, $phrase] = wsm_stripe_diag();
        $flash = $phrase; $flashKind = $etat === 'ok' ? 'ok' : ($etat === 'uwaga' ? 'warn' : 'err');
        wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Test połączenia stripe', mb_substr($phrase, 0, 120), 'Sieć');
    } elseif (isset($_POST['klucze'])) {
        // LES CLÉS DE LA PLATEFORME : superadmin, et ce groupe seulement.
        if (!$super) {
            $flash = 'Klucze platformy zmienia tylko superadmin.'; $flashKind = 'err';
        } else {
            $refus = [];
            $changed = wsm_settings_save($pdo, $_POST, (string) ($me['nom'] ?? ''), $refus, ['stripe']);
            wsm_settings_apply($pdo);
            if ($refus) { $flash = 'Odrzucone: ' . implode(' · ', $refus); $flashKind = 'err'; }
            else {
                // L'audit ne retient QUE les clés modifiées, jamais les valeurs.
                if ($changed) wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Klucze Stripe', implode(', ', $changed), 'Płatności');
                $flash = $changed ? 'Zapisano: ' . implode(', ', $changed) . '.' : 'Bez zmian.';
            }
        }
    }
}

$cfg   = wsm_stripe_cfg();
$acct  = wsm_stripe_account();
$stan  = $acct !== '' && wsm_stripe_enabled() ? wsm_stripe_account_state() : ['ok' => false];
$terms = function_exists('wsm_platform_terms') ? wsm_platform_terms($pdo) : ['rate' => 0];
$view  = $super ? array_filter(wsm_settings_view($pdo), fn($f) => $f['group'] === 'stripe') : [];

console_head('Płatności', $me, <<<'CSS'
  .stripe-blok { margin-top: 8px; }
  .stripe-blok > h3 { margin: 22px 0 10px; }
  .stripe-blok .laduje { color: var(--text-muted); font-size: 14px; }
  .adres { font-family: var(--font-mono); font-size: 13px; overflow-wrap: anywhere; }
CSS);
console_flash($flash, $flashKind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Płatności' => null]);
?>

<div class="kpis">
  <div class="kpi"><b><?= wsm_stripe_enabled() ? 'TAK' : 'NIE' ?></b><span>Klucze platformy<?= $cfg['test'] ? ' (test)' : '' ?></span></div>
  <div class="kpi"><b><?= $acct !== '' ? 'TAK' : 'NIE' ?></b><span>Konto Stripe sklepu</span></div>
  <div class="kpi"><b><?= !empty($stan['charges_enabled']) ? 'TAK' : 'NIE' ?></b><span>Przyjmuje płatności</span></div>
  <div class="kpi"><b><?= !empty($stan['payouts_enabled']) ? 'TAK' : 'NIE' ?></b><span>Wypłaty na konto</span></div>
  <div class="kpi"><b><?= wsm_stripe_can_verify() ? 'TAK' : 'NIE' ?></b><span>Webhook podpisany</span></div>
  <div class="kpi"><b><?= h(rtrim(rtrim(number_format(((float) ($terms['rate'] ?? 0)) * 100, 2, ',', ''), '0'), ',')) ?> %</b><span>Prowizja platformy</span></div>
</div>

<form method="post" class="panel">
  <?= console_csrf_field() ?>
  <h2>Stan płatności</h2>
  <p class="small">Klient płaci w kasie sklepu, w formularzu Stripe osadzonym na stronie „Zapłać” — BLIK, Przelewy24,
    karta, Apple Pay, Google Pay (to, co włączysz w ustawieniach Stripe). Pieniądze trafiają na konto Stripe sklepu;
    przy każdej płatności Stripe potrąca prowizję platformy (<?= h(rtrim(rtrim(number_format(((float) ($terms['rate'] ?? 0)) * 100, 2, ',', ''), '0'), ',')) ?> % + VAT),
    a rozliczenie miesięczne platformy odejmuje ją od kwoty do zapłaty.</p>
  <p class="actions" style="margin:0 0 12px">
    <button type="submit" name="test" value="1">Sprawdź połączenie</button>
  </p>
  <dl class="kv">
    <dt>Adres webhooka</dt><dd class="adres"><?= h(wsm_stripe_webhook_url()) ?></dd>
    <dt>Zdarzenia</dt><dd>checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed — dla <b>kont połączonych</b> (Connect)</dd>
    <dt>Konto sklepu</dt><dd class="adres"><?= $acct !== '' ? h($acct) : '—' ?></dd>
  </dl>
</form>

<div class="panel">
  <h2>Konto Stripe sklepu</h2>
  <?php if (!wsm_stripe_enabled()): ?>
    <p class="warnbox">Platforma nie ma jeszcze kluczy Stripe. Do tego czasu sklep nie przyjmuje płatności online —
      zamówienia powstają i czekają.<?= $super ? ' Wpisz klucze w sekcji poniżej.' : ' Klucze wpisuje superadmin.' ?></p>
  <?php elseif ($acct === ''): ?>
    <p class="small">Sklep nie ma jeszcze konta Stripe. Zakładamy je jednym przyciskiem; potem uzupełniasz dane firmy
      i rachunek do wypłat <b>tutaj, w konsoli</b> — formularz Stripe pojawi się poniżej.</p>
    <?php if ($isAdmin): ?>
    <form method="post" class="actions">
      <?= console_csrf_field() ?>
      <label class="field" style="margin:0;min-width:280px"><span>E-mail firmy do Stripe</span>
        <input type="email" name="email" value="<?= h((string) ($me['email'] ?? '')) ?>" required></label>
      <button class="primary" type="submit" name="zaloz" value="1" style="align-self:flex-end">Załóż konto Stripe sklepu</button>
    </form>
    <?php endif; ?>
  <?php else: ?>
    <?php if (empty($stan['ok'])): ?>
      <p class="warnbox">Stripe nie odpowiada o konto <?= h($acct) ?><?= isset($stan['error']) ? ': ' . h((string) $stan['error']) : '' ?>.</p>
    <?php endif; ?>
    <?php // LES COMPOSANTS STRIPE, montés par le script plus bas. Chaque
          // conteneur porte le nom du composant ; Stripe y insère son interface. ?>
    <div class="stripe-blok" id="stripe-connect" data-pk="<?= h($cfg['publishable_key']) ?>" data-t="<?= h(console_csrf()) ?>">
      <div data-skladnik="notification-banner"></div>
      <?php if (empty($stan['details_submitted']) || !empty($stan['due'])): ?>
      <h3>Dane firmy i rachunek do wypłat</h3>
      <div data-skladnik="account-onboarding"><p class="laduje">Ładowanie formularza Stripe…</p></div>
      <?php endif; ?>
      <h3>Płatności</h3>
      <div data-skladnik="payments"><p class="laduje">Ładowanie płatności…</p></div>
      <h3>Wypłaty</h3>
      <div data-skladnik="payouts"><p class="laduje">Ładowanie wypłat…</p></div>
    </div>
  <?php endif; ?>
</div>

<?php if ($super): ?>
<form method="post" class="panel">
  <?= console_csrf_field() ?>
  <h2>Klucze platformy <span class="tag">superadmin</span></h2>
  <p class="muted small">Klucze konta Stripe <b>platformy</b> (nie sklepu). Tryb testowy: klucze sk_test_/pk_test_ —
    pieniądze nie są pobierane. Zapisany klucz tajny i sekret webhooka nie są nigdy pokazywane ponownie; puste pole = bez zmian.</p>
  <div class="grid2">
  <?php foreach ($view as $key => $f): ?>
    <label class="field">
      <span><?= h($f['label']) ?><?php if ($f['locked']): ?> <em class="tag">serwer</em><?php elseif ($f['source'] === 'baza'): ?> <em class="tag ok">zapisane</em><?php endif; ?></span>
      <input name="<?= h($f['form']) ?>" type="<?= $f['type'] === 'secret' ? 'password' : 'text' ?>"
             value="<?= $f['type'] === 'secret' ? '' : h($f['show'] === WSM_SETTING_PLACEHOLDER ? '' : $f['show']) ?>"
             placeholder="<?= h($f['type'] === 'secret' ? ($f['set'] ? '•••••••• (zapisane)' : '') : '') ?>"
             autocomplete="off"<?= $f['locked'] ? ' disabled' : '' ?>>
      <small class="hint"><?= h($f['help']) ?></small>
    </label>
  <?php endforeach; ?>
  </div>
  <p class="actions"><button class="primary" type="submit" name="klucze" value="1">Zapisz klucze</button></p>
</form>
<?php endif; ?>

<?php if ($acct !== '' && wsm_stripe_enabled()): ?>
<script src="<?= h(WSM_STRIPE_CONNECT_JS) ?>" async></script>
<script>
// Les composants intégrés de Stripe Connect. Le secret de session de compte
// est demandé à cette page (POST « sesja »), avec le jeton du formulaire ; il
// est redemandé automatiquement quand il expire.
(function () {
  var box = document.getElementById('stripe-connect');
  if (!box) return;
  var fetchClientSecret = function () {
    var fd = new FormData();
    fd.append('_t', box.getAttribute('data-t') || '');
    fd.append('sesja', '1');
    return fetch('platnosci.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (d) { if (!r.ok || !d.client_secret) throw new Error(d.error || ('HTTP ' + r.status)); return d.client_secret; }); });
  };
  window.StripeConnect = window.StripeConnect || {};
  window.StripeConnect.onLoad = function () {
    var css = getComputedStyle(document.documentElement);
    var inst = window.StripeConnect.init({
      publishableKey: box.getAttribute('data-pk'),
      fetchClientSecret: fetchClientSecret,
      locale: 'pl-PL',
      appearance: { overlays: 'dialog', variables: {
        colorPrimary: (css.getPropertyValue('--brand') || '#41281A').trim() || '#41281A',
        borderRadius: '12px', fontFamily: 'Mulish, system-ui, sans-serif'
      } }
    });
    box.querySelectorAll('[data-skladnik]').forEach(function (el) {
      var c = inst.create(el.getAttribute('data-skladnik'));
      if (!c) return;
      if (c.setOnExit) c.setOnExit(function () { location.reload(); });
      if (c.setOnLoaderStart) c.setOnLoaderStart(function () { var l = el.querySelector('.laduje'); if (l) l.remove(); });
      el.appendChild(c);
    });
  };
})();
</script>
<?php endif; ?>
<?php console_foot();
