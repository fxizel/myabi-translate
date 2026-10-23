# Comptes, sessions et journal d’audit

## Correctifs du 23 octobre 2026 : CVE-2026-109618 et CVE-2026-109619

Les avis définitifs décrivent deux risques critiques d’exécution de code à distance dans PHP : [corruption lors d’un épuisement mémoire](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) et [état global conservé entre requêtes](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp). PHP-FPM est également concerné selon les chemins exécutés. Seule la mise à jour du moteur corrige complètement ces failles ; un middleware Laravel intervient trop tard pour certains déclenchements.

| Branche | Première version corrigée pour les deux CVE | Utilisation myABI |
| --- | --- | --- |
| PHP 8.2 | 8.2.35 | Non prise en charge |
| PHP 8.3 | 8.3.36 | Non prise en charge |
| PHP 8.4 | **8.4.27** | Référence : image `php:8.4.27-fpm-bookworm` |
| PHP 8.5 | **8.5.12** | Autorisée par Composer, à qualifier avant changement de branche |

Composer et son verrou refusent les versions précédentes, y compris PHP 8.5.0 à 8.5.11. `scripts/check-php-runtime.php`, indépendant de Laravel et des secrets, contrôle le binaire CLI lors de la construction et du démarrage Docker, du déploiement natif et des contrôles d’exploitation. Il ne certifie pas le binaire FPM d’un autre service. `composer audit` vérifie les bibliothèques ; il ne met pas PHP à jour.

### Configuration complémentaire

Le suivi natif `session.upload_progress.enabled` est désactivé dans le `php.ini` Docker et imposé administrativement dans les pools Docker/natif. L’application suit ses imports elle-même et utilise les sessions Laravel en base. Ce réglage réduit un chemin décrit dans CVE-2026-109619 ; il ne remplace pas le correctif PHP. Le `.user.ini` fourni reprend la valeur, mais est appliqué trop tard pour protéger le démarrage : sur hébergement géré, demander aussi son application dans `php.ini` ou le pool FPM.

Conserver les limites de mémoire, de taille des requêtes et de durée. Les modèles Nginx bornent le corps HTTP à 110 Mio avant PHP. `memory_limit=-1`, le masquage des erreurs et le recyclage périodique des workers ne sont pas des corrections complètes. Éviter les paramètres FastCGI `PHP_VALUE`/`PHP_ADMIN_VALUE` par requête ; utiliser la configuration de démarrage et les valeurs administratives du pool. Examiner les avertissements de configuration et erreurs fatales dans les journaux privés, sans publier de données métier.

### Mise à jour des installations existantes

1. Inventorier les services et le frontal selon le [guide VPS](deploiement-vps.md). Identifier séparément les binaires CLI, FPM et les processus actifs. Ne pas utiliser de page publique `phpinfo()`.
2. Docker : suivre la procédure de livraison et de sauvegarde existante, construire avec `docker compose --env-file compose.env build --pull app myabi-web`, puis recréer `app` et `myabi-web` avec les nouvelles images. Le client Python utilise aussi `docker build --pull`. Un redémarrage d’une ancienne image ne met pas PHP à jour. Les commandes ponctuelles doivent utiliser la même nouvelle image.
3. Natif/hébergement géré : installer PHP 8.4.27 ou ultérieur dans la branche 8.4 pour **CLI et FPM**, appliquer `session.upload_progress.enabled=Off` dans la configuration de démarrage/pool, tester puis redémarrer uniquement l’instance FPM myABI. Renouveler tous ses workers et relancer les serveurs de développement éventuels. Un paquet rétroporté exige une preuve du distributeur et une adaptation explicite du contrôle de version ; aucun contournement silencieux n’est prévu.
4. Relever les versions et la configuration effectives, puis rejouer la recette de connexion, téléversement synthétique, traitement et téléchargement. Ne déclarer l’installation corrigée qu’après remplacement des processus vulnérables.

Contrôles Docker après recréation, depuis le répertoire Compose :

```sh
docker compose --env-file compose.env exec -T app php scripts/check-php-runtime.php
docker compose --env-file compose.env exec -T app php-fpm -v
docker compose --env-file compose.env exec -T app php-fpm -tt
docker compose --env-file compose.env exec -T app php-fpm -i | grep session.upload_progress.enabled
docker compose --env-file compose.env run --rm cli php scripts/operations.php health
```

En natif, utiliser les chemins réellement installés : `php8.4 scripts/check-php-runtime.php`, `php-fpm8.4 -v` et `php-fpm8.4 --test --fpm-config /etc/myabi/php-fpm.conf`. Vérifier aussi les valeurs administratives du pool avec `--fpm-config /etc/myabi/php-fpm.conf -tt`. Ces contrôles des exécutables et fichiers doivent accompagner le redémarrage et les sondes HTTP pour vérifier le service actif. Aucun déploiement distant n’est effectué par ces changements du dépôt.

## Authentification et sessions

L’application utilise Laravel Fortify pour le mot de passe, la récupération par e-mail et le second facteur TOTP. L’inscription publique est fermée. Un administrateur crée un compte nominatif dans une organisation et lui attribue plusieurs rôles, chacun avec ses langues. Les rôles et la révocation sont relus en base à chaque requête.

Les invitations sont aléatoires, stockées sous forme de SHA-256, valables 72 heures et consommées dans une transaction. Le compte technique d’import ne peut pas se connecter. Les mots de passe définis à l’installation, par invitation, récupération, depuis le profil ou par un administrateur comportent au minimum 8 caractères par défaut. Cette longueur est réglable avec `AUTH_PASSWORD_MIN_LENGTH=8` dans `.env`, via `config('auth.password_min_length')`. Après modification, appliquer la même procédure de rechargement de configuration que pour le MFA, décrite ci-dessous. Cinq échecs de connexion entraînent un verrouillage de 15 minutes ; les requêtes sont aussi limitées par adresse IP. Les comptes désactivés et leurs auteurs historiques ne sont pas supprimés.

Dans « Administration », un administrateur peut choisir « Activer sans e-mail » à la création, ou ouvrir un compte existant pour l’activer et définir son mot de passe avec confirmation. Cette action active aussi un compte désactivé, sans envoi d’invitation ni validation de l’adresse e-mail ; elle ne marque donc pas l’adresse comme vérifiée. Le mot de passe est à transmettre à l’utilisateur par un canal approprié. L’organisation doit être active et les comptes techniques sont exclus. Les invitations et liens de récupération précédents sont invalidés, toutes les sessions du compte sont révoquées et son verrouillage de connexion est levé. Le second facteur existant est conservé et reste obligatoire selon les rôles lorsque le MFA est activé sur le serveur. L’activation est tracée par `account.activated` avec la méthode `administrator`, et chaque définition du mot de passe par `security.password_set_by_admin`, sans mot de passe ni empreinte. L’action sur un compte existant est limitée à cinq requêtes par minute et par adresse IP, avec le compteur de récupération de mot de passe.

Chaque utilisateur connecté peut modifier son mot de passe depuis « Mon profil », en saisissant son mot de passe actuel, le nouveau mot de passe et sa confirmation. Cette action est limitée à cinq requêtes par minute et par adresse IP, avec le même compteur que la récupération et la configuration TOTP. Elle révoque les autres sessions et les liens de récupération en attente, conserve la session courante sans prolonger sa durée maximale et ne modifie pas le second facteur. L’événement `security.password_changed` est enregistré sans mot de passe ni empreinte dans le journal d’audit.

Le paramètre serveur `MFA_ENABLED` est désactivé par défaut (`false`), y compris lorsqu’il est absent de `.env`. Dans cet état, aucun compte n’a de défi TOTP ni d’obligation de configuration du second facteur. Les routes de configuration et de vérification MFA sont indisponibles ; le profil indique que le MFA est désactivé sur le serveur, sans afficher de clé, QR code, codes de récupération ou boutons MFA. Les secrets et codes existants sont conservés pour une réactivation ultérieure.

Avec `MFA_ENABLED=true`, le TOTP doit être confirmé avant qu’un Validateur, Gestionnaire ou Administrateur puisse accéder au Référentiel. Il reste facultatif pour les autres rôles ; tout compte qui l’a confirmé doit fournir un code TOTP ou de récupération à la connexion. Lors d’une réactivation, une session ouverte sans MFA doit se reconnecter si le compte possède déjà un TOTP confirmé ; un compte soumis à l’obligation et non encore configuré est dirigé vers son profil. Un code de récupération est consommé après utilisation. Les codes TOTP déjà acceptés ne sont pas réutilisables dans leur fenêtre temporelle grâce au fournisseur Fortify et au cache partagé. Les secrets TOTP sont chiffrés avec `APP_KEY` ; conserver cette clé dans les sauvegardes sécurisées. Une invitation en attente et ses secrets dans une tâche de notification sont chiffrés dans la file.

Le réglage se modifie exclusivement dans la configuration du serveur : `.env` en local, `/srv/myabi/shared/.env` en installation native ou `/opt/myabi/deploy/docker/secrets/app.env` avec Docker. Après modification, exécuter `php artisan config:clear` en développement, ou `php artisan config:cache` puis recharger PHP-FPM en déploiement. La [procédure VPS](deploiement-vps.md#réglage-global-du-mfa) précise les commandes Docker. Ne pas vider le cache applicatif : il contient notamment les limites de tentatives et la protection contre la réutilisation des codes TOTP.

La session expire après 60 minutes sans activité et 12 heures depuis la connexion. La connexion persistante est désactivée. L’administrateur peut révoquer toutes les sessions et déverrouiller le compte. Une récupération de mot de passe révoque les sessions précédentes. Utiliser `SESSION_DRIVER=database`, `SESSION_LIFETIME=60`, `SESSION_SECURE_COOKIE=true` avec HTTPS en production. Ne pas vider le cache applicatif pour contourner des limites de tentatives.

## Origine des liens et récupération de compte

Renseigner `APP_URL` avec l’URL HTTPS canonique avant l’ouverture du service. Les liens de récupération sont construits depuis cette configuration, indépendamment de l’en-tête `Host` reçu. En production, les routes web refusent les hôtes différents de celui de `APP_URL` (HTTP 400, sous-domaines compris). Les alias éventuels doivent rediriger vers ce domaine au niveau du frontal. La route de santé `/up` reste accessible à la sonde interne sur l’adresse de bouclage. Après un changement d’URL, reconstruire le cache de configuration et recharger PHP-FPM ; aucune modification de la configuration distante n’est automatique.

Une demande de récupération valide affiche le même message et le même statut, que l’adresse soit connue, inconnue ou déjà soumise au délai de réémission du lien. Les réponses HTML ne créent pas de différence de sac d’erreurs. Le formulaire de nouveau mot de passe renvoie une même erreur de lien invalide pour une adresse inconnue ou un mauvais jeton, et valide les types des champs avant toute recherche de compte. Les contrôles de format de l’adresse, la limite par IP, l’expiration et l’usage unique du lien ainsi que la conservation du second facteur restent appliqués. Cette uniformisation ne garantit pas l’indistinguabilité de tous les temps de réponse SMTP.

## Accès MariaDB séparés

Les triggers de migration rejettent tout `UPDATE` et `DELETE` sur `audit_events`, même via une requête SQL directe. La protection doit être complétée par deux comptes SQL distincts :

- Le compte de migration peut modifier le schéma et créer les triggers. Il est utilisé seulement par la livraison, avec ses secrets propres.
- Le compte applicatif, utilisé par le site, les workers et les tâches planifiées, reçoit les droits nécessaires table par table. Sur `audit_events`, seuls `SELECT` et `INSERT` sont autorisés. Aucun droit `DROP`, `ALTER`, `TRIGGER`, `CREATE`, `SUPER` ou `GRANT OPTION` ne doit lui permettre de contourner cette restriction.

Les droits MariaDB s’additionnent. Un droit `UPDATE` sur `base.*` ne peut pas être neutralisé par une restriction sur `base.audit_events`. Ne pas commencer par un `GRANT ALL ON base.*` au compte applicatif.

Exemple à adapter avec l’hôte, les noms de comptes et le nom de base fournis par l’hébergeur ; exécuter avec le compte responsable de la base :

```sql
GRANT SELECT, INSERT ON `BASE`.`audit_events` TO 'COMPTE_APPLICATION'@'HOTE';
-- Pour chacune des autres tables de l’application :
GRANT SELECT, INSERT, UPDATE, DELETE ON `BASE`.`users` TO 'COMPTE_APPLICATION'@'HOTE';
GRANT SELECT, INSERT, UPDATE, DELETE ON `BASE`.`organizations` TO 'COMPTE_APPLICATION'@'HOTE';
-- Répéter pour sessions, password_reset_tokens, cache, cache_locks, jobs,
-- job_batches, failed_jobs, migrations, myabi_versions, import_batches,
-- import_rows, terms, revisions, proposals, proposal_imports, publications,
-- catalogue_state et les nouvelles tables livrées.
```

Le compte de migration reçoit les droits de schéma nécessaires via le panneau de l’hébergement. Ses identifiants ne doivent pas être placés dans le `.env` utilisé par le serveur web. Les migrations s’exécutent avec une configuration de déploiement temporaire distincte, puis le processus applicatif redémarre avec les identifiants limités.

Après chaque livraison, lancer avec les identifiants **applicatifs** :

```sh
php artisan security:check-audit
```

La commande normalise les identifiants SQL échappés et vérifie les droits accordés avec une liste autorisée. Elle effectue ensuite des essais de lecture, insertion, modification et suppression dans une transaction annulée. Elle refuse les droits globaux autres que `USAGE`, les écritures sur un périmètre de schéma avec joker, les droits destructifs, les délégations, les privilèges inconnus et les rôles hérités non examinés. Le compte doit être dédié à la base de l’application. La commande échoue hors MariaDB et ne modifie aucune entrée d’audit existante. Conserver sa sortie dans le procès-verbal de recette de l’hébergement choisi. Les tests SQLite vérifient les règles applicatives ; ils ne certifient pas les droits du compte SQL de production.

## Résumés quotidiens

`php artisan notifications:daily` prépare un résumé par jour et destinataire. Le profil permet de le désactiver. Le résumé respecte les langues des validateurs, limite les décisions aux propositions de leur auteur et réserve les informations d’import au Gestionnaire. Les propositions en attente depuis au moins 30 jours sont comptées séparément. Les messages sont disponibles en allemand, français et italien et les comptes désactivés sont revérifiés au moment de l’envoi.

Lancer `php artisan notifications:daily --dry-run` pour compter les destinataires sans envoyer ni mettre en file un message. Le planificateur doit être déclenché chaque minute avec `php artisan schedule:run`. Le pilote conserve `QUEUE_CONNECTION=sync` : la livraison a lieu pendant la commande quotidienne, sans worker permanent. Les messages du workflow sont regroupés quotidiennement ; les liens d’invitation et de récupération de compte restent des messages de sécurité demandés explicitement.

La date du dernier résumé n’avance qu’après un envoi SMTP réussi. Un échec est audité sans les détails du transport et fait sortir la commande avec un code d’erreur. Relancer `notifications:daily` réessaie les destinataires en échec et ignore ceux déjà servis le même jour. Les décisions conservées pendant une panne restent incluses lors de la prochaine livraison réussie.

La durée avant anonymisation d’un compte désactivé reste une décision de l’ARGE-ABI ; aucune suppression ou anonymisation automatique fondée sur un délai supposé n’est activée.
