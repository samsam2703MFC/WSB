<?php
// ============================================================================
//  seed.php — reference/demo data for webshop_mrszoko (engine-agnostic).
//  This is the ONLY place default business data lives on the server side; it
//  mirrors the front-end dev seed so behaviour is identical whether the API is
//  live or not. Runs once on a fresh database (see wsm_bootstrap()).
// ============================================================================

require_once __DIR__ . '/commerce.php';

function wsm_seed(PDO $pdo): void {
    $ins = function(string $table, array $row) use ($pdo) {
        $cols = array_keys($row);
        $ph = array_map(fn($c) => ':' . $c, $cols);
        $sql = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
        $st = $pdo->prepare($sql);
        $st->execute($row);
        return (int) $pdo->lastInsertId();
    };

    $pdo->beginTransaction();

    // ---- KPIs : AUCUN, et c'est le sujet ------------------------------------
    // Ces tuiles étaient six chaînes écrites à la main : « Obrót sieci
    // 428 k€ », « Aktywne sklepy 14 / 15 », « Adopcja whitelisty 82 % ». En
    // euros, pour une boutique qui facture en złoty ; pour un réseau de quinze
    // points de vente qui n'existe pas ; avec des flèches de progression
    // inventées. Et c'était la PREMIÈRE chose qu'on voyait en se connectant.
    //
    // Sur un tableau de bord, un nombre plausible ne se met pas en doute : il
    // se retient, et il sert à décider. Les tuiles sont désormais calculées
    // (voir /franchisor/kpis), depuis la même fonction que l'écran Pulpit.
    // La table reste, vide : plus personne ne la lit.

    // ---- Shops --------------------------------------------------------------
    // AUCUNE. La maquette d'origine peuplait cinq boutiques belges — Bruxelles-
    // Centre, Anderlecht, Uccle, Schaerbeek, Louvain — avec leur chiffre
    // d'affaires et leur taux d'adoption. C'était le décor d'une démonstration
    // de franchise ; l'affaire réelle est UNE boutique à Wrocław.
    //
    // Des données de démonstration qui ressemblent à des vraies sont pires que
    // pas de données du tout : on lit « 29 800 » sur un tableau de bord et on
    // en tire une conclusion. Elles sont retirées ici pour les installations
    // neuves, et `php migrate.php --purge-demo-shops` les retire des bases
    // déjà en service.
    //
    // La table reste : elle sert au périmètre des utilisateurs (wsm_user_shops)
    // et aux zones de chalandise. Elle est simplement vide tant que personne
    // n'ouvre un second point de vente.

    // ---- Categories (menu_default from the menu seed) -----------------------
    $catDefaults = ['Menu i zestawy' => 1, 'Katering' => 1];
    $cats = ['Pieczywo', 'Ciasta świeże', 'Czekolada', 'Katering', 'Lody', 'Menu i zestawy'];
    $catId = [];
    foreach ($cats as $i => $name) {
        $catId[$name] = $ins('wsm_categories', ['name' => $name, 'sort_order' => $i,
            'menu_default' => $catDefaults[$name] ?? 0, 'brand_whitelist' => 1,
            'office_delivery' => ($name === 'Katering' ? 1 : 0), 'brand_mandatory' => 0, 'active' => 1]);
    }

    // ---- Products (catalogue + menu products) -------------------------------
    // [id, category, nom, prix, base_cost, statut, saison, bw, bm, ad, menu_override]
    $products = [
        ['p-baguette', 'Pieczywo', 'Bagietka tradycyjna', 1.35, 0.40, 'Opublikowany', '', 1, 1, 96, null, [280, 650, 90, 90]],
        ['p-pain-choco', 'Pieczywo', 'Czekoladowa drożdżówka', 1.60, 0.55, 'Opublikowany', '', 1, 0, 74, null, [90, 140, 90, 60]],
        ['p-eclair', 'Ciasta świeże', 'Ekler czekoladowy', 3.50, 1.20, 'Opublikowany', '', 1, 1, 88, null, [120, 160, 60, 50]],
        ['p-tarte-fraises', 'Ciasta świeże', 'Tarta truskawkowa', 4.20, 1.60, 'Sezonowy', 'Lato', 1, 0, 52, null, [850, 260, 260, 60]],
        ['p-buche', 'Ciasta świeże', 'Rolada firmowa', 24.00, 9.00, 'Opublikowany', 'Boże Narodzenie', 1, 1, 100, null, [1200, 350, 120, 100]],
        ['p-macarons', 'Czekolada', 'Makaroniki (pudełko 24)', 19.90, 7.50, 'Opublikowany', '', 1, 0, 64, null, [420, 250, 180, 60]],
        ['p-quiche', 'Katering', 'Quiche lorraine', 5.80, 2.20, 'Szkic', '', 0, 0, 22, null, [600, 240, 240, 45]],
        ['p-foiegras', 'Katering', 'Foie gras mi-cuit', 28.00, 12.00, 'Opublikowany', '', 1, 0, 41, null, [350, 150, 90, 70]],
        ['p-glace', 'Lody', 'Lody rzemieślnicze', 6.50, 2.10, 'Opublikowany', 'Lato', 0, 0, 30, null, [500, 120, 120, 110]],
        // menu products (menu builder)
        ['p-midi', 'Menu i zestawy', "Menu lunchowe — Szoko Shop", 8.50, 2.40, 'Opublikowany', '', 0, 0, 0, 'on'],
        ['p-gouter', 'Menu i zestawy', "Zestaw podwieczorkowy — Szoko Shop", 3.20, 0.90, 'Opublikowany', '', 0, 0, 0, 'on'],
        ['p-cafe', 'Menu i zestawy', "Café Gourmand — Szoko Shop", 6.50, 2.10, 'Opublikowany', '', 0, 0, 0, 'off'],
        ['p-brunch', 'Menu i zestawy', "Brunch weekendowy — Szoko Shop", 18.00, 5.50, 'Opublikowany', '', 0, 0, 0, null],
    ];
    foreach ($products as $i => $p) {
        // Logistique InPost + TVA tpay : gabarit déduit des dimensions.
        $lg = $p[11] ?? [230, 0, 0, 0];        // [poids g, L, l, H en mm]
        $ins('wsm_products', ['id' => $p[0], 'category_id' => $catId[$p[1]], 'nom' => $p[2],
            'prix' => $p[3], 'base_cost' => $p[4], 'statut' => $p[5], 'saison' => $p[6],
            'brand_whitelist' => $p[7], 'brand_mandatory' => $p[8], 'adoption' => $p[9],
            'menu_override' => $p[10], 'sort_order' => $i, 'active' => 1,
            'sku' => strtoupper(str_replace('p-', 'MS-', $p[0])), 'ean' => '',
            'vat_rate' => 0.23, 'weight_g' => $lg[0],
            'length_mm' => $lg[1], 'width_mm' => $lg[2], 'height_mm' => $lg[3],
            'parcel_template' => wsm_inpost_template($lg[1], $lg[2], $lg[3])]);
    }

    // ---- Menu builder tree (bundles → slots → choices) ----------------------
    // p-midi
    wsm_seed_bundle($ins, 'p-midi', 'b1', 'Zestaw pełny', 'Danie + napój + deser do wyboru', 4.50, 0, [
        ['s1', 'Danie', 1, 'single', 1, 1, 0, [
            ['c1', 'Quiche lorraine', 'a', 0, 1.10, 0], ['c2', 'Tost firmowy', 'b', 1.50, 1.60, 1], ['c3', 'Sałatka Cezar', 'd', 0, 1.30, 2]]],
        ['s2', 'Napój', 1, 'single', 1, 1, 1, [
            ['c4', 'Woda niegazowana 50cl', '', 0, 0.30, 0], ['c5', 'Napój 33cl', '', 0.50, 0.45, 1], ['c6', 'Świeżo wyciskany sok', 'c', 1.20, 0.90, 2]]],
        ['s3', 'Dodatki dla łasuchów', 0, 'multi', 0, 2, 2, [
            ['c7', 'Domowe ciastko', 'a', 2.00, 0.70, 0], ['c8', 'Kawałek tarty', 'b', 2.80, 1.10, 1], ['c9', 'Café gourmand', '', 3.20, 1.40, 2, 0]]],
    ]);
    wsm_seed_bundle($ins, 'p-midi', 'b2', 'Zestaw dziecięcy', 'Małe danie + syrop + niespodzianka', -1.00, 1, [
        ['s4', 'Małe danie', 1, 'single', 1, 1, 0, [
            ['c10', 'Mini tost', 'b', 0, 0.90, 0], ['c11', 'Domowe nuggetsy', 'a', 0, 1.10, 1]]],
        ['s5', 'Napój', 1, 'single', 1, 1, 1, [
            ['c12', "Syrop z wodą", '', 0, 0.20, 0], ['c13', 'Sok jabłkowy', 'c', 0, 0.40, 1]]],
    ]);
    // p-gouter
    wsm_seed_bundle($ins, 'p-gouter', 'gb1', 'Duet podwieczorkowy', 'Wypiek + gorący napój', 1.20, 0, [
        ['gs1', 'Wypiek', 1, 'single', 1, 1, 0, [
            ['gc1', 'Czekoladowa drożdżówka', 'b', 0, 0.50, 0], ['gc2', 'Rogalik migdałowy', 'a', 0.60, 0.65, 1]]],
        ['gs2', 'Gorący napój', 1, 'single', 1, 1, 1, [
            ['gc3', 'Kawa', '', 0, 0.35, 0], ['gc4', 'Gorąca czekolada', 'd', 0.50, 0.55, 1]]],
    ]);

    // ---- Vouchers -----------------------------------------------------------
    foreach ([
        ['MARQUE15', '−15 % na ciasta', 'Koszyk', 'kampania letnia'],
        ['BIENVENUE', 'Onboarding B2B', 'add_office', 'bezterminowo'],
        ['RENTREE10', '−10 € od 50 €', 'Kwota', 'wrz.'],
    ] as $v) $ins('wsm_vouchers', ['code' => $v[0], 'valeur' => $v[1], 'type' => $v[2], 'validite' => $v[3]]);

    // ---- Pricing rules ------------------------------------------------------
    foreach ([
        ['Wiosenne menu marki', 'Menu', '19,90 €'],
        ['Cennik sieciowy ciast', 'Ciasta świeże', 'cena stała'],
        ['Happy hour sieci', 'Pieczywo 18–19', '−20 %'],
    ] as $r) $ins('wsm_pricing_rules', ['nom' => $r[0], 'cible' => $r[1], 'effet' => $r[2], 'shop_id' => null]);

    // ---- Params -------------------------------------------------------------
    foreach ([
        ['admin.schema_reports', 'bool', '1'],
        ['webshop.enabled', 'bool', '1'],
        ['nav.icon_back', 'text', 'arrow-left'],
        ['delivery.enabled', 'bool', '1'],
        ['order.cutoff_default', 'text', '17:00'],
        ['brand.support_url', 'text', 'https://pomoc.misterszoko.com'],
    ] as $p) $ins('wsm_params', ['cle' => $p[0], 'type' => $p[1], 'val' => $p[2]]);

    // ---- Email templates ----------------------------------------------------
    foreach ([
        ['order_confirm', 'PL', 'Twoje zamówienie {{commande_ref}} jest potwierdzone'],
        ['order_ready', 'PL', 'Twoje zamówienie jest gotowe'],
        ['invoice', 'PL', 'Faktura {{commande_ref}}'],
        ['office_onboarding', 'PL', 'Witamy — Twoje konto {{bureau}}'],
        ['office_reject', 'PL', 'Twój wniosek o przyłączenie'],
        ['delivery_confirmed', 'PL', 'Twoja dostawa {{livraison_ref}} jest potwierdzona'],
    ] as $t) $ins('wsm_email_templates', ['cle' => $t[0], 'langue' => $t[1], 'sujet' => $t[2]]);

    // ---- Users + user↔shop scopes -------------------------------------------
    // Le périmètre des comptes franchise pointait sur les cinq boutiques
    // belges de démonstration. Celles-ci n'existent plus (voir plus haut), donc
    // les liens aussi disparaissent : une ligne wsm_user_shops qui désigne une
    // boutique absente n'est pas une donnée, c'est un compte qui ne voit rien.
    // Les comptes eux-mêmes restent — l'écran Użytkownicy a besoin de montrer
    // autre chose qu'une seule ligne — mais leur portée redevient le réseau.
    // Un compte par rôle : c'est ce qui rend l'écran Użytkownicy lisible dès la
    // première ouverture, et ce qui permet de VOIR ce que chaque rôle ouvre
    // sans créer quatre comptes à la main.
    $users = [
        ['Sophie Renard', 'sophie.renard@misterszoko.com', WSM_ROLE_ADMIN, 'Cała sieć', 1, []],
        ['Thomas Legrand', 'thomas.legrand@misterszoko.com', 'Sprzedaż', 'Cała sieć', 1, []],
        ['Marek Kowalski', 'm.kowalski@misterszoko.com', 'Magazyn', 'Cała sieć', 1, []],
        ['Julie Peeters', 'j.peeters@misterszoko.com', 'Księgowość', 'Cała sieć', 0, []],
    ];
    foreach ($users as $u) {
        $uid = $ins('wsm_users', ['nom' => $u[0], 'email' => $u[1], 'role' => $u[2], 'portee' => $u[3], 'act' => $u[4]]);
        foreach ($u[5] as $sid) $ins('wsm_user_shops', ['user_id' => $uid, 'shop_id' => $sid]);
    }

    // ---- Audit --------------------------------------------------------------
    foreach ([
        ['17/07 14:22', 'Sophie Renard', 'Zmiana', 'wsm_products #128 (brand_mandatory)', 'Sieć'],
        ['17/07 13:05', 'Thomas Legrand', 'Utworzenie', 'wsm_vouchers BXL10', 'Bruxelles-Centre'],
        ['17/07 11:40', 'Sophie Renard', 'Zmiana', 'wsm_params webshop.enabled', 'Sieć'],
        ['16/07 18:12', 'Marek Kowalski', 'Usunięcie', 'wsm_deliveries #44', 'Anderlecht'],
        ['16/07 09:30', 'Sophie Renard', 'Utworzenie', 'wsm_users j.peeters', 'Louvain'],
    ] as $a) $ins('wsm_audit', ['ts' => $a[0], 'user' => $a[1], 'verb' => $a[2], 'entity' => $a[3], 'shop' => $a[4]]);

    // ---- Catchment ----------------------------------------------------------
    foreach ([
        ['Bruxelles Capitale (19 gmin)', '1000 · 1020 · 1030 · 1040 · 1050', 1, 1, null],
        ['Brabant flamand — peryferie', '1600 · 1700 · 1800 · 3000', 1, 1, null],
    ] as $z) $ins('wsm_catchment', ['name' => $z[0], 'postcodes' => $z[1], 'exclusive' => $z[2], 'active' => $z[3], 'shop_id' => $z[4]]);

    // ---- Delivery module ----------------------------------------------------
    // Clients + their delivery points
    $clients = [
        ['CL-0021', 'Le Cirio SA', 'horeca', 'aktywny', 'BE 0421.111.222', '30 dni koniec mies.', 6000, 3200, '250 €', '8 %', 'Miesięczna',
            ['Brasserie — wejście od tyłu', 'Rue de la Bourse 18, 1000 Bruxelles', '08:00–11:00', 'Pn Wt Śr Cz Pt So', 'QR', 230, 50.8481, 4.3520, ['inpost_locker', 'WAW01M', 'Marszałkowska', '104', '00-026', 'Warszawa', '512340011', 'zamowienia@lecirio.pl']],
            ['firma', 'zamowienia@lecirio.pl', '512340011', '', '', '5252248481', 'PL5252248481', 'Marszałkowska', '104', '00-026', 'Warszawa', 'PL']],
        ['CL-0044', 'Rocco Forte', 'horeca', 'aktywny', 'BE 0455.222.333', '30 dni', 8000, 2600, '300 €', '10 %', 'Tygodniowa',
            ['Kuchnia — rampa serwisowa', "Rue de l'Amigo 1-3, 1000 Bruxelles", '07:30–10:00', 'Pn Wt Śr Cz Pt', 'PIN', 205, 50.8455, 4.3519, ['inpost_courier', '', 'Floriańska', '12', '31-019', 'Kraków', '512340022', 'kuchnia@roccoforte.pl']],
            ['firma', 'kuchnia@roccoforte.pl', '512340022', '', '', '6751745962', 'PL6751745962', 'Floriańska', '12', '31-019', 'Kraków', 'PL']],
        ['CL-0052', 'Belga SPRL', 'horeca', 'zawieszony', 'BE 0466.333.444', '7 dni', 4000, 4120, '—', '5 %', 'Za dostawę',
            ['Taras — dostęp Flagey', 'Place Eugène Flagey 18, 1050 Ixelles', '09:00–11:30', 'Wt Śr Cz Pt So', 'Podpis', 60, 50.8275, 4.3705, ['inpost_locker', 'LOD24A', 'Piotrkowska', '58', '90-105', 'Łódź', '512340033', 'biuro@belga.pl']],
            ['firma', 'biuro@belga.pl', '512340033', '', '', '9542752600', 'PL9542752600', 'Piotrkowska', '58', '90-105', 'Łódź', 'PL']],
        ['CL-0060', 'Dandoy', 'retail', 'aktywny', 'BE 0401.444.555', '30 dni', 5000, 1900, '200 €', '6 %', 'Miesięczna',
            ['Sklep Sablon — tył', 'Rue Charles Buls 14, 1000 Bruxelles', '08:00–10:30', 'Pn Śr Pt', 'QR', 180, 50.8459, 4.3524, ['inpost_courier', '', 'Długa', '7', '80-827', 'Gdańsk', '512340044', 'sklep@dandoy.pl']],
            ['firma', 'sklep@dandoy.pl', '512340044', '', '', '5213017228', 'PL5213017228', 'Długa', '7', '80-827', 'Gdańsk', 'PL']],
        ['CL-0071', 'KBC Group', 'corporate', 'aktywny', 'BE 0403.227.515', '30 dni koniec mies.', 12000, 5400, '400 €', '12 %', 'Miesięczna',
            ['Kafeteria HQ — hala dostaw', 'Havenlaan 2, 3000 Leuven', '07:00–09:00', 'Pn Wt Śr Cz Pt', 'PIN', -15, 50.8798, 4.7005, ['inpost_courier', '', 'Świdnicka', '40', '50-024', 'Wrocław', '512340055', 'kafeteria@kbc.pl']],
            ['firma', 'kafeteria@kbc.pl', '512340055', '', '', '5252248481', 'PL5252248481', 'Świdnicka', '40', '50-024', 'Wrocław', 'PL']],
        ['CL-0088', 'Événements Sud', 'event', 'prospekt', 'BE 0788.555.666', 'Gotówka', 2000, 0, '—', '0 %', 'Za dostawę',
            ['Zamek — dostęp kateringu', 'Chaussée de Bruxelles 100, 1410 Waterloo', '11:00–13:00', 'So Nd', 'Zostawienie', -78, 50.7147, 4.3990, ['inpost_locker', 'POZ103', 'Zamkowa', '3', '61-768', 'Poznań', '512340066', 'anna.nowak@example.pl']],
            ['osoba', 'anna.nowak@example.pl', '512340066', 'Anna', 'Nowak', '', '', 'Zamkowa', '3', '61-768', 'Poznań', 'PL']],
    ];
    $pointId = [];  // client code → point id
    foreach ($clients as $c) {
        // Champs tpay (payeur + facture) / InPost (destinataire) — NIP à somme
        // de contrôle valide, téléphones à 9 chiffres, codes postaux NN-NNN.
        $x = $c[12] ?? [];
        $cid = $ins('wsm_clients', ['code' => $c[0], 'raison' => $c[1], 'seg' => $c[2], 'statut' => $c[3],
            'tva' => $c[4], 'paiement' => $c[5], 'plafond' => $c[6], 'encours' => $c[7],
            'franco' => $c[8], 'remise' => $c[9], 'fact' => $c[10],
            'client_type' => $x[0] ?? 'firma', 'email' => $x[1] ?? '', 'phone' => $x[2] ?? '',
            'first_name' => $x[3] ?? '', 'last_name' => $x[4] ?? '', 'nip' => $x[5] ?? '',
            'vat_eu' => $x[6] ?? '', 'bill_street' => $x[7] ?? '', 'bill_building' => $x[8] ?? '',
            'bill_postcode' => $x[9] ?? '', 'bill_city' => $x[10] ?? '', 'bill_country' => $x[11] ?? 'PL']);
        $pt = $c[11];
        $px = $pt[8] ?? [];
        $pid = $ins('wsm_client_points', ['client_id' => $cid, 'libelle' => $pt[0], 'adresse' => $pt[1],
            'fenetre' => $pt[2], 'jours' => $pt[3], 'validation' => $pt[4], 'marge' => $pt[5],
            'lat' => $pt[6], 'lng' => $pt[7],
            'delivery_method' => $px[0] ?? 'inpost_courier', 'inpost_point' => $px[1] ?? '',
            'street' => $px[2] ?? '', 'building' => $px[3] ?? '', 'postcode' => $px[4] ?? '',
            'city' => $px[5] ?? '', 'country' => 'PL',
            'contact_phone' => $px[6] ?? '', 'contact_email' => $px[7] ?? '']);
        $pointId[$c[0]] = ['point' => $pid, 'client' => $cid, 'window' => $pt[2], 'validation' => $pt[4]];
    }

    // Drivers
    $driverId = [];
    foreach ([
        ['Marek Kowalski', 'BXL-Centre · Renault chłodnia', 'var(--brand)', 'Renault Master chłodnia', 'Bruxelles-Centre'],
        ['Julien Dubois', 'Południe · Iveco Daily', 'var(--sale-strong)', 'Iveco Daily', 'Południe'],
        ['Sofie Peeters', 'Wschód · Renault Kangoo', 'var(--success)', 'Renault Kangoo', 'Wschód'],
    ] as $d) {
        $driverId[$d[0]] = $ins('wsm_drivers', ['nom' => $d[0], 'info' => $d[1], 'color' => $d[2],
            'vehicule' => $d[3], 'zone' => $d[4], 'active' => 1]);
    }

    // Rounds
    $roundId = [];
    foreach ([
        ['Trasa Bruxelles-Centre', 'Marek Kowalski'],
        ['Trasa Południe', 'Julien Dubois'],
        ['Trasa Wschód', 'Sofie Peeters'],
    ] as $r) {
        $roundId[$r[0]] = $ins('wsm_rounds', ['name' => $r[0], 'driver_id' => $driverId[$r[1]] ?? null,
            'round_date' => null, 'status' => 'planifiée']);
    }

    // Deliveries (a few, in different statuses) + their event trails
    $mkDelivery = function(array $d) use ($ins, $pointId, $driverId, $roundId) {
        $c = $pointId[$d['client']];
        $did = $ins('wsm_deliveries', [
            'ref' => $d['ref'], 'client_id' => $c['client'], 'point_id' => $c['point'],
            'driver_id' => $d['driver'] ? ($driverId[$d['driver']] ?? null) : null,
            'round_id' => $d['round'] ? ($roundId[$d['round']] ?? null) : null,
            'status' => $d['status'], 'window_label' => $c['window'],
            'validation_method' => $c['validation'], 'confirm_code' => $d['code'] ?? '',
            'confirmed' => $d['confirmed'] ?? 0, 'ca' => $d['ca'], 'couts' => $d['couts'],
            'scheduled_date' => null, 'notes' => $d['notes'] ?? '',
            'confirmed_at' => ($d['confirmed'] ?? 0) ? '2026-07-17 09:40:00' : null,
        ]);
        foreach ($d['events'] as $e) {
            $ins('wsm_delivery_events', ['delivery_id' => $did, 'event' => $e[0], 'detail' => $e[1], 'actor' => $e[2]]);
        }
        return $did;
    };
    $mkDelivery(['ref' => 'LIV-2026-0001', 'client' => 'CL-0021', 'driver' => 'Marek Kowalski', 'round' => 'Trasa Bruxelles-Centre',
        'status' => 'livrée', 'confirmed' => 1, 'code' => 'QR-8842', 'ca' => 520, 'couts' => 210,
        'events' => [['créée', 'Dostawa zaplanowana', 'Sophie Renard'], ['assignée', 'Kierowca Marek Kowalski', 'Sophie Renard'],
            ['en_cours', 'Wyjazd na trasę BXL-Centre', 'Marek Kowalski'], ['livrée', 'Potwierdzona przez QR-8842', 'Marek Kowalski']]]);
    $mkDelivery(['ref' => 'LIV-2026-0002', 'client' => 'CL-0044', 'driver' => 'Marek Kowalski', 'round' => 'Trasa Bruxelles-Centre',
        'status' => 'en_cours', 'ca' => 300, 'couts' => 150,
        'events' => [['créée', 'Dostawa zaplanowana', 'Sophie Renard'], ['assignée', 'Kierowca Marek Kowalski', 'Sophie Renard'],
            ['en_cours', 'Wyjazd na trasę BXL-Centre', 'Marek Kowalski']]]);
    $mkDelivery(['ref' => 'LIV-2026-0003', 'client' => 'CL-0060', 'driver' => 'Julien Dubois', 'round' => 'Trasa Południe',
        'status' => 'assignée', 'ca' => 415, 'couts' => 260,
        'events' => [['créée', 'Dostawa zaplanowana', 'Sophie Renard'], ['assignée', 'Kierowca Julien Dubois', 'Sophie Renard']]]);
    $mkDelivery(['ref' => 'LIV-2026-0004', 'client' => 'CL-0071', 'driver' => null, 'round' => null,
        'status' => 'planifiée', 'ca' => 580, 'couts' => 312,
        'events' => [['créée', 'Dostawa zaplanowana', 'Sophie Renard']]]);

    // Incidents
    foreach ([
        ['INC-2026-0412', 'LIV-2026-0002', 'Uszkodzona paczka', 'Café Belga · Ixelles', 'Do obsłużenia', '24 €',
            'Pojemnik izotermiczny uderzony przy rozładunku. 2 słoiki konfitur stłuczone.', '50.8275, 4.3705'],
        ['INC-2026-0411', null, 'Brakująca paczka', 'Hôtel Amigo · Sablon', 'Do obsłużenia', '46 €',
            '1 oczekiwana paczka nieobecna przy skanie zdawczym.', '50.8451, 4.3520'],
        ['INC-2026-0407', null, 'Dostawa odrzucona', 'Event Château · Waterloo', 'W trakcie', '40 €',
            'Przyjazd poza oknem czasowym. Klient nieobecny, zostawienie odrzucone.', '50.7147, 4.3990'],
        ['INC-2026-0403', 'LIV-2026-0001', 'Zwrot kaucji', 'Maison Dandoy · Sablon', 'Rozwiązany', '0 €',
            '3 pojemniki kaucyjne odebrane w punkcie. Uzgodnienie OK.', '50.8410, 4.3560'],
    ] as $inc) {
        $did = null;
        if ($inc[1]) {
            $q = $pdo->prepare('SELECT id FROM wsm_deliveries WHERE ref = ?');
            $q->execute([$inc[1]]);
            $did = $q->fetchColumn() ?: null;
        }
        $ins('wsm_incidents', ['ref' => $inc[0], 'delivery_id' => $did, 'type' => $inc[2], 'point' => $inc[3],
            'statut' => $inc[4], 'impact' => $inc[5], 'description' => $inc[6], 'geo' => $inc[7]]);
    }

    $pdo->commit();

    wsm_seed_landing($pdo);
}

/** Helper: insert one bundle + its slots + choices. */
function wsm_seed_bundle(callable $ins, string $pid, string $bid, string $name, string $desc, float $mod, int $order, array $slots): void {
    $ins('wsm_bundles', ['id' => $bid, 'product_id' => $pid, 'name' => $name, 'description' => $desc,
        'price_modifier' => $mod, 'sort_order' => $order, 'active' => 1]);
    foreach ($slots as $s) {
        // [id, label, required, kind, min, max, order, choices]
        $ins('wsm_bundle_slots', ['id' => $s[0], 'bundle_id' => $bid, 'label' => $s[1], 'required' => $s[2],
            'kind' => $s[3], 'min_select' => $s[4], 'max_select' => $s[5], 'sort_order' => $s[6], 'active' => 1]);
        foreach ($s[7] as $c) {
            // [id, label, img, delta, cost, order, active?]
            $ins('wsm_bundle_slot_choices', ['id' => $c[0], 'slot_id' => $s[0], 'label' => $c[1], 'img' => $c[2],
                'delta' => $c[3], 'cost' => $c[4], 'sort_order' => $c[5], 'active' => $c[6] ?? 1]);
        }
    }
}

/**
 * Landing Mister Szoko — peuple wsm_landing_i18n + wsm_landing_products depuis
 * la SOURCE UNIQUE landing/content_seed.json (aucun texte en dur, ni ici ni
 * dans la page ; app.js utilise le même fichier comme repli hors-API).
 * Idempotent : vide puis réinsère les deux tables (contenu éditorial pur).
 * Chemin identique en repo et sur le serveur : ../../landing depuis php-api.
 */
function wsm_seed_landing(PDO $pdo): void {
    $file = __DIR__ . '/../../landing/content_seed.json';
    if (!is_file($file)) return; // pas de landing embarquée (ex: API seule) → tables vides
    $doc = json_decode((string) file_get_contents($file), true);
    if (!is_array($doc) || empty($doc['strings'])) return;

    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM wsm_landing_i18n');
    $pdo->exec('DELETE FROM wsm_landing_products');

    $si = $pdo->prepare('INSERT INTO wsm_landing_i18n (lang, k, v) VALUES (?,?,?)');
    foreach ($doc['strings'] as $lang => $pairs) {
        foreach ($pairs as $k => $v) $si->execute([$lang, $k, (string) $v]);
    }

    $pi = $pdo->prepare('INSERT INTO wsm_landing_products
        (id, sort_order, swatch_from, swatch_to, fluidity, active,
         price_from_pln, price_perkg_pln, price_from_eur, price_perkg_eur)
        VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($doc['products'] ?? [] as $p) {
        $pi->execute([$p['id'], $p['sort_order'] ?? 0,
            $p['swatch_from'] ?? '--brand-900', $p['swatch_to'] ?? '--brand-700',
            $p['fluidity'] ?? 3, $p['active'] ?? 1,
            $p['price_from_pln'] ?? null, $p['price_perkg_pln'] ?? null,
            $p['price_from_eur'] ?? null, $p['price_perkg_eur'] ?? null]);
    }
    $pdo->commit();
}

/**
 * Boutique en ligne — peuple wsm_shop_i18n, wsm_shipping_methods et les
 * colonnes vitrine de wsm_products depuis la SOURCE UNIQUE
 * shop/content_seed.json. Même principe que la landing : un seul fichier
 * décrit le contenu, la base le sert, les pages ne contiennent aucun libellé.
 *
 * Idempotent et NON destructif pour les commandes : les textes et les tarifs
 * sont remplacés, les produits sont créés ou mis à jour — jamais supprimés,
 * parce qu'une ligne de commande passée y fait référence.
 */
function wsm_seed_shop(PDO $pdo): void {
    require_once __DIR__ . '/commerce.php';               // wsm_inpost_template()

    $file = __DIR__ . '/../../shop/content_seed.json';
    if (!is_file($file)) return;
    $doc = json_decode((string) file_get_contents($file), true);
    if (!is_array($doc) || empty($doc['strings'])) return;

    $pdo->beginTransaction();

    // --- Textes -------------------------------------------------------------
    $pdo->exec('DELETE FROM wsm_shop_i18n');
    $si = $pdo->prepare('INSERT INTO wsm_shop_i18n (lang, k, v) VALUES (?,?,?)');
    foreach ($doc['strings'] as $lang => $pairs) {
        foreach ($pairs as $k => $v) $si->execute([$lang, $k, (string) $v]);
    }

    // --- Modes de livraison -------------------------------------------------
    $pdo->exec('DELETE FROM wsm_shipping_methods');
    $sm = $pdo->prepare('INSERT INTO wsm_shipping_methods
        (id, carrier, sort_order, active, price_net, vat_rate, free_from, min_weight_g, max_weight_g)
        VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($doc['shipping'] ?? [] as $m) {
        $sm->execute([$m['id'], $m['carrier'] ?? 'inpost', $m['sort_order'] ?? 0, $m['active'] ?? 1,
            $m['price_net'] ?? 0, $m['vat_rate'] ?? 0.23, $m['free_from'] ?? 0,
            $m['min_weight_g'] ?? 0, $m['max_weight_g'] ?? 25000]);
    }

    // --- Produits vendus en ligne ------------------------------------------
    $lang0 = $doc['default'] ?? 'pl';
    foreach ($doc['products'] ?? [] as $p) {
        $catName = (string) ($p['category'] ?? 'Czekolada');
        $st = $pdo->prepare('SELECT id FROM wsm_categories WHERE name = ?');
        $st->execute([$catName]);
        $catId = (int) $st->fetchColumn();
        if (!$catId) {
            $pdo->prepare('INSERT INTO wsm_categories (name, sort_order, active) VALUES (?,?,1)')
                ->execute([$catName, 99]);
            $catId = (int) $pdo->lastInsertId();
        }

        $shop = [
            'slug' => $p['slug'] ?? $p['id'], 'shop_visible' => 1, 'stock' => $p['stock'] ?? 0,
            'image_url' => $p['image_url'] ?? '',
            'swatch_from' => $p['swatch_from'] ?? '--brand-500', 'swatch_to' => $p['swatch_to'] ?? '--brand-800',
            'origin' => $p['origin'] ?? '', 'cocoa' => $p['cocoa'] ?? '',
            'unit_label' => $p['unit_label'] ?? '', 'badge' => $p['badge'] ?? '',
            'sku' => $p['sku'] ?? '', 'ean' => $p['ean'] ?? '',
            'vat_rate' => $p['vat_rate'] ?? 0.23, 'weight_g' => $p['weight_g'] ?? 0,
            'length_mm' => $p['length_mm'] ?? 0, 'width_mm' => $p['width_mm'] ?? 0, 'height_mm' => $p['height_mm'] ?? 0,
            'parcel_template' => wsm_inpost_template((int) ($p['length_mm'] ?? 0), (int) ($p['width_mm'] ?? 0), (int) ($p['height_mm'] ?? 0)),
            'category_id' => $catId,
            'nom' => $doc['strings'][$lang0]['product.' . $p['id'] . '.name'] ?? $p['id'],
            'prix' => $p['price'] ?? 0, 'statut' => 'Opublikowany',
            'sort_order' => $p['sort_order'] ?? 0, 'active' => 1,
        ];

        $st = $pdo->prepare('SELECT 1 FROM wsm_products WHERE id = ?');
        $st->execute([$p['id']]);
        if ($st->fetchColumn()) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($shop)));
            $pdo->prepare("UPDATE wsm_products SET $set WHERE id = ?")
                ->execute([...array_values($shop), $p['id']]);
        } else {
            $cols = array_merge(['id' => $p['id']], $shop);
            $pdo->prepare('INSERT INTO wsm_products (' . implode(',', array_keys($cols)) . ') VALUES ('
                . implode(',', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($cols));
        }
    }

    $pdo->commit();
}

/**
 * Complète les tables de contenu avec les clés ABSENTES, sans toucher aux
 * existantes. C'est ce qui permet de livrer un nouveau libellé (un lien, un
 * bouton) sans effacer les retouches faites depuis la console : le seed
 * complet, lui, remplace tout et n'est joué qu'à la création de la base.
 *
 * @return array [clés i18n ajoutées, méthodes de livraison ajoutées]
 */
function wsm_sync_content(PDO $pdo): array {
    $livre = wsm_content_livre();
    $added = 0; $ship = 0;

    // Ce qui est DÉJÀ là, lu une fois par table : on n'écrase jamais un
    // libellé retouché depuis la console.
    $have = [];
    foreach (array_unique(array_column($livre['i18n'], 0)) as $table) {
        foreach ($pdo->query("SELECT lang, k FROM $table")->fetchAll() as $r) {
            $have[$table . "\0" . $r['lang'] . "\0" . $r['k']] = true;
        }
    }
    $ins = [];
    foreach ($livre['i18n'] as [$table, $lang, $k, $v]) {
        if (isset($have[$table . "\0" . $lang . "\0" . $k])) continue;
        $ins[$table] ??= $pdo->prepare("INSERT INTO $table (lang, k, v) VALUES (?,?,?)");
        $ins[$table]->execute([$lang, $k, $v]);
        $added++;
    }

    // Les rares textes qu'on impose, et ceux qu'on retire : voir
    // wsm_content_forces() pour la règle, identique côté SQL de déploiement.
    [$maj, $sup] = wsm_content_applique_forces($pdo, $livre);

    // Modes de livraison : ajoutés s'ils manquent, jamais retarifés d'office —
    // un prix de port se décide en console, pas au déploiement.
    $sel = $pdo->prepare('SELECT 1 FROM wsm_shipping_methods WHERE id = ?');
    $insS = $pdo->prepare('INSERT INTO wsm_shipping_methods
        (id, carrier, kind, sort_order, active, price_net, vat_rate, free_from, min_weight_g, max_weight_g)
        VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($livre['ship'] as $m) {
        $sel->execute([$m['id']]);
        if ($sel->fetchColumn()) continue;
        $insS->execute([$m['id'], $m['carrier'], $m['kind'], $m['sort_order'], $m['active'],
                        $m['price_net'], $m['vat_rate'], $m['free_from'],
                        $m['min_weight_g'], $m['max_weight_g']]);
        $ship++;
    }

    // Le ménage : nommé, idempotent, et jamais destructeur.
    foreach (wsm_content_menage() as [$sql, $args]) {
        try { $st = $pdo->prepare($sql); $st->execute($args); $maj += $st->rowCount(); }
        catch (Throwable $e) { /* table absente : rien à nettoyer */ }
    }

    // Les migrations à un seul coup (nom, seuils), puis les remplacements en
    // place, après les ajouts : un libellé tout juste inséré doit être
    // nettoyé comme les autres.
    $maj += wsm_content_applique_jednorazowe($pdo);
    foreach (wsm_content_remplacements() as [$table, $col, $de, $vers]) {
        try {
            $st = $pdo->prepare("UPDATE $table SET $col = REPLACE($col, ?, ?) WHERE $col LIKE ?");
            $st->execute([$de, $vers, '%' . $de . '%']);
            $maj += $st->rowCount();
        } catch (Throwable $e) { /* table absente : rien à nettoyer */ }
    }
    return [$added, $ship, $maj, $sup];
}

/**
 * LES TEXTES QU'ON FORCE, ET CEUX QU'ON RETIRE.
 *
 * La synchronisation est un INSERT IGNORE, et c'est la bonne règle par défaut :
 * un libellé retouché depuis Treści ne doit pas être écrasé au déploiement
 * suivant. Elle a un revers exact, et il est silencieux — un texte MODIFIÉ
 * dans le fichier n'atteint jamais une base qui connaît déjà sa clé. On croit
 * avoir changé la vitrine, le déploiement passe au vert, et le site affiche
 * toujours l'ancienne phrase. Rien ne le signale.
 *
 * D'où cette liste, courte par construction : les clés dont la version du
 * dépôt fait autorité, et celles qui n'ont plus de lecteur. Elle vit ICI et
 * pas dans les deux fonctions de synchronisation, parce que la voie SQLite
 * (développement, tests) et la voie SQL (production) auraient divergé au
 * premier ajout — et c'est la version de production que personne ne relit.
 *
 * Y inscrire une clé, c'est décider qu'on reprend la main dessus : ce qui a
 * pu être écrit en console sera remplacé. On le fait pour un bloc réécrit, pas
 * pour du confort.
 *
 * @return array<string, array{force: list<string>, purge: list<string>}>
 */
/**
 * DES REMPLACEMENTS EN PLACE, pas des réécritures.
 *
 * « force » remplace une valeur entière par celle du fichier — donc écrase ce
 * que quelqu'un a tapé dans Treści. Pour retirer un CARACTÈRE de tout le site,
 * c'est trop brutal : on perdrait les descriptions écrites à la main pour
 * corriger une ponctuation.
 *
 * Ici on retouche la valeur telle qu'elle est en base, où qu'elle vienne :
 * REPLACE() sur la colonne. Le tiret cadratin part, le reste du texte — y
 * compris ce qui a été écrit après la livraison — ne bouge pas.
 *
 * Idempotent : rejoué, il ne trouve plus rien à remplacer.
 *
 * @return list<array{0:string,1:string,2:string,3:string}> [table, colonne, de, vers]
 */
function wsm_content_remplacements(): array {
    return [
        // Le tiret cadratin ne doit plus paraître sur la vitrine. Les 86
        // occurrences livrées étaient toutes de la forme « espace — espace »,
        // donc une seule règle suffit, et la virgule se lit naturellement en
        // polonais : « Dostawa gratis — próg osiągnięty » devient « Dostawa
        // gratis, próg osiągnięty ».
        ['wsm_shop_i18n', 'v', ' — ', ', '],
        // PAS DE NOM DE MARQUE ICI. Une règle jouée à chaque déploiement ne
        // distingue pas « jamais touché » de « réécrit exprès » : avec
        // « Mister Szoko → Market Levano » ici, l'équipe ne pouvait plus
        // écrire « Mister Szoko » nulle part — or c'est ce que dit le logo
        // qu'elle vient de reprendre. Les changements de nom sont des
        // migrations à un seul coup : wsm_content_jednorazowe().
    ];
}

/**
 * LES MIGRATIONS À UN SEUL COUP.
 *
 * Une règle rejouée à chaque déploiement reprend à l'équipe ce qu'elle a
 * remis exprès : l'ancien nom écrit dans une phrase, un ancien seuil
 * reposé dans Dostawa. Ces opérations-ci ne passent qu'UNE fois par base :
 * un marqueur dans wsm_settings (« migracja.<nom> ») dit qu'elles ont eu
 * lieu, et le déploiement suivant les saute. Même liste pour la voie
 * SQLite et pour le SQL de production — lue au même endroit.
 *
 * Chaque opération ne touche que ce qui est encore LIVRÉ : un REPLACE ne
 * remplace que le nom, une mise à jour exige la valeur d'origine mot pour
 * mot. Ce qui a été retouché dans la console reste tel quel.
 *
 * @return array<string, list<array{0:string,1:list<string|int>}>> marqueur => [[sql, paramètres], …]
 */
function wsm_content_jednorazowe(): array {
    $ops = [];

    // SZOKO SHOP (octobre 2026). Mister Szoko, puis Market Levano une
    // semaine, puis « Szoko Shop » avec le logo d'origine. Les deux anciens
    // noms partent des textes, des messages, des noms de produit.
    $nom = [];
    foreach (['Market Levano', 'Mister Szoko'] as $ancien) {
        foreach ([['wsm_shop_i18n', 'v'], ['wsm_landing_i18n', 'v'], ['wsm_mail_templates', 'subject'],
                  ['wsm_mail_templates', 'body'], ['wsm_shops', 'nom'], ['wsm_products', 'nom']] as [$t, $c]) {
            $nom[] = ["UPDATE $t SET $c = REPLACE($c, ?, ?) WHERE $c LIKE ?", [$ancien, 'Szoko Shop', '%' . $ancien . '%']];
        }
    }
    // (Cette migration passait aussi pro@misterszoko.com → MarketLevano@gmail.com.
    // L'équipe a repris l'ancienne adresse fin octobre : l'opération est
    // retirée — jouée sur une base neuve, elle aurait défait ce retour. En
    // production elle a déjà eu lieu, et migracja.2026_10_email_pro la défait.)
    $ops['migracja.2026_10_szoko_shop'] = $nom;

    // DARMOWA DOSTAWA OD 700 ZŁ, WYSYŁKA DO 12:00 — la politique annoncée par
    // l'équipe. Les seuils livrés (200 zł Paczkomat, 300 zł kurier) passent à
    // 700 zł ; un seuil déjà réglé autrement dans Dostawa ne bouge pas. Les
    // deux phrases des promesses suivent : l'heure dans un seul texte
    // (promise.1.d, lu aussi par le bandeau du haut), le seuil par {prog},
    // rempli avec la valeur que la caisse applique vraiment.
    $d = [];
    foreach ([['inpost_locker', 20000], ['inpost_courier', 30000], ['dpd_courier', 30000]] as [$id, $avant]) {
        $d[] = ['UPDATE wsm_shipping_methods SET free_from = ? WHERE id = ? AND free_from = ?', [70000, $id, $avant]];
    }
    foreach ([
        ['pl', 'promise.1.d', 'Zamówienia złożone do 13:00 pakujemy tego samego dnia.', 'Zamówienia złożone do 12:00 wysyłamy tego samego dnia.'],
        ['uk', 'promise.1.d', 'Замовлення до 13:00 пакуємо того ж дня.', 'Замовлення, оформлені до 12:00, відправляємо того ж дня.'],
        ['en', 'promise.1.d', 'Orders placed before 13:00 are packed the same day.', 'Orders placed by 12:00 ship the same day.'],
        ['pl', 'promise.2.d', 'InPost w całej Polsce. Darmowa dostawa od 200 zł.', 'InPost w całej Polsce. Darmowa dostawa od {prog}.'],
        ['uk', 'promise.2.d', 'InPost по всій Польщі. Безкоштовна доставка від 200 zł.', 'InPost по всій Польщі. Безкоштовна доставка від {prog}.'],
        ['en', 'promise.2.d', 'InPost across Poland. Free delivery from 200 zł.', 'InPost across Poland. Free delivery from {prog}.'],
    ] as [$l, $k, $de, $vers]) {
        $d[] = ['UPDATE wsm_shop_i18n SET v = ? WHERE k = ? AND lang = ? AND v = ?', [$vers, $k, $l, $de]];
    }
    $ops['migracja.2026_10_dostawa_700'] = $d;

    // ADRES DO ZWROTÓW W REGULAMINIE (tpay, octobre 2026). Le règlement vit
    // en base : le fichier de contenu ne le réécrit plus. La phrase entre
    // devant une phrase d'ancrage restée telle que livrée — un paragraphe
    // réécrit dans Treści n'est pas touché, et le contrôle de déploiement dit
    // alors que l'adresse manque au règlement. {adres_zwrotu} est rempli à
    // l'affichage (zwrot_adres) : changer l'adresse ne demande pas de
    // retoucher le texte.
    $z = [];
    foreach (wsm_regulamin_zwroty() as $k => [$ancre, $neuf]) {
        $z[] = ['UPDATE wsm_shop_i18n SET v = REPLACE(v, ?, ?) WHERE lang = ? AND k = ? AND v NOT LIKE ?',
                 [$ancre, $neuf, 'pl', $k, '%{adres_zwrotu}%']];
    }
    $ops['migracja.2026_10_adres_zwrotu'] = $z;

    // L'ANCIENNE ADRESSE ET LE NUMÉRO AVEC +48 (fin octobre 2026). L'équipe
    // reprend pro@misterszoko.com partout où MarketLevano@gmail.com était
    // écrit — textes de la boutique, de la page d'origine, messages aux
    // clients —, sous toutes les casses qu'une main a pu taper. Le numéro
    // passe à « +48 660 884 677 », seulement là où il est encore tel que livré.
    $m = [];
    foreach (['MarketLevano@gmail.com', 'marketlevano@gmail.com', 'Marketlevano@gmail.com', 'MARKETLEVANO@GMAIL.COM'] as $ancien) {
        foreach ([['wsm_shop_i18n', 'v'], ['wsm_landing_i18n', 'v'], ['wsm_mail_templates', 'subject'], ['wsm_mail_templates', 'body']] as [$t, $c]) {
            $m[] = ["UPDATE $t SET $c = REPLACE($c, ?, ?) WHERE $c LIKE ?", [$ancien, 'pro@misterszoko.com', '%' . $ancien . '%']];
        }
    }
    foreach (['660 884 677', '660884677', '660-884-677'] as $ancien) {
        $m[] = ['UPDATE wsm_shop_i18n SET v = ? WHERE k = ? AND v = ?', ['+48 660 884 677', 'footer.phone', $ancien]];
    }
    $ops['migracja.2026_10_email_pro'] = $m;

    // LE NOUVEAU HAUT DE PAGE (maquette de l'équipe, fin octobre 2026) :
    // « Pewne składniki. Udane wypieki. » et le boulanger au pétrin. Le texte
    // est REMPLACÉ quel qu'il soit : l'équipe a demandé ces mots-là, 1:1, et
    // les pose une fois ; Treści les rechange ensuite comme avant. Les
    // slajdy (slajdy.php) portent leurs propres textes ; ceux-ci restent ce
    // que montre la page quand aucun slajd n'est en ligne.
    $h = [];
    foreach ([
        'pl' => ['Manufaktura · małe partie', 'Pewne składniki. Udane wypieki.', 'Zaopatrz piekarnię lub cukiernię w produkty do codziennej pracy.', 'Zobacz produkty'],
        'uk' => ['Мануфактура · малі партії', 'Надійні інгредієнти. Вдала випічка.', 'Забезпечте пекарню чи кондитерську продуктами для щоденної роботи.', 'Переглянути продукти'],
        'en' => ['Small batch · workshop', 'Reliable ingredients. Successful bakes.', 'Stock your bakery or patisserie with products for everyday work.', 'See the products'],
    ] as $l => [$eyebrow, $title, $lead, $cta]) {
        foreach (['home.eyebrow' => $eyebrow, 'home.title' => $title, 'home.lead' => $lead, 'home.cta' => $cta] as $k => $v) {
            $h[] = ['UPDATE wsm_shop_i18n SET v = ? WHERE k = ? AND lang = ?', [$v, $k, $l]];
        }
    }
    $ops['migracja.2026_10_hero_piekarz'] = $h;

    // STRIPE (octobre 2026) : tous les paiements passent par Stripe Connect,
    // intégrés dans la boutique ; tpay et le virement partent. Les textes qui
    // nommaient tpay changent PHRASE PAR PHRASE (REPLACE) : un paragraphe déjà
    // réécrit dans Treści garde ses mots, et le contrôle de déploiement dit
    // alors qu'il nomme encore tpay. Le règlement et la politique : à faire
    // relire — l'opérateur change, donc le destinataire des données aussi.
    $s = [];
    foreach ([
        ['pl', 'checkout.pay_info', 'Płatność obsługuje tpay.com. Po złożeniu zamówienia przeniesiemy Cię na stronę płatności.', 'Płatność obsługuje Stripe. Po złożeniu zamówienia zapłacisz na następnej stronie sklepu — BLIK, Przelewy24, kartą, Apple Pay lub Google Pay.'],
        ['uk', 'checkout.pay_info', 'Оплату обслуговує tpay.com. Після оформлення ми перенаправимо вас на сторінку оплати.', 'Оплату обслуговує Stripe. Після оформлення замовлення ви оплатите на наступній сторінці магазину — BLIK, Przelewy24, карткою, Apple Pay або Google Pay.'],
        ['en', 'checkout.pay_info', 'Payments are handled by tpay.com. You\'ll be taken to the payment page after placing the order.', 'Payments are handled by Stripe. After placing the order you\'ll pay on the next page of the shop — BLIK, Przelewy24, card, Apple Pay or Google Pay.'],
        ['pl', 'order.pay_unavailable', 'Płatność online nie jest jeszcze uruchomiona, skontaktujemy się z Tobą e-mailem w sprawie przelewu.', 'Płatność online nie jest jeszcze uruchomiona. Zamówienie jest zapisane — skontaktujemy się z Tobą e-mailem.'],
        ['uk', 'order.pay_unavailable', 'Онлайн-оплату ще не запущено, ми напишемо вам щодо переказу.', 'Онлайн-оплату ще не запущено. Замовлення збережено — ми напишемо вам електронною поштою.'],
        ['en', 'order.pay_unavailable', 'Online payment isn\'t live yet, we\'ll e-mail you about the bank transfer.', 'Online payment isn\'t live yet. Your order is saved — we\'ll get in touch by e-mail.'],
        ['pl', 'metoda.przelew_online', 'Szybki przelew', 'Przelewy24'],
        ['uk', 'metoda.przelew_online', 'Швидкий переказ', 'Przelewy24'],
        ['en', 'metoda.przelew_online', 'Instant bank transfer', 'Przelewy24'],
        ['pl', 'terms.s6.b', '**Tpay** (Krajowy Integrator Płatności S.A. z siedzibą w Poznaniu). Dostępne metody obejmują szybki przelew, BLIK oraz kartę płatniczą.', '**Stripe** (Stripe Payments Europe, Limited z siedzibą w Dublinie, Irlandia). Dostępne metody obejmują BLIK, Przelewy24, kartę płatniczą oraz Apple Pay i Google Pay.'],
        ['pl', 'privacy.s4.b', '- **operator płatności** — Krajowy Integrator Płatności S.A. (Tpay), w zakresie niezbędnym do rozliczenia transakcji,', '- **operator płatności** — Stripe Payments Europe, Limited (Irlandia), w zakresie niezbędnym do rozliczenia transakcji,'],
        ['pl', 'privacy.s4.b', 'Nie przekazujemy danych poza Europejski Obszar Gospodarczy.', 'Operator płatności może przetwarzać dane także poza Europejskim Obszarem Gospodarczym — wyłącznie na podstawie standardowych klauzul umownych zatwierdzonych przez Komisję Europejską.'],
    ] as [$l, $k, $de, $vers]) {
        $s[] = ['UPDATE wsm_shop_i18n SET v = REPLACE(v, ?, ?) WHERE lang = ? AND k = ?', [$de, $vers, $l, $k]];
    }
    $ops['migracja.2026_10_stripe'] = $s;

    // LA MÊME, APRÈS LA VIRGULE. wsm_content_remplacements() change à chaque
    // déploiement « — » en « , » : en base, la ligne de la politique se lit
    // « **operator płatności**, Krajowy Integrator… » — la migration ci-dessus
    // cherchait le tiret, et le déploiement l'a dit (« 1 tekst wspomina
    // tpay »). Les deux graphies, cette fois.
    $s2 = [];
    foreach ([', ', ' — '] as $sep) {
        $s2[] = ['UPDATE wsm_shop_i18n SET v = REPLACE(v, ?, ?) WHERE lang = ? AND k = ?',
                 ['- **operator płatności**' . $sep . 'Krajowy Integrator Płatności S.A. (Tpay), w zakresie niezbędnym do rozliczenia transakcji,',
                  '- **operator płatności**' . $sep . 'Stripe Payments Europe, Limited (Irlandia), w zakresie niezbędnym do rozliczenia transakcji,',
                  'pl', 'privacy.s4.b']];
    }
    $ops['migracja.2026_10_stripe_przecinek'] = $s2;

    // L'E-PARAGON SE DEMANDE, IL NE SE PRÉSUME PAS. La case de la caisse est
    // désormais décochée d'office ; l'aide disait « décochez si vous préférez
    // le papier ». Remplacée seulement si personne ne l'a réécrite dans Treści.
    $e = [];
    foreach ([
        ['pl', 'Zgoda na paragon fiskalny w postaci elektronicznej. Odznacz, jeśli wolisz papierowy w paczce. Przy fakturze nie dotyczy.', 'Zgoda na paragon fiskalny w postaci elektronicznej, wysłany na Twój e-mail. Bez zaznaczenia dołączymy paragon papierowy do paczki. Przy fakturze nie dotyczy.'],
        ['uk', 'Згода на фіскальний чек в електронній формі. Зніміть позначку, якщо хочете паперовий чек у посилці. Не стосується фактури.', 'Згода на фіскальний чек в електронній формі на вашу електронну пошту. Без позначки додамо паперовий чек до посилки. Не стосується фактури.'],
        ['en', 'Consent to an electronic fiscal receipt. Untick it if you prefer a paper receipt in the parcel. Does not apply to invoices.', 'Consent to an electronic fiscal receipt sent to your e-mail. If left unticked, a paper receipt will be packed with your parcel. Does not apply to invoices.'],
    ] as [$l, $de, $vers]) {
        $e[] = ['UPDATE wsm_shop_i18n SET v = ? WHERE k = ? AND lang = ? AND v = ?', [$vers, 'checkout.ereceipt_hint', $l, $de]];
    }
    $ops['migracja.2026_10_eparagon_zgoda'] = $e;

    return $ops;
}

/**
 * Les deux phrases du règlement qui donnent l'adresse des retours, et la
 * phrase livrée devant laquelle chacune entre. Le fichier de contenu porte
 * déjà le résultat ; le test vérifie que les deux disent la même chose.
 *
 * @return array<string, array{0:string,1:string}> clé => [ancre, ancre précédée de la phrase]
 */
function wsm_regulamin_zwroty(): array {
    $a8 = 'Bezpośrednie koszty zwrotu towaru ponosi Klient.';
    $a9 = 'Reklamację rozpatrujemy w terminie **14 dni**';
    return [
        'terms.s8.b' => [$a8, "Towar należy odesłać na adres do zwrotów: **{adres_zwrotu}**. Ten sam adres jest podany w stopce sklepu i na stronie „Zwroty”.\n\n" . $a8],
        'terms.s9.b' => [$a9, "Jeżeli do rozpatrzenia reklamacji potrzebny jest towar, prosimy odesłać go na adres do zwrotów: **{adres_zwrotu}**.\n\n" . $a9],
    ];
}

/** Applique les migrations à un seul coup sur une base ouverte. @return int lignes changées */
function wsm_content_applique_jednorazowe(PDO $pdo): int {
    $n = 0;
    foreach (wsm_content_jednorazowe() as $marqueur => $ops) {
        try {
            $st = $pdo->prepare("SELECT 1 FROM wsm_settings WHERE cle = ?");
            $st->execute([$marqueur]);
            if ($st->fetchColumn()) continue;                 // déjà jouée sur cette base
        } catch (Throwable $e) { continue; }                  // pas de quoi noter : on ne joue rien
        foreach ($ops as [$sql, $args]) {
            try { $u = $pdo->prepare($sql); $u->execute($args); $n += $u->rowCount(); }
            catch (Throwable $e) { /* table absente : rien à migrer */ }
        }
        try {
            $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?, '1', 0, ?, 'wdrozenie')")
                ->execute([$marqueur, date('Y-m-d H:i:s')]);
        } catch (Throwable $e) { /* posé entre-temps par un autre passage : c'est fait */ }
    }
    return $n;
}

/**
 * LE MÉNAGE : ce qui reste d'une maquette et qu'on ne veut plus voir.
 *
 * Cinq catégories — Pieczywo, Lody, Katering… — sont arrivées avec la maquette
 * de boulangerie qui a précédé la chocolaterie. Le champ est obligatoire sur
 * chaque produit, donc on choisissait forcément une étiquette fausse. Et deux
 * produits « test-dpd-… », nés d'une mise au point du transporteur, se
 * retrouvaient achetables en vitrine.
 *
 * DEUX RÈGLES, ET ELLES COMPTENT :
 *
 *  · ON NE SUPPRIME RIEN. La colonne category_id est NOT NULL : une catégorie
 *    effacée laisserait ses produits avec une référence morte. Un produit
 *    effacé emporterait le sens des commandes et des factures qui le nomment.
 *    On désactive, on masque — l'histoire reste lisible.
 *  · UNE CATÉGORIE QUI TIENT ENCORE UN PRODUIT N'EST PAS TOUCHÉE. Si quelqu'un
 *    s'en sert pour de vrai, la maquette n'est plus une maquette.
 *
 * Idempotent : rejoué, il ne trouve plus rien à changer.
 *
 * @return list<array{0:string,1:list<string>}> [sql, paramètres]
 */
function wsm_content_menage(): array {
    // L'ORDRE COMPTE. On masque d'abord les produits de test, puis on regarde
    // quelles catégories ne tiennent plus rien de visible : l'inverse laisserait
    // une catégorie active parce qu'un produit qu'on s'apprête à masquer y était
    // encore rangé.
    $ops = [
        // Les produits de mise au point DPD : masqués, pas effacés — une
        // commande de test peut les nommer, et un document doit rester lisible.
        ['UPDATE wsm_products SET shop_visible = 0 WHERE id LIKE ? AND shop_visible = 1',
         ['test-dpd-%']],
    ];
    foreach (['Pieczywo', 'Ciasta świeże', 'Katering', 'Lody', 'Menu i zestawy'] as $nom) {
        // « Aucun produit VISIBLE », pas « aucun produit » : les articles de
        // l'ancienne maquette dorment déjà, masqués, dans ces rayons. Exiger
        // la table vide n'aurait jamais rien retiré — la boutique garderait
        // pour toujours un menu déroulant qui propose surtout du faux.
        //
        // Les produits gardent leur référence : la colonne est NOT NULL, et
        // une catégorie désactivée reste lisible partout où elle est nommée.
        $ops[] = ['UPDATE wsm_categories SET active = 0 WHERE name = ? AND active = 1'
                . ' AND id NOT IN (SELECT category_id FROM wsm_products'
                . '                 WHERE shop_visible = 1 AND active = 1)', [$nom]];
    }
    // LE TITRE DU CATALOGUE, quand il est encore celui d'origine. La
    // boutique vend aussi des sucres et des farines : « Nasze czekolady »
    // au-dessus d'un rayon « Mąki » serait faux. On ne remplace que la phrase
    // LIVRÉE, mot pour mot — un titre retouché dans Treści reste intact.
    foreach ([['pl', 'Nasze czekolady', 'Nasze produkty'], ['uk', 'Наш шоколад', 'Наші продукти'],
              ['en', 'Our chocolate', 'Our products']] as [$l, $de, $vers]) {
        $ops[] = ['UPDATE wsm_shop_i18n SET v = ? WHERE k = ? AND lang = ? AND v = ?', [$vers, 'catalog.title', $l, $de]];
    }
    return $ops;
}

function wsm_content_forces(): array {
    return [
        'wsm_shop_i18n' => [
            // Bloc B2B de l'accueil, réécrit : « Strefa pro / Przerabiasz
            // ponad 40 kg… » → « B2B ? Mamy dla Ciebie lepsze ceny », et le
            // bouton mène au formulaire au lieu d'un mailto.
            'force' => ['story.pro.'],
            // Le sujet du mailto ne sert plus à rien depuis. Laissé en base, il
            // resterait éditable dans Treści : quelqu'un le retoucherait un
            // jour, en soignerait la traduction, et rien ne changerait sur le
            // site. Un texte sans lecteur, on l'enlève.
            // story.strip.1 disait « Wysyłka w 48 h » juste au-dessus d'un
            // bloc qui promet 24 h. Le bandeau lit maintenant le MÊME champ
            // que ce bloc, donc plus personne ne lit celui-ci : laissé en
            // base, il resterait modifiable dans Treści, et quelqu'un
            // corrigerait un jour un délai que la page n'affiche plus.
            // Les textes de l'abonnement n'ont plus d'ecran : le bloc a ete
            // retire de la page de commande. Laisses en base, ils resteraient
            // modifiables dans Tresci -- quelqu'un les soignerait un jour, et
            // rien ne changerait nulle part.
            // checkout.terms disait « Akceptuję regulamin i politykę
            // prywatności » en un seul bloc, sans lien. La phrase est
            // maintenant en trois morceaux pour porter DEUX liens et les
            // formes flechies. L'ancienne cle resterait modifiable dans
            // Tresci, sur une phrase que plus rien n'affiche.
            // story.strip.2 et .3 (« Im większe opakowanie… », « Faktury
            // netto/VAT… ») : le bandeau du bas du hero est retiré à la
            // demande de l'équipe, plus rien ne les lit.
            'purge' => ['story.pro.mail_subject', 'story.strip.1', 'story.strip.2', 'story.strip.3',
                        'sub.title', 'sub.lead', 'sub.rhythm', 'sub.cta',
                        'sub.done', 'sub.already', 'checkout.terms'],
        ],
    ];
}

/** Applique wsm_content_forces() sur une base ouverte. @return array{0:int,1:int} */
function wsm_content_applique_forces(PDO $pdo, array $livre): array {
    $forces = wsm_content_forces();
    $maj = 0; $sup = 0; $st = [];

    foreach ($livre['i18n'] as [$table, $lang, $k, $v]) {
        $force = false;
        foreach ($forces[$table]['force'] ?? [] as $p) {
            if (str_starts_with($k, $p)) { $force = true; break; }
        }
        if (!$force) continue;
        // `v <> ?` : on ne compte QUE les lignes réellement changées. Un
        // compteur qui gonfle à chaque déploiement ne dit plus rien.
        $st[$table] ??= $pdo->prepare("UPDATE $table SET v = ? WHERE lang = ? AND k = ? AND v <> ?");
        $st[$table]->execute([$v, $lang, $k, $v]);
        $maj += $st[$table]->rowCount();
    }
    foreach ($forces as $table => $regle) {
        foreach ($regle['purge'] ?? [] as $k) {
            $d = $pdo->prepare("DELETE FROM $table WHERE k = ?");
            $d->execute([$k]);
            $sup += $d->rowCount();
        }
    }
    return [$maj, $sup];
}

/**
 * CE QUE LES FICHIERS DE CONTENU LIVRENT, sans toucher à la base.
 *
 * Extrait de wsm_sync_content() pour qu'il existe UNE seule lecture des
 * fichiers, partagée par la voie « j'exécute » (développement) et la voie
 * « j'émets du SQL » (déploiement). Deux lectures séparées auraient dérivé au
 * premier champ ajouté, et la version de production est justement celle que
 * personne ne relit.
 *
 * @return array{i18n: list<array{0:string,1:string,2:string,3:string}>, ship: list<array>}
 */
function wsm_content_livre(): array {
    $out = ['i18n' => [], 'ship' => []];
    $fichiers = [
        'wsm_landing_i18n' => __DIR__ . '/../../landing/content_seed.json',
        'wsm_shop_i18n'    => __DIR__ . '/../../shop/content_seed.json',
    ];
    foreach ($fichiers as $table => $file) {
        if (!is_file($file)) continue;
        $doc = json_decode((string) file_get_contents($file), true);
        if (!is_array($doc) || empty($doc['strings'])) continue;
        foreach ($doc['strings'] as $lang => $pairs) {
            foreach ((array) $pairs as $k => $v) {
                $out['i18n'][] = [$table, (string) $lang, (string) $k, (string) $v];
            }
        }
    }
    $shopFile = $fichiers['wsm_shop_i18n'];
    if (is_file($shopFile)) {
        $doc = json_decode((string) file_get_contents($shopFile), true);
        foreach ((array) ($doc['shipping'] ?? []) as $m) {
            $out['ship'][] = [
                'id' => (string) ($m['id'] ?? ''), 'carrier' => (string) ($m['carrier'] ?? 'inpost'),
                // 'punkt' ou 'adres'. Sans ce champ, une méthode livrée
                // arriverait en base sur le défaut de la colonne — « adres » —
                // et un Paczkomat réclamerait une rue au client.
                'kind' => (string) ($m['kind'] ?? 'adres'),
                'sort_order' => (int) ($m['sort_order'] ?? 0), 'active' => (int) ($m['active'] ?? 1),
                'price_net' => (int) ($m['price_net'] ?? 0), 'vat_rate' => (float) ($m['vat_rate'] ?? 0.23),
                'free_from' => (int) ($m['free_from'] ?? 0),
                // Le poids PLANCHER d'un transporteur de palettes. Absent du
                // fichier, il vaut zéro : les modes historiques n'en ont pas.
                'min_weight_g' => (int) ($m['min_weight_g'] ?? 0),
                'max_weight_g' => (int) ($m['max_weight_g'] ?? 25000),
            ];
        }
    }
    return $out;
}

/**
 * Un littéral texte pour MySQL, écrit en HEXADÉCIMAL.
 *
 * Pas par goût de l'obscurité : ces chaînes sont du contenu éditorial en trois
 * langues, pleines d'apostrophes, de guillemets, d'accents et de retours à la
 * ligne. Les échapper à la main, c'est une famille de bogues — et une famille
 * d'injections. En hexadécimal il n'y a plus rien à échapper : les octets
 * passent tels quels, et l'introducteur `_utf8mb4` dit à MySQL comment les
 * lire. La chaîne vide n'a pas d'hexadécimal, d'où le cas à part.
 */
function wsm_sql_txt(string $s): string {
    return $s === '' ? "''" : '_utf8mb4 0x' . bin2hex($s);
}

/**
 * LE MÊME TRAVAIL, ÉMIS EN SQL AU LIEU D'ÊTRE EXÉCUTÉ.
 *
 * Pourquoi cette voie existe : le php en ligne de commande du serveur de
 * production n'a pas pdo_mysql. `php migrate.php --sync-content` n'y a donc
 * JAMAIS rien fait, et l'échec était avalé par un `|| echo` — trois
 * déploiements verts n'ont rien synchronisé. Émettre du SQL ne demande aucune
 * base : le serveur peut le faire, et le client mysql, lui, marche.
 *
 * `INSERT IGNORE` porte exactement la règle du mode exécuté : la clé primaire
 * est (lang, k), donc un libellé déjà présent — y compris retouché depuis la
 * console — est laissé intact.
 */
function wsm_sync_content_sql(): string {
    $livre = wsm_content_livre();
    $out = [];

    // ── LA COLONNE AVANT LA LIGNE ────────────────────────────────────────
    //
    // Ce SQL tourne AVANT que l'application ait démarré une seule fois. Or
    // c'est wsm_bootstrap() — donc le SAPI web, donc une visite — qui ajoute
    // les colonnes récentes. Résultat au déploiement 90 : le SQL nommait la
    // colonne `kind`, MySQL ne la connaissait pas encore, tout le lot est
    // tombé, et le `|| echo` a avalé l'échec. La méthode DPD n'est jamais
    // arrivée en base ; seule la vérification d'effet l'a vu (« 2 lignes pour
    // 3 livrés »). Sans elle, on aurait cru la boutique à jour.
    //
    // MySQL n'a pas d'`ADD COLUMN IF NOT EXISTS` : on interroge donc
    // information_schema et on prépare l'ordre seulement s'il manque. C'est
    // idempotent, et ça ne coûte rien quand la colonne est déjà là.
    $ajoute = function (string $table, string $col, string $decl) use (&$out): void {
        // Littéraux ASCII SIMPLES ici, pas les hexadécimaux utf8mb4 utilisés
        // pour le contenu : information_schema porte ses propres collations, et
        // comparer un _utf8mb4 à ses colonnes peut lever « Illegal mix of
        // collations » — ce qui ferait tomber tout le lot, exactement comme la
        // colonne manquante vient de le faire. Un nom de table et un nom de
        // colonne n'ont de toute façon jamais d'accent.
        $out[] = "SET @c := (SELECT COUNT(*) FROM information_schema.columns"
               . " WHERE table_schema = DATABASE() AND table_name = '$table'"
               . " AND column_name = '$col');";
        // L'ordre voyage DANS une chaîne SQL : ses propres apostrophes doivent
        // donc être doublées. Sans ça, « DEFAULT 'adres' » referme la chaîne au
        // milieu et MySQL refuse tout le lot — le défaut qu'on est en train de
        // réparer, reproduit un étage plus bas.
        $ordre = str_replace("'", "''", "ALTER TABLE `$table` ADD COLUMN `$col` $decl");
        $out[] = "SET @s := IF(@c = 0, '$ordre', 'SELECT 1');";
        $out[] = "PREPARE wsm_st FROM @s; EXECUTE wsm_st; DEALLOCATE PREPARE wsm_st;";
    };
    $ajoute('wsm_shipping_methods', 'kind', "VARCHAR(12) NOT NULL DEFAULT 'adres'");
    // Même leçon que `kind` au déploiement 90 : la colonne AVANT la ligne.
    // Ce SQL tourne avant que l'application ait démarré une seule fois, donc
    // avant que wsm_bootstrap() ait pu poser min_weight_g. Sans cet ordre,
    // l'INSERT de Fresh Logistic nomme une colonne inconnue et TOUT le lot
    // tombe — y compris les libellés des autres transporteurs.
    $ajoute('wsm_shipping_methods', 'min_weight_g', 'INT NOT NULL DEFAULT 0');

    // ─── LES TEXTES QU'INSERT IGNORE N'AURAIT JAMAIS LIVRÉS ──────────────
    //
    // Même liste que la voie SQLite, lue au même endroit : wsm_content_forces().
    // Deux listes séparées auraient divergé au premier ajout, et la seule qui
    // compte pour le client est celle qui tourne ici.
    $forces = wsm_content_forces();
    foreach ($livre['i18n'] as [$table, $lang, $k, $v]) {
        $force = false;
        foreach ($forces[$table]['force'] ?? [] as $p) {
            if (str_starts_with($k, $p)) { $force = true; break; }
        }
        if (!$force) continue;
        $out[] = "UPDATE `$table` SET v = " . wsm_sql_txt($v)
               . ' WHERE lang = ' . wsm_sql_txt($lang) . ' AND k = ' . wsm_sql_txt($k) . ';';
    }
    foreach ($forces as $table => $regle) {
        foreach ($regle['purge'] ?? [] as $k) {
            $out[] = "DELETE FROM `$table` WHERE k = " . wsm_sql_txt($k) . ';';
        }
    }
    // Et les deux services historiques prennent le type qu'ils ont toujours eu.
    // Sans cette ligne, le Paczkomat hérite du défaut « adres » et la caisse
    // réclame une rue pour une skrytka.
    $out[] = "UPDATE wsm_shipping_methods SET kind = 'punkt'"
           . " WHERE id = 'inpost_locker' AND (kind IS NULL OR kind = '' OR kind = 'adres');";

    foreach ($livre['i18n'] as [$table, $lang, $k, $v]) {
        $out[] = "INSERT IGNORE INTO $table (lang, k, v) VALUES ("
               . wsm_sql_txt($lang) . ', ' . wsm_sql_txt($k) . ', ' . wsm_sql_txt($v) . ');';
    }
    foreach ($livre['ship'] as $m) {
        $out[] = 'INSERT IGNORE INTO wsm_shipping_methods'
               . ' (id, carrier, kind, sort_order, active, price_net, vat_rate, free_from,'
               . ' min_weight_g, max_weight_g) VALUES ('
               . wsm_sql_txt($m['id']) . ', ' . wsm_sql_txt($m['carrier']) . ', '
               . wsm_sql_txt($m['kind']) . ', '
               . $m['sort_order'] . ', ' . $m['active'] . ', ' . $m['price_net'] . ', '
               . $m['vat_rate'] . ', ' . $m['free_from'] . ', '
               . $m['min_weight_g'] . ', ' . $m['max_weight_g'] . ');';
    }
    foreach (wsm_content_menage() as [$sql, $args]) {
        foreach ($args as $v) {
            $pos = strpos($sql, '?');
            if ($pos === false) break;
            $sql = substr($sql, 0, $pos) . wsm_sql_txt((string) $v) . substr($sql, $pos + 1);
        }
        $out[] = $sql . ';';
    }

    // Les migrations à un seul coup : chaque opération porte la condition
    // « pas encore de marqueur », et le marqueur se pose à la fin du lot.
    // Rejoué, le lot entier ne trouve plus rien à faire.
    foreach (wsm_content_jednorazowe() as $marqueur => $ops) {
        $garde = ' AND NOT EXISTS (SELECT 1 FROM wsm_settings WHERE cle = ' . wsm_sql_txt($marqueur) . ')';
        foreach ($ops as [$sql, $args]) {
            foreach ($args as $v) {
                $pos = strpos($sql, '?');
                if ($pos === false) break;
                $lit = is_int($v) ? (string) $v : wsm_sql_txt((string) $v);
                $sql = substr($sql, 0, $pos) . $lit . substr($sql, $pos + 1);
            }
            $out[] = $sql . $garde . ';';
        }
        $out[] = 'INSERT IGNORE INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES ('
               . wsm_sql_txt($marqueur) . ", '1', 0, NOW(), 'wdrozenie');";
    }

    // Même nettoyage que la voie « base ouverte ». En SQL les motifs passent
    // en hexadécimal : un tiret cadratin recopié dans un fichier .sql traverse
    // trois encodages avant d'arriver à MySQL, et il suffit d'un pour que le
    // REPLACE ne trouve rien et se taise.
    foreach (wsm_content_remplacements() as [$table, $col, $de, $vers]) {
        $out[] = "UPDATE $table SET $col = REPLACE($col, " . wsm_sql_txt($de) . ', '
               . wsm_sql_txt($vers) . ') WHERE ' . $col . ' LIKE CONCAT(\'%\', '
               . wsm_sql_txt($de) . ", '%');";
    }
    return $out ? implode("\n", $out) . "\n" : '';
}

/**
 * Pays de l'Union servis par la boutique. Seule la Pologne est ouverte au
 * départ : c'est le marché réel (InPost livre en Pologne). Le back-office
 * ouvre les autres quand l'expédition suit.
 *
 * `is_eu` n'est pas une étiquette : c'est lui qui autorise l'autoliquidation.
 */
function wsm_seed_countries(PDO $pdo): void {
    // code, pl, uk, en
    $eu = [
        ['PL', 'Polska', 'Польща', 'Poland'],
        ['AT', 'Austria', 'Австрія', 'Austria'],
        ['BE', 'Belgia', 'Бельгія', 'Belgium'],
        ['BG', 'Bułgaria', 'Болгарія', 'Bulgaria'],
        ['HR', 'Chorwacja', 'Хорватія', 'Croatia'],
        ['CY', 'Cypr', 'Кіпр', 'Cyprus'],
        ['CZ', 'Czechy', 'Чехія', 'Czechia'],
        ['DK', 'Dania', 'Данія', 'Denmark'],
        ['EE', 'Estonia', 'Естонія', 'Estonia'],
        ['FI', 'Finlandia', 'Фінляндія', 'Finland'],
        ['FR', 'Francja', 'Франція', 'France'],
        ['EL', 'Grecja', 'Греція', 'Greece'],
        ['ES', 'Hiszpania', 'Іспанія', 'Spain'],
        ['NL', 'Holandia', 'Нідерланди', 'Netherlands'],
        ['IE', 'Irlandia', 'Ірландія', 'Ireland'],
        ['LT', 'Litwa', 'Литва', 'Lithuania'],
        ['LU', 'Luksemburg', 'Люксембург', 'Luxembourg'],
        ['LV', 'Łotwa', 'Латвія', 'Latvia'],
        ['MT', 'Malta', 'Мальта', 'Malta'],
        ['DE', 'Niemcy', 'Німеччина', 'Germany'],
        ['PT', 'Portugalia', 'Португалія', 'Portugal'],
        ['RO', 'Rumunia', 'Румунія', 'Romania'],
        ['SK', 'Słowacja', 'Словаччина', 'Slovakia'],
        ['SI', 'Słowenia', 'Словенія', 'Slovenia'],
        ['SE', 'Szwecja', 'Швеція', 'Sweden'],
        ['HU', 'Węgry', 'Угорщина', 'Hungary'],
        ['IT', 'Włochy', 'Італія', 'Italy'],
    ];
    $ins = $pdo->prepare('INSERT INTO wsm_countries (code, name_pl, name_uk, name_en, is_eu, active, sort_order)
                          VALUES (?,?,?,?,1,?,?)');
    foreach ($eu as $i => [$c, $pl, $uk, $en]) {
        // La Pologne d'abord, et seule ouverte : on ne prétend pas livrer
        // ailleurs tant qu'aucun transporteur ne le fait.
        $ins->execute([$c, $pl, $uk, $en, $c === 'PL' ? 1 : 0, $c === 'PL' ? 0 : $i + 10]);
    }
    // Périmètre des transporteurs : InPost, c'est la Pologne.
    try { $pdo->exec("UPDATE wsm_shipping_methods SET countries = 'PL' WHERE countries = ''"); }
    catch (Throwable $e) { /* colonne pas encore migrée */ }
}

/**
 * Paliers de remise au poids. Reprennent la logique annoncée depuis le début :
 * le kilogramme baisse avec le format. Réglables ensuite en console.
 */
function wsm_seed_discounts(PDO $pdo): void {
    $ins = $pdo->prepare('INSERT INTO wsm_discount_tiers (min_weight_g, percent, label, active) VALUES (?,?,?,1)');
    foreach ([[3000, 5.0, 'od 3 kg'], [10000, 12.0, 'od 10 kg'], [20000, 20.0, 'od 20 kg']] as [$g, $p, $l]) {
        $ins->execute([$g, $p, $l]);
    }
}

/**
 * Modèles de courrier. Semés une seule fois : dès que la console en modifie un,
 * c'est la base qui fait foi et un déploiement ne le réécrit plus.
 *
 * Le modèle « na_zamowienie » est celui qui tient la promesse commerciale :
 * une commande dépassant le stock passe quand même, et l'acheteur reçoit
 * immédiatement un mot disant qu'on le recontacte avec la date.
 */
function wsm_seed_mail_templates(PDO $pdo): void {
    $ins = $pdo->prepare('INSERT INTO wsm_mail_templates (code, lang, name, subject, body, event, active)
                          VALUES (?,?,?,?,?,?,1)');

    $t = [];

    // ---- Commande reçue ----------------------------------------------------
    $t[] = ['zamowienie', 'pl', 'Potwierdzenie zamówienia', 'Zamówienie {{numer}} przyjęte',
"Dzień dobry {{imie}},

dziękujemy za zamówienie {{numer}} na kwotę {{kwota}}.

{{pozycje}}

Podgląd zamówienia: {{link}}

Odezwiemy się, gdy paczka ruszy w drogę.

Szoko Shop", 'zamowienie'];

    $t[] = ['zamowienie', 'uk', 'Підтвердження замовлення', 'Замовлення {{numer}} прийнято',
"Доброго дня, {{imie}}!

Дякуємо за замовлення {{numer}} на суму {{kwota}}.

{{pozycje}}

Перегляд замовлення: {{link}}

Ми напишемо, щойно посилка вирушить.

Szoko Shop", 'zamowienie'];

    $t[] = ['zamowienie', 'en', 'Order confirmation', 'Order {{numer}} received',
"Hello {{imie}},

thank you for order {{numer}}, total {{kwota}}.

{{pozycje}}

Order details: {{link}}

We will write again as soon as the parcel is on its way.

Szoko Shop", 'zamowienie'];

    // ---- Commande au-delà du stock ----------------------------------------
    $t[] = ['na_zamowienie', 'pl', 'Zamówienie ponad stan — kontakt', 'Zamówienie {{numer}} — skontaktujemy się mailowo',
"Dzień dobry {{imie}},

zamówienie {{numer}} zostało przyjęte. Część pozycji robimy dla Państwa
na świeżo, dlatego skontaktujemy się mailowo z terminem wysyłki:

{{brakujace}}

Reszta zamówienia czeka spakowana. Podgląd: {{link}}

Szoko Shop", 'na_zamowienie'];

    $t[] = ['na_zamowienie', 'uk', 'Замовлення понад запас — контакт', 'Замовлення {{numer}} — ми напишемо вам',
"Доброго дня, {{imie}}!

Замовлення {{numer}} прийнято. Частину позицій ми виготовляємо свіжими,
тож напишемо вам електронною поштою про строк відправлення:

{{brakujace}}

Решта замовлення вже спакована. Перегляд: {{link}}

Szoko Shop", 'na_zamowienie'];

    $t[] = ['na_zamowienie', 'en', 'Order beyond stock — contact', 'Order {{numer}} — we will contact you by e-mail',
"Hello {{imie}},

order {{numer}} has been accepted. Some items are made fresh for you,
so we will contact you by e-mail with the shipping date:

{{brakujace}}

The rest of your order is already packed. Details: {{link}}

Szoko Shop", 'na_zamowienie'];

    // ---- Paiement reçu -----------------------------------------------------
    $t[] = ['platnosc', 'pl', 'Płatność otrzymana', 'Płatność za {{numer}} zaksięgowana',
"Dzień dobry {{imie}},

potwierdzamy wpłatę {{kwota}} za zamówienie {{numer}}.
Zabieramy się do pakowania.

Szoko Shop", 'platnosc'];

    $t[] = ['platnosc', 'uk', 'Оплату отримано', 'Оплату за {{numer}} зараховано',
"Доброго дня, {{imie}}!

Підтверджуємо оплату {{kwota}} за замовлення {{numer}}.
Беремося пакувати.

Szoko Shop", 'platnosc'];

    $t[] = ['platnosc', 'en', 'Payment received', 'Payment for {{numer}} confirmed',
"Hello {{imie}},

we confirm your payment of {{kwota}} for order {{numer}}.
We are getting it packed.

Szoko Shop", 'platnosc'];

    // ---- Expédition --------------------------------------------------------
    $t[] = ['wysylka', 'pl', 'Przesyłka nadana', 'Zamówienie {{numer}} wysłane',
"Dzień dobry {{imie}},

paczka z zamówieniem {{numer}} jest w drodze.
Punkt odbioru: {{paczkomat}}

Śledzenie: {{link}}

Szoko Shop", 'wysylka'];

    $t[] = ['wysylka', 'uk', 'Посилку відправлено', 'Замовлення {{numer}} відправлено',
"Доброго дня, {{imie}}!

Посилка із замовленням {{numer}} у дорозі.
Пункт видачі: {{paczkomat}}

Відстеження: {{link}}

Szoko Shop", 'wysylka'];

    $t[] = ['wysylka', 'en', 'Parcel dispatched', 'Order {{numer}} dispatched',
"Hello {{imie}},

the parcel with order {{numer}} is on its way.
Pick-up point: {{paczkomat}}

Tracking: {{link}}

Szoko Shop", 'wysylka'];

    // ---- Demande de paiement (proforma) ------------------------------------
    $t[] = ['zadanie_zaplaty', 'pl', 'Prośba o płatność', 'Prośba o płatność — {{numer}}',
"Dzień dobry {{imie}},

w załączeniu prośba o płatność za zamówienie {{numer}} na kwotę {{kwota}}.

{{pozycje}}

Prosimy o przelew do {{termin}} na rachunek:
{{rachunek}}
W tytule prosimy podać {{numer}}.

Po zaksięgowaniu wpłaty wystawimy fakturę i ruszamy z realizacją.

Szoko Shop", 'zadanie_zaplaty'];

    $t[] = ['zadanie_zaplaty', 'en', 'Payment request', 'Payment request — {{numer}}',
"Hello {{imie}},

please find our payment request for order {{numer}}, total {{kwota}}.

{{pozycje}}

Bank transfer by {{termin}} to:
{{rachunek}}
Please quote {{numer}} as the reference.

We issue the invoice and start production as soon as the payment clears.

Szoko Shop", 'zadanie_zaplaty'];

    $t[] = ['zadanie_zaplaty', 'uk', 'Запит на оплату', 'Запит на оплату — {{numer}}',
"Доброго дня, {{imie}}!

Надсилаємо запит на оплату замовлення {{numer}} на суму {{kwota}}.

{{pozycje}}

Просимо переказати кошти до {{termin}} на рахунок:
{{rachunek}}
У призначенні платежу вкажіть {{numer}}.

Після зарахування оплати виставимо рахунок і почнемо виконання.

Szoko Shop", 'zadanie_zaplaty'];

    // ---- Relance ------------------------------------------------------------
    $t[] = ['przypomnienie', 'pl', 'Przypomnienie o płatności', 'Przypomnienie — {{numer}} po terminie',
"Dzień dobry {{imie}},

przypominamy o niezapłaconej fakturze {{numer}} na kwotę {{kwota}},
z terminem płatności {{termin}}.

Jeśli przelew został już wykonany, prosimy potraktować tę wiadomość jako
bezprzedmiotową i dać nam znać — sprawdzimy księgowanie.

Rachunek: {{rachunek}}

Szoko Shop", 'przypomnienie'];

    $t[] = ['przypomnienie', 'en', 'Payment reminder', 'Reminder — {{numer}} overdue',
"Hello {{imie}},

a reminder about unpaid invoice {{numer}}, total {{kwota}}, due {{termin}}.

If the transfer has already been made, please disregard this message and let
us know — we will check our records.

Account: {{rachunek}}

Szoko Shop", 'przypomnienie'];

    $t[] = ['przypomnienie', 'uk', 'Нагадування про оплату', 'Нагадування — {{numer}} прострочено',
"Доброго дня, {{imie}}!

Нагадуємо про несплачений рахунок {{numer}} на суму {{kwota}},
термін оплати — {{termin}}.

Якщо переказ уже здійснено, просимо вважати це повідомлення неактуальним
і повідомити нас — ми перевіримо зарахування.

Рахунок: {{rachunek}}

Szoko Shop", 'przypomnienie'];

    // ---- Changements d'état saisis dans la console -------------------------
    //  Le texte reste court : un client qui reçoit quatre messages pour une
    //  commande ne lit que la première ligne de chacun.
    $t[] = ['w_realizacji', 'pl', 'W realizacji', 'Zamówienie {{numer}} jest w realizacji',
"Dzień dobry {{imie}},

zabraliśmy się do Państwa zamówienia {{numer}}. Damy znać, gdy paczka ruszy w drogę.

Podgląd zamówienia: {{link}}

Szoko Shop", 'w_realizacji'];

    $t[] = ['w_realizacji', 'en', 'In progress', 'Order {{numer}} is being prepared',
"Hello {{imie}},

we have started preparing your order {{numer}}. We will write again when the parcel is on its way.

Order details: {{link}}

Szoko Shop", 'w_realizacji'];

    $t[] = ['w_realizacji', 'uk', 'У роботі', 'Замовлення {{numer}} у роботі',
"Доброго дня, {{imie}}!

Ми взялися за Ваше замовлення {{numer}}. Повідомимо, щойно посилка вирушить.

Перегляд замовлення: {{link}}

Szoko Shop", 'w_realizacji'];

    $t[] = ['wyslane', 'pl', 'Wysłane', 'Zamówienie {{numer}} wysłane',
"Dzień dobry {{imie}},

zamówienie {{numer}} zostało wysłane.

Numer przesyłki: {{sledzenie}}
Sposób dostawy: {{dostawa}} {{paczkomat}}

Podgląd zamówienia: {{link}}

Szoko Shop", 'wyslane'];

    $t[] = ['wyslane', 'en', 'Shipped', 'Order {{numer}} has been shipped',
"Hello {{imie}},

order {{numer}} is on its way.

Tracking number: {{sledzenie}}
Delivery: {{dostawa}} {{paczkomat}}

Order details: {{link}}

Szoko Shop", 'wyslane'];

    $t[] = ['wyslane', 'uk', 'Відправлено', 'Замовлення {{numer}} відправлено',
"Доброго дня, {{imie}}!

Замовлення {{numer}} вирушило до Вас.

Номер відправлення: {{sledzenie}}
Доставка: {{dostawa}} {{paczkomat}}

Перегляд замовлення: {{link}}

Szoko Shop", 'wyslane'];

    $t[] = ['dostarczone', 'pl', 'Dostarczone', 'Zamówienie {{numer}} dostarczone',
"Dzień dobry {{imie}},

zamówienie {{numer}} zostało dostarczone. Mamy nadzieję, że wszystko dotarło w porządku —
jeśli coś jest nie tak, prosimy o odpowiedź na tę wiadomość.

Szoko Shop", 'dostarczone'];

    $t[] = ['dostarczone', 'en', 'Delivered', 'Order {{numer}} delivered',
"Hello {{imie}},

order {{numer}} has been delivered. We hope everything arrived in good condition —
if anything is wrong, simply reply to this message.

Szoko Shop", 'dostarczone'];

    $t[] = ['dostarczone', 'uk', 'Доставлено', 'Замовлення {{numer}} доставлено',
"Доброго дня, {{imie}}!

Замовлення {{numer}} доставлено. Сподіваємось, усе прибуло в порядку —
якщо щось не так, просто дайте відповідь на цей лист.

Szoko Shop", 'dostarczone'];

    $t[] = ['anulowane', 'pl', 'Anulowane', 'Zamówienie {{numer}} anulowane',
"Dzień dobry {{imie}},

zamówienie {{numer}} zostało anulowane. Jeśli była to pomyłka albo płatność już wyszła,
prosimy o kontakt — wyjaśnimy to od razu.

Szoko Shop", 'anulowane'];

    $t[] = ['anulowane', 'en', 'Cancelled', 'Order {{numer}} cancelled',
"Hello {{imie}},

order {{numer}} has been cancelled. If this was a mistake, or your payment has already left,
please get in touch — we will sort it out straight away.

Szoko Shop", 'anulowane'];

    $t[] = ['anulowane', 'uk', 'Скасовано', 'Замовлення {{numer}} скасовано',
"Доброго дня, {{imie}}!

Замовлення {{numer}} скасовано. Якщо це помилка або оплата вже пішла,
будь ласка, зв'яжіться з нами — ми одразу все з'ясуємо.

Szoko Shop", 'anulowane'];

    // ---- Formulaire de contact : l'accusé de réception ----------------------
    //  Court, et il ne promet pas de délai qu'on ne tiendrait pas. Il rappelle
    //  le sujet choisi : quelqu'un qui écrit trois fois sait laquelle il lit.
    $t[] = ['formularz', 'pl', 'Potwierdzenie kontaktu', 'Otrzymaliśmy Twoją wiadomość',
"Dzień dobry {{imie}},

dziękujemy za wiadomość — temat: {{temat}}. Zapisaliśmy ją i odpowiemy
tak szybko, jak to możliwe.

Ten e-mail jest tylko potwierdzeniem: nie trzeba na niego odpowiadać.

Szoko Shop
{{sklep}}", 'formularz'];

    $t[] = ['formularz', 'en', 'Contact confirmation', 'We have received your message',
"Hello {{imie}},

thank you for writing — subject: {{temat}}. Your message has been saved and
we will get back to you as soon as we can.

This e-mail is a confirmation only; there is no need to reply to it.

Szoko Shop
{{sklep}}", 'formularz'];

    $t[] = ['formularz', 'uk', 'Підтвердження звернення', 'Ми отримали ваше повідомлення',
"Доброго дня, {{imie}}!

Дякуємо за звернення — тема: {{temat}}. Ми зберегли ваше повідомлення
й відповімо якнайшвидше.

Цей лист — лише підтвердження, відповідати на нього не потрібно.

Szoko Shop
{{sklep}}", 'formularz'];

    // ---- Commande abandonnée à la caisse -----------------------------------
    //  ELLE PORTE LE LIEN, PAS UN RIB. Le client a choisi ses articles et
    //  s'est arrêté au paiement : lui demander un virement le fait renoncer
    //  une seconde fois. Et le ton reste celui d'un rappel, jamais d'une
    //  relance de recouvrement — il ne doit rien, il n'a pas fini.
    $t[] = ['niedokonczone', 'pl', 'Zamówienie czeka', 'Twoje zamówienie {{numer}} czeka na opłacenie',
"Dzień dobry {{imie}},

zamówienie {{numer}} jest gotowe, brakuje tylko płatności:

{{pozycje}}

Do zapłaty: {{kwota}}. Płatność zajmie chwilę:
{{link}}

Jeśli zmieniłeś zdanie — nic nie musisz robić, zamówienie samo wygaśnie.

Szoko Shop
{{sklep}}", 'niedokonczone'];

    $t[] = ['niedokonczone', 'en', 'Your order is waiting', 'Your order {{numer}} is waiting for payment',
"Hello {{imie}},

order {{numer}} is ready — only the payment is missing:

{{pozycje}}

Amount: {{kwota}}. Paying takes a moment:
{{link}}

Changed your mind? Do nothing — the order will simply expire.

Szoko Shop
{{sklep}}", 'niedokonczone'];

    $t[] = ['niedokonczone', 'uk', 'Замовлення чекає', 'Ваше замовлення {{numer}} чекає на оплату',
"Доброго дня, {{imie}}!

Замовлення {{numer}} готове — бракує лише оплати:

{{pozycje}}

До сплати: {{kwota}}. Оплата займе хвилину:
{{link}}

Передумали? Нічого не робіть — замовлення просто скасується.

Szoko Shop
{{sklep}}", 'niedokonczone'];

    // Le dernier message le DIT. « Ostatnie » évite au client de se demander
    // combien il en recevra encore, et nous évite d'en envoyer un troisième.
    $t[] = ['niedokonczone2', 'pl', 'Ostatnie przypomnienie', 'Ostatnie przypomnienie — {{numer}}',
"Dzień dobry {{imie}},

to ostatnia wiadomość w sprawie zamówienia {{numer}} — więcej nie napiszemy.

Do zapłaty: {{kwota}}
{{link}}

Jeśli nie chcesz go realizować, po prostu zignoruj ten list.

Szoko Shop
{{sklep}}", 'niedokonczone2'];

    $t[] = ['niedokonczone2', 'en', 'Last reminder', 'Last reminder — {{numer}}',
"Hello {{imie}},

this is the last message about order {{numer}} — we will not write again.

Amount: {{kwota}}
{{link}}

If you no longer want it, simply ignore this e-mail.

Szoko Shop
{{sklep}}", 'niedokonczone2'];

    $t[] = ['niedokonczone2', 'uk', 'Останнє нагадування', 'Останнє нагадування — {{numer}}',
"Доброго дня, {{imie}}!

Це останній лист щодо замовлення {{numer}} — більше не писатимемо.

До сплати: {{kwota}}
{{link}}

Якщо ви передумали, просто проігноруйте цей лист.

Szoko Shop
{{sklep}}", 'niedokonczone2'];

    // ---- Abonnement : l'échéance est PRÊTE, elle n'est pas PRÉLEVÉE --------
    //  Ce texte est le contrat. Rien n'est débité : la boutique n'enregistre
    //  aucune carte. Écrire « pobraliśmy » ferait du premier renouvellement
    //  un litige — et donnerait raison au client.



    // ---- Modèle libre, sans événement : la réponse écrite à la main --------
    $t[] = ['kontakt', 'pl', 'Odpowiedź do klienta (pusty)', 'W sprawie zamówienia {{numer}}',
"Dzień dobry {{imie}},

Szoko Shop", ''];

    foreach ($t as $row) $ins->execute($row);
}

/**
 * Les modèles ajoutés APRÈS la mise en service.
 *
 * Le semis complet ne tourne qu'une fois, sur une base vide — c'est voulu :
 * un déploiement ne doit jamais réécrire un texte que quelqu'un a corrigé à la
 * main. Mais un modèle entièrement NOUVEAU n'écrase rien, et sans lui la
 * fonction qui l'utilise reste muette. On n'insère donc que les couples
 * (code, langue) absents, et on ne touche jamais à ce qui existe.
 *
 * @return int nombre de modèles ajoutés
 */
function wsm_seed_mail_templates_topup(PDO $pdo): int {
    $have = [];
    foreach ($pdo->query("SELECT code, lang FROM wsm_mail_templates")->fetchAll() ?: [] as $r) {
        $have[$r['code'] . '|' . $r['lang']] = true;
    }
    if (!$have) { wsm_seed_mail_templates($pdo); return -1; }   // base vide : semis complet

    // On rejoue le semis dans une base jetable pour en extraire la liste
    // courante : une seule source de vérité pour les textes.
    $tmp = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                                   PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $tmp->exec("CREATE TABLE wsm_mail_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, lang TEXT,
                 name TEXT, subject TEXT, body TEXT, event TEXT, active INTEGER)");
    wsm_seed_mail_templates($tmp);

    $ins = $pdo->prepare('INSERT INTO wsm_mail_templates (code, lang, name, subject, body, event, active)
                          VALUES (?,?,?,?,?,?,1)');
    $n = 0;
    foreach ($tmp->query("SELECT code, lang, name, subject, body, event FROM wsm_mail_templates ORDER BY id")->fetchAll() ?: [] as $r) {
        if (isset($have[$r['code'] . '|' . $r['lang']])) continue;
        $ins->execute([$r['code'], $r['lang'], $r['name'], $r['subject'], $r['body'], $r['event']]);
        $n++;
    }
    return $n;
}
