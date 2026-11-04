<?php
// ============================================================================
//  superadmin.php — le décompte du propriétaire de la plateforme.
//
//  Cet écran ne sert pas la boutique : il sert celui qui la loue. Il répond à
//  une seule question — combien la boutique doit-elle ce mois-ci, et pour
//  quelle raison exactement.
//
//  IL N'EST PAS PROTÉGÉ PAR UN RÔLE. Un rôle s'attribue depuis la console, et
//  la console appartient à la boutique : le locataire pourrait alors ouvrir la
//  page qui chiffre son loyer. L'autorisation vient du fichier de
//  configuration du serveur (voir platform.php, règle 1). Sans liste
//  configurée, la page n'existe pas — 404, pas 403 : un 403 confirmerait à un
//  curieux qu'il y a quelque chose derrière.
//
//  Tout montant affiché porte son calcul à côté. C'est la même exigence que
//  la valorisation dans l'Audyt : une somme réclamée sans son détail se
//  discute mal, et se conteste encore plus mal.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/platform.php';
require_once $API . '/usage.php';
require_once $API . '/roles.php';
require_once $API . '/invoice.php';       // wsm_status_triggers()
require_once $API . '/mail.php';
require_once $API . '/ksef.php';
require_once $API . '/settings.php';
// L'état de chaque intégration, pour la table des clés.
foreach (['stripe', 'inpost', 'dpd', 'fiskal', 'smsapi', 'translate', 'allegro'] as $m) {
    if (is_file($API . '/' . $m . '.php')) require_once $API . '/' . $m . '.php';
}
/** Chaque intégration marche-t-elle, avec les clés en place ? */
$kluczeEtat = function (): array {
    $f = fn(string $fn) => function_exists($fn) && (bool) $fn();
    return ['claude' => $f('wsm_tr_enabled'), 'stripe' => $f('wsm_stripe_ready'), 'ksef' => $f('wsm_ksef_enabled'),
            'paragon' => $f('wsm_fiskal_enabled'), 'inpost' => $f('wsm_inpost_enabled'), 'dpd' => $f('wsm_dpd_enabled'),
            'mail' => $f('wsm_mail_enabled'), 'sms' => $f('wsm_sms_enabled'), 'allegro' => $f('wsm_allegro_enabled')];
};

// ============================================================================
//  LA PORTE. Rien au-delà de cette ligne ne s'exécute pour quelqu'un d'autre.
//
//  TROIS TITRES D'ENTRÉE, et le troisième est celui qu'on utilise vraiment :
//
//   1. le rôle Superadmin en base ;
//   2. l'adresse déclarée côté serveur (superadmin_emails) ;
//   3. le CODE DU JOUR, ouvert aux comptes Administrator.
//
//  Le troisième existe parce que les deux premiers ne s'ouvraient à personne :
//  aucun compte ne portait le rôle, la liste du serveur était vide, et seul un
//  Superadmin peut en désigner un autre. L'écran était inatteignable.
//
//  LE CODE RESTE EXIGÉ DANS TOUS LES CAS où il est configuré — y compris pour
//  un vrai Superadmin. Une seule porte, une seule règle à retenir : sinon on
//  finit par ne plus savoir qui doit taper quoi.
// ============================================================================
$parRole = wsm_is_superadmin($me);
$parCode = wsm_super_par_code($me);
if (!$parRole && !$parCode) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><title>404</title>'
       . '<p style="font:16px system-ui;padding:2rem">Nie znaleziono strony.</p>');
}

$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

// ============================================================================
//  LE CODE DU JOUR — second verrou, et rien d'autre ne s'exécute avant lui.
//
//  L'écran est déjà derrière la connexion et le rôle Superadmin. Celui-ci
//  ferme le cas de la session laissée ouverte : on ne tombe pas sur la
//  facturation de la plateforme d'un clic distrait.
//
//  IL SE RETIENT POUR LA JOURNÉE, pas pour toujours. Redemander le code à
//  chaque clic ferait écrire le nombre sur un papier collé à l'écran — ce qui
//  vaut moins que pas de code du tout. Il expire au changement de jour,
//  puisque c'est le jour qui le fabrique.
//
//  ON COMPTE LES ESSAIS. Sept chiffres dont six fixes se devinent en quelques
//  secondes si l'on peut essayer sans fin. Même règle que la page de
//  connexion : cinq essais, puis un quart d'heure.
// ============================================================================
if (wsm_super_code_actif() && !wsm_super_code_donne()) {
    $jour = date('Y-m-d');
    $codeErr = '';

    $bloque = (int) ($_SESSION['wsm_super_code_lock'] ?? 0) > time();

    if (!$bloque && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['kod_dnia'])) {
        if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) {
            $codeErr = 'Sesja formularza wygasła. Spróbuj jeszcze raz.';
        } elseif (wsm_super_code_ok((string) $_POST['kod_dnia'])) {
            // Régénération : le code ouvre une session privilégiée, et une
            // session privilégiée ne garde pas l'identifiant d'avant.
            session_regenerate_id(true);
            $_SESSION['wsm_super_code_jour'] = $jour;
            unset($_SESSION['wsm_super_code_try'], $_SESSION['wsm_super_code_lock']);
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Kod dnia — wejście', 'superadmin.php', 'Platforma');
            header('Location: superadmin.php', true, 303);
            exit;
        } else {
            $n = (int) ($_SESSION['wsm_super_code_try'] ?? 0) + 1;
            $_SESSION['wsm_super_code_try'] = $n;
            // Le refus ne dit RIEN du code : ni sa longueur, ni combien de
            // chiffres étaient bons. Il dit seulement qu'il est faux.
            $codeErr = 'Nieprawidłowy kod.';
            if ($n >= WSM_SUPER_CODE_MAX) {
                $_SESSION['wsm_super_code_lock'] = time() + WSM_SUPER_CODE_LOCK;
                $_SESSION['wsm_super_code_try'] = 0;
                $bloque = true;
                // Une rafale d'essais sur CET écran mérite une trace
                // nominative : c'est la facturation de la plateforme.
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Kod dnia — zablokowany',
                          'superadmin.php', 'Platforma');
            }
        }
    }

    if ($bloque) {
        $codeErr = 'Za dużo prób. Spróbuj za '
                 . max(1, (int) ceil(((int) $_SESSION['wsm_super_code_lock'] - time()) / 60)) . ' min.';
    }

    console_head('Superadmin', $me, <<<'CSS'
      .gate { max-width: 380px; }
      .gate p { font-size: 13.5px; color: var(--text-muted); line-height: 1.6; margin: 0 0 16px; }
      .gate input { width: 100%; box-sizing: border-box; min-height: 46px; padding: 0 13px;
                    font-family: var(--font-mono); font-size: 19px; letter-spacing: .22em;
                    text-align: center; border: 1px solid var(--border-default);
                    border-radius: var(--radius-md); background: var(--bg-page); color: inherit; }
      .gate button { width: 100%; min-height: 46px; margin-top: 12px; }
      .gate .err { border: 1px solid color-mix(in srgb, var(--danger) 45%, transparent);
                   color: var(--danger); background: color-mix(in srgb, var(--danger) 7%, transparent);
                   border-radius: var(--radius-md); padding: 10px 12px; font-size: 13.5px;
                   margin-bottom: 14px; }
    CSS);
    console_crumbs(['Pulpit' => 'pulpit.php', 'Superadmin' => null]);
    ?>
    <div class="panel gate">
      <h2>Kod dnia</h2>
      <?php if ($codeErr !== ''): ?><p class="err" role="alert"><?= h($codeErr) ?></p><?php endif; ?>
      <p>Ten ekran pokazuje rozliczenie platformy. Poza logowaniem wymaga kodu,
         który zmienia się każdego dnia.</p>
      <form method="post" action="superadmin.php">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <?php // inputmode=numeric : le clavier numérique s'ouvre sur un
              // téléphone. autocomplete=off : ce code ne se retient pas
              // dans un gestionnaire de mots de passe, il change chaque jour. ?>
        <input type="password" name="kod_dnia" inputmode="numeric" pattern="[0-9]*"
               autocomplete="off" autofocus aria-label="Kod dnia"
               <?= isset($_SESSION['wsm_super_code_lock']) && (int) $_SESSION['wsm_super_code_lock'] > time() ? 'disabled' : '' ?>>
        <button class="btn btn--brand" type="submit">Dalej</button>
      </form>
      <p style="margin:14px 0 0">Po <?= (int) WSM_SUPER_CODE_MAX ?> błędnych próbach
         ekran zamyka się na <?= (int) (WSM_SUPER_CODE_LOCK / 60) ?> minut.</p>
    </div>
    <?php
    console_foot();
    exit;
}

$flash = ''; $kind = 'ok'; $errors = [];

// Les écrans qu'un profil peut citer : le rail COMPLET, non filtré. Passer
// $me donnerait la liste de celui qui regarde — on attribuerait alors des
// droits en fonction de ce que l'on possède soi-même, ce qui n'a aucun sens.
$dispo = console_menu(null);
unset($dispo['superadmin.php']);       // ceinture : cet écran ne s'accorde pas

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) { http_response_code(400); exit('Bad request.'); }
    $actor = (string) ($me['nom'] ?? '');

    if (isset($_POST['profil_zapisz'])) {
        $nom = (string) ($_POST['profil_nom'] ?? '');
        $ecr = [];
        foreach ((array) ($_POST['ekran'] ?? []) as $f => $d) $ecr[(string) $f] = (string) $d;
        [$okP, $errors] = wsm_profil_save($pdo, $nom, $ecr, (string) ($_POST['profil_opis'] ?? ''), $dispo);
        if ($okP) {
            $flash = 'Zapisano profil ' . $nom . '.';
            // Une trace NOMINATIVE, contrairement à l'enregistreur : changer
            // qui ouvre quoi est un acte, pas une visite.
            wsm_audit($pdo, $actor, 'Zmiana profilu', 'wsm_role_profiles ' . $nom, 'Platforma');
        } else { $flash = 'Popraw zaznaczone pola.'; $kind = 'err'; }

    } elseif (isset($_POST['profil_reset'])) {
        $nom = (string) $_POST['profil_reset'];
        if (wsm_profil_reset($pdo, $nom)) {
            $flash = 'Profil ' . $nom . ' wrócił do ustawień wbudowanych.';
            wsm_audit($pdo, $actor, 'Przywrócenie profilu', 'wsm_role_profiles ' . $nom, 'Platforma');
        } else { $flash = 'Tego profilu nie można zmieniać.'; $kind = 'err'; }

    } elseif (isset($_POST['profil_usun'])) {
        $nom = (string) $_POST['profil_usun'];
        [$okD, $msg] = wsm_profil_supprime($pdo, $nom, wsm_roles_base());
        if ($okD) {
            $flash = 'Usunięto profil ' . $nom . '.';
            wsm_audit($pdo, $actor, 'Usunięcie profilu', 'wsm_role_profiles ' . $nom, 'Platforma');
            header('Location: superadmin.php', true, 303);
            exit;
        } else { $flash = $msg; $kind = 'err'; }

    } elseif (isset($_POST['wyzwalacze'])) {
        // CES QUATRE INTERRUPTEURS DÉCIDENT DE CE QUI PART AU FISC. Chaque
        // changement laisse une trace NOMINATIVE : « personne n'a touché à
        // rien » doit pouvoir se vérifier, pas se croire.
        require_once $API . '/settings.php';
        $avant = wsm_orders_cfg();
        $st = (string) ($_POST['doc_status'] ?? 'wyslane');
        if (!in_array($st, ['wyslane', 'oplacone', 'dostarczone', 'nigdy'], true)) $st = 'wyslane';
        // wsm_settings_save() attend ses clés avec « __ » à la place du point :
        // c'est la forme que porte un nom de champ HTML. Lui passer la forme
        // pointée ne lève rien — elle est simplement IGNORÉE, et l'écran
        // annonce « enregistré » sans avoir rien enregistré.
        wsm_settings_save($pdo, [
            'orders__doc_status'   => $st,
            'orders__doc_mail'     => empty($_POST['doc_mail'])     ? '0' : '1',
            'orders__doc_ksef'     => empty($_POST['doc_ksef'])     ? '0' : '1',
            'orders__vies_recheck' => empty($_POST['vies_recheck']) ? '0' : '1',
        ], $actor);
        wsm_settings_apply($pdo);
        $apres = wsm_orders_cfg();
        $diff = [];
        foreach ($apres as $k => $v) {
            if (($avant[$k] ?? null) !== $v) {
                $diff[] = $k . ': ' . (is_bool($avant[$k] ?? null) ? ($avant[$k] ? 'tak' : 'nie') : (string) ($avant[$k] ?? '?'))
                        . ' → ' . (is_bool($v) ? ($v ? 'tak' : 'nie') : (string) $v);
            }
        }
        wsm_audit($pdo, $actor, 'Wyzwalacze zamówień',
                  $diff ? implode(' · ', $diff) : 'bez zmian', 'Platforma');
        $flash = $diff ? 'Zapisano: ' . implode(' · ', $diff) : 'Nic się nie zmieniło.';
        $kind  = 'ok';

    } elseif (isset($_POST['zeruj'])) {
        // ─── LE MENAGE D'AVANT L'OUVERTURE ────────────────────────────────
        //
        // Irreversible, donc traite comme tel : reserve au Superadmin, derriere
        // le code du jour, avec un mot a retaper. Une fenetre « etes-vous
        // sur ? » apprend a cliquer « oui » sans lire ; un mot a taper, non.
        require_once $API . '/golive.php';
        if (trim((string) ($_POST['potwierdz'] ?? '')) !== WSM_GOLIVE_MOT) {
            $flash = 'Wpisz ' . WSM_GOLIVE_MOT . ', żeby potwierdzić.'; $kind = 'err';
        } else {
            [$okZ, $msgZ] = wsm_golive_reset($pdo, $actor);
            $flash = $okZ ? 'Wyczyszczono: ' . $msgZ : $msgZ;
            $kind  = $okZ ? 'ok' : 'err';
        }

    } elseif (isset($_POST['wystaw'])) {
        [$p, $err] = wsm_platform_issue($pdo, (string) $_POST['wystaw'], $actor);
        if ($p) {
            $flash = 'Wystawiono rozliczenie za ' . $_POST['wystaw'] . ' — '
                   . pln((int) $p['total_gross']) . ' brutto.';
            wsm_audit($pdo, $actor, 'Wystawienie rozliczenia',
                      'wsm_platform_periods ' . $_POST['wystaw'], 'Platforma');
        } else { $flash = $err; $kind = 'err'; }

    } elseif (isset($_POST['oplacone']) || isset($_POST['cofnij'])) {
        $ym  = (string) ($_POST['oplacone'] ?? $_POST['cofnij']);
        [$p, $err] = wsm_platform_mark_paid($pdo, $ym, isset($_POST['oplacone']));
        if ($p) {
            $flash = isset($_POST['oplacone'])
                ? 'Oznaczono ' . $ym . ' jako opłacone.'
                : 'Cofnięto opłacenie ' . $ym . '.';
            wsm_audit($pdo, $actor, 'Zmiana statusu rozliczenia',
                      'wsm_platform_periods ' . $ym, 'Platforma');
        } else { $flash = $err; $kind = 'err'; }

    } elseif (isset($_POST['klucze_zapisz']) || isset($_POST['klucze_wklej'])) {
        // ====================================================================
        //  LES CLÉS DE TOUTES LES INTÉGRATIONS — la table, ou un bloc collé.
        //
        //  Seuls les groupes d'intégration s'écrivent (WSM_SETTINGS_KLUCZE) :
        //  jamais la base, le jeton d'administration ni le code superadmin.
        //  Une valeur posée sur le serveur (variable d'environnement) reste
        //  maîtresse et n'est pas touchée. Le résultat s'affiche SANS
        //  redirection : rien de ce qui a été collé ne passe dans une adresse,
        //  donc dans un journal de serveur. Le journal d'audit ne garde que les
        //  NOMS des clés changées.
        // ====================================================================
        $champsK = wsm_settings_klucze_fields();
        $nieznane = []; $bledne = []; $naSerwerze = [];
        if (isset($_POST['klucze_wklej'])) {
            $lu = wsm_settings_parse_paste((string) ($_POST['wklej'] ?? ''));
            $postK = $lu['wartosci']; $nieznane = $lu['nieznane']; $bledne = $lu['bledne'];
        } else {
            $postK = [];
            foreach ($champsK as $k => $d) {
                $fk = str_replace('.', '__', $k);
                if (array_key_exists($fk, $_POST)) $postK[$fk] = (string) $_POST[$fk];
            }
        }
        foreach (array_keys($postK) as $fk) {
            $k = str_replace('__', '.', $fk);
            if (isset($champsK[$k]) && getenv($champsK[$k][3]) !== false) { $naSerwerze[] = $champsK[$k][3]; unset($postK[$fk]); }
        }
        $refusK = [];
        $zmienione = $postK ? wsm_settings_save($pdo, $postK, $actor, $refusK, array_keys(WSM_SETTINGS_KLUCZE)) : [];
        $nom = fn(string $k) => ($champsK[$k][1] ?? $k) . ' (' . (WSM_SETTINGS_KLUCZE[$champsK[$k][0] ?? ''] ?? '') . ')';
        $m = [$zmienione ? 'Zapisano ' . count($zmienione) . ': ' . implode(', ', array_map($nom, $zmienione)) . '.' : 'Nic nie zmieniono.'];
        if ($refusK) $m[] = 'Odrzucone: ' . implode('; ', array_map(fn($k) => $nom($k) . ' — ' . $refusK[$k], array_keys($refusK))) . '.';
        if ($bledne) $m[] = 'Błędna wartość: ' . implode('; ', array_map(fn($n) => $n . ' (' . $bledne[$n] . ')', array_keys($bledne))) . '.';
        if ($naSerwerze) $m[] = 'Ustawione na serwerze, bez zmian: ' . implode(', ', $naSerwerze) . '.';
        if ($nieznane) {
            // Un nom inconnu ne se répète que s'il RESSEMBLE à un nom : une ligne
            // de jeton mal collée ne doit pas s'afficher en clair.
            $lisibles = array_values(array_filter($nieznane, fn($n) => (bool) preg_match('/^[A-Za-z][A-Za-z0-9]*([_.][A-Za-z0-9]+)+$/', $n) && strlen($n) <= 40));
            $m[] = 'Pominięte, to nie są klucze integracji: ' . ($lisibles ? implode(', ', array_slice($lisibles, 0, 12)) : '')
                 . (count($nieznane) > count($lisibles) ? ($lisibles ? ' i ' : '') . (count($nieznane) - count($lisibles)) . ' nierozpoznanych linii' : '') . '.';
        }
        if ($zmienione) wsm_audit($pdo, $actor, 'Klucze integracji', mb_substr(implode(', ', $zmienione), 0, 200), 'Platforma');
        // La configuration de cette requête a été lue AVANT l'enregistrement :
        // sans la relire, la table dirait « brak » à côté d'une clé tout juste posée.
        wsm_settings_apply($pdo);
        $flash = implode(' ', $m);
        $kind = ($refusK || $bledne) ? 'err' : ($zmienione ? 'ok' : 'warn');
        $kluczeWidok = true;
    } elseif (isset($_POST['warunki'])) {
        [$t, $errors] = wsm_platform_terms_save($pdo, $_POST, $actor);
        if ($t) {
            $flash = 'Zapisano warunki obowiązujące od ' . $t['from_ym'] . '.';
            wsm_audit($pdo, $actor, 'Zmiana warunków', 'wsm_platform_terms', 'Platforma');
        } else { $flash = 'Popraw zaznaczone pola.'; $kind = 'err'; }
    }
}

// ============================================================================
//  LA TABLE DES CLÉS — une vue à part, comme l'éditeur de profil.
//
//  Toutes les intégrations sur une page : ce qui est posé, d'où ça vient
//  (base, serveur), si l'intégration marche, et un champ pour coller. Un
//  secret ne se relit jamais : le champ est vide, il dit seulement « ustawione ».
// ============================================================================
if (isset($_GET['klucze']) || !empty($kluczeWidok)) {
    $vue = wsm_settings_view($pdo);
    $champsK = wsm_settings_klucze_fields();
    $etat = $kluczeEtat();
    $parGroupe = [];
    foreach ($champsK as $k => $d) $parGroupe[$d[0]][$k] = $vue[$k];
    $sourceLbl = ['baza' => 'zapisane w konsoli', 'serwer' => 'z serwera', 'domyślne' => 'domyślne', 'brak' => ''];
    console_head('Klucze integracji', $me, <<<'CSS'
      .why { font-size: 14px; color: var(--text-muted); line-height: 1.6; margin: 0 0 14px; max-width: 90ch; }
      .klucze td { vertical-align: top; padding: 10px 8px; }
      .klucze tr.grp th { text-align: left; padding: 22px 8px 8px; font-size: 16px; color: var(--text-strong); border-bottom: 1px solid var(--border-subtle); }
      .klucze td small { display: block; color: var(--text-muted); font-size: 12.5px; margin-top: 3px; line-height: 1.45; }
      .klucze input, .klucze select, .klucze textarea { width: 100%; min-width: 220px; }
      .klucze code { font-size: 12.5px; word-break: break-all; }
      textarea.wklej { width: 100%; font-family: var(--font-mono); font-size: 13px; min-height: 170px; }
    CSS);
    console_flash($flash, $kind);
    console_crumbs(['Pulpit' => 'pulpit.php', 'Superadmin' => 'superadmin.php', 'Klucze integracji' => null]);
    ?>
    <div class="panel">
      <h2>Wklej wszystkie klucze naraz</h2>
      <p class="why">Jedna linia na klucz: <code>NAZWA=wartość</code> (jak w pliku .env), <code>NAZWA: wartość</code>
        albo dwie kolumny skopiowane z arkusza. Nazwy to zmienne z tabeli poniżej (z <code>WSM_</code> albo bez).
        Puste linie i komentarze (#) są pomijane. Zapisują się tylko klucze integracji — nigdy baza danych ani dostęp
        administratora. Wartości nie wracają na ekran i nie trafiają do dziennika: zapisuje się tylko, KTÓRE klucze zmieniono.</p>
      <form method="post" autocomplete="off">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <textarea class="wklej" name="wklej" spellcheck="false" autocomplete="off" aria-label="Klucze do wklejenia"
                  placeholder="WSM_ANTHROPIC_API_KEY=…&#10;WSM_SMSAPI_TOKEN=…&#10;WSM_SMSAPI_NADAWCA=SzokoShop&#10;WSM_INPOST_TOKEN=…&#10;WSM_FAKTUROWNIA_DOMENA=firma&#10;WSM_FAKTUROWNIA_TOKEN=…"></textarea>
        <div class="actions"><button class="btn btn--brand" type="submit" name="klucze_wklej" value="1">Zapisz wklejone klucze</button></div>
      </form>
    </div>

    <form method="post" autocomplete="off" class="panel">
      <input type="hidden" name="_t" value="<?= h($csrf) ?>">
      <h2>Wszystkie integracje</h2>
      <p class="why">Pole sekretu jest zawsze puste — „ustawione” znaczy, że klucz jest zapisany. Puste pole sekretu = bez zmian.
        Wartość ustawiona na serwerze (plik konfiguracyjny, zmienna środowiska) ma pierwszeństwo i nie zmienia się stąd.</p>
      <div class="tablewrap"><table class="klucze">
        <thead><tr><th>Pole</th><th>Zmienna</th><th>Stan</th><th>Nowa wartość</th></tr></thead>
        <tbody>
        <?php foreach ($parGroupe as $g => $rows): ?>
        <tr class="grp"><th colspan="4"><?= h(WSM_SETTINGS_KLUCZE[$g]) ?>
          <span class="tag <?= !empty($etat[$g]) ? 'ok' : 'bad' ?>"><?= !empty($etat[$g]) ? 'działa' : 'wyłączone' ?></span></th></tr>
        <?php foreach ($rows as $k => $v): $t = (string) $v['type']; ?>
        <tr>
          <td><?= h((string) $v['label']) ?><?php if ((string) $v['help'] !== ''): ?><small><?= h((string) $v['help']) ?></small><?php endif; ?></td>
          <td><code><?= h((string) $v['env']) ?></code></td>
          <td><span class="tag <?= $v['set'] ? 'ok' : 'wait' ?>"><?= $v['set'] ? 'ustawione' : 'brak' ?></span>
            <?php if (($sourceLbl[$v['source']] ?? '') !== ''): ?><small><?= h($sourceLbl[$v['source']]) ?></small><?php endif; ?></td>
          <td>
          <?php if ($v['locked']): ?>
            <span class="muted">ustawione na serwerze</span>
          <?php elseif ($t === 'secret'): ?>
            <input type="password" name="<?= h((string) $v['form']) ?>" autocomplete="new-password" spellcheck="false"
                   placeholder="<?= $v['set'] ? 'ustawione — wklej, żeby zmienić' : 'wklej klucz' ?>" aria-label="<?= h((string) $v['label']) ?>">
          <?php elseif ($t === 'pem'): ?>
            <textarea name="<?= h((string) $v['form']) ?>" rows="2" spellcheck="false" placeholder="-----BEGIN PUBLIC KEY-----" aria-label="<?= h((string) $v['label']) ?>"></textarea>
          <?php elseif (str_starts_with($t, 'select:')): ?>
            <select name="<?= h((string) $v['form']) ?>" aria-label="<?= h((string) $v['label']) ?>">
              <?php foreach (explode('|', substr($t, 7)) as $o): ?><option value="<?= h($o) ?>"<?= (string) $v['show'] === $o ? ' selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?>
            </select>
          <?php else: ?>
            <input type="text" name="<?= h((string) $v['form']) ?>" spellcheck="false" aria-label="<?= h((string) $v['label']) ?>"
                   value="<?= $v['show'] !== WSM_SETTING_PLACEHOLDER ? h((string) $v['show']) : '' ?>">
          <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endforeach; ?>
        </tbody>
      </table></div>
      <div class="actions"><button class="btn btn--brand" type="submit" name="klucze_zapisz" value="1">Zapisz klucze</button>
        <a class="code" href="ustawienia.php">Sprawdź połączenia w Integracjach →</a></div>
    </form>
    <?php
    console_foot();
    exit;
}

// ============================================================================
//  L'ÉDITEUR D'UN PROFIL — une vue à part, et pas un panneau de plus.
//
//  Modifier qui ouvre quoi demande de lire vingt lignes ligne à ligne. Le
//  poser au bas d'un écran qui chiffre déjà douze mois de loyer, c'est se
//  tromper de case une fois sur trois. Il prend donc la page entière — et
//  reste DERRIÈRE la même porte : rien à garder deux fois.
// ============================================================================
$editProfil = isset($_GET['profil']) ? (string) $_GET['profil'] : null;
if ($editProfil !== null) {
    $nouveau = ($editProfil === '+' || $editProfil === '');
    $tableau = wsm_profil_tableau($pdo, $dispo, 30);
    $ligne   = $nouveau ? null : ($tableau[$editProfil] ?? null);

    if (!$nouveau && !$ligne) {
        http_response_code(404);
        $flash = 'Nie ma takiego profilu.'; $kind = 'err';
    }
    // Un profil intouchable ne s'ouvre pas dans un formulaire : proposer des
    // cases qu'on refusera d'enregistrer est une promesse qu'on ne tient pas.
    if ($ligne && !$ligne['modifiable']) {
        header('Location: superadmin.php', true, 303);
        exit;
    }

    // Après un enregistrement refusé, on RÉAFFICHE ce qui a été saisi : sinon
    // la correction d'une faute de frappe efface vingt cases cochées à la main.
    $vNom  = (string) ($_POST['profil_nom']  ?? ($ligne['rola'] ?? ''));
    $vOpis = (string) ($_POST['profil_opis'] ?? ($ligne['aide'] ?? ''));
    $vEcr  = isset($_POST['ekran']) ? (array) $_POST['ekran'] : ($ligne['droits'] ?? []);
    $fait  = wsm_profil_de_fait($pdo, 30)[$ligne['rola'] ?? ''] ?? [];
    $satParent = [];
    foreach (wsm_profil_satellites() as $p => $ss) foreach ($ss as $s) $satParent[$p][] = $s;

    console_head('Profil', $me, <<<'CSS'
      .why { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0 0 14px; }
      .fld { display: grid; gap: 5px; margin-bottom: 14px; max-width: 520px; }
      .fld label { font-size: 12.5px; color: var(--text-muted); }
      .fld .err { font-size: 12px; color: var(--danger); }
      .fld input.bad { border-color: var(--danger); }
      .ecr td:first-child { font-family: var(--font-mono); font-size: 12.5px; }
      .ecr select { min-height: 40px; }
      /* Un droit jamais exercé en trente jours n'est pas une faute : c'est une
         question. Il se signale, il ne s'accuse pas. */
      .dormant { color: var(--warning); font-size: 11.5px; white-space: nowrap; }
      .vu { font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); }
      .sat { font-size: 11.5px; color: var(--text-muted); }
      .danger-zone { border-top: 1px solid var(--border-subtle); margin-top: 22px; padding-top: 16px; }
    CSS);
    console_crumbs(['Pulpit' => 'pulpit.php', 'Superadmin' => 'superadmin.php',
                    ($nouveau ? 'Nowy profil' : $vNom) => null]);
    if ($flash !== '') console_flash($flash, $kind);
    ?>
    <div class="panel">
      <h2><?= $nouveau ? 'Nowy profil' : 'Profil ' . h($vNom) ?></h2>
      <p class="why">
        Profil mówi, które ekrany ktoś <b>otwiera</b> i gdzie może <b>zmieniać</b>.
        Ekran spoza listy nie znika tylko z menu — <b>strona sama się pilnuje</b> i odpowiada
        odmową. Zapis <b>zastępuje</b> całą listę: to, co odznaczysz, naprawdę zniknie.
        <br>
        Kolumna „Otwierano” pokazuje, ile razy ten profil wchodził na ekran przez ostatnie
        <b>30 dni</b>. Prawo, z którego nikt nie korzysta, nie daje żadnego błędu i nikt się
        nie skarży — dlatego łatwo je przeoczyć. Ale <b>trzydzieści dni to nie rok</b>:
        praca miesięczna (KSeF, faktury) potrafi się w tym okresie nie pokazać. To podpowiedź,
        nie wyrok.
      </p>

      <form method="post" action="superadmin.php?profil=<?= rawurlencode($nouveau ? '+' : $vNom) ?>">
        <input type="hidden" name="_t" value="<?= h($csrf) ?>">
        <input type="hidden" name="profil_zapisz" value="1">

        <div class="fld">
          <label for="pnom">Nazwa profilu</label>
          <input id="pnom" name="profil_nom" maxlength="<?= (int) WSM_PROFIL_NOM_MAX ?>"
                 value="<?= h($vNom) ?>" class="<?= isset($errors['rola']) ? 'bad' : '' ?>"
                 <?= $nouveau ? 'autofocus' : 'readonly' ?>>
          <?php if (isset($errors['rola'])): ?><span class="err"><?= h($errors['rola']) ?></span><?php endif; ?>
          <?php if (!$nouveau): ?><span class="why" style="margin:0">Nazwy nie zmieniamy —
            noszą ją konta w tabeli użytkowników.</span><?php endif; ?>
        </div>

        <div class="fld">
          <label for="popis">Do czego służy</label>
          <input id="popis" name="profil_opis" maxlength="160" value="<?= h($vOpis) ?>"
                 class="<?= isset($errors['opis']) ? 'bad' : '' ?>"
                 placeholder="np. Praktykant — podgląd zamówień, nic więcej">
          <?php if (isset($errors['opis'])): ?><span class="err"><?= h($errors['opis']) ?></span><?php endif; ?>
          <span class="why" style="margin:0">To zdanie widać przy nadawaniu roli
            na ekranie Użytkownicy — samo „Sprzedaż” nikomu nie pomaga wybrać.</span>
        </div>

        <table class="rwd ecr">
          <thead><tr><th>Ekran</th><th>Dostęp</th><th class="num">Otwierano (30 dni)</th></tr></thead>
          <tbody>
          <?php foreach ($dispo as $f => $label):
            $cur = (string) ($vEcr[$f] ?? '');
            $n   = (int) ($fait[$f] ?? 0);
            // Les écrans qui donnent les clés méritent d'être nommés comme tels
            // au moment où on les coche, pas dans une note qu'on lira après.
            $sensible = in_array($f, ['uzytkownicy.php', 'ustawienia.php', 'audyt.php'], true); ?>
            <tr>
              <td data-l="Ekran"><?= h($label) ?><br>
                <span class="sat"><?= h((string) $f) ?><?php
                  if ($sensible) echo ' — <b>daje klucze</b>';
                  if (isset($satParent[$f])) echo ' + ' . h(implode(', ', $satParent[$f]));
                ?></span></td>
              <td data-l="Dostęp">
                <?php // UNE ÉTIQUETTE PAR LISTE, et pas un intitulé de colonne.
                      // Vingt et une listes s'annonçaient « menu déroulant,
                      // Podgląd » à la synthèse vocale : le nom de l'écran est
                      // dans la cellule d'à côté, et une cellule d'à côté ne se
                      // lit pas. Sur téléphone, le tableau se replie en fiches
                      // et la colonne disparaît carrément. ?>
                <select name="ekran[<?= h((string) $f) ?>]"
                        aria-label="Dostęp do ekranu <?= h($label) ?>">
                  <option value=""<?= $cur === ''  ? ' selected' : '' ?>>— zamknięty</option>
                  <option value="r"<?= $cur === 'r' ? ' selected' : '' ?>>Podgląd</option>
                  <option value="w"<?= $cur === 'w' ? ' selected' : '' ?>>Podgląd i zmiana</option>
                </select>
              </td>
              <td data-l="Otwierano (30 dni)" class="num">
                <?php if ($n > 0): ?><span class="vu"><?= $n ?></span>
                <?php elseif ($cur !== ''): ?><span class="dormant">ani razu</span>
                <?php else: ?><span class="vu">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="why" style="margin-top:12px">
          Wydruki i etykiety (<code>…_druk.php</code>, <code>etykieta_…</code>) nie mają tu
          własnego wiersza — <b>idą za swoim ekranem</b>. Inaczej ktoś dostałby „Zamówienia”
          bez prawa do wydruku, ekran by się otworzył, a przycisk odmówił — i szukałoby się
          usterki w drukarce.
        </p>
        <div class="actions" style="margin-top:14px">
          <button class="btn btn--brand" type="submit">Zapisz profil</button>
          <a class="btn ghost" href="superadmin.php">Anuluj</a>
        </div>
      </form>

      <?php if (!$nouveau && $ligne): ?>
      <div class="danger-zone">
        <?php if ($ligne['custom']): ?>
          <form method="post" onsubmit="return confirm('Usunąć profil <?= h($vNom) ?>?')">
            <input type="hidden" name="_t" value="<?= h($csrf) ?>">
            <p class="why" style="margin:0 0 8px">Profil utworzony w konsoli. Usunięcie jest
              możliwe tylko wtedy, gdy nie ma go już żadne <b>aktywne konto</b> — inaczej te
              konta cicho spadłyby na „Podgląd”, bez jednego komunikatu.</p>
            <button class="btn sm ghost" name="profil_usun" value="<?= h($vNom) ?>">Usuń profil</button>
          </form>
        <?php elseif ($ligne['change']): ?>
          <form method="post">
            <input type="hidden" name="_t" value="<?= h($csrf) ?>">
            <p class="why" style="margin:0 0 8px">Ten profil został zmieniony w konsoli.
              Można wrócić do listy wbudowanej w kod.</p>
            <button class="btn sm ghost" name="profil_reset" value="<?= h($vNom) ?>">Przywróć domyślne</button>
          </form>
        <?php else: ?>
          <p class="why" style="margin:0">Profil jest dokładnie taki, jak w kodzie —
            nie ma czego przywracać.</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php
    console_foot();
    exit;
}

$terms   = wsm_platform_terms($pdo);
$serie   = wsm_platform_series($pdo, 12);
$tot     = wsm_platform_totals($serie);
$histo   = wsm_platform_terms_history($pdo);
$courant = $serie[0] ?? null;

/**
 * Le pourcentage tel qu'on l'écrit en Pologne.
 *
 * ON NE RONGE LES ZÉROS QU'APRÈS LA VIRGULE. Sans cette garde, pct(0.20, 0)
 * rend « 2 % » : number_format donne « 20 », et le rtrim('0') emporte le zéro
 * des dizaines. Les deux taux qu'on regardait — 23 % de TVA et 5 % de
 * commission — n'ont pas de zéro final, donc rien ne se voyait. Trouvé sur
 * l'écran Dostawa, où « × 100 % » s'affichait « × 1 % ».
 */
function pct(float $r, int $dec = 2): string {
    $s = number_format($r * 100, $dec, ',', "\u{202F}");
    if (str_contains($s, ',')) $s = rtrim(rtrim($s, '0'), ',');
    return $s . "\u{202F}%";
}

/** Une barre pour douze mois : le décompte mensuel, du plus ancien au récent. */
function barres(array $serie): string {
    $pts = array_reverse($serie);
    $max = 0;
    foreach ($pts as $p) $max = max($max, (int) $p['total_gross']);
    if ($max <= 0) return '<p class="why">Brak rozliczeń do pokazania.</p>';

    $h = '<div class="chart"><div class="bars">';
    foreach ($pts as $p) {
        $v = (int) $p['total_gross'];
        $lbl = '<span class="lbl">' . h(substr($p['ym'], 5) . '/' . substr($p['ym'], 2, 2)) . '</span>';
        // Zéro ne dessine RIEN. Un moignon de deux pixels se lit comme « il
        // s'est passé quelque chose de très petit » alors qu'il ne s'est rien
        // passé du tout — et c'est une lecture qu'on ne rattrape pas.
        if ($v === 0) {
            $h .= '<div class="bar" title="' . h($p['ym'] . ' · brak') . '">' . $lbl . '</div>';
            continue;
        }
        $pc = max(1, (int) round($v / $max * 100));
        // La couleur dit l'état, pas la valeur : encaissé, émis, pas encore dû.
        $cls = !$p['frozen'] ? 'draft' : ($p['status'] === 'oplacone' ? 'ok' : 'wait');
        $h .= '<div class="bar" title="' . h($p['ym'] . ' · ' . pln($v)) . '">'
            . '<i class="fill ' . $cls . '" style="height:' . $pc . '%"></i>'
            . $lbl . '</div>';
    }
    $h .= '</div><div class="scale"><span>' . h(pln($max)) . '</span><span>0</span></div></div>';
    return $h;
}

console_head('Superadmin', $me, <<<'CSS'
  .why { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0 0 14px; }
  /* La formule passe à la ligne au lieu de défiler. Un calcul dont on ne voit
     pas la fin ne se vérifie pas — et c'est toute la raison de l'afficher. */
  .calc { font-family: var(--font-mono); font-size: 12px; color: var(--text-muted);
          background: var(--surface-sunken); border-radius: 8px; padding: 9px 11px;
          margin: 8px 0 0; line-height: 1.9; }
  .cards { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; }
  @media (min-width: 700px) { .cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  @media (min-width: 1100px) { .cards { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
  .cards .c { border: 1px solid var(--border-subtle); border-radius: 12px;
              padding: 13px 15px; min-width: 0; }
  .cards .c.hot { border-color: var(--brand); }
  .cards .c h4 { margin: 0 0 6px; font-family: var(--font-mono); font-size: 10.5px;
                 text-transform: uppercase; letter-spacing: .1em; color: var(--text-muted); }
  .cards .c b { display: block; font-family: var(--font-display); font-size: 23px;
                color: var(--text-strong); line-height: 1.15; }
  .cards .c small { display: block; margin-top: 5px; font-size: 12px; color: var(--text-muted); }
  /* Le tableau des rozliczenia a sept colonnes. Le coller à côté du
     formulaire dès 980 px coupait la colonne « Stan » et le bouton d'action :
     on voyait un décompte sans savoir s'il était payé. Les deux panneaux ne
     se partagent donc une rangée qu'à partir de 1400 px, où les sept colonnes
     tiennent vraiment. */
  .split { display: grid; grid-template-columns: minmax(0, 1fr); gap: 20px; }
  @media (min-width: 1400px) { .split { grid-template-columns: minmax(0, 1.9fr) minmax(0, 1fr); } }
  .chart { position: relative; padding-right: 62px; min-width: 0; margin-top: 12px; }
  .chart .bars { display: flex; align-items: flex-end; gap: 3px; height: 150px; min-width: 0; }
  .chart .bar { display: flex; flex-direction: column; justify-content: flex-end;
                align-items: center; height: 100%; flex: 1 1 0; min-width: 0; }
  .chart .fill { display: block; width: 68%; min-height: 2px; border-radius: 3px 3px 0 0;
                 background: var(--brand); }
  .chart .fill.ok    { background: var(--success); }
  .chart .fill.wait  { background: var(--caramel-400); }
  /* Un mois pas encore facturable n'est pas un impayé : il se lit comme une
     estimation, hachuré, et non comme une somme qu'on attend. */
  .chart .fill.draft { background: repeating-linear-gradient(45deg,
                        var(--border-default) 0 4px, transparent 4px 8px); }
  .chart .lbl { font-family: var(--font-mono); font-size: 9.5px; color: var(--text-muted);
                margin-top: 5px; white-space: nowrap; min-width: 0; }
  /* Douze « 09/25 » ne tiennent pas dans un panneau étroit : ils se
     chevauchaient jusqu'à devenir une bouillie grise. Plutôt que de rogner
     les étiquettes jusqu'à l'illisible, on n'en garde qu'une sur deux —
     l'axe reste lisible et les barres gardent leur largeur. */
  @media (max-width: 1100px) {
    .chart .lbl { display: none; }
    .chart .bar:nth-child(2n+1) .lbl { display: block; }
  }
  .chart .scale { position: absolute; right: 0; top: 0; height: 150px;
                  display: flex; flex-direction: column; justify-content: space-between;
                  font-family: var(--font-mono); font-size: 10px;
                  color: var(--text-muted); text-align: right; }
  .legend { display: flex; gap: 14px; flex-wrap: wrap; font-size: 12px;
            color: var(--text-muted); margin: 10px 0 0; }
  .legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px;
              margin-right: 5px; vertical-align: -1px; }
  .st { font-size: 11.5px; padding: 2px 9px; border-radius: 999px; white-space: nowrap;
        border: 1px solid var(--border-default); color: var(--text-muted); }
  .st.oplacone   { color: var(--success); border-color: var(--success); }
  .st.wystawione { color: var(--warning); border-color: var(--warning); }
  .fld { display: grid; gap: 5px; margin-bottom: 12px; }
  .fld label { font-size: 12.5px; color: var(--text-muted); }
  .fld .err { font-size: 12px; color: var(--danger); }
  .fld input.bad, .fld select.bad { border-color: var(--danger); }
  .hist { font-size: 12.5px; color: var(--text-muted); margin: 14px 0 0; }
  .hist li { margin-bottom: 4px; }
  /* Une barre DANS la cellule plutôt qu'un graphique à côté : la comparaison
     entre écrans se fait d'un coup d'œil, sans quitter la ligne qu'on lit. */
  .use-bar { display: block; height: 6px; border-radius: 3px; background: var(--brand);
             min-width: 2px; margin-top: 4px; }
  .use-bar.cold { background: var(--border-default); }
  .dead { display: flex; flex-wrap: wrap; gap: 7px; margin: 10px 0 0; padding: 0; list-style: none; }
  .dead li { font-family: var(--font-mono); font-size: 11.5px; padding: 3px 9px;
             border: 1px solid var(--border-default); border-radius: 999px;
             color: var(--text-muted); }
  .flow { margin: 10px 0 0; padding: 0; list-style: none; font-size: 13px; }
  .flow li { display: flex; align-items: baseline; gap: 8px; padding: 5px 0;
             border-bottom: 1px solid var(--border-subtle); }
  .flow li:last-child { border-bottom: 0; }
  .flow .f { font-family: var(--font-mono); font-size: 12px; color: var(--text-strong); }
  .flow .n { margin-left: auto; font-family: var(--font-mono); font-size: 12px;
             color: var(--text-muted); }
  .slow { color: var(--warning); font-weight: 600; }
  .dormant { color: var(--warning); }
  .prof-nom { font-family: var(--font-mono); font-size: 13px; color: var(--text-strong); }
  .prof-aide { display: block; font-size: 12px; color: var(--text-muted); margin-top: 3px; }
CSS);
console_crumbs(['Pulpit' => 'pulpit.php', 'Superadmin' => null]);
?>

<?php if ($flash !== '') console_flash($flash, $kind); ?>

<div class="cards">
  <div class="c hot">
    <h4>Bieżący miesiąc — na razie</h4>
    <b><?= h(pln((int) $tot['running'])) ?></b>
    <small>Miesiąc trwa: to nie jest jeszcze rachunek.</small>
  </div>
  <div class="c">
    <h4>Wystawione — 12 mies.</h4>
    <b><?= h(pln((int) $tot['due'])) ?></b>
    <small><?= h(pln((int) $tot['paid'])) ?> opłacone</small>
  </div>
  <div class="c<?= $tot['outstanding'] > 0 ? ' hot' : '' ?>">
    <h4>Do zapłaty</h4>
    <b><?= h(pln((int) $tot['outstanding'])) ?></b>
    <small>Wystawione i jeszcze nieopłacone</small>
  </div>
  <div class="c">
    <h4>Miesiące niewystawione</h4>
    <b><?= h(pln((int) $tot['pending'])) ?></b>
    <small>Zamknięte, czekają na wystawienie</small>
  </div>
</div>

<?php if ($courant): ?>
<div class="panel" style="margin-top:20px">
  <h2>Rachunek za <?= h($courant['ym']) ?> — krok po kroku</h2>
  <p class="why">
    Liczymy tylko to, co <b>naprawdę wpłynęło</b>: zamówienia opłacone i nieanulowane,
    datowane dniem zapłaty, nie dniem złożenia. Faktura wystawiona i nieopłacona nikomu
    nie przyniosła pieniędzy, a prowizja od sprzedaży, której nie było, raz się pobiera,
    a potem zwraca.
  </p>
  <p class="calc">podstawa <?= h(pln((int) $courant['base_amount'])) ?>
    × <?= h(pct((float) $courant['rate'])) ?> = prowizja <?= h(pln((int) $courant['commission_net'])) ?>
    &nbsp;+&nbsp; czynsz <?= h(pln((int) $courant['rent_net'])) ?>
    &nbsp;=&nbsp; <?= h(pln((int) $courant['total_net'])) ?> netto
    &nbsp;+&nbsp; VAT <?= h(pct((float) $courant['vat_rate'], 0)) ?> <?= h(pln((int) $courant['total_vat'])) ?>
    &nbsp;=&nbsp; <?= h(pln((int) $courant['total_gross'])) ?> brutto</p>

  <table class="rwd" style="margin-top:14px">
    <thead><tr><th>Pozycja</th><th class="num">Kwota</th><th>Skąd</th></tr></thead>
    <tbody>
      <tr>
        <td data-l="Pozycja">Obrót brutto — towar</td>
        <td data-l="Kwota" class="num"><?= h(pln((int) $courant['goods_gross'])) ?></td>
        <td data-l="Skąd"><?= (int) $courant['orders'] ?> opłaconych zamówień</td>
      </tr>
      <tr>
        <td data-l="Pozycja">Obrót brutto — dostawa</td>
        <td data-l="Kwota" class="num"><?= h(pln((int) $courant['shipping_gross'])) ?></td>
        <td data-l="Skąd">koszt przelotowy, pokazany osobno</td>
      </tr>
      <tr>
        <td data-l="Pozycja"><b>Podstawa prowizji</b></td>
        <td data-l="Kwota" class="num"><b><?= h(pln((int) $courant['base_amount'])) ?></b></td>
        <td data-l="Skąd"><?= h(WSM_PLAT_BASES[$courant['basis']]) ?></td>
      </tr>
      <tr>
        <td data-l="Pozycja">Prowizja <?= h(pct((float) $courant['rate'])) ?></td>
        <td data-l="Kwota" class="num"><?= h(pln((int) $courant['commission_net'])) ?></td>
        <td data-l="Skąd">podstawa × stawka</td>
      </tr>
      <tr>
        <td data-l="Pozycja">Czynsz</td>
        <td data-l="Kwota" class="num"><?= h(pln((int) $courant['rent_net'])) ?></td>
        <td data-l="Skąd">stały, niezależny od sprzedaży</td>
      </tr>
      <tr>
        <td data-l="Pozycja"><b>Razem brutto</b></td>
        <td data-l="Kwota" class="num"><b><?= h(pln((int) $courant['total_gross'])) ?></b></td>
        <td data-l="Skąd">netto + VAT <?= h(pct((float) $courant['vat_rate'], 0)) ?></td>
      </tr>
      <?php if ((int) ($courant['fees_collected'] ?? 0) > 0): ?>
      <tr>
        <td data-l="Pozycja">Pobrane już przez Stripe</td>
        <td data-l="Kwota" class="num">−<?= h(pln((int) $courant['fees_collected'])) ?></td>
        <td data-l="Skąd">prowizja potrącona przy każdej płatności (application fee)</td>
      </tr>
      <tr>
        <td data-l="Pozycja"><b>Pozostaje do zapłaty</b></td>
        <td data-l="Kwota" class="num"><b><?= h(pln((int) $courant['to_pay_gross'])) ?></b></td>
        <td data-l="Skąd">razem brutto − pobrane przez Stripe</td>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>

  <?php if ($courant['basis'] === 'brutto' && $courant['shipping_gross'] > 0):
    $surPort = (int) round($courant['shipping_gross'] * $courant['rate']); ?>
  <p class="why" style="margin-top:14px">
    Podstawa obejmuje dostawę, więc <b><?= h(pln($surPort)) ?></b> prowizji pochodzi z kosztu,
    który tylko przez sklep przechodzi — z marży nigdy nie był. To świadomy wybór, nie
    przeoczenie: „obrót brutto” dosłownie tyle znaczy. Przełącz podstawę na
    <b>sam towar</b> poniżej, jeśli wolisz liczyć bez dostawy.
  </p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="split" style="margin-top:20px">
  <div class="panel">
    <h2>Rozliczenia miesięczne</h2>
    <p class="why">
      Wystawić można tylko miesiąc <b>zakończony</b>: rachunek za trwający miesiąc byłby
      częściowy. Wystawione rozliczenie <b>zamraża</b> obrót, stawkę, czynsz i VAT —
      zmiana warunków w marcu nie przepisuje rachunku za luty.
    </p>
    <?= barres($serie) ?>
    <p class="legend">
      <span><i style="background:var(--success)"></i>Opłacone</span>
      <span><i style="background:var(--caramel-400)"></i>Wystawione</span>
      <span><i style="background:var(--border-default)"></i>Jeszcze niewystawione</span>
    </p>

    <table class="rwd" style="margin-top:16px">
      <thead><tr>
        <th>Miesiąc</th><th class="num">Obrót brutto</th><th class="num">Prowizja</th>
        <th class="num">Czynsz</th><th class="num">Razem brutto</th><th>Stan</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($serie as $p):
        $enCours = $p['ym'] === date('Y-m'); ?>
        <tr>
          <td data-l="Miesiąc"><b><?= h($p['ym']) ?></b>
            <?php if ($enCours): ?><br><small style="color:var(--text-muted)">trwa</small><?php endif; ?></td>
          <td data-l="Obrót brutto" class="num"><?= h(pln((int) $p['gross'])) ?></td>
          <td data-l="Prowizja" class="num"><?= h(pln((int) $p['commission_net'])) ?>
            <br><small style="color:var(--text-muted)"><?= h(pct((float) $p['rate'])) ?></small></td>
          <td data-l="Czynsz" class="num"><?= h(pln((int) $p['rent_net'])) ?></td>
          <td data-l="Razem brutto" class="num"><b><?= h(pln((int) $p['total_gross'])) ?></b></td>
          <td data-l="Stan">
            <?php if (!$p['frozen']): ?>
              <span class="st"><?= $enCours ? 'trwa' : 'niewystawione' ?></span>
            <?php else: ?>
              <span class="st <?= h($p['status']) ?>"><?= h($p['status']) ?></span>
            <?php endif; ?>
          </td>
          <td data-l="">
            <form method="post" style="display:inline">
              <input type="hidden" name="_t" value="<?= h($csrf) ?>">
              <?php if (!$p['frozen'] && !$enCours && (int) $p['total_gross'] > 0): ?>
                <button class="btn sm" name="wystaw" value="<?= h($p['ym']) ?>">Wystaw</button>
              <?php elseif (!$p['frozen'] && !$enCours): ?>
                <?php /* Ni vente ni loyer : il n'y a rien à facturer. Proposer
                          « Wystaw » produirait une note à 0 zł, c'est-à-dire du
                          bruit dans une suite de documents qui doit rester lisible. */ ?>
                <span style="color:var(--text-muted);font-size:12px">—</span>
              <?php elseif ($p['frozen'] && $p['status'] === 'wystawione'): ?>
                <button class="btn sm" name="oplacone" value="<?= h($p['ym']) ?>">Opłacone</button>
              <?php elseif ($p['frozen'] && $p['status'] === 'oplacone'): ?>
                <button class="btn sm ghost" name="cofnij" value="<?= h($p['ym']) ?>">Cofnij</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="panel">
    <h2>Warunki najmu</h2>
    <p class="why">
      Zapis nie nadpisuje poprzednich warunków — dopisuje nowe, obowiązujące od wskazanego
      miesiąca. Dzięki temu za dwa lata nadal widać, na jakich zasadach powstał każdy
      rachunek. Miesiąca już wystawionego nie da się objąć nowymi warunkami.
    </p>
    <form method="post">
      <input type="hidden" name="_t" value="<?= h($csrf) ?>">
      <input type="hidden" name="warunki" value="1">

      <div class="fld">
        <label for="rent">Czynsz miesięczny (netto, zł)</label>
        <input id="rent" name="rent_net" inputmode="decimal"
               class="<?= isset($errors['rent_net']) ? 'bad' : '' ?>"
               value="<?= h(number_format($terms['rent_net'] / 100, 2, '.', '')) ?>">
        <?php if (isset($errors['rent_net'])): ?><span class="err"><?= h($errors['rent_net']) ?></span><?php endif; ?>
      </div>

      <div class="fld">
        <label for="rate">Prowizja (%)</label>
        <input id="rate" name="rate" inputmode="decimal"
               class="<?= isset($errors['rate']) ? 'bad' : '' ?>"
               value="<?= h(rtrim(rtrim(number_format($terms['rate'] * 100, 2, '.', ''), '0'), '.')) ?>">
        <?php if (isset($errors['rate'])): ?><span class="err"><?= h($errors['rate']) ?></span><?php endif; ?>
      </div>

      <div class="fld">
        <label for="basis">Podstawa prowizji</label>
        <select id="basis" name="basis">
          <?php foreach (WSM_PLAT_BASES as $k => $lbl): ?>
            <option value="<?= h($k) ?>"<?= $terms['basis'] === $k ? ' selected' : '' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fld">
        <label for="vat">VAT na rozliczeniu (%)</label>
        <input id="vat" name="vat_rate" inputmode="decimal"
               class="<?= isset($errors['vat_rate']) ? 'bad' : '' ?>"
               value="<?= h(rtrim(rtrim(number_format($terms['vat_rate'] * 100, 2, '.', ''), '0'), '.')) ?>">
        <?php if (isset($errors['vat_rate'])): ?><span class="err"><?= h($errors['vat_rate']) ?></span><?php endif; ?>
      </div>

      <div class="fld">
        <label for="from">Obowiązuje od (YYYY-MM)</label>
        <input id="from" name="from_ym" placeholder="<?= h(date('Y-m')) ?>"
               class="<?= isset($errors['from_ym']) ? 'bad' : '' ?>"
               value="<?= h((string) ($_POST['from_ym'] ?? date('Y-m'))) ?>">
        <?php if (isset($errors['from_ym'])): ?><span class="err"><?= h($errors['from_ym']) ?></span><?php endif; ?>
      </div>

      <div class="fld">
        <label for="note">Notatka</label>
        <input id="note" name="note" maxlength="255" value="">
      </div>

      <button class="btn primary" type="submit">Zapisz warunki</button>
    </form>

    <?php if ($histo): ?>
    <h3 style="margin-top:20px;font-size:14px">Historia warunków</h3>
    <ul class="hist">
      <?php foreach (array_slice($histo, 0, 8) as $t): ?>
      <li><b><?= h((string) $t['from_ym']) ?></b> —
        czynsz <?= h(pln((int) $t['rent_net'])) ?>,
        prowizja <?= h(pct((float) $t['rate'])) ?>
        (<?= h(WSM_PLAT_BASES[$t['basis']] ?? (string) $t['basis']) ?>),
        VAT <?= h(pct((float) $t['vat_rate'], 0)) ?>
        <?php if (($t['note'] ?? '') !== ''): ?><br><span style="opacity:.8"><?= h((string) $t['note']) ?></span><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>

<?php
// ============================================================================
//  L'ENREGISTREUR DE PAGES — ce qu'on mesure pour ranger la console ensuite.
//
//  Le rail a été rangé « par métier » d'après l'idée qu'on se faisait du
//  travail réel. Personne n'a jamais vérifié. Ces quatre tableaux disent ce
//  qui se passe vraiment, et chacun répond à une question qu'on ne peut pas
//  deviner en regardant le code.
//
//  Il n'y a NI nom NI adresse là-dedans, et ce n'est pas un oubli : on
//  enregistre l'écran et le rôle, jamais la personne ni la query string. Les
//  raisons sont écrites en tête de usage.php.
// ============================================================================
$uJours   = 30;
$uEcrans  = wsm_usage_par_ecran($pdo, $uJours);
$uRoles   = wsm_usage_par_role($pdo, $uJours);
$uChemins = wsm_usage_chemins($pdo, 10);
$uMorts   = wsm_usage_morts($pdo, console_menu($me), $uJours);
$uDepuis  = wsm_usage_depuis($pdo);
$uTotal   = array_sum(array_column($uEcrans, 'n'));
$uMax     = $uEcrans ? max(array_column($uEcrans, 'n')) : 0;
?>

<div class="panel" style="margin-top:20px">
  <h2>Użycie konsoli — <?= (int) $uJours ?> dni</h2>
  <p class="why">
    <?php if ($uDepuis === ''): ?>
      Rejestrator dopiero ruszył — nie ma jeszcze ani jednej odsłony. Wróć tu za kilka dni:
      dopóki nie ma pomiarów, <b>zero nic nie znaczy</b>.
    <?php else: ?>
      Zapisujemy <b>ekran</b>, <b>rolę</b> i <b>czas serwera</b> — nigdy osoby i nigdy adresu
      z parametrami (numery zamówień, wyszukiwane nazwiska). Pomiar od
      <?= h($uDepuis) ?>, <?= (int) $uTotal ?> odsłon.
      Zapisy osób pozostają tam, gdzie ich miejsce: w Audycie.
    <?php endif; ?>
  </p>

  <div class="split" style="margin-top:14px">
    <div>
      <h3 style="font-size:14px;margin:0 0 4px">Które ekrany pracują</h3>
      <?php if (!$uEcrans): ?>
        <p class="why" style="margin:0">Brak danych.</p>
      <?php else: ?>
      <table class="rwd">
        <thead><tr><th>Ekran</th><th class="num">Odsłony</th><th class="num">Śr. czas</th><th class="num">Najgorszy</th></tr></thead>
        <tbody>
          <?php foreach ($uEcrans as $e): ?>
          <tr>
            <td data-l="Ekran"><?= h($e['ekran']) ?>
              <?php // La barre se lit par rapport à l'écran le plus ouvert. ?>
              <i class="use-bar<?= $e['n'] * 8 < $uMax ? ' cold' : '' ?>"
                 style="width:<?= $uMax > 0 ? max(2, (int) round($e['n'] / $uMax * 100)) : 2 ?>%"></i></td>
            <td data-l="Odsłony" class="num"><?= (int) $e['n'] ?></td>
            <?php // Au-delà de 800 ms on ne « charge » plus une page, on attend. ?>
            <td data-l="Śr. czas" class="num<?= $e['ms_moy'] >= 800 ? ' slow' : '' ?>"><?= (int) $e['ms_moy'] ?> ms</td>
            <td data-l="Najgorszy" class="num"><?= (int) $e['ms_max'] ?> ms</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div>
      <h3 style="font-size:14px;margin:0 0 4px">Czego nikt nie otwiera</h3>
      <p class="why" style="margin:0">
        <?php // LA sortie la plus utile, et la seule qu'un tableau de compteurs
              // ne donne pas : un écran jamais ouvert ne produit aucune ligne,
              // donc ne s'affiche nulle part. On part du rail — ce qui est
              // LIVRÉ — et on retire ce qui a été vu. Six écrans livrés étaient
              // morts en production sans que personne le remarque. ?>
        Ekrany z menu, których w tym okresie nikt nie otworzył.
        Albo są niepotrzebne, albo nie da się ich znaleźć — jedno i drugie warto wiedzieć.
      </p>
      <?php if (!$uMorts): ?>
        <p class="why" style="margin:8px 0 0"><b>Każdy ekran był używany.</b></p>
      <?php else: ?>
        <ul class="dead">
          <?php foreach ($uMorts as $f => $label): ?>
          <li title="<?= h($f) ?>"><?= h($label) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <h3 style="font-size:14px;margin:18px 0 4px">Kto pracuje gdzie</h3>
      <?php if (!$uRoles): ?>
        <p class="why" style="margin:0">Brak danych.</p>
      <?php else: ?>
      <ul class="flow">
        <?php foreach ($uRoles as $r): ?>
        <li><span class="f"><?= h($r['rola'] !== '' ? $r['rola'] : '—') ?></span>
          <span class="n"><?= (int) $r['n'] ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

      <h3 style="font-size:14px;margin:18px 0 4px">Co po czym</h3>
      <p class="why" style="margin:0">
        <?php // C'est cette liste qui dira comment ranger le rail : deux écrans
              // qui s'enchaînent tout le temps devraient être voisins. ?>
        Najczęstsze przejścia. To one mówią, co powinno sąsiadować w menu.
      </p>
      <?php if (!$uChemins): ?>
        <p class="why" style="margin:8px 0 0">Brak danych.</p>
      <?php else: ?>
      <ul class="flow">
        <?php foreach ($uChemins as $c): ?>
        <li><span class="f"><?= h($c['skad']) ?> → <?= h($c['dokad']) ?></span>
          <span class="n"><?= (int) $c['n'] ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
// ============================================================================
//  LES PROFILS — DE DROIT ET DE FAIT, CÔTE À CÔTE.
//
//  Le tableau au-dessus dit ce que l'équipe FAIT. Celui-ci dit ce qu'elle a le
//  DROIT de faire. Les deux ne se ressemblent pas, et c'est l'écart qui vaut
//  qu'on les mette sur la même page.
//
//  Une matrice de droits ne se relit pas : elle s'écrit une fois, paraît
//  juste, et dérive au premier écran livré. Un droit EN TROP ne provoque
//  aucune erreur, aucune page blanche, aucune plainte — il ne se voit qu'après
//  un incident. Confronté aux mesures, il se voit AVANT.
//
//  Les deux profils complets figurent ici sans bouton : les montrer et
//  expliquer POURQUOI ils ne se touchent pas vaut mieux que les cacher, ce qui
//  ferait chercher où on les modifie.
// ============================================================================
$profs = wsm_profil_tableau($pdo, $dispo, 30);
$mesure = $uDepuis !== '';
?>

<?php $etatK = $kluczeEtat(); $okK = count(array_filter($etatK)); ?>
<div class="panel" style="margin-top:20px">
  <h2>Klucze integracji</h2>
  <p class="why">Wszystkie klucze w jednej tabeli — albo wklejone naraz z listy. Działa <?= $okK ?> z <?= count($etatK) ?> integracji.</p>
  <p style="display:flex;flex-wrap:wrap;gap:6px;margin:0 0 14px">
    <?php foreach (WSM_SETTINGS_KLUCZE as $g => $lbl): ?><span class="tag <?= !empty($etatK[$g]) ? 'ok' : 'bad' ?>"><?= h($lbl) ?></span><?php endforeach; ?>
  </p>
  <a class="btn btn--brand" href="superadmin.php?klucze=1">Otwórz tabelę kluczy</a>
</div>

<div class="panel" style="margin-top:20px">
  <h2>Profile — kto co otwiera</h2>
  <p class="why">
    Profil mówi, które ekrany ktoś otwiera i gdzie może zmieniać. Ekran spoza listy
    <b>nie znika tylko z menu</b> — strona sama się pilnuje i odpowiada odmową.
    <br>
    Kolumna <b>„Prawa bez użycia”</b> to sedno: prawo, z którego przez 30 dni nikt nie
    skorzystał, nie daje żadnego błędu i nikt się nie skarży. Widać je dopiero wtedy, gdy
    ktoś przejmie konto. <?php if (!$mesure): ?><b>Na razie nie ma pomiarów</b> — dopóki ich
    nie ma, „ani razu” nic nie znaczy.<?php endif; ?>
  </p>

  <table class="rwd" style="margin-top:6px">
    <thead><tr>
      <th>Profil</th><th class="num">Konta</th><th class="num">Otwiera</th>
      <th class="num">Używa (30 dni)</th><th class="num">Prawa bez użycia</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($profs as $p): ?>
      <tr>
        <td data-l="Profil">
          <span class="prof-nom"><?= h($p['rola']) ?></span>
          <?php if ($p['change']): ?><span class="st">zmieniony</span><?php endif; ?>
          <?php if ($p['custom']): ?><span class="st">własny</span><?php endif; ?>
          <?php if (!$p['modifiable']): ?><span class="st">wbudowany</span><?php endif; ?>
          <span class="prof-aide"><?= h($p['aide']) ?></span>
        </td>
        <td data-l="Konta" class="num"><?= (int) $p['comptes'] ?></td>
        <td data-l="Otwiera" class="num"><?= $p['tout'] ? 'wszystko' : count($p['droits']) ?></td>
        <td data-l="Używa (30 dni)" class="num"><?= count($p['uzywane']) ?></td>
        <td data-l="Prawa bez użycia" class="num">
          <?php // Sans mesure, « 12 droits dormants » se lit comme un reproche
                // alors qu'on n'a simplement rien mesuré. On se tait. ?>
          <?php if (!$mesure): ?><span style="color:var(--text-muted)">—</span>
          <?php elseif (!$p['nieuzywane']): ?><span style="color:var(--success)">0</span>
          <?php else: ?><span class="dormant" title="<?= h(implode(', ', array_keys($p['nieuzywane']))) ?>"><?= count($p['nieuzywane']) ?></span>
          <?php endif; ?>
        </td>
        <td data-l="">
          <?php if ($p['modifiable']): ?>
            <a class="btn ghost" href="superadmin.php?profil=<?= rawurlencode($p['rola']) ?>">Zmień</a>
          <?php else: ?>
            <?php // Pas un bouton grisé : une raison. Un bouton mort fait
                  // cliquer trois fois avant qu'on abandonne sans comprendre. ?>
            <span style="color:var(--text-muted);font-size:12px">otwiera konta — nietykalny</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php if ($mesure && $p['bez_prawa']): ?>
      <tr>
        <td colspan="6" style="font-size:12.5px;color:var(--warning)">
          <?php // L'autre sens de l'écart : ouvert, puis retiré. Normal juste
                // après une modification, anormal si personne n'a rien touché. ?>
          <b><?= h($p['rola']) ?></b> otwierał ostatnio ekrany, których już nie ma na liście:
          <?= h(implode(', ', array_keys($p['bez_prawa']))) ?>.
          Jeśli nikt nic nie zmieniał, warto sprawdzić dlaczego.
        </td>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="actions" style="margin-top:14px">
    <a class="btn btn--brand" href="superadmin.php?profil=%2B">Nowy profil</a>
  </div>
  <p class="why" style="margin:12px 0 0">
    <b>„Administrator” i „Superadmin” nie dają się zmienić</b>, i to nie jest niedoróbka:
    to one otwierają ekran kont. Jedno odznaczone pole i nikt — łącznie z tym, kto je
    odznaczył — nie wszedłby już do konsoli. Zostają drogą powrotną.
  </p>
</div>

<?php
// ============================================================================
//  STATUSY I WYZWALACZE — ce que chaque état de commande déclenche.
//
//  Un état n'est plus un mot depuis que « wysłane » émet un document fiscal,
//  l'envoie et le dépose au registre national. Ce qui se déclenche n'était
//  écrit nulle part : il fallait lire quatre fichiers pour le savoir, et
//  personne ne le faisait — on découvrait l'effet après l'avoir provoqué.
//
//  CE TABLEAU EST CALCULÉ, PAS RECOPIÉ. Chaque ligne interroge les sources qui
//  décident vraiment — les modèles de courrier en base, l'état du canal KSeF,
//  les données du vendeur. Une seconde liste tenue à la main aurait divergé au
//  premier changement, et une table de déclencheurs fausse est pire qu'aucune.
// ============================================================================
$trig = wsm_status_triggers($pdo);
?>

<div class="panel" style="margin-top:20px">
  <h2>Statusy zamówień i co wyzwalają</h2>
  <p class="why">
    Stan zamówienia nie jest już tylko słowem: <b>„Wysłane" wystawia dokument podatkowy</b>,
    wysyła go klientowi i zgłasza do KSeF. Poniżej — co dokładnie robi każdy stan, kto go
    ustawia, i czy klient dostaje wiadomość.
    <br>
    Tabela jest <b>liczona z kodu i z bazy</b>, nie przepisana ręcznie: gdyby ktoś wyłączył
    szablon albo zamknął kanał KSeF, widać to tutaj tego samego dnia.
    Same szablony zmienia się w <a href="poczta.php">Poczcie</a>.
  </p>
  <?php $reg = wsm_orders_cfg(); ?>
  <form method="post" style="margin:0 0 18px">
    <input type="hidden" name="_t" value="<?= h($csrf) ?>">
    <input type="hidden" name="wyzwalacze" value="1">
    <div class="cards">
      <div class="c<?= $reg['doc_status'] === 'nigdy' ? ' hot' : '' ?>">
        <h4>Który stan wystawia dokument</h4>
        <?php // Le <h4> de la carte n'est pas une étiquette : la synthèse
              // vocale annonçait « menu déroulant, Wysłane » sans dire de quoi
              // il s'agit — sur l'interrupteur qui décide quand un document
              // fiscal est émis. ?>
        <select name="doc_status" aria-label="Który stan zamówienia wystawia dokument">
          <?php foreach (['wyslane' => 'Wysłane (domyślnie)', 'oplacone' => 'Opłacone',
                          'dostarczone' => 'Dostarczone', 'nigdy' => 'Nigdy — tylko ręcznie'] as $k => $lbl): ?>
          <option value="<?= h($k) ?>"<?= $reg['doc_status'] === $k ? ' selected' : '' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <small>„Opłacone" wystawia wcześniej — ale zamówienie opłacone można jeszcze
          anulować, a dokumentu już nie cofniesz.</small>
      </div>
      <div class="c">
        <h4>Dokument mailem</h4>
        <label class="check"><input type="checkbox" name="doc_mail" value="1"
          <?= $reg['doc_mail'] ? ' checked' : '' ?>><span>wysyłaj do klienta</span></label>
        <small>Wyłączenie nie kasuje dokumentu — tylko go nie wysyła.</small>
      </div>
      <div class="c">
        <h4>KSeF</h4>
        <label class="check"><input type="checkbox" name="doc_ksef" value="1"
          <?= $reg['doc_ksef'] ? ' checked' : '' ?>><span>zgłaszaj faktury</span></label>
        <small>Wyłączone: faktura czeka w kolejce i wysyła się ręcznie. Paragon nigdy tam nie idzie.</small>
      </div>
      <div class="c<?= $reg['vies_recheck'] ? '' : ' hot' ?>">
        <h4>VIES przed wystawieniem</h4>
        <label class="check"><input type="checkbox" name="vies_recheck" value="1"
          <?= $reg['vies_recheck'] ? ' checked' : '' ?>><span>sprawdzaj ponownie</span></label>
        <small>Wyłączenie zostawia status z chwili zamówienia. Przy WDT to ryzyko podatkowe —
          numer mógł zostać w międzyczasie cofnięty.</small>
      </div>
    </div>
    <div class="actions"><button class="btn btn--brand" type="submit">Zapisz wyzwalacze</button></div>
    <?php // On ne cache pas ce qu'un interrupteur coupé implique : ces quatre
          // cases décident de ce qui part au fisc, et chaque changement est
          // tracé nominativement dans l'Audyt. ?>
    <p class="why" style="margin:10px 0 0">
      Każda zmiana tutaj trafia do <a href="audyt.php">Audytu</a> z nazwiskiem —
      „nikt nic nie ruszał" ma dać się sprawdzić, a nie tylko powiedzieć.
    </p>
  </form>

  <table class="rwd">
    <thead><tr><th>Stan</th><th>Kto go ustawia</th><th>Co wyzwala</th><th>Wiadomość do klienta</th></tr></thead>
    <tbody>
    <?php foreach ($trig as $t): ?>
      <tr>
        <td data-l="Stan"><span class="prof-nom"><?= h($t['statut']) ?></span></td>
        <td data-l="Kto go ustawia"><?= h($t['kto']) ?></td>
        <td data-l="Co wyzwala">
          <ul style="margin:0;padding-left:18px">
            <?php foreach ($t['wyzwala'] as $w): ?>
            <li<?= str_starts_with($w, 'UWAGA') ? ' style="color:var(--warning)"' : '' ?>><?= h($w) ?></li>
            <?php endforeach; ?>
          </ul>
        </td>
        <td data-l="Wiadomość do klienta">
          <?php // « Pas de modèle » n'est pas une panne : c'est un état muet,
                // voulu ou oublié. On le dit sans accuser, mais on le dit. ?>
          <span class="st<?= str_contains($t['mail'], 'czynny') ? ' oplacone' : '' ?>"><?= h($t['mail']) ?></span>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once $API . '/golive.php'; $gl = wsm_golive_compte($pdo);
      $glTotal = array_sum($gl); ?>
<div class="panel danger" style="margin-top:20px" id="zerowanie">
  <h2>Zerowanie przed startem</h2>
  <p class="why">
    Sklep w budowie zbiera setki zamówień testowych, ich dokumenty, ruchy magazynowe
    i listy. W dniu otwarcia te liczby nie są bezużyteczne — one <b>kłamią</b>: pulpit
    pokazuje obrót, którego nie było, faktury zaczynają się od FV/240 zamiast FV/1,
    a magazyn podaje stany z wrześniowej próby.
  </p>
  <p class="why">
    <b>Zostaje nietknięte wszystko, co opisuje sklep</b> — produkty, kategorie, marki,
    ceny, ustawienia, konta, treści, wzory listów, kraje, sposoby dostawy, kody rabatowe.
    <b>Znika wszystko, co opisuje działalność</b> — zamówienia, płatności, wysyłki,
    dokumenty, reklamacje, ruchy magazynowe, listy.
  </p>
  <p class="why">
    <b>Stany magazynowe schodzą do zera</b>, nie są zachowywane. Stan to wynik ruchów:
    kasując ruchy i zostawiając liczby, zostawilibyśmy wartość, której już nic nie
    tłumaczy. Zaczynasz od pustego magazynu i liczysz naprawdę — to jedyna uczciwa
    inwentaryzacja.
  </p>

  <?php // LE DECOMPTE AVANT LE GESTE. « To skasuje 552 zamówienia i 239 faktur »
        // fait reflechir ; « czy na pewno? » fait kliknąć. ?>
  <table class="rwd dense" style="max-width:520px;margin:14px 0">
    <thead><tr><th>Co zniknie</th><th class="num">Wierszy</th></tr></thead>
    <tbody>
    <?php // « stock niezerowy » n'est pas une table qu'on vide : c'est le
          // nombre de produits dont la quantite retombe a zero. Range sous le
          // meme titre sans un mot, il se lisait comme « 46 lignes effacees ».
          $etiq = ['stock_niezerowy' => 'produkty — stan wróci do zera']; ?>
    <?php foreach ($gl as $t => $n): if (!$n) continue; ?>
    <tr><td data-l="Co"><?= h($etiq[$t] ?? str_replace(['wsm_', '_'], ['', ' '], (string) $t)) ?></td>
        <td data-l="Wierszy" class="num"><?= (int) $n ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$glTotal): ?><tr><td class="muted" colspan="2">Nie ma czego czyścić — baza jest już pusta.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <?php if ($glTotal): ?>
  <form method="post" class="actions">
    <?= console_csrf_field() ?>
    <input type="text" name="potwierdz" autocomplete="off" spellcheck="false"
           placeholder="<?= h(WSM_GOLIVE_MOT) ?>" aria-label="Wpisz <?= h(WSM_GOLIVE_MOT) ?>, żeby potwierdzić" required>
    <button class="niebezpieczny" type="submit" name="zeruj" value="1">Wyczyść i zacznij od zera</button>
  </form>
  <p class="why" style="margin-top:10px">Operacja jest <b>nieodwracalna</b> i zapisuje się w dzienniku audytu.
    Wykonuje się w jednej transakcji: albo wszystko, albo nic.</p>
  <?php endif; ?>
</div>


<?php console_foot(); ?>
