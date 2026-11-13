<?php
// ============================================================================
//  ksef.php — le registre national des factures, vu d'ici.
//
//  L'ÉCRAN EST UTILE ALORS MÊME QUE LE CANAL EST FERMÉ, et c'est tout son
//  intérêt. Sans jeton d'autorisation, il fait déjà les deux choses qui
//  coûtent cher à découvrir tard :
//
//   · il CONSTRUIT le document XML FA(2) et le donne à télécharger — on peut
//     le déposer à la main sur le portail du ministère dès aujourd'hui ;
//   · il dit, facture par facture, CE QUI EMPÊCHERAIT le dépôt. « Sprzedaż
//     wewnątrzwspólnotowa bez numeru VAT-UE » est une correction de dix
//     minutes ; la découvrir au contrôle coûte le taux polonais sur la vente.
//
//  Aucun bouton n'envoie quoi que ce soit tant que les identifiants manquent,
//  et le bandeau le dit avant tout le reste.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/ksef.php';

// Le téléchargement passe AVANT toute sortie : un seul octet écrit plus haut
// et l'en-tête part en erreur au lieu du fichier.
$xmlId = (int) ($_GET['xml'] ?? 0);
if ($xmlId > 0) {
    $inv = wsm_invoice_by_id($pdo, $xmlId);
    if (!$inv) { http_response_code(404); exit('Nie ma takiej faktury.'); }
    $xml = wsm_ksef_xml($pdo, $inv);
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . wsm_ksef_nazwa_pliku($inv) . '"');
    header('Content-Length: ' . strlen($xml));
    echo $xml;
    exit;
}

$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$flash = ''; $kind = 'ok';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może wysyłać faktury do KSeF.'; $kind = 'err';
    } elseif (isset($_POST['wyslij'])) {
        // La file est recalculée ici, côté serveur : un champ caché portant
        // la liste des identifiants serait modifiable, et déposer au registre
        // national une facture qu'on n'a pas vue ne se rattrape pas.
        $r = wsm_ksef_run($pdo, (string) ($me['nom'] ?? ''));
        $flash = $r['message'];
        $kind = $r['wyslane'] > 0 ? 'ok' : 'err';
    }
}

$ouvert = wsm_ksef_enabled();
$manque = wsm_ksef_manquants();
$cfg    = wsm_ksef_cfg();
$file   = wsm_ksef_queue($pdo);
$k      = wsm_ksef_kpis($pdo, $file);
$gotowe = array_values(array_filter($file, fn($x) => !$x['blockers']));

console_head('KSeF', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 20px; max-width: 82ch; line-height: 1.6; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 22px; }
  .kpi { background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 14px 16px; }
  .kpi b { display: block; font-family: var(--font-mono); font-size: 22px; color: var(--text-strong); }
  .kpi span { font-size: 12.5px; color: var(--text-muted); }
  .kpi.pret b { color: var(--ok, #1a7f4b); }
  .kpi.alarme b { color: var(--danger); }
  .manque { margin: 0; padding-left: 20px; line-height: 1.9; font-size: 13.5px; }
  .manque code { font-family: var(--font-mono); font-size: 12.5px; }
  .l { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: start;
       padding: 12px 14px; border: 1px solid var(--border-subtle); border-radius: var(--radius-md);
       background: var(--surface-card); margin-bottom: 10px; }
  .l b { font-family: var(--font-mono); font-size: 14px; color: var(--text-strong); }
  .l small { display: block; color: var(--text-muted); font-size: 12.5px; margin-top: 3px; }
  .l .droite { text-align: right; white-space: nowrap; display: flex; flex-direction: column;
               align-items: flex-end; gap: 7px; }
  .l .droite .kw { font-family: var(--font-mono); font-size: 12.5px; color: var(--text-muted); }
  .stop { color: var(--danger); font-size: 12.5px; line-height: 1.6; margin-top: 6px; }
  .stop li { margin-bottom: 2px; }
  .stop ul { margin: 0; padding-left: 18px; }
  .tag { font-size: 12px; padding: 2px 10px; border-radius: 999px; border: 1px solid var(--border-subtle);
         display: inline-block; margin-top: 6px; color: var(--text-muted); }
  .tag.kor { color: var(--warn, #9a6a00); border-color: color-mix(in srgb, var(--warn, #9a6a00) 45%, transparent); }
  .lien { font-size: 12.5px; min-height: 38px; display: inline-flex; align-items: center;
          padding: 0 13px; border: 1px solid var(--border-subtle); border-radius: var(--radius-md);
          text-decoration: none; color: var(--text-strong); }
  .lien:hover { border-color: var(--brand); }
  @media (max-width: 640px) {
    .l { grid-template-columns: 1fr; }
    .l .droite { align-items: flex-start; text-align: left; }
  }
CSS, '');
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'KSeF' => null]);
?>

  <p class="hint">
    Ten ekran działa, <b>zanim</b> kanał zostanie otwarty — i po to istnieje. Każdą fakturę
    można już dziś <b>pobrać jako XML&nbsp;FA(2)</b> i złożyć ręcznie w aplikacji Ministerstwa.
    Pokazuje też, co <b>zablokowałoby</b> wysyłkę: „sprzedaż wewnątrzwspólnotowa bez numeru
    VAT-UE nabywcy” to dziesięć minut poprawki, a odkryta w trakcie kontroli kosztuje polską
    stawkę od całej sprzedaży.
    <b>Numer KSeF zapisuje się raz.</b> Drugie wysłanie tej samej faktury utworzyłoby duplikat
    w rejestrze państwowym, który da się usunąć wyłącznie korektą — dlatego faktura z numerem
    nie wraca do kolejki.
    Uwaga na daty: dla urzędu faktura jest wystawiona w dniu, w którym <b>KSeF ją przyjmie</b>,
    a nie w dniu wydruku.
  </p>

  <?php if (!$ouvert): ?>
  <div class="panel" style="border-color: color-mix(in srgb, var(--warn, #9a6a00) 40%, transparent)">
    <h2>Kanał KSeF jest zamknięty</h2>
    <p class="hint" style="margin-bottom:10px">
      Brakuje danych dostępowych. Trafiają wyłącznie na serwer —
      do <code>config.local.php</code> lub zmiennych środowiskowych.
      <b>Nigdy do repozytorium</b>, które jest publiczne. Token KSeF ma moc podpisu
      pod każdą naszą fakturą.
    </p>
    <ul class="manque">
      <?php foreach ($manque as $x): ?><li><?= h($x) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php else: ?>
  <div class="panel">
    <h2>Kanał KSeF jest otwarty — środowisko <?= h($cfg['env']) ?></h2>
    <p class="hint" style="margin:0">
      <?php if ($cfg['env'] !== 'prod'): ?>
        To jest środowisko testowe. Faktury tu złożone <b>nie mają mocy prawnej</b> —
        żeby wysyłać naprawdę, ustaw <code>WSM_KSEF_ENV=prod</code> i token z produkcji.
      <?php else: ?>
        Środowisko produkcyjne. Każda wysyłka jest <b>ostateczna</b>.
      <?php endif; ?>
    </p>
  </div>
  <?php endif; ?>

  <div class="kpis">
    <div class="kpi pret"><b><?= (int) $k['gotowe'] ?></b><span>gotowych do złożenia</span></div>
    <div class="kpi <?= $k['zablokowane'] > 0 ? 'alarme' : '' ?>"><b><?= (int) $k['zablokowane'] ?></b><span>zablokowanych</span></div>
    <div class="kpi"><b><?= h(pln((int) $k['kwota'])) ?></b><span>na fakturach gotowych</span></div>
    <div class="kpi"><b><?= (int) $k['przyjete'] ?></b><span>już w rejestrze</span></div>
    <div class="kpi"><b><?= (int) $k['odrzucone'] ?></b><span>odrzuconych przez KSeF</span></div>
  </div>

  <div class="panel">
    <h2>Poza rejestrem</h2>
    <?php if (!$file): ?>
      <p class="muted">Nic nie czeka. Albo wszystkie faktury są w rejestrze, albo nie ma jeszcze żadnej.</p>
    <?php else: ?>
      <?php if ((int) $k['wszystkie'] > count($file)): ?>
      <p class="hint" style="margin:0 0 14px">
        Poza rejestrem jest <b><?= (int) $k['wszystkie'] ?></b> faktur; pokazujemy
        <b><?= count($file) ?></b> najnowszych. Liczniki powyżej opisują <b>to, co widać</b>,
        a nie całość — inaczej przycisk obiecywałby więcej, niż lista pokazuje.
      </p>
      <?php endif; ?>
      <?php foreach ($file as $x): $inv = $x['inv']; ?>
      <div class="l">
        <span>
          <b><?= h((string) $inv['number']) ?></b>
          <small><?= h((string) $inv['buyer_name']) ?>
            <?php if (trim((string) $inv['buyer_nip']) !== ''): ?> · NIP <?= h((string) $inv['buyer_nip']) ?><?php endif; ?>
            <?php if (trim((string) $inv['buyer_vat_eu']) !== ''): ?> · VAT-UE <?= h((string) $inv['buyer_vat_eu']) ?><?php endif; ?>
            · wystawiona <?= h((string) $inv['issued_at']) ?></small>
          <?php if ((string) $inv['kind'] === 'korekta'): ?>
            <span class="tag kor">korekta</span>
          <?php endif; ?>
          <?php if (!empty($inv['reverse_charge'])): ?>
            <span class="tag">dostawa wewnątrzwspólnotowa · 0 %</span>
          <?php endif; ?>
          <?php if ($x['obce']): ?>
            <span class="tag">stawki spoza schemy: <?= h(implode(', ', array_map(fn($p) => $p . ' %', $x['obce']))) ?></span>
          <?php endif; ?>
          <?php if ($x['blockers']): ?>
            <div class="stop"><ul>
              <?php foreach ($x['blockers'] as $b): ?><li><?= h($b) ?></li><?php endforeach; ?>
            </ul></div>
          <?php endif; ?>
        </span>
        <span class="droite">
          <span class="kw"><?= h(pln((int) $inv['total_gross'])) ?></span>
          <a class="lien" href="ksef.php?xml=<?= (int) $inv['id'] ?>">Pobierz XML</a>
        </span>
      </div>
      <?php endforeach; ?>

      <?php if ($isAdmin && $ouvert && $gotowe): ?>
      <form method="post" style="margin-top:16px">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <button class="primary" type="submit" name="wyslij" value="1">
          Złóż <?= count($gotowe) ?> faktur w KSeF</button>
      </form>
      <?php elseif ($gotowe): ?>
      <p class="hint" style="margin:16px 0 0">
        <b><?= count($gotowe) ?></b> faktur jest gotowych, ale kanał jest zamknięty.
        Pobierz XML i złóż je w aplikacji Ministerstwa — dokument jest ten sam.
      </p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php console_foot();
