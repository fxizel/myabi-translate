# Conception technique de la recherche approximative

Date : 5 novembre 2026.

Statut : conception technique validée le 5 novembre 2026, désormais réalisée dans le dépôt. Les décisions ci-dessous complètent le [périmètre fonctionnel validé](../fonctionnel/recherche-approximative.md). La grille de seuils reste une hypothèse calibrable ; les mesures synthétiques locales ne valent pas qualification de l'hébergement cible. La section de réalisation décrit le comportement livré et les limites de recette.

## Décisions de calibrage

| Sujet | Décision validée |
| --- | --- |
| Priorité | Privilégier la pertinence et écarter les ressemblances faibles, quitte à manquer certains libellés plus éloignés. |
| Qualité minimale | Au moins 95 % de résultats pertinents au total sur le corpus annoté, en évaluant jusqu’aux dix premiers résultats approximatifs de chaque recherche. Les cas positifs obligatoires doivent aussi réussir. |
| Fautes de frappe | Adapter la tolérance à la longueur du mot : plus stricte sur les mots courts, davantage d’écart possible sur les mots longs. Les seuils chiffrés seront validés sur le corpus d’essai. |
| Recherche de plusieurs mots | Exiger une correspondance pour tous les mots importants ; les articles et prépositions courants peuvent être ignorés. |
| Ordre des mots | Accepter un ordre différent, avec un meilleur classement pour l’ordre saisi. |
| Réglage utilisateur | Conserver le niveau équilibré unique et les règles fonctionnelles déjà validées, notamment les guillemets et le minimum de trois caractères. |
| Indexation | Prévoir un index complémentaire dans MariaDB pour les mots et fragments utiles, avec mesure du stockage et du coût des mises à jour. |
| Fraîcheur | Rendre les modifications recherchables dès l’enregistrement réussi, par une mise à jour ciblée sur les termes concernés. |

« Ouvrir dossier » peut ainsi retrouver « Ouverture du dossier », mais le seul mot « dossier » ne suffit pas à retenir « Clôture du dossier ». Les correspondances habituelles restent placées avant les résultats approximatifs ; la préférence pour l’ordre saisi intervient dans le classement des correspondances proches.

## Décisions de performance

| Sujet | Décision validée |
| --- | --- |
| Cible | Afficher la première page de résultats approximatifs complets, filtres et compteurs compris, en au plus 5 secondes dans 65 % des essais. |
| Charge de référence | 5 recherches simultanées sur un catalogue complet représentatif. |
| Cas exceptionnel | Plafond de 20 secondes, retour compris ; interrompre le calcul avant pour préserver le retour. |
| Dépassement | Afficher uniquement les correspondances habituelles dans le périmètre et avec les filtres demandés, avec un message expliquant le dépassement du délai. |
| Échec d’indexation | Conserver l’enregistrement métier et suspendre la recherche approximative jusqu’à réparation, avec une indisponibilité explicite et sans servir de résultats périmés. |

Le repli conserve la référence allemande et les valeurs en vigueur et propositions en attente de la langue cible. Il ne revient pas implicitement à une recherche dans toutes les langues ou dans d’autres champs. Les résultats approximatifs partiels ne sont pas affichés comme une liste complète.

Le délai de 5 secondes concerne la recherche approximative. L’objectif existant de consultation et de recherche habituelle inférieur à deux secondes reste celui des [spécifications techniques](../../specifications-techniques-referentiel-traductions-arge-abi.md).

## Protocole de calibration proposé

Le corpus doit contenir des requêtes et des valeurs candidates annotées comme pertinentes ou non pertinentes, avec des cas positifs obligatoires et des cas à rejeter. La qualité des seuls résultats retournés ne suffit pas : il faut également vérifier que les correspondances attendues sont retrouvées.

Les essais doivent distinguer les fautes de frappe, les mots courts et longs, les variantes de libellés, l’ordre des mots et les recherches exactes. Ils couvrent les quatre langues cibles du catalogue, les caractères Unicode, les différentes origines de valeurs et les attributs secondaires.

Les mots qui portent le sens doivent être examinés explicitement avant de figurer dans une liste de mots ignorés. Une liste générale de prépositions ne peut pas, à elle seule, justifier la suppression de « avec » ou « sans ». Les variables, nombres et références structurées doivent également faire partie des cas à examiner.

### Corpus initial

Tous les exemples sont fictifs. Les lignes proposées restent à annoter avant de devenir des critères de recette.

| Langue | Recherche | Valeur candidate | Attente |
| --- | --- | --- | --- |
| FR | dosier | Dossier | À retrouver, selon le périmètre fonctionnel. |
| FR | ouvrir dossier | Ouverture du dossier | À retrouver. |
| FR | ouvrir dossier | Clôture du dossier | À rejeter : un mot important manque. |
| FR | date création dossier | Dossier — date de création | À retrouver ; l’ordre saisi garde la préférence. |
| FR | `"dosier"` | Dossier | À rejeter : les guillemets imposent l’expression exacte. |
| DE | Dossir öffnen | Dossier öffnen | Exemple de faute à annoter. |
| IT | aprire fascicloo | Aprire un fascicolo | Exemple de faute et de phrase à annoter. |
| EN | opne case | Open a case | Exemple d’inversion à annoter. |
| FR | dossier sans erreur | Dossier avec erreur | Cas de sens à examiner ; rejet proposé. |

Une fois les annotations arrêtées, la calibration doit déterminer les seuils par longueur, les variantes de libellés admises et la préférence accordée à l’ordre des mots, pour atteindre le critère de qualité validé.

### Mesure de la pertinence

Pour chaque recherche du corpus, l’évaluation retient au maximum les dix premiers résultats approximatifs, après regroupement par terme. Les correspondances habituelles ne sont pas incluses dans cette mesure. Le nombre de résultats pertinents est cumulé puis divisé par le nombre total de résultats évalués ; le rapport doit atteindre 95 % au moins. La ventilation par langue et famille de requêtes complète ce résultat global.

Les cas positifs obligatoires sont vérifiés séparément : une réponse vide à tort reste un échec, même si les quelques résultats renvoyés ailleurs sont tous pertinents. Un corpus sans résultat approximatif évalué ne permet pas de valider le taux de pertinence.

## Protocole de performance proposé

Les mesures porteront sur un corpus synthétique reproduisant la volumétrie complète prévue par les spécifications. Le nombre de termes, de valeurs, de propositions, de mots distincts et leurs distributions doivent être décrits séparément. Un petit échantillon fonctionnel ne peut pas valider la performance du catalogue complet.

Pour mesurer le délai visible, le chronométrage proposé va de l’application des critères jusqu’à l’affichage de la première page et de ses compteurs, sur un environnement de référence documenté. Le temps serveur et le temps SQL sont aussi relevés pour expliquer le résultat. Les conditions matérielles, versions et conditions réseau devront accompagner les mesures.

Les recherches approximatives complètes affichées en cinq secondes au plus doivent représenter au moins 65 % des essais de référence. Cette proportion a été abaissée de 95 % à 65 % par décision de l'utilisateur le 5 novembre 2026 ; la précision minimale reste de 95 %. Les replis et erreurs restent dans le dénominateur, sont comptés séparément et ne constituent pas des succès approximatifs complets. Une recherche terminée sans correspondance est un résultat complet lorsqu’elle a correctement évalué les critères.

Le scénario nominal comporte cinq recherches simultanées, avec des requêtes variées. Les résultats sont aussi examinés par langue, longueur de saisie, type de recherche et sélectivité des filtres. Les essais à froid, à chaud et pendant un import complètent le scénario nominal, conformément aux exigences existantes de consultation pendant les traitements.

Les relevés comprennent les durées, les replis, les erreurs, la consommation mémoire, les requêtes SQL et le coût de stockage des index. Les identifiants des cas synthétiques suffisent dans les relevés ; les rapports d’exécution resteront locaux, hors Git et des livraisons.

## Gestion du budget de calcul proposée

La recherche doit préparer les correspondances habituelles dans le périmètre demandé pour permettre le repli. Le budget du traitement approximatif doit inclure les requêtes SQL, la sélection des candidats, le classement et les compteurs. Un contrôle du délai uniquement après une requête SQL non bornée ne suffit pas à respecter le plafond.

Les limites de candidats, l’annulation des traitements SQL et la marge nécessaire au retour de la page doivent être définies ensemble. Si un traitement ne peut pas produire un résultat complet dans le budget, il utilise le repli décidé ; il ne présente pas un total approximatif tronqué comme exhaustif.

MariaDB permet de limiter une requête avec `max_statement_time`, mais son arrêt dépend de points de contrôle pendant l’exécution et peut être retardé. Cette limite SQL doit donc être combinée avec le budget applicatif restant, des traitements bornés et une marge pour le retour. Le plafond de vingt secondes reste un critère à vérifier ; fixer une variable SQL à vingt secondes ne suffit pas à le démontrer. Voir la [documentation MariaDB sur l’arrêt des requêtes](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/query-optimizations/aborting-statements).

## Indexation retenue et modèle proposé

Les [index de l’extension](../../database/migrations/2026_10_14_130100_create_browser_extension_search.php) couvrent notamment les valeurs normalisées, les préfixes et les mots exacts. La [projection correspondante](../../app/Services/BrowserExtension/SearchProjection.php) ne couvre pas les propositions en attente. Une recherche de mots exacts seule ne suffit pas au cas « dosier » vers « dossier ».

L’index complémentaire dans MariaDB est retenu, en réutilisant les composants existants compatibles. Le modèle proposé prépare les mots et des fragments de mots pour sélectionner les variantes candidates, puis rattache les correspondances aux valeurs, langues, attributs et propositions autorisés. Les positions des mots permettent de prendre en compte leur ordre dans le classement.

Le stockage supplémentaire et le coût des mises à jour lors des imports et décisions devront être mesurés. Ces coûts accompagnent les index supplémentaires, comme le rappelle le [guide MariaDB sur les index](https://mariadb.com/docs/server/mariadb-quickstart-guides/mariadb-indexes-guide). Les règles de longueur des mots et de mots ignorés d’un index `FULLTEXT` doivent aussi être confrontées au corpus ; elles sont décrites dans la [présentation officielle des index plein texte](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/optimization-and-indexes/full-text-indexes/full-text-index-overview).

### Organisation logique proposée

Les noms ci-dessous décrivent des responsabilités ; le schéma physique sera arrêté avec les essais de coût. Les données de recherche restent séparées des identités métier et ne constituent pas des propositions ou révisions supplémentaires.

| Ensemble | Contenu et rôle |
| --- | --- |
| Valeurs recherchables | Identifiant du terme, attribut, langue, origine, identifiant de proposition éventuel, version de normalisation et lien vers la valeur courante. Chaque valeur reste distincte pour expliquer la correspondance. |
| Vocabulaire | Mots normalisés distincts par langue, avec leur longueur. Les comparaisons de fautes portent sur ce vocabulaire, sans charger tout le catalogue en PHP. |
| Occurrences | Association entre les valeurs et les mots, avec leur position. Les répétitions significatives restent représentables. |
| Fragments de mots | Fragments courts et repères de début et de fin, associés aux mots du vocabulaire pour sélectionner les candidats. Les tailles et limites de sélection seront testées. |
| État de l’index | Disponibilité, génération active et version de normalisation, pour empêcher l’utilisation d’un index incomplet ou incompatible. |

Le dictionnaire de mots mutualise les fragments au lieu de les recopier pour chaque occurrence du même mot. La page charge les valeurs originales nécessaires à ses lignes et extraits ; le besoin éventuel de stocker un texte normalisé complet dans la projection sera comparé au coût d’une nouvelle copie. Aucune organisation supplémentaire n’entre dans l’identité du terme.

### Mise à jour ciblée et reconstruction

La mise à jour ciblée accompagne l’enregistrement d’un terme ou d’une proposition et ses décisions. Elle couvre la création, la correction, la validation, le rejet, les propositions remplacées, les imports appliqués et les changements d’éligibilité des termes. Une proposition qui cesse d’être en attente doit immédiatement quitter les origines recherchables correspondantes.

Les points de synchronisation existants de [SearchText](../../app/Services/SearchText.php) et du [workflow](../../app/Services/WorkflowService.php) sont à réutiliser et compléter après inventaire des écritures. Dans le workflow actuel, le rejet ne passe pas par l’appel de synchronisation présent dans la branche de validation ; ce chemin doit être couvert pour un index de propositions en attente.

La reconstruction complète est un traitement explicite par lots bornés, distinct des requêtes de recherche. Une génération ne devient utilisable qu’après contrôle de sa complétude et de sa cohérence avec les écritures intervenues pendant sa préparation. Elle ne permet pas d’accepter des résultats périmés après une modification confirmée. Le coût d’une mise à jour ciblée et celui d’une reconstruction complète seront mesurés séparément.

En cas d’échec d’indexation, la décision validée est de conserver l’action métier et de rendre l’approximatif indisponible jusqu’à réparation, sans servir de résultats périmés. La [projection de l’extension](../../app/Services/BrowserExtension/SearchProjection.php) suit déjà ce principe pour son propre index. La confirmation de l’action reste conditionnée à la persistance effective du changement métier et de l’état d’indisponibilité ; une erreur qui empêche ces écritures ne peut pas être présentée comme un enregistrement réussi.

Une nouvelle recherche après confirmation de l’enregistrement voit la modification dans l’index disponible, ou l’indisponibilité de l’approximatif. Une génération connue comme incomplète ou incompatible ne peut pas continuer à fournir des résultats. La réparation passe par une reprise ou une reconstruction contrôlée ; la remise en service exige un contrôle de complétude et de cohérence avec les dernières écritures.

Le message proposé après une erreur d’indexation est : « Modification enregistrée. La recherche approximative est temporairement indisponible. » Lors d’une tentative de recherche, l’indisponibilité est distinguée d’un résultat vide. Les modes de recherche existants restent accessibles ; l’application ne prétend pas avoir terminé un élargissement approximatif ni ne change silencieusement son périmètre.

## Comparaison et classement proposés

### Chaîne de recherche

1. Valider la saisie, les droits, les filtres et le mode ; appliquer la priorité des guillemets et la règle des saisies courtes.
2. Préparer les correspondances habituelles dans le périmètre demandé, avec leurs compteurs, pour permettre le repli.
3. Normaliser la requête selon un profil versionné et identifier ses mots importants.
4. Rechercher dans le vocabulaire les mots exacts, les variantes lexicales autorisées et les candidats issus des fragments ; calculer leur coût de rapprochement sur des caractères Unicode.
5. Vérifier que tous les mots importants correspondent dans une même valeur admissible. Des mots éparpillés dans plusieurs attributs, langues ou propositions ne constituent pas ensemble une correspondance de phrase.
6. Classer les valeurs, conserver la meilleure correspondance de chaque terme, placer les résultats habituels d’abord, puis calculer les compteurs et la pagination sur les mêmes termes distincts.
7. Charger les seules valeurs originales nécessaires à la page et aux extraits, en conservant leur origine et le lien vers la correspondance.

Une limite de travail ne doit pas devenir un classement silencieux des seuls premiers candidats lus. Si le résultat complet ne peut pas être établi dans le budget, le traitement rejoint le repli prévu. Les tailles de lots et plafonds de candidats doivent donc être évalués avec les objectifs de complétude, mémoire et délai.

### Fautes et variantes de libellés

La comparaison proposée compte les insertions, suppressions, substitutions et inversions de lettres voisines sur les mots normalisés. Les quatre opérations correspondent à des cas de correction orthographique décrits dans cette [étude du KTH sur la correction de fautes](https://www.csc.kth.se/~viggo/papers/ftp/detectspell.pdf). La variante de distance retenue et ses cas limites devront être explicitement testés ; les longueurs et coûts ne doivent pas être calculés sur les octets UTF-8.

Les variantes de libellés suivent une voie distincte et contrôlée par langue. Le couple « ouvrir / ouverture » fait partie des cas à retrouver : il ne faut pas supposer qu’un petit nombre de corrections de lettres ou une suppression générale des suffixes suffira à le traiter. La proposition initiale est une ressource interne de familles lexicales et de règles annotées, versionnée avec le corpus. Elle ne constitue pas une recherche de synonymes généraux et ne change aucune identité de terme.

La normalisation et le surlignage doivent rester compatibles : le texte original garde ses accents, sa casse, ses espaces et ses retours à la ligne. Le rapprochement utilise une représentation normalisée ; les extraits indiquent les segments de l’original qui ont effectivement correspondu.

### Premiers seuils à tester

Cette grille est une hypothèse de départ proposée pour les essais, sans validation de qualité ou de vitesse à ce stade. Elle pourra être modifiée pour atteindre les critères validés. La tranche de longueur repose sur le plus court des deux mots normalisés.

| Longueur du mot | Tolérance orthographique de départ proposée |
| --- | --- |
| 1 ou 2 caractères significatifs | Correspondance exacte ; ces mots ne sont pas supprimés lorsqu’ils portent le sens. |
| 3 ou 4 caractères | Correspondance exacte ou une inversion de deux lettres voisines ; tester séparément l’ouverture à une autre correction. |
| 5 à 7 caractères | Jusqu’à une opération de correction. |
| 8 caractères ou plus | Jusqu’à deux opérations de correction. |

Le minimum de trois caractères reste celui de la saisie complète. Une phrase peut donc comporter des mots significatifs plus courts. Les familles lexicales annotées ne sont pas assimilées à un dépassement arbitraire de ces seuils de fautes : leur couverture et leurs faux rapprochements sont vérifiés séparément.

### Ordre de classement initial proposé

La priorité des correspondances habituelles reste absolue. Pour les résultats approximatifs, l’ordre proposé privilégie la qualité lexicale, puis l’ordre saisi à qualité lexicale égale, puis la proximité de longueur du libellé. Un identifiant stable départage les égalités, pour éviter des permutations entre les pages sans changement des données.

Le score reste interne et ne vaut pas un pourcentage de confiance affiché à l’utilisateur. Les variantes de mots, leurs coûts et la préférence pour l’ordre sont réglés à partir du corpus, en suivant les résultats pertinents, les faux rapprochements et les cas obligatoires manqués.

## Essais prévus après autorisation de réalisation

La recette proposée associe les cas de qualité à un scénario nominal d’au moins 200 recherches, exécutées avec cinq recherches simultanées. Le catalogue synthétique complet et les annotations sont fixés avant de modifier les seuils ; les cas réservés à la vérification finale restent distincts des cas utilisés pour régler le moteur.

L’environnement de référence doit être isolé et représentatif de l’hébergement cible. Ses capacités CPU, mémoire, stockage et concurrence doivent être documentées avant mesure. L’attente éventuelle avant le traitement serveur fait partie du délai visible ; elle ne peut pas être retirée pour améliorer artificiellement le résultat à cinq recherches simultanées.

La recette doit aussi couvrir les corrections et décisions pendant la consultation, les annulations de transactions, la reconstruction, le dépassement forcé du délai et les erreurs d’indexation. Le rapport indique les critères satisfaits ou non satisfaits ; aucun résultat de performance n’est déduit de la seule conception.

## Points techniques restant à préciser

Ce tableau conserve les travaux prévus lors de la conception. La section suivante précise leur réalisation et les contrôles restant à effectuer sur l'environnement cible.

| Sujet | Travail de conception restant |
| --- | --- |
| Qualité | Arrêter les annotations du corpus de référence pour appliquer le seuil validé de 95 % et les cas positifs obligatoires. |
| Algorithme | Revoir la chaîne proposée, puis valider les seuils adaptatifs et les familles de libellés sur le corpus. |
| Indexation | Finaliser le schéma physique et les limites de sélection après évaluation du stockage et des mises à jour ciblées. |
| Échec d’indexation | Vérifier la conservation effective de l’action métier, la suspension de l’approximatif et sa remise en service après réparation. |
| Délai | Définir les budgets SQL et applicatifs nécessaires pour appliquer le plafond et retourner le repli. |
| Recette | Fixer l’environnement de mesure et les dimensions du corpus synthétique complet avant toute conclusion de performance. |

## Réalisation du profil 1

Le moteur est dans `App\Services\CatalogueSearch`. `SearchText::syncMany` synchronise la projection après l'enregistrement des propositions ; le rejet et la transition vers les termes communs sont également couverts. Les attributs déjà décodés par un import ne sont réutilisés que s'ils appartiennent à la source effectivement retenue du terme. L'extension conserve son normaliseur, ses tables, son moteur et son contrat API.

Les six tables `catalogue_search_*` séparent état, mutations à traiter, valeurs, vocabulaire mutualisé, occurrences positionnées et bigrammes. `terms.catalogue_reference_de` conserve uniquement les références dont la langue est identifiée DE, avec une signature de la source. Cette projection compacte est enregistrée avec le changement métier. Le repli interroge ces références, le JSON des validations et les propositions actuellement en attente, indépendamment des tables de l'approximatif. `term_search` fournit un surensemble de candidats habituels ; les termes marqués à traiter y sont ajoutés pour couvrir les écritures SQL directes. Chaque valeur est ensuite vérifiée dans le périmètre exact demandé.

Les mots sont normalisés avec le composant compatible de l'extension et un profil propre au catalogue. Les accents, ligatures et casse sont rapprochés ; les positions de l'original sont conservées. Les petits mots significatifs, les négations, nombres, variables et références structurées ne reçoivent pas de correction arbitraire. Les familles morphologiques contrôlées sont dans `resources/search/lexical-families.php`. Toute évolution du profil ou de cette ressource impose une nouvelle version et une reconstruction.

La comparaison est la distance de Damerau-Levenshtein restreinte, dite OSA, sur les caractères Unicode. Elle ne correspond pas à la distance non restreinte ; le cas `CA → ABC` est testé. Les candidats par fragments sont un surensemble : une inversion peut détruire trois bigrammes. Les comparaisons finales appliquent la grille de départ selon le plus court des deux mots. Tous les mots importants doivent correspondre à des occurrences distinctes d'une même valeur. Une affectation minimise le coût lexical et préfère un alignement dans l'ordre lorsque le coût est identique.

Deux tables SQL temporaires propres à la connexion portent les variantes et les meilleurs résultats par terme. Les candidats groupés sont lus une seule fois, sans verrou d'écriture, dans un fichier temporaire privé et automatiquement supprimé, limité à 16 Mio de métadonnées. Seuls 200 identifiants ou scores sont repris en mémoire à la fois. Cette lecture évite les verrous partagés que pourrait prendre un `INSERT SELECT` sur la source. Le cas d'un unique mot candidat utilise un classement SQL par terme ; les autres cas conservent l'affectation des occurrences. Le classement final précède la pagination : habituels d'abord, puis coût lexical, ordre, écart de longueur et identifiant du terme. Les garde-fous de candidats, occurrences, mémoire, fichier temporaire et temps provoquent un repli complet ; ils ne produisent pas un total tronqué. Les compteurs par type, habituel et approximatif portent sur les mêmes termes distincts.

Les déclencheurs transactionnels enregistrent les mutations des termes et propositions. Une écriture optionnelle échouée est annulée jusqu'au point de sauvegarde, puis l'indisponibilité est persistée dans la transaction métier. Si cette transaction ne peut pas être validée, l'action ne peut pas être confirmée. Une synchronisation ultérieure ne réactive pas un index suspendu. Les lectures utilisent une vue cohérente ; elles ne gardent pas de verrou partagé global pendant tout le calcul.

Le plafond initial de quinze secondes a été porté à vingt secondes par décision de l'utilisateur le 5 novembre 2026. La proportion exigée sous cinq secondes est désormais de 65 %, tandis que le seuil de pertinence reste de 95 %. Le budget est de 17 secondes de travail avec une réserve de retour de 3 secondes. Les requêtes candidates sont soumises à une limite SQL de 2 secondes au maximum, réduite au budget restant et restaurée même après annulation. La préparation des résultats habituels dispose d'une limite par instruction de 3 secondes lorsque l'index est disponible, 6 secondes lorsqu'il est indisponible. Le budget est recalculé séparément avant les compteurs et avant la sélection de la page ; une relation déjà prouvée vide dans le même instantané n'est pas recalculée. Ces valeurs ont été révisées après les premières mesures synthétiques ; aucun seuil de pertinence n'a été assoupli. Ces mécanismes sont vérifiés par des essais d'annulation et ne prouvent pas, seuls, un plafond visible sur toute infrastructure. Les paramètres techniques sont dans `config/catalogue-search.php`.

Les configurations Nginx fournies transmettent l'horodatage interne `MYABI_REQUEST_STARTED_AT` au contrôleur FastCGI, avant l'attente du processus PHP. Cette attente réduit le budget restant ; aucun en-tête HTTP du client n'est utilisé comme horloge. La conversion initiale utilise les horloges des services du même hôte, puis le calcul repose sur l'horloge monotone PHP. Pour une autre configuration native, installer le même paramètre et vérifier la synchronisation des horloges ; sans lui, la mesure applicative commence à l'entrée dans PHP. Nginx documente [la variable temporelle `$msec`](https://nginx.org/en/docs/http/ngx_http_core_module.html#var_msec) et [les paramètres FastCGI](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_param).

Si le serveur ne peut pas préparer une liste habituelle complète, la réponse est une erreur 503 avec les critères conservés et sans tableau ni total fictif. Ce cas est compté comme une erreur de recette, distincte du repli habituel complet, d'un index indisponible et d'une liste vide.

### Reconstruction et déploiement

Après sauvegarde cohérente et migration, exécuter `php artisan referentiel:catalogue-search-rebuild`. La commande prend le verrou exclusif des opérations lourdes, vérifie les originaux privés, prépare une génération par lots de 200 termes, actualise les statistiques MariaDB hors transaction, contrôle ses compteurs et sa cohérence avec les mutations, puis l'active atomiquement. Elle reprend un curseur compatible et recommence une génération si le catalogue ou le profil a changé. La recherche habituelle reste disponible pendant la préparation ; les écritures métier sont suspendues. Les générations précédentes sont nettoyées par lots après activation.

Le compte applicatif a besoin de `CREATE TEMPORARY TABLES` sur la seule base applicative. Le provisionnement Docker l'accorde sans élargir les droits `SELECT, INSERT` du journal d'audit. Après création des nouvelles tables, rejouer le provisionnement table par table. Ne pas attribuer les droits de migration au compte web.

### Corpus et outils de mesure

`tests/Fixtures/catalogue-search-corpus.php` fixe 400 recherches annotées, dont 200 de calibration et 200 réservées à la vérification, avec des noms différents dans les deux moitiés. Chaque contexte est un ensemble de candidats entièrement annoté. Les quatre langues, origines, champs secondaires, guillemets, saisies courtes, variables, nombres et différences de sens sont couverts. La précision ne compte que les dix premiers termes approximatifs au maximum ; les positifs obligatoires sont contrôlés séparément. Cette évaluation ne mesure pas la pertinence de toutes les requêtes possibles.

Chaque contexte annoté comporte trois candidats. La précision obtenue dans ces ensembles fermés ne démontre pas la précision de toutes les recherches sans filtre sur le grand catalogue.

Les premiers essais MariaDB avec la grille initiale ont évalué 161 résultats approximatifs en calibration, dont 160 pertinents, et 160 résultats réservés, tous pertinents ; aucun positif obligatoire n'a été manqué. Les annotations n'ont pas été modifiées pour obtenir ces résultats. Les rapports détaillés et leurs empreintes restent privés sous `.runtime/recherche-approximative/`.

Un complément figé de 96 recherches (`catalogue-search-corrections.php`) couvre explicitement insertions, substitutions et deux corrections, sur d'autres mots et avec 48 cas dans chaque moitié. Il complète les suppressions et inversions des 400 annotations initiales sans modifier celles-ci ni régler les tolérances sur la moitié réservée. Les tests de répétitions, Unicode et différences de sens complètent cette mesure.

Le générateur `scripts/lib/SyntheticSearchCatalogue.php` reproduit les proportions des sept types sur au moins 581 142 termes, leurs attributs multiples, des textes répétés, cellules vides, valeurs multilignes et propositions. Il crée de nouveaux CSV synthétiques privés et ne lit pas `data/imports`. Ses taux de remplissage et son vocabulaire sont synthétiques ; les nombres de termes, valeurs, propositions, mots et index doivent accompagner les résultats.

Pour les mesures PHP/SQL, exporter des paramètres synthétiques de test et utiliser le bootstrap gardé `scripts/lib/isolated_search_bootstrap.php`, avec `APP_ENV=testing`, `MYABI_ISOLATED_TESTS=1`, un schéma neuf `myabi_test_*`, un hôte loopback, une clé applicative jetable et `MYABI_BENCHMARK_DATADIR` correspondant exactement à l'instance. Migrer ce seul schéma avant la génération. Ne jamais charger le `.env` de l'application ni diriger ces outils vers sa base de travail.

```sh
php scripts/benchmark_approximate_search.php --bootstrap=scripts/lib/isolated_search_bootstrap.php --seed=581142 --report=.runtime/recherche-approximative/seed.json --fixtures=.runtime/recherche-approximative/fixtures.json
php scripts/benchmark_approximate_search.php --bootstrap=scripts/lib/isolated_search_bootstrap.php --rebuild --report=.runtime/recherche-approximative/rebuild.json --fixtures=.runtime/recherche-approximative/fixtures.json
php scripts/benchmark_approximate_search.php --bootstrap=scripts/lib/isolated_search_bootstrap.php --samples=200 --report=.runtime/recherche-approximative/service.json --fixtures=.runtime/recherche-approximative/fixtures.json
php scripts/benchmark_approximate_search.php --bootstrap=scripts/lib/isolated_search_bootstrap.php --describe --report=.runtime/recherche-approximative/catalogue-description.json --fixtures=.runtime/recherche-approximative/fixtures.json
php scripts/benchmark_approximate_search.php --bootstrap=scripts/lib/isolated_search_bootstrap.php --update-samples=20 --report=.runtime/recherche-approximative/updates.json --fixtures=.runtime/recherche-approximative/fixtures.json
php scripts/benchmark_approximate_search.php --bootstrap=scripts/lib/isolated_search_bootstrap.php --import-type=server --changed --import-control --report=.runtime/recherche-approximative/import-control.json --fixtures=.runtime/recherche-approximative/fixtures.json
php scripts/benchmark_approximate_search.php --bootstrap=scripts/lib/isolated_search_bootstrap.php --import-type=mric --import-rows=5000 --changed --report=.runtime/recherche-approximative/import-concurrent.json --fixtures=.runtime/recherche-approximative/fixtures.json
```

Le script navigateur `scripts/benchmark_approximate_search.cjs` mesure cinq clients, le rendu et l'attente comprise. Son serveur de test doit utiliser trois processus PHP avec base et stockage isolés. Il sépare résultats complets, replis et erreurs. Un essai Windows avec trois serveurs PHP et un proxy local ne qualifie pas PHP-FPM/Nginx sous Linux : la cible à cinq secondes et les essais à froid, à chaud et pendant import restent à vérifier sur une réplique de la configuration VPS.

La réévaluation des trois campagnes locales de 200 recherches avec le seuil accepté de 65 % donne 67,5 % après redémarrage du buffer MariaDB, 69,5 % à chaud et 65,5 % pendant import. Le maximum visible observé est de 17,70 secondes, sans dépassement de vingt secondes. Le critère de rapidité est donc atteint localement dans les trois conditions, avec une seule recherche de marge sur 200 pendant import. Les 90 replis et 34 erreurs sur 600 essais restent des échecs approximatifs complets. Ces mesures précèdent le changement du critère : leurs rapports bruts sont conservés et leur nouvelle évaluation est séparée sous `.runtime/recherche-approximative/acceptance-evaluation.json`. Elles ne remplacent pas la qualification Linux ni la validation de représentativité du catalogue synthétique.

Les fichiers de résultats et les paramètres synthétiques restent sous `.runtime`. Le contrôle comparatif annule les deux transactions métier, mais peut laisser des originaux synthétiques privés et des fichiers de progression ; il ne doit jamais viser une base de travail. L'import de charge, lui, est réellement validé dans la base jetable. Le générateur préremplit les références DE : une reconstruction ainsi mesurée ne représente pas le coût d'une première initialisation avec caches vides. Prévoir la place pour la génération active, celle en construction et les fichiers temporaires avant une reconstruction réelle.
