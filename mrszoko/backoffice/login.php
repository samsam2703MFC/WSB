<?php
// ============================================================================
//  login.php — la porte d'entrée de la console.
//
//  CE QU'ELLE REMPLACE. On entrait par la console de franchise exportée :
//  1,2 Mo de React, six fichiers de script et un portier en JavaScript, pour
//  afficher deux champs et un bouton. Une fois identifié, ce même JavaScript
//  renvoyait aussitôt vers pulpit.php — l'application entière ne servait donc
//  qu'à porter un formulaire de connexion.
//
//  Trois conséquences, et la première n'est pas le poids :
//
//   1. LA PORTE NE DÉPEND PLUS DE JAVASCRIPT. Un script qui ne charge pas,
//      une extension qui bloque, un réseau qui coupe au mauvais moment : la
//      page restait vide et personne ne pouvait entrer. Ici le serveur rend
//      le formulaire, traite le POST et redirige. Sans une ligne de script.
//   2. LE REFUS EST LISIBLE. « bad_credentials » dans une console de
//      navigateur ne dit rien à personne. Un compte verrouillé après cinq
//      essais doit s'annoncer, avec le temps d'attente, sinon on ressaie
//      vingt fois et on croit à une panne.
//   3. RIEN N'A CHANGÉ DANS LES RÈGLES. wsm_login() reste l'unique
//      implémentation : verrouillage, compteur d'échecs, régénération de
//      session, réhachage du mot de passe, journal d'audit. Cette page
//      l'appelle, elle ne la réécrit pas.
//
//  CE QU'ELLE NE FAIT PAS, EXPRÈS : elle ne dit jamais si l'adresse existe.
//  « Nie znaleziono konta » est une façon polie de confirmer une adresse à
//  qui essaie une liste. Le refus est le même dans les deux cas.
// ============================================================================
declare(strict_types=1);

$api = is_dir(__DIR__ . '/api') ? __DIR__ . '/api' : __DIR__ . '/php-api';
require_once $api . '/db.php';
require_once $api . '/delivery.php';        // wsm_audit(), utilisé par auth.php
require_once $api . '/auth.php';

$pdo = wsm_bootstrap();
wsm_session_start();

function lg_h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Déjà identifié : on ne remontre pas la porte à quelqu'un qui est entré.
if (wsm_current_user($pdo)) { header('Location: pulpit.php', true, 302); exit; }

// Le jeton anti-CSRF vit dans un cookie et dans le formulaire : un site tiers
// peut faire POSTer votre navigateur, il ne peut pas LIRE le cookie pour en
// recopier la valeur.
$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$erreur = '';
$email  = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $pass  = (string) ($_POST['password'] ?? '');

    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) {
        // Un jeton absent, c'est le plus souvent un cookie perdu ou un onglet
        // resté ouvert toute la nuit — pas une attaque. On le dit comme tel.
        $erreur = 'Sesja formularza wygasła. Spróbuj jeszcze raz.';
    } elseif ($email === '' || $pass === '') {
        $erreur = 'Podaj adres e-mail i hasło.';
    } else {
        try {
            wsm_login($pdo, $email, $pass);
            // Redirection APRÈS succès : un rafraîchissement ne renvoie pas le
            // mot de passe, et l'historique du navigateur n'en garde pas trace.
            header('Location: pulpit.php', true, 303);
            exit;
        } catch (Throwable $e) {
            $erreur = match ($e->getMessage()) {
                'account_locked' => 'Konto jest tymczasowo zablokowane po '
                    . WSM_LOGIN_MAX_TRIES . ' nieudanych próbach. Spróbuj za '
                    . (int) (WSM_LOGIN_LOCK / 60) . ' minut.',
                // Volontairement le MÊME message pour « adresse inconnue » et
                // « mauvais mot de passe ». Distinguer les deux confirmerait à
                // un inconnu quelles adresses existent chez nous.
                'bad_credentials' => 'Nieprawidłowy e-mail lub hasło.',
                default => 'Logowanie chwilowo niemożliwe. Spróbuj za chwilę.',
            };
        }
    }
}

$titre = 'Logowanie — Konsola ' . wsm_brand_name();
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= lg_h($titre) ?></title>
<?php
// Le logo et les couleurs de la boutique, comme dans la console (console.php) :
// le logo déposé dans Ustawienia, sinon celui du design system.
$lgLogo = trim((string) (wsm_config()['shop']['logo_image'] ?? ''));
$lgLogo = ($lgLogo === '' || preg_match('/^x{2,}$/i', $lgLogo)) ? '../shop/' . wsm_ds_logo_path()
        : (str_starts_with($lgLogo, 'media/') ? '../shop/' . $lgLogo : $lgLogo);
?>
<link rel="icon" href="<?= lg_h($lgLogo) ?>">
<link rel="stylesheet" href="_ds/mister-szoko/global.css">
<link rel="stylesheet" href="_ds/mister-szoko/brand.css">
<link rel="stylesheet" href="../shop/<?= lg_h(wsm_ds_path('colors.css')) ?>">
<style>
  /* La page tient dans son propre style : elle doit s'afficher même si la
     feuille de la console manque, parce que c'est la seule page depuis
     laquelle on ne peut pas se rabattre ailleurs. */
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
         background: #F5F3F0; padding: 24px; -webkit-font-smoothing: antialiased;
         font-family: var(--font-sans, system-ui, sans-serif); color: var(--text-default, #241a14); }
  .box { width: 100%; max-width: 380px; }
  /* Le même habit que la boutique et la console : la marque au centre, une
     carte blanche sans cadre, des champs arrondis, un bouton en pastille. */
  .mark { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 10px; margin-bottom: 26px; }
  .mark img { width: 56px; height: 56px; border-radius: 14px; }
  .mark b { font-size: 24px; font-weight: 800; letter-spacing: -.02em; color: var(--text-strong, #241a14); display: block; line-height: 1.25; }
  .mark span { font-size: 14px; color: var(--text-muted, #7a6a5f); }
  form { background: var(--surface-card, #fff); border: 0;
         box-shadow: 0 1px 2px rgba(46, 22, 12, .04), 0 8px 30px rgba(46, 22, 12, .07);
         border-radius: 20px; padding: 26px; }
  label { display: block; margin-bottom: 14px; }
  label span { display: block; font-size: 12.5px; font-weight: 600; margin-bottom: 6px;
               color: var(--text-strong, #241a14); }
  input { width: 100%; box-sizing: border-box; min-height: 44px; padding: 0 12px; font-size: 15px;
          border: 1px solid #D9CFC3; border-radius: 10px;
          background: var(--surface-page, #fff); color: inherit; font-family: inherit; }
  input:focus { outline: none; border-color: var(--brand, #41281A);
                box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand, #41281A) 16%, transparent); }
  button { width: 100%; min-height: 48px; font-size: 16px; font-weight: 600; cursor: pointer;
           border: 0; border-radius: 999px; margin-top: 6px;
           background: var(--brand, #7a4a2b); color: #fff; font-family: inherit; }
  button:hover { filter: brightness(1.08); }
  .err { border: 1px solid color-mix(in srgb, var(--danger, #b3261e) 45%, transparent);
         color: var(--danger, #b3261e); background: color-mix(in srgb, var(--danger, #b3261e) 7%, transparent);
         border-radius: var(--radius-md, 10px); padding: 11px 13px; font-size: 13.5px;
         margin-bottom: 16px; line-height: 1.5; }
  .foot { margin-top: 18px; font-size: 12.5px; color: var(--text-muted, #7a6a5f); line-height: 1.6; text-align: center; }
  .foot a { color: inherit; }
</style>
</head>
<body>
  <div class="box">
    <div class="mark">
      <img src="<?= lg_h($lgLogo) ?>" alt="">
      <span><b>Konsola <?= lg_h(wsm_brand_name()) ?></b><span>Zaplecze sklepu</span></span>
    </div>

    <?php if ($erreur !== ''): ?>
      <?php // role="alert" : un lecteur d'écran annonce le refus au lieu de
            // laisser quelqu'un attendre devant un formulaire qui n'a pas
            // l'air d'avoir bougé. ?>
      <p class="err" role="alert"><?= lg_h($erreur) ?></p>
    <?php endif; ?>

    <form method="post" action="login.php">
      <input type="hidden" name="_t" value="<?= lg_h($csrf) ?>">
      <label>
        <span>Adres e-mail</span>
        <input type="email" name="email" value="<?= lg_h($email) ?>" autocomplete="username"
               required autofocus inputmode="email">
      </label>
      <label>
        <span>Hasło</span>
        <input type="password" name="password" autocomplete="current-password" required>
      </label>
      <button type="submit">Zaloguj się</button>
    </form>

    <p class="foot">
      Po <?= (int) WSM_LOGIN_MAX_TRIES ?> nieudanych próbach konto zostaje zablokowane
      na <?= (int) (WSM_LOGIN_LOCK / 60) ?> minut. Hasło resetuje osoba z rolą
      Administrator, na ekranie Użytkownicy.<br>
      <a href="../shop/">← Sklep</a>
    </p>
  </div>
</body>
</html>
