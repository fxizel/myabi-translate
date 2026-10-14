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
| `BROWSER_EXTENSION_ALLOWED_ORIGINS` | Origines d’extension autorisées, séparées par une virgule si le navigateur transmet `Origin` ; aucune origine universelle ni cookie. |
| `BROWSER_EXTENSION_RATE_LIMIT_STORE` | Cache partagé des quotas, par défaut `database`, indépendant du cache général. |
| `BROWSER_EXTENSION_FORCE_HTTPS` | Contrôle HTTPS, activé par défaut en production ; configurer le frontal et les proxys de confiance. |

Les origines autorisées sont celles de l’extension, pas le domaine de MyAB. Les appels sans `Origin` exigent toujours le bearer. Un prévol configuré n’accorde aucun droit sur les termes. Les réponses d’erreur exposent `Retry-After` et `X-Request-ID` aux origines autorisées.

Pour Chrome, relever l’identifiant de l’extension dans `chrome://extensions` en mode développeur et autoriser son origine `chrome-extension://<identifiant>`. Pour Firefox, relever l’UUID d’origine du module dans le profil utilisé ; il peut changer lors d’un nouveau chargement temporaire. Une autre installation ou un autre chemin de chargement peut donc exiger une nouvelle origine autorisée. Un refus `origin_not_allowed` concerne cette configuration, indépendamment des permissions du jeton. Après une reconstruction du client, recharger l’extension et rouvrir ses paramètres pour utiliser le nouveau bundle.

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
