# Procès-verbal de recette du lot 1

Recette locale des 21–22 septembre 2026. PHP 8.4.25, Laravel 13.32.0, MariaDB 11.4 dans un conteneur local, Windows. L'hébergement de production reste PHP/Blade/MariaDB géré : le conteneur sert uniquement à la recette.

Le lot 1 est implémenté. Les fonctions des lots 2 et 3 restent les lots ultérieurs définis dans les spécifications : OIDC, glossaire, commentaires, cohérence, mémoire de traduction, XLSX, annulation d'import et fonctions optionnelles. Les critères dépendant d'alwaysdata ou de myABI ne sont pas déclarés vérifiés par des essais locaux.

## Preuves métier

- Les sept originaux, 581 142 enregistrements et 108 297 018 octets, ont été analysés intégralement. Restitution brute identique octet pour octet, avec conservation CP1252, séparateurs, guillemets et retours de ligne. Commande : `php scripts/test_devconf_roundtrip.php --validate-semantics`.
- Deux imports complets successifs ont produit 581 141 identités de terme, 1 162 284 présences, 1 068 549 propositions et 581 141 révisions avant les modifications de recette. Le réimport inchangé ne duplique ni les propositions ni les révisions. Les deux occurrences de l'identité Incident code ambiguë restent conservées ; elles ne sont pas fusionnées en une valeur publiable.
- Les interruptions d'analyse, d'application et de publication sont couvertes par les tests. Une application incomplète est annulée dans MariaDB ; le suivi privé reste lisible hors transaction et une reprise ne produit pas de doublon.
- La validation initiale analyse puis traite tout le lot par étapes de 300 au maximum. L'aperçu est asynchrone, sans validation ; sa confirmation vérifie la génération examinée. Les cas de concurrence, divergence, avertissement, obsolescence et auto-validation non autorisée sont exclus. Une reprise repart du dernier point validé.
- Le critère des 500 propositions dans une même session est couvert par un scénario serveur dédié : cinq pages de 100, retour à la même file filtrée après chaque décision et session maintenue pendant 25 minutes simulées. Les 500 valeurs validées, leurs révisions et les 500 décisions d'audit sont contrôlées. Ce test de 551 assertions réussit sur SQLite (5,60 s) et MariaDB (18,20 s) ; il ne mesure pas le temps de lecture d'un Validateur ni la latence sur Free.
- La recette navigateur a vérifié la connexion TOTP, l'envoi d'une proposition, le refus d'auto-validation, la dérogation explicite du Gestionnaire auditée et la comparaison r1/r2. Les erreurs préservent les saisies. Le transfert multipart HTTP réel du fichier Incident code de 71 838 828 octets a réussi en 48,032 s, via connexion, TOTP et CSRF ordinaires, dans une base et un stockage isolés avec le quota de 1 Gio. L'import reçu en attente, sa taille et son SHA-256 ont été vérifiés, puis son annulation et la déconnexion ont été effectuées par les routes autorisées ; le catalogue est resté inchangé. L'automatisation du sélecteur de fichiers du navigateur n'a pas fourni de résultat exploitable : cette manipulation reste à recontrôler manuellement sur le pilote.
- Une publication mRic complète a été produite pour ARGE-ABI et VD, 38 562 lignes par destinataire, après 100 validations FR. Les 100 valeurs, les cellules techniques, les fins de ligne, les ZIP et les manifestes ont été vérifiés. Après une nouvelle validation, les octets des publications restent identiques. Les douze contrôles d'accès au téléchargement passent. Génération : 18,025 s ; mémoire PHP maximale : 30 Mio.
- Une sauvegarde complète de 304 611 614 octets a été restaurée dans une base MariaDB et un répertoire privé nouveaux, sans remplacer la source. Les comptes et sommes de contrôle des 23 tables, les 8 déclencheurs SQL et les empreintes des 85 fichiers privés concordent, y compris les sept originaux et la publication pour deux destinataires. Sauvegarde : 20,60 s ; restauration : 473,93 s ; contrôles compris : 555,18 s. Les modifications et suppressions du journal d'audit restent refusées et une seconde restauration vers la destination non vide est refusée. Le site local a été rouvert et le catalogue vérifié dans le navigateur.
- L'ordre de référence des sources suit leur application au catalogue, indépendamment de l'ordre de téléversement. Les portées explicitement corrigées et les identités exactes restent stables lors des réimports.

Les dernières suites complètes réussissent : **156 tests et 1 783 assertions sur SQLite** en 196,67 s ; **155 tests et 1 751 assertions sur MariaDB** en 222,26 s. Un test de reprise DDL est exécuté uniquement sur SQLite : MariaDB valide implicitement les transactions lors des changements de schéma, ce qui est incompatible avec l'isolation `RefreshDatabase` de ce test. La migration a également été appliquée et mesurée sur les deux catalogues MariaDB complets, sans changement de données métier. Les sept tests Python d'exploitation et les treize tests JavaScript réussissent également. Les commandes sont `php artisan test`, `python3 -m unittest discover -s tests/Operations -p 'test_*.py'` et `node --test tests/JavaScript/drafts.test.cjs`. Les tests utilisent des données synthétiques, des bases et répertoires de stockage isolés. Les scripts `referentiel:benchmark`, `benchmark_catalogue.php` et `benchmark_publication.php` sont réservés à un environnement local ou de test autorisé ; ils ne publient aucune valeur métier dans leur sortie.

Les corrections du suivi de progression font partie de ces suites. Elles avaient aussi été vérifiées séparément sur les deux moteurs : 13 tests et 116 assertions pour la reprise et l'écriture atomique, puis un test supplémentaire de 7 assertions pour un échec de progression après `COMMIT`. Ce dernier confirme qu'un import déjà appliqué ne peut plus être rétrogradé en échec : son état SQL fait foi et l'API affiche 100 %, même si le dernier fichier de progression est indisponible.

Une seconde revue a reproduit la perte d'avertissement lorsque plusieurs lignes contenaient un brouillon et qu'une seule était envoyée. Le suivi JavaScript distingue maintenant chaque éditeur et les valeurs réellement soumises. Treize tests d'événements vérifient notamment l'envoi partiel, le lot partiellement sélectionné, les confirmations et navigations annulées, les valeurs restaurées et le retour à la valeur initiale. Ils passent avec `node --test tests/JavaScript/drafts.test.cjs` et sont intégrés à la CI ; ils n'utilisent aucune base ni dépendance npm. Node reste réservé à la vérification hors production.

Une page de recette isolée a également été ouverte dans le navigateur : avec deux brouillons, l'envoi de la première ligne conserve la page et les deux valeurs ; après remise à vide du second brouillon, l'envoi de la première ligne aboutit. Le navigateur intégré n'a pas exposé le dialogue natif `beforeunload` à l'automatisation : l'observation porte sur le blocage puis l'aboutissement de la navigation, pas sur le texte du dialogue. Aucune proposition n'a été créée dans la base principale ; le serveur et l'onglet temporaires ont été arrêtés.

## Performances et capacité

Le premier essai sans optimisation occupait 1 586 718 088 octets après deux imports ; le quota gratuit n'était donc pas respecté. Cet essai a motivé la compression InnoDB sans perte, le stockage ASCII des empreintes SHA-256 et la suppression d'index inutilisés. Aucun original, historique ou publication n'a été purgé.

La recherche utilise une projection SQL compacte et transactionnelle, y compris pour les mots courts et les accents. Les indicateurs d'état par langue utilisent une seconde projection, pour éviter de lire les gros documents JSON à chaque filtre. Les mesures de pages incluent le contrôleur et le rendu Blade ; elles ne sont pas un test de latence Internet.

Après les derniers index, le catalogue, les recherches et les six filtres d'état ont été mesurés pour Gestionnaire et Lecteur. Les pages vont de 0,038 à 2,014 s : la recherche texte reste à la limite de la cible de deux secondes, qui n'est donc pas garantie. Le filtre « proposé » passe de plus de six secondes à 0,73–0,83 s. Le tableau de bord prend 0,962 s, la validation 0,309 s, les imports 0,768 s et les publications 0,028 s. La mémoire maximale observée pour ces pages est de 30 Mio.

Après la migration 240000 décrite ci-dessous, le même contrôle Gestionnaire a été rejoué : catalogue 0,455 s, préfixe 0,045 s, recherche texte 2,049 s, autres recherches et filtres 0,667–1,622 s, pic PHP 28 Mio. La cible de deux secondes reste donc à vérifier sur l'hébergement ; elle n'est pas déclarée atteinte pour toutes les recherches locales.

Une régression du réimport sur des statistiques MariaDB neuves a été reproduite : le plan SQL parcourait une source entière à chaque lot. La requête est maintenant bornée aux 300 empreintes du lot ; mesure du même résultat : 1,940 s avant correction, 0,0179 s après. Une erreur Windows de remplacement du fichier de progression a également provoqué un rollback réel du dernier import. Le remplacement atomique réessaie désormais les blocages de lecture temporaires, avec une limite d'une seconde ; un échec permanent conserve l'ancien point de progression et reste signalé. Les 13 tests ciblés de reprise et d'écriture atomique passent sur SQLite et MariaDB après cette correction. Un verrou Windows réel de 300 ms a aussi été reproduit : l'ancien fichier reste intact pendant le verrou et son remplacement réussit après libération. Les attributs Git désactivent toute conversion de fin de ligne des originaux DEVCONF.

Le premier passage du catalogue complet dans une base neuve compressée prend **1 146,746 s (19 min 06,746 s)**. Le second totalise **1 313,917 s (21 min 53,917 s)** de traitements réussis, analyse et application comprises. Le dernier fichier du second passage a dû être réanalysé et réappliqué après le rollback Windows décrit ci-dessus : 255,138 s d'analyse et 508,833 s d'application, avec un pic PHP de 58 Mio. Le total du second passage additionne les six premiers traitements réussis et cette reprise ; il exclut l'essai interrompu, le temps de correction et la remise à zéro du seul staging non appliqué (38,744 s). Ce n'est donc pas la preuve d'un second passage ininterrompu en moins de trente minutes. Les 14 imports sont appliqués ; chacune des sept paires de fichiers conserve exactement les mêmes liens vers les termes. La reprise ne crée aucune proposition ou révision supplémentaire.

### Relevé de capacité du catalogue de recette avant la migration 240000

La base principale contient les deux imports complets, les modifications de recette et la publication pour deux organisations. Après les migrations jusqu'à 230000, le calcul applicatif local donne 967 021 369 octets (90,06 %), dont 770 351 104 octets de données/index SQL et 196 670 265 octets de fichiers ; cette mesure inclut les dépendances de développement locales.

En remplaçant le code local par le paquet de production mesuré, l'estimation devient :

| Élément | Octets |
| --- | ---: |
| Données et index SQL (`information_schema`) | 770 351 104 |
| Stockage applicatif, originaux et publications | 142 818 386 |
| Code et dépendances de production | 33 174 808 |
| Total avec une version de code | 946 344 298 |
| Livraison suivante : deuxième code, archive et 20 Mio de marge | 60 065 290 |
| Total estimé pendant livraison | 1 006 409 588 |

Cette estimation laisse environ 64 Mio sur 1 Gio, avant les autres usages du compte. Elle ne suffit pas à valider le fournisseur : les blocs réellement alloués au répertoire MariaDB local occupent 875 507 712 octets, davantage que les statistiques des tables. Avec cette mesure physique conservatrice, le même scénario de livraison atteindrait 1 111 566 196 octets, soit environ 36 Mio au-dessus du quota. La copie intégralement restaurée, avec comptes et sommes de contrôle identiques, occupe 782 172 160 octets SQL physiques : le scénario redescendrait à 1 018 230 644 octets. La reconstruction modifie donc fortement l'allocation ; elle ne prouve ni une marge durable ni le quota du fournisseur. Aucun basculement vers cette copie n'a été effectué.

Le quota local de 1 073 741 824 octets est une hypothèse de recette (1 Gio). L'offre annoncée « 1 Go » doit être confirmée en octets sur le compte cible ; il ne faut pas l'assimiler sans contrôle à 1 Gio. Comparer les relevés ci-dessus au compteur réellement facturé, partagé avec les autres fichiers et les mails, reste indispensable. Le profil Free n'est pas validé à ce stade.

Le refus d'un troisième téléversement Incident code sur cette base a aussi été constaté : la réserve préalable de 154 163 416 octets ne tient plus. Il n'est donc pas possible de promettre une semaine supplémentaire d'import complet et de publication. Aucun quota n'a été relevé et aucune donnée métier n'a été purgée pour obtenir les résultats retenus.

### Contre-mesure sur la base neuve du benchmark avant la migration 240000

La base distincte ayant servi aux deux passages chronométrés conserve une allocation plus élevée après la reprise. Les trois migrations finales ont été appliquées dans son stockage isolé en 30,426 s. Elle contient les mêmes 581 141 termes, 1 068 549 propositions, 581 141 révisions et 1 162 284 présences. Son calcul applicatif atteint **1 019 754 116 octets (94,97 % de 1 Gio)**, dont 857 604 096 octets SQL. Ce relevé comprend les dépendances de développement mais exclut la publication de la base principale.

En utilisant le code de production mesuré, les originaux isolés (108 297 130 octets, suivi compris), et en ajoutant les fichiers de la publication réellement produite (34 049 624 octets), le scénario représentatif atteint 1 033 125 658 octets. Avec la livraison suivante et sa marge, il atteint **1 093 190 948 octets**, soit **19 449 124 octets au-dessus de 1 Gio**, avant les autres usages du compte. Il s'agit d'une projection explicite à partir de deux relevés locaux, pas d'une publication supplémentaire exécutée dans la base du benchmark. Ce scénario de capacité n'est pas satisfait localement.

Le répertoire SQL de cette base occupe physiquement **928 780 288 octets alloués** (`du -s -B1`), soit 71 176 192 octets de plus que les statistiques des tables. Avec les seuls fichiers isolés et une version du code de production, il ne resterait déjà que 3 489 598 octets sur 1 Gio, avant publication et livraison suivante. Les fichiers InnoDB globaux, redo/undo et binlogs sont extérieurs au répertoire mesuré ; ils ne sont pas présentés comme inclus dans ce chiffre.

La croissance indicative d'un réimport hebdomadaire des sept fichiers inchangés est d'environ **191 Mo** pour 581 142 présences supplémentaires. Cette différence corrige le périmètre du premier relevé, qui incluait environ 34,5 Mo de fichiers de la base principale, et exclut les 20,4 Mo ajoutés par les migrations finales. Elle reste une estimation : le détail des fichiers du premier relevé n'a pas été figé séparément, et le rollback puis la reconstruction du staging influencent les pages allouées. Des fichiers entièrement nouveaux peuvent ajouter jusqu'à 108,3 Mo d'originaux par semaine, avant les nouvelles propositions, révisions et publications. Ces chiffres ne permettent pas de garantir une exploitation hebdomadaire durable sur Free. Aucune purge ou augmentation de quota n'a été utilisée pour cette contre-mesure.

Le calcul applicatif additionne fichiers, code, dépendances, données et index MariaDB, ainsi que `EXTERNAL_STORAGE_USED_BYTES`. Les statistiques InnoDB constituent une estimation : le compteur réel du compte d'hébergement doit être contrôlé. La marge doit également couvrir la livraison suivante, les fichiers temporaires, les autres sites et les mails. L'alerte apparaît à 80 % ; une opération sans marge est refusée, sans purge métier ni passage payant.

### Réduction des index sans perte de traçabilité — migration 240000

Le contrôle de conflit de portée utilise désormais l'empreinte brute indexée, le type et l'organisation cible, puis compare strictement les clés. Les éventuelles anciennes lignes sans empreinte brute restent incluses dans un repli traité par lots bornés. Les index séparés de l'empreinte avec portée et du dernier identifiant de publication sont devenus inutiles et ont été retirés ; leurs colonnes restent conservées. L'empreinte historique de chaque présence est également conservée : elle ne peut pas être supprimée sur la seule base de son absence de lecture dans l'interface.

Sur la base du benchmark, la migration prend 0,368 s. Les statistiques SQL passent de **857 604 096 à 819 978 240 octets**, soit **37 625 856 octets de moins** après réestimation InnoDB. Les comptes métier, la génération, les sommes des numéros de révision et des verrous restent identiques. Le contrôle de conflit conserve son index brut, environ deux candidats dans le plan observé et 3,5–7,1 ms de lecture. La même migration a été appliquée à la base principale en 0,264 s, puis le contrôle de santé et le catalogue dans le navigateur ont été vérifiés.

L'allocation physique du benchmark reste **928 784 384 octets** : aucun octet alloué n'est rendu au disque par cette opération. En revanche, 42 991 616 octets supplémentaires deviennent réutilisables à l'intérieur de `terms.ibd`. Aucun `OPTIMIZE`, aucune purge, aucune reconstruction ni hausse de quota n'a été effectuée pour cette mesure. L'économie SQL ne suffit donc pas à qualifier le stockage Free ou une prochaine semaine d'import.

Le paquet final vérifié après ces corrections représente **33 177 855 octets décompressés** et **5 921 005 octets compressés**. La livraison suivante ajoute donc 60 070 380 octets, code, archive et marge de 20 Mio compris. Les projections actualisées sont :

| Scénario après migration 240000, publication et prochaine livraison incluses | Octets |
| --- | ---: |
| Base principale, estimation SQL | 956 992 023 |
| Base principale, allocation physique SQL | 1 111 587 351 |
| Benchmark, estimation SQL et fichiers de publication mesurés séparément | 1 055 573 229 |
| Benchmark, allocation physique SQL et fichiers de publication mesurés séparément | 1 164 379 373 |

La base principale occupe maintenant 720 912 384 octets selon les statistiques SQL, mais toujours 875 507 712 octets physiques. L'écart de **154 595 328 octets** a été ajouté au paramètre local `EXTERNAL_STORAGE_USED_BYTES`, conformément à la procédure de rapprochement du compteur. Le quota reste 1 073 741 824 octets. Avec le code et les dépendances de développement réellement présents, le compteur local réconcilié atteint **1 072 195 062 octets (99,86 %)**. La réservation de 154 163 416 octets pour un nouvel Incident code est bien refusée, sans création de lot. Ce réglage local ne remplace pas le compteur du compte alwaysdata : il empêche seulement de présenter les pages SQL encore allouées comme de l'espace disponible.

## Matrice des exigences

« Vérifiée » signifie vérifiée dans le périmètre local indiqué, pas en production.

| Critères | État | Preuve ou condition restante |
| --- | --- | --- |
| D-01 | Vérifiée | Pilote interne sans but lucratif décrit ; aucune activation publique effectuée. |
| D-02, D-03, D-05 | À vérifier | Compte alwaysdata Free, localisation contractuelle, quotas effectifs et dépenses GitHub à confirmer. Aucun service payant activé. |
| D-04, D-07, T-01, T-02 | Vérifiées | Laravel/Blade/PHP/MariaDB, dépendances verrouillées, paquet sans Node/Redis/serveur permanent. |
| D-06 | À vérifier | HTTPS et connexion externe sur le compte cible. Les refus d'accès non authentifié sont testés localement. |
| D-08 | À vérifier | Périmètres multi-organisations et deux destinataires testés ; charge de cinq utilisateurs sur Free encore à mesurer. |
| D-09, T-09, T-10, T-11 | Vérifiées localement | Sept fichiers complets, empreintes identiques, restitution binaire et collisions conservées. |
| D-10 | À vérifier sur Free | Durées locales documentées ; second passage repris après interruption. Chronométrage complet, consultation simultanée et CPU partagé restent à mesurer chez l'hébergeur. |
| T-03 | Non satisfaite selon l'allocation physique locale ; à réévaluer sur Free | Après réduction des index, les estimations SQL diminuent mais les scénarios physiques avec publication et prochaine livraison dépassent toujours le quota. Temporaires et compteur du fournisseur à contrôler. |
| D-11, T-21, T-22 | À vérifier à distance | Workflow Release, paquet, verrous, contrôle de quota, sauvegarde préalable et retour de schéma compatible implémentés et testés localement. |
| T-04 | Vérifiée localement | Alerte 80 % en DE/FR/IT et refus de marge insuffisante avant opération. |
| T-05 | Vérifiée localement par HTTP | Parseur et écritures bornés ; transfert multipart réel 71,8 Mo avec taille/empreinte identiques. Sélecteur navigateur et frontal alwaysdata à recontrôler. |
| T-06, T-07, T-08 | Vérifiées localement | Traitement CLI planifié, verrou partagé/exclusif, reprise et progression atomique privée. Planificateur Free à vérifier. |
| T-12, T-13 | Vérifiées localement | Workflow, droits, quatre yeux, concurrence, références modifiées, comparaison, restauration par proposition liée et historique. |
| T-14, T-15 | Vérifiées localement | Gel, manifestes, téléchargements ACL, contrôles de sources/portées/collisions, reprise après interruption. |
| T-16 | À vérifier sur Free | Recherche, filtres et pagination SQL de 100 lignes maximum mesurés sur le catalogue réel en local. |
| T-17, T-18, T-20 | Vérifiées localement | Tests serveur, TOTP/sessions et privilèges SQL réels sur MariaDB local. Comptes SQL de production à contrôler. |
| T-19 | À vérifier sur Free | Échappement, CSRF, fichiers privés, secrets exclus et en-têtes implémentés ; HTTPS/racine web/permissions effectives à vérifier. |
| T-23 | Vérifiée localement | Restauration intégrale base et fichiers, comptes/empreintes/déclencheurs identiques ; destination durable hors hébergement encore à désigner. |
| T-24 | Vérifiée pour le lot 1 | Quatre espaces avec [habillage myABI](recette-design-myabi.md), DE/FR/IT, recherche, révisions et résumés quotidiens ; SMTP réel à vérifier. |
| T-25 | À vérifier | Procédures livrées ; responsable, suppléant et destination durable des sauvegardes à désigner. |

Les détails de sécurité et d'exploitation se trouvent dans [recette-operations.md](recette-operations.md). Les scripts et commandes d'exploitation sont documentés dans [operations.md](operations.md).

## Conditions de validation finale du pilote

La publication du type Incident code reste bloquée par sa collision exacte. Les portées non confirmées, notamment les préfixes PHV/WIP/TEST/OLD, bloquent les publications concernées. Il faut les arbitrages du §9 technique et les réponses LogObject, pas une transformation automatique des fichiers.

Le compte alwaysdata, ses secrets et une instance myABI de test n'ont pas été fournis pendant cette réalisation. Il reste donc à exécuter la recette sur Free, puis le retour et le contrôle des 100 traductions dans myABI. Le code local, les restitutions binaires et les tests automatisés ne remplacent pas ces deux vérifications.

La vérification GitHub du 22 septembre confirme une authentification valide pour le dépôt, mais aucune variable ni aucun secret Actions de dépôt configuré. Le workflow de livraison n'utilise pas d'environnement GitHub distinct : ses paramètres SSH et `DEPLOY_ENABLED` restent donc absents. Seuls les noms ont été interrogés, aucun contenu de secret n'a été lu, et aucun workflow distant ni déploiement n'a été déclenché.
