<?php
// ============================================================================
//  produkty.php — écran « Produits et photos » de la console marque.
//
//  C'est ici qu'on envoie une photo de produit. Le fichier est décodé et
//  ré-encodé côté serveur (media.php), donc ce qui atterrit dans la boutique
//  est une image fabriquée par nous, redimensionnée et sans métadonnées.
//
//  Même logique que zamowienia.php : page PHP autonome, même session et mêmes
//  rôles que la console. Lecture pour tout compte actif, écriture pour Centrala.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/console.php';
[$pdo, $me, $isAdmin] = console_boot();
$API = console_api_dir();
require_once $API . '/shop.php';
require_once $API . '/cms.php';   // les textes du produit vivent dans wsm_shop_i18n
require_once $API . '/media.php';
require_once $API . '/stock.php';
require_once $API . '/brand.php';
// La règle « ce rayon existe-t-il, faut-il le créer ou le rallumer » vit avec
// le reste du domaine produit, pas dans cet écran : le panneau Kategorie et la
// fiche produit l'appellent tous les deux.
require_once $API . '/commerce.php';

/** La table produits stocke des złotys, pas des grosze : conversion locale. */
function zl($v): string { return number_format((float) $v, 2, ',', "\u{202F}") . "\u{202F}zł"; }

// Jeton anti-CSRF, émis avant toute sortie (un en-tête ne s'ajoute plus après).
$csrf = (string) ($_COOKIE['ms_bo_csrf'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $csrf)) {
    $csrf = bin2hex(random_bytes(16));
    setcookie('ms_bo_csrf', $csrf, ['expires' => time() + 86400, 'path' => '/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => wsm_is_https()]);
}

$flash = ''; $kind = 'ok'; $fieldErrors = []; $creation = false;
$openId = (string) ($_GET['id'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['_t'] ?? ''))) {
        http_response_code(400); exit('Bad request.');
    }
    if (!$isAdmin) {
        $flash = 'Tylko rola Centrala może zmieniać produkty.'; $kind = 'err';
    } else {
        $id = (string) ($_POST['id'] ?? '');
        $openId = $id;

        // ---- « ALBO NOWA KATEGORIA », DEPUIS LA FICHE ----------------------
        //
        // LE PROBLÈME EXACT, RAPPORTÉ PAR LA MAISON : « je crée un produit,
        // j'ouvre la liste des catégories, et je ne peux choisir que
        // Czekolada ». C'était vrai. Le ménage a désactivé les cinq catégories
        // héritées de la maquette de boulangerie — Pieczywo, Lody, Katering… —
        // et il n'en restait plus qu'une seule active. La liste déroulante
        // disait donc la vérité, et cette vérité était un cul-de-sac : un
        // champ obligatoire avec un seul choix possible.
        //
        // Le panneau qui crée des catégories existait déjà, plus bas sur la
        // page. Mais renvoyer quelqu'un ailleurs au milieu d'une saisie, c'est
        // lui faire perdre ce qu'il a tapé et lui demander de comprendre
        // l'architecture de l'écran avant de pouvoir ranger un produit.
        //
        // ON RÉSOUT LE NOM ICI, AVANT TOUT LE RESTE, et on le pose dans
        // category_id : les deux chemins d'enregistrement — création et
        // édition — lisent ce champ-là et n'ont pas à savoir d'où il vient.
        // Un troisième endroit où traiter la catégorie aurait divergé.
        $katNowa = trim((string) ($_POST['kat_nowa'] ?? ''));
        // ON NE CRÉE PAS UN RAYON POUR UNE SAISIE QUI VA ÊTRE REFUSÉE. À la
        // création, le nom du produit est le seul champ qui peut tout faire
        // échouer ; sans cette garde, une fiche envoyée sans nom laisserait
        // derrière elle une catégorie neuve — et une catégorie ne se supprime
        // pas, elle s'éteint. La corbeille se remplirait de « Mąk », « Maka »,
        // « Mąka  » au premier tâtonnement.
        if ($katNowa !== '' && !(isset($_POST['nowy']) && trim((string) ($_POST['nazwa'] ?? '')) === '')) {
            [$kidN, $quoi] = wsm_categorie_assure($pdo, $katNowa);
            if ($kidN > 0) {
                // Le nom tapé l'emporte sur la liste déroulante : les deux
                // chemins d'enregistrement — création et édition — lisent
                // category_id et n'ont pas à savoir d'où il vient. Un
                // troisième endroit où traiter la catégorie aurait divergé.
                $_POST['category_id'] = (string) $kidN;
                if ($quoi === 'cree') {
                    wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Dodanie', 'wsm_categories #' . $kidN, 'Sieć');
                    $flash = 'Dodano kategorię ' . $katNowa . '.';
                } elseif ($quoi === 'rallume') {
                    wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Zmiana', 'wsm_categories #' . $kidN, 'Sieć');
                    $flash = 'Włączono z powrotem kategorię ' . $katNowa . '.';
                }
            } else {
                $fieldErrors['category_id'] = 'nie udalo sie dodac kategorii';
            }
        }

        // ---- CRÉER UN PRODUIT ----------------------------------------------
        //
        // CE CHEMIN N'EXISTAIT PAS. L'écran s'appelle « Produkty », on y
        // modifie et on y supprime — donc on suppose qu'on peut y ajouter. Les
        // 22 produits de la base venaient tous du fichier de semis ou
        // d'anciennes maquettes, et rien ne le disait.
        //
        // ON CRÉE PEU, PUIS ON COMPLÈTE. Trois champs suffisent (nom,
        // catégorie, prix) : le reste a des défauts, et la fiche qui s'ouvre
        // juste après porte déjà tout. Un formulaire de création qui
        // redemanderait les quinze champs de la fiche serait un deuxième
        // endroit où les tenir à jour.
        //
        // ET IL NAÎT INVISIBLE. shop_visible = 0 : un produit sans photo, sans
        // description et sans poids n'a rien à faire en vente. On le rend
        // visible quand il est prêt, d'un clic sur sa fiche.
        // ---- CRÉER UN PRODUIT ----------------------------------------------
        //
        // La création ne fait QUE poser la ligne minimale : identifiant,
        // catégorie, nom. Tout le reste — prix, photo, textes, gramatura,
        // dimensions — est appliqué juste après par le chemin d'EDITION, avec
        // ses validations. C'est ce qui rend les deux formulaires identiques
        // par construction : il n'y a pas deux codes d'enregistrement, il y en
        // a un, et la creation lui donne un produit sur lequel travailler.
        $creation = false;
        if (isset($_POST['nowy'])) {
            $nazwa = trim((string) ($_POST['nazwa'] ?? ''));
            $kat   = (int) ($_POST['category_id'] ?? 0);
            if ($nazwa === '') $fieldErrors['nazwa'] = 'wymagana';
            elseif (mb_strlen($nazwa) > WSM_PRODUCT_NAME_MAX) $fieldErrors['nazwa'] = 'maks. ' . WSM_PRODUCT_NAME_MAX . ' znaków';
            if ($kat <= 0) {
                $fieldErrors['category_id'] = 'wybierz kategorie';
            } else {
                $stC = $pdo->prepare("SELECT 1 FROM wsm_categories WHERE id = ?");
                $stC->execute([$kat]);
                if (!$stC->fetchColumn()) $fieldErrors['category_id'] = 'nie ma takiej kategorii';
            }

            if (!$fieldErrors) {
                // L'identifiant est une CLE : commandes, magasin, factures et
                // documents le portent pour toujours. Il se derive du nom et
                // se rend unique — deux produits qui le partagent, ce sont
                // deux histoires qui se melangent.
                $newId = wsm_slug_libre($pdo, wsm_slugify($nazwa, 'produkt'), 'id');
                // L'ADRESSE EST POSEE DES L'INSERTION. Derivee apres coup, elle
                // laissait la ligne sans slug quand la suite refusait un autre
                // champ : un produit orphelin, invisible et invendable, cree a
                // chaque tentative ratee. Le champ du formulaire est forcement
                // vide a la creation — le produit n'avait pas encore de nom
                // quand la fiche a ete rendue.
                $newSlug = trim((string) ($_POST['slug'] ?? ''));
                $newSlug = wsm_slug_libre($pdo,
                    wsm_slugify($newSlug !== '' ? $newSlug : $nazwa, $newId), 'slug', $newId);
                try {
                    $pdo->prepare("INSERT INTO wsm_products
                            (id, category_id, nom, slug, statut, active, shop_visible, sort_order, rodzaj)
                            VALUES (?,?,?,?,?,1,0,?,?)")
                        ->execute([$newId, $kat, $nazwa, $newSlug, 'Szkic',
                                   wsm_rodzaj((string) ($_POST['rodzaj'] ?? 'towar')),
                                   (int) $pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM wsm_products")->fetchColumn()]);
                    // Les trois textes naissent avec lui : wsm_cms_save()
                    // refuse une cle absente, donc sans ces lignes la
                    // description saisie sur la fiche partirait en silence.
                    $insT = $pdo->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES (?,?,?)");
                    foreach (['name' => $nazwa, 'subtitle' => '', 'desc' => ''] as $suf => $v) {
                        try { $insT->execute([WSM_CMS_BASE_LANG, 'product.' . $newId . '.' . $suf, $v]); }
                        catch (Throwable $e) { /* deja la */ }
                    }
                    wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Dodanie produktu', 'wsm_products ' . $newId, 'Siec');
                    // LE SLUG NE PEUT PAS ÊTRE SAISI À LA CRÉATION : au moment
                    // où la fiche est rendue, le produit n'a pas encore de nom,
                    // donc le champ part vide — et le validateur le refuse, à
                    // juste titre, puisqu'un produit sans adresse ne se vend
                    // pas. On le dérive donc du nom qui vient d'être tapé. Il
                    // reste visible et modifiable sur la fiche qui se rouvre.
                    $_POST['slug'] = $newSlug;
                    // On enchaine sur l'edition, avec le produit tout neuf.
                    $id = $newId; $openId = $newId; $_POST['id'] = $newId; $creation = true;
                } catch (Throwable $e) {
                    // Le MOTIF, pas un mur : wsm_db_powod() le dit en clair et
                    // écrit l'erreur complète dans le journal du serveur.
                    $flash = 'Nie udało się utworzyć produktu: ' . wsm_db_powod($e, 'produkty: nowy produkt') . '.'; $kind = 'err';
                }
            } else {
                $flash = 'Popraw zaznaczone pola.'; $kind = 'err';
            }
        }

        // ---- CATÉGORIES ----------------------------------------------------
        //
        // Elles n'étaient éditables NULLE PART. Six lignes posées au semis,
        // dont cinq héritées d'une maquette de boulangerie — Pieczywo, Lody,
        // Katering — pour une chocolaterie. Le champ est obligatoire sur
        // chaque produit : on choisissait donc forcément une étiquette fausse.
        //
        // On ne SUPPRIME pas : une catégorie effacée laisserait ses produits
        // avec une référence morte, et la colonne est NOT NULL. On désactive,
        // ce qui la retire des listes sans toucher à ce qui la nomme déjà.
        if (isset($_POST['kat_zapisz'])) {
            $kid  = (int) $_POST['kat_zapisz'];
            $knom = trim((string) ($_POST['kat_nazwa'] ?? ''));
            if ($knom === '') {
                $flash = 'Nazwa kategorii jest wymagana.'; $kind = 'err';
            } elseif (mb_strlen($knom) > 80) {
                // Même borne que la création (wsm_categorie_assure) ; la
                // colonne en tient 120, mais un rayon se lit dans un onglet.
                $flash = 'Nazwa kategorii może mieć najwyżej 80 znaków.'; $kind = 'err';
            } else {
                if ($kid > 0) {
                    try {
                        $pdo->prepare("UPDATE wsm_categories SET name = ? WHERE id = ?")->execute([$knom, $kid]);
                        wsm_kat_rodzic($pdo, $kid, (int) ($_POST['kat_rodzic'] ?? 0));
                        $flash = 'Zapisano kategorię ' . $knom . '.';
                    } catch (Throwable $e) {
                        // Le doublon est le cas courant ; les autres refus disent leur motif.
                        $powod = wsm_db_powod($e, 'produkty: kategoria');
                        $flash = $powod === 'taki wpis już istnieje' ? 'Inna kategoria nazywa się już tak samo.'
                                                                   : 'Nie udało się zapisać kategorii: ' . $powod . '.';
                        $kind = 'err';
                    }
                } else {
                    // MÊME RÈGLE QUE DEPUIS LA FICHE PRODUIT. Avant, taper ici
                    // le nom d'un rayon éteint répondait « ce nom existe
                    // déjà » — vrai, et parfaitement inutile : le rayon était
                    // dans le tableau juste au-dessus, avec son bouton
                    // « Włącz ». Deux gestes pour un, et il fallait avoir
                    // compris pourquoi.
                    [$kid, $quoi] = wsm_categorie_assure($pdo, $knom);
                    if ($kid > 0) wsm_kat_rodzic($pdo, $kid, (int) ($_POST['kat_rodzic'] ?? 0));
                    if ($kid === 0) {
                        $flash = 'Nie udało się dodać kategorii ' . $knom . '.'; $kind = 'err';
                    } else {
                        $flash = $quoi === 'rallume' ? 'Włączono z powrotem kategorię ' . $knom . '.'
                               : ($quoi === 'trouve' ? 'Kategoria ' . $knom . ' już jest na liście.'
                                                     : 'Dodano kategorię ' . $knom . '.');
                    }
                }
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), $kid ? 'Zmiana' : 'Dodanie',
                          'wsm_categories #' . $kid, 'Sieć');
            }
        }
        elseif (isset($_POST['kat_akt'])) {
            $kid = (int) $_POST['kat_akt'];
            $on  = ((string) ($_POST['kat_on'] ?? '1')) === '1' ? 1 : 0;
            // COMBIEN DE PRODUITS EN DÉPENDENT ? Désactiver une catégorie
            // encore portée par des produits ne les casse pas, mais il faut le
            // dire : ils ne se rangeront plus nulle part de visible.
            $n = (int) $pdo->query("SELECT COUNT(*) FROM wsm_products WHERE category_id = " . $kid)->fetchColumn();
            $pdo->prepare("UPDATE wsm_categories SET active = ? WHERE id = ?")->execute([$on, $kid]);
            $flash = ($on ? 'Włączono' : 'Wyłączono') . ' kategorię'
                   . ($n && !$on ? ' — nosi ją nadal ' . $n . ' produkt(ów)' : '') . '.';
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), $on ? 'Włączenie' : 'Wyłączenie',
                      'wsm_categories #' . $kid, 'Sieć');
        }

        // ---- Marques -------------------------------------------------------
        elseif (isset($_POST['marka_zapisz'])) {
            $bid = (int) $_POST['marka_zapisz'] ?: null;
            [$b, $errs] = wsm_brand_save($pdo, $_POST, $_FILES['logo'] ?? [], $bid);
            if ($errs) {
                $fieldErrors = $errs;
                $flash = 'Popraw dane marki: ' . implode(' · ', $errs); $kind = 'err';
            } else {
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), $bid ? 'Zmiana' : 'Dodanie',
                          'wsm_brands ' . $b['name'], 'Sieć');
                $flash = 'Zapisano markę ' . $b['name'] . '.';
            }
            $openId = '';
        } elseif (isset($_POST['marka_usun'])) {
            [$ok, $msg] = wsm_brand_delete($pdo, (int) $_POST['marka_usun']);
            if ($ok) wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Usunięcie', 'wsm_brands #' . (int) $_POST['marka_usun'], 'Sieć');
            $flash = $msg; $kind = $ok ? 'ok' : 'err';
            $openId = '';
        } else

        // ---- Désactiver / réactiver / supprimer ----------------------------
        //
        // DEUXIÈME CHAÎNE, et elle a besoin d'une garde. Son `else` final
        // traite la fiche d'un produit ; une création ou une marque tombait
        // dedans avec un identifiant vide, écrasait le message de réussite par
        // « Nie znaleziono produktu » et faisait passer l'écran en rouge alors
        // que tout venait de se passer correctement.
        //
        // Désactiver retire le produit du catalogue et du magasin sans toucher
        // à l'histoire : les commandes passées, les factures et les mouvements
        // continuent de le nommer. C'est presque toujours ce qu'on veut.
        if (isset($_POST['marka_zapisz']) || isset($_POST['marka_usun'])
            || isset($_POST['kat_zapisz']) || isset($_POST['kat_akt'])
            || (isset($_POST['nowy']) && !$creation)) {
            // Déjà traité au-dessus.
            //
            // LA CRÉATION REFUSÉE FAIT PARTIE DE CETTE LISTE. Quand elle
            // réussit, elle se poursuit volontairement dans le chemin
            // d'édition — c'est ce qui fait qu'il n'y a qu'un seul code
            // d'enregistrement. Quand elle échoue, l'identifiant est vide, et
            // la fiche tombait ici avec « Nie znaleziono produktu » : un
            // message qui parle d'un produit inexistant à quelqu'un qui
            // essayait justement d'en créer un, et qui EFFAÇAIT au passage la
            // vraie raison du refus — « nazwa wymagana » — affichée sous le
            // champ concerné.
        }
        elseif (isset($_POST['aktywacja'])) {
            $on = $_POST['aktywacja'] === '1' ? 1 : 0;
            $pdo->prepare("UPDATE wsm_products SET active = ?, shop_visible = CASE WHEN ? = 0 THEN 0 ELSE shop_visible END WHERE id = ?")
                ->execute([$on, $on, $id]);
            wsm_audit($pdo, (string) ($me['nom'] ?? ''), $on ? 'Włączenie produktu' : 'Wyłączenie produktu', 'wsm_products ' . $id, 'Sieć');
            $flash = $on ? 'Produkt włączony: ' . $id : 'Produkt wyłączony — zniknął ze sklepu i z magazynu.';
            $kind = 'ok';
        }
        // Supprimer n'est possible QUE si rien ne s'y réfère. Un produit cité
        // par une commande ou par une facture ne peut pas disparaître : le
        // document deviendrait illisible, et une facture doit se relire à
        // l'identique dans dix ans. Dans ce cas on propose la désactivation.
        elseif (isset($_POST['usun'])) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_order_items WHERE product_id = ?");
            $st->execute([$id]);
            $used = (int) $st->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(*) FROM wsm_stock_moves WHERE product_id = ?");
            $st->execute([$id]);
            $moved = (int) $st->fetchColumn();
            if ($used > 0) {
                $flash = 'Nie można usunąć: produkt występuje w ' . $used . ' pozycjach zamówień. '
                       . 'Dokumenty muszą pozostać czytelne — wyłącz go zamiast usuwać.';
                $kind = 'err';
            } elseif ($moved > 0) {
                $flash = 'Nie można usunąć: produkt ma ' . $moved . ' ruchów magazynowych. Wyłącz go zamiast usuwać.';
                $kind = 'err';
            } else {
                $st = $pdo->prepare("SELECT image_url FROM wsm_products WHERE id = ?");
                $st->execute([$id]);
                $img = (string) $st->fetchColumn();
                $pdo->prepare("DELETE FROM wsm_products WHERE id = ?")->execute([$id]);
                if ($img !== '') wsm_media_delete($img);
                wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Usunięcie produktu', 'wsm_products ' . $id, 'Sieć');
                $flash = 'Usunięto produkt ' . $id . '.';
                $kind = 'ok';
                $openId = '';
            }
        }
        else {
        $st = $pdo->prepare("SELECT id, image_url, weight_g, length_mm, width_mm, height_mm
                               FROM wsm_products WHERE id = ?");
        $st->execute([$id]);
        $cur = $st->fetch();
        if (!$cur) {
            $flash = 'Nie znaleziono produktu.'; $kind = 'err';
        } else {
            $body = [];
            foreach (['slug', 'origin', 'cocoa', 'unit_label', 'badge', 'vat_rate'] as $k) {
                if (isset($_POST[$k])) $body[$k] = $_POST[$k];
            }
            // ── LA GRAMATURE ET LES DIMENSIONS ───────────────────────────
            //
            // Elles n'étaient éditables sur AUCUN écran, alors qu'elles
            // décident du gabarit InPost et du coût d'expédition. Un produit
            // resté à 0 g part au tarif d'un gabarit choisi sur du vide.
            $mesures = [];
            foreach (['weight_g' => 200000, 'length_mm' => 3000, 'width_mm' => 3000, 'height_mm' => 3000] as $k => $max) {
                if (!isset($_POST[$k]) || trim((string) $_POST[$k]) === '') continue;
                $n = (int) round(wsm_parse_price((string) $_POST[$k]) ?? -1);
                if ($n < 0 || $n > $max) { $fieldErrors[$k] = 'od 0 do ' . $max; continue; }
                $mesures[$k] = $n;
            }
            // ── LES VALEURS NUTRITIVES POUR 100 g ─────────────────────────
            // Des chiffres, plus une phrase perdue dans la description. Le
            // témoin « nutri__on » dit que la fiche les portait : sans lui, un
            // autre formulaire (stock, visibilité) les effacerait.
            $nutri = null;
            if (!empty($_POST['nutri__on'])) {
                $nErr = [];
                $nutri = wsm_nutrition_clean((array) ($_POST['nutri'] ?? []), $nErr);
                foreach (array_keys($nErr) as $nk) $fieldErrors['nutri_' . $nk] = 'liczba, np. 32,5 albo <0,5';
            }
            // Le stock ne se pose plus directement : il se corrige, et la
            // correction laisse une trace dans le magasin (qui, quand, pourquoi).
            $wantStock = isset($_POST['stock']) ? max(0, (int) $_POST['stock']) : null;
            $body['shop_visible'] = !empty($_POST['shop_visible']) ? 1 : 0;
            // Le nom tient dans sa colonne (VARCHAR(160)) : au-delà, MySQL
            // refuse l'enregistrement entier — on le dit sur le champ.
            if (mb_strlen(trim((string) ($_POST['nazwa'] ?? ''))) > WSM_PRODUCT_NAME_MAX) {
                $fieldErrors['nazwa'] = 'maks. ' . WSM_PRODUCT_NAME_MAX . ' znaków';
            }

            $old = (string) $cur['image_url'];
            $newUrl = null;

            if (!empty($_POST['remove_image'])) {
                $body['image_url'] = '';
            } elseif (!empty($_FILES['photo']['name'] ?? '')) {
                [$url, $err] = wsm_media_store($_FILES['photo']);
                if ($err !== null) { $fieldErrors['photo'] = $err; }
                else { $body['image_url'] = $url; $newUrl = $url; }
            } elseif (isset($_POST['image_url'])) {
                // LE CHAMP VIDE VEUT DIRE « JE N'Y TOUCHE PAS », JAMAIS
                // « efface ». Quand la photo est un fichier envoyé, le
                // formulaire rend ce champ VIDE — c'est un champ d'adresse
                // externe, une adresse media/ n'a rien à y faire. La condition
                // d'avant comparait ce vide à « media/xyz.webp », les trouvait
                // différents, et posait une chaîne vide : chaque « Zapisz »
                // effaçait la photo qu'on venait de mettre.
                //
                // Effacer reste possible, mais par la case « Usuń zdjęcie » :
                // un geste explicite, coché exprès. Une destruction ne doit pas
                // être le comportement par défaut d'un bouton d'enregistrement.
                $v = trim((string) $_POST['image_url']);
                if ($v !== '' && $v !== $old) $body['image_url'] = $v;
            }

            if (!$fieldErrors) {
                [$cols, $errs] = wsm_validate_product_shop($pdo, $body, $id);
                if ($errs) {
                    $fieldErrors = $errs;
                    // L'envoi a réussi mais l'enregistrement échoue : on ne
                    // laisse pas le fichier orphelin sur le disque.
                    if ($newUrl !== null) wsm_media_delete($newUrl);
                    $flash = 'Popraw zaznaczone pola.'; $kind = 'err';
                } else {
                    $set = []; $vals = [];
                    foreach ($cols as $k => $v) { $set[] = "$k = ?"; $vals[] = $v; }
                    // null = saisie inexploitable : on NE TOUCHE PAS au prix.
                    // L'ancien code écrivait (float) d'une chaîne à espaces —
                    // « 1 234,50 » devenait 1,00 zł, en silence.
                    if (isset($_POST['prix']) && trim((string) $_POST['prix']) !== '') {
                        $pr = wsm_parse_price((string) $_POST['prix']);
                        if ($pr === null) $fieldErrors['prix'] = 'nie rozumiem tej ceny';
                        elseif ($pr < 0)  $fieldErrors['prix'] = 'nie może być ujemna';
                        else { $set[] = 'prix = ?'; $vals[] = $pr; }
                    }
                    // La marque est une référence : la chaîne vide veut dire
                    // « aucune », et NULL est le bon marqueur en base — 0
                    // pointerait vers une ligne qui n'existe pas.
                    // La categorie n'etait modifiable NULLE PART : posee au
                    // semis, figee pour toujours. Elle est obligatoire en base,
                    // donc une valeur absente ou inconnue est refusee plutot
                    // qu'ecrite.
                    if (isset($_POST['category_id']) && (int) $_POST['category_id'] > 0) {
                        $kc = (int) $_POST['category_id'];
                        $stK = $pdo->prepare("SELECT 1 FROM wsm_categories WHERE id = ?");
                        $stK->execute([$kc]);
                        if ($stK->fetchColumn()) { $set[] = 'category_id = ?'; $vals[] = $kc; }
                        else $fieldErrors['category_id'] = 'nie ma takiej kategorii';
                    }
                    // Ce que l'on vend : un objet (colis), une prestation ou un
                    // logiciel. Seul un objet appelle un transporteur à la caisse.
                    if (isset($_POST['rodzaj'])) { $set[] = 'rodzaj = ?'; $vals[] = wsm_rodzaj((string) $_POST['rodzaj']); }
                    if (isset($_POST['brand_id'])) {
                        $bid = (int) $_POST['brand_id'];
                        $set[] = 'brand_id = ?'; $vals[] = $bid > 0 ? $bid : null;
                    }
                    foreach ($mesures as $k => $v) { $set[] = "$k = ?"; $vals[] = $v; }
                    if ($nutri !== null) { $set[] = 'nutrition = ?'; $vals[] = wsm_nutrition_encode($nutri); }
                    // Le gabarit se DÉDUIT des dimensions : le laisser en
                    // arrière ferait payer un tarif qui ne correspond plus au
                    // colis. Même règle que le semis (wsm_seed_shop).
                    if ($mesures && function_exists('wsm_inpost_template')) {
                        $dim = fn(string $k) => (int) ($mesures[$k] ?? $cur[$k] ?? 0);
                        $set[] = 'parcel_template = ?';
                        $vals[] = wsm_inpost_template($dim('length_mm'), $dim('width_mm'), $dim('height_mm'));
                    }
                    $vals[] = $id;
                    if ($set) {
                        $pdo->prepare("UPDATE wsm_products SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
                    }
                    if ($wantStock !== null) {
                        wsm_stock_set($pdo, $id, $wantStock, [
                            'reason' => trim((string) ($_POST['stock_reason'] ?? '')) ?: 'korekta ręczna',
                            'actor'  => (string) ($me['nom'] ?? ''),
                        ]);
                    }
                    // ── Les trois textes, dans la langue de base ──────────
                    //
                    // wsm_cms_save() refuse une clé absente de la base — garde
                    // juste, elle empêche un POST d'inventer des clés. Mais un
                    // produit peut n'avoir jamais eu de description : on pose
                    // donc la ligne vide AVANT, sinon la saisie serait avalée
                    // en silence, ce qui est exactement le défaut qu'on répare.
                    $vals = [];
                    foreach (['nazwa' => 'name', 'podtytul' => 'subtitle', 'opis' => 'desc'] as $champ => $suf) {
                        if (!isset($_POST[$champ])) continue;
                        $cle = 'product.' . $id . '.' . $suf;
                        $st2 = $pdo->prepare("SELECT COUNT(*) FROM wsm_shop_i18n WHERE lang = ? AND k = ?");
                        $st2->execute([WSM_CMS_BASE_LANG, $cle]);
                        if (!(int) $st2->fetchColumn()) {
                            $pdo->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES (?,?,'')")
                                ->execute([WSM_CMS_BASE_LANG, $cle]);
                        }
                        $vals[$cle] = [WSM_CMS_BASE_LANG => (string) $_POST[$champ]];
                    }
                    // LES PROPRIÉTÉS, ligne à ligne : product.<id>.prop.<n>.k/.v,
                    // des textes traduisibles dans Treści comme la description.
                    // Une ligne vide jamais remplie ne crée rien ; une ligne
                    // remplie puis vidée est vidée — la fiche n'en montre plus.
                    foreach ((array) ($_POST['prop'] ?? []) as $n => $ligne) {
                        $n = (int) $n;
                        if ($n < 1 || $n > WSM_PRODUCT_PROPS_MAX || !is_array($ligne)) continue;
                        foreach (['k' => 80, 'v' => 300] as $moitie => $max) {
                            $cle = 'product.' . $id . '.prop.' . $n . '.' . $moitie;
                            $val = mb_substr(trim(str_replace(["\r", "\n"], ' ', (string) ($ligne[$moitie] ?? ''))), 0, $max);
                            $st2 = $pdo->prepare("SELECT COUNT(*) FROM wsm_shop_i18n WHERE lang = ? AND k = ?");
                            $st2->execute([WSM_CMS_BASE_LANG, $cle]);
                            $existe = (bool) (int) $st2->fetchColumn();
                            if (!$existe && $val === '') continue;
                            if (!$existe) {
                                $pdo->prepare("INSERT INTO wsm_shop_i18n (lang, k, v) VALUES (?,?,'')")
                                    ->execute([WSM_CMS_BASE_LANG, $cle]);
                            }
                            $vals[$cle] = [WSM_CMS_BASE_LANG => $val];
                        }
                    }
                    $nTxt = 0;
                    if ($vals) {
                        [$nTxt, ] = wsm_cms_save($pdo, 'wsm_shop_i18n', $vals,
                                                 [WSM_CMS_BASE_LANG], (string) ($me['nom'] ?? ''));
                        // La console liste et trie sur wsm_products.nom : le
                        // laisser en arrière ferait dire deux noms différents
                        // au même produit, selon l'écran qu'on regarde.
                        $nn = trim((string) ($_POST['nazwa'] ?? ''));
                        if ($nn !== '') $pdo->prepare("UPDATE wsm_products SET nom = ? WHERE id = ?")->execute([$nn, $id]);
                    }
                    wsm_audit($pdo, (string) ($me['nom'] ?? ''), 'Zmiana',
                              'wsm_products ' . $id . ($nTxt ? ' · teksty: ' . $nTxt : ''), 'Sieć');
                    // L'ancienne photo n'est effacée qu'une fois la nouvelle
                    // réellement enregistrée en base.
                    if ($newUrl !== null && $old !== '' && $old !== $newUrl) wsm_media_delete($old);
                    if (!empty($_POST['remove_image']) && $old !== '') wsm_media_delete($old);
                    $flash = $creation
                        ? 'Utworzono ' . $id . '. Produkt jest NIEWIDOCZNY w sklepie — '
                          . 'sprawdź fiszkę i zaznacz „W sprzedaży", gdy będzie gotowy.'
                        : 'Zapisano: ' . $id;
                    $kind = 'ok';
                }
            } else {
                $flash = 'Popraw zaznaczone pola.'; $kind = 'err';
            }
        }
        }
    }
}

$brands = wsm_brands_all($pdo);
$brandCounts = wsm_brand_counts($pdo);

// ─── LES TEXTES DU PRODUIT ─────────────────────────────────────────────────
//
// Le nom, le sous-titre et la description d'un produit ne sont PAS des
// colonnes de wsm_products : ce sont des textes traduits, `product.<id>.name`
// et compagnie, que la boutique lit dans wsm_shop_i18n (shop.php : `$S[...]
// ?? $r['nom']`). Ils vivaient donc uniquement dans l'écran Treści, à l'autre
// bout de la console — et cet écran-ci, celui du produit, n'en portait aucun.
//
// Résultat, rapporté depuis la boutique : « zdjęcie się dodaje ale opis nie
// chce się zmienić ». La photo est sur cet écran, le texte non. Rien n'était
// écrasé : il n'y avait simplement nulle part où l'écrire.
// ─── LE SLUG MANQUANT BLOQUAIT TOUT ────────────────────────────────────────
//
// 13 produits sur 22 n'ont pas de slug — des lignes d'avant la boutique, que
// personne n'a jamais rouvertes. Or wsm_validate_product_shop() le déclare
// « wymagany », et le formulaire le poste toujours, même vide : TOUT
// enregistrement sur ces produits était donc refusé en bloc, avec l'erreur
// posée sur un champ que personne n'éditait. On changeait un prix, une
// description, et rien n'entrait — « jakby były nadpisane ».
//
// On propose donc une adresse, VISIBLE dans le champ avant d'enregistrer. Pas
// de génération silencieuse : le slug est l'adresse publique du produit, on
// ne l'invente pas dans le dos de qui appuie sur le bouton.
$slugProp = function (string $nom, string $id) use ($pdo): string {
    $tr = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
           'Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ź'=>'z','Ż'=>'z'];
    $v = strtolower(strtr(trim($nom), $tr));
    $v = trim((string) preg_replace('/[^a-z0-9]+/', '-', $v), '-');
    if ($v === '') $v = strtolower($id);
    $v = substr($v, 0, 80);
    // Deux produits ne peuvent pas partager une adresse : l'un deviendrait
    // inatteignable. En cas de collision, l'identifiant tranche.
    $st = $pdo->prepare("SELECT id FROM wsm_products WHERE slug = ? AND id <> ?");
    $st->execute([$v, $id]);
    return $st->fetchColumn() ? substr($v . '-' . strtolower($id), 0, 80) : $v;
};

// UN CHAMP REFUSÉ NE DOIT PAS EFFACER LES DOUZE AUTRES.
//
// En cas d'erreur, la fiche se rechargeait depuis la BASE : tout ce qui venait
// d'être tapé — prix, description, gramatura — disparaissait, et seul un petit
// « wymagany » sous un champ qu'on n'éditait pas expliquait pourquoi. De
// l'extérieur, ça se lit exactement comme « zmiany nie chcą wejść ».
//
// $vv() redonne donc la saisie quand l'enregistrement a échoué SUR CE
// produit-là. Ailleurs, la base fait foi.
// Vrai UNIQUEMENT pendant le rendu de la fiche de création, et seulement si
// cette création vient d'être refusée. Posé par $fiszka avant de dessiner.
$enCreation = false;

$vv = function (string $champ, string $defaut) use (&$fieldErrors, &$openId, &$id, &$enCreation) {
    // LA FICHE DE CRÉATION N'A PAS D'IDENTIFIANT, et c'est par l'identifiant
    // qu'on reconnaît la fiche à re-remplir. Sans ce premier cas, une création
    // refusée — un nom oublié, une catégorie non choisie — renvoyait un
    // formulaire vierge : tout ce qui avait été tapé était à retaper. Le
    // drapeau est indispensable, car un identifiant vide « vise » aussi les
    // vingt-deux fiches produit qui se dessinent juste après.
    $vise = $enCreation || ($openId !== '' && isset($id) && $openId === $id);
    return ($fieldErrors && $vise && isset($_POST[$champ])) ? (string) $_POST[$champ] : $defaut;
};

$kategorie = $pdo->query("SELECT id, name, parent_id FROM wsm_categories WHERE active = 1 ORDER BY sort_order, name")
                 ->fetchAll(PDO::FETCH_ASSOC);
// Toutes, actives ou non, avec le nombre de produits qui les portent : on ne
// désactive pas une catégorie sans savoir ce qu'elle tient.
$katWszystkie = $pdo->query(
    "SELECT c.id, c.name, c.active, c.parent_id, COUNT(p.id) AS ile
       FROM wsm_categories c LEFT JOIN wsm_products p ON p.category_id = c.id
      GROUP BY c.id, c.name, c.active, c.parent_id
      ORDER BY c.sort_order, c.name")->fetchAll(PDO::FETCH_ASSOC);
// Les rayons qui peuvent en ranger d'autres : ceux du premier niveau.
$katGlowne = array_values(array_filter($katWszystkie, fn($k) => (int) $k['parent_id'] === 0 && (int) $k['active'] === 1));
/** La liste « rayon parent » d'un formulaire de catégorie. */
$rodzicSelect = function (int $actuel, int $soi = 0) use ($katGlowne): string {
    $h = '<select name="kat_rodzic" aria-label="Kategoria nadrzędna"><option value="0">— główna —</option>';
    foreach ($katGlowne as $g) {
        if ((int) $g['id'] === $soi) continue;
        $h .= '<option value="' . (int) $g['id'] . '"' . ($actuel === (int) $g['id'] ? ' selected' : '') . '>w: ' . h((string) $g['name']) . '</option>';
    }
    return $h . '</select>';
};

$T = wsm_cms_load($pdo, 'wsm_shop_i18n', [WSM_CMS_BASE_LANG]);
$txt = fn(string $id, string $champ): string
     => (string) ($T['product.' . $id . '.' . $champ][WSM_CMS_BASE_LANG] ?? '');

$rows = $pdo->query(
    "SELECT p.*, c.name AS cat FROM wsm_products p
       LEFT JOIN wsm_categories c ON c.id = p.category_id
      ORDER BY p.shop_visible DESC, p.sort_order, p.nom"
)->fetchAll();
$writable = is_dir(wsm_media_dir()) ? is_writable(wsm_media_dir()) : is_writable(dirname(wsm_media_dir()));
$nVisible = count(array_filter($rows, fn($r) => (int) $r['shop_visible'] === 1));
$nPhoto   = count(array_filter($rows, fn($r) => (int) $r['shop_visible'] === 1 && (string) $r['image_url'] !== ''));

console_head('Produkty i zdjęcia', $me, <<<'CSS'
  .hint { color: var(--text-muted); font-size: 13.5px; margin: 0 0 20px; max-width: 78ch; line-height: 1.55; }
  .item { background: var(--surface-card); border: 0; border-radius: 18px;
          margin-bottom: 12px; box-shadow: var(--shadow-xs); overflow: hidden; }
  .item > summary { display: flex; align-items: center; gap: 14px; padding: 12px 14px; cursor: pointer; list-style: none; }
  .item > summary::-webkit-details-marker { display: none; }
  .item[open] > summary { border-bottom: 1px solid var(--border-subtle); background: var(--surface-raised); }
  /* Les photos produit ont un fond blanc : on les pose sur du blanc, entières
     (contain), au lieu de les rogner sur un carré crème qui les encadrait. */
  .thumb { width: 56px; height: 56px; flex: none; border-radius: 12px; object-fit: contain; background: #FFFFFF;
           box-shadow: inset 0 0 0 1px var(--border-subtle); }
  .thumb.empty { display: grid; place-items: center; font-family: var(--font-sans); font-size: 10px; background: var(--surface-sunken); box-shadow: none;
                 color: var(--text-muted); text-align: center; line-height: 1.25; padding: 4px; }
  .sum-name { font-family: var(--font-display); font-size: 16px; color: var(--text-strong); font-weight: 700; letter-spacing: -.01em; }
  .sum-meta { font-family: var(--font-sans); font-size: 12.5px; color: var(--text-muted); margin-top: 3px; }
  .sum-right { margin-left: auto; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
  .tag.on  { background: color-mix(in srgb, var(--success) 18%, transparent); color: var(--success); }
  .tag.off { background: var(--paper-300); color: var(--text-muted); }
  .edit { padding: 18px 14px; display: grid; grid-template-columns: 1fr; gap: 20px; }
  @media (min-width: 820px) { .edit { grid-template-columns: 260px 1fr; gap: 24px; } }
  .preview img, .preview .ph { width: 100%; aspect-ratio: 4/3; object-fit: contain; border-radius: 14px;
                               background: #FFFFFF; box-shadow: inset 0 0 0 1px var(--border-subtle); display: block; }
  .preview .ph { display: grid; place-items: center; font-family: var(--font-sans); font-size: 12px; color: var(--text-muted);
                 background: var(--surface-sunken); box-shadow: none; }
  label.f { display: flex; flex-direction: column; gap: 5px; font-size: 13px; font-weight: 600;
            color: var(--text-strong); margin-bottom: 12px; }
  label.f input { font-weight: 400; }
  label.f small { font-weight: 400; color: var(--text-muted); font-size: 12px; }
  label.f small.err { color: var(--danger); font-weight: 600; }
  label.chk { display: flex; gap: 10px; align-items: flex-start; font-size: 13.5px; cursor: pointer; margin-bottom: 10px; }
  label.chk input { accent-color: var(--brand); }
  button.danger { background: transparent; border-color: color-mix(in srgb, var(--danger) 45%, transparent); color: var(--danger); }
  a.view { font-size: 13px; color: var(--brand); font-weight: 600; text-decoration: none; }
  a.view:hover { text-decoration: underline; }
  fieldset.fs { grid-column: 1 / -1; border: 1px solid var(--border-subtle); border-radius: 12px; padding: 12px 14px 4px; margin: 0 0 14px; }
  fieldset.fs legend { font-size: 13px; font-weight: 700; color: var(--text-strong); padding: 0 6px; }
  fieldset.fs legend small { font-weight: 400; color: var(--text-muted); }
  .props-row { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 3fr); gap: 8px; margin-bottom: 8px; }
  .props-row input { width: 100%; }
  .nutri-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 0 12px; }
CSS, '');
console_flash($flash, $kind);
console_crumbs(['Pulpit' => 'pulpit.php', 'Produkty' => null]);
?>
  <?php if (!$isAdmin): ?>
    <p class="warnbox">Twoja rola pozwala tylko przeglądać. Zmiany może zapisywać rola <b>Centrala</b>.</p>
  <?php elseif (!$writable): ?>
    <p class="warnbox">Katalog <code>shop/media/</code> nie jest zapisywalny — wgrywanie zdjęć nie zadziała.
      Na serwerze: <code>chown www-data shop/media</code>.</p>
  <?php endif; ?>

  <p class="hint">
    Zdjęcie wgrywasz tutaj — plik jest po stronie serwera <b>ponownie zakodowany</b> i zmniejszony
    (maks. 1400 px, WebP), więc do sklepu trafia lekki obraz bez metadanych. Przyjmujemy JPEG, PNG,
    WebP i GIF do 8 MB. Zamiast pliku możesz też podać adres <code>https://</code>.
    Produkt pojawia się w sklepie dopiero po zaznaczeniu <b>W sprzedaży</b> i nadaniu adresu (slug).
  </p>

  <div class="kpis">
    <div class="kpi"><b><?= $nVisible ?></b><span>W sprzedaży</span></div>
    <div class="kpi"><b><?= $nPhoto ?> / <?= $nVisible ?></b><span>Ze zdjęciem</span></div>
    <div class="kpi"><b><?= count($rows) ?></b><span>Produkty w bazie</span></div>
  </div>

  <?php if ($isAdmin): ?>
  <?php // TROIS CHAMPS, PAS QUINZE. Le reste a des défauts et s'édite sur la
        // fiche qui s'ouvre juste après : un formulaire de création qui
        // redemanderait tout serait un second endroit à tenir à jour, et il
        // divergerait au premier champ ajouté.
        $nErr = fn(string $k) => isset($fieldErrors[$k]) ? '<small class="err">' . h($fieldErrors[$k]) . '</small>' : ''; ?>
  <?php endif; ?>

  <?php
  // ─── UNE SEULE FICHE, POUR CRÉER COMME POUR MODIFIER ──────────────────────
  //
  // « Le nouveau produit doit avoir exactement le même formulaire que la
  // fiche » — et la bonne façon de le tenir n'est pas de recopier le
  // formulaire, c'est de n'en avoir qu'UN. Deux copies divergent au premier
  // champ ajouté, et c'est celle qu'on utilise le moins qui devient fausse :
  // ici la création, donc le moment où l'on décide de tout.
  //
  // $nowy ne change que trois choses : le titre du repli, le bouton posté, et
  // les gestes qui n'ont pas de sens sur un produit qui n'existe pas encore
  // (supprimer, désactiver, voir en boutique).
  $fiszka = function (array $p, bool $nowy = false) use (&$fieldErrors, &$enCreation, $openId, $isAdmin, $csrf, $kategorie, $brands, $txt, $vv, $slugProp, $pdo) {
    // Les fiches se dessinent l'une après l'autre : ce drapeau ne vaut que
    // pour celle qu'on est en train de rendre.
    $enCreation = $nowy && (bool) $fieldErrors;

    // Un produit qui n'existe pas encore n'a aucune de ces valeurs : tout
    // passe par ?? pour que la MÊME fiche serve les deux cas.
    $id  = (string) ($p['id'] ?? '');
    $img = (string) ($p['image_url'] ?? '');
    $vis = (int) ($p['shop_visible'] ?? 0) === 1;
    $act = (int) ($p['active'] ?? 1) === 1;
    // À la création, la fiche s'ouvre seule si l'enregistrement a été refusé :
    // sinon la saisie conservée resterait cachée derrière un repli fermé.
    $open = $nowy ? (bool) $fieldErrors : ($openId !== '' && $openId === $id);
    $p += ['id' => '', 'nom' => '', 'prix' => 0, 'slug' => '', 'unit_label' => '', 'origin' => '',
           'cocoa' => '', 'badge' => '', 'vat_rate' => 0.23, 'stock' => 0, 'cat' => '',
           'weight_g' => 0, 'length_mm' => 0, 'width_mm' => 0, 'height_mm' => 0,
           'parcel_template' => '', 'brand_id' => null, 'category_id' => 0]; ?>
  <details class="item<?= $nowy ? ' nowa' : '' ?>"<?= $open ? ' open' : '' ?> id="p-<?= h($id ?: 'nowy') ?>">
    <summary>
      <?php if ($img !== ''): ?>
        <img class="thumb" src="<?= h(img_src($img)) ?>" alt="">
      <?php else: ?>
        <div class="thumb empty">brak<br>zdjęcia</div>
      <?php endif; ?>
      <div>
        <div class="sum-name"><?= $nowy ? '+ Nowy produkt' : h((string) $p['nom']) ?></div>
        <div class="sum-meta"><?php if ($nowy): ?>Ta sama fiszka co przy edycji — wypełniasz, co wiesz, resztę później.<?php else: ?>
          <?= h($id) ?> · <?= h((string) ($p['cat'] ?? '')) ?> · <?= h(zl($p['prix'])) ?>
          · VAT <?= h(wsm_vat_percent((float) ($p['vat_rate'] ?? 0.23))) ?> %<?php endif; ?></div>
      </div>
      <div class="sum-right">
        <?php if ($vis && $img === ''): ?><span class="tag no">bez zdjęcia</span><?php endif; ?>
        <?php if (!$act): ?><span class="tag bad">wyłączony</span><?php endif; ?>
        <span class="tag <?= $vis ? 'on' : 'off' ?>"><?= $vis ? 'W sprzedaży' : 'Ukryty' ?></span>
        <span class="tag">stan <?= (int) $p['stock'] ?></span>
      </div>
    </summary>

    <form class="edit" method="post" enctype="multipart/form-data" action="produkty.php?id=<?= h(urlencode($id)) ?>#p-<?= h($id) ?>">
      <input type="hidden" name="_t" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= h($id) ?>">

      <div class="preview">
        <?php if ($img !== ''): ?>
          <img src="<?= h(img_src($img)) ?>" alt="<?= h((string) $p['nom']) ?>">
          <?php if ($isAdmin): ?>
          <label class="chk" style="margin-top:12px">
            <input type="checkbox" name="remove_image" value="1"><span>Usuń zdjęcie przy zapisie</span>
          </label>
          <?php endif; ?>
        <?php else: ?>
          <div class="ph">Bez zdjęcia</div>
        <?php endif; ?>
        <?php if (!$nowy && $vis && $p['slug'] !== ''): ?>
          <p style="margin:12px 0 0"><a class="view" href="../shop/p/<?= h(urlencode((string) $p['slug'])) ?>" target="_blank" rel="noopener">Zobacz w sklepie ↗</a></p>
        <?php endif; ?>
      </div>

      <div>
        <div class="grid2">
          <label class="f">Zdjęcie (plik)
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php if (isset($fieldErrors['photo']) && $open): ?>
              <small class="err"><?= h($fieldErrors['photo']) ?></small>
            <?php else: ?><small>JPEG · PNG · WebP · GIF, maks. 8 MB</small><?php endif; ?>
          </label>
          <label class="f">…albo adres obrazu
            <input type="url" name="image_url" value="<?= h(str_starts_with($img, 'media/') ? '' : $img) ?>"
                   placeholder="https://…"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php if (isset($fieldErrors['image_url']) && $open): ?>
              <small class="err"><?= h($fieldErrors['image_url']) ?></small>
            <?php else: ?><small>Tylko https — inaczej przeglądarka zablokuje obraz.</small><?php endif; ?>
          </label>

          <?php // LE NOM ET LA DESCRIPTION EN PREMIER. Ce sont les deux
                // choses qu'on vient changer le plus souvent sur un produit,
                // et elles n'étaient sur AUCUN écran de produit. ?>
          <label class="f" style="grid-column:1/-1">Nazwa produktu
            <input type="text" name="nazwa" value="<?= h($vv('nazwa', $txt((string) $p['id'], 'name'))) ?>"
                   maxlength="120"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php if (isset($fieldErrors['nazwa']) && $open): ?><small class="err"><?= h($fieldErrors['nazwa']) ?></small><?php endif; ?>
            <small>Widoczna w sklepie i na dokumentach. Tłumaczenia — w <a class="view" href="tresci.php?sekcja=product">Treściach</a>.</small>
          </label>
          <label class="f" style="grid-column:1/-1">Podtytuł
            <input type="text" name="podtytul" value="<?= h($vv('podtytul', $txt((string) $p['id'], 'subtitle'))) ?>"
                   maxlength="200"<?= $isAdmin ? '' : ' disabled' ?>>
            <small>Jedna linia pod nazwą na karcie produktu.</small>
          </label>
          <label class="f" style="grid-column:1/-1">Opis
            <textarea name="opis" rows="6"<?= $isAdmin ? '' : ' disabled' ?>><?= h($vv('opis', $txt((string) $p['id'], 'desc'))) ?></textarea>
            <small>Tekst na stronie produktu. Pusta linia = nowy akapit, „- ” na początku linii = punkt listy, **pogrubienie**.
              Opakowanie, skład czy wartości odżywcze wpisz niżej — sklep pokaże je w osobnych tabelach.</small>
          </label>

          <?php if (!$nowy):
            // LES PROPRIÉTÉS ET LES VALEURS NUTRITIVES — en champs, plus dans
            // la description. Les lignes remplies, plus trois vides : il en
            // vient trois autres à chaque enregistrement, jusqu'à douze.
            $pid = (string) $p['id'];
            $refait = fn(string $grp, string $k, string $i, string $def) =>
                ($fieldErrors && $openId === $pid && isset($_POST[$grp][$k][$i])) ? (string) $_POST[$grp][$k][$i] : $def;
            $derniere = 0;
            for ($n = 1; $n <= WSM_PRODUCT_PROPS_MAX; $n++) if ($txt($pid, "prop.$n.k") !== '' || $txt($pid, "prop.$n.v") !== '') $derniere = $n;
            $exemples = [1 => ['Opakowanie', 'Zgrzewany worek z zapięciem strunowym'], 2 => ['Opakowanie zbiorcze', '10 kg (4 × 2,5 kg), karton'],
                         3 => ['Skład', 'cukier, masło kakaowe, mleko w proszku…'], 4 => ['Przechowywanie', 'W suchym i zacienionym miejscu'],
                         5 => ['Kraj pochodzenia', 'Belgia']]; ?>
          <fieldset class="fs">
            <legend>Właściwości produktu <small>— tabela pod opisem w sklepie. Tłumaczenia: Treści → product.<?= h($pid) ?>.prop</small></legend>
            <?php for ($n = 1; $n <= min(WSM_PRODUCT_PROPS_MAX, max($derniere + 3, 5)); $n++): ?>
            <div class="props-row">
              <input type="text" name="prop[<?= $n ?>][k]" maxlength="80" aria-label="Nazwa <?= $n ?>"
                     value="<?= h($refait('prop', (string) $n, 'k', $txt($pid, "prop.$n.k"))) ?>"
                     placeholder="<?= h($exemples[$n][0] ?? 'Nazwa') ?>"<?= $isAdmin ? '' : ' disabled' ?>>
              <input type="text" name="prop[<?= $n ?>][v]" maxlength="300" aria-label="Wartość <?= $n ?>"
                     value="<?= h($refait('prop', (string) $n, 'v', $txt($pid, "prop.$n.v"))) ?>"
                     placeholder="<?= h($exemples[$n][1] ?? 'Wartość') ?>"<?= $isAdmin ? '' : ' disabled' ?>>
            </div>
            <?php endfor; ?>
            <small class="muted">Wiersz bez nazwy albo bez wartości się nie pokazuje. Pochodzenie, kakao, gramatura i EAN dochodzą same z pól niżej.</small>
          </fieldset>

          <fieldset class="fs">
            <legend>Wartości odżywcze w 100 g <small>— same liczby, np. 32,5 albo &lt;0,5; jednostki dopisuje sklep. Puste = bez tabeli.</small></legend>
            <input type="hidden" name="nutri__on" value="1">
            <?php $nAkt = wsm_nutrition_decode((string) ($p['nutrition'] ?? ''));
                  $nLbl = ['energy_kj' => 'Wartość energetyczna (kJ)', 'energy_kcal' => 'Wartość energetyczna (kcal)', 'fat' => 'Tłuszcz (g)',
                           'saturates' => 'w tym kwasy nasycone (g)', 'carbs' => 'Węglowodany (g)', 'sugars' => 'w tym cukry (g)',
                           'fibre' => 'Błonnik (g)', 'protein' => 'Białko (g)', 'salt' => 'Sól (g)']; ?>
            <div class="nutri-grid">
              <?php foreach (array_keys(WSM_NUTRITION_FIELDS) as $nk):
                    $nVal = ($fieldErrors && $openId === $pid && isset($_POST['nutri'][$nk])) ? (string) $_POST['nutri'][$nk] : (string) ($nAkt[$nk] ?? ''); ?>
              <label class="f"><?= h($nLbl[$nk]) ?>
                <input type="text" name="nutri[<?= h($nk) ?>]" value="<?= h($nVal) ?>" inputmode="decimal" maxlength="12"<?= $isAdmin ? '' : ' disabled' ?>>
                <?php if (isset($fieldErrors['nutri_' . $nk]) && $openId === $pid): ?><small class="err"><?= h($fieldErrors['nutri_' . $nk]) ?></small><?php endif; ?>
              </label>
              <?php endforeach; ?>
            </div>
          </fieldset>
          <?php endif; ?>

          <label class="f">Adres w sklepie (slug)
            <?php $slugVal = (string) $p['slug'] !== ''
                    ? (string) $p['slug']
                    : $slugProp($txt((string) $id, 'name') ?: (string) $p['nom'], (string) $id); ?>
            <input type="text" name="slug" value="<?= h($vv('slug', $slugVal)) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php if (isset($fieldErrors['slug']) && $open): ?>
              <small class="err"><?= h($fieldErrors['slug']) ?></small>
            <?php else: ?><small>/shop/p/<b><?= h((string) $p['slug'] ?: '…') ?></b></small><?php endif; ?>
          </label>
          <label class="f">Cena brutto (zł)
            <input type="text" name="prix" value="<?= h($vv('prix', number_format((float) $p['prix'], 2, ',', ''))) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
            <small>Cena widoczna dla klienta, z VAT.</small>
          </label>

          <?php $rate = (float) ($p['vat_rate'] ?? 0.23);
                [$netG, $vatG] = wsm_split_vat(wsm_grosze($p['prix']), $rate); ?>
          <?php // GRAMATURA ET WYMIARY. Absents de tous les écrans jusqu'ici,
                // alors qu'ils choisissent le gabarit InPost et donc le prix
                // payé pour expédier. ?>
          <label class="f">Rodzaj
            <select name="rodzaj"<?= $isAdmin ? '' : ' disabled' ?>>
              <?php $rodzAkt = wsm_rodzaj((string) $vv('rodzaj', (string) ($p['rodzaj'] ?? 'towar')));
                    foreach (WSM_RODZAJE as $rk => $rl): ?>
              <option value="<?= h($rk) ?>"<?= $rodzAkt === $rk ? ' selected' : '' ?>><?= h($rl) ?></option>
              <?php endforeach; ?>
            </select>
            <small>Usługa i licencja nie wymagają wysyłki: koszyk bez towaru dostaje tylko metodę „bez wysyłki” (Dostawa).</small>
          </label>
          <label class="f">Gramatura (g)
            <input type="text" name="weight_g" value="<?= h($vv('weight_g', (string) (int) ($p['weight_g'] ?? 0))) ?>"
                   placeholder="1000"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php if (isset($fieldErrors['weight_g']) && $open): ?>
              <small class="err"><?= h($fieldErrors['weight_g']) ?></small>
            <?php else: ?><small>Waga produktu. Wchodzi w wagę paczki.</small><?php endif; ?>
          </label>
          <label class="f">Wymiary (mm) — dł. × szer. × wys.
            <span class="wym">
              <input type="text" name="length_mm" value="<?= h($vv('length_mm', (string) (int) ($p['length_mm'] ?? 0))) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
              <input type="text" name="width_mm"  value="<?= h($vv('width_mm',  (string) (int) ($p['width_mm'] ?? 0))) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
              <input type="text" name="height_mm" value="<?= h($vv('height_mm', (string) (int) ($p['height_mm'] ?? 0))) ?>"<?= $isAdmin ? '' : ' disabled' ?>>
            </span>
            <small>Gabaryt paczkomatu wylicza się z tych trzech liczb<?php
              $gab = (string) ($p['parcel_template'] ?? ''); echo $gab !== '' ? ' — teraz: <b>' . h($gab) . '</b>' : ''; ?>.</small>
          </label>

          <?php // LA CATÉGORIE N'ÉTAIT MODIFIABLE NULLE PART. Obligatoire en
                // base, posée au semis, figée ensuite : un produit rangé au
                // mauvais endroit y restait. ?>
          <label class="f">Kategoria
            <?php // PAS de « required » : on peut tout aussi bien taper un nom neuf
                  // dans le champ d'à côté, et le navigateur refuserait d'envoyer
                  // le formulaire en réclamant un choix dans la liste. Le
                  // contrôle reste côté serveur, où il voit les deux champs. ?>
            <select name="category_id"<?= $isAdmin ? '' : ' disabled' ?>>
              <?php if ($nowy): ?><option value="">— wybierz —</option><?php endif; ?>
              <?php $catAkt = (int) $vv('category_id', (string) (int) $p['category_id']);
                    foreach ($kategorie as $k): ?>
              <option value="<?= (int) $k['id'] ?>"<?= $catAkt === (int) $k['id'] ? ' selected' : '' ?>><?= (int) $k['parent_id'] > 0 ? '↳ ' : '' ?><?= h((string) $k['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($fieldErrors['category_id']) && $open): ?>
              <small class="err"><?= h($fieldErrors['category_id']) ?></small>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <?php // Taper un nom ici crée le rayon et y range le produit, du
                  // même geste. Un nom déjà pris — y compris un rayon éteint —
                  // rallume celui qui existe au lieu de refuser. ?>
            <input type="text" name="kat_nowa" maxlength="80" placeholder="albo wpisz nową kategorię"
                   value="<?= h($vv('kat_nowa', '')) ?>">
            <small>Nowa nazwa tworzy kategorię i przypisuje do niej produkt.
              Wszystkie kategorie <a class="view" href="#kategorie">niżej na tej stronie</a>.</small>
            <?php else: ?><small>Kategorie zmieniasz <a class="view" href="#kategorie">niżej na tej stronie</a>.</small><?php endif; ?>
          </label>

          <label class="f">Stawka VAT
            <select name="vat_rate"<?= $isAdmin ? '' : ' disabled' ?>>
              <?php foreach (WSM_VAT_RATES as $r): ?>
              <option value="<?= h((string) $r) ?>"<?= abs($r - $rate) < 0.0005 ? ' selected' : '' ?>><?= h(wsm_vat_percent($r)) ?> %</option>
              <?php endforeach; ?>
              <?php if (!array_filter(WSM_VAT_RATES, fn($r) => abs($r - $rate) < 0.0005)): ?>
              <option value="<?= h((string) $rate) ?>" selected><?= h(wsm_vat_percent($rate)) ?> % (nietypowa)</option>
              <?php endif; ?>
            </select>
            <?php if (isset($fieldErrors['vat_rate']) && $open): ?>
              <small class="err"><?= h($fieldErrors['vat_rate']) ?></small>
            <?php else: ?>
              <small>Z ceny brutto: <b><?= h(number_format($netG / 100, 2, ',', ' ')) ?> zł netto</b>
                + <?= h(number_format($vatG / 100, 2, ',', ' ')) ?> zł VAT. Tak trafi na fakturę.</small>
            <?php endif; ?>
          </label>

          <label class="f">Stan magazynowy
            <input type="number" name="stock" min="0" value="<?= (int) $p['stock'] ?>"<?= $isAdmin ? '' : ' disabled' ?>>
            <?php if (isset($fieldErrors['stock']) && $open): ?>
              <small class="err"><?= h($fieldErrors['stock']) ?></small>
            <?php else: ?><small>Zamówienie ponad stan przechodzi — klient dostaje mail „skontaktujemy się”.</small><?php endif; ?>
          </label>
          <label class="f">Powód zmiany stanu
            <input type="text" name="stock_reason" placeholder="np. inwentaryzacja, stłuczka"<?= $isAdmin ? '' : ' disabled' ?>>
            <small>Zapisywany w <a class="view" href="magazyn.php">Magazynie</a>. Zostaw puste, jeśli stanu nie zmieniasz.</small>
          </label>
          <label class="f">Gramatura
            <input type="text" name="unit_label" value="<?= h($vv('unit_label', (string) $p['unit_label'])) ?>" placeholder="1 kg"<?= $isAdmin ? '' : ' disabled' ?>>
            <small>Pokazywana na karcie produktu.</small>
          </label>

          <label class="f">Marka
            <select name="brand_id"<?= $isAdmin ? '' : ' disabled' ?>>
              <option value="">— bez marki —</option>
              <?php foreach ($brands as $b): ?>
              <option value="<?= (int) $b['id'] ?>"<?= (int) ($p['brand_id'] ?? 0) === (int) $b['id'] ? ' selected' : '' ?>>
                <?= h((string) $b['name']) ?><?= (int) $b['active'] === 1 ? '' : ' (wyłączona)' ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small>Logo pokazuje się na kafelku i na karcie produktu w sklepie.
              Marki dodaje się <a class="view" href="produkty.php#marki">niżej na tej stronie</a>.</small>
          </label>

          <label class="f">Pochodzenie
            <input type="text" name="origin" value="<?= h($vv('origin', (string) $p['origin'])) ?>" placeholder="Madagaskar"<?= $isAdmin ? '' : ' disabled' ?>>
          </label>
          <label class="f">Kakao
            <input type="text" name="cocoa" value="<?= h($vv('cocoa', (string) $p['cocoa'])) ?>" placeholder="70 %"<?= $isAdmin ? '' : ' disabled' ?>>
          </label>

          <label class="f">Etykieta
            <input type="text" name="badge" value="<?= h($vv('badge', (string) $p['badge'])) ?>" placeholder="bestseller"<?= $isAdmin ? '' : ' disabled' ?>>
            <small>bestseller · nowosc · prezent — tłumaczone w wsm_shop_i18n.</small>
          </label>
          <div></div>
        </div>

        <label class="chk" style="margin-top:14px">
          <input type="checkbox" name="shop_visible" value="1"<?= $vis ? ' checked' : '' ?><?= $isAdmin ? '' : ' disabled' ?>>
          <span><b>W sprzedaży</b><br><small style="color:var(--text-muted)">Widoczny w sklepie i możliwy do kupienia.</small></span>
        </label>

        <?php if ($isAdmin): ?>
        <div class="actions">
          <?php if ($nowy): ?>
          <button class="primary" type="submit" name="nowy" value="1">Utwórz produkt</button>
          <small style="color:var(--text-muted)">Powstaje NIEWIDOCZNY w sklepie — sprzedaż włączasz sam, gdy fiszka jest gotowa.</small>
          <?php else: ?>
          <button class="primary" type="submit">Zapisz</button>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </form>

    <?php // Désactiver et supprimer n'ont pas de sens sur un produit qui
          // n'existe pas encore. ?>
    <?php if ($isAdmin && !$nowy): ?>
    <div class="edit" style="padding-top:0">
      <div></div>
      <div>
        <h3 style="font-family:var(--font-display);font-size:15px;margin:0 0 6px">Cykl życia produktu</h3>
        <p style="font-size:12.5px;color:var(--text-muted);line-height:1.6;margin:0 0 10px">
          <b>Wyłączenie</b> zdejmuje produkt ze sklepu i z magazynu, ale zostawia historię:
          dawne zamówienia i faktury nadal go nazywają. To niemal zawsze właściwy ruch.
          <b>Usunięcie</b> jest możliwe tylko wtedy, gdy nic się do produktu nie odwołuje —
          inaczej dokumenty przestałyby być czytelne.
        </p>
        <div class="actions">
          <form method="post">
            <input type="hidden" name="_t" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= h($id) ?>">
            <button type="submit" name="aktywacja" value="<?= $act ? '0' : '1' ?>">
              <?= $act ? 'Wyłącz produkt' : 'Włącz produkt' ?>
            </button>
          </form>
          <form method="post" onsubmit="return confirm('Usunąć produkt <?= h($id) ?> bezpowrotnie?')">
            <input type="hidden" name="_t" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= h($id) ?>">
            <button class="danger" type="submit" name="usun" value="1">Usuń trwale</button>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </details>
  <?php }; ?>

  <?php // La fiche de création, en tête : on la trouve là où on la cherche. ?>
  <?php if ($isAdmin) $fiszka([], true); ?>

  <?php foreach ($rows as $p) $fiszka($p); ?>


<?php
// ---------------------------------------------------------------------------
//  Marques
//
//  Une marque vit ici plutôt que sur chaque fiche produit : le logo, le nom et
//  l'adresse du site se corrigent UNE fois et se voient partout. La fiche
//  produit ne fait que la désigner.
// ---------------------------------------------------------------------------
$editB = isset($_GET['marka']) ? wsm_brand($pdo, (int) $_GET['marka']) : null;
?>
<div class="panel" id="kategorie">
  <h2>Kategorie</h2>
  <p class="lead">
    Każdy produkt musi mieć kategorię — to pole obowiązkowe w bazie. Kategorii się NIE USUWA:
    produkty, które ją noszą, zostałyby z martwym odwołaniem. Wyłączona kategoria znika z list
    wyboru, ale nie rusza tego, co już jest do niej przypisane.
  </p>
  <table class="rwd dense">
    <thead><tr><th>Nazwa i kategoria nadrzędna</th><th class="num">Produkty</th><th>Stan</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($katWszystkie as $k): ?>
    <tr>
      <td data-l="Nazwa">
        <?php if ($isAdmin): ?>
        <form method="post" class="kat-in">
          <input type="hidden" name="_t" value="<?= h($csrf) ?>">
          <input type="text" name="kat_nazwa" value="<?= h((string) $k['name']) ?>" maxlength="80" required>
          <?= $rodzicSelect((int) $k['parent_id'], (int) $k['id']) ?>
          <button type="submit" name="kat_zapisz" value="<?= (int) $k['id'] ?>">Zapisz</button>
        </form>
        <?php else: ?><?= (int) $k['parent_id'] > 0 ? '↳ ' : '' ?><?= h((string) $k['name']) ?><?php endif; ?>
      </td>
      <td data-l="Produkty" class="num"><?= (int) $k['ile'] ?></td>
      <td data-l="Stan"><span class="stan <?= (int) $k['active'] ? 'p-ok' : 'p-bad' ?>"><i class="kropka"></i><?= (int) $k['active'] ? 'Aktywna' : 'Wyłączona' ?></span></td>
      <td>
        <?php if ($isAdmin): ?>
        <form method="post">
          <input type="hidden" name="_t" value="<?= h($csrf) ?>">
          <input type="hidden" name="kat_on" value="<?= (int) $k['active'] ? '0' : '1' ?>">
          <button type="submit" name="kat_akt" value="<?= (int) $k['id'] ?>"><?= (int) $k['active'] ? 'Wyłącz' : 'Włącz' ?></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($isAdmin): ?>
  <form method="post" class="kat-in" style="margin-top:14px">
    <input type="hidden" name="_t" value="<?= h($csrf) ?>">
    <input type="text" name="kat_nazwa" placeholder="Nowa kategoria" maxlength="80" required>
    <?= $rodzicSelect(0) ?>
    <button class="primary" type="submit" name="kat_zapisz" value="0">Dodaj kategorię</button>
  </form>
  <?php endif; ?>
</div>

<div class="panel" id="marki">
  <h2>Marki</h2>
  <p class="muted small">
    Logo marki pokazuje się na kafelku w katalogu i na karcie produktu w sklepie.
    Wgrywany plik zachowuje <b>przezroczystość</b> — logo z przezroczystym tłem nie dostanie
    kremowego prostokąta. Marka bez logo pokazuje swoją nazwę: pusty kadr wygląda jak zepsuty obrazek.
  </p>

  <?php if ($brands): ?>
  <div class="tablewrap">
  <table class="rwd">
    <thead><tr><th>Logo</th><th>Nazwa</th><th>Strona</th><th class="num">Produkty</th><th>Stan</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($brands as $b): $n = $brandCounts[(int) $b['id']] ?? 0; ?>
    <tr>
      <td data-l="Logo">
        <?php if ((string) $b['logo_url'] !== ''): ?>
          <img src="<?= h(img_src((string) $b['logo_url'])) ?>" alt="<?= h((string) $b['name']) ?>"
               style="height:26px;width:auto;max-width:110px;object-fit:contain;display:block">
        <?php else: ?><span class="muted small">brak logo</span><?php endif; ?>
      </td>
      <td data-l="Nazwa"><b><?= h((string) $b['name']) ?></b><br>
        <code class="muted" style="font-size:11.5px"><?= h((string) $b['slug']) ?></code></td>
      <td data-l="Strona"><?= (string) $b['site_url'] !== ''
            ? '<a href="' . h((string) $b['site_url']) . '" target="_blank" rel="noopener">otwórz ↗</a>'
            : '<span class="muted">—</span>' ?></td>
      <td data-l="Produkty" class="num"><?= $n ?></td>
      <td data-l="Stan"><span class="tag <?= (int) $b['active'] === 1 ? 'ok' : 'off' ?>">
            <?= (int) $b['active'] === 1 ? 'czynna' : 'wyłączona' ?></span></td>
      <td data-l="">
        <?php if ($isAdmin): ?>
        <div class="actions">
          <a class="code" href="produkty.php?marka=<?= (int) $b['id'] ?>#marki">Edytuj</a>
          <?php if ($n === 0): ?>
          <form method="post" onsubmit="return confirm('Usunąć markę <?= h((string) $b['name']) ?>?')">
            <input type="hidden" name="_t" value="<?= h($csrf) ?>">
            <button class="danger" type="submit" name="marka_usun" value="<?= (int) $b['id'] ?>">Usuń</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <p class="muted small">Nie ma jeszcze żadnej marki.</p>
  <?php endif; ?>

  <?php if ($isAdmin): ?>
  <h3><?= $editB ? 'Edycja marki — ' . h((string) $editB['name']) : 'Nowa marka' ?></h3>
  <form method="post" enctype="multipart/form-data" class="grid2">
    <input type="hidden" name="_t" value="<?= h($csrf) ?>">
    <input type="hidden" name="marka_zapisz" value="<?= (int) ($editB['id'] ?? 0) ?>">
    <label class="field"><span>Nazwa</span>
      <input type="text" name="name" value="<?= h((string) ($editB['name'] ?? '')) ?>" required maxlength="120"></label>
    <label class="field"><span>Strona marki (https://)</span>
      <input type="url" name="site_url" value="<?= h((string) ($editB['site_url'] ?? '')) ?>" placeholder="https://"></label>
    <label class="field"><span>Logo (PNG z przezroczystością, SVG nie)</span>
      <input type="file" name="logo" accept="image/png,image/webp,image/jpeg,image/gif"></label>
    <label class="field"><span>Kolejność</span>
      <input type="number" name="sort_order" value="<?= (int) ($editB['sort_order'] ?? 0) ?>" style="max-width:120px"></label>
    <label class="field" style="grid-column:1/-1"><span>Notatka wewnętrzna</span>
      <input type="text" name="note" value="<?= h((string) ($editB['note'] ?? '')) ?>" maxlength="255"
             placeholder="np. kontakt handlowy, warunki"></label>
    <label class="field" style="display:flex;align-items:center;gap:8px">
      <input type="checkbox" name="active" value="1" <?= !$editB || (int) $editB['active'] === 1 ? 'checked' : '' ?>>
      <span style="margin:0">Czynna — widoczna w sklepie</span></label>
    <?php if ($editB && (string) $editB['logo_url'] !== ''): ?>
    <label class="field" style="display:flex;align-items:center;gap:8px">
      <input type="checkbox" name="remove_logo" value="1">
      <span style="margin:0">Usuń obecne logo</span></label>
    <?php endif; ?>
    <div class="actions" style="grid-column:1/-1">
      <button class="primary" type="submit"><?= $editB ? 'Zapisz markę' : 'Dodaj markę' ?></button>
      <?php if ($editB): ?><a class="code" href="produkty.php#marki">Anuluj</a><?php endif; ?>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php console_foot();
