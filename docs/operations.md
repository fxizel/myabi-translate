# Installation, livraison et reprise

La cible VPS demandée le 22 septembre 2026 est décrite dans [le guide VPS](deploiement-vps.md). Les sections alwaysdata ci-dessous documentent le profil initial ; ses limites de stockage et services gérés ne s'appliquent pas automatiquement au VPS. Les formats de paquet, sauvegarde et restauration restent communs. La variante native accepte désormais `DEPLOY_GROUP`, `DEPLOY_PUBLIC_GROUP` et un hook privé optionnel `post-migrate-hook.sh` pour les droits des nouvelles tables après migration, avant les contrôles de santé.

## Préparer le pilote

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

Le dépôt ne fournit pas de workflow d'intégration ni de livraison automatique : les tests et la construction du paquet s'exécutent localement ou dans la chaîne d'intégration de l'exploitant. Depuis un clone propre du commit à livrer, sous Linux :

```sh
composer install --no-dev --optimize-autoloader --no-interaction
bash scripts/package-release.sh v1.0.0 "$(git rev-parse HEAD)" myabi-v1.0.0.tar.gz
python3 scripts/inspect-release.py myabi-v1.0.0.tar.gz
```

La construction utilise les dépendances de production verrouillées sans les dépendances de test, vérifie les ressources CSS/JavaScript livrées, crée `RELEASE.json`, puis prépare une archive depuis une liste autorisée de répertoires. Secrets, données d’origine, stockage privé, tests du projet, seeders/factories de développement et caches générés sont exclus. L'inspecteur rejette les chemins inattendus, traversées de répertoires, liens, fichiers d’environnement et dépendances de test. Les sauvegardes métier ne transitent jamais par l'outil de construction.

Transférer l'archive par SSH avec une clé dédiée à privilèges limités et une empreinte d'hôte vérifiée (`StrictHostKeyChecking=yes`, sans `ssh-keyscan` non vérifié), puis exécuter sur l'hébergement `bash scripts/deploy.sh /chemin/absolu/myabi myabi-v1.0.0.tar.gz`. Le chemin de l'application ne doit pas contenir d'espaces. Si cette livraison est automatisée, conserver ces mêmes garanties et ne jamais afficher les secrets dans les journaux. [Documentation SSH alwaysdata](https://help.alwaysdata.com/en/docs/web-hosting/remote-access/ssh/).

Le script `deploy.sh` attend au maximum 35 minutes le verrou exclusif `shared/storage/app/private/operations.lock`, commun aux imports, publications et opérations. Il contrôle l’espace, installe le code, suspend les requêtes web, appelle le hook de sauvegarde, exécute les migrations avec le compte distinct, puis contrôle le schéma, les répertoires, les droits d’audit et les pages `/up` et `/login` en HTTPS. Le lien `current` est remplacé atomiquement. L’application ne rouvre qu’après ces contrôles.

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

Le dossier `public` contient un `.user.ini` pour PHP-FPM : mémoire 256 Mio, fichier 100 Mio, requête 110 Mio, lecture de la requête jusqu'à 1 800 secondes. Les traitements métier restent confiés au planificateur ; la requête web normale est limitée à 120 secondes. Vérifier dans le panneau alwaysdata que ces valeurs sont appliquées et que le frontal HTTP accepte aussi le DEVCONF Incident code complet. PHP CLI doit utiliser PHP 8.4 et une limite mémoire de 256 Mio. Le serveur intégré local peut ignorer `.user.ini` : utiliser alors les mêmes réglages dans son `php.ini`.

Le test du téléversement se fait avec le fichier réel depuis un navigateur, séparément de la durée d'analyse et d'application. Ne pas considérer un import CLI comme preuve du transfert HTTP.
