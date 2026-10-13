# Parcours UI et UX de myABI Translate

État au 13 octobre 2026, code de référence `346df90`. Ce document décrit les écrans et interactions implémentés dans les vues Blade et les scripts du navigateur. Il ne constitue pas une maquette cible ni une validation visuelle sur une instance déployée. Les droits sont détaillés dans les [personas](personas.md) et les règles dans les [exigences fonctionnelles](exigences-fonctionnelles.md).

## Organisation des écrans

L’application possède un bandeau d’identité, une bande d’onglets, une navigation latérale, un fil d’Ariane et une zone principale. Le nom du compte mène au profil ; l’icône d’aide ouvre une page expliquant import, traduction, validation et publication. Le bandeau affiche aussi l’organisation du compte, le choix de langue d’interface et la déconnexion.

| Écran | Adresse applicative | Accès et contenu principal |
| --- | --- | --- |
| Connexion | `/login` | Courriel, mot de passe, récupération d’accès. |
| Invitation | `/invitations/{token}` | Choix et confirmation du mot de passe pour l’invitation reçue. |
| Récupération | `/forgot-password`, `/reset-password/{token}` | Demande de lien puis choix du nouveau mot de passe. |
| Défi du second facteur | `/two-factor-challenge` | Code TOTP ou code de récupération lorsque demandé. |
| Tableau de bord | `/` | Compteurs et accès aux espaces de travail. |
| Traductions | `/terms` | Recherche, filtres, tableau du catalogue et saisie rapide. |
| Fiche de terme | `/terms/{term}` | Attributs, propositions, révisions et provenance. |
| Validation | `/validation` | Validateur ou Gestionnaire ; sélection, validation filtrée et suivi. |
| Imports | `/imports` | Gestionnaire ; capacité, téléversement et historique. |
| Fiche d’import | `/imports/{import}` | Gestionnaire ; analyse, application, rapport et opérations initiales. |
| Aperçu de validation initiale | `/imports/{import}/validation-preview` | Gestionnaire auteur de l’aperçu ; admissibilité et exclusions. |
| Exports | `/exports` | Gestionnaire ; téléchargement d’un CSV courant. |
| Publications et versions | `/publications` | Liste accessible au compte ; création et gestion des versions pour le Gestionnaire. |
| Fiche de publication | `/publications/{publication}` | Destinataire autorisé ou Gestionnaire ; état, fichiers et téléchargement. |
| Profil | `/profile` | Préférences, mot de passe, second facteur et actions personnelles. |
| Administration | `/admin` | Administrateur ; comptes et organisations. |
| Audit | `/audit` | Gestionnaire ou Administrateur ; journal et export CSV. |
| Aide | `/help` | Utilisateur connecté ; explication des quatre étapes métier principales. |

La navigation masque les espaces non autorisés. Une fiche ouvre un onglet de détail avec un lien de fermeture ; il s’agit de liens de navigation, sans gestion de plusieurs documents ouverts indépendamment. Depuis une fiche de terme, retour et fermeture conservent la langue, mais ne reconstruisent pas tous les filtres de la liste précédente.

## UX-01 Entrer dans l’application

**Acteurs :** tous les titulaires de comptes. **Cas :** UC-01, UC-02. **Règles :** EF-01, EF-04 à EF-08.

| Étape | Action et écran | Retour de l’application |
| --- | --- | --- |
| Activation par invitation | Ouvrir le lien reçu ; le courriel est affiché en lecture seule ; saisir deux fois le mot de passe. | Acceptation du lien, session ouverte et arrivée au profil. |
| Connexion | Saisir courriel et mot de passe. | Accès à la destination ou demande de second facteur selon le compte et le réglage global. |
| Second facteur | Fournir le code TOTP ou un code de récupération. | Connexion poursuivie ou erreur de code. Un rôle soumis à l’obligation sans configuration est dirigé vers le profil. |
| Mot de passe oublié | Demander le lien depuis la connexion, puis ouvrir le courriel et remplir le formulaire de réinitialisation. | Le nouveau mot de passe passe les contrôles communs de confirmation et de longueur. |
| Session expirée | Revenir sur une page après expiration ou révocation. | Redirection vers la connexion. Le contrôle de sécurité affiche un message de session expirée lorsqu’il détecte l’expiration ou la révocation ; une session déjà absente donne une simple redirection. |

Le sélecteur de langue DE, FR, IT est disponible sur les pages d’accès. La case « Se souvenir de moi » est présente sur le formulaire de connexion, mais le serveur désactive cette option : elle n’accorde pas de session persistante.

## UX-02 Se repérer depuis le tableau de bord

**Acteurs :** tous les utilisateurs connectés. **Cas :** UC-03. **Règle :** EF-09.

L’accueil présente quatre compteurs : termes, propositions en attente, éléments à revoir et propositions anciennes. Deux panneaux conduisent aux traductions et à la validation ; pour un compte sans droit de validation, le second lien conduit aux publications. Le Gestionnaire dispose en complément d’une table des cinq derniers imports et d’un accès au nouvel import.

Les compteurs sont informatifs ; ils ne constituent pas des graphiques interactifs. La version courante, lorsqu’elle existe, apparaît dans le contexte du tableau de bord. Une liste d’imports vide affiche un état vide.

## UX-03 Trouver un terme et comprendre ses valeurs

**Acteurs :** tous les utilisateurs connectés selon leur périmètre. **Cas :** UC-04. **Règles :** EF-10 à EF-17.

1. Dans Traductions, sélectionner une langue de travail, distincte du sélecteur de langue de l’interface.
2. Saisir la recherche et éventuellement choisir type, version ou état, puis cliquer sur Filtrer. Réinitialiser conserve la langue de travail.
3. Déplier les filtres supplémentaires pour périmètre, organisation, contexte, tri, mode de recherche et taille de page.
4. Lire les colonnes clé, référence, valeur actuelle, proposition, état et périmètre ; les badges de type affichent des nombres de résultats et permettent d’affiner le type.
5. Ouvrir une clé ou une référence pour accéder à la fiche et à ses attributs.

Le menu Colonnes masque ou affiche clé, périmètre et état. Les cases de sélection ne sont proposées que pour les lignes admissibles à la saisie groupée. Une cellule sans valeur validée affiche une indication d’absence ; une proposition existante est présentée séparément, avec son nombre et un lien de détail.

Pour les formats à plusieurs attributs, la liste utilise le premier attribut. La fiche possède un panneau par attribut, avec référence, valeur actuelle, état, placeholders et, si autorisé, éditeur. Les valeurs du fichier source et les métadonnées techniques se consultent dans des sections repliables.

La fiche présente aussi les présences dans les versions et imports, les propositions et décisions, les révisions et les événements. Les propositions, révisions et présences disposent de leurs propres liens de pagination. L’historique affiche au plus 30 événements, sans commande de pagination dans la vue. L’accès à l’import lui-même est un lien réservé au Gestionnaire.

## UX-04 Proposer ou corriger une traduction

**Acteurs :** Traducteur, Validateur ou Gestionnaire autorisé pour la cellule. **Cas :** UC-05 à UC-07, UC-11. **Règles :** EF-18 à EF-25, EF-65.

| Situation de départ | Interaction disponible | Résultat après envoi |
| --- | --- | --- |
| Ligne sans proposition sur l’attribut principal | Saisir dans le catalogue, puis Proposer. | Proposition en attente ; valeur validée conservée. |
| Plusieurs lignes admissibles remplies | Cocher les lignes puis Proposer dans la barre groupée et confirmer. | Envoi des valeurs des lignes sélectionnées. |
| Travail sur un autre attribut ou concurrence de propositions | Ouvrir la fiche et utiliser l’éditeur de l’attribut. | Nouvelle proposition ; la fiche permet de consulter les propositions déjà présentes. |
| Proposition en attente modifiable | Déplier Corriger et envoyer la valeur corrigée. | Proposition mise à jour et, si import non revendiqué, prise en charge par le contributeur. |
| Proposition rejetée | Déplier la correction du rejet, ajuster la valeur préremplie et proposer. | Nouvelle proposition liée au rejet. |
| Ancienne révision | Cliquer Restaurer sur une révision, examiner la valeur préremplie et proposer. | Nouvelle proposition liée à la révision, encore soumise à validation. |

L’éditeur affiche un compteur de caractères et un indicateur de saisie non envoyée. Le navigateur contrôle le texte vide et les placeholders à l’envoi individuel, et affiche un avertissement lorsque la longueur dépasse deux fois la référence. Les contrôles serveur, dont l’exportabilité Windows-1252, s’appliquent à tous les envois.

Ctrl + Entrée ou Cmd + Entrée soumet le formulaire de l’éditeur. En cas de rejet du formulaire, la valeur peut être réaffichée comme saisie non envoyée. Quitter la page avec d’autres éditeurs modifiés déclenche l’avertissement du navigateur. Aucun stockage persistant de brouillons n’est associé à cet indicateur.

## UX-05 Examiner et décider les propositions

**Acteurs :** Validateur ou Gestionnaire. **Cas :** UC-08 à UC-11. **Règles :** EF-26 à EF-33.

La file Validation juxtapose référence, valeur validée et proposition, avec auteur, date, anomalies et lien vers le détail. Le script souligne la partie ajoutée et barre la partie retirée entre valeur courante et proposition ; ce repérage est un différentiel textuel simple.

- **Décision individuelle :** ouvrir la fiche ; pour rejeter, saisir les détails et choisir éventuellement la catégorie ; pour valider sa propre proposition, le Gestionnaire dispose d’une case de dérogation. Le bouton de validation de sa propre proposition est désactivé pour un Validateur sans rôle Gestionnaire.
- **Décision sur sélection :** cocher les lignes, choisir Valider ou Rejeter, renseigner le motif commun si nécessaire et confirmer. Le compteur indique le nombre coché.
- **Affichage Tous :** les résultats se chargent progressivement avec un état de chargement et un lien pour charger la suite ; une erreur permet de réessayer. La sélection reste limitée à 500 propositions.
- **Validation des résultats filtrés :** un bloc séparé annonce le total concerné, indépendamment des cases cochées. La confirmation lance le traitement en arrière-plan. Les cinq dernières opérations de l’utilisateur sont présentées avec progression, résultats, motifs d’exclusion et reprise admissible après échec.
- **Confirmation d’une valeur à revoir :** dans le panneau de l’attribut de la fiche, utiliser Confirmer la valeur. Le système conserve le texte et lève le besoin de revue sans créer une nouvelle révision.

Le formulaire partagé affiche un tri et un mode de recherche dans les filtres avancés. Dans Validation, le serveur effectue une recherche textuelle et présente les propositions des plus anciennes aux plus récentes, puis par identifiant à date égale ; ces deux sélecteurs n’y modifient pas la requête. Les langues affichées pour validation suivent les droits du compte.

## UX-06 Analyser et appliquer un import

**Acteur :** Gestionnaire. **Cas :** UC-13. **Règles :** EF-35 à EF-41, EF-67.

L’écran Imports commence par la consommation de stockage, une jauge et un avertissement à partir de 80 % du quota configuré. Le parcours indique trois étapes : paramètres, analyse à blanc, application.

1. Choisir la version, le type DEVCONF, la source et l’organisation lorsqu’elle est requise.
2. Sélectionner le fichier et demander l’analyse. Sans version disponible, le bouton est désactivé ; un lien permet d’aller créer une version dans Publications.
3. Arriver sur la fiche de l’import et suivre son état et la progression.
4. Au stade analysé, lire les compteurs et détails ; choisir Annuler ou Appliquer. L’application demande une confirmation navigateur.
5. Au stade appliqué, ouvrir les traductions filtrées par version et type.

La fiche présente paramètres, auteur, dates, empreinte du fichier, catégories des lignes et filtre des collisions. Les téléchargements Rapport CSV et Original restent accessibles au Gestionnaire. Un échec est affiché dans un message ; aucune action de relance de l’import lui-même n’est rendue dans cette fiche, bien qu’une route de relance existe.

## UX-07 Préparer la validation initiale d’un import

**Acteur :** Gestionnaire. **Cas :** UC-14. **Règle :** EF-34.

1. Dans un import appliqué, choisir la langue et éventuellement la dérogation, puis demander l’aperçu.
2. Dans la liste des opérations de l’import, suivre l’analyse des propositions.
3. Ouvrir son aperçu lorsqu’il est prêt. L’écran montre total, admissibles, exclusions et détail des motifs.
4. Confirmer le démarrage, ou revenir à l’import. Avec zéro proposition admissible, le bouton de démarrage est désactivé.
5. Suivre les compteurs et erreurs dans les opérations de l’import ; utiliser la reprise si elle est proposée et encore autorisée par le serveur.

La confirmation porte sur l’aperçu examiné. Une évolution du catalogue ou l’expiration de l’aperçu demande une nouvelle préparation ; l’écran ne garantit pas que le nombre affiché auparavant soit encore validable après un tel changement.

## UX-08 Gérer une version ou le périmètre d’un terme

**Acteur :** Gestionnaire. **Cas :** UC-12, UC-15. **Règles :** EF-42 à EF-45.

**Version :** dans Publications, le panneau Nouvelle version demande numéro, date de sortie et statut. La table des versions permet ensuite de modifier le statut. Numéro et date y sont affichés sans champ d’édition ; le passage à courante archive les autres versions courantes.

**Périmètre :** dans la fiche de terme, sélectionner Commun ou une organisation, puis enregistrer. L’état « périmètre non confirmé » disparaît si l’enregistrement réussit. Une identité identique dans le périmètre cible ou une modification concurrente produit une erreur et conserve l’état précédent.

## UX-09 Produire un export ou une publication

**Acteur :** Gestionnaire. **Cas :** UC-16, UC-18. **Règles :** EF-45 à EF-53.

| Parcours | Choix et action | Retour visible |
| --- | --- | --- |
| CSV courant | Dans Exports, sélectionner type, version admissible et organisation active, puis Télécharger. | Téléchargement d’un fichier unique, ou message de refus. Sans version ou organisation admissible à proposer, un état indisponible apparaît et le bouton est désactivé. |
| Publication | Dans Publications, choisir version, types cochés et note facultative, puis Publier et confirmer. | Fiche numérotée en attente, puis construction, publication ou échec. Tous les types sont cochés initialement ; après erreur, les choix soumis sont conservés. |
| Reprise de publication | Sur une fiche en échec, cliquer Réessayer et confirmer. | Retour en attente de construction. |
| Retrait | Sur une publication publiée, déplier Retirer, saisir le motif et confirmer. | État retiré et motif visible ; archive conservée. |

Le formulaire de publication ne comporte pas de sélection des organisations : il utilise toutes les organisations actives. Les valeurs ne sont figées qu’au moment de la construction. Les références aux fichiers d’origine, les contrôles de périmètre et la validité des traductions déterminent si la sortie peut aboutir.

## UX-10 Télécharger une publication et gérer son profil

**Acteurs :** utilisateurs connectés, selon le destinataire pour les publications. **Cas :** UC-17, UC-21. **Règles :** EF-51, EF-52, EF-60 à EF-62.

**Publication :** la liste montre numéro, version, note, état, auteur et date. Ouvrir une fiche affiche les archives autorisées, leurs sommes de contrôle et une table des fichiers avec volumes, version de format et couverture par langue. Le Gestionnaire peut voir plusieurs destinataires ; les autres comptes ne voient que leur organisation. Le lien d’une publication retirée transmet explicitement le choix de télécharger les fichiers retirés, sans dialogue supplémentaire de confirmation dans cette vue.

**Profil :** le nom du compte dans le bandeau ouvre identité, courriel, organisation, rôles et traçabilité en lecture. Le formulaire de préférences propose DE, FR ou IT et le résumé quotidien. Le changement de mot de passe utilise trois champs : actuel, nouveau et confirmation. La table Mes actions présente les événements personnels.

**Second facteur :** lorsque désactivé globalement, le profil affiche ce statut. Lorsqu’actif, il présente successivement démarrage avec mot de passe, QR code et clé, confirmation du code, puis état activé. Les codes de récupération apparaissent après confirmation. La désactivation est proposée seulement aux comptes pour lesquels ce facteur est facultatif.

## UX-11 Administrer les accès

**Acteur :** Administrateur. **Cas :** UC-19. **Règles :** EF-54 à EF-58.

L’administration réunit la création de compte, la recherche des utilisateurs et les organisations. Chaque compte se déplie pour afficher son formulaire, ses actions et sa traçabilité.

1. Créer ou rechercher un compte par nom ou courriel, avec filtre d’organisation.
2. Renseigner prénom, nom, courriel, organisation, rôles et langues, langue d’interface et statut.
3. À la création, choisir l’invitation ou cocher l’activation sans courriel et fournir le mot de passe confirmé.
4. Sur un compte existant, déplier l’activation avec mot de passe, ou utiliser Révoquer les sessions, Déverrouiller et, avant acceptation, Renvoyer l’invitation.
5. Dans Organisations, déplier un organisme pour modifier code, nom et état actif ; le formulaire de création demande code et nom.

Les actions sensibles équipées d’un dialogue demandent confirmation dans le navigateur. Les mots de passe ne sont pas réaffichés après une erreur. Le formulaire de compte concerné par une erreur se rouvre et conserve ses autres saisies. Les protections du serveur peuvent refuser l’affectation à une organisation inactive ou une perte du dernier accès administrateur durable.

## UX-12 Examiner le journal et consulter l’aide

**Acteurs :** Gestionnaire ou Administrateur pour l’audit ; utilisateurs connectés pour l’aide. **Cas :** UC-20. **Règles :** EF-59, EF-63.

Dans Audit, renseigner les critères de dates, utilisateur, action et entité, puis filtrer. La liste présente les événements et les valeurs enregistrées avant et après ; l’export CSV reprend les critères. Les dates de l’interface sont affichées en Europe/Zurich, celles de l’export en UTC.

La page Aide possède quatre sections : importer, traduire, valider et publier. Elle rappelle l’envoi explicite des propositions, le principe des quatre yeux et la conservation des publications figées.

## Retours et interactions transversales

| Élément | Comportement présent |
| --- | --- |
| Densité | Bouton Compact ; préférence compacte ou confortable mémorisée dans le navigateur. |
| Langue d’interface | Sélecteur DE, FR, IT avec envoi au changement ; préférence enregistrée pour le compte connecté, choix de session pour un visiteur. |
| Succès et erreurs | Bandeaux en tête de contenu, liste des erreurs de formulaire, avertissements et retours près de l’éditeur. |
| Opération en cours | État, progression ou compteurs. Les vues marquées pour actualisation se rechargent après environ dix secondes si la page est visible au démarrage du mécanisme et qu’aucun éditeur ou formulaire n’a été modifié. |
| Écritures suspendues | Bandeau lorsque le verrouillage d’une opération lourde suspend les modifications. |
| Données absentes | Messages d’état vide pour catalogue, propositions, imports, publications et historiques selon la vue. |
| Repères d’accessibilité | Lien d’évitement vers le contenu, libellés de champs, navigation courante signalée, tableaux dans des régions accessibles et messages portant des rôles de statut ou d’alerte. Ces éléments ne constituent pas une certification d’accessibilité. |

## Fonctions serveur sans commande d’écran correspondante

Ces capacités existent dans les routes ou contrôleurs ; elles ne constituent pas des étapes supplémentaires dans les écrans actuels.

| Fonction implémentée | Limite de l’interface actuelle |
| --- | --- |
| Relancer un import échoué ; annuler un import en attente ou échoué | Pas de bouton de relance de l’import ; Annuler n’est présenté qu’après analyse. |
| Définir des destinataires explicites à la création de publication | Pas de sélecteur ; le formulaire utilise toutes les organisations actives. |
| Filtrer les listes d’imports par statut et de publications par statut ou version | Critères acceptés dans l’URL, sans formulaire de filtre sur ces listes. |
| Modifier numéro et date d’une version encore sans import | La liste ne rend qu’un sélecteur de statut. |
| Filtrer Validation par import ou propositions anciennes | Paramètres acceptés dans l’URL, sans champs correspondants dans les filtres affichés. |

## Sources des parcours

- Structure, accueil et aide : [layout](../../resources/views/layouts/app.blade.php), [tableau de bord](../../resources/views/dashboard.blade.php), [aide](../../resources/views/help.blade.php).
- Traductions : [liste](../../resources/views/terms/index.blade.php), [fiche](../../resources/views/terms/show.blade.php), [filtres](../../resources/views/partials/term-filters.blade.php), [éditeur](../../resources/views/components/proposal-editor.blade.php).
- Traitements : [validation](../../resources/views/validation/index.blade.php), [imports](../../resources/views/imports), [exports](../../resources/views/exports/index.blade.php), [publications](../../resources/views/publications).
- Comptes : [authentification](../../resources/views/auth), [profil](../../resources/views/profile.blade.php), [administration](../../resources/views/admin), [audit](../../resources/views/audit/index.blade.php).
- Interactions et accès : [app.js](../../public/js/app.js), [validation.js](../../public/js/validation.js), [routes métier](../../routes/web.php), [routes d’accès](../../routes/auth.php).
- Points de contrôle existants : [rendu des vues](../../tests/Feature/ViewSmokeTest.php), [saisies non envoyées](../../tests/JavaScript/drafts.test.cjs), [progression](../../tests/JavaScript/progress.test.cjs), [validation](../../tests/JavaScript/validation.test.cjs).
