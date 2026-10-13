# Recette locale : sécurité et exploitation

Contrôles effectués le 22 septembre 2026 sur le code en cours de livraison, avec PHP 8.4.25, SQLite et MariaDB 11.4 local. Ce document complète le procès-verbal général ; il ne vaut pas validation de l’hébergement alwaysdata ni d’un déploiement distant.

## Preuves obtenues

| Critère | Résultat local | Preuve et limite |
| --- | --- | --- |
| T-17 — droits serveur | Vérifié sur les scénarios de sécurité | `AuthSecurityTest` vérifie les rôles indépendants, langues, organisations, changements dès la requête suivante et refus d’administration. Les accès aux termes, imports, rapports et publications relèvent également des tests métier recensés dans la recette générale. |
| T-18 — authentification et sessions | Vérifié localement | 16 tests, 135 assertions dans `AuthSecurityTest` : invitation 72 h à usage unique, mot de passe de 12 caractères, réinitialisation et révocation, TOTP obligatoire, verrouillage après cinq échecs, inactivité 60 min, maximum 12 h. Une session révoquée ne peut plus modifier sa langue ; le choix de langue invité reste disponible. |
| T-19 — secrets et fichiers | Vérifié pour le code et le paquet ; hébergement à vérifier | Scan des chemins Git suivis et nouveaux : aucun `.env` réel, `.runtime`, `vendor` ou secret privé destiné au commit. Les trois correspondances examinées sont la clé et le mot de passe synthétiques de CI, et un exemple documentaire. Le paquet exclut données, stockage privé, environnements et dépendances de test. HTTPS, racine web `current/public` et permissions effectives restent à contrôler sur le compte cible. |
| T-20 — audit en ajout seul | Vérifié sur MariaDB local | Triggers testés contre modification et suppression. Dans une base jetable, `security:check-audit` réussit avec le compte applicatif limité, échoue après ajout d’un droit `UPDATE` global au schéma avec nom échappé, puis réussit après révocation. Aucun événement de la sonde ne subsiste. 22 cas unitaires vérifient la normalisation et le refus des privilèges larges, DDL, rôles hérités et délégation. Les privilèges réels de production restent à vérifier. |
| T-21 — livraison sécurisée | Construction locale vérifiée ; livraison distante à vérifier | Installation isolée depuis `composer.lock`, sans dépendances de développement ; inspecteur d’archive et commandes Laravel de découverte, cache des routes et vues exécutés. Le workflow exige la publication d’une Release, les tests préalables, une clé dédiée et une empreinte SSH configurée ; `DEPLOY_ENABLED` doit être explicitement activé. Aucun déploiement distant n’a été lancé. |
| T-22 — verrou, quota et retour | Verrou et maintenance vérifiés localement ; scénario distant à vérifier | Les scripts de déploiement, sauvegarde et restauration utilisent le même `operations.lock` exclusif. La restauration complète a maintenu ce verrou et la maintenance locale ; le site a été rouvert par le `finally`. Les changements d’organisation sont refusés pendant le traitement lourd et invalident l’analyse d’import après succès. Le contrôle de quota compte archives, versions, base et marge ; le retour automatique exige des migrations identiques. La syntaxe Bash passe. Le secret de maintenance est masqué et son absence a été vérifiée dans la sortie réelle de Laravel. |
| T-23 — restauration cohérente | Vérifié sur le catalogue local complet ; hébergement à vérifier | Sauvegarde et restauration effectives de `myabi` vers la base neuve `myabi_full_restore`, avec les fichiers dans `.runtime/full-restore/private`. Les 23 tables ont des comptes et sommes de contrôle étendues identiques ; les 85 fichiers privés ont les mêmes empreintes. Les sept formats, les 14 lots appliqués et la publication 1 restent cohérents. Les huit triggers correspondent exactement à un schéma vide migré depuis le code courant. Modification/suppression de l’audit et seconde restauration sur une base non vide sont refusées. Aucun basculement du site vers la copie n’a été effectué. |

Le premier essai synthétique représente 2 114 octets et deux membres vérifiés (dump SQL et fichier privé). L’empreinte SHA-256 du fichier restauré est `fd4455a880951db2b2a37d8776b44678abfea4551273ec26a0371c525abb7262`. Les sept tests Python couvrent aussi le flux non repositionnable, la corruption, les chemins interdits, les caches exclus et le refus d’écrasement.

La campagne complète a ensuite été exécutée sur l’instance **locale de recette**, construite avec les sept CSV fournis dans le dépôt : `APP_ENV=local`, application sur `127.0.0.1:8000`, MariaDB sur `127.0.0.1:33077`. Elle ne concernait aucun service de production ni transfert hors du poste. Les tables source sont restées inchangées ; le compte de restauration était limité à la destination, avec `SET USER` temporaire pour conserver les definers connus puis révocation vérifiée après import. La source contenait bien 14 lots appliqués ; l’interruption d’un autre benchmark isolé ne concernait pas cette base.

| Mesure de restauration complète | Résultat |
| --- | ---: |
| Termes source et destination | 581 141 |
| Propositions source et destination | 1 068 551 |
| Présences `import_rows` source et destination | 1 162 284 |
| Tables comparées par compte et `CHECKSUM TABLE EXTENDED` | 23 |
| Triggers comparés aux migrations courantes | 8 |
| Membres vérifiés dans la sauvegarde | 86, dont 85 fichiers privés |
| Taille de l’archive de sauvegarde | 304 611 614 octets |
| Création de la sauvegarde | 20,60 s |
| Restauration SQL et fichiers | 473,93 s |
| Campagne avec contrôles avant/après | 555,17 s |

La publication 1 contient deux destinataires : les deux CSV, deux ZIP, deux manifestes de révisions, deux catalogues et le manifeste principal sont identiques. Le contenu des CSV et manifestes à l’intérieur des ZIP a également été vérifié. Les contrôles d’archive précèdent l’import ; la restauration refuse toute destination déjà remplie. Les états de maintenance initiaux sont respectés et le site local a été vérifié rouvert après succès.

L’allocation physique du seul schéma source, mesurée par `du -sB1`, est de **875 507 712 octets** ; celle de la copie reconstruite est de **782 172 160 octets**, avec les mêmes données et sommes de contrôle. Cet écart de 93 335 552 octets montre l’effet de la reconstruction sur les pages allouées ; il ne garantit pas la croissance future ni la méthode de quota de l’hébergeur. Le scénario global de capacité, incluant fichiers et livraisons, reste celui du procès-verbal général.

Le dernier paquet local vérifié représente **5 921 005 octets compressés** et **33 177 855 octets décompressés**, avec **9 319 membres** et **97 dépendances de production**. Son SHA-256 est `562624d47eebc45396a278a5198954a86612ed84ba9cdc1fc5e29b8421d9a2e0`. Il inclut la licence `proprietary`, `ext-pdo_mysql`, `public/.user.ini`, les migrations 220000/230000/240000, les corrections `ImportService`/`ImportController`, le contrôle de portée de `TermController` et le suivi des brouillons de `public/js/app.js`. Les empreintes de tous les fichiers source livrés sont comparées aux fichiers courants. `AtomicFileWriter` est effectivement chargé par le `vendor/autoload.php` du paquet avec classmap autoritaire, et sa réflexion résout le fichier extrait attendu.

Les assertions excluent explicitement `.env`, `.runtime`, `data`, `storage`, `tests`, `maquette`, les ressources CSS/JavaScript de développement, les caches Python, PHPUnit, Mockery, Faker, Pint et Collision ; les ressources publiques nécessaires à l’application sont présentes. Des fixtures `.pyc`/`.pyo` placées dans la construction sont absentes de l’archive. Cette reconstruction finale est exécutée dans un nouveau dossier isolé avec `COMPOSER_DISABLE_NETWORK=1` : aucune dépendance n’est installée, modifiée ou retirée, et le chargement automatique est régénéré après copie des sources. Après extraction dans un dossier neuf, `package:discover`, `route:cache` et `view:cache` réussissent sans dépendances de développement, dans un environnement synthétique SQLite en mémoire. Il s’agit d’un instantané de vérification local ; la Release définitive est reconstruite depuis son commit par la CI.

`composer validate --strict` et `composer check-platform-reqs --lock --no-dev` réussissent. L’audit officiel Packagist, exécuté sur l’ensemble verrouillé avec les dépendances de test, retourne zéro alerte de sécurité et zéro paquet abandonné à la date du contrôle. La CI exécute désormais ces contrôles à chaque passage. Un audit ponctuel ne préjuge pas des avis publiés ultérieurement.

Les six tests du résumé quotidien vérifient les périmètres, le désabonnement, l’absence de double envoi quotidien, l’essai à blanc et la reprise après panne SMTP. Le marqueur d’envoi n’est avancé qu’après réussite effective de l’envoi synchrone ; après échec, les décisions anciennes restent incluses. L’émission ou le renouvellement d’un jeton d’invitation conserve sa trace d’audit même si son envoi échoue. Tous les courriels de test ont été interceptés ou simulés ; aucune notification réelle n’a été envoyée.

## Conditions externes avant activation du pilote

- Confirmer PHP web/CLI, MariaDB, extensions, HTTPS, racine web privée et refus d’accès HTTP aux secrets et fichiers métier sur le compte gratuit choisi.
- Configurer et tester séparément les comptes SQL applicatif, migration et sauvegarde ; exécuter `security:check-audit` avec le compte réellement utilisé par le site.
- Désigner le stockage durable de l’organisation, configurer le hook de sauvegarde, les clés SSH dédiées et les empreintes vérifiées. Le hook doit confirmer une copie indépendante vérifiée avant toute migration.
- Tester une Release complète, le verrou face à un import actif, les refus de quota, les contrôles de santé et un retour compatible avec le schéma. Le workflow GitHub et le compte alwaysdata n’ont pas été exécutés à distance pendant cette recette locale.
- Rejouer la restauration du catalogue complet depuis la copie indépendante désignée, avec les accès et contraintes de l’hébergement cible. La restauration locale complète est vérifiée ; elle ne certifie pas les permissions, le débit ou les capacités de ce futur environnement.
- Vérifier les envois SMTP et les tâches planifiées du compte, l’heure suisse, les alertes d’échec et les responsabilités du titulaire et de son suppléant.
- Confirmer les quotas mesurés et la croissance dans la recette générale, le maintien à 0 €, les limites GitHub, la classification des données et le délai d’anonymisation décidé par l’ARGE-ABI.

Les commandes reproductibles et la procédure d’exploitation sont décrites dans [operations.md](operations.md) et [security-deployment.md](security-deployment.md). Les preuves détaillées locales se trouvent dans `.runtime/full-restore/result.json` et `.runtime/production-post-audit-v2-result.json` ; le paquet inspecté est `.runtime/production-post-audit-v2.tar.gz`. Ces diagnostics et la sauvegarde restent exclus de Git ; aucune sauvegarde métier n’est placée dans un artefact GitHub.
