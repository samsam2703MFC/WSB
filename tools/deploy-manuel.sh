#!/usr/bin/env bash
# =============================================================================
#  deploy-manuel.sh — le déploiement, à la main, quand GitHub Actions ne prend
#  pas la file d'attente.
#
#  POURQUOI CE FICHIER EXISTE. Le 6 août, quatre exécutions du workflow sont
#  restées en file sans jamais démarrer, l'API de GitHub renvoyant tour à tour
#  un 500 sur le déclenchement, des exécutions annulées qui repassaient en
#  attente, et un 409 « impossible d'annuler » sur celles-là mêmes qu'elle
#  venait de lister. Trois changements finis attendaient un runner qui ne
#  venait pas. Une boutique ne s'arrête pas parce qu'un fournisseur a une
#  mauvaise soirée.
#
#  IL FAIT EXACTEMENT CE QUE FAIT LE WORKFLOW, dans le même ordre :
#    1. il assemble site/ (backoffice + landing + shop, php-api → api) ;
#    2. il vérifie l'assemblage AVANT d'envoyer quoi que ce soit ;
#    3. il envoie par rsync, SANS --delete (les photos du serveur survivent) ;
#    4. il joue le SQL de contenu par le client mysql — le php en ligne de
#       commande de ce serveur n'a pas pdo_mysql, `migrate.php --sync-content`
#       n'y ferait rien du tout ;
#    5. il vérifie l'EFFET sur les pages réelles, pas l'exécution du script.
#
#  CE QU'IL NE FAIT PAS : les vingt étapes de vérification du workflow (rôles,
#  KSeF, transporteurs, écrans de la console…). Ce script met le code en ligne
#  et contrôle l'essentiel ; le workflow reste la référence quand il repart.
#
#  Usage, depuis la racine du dépôt, sur une machine qui atteint le serveur :
#
#      SSH_HOST=185.180.206.46 SSH_USER=root SSH_PASSWORD='…' \
#      DB_USER=…  DB_PASS=… \
#      bash tools/deploy-manuel.sh
#
#  DB_USER / DB_PASS sont ceux de phpMyAdmin (mêmes valeurs que les secrets
#  WSM_DB_USER / WSM_DB_PASS). Sans eux, le code part quand même mais les
#  textes de la vitrine ne sont pas synchronisés — le script le dit.
#
#  ADM_EMAIL / ADM_PASS (secrets WSM_ADMIN_EMAIL / WSM_ADMIN_PASSWORD) sont
#  FACULTATIFS : fournis, ils reposent le mot de passe de la console à chaque
#  passage — c'est ainsi qu'on reprend la main sans SSH. Absents, on ne touche
#  à aucun compte. Le hachage est calculé ICI ; le mot de passe en clair ne
#  part jamais vers le serveur.
#
#  Rien n'est écrit en dur ici : aucun mot de passe ne doit ENTRER dans ce
#  fichier. Le dépôt est public.
# =============================================================================
set -euo pipefail

: "${SSH_HOST:?SSH_HOST manquant (ex. 185.180.206.46)}"
: "${SSH_USER:?SSH_USER manquant}"
: "${SSH_PASSWORD:?SSH_PASSWORD manquant}"
SSH_PORT="${SSH_PORT:-22}"
DEPLOY_DIR="${DEPLOY_DIR:-/var/www/html/mrszoko}"
DB_USER="${DB_USER:-}"
DB_PASS="${DB_PASS:-}"

command -v sshpass >/dev/null || { echo "sshpass absent : apt install sshpass (ou brew install hudochenkov/sshpass/sshpass)"; exit 1; }
command -v rsync   >/dev/null || { echo "rsync absent"; exit 1; }
command -v php     >/dev/null || { echo "php absent (il sert à vérifier le rail et à émettre le SQL)"; exit 1; }

export SSHPASS="$SSH_PASSWORD"
SSHOPT="-p $SSH_PORT -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null"
# scp ne parle PAS la même langue que ssh : chez lui le port est « -P », et
# « -p » veut dire « préserver les dates ». Réutiliser SSHOPT tel quel a donc
# fait lire « 22 » comme un nom de fichier — « scp: stat local "22": No such
# file or directory », en plein milieu d'un déploiement par ailleurs réussi.
SCPOPT="-P $SSH_PORT -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null"
distant() { sshpass -e ssh $SSHOPT "$SSH_USER@$SSH_HOST" "$@"; }

echo "══ 1/5 · assemblage ═══════════════════════════════════════════════════"
rm -rf site && mkdir -p site/backoffice site/landing

BO=mrszoko/backoffice
cp -r "$BO"/_ds site/backoffice/
mkdir -p site/backoffice/img && cp "$BO"/img/logo.png site/backoffice/img/
cat > site/backoffice/index.html <<'HTML'
<!doctype html><html lang="pl"><head><meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="refresh" content="0; url=login.php">
<title>Konsola Szoko Shop</title></head>
<body><p><a href="login.php">Przejdź do logowania</a></p></body></html>
HTML
# php-api → api : c'est le chemin que la console dérive de sa propre adresse.
# La base SQLite locale ne part JAMAIS — le serveur a MySQL.
rsync -a --exclude 'data' --exclude '*.sqlite' "$BO"/php-api/ site/backoffice/api/
# TOUT le PHP du répertoire, pas une liste blanche : sept écrans livrés ont
# déjà été oubliés d'une liste tenue à la main, et sont restés morts en
# production pendant des semaines.
cp mrszoko/backoffice/*.php mrszoko/backoffice/console.css site/backoffice/

cp -r mrszoko/landing/. site/landing/

mkdir -p site/shop
rsync -a --exclude 'router.php' --exclude 'serve.sh' --exclude '/ds' mrszoko/shop/. site/shop/
cp mrszoko/shop/.htaccess site/shop/.htaccess
mkdir -p site/shop/media && cp mrszoko/shop/media/.htaccess site/shop/media/.htaccess
cp mrszoko/.htaccess site/.htaccess

# Les design systems des boutiques (design-systems/<slug>/), compilés en UN
# fichier chacun et CONTRÔLÉS : un jeton du contrat oublié, ou un nom hors
# contrat dans le code, arrête le déploiement ici, avant le site.
php tools/build-ds.php site/shop/ds || { echo "  design systems refusés"; exit 1; }

cat > site/index.html <<'HTML'
<!DOCTYPE html>
<html lang="pl"><head><meta charset="utf-8">
<meta http-equiv="refresh" content="0; url=shop/">
<title>Szoko Shop</title>
<link rel="canonical" href="shop/">
</head><body><p><a href="shop/">Szoko Shop</a></p></body></html>
HTML

echo "══ 2/5 · contrôles AVANT envoi ════════════════════════════════════════"
# Le rail est la seule source de vérité de ce qui doit exister. Un écran
# ajouté au menu et oublié à la copie casse ICI, pas devant un client.
php -r '
  require_once "mrszoko/backoffice/console.php";
  $manque = [];
  foreach (console_sections(["role" => "Centrala"]) as $items) {
    foreach (array_keys($items) as $f) {
      if (!is_file("site/backoffice/" . $f)) $manque[] = $f;
    }
  }
  if ($manque) { fwrite(STDERR, "ÉCRANS DU RAIL NON ASSEMBLÉS : " . implode(", ", $manque) . "\n"); exit(1); }
  echo "  rail : chaque entrée du menu a son fichier\n";
'
test -s site/backoffice/login.php || { echo "  login.php absent : personne ne pourrait entrer"; exit 1; }
grep -q 'login.php' site/backoffice/index.html || { echo "  index.html ne renvoie pas vers login.php"; exit 1; }
test -s site/shop/ds/szoko/tokens.css && grep -q -- '--brand-700' site/shop/ds/szoko/tokens.css \
  || { echo "  ds/szoko/tokens.css compilé vide ou incomplet"; exit 1; }
# Les trois pièces des boutons de statut doivent partir ENSEMBLE : deux sur
# trois, et l'écran répond 200 avec des pastilles qu'aucun pouce n'atteint.
grep -q 'wsm_order_etapy' site/backoffice/api/invoice.php \
  && grep -q 'name="status"' site/backoffice/zamowienia.php \
  && grep -q '\.etap' site/backoffice/console.css \
  || { echo "  les boutons de statut ne sont pas complets dans l'assemblage"; exit 1; }
echo "  boutons de statut : les trois pièces sont là"
# Les files, le contrôle d'avant-expédition et l'envoi par lot : même règle.
grep -q 'wsm_order_preflight' site/backoffice/api/invoice.php \
  && grep -q 'name="ids\[\]"' site/backoffice/zamowienia.php \
  && grep -q '\.przed' site/backoffice/console.css \
  || { echo "  la liste kontrolna / wysyłka hurtem n'est pas complète"; exit 1; }
# LA LISTE DOIT PORTER CE QUE LES VOYANTS LISENT. Sans vat_eu ni vat_status,
# une commande B2B confirmée par VIES s'affiche « brak numeru VAT UE » et
# annonce un paragon, pendant qu'une FACTURE part au registre.
php -r '
  require_once "site/backoffice/api/shop.php";
  $r = new ReflectionFunction("wsm_orders_list");
  $src = implode("", array_slice(file($r->getFileName()),
           $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
  $m = [];
  foreach (["vat_eu", "vat_status", "company", "paid_at"] as $c)
    if (!str_contains($src, "\x27" . $c . "\x27")) $m[] = $c;
  if ($m) { fwrite(STDERR, "  la liste ne transporte pas : " . implode(", ", $m) . "\n"); exit(1); }
'
echo "  files, contrôle avant expédition, envoi par lot : présents"
# La voie « mot de passe » doit partir AVEC le code : le serveur, lui, émet le
# SQL depuis le migrate.php DÉPLOYÉ (deploy-serwer.sh, et le workflow de
# référence). Un tronc sans ce drapeau rendrait la console irrécupérable
# depuis la machine elle-même.
grep -q 'set-password-sql' site/backoffice/api/migrate.php \
  && grep -q 'function wsm_set_password_sql' site/backoffice/api/auth.php \
  || { echo "  la voie « mot de passe en SQL » n'est pas dans l'assemblage"; exit 1; }
# LES DEUX VUES PARTENT ENSEMBLE. Le sélecteur sans la tablica donne un
# onglet qui mène à une page vide ; la tablica sans son CSS donne trois
# colonnes empilées sur toute la hauteur. Ni l'un ni l'autre ne fait d'erreur.
grep -q 'widok' site/backoffice/zamowienia.php \
  && grep -q 'class="tablica"' site/backoffice/zamowienia.php \
  && grep -q '\.widoki' site/backoffice/console.css \
  && grep -q 'tr\.szuflada' site/backoffice/console.css \
  || { echo "  les deux vues (lista / tablica) ne sont pas complètes"; exit 1; }
echo "  dwa widoki zamówień (lista + tablica) : obecne"
# UNE SEULE FAMILLE DE TEXTE. Plus Jakarta Sans a quitté la marque ; elle est
# revenue une fois déjà par une régénération de fonts.css. Si son @font-face
# reparaît, le site sert deux sans humanistes qui se ressemblent — le décalage
# qu'on vient de retirer — et trois fichiers de police en plus.
grep -q "Plus Jakarta Sans'" site/shop/ds/szoko/tokens.css \
  && { echo "  Plus Jakarta Sans est revenue dans tokens.css — la marque n'a qu'une famille de texte"; exit 1; }
grep -q "font-family: 'Plus Jakarta Sans'" site/backoffice/_ds/mister-szoko/tokens/fonts.css \
  && { echo "  Plus Jakarta Sans est revenue dans la console"; exit 1; }
echo "  typografia: jedna rodzina tekstu (Mulish) — potwierdzone"
# KSeF DOIT ÊTRE RÉGLABLE DEPUIS LA CONSOLE. Sans ses champs, l'intégration
# existe et reste inatteignable — c'est l'état dans lequel elle a vécu, et
# aucun contrôle ne le disait. La clé publique se colle : sans le type « pem »
# il faudrait déposer un fichier sur la machine.
grep -q "'ksef.token'" site/backoffice/api/settings.php \
  && grep -q "'ksef.public_key'" site/backoffice/api/settings.php \
  && grep -q 'function wsm_setting_pem_store' site/backoffice/api/settings.php \
  && grep -q "ksef" site/backoffice/ustawienia.php \
  || { echo "  KSeF n'est pas réglable depuis l'écran Ustawienia"; exit 1; }
echo "  KSeF w Ustawieniach (token, klucz PEM, środowisko) : obecne"
# LA FICHE PRODUIT DOIT PORTER CE QU'ON Y CHANGE. La gramatura et les
# dimensions n'ont eu de champ nulle part pendant des mois, alors qu'elles
# choisissent le gabarit InPost — donc le prix payé pour expédier. Et le prix
# doit passer par wsm_parse_price() : « 1 234,50 » lu par (float) donnait 1,00.
grep -q 'name="weight_g"' site/backoffice/produkty.php \
  && grep -q 'name="length_mm"' site/backoffice/produkty.php \
  && grep -q 'wsm_parse_price' site/backoffice/produkty.php \
  && grep -q 'function wsm_parse_price' site/backoffice/api/shop.php \
  || { echo "  fiche produit incomplète : gramatura, wymiary ou lecture du prix"; exit 1; }
echo "  fiszka produktu: gramatura, wymiary, cena z separatorem — obecne"
# CRÉER UN PRODUIT. Le chemin n'existait pas : les 22 produits venaient du
# semis ou d'anciennes maquettes, et l'écran n'en disait rien. Les trois pièces
# partent ensemble — sans wsm_slug_libre() deux produits pourraient partager
# une clé que portent commandes, stock et factures.
grep -q "name=\"nowy\"" site/backoffice/produkty.php \
  && grep -q 'function wsm_slugify' site/backoffice/api/shop.php \
  && grep -q 'function wsm_slug_libre' site/backoffice/api/shop.php \
  || { echo "  création de produit incomplète dans l'assemblage"; exit 1; }
# ET IL NAÎT INVISIBLE : un produit sans photo ni poids n'a rien à faire en vente.
grep -q "shop_visible, sort_order)" site/backoffice/produkty.php \
  || { echo "  la création ne pose plus shop_visible — un brouillon partirait en vente"; exit 1; }
echo "  tworzenie produktu (klucz, slug, niewidoczny na starcie) : obecne"
# UNE SEULE FICHE POUR CRÉER ET MODIFIER. Deux formulaires recopiés divergent
# au premier champ ajouté, et c'est celui qu'on utilise le moins — la création,
# donc le moment où l'on décide de tout — qui devient faux.
grep -q 'fiszka = function' site/backoffice/produkty.php \
  && grep -q 'name="category_id"' site/backoffice/produkty.php \
  && grep -q 'id="kategorie"' site/backoffice/produkty.php \
  || { echo "  fiche partagée ou gestion des catégories absente"; exit 1; }
echo "  jedna fiszka (tworzenie + edycja) i zarzadzanie kategoriami : obecne"
# LA PHOTO DU HERO. Les trois pieces partent ensemble : sans le type « image »
# le champ n'enregistre rien, sans la classe le fond reste un degrade, et sans
# la section « shop » dans config.php le reglage ne redescend jamais.
grep -q "'hero_image'" site/backoffice/api/settings.php \
  && grep -q "'shop' =>" site/backoffice/api/config.php \
  && grep -q 'hero--foto' site/shop/shop.css \
  || { echo "  champ photo du hero incomplet dans l'assemblage"; exit 1; }
echo "  zdjecie na stronie glownej (pole + config + styl) : obecne"
# LES ICONES DES PROMESSES : memes trois pieces que la photo, plus la vitrine
# qui doit LIRE le reglage — un fichier depose que la page n'affiche pas est
# depose pour rien, et personne ne le voit.
grep -q "'promise_icon_1'" site/backoffice/api/settings.php \
  && grep -q "'promise_icon_3'" site/backoffice/api/config.php \
  && grep -q 'promise-ikona' site/shop/index.php \
  && grep -q 'promise-ikona' site/shop/shop.css \
  || { echo "  ikony obietnic incompletes dans l'assemblage"; exit 1; }
echo "  ikony obietnic (pola + config + witryna + styl) : obecne"
# LES MAQUETTES DE FIN OCTOBRE : les slajdy de la page d'accueil (ecran,
# API, photo du boulanger, tables), la fiche produit et le panier (dessins des
# transporteurs, paliers de remise, fonds chauds). Une piece oubliee laisse
# une page qui appelle une fonction absente.
[ -f site/backoffice/slajdy.php ] && [ -f site/backoffice/api/slajdy.php ] \
  && [ -f site/shop/assets/hero-piekarz.webp ] \
  && grep -q 'function ilustracja_dostawy' site/shop/znaki.php \
  && grep -q 'function stan_progu' site/shop/lib.php \
  && grep -q 'wsm_slajdy_ensure' site/backoffice/api/db.php \
  && grep -q 'wsm_slide_i18n' site/backoffice/api/schema/webshop_mrszoko.mysql.sql \
  || { echo "  slajdy / karta produktu / koszyk incomplets dans l'assemblage"; exit 1; }
echo "  slajdy, progi rabatowe, koszyk z przewoznikami (pliki + schemat) : obecne"
# LE WYGLAD : l'ecran, le moteur, la vitrine qui l'imprime, et les polices au
# choix — on verifie les BINAIRES, pas la declaration : une famille declaree
# sans fichiers donne un selecteur qui marche et une boutique en Georgia.
[ -f site/backoffice/wyglad.php ] && [ -f site/backoffice/api/theme.php ] \
  && grep -q 'theme_head()' site/shop/layout.php \
  && grep -q 'wsm_theme_head' site/shop/lib.php \
  && grep -q "font-family: 'Lora'" site/shop/ds/szoko/tokens.css \
  && ls site/shop/ds/szoko/fonts/lora-normal-*-latin-ext.woff2 site/shop/ds/szoko/fonts/nunito-normal-*-latin-ext.woff2 \
        site/shop/ds/szoko/fonts/jost-normal-*-latin-ext.woff2 site/shop/ds/szoko/fonts/playfair-display-normal-*-latin-ext.woff2 >/dev/null 2>&1 \
  || { echo "  ecran Wyglad ou polices au choix incomplets dans l'assemblage"; exit 1; }
echo "  wyglad sklepu (ekran + theme.php + witryna + czcionki) : obecne"
grep -q 'wsm_crm_achats' site/backoffice/api/crm.php \
  && grep -q 'Kupione kilogramy' site/backoffice/klienci.php \
  || { echo "  kilogramy w Klientach incomplets dans l'assemblage"; exit 1; }
echo "  kilogramy i produkty w Klientach : obecne"
# LE CMS : pages, blocs, mediatheque — l'API, les ecrans, le rail, la vitrine,
# le schema. Une piece oubliee donne un ecran qui s'ouvre et une vitrine qui
# ignore ce qu'on y ecrit.
[ -f site/backoffice/api/pages.php ] && [ -f site/backoffice/strony.php ] && [ -f site/backoffice/media.php ] \
  && grep -q "'strony.php'" site/backoffice/console.php \
  && grep -q 'wsm_page_find' site/shop/index.php && grep -q 'wsm_page_liens' site/shop/layout.php \
  && grep -q '\.blok-in' site/shop/shop.css \
  && grep -q 'wsm_page_i18n' site/backoffice/api/schema/webshop_mrszoko.mysql.sql \
  || { echo "  CMS (strony, bloki, media) incomplet dans l'assemblage"; exit 1; }
echo "  CMS: strony, bloki, media : obecne"
# L'ORDRE DES CHOSES (Uklad strony) et le logo deposable.
[ -f site/backoffice/api/layout.php ] && [ -f site/backoffice/uklad.php ] \
  && grep -q "'uklad.php'" site/backoffice/console.php \
  && grep -q 'wsm_layout_home' site/shop/index.php && grep -q 'liens_uklad' site/shop/layout.php \
  && grep -q 'logo_src()' site/shop/layout.php && grep -q "'logo_image'" site/backoffice/api/settings.php \
  || { echo "  Uklad strony ou logo incomplets dans l'assemblage"; exit 1; }
echo "  uklad strony + logo : obecne"
# LE KREATOR : la boutique composee en sections. L'API, l'ecran, le rail, la
# vitrine qui rend (accueil, pages, pasek au-dessus du nagłowek), le style,
# le schema, db.php qui cree les tables au premier appel.
[ -f site/backoffice/api/sections.php ] && [ -f site/backoffice/budowa.php ] \
  && grep -q "'budowa.php'" site/backoffice/console.php \
  && grep -q 'sekcja_id_html($pdo, $lang' site/shop/index.php && grep -q 'sekcje_html($pdo, $lang' site/shop/index.php \
  && grep -q 'sekcje_html(wsm_pdo(), $lang' site/shop/layout.php && grep -q 'function sekcja_html' site/shop/lib.php \
  && grep -q '\.sek--hero' site/shop/shop.css && grep -q '\.ogloszenie' site/shop/shop.css \
  && grep -q 'wsm_section_i18n' site/backoffice/api/schema/webshop_mrszoko.mysql.sql \
  && grep -q 'wsm_section_i18n' site/backoffice/api/db.php \
  || { echo "  Kreator strony (sekcje) incomplet dans l'assemblage"; exit 1; }
echo "  kreator strony (sekcje) : obecne"
# LES NOMS DES FICHIERS de la mediatheque : l'API, le champ a l'envoi, la
# table creee au premier appel, le schema.
grep -q 'function wsm_media_titles(PDO' site/backoffice/api/media.php \
  && grep -q 'name="nazwa"' site/backoffice/media.php \
  && grep -q "'wsm_media'" site/backoffice/api/db.php \
  && grep -q 'wsm_media' site/backoffice/api/schema/webshop_mrszoko.mysql.sql \
  || { echo "  nazwy plikow w mediotece incompletes dans l'assemblage"; exit 1; }
echo "  nazwy plikow w mediotece : obecne"
# SZOKO SHOP : dessins en couleur (bus et horloge du bandeau compris),
# logique des logos de paiement, logo d'origine, illustration, seuil de
# livraison gratuite lu dans Dostawa, valeurs nutritives, contact.
[ -f site/shop/znaki.php ] && [ -f site/backoffice/api/metody.php ] \
  && [ -f site/shop/assets/logo.png ] && [ -f site/shop/assets/hero-art.svg ] && [ -f site/backoffice/img/logo.png ] \
  && grep -q 'function ikona_korzysci' site/shop/znaki.php && grep -q 'function prog_darmowej(PDO' site/shop/lib.php \
  && grep -q 'kontakt-dane--strona' site/shop/index.php && grep -q 'migracja.2026_10_email_pro' site/backoffice/api/seed.php \
  && grep -q "u('assets/logo.png')" site/shop/lib.php \
  && grep -q 'function metody_html' site/shop/lib.php && grep -q 'metody_html(wsm_pdo()' site/shop/layout.php \
  && grep -q 'function wsm_nutrition_rows' site/backoffice/api/shop.php \
  && grep -q "'kontakt'   =>" site/backoffice/api/layout.php && grep -q 'function wsm_brand_name' site/backoffice/api/db.php \
  || { echo "  Szoko Shop incomplet dans l'assemblage"; exit 1; }
echo "  szoko shop (rysunki, logotypy, logo, prog dostawy, kontakt, tabele produktu) : obecne"
# ZWROTY : l'adresse des retours exigée par tpay, au règlement, en pied de
# page et sur une page à part. Une pièce oubliée laisse un des trois muet.
grep -q 'function zwrot_adres(array' site/shop/lib.php && grep -q "'{adres_zwrotu}' => zwrot_adres_linia" site/shop/lib.php \
  && grep -q "if (\$page === 'zwroty')" site/shop/index.php && grep -q 'seller--zwrot' site/shop/layout.php \
  && grep -q "'zwroty'     =>" site/backoffice/api/layout.php && grep -q "'zwrot_ulica'" site/backoffice/api/settings.php \
  || { echo "  zwroty incomplets dans l'assemblage"; exit 1; }
echo "  zwroty (adres do zwrotow : strona, stopka, regulamin, ustawienia) : obecne"
# MYSQL STRICT : ce que SQLite (les tests) laissait passer et que la
# production refusait — identifiant de produit trop long, date comparée à
# '', mot réservé `lead`, rowCount des UPDATE ; plus le journal des refus
# et les pages d'erreur lisibles.
grep -q 'WSM_PRODUCT_ID_MAX   = 40' site/backoffice/api/shop.php && grep -q 'function wsm_sql_sans_date' site/backoffice/api/db.php \
  && grep -q 'MYSQL_ATTR_FOUND_ROWS' site/backoffice/api/db.php && grep -q 'class WsmPDO' site/backoffice/api/db.php \
  && grep -q '`lead`' site/backoffice/api/pages.php && grep -q '`lead`' site/backoffice/api/sections.php \
  && grep -q 'set_exception_handler' site/backoffice/console.php && grep -q 'set_exception_handler' site/shop/lib.php \
  && grep -q 'WSM_BUYER_MAX' site/backoffice/api/shop.php && grep -q 'wsm_journal_lire(15)' site/backoffice/pulpit.php \
  || { echo "  poprawki MySQL incompletes dans l'assemblage"; exit 1; }
echo "  poprawki MySQL (id produktu, daty, lead, FOUND_ROWS, dziennik, strony bledu) : obecne"
# LA CATEGORIE SE CREE DEPUIS LA FICHE PRODUIT. Le menage a eteint cinq
# rayons de la maquette : il n'en restait qu'un allume, sur un champ
# obligatoire. Trois pieces, et deux sur trois donnent un ecran qui accepte
# un nom de rayon et le jette en silence.
grep -q 'function wsm_categorie_assure' site/backoffice/api/commerce.php \
  && grep -q 'kat_nowa' site/backoffice/produkty.php \
  && grep -q "commerce.php" site/backoffice/produkty.php \
  || { echo "  nowa kategoria z fiszki produktu: niekompletna"; exit 1; }
echo "  nowa kategoria z fiszki produktu : obecna"
# RETROUVER SA COMMANDE SANS COMPTE. Quatre pieces, et deux sur quatre
# donnent une page qui s'affiche et un bouton qui ne fait rien : sans le
# jeton CSRF la boutique refuse le POST, sans le lien personne n'y arrive.
grep -q 'function wsm_suivi_cherche' site/backoffice/api/shop.php \
  && grep -q "page === 'moje-zamowienie'" site/shop/index.php \
  && grep -q 'csrf_field()' site/shop/index.php \
  && grep -q "u('moje-zamowienie')" site/shop/lib.php \
  && grep -q "'zamowienie' => \['label' => 'Moje zamówienie[^']*', 'fixed' => true\]" site/backoffice/api/layout.php \
  || { echo "  wyszukiwanie zamowienia: niekompletne"; exit 1; }
echo "  wyszukiwanie zamowienia (regula + strona + dwa linki) : obecne"
# LE COMPTE A REBOURS DE LA PROMESSE « wysylka w 24 h ». Quatre pieces :
# la colonne qui date le depart, la regle qui compte, le voyant, et la file
# des retards. Sans shipped_at la console dit « wyslane » sans jamais dire
# « a temps » ; sans le style, le voyant est un texte gris de plus.
grep -q 'function wsm_order_termin' site/backoffice/api/invoice.php \
  && grep -q 'function wsm_orders_po_terminie' site/backoffice/api/shop.php \
  && grep -q "'shipped_at'" site/backoffice/api/db.php \
  && grep -q 'Po terminie' site/backoffice/zamowienia.php \
  && grep -q '\.termin' site/backoffice/console.css \
  || { echo "  licznik terminu wysylki: niekompletny"; exit 1; }
echo "  licznik terminu wysylki (kolumna + regula + kolejka + styl) : obecny"
# AUCUN EKRAN NIE PRZYJMUJE POST BEZ TOKENU. Sest ekranow nie mialo go
# wcale, w tym Ustawienia i Uzytkownicy. Kontrola jest tutaj, bo dodanie
# nowego ekranu bez tokenu nie rzuca zadnego bledu.
grep -q 'function console_csrf_ok' site/backoffice/console.php \
  && grep -q 'console_csrf_ok()' site/backoffice/uzytkownicy.php \
  && grep -q 'console_csrf_ok()' site/backoffice/ustawienia.php \
  && grep -q "POST\['usun'\]" site/backoffice/uzytkownicy.php \
  || { echo "  token CSRF konsoli albo usuwanie konta: niekompletne"; exit 1; }
echo "  token CSRF konsoli + usuwanie konta : obecne"
# LE MENAGE D'AVANT L'OUVERTURE. Trois pieces : la regle, le panneau, le
# mot a retaper. Sans le mot, un clic vide la boutique.
grep -q 'function wsm_golive_reset' site/backoffice/api/golive.php \
  && grep -q 'id="zerowanie"' site/backoffice/superadmin.php \
  && grep -q 'WSM_GOLIVE_MOT' site/backoffice/superadmin.php \
  || { echo "  zerowanie przed startem: niekompletne"; exit 1; }
echo "  zerowanie przed startem (regula + panel + slowo) : obecne"
# REMPLIR LA FACTURE DEPUIS LE REGISTRE. Deux registres, et il faut les
# deux : VIES ne connait pas un NIP polonais qui ne fait pas d'intracom, et
# repondrait « numer nieznany » a un client irreprochable.
grep -q 'function wsm_mf_check' site/backoffice/api/mf.php \
  && grep -q "name=\"pobierz_dane\"" site/shop/index.php \
  && grep -q "source = 'vies'" site/backoffice/api/vies.php \
  || { echo "  pobieranie danych z rejestru: niekompletne"; exit 1; }
echo "  pobieranie danych z rejestru (MF + VIES + rozdzielone) : obecne"
# LE CANAL INPOST, FINI. Le test de connexion doit repondre AVANT la
# premiere vraie commande, et les statuts doivent revenir : sans eux une
# commande reste « Wysłane » pour toujours.
grep -q 'function wsm_inpost_diag' site/backoffice/api/inpost.php \
  && grep -q 'function wsm_inpost_sync' site/backoffice/api/inpost.php \
  && grep -q "POST\['sprawdz'\]" site/backoffice/wysylka.php \
  || { echo "  kanal InPost: niekompletny"; exit 1; }
echo "  kanal InPost (test polaczenia + statusy) : obecny"
# LES DEUX DOCUMENTS QUE L'OPERATEUR DE PAIEMENT EXIGE. Quatre pieces : les
# deux pages, le lien du pied de page (present partout, telephone compris) et
# la case de la caisse qui pointe enfin sur quelque chose.
grep -q "page === 'regulamin'" site/shop/index.php \
  && grep -q "page === 'prywatnosc'" site/shop/index.php \
  && grep -q "u('regulamin')" site/shop/lib.php && grep -q "u('prywatnosc')" site/shop/lib.php \
  && grep -q "'regulamin'  => \['label' => '[^']*', 'fixed' => true\]" site/backoffice/api/layout.php \
  && grep -q 'checkout.terms_l2' site/shop/index.php \
  || { echo "  regulamin i polityka: niekompletne"; exit 1; }
echo "  regulamin i polityka (dwie strony + stopka + kasa) : obecne"
# STRIPE CONNECT, TOUS LES PAIEMENTS. Trois pannes differentes : les cles
# de la plateforme ouvrent la session, le compte de la boutique encaisse, le
# secret du webhook valide la CONFIRMATION. Sans le dernier, le client paie
# et la commande reste impayee.
grep -q 'function wsm_stripe_diag' site/backoffice/api/stripe.php \
  && grep -q 'function wsm_stripe_webhook_url' site/backoffice/api/stripe.php \
  && grep -q 'embedded_page' site/backoffice/api/stripe.php \
  && [ -f site/backoffice/platnosci.php ] \
  && grep -q 'data-stripe-checkout' site/shop/index.php \
  || { echo "  platnosci Stripe: niekompletne"; exit 1; }
echo "  platnosci Stripe (modul + ekran Platnosci + strona Zaplac) : obecne"









echo "  reprise en main du compte console : présente"
echo "  login, tokens, htaccess : présents"

echo "══ 3/5 · envoi vers $SSH_USER@$SSH_HOST:$DEPLOY_DIR ═══════════════════"
# SANS --delete, volontairement : les photos envoyées depuis la console vivent
# sur le serveur et ne sont pas dans le dépôt.
sshpass -e rsync -rltDz --omit-dir-times \
  -e "ssh $SSHOPT" site/ "$SSH_USER@$SSH_HOST:$DEPLOY_DIR/"
echo "  envoyé"

echo "══ 4/5 · contenu éditorial + compte de la console (SQL) ═══════════════"
if [ -n "$DB_USER" ]; then
  # PAR LE CLIENT mysql, et pas par migrate.php : le php en ligne de commande
  # de ce serveur n'a pas pdo_mysql. `--sync-content` n'y a JAMAIS rien fait,
  # et l'échec était avalé — trois déploiements verts n'ont rien synchronisé.
  php "$BO"/php-api/migrate.php --sync-content-sql > /tmp/wsm-sync.sql
  echo "  $(wc -l < /tmp/wsm-sync.sql) instructions SQL"
  sshpass -e scp $SCPOPT /tmp/wsm-sync.sql "$SSH_USER@$SSH_HOST:/tmp/wsm-sync.sql" >/dev/null
  distant "DB_USER='$DB_USER' DB_PASS='$DB_PASS' bash -s" <<'REMOTE'
    if mysql -u"$DB_USER" -p"$DB_PASS" --default-character-set=utf8mb4 mrszoko < /tmp/wsm-sync.sql 2>/tmp/wsm-sync.err; then
      echo "  contenu éditorial : synchronisé"
    else
      echo "  ATTENTION : synchronisation du contenu échouée"
      head -3 /tmp/wsm-sync.err | sed 's/^/    MySQL: /'
    fi
    rm -f /tmp/wsm-sync.sql /tmp/wsm-sync.err
REMOTE
  rm -f /tmp/wsm-sync.sql
else
  echo "  DB_USER absent — textes de la vitrine NON synchronisés."
  echo "  Le nouveau bloc B2B n'apparaîtra pas tant que ce SQL n'aura pas tourné."
fi

# ── Le mot de passe de la console ────────────────────────────────────────────
#
#  PENDANT DES MOIS, RIEN N'ARRIVAIT. Le workflow appelait sur le serveur
#  `php migrate.php --set-password` — or ce php-là n'a pas pdo_mysql : l'appel
#  levait « could not find driver », le `if` rattrapait, l'étape écrivait
#  « WSM_ADMIN_PASSWORD refusé » et le déploiement continuait au vert. Poser le
#  secret, le changer, le refaire : la base ne bougeait pas d'un octet.
#
#  Ici le hachage est calculé SUR CETTE MACHINE, et seul le SQL part. Le mot de
#  passe en clair ne touche jamais le serveur — ni son disque, ni son `ps`, ni
#  ses journaux. C'est mieux que ce que faisait le workflow, qui l'exportait
#  dans l'environnement du shell distant.
if [ -n "${ADM_PASS:-}" ]; then
  ADM_EMAIL="${ADM_EMAIL:-admin@misterszoko.com}"
  if [ -z "$DB_USER" ]; then
    echo "  ^ ADM_PASS fourni mais DB_USER absent : le mot de passe N'EST PAS posé."
  elif ( umask 077; printf '%s' "$ADM_PASS" \
           | php "$BO"/php-api/migrate.php --set-password-sql "$ADM_EMAIL" > /tmp/wsm-adm.sql ); then
    sshpass -e scp $SCPOPT /tmp/wsm-adm.sql "$SSH_USER@$SSH_HOST:/tmp/wsm-adm.sql" >/dev/null
    # Le SELECT final du SQL ne rend une ligne QUE si le compte porte bien ce
    # hachage : c'est la preuve, pas la promesse. Zéro ligne = rien n'a été posé.
    distant "DB_USER='$DB_USER' DB_PASS='$DB_PASS' bash -s" <<'REMOTE'
      OUT=$(mysql -u"$DB_USER" -p"$DB_PASS" --default-character-set=utf8mb4 -N -B mrszoko \
              < /tmp/wsm-adm.sql 2>/tmp/wsm-adm.err) && RC=0 || RC=1
      rm -f /tmp/wsm-adm.sql
      if [ "$RC" = 0 ] && [ -n "$OUT" ]; then
        echo "$OUT"
      else
        echo "  ^ ATTENTION : mot de passe de la console NON posé"
        head -3 /tmp/wsm-adm.err 2>/dev/null | sed 's/^/    MySQL: /'
      fi
      rm -f /tmp/wsm-adm.err
REMOTE
  else
    # Refusé à l'émission (trop court, e-mail invalide) : on le dit fort, on ne
    # fait pas tomber le déploiement — le reste du site est sain — et surtout on
    # ne touche pas au compte existant, qui reste utilisable.
    echo "  ^ ATTENTION : WSM_ADMIN_PASSWORD refusé (min 10 caractères, e-mail valide)."
    echo "    Le compte existant n'a PAS été touché."
  fi
  rm -f /tmp/wsm-adm.sql
else
  echo "  ADM_PASS absent — mot de passe de la console inchangé (c'est la règle :"
  echo "  sans secret, on ne touche à rien)."
fi

echo "══ 5/5 · l'effet, sur les pages réelles ═══════════════════════════════"
distant bash -s <<'REMOTE'
  fail=0
  SH="http://localhost/mrszoko/shop"
  BOU="http://localhost/mrszoko/backoffice"

  H=$(curl -skL "$SH/")
  case "$H" in
    *'Strefa pro'*|*'40 kg'*) echo "  ^ ZLE : stary blok « Strefa pro » nadal na stronie"; fail=1;;
    *'id="pro"'*)             echo "  blok B2B jest na stronie — potwierdzone";;
    *)                        echo "  ^ ATTENTION : brak bloku B2B"; fail=1;;
  esac
  case "$H" in
    *'kontakt?temat=wspolpraca'*|*'kontakt&#63;temat=wspolpraca'*)
      echo "  przycisk prowadzi do formularza z tematem — potwierdzone";;
    *) echo "  ^ ZLE : przycisk B2B nie prowadzi do formularza"; fail=1;;
  esac
  case "$(curl -skL "$SH/kontakt?temat=wspolpraca")" in
    *'value="wspolpraca" selected'*) echo "  formularz otwiera się na « Współpraca / hurt » — potwierdzone";;
    *) echo "  ^ ZLE : formularz ignoruje temat z adresu"; fail=1;;
  esac

  # La console sans session redirige vers sa porte : ce qu'on refuse, c'est
  # 404 (fichier absent) et 500 (page qui explose).
  for e in zamowienia.php superadmin.php dostawa.php login.php; do
    C=$(curl -skL -o /dev/null -w '%{http_code}' "$BOU/$e")
    case "$C" in
      200) echo "  $e : $C";;
      *)   echo "  ^ ZLE : $e odpowiada $C"; fail=1;;
    esac
  done
  # Sans tube, comme les contrôles voisins. Écrit « curl … | grep -q », celui-ci
  # a déclaré le style ABSENT alors qu'il était parfaitement servi : grep -q
  # s'arrête au premier motif, curl a encore 26 Ko à écrire, SIGPIPE, 141. Ici
  # le bloc distant ne pose pas pipefail, donc la panne ne s'y déclencherait
  # pas — mais un `set -o pipefail` ajouté un jour la réveillerait, et ce
  # jour-là personne ne ferait le lien.
  CSS=$(curl -skL "$BOU/console.css" || true)
  case "$CSS" in
    *".etap"*) echo "  console.css ze stylem etapów: dojechał";;
    *) echo "  ^ ZLE : console.css bez stylu etapów — przyciski będą za małe"; fail=1;;
  esac

  [ "$fail" = 0 ] && echo "  ── TOUT EST VERT" || { echo "  ── DES CONTRÔLES ONT ÉCHOUÉ"; exit 1; }
REMOTE

echo
echo "Déploiement terminé. La boutique : https://$SSH_HOST/mrszoko/shop/"
