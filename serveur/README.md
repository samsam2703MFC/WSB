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
curl -fsSL https://raw.githubusercontent.com/samsam2703MFC/WSB/main/serveur/installer.sh -o installer.sh
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

## 7. Serveur qui a déjà Traefik (Docker) : le mode conteneurs

Si les ports 80/443 sont déjà tenus par Traefik, le déploiement GitHub ne
touche pas à Apache de la machine (désactivé au démarrage) et lance
`wsm docker-init` une fois :

- `wsb-web` (PHP 8.3 + Apache) sert toutes les boutiques et le siège ;
  `wsb-db` (MariaDB) garde leurs bases, sur un réseau privé ;
- la configuration de Traefik (réseau, entrées HTTP/HTTPS, résolveur de
  certificats) est **lue sur le serveur** et n'est jamais affichée ;
  `wsb-web` porte une route Traefik par domaine, certificat compris ;
- rien de Traefik ni des autres conteneurs n'est modifié ;
- secrets dans `/etc/wsm/` (root seul), bases dans `/var/lib/wsb-mariadb`.

Sans nom de domaine, les sites ont une adresse provisoire :
`https://sandbox.<ip-avec-tirets>.sslip.io/` et
`https://admin.<ip-avec-tirets>.sslip.io/siege/`.

## 8. Le siège BuddyCorp (superadmin)

GitHub → Settings → Secrets and variables → Actions :

| Où | Nom | Valeur |
| --- | --- | --- |
| Secrets | `SIEGE_TELEFONY` | les numéros autorisés : `+48600100200, +32470111222` |
| Secrets | `SMSAPI_TOKEN` | le jeton OAuth SMSAPI qui envoie le code de connexion |
| Variables | `SMSAPI_NADAWCA` | le nom d'expéditeur validé chez SMSAPI (facultatif) |
| Variables | `SIEGE_DOMAINE` | son domaine, ex. `admin.buddycorp.pl` (facultatif) |

Entrée : numéro + **code du jour** (JJMM puis le jour de la semaine, lundi = 1 :
vendredi 13/11 → `13115`), puis le code reçu par SMS → 12 heures.
Sans numéro autorisé, la page reste cachée (404).

Les clés d'intégration de toutes les boutiques se saisissent là (onglet
*Klucze*) : une table, une clé de chiffrement par boutique, chaque boutique ne
lit que les siennes. Dans la console d'une boutique, elles apparaissent
verrouillées (« centrala »).

## Toutes les commandes

`sudo wsm aide`.
