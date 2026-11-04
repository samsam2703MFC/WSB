<?php
// ============================================================================
//  layout.php — enveloppe commune des pages de la boutique.
//
//  Sur la performance, trois décisions se voient dans ce fichier :
//   · la police est demandée par un <link> précédé de deux <link preconnect>,
//     pas par un @import enfoui dans une feuille — sinon le navigateur ne la
//     découvre qu'après avoir téléchargé toute la chaîne d'imports ;
//   · les deux feuilles de style sont sur la même origine et se chargent en
//     parallèle (aucune n'en importe une autre) ;
//   · le JavaScript est en `defer` et ne sert qu'au confort : la page est
//     déjà complète et cliquable sans lui.
// ============================================================================


/**
 * @param string $page  premier segment de route ('' = catalogue) — décide si
 *                      la page entre dans un index. Voir seo.php, règle 1 :
 *                      panier, caisse et suivi de commande n'y entrent jamais.
 * @param string $image adresse absolue d'une vignette de partage, si la page
 *                      en a une (la photo du produit sur une fiche).
 */
function layout_head(array $S, string $lang, array $langs, string $title = '',
                     string $desc = '', string $page = '', string $image = ''): void {
    $title = $title !== '' ? $title . ', ' . ($S['brand'] ?? 'Szoko Shop') : ($S['meta.title'] ?? 'Szoko Shop');
    $desc  = $desc !== '' ? $desc : ($S['meta.desc'] ?? '');
    ?><!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<link rel="icon" href="<?= e(logo_src()) ?>">
<link rel="stylesheet" href="<?= e(asset('tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('shop.css')) ?>">
<?= theme_head() ?><?php
// Robots, canonique, grappe hreflang ABSOLUE et partage social. Les hreflang
// émis ici auparavant étaient relatifs : Google les ignore en bloc, sans un
// mot dans la Search Console. Ils n'ont donc jamais rien fait.
seo_head($page, $lang, $langs, $title, $desc, WSM_SHOP_DEFAULT_LANG, $image);
// Google et Meta : codes de vérification, puis — seulement s'il y a des
// balises réglées — la config de zgody.js. Aucun script tiers ici : ils ne
// se chargent qu'après l'accord du visiteur.
echo ads_head();
?>
</head>
<!-- La confirmation d'ajout est traduite en base (product.added) ; sans cet
     attribut, shop.js retombait sur un simple « ✓ » et la traduction ne
     servait à rien. -->
<?php // Une classe par page (strona-start, strona-p, strona-koszyk…) : l'habit
      // « Apple » règle quelques détails page par page (la barre du haut de
      // la fiche produit ne colle pas, sa barre d'achat oui). ?>
<body data-added="<?= e($S['product.added'] ?? '') ?>" class="strona-<?= e($page !== '' ? $page : 'start') ?>">
<?php
}

/**
 * Fil d'Ariane. Deux raisons d'exister, aucune décorative :
 *  • sur un téléphone, il donne le chemin du retour sans dépendre du bouton
 *    « précédent » — celui-ci renvoie à la liste de résultats d'un moteur de
 *    recherche, pas au catalogue ;
 *  • balisé en schema.org, il est repris tel quel par Google sous le titre,
 *    ce qui remplace une URL illisible par « Sklep › Katalog › Produit ».
 *
 * @param array $items  [libellé => href|null], le dernier étant la page courante
 */
function layout_crumbs(array $items): void {
    if (!$items) return;
    $last = array_key_last($items);
    $pos = 0;
    echo '<nav class="crumbs" aria-label="' . e('Ścieżka') . '" itemscope itemtype="https://schema.org/BreadcrumbList">';
    foreach ($items as $label => $href) {
        $pos++;
        if ($pos > 1) echo '<span class="sepc" aria-hidden="true">›</span>';
        echo '<span itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
        if ($href !== null && $label !== $last) {
            echo '<a itemprop="item" href="' . e((string) $href) . '"><span itemprop="name">' . e((string) $label) . '</span></a>';
        } else {
            echo '<span itemprop="name" aria-current="page">' . e((string) $label) . '</span>';
        }
        echo '<meta itemprop="position" content="' . $pos . '"></span>';
    }
    echo '</nav>';
}

function layout_header(array $S, string $lang, array $langs, int $cartCount): void {
    // Les huit langues du projet. Le code court sert d'étiquette ; « UA »
    // et non « UK », parce qu'un visiteur ukrainien lit UK comme britannique.
    $codeLabel = ['pl' => 'PL', 'en' => 'EN', 'uk' => 'UA', 'de' => 'DE',
                  'fr' => 'FR', 'cs' => 'CS', 'sk' => 'SK', 'hu' => 'HU'];
    $self = strtok((string) ($_SERVER['REQUEST_URI'] ?? u()), '?');
    ?>
<?php // La barre d'annonce du Kreator (cible « cały sklep »), sur toutes les pages.
      sekcje_html(wsm_pdo(), $lang, defined('WSM_SECTION_SITE') ? WSM_SECTION_SITE : -1); ?>
<header class="site-head">
  <div class="wrap head-in">
    <a class="head-logo" href="<?= e(u()) ?>" aria-label="<?= e($S['a11y.home'] ?? '') ?>">
      <?php // Le logo, et le NOM à côté : sans lui, une image ne dit à
            // personne chez qui il achète. Un logo déposé qui porte déjà le
            // nom se règle à « 0 » dans Integracje → Sklep. ?>
      <img src="<?= e(logo_src()) ?>" alt="<?= e(logo_nazwa_on() ? '' : ($S['brand'] ?? 'Szoko Shop')) ?>" height="44">
      <?php if (logo_nazwa_on()): ?><span class="head-nazwa"><?= e($S['brand'] ?? 'Szoko Shop') ?></span><?php endif; ?>
    </a>
    <nav class="head-nav" id="head-nav" aria-label="<?= e($S['a11y.nav'] ?? '') ?>">
      <?php // L'ORDRE ET LA PRÉSENCE DES LIENS VIENNENT D'UKŁAD STRONY : Sklep,
            // B2B, Moje zamówienie, Kontakt et les pages cochées « w menu ».
            // Le suivi de commande avant Kontakt par défaut : neuf visiteurs
            // sur dix qui cliquent « Kontakt » demandent où en est leur colis.
            foreach (liens_uklad(wsm_pdo(), $S, $lang, 'nav') as $l): ?>
      <a class="navlink" href="<?= e($l['href']) ?>"><?= e($l['label']) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="head-right">
      <div class="langs" role="group" aria-label="<?= e($S['a11y.lang'] ?? '') ?>">
        <?php foreach ($langs as $l): ?>
        <a href="<?= e($self . '?lang=' . $l) ?>" lang="<?= e($l) ?>"<?= $l === $lang ? ' aria-current="true"' : '' ?>><?= e($codeLabel[$l] ?? strtoupper($l)) ?></a>
        <?php endforeach; ?>
      </div>
      <?php // Le menu sur téléphone : caché tant que shop.js n'est pas là pour
            // l'ouvrir. Sans script, les mêmes liens sont dans le pied de page. ?>
      <button class="head-menu" type="button" aria-expanded="false" aria-controls="head-nav" aria-label="<?= e($S['a11y.nav'] ?? 'Menu') ?>" hidden>
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 9h16M4 15h16"/></svg>
      </button>
      <a class="cart-btn" href="<?= e(u('koszyk')) ?>" aria-label="<?= e($S['a11y.cart'] ?? '') ?>">
        <svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 7h12l-1 13H7L6 7Z"/><path d="M9 7V5a3 3 0 0 1 6 0v2"/></svg>
        <span class="cart-label"><?= e($S['nav.cart'] ?? '') ?></span>
        <span class="cart-count<?= $cartCount ? '' : ' is-zero' ?>" data-cart-count><?= $cartCount ?></span>
      </a>
    </div>
  </div>
</header>
<?php
}

function layout_footer(array $S, string $lang = ''): void {
    if ($lang === '') $lang = (string) ($GLOBALS['lang'] ?? WSM_SHOP_DEFAULT_LANG);
    ?>
<footer class="site-foot">
  <?php // LA NEWSLETTER, en haut du pied, sur toutes les pages : c'est là qu'on
        // la cherche. Le formulaire est celui de la page /newsletter (nl_form). ?>
  <?php if (empty($GLOBALS['WSM_PAS_DE_NL'])): ?>
  <section class="foot-nl" aria-labelledby="foot-nl-t"><div class="wrap foot-nl-in">
    <div class="foot-nl-txt">
      <h2 id="foot-nl-t"><?= e($S['nl.title'] ?? 'Newsletter') ?></h2>
      <p><?= e($S['nl.lead'] ?? '') ?></p>
    </div>
    <?php nl_form($S); ?>
  </div></section>
  <?php endif; ?>
  <?php // COMMENT PAYER, COMMENT RECEVOIR — sur toutes les pages, avant de
        // commander. Ce que la caisse offre vraiment (metody.php). ?>
  <?php $metody = metody_html(wsm_pdo(), $S, 'metody--stopka'); if ($metody !== ''): ?>
  <div class="foot-metody"><div class="wrap"><?= $metody ?></div></div>
  <?php endif; ?>
  <div class="wrap foot-in">
    <div class="foot-main">
      <a class="foot-brand" href="<?= e(u()) ?>">
        <img class="foot-logo" src="<?= e(logo_src()) ?>" alt="" height="40" loading="lazy">
        <?php if (logo_nazwa_on()): ?><span><?= e($S['brand'] ?? '') ?></span><?php endif; ?>
      </a>
      <p class="foot-tagline"><?= e($S['footer.tagline'] ?? '') ?></p>
      <?php // Vente à distance en Pologne : raison sociale, adresse du siège et
            // numéros d'immatriculation doivent figurer sur le site. ?>
      <?php if (($S['seller.name'] ?? '') !== ''): ?>
      <p class="seller">
        <span class="seller-label"><?= e($S['seller.legal'] ?? '') ?></span>
        <?= e($S['seller.name']) ?><br>
        <?= e($S['seller.address'] ?? '') ?><br>
        <?= e($S['seller.ids'] ?? '') ?>
      </p>
      <?php endif; ?>
      <?php // L'ADRESSE DES RETOURS, à côté des données de la société : tpay
            // l'exige ici, en bas de chaque page. Même source que le
            // règlement et la page « Zwroty » (zwrot_adres). ?>
      <?php $zw = zwrot_adres($S); if ($zw['linie']): ?>
      <p class="seller seller--zwrot">
        <span class="seller-label"><?= e($S['returns.address'] ?? '') ?></span>
        <?php if ($zw['odbiorca'] !== ''): ?><?= e($zw['odbiorca']) ?><br><?php endif; ?>
        <?= e(implode(', ', $zw['linie'])) ?><br>
        <a href="<?= e(u('zwroty')) ?>"><?= e($S['returns.footer_link'] ?? '') ?></a>
      </p>
      <?php endif; ?>
      <p class="foot-copy"><?= e(date('Y')) ?> · <?= e($S['brand'] ?? '') ?> · <?= e($S['footer.rights'] ?? '') ?></p>
    </div>
    <nav class="foot-links" aria-label="<?= e($S['footer.contact'] ?? '') ?>">
      <?php // L'ORDRE VIENT D'UKŁAD STRONY, mais deux liens n'y ont pas
            // d'interrupteur : le règlement et la politique de confidentialité.
            // « W ŁATWO DOSTĘPNYM MIEJSCU », mot pour mot ce que demande
            // l'opérateur de paiement — et la loi avant lui. Le pied de page
            // est le seul endroit présent sur TOUTES les pages, y compris sur
            // téléphone où la barre du haut disparaît — d'où aussi le suivi
            // de commande ici, et pas seulement en haut.
            foreach (liens_uklad(wsm_pdo(), $S, $lang, 'footer') as $l): ?>
      <a href="<?= e($l['href']) ?>"><?= e($l['label']) ?></a>
      <?php endforeach; ?>
      <?= ads_footer_link($S) ?>
    </nav>
  </div>
</footer>
<?= ads_banner($S) ?>
<script src="<?= e(asset('shop.js')) ?>" defer></script>
</body>
</html>
<?php
}

/** Le texte exact des deux cases, tel qu'affiché : la preuve de l'accord. */
function nl_textes_zgody(array $S): array {
    $marque = (string) ($S['brand'] ?? '');
    return [str_replace('{sklep}', $marque, (string) ($S['nl.consent_email'] ?? '')),
            str_replace('{sklep}', $marque, (string) ($S['nl.consent_sms'] ?? ''))];
}

/**
 * LE FORMULAIRE D'INSCRIPTION (newsletter, et SMS en option).
 *
 * Deux cases, jamais cochées d'avance : l'accord se donne (art. 398 Prawo
 * komunikacji elektronicznej). Le texte exact des cases est la PREUVE de
 * l'accord : le serveur le recompose (nl_textes_zgody), il ne vient pas du
 * navigateur. La case SMS n'apparaît que si SMSAPI marche : le numéro se
 * confirme par un lien reçu par SMS. Même piège et même horodatage signé que
 * le formulaire de contact.
 */
function nl_form(array $S, array $v = [], array $err = []): void {
    global $WSM_API_DIR;
    if (!function_exists('wsm_contact_stamp')) require_once $WSM_API_DIR . '/contact.php';
    [$zNl, $zSms] = nl_textes_zgody($S);
    if (!function_exists('wsm_sms_enabled')) { require_once $WSM_API_DIR . '/marketing.php'; require_once $WSM_API_DIR . '/smsapi.php'; }
    $sms = wsm_sms_enabled();
    $id = 'nl' . substr(md5((string) mt_rand()), 0, 4);
    ?>
    <form class="nl-form" method="post" action="<?= e(u('newsletter')) ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="_ts" value="<?= e(wsm_contact_stamp()) ?>">
      <div class="hp" aria-hidden="true"><label for="<?= $id ?>-w">Nie wypełniaj tego pola</label>
        <input type="text" id="<?= $id ?>-w" name="firma_www" tabindex="-1" autocomplete="off" value=""></div>
      <div class="nl-row">
        <label class="sr-only" for="<?= $id ?>-e"><?= e($S['nl.email'] ?? '') ?></label>
        <input id="<?= $id ?>-e" name="email" type="email" required maxlength="200" autocomplete="email"
               placeholder="<?= e($S['nl.email'] ?? '') ?>" value="<?= e((string) ($v['email'] ?? '')) ?>"<?= isset($err['email']) ? ' aria-invalid="true"' : '' ?>>
        <button class="btn btn--brand" type="submit"><?= e($S['nl.submit'] ?? '') ?></button>
      </div>
      <label class="nl-check"><input type="checkbox" name="zgoda_nl" value="1"<?= !empty($v['zgoda_nl']) ? ' checked' : '' ?>>
        <span><?= e($zNl) ?></span></label>
      <?php if ($sms): ?>
      <label class="nl-check"><input type="checkbox" name="zgoda_sms" value="1"<?= !empty($v['zgoda_sms']) ? ' checked' : '' ?>>
        <span><?= e($zSms) ?></span></label>
      <div class="nl-tel">
        <label for="<?= $id ?>-t"><?= e($S['nl.phone'] ?? '') ?></label>
        <input id="<?= $id ?>-t" name="phone" type="tel" maxlength="20" autocomplete="tel" inputmode="tel"
               placeholder="600 100 200" value="<?= e((string) ($v['phone'] ?? '')) ?>"<?= isset($err['phone']) ? ' aria-invalid="true"' : '' ?>>
      </div>
      <?php endif; ?>
      <?php foreach ($err as $m): ?><small class="err"><?= e($m) ?></small><?php endforeach; ?>
    </form>
    <?php
}

/** Bandeau d'erreur ou de succès, réutilisé par toutes les pages. */
function notice(string $kind, string $text): void {
    if ($text === '') return;
    echo '<p class="notice notice--' . e($kind) . '">' . e($text) . '</p>';
}
