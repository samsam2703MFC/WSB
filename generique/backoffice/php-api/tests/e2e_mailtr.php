<?php
// ============================================================================
//  e2e_mailtr.php — preuve que traduire le courrier ne détruit rien.
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. L'ORIGINAL NE BOUGE PAS. Ce que le client a écrit est la pièce. Une
//      traduction qui remplacerait le corps du message ferait disparaître la
//      seule version qui fasse foi — et une machine se trompe.
//   2. LA DÉTECTION NE COÛTE RIEN ET NE MENT PAS. Un alphabet et huit mots
//      outils reconnaissent le courrier réel sans appeler personne ; quand
//      deux langues se disputent le texte, la fonction le DIT au lieu de
//      trancher au hasard.
//   3. ON NE PAIE UNE TRADUCTION QU'UNE FOIS. La base porte l'unicité, donc
//      deux clics simultanés ne font pas deux appels facturés.
//   4. SANS CLÉ, RIEN — et proprement.
//
//  Aucun appel réseau : la clé est délibérément absente, et ce qui est testé
//  est tout ce qui l'entoure. Un test qui appellerait l'API serait lent, cher
//  et non déterministe.
//
//  Usage :  php tests/e2e_mailtr.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/translate.php';
require_once dirname(__DIR__) . '/mail.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end tłumaczenie poczty\n\n";

// ---- 1. La détection -------------------------------------------------------------
echo "-- wykrywanie języka --\n";
$echantillons = [
    'pl' => "Dzień dobry, czy czekolada ruby jest dostępna? Proszę o informację.",
    'uk' => "Доброго дня! Дякую за замовлення, чи можна змінити адресу?",
    'en' => "Hello, could you please tell me if the ruby chocolate is in stock?",
    'de' => "Guten Tag, ich möchte eine Bestellung aufgeben. Vielen Dank für Ihre Mühe.",
    'fr' => "Bonjour, je voudrais commander trois kilos de chocolat noir. Cordialement.",
    'cs' => "Dobrý den, děkuji za objednávku, prosím o potvrzení termínu.",
    'hu' => "Jó napot kívánok, köszönöm a rendelést, kérem a visszaigazolást.",
];
foreach ($echantillons as $attendu => $texte) {
    [$vu, $conf] = wsm_tr_detect($texte);
    ok("« " . mb_substr($texte, 0, 22) . "… » → $attendu", $vu === $attendu, [$vu, $conf]);
}

// Le slovaque et le tchèque se ressemblent beaucoup : on vérifie au moins que
// la détection ne les prend pas pour du polonais, ce qui ferait répondre dans
// la mauvaise langue à un client bien réel.
[$vuSk] = wsm_tr_detect("Dobrý deň, ďakujem za objednávku, prosím o potvrdenie.");
ok('le slovaque n\'est pas pris pour du polonais', $vuSk !== 'pl', $vuSk);

// LA RÉGRESSION EXACTE QUI A ÉTÉ TROUVÉE ICI : « köszönöm » porte trois « ö »,
// et l'allemand gagnait sur un texte hongrois qui ne contient pas un mot
// d'allemand. « ä ö ü » ne sont pas allemands — le hongrois, le turc, le
// finnois et le suédois les emploient aussi. Seul « ß » l'est.
[$vuHu] = wsm_tr_detect('köszönöm');
ok('« köszönöm » seul n\'est pas de l\'allemand — trois ö ne font pas une langue',
    $vuHu !== 'de', $vuHu);
[$vuDe] = wsm_tr_detect('Grüße aus Berlin, vielen Dank');
ok('mais « ß » reste un signe allemand sûr', $vuDe === 'de', $vuDe);

// L'AMBIGUÏTÉ SE DÉCLARE. Un texte trop court ou sans indice ne doit pas
// produire une certitude : c'est ce qui ferait écrire en allemand à quelqu'un
// qui a juste tapé « ok ».
[$c1, $conf1] = wsm_tr_detect('ok');
ok('un texte sans indice retombe sur le polonais', $c1 === 'pl', $c1);
ok('et il annonce une confiance nulle', $conf1 === 0.0, $conf1);
[$c2, $conf2] = wsm_tr_detect('');
ok('un texte vide ne fait pas tomber la détection', $c2 === 'pl' && $conf2 === 0.0);

// Un texte franchement écrit dans une langue doit, lui, être sûr.
[, $confPl] = wsm_tr_detect($echantillons['pl']);
ok('un texte franc porte une confiance élevée', $confPl > 0.3, $confPl);

// Le nom natif sert au sélecteur du visiteur ; les phrases polonaises de la
// console, elles, demandent une forme déclinée. « napisaną po Українська » ne
// se dit pas, et coller un nom nominatif au milieu d'une phrase se voit.
echo "\n-- odmiana nazw języków --\n";
ok('la forme adverbiale existe', wsm_lang_po('uk') === 'po ukraińsku', wsm_lang_po('uk'));
ok('et l\'accusatif aussi', wsm_lang_na('de') === 'na niemiecki', wsm_lang_na('de'));
ok('les huit langues sont déclinées',
    count(array_filter(array_keys(WSM_LANGS), fn($c) => isset(WSM_LANG_PL[$c]))) === 8,
    array_keys(WSM_LANG_PL));
ok('une langue inconnue ne casse pas la phrase', wsm_lang_po('zz') !== '', wsm_lang_po('zz'));

// ---- 2. La langue dans laquelle répondre ---------------------------------------------
echo "\n-- w jakim języku odpisać --\n";
$sfx = bin2hex(random_bytes(3));
$mailUk = "klient.uk.$sfx@example.com";
$idIn = wsm_mail_queue($pdo, [
    'email' => $mailUk, 'direction' => 'wejscie',
    'subject' => 'Питання про замовлення',
    'body' => "Доброго дня! Дякую за замовлення, чи можна змінити адресу доставки?",
    'actor' => 'test',
]);
ok('le message de test est enregistré', $idIn > 0);
ok('la langue du client se déduit de son message',
    wsm_tr_lang_client($pdo, $mailUk) === 'uk', wsm_tr_lang_client($pdo, $mailUk));

// Une commande passée sur la boutique porte une langue CHOISIE par le client :
// elle doit primer sur une détection, qui n'est qu'une supposition.
$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO wsm_orders (code, access_token, email, lang, status, payment_status,
                    items_net, items_gross, shipping_net, shipping_gross, total_net, total_gross,
                    delivery_method, created_at)
               VALUES (?,?,?,?,'nowe','oczekuje',0,0,0,0,0,0,'inpost_locker',?)")
     ->execute(['MS-TR-' . strtoupper($sfx), bin2hex(random_bytes(8)), $mailUk, 'en', date('Y-m-d H:i:s')]);
ok('la langue CHOISIE sur la boutique prime sur la langue devinée',
    wsm_tr_lang_client($pdo, $mailUk) === 'en', wsm_tr_lang_client($pdo, $mailUk));

ok('une adresse inconnue retombe sur le polonais',
    wsm_tr_lang_client($pdo, "personne.$sfx@example.com") === 'pl');
ok('une adresse vide aussi', wsm_tr_lang_client($pdo, '') === 'pl');

// ---- 3. Sans clé, rien — et proprement -------------------------------------------------
echo "\n-- bez klucza nic, ale czysto --\n";
wsm_config_overlay(['anthropic_api_key' => '']);
ok('la traduction se déclare indisponible', wsm_tr_enabled() === false);

[$t, $e] = wsm_tr_text('Dzień dobry', 'pl', 'en');
ok('traduire un texte échoue proprement', $t === null && $e !== null, [$t, $e]);

[$m, $em] = wsm_tr_message($pdo, $idIn, 'pl', 'test');
ok('traduire un message échoue proprement', $m === null && $em !== null, $em);

// ET SURTOUT : l'échec n'a rien abîmé.
$st = $pdo->prepare("SELECT subject, body FROM wsm_messages WHERE id = ?");
$st->execute([$idIn]);
$apres = $st->fetch();
ok('l\'ORIGINAL est intact après un échec',
    str_contains((string) $apres['body'], 'Доброго дня'), mb_substr((string) $apres['body'], 0, 40));
ok('aucune traduction vide n\'a été rangée', wsm_tr_cached($pdo, $idIn, 'pl') === null);

// ---- 4. Traduire vers sa propre langue ne coûte rien -------------------------------------
echo "\n-- ten sam język: nic do roboty --\n";
wsm_config_overlay(['anthropic_api_key' => 'sk-test-nieprawdziwy']);
[$same, $eSame] = wsm_tr_text('Dzień dobry', 'pl', 'pl');
ok('traduire du polonais vers le polonais renvoie le texte, sans appel réseau',
    $same === 'Dzień dobry' && $eSame === null, [$same, $eSame]);
[$vide, $eVide] = wsm_tr_text('   ', 'pl', 'en');
ok('un texte vide ne déclenche pas d\'appel', $vide === '' && $eVide === null, [$vide, $eVide]);
[$nul, $eNul] = wsm_tr_text('Dzień dobry', 'pl', 'zz');
ok('une langue cible inconnue est refusée', $nul === null && $eNul !== null, $eNul);

// Un message DÉJÀ en polonais se range sans appel : sinon l'écran redemanderait
// la traduction à chaque ouverture.
$idPl = wsm_mail_queue($pdo, [
    'email' => "pl.$sfx@example.com", 'direction' => 'wejscie',
    'subject' => 'Pytanie o zamówienie',
    'body' => 'Dzień dobry, proszę o informację czy zamówienie zostało wysłane. Dziękuję.',
    'actor' => 'test',
]);
[$trPl, $ePl] = wsm_tr_message($pdo, $idPl, 'pl', 'test');
ok('un message déjà polonais est rangé sans appeler l\'API', $trPl !== null, $ePl);
ok('et son texte est celui d\'origine, mot pour mot',
    ($trPl['body'] ?? '') === 'Dzień dobry, proszę o informację czy zamówienie zostało wysłane. Dziękuję.',
    mb_substr((string) ($trPl['body'] ?? ''), 0, 40));
ok('la langue source détectée est notée', ($trPl['src_lang'] ?? '') === 'pl', $trPl['src_lang'] ?? null);

// ---- 5. On ne paie qu'une fois --------------------------------------------------------------
echo "\n-- płacimy raz --\n";
$avant = (int) $pdo->query("SELECT COUNT(*) FROM wsm_message_tr")->fetchColumn();
wsm_tr_message($pdo, $idPl, 'pl', 'test');       // second appel
wsm_tr_message($pdo, $idPl, 'pl', 'test');       // troisième
$apresN = (int) $pdo->query("SELECT COUNT(*) FROM wsm_message_tr")->fetchColumn();
ok('rejouer la traduction n\'ajoute pas de ligne', $apresN === $avant, [$avant, $apresN]);

// L'unicité est portée par la BASE, pas par une vérification applicative :
// deux requêtes simultanées ne doivent pas passer toutes les deux.
$dup = false;
try {
    $pdo->prepare("INSERT INTO wsm_message_tr (message_id, lang, src_lang, subject, body, actor, created_at)
                   VALUES (?,?,?,?,?,?,?)")
        ->execute([$idPl, 'pl', 'pl', 'x', 'x', 'test', date('Y-m-d H:i:s')]);
} catch (Throwable $e) { $dup = true; }
ok('la base refuse un doublon — c\'est elle le garde-fou, pas le code', $dup === true);

// ---- 6. Le message d'un inconnu ---------------------------------------------------------------
echo "\n-- brzegi --\n";
[$nn, $en] = wsm_tr_message($pdo, 999999999, 'pl', 'test');
ok('traduire un message inexistant est refusé sans exception', $nn === null && $en !== null, $en);

// ---- Nettoyage -----------------------------------------------------------------------------------
$pdo->prepare("DELETE FROM wsm_message_tr WHERE message_id IN (?,?)")->execute([$idIn, $idPl]);
$pdo->prepare("DELETE FROM wsm_messages WHERE id IN (?,?)")->execute([$idIn, $idPl]);
$pdo->prepare("DELETE FROM wsm_orders WHERE code = ?")->execute(['MS-TR-' . strtoupper($sfx)]);
wsm_config_overlay(['anthropic_api_key' => '']);

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
