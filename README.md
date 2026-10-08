# Référentiel des traductions myABI · ARGE-ABI

Application Laravel 13 / PHP 8.4 pour importer les sept formats DEVCONF, rechercher les termes, proposer et valider les traductions, gérer les révisions et publier des catalogues figés par organisation. Les écrans Blade reprennent la [maquette myABI du 22.09.2026](maquette/README.md) et sont disponibles en allemand, français et italien. Le bandeau bleu nuit, les onglets, la navigation latérale et les tableaux compacts utilisent uniquement des ressources locales ; le bouton Compact mémorise la densité d’affichage dans le navigateur. Les originaux, rapports et publications restent dans le stockage privé.

Le périmètre métier est défini dans les [spécifications fonctionnelles](specifications-fonctionnelles-referentiel-traductions-arge-abi.md), les [spécifications techniques](specifications-techniques-referentiel-traductions-arge-abi.md) et l’[annexe DEVCONF](docs/formats-devconf.md). Les points soumis à confirmation de LogObject sont signalés dans l’application ; ils ne sont pas résolus par des transformations silencieuses.

## Installation locale

Prérequis : PHP 8.4 avec `mbstring`, `intl`, `iconv`, `pdo_mysql`, `zip`, `xml` et `ctype`, Composer 2 et MariaDB 11.4. SQLite avec `pdo_sqlite` sert aux tests rapides. L'application ne nécessite ni Node ni Redis ; CSS et JavaScript sont livrés directement dans `public/`. Node 24 sert uniquement aux tests JavaScript de développement et de CI, sans dépendance npm.

```sh
composer install
cp .env.example .env
php artisan key:generate
```

Renseigner la base de développement dans `.env`, définir `APP_ENV=local`, `APP_URL=http://127.0.0.1:8000`, `SESSION_SECURE_COOKIE=false` et `MAIL_MAILER=log` pour les essais sans envoi de courriel. Ne pas utiliser les identifiants du pilote ni des données opérationnelles dans les tests. Ne jamais committer `.env` ou les fichiers privés.

```sh
php artisan migrate
php artisan referentiel:install --email=prenom.nom@example.org --name="Prénom Nom"
php artisan serve --host=127.0.0.1
```

La commande d’installation demande le mot de passe sans l’afficher. Le MFA est désactivé par défaut pour tout le serveur (`MFA_ENABLED=false` dans `.env`) : aucun compte n’a de code TOTP à saisir ni de second facteur à configurer. L’administrateur peut créer les organisations et inviter les utilisateurs depuis l’administration. Le compte technique d’import est créé automatiquement et ne peut pas se connecter.

Les mots de passe exigent au moins 8 caractères par défaut. La variable `AUTH_PASSWORD_MIN_LENGTH=8` dans `.env` règle cette longueur pour l’installation, les invitations, la récupération, le profil et l’administration. Après modification, exécuter `php artisan config:clear` en développement, ou reconstruire la configuration avec `php artisan config:cache` et recharger PHP-FPM en déploiement ; voir le [guide VPS](docs/deploiement-vps.md#longueur-minimale-des-mots-de-passe).

Pour activer le MFA, définir `MFA_ENABLED=true` dans `.env`, puis exécuter `php artisan config:clear` en développement. Le TOTP devient obligatoire pour les rôles Validateur, Gestionnaire et Administrateur ; les autres utilisateurs peuvent l’activer et tout compte déjà configuré doit fournir son code à la connexion. Lors de la réactivation, les sessions ouvertes sans MFA doivent se reconnecter si le compte a déjà confirmé son TOTP ; les comptes soumis à l’obligation et non encore configurés sont dirigés vers le profil. Repasser à `false` conserve les secrets et codes de récupération sans les afficher dans le profil. En déploiement, reconstruire la configuration avec `php artisan config:cache` et recharger PHP-FPM ; voir le [guide VPS](docs/deploiement-vps.md) pour les commandes Docker et le fichier concerné.

Le serveur local (`php artisan serve` ou `composer dev`) ne démarre pas les traitements en arrière-plan. Pour traiter les imports, publications et validations en masse en attente, exécuter `php artisan referentiel:work` dans un autre terminal : cette commande ponctuelle enchaîne les lots jusqu’à épuisement des opérations en attente, puis s’arrête. L’option `--once` limite l’exécution à une opération ou à un seul lot de validation de 300 propositions maximum ; elle ne suffit donc pas à terminer une validation de plusieurs milliers de propositions. Pour un traitement automatique en développement, maintenir `php artisan schedule:work` dans un terminal local ; ce planificateur lance aussi les notifications quotidiennes. En production, utiliser uniquement la tâche planifiée `schedule:run` décrite dans la procédure d’exploitation.

Sous PowerShell, remplacer `cp` par `Copy-Item`. Sur ce poste, le runtime de vérification est disponible dans `.runtime/php/php.exe` et reste ignoré par Git ; ce répertoire ne fait pas partie d’une livraison.

```powershell
.\.runtime\php\php.exe artisan referentiel:work
```

## Utilisation

1. L’administrateur crée les organisations et des comptes nominatifs avec rôles et langues. Les invitations expirent après 72 heures.
2. Le Gestionnaire crée une version myABI, téléverse un DEVCONF complet, examine l’analyse et ses anomalies, puis confirme l’application. Le traitement se déroule via le planificateur ; la progression et le rapport restent consultables.
3. Le Traducteur recherche les termes de son périmètre et envoie explicitement ses propositions. Les caractères non exportables et placeholders manquants bloquent l’envoi.
4. Le Validateur examine les propositions dans ses langues et valide ou rejette avec un motif. Les valeurs examinées et la concurrence sont contrôlées par le serveur. Une dérogation du Gestionnaire au principe des quatre yeux est motivée et auditée.
5. Le Gestionnaire publie un catalogue pour les organisations choisies. Les utilisateurs téléchargent les publications disponibles pour leur organisation. Les fichiers figés et leur manifeste permettent de retrouver les valeurs publiées et leurs révisions.

Le profil contient la langue de l’interface, l’activation du résumé quotidien, le second facteur lorsque le serveur l’active (sinon son statut désactivé) et les actions personnelles. Le Gestionnaire et l’Administrateur disposent du journal d’audit filtrable et de son export CSV.

## Vérification

```sh
php artisan test
python3 -m unittest discover -s tests/Operations -p 'test_*.py'
node --test tests/JavaScript/*.test.cjs
```

Les tests automatiques utilisent des fixtures synthétiques pour les formats, droits, invitations, TOTP, sessions, workflow, imports, publications et artefacts d’exploitation. Ils sont prévus pour PHP 8.4 avec SQLite et MariaDB 11.4. Les tests n’envoient aucun e-mail.

Le contrôle des originaux est une étape locale distincte dans un environnement autorisé : `php scripts/test_devconf_roundtrip.php --validate-semantics`. Les mesures sur les fichiers complets, l'espace réellement consommé chez l'hébergeur et la réimportation dans une instance myABI doivent figurer dans le procès-verbal de recette. Un test local ne certifie pas le quota ou les performances du pilote.

## Extension navigateur MyAB Translation Inspector

Le dossier [`extension/`](extension/README.md) contient une extension Chrome et Firefox (WebExtensions MV3, TypeScript) : un Alt+clic sur un texte de l’ERP MyAB interroge `POST {apiUrl}/search` et affiche dans un panneau flottant la clé, le texte allemand, la traduction, le module et le statut des correspondances, avec les actions Copier la clé, Copier le texte allemand, Ouvrir la traduction et Signaler correcte. Seuls le texte cliqué et son contexte (jamais de HTML) sont transmis, uniquement sur les domaines MyAB configurés.

- L’extension est indépendante de l’application Laravel et hors des livraisons Laravel et Docker. Node 20.19+ n’est requis que pour la développer : `cd extension && npm install && npm run check`.
- Les routes `POST /api/browser-extension/search` et `/feedback` n’existent pas encore dans cette application. En attendant, `npm run serve:mock` lance une API factice qui permet d’essayer l’extension sur le vrai MyAB.
- Le [README de l’extension](extension/README.md) décrit le build, le chargement dans Chrome et Firefox, la configuration, le contrat de l’API, la sécurité et la page de test.

## Livraison et exploitation

Le [guide VPS](docs/deploiement-vps.md) compare les modes de livraison et décrit la stack Docker Compose, les configurations, les sauvegardes et le retour arrière. Caddy est le frontal HTTPS unique, en conteneur Docker : le profil `compose.caddy.yml` relie uniquement `myabi-web` au réseau existant, sans port hôte publié. Une variante native reste disponible dans `deploy/vps/`.

Le [procès-verbal de recette](docs/recette.md) distingue les preuves locales des essais encore nécessaires sur l'hébergement cible et dans myABI. Les mesures historiques alwaysdata Free restent documentées ; le VPS reçoit sa propre enveloppe de stockage mesurée. Le profil utilise la compression InnoDB ; les migrations vérifient qu'elle est effectivement activée. Ne pas relever un quota pour contourner un refus sans capacité réellement disponible.

Voir [docs/operations.md](docs/operations.md) pour l’hébergement, le paquet de livraison, les sauvegardes, la restauration et le retour au code précédent. Voir [docs/security-deployment.md](docs/security-deployment.md) pour les comptes SQL distincts, le contrôle du journal en ajout seul et les sessions.

Le dépôt ne contient pas de workflow d'intégration ou de livraison automatique. Le paquet de production se construit depuis `composer.lock` avec `scripts/package-release.sh` ; `scripts/deploy.sh` le livre sur une installation native (voir [docs/operations.md](docs/operations.md)). La livraison Docker suit le guide VPS et ne doit pas utiliser ce script natif.

Les fonctions explicitement prévues en lot 2 ou 3 — fédération OpenID Connect, glossaire, commentaires, mémoire de traduction, réimport XLSX, assistance automatique et API — ne sont pas activées par cette livraison du MVP.

## Données, licence et contribution

- **Données** : le dépôt ne contient aucune donnée réelle. Les sept fichiers DEVCONF de LogObject se placent localement dans `data/imports/` (ignoré par Git, voir [data/README.md](data/README.md)) ; les tests utilisent des fixtures synthétiques.
- **Licence** : [GNU AGPL-3.0](LICENSE). Les données et les marques de tiers (LogObject, myABI) ne sont pas couvertes par cette licence.
- **Sécurité** : voir [SECURITY.md](SECURITY.md) pour signaler une vulnérabilité.
- **Contribution** : voir [CONTRIBUTING.md](CONTRIBUTING.md).
