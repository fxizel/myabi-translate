# MyAB Translation Inspector

Extension navigateur (Chrome et Firefox, **un seul code source**) qui retrouve la clé technique d'un texte affiché dans l'ERP MyAB, pour corriger sa traduction dans l'application de gestion des traductions.

**Elle ne modifie jamais MyAB.** Elle identifie un texte, collecte son contexte, interroge l'API de l'application de gestion et affiche les correspondances avec un lien vers la traduction.

> **Première vérification.** Ce code a été écrit sans pouvoir être compilé ni exécuté (Node n'était pas installé sur la machine de développement). Avant tout usage : `npm install && npm run check` (typage, tests, build). Voir [Limites et points à vérifier](#limites-et-points-à-vérifier).

## Utilisation

1. Ouvrez la page **Paramètres** (ouverte automatiquement après l'installation) : URL de l'API, token, domaines MyAB. Le navigateur demande l'autorisation d'accéder à ces domaines.
2. Dans MyAB, activez le **mode inspecteur** depuis l'icône de l'extension (ou `Alt+Maj+M`, modifiable). Le badge **ON** s'affiche. Rechargez les onglets MyAB déjà ouverts.
3. **Alt + clic** sur un texte : l'élément est surligné, le panneau s'ouvre en haut à droite avec les clés trouvées, triées par score.
4. Actions : *Copier la clé*, *Copier le texte allemand*, *Ouvrir la traduction*, *Rechercher manuellement dans l'application*, et (si activé) *Signaler cette correspondance comme correcte*.
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
| Domaines MyAB autorisés | Un par ligne : `myab.example.test` ou `*.example.test`. Seuls ces domaines reçoivent le content script |
| Langue de l'interface | Automatique, français, allemand, anglais |
| Touche du clic | Alt (défaut), Alt+Maj ou Alt+Ctrl |
| Raccourci d'activation | Affiché ; se modifie dans le navigateur (`chrome://extensions/shortcuts`, ou Firefox : Gérer les raccourcis) |
| Nombre maximum de résultats | 1 à 20 |
| Collecter le contexte | Désactivé : seuls texte, URL, titre et langue de la page partent |
| URL de page transmise | Sans paramètres (défaut), complète, ou domaine seulement |
| Délai d'attente | 1 à 30 s |
| Avancé | Sélecteurs CSS facultatifs (fil d'Ariane, modale, titre de section, éléments à ignorer) et gabarit de recherche manuelle (`{appUrl}/terms?q={query}`) |

Rien n'est codé en dur sur MyAB : domaines, structure du DOM et URL sont des réglages. `config.example.json` montre le format ; la page Paramètres sait **importer** et **exporter** ce fichier (jamais le token). Après import, vérifier puis enregistrer.

## Contrat de l'API

L'application de gestion doit exposer (voir *Limites* : ces routes n'existent pas encore dans ce dépôt Laravel).

### `POST {apiUrl}/search`

En-têtes : `Authorization: Bearer <token>`, `Content-Type: application/json`, `Accept: application/json`. Les cookies ne sont pas envoyés.

```json
{
  "text": "Commande",
  "pageUrl": "https://myab.example.test/orders#/detail",
  "pageTitle": "Détail de la commande",
  "surroundingText": "Informations générales Fournisseur Commande Statut",
  "elementTag": "span",
  "elementId": "",
  "elementClasses": ["lbl"],
  "ariaLabel": "",
  "title": "",
  "dataAttributes": { "data-i18n-key": "PURCHASE_ORDER" },
  "lang": "fr",
  "maxResults": 5,
  "uiLanguage": "fr",

  "alternativeTexts": ["mot en gras"],
  "role": "button",
  "elementName": "supplier",
  "inputType": "text",
  "ariaAttributes": { "aria-describedby": "tip" },
  "parentText": "Commande Statut",
  "siblingTexts": ["Statut"],
  "formLabel": "Fournisseur",
  "sectionTitle": "Informations générales",
  "inDialog": true,
  "dialogTitle": "Confirmer la commande",
  "breadcrumbs": ["Accueil", "Achats", "Commandes"],
  "tableColumn": "Fournisseur",
  "frameUrl": "https://myab.example.test/frame"
}
```

Les champs jusqu'à `uiLanguage` sont toujours présents (vides si le contexte est désactivé). Ceux qui suivent sont **facultatifs** et omis quand ils n'ont pas de valeur. `alternativeTexts` : autres lectures du texte cliqué (élément le plus profond, texte direct).

Réponse attendue (format de la spécification) :

```json
{
  "query": "Commande",
  "results": [
    {
      "id": 1245, "key": "PURCHASE_ORDER",
      "sourceLanguage": "de", "sourceText": "Bestellung",
      "currentTranslation": "Commande", "proposedTranslation": "Commande fournisseur",
      "module": "Purchasing", "status": "approved", "score": 0.96,
      "translationUrl": "https://translations.example.test/translations/1245"
    }
  ]
}
```

Champs facultatifs : `targetLanguage` (défaut : langue de la page), `exact` (booléen : l'API certifie une correspondance exacte). Le score est un ratio 0–1 (un pourcentage 0–100 est toléré). Les entrées sans `key` sont ignorées. Statuts reconnus : `approved`, `review`, `pending`, `proposed`, `rejected`, `draft`, `obsolete` (autres : affichés tels quels).

### `POST {apiUrl}/feedback` (facultatif, si activé)

`{ "text", "resultId", "key", "pageUrl", "verdict": "correct" }` — réponse JSON quelconque en 2xx.

### Affichage des résultats

Tri par score décroissant (à score égal : exactes d'abord, puis clé). « Exacte » : `exact: true` ou texte identique (sans casse ni accents) à la traduction courante ou au texte source.

| État | Condition | Message |
| --- | --- | --- |
| Correspondance forte | première exacte, score ≥ 0,90 (ou seule) et écart ≥ 0,15 avec la suivante | *Correspondance forte* |
| Plusieurs possibles | au moins une exacte, mais ambiguïté | *Plusieurs correspondances possibles* |
| Aucune exacte | résultats, mais aucun exact | *Aucune traduction exacte trouvée.* puis les résultats approximatifs |
| Aucun résultat | liste vide | *Aucune traduction trouvée.* |

Seuils : `STRONG_SCORE` et `AMBIGUITY_GAP` dans `src/shared/ranking.ts`. Le lien « Ouvrir la traduction » n'est affiché que si `translationUrl` est en HTTPS **sur l'origine de l'API ou de l'URL de l'application** (une API compromise ne peut pas rediriger ailleurs).

### Erreurs

Messages en clair pour : API inaccessible, délai dépassé, 401 (token absent / invalide / expiré), 403, 404 (URL erronée), 429 (avec délai `Retry-After`), 5xx, réponse invalide, redirection HTTP non sécurisée, extension non configurée, domaine non autorisé, permission manquante, extension rechargée. « Réessayer » est proposé pour les erreurs transitoires, « Ouvrir les paramètres » pour celles de configuration.

## Données collectées et sécurité

**Jamais transmis** : le HTML de la page, les valeurs des champs de saisie (`value`, `textarea`, options de `select` ne sont jamais lus ; seul le libellé d'un champ l'est), les identifiants dans l'URL, la query-string (par défaut).

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

Le bouton **Tester la connexion** des Paramètres fonctionne aussi. « Ouvrir la traduction » et « Rechercher manuellement » ouvrent de fausses pages servies par le même serveur. `http://localhost:8787/__requests` liste les 50 dernières requêtes reçues (le token n'y figure jamais) : pratique pour contrôler exactement ce que l'extension envoie.

Le même faux serveur est testé automatiquement (`tests/mockApi.test.ts`) avec le vrai client de l'extension.

## Tests

`npm test` couvre : normalisation et extraction du texte, extraction du contexte, appel API (en-têtes, corps, 401/403/404/429/5xx, réseau, délai, annulation, JSON invalide, confidentialité du token), classement et affichage des résultats, interception Alt+clic / Échap, réglages et validation du formulaire, domaines et URL.

## Limites et points à vérifier

- **Vérifié par les tests automatiques uniquement** (`npm run check` : typage, tests Vitest, build des deux navigateurs). L'Alt+clic réel, le panneau et les permissions optionnelles restent à essayer dans Chrome et Firefox, avec la page de test puis le vrai MyAB. Les `.zip` ne sont pas versionnés : `npm run package` les génère dans `dist/packages/`.
- **Firefox non signé** : une extension non signée ne se charge que temporairement (`about:debugging`, `web-ext run`) ; l'installation durable demande une signature AMO (« non listé »).
- **Backend absent.** Les routes `POST /api/browser-extension/search` (et `/feedback`) n'existent pas encore dans l'application Laravel de ce dépôt. Authentification attendue : jeton porteur propre à l'utilisateur, avec les droits de visibilité de l'utilisateur sur les termes.
- **Firefox** : `optional_host_permissions` en MV3 et `data_collection_permissions` (catégorie `websiteContent`, à ajuster) sont déclarés pour Firefox 128+ ; à confirmer au chargement (avertissements dans `about:debugging`). Le Shadow DOM de l'overlay utilise `adoptedStyleSheets` avec repli sur `<style>`.
- **Shadow DOM fermé** de MyAB : non inspectable (l'hôte est identifié).
- **Onglets déjà ouverts** : à recharger après modification des domaines.
- **Heuristique de texte** : l'élément « logique » est l'ancêtre inline jusqu'au premier bloc ou élément interactif ; plusieurs libellés indépendants dans une même cellule restent séparés. Le texte de l'élément le plus profond est toujours fourni dans `alternativeTexts`. Les réglages « éléments à ignorer » et les sélecteurs avancés permettent d'ajuster au DOM réel de MyAB.
- **Échap** ferme le panneau mais laisse le mode actif ; désactivation par l'icône ou le raccourci.
- La recherche manuelle ouvre `{appUrl}/terms?q=…`, la route de recherche actuelle de ce dépôt ; modifiable via le gabarit.
