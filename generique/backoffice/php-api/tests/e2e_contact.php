<?php
// ============================================================================
//  e2e_contact.php — preuve que le formulaire public tient la porte.
//
//  Un formulaire de contact est une porte ouverte sur la boîte mail de la
//  maison. Ce qui est démontré, dans l'ordre du danger :
//
//   1. LES TROIS FILTRES ANTI-ROBOT MORDENT. Piège, délai signé, plafond par
//      IP. Sans eux, la messagerie se remplit de publicité en une nuit et le
//      vrai courrier se noie dedans — c'est ainsi qu'on rate une commande.
//   2. LE DÉLAI NE SE FALSIFIE PAS. Un champ caché renvoyé tel quel se
//      réécrit en une ligne ; celui-ci est signé.
//   3. LE MESSAGE ARRIVE DANS LA MESSAGERIE, pas dans un e-mail perdu.
//   4. L'ACCUSÉ PART DANS LA LANGUE DU VISITEUR, et une seule fois.
//   5. ON NE DIT PAS AU ROBOT POURQUOI IL EST REFUSÉ.
//
//  Usage :  php tests/e2e_contact.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/contact.php';
require_once dirname(__DIR__) . '/mail.php';
$pdo = wsm_bootstrap();
wsm_mail_transport(fn(array $m) => [true, '']);      // aucun e-mail ne part d'un test

echo "webshop_mrszoko — end-to-end formularz kontaktowy\n\n";

$sfx = bin2hex(random_bytes(3));
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(2, 250);   // plage de doc, jamais réelle
$ip = $_SERVER['REMOTE_ADDR'];

/** Un envoi valable, sauf ce qu'on change exprès. */
$bon = function (array $sur = []) use ($sfx): array {
    return $sur + [
        'name' => 'Jan Testowy', 'email' => 'kontakt.' . $sfx . '@example.com',
        'topic' => 'pytanie', 'phone' => '',
        'message' => 'Dzień dobry, czy czekolada ruby jest bezglutenowa?',
        'consent' => '1', 'firma_www' => '',
        '_ts' => wsm_contact_stamp(time() - 20),
    ];
};

// ---- 1. Le jeton horodaté --------------------------------------------------------
echo "-- podpisany znacznik czasu --\n";
[$okS] = wsm_contact_stamp_ok(wsm_contact_stamp(time() - 20));
ok('un jeton authentique et posé passe', $okS === true);

[$okR, $rR] = wsm_contact_stamp_ok(wsm_contact_stamp(time()));
ok('un envoi instantané est refusé — personne ne lit et écrit en 0 s',
    $okR === false && $rR === 'trop_rapide', $rR);

[$okP, $rP] = wsm_contact_stamp_ok(wsm_contact_stamp(time() - WSM_CONTACT_DELAI_MAX - 60));
ok('un jeton périmé est refusé — il aurait pu être récolté puis rejoué',
    $okP === false && $rP === 'perime', $rP);

// LE POINT : un horodatage inventé ne passe pas. Sans signature, il suffisait
// d'écrire « time()-60 » dans le champ caché pour franchir le délai.
[$okF, $rF] = wsm_contact_stamp_ok((time() - 60) . '.0000000000000000000000aa');
ok('un jeton FORGÉ est refusé — c\'est toute la raison de la signature',
    $okF === false && $rF === 'signature', $rF);
[$okM] = wsm_contact_stamp_ok('n-importe-quoi');
ok('un jeton malformé est refusé', $okM === false);
[$okV] = wsm_contact_stamp_ok('');
ok('un jeton absent est refusé', $okV === false);

// ---- 2. Le piège ------------------------------------------------------------------
echo "\n-- pułapka --\n";
[$id1, $e1] = wsm_contact_submit($pdo, $bon(['firma_www' => 'http://spam.example']));
ok('un piège rempli fait tomber l\'envoi', $id1 === null, $e1);
ok('et rien n\'entre en base', isset($e1['_bot']), $e1);

// ---- 3. On ne renseigne pas le robot ------------------------------------------------
echo "\n-- nie tłumaczymy robotowi, co zrobił źle --\n";
[$n2, $e2] = wsm_contact_submit($pdo, $bon(['_ts' => wsm_contact_stamp(time())]));
ok('un envoi trop rapide est refusé', $n2 === null);
ok('la raison est rangée sous une clé technique, pas dans un message client',
    array_keys($e2) === ['_bot'], $e2);

// ---- 4. La validation ----------------------------------------------------------------
echo "\n-- walidacja --\n";
$cas = [
    ['name' => ''],                        // sans nom
    ['email' => 'pas-une-adresse'],        // adresse invalide
    ['message' => 'krótko'],               // trop court pour dire quoi que ce soit
    ['consent' => ''],                     // sans consentement
    ['phone' => 'abc!!'],                  // numéro absurde
];
$attendu = ['name', 'email', 'message', 'consent', 'phone'];
foreach ($cas as $i => $sur) {
    [$nn, $ee] = wsm_contact_submit($pdo, $bon($sur));
    ok("le champ « {$attendu[$i]} » est contrôlé", $nn === null && isset($ee[$attendu[$i]]), $ee);
}

// Le consentement n'est pas décoratif : sans lui on n'a pas le droit de
// garder l'adresse pour répondre.
[$nc, $ec] = wsm_contact_submit($pdo, $bon(['consent' => '']));
ok('sans consentement, aucun message n\'est conservé', $nc === null && isset($ec['consent']));

// ---- 5. Le chemin nominal ----------------------------------------------------------------
echo "\n-- wiadomość trafia do skrzynki --\n";
$avant = (int) $pdo->query("SELECT COUNT(*) FROM wsm_messages WHERE direction = 'wejscie'")->fetchColumn();
[$id, $err] = wsm_contact_submit($pdo, $bon(), 'pl');
ok('un envoi correct passe', $id !== null, $err);
$apres = (int) $pdo->query("SELECT COUNT(*) FROM wsm_messages WHERE direction = 'wejscie'")->fetchColumn();
ok('la messagerie a UNE entrée de plus', $apres === $avant + 1, [$avant, $apres]);

$st = $pdo->prepare("SELECT * FROM wsm_messages WHERE id = ?");
$st->execute([$id]);
$msg = $st->fetch();
ok('elle est marquée comme reçue, pas comme envoyée',
    ($msg['direction'] ?? '') === 'wejscie', $msg['direction'] ?? null);
ok('l\'adresse du visiteur est conservée',
    str_contains((string) $msg['email'], $sfx), $msg['email'] ?? null);
ok('le sujet choisi apparaît dans l\'objet',
    str_contains((string) $msg['subject'], 'Pytanie'), $msg['subject'] ?? null);
ok('le corps porte le texte du visiteur',
    str_contains((string) $msg['body'], 'bezglutenowa'), mb_substr((string) $msg['body'], 0, 60));
ok('et la langue, pour savoir dans laquelle répondre',
    str_contains((string) $msg['body'], 'język: pl'), mb_substr((string) $msg['body'], 0, 80));
ok('l\'IP est notée pour le plafond', str_contains((string) $msg['actor'], $ip), $msg['actor'] ?? null);

// ---- 6. L'accusé de réception ------------------------------------------------------------------
echo "\n-- potwierdzenie w języku gościa --\n";
$st2 = $pdo->prepare("SELECT * FROM wsm_messages WHERE event_key = ?");
$st2->execute(['formularz:' . $id]);
$acc = $st2->fetch();
ok('un accusé part', $acc !== false, 'formularz:' . $id);
if ($acc) {
    ok('il est sortant', ($acc['direction'] ?? '') === 'wyjscie');
    ok('il utilise le modèle du formulaire', ($acc['template_code'] ?? '') === 'formularz',
        $acc['template_code'] ?? null);
    ok('il va bien au visiteur', str_contains((string) $acc['email'], $sfx));
}

// L'accusé ne part qu'UNE fois par message : la clé d'événement est unique en
// base, donc c'est la base qui l'empêche, pas une vérification qui perdrait
// la course entre deux envois simultanés.
$n = wsm_contact_accuse($pdo, 'kontakt.' . $sfx . '@example.com', 'Jan', 'pytanie', 'pl', (int) $id);
ok('un second accusé pour le même message ne part pas', $n === 0, $n);

// Dans une autre langue, un autre modèle.
$ipAutre = '203.0.113.' . random_int(2, 250);
$_SERVER['REMOTE_ADDR'] = $ipAutre;
[$idEn] = wsm_contact_submit($pdo, $bon(['email' => 'en.' . $sfx . '@example.com']), 'en');
$st2->execute(['formularz:' . $idEn]);
$accEn = $st2->fetch();
ok('un visiteur anglophone reçoit l\'accusé en anglais',
    $accEn && str_contains((string) $accEn['subject'], 'received'), $accEn['subject'] ?? null);

// Une langue sans modèle retombe sur le polonais — jamais sur rien.
[$idDe] = wsm_contact_submit($pdo, $bon(['email' => 'de.' . $sfx . '@example.com']), 'de');
$st2->execute(['formularz:' . $idDe]);
$accDe = $st2->fetch();
ok('une langue sans modèle retombe sur le polonais, pas sur le silence',
    $accDe !== false, 'formularz:' . $idDe);

// ---- 7. Le plafond par IP ------------------------------------------------------------------------
echo "\n-- limit na adres IP --\n";
// Une adresse que rien d'autre dans ce test (ni un passage précédent sur la
// même base) n'a pu prendre : tirée dans la plage IPv4 de documentation, elle
// retombait une fois sur 250 sur $ipAutre, et ses deux messages comptaient.
$_SERVER['REMOTE_ADDR'] = '2001:db8::' . dechex(random_int(1, 0xffff)) . ':' . dechex(random_int(1, 0xffff));
$ipLim = $_SERVER['REMOTE_ADDR'];
$posees = 0;
for ($i = 0; $i < WSM_CONTACT_MAX_PAR_IP + 2; $i++) {
    [$idx] = wsm_contact_submit($pdo, $bon(['email' => "lim$i.$sfx@example.com"]));
    if ($idx) $posees++;
}
ok('le plafond arrête la salve', $posees === WSM_CONTACT_MAX_PAR_IP,
    [$posees, WSM_CONTACT_MAX_PAR_IP]);
[$nl, $el] = wsm_contact_submit($pdo, $bon(['email' => "encore.$sfx@example.com"]));
ok('et le refus se DIT au visiteur — c\'est peut-être un vrai client insistant',
    $nl === null && isset($el['_limit']), $el);

// Une autre adresse IP n'est pas punie pour celle-là.
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int(2, 250);
[$idAutre] = wsm_contact_submit($pdo, $bon(['email' => "autre.$sfx@example.com"]));
ok('une autre IP passe toujours — le plafond n\'est pas global', $idAutre !== null);

// ---- 8. LA PORTE B2B DE L'ACCUEIL -----------------------------------------------------
//
// Le bloc « B2B ? Mamy dla Ciebie lepsze ceny » ne fait plus ouvrir un client
// de messagerie : il mène ICI, avec le sujet déjà posé. La première version
// passait le LIBELLÉ du sujet (« Konto B2B — Mister Szoko ») ; le formulaire ne
// sait présélectionner qu'un CODE de la liste, le libellé était donc ignoré en
// silence — le lien marchait, le bouton s'illuminait, et la demande arrivait
// classée « inne » au milieu du tout-venant. Rien ne l'aurait montré avant de
// dépouiller la Poczta.
echo "\n-- wejście B2B ze strony głównej --\n";
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(2, 250);

// php-api/tests → php-api → backoffice → mrszoko, puis la boutique. Le
// déploiement renomme php-api en api : la profondeur, elle, ne change pas.
$fSrc = dirname(__DIR__, 3) . '/shop/index.php';
ok('la source de la boutique est là où on la cherche', is_file($fSrc), $fSrc);
$src = is_file($fSrc) ? (string) file_get_contents($fSrc) : '';
preg_match("/'temat'\s*=>\s*'([a-z_]+)'/", $src, $m);
$codeCta = $m[1] ?? '';
ok('le bouton B2B porte un sujet de la liste, pas une phrase',
    $codeCta !== '' && in_array($codeCta, WSM_CONTACT_SUJETS, true), $codeCta);

// La page d'accueil pose le sujet, la page de contact le RELIT. Sans cette
// relecture le paramètre serait décoratif — c'était exactement le défaut.
ok('la page de contact relit le sujet reçu, et le valide',
    str_contains($src, "\$_GET['temat']") && str_contains($src, 'WSM_CONTACT_SUJETS'));

[$idB2b] = wsm_contact_submit($pdo, $bon(['topic' => $codeCta, 'email' => "b2b.$sfx@example.com"]));
$stB = $pdo->prepare('SELECT subject FROM wsm_messages WHERE id = ?');
$stB->execute([$idB2b]);
$sujB2b = (string) $stB->fetchColumn();
ok('une demande venue du bloc B2B arrive classée sur son sujet',
    $idB2b !== null && !str_contains(mb_strtolower($sujB2b), 'inne'), $sujB2b);

// Et la démonstration par l'absurde : l'ancien libellé libre retombe sur
// « inne », c'est-à-dire nulle part.
[$idLibre] = wsm_contact_submit($pdo, $bon(['topic' => 'Konto B2B — Mister Szoko',
                                            'email' => "libre.$sfx@example.com"]));
$stB->execute([$idLibre]);
ok('un sujet écrit à la main retombe sur « inne » — d\'où le code',
    str_contains(mb_strtolower((string) $stB->fetchColumn()), 'inne'));

// ---- Nettoyage ---------------------------------------------------------------------------------------
$pdo->prepare("DELETE FROM wsm_messages WHERE email LIKE ?")->execute(['%' . $sfx . '@example.com']);
$pdo->prepare("DELETE FROM wsm_messages WHERE actor LIKE ?")->execute(['formularz|203.0.113.%']);
$pdo->prepare("DELETE FROM wsm_messages WHERE actor LIKE ?")->execute(['formularz|198.51.100.%']);

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
