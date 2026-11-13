<?php
// ============================================================================
//  e2e_formularze.php — les formulaires publics (/f/<adresse>).
//
//   1. les modèles : créés INACTIFS, adresse libre la plus proche ;
//   2. la construction : champ e-mail obligatoire, listes avec options,
//      identifiants réservés renommés, doublons suffixés ;
//   3. l'envoi : piège, jeton horodaté, plafond par IP, champs requis, NIP
//      vérifié, liste fermée, accord exigé ; la réponse arrive en base ET
//      dans Poczta, l'équipe est prévenue, la newsletter passe par la double
//      confirmation ;
//   4. la sortie : CSV pour Excel (BOM, « ; »), formules neutralisées,
//      statut, effacement ;
//   5. la boutique en HTTP : inactif = 404, actif = page, envoi réel, plan du site.
//
//  Usage :  php tests/e2e_formularze.php [http://localhost:8091]
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/mail.php';
require_once dirname(__DIR__) . '/formularze.php';
require_once dirname(__DIR__) . '/marketing.php';
$pdo = wsm_bootstrap();
wsm_forms_ensure($pdo);
wsm_mail_transport(fn(array $m) => [true, '']);
$SHOP = $argv[1] ?? getenv('WSM_SHOP_URL') ?: 'http://localhost:8091';

echo "webshop_mrszoko — end-to-end formularze (hurt, próbki, warsztaty, prezenty)\n\n";

$sfx = bin2hex(random_bytes(3));
$forms = []; $ips = [];
register_shutdown_function(function () use ($pdo, &$forms, &$ips, $sfx) {
    try {
        foreach ($forms as $id) wsm_form_delete($pdo, $id);
        foreach ($ips as $ip) $pdo->prepare("DELETE FROM wsm_messages WHERE actor = ?")->execute(['formularz|' . $ip]);
        $pdo->prepare("DELETE FROM wsm_messages WHERE body LIKE ?")->execute(['%' . $sfx . '%']);
        $pdo->prepare("DELETE FROM wsm_subscribers WHERE email LIKE ?")->execute(['%' . $sfx . '%']);
    } catch (Throwable $e) { echo "  (nettoyage: " . $e->getMessage() . ")\n"; }
});
$stamp = fn() => wsm_contact_stamp(time() - 10);       // un humain met plus de 3 s à remplir
$ip = function (string $v) use (&$ips): void { $_SERVER['REMOTE_ADDR'] = $v; $ips[] = $v; };

// ---- 1. Les modèles ---------------------------------------------------------------------------------
echo "-- szablony --\n";
$t = wsm_form_szablony();
ok('quatre modèles chocolatier : hurt, próbki, warsztaty, prezenty', array_keys($t) === ['hurt', 'probki', 'warsztaty', 'prezenty']);
// Le modèle « hurt » existe peut-être déjà en dev : on compte sur la règle, pas sur l'adresse exacte.
[$fid, $e] = wsm_form_from_szablon($pdo, 'hurt');
$forms[] = $fid;
$f = wsm_form_get($pdo, $fid);
ok('créé depuis le modèle, INACTIF (relu avant publication)', $fid > 0 && !$e && (int) $f['aktywny'] === 0, $e);
[$fid2] = wsm_form_from_szablon($pdo, 'hurt');
$forms[] = $fid2;
$f2 = wsm_form_get($pdo, $fid2);
ok('deuxième « hurt » : adresse libre suivante', $f2['slug'] !== $f['slug'] && str_starts_with($f2['slug'], 'hurt-'), [$f['slug'], $f2['slug']]);
ok('le modèle hurt demande firme, NIP, e-mail, activité', (bool) array_filter($f['pola_list'], fn($p) => $p['typ'] === 'nip') && (bool) array_filter($f['pola_list'], fn($p) => $p['id'] === 'rodzaj' && $p['typ'] === 'select'));
ok('modèle inconnu refusé', wsm_form_from_szablon($pdo, 'nope')[0] === 0);

// ---- 2. La construction -----------------------------------------------------------------------------
echo "\n-- budowa --\n";
[$pola, $e] = wsm_form_pola_clean([['etykieta' => 'Imię', 'typ' => 'text'], ['etykieta' => 'Ulubiona', 'typ' => 'select', 'opcje' => '']]);
ok('sans champ e-mail : refusé', (bool) array_filter($e, fn($x) => str_contains($x, 'E-mail')));
ok('liste sans options : refusée', (bool) array_filter($e, fn($x) => str_contains($x, 'opcji')));
[$pola, $e] = wsm_form_pola_clean([['etykieta' => 'E-mail', 'typ' => 'email'], ['etykieta' => 'Zgoda', 'id' => 'zgoda', 'typ' => 'text'],
    ['etykieta' => 'Smak', 'typ' => 'select', 'opcje' => 'gorzka | mleczna|biała|gorzka'], ['etykieta' => 'Smak', 'typ' => 'text'], ['etykieta' => '', 'typ' => 'text'],
    ['etykieta' => 'Typ dziwny', 'typ' => 'script']]);
$ids = array_column($pola, 'id');
ok('identifiant réservé (zgoda) renommé, doublon suffixé, ligne vide retirée', !in_array('zgoda', $ids, true) && in_array('smak_2', $ids, true) && count($pola) === 5, $ids);
ok('options nettoyées, sans doublon', ($pola[2]['opcje'] ?? []) === ['gorzka', 'mleczna', 'biała'], $pola[2]['opcje'] ?? null);
ok('type inconnu ramené à « text »', $pola[4]['typ'] === 'text');
[, $e] = wsm_form_save($pdo, $fid2, ['nazwa' => 'X', 'slug' => $f['slug'], 'pola' => $f['pola_list']]);
ok('adresse déjà prise : refusée', (bool) array_filter($e, fn($x) => str_contains($x, 'zajęty')), $e);
[, $e] = wsm_form_save($pdo, $fid, ['nazwa' => $f['nazwa'], 'slug' => $f['slug'], 'wstep' => $f['wstep'], 'pola' => $f['pola_list'],
    'podziekowanie' => 'Dziękujemy ' . $sfx, 'powiadom' => 'zespol.' . $sfx . '@example.com', 'newsletter' => 1, 'aktywny' => 1]);
$f = wsm_form_get($pdo, $fid);
ok('enregistré et ACTIVÉ', !$e && (int) $f['aktywny'] === 1, $e);
ok('slug normalisé (ą→a, espaces→-)', wsm_form_slug('Próbki Czekolady Ąę!') === 'probki-czekolady-ae');

// ---- 3. L'envoi ---------------------------------------------------------------------------------------
echo "\n-- wysyłka --\n";
$tx = ['zgoda' => 'Wyrażam zgodę… (test ' . $sfx . ')', 'nl' => 'Chcę newsletter (test)'];
$bon = ['_ts' => $stamp(), 'zgoda' => '1', 'p' => ['firma' => 'Cukiernia ' . $sfx, 'nip' => '123-456-32-18', 'imie' => 'Ewa Nowak',
        'email' => 'Ewa.' . $sfx . '@Example.com', 'telefon' => '+48 600 100 200', 'rodzaj' => 'Cukiernia', 'ilosc' => '20–100 kg', 'wiadomosc' => '=HYPERLINK("x") ' . $sfx]];
$ip('203.0.113.' . random_int(20, 200));
ok('piège rempli : refus silencieux', isset(wsm_form_submit($pdo, $f, $bon + ['firma_www' => 'spam'], $tx)[1]['_bot']));
ok('jeton absent ou forgé : refus silencieux', isset(wsm_form_submit($pdo, $f, array_merge($bon, ['_ts' => '123.abc']), $tx)[1]['_bot']));
ok('envoyé trop vite (< 3 s) : refus silencieux', isset(wsm_form_submit($pdo, $f, array_merge($bon, ['_ts' => wsm_contact_stamp()]), $tx)[1]['_bot']));
$mal = $bon; $mal['p']['nip'] = '1234563219'; $mal['p']['email'] = 'pas-une-adresse'; $mal['p']['rodzaj'] = 'Inventé'; $mal['p']['firma'] = ''; unset($mal['zgoda']);
[$x, $e] = wsm_form_submit($pdo, $f, $mal, $tx);
ok('NIP faux, e-mail faux, liste hors choix, champ requis vide, pas d\'accord : tout est dit', $x === null && isset($e['nip'], $e['email'], $e['rodzaj'], $e['firma'], $e['zgoda']), array_keys($e));
[$eid, $e] = wsm_form_submit($pdo, $f, $bon + ['zgoda_nl' => '1'], $tx, 'pl');
ok('envoi valide enregistré', $eid > 0 && !$e, $e);
$row = $pdo->query("SELECT * FROM wsm_form_entries WHERE id = " . (int) $eid)->fetch();
$d = json_decode((string) $row['dane'], true);
ok('NIP gardé en chiffres, e-mail en minuscules', $d['nip'] === '1234563218' && $row['email'] === 'ewa.' . $sfx . '@example.com', [$d['nip'], $row['email']]);
ok('preuve de l\'accord : le texte exact affiché, recomposé par le serveur', $row['zgoda_tekst'] === $tx['zgoda']);
$msg = $pdo->prepare("SELECT * FROM wsm_messages WHERE direction = 'wejscie' AND body LIKE ? ORDER BY id DESC");
$msg->execute(['%Cukiernia ' . $sfx . '%']);
$m = $msg->fetch();
$msg->closeCursor();   // SQLite : un curseur ouvert garde un verrou de lecture et bloque la boutique
ok('la réponse arrive dans Poczta (message reçu)', $m && str_contains((string) $m['subject'], 'Cukiernia ' . $sfx) && str_contains((string) $m['body'], 'NIP: 1234563218'));
$notif = $pdo->prepare("SELECT COUNT(*) FROM wsm_messages WHERE direction = 'wyjscie' AND email = ?");
$notif->execute(['zespol.' . $sfx . '@example.com']);
ok('l\'équipe est prévenue à l\'adresse réglée', (int) $notif->fetchColumn() === 1);
$notif->closeCursor();
$sub = wsm_mkt_sub_by_email($pdo, 'ewa.' . $sfx . '@example.com');
ok('case newsletter : inscription EN ATTENTE de confirmation (double opt-in), source « f:… »', $sub && str_starts_with((string) $sub['source'], 'f:')
   && strlen((string) $sub['source']) <= 20 && (string) $sub['email_status'] === 'oczekuje', $sub);
$b2 = $bon; $b2['p']['email'] = 'drugi.' . $sfx . '@example.com';
wsm_form_submit($pdo, $f, $b2, $tx);
ok('sans la case : aucune inscription', wsm_mkt_sub_by_email($pdo, 'drugi.' . $sfx . '@example.com') === null);
// Le plafond par IP (partagé avec le formulaire de contact).
$n = 0;
for ($i = 0; $i < 8; $i++) { $b = $bon; $b['p']['email'] = "seria$i.$sfx@example.com"; [, $e] = wsm_form_submit($pdo, $f, $b, $tx); if (isset($e['_limit'])) { $n = $i; break; } }
ok('plafond par IP atteint : refus dit à l\'écran', $n > 0, $n);
$ip('203.0.113.' . random_int(201, 250));
$gros = $bon; $gros['p']['wiadomosc'] = str_repeat('🍫', 5000); $gros['p']['firma'] = str_repeat('Ж', 300);
$f20 = $f; $f20['pola_list'] = array_merge($f['pola_list'], array_map(fn($i) => ['id' => 'extra' . $i, 'typ' => 'textarea', 'etykieta' => 'X' . $i, 'wymagane' => false, 'opcje' => []], range(1, 8)));
foreach (range(1, 8) as $i) $gros['p']['extra' . $i] = str_repeat('🍫', 4999);
ok('réponse énorme (robot) : refusée avant la base, pas de 500', isset(wsm_form_submit($pdo, $f20, $gros, $tx)[1]['_limit']));
[, $e] = wsm_form_save($pdo, $fid2, ['nazwa' => 'X', 'slug' => $f2['slug'], 'pola' => $f2['pola_list'], 'powiadom' => str_repeat('a', 190) . '@example.com']);
ok('adresse de notification > 200 signes : refusée', (bool) array_filter($e, fn($x) => str_contains($x, '200')), $e);

// ---- 4. La sortie ------------------------------------------------------------------------------------
echo "\n-- eksport i obsługa --\n";
$wpisy = wsm_form_entries($pdo, $fid);
$csv = wsm_form_csv($f, $wpisy);
ok('CSV : BOM UTF-8 et séparateur « ; »', str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, '";"'));
ok('formule neutralisée (=HYPERLINK ne s\'exécute pas dans Excel)', str_contains($csv, "\"'=HYPERLINK(\"\"x\"\")") && !str_contains($csv, '";"=HYPERLINK'));
ok('changer le statut + notatka', wsm_form_entry_status($pdo, $eid, 'w_toku', 'Oddzwonić w piątek') && $pdo->query("SELECT status FROM wsm_form_entries WHERE id = " . (int) $eid)->fetchColumn() === 'w_toku');
ok('statut inconnu refusé', !wsm_form_entry_status($pdo, $eid, 'hacked'));
ok('liste : compteurs nouveaux / total', (bool) array_filter(wsm_forms_list($pdo), fn($x) => (int) $x['id'] === $fid && (int) $x['razem'] >= 2));
ok('effacement RODO', wsm_form_entry_delete($pdo, $eid) && !$pdo->query("SELECT COUNT(*) FROM wsm_form_entries WHERE id = " . (int) $eid)->fetchColumn());

// ---- 5. La boutique en HTTP ----------------------------------------------------------------------------
echo "\n-- sklep (HTTP) --\n";
$jar = tempnam(sys_get_temp_dir(), 'wsmf');
$req = function (string $method, string $url, array $post = []) use ($jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
                            CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($post) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $hl = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($raw, 0, $hl), substr($raw, $hl)];
};
[$c0] = $req('GET', $SHOP . '/');
if ($c0 === 0) { echo "  (sklep nieosiągalny — część HTTP pominięta)\n"; }
else {
    [$ci] = $req('GET', $SHOP . '/f/' . $f2['slug']);
    ok('formulaire inactif : 404', $ci === 404, $ci);
    [$ca, , $ha] = $req('GET', $SHOP . '/f/' . $f['slug']);
    ok('formulaire actif : 200, titre, champs, NIP, accord, newsletter', $ca === 200 && str_contains($ha, '<h1>' . htmlspecialchars($f['nazwa'])) && str_contains($ha, 'name="p[nip]"')
       && str_contains($ha, 'name="zgoda"') && str_contains($ha, 'name="zgoda_nl"'), $ca);
    ok('piège et jeton horodaté présents', str_contains($ha, 'name="firma_www"') && preg_match('/name="_ts" value="\d+\.[a-f0-9]{24}"/', $ha) === 1);
    ok('page indexable (page d\'entrée B2B)', str_contains($ha, 'index, follow'));
    preg_match('/name="_t" value="([^"]+)"/', $ha, $mc);
    preg_match('/name="_ts" value="([^"]+)"/', $ha, $mt);
    sleep(4);
    $post = ['_t' => $mc[1] ?? '', '_ts' => $mt[1] ?? '', 'zgoda' => '1', 'p' => ['firma' => 'HTTP ' . $sfx, 'nip' => '1234563218', 'imie' => 'Jan Test',
             'email' => 'http.' . $sfx . '@example.com', 'rodzaj' => 'Piekarnia']];
    $ip('127.0.0.1');
    [$cp, $hp] = $req('POST', $SHOP . '/f/' . $f['slug'], $post);
    ok('envoi réel : redirection vers ?ok=1', in_array($cp, [302, 303], true) && str_contains($hp, 'ok=1'), [$cp, $mc[1] ?? null]);
    $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_form_entries WHERE form_id = ? AND email = ?");
    $st->execute([$fid, 'http.' . $sfx . '@example.com']);
    ok('… et la réponse est en base', (int) $st->fetchColumn() === 1);
    $st->closeCursor();
    [, , $hk] = $req('GET', $SHOP . '/f/' . $f['slug'] . '?ok=1');
    ok('page de remerciement : le texte réglé', str_contains($hk, 'Dziękujemy ' . $sfx));
    [, , $sm] = $req('GET', $SHOP . '/sitemap.xml');
    ok('plan du site : le formulaire actif y est, l\'inactif non', str_contains($sm, '/f/' . $f['slug'] . '<') && !str_contains($sm, '/f/' . $f2['slug'] . '<'));
}
@unlink($jar);

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILED") . " — $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
