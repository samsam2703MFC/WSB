<?php
// ============================================================================
//  e2e_dpd.php — le second transporteur.
//
//  CE QUE L'ARRIVÉE D'UN SECOND TRANSPORTEUR CASSE, ET QUE PERSONNE NE VOIT.
//
//  Tant qu'il n'y en avait qu'un, quatorze endroits pouvaient écrire
//  « delivery_method === 'inpost_locker' » pour dire « il faut un code de
//  point », et « === 'inpost_courier' » pour dire « il faut une adresse ».
//  Les deux tests marchaient, et ils étaient FAUX : ils décrivaient un nom, pas
//  un service. Ajoutez DPD, et les deux répondent non — donc :
//
//    · la caisse n'exige plus RIEN : ni code, ni adresse. Une commande part
//      payée, sans destination, et le défaut ne se voit qu'à l'expédition ;
//    · le formulaire montre le champ « Paczkomat » à qui a choisi le coursier ;
//    · la file d'expédition présente une commande DPD à l'API d'InPost, qui la
//      refuse pour une raison qui n'a aucun rapport avec ce qui cloche.
//
//  D'où les trois choses vérifiées ici, dans cet ordre :
//
//   1. LE TYPE DE SERVICE EST UNE DONNÉE ('punkt' | 'adres'), lue dans la
//      table — pas devinée d'un nom de méthode.
//   2. LA FILE PARLE AU BON TRANSPORTEUR, et un transporteur inconnu ne se
//      présente JAMAIS comme prêt à partir.
//   3. SANS IDENTIFIANTS, RIEN NE PART — et ce qui manque est NOMMÉ, y compris
//      l'extension PHP soap, parce que l'API de DPD Polska est du SOAP et
//      qu'une extension absente arrête tout aussi sûrement qu'un mot de passe.
//
//  Usage :  php tests/e2e_dpd.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/dpd.php';
require_once dirname(__DIR__) . '/inpost.php';
require_once dirname(__DIR__) . '/shipping.php';
$pdo = wsm_bootstrap();

echo "webshop_mrszoko — end-to-end DPD\n\n";

// ---- 1. La méthode est livrée, et elle est cohérente ----------------------
echo "-- metoda dostawy jest w bazie i trzyma się kupy --\n";
$st = $pdo->query("SELECT id, carrier, kind, active FROM wsm_shipping_methods WHERE id = 'dpd_courier'");
$m = $st->fetch();
ok('dpd_courier existe', (bool) $m, $m);
ok('son transporteur est dpd', ($m['carrier'] ?? '') === 'dpd', $m['carrier'] ?? null);
ok('et c\'est un service à l\'ADRESSE', ($m['kind'] ?? '') === 'adres', $m['kind'] ?? null);
// Le Paczkomat doit rester un point : c'est la migration qui le pose, et si
// elle n'a pas tourné, la caisse réclame une rue pour un casier.
ok('le Paczkomat est resté un POINT', wsm_ship_kind($pdo, 'inpost_locker') === 'punkt',
   wsm_ship_kind($pdo, 'inpost_locker'));
ok('le coursier InPost est une adresse', wsm_ship_kind($pdo, 'inpost_courier') === 'adres');
ok('les libellés sont traduits, pas la clé',
   (wsm_shop_strings($pdo, 'pl')['ship.dpd_courier.label'] ?? '') !== '',
   wsm_shop_strings($pdo, 'pl')['ship.dpd_courier.label'] ?? null);

// ---- 2. Le transporteur se lit dans la table, jamais dans le nom ----------
echo "\n-- przewoźnik czytany z tabeli, nie z nazwy --\n";
ok('dpd_courier → dpd', wsm_ship_carrier($pdo, 'dpd_courier') === 'dpd');
ok('inpost_locker → inpost', wsm_ship_carrier($pdo, 'inpost_locker') === 'inpost');
// Une méthode renommée en console ne doit pas changer d'API : c'est la ligne
// de la table qui décide, et le nom n'est qu'une étiquette.
$pdo->exec("INSERT INTO wsm_shipping_methods (id, carrier, kind, sort_order, active, price_net, vat_rate, free_from, max_weight_g, countries)
            VALUES ('nasza_dostawa', 'dpd', 'adres', 9, 0, 1000, 0.23, 0, 20000, 'PL')");
ok('une méthode au nom quelconque suit sa colonne carrier',
   wsm_ship_carrier($pdo, 'nasza_dostawa') === 'dpd', wsm_ship_carrier($pdo, 'nasza_dostawa'));
$pdo->exec("DELETE FROM wsm_shipping_methods WHERE id = 'nasza_dostawa'");

// ---- 3. Fermé tant qu'il manque quelque chose -----------------------------
echo "\n-- zamknięte, dopóki czegoś brakuje --\n";
wsm_config_overlay(['dpd' => ['login' => '', 'password' => '', 'fid' => '',
    'sender_name' => '', 'sender_address' => '', 'sender_city' => '', 'sender_postcode' => '']]);
ok('sans rien, le canal est fermé', wsm_dpd_enabled() === false);
$manque = wsm_dpd_manquants();
ok('et ce qui manque est nommé', count($manque) >= 4, $manque);
// « xxxx » est la marque de « pas encore renseigné » partout dans ce projet :
// la prendre pour un identifiant ouvrirait l'intégration sur du vent.
wsm_config_overlay(['dpd' => ['login' => 'xxxx', 'password' => 'xxxx', 'fid' => 'xxxx',
    'sender_name' => 'xxxx', 'sender_address' => 'xxxx', 'sender_city' => 'xxxx', 'sender_postcode' => 'xxxx']]);
ok('« xxxx » ne compte pas pour un identifiant', wsm_dpd_enabled() === false);
ok('et la configuration le voit vide', wsm_dpd_cfg()['login'] === '', wsm_dpd_cfg()['login']);

// L'extension PHP est dans la MÊME liste que les identifiants : pour qui
// regarde l'écran, « il manque le mot de passe » et « il manque soap » sont la
// même phrase — « ça ne partira pas tant que… ».
wsm_config_overlay(['dpd' => ['login' => 'sklep', 'password' => 'sekret', 'fid' => '1234',
    'sender_name' => 'Mister Szoko', 'sender_address' => 'Polna 1',
    'sender_city' => 'Wrocław', 'sender_postcode' => '50-001', 'sender_phone' => '600100200']]);
if (wsm_dpd_soap_ok()) {
    ok('avec tout, le canal est ouvert', wsm_dpd_enabled() === true, wsm_dpd_manquants());
} else {
    ok('sans l\'extension soap, le canal reste FERMÉ même avec les identifiants',
       wsm_dpd_enabled() === false);
    ok('et l\'extension manquante est NOMMÉE, pas devinée',
       in_array('rozszerzenie PHP soap', wsm_dpd_manquants(), true), wsm_dpd_manquants());
}

// ---- 4. La charge utile se construit même éteinte -------------------------
echo "\n-- ładunek buduje się nawet przy zamkniętym kanale --\n";
$cmd = [
    'id' => 999001, 'code' => 'MS-2026-0999', 'email' => 'k@example.com', 'phone' => '512340099',
    'first_name' => 'Jan', 'last_name' => 'Kowalski', 'company' => '',
    'delivery_method' => 'dpd_courier', 'inpost_point' => '', 'weight_g' => 1200,
    'ship' => ['street' => 'Kwiatowa', 'building' => '7', 'postcode' => '00-001',
               'city' => 'Warszawa', 'country' => 'PL'],
];
$p = wsm_dpd_payload($cmd);
ok('le destinataire porte la ville', ($p['openUMLFeV3']['packages'][0]['receiver']['city'] ?? '') === 'Warszawa');
ok('la rue et le numéro sont réunis comme DPD les attend',
   ($p['openUMLFeV3']['packages'][0]['receiver']['address'] ?? '') === 'Kwiatowa 7',
   $p['openUMLFeV3']['packages'][0]['receiver']['address'] ?? null);
ok('le code postal part sans tiret', ($p['openUMLFeV3']['packages'][0]['receiver']['postalCode'] ?? '') === '00001',
   $p['openUMLFeV3']['packages'][0]['receiver']['postalCode'] ?? null);
ok('le poids est en kilos', ($p['openUMLFeV3']['packages'][0]['parcels'][0]['weight'] ?? 0) === 1.2,
   $p['openUMLFeV3']['packages'][0]['parcels'][0]['weight'] ?? null);
ok('la référence est le code de commande',
   ($p['openUMLFeV3']['packages'][0]['ref1'] ?? '') === 'MS-2026-0999');
// Règle 4 : tout est encaissé par tpay AVANT l'expédition. Un COD qui
// s'inviterait ferait payer le client deux fois.
//
// PAS DE `??` ICI. L'opérateur rend la valeur par défaut aussi bien pour une
// clé ABSENTE que pour une clé à null : « ?? 'x' » ne peut donc jamais valoir
// null, et l'assertion était vraie quoi qu'il arrive. array_key_exists()
// distingue les deux, et c'est toute la question.
$svc = $p['openUMLFeV3']['packages'][0]['services'] ?? [];
ok('le champ COD est là et vaut null — dit, pas sous-entendu',
   array_key_exists('cod', $svc) && $svc['cod'] === null, $svc);
$json = json_encode($p, JSON_UNESCAPED_UNICODE);
ok('et aucun montant à encaisser ne traîne dans le ładunek',
   !preg_match('/"(cod|codAmount|collectOnDelivery)"\s*:\s*[0-9"]/', (string) $json));
ok('le port est à notre charge, pas à celle du client',
   ($p['openUMLFeV3']['packages'][0]['payerType'] ?? '') === 'SENDER');
ok('l\'expéditeur est renseigné — sans lui le colis ne revient jamais',
   ($p['openUMLFeV3']['packages'][0]['sender']['city'] ?? '') === 'Wrocław');

// ---- 5. Ce qui bloque, et dans le VOCABULAIRE COMMUN ----------------------
echo "\n-- co blokuje, i to wspólnym słownikiem --\n";
ok('une commande complète ne bloque sur rien', wsm_dpd_blockers($cmd) === [], wsm_dpd_blockers($cmd));
$sansAdr = $cmd; $sansAdr['ship']['city'] = '';
ok('sans ville, ça bloque', in_array('adres.city', wsm_dpd_blockers($sansAdr), true));
$sansTel = $cmd; $sansTel['phone'] = '';
ok('sans téléphone, ça bloque', in_array('telefon', wsm_dpd_blockers($sansTel), true));
$sansPoids = $cmd; $sansPoids['weight_g'] = 0;
ok('sans poids, ça bloque', in_array('waga', wsm_dpd_blockers($sansPoids), true));
// LE point : les codes sont les mêmes que ceux d'InPost, pour que l'écran les
// traduise sans savoir de quel transporteur il parle.
foreach (wsm_dpd_blockers($sansAdr) as $c) {
    ok("« $c » a un libellé humain", wsm_ship_blocker_label($c) !== $c, wsm_ship_blocker_label($c));
}

// ---- 6. La file parle au BON transporteur ---------------------------------
echo "\n-- kolejka rozmawia z właściwym przewoźnikiem --\n";
$a = wsm_ship_adapter($pdo, $cmd);
ok('une commande DPD est routée vers l\'adaptateur DPD',
   ($a['create'] ?? '') === 'wsm_dpd_create', $a['create'] ?? null);
$cmdI = $cmd; $cmdI['delivery_method'] = 'inpost_locker';
$aI = wsm_ship_adapter($pdo, $cmdI);
ok('une commande Paczkomat va toujours chez InPost',
   ($aI['create'] ?? '') === 'wsm_inpost_create', $aI['create'] ?? null);

// Un transporteur inconnu ne doit JAMAIS se présenter comme prêt : on la
// verrait échouer au moment du clic, sans que la file ait prévenu.
$cmdX = $cmd; $cmdX['delivery_method'] = 'poczta_polska';
ok('un transporteur inconnu n\'a pas d\'adaptateur', wsm_ship_adapter($pdo, $cmdX) === null);
ok('et la commande est signalée bloquée, pas prête',
   wsm_ship_blockers($pdo, $cmdX) === ['przewoznik'], wsm_ship_blockers($pdo, $cmdX));
ok('avec un libellé lisible', wsm_ship_blocker_label('przewoznik') !== 'przewoznik');
[$s, $err] = wsm_ship_create($pdo, $cmdX);
ok('et rien n\'est créé', $s === null && str_starts_with((string) $err, 'brak_przewoznika'), $err);
ok('le message d\'erreur nomme le problème en polonais',
   str_contains(wsm_ship_erreur_humaine((string) $err), 'przewoźnik'),
   wsm_ship_erreur_humaine((string) $err));

// ---- 7. La caisse exige ce qu'il FAUT, selon le service -------------------
echo "\n-- kasa wymaga tego, co trzeba dla danej usługi --\n";
$saisie = ['email' => 'k@example.com', 'phone' => '512340099',
           'first_name' => 'Jan', 'last_name' => 'Kowalski'];
// DPD : une adresse. Un code de Paczkomat n'a rien à faire là.
[, $eD] = wsm_validate_buyer($saisie, 'dpd_courier', false, wsm_ship_kind($pdo, 'dpd_courier'));
ok('DPD sans adresse → refus sur la rue', isset($eD['ship_street']), $eD);
ok('DPD ne réclame PAS de paczkomat', !isset($eD['inpost_point']), $eD);
$avecAdr = $saisie + ['ship_street' => 'Kwiatowa', 'ship_building' => '7',
                      'ship_postcode' => '00-001', 'ship_city' => 'Warszawa'];
[, $eD2] = wsm_validate_buyer($avecAdr, 'dpd_courier', false, wsm_ship_kind($pdo, 'dpd_courier'));
ok('DPD avec adresse complète → accepté', !isset($eD2['ship_street'], $eD2['inpost_point']), $eD2);
// Paczkomat : un code, pas une rue.
[, $eL] = wsm_validate_buyer($saisie, 'inpost_locker', false, wsm_ship_kind($pdo, 'inpost_locker'));
ok('Paczkomat sans code → refus sur le point', isset($eL['inpost_point']), $eL);
ok('Paczkomat ne réclame PAS de rue', !isset($eL['ship_street']), $eL);
// La longueur des colonnes : refusée SUR LE CHAMP, pas par la base. Un numéro
// de 42 signes pour une colonne de 30 faisait tomber toute la commande sous
// MySQL, et l'acheteur ne voyait aucun champ marqué.
[, $eLong] = wsm_validate_buyer(['ship_building' => '12/4 lok. 5, klatka B, wejście od podwórza', 'ship_city' => str_repeat('a', 121)] + $avecAdr,
                                  'dpd_courier', false, wsm_ship_kind($pdo, 'dpd_courier'));
ok('un numéro trop long est refusé sur son champ, avec la suite à faire', str_starts_with($eLong['ship_building'] ?? '', 'maks. 30 znaków') && str_contains($eLong['ship_building'], 'uwagach'), $eLong);
ok('… une ville trop longue aussi', ($eLong['ship_city'] ?? '') === 'maks. 120 znaków', $eLong);
[, $eOk] = wsm_validate_buyer(['ship_building' => '12/4 lok. 5'] + $avecAdr, 'dpd_courier', false, wsm_ship_kind($pdo, 'dpd_courier'));
ok('… un numéro normal passe', !isset($eOk['ship_building']), $eOk);
ok('le formulaire de la caisse borne chaque champ à sa colonne', str_contains((string) @file_get_contents(dirname(__DIR__, 3) . '/shop/index.php'), "' maxlength=\"' . (int) WSM_BUYER_MAX[\$name]"));

// ---- 8. Le suivi ----------------------------------------------------------
echo "\n-- śledzenie --\n";
$url = wsm_dpd_tracking_url('00123456789012');
ok('le lien de suivi est public et porte le numéro',
   str_starts_with($url, 'https://tracktrace.dpd.com.pl/') && str_contains($url, '00123456789012'), $url);
ok('un numéro vide ne fabrique pas un lien mort', wsm_dpd_tracking_url('') === '');
ok('le lien ne porte aucun identifiant',
   !str_contains($url, 'sekret') && !str_contains($url, 'sklep'), $url);

// ---- 9. Rien ne part sans configuration -----------------------------------
echo "\n-- bez konfiguracji nic nie wychodzi --\n";
wsm_config_overlay(['dpd' => ['login' => '', 'password' => '', 'fid' => '',
    'sender_name' => '', 'sender_address' => '', 'sender_city' => '', 'sender_postcode' => '']]);
// UNE VRAIE COMMANDE. wsm_shipments porte une clé étrangère vers wsm_orders :
// un identifiant inventé ne fait pas « échouer le test », il fait tomber la
// suite entière — et on ne teste plus rien du tout.
$sfx = substr(bin2hex(random_bytes(3)), 0, 6);
$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$pid = 'test-dpd-' . $sfx;
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, statut, active, shop_visible,
                    slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku, base_cost)
               VALUES (?,?,?,90.00,'Opublikowany',1,1,?,99,0.23,300,200,150,100,?,5.00)")
     ->execute([$pid, $cat, 'DPD ' . $sfx, $pid, strtoupper($sfx)]);
[$vraie, $errC] = wsm_shop_create_order($pdo, [
    'items' => [['id' => $pid, 'qty' => 1]], 'lang' => 'pl',
    'delivery_method' => 'dpd_courier', 'email' => "dpd.$sfx@example.com",
    'phone' => '600100200', 'first_name' => 'Jan', 'last_name' => 'Kowalski',
    'client_type' => 'osoba', 'ship_street' => 'Testowa', 'ship_building' => '1',
    'ship_postcode' => '00-001', 'ship_city' => 'Warszawa', 'ship_country' => 'PL',
    'consent_terms' => true,
]);
ok('une commande DPD passe la caisse', $vraie !== null, $errC);
$cmd = $vraie ? wsm_order_by_id($pdo, (int) $vraie['id']) : $cmd;
[$sh, $er] = wsm_dpd_create($pdo, $cmd);
ok('aucune expédition créée', $sh === null);
ok('et la raison est « pas configuré », pas une panne', $er === 'dpd_nieskonfigurowany', $er);
$stE = $pdo->prepare("SELECT status FROM wsm_shipments WHERE order_id = ?");
$stE->execute([(int) $cmd['id']]);
$etat = (string) $stE->fetchColumn();
ok('l\'expédition passe en ATTENTE, pas en erreur', $etat === 'oczekuje_na_konfiguracje', $etat);
ok('et l\'écran sait le dire à qui peut le régler',
   str_contains(wsm_ship_erreur_humaine('dpd_nieskonfigurowany'), 'Ustawieniach'),
   wsm_ship_erreur_humaine('dpd_nieskonfigurowany'));
// L'étiquette non plus ne part pas dans le vide.
[$pdf, , $eL2] = wsm_dpd_label(['shipment' => ['tracking_number' => '123']] + $cmd);
ok('pas d\'étiquette sans configuration', $pdf === null && $eL2 === 'dpd_nieskonfigurowany', $eL2);
[$pdf2, , $eL3] = wsm_dpd_label($cmd);
ok('ni sans numéro de suivi', $pdf2 === null && $eL3 === 'brak_numeru', $eL3);

wsm_config_overlay(['dpd' => ['login' => '', 'password' => '', 'fid' => '']]);

echo "\n" . ($fail === 0 ? "OK — $pass assertions" : "ÉCHEC — $fail sur " . ($pass + $fail)) . "\n";
exit($fail === 0 ? 0 : 1);
