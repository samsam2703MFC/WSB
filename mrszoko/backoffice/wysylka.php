<?php
// ============================================================================
//  wysylka.php — le panneau d'expédition.
//
//  CE QUE CET ÉCRAN EXISTE POUR EMPÊCHER : une commande payée dont le colis
//  n'est jamais créé. Elle ne fait aucun bruit — elle est payée, elle attend,
//  et personne ne la cherche. Le client, lui, attend aussi, puis écrit, puis
//  demande son argent. Un numéro de téléphone manquant suffit.
//
//  Le compteur « zablokowanych » est donc le chiffre de la page, en rouge, et
//  chaque blocage est nommé À CÔTÉ de la commande — pas dans un journal.
//
//  Sans jeton InPost, l'écran reste utile : il dit que la configuration
//  manque, et la file sert alors de liste de colis à faire à la main.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/inpost.php';
require_once $API . '/shipping.php';

$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$flash = ''; $kind = 'ok'; $detail = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może nadawać przesyłki.'; $kind = 'err';
    } elseif (isset($_POST['sprawdz'])) {
        // CE BOUTON MARCHE CANAL FERME, et c'est tout son interet : il sert
        // justement a savoir POURQUOI il est ferme. Le ranger derriere le
        // controle « InPost est-il configure ? » l'aurait rendu inaccessible
        // exactement quand on en a besoin.
        [$kInp, $mInp] = wsm_inpost_diag();
        $flash = $mInp;
        $kind  = $kInp === 'ok' ? 'ok' : ($kInp === 'uwaga' ? 'warn' : 'err');
        wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Test połączenia InPost',
                  mb_substr($mInp, 0, 120), 'Sieć');
    } elseif (isset($_POST['odswiez']) && wsm_inpost_enabled()) {
        // LA VIE DU COLIS APRES SON DEPART. Sans ca, une commande restait
        // « Wysłane » pour toujours et la boutique etait le dernier endroit ou
        // l'on apprenait qu'elle avait ete livree — le client, lui, le voyait
        // sur le site du transporteur.
        [$vus, $av] = wsm_inpost_sync($pdo);
        $flash = 'Sprawdzono ' . $vus . ' przesyłek· zamówień oznaczonych jako dostarczone: ' . $av . '.';
        $kind  = 'ok';
    } elseif (!wsm_inpost_enabled()) {
        // LE TRANSPORTEUR FERMÉ SE DIT AVANT D'ESSAYER, PAS APRÈS.
        // « Prêt » qualifiait la COMMANDE — téléphone, adresse, poids — et
        // ignorait si le canal était seulement ouvert. Le bouton annonçait
        // donc « Nadaj wszystkie gotowe (146) », lançait cent quarante-six
        // appels voués à l'échec, et rendait « Utworzono 0 przesyłek.
        // Zablokowanych: 146 ». Un compteur qui promet ce qu'il ne peut pas
        // tenir est la même faute que le bouton qui annonçait 300 sous une
        // liste de 200.
        $flash = 'Kanał InPost jest zamknięty — bez tokenu i identyfikatora organizacji '
               . 'żadna przesyłka nie powstanie. Uzupełnij je w Ustawieniach.';
        $kind = 'err';
    } elseif (isset($_POST['nadaj'])) {
        $ids = array_map('intval', (array) ($_POST['zam'] ?? []));
        if (!$ids) {
            $flash = 'Nie zaznaczono żadnego zamówienia.'; $kind = 'err';
        } else {
            $r = wsm_ship_batch($pdo, $ids, (string) ($me['nom'] ?? ''));
            $flash = $r['message'];
            $kind = $r['utworzone'] > 0 ? 'ok' : 'err';
            $detail = $r['bledy'];
        }
    } elseif (isset($_POST['nadaj_gotowe'])) {
        // COCHER CENT VINGT-QUATRE CASES N'EST PAS UN FLUX DE TRAVAIL. Le
        // geste réel du matin est « tout ce qui peut partir, qu'il parte ».
        // La liste des identifiants est RECALCULÉE ici, jamais reprise du
        // formulaire : un champ caché serait modifiable, et la règle « on
        // n'expédie que le prêt » doit tenir côté serveur.
        $prets = [];
        foreach (wsm_ship_queue($pdo) as $x) if ($x['pret']) $prets[] = (int) $x['order']['id'];
        if (!$prets) {
            $flash = 'Nic nie jest gotowe do nadania.'; $kind = 'err';
        } else {
            $r = wsm_ship_batch($pdo, $prets, (string) ($me['nom'] ?? ''));
            $flash = $r['message'];
            $kind = $r['utworzone'] > 0 ? 'ok' : 'err';
            $detail = $r['bledy'];
        }
    }
}

// La file est calculée UNE fois et passée aux compteurs : ils décrivent la
// liste affichée, pas une autre. Sinon « 300 do wysłania » sous 200 lignes.
$file = wsm_ship_queue($pdo);
$k = wsm_ship_kpis($pdo, $file);
$causes = wsm_ship_blockers_summary($pdo, $file);
$partis = wsm_ship_sent($pdo, 25);
$configure = wsm_inpost_enabled();

console_head('Wysyłka', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 20px; max-width: 82ch; line-height: 1.6; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 22px; }
  .kpi { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 14px 16px; }
  .kpi b { display: block; font-family: var(--font-mono); font-size: 22px; color: var(--text-strong); }
  .kpi span { font-size: 12.5px; color: var(--text-muted); }
  .kpi.alarme b { color: var(--danger); }
  .kpi.pret b { color: var(--ok, #1a7f4b); }
  .causes { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
  .cause { font-size: 12.5px; padding: 7px 13px; border-radius: 999px; min-height: 38px;
           display: inline-flex; align-items: center; gap: 7px;
           border: 1px solid color-mix(in srgb, var(--danger) 35%, transparent); color: var(--danger); }
  .cause b { font-family: var(--font-mono); }
  .lignes { display: grid; gap: 10px; }
  .l { display: grid; grid-template-columns: auto 1fr auto; gap: 12px; align-items: start;
       padding: 12px 14px; border: 1px solid var(--border-subtle); border-radius: var(--radius-md);
       background: var(--surface-card); }
  .l.bloque { border-color: color-mix(in srgb, var(--danger) 35%, transparent); }
  .l input[type=checkbox] { width: 22px; height: 22px; margin-top: 2px; accent-color: var(--brand); }
  .l .qui b { font-family: var(--font-mono); font-size: 14px; color: var(--text-strong); }
  .l .qui small { display: block; color: var(--text-muted); font-size: 12.5px; margin-top: 2px; }
  .l .stop { margin-top: 6px; font-size: 12.5px; color: var(--danger); line-height: 1.5; }
  .l .droite { text-align: right; font-family: var(--font-mono); font-size: 12.5px; color: var(--text-muted); white-space: nowrap; }
  .barre { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin: 16px 0 4px; }
  .det { margin: 10px 0 0; padding-left: 18px; font-size: 13px; color: var(--danger); line-height: 1.7; }
CSS, '');
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Wysyłka' => null]);
if ($detail) {
    echo '<ul class="det">';
    foreach (array_slice($detail, 0, 12) as $d) echo '<li>' . h($d) . '</li>';
    echo '</ul>';
}
?>

  <p class="hint">
    Zamówienie <b>zapłacone</b>, którego paczka nigdy nie powstała, <b>nie robi żadnego hałasu</b> —
    jest opłacone, czeka, i nikt go nie szuka. Klient też czeka, potem pisze, potem prosi o zwrot.
    Wystarczy brakujący numer telefonu. Dlatego <b>to, co blokuje, jest nazwane obok zamówienia</b>,
    a nie w dzienniku. Lista pokazuje wyłącznie <b>zapłacone</b>: paczka do niezapłaconego zamówienia
    to towar oddany za darmo.
  </p>

  <?php if (!$configure): ?>
  <div class="panel" style="border-color: color-mix(in srgb, var(--warn, #9a6a00) 40%, transparent)">
    <h2>InPost nie jest skonfigurowany</h2>
    <p class="hint">
      Brakuje tokenu i identyfikatora organizacji — uzupełnij je w <a href="ustawienia.php">Integracjach</a>.
      Do tego czasu <b>ten ekran nadal działa jako lista rzeczy do zrobienia</b>: paczki nadajesz ręcznie,
      a widzisz tu dokładnie które i co im brakuje.
    </p>
    <?php // LE BOUTON MARCHE MEME CANAL FERME : c'est justement ce qu'on veut
          // savoir. Coller un jeton et n'avoir aucun retour, c'est l'apprendre
          // le jour ou un client a paye et ou le colis n'existe pas. ?>
    <form method="post" class="barre">
      <?= console_csrf_field() ?>
      <button type="submit" name="sprawdz" value="1">Sprawdź połączenie z ShipX</button>
      <span class="hint" style="margin:0">Zapyta ShipX o Twoją organizację i powie, co dokładnie jest nie tak.</span>
    </form>
  </div>
  <?php else: ?>
  <form method="post" class="barre">
    <?= console_csrf_field() ?>
    <button type="submit" name="sprawdz" value="1">Sprawdź połączenie</button>
    <?php // Ce bouton finit le voyage : sans lui, une commande reste
          // « Wysłane » pour toujours et la boutique est le dernier endroit ou
          // l'on apprend qu'elle a ete livree. ?>
    <button type="submit" name="odswiez" value="1">Odśwież statusy przesyłek</button>
    <span class="hint" style="margin:0">Statusy pobierane z InPost: doręczona paczka zamyka zamówienie.</span>
  </form>
  <?php endif; ?>

  <div class="kpis">
    <div class="kpi"><b><?= (int) $k['do_wyslania'] ?></b><span>do wysłania</span></div>
    <div class="kpi pret"><b><?= (int) $k['gotowe'] ?></b><span>gotowych — nic nie brakuje</span></div>
    <div class="kpi<?= $k['bloquees'] > 0 ? ' alarme' : '' ?>"><b><?= (int) $k['bloquees'] ?></b><span>zablokowanych</span></div>
    <div class="kpi"><b><?= (int) $k['wyslane'] ?></b><span>nadanych łącznie</span></div>
  </div>

  <?php if ($causes): ?>
  <div class="causes">
    <?php foreach ($causes as $c): ?>
    <span class="cause"><b><?= (int) $c['n'] ?>×</b> <?= h($c['label']) ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="panel">
    <h2>Do wysłania</h2>
    <?php if (!$file): ?>
      <p class="muted">Nic nie czeka. Wszystkie zapłacone zamówienia mają numer śledzenia.</p>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="_t" value="<?= h($csrf) ?>">
      <div class="lignes">
        <?php foreach ($file as $x): $o = $x['order']; ?>
        <label class="l<?= $x['pret'] ? '' : ' bloque' ?>">
          <input type="checkbox" name="zam[]" value="<?= (int) $o['id'] ?>"<?= $x['pret'] ? '' : ' disabled' ?>
                 aria-label="Zaznacz zamówienie <?= h((string) $o['code']) ?>">
          <span class="qui">
            <b><?= h((string) $o['code']) ?></b>
            <small><?= h(trim((string) $o['first_name'] . ' ' . (string) $o['last_name']) ?: (string) $o['company']) ?>
              · <?= h((string) $o['email']) ?>
              <?php // Le transporteur est nommé : c'est l'écran depuis lequel on
                    // nadaje, et savoir chez QUI part le colis est la première
                    // chose qu'on regarde quand un envoi coince. ?>
              · <?= h(wsm_ship_opis($pdo, (string) $o['delivery_method'], (string) $o['inpost_point'])) ?></small>
            <?php if (!$x['pret']): ?>
            <span class="stop">
              <?php foreach ($x['blockers'] as $b): ?>
              ✕ <?= h(wsm_ship_blocker_label($b)) ?><br>
              <?php endforeach; ?>
            </span>
            <?php endif; ?>
          </span>
          <span class="droite">
            <?= h(pln((int) $o['total_gross'])) ?><br>
            <?= number_format((int) $o['weight_g'] / 1000, 2, ',', ' ') ?> kg
          </span>
        </label>
        <?php endforeach; ?>
      </div>
      <?php if ($isAdmin && !$configure): ?>
      <?php // Canal fermé : pas de bouton qui promette. La raison est ICI, à
            // l'endroit où l'on allait cliquer, et pas seulement dans le
            // bandeau du haut — c'est la même règle que l'écran KSeF. ?>
      <div class="barre">
        <span class="hint" style="margin:0">
          <b>Nic stąd nie wyjdzie</b>, dopóki kanał InPost jest zamknięty — dlatego nie ma
          tu przycisku, który by to obiecywał. Lista poniżej pozostaje listą pracy: te paczki
          nadaje się na razie ręcznie.
        </span>
      </div>
      <?php elseif ($isAdmin): ?>
      <div class="barre">
        <button type="submit" name="nadaj" value="1">Nadaj zaznaczone</button>
        <?php // Le compte est ÉCRIT SUR LE BOUTON : « nadaj wszystkie » sans
              // chiffre, un matin de rush, c'est cent vingt colis partis sans
              // qu'on ait su combien. La confirmation le répète. ?>
        <button class="primary" type="submit" name="nadaj_gotowe" value="1"
                onclick="return confirm('Nadać wszystkie <?= (int) $k['gotowe'] ?> gotowych przesyłek? Tego nie da się cofnąć.');"
                <?= (int) $k['gotowe'] === 0 ? ' disabled' : '' ?>>
          Nadaj wszystkie gotowe (<?= (int) $k['gotowe'] ?>)</button>
        <span class="hint" style="margin:0">Zablokowanych nie da się zaznaczyć — najpierw uzupełnij dane w zamówieniu.</span>
      </div>
      <?php endif; ?>
    </form>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Ostatnio nadane</h2>
    <?php if (!$partis): ?><p class="muted">Nic jeszcze nie zostało nadane.</p><?php else: ?>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Zamówienie</th><th>Odbiorca</th><th>Numer śledzenia</th><th>Stan</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($partis as $s): ?>
      <tr>
        <td data-l="Zamówienie"><b class="code"><?= h((string) $s['code']) ?></b></td>
        <td data-l="Odbiorca"><?= h(trim((string) $s['first_name'] . ' ' . (string) $s['last_name']) ?: (string) $s['company']) ?></td>
        <td data-l="Numer śledzenia"><span class="code"><?= h((string) $s['tracking_number']) ?></span></td>
        <td data-l="Stan"><?= h(WSM_SHIP_STATUSES[(string) $s['status']] ?? (string) $s['status']) ?></td>
        <td class="num">
          <?php if (trim((string) $s['label_url']) !== ''): ?>
          <a href="<?= h((string) $s['label_url']) ?>" target="_blank" rel="noopener">Etykieta ↗</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
<?php console_foot();
