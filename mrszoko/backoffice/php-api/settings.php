<?php
// ============================================================================
//  settings.php — les réglages d'intégration saisis depuis le back-office.
//
//  Pourquoi une table plutôt que le fichier serveur : les identifiants tpay,
//  InPost et le compte d'envoi de courrier arrivent APRÈS la mise en ligne, et
//  celui qui les reçoit n'a pas d'accès SSH. L'écran Ustawienia les prend, la
//  base les garde, et rien ne part dans le dépôt — qui est public.
//
//  Trois règles, appliquées ici et nulle part ailleurs :
//
//   1. LE FICHIER SERVEUR L'EMPORTE. config.local.php et les variables
//      d'environnement restent la source d'autorité ; la base ne remplit que
//      ce qu'ils laissent vide. Un réglage posé par l'exploitant ne peut pas
//      être renversé depuis un navigateur.
//
//   2. « xxxx » N'EST PAS UNE VALEUR. Le formulaire est livré avec des
//      espaces réservés ; tant qu'ils y sont, l'intégration reste éteinte
//      (fail-closed) plutôt qu'à moitié branchée sur des identifiants faux.
//
//   3. UN SECRET NE SE RELIT PAS. Une fois enregistré, un secret n'est jamais
//      renvoyé au navigateur : l'écran affiche « ustawione », pas la valeur.
//      Il ne part jamais non plus dans le journal d'audit.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/metody.php';   // les choix « multi » des logos de livraison et de paiement

/** L'espace réservé livré dans le formulaire : présent = pas encore réglé. */
const WSM_SETTING_PLACEHOLDER = 'xxxx';

/** Une valeur qui ne vaut rien : vide, ou l'espace réservé du formulaire. */
function wsm_setting_blank(string $v): bool {
    $v = trim($v);
    return $v === '' || (bool) preg_match('/^x{2,}$/i', $v);
}

/**
 * Un champ qui reçoit un FICHIER d'image. Deux types pour un même geste :
 * « image » (photo, fond opaque) et « ikona » (pictogramme, transparence
 * conservée). Tout ce qui lit ou enregistre un fichier passe par ici, pour
 * qu'un troisième type un jour n'oublie pas la moitié des écrans.
 */
function wsm_setting_obraz(string $type): bool {
    return $type === 'image' || $type === 'ikona';
}

/** Le canal alpha est-il à conserver à l'enregistrement ? */
function wsm_setting_alpha(string $type): bool {
    return $type === 'ikona';
}

/**
 * Les réglages pilotables depuis la console.
 *   cle => [groupe, libellé, chemin dans wsm_config, variable d'env, type, aide]
 * type : text | secret | bool | select:a|b|c
 */
function wsm_settings_fields(): array {
    return [
        // STRIPE : les clés de la PLATEFORME. Groupe à part, que l'écran
        // Integracje ne montre ni n'enregistre (voir le filtre $groupes de
        // wsm_settings_save) : seule la page Płatności, et seulement pour le
        // superadmin, les écrit. Une boutique ne détient pas la clé qui ouvre
        // tous les comptes connectés de la plateforme.
        'stripe.secret_key'      => ['stripe', 'Klucz tajny platformy (sk_…)',     ['stripe', 'secret_key'],      'WSM_STRIPE_SECRET_KEY',      'secret', 'Dashboard Stripe platformy → Developers → API keys. Pokazywany tylko raz.'],
        'stripe.publishable_key' => ['stripe', 'Klucz publiczny platformy (pk_…)', ['stripe', 'publishable_key'], 'WSM_STRIPE_PUBLISHABLE_KEY', 'text',   'Ten sam tryb co klucz tajny (test albo produkcja).'],
        'stripe.webhook_secret'  => ['stripe', 'Sekret webhooka (whsec_…)',        ['stripe', 'webhook_secret'],  'WSM_STRIPE_WEBHOOK_SECRET',  'secret', 'Webhook dla „Connected accounts”, zdarzenia checkout.session.*. Bez niego żadne potwierdzenie płatności nie zostanie przyjęte.'],
        'stripe.account'         => ['stripe', 'Konto Stripe sklepu (acct_…)',     ['stripe', 'account'],         'WSM_STRIPE_ACCOUNT',         'text',   'Zakładane przyciskiem na ekranie Płatności.'],

        // KSeF ÉTAIT LA SEULE INTÉGRATION SANS ÉCRAN. tpay, InPost, DPD, la
        // poste et la facturation se règlent depuis la console ; le registre
        // national, lui, ne se réglait que par variables d'environnement —
        // donc par un accès au serveur. « Fail-closed » veut dire fermé tant
        // qu'on n'a pas les identifiants ; encore faut-il pouvoir les poser.
        //
        // Le NIP n'est PAS répété ici : il vient de la section « faktura »
        // (seller_nip). Deux champs pour un même numéro finissent par ne plus
        // dire la même chose, et c'est le registre national qui trancherait.
        'ksef.token'      => ['ksef', 'Token autoryzacyjny', ['ksef', 'token'], 'WSM_KSEF_TOKEN', 'secret',
                              'Generowany w aplikacji KSeF na NIP sprzedawcy. Nigdy nie opuszcza serwera.'],
        'ksef.public_key' => ['ksef', 'Klucz publiczny Ministerstwa Finansów', ['ksef', 'public_key'], 'WSM_KSEF_PUBLIC_KEY', 'pem',
                              'Wklej zawartość pliku .pem. Bez niego tokenu nie da się zaszyfrować, więc sesji nie da się otworzyć.'],
        'ksef.env'        => ['ksef', 'Środowisko', ['ksef', 'env'], 'WSM_KSEF_ENV', 'select:test|demo|prod',
                              'test = piaskownica, demo = przedprodukcyjne, prod = prawdziwy rejestr.'],

        // Newsletter : qui peut la recevoir (marketing.php, config « marketing »).
        'marketing.klienci_bez_zgody' => ['sms', 'Newsletter do klientów bez zgody', ['marketing', 'klienci_bez_zgody'], 'WSM_MKT_KLIENCI_BEZ_ZGODY', 'select:1|0',
                             '0 = tylko osoby, które zaznaczyły zgodę i potwierdziły ją mailem (domyślnie). 1 = także kupujący bez zgody: od 10.11.2024 '
                             . 'Prawo komunikacji elektronicznej (art. 398) wymaga uprzedniej zgody, a wyjątek dla dotychczasowych klientów jest w Polsce niepewny. Włącz tylko po opinii prawnika.'],
        // Claude : traductions, rédaction des newsletters et SMS, lecture des factures PDF.
        'anthropic_api_key' => ['claude', 'Klucz API Claude', ['anthropic_api_key'], 'WSM_ANTHROPIC_API_KEY', 'secret',
                             'console.anthropic.com → API Keys. Tłumaczenia, pisanie newsletterów i SMS-ów, odczyt faktur zakupu z PDF.'],

        // Allegro : la deuxième vitrine (allegro.php).
        'allegro.client_id'     => ['allegro', 'Client ID', ['allegro', 'client_id'], 'WSM_ALLEGRO_CLIENT_ID', 'text',
                             'Z aplikacji w apps.developer.allegro.pl.'],
        'allegro.client_secret' => ['allegro', 'Client Secret', ['allegro', 'client_secret'], 'WSM_ALLEGRO_CLIENT_SECRET', 'secret',
                             'Z tej samej aplikacji. Nigdy nie opuszcza serwera.'],
        'allegro.refresh_token' => ['allegro', 'Refresh token', ['allegro', 'refresh_token'], 'WSM_ALLEGRO_REFRESH_TOKEN', 'secret',
                             'Z autoryzacji konta sprzedawcy (device flow).'],
        'allegro.seller_id'     => ['allegro', 'ID sprzedawcy', ['allegro', 'seller_id'], 'WSM_ALLEGRO_SELLER_ID', 'text', ''],
        'allegro.sandbox'       => ['allegro', 'Środowisko', ['allegro', 'sandbox'], 'WSM_ALLEGRO_SANDBOX', 'select:1|0',
                             '1 = sandbox (testy), 0 = produkcja.'],

        // GOOGLE : être trouvé (Search Console), mesurer (GA4), payer les
        // conversions (Ads) et apparaître dans l'onglet Zakupy (Merchant
        // Center, qui lit le flux /feed/produkty.xml). Tout est public — un
        // identifiant de balise se lit dans le code de n'importe quelle page —
        // sauf qu'aucun ne se charge avant l'accord du visiteur (reklama.php).
        'google.site_verification' => ['google', 'Kod weryfikacji Search Console', ['google', 'site_verification'], 'WSM_GOOGLE_SITE_VERIFICATION', 'text',
                             'Search Console → Dodaj usługę → Prefiks URL → metoda „Tag HTML”: wklej tylko wartość content="…".'],
        'google.ga4_id'     => ['google', 'Identyfikator GA4 (G-…)', ['google', 'ga4_id'], 'WSM_GOOGLE_GA4_ID', 'text',
                             'Google Analytics → Administracja → Strumienie danych → Identyfikator pomiaru. Ładuje się dopiero po zgodzie „analityczne”.'],
        'google.ads_id'     => ['google', 'Google Ads — identyfikator konwersji (AW-…)', ['google', 'ads_id'], 'WSM_GOOGLE_ADS_ID', 'text',
                             'Google Ads → Cele → Konwersje → Zakup → Skonfiguruj tag samodzielnie. Ładuje się dopiero po zgodzie „marketingowe”.'],
        'google.ads_label'  => ['google', 'Google Ads — etykieta konwersji „zakup”', ['google', 'ads_label'], 'WSM_GOOGLE_ADS_LABEL', 'text',
                             'Druga część send_to: AW-123/<etykieta>. Bez niej zakup nie liczy się jako konwersja.'],
        'google.merchant_id' => ['google', 'Merchant Center — ID konta', ['google', 'merchant_id'], 'WSM_GOOGLE_MERCHANT_ID', 'text',
                             'Tylko do linku w konsoli. Plik produktów dla Merchant Center: /feed/produkty.xml.'],
        'bing.site_verification' => ['google', 'Kod weryfikacji Bing Webmaster', ['bing', 'site_verification'], 'WSM_BING_SITE_VERIFICATION', 'text',
                             'Opcjonalnie. Bing Webmaster Tools → metoda „Meta tag”: wartość content="…".'],

        // META : Facebook et Instagram. Le pixel et le domaine sont publics ;
        // les deux jetons (Conversions API, Page) ouvrent la publicité et la
        // page de la marque — ils ne quittent jamais le serveur.
        'meta.pixel_id'     => ['meta', 'Piksel Meta — ID zbioru danych', ['meta', 'pixel_id'], 'WSM_META_PIXEL_ID', 'text',
                             'Menedżer zdarzeń → Źródła danych → ID (same cyfry). Ładuje się dopiero po zgodzie „marketingowe”.'],
        'meta.capi_token'   => ['meta', 'Token Conversions API', ['meta', 'capi_token'], 'WSM_META_CAPI_TOKEN', 'secret',
                             'Menedżer zdarzeń → zbiór danych → Ustawienia → Conversions API → Wygeneruj token. Zakup wysyłany z serwera, tylko gdy klient zgodził się na marketing.'],
        'meta.test_event_code' => ['meta', 'Kod zdarzeń testowych', ['meta', 'test_event_code'], 'WSM_META_TEST_EVENT_CODE', 'text',
                             'Opcjonalnie, na czas testów (TEST12345). Puste = zdarzenia produkcyjne.'],
        'meta.domain_verification' => ['meta', 'Weryfikacja domeny (Facebook)', ['meta', 'domain_verification'], 'WSM_META_DOMAIN_VERIFICATION', 'text',
                             'Business Manager → Bezpieczeństwo marki → Domeny → metoda „Meta-tag”: wartość content="…".'],
        'meta.page_id'      => ['meta', 'Strona Facebook — ID', ['meta', 'page_id'], 'WSM_META_PAGE_ID', 'text',
                             'Strona → Informacje → Przejrzystość strony → Identyfikator strony.'],
        'meta.page_token'   => ['meta', 'Token strony (publikowanie)', ['meta', 'page_token'], 'WSM_META_PAGE_TOKEN', 'secret',
                             'Bezterminowy token strony z uprawnieniami pages_manage_posts, pages_read_engagement, instagram_basic, instagram_content_publish. Nigdy nie opuszcza serwera.'],
        'meta.ig_user_id'   => ['meta', 'Instagram — ID konta firmowego', ['meta', 'ig_user_id'], 'WSM_META_IG_USER_ID', 'text',
                             'Konto firmowe połączone ze stroną. Przycisk „Sprawdź połączenie” w Marketing → Meta odczyta je sam.'],
        'meta.graph_version' => ['meta', 'Wersja Graph API', ['meta', 'graph_version'], 'WSM_META_GRAPH_VERSION', 'text',
                             'Puste = v23.0. Zmieniaj, gdy Meta ogłosi wycofanie wersji.'],

        // SMS marketing : SMSAPI (smsapi.php), comme le CRM.
        'smsapi.token'   => ['sms', 'Token OAuth SMSAPI', ['smsapi', 'token'], 'WSM_SMSAPI_TOKEN', 'secret',
                             'Panel SMSAPI → Ustawienia API → Tokeny OAuth, z uprawnieniem „sms”. Nigdy nie opuszcza serwera.'],
        'smsapi.nadawca' => ['sms', 'Nazwa nadawcy', ['smsapi', 'nadawca'], 'WSM_SMSAPI_NADAWCA', 'text',
                             'Do 11 znaków, zatwierdzona w panelu SMSAPI (np. SzokoShop). To ją widzi klient.'],
        'smsapi.region'  => ['sms', 'Platforma', ['smsapi', 'region'], 'WSM_SMSAPI_REGION', 'select:pl|com',
                             'pl = smsapi.pl (konto polskie), com = smsapi.com. Token działa tylko na swojej platformie.'],
        'smsapi.cena'    => ['sms', 'Cena jednego SMS-a (zł)', ['smsapi', 'cena'], 'WSM_SMSAPI_CENA', 'text',
                             'Do szacowania kosztu kampanii przed wysyłką. Puste = 0,16 zł.'],

        // La caisse fiscale : Fakturownia + module Paragony.pl + imprimante
        // fiscale online chez la société (fiskal.php).
        'fakturownia.domena'        => ['paragon', 'Adres konta Fakturowni', ['fakturownia', 'domena'], 'WSM_FAKTUROWNIA_DOMENA', 'text',
                                        'Np. szoko.fakturownia.pl (albo samo „szoko”).'],
        'fakturownia.api_token'     => ['paragon', 'Kod autoryzacyjny API', ['fakturownia', 'api_token'], 'WSM_FAKTUROWNIA_TOKEN', 'secret',
                                        'Fakturownia → Ustawienia → Ustawienia konta → Integracja. Wystawia dokumenty fiskalne w imieniu firmy — nigdy nie opuszcza serwera.'],
        'fakturownia.drukarka'      => ['paragon', 'Drukarka fiskalna', ['fakturownia', 'drukarka'], 'WSM_FAKTUROWNIA_DRUKARKA', 'text',
                                        'Nazwa z przycisku „Sprawdź połączenie”. Puste = drukarka domyślna w Fakturowni.'],
        'fakturownia.webhook_token' => ['paragon', 'Token webhooka', ['fakturownia', 'webhook_token'], 'WSM_FAKTUROWNIA_WEBHOOK_TOKEN', 'secret',
                                        'Wymyśl dowolny ciąg, wpisz go tutaj i w polu „API token” webhooka w Fakturowni (to NIE jest kod API).'],

        'inpost.token'           => ['inpost', 'Token ShipX (serwerowy)',  ['inpost', 'token'],           'WSM_INPOST_TOKEN',           'secret', 'Nigdy nie opuszcza serwera.'],
        'inpost.organization_id' => ['inpost', 'ID organizacji',           ['inpost', 'organization_id'], 'WSM_INPOST_ORG_ID',          'text',   'Numer organizacji w ShipX.'],
        'inpost.geowidget_token' => ['inpost', 'Token Geowidget (publiczny)', ['inpost', 'geowidget_token'], 'WSM_INPOST_GEOWIDGET_TOKEN', 'text', 'Trafia do strony — to token przeglądarkowy.'],
        'inpost.sandbox'         => ['inpost', 'Środowisko', ['inpost', 'sandbox'], 'WSM_INPOST_SANDBOX', 'select:1|0', '1 = sandbox, 0 = produkcja.'],

        'dpd.login'    => ['dpd', 'Login DPD',            ['dpd', 'login'],    'WSM_DPD_LOGIN',    'text',   'Z panelu DPD (usługi webowe).'],
        'dpd.password' => ['dpd', 'Hasło DPD',            ['dpd', 'password'], 'WSM_DPD_PASSWORD', 'secret', 'Nigdy nie opuszcza serwera.'],
        'dpd.fid'      => ['dpd', 'Numer klienta (FID)',  ['dpd', 'fid'],      'WSM_DPD_FID',      'text',   'Bez niego DPD nie wie, komu policzyć transport.'],
        'dpd.sandbox'  => ['dpd', 'Środowisko', ['dpd', 'sandbox'], 'WSM_DPD_SANDBOX', 'select:1|0', '1 = testy, 0 = produkcja (prawdziwe paczki).'],
        'dpd.sender_name'     => ['dpd', 'Nadawca — nazwa',   ['dpd', 'sender_name'],     'WSM_DPD_SENDER_NAME',     'text', 'Drukowane na etykiecie. Bez adresu zwrotnego paczka nie wraca.'],
        'dpd.sender_address'  => ['dpd', 'Nadawca — ulica i numer', ['dpd', 'sender_address'], 'WSM_DPD_SENDER_ADDRESS', 'text', ''],
        'dpd.sender_postcode' => ['dpd', 'Nadawca — kod',     ['dpd', 'sender_postcode'], 'WSM_DPD_SENDER_POSTCODE', 'text', ''],
        'dpd.sender_city'     => ['dpd', 'Nadawca — miasto',  ['dpd', 'sender_city'],     'WSM_DPD_SENDER_CITY',     'text', ''],
        'dpd.sender_phone'    => ['dpd', 'Nadawca — telefon', ['dpd', 'sender_phone'],    'WSM_DPD_SENDER_PHONE',    'text', 'Kurier dzwoni tu, nie do klienta.'],

        // ─── CE QUE DÉCLENCHE UN CHANGEMENT D'ÉTAT ────────────────────────
        //
        // Ces quatre réglages décident de ce qui part au fisc et au client.
        // Ils ne s'affichent PAS dans Ustawienia : ils vivent derrière la
        // porte du Superadmin, avec le tableau qui explique chacun d'eux.
        // Les déclarer ici quand même donne trois choses gratuitement — le
        // stockage, la priorité serveur > base, et la variable d'environnement.
        //
        // TOUS ONT UN DÉFAUT « ACTIVÉ » LU DANS LE CODE, pas dans config.php :
        // une base vide doit se comporter exactement comme avant, et un
        // réglage absent ne doit jamais éteindre une obligation légale.
        'orders.doc_status'   => ['zamowienia', 'Stan, który wystawia dokument', ['orders', 'doc_status'], 'WSM_ORDER_DOC_STATUS', 'select:wyslane|oplacone|dostarczone|nigdy', 'Domyślnie „wysłane". „nigdy" wyłącza automat — dokumenty wystawia się wtedy ręcznie.'],
        // LE DÉLAI PROMIS, RÉGLABLE. La vitrine annonce « Wysyłka w 24 h ».
        // Écrit en dur, ce chiffre aurait vécu à deux endroits — le texte de la
        // vitrine et le compteur de la console — et ils auraient divergé le
        // jour où l'un des deux change.
        'orders.ship_h'       => ['zamowienia', 'Obiecany czas wysyłki (godz.)', ['orders', 'ship_h'], 'WSM_ORDER_SHIP_H', 'text', 'Liczony od zapłaty, od 1 do 720 godz. Domyślnie 24. Tyle samo powinno stać w Treściach, w obietnicy na stronie.'],
        'orders.doc_mail'     => ['zamowienia', 'Wysyłać dokument mailem', ['orders', 'doc_mail'], 'WSM_ORDER_DOC_MAIL', 'select:1|0', 'Wyłączenie nie kasuje dokumentu — tylko go nie wysyła.'],
        'orders.doc_ksef'     => ['zamowienia', 'Zgłaszać fakturę do KSeF', ['orders', 'doc_ksef'], 'WSM_ORDER_DOC_KSEF', 'select:1|0', 'Wyłączone: faktura czeka w kolejce KSeF i wysyła się ręcznie.'],
        'orders.vies_recheck' => ['zamowienia', 'Sprawdzać VIES przed wystawieniem', ['orders', 'vies_recheck'], 'WSM_ORDER_VIES_RECHECK', 'select:1|0', 'Wyłączenie zostawia status z chwili zamówienia — przy WDT to ryzyko podatkowe.'],

        'mail.transport'   => ['mail', 'Sposób wysyłki', ['mail', 'transport'], 'WSM_MAIL_TRANSPORT', 'select:mail|smtp', 'mail = funkcja serwera, smtp = konto pocztowe.'],
        'mail.from'        => ['mail', 'Adres nadawcy',  ['mail', 'from'],      'WSM_MAIL_FROM',      'text',   'Bez niego poczta jest wyłączona.'],
        'mail.from_name'   => ['mail', 'Nazwa nadawcy',  ['mail', 'from_name'], 'WSM_MAIL_FROM_NAME', 'text',   'Widoczna w skrzynce klienta.'],
        'mail.reply_to'    => ['mail', 'Adres do odpowiedzi', ['mail', 'reply_to'], 'WSM_MAIL_REPLY_TO', 'text', 'Tu trafią odpowiedzi klientów.'],
        'mail.bcc'         => ['mail', 'Kopia ukryta (BCC)',  ['mail', 'bcc'],  'WSM_MAIL_BCC',       'text',   'Kopia każdej wiadomości do archiwum.'],
        'mail.smtp_host'   => ['mail', 'Serwer SMTP',    ['mail', 'smtp_host'], 'WSM_MAIL_SMTP_HOST', 'text',   'Tylko przy sposobie „smtp”.'],
        'mail.smtp_port'   => ['mail', 'Port SMTP',      ['mail', 'smtp_port'], 'WSM_MAIL_SMTP_PORT', 'text',   '587 dla TLS, 465 dla SSL.'],
        'mail.smtp_user'   => ['mail', 'Użytkownik SMTP', ['mail', 'smtp_user'], 'WSM_MAIL_SMTP_USER', 'text',  'Zwykle pełny adres e-mail.'],
        'mail.smtp_pass'   => ['mail', 'Hasło SMTP',     ['mail', 'smtp_pass'], 'WSM_MAIL_SMTP_PASS', 'secret', 'Zapisane w bazie, nigdy nie pokazywane ponownie.'],
        'mail.smtp_secure' => ['mail', 'Szyfrowanie',    ['mail', 'smtp_secure'], 'WSM_MAIL_SMTP_SECURE', 'select:tls|ssl|brak', 'tls dla portu 587, ssl dla 465.'],

        // L'IMAGE DU HERO. Le haut de la page d'accueil était un dégradé :
        // joli, mais muet. Une photo y dit en une seconde ce que le texte met
        // trois lignes à expliquer. Elle se dépose ici, comme une photo de
        // produit — même stockage, mêmes contrôles, même limite de 8 Mo.
        'hero_image' => ['sklep', 'Zdjęcie na stronie głównej', ['shop', 'hero_image'], 'WSM_SHOP_HERO', 'image',
                         'Zapasowe tło nagłówka — widoczne tylko wtedy, gdy żaden slajd nie jest w danym dniu widoczny. Zdjęcia, które się zmieniają, ustawia się w Oferta → Slajdy.'],

        // LES ICÔNES DES TROIS PROMESSES (« Wysyłka 24h », « Paczkomat /
        // kurier », « Pakowanie termiczne »). Le texte vit dans Treści ; ici
        // ne se dépose que l'image. Type « ikona », pas « image » : le canal
        // alpha est conservé à l'enregistrement, sinon un pictogramme sur fond
        // transparent ressortirait dans un carré crème au milieu d'une carte
        // blanche. Vide = le bloc reste du texte, comme avant.
        'promise_icon_1' => ['sklep', 'Ikona obietnicy 1', ['shop', 'promise_icon_1'], 'WSM_SHOP_ICON_1', 'ikona',
                             'Pierwsza z trzech obietnic pod nagłówkiem (jej tekst: Treści → promise.1). Kwadrat, PNG lub WebP z przezroczystym tłem, ok. 200×200 px. Puste = sam tekst.'],
        'promise_icon_2' => ['sklep', 'Ikona obietnicy 2', ['shop', 'promise_icon_2'], 'WSM_SHOP_ICON_2', 'ikona',
                             'Druga obietnica (Treści → promise.2). Ten sam format co wyżej.'],
        'promise_icon_3' => ['sklep', 'Ikona obietnicy 3', ['shop', 'promise_icon_3'], 'WSM_SHOP_ICON_3', 'ikona',
                             'Trzecia obietnica (Treści → promise.3). Ten sam format co wyżej.'],
        // LE LOGO. Il était un fichier du dépôt (assets/logo.png) : le changer
        // voulait dire ouvrir le code. Déposé ici, il prend la barre du haut,
        // le pied de page et l'icône d'onglet. Vide = le logo du dépôt.
        'logo_image'     => ['sklep', 'Logo sklepu', ['shop', 'logo_image'], 'WSM_SHOP_LOGO', 'ikona',
                             'Górna belka, stopka i ikona karty. PNG lub WebP z przezroczystym tłem, szerokość ok. 300–600 px. Puste = logo z repozytorium.'],
        'logo_nazwa'     => ['sklep', 'Nazwa sklepu obok logo', ['shop', 'logo_nazwa'], 'WSM_SHOP_LOGO_NAZWA', 'select:1|0',
                             '1 = obok logo stoi nazwa sklepu (Treści → brand). 0 = samo logo — gdy wgrane logo zawiera już nazwę.'],
        // LES LOGOS DE LIVRAISON ET DE PAIEMENT (pied de page, fiche produit).
        // Rien de coché jamais enregistré = la réalité : modes actifs, Stripe.
        'metody_wysylki'   => ['sklep', 'Sposoby wysyłki — logotypy dla klienta', ['shop', 'metody_wysylki'], 'WSM_SHOP_METODY_WYSYLKI', wsm_metody_typ('wysylka'),
                               'Pokazywane w stopce każdej strony i przy przycisku „Dodaj do koszyka”. Zaznacz tylko to, co kasa naprawdę oferuje — DPD Pickup dopiero wtedy, gdy będzie w kasie.'],
        'metody_platnosci' => ['sklep', 'Formy płatności — logotypy dla klienta', ['shop', 'metody_platnosci'], 'WSM_SHOP_METODY_PLATNOSCI', wsm_metody_typ('platnosc'),
                               'Jak wyżej. Dopóki Stripe nie przyjmuje płatności, domyślnie nic — po uruchomieniu Stripe: BLIK, karty, portfele i Przelewy24.'],

        // L'ADRESSE DES RETOURS, exigée par tpay : règlement, pied de page,
        // page « Zwroty ». Les trois champs ou rien : une adresse à moitié
        // remplie renverrait un colis nulle part, donc tant qu'il en manque
        // un, la vitrine montre l'adresse du siège (Treści → seller.address).
        'zwrot_odbiorca' => ['sklep', 'Zwroty — odbiorca', ['shop', 'zwrot_odbiorca'], 'WSM_SHOP_ZWROT_ODBIORCA', 'text',
                             'Nazwa na paczce zwrotnej, np. nazwa firmy albo „Szoko Shop — zwroty”. Puste = sprzedawca z Treści (seller.name).'],
        'zwrot_ulica'    => ['sklep', 'Zwroty — ulica i numer', ['shop', 'zwrot_ulica'], 'WSM_SHOP_ZWROT_ULICA', 'text',
                             'Adres, na który klient odsyła towar: w regulaminie, w stopce i na stronie „Zwroty” (wymóg operatorów płatności). Ulica, kod i miasto razem — dopóki któregoś brakuje, pokazujemy adres siedziby z Treści (seller.address).'],
        'zwrot_kod'      => ['sklep', 'Zwroty — kod pocztowy', ['shop', 'zwrot_kod'], 'WSM_SHOP_ZWROT_KOD', 'text', 'Np. 50-078.'],
        'zwrot_miasto'   => ['sklep', 'Zwroty — miasto', ['shop', 'zwrot_miasto'], 'WSM_SHOP_ZWROT_MIASTO', 'text', ''],

        'shop_url' => ['sklep', 'Publiczny adres sklepu', ['shop_url'], 'WSM_SHOP_URL', 'text', 'Używany w linkach wysyłanych klientom i w powrocie z płatności Stripe.'],

        // ---- Faktury : ce qui figure sur le document ----------------------
        // Rien n'est en dur dans le code. Le vendeur change d'adresse ou de
        // banque sans redéploiement, et une facture déjà émise garde SA copie
        // (voir invoice.php) : modifier ces champs ne réécrit pas le passé.
        'invoice.seller_name'    => ['faktura', 'Sprzedawca — nazwa',     ['invoice', 'seller_name'],    'WSM_INV_SELLER_NAME',    'text',   'Pełna nazwa z KRS.'],
        'invoice.seller_nip'     => ['faktura', 'Sprzedawca — NIP',       ['invoice', 'seller_nip'],     'WSM_INV_SELLER_NIP',     'text',   'Bez prefiksu PL na fakturze krajowej.'],
        'invoice.seller_address' => ['faktura', 'Sprzedawca — adres',     ['invoice', 'seller_address'], 'WSM_INV_SELLER_ADDRESS', 'text',   'Ulica, kod, miasto, kraj.'],
        'invoice.place'          => ['faktura', 'Miejsce wystawienia',    ['invoice', 'place'],          'WSM_INV_PLACE',          'text',   'Zwykle siedziba firmy.'],
        'invoice.iban'           => ['faktura', 'Numer rachunku (IBAN)',  ['invoice', 'iban'],           'WSM_INV_IBAN',           'text',   'Trafia na każdą fakturę z przelewem.'],
        'invoice.bank'           => ['faktura', 'Nazwa banku',            ['invoice', 'bank'],           'WSM_INV_BANK',           'text',   'Obok numeru rachunku.'],
        'invoice.payment_days'   => ['faktura', 'Termin płatności (dni)', ['invoice', 'payment_days'],   'WSM_INV_PAYMENT_DAYS',   'text',   '0 = płatne przy zamówieniu (Stripe pobiera od razu).'],
        'invoice.reminder_days'  => ['faktura', 'Przypomnienie po (dni)', ['invoice', 'reminder_days'], 'WSM_INV_REMINDER_DAYS', 'text', 'Ile dni po terminie wysłać przypomnienie. Puste lub 0 = nie przypominaj.'],
        'invoice.number_format'  => ['faktura', 'Format numeru',          ['invoice', 'number_format'],  'WSM_INV_NUMBER_FORMAT',  'text',   'xxx = kolejny numer, mm = miesiąc, yy = rok. Numeracja zeruje się wraz z tym, co jest w formacie.'],
    ];
}

/** Les valeurs enregistrées, brutes. */
function wsm_settings_all(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT cle, val FROM wsm_settings")->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) $out[(string) $r['cle']] = (string) $r['val'];
    return $out;
}

/** La valeur actuellement en vigueur pour un chemin de configuration. */
function wsm_config_at(array $path) {
    $node = wsm_config();
    foreach ($path as $k) {
        if (!is_array($node) || !array_key_exists($k, $node)) return null;
        $node = $node[$k];
    }
    return $node;
}

/**
 * Superpose les réglages de la base sur la configuration — mais UNIQUEMENT
 * là où le fichier serveur et l'environnement n'ont rien dit. Appelé une fois
 * par requête, depuis wsm_bootstrap().
 */
function wsm_settings_apply(PDO $pdo): void {
    $vals = wsm_settings_all($pdo);
    if (!$vals) return;
    $patch = [];
    foreach (wsm_settings_fields() as $key => [$grp, $label, $path, $env, $type, $help]) {
        if (!isset($vals[$key])) continue;
        $v = (string) $vals[$key];
        if (wsm_setting_blank($v)) continue;                 // « xxxx » : rien n'est configuré
        if (getenv($env) !== false) continue;                // l'environnement a parlé
        $cur = wsm_config_at($path);
        if ($type !== 'bool' && $type !== 'select:1|0' && is_string($cur) && $cur !== '') continue;  // le fichier a parlé

        if ($type === 'select:1|0') $v = ($v === '1' || $v === 'tak');
        if ($key === 'mail.smtp_port') $v = (int) $v;
        if ($key === 'mail.smtp_secure' && $v === 'brak') $v = '';

        // Reconstitue le chemin : ['stripe','account'] => ['stripe' => ['account' => v]]
        $node = $v;
        foreach (array_reverse($path) as $k) $node = [$k => $node];
        $patch = array_replace_recursive($patch, $node);
    }
    if ($patch) wsm_config_overlay($patch);
}

/**
 * Range une clé PEM collée dans le formulaire et rend SON CHEMIN.
 *
 * Le certificat public du Ministère n'est pas un secret — il est publié. Mais
 * c'est un FICHIER que la configuration désigne par un chemin, pas une valeur :
 * le poser demandait donc un accès au serveur, et l'écran ne servait à rien.
 * On le colle, on l'écrit sous data/ — répertoire exclu du rsync, donc il
 * survit aux déploiements, et refusé par le .htaccess de l'API, donc il ne se
 * télécharge pas.
 *
 * Le contenu est vérifié AVANT d'être écrit : un fichier qui n'est pas une clé
 * ferait échouer l'ouverture de session KSeF sans jamais dire pourquoi.
 *
 * @return array{0:string,1:string} [chemin, erreur] — l'un des deux est vide.
 */
function wsm_setting_pem_store(string $pem): array {
    $pem = trim($pem);
    if (!preg_match('/^-----BEGIN (PUBLIC KEY|CERTIFICATE)-----/', $pem)
        || !preg_match('/-----END (PUBLIC KEY|CERTIFICATE)-----$/', $pem)) {
        return ['', 'to nie wygląda na klucz PEM — brak linii BEGIN/END'];
    }
    if (function_exists('openssl_pkey_get_public')) {
        $ok = @openssl_pkey_get_public($pem) !== false
           || (function_exists('openssl_x509_read') && @openssl_x509_read($pem) !== false);
        if (!$ok) return ['', 'OpenSSL nie potrafi odczytać tego klucza'];
    }
    $dir = __DIR__ . '/data';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return ['', 'nie da się utworzyć katalogu data/'];
    $path = $dir . '/ksef-mf.pem';
    $umask = @umask(0077);
    $ok = @file_put_contents($path, $pem . "\n") !== false;
    @umask($umask);
    if (!$ok) return ['', 'zapis pliku nie powiódł się: ' . $path];
    @chmod($path, 0640);
    return [$path, ''];
}

/**
 * Enregistre le formulaire. Un champ laissé sur son masque (•••) ne touche à
 * rien : c'est la façon de modifier un réglage sans retaper les secrets.
 * Renvoie la liste des clés modifiées — les VALEURS ne sont jamais renvoyées.
 */
function wsm_settings_save(PDO $pdo, array $post, string $actor = '', array &$refus = [], ?array $groupes = null): array {
    $refus = [];
    $fields = wsm_settings_fields();
    // UN ÉCRAN N'ÉCRIT QUE SES GROUPES. Sans ce filtre, un formulaire forgé
    // sur Integracje pouvait écrire n'importe quel réglage — y compris la clé
    // Stripe de la plateforme, qui n'appartient pas à la boutique.
    if ($groupes !== null) $fields = array_filter($fields, fn($f) => in_array($f[0], $groupes, true));
    $now = date('Y-m-d H:i:s');
    $changed = [];
    $up = $pdo->prepare("UPDATE wsm_settings SET val = ?, secret = ?, updated_at = ?, updated_by = ? WHERE cle = ?");
    $ins = $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,?,?,?)");
    foreach ($fields as $key => [$grp, $label, $path, $env, $type, $help]) {
        $formKey = str_replace('.', '__', $key);
        // Un <input type="file"> seul n'apparaît PAS dans $_POST : sans cette
        // exception, le champ image sortait de la boucle avant d'être lu, et
        // l'envoi ne faisait rien sans rien dire.
        $poste = array_key_exists($formKey, $post)
              || (wsm_setting_obraz($type) && (isset($_FILES[$formKey]) || !empty($post[$formKey . '__usun'])))
              // Une case décochée n'est PAS envoyée : sans ce témoin, tout
              // décocher ne changerait rien, et « rien » serait impossible.
              || (str_starts_with($type, 'multi:') && !empty($post[$formKey . '__multi']));
        if (!$poste) continue;
        if (str_starts_with($type, 'multi:')) {
            // Les codes connus, dans l'ordre du dictionnaire ; aucun = « brak ».
            $permis = array_map(fn($o) => explode(':', $o, 2)[0], explode('|', substr($type, 6)));
            $coches = array_map('strval', (array) ($post[$formKey] ?? []));
            $v = implode(',', array_values(array_filter($permis, fn($c) => in_array($c, $coches, true))));
            if ($v === '') $v = 'brak';
        } else {
        $v = trim((string) ($post[$formKey] ?? ''));
        }
        if ($type === 'secret' && ($v === '' || str_starts_with($v, '•'))) continue;   // inchangé
        if (wsm_setting_obraz($type)) {
            // Le formulaire poste un champ texte VIDE et, à côté, un fichier.
            // Vide + aucun fichier = on ne touche à rien : sinon enregistrer
            // l'écran effacerait l'image à chaque passage, exactement comme le
            // « Zapisz » qui effaçait la photo d'un produit.
            $sup = !empty($post[$formKey . '__usun']);
            $f = $_FILES[$formKey] ?? null;
            if (!$sup && (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) continue;
            if ($sup) {
                $v = WSM_SETTING_PLACEHOLDER;
            } else {
                require_once __DIR__ . '/media.php';
                [$url, $err] = wsm_media_store($f, wsm_setting_alpha($type));
                if ($err !== null) { $refus[$key] = $err; continue; }
                $v = (string) $url;
            }
        }
        if ($type === 'pem') {
            // Vide = on ne touche à rien : le champ s'affiche toujours vide,
            // sinon il faudrait recoller la clé à chaque enregistrement.
            if ($v === '') continue;
            [$chemin, $err] = wsm_setting_pem_store($v);
            // UN REFUS SE DIT. Écrit en silence, il laisserait croire la clé
            // posée, et la session KSeF échouerait plus tard, ailleurs.
            if ($err !== '') { $refus[$key] = $err; continue; }
            $v = $chemin;
        }
        if ($type === 'text' && $v === '') $v = WSM_SETTING_PLACEHOLDER;               // vider = revenir au masque

        $up->execute([$v, $type === 'secret' ? 1 : 0, $now, mb_substr($actor, 0, 120), $key]);
        if ($up->rowCount() === 0) {
            try { $ins->execute([$key, $v, $type === 'secret' ? 1 : 0, $now, mb_substr($actor, 0, 120)]); }
            catch (Throwable $e) { continue; }
        }
        $changed[] = $key;
    }
    return $changed;
}

// ---------------------------------------------------------------------------
//  Les clés de TOUTES les intégrations, d'un coup (Superadmin → Klucze)
// ---------------------------------------------------------------------------

/**
 * Les groupes qui sont des intégrations — et rien d'autre. La base de données,
 * le jeton d'administration, la liste et le code du superadmin ne se collent
 * JAMAIS depuis un navigateur : ce sont les clés de la maison, pas des
 * intégrations, et qui les aurait pourrait se donner le reste.
 */
const WSM_SETTINGS_KLUCZE = [
    'claude'  => 'Claude (AI)',
    'stripe'  => 'Stripe — płatności',
    'ksef'    => 'KSeF',
    'paragon' => 'Fakturownia + Paragony.pl',
    'inpost'  => 'InPost',
    'dpd'     => 'DPD',
    'mail'    => 'Poczta (SMTP)',
    'sms'     => 'SMSAPI',
    'allegro' => 'Allegro',
    'google'  => 'Google (Search Console, GA4, Ads)',
    'meta'    => 'Meta (Facebook, Instagram)',
];

/** Les champs de ces groupes, dans l'ordre des groupes (les réglages de politique en moins). */
function wsm_settings_klucze_fields(): array {
    $out = [];
    $f = wsm_settings_fields();
    foreach (array_keys(WSM_SETTINGS_KLUCZE) as $g) {
        foreach ($f as $k => $d) {
            if ($d[0] !== $g || $k === 'marketing.klienci_bez_zgody') continue;   // une décision, pas une clé
            $out[$k] = $d;
        }
    }
    return $out;
}

/**
 * Lit un bloc collé : un fichier .env, une liste « NOM=valeur », deux
 * colonnes copiées d'un tableur. Le nom peut être la variable du serveur
 * (WSM_STRIPE_SECRET_KEY, ou sans « WSM_ ») ou la clé du réglage
 * (stripe.secret_key). Une clé PEM peut tenir sur plusieurs lignes.
 *
 * Rien n'est écrit ici : on rend ce qui sera enregistré, au format du
 * formulaire, et ce qui ne l'a pas été — par NOM seulement, jamais la valeur.
 *
 * @return array{wartosci: array<string,string>, nieznane: list<string>, bledne: array<string,string>}
 */
function wsm_settings_parse_paste(string $tekst): array {
    $champs = wsm_settings_klucze_fields();
    $parNom = [];
    foreach ($champs as $k => $d) {
        $parNom[strtoupper($d[3])] = $k;                              // WSM_STRIPE_SECRET_KEY
        $parNom[strtoupper(preg_replace('/^WSM_/', '', $d[3]))] = $k;  // STRIPE_SECRET_KEY
        $parNom[strtoupper($k)] = $k;                                  // STRIPE.SECRET_KEY
        $parNom[strtoupper(str_replace('.', '__', $k))] = $k;          // STRIPE__SECRET_KEY
    }
    $out = ['wartosci' => [], 'nieznane' => [], 'bledne' => []];
    $lignes = preg_split('/\r\n|\r|\n/', $tekst) ?: [];
    for ($i = 0; $i < count($lignes); $i++) {
        $l = trim($lignes[$i]);
        if ($l === '' || str_starts_with($l, '#') || str_starts_with($l, '//')) continue;
        $l = (string) preg_replace('/^export\s+/i', '', $l);
        if (!preg_match('/^["\']?([A-Za-z][A-Za-z0-9_.]*)["\']?\s*(?:=|:|;|\t)\s*(.*)$/s', $l, $m)) continue;
        $nom = strtoupper($m[1]);
        $v = trim($m[2]);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = substr($v, 1, -1);
        if (!isset($parNom[$nom])) { $out['nieznane'][] = $m[1]; continue; }
        $k = $parNom[$nom];
        $type = (string) $champs[$k][4];
        // Une clé PEM continue jusqu'à sa ligne « -----END … -----».
        if ($type === 'pem' && str_starts_with($v, '-----BEGIN') && !str_contains($v, '-----END')) {
            while (++$i < count($lignes)) {
                $v .= "\n" . trim($lignes[$i]);
                if (str_contains($lignes[$i], '-----END')) break;
            }
        }
        if ($v === '') continue;
        if (str_starts_with($type, 'select:')) {
            $opts = explode('|', substr($type, 7));
            $vv = strtolower($v);
            if ($opts === ['1', '0']) $vv = in_array($vv, ['1', 'true', 'tak', 'yes', 'on'], true) ? '1' : (in_array($vv, ['0', 'false', 'nie', 'no', 'off'], true) ? '0' : $vv);
            if (!in_array($vv, $opts, true)) { $out['bledne'][$m[1]] = 'dozwolone: ' . implode(', ', $opts); continue; }
            $v = $vv;
        }
        $out['wartosci'][str_replace('.', '__', $k)] = $v;
    }
    return $out;
}

/**
 * Ce que l'écran affiche : par champ, la valeur montrable, sa provenance et
 * son état. Un secret n'est JAMAIS renvoyé — seulement le fait qu'il existe.
 */
function wsm_settings_view(PDO $pdo): array {
    $vals = wsm_settings_all($pdo);
    $out = [];
    foreach (wsm_settings_fields() as $key => [$grp, $label, $path, $env, $type, $help]) {
        $db = (string) ($vals[$key] ?? '');
        $effective = wsm_config_at($path);
        $set = is_bool($effective) ? true : (is_int($effective) ? $effective !== 0 : !wsm_setting_blank((string) $effective));

        $source = 'brak';
        if (getenv($env) !== false)                      $source = 'serwer';
        elseif (!wsm_setting_blank($db))                 $source = 'baza';
        elseif (str_starts_with($type, 'select:'))       $source = 'domyślne';
        elseif ($set)                                    $source = 'serwer';

        if ($type === 'secret') {
            $show = $set ? str_repeat('•', 12) : WSM_SETTING_PLACEHOLDER;
        } elseif ($type === 'select:1|0') {
            $show = ($effective === true || $effective === '1' || $effective === 1) ? '1' : '0';
        } else {
            $show = is_scalar($effective) && (string) $effective !== '' ? (string) $effective : WSM_SETTING_PLACEHOLDER;
            if ($key === 'mail.smtp_secure' && $show === WSM_SETTING_PLACEHOLDER) $show = 'tls';
        }

        $out[$key] = [
            'group' => $grp, 'label' => $label, 'type' => $type, 'help' => $help,
            'env' => $env, 'show' => $show, 'set' => $set, 'source' => $source,
            'form' => str_replace('.', '__', $key),
            'locked' => getenv($env) !== false,
        ];
    }
    return $out;
}
