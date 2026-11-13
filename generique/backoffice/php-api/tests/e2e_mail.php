<?php
// ============================================================================
//  e2e_mail.php — preuve que la promesse faite au client est tenue.
//
//  Une commande qui dépasse le stock passe quand même : c'est la règle
//  commerciale. Elle ne vaut que si l'acheteur est prévenu. Ce qu'on démontre
//  ici, dans l'ordre d'importance :
//
//    1. une commande hors stock déclenche le message « on vous recontacte » ;
//    2. ce message n'est envoyé QU'UNE FOIS, même si l'événement se répète ;
//    3. si le serveur de mail est muet, rien n'est perdu : le message reste
//       en file, avec l'erreur, et se renvoie ;
//    4. un secret enregistré n'est jamais réaffiché, et « xxxx » ne configure
//       rien — l'intégration reste éteinte plutôt qu'à moitié branchée.
//
//  Le transport est injecté : on prouve la logique sans envoyer un seul e-mail.
//
//  Usage :  php tests/e2e_mail.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/mail.php';
require_once dirname(__DIR__) . '/settings.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end poczta (szablony · automaty · ustawienia)\n\n";

// ---- 1. Les modèles sont des données ---------------------------------------
echo "-- szablony --\n";
$tpls = wsm_mail_templates($pdo);
ok('modèles semés', count($tpls) >= 12, count($tpls));

$langs = array_unique(array_map(fn($t) => $t['lang'], $tpls));
sort($langs);
ok('trois langues livrées', $langs === ['en', 'pl', 'uk'], $langs);

foreach (array_keys(WSM_MAIL_EVENTS) as $ev) {
    $t = wsm_mail_template_for_event($pdo, $ev, 'pl');
    if (!$t) { ok("un modèle actif pour l'événement $ev", false); break; }
}
ok('chaque événement a son modèle actif',
    (bool) array_reduce(array_keys(WSM_MAIL_EVENTS),
        fn($a, $ev) => $a && wsm_mail_template_for_event($pdo, $ev, 'pl') !== null, true));

$uk = wsm_mail_template($pdo, 'zamowienie', 'uk');
ok('modèle ukrainien distinct du polonais',
    $uk && $uk['subject'] !== wsm_mail_template($pdo, 'zamowienie', 'pl')['subject']);
$zz = wsm_mail_template($pdo, 'zamowienie', 'zz');
ok('langue inconnue retombe sur le polonais', $zz && $zz['lang'] === 'pl', $zz['lang'] ?? null);

// ---- 2. Rendu des variables ------------------------------------------------
echo "\n-- zmienne --\n";
$vars = ['imie' => 'Anna', 'numer' => 'MS-000123', 'kwota' => '598,05 zł'];
ok('variable remplacée', wsm_mail_render('Witaj {{imie}}', $vars) === 'Witaj Anna');
ok('espaces tolérés dans la variable', wsm_mail_render('{{ numer }}', $vars) === 'MS-000123');
ok('casse tolérée', wsm_mail_render('{{IMIE}}', $vars) === 'Anna');
ok('variable inconnue effacée, jamais affichée au client',
    wsm_mail_render('a{{nieznana}}b', $vars) === 'ab');
ok('texte sans variable inchangé', wsm_mail_render('Dzień dobry', $vars) === 'Dzień dobry');

// ---- 3. Une vraie commande hors stock --------------------------------------
echo "\n-- zamówienie ponad stan magazynu --\n";

// Produit à 2 en stock ; on en commande 5. La commande doit passer, et le
// client doit recevoir le message qui l'annonce.
$pid = 'test-mail-' . bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible, slug,
                                         stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku)
               VALUES (?, (SELECT id FROM wsm_categories LIMIT 1), ?, ?, 'Opublikowany', 1, 1, ?, 2, 0.23, 200, 120, 80, 40, ?)")
    ->execute([$pid, 'Test poczty', 49.90, $pid, strtoupper($pid)]);

// La poste est déclarée prête ; le transport, lui, est un carnet, pas un réseau.
wsm_config_overlay(['mail' => ['from' => 'sklep@misterszoko.com', 'from_name' => 'Mister Szoko', 'transport' => 'mail']]);
$sent = [];
wsm_mail_transport(function (array $m) use (&$sent) { $sent[] = $m; return [true, '']; });

$body = [
    'lang' => 'pl', 'delivery_method' => 'inpost_locker', 'inpost_point' => 'WRO01A',
    'items' => [['id' => $pid, 'qty' => 5]],
    'client_type' => 'osoba', 'email' => 'klient.test@example.com', 'phone' => '600100200',
    'first_name' => 'Anna', 'last_name' => 'Nowak', 'consent_terms' => 1,
    'ship_street' => 'Leszczyńskiego', 'ship_building' => '4', 'ship_postcode' => '50-078',
    'ship_city' => 'Wrocław', 'ship_country' => 'PL',
];
[$order, $errs] = wsm_shop_create_order($pdo, $body);
ok('la commande passe malgré le stock insuffisant', $order !== null, $errs);

if ($order) {
    ok('la commande est marquée à confirmer', !empty($order['backorder']));

    $msgs = wsm_messages_list($pdo, ['order_id' => (int) $order['id']]);
    $codes = array_column($msgs, 'template_code');
    ok('accusé de réception envoyé', in_array('zamowienie', $codes, true), $codes);
    ok('message « on vous recontacte » envoyé', in_array('na_zamowienie', $codes, true), $codes);

    $back = null;
    foreach ($msgs as $m) if ($m['template_code'] === 'na_zamowienie') $back = $m;
    ok('adressé au bon client', ($back['email'] ?? '') === 'klient.test@example.com');
    ok('le numéro de commande est dans le sujet', str_contains((string) ($back['subject'] ?? ''), (string) $order['code']),
        $back['subject'] ?? null);
    ok('le corps nomme ce qui manque', str_contains((string) ($back['body'] ?? ''), 'do wykonania'),
        $back['body'] ?? null);
    ok('aucune variable non résolue ne part au client',
        !str_contains((string) ($back['body'] ?? ''), '{{'));
    ok('marqué comme envoyé', ($back['status'] ?? '') === 'wyslana', $back['status'] ?? null);
    ok('le transport a bien été appelé', count($sent) >= 2, count($sent));

    // 2. Idempotence : le même événement ne repart pas.
    $before = count(wsm_messages_list($pdo, ['order_id' => (int) $order['id']]));
    wsm_mail_auto($pdo, 'na_zamowienie', $order);
    wsm_mail_auto($pdo, 'zamowienie', $order);
    $after = count(wsm_messages_list($pdo, ['order_id' => (int) $order['id']]));
    ok('un événement répété n\'écrit pas deux fois au client', $before === $after, [$before, $after]);

    // 3. Le paiement déclenche son message, une seule fois lui aussi.
    wsm_order_mark_paid($pdo, (int) $order['id'], 'test');
    $codes = array_column(wsm_messages_list($pdo, ['order_id' => (int) $order['id']]), 'template_code');
    ok('le paiement est accusé', in_array('platnosc', $codes, true), $codes);
    wsm_order_mark_paid($pdo, (int) $order['id'], 'test');
    $codes2 = array_column(wsm_messages_list($pdo, ['order_id' => (int) $order['id']]), 'template_code');
    ok('un paiement déjà encaissé ne renvoie rien', count($codes) === count($codes2));

    // Trace visible dans l'historique de la commande.
    $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_order_events WHERE order_id = ? AND event = 'wiadomosc'");
    $st->execute([(int) $order['id']]);
    ok('les envois sont inscrits dans l\'historique de la commande', (int) $st->fetchColumn() >= 2);
}

// ---- 4. Le serveur de mail est muet ----------------------------------------
echo "\n-- serwer poczty milczy --\n";
wsm_mail_transport(fn(array $m) => [false, 'SMTP: połączenie odrzucone']);
$id = wsm_mail_queue($pdo, [
    'email' => 'klient.test@example.com', 'direction' => 'wyjscie',
    'subject' => 'Test', 'body' => 'Treść', 'actor' => 'test',
]);
ok('message écrit avant l\'envoi', $id > 0);
[$sentOk, $err] = wsm_mail_send($pdo, $id);
ok('l\'échec est signalé', $sentOk === false && $err !== '');
$m = wsm_message_by_id($pdo, $id);
ok('le message n\'est pas perdu', $m !== null);
ok('son état dit « błąd »', ($m['status'] ?? '') === 'blad', $m['status'] ?? null);
ok('l\'erreur est lisible dans la console', str_contains((string) ($m['error'] ?? ''), 'SMTP'), $m['error'] ?? null);

wsm_mail_transport(fn(array $m2) => [true, '']);
[$sentOk] = wsm_mail_send($pdo, $id);
$m = wsm_message_by_id($pdo, $id);
ok('renvoyé une fois le service revenu', $sentOk === true && ($m['status'] ?? '') === 'wyslana');
ok('l\'heure d\'envoi est enregistrée', !empty($m['sent_at']));

$bad = wsm_mail_queue($pdo, ['email' => 'pas-une-adresse', 'direction' => 'wyjscie',
                             'subject' => 'x', 'body' => 'y', 'actor' => 'test']);
[$sentOk, $err] = wsm_mail_send($pdo, $bad);
ok('une adresse invalide n\'est pas remise au transport', $sentOk === false, $err);
ok('et le message est marqué en erreur', (wsm_message_by_id($pdo, $bad)['status'] ?? '') === 'blad');

// Une commande ne peut pas échouer à cause de la messagerie.
wsm_mail_transport(function (array $m) { throw new RuntimeException('transport en feu'); });
$boom = wsm_mail_auto($pdo, 'zamowienie', ['id' => 0, 'email' => 'x@example.com', 'lang' => 'pl', 'items' => []]);
ok('un transport qui explose ne remonte pas jusqu\'à la commande', is_int($boom));
wsm_mail_transport(fn(array $m) => [true, '']);

// ---- 5. Réglages : « xxxx » ne configure rien ------------------------------
echo "\n-- ustawienia (xxxx) --\n";
ok('« xxxx » est vide', wsm_setting_blank('xxxx'));
ok('« XXXXXX » aussi', wsm_setting_blank('XXXXXX'));
ok('une chaîne vide aussi', wsm_setting_blank('   '));
ok('un vrai identifiant ne l\'est pas', !wsm_setting_blank('9f3a-77c1'));
ok('un identifiant contenant des x ne l\'est pas', !wsm_setting_blank('tpay-xx-9931'));

// Table et surcouche remises à zéro : ce test écrit de vrais réglages, il ne
// doit pas hériter de son exécution précédente.
$pdo->exec("DELETE FROM wsm_settings WHERE cle LIKE 'stripe.%'");
wsm_config_overlay(['stripe' => ['publishable_key' => '', 'secret_key' => '']]);

$view = wsm_settings_view($pdo);
ok('les champs Stripe, InPost et poczta sont proposés',
    isset($view['stripe.publishable_key'], $view['inpost.token'], $view['mail.from']));
ok('un champ vide s\'affiche en xxxx', $view['stripe.publishable_key']['show'] === 'xxxx', $view['stripe.publishable_key']['show']);
ok('un secret est marqué comme tel', $view['stripe.secret_key']['type'] === 'secret');

// Enregistrement d'un secret : il ne doit plus jamais ressortir.
wsm_settings_save($pdo, ['stripe__secret_key' => 'super-secret-123', 'stripe__publishable_key' => 'ID-999'], 'test');
$row = $pdo->query("SELECT val FROM wsm_settings WHERE cle = 'stripe.secret_key'")->fetchColumn();
ok('le secret est bien enregistré', $row === 'super-secret-123');
wsm_config_overlay(['stripe' => ['secret_key' => 'super-secret-123', 'publishable_key' => 'ID-999']]);
$view = wsm_settings_view($pdo);
ok('mais il n\'est jamais réaffiché', !str_contains(json_encode($view), 'super-secret-123'));
ok('l\'écran dit seulement qu\'il est posé', $view['stripe.secret_key']['set'] === true);
ok('un champ non secret reste lisible', $view['stripe.publishable_key']['show'] === 'ID-999', $view['stripe.publishable_key']['show']);

// Un champ secret laissé sur son masque ne l'efface pas.
wsm_settings_save($pdo, ['stripe__secret_key' => '••••••••••••'], 'test');
$row = $pdo->query("SELECT val FROM wsm_settings WHERE cle = 'stripe.secret_key'")->fetchColumn();
ok('laisser le masque ne détruit pas le secret', $row === 'super-secret-123');
wsm_settings_save($pdo, ['stripe__secret_key' => ''], 'test');
$row = $pdo->query("SELECT val FROM wsm_settings WHERE cle = 'stripe.secret_key'")->fetchColumn();
ok('un champ vide non plus', $row === 'super-secret-123');

// Le journal d'audit ne reçoit que des clés.
$changed = wsm_settings_save($pdo, ['stripe__secret_key' => 'autre-secret'], 'test');
ok('l\'enregistrement ne renvoie que des clés', $changed === ['stripe.secret_key'], $changed);

// ---- 6. Poczta fail-closed --------------------------------------------------
echo "\n-- poczta bez konfiguracji --\n";
wsm_config_overlay(['mail' => ['from' => '', 'transport' => 'mail']]);
ok('sans adresse d\'expéditeur, la poste est éteinte', wsm_mail_enabled() === false);
ok('et l\'écran dit ce qui manque', in_array('adres nadawcy', wsm_mail_blockers(), true), wsm_mail_blockers());
wsm_config_overlay(['mail' => ['from' => 'sklep@misterszoko.com', 'transport' => 'smtp', 'smtp_host' => '']]);
ok('SMTP sans serveur reste éteint', wsm_mail_enabled() === false);
ok('et le dit', in_array('serwer SMTP', wsm_mail_blockers(), true), wsm_mail_blockers());
wsm_config_overlay(['mail' => ['transport' => 'smtp', 'smtp_host' => 'smtp.example.com']]);
ok('avec expéditeur et serveur, la poste est prête', wsm_mail_enabled() === true);
wsm_config_overlay(['mail' => ['transport' => 'mail']]);
ok('mail() suffit quand un expéditeur est posé', wsm_mail_enabled() === true);

$c = wsm_mail_cfg();
ok('port par défaut cohérent avec TLS', $c['smtp_port'] === 587, $c['smtp_port']);
wsm_config_overlay(['mail' => ['smtp_secure' => 'ssl']]);
ok('port par défaut cohérent avec SSL', wsm_mail_cfg()['smtp_port'] === 465, wsm_mail_cfg()['smtp_port']);

ok('un sujet accentué est encodé', str_starts_with(wsm_mail_encode('Zamówienie'), '=?UTF-8?B?'));
ok('un sujet ASCII ne l\'est pas', wsm_mail_encode('Order MS-1') === 'Order MS-1');

// ---- Changements d'état : le client est prévenu, une fois ---------------------
//  C'est un envoi déclenché par un clic dans la console : les deux dangers sont
//  d'écrire deux fois au client, et de bloquer un changement d'état parce que
//  personne n'a écrit le modèle correspondant.
echo "\n-- powiadomienia o zmianie statusu --\n";
require_once dirname(__DIR__) . '/seed.php';
wsm_seed_mail_templates_topup($pdo);          // les modèles d'état arrivent sur une base existante

foreach (['w_realizacji', 'wyslane', 'dostarczone', 'anulowane'] as $ev) {
    ok("un modèle existe pour « $ev »", wsm_mail_template_for_event($pdo, $ev, 'pl') !== null);
    ok("« $ev » figure dans les événements de la console", isset(WSM_MAIL_EVENTS[$ev]));
}

$pdo->prepare("DELETE FROM wsm_messages WHERE event_key LIKE ?")->execute(['status:' . (int) $order['id'] . ':%']);
$s1 = wsm_mail_for_status($pdo, $order, 'wyslane', 'test');
ok('le message de mise en expédition part', $s1 > 0, $s1);
$s2 = wsm_mail_for_status($pdo, $order, 'wyslane', 'test');
ok('repasser par le même état ne réécrit pas au client', $s2 === 0, $s2);

$s3 = wsm_mail_for_status($pdo, $order, 'dostarczone', 'test');
ok('un autre état, un autre message', $s3 > 0 && $s3 !== $s1, [$s1, $s3]);

$m = $pdo->query("SELECT * FROM wsm_messages WHERE id = " . (int) $s1)->fetch();
ok('le sujet porte le numéro de commande', str_contains((string) $m['subject'], (string) $order['code']),
    $m['subject'] ?? null);
ok('aucun {{jeton}} non remplacé', !str_contains((string) $m['body'], '{{'), $m['body'] ?? null);
ok('le message est rattaché à la commande', (int) $m['order_id'] === (int) $order['id']);

// Les états internes ne dérangent personne : « nowe » et « oplacone » ne sont
// pas des nouvelles pour le client, qui vient justement de commander.
ok('« nowe » n\'écrit pas au client', wsm_mail_for_status($pdo, $order, 'nowe', 'test') === 0);
ok('« oplacone » non plus (la confirmation de paiement a son propre modèle)',
    wsm_mail_for_status($pdo, $order, 'oplacone', 'test') === 0);
ok('un état inventé n\'écrit rien', wsm_mail_for_status($pdo, $order, 'cos-nowego', 'test') === 0);

// Une commande sans adresse valable ne doit pas faire échouer le changement.
$sansMail = $order; $sansMail['email'] = 'nie-adres';
ok('une adresse invalide n\'envoie rien et ne casse rien',
    wsm_mail_for_status($pdo, $sansMail, 'anulowane', 'test') === 0);

// Le numéro de suivi doit atteindre le modèle « wysłane ».
$avecSuivi = $order; $avecSuivi['shipment'] = ['tracking_number' => '6200012345678'];
$vars = wsm_mail_vars($avecSuivi);
ok('le numéro de suivi est une variable de modèle', ($vars['sledzenie'] ?? '') === '6200012345678', $vars['sledzenie'] ?? null);
ok('sans expédition, il est vide plutôt qu\'absent', isset(wsm_mail_vars($order)['sledzenie']));

// Une ligne réduite à un libellé vide est retirée, pas laissée en suspens.
$modele = "Dzień dobry,\n\nNumer przesyłki: {{sledzenie}}\nSposób dostawy: {{dostawa}}\n\nMister Szoko";
$plein = wsm_mail_render($modele, $vars);
ok('avec un numéro, la ligne de suivi est là', str_contains($plein, '6200012345678'));
$vide = wsm_mail_render($modele, wsm_mail_vars($order));
ok('sans numéro, la ligne de suivi disparaît', !str_contains($vide, 'Numer przesyłki'), $vide);
ok('mais les lignes renseignées restent', str_contains($vide, 'Sposób dostawy'), $vide);
ok('et le corps du message est intact', str_contains($vide, 'Mister Szoko') && str_contains($vide, 'Dzień dobry'));
ok('une ligne sans variable n\'est jamais touchée',
    str_contains(wsm_mail_render("Uwaga:\n{{nieznane}}", []), 'Uwaga:'));

// ---- Nettoyage --------------------------------------------------------------
$pdo->prepare("DELETE FROM wsm_products WHERE id = ?")->execute([$pid]);
$pdo->exec("DELETE FROM wsm_settings WHERE cle LIKE 'stripe.%'");
wsm_config_overlay(['stripe' => ['publishable_key' => '', 'secret_key' => '']]);

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
