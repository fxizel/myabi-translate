# Mise en œuvre du référentiel commun

Date : 13 octobre 2026. Contrat : [passation](passation-termes-communs.md), [spécifications fonctionnelles](../../specifications-fonctionnelles-referentiel-traductions-arge-abi.md) et [formats DEVCONF](formats-devconf.md).

## Découpage réalisé

| Lot | Résultat |
| --- | --- |
| Contrat et interface | Catalogue commun ; suppression du propriétaire, des filtres de portée et de la route de changement de périmètre ; documentation et interface DE/FR/IT alignées. |
| Identité, imports et workflow | Identité par type et composantes exactes ; comparaison des candidats après empreinte ; propositions concurrentes et provenances conservées ; droits par rôle, langue et activation. |
| Export et publication | Export de tous les termes courants du type avec repli importé ; publication des termes au moins partiellement validés avec cellules cibles non validées vides ; sources techniques multiples et ordre stable. |
| Diagnostic et transition | Diagnostic privé à blanc ; conservation de la première fiche compatible ; propositions de reprise pour valeurs divergentes ; lignées historiques, décisions et publications figées préservées. |
| Contrôles | Tests synthétiques du cycle métier, des sept formats, des reprises et des interruptions ; installation neuve et répétition de mise à niveau sur copie privée. |

Les groupes et campagnes existants ont été adaptés au catalogue commun. Ce chantier n'ajoute pas de nouvelles fonctions de campagne.

## Diagnostic privé

Avant de mettre à niveau une installation contenant des termes, lancer depuis le nouveau code :

```text
php artisan referentiel:common-terms --diagnose
```

La commande ne modifie pas les termes. Une table temporaire propre à la connexion indexe les identités recalculées, y compris lorsque l'empreinte historique manque. La comparaison finale porte sur le type et les composantes exactes de la clé, avec leur casse, leurs espaces et leurs composantes vides. Les lectures du catalogue et de la liste des groupes candidats sont paginées par lots de 300 ; chaque groupe est ensuite comparé séparément.

Le rapport JSONL est écrit dans `storage/app/private/reports`, avec un nom aléatoire. Il inventorie les candidats, divergences, références et liens : présences, propositions et imports associés, révisions, restaurations, remplacements, audit, publications et campagnes. Les incompatibilités sont explicitement signalées. La sortie du terminal contient seulement des compteurs ; les clés et valeurs restent dans le rapport privé. Un groupe bloqué fait retourner un code non nul.

Ne pas copier ce rapport dans `public`, Git ou une livraison. Les permissions Unix du stockage privé s'appliquent ; sous Windows, les ACL du répertoire doivent déjà être configurées.

## Bascule

1. Sauvegarder de manière cohérente la base et le stockage privé, puis répéter la mise à niveau sur leur copie. Inventorier le nombre de termes, les doublons et les opérations en attente. Une base vide suit directement les migrations.
2. Suspendre les accès d'écriture pendant la mise à niveau. Terminer les imports en cours d'application, publications en construction et validations en cours. Le traitement refuse ces états inachevés et prend le verrou exclusif des opérations métier.
3. Installer l'ensemble cohérent du code et lancer `php artisan migrate --force`. Ne pas servir le nouveau code sur un schéma partiellement migré. La migration ajoute les tables de correspondance, diagnostique les doublons avant toute consolidation et refuse les groupes ambigus.
4. Relancer la même migration après une interruption : chaque groupe compatible est transactionnel et sa correspondance est unique. L'inventaire initial et les compteurs finaux restent enregistrés dans `common_term_transition`. Les opérations de schéma sont conditionnelles à l'existence des colonnes, tables et index, pour reprendre après un DDL partiel.
5. Vérifier les compteurs, les liens et les fichiers figés. Les aperçus d'import et de validation antérieurs sont invalidés, la génération du catalogue augmente une seule fois et les imports analysés repartent en analyse depuis leur original intact.
6. Rouvrir les écritures seulement après les contrôles. Aucun déploiement sur une infrastructure existante n'est réalisé par ce chantier.

La fiche de base est celle dont la date de création est la première, avec départage par identifiant. Son identifiant, ses traductions validées et sa référence restent la base commune. Un groupe exige des références et un sens compatibles, des sources vérifiables, des révisions concordantes et des liens de groupe non contradictoires. Les mises à jour de référence par les imports ultérieurs conservent leur comportement existant.

Les valeurs validées divergentes des autres fiches deviennent des propositions en attente. Une proposition identique déjà en attente est réutilisée et ses provenances sont complétées. Les origines explicitement nulles et les contributions manuelles restent telles quelles ; l'organisation actuelle de l'auteur ne remplace jamais l'origine historique. Ces opérations ne valident aucune valeur.

## Conservation des historiques

`terms.consolidated_into_id` distingue les anciennes lignées du catalogue courant. Les anciennes fiches restent présentes pour leurs révisions et liens historiques ; elles sont exclues des requêtes courantes et des projections de recherche et d'état. `term_consolidations` conserve les correspondances et les métadonnées de reprise ; `term_legacy_scopes` archive les anciennes propriétés avant suppression des colonnes actives. Les anciennes empreintes des présences d'import ne sont pas recalculées.

Les propositions sont rattachées à la fiche commune avec leur `legacy_term_id`. Leurs identifiants, auteurs, origines, décisions, imports et liens de restauration ou remplacement sont préservés. `common_term_recovered_values` trace les propositions de reprise vers l'ancienne fiche et, lorsqu'elle est connue, la décision d'origine. Les numéros et identifiants des révisions ne sont ni fusionnés ni renumérotés. La fiche commune permet de consulter séparément chaque lignée et les liens vers un ancien terme redirigent vers elle.

Les campagnes terminées conservent leurs participations et confirmations historiques. Les contextes des propositions sont actualisés seulement lorsque l'ancienne empreinte correspond exactement au contexte vérifié ; un contexte périmé reste périmé. Les confirmations des campagnes ouvertes sont conservées lorsque leur contexte et leur valeur concordent, sinon retournées pour examen avec une trace de leur ancien état.

Les anciens fichiers et manifestes de publication ne sont jamais régénérés. Les nouveaux manifestes décrivent toutes les sources techniques et, pour chaque ligne publiée, le terme, sa révision, l'import et sa position. Les exports simples conservent dans leur audit les sources utilisées et la couverture ; leur CSV demeure temporaire.

La migration retire `terms.organization_id`, `scope_unconfirmed`, `scope_overridden` et leurs index. L'empreinte courante `identity_hash` devient indépendante de l'organisation et reste non unique : une empreinte ne remplace jamais la comparaison exacte. Les organisations restent liées aux comptes, propositions, imports, audit et destinataires.

Un `migrate:rollback` ne peut pas annuler une reprise contenant des termes : restaurer la sauvegarde cohérente avec le code correspondant. Le rollback d'une installation encore vide reste possible pour les tests de schéma.

## Validation et limites de la recette locale

Les tests couvrent notamment deux organisations partageant une identité, la conservation des provenances, les droits par langue, les clés exactes et collisions, l'export couvrant plusieurs sources, les publications partielles des sept formats, la représentation brute CSV, les références altérées, les lignées historiques et les interruptions de reprise.

Une copie cohérente de la base SQLite de recette locale a été vérifiée : elle contenait zéro terme, révision, proposition, import ou publication. Son diagnostic et sa mise à niveau réussissent. Les reprises de données sont donc exercées sur des bases synthétiques isolées ; aucun regroupement de données locales n'est effectué.

Résultats du contrôle final : **411 tests PHP réussis, 11 209 assertions, 13 tests ignorés selon leurs prérequis d'environnement ; 28 tests JavaScript réussis ; Pint et `git diff --check` réussis.** Le scénario de 1 000 + 200 termes vérifie 1 200 lignes uniques, un seul en-tête, un ordre stable, les validations et replis communs, et des fichiers identiques pour deux destinataires. Les tests de transition couvrent également les index partiellement créés, les confirmations historiques et la confidentialité des erreurs SQL.

```text
php artisan test
node --test tests/JavaScript/*.test.cjs
php scripts/test_devconf_roundtrip.php --validate-semantics
```

Le runtime de test est PHP 8.4 / Node 24. Lors du premier contrôle, la connexion principale locale MariaDB n'était pas disponible ; les validations complémentaires ci-dessous utilisent une instance de test distincte. Les tests exigeant des permissions Unix restent ignorés sous Windows.

**Complément du 14 octobre 2026 :** après ajout local des sept originaux dans `data/imports`, le contrôle `php scripts/test_devconf_roundtrip.php --validate-semantics` réussit sur les sept formats : **581 142 enregistrements, 108 297 018 octets**, tous restitués à l'identique octet pour octet, sans cellule modifiée. Les empreintes des originaux avant et après le contrôle sont identiques. Les sept CSV sont ignorés par Git, aucun n'est suivi, et les livraisons ainsi que le contexte Docker excluent `data`. Le rapport détaillé reste local dans `.runtime`, hors Git et des livraisons ; seules les statistiques agrégées sont consignées ici.

## Contrôles MariaDB isolés du 14 octobre 2026

Une instance portable **MariaDB 11.4.8** a été initialisée sous `.runtime`, avec un répertoire de données neuf, un port libre sur la boucle locale, des identifiants de test aléatoires et deux schémas jetables distincts. Aucun service Windows n'est installé. Le répertoire effectif du serveur est vérifié avant l'exécution ; la connexion applicative et son fichier `.env` ne sont pas modifiés. Les CSV réels ne sont ni copiés ni importés dans ces bases : toutes les fixtures sont synthétiques.

Les tests effectuant des changements de schéma exigent l'environnement `testing`, l'indicateur explicite `MYABI_ISOLATED_TESTS=1`, un nom de base préfixé `myabi_test_` et un hôte local avant toute remise à neuf. Leur nettoyage utilise un schéma neuf au lieu d'une migration inverse métier. Les autres tests conservent leur isolation transactionnelle. La configuration, les identifiants, l'exécutable et les rapports détaillés restent privés et ignorés sous `.runtime`.

Les contrôles ont corrigé deux défauts d'installation et de transaction : le nom automatique d'un index de campagne excédait la limite MariaDB ; le diagnostic créait son index temporaire par une instruction séparée et supprimait sa table sans le mot `TEMPORARY`. L'index de campagne porte maintenant un nom court explicite. Pour MariaDB, l'index du diagnostic est déclaré dans `CREATE TEMPORARY TABLE` et la suppression utilise `DROP TEMPORARY TABLE`. Un test sentinelle prouve que le diagnostic ne valide pas la transaction appelante. Ces distinctions correspondent aux [règles de commits implicites MariaDB](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit).

Les neuf scénarios de concurrence entre connexions ont effectivement été exécutés, avec vérification des verrous de lignes, de l'atomicité sous privilèges SQL restreints et des états relus après un verrou. Les scénarios de transition contrôlent également une interruption après un groupe validé, une reconnexion, des colonnes et index partiellement modifiés par DDL, puis une reprise répétée.

Une seconde base teste la mise à niveau réelle depuis toutes les migrations historiques jusqu'à la nouvelle transition. **39 vérifications réussissent** : quatre fiches anciennes deviennent trois termes courants et une lignée archivée ; deux cellules divergentes sont reprises ; la première fiche et ses valeurs, les décisions, révisions, audits, provenances multiples, empreintes des présences et fichiers figés restent préservés. Les huit triggers et les index attendus sont présents, la génération augmente une seule fois et deux nouvelles exécutions de la migration sont identiques.

La fixture de métriques de campagne calcule désormais sa plage d'identifiants à partir de la première fiche réellement créée, sans supposer une remise à 1 des compteurs après un rollback. Les scénarios de campagne passent sur les deux moteurs après cette correction. La régression SQLite complète vérifie **412 tests et 11 215 assertions**, avec 14 exclusions correspondant aux prérequis MariaDB ou Unix.

**Bilan MariaDB final : 420 tests réussis, 11 286 assertions, aucune erreur**, en 6 min 41 s et avec un pic mémoire PHP de 98 Mio. Les six exclusions concernent quatre tests de permissions Unix sous Windows et deux tests spécifiques à SQLite, exécutés dans la suite SQLite. Les neuf tests de concurrence MariaDB ne sont plus ignorés. Pint et `git diff --check` réussissent. L'instance isolée est arrêtée après les contrôles ; les schémas, fixtures et rapports restent uniquement locaux et ignorés dans `.runtime`.

Commande de la suite MariaDB avec sa configuration privée vérifiée :

```text
php vendor/phpunit/phpunit/phpunit --configuration .runtime/mariadb-tests/phpunit.xml
```
