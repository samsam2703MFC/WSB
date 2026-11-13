# Le nouveau serveur — toutes les boutiques, un seul code

```
GitHub (ce dépôt)                      Serveur  /srv/webshops/
  generique/   le code commun    ──►     releases/<version>/site    une version, partagée
  webshops/    une boutique / dossier    boutiques/<boutique>/      SES secrets, photos, données
  serveur/     ces outils                sauvegardes/<boutique>/    ses bases, chaque nuit
```

**Aucun secret dans GitHub.** Le dépôt est public. Les mots de passe des
bases sont tirés au sort sur le serveur ; les clés Stripe, InPost, poste,
KSeF se saisissent dans la console de chaque boutique (Integracje).
GitHub ne connaît que l'adresse du serveur et une clé SSH qui ne peut que
déployer.

## 1. Une clé SSH pour GitHub (sur votre ordinateur)

```bash
ssh-keygen -t ed25519 -C github-deploy -f ~/.ssh/webshops_deploy -N ""
```

```bash
cat ~/.ssh/webshops_deploy.pub
```

La ligne affichée (`ssh-ed25519 AAAA… github-deploy`) est la moitié
**publique** : elle va sur le serveur à l'étape 2. Le fichier
`~/.ssh/webshops_deploy` (sans `.pub`) est la moitié **privée** : elle va dans
GitHub à l'étape 3, et nulle part ailleurs.

## 2. Installer le serveur (une fois)

Ubuntu 22.04 ou 24.04, connecté en root (ou avec `sudo`) :

```bash
curl -fsSL https://raw.githubusercontent.com/samsam2703MFC/webshop-mrszoko-backoffice/main/serveur/installer.sh -o installer.sh
```

```bash
sudo bash installer.sh --email VOUS@example.pl --cle "ssh-ed25519 AAAA… github-deploy"
```

Il installe Apache, PHP, MariaDB, certbot, crée `/srv/webshops`, l'outil
`wsm`, l'utilisateur `wsm-deploy` (qui ne peut lancer que `wsm`), les
sauvegardes de nuit, le pare-feu, et construit la première version du code.

## 3. Brancher GitHub (une fois)

GitHub → ce dépôt → **Settings → Secrets and variables → Actions → New
repository secret** :

| Nom | Valeur |
| --- | --- |
| `NOWY_SERWER_HOST` | l'adresse IP du serveur |
| `NOWY_SERWER_KLUCZ` | tout le contenu de `~/.ssh/webshops_deploy` (la clé privée, lignes BEGIN/END comprises) |

Port SSH différent de 22 : onglet **Variables**, `NOWY_SERWER_PORT`.

## 4. Créer les boutiques

```bash
sudo wsm creer sandbox sandbox.VOTRE-DOMAINE.pl --webshop szoko
```

```bash
sudo wsm creer mycobot mycobotbuddy.pl
```

```bash
sudo wsm creer poszla-w-las poszlawlas.pl
```

Chaque création : une base, une configuration, le site Apache, le design de
`webshops/<boutique>/`, son catalogue s'il en a un, et un compte
administrateur dont le mot de passe s'affiche **une fois** (copie dans
`/srv/webshops/boutiques/<boutique>/ADMIN.txt`, lisible par root seul).

Puis, chez le registraire de chaque domaine : un enregistrement **A** vers
l'adresse IP du serveur (et `www` si vous le voulez). Quand le DNS répond :

```bash
sudo wsm https mycobot
```

## 5. Ensuite, au quotidien

| Je veux… | Je fais |
| --- | --- |
| essayer un changement | je pousse sur `main` → il part seul sur **sandbox** |
| le mettre partout | GitHub → Actions → « Nouveau serveur (wsm) » → Run workflow → **valider** |
| annuler pour une boutique | même écran → **retour** + nom de la boutique |
| voir l'état | même écran → **liste**, ou `sudo wsm liste` sur le serveur |

Chaque déploiement sauvegarde la base d'abord, vérifie que la boutique et sa
console répondent ensuite, et revient tout seul à la version précédente si ce
n'est pas le cas — en s'arrêtant avant de toucher les boutiques suivantes.

## 6. Szoko : déménager de l'ancien serveur

Sur l'**ancien** serveur :

```bash
mysqldump --single-transaction mrszoko | gzip > szoko.sql.gz
```

Copier `szoko.sql.gz` et le dossier `/var/www/html/mrszoko/shop/media/` vers
le nouveau serveur, puis sur le **nouveau** :

```bash
sudo wsm creer szoko szoko-NOUVEAU-DOMAINE.pl --webshop szoko
```

```bash
sudo wsm importer-base szoko szoko.sql.gz
```

```bash
sudo rsync -a media/ /srv/webshops/boutiques/szoko/media/ && sudo chown -R www-data:www-data /srv/webshops/boutiques/szoko/media
```

Basculer le DNS de Szoko vers le nouveau serveur, `sudo wsm https szoko`,
puis arrêter l'ancien déploiement (Actions → « Deploy to server » → Disable
workflow).

## Toutes les commandes

`sudo wsm aide`.
