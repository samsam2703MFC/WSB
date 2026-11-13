# Brancher la landing sur MySQL et écrire toute la structure

De zéro à une base `tfb_landing` peuplée, avec les 8 modules et leurs captures.
Chaque étape se vérifie avant de passer à la suivante.

Prérequis : **Node ≥ 20**, **MySQL ≥ 8.0** (ou MariaDB ≥ 10.6), et un accès à la
base — soit depuis le serveur applicatif, soit par un tunnel SSH (§1.3).

---

## 1. Créer la base et son utilisateur

### 1.1 Se connecter à MySQL

```bash
mysql -u root -p
```

### 1.2 Créer la base, l'utilisateur, les droits

L'encodage n'est pas optionnel : la landing stocke de l'arabe, du polonais et de
l'ukrainien. **utf8mb4** ou rien.

```sql
CREATE DATABASE tfb_landing
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- 'localhost' si l'app tourne sur la même machine que MySQL, sinon '%'
CREATE USER 'tfb'@'localhost' IDENTIFIED BY 'UN_MOT_DE_PASSE_SOLIDE';

-- Prisma a besoin de créer, modifier et supprimer des tables (migrate deploy),
-- pas seulement de lire et écrire des lignes.
GRANT ALL PRIVILEGES ON tfb_landing.* TO 'tfb'@'localhost';
FLUSH PRIVILEGES;
```

Vérification :

```sql
SHOW CREATE DATABASE tfb_landing;   -- doit afficher utf8mb4 / utf8mb4_unicode_ci
SHOW GRANTS FOR 'tfb'@'localhost';
```

### 1.3 Si la base n'est pas joignable depuis votre poste

Sur un hébergement mutualisé, MySQL n'écoute souvent que sur `localhost`. Deux voies :

**Tunnel SSH** — la base devient joignable en local sur le port 3307 :

```bash
ssh -N -L 3307:127.0.0.1:3306 utilisateur@185.180.206.46
# puis, dans un autre terminal :
# DATABASE_URL="mysql://tfb:MDP@127.0.0.1:3307/tfb_landing"
```

**Import SQL manuel** — si aucun tunnel n'est possible, sautez à [§6](#6-repli--sans-node-sur-le-serveur).

---

## 2. Configurer l'application

```bash
cd /chemin/vers/landing_tfb
cp .env.example .env
```

Éditez `.env` :

```dotenv
DATABASE_URL="mysql://tfb:UN_MOT_DE_PASSE_SOLIDE@127.0.0.1:3306/tfb_landing"
STORAGE_PATH=/var/www/tfb-storage
ADMIN_SESSION_SECRET=<voir ci-dessous>
DEFAULT_LOCALE=fr
```

Le secret de session (32 octets minimum, sinon la signature JWT est refusée) :

```bash
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

**Mot de passe avec des caractères spéciaux :** `DATABASE_URL` est une URL. Un `@`,
un `#`, un `/` ou un `?` dans le mot de passe doit être encodé, sinon Prisma lit
l'URL de travers :

```bash
node -e "console.log(encodeURIComponent('mon:mot@de/passe'))"
# → mon%3Amot%40de%2Fpasse
```

Installer les dépendances et générer le client :

```bash
npm ci
npx prisma generate
```

---

## 3. Écrire la structure

C'est l'étape qui crée les **12 tables** `tfb_`.

```bash
npx prisma migrate deploy
```

Sortie attendue : les deux migrations appliquées —
`20260730000000_init` (10 tables) et `20260730120000_module_features`
(`tfb_module_features` + la colonne `feature_id` sur les captures).

Vérification :

```bash
mysql -u tfb -p tfb_landing -e "SHOW TABLES;"
```

Les 12 tables attendues :

| Table | Rôle |
| --- | --- |
| `tfb_languages` | les 8 locales, `is_default` = fr, `is_rtl` = ar |
| `tfb_translations` | **toute** la copie du site, une ligne par champ et par langue |
| `tfb_brands` | les enseignes du bandeau de confiance |
| `tfb_sections` | l'ordre des sections de la page d'accueil |
| `tfb_modules` | un module produit par ligne |
| `tfb_module_features` | une fonction documentée par ligne |
| `tfb_module_screenshots` | les captures, rattachées ou non à une fonction |
| `tfb_plans` | les paliers tarifaires |
| `tfb_subscriptions` | les abonnements Stripe |
| `tfb_contact_messages` | la boîte de réception du formulaire |
| `tfb_admin_users` | les comptes du back-office |
| `tfb_settings` | les réglages clé/valeur |

Plus `_prisma_migrations`, la table de suivi de Prisma — ne l'éditez jamais à la main.

---

## 4. Remplir la base

### 4.1 Le seed — langues, sections, plans, compte admin

```bash
npm run seed
```

Écrit 8 langues, 8 sections, 6 enseignes, 8 modules, 3 plans, ~500 traductions
FR/EN/AR, et le compte `admin@franchisebuddy.eu` / `changeme`.

**Changez ce mot de passe.** Ou mieux, imposez-le avant de seeder :

```bash
SEED_ADMIN_EMAIL=vous@votredomaine.eu SEED_ADMIN_PASSWORD='…' npm run seed
```

Le seed est idempotent : il fait des upserts sur les clés naturelles, donc le
relancer après des modifications en back-office ne casse rien (il réécrit les
traductions des locales rédigées).

### 4.2 La sync — les 8 modules réels et leurs captures

```bash
npm run sync:modules -- --dry-run   # valide et rapporte, n'écrit rien
npm run sync:modules                # écrit
```

Elle lit le `.tfb/module.json` de chaque dépôt listé dans
`content/modules.repos.json` et écrit modules, fonctions, captures et traductions.

**Tant que les branches `claude/tfb-module-manifest` ne sont pas fusionnées dans le
`main` de chaque dépôt module, la sync répond « 8 sans fiche » et n'écrit rien.**
C'est voulu et ce n'est pas une erreur. Deux façons d'avancer :

```bash
# a) fusionner les 8 branches, puis :
npm run sync:modules

# b) ou tester tout de suite depuis des clones locaux :
git clone https://github.com/samsam2703MFC/signage /tmp/repos/signage
# … les 8 dépôts, dans /tmp/repos, chacun sur la branche claude/tfb-module-manifest
npm run sync:modules -- --from-disk /tmp/repos
```

Pour un dépôt privé, exportez `GITHUB_TOKEN` (droit `contents: read`) avant de lancer.
La sync interroge alors l'API Contents, seul chemin qui honore un jeton ; sans jeton
valide elle retombe en lecture publique et le dit. Elle n'envoie **jamais** le jeton à
`raw.githubusercontent`, qui répondrait 404 — un manifeste sain deviendrait
indiscernable d'un manifeste absent.

**Branche lue.** Les 8 dépôts sont normalisés sur `main`. Si l'un d'eux revient à une
autre branche, pinnez-la dans `content/modules.repos.json` :

```json
{ "repo": "samsam2703MFC/signage", "ref": "une-autre-branche" }
```

Vérification :

```bash
mysql -u tfb -p tfb_landing -e "
SELECT m.\`key\`, m.module_group,
       (SELECT COUNT(*) FROM tfb_module_features f WHERE f.module_id = m.id) AS fonctions,
       (SELECT COUNT(*) FROM tfb_module_screenshots s WHERE s.module_id = m.id) AS captures
FROM tfb_modules m ORDER BY m.sort_order;"
```

### 4.3 Retirer les modules de démonstration

Le seed pose 7 modules inventés hérités de la maquette (`shop`, `invoicing`,
`offers`, `scan`, `marketing`, `ceobot`, `pwa`). Une fois les vraies fiches
chargées, sortez-les de la landing :

```bash
npm run sync:modules -- --retire-unlisted
```

Ils sont **désactivés**, pas supprimés : `is_active = 0`. Réversible d'un clic dans
le back-office. La commande refuse d'agir si un seul dépôt n'a pas livré sa fiche,
pour qu'un dépôt muet ne puisse jamais vider la page.

---

## 5. Le stockage des fichiers

Les captures ne sont pas dans la base : la base garde le chemin, le disque garde
le fichier.

```bash
mkdir -p /var/www/tfb-storage/screenshots /var/www/tfb-storage/brands
chown -R <utilisateur-node>: /var/www/tfb-storage
```

`STORAGE_PATH` doit pointer là, et **survivre aux déploiements** — ne le mettez pas
dans le dossier de l'application si celui-ci est réécrit à chaque mise en ligne.

---

## 6. Repli — sans Node sur le serveur

Si le serveur n'a que PHP et phpMyAdmin, la structure s'importe telle quelle :

```bash
mysql -u tfb -p tfb_landing < docs/sql/tfb_landing_structure.sql
```

ou par l'onglet *Importer* de phpMyAdmin. Ce fichier contient les 12 `CREATE TABLE`
et leurs clés étrangères, rien d'autre.

Attention : cette voie **ne crée pas** `_prisma_migrations`. Les migrations
suivantes devront être appliquées à la main de la même façon. Préférez
`prisma migrate deploy` chaque fois que c'est possible — au besoin par le tunnel
SSH de §1.3, depuis votre poste.

Le contenu, lui, se charge depuis une machine ayant Node **et** l'accès à la base :
`npm run seed` puis `npm run sync:modules` (voir §4).

---

## 7. Lancer et vérifier

```bash
npm run build
npm start          # écoute sur le port 3000
```

Contrôles :

```bash
curl -s localhost:3000/api/languages | head -c 200          # les 8 locales
curl -s "localhost:3000/api/landing?lang=fr" | head -c 200  # le payload complet
curl -sI localhost:3000/fr | head -1                        # 200
```

Puis, dans un navigateur :

- `/fr` — l'accueil, la grille de modules
- `/fr/modules/signage` — l'explication du module puis chaque fonction avec sa capture
- `/ar/modules/signage` — la même, en miroir
- `/admin` — le back-office

Si une capture reste sur son placeholder : le fichier n'est pas sous `STORAGE_PATH`.
Vérifiez `curl -sI localhost:3000/api/storage/screenshots/<fichier>.png`.

---

## 8. Quand ça coince

| Symptôme | Cause | Correctif |
| --- | --- | --- |
| `P1001: Can't reach database server` | MySQL n'écoute pas sur l'interface visée, ou pare-feu | `bind-address` dans `my.cnf`, ou tunnel SSH (§1.3) |
| `P1000: Authentication failed` | mot de passe, ou hôte du compte (`'tfb'@'localhost'` ≠ `'tfb'@'%'`) | recréer l'utilisateur avec le bon hôte |
| `P1010: User was denied access` | droits insuffisants pour créer des tables | `GRANT ALL PRIVILEGES ON tfb_landing.*` |
| `P3009: migrate found failed migrations` | une migration a échoué à mi-course | corriger la cause, puis `npx prisma migrate resolve --rolled-back <nom>` |
| Caractères arabes en `????` | base ou connexion en latin1 | recréer la base en `utf8mb4` et réimporter |
| `Unknown collation: 'utf8mb4_0900_ai_ci'` | dump MySQL 8 importé dans MariaDB | le SQL de `docs/sql/` utilise `utf8mb4_unicode_ci`, compatible avec les deux |
| La landing affiche les clés (`hero.title`) | `tfb_translations` vide | `npm run seed` n'a pas tourné, ou pas sur cette base |
| Les modules réels n'apparaissent pas | manifestes absents de `main` | voir §4.2 |

---

## 9. Mettre les modules en ligne automatiquement

La chaîne complète, du `git push` dans un dépôt module à l'affichage public :

```
dépôt module            landing_tfb                    base            navigateur
.tfb/module.json  ──▶  npm run sync:modules  ──▶  tfb_modules   ──▶  /fr/modules/…
   (vous éditez)        (minuteur, 10 min)        tfb_module_*      (ISR, 60 s)
```

**Aucun redéploiement n'est nécessaire.** Les pages de la landing sont rendues en
ISR — `export const revalidate = 60` dans `src/app/(site)/[locale]/page.tsx` et
`modules/[slug]/page.tsx` — donc dès que la base change, la page suivante servie
après 60 secondes est régénérée avec le nouveau contenu.

Le seul maillon à automatiser est donc la sync.

### 9.1 Le minuteur systemd (recommandé)

```bash
sudo mkdir -p /etc/tfb
sudo tee /etc/tfb/landing.env >/dev/null <<'ENV'
DATABASE_URL=mysql://tfb:MOTDEPASSE@127.0.0.1:3306/tfb_landing
STORAGE_PATH=/var/www/tfb-storage
# GITHUB_TOKEN=ghp_…   # seulement si un dépôt module est privé
ENV
sudo chmod 600 /etc/tfb/landing.env

sudo cp deploy/tfb-sync.service deploy/tfb-sync.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now tfb-sync.timer
```

Vérifier :

```bash
systemctl list-timers tfb-sync          # prochain passage
sudo systemctl start tfb-sync.service   # passage immédiat
journalctl -u tfb-sync -n 40 --no-pager # ce qu'il a écrit
```

Délai maximum entre un push dans un dépôt module et l'affichage en ligne :
**~11 minutes** (10 min de minuteur + 60 s d'ISR).

Sans systemd, la même chose en cron :

```cron
*/10 * * * * cd /var/www/landing_tfb && /usr/bin/npm run sync:modules >> /var/log/tfb-sync.log 2>&1
```

(avec `DATABASE_URL` et `STORAGE_PATH` exportés dans l'environnement de la tâche).

### 9.2 Pourquoi pas depuis GitHub Actions

`.github/workflows/sync-modules.yml` **valide** les fiches, il n'écrit pas en base.
C'est délibéré : la sync copie les captures dans `STORAGE_PATH`, et le disque d'un
runner GitHub est détruit à la fin du job. Écrire depuis là remplirait la base de
chemins vers des fichiers qui n'existent nulle part, et tous les carrousels
retomberaient sur leur placeholder — sans la moindre erreur pour vous prévenir.

La validation en CI reste utile : une fiche cassée se voit avant que le serveur ne
la lise.

### 9.3 Déclencher la sync depuis le dépôt module (SSH)

Dix minutes d'attente, c'est parfois neuf de trop. Le dépôt module peut prévenir le
serveur lui-même : dès qu'une fiche change sur `main`, son workflow ouvre une session
SSH et lance la sync. Le contenu est en ligne une minute plus tard.

La clé utilisée est à **commande forcée** : elle ne peut rien faire d'autre que
déclencher la sync. Ni shell, ni tunnel, ni copie de fichier — même entre de
mauvaises mains, elle ne donne que le droit de rafraîchir la landing.

#### a. Le compte de déploiement, sur le serveur

```bash
sudo useradd --system --create-home --shell /bin/sh deploy
```

#### b. Le script, seul geste que la clé pourra accomplir

```bash
sudo cp deploy/tfb-sync-trigger.sh /usr/local/bin/tfb-sync-trigger
sudo chown root: /usr/local/bin/tfb-sync-trigger
sudo chmod 755 /usr/local/bin/tfb-sync-trigger

sudo cp deploy/tfb-sync.sudoers /etc/sudoers.d/tfb-sync
sudo chmod 440 /etc/sudoers.d/tfb-sync
sudo visudo -cf /etc/sudoers.d/tfb-sync      # doit répondre "parsed OK"
```

La règle sudoers autorise **une** ligne de commande, écrite en entier. Pas de joker :
`systemctl *` reviendrait à donner le droit d'arrêter n'importe quel service.

#### c. La paire de clés

Générez-la sur votre poste, **sans passphrase** (personne ne sera là pour la taper) :

```bash
ssh-keygen -t ed25519 -N '' -C 'tfb-sync@github-actions' -f ~/.ssh/tfb-sync
```

La publique va sur le serveur, précédée de ses restrictions — le modèle complet est
dans `deploy/authorized_keys.example` :

```bash
sudo -u deploy mkdir -p ~deploy/.ssh && sudo -u deploy chmod 700 ~deploy/.ssh
printf 'command="/usr/local/bin/tfb-sync-trigger",restrict %s\n' "$(cat ~/.ssh/tfb-sync.pub)" \
  | sudo -u deploy tee -a ~deploy/.ssh/authorized_keys
sudo -u deploy chmod 600 ~deploy/.ssh/authorized_keys
```

Relevez au passage l'empreinte du serveur, pour que la CI sache à qui elle parle :

```bash
ssh-keyscan -H VOTRE_SERVEUR 2>/dev/null
```

#### d. Les secrets, dans chaque dépôt module

Settings → Secrets and variables → Actions :

| Nom | Type | Valeur |
| --- | --- | --- |
| `TFB_SSH_HOST` | secret | l'hôte du serveur |
| `TFB_SSH_KEY` | secret | le contenu de `~/.ssh/tfb-sync` (la clé **privée**) |
| `TFB_SSH_KNOWN_HOSTS` | secret | la sortie de `ssh-keyscan` ci-dessus |
| `TFB_SSH_USER` | variable | `deploy` (défaut si absent) |
| `TFB_SSH_PORT` | variable | `22` (défaut si absent) |

Sans `TFB_SSH_HOST`, le workflow ne fait rien et reste vert : un dépôt pas encore
configuré ne doit pas afficher un échec permanent.

#### e. Le workflow

`deploy/notify-landing.yml` est déjà posé dans les huit dépôts modules, en
`.github/workflows/notify-landing.yml`. Il se déclenche sur un push `main` touchant
`.tfb/**` ou `docs/landing/**`, et se lance aussi à la main
(Actions → notify-landing → Run workflow).

#### f. Vérifier

Depuis votre poste, avec la clé privée :

```bash
ssh -i ~/.ssh/tfb-sync deploy@VOTRE_SERVEUR
# → la session se ferme aussitôt : la commande forcée a tourné, pas de shell.

sudo journalctl -t tfb-sync-trigger -n 5 --no-pager   # qui a déclenché, et d'où
sudo journalctl -u tfb-sync -n 30 --no-pager          # ce que la sync a écrit
```

Essayez ensuite d'en faire autre chose — la clé doit refuser :

```bash
ssh -i ~/.ssh/tfb-sync deploy@VOTRE_SERVEUR 'cat /etc/passwd'   # ne lit rien
```

**Gardez le minuteur en place.** Un push pendant que GitHub est en panne, un runner
qui échoue, un serveur redémarré : le minuteur rattrape ce que le déclencheur a
raté. Les deux voies écrivent la même chose, et la sync est idempotente.

### 9.4 Vérifier que c'est passé

```bash
mysql -u tfb -p tfb_landing -e "
SELECT m.\`key\`, m.is_active, COUNT(f.id) AS fonctions, m.updated_at
FROM tfb_modules m LEFT JOIN tfb_module_features f ON f.module_id = m.id
GROUP BY m.id ORDER BY m.updated_at DESC;"

curl -s "https://VOTRE_DOMAINE/api/landing?lang=fr" | grep -o '"key":"[a-z_]*"' | head -20
```

Puis la page elle-même, une minute plus tard : `https://VOTRE_DOMAINE/fr#modules`.

---

## 10. Garder la landing à jour

Une fois la base en place, la mise à jour du contenu ne passe plus par un
déploiement. Trois voies, au choix :

1. **Le minuteur** — `tfb-sync.timer`, qui fait tout le travail sans vous (§9).
2. **Le back-office** — `/admin`, pour la copie, les sections, les tarifs, les visuels.
3. **`npm run sync:modules`** à la main, quand vous ne voulez pas attendre le minuteur.

Et en garde-fou, le workflow `.github/workflows/sync-modules.yml`, qui valide les
fiches à chaque nuit sans écrire en base — pour qu'une fiche cassée se voie avant
que le serveur ne la lise (§9.2).

---

## 11. Mettre le CODE à jour

Le §10 concerne le **contenu** : il se met à jour tout seul, sans déploiement. Le
**code**, lui, a besoin d'un build. C'est ce que fait `deploy/tfb-update.sh`.

Les deux sont volontairement séparés : une fiche module cassée ne doit pas
empêcher un correctif de partir, et un build raté ne doit pas figer le contenu.

### 11.1 Le service applicatif

**Commencez par vérifier qu'il n'existe pas déjà :**

```bash
systemctl status tfb-landing
systemctl show tfb-landing -p WorkingDirectory -p User -p ExecStart
```

S'il tourne déjà, **ne copiez pas** `deploy/tfb-landing.service` : vous écraseriez
une unité qui fonctionne. Notez simplement son `WorkingDirectory` et son `User`,
et passez au §11.2.

Sinon, le §7 lance `npm start` à la main — bon pour vérifier, insuffisant pour un
serveur où rien ne relance l'application au reboot :

```bash
sudo cp deploy/tfb-landing.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now tfb-landing
systemctl status tfb-landing
```

Adaptez `User=`, `WorkingDirectory=` et les `ReadWritePaths=` à votre installation.

**Un dépôt peut porter plusieurs applications.** Sur un serveur où la vitrine et
le back-office cohabitent, deux services tournent, avec des noms proches et des
dossiers différents — par exemple `landing-tfb.service` pour la vitrine et
`tfb-landing.service` pour le back-office. Vérifiez à quel dossier vous parlez
avant chaque commande : `systemctl show <service> -p WorkingDirectory`.

### 11.2 La mise à jour

```bash
sudo cp deploy/tfb-update.sh /usr/local/bin/tfb-update
sudo chmod 755 /usr/local/bin/tfb-update
sudo chown root: /usr/local/bin/tfb-update
sudo cp deploy/tfb-update.service /etc/systemd/system/
sudo systemctl daemon-reload
```

Puis, à chaque mise à jour :

```bash
sudo systemctl start tfb-update.service
journalctl -u tfb-update -f
```

Le script fait, dans cet ordre : `git merge --ff-only`, `npm ci`,
`prisma generate`, **build**, **migrations**, redémarrage, contrôle de santé.

Trois propriétés valent d'être connues, parce qu'elles décident de ce qui se passe
une nuit où ça se passe mal :

- **Le build précède les migrations.** Le build ne touche pas la base, donc un
  build raté laisse le schéma exactement où il était. Migrer d'abord, c'est se
  retrouver avec une base en avance sur le code qui tourne — la panne la plus
  pénible à défaire.
- **L'ancien `.next` est conservé jusqu'à ce que le nouveau build aboutisse.** Si
  le build échoue, il est remis en place, le dépôt revient au commit précédent, et
  **le service n'est pas redémarré** : le site continue de servir la version
  d'avant, et `git log` ne ment pas sur ce qui tourne.
- **Un dépôt modifié à la main arrête tout.** Si un fichier suivi a été corrigé
  directement sur le serveur, le script refuse plutôt que d'écraser ce travail.
  Un fichier non suivi, lui, ne bloque rien.

Le tout se règle par variables d'environnement si votre installation diffère :
`TFB_APP_DIR`, `TFB_BRANCH`, `TFB_APP_SERVICE`, `TFB_HEALTH_URL`, `TFB_ENV_FILE`.

### 11.3 Déclencher depuis GitHub

Même montage qu'au §9.3, avec une clé **distincte** : celle qui déploie ne doit
pas être celle qui synchronise, sinon compromettre l'une donne l'autre.

```bash
sudo cp deploy/tfb-update-trigger.sh /usr/local/bin/tfb-update-trigger
sudo chmod 755 /usr/local/bin/tfb-update-trigger
sudo chown root: /usr/local/bin/tfb-update-trigger
sudo cp deploy/tfb-update.sudoers /etc/sudoers.d/tfb-update
sudo chmod 440 /etc/sudoers.d/tfb-update
sudo visudo -cf /etc/sudoers.d/tfb-update      # doit répondre "parsed OK"
```

Dans `~deploy/.ssh/authorized_keys`, une seconde ligne sur le modèle du §9.3 :

```
command="/usr/local/bin/tfb-update-trigger",restrict ssh-ed25519 AAAA… tfb-update@github-actions
```

Puis, dans **Settings → Secrets and variables → Actions** du dépôt :
`DEPLOY_SSH_KEY` (la clé privée), `DEPLOY_HOST`, `DEPLOY_USER`,
`DEPLOY_KNOWN_HOSTS` (la sortie de `ssh-keyscan -H <hôte>`).

`DEPLOY_KNOWN_HOSTS` n'est pas facultatif : sans lui il faudrait désactiver la
vérification de l'hôte, et n'importe qui capable de se placer sur le chemin
recevrait la clé de déploiement.

`.github/workflows/deploy.yml` s'occupe du reste, à chaque push sur la branche de
production. Il ne construit rien lui-même — pour la même raison qu'au §9.2, le
build doit atterrir sur le disque que l'application sert.

### 11.4 Quand ça coince

| Symptôme | Cause | Correctif |
| --- | --- | --- |
| `des modifications non commitées traînent` | quelqu'un a édité un fichier sur le serveur | `git -C /var/www/landing_tfb status`, puis commitez ou annulez |
| `l'historique local a divergé` | un commit a été fait sur le serveur | `git -C … log --oneline -5` pour voir ce qui a été ajouté |
| `build raté — revenu à …` | le code poussé ne compile pas | le site tourne toujours ; corrigez et repoussez |
| `le service ne répond pas` | l'application démarre mal | `journalctl -u tfb-landing -n 50` — souvent `.env` incomplet |
