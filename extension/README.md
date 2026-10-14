# MyAB Translation Inspector

Extension navigateur (Chrome et Firefox, **un seul code source**) qui retrouve la clé technique d'un texte affiché dans l'ERP MyAB, pour corriger sa traduction dans l'application de gestion des traductions.

**Elle ne modifie jamais MyAB.** Elle identifie un texte, collecte son contexte, interroge l'API de l'application de gestion et affiche les correspondances avec un lien vers la traduction.

> **Vérification.** `npm install && npm run check` contrôle le typage, les tests et les builds des deux navigateurs. La [recette du backend réel](../docs/technique/api-extension-implementation.md) décrit les vérifications intégrées et les conditions de mise en service.

## Utilisation

1. Ouvrez la page **Paramètres** (ouverte automatiquement après l'installation) : URL de l'API, token, domaines MyAB. Le navigateur demande l'autorisation d'accéder à ces domaines.
2. Dans MyAB, activez le **mode inspecteur** depuis l'icône de l'extension (ou `Alt+Maj+M`, modifiable). Le badge **ON** s'affiche. Rechargez les onglets MyAB déjà ouverts.
3. **Alt + clic** sur un texte : l'élément est surligné, le panneau s'ouvre en haut à droite avec les clés trouvées, triées par score.
4. Actions : *Copier la clé*, *Copier le texte source*, *Ouvrir la traduction*, *Rechercher manuellement dans l'application*, et (si activé et autorisé) *Signaler cette correspondance comme correcte*. Un aperçu tronqué est annoncé comme tel.
5. **Échap** ferme le panneau et le surlignage ; MyAB n'est plus du tout influencé tant qu'il n'y a pas d'Alt+clic.

Pendant un Alt+clic, MyAB ne reçoit ni `pointerdown`, `mousedown`, `mouseup`, `click` ni `dblclick` : un lien ne navigue pas, un bouton ne se déclenche pas. Sans Alt, ou mode désactivé, rien n'est intercepté.

## Prérequis et commandes

Node.js 20.19 ou plus récent (hors production uniquement ; l'application Laravel n'en a pas besoin). Toutes les commandes se lancent depuis `extension/`.

```bash
cd extension
npm install
npm run check        # tsc --noEmit + tests + build des deux navigateurs
```

| Commande | Effet |
| --- | --- |
| `npm test` | Tests Vitest (jsdom) |
| `npm run typecheck` | Contrôle TypeScript strict |
| `npm run build` | `dist/chrome` et `dist/firefox` |
| `npm run build:chrome` / `build:firefox` | Un seul navigateur |
| `npm run dev:chrome` / `dev:firefox` | Build + surveillance des sources |
| `npm run package` | Build + `.zip` des deux navigateurs dans `dist/packages/` |
| `npm run serve:test` | Sert la page de test sur `http://localhost:5173` |

## Build et chargement dans Chrome

```bash
npm run build:chrome          # → dist/chrome
```

1. `chrome://extensions` → activer le **mode développeur**.
2. **Charger l'extension non empaquetée** → dossier `extension/dist/chrome`.
3. Après chaque `npm run dev:chrome`, cliquer sur « Recharger » de l'extension.

Package : `npm run package:chrome` produit `dist/packages/myab-translation-inspector-chrome-<version>.zip`, au format attendu par le Chrome Web Store. Chrome 110 ou plus récent (Manifest V3, service worker).

## Build et chargement dans Firefox

```bash
npm run build:firefox         # → dist/firefox
```

1. `about:debugging#/runtime/this-firefox` → **Charger un module complémentaire temporaire…**
2. Choisir `extension/dist/firefox/manifest.json`. (Ou : `npx web-ext run -s dist/firefox`.)

Package : `npm run package:firefox` produit `dist/packages/myab-translation-inspector-firefox-<version>.zip`. **Firefox exige une signature** pour installer durablement un module : soumettre ce `.zip` à addons.mozilla.org en mode « non listé » pour obtenir un `.xpi` signé (le code n'est pas minifié, ce qui facilite la revue ; fournir aussi les sources du dépôt si AMO les demande). Firefox 128 ou plus récent ; l'identifiant `user@example.test` est dans `manifest.firefox.json`.

## Architecture

```
extension/
├── manifest.chrome.json        service worker (Chrome)
├── manifest.firefox.json       event page + gecko (Firefox)
├── src/
│   ├── content/
│   │   ├── inspector.ts        interception Alt+clic, Échap, dialogue avec le background
│   │   ├── textExtractor.ts    texte visible, nettoyage, libellés de formulaire, repli sur les attributs
│   │   ├── contextExtractor.ts parent, frères, section, modale, fil d'Ariane, colonne de tableau…
│   │   ├── overlay.ts          surlignage + panneau (Shadow DOM fermé, aucun innerHTML)
│   │   ├── overlayStyles.ts
│   │   └── dom.ts              helpers (traversée des Shadow DOM, sélecteurs sûrs)
│   ├── background/
│   │   ├── serviceWorker.ts    seul à connaître le token : appels API, routage des messages
│   │   └── contentScripts.ts   enregistre le content script pour les seuls domaines autorisés
│   ├── api/translationApi.ts   client HTTP : timeout, erreurs typées, validation de la réponse
│   ├── options/                page Paramètres (+ formModel.ts : validation pure, testée)
│   ├── popup/                  interrupteur du mode inspecteur
│   ├── shared/                 réglages, domaines, URL, texte, classement, i18n (fr/de/en), messages
│   └── types/
├── assets/icons/               (régénérables : scripts/generate-icons.ps1)
├── test-pages/                 page HTML de test (+ iframe)
├── tests/                      Vitest
├── scripts/                    build.mjs, serve-test-page.mjs, generate-icons.ps1
└── config.example.json
```

**Mutualisation.** Chrome et Firefox partagent 100 % de `src/`. Seuls diffèrent le manifeste (clé `background`, bloc `browser_specific_settings`) et la cible esbuild. `shared/browserApi.ts` prend `browser` s'il existe, sinon `chrome`, avec des signatures à base de promesses (valables pour les deux en MV3). Aucun framework : l'interface est un panneau et deux pages, du TypeScript/DOM suffit. Bundler : esbuild (un seul `build.mjs`, sans configuration).

**Flux.**

```
Alt+clic ─► inspector (n'importe quelle frame) ─► message ─► background ─► API /search
                                                                   │
panneau (frame du haut) ◄── panel:loading / results / error ◄──────┘
```

Les iframes sont gérées : chaque frame détecte son clic et surligne son élément, mais le panneau n'existe que dans la frame du haut (relais par le background). Le shadow DOM **ouvert** est traversé (`composedPath`) ; un shadow DOM **fermé** est opaque pour tout script de page, extension comprise : le clic est alors attribué à l'élément hôte.

## Configuration (page Paramètres)

| Réglage | Rôle |
| --- | --- |
| URL de l'API | `https://translations.example.test/api/browser-extension`. **HTTPS obligatoire** (HTTP toléré pour `localhost` / `127.0.0.1` seulement) |
| Token / clé API | Envoyé en `Authorization: Bearer`. Jamais affiché, jamais journalisé, jamais exporté. Conservation : sur l'appareil (`storage.local`) ou session du navigateur seulement (`storage.session`, à ressaisir) |
| URL de l'application | Facultatif ; base des liens et de la recherche manuelle. Vide = origine de l'API |
| Mode API | Réelle (défaut) ou test explicite ; aucun repli automatique vers le mock |
| Langue du texte recherché | Automatique depuis la page, ou allemand, français, italien, anglais ; indépendante de la langue de l’interface |
| Domaines MyAB autorisés | Un par ligne : `myab.example.test` ou `*.example.test`. Seuls ces domaines reçoivent le content script |
| Langue de l'interface | Automatique, français, allemand, anglais |
| Touche du clic | Alt (défaut), Alt+Maj ou Alt+Ctrl |
| Raccourci d'activation | Affiché ; se modifie dans le navigateur (`chrome://extensions/shortcuts`, ou Firefox : Gérer les raccourcis) |
| Nombre maximum de résultats | 1 à 20 |
| Collecter le contexte | Désactivé par défaut : seuls texte, URL, titre et langue de la page partent |
| URL de page transmise | Domaine seulement (défaut), sans paramètres, ou complète |
| Délai d'attente | 1 à 30 s |
| Avancé | Sélecteurs CSS facultatifs (fil d'Ariane, modale, titre de section, éléments à ignorer) et gabarit de recherche manuelle (`{appUrl}/terms?q={query}`) |

Rien n'est codé en dur sur MyAB : domaines, structure du DOM et URL sont des réglages. `config.example.json` montre le format ; la page Paramètres sait **importer** et **exporter** ce fichier (jamais le token). Après import, vérifier puis enregistrer.

## Contrat de l'API

Le backend Laravel expose `POST /api/browser-extension/search` et `POST /api/browser-extension/feedback`, avec un jeton personnel Sanctum. Le [contrat version 2](../docs/technique/api-extension-navigateur.md) définit les champs, limites, scores, statuts et erreurs ; la [mise en œuvre](../docs/technique/api-extension-implementation.md) décrit la projection, l’entretien et la recette.

Les recherches réelles envoient `contractVersion: 2` et `textTruncated`, avec la langue du texte inspecté, distincte de celle du panneau. Chaque résultat identifie une cellule par terme, type DEVCONF, tuple exact, attribut et langue. La valeur importée, la référence et la traduction validée sont affichées séparément. Les propositions pending sont comptées, sans sélection arbitraire d’une traduction en vigueur.

La version reconstruite pour cette API est **0.2.0**. Vérifier cette version dans la fiche de l’extension après chargement. Une erreur 426 demande un client compatible : **Recharger** conserve le chemin de l’installation et ne remplace pas une ancienne copie extraite ailleurs. Dans ce cas, désactiver l’ancienne copie et charger le dossier `extension/dist/chrome` reconstruit, puis rouvrir les paramètres. Les paramètres et permissions sont propres à chaque identifiant d’extension ; renseigner la configuration dans la nouvelle installation et faire autoriser son origine côté API si nécessaire.

Le serveur calcule `matchState`, `exactCandidateCount`, `hasMore` et `candidateSetComplete` avant la limite d’affichage. Plusieurs exacts restent ambigus avec `maxResults: 1`. Le client respecte `exact: false` et annonce les aperçus tronqués. Le feedback est facultatif, signé et sans effet sur la validation ou les campagnes.

Le mode **API de test** accepte encore les réponses du mock ; il est choisi explicitement dans les paramètres. Aucun échec réseau ne change de backend automatiquement. Un client ancien reçoit 426 sur l’API réelle.

## Connexion au backend réel

1. Dans le référentiel, ouvrir **Profil → Extension navigateur**, confirmer le mot de passe et créer un jeton. Copier le secret immédiatement ; la MFA applicable doit avoir été satisfaite à la connexion.
2. Dans les paramètres de l’extension, choisir **API réelle**, l’URL `https://translations.example.org/api/browser-extension` et ce jeton. Pour la recette locale, utiliser le port du serveur Laravel, par exemple `http://127.0.0.1:8000/api/browser-extension`.
3. Ajouter le domaine MyAB, accepter les permissions et tester la connexion. Si la langue de la page est absente ou inconnue, choisir celle du texte dans le réglage dédié.
4. Activer l’inspecteur et recharger l’onglet MyAB. Alt+clic ouvre les correspondances ; leur lien vers la fiche exige la connexion web habituelle.

Après installation des dépendances PHP et migrations, construire la projection avec `php artisan referentiel:extension-search-rebuild`. Une projection manquante ou périmée produit 503. Le serveur factice du port 8787 reste un outil de test et ne consulte pas le référentiel.

## Données collectées et sécurité

**Jamais transmis** : le HTML de la page et les valeurs des champs de saisie (`value`, `textarea`, options de `select` ne sont jamais lus ; seul le libellé d'un champ l'est). Le mode d’URL par défaut transmet seulement l’origine ; les modes plus détaillés peuvent contenir des identifiants métier dans le chemin, fragment ou paramètres. Un texte visible peut lui-même être une donnée métier. Le serveur réel ne conserve aucun texte ni contexte de recherche.

**Contexte limité** : tailles bornées partout (texte 500 caractères, 20 attributs `data-*` de 100 caractères…). Pour une **cellule de tableau**, on n'envoie jamais le texte de la ligne (données métier), seulement l'en-tête de colonne. Le background reconstruit la requête champ par champ avant l'envoi (`sanitizeSearchRequest`).

**Permissions** : `storage` et `scripting` uniquement. Les domaines MyAB et l'API sont des *permissions d'hôte optionnelles* demandées depuis la page Paramètres, par origine. Les permissions des domaines retirés de la liste sont **révoquées**. Le content script n'est déclaré nulle part dans le manifeste : il est enregistré dynamiquement pour les seuls domaines configurés **et** autorisés, et se re-vérifie lui-même (`isHostAllowed`).

**Token** : lu uniquement par le background et la page Paramètres, transmis en `Authorization: Bearer`, jamais dans une URL, jamais journalisé (testé). `storage.local` est isolé des pages web mais **non chiffré** par le navigateur (protection du profil de l'OS) ; le mode « session du navigateur » ne l'écrit pas sur le disque. `storage.sync` n'est volontairement pas utilisé.

**Divers** : HTTPS imposé (y compris pour la réponse finale après redirection) ; `credentials: 'omit'` ; pas d'`eval` ni de script inline (CSP `script-src 'self'`) ; panneau dans un Shadow DOM fermé, contenu de l'API inséré en texte (jamais `innerHTML`) ; événements synthétiques de la page ignorés (`isTrusted`) ; les clics dans le panneau n'atteignent pas les écouteurs de MyAB.

## Page de test

```bash
npm run serve:test      # http://localhost:5173
```

Dans les Paramètres, ajouter le domaine `localhost` (et, pour une API locale, une URL `http://localhost:…/api/browser-extension`), activer le mode inspecteur, recharger la page de test. Elle couvre : texte simple, boutons (dont icône seule et ligature d'icône), liens, libellés et champs, tableau, éléments imbriqués, modales (`<dialog>` natif et ARIA, fermée par clic extérieur), texte répété, Shadow DOM, iframe, éléments décoratifs. Le compteur « Clics reçus par la page » **ne doit pas bouger** lors d'un Alt+clic.

## Tester sans backend (API factice)

Seule l'API est simulée : l'extension peut donc être essayée sur le **vrai MyAB**.

```bash
npm run serve:mock      # API factice seule, http://localhost:8787/api/browser-extension
npm run test:manual     # idem + page de test locale (5173)
```

1. Chargez l'extension (`dist/chrome` ou `dist/firefox`) et ouvrez ses **Paramètres** :
   - **URL de l'API** : `http://localhost:8787/api/browser-extension` (HTTP accepté pour localhost uniquement) ;
   - **Mode API** : API de test ;
   - **Token** : `ok` ;
   - **Domaines MyAB** : le domaine réel de MyAB (par exemple `myab.example.test`) ;
   - (ou **Importer** `config.mock.json`, puis remplacer le domaine `localhost` par celui de MyAB.)

   Enregistrez et acceptez les autorisations demandées par le navigateur.
2. Activez le mode inspecteur, ouvrez MyAB, **rechargez l'onglet**, puis Alt+clic.

Pour un texte quelconque de MyAB, le faux serveur **génère** une réponse : une correspondance exacte (clé dérivée du texte en majuscules, par exemple `BON_DE_LIVRAISON_N_5`, score 94 %) et une variante approximative (62 %). Quelques textes ont une réponse fixe, utile pour voir chaque état d'affichage, et le **token** choisit le comportement (changez-le dans les Paramètres) :

| Texte cliqué | Résultat | Token | Comportement |
| --- | --- | --- | --- |
| « Commande » | correspondance forte (96 % / 71 %) | `ok` | réponse normale |
| « Statut » | plusieurs possibles | `empty` | toujours « aucun résultat » |
| « Enregistrer » | aucune exacte, 3 approximatifs | `401` ou `expired` | 401 token expiré |
| « Valider » | une seule exacte (75 %) | `403` | 403 interdit |
| « Événement » (avec ou sans accent) | « Ereignis » et la clé longue `modulesadministrationManagementcourseCoursesOverviewConfig.Event` (97 % / 74 %) | | |
| « xss » (à ajouter dans la page) | contenu hostile : HTML, `javascript:`, lien hors origine | `429` | 429 + `Retry-After: 5` |
| autre texte | réponse générée (voir ci-dessus) | `500` | erreur serveur |
|  |  | `slow` | 15 s → délai dépassé |
|  |  | `badjson` | 200 mais page HTML |
|  |  | `down` | connexion coupée (réseau) |

Le bouton **Tester la connexion** des Paramètres fonctionne aussi. « Ouvrir la traduction » et « Rechercher manuellement » ouvrent de fausses pages servies par le même serveur. `http://localhost:8787/__requests` liste les métadonnées des 50 dernières requêtes (champs et mode de scénario, sans texte inspecté ni token). Cet endpoint de test n’existe pas dans l’API réelle.

Le même faux serveur est testé automatiquement (`tests/mockApi.test.ts`) avec le vrai client de l'extension.

## Tests

`npm test` couvre : normalisation et extraction du texte, extraction du contexte, appel API (en-têtes, corps, 401/403/404/429/5xx, réseau, délai, annulation, JSON invalide, confidentialité du token), classement et affichage des résultats, interception Alt+clic / Échap, réglages et validation du formulaire, domaines et URL.

## Limites et points à vérifier

- **Recette réelle sur API synthétique** : Chromium 151 et Firefox 153 ont vérifié les permissions locales, Alt+clic, les quatre scénarios, les ambiguïtés avec limite 1 et la révocation. Le passage sur une instance MyAB conserve les réglages de domaine et de DOM à vérifier. Les `.zip` ne sont pas versionnés : `npm run package` les génère dans `dist/packages/`.
- **Firefox non signé** : une extension non signée ne se charge que temporairement (`about:debugging`, `web-ext run`) ; l'installation durable demande une signature AMO (« non listé »).
- **Backend réel.** Les routes `/api/browser-extension/search` et `/feedback` nécessitent les migrations, une projection disponible et un jeton personnel. Le catalogue couvre les sept formats DEVCONF ; les textes MyAB absents de ces fichiers ne sont pas inventés comme correspondances.
- **Firefox** : `optional_host_permissions` en MV3 et `data_collection_permissions` (catégorie `websiteContent`, à ajuster) sont déclarés pour Firefox 128+ ; à confirmer au chargement (avertissements dans `about:debugging`). Le Shadow DOM de l'overlay utilise `adoptedStyleSheets` avec repli sur `<style>`.
- **Shadow DOM fermé** de MyAB : non inspectable (l'hôte est identifié).
- **Onglets déjà ouverts** : à recharger après modification des domaines.
- **Heuristique de texte** : l'élément « logique » est l'ancêtre inline jusqu'au premier bloc ou élément interactif ; plusieurs libellés indépendants dans une même cellule restent séparés. Le texte de l'élément le plus profond est toujours fourni dans `alternativeTexts`. Les réglages « éléments à ignorer » et les sélecteurs avancés permettent d'ajuster au DOM réel de MyAB.
- **Échap** ferme le panneau mais laisse le mode actif ; désactivation par l'icône ou le raccourci.
- La recherche manuelle ouvre `{appUrl}/terms?q=…`, la route de recherche actuelle de ce dépôt ; modifiable via le gabarit.
