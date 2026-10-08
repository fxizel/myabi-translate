# Comptes, sessions et journal d’audit

L’application utilise Laravel Fortify pour le mot de passe, la récupération par e-mail et le second facteur TOTP. L’inscription publique est fermée. Un administrateur crée un compte nominatif dans une organisation et lui attribue plusieurs rôles, chacun avec ses langues. Les rôles et la révocation sont relus en base à chaque requête.

Les invitations sont aléatoires, stockées sous forme de SHA-256, valables 72 heures et consommées dans une transaction. Le compte technique d’import ne peut pas se connecter. Les mots de passe définis à l’installation, par invitation, récupération, depuis le profil ou par un administrateur comportent au minimum 8 caractères par défaut. Cette longueur est réglable avec `AUTH_PASSWORD_MIN_LENGTH=8` dans `.env`, via `config('auth.password_min_length')`. Après modification, appliquer la même procédure de rechargement de configuration que pour le MFA, décrite ci-dessous. Cinq échecs de connexion entraînent un verrouillage de 15 minutes ; les requêtes sont aussi limitées par adresse IP. Les comptes désactivés et leurs auteurs historiques ne sont pas supprimés.

Dans « Administration », un administrateur peut choisir « Activer sans e-mail » à la création, ou ouvrir un compte existant pour l’activer et définir son mot de passe avec confirmation. Cette action active aussi un compte désactivé, sans envoi d’invitation ni validation de l’adresse e-mail ; elle ne marque donc pas l’adresse comme vérifiée. Le mot de passe est à transmettre à l’utilisateur par un canal approprié. L’organisation doit être active et les comptes techniques sont exclus. Les invitations et liens de récupération précédents sont invalidés, toutes les sessions du compte sont révoquées et son verrouillage de connexion est levé. Le second facteur existant est conservé et reste obligatoire selon les rôles lorsque le MFA est activé sur le serveur. L’activation est tracée par `account.activated` avec la méthode `administrator`, et chaque définition du mot de passe par `security.password_set_by_admin`, sans mot de passe ni empreinte. L’action sur un compte existant est limitée à cinq requêtes par minute et par adresse IP, avec le compteur de récupération de mot de passe.

Chaque utilisateur connecté peut modifier son mot de passe depuis « Mon profil », en saisissant son mot de passe actuel, le nouveau mot de passe et sa confirmation. Cette action est limitée à cinq requêtes par minute et par adresse IP, avec le même compteur que la récupération et la configuration TOTP. Elle révoque les autres sessions et les liens de récupération en attente, conserve la session courante sans prolonger sa durée maximale et ne modifie pas le second facteur. L’événement `security.password_changed` est enregistré sans mot de passe ni empreinte dans le journal d’audit.

Le paramètre serveur `MFA_ENABLED` est désactivé par défaut (`false`), y compris lorsqu’il est absent de `.env`. Dans cet état, aucun compte n’a de défi TOTP ni d’obligation de configuration du second facteur. Les routes de configuration et de vérification MFA sont indisponibles ; le profil indique que le MFA est désactivé sur le serveur, sans afficher de clé, QR code, codes de récupération ou boutons MFA. Les secrets et codes existants sont conservés pour une réactivation ultérieure.

Avec `MFA_ENABLED=true`, le TOTP doit être confirmé avant qu’un Validateur, Gestionnaire ou Administrateur puisse accéder au Référentiel. Il reste facultatif pour les autres rôles ; tout compte qui l’a confirmé doit fournir un code TOTP ou de récupération à la connexion. Lors d’une réactivation, une session ouverte sans MFA doit se reconnecter si le compte possède déjà un TOTP confirmé ; un compte soumis à l’obligation et non encore configuré est dirigé vers son profil. Un code de récupération est consommé après utilisation. Les codes TOTP déjà acceptés ne sont pas réutilisables dans leur fenêtre temporelle grâce au fournisseur Fortify et au cache partagé. Les secrets TOTP sont chiffrés avec `APP_KEY` ; conserver cette clé dans les sauvegardes sécurisées. Une invitation en attente et ses secrets dans une tâche de notification sont chiffrés dans la file.

Le réglage se modifie exclusivement dans la configuration du serveur : `.env` en local, `/srv/myabi/shared/.env` en installation native ou `/opt/myabi/deploy/docker/secrets/app.env` avec Docker. Après modification, exécuter `php artisan config:clear` en développement, ou `php artisan config:cache` puis recharger PHP-FPM en déploiement. La [procédure VPS](deploiement-vps.md#réglage-global-du-mfa) précise les commandes Docker. Ne pas vider le cache applicatif : il contient notamment les limites de tentatives et la protection contre la réutilisation des codes TOTP.

La session expire après 60 minutes sans activité et 12 heures depuis la connexion. La connexion persistante est désactivée. L’administrateur peut révoquer toutes les sessions et déverrouiller le compte. Une récupération de mot de passe révoque les sessions précédentes. Utiliser `SESSION_DRIVER=database`, `SESSION_LIFETIME=60`, `SESSION_SECURE_COOKIE=true` avec HTTPS en production. Ne pas vider le cache applicatif pour contourner des limites de tentatives.

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
