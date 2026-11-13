<?php
// ============================================================================
//  rabaty.php — écran « Rabaty ilościowe » de la console marque.
//
//  La remise se calcule sur le POIDS total du panier, pas sur le montant :
//  c'est le kilogramme qui baisse avec le volume, et c'est ce que la boutique
//  promet depuis le début.
//
//  Un seul palier s'applique — le plus élevé atteint. Les paliers ne se
//  cumulent jamais : deux remises de 12 % et 20 % ne font pas 32 %.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/promo.php';
wsm_promo_ensure($pdo);
function kg($g): string { return number_format(((int) $g) / 1000, ((int) $g) % 1000 ? 2 : 0, ',', ' ') . ' kg'; }
$bonPost = [];

$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$flash = ''; $kind = 'ok'; $errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać rabaty.'; $kind = 'err';
    } elseif (isset($_POST['delete'])) {
        $pdo->prepare("DELETE FROM wsm_discount_tiers WHERE id = ?")->execute([(int) $_POST['delete']]);
        wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Usunięcie', 'wsm_discount_tiers #' . (int) $_POST['delete'], 'Sieć');
        $flash = 'Usunięto próg.';
    } elseif (isset($_POST['bon_off'])) {
        [$okB, $msgB] = wsm_promo_disable($pdo, (int) $_POST['bon_off'], (string) ($me['nom'] ?? ''));
        $flash = $msgB; $kind = $okB ? 'ok' : 'err';
    } elseif (isset($_POST['bon_save'])) {
        [$okB, $msgB] = wsm_promo_save($pdo, $_POST, (string) ($me['nom'] ?? ''));
        $flash = $msgB; $kind = $okB ? 'ok' : 'err';
        if (!$okB) $bonPost = $_POST;      // on réaffiche ce qui a été tapé
    } else {
        // Un poids en kilogrammes côté écran, en grammes en base : l'unité de
        // saisie doit être celle du métier, pas celle du stockage.
        $kgIn = str_replace(',', '.', (string) ($_POST['weight_kg'] ?? ''));
        $pct  = str_replace(',', '.', (string) ($_POST['percent'] ?? ''));
        $g    = (int) round(((float) $kgIn) * 1000);
        $p    = round((float) $pct, 2);

        if ($g <= 0)               $errors['weight_kg'] = 'waga musi być dodatnia';
        if ($p <= 0 || $p >= 100)  $errors['percent']   = 'rabat od 0 do 100 %';

        $id = (int) ($_POST['id'] ?? 0);
        if (!$errors) {
            // Deux paliers au même poids se contrediraient : l'un serait
            // simplement ignoré, sans qu'on sache lequel.
            $st = $pdo->prepare("SELECT id FROM wsm_discount_tiers WHERE min_weight_g = ? AND id <> ?");
            $st->execute([$g, $id]);
            if ($st->fetchColumn()) $errors['weight_kg'] = 'próg o tej wadze już istnieje';
        }
        if ($errors) {
            $flash = 'Popraw zaznaczone pola.'; $kind = 'err';
        } elseif ($id > 0) {
            $pdo->prepare("UPDATE wsm_discount_tiers SET min_weight_g = ?, percent = ?, label = ?, active = ? WHERE id = ?")
                ->execute([$g, $p, mb_substr((string) ($_POST['label'] ?? ''), 0, 80),
                           empty($_POST['active']) ? 0 : 1, $id]);
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Zmiana', 'wsm_discount_tiers #' . $id, 'Sieć');
            $flash = 'Zapisano próg ' . kg($g) . ' → ' . $p . ' %.';
        } else {
            $pdo->prepare("INSERT INTO wsm_discount_tiers (min_weight_g, percent, label, active) VALUES (?,?,?,?)")
                ->execute([$g, $p, mb_substr((string) ($_POST['label'] ?? ''), 0, 80), empty($_POST['active']) ? 0 : 1]);
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Utworzenie', 'wsm_discount_tiers ' . kg($g), 'Sieć');
            $flash = 'Dodano próg ' . kg($g) . ' → ' . $p . ' %.';
        }
    }
}

$tiers = $pdo->query("SELECT * FROM wsm_discount_tiers ORDER BY min_weight_g")->fetchAll();
// Un exemple vaut mieux qu'une explication : on montre ce que donnerait un
// panier réel à chaque palier, sur le produit le plus vendu.
$ref = $pdo->query("SELECT nom, prix, weight_g FROM wsm_products
                     WHERE shop_visible = 1 AND weight_g > 0 ORDER BY sort_order LIMIT 1")->fetch() ?: null;

console_head('Rabaty', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 20px; max-width: 80ch; line-height: 1.6; }
  .panel table { border: 0; }
  input[type=text], input[type=number] { font-family: var(--font-mono); width: 100%; }
  input.wide { font-family: var(--font-sans); }
  label.chk { display: inline-flex; gap: 8px; align-items: center; font-size: 13px; cursor: pointer; }
  label.chk input { accent-color: var(--brand); }
  button.danger { background: transparent; border-color: color-mix(in srgb, var(--danger) 45%, transparent); color: var(--danger); }
  small.err { color: var(--danger); font-weight: 600; display: block; margin-top: 4px; }
  .add { display: grid; grid-template-columns: 1fr; gap: 12px; align-items: end; }
  @media (min-width: 760px) { .add { grid-template-columns: 130px 110px 1fr auto auto; } }
  .add label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; font-weight: 600; color: var(--text-strong); }
  .ex { font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); }
  select { font-family: var(--font-sans); width: 100%; }
  /* Le formulaire de code a NEUF champs : une seule colonne les empile sur
     deux écrans de haut, et le bouton disparaît sous le pli. */
  .add--bon { grid-template-columns: 1fr; }
  @media (min-width: 620px) { .add--bon { grid-template-columns: 1fr 1fr; } }
  @media (min-width: 980px) { .add--bon { grid-template-columns: repeat(4, 1fr); }
                              .add--bon .wideL { grid-column: span 2; } }
  .add--bon .chk, .add--bon button { align-self: end; }
  .tier { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 14px; align-items: end;
          padding: 14px 0; border-bottom: 1px solid var(--border-subtle); }
  .tier:last-child { border-bottom: 0; }
  .tier form { display: contents; }
  .tier label.f { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px;
                  font-weight: 600; color: var(--text-strong); margin: 0; }
  .tier label.f.wide { grid-column: 1 / -1; }
  .tier label.chk { grid-column: 1 / -1; }
  @media (min-width: 760px) {
    .tier { grid-template-columns: 130px 110px 1fr auto auto auto; }
    .tier label.f.wide, .tier label.chk { grid-column: auto; }
  }
CSS, '');
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Rabaty' => null]);
?>

  <p class="hint">
    Rabat liczy się od <b>wagi całego koszyka</b>, nie od kwoty — to kilogram tanieje wraz z ilością.
    Obowiązuje <b>jeden próg</b>: najwyższy osiągnięty. Progi nigdy się nie sumują (12 % i 20 % to nie 32 %).
    Rabat obniża ceny produktów; dostawa i próg darmowej wysyłki liczone są już od kwoty po rabacie.
    W koszyku klient widzi, ile brakuje do następnego progu.
  </p>

  <div class="panel">
    <h2>Progi</h2>
    <?php if (!$tiers): ?><p class="muted">Brak progów — rabat nie jest naliczany.</p><?php endif; ?>
    <?php foreach ($tiers as $t): ?>
    <div class="tier">
      <form method="post">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
        <label class="f"><span>Od wagi (kg)</span>
          <input type="text" name="weight_kg" inputmode="decimal" value="<?= h(number_format((int) $t['min_weight_g'] / 1000, 2, ',', '')) ?>"<?= $isAdmin ? '' : ' disabled' ?>></label>
        <label class="f"><span>Rabat (%)</span>
          <input type="text" name="percent" inputmode="decimal" value="<?= h(number_format((float) $t['percent'], 2, ',', '')) ?>"<?= $isAdmin ? '' : ' disabled' ?>></label>
        <label class="f wide"><span>Opis</span>
          <input class="wide" type="text" name="label" value="<?= h((string) $t['label']) ?>"<?= $isAdmin ? '' : ' disabled' ?>></label>
        <label class="chk"><input type="checkbox" name="active" value="1"<?= (int) $t['active'] === 1 ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>> Aktywny</label>
        <?php if ($isAdmin): ?><button class="primary" type="submit">Zapisz</button><?php endif; ?>
      </form>
      <?php if ($isAdmin): ?>
      <form method="post">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <button class="danger" type="submit" name="delete" value="<?= (int) $t['id'] ?>">Usuń</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($isAdmin): ?>
  <div class="panel">
    <h2>Dodaj próg</h2>
    <form class="add" method="post">
      <input type="hidden" name="_t" value="<?= h($csrf) ?>">
      <label>Od wagi (kg)
        <input type="text" name="weight_kg" placeholder="10" value="<?= h((string) ($_POST['weight_kg'] ?? '')) ?>">
        <?php if (isset($errors['weight_kg'])) echo '<small class="err">' . h($errors['weight_kg']) . '</small>'; ?>
      </label>
      <label>Rabat (%)
        <input type="text" name="percent" placeholder="12" value="<?= h((string) ($_POST['percent'] ?? '')) ?>">
        <?php if (isset($errors['percent'])) echo '<small class="err">' . h($errors['percent']) . '</small>'; ?>
      </label>
      <label>Opis <input class="wide" type="text" name="label" placeholder="od 10 kg"></label>
      <label class="chk"><input type="checkbox" name="active" value="1" checked> Aktywny</label>
      <button class="primary" type="submit">Dodaj</button>
    </form>
  </div>
  <?php endif; ?>

  <?php // ---- Kody rabatowe ---------------------------------------------------
        // Ces codes existaient en base depuis le premier jour, listés dans la
        // console et LUS NULLE PART : aucune caisse n'en acceptait un seul.
        $bons = wsm_promo_list($pdo); ?>
  <div class="panel">
    <h2>Kody rabatowe</h2>
    <p class="hint" style="margin-bottom:14px">
      Kod <b>procentowy nie sumuje się</b> z rabatem ilościowym ani z ceną firmową — działa
      <b>lepszy z nich</b>. Kod <b>kwotowy</b> odejmuje się po rabacie, tylko od towaru
      (dostawy nie opłaca żaden kod poza „darmowa wysyłka”), i <b>nigdy nie oddaje pieniędzy</b>:
      50 zł na koszyku za 30 zł zdejmuje 30 zł. Wycofany kod zostaje na liście —
      zamówienia, które go użyły, muszą pozostać wytłumaczalne.
    </p>
    <?php if (!$bons): ?><p class="muted">Brak kodów.</p><?php endif; ?>
    <?php if ($bons): ?>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Kod</th><th>Rabat</th><th class="num">Od kwoty</th><th>Ważny do</th>
                 <th class="num">Użycia</th><th class="num">Koszt</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($bons as $b): $s = $b['stats']; $czynny = (int) $b['active'] === 1; ?>
      <tr<?= $czynny ? '' : ' style="opacity:.55"' ?>>
        <td data-l="Kod"><b class="mono"><?= h((string) $b['code']) ?></b>
            <?= $czynny ? '' : ' <small>(wycofany)</small>' ?>
            <?php if (trim((string) $b['note']) !== ''): ?><br><small class="muted"><?= h((string) $b['note']) ?></small><?php endif; ?></td>
        <td data-l="Rabat"><?= h((string) $b['libelle']) ?></td>
        <td data-l="Od kwoty" class="num"><?= (int) $b['min_gross'] > 0 ? h(wsm_promo_zl((int) $b['min_gross'])) : '—' ?></td>
        <td data-l="Ważny do"><?= trim((string) $b['ends_at']) !== '' ? h(substr((string) $b['ends_at'], 0, 10)) : 'bezterminowo' ?></td>
        <td data-l="Użycia" class="num"><?= (int) $s['uses'] ?><?= (int) $b['max_uses'] > 0 ? ' / ' . (int) $b['max_uses'] : '' ?></td>
        <td data-l="Koszt" class="num"><?= (int) $s['amount'] > 0 ? h(wsm_promo_zl((int) $s['amount'])) : '—' ?></td>
        <td class="num">
          <?php if ($isAdmin && $czynny): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="_t" value="<?= h($csrf) ?>">
            <button class="danger" type="submit" name="bon_off" value="<?= (int) $b['id'] ?>">Wycofaj</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($isAdmin): ?>
  <div class="panel">
    <h2>Nowy kod</h2>
    <form class="add add--bon" method="post">
      <input type="hidden" name="_t" value="<?= h($csrf) ?>">
      <input type="hidden" name="bon_save" value="1">
      <label>Kod
        <input type="text" name="code" placeholder="zostaw puste = wylosuj"
               value="<?= h((string) ($bonPost['code'] ?? '')) ?>"></label>
      <label>Rodzaj
        <select name="kind">
          <?php foreach (['procent' => 'Procent', 'kwota' => 'Kwota', 'wysylka' => 'Darmowa wysyłka'] as $k => $lib): ?>
          <option value="<?= h($k) ?>"<?= ($bonPost['kind'] ?? '') === $k ? ' selected' : '' ?>><?= h($lib) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label>Rabat (%)
        <input type="text" name="pct" placeholder="10" value="<?= h((string) ($bonPost['pct'] ?? '')) ?>"></label>
      <label>Kwota (zł)
        <input type="text" name="kwota" placeholder="20,00" value="<?= h((string) ($bonPost['kwota'] ?? '')) ?>"></label>
      <label>Od kwoty (zł)
        <input type="text" name="min_gross" placeholder="0" value="<?= h((string) ($bonPost['min_gross'] ?? '')) ?>"></label>
      <label>Ważny do
        <input type="date" name="ends_at" value="<?= h((string) ($bonPost['ends_at'] ?? '')) ?>"></label>
      <label>Ile użyć
        <input type="number" name="max_uses" min="0" placeholder="0 = bez limitu"
               value="<?= h((string) ($bonPost['max_uses'] ?? '')) ?>"></label>
      <label>Na adres
        <input type="number" name="per_email" min="0" placeholder="0 = bez limitu"
               value="<?= h((string) ($bonPost['per_email'] ?? '')) ?>"></label>
      <label class="wideL">Opis wewnętrzny
        <input class="wide" type="text" name="note" placeholder="np. kampania jesienna"
               value="<?= h((string) ($bonPost['note'] ?? '')) ?>"></label>
      <label class="chk"><input type="checkbox" name="active" value="1" checked> Aktywny</label>
      <button class="primary" type="submit">Dodaj kod</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($ref): $unit = (float) $ref['prix']; $w = (int) $ref['weight_g']; ?>
  <div class="panel">
    <h2>Ile to daje w praktyce</h2>
    <p class="hint" style="margin-bottom:12px">Na przykładzie: <b><?= h((string) $ref['nom']) ?></b>, <?= h(kg($w)) ?>, <?= number_format($unit, 2, ',', ' ') ?> zł.</p>
    <div class="tablewrap">
    <table class="rwd">
      <thead><tr><th>Sztuk</th><th class="num">Waga</th><th class="num">Rabat</th><th class="num">Do zapłaty</th><th class="num">Cena / kg</th></tr></thead>
      <tbody>
      <?php foreach ([1, 3, 5, 10, 20] as $n):
        $tw = $w * $n;
        [$pc, ] = wsm_discount_for_weight($pdo, $tw);
        $tot = round($unit * $n * (1 - $pc / 100), 2); ?>
      <tr>
        <td data-l="Sztuk"><?= $n ?></td>
        <td data-l="Waga" class="num"><?= h(kg($tw)) ?></td>
        <td data-l="Rabat" class="num"><?= $pc > 0 ? '−' . rtrim(rtrim(number_format($pc, 2, ',', ''), '0'), ',') . ' %' : '—' ?></td>
        <td data-l="Do zapłaty" class="num"><?= number_format($tot, 2, ',', ' ') ?> zł</td>
        <td data-l="Cena / kg" class="num ex"><?= $tw > 0 ? number_format($tot / ($tw / 1000), 2, ',', ' ') . ' zł' : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>
<?php console_foot();
