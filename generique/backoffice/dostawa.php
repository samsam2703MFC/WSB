<?php
// ============================================================================
//  dostawa.php — UNE seule page pour décider de la livraison.
//
//  POURQUOI CET ÉCRAN EXISTE. La même décision se prenait à trois endroits :
//  les pays desservis dans « Kraje », les tarifs et le poids dans « Treści »,
//  et la marge dans « Audyt ». Or ces trois choses ne se règlent pas
//  séparément — le seuil de gratuité du port dépend du coût du colis, qui
//  dépend du transporteur, qui dépend du pays. On les regardait l'une après
//  l'autre en gardant la précédente en tête, ce qui est exactement la façon de
//  se tromper d'un facteur dix sans s'en apercevoir.
//
//  ET SURTOUT : « darmowa dostawa od 200 zł » se décidait au doigt mouillé,
//  parce que le voisin affiche 200. La question a pourtant une réponse
//  arithmétique — il ne manquait que le taux de marge, qui est mesuré depuis
//  toujours dans analytics.php. Le calcul est ici, avec sa formule écrite à
//  côté du résultat.
//
//  CE QUE CET ÉCRAN NE FAIT PAS. Il n'ouvre pas un pays à la vente : cocher un
//  pays engage la TVA, l'OSS et les mentions légales, pas seulement un colis.
//  Cette décision reste dans « Kraje », et les deux écrans se renvoient l'un à
//  l'autre.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/cms.php';           // wsm_cms_grosze()
require_once $API . '/analytics.php';
// WSM_SHIP_MANUELS : les transporteurs connus qu'aucune API ne pilote. Sans ce
// require, la carte tombait en « Undefined constant » au premier tour de
// boucle — page à moitié rendue, formulaire ouvert et jamais fermé.
require_once $API . '/shipping.php';

$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$flash = ''; $kind = 'ok';

/** La part de marge qu'on veut garder, retenue d'un envoi à l'autre. */
$garde = isset($_REQUEST['garde']) ? (float) str_replace(',', '.', (string) $_REQUEST['garde']) : 0.0;
$garde = max(0.0, min(90.0, $garde)) / 100;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    if (!$isAdmin) {
        $flash = 'Twoja rola nie pozwala zmieniać dostawy.'; $kind = 'err';

    } elseif (isset($_POST['metody'])) {
        // Une seule écriture pour toute la ligne : séparer « tarif » et
        // « portée » en deux formulaires obligeait à enregistrer deux fois et
        // laissait la moitié du travail en plan si l'on quittait la page.
        $up = $pdo->prepare("UPDATE wsm_shipping_methods
                                SET active = ?, kind = ?, countries = ?, price_net = ?,
                                    cost_net = ?, min_weight_g = ?, max_weight_g = ?,
                                    free_from = ?
                              WHERE id = ?");
        /** Kilos saisis → grammes. La virgule polonaise vaut le point. */
        $enGrammes = fn($v): int => (int) round(((float) str_replace(',', '.', trim((string) $v))) * 1000);
        // Les codes pays sont vérifiés CONTRE LA TABLE, pas contre une forme :
        // la règle et ses raisons vivent dans wsm_ship_codes() (shop.php), pour
        // qu'une suite puisse la tenir sans passer par un formulaire.
        $rejetes = []; $fermes = [];
        $n = 0;
        foreach ((array) ($_POST['d'] ?? []) as $id => $row) {
            // Les cases cochées arrivent en tableau ; on les repasse par la
            // MÊME vérification que la saisie libre d'hier, pour qu'il n'y ait
            // pas deux règles selon la façon dont le pays est entré.
            $brut = is_array($row['kraje'] ?? null) ? implode(',', $row['kraje']) : '';
            $v = wsm_ship_codes($pdo, $brut);
            $codes = $v['codes'];
            foreach ($v['inconnus'] as $c) $rejetes[$c] = true;
            foreach ($v['fermes'] as $c)   $fermes[$c]  = true;
            $k = in_array($row['kind'] ?? '', ['punkt', 'brak'], true) ? (string) $row['kind'] : 'adres';
            $up->execute([
                empty($row['active']) ? 0 : 1,
                $k,
                implode(',', $codes),
                wsm_cms_grosze($row['price_net'] ?? ''),
                wsm_cms_grosze($row['cost_net'] ?? ''),
                // LE PLANCHER, saisi en kilos. Zéro veut dire « pas de
                // minimum » — c'est le cas de tous les transporteurs sauf
                // celui qui prend des palettes.
                max(0, $enGrammes($row['min_weight_kg'] ?? '0')),
                // Jamais zéro : un poids maximum à 0 refuserait TOUTES les
                // commandes, y compris celles d'un seul carré de chocolat.
                max(1, $enGrammes($row['max_weight_kg'] ?? '25')),
                wsm_cms_grosze($row['free_from'] ?? ''),
                (string) $id,
            ]);
            $n++;
        }
        wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Zmiana dostawy', $n . ' metod', 'Sieć');
        $flash = 'Zapisano ' . $n . ' metod dostawy.';
        // Ce qu'on a jeté et ce qui va coincer : on le dit MAINTENANT. Écrit
        // silencieusement, un code invalide se découvre le jour où un client
        // n'arrive pas à commander.
        if ($rejetes) {
            $flash .= ' Pominięto nieznane kody: ' . implode(', ', array_keys($rejetes)) . '.';
            $kind = 'err';
        }
        if ($fermes) {
            $flash .= ' Uwaga: ' . implode(', ', array_keys($fermes))
                    . ' — przewoźnik tam jeździ, ale kraj nie jest otwarty na sprzedaż (Kraje).';
        }

    } elseif (isset($_POST['ustaw_prog'])) {
        // On écrit le seuil CALCULÉ, pas un nombre tapé : c'est tout l'objet
        // de l'écran. Le montant voyage dans le formulaire parce qu'il est
        // affiché juste à côté — le recalculer ici pourrait donner autre chose
        // que ce qui a été lu, et on aurait cliqué sur un chiffre pour en
        // enregistrer un autre.
        $id  = (string) $_POST['ustaw_prog'];
        $val = (int) ($_POST['prog_brut'] ?? 0);
        if ($val > 0) {
            $pdo->prepare("UPDATE wsm_shipping_methods SET free_from = ? WHERE id = ?")->execute([$val, $id]);
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Próg darmowej dostawy',
                      $id . ' → ' . $val . ' gr', 'Sieć');
            $flash = 'Ustawiono próg dla ' . $id . ' na ' . pln($val) . ' brutto.';
        } else { $flash = 'Nie ma czego ustawić — próg nie został policzony.'; $kind = 'err'; }
    }
}

$methods = $pdo->query("SELECT * FROM wsm_shipping_methods ORDER BY sort_order, id")->fetchAll() ?: [];
$serie   = wsm_margin_series($pdo, 12);
$marge   = wsm_marge_taux($pdo, $serie);
$vat     = wsm_vat_moyen($pdo);
$actifs  = (int) $pdo->query("SELECT COUNT(*) FROM wsm_countries WHERE active = 1")->fetchColumn();

/** Le libellé public d'une méthode, ou son identifiant à défaut. */
$etiquette = function (string $id) use ($pdo): string {
    static $S = null;
    if ($S === null) $S = wsm_shop_strings($pdo, 'pl');
    return (string) ($S['ship.' . $id . '.label'] ?? $id);
};
$zl2 = fn($g) => number_format(((int) $g) / 100, 2, '.', '');

// LES PAYS, EN DEUX TAS. Ouverts d'abord — c'est là qu'on choisit ; fermés
// repliés dessous, parce qu'on prépare parfois un transporteur avant d'ouvrir
// le marché, et surtout parce qu'un pays coché mais fermé DOIT rester affiché :
// une case non rendue n'est pas envoyée, donc l'enregistrement l'effacerait
// sans un mot.
$tousPaysBase = $pdo->query("SELECT code, name_pl, active FROM wsm_countries
                             ORDER BY active DESC, name_pl")->fetchAll() ?: [];
$ouverts = array_values(array_filter($tousPaysBase, fn($c) => (int) $c['active'] === 1));
$fermes  = array_values(array_filter($tousPaysBase, fn($c) => (int) $c['active'] !== 1));

/** Grammes → kilos, sans zéros inutiles. 31500 → « 31.5 », 0 → « 0 ». */
$kg = function ($g): string {
    $v = ((int) $g) / 1000;
    $s = number_format($v, 3, '.', '');
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
};
/**
 * Un pourcentage lisible. ON NE RONGE LES ZÉROS QU'APRÈS LA VIRGULE.
 *
 * Sans cette garde, « 100 » perd son zéro final et devient « 1 » : la formule
 * affichait « marża × 1 % » là où elle voulait dire 100 %, et un taux de TVA
 * de 20 % se serait lu « 2 % ». La chaîne est juste dans les deux cas où on
 * l'a regardée (23 % et 5 %, qui n'ont pas de zéro final) — c'est exactement
 * ce qui fait passer ce genre de faute.
 */
$pct = function (float $r, int $d = 1): string {
    $s = number_format($r * 100, $d, ',', "\u{202F}");
    if (str_contains($s, ',')) $s = rtrim(rtrim($s, '0'), ',');
    return $s . "\u{202F}%";
};

console_head('Dostawa', $me, <<<'CSS'
  .why { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0 0 14px; }
  .calc { font-family: var(--font-mono); font-size: 12.5px; color: var(--text-muted);
          background: var(--surface-sunken); border-radius: 8px; padding: 10px 12px;
          margin: 10px 0 0; line-height: 1.9; }
  .num input { width: 100%; box-sizing: border-box; text-align: right; }
  td.num { white-space: nowrap; }
  .src { font-size: 11.5px; padding: 2px 9px; border-radius: 999px; white-space: nowrap;
         border: 1px solid var(--border-default); color: var(--text-muted); }
  .src.mesure { color: var(--success); border-color: var(--success); }
  .src.devine { color: var(--warning); border-color: var(--warning); }
  .prog b { font-family: var(--font-display); font-size: 19px; color: var(--text-strong); }
  .prog small { display: block; font-size: 12px; color: var(--text-muted); margin-top: 3px; }
  .impossible { color: var(--warning); font-size: 12.5px; }
  /* UNE CARTE PAR MÉTHODE, et pas une ligne de tableau. Sept champs plus
     vingt-sept pays ne tiennent pas dans une rangée — et surtout pas sur le
     téléphone depuis lequel on regarde la boutique dans l'atelier. */
  .met { border: 1px solid var(--border-subtle); border-radius: 12px;
         padding: 14px 16px 16px; margin: 0 0 16px; min-width: 0; }
  .met > legend { font-family: var(--font-display); font-size: 16px; padding: 0 8px; }
  .met > legend small { display: block; font-family: var(--font-mono); font-size: 11px;
                        font-weight: 400; color: var(--text-muted); margin-top: 2px; }
  .met .grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 0 16px; }
  @media (min-width: 640px)  { .met .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  @media (min-width: 1100px) { .met .grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
  .kraje { margin-top: 6px; border-top: 1px solid var(--border-subtle); padding-top: 12px; }
  .kraje .tytul { display: block; font-size: 12px; color: var(--text-muted);
                  text-transform: uppercase; letter-spacing: .06em; margin-bottom: 8px; }
  /* Des colonnes qui s'adaptent : vingt-sept pays sur une seule colonne font
     défiler la page trois écrans pour cocher une case. */
  .kratka { display: grid; gap: 2px 14px;
            grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); }
  .kratka .check { display: flex; align-items: center; gap: 8px; min-height: 34px;
                   font-size: 13.5px; }
  .kratka code { font-family: var(--font-mono); font-size: 11.5px; color: var(--text-muted); }
  .kraje details { margin-top: 10px; }
  .kraje summary { font-size: 12.5px; color: var(--text-muted); cursor: pointer;
                   min-height: 34px; display: flex; align-items: center; }
  .zamk { font-style: normal; font-size: 10.5px; color: var(--warning); }
CSS);
console_crumbs(['Pulpit' => 'pulpit.php', 'Dostawa' => null]);
console_flash($flash, $kind);
?>

<div class="kpis">
  <div class="kpi"><b><?= count(array_filter($methods, fn($m) => (int) $m['active'] === 1)) ?></b><span>Czynne metody</span></div>
  <div class="kpi"><b><?= $actifs ?></b><span>Kraje otwarte na sprzedaż</span></div>
  <div class="kpi<?= $marge['source'] === 'sprzedaz' ? '' : ' hot' ?>">
    <b><?= $marge['taux'] > 0 ? h($pct($marge['taux'])) : '—' ?></b><span>Marża</span></div>
  <div class="kpi"><b><?= h($pct($vat, 0)) ?></b><span>Średni VAT</span></div>
</div>

<div class="panel">
  <h2>Metody dostawy</h2>
  <p class="why">
    Wszystko, co decyduje o dostawie, w jednej karcie na metodę. <b>Kraje</b> zaznacza się
    z listy — nie wpisuje. Kraj otwarty na sprzedaż, ale bez przewoźnika,
    <b>nie pozwoli złożyć zamówienia</b>, i kasa powie to wprost.
    <br>
    <b>Waga od / do</b> decyduje, co klient w ogóle zobaczy. Paczkomat kończy się na 25 kg,
    a transport paletowy <b>zaczyna</b> od 200 kg: metoda, która nie weźmie tego koszyka,
    nie pokazuje się w kasie. Wcześniej pokazywała się i odmawiała po wyborze.
    <br>
    <b>Koszt u przewoźnika</b> to Wasz rachunek za paczkę — klient go nie widzi. Bez niego
    nie da się policzyć progu darmowej dostawy niżej, ani zobaczyć w
    <a href="audyt.php">Audycie</a>, jaką część dostawy pokrywa klient.
    <br>
    O tym, <b>gdzie w ogóle sprzedajecie</b>, decyduje ekran <a href="kraje.php">Kraje</a> —
    to nie jest ta sama decyzja: otwarcie kraju pociąga VAT i OSS, nie tylko paczkę.
  </p>

  <form method="post">
    <input type="hidden" name="_t" value="<?= h($csrf) ?>">
    <input type="hidden" name="metody" value="1">
    <?php foreach ($methods as $m): $id = (string) $m['id'];
      $sien = array_filter(array_map('trim', explode(',', strtoupper((string) ($m['countries'] ?? '')))));
      $aLui = fn(string $c) => in_array($c, $sien, true);
      // LES CODES QUE LA TABLE DES PAYS NE CONNAÎT PAS DU TOUT — pas ceux qui
      // sont simplement fermés à la vente : ceux-là ont leur case dans le
      // repli, cochée, et survivent à l'enregistrement. La comparaison portait
      // sur les seuls pays OUVERTS, si bien qu'un « DE » fermé s'annonçait
      // « absent de la table » et promettait d'être effacé — alors qu'il est
      // là et qu'il ne bougera pas.
      $extras = array_values(array_diff($sien, array_map('strtoupper',
                array_column($tousPaysBase, 'code'))));
      $manuel = isset(WSM_SHIP_MANUELS[(string) $m['carrier']]); ?>
    <fieldset class="met">
      <legend><?= h($etiquette($id)) ?>
        <small><?= h($id) ?> · <?= h((string) $m['carrier']) ?><?php
          if ($manuel) echo ' · nadanie ręczne'; ?></small></legend>

      <?php if ($manuel): ?>
      <p class="why" style="margin:0 0 12px">
        <?php // On le DIT ici et pas seulement dans la file : quelqu'un qui
              // active cette méthode doit savoir tout de suite qu'aucune
              // étiquette ne sortira toute seule. ?>
        Ten przewoźnik nie ma automatycznego nadania: zamówienie przejdzie te same kontrole
        danych co inne, ale <b>list przewozowy umawia się telefonicznie</b>. Wysyłka pokaże to
        wprost, zamiast udawać błąd konfiguracji.
      </p>
      <?php endif; ?>

      <div class="grid">
        <label class="field"><span>Czynna</span>
          <label class="check"><input type="checkbox" name="d[<?= h($id) ?>][active]" value="1"
            <?= (int) $m['active'] === 1 ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>>
            <span>widoczna w kasie</span></label></label>

        <label class="field"><span>Odbiór</span>
          <?php // Point ou adresse : c'est CE champ qui décide si la caisse
                // demande un code de paczkomat ou une rue. ?>
          <select name="d[<?= h($id) ?>][kind]"<?= $isAdmin ? '' : ' disabled' ?>>
            <option value="adres"<?= wsm_ship_kind_row($m) === 'adres' ? ' selected' : '' ?>>Pod adres</option>
            <option value="punkt"<?= wsm_ship_kind_row($m) === 'punkt' ? ' selected' : '' ?>>Do punktu</option>
            <option value="brak"<?= wsm_ship_kind_row($m) === 'brak' ? ' selected' : '' ?>>Bez wysyłki (usługa, licencja) — tylko dla koszyka bez towaru</option>
          </select></label>

        <label class="field"><span>Cena netto (zł)</span>
          <input inputmode="decimal" name="d[<?= h($id) ?>][price_net]"
                 value="<?= h($zl2($m['price_net'])) ?>"<?= $isAdmin ? '' : ' disabled' ?>></label>

        <label class="field"><span>Koszt u przewoźnika (zł)</span>
          <input inputmode="decimal" name="d[<?= h($id) ?>][cost_net]"
                 value="<?= h($zl2($m['cost_net'] ?? 0)) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
          <span class="hint">Wasz rachunek. Bez niego próg niżej się nie policzy.</span></label>

        <?php // EN KILOS, PAS EN GRAMMES. « 1500000 » se saisit de travers une
              // fois sur trois, et un zéro de trop ouvre un transporteur à des
              // colis qu'il refusera au dépôt. La base garde des grammes. ?>
        <label class="field"><span>Waga od (kg)</span>
          <input inputmode="decimal" name="d[<?= h($id) ?>][min_weight_kg]"
                 value="<?= h($kg($m['min_weight_g'] ?? 0)) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
          <span class="hint">0 = bez dolnej granicy</span></label>

        <label class="field"><span>Waga do (kg)</span>
          <input inputmode="decimal" name="d[<?= h($id) ?>][max_weight_kg]"
                 value="<?= h($kg($m['max_weight_g'] ?? 0)) ?>"<?= $isAdmin ? '' : ' disabled' ?>></label>

        <label class="field"><span>Gratis od (zł brutto)</span>
          <input inputmode="decimal" name="d[<?= h($id) ?>][free_from]"
                 value="<?= h($zl2($m['free_from'])) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
          <span class="hint">0 = nigdy za darmo</span></label>
      </div>

      <div class="kraje">
        <span class="tytul">Kraje</span>
        <?php if (!$ouverts): ?>
          <p class="why" style="margin:0">Żaden kraj nie jest otwarty na sprzedaż —
            otwórz go w <a href="kraje.php">Krajach</a>.</p>
        <?php else: ?>
        <div class="kratka">
          <?php foreach ($ouverts as $c): $code = strtoupper((string) $c['code']); ?>
          <label class="check"><input type="checkbox" name="d[<?= h($id) ?>][kraje][]"
                 value="<?= h($code) ?>"<?= $aLui($code) ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>>
            <span><?= h((string) $c['name_pl']) ?> <code><?= h($code) ?></code></span></label>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($fermes || $extras): ?>
        <?php // Repliés, parce qu'on ne choisit presque jamais dedans — mais
              // présents, parce qu'on prépare parfois un transporteur avant
              // d'ouvrir le marché, et parce que ceux qui sont DÉJÀ cochés
              // disparaîtraient à l'enregistrement s'ils n'étaient pas rendus. ?>
        <details<?= $extras ? ' open' : '' ?>>
          <summary>Kraje zamknięte na sprzedaż (<?= count($fermes) ?>)</summary>
          <div class="kratka">
            <?php foreach ($fermes as $c): $code = strtoupper((string) $c['code']); ?>
            <label class="check"><input type="checkbox" name="d[<?= h($id) ?>][kraje][]"
                   value="<?= h($code) ?>"<?= $aLui($code) ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>>
              <span><?= h((string) $c['name_pl']) ?> <code><?= h($code) ?></code>
                <em class="zamk">zamknięty</em></span></label>
            <?php endforeach; ?>
          </div>
          <?php if ($extras): ?>
          <p class="why" style="margin:8px 0 0">Ta metoda ma zapisane kody, których nie ma
            w tabeli krajów: <b><?= h(implode(', ', $extras)) ?></b>. Zapis je usunie.</p>
          <?php endif; ?>
        </details>
        <?php endif; ?>
      </div>
    </fieldset>
    <?php endforeach; ?>

    <div class="actions">
      <button class="primary" type="submit"<?= $isAdmin ? '' : ' disabled' ?>>Zapisz metody</button>
    </div>
  </form>
</div>

<div class="panel">
  <h2>Od jakiej kwoty stać nas na darmową dostawę</h2>

  <?php
  // ==========================================================================
  //  LA PROVENANCE DU TAUX AVANT LE TAUX.
  //
  //  Un seuil calculé sur une marge devinée est un seuil deviné. On dit donc
  //  d'abord d'où vient le nombre — vendu, catalogue, ou rien — et on refuse
  //  de calculer quand il n'y a rien, plutôt que de combler avec 15 %.
  // ==========================================================================
  ?>
  <p class="why">
    <?php if ($marge['source'] === 'sprzedaz'): ?>
      <span class="src mesure">zmierzona</span>
      Marża <b><?= h($pct($marge['taux'])) ?></b> policzona z <b>opłaconych</b> zamówień z ostatnich
      12 miesięcy — tylko z linii, dla których znany jest koszt zakupu
      (<?= h($pct($marge['couverture'])) ?> obrotu).
    <?php elseif ($marge['source'] === 'katalog'): ?>
      <span class="src devine">z cennika</span>
      Za mało sprzedaży, żeby zmierzyć marżę, więc liczymy ją z <b>katalogu</b>:
      <b><?= h($pct($marge['taux'])) ?></b> na <?= (int) $marge['produits'] ?> produktach, które mają
      wpisany koszt zakupu. To marża <b>na papierze</b> — nie mówi, co się naprawdę sprzedaje.
    <?php else: ?>
      <span class="src devine">brak danych</span>
      <b>Nie da się policzyć progu.</b> Żaden produkt nie ma wpisanego kosztu zakupu, a sprzedaży
      jest za mało, żeby marżę zmierzyć. Uzupełnij <b>koszt zakupu</b> w
      <a href="produkty.php">Produktach</a> — bez niego każdy próg tutaj byłby zgadywaniem
      podanym jako wynik.
    <?php endif; ?>
  </p>

  <?php if ($marge['taux'] > 0): ?>
  <form method="get" class="actions" style="margin:0 0 14px">
    <label class="field" style="margin:0;max-width:320px">
      <span>Ile marży zostawiamy sobie (%)</span>
      <input name="garde" inputmode="decimal" value="<?= h(rtrim(rtrim(number_format($garde * 100, 1, '.', ''), '0'), '.')) ?>">
      <span class="hint">0 % znaczy: zamówienie przy progu <b>wychodzi na zero</b> — cała marża
        idzie na paczkę. Wpisz 30, jeśli chcesz, żeby zostawało 30 % marży.</span>
    </label>
    <button type="submit">Przelicz</button>
  </form>

  <p class="calc">
    próg netto = koszt paczki ÷ (marża × (1 − zatrzymana część))
    &nbsp;=&nbsp; koszt ÷ (<?= h($pct($marge['taux'])) ?> × <?= h($pct(1 - $garde, 0)) ?>)
    <br>
    próg brutto = próg netto × (1 + VAT <?= h($pct($vat, 0)) ?>)
    &nbsp;— bo kasa porównuje próg z kwotą <b>brutto</b> koszyka
  </p>

  <div class="tablewrap" style="margin-top:14px">
  <table class="rwd">
    <thead><tr>
      <th>Metoda</th><th class="num">Koszt paczki</th><th class="num">Próg netto</th>
      <th class="num">Próg brutto</th><th class="num">Zostaje przy progu</th>
      <th class="num">Dziś ustawione</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($methods as $m):
      $id   = (string) $m['id'];
      $cout = (int) ($m['cost_net'] ?? 0);
      $s    = wsm_franco_seuil($cout, (float) $marge['taux'], $garde, $vat);
      $reste = $s['possible'] ? wsm_franco_reste($s['net'], (float) $marge['taux'], $cout) : 0;
      $actuel = (int) $m['free_from']; ?>
      <tr>
        <td data-l="Metoda"><b><?= h($etiquette($id)) ?></b></td>
        <td data-l="Koszt paczki" class="num"><?= $cout > 0 ? h(pln($cout)) : '—' ?></td>
        <?php if (!$s['possible']): ?>
          <td data-l="Próg" class="num" colspan="4"><span class="impossible"><?= h($s['raison']) ?></span></td>
          <td></td>
        <?php else: ?>
          <td data-l="Próg netto" class="num"><?= h(pln($s['net'])) ?></td>
          <td data-l="Próg brutto" class="num prog"><b><?= h(pln($s['brut'])) ?></b>
            <small>tyle musi kosztować towar</small></td>
          <?php // Le contrôle qui empêche de lire le seuil comme un gain :
                // à part gardée nulle, ce qui reste vaut exactement zéro. ?>
          <td data-l="Zostaje przy progu" class="num"><?= h(pln($reste)) ?></td>
          <td data-l="Dziś ustawione" class="num">
            <?= $actuel > 0 ? h(pln($actuel)) : '<span style="color:var(--text-muted)">brak</span>' ?>
            <?php if ($actuel > 0 && $actuel < $s['brut']): ?>
              <br><small class="impossible">niżej niż próg — dopłacacie</small>
            <?php endif; ?>
          </td>
          <td data-l="">
            <form method="post" style="display:inline">
              <input type="hidden" name="_t" value="<?= h($csrf) ?>">
              <input type="hidden" name="prog_brut" value="<?= (int) $s['brut'] ?>">
              <button class="btn sm" name="ustaw_prog" value="<?= h($id) ?>"
                      <?= $isAdmin ? '' : ' disabled' ?>>Ustaw</button>
            </form>
          </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <p class="why" style="margin-top:14px">
    <b>Czego ten rachunek nie wie.</b> Liczy średnią — jedno zamówienie za 300 zł na produktach
    o niskiej marży nadal może dokładać do paczki. I zakłada <b>jedną paczkę na zamówienie</b>:
    przy dwóch przesyłkach próg jest dwa razy wyższy. To liczba do rozmowy, nie wyrocznia.
  </p>
  <?php endif; ?>
</div>

<?php console_foot(); ?>
