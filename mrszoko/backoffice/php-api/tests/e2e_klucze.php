<?php
// ============================================================================
//  e2e_klucze.php — Superadmin : les clés de toutes les intégrations, dans
//  une table ou collées d'un bloc.
//
//   1. la liste : toutes les intégrations, et JAMAIS les clés de la maison
//      (base de données, jeton d'administration, code superadmin) ;
//   2. le collage : .env, « NOM: valeur », deux colonnes, PEM sur plusieurs
//      lignes, choix validés, noms inconnus écartés ;
//   3. l'enregistrement : la clé Claude devient active, un secret ne se relit
//      pas, un groupe hors intégration ne s'écrit pas ;
//   4. l'écran : derrière la porte du superadmin, sans redirection qui
//      porterait une valeur, l'audit ne garde que des NOMS.
//
//  Usage :  php tests/e2e_klucze.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/settings.php';
require_once dirname(__DIR__) . '/translate.php';
require_once dirname(__DIR__) . '/allegro.php';
$pdo = wsm_bootstrap();
$racine = dirname(__DIR__, 4);
$lire = fn(string $rel) => (string) @file_get_contents($racine . '/' . $rel);

echo "webshop_mrszoko — end-to-end klucze integracji (superadmin)\n\n";

// Ce que le test touche, remis en l'état à la fin.
$touche = ['anthropic_api_key', 'allegro.client_id', 'allegro.client_secret', 'allegro.sandbox', 'invoice.iban'];
$avant = [];
foreach ($touche as $k) {
    $st = $pdo->prepare("SELECT val, secret, updated_at, updated_by FROM wsm_settings WHERE cle = ?");
    $st->execute([$k]);
    $avant[$k] = $st->fetch() ?: null;
}
register_shutdown_function(function () use ($pdo, $avant) {
    foreach ($avant as $k => $r) {
        try {
            $pdo->prepare("DELETE FROM wsm_settings WHERE cle = ?")->execute([$k]);
            if ($r) $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,?,?,?)")
                        ->execute([$k, $r['val'], $r['secret'], $r['updated_at'], $r['updated_by']]);
        } catch (Throwable $e) { /* le nettoyage ne masque jamais le résultat */ }
    }
});

// ---- 1. La liste --------------------------------------------------------------------------------
echo "-- lista kluczy --\n";
$f = wsm_settings_fields();
ok('la clé Claude se règle depuis la console (secret)', ($f['anthropic_api_key'][4] ?? '') === 'secret' && ($f['anthropic_api_key'][3] ?? '') === 'WSM_ANTHROPIC_API_KEY');
ok('Allegro aussi : client, secret, jeton, vendeur, environnement', isset($f['allegro.client_id'], $f['allegro.client_secret'], $f['allegro.refresh_token'],
   $f['allegro.seller_id'], $f['allegro.sandbox']) && $f['allegro.client_secret'][4] === 'secret' && $f['allegro.refresh_token'][4] === 'secret');
$kf = wsm_settings_klucze_fields();
$envs = array_map(fn($d) => $d[3], $kf);
ok('la table couvre les 9 intégrations', array_keys(WSM_SETTINGS_KLUCZE) === ['claude', 'stripe', 'ksef', 'paragon', 'inpost', 'dpd', 'mail', 'sms', 'allegro']
   && count(array_unique(array_map(fn($d) => $d[0], $kf))) === 9);
ok('… avec tous les secrets connus (Stripe, KSeF, SMSAPI, Fakturownia, InPost, DPD, SMTP, Claude, Allegro)',
   !array_diff(['WSM_STRIPE_SECRET_KEY', 'WSM_STRIPE_WEBHOOK_SECRET', 'WSM_KSEF_TOKEN', 'WSM_SMSAPI_TOKEN', 'WSM_FAKTUROWNIA_TOKEN',
                'WSM_INPOST_TOKEN', 'WSM_DPD_PASSWORD', 'WSM_MAIL_SMTP_PASS', 'WSM_ANTHROPIC_API_KEY', 'WSM_ALLEGRO_CLIENT_SECRET'], $envs));
ok('JAMAIS les clés de la maison : base, jeton admin, superadmin', !array_intersect($envs, ['WSM_DB_PASS', 'WSM_DB_USER', 'WSM_ADMIN_TOKEN',
   'WSM_SUPERADMIN_EMAILS', 'WSM_SUPERADMIN_CODE']));
ok('ni les réglages qui ne sont pas des clés (IBAN, boutique, décision marketing)', !array_intersect($envs, ['WSM_INV_IBAN', 'WSM_SHOP_URL', 'WSM_MKT_KLIENCI_BEZ_ZGODY']));

// ---- 2. Le collage ------------------------------------------------------------------------------
echo "\n-- wklejanie --\n";
$bloc = "# Klucze od dostawców\n"
      . "WSM_ANTHROPIC_API_KEY=test-claude-1\n"
      . "export WSM_SMSAPI_TOKEN=\"test-sms-2\"\n"
      . "SMSAPI_NADAWCA: SzokoShop\n"
      . "allegro.client_id = test-allegro-id\n"
      . "allegro__sandbox\tnie\n"
      . "WSM_INPOST_TOKEN;test-inpost-3\n"
      . "WSM_KSEF_PUBLIC_KEY=-----BEGIN PUBLIC KEY-----\nAAAABBBB\n-----END PUBLIC KEY-----\n"
      . "WSM_SMSAPI_REGION=eu\n"
      . "WSM_DB_PASS=nie-wolno\nWSM_SUPERADMIN_CODE=nie-wolno\nWSM_ADMIN_TOKEN=nie-wolno\nWSM_INV_IBAN=PL00\n"
      . "\n// komentarz\n";
$p = wsm_settings_parse_paste($bloc);
$w = $p['wartosci'];
ok('.env, export entre guillemets, « NOM: », « = » espacé, tabulation, point-virgule', ($w['anthropic_api_key'] ?? '') === 'test-claude-1'
   && ($w['smsapi__token'] ?? '') === 'test-sms-2' && ($w['smsapi__nadawca'] ?? '') === 'SzokoShop' && ($w['allegro__client_id'] ?? '') === 'test-allegro-id'
   && ($w['inpost__token'] ?? '') === 'test-inpost-3', $w);
ok('« nie » devient 0 pour un oui/non', ($w['allegro__sandbox'] ?? '') === '0');
ok('une clé PEM sur plusieurs lignes est reprise entière', str_starts_with($w['ksef__public_key'] ?? '', '-----BEGIN PUBLIC KEY-----')
   && str_ends_with($w['ksef__public_key'] ?? '', '-----END PUBLIC KEY-----') && str_contains($w['ksef__public_key'] ?? '', 'AAAABBBB'));
ok('un choix invalide (région « eu ») est refusé, avec les choix permis', isset($p['bledne']['WSM_SMSAPI_REGION']) && !isset($w['smsapi__region']));
ok('base, code superadmin, jeton admin, IBAN : écartés comme « pas des clés d\'intégration »',
   !array_diff(['WSM_DB_PASS', 'WSM_SUPERADMIN_CODE', 'WSM_ADMIN_TOKEN', 'WSM_INV_IBAN'], $p['nieznane'])
   && !array_filter(array_keys($w), fn($k) => in_array($k, ['invoice__iban', 'db__pass'], true)));
ok('commentaires et lignes vides ignorés', !in_array('komentarz', $p['nieznane'], true) && count($w) === 7, array_keys($w));

// ---- 3. L'enregistrement -----------------------------------------------------------------------
echo "\n-- zapis --\n";
$refus = [];
$zmienione = wsm_settings_save($pdo, ['anthropic_api_key' => 'test-claude-1', 'allegro__client_id' => 'test-allegro-id',
                                      'allegro__client_secret' => 'test-allegro-secret', 'allegro__sandbox' => '1',
                                      'invoice__iban' => 'PL61109010140000071219812874'], 'test', $refus, array_keys(WSM_SETTINGS_KLUCZE));
ok('les clés d\'intégration s\'enregistrent ; l\'IBAN, hors groupe, non', in_array('anthropic_api_key', $zmienione, true)
   && in_array('allegro.client_secret', $zmienione, true) && !in_array('invoice.iban', $zmienione, true), $zmienione);
wsm_settings_apply($pdo);
ok('la clé Claude collée rend l\'IA disponible (traductions, newsletter, PDF)', wsm_tr_enabled());
ok('Allegro lit ses identifiants collés', wsm_allegro_cfg()['client_id'] === 'test-allegro-id' && wsm_allegro_cfg()['client_secret'] === 'test-allegro-secret');
$vue = wsm_settings_view($pdo);
ok('un secret ne se relit JAMAIS : l\'écran montre « •••• », pas la valeur', ($vue['anthropic_api_key']['show'] ?? '') === str_repeat('•', 12)
   && !str_contains(json_encode($vue, JSON_UNESCAPED_UNICODE), 'test-claude-1') && !str_contains(json_encode($vue, JSON_UNESCAPED_UNICODE), 'test-allegro-secret'));
ok('… et sa provenance : « baza »', ($vue['anthropic_api_key']['source'] ?? '') === 'baza');
$st = $pdo->prepare("SELECT secret FROM wsm_settings WHERE cle = ?"); $st->execute(['anthropic_api_key']);
ok('marqué secret en base', (int) $st->fetchColumn() === 1);
$avantNb = (int) $pdo->query("SELECT COUNT(*) FROM wsm_settings WHERE cle = 'anthropic_api_key' AND val = 'test-claude-1'")->fetchColumn();
wsm_settings_save($pdo, ['anthropic_api_key' => ''], 'test', $refus, array_keys(WSM_SETTINGS_KLUCZE));
ok('un champ secret laissé vide ne l\'efface pas', (int) $pdo->query("SELECT COUNT(*) FROM wsm_settings WHERE cle = 'anthropic_api_key' AND val = 'test-claude-1'")->fetchColumn() === $avantNb && $avantNb === 1);

// ---- 4. L'écran -----------------------------------------------------------------------------------
echo "\n-- ekran superadmina --\n";
$sa = $lire('mrszoko/backoffice/superadmin.php');
$porte = strpos($sa, "wsm_super_code_ok(");
$handler = strpos($sa, "isset(\$_POST['klucze_zapisz']) || isset(\$_POST['klucze_wklej'])");
$vueK = strpos($sa, "if (isset(\$_GET['klucze'])");
ok('la table et son enregistrement vivent DERRIÈRE la porte du superadmin', $porte !== false && $handler > $porte && $vueK > $porte);
$bloc = substr($sa, (int) $handler, 3800);
ok('l\'enregistrement n\'écrit que les groupes d\'intégration', str_contains($bloc, 'wsm_settings_save($pdo, $postK, $actor, $refusK, array_keys(WSM_SETTINGS_KLUCZE))'));
ok('pas de redirection : rien de collé ne passe dans une adresse', !str_contains($bloc, 'Location:'));
ok('l\'audit garde les NOMS des clés changées, pas les valeurs', str_contains($bloc, "wsm_audit(\$pdo, \$actor, 'Klucze integracji', mb_substr(implode(', ', \$zmienione)"));
ok('le bloc collé n\'est jamais réaffiché', !str_contains(substr($sa, (int) $vueK, 9000), "\$_POST['wklej']"));
ok('un secret s\'affiche en champ mot de passe, vide', str_contains($sa, 'type="password" name="<?= h((string) $v[\'form\']) ?>" autocomplete="new-password"'));
ok('lien depuis la page principale', str_contains($sa, 'href="superadmin.php?klucze=1"'));
ok('après enregistrement, la configuration est relue (pas de « brak » à côté d\'une clé posée)', str_contains($bloc, 'wsm_settings_apply($pdo);'));
$css = $lire('mrszoko/backoffice/console.css');
ok('console lisible : police système, texte 15 px, intitulés en minuscules, montants tabulaires',
   str_contains($css, "--bo-font: system-ui") && str_contains($css, 'font-size: 15px; line-height: 1.5;')
   && str_contains($css, 'td.num, th.num { font-family: var(--bo-font); font-variant-numeric: tabular-nums; }'));
$ust = $lire('mrszoko/backoffice/ustawienia.php');
ok('Integracje montre aussi Claude et Allegro, avec leur état', str_contains($ust, "'claude' => ['Claude (AI)") && str_contains($ust, "'allegro' => wsm_allegro_enabled()"));

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
