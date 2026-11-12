<?php
// ============================================================================
//  config.php — connection + auth config for the webshop_mrszoko API.
//  Everything is env-driven (no secrets hardcoded). Copy nothing: this file
//  reads the environment and falls back to a local SQLite database so the API
//  runs out-of-the-box for dev / CI / the end-to-end delivery test.
//
//  Production (MySQL):
//    WSM_DB_ENGINE=mysql
//    WSM_DB_HOST=127.0.0.1  WSM_DB_PORT=3306
//    WSM_DB_NAME=mrszoko
//    WSM_DB_USER=...  WSM_DB_PASS=...
//    WSM_ADMIN_TOKEN=<service token>   (en-tête X-Admin-Token, automatisation)
//
//  Les humains ne se connectent PAS avec ce jeton : ils ouvrent une session
//  (e-mail + mot de passe) — voir auth.php. Le jeton n'a pas de valeur par
//  défaut : s'il est vide, la voie jeton est désactivée.
//
//  Local / CI (SQLite, default):
//    WSM_DB_ENGINE=sqlite (default)
//    WSM_SQLITE_PATH=<file>  (default: php-api/data/webshop_mrszoko.sqlite)
// ============================================================================

$cfg = [
    'engine'      => getenv('WSM_DB_ENGINE') ?: 'sqlite',
    'mysql'       => [
        'host'    => getenv('WSM_DB_HOST') ?: '127.0.0.1',
        'port'    => getenv('WSM_DB_PORT') ?: '3306',
        'name'    => getenv('WSM_DB_NAME') ?: 'webshop_mrszoko',
        'user'    => getenv('WSM_DB_USER') ?: 'root',
        'pass'    => getenv('WSM_DB_PASS') ?: '',
    ],
    'sqlite_path' => getenv('WSM_SQLITE_PATH') ?: (__DIR__ . '/data/webshop_mrszoko.sqlite'),
    // Jeton de SERVICE (automatisation / tests), en-tête X-Admin-Token.
    // AUCUNE valeur par défaut : vide = voie jeton entièrement désactivée
    // (fail-closed). En production il est écrit dans config.local.php ; en
    // local, exportez WSM_ADMIN_TOKEN (voir serve.sh).
    'admin_token' => getenv('WSM_ADMIN_TOKEN') ?: '',
    // Partage inter-origine : vide = aucun en-tête CORS émis. Le front est
    // servi sur la MÊME origine que l'API (/mrszoko/backoffice → ./api), donc
    // rien n'est nécessaire. Ne renseigner qu'une origine précise, jamais '*' :
    // les requêtes portent une session par cookie.
    'cors_origin' => getenv('WSM_CORS_ORIGIN') ?: '',

    // Adresse publique de la boutique — sert à composer les URL de retour et
    // de notification envoyées à tpay. Vide : déduite de la requête en cours.
    'shop_url' => getenv('WSM_SHOP_URL') ?: '',

    // Le design system de CETTE boutique (design-systems/<slug>/) : couleurs,
    // polices, logo par défaut. Toutes les boutiques partagent le code ;
    // celle-ci porte celui-là. Réglé par le serveur, jamais depuis la console
    // — voir ds.php. Inconnu ou absent : « szoko ».
    'design_system' => getenv('WSM_DESIGN_SYSTEM') ?: 'szoko',

    // ---- Vitrine : ce qui se règle depuis la console -----------------------
    // VIDE PAR DÉFAUT, et ce n'est pas un détail : wsm_settings_apply() laisse
    // la main au fichier de configuration dès qu'il a posé une valeur non
    // vide. Une valeur en dur ici rendrait le champ de l'écran Ustawienia
    // inopérant — il accepterait l'image, dirait « Zapisano », et rien ne
    // changerait sur le site.
    'shop' => [
        'hero_image'     => getenv('WSM_SHOP_HERO') ?: '',
        // Les icônes des trois promesses de la page d'accueil. Même règle :
        // vides ici, déposées depuis Ustawienia → Sklep.
        'promise_icon_1' => getenv('WSM_SHOP_ICON_1') ?: '',
        'promise_icon_2' => getenv('WSM_SHOP_ICON_2') ?: '',
        'promise_icon_3' => getenv('WSM_SHOP_ICON_3') ?: '',
        'logo_image'     => getenv('WSM_SHOP_LOGO') ?: '',
        // Les logos de livraison et de paiement montrés au client (metody.php).
        // Vides ici : la liste suit alors la réalité (modes actifs, tpay) ;
        // Integracje → Sklep permet de choisir à la main.
        'metody_wysylki'   => getenv('WSM_SHOP_METODY_WYSYLKI') ?: '',
        'metody_platnosci' => getenv('WSM_SHOP_METODY_PLATNOSCI') ?: '',
        // Le nom à côté du logo, dans la barre du haut. Un logo déposé qui
        // porte déjà le nom l'afficherait deux fois : « 0 » le retire.
        'logo_nazwa'       => (getenv('WSM_SHOP_LOGO_NAZWA') ?: '1') !== '0',
        // L'adresse où le client renvoie un colis (retour, réclamation),
        // exigée par tpay : dans le règlement, en pied de page, sur la page
        // « Zwroty ». Vide ici : tant que rue, code et ville ne sont pas
        // posés dans Integracje → Sklep, c'est l'adresse du siège (Treści,
        // seller.address) qui s'affiche — jamais une adresse à moitié.
        'zwrot_odbiorca'   => getenv('WSM_SHOP_ZWROT_ODBIORCA') ?: '',
        'zwrot_ulica'      => getenv('WSM_SHOP_ZWROT_ULICA') ?: '',
        'zwrot_kod'        => getenv('WSM_SHOP_ZWROT_KOD') ?: '',
        'zwrot_miasto'     => getenv('WSM_SHOP_ZWROT_MIASTO') ?: '',
    ],

    // ---- tpay.com : encaissement ------------------------------------------
    // AUCUN défaut. Sans client_id/secret, aucune transaction n'est créée ;
    // sans security_code, aucune notification n'est acceptée (fail-closed).
    // Ces valeurs vivent dans config.local.php sur le serveur, jamais ici :
    // ce dépôt est public.
    // ---- Stripe Connect : TOUS les paiements -----------------------------
    // Les clés sont celles de la PLATEFORME (le propriétaire de l'outil) : la
    // clé secrète ouvre tous les comptes connectés, elle ne se saisit donc que
    // dans l'écran Płatności réservé au superadmin, ou ici par l'environnement
    // (secrets GitHub WSM_STRIPE_*). `account` est le compte connecté de la
    // boutique (acct_…), créé depuis l'écran Płatności.
    'stripe' => [
        'secret_key'      => getenv('WSM_STRIPE_SECRET_KEY') ?: '',
        'publishable_key' => getenv('WSM_STRIPE_PUBLISHABLE_KEY') ?: '',
        'webhook_secret'  => getenv('WSM_STRIPE_WEBHOOK_SECRET') ?: '',
        'account'         => getenv('WSM_STRIPE_ACCOUNT') ?: '',
    ],

    // ---- VIES : vérification des numéros de TVA intracommunautaire ---------
    // Service public de la Commission européenne : aucun identifiant requis.
    // `requester` est NOTRE numéro de TVA — sans lui, VIES ne délivre pas de
    // numéro de consultation, et il n'y a donc pas de preuve opposable.
    // `enabled=0` coupe l'appel réseau (utile en CI ou hors ligne) : les
    // numéros ne sont alors validés que sur la forme.
    'vies' => [
        'enabled'   => (getenv('WSM_VIES_ENABLED') ?: '1') !== '0',
        // Notre numéro de TVA. Ce n'est PAS un secret — il figure au registre
        // public KRS — et il doit être présent pour que VIES délivre un numéro
        // de consultation opposable. Surchargeable par l'environnement.
        'requester' => getenv('WSM_VIES_REQUESTER') ?: 'PL8971902620',
        'timeout'   => (int) (getenv('WSM_VIES_TIMEOUT') ?: 6),
        'ttl'       => (int) (getenv('WSM_VIES_TTL') ?: 2592000),
    ],

    // ---- Poczta : les messages envoyés aux clients -------------------------
    // Tout est vide par défaut : sans adresse d'expéditeur, la messagerie
    // n'envoie rien et garde les messages en file, visibles dans la console.
    // Ces réglages se saisissent aussi depuis le back-office (écran
    // Ustawienia) — mais ce qui est posé ici l'emporte. Le mot de passe SMTP
    // n'a rien à faire dans ce fichier : ce dépôt est public.
    'mail' => [
        'transport'   => getenv('WSM_MAIL_TRANSPORT') ?: '',   // mail | smtp
        'from'        => getenv('WSM_MAIL_FROM') ?: '',
        'from_name'   => getenv('WSM_MAIL_FROM_NAME') ?: '',
        'reply_to'    => getenv('WSM_MAIL_REPLY_TO') ?: '',
        'bcc'         => getenv('WSM_MAIL_BCC') ?: '',
        'smtp_host'   => getenv('WSM_MAIL_SMTP_HOST') ?: '',
        'smtp_port'   => (int) (getenv('WSM_MAIL_SMTP_PORT') ?: 0),
        'smtp_user'   => getenv('WSM_MAIL_SMTP_USER') ?: '',
        'smtp_pass'   => getenv('WSM_MAIL_SMTP_PASS') ?: '',
        'smtp_secure' => getenv('WSM_MAIL_SMTP_SECURE') ?: '',
    ],

    // ---- Faktury : les mentions du document --------------------------------
    // Le NIP et l'adresse figurent au registre public KRS : ce ne sont pas des
    // secrets. Le numéro de compte non plus — il est destiné à être imprimé.
    // Ils vivent néanmoins en base (écran Ustawienia) pour se corriger sans
    // redéploiement ; ce qui est posé ici l'emporte.
    'invoice' => [
        'seller_name'    => getenv('WSM_INV_SELLER_NAME') ?: 'ATELIER WRO01 sp. z o.o.',
        'seller_nip'     => getenv('WSM_INV_SELLER_NIP') ?: '8971902620',
        'seller_address' => getenv('WSM_INV_SELLER_ADDRESS') ?: 'ul. Stanisława Leszczyńskiego 4/29, 50-078 Wrocław, Polska',
        'place'          => getenv('WSM_INV_PLACE') ?: 'Wrocław',
        'iban'           => getenv('WSM_INV_IBAN') ?: '',
        'bank'           => getenv('WSM_INV_BANK') ?: '',
        'payment_days'   => getenv('WSM_INV_PAYMENT_DAYS') ?: '',
        'number_format'  => getenv('WSM_INV_NUMBER_FORMAT') ?: '',
        'reminder_days'  => getenv('WSM_INV_REMINDER_DAYS') ?: '',
    ],

    // ---- InPost ShipX : expédition ----------------------------------------
    // geowidget_token est le jeton PUBLIC du sélecteur de Paczkomat (il part
    // dans la page) ; token est le jeton SERVEUR ShipX (il n'en sort jamais).
    'inpost' => [
        'token'           => getenv('WSM_INPOST_TOKEN') ?: '',
        'organization_id' => getenv('WSM_INPOST_ORG_ID') ?: '',
        'geowidget_token' => getenv('WSM_INPOST_GEOWIDGET_TOKEN') ?: '',
        'sending_method'  => getenv('WSM_INPOST_SENDING_METHOD') ?: 'parcel_locker',
        'sandbox'         => (getenv('WSM_INPOST_SANDBOX') ?: '1') !== '0',
    ],

    // ---- DPD Polska : expédition à l'adresse, Pologne et Europe ------------
    //  FERMÉ tant que ces champs sont vides, et « xxxx » compte pour vide.
    //  L'adresse d'expéditeur n'est pas décorative : DPD l'imprime sur
    //  l'étiquette, et un colis refusé à l'arrivée sans adresse de retour ne
    //  revient jamais. L'API est du SOAP — l'extension php-soap doit être là.
    'dpd' => [
        'login'    => getenv('WSM_DPD_LOGIN') ?: '',
        'password' => getenv('WSM_DPD_PASSWORD') ?: '',
        'fid'      => getenv('WSM_DPD_FID') ?: '',
        'sandbox'  => (getenv('WSM_DPD_SANDBOX') ?: '1') !== '0',
        'sender_name'     => getenv('WSM_DPD_SENDER_NAME') ?: '',
        'sender_address'  => getenv('WSM_DPD_SENDER_ADDRESS') ?: '',
        'sender_city'     => getenv('WSM_DPD_SENDER_CITY') ?: '',
        'sender_postcode' => getenv('WSM_DPD_SENDER_POSTCODE') ?: '',
        'sender_country'  => getenv('WSM_DPD_SENDER_COUNTRY') ?: 'PL',
        'sender_phone'    => getenv('WSM_DPD_SENDER_PHONE') ?: '',
    ],

    // ---- Allegro : le second canal de vente --------------------------------
    //  FERMÉ TANT QUE CES CHAMPS SONT VIDES, et « xxxx » compte pour vide :
    //  c'est la valeur d'un champ de démonstration, et la prendre pour un
    //  identifiant ouvrirait une intégration sur du vent.
    //  Le secret et le jeton de rafraîchissement ne vivent QUE côté serveur
    //  (config.local.php ou variables d'environnement) — jamais dans le dépôt,
    //  qui est public.
    'allegro' => [
        'client_id'     => getenv('WSM_ALLEGRO_CLIENT_ID') ?: '',
        'client_secret' => getenv('WSM_ALLEGRO_CLIENT_SECRET') ?: '',
        'refresh_token' => getenv('WSM_ALLEGRO_REFRESH_TOKEN') ?: '',
        'seller_id'     => getenv('WSM_ALLEGRO_SELLER_ID') ?: '',
        'sandbox'       => (getenv('WSM_ALLEGRO_SANDBOX') ?: '1') !== '0',
    ],

    // ---- KSeF : le registre national des factures --------------------------
    //  FERMÉ TANT QUE CES CHAMPS SONT VIDES, et « xxxx » compte pour vide.
    //  Le jeton d'autorisation est délivré par l'application KSeF au NIP du
    //  vendeur : il vaut signature sur toutes nos factures, et n'a donc rien
    //  à faire dans ce dépôt, qui est public. `public_key` est le CHEMIN sur
    //  le serveur du fichier de clé publique du ministère — sans lui le
    //  jeton ne peut pas être chiffré, donc aucune session ne s'ouvre.
    //  `env` : test (défaut) | demo | prod.
    'ksef' => [
        'nip'        => getenv('WSM_KSEF_NIP') ?: '',
        'token'      => getenv('WSM_KSEF_TOKEN') ?: '',
        'public_key' => getenv('WSM_KSEF_PUBLIC_KEY') ?: '',
        // VIDE, pas 'test' : une valeur en dur ici rendrait le champ de
        // l'écran Ustawienia inopérant — wsm_settings_apply() laisse la main
        // au fichier dès qu'il a parlé. Le repli sur 'test' vit dans
        // wsm_ksef_cfg(), qui valide aussi la valeur contre test|demo|prod.
        'env'        => getenv('WSM_KSEF_ENV') ?: '',
    ],

    // ---- SMS marketing : SMSAPI (smsapi.php) -------------------------------
    //  FERMÉ TANT QUE LE JETON ET L'EXPÉDITEUR SONT VIDES. `token` = jeton
    //  OAuth du panneau SMSAPI (permission « sms ») ; `nadawca` = le nom
    //  d'expéditeur validé chez SMSAPI ; `region` = pl (smsapi.pl, défaut) ou
    //  com ; `cena` = prix d'un SMS en złotys, pour l'estimation du coût.
    // ---- Marketing : à qui la newsletter a le droit d'écrire --------------
    //  FERMÉ PAR DÉFAUT. Depuis le 10 novembre 2024, un e-mail commercial
    //  demande un consentement PRÉALABLE (Prawo komunikacji elektronicznej,
    //  art. 398), et la Pologne n'a pas repris clairement l'exception
    //  « clients existants » (soft opt-in) de la directive. Fermé : la
    //  newsletter ne part qu'aux personnes qui ont coché la case et confirmé
    //  par e-mail. Ouvert (1) : aussi aux acheteurs sans case cochée, à ne
    //  décider qu'avec l'avis d'un juriste.
    'marketing' => [
        'klienci_bez_zgody' => (getenv('WSM_MKT_KLIENCI_BEZ_ZGODY') ?: '0') === '1',
    ],

    'smsapi' => [
        'token'   => getenv('WSM_SMSAPI_TOKEN') ?: '',
        'nadawca' => getenv('WSM_SMSAPI_NADAWCA') ?: '',
        'region'  => getenv('WSM_SMSAPI_REGION') ?: '',
        'cena'    => getenv('WSM_SMSAPI_CENA') ?: '',
    ],

    // ---- Caisse fiscale : Fakturownia + Paragony.pl (fiskal.php) -----------
    //  FERMÉ TANT QUE LE DOMAINE ET LE JETON SONT VIDES. `domena` = le compte
    //  (szoko.fakturownia.pl), `api_token` = Ustawienia → Integracja → Kod
    //  autoryzacyjny API, `drukarka` = le nom de l'imprimante (vide = celle
    //  par défaut), `webhook_token` = un mot inventé, recopié dans le webhook
    //  côté Fakturownia. Le jeton émet des documents fiscaux au nom de la
    //  société : jamais dans ce dépôt, qui est public.
    'fakturownia' => [
        'domena'        => getenv('WSM_FAKTUROWNIA_DOMENA') ?: '',
        'api_token'     => getenv('WSM_FAKTUROWNIA_TOKEN') ?: '',
        'drukarka'      => getenv('WSM_FAKTUROWNIA_DRUKARKA') ?: '',
        'webhook_token' => getenv('WSM_FAKTUROWNIA_WEBHOOK_TOKEN') ?: '',
    ],

    // ---- Superadmin : le propriétaire de la plateforme ---------------------
    // Ce module calcule ce que la boutique DOIT au propriétaire (location +
    // commission). Il ne peut donc pas être ouvert par un rôle attribuable
    // depuis la console : un compte Centrala qui pourrait se hisser
    // superadmin pourrait réécrire sa propre facture. L'identité vient d'ICI
    // et de nulle part ailleurs — ni d'une colonne, ni d'une case à cocher.
    //
    // Liste d'adresses séparées par des virgules. VIDE = module entièrement
    // fermé (fail-closed) : invisible dans le rail, et la page répond 404.
    // En production, elle vit dans config.local.php, jamais ici.
    'superadmin_emails' => getenv('WSM_SUPERADMIN_EMAILS') ?: '',
    // Le nombre de BASE du code du jour de l'écran plateforme. Le chiffre du
    // jour de la semaine s'ajoute tout seul (voir platform.php). Vide = pas de
    // second verrou. JAMAIS de valeur ici : ce dépôt est public.
    'superadmin_code'   => getenv('WSM_SUPERADMIN_CODE') ?: '',

    // ---- Claude : traduction automatique du contenu -------------------------
    // Sert à remplir les traductions MANQUANTES du site (voir translate.php).
    // AUCUN défaut : sans clé, le bouton n'apparaît pas et rien n'est à
    // moitié fait. La clé vit dans config.local.php sur le serveur — ce dépôt
    // est public, elle n'a rien à y faire.
    'anthropic_api_key' => getenv('WSM_ANTHROPIC_API_KEY') ?: '',
];

// Optional local override (untracked, gitignored): drop a config.local.php on the
// server that `return`s an array of keys to override — e.g. real MySQL creds and
// the admin token — without editing this file or setting env vars.
//   <?php return ['engine'=>'mysql','mysql'=>[...],'admin_token'=>'...'];
if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    if (is_array($local)) $cfg = array_replace_recursive($cfg, $local);
}

return $cfg;
