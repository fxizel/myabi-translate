# Passation pour le passage aux termes communs

Date : 13 octobre 2026.

Objectif : supprimer la propriété organisationnelle des termes et partager une seule traduction validée par terme, attribut et langue. Les organisations restent présentes pour rattacher les utilisateurs, identifier les sources des propositions et des imports, et gérer les destinataires des publications.

Cette passation permet de reprendre la mise en œuvre dans une autre session. Elle rassemble les décisions utilisateur, les comportements constatés et le plan technique.

## État du travail

- Le cadrage et l'analyse du code sont réalisés. Aucune modification applicative de ce chantier n'a été réalisée dans cette session ; seule la passation a été écrite et actualisée.
- Les quatre arbitrages complémentaires et la précision sur les publications partiellement validées sont actés. Les choix techniques de reprise doivent appliquer ces décisions.
- L'utilisateur confirme que l'outil n'est pas encore en production. Dimensionner la reprise selon les données réellement présentes ; ne pas présumer l'existence d'une base de production à migrer.
- Révision du dépôt lors de la préparation de cette passation : `6c588ac`.
- Les tests n'ont pas été exécutés pendant l'analyse.
- Aucun diagnostic des données privées n'a été lancé. Le nombre de termes dédoublés par organisation et de valeurs divergentes reste à mesurer.
- Au démarrage de cette session, `docs/README.md` était déjà modifié et `docs/fonctionnel/groupes-et-campagnes-traduction.md` était non suivi. Préserver ces travaux et réexaminer l'état Git avant toute intervention.
- Au dernier contrôle, d'autres travaux étaient présents : contrôleurs, modèles, services, vues, migration et tests des groupes et campagnes, ainsi que des modifications des termes, propositions, imports, workflow et routes. Ces changements ne proviennent pas de la rédaction de cette passation. Les préserver et revérifier les constats techniques sur le code courant avant de mettre en œuvre le référentiel commun.

## Décisions utilisateur

L'utilisateur a précisé :

> myABI-translate doit garder la notion d'organisation afin de rattacher un utilisateur à celle-ci et de connaître la source de la proposition. La partie, un terme avec une traduction différente par organisation ne doit pas exister. Cela n'est pas la volonté du projet.

Les règles à réaliser sont donc :

1. Un terme courant est identifié par son type DEVCONF et sa clé exacte, sans organisation propriétaire.
2. Une seule traduction validée courante existe par terme, attribut et langue.
3. Plusieurs propositions, éventuellement divergentes, peuvent être soumises pour cette même cellule. Leur provenance est conservée et une décision autorisée choisit la valeur commune.
4. L'organisation continue de rattacher les utilisateurs et de documenter l'origine des contributions.
5. Les préfixes de formulaires ou workflows restent dans les clés et le contexte techniques. Ils ne créent plus un propriétaire ou une variante de traduction.
6. Un formulaire spécifique à une installation reste un terme distinct si sa clé est distincte. Il appartient néanmoins au référentiel commun.
7. Lorsqu'un nouvel import CSV retrouve un terme avec la même clé exacte et le même type, réutiliser ce terme et soumettre les nouvelles valeurs de traduction comme propositions. L'import ne remplace pas directement la traduction validée et ne crée pas une variante organisationnelle.
8. L'export d'un type DEVCONF contient tous les termes connus de ce type, quel que soit leur fichier d'origine. L'utilisateur choisit explicitement l'option B : dans l'exemple de 1 200 termes connus et d'un fichier source de 1 000 lignes, l'export contient les 1 200 termes. La provenance et l'organisation ne limitent pas le contenu de l'export.
9. Pour l'export, lorsqu'aucune traduction validée n'existe pour une cellule cible, conserver la valeur importée comme repli non validé (option A du troisième arbitrage). Ne pas la valider automatiquement et ne pas utiliser une correction manuelle en attente comme remplacement.
10. La publication a une finalité distincte de l'export : elle produit un fichier contenant les termes ayant une traduction validée, y compris lorsqu'ils ne sont que partiellement validés. L'utilisateur choisit B : pour un terme validé en français mais pas en italien, inclure la traduction française et laisser la cellule italienne vide. Les cellules cibles sans validation ne reprennent pas de valeur importée dans la publication.
11. Pour reprendre plusieurs anciennes fiches ayant le même type, la même clé exacte et les mêmes références, conserver la première fiche comme base avec ses traductions validées. Les valeurs divergentes des autres fiches deviennent des propositions à examiner ; les historiques et leur provenance sont conservés. L'utilisateur choisit l'option A du quatrième arbitrage.

L'utilisateur a demandé une analyse fine, un plan d'action et cette passation, puis répondu aux questions de cadrage une par une. Les décisions métier sont actées ; la mise en œuvre reste à réaliser.

## Modèle cible

| Élément | Règle cible |
| --- | --- |
| Organisation | Rattachement des comptes, origine des contributions et destination des fichiers. |
| Utilisateur | Organisation conservée ; droits fondés sur le rôle, les langues et l'état du compte. |
| Terme | Identité globale par type et composantes exactes de la clé. Aucun propriétaire organisationnel. |
| Traduction validée | Une seule valeur commune par terme, attribut et langue. |
| Proposition | Auteur, origine, historique, état et décision conservés. Des valeurs concurrentes restent possibles. |
| Import | Type de source, organisation source, version, original et présences conservés. |
| Export | Tous les termes connus du type sélectionné, valeurs validées communes et repli sur les valeurs importées en l'absence de validation. |
| Publication | Termes ayant une traduction validée, même partiellement ; cellules cibles validées renseignées et autres cellules cibles vides ; fichiers figés et accès par destinataire. |

La version myABI continue de définir les présences et publications. Elle n'entre pas actuellement dans l'identité d'un terme ; ce chantier n'impose pas d'ajouter des variantes par version.

## Analyse du code

### Identité et import

[ImportService](../../app/Services/ImportService.php) maintient deux empreintes : `identity_hash`, calculée avec le propriétaire, et `raw_identity_hash`, calculée avec le type et la clé exacte. [FormatDefinition](../../app/Domain/Devconf/FormatDefinition.php) fournit déjà l'identité sans organisation.

Les méthodes `owner()`, `identityHash()`, `findExact()` et `known()` de l'importeur utilisent encore les périmètres, les décisions explicites et les liens de provenance pour choisir une variante. Elles doivent évoluer vers une sélection globale et non ambiguë.

Une empreinte reste un index de candidats. Comparer ensuite le type et les composantes exactes de la clé ; conserver casse, espaces et composantes vides autorisées. Ne pas imposer une unicité fondée sur la seule empreinte qui empêcherait de représenter une collision entre clés distinctes. Assurer la création du terme commun sous les protections de concurrence existantes.

Une identité retrouvée dans plusieurs imports n'est pas en soi une collision : les sources peuvent alimenter le même terme. Les doublons ambigus à l'intérieur d'un fichier restent conservés, signalés et bloquants.

### Droits et visibilité

[User](../../app/Models/User.php), [Term](../../app/Models/Term.php) et [CommonScope](../../app/Services/CommonScope.php) limitent encore certaines consultations et contributions au commun et à l'organisation du compte.

La cible ouvre le catalogue aux utilisateurs autorisés et la contribution aux langues autorisées, quel que soit l'ancien propriétaire. Adapter aussi les appels de [WorkflowService](../../app/Services/WorkflowService.php), les contrôleurs et les conditions Blade.

Conserver les restrictions d'édition des propositions, la séparation auteur–validateur, la dérogation explicite du Gestionnaire, les comptes techniques non connectables et les contrôles d'activation. Un Administrateur seul ne devient pas Gestionnaire. Les droits de téléchargement des publications restent fondés sur le destinataire.

Le workflow sait déjà conserver plusieurs propositions sur une cellule. La validation d'une proposition met à jour la valeur commune et rejette les autres propositions en attente de cette cellule comme supplantées.

### Provenance des propositions

Le schéma possède déjà `proposals.organization_id`, `author_id`, `import_id` et la table de liens `proposal_imports`. L'importeur réutilise une proposition en attente identique et ajoute un lien vers l'import supplémentaire. Il n'est pas nécessaire de créer un mécanisme parallèle sans examiner l'existant.

[ProposalFilter](../../app/Services/ProposalFilter.php) filtre actuellement l'organisation sur le propriétaire du terme. Son filtre d'import ne considère que l'import principal. À l'inverse, `InitialValidationService::importProposals()` inclut déjà les imports supplémentaires.

Recommandation : conserver l'organisation d'origine au moment de la contribution, présenter séparément l'auteur courant et les imports associés, et harmoniser les filtres avec cette provenance. Ne pas recalculer l'origine à partir de l'organisation actuelle du compte. Une proposition partagée entre imports peut correspondre à plusieurs organisations sources.

Lorsqu'une proposition importée est reprise ou corrigée, son origine historique reste consultable. Un import associé ne doit pas être présenté comme ayant fourni la valeur corrigée s'il n'a fourni qu'une valeur antérieure.

### Référence commune et sources concurrentes

Un terme possède une source courante unique : `source_import_id`, `source_offset`, `source_length`, ainsi que ses empreintes de référence et de métadonnées. L'importeur peut remplacer cette source lorsqu'un import admissible modifie le terme. Les traductions validées sont conservées et celles dont la référence change sont marquées à revoir.

Le premier arbitrage est clos : conserver le comportement actuel de la référence source, sans ajouter un circuit de propositions ou un mécanisme d'arbitrage entre sources dans ce chantier. Conserver un terme existant ne signifie pas ignorer les nouvelles traductions : elles suivent le workflow de propositions. Le choix de la première fiche lors de la consolidation ne fige pas toutes ses valeurs pour les imports futurs.

La vérification du code distingue les cas suivants :

- La clé n'a pas de parcours de modification et n'est pas une colonne remplaçable à l'export. Une clé différente dans un import désigne une autre identité.
- Le libellé source affiché est lu depuis le CSV original associé au terme et ne possède pas de champ d'édition dédié. L'importeur peut toutefois changer la source courante et sa référence lors d'un import admissible.
- La langue `de` est autorisée dans les propositions manuelles selon les droits. Après validation, l'export peut remplacer la colonne allemande qui sert de référence, notamment `de_CH` pour les formats NLS. Cela ne réécrit pas l'original ni directement la référence affichée dans la fiche.
- L'importeur exclut actuellement `de` de la création automatique de propositions. Ne pas assimiler cette exclusion à une interdiction des propositions manuelles allemandes.

L'utilisateur considère le scénario de divergence de référence hors du besoin retenu. Cela ne constitue pas une demande d'interdire l'allemand ou de modifier les clés. Le traitement des réimports dont la valeur est déjà validée ou déjà proposée doit rester cohérent avec la déduplication de l'existant ; aucune demande explicite de supprimer cette déduplication n'a été formulée.

Les collisions ambiguës à l'intérieur d'un CSV restent soumises aux contrôles DEVCONF. Les métadonnées techniques propres aux fichiers ne doivent pas créer de variantes de traduction.

### Exports et publications

[DevconfExportService](../../app/Services/DevconfExportService.php) mutualise la sélection et les contrôles des sources pour les exports simples et les publications. Il privilégie la source de l'organisation destinataire, puis la source éditeur. Il bloque actuellement les périmètres non confirmés, étrangers au destinataire et les lignes attendues absentes de la source.

L'export réunit tous les termes connus du type sélectionné, quel que soit leur fichier d'origine. Un fichier d'origine de 1 000 lignes ne limite pas un catalogue qui contient 1 200 termes de ce type. L'organisation destinataire ne limite pas les lignes à exporter.

Cette décision exige un export construit depuis les termes communs, avec résolution de la source technique de chaque ligne. Adapter `DevconfExportService`, `SimpleExportService`, `PublicationService` et le parcours de `RawExporter`, actuellement construit autour d'un fichier source unique. Un terme doit apparaître une seule fois malgré ses présences dans plusieurs imports ; les identités ambiguës restent bloquantes.

L'export conserve la valeur importée lorsque la cellule n'a pas de traduction validée. Cette valeur est un repli, pas une validation. La publication possède une sélection distincte : elle contient les termes ayant une traduction validée, plutôt que systématiquement tous les termes connus.

La publication filtre les termes selon leur validation, sans limiter leur provenance à une organisation ou à un fichier. Un terme validé en français et non validé en italien est inclus, avec la valeur française validée et une cellule italienne vide. L'absence de validation dans une autre langue ne suffit pas à exclure le terme entier.

La validation étant stockée par attribut et langue, appliquer les valeurs validées aux cellules cibles correspondantes et laisser vides les cellules cibles sans validation. Un terme sans aucune traduction validée dans les langues de publication est exclu. La publication n'utilise ni repli importé pour ces cellules ni correction en attente. Préserver les champs de référence source et les colonnes techniques exigés par le format DEVCONF. Les anciennes publications restent figées et ne sont pas retraitées.

Utiliser comme base les références de provenance du terme ou de sa révision (`source_import_id`, `source_offset`, `source_length`) pour retrouver sa ligne technique. La sélection doit être reproductible et indépendante de l'organisation destinataire. Vérifier les sources et la compatibilité des formats ; écrire un seul en-tête pour le type et prévoir un ordre stable des lignes. Conserver la représentation brute des cellules inchangées et les règles CSV pour les cellules remplacées, y compris guillemets facultatifs et retours à la ligne internes.

La couverture de l'export s'évalue désormais contre l'ensemble des termes à exporter ; celle de la publication s'évalue contre son ensemble de termes validés éligibles. L'exclusion explicite d'un terme non validé de la publication n'est pas une omission de source. L'absence d'un terme dans un fichier n'est pas un blocage si sa source technique existe dans un autre import admissible. En revanche, un terme attendu sans ligne source vérifiable bloque la génération ; ne pas l'omettre ni inventer ses données techniques. Conserver les contrôles d'original, d'empreinte, de format, de position, d'identité exacte, de révision disponible, d'encodage et de placeholders. Le drapeau historique `is_complete` reste inutilisé.

Les manifestes doivent représenter les différentes sources du fichier et la provenance de chaque ligne, plutôt qu'un unique import source par type et destinataire. Adapter les estimations de quota, la couverture par langue, les lectures par lots et les scénarios de reprise à la génération depuis plusieurs sources. Les droits des destinataires et l'immuabilité des publications restent nécessaires ; les destinataires ne définissent plus une sélection de termes propre à leur organisation.

La question 2 tranche l'origine des termes, sans définir de nouvelles règles de version ni le traitement des termes obsolètes. Ne pas déduire une exclusion supplémentaire par provenance ou organisation. La question 3 et sa précision distinguent l'export avec repli importé et la publication des termes au moins partiellement validés, avec cellules cibles non validées vides.

### Interface et recherche

[TermController](../../app/Http/Controllers/TermController.php), [les routes](../../routes/web.php), [les vues de termes](../../resources/views/terms) et [les filtres](../../resources/views/partials/term-filters.blade.php) exposent encore la portée, le choix Commun ou organisation, la confirmation de périmètre et la route `terms.scope`.

Supprimer ces fonctions et les restrictions de lecture fondées sur le propriétaire. Dans la validation, remplacer le filtre de propriétaire par une organisation d'origine explicitement nommée, en couvrant toutes les provenances.

Adapter les traductions d'interface en DE, FR et IT, les compteurs du tableau de bord, les résumés quotidiens et les requêtes du catalogue. `TermController` impose certains index SQL, dont `terms_organization_id_index` : coordonner la modification des requêtes et des index. Conserver les clés de traduction nécessaires à la lecture d'anciens événements ou messages stockés.

### Schéma et compatibilité historique

À conserver : les organisations et leurs liens aux utilisateurs, imports, propositions, audit et destinataires des publications.

À retirer du modèle courant après transition : `terms.organization_id`, `scope_unconfirmed`, `scope_overridden` et les comportements de `CommonScope`. Examiner le devenir de l'ancienne `identity_hash` séparément des empreintes historiques des présences.

Les anciennes révisions conservent leur `metadata.organization_id` et leur origine `scope` comme informations historiques. Les nouveaux états du terme ne doivent plus utiliser ces métadonnées pour définir une propriété active.

La migration [require_unresolved_prefix_scope_confirmation](../../database/migrations/2026_09_21_220000_require_unresolved_prefix_scope_confirmation.php) appelle encore `FormatDefinition::UNCONFIRMED_SCOPE_PREFIXES` et `suggestedScope()`. Garder une compatibilité pour les migrations historiques tant qu'elles en dépendent ; supprimer seulement leur utilisation métier active. Vérifier une installation neuve et une mise à niveau. Ne pas réécrire arbitrairement les migrations déjà appliquées.

## Arbitrages actés

| Sujet | Décision utilisateur |
| --- | --- |
| Référence commune | Clos à la demande de l'utilisateur : conserver le comportement actuel du libellé source, sans mécanisme d'arbitrage supplémentaire. Une même clé retrouve le terme existant et les nouvelles traductions importées deviennent des propositions. Ne pas déduire un gel de toutes les références de la formule « garder le premier enregistrement ». L'outil n'est pas encore en production. |
| Couverture d'export | Acté, option B : exporter tous les termes connus du type sélectionné, quel que soit leur fichier d'origine. Construire le fichier depuis plusieurs sources techniques, avec contrôle de couverture et de provenance par terme. |
| Valeurs sans validation | Acté : option A pour l'export, repli sur la valeur importée ; option B pour la publication partielle, inclure le terme et ses traductions validées, avec cellules cibles non validées vides. |
| Identités existantes multiples | Acté, option A : pour le même type, la même clé exacte et les mêmes références, conserver la première fiche comme base avec ses valeurs validées ; les autres valeurs divergentes deviennent des propositions. Préserver les historiques et provenances. |

Les quatre arbitrages sont clos. Les décisions imposent un référentiel commun, l'export complet avec repli importé, la publication des termes au moins partiellement validés avec cellules cibles non validées vides, et la première fiche comme base de consolidation des doublons compatibles.

## Diagnostic et migration des données

Préparer une commande de diagnostic à blanc, avec lectures par lots et rapport privé. Elle regroupe les candidats par empreinte puis compare exactement leur type et leur clé. Inclure les empreintes historiques manquantes dans les vérifications.

Ce diagnostic et la consolidation sont conditionnels à la présence de données à reprendre. Si la base concernée est vide, appliquer directement le modèle commun sans construire une reprise d'historiques inexistants. L'absence de production n'autorise pas à supprimer les données locales ou les originaux.

| Cas | Traitement |
| --- | --- |
| Un terme pour une identité | Retirer son propriétaire en conservant son identifiant et son historique. |
| Plusieurs termes compatibles | Conserver la première fiche comme base après vérification du type, de la clé exacte, des références et des liens. |
| Valeurs validées divergentes sur des fiches compatibles | Conserver les valeurs validées de la première fiche ; créer ou réutiliser des propositions pour les autres valeurs divergentes, avec leurs provenances. |
| Références ou sens incompatibles | Blocage et clarification de l'identité ; aucune fusion automatique. |

Recenser les liens aux présences d'import, propositions, liens `proposal_imports`, révisions, propositions restaurées ou remplacées, audit et marqueurs de publication. Vérifier aussi les projections `term_search` et `term_states`.

La première fiche doit être déterminée par un ordre de création stable, avec départage par identifiant, et son identifiant reste celui du terme commun. Ne pas remplacer ses valeurs validées par celles d'une autre fiche lors de la consolidation. Les valeurs divergentes reprises sont de nouvelles propositions en attente, et non des validations automatiques. Réutiliser une proposition identique en attente lorsque cela préserve les provenances et évite les doublons.

Conserver les anciennes décisions, révisions et valeurs dans leurs lignées historiques. La création d'une proposition de reprise ne réécrit pas rétroactivement le statut des anciennes décisions de validation. Tracer le lien entre la nouvelle proposition, l'ancienne fiche et l'origine de sa valeur.

Ce choix s'applique aux doublons compatibles déjà présents dans la base, pas aux collisions ambiguës de lignes à l'intérieur d'un CSV. Une incompatibilité de référence ou de sens reste bloquante ; ne pas appliquer la règle de la première fiche pour contourner les contrôles DEVCONF.

Les révisions sont uniques par `(term_id, number)`. Rattacher toutes les révisions à un identifiant unique peut provoquer des collisions et falsifier la lecture historique. Définir une correspondance entre anciens identifiants et terme commun, avec conservation des lignées historiques. Ne pas concaténer ou renuméroter silencieusement les révisions. Ne pas modifier les fichiers et manifestes des publications figées.

La bascule doit refuser les groupes ambigus non résolus. Elle doit pouvoir reprendre après interruption et produire des compteurs vérifiables, sans valeurs métier dans les journaux publics.

## Plan de mise en œuvre

### Étape 1 Formaliser le contrat

Mettre à jour les spécifications fonctionnelles et techniques ainsi que l'annexe DEVCONF avec les décisions actées : modèle commun, droits, provenance, export complet avec repli, publication des traductions validées et reprise des doublons compatibles depuis la première fiche. Conserver le comportement actuel de la référence. Aligner le cadrage des groupes et campagnes sur les termes communs, sans développer cette fonctionnalité dans le cadre de ce chantier.

Livrable : règles explicites et scénarios d'acceptation, notamment pour deux organisations important la même identité.

### Étape 2 Préparer le diagnostic

Construire la commande à blanc et ses tests avec des données synthétiques. Produire un inventaire privé des identités multiples, références et traductions divergentes, ainsi que des liens à conserver. Mesurer le volume avant d'estimer la durée de reprise.

Livrable : rapport de compatibilité, groupes à consolider selon la règle actée et cas ambigus à bloquer. Aucun terme n'est fusionné à ce stade.

### Étape 3 Construire la transition des données

Ajouter de nouvelles migrations et un traitement de reprise contrôlé, avec correspondances d'identifiants, conservation des historiques et protection contre les ambiguïtés. Pour les doublons compatibles, garder la première fiche et ses valeurs validées, puis reprendre les autres valeurs divergentes comme propositions avec leur provenance. Garder temporairement les anciennes colonnes pour la transition. Tester les interruptions et reprises, y compris les opérations de schéma partiellement réalisées sur MariaDB.

Livrable : migration répétable et vérifications des liens, valeurs, révisions et publications.

### Étape 4 Modifier identité imports et droits

Utiliser la sélection globale par identité exacte. Retirer l'attribution du propriétaire par préfixe et les préférences de variantes par source. Adapter les autorisations et les appels qui transmettent encore un propriétaire. Préserver les propositions, les provenances multiples, les verrous de concurrence et le comportement existant de mise à jour de la référence.

Livrable : un seul terme courant pour une identité exacte, avec contributions et décisions communes.

### Étape 5 Adapter exports filtres et écrans

Construire l'export depuis tous les termes connus du type, avec valeurs communes validées et repli importé pour les cellules non validées. Construire les nouvelles publications depuis les termes ayant une traduction validée, même partiellement, en laissant vides les cellules cibles sans validation. Résoudre la source technique de chaque terme et adapter le moteur de génération depuis plusieurs sources, les manifestes, la couverture et les estimations de quota. Conserver les blocages d'intégrité et d'identité ainsi que le contrat de représentation brute des cellules inchangées.

Supprimer la route et le formulaire de périmètre ainsi que les colonnes et filtres correspondants. Harmoniser les filtres d'origine et d'import avec les liens multiples. Adapter les index, les compteurs, les résumés, les traductions d'interface et les scripts de recette ou benchmark dépendants des anciennes colonnes.

Livrable : interface sans notion de propriétaire, export complet par type et publication des termes validés, avec provenance par ligne.

### Étape 6 Vérifier la bascule et nettoyer

Réaliser les tests, l'installation neuve, la mise à niveau et une répétition sur une copie privée cohérente. Coordonner la bascule avec le traitement des opérations en attente, les verrous et les écritures. Incrémenter la génération du catalogue et invalider les anciens aperçus d'import ou de validation dont les règles ont changé.

Déployer un ensemble cohérent : ne pas activer la sélection globale avec des données encore ambiguës ou des exports utilisant l'ancien calcul de couverture. Après vérification, supprimer les colonnes et dépendances inutilisées. Préparer un retour arrière à partir d'une sauvegarde cohérente ; une simple migration `down()` ne suffit pas à annuler un regroupement d'historiques.

Livrable : procédure de bascule vérifiée et schéma courant sans propriétaire de terme. Cette passation ne demande aucun déploiement sur une infrastructure existante.

## Tests et critères d'acceptation

Adapter notamment les tests de `ScopeIdentityTest`, `ImportRecoveryTest`, `WorkflowImportTest`, `CatalogueSearchTest`, `ExportTest` et `PublicationTest`. Transformer les scénarios de variantes privées en scénarios d'identité commune. Conserver les tests de clés exactes, collisions et intégrité. Compléter les tests de migration et de provenance multiple.

Les vérifications doivent démontrer que :

- deux organisations important la même identité retrouvent le même terme ;
- leurs valeurs différentes créent des propositions concurrentes, sans variante validée organisationnelle ;
- une validation devient la valeur commune utilisée par les exports concernés ;
- des propositions identiques provenant de plusieurs imports conservent toutes leurs provenances, sans double comptage dans les filtres ;
- une reprise de proposition et un changement d'organisation du compte préservent l'origine historique ;
- les langues et rôles restent contrôlés côté serveur, y compris lors des opérations de masse ;
- les clés distinctes par casse ou espaces et les collisions d'empreinte ne sont pas fusionnées ;
- les ambiguïtés DEVCONF, les sources altérées ou incomplètes et les placeholders incompatibles restent bloquants ;
- 1 200 termes connus sont exportés même si un des fichiers d'origine ne contient que 1 000 lignes ; les autres lignes sont résolues depuis leurs sources respectives ;
- une cellule sans traduction validée conserve sa valeur importée dans l'export, sans validation automatique et sans utiliser une correction manuelle en attente ;
- les nouvelles publications incluent les termes ayant une traduction validée, avec une sélection indépendante de l'organisation ou du fichier d'origine ;
- un terme validé en français mais pas en italien figure dans la publication avec sa valeur française validée et une cellule italienne vide ; un terme sans traduction validée est exclu ;
- les cellules cibles sans validation sont vides dans la publication, sans repli importé ni proposition en attente, tout en préservant les champs source et techniques du format ;
- plusieurs présences d'une même identité ne créent pas de ligne supplémentaire ; une source technique manquante ou une identité ambiguë bloque le fichier plutôt que de tronquer le catalogue ;
- l'export contient un seul en-tête, respecte un ordre stable et conserve les cellules techniques et la représentation brute des cellules inchangées de chaque source ;
- les manifestes retracent les sources techniques par ligne et les révisions utilisées ; l'organisation destinataire ne filtre pas les termes ;
- une mise à jour de la référence conserve les traductions validées et leur marquage à revoir selon le comportement existant ;
- les publications existantes conservent exactement leurs fichiers et manifestes ;
- la consolidation de fiches compatibles conserve l'identifiant et les valeurs validées de la première fiche ; les valeurs divergentes des autres fiches sont soumises comme propositions avec leur provenance, sans réécriture des anciennes décisions ;
- une nouvelle exécution de la reprise ne crée pas une seconde proposition identique ni une seconde correspondance pour le même doublon ;
- la migration refuse les ambiguïtés, reprend après interruption et conserve les liens et historiques ;
- l'installation neuve et la mise à niveau fonctionnent, et les requêtes du catalogue n'imposent aucun index supprimé.

Exécuter les contrôles du dépôt avec les runtimes disponibles :

```text
php artisan test
node --test tests/JavaScript/*.test.cjs
php scripts/test_devconf_roundtrip.php --validate-semantics
```

Les tests d'extension ne sont nécessaires que si son code est modifié : `cd extension` puis `npm run check`. L'extension reste hors des livraisons Laravel. Aucun Node ni Redis n'est requis en production.

## Contraintes du dépôt

Relire [AGENTS.md](../../AGENTS.md), les spécifications à la racine et [formats-devconf.md](formats-devconf.md) avant la mise en œuvre. [DESIGN.md](../../DESIGN.md) reste la référence visuelle.

Préserver `data/imports`, les originaux et les fichiers figés. Les imports créent des propositions ; seules les décisions autorisées valident. Ne jamais fusionner les identités ambiguës ni contourner les blocages du §9 technique. Le retrait de la propriété ne résout pas la collision exacte Incident code ni les questions de sensibilité des clés dans myABI.

Les données réelles, secrets, identifiants d'infrastructure, originaux et rapports restent hors du dépôt public, de `public` et des livraisons. Utiliser des données synthétiques dans les tests et des valeurs d'exemple dans les documents publics. Les rapports de diagnostic et de reprise restent privés.

## Références pour la reprise

- [Spécifications fonctionnelles](../../specifications-fonctionnelles-referentiel-traductions-arge-abi.md).
- [Spécifications techniques et §9](../../specifications-techniques-referentiel-traductions-arge-abi.md).
- [Formats DEVCONF](formats-devconf.md).
- [PRD de l'existant](../fonctionnel/PRD.md) et documents fonctionnels associés.
- [Groupes et campagnes en cours de cadrage](../fonctionnel/groupes-et-campagnes-traduction.md).
- [Schéma initial du catalogue](../../database/migrations/2026_09_21_100000_create_catalogue_tables.php) et migrations suivantes.
- [SourceRecord et révisions](../../app/Services/SourceRecord.php).
- [Validation initiale](../../app/Services/InitialValidationService.php), [validation filtrée](../../app/Services/FilteredValidationService.php) et [publications](../../app/Services/PublicationService.php).

## Message de reprise suggéré

> Lis `AGENTS.md` et `docs/technique/passation-termes-communs.md`, puis reprends le chantier du référentiel commun en appliquant les arbitrages actés. Préserve les organisations pour les comptes et la provenance ; supprime la propriété organisationnelle des termes. L'export inclut tous les termes du type avec repli importé. La publication inclut les termes ayant une traduction validée et laisse vides les cellules cibles non validées. Pour les doublons compatibles existants, conserve la première fiche et reprends les autres valeurs divergentes comme propositions en préservant les historiques. Commence par vérifier l'état du dépôt, puis prépare le diagnostic à blanc et la transition selon les données réellement présentes. L'outil n'est pas en production. Préserve les originaux, publications et blocages DEVCONF. Aucun déploiement n'est demandé.
