<?php
// ============================================================================
//  zgloszenia.php — réclamations, rétractations, et liens directs.
//
//  POURQUOI CES DEUX CHOSES SUR LE MÊME ÉCRAN : ce sont les deux bouts d'une
//  même journée. Le lien fait venir, la réclamation fait revenir — ou partir.
//  Les séparer sur deux pages obligerait à ouvrir les deux chaque matin.
//
//  LE CHIFFRE QUI COMMANDE L'ÉCRAN : les jours restants avant que le silence
//  vaille acceptation. Le droit polonais donne QUATORZE JOURS pour répondre à
//  une réclamation ; passé ce délai sans réponse, elle est réputée acceptée.
//  Ce n'est pas une bonne pratique, c'est le prix du produit. Les dossiers
//  sont donc triés par URGENCE, pas par date d'ouverture — un dossier neuf ne
//  doit jamais passer devant un dossier qui expire demain.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/claims.php';
require_once $API . '/links.php';
wsm_claims_ensure($pdo);
wsm_links_ensure($pdo);

$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$flash = ''; $kind = 'ok'; $linkPost = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może rozstrzygać zgłoszenia.'; $kind = 'err';
    } elseif (isset($_POST['zapisz_zgl'])) {
        [$ok, $m] = wsm_claim_update($pdo, (int) $_POST['id'], (string) ($_POST['statut'] ?? ''),
            (string) ($_POST['decision'] ?? ''),
            (int) round(((float) str_replace(',', '.', (string) ($_POST['zwrot'] ?? '0'))) * 100),
            (string) ($me['nom'] ?? ''));
        $flash = $m; $kind = $ok ? 'ok' : 'err';
    } elseif (isset($_POST['nowy_link'])) {
        [$code, $m] = wsm_link_create($pdo, $_POST, (string) ($me['nom'] ?? ''));
        $flash = $m; $kind = $code !== '' ? 'ok' : 'err';
        if ($code === '') $linkPost = $_POST;
    } elseif (isset($_POST['link_off'])) {
        [$ok, $m] = wsm_link_disable($pdo, (int) $_POST['link_off'], (string) ($me['nom'] ?? ''));
        $flash = $m; $kind = $ok ? 'ok' : 'err';
    }
}

$filtre = (string) ($_GET['stan'] ?? 'otwarte');
$claims = wsm_claims_list($pdo, $filtre);
$k = wsm_claims_kpis($pdo);
$links = wsm_links_list($pdo);
$produits = $pdo->query("SELECT id, nom FROM wsm_products
                          WHERE active = 1 AND shop_visible = 1 ORDER BY nom")->fetchAll() ?: [];
$baseSklep = rtrim(wsm_shop_base_url(), '/');

console_head('Zgłoszenia', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 20px; max-width: 82ch; line-height: 1.6; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 22px; }
  .kpi { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 14px 16px; }
  .kpi b { display: block; font-family: var(--font-mono); font-size: 22px; color: var(--text-strong); }
  .kpi span { font-size: 12.5px; color: var(--text-muted); }
  .kpi.alarme b { color: var(--danger); }
  .filtres { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
  .filtres a { font-size: 13px; padding: 7px 13px; border-radius: 999px; border: 1px solid var(--border-subtle);
               color: var(--text-body); text-decoration: none; min-height: 38px; display: inline-flex; align-items: center; }
  .filtres a.on { background: var(--brand); color: var(--paper-50); border-color: var(--brand); }
  .zgl { border: 1px solid var(--border-subtle); border-radius: var(--radius-lg);
         padding: 16px; margin-bottom: 14px; background: var(--surface-card); }
  .zgl-tete { display: flex; gap: 12px; align-items: baseline; flex-wrap: wrap; margin-bottom: 8px; }
  .zgl-tete b { font-family: var(--font-mono); font-size: 15px; color: var(--text-strong); }
  .zgl-tete .kto { color: var(--text-muted); font-size: 13px; }
  .jours { font-family: var(--font-mono); font-size: 12px; padding: 3px 10px; border-radius: 999px;
           border: 1px solid var(--border-subtle); margin-left: auto; white-space: nowrap; }
  .jours.pilne { color: var(--warn, #9a6a00); border-color: color-mix(in srgb, var(--warn, #9a6a00) 45%, transparent); }
  .jours.tarde { color: var(--danger); border-color: color-mix(in srgb, var(--danger) 45%, transparent); font-weight: 700; }
  .zgl-raison { white-space: pre-wrap; font-size: 14px; line-height: 1.6; color: var(--text-body);
                background: var(--bg-page); border-radius: var(--radius-md); padding: 12px 14px; margin-bottom: 12px; }
  .zgl-form { display: grid; grid-template-columns: 1fr; gap: 10px; align-items: end; }
  @media (min-width: 820px) { .zgl-form { grid-template-columns: 180px 130px 1fr auto; } }
  .zgl-form label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px;
                    font-weight: 600; color: var(--text-strong); }
  input[type=text], input[type=number], select, textarea { font-family: var(--font-sans); width: 100%; }
  textarea { resize: vertical; min-height: 44px; }
  .add { display: grid; grid-template-columns: 1fr; gap: 12px; align-items: end; }
  @media (min-width: 700px) { .add { grid-template-columns: 1fr 1fr; } }
  @media (min-width: 1040px) { .add { grid-template-columns: repeat(4, 1fr); } }
  .add label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; font-weight: 600; color: var(--text-strong); }
  .add button { align-self: end; }
  button.danger { background: transparent; border-color: color-mix(in srgb, var(--danger) 45%, transparent); color: var(--danger); }
  .url { font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); word-break: break-all; }
CSS, '');
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Zgłoszenia' => null]);
?>

  <p class="hint">
    <b>Milczenie kosztuje towar.</b> Na reklamację masz <b>14 dni</b> na odpowiedź —
    po tym terminie uznaje się ją za <b>przyjętą</b>. Dlatego lista jest ułożona
    według <b>pozostałych dni</b>, nie według daty wpłynięcia: świeże zgłoszenie
    nigdy nie wyprzedza tego, które wygasa jutro.
    Odstąpienie od umowy przysługuje przez 14 dni od odbioru; reklamacja z tytułu
    wady — przez 2 lata. <b>Zwrot nigdy nie przekroczy tego, co zapłacono.</b>
  </p>

  <div class="kpis">
    <div class="kpi"><b><?= (int) $k['otwarte'] ?></b><span>otwartych zgłoszeń</span></div>
    <div class="kpi<?= $k['pilne'] > 0 ? ' alarme' : '' ?>"><b><?= (int) $k['pilne'] ?></b><span>pilnych (≤ 3 dni)</span></div>
    <div class="kpi<?= $k['po_terminie'] > 0 ? ' alarme' : '' ?>"><b><?= (int) $k['po_terminie'] ?></b><span>po terminie</span></div>
    <div class="kpi"><b><?= h(wsm_claim_zl((int) $k['zwroty'])) ?></b><span>zwrócono łącznie</span></div>
  </div>

  <div class="panel">
    <h2>Zgłoszenia</h2>
    <div class="filtres">
      <?php foreach (['otwarte' => 'Otwarte', '' => 'Wszystkie'] + WSM_CLAIM_STATUSES as $v => $lib): ?>
      <a href="?stan=<?= h((string) $v) ?>"<?= (string) $v === $filtre ? ' class="on"' : '' ?>><?= h($lib) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (!$claims): ?>
      <p class="muted">Brak zgłoszeń w tym widoku.</p>
    <?php endif; ?>

    <?php foreach ($claims as $c):
      $ouvert = in_array((string) $c['statut'], ['nowa', 'w_toku'], true);
      $cls = !$ouvert ? '' : ((int) $c['reponse_reste'] < 0 ? ' tarde' : ((int) $c['reponse_reste'] <= 3 ? ' pilne' : '')); ?>
    <div class="zgl">
      <div class="zgl-tete">
        <b><?= h((string) $c['numer']) ?></b>
        <span class="kto"><?= h((string) $c['type_label']) ?> · <?= h((string) $c['order_code']) ?> · <?= h((string) $c['email']) ?></span>
        <?php if ($ouvert): ?>
        <span class="jours<?= $cls ?>">
          <?= (int) $c['reponse_reste'] >= 0
              ? (int) $c['reponse_reste'] . ' dni na odpowiedź'
              : 'PO TERMINIE o ' . abs((int) $c['reponse_reste']) . ' dni — milczenie = zgoda' ?>
        </span>
        <?php else: ?>
        <span class="jours"><?= h((string) $c['statut_label']) ?></span>
        <?php endif; ?>
      </div>
      <div class="zgl-raison"><?= h((string) $c['raison']) ?></div>
      <?php if (trim((string) $c['decision']) !== ''): ?>
      <p class="hint" style="margin:0 0 10px"><b>Decyzja:</b> <?= h((string) $c['decision']) ?></p>
      <?php endif; ?>
      <p class="hint" style="margin:0 0 12px">
        Zapłacono <b><?= h(wsm_claim_zl((int) $c['paid_gross'])) ?></b>,
        zwrócono <b><?= h(wsm_claim_zl((int) $c['refund_gross'])) ?></b>,
        pozostaje do zwrotu <b><?= h(wsm_claim_zl((int) $c['remboursable'])) ?></b>.
      </p>
      <?php if ($isAdmin): ?>
      <form class="zgl-form" method="post">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <label>Stan
          <select name="statut">
            <?php foreach (WSM_CLAIM_STATUSES as $v => $lib): ?>
            <option value="<?= h($v) ?>"<?= $v === (string) $c['statut'] ? ' selected' : '' ?>><?= h($lib) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label>Zwrot (zł)
          <input type="text" name="zwrot" inputmode="decimal" placeholder="0,00"></label>
        <label>Decyzja — to trafi do klienta
          <textarea name="decision" rows="2"><?= h((string) $c['decision']) ?></textarea></label>
        <button class="primary" type="submit" name="zapisz_zgl" value="1">Zapisz</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <h2>Linki bezpośrednie</h2>
    <p class="hint" style="margin-bottom:14px">
      Link, którego nie da się policzyć, wraca co roku „bo działał” — a to opinia, nie liczba.
      <b>Kliknięcia i zamówienia to dwie kolumny</b>, nigdy jeden wskaźnik: tysiąc kliknięć bez
      sprzedaży i dziesięć kliknięć z dwiema sprzedażami to dwie różne historie.
      Link może od razu <b>ustawić kod rabatowy</b> — inaczej klient szuka, gdzie go wpisać, i zamyka kartę.
      <b>Nie śledzimy ludzi</b>: żadnego ciasteczka identyfikującego, żadnego adresu IP — tylko nazwa
      linku zapisana na zamówieniu.
    </p>
    <?php if ($links): ?>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Link</th><th>Cel</th><th>Kod</th><th class="num">Kliknięć</th>
                 <th class="num">Zamówień</th><th class="num">Obrót</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($links as $l): ?>
      <tr<?= (int) $l['active'] === 1 ? '' : ' style="opacity:.55"' ?>>
        <td data-l="Link"><b><?= h((string) $l['nom']) ?></b><br>
          <span class="url"><?= h(wsm_link_url($baseSklep, (string) $l['code'])) ?></span></td>
        <td data-l="Cel"><?= h((string) $l['cible_label']) ?></td>
        <td data-l="Kod"><?= trim((string) $l['kod']) !== '' ? '<b class="url">' . h((string) $l['kod']) . '</b>' : '—' ?></td>
        <td data-l="Kliknięć" class="num"><?= (int) $l['klikniec'] ?></td>
        <td data-l="Zamówień" class="num"><?= (int) $l['zamowien'] ?></td>
        <td data-l="Obrót" class="num"><?= h(wsm_claim_zl((int) $l['obrot'])) ?></td>
        <td class="num">
          <?php if ($isAdmin && (int) $l['active'] === 1): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="_t" value="<?= h($csrf) ?>">
            <button class="danger" type="submit" name="link_off" value="<?= (int) $l['id'] ?>">Wycofaj</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php else: ?>
      <p class="muted">Brak linków.</p>
    <?php endif; ?>
  </div>

  <?php if ($isAdmin): ?>
  <div class="panel">
    <h2>Nowy link</h2>
    <form class="add" method="post">
      <input type="hidden" name="_t" value="<?= h($csrf) ?>">
      <input type="hidden" name="nowy_link" value="1">
      <label>Nazwa — do czego służy
        <input type="text" name="nom" placeholder="np. newsletter listopad"
               value="<?= h((string) ($linkPost['nom'] ?? '')) ?>"></label>
      <label>Cel
        <select name="cible">
          <?php foreach (WSM_LINK_CIBLES as $v => $lib): ?>
          <option value="<?= h($v) ?>"<?= ($linkPost['cible'] ?? '') === $v ? ' selected' : '' ?>><?= h($lib) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label>Produkt (dla karty i koszyka)
        <select name="produkt">
          <option value="">—</option>
          <?php foreach ($produits as $p): ?>
          <option value="<?= h((string) $p['id']) ?>"<?= ($linkPost['produkt'] ?? '') === (string) $p['id'] ? ' selected' : '' ?>><?= h((string) $p['nom']) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label>Kod rabatowy (opcjonalnie)
        <input type="text" name="kod" placeholder="np. WITAJ10"
               value="<?= h((string) ($linkPost['kod'] ?? '')) ?>"></label>
      <button class="primary" type="submit">Utwórz link</button>
    </form>
  </div>
  <?php endif; ?>
<?php console_foot();
