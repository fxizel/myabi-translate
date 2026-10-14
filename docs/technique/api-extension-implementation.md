# Mise en œuvre de l’API de l’extension navigateur

Date : 14 octobre 2026. État : implémentation et recette locale terminées.

Laravel expose la recherche réelle de MyAB Translation Inspector et un retour de correspondance facultatif, selon le [contrat version 2](api-extension-navigateur.md). L’extension est construite et distribuée séparément ; aucun runtime Node n’est ajouté à la production Laravel.

## Découpage et livrables

| Lot | Réalisation | Vérification |
| --- | --- | --- |
| Jetons et droits | Sanctum, création/révocation dans le profil, confirmation du mot de passe, preuve MFA, compte courant, quotas partagés et transport borné. | `BrowserExtensionSecurityTest`, tests auth/profil et audit. |
| Recherche | Projection privée par cellule, normalisations versionnées, égalités indexées, préfixes et tokens, comptage des exacts avant limite, génération complète et reprise par lots. | `BrowserExtensionSearchTest`, fixtures Unicode communes, tests d’import et benchmark MariaDB. |
| Client Chrome et Firefox | Protocole v2, mode réel/mock explicite, identité complète, verdict serveur, origine des valeurs, statuts, langues et limites de copie. | `npm run check`, dont content script → background → HTTP. |
| Feedback | Preuve signée liée au token/utilisateur/cellule/révision, revalidation après verrouillage, idempotence, stockage privé et purge. | `BrowserExtensionFeedbackTest` ; aucune écriture dans le workflow. |
| Recette intégrée | API et profils isolés, navigation, jetons, recherches, ambiguïtés et révocation. | Fixtures synthétiques et navigateurs réels. |

Les tokens sont hashés. Leur secret est montré une seule fois dans la réponse de création, jamais en session, dans un lien ou dans le journal. Le catalogue reste commun : les organisations identifient les acteurs et la provenance, sans produire de variantes de traduction.

## Mise en service du backend

Installer les dépendances PHP verrouillées, dont Sanctum et PHP `intl`, puis appliquer les migrations selon la procédure existante. Les [routes API](../../routes/api.php) utilisent [leur propre configuration](../../config/browser-extension.php). Les migrations ajoutent les tables de jetons, projection et feedback et leurs triggers sans reconstruire les tables métier.

Construire explicitement la projection après migration :

```bash
php artisan referentiel:extension-search-rebuild
```

Cette commande lit les originaux privés canoniques et vérifie leur intégrité. Elle traite les termes par lots, conserve un curseur de reprise et active uniquement une génération complète. Une modification du catalogue pendant la reconstruction empêche d’activer un index incomplet : reprendre la commande après résolution de l’erreur ou fin des écritures concurrentes. L’API retourne 503 tant que sa projection est indisponible.

Les imports et décisions synchronisent la projection dans leur transaction. Une modification SQL directe non synchronisée la rend indisponible plutôt que d’affirmer une correspondance unique sur des valeurs périmées. La recherche interactive ne relit pas les originaux.

| Paramètre | Usage |
| --- | --- |
| `APP_URL` | URL canonique des liens vers les fiches ; HTTPS en production. |
| `BROWSER_EXTENSION_ENABLED` | Activation du service, par défaut vrai. |
| `BROWSER_EXTENSION_FEEDBACK_ENABLED` | Activation du feedback, par défaut vrai ; exige aussi permission du token et choix local de l’utilisateur. |
| `BROWSER_EXTENSION_RATE_LIMIT_STORE` | Cache partagé des quotas, par défaut `database`, indépendant du cache général. |
| `BROWSER_EXTENSION_FORCE_HTTPS` | Contrôle HTTPS, activé par défaut en production ; configurer le frontal et les proxys de confiance. |

Les origines d’extension Chrome (`chrome-extension://` suivi d’un identifiant de 32 lettres de `a` à `p`) et Firefox (`moz-extension://` suivi d’un UUID) sont acceptées automatiquement, sans déclaration d’utilisateur, de profil ou d’installation. Les origines de sites web, `null` et les origines malformées sont refusées ; aucune origine universelle ni authentification par cookie n’est activée. Les appels sans `Origin` restent possibles et exigent toujours le bearer. Un prévol ne donne aucun droit sur les termes. Les réponses d’erreur exposent `Retry-After` et `X-Request-ID` aux origines d’extension acceptées.

Le parcours utilisateur consiste à installer l’extension, renseigner l’URL de l’API et son jeton personnel, puis accorder les permissions d’hôte demandées par le navigateur. Le serveur vérifie sur chaque appel le jeton, son expiration et sa révocation, les droits courants du compte et la MFA applicable. L’origine indique seulement un format d’appel accepté et ne prouve aucune identité. L’ancien paramètre `BROWSER_EXTENSION_ALLOWED_ORIGINS` est supprimé ; une valeur encore présente dans un fichier privé est ignorée après mise à jour du serveur et rechargement de sa configuration. Un refus `origin_not_allowed` avec le client officiel demande de vérifier la version du serveur et de recharger l’extension, sans inscrire son identifiant. Après une reconstruction du client, recharger l’extension et rouvrir ses paramètres pour utiliser le nouveau bundle.

## Connexion de l’extension

1. Se connecter au référentiel, ouvrir **Profil → Extension navigateur**, confirmer le mot de passe et créer un jeton. La MFA applicable doit avoir été satisfaite à la connexion. Copier immédiatement le secret, qui ne sera pas réaffiché.
2. Construire avec `cd extension && npm run check`, puis recharger l’extension non empaquetée Chrome ou le module temporaire Firefox.
3. Choisir le mode **API réelle**, renseigner `https://translations.example.org/api/browser-extension` et le jeton personnel. Pour une recette locale, utiliser le serveur Laravel avec `localhost` ou `127.0.0.1`.
4. Ajouter le domaine MyAB, accepter les permissions d’hôte, et choisir la langue du texte si la page n’en fournit pas une reconnue. Cette langue est indépendante de celle de l’interface.
5. Tester la connexion, activer l’inspecteur et recharger l’onglet MyAB. Alt+clic retrouve les cellules sans déclencher l’action de la page. Ouvrir la fiche pour proposer une correction selon les droits web habituels.

Le panneau distingue texte trouvé, référence, valeur importée et traduction validée, avec le type DEVCONF et l’attribut. Une valeur importée peut être retrouvée sans être validée. Les propositions pending sont comptées, sans choix arbitraire de valeur en vigueur. Une ambiguïté reste affichée même avec une limite d’un résultat.

Le mode **API de test** utilise explicitement le serveur factice du port 8787. Il ne lit pas le référentiel. Aucun échec de l’API réelle ne provoque de bascule vers ce serveur. Un client antérieur au contrat v2 reçoit 426 sur la vraie API.

## Entretien et validation

Les commandes d’entretien restent ponctuelles ou planifiées séparément :

```bash
php artisan extension:prune-feedback
php artisan sanctum:prune-expired --hours=24
```

La rétention des feedbacks est `browser-extension.feedback_retention_days`, par défaut 90 jours. La purge ne supprime ni traductions ni journal d’audit. La révocation d’un token ne nécessite pas d’attendre cette purge.

Vérifications du chantier :

```bash
php artisan test
node --test tests/JavaScript/*.test.cjs
(cd extension && npm run check)
php scripts/test_devconf_roundtrip.php --validate-semantics
git diff --check
```

Le [benchmark](../../app/Console/Commands/BenchmarkBrowserExtensionSearch.php) exige `APP_ENV=testing`, `MYABI_ISOLATED_TESTS=1`, une connexion locale et un schéma `myabi_test_*` dédié et vide. Il n’est jamais exécuté contre la base de production :

```bash
php artisan referentiel:extension-search-benchmark --terms=100000 --samples=30
```

### Mise à jour 0.2.1 : connexion sans déclaration d’installation

Déployer le code serveur et recharger sa configuration dans le processus web selon la [procédure de déploiement](../deploiement/deploiement-vps.md). Cette mise à jour ne crée aucune migration. Un ancien `BROWSER_EXTENSION_ALLOWED_ORIGINS` peut être retiré du fichier privé ; il n’est plus lu. L’URL de l’API et les jetons existants restent utilisables tant que leurs droits et leur validité sont conservés.

Construire les paquets avec `cd extension && npm run package`. Pour Chrome, recharger l’installation existante depuis le dossier reconstruit `extension/dist/chrome`, puis rouvrir les paramètres et tester la connexion. Pour Firefox, recharger le module temporaire depuis `extension/dist/firefox` en recette, ou livrer une version signée pour l’installation durable. Aucun identifiant de profil ou d’installation n’est à relever côté serveur.

Contrôles de cette mise à jour : 526 tests PHP réussis (12 145 assertions, 19 tests ignorés selon leurs prérequis), dont 51 tests de sécurité API ; 28 tests JavaScript Laravel ; 297 tests de l’extension, contrôle TypeScript et constructions/paquets Chrome et Firefox 0.2.1. Pint et `git diff --check` sont conformes. Les tests couvrent plusieurs installations Chrome et profils Firefox, les origines web ou malformées refusées, les prévols restreints, le refus des cookies seuls et des jetons révoqués, ainsi que les erreurs CORS lisibles. La recette navigateur réelle et les contrôles MariaDB/DEVCONF ci-dessous décrivent la livraison initiale ; ils n’ont pas été relancés pour cette modification du transport.

### Résultats de recette du 14 octobre 2026

| Contrôle | Résultat |
| --- | --- |
| Suite PHP complète | 499 tests réussis, 11 867 assertions ; 19 tests ignorés pour leurs prérequis de plateforme ou de base. |
| Recherche et imports | 19 tests ciblés, 246 assertions, réussis sur SQLite et MariaDB 11.4.8. |
| Concurrence MariaDB | 5 tests, 56 assertions, avec deux processus : quotas token/utilisateur partagés, verrouillage de projection, rollback et activation atomique. |
| JavaScript Laravel | 28 tests réussis. |
| Extension | 294 tests réussis, dont les diagnostics 403 et la sonde en mode réel/mock ; contrôle TypeScript et constructions Chrome/Firefox réussis. |
| Navigateurs réels | Chromium 151 et Firefox 153, profils jetables : permission limitée à l’hôte local, sonde v2, Alt+clic, valeur validée/importée, ambiguïté et plusieurs attributs avec limite 1, révocation. Feedback signé envoyé depuis le panneau Chromium. |
| Profil web | Connexion, confirmation du mot de passe, création sans cache, secret montré une fois, recherche sans cookie et révocation vérifiés. |
| DEVCONF | Sept originaux privés, 581 142 enregistrements : contrôle sémantique et identité des octets réussis. |
| Qualité | Pint, validation stricte de Composer et `git diff --check` réussis. |

Le benchmark synthétique couvre 100 000 termes des sept formats, soit 1 157 860 cellules et 1 571 382 valeurs indexées. Avec 30 mesures à chaud par mode, le p95 de `SearchService` est de 97,390 ms en recherche exacte, 77,267 ms en recherche normalisée et 33,993 ms en recherche tolérante. Les mesures utilisent MariaDB 11.4.8 et PHP 8.4.26 sur un Core Ultra 5 235U, 14 processeurs logiques et environ 15,4 Gio de RAM. Elles excluent authentification, transport HTTP et réseau ; elles ne constituent pas une mesure du déploiement de production.

Les cinq tests de concurrence sont ignorés par la suite SQLite habituelle et exécutés séparément contre un schéma MariaDB isolé. Le [lanceur de recette](../../tests/Support/run-browser-extension-concurrency.php) exige `MYABI_ISOLATED_TESTS=1`, la connexion privée du runtime de test et la vérification du répertoire de données ; il crée et supprime son propre schéma. Les extensions chargées localement ont été testées dans des profils séparés des profils personnels. La distribution Firefox signée et la configuration du frontal de production suivent les procédures de livraison existantes.

Les jeux, profils navigateur, credentials de recette et rapports détaillés restent dans `.runtime`, hors Git et des livraisons. Les tests utilisent des fixtures synthétiques. Les contrôles des originaux publient seulement leur bilan et ne prétendent pas attester une réimportation dans un ERP réel. Les libellés MyAB absents des formats DEVCONF restent hors du catalogue.
