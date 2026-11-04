<?php
declare(strict_types=1);
/**
 * widocznosc.php — Google : être trouvé, apparaître dans « Zakupy », mesurer.
 *
 * Trois questions, dans l'ordre où elles se posent :
 *   1. Google connaît-il la boutique ? (Search Console, plan du site, robots)
 *   2. Les produits peuvent-ils apparaître dans l'onglet Zakupy ? (flux
 *      Merchant Center : ce qui part, ce qui est écarté et pourquoi)
 *   3. Chaque fiche est-elle bien écrite pour être trouvée ? (audit : nom,
 *      description, photo, EAN, poids, traductions)
 *
 * Rien n'est noté sur 100 pour faire joli : chaque ligne d'audit est un
 * constat avec son geste, et le lien qui mène à la fiche à corriger.
 */
require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/settings.php';
require_once $API . '/reklama.php';
require_once $API . '/shop.php';
require_once $API . '/delivery.php';   // wsm_audit

$flash = ''; $kind = 'ok';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!console_csrf_ok()) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) { $flash = 'Tylko do odczytu.'; $kind = 'err'; }
    elseif (isset($_POST['zapisz'])) {
        $refus = [];
        $changed = wsm_settings_save($pdo, $_POST, (string) ($me['nom'] ?? ''), $refus, ['google']);
        wsm_settings_apply($pdo);
        if ($changed) wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Google i SEO', implode(', ', $changed), 'Sieć');
        $msg = $refus ? 'Odrzucone: ' . implode(', ', array_keys($refus)) : ($changed ? 'Zapisano: ' . count($changed) . '.' : 'Nic nie zmieniono.');
        header('Location: widocznosc.php?ok=' . rawurlencode($msg) . '&k=' . ($refus ? 'err' : 'ok'), true, 303);
        exit;
    }
}
if (isset($_GET['ok'])) { $flash = (string) $_GET['ok']; $kind = ($_GET['k'] ?? '') === 'err' ? 'err' : 'ok'; }

$cfg = wsm_ads_cfg();
$view = wsm_settings_view($pdo);
$shop = wsm_shop_base_url();
$langs = wsm_shop_available_langs($pdo);
$feed = wsm_feed_produkty($pdo, 'pl', $shop, false);
$audit = wsm_seo_audit($pdo, $langs);
$problemy = count(array_filter($audit, fn($a) => $a['wynik'] < 80));
$zgody = wsm_ads_consent_stats($pdo);
$https = str_starts_with($shop, 'https://') && !filter_var((string) parse_url($shop, PHP_URL_HOST), FILTER_VALIDATE_IP);

console_head('Google i SEO', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 14px; margin: 0 0 16px; max-width: 88ch; line-height: 1.6; }
  .lista { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
  .lista li { display: grid; grid-template-columns: 28px minmax(0, 1fr); gap: 10px; align-items: start; }
  .lista .ico { width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: 13px; }
  .ico.ok { background: color-mix(in srgb, var(--success) 18%, transparent); color: var(--success); }
  .ico.bad { background: color-mix(in srgb, var(--danger) 14%, transparent); color: var(--danger); }
  .ico.wait { background: color-mix(in srgb, var(--warning) 18%, transparent); color: var(--caramel-600); }
  .lista b { color: var(--text-strong); }
  .lista p { margin: 2px 0 0; color: var(--text-muted); font-size: 14px; }
  code.url { word-break: break-all; }
  .uwagi { margin: 0; padding-left: 18px; }
  .uwagi li { margin: 2px 0; font-size: 14px; }
  .uwagi .blad { color: var(--danger); }
  .uwagi .info { color: var(--text-muted); }
  .wynik { font-variant-numeric: tabular-nums; font-weight: 700; }
  .wynik.zle { color: var(--danger); } .wynik.srednio { color: var(--caramel-600); } .wynik.dobrze { color: var(--success); }
CSS);
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Marketing' => null, 'Google i SEO' => null]);

$etap = function (bool $ok, string $titre, string $txt, bool $attente = false): void {
    $c = $ok ? 'ok' : ($attente ? 'wait' : 'bad');
    echo '<li><span class="ico ' . $c . '" aria-hidden="true">' . ($ok ? '✓' : ($attente ? '…' : '!')) . '</span><div><b>' . h($titre) . '</b>'
       . ' <span class="sr-only">' . ($ok ? 'gotowe' : 'do zrobienia') . '</span><p>' . $txt . '</p></div></li>';
};
?>
<div class="kpis">
  <div class="kpi"><b><?= count($feed['items']) ?></b><span>Produkty w pliku Google / Meta</span></div>
  <div class="kpi"><b><?= count($feed['pominiete']) ?></b><span>Pominięte (brak zdjęcia lub ceny)</span></div>
  <div class="kpi"><b><?= $problemy ?></b><span>Kart produktu do poprawy</span></div>
  <div class="kpi"><b><?= $zgody['n'] ? (int) round(100 * $zgody['analityczne'] / $zgody['n']) . ' %' : '—' ?></b><span>Kupujący ze zgodą na analitykę (30 dni)</span></div>
</div>

<?php if ($cfg['bledne']): ?>
<p class="warnbox">Te wartości mają zły format i <b>nie są używane</b>: <?= h(implode(', ', array_intersect_key($cfg['bledne'], array_flip(['gsc', 'ga4', 'ads', 'ads_label', 'merchant', 'bing'])))) ?: 'brak w tej sekcji' ?>.</p>
<?php endif; ?>

<div class="panel"><h2>Krok po kroku</h2>
  <ul class="lista">
  <?php
  $etap($https, 'Adres z domeną i HTTPS',
      $https ? 'Sklep działa pod <code class="url">' . h($shop) . '</code>.'
             : 'Teraz: <code class="url">' . h($shop) . '</code>. Google indeksuje i adres IP, ale Merchant Center, weryfikacja domeny i Instagram wymagają domeny z certyfikatem — to pierwszy krok.', !$https);
  $etap($cfg['gsc'] !== '', 'Search Console',
      $cfg['gsc'] !== '' ? 'Kod weryfikacji jest na każdej stronie. W Search Console kliknij „Zweryfikuj”, potem dodaj mapę witryny poniżej.'
                         : 'search.google.com/search-console → Dodaj usługę → Prefiks URL → „Tag HTML”. Wklej wartość content="…" w polu niżej.');
  $etap(true, 'Mapa witryny i robots.txt',
      'Gotowe same: <code class="url">' . h($shop) . '/sitemap.xml</code> (produkty, strony i formularze we wszystkich opublikowanych językach) · <code class="url">' . h($shop) . '/robots.txt</code>. W Search Console: Mapy witryn → wklej adres sitemap.xml.');
  $etap(count($feed['items']) > 0, 'Google Merchant Center — darmowe wyniki w „Zakupy”',
      'merchants.google.com → Produkty → Źródła danych → Dodaj → „Plik pobierany automatycznie” (codziennie): <code class="url">' . h($shop) . '/feed/produkty.xml</code>'
      . ($cfg['merchant'] !== '' ? ' · <a href="https://merchants.google.com/mc/overview?a=' . h($cfg['merchant']) . '" target="_blank" rel="noopener">otwórz Merchant Center ↗</a>' : '')
      . '. Ceny i dostępność w pliku = ceny na stronie, aktualizowane przy każdym pobraniu.');
  $etap($cfg['ga4'] !== '', 'Google Analytics 4',
      $cfg['ga4'] !== '' ? 'Pomiar ' . h($cfg['ga4']) . ' ładuje się po zgodzie „analityczne” w banerze cookies. Zdarzenia: wyświetlenie produktu, dodanie do koszyka, kasa, zakup (z wartością).'
                         : 'Bez GA4 nie wiesz, skąd przychodzą klienci. analytics.google.com → Administracja → Strumienie danych → identyfikator G-….');
  $etap($cfg['ads'] !== '' && $cfg['ads_label'] !== '', 'Google Ads — konwersja „zakup”',
      $cfg['ads'] !== '' ? ($cfg['ads_label'] !== '' ? 'Zakup liczy się jako konwersja (po zgodzie „marketingowe”), z wartością zamówienia.' : 'Brakuje etykiety konwersji — bez niej zakup się nie liczy.')
                         : 'Opcjonalnie, gdy zaczniesz kampanie Google Ads (w tym Performance Max z pliku Merchant).', $cfg['ads'] === '');
  ?>
  </ul>
</div>

<form method="post" class="panel">
  <?= console_csrf_field() ?>
  <h2>Identyfikatory Google</h2>
  <p class="hint">Wszystkie są publiczne (widać je w kodzie strony) — ale żaden skrypt Google nie ładuje się przed zgodą klienta w banerze cookies.</p>
  <?php console_reglages($view, 'google', $isAdmin); ?>
  <?php if ($isAdmin): ?><p class="actions"><button class="btn btn--brand" type="submit" name="zapisz" value="1">Zapisz</button></p><?php endif; ?>
</form>

<?php if ($feed['pominiete']): ?>
<div class="panel"><h2>Poza plikiem Google / Meta</h2>
  <p class="hint">Te produkty nie trafiają do pliku — Google odrzuciłby je, a powtarzające się odrzucenia potrafią zawiesić całe konto Merchant.</p>
  <div class="tablewrap"><table class="rwd">
    <thead><tr><th>Produkt</th><th>Dlaczego</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($feed['pominiete'] as $p): ?>
      <tr><td data-l="Produkt"><?= h((string) $p['name']) ?></td><td data-l="Dlaczego"><?= h(implode(', ', $p['powody'])) ?></td>
        <td><a href="produkty.php?id=<?= rawurlencode((string) $p['id']) ?>">Popraw ›</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="panel"><h2>Karty produktów — co poprawić</h2>
  <p class="hint">Od najsłabszych. Nazwa to tytuł w Google (do ~70 znaków: rodzaj, marka, % kakao, gramatura). Opis to fragment pod tytułem i treść
    dla wyszukiwarki — skład, smak, zastosowanie, 150+ znaków. EAN pozwala Google połączyć produkt z wyszukiwaniami konkretnego towaru.</p>
  <div class="tablewrap"><table class="rwd">
    <thead><tr><th>Produkt</th><th class="num">Wynik</th><th>Uwagi</th><th></th></tr></thead>
    <tbody>
    <?php if (!$audit): ?><tr><td colspan="4" class="muted">Brak produktów widocznych w sklepie.</td></tr><?php endif; ?>
    <?php foreach ($audit as $a): $w = (int) $a['wynik']; ?>
      <tr>
        <td data-l="Produkt"><a href="<?= h($shop . '/p/' . rawurlencode($a['slug'])) ?>" target="_blank" rel="noopener"><?= h($a['name']) ?> ↗</a></td>
        <td data-l="Wynik" class="num"><span class="wynik <?= $w < 60 ? 'zle' : ($w < 85 ? 'srednio' : 'dobrze') ?>"><?= $w ?></span></td>
        <td data-l="Uwagi"><?php if (!$a['uwagi']): ?><span class="tag ok">w porządku</span><?php else: ?>
          <ul class="uwagi"><?php foreach ($a['uwagi'] as [$niv, $msg]): ?><li class="<?= h($niv) ?>"><?= h($msg) ?></li><?php endforeach; ?></ul><?php endif; ?></td>
        <td><a href="produkty.php?id=<?= rawurlencode($a['id']) ?>">Edytuj ›</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php console_foot();
