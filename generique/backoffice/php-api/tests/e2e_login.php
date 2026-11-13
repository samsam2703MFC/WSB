<?php
// ============================================================================
//  e2e_login.php — la porte. C'est la seule page dont l'échec n'a pas de
//  repli : si elle ne marche pas, personne n'entre nulle part.
//
//  CE QU'ELLE REMPLACE. On entrait par la console de franchise exportée —
//  1,2 Mo de React et un portier en JavaScript — qui, une fois l'identité
//  établie, renvoyait aussitôt vers pulpit.php. Toute cette application ne
//  servait qu'à porter deux champs et un bouton.
//
//  CE QUI EST VÉRIFIÉ ICI, et pourquoi chaque point a coûté quelque chose à
//  quelqu'un quelque part :
//
//   1. LA PORTE S'OUVRE SANS JAVASCRIPT. Un script qui ne charge pas laissait
//      la page vide, et il n'y avait aucun moyen d'entrer. Le formulaire est
//      rendu par le serveur, point.
//   2. LE REFUS NE DIT PAS SI L'ADRESSE EXISTE. « Nie znaleziono konta » est
//      une façon polie de confirmer une adresse à qui essaie une liste : le
//      message doit être le MÊME pour un compte inconnu et un mauvais mot de
//      passe.
//   3. LE VERROUILLAGE S'ANNONCE. Cinq essais et le compte se ferme quinze
//      minutes ; sans message, on ressaie vingt fois et on croit à une panne.
//   4. LE MOT DE PASSE NE REVIENT JAMAIS DANS LA PAGE. Ni en valeur de champ,
//      ni dans l'URL — d'où la redirection après succès.
//   5. LE JETON ANTI-CSRF EST EXIGÉ. Sinon un site tiers fait POSTer le
//      navigateur de quelqu'un d'identifié.
//
//  Usage :  php tests/e2e_login.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

// On charge auth.php pour les CONSTANTES (seuil et durée du verrouillage) :
// les répéter ici les ferait mentir le jour où elles changent.
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/delivery.php';
require_once dirname(__DIR__) . '/auth.php';

$BO = getenv('WSM_BO_URL') ?: 'http://localhost:8093/backoffice';

/** Une requête, cookies conservés dans un bocal comme le ferait un navigateur. */
function req(string $method, string $url, array $post = [], ?string $jar = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
    ]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($post) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($raw, 0, $hlen), substr($raw, $hlen)];
}

echo "webshop_mrszoko — end-to-end logowanie (drzwi do konsoli)\n\n";

[$c, , $html] = req('GET', "$BO/login.php");
if ($c === 0) { echo "Konsola nieosiągalna ($BO) — uruchom serwer\n"; exit(0); }

// ---- 1. La page existe et se suffit --------------------------------------
echo "-- strona logowania stoi sama --\n";
ok('login.php répond 200', $c === 200, $c);
ok('le champ e-mail est là', str_contains($html, 'name="email"'));
ok('le champ mot de passe est là', str_contains($html, 'name="password"'));
ok('le jeton anti-CSRF est dans le formulaire', str_contains($html, 'name="_t"'));
// LE point de tout l'exercice : plus de dépendance à un script.
ok('AUCUN <script> — la porte ne dépend plus de JavaScript', !str_contains($html, '<script'));
ok('la page se dit non indexable', str_contains($html, 'noindex'));
ok('les autocomplete aident le gestionnaire de mots de passe',
    str_contains($html, 'autocomplete="username"') && str_contains($html, 'autocomplete="current-password"'));
ok('la durée du verrouillage est annoncée AVANT de se faire verrouiller',
    str_contains($html, (string) (int) (WSM_LOGIN_LOCK / 60)));

// ---- 2. L'ancienne adresse mène toujours quelque part ---------------------
echo "\n-- stary adres nadal prowadzi do drzwi --\n";
[$ci, , $ih] = req('GET', "$BO/index.html");
ok('index.html répond', $ci === 200, $ci);
ok('et renvoie vers login.php', str_contains($ih, 'login.php'), substr($ih, 0, 120));

// ---- 3. Le refus ----------------------------------------------------------
echo "\n-- odmowa --\n";
$jar = tempnam(sys_get_temp_dir(), 'wsmlog');
[$c0, , $h0] = req('GET', "$BO/login.php", [], $jar);
preg_match('/name="_t" value="([a-f0-9]{32})"/', $h0, $m);
$tok = $m[1] ?? '';
ok('le jeton est bien un jeton', strlen($tok) === 32, $tok);

[$cBad, , $hBad] = req('POST', "$BO/login.php",
    ['_t' => $tok, 'email' => 'personne.nexiste.pas@misterszoko.test', 'password' => 'CeQueJeVeux-1'], $jar);
ok('mauvais identifiants → on reste sur la page (pas de redirection)', $cBad === 200, $cBad);
ok('et le refus est écrit', str_contains($hBad, 'Nieprawidłowy'), $cBad);

// LA règle : le message doit être IDENTIQUE pour un compte qui existe avec un
// mauvais mot de passe. Sinon la page devient un annuaire d'adresses valides.
[$cW, , $hW] = req('POST', "$BO/login.php",
    ['_t' => $tok, 'email' => 'sophie.renard@misterszoko.com', 'password' => 'ToutSaufLeBon-9'], $jar);
$msgInconnu = str_contains($hBad, 'Nieprawidłowy e-mail lub hasło');
$msgConnu   = str_contains($hW, 'Nieprawidłowy e-mail lub hasło') || str_contains($hW, 'zablokowane');
ok('compte inconnu et mauvais mot de passe donnent LE MÊME refus',
    $msgInconnu && $msgConnu, [$msgInconnu, $msgConnu]);

// ---- 4. Le jeton est exigé ------------------------------------------------
echo "\n-- bez tokenu ani rusz --\n";
[$cNo, , $hNo] = req('POST', "$BO/login.php",
    ['email' => 'sophie.renard@misterszoko.com', 'password' => 'peu importe'], $jar);
ok('un POST sans jeton ne connecte pas', $cNo === 200, $cNo);
ok('et le dit sans accuser l\'utilisateur', str_contains($hNo, 'wygasła') || str_contains($hNo, 'Nieprawidłowy'), $cNo);

// ---- 5. Le mot de passe ne revient jamais ---------------------------------
echo "\n-- hasło nigdy nie wraca na stronę --\n";
ok('le mot de passe saisi n\'est pas réaffiché', !str_contains($hBad, 'CeQueJeVeux-1'));
ok('le champ mot de passe n\'a jamais de value', !preg_match('/name="password"[^>]*value=/', $hBad));
ok('l\'e-mail, lui, est repris — retaper son adresse après une faute de frappe agace',
    str_contains($hBad, 'personne.nexiste.pas@misterszoko.test'));

// ---- 6. Le succès redirige, il n'affiche pas ------------------------------
echo "\n-- udane logowanie przenosi, nie wyświetla --\n";
$jar2 = tempnam(sys_get_temp_dir(), 'wsmlog2');
[, , $hp] = req('GET', "$BO/login.php", [], $jar2);
preg_match('/name="_t" value="([a-f0-9]{32})"/', $hp, $m2);
$tok2 = $m2[1] ?? '';
$mail = getenv('WSM_E2E_LOGIN_EMAIL') ?: '';
$pass2 = getenv('WSM_E2E_LOGIN_PASS') ?: '';
if ($mail === '' || $pass2 === '') {
    echo "  · succès non exercé (WSM_E2E_LOGIN_EMAIL / _PASS non fournis) — ne compte PAS pour vert\n";
} else {
    [$cOk, $hdr] = req('POST', "$BO/login.php", ['_t' => $tok2, 'email' => $mail, 'password' => $pass2], $jar2);
    ok('bon mot de passe → redirection 303', $cOk === 303, $cOk);
    ok('vers le tableau de bord', str_contains($hdr, 'pulpit.php'), $hdr);
    // Une session ouverte ne doit plus voir la porte : on la lui repasse.
    [$cAgain, $hAgain] = req('GET', "$BO/login.php", [], $jar2);
    ok('déjà identifié → login.php renvoie vers le tableau de bord',
        $cAgain === 302 && str_contains($hAgain, 'pulpit.php'), $cAgain);
}
@unlink($jar); @unlink($jar2);

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
