# Installation, livraison et reprise

La cible VPS demandée le 22 septembre 2026 est décrite dans [le guide VPS](deploiement-vps.md). Les sections alwaysdata ci-dessous documentent le profil initial ; ses limites de stockage et services gérés ne s'appliquent pas automatiquement au VPS. Les formats de paquet, sauvegarde et restauration restent communs. La variante native accepte désormais `DEPLOY_GROUP`, `DEPLOY_PUBLIC_GROUP` et un hook privé optionnel `post-migrate-hook.sh` pour les droits des nouvelles tables après migration, avant les contrôles de santé.

## Préparer le pilote

PHP **8.4.27 minimum** est requis pour CLI et FPM. Appliquer les [correctifs des CVE PHP du 21 octobre 2026](security-deployment.md) et renouveler les processus avant ouverture du service ; le seul choix « PHP 8.4 » ne prouve pas la présence des correctifs.

Le site sert uniquement `current/public`, avec PHP 8.4 et HTTPS. Choisir la même version de PHP pour le site et les tâches SSH. alwaysdata permet de choisir les versions de langage au niveau du site et de l’environnement ; vérifier les binaires réellement fournis au compte. [Documentation PHP](https://help.alwaysdata.com/en/docs/web-hosting/languages/php/).

Créer une base MariaDB et deux comptes distincts : un compte applicatif limité et un compte de migration. Appliquer la [procédure de sécurité SQL](security-deployment.md). La version MariaDB testée par la CI est 11.4 ; confirmer la version réellement attribuée au pilote avant la recette.

Arborescence recommandée, dans un répertoire privé du compte :

```text
myabi/
  current -> releases/<commit>
  releases/<commit>/          code courant et code précédent
  incoming/                  paquet transféré, supprimé après succès
  shared/
    .env                     configuration applicative privée
    migration.env            variables DB_* du compte de migration
    backup.cnf               accès MariaDB de sauvegarde
    backup-hook.sh           copie hors hébergement avant migration
    initial-quota.json       seulement pour la première livraison
    storage/                 originaux, rapports, publications et runtime
```

Les fichiers de configuration, clés et scripts de sauvegarde sont détenus par le responsable de l’hébergement, avec des droits `0600` pour les secrets et `0700` pour le hook. Ils ne sont ni copiés dans Git ni inclus dans l’artefact GitHub. Le compte SQL de migration reste hors du `.env` web. Exemple du fichier `migration.env`, interprété comme un script shell privé :

```sh
DB_CONNECTION=mariadb
DB_HOST=mysql-compte.alwaysdata.net
DB_PORT=3306
DB_DATABASE=compte_myabi
DB_USERNAME=compte_migration
DB_PASSWORD='secret-stocke-hors-git'
```

Le fichier `.env` web part de `.env.example`, avec `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, la clé `APP_KEY` générée une seule fois, le SMTP réellement fourni et les identifiants SQL applicatifs. Conserver `QUEUE_CONNECTION=sync` et `CACHE_STORE=file` pour le pilote : aucun processus permanent ni Redis n’est requis. Les traitements lourds sont exécutés par une commande cron reprenable ; les courriels du workflow sont délivrés pendant le résumé quotidien.

Configurer chaque minute dans le panneau de l’hébergement :

```sh
cd /home/COMPTE/myabi/current && php artisan schedule:run
```

La planification lance les imports/publications et le résumé quotidien à 07:00, heure suisse. Relever le code de sortie et la sortie d’erreur dans les outils existants de l’équipe. La commande `referentiel:work --once` permet de traiter manuellement un travail en attente. Une opération en échec reste visible et peut être examinée avant reprise.

La première livraison doit disposer de `shared/initial-quota.json` avec des mesures réelles, par exemple :

```json
{"database_bytes":0,"external_bytes":0,"quota":1073741824,"url":"https://compte.alwaysdata.net"}
```

Remplacer les zéros par les usages constatés. L’espace de l’hébergement est partagé avec la base, les courriels et d’autres fichiers. La valeur `external_bytes` représente ici l’espace hors du répertoire `myabi` et hors de sa base. Les livraisons suivantes interrogent la base et la configuration de l’application. La précondition de déploiement additionne tout le répertoire du site, le paquet reçu, la nouvelle version décompressée, la base, l’usage externe et 20 Mio de marge. Deux versions du code sont conservées après succès. Si le quota ne suffit pas, le déploiement s’arrête ; aucun passage payant ni purge de l’historique n’est automatique.

Le quota de 1 073 741 824 octets des exemples est l'hypothèse de recette locale (1 Gio). L'[offre Free](https://help.alwaysdata.com/fr/docs/admin-facturation/facturation/prix-cloud-public/) affiche 1 Go partagé entre fichiers, courriels et bases. Relever la limite effective en octets dans le compte et renseigner `STORAGE_QUOTA_BYTES` ainsi que le quota de la première livraison ; ne pas supposer que l'affichage commercial désigne 1 Gio. Réconcilier aussi l'estimation SQL avec l'espace réellement comptabilisé, et inclure tout écart non compté dans `EXTERNAL_STORAGE_USED_BYTES`.

## Construire et livrer le paquet

Le dépôt ne fournit pas de workflow d'intégration ni de livraison automatique : les tests et la construction du paquet s'exécutent localement ou dans la chaîne d'intégration de l'exploitant.

Préparer auparavant la [version applicative `AAAA.MM.compteur`](../../README.md#version-de-lapplication) en local avec `php artisan app:version --bump` et committer le fichier `config/version.php`. Le paquet et les images Docker embarquent ce fichier ; ne pas incrémenter la version sur le serveur. Une reconstruction ou un retour arrière conserve le numéro du code correspondant.

Depuis un clone propre du commit à livrer, sous Linux :

```sh
composer install --no-dev --optimize-autoloader --no-interaction
version=$(php artisan app:version)
bash scripts/package-release.sh "v$version" "$(git rev-parse HEAD)" "myabi-v$version.tar.gz"
python3 scripts/inspect-release.py "myabi-v$version.tar.gz"
```

La construction utilise les dépendances de production verrouillées sans les dépendances de test, vérifie les ressources CSS/JavaScript livrées, crée `RELEASE.json`, puis prépare une archive depuis une liste autorisée de répertoires. Secrets, données d’origine, stockage privé, tests du projet, seeders/factories de développement et caches générés sont exclus. L'inspecteur rejette les chemins inattendus, traversées de répertoires, liens, fichiers d’environnement et dépendances de test. Les sauvegardes métier ne transitent jamais par l'outil de construction.

Transférer l'archive par SSH avec une clé dédiée à privilèges limités et une empreinte d'hôte vérifiée (`StrictHostKeyChecking=yes`, sans `ssh-keyscan` non vérifié), puis exécuter sur l'hébergement `bash scripts/deploy.sh /chemin/absolu/myabi myabi-v2026.11.1.tar.gz` (adapter le numéro). Le chemin de l'application ne doit pas contenir d'espaces. Si cette livraison est automatisée, conserver ces mêmes garanties et ne jamais afficher les secrets dans les journaux. [Documentation SSH alwaysdata](https://help.alwaysdata.com/en/docs/web-hosting/remote-access/ssh/).

Le script `deploy.sh` attend au maximum 35 minutes le verrou exclusif `shared/storage/app/private/operations.lock`, commun aux imports, publications et opérations. Il contrôle l’espace, installe le code, suspend les requêtes web, appelle le hook de sauvegarde, exécute les migrations avec le compte distinct, puis contrôle le schéma, les répertoires, les droits d’audit et les pages `/up` et `/login` en HTTPS. Le lien `current` est remplacé atomiquement. L’application ne rouvre qu’après ces contrôles.

## Consulter les logs techniques et le journal d’audit

### Emplacement et configuration des logs Laravel

Les logs techniques Laravel servent au diagnostic des avertissements et des erreurs de l’application. Leur configuration est définie dans [config/logging.php](../../config/logging.php). Ils restent hors de `public`, de Git et des paquets de livraison.

| Installation | Répertoire des logs Laravel |
| --- | --- |
| Développement local | `storage/logs/`, à la racine du projet |
| Native ou hébergement géré avec `deploy.sh` | `shared/storage/logs/`, accessible aussi par `current/storage/logs/` |
| Docker | `data/storage/logs/` depuis le répertoire Compose, monté dans `/var/www/html/storage/logs/` dans les conteneurs `app` et `cli` |

Le [.env.example](../../.env.example) et les exemples natif/Docker prévoient les réglages suivants dans le fichier d’environnement applicatif privé :

```dotenv
LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14
```

Avec ces réglages, les fichiers se nomment `laravel-AAAA-MM-JJ.log`. Le seuil `warning` conserve les avertissements et les niveaux supérieurs (`error`, `critical`, `alert`, `emergency`) ; les messages `debug`, `info` et `notice` sont filtrés. Vérifier les réglages de l’installation : en l’absence des variables, la configuration du dépôt utilise `stack` avec `single`, donc `laravel.log`, au niveau `debug`. Après modification, appliquer la [procédure de rechargement de configuration](security-deployment.md#authentification-et-sessions). Conserver `APP_DEBUG=false` en exploitation.

### Contenu et confidentialité

Les entrées portent une date, un niveau, un message et éventuellement un contexte. Les exceptions signalées à Laravel peuvent inclure leur message et une trace d’appels.

Pour les échecs pris en charge par [OperationFailure](../../app/Services/OperationFailure.php), l’événement `operation.failed` contient uniquement `code`, `reference` (UUID de diagnostic), `entity`, `id`, `exception_class` et `sqlstate` lorsqu’il est disponible. Ce mécanisme n’enregistre ni le message brut de l’exception, ni les requêtes SQL, ni les valeurs métier. La référence permet de retrouver l’entrée correspondant à l’échec d’une opération. Les exceptions de l’API de l’extension navigateur sont volontairement exclues du signalement Laravel dans [bootstrap/app.php](../../bootstrap/app.php), afin de ne pas y recopier de textes inspectés ou d’identifiants sensibles.

Réserver la lecture aux personnes autorisées. Les autres erreurs peuvent contenir des informations sensibles : vérifier et expurger tout extrait avant partage. Ne pas publier de logs bruts, de secrets ou de valeurs métier dans une issue, un dépôt ou une livraison.

### Rotation

Avec `daily` et `LOG_DAILY_DAYS=14`, Monolog conserve au maximum 14 fichiers quotidiens correspondants et supprime les plus anciens lors de la rotation déclenchée par une écriture. Il s’agit d’un nombre de fichiers, sans garantie de suppression après 14 jours calendaires en l’absence d’activité. Aucun plafond de taille par fichier Laravel n’est configuré ; surveiller l’espace occupé. Un ancien fichier `laravel.log` issu du canal `single` n’est pas pris en charge par cette rotation quotidienne.

Les journaux Docker de sortie standard et d’erreur ont leur propre rotation (trois fichiers de 10 Mio par service dans le modèle fourni). Cette limite ne s’applique pas aux fichiers Laravel du stockage persistant.

### Consultation

Depuis la racine du projet local ou le répertoire `current` en installation native, sous Linux :

```sh
ls -lh storage/logs/laravel*.log
tail -n 100 -f storage/logs/laravel-AAAA-MM-JJ.log
```

Sous PowerShell, depuis la racine du projet local :

```powershell
Get-ChildItem .\storage\logs\laravel*.log
Get-Content -LiteralPath .\storage\logs\laravel-AAAA-MM-JJ.log -Tail 100 -Wait
```

Remplacer `AAAA-MM-JJ` par la date du fichier à examiner ; `Ctrl+C` arrête le suivi. À la rotation quotidienne, relancer le suivi sur le nouveau fichier. Pour retrouver une référence d’échec sous Linux, utiliser `grep -F 'REFERENCE_UUID' storage/logs/laravel-*.log` en remplaçant `REFERENCE_UUID` par la référence recherchée.

Avec Docker, depuis le répertoire Compose, consulter les fichiers Laravel dans le conteneur actif :

```sh
docker compose --env-file compose.env exec -T app sh -c 'ls -lh storage/logs/laravel*.log'
docker compose --env-file compose.env exec -T app tail -n 100 -f storage/logs/laravel-AAAA-MM-JJ.log
```

Pour les sorties des services PHP-FPM et Nginx, utiliser séparément `docker compose --env-file compose.env logs --tail=100 app myabi-web`. Avec le canal `daily`, cette commande n’affiche pas les fichiers de logs Laravel. Les commandes ci-dessus lisent les journaux sans les modifier.

### Journal d’audit métier

Le journal d’audit est enregistré en base dans la table `audit_events`. Il conserve les actions métier et de sécurité (imports, propositions, décisions, publications, comptes), leur auteur, l’organisation, la date, l’entité concernée, les valeurs avant/après selon l’action, l’adresse IP et l’identifiant de requête lorsqu’ils sont disponibles. [AuditService](../../app/Services/AuditService.php) masque les champs de secrets prévus par le service.

Les rôles autorisés le consultent sur `/audit` et peuvent l’exporter en CSV via `/audit/export`. Ce journal est protégé en ajout seul ; la rotation des fichiers techniques ne le purge pas. Ses données et ses exports restent privés. Voir les [protections SQL du journal d’audit](security-deployment.md#accès-mariadb-séparés).

## Sauvegarder hors hébergement

Avant chaque migration, le script exige `shared/backup-hook.sh`. Ce hook s’exécute **sous le verrou déjà acquis**, pendant la maintenance, et doit terminer avec un code non nul si la copie ou sa vérification échoue. Il reçoit les chemins de la nouvelle version, du répertoire partagé et de la version précédente. Il ne doit pas rappeler `backup.sh`, qui chercherait à reprendre le même verrou.

La destination durable appartient à l’organisation et doit être désignée avant l’activation du déploiement. Le hook peut appeler `backup-stream.py` et transférer sa sortie par SSH vers ce stockage, avec une seconde clé dédiée et une empreinte vérifiée. Le programme produit une archive ZIP en flux contenant le dump SQL, les fichiers privés et un manifeste SHA-256 ; il n’écrit pas une seconde copie complète sur le quota du pilote. Le destinataire enregistre dans un fichier temporaire, vérifie `verify-backup.py`, puis renomme atomiquement la copie. Le hook ne réussit qu’après cette confirmation.

`backup.cnf` utilise le format client MariaDB, avec hôte, utilisateur et mot de passe sous `[client]`. Le compte doit pouvoir lire toutes les tables et exporter les triggers ; il n’est pas le compte web limité. Le dump conserve le schéma, les données et les triggers et emploie `--single-transaction` et `--quick`. [Documentation mariadb-dump](https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump).

Pour une sauvegarde manuelle depuis un poste autorisé, après adaptation et vérification de l’hôte SSH :

```sh
ssh -o StrictHostKeyChecking=yes COMPTE@HOTE \
  'bash /home/COMPTE/myabi/current/scripts/backup.sh /home/COMPTE/myabi' > myabi-backup.partial.zip
python3 scripts/verify-backup.py myabi-backup.partial.zip
mv myabi-backup.partial.zip myabi-backup.zip
```

N’exécuter le renommage que si SSH et la vérification réussissent ; conserver le code de sortie et le manifeste. Le script distant respecte une maintenance déjà active et ne rouvre pas un site qu’il n’avait pas fermé. Stocker `APP_KEY`, `.env`, clés et accès de restauration séparément dans les moyens sécurisés existants de l’organisation. L’archive de données ne contient pas ces secrets.

La sauvegarde du fournisseur complète cette copie ; sa durée de conservation et sa restauration doivent être contrôlées dans le compte sélectionné. Un simple téléchargement d’archive n’est pas une preuve de restauration.

## Restaurer et revenir au code précédent

Une restauration s’effectue d’abord dans **une base vide** et **un répertoire privé vide**. Le script refuse d’écraser une base ou un dossier contenant déjà des données. Les secrets de restauration sont fournis dans un fichier client MariaDB distinct. Prévoir les droits pour restaurer les triggers, notamment leur `DEFINER`, qui doit correspondre à un compte existant et autorisé.

```sh
bash scripts/restore.sh /home/COMPTE/myabi \
  /chemin/autorise/myabi-backup.zip \
  /chemin/autorise/private-restaure \
  /chemin/secret/restore.cnf BASE_RESTAURATION_VIDE
```

`verify-backup.py` vérifie tous les membres et leurs empreintes avant l’import. `restore.sh` acquiert le verrou commun et laisse le site en maintenance. Après l’import, vérifier avec la version de code indiquée dans le manifeste : migrations, organisations, comptes, termes, révisions, audit, originaux référencés et empreintes des publications. Tester une connexion avec la clé `APP_KEY` restaurée, un téléchargement complet et un import synthétique dans cet environnement autorisé. Reconfigurer le compte applicatif limité, exécuter `security:check-audit`, puis basculer ensemble la configuration SQL et le stockage privé. Vider/recréer le cache de configuration avant de rouvrir avec `php artisan up`.

Le retour automatique au code précédent n’est permis que si les deux répertoires de migrations sont strictement identiques. Dans ce cas, une erreur de santé restaure le lien du code précédent. Si les migrations diffèrent, le site reste en maintenance : le responsable doit établir la compatibilité du schéma ou restaurer la copie cohérente. Aucun `migrate:rollback` aveugle ne supprime l’historique. Les migrations déjà livrées ne doivent pas être réécrites.

La disponibilité et la capacité du compte alwaysdata, le hook vers le stockage de l'organisation, les droits effectifs de production, le débit des fichiers complets et une restauration intégrale restent des vérifications à effectuer dans les environnements désignés. Les scripts et tests locaux ne les certifient pas à distance.

## Limites de transfert PHP

Le dossier `public` contient un `.user.ini` pour PHP-FPM : mémoire 256 Mio, fichier 100 Mio, requête 110 Mio, lecture de la requête jusqu'à 1 800 secondes. Les traitements métier restent confiés au planificateur ; la requête web normale est limitée à 120 secondes. Vérifier dans le panneau alwaysdata que ces valeurs sont appliquées et que le frontal HTTP accepte aussi le DEVCONF Incident code complet. PHP CLI doit utiliser PHP 8.4 et une limite mémoire de 256 Mio. Le serveur intégré local ignore `.user.ini` : repérer son `php.ini` avec `php --ini`, y appliquer les mêmes limites, puis redémarrer le serveur. Voir le [diagnostic des imports locaux](../../README.md#installation-locale).

Le test du téléversement se fait avec le fichier réel depuis un navigateur, séparément de la durée d'analyse et d'application. Ne pas considérer un import CLI comme preuve du transfert HTTP.

## Captures associées aux propositions

Les sauvegardes cohérentes existantes incluent automatiquement le dossier privé de captures et leurs miniatures. Le même verrou suspend les écritures pendant le dump MariaDB et la copie des fichiers. Après restauration dans une base et un dossier vides, vérifier les références SQL, les empreintes du manifeste, les permissions et l’ouverture authentifiée des captures de propositions en attente, validées et rejetées avant réouverture. Les secrets restent sauvegardés séparément.

La commande ponctuelle `php artisan proposals:screenshots:prune` contrôle les références sans supprimer ; `--apply` retire les seuls orphelins reconnus de plus de 24 heures sous verrou exclusif. Aucune capture référencée n’est purgée. Voir le [contrat des captures](../technique/captures-propositions.md).

## Index du catalogue et recherche approximative

Après sauvegarde cohérente et migration, exécuter `php artisan referentiel:catalogue-search-rebuild`. La commande initialise les références DE et prépare l'index par lots de 200 termes sous le verrou exclusif existant. Les écritures métier sont suspendues pendant ce traitement ponctuel ; la recherche habituelle reste accessible. Ne pas exposer un index en construction comme prêt.

Une erreur d'indexation conserve l'action métier seulement si sa transaction a effectivement été validée et rend l'approximatif indisponible. Contrôler l'espace, les originaux privés et les droits SQL, puis relancer la reconstruction. Une reprise compatible conserve son curseur ; une modification concurrente ou un nouveau profil impose une nouvelle génération. La remise en service suit le contrôle de cohérence et la bascule atomique, sans modification des révisions métier.

Le compte web nécessite `CREATE TEMPORARY TABLES` sur la base applicative, en complément des droits existants table par table. Conserver `SELECT, INSERT` seulement sur `audit_events`. Dans la variante native, l'administrateur SQL accorde ce droit sur la seule base choisie ; il ne donne pas les droits de migration au compte web. Les rapports de calibration et de charge restent locaux sous `.runtime/recherche-approximative/`, hors sauvegardes de livraison et hors `public`. Voir la [conception technique](../technique/recherche-approximative-conception.md).

Les configurations Nginx fournies transmettent `MYABI_REQUEST_STARTED_AT` par FastCGI pour inclure l'attente devant PHP dans le budget. Une configuration native différente doit transmettre le même horodatage interne, avec des horloges cohérentes. Ce paramètre ne doit pas provenir d'un en-tête client. La reconstruction vérifie la cohérence de l'index ; elle ne certifie pas les objectifs de pertinence ou de délai. Prévoir l'espace de deux générations, du cache DE et des fichiers temporaires, puis qualifier les ressources et les recherches larges sur l'environnement cible.
