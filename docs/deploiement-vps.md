# Déploiement sur un VPS

## Hypothèses

Ce guide décrit un déploiement générique sur un VPS Linux déjà administré. Les valeurs d'exemple sont `myabi.example.org` pour le domaine, `vps.example.org` pour l'hôte et `203.0.113.10` pour l'adresse IPv4 ; les remplacer par celles de l'environnement cible.

Le scénario de référence suppose un serveur partagé avec d'autres services : Docker est installé et **Caddy, en conteneur Docker, constitue le frontal HTTPS unique**. D'autres services web peuvent y être raccordés ; myABI dispose de son propre service web. Relever avant tout déploiement le nom du conteneur Caddy, son réseau de proxy, le montage de sa configuration, le SMTP et la destination des sauvegardes.

**Utiliser un système hôte maintenu.** Vérifier que la version de la distribution reçoit encore des mises à jour de sécurité ; dans le cas contraire, planifier sa migration avant la mise en production, avec sauvegarde et inventaire des services existants. Docker ne remplace pas les mises à jour de sécurité du noyau hôte. [Versions Ubuntu maintenues](https://ubuntu.com/project/docs/release-team/list-of-releases/).

## Choix possibles

| Méthode | Préparation disponible | Intérêt et contrepartie |
| --- | --- | --- |
| **Docker Compose, livraison manuelle par SSH — recommandée pour le premier déploiement** | Images PHP 8.4 / Nginx, MariaDB 11.4, Compose, modèles de secrets, sauvegarde, migrations et timers | Isole la stack des services existants. Chaque étape du premier déploiement est observable. Nécessite l'intégration au frontal HTTPS existant. |
| **Même stack, images construites en CI puis déploiement contrôlé** | Dockerfile compatible avec une construction en CI ; procédure manuelle réutilisable | Évite de compiler sur le VPS et rend les versions reproductibles. Ajouter ensuite un registre privé, des tags/digests immuables et un déclenchement explicite. Le workflow SSH natif existant ne pilote pas cette stack Docker. |
| **Installation native Nginx / PHP-FPM / MariaDB** | Modèles séparés dans `deploy/vps/`, livraison SSH existante adaptée | Convient si ces versions sont déjà administrées sur le VPS. Davantage de configuration système et de risques de conflit avec les services présents. |
| **Panneau existant, par exemple Coolify ou Portainer** | Réutilisation possible des images et de Compose après adaptation | À privilégier si le VPS est déjà administré ainsi. Ne pas installer un second frontal/panneau sans inventaire. Volumes, sauvegarde cohérente et commandes de migration restent indispensables. |

L'option Docker est une évolution proposée de la contrainte initiale « sans Docker » du pilote gratuit. Le code reste PHP/Blade/MariaDB ; aucun Node ni Redis n'est nécessaire à l'exécution. Les options de conteneurisation et de livraison sont distinctes : la même stack peut être livrée manuellement puis automatisée. [Docker Compose en production](https://docs.docker.com/compose/how-tos/production/), [stockage persistant Coolify](https://coolify.io/docs/core/persistent-storage/storage-mounts/overview).

## Inventaire préalable en lecture seule

Depuis le dépôt, exécuter le script sur le VPS avec un accès SSH dont l'empreinte a été vérifiée :

```sh
ssh -o StrictHostKeyChecking=yes UTILISATEUR@vps.example.org \
  'bash -s' < scripts/vps-preflight.sh
```

Le script lit les versions, la mémoire, les disques/inodes, les ports et un inventaire Docker. Il ne lit pas les variables d'environnement des conteneurs, ne modifie aucun service et n'affiche aucun fichier de secret. Après identification du conteneur Caddy, un troisième argument (`bash scripts/vps-preflight.sh myabi.example.org /opt/myabi NOM_CADDY`) affiche uniquement ses réseaux et adresses IP. Si le compte n'a pas accès à Docker, un administrateur peut exécuter les mêmes vérifications. L'accès au socket Docker équivaut à un accès administratif à l'hôte : ne pas le donner au compte web.

Confirmer : conteneur Caddy qui possède 80/443, réseau auquel il est rattaché et chemin de son Caddyfile ; version Compose au moins 2.24.4 (`profiles`, `secrets`, `bind.create_host_path`, suppression explicite des ports du profil de base) ; espace réellement disponible dans le répertoire Docker ; charge des autres services ; accès SMTP et sauvegarde hors VPS. Le profil Caddy n'utilise pas le port hôte 18080. Ne pas exécuter `docker system prune` ni arrêter les autres projets.

## Architecture Docker préparée

`deploy/docker/compose.yml` définit le projet `myabi-translate` :

- `myabi-web` : Nginx propre à myABI. Avec `compose.caddy.yml`, **aucun port hôte n'est publié** ; Caddy le joint par l'alias unique `myabi-translate-web:8080` sur son réseau de proxy. Le profil de base seul conserve `127.0.0.1:18080` pour une autre topologie ou une vérification locale.
- `app` : PHP-FPM 8.4, code en lecture seule, compte non privilégié UID/GID `10001:10001` ; seules les zones de runtime sont modifiables.
- `db` : MariaDB 11.4 sur un réseau interne, sans port publié. Le compte applicatif n'a pas les droits de migration.
- `cli`, `migrate`, `provision`, `backup` : outils ponctuels, sous le profil Compose `operations`. Les secrets élevés sont montés uniquement dans les outils qui en ont besoin.
- `data/storage` et `data/mariadb` : données persistantes du projet, hors images et hors Git. Le groupe privé 10001 permet le partage des fichiers entre PHP-FPM et le CLI UID 10002.

Le trajet retenu est `Internet → Caddy existant (HTTPS) → web myABI (HTTP interne) → PHP-FPM myABI → MariaDB privée`. PHP utilise l'alias interne distinct `myabi-translate-php` pour éviter une collision avec le nom générique `app` d'une autre stack sur le réseau de proxy. Ni PHP ni MariaDB ne rejoignent le réseau de Caddy. Aucun second Caddy, certificat ou Nginx global n'est installé.

Le nom du service Compose `myabi-web` est lui aussi volontairement unique : Compose ajoute automatiquement ce nom aux alias DNS de chaque réseau. Un service nommé simplement `web` pourrait perturber le routage d'un site existant utilisant `web:3000`, même avec l'alias supplémentaire `myabi-translate-web`.

Les limites initiales sont 1 536 Mio pour MariaDB, 1 024 Mio pour PHP-FPM, 96 Mio pour Nginx et 512 Mio pour une opération CLI. Elles limitent les pics, sans réserver ni garantir ces ressources. Vérifier la marge réelle avec les autres services avant activation. Le pool FPM est limité à trois processus ; les traitements lourds passent par le CLI.

Le modèle propose une **enveloppe applicative initiale de 10 Gio**, à confirmer selon l'espace réellement disponible sur le serveur. Ce n'est pas un quota de système de fichiers ni une promesse de conservation illimitée. Conserver de la marge pour les images, les journaux Docker, les temporaires de sauvegarde, MariaDB physique et les autres services. Renseigner `EXTERNAL_STORAGE_USED_BYTES` à partir des mesures ; la somme SQL `data_length + index_length` ne mesure pas tous les octets physiques. Ne pas relever le quota local du poste pour contourner son refus : le VPS a sa propre configuration.

Les journaux Docker sont limités à trois fichiers de 10 Mio par service. Aucun nettoyage de données métier n'est automatique. Un réimport inchangé complet produit encore de nouvelles présences historiques : suivre la croissance hebdomadaire observée dans le PV.

## Première installation Docker

Les commandes suivantes sont destinées à un shell Linux sur le VPS, **après validation de l'inventaire et du choix de déploiement**. Elles n'ont pas été exécutées sur votre serveur. Adapter `/opt/myabi` si ce chemin existe déjà ; ne pas remplacer un autre projet.

### 1. Code et configuration privée

Placer une copie du code dans `/opt/myabi` avec un checkout parcimonieux excluant `data`, puis sélectionner un commit revu contenant cette préparation. Ne pas transférer `.env`, `.runtime`, la base locale ou les comptes de démonstration. Le contexte de construction Docker est une liste autorisée, qui exclut également les originaux `data/imports` déjà présents dans l'historique Git.

```sh
cd /opt/myabi/deploy/docker
cp compose.env.example compose.env
install -d -m 0700 secrets
install -d -m 0750 data data/mariadb
install -d -o 10001 -g 10001 -m 2770 data/storage
install -d -o 10001 -g 10001 -m 2770 data/storage/app data/storage/app/private
install -o 10001 -g 10001 -m 0660 /dev/null data/storage/app/private/operations.lock
cp app.env.example secrets/app.env
chmod 0600 secrets/app.env compose.env
```

Ces commandes de création supposent une **destination neuve**, notamment pour `operations.lock`. Ne jamais remplacer le fichier de verrou d'une installation active. UID/GID 10001 correspondent aux identités internes des images ; les contrôles sont effectués dans les conteneurs, sans créer un utilisateur Linux homonyme sur l'hôte.

Générer des secrets neufs dans les fichiers, sans les afficher :

```sh
python3 - <<'PY'
import base64, os, pathlib, secrets
root = pathlib.Path('secrets')
for name in ('db-root-password', 'db-migration-password', 'db-backup-password'):
    path = root / name
    with path.open('x') as target:
        target.write(secrets.token_hex(32) + '\n')
    path.chmod(0o600)
path = root / 'app.env'
text = path.read_text()
if '\nAPP_KEY=\n' not in text or '\nDB_PASSWORD=\n' not in text:
    raise SystemExit('Configuration already contains credentials; do not overwrite it.')
text = text.replace('\nAPP_KEY=\n', '\nAPP_KEY=base64:' + base64.b64encode(os.urandom(32)).decode() + '\n')
text = text.replace('\nDB_PASSWORD=\n', '\nDB_PASSWORD=' + secrets.token_hex(32) + '\n')
path.write_text(text)
PY
chown 10001:10001 secrets/app.env
chmod 0640 secrets/app.env
```

Compléter le SMTP, l'expéditeur autorisé, les mesures de stockage et `RELEASE_VERSION` dans `secrets/app.env`. Conserver `APP_KEY` durablement et séparément des sauvegardes de données. Les sauvegardes et sessions chiffrées dépendent de cette clé ; ne pas la régénérer lors d'une mise à jour. `provision` réconcilie explicitement les mots de passe SQL avec ces fichiers : c'est une opération administrative.

Dans `compose.env`, fixer `APP_REVISION` au SHA Git complet et `RELEASE_TAG` à une version immuable correspondant à ce code. Ajouter les clés de `compose.caddy.env.example` : `COMPOSE_FILE=compose.yml:compose.caddy.yml`, `COMPOSE_PATH_SEPARATOR=:`, `CADDY_NETWORK` avec le nom du réseau existant et `CADDY_TRUSTED_PROXY_CIDR` avec l'adresse réelle de Caddy. Les commandes et unités systemd lisent toutes `compose.env` et sélectionnent ainsi le même profil. Préférer une adresse stable précise `/32` ou `/128` ; un sous-réseau dédié au proxy est possible si tous ses membres sont de confiance. Ne jamais utiliser `0.0.0.0/0` ni un réseau privé entier par commodité. Si l'adresse Caddy change lors d'une recréation, mettre à jour cette valeur et recréer `myabi-web`. Ne pas utiliser les tags locaux `vps-preparation` pour une livraison officielle.

### 2. Construire, créer le schéma et contrôler les droits

```sh
docker compose --env-file compose.env config --quiet
docker compose --env-file compose.env build app myabi-web
docker compose --env-file compose.env up -d --wait db
docker compose --env-file compose.env run --rm provision
docker compose --env-file compose.env run --rm migrate
docker compose --env-file compose.env run --rm provision
docker compose --env-file compose.env run --rm cli php scripts/operations.php health
docker compose --env-file compose.env run --rm cli php artisan security:check-audit
docker compose --env-file compose.env run --rm cli php artisan referentiel:install
docker compose --env-file compose.env up -d app myabi-web
```

Le premier `provision` crée les trois comptes dédiés ; le second accorde les droits **table par table après création du schéma**. Sur `audit_events`, le compte web reçoit uniquement `SELECT` et `INSERT`. Ne pas remplacer ces étapes par `MARIADB_USER`/`MARIADB_PASSWORD` de l'image officielle, qui donneraient des droits trop larges. Ne pas supprimer le compte de migration : il reste le `DEFINER` des triggers.

La commande d'installation demande l'adresse, le nom et le mot de passe du premier administrateur. Elle ne crée pas le compte local `demo@referentiel.invalid`. Le MFA est désactivé par défaut (`MFA_ENABLED=false`) ; si le serveur l'active, l'administrateur doit ensuite configurer son TOTP. Les migrations ne sont jamais exécutées automatiquement au démarrage de PHP-FPM.

### 3. Raccorder le HTTPS existant

Caddy appartient déjà à son propre projet Docker. Le fichier `compose.caddy.yml` utilise son réseau déclaré **externe** : il doit exister avant le démarrage de myABI et ne sera pas créé ou supprimé par ce projet. Seul `myabi-web` y reçoit l'alias `myabi-translate-web`. `ports: !reset []` supprime la publication locale du profil de base. [Réseaux Compose](https://docs.docker.com/compose/how-tos/networking/), [fusion des configurations](https://docs.docker.com/reference/compose-file/merge/).

Ajouter ou importer **le seul bloc de site** de `Caddyfile.docker.example` dans la configuration existante, en conservant ses autres domaines et options globales :

```caddyfile
myabi.example.org {
    reverse_proxy myabi-translate-web:8080
}
```

Caddy continue de gérer HTTPS et le renouvellement avec ses volumes et sa configuration actuels. Il transmet les en-têtes de proxy automatiquement ; ne pas reprendre un `Host` ou une adresse client arbitraire depuis le navigateur. PHP reçoit `HTTPS=on` dans le web interne de cette application dédiée au frontal TLS. Nginx ne fait confiance aux en-têtes d'adresse que depuis `CADDY_TRUSTED_PROXY_CIDR`. [Reverse proxy Caddy](https://caddyserver.com/docs/caddyfile/directives/reverse_proxy), [HTTPS automatique](https://caddyserver.com/docs/automatic-https).

Valider puis recharger le **Caddyfile complet**, avec le binaire du conteneur réel et son chemin de configuration constaté. Exemple à adapter :

```sh
docker exec NOM_CADDY caddy validate --config CHEMIN_CADDYFILE --adapter caddyfile
# Seulement si la validation réussit :
docker exec NOM_CADDY caddy reload --config CHEMIN_CADDYFILE --adapter caddyfile
```

Le montage existant doit effectivement fournir le fichier modifié au conteneur. Si Caddy est administré par API ou `caddy-docker-proxy`, utiliser son mécanisme actuel plutôt que remplacer sa configuration par ce fragment. L'inventaire doit le confirmer. [Commandes Caddy](https://caddyserver.com/docs/command-line).

Contrôler ensuite la page de connexion, les cookies Secure, l'adresse cliente, un refus sur `/.env`, les limites d'upload et le renouvellement du certificat. Le paramètre FPM termine une requête à 120 secondes ; l'upload est tamponné par Nginx avec une limite de 110 Mio et un délai de lecture distinct. Les imports longs restent en CLI.

### Capacité et temporaires des téléversements

PHP reçoit ses temporaires dans `storage/framework/uploads`, créé avec le masque privé `0007` et inclus dans le comptage de `storage_path()`. Les limites restent de 100 Mio par fichier et 110 Mio par requête ; le tmpfs `/tmp` de 64 Mio ne reçoit plus les fichiers envoyés. Le quota applicatif est contrôlé après réception : il ne réserve pas l'espace nécessaire au tampon Nginx ni aux réceptions simultanées.

Avant ouverture, mesurer l'espace libre sur le système de fichiers de `data/storage` et sur celui des couches Docker de Nginx. Prévoir au minimum 110 Mio par requête simultanée tamponnée par Nginx, plus 100 Mio par réception PHP (trois workers FPM au maximum, soit 300 Mio), en plus de l'espace des originaux, du traitement et des autres services. Deux fichiers de 100 Mio demandent ainsi au moins 420 Mio de marge temporaire répartie sur ces emplacements. Adapter cette marge à la concurrence réellement autorisée et alerter avant saturation ; le nombre de clients Nginx n'est pas limité par les trois workers PHP.

PHP supprime normalement ses temporaires à la fin des requêtes. Après un arrêt brutal, nettoyer uniquement les fichiers `php*` ordinaires du dossier dédié, **pendant une fenêtre de maintenance avec les services `myabi-web` et `app` arrêtés** : l'ancienneté seule ne permet pas d'exclure un upload actif. Depuis le répertoire Compose, exécuter ces étapes dans un shell avec `set -e`, après arrêt des timers et fin des opérations déjà actives :

```sh
was_down=$(docker compose --env-file compose.env run --rm --no-deps cli sh -eu -c 'if test -f storage/framework/down; then printf 1; else printf 0; fi')
docker compose --env-file compose.env run --rm cli php artisan down --quiet
docker compose --env-file compose.env stop --timeout 1800 myabi-web
docker compose --env-file compose.env stop --timeout 130 app
test -z "$(docker compose --env-file compose.env ps --status running --services app myabi-web)"
docker compose --env-file compose.env run --rm --no-deps cli sh -eu -c '
    uploads=/var/www/html/storage/framework/uploads
    test ! -L "$uploads"
    test "$(realpath -e "$uploads")" = "$uploads"
    find "$uploads" -maxdepth 1 -type f -name "php*" -delete
'
docker compose --env-file compose.env up -d app myabi-web
if test "$was_down" = 0; then
    docker compose --env-file compose.env run --rm cli php artisan up --quiet
fi
```

Ne pas démarrer une autre instance PHP pendant cette fenêtre. Ne rouvrir avec `artisan up` que si la maintenance a été mise en place pour ce nettoyage ; conserver une maintenance préexistante. Contrôler `/up` et `/login` puis réactiver les timers ; en cas d'échec, conserver la maintenance. Aucun nettoyage de temporaires n'est planifié pendant le service. La recette Docker doit envoyer des CSV synthétiques de 70 Mio et proches de 100 Mio, puis deux fichiers simultanés ; comparer les empreintes privées, l'absence de temporaires résiduels et le refus au-delà de 100 Mio.

Le test de transport optionnel ci-dessous utilise une image PHP myABI construite localement, Nginx 1.30 et les configurations du dépôt montées en lecture seule. Il crée puis supprime ses propres conteneurs, réseau et volume, envoie uniquement des CSV synthétiques, vérifie les empreintes et force une nouvelle adresse PHP. Son point HTTP autonome ne remplace pas la recette d'import Laravel authentifiée. Depuis la racine du dépôt, avec environ 1 Gio libre pour les fixtures et leur stockage :

```sh
MYABI_RUNTIME_APP_IMAGE=myabi-translate-app:TAG_LOCAL python3 -m unittest discover -s tests/Operations -p test_docker_runtime.py -v
```

### 4. Planification et sauvegarde

Installer les quatre unités `myabi-docker-*.service`/`.timer` dans `/etc/systemd/system` après adaptation des chemins. Activer uniquement les timers Docker de ce projet : aucun cron `schedule:run` ni timer natif concurrent. Le worker exécute une opération reprenable par minute ; le résumé est séparé à 07:00 Europe/Zurich et attend le verrou avant de choisir l'image. Les services ont un plafond de durée pour détecter les exécutions bloquées ; consulter le journal avant de reprendre un travail interrompu.

```sh
systemctl daemon-reload
systemctl enable --now myabi-docker-work.timer myabi-docker-notifications.timer
systemctl list-timers 'myabi-docker-*'
```

Sauvegarde cohérente vers un fichier privé, depuis ce même répertoire Linux :

```sh
umask 077
install -d -m 0700 backups
docker compose --env-file compose.env run --rm -T backup > backups/myabi.partial.zip
python3 ../../scripts/verify-backup.py backups/myabi.partial.zip
mv backups/myabi.partial.zip backups/myabi.zip
```

**Exécuter chaque étape seulement si la précédente réussit** (script d'automatisation avec `set -euo pipefail`). Le flux est binaire : ne pas le convertir en texte via un ancien PowerShell. La sauvegarde prend le verrou commun, respecte une maintenance déjà active, sauvegarde SQL + fichiers privés + manifeste, puis rouvre uniquement si elle avait elle-même fermé le site. Copier cette archive vers une destination privée **hors de ce VPS**, vérifier sa somme et tester sa restauration. Les fichiers `secrets/` sont conservés séparément. Une copie locale et un instantané fourni par l'hébergeur seuls ne constituent pas la procédure de reprise.

Prévoir une sauvegarde périodique après choix de sa destination et de sa rétention ; aucun envoi externe n'est configuré sans cette information. Le test de restauration réutilise `scripts/restore-backup.py` vers une base et un dossier privés neufs, avec un compte autorisé à recréer les triggers/DEFINER. Ne jamais tester en écrasant la base active.

## Longueur minimale des mots de passe

`AUTH_PASSWORD_MIN_LENGTH=8` fixe la longueur minimale, avec 8 caractères par défaut lorsque la variable est absente. Ce réglage s’applique à l’installation du premier administrateur et à toute définition ou modification d’un mot de passe. Avec Docker, modifier `/opt/myabi/deploy/docker/secrets/app.env`, puis appliquer les commandes de recréation du service `app`, de `config:cache` et de rechargement PHP-FPM de la [procédure ci-dessous](#réglage-global-du-mfa). En installation native, modifier `/srv/myabi/shared/.env`, exécuter `php artisan config:cache` depuis la version active et recharger son service PHP-FPM. En développement, modifier `.env` puis exécuter `php artisan config:clear`.

## Réglage global du MFA

Le paramètre `MFA_ENABLED=false`, valeur par défaut, désactive le second facteur pour tous les comptes : ni défi TOTP à la connexion, ni obligation de configuration selon le rôle. Les secrets et codes de récupération existants restent conservés, sans être affichés dans le profil. Avec `MFA_ENABLED=true`, le TOTP redevient obligatoire pour Validateur, Gestionnaire et Administrateur, et facultatif pour les autres rôles. Tout compte ayant déjà confirmé son TOTP doit fournir un code à la connexion. À la réactivation, les sessions ouvertes sans MFA doivent se reconnecter pour les comptes déjà configurés ; les comptes soumis à l'obligation et non encore configurés sont dirigés vers leur profil.

Pour Docker, modifier `MFA_ENABLED` dans `/opt/myabi/deploy/docker/secrets/app.env` (le `.env` privé monté dans l'application), puis appliquer au seul service PHP de myABI :

```sh
cd /opt/myabi/deploy/docker
docker compose --env-file compose.env up -d --no-deps --force-recreate app
docker compose --env-file compose.env exec app php artisan config:cache
docker compose --env-file compose.env kill -s USR2 app
# Attendre que PHP écoute après le rechargement (au plus 60 secondes).
docker compose --env-file compose.env exec -T app php -r '
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $socket = @fsockopen("127.0.0.1", 9000, $errno, $error, 1);
        if ($socket) { fclose($socket); exit(0); }
        sleep(1);
    }
    exit(1);
'
docker compose --env-file compose.env exec -T myabi-web nginx -t
docker compose --env-file compose.env exec -T myabi-web nginx -s reload
curl --fail --silent --show-error --output /dev/null https://myabi.example.org/up
curl --fail --silent --show-error --output /dev/null https://myabi.example.org/login
```

Exécuter chaque étape seulement si la précédente réussit (`set -e`). La recréation reprend le fichier privé même si l'éditeur l'a remplacé ; le cache de configuration est reconstruit dans le conteneur web, dont `bootstrap/cache` est un volume temporaire. Le signal `USR2` recharge PHP-FPM et son OPcache (PHP-FPM est le processus principal de l'image). Les commandes CLI ponctuelles lisent le même fichier privé et disposent de leur propre cache temporaire ; une commande `config:cache` lancée avec `run --rm cli` ne mettrait pas à jour le conteneur web. La disponibilité du port PHP, la validation Nginx et les deux contrôles HTTP conditionnent la fin de l'intervention. Aucun redémarrage du frontal Caddy partagé n'est nécessaire.

L'upstream livré utilise une zone partagée et `resolve` avec le DNS Docker `127.0.0.11`, afin de retrouver l'adresse PHP après recréation. Cette fonction est disponible dans Nginx libre depuis 1.27.3 et l'image livrée utilise 1.30. Le rechargement explicite ci-dessus couvre aussi les installations conservant l'ancienne configuration. Dans une stack jetable, forcer un changement d'adresse PHP et contrôler les réponses HTTP après expiration du cache DNS de cinq secondes. [Résolution Nginx](https://nginx.org/en/docs/http/ngx_http_upstream_module.html#resolve), [remplacement des conteneurs Docker](https://docs.docker.com/compose/how-tos/networking/#updating-containers-on-the-network).

Pour la variante native, modifier `/srv/myabi/shared/.env`, reconstruire la configuration avec `php artisan config:cache` depuis la version active et recharger son service PHP-FPM. En développement local, modifier `.env` puis exécuter `php artisan config:clear`. Conserver le cache applicatif des limites de tentatives et de protection anti-rejeu TOTP. Aucun changement de configuration ni déploiement distant n'est effectué par l'ajout de cette option au code.

## Mises à jour et retour arrière Docker

1. Préparer les nouvelles images et garder les tags/digests de la version précédente. Mesurer la marge disque ; aucun nettoyage global Docker.
2. Arrêter uniquement les deux timers myABI et attendre la fin des services déjà actifs. Ne pas tuer un import en cours pour gagner du temps.
3. Avec l'ancienne image, placer l'application en maintenance (`cli php artisan down --quiet`), produire une sauvegarde cohérente, vérifier puis copier hors VPS. Arrêter `myabi-web` et `app` du projet après fermeture ; ne pas arrêter `db` ni les autres projets.
4. Fixer la nouvelle version dans `compose.env`, exécuter `migrate`, puis `provision` pour les droits des nouvelles tables. Contrôler la santé CLI et `security:check-audit`.
5. Recréer `app` et `myabi-web`, rouvrir explicitement, vérifier HTTPS et la connexion, puis réactiver les timers. En cas d'échec, laisser/remettre la maintenance et examiner la cause.

Un retour au tag précédent n'est sûr que si le schéma reste compatible. Si les migrations ont changé, restaurer une copie cohérente ou établir la compatibilité avant réouverture. Ne pas lancer `migrate:rollback` automatiquement. Les volumes/bind mounts survivent aux recréations ; **ne jamais employer `down -v` sur l'installation réelle**.

## Livraison native alternative

Les modèles `deploy/vps/` servent à une installation native volontaire : pool FPM isolé, ACL permettant à Nginx de lire uniquement `public`, secrets SQL de migration inaccessibles au compte web, timers et contrôle HTTPS préalable. Voir [leur guide](../deploy/vps/README.md) et la procédure générique de [livraison/reprise](operations.md).

Le paquet natif se construit avec `scripts/package-release.sh` et se livre avec `scripts/deploy.sh` (voir [operations.md](operations.md)). Les variables optionnelles sont `PHP_BIN`, `DEPLOY_GROUP` et `DEPLOY_PUBLIC_GROUP`. Ne pas utiliser cette voie pour Docker. Le hook privé `post-migrate-hook.sh` peut appliquer les droits des nouvelles tables avant les contrôles de santé.

Pour automatiser Compose, l'étape suivante sera de construire/publier les deux images depuis un commit testé, puis de faire exécuter la procédure ci-dessus avec une identité dédiée et une clé d'hôte vérifiée. On décidera du registre et du mécanisme d'exécution après le premier déploiement manuel. Ne pas installer de runner d'intégration permanent sur le VPS.

## Critères avant ouverture aux utilisateurs

- Système hôte maintenu, services existants inventoriés, HTTPS et sauvegarde hors serveur fonctionnels.
- Droits SQL contrôlés ; premier administrateur nominatif et TOTP configuré si `MFA_ENABLED=true` ; SMTP testé vers une adresse de recette autorisée.
- Sept formats DEVCONF, progression, publication et transfert complet vérifiés sur cette cible.
- Réimport des 100 traductions dans myABI, arbitrages métier restants et performances de cinq utilisateurs.
- Capacité mesurée avec historique, réimport, publication, sauvegarde et images conservées ; restauration complète éprouvée.

Le [PV local](recette.md) reste la preuve des essais déjà effectués. Le changement d'hébergement lève la contrainte de l'offre Free mais ne transforme pas ces résultats en recette distante.
