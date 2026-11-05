# Recherche approximative dans Traductions

Date : 5 novembre 2026.

Statut : périmètre fonctionnel et disposition de la maquette validés. La réalisation et le calibrage du moteur restent à définir.

La recherche approximative aide à retrouver un terme malgré une faute de frappe ou un libellé légèrement différent. Elle complète la recherche de la page **Traductions**, dans le cadre visuel de [DESIGN.md](../../DESIGN.md).

## Décisions validées

| Sujet | Décision |
| --- | --- |
| Usages | Tolérer les fautes de frappe et retrouver des libellés proches. |
| Activation | Option **Recherche approximative** près du champ de recherche, désactivée par défaut. |
| Tolérance | Niveau équilibré, sans réglage utilisateur ni seuil en pourcentage. |
| Longueur minimale | Pour une saisie de 1 ou 2 caractères, seules les correspondances habituelles sont affichées. L’élargissement commence à 3 caractères, avec une aide près du champ. |
| Guillemets | Une expression entre guillemets conserve la recherche exacte, même lorsque l’option approximative est cochée. |
| Changement de mode | Cocher l’option approximative revient au mode Texte ; choisir Début de clé ou Expression régulière désactive automatiquement l’option. |
| Textes recherchés | Référence allemande, valeurs en vigueur et propositions en attente de validation dans la langue cible sélectionnée. |
| Présentation | Tableau actuel unique, avec les correspondances habituelles d’abord, puis les résultats proches par pertinence. |
| Explication | Texte correspondant surligné, badge **Approximative** pour un résultat élargi et origine de la correspondance identifiable. |
| Valeur non affichée | Extrait dans la ligne avec le nom du champ, le texte trouvé surligné et un lien **Voir la correspondance** vers la fiche. |

Le périmètre vise une ressemblance entre les textes. Par exemple, « dosier » peut retrouver « dossier », et « ouvrir dossier » peut retrouver « Ouvrir un dossier » ou « Ouverture du dossier ». Une proximité de sens seule, comme entre « voiture » et « véhicule », ne constitue pas une correspondance attendue.

## Parcours retenu

1. L’utilisateur choisit sa langue cible et saisit sa recherche dans le champ existant.
2. Il coche **Recherche approximative** pour élargir la recherche.
3. Il applique la recherche et les filtres avec **Filtrer**.
4. Le tableau affiche les résultats et distingue les correspondances habituelles des résultats approximatifs.
5. **Réinitialiser** efface les critères et désactive l’option, en conservant la langue cible.

Une correspondance habituelle est retrouvée sans tolérance supplémentaire ; elle ne signifie pas nécessairement que le texte recherché est égal au libellé complet. En mode approximatif, les deux catégories respectent le périmètre de langue et de contenus défini ci-dessus.

Les guillemets imposent la formulation et l’ordre des mots, selon les règles de recherche exacte existantes. Par exemple, `"ouvrir dossier"` ne doit pas être élargi à « Ouvrir un dossier » ou « Ouverture du dossier ». L’option peut rester cochée ; une indication près du champ précise que les guillemets imposent la recherche exacte pour cette saisie. Le périmètre de contenus et de langue du mode sélectionné reste applicable.

Pour une saisie de 1 ou 2 caractères hors espaces, la recherche reste limitée aux correspondances habituelles, même si l’option est cochée. Une aide indique que l’élargissement approximatif commence à 3 caractères. Ce minimum concerne la saisie complète ; les règles de rapprochement de chaque mot dans une phrase restent à calibrer. Une saisie vide conserve la consultation du catalogue selon les filtres.

Les modes restent exclusifs. Cocher **Recherche approximative** sélectionne automatiquement **Texte** dans les filtres complémentaires. Choisir **Début de clé** ou **Expression régulière** décoche automatiquement l’option approximative. Revenir à **Texte** seul ne réactive pas cette option. Le bouton **Filtrer** applique les critères. L’accès au mode Expression régulière reste réservé au Gestionnaire selon les droits existants.

La maquette est présentée avec l’option activée pour illustrer les résultats. Cet état de démonstration ne change pas le défaut désactivé du produit.

## Contenus et résultats

La recherche approximative porte sur la référence allemande et sur les valeurs en vigueur et propositions **en attente** de la langue cible. Les autres langues, les anciennes propositions rejetées et les révisions historiques ne doivent pas fournir de correspondances approximatives. Les clés techniques et le contexte restent utilisables dans leurs modes et filtres existants.

Chaque terme apparaît une seule fois, même si plusieurs de ses valeurs correspondent. Le résultat indique si la ressemblance provient de la **référence DE**, de la **valeur en vigueur** ou d’une **proposition** dans la langue cible. Le surlignage concerne le texte qui justifie la correspondance. Le badge **Approximative** décrit la recherche et reste distinct de l’état métier du terme ou de la proposition.

Lorsque la correspondance vient d’un attribut secondaire ou d’une autre proposition encore en attente, la ligne conserve ses valeurs habituelles et ajoute un extrait dans la colonne concernée. Cet extrait précise le nom du champ, la nature de la valeur et sa langue, surligne le texte retrouvé et propose **Voir la correspondance** pour ouvrir la fiche au bon emplacement. Une proposition affichée par défaut n’est pas remplacée par celle retrouvée. L’extrait présente la correspondance principale du terme ; celui-ci reste compté une seule fois.

Les filtres de type, version, état, groupe, campagne et contexte restent cumulatifs. Le classement place les correspondances habituelles avant les résultats approximatifs ; les résultats approximatifs sont classés par pertinence sur l’ensemble des résultats, avant pagination. Le compteur et les pages portent sur les termes distincts qui satisfont les mêmes critères.

La recherche respecte les droits de consultation et le caractère commun du référentiel. Un rapprochement lexical ne modifie aucune valeur, proposition, validation, révision ou identité de terme. Les [règles DEVCONF](../technique/formats-devconf.md), notamment les blocages d’identité ambiguë, continuent de s’appliquer.

## Critères d’acceptation

Les exemples ci-dessous sont fictifs et constituent le début du corpus de calibration. Leur comportement devra être vérifié sur le moteur réalisé.

| Cas | Résultat attendu |
| --- | --- |
| Première ouverture de Traductions | Recherche approximative désactivée. |
| Activation de l’option depuis Début de clé ou Expression régulière | Le mode revient automatiquement à Texte. |
| Choix de Début de clé ou Expression régulière avec l’option active | L’option approximative est automatiquement décochée. |
| Retour au mode Texte | L’option reste désactivée jusqu’à une nouvelle activation explicite. |
| Saisie de 1 ou 2 caractères avec l’option active | Correspondances habituelles uniquement ; l’aide indique le minimum de 3 caractères. |
| Recherche « dosier » avec l’option active | Retrouve un texte « dossier » dans les contenus et la langue autorisés. |
| Recherche « ouvrir dossier » avec l’option active | Peut retrouver « Ouvrir un dossier » et « Ouverture du dossier », identifiés comme approximatifs. |
| Recherche `"ouvrir dossier"` avec l’option active | Retrouve l’expression exacte, sans les libellés proches. |
| Recherche `"dosier"` avec l’option active | Ne corrige pas la faute en « dossier ». |
| Correspondance habituelle et libellés proches présents | Les correspondances habituelles apparaissent avant les résultats approximatifs, y compris entre les pages. |
| Un terme correspond dans plusieurs valeurs | Une seule ligne et un seul terme compté. |
| Seule une proposition en attente correspond | Le terme est retrouvé et la correspondance est identifiée dans la colonne Proposition. |
| Une proposition en attente non affichée par défaut correspond | La proposition affichée est conservée ; un extrait nommé et surligné et un lien vers la proposition retrouvée sont ajoutés dans la ligne. |
| Un attribut secondaire correspond | Le nom du champ et le texte retrouvé sont affichés dans un extrait de la colonne concernée, avec accès à la fiche. |
| Le texte recherché existe uniquement dans une autre langue cible | Ce texte seul ne fait pas apparaître le terme en mode approximatif. |
| Filtres actifs | Les deux catégories de résultats respectent tous les filtres. |
| Aucun résultat | Le tableau affiche un état vide compréhensible ; l’utilisateur peut ajuster ses critères. |
| Réinitialisation | Recherche et filtres effacés, option désactivée, langue cible conservée. |
| Consultation d’un résultat proche | Les valeurs et leurs états restent inchangés. |

## Cadrage technique

Le catalogue actuel reçoit la recherche, les filtres et la pagination dans [TermController](../../app/Http/Controllers/TermController.php), puis affiche le tableau [terms/index.blade.php](../../resources/views/terms/index.blade.php) et le formulaire [term-filters.blade.php](../../resources/views/partials/term-filters.blade.php).

La projection générale composée par [SearchText](../../app/Services/SearchText.php) concatène des contenus de plusieurs langues et des propositions, sans conserver dans le texte l’origine de chaque correspondance. Elle ne suffit donc pas, à elle seule, à garantir le périmètre par langue et par état de proposition retenu ici. La conception du moteur doit identifier les valeurs par terme, attribut, langue et nature, et évaluer les projections existantes avant d’ajouter un stockage.

L’extension possède déjà une [projection par valeur](../../app/Services/BrowserExtension/SearchProjection.php), une [normalisation des textes](../../app/Services/BrowserExtension/TextNormalizer.php) et un [service de recherche](../../app/Services/BrowserExtension/SearchService.php). La projection distingue la référence, la traduction importée et la valeur validée, mais n’indexe pas les propositions en attente et écarte les termes obsolètes. Le service sélectionne des candidats par préfixe et mots communs avec des limites propres à l’extension. Il ne peut donc pas être utilisé tel quel pour le catalogue, son périmètre de langues, ses propositions, ses filtres et sa pagination.

La réalisation proposée consiste à réutiliser les composants de normalisation compatibles et à concevoir une recherche du catalogue qui conserve, pour chaque valeur, son terme, son attribut, sa langue et son origine. Une proposition doit aussi être identifiable pour ouvrir la bonne correspondance et vérifier qu’elle est toujours en attente. Les valeurs admissibles et leur indexation doivent suivre les créations, corrections, décisions et imports sans créer de révision métier supplémentaire.

La sélection des candidats doit permettre de retrouver un mot isolé mal orthographié, même sans mot commun exact, puis classer les ressemblances de caractères et de mots. Le corpus doit aussi couvrir les variations de libellés comme « ouvrir » et « ouverture ». La normalisation doit conserver un moyen de surligner le texte original, notamment avec les accents et les caractères Unicode. Ces mécanismes et leurs seuils sont à évaluer dans le cadre de la calibration, sans modifier le contrat de l’extension.

Le traitement devra appliquer les droits et les filtres, rechercher et classer les correspondances, regrouper les résultats par terme, puis paginer de manière stable. La pagination ne doit pas limiter la recherche aux lignes déjà visibles. Si une limite technique réduit les candidats, elle ne peut pas être présentée comme un total exhaustif sans règle produit explicite.

La réalisation reste dans la pile Laravel, PHP et MariaDB du projet, sans besoin de Node ni de Redis en production. Pour la recherche approximative, la cible validée est désormais de cinq secondes au plus dans 95 % des essais, avec cinq recherches simultanées, un plafond de calcul de quinze secondes et un repli sur les correspondances habituelles en cas de dépassement. La [conception technique](../technique/recherche-approximative-conception.md) précise ces décisions et le protocole de mesure. L’objectif habituel inférieur à deux secondes des [spécifications techniques](../../specifications-techniques-referentiel-traductions-arge-abi.md) reste applicable à la recherche habituelle.

## Conception du moteur

La [fiche technique](../technique/recherche-approximative-conception.md) rassemble les règles de calibrage validées : priorité à la pertinence, tolérance adaptée à la longueur des mots, correspondance de tous les mots importants et ordre souple avec préférence pour l’ordre saisi. Elle distingue les décisions validées, le protocole d’essai proposé et les choix de moteur encore à préciser.
