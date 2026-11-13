#!/usr/bin/env bash
# =============================================================================
#  deploy-serwer.sh — le déploiement lancé DEPUIS LE SERVEUR lui-même.
#
#  POURQUOI CELUI-CI EN PLUS DE deploy-manuel.sh. L'autre pousse depuis une
#  machine de travail vers le serveur : il lui faut rsync, sshpass et le mot de
#  passe SSH. Or quand on est déjà connecté en SSH sur la machine, tout ça est
#  du détour — et le dépôt est PUBLIC, donc le serveur peut se servir seul.
#
#  CE QU'IL NE DEMANDE PAS :
#   · pas de mot de passe SSH — on est déjà dedans ;
#   · pas d'identifiants MySQL — ils sont déjà dans config.local.php, sur cette
#     machine. Les redemander, c'est les faire retaper, donc les faire passer
#     par un historique de shell.
#
#  ATTENTION AU DISQUE. Cette machine tourne à 93 % de 24,5 Go. Le clone est
#  donc superficiel (--depth 1, un seul commit) et effacé à la fin, quoi qu'il
#  arrive. Un déploiement qui remplit le disque casse la boutique qu'il devait
#  mettre à jour.
#
#  Usage, en root sur le serveur :
#      curl -fsSL https://raw.githubusercontent.com/samsam2703MFC/webshop-mrszoko-backoffice/main/tools/deploy-serwer.sh | bash
#  ou, si le fichier est déjà là :
#      bash deploy-serwer.sh
#
#  Pour reposer AU PASSAGE le mot de passe de la console (compte bloqué, mot de
#  passe perdu) :
#      bash deploy-serwer.sh --haslo
#  Il le demande alors sans l'afficher, et le redemande pour confirmer. Il n'est
#  ni tapé sur la ligne de commande, ni gardé dans l'historique du shell : une
#  faute de frappe sur un mot de passe qu'on ne relit pas, c'est la console
#  perdue jusqu'au prochain passage.
# =============================================================================
set -euo pipefail

HASLO=0
for a in "$@"; do case "$a" in --haslo|--hasło) HASLO=1 ;; esac; done

DEPLOY_DIR="${DEPLOY_DIR:-/var/www/html/mrszoko}"
ADM_EMAIL="${ADM_EMAIL:-admin@misterszoko.com}"
DEPOT="${DEPOT:-https://github.com/samsam2703MFC/webshop-mrszoko-backoffice.git}"
BRANCHE="${BRANCHE:-main}"
TRAVAIL="$(mktemp -d /tmp/wsm-deploy.XXXXXX)"
# Le ménage est un piège, PAS une politesse : sans lui, un échec en cours de
# route laisse un clone de plusieurs dizaines de méga sur un disque à 93 %.
trap 'rm -rf "$TRAVAIL"' EXIT

# On demande AVANT de rien faire : une saisie refusée doit coûter dix secondes,
# pas un déploiement complet suivi d'un « au fait, non ».
if [ "$HASLO" = 1 ] && [ -z "${ADM_PASS:-}" ]; then
  [ -t 0 ] || { echo "  ^ --haslo demande un terminal. En script : ADM_PASS='…' bash deploy-serwer.sh"; exit 1; }
  printf 'Nowe hasło do konsoli (%s), min. 10 znaków : ' "$ADM_EMAIL" >&2
  read -rs ADM_PASS; echo >&2
  printf 'Powtórz : ' >&2
  read -rs ADM_PASS2; echo >&2
  [ "$ADM_PASS" = "$ADM_PASS2" ] || { echo "  ^ les deux saisies diffèrent — rien n'a été fait"; exit 1; }
  [ "${#ADM_PASS}" -ge 10 ] || { echo "  ^ moins de 10 caractères — rien n'a été fait"; exit 1; }
  unset ADM_PASS2
fi

echo "══ 0/6 · place disponible ═════════════════════════════════════════════"
DISPO=$(df -Pm /tmp | awk 'NR==2 {print $4}')
echo "  /tmp : ${DISPO} Mo libres"
[ "$DISPO" -lt 300 ] && { echo "  ^ moins de 300 Mo : libérez de la place avant (le clone + l'assemblage n'y tiendraient pas)"; exit 1; }
[ -d "$DEPLOY_DIR" ] || { echo "  ^ $DEPLOY_DIR n'existe pas — mauvais serveur ou DEPLOY_DIR à préciser"; exit 1; }
command -v git >/dev/null || { echo "  ^ git absent : apt install -y git"; exit 1; }
command -v php >/dev/null || { echo "  ^ php absent"; exit 1; }

echo "══ 1/6 · récupération du code ($BRANCHE) ══════════════════════════════"
git clone --depth 1 --branch "$BRANCHE" --quiet "$DEPOT" "$TRAVAIL/depot"
cd "$TRAVAIL/depot"
echo "  $(git log -1 --format='%h %s' | cut -c1-72)"

echo "══ 2/6 · assemblage ═══════════════════════════════════════════════════"
BO=generique/backoffice
rm -rf site && mkdir -p site/backoffice site/landing site/shop/media
cp -r "$BO"/_ds site/backoffice/
mkdir -p site/backoffice/img && cp "$BO"/img/logo.png site/backoffice/img/
cat > site/backoffice/index.html <<'HTML'
<!doctype html><html lang="pl"><head><meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="refresh" content="0; url=login.php">
<title>Konsola Szoko Shop</title></head>
<body><p><a href="login.php">Przejdź do logowania</a></p></body></html>
HTML
# php-api → api : le chemin que la console dérive de sa propre adresse.
# data/ et *.sqlite ne partent JAMAIS : ici la base est MySQL, et la base
# locale du dépôt écraserait… rien, mais elle n'a rien à faire en production.
mkdir -p site/backoffice/api
( cd "$BO"/php-api && tar -cf - --exclude=data --exclude='*.sqlite' . ) \
  | ( cd site/backoffice/api && tar -xf - )
# TOUT le PHP du répertoire, jamais une liste blanche : sept écrans livrés ont
# déjà été oubliés d'une liste tenue à la main et sont restés morts des
# semaines en production.
cp generique/backoffice/*.php generique/backoffice/console.css site/backoffice/

cp -r generique/landing/. site/landing/

( cd generique/shop && tar -cf - --exclude=router.php --exclude=serve.sh --exclude=./ds . ) \
  | ( cd site/shop && tar -xf - )
cp generique/shop/.htaccess site/shop/.htaccess
cp generique/shop/media/.htaccess site/shop/media/.htaccess
cp generique/.htaccess site/.htaccess

# Les design systems des boutiques (webshops/<slug>/design-system/), compilés en UN
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

echo "══ 3/6 · contrôles AVANT de toucher au site ═══════════════════════════"
# Le rail est la seule source de vérité de ce qui doit exister. Un écran ajouté
# au menu et oublié à la copie casse ICI, pas devant un client.
php -r '
  require_once "generique/backoffice/console.php";
  $manque = [];
  foreach (console_sections(["role" => "Centrala"]) as $items) {
    foreach (array_keys($items) as $f) {
      if (!is_file("site/backoffice/" . $f)) $manque[] = $f;
    }
  }
  if ($manque) { fwrite(STDERR, "  ÉCRANS DU RAIL NON ASSEMBLÉS : " . implode(", ", $manque) . "\n"); exit(1); }
  echo "  rail : chaque entrée du menu a son fichier\n";
'
test -s site/backoffice/login.php || { echo "  login.php absent : personne ne pourrait entrer"; exit 1; }
grep -q -- '--brand-700' site/shop/ds/szoko/tokens.css || { echo "  ds/szoko/tokens.css incomplet"; exit 1; }
# Les trois pièces des boutons de statut doivent partir ENSEMBLE : deux sur
# trois donnent un écran qui répond 200 avec des pastilles hors d'atteinte du
# pouce, et personne ne le voit avant de s'en servir.
grep -q 'wsm_order_etapy' site/backoffice/api/invoice.php \
  && grep -q 'name="status"' site/backoffice/zamowienia.php \
  && grep -q '\.etap' site/backoffice/console.css \
  || { echo "  boutons de statut incomplets dans l'assemblage"; exit 1; }
# Ce script émet le SQL du mot de passe depuis le migrate.php qu'il vient de
# poser : sans ce drapeau, --haslo n'aurait rien à appeler.
grep -q 'set-password-sql' site/backoffice/api/migrate.php \
  && grep -q 'function wsm_set_password_sql' site/backoffice/api/auth.php \
  || { echo "  voie « mot de passe en SQL » absente de l'assemblage"; exit 1; }
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









echo "  login, tokens, boutons de statut, reprise du compte : présents"

echo "══ 4/6 · mise en place dans $DEPLOY_DIR ═══════════════════════════════"
# On NE SUPPRIME RIEN : les photos envoyées depuis la console vivent ici et ne
# sont pas dans le dépôt. C'est la même règle que le rsync du workflow.
# config.local.php n'est pas dans le dépôt : il survit forcément.
cp -a site/. "$DEPLOY_DIR"/
# Le propriétaire du serveur web, sinon la console ne peut plus écrire ses
# photos ni ses journaux.
if id -u www-data >/dev/null 2>&1; then
  chown -R www-data:www-data "$DEPLOY_DIR" 2>/dev/null || true
fi
echo "  copié"

echo "══ 5/6 · contenu éditorial (SQL) ══════════════════════════════════════"
# PAR LE CLIENT mysql, pas par migrate.php : le php en ligne de commande de ce
# serveur n'a pas pdo_mysql. « --sync-content » n'y a JAMAIS rien fait, et
# l'échec était avalé — trois déploiements verts n'ont rien synchronisé.
#
# Les identifiants sont LUS dans config.local.php, jamais redemandés : ils
# passeraient sinon par l'historique du shell.
CFG="$DEPLOY_DIR/backoffice/api/config.local.php"
if [ -f "$CFG" ]; then
  OPT="$TRAVAIL/my.cnf"
  ( umask 077; php -r '
      $c = require $argv[1];
      $m = $c["mysql"] ?? [];
      if (($c["engine"] ?? "") !== "mysql" || ($m["user"] ?? "") === "") exit(2);
      printf("[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n",
             $m["user"], $m["pass"] ?? "", $m["host"] ?? "127.0.0.1", $m["port"] ?? "3306");
    ' "$CFG" > "$OPT" ) && LU=1 || LU=0
  BASE=$(php -r '$c = require $argv[1]; echo $c["mysql"]["name"] ?? "mrszoko";' "$CFG")
  if [ "$LU" = 1 ]; then
    php "$DEPLOY_DIR/backoffice/api/migrate.php" --sync-content-sql > "$TRAVAIL/sync.sql"
    echo "  $(wc -l < "$TRAVAIL/sync.sql") instructions, base « $BASE »"
    if mysql --defaults-extra-file="$OPT" --default-character-set=utf8mb4 "$BASE" \
         < "$TRAVAIL/sync.sql" 2> "$TRAVAIL/sync.err"; then
      echo "  contenu éditorial : synchronisé"
    else
      echo "  ^ ATTENTION : synchronisation échouée"
      head -3 "$TRAVAIL/sync.err" | sed 's/^/    MySQL: /'
    fi

    # ── Le mot de passe de la console, par la même porte ──────────────────
    #
    # PAR LE CLIENT mysql, pour la RAISON EXACTE d'au-dessus : le php de cette
    # machine n'a pas pdo_mysql. Le déploiement appelait pourtant
    # `migrate.php --set-password` — qui levait « could not find driver », dans
    # un `if` qui rattrapait. Le secret pouvait être posé, changé, refait : rien
    # n'arrivait jamais en base, et l'étape se disait verte.
    #
    # password_hash(), lui, n'a besoin d'aucune base. Le mot de passe entre par
    # stdin (jamais dans `ps`), le SQL qui en sort ne porte qu'un hachage.
    if [ -n "${ADM_PASS:-}" ]; then
      if ( umask 077; printf '%s' "$ADM_PASS" \
             | php "$DEPLOY_DIR/backoffice/api/migrate.php" --set-password-sql "$ADM_EMAIL" \
             > "$TRAVAIL/adm.sql" ) 2> "$TRAVAIL/adm.err"; then
        # Le SELECT final ne rend une ligne QUE si le compte porte bien ce
        # hachage : on mesure l'effet, pas l'exécution.
        OUT=$(mysql --defaults-extra-file="$OPT" --default-character-set=utf8mb4 -N -B "$BASE" \
                < "$TRAVAIL/adm.sql" 2> "$TRAVAIL/adm.err") && RC=0 || RC=1
        if [ "$RC" = 0 ] && [ -n "$OUT" ]; then
          echo "$OUT"
        else
          echo "  ^ ATTENTION : mot de passe de la console NON posé"
          head -3 "$TRAVAIL/adm.err" | sed 's/^/    MySQL: /'
        fi
      else
        echo "  ^ ATTENTION : mot de passe refusé — $(head -1 "$TRAVAIL/adm.err")"
        echo "    Le compte existant n'a PAS été touché."
      fi
    fi

    # QUELQU'UN PEUT-IL ENTRER ? Dit à chaque passage, avec ou sans --haslo :
    # c'est la première question quand la console refuse, et personne ne la
    # posait. Zéro ici veut dire « la console est murée » — et l'amorçage du
    # compte a justement échoué en silence pendant des mois.
    ENTREE=$(mysql --defaults-extra-file="$OPT" -N -B "$BASE" -e \
      "SELECT COUNT(*) FROM wsm_users
        WHERE act = 1 AND password_hash IS NOT NULL AND password_hash <> ''
          AND (locked_until IS NULL OR locked_until < NOW());" 2>/dev/null || echo ZLE)
    case "$ENTREE" in
      0)   echo "  ^ ZLE : AUCUN compte ne peut se connecter — relancez avec --haslo" ;;
      ZLE) echo "  ^ comptes de connexion non vérifiables" ;;
      *)   echo "  connexion : $ENTREE compte(s) peuvent entrer dans la console" ;;
    esac
  else
    echo "  ^ config.local.php ne décrit pas MySQL — contenu NON synchronisé"
  fi
else
  echo "  ^ $CFG absent — contenu NON synchronisé (le nouveau bloc B2B n'apparaîtra pas)"
fi

echo "══ 6/6 · l'effet, sur les pages réelles ═══════════════════════════════"
fail=0
SH="http://localhost/mrszoko/shop"
BOU="http://localhost/mrszoko/backoffice"

H=$(curl -skL "$SH/")
case "$H" in
  *'Strefa pro'*|*'40 kg'*) echo "  ^ ZLE : stary blok « Strefa pro » nadal na stronie"; fail=1;;
  *'id="pro"'*)             echo "  blok B2B jest na stronie";;
  *)                        echo "  ^ ATTENTION : brak bloku B2B"; fail=1;;
esac
case "$H" in
  *'kontakt?temat=wspolpraca'*|*'kontakt&#63;temat=wspolpraca'*)
    echo "  przycisk prowadzi do formularza z tematem";;
  *) echo "  ^ ZLE : przycisk B2B nie prowadzi do formularza"; fail=1;;
esac
case "$(curl -skL "$SH/kontakt?temat=wspolpraca")" in
  *'value="wspolpraca" selected'*) echo "  formularz otwiera się na « Współpraca / hurt »";;
  *) echo "  ^ ZLE : formularz ignoruje temat z adresu"; fail=1;;
esac
for e in login.php zamowienia.php superadmin.php dostawa.php; do
  C=$(curl -skL -o /dev/null -w '%{http_code}' "$BOU/$e")
  [ "$C" = 200 ] && echo "  $e : 200" || { echo "  ^ ZLE : $e odpowiada $C"; fail=1; }
done
# PAS DE TUBE ICI, ET C'EST TOUT LE SUJET.
#
# Écrit « curl … | grep -q », ce contrôle a déclaré le style ABSENT alors que
# le fichier était parfaitement servi. grep -q s'arrête au PREMIER motif
# trouvé ; curl, lui, a encore 26 Ko à écrire, reçoit un SIGPIPE et meurt en
# 141 ; et `set -o pipefail` — plus haut, et à juste titre — promeut cette
# mort en échec du tube. Le motif était là, le contrôle disait non.
#
# Le piège ne se voit pas sur un petit fichier : quand tout tient dans le
# tampon du tube (64 Ko), le producteur a fini d'écrire avant que grep ne
# sorte, et le contrôle passe. Il ne mord QUE sur un flux réel — c'est-à-dire
# uniquement en production.
#
# On lit donc la réponse dans une variable, comme les contrôles voisins.
CSS=$(curl -skL "$BOU/console.css" || true)
case "$CSS" in
  *".etap"*) echo "  console.css ze stylem etapów : dojechał";;
  *) echo "  ^ ZLE : console.css bez stylu etapów — przyciski będą za małe"; fail=1;;
esac

echo
if [ "$fail" = 0 ]; then
  echo "TOUT EST VERT — https://185.180.206.46/mrszoko/shop/"
else
  echo "DES CONTRÔLES ONT ÉCHOUÉ — voir ci-dessus"; exit 1
fi
