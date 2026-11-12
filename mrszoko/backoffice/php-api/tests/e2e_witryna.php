<?php
// ============================================================================
//  e2e_witryna.php — la vitrine dit ce que l'équipe a demandé.
//
//  Deux courriers de l'équipe, en octobre 2026. Le premier : contact sur
//  l'accueil, logos de livraison et de paiement, fiche produit en trois
//  blocs, valeurs nutritives. Le second : « Szoko Shop » avec le logo
//  d'origine, des icônes en couleur, plus de bandeau du bas, et en haut du
//  hero la livraison gratuite dès 700 zł (un bus) et l'expédition le jour
//  même pour une commande passée avant 12:00 (une horloge).
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. LE NOM ET LE SEUIL CHANGENT EN BASE, UNE SEULE FOIS. Le fichier de
//      contenu ne réécrit jamais un texte déjà en base : sans migration, la
//      production garderait « Market Levano » sous un déploiement vert. Mais
//      une règle rejouée à chaque déploiement reprendrait à l'équipe ce
//      qu'elle remet exprès — « Mister Szoko » est ce que dit le logo. D'où
//      un marqueur : jouée une fois, plus jamais. Seuls les seuils LIVRÉS
//      passent à 700 zł ; un seuil réglé dans Dostawa ne bouge pas. Les deux
//      voies (PHP, et le SQL de production traduit en SQLite) sont jouées
//      sur une COPIE de la base.
//   2. LE SEUIL AFFICHÉ EST CELUI QUE LA CAISSE APPLIQUE : {prog} est rempli
//      depuis Dostawa, jamais recopié dans un texte ; sans seuil, la phrase
//      disparaît au lieu d'annoncer « od 0 zł ».
//   3. UN LOGO DE PAIEMENT EST UNE PROMESSE : par défaut, la vitrine montre
//      les modes de livraison ACTIFS et, pour payer, Stripe s'il est prêt,
//      sinon le virement. Le choix de la console fait foi, « rien » compris.
//   4. LES DESSINS NE CHARGENT RIEN : aucune adresse externe, aucun script,
//      les libellés échappés.
//   5. LES VALEURS NUTRITIVES SONT DES CHIFFRES, dans l'ordre du règlement UE
//      1169/2011 ; un mot est refusé. Les propriétés sont des textes traduits.
//   6. LA PAGE SERVIE (8091) : nom et logo d'origine, bandeau du haut,
//      icônes en couleur, téléphone cliquable, logos en pied de page, fiche
//      produit en trois blocs, titre de rayon, aucun ancien nom.
//
//  Usage :  php tests/e2e_witryna.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/delivery.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/settings.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/seed.php';
require_once dirname(__DIR__) . '/layout.php';
require_once dirname(dirname(__DIR__), 2) . '/shop/znaki.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end witryna (Szoko Shop)\n\n";

$sfx = bin2hex(random_bytes(3));
$racine = dirname(__DIR__, 4);
$lire = fn(string $rel) => (string) @file_get_contents($racine . '/' . $rel);
$val = function (string $k, string $lang = 'pl') use ($pdo): ?string {
    $st = $pdo->prepare("SELECT v FROM wsm_shop_i18n WHERE lang = ? AND k = ?");
    $st->execute([$lang, $k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
};
$nbsp = "\u{00A0}";

// ---- 1. Le nom, dans les fichiers -------------------------------------------------------
echo "-- nazwa: Szoko Shop w plikach, logo z początków --\n";
foreach (['mrszoko/shop/content_seed.json', 'mrszoko/landing/content_seed.json'] as $f) {
    $doc = json_decode($lire($f), true);
    $txt = json_encode($doc['strings'] ?? [], JSON_UNESCAPED_UNICODE);
    ok("$f : plus un ancien nom dans les textes", str_contains($txt, 'Szoko Shop')
       && !str_contains($txt, 'Market Levano') && !str_contains($txt, 'Mister Szoko'));     // pro@misterszoko.com est revenue exprès
}
$seedDoc = json_decode($lire('mrszoko/shop/content_seed.json'), true);
$seed = $seedDoc['strings'];
ok('la marque, dans les trois langues', ($seed['pl']['brand'] ?? '') === 'Szoko Shop' && ($seed['uk']['brand'] ?? '') === 'Szoko Shop' && ($seed['en']['brand'] ?? '') === 'Szoko Shop');
ok('l\'adresse d\'origine et le téléphone avec +48', ($seed['pl']['footer.email'] ?? '') === 'pro@misterszoko.com' && ($seed['pl']['footer.phone'] ?? '') === '+48 660 884 677');
ok('… et plus un MarketLevano@gmail.com nulle part dans les fichiers', !str_contains(strtolower($lire('mrszoko/shop/content_seed.json') . $lire('mrszoko/landing/content_seed.json')), 'marketlevano@gmail.com'));
$neufs = ['info.darmowa', 'home.contact.title', 'metody.ship', 'metody.pay', 'metoda.blik', 'product.props', 'product.nutrition', 'nutri.energy', 'nutri.salt', 'product.category'];
ok('les nouveaux libellés existent en pl, uk et en', !array_filter($neufs, fn($k) => empty($seed['pl'][$k]) || empty($seed['uk'][$k]) || empty($seed['en'][$k])));
ok('… sans tiret cadratin (le déploiement les changerait en virgules)', !array_filter($neufs, fn($k) => str_contains($seed['pl'][$k] . $seed['uk'][$k] . $seed['en'][$k], ' — ')));
$langs = ['pl', 'uk', 'en'];
ok('le bandeau et la promesse portent {prog}, pas un montant recopié',
   !array_filter($langs, fn($l) => !str_contains($seed[$l]['info.darmowa'], '{prog}') || !str_contains($seed[$l]['promise.2.d'], '{prog}')
                                   || preg_match('/\d\s*(zł|zl)/u', $seed[$l]['promise.2.d'] . $seed[$l]['info.darmowa'])));
ok('l\'heure limite, 12:00, dans un seul texte : la promesse « Wysyłka w 24 h »', !array_filter($langs, fn($l) => !str_contains($seed[$l]['promise.1.d'], '12:00')));
ok('les slogans du bandeau du bas ne sont plus livrés…', !array_filter($langs, fn($l) => array_filter(array_keys($seed[$l]), fn($k) => str_starts_with($k, 'story.strip.'))));
$purge = wsm_content_forces()['wsm_shop_i18n']['purge'] ?? [];
ok('… et partent de la base', !array_diff(['story.strip.1', 'story.strip.2', 'story.strip.3'], $purge));
$seuilsLivres = array_column($seedDoc['shipping'], 'free_from', 'id');
ok('livrés : Paczkomat, kurier InPost et DPD gratuits dès 700 zł', $seuilsLivres['inpost_locker'] === 70000 && $seuilsLivres['inpost_courier'] === 70000 && $seuilsLivres['dpd_courier'] === 70000, $seuilsLivres);
ok('le nom lu par la console vient de Treści', wsm_brand_name() === ($val('brand') ?: 'Szoko Shop'), wsm_brand_name());
$consoleTxt = '';
foreach (glob($racine . '/mrszoko/backoffice/*.php') as $f) foreach (file($f) as $l) if (!preg_match('#^\s*(//|\*|/\*)#', $l)) $consoleTxt .= $l;
ok('plus un ancien nom affiché par la console', !str_contains($consoleTxt, 'Market Levano') && !str_contains($consoleTxt, 'Mister Szoko'));
// Sans logo déposé, la console, l'écran de connexion et la vitrine prennent le
// logo du design system de la boutique (ds.php) — chez Szoko, le logo d'origine.
ok('la console et l\'écran de connexion reprennent le logo du design system', str_contains($lire('mrszoko/backoffice/console.php'), "'../shop/' . wsm_ds_logo_path()")
   && str_contains($lire('mrszoko/backoffice/login.php'), "'../shop/' . wsm_ds_logo_path()") && !str_contains($consoleTxt, 'logo.svg'));
ok('le design system de Szoko porte le logo d\'origine', json_decode($lire('design-systems/szoko/ds.json'), true)['logo'] === 'assets/logo.png');
foreach (['design-systems/szoko/assets/logo.png', 'mrszoko/shop/assets/logo.png', 'mrszoko/backoffice/img/logo.png'] as $f) {
    $png = $lire($f);
    $dim = $png === '' ? false : @getimagesizefromstring($png);
    ok("$f : le logo d'origine, un vrai PNG", str_starts_with($png, "\x89PNG\r\n\x1a\n") && $dim !== false && $dim[0] >= 100 && $dim[1] >= 100, $dim ?: null);
}
ok('le même fichier dans le design system, la vitrine et la console', $lire('mrszoko/shop/assets/logo.png') === $lire('mrszoko/backoffice/img/logo.png')
   && $lire('design-systems/szoko/assets/logo.png') === $lire('mrszoko/shop/assets/logo.png'));
ok('le monogramme SVG d\'une semaine n\'est plus livré', !is_file($racine . '/mrszoko/shop/assets/logo.svg') && !is_file($racine . '/mrszoko/backoffice/img/logo.svg'));

// ---- 2. Les migrations à un seul coup, sur une copie de la base --------------------------
echo "\n-- migracje jednorazowe: nazwa i próg 700 zł, raz na bazę --\n";
ok('chaque migration a son marqueur', array_slice(array_keys(wsm_content_jednorazowe()), 0, 2) === ['migracja.2026_10_szoko_shop', 'migracja.2026_10_dostawa_700']);
ok('aucun nom de marque dans les remplacements de chaque déploiement', !array_filter(wsm_content_remplacements(), fn($r) => preg_match('/szoko|levano/i', $r[2] . $r[3])));
$sql = wsm_sync_content_sql();
$lignes = explode("\n", $sql);
$garde = 'AND NOT EXISTS (SELECT 1 FROM wsm_settings WHERE cle = ';
$hexNoms = [bin2hex('Market Levano'), bin2hex('Mister Szoko')];
$nommees = array_filter($lignes, fn($l) => str_contains($l, $hexNoms[0]) || str_contains($l, $hexNoms[1]));
ok('la voie SQL ne touche jamais un ancien nom sans la garde du marqueur', count($nommees) >= 12 && !array_filter($nommees, fn($l) => !str_contains($l, $garde)), count($nommees));
ok('… les seuils non plus, et seulement ceux d\'origine', str_contains($sql, 'UPDATE wsm_shipping_methods SET free_from = 70000 WHERE id = ' . wsm_sql_txt('inpost_locker') . ' AND free_from = 20000 ' . $garde)
   && str_contains($sql, 'UPDATE wsm_shipping_methods SET free_from = 70000 WHERE id = ' . wsm_sql_txt('dpd_courier') . ' AND free_from = 30000 ' . $garde));
foreach (array_keys(wsm_content_jednorazowe()) as $m) {
    $pose = strpos($sql, 'INSERT IGNORE INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (' . wsm_sql_txt($m));
    ok("$m : le marqueur se pose après sa dernière opération", $pose !== false && $pose > strrpos($sql, $garde . wsm_sql_txt($m) . ')'));
}

// La même histoire jouée deux fois, par chaque voie, sur une copie : une base
// qui n'a jamais vu ces migrations, puis la même base après que l'équipe a
// remis « Mister Szoko » dans une phrase et 200 zł dans Dostawa.
// Une COPIE de la base : sous SQLite, un fichier (VACUUM INTO) ; sous MySQL,
// le moteur de production, la même base dans une transaction annulée à la
// fin. Chaque voie se joue ainsi sur le moteur qui l'exécutera vraiment.
$mysql = wsm_sql_mysql($pdo);
$copie = function () use ($pdo, $mysql): array {
    if ($mysql) { $pdo->beginTransaction(); return [$pdo, null]; }
    $f = sys_get_temp_dir() . '/wsm-witryna-' . bin2hex(random_bytes(4)) . '.sqlite';
    $pdo->exec('VACUUM INTO ' . $pdo->quote($f));
    return [new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]), $f];
};
$jeter = function (PDO $c, ?string $f): void {
    if ($f === null) { if ($c->inTransaction()) $c->rollBack(); return; }
    @unlink($f);
};
$un = function (PDO $c, string $q, array $a = []) { $st = $c->prepare($q); $st->execute($a); return $st->fetchColumn(); };
$scenario = function (string $voie, callable $jouer) use ($copie, $jeter, $un, $sfx): void {
    [$c, $f] = $copie();
    $c->exec("DELETE FROM wsm_settings WHERE cle LIKE 'migracja.%'");
    $c->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES ('pl', ?, ?)")->execute(["test.witryna.$sfx", 'Napisz do Market Levano: pro@misterszoko.com, dziękujemy']);
    $c->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES ('en', ?, ?)")->execute(["test.witryna.$sfx", 'Mister Szoko, since 2019']);
    // L'adresse Market Levano, sous deux casses, et le numéro d'avant le +48.
    $c->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES ('uk', ?, ?)")->execute(["test.witryna.$sfx", 'Пишіть: MarketLevano@gmail.com або marketlevano@gmail.com']);
    $c->exec("UPDATE wsm_shop_i18n SET v = '660 884 677' WHERE k = 'footer.phone'");
    $c->prepare("INSERT INTO wsm_mail_templates (code, lang, name, subject, body, event, active) VALUES (?, 'pl', 't', 'Zamówienie, Market Levano', ?, '', 0)")
      ->execute(["test-$sfx", "Dziękujemy.\nMister Szoko"]);
    $c->exec("UPDATE wsm_shipping_methods SET free_from = 20000 WHERE id = 'inpost_locker'");
    $c->exec("UPDATE wsm_shipping_methods SET free_from = 25000 WHERE id = 'inpost_courier'");   // réglé à la main
    $c->exec("UPDATE wsm_shipping_methods SET free_from = 30000 WHERE id = 'dpd_courier'");
    $c->exec("UPDATE wsm_shop_i18n SET v = 'InPost w całej Polsce. Darmowa dostawa od 200 zł.' WHERE lang = 'pl' AND k = 'promise.2.d'");
    $c->exec("UPDATE wsm_shop_i18n SET v = 'Free delivery, our way' WHERE lang = 'en' AND k = 'promise.2.d'");      // retouchée
    $c->exec("UPDATE wsm_shop_i18n SET v = 'Zamówienia złożone do 13:00 pakujemy tego samego dnia.' WHERE lang = 'pl' AND k = 'promise.1.d'");
    $jouer($c);
    $v = fn(string $l, string $k) => $un($c, "SELECT v FROM wsm_shop_i18n WHERE lang = ? AND k = ?", [$l, $k]);
    $seuil = fn(string $id) => (int) $un($c, "SELECT free_from FROM wsm_shipping_methods WHERE id = ?", [$id]);
    ok("$voie : l'ancien nom et l'ancienne adresse changent, le reste de la phrase reste",
       $v('pl', "test.witryna.$sfx") === 'Napisz do Szoko Shop: pro@misterszoko.com, dziękujemy', $v('pl', "test.witryna.$sfx"));
    ok("$voie : MarketLevano@gmail.com redevient pro@misterszoko.com, quelle que soit la casse",
       $v('uk', "test.witryna.$sfx") === 'Пишіть: pro@misterszoko.com або pro@misterszoko.com', $v('uk', "test.witryna.$sfx"));
    ok("$voie : le numéro livré prend le +48", $v('pl', 'footer.phone') === '+48 660 884 677' && $v('en', 'footer.phone') === '+48 660 884 677', $v('pl', 'footer.phone'));
    ok("$voie : … les deux anciens noms, jusque dans les messages aux clients", $v('en', "test.witryna.$sfx") === 'Szoko Shop, since 2019'
       // Deux lectures, pas « subject || body » : sous MySQL, || est un OU logique.
       && $un($c, "SELECT subject FROM wsm_mail_templates WHERE code = ?", ["test-$sfx"]) === 'Zamówienie, Szoko Shop'
       && $un($c, "SELECT body FROM wsm_mail_templates WHERE code = ?", ["test-$sfx"]) === "Dziękujemy.\nSzoko Shop");
    ok("$voie : les seuils d'origine passent à 700 zł, un seuil réglé à la main reste",
       [$seuil('inpost_locker'), $seuil('inpost_courier'), $seuil('dpd_courier')] === [70000, 25000, 70000],
       [$seuil('inpost_locker'), $seuil('inpost_courier'), $seuil('dpd_courier')]);
    ok("$voie : la promesse d'origine prend {prog} et 12:00, une promesse retouchée reste",
       $v('pl', 'promise.2.d') === 'InPost w całej Polsce. Darmowa dostawa od {prog}.' && $v('en', 'promise.2.d') === 'Free delivery, our way'
       && $v('pl', 'promise.1.d') === 'Zamówienia złożone do 12:00 wysyłamy tego samego dnia.');
    ok("$voie : les deux marqueurs posés, au nom du déploiement",
       (int) $un($c, "SELECT COUNT(*) FROM wsm_settings WHERE cle IN ('migracja.2026_10_szoko_shop', 'migracja.2026_10_dostawa_700') AND updated_by = 'wdrozenie'") === 2);
    $c->prepare("UPDATE wsm_shop_i18n SET v = ? WHERE lang = 'pl' AND k = ?")->execute(['Logo: Mister Szoko', "test.witryna.$sfx"]);
    $c->exec("UPDATE wsm_shipping_methods SET free_from = 20000 WHERE id = 'inpost_locker'");
    $jouer($c);
    ok("$voie : rejouée, elle ne reprend rien à l'équipe", $v('pl', "test.witryna.$sfx") === 'Logo: Mister Szoko' && $seuil('inpost_locker') === 20000);
    $jeter($c, $f);
};
$scenario('voie PHP', fn(PDO $c) => wsm_sync_content($c));
// Le SQL de production, tel qu'émis, traduit en dialecte SQLite : les
// littéraux hexadécimaux en texte, INSERT IGNORE et NOW() en leurs
// équivalents. Seules les lignes des migrations sont jouées.
$hexMig = bin2hex('migracja.');
$migSql = array_map(fn($l) => $mysql ? $l : str_replace(['INSERT IGNORE INTO', 'NOW()'], ['INSERT OR IGNORE INTO', "datetime('now')"],
    preg_replace('/_utf8mb4 0x([0-9a-f]+)/', 'CAST(X\'$1\' AS TEXT)', $l)), array_values(array_filter($lignes, fn($l) => str_contains($l, $hexMig))));
$scenario('voie SQL', function (PDO $c) use ($migSql) { foreach ($migSql as $q) $c->exec($q); });

// ---- 3. Le seuil affiché : celui de la caisse -------------------------------------------
echo "\n-- próg darmowej dostawy: z Dostawy, nie z tekstu --\n";
$code = 'require ' . var_export($racine . '/mrszoko/shop/lib.php', true) . ';'
      . ' echo json_encode([kwota_krotka(70000), kwota_krotka(1234550), kwota_krotka(9990),'
      . ' z_progiem("InPost w całej Polsce. Darmowa dostawa od {prog}.", null), z_progiem("Darmowa dostawa już od {prog}", null),'
      . ' z_progiem("Darmowa dostawa od {prog}.", 55000), z_progiem("Bez progu.", 5), prog_darmowej(wsm_pdo()), logo_src()], JSON_UNESCAPED_UNICODE);';
$r = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1'), true) ?: [];
ok('un montant rond sans décimales, un autre avec, espaces insécables', ($r[0] ?? '') === "700{$nbsp}zł" && ($r[1] ?? '') === "12{$nbsp}345,50{$nbsp}zł" && ($r[2] ?? '') === "99,90{$nbsp}zł", $r);
ok('sans seuil, la phrase qui le cite disparaît, le reste demeure', ($r[3] ?? null) === 'InPost w całej Polsce.' && ($r[4] ?? null) === '');
ok('avec un seuil, il remplace {prog} ; un texte sans {prog} reste tel quel', ($r[5] ?? '') === "Darmowa dostawa od 550{$nbsp}zł." && ($r[6] ?? '') === 'Bez progu.');
$attendu = $pdo->query("SELECT MIN(free_from) FROM wsm_shipping_methods WHERE active = 1 AND free_from > 0")->fetchColumn();
ok('le seuil lu est le plus bas des modes ACTIFS', ($r[7] ?? 'x') === ($attendu === null || $attendu === false ? null : (int) $attendu), [$r[7] ?? null, $attendu]);
ok('sans logo déposé, le logo du design system (Szoko : celui d\'origine)', str_ends_with((string) ($r[8] ?? ''), 'ds/szoko/logo.png'), $r[8] ?? null);

// ---- 4. Le logo et les dessins ------------------------------------------------------------
echo "\n-- rysunki: kolorowe, nic z zewnątrz, nic wykonywalnego --\n";
$svg = $lire('mrszoko/shop/assets/hero-art.svg');
ok('l\'illustration du hero : un SVG sans script ni adresse externe', str_starts_with(ltrim($svg), '<svg') && !preg_match('/<script|on[a-z]+=|https?:\/\/(?!www\.w3\.org)|xlink:href="http/i', $svg));
$tous = array_merge(array_keys(WSM_METODY_WYSYLKI), array_keys(WSM_METODY_PLATNOSCI));
ok('chaque logo de la console a sa tuile', !array_filter($tous, fn($c) => znak_tile($c) === ''));
ok('un code inconnu ne dessine rien', znak_tile('paypal') === '' && znak_tile('<b>') === '');
$tuiles = implode('', array_map('znak_tile', $tous));
ok('les tuiles ne chargent rien et n\'exécutent rien', !preg_match('/<script|on[a-z]+=|https?:|<img|href/i', $tuiles));
ok('Mastercard : deux cercles et leur intersection, aux trois couleurs', str_contains($tuiles, '#EB001B') && str_contains($tuiles, '#F79E1B') && str_contains($tuiles, '#FF5F00'));
ok('les libellés passent échappés', str_contains(znaki_lista(['blik'], fn($c) => '<b>X</b>'), '&lt;b&gt;X&lt;/b&gt;'));
ok('une liste vide ne rend rien', znaki_lista([], fn($c) => $c) === '' && znaki_lista(['nic'], fn($c) => $c) === '');
ok('les promesses en couleur : camion caramel, colis kraft, thermomètre rouge et flocon bleu',
   str_contains(ikona_obietnicy(1), '#C68A3C') && str_contains(ikona_obietnicy(2), '#E2B97E')
   && str_contains(ikona_obietnicy(3), '#E0473C') && str_contains(ikona_obietnicy(3), '#2E8BD8') && ikona_obietnicy(4) === '');
ok('le bus et l\'horloge du bandeau, rien pour un code inconnu', str_contains(ikona_korzysci('bus'), 'class="korzysc-ikona"')
   && str_contains(ikona_korzysci('zegar'), 'class="korzysc-ikona"') && ikona_korzysci('<b>') === '');
$dessins = ikona_obietnicy(1) . ikona_obietnicy(2) . ikona_obietnicy(3) . ikona_korzysci('bus') . ikona_korzysci('zegar');
ok('… et aucun ne charge ni n\'exécute quoi que ce soit', !preg_match('/<script|on[a-z]+=|https?:|<img|href/i', $dessins));

// ---- 5. Ce que la vitrine montre pour payer et recevoir -----------------------------------
echo "\n-- metody: domyślnie prawda, wybór konsoli wygrywa --\n";
$actifs = array_column($pdo->query("SELECT id FROM wsm_shipping_methods WHERE active = 1 ORDER BY sort_order, id")->fetchAll(), 'id');
$attendu = array_values(array_unique(array_filter(array_map(fn($i) => WSM_METODY_Z_DOSTAWY[$i] ?? null, $actifs))));
ok('par défaut, les modes de livraison ACTIFS', wsm_metody_auto($pdo, 'wysylka') === $attendu, [wsm_metody_auto($pdo, 'wysylka'), $actifs]);
ok('… et DPD Pickup n\'y est pas tant que la caisse ne l\'offre pas', !in_array('dpd_pickup', wsm_metody_auto($pdo, 'wysylka'), true));
$stripeAvant = wsm_config()['stripe'] ?? [];
wsm_config_overlay(['stripe' => ['secret_key' => '', 'publishable_key' => '', 'account' => '']]);
ok('sans Stripe prêt, aucun moyen de paiement promis — plus de virement', wsm_metody_auto($pdo, 'platnosc') === []);
wsm_config_overlay(['stripe' => ['secret_key' => 'sk_test_x', 'publishable_key' => 'pk_test_x', 'account' => '']]);
ok('… même avec les clés, tant que la boutique n\'a pas de compte', wsm_metody_auto($pdo, 'platnosc') === []);
wsm_config_overlay(['stripe' => ['account' => 'acct_test1']]);
ok('Stripe prêt : BLIK, cartes, portefeuilles, Przelewy24', wsm_metody_auto($pdo, 'platnosc') === ['blik', 'visa', 'mastercard', 'googlepay', 'applepay', 'przelew_online']);
ok('le virement traditionnel a quitté la liste', !isset(WSM_METODY_PLATNOSCI['przelew']));
wsm_config_overlay(['stripe' => array_replace(['secret_key' => '', 'publishable_key' => '', 'account' => ''], $stripeAvant)]);
$shopAvant = wsm_config()['shop'] ?? [];
wsm_config_overlay(['shop' => ['metody_platnosci' => 'visa,blik,zly']]);
ok('le choix de la console fait foi, dans l\'ordre du dictionnaire, sans code inconnu', wsm_metody($pdo, 'platnosc') === ['blik', 'visa']);
wsm_config_overlay(['shop' => ['metody_platnosci' => 'brak']]);
ok('« brak » : rien du tout', wsm_metody($pdo, 'platnosc') === []);
wsm_config_overlay(['shop' => ['metody_platnosci' => 'xxxx']]);
ok('le masque d\'un réglage vidé : le défaut', wsm_metody($pdo, 'platnosc') === wsm_metody_auto($pdo, 'platnosc'));
wsm_config_overlay(['shop' => $shopAvant]);
$avantM = $pdo->query("SELECT val FROM wsm_settings WHERE cle = 'metody_platnosci'")->fetchColumn();
wsm_settings_save($pdo, ['metody_platnosci__multi' => '1', 'metody_platnosci' => ['visa', 'blik', '<script>']], 'test');
ok('la console enregistre les cases cochées, dans l\'ordre, sans intrus', $pdo->query("SELECT val FROM wsm_settings WHERE cle = 'metody_platnosci'")->fetchColumn() === 'blik,visa');
wsm_settings_save($pdo, ['metody_platnosci__multi' => '1'], 'test');
ok('tout décoché s\'enregistre « brak » — pas « inchangé »', $pdo->query("SELECT val FROM wsm_settings WHERE cle = 'metody_platnosci'")->fetchColumn() === 'brak');
$pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'metody_platnosci'")->execute();
if ($avantM !== false) $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES ('metody_platnosci', ?, 0, ?, 'test')")->execute([$avantM, date('Y-m-d H:i:s')]);
ok('sans le témoin, un autre formulaire ne touche pas au choix', (function () use ($pdo) {
    $avant = $pdo->query("SELECT COUNT(*) FROM wsm_settings WHERE cle = 'metody_wysylki'")->fetchColumn();
    wsm_settings_save($pdo, ['shop_url' => ''], 'test');
    return $pdo->query("SELECT COUNT(*) FROM wsm_settings WHERE cle = 'metody_wysylki'")->fetchColumn() === $avant;
})());

// ---- 6. Valeurs nutritives et propriétés -----------------------------------------------------
echo "\n-- wartości odżywcze i właściwości --\n";
$e = [];
$n = wsm_nutrition_clean(['salt' => '0.02', 'energy_kcal' => '598', 'fat' => ' 54 ', 'saturates' => '<0,5', 'sugars' => 'dużo', 'protein' => '', 'nieznane' => '5'], $e);
ok('des nombres, virgule polonaise, « < » permis, dans l\'ordre du règlement', $n === ['energy_kcal' => '598', 'fat' => '54', 'saturates' => '<0,5', 'salt' => '0,02'], $n);
ok('un mot est refusé, et nommé', $e === ['sugars' => 'dużo'], $e);
ok('aller-retour JSON sans perte', wsm_nutrition_decode(wsm_nutrition_encode($n)) === $n && wsm_nutrition_encode([]) === '' && wsm_nutrition_decode('pas du json') === []);
$S = ['nutri.energy' => 'Wartość energetyczna', 'nutri.fat' => 'Tłuszcz', 'nutri.saturates' => 'w tym nasycone', 'nutri.salt' => 'Sól'];
$rows = wsm_nutrition_rows(['energy_kj' => '2475', 'energy_kcal' => '598', 'fat' => '54', 'saturates' => '32,5', 'salt' => '0,02'], $S);
ok('l\'énergie sur une ligne, kJ puis kcal', $rows[0] === ['Wartość energetyczna', '2475 kJ / 598 kcal', false], $rows[0] ?? null);
ok('« dont » marqué comme sous-ligne, l\'unité ajoutée', $rows[2] === ['w tym nasycone', '32,5 g', true] && $rows[3] === ['Sól', '0,02 g', false], $rows);
$S = ["product.x.prop.1.k" => 'Opakowanie', "product.x.prop.1.v" => 'Worek', "product.x.prop.2.k" => 'Skład', "product.x.prop.2.v" => '',
      "product.x.prop.12.k" => 'Kraj', "product.x.prop.12.v" => 'Belgia', "product.x.prop.13.k" => 'Za dużo', "product.x.prop.13.v" => 'x'];
ok('une propriété n\'est montrée qu\'avec ses deux moitiés, douze au plus', wsm_product_props($S, 'x') === [['Opakowanie', 'Worek'], ['Kraj', 'Belgia']], wsm_product_props($S, 'x'));
ok('les deux schémas et db.php connaissent la colonne nutrition',
   str_contains($lire('mrszoko/backoffice/php-api/schema/webshop_mrszoko.mysql.sql'), '`nutrition`') && str_contains($lire('mrszoko/backoffice/php-api/schema/webshop_mrszoko.sqlite.sql'), 'nutrition       TEXT')
   && str_contains($lire('mrszoko/backoffice/php-api/db.php'), "'nutrition'    => ['TEXT', 'TEXT']"));
ok('la colonne existe dans cette base', in_array('nutrition', wsm_table_columns($pdo, 'wsm_products'), true));
$prod = $lire('mrszoko/backoffice/produkty.php');
ok('la fiche de la console porte les deux tableaux', str_contains($prod, 'name="nutri__on"') && str_contains($prod, 'name="prop[<?= $n ?>][k]"') && str_contains($prod, 'wsm_nutrition_clean('));

// ---- 7. Układ : le bandeau, le contact, le téléphone ------------------------------------------
echo "\n-- układ: pasek u góry, kontakt na stronie głównej, telefon w stopce --\n";
$hb = wsm_layout_home_builtins();
ok('« pasek » dit ce qu\'il montre maintenant, et suit le hero', str_contains($hb['pasek']['label'] ?? '', 'darmowa dostawa') && ($hb['pasek']['pin'] ?? '') === 'hero' && empty($hb['pasek']['fixed']));
ok('« kontakt » est une section de l\'accueil, réglable', isset($hb['kontakt']) && empty($hb['kontakt']['fixed']));
ok('« telefon » est un lien du pied de page, juste après l\'e-mail', array_slice(array_keys(wsm_layout_footer_builtins()), 0, 2) === ['email', 'telefon']);
ok('cachées, les sections laissent leur marqueur', wsm_layout_marker('kontakt') === '<!-- kontakt: ukryty w Układzie strony -->' && wsm_layout_marker('pasek') === '<!-- pasek: ukryty w Układzie strony -->');

// ---- 8. La page servie ---------------------------------------------------------------------
echo "\n-- strona podana (8091) --\n";
$base = 'http://localhost:8091';
$ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true, 'header' => "Accept-Language: pl\r\n"]]);
$get = fn(string $p) => @file_get_contents($base . $p, false, $ctx);
$h = $get('/?lang=pl&t=' . microtime(true));
if ($h === false) { echo "  (sklep na 8091 nie odpowiada — pominięte)\n"; }
else {
    $brand = $val('brand') ?: 'Szoko Shop';
    ok('le nom à côté du logo d\'origine', str_contains($h, '<span class="head-nazwa">' . htmlspecialchars($brand) . '</span>') && str_contains($h, 'ds/szoko/logo.png') && !str_contains($h, 'logo.svg'));
    ok('pas une trace des anciens noms, ni de l\'adresse Market Levano', !str_contains($h, 'Market Levano') && !str_contains($h, 'Mister Szoko') && !str_contains(strtolower($h), 'marketlevano@'));
    // Le haut de page est maintenant le premier slajd (le boulanger) ; sans
    // slajd en ligne, l'illustration d'origine revient — voir e2e_slajdy.
    ok('le haut de page : les slajdy, sinon l\'illustration', str_contains($h, '<section class="hero hero--slajdy"') || str_contains($h, '<section class="hero hero--art"'));
    // Le bandeau du haut : la livraison gratuite au seuil de la caisse, l'heure limite.
    $prog = (int) $pdo->query("SELECT MIN(free_from) FROM wsm_shipping_methods WHERE active = 1 AND free_from > 0")->fetchColumn();
    $kw = fn(int $g) => number_format(intdiv($g, 100), 0, ',', $nbsp) . $nbsp . 'zł';
    $pasek = preg_match('#<section class="hero[^"]*"[^>]*>\s*<div class="hero-strip hero-strip--gora">(.*?)</div>\s*</div>#s', $h, $m) ? $m[1] : '';
    ok('le bandeau ouvre le hero, avant le titre', $pasek !== '' && strpos($h, 'hero-strip--gora') < strpos($h, 'class="wrap hero-in"'));
    ok('… la livraison gratuite au seuil de la caisse, avec le bus', $prog > 0 && str_contains($pasek, htmlspecialchars(str_replace('{prog}', $kw($prog), (string) $val('info.darmowa'))))
       && preg_match('#<span class="korzysc"><svg class="korzysc-ikona"[^>]*>.*?</svg><span>' . preg_quote(htmlspecialchars(str_replace('{prog}', $kw($prog), (string) $val('info.darmowa'))), '#') . '</span></span>#s', $pasek) === 1, $pasek);
    ok('… l\'heure limite, la phrase même de la promesse, avec l\'horloge', str_contains($pasek, '<span>' . htmlspecialchars((string) $val('promise.1.d')) . '</span>')
       && substr_count($pasek, 'class="korzysc-ikona"') === 2 && str_contains($pasek, '<circle cx="24" cy="15" r="12" fill="#FFFFFF"'));
    ok('plus de bandeau du bas : ni ses slogans, ni un {prog} brut', substr_count($h, 'class="hero-strip') === 1
       && !str_contains($h, 'tańszy kilogram') && !str_contains($h, 'Faktury netto') && !str_contains($h, '{prog}'));
    ok('la promesse « Paczkomat lub kurier » donne le même seuil', str_contains($h, '<p>' . htmlspecialchars(str_replace('{prog}', $kw($prog), (string) $val('promise.2.d'))) . '</p>'));
    ok('les trois promesses portent leur dessin, en couleur', substr_count($h, 'promise-ikona--rys') === 3 && str_contains($h, '#2E8BD8') && str_contains($h, '#E2B97E'));
    // Le seuil vient de Dostawa : changé là, il change partout ; absent, rien ne promet « od 0 zł ».
    $seuils = $pdo->query("SELECT id, free_from FROM wsm_shipping_methods")->fetchAll(PDO::FETCH_KEY_PAIR);
    $remet = $pdo->prepare("UPDATE wsm_shipping_methods SET free_from = ? WHERE id = ?");
    $actif = (string) $pdo->query("SELECT id FROM wsm_shipping_methods WHERE active = 1 ORDER BY sort_order LIMIT 1")->fetchColumn();
    $remet->execute([55000, $actif]);
    $h2 = (string) $get('/?lang=pl&t=' . microtime(true));
    ok('un seuil changé dans Dostawa change le bandeau et la promesse', str_contains($h2, htmlspecialchars(str_replace('{prog}', "550{$nbsp}zł", (string) $val('info.darmowa'))))
       && str_contains($h2, htmlspecialchars(str_replace('{prog}', "550{$nbsp}zł", (string) $val('promise.2.d')))));
    $pdo->exec("UPDATE wsm_shipping_methods SET free_from = 0");
    $h3 = (string) $get('/?lang=pl&t=' . microtime(true));
    $godzina = $val('promise.1.d');
    $pdo->prepare("UPDATE wsm_shop_i18n SET v = '' WHERE lang = 'pl' AND k = 'promise.1.d'")->execute();
    $h5 = (string) $get('/?lang=pl&t=' . microtime(true));
    $pdo->prepare("UPDATE wsm_shop_i18n SET v = ? WHERE lang = 'pl' AND k = 'promise.1.d'")->execute([(string) $godzina]);
    foreach ($seuils as $id => $ff) $remet->execute([$ff, $id]);
    ok('ni seuil ni heure : pas de bandeau vide, et la page le dit', !str_contains($h5, 'hero-strip--gora') && str_contains($h5, '<!-- pasek: pusty'));
    ok('sans seuil, pas de « od 0 zł » : le bandeau garde l\'heure seule', substr_count($h3, 'class="korzysc"') === 1 && !preg_match('/od 0\D/u', $h3) && !str_contains($h3, '{prog}'));
    ok('… et la promesse perd sa phrase sur la gratuité, pas le reste', $val('promise.2.d') !== 'InPost w całej Polsce. Darmowa dostawa od {prog}.' || str_contains($h3, '<p>InPost w całej Polsce.</p>'));
    $avantHome = $pdo->query("SELECT val FROM wsm_settings WHERE cle = 'layout.home'")->fetchColumn();
    wsm_layout_toggle($pdo, 'home', 'pasek', 'test');
    $h4 = (string) $get('/?lang=pl&t=' . microtime(true));
    wsm_layout_reset($pdo, 'home');
    if ($avantHome !== false) $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES ('layout.home', ?, 0, ?, 'test')")->execute([$avantHome, date('Y-m-d H:i:s')]);
    ok('caché dans Układ strony, le bandeau laisse son marqueur', !str_contains($h4, 'hero-strip--gora') && str_contains($h4, wsm_layout_marker('pasek')) && str_contains($h4, 'class="wrap hero-in"'));
    ok('le contact sur l\'accueil : un appel, un e-mail, le formulaire', str_contains($h, 'class="wrap block kontakt-dom"')
       && str_contains($h, 'href="tel:+48660884677"') && str_contains($h, 'href="mailto:' . htmlspecialchars((string) $val('footer.email')) . '"') && preg_match('#kontakt-poz--form" href="[^"]*/kontakt"#', $h) === 1);
    ok('le téléphone aussi en pied de page', preg_match('#<nav class="foot-links"[^>]*>.*href="tel:\+48660884677">' . preg_quote(htmlspecialchars((string) $val('footer.phone')), '#') . '</a>#s', $h) === 1);
    $hK = (string) $get('/kontakt?lang=pl&t=' . microtime(true));
    ok('la page Kontakt donne le téléphone et l\'e-mail au-dessus du formulaire', preg_match('#<div class="kontakt-dane kontakt-dane--strona">.*href="tel:\+48660884677".*href="mailto:[^"]+".*</div>.*<form method="post"#s', $hK) === 1);
    ok('les logos en pied de page, au-dessus des liens', str_contains($h, '<div class="foot-metody">') && strpos($h, 'foot-metody') < strpos($h, 'class="foot-links"'));
    ok('le pied de page n\'est plus en police mono', !preg_match('#<footer class="site-foot">.*class="[^"]*\bmono\b#s', $h));
    // Une fiche complète, sur un produit de la base.
    $p = $pdo->query("SELECT p.id, p.slug, p.category_id, c.name AS cat FROM wsm_products p JOIN wsm_categories c ON c.id = p.category_id
                       WHERE p.shop_visible = 1 AND p.active = 1 AND p.slug <> '' AND c.active = 1 ORDER BY p.sort_order LIMIT 1")->fetch();
    $avantN = $pdo->query("SELECT nutrition FROM wsm_products WHERE id = " . $pdo->quote($p['id']))->fetchColumn();
    $avantD = $val("product.{$p['id']}.desc");
    $pdo->prepare("UPDATE wsm_products SET nutrition = ? WHERE id = ?")->execute([wsm_nutrition_encode(['energy_kj' => '2475', 'energy_kcal' => '598', 'fat' => '54', 'saturates' => '32,5']), $p['id']]);
    $up = fn(string $k, string $v) => $pdo->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES ('pl', ?, ?)")->execute([$k, $v]);
    $pdo->prepare("DELETE FROM wsm_shop_i18n WHERE lang = 'pl' AND (k = ? OR k LIKE ?)")->execute(["product.{$p['id']}.desc", "product.{$p['id']}.prop.%"]);
    $up("product.{$p['id']}.desc", "Pierwsze zdanie.\nDruga linia <script>x</script>.\n\nZastosowanie:\n- polewy\n- **pralinki**");
    $up("product.{$p['id']}.prop.1.k", 'Opakowanie'); $up("product.{$p['id']}.prop.1.v", "Worek $sfx");
    $hp = (string) $get('/p/' . rawurlencode($p['slug']) . '?lang=pl&t=' . microtime(true));
    ok('la fiche : le rayon dans le fil d\'Ariane, un lien vers son onglet', preg_match('#<nav class="crumbs".*href="[^"]*\?k=' . (int) $p['category_id'] . '\#katalog">' . preg_quote(htmlspecialchars($p['cat'])) . '</a>#s', $hp) === 1);
    ok('… la référence et le rayon sous le bouton', str_contains($hp, '<dl class="buy-meta">') && str_contains($hp, '?k=' . (int) $p['category_id']));
    ok('… comment recevoir et comment payer, sous le bouton', str_contains($hp, 'class="metody metody--karta"'));
    ok('… la description en paragraphes et en liste, le HTML en texte', str_contains($hp, '<p>Pierwsze zdanie.<br>Druga linia &lt;script&gt;x&lt;/script&gt;.</p><p>Zastosowanie:</p><ul><li>polewy</li><li><strong>pralinki</strong></li></ul>'));
    ok('… les propriétés dans leur tableau', str_contains($hp, "<dt>Opakowanie</dt><dd>Worek $sfx</dd>"));
    ok('… les valeurs nutritives pour 100 g, « dont » en retrait', str_contains($hp, '<dd>2475 kJ / 598 kcal</dd>') && preg_match('#<div class="pod"><dt>[^<]+</dt><dd>32,5 g</dd>#', $hp) === 1);
    $pdo->prepare("UPDATE wsm_products SET nutrition = ? WHERE id = ?")->execute([$avantN === false ? null : $avantN, $p['id']]);
    $pdo->prepare("DELETE FROM wsm_shop_i18n WHERE lang = 'pl' AND (k = ? OR k LIKE ?)")->execute(["product.{$p['id']}.desc", "product.{$p['id']}.prop.%"]);
    if ($avantD !== null) $up("product.{$p['id']}.desc", $avantD);
    $hk = (string) $get('/?lang=pl&k=' . (int) $p['category_id'] . '&t=' . microtime(true));
    ok('un onglet choisi donne son nom au titre du catalogue', str_contains($hk, '<h2>' . htmlspecialchars($p['cat']) . '</h2>'));
    // Un logo déposé qui porte déjà le nom : « 0 » retire le texte, l'image prend le nom en alt.
    $pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'logo_nazwa'")->execute();
    $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES ('logo_nazwa', '0', 0, ?, 'test')")->execute([date('Y-m-d H:i:s')]);
    $h0 = (string) $get('/?lang=pl&t=' . microtime(true));
    $pdo->prepare("DELETE FROM wsm_settings WHERE cle = 'logo_nazwa'")->execute();
    ok('« nazwa obok logo = 0 » : le logo seul, le nom dans son alt', !str_contains($h0, 'head-nazwa') && str_contains($h0, 'alt="' . htmlspecialchars($brand) . '" height="44"'));
}

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
