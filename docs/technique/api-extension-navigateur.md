# API réelle de recherche pour l’extension navigateur

Statut : contrat implémenté et recette locale terminée. Date : 14 octobre 2026. Voir la [mise en œuvre](api-extension-implementation.md) pour le découpage, les résultats de validation et la mise en service.

L’API doit permettre à MyAB Translation Inspector de retrouver une cellule du référentiel à partir d’un texte affiché dans MyAB, puis d’ouvrir sa fiche pour correction. Elle consulte le catalogue commun sans modifier MyAB, les traductions, les propositions, les campagnes ou les publications. Une correspondance textuelle ne constitue jamais une décision de validation ni une preuve que la version du référentiel est installée dans MyAB.

L’implémentation comprend la recherche réelle, les jetons personnels, les adaptations du client nécessaires à un affichage fidèle et le retour « cette correspondance est correcte ». Ce dernier exige une permission dédiée et une activation locale. Les paramètres ci-dessous, notamment expiration et quotas, définissent les valeurs par défaut de la réalisation.

## État existant et audit du contrat client

Avant ce chantier, le [README de l’extension](../../extension/README.md#contrat-de-lapi), le [serveur factice](../../extension/scripts/mock-api.mjs) et les tests définissaient le contrat client, sans backend Laravel. L’audit ci-dessous porte sur cet état initial. Les routes réelles, Sanctum et les adaptations v2 sont désormais présentes ; leur activation exige une projection disponible et un jeton personnel.

| Point audité | Constat initial | Exigence de réalisation |
| --- | --- | --- |
| Identité | Un résultat expose surtout `id` et une clé textuelle. Les clés DEVCONF peuvent être composites et un terme peut avoir plusieurs attributs. | Porter le terme, le type, les composantes exactes de clé, l’attribut et la langue ; réserver la clé lisible à l’affichage. |
| Ambiguïté | `shared/viewModel.ts` limite les résultats avant classification ; `ranking.ts` considère un seul exact comme une correspondance forte. | Calculer le verdict avant la limite d’affichage et conserver les concurrents non affichés dans ce calcul. |
| Exactitude | `exact: false` ne neutralise pas la comparaison locale sans casse ni accents. | Respecter le verdict explicite du serveur ; séparer comparaison stricte et recherche tolérante. |
| Valeurs | Le contrat ne distingue pas clairement valeur importée, référence et traduction validée. | Afficher l’origine de la correspondance et réserver `currentTranslation` à la valeur validée. |
| Propositions | Un champ unique `proposedTranslation` ne représente pas plusieurs propositions concurrentes. | Ne choisir aucune proposition arbitrairement ; la première livraison renvoie ce champ vide et un nombre de propositions en attente. |
| Langue source | Le libellé « Copier le texte allemand » suppose une référence toujours allemande. | Afficher la langue réelle de la référence, éventuellement inconnue, et « Copier le texte source ». |
| Statuts | Le client cite `approved`, `pending`, etc. ; `Term::state()` retourne `missing`, `validated`, `published`, `review`, `obsolete`. | Utiliser les statuts du référentiel, indépendamment du score et de l’origine du texte trouvé. |
| Troncatures | Le parseur coupe la clé à 300 caractères et les textes à 1 000. | Conserver l’identité machine entière ; signaler les aperçus de textes et les saisies tronquées. |
| Confidentialité | Les URL peuvent conserver chemin, fragment et paramètres ; des attributs `data-*` peuvent contenir des données métier. | Réduire les champs envoyés au nécessaire et ne conserver aucun texte, contexte ou URL de recherche. |
| Sécurité | Le contrat demande un bearer, sans cycle de vie ni reprise des règles de compte/MFA. | Ajouter un jeton personnel limité et une vérification de l’état courant du compte à chaque appel. |

Ces écarts concernent le passage à une API réelle. Le serveur factice reste utile pour tester les états d’interface et ne devient jamais un repli automatique si le backend est indisponible.

## Règles métier et périmètre

Le contrat métier reste celui des [formats DEVCONF](formats-devconf.md) et de la [transition vers les termes communs](transition-termes-communs.md).

- L’identité est `type DEVCONF + tuple exact de clé`, sans variante par organisation. Les organisations restent liées aux utilisateurs, à la provenance, à l’audit et aux destinataires des publications.
- Le résultat représente une cellule `(termId, attribute, targetLanguage)`. Deux attributs d’un même terme restent deux résultats distincts. Deux types partageant une clé lisible restent deux termes distincts.
- Les lignées consolidées sont exclues du catalogue courant, conformément au scope de `Term`. La recherche n’enlève pas ce scope et ne fusionne aucune identité ambiguë. Les blocages du §9 technique restent applicables.
- Les termes obsolètes sont exclus de la première recherche interactive. Leur accès historique demeure dans l’application web.
- Les sept formats sont couverts, avec tous les attributs de `FormatRegistry`, y compris Law et Incident. Une clé lisible identique n’autorise pas à supprimer un résultat concurrent.
- Une référence ou une traduction importée peut identifier le texte actuellement visible dans MyAB même si elle n’est pas validée. Son origine reste explicite. Les propositions, en attente ou rejetées, ne constituent pas un corpus de correspondance avec MyAB dans cette première livraison.
- Une proposition ne remplace jamais `currentTranslation`. Un feedback ne crée, ne valide, ne confirme ni ne publie une traduction et ne fait pas progresser une campagne.
- Aucune URL reçue n’est visitée par le serveur. Aucune version installée, organisation, langue ou permission n’est déduite du domaine de MyAB.

Les fonctions d’écriture continuent à passer par les parcours web existants et leurs droits par langue. La lecture de l’API suit le catalogue commun : elle ne filtre pas les résultats sur l’organisation ni sur les langues que l’utilisateur peut traduire.

## Authentification et gestion des jetons

### Création dans le profil web

Introduire les jetons personnels de Laravel Sanctum, en version compatible avec Laravel 13 et les dépendances du dépôt. Utiliser l’authentification bearer ; aucune authentification de type SPA par cookies n’est nécessaire à cette API.

Le profil propose une section « Extension navigateur », conforme à [DESIGN.md](../../DESIGN.md), avec liste, création et révocation des jetons de l’utilisateur connecté. Ces actions restent dans les routes web avec CSRF, `auth`, `session.secure`, MFA applicable et confirmation récente du mot de passe, datant de moins de 15 minutes. Une session issue d’un challenge MFA doit être exigée lorsque la politique MFA courante l’impose. Vérifier de nouveau le compte lors de la création.

Valeurs proposées : cinq jetons actifs au maximum par utilisateur, nom de 1 à 100 caractères, expiration à 30 jours au maximum. Aucun jeton illimité ni permission `*`. La valeur secrète est aléatoire, stockée uniquement sous forme de hash et montrée une seule fois, avec une réponse `Cache-Control: no-store` ; elle n’est jamais réaffichée, exportée ou placée dans une URL. L’extension conserve les modes de stockage local ou session déjà proposés.

| Permission du jeton | Droit accordé |
| --- | --- |
| `extension:search` | Recherche en lecture et test de connexion. |
| `extension:feedback` | Retour de correspondance, seulement si le lot feedback est activé ; jamais un droit de validation. |

La création ne peut accorder aucun droit supplémentaire à ceux de l’utilisateur. L’utilisateur révoque ses propres jetons ; un administrateur conserve la révocation globale du compte. Changer le mot de passe, réinitialiser les accès ou utiliser une action existante qui incrémente `session_version` doit invalider les jetons précédents.

L’extension stocke le secret et l’origine de l’API dans une même valeur, séparée des paramètres exportables. Chaque appel (recherche, feedback, test de connexion) exige que cette origine corresponde à sa destination. Une configuration vers une nouvelle origine efface les jetons local et session avant publication des nouveaux paramètres ; un test avant enregistrement exige un jeton saisi pour cette destination. Les anciens jetons sans origine doivent être ressaisis après la mise à jour 0.2.2 ; aucune migration ne leur attribue implicitement l’URL courante. Les contrôles d’origine empêchent aussi de mélanger un ancien instantané des paramètres avec un nouveau jeton pendant des écritures concurrentes.

### Contrôles à chaque requête

Le middleware bearer exige un token personnel réel, sans accepter une session web à sa place. `auth:sanctum` doit donc être complété par un contrôle explicite du bearer et du type de token ; sa seule présence ne suffit pas à exclure les cookies.

Recharger l’utilisateur et vérifier, dans cet ordre : token existant, non expiré et non révoqué ; version de sécurité identique ; `User::isLoginAllowed()` ; `User::canSeeCatalogue()` ; exigences MFA actuelles ; permission du jeton pour l’opération. Les organisations désactivées, comptes techniques, invitations non acceptées et comptes verrouillés doivent être refusés. Un rôle changé ne reste pas acquis dans le jeton.

Conserver avec le token la valeur de `session_version` et la preuve d’une création sous la politique MFA adéquate. Si la politique MFA devient plus exigeante ou si cette preuve n’est plus valable, demander un nouveau jeton depuis une session conforme. Ne pas appliquer directement `EnsureSecureSession` aux routes API : il utilise une session HTTP.

Les audits privés enregistrent la création/révocation, l’utilisateur, l’identifiant du token, l’expiration et les permissions. Ils n’enregistrent ni secret ni texte libre saisi pour son nom. La date de dernière utilisation peut être affichée, sans historique des recherches. La suppression des jetons expirés reste une commande d’entretien ponctuelle ou planifiée séparément.

## Transport et protection des requêtes

Base réelle proposée : `https://translations.example.org/api/browser-extension`. Pour la recette locale uniquement : `http://127.0.0.1:8000/api/browser-extension` ou `http://localhost:8000/api/browser-extension`, sur le serveur Laravel. Le port 8787 reste celui de l’API factice.

Le service utilise Laravel/PHP et MariaDB en production, sans Node, Redis ou moteur externe obligatoire. Ajouter `routes/api.php` et son chargement dans `bootstrap/app.php`. Aucun appel API ne redirige vers `/login`, ne renvoie une page HTML ou ne change d’origine. HTTPS est requis en production ; la configuration des proxys de confiance doit préserver ce contrôle.

En-têtes : `Authorization: Bearer <jeton>`, `Content-Type: application/json`, `Accept: application/json`. Les réponses portent `Cache-Control: no-store`, un identifiant technique `X-Request-ID` sans contenu métier, et un JSON UTF-8. Le client conserve `credentials: 'omit'` et `cache: 'no-store'`.

Les appels partent du service worker autorisé par les permissions d’hôte, comme dans le client existant. Les origines d’extension ne sont pas assimilées aux domaines MyAB ni à une preuve d’identité. Accepter automatiquement les origines Chrome `chrome-extension://<identifiant de 32 lettres a-p>` et Firefox `moz-extension://<UUID>`, sans déclaration par installation. Refuser les origines de sites web, opaques et malformées, ainsi que les chemins, ports, credentials, query-strings et fragments dans `Origin`. Les prévols acceptent uniquement `POST`, `Authorization`, `Content-Type` et `Accept` ; les réponses CORS reflètent l’origine d’extension acceptée, sans `Access-Control-Allow-Credentials` ni origine universelle. Les réponses d’erreur appliquent la même règle et exposent `Retry-After` et `X-Request-ID`. Le contrôle bearer reste obligatoire sur chaque recherche et retour de correspondance, même après un prévol réussi ou pour un appel sans en-tête `Origin`.

Limiter le corps à 64 Kio avant décodage et la réponse à 256 Kio. Rejeter le JSON invalide, les types incorrects, les champs hors limites et les versions inconnues avant la recherche. Une validation échouée ne recopie aucune valeur reçue dans le message d’erreur. Utiliser des paramètres SQL liés ; `%`, `_` et `!` sont des caractères littéraux, jamais des opérateurs fournis par le client. L’API n’accepte ni regex, SQL ni sélecteur CSS à exécuter.

Quotas initiaux proposés : 60 recherches/minute/token et 120/minute/utilisateur, 20 feedbacks/minute/utilisateur. Avant authentification, limiter les tentatives par IP à 120/minute, en tenant compte des proxys de confiance ; ces limites sont cumulatives. Utiliser un stockage de limite partagé entre workers, compatible avec le déploiement sans Redis. Une limite retourne 429 avec `Retry-After` ; elle ne bloque pas définitivement le compte.

## Contrat de recherche version 2

### Requête

`POST /api/browser-extension/search`

`contractVersion: 2` est obligatoire pour la vraie API. Le client actuel ignore les métadonnées d’ambiguïté : une requête sans version ou avec une version non prise en charge reçoit 426 `client_upgrade_required`, au lieu d’une réponse qu’il pourrait interpréter comme une correspondance unique. Le client adapté peut continuer à utiliser le mock, qui ignore les champs supplémentaires.

| Champ | Règle serveur |
| --- | --- |
| `contractVersion` | Entier égal à 2. |
| `text` | Chaîne non vide après nettoyage, au plus 500 caractères. |
| `textTruncated` | Booléen obligatoire ; vrai si la collecte a coupé le texte. |
| `searchLanguage` | Facultatif : `de`, `fr`, `it`, `en`. Choix explicite de la langue du texte inspecté. |
| `lang` | Chaîne de 0 à 12 caractères ; langue indiquée par la page. |
| `uiLanguage` | `de`, `fr` ou `en`, défaut `fr` ; langue de l’interface, indépendante de la recherche. |
| `maxResults` | Entier fini de 1 à 20, défaut 5 ; limite d’affichage, pas limite de détection des exacts. |
| `pageUrl`, `frameUrl` | Facultatifs ou vides, au plus 2 000 caractères chacun ; non utilisés comme droits. |
| `pageTitle`, `ariaLabel`, `title` | Chaînes facultatives, au plus 200 caractères chacune. |
| `surroundingText`, `parentText` | Chaînes facultatives, au plus 600 et 400 caractères. |
| `elementTag`, `elementId` | Chaînes facultatives, au plus 40 et 100 caractères. |
| `elementClasses` | Au plus 20 chaînes de 60 caractères. |
| `dataAttributes` | Au plus 20 entrées de 100 caractères ; clés `data-` suivies de 1 à 80 caractères `[a-z0-9_.:-]`. |
| `ariaAttributes` | Au plus 10 entrées de 100 caractères ; même règle avec préfixe `aria-`. |
| `alternativeTexts` | Au plus 3 chaînes de 300 caractères ; lectures secondaires, jamais preuve que `text` est exact. |
| `siblingTexts`, `breadcrumbs` | Au plus 6 chaînes de 100 et 8 chaînes de 80 caractères. |
| `role`, `elementName`, `inputType`, `formLabel`, `sectionTitle`, `dialogTitle`, `tableColumn` | Chaînes facultatives de 200 caractères chacune. |
| `inDialog` | Booléen facultatif. |

Les limites sont indépendantes des contrôles du navigateur. Les champs inconnus sont rejetés en 422 ; les champs facultatifs absents sont remplacés par des valeurs vides côté service. Les chaînes restent du texte, sans interprétation HTML. La profondeur JSON maximale est 4. Côté client, chaque borne s’applique au résultat final, ellipse comprise : `truncate` peut actuellement produire un caractère de plus que la limite. Corriger cette fonction et propager `textTruncated` depuis la lecture du texte original jusqu’à l’envoi, sans perdre ce marqueur lors des nettoyages successifs. Les fixtures partagées vérifient aussi les caractères Unicode hors BMP.

La langue cible est `searchLanguage` si elle est fournie ; sinon le premier sous-tag de `lang`, après conversion `_` vers `-` et mise en minuscules. Ainsi `fr-CH` devient `fr`. Une langue vide ou non prise en charge retourne 422 `search_language_required` : l’utilisateur choisit une langue dans les paramètres, sans repli silencieux sur la langue de l’interface. Une cellule sans colonne importée dans cette langue peut néanmoins avoir une valeur validée ; elle n’est pas exclue pour ce seul motif.

Le test de connexion reste un appel de recherche avec `text: "Test"`, `lang: "fr"`, `maxResults: 1`, `contractVersion: 2`, `textTruncated: false` et contexte vide. Une réponse 200 avec zéro résultat est un succès. Ce test n’exige aucun domaine MyAB et ne crée aucun feedback.

### Réponse et identité

Exemple fictif d’une correspondance avec une valeur importée non validée :

```json
{
  "contractVersion": 2,
  "query": "Exemple de libellé",
  "matchState": "strong",
  "exactCandidateCount": 1,
  "hasMore": false,
  "candidateSetComplete": true,
  "feedbackAllowed": false,
  "results": [
    {
      "id": "cell2.WzEyMzQsImxhYmVsIiwiZnIiXQ",
      "termId": 1234,
      "type": "mric",
      "key": "EXAMPLE_LABEL",
      "keyParts": ["EXAMPLE_LABEL"],
      "attribute": "label",
      "targetLanguage": "fr",
      "sourceLanguage": "de",
      "sourceText": "Beispieltext",
      "currentTranslation": "",
      "importedTranslation": "Exemple de libellé",
      "proposedTranslation": "",
      "pendingCount": 1,
      "matchedText": "Exemple de libellé",
      "valueOrigin": "imported",
      "matchReason": "literal",
      "module": "",
      "status": "missing",
      "score": 1.0,
      "exact": true,
      "previewTruncated": false,
      "translationUrl": "https://translations.example.org/terms/1234?language=fr"
    }
  ]
}
```

`termId` est la fiche courante, `type` le code du format et `keyParts` le tuple exact dans l’ordre de `identityColumns`. Aucun trim, changement de casse, découpage ou assemblage avec un séparateur ne remplace ce tuple. `key` est seulement une représentation lisible ; une version raccourcie à l’écran ne doit pas être réutilisée comme identité.

`id` est un identifiant stable de cellule, opaque pour le client : préfixe `cell2.` suivi de l’encodage base64url du JSON `[termId, attribute, targetLanguage]`, sans padding. Il distingue les attributs et langues d’une même fiche et ne prouve aucun droit. Si le lot feedback est actif et autorisé, ajouter une propriété `feedbackProof` signée selon le contrat ci-dessous. Aucun besoin de signature ou de stockage de feedback ne bloque le lot de recherche. Une réponse respecte le plafond de taille ; si l’identité complète ne peut pas être transportée, elle échoue explicitement plutôt que de fournir une clé machine tronquée.

`sourceText` et `sourceLanguage` proviennent de la référence de l’attribut, via `FormatDefinition::attributes()` ; une langue de référence inconnue est `""`, jamais inventée comme `de`. `currentTranslation` contient uniquement la valeur validée de cette cellule, ou `""` si elle est absente. `importedTranslation` provient de la dernière source canonique du terme, pas d’un fallback propre à l’organisation ou d’un ancien import choisi arbitrairement.

`valueOrigin` vaut `validated`, `imported` ou `reference` et qualifie le texte utilisé pour la correspondance. Pour une même cellule, conserver d’abord une origine avec exactitude éligible, puis le meilleur score ; seulement à égalité préférer `validated`, puis `imported`, puis `reference`. Une valeur validée différente ne doit donc pas effacer une valeur importée exacte. Ne compter qu’un résultat par cellule. `matchedText` est le texte de l’origine retenue ; il ne signifie pas que cette valeur est déployée dans l’ERP.

`status` reprend `Term::state(attribute, targetLanguage)` : `review`, `missing`, `published`, `validated`, et `obsolete` pour un éventuel futur parcours historique. Il ne dépend pas du score. `pendingCount` compte les propositions `pending` de la cellule ; `proposedTranslation` reste vide dans cette livraison. Le panneau affiche une valeur en révision comme telle même si elle était auparavant validée.

Les quatre champs de texte source/courant/importé/trouvé sont des aperçus de 1 000 caractères au maximum ; `previewTruncated` indique si au moins un aperçu est coupé. L’exactitude est calculée sur les valeurs complètes avant aperçu. Le client ne présente pas la copie d’un aperçu tronqué comme une copie intégrale ; la fiche web donne accès à la valeur complète. `module` est vide si aucun contexte de module fiable n’est disponible, sans déduction à partir du nom d’une organisation ou d’un chemin d’URL.

`translationUrl` utilise uniquement l’URL canonique de l’application configurée côté serveur et la route `terms.show`, avec la langue. Aucune URL issue de la requête ne sert à sa construction. Le client applique également son contrôle d’origine existant. L’ouverture de la fiche utilise la connexion web et ses droits ; le token ne figure jamais dans le lien.

### Exactitude, classement et ambiguïté

Définir deux normalisations versionnées, avec des fixtures PHP/TypeScript communes :

1. **Comparaison littérale** : NFC, suppression des caractères invisibles et glyphes d’icônes déjà ignorés par `normalizeText`, réduction des espaces et trim. Casse, accents et ponctuation restent significatifs.
2. **Recherche tolérante** : normalisation `searchKey` du client, avec minuscules, suppression des marques combinantes et unification des apostrophes. Elle trouve des candidats mais ne certifie pas leur identité.

Ces normalisations ne s’appliquent jamais aux composantes de la clé technique. Une collation SQL insensible à la casse ou aux accents ne constitue pas une preuve d’égalité.

La recherche examine les valeurs validées et importées dans la langue cible, ainsi que la référence si sa langue est cette langue cible. Une référence à langue inconnue peut fournir un candidat tolérant, avec cette incertitude affichée, mais ne certifie pas `exact`. Une référence d’une autre langue n’est pas utilisée pour déclarer exact un texte dans la langue cible.

`exact` vaut vrai uniquement si `textTruncated` est faux et si le texte principal est littéralement égal à la valeur complète selon la première normalisation. Une correspondance avec `alternativeTexts` ou avec un préfixe extrait d’un texte long n’est jamais exacte pour le texte principal.

| Origine du candidat | Score proposé et raison |
| --- | --- |
| Texte principal littéralement égal et éligible à `exact: true` | `1.00`, `literal`. |
| Texte principal égal après normalisation tolérante | `0.88`, `normalized`. |
| Égalité littérale privée d’exactitude par troncature ou langue de référence inconnue | `0.88`, `normalized`, avec `exact: false`. |
| Lecture secondaire littéralement égale | `0.82`, `alternative`. |
| Approximation par tokens | `0.60 + 0.20 × Jaccard(tokens)`, `approximate`, si Jaccard ≥ 0.50. |
| Approximation par préfixe | `0.60 + 0.20 × couverture`, `approximate`, si couverture ≥ 0.50. |

Les tokens sont les suites de lettres/chiffres du texte normalisé ; la similarité de Jaccard est l’intersection divisée par l’union des ensembles de tokens, ou zéro si cette union est vide. Pour le préfixe, la valeur normalisée doit commencer par le texte principal normalisé, long d’au moins trois caractères ; la couverture est la longueur du texte divisée par celle de la valeur. Les deux recherches approximatives sont indépendantes et retiennent le meilleur de leurs scores : un préfixe comme `enregis` doit retrouver `enregistrer` même si Jaccard vaut zéro. Pour un texte de moins de trois caractères, seuls les deux étages d’égalité sont exécutés. Le score est un indice de classement entre 0 et 1, pas une probabilité.

Une indication exacte `data-i18n-key` peut aider à ordonner les candidats à clé simple, sans supprimer les autres types portant cette clé et sans transformer un libellé différent en égalité textuelle. Le client omet cette indication si sa valeur originale dépasse 100 caractères, au lieu d’envoyer une clé coupée ; le serveur refuse une valeur hors limite. Cette vérification doit intervenir avant toute troncature du collecteur actuel. Les clés composites ne sont pas reconstruites à partir d’un texte libre du DOM. La première livraison n’utilise pas les autres attributs `data-*` comme identités.

Le contexte textuel est indicatif. Il peut départager les candidats non exacts avec un bonus maximal de 0.01, sans dépasser 0.89 ni masquer plusieurs exacts. Les exacts gardent le même score. À égalité, préférer une indication de clé simple exactement concordante, puis trier par type, tuple exact en ordre binaire, ordre de l’attribut dans le format, puis `termId`. Le client conserve cet ordre ; son tri local actuel par clé lisible ne le remplace pas.

Dédupliquer les origines de chaque cellule, compter les candidats exacts et calculer `matchState` **avant** `maxResults`. La sélection exacte ne peut pas être plafonnée avant le comptage. `hasMore` signifie qu’il existe d’autres candidats que ceux affichés, ou que le plafond de présélection tolérante a été atteint. `candidateSetComplete` signifie que les étages définis ci-dessus ont été évalués sans plafond, texte tronqué ou projection incomplète ; il ne prétend pas identifier la version réellement installée dans MyAB.

- `none` : aucun candidat retenu et ensemble évalué complet.
- `noExact` : aucun candidat exact ; les candidats affichés sont approximatifs. Une réponse incomplète sans candidat utilise aussi cet état avec un message « recherche incomplète », jamais « aucune traduction existe ».
- `strong` : un seul candidat exact, premier du classement, ensemble complet, score au moins 0.90 et écart d’au moins 0.15 avec le deuxième candidat avant limite d’affichage, s’il existe.
- `multiple` : il existe un exact mais ces conditions de correspondance forte ne sont pas réunies.

`exactCandidateCount` compte les cellules distinctes correspondant au texte principal, même si une seule est affichée. Un `maxResults: 1` avec deux exacts doit donc répondre `multiple`, `exactCandidateCount: 2`, `hasMore: true`. Une réponse tronquée ou une projection non prête ne peut jamais certifier `strong`.

Le seuil d’écart est volontairement conservateur : un exact à 1.00 accompagné d’un autre candidat égal après suppression des accents à 0.88 reste `multiple`, même si `exactCandidateCount` vaut 1. Le panneau indique le concurrent et son niveau d’approximation. La troncature d’un aperçu de sortie n’invalide pas un exact calculé sur les valeurs complètes ; la troncature du texte d’entrée ou du corpus de candidats l’invalide.

## Recherche et projection par cellule

Réutiliser `Term::visibleTo`, le scope courant de `Term`, `FormatRegistry`, `FormatDefinition::attributes()`, `Term::state()` et le modèle de valeurs validées. Ne pas appeler le contrôleur Blade pour obtenir du JSON.

La projection générale `SearchText` agrège les références, traductions importées/validées, contexte et toutes les propositions, même rejetées. `term_search.search_text LIKE ...` peut aider à une exploration, mais ne fournit ni attribut/langue/origine fiables ni index d’exactitude. La première API exploitable doit disposer d’une projection dédiée par cellule, sans modifier la sémantique de la recherche web.

Projection proposée : terme courant, type, attribut, langue de valeur éventuellement inconnue, origine, valeur complète, normalisations, hashes indexés des normalisations, `revision_no`, `source_hash` et version de normalisation. Les valeurs sont privées dans la base ; aucun fichier de projection n’est livré sous `public`. Stocker aussi les tokens nécessaires à la présélection tolérante et prévoir un index de préfixe. Une recherche par hash est toujours suivie d’une comparaison des valeurs normalisées complètes ; un hash ne fusionne aucune identité.

La projection contient les valeurs validées et les cellules du dernier original canonique, pas les propositions historiques, rejetées ou pending. Les données sont extraites via `SourceRecord`/`FormatDefinition` pendant les imports, décisions ou reconstructions, en réutilisant les lignes déjà lues lorsque possible. Une recherche interactive ne relit pas un CSV pour chaque terme du catalogue.

Mettre à jour ou invalider la projection dans la même transaction que les modifications de valeurs, références, consolidations ou obsolescence. Une modification de proposition peut changer `pendingCount`, pas le corpus de correspondance. Un changement d’identité remplace les anciennes entrées sans réécrire l’identité métier. Les triggers SQLite existants doivent être préservés si une migration reconstruit une table.

Prévoir une commande Laravel idempotente de reconstruction par lots, avec curseur de reprise et activation atomique d’une génération complète. Capturer les écritures concurrentes ou vérifier un compteur de génération du catalogue avant activation ; si des changements n’ont pas été intégrés, reprendre la reconstruction sans activer l’index incomplet. Vérifier seulement les révisions des lignes trouvées ne suffit pas : une cellule oubliée peut masquer un autre exact. Les requêtes de comptage, classement et lecture utilisent une génération cohérente.

Pendant une reconstruction nécessaire ou après une erreur détectée d’extraction/synchronisation de source, invalider la disponibilité de la projection et retourner 503 `search_index_unavailable` plutôt qu’une réponse certifiant l’unicité sur une projection partielle. Une recherche détectant une entrée périmée doit échouer ou refaire la comparaison après synchronisation ; elle ne renvoie pas un ancien état comme courant. La suppression externe d’un original après une indexation réussie n’est pas détectée par une recherche qui ne relit pas le fichier : elle relève du contrôle d’intégrité des originaux et de la prochaine reconstruction, sans prétendre à une surveillance des fichiers par l’API.

Les étages exact et normalisé utilisent leurs index et filtrent les termes visibles/courants avant comptage et limitation. La recherche tolérante présélectionne au plus 200 cellules distinctes, avec ordre déterministe ; si d’autres cellules satisfont sa présélection, `candidateSetComplete` vaut faux. Tous les exacts sont traités indépendamment de ce plafond. Hydrater uniquement les fiches nécessaires aux résultats affichés et aux états, en lot pour éviter les requêtes N+1.

Objectif de recette proposé : p95 serveur inférieur à 750 ms, hors réseau, pour les recherches exactes/normalisées sur un jeu synthétique de 100 000 termes répartis sur les sept formats, avec attributs, langues et collisions représentatifs ; p95 inférieur à 1.5 s pour les recherches tolérantes. Enregistrer le matériel, le nombre de cellules et les plans SQL MariaDB. Un benchmark SQLite ne valide pas les performances de production. Les dépassements de délai retournent une erreur sans fabriquer un résultat unique.

## Retour de correspondance facultatif

`POST /api/browser-extension/feedback` nécessite `extension:feedback` et l’activation de ce lot. `feedbackAllowed` dans la recherche vaut vrai seulement si les deux conditions sont remplies ; le client combine ce booléen avec le réglage local avant d’afficher le bouton. Un appel forcé quand le lot ou la permission manque reçoit 403 `feedback_not_allowed`.

Corps : `contractVersion: 2`, `resultId` issu du résultat, `feedbackProof` issue de la recherche et `verdict: "correct"`. Le client peut conserver `text`, `key` et `pageUrl` pour le mock ; le serveur réel accepte ces trois champs aux limites de la recherche et les écarte, sans les utiliser pour résoudre l’identité ni les stocker. Limites : `resultId` de 1 024 caractères et `feedbackProof` de 4 096 caractères.

`feedbackProof` est un payload signé par HMAC, valable 15 minutes, lié à l’utilisateur et au token, contenant `termId`, type, hash d’identité, attribut, langue, révision, empreinte source, nonce et expiration. Le hash est calculé sur le JSON canonique du type et du tuple exact ; les composantes longues ne sont jamais tronquées pour signer. L’identité complète reste dans le résultat et dans le terme courant. La preuve ne contient aucun texte de recherche, contexte, URL ou secret. Le serveur vérifie signature, sujet, expiration, concordance avec `resultId`, identité courante, visibilité et révision avant tout enregistrement. Le hash ne sert pas à retrouver ou fusionner un terme : `termId` désigne la fiche et son identité courante est comparée à la preuve. Un identifiant inventé ou appartenant à un autre sujet reçoit 404 ; un résultat périmé reçoit 409 `result_stale`. Une preuve expirée demande une nouvelle recherche.

Le stockage privé contient seulement l’auteur, son organisation de provenance, token utilisé, cellule, révision, verdict et date, ainsi qu’une empreinte de la preuve pour l’idempotence. Deux envois de la même preuve ne créent qu’un événement. Réponse : `200 {"ok": true}`. Le contenu brut du clic et sa preuve complète ne sont pas conservés.

Ces retours n’alimentent pas automatiquement le classement de la première API. Leur éventuelle exploitation ou modération sera spécifiée séparément, sans écriture implicite dans le workflow. La durée de conservation et la purge des retours doivent être configurées ; valeur proposée : 90 jours, sans modifier la conservation du journal d’audit existant.

## Erreurs et confidentialité

Format uniforme : `{"error":{"code":"...","message":"..."},"requestId":"..."}`. Le message explique l’action possible sans reproduire le bearer, le texte ou une ligne CSV. Les erreurs de champs portent uniquement leur nom et un code de règle.

| HTTP | Code ou cas | Comportement client attendu |
| --- | --- | --- |
| 200 | Recherche réussie, éventuellement sans résultat | Afficher le verdict serveur et les limites indiquées. |
| 401 | `unauthenticated` | Token absent, invalide, expiré/révoqué, version de sécurité périmée ou compte indisponible ; demander un nouveau jeton. |
| 403 | `permission_denied`, `mfa_required`, `feedback_not_allowed`, `origin_not_allowed` | Expliquer le droit ou la configuration à corriger ; une origine refusée avec le client officiel demande de vérifier la version du serveur, sans déclaration d’installation. |
| 404 | Route inconnue ou preuve non accessible | Vérifier URL/configuration, sans révéler une identité étrangère. |
| 409 | `result_stale` | Refaire une recherche avant feedback. |
| 413 | `payload_too_large` | Réduire le contexte ; ne pas réessayer automatiquement le même corps. |
| 415 | `unsupported_media_type` | Envoyer un JSON. |
| 422 | `invalid_request`, `search_language_required` | Corriger la langue ou la requête ; conserver uniquement les noms de champs dans les détails. |
| 426 | `client_upgrade_required` | Mettre à jour l’extension. |
| 429 | `rate_limited` | Respecter `Retry-After`. |
| 500/503 | `internal_error`, `search_index_unavailable` | Erreur transitoire ; aucun détail interne ni repli mock. |

Les contrôles de version et de corps interviennent avant toute recherche ; les erreurs d’authentification ne révèlent aucun résultat. Le client adapté traite explicitement 409/413/415/422/426 et le format d’erreur, que le client actuel regroupe en erreurs HTTP génériques.

Ne stocker ni corps de recherche, ni corpus de clics, ni URL, titre ou contexte dans les logs, traces d’exception, audits ou métriques. `AuditService` ne masque pas automatiquement les champs `text` et `pageUrl` : ne jamais lui passer la requête. Les erreurs de validation ne doivent pas enregistrer le corps par un middleware, un outil de debug ou un proxy. Désactiver le debug en production.

Les métriques autorisées sont durée, statut HTTP, nombre de résultats, compteurs d’erreurs et saturation de projection, sans clés, textes ou valeurs métier. Les journaux techniques publics éventuels ne portent aucune donnée métier ; les audits de sécurité restent privés hors `public` et des livraisons. Aucun endpoint comparable à `__requests` du mock n’est exposé dans l’API réelle.

Le client adapté choisit le mode d’URL `origin` par défaut et envoie seulement `data-i18n-key` parmi les attributs `data-*` utilisés pour cette API. Le contexte facultatif reste explicite et réduit aux indices utiles ; il n’est jamais nécessaire pour un résultat exact. Le serveur accepte les champs contextuels documentés mais les utilise en mémoire seulement, supprime les credentials des URL et n’exploite ni query-string ni fragment. Corriger la promesse du README : retirer username/password ne retire pas nécessairement les identifiants métier du chemin ou du fragment, et un texte visible peut lui-même être une donnée métier.

## Adaptations du client et compatibilité

La mise en service réelle doit inclure ces changements dans l’extension, avec maintien des tests mock :

1. Ajouter `contractVersion`, `textTruncated` propagé depuis l’extraction et le choix facultatif de langue de recherche, y compris `it`, distinct de la langue de l’interface. Adapter explicitement `buildSearchRequest`, `sanitizeSearchRequest` et la sonde de connexion du service worker : la reconstruction de la requête doit conserver et valider les champs v2, sans réintroduire une coupe non signalée.
2. Parser et valider les métadonnées serveur, les composantes exactes, l’attribut, l’origine et les statuts, sans tronquer silencieusement l’identité. Un contrat v2 incomplet est une réponse invalide.
3. Utiliser `matchState` et l’ordre serveur pour v2. Respecter `exact: false`, `candidateSetComplete` et `hasMore` ; afficher une ambiguïté même avec un seul résultat visible.
4. Montrer séparément valeur trouvée/importée, référence et traduction validée ; ne pas présenter une source non validée comme approuvée. Préciser l’attribut pour les formats comportant plusieurs cellules.
5. Adapter copie des textes, previews, langue source inconnue, statuts et erreurs. Une copie tronquée est annoncée comme telle.
6. Relayer `resultId` et `feedbackProof` intacts pour le feedback et respecter `feedbackAllowed`. Réduire les URL et attributs envoyés par défaut, omettre les indications de clé trop longues avant troncature ; préserver l’interception Alt+clic et les permissions d’hôte existantes.

La compatibilité avec le mock non versionné reste un mode de test explicite : ses réponses ne certifient pas un résultat réel. Même dans ce mode, corriger la classification avant limite et le respect de `exact: false`. Aucun retry réseau ne change de backend automatiquement.

## Découpage de réalisation et recette

| Lot | Livrable | Condition de sortie |
| --- | --- | --- |
| 1 | Token personnel, profil, middleware bearer, erreurs et quotas | Révocation, droits de compte et MFA testés sans cookie. |
| 2 | Projection par cellule, recherche et contrat v2, client adapté | Recherche réelle des sept formats, classement et ambiguïtés conservées après limite. |
| 3 | Feedback facultatif et preuves signées | Idempotence, résultat périmé et absence d’effet sur le workflow vérifiés. |
| 4 | Recette locale et guide de mise en service | Performance MariaDB, contrôle HTTPS, permissions Chrome/Firefox et confidentialité vérifiés en recette isolée. |

Les lots 1 et 2 forment la première livraison utilisable ; le lot 3 ajoute le feedback facultatif. Les quatre lots sont réalisés en recette locale. Les migrations de projection ne reconstruisent pas les tables métier sans nécessité. La reconstruction initiale reste hors du chemin HTTP et des commandes de déploiement automatiques.

### Critères d’acceptation

- Une fixture pour chacun des sept formats retrouve la cellule correcte par valeur validée, importée et référence compatible avec la langue ; Law et Incident testent plusieurs attributs d’un même terme.
- Deux organisations retrouvent le même terme courant ; leurs identités et valeurs validées ne divergent pas. Une lignée consolidée et un terme obsolète ne participent pas à la recherche courante.
- Une référence non allemande ou inconnue n’est pas présentée comme allemande. Les langues `de`, `fr`, `it`, `en`, sous-tags régionaux et langues absentes/non prises en charge sont testées.
- Une valeur importée sans validation retourne `status: missing` avec son origine ; une proposition pending ou rejetée n’est pas un texte actuellement validé et n’apparaît pas comme un exact de MyAB. Plusieurs propositions pending donnent leur nombre, sans sélection arbitraire.
- Deux clés/types ou attributs partageant un texte restent plusieurs candidats. Avec `maxResults: 1`, le deuxième exact reste compté et le verdict reste `multiple`.
- Casse, accents, apostrophes, Unicode NFC/NFD, espaces, `%`, `_`, `!`, clés composites contenant un séparateur et clés longues ont des fixtures. Les collisions de hash simulées n’autorisent jamais une fusion ou un faux exact.
- `exact: false` reste faux côté client ; texte extrait tronqué, lectures secondaires et plafond de 200 candidats n’autorisent aucun verdict `strong` injustifié. Un preview tronqué indique cette limite tout en préservant l’exactitude calculée sur la valeur complète.
- Un préfixe sans token commun est retrouvé ; Jaccard avec ensembles vides vaut zéro. Un exact importé reste retenu si la valeur validée de la même cellule diffère. Un exact à 1.00 accompagné d’un autre candidat normalisé à 0.88 reste `multiple`.
- La projection suit import, décision, révision, consolidation, obsolescence et rollback de transaction. Une erreur de source privée absente/corrompue détectée pendant extraction/synchronisation/reconstruction, ou une génération incomplète, donne 503 sans fuite ni résultat périmé. Un import, une décision et une consolidation concurrents pendant reconstruction n’autorisent pas une bascule incomplète.
- Les tests utilisent des tokens véritables et des requêtes sans cookie, pas seulement un utilisateur simulé : expiration, révocation individuelle/globale, `session_version`, changement de rôle, organisation inactive, compte technique/verrouillé, invitation et MFA.
- Cookie seul, token search utilisé pour feedback, preuve modifiée, autre utilisateur/token, résultat périmé et rejeu de feedback sont refusés ou traités comme défini. Aucun feedback ne modifie traduction, proposition, publication ou campagne.
- JSON malformé, nombres non entiers, bornes, corps trop volumineux et champs inconnus échouent sans echo de valeurs. Les fixtures d’intégration couvrent un mot de 501 caractères, un titre de 201 et une indication de clé de 101 : texte/titre sont bornés ellipse comprise, la clé est omise et le marqueur de troncature reste transmis. Quotas entre plusieurs tokens/workers et `Retry-After` sont vérifiés.
- Des sentinelles fictives de token, texte métier et URL sensible n’apparaissent jamais dans les logs/audits d’une recherche réussie, d’une validation échouée ou d’une erreur serveur. Les exemples et fixtures restent synthétiques dans Git.
- Le client v2 refuse un contrat incomplet ; l’ancien client reçoit 426 sur l’API réelle. Un test d’intégration part du message du content script, traverse le sanitizer du background et inspecte la requête HTTP finale, avec les champs v2 et le marqueur de troncature conservés. Le test de connexion réussit avec un catalogue vide et le mock reste utilisable en mode de test.

Vérifications d’implémentation : `php artisan test`, `node --test tests/JavaScript/*.test.cjs`, `cd extension && npm run check`, contrôle de style et `git diff --check`. Les modifications liées aux imports, formats ou projections métier doivent aussi passer `php scripts/test_devconf_roundtrip.php --validate-semantics` avec des entrées disponibles, conservées localement et sans rapport privé dans Git. Si les originaux sont absents, ne pas annoncer de validation sémantique sur données réelles.

Recette manuelle : démarrer Laravel avec une base isolée, importer des fixtures synthétiques et créer un jeton depuis le profil ; remplacer l’URL du mock par la base Laravel dans l’extension, enregistrer les permissions, tester la connexion, puis Alt+clic en Chrome et Firefox. Vérifier un exact validé, un import non validé, une ambiguïté avec limite 1, une cellule multi-attribut et un token révoqué. Cette recette a été exécutée avec les navigateurs réels ; son bilan figure dans la mise en œuvre. La mise en service du catalogue de production exige ses migrations, sa projection, ses jetons et la configuration du frontal.

## Sources de réalisation

| Source du dépôt | Utilité |
| --- | --- |
| [Types client](../../extension/src/types/index.ts), [client HTTP](../../extension/src/api/translationApi.ts), [messages](../../extension/src/shared/messages.ts) | Contrat et validations actuellement acceptés par l’extension. |
| [Classement](../../extension/src/shared/ranking.ts), [modèle du panneau](../../extension/src/shared/viewModel.ts), [service worker](../../extension/src/background/serviceWorker.ts) | Calcul du verdict, limite, test de connexion et feedback actuels. |
| [Term](../../app/Models/Term.php), [User](../../app/Models/User.php), [sécurité de session](../../app/Http/Middleware/EnsureSecureSession.php) | Catalogue courant, statuts et garanties de compte à reprendre. |
| [FormatRegistry](../../app/Domain/Devconf/FormatRegistry.php), [FormatDefinition](../../app/Domain/Devconf/FormatDefinition.php), [SourceRecord](../../app/Services/SourceRecord.php) | Identités exactes, attributs, langues et extraction depuis les originaux privés. |
| [SearchText](../../app/Services/SearchText.php), [TermController](../../app/Http/Controllers/TermController.php), [tests catalogue](../../tests/Feature/CatalogueSearchTest.php) | Présélection existante, hydratation bornée et repères de test. |
| [AuditService](../../app/Services/AuditService.php) | Journal privé ; le filtrage actuel ne masque pas les textes et URL arbitraires. |

Références officielles consultées le 14 octobre 2026 : [Laravel Sanctum](https://laravel.com/docs/13.x/sanctum), pour les tokens hashés, permissions, révocation et expiration ; [requêtes interorigines des extensions Chrome](https://developer.chrome.com/docs/extensions/develop/concepts/network-requests), pour les permissions d’hôte et les appels depuis le service worker. Les règles de compte, quotas, classement et rétention ci-dessus sont les choix de cette proposition, pas des valeurs imposées par ces bibliothèques.
