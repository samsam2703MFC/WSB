<?php
// ============================================================================
//  e2e_posty.php — la médiathèque classée et les posts Facebook / Instagram.
//
//   1. la médiathèque : classement vérifié, tags propres, filtres, JPEG des
//      réseaux (cadre Instagram 4:5–1,91:1, jamais coupé) ;
//   2. l'écriture : limites d'Instagram, emoji intacts, produit, date ;
//   3. la publication (fausse API Meta) : Facebook photo / carrousel,
//      Instagram photo / carrousel, lien suivi, réclamation avant envoi,
//      échec partiel → seul le canal raté est republié, Instagram refusé
//      sur une adresse IP, post interrompu jamais relancé seul ;
//   4. Claude : trois propositions, aucune sans consigne ;
//   5. le calendrier : Pâques, Tłusty czwartek, Black Friday justes ;
//   6. la console : les écrans répondent pour un administrateur.
//
//  Usage :  php tests/e2e_posty.php
// ============================================================================

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, $got = null) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($got !== null ? "  (got: " . json_encode($got, JSON_UNESCAPED_UNICODE) . ")" : "") . "\n"; }
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/shop.php';
require_once dirname(__DIR__) . '/social.php';
require_once dirname(__DIR__) . '/marketing_ai.php';
$pdo = wsm_bootstrap();
wsm_social_ensure($pdo);
wsm_mediateka_ensure($pdo);
$BO = getenv('WSM_BO_URL') ?: 'http://localhost:8093/backoffice';

echo "webshop_mrszoko — end-to-end mediateka i posty Facebook / Instagram\n\n";

$sfx = bin2hex(random_bytes(3));
$cat = (string) $pdo->query("SELECT id FROM wsm_categories LIMIT 1")->fetchColumn();
$fichiers = []; $produits = []; $posts = [];
$email = 'posty.' . $sfx . '@misterszoko.test';
register_shutdown_function(function () use ($pdo, &$fichiers, &$produits, &$posts, $email, $sfx) {
    try {
        foreach ($posts as $id) $pdo->prepare("DELETE FROM wsm_social_posts WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM wsm_links WHERE nom LIKE ?")->execute(['Post Facebook #%']);
        foreach ($produits as $id) $pdo->prepare("DELETE FROM wsm_products WHERE id = ?")->execute([$id]);
        foreach ($fichiers as $f) {
            if (preg_match('#^media/([a-f0-9]{24})\.#', $f, $m)) {
                $pdo->prepare("DELETE FROM wsm_media_meta WHERE url = ?")->execute([$f]);
                $pdo->prepare("DELETE FROM wsm_media WHERE url = ?")->execute([$f]);
                @unlink(wsm_media_dir() . '/' . substr($f, 6));
                foreach (['feed', 'ig'] as $t) @unlink(wsm_media_dir() . '/jpg/' . $m[1] . '-' . $t . '.jpg');
            }
        }
        $pdo->prepare("DELETE FROM wsm_users WHERE email = ?")->execute([$email]);
    } catch (Throwable $e) { echo "  (nettoyage: " . $e->getMessage() . ")\n"; }
});
$img = function (int $w, int $h) use (&$fichiers): string {
    $name = bin2hex(random_bytes(12)) . '.png';
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 120, 70, 40));
    imagepng($im, wsm_media_dir() . '/' . $name); imagedestroy($im);
    $fichiers[] = 'media/' . $name;
    return 'media/' . $name;
};
$pid = 'test-po-' . $sfx;
$pdo->prepare("INSERT INTO wsm_products (id, category_id, nom, prix, base_cost, statut, active, shop_visible, slug, stock, vat_rate, weight_g, length_mm, width_mm, height_mm, sku)
               VALUES (?,?,?,49.9,0,'Opublikowany',1,1,?,5,0.23,500,100,100,100,?)")
    ->execute([$pid, $cat, 'Pralinki testowe ' . $sfx, $pid, 'PR-' . $sfx]);
$produits[] = $pid;

// ---- 1. La médiathèque ---------------------------------------------------------------------------
echo "-- mediateka --\n";
$haute = $img(400, 1000);   // trop haute pour Instagram (0,4)
$large = $img(2000, 500);   // trop large (4,0)
$carre = $img(800, 800);
ok('classement accepté : pralines, Walentynki, produit, alt', wsm_mediateka_meta_set($pdo, $carre, ['kategoria' => 'pralinki', 'okazja' => 'walentynki',
   'product_id' => $pid, 'alt' => "Pralinki\x01 w pudełku", 'tagi' => '#Walentynki, prezent;prezent  kakao!'], 'test') === '');
$meta = wsm_mediateka_meta_all($pdo)[$carre] ?? [];
ok('tags nettoyés : minuscules, sans #, sans doublon', ($meta['tagi'] ?? '') === 'walentynki, prezent, kakao', $meta['tagi'] ?? null);
ok('alt sans caractère de contrôle', ($meta['alt'] ?? '') === 'Pralinki w pudełku', $meta['alt'] ?? null);
ok('catégorie inconnue refusée', wsm_mediateka_meta_set($pdo, $carre, ['kategoria' => 'viennoiseries'], 'test') === 'nieznana kategoria');
ok('produit inconnu refusé', wsm_mediateka_meta_set($pdo, $carre, ['product_id' => 'nie-ma-' . $sfx], 'test') === 'nieznany produkt');
ok('chemin étranger refusé', wsm_mediateka_meta_set($pdo, 'media/../../api/config.php', [], 'test') === 'nieznany plik');
$l = wsm_mediateka_list($pdo, ['okazja' => 'walentynki', 'kategoria' => 'pralinki']);
ok('tags trop longs : coupés à des étiquettes entières, jamais plus de 250 signes', mb_strlen(wsm_mediateka_tagi(implode(' ', array_map(fn($i) => str_repeat(chr(97 + $i), 30), range(0, 9))))) <= 250);
ok('filtre catégorie + occasion', in_array($carre, array_column($l, 'url'), true) && !in_array($haute, array_column($l, 'url'), true));
ok('filtre texte (tags)', in_array($carre, array_column(wsm_mediateka_list($pdo, ['q' => 'kakao']), 'url'), true));
ok('filtre « sans alt »', in_array($haute, array_column(wsm_mediateka_list($pdo, ['bez_alt' => true]), 'url'), true)
   && !in_array($carre, array_column(wsm_mediateka_list($pdo, ['bez_alt' => true]), 'url'), true));
$jh = wsm_media_jpg($haute, 'ig'); $jl = wsm_media_jpg($large, 'ig'); $jc = wsm_media_jpg($carre, 'feed');
$dim = fn(?string $r) => $r ? getimagesize(wsm_media_dir() . '/' . substr($r, 6)) : [0, 0, 0];
ok('Instagram, photo trop haute : élargie à 4:5 (800×1000), pas coupée', array_slice($dim($jh), 0, 2) === [800, 1000], $dim($jh));
ok('Instagram, photo trop large : rehaussée à 1,91:1 (2000×1048)', array_slice($dim($jl), 0, 2) === [2000, 1048], $dim($jl));
ok('JPEG réel, cadre inchangé pour le flux', ($dim($jc)[2] ?? 0) === IMAGETYPE_JPEG && array_slice($dim($jc), 0, 2) === [800, 800]);
ok('format inconnu refusé', wsm_media_jpg($carre, 'tiktok') === null && wsm_media_jpg('https://evil.example/x.jpg') === null);
$t1 = filemtime(wsm_media_dir() . '/' . substr($jc, 6));
ok('resservi sans être refait', wsm_media_jpg($carre, 'feed') === $jc && filemtime(wsm_media_dir() . '/' . substr($jc, 6)) === $t1);

// ---- 2. L'écriture --------------------------------------------------------------------------------
echo "\n-- pisanie posta --\n";
[$c, $e] = wsm_social_clean($pdo, ['tekst' => str_repeat('a', 2201), 'kanaly' => ['fb']]);
ok('plus de 2 200 signes refusés', isset($e['tekst']));
[$c, $e] = wsm_social_clean($pdo, ['tekst' => implode(' ', array_map(fn($i) => '#tag' . $i, range(1, 31))), 'kanaly' => ['fb']]);
ok('plus de 30 hashtags refusés (Instagram rejetterait tout)', isset($e['tekst']));
[$c, $e] = wsm_social_clean($pdo, ['tekst' => 'Bez zdjęcia', 'kanaly' => ['ig']]);
ok('Instagram sans photo refusé', isset($e['media']));
[$c, $e] = wsm_social_clean($pdo, ['tekst' => "Szef kuchni 👩‍🍳 i czekolada\r\nNowa linia\x07", 'kanaly' => ['fb', 'tiktok'], 'media' => [$carre, $carre, 'media/../x.png']]);
ok('emoji composé intact, retours ligne normalisés, contrôle retiré', $c['tekst'] === "Szef kuchni 👩‍🍳 i czekolada\nNowa linia", $c['tekst']);
ok('canal inconnu ignoré, photo en double ignorée, chemin étranger ignoré', $c['kanaly'] === ['fb'] && $c['media'] === [$carre], [$c['kanaly'], $c['media']]);

// Sans connexion Meta : on écrit, on ne planifie pas.
wsm_config_overlay(['meta' => ['page_id' => '', 'page_token' => '', 'ig_user_id' => '']]);
[$id0, $e] = wsm_social_save($pdo, null, ['tekst' => 'Szkic ' . $sfx, 'kanaly' => ['fb', 'ig'], 'media' => [$carre]], 'szkic', 'test');
$posts[] = $id0;
ok('brouillon sans connexion : enregistré', $id0 > 0 && !$e && wsm_social_get($pdo, $id0)['status'] === 'szkic', $e);
[, $e] = wsm_social_save($pdo, $id0, ['tekst' => 'Szkic', 'kanaly' => ['fb'], 'media' => [$carre], 'termin' => date('Y-m-d\TH:i', time() + 3600)], 'zaplanuj', 'test');
ok('planifier sans connexion : refusé, avec le chemin du réglage', isset($e['kanaly']) && str_contains($e['kanaly'], 'Meta'));

$TOKEN = 'test-meta-page-' . str_repeat('y', 30);
wsm_config_overlay(['meta' => ['page_id' => '100200300', 'page_token' => $TOKEN, 'ig_user_id' => '17840000000000001', 'graph_version' => 'v23.0']]);
[, $e] = wsm_social_save($pdo, $id0, ['tekst' => 'Szkic', 'kanaly' => ['fb'], 'media' => [$carre], 'termin' => '2020-01-01T10:00'], 'zaplanuj', 'test');
ok('date passée refusée', isset($e['termin']));

// ---- 3. La publication --------------------------------------------------------------------------
echo "\n-- publikacja (fałszywe API Meta) --\n";
$appels = []; $igEchec = false; $fbEchec = false;
wsm_graph_transport(function (string $m, string $url, array $p, array $files) use (&$appels, &$igEchec, &$fbEchec) {
    $path = preg_replace('#^https://graph\.facebook\.com/v23\.0/#', '', $url);
    $appels[] = ['m' => $m, 'path' => $path, 'p' => $p, 'f' => $files];
    if ($fbEchec && str_starts_with($path, '100200300/')) return [400, '{"error":{"message":"(#200) Permissions error","code":200}}'];
    if ($igEchec && str_starts_with($path, '17840000000000001/media')) return [400, '{"error":{"message":"Only photo or video can be accepted as media type.","code":9004,"error_user_msg":"Zdjęcie nie mogło zostać pobrane"}}'];
    if ($path === '100200300/photos') return [200, empty($p['published']) || $p['published'] === 'true' ? '{"id":"777","post_id":"100200300_555"}' : '{"id":"888' . mt_rand(1000, 9999) . '"}'];
    if ($path === '100200300/feed') return [200, '{"id":"100200300_999"}'];
    if ($path === '17840000000000001/media') return [200, '{"id":"c' . substr(md5(json_encode($p)), 0, 6) . '"}'];
    if ($path === '17840000000000001/media_publish') return [200, '{"id":"18000000000000042"}'];
    if ($m === 'GET' && ($p['fields'] ?? '') === 'status_code') return [200, '{"status_code":"FINISHED"}'];
    if ($m === 'GET' && ($p['fields'] ?? '') === 'permalink') return [200, '{"permalink":"https://www.instagram.com/p/AbCdEf/"}'];
    return [404, '{"error":{"message":"inconnu"}}'];
});
wsm_social_sleep(fn(int $s) => null);
$SHOPOK = 'https://sklep.misterszoko.example/shop';

[$id1, $e] = wsm_social_save($pdo, null, ['tekst' => "Nowe pralinki na Walentynki 💝\n#czekolada #walentynki", 'kanaly' => ['fb', 'ig'],
                                           'media' => [$carre], 'product_id' => $pid], 'teraz', 'test');
$posts[] = $id1;
ok('« Opublikuj teraz » : enregistré et dû', $id1 > 0 && !$e, $e);
$p1 = wsm_social_get($pdo, $id1);
ok('produit + Facebook : lien suivi « fb<id> » créé', $p1['link_code'] === 'fb' . $id1, $p1['link_code']);
$etat = wsm_social_publish($pdo, $id1, $SHOPOK, true);
$p1 = wsm_social_get($pdo, $id1);
ok('publié sur les deux canaux', $etat === 'opublikowany' && $p1['fb_status'] === 'ok' && $p1['ig_status'] === 'ok', [$etat, $p1['fb_error'], $p1['ig_error']]);
$fbPhoto = array_values(array_filter($appels, fn($a) => $a['path'] === '100200300/photos'))[0] ?? null;
ok('Facebook : la photo part EN FICHIER (pas une adresse), JPEG', $fbPhoto && isset($fbPhoto['f']['source']) && is_file($fbPhoto['f']['source']) && str_ends_with($fbPhoto['f']['source'], '-feed.jpg'));
ok('Facebook : le texte porte le lien produit avec ?l=fb<id>', $fbPhoto && str_contains((string) $fbPhoto['p']['message'], '/p/' . $pid . '?l=fb' . $id1), $fbPhoto['p']['message'] ?? null);
$igC = array_values(array_filter($appels, fn($a) => $a['path'] === '17840000000000001/media'))[0] ?? null;
ok('Instagram : adresse publique de la version « -ig.jpg », légende SANS lien', $igC && preg_match('#^https://sklep\.misterszoko\.example/shop/media/jpg/[a-f0-9]{24}-ig\.jpg$#', (string) $igC['p']['image_url'])
   && !str_contains((string) $igC['p']['caption'], '?l='), $igC['p'] ?? null);
ok('Instagram : publié après « FINISHED », lien permanent gardé', $p1['ig_id'] === '18000000000000042' && $p1['ig_url'] === 'https://www.instagram.com/p/AbCdEf/');
ok('Facebook : identifiant de post et lien', $p1['fb_id'] === '100200300_555' && str_starts_with($p1['fb_url'], 'https://www.facebook.com/'));
ok('le jeton part en paramètre, jamais dans l\'adresse', !array_filter($appels, fn($a) => str_contains($a['path'], $TOKEN)) && ($appels[0]['p']['access_token'] ?? '') === $TOKEN);
ok('un post publié ne se réécrit pas', isset(wsm_social_save($pdo, $id1, ['tekst' => 'zmiana', 'kanaly' => ['fb']], 'szkic', 'test')[1]['_']));
ok('ni ne se supprime d\'ici', wsm_social_delete($pdo, $id1) === false);
ok('republier un post déjà parti : rien (pas réclamable)', wsm_social_publish($pdo, $id1, $SHOPOK, true) === '');

// Carrousels.
$appels = [];
[$id2] = wsm_social_save($pdo, null, ['tekst' => 'Karuzela ' . $sfx, 'kanaly' => ['fb', 'ig'], 'media' => [$carre, $haute, $large]], 'teraz', 'test');
$posts[] = $id2;
$etat = wsm_social_publish($pdo, $id2, $SHOPOK, true);
$nonPub = array_filter($appels, fn($a) => $a['path'] === '100200300/photos' && ($a['p']['published'] ?? '') === 'false');
$feed = array_values(array_filter($appels, fn($a) => $a['path'] === '100200300/feed'))[0] ?? null;
ok('Facebook, 3 photos : déposées non publiées puis UN post qui les rassemble', count($nonPub) === 3 && $feed && count((array) $feed['p']['attached_media']) === 3, [count($nonPub)]);
$kids = array_filter($appels, fn($a) => $a['path'] === '17840000000000001/media' && ($a['p']['is_carousel_item'] ?? '') === 'true');
$car = array_values(array_filter($appels, fn($a) => $a['path'] === '17840000000000001/media' && ($a['p']['media_type'] ?? '') === 'CAROUSEL'))[0] ?? null;
ok('Instagram, 3 photos : trois éléments puis un CAROUSEL avec la légende', count($kids) === 3 && $car && count(explode(',', (string) $car['p']['children'])) === 3 && $car['p']['caption'] === 'Karuzela ' . $sfx);
ok('carrousel publié', $etat === 'opublikowany', $etat);

// Échec partiel : seul Instagram rate, et la reprise ne refait PAS Facebook.
$appels = []; $igEchec = true;
[$id3] = wsm_social_save($pdo, null, ['tekst' => 'Częściowo ' . $sfx, 'kanaly' => ['fb', 'ig'], 'media' => [$carre]], 'teraz', 'test');
$posts[] = $id3;
$etat = wsm_social_publish($pdo, $id3, $SHOPOK, true);
$p3 = wsm_social_get($pdo, $id3);
ok('Facebook passe, Instagram refuse : « częściowo »', $etat === 'czesciowo' && $p3['fb_status'] === 'ok' && $p3['ig_status'] === 'blad', $etat);
ok('message de Meta lisible (error_user_msg) enregistré', str_contains($p3['ig_error'], 'Zdjęcie nie mogło zostać pobrane'), $p3['ig_error']);
$appels = []; $igEchec = false;
ok('« Ponów » remet en file', wsm_social_retry($pdo, $id3));
$etat = wsm_social_publish($pdo, $id3, $SHOPOK, true);
ok('reprise : publié, et Facebook n\'a PAS été rappelé (pas de doublon)', $etat === 'opublikowany' && !array_filter($appels, fn($a) => str_starts_with($a['path'], '100200300/')), array_column($appels, 'path'));

// Instagram sur une adresse IP.
[$id4] = wsm_social_save($pdo, null, ['tekst' => 'IP ' . $sfx, 'kanaly' => ['ig'], 'media' => [$carre]], 'teraz', 'test');
$posts[] = $id4;
$appels = [];
$etat = wsm_social_publish($pdo, $id4, 'https://185.180.206.46/mrszoko/shop', true);
$p4 = wsm_social_get($pdo, $id4);
ok('boutique sur adresse IP : Instagram refusé AVANT tout appel, avec la raison', $etat === 'blad' && $appels === [] && str_contains($p4['ig_error'], 'adresem IP'), $p4['ig_error']);
ok('… et en http non plus', wsm_social_ig_adres('http://sklep.example') !== '' && wsm_social_ig_adres('https://sklep.example') === '');

// La pompe et la date.
[$id5] = wsm_social_save($pdo, null, ['tekst' => 'Jutro ' . $sfx, 'kanaly' => ['fb'], 'media' => [$carre], 'termin' => date('Y-m-d\TH:i', time() + 86400)], 'zaplanuj', 'test');
[$id6] = wsm_social_save($pdo, null, ['tekst' => 'Teraz ' . $sfx, 'kanaly' => ['fb'], 'media' => [$carre], 'termin' => date('Y-m-d\TH:i', time() + 120)], 'zaplanuj', 'test');
$posts[] = $id5; $posts[] = $id6;
$pdo->prepare("UPDATE wsm_social_posts SET termin = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', time() - 60), $id6]);
$r = wsm_social_pump($pdo, $SHOPOK, 10);
ok('la pompe publie ce qui est dû', wsm_social_get($pdo, $id6)['status'] === 'opublikowany' && $r['opublikowane'] >= 1, $r);
ok('… et laisse ce qui est prévu demain', wsm_social_get($pdo, $id5)['status'] === 'zaplanowany');
ok('sans « teraz », pas de publication avant l\'heure', wsm_social_publish($pdo, $id5, $SHOPOK) === '');

// Le post interrompu.
$pdo->prepare("UPDATE wsm_social_posts SET status = 'publikowanie', claimed_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', time() - 3600), $id5]);
ok('post resté « publikowanie » 1 h : déclaré interrompu', wsm_social_stale($pdo) >= 1 && wsm_social_get($pdo, $id5)['status'] === 'blad');
ok('… avec la consigne de vérifier avant de relancer', str_contains(wsm_social_get($pdo, $id5)['fb_error'], 'sprawdź'));

// La pompe réclame le post pendant qu'on l'édite : pas de faux « zapisano ».
[$id7] = wsm_social_save($pdo, null, ['tekst' => 'Wyścig ' . $sfx, 'kanaly' => ['fb'], 'media' => [$carre], 'termin' => date('Y-m-d\TH:i', time() + 3600)], 'zaplanuj', 'test');
$posts[] = $id7;
$pdo->prepare("UPDATE wsm_social_posts SET status = 'publikowanie', claimed_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $id7]);
[, $e7] = wsm_social_save($pdo, $id7, ['tekst' => 'Zmiana ' . $sfx, 'kanaly' => ['fb'], 'media' => [$carre]], 'szkic', 'test');
ok('édition d\'un post en cours de publication : refusée, pas « zapisano »', isset($e7['_']) && wsm_social_get($pdo, $id7)['tekst'] === 'Wyścig ' . $sfx, $e7);
// Un post déclaré interrompu pendant qu'il finissait : l'état final ne l'écrase pas.
$pdo->prepare("UPDATE wsm_social_posts SET status = 'zaplanowany', termin = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', time() - 60), $id7]);
$appels = [];
wsm_graph_transport(function ($m, $url, $p, $f) use (&$appels, $pdo, $id7) {
    $appels[] = $url;
    $pdo->prepare("UPDATE wsm_social_posts SET status = 'blad' WHERE id = ?")->execute([$id7]);   // wsm_social_stale() passe pendant l'envoi
    return [200, '{"id":"777","post_id":"100200300_7"}'];
});
wsm_social_publish($pdo, $id7, $SHOPOK);
ok('l\'état final ne réécrit pas un post que la console a déjà déclaré interrompu', wsm_social_get($pdo, $id7)['status'] === 'blad');

// Dupliquer, supprimer.
$dup = wsm_social_duplicate($pdo, $id1, 'test');
$posts[] = $dup;
ok('dupliquer un post publié : nouveau brouillon, même texte', wsm_social_get($pdo, $dup)['status'] === 'szkic' && wsm_social_get($pdo, $dup)['tekst'] === wsm_social_get($pdo, $id1)['tekst']);
ok('un brouillon se supprime', wsm_social_delete($pdo, $dup));

// ---- 4. Claude ------------------------------------------------------------------------------------
echo "\n-- Claude --\n";
$avantCle = (string) (wsm_config()['anthropic_api_key'] ?? '');
wsm_config_overlay(['anthropic_api_key' => 'test-cle-factice']);
$vu = '';
wsm_ai_transport(function (string $sys, string $user, int $max) use (&$vu) {
    $vu = $sys . "\n" . $user;
    return ['{"propozycje":["Wersja 1 #czekolada","Wersja 2","Wersja 3","Wersja 4"]}', null];
});
[$prop, $err] = wsm_social_ai($pdo, 'Walentynkowe pralinki', $pid, ['fb', 'ig']);
ok('trois propositions (pas plus)', $err === null && count($prop) === 3, [$prop, $err]);
ok('les faits du produit sont donnés, et l\'interdiction d\'inventer aussi', str_contains($vu, 'Pralinki testowe') && str_contains($vu, 'NIGDY nie wymyślasz'));
ok('Instagram : « link w bio », pas de lien', str_contains($vu, 'link w bio'));
ok('consigne vide refusée sans appel', wsm_social_ai($pdo, ' ', '', ['fb'])[1] !== null);
wsm_config_overlay(['anthropic_api_key' => $avantCle]);

// ---- 5. Le calendrier ---------------------------------------------------------------------------
echo "\n-- kalendarz okazji --\n";
$o26 = wsm_social_okazje(2026);
ok('Pâques 2026 : 5 avril', ($o26['2026-04-05'] ?? '') === 'Wielkanoc');
ok('Tłusty czwartek 2026 : 12 février', ($o26['2026-02-12'] ?? '') === 'Tłusty czwartek');
ok('Black Friday 2026 : 27 novembre', ($o26['2026-11-27'] ?? '') === 'Black Friday');
ok('Black Friday 2030 (novembre commence un vendredi) : 29, pas 22', (wsm_social_okazje(2030)['2030-11-29'] ?? '') === 'Black Friday' && !isset(wsm_social_okazje(2030)['2030-11-22']));
ok('Walentynki, Mikołajki, Dzień Matki', isset($o26['2026-02-14'], $o26['2026-12-06'], $o26['2026-05-26']));
$mois = wsm_social_month($pdo, date('Y-m'));
ok('vue du mois : les posts du mois rangés par jour', (bool) array_filter($mois, fn($l) => (bool) array_filter($l, fn($p) => (int) $p['id'] === $id6)));

// ---- 6. La console ------------------------------------------------------------------------------
echo "\n-- konsola (HTTP) --\n";
$req = function (string $method, string $url, array $post = [], ?string $jar = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
                            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($post) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $hl = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($raw, 0, $hl), substr($raw, $hl)];
};
[$c0, , $h0] = $req('GET', "$BO/login.php");
if ($c0 === 0) { echo "  (konsola nieosiągalna pod $BO — część HTTP pominięta)\n"; }
else {
    $mdp = 'Test-' . bin2hex(random_bytes(6)) . '!';
    wsm_set_password($pdo, $email, $mdp, WSM_ROLE_ADMIN, 'Test posty');
    $jar = tempnam(sys_get_temp_dir(), 'wsmpo');
    [, , $h0] = $req('GET', "$BO/login.php", [], $jar);
    preg_match('/name="_t" value="([a-f0-9]{32})"/', $h0, $m);
    [$cl] = $req('POST', "$BO/login.php", ['_t' => $m[1] ?? '', 'email' => $email, 'password' => $mdp], $jar);
    ok('connexion administrateur', in_array($cl, [302, 303], true), $cl);
    foreach (['posty.php' => 'Posty', 'posty.php?widok=kalendarz&m=2027-02' => 'Tłusty czwartek', 'posty.php?nowy=1' => 'Opublikuj teraz', 'posty.php?id=' . $id1 => 'Duplikuj',
              'media.php' => 'Opis i kategoria', 'media.php?okazja=walentynki' => 'Walentynki', 'meta.php' => 'Conversions API', 'widocznosc.php' => 'Merchant Center',
              'formularze.php' => 'Współpraca hurtowa'] as $u => $mot) {
        [$cc, , $hh] = $req('GET', "$BO/$u", [], $jar);
        ok("$u répond 200 et montre « $mot »", $cc === 200 && str_contains($hh, $mot), $cc);
    }
    [, , $hm] = $req('GET', "$BO/posty.php", [], $jar);
    ok('menu Marketing : Posty, Formularze, Google i SEO, Meta', str_contains($hm, 'posty.php') && str_contains($hm, 'formularze.php') && str_contains($hm, 'widocznosc.php') && str_contains($hm, 'meta.php'));
    @unlink($jar);
}

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILED") . " — $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
