<?php
// ============================================================================
//  e2e_campaign.php — les envois groupés, et les garde-fous qui empêchent
//  qu'ils coûtent la boutique.
//
//  Ce qui est démontré, dans l'ordre de ce que ça casse :
//
//   1. RIEN NE PART VERS SMTP. Les messages entrent en FILE. Cent messages
//      poussés d'un coup depuis une IP qui n'en envoie jamais coûtent la
//      réputation du domaine — et avec elle, les confirmations de commande.
//   2. ON N'ÉCRIT QU'À CEUX QUI ONT ACHETÉ. Une adresse sans commande payée
//      n'a pas de relation commerciale avec nous.
//   3. LE REFUS PRIME SUR TOUT, et il survit à la campagne suivante.
//   4. UN ENVOI PART UNE SEULE FOIS. Recevoir deux fois la même offre fait
//      se désabonner ceux qui allaient acheter.
//   5. CHAQUE MESSAGE PORTE SON LIEN DE SORTIE. Sans lui l'envoi est
//      illégal, pas seulement impoli — et le lien est ajouté par le code,
//      pas laissé à la rédaction.
//   6. LE JETON DE DÉSABONNEMENT NE VAUT QUE POUR SON ADRESSE.
//
//  Usage :  php tests/e2e_campaign.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/campaign.php';
require_once dirname(__DIR__) . '/mail.php';
$pdo = wsm_bootstrap();

// LE TRANSPORT EST REMPLACÉ PAR UN COMPTEUR : ce test doit prouver que RIEN
// ne part, et un transport réel rendrait la preuve impossible à faire.
$envois = 0;
wsm_mail_transport(function (array $m) use (&$envois) { $envois++; return [true, '']; });
wsm_camp_ensure($pdo);

echo "webshop_mrszoko — end-to-end kampanie\n\n";

$sfx = bin2hex(random_bytes(3));
register_shutdown_function(function () use ($pdo, $sfx) {
    try {
        $pdo->exec("DELETE FROM wsm_messages WHERE event_key LIKE 'camp-%' AND email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_messages WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_campaigns WHERE nom LIKE 'test-$sfx%'");
        $pdo->exec("DELETE FROM wsm_clients WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE order_id IN
                      (SELECT id FROM wsm_orders WHERE email LIKE '%$sfx@example.com')");
        $pdo->exec("DELETE FROM wsm_orders WHERE email LIKE '%$sfx@example.com'");
        $pdo->exec("DELETE FROM wsm_stock_moves WHERE product_id LIKE 'test-cp-$sfx%'");
        $pdo->exec("DELETE FROM wsm_order_items WHERE product_id LIKE 'test-cp-$sfx%'");
        $pdo->exec("DELETE FROM wsm_products WHERE id LIKE 'test-cp-$sfx%'");
    } catch (Throwable $e) { /* le nettoyage ne masque jamais le résultat */ }
});

$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$pid = 'test-cp-' . $sfx;
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible,
                    slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, base_cost)
               VALUES (?,?,?,80.00,'Opublikowany',1,1,?,99,0.23,250,200,150,100,?,5.00)")
     ->execute([$pid, $cat, 'Camp ' . $sfx, $pid, strtoupper($sfx)]);

// Trois acheteurs payés, un impayé — et une adresse qui n'a jamais rien pris.
$cmd = function (string $email, string $paiement, string $quand) use ($pdo, $pid): int {
    $pdo->prepare("INSERT INTO wsm_orders (code, access_token, email, first_name, last_name, lang,
                        status, payment_status, items_net, items_gross, shipping_net, shipping_gross,
                        total_net, total_gross, delivery_method, created_at, paid_at)
                   VALUES (?,?,?,'Jan','Kowalski','pl','dostarczone',?,6504,8000,0,0,6504,8000,'inpost_courier',?,?)")
         ->execute(['MS-CP-' . strtoupper(bin2hex(random_bytes(3))), bin2hex(random_bytes(8)),
                    $email, $paiement, $quand, $paiement === 'oplacone' ? $quand : null]);
    $oid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO wsm_order_items (order_id, product_id, name, qty, unit_gross, unit_net,
                        vat_rate, line_net, line_vat, line_gross)
                   VALUES (?,?,?,1,8000,6504,0.23,6504,1496,8000)")->execute([$oid, $pid, 'Camp']);
    return $oid;
};

$recent = date('Y-m-d H:i:s', time() - 5 * 86400);
$vieux  = date('Y-m-d H:i:s', time() - 200 * 86400);
$acheteur = "kupil.$sfx@example.com";
$fidele   = "staly.$sfx@example.com";
$dormant  = "spiacy.$sfx@example.com";
$impaye   = "niezaplacil.$sfx@example.com";

$cmd($acheteur, 'oplacone', $recent);
foreach ([1, 2, 3] as $i) $cmd($fidele, 'oplacone', $recent);
$cmd($dormant, 'oplacone', $vieux);
$cmd($impaye, 'oczekuje', $recent);

// ---- 1. L'audience ---------------------------------------------------------------------
echo "-- kto dostanie wiadomość --\n";
$tous = array_column(wsm_camp_audience($pdo, 'klienci'), 'email');
ok('celui qui a payé est dans l\'audience', in_array($acheteur, $tous, true), count($tous));
ok('celui qui n\'a PAS payé n\'y est pas — pas de relation commerciale',
   !in_array($impaye, $tous, true), $tous);

$stali = array_column(wsm_camp_audience($pdo, 'stali'), 'email');
ok('le client à trois commandes est « stały »', in_array($fidele, $stali, true), $stali);
ok('celui à une seule commande ne l\'est pas', !in_array($acheteur, $stali, true));

$spiacy = array_column(wsm_camp_audience($pdo, 'spiacy'), 'email');
ok('celui qui n\'a rien acheté depuis 200 jours est « śpiący »', in_array($dormant, $spiacy, true), $spiacy);
ok('celui qui a acheté la semaine dernière ne l\'est pas', !in_array($acheteur, $spiacy, true));

ok('un segment inconnu ne rend personne', wsm_camp_audience($pdo, 'cokolwiek') === []);

// ---- 2. Le refus prime -------------------------------------------------------------------
echo "\n-- rezygnacja jest ważniejsza od wszystkiego --\n";
[$okStop, $mStop] = wsm_camp_stop($pdo, $acheteur, 'test');
ok('le désabonnement réussit', $okStop === true, $mStop);
$tous2 = array_column(wsm_camp_audience($pdo, 'klienci'), 'email');
ok('l\'adresse sort de TOUTES les audiences', !in_array($acheteur, $tous2, true), $tous2);
ok('mais les autres restent', in_array($fidele, $tous2, true));
ok('une adresse invalide est refusée', wsm_camp_stop($pdo, 'pas-une-adresse')[0] === false);
// La fiche SUBSISTE : effacer ferait réapparaître l'adresse au prochain achat.
$st = $pdo->prepare("SELECT no_mailing FROM wsm_clients WHERE LOWER(email) = ?");
$st->execute([$acheteur]);
ok('la fiche subsiste et porte le refus', (int) $st->fetchColumn() === 1);

// ---- 3. Les bornes à la saisie -------------------------------------------------------------
echo "\n-- granice przy zapisie --\n";
ok('un segment inconnu est refusé',
   wsm_camp_create($pdo, ['segment' => 'nigdzie', 'sujet' => 'Test', 'corps' => str_repeat('x', 40)], 't')[0] === 0);
ok('un objet vide est refusé',
   wsm_camp_create($pdo, ['segment' => 'klienci', 'sujet' => '', 'corps' => str_repeat('x', 40)], 't')[0] === 0);
[$idCourt, $mCourt] = wsm_camp_create($pdo, ['segment' => 'klienci', 'sujet' => 'Nowość', 'corps' => 'za krótko'], 't');
ok('un corps de trois mots est refusé', $idCourt === 0, $mCourt);

[$cid, $mC] = wsm_camp_create($pdo, [
    'nom' => 'test-' . $sfx, 'segment' => 'stali', 'sujet' => 'Nowa czekolada w sklepie',
    'corps' => "Dzień dobry {{imie}},\n\nmamy nową tabliczkę — 72 % z Ghany. Zapraszamy.",
], 'test');
ok('une campagne complète est créée', $cid > 0, $mC);
ok('et le message annonce le nombre de destinataires', str_contains($mC, 'Odbiorców'), $mC);

// ---- 4. RIEN NE PART VERS SMTP -------------------------------------------------------------
echo "\n-- nic nie idzie prosto do SMTP --\n";
$envois = 0;
$r = wsm_camp_send($pdo, $cid, 'https://example.pl/mrszoko/shop', 'test');
ok('des messages sont mis en file', $r['files'] >= 1, $r);
ok('MAIS AUCUN n\'a été remis à un transport', $envois === 0, $envois);
$st = $pdo->prepare("SELECT COUNT(*) FROM wsm_messages WHERE event_key LIKE ? AND status = 'kolejka'");
$st->execute(['camp-' . $cid . '-%']);
ok('ils sont bien en « kolejka »', (int) $st->fetchColumn() === $r['files']);
ok('et le message d\'écran explique pourquoi', str_contains($r['message'], 'reputacj'), $r['message']);

// ---- 5. Un envoi part une seule fois --------------------------------------------------------
echo "\n-- wysyłka idzie tylko raz --\n";
$r2 = wsm_camp_send($pdo, $cid, 'https://example.pl/mrszoko/shop', 'test');
ok('un second envoi ne met rien de plus en file', $r2['files'] === 0, $r2);
ok('et le dit', str_contains($r2['message'], 'już'), $r2['message']);
$st->execute(['camp-' . $cid . '-%']);
ok('le nombre de messages n\'a pas bougé', (int) $st->fetchColumn() === $r['files']);

// ---- 6. Chaque message porte son lien de sortie -----------------------------------------------
echo "\n-- każdy list niesie swój link wypisu --\n";
$st = $pdo->prepare("SELECT email, body FROM wsm_messages WHERE event_key LIKE ? LIMIT 5");
$st->execute(['camp-' . $cid . '-%']);
$avecLien = 0; $total = 0; $bonJeton = 0;
foreach ($st->fetchAll() ?: [] as $m) {
    $total++;
    if (str_contains((string) $m['body'], 'stop=')) $avecLien++;
    $jeton = wsm_camp_stop_token((string) $m['email']);
    if (str_contains((string) $m['body'], $jeton)) $bonJeton++;
}
ok('tous les messages portent un lien de désabonnement', $total > 0 && $avecLien === $total, [$avecLien, $total]);
ok('et chacun porte SON jeton, pas celui du voisin', $bonJeton === $total, [$bonJeton, $total]);

// ---- 7. Le jeton ne vaut que pour son adresse -------------------------------------------------
echo "\n-- token działa tylko na swój adres --\n";
$a = "ala.$sfx@example.com"; $b = "bob.$sfx@example.com";
ok('le bon jeton passe', wsm_camp_stop_ok($a, wsm_camp_stop_token($a)) === true);
ok('celui du voisin ne passe pas', wsm_camp_stop_ok($a, wsm_camp_stop_token($b)) === false);
ok('un jeton inventé non plus', wsm_camp_stop_ok($a, str_repeat('0', 32)) === false);
ok('la casse de l\'adresse ne change rien',
   wsm_camp_stop_ok(strtoupper($a), wsm_camp_stop_token($a)) === true);

// ---- 8. L'exemplaire de test -------------------------------------------------------------------
echo "\n-- próbka do siebie --\n";
[$okT, $mT] = wsm_camp_test($pdo, $cid, "probka.$sfx@example.com", 'https://example.pl/mrszoko/shop', 'test');
ok('la copie de test part en file', $okT === true, $mT);
ok('son objet est marqué [PRÓBKA]',
   (bool) $pdo->query("SELECT COUNT(*) FROM wsm_messages WHERE subject LIKE '[PRÓBKA]%'")->fetchColumn());
ok('une adresse invalide est refusée', wsm_camp_test($pdo, $cid, 'zzz', 'https://x', 't')[0] === false);
// Elle N'EST PAS idempotente : on doit pouvoir se la renvoyer après correction.
$avant = (int) $pdo->query("SELECT COUNT(*) FROM wsm_messages WHERE subject LIKE '[PRÓBKA]%'")->fetchColumn();
wsm_camp_test($pdo, $cid, "probka.$sfx@example.com", 'https://example.pl/mrszoko/shop', 'test');
$apres = (int) $pdo->query("SELECT COUNT(*) FROM wsm_messages WHERE subject LIKE '[PRÓBKA]%'")->fetchColumn();
ok('on peut se la renvoyer autant de fois qu\'on corrige', $apres > $avant, [$avant, $apres]);

// ---- 9. Le compte de la campagne ----------------------------------------------------------------
echo "\n-- ile kampania dała --\n";
$vu = null;
foreach (wsm_camp_list($pdo) as $c) if ((int) $c['id'] === $cid) $vu = $c;
ok('la campagne est en liste', $vu !== null);
ok('avec son état', $vu && (string) $vu['statut'] === 'wyslana', $vu['statut'] ?? null);
ok('le nombre envoyé est retenu', $vu && (int) $vu['wyslane'] === $r['files'], $vu['wyslane'] ?? null);
ok('le segment est écrit en clair', $vu && str_contains((string) $vu['segment_label'], 'Stali'), $vu['segment_label'] ?? null);

// ---- 10. Le corps personnalisé ------------------------------------------------------------------
echo "\n-- treść spersonalizowana --\n";
$camp = wsm_camp_get($pdo, $cid);
$corps = wsm_camp_body($camp, ['email' => $a, 'nom' => 'Ala'], 'https://example.pl/mrszoko/shop');
ok('le prénom remplace le marqueur', str_contains($corps, 'Ala') && !str_contains($corps, '{{imie}}'), substr($corps, 0, 60));
ok('le lien de sortie est ajouté par le CODE, pas par la rédaction',
   str_contains($corps, 'stop=') && !str_contains((string) $camp['corps'], 'stop='));

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
