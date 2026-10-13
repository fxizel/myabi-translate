# Personas fonctionnels de myABI Translate

État au 13 octobre 2026, code de référence `346df90`. Ces personas sont des profils d’usage déduits des rôles implémentés. Ils ne décrivent pas des personnes interrogées et n’ajoutent ni données démographiques, ni fréquence d’usage, ni besoins supposés. Voir le [PRD](PRD.md) pour le périmètre du produit.

## P01 Lecteur du référentiel

**Rôle applicatif :** `reader`.

**But couvert :** retrouver les traductions accessibles et récupérer un catalogue publié pour son organisation.

**Actions disponibles :** consulter le tableau de bord, rechercher et filtrer le catalogue, ouvrir un terme, lire ses valeurs importées, propositions, révisions et historique ; consulter les publications destinées à son organisation et télécharger leurs archives. Il dispose aussi du profil et de l’aide.

**Périmètre :** termes communs et termes de son organisation. Il peut consulter les langues proposées par le catalogue ; les langues associées au rôle Lecteur ne constituent pas un filtre de lecture dans le code actuel.

**Limites :** ce rôle seul ne permet ni proposition, ni validation, ni import, ni export simple, ni administration. Une publication destinée exclusivement à une autre organisation n’est pas accessible.

**Parcours concernés :** UC-03, UC-04, UC-17, UC-21 ; UX-02, UX-03, UX-10.

## P02 Traducteur contributeur

**Rôle applicatif :** `translator`, avec une ou plusieurs langues.

**But couvert :** soumettre une traduction ou corriger une proposition dans son périmètre autorisé.

**Actions disponibles :** consultation du référentiel ; saisie depuis le catalogue ou la fiche ; envoi de plusieurs lignes sélectionnées ; correction de ses propositions encore en attente ; prise en charge d’une proposition importée non encore revendiquée ; nouvelle proposition liée à un rejet ; reprise d’une valeur d’ancienne révision comme proposition.

**Périmètre :** langues attribuées au rôle Traducteur, sur les termes communs ou de son organisation. Il consulte les termes visibles même s’il ne peut pas contribuer dans la langue affichée.

**Retours utiles présents :** référence, valeur validée, propositions, placeholders, compteur de caractères, indication de saisie non envoyée, erreurs de contenu, motif de rejet et résumé quotidien des décisions sur ses propositions si activé.

**Limites :** une saisie ne devient pas une valeur validée. Une proposition en attente appartenant à un autre utilisateur ne lui est pas modifiable, sauf proposition d’import encore non revendiquée. Le catalogue masque la saisie rapide lorsqu’une proposition existe déjà pour l’attribut principal ; la fiche permet de proposer une autre valeur.

**Parcours concernés :** UC-04 à UC-07, UC-11, UC-17, UC-21 ; UX-03, UX-04, UX-10.

## P03 Validateur linguistique

**Rôle applicatif :** `validator`, avec une ou plusieurs langues.

**But couvert :** examiner les valeurs proposées et décider lesquelles deviennent les traductions validées.

**Actions disponibles :** accès à tous les périmètres du catalogue ; proposition et validation dans les langues attribuées au rôle Validateur ; rejet motivé ; décision sur sélection ; validation de tous les résultats filtrés ; confirmation d’une valeur « à revoir » ; consultation des révisions et de l’historique d’un terme.

**Périmètre :** toutes les organisations pour la consultation, langues de validation attribuées pour la contribution et la décision. Les restrictions du rôle Traducteur à sa propre organisation ne limitent pas les langues dans lesquelles ce compte dispose aussi du rôle Validateur.

**Retours utiles présents :** comparaison entre valeur en vigueur et proposition, auteur, ancienneté, anomalies, motif de rejet, comptage des exclusions des traitements filtrés et résumé quotidien des propositions à traiter si activé.

**Limites :** il ne valide pas ses propres propositions. La validation initiale depuis un import, les imports, exports simples, publications et changements de périmètre exigent le rôle Gestionnaire. Les téléchargements de publications restent limités à son organisation, malgré sa visibilité globale du catalogue.

**Parcours concernés :** UC-04 à UC-11, UC-17, UC-21 ; UX-03 à UX-05, UX-10.

## P04 Gestionnaire du référentiel

**Rôle applicatif :** `manager`.

**But couvert :** alimenter et organiser le référentiel, traiter les traductions et produire les fichiers pour les organisations.

**Actions disponibles :** toutes les fonctions de contribution et de décision dans les quatre langues ; imports et rapports ; validation initiale après aperçu ; confirmation ou changement de périmètre ; gestion des versions ; export CSV ; création, téléchargement, retrait et reprise de publications ; consultation et export du journal d’audit.

**Périmètre :** ensemble des termes et organisations. Le rôle Gestionnaire donne les capacités de traduction et validation sans limiter celles-ci aux langues enregistrées dans son tableau de rôles.

**Retours utiles présents :** statistiques du catalogue, imports récents, consommation de stockage, analyse des imports et collisions, opérations en cours, couverture des publications et sommes de contrôle.

**Limites :** la dérogation à l’auto-validation doit être cochée et est auditée. Les blocages de contenu, de source et de périmètre restent applicables. Il ne gère les comptes et organisations que s’il possède aussi le rôle Administrateur.

**Parcours concernés :** UC-04 à UC-18, UC-20, UC-21 ; UX-03 à UX-10, UX-12.

## P05 Administrateur des accès

**Rôle applicatif :** `admin`.

**But couvert :** gérer les personnes, leurs organisations et leurs droits d’accès.

**Actions disponibles :** rechercher les comptes, créer ou modifier un compte, attribuer plusieurs rôles et leurs langues, envoyer ou renouveler une invitation admissible, activer un compte ou définir son mot de passe sans courriel, désactiver un compte, révoquer ses sessions, le déverrouiller ; créer ou modifier une organisation ; consulter et exporter l’audit.

**Périmètre :** administration des comptes non techniques et des organisations. Pour la lecture métier, le rôle seul donne la visibilité des termes communs et de sa propre organisation, ainsi que des publications destinées à celle-ci.

**Retours utiles présents :** statut actif, rôles, organisation, état d’invitation, informations de création et modification, messages de résultat des actions et événements d’audit.

**Limites :** il ne peut pas désactiver l’organisation racine. Les modifications couvertes par la protection du dernier administrateur préservent un accès administratif durable ; les verrouillages temporaires et l’inscription au second facteur en attente ne sont pas considérés comme sa perte. Il lui faut les rôles métier correspondants pour traduire, valider, importer ou publier.

**Parcours concernés :** UC-01 à UC-04, UC-17, UC-19 à UC-21 ; UX-01 à UX-03, UX-10 à UX-12.

## Matrice des capacités par rôle seul

Les droits des rôles cumulés se combinent. Cette matrice suppose un compte actif autorisé à se connecter ; les préconditions propres à chaque action restent applicables.

| Capacité | Lecteur | Traducteur | Validateur | Gestionnaire | Administrateur |
| --- | --- | --- | --- | --- | --- |
| Lire le catalogue | Commun et organisation | Commun et organisation | Tous périmètres | Tous périmètres | Commun et organisation |
| Proposer une valeur | Non | Langues attribuées, commun et organisation | Langues attribuées, tous périmètres | Toutes langues et tous périmètres | Non |
| Corriger une proposition en attente | Non | Selon droits de traduction et auteur | Selon droits de traduction et auteur | Selon droits de traduction et auteur | Non |
| Valider ou rejeter | Non | Non | Langues attribuées | Toutes langues | Non |
| Déroger à l’interdiction de valider sa propre proposition | Non | Non | Non | Oui, choix explicite audité | Non |
| Valider les résultats filtrés | Non | Non | Langues attribuées | Toutes langues | Non |
| Valider initialement un import après aperçu | Non | Non | Non | Oui | Non |
| Importer et consulter les originaux et rapports | Non | Non | Non | Oui | Non |
| Changer le périmètre d’un terme | Non | Non | Non | Oui | Non |
| Gérer les versions et exporter un CSV | Non | Non | Non | Oui | Non |
| Créer ou retirer une publication | Non | Non | Non | Oui | Non |
| Télécharger une publication | Organisation destinataire | Organisation destinataire | Organisation destinataire | Tous destinataires | Organisation destinataire |
| Gérer comptes et organisations | Non | Non | Non | Non | Oui |
| Consulter et exporter l’audit global | Non | Non | Non | Oui | Oui |
| Modifier ses préférences et son mot de passe | Oui | Oui | Oui | Oui | Oui |

## Acteurs automatiques associés

Le compte technique d’import attribue les propositions et révisions issues des fichiers. Il ne constitue pas un persona humain et ne se connecte pas. Le traitement des opérations en attente exécute les imports, validations de masse et publications demandés ; le service de résumé quotidien envoie les compteurs pertinents aux comptes admissibles ayant activé cette préférence.

## Sources

[User et règles de droits](../../app/Models/User.php), [contrôles d’accès aux espaces](../../app/Http/Middleware/RequireRole.php), [périmètre commun](../../app/Services/CommonScope.php), [workflow des propositions](../../app/Services/WorkflowService.php), [administration](../../app/Http/Controllers/AdminController.php), [protection des administrateurs](../../app/Services/AdminAvailability.php), [accès aux publications](../../app/Http/Controllers/PublicationController.php), [résumé quotidien](../../app/Services/DailyDigestService.php).

Contrôles existants associés : [workflow et droits](../../tests/Feature/WorkflowImportTest.php), [sécurité de l’administration](../../tests/Feature/AdminSecurityInvariantTest.php), [publications](../../tests/Feature/PublicationTest.php).
