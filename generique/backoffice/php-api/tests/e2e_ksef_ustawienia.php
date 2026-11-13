<?php
// ============================================================================
//  e2e_ksef_ustawienia.php — KSeF se règle depuis la console, pas par SSH.
//
//  CE QUI MANQUAIT. tpay, InPost, DPD, la poste et la facturation ont leurs
//  champs dans l'écran Ustawienia. Le registre national, non : ses trois
//  réglages ne vivaient que dans des variables d'environnement. « Fermé tant
//  qu'on n'a pas les identifiants » est la bonne règle — mais encore faut-il
//  pouvoir les poser, et il fallait un accès au serveur pour ça.
//
//  LE CAS DIFFICILE EST LA CLÉ PUBLIQUE. La configuration la désigne par un
//  CHEMIN, pas par une valeur : la poser demandait de déposer un fichier sur
//  la machine. Ici on colle son contenu, le serveur l'écrit sous data/ — hors
//  rsync, donc il survit aux déploiements, et refusé par le .htaccess de
//  l'API — puis range le chemin dans le réglage. L'écran suffit.
//
//  Usage :  php tests/e2e_ksef_ustawienia.php
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
require_once dirname(__DIR__) . '/invoice.php';
require_once dirname(__DIR__) . '/ksef.php';

echo "webshop_mrszoko — end-to-end KSeF w Ustawieniach\n\n";

$pdo = wsm_bootstrap();
$avant = (int) $pdo->query("SELECT COUNT(*) FROM wsm_settings WHERE cle LIKE 'ksef.%'")->fetchColumn();
$pdo->exec("DELETE FROM wsm_settings WHERE cle LIKE 'ksef.%'");
wsm_config_overlay([]); wsm_settings_apply($pdo);

// ---- 1. Les trois réglages sont à l'écran ---------------------------------
echo "-- trzy ustawienia sa na ekranie --\n";
$champs = wsm_settings_fields();
foreach (['ksef.token' => 'secret', 'ksef.public_key' => 'pem', 'ksef.env' => 'select:test|demo|prod'] as $k => $t) {
    ok("$k : present, type $t", isset($champs[$k]) && $champs[$k][4] === $t, $champs[$k][4] ?? null);
    ok("$k : range dans le groupe ksef", ($champs[$k][0] ?? '') === 'ksef');
}
// LE NIP N'EST PAS RÉPÉTÉ : deux champs pour un même numéro finiraient par ne
// plus dire la même chose, et c'est le registre national qui trancherait.
ok('le NIP n a PAS de champ ksef a lui', !isset($champs['ksef.nip']));

// ---- 2. Fermé tant qu'il manque quelque chose -----------------------------
echo "\n-- zamkniety, dopoki czegos brakuje --\n";
ok('canal ferme au depart', wsm_ksef_enabled() === false);
ok('et il DIT ce qui manque (2 points)', count(wsm_ksef_manquants()) === 2, wsm_ksef_manquants());

// ---- 3. La clé collée : ce qui est refusé, et pourquoi --------------------
//
// Un refus muet laisserait croire la clé posée ; la session KSeF échouerait
// bien plus tard, sur une facture réelle, sans rapport visible avec ce geste.
echo "\n-- odmowa mowi dlaczego --\n";
foreach ([
    'du texte quelconque'        => 'ceci nest pas une cle',
    'un debut sans fin'          => "-----BEGIN PUBLIC KEY-----\nMIIB",
    'une enveloppe vide de sens' => "-----BEGIN PUBLIC KEY-----\nPAS-DU-BASE64\n-----END PUBLIC KEY-----",
] as $quoi => $mauvais) {
    $r = [];
    wsm_settings_save($pdo, ['ksef__public_key' => $mauvais], 'test', $r);
    ok("refuse : $quoi", isset($r['ksef.public_key']) && $r['ksef.public_key'] !== '', $r);
}
ok('un refus ne laisse RIEN en base',
   (int) $pdo->query("SELECT COUNT(*) FROM wsm_settings WHERE cle = 'ksef.public_key'")->fetchColumn() === 0);
ok('... et le canal reste ferme', wsm_ksef_enabled() === false);

// ---- 4. Une vraie clé ouvre le canal --------------------------------------
//
// Fabriquée par OpenSSL, pas bricolée : une chaîne écrite à la main ne
// testerait que l'expression régulière.
echo "\n-- prawdziwy klucz otwiera kanal --\n";
$k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$pub = openssl_pkey_get_details($k)['key'];
$r = [];
$ch = wsm_settings_save($pdo, ['ksef__public_key' => $pub, 'ksef__token' => 'TOKEN-E2E-123456'], 'test', $r);
ok('la vraie cle passe', $r === [], $r);
ok('deux reglages ecrits', count($ch) === 2, $ch);
wsm_config_overlay([]); wsm_settings_apply($pdo);
ok('canal OUVERT', wsm_ksef_enabled() === true);
ok('plus rien ne manque', wsm_ksef_manquants() === [], wsm_ksef_manquants());

$cfg = wsm_ksef_cfg();
ok('le reglage porte un CHEMIN, pas la cle',
   str_ends_with($cfg['public_key'], '.pem') && !str_contains($cfg['public_key'], 'BEGIN'));
ok('le fichier existe', is_file($cfg['public_key']));
// Lisible par le serveur web et personne d'autre : c'est un fichier de
// configuration, pas une ressource publique du site.
ok('... en 0640', substr(sprintf('%o', fileperms($cfg['public_key'])), -4) === '0640',
   substr(sprintf('%o', fileperms($cfg['public_key'])), -4));
ok('... sous data/, donc hors rsync et refuse par le .htaccess',
   str_contains($cfg['public_key'], '/data/'));
ok('son contenu EST la cle collee', trim((string) file_get_contents($cfg['public_key'])) === trim($pub));
ok('le NIP est herite de la facturation',
   $cfg['nip'] === wsm_ksef_nip((string) (wsm_invoice_cfg()['seller_nip'] ?? '')), $cfg['nip']);

// ---- 5. Champ vide = on ne touche à rien ----------------------------------
//
// Sinon il faudrait recoller la clé à chaque enregistrement de l'écran — et
// le jour où on ne la recolle pas, KSeF se refermerait sans prévenir.
echo "\n-- puste pole niczego nie rusza --\n";
$r = [];
wsm_settings_save($pdo, ['ksef__public_key' => '', 'ksef__env' => 'demo'], 'test', $r);
wsm_config_overlay([]); wsm_settings_apply($pdo);
ok('la cle est toujours la', wsm_ksef_enabled() === true);
ok('et le reste du formulaire s enregistre', wsm_ksef_cfg()['env'] === 'demo', wsm_ksef_cfg()['env']);
// L'environnement est un choix FERMÉ. Une valeur de travers ne doit jamais
// retomber sur « prod » : déposer pour de vrai en se croyant en bac à sable
// est le seul sens de l'erreur qu'on ne peut pas rattraper.
//
// Testé sur wsm_ksef_cfg(), là où le repli vit. Passer par l'écran ne le
// montrerait pas : wsm_settings_apply() n'applique qu'UNE fois par requête —
// en production chaque requête repart de la base, mais ici tout est dans le
// même processus, et la deuxième écriture ne redescendrait pas.
foreach (['produkcja', 'produkcyjne', '', 'xxxx', 'nimportequoi'] as $tordu) {
    wsm_config_overlay(['ksef' => ['env' => $tordu]]);
    $e = wsm_ksef_cfg()['env'];
    ok("env « $tordu » retombe sur test, jamais sur prod", $e === 'test', $e);
}
// Et les trois valeurs légitimes passent, y compris en majuscules.
foreach (['test' => 'test', 'demo' => 'demo', 'prod' => 'prod', 'PROD' => 'prod', ' prod ' => 'prod'] as $saisi => $attendu) {
    wsm_config_overlay(['ksef' => ['env' => $saisi]]);
    ok("env « $saisi » vaut $attendu", wsm_ksef_cfg()['env'] === $attendu, wsm_ksef_cfg()['env']);
}

// ---- 6. Le paragon ne va PAS au registre ----------------------------------
//
// Il n'existe pas pour le fisc en tant que facture : l'y déposer inscrirait
// un document qui n'a pas lieu d'être. C'est un refus VOULU, pas une panne.
echo "\n-- paragon nie idzie do rejestru --\n";
$sq = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$doc = ['kind' => 'faktura', 'number' => 'FV/1', 'ksef_number' => '', 'issued_at' => '2026-08-18',
        'seller_nip' => '8971902620', 'seller_name' => 'Mister Szoko sp. z o.o.',
        'seller_address' => 'ul. Polna 1, 00-002 Wroclaw, PL',
        'buyer_name' => 'Kowalski', 'buyer_address' => 'ul. Kwiatowa 7, 00-001 Warszawa, PL',
        'total_gross' => 20000, 'currency' => 'PLN',
        'items' => [['name' => 'Czekolada', 'qty' => 1, 'unit_net' => 16260, 'unit_gross' => 20000,
                     'vat_rate' => 0.23, 'line_net' => 16260, 'line_vat' => 3740, 'line_gross' => 20000]]];
ok('une facture propre ne rencontre aucun blocage', wsm_ksef_blockers($sq, $doc) === []);
ok('un paragon est refuse', count(wsm_ksef_blockers($sq, ['kind' => 'paragon'] + $doc)) === 1);
ok('une proforma aussi', count(wsm_ksef_blockers($sq, ['kind' => 'proforma'] + $doc)) === 1);
ok('un document deja depose n y retourne pas', wsm_ksef_blockers($sq, ['ksef_number' => 'X-1'] + $doc) !== []);

// ---- Ménage : l'environnement de développement repart comme il était ------
@unlink($cfg['public_key']);
$pdo->exec("DELETE FROM wsm_settings WHERE cle LIKE 'ksef.%'");
echo "\n(remis en etat : $avant reglage(s) ksef au depart, 0 laisse)\n";

// ---- Le champ image : vide ne veut pas dire « efface » ---------------------
//
// C'est la même règle que pour la photo d'un produit, et le même piège : là,
// le formulaire rendait le champ vide et chaque « Zapisz » effaçait l'image.
// Ici l'écran poste un <input type="file"> qui reste vide tant qu'on ne
// choisit rien — enregistrer les réglages ne doit pas décrocher la photo du
// haut de page.
echo "\n-- pole obrazu: puste to nie znaczy skasuj --\n";
$champs = wsm_settings_fields();
ok('le champ image existe', ($champs['hero_image'][4] ?? '') === 'image', $champs['hero_image'][4] ?? null);
ok('… et il vise la configuration de la vitrine',
   ($champs['hero_image'][2] ?? []) === ['shop', 'hero_image'], $champs['hero_image'][2] ?? null);

$pdo->prepare("DELETE FROM wsm_settings WHERE cle = ?")->execute(['hero_image']);
$pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,0,?,?)")
    ->execute(['hero_image', 'media/aaaaaaaaaaaaaaaaaaaaaaaa.webp', date('Y-m-d H:i:s'), 'test']);

// Enregistrement SANS fichier ni case « supprimer » : l'image doit rester.
//
// On repasse la valeur EXISTANTE d'un autre champ : il faut bien poster
// quelque chose pour que la boucle tourne, mais un test ne doit rien laisser
// derrière lui. Écrit en dur, « https://example.test » restait en base et
// faisait échouer une autre suite, qui vérifie l'adresse de la boutique.
$r = [];
$urlAvant = (string) ($pdo->query("SELECT val FROM wsm_settings WHERE cle = 'shop_url'")->fetchColumn() ?: '');
wsm_settings_save($pdo, ['shop_url' => $urlAvant], 'test', $r);
$reste = (string) $pdo->query("SELECT val FROM wsm_settings WHERE cle = 'hero_image'")->fetchColumn();
ok('un enregistrement sans fichier laisse l\'image en place', $reste === 'media/aaaaaaaaaaaaaaaaaaaaaaaa.webp', $reste);

// La case « supprimer », elle, est un geste explicite.
wsm_settings_save($pdo, ['hero_image__usun' => '1'], 'test', $r);
$apres = (string) $pdo->query("SELECT val FROM wsm_settings WHERE cle = 'hero_image'")->fetchColumn();
ok('la case « Usuń » retire l\'image', wsm_setting_blank($apres), $apres);
$pdo->prepare("DELETE FROM wsm_settings WHERE cle = ?")->execute(['hero_image']);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
