# Cas d’utilisation de myABI Translate

État au 13 octobre 2026, référentiel commun après la transition du 13.10.2026. Ces cas décrivent les fonctions implémentées dans l’application Laravel. Les acteurs correspondent aux [personas](personas.md) ; les chemins d’écran sont détaillés dans les [parcours UI et UX](parcours-ui-ux.md). Les résultats supposent que les droits et les conditions indiqués sont satisfaits.

L’extension navigateur [MyAB Translation Inspector](../../extension/README.md) retrouve les cellules du catalogue commun via l’API Laravel version 2 et un jeton créé dans le profil. Elle distingue les valeurs importées et validées et ouvre la fiche pour correction, sans modifier MyAB. Le [contrat et la recette](../technique/api-extension-implementation.md) décrivent ce parcours complémentaire ; son feedback facultatif n’est pas une validation de traduction.

## Accès au référentiel

### UC-01 Activer un compte invité

**Acteurs :** Administrateur et titulaire du compte. **Déclencheur :** création d’un compte ou renvoi d’une invitation encore admissible.

1. L’Administrateur renseigne l’identité, le courriel, une organisation active, les rôles et leurs langues.
2. Le système émet une invitation valable 72 heures.
3. Le titulaire ouvre le lien, choisit et confirme un mot de passe conforme à la longueur minimale configurée.
4. Le compte est activé par acceptation de l’invitation, une session est ouverte et le profil est affiché.

**Résultat :** l’acceptation est enregistrée et le lien ne peut plus être réutilisé. Si le second facteur est requis, son paramétrage conditionne ensuite l’accès aux espaces métier.

**Variantes :** une invitation expirée ou invalide est refusée. Un compte déjà accepté ne peut plus être réinvité. L’activation sans courriel relève d’UC-19.

### UC-02 Se connecter et récupérer son accès

**Acteur :** titulaire d’un compte nominatif admissible.

1. Saisir son courriel et son mot de passe.
2. Lorsque le second facteur global est actif et que le compte l’a configuré, fournir le code d’authentification ou un code de récupération.
3. Accéder à l’espace demandé ou au tableau de bord ; un rôle soumis au second facteur et non encore configuré est dirigé vers le profil.

**Résultat :** une session contrôlée permet l’accès aux fonctions du compte. La déconnexion termine cet accès.

**Variantes :** le lien « Mot de passe oublié » permet de demander un courriel puis de choisir un nouveau mot de passe avec confirmation. Les comptes inactifs, techniques, verrouillés, rattachés à une organisation inactive ou à une invitation non acceptée sont empêchés de se connecter. Cinq échecs déclenchent un verrouillage de 15 minutes. Les sessions expirent après une heure d’inactivité ou douze heures au total. La case « Se souvenir de moi » est affichée, mais sa valeur est forcée à faux côté serveur.

### UC-03 Consulter son tableau de bord

**Acteur :** tout utilisateur connecté ayant satisfait aux conditions d’accès.

1. Ouvrir l’accueil.
2. Lire les compteurs de termes, propositions en attente, termes à revoir et propositions anciennes.
3. Ouvrir le catalogue ou, selon les droits, la validation ou les publications.

**Résultat :** accès aux espaces de travail autorisés. Les Gestionnaires voient aussi les cinq imports les plus récents.

**Particularité :** les compteurs n’ont pas tous le même périmètre linguistique : les termes et éléments à revoir suivent la visibilité du catalogue, les propositions suivent aussi les langues de traduction ou de validation du compte. Pour les propositions anciennes, l’accueil utilise la date de création et un seuil de 30 jours.

## Catalogue et contribution

### UC-04 Rechercher un terme et consulter sa fiche

**Acteur :** tout utilisateur connecté. **Précondition :** terme courant du catalogue commun.

1. Ouvrir Traductions et choisir la langue de travail.
2. Rechercher du texte ou combiner les filtres de type, version, langue, état, groupe et contexte.
3. Parcourir les résultats triés et paginés, puis ouvrir une clé ou sa référence.
4. Consulter les attributs, valeurs importées et validées, propositions, présences dans les imports, révisions et historique.

**Résultat :** le terme et sa provenance sont identifiés. Les droits du compte déterminent les actions proposées sur la fiche.

**Variantes :** recherche par préfixe de clé ; recherche entre guillemets d’une séquence dans le texte indexé ; expression régulière pour le Gestionnaire sur MariaDB ou MySQL. Un ancien identifiant consolidé redirige vers la fiche commune, avec accès à sa lignée historique. Le filtre de version sélectionne les termes présents dans des imports appliqués de cette version ; les valeurs affichées restent celles du catalogue courant.

### UC-05 Soumettre une ou plusieurs propositions

**Acteurs :** Traducteur, Validateur ou Gestionnaire disposant du droit de traduction pour la langue et le terme.

1. Saisir une valeur dans la ligne du catalogue ou dans l’attribut de la fiche.
2. Consulter les placeholders et les retours de saisie.
3. Envoyer par « Proposer » ; dans le catalogue, sélectionner plusieurs lignes remplies puis confirmer l’envoi groupé.

**Résultat :** chaque valeur envoyée devient une proposition en attente attribuée à l’utilisateur. La traduction validée reste inchangée. L’envoi groupé accepte au plus 100 lignes et est annulé en bloc si une ligne traitée échoue.

**Exceptions :** contenu vide, caractère non exportable en Windows-1252, placeholders différents ou mauvais nombre d’occurrences, attribut ou langue absent du format, droits insuffisants ou terme modifié depuis l’affichage. Les formulaires de nouvelle proposition sont masqués pour les termes obsolètes. La présence d’une proposition sur l’attribut principal remplace la saisie rapide du catalogue par un lien vers les propositions de la fiche.

### UC-06 Corriger une proposition encore en attente

**Acteurs :** utilisateur autorisé à traduire, auteur de la proposition ou repreneur d’une proposition importée non revendiquée.

1. Ouvrir la fiche et déplier « Corriger » sur la proposition.
2. Modifier la valeur et envoyer.
3. Le système contrôle le contenu et la version examinée de la proposition.

**Résultat :** la proposition reste en attente avec la nouvelle valeur ; l’ancienne valeur, son auteur, son organisation et les imports associés sont conservés dans l’historique ; l’origine ne suit pas les changements d’organisation du compte. Une proposition importée corrigée devient revendiquée par l’utilisateur qui la corrige.

**Exceptions :** proposition déjà décidée, modification concurrente ou proposition appartenant à un autre auteur et déjà revendiquée. La vue affiche les propositions et leur traçabilité, sans écran dédié à l’historique interne de chaque correction.

### UC-07 Reproposer après un rejet

**Acteur :** utilisateur autorisé à traduire le terme dans la langue concernée.

1. Consulter la proposition rejetée et son motif dans la fiche.
2. Déplier la correction du rejet ; la valeur rejetée préremplit l’éditeur.
3. Corriger et envoyer une nouvelle proposition.

**Résultat :** une nouvelle proposition en attente est reliée à la proposition rejetée. Le rejet et son motif restent conservés. Cette action n’exige pas d’être l’auteur de la proposition rejetée, mais reste soumise aux droits de traduction et aux contrôles de contenu.

## Décisions et révisions

### UC-08 Valider ou rejeter une proposition individuelle

**Acteurs :** Validateur dans ses langues ou Gestionnaire. **Précondition :** proposition en attente.

1. Ouvrir la fiche et comparer référence, valeur courante et proposition.
2. Choisir la validation, ou saisir les détails du rejet et sa catégorie puis rejeter.
3. Si le Gestionnaire valide sa propre proposition, cocher explicitement la dérogation.

**Résultat d’une validation :** la valeur devient validée, son marquage « à revoir » est levé, une nouvelle révision est créée et les autres propositions en attente du même terme, attribut et langue sont rejetées comme remplacées.

**Résultat d’un rejet :** la proposition porte le motif et la décision ; la valeur validée ne change pas.

**Exceptions :** état ou version périmé, auto-validation sans dérogation autorisée, contenu non conforme lors de la validation, rejet sans détails. La dérogation n’exige pas de motif textuel dans le code actuel.

### UC-09 Décider une sélection de propositions

**Acteurs :** Validateur ou Gestionnaire, dans les langues autorisées.

1. Ouvrir Validation, choisir une langue et filtrer les propositions.
2. Examiner et cocher les lignes ; choisir Valider ou Rejeter.
3. En cas de rejet, saisir le motif commun ; confirmer l’opération.

**Résultat :** décision appliquée aux 1 à 500 propositions sélectionnées, selon les règles individuelles d’UC-08. Une erreur dans la sélection annule toute cette opération.

**Variantes :** le mode « Tous » charge progressivement les lignes et limite la sélection à 500. La dérogation du Gestionnaire est disponible. Cocher deux propositions concurrentes d’une même cellule peut invalider la sélection, car la première validation rejette la seconde comme remplacée.

### UC-10 Valider tous les résultats filtrés

**Acteurs :** Validateur ou Gestionnaire. **Précondition :** une file filtrée non vide dans une langue autorisée.

1. Examiner les filtres et le nombre total annoncé dans Validation.
2. Cliquer sur la validation de tous les résultats filtrés puis confirmer.
3. Suivre le traitement en arrière-plan et les compteurs de propositions examinées, validées et exclues.

**Résultat :** traitement de l’ensemble filtré examiné, au-delà de la page affichée et de la limite de 500 d’une sélection manuelle. Les nouvelles propositions créées ensuite n’entrent pas dans cet ensemble.

**Exclusions :** contenu invalide, proposition de l’opérateur sans dérogation autorisée, concurrence de plusieurs propositions sur la même cellule, même si une concurrente est hors filtre. Les avertissements de contenu seuls, la divergence et l’obsolescence ne sont pas des exclusions propres à ce mode.

**Exceptions :** confirmation périmée après 30 minutes, changement du catalogue, droits modifiés, autre opération filtrée de l’utilisateur déjà active. Les lots déjà validés restent acquis ; une reprise après échec n’est admise que si les contrôles de cohérence restent satisfaits.

### UC-11 Réexaminer une valeur et exploiter les révisions

**Acteurs :** lecteur autorisé pour la consultation ; contributeur autorisé pour une reprise ; Validateur ou Gestionnaire pour une confirmation.

1. Ouvrir la fiche et consulter la liste des révisions ou choisir deux révisions à comparer.
2. Pour reprendre une ancienne traduction, ouvrir « Restaurer » sur une révision puis envoyer la valeur préremplie comme nouvelle proposition.
3. Pour une valeur marquée « à revoir », un utilisateur autorisé à valider peut la confirmer après examen de la nouvelle référence.

**Résultats distincts :** la comparaison est une consultation ; la reprise crée une proposition en attente, sans rétablissement immédiat de l’ensemble du terme ; la confirmation conserve la valeur actuelle et retire son marquage « à revoir », avec audit et sans nouvelle révision.

**Exceptions :** une confirmation exige une valeur validée, un marquage à revoir, un contenu toujours conforme et un terme non modifié depuis l’affichage.

## Alimentation et production des fichiers

### UC-12 Créer une version myABI ou changer son statut

**Acteur :** Gestionnaire.

1. Ouvrir Publications et renseigner un numéro unique, une date de sortie et un statut : préparation, courante ou archivée.
2. Créer la version ; elle devient disponible pour les imports.
3. Modifier ensuite son statut depuis la liste des versions.

**Résultat :** passer une version à « courante » archive automatiquement toute autre version courante.

**Limites :** la liste ne propose que le changement de statut. La route de modification accepte aussi numéro et date, mais les refuse dès qu’un import est rattaché à cette version. Les sorties nouvelles appliquent également la règle de date décrite dans UC-16.

### UC-13 Analyser puis appliquer un fichier DEVCONF

**Acteur :** Gestionnaire. **Préconditions :** une version créée et un fichier du type choisi.

1. Ouvrir Imports, sélectionner version, type, source éditeur ou organisation et, pour cette dernière, l’organisation.
2. Téléverser le fichier et lancer l’analyse.
3. Consulter progression, compteurs, anomalies et collisions ; au stade analysé, appliquer avec confirmation ou annuler.
4. Après application, ouvrir le catalogue concerné ou télécharger le rapport et l’original.

**Résultat :** les lignes alimentent les termes et leur provenance ; les traductions admissibles créent ou rejoignent des propositions en attente. Les traductions déjà validées ne sont pas remplacées automatiquement.

**Variantes et exceptions :** mauvais format, capacité insuffisante ou erreur de traitement ; catalogue modifié après analyse entraînant une nouvelle analyse ; collision conservée comme blocage des sorties concernées. Un import d’une version plus ancienne conserve des présences sans remplacer les sources courantes des termes existants ni produire de nouvelles propositions. L’absence de lignes ne supprime ni n’obsolétise les termes. Les routes d’annulation et de relance ont des états autorisés précis ; le bouton de relance d’un import échoué n’est pas présent dans sa fiche actuelle.

### UC-14 Valider initialement les propositions d’un import

**Acteur :** Gestionnaire. **Précondition :** import appliqué.

1. Dans sa fiche, choisir une langue et éventuellement la dérogation pour ses propres propositions.
2. Demander le calcul de l’aperçu en arrière-plan.
3. Ouvrir son aperçu prêt ; lire le nombre admissible et les exclusions.
4. Confirmer la validation des propositions admissibles puis suivre les opérations de la fiche.

**Résultat :** validation par lots des propositions de l’import, y compris celles partagées avec un import précédent.

**Exclusions :** erreur ou avertissement de contenu, divergence avec une valeur validée, terme obsolète, propositions concurrentes, auto-validation sans dérogation. L’aperçu est lié à l’utilisateur et au catalogue examiné ; un aperçu périmé ou âgé de plus de 30 minutes ne permet pas de lancer la validation. Une erreur de lot permet une reprise sous les contrôles de cohérence du service.

### UC-15 Ancien changement de propriétaire retiré

Ce parcours historique est retiré depuis le 13.10.2026 : tous les termes sont communs, sans propriétaire. La fiche et la route de modification de portée ont été supprimées. Les anciennes décisions restent lisibles dans l'historique. Les identifiants UC-15 et EF-42 sont conservés pour tracer cette évolution ; la [transition](../technique/transition-termes-communs.md) ne fusionne jamais les groupes ambigus.

### UC-16 Télécharger un export CSV courant

**Acteur :** Gestionnaire.

1. Ouvrir Exports, choisir un type, une version proposée et une organisation active.
2. Demander le téléchargement.
3. Le système parcourt tous les termes courants connus du type et vérifie leur source technique respective, indépendamment du destinataire. Plusieurs imports peuvent alimenter le fichier, avec un en-tête unique et une seule ligne par terme.

**Résultat :** un CSV DEVCONF avec les valeurs validées courantes et les valeurs d’origine pour les cellules non validées. Une proposition en attente n’est pas injectée ; aucune publication n’est créée.

**Exceptions :** version archivée ou datée avant la plus récente version ayant un import appliqué ; source absente, altérée ou incomplète ; collision ; révision indisponible ; traduction validée incompatible avec la référence ; stockage insuffisant ou opération lourde incompatible. Une valeur « à revoir » reste exportable si les contrôles de contenu réussissent.

### UC-17 Consulter et télécharger une publication

**Acteurs :** Gestionnaire, ou utilisateur de l’organisation destinataire.

1. Ouvrir Publications puis une publication accessible.
2. Lire son état, sa note, les fichiers et leur couverture.
3. Télécharger le ZIP du destinataire accessible lorsqu’il est disponible.

**Résultat :** archive contenant les fichiers figés, le catalogue et les références des révisions capturées. Les imports et décisions postérieurs ne changent pas cette archive.

**Variantes :** une publication retirée affiche son motif et reste téléchargeable par le lien de sa fiche, qui transmet explicitement le choix de télécharger un retrait. Une publication en attente, en construction ou en échec ne fournit pas de téléchargement final. L’accès au ZIP d’une autre organisation est refusé aux non-Gestionnaires.

### UC-18 Créer une publication et gérer sa disponibilité

**Acteur :** Gestionnaire.

1. Dans Publications, sélectionner une version et les types, ajouter éventuellement une note, puis confirmer.
2. Le formulaire actuel cible toutes les organisations actives ; suivre la construction en arrière-plan sur la fiche créée.
3. Après publication, retirer éventuellement le catalogue en renseignant un motif et en confirmant.

**Résultat :** une publication numérotée, avec un ZIP par destinataire et une sélection commune des termes au moins partiellement validés. Les cellules cibles validées sont renseignées, les autres vides ; les références et colonnes techniques sont préservées. Un retrait conserve fichiers et historique.

**Exceptions et variantes :** absence de source détectée dès la demande ; autres contrôles de sortie d’UC-16 revérifiés à la construction ; bouton Réessayer après échec. Les valeurs sont figées lors de la construction, pas au clic initial. Le serveur accepte aussi une liste explicite d’organisations destinataires, sans sélecteur correspondant dans le formulaire.

## Administration et suivi

### UC-19 Administrer les comptes et organisations

**Acteur :** Administrateur.

1. Ouvrir Administration, rechercher un compte par nom ou courriel et filtrer par organisation.
2. Créer ou modifier identité, courriel, organisation active, statut, langue d’interface et rôles avec leurs langues.
3. Selon le besoin, envoyer une invitation, activer sans courriel avec un mot de passe confirmé, définir un mot de passe sur un compte existant, révoquer les sessions ou déverrouiller le compte.
4. Créer une organisation active par code et nom ; modifier ensuite son code, son nom ou son statut actif.

**Résultat :** données et droits enregistrés avec audit. La définition administrative du mot de passe invalide invitations et liens de récupération antérieurs, révoque les sessions et lève le verrouillage ; elle ne prouve pas la propriété du courriel.

**Exceptions :** courriel ou code d’organisation déjà utilisé, organisation inactive lors de l’affectation ou de l’activation manuelle, compte technique, modification qui supprimerait le dernier accès administratif durable. Les verrouillages temporaires et l’inscription au second facteur en attente ne comptent pas comme une perte de cet accès. L’organisation racine conserve son état actif.

### UC-20 Consulter et exporter le journal d’audit

**Acteurs :** Gestionnaire ou Administrateur.

1. Ouvrir Audit.
2. Filtrer par dates, utilisateur, action ou type d’entité.
3. Lire les événements et leurs détails ; télécharger le CSV correspondant aux mêmes critères.

**Résultat :** consultation des acteurs, objets, dates et valeurs avant/après enregistrées pour les événements. Les dates de filtre sont interprétées en Europe/Zurich ; l’export comporte des dates UTC.

**Limite :** le journal global exige ces rôles. La consultation de l’historique d’un terme visible et de ses propres actions dans le profil est également disponible aux autres utilisateurs, sans accès global à l’audit.

### UC-21 Gérer son profil et recevoir le résumé quotidien

**Acteur :** tout utilisateur connecté.

1. Ouvrir son profil et consulter identité, organisation, rôles et dernières actions.
2. Choisir la langue d’interface et activer ou désactiver le résumé quotidien.
3. Pour changer son mot de passe, fournir le mot de passe actuel et confirmer le nouveau.
4. Si le second facteur global est actif, démarrer sa configuration avec le mot de passe, lire le QR code ou la clé, confirmer un code à six chiffres et conserver les codes de récupération affichés après confirmation.

**Résultat :** préférences enregistrées ; après changement du mot de passe, la session courante reste ouverte et les autres sessions sont invalidées. Un second facteur obligatoire pour les rôles du compte ne peut pas être désactivé par son titulaire.

**Résumé quotidien :** le planificateur demande l’envoi à 07:00 Europe/Zurich. Les destinataires sont les comptes actifs, non techniques, sans invitation en attente, rattachés à une organisation active et ayant activé le résumé. Un résumé n’est envoyé que si au moins un compteur est non nul, avec au plus un envoi réussi par jour. Les Traducteurs et les utilisateurs autorisés à valider reçoivent les décisions sur leurs propres propositions depuis le dernier envoi réussi ; les Validateurs et Gestionnaires reçoivent aussi les nombres de propositions en attente et anciennes dans leurs langues autorisées. Les Gestionnaires reçoivent en plus les imports appliqués ou échoués et les termes obsolètes modifiés depuis le dernier envoi, ainsi que le nombre courant de termes non obsolètes à revoir. L’exécution dépend du planificateur et du service de courriel. Le compteur d’ancienneté de ce résumé utilise la dernière modification des propositions.

## Traçabilité des cas

| Cas | Sources de comportement | Contrôles existants associés |
| --- | --- | --- |
| UC-01 et UC-02 | [Invitations](../../app/Http/Controllers/AuthInvitationController.php), [authentification](../../app/Providers/FortifyServiceProvider.php), [routes d’accès](../../routes/auth.php) | [AuthSecurityTest](../../tests/Feature/AuthSecurityTest.php), [GlobalMfaTest](../../tests/Feature/GlobalMfaTest.php) |
| UC-03 et UC-04 | [Catalogue](../../app/Http/Controllers/TermController.php), [fiche](../../resources/views/terms/show.blade.php) | [CatalogueSearchTest](../../tests/Feature/CatalogueSearchTest.php), [CatalogueStateTest](../../tests/Feature/CatalogueStateTest.php) |
| UC-05 à UC-09 et UC-11 | [Workflow](../../app/Services/WorkflowService.php), [propositions](../../app/Http/Controllers/ProposalController.php) | [CatalogueProposalTest](../../tests/Feature/CatalogueProposalTest.php), [WorkflowImportTest](../../tests/Feature/WorkflowImportTest.php) |
| UC-10 | [Validation filtrée](../../app/Services/FilteredValidationService.php), [filtres](../../app/Services/ProposalFilter.php) | [FilteredValidationTest](../../tests/Feature/FilteredValidationTest.php), [ValidationPaginationTest](../../tests/Feature/ValidationPaginationTest.php) |
| UC-12 et UC-18 | [Versions](../../app/Http/Controllers/VersionController.php), [publications](../../app/Services/PublicationService.php) | [PublicationTest](../../tests/Feature/PublicationTest.php) |
| UC-13 à UC-15 | [Imports](../../app/Services/ImportService.php), [validation initiale](../../app/Services/InitialValidationService.php), [transition commune](../technique/transition-termes-communs.md) | [WorkflowImportTest](../../tests/Feature/WorkflowImportTest.php), [InitialValidationTest](../../tests/Feature/InitialValidationTest.php), [ScopeIdentityTest](../../tests/Feature/ScopeIdentityTest.php) |
| UC-16 et UC-17 | [Export](../../app/Services/SimpleExportService.php), [contrôles de sortie](../../app/Services/DevconfExportService.php), [téléchargement](../../app/Http/Controllers/PublicationController.php) | [ExportTest](../../tests/Feature/ExportTest.php), [PublicationTest](../../tests/Feature/PublicationTest.php) |
| UC-19 et UC-20 | [Administration](../../app/Http/Controllers/AdminController.php), [audit](../../app/Http/Controllers/AdminAuditController.php) | [AdminAccountActivationTest](../../tests/Feature/AdminAccountActivationTest.php), [AdminSecurityInvariantTest](../../tests/Feature/AdminSecurityInvariantTest.php), [AuditLocalizationTest](../../tests/Feature/AuditLocalizationTest.php) |
| UC-21 | [Profil](../../app/Http/Controllers/ProfileController.php), [résumé](../../app/Services/DailyDigestService.php), [envoi](../../app/Console/Commands/SendDailyDigest.php) | [ProfilePasswordTest](../../tests/Feature/ProfilePasswordTest.php), [SecurityDailyDigestTest](../../tests/Feature/SecurityDailyDigestTest.php) |
