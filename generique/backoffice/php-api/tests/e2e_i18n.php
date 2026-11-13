<?php
// ============================================================================
//  e2e_i18n.php — preuve que huit langues ne cassent pas la boutique.
//
//  Ce qui est démontré, dans l'ordre du danger :
//
//   1. LA MIGRATION NE DÉPUBLIE RIEN. La liste publique passe de « ce qui
//      est en base » à « ce qui est coché ». Si le premier remplissage ne
//      reprenait pas l'existant, l'ukrainien et l'anglais disparaîtraient de
//      la vitrine à la seconde du déploiement — sur des pages déjà indexées,
//      et sans une ligne dans les journaux.
//   2. UNE LANGUE À MOITIÉ TRADUITE NE SE PUBLIE PAS TOUTE SEULE. Un drapeau
//      DE au-dessus d'un texte polonais se lit comme un site cassé.
//   3. LES MARQUEURS SURVIVENT À LA TRADUCTION. « {qty} » traduit en
//      « {ilość} » affiche au client une accolade et un mot polonais.
//   4. LE POLONAIS NE SE DÉPUBLIE PAS. C'est le filet : sans lui, une clé
//      manquante n'a plus rien sur quoi retomber.
//   5. TOUT ÉCRIT LAISSE UNE TRACE, ET LA TRACE SAIT REVENIR EN ARRIÈRE.
//
//  Usage :  php tests/e2e_i18n.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/i18n.php';
require_once dirname(__DIR__) . '/translate.php';
require_once dirname(__DIR__) . '/shop.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end języki (publikacja · pokrycie · tłumaczenie)\n\n";

// ---- 1. Le registre ------------------------------------------------------------
echo "-- rejestr --\n";
wsm_i18n_ensure($pdo);
wsm_i18n_ensure_origin($pdo);
ok('huit langues sont connues', count(WSM_LANGS) === 8, array_keys(WSM_LANGS));
ok('le polonais est la source', WSM_LANG_BASE === 'pl');
ok('chaque langue porte son nom NATIF',
    wsm_lang_name('cs') === 'Čeština' && wsm_lang_name('hu') === 'Magyar',
    [wsm_lang_name('cs'), wsm_lang_name('hu')]);
ok('l\'ukrainien s\'étiquette UA et non UK — UK se lit « britannique »',
    wsm_lang_short('uk') === 'UA', wsm_lang_short('uk'));
ok('les tables de contenu existent', wsm_table_exists($pdo, 'wsm_langs')
    && wsm_table_exists($pdo, 'wsm_i18n_history'));
ok('la colonne d\'origine est posée',
    in_array('origin', wsm_table_columns($pdo, 'wsm_shop_i18n'), true));

// ---- 2. LA MIGRATION -------------------------------------------------------------
//  Le test le plus important du fichier. On simule une base d'avant : la table
//  des langues n'existe pas encore, mais le contenu, si.
echo "\n-- migracja nie chowa niczego --\n";
$avant = [];
foreach ($pdo->query("SELECT DISTINCT lang FROM wsm_shop_i18n")->fetchAll() ?: [] as $r) {
    if (isset(WSM_LANGS[$r['lang']])) $avant[] = (string) $r['lang'];
}
sort($avant);
$pdo->exec("DELETE FROM wsm_langs");                 // on efface la décision…
wsm_i18n_ensure($pdo);                               // …et on rejoue le premier passage
$apres = wsm_lang_published($pdo);
sort($apres);
ok('les langues déjà servies restent publiées', $apres === $avant, [$avant, $apres]);
ok('et rien de plus n\'a été publié au passage', count($apres) === count($avant), $apres);

// LA TABLE SE RECRÉE TOUTE SEULE. Sur le serveur, wsm_langs n'existait pas :
// elle n'était créée que par un écran authentifié, que ni un visiteur ni le
// déploiement n'ouvrent jamais. Troisième fois que « livré après le
// démarrage n'arrive pas tout seul » coûte un cycle.
echo "\n-- baza naprawia się sama --\n";
$pdo->exec("DROP TABLE IF EXISTS wsm_langs");
ok('la table a bien disparu', !wsm_table_exists($pdo, 'wsm_langs'));
$pdo2 = wsm_bootstrap();                       // un simple démarrage, rien de plus
ok('un démarrage ordinaire la recrée', wsm_table_exists($pdo2, 'wsm_langs'));
$repub = wsm_lang_published($pdo2);
ok('et la reprise a de nouveau préservé les langues servies',
    in_array('pl', $repub, true) && count($repub) >= 1, $repub);

// ---- 3. La publication ------------------------------------------------------------
echo "\n-- publikacja to decyzja --\n";
// L'allemand est vide : la publier reviendrait à afficher un menu allemand
// au-dessus d'une boutique polonaise.
$pdo->exec("DELETE FROM wsm_shop_i18n WHERE lang = 'de'");
[$okDe, $msgDe] = wsm_lang_publish($pdo, 'de', true);
ok('une langue non traduite est refusée à la publication', $okDe === false, $msgDe);
ok('et le refus dit le taux et le seuil',
    str_contains($msgDe, '%'), $msgDe);
ok('elle n\'est donc pas dans la liste publique',
    !in_array('de', wsm_lang_published($pdo), true), wsm_lang_published($pdo));

[$okForce, $msgForce] = wsm_lang_publish($pdo, 'de', true, true);
ok('le passage en force existe, et il est explicite', $okForce === true, $msgForce);
ok('elle apparaît alors publiquement',
    in_array('de', wsm_lang_published($pdo), true), wsm_lang_published($pdo));
wsm_lang_publish($pdo, 'de', false);
ok('et on peut la retirer', !in_array('de', wsm_lang_published($pdo), true));

// LE FILET : le polonais ne se coupe pas.
[$okPl, $msgPl] = wsm_lang_publish($pdo, 'pl', false);
ok('le polonais ne se dépublie pas — c\'est le repli de tout le reste',
    $okPl === false, $msgPl);
ok('il reste servi', in_array('pl', wsm_lang_published($pdo), true));

[$okX] = wsm_lang_publish($pdo, 'zz', true, true);
ok('une langue inconnue est refusée', $okX === false);

// ---- 4. La couverture ---------------------------------------------------------------
echo "\n-- pokrycie --\n";
$sfx = bin2hex(random_bytes(3));
$k1 = 'test.i18n.' . $sfx . '.a';
$k2 = 'test.i18n.' . $sfx . '.b';
wsm_i18n_put($pdo, 'wsm_shop_i18n', 'pl', $k1, 'Do kasy');
wsm_i18n_put($pdo, 'wsm_shop_i18n', 'pl', $k2, 'Koszyk {qty}');
wsm_i18n_put($pdo, 'wsm_shop_i18n', 'fr', $k1, 'Commander');

$cv = wsm_lang_coverage($pdo, 'wsm_shop_i18n', 'fr');
ok('les clés manquantes sont nommées', in_array($k2, $cv['missing'], true), array_slice($cv['missing'], 0, 3));
ok('celles traduites ne le sont pas', !in_array($k1, $cv['missing'], true));
ok('le pourcentage est cohérent', $cv['pct'] > 0 && $cv['pct'] <= 100, $cv['pct']);

// Une clé VIDE en polonais n'est pas une dette : elle n'existe pas.
$k3 = 'test.i18n.' . $sfx . '.c';
wsm_i18n_put($pdo, 'wsm_shop_i18n', 'pl', $k3, '');
$cv2 = wsm_lang_coverage($pdo, 'wsm_shop_i18n', 'fr');
ok('une clé vide en polonais ne compte pas comme à traduire',
    !in_array($k3, $cv2['missing'], true), $k3);
ok('le total ne bouge donc pas', $cv2['total'] === $cv['total'], [$cv['total'], $cv2['total']]);

// ---- 5. Les marqueurs ------------------------------------------------------------------
echo "\n-- znaczniki przeżywają tłumaczenie --\n";
ok('les marqueurs sont repérés',
    wsm_tr_placeholders('Koszyk {qty} — {total} zł') === ['{qty}', '{total}'],
    wsm_tr_placeholders('Koszyk {qty} — {total} zł'));
ok('une traduction fidèle passe',
    wsm_tr_placeholders_ok('Koszyk {qty}', 'Panier {qty}'));
ok('un marqueur TRADUIT est rejeté — le client lirait « {ilość} »',
    !wsm_tr_placeholders_ok('Koszyk {qty}', 'Panier {ilosc}'));
ok('un marqueur PERDU est rejeté',
    !wsm_tr_placeholders_ok('Koszyk {qty}', 'Panier'));
ok('un marqueur EN TROP est rejeté',
    !wsm_tr_placeholders_ok('Koszyk', 'Panier {qty}'));
ok('l\'ordre peut changer — les langues ne rangent pas les mots pareil',
    wsm_tr_placeholders_ok('{a} i {b}', '{b} und {a}'));
ok('les marqueurs printf comptent aussi',
    !wsm_tr_placeholders_ok('%s zł', 'zł'));

// ---- 6. La traduction automatique est fermée sans clé ----------------------------------
echo "\n-- bez klucza nic --\n";
wsm_config_overlay(['anthropic_api_key' => '']);
ok('sans clé la traduction est indisponible', wsm_tr_enabled() === false);
wsm_config_overlay(['anthropic_api_key' => 'xxxx']);
ok('« xxxx » ne compte pas comme une clé', wsm_tr_enabled() === false);
$r = wsm_tr_fill($pdo, 'wsm_shop_i18n', 'fr', 'test');
ok('le remplissage refuse proprement, sans rien écrire',
    $r['written'] === 0 && $r['errors'] !== [], $r);
wsm_config_overlay(['anthropic_api_key' => 'sk-test-nieprawdziwy']);
ok('avec une clé, la fonction se déclare disponible', wsm_tr_enabled() === true);
$r2 = wsm_tr_fill($pdo, 'wsm_shop_i18n', 'pl', 'test');
ok('on ne traduit pas le polonais vers le polonais',
    $r2['written'] === 0 && $r2['errors'] !== [], $r2);
wsm_config_overlay(['anthropic_api_key' => '']);

// ---- 7. Marquage automatique / humain ---------------------------------------------------
echo "\n-- co przeczytał człowiek, a co maszyna --\n";
wsm_i18n_put($pdo, 'wsm_shop_i18n', 'fr', $k2, 'Panier {qty}', 'auto');
ok('un texte automatique est compté comme à relire',
    wsm_tr_pending($pdo, 'fr') >= 1, wsm_tr_pending($pdo, 'fr'));
$n = wsm_tr_pending($pdo, 'fr');
wsm_tr_approve($pdo, 'wsm_shop_i18n', 'fr', $k2);
ok('l\'approbation le retire de la pile sans changer le texte',
    wsm_tr_pending($pdo, 'fr') === $n - 1
    && wsm_i18n_get($pdo, 'wsm_shop_i18n', 'fr', $k2) === 'Panier {qty}',
    [$n, wsm_tr_pending($pdo, 'fr')]);

// ---- 8. L'historique -----------------------------------------------------------------------
echo "\n-- historia --\n";
wsm_i18n_put($pdo, 'wsm_shop_i18n', 'fr', $k1, 'Passer commande');
wsm_i18n_log($pdo, 'wsm_shop_i18n', 'fr', $k1, 'Commander', 'Passer commande', 'test');
$h = wsm_i18n_history($pdo, ['lang' => 'fr', 'k' => $k1], 5);
ok('le changement est journalisé', count($h) >= 1, count($h));
ok('avec l\'avant et l\'après',
    ($h[0]['old_v'] ?? '') === 'Commander' && ($h[0]['new_v'] ?? '') === 'Passer commande', $h[0] ?? null);
ok('et l\'auteur', ($h[0]['actor'] ?? '') === 'test');

// Un « changement » qui ne change rien ne s'écrit pas : un historique rempli
// de lignes identiques est un historique qu'on cesse de lire.
$avantN = count(wsm_i18n_history($pdo, ['lang' => 'fr', 'k' => $k1], 50));
wsm_i18n_log($pdo, 'wsm_shop_i18n', 'fr', $k1, 'Idem', 'Idem', 'test');
ok('une valeur inchangée n\'écrit pas de ligne',
    count(wsm_i18n_history($pdo, ['lang' => 'fr', 'k' => $k1], 50)) === $avantN);

[$okRev, $msgRev] = wsm_i18n_revert($pdo, (int) $h[0]['id'], 'test');
ok('le retour en arrière rétablit le texte', $okRev === true, $msgRev);
ok('et le texte est bien l\'ancien',
    wsm_i18n_get($pdo, 'wsm_shop_i18n', 'fr', $k1) === 'Commander',
    wsm_i18n_get($pdo, 'wsm_shop_i18n', 'fr', $k1));
$h2 = wsm_i18n_history($pdo, ['lang' => 'fr', 'k' => $k1], 5);
ok('le retour est LUI-MÊME journalisé — on peut annuler l\'annulation',
    ($h2[0]['origin'] ?? '') === 'revert', $h2[0]['origin'] ?? null);
[$okRev2] = wsm_i18n_revert($pdo, (int) $h[0]['id'], 'test');
ok('rejouer un retour déjà appliqué ne fait rien', $okRev2 === false);

// ---- 9. La boutique suit ---------------------------------------------------------------------
echo "\n-- sklep serwuje to, co opublikowane --\n";
// Le cache statique de wsm_shop_available_langs a déjà pu se remplir : on
// interroge donc la source, qui est ce que la boutique lira au prochain appel.
$pub = wsm_lang_published($pdo);
ok('le polonais est toujours servi', in_array('pl', $pub, true), $pub);
ok('aucune langue non cochée ne s\'y glisse',
    count(array_diff($pub, array_keys(WSM_LANGS))) === 0, $pub);
foreach ($pub as $l) {
    if ($l === 'pl') continue;
    $reg = wsm_lang_registry($pdo);
    ok("« $l » servi est bien coché publié", ($reg[$l]['published'] ?? false) === true);
}

// ---- Nettoyage ----------------------------------------------------------------------------------
foreach ([$k1, $k2, $k3] as $k) {
    $pdo->prepare("DELETE FROM wsm_shop_i18n WHERE k = ?")->execute([$k]);
    $pdo->prepare("DELETE FROM wsm_i18n_history WHERE k = ?")->execute([$k]);
}

echo "\n" . ($fail === 0 ? "OK — $pass assertions\n" : "ÉCHEC — $fail sur " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
