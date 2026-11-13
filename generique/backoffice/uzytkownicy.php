<?php
// ============================================================================
//  uzytkownicy.php — les comptes et les rôles.
//
//  L'écran de la console héritée listait les utilisateurs et ne pouvait rien
//  écrire : il n'existait aucune route d'écriture côté API. Changer un rôle
//  n'avait donc aucun effet. C'était le bug.
//
//  Ici, les règles d'auth.php sont appliquées telles quelles, plus trois
//  garde-fous qu'un écran de gestion de comptes doit avoir :
//
//   1. ON NE SE VERROUILLE PAS DEHORS. Impossible de se désactiver soi-même,
//      ni de retirer le dernier compte capable d'écrire et de se connecter.
//      Sans cette règle, un clic malheureux ferme la console à tout le monde
//      et il faut un accès SSH pour rentrer.
//   2. UN MOT DE PASSE NE SE RELIT PAS. On le pose, on ne l'affiche jamais.
//      Minimum dix caractères — le même plancher que le déploiement.
//   3. TOUT CHANGEMENT EST DANS L'AUDIT, avec l'auteur réel.
//
//  Lecture : tout compte dont le rôle ouvre cet écran. Écriture : Administrator
//  et Superadmin — et seul un Superadmin peut en désigner un autre.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();

$flash = ''; $kind = 'ok';

/**
 * Les comptes actifs qui peuvent réellement se connecter ET tout écrire.
 *
 * « Centrala » y figure encore : c'est l'ancien nom du rôle, et tant qu'une
 * base n'a pas été migrée, ce sont ces comptes-là qui tiennent la porte.
 * L'oublier ferait croire qu'il ne reste aucun administrateur, et bloquerait
 * une modification parfaitement saine.
 */
function admins_restants(PDO $pdo, int $exceptId = 0): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_users
                          WHERE act = 1 AND role IN (?, ?, 'Centrala')
                            AND password_hash IS NOT NULL AND password_hash <> ''
                            AND id <> ?");
    $st->execute([WSM_ROLE_ADMIN, WSM_ROLE_SUPERADMIN, $exceptId]);
    return (int) $st->fetchColumn();
}

// L'ECRAN LE PLUS SENSIBLE DE LA MAISON N'AVAIT PAS DE JETON. On y cree des
// comptes, on y pose des mots de passe, et on peut desormais y supprimer
// quelqu'un — le tout sur un simple POST. Une page piegee ouverte dans un
// autre onglet suffisait : le navigateur joint le cookie de session tout seul,
// et le journal d'audit aurait note le geste au nom de la victime.
$csrf = console_csrf();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!console_csrf_ok()) {
        http_response_code(400);
        exit('Bad request.');
    }
    if (!$isAdmin) {
        $flash = 'Twoja rola nie pozwala zmieniać kont.'; $kind = 'err';
    } else {
        $id    = (int) ($_POST['id'] ?? 0);
        $meId  = (int) ($me['id'] ?? 0);
        $st    = $pdo->prepare("SELECT * FROM wsm_users WHERE id = ?");
        $st->execute([$id]);
        $u = $st->fetch();

        if (isset($_POST['nowy'])) {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $pass  = (string) ($_POST['password'] ?? '');
            $nom   = trim((string) ($_POST['nom'] ?? ''));
            $role  = (string) ($_POST['role'] ?? WSM_ROLE_ADMIN);
            // Le rôle arrive du navigateur : on le repasse par la règle, pas
            // par la liste déroulante qui l'a proposé.
            if (!wsm_peut_donner_role($me, $role)) {
                $flash = 'Nie możesz nadać roli ' . $role . '.'; $kind = 'err'; $role = '';
            }
            try {
                if ($role === '') throw new InvalidArgumentException($flash);
                // La fonction renvoie un message d'exploitation en français ;
                // l'écran est en polonais, on formule donc le nôtre.
                $msg = wsm_set_password($pdo, $email, $pass, $role, $nom);
                $msg = str_contains($msg, 'créé')
                    ? 'Utworzono konto ' . $email . '.'
                    : 'Konto ' . $email . ' istniało — ustawiono nowe hasło.';
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Konto', $email . ' · ' . $role, 'Sieć');
                $flash = $msg;
            } catch (InvalidArgumentException $e) {
                $flash = $e->getMessage(); $kind = 'err';
            }
        } elseif (!$u) {
            $flash = 'Nie znaleziono konta.'; $kind = 'err';
        } elseif (isset($_POST['zapisz'])) {
            $role = (string) ($_POST['role'] ?? $u['role']);
            $act  = empty($_POST['act']) ? 0 : 1;
            $ecrit = in_array($role, [WSM_ROLE_ADMIN, WSM_ROLE_SUPERADMIN], true);

            // Les trois façons de fermer la porte derrière soi.
            if ($id === $meId && $act === 0) {
                $flash = 'Nie możesz wyłączyć własnego konta.'; $kind = 'err';
            } elseif ($role !== (string) $u['role'] && !wsm_peut_donner_role($me, $role)) {
                // Un Administrator ne se hisse pas Superadmin, et ne hisse
                // personne : la facturation de la plateforme se garde d'un
                // compte de la boutique, même compromis.
                $flash = 'Nie możesz nadać roli ' . $role . '.'; $kind = 'err';
            } elseif (($act === 0 || !$ecrit)
                      && in_array((string) $u['role'], [WSM_ROLE_ADMIN, WSM_ROLE_SUPERADMIN, 'Centrala'], true)
                      && admins_restants($pdo, $id) === 0) {
                $flash = 'To ostatnie konto z pełnym dostępem — po tej zmianie nikt nie wszedłby do konsoli.';
                $kind = 'err';
            } else {
                $pdo->prepare("UPDATE wsm_users SET nom = ?, role = ?, portee = ?, act = ? WHERE id = ?")
                    ->execute([
                        mb_substr(trim((string) ($_POST['nom'] ?? $u['nom'])), 0, 120),
                        $role,
                        // Le champ n'existe plus à l'écran : on garde ce que la
                        // colonne portait, plutôt que de l'effacer en silence.
                        (string) $u['portee'],
                        $act, $id,
                    ]);
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Zmiana konta',
                          (string) $u['email'] . ' · ' . $role . ($act ? '' : ' · wyłączone'), 'Sieć');
                $flash = 'Zapisano konto ' . $u['email'] . '.';
            }
        } elseif (isset($_POST['haslo'])) {
            try {
                wsm_set_password($pdo, (string) $u['email'], (string) ($_POST['password'] ?? ''),
                                 (string) $u['role'], (string) $u['nom']);
                // Le journal retient QUE le fait, jamais le mot de passe.
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Nowe hasło', (string) $u['email'], 'Sieć');
                $flash = 'Ustawiono nowe hasło dla ' . $u['email'] . '.';
            } catch (InvalidArgumentException $e) {
                $flash = $e->getMessage(); $kind = 'err';
            }
        } elseif (isset($_POST['przelacz'])) {
            // DESACTIVER D'UN BOUTON. C'etait deja possible — une case a cocher
            // au milieu du formulaire d'edition, qu'il fallait ensuite
            // enregistrer. Personne ne l'a trouvee. Le geste le plus courant
            // d'un ecran de comptes merite son propre bouton, avec les MEMES
            // garde-fous : ils sont ici, pas dans le formulaire.
            $act = (int) $u['act'] === 1 ? 0 : 1;
            if ($id === $meId && $act === 0) {
                $flash = 'Nie możesz wyłączyć własnego konta.'; $kind = 'err';
            } elseif ($act === 0
                      && in_array((string) $u['role'], [WSM_ROLE_ADMIN, WSM_ROLE_SUPERADMIN, 'Centrala'], true)
                      && admins_restants($pdo, $id) === 0) {
                $flash = 'To ostatnie konto z pełnym dostępem — po tej zmianie nikt nie wszedłby do konsoli.';
                $kind = 'err';
            } elseif (!wsm_peut_donner_role($me, (string) $u['role'])) {
                // On ne touche pas a un compte dont on ne pourrait pas donner
                // le role : sinon un Administrator eteindrait le Superadmin.
                $flash = 'Nie możesz zmieniać konta o roli ' . $u['role'] . '.'; $kind = 'err';
            } else {
                $pdo->prepare("UPDATE wsm_users SET act = ? WHERE id = ?")->execute([$act, $id]);
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), $act ? 'Włączenie konta' : 'Wyłączenie konta',
                          (string) $u['email'], 'Sieć');
                $flash = $act ? 'Konto ' . $u['email'] . ' włączone.'
                              : 'Konto ' . $u['email'] . ' wyłączone — nie zaloguje się, ale zostaje na liście.';
            }
        } elseif (isset($_POST['usun'])) {
            // ─── SUPPRIMER, ET SEULEMENT QUAND C'EST SANS RISQUE ──────────
            //
            // Desactiver suffit presque toujours : le compte ne se connecte
            // plus, et son nom reste lisible partout ou le journal d'audit le
            // cite. Supprimer sert au vrai cas — une adresse tapee de travers,
            // un compte cree par erreur, un prestataire parti pour de bon.
            //
            // MEME GARDE-FOUS QUE LA DESACTIVATION, plus un : on exige que
            // l'adresse soit RETAPEE. Un bouton « Usuń » a cote d'un bouton
            // « Zapisz » se clique par erreur ; une adresse ne se retape pas
            // par erreur.
            $conf = strtolower(trim((string) ($_POST['potwierdz'] ?? '')));
            if ($id === $meId) {
                $flash = 'Nie możesz usunąć własnego konta.'; $kind = 'err';
            } elseif (!wsm_peut_donner_role($me, (string) $u['role'])) {
                $flash = 'Nie możesz usunąć konta o roli ' . $u['role'] . '.'; $kind = 'err';
            } elseif (in_array((string) $u['role'], [WSM_ROLE_ADMIN, WSM_ROLE_SUPERADMIN, 'Centrala'], true)
                      && admins_restants($pdo, $id) === 0) {
                $flash = 'To ostatnie konto z pełnym dostępem — po usunięciu nikt nie wszedłby do konsoli.';
                $kind = 'err';
            } elseif ($conf !== strtolower((string) $u['email'])) {
                $flash = 'Wpisz adres konta, żeby potwierdzić usunięcie.'; $kind = 'err';
            } else {
                // LE JOURNAL D'ABORD. Ecrit apres la suppression, il pourrait
                // manquer si celle-ci echoue a mi-chemin — et un compte
                // disparu sans trace est exactement ce qu'un audit doit
                // empecher.
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Usunięcie konta',
                          (string) $u['email'] . ' · ' . (string) $u['role'], 'Sieć');
                $pdo->prepare("DELETE FROM wsm_users WHERE id = ?")->execute([$id]);
                $flash = 'Konto ' . $u['email'] . ' usunięte. Ślad został w dzienniku audytu.';
            }
        } elseif (isset($_POST['odblokuj'])) {
            $pdo->prepare("UPDATE wsm_users SET failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$id]);
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Odblokowanie', (string) $u['email'], 'Sieć');
            $flash = 'Odblokowano ' . $u['email'] . '.';
        }
    }
}

/**
 * Ce qu'un rôle ouvre, en toutes lettres. Repris du RAIL lui-même : une
 * seconde liste écrite ici mentirait le jour où un écran change de section,
 * et c'est précisément l'écran où l'on ne peut pas se permettre d'à-peu-près.
 */
function role_ouvre(string $role): array {
    $faux = ['email' => '', 'role' => $role];
    $out = [];
    foreach (console_sections($faux) as $items) {
        foreach ($items as $f => $label) {
            $out[] = $label . (wsm_droit_ecran($faux, $f) === 'r' ? ' (podgląd)' : '');
        }
    }
    return $out;
}

$users = $pdo->query("SELECT * FROM wsm_users ORDER BY act DESC, role, nom")->fetchAll() ?: [];
// Les rôles proposés, et RIEN de plus : « Superadmin » n'apparaît que dans la
// liste d'un Superadmin. Le refus vit aussi côté serveur
// (wsm_peut_donner_role) — une liste déroulante n'est pas un contrôle
// d'accès, elle se modifie dans le navigateur en quinze secondes.
$roles = [];
foreach (wsm_roles() as $r => $def) {
    if (wsm_peut_donner_role($me, $r)) $roles[$r] = (string) ($def['aide'] ?? $r);
}
$now = time();

console_head('Użytkownicy', $me, <<<'CSS'
  .why { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0 0 14px; }
  .usr { border-bottom: 1px solid var(--border-subtle); }
  .usr:last-child { border-bottom: 0; }
  .usr > summary { display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
                   padding: 12px 4px; cursor: pointer; list-style: none; }
  .usr > summary::-webkit-details-marker { display: none; }
  .usr > summary::after { content: "▾"; color: var(--text-muted); font-size: 12px; margin-left: auto; }
  .usr[open] > summary::after { content: "▴"; }
  .usr .nm { font-weight: 600; color: var(--text-strong); }
  .usr .em { font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); }
  .ouvre { font-size: 12.5px; color: var(--text-muted); line-height: 1.7; display: block; padding-top: 6px; }
  .usr .inner { padding: 6px 4px 20px; }
CSS);
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Użytkownicy' => null]);
?>

<div class="panel">
  <h2>Konta i role</h2>
  <p class="why">
    <b>Centrala</b> widzi i zmienia wszystko. <b>Franczyza</b> tylko czyta — otwiera każdy ekran,
    ale przyciski zapisu są dla niej wyłączone (to samo ograniczenie działa w API, nie tylko
    w przeglądarce). Konta bez hasła nie mogą się zalogować w ogóle.
    Nie da się wyłączyć własnego konta ani ostatniej Centrali: inaczej konsola zamknęłaby się
    dla wszystkich i trzeba by wchodzić przez SSH.
  </p>

  <?php foreach ($users as $u):
    $locked = !empty($u['locked_until']) && strtotime((string) $u['locked_until']) > $now;
    $noPass = ((string) ($u['password_hash'] ?? '')) === ''; ?>
  <details class="usr"<?= (int) $u['id'] === (int) ($_GET['id'] ?? 0) ? ' open' : '' ?>>
    <summary>
      <span>
        <span class="nm"><?= h((string) $u['nom']) ?></span>
        <?php if ((int) $u['id'] === (int) ($me['id'] ?? 0)): ?> <span class="tag">to Ty</span><?php endif; ?>
        <br><span class="em"><?= h((string) $u['email']) ?></span>
      </span>
      <span class="tag <?= in_array((string) $u['role'], [WSM_ROLE_ADMIN, WSM_ROLE_SUPERADMIN], true) ? 'ok' : '' ?>"><?= h((string) $u['role']) ?></span>
      <?php if (!$u['act']): ?><span class="tag bad">wyłączone</span><?php endif; ?>
      <?php if ($noPass): ?><span class="tag no">bez hasła</span><?php endif; ?>
      <?php if ($locked): ?><span class="tag bad">zablokowane</span><?php endif; ?>
    </summary>

    <div class="inner">
      <?php if ($locked): ?>
      <p class="warnbox">Konto zablokowane po nieudanych próbach do
        <?= h((string) $u['locked_until']) ?>. Blokada zniknie sama; możesz ją też zdjąć teraz.</p>
      <?php endif; ?>

      <form method="post">
        <?= console_csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
        <div class="grid2">
          <label class="field"><span>Imię i nazwisko</span>
            <input type="text" name="nom" value="<?= h((string) $u['nom']) ?>"<?= $isAdmin ? '' : ' disabled' ?>></label>
          <label class="field"><span>Rola</span>
            <select name="role"<?= $isAdmin ? '' : ' disabled' ?>>
              <?php foreach ($roles as $r => $desc): ?>
              <option value="<?= h($r) ?>"<?= (string) $u['role'] === $r ? ' selected' : '' ?>><?= h($desc) ?></option>
              <?php endforeach; ?>
            </select></label>
          <?php // « Zakres » était un champ de texte libre qui ne filtrait RIEN :
                // on pouvait y écrire « tylko Wrocław » et croire que ça
                // restreignait quelque chose. Ce qui décide, c'est la rôle —
                // alors on montre ce qu'elle ouvre, au lieu d'un champ qui
                // fait semblant. ?>
          <label class="field"><span>Co otwiera ta rola</span>
            <span class="ouvre"><?= h(implode(' · ', role_ouvre((string) $u['role']))) ?: '—' ?></span></label>
          <label class="field" style="display:flex;gap:10px;align-items:center;margin-top:22px">
            <input type="checkbox" name="act" value="1"<?= $u['act'] ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>>
            <span style="margin:0;text-transform:none;letter-spacing:0;font-size:14px;color:var(--text-strong)">Konto aktywne</span>
          </label>
        </div>
        <dl class="kv" style="margin:6px 0 14px">
          <dt>Ostatnie logowanie</dt><dd><?= h((string) ($u['last_login'] ?? '')) ?: '—' ?></dd>
          <dt>Nieudane próby</dt><dd><?= (int) ($u['failed_attempts'] ?? 0) ?></dd>
        </dl>
        <div class="actions">
          <button class="primary" type="submit" name="zapisz" value="1"<?= $isAdmin ? '' : ' disabled' ?>>Zapisz</button>
          <?php if ($locked && $isAdmin): ?>
          <button type="submit" name="odblokuj" value="1">Odblokuj</button>
          <?php endif; ?>
          <?php // LE GESTE LE PLUS COURANT A SON PROPRE BOUTON. Il existait
                // deja — une case a cocher au milieu du formulaire, qu'il
                // fallait ensuite enregistrer — et personne ne l'a trouvee.
                // Il ne remplace pas la case : les deux ecrivent la meme
                // colonne, avec les memes garde-fous. ?>
          <?php if ($isAdmin && (int) $u['id'] !== (int) ($me['id'] ?? 0)): ?>
          <button type="submit" name="przelacz" value="1"><?= (int) $u['act'] ? 'Wyłącz konto' : 'Włącz konto' ?></button>
          <?php endif; ?>
        </div>
      </form>

      <?php // ─── SUPPRIMER : dernier recours, et il se merite ────────────────
            //
            // Desactiver suffit presque toujours — le compte ne se connecte
            // plus, et son nom reste lisible partout ou le journal le cite.
            // Supprimer sert au vrai cas : une adresse tapee de travers, un
            // compte cree par erreur, un prestataire parti pour de bon.
            //
            // L'ADRESSE SE RETAPE. Un bouton « Usuń » a cote d'un bouton
            // « Zapisz » se clique par erreur ; une adresse, non. Le champ est
            // la garde, pas une fenetre de confirmation — qu'on apprend a
            // valider sans lire. ?>
      <?php if ($isAdmin && (int) $u['id'] !== (int) ($me['id'] ?? 0)): ?>
      <details class="usun-konto">
        <summary>Usuń konto</summary>
        <p class="why">Wyłączenie wystarcza w większości przypadków: konto się nie zaloguje,
          a jego nazwa zostaje czytelna wszędzie tam, gdzie wymienia ją dziennik audytu.
          Usunięcie jest nieodwracalne. Sam fakt usunięcia zostaje w dzienniku.</p>
        <form method="post" class="actions">
        <?= console_csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <input type="text" name="potwierdz" autocomplete="off" spellcheck="false"
                 placeholder="<?= h((string) $u['email']) ?>" aria-label="Wpisz adres, żeby potwierdzić" required>
          <button class="niebezpieczny" type="submit" name="usun" value="1">Usuń bezpowrotnie</button>
        </form>
      </details>
      <?php endif; ?>

      <?php if ($isAdmin): ?>
      <h3>Nowe hasło</h3>
      <p class="why">Hasła nie da się odczytać — można je tylko ustawić na nowo.
        Minimum <?= WSM_PASSWORD_MIN ?> znaków. W dzienniku audytu zapisuje się sam fakt zmiany.</p>
      <form method="post" class="actions">
        <?= console_csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
        <input type="password" name="password" autocomplete="new-password" placeholder="nowe hasło" required>
        <button type="submit" name="haslo" value="1">Ustaw hasło</button>
      </form>
      <?php endif; ?>
    </div>
  </details>
  <?php endforeach; ?>
</div>

<?php if ($isAdmin): ?>
<div class="panel">
  <h2>Nowe konto</h2>
  <p class="why">Konto powstaje od razu z hasłem — konto bez hasła istnieje, ale nie może się
    zalogować, więc nikomu nie służy.</p>
  <form method="post">
        <?= console_csrf_field() ?>
    <input type="hidden" name="nowy" value="1">
    <div class="grid2">
      <label class="field"><span>E-mail</span>
        <input type="email" name="email" required autocomplete="off"></label>
      <label class="field"><span>Imię i nazwisko</span>
        <input type="text" name="nom"></label>
      <label class="field"><span>Rola</span>
        <select name="role">
          <?php foreach ($roles as $r => $desc): ?>
          <option value="<?= h($r) ?>"><?= h($desc) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label class="field"><span>Hasło (min. <?= WSM_PASSWORD_MIN ?> znaków)</span>
        <input type="password" name="password" autocomplete="new-password" required></label>

    </div>
    <div class="actions"><button class="primary" type="submit">Utwórz konto</button></div>
  </form>
</div>
<?php endif; ?>
<?php console_foot();
