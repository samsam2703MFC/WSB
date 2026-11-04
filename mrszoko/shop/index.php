<?php
// ============================================================================
//  index.php — contrôleur de la boutique Mister Szoko.
//
//  Cinq pages, toutes rendues côté serveur depuis la base :
//    /                      catalogue
//    /p/<slug>              fiche produit
//    /koszyk                panier
//    /kasa                  caisse
//    /zamowienie/<code>     confirmation et suivi
//
//  Les mutations du panier et la commande passent par de vrais formulaires
//  POST : la boutique reste utilisable si le JavaScript ne charge pas.
// ============================================================================
declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/seo.php';
require __DIR__ . '/layout.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
// Les pages portent le panier et l'adresse de l'acheteur : jamais de cache partagé.
header('Cache-Control: private, no-cache, must-revalidate');

try {
    $pdo = wsm_bootstrap();
} catch (Throwable $ex) {
    http_response_code(503);
    exit('Sklep chwilowo niedostępny.');
}

// ---- Langue ----------------------------------------------------------------
//  L'ordre vient du registre des langues (i18n.php), pas d'une liste écrite
//  ici : à trois langues une constante passait, à huit elle laisse tomber les
//  cinq qu'elle ne connaît pas au même rang.
$langs = wsm_shop_available_langs($pdo);
$lang  = pick_lang($pdo);

if (isset($_GET['lang'])) {
    // ON NE REDIRIGE PLUS. Auparavant « ?lang=en » posait un cookie puis
    // renvoyait en 302 vers la même adresse sans la langue : la langue ne
    // vivait que dans le cookie, et « ?lang=en » n'était donc pas une page.
    //
    // Trois conséquences, toutes invisibles depuis un navigateur :
    //  • toute la grappe hreflang pointait sur des adresses qui redirigeaient
    //    vers UNE seule page — un moteur en conclut qu'il n'y a qu'une page,
    //    et le multilingue ne référence rien du tout ;
    //  • le sitemap listait les mêmes redirections ;
    //  • un lien « ?lang=en » partagé affichait au destinataire la langue de
    //    SON cookie, pas celle qu'on avait voulu montrer.
    //
    // La page se rend donc à son adresse, en 200. Le cookie garde le choix
    // pour la navigation suivante, et « ?lang=pl » — qui ferait doublon avec
    // « / » — est ramené sur « / » par la balise canonique.
    remember_lang($lang);
}
$S = wsm_shop_strings($pdo, $lang);

// ---- Jeton anti-CSRF -------------------------------------------------------
function csrf_token(): string {
    static $t = null;
    if ($t !== null) return $t;
    $t = (string) ($_COOKIE['ms_csrf'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $t)) {
        $t = bin2hex(random_bytes(16));
        setcookie('ms_csrf', $t, ['expires' => time() + 86400, 'path' => shop_base() . '/',
            'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
    }
    return $t;
}
function csrf_field(): string {
    return '<input type="hidden" name="_t" value="' . e(csrf_token()) . '">';
}
function csrf_ok(): bool {
    $sent = (string) ($_POST['_t'] ?? '');
    $have = (string) ($_COOKIE['ms_csrf'] ?? '');
    return $sent !== '' && $have !== '' && hash_equals($have, $sent);
}

// Le jeton doit être émis AVANT le moindre octet de page : setcookie() écrit
// un en-tête, et un en-tête ne s'ajoute plus une fois le corps commencé.
// Appelé ici, il vaut pour tous les formulaires rendus plus bas.
csrf_token();

$seg    = route_segments();
$page   = $seg[0] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cart   = cart_read();

// ---- Lien direct tracé : ?l=xxxxxx -----------------------------------------
//  Un lien partagé doit pouvoir DIRE ce qu'il a rapporté, sinon on reconduit
//  une campagne sans savoir si elle a vendu. Le lien peut aussi poser le code
//  de réduction annoncé dans le message : sans ça, le visiteur cherche où le
//  taper et referme l'onglet.
//
//  AUCUN PISTAGE : pas de cookie d'identification, pas d'adresse IP. On note
//  la SOURCE sur la commande, comme « vu en vitrine » sur un ticket.
if (isset($_GET[WSM_LINK_PARAM_PUB]) && $method === 'GET') {
    $lf = $WSM_API_DIR . '/links.php';
    if (is_file($lf)) {
        require_once $lf;
        $lien = wsm_link_find($pdo, (string) $_GET[WSM_LINK_PARAM_PUB]);
        if ($lien) {
            wsm_link_hit($pdo, (string) $lien['code']);
            source_write((string) $lien['code']);
            if (trim((string) $lien['kod']) !== '') voucher_write((string) $lien['kod']);
            if ((string) $lien['cible'] === 'koszyk' && trim((string) $lien['produkt']) !== '') {
                $cart[(string) $lien['produkt']] = min(WSM_SHOP_MAX_QTY,
                    ($cart[(string) $lien['produkt']] ?? 0) + 1);
                cart_write($cart);
                redirect(u('koszyk'));
            }
            if ((string) $lien['cible'] === 'produkt' && trim((string) $lien['produkt']) !== '') {
                $pr = wsm_shop_product($pdo, (string) $lien['produkt'], $lang);
                if ($pr) redirect(u('p/' . $pr['slug']));
            }
            // UN LIEN « SKLEP » POSÉ SUR UNE AUTRE PAGE Y RESTE. La newsletter
            // met ?l=nl12 sur la fiche d'un produit : renvoyer à l'accueil
            // ferait perdre au client le produit sur lequel il a cliqué. On
            // garde le chemin demandé (le nôtre, jamais une adresse tierce) et
            // on retire seulement le paramètre.
            $ici = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
            if ($ici !== '' && str_starts_with($ici, '/') && !str_starts_with($ici, '//') && rtrim($ici, '/') !== rtrim((string) parse_url(u(), PHP_URL_PATH), '/')) {
                $reste = $_GET;
                unset($reste[WSM_LINK_PARAM_PUB]);
                redirect($ici . ($reste ? '?' . http_build_query($reste) : ''));
            }
            redirect(u());
        }
        // Un code inconnu ne casse rien : on sert la boutique, sans source.
    }
}

// ==================== CE QUE LES MOTEURS VONT CHERCHER =======================
//  Servis avant tout le reste : ce sont des fichiers, pas des pages — ils
//  n'ont ni en-tête, ni panier, ni session à charger.
if ($page === 'robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo seo_robots_txt();
    exit;
}

if ($page === 'sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    // Le catalogue, puis chaque produit visible. Les pages personnelles n'y
    // sont pas : elles portent un `noindex`, et les lister ici reviendrait à
    // signaler leur existence à un robot pour lui demander de les ignorer.
    $urls = [['path' => '/', 'priority' => '1.0'],
             ['path' => '/kontakt', 'priority' => '0.5'],
             ['path' => '/zwroty', 'priority' => '0.4']];
    foreach (wsm_shop_products($pdo, $lang) as $prod) {
        if (($prod['slug'] ?? '') === '') continue;
        $urls[] = ['path' => '/p/' . $prod['slug'], 'priority' => '0.8'];
    }
    // Les pages publiées depuis Strony : indexables, donc listées.
    if (function_exists('wsm_page_sitemap')) {
        foreach (wsm_page_sitemap($pdo) as $sl) $urls[] = ['path' => '/' . $sl, 'priority' => '0.6'];
    }
    // Les formulaires actifs (hurt, próbki, warsztaty…) : ce sont des pages
    // d'entrée pour les professionnels qui cherchent un fournisseur.
    if (is_file($WSM_API_DIR . '/formularze.php')) {
        require_once $WSM_API_DIR . '/formularze.php';
        foreach (wsm_forms_sitemap($pdo) as $sl) $urls[] = ['path' => '/f/' . $sl, 'priority' => '0.6'];
    }
    echo seo_sitemap($urls, $langs, WSM_SHOP_DEFAULT_LANG);
    exit;
}

// LE FLUX PRODUITS — Google Merchant Center (onglet Zakupy) et catalogue Meta
// (boutique Facebook et Instagram) le relisent chaque jour à cette adresse.
// Même source que les pages : le prix du flux est le prix affiché.
if ($page === 'feed' && ($seg[1] ?? '') === 'produkty.xml') {
    require_once $WSM_API_DIR . '/mediateka.php';      // versions JPEG des photos
    $base = seo_origin() !== '' ? seo_origin() . shop_base() : wsm_shop_base_url();
    $fl = in_array((string) ($_GET['lang'] ?? ''), $langs, true) ? (string) $_GET['lang'] : WSM_SHOP_DEFAULT_LANG;
    $feed = wsm_feed_produkty($pdo, $fl, $base);
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=1800');
    // Un fichier pour les robots des places de marché, pas une page à
    // indexer : il dupliquerait chaque fiche produit dans les résultats.
    header('X-Robots-Tag: noindex');
    echo wsm_feed_xml($feed['items'], (string) ($S['brand'] ?? 'Szoko Shop'), $base . '/');
    exit;
}

/**
 * Le bloc « może się przydać ».
 *
 * CHAQUE CARTE PORTE SA SOURCE : « często kupowane razem » n'est écrit que
 * sous des produits réellement vus dans les mêmes commandes payées. Le repli
 * dit « z tej samej półki », ce qui est exact et n'invente aucune
 * statistique. Une suggestion est une affirmation ; une affirmation fausse
 * décrédibilise toute la page le jour où quelqu'un s'en aperçoit.
 */
function upsell_block(PDO $pdo, array $ids, string $lang, array $S): void {
    global $WSM_API_DIR;
    $f = $WSM_API_DIR . '/upsell.php';
    if (!is_file($f)) return;
    require_once $f;
    $sug = wsm_upsell_for($pdo, $ids, $lang);
    if (!$sug) return;                       // rien d'honnête à proposer : rien
    ?>
  <section class="upsell">
    <h2><?= e($S['upsell.title'] ?? '') ?></h2>
    <div class="grid">
      <?php foreach ($sug as $x): $p = $x['product']; ?>
      <article class="card">
        <a class="card-media" href="<?= e(u('p/' . $p['slug'])) ?>">
          <?= product_visual($p, 'card-photo') ?>
        </a>
        <div class="card-body">
          <p class="card-meta mono"><?= e($S[wsm_upsell_cle($x['source'])] ?? '') ?></p>
          <h3><a href="<?= e(u('p/' . $p['slug'])) ?>"><?= e($p['name']) ?></a></h3>
          <div class="card-buy">
            <span class="price"><?= e(zl($p['price'])) ?><small><?= e($S['price.vat_incl'] ?? '') ?></small></span><?php
              // LE PRIX AU KILO, EN PETIT. La même chocolat existe en 1 kg et
              // en 3 kg : 64,90 et 169,90 ne se comparent pas de tête, et c'est
              // le moment où le client décide. Rien ne s'affiche quand le poids
              // manque — « 0,00 zł/kg » se lirait comme une gratuité.
              if (!empty($p['price_per_kg'])): ?><span class="za-kg mono"><?= e(zl($p['price_per_kg'])) ?><?= e($S['price.per_kg'] ?? '') ?></span><?php endif; ?>
            <form method="post" action="<?= e(u('koszyk')) ?>" data-add data-cena="<?= (int) $p['price'] ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="add" value="<?= e($p['id']) ?>">
              <input type="hidden" name="qty" value="1">
              <button class="btn btn--brand btn--sm" type="submit"><?= e($S['product.add'] ?? '') ?></button>
            </form>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
    <?php
}

// ---- Désabonnement : ?stop=<adresse>&t=<jeton> ------------------------------
//  EN UN CLIC, SANS FORMULAIRE ET SANS CONNEXION. Un désabonnement qui exige
//  de retrouver un mot de passe n'est pas un désabonnement : c'est un
//  signalement pour courrier indésirable, et celui-là coûte la réputation du
//  domaine — donc les confirmations de commande.
//
//  Le jeton est signé : sans lui, n'importe qui pourrait désabonner
//  n'importe quelle adresse en devinant un e-mail.
if (isset($_GET['stop']) && $method === 'GET') {
    $cf = $WSM_API_DIR . '/campaign.php';
    if (is_file($cf)) {
        require_once $cf;
        $adr = (string) $_GET['stop'];
        $stopOk = wsm_camp_stop_ok($adr, (string) ($_GET['t'] ?? ''));
        if ($stopOk) [$stopOk, ] = wsm_camp_stop($pdo, $adr, 'klient');
        layout_head($S, $lang, $langs, $S['stop.title'] ?? '', '', 'koszyk');  // noindex
        layout_header($S, $lang, $langs, cart_count($cart));
        echo '<main class="wrap block"><h1>' . e($S['stop.title'] ?? '') . '</h1>';
        echo '<p class="lead">' . e($stopOk ? ($S['stop.done'] ?? '') : ($S['stop.bad'] ?? '')) . '</p>';
        echo '<p><a class="btn btn--brand" href="' . e(u()) . '">' . e($S['cart.empty_cta'] ?? '') . '</a></p></main>';
        layout_footer($S);
        exit;
    }
}

// ============================ ACTIONS (POST) ================================
if ($method === 'POST') {
    if (!csrf_ok()) { http_response_code(400); exit('Bad request.'); }

    // ---- La session de paiement intégrée (demandée par la page Zapłać) ----
    // Le navigateur ne reçoit qu'un secret de session à usage unique ; la
    // commande, son montant et le compte connecté sont fixés ICI. Une
    // commande payée, annulée ou inconnue n'ouvre rien.
    if ($page === 'zaplac' && ($seg[2] ?? '') === 'sesja') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $oZ = wsm_order_by_code($pdo, (string) ($seg[1] ?? ''), (string) ($_GET['t'] ?? ''));
        if (!$oZ || $oZ['payment_status'] === 'oplacone' || $oZ['status'] === 'anulowane' || !wsm_stripe_ready()) {
            http_response_code(409);
            echo json_encode(['error' => 'unavailable']);
            exit;
        }
        $powrot = wsm_shop_base_url() . '/zamowienie/' . rawurlencode($oZ['code'])
                . '?t=' . rawurlencode((string) $oZ['access_token']) . '&session_id={CHECKOUT_SESSION_ID}';
        $rZ = wsm_stripe_checkout($pdo, $oZ, $powrot);
        if ($rZ['client_secret'] === '') {
            http_response_code(502);
            echo json_encode(['error' => 'stripe']);
            exit;
        }
        echo json_encode(['clientSecret' => $rZ['client_secret']]);
        exit;
    }

    if ($page === 'koszyk' || $page === '' || $page === 'p') {
        // LE MODE DE LIVRAISON CHOISI SURVIT À UN CHANGEMENT DE QUANTITÉ. Le
        // panier revenait sur « Paczkomat » après chaque « + » : le client qui
        // avait pris DPD voyait le prix de port changer sous ses yeux.
        $dost = (string) ($_POST['dostawa'] ?? '');
        $wrot = preg_match('/^[a-z0-9_]{1,40}$/', $dost) ? u('koszyk', ['dostawa' => $dost]) : u('koszyk');
        if (isset($_POST['add'])) {
            $id  = (string) $_POST['add'];
            // La carte de quantité cochée sur la fiche (« ile » = 1, 11, 25…),
            // sinon le champ « Inna ilość » — et partout ailleurs le seul
            // champ « qty » des cartes du catalogue. Le champ l'emporte dès
            // qu'il dit autre chose que 1 : sans JavaScript, cliquer dedans ne
            // coche pas sa carte, la carte « 1 » resterait cochée et l'on
            // ajouterait 1 au lieu des 30 tapés. Avec JavaScript, choisir une
            // carte remet le champ à 1 (shop.js).
            $ile  = (string) ($_POST['ile'] ?? '');
            $wpis = (int) ($_POST['qty'] ?? 1);
            $qty  = max(1, min(WSM_SHOP_MAX_QTY, (ctype_digit($ile) && $wpis <= 1) ? (int) $ile : $wpis));
            $cart[$id] = min(WSM_SHOP_MAX_QTY, ($cart[$id] ?? 0) + $qty);
            cart_write($cart);
            redirect(u('koszyk'));
        }
        if (isset($_POST['remove'])) {
            unset($cart[(string) $_POST['remove']]);
            cart_write($cart);
            redirect($wrot);
        }
        // Le code de réduction : posé ou retiré, puis on recharge le panier.
        // Il n'est PAS validé ici — c'est le devis qui juge, et lui seul sait
        // ce que pèse le panier au moment où on le regarde.
        if (isset($_POST['kod_set'])) {
            voucher_write((string) ($_POST['kod'] ?? ''));
            redirect($wrot);
        }
        if (isset($_POST['kod_drop'])) {
            voucher_write('');
            redirect($wrot);
        }
        // Les quantités tapées, PUIS le « − » ou le « + » cliqué : un bouton
        // ne porte qu'un pas (krok[id] = -1 | 1), jamais une quantité — la
        // quantité affichée peut avoir changé dans le champ juste avant.
        // Un produit qui n'est pas dans le panier ne s'y ajoute pas par là.
        if ((isset($_POST['set']) && is_array($_POST['set'])) || (isset($_POST['krok']) && is_array($_POST['krok']))) {
            foreach ((array) ($_POST['set'] ?? []) as $id => $q) {
                $q = (int) $q;
                if ($q <= 0) unset($cart[(string) $id]);
                else $cart[(string) $id] = min(WSM_SHOP_MAX_QTY, $q);
            }
            foreach ((array) ($_POST['krok'] ?? []) as $id => $k) {
                $id = (string) $id;
                if (!isset($cart[$id])) continue;
                $q = $cart[$id] + ((int) $k < 0 ? -1 : 1);
                if ($q <= 0) unset($cart[$id]);
                else $cart[$id] = min(WSM_SHOP_MAX_QTY, $q);
            }
            cart_write($cart);
            redirect($wrot);
        }
        redirect($wrot);
    }

    // ---- Mise en place d'un abonnement depuis la confirmation ---------------
    //  Le jeton d'accès de la commande fait foi : sans lui, connaître un
    //  numéro de commande ne suffit pas à abonner quelqu'un d'autre.
    if ($page === 'zamowienie' && isset($_POST['zgloszenie'])) {
        $oCl = wsm_order_by_code($pdo, (string) ($seg[1] ?? ''), (string) ($_GET['t'] ?? ''));
        if ($oCl) {
            require_once $WSM_API_DIR . '/claims.php';
            [$cid, $mCl] = wsm_claim_open($pdo, (int) $oCl['id'], (string) ($_POST['ctype'] ?? ''),
                                          (string) ($_POST['craison'] ?? ''), 'klient');
            if ($cid > 0) {
                $c = wsm_claim_get($pdo, $cid);
                $claimMsg = str_replace('{numer}', (string) ($c['numer'] ?? ''),
                                        $S['claim.done'] ?? $mCl);
            } else {
                $claimErr = $mCl;                 // le message dit ce qui manque
            }
        }
        // On retombe sur l'affichage du suivi, plus bas.
    }

    // ---- Remplir la facture depuis le registre ------------------------------
    //
    // POURQUOI DEUX REGISTRES. On nous a demande de remplir les champs « depuis
    // VIES ». VIES ne peut pas le faire pour un NIP polonais : il ne connait
    // que les numeros enregistres pour le commerce intracommunautaire, donc une
    // societe qui vend uniquement en Pologne n'y figure pas. On lui aurait
    // repondu « numer nieznany » — vrai pour VIES, FAUX pour le client, dont le
    // NIP est parfaitement valide.
    //
    //   · un numero de TVA UE  → VIES
    //   · un NIP polonais      → Biala lista du ministere
    //
    // SANS JAVASCRIPT : le bouton poste le formulaire, le serveur remplit ce
    // qu'il sait, et la page revient avec les cases pleines. Rien a charger,
    // rien qui echoue en silence sur un telephone qui bloque les scripts.
    if ($page === 'kasa' && isset($_POST['pobierz_dane'])) {
        $formValues = $_POST;
        $vatUE = trim((string) ($_POST['vat_eu'] ?? ''));
        $nipPl = trim((string) ($_POST['nip'] ?? ''));
        $trouve = null; $regInfo = ''; $regKind = 'warn';

        if ($vatUE !== '') {
            require_once $WSM_API_DIR . '/vies.php';
            $r = wsm_vies_check($pdo, $vatUE);
            if (($r['status'] ?? '') === 'valid') { $trouve = $r; $regInfo = $S['reg.ok_vies'] ?? ''; $regKind = 'ok'; }
            elseif (($r['status'] ?? '') === 'invalid') $regInfo = $S['reg.nieznany'] ?? '';
            else $regInfo = $S['reg.niedostepny'] ?? '';
        } elseif ($nipPl !== '') {
            require_once $WSM_API_DIR . '/mf.php';
            $r = wsm_mf_check($pdo, $nipPl);
            if (($r['status'] ?? '') === 'valid') { $trouve = $r; $regInfo = $S['reg.ok_mf'] ?? ''; $regKind = 'ok'; }
            elseif (($r['status'] ?? '') === 'invalid') $regInfo = $S['reg.nieznany'] ?? '';
            else $regInfo = $S['reg.niedostepny'] ?? '';
        } else {
            $regInfo = $S['reg.podaj'] ?? '';
        }

        if ($trouve) {
            // ON NE REMPLIT QUE CE QUI EST VIDE. Ecraser ce que quelqu'un vient
            // de taper, c'est lui reprendre son travail sans le prevenir — et
            // le registre n'a pas toujours raison : une societe demenagee met
            // des semaines a y apparaitre a sa nouvelle adresse.
            if (trim((string) ($formValues['company'] ?? '')) === '' && ($trouve['name'] ?? '') !== '') {
                $formValues['company'] = (string) $trouve['name'];
            }
            require_once $WSM_API_DIR . '/mf.php';
            $ad = wsm_mf_adresse((string) ($trouve['address'] ?? ''));
            foreach (['street' => 'bill_street', 'building' => 'bill_building',
                      'postcode' => 'bill_postcode', 'city' => 'bill_city'] as $k => $champ) {
                if ($ad[$k] !== '' && trim((string) ($formValues[$champ] ?? '')) === '') {
                    $formValues[$champ] = $ad[$k];
                }
            }
            $formValues['invoice'] = '1';           // on vient de demander une facture
        }
        // on retombe sur l'affichage de la caisse, plus bas
    }

    // ---- Passage de commande ----------------------------------------------
    if ($page === 'kasa' && !isset($_POST['pobierz_dane'])) {
        $body = $_POST;
        $body['items'] = cart_items($cart);
        $body['lang']  = $lang;
        $body['invoice'] = !empty($_POST['invoice']);
        $body['consent_terms'] = !empty($_POST['consent_terms']);
        // L'accord e-paragon n'existe que si la case a été MONTRÉE (champ
        // témoin) : une case décochée n'envoie rien, et rien ne doit valoir
        // « pas demandé » quand c'est un « non ».
        $body['e_paragon'] = isset($_POST['e_paragon_pole']) ? !empty($_POST['e_paragon']) : null;
        // Le code vient du cookie, jamais d'un champ caché : un champ caché
        // est modifiable dans le navigateur, et le cookie est de toute façon
        // revalidé en base à la création de la commande.
        $body['voucher'] = voucher_read();
        // La source suit la commande, figée. Sans elle, un lien partagé ne
        // peut jamais dire ce qu'il a rapporté.
        $body['source'] = source_read();

        [$order, $errors] = wsm_shop_create_order($pdo, $body);
        if ($errors) {
            $formErrors = $errors;
            $formValues = $_POST;
            // on retombe sur l'affichage de la caisse, plus bas
        } else {
            cart_write([]);
            // L'ACCORD COOKIES TEL QU'IL ÉTAIT EN COMMANDANT. C'est lui, et lui
            // seul, qui autorisera l'envoi de l'achat à Meta par le serveur
            // une fois la commande payée (reklama.php).
            if (function_exists('wsm_ads_order_capture')) {
                try {
                    require_once $WSM_API_DIR . '/contact.php';      // wsm_contact_ip()
                    wsm_ads_order_capture($pdo, (int) ($order['id'] ?? 0), wsm_zgody_odczyt(), $_COOKIE, wsm_contact_ip(),
                                          (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), seo_url('/kasa', $lang, WSM_SHOP_DEFAULT_LANG));
                } catch (Throwable $e) { /* la commande d'abord */ }
            }
            // Les cases marketing de la caisse : inscription avec la preuve
            // (texte exact affiché). Ne fait jamais échouer la commande.
            if (!empty($_POST['zgoda_nl']) || !empty($_POST['zgoda_sms'])) {
                try {
                    require_once $WSM_API_DIR . '/marketing.php';
                    require_once $WSM_API_DIR . '/contact.php';
                    wsm_mkt_subscribe($pdo, [
                        'email' => (string) ($order['email'] ?? ($_POST['email'] ?? '')), 'phone' => (string) ($_POST['phone'] ?? ''),
                        'name' => trim((string) ($_POST['first_name'] ?? '')), 'lang' => $lang, 'source' => 'kasa',
                        'newsletter' => !empty($_POST['zgoda_nl']),
                        'sms' => !empty($_POST['zgoda_sms']) && wsm_mkt_phone((string) ($_POST['phone'] ?? '')) !== '',
                        'ip' => wsm_contact_ip(),
                        'tekst_email' => (string) ($S['checkout.nl_optin'] ?? ''), 'tekst_sms' => (string) ($S['checkout.sms_optin'] ?? ''),
                    ]);
                } catch (Throwable $e) { /* la commande d'abord ; l'inscription se refait */ }
            }
            // Le code part avec le panier : il vient d'être consommé, et le
            // laisser traîner le ferait re-tenter — puis refuser — sur la
            // commande suivante, ce qui ressemblerait à une panne.
            voucher_write('');
            // LE PAIEMENT SE FAIT CHEZ NOUS : la page « Zapłać » porte le
            // formulaire Stripe intégré — pas de redirection vers un site tiers.
            // Sans Stripe prêt, la commande existe quand même ; la tentative
            // est tracée « niedostepne » et sa page le dit.
            $qZ = ['t' => $order['access_token']];
            if (wsm_stripe_ready()) redirect(u('zaplac/' . rawurlencode($order['code']), $qZ));
            wsm_stripe_checkout($pdo, $order, '');
            redirect(u('zamowienie/' . rawurlencode($order['code']), $qZ));
        }
    }
}

// ============================== RENDU (GET) =================================
$cartCount = cart_count($cart);

// ---------------------------------------------------------------- CATALOGUE -
if ($page === '') {
    // ─── LE RAYON DEMANDÉ ────────────────────────────────────────────────────
    //
    // Les catégories viennent de la BASE, pas d'une liste écrite dans le code :
    // celles qu'on ajoute dans la console apparaissent ici sans qu'on redéploie.
    // Un identifiant inconnu — lien périmé, catégorie désactivée depuis — ne
    // renvoie pas le catalogue entier sous un titre de rayon : il ramène
    // simplement à « tout », ce qui est vrai et ne fait acheter personne à côté.
    $kategorie = wsm_shop_categories($pdo);
    $katId = isset($_GET['k']) ? (int) $_GET['k'] : 0;
    if ($katId > 0 && !in_array($katId, array_map(fn($c) => (int) $c['id'], $kategorie), true)) {
        $katId = 0;
    }
    $products = wsm_shop_products($pdo, $lang, $katId > 0 ? $katId : null);
    layout_head($S, $lang, $langs, '', '', '');
    layout_header($S, $lang, $langs, $cartCount);
    // La carte d'identité du vendeur, une seule fois, sur la page d'entrée.
    seo_org($S, $lang, WSM_SHOP_DEFAULT_LANG);
    ?>
<main>
<?php // L'ACCUEIL SE LIT DANS L'ORDRE D'UKŁAD STRONY (layout.php) : chaque entrée
      // est une section intégrée ou un bloc écrit dans Strony. Une section
      // cachée laisse un commentaire à sa place — un contrôle qui ne la trouve
      // pas doit pouvoir distinguer « voulu » de « cassé ». Sans layout.php
      // (assemblage incomplet), l'ordre d'origine, tout visible.
      $uklad = function_exists('wsm_layout_home') ? wsm_layout_home($pdo) : [];
      if (!$uklad) $uklad = array_map(fn($k) => ['k' => $k, 'type' => 'builtin', 'id' => 0, 'on' => 1],
                                      ['hero', 'pasek', 'obietnice', 'katalog', 'pro', 'kontakt']);
      $pasekOn = true;
      foreach ($uklad as $it) if ($it['k'] === 'pasek') $pasekOn = (bool) $it['on'];
      // Le nagłówek natif caché, le premier nagłówek du Kreator porte le h1.
      $heroOn = true; foreach ($uklad as $it) if ($it['k'] === 'hero') $heroOn = (bool) $it['on'];
      foreach ($uklad as $it): $typ = (string) $it['k'];
          if ($typ === 'pasek') continue;                                      // rendu dans le hero
          if ($it['type'] === 'blok') { if ($it['on']) blok_html($pdo, $lang, (int) $it['id']); continue; }
          if ($it['type'] === 'sekcja') { if ($it['on']) sekcja_id_html($pdo, $lang, (int) $it['id'], ['h1' => !$heroOn]); continue; }
          if (!$it['on']) { echo '  ' . wsm_layout_marker($typ) . "\n"; continue; } ?>
  <?php if ($typ === 'hero'): ?>
  <?php // LA PHOTO DU HERO, si elle est posée dans Ustawienia.
        //
        // Le voile n'est pas décoratif : le titre est blanc, et une photo
        // claire le rendrait illisible. Il est posé EN CSS par-dessus l'image,
        // pas incrusté dans le fichier, pour qu'on puisse changer la photo
        // sans refaire le montage. Sans photo, le dégradé d'origine reste —
        // la page ne dépend pas d'un fichier qui peut manquer.
        $heroImg = trim((string) (wsm_config()['shop']['hero_image'] ?? ''));
        if ($heroImg === '' || strtolower($heroImg) === 'xxxx') $heroImg = ''; ?>
  <?php // Sans photo, une illustration (assets/hero-art.svg) : des pastilles
        // de chocolat à droite du titre. « Jest pusto » disait l'équipe — un
        // seul aplat de couleur, sans image. La photo déposée la remplace.
        //
        // LES SLAJDY passent avant les deux (console → Slajdy) : le boulanger
        // de la maquette, puis les nouvelles datées (« rabaty świąteczne od…
        // do… »). Sans slajd en ligne aujourd'hui, ce haut de page d'origine
        // revient tout seul — jamais de bandeau vide.
        $slajdy = function_exists('wsm_slajdy_widoczne') ? wsm_slajdy_widoczne($pdo, $lang) : [];
        $slUst  = $slajdy ? wsm_slajdy_ustawienia($pdo) : ['auto' => false, 'co' => 5]; ?>
  <section class="hero<?= $slajdy ? ' hero--slajdy' : ($heroImg !== '' ? ' hero--foto' : ' hero--art') ?>"<?= !$slajdy && $heroImg !== ''
      ? ' style="--hero-foto:url(' . e($heroImg) . ')"' : '' ?>>
    <?php // LE BANDEAU DU HAUT, juste sous la barre de navigation — là où
          // l'équipe l'a entouré : la livraison gratuite (le seuil que la
          // caisse applique, pas un chiffre recopié) et l'heure limite
          // d'expédition (promise.1.d, la MÊME phrase que la promesse
          // « Wysyłka w 24 h » : une heure, un seul texte). Il remplace le
          // bandeau du bas et ses trois slogans, retirés à sa demande.
          // Układ strony le cache ; caché, il laisse son marqueur.
          if ($pasekOn):
            $darm = z_progiem((string) ($S['info.darmowa'] ?? ''), prog_darmowej($pdo));
            $wys  = trim((string) ($S['promise.1.d'] ?? ''));
            if ($darm !== '' || $wys !== ''): ?>
    <div class="hero-strip hero-strip--gora">
      <div class="wrap hero-strip-in">
        <?php if ($darm !== ''): ?><span class="korzysc"><?= function_exists('ikona_korzysci') ? ikona_korzysci('bus') : '' ?><span><?= e($darm) ?></span></span><?php endif; ?>
        <?php if ($wys !== ''): ?><span class="korzysc"><?= function_exists('ikona_korzysci') ? ikona_korzysci('zegar') : '' ?><span><?= e($wys) ?></span></span><?php endif; ?>
      </div>
    </div>
    <?php // Ni seuil dans Dostawa ni heure dans Treści : rien à dire, mais
          // la page le dit — un contrôle distingue « vide » de « perdu ».
          else: echo "    <!-- pasek: pusty, brak progu i godziny wysyłki -->\n"; endif;
          else: echo '    ' . wsm_layout_marker('pasek') . "\n"; endif; ?>
    <?php if ($slajdy): $ile = count($slajdy); ?>
    <?php // UN SEUL h1 : celui du premier slajd. Les suivants portent un h2 de
          // même allure — un titre par slajd, mais une seule page.
          // Sans JavaScript, seul le premier se montre (les autres sont
          // `hidden`) ; shop.js les révèle et les fait tourner. ?>
    <div class="slajdy" data-slajdy data-auto="<?= $slUst['auto'] && $ile > 1 ? '1' : '0' ?>" data-co="<?= (int) $slUst['co'] * 1000 ?>"
         aria-roledescription="carousel" aria-label="<?= e($S['home.title'] ?? '') ?>">
      <div class="slajdy-tor">
        <?php foreach ($slajdy as $i => $sl):
          $maTekst = ($sl['eyebrow'] . $sl['title'] . $sl['tekst']) !== '';
          $href = slajd_href($sl['link']);
          $nr = str_replace('{n}', (string) ($i + 1), $S['hero.slide'] ?? 'Slajd {n}') . ' / ' . $ile; ?>
        <div class="slajd<?= $i === 0 ? ' is-on' : '' ?><?= $maTekst ? '' : ' slajd--obraz' ?><?= $sl['image'] === '' ? ' slajd--bez-zdjecia' : '' ?>"
             role="group" aria-roledescription="slide" aria-label="<?= e($nr) ?>" data-slajd<?= $i > 0 ? ' hidden' : '' ?>>
          <?php if ($sl['image'] !== ''): ?>
          <img class="slajd-foto" src="<?= e(media_src($sl['image'])) ?>" alt=""<?= $i === 0 ? ' fetchpriority="high"' : ' loading="lazy"' ?> decoding="async">
          <?php endif; ?>
          <?php if ($maTekst): ?>
          <div class="wrap hero-in">
            <?php if ($sl['eyebrow'] !== ''): ?><p class="eyebrow"><?= e($sl['eyebrow']) ?></p><?php endif; ?>
            <?php if ($sl['title'] !== ''): ?><<?= $i === 0 ? 'h1' : 'h2' ?> class="slajd-h"><?= e($sl['title']) ?></<?= $i === 0 ? 'h1' : 'h2' ?>><?php endif; ?>
            <?php if ($sl['tekst'] !== ''): ?><p class="lead"><?= nl2br(e($sl['tekst']), false) ?></p><?php endif; ?>
            <?php if ($sl['cta'] !== ''): ?><a class="btn btn--accent" href="<?= e($href !== '' ? $href : '#katalog') ?>"><?= e($sl['cta']) ?></a><?php endif; ?>
          </div>
          <?php elseif ($href !== ''): ?>
          <a class="slajd-link" href="<?= e($href) ?>" aria-label="<?= e($nr) ?>"></a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($ile > 1): ?>
      <div class="wrap slajdy-nav" data-slajdy-nav hidden>
        <button class="slajdy-btn" type="button" data-slajdy-wstecz aria-label="<?= e($S['hero.prev'] ?? '') ?>"><?= ikona_ui('wstecz') ?></button>
        <span class="slajdy-kropki">
          <?php for ($i = 0; $i < $ile; $i++): ?>
          <button class="slajdy-kropka" type="button" data-slajdy-idz="<?= $i ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>
                  aria-label="<?= e(str_replace('{n}', (string) ($i + 1), $S['hero.slide'] ?? 'Slajd {n}')) ?>"></button>
          <?php endfor; ?>
        </span>
        <button class="slajdy-btn" type="button" data-slajdy-dalej aria-label="<?= e($S['hero.next'] ?? '') ?>"><?= ikona_ui('dalej') ?></button>
        <?php if ($slUst['auto']): ?>
        <button class="slajdy-btn" type="button" data-slajdy-pauza aria-label="<?= e($S['hero.pause'] ?? '') ?>"
                data-t-pauza="<?= e($S['hero.pause'] ?? '') ?>" data-t-graj="<?= e($S['hero.play'] ?? '') ?>"><?= ikona_ui('pauza') ?><?= ikona_ui('graj') ?></button>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="wrap hero-in">
      <p class="eyebrow"><?= e($S['home.eyebrow'] ?? '') ?></p>
      <h1><?= e($S['home.title'] ?? '') ?></h1>
      <p class="lead"><?= e($S['home.lead'] ?? '') ?></p>
      <a class="btn btn--accent" href="#katalog"><?= e($S['home.cta'] ?? '') ?></a>
    </div>
    <?php endif; ?>
  </section>
  <?php elseif ($typ === 'obietnice'): ?>
  <?php // « Dlaczego Szoko Shop. » : la bande grise des pages Apple, les trois
        // promesses en cartes blanches dessous. ?>
  <section class="obietnice-pas" aria-labelledby="obietnice-h">
  <div class="wrap">
  <h2 class="tytul-2" id="obietnice-h"><?= e($S['promise.title'] ?? '') ?> <span><?= e($S['promise.sub'] ?? '') ?></span></h2>
  <div class="promises">
    <?php // L'ICÔNE D'UNE PROMESSE, si elle est déposée dans Ustawienia → Sklep.
          // Même règle que la photo du hero : absente, le bloc reste du texte.
          // La page ne dépend pas d'un fichier qui peut manquer, et « xxxx »
          // (le masque d'un réglage vidé) n'est pas une adresse.
          $cfgSklep = wsm_config()['shop'] ?? [];
          for ($i = 1; $i <= 3; $i++): if (!isset($S["promise.$i.t"])) continue;
          $ikona = trim((string) ($cfgSklep["promise_icon_$i"] ?? ''));
          if (preg_match('/^x{2,}$/i', $ikona)) $ikona = ''; ?>
    <?php // Sans icône déposée, le dessin de la maison (znaki.php) : camion,
          // colis, thermomètre — comme sur la maquette de l'équipe. ?>
    <?php $rys = $ikona === '' && function_exists('ikona_obietnicy') ? ikona_obietnicy($i) : ''; ?>
    <div class="promise<?= $ikona !== '' || $rys !== '' ? ' promise--ikona' : '' ?>">
      <?php if ($ikona !== ''): ?>
      <img class="promise-ikona" src="<?= e(media_src($ikona)) ?>" alt="" width="56" height="56" loading="lazy" decoding="async">
      <?php elseif ($rys !== ''): ?>
      <span class="promise-ikona promise-ikona--rys"><?= $rys ?></span>
      <?php endif; ?>
      <div class="promise-txt">
        <h2><?= e($S["promise.$i.t"]) ?></h2>
        <p><?= e(z_progiem((string) ($S["promise.$i.d"] ?? ''), prog_darmowej($pdo))) ?></p>
      </div>
    </div>
    <?php endfor; ?>
  </div>
  </div>
  </section>
  <?php elseif ($typ === 'katalog'): ?>
  <section class="wrap block" id="katalog">
    <?php // LE TITRE SUIT LE RAYON. « Nasze produkty » pour tout le catalogue ;
          // le nom de la catégorie quand un onglet est choisi — « Mąki »
          // au-dessus des farines, pas un titre de chocolaterie. ?>
    <?php $katNom = ''; foreach ($kategorie as $c) if ((int) $c['id'] === $katId) $katNom = (string) $c['name']; ?>
    <div class="section-head">
      <h2><?= e($katNom !== '' ? $katNom : ($S['catalog.title'] ?? '')) ?></h2>
      <?php if ($katNom === ''): ?><p class="sub"><?= e($S['catalog.sub'] ?? '') ?></p><?php endif; ?>
    </div>
    <?php // UN RAYON PAR CATÉGORIE, s'il y en a plus d'une à montrer. Avec une
          // seule, le filtre ne filtre rien : il ferait cliquer pour rien.
          // Des liens, pas du JavaScript — ils marchent partout, se partagent,
          // et le navigateur sait déjà les mettre en signet. ?>
    <?php if (count($kategorie) > 1): ?>
    <nav class="rayons" aria-label="<?= e($S['catalog.title'] ?? '') ?>">
      <a href="<?= e(u()) ?>#katalog"<?= $katId === 0 ? ' class="on" aria-current="true"' : '' ?>><?= e($S['catalog.all'] ?? 'Wszystkie') ?></a>
      <?php foreach ($kategorie as $c): ?>
      <a href="<?= e(u('', ['k' => (int) $c['id']])) ?>#katalog"<?= $katId === (int) $c['id'] ? ' class="on" aria-current="true"' : '' ?>>
        <?= e((string) $c['name']) ?> <i><?= (int) $c['ile'] ?></i>
      </a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <?php if (!$products): ?>
      <p class="muted"><?= e($S['catalog.empty'] ?? '') ?></p>
    <?php else: ?>
    <div class="grid">
      <?php foreach ($products as $p): ?>
      <article class="card">
        <a class="card-media" href="<?= e(u('p/' . $p['slug'])) ?>">
          <?= product_visual($p, 'card-photo') ?>
          <?php if ($p['badge'] !== ''): ?><span class="badge"><?= e($p['badge']) ?></span><?php endif; ?>
        </a>
        <div class="card-body">
          <?php if ($p['brand']): ?><?= brand_mark($p['brand']) ?><?php endif; ?>
          <p class="card-meta mono"><?= e($p['subtitle']) ?></p>
          <h3><a href="<?= e(u('p/' . $p['slug'])) ?>"><?= e($p['name']) ?></a></h3>
          <div class="card-buy">
            <span class="price"><?= e(zl($p['price'])) ?><small><?= e($S['price.vat_incl'] ?? '') ?></small></span><?php
              // LE PRIX AU KILO, EN PETIT. La même chocolat existe en 1 kg et
              // en 3 kg : 64,90 et 169,90 ne se comparent pas de tête, et c'est
              // le moment où le client décide. Rien ne s'affiche quand le poids
              // manque — « 0,00 zł/kg » se lirait comme une gratuité.
              if (!empty($p['price_per_kg'])): ?><span class="za-kg mono"><?= e(zl($p['price_per_kg'])) ?><?= e($S['price.per_kg'] ?? '') ?></span><?php endif; ?>
            <?php // Rupture : on prend la commande quand même et on prévient.
                  // Refuser faute de stock, c'est perdre le client. ?>
            <?php if ($p['stock'] <= 0): ?><span class="mono ondemand"><?= e($S['product.on_demand'] ?? '') ?></span><?php endif; ?>
            <form method="post" action="<?= e(u('koszyk')) ?>" data-add data-cena="<?= (int) $p['price'] ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="add" value="<?= e($p['id']) ?>">
              <input type="hidden" name="qty" value="1">
              <button class="btn btn--brand btn--sm" type="submit"><?= e($S['product.add'] ?? '') ?></button>
            </form>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
  <?php elseif ($typ === 'pro' && isset($S['story.pro.title'])): ?>
  <section class="wrap block" id="pro" style="padding-top:0">
    <div class="pro">
      <div class="pro-in">
        <div>
          <p class="eyebrow"><?= e($S['story.pro.eyebrow'] ?? '') ?></p>
          <h2><?= e($S['story.pro.title']) ?></h2>
          <p><?= e($S['story.pro.text'] ?? '') ?></p>
        </div>
        <?php
        // LE FORMULAIRE, PLUS LE « mailto: ».
        //
        // Un lien mailto suppose un client de messagerie configuré sur la
        // machine. Sur un téléphone il ouvre parfois une application que
        // personne n'utilise, au bureau il ouvre Outlook chez les uns et rien
        // du tout chez les autres — et le prospect B2B qu'on vient
        // d'intéresser referme l'onglet. Le formulaire de contact existe, il
        // écrit dans la Poczta, et il ne dépend de rien chez le visiteur.
        //
        // Le sujet voyage en paramètre pour que la demande arrive déjà
        // qualifiée. Il porte un CODE de la liste (« wspolpraca »), pas une
        // phrase : la page de contact ne sait présélectionner qu'une option
        // qui existe. Un libellé libre serait ignoré en silence — le lien
        // marcherait, la demande arriverait classée « inne », et personne ne
        // verrait la différence avant de dépouiller la Poczta.
        ?>
        <a class="btn btn--accent btn--lg"
           href="<?= e(u('kontakt', ['temat' => 'wspolpraca'])) ?>">
          <?= e($S['story.pro.cta'] ?? '') ?>
        </a>
      </div>
    </div>
  </section>
  <?php elseif ($typ === 'kontakt'):
        // LE TÉLÉPHONE ET L'E-MAIL, sur la page d'accueil — et cliquables :
        // sur un téléphone, « zadzwoń » doit appeler, pas faire recopier.
        $tel = trim((string) ($S['footer.phone'] ?? '')); $mail = trim((string) ($S['footer.email'] ?? ''));
        $telHref = tel_href($tel);
        if ($telHref !== '' || $mail !== ''): ?>
  <section class="wrap block kontakt-dom" id="kontakt-dane">
    <div class="kontakt-karta">
      <div class="kontakt-txt">
        <p class="eyebrow"><?= e($S['home.contact.eyebrow'] ?? '') ?></p>
        <h2><?= e($S['home.contact.title'] ?? '') ?></h2>
        <p><?= e($S['home.contact.lead'] ?? '') ?></p>
      </div>
      <div class="kontakt-dane">
        <?php if ($telHref !== ''): ?>
        <a class="kontakt-poz" href="<?= e($telHref) ?>"><?= function_exists('ikona_kontaktu') ? ikona_kontaktu('telefon') : '' ?>
          <span><small><?= e($S['home.contact.phone'] ?? '') ?></small><b><?= e($tel) ?></b></span></a>
        <?php endif; ?>
        <?php if ($mail !== ''): ?>
        <a class="kontakt-poz" href="<?= e('mailto:' . $mail) ?>"><?= function_exists('ikona_kontaktu') ? ikona_kontaktu('email') : '' ?>
          <span><small><?= e($S['home.contact.email'] ?? '') ?></small><b><?= e($mail) ?></b></span></a>
        <?php endif; ?>
        <a class="kontakt-poz kontakt-poz--form" href="<?= e(u('kontakt')) ?>"><?= function_exists('ikona_kontaktu') ? ikona_kontaktu('formularz') : '' ?>
          <span><b><?= e($S['home.contact.form'] ?? '') ?></b></span></a>
      </div>
    </div>
  </section>
  <?php endif; ?>
  <?php endif; ?>
<?php endforeach; ?>
</main>
<?php
    layout_footer($S);
    exit;
}

// ------------------------------------------------------------ FICHE PRODUIT -
if ($page === 'p') {
    $p = wsm_shop_product($pdo, (string) ($seg[1] ?? ''), $lang);
    if (!$p) {
        http_response_code(404);
        layout_head($S, $lang, $langs, $S['product.unknown'] ?? '', '', 'p');
        layout_header($S, $lang, $langs, $cartCount);
        echo '<main class="wrap block"><h1>' . e($S['product.unknown'] ?? '') . '</h1>'
           . '<p><a class="btn btn--brand" href="' . e(u()) . '">' . e($S['product.back'] ?? '') . '</a></p></main>';
        layout_footer($S);
        exit;
    }
    $related = array_values(array_filter(wsm_shop_products($pdo, $lang), fn($x) => $x['id'] !== $p['id']));
    $related = array_slice($related, 0, 4);
    $stockLabel = $p['stock'] <= 0 ? ($S['product.on_demand'] ?? '')
        : ($p['stock'] <= 10 ? ($S['product.stock_low'] ?? '') : ($S['product.stock_in'] ?? ''));

    $ogImg = ($p['image'] ?? '') !== '' && seo_origin() !== ''
    ? (preg_match('#^https?://#i', (string) $p['image'])
        ? (string) $p['image']
        : seo_origin() . shop_base() . '/' . ltrim((string) $p['image'], '/'))
    : '';
    // La vue produit, pour GA4 et Meta — ne part qu'après l'accord (zgody.js).
    if (function_exists('wsm_ads_item')) ads_event(['n' => 'view_item', 'value' => round($p['price'] / 100, 2), 'items' => [wsm_ads_item($p)]]);
layout_head($S, $lang, $langs, $p['name'], $p['desc'], 'p', $ogImg);
    layout_header($S, $lang, $langs, $cartCount);
    // Le prix balisé sort du MÊME tableau que le prix affiché douze lignes
    // plus bas : un écart entre les deux fait retirer les résultats enrichis
    // du site entier, et ce serait mérité.
    seo_product($p, $lang, WSM_SHOP_DEFAULT_LANG,
                function_exists('wsm_ads_ship_min') ? ['ship' => wsm_ads_ship_min($pdo), 'handling' => wsm_ads_handling_days()] : []);
    ?>
<main class="wrap block">
  <?php // Le fil d'Ariane passe par le RAYON du produit : « Sklep › Mąki ›
        // Mąka tortowa », et le rayon ramène à son onglet du catalogue.
        $rayon = ($p['category'] !== '' && $p['category_id'] > 0)
            ? [$p['category'] => u('', ['k' => $p['category_id']]) . '#katalog']
            : [($S['catalog.title'] ?? $S['nav.shop'] ?? 'Katalog') => u() . '#katalog'];
        layout_crumbs([($S['nav.shop'] ?? 'Sklep') => u()] + $rayon + [$p['name'] => null]); ?>
  <div class="product">
    <div class="product-media"><?= product_visual($p, 'product-photo') ?></div>
    <div class="product-buy">
      <?php if ($p['brand']): ?>
      <div class="brand-line">
        <?= brand_mark($p['brand'], 'brand-mark brand-mark--lg') ?>
        <span class="brand-name">
          <span class="mono eyebrow"><?= e($S['product.brand'] ?? '') ?></span>
          <?php if (($p['brand']['site'] ?? '') !== ''): ?>
            <a href="<?= e($p['brand']['site']) ?>" target="_blank" rel="noopener nofollow"><?= e($p['brand']['name']) ?></a>
          <?php else: ?>
            <b><?= e($p['brand']['name']) ?></b>
          <?php endif; ?>
        </span>
      </div>
      <?php endif; ?>
      <?php if ($p['subtitle'] !== ''): ?><p class="mono eyebrow"><?= e($p['subtitle']) ?></p><?php endif; ?>
      <h1><?= e($p['name']) ?></h1>
      <p class="price price--lg"><?= e(zl($p['price'])) ?><small><?= e($S['price.vat_incl'] ?? '') ?></small><?php
        if (!empty($p['price_per_kg'])): ?><span class="za-kg mono"><?= e(zl($p['price_per_kg'])) ?><?= e($S['price.per_kg'] ?? '') ?></span><?php endif; ?></p>
      <p class="mono muted"><?= e(zl($p['price_net'])) ?> <?= e($S['price.net'] ?? '') ?>
        · VAT <?= e(wsm_vat_percent((float) $p['vat_rate'])) ?> %
        · <?= e($S['product.' . ($p['stock'] <= 0 ? 'stock_out' : 'stock_in')] ?? '') ?></p>

      <?php // L'ACHAT, COMME CHEZ APPLE (maquette « UX style Apple ») : on choisit
            // une QUANTITÉ dans des cartes, et chaque carte dit déjà son prix et
            // sa remise. Les cartes au-delà de « 1 » sont exactement les
            // quantités qui font franchir un palier de Rabaty, panier compris :
            // « Kup 15 kg, aby otrzymać rabat 10 % », écrit en cartes qu'on
            // touche. shop.js recalcule la boîte des paliers et le total ;
            // sans lui, les cartes sont de vrais boutons radio et le formulaire
            // part tel quel. Un produit sans poids ne fait pas avancer la
            // remise : il garde le simple champ de quantité.
            $waga  = (int) $p['weight_g'];
            $progi = $waga > 0 ? progi_rabatu($pdo) : [];
            $wKosz = $progi ? waga_koszyka($pdo, $cart) : 0;
            $rabatDla = function (int $g) use ($progi): float {
                $r = 0.0; foreach ($progi as $t) if ($g >= $t['g'] && $t['p'] > $r) $r = $t['p'];
                return $r;
            };
            $opcje = [1];
            foreach ($progi as $t) {
                $n = (int) ceil(($t['g'] - $wKosz) / max(1, $waga));
                if ($n > 1 && $n <= WSM_SHOP_MAX_QTY && !in_array($n, $opcje, true) && count($opcje) < 4) $opcje[] = $n;
            }
            $maxQ = (int) WSM_SHOP_MAX_QTY; ?>
      <div class="kup" id="kup">
        <form class="buy-form" id="kup-form" method="post" action="<?= e(u('koszyk')) ?>" data-add data-cena="<?= (int) $p['price'] ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="add" value="<?= e($p['id']) ?>">
          <?php if ($progi): ?>
          <h2 class="kup-tytul"><?= e($S['product.qty_title'] ?? '') ?> <span><?= e($S['product.qty_sub'] ?? '') ?></span></h2>
          <div class="ile-opcje" role="radiogroup" aria-label="<?= e($S['product.qty'] ?? '') ?>"
               data-t-badge="<?= e($S['product.discount_badge'] ?? 'rabat {p} %') ?>">
            <?php foreach ($opcje as $i => $n): $r = $rabatDla($wKosz + $n * $waga); ?>
            <label class="ile-opcja" data-n="<?= $n ?>">
              <input type="radio" name="ile" value="<?= $n ?>"<?= $i === 0 ? ' checked' : '' ?>>
              <span class="ile-txt"><b><?= e(kg_txt($n * $waga)) ?></b><small><?= $n ?> × <?= e($p['unit'] !== '' ? $p['unit'] : kg_txt($waga)) ?></small></span>
              <?php // Le prix et l'insigne se redessinent après un ajout sur place
                    // (shop.js) : le panier pèse alors plus, la remise change. ?>
              <span class="ile-cena"><span data-ile-cena><?= e(zl((int) round($p['price'] * $n * (1 - $r / 100)))) ?></span><em data-ile-rabat<?= $r > 0 ? '' : ' hidden' ?>><?= $r > 0 ? e(str_replace('{p}', procent_txt($r), $S['product.discount_badge'] ?? 'rabat {p} %')) : '' ?></em></span>
            </label>
            <?php endforeach; ?>
            <label class="ile-opcja ile-opcja--inna">
              <input type="radio" name="ile" value="inna">
              <span class="ile-txt"><b><?= e($S['product.qty_other'] ?? '') ?></b><small><?= e(str_replace('{max}', (string) $maxQ, $S['product.qty_other_sub'] ?? '')) ?></small></span>
              <?php // Le champ est DANS l'étiquette de la carte, dont le contrôle est
                    // le bouton radio : il porte donc son propre nom (aria-label). ?>
              <span class="qty qty--inna"><input type="number" name="qty" value="1" min="1" max="<?= $maxQ ?>" inputmode="numeric"
                     aria-label="<?= e($S['product.qty_other'] ?? ($S['product.qty'] ?? '')) ?>"></span>
            </label>
          </div>

          <?php $stP = stan_progu($progi, $wKosz + $waga); ?>
          <?php // data-w-koszyku, et SURTOUT pas data-koszyk : ce nom-là marque le
                // panier vivant (shop.js). Porté ici, il faisait prendre la fiche
                // pour un panier — elle était remplacée après chaque ajout et
                // perdait ses écouteurs (total figé, barre d'achat disparue). ?>
          <div class="prog-rabat" data-prog data-waga="<?= $waga ?>" data-w-koszyku="<?= (int) $wKosz ?>"
               data-progi="<?= e((string) json_encode($progi)) ?>"
               data-t-next="<?= e($S['product.tier_next'] ?? '') ?>" data-t-top="<?= e($S['product.tier_top'] ?? '') ?>"
               data-t-have="<?= e($S['product.tier_have'] ?? '') ?>" data-t-cart="<?= e($S['product.tier_cart'] ?? '') ?>">
            <?= ikona_prezentu() ?>
            <div class="prog-tresc">
              <p class="prog-zdanie" data-prog-zdanie aria-live="polite"><?= $stP['next']
                  ? zdanie_html($S['product.tier_next'] ?? '', ['{x}' => kg_txt($stP['missing']), '{p}' => procent_txt($stP['next']['p'])])
                  : zdanie_html($S['product.tier_top'] ?? '', ['{p}' => procent_txt($stP['have'])]) ?></p>
              <div class="prog-pasek" aria-hidden="true"><i data-prog-pasek style="width:<?= (int) $stP['pct'] ?>%"></i></div>
              <p class="prog-dopisek">
                <span class="prog-ma" data-prog-ma<?= $stP['next'] && $stP['have'] > 0 ? '' : ' hidden' ?>><?= e(str_replace('{p}', procent_txt($stP['have']), $S['product.tier_have'] ?? '')) ?></span>
                <span class="prog-kosz" data-prog-kosz<?= $wKosz > 0 ? '' : ' hidden' ?>><?= e(str_replace('{k}', kg_txt($wKosz), $S['product.tier_cart'] ?? '')) ?></span>
                <span class="prog-lista"><?= e(str_replace('{lista}', implode(' · ', array_map(fn($t) => strtr($S['product.tier_item'] ?? '{p} % / {kg}', ['{p}' => procent_txt($t['p']), '{kg}' => kg_txt($t['g'])]), $progi)), $S['product.tiers_list'] ?? '')) ?></span>
              </p>
            </div>
          </div>
          <?php else: ?>
          <label class="qty qty--sama">
            <span class="sr-only"><?= e($S['product.qty'] ?? '') ?></span>
            <input type="number" name="qty" value="1" min="1" max="<?= $maxQ ?>" inputmode="numeric">
          </label>
          <?php endif; ?>

          <?php // LA LIVRAISON, avant de payer : l'heure limite, les transporteurs
                // et leur prix (Dostawa), le seuil de gratuité et l'emballage
                // isotherme — les mêmes phrases que les promesses de l'accueil. ?>
          <?php // Le poids d'UNE pièce : un transporteur qui ne la prend pas
                // (Paczkomat au-delà de 25 kg, un minimum de palette) n'est pas
                // annoncé ici — le panier fait le même tri. ?>
          <?php $metodyD = wsm_shipping_methods($pdo, $lang, WSM_SHOP_HOME_COUNTRY, max(0, (int) $p['weight_g']));
                $darmD = z_progiem((string) ($S['info.darmowa'] ?? ''), prog_darmowej($pdo)); ?>
          <h2 class="kup-tytul"><?= e($S['product.ship_title'] ?? '') ?> <span><?= e($S['product.ship_sub'] ?? '') ?></span></h2>
          <ul class="dostawa-info">
            <li><?= ikona_obietnicy(1) ?><span><b><?= e((string) ($S['promise.1.d'] ?? '')) ?></b>
              <?php if ($metodyD): ?><small><?= e(implode(' · ', array_map(fn($m) => $m['label'] . ' ' . zl($m['price']), $metodyD))) ?></small><?php endif; ?></span></li>
            <?php if ($darmD !== ''): ?><li><?= ikona_obietnicy(2) ?><span><b><?= e($darmD) ?></b></span></li><?php endif; ?>
            <?php if (($S['promise.3.t'] ?? '') !== ''): ?><li><?= ikona_obietnicy(3) ?><span><b><?= e((string) $S['promise.3.t']) ?></b><small><?= e((string) ($S['promise.3.d'] ?? '')) ?></small></span></li><?php endif; ?>
          </ul>

          <div class="kup-razem">
            <p><span><?= e($S['product.total'] ?? '') ?></span> <b data-kup-razem><?= e(zl((int) $p['price'])) ?></b>
              <em data-kup-oszczednosc data-t="<?= e($S['product.save'] ?? '') ?>" hidden></em></p>
            <small><?= e($S['product.total_note'] ?? '') ?></small>
          </div>
          <button class="btn btn--accent btn--lg btn--block" type="submit"><?= e($S['product.add'] ?? '') ?></button>
        </form>
        <?php if ($p['stock'] <= 0) notice('warn', $S['product.on_demand_note'] ?? ''); ?>
        <p class="stock"><?= e($stockLabel) ?></p>
      </div>

      <?php // CE QUE LE CLIENT CHERCHE AVANT DE PAYER, en clair et dans l'ordre
            // de la maquette de l'équipe : la référence, le rayon, puis comment
            // il recevra et comment il paiera. ?>
      <dl class="buy-meta">
        <?php if ($p['sku'] !== ''): ?><div><dt><?= e($S['product.sku'] ?? '') ?></dt><dd class="mono"><?= e($p['sku']) ?></dd></div><?php endif; ?>
        <?php if ($p['category'] !== '' && $p['category_id'] > 0): ?>
        <div><dt><?= e($S['product.category'] ?? '') ?></dt><dd><a href="<?= e(u('', ['k' => $p['category_id']]) . '#katalog') ?>"><?= e($p['category']) ?></a></dd></div>
        <?php endif; ?>
      </dl>
      <?php $telP = trim((string) ($S['footer.phone'] ?? '')); if (tel_href($telP) !== ''): ?>
      <p class="kup-pomoc"><?= ikona_kontaktu('telefon') ?><span><?= e($S['product.help'] ?? '') ?> <a href="<?= e(tel_href($telP)) ?>"><?= e($telP) ?></a></span></p>
      <?php endif; ?>
      <?= metody_html($pdo, $S, 'metody--karta') ?>
    </div>
  </div>

  <?php // LA DESCRIPTION, LES PROPRIÉTÉS, LES VALEURS NUTRITIVES — trois blocs
        // distincts, plus « tout fondu dans un seul texte ». Les propriétés
        // s'écrivent ligne à ligne dans la console (Opakowanie, Skład…) ; les
        // valeurs nutritives sont des chiffres pour 100 g, dans l'ordre légal.
        $opis = opis_html((string) $p['desc']);
        $wlasc = [];
        if ($p['origin'] !== '') $wlasc[] = [$S['product.origin'] ?? '', $p['origin']];
        if ($p['cocoa'] !== '')  $wlasc[] = [$S['product.cocoa'] ?? '', $p['cocoa']];
        if ($p['weight_g'] > 0)  $wlasc[] = [$S['product.weight'] ?? '', number_format($p['weight_g'] / 1000, 3, ',', ' ') . ' kg'];
        foreach (wsm_product_props($S, (string) $p['id']) as $pr) $wlasc[] = $pr;
        if ($p['ean'] !== '')    $wlasc[] = [$S['product.ean'] ?? 'EAN', $p['ean']];
        $nutri = wsm_nutrition_rows((array) ($p['nutrition'] ?? []), $S); ?>
  <?php if ($opis !== '' || $wlasc || $nutri): ?>
  <div class="product-info">
    <?php if ($opis !== ''): ?>
    <section class="product-opis">
      <h2><?= e($S['product.desc_title'] ?? '') ?></h2>
      <div class="prose"><?= $opis ?></div>
    </section>
    <?php endif; ?>
    <?php if ($wlasc || $nutri): ?>
    <div class="product-tabele">
      <?php if ($wlasc): ?>
      <section class="product-tabela">
        <h2><?= e($S['product.props'] ?? '') ?></h2>
        <dl class="tabela"><?php foreach ($wlasc as [$k, $v]): ?>
          <div><dt><?= e((string) $k) ?></dt><dd><?= e((string) $v) ?></dd></div><?php endforeach; ?>
        </dl>
      </section>
      <?php endif; ?>
      <?php if ($nutri): ?>
      <section class="product-tabela product-tabela--nutri">
        <h2><?= e($S['product.nutrition'] ?? '') ?></h2>
        <dl class="tabela"><?php foreach ($nutri as [$k, $v, $sous]): ?>
          <div<?= $sous ? ' class="pod"' : '' ?>><dt><?= e((string) $k) ?></dt><dd><?= e((string) $v) ?></dd></div><?php endforeach; ?>
        </dl>
      </section>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($related): ?>
  <section class="block">
    <div class="section-head"><h2><?= e($S['product.related'] ?? '') ?></h2></div>
    <div class="grid">
      <?php foreach ($related as $r): ?>
      <article class="card">
        <a class="card-media" href="<?= e(u('p/' . $r['slug'])) ?>"><?= product_visual($r, 'card-photo') ?></a>
        <div class="card-body">
          <p class="card-meta mono"><?= e($r['subtitle']) ?></p>
          <h3><a href="<?= e(u('p/' . $r['slug'])) ?>"><?= e($r['name']) ?></a></h3>
          <div class="card-buy"><span class="price"><?= e(zl($r['price'])) ?></span></div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php // LA BARRE D'ACHAT, collée en bas de l'écran pendant qu'on lit la
        // description et les tableaux — comme sur les pages Apple. Son bouton
        // envoie le formulaire du haut (form="kup-form") : la quantité
        // choisie part avec, même sans JavaScript. shop.js la cache tant que
        // le vrai bouton est à l'écran et y recopie le total. ?>
  <div class="kup-pasek is-ukryty" data-kup-pasek>
    <div class="kup-pasek-in">
      <span class="kup-pasek-txt"><b><?= e($p['name']) ?></b><span data-kup-pasek-razem><?= e(zl((int) $p['price'])) ?></span></span>
      <button class="btn btn--accent" type="submit" form="kup-form"><?= e($S['product.add'] ?? '') ?></button>
    </div>
  </div>
</main>
<?php
    layout_footer($S);
    exit;
}

// ------------------------------------------------------------------ CONTACT -
//  Le message entre dans la MESSAGERIE, pas dans un e-mail : même écran, même
//  recherche, même statuts que le courrier reçu — et rien n'est perdu si le
//  SMTP est muet. Voir php-api/contact.php pour les trois filtres anti-robot.
// ==================== NEWSLETTER I SMS (marketing.php) ======================
//  Quatre gestes, une seule page : s'inscrire (formulaire du pied ou de cette
//  page), confirmer son adresse (lien du mail), se désinscrire de l'e-mail
//  (lien signé), se désinscrire des SMS (lien court /s/…). Se désinscrire
//  demande UN clic de confirmation : les antivirus de messagerie ouvrent les
//  liens tout seuls, et ne doivent pas désinscrire à la place du client.
if ($page === 'newsletter' || $page === 's') {
    require_once $WSM_API_DIR . '/marketing.php';
    require_once $WSM_API_DIR . '/contact.php';      // le piège et l'horodatage, comme le contact
    $titre = $S['nl.title'] ?? 'Newsletter';
    $msg = ''; $msgKind = 'ok'; $bouton = null; $v = []; $nlErr = []; $formulaire = false;
    $etape = (string) ($seg[1] ?? '');

    if ($page === 's') {
        $tok = preg_replace('/[^a-z0-9]/', '', strtolower($etape)) ?? '';
        $titre = $S['nl.sms_unsub_title'] ?? '';
        $q = $pdo->prepare("SELECT sms_status FROM wsm_subscribers WHERE sms_token = ? AND sms_token <> ''");
        $q->execute([$tok]);
        $etat = $q->fetchColumn();
        $marque = (string) ($S['brand'] ?? '');
        if ($etat === false) { $msg = $S['nl.bad_link'] ?? ''; $msgKind = 'warn'; }
        elseif ($etat === 'oczekuje') {
            // Le lien du SMS de confirmation : le TÉLÉPHONE l'ouvre, un bouton
            // confirme (un aperçu de lien qui « ouvre » tout seul ne le presse pas).
            $titre = $S['nl.sms_confirm_title'] ?? $titre;
            if ($method === 'POST' && ($_POST['a'] ?? '') === 'potwierdz') {
                $okSms = wsm_mkt_sms_confirm($pdo, $tok);
                $msg = $okSms ? ($S['nl.sms_ok'] ?? '') : ($S['nl.bad_link'] ?? ''); $msgKind = $okSms ? 'ok' : 'warn';
            } elseif ($method === 'POST') {
                wsm_mkt_unsub_sms($pdo, $tok);
                $msg = $S['nl.sms_unsub_done'] ?? '';
            } else {
                $msg = str_replace('{sklep}', $marque, (string) ($S['nl.sms_confirm_ask'] ?? '')); $msgKind = 'info';
                $bouton = ['action' => u('s/' . $tok), 'label' => $S['nl.sms_confirm_btn'] ?? '', 'champs' => ['a' => 'potwierdz']];
            }
        }
        elseif ($method === 'POST' || $etat === 'wypisany') {
            if ($method === 'POST') wsm_mkt_unsub_sms($pdo, $tok);
            $msg = $S['nl.sms_unsub_done'] ?? '';
        } else {
            $msg = $S['nl.sms_unsub_ask'] ?? ''; $msgKind = 'info';
            $bouton = ['action' => u('s/' . $tok), 'label' => $S['nl.unsub_btn'] ?? '', 'champs' => []];
        }
    } elseif ($etape === 'potwierdz') {
        // Un bouton, pas le simple clic : les antivirus de messagerie ouvrent
        // les liens tout seuls, et une confirmation automatique effacerait un
        // refus donné plus tôt par la personne.
        $tk = preg_replace('/[^a-f0-9]/', '', (string) ($method === 'POST' ? ($_POST['t'] ?? '') : ($_GET['t'] ?? ''))) ?? '';
        if ($method === 'POST') {
            [$ok] = wsm_mkt_confirm($pdo, $tk);
            $titre = $S['nl.confirmed_title'] ?? '';
            $msg = $ok ? ($S['nl.confirmed'] ?? '') : ($S['nl.bad_link'] ?? '');
            $msgKind = $ok ? 'ok' : 'warn';
        } else {
            $msg = $S['nl.confirm_ask'] ?? ''; $msgKind = 'info';
            $bouton = ['action' => u('newsletter/potwierdz'), 'label' => $S['nl.confirm_btn'] ?? '', 'champs' => ['t' => $tk]];
        }
    } elseif ($etape === 'wypisz') {
        $titre = $S['nl.unsub_title'] ?? '';
        $src = $method === 'POST' ? $_POST : $_GET;
        $em = (string) ($src['e'] ?? ''); $tk = (string) ($src['t'] ?? '');
        $rf = preg_replace('/[^a-z0-9:]/', '', (string) ($src['r'] ?? '')) ?? '';
        if (!wsm_mkt_token_email_ok($em, $tk)) { $msg = $S['nl.bad_link'] ?? ''; $msgKind = 'warn'; }
        elseif ($method === 'POST') { wsm_mkt_unsub_email($pdo, $em, $rf); $msg = $S['nl.unsub_done'] ?? ''; }
        else {
            $msg = str_replace('{email}', $em, $S['nl.unsub_ask'] ?? ''); $msgKind = 'info';
            $bouton = ['action' => u('newsletter/wypisz'), 'label' => $S['nl.unsub_btn'] ?? '',
                       'champs' => ['e' => $em, 't' => $tk, 'r' => $rf]];
        }
    } else {
        $formulaire = true;
        if ($method === 'POST') {
            $v = $_POST;
            $ip = wsm_contact_ip();
            [$tsOk] = wsm_contact_stamp_ok((string) ($_POST['_ts'] ?? ''));
            $robot = trim((string) ($_POST['firma_www'] ?? '')) !== '' || !$tsOk;
            if (!$robot && $ip !== '') {
                // Cinq inscriptions par heure et par adresse IP : au-delà, c'est un robot.
                $q = $pdo->prepare("SELECT COUNT(*) FROM wsm_subscribers WHERE ip = ? AND updated_at >= ?");
                $q->execute([$ip, date('Y-m-d H:i:s', time() - 3600)]);
                $robot = (int) $q->fetchColumn() >= 5;
            }
            $chceNl = !empty($_POST['zgoda_nl']); $chceSms = !empty($_POST['zgoda_sms']);
            if (wsm_mkt_email_norm((string) ($_POST['email'] ?? '')) === '') $nlErr['email'] = $S['nl.err_email'] ?? '';
            if (!$chceNl && !$chceSms) $nlErr['zgoda'] = $S['nl.err_consent'] ?? '';
            if ($chceSms && wsm_mkt_phone((string) ($_POST['phone'] ?? '')) === '') $nlErr['phone'] = $S['nl.err_phone'] ?? '';
            if (!$nlErr) {
                // Un robot reçoit la même réponse qu'un humain : lui dire non lui
                // apprendrait à contourner.
                if (!$robot) {
                    // La preuve : le texte des cases tel que le SERVEUR l'affiche,
                    // jamais ce qu'un formulaire renverrait.
                    [$zNl, $zSms] = nl_textes_zgody($S);
                    wsm_mkt_subscribe($pdo, [
                        'email' => (string) $_POST['email'], 'phone' => (string) ($_POST['phone'] ?? ''), 'lang' => $lang,
                        'source' => 'strona', 'newsletter' => $chceNl, 'sms' => $chceSms, 'ip' => $ip,
                        'tekst_email' => $chceNl ? $zNl : '', 'tekst_sms' => $chceSms ? $zSms : '',
                    ]);
                }
                $msg = trim(($chceNl ? str_replace('{email}', strtolower(trim((string) $_POST['email'])), $S['nl.check_mail'] ?? '') : '')
                          . ' ' . ($chceSms ? ($S['nl.sms_check'] ?? '') : ''));
                $formulaire = false; $v = [];
            }
        }
    }

    $GLOBALS['WSM_PAS_DE_NL'] = true;        // pas deux formulaires sur la même page
    layout_head($S, $lang, $langs, $titre, $S['nl.lead'] ?? '', 'newsletter');
    layout_header($S, $lang, $langs, $cartCount);
    ?>
<main class="wrap block nl-page">
  <h1><?= e($titre) ?></h1>
  <?php if ($msg !== '') notice($msgKind, $msg); ?>
  <?php if ($bouton): ?>
  <form method="post" action="<?= e($bouton['action']) ?>">
    <?= csrf_field() ?>
    <?php foreach ($bouton['champs'] as $k => $x): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($x) ?>"><?php endforeach; ?>
    <button class="btn btn--brand" type="submit"><?= e($bouton['label']) ?></button>
  </form>
  <?php endif; ?>
  <?php if ($formulaire): ?>
  <p class="lead"><?= e($S['nl.lead'] ?? '') ?></p>
  <?php nl_form($S, $v, $nlErr); ?>
  <?php endif; ?>
  <p><a class="btn btn--ghost" href="<?= e(u()) ?>"><?= e($S['cart.empty_cta'] ?? '') ?></a></p>
</main>
<?php
    layout_footer($S);
    exit;
}

// ---------------------------------------------------------- FORMULAIRES -----
//  /f/<adresse> : les formulaires construits dans Marketing → Formularze.
//  Même fabrique que Kontakt (piège, jeton horodaté, plafond par IP), et la
//  preuve de l'accord recomposée ici, jamais renvoyée par le navigateur.
if ($page === 'f' && is_file($WSM_API_DIR . '/formularze.php')) {
    require_once $WSM_API_DIR . '/formularze.php';
    $form = wsm_form_by_slug($pdo, (string) ($seg[1] ?? ''));
    if (!$form) {
        http_response_code(404);
        layout_head($S, $lang, $langs, '404', '', 'koszyk');
        layout_header($S, $lang, $langs, $cartCount);
        echo '<main class="wrap block"><h1>404</h1><p class="muted">' . e($S['form.not_found'] ?? '') . '</p>'
           . '<p><a class="btn btn--brand" href="' . e(u()) . '">' . e($S['cart.empty_cta'] ?? '') . '</a></p></main>';
        layout_footer($S);
        exit;
    }
    $sprzedawca = trim((string) ($S['seller.name'] ?? '')) !== '' ? (string) $S['seller.name'] : (string) ($S['brand'] ?? '');
    $tZgoda = str_replace('{sprzedawca}', $sprzedawca, (string) ($S['form.consent'] ?? ''));
    [$tNl] = nl_textes_zgody($S);
    $fErr = []; $fv = [];
    if ($method === 'POST') {
        [$fid, $fErr] = wsm_form_submit($pdo, $form, $_POST, ['zgoda' => $tZgoda, 'nl' => $tNl], $lang);
        // Un robot repéré reçoit la page de remerciement, comme tout le monde :
        // lui dire pourquoi il est refusé lui apprendrait quoi corriger.
        if ($fid || isset($fErr['_bot'])) redirect(u('f/' . $form['slug'], ['ok' => 1]));
        $fv = $_POST;
    }
    $ok = isset($_GET['ok']);
    // La case newsletter est déjà dans ce formulaire : pas un second
    // formulaire d'inscription dans le pied de la même page.
    if (!empty($form['newsletter'])) $GLOBALS['WSM_PAS_DE_NL'] = true;
    layout_head($S, $lang, $langs, (string) $form['nazwa'], mb_substr(trim((string) $form['wstep']), 0, 160), 'f');
    layout_header($S, $lang, $langs, $cartCount);
    ?>
<main class="wrap block formularz">
  <?php layout_crumbs([($S['nav.shop'] ?? 'Sklep') => u(), (string) $form['nazwa'] => null]); ?>
  <h1><?= e((string) $form['nazwa']) ?></h1>
  <?php if (trim((string) $form['wstep']) !== ''): ?><p class="lead"><?= nl2br(e((string) $form['wstep'])) ?></p><?php endif; ?>
  <?php if ($ok): ?>
    <?php notice('ok', trim((string) $form['podziekowanie']) !== '' ? (string) $form['podziekowanie'] : (string) ($S['form.sent'] ?? '')); ?>
    <p><a class="btn btn--brand" href="<?= e(u()) ?>"><?= e($S['cart.empty_cta'] ?? '') ?></a></p>
  <?php else: ?>
    <?php if (isset($fErr['_limit'])) notice('error', $fErr['_limit']); elseif ($fErr) notice('error', $S['contact.error'] ?? ''); ?>
    <form method="post" class="checkout-form" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="_ts" value="<?= e(wsm_contact_stamp()) ?>">
      <div class="hp" aria-hidden="true">
        <label for="f-firma_www">Nie wypełniaj tego pola</label>
        <input type="text" id="f-firma_www" name="firma_www" tabindex="-1" autocomplete="off" value="">
      </div>
      <fieldset>
        <p class="hint"><?= e($S['contact.required'] ?? '') ?></p>
        <?php foreach ($form['pola_list'] as $pole):
            $pid = (string) $pole['id']; $id = 'f-' . $pid; $err = $fErr[$pid] ?? '';
            $val = (string) (($fv['p'] ?? [])[$pid] ?? ''); $req = !empty($pole['wymagane']);
            $aria = $err ? ' aria-invalid="true" aria-describedby="' . e($id) . '-e"' : '';
            $name = 'p[' . e($pid) . ']'; ?>
        <p class="field<?= $err ? ' has-error' : '' ?><?= $pole['typ'] === 'textarea' ? ' wide' : '' ?>">
          <?php if ($pole['typ'] === 'checkbox'): ?>
          <label class="check" for="<?= e($id) ?>"><input type="checkbox" id="<?= e($id) ?>" name="<?= $name ?>" value="1"<?= $val !== '' ? ' checked' : '' ?><?= $aria ?>>
            <span><?= e((string) $pole['etykieta']) ?><?= $req ? ' <span aria-hidden="true">*</span>' : '' ?></span></label>
          <?php else: ?>
          <label for="<?= e($id) ?>"><?= e((string) $pole['etykieta']) ?><?= $req ? ' <span aria-hidden="true">*</span>' : '' ?></label>
          <?php if ($pole['typ'] === 'textarea'): ?>
          <textarea id="<?= e($id) ?>" name="<?= $name ?>" rows="5" maxlength="5000"<?= $req ? ' required' : '' ?><?= $aria ?>><?= e($val) ?></textarea>
          <?php elseif ($pole['typ'] === 'select'): ?>
          <select id="<?= e($id) ?>" name="<?= $name ?>"<?= $req ? ' required' : '' ?><?= $aria ?>>
            <option value=""><?= e($S['form.choose'] ?? '—') ?></option>
            <?php foreach ((array) $pole['opcje'] as $o): ?><option<?= $val === (string) $o ? ' selected' : '' ?>><?= e((string) $o) ?></option><?php endforeach; ?>
          </select>
          <?php else:
              $typ = ['email' => 'email', 'tel' => 'tel', 'number' => 'number', 'date' => 'date'][$pole['typ']] ?? 'text';
              $ac = ['email' => 'email', 'tel' => 'tel'][$pole['typ']] ?? ($pid === 'imie' ? 'name' : ($pid === 'firma' ? 'organization' : '')); ?>
          <input id="<?= e($id) ?>" name="<?= $name ?>" type="<?= $typ ?>" value="<?= e($val) ?>" maxlength="300"<?= $req ? ' required' : '' ?><?= $ac !== '' ? ' autocomplete="' . $ac . '"' : '' ?><?= $pole['typ'] === 'nip' ? ' inputmode="numeric"' : '' ?><?= $pole['typ'] === 'number' ? ' min="0" step="1"' : '' ?><?= $aria ?>>
          <?php endif; endif; ?>
          <?php if ($err): ?><small class="err" id="<?= e($id) ?>-e"><?= e($err) ?></small><?php endif; ?>
        </p>
        <?php endforeach; ?>
        <p class="field wide<?= isset($fErr['zgoda']) ? ' has-error' : '' ?>">
          <label class="check" for="f-zgoda"><input type="checkbox" id="f-zgoda" name="zgoda" value="1"<?= !empty($fv['zgoda']) ? ' checked' : '' ?>>
            <span><?= e($tZgoda) ?> <a href="<?= e(u('prywatnosc')) ?>"><?= e($S['legal.privacy'] ?? '') ?></a> <span aria-hidden="true">*</span></span></label>
          <?php if (isset($fErr['zgoda'])): ?><small class="err"><?= e($fErr['zgoda']) ?></small><?php endif; ?>
        </p>
        <?php if (!empty($form['newsletter'])): ?>
        <p class="field wide">
          <label class="check" for="f-zgoda_nl"><input type="checkbox" id="f-zgoda_nl" name="zgoda_nl" value="1"<?= !empty($fv['zgoda_nl']) ? ' checked' : '' ?>>
            <span><?= e($tNl) ?></span></label>
        </p>
        <?php endif; ?>
      </fieldset>
      <p class="pay-info"><button class="btn btn--brand" type="submit"><?= e($S['form.submit'] ?? ($S['contact.submit'] ?? '')) ?></button></p>
    </form>
  <?php endif; ?>
</main>
<?php
    layout_footer($S);
    exit;
}

if ($page === 'kontakt') {
    // $WSM_API_DIR, PAS un chemin en dur : le déploiement renomme php-api en
    // api. Écrit en dur, ce require passait en local et TUAIT la page sur le
    // serveur — 500, corps vide, aucun message dans le navigateur. Le
    // formulaire de contact a été injoignable en production tant que cette
    // ligne a nommé un dossier qui n existe que dans le dépôt.
    require_once $WSM_API_DIR . '/contact.php';

    $envoye = false; $cErr = []; $v = [];
    if ($method === 'POST') {
        $v = $_POST;
        [$cid, $cErr] = wsm_contact_submit($pdo, $_POST, $lang);
        if ($cid) { $envoye = true; $v = []; }
    } else {
        // Le sujet peut arriver de la page d'où l'on vient (le bloc B2B de
        // l'accueil pointe ici avec ?temat=wspolpraca). On le VALIDE contre la
        // liste avant de le présélectionner : un paramètre d'URL est saisi par
        // le visiteur, pas par nous, et il finirait sinon en `selected` sur une
        // option fabriquée. Inconnu, on ne présélectionne rien — le formulaire
        // s'ouvre sur son premier choix, comme si le paramètre n'existait pas.
        $t = (string) ($_GET['temat'] ?? '');
        if ($t !== '' && in_array($t, WSM_CONTACT_SUJETS, true)) $v['topic'] = $t;
    }

    layout_head($S, $lang, $langs, $S['contact.title'] ?? '', $S['contact.lead'] ?? '', 'kontakt');
    layout_header($S, $lang, $langs, $cartCount);

    // Même fabrique de champ que la caisse : un formulaire de ce site doit se
    // ressembler d'une page à l'autre, jusqu'aux messages d'erreur.
    $cField = function (string $name, string $label, array $opt = []) use ($v, $cErr) {
        $err = $cErr[$name] ?? '';
        $id  = 'k-' . $name;
        $req = $opt['required'] ?? true;
        echo '<p class="field' . ($err ? ' has-error' : '') . ($opt['wide'] ?? false ? ' wide' : '') . '">';
        echo '<label for="' . e($id) . '">' . e($label) . ($req ? ' <span aria-hidden="true">*</span>' : '') . '</label>';
        if (($opt['type'] ?? '') === 'textarea') {
            echo '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="7" maxlength="5000"'
               . ($req ? ' required' : '') . ($err ? ' aria-invalid="true" aria-describedby="' . e($id) . '-e"' : '')
               . '>' . e((string) ($v[$name] ?? '')) . '</textarea>';
        } else {
            echo '<input id="' . e($id) . '" name="' . e($name) . '" type="' . e($opt['type'] ?? 'text') . '"'
               . ' value="' . e((string) ($v[$name] ?? '')) . '"'
               . ' maxlength="' . (int) ($opt['maxlength'] ?? 190) . '"'
               . ($req ? ' required' : '')
               . (isset($opt['autocomplete']) ? ' autocomplete="' . e($opt['autocomplete']) . '"' : '')
               . (isset($opt['inputmode']) ? ' inputmode="' . e($opt['inputmode']) . '"' : '')
               . ($err ? ' aria-invalid="true" aria-describedby="' . e($id) . '-e"' : '') . '>';
        }
        if ($err) echo '<small class="err" id="' . e($id) . '-e">' . e($err) . '</small>';
        echo '</p>';
    };
    ?>
<main class="wrap block">
  <?php layout_crumbs([
      ($S['nav.shop'] ?? 'Sklep') => u(),
      ($S['contact.title'] ?? 'Kontakt') => null,
  ]); ?>
  <h1><?= e($S['contact.title'] ?? '') ?></h1>
  <p class="lead"><?= e($S['contact.lead'] ?? '') ?></p>

  <?php // LE TÉLÉPHONE ET L'E-MAIL, AU-DESSUS DU FORMULAIRE. Demandé par
        // l'équipe : qui ouvre « Kontakt » cherche souvent à appeler, pas à
        // écrire. Mêmes sources que l'accueil et le pied de page (Treści :
        // footer.phone, footer.email) — un numéro, un seul endroit. ?>
  <?php $kTel = trim((string) ($S['footer.phone'] ?? '')); $kTelHref = $kTel !== '' ? tel_href($kTel) : '';
        $kMail = trim((string) ($S['footer.email'] ?? ''));
        if ($kTelHref !== '' || $kMail !== ''): ?>
  <div class="kontakt-dane kontakt-dane--strona">
    <?php if ($kTelHref !== ''): ?>
    <a class="kontakt-poz" href="<?= e($kTelHref) ?>"><?= function_exists('ikona_kontaktu') ? ikona_kontaktu('telefon') : '' ?>
      <span><small><?= e($S['home.contact.phone'] ?? '') ?></small><b><?= e($kTel) ?></b></span></a>
    <?php endif; ?>
    <?php if ($kMail !== ''): ?>
    <a class="kontakt-poz" href="<?= e('mailto:' . $kMail) ?>"><?= function_exists('ikona_kontaktu') ? ikona_kontaktu('email') : '' ?>
      <span><small><?= e($S['home.contact.email'] ?? '') ?></small><b><?= e($kMail) ?></b></span></a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($envoye): ?>
    <?php notice('ok', $S['contact.sent'] ?? ''); ?>
    <p><a class="btn btn--brand" href="<?= e(u()) ?>"><?= e($S['cart.empty_cta'] ?? '') ?></a></p>
  <?php else: ?>
    <?php
    // Un robot repéré reçoit la MÊME page qu'une erreur de saisie : lui dire
    // « piège » ou « trop rapide » lui apprendrait exactement quoi corriger.
    // Le plafond par IP, lui, s'affiche — c'est peut-être un client insistant.
    if (isset($cErr['_limit']))     notice('error', $cErr['_limit']);
    elseif (isset($cErr['_db']))    notice('error', $cErr['_db']);
    elseif ($cErr)                  notice('error', $S['contact.error'] ?? '');
    ?>
    <form method="post" class="checkout-form" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="_ts" value="<?= e(wsm_contact_stamp()) ?>">
      <?php // Le piège. Hors de la tabulation et masqué à la synthèse vocale :
            // aucun humain ne peut le remplir par accident, un robot qui lit le
            // HTML le remplit presque toujours. ?>
      <div class="hp" aria-hidden="true">
        <label for="firma_www">Nie wypełniaj tego pola</label>
        <input type="text" id="firma_www" name="firma_www" tabindex="-1" autocomplete="off" value="">
      </div>

      <fieldset>
        <p class="hint"><?= e($S['contact.required'] ?? '') ?></p>
        <div class="row">
          <?php $cField('name',  $S['contact.name'] ?? '',  ['autocomplete' => 'name', 'maxlength' => 120]); ?>
          <?php $cField('email', $S['contact.email'] ?? '', ['type' => 'email', 'autocomplete' => 'email']); ?>
        </div>
        <div class="row">
          <?php $cField('phone', $S['contact.phone'] ?? '', ['required' => false, 'autocomplete' => 'tel',
                                                             'inputmode' => 'tel', 'maxlength' => 24]); ?>
          <p class="field">
            <label for="k-topic"><?= e($S['contact.topic'] ?? '') ?></label>
            <select id="k-topic" name="topic">
              <?php foreach (WSM_CONTACT_SUJETS as $code): ?>
              <option value="<?= e($code) ?>"<?= ($v['topic'] ?? '') === $code ? ' selected' : '' ?>><?= e($S['contact.topic.' . $code] ?? $code) ?></option>
              <?php endforeach; ?>
            </select>
          </p>
        </div>
        <?php $cField('message', $S['contact.message'] ?? '', ['type' => 'textarea', 'wide' => true]); ?>

        <p class="field<?= isset($cErr['consent']) ? ' has-error' : '' ?>">
          <label class="check" for="k-consent">
            <input type="checkbox" id="k-consent" name="consent" value="1"<?= !empty($v['consent']) ? ' checked' : '' ?>>
            <span><?= e($S['contact.consent'] ?? '') ?></span>
          </label>
          <?php if (isset($cErr['consent'])): ?><small class="err"><?= e($cErr['consent']) ?></small><?php endif; ?>
        </p>
      </fieldset>

      <p class="pay-info"><button class="btn btn--brand" type="submit"><?= e($S['contact.submit'] ?? '') ?></button></p>
    </form>
  <?php endif; ?>
</main>
<?php
    layout_footer($S);
    exit;
}

// -------------------------------------------------------------------- PANIER -
if ($page === 'koszyk') {
    $shipId = (string) ($_GET['dostawa'] ?? 'inpost_locker');
    // L'adresse déjà saisie sert au devis : sans elle, un client professionnel
    // verrait le prix public jusqu'au dernier écran, puis un autre montant sur
    // sa facture. Un prix qui change en cours de route fait abandonner.
    [$q, $qErr] = wsm_shop_quote($pdo, cart_items($cart), $shipId, $lang,
                                 ['email' => (string) ($_POST['email'] ?? $_GET['email'] ?? ''),
                                  'voucher' => voucher_read()]);

    layout_head($S, $lang, $langs, $S['cart.title'] ?? '', '', 'koszyk');
    layout_header($S, $lang, $langs, $cartCount);
    ?>
<main class="wrap block">
  <?php layout_crumbs([
      ($S['nav.shop'] ?? 'Sklep') => u(),
      ($S['cart.title'] ?? 'Koszyk') => null,
  ]); ?>
  <?php if (!$q['lines']): ?>
    <h1><?= e($S['cart.title'] ?? '') ?></h1>
    <p class="muted"><?= e($S['cart.empty'] ?? '') ?></p>
    <p><a class="btn btn--brand" href="<?= e(u()) ?>"><?= e($S['cart.empty_cta'] ?? '') ?></a></p>
  <?php else: ?>
  <?php // LE PANIER SE MET À JOUR SUR PLACE (maquette de l'équipe, « interaktywnie ») :
        // « − », « + », la corbeille, le mode de livraison et le code partent
        // en arrière-plan, et shop.js remplace ce bloc [data-koszyk] par celui
        // que le serveur renvoie. Les montants restent calculés ICI, jamais
        // dans le navigateur. Sans JavaScript, chaque bouton est un vrai
        // envoi de formulaire et la page se recharge : rien ne manque. ?>
  <div class="koszyk-zywy" data-koszyk>
  <?php $m = $q['method'] ?? null; $dostId = (string) ($m['id'] ?? ''); $kasaHref = u('kasa', ['dostawa' => $m['id'] ?? 'inpost_locker']); ?>
  <?php // LE SAC, COMME CHEZ APPLE : le montant dans le titre, le bouton de la
        // caisse tout de suite — on n'a pas à descendre pour payer. ?>
  <header class="torba-glowa">
    <h1><?= e(str_replace('{x}', zl($q['total_gross']), $S['cart.title_sum'] ?? '{x}')) ?></h1>
    <?php if (($S['promise.1.d'] ?? '') !== ''): ?><p><?= e((string) $S['promise.1.d']) ?></p><?php endif; ?>
    <a class="btn btn--accent btn--lg" href="<?= e($kasaHref) ?>"><?= e($S['cart.checkout'] ?? '') ?></a>
  </header>
  <?php if (!empty($q['backorder'])) notice('warn', $S['cart.backorder'] ?? ''); ?>

  <div class="torba-info">
    <?php // LA LIVRAISON GRATUITE EN IMAGE : verte avec le colis quand le seuil
          // est atteint, une barre et le montant qui manque sinon. Le seuil
          // est celui du mode choisi — celui que la caisse appliquera. Un
          // bon « darmowa wysyłka » le dit en ses propres mots : écrire
          // « próg osiągnięty » sous un panier de 40 zł serait faux.
          $prog = $m ? (int) $m['free_from'] : 0; ?>
    <?php if ($q['shipping_free']): ?>
    <div class="darmowa darmowa--ok">
      <span class="darmowa-znak"><?= ikona_ui('ok') ?></span>
      <p><?= e(($prog > 0 && $q['items_gross'] >= $prog) || empty($q['voucher']['free_shipping'])
               ? ($S['cart.free_ok'] ?? '') : ($S['cart.voucher_ship'] ?? '')) ?></p>
      <?= ilustracja_paczki(true) ?>
    </div>
    <?php elseif ($prog > 0): ?>
    <div class="darmowa">
      <div class="darmowa-tresc">
        <p><?= zdanie_html(str_contains($S['cart.free_left'] ?? '', '[') ? ($S['cart.free_left'] ?? '') : str_replace('{x}', '[{x}]', $S['cart.free_left'] ?? ''),
                           ['{x}' => zl(max(0, $prog - $q['items_gross']))]) ?></p>
        <div class="prog-pasek prog-pasek--dostawa" aria-hidden="true"><i data-pasek="dostawa" style="width:<?= max(0, min(100, (int) floor($q['items_gross'] * 100 / $prog))) ?>%"></i></div>
      </div>
      <?= ilustracja_paczki(false) ?>
    </div>
    <?php endif; ?>

    <?php // LE PALIER SUIVANT, avec ce qui manque EN KILOS — la même boîte que
          // sur la fiche produit. Elle se tait quand un code ou un compte
          // professionnel donne déjà plus que ce palier : promettre 5 % à
          // qui a 10 % ferait croire qu'il perd quelque chose.
          $progi = progi_rabatu($pdo);
          $stK = stan_progu($progi, (int) $q['weight_g']);
          $maTeraz = (float) ($q['discount_percent'] ?? 0);
          if ($progi && ($stK['next'] ? $stK['next']['p'] > $maTeraz
                                      : ($stK['have'] > 0 && ($q['discount_source'] ?? 'waga') === 'waga'))): ?>
    <div class="prog-rabat prog-rabat--koszyk">
      <?= ikona_prezentu() ?>
      <div class="prog-tresc">
        <p class="prog-zdanie"><?= $stK['next']
            ? zdanie_html($S['cart.tier_next'] ?? '', ['{x}' => kg_txt($stK['missing']), '{p}' => procent_txt($stK['next']['p'])])
            : zdanie_html($S['product.tier_top'] ?? '', ['{p}' => procent_txt($stK['have'])]) ?></p>
        <div class="prog-pasek" aria-hidden="true"><i data-pasek="rabat" style="width:<?= (int) $stK['pct'] ?>%"></i></div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="cart">
    <form class="cart-lines" method="post" action="<?= e(u('koszyk')) ?>" data-koszyk-form>
      <?= csrf_field() ?>
      <input type="hidden" name="dostawa" value="<?= e($dostId) ?>">
      <?php // Le bouton par défaut du formulaire : « Entrée » dans un champ de
            // quantité applique les quantités. Sans lui, le navigateur
            // prendrait le PREMIER bouton — le « − » de la première ligne. ?>
      <button class="sr-only" type="submit" tabindex="-1" aria-hidden="true">↻</button>
      <?php foreach ($q['lines'] as $l): ?>
      <div class="cart-line">
        <a class="cart-thumb" href="<?= e(u('p/' . $l['slug'])) ?>">
          <?= product_visual(['image' => $l['image'], 'name' => $l['name'], 'from' => $l['from'], 'to' => $l['to'], 'cocoa' => '', 'unit' => ''], 'thumb') ?>
        </a>
        <div class="cart-info">
          <h2><a href="<?= e(u('p/' . $l['slug'])) ?>"><?= e($l['name']) ?></a></h2>
          <p class="muted"><?php if ((string) $l['unit'] !== ''): ?><?= e($l['unit']) ?> · <?php endif; ?><?= e(zl($l['unit_gross'])) ?> <?= e($S['price.vat_incl'] ?? '') ?></p>
        </div>
        <div class="stepper">
          <button class="stepper-btn" type="submit" name="krok[<?= e($l['id']) ?>]" value="-1"
                  aria-label="<?= e(($S['cart.minus'] ?? '−') . ': ' . $l['name']) ?>"><?= ikona_ui('minus') ?></button>
          <label class="qty">
            <span class="sr-only"><?= e($S['cart.qty'] ?? '') ?></span>
            <input type="number" name="set[<?= e($l['id']) ?>]" value="<?= (int) $l['qty'] ?>" min="0" max="<?= (int) WSM_SHOP_MAX_QTY ?>" inputmode="numeric">
          </label>
          <button class="stepper-btn" type="submit" name="krok[<?= e($l['id']) ?>]" value="1"
                  aria-label="<?= e(($S['cart.plus'] ?? '+') . ': ' . $l['name']) ?>"<?= (int) $l['qty'] >= WSM_SHOP_MAX_QTY ? ' disabled' : '' ?>><?= ikona_ui('plus') ?></button>
        </div>
        <div class="cart-kwota">
          <span class="cart-sum"><?= e(zl($l['line_gross'])) ?></span>
          <button class="cart-kosz" type="submit" name="remove" value="<?= e($l['id']) ?>"
                  aria-label="<?= e(($S['cart.remove'] ?? '') . ': ' . $l['name']) ?>"><?= ikona_ui('kosz') ?><span><?= e($S['cart.remove'] ?? '') ?></span></button>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="cart-actions">
        <a class="btn btn--ghost btn--wstecz" href="<?= e(u()) ?>"><?= ikona_ui('wstecz') ?><?= e($S['cart.continue'] ?? '') ?></a>
        <?php // Sans JavaScript il faut un bouton pour appliquer les quantités ;
              // avec, shop.js envoie tout seul et ce bouton n'aurait rien à dire. ?>
        <noscript><button class="btn btn--ghost" type="submit"><?= e($S['cart.qty'] ?? '') ?> ↻</button></noscript>
      </div>
    </form>

    <div class="torba-dol">
      <?php // LES TRANSPORTEURS EN IMAGE : la consigne InPost, le fourgon InPost,
            // le fourgon DPD (znaki.php, ilustracja_dostawy). « Gratis » est
            // écrit sur CHAQUE mode que le panier rend gratuit, pas seulement
            // sur celui qui est coché : le client compare, il doit voir juste. ?>
      <section class="torba-dostawa" aria-labelledby="dostawa-h">
        <h2 class="kup-tytul" id="dostawa-h"><?= e($S['cart.ship_title'] ?? '') ?> <span><?= e($S['cart.ship_sub'] ?? '') ?></span></h2>
        <form class="ship-pick" method="get" action="<?= e(u('koszyk')) ?>" data-koszyk-form>
          <fieldset>
            <legend class="sr-only"><?= e($S['cart.shipping'] ?? '') ?></legend>
            <?php foreach ($q['methods'] as $sm):
              $wybrana = $m && $sm['id'] === $m['id'];
              $gratis = $wybrana ? (bool) $q['shipping_free']
                      : (!empty($q['voucher']['free_shipping']) || ($sm['free_from'] > 0 && $q['items_gross'] >= $sm['free_from'])); ?>
            <label class="dostawa-opcja<?= $wybrana ? ' is-on' : '' ?>">
              <input type="radio" name="dostawa" value="<?= e($sm['id']) ?>"<?= $wybrana ? ' checked' : '' ?> data-od-razu>
              <span class="dostawa-obraz"><?= ilustracja_dostawy((string) $sm['carrier'], (string) $sm['kind']) ?></span>
              <span class="dostawa-txt"><strong><?= e($sm['label']) ?></strong><?php if ($sm['note'] !== ''): ?><small><?= e($sm['note']) ?></small><?php endif; ?></span>
              <em class="dostawa-cena<?= $gratis ? ' is-gratis' : '' ?>"><?= $gratis ? e($S['cart.free'] ?? '') : e(zl($sm['price'])) ?></em>
            </label>
            <?php endforeach; ?>
          </fieldset>
          <noscript><button class="btn btn--ghost btn--sm" type="submit">↻</button></noscript>
        </form>
      </section>

      <aside class="summary" aria-label="<?= e($S['checkout.summary'] ?? '') ?>">
        <?php // Le code de réduction, replié comme chez Apple (« Masz kod
              // rabatowy? »). Ouvert d'office quand un code est posé ou refusé :
              // le motif du refus doit se voir sans chercher. ?>
        <?php $kodCourant = voucher_read(); $kodOtwarty = ($q['voucher']['applied'] ?? false) || ($q['voucher_error'] ?? '') !== '' || $kodCourant !== ''; ?>
        <details class="voucher-zw"<?= $kodOtwarty ? ' open' : '' ?>>
          <summary><?= e($S['cart.voucher_q'] ?? ($S['cart.voucher'] ?? '')) ?></summary>
          <form class="voucher" method="post" action="<?= e(u('koszyk')) ?>" data-koszyk-form>
            <?= csrf_field() ?>
            <input type="hidden" name="dostawa" value="<?= e($dostId) ?>">
            <?php if (($q['voucher']['applied'] ?? false)): ?>
            <p class="voucher-on">
              <strong><?= e($q['voucher']['code']) ?></strong>
              <span><?= e($q['voucher']['kind'] === 'wysylka'
                         ? ($S['cart.voucher_ship'] ?? '') : $q['voucher']['label']) ?></span>
              <button class="linkbtn" type="submit" name="kod_drop" value="1"><?= e($S['cart.voucher_drop'] ?? '') ?></button>
            </p>
            <?php else: ?>
            <p class="field<?= ($q['voucher_error'] ?? '') !== '' ? ' has-error' : '' ?>">
              <label for="f-kod" class="sr-only"><?= e($S['cart.voucher'] ?? '') ?></label>
              <span class="voucher-row">
                <input id="f-kod" name="kod" type="text" value="<?= e($kodCourant) ?>"
                       placeholder="<?= e($S['cart.voucher_ph'] ?? '') ?>"
                       autocomplete="off" spellcheck="false" maxlength="40"
                       <?= ($q['voucher_error'] ?? '') !== '' ? ' aria-invalid="true" aria-describedby="f-kod-e"' : '' ?>>
                <button class="btn btn--ghost btn--sm" type="submit" name="kod_set" value="1"><?= e($S['cart.voucher_apply'] ?? '') ?></button>
              </span>
              <?php if (($q['voucher_error'] ?? '') !== ''): ?>
              <small class="err" id="f-kod-e"><?= e($q['voucher_error']) ?></small>
              <?php endif; ?>
            </p>
            <?php endif; ?>
          </form>
        </details>

        <dl class="totals">
          <dt><?= e($S['cart.subtotal'] ?? '') ?></dt><dd><?= e(zl($q['items_gross'])) ?></dd>
          <?php if (($q['discount_percent'] ?? 0) > 0): ?>
          <?php // Le libellé dit D'OÙ vient la remise. « Rabat ilościowy » sur une
                // remise venue d'un code nommait la mauvaise raison : celui qui
                // retire son code ne comprenait pas que le montant ne bouge pas. ?>
          <dt class="disc"><?= e(($q['discount_source'] ?? 'waga') === 'waga'
                ? ($S['cart.discount'] ?? '')
                : (string) ($q['discount_label'] ?? ($S['cart.discount'] ?? ''))) ?> −<?= e(procent_txt((float) $q['discount_percent'])) ?> %</dt>
          <dd class="disc">−<?= e(zl($q['discount_amount'])) ?></dd>
          <?php endif; ?>
          <?php // Le bon en MONTANT a sa propre ligne : additionné à la remise
                // au poids il deviendrait illisible, et l'acheteur ne saurait
                // plus lequel des deux il perd s'il retire son code. ?>
          <?php if ((int) ($q['voucher']['amount'] ?? 0) > 0): ?>
          <dt class="disc"><?= e($S['cart.voucher_line'] ?? '') ?> <?= e($q['voucher']['code']) ?></dt>
          <dd class="disc">−<?= e(zl((int) $q['voucher']['amount'])) ?></dd>
          <?php endif; ?>
          <dt><?= e($S['cart.shipping'] ?? '') ?></dt>
          <dd<?= $q['shipping_gross'] === 0 ? ' class="disc"' : '' ?>><?= $q['shipping_gross'] === 0 ? e($S['cart.free'] ?? '') : e(zl($q['shipping_gross'])) ?></dd>
          <dt class="grand"><?= e($S['cart.total'] ?? '') ?></dt><dd class="grand"><?= e(zl($q['total_gross'])) ?></dd>
          <dt class="small"><?= e($S['cart.vat_of'] ?? '') ?></dt><dd class="small"><?= e(zl($q['total_vat'])) ?></dd>
          <dt class="small"><?= e($S['cart.weight'] ?? '') ?></dt><dd class="small"><?= e(kg_txt((int) $q['weight_g'])) ?></dd>
        </dl>
        <?php if (isset($qErr['weight_g'])) notice('warn', $qErr['weight_g']); ?>
        <a class="btn btn--accent btn--lg btn--block btn--kasa" href="<?= e($kasaHref) ?>"><?= e($S['cart.checkout'] ?? '') ?><?= ikona_ui('dalej') ?></a>
      </aside>
    </div>

    <?php // L'AIDE, sous le récapitulatif : le téléphone, les retours. ?>
    <?php $telK = trim((string) ($S['footer.phone'] ?? '')); ?>
    <div class="torba-pomoc">
      <?php if (tel_href($telK) !== ''): ?>
      <p><b><?= e($S['cart.help_title'] ?? '') ?></b><?= e($S['cart.help_text'] ?? '') ?> <a href="<?= e(tel_href($telK)) ?>"><?= e($telK) ?></a></p>
      <?php endif; ?>
      <p><b><?= e($S['cart.returns_title'] ?? '') ?></b><?= e($S['cart.returns_text'] ?? '') ?> <a href="<?= e(u('zwroty')) ?>"><?= e($S['returns.footer_link'] ?? '') ?> ›</a></p>
    </div>
  </div>
  <?php // Les suggestions vivent ICI et pas sur la fiche produit, qui a déjà
        // sa rangée « podobne ». Deux blocs de propositions sur la même page
        // se neutralisent : on ne choisit plus, on fait défiler. Elles sont
        // DANS le bloc vivant : un produit ajouté d'ici entre dans le panier
        // au-dessus sans recharger la page.
        upsell_block($pdo, array_keys($cart), $lang, $S); ?>
  </div>
  <?php endif; ?>
</main>
<?php
    layout_footer($S);
    exit;
}

// -------------------------------------------------------------------- CAISSE -
if ($page === 'kasa') {
    // Le jeton PUBLIC du sélecteur de Paczkomat vit dans l'adaptateur InPost.
    // Chargé ici seulement : c'est la seule page qui en a besoin.
    $gf = $WSM_API_DIR . '/inpost.php';
    if (is_file($gf)) require_once $gf;

    $errors = $formErrors ?? [];
    $v = $formValues ?? [];
    $shipId  = (string) ($v['delivery_method'] ?? ($_GET['dostawa'] ?? 'inpost_locker'));
    $shipCty = (string) ($v['ship_country'] ?? ($_GET['kraj'] ?? ''));
    [$q, ] = wsm_shop_quote($pdo, cart_items($cart), $shipId, $lang,
        ['country' => $shipCty, 'vat_eu' => (string) ($v['vat_eu'] ?? ''),
         'email' => (string) ($v['email'] ?? ''), 'voucher' => voucher_read()]);

    /** Champ de formulaire : valeur réaffichée, erreur montrée sous le champ. */
    $field = function (string $name, string $label, array $opt = []) use ($v, $errors, $S) {
        $type = $opt['type'] ?? 'text';
        $err  = $errors[$name] ?? '';
        $val  = (string) ($v[$name] ?? ($opt['value'] ?? ''));
        $id   = 'f-' . $name;
        echo '<p class="field' . ($err ? ' has-error' : '') . ($opt['wide'] ?? false ? ' wide' : '') . '">';
        echo '<label for="' . e($id) . '">' . e($label) . ($opt['required'] ?? true ? ' <span aria-hidden="true">*</span>' : '') . '</label>';
        echo '<input id="' . e($id) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($val) . '"'
           . (($opt['required'] ?? true) ? ' required' : '')
           . (isset($opt['autocomplete']) ? ' autocomplete="' . e($opt['autocomplete']) . '"' : '')
           . (isset($opt['inputmode']) ? ' inputmode="' . e($opt['inputmode']) . '"' : '')
           . (isset($opt['placeholder']) ? ' placeholder="' . e($opt['placeholder']) . '"' : '')
           // La longueur de la colonne, dite au navigateur : on ne tape pas
           // ce que la base refusera (le serveur le vérifie de toute façon).
           . (defined('WSM_BUYER_MAX') && isset(WSM_BUYER_MAX[$name]) ? ' maxlength="' . (int) WSM_BUYER_MAX[$name] . '"' : '')
           . ($err ? ' aria-invalid="true" aria-describedby="' . e($id) . '-e"' : '') . '>';
        if (isset($opt['hint'])) echo '<small class="hint">' . e($opt['hint']) . '</small>';
        if ($err) echo '<small class="err" id="' . e($id) . '-e">' . e($err) . '</small>';
        echo '</p>';
    };

    if ($q && function_exists('wsm_ads_item')) {
        ads_event(['n' => 'begin_checkout', 'value' => round(((int) $q['total_gross']) / 100, 2),
                   'items' => array_map(fn($l) => ['id' => (string) $l['id'], 'name' => (string) $l['name'],
                                                   'price' => round(((int) $l['unit_gross']) / 100, 2), 'qty' => (int) $l['qty']],
                                        (array) ($q['lines'] ?? []))]);
    }
    layout_head($S, $lang, $langs, $S['checkout.title'] ?? '', '', 'kasa');
    layout_header($S, $lang, $langs, $cartCount);
    ?>
<main class="wrap block">
  <?php layout_crumbs([
      ($S['nav.shop'] ?? 'Sklep') => u(),
      ($S['cart.title'] ?? 'Koszyk') => u('koszyk'),
      ($S['checkout.title'] ?? 'Kasa') => null,
  ]); ?>
  <h1><?= e($S['checkout.title'] ?? '') ?></h1>
  <?php if (!$q['lines']): ?>
    <p class="muted"><?= e($S['checkout.empty'] ?? '') ?></p>
    <p><a class="btn btn--brand" href="<?= e(u()) ?>"><?= e($S['cart.empty_cta'] ?? '') ?></a></p>
  <?php else: ?>
  <?php if ($errors) notice('error', $S['checkout.error'] ?? ''); ?>
  <div class="checkout">
    <form class="checkout-form" method="post" action="<?= e(u('kasa')) ?>" novalidate>
      <?= csrf_field() ?>

      <fieldset>
        <legend><?= e($S['checkout.contact'] ?? '') ?></legend>
        <div class="row">
          <?php $field('first_name', $S['checkout.first_name'] ?? '', ['autocomplete' => 'given-name']); ?>
          <?php $field('last_name', $S['checkout.last_name'] ?? '', ['autocomplete' => 'family-name']); ?>
        </div>
        <div class="row">
          <?php $field('email', $S['checkout.email'] ?? '', ['type' => 'email', 'autocomplete' => 'email', 'hint' => $S['checkout.email_hint'] ?? '']); ?>
          <?php $field('phone', $S['checkout.phone'] ?? '', ['type' => 'tel', 'autocomplete' => 'tel', 'inputmode' => 'numeric', 'hint' => $S['checkout.phone_hint'] ?? '']); ?>
        </div>
      </fieldset>

      <fieldset>
        <legend><?= e($S['checkout.delivery'] ?? '') ?></legend>
        <?php // Le pays commande tout : les transporteurs proposés et le régime
              // de TVA. Il est donc demandé AVANT le mode de livraison. ?>
        <p class="field<?= isset($errors['ship_country']) ? ' has-error' : '' ?>">
          <label for="f-ship_country"><?= e($S['checkout.country'] ?? '') ?> <span aria-hidden="true">*</span></label>
          <select id="f-ship_country" name="ship_country" data-country>
            <?php foreach ($q['countries'] as $c): ?>
            <option value="<?= e($c['code']) ?>"<?= $c['code'] === ($q['country'] ?? 'PL') ? ' selected' : '' ?>><?= e($c['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['ship_country'])) echo '<small class="err">' . e($errors['ship_country']) . '</small>'; ?>
          <noscript><small class="hint"><?= e($S['checkout.country_hint'] ?? '') ?></small></noscript>
        </p>
        <?php if (!$q['methods']): ?>
          <?php notice('warn', $S['checkout.no_shipping'] ?? ''); ?>
        <?php endif; ?>
        <?php foreach ($q['methods'] as $sm): ?>
        <label class="radio">
          <?php // data-kind : le type de service voyage AVEC le bouton. Sans
                // lui, le script devrait deviner d'après le nom de la méthode,
                // et se tromperait sur tout transporteur ajouté ensuite. ?>
          <input type="radio" name="delivery_method" value="<?= e($sm['id']) ?>"<?= $sm['id'] === $shipId ? ' checked' : '' ?>
                 data-ship data-kind="<?= e($sm['kind'] ?? 'adres') ?>">
          <span><strong><?= e($sm['label']) ?></strong>
            <em class="mono"><?= $q['shipping_free'] ? e($S['cart.free'] ?? '') : e(zl($sm['price'])) ?></em>
            <small><?= e($sm['note']) ?></small></span>
        </label>
        <?php endforeach; ?>

        <?php // PAS DE CHAMPS DE LIVRAISON QUAND IL N'Y A PAS DE LIVRAISON.
              // Sans cette condition, un client allemand lisait « nous ne
              // livrons pas encore dans ce pays » et trouvait juste dessous un
              // champ « Paczkomat — KRA010 » à remplir. Les deux ne peuvent pas
              // être vrais en même temps, et c'est le champ qu'on croit. ?>
        <?php if ($q['methods']): ?>
        <?php
        // Le type du service choisi. C'est lui qui décide de ce qu'on demande :
        // un code de point, ou une adresse. Comparer l'identifiant à
        // « inpost_courier » marchait tant qu'il n'y avait qu'InPost — et
        // affichait le champ Paczkomat pour un coursier DPD.
        $kindSel = 'adres';
        foreach ($q['methods'] as $sm) if ($sm['id'] === $shipId) $kindSel = $sm['kind'] ?? 'adres';
        ?>
        <div class="ship-locker"<?= $kindSel === 'punkt' ? '' : ' hidden' ?> data-ship-locker>
          <?php $field('inpost_point', $S['checkout.point'] ?? '', ['hint' => $S['checkout.point_hint'] ?? '', 'placeholder' => 'KRA010']); ?>
          <?php
          // LE SÉLECTEUR DE PACZKOMAT SUR CARTE.
          //
          // Sans lui, on demandait au client d'aller chercher « KRA010 » sur
          // inpost.pl et de revenir le taper. Quitter une caisse pour trouver
          // un code ailleurs, c'est l'endroit exact où l'on abandonne un
          // panier — et un code recopié de travers, c'est un colis qui part
          // au mauvais casier.
          //
          // C'est un ENRICHISSEMENT, pas un remplacement : le champ texte
          // reste au-dessus et garde la valeur. Sans jeton, ou sans
          // JavaScript, la caisse fonctionne exactement comme avant.
          $geo = function_exists('wsm_inpost_geowidget_token') ? wsm_inpost_geowidget_token() : '';
          if ($geo !== '' && strtolower($geo) !== 'xxxx'):
          ?>
          <div class="geo" data-geo hidden>
            <button type="button" class="btn btn--ghost btn--sm" data-geo-open>
              <?= e($S['checkout.point_map'] ?? '') ?></button>
            <p class="geo-chosen mono" data-geo-chosen hidden></p>
            <?php // Montré uniquement si la carte ne vient pas : sinon on
                  // annonce une panne qui n'a pas eu lieu. ?>
            <p class="geo-fail" data-geo-fail hidden><?= e($S['checkout.point_map_fail'] ?? '') ?></p>
            <div class="geo-box" data-geo-box hidden>
              <inpost-geowidget token="<?= e($geo) ?>" language="<?= e($lang) ?>"
                                config="parcelCollect" onpoint="wsmGeoPoint"></inpost-geowidget>
            </div>
            <?php
            // LA SORTIE DE SECOURS, MONTRÉE DÈS QUE LA CARTE EST OUVERTE.
            //
            // Le repli au-dessus ne se déclenche que si la carte n'ARRIVE PAS.
            // Il existe un troisième cas, et c'est celui qu'on a rencontré : la
            // carte arrive, se dessine, et affiche à la place « Brak dostępu,
            // sprawdź czy token został wygenerowany dla odpowiedniej witryny ».
            // Le composant est défini, la boîte a sa hauteur : aucune de nos
            // détections ne bronche, et le client reste devant un refus en
            // polonais sans savoir qu'il peut simplement taper son code.
            //
            // On ne cherche pas à reconnaître leur message — il est chez eux,
            // dans trois langues, et il changera. On rend la sortie visible en
            // permanence : c'est vrai quand la carte marche (certains préfèrent
            // taper), et ça sauve la commande quand elle refuse.
            ?>
            <p class="geo-help" data-geo-help hidden><?= e($S['checkout.point_map_help'] ?? '') ?></p>
          </div>
          <?php // Chargés ICI et nulle part ailleurs : uniquement sur la caisse,
                // uniquement quand le jeton existe. Une boutique qui n'utilise
                // pas les Paczkomat ne doit pas payer une requête vers un
                // domaine tiers sur chacune de ses pages. ?>
          <link rel="stylesheet" href="https://geowidget.inpost.pl/inpost-geowidget.css">
          <script src="https://geowidget.inpost.pl/inpost-geowidget.js" defer></script>
          <?php endif; ?>
        </div>
        <div class="ship-courier"<?= $kindSel === 'punkt' ? ' hidden' : '' ?> data-ship-courier>
          <div class="row">
            <?php $field('ship_street', $S['checkout.street'] ?? '', ['autocomplete' => 'address-line1', 'required' => false]); ?>
            <?php $field('ship_building', $S['checkout.building'] ?? '', ['required' => false]); ?>
          </div>
          <div class="row">
            <?php $field('ship_postcode', $S['checkout.postcode'] ?? '', ['autocomplete' => 'postal-code', 'placeholder' => '00-000', 'required' => false]); ?>
            <?php $field('ship_city', $S['checkout.city'] ?? '', ['autocomplete' => 'address-level2', 'required' => false]); ?>
          </div>
        </div>
        <?php endif; ?>
      </fieldset>

      <fieldset>
        <legend><?= e($S['checkout.invoice'] ?? '') ?></legend>
        <label class="check">
          <input type="checkbox" name="invoice" value="1"<?= !empty($v['invoice']) ? ' checked' : '' ?> data-invoice>
          <span><?= e($S['checkout.want_invoice'] ?? '') ?><small><?= e($S['checkout.invoice_hint'] ?? '') ?></small></span>
        </label>
        <?php // L'ACCORD POUR L'E-PARAGON (art. 111 ust. 3a ustawy o VAT) :
              // proposé seulement quand une caisse fiscale est branchée, et
              // DÉCOCHÉ d'office — un accord se donne, il ne se présume pas.
              // Sans la case, c'est le paragon papier, glissé dans le colis.
              // Après une erreur de formulaire, on garde ce que le client
              // avait choisi. ?>
        <?php if (kasa_fiskalna()): ?>
        <input type="hidden" name="e_paragon_pole" value="1">
        <label class="check">
          <input type="checkbox" name="e_paragon" value="1"<?= !empty($v['e_paragon']) ? ' checked' : '' ?>>
          <span><?= e($S['checkout.ereceipt'] ?? '') ?><small><?= e($S['checkout.ereceipt_hint'] ?? '') ?></small></span>
        </label>
        <?php endif; ?>
        <div class="invoice-fields"<?= !empty($v['invoice']) ? '' : ' hidden' ?> data-invoice-fields>
          <div class="row">
            <?php $field('company', $S['checkout.company'] ?? '', ['required' => false]); ?>
            <?php $field('nip', $S['checkout.nip'] ?? '', ['required' => false, 'inputmode' => 'numeric', 'placeholder' => '5252248481']); ?>
          </div>
          <?php $field('vat_eu', $S['checkout.vat_eu'] ?? '', ['required' => false, 'placeholder' => 'PL5252248481']); ?>

          <?php // ─── REMPLIR DEPUIS LE REGISTRE ────────────────────────────
                // Une adresse recopiee a la main sur une facture, c'est une
                // correction de facture la semaine suivante. Le bouton poste
                // le formulaire ; le serveur lit le registre et renvoie la
                // page remplie. Aucun JavaScript : sur le telephone qui bloque
                // les scripts, il marche exactement pareil. ?>
          <p class="reg-ligne">
            <button class="btn btn--ghost" type="submit" name="pobierz_dane" value="1"
                    formnovalidate><?= e($S['reg.cta'] ?? 'Pobierz dane z rejestru') ?></button>
            <small><?= e($S['reg.hint'] ?? '') ?></small>
          </p>
          <?php if (isset($regInfo) && $regInfo !== '') notice($regKind ?? 'warn', $regInfo); ?>

          <p class="mono muted"><?= e($S['checkout.bill_address'] ?? '') ?></p>
          <div class="row">
            <?php $field('bill_street', $S['checkout.street'] ?? '', ['required' => false]); ?>
            <?php $field('bill_building', $S['checkout.building'] ?? '', ['required' => false]); ?>
          </div>
          <div class="row">
            <?php $field('bill_postcode', $S['checkout.postcode'] ?? '', ['required' => false, 'placeholder' => '00-000']); ?>
            <?php $field('bill_city', $S['checkout.city'] ?? '', ['required' => false]); ?>
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend><?= e($S['checkout.note'] ?? '') ?></legend>
        <p class="field wide">
          <label for="f-note" class="sr-only"><?= e($S['checkout.note'] ?? '') ?></label>
          <textarea id="f-note" name="note" rows="3" maxlength="500"><?= e((string) ($v['note'] ?? '')) ?></textarea>
        </p>
        <p class="field<?= isset($errors['consent_terms']) ? ' has-error' : '' ?>">
          <label class="check">
            <input type="checkbox" name="consent_terms" value="1"<?= !empty($v['consent_terms']) ? ' checked' : '' ?>>
            <?php // LA CASE POINTE ENFIN SUR QUELQUE CHOSE. Elle disait
                  // « Akceptuję regulamin i politykę prywatności » sans qu'aucun
                  // des deux documents n'existe : on faisait accepter le vide,
                  // et c'est ce que l'opérateur de paiement a relevé.
                  //
                  // target="_blank" : le panier est rempli, le formulaire aussi.
                  // Ouvrir le règlement dans le MÊME onglet ferait perdre la
                  // saisie à qui veut simplement lire ce qu'il accepte — donc
                  // apprendrait à cocher sans lire. ?>
            <?php // LES LIBELLES DE LA PHRASE NE SONT PAS CEUX DES PAGES. Le
                  // titre d'une page est au nominatif ; dans une phrase le
                  // polonais le decline. Reutiliser le titre donnait
                  // « zapoznałem się z Polityka prywatności » — une faute que
                  // tout client polonais voit, sur la ligne meme ou on lui
                  // demande sa confiance. ?>
            <span><?= e($S['checkout.terms_a'] ?? '') ?>
              <a href="<?= e(u('regulamin')) ?>" target="_blank" rel="noopener"><?= e($S['checkout.terms_l1'] ?? '') ?></a>
              <?= e($S['checkout.terms_b'] ?? '') ?>
              <a href="<?= e(u('prywatnosc')) ?>" target="_blank" rel="noopener"><?= e($S['checkout.terms_l2'] ?? '') ?></a>.</span>
          </label>
          <?php if (isset($errors['consent_terms'])) echo '<small class="err">' . e($errors['consent_terms']) . '</small>'; ?>
        </p>
        <?php // LE MARKETING SE DEMANDE, IL NE SE PRÉSUME PAS : deux cases, vides,
              // séparées de celle du règlement. La newsletter passera encore par
              // la confirmation du mail ; le SMS part au numéro de la commande. ?>
        <?php $zNl = (string) ($S['checkout.nl_optin'] ?? ''); $zSms = (string) ($S['checkout.sms_optin'] ?? ''); ?>
        <?php if ($zNl !== ''): ?>
        <label class="check"><input type="checkbox" name="zgoda_nl" value="1"<?= !empty($v['zgoda_nl']) ? ' checked' : '' ?>>
          <span><?= e($zNl) ?></span></label>
        <?php endif; ?>
        <?php if ($zSms !== ''): ?>
        <label class="check"><input type="checkbox" name="zgoda_sms" value="1"<?= !empty($v['zgoda_sms']) ? ' checked' : '' ?>>
          <span><?= e($zSms) ?></span></label>
        <?php endif; ?>
      </fieldset>

      <button class="btn btn--accent btn--lg btn--block" type="submit"><?= e($S['checkout.submit'] ?? '') ?></button>
      <p class="mono muted pay-info"><?= e($S['checkout.pay_info'] ?? '') ?></p>
    </form>

    <aside class="summary">
      <h2><?= e($S['checkout.summary'] ?? '') ?></h2>
      <?php if (!empty($q['backorder'])) notice('warn', $S['cart.backorder'] ?? ''); ?>
      <ul class="sum-lines">
        <?php foreach ($q['lines'] as $l): ?>
        <li><span><?= e($l['name']) ?> <em class="mono">×<?= (int) $l['qty'] ?></em></span><span class="mono"><?= e(zl($l['line_gross'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <dl class="totals mono">
        <dt><?= e($S['cart.subtotal'] ?? '') ?></dt><dd><?= e(zl($q['items_gross'])) ?></dd>
        <?php if (($q['discount_percent'] ?? 0) > 0): ?>
        <?php // Le libellé dit D'OÙ vient la remise. « Rabat ilościowy » sur une
              // remise venue d'un code nommait la mauvaise raison : celui qui
              // retire son code ne comprenait pas que le montant ne bouge pas. ?>
        <dt class="disc"><?= e(($q['discount_source'] ?? 'waga') === 'waga'
              ? ($S['cart.discount'] ?? '')
              : (string) ($q['discount_label'] ?? ($S['cart.discount'] ?? ''))) ?> −<?= (int) $q['discount_percent'] ?> %</dt>
        <dd class="disc">−<?= e(zl($q['discount_amount'])) ?></dd>
        <?php endif; ?>
        <?php // Le bon en MONTANT a sa propre ligne : additionné à la remise
              // au poids il deviendrait illisible, et l'acheteur ne saurait
              // plus lequel des deux il perd s'il retire son code. ?>
        <?php if ((int) ($q['voucher']['amount'] ?? 0) > 0): ?>
        <dt class="disc"><?= e($S['cart.voucher_line'] ?? '') ?> <?= e($q['voucher']['code']) ?></dt>
        <dd class="disc">−<?= e(zl((int) $q['voucher']['amount'])) ?></dd>
        <?php endif; ?>
        <dt><?= e($S['cart.shipping'] ?? '') ?></dt>
        <dd><?= $q['shipping_gross'] === 0 ? e($S['cart.free'] ?? '') : e(zl($q['shipping_gross'])) ?></dd>
        <?php if (!empty($q['reverse_charge'])): ?>
        <dt class="small rc"><?= e($S['checkout.reverse_charge'] ?? '') ?></dt><dd class="small rc">0,00</dd>
        <?php else: foreach ($q['vat_breakdown'] as $vb): ?>
        <dt class="small">VAT <?= (int) round($vb['rate'] * 100) ?> %</dt><dd class="small"><?= e(zl($vb['vat'])) ?></dd>
        <?php endforeach; endif; ?>
        <dt class="grand"><?= e($S['cart.total'] ?? '') ?></dt><dd class="grand"><?= e(zl($q['total_gross'])) ?></dd>
      </dl>
    </aside>
  </div>
  <?php endif; ?>
</main>
<?php
    layout_footer($S);
    exit;
}

// ------------------------------------------------------ PAGES JURIDIQUES ----
//
// Regulamin et Polityka Prywatności. Deux pages, une seule mécanique : le texte
// vient de la base (donc corrigeable en console), les chiffres viennent de la
// configuration réelle (donc jamais en contradiction avec la caisse).
//
// LE CORPS EST EN POLONAIS, QUELLE QUE SOIT LA LANGUE DU VISITEUR, et c'est un
// choix, pas un oubli : un règlement traduit approximativement est un règlement
// dont on ne sait plus lequel fait foi. Le titre et l'avertissement, eux,
// parlent la langue du visiteur — et l'avertissement DIT que la version
// polonaise est celle qui engage.
if ($page === 'regulamin' || $page === 'prywatnosc') {
    $prefixe = $page === 'regulamin' ? 'terms' : 'privacy';
    $titre   = (string) ($S[$page === 'regulamin' ? 'legal.terms' : 'legal.privacy'] ?? '');
    $pl      = $lang === 'pl' ? $S : wsm_shop_strings($pdo, 'pl');
    $vals    = legal_valeurs($pdo, $pl);
    $sections = legal_sections($pl, $prefixe, $vals);
    $quand   = (string) ($pl[$prefixe . '.updated'] ?? '');

    layout_head($S, $lang, $langs, $titre, '', $page);
    layout_header($S, $lang, $langs, $cartCount);
    ?>
<main class="wrap block legal">
  <h1><?= e($titre) ?></h1>
  <?php if ($quand !== ''): ?>
  <p class="mono muted"><?= e(str_replace('{data}', $quand, (string) ($S['legal.updated'] ?? ''))) ?></p>
  <?php endif; ?>
  <?php if (($S['legal.pl_only'] ?? '') !== ''): ?>
    <?php notice('warn', (string) $S['legal.pl_only']); ?>
  <?php endif; ?>

  <?php if (!$sections): ?>
  <p class="muted">Dokument nie został jeszcze uzupełniony.</p>
  <?php endif; ?>

  <?php // La numérotation est POSÉE ICI, pas tapée dans le texte : on peut
        // insérer une section sans renuméroter les onze suivantes à la main. ?>
  <ol class="legal-spis">
    <?php foreach ($sections as $i => [$t, ]): ?>
    <li><a href="#p<?= $i + 1 ?>"><?= e($t) ?></a></li>
    <?php endforeach; ?>
  </ol>

  <?php foreach ($sections as $i => [$t, $b]): ?>
  <section class="legal-sec" id="p<?= $i + 1 ?>">
    <h2><span class="nr"><?= $i + 1 ?>.</span> <?= e($t) ?></h2>
    <?= $b ?>
  </section>
  <?php endforeach; ?>

  <p class="mono muted"><?= e($S['legal.print'] ?? '') ?></p>
</main>
<?php
    layout_footer($S);
    exit;
}

// ---------------------------------------------------------------- ZWROTY ----
//
// Demandée par tpay : une page à part où le client trouve l'adresse COMPLÈTE
// pour renvoyer un colis, et comment s'y prendre. L'adresse vient de
// zwrot_adres() — la même que le règlement et le pied de page, un seul
// endroit à corriger. Les règles (14 jours, produit non ouvert, rembourse-
// ment) sont celles du règlement, redites simplement et traduites ; le
// règlement, en polonais, reste le texte qui engage, et la page y renvoie.
if ($page === 'zwroty') {
    $adr  = zwrot_adres($S);
    $mail = (string) ($S['footer.email'] ?? '');
    $t = fn(string $k) => str_replace(['{email}', '{dni}'], [$mail, '14'], (string) ($S[$k] ?? ''));

    layout_head($S, $lang, $langs, (string) ($S['returns.title'] ?? ''), $t('returns.lead'), 'zwroty');
    layout_header($S, $lang, $langs, $cartCount);
    ?>
<main class="wrap block zwroty">
  <h1><?= e($S['returns.title'] ?? '') ?></h1>
  <p class="lead"><?= e($t('returns.lead')) ?></p>
  <div class="zwroty-uklad">
    <section class="zwroty-adres" aria-labelledby="zw-adres">
      <h2 id="zw-adres"><?= e($S['returns.address'] ?? '') ?></h2>
      <address>
        <?php if ($adr['odbiorca'] !== ''): ?><strong><?= e($adr['odbiorca']) ?></strong><br><?php endif; ?>
        <?= implode('<br>', array_map(fn($l) => e($l), $adr['linie'])) ?>
      </address>
      <p class="hint"><?= e($t('returns.address_hint')) ?></p>
    </section>
    <section class="zwroty-kroki" aria-labelledby="zw-kroki">
      <h2 id="zw-kroki"><?= e($S['returns.steps'] ?? '') ?></h2>
      <ol>
        <?php for ($i = 1; $i <= 6; $i++): if (trim((string) ($S["returns.step.$i"] ?? '')) === '') continue; ?>
        <li><?= e($t("returns.step.$i")) ?></li>
        <?php endfor; ?>
      </ol>
      <p class="muted"><?= e($t('returns.food')) ?></p>
    </section>
  </div>
  <section class="zwroty-reklamacja">
    <h2><?= e($S['returns.claim.title'] ?? '') ?></h2>
    <p><?= e($t('returns.claim.text')) ?></p>
  </section>
  <p class="zwroty-cta">
    <a class="btn btn--brand" href="<?= e(u('moje-zamowienie')) ?>"><?= e($S['returns.cta.order'] ?? '') ?></a>
    <a class="btn btn--ghost" href="<?= e(u('regulamin')) ?>"><?= e($S['returns.cta.terms'] ?? '') ?></a>
  </p>
</main>
<?php
    layout_footer($S);
    exit;
}

// ------------------------------------------------- RETROUVER SA COMMANDE ----
//
// Le lien signé du mail de confirmation reste la voie normale. Celle-ci existe
// pour le jour où ce mail a disparu — et pour le client qui cherche « gdzie
// moje zamówienie » avant de penser à fouiller sa boîte.
//
// ON N'AFFICHE RIEN ICI. Le couple numéro + e-mail reconnu REDIRIGE vers la
// page de commande existante, avec son jeton. Une deuxième page qui afficherait
// une commande, c'est un deuxième endroit à tenir juste — et le premier qui
// oubliera d'y masquer une donnée.
if ($page === 'moje-zamowienie') {
    $tErr = ''; $tKod = ''; $tMail = '';
    if ($method === 'POST') {
        $tKod  = (string) ($_POST['kod'] ?? '');
        $tMail = (string) ($_POST['email'] ?? '');
        $ip    = wsm_ip_appelant();
        if (wsm_suivi_trop($pdo, $ip)) {
            $tErr = (string) ($S['track.limit'] ?? '');
        } else {
            $trouve = wsm_suivi_cherche($pdo, $tKod, $tMail);
            if ($trouve) {
                redirect(u('zamowienie/' . rawurlencode($trouve['code']), ['t' => $trouve['token']]));
            }
            // UN SEUL MESSAGE POUR LES DEUX ÉCHECS. Dire « ce numéro n'existe
            // pas » d'un côté et « mauvaise adresse » de l'autre, c'est offrir
            // un détecteur de numéros valides : on essaie des numéros jusqu'à
            // changer de message, puis on s'attaque à l'adresse.
            wsm_suivi_echec($pdo, $ip, $tKod);
            $tErr = (string) ($S['track.notfound'] ?? '');
        }
    }
    layout_head($S, $lang, $langs, $S['track.title'] ?? '', $S['track.lead'] ?? '', 'moje-zamowienie');
    layout_header($S, $lang, $langs, $cartCount);
    ?>
<main class="wrap block">
  <h1><?= e($S['track.title'] ?? '') ?></h1>
  <p class="lead"><?= e($S['track.lead'] ?? '') ?></p>

  <?php if ($tErr !== ''): ?>
  <p class="notice notice--error" role="alert"><?= e($tErr) ?></p>
  <?php endif; ?>

  <form method="post" class="checkout-form form--suivi">
    <?php // SANS CE JETON, le formulaire ne part pas : la boutique refuse tout
          // POST sans lui, avec un « Bad request » nu et une page blanche. La
          // page s'affichait parfaitement et le bouton ne faisait rien. ?>
    <?= csrf_field() ?>
    <div class="row">
      <p class="field">
        <label for="t-kod"><?= e($S['track.code'] ?? '') ?></label>
        <input id="t-kod" name="kod" type="text" maxlength="40" required
               autocomplete="off" spellcheck="false"
               value="<?= e($tKod) ?>" placeholder="MS-000000-0000">
        <small><?= e($S['track.code_hint'] ?? '') ?></small>
      </p>
      <p class="field">
        <label for="t-mail"><?= e($S['track.email'] ?? '') ?></label>
        <input id="t-mail" name="email" type="email" maxlength="190" required
               autocomplete="email" value="<?= e($tMail) ?>">
        <small><?= e($S['track.email_hint'] ?? '') ?></small>
      </p>
    </div>
    <p><button class="btn btn--accent" type="submit"><?= e($S['track.submit'] ?? '') ?></button></p>
  </form>

  <p class="muted small"><?= e($S['track.help'] ?? '') ?>
    <a href="<?= e(u('kontakt')) ?>"><?= e($S['nav.contact'] ?? 'Kontakt') ?></a>.</p>
</main>
<?php
    layout_footer($S);
    exit;
}

// ------------------------------------------------------------- CONFIRMATION -
// ------------------------------------------------------------------ ZAPŁAĆ -
// Le paiement, DANS la boutique : le formulaire Stripe (BLIK, Przelewy24,
// carte, Apple Pay, Google Pay) est monté ici par shop.js. Stripe renvoie
// ensuite sur la page de la commande, qui relit la session chez Stripe.
if ($page === 'zaplac' && count($seg) === 2) {
    $o = wsm_order_by_code($pdo, (string) ($seg[1] ?? ''), (string) ($_GET['t'] ?? ''));
    if ($o && ($o['payment_status'] === 'oplacone' || $o['status'] === 'anulowane' || !wsm_stripe_ready())) {
        redirect(u('zamowienie/' . rawurlencode($o['code']), ['t' => $o['access_token']]));
    }
    layout_head($S, $lang, $langs, $S['pay.title'] ?? '', '', 'zaplac');
    layout_header($S, $lang, $langs, $cartCount);
    if (!$o) {
        http_response_code(404);
        echo '<main class="wrap block"><h1>' . e($S['order.title'] ?? '') . '</h1>'
           . '<p class="muted">' . e($S['order.not_found'] ?? '') . '</p></main>';
        layout_footer($S);
        exit;
    }
    $qZ = ['t' => $o['access_token']];
    ?>
<main class="wrap block zaplac">
  <?php layout_crumbs([
      ($S['nav.shop'] ?? 'Sklep') => u(),
      ($S['order.title'] ?? 'Zamówienie') . ' ' . $o['code'] => u('zamowienie/' . rawurlencode($o['code']), $qZ),
      ($S['pay.title'] ?? 'Zapłać') => null,
  ]); ?>
  <header class="zaplac-glowa">
    <h1><?= e(str_replace('{x}', zl((int) $o['total_gross']), $S['pay.heading'] ?? '{x}')) ?></h1>
    <p class="muted"><?= e($S['order.number'] ?? '') ?> <b><?= e($o['code']) ?></b> · <?= e($S['pay.lead'] ?? '') ?></p>
  </header>
  <div class="zaplac-grid">
    <section class="zaplac-forma">
      <?php // Le formulaire Stripe se monte ICI (shop.js). La clé publiée et le
            // compte connecté ne sont pas des secrets : ce sont les deux
            // choses que Stripe.js doit connaître pour parler au bon compte. ?>
      <div id="stripe-checkout" class="stripe-checkout" data-stripe-checkout
           data-pk="<?= e(wsm_stripe_cfg()['publishable_key']) ?>"
           data-konto="<?= e(wsm_stripe_account()) ?>"
           data-sesja="<?= e(u('zaplac/' . rawurlencode($o['code']) . '/sesja', $qZ)) ?>"
           data-t="<?= e(csrf_token()) ?>"
           data-blad="<?= e($S['pay.error'] ?? '') ?>">
        <p class="muted zaplac-laduje"><?= e($S['pay.loading'] ?? '') ?></p>
      </div>
      <noscript><?php notice('warn', $S['pay.noscript'] ?? ''); ?></noscript>
      <p class="zaplac-bezp"><?= ikona_ui('klodka') ?><span><?= e($S['pay.secure'] ?? '') ?></span></p>
    </section>
    <aside class="summary zaplac-podsumowanie">
      <h2><?= e($S['order.items'] ?? '') ?></h2>
      <ul class="sum-lines">
        <?php foreach ($o['items'] as $l): ?>
        <li><span><?= e($l['name']) ?> <em>×<?= (int) $l['qty'] ?></em></span><span><?= e(zl($l['line_gross'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <dl class="totals">
        <dt><?= e($S['cart.shipping'] ?? '') ?></dt>
        <dd><?= (int) $o['shipping_gross'] === 0 ? e($S['cart.free'] ?? '') : e(zl($o['shipping_gross'])) ?></dd>
        <dt class="grand"><?= e($S['order.total'] ?? '') ?></dt><dd class="grand"><?= e(zl($o['total_gross'])) ?></dd>
      </dl>
    </aside>
  </div>
</main>
<script src="<?= e(WSM_STRIPE_JS) ?>" defer></script>
<?php
    layout_footer($S);
    exit;
}

if ($page === 'zamowienie') {
    $o = wsm_order_by_code($pdo, (string) ($seg[1] ?? ''), (string) ($_GET['t'] ?? ''));
    // LE RETOUR DE STRIPE : on relit la session CHEZ STRIPE (clé secrète) et
    // on applique ce qu'elle dit — le navigateur n'a fourni qu'un identifiant.
    // Le webhook reste la voie principale ; ceci évite d'afficher « en
    // attente » à quelqu'un qui vient de payer.
    if ($o && isset($_GET['session_id']) && $o['payment_status'] !== 'oplacone') {
        wsm_stripe_session_sync($pdo, $o, (string) $_GET['session_id']);
        $o = wsm_order_by_code($pdo, (string) $o['code'], (string) $o['access_token']);
    }
    // L'ACHAT, mesuré une seule fois et seulement payé : même identifiant que
    // celui que le serveur envoie à Meta (dédoublonnage).
    if ($o && $o['payment_status'] === 'oplacone' && function_exists('wsm_ads_purchase_event')) ads_event(wsm_ads_purchase_event($o));
    layout_head($S, $lang, $langs, $S['order.title'] ?? '', '', 'zamowienie');
    layout_header($S, $lang, $langs, $cartCount);
    if (!$o) {
        http_response_code(404);
        echo '<main class="wrap block"><h1>' . e($S['order.title'] ?? '') . '</h1>'
           . '<p class="muted">' . e($S['order.not_found'] ?? '') . '</p>'
           . '<p><a class="btn btn--brand" href="' . e(u()) . '">' . e($S['cart.empty_cta'] ?? '') . '</a></p></main>';
        layout_footer($S);
        exit;
    }
    // Le bouton « Zapłać teraz » mène à la page de paiement intégrée — pour
    // une commande pas encore payée, tant que Stripe est prêt.
    $payUrl = (wsm_stripe_ready() && in_array($o['payment_status'], ['oczekuje', 'nieudane'], true) && $o['status'] !== 'anulowane')
        ? u('zaplac/' . rawurlencode($o['code']), ['t' => $o['access_token']]) : '';
    // Le paragon fiscal : son lien quand l'imprimante l'a émis, sinon ce qui
    // va se passer. Rien du tout sans caisse branchée, ni pour une facture.
    $paragon = kasa_fiskalna() ? wsm_fiskal_dla_klienta($pdo, $o) : ['stan' => '', 'url' => ''];
    ?>
<main class="wrap block">
  <?php layout_crumbs([
      ($S['nav.shop'] ?? 'Sklep') => u(),
      ($S['order.title'] ?? 'Zamówienie') => null,
  ]); ?>
  <div class="thanks">
    <h1><?= e($S['order.thanks'] ?? '') ?></h1>
    <p class="mono order-code"><?= e($S['order.number'] ?? '') ?> · <strong><?= e($o['code']) ?></strong></p>
    <p class="muted"><?= e(str_replace('{email}', $o['email'], $S['order.confirm_mail'] ?? '')) ?></p>
    <?php if (!empty($o['backorder'])) notice('warn', $S['order.backorder'] ?? ''); ?>
  </div>

  <div class="order-grid">
    <section>
      <h2><?= e($S['order.items'] ?? '') ?></h2>
      <ul class="sum-lines">
        <?php foreach ($o['items'] as $l): ?>
        <li><span><?= e($l['name']) ?> <em class="mono">×<?= (int) $l['qty'] ?></em></span><span class="mono"><?= e(zl($l['line_gross'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <dl class="totals mono">
        <dt><?= e($S['cart.shipping'] ?? '') ?></dt>
        <dd><?= $o['shipping_gross'] === 0 ? e($S['cart.free'] ?? '') : e(zl($o['shipping_gross'])) ?></dd>
        <dt class="grand"><?= e($S['order.total'] ?? '') ?></dt><dd class="grand"><?= e(zl($o['total_gross'])) ?></dd>
      </dl>
    </section>

    <aside class="summary">
      <h2><?= e($S['order.status'] ?? '') ?></h2>
      <dl class="totals">
        <dt><?= e($S['order.status'] ?? '') ?></dt><dd><?= e($S['status.' . $o['status']] ?? $o['status']) ?></dd>
        <dt><?= e($S['order.payment'] ?? '') ?></dt><dd><?= e($S['pay.' . $o['payment_status']] ?? $o['payment_status']) ?></dd>
        <dt><?= e($S['order.delivery'] ?? '') ?></dt>
        <dd><?= e($S['ship.' . $o['delivery_method'] . '.label'] ?? $o['delivery_method']) ?>
          <?php if ($o['inpost_point'] !== ''): ?><br><span class="mono"><?= e($o['inpost_point']) ?></span><?php endif; ?>
          <?php if (($o['shipment']['tracking_number'] ?? '') !== ''): ?><br><span class="mono"><?= e($o['shipment']['tracking_number']) ?></span><?php endif; ?>
        </dd>
        <?php if ($paragon['stan'] !== '' && $o['status'] !== 'anulowane'): ?>
        <dt><?= e($S['order.receipt'] ?? '') ?></dt>
        <dd><?php if ($paragon['stan'] === 'gotowy'): ?>
          <a href="<?= e($paragon['url']) ?>" target="_blank" rel="noopener"><?= e($S['order.receipt_open'] ?? '') ?></a>
        <?php elseif ($paragon['stan'] === 'e'): ?>
          <?= e(str_replace('{email}', $o['email'], $S['order.receipt_wait'] ?? '')) ?>
        <?php else: ?>
          <?= e($S['order.receipt_paper'] ?? '') ?>
        <?php endif; ?></dd>
        <?php endif; ?>
      </dl>
      <?php if ($payUrl !== ''): ?>
        <a class="btn btn--accent btn--lg btn--block" href="<?= e($payUrl) ?>"><?= e($S['order.pay_now'] ?? '') ?></a>
      <?php elseif ($o['payment_status'] === 'niedostepne' || ($o['payment']['status'] ?? '') === 'niedostepne'): ?>
        <?php notice('warn', $S['order.pay_unavailable'] ?? ''); ?>
      <?php endif; ?>
      <p class="mono muted"><?= e($S['order.keep_link'] ?? '') ?></p>
    </aside>
  </div>

  <?php // ---- Réclamation / rétractation ------------------------------------
        //  Le compteur de jours est AFFICHÉ, y compris quand il est dépassé :
        //  c'est ce chiffre qui fait agir, et le masquer ne l'annule pas.
        //  Le droit de rétractation expire ; celui de signaler un défaut dure
        //  deux ans, et l'écran le dit plutôt que de fermer la porte.
        $clFile = $WSM_API_DIR . '/claims.php';
        if (is_file($clFile)):
          require_once $clFile;
          $reste = wsm_claim_zwrot_reste($pdo, $o);
          $dejaCl = $claimMsg ?? ''; ?>
  <?php // UN LIEN, PAS UN PAVÉ. Quatre-vingt-dix-neuf commandes sur cent se
        // passent bien : leur page n'a pas à s'ouvrir sur un formulaire de
        // réclamation déplié, qui suggère un problème avant qu'il existe.
        //
        // <details> est du HTML natif : il s'ouvre sans une ligne de
        // JavaScript, il est lisible au clavier et par un lecteur d'écran, et
        // il reste ouvert si le formulaire revient avec une erreur — d'où le
        // « open » conditionnel : sinon le message d'erreur serait replié,
        // donc invisible, et le client cliquerait « Wyślij » sans comprendre. ?>
  <details class="claim"<?= ($dejaCl !== '' || isset($claimErr)) ? ' open' : '' ?>>
    <summary><?= e($S['claim.title'] ?? '') ?></summary>
    <p class="muted"><?= e($S['claim.lead'] ?? '') ?></p>
    <?php // Celui qui se rétracte doit savoir OÙ renvoyer : l'adresse est
          // là, avant comme après l'envoi du formulaire. ?>
    <p class="muted claim-adres"><?= e($S['returns.address'] ?? '') ?>: <?= e(zwrot_adres_linia($S)) ?> · <a href="<?= e(u('zwroty')) ?>"><?= e($S['returns.footer_link'] ?? '') ?></a></p>
    <p class="mono muted"><?= $reste['jours'] > 0
        ? e(str_replace('{n}', (string) $reste['jours'], $S['claim.left'] ?? ''))
        : e($S['claim.over'] ?? '') ?></p>
    <?php if ($dejaCl !== ''): ?>
      <?php notice('ok', $dejaCl); ?>
    <?php else: ?>
      <?php if (isset($claimErr)) notice('warn', $claimErr); ?>
    <form method="post" action="<?= e(u('zamowienie/' . rawurlencode($o['code']), ['t' => $o['access_token']])) ?>">
      <?= csrf_field() ?>
      <p class="field">
        <label for="f-ctype"><?= e($S['claim.type'] ?? '') ?></label>
        <select id="f-ctype" name="ctype">
          <?php if ($reste['jours'] > 0): ?>
          <option value="zwrot"><?= e($S['claim.zwrot'] ?? '') ?></option>
          <?php endif; ?>
          <option value="reklamacja"><?= e($S['claim.reklamacja'] ?? '') ?></option>
        </select>
      </p>
      <p class="field">
        <label for="f-craison"><?= e($S['claim.reason'] ?? '') ?></label>
        <textarea id="f-craison" name="craison" rows="4" required
                  placeholder="<?= e($S['claim.reason_ph'] ?? '') ?>"></textarea>
      </p>
      <button class="btn btn--brand" type="submit" name="zgloszenie" value="1"><?= e($S['claim.cta'] ?? '') ?></button>
    </form>
    <?php endif; ?>
  </details>
  <?php endif; ?>
</main>
<?php
    layout_footer($S);
    exit;
}

// ------------------------------------------------------- STRONY Z KONSOLI -
// Une page écrite dans Strony : une adresse à UN segment, hors routes
// réservées (pages.php les connaît). Inconnue ou non publiée → le 404
// ci-dessous, comme pour n'importe quelle adresse inventée : un brouillon
// n'existe pas pour le public.
if (count($seg) === 1 && $page !== '' && function_exists('wsm_page_find')) {
    $pg = wsm_page_find($pdo, $page, $lang);
    if ($pg) {
        $img = (string) $pg['image_url'];
        $og  = $img === '' ? '' : (str_starts_with($img, 'https://') ? $img : seo_origin() . media_src($img));
        layout_head($S, $lang, $langs, $pg['title'], $pg['meta_desc'] !== '' ? $pg['meta_desc'] : $pg['lead'], $page, $og);
        layout_header($S, $lang, $langs, $cartCount);
        ?>
<main>
  <div class="wrap block page">
  <article>
    <h1><?= e($pg['title']) ?></h1>
    <?php if ($pg['lead'] !== ''): ?><p class="lead"><?= e($pg['lead']) ?></p><?php endif; ?>
    <?php if ($img !== ''): ?><img class="page-hero" src="<?= e(media_src($img)) ?>" alt="" decoding="async"><?php endif; ?>
    <?php if ($pg['body'] !== ''): ?><div class="prose"><?= wsm_page_render($pg['body'], fn(string $x) => u($x)) ?></div><?php endif; ?>
    <?php if ($pg['cta_label'] !== '' && $pg['cta_url'] !== ''): ?>
    <p><a class="btn btn--accent" href="<?= e(page_href($pg['cta_url'])) ?>"><?= e($pg['cta_label']) ?></a></p>
    <?php endif; ?>
  </article>
  </div>
  <?php // Les sections composées dans le Kreator, sous le corps de la page.
        sekcje_html($pdo, $lang, (int) $pg['id']); ?>
</main>
<?php
        layout_footer($S, $lang);
        exit;
    }
}

// ----------------------------------------------------------------- 404 ------
http_response_code(404);
layout_head($S, $lang, $langs, '404', '', 'koszyk');   // 'koszyk' = non indexable
layout_header($S, $lang, $langs, $cartCount);
echo '<main class="wrap block"><h1>404</h1><p><a class="btn btn--brand" href="' . e(u()) . '">'
   . e($S['cart.empty_cta'] ?? '') . '</a></p></main>';
layout_footer($S);
