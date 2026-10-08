# Design du référentiel myABI

Ce document décrit la présentation des écrans Blade du référentiel des traductions. Il reprend les valeurs effectivement appliquées dans [public/css/app.css](public/css/app.css). En cas d’écart, la feuille de styles de l’application fait foi ; ce document doit être mis à jour avec elle.

Il fixe une présentation, pas les règles métier : les spécifications fonctionnelles et techniques à la racine restent la référence pour les parcours, les droits et les données.

**Origine.** Ce document remplace la maquette HTML myABI du 22.09.2026, réalisée à partir de captures myABI et sans backend, qui succédait elle-même à la variante Swiss approuvée le 20.09.2026. Ces évolutions visuelles ne valent pas validation des spécifications. La maquette reste consultable dans l’historique Git, dossier `maquette/` du commit `192a626`.

## Principes

- **Outil de travail dense.** Interface d’administration pour des personnes qui traitent des centaines de termes : lignes compactes, petites tailles de texte, peu de décoration, aucune ombre portée ni dégradé.
- **Cadre persistant.** Bandeau bleu nuit, onglets de travail, navigation latérale blanche et barre de contexte restent identiques sur toutes les pages, authentification comprise.
- **Surfaces blanches sur fond gris clair.** Le contenu vit dans des panneaux blancs à filet fin ; les en-têtes de panneaux, barres d’outils et pieds de tableau utilisent un gris très clair.
- **Une seule couleur d’action.** Le bleu `#2266a4` signale tout ce qui est cliquable ou actif ; le rouge est réservé au profil et aux erreurs, l’ambre à l’onglet actif et aux états « à réviser ».
- **Ressources locales uniquement.** CSS et JavaScript statiques, icônes SVG incluses dans les vues, polices système. Aucun CDN, aucune police web, aucune bibliothèque d’interface.
- **Valeurs métier visibles telles quelles.** Les textes source, clés et traductions conservent leurs retours à la ligne (`white-space: pre-wrap`) et se coupent n’importe où (`overflow-wrap: anywhere`) plutôt que de déborder.

## Couleurs

Les jetons sont déclarés sur `:root` dans `app.css`. Les autres valeurs sont des nuances fixes associées à un composant.

### Jetons

| Jeton | Valeur | Usage |
|---|---|---|
| `--navy` | `#102348` | Bandeau, filet gauche de 4 px du cadre |
| `--blue` | `#2266a4` | Liens, éléments actifs, texte source, icônes de recherche |
| `--ink` | `#172d42` | Texte courant |
| `--muted` | `#536b80` | Libellés, métadonnées, légendes |
| `--line` | `#cfd8e2` | Bordures des panneaux, champs, tableaux |
| `--soft` | `#f7f9fb` | Champs en lecture seule, blocs `pre` |
| `--canvas` | `#ebeff3` | Fond de page |
| `--tint` | `#e7f2ff` | Sélection, survol des lignes, élément de navigation actif |
| `--error` | `#a12f2f` | Erreurs, actions dangereuses |

### Nuances par composant

| Élément | Valeurs |
|---|---|
| Bouton principal | fond `#1764b5`, survol `#105294`, texte blanc |
| Focus clavier | contour `#176cc0`, 2 px, décalage 2 px |
| Bordures de champ | repos `#cad5e0`, focus `#6d9dcc`, survol bouton `#9ebfdf`, actif `#c3dfff`, langue active `#a9ccef` |
| Placeholder | `#687d90` |
| En-têtes de panneau, barres d’outils, pieds de tableau | `#f8fafc` |
| En-tête de tableau | fond `#dce3e9`, filets `#ccd5df` / `#c1cbd7` |
| Cellules | filets `#eef1f5` / `#e9edf1`, lignes paires `#fbfcfd` |
| Bandeau | texte `#ecf6ff`, icônes `#e8f3ff`, texte secondaire `#c8d8ec`, survol `#284165` |
| Profil (bandeau) | pastille `#df0018`, survol `#bf0015`, organisation `#d6e3f3` |
| Onglets de travail | fond `#d9e2ec`, bordure `#b9c8d9`, texte `#233c55`, survol `#eaf0f6`, actif blanc avec filet supérieur `#efb430`, étoile `#af7f13` |
| Navigation | survol `#f2f6fb`, compteur `#e9eef4` |
| Étape active | fond `#f1f7fe`, filet inférieur `#2778ba` |

### États

Badges et messages partagent quatre tonalités, plus une neutre.

| Tonalité | Texte | Fond | Bordure | États |
|---|---|---|---|---|
| Neutre | `#53677b` | `#f4f6f8` | `#dbe3eb` | par défaut |
| Succès | `#32634d` | `#f0f7f3` | `#d6e5db` | approved, validated, published, applied, current, ready, completed |
| En cours | `#2266a4` | `#eaf3fd` | `#cddff3` | pending, proposed, queued, analyzing, applying |
| À réviser | `#825f20` | `#fff8e9` | `#eadcbd` | review, obsolete, analyzed |
| Échec | `#a12f2f` | `#fff4f3` | `#edc5c1` | rejected, failed, blocked |

Messages (`.notice`) : filet gauche de 3 px. Information `#4785ba` sur `#f7fbff` (texte `#405c76`) ; succès `#4e9065` sur `#eff7f2` ; erreur `--error` sur `#fff4f3` (texte `#761b1b`) ; avertissement `#9a6500` sur `#fff8e6` (texte `#684500`).

La couleur ne porte jamais seule l’information : chaque badge affiche un libellé, précédé d’un point de 4 px.

## Typographie

Police système : `Arial, "Helvetica Neue", sans-serif`. Corps 13 px, interligne 1.4. Les nombres en colonne utilisent `font-variant-numeric: tabular-nums`.

| Rôle | Taille | Graisse |
|---|---|---|
| Titre d’authentification | 21 px | 600 |
| `h1` titre de page | 17 px | 600 |
| `h2` | 16 px | 600 |
| `h3`, en-tête de panneau, fil d’Ariane courant | 14 px | 600 |
| Corps | 13 px | 400 |
| Tableaux, champs, boutons, messages | 12 px | 400 (boutons 600) |
| Métadonnées, `small`, pagination, onglets latéraux réduits | 11 px | 400 |
| Clés, légendes de cellule, badges, pied de page | 10 px | 400 |
| Texte source dans la fiche | 16 px, bleu | 600 |
| Valeurs dans la fiche | 15 px | 400 |
| Chiffre de statistique | 25 px, bleu | 500 |
| Variables (`.token`) | 12 px `Consolas, monospace` sur `--tint` | 400 |

Les libellés de section de la navigation sont en capitales, 11 px, graisse 600, interlettrage 0.15 px.

## Espacements, formes et profondeur

- Échelle resserrée : 3, 4, 5, 6, 8, 9, 10, 12, 14 px. Contenu principal : `12px 14px 20px`. Panneau (`.block`) : 14 px.
- Hauteurs : bandeau 50 px, bande d’onglets 34 px, barre de contexte 43 px, bouton et champ 29 px, petit bouton 25 px, entrée de navigation 34 px.
- Rayons : 3 px pour boutons, champs, onglets et navigation ; 2 px pour badges, boutons du bandeau et filtres de type. Pas d’angle plus arrondi, sauf les points d’état.
- Filets de 1 px partout ; 2 px pour le haut de l’onglet actif et le bas de l’étape active ; 3 px pour le bord gauche des messages ; 4 px pour le bord gauche du cadre.
- Aucune ombre, sauf la fenêtre déroulante des actions groupées : `0 3px 12px #1023481a`.

## Densité

Le bouton **Compact** de la barre de contexte bascule l’espacement vertical des cellules de tableau :

- compact (par défaut) : `--row-padding: 7px` ;
- confortable : `body[data-density=comfortable]`, `--row-padding: 14px`.

Le bouton porte `aria-pressed`. Le changement se fait sans rechargement et sans remplacer les champs en cours de saisie. Cette préférence est la seule donnée enregistrée dans le navigateur.

## Cadre de l’application

```
┌──────────────────────────────────────────────────────────────────┐
│ [logo]  ⌂ ?  Référentiel de traductions          [● Profil] Org. │ bandeau 50 px
│ ▦ [★ Gestion des traductions] [Fiche terme ×]                    │ onglets 34 px
├────────────┬─────────────────────────────────────────────────────┤
│ RÉFÉRENTIEL│ Référentiel › Traductions   ▤ Compact  FR ▾  Quitter│ contexte 43 px
│ Traductions│─────────────────────────────────────────────────────│
│ Validation │ Titre de page                     [Actions]         │
│ Imports    │ ┌ barre de langues ────────────────────────────────┐│
│ Publicat.  │ │ barre d’outils / filtres                         ││
│ ────────── │ │ tableau                                          ││
│ CONTEXTE   │ │ pagination                                       ││
│ …          │ └──────────────────────────────────────────────────┘│
│ pied       │ pied de page                                        │
└────────────┴─────────────────────────────────────────────────────┘
  210 px
```

- **Bandeau** (`.masthead`) : logo clair sur marine (168 × 46 px, `public/images/branding/myabi-translate-header-v2.png`), boutons d’icône 30 px, titre de l’application, puis à droite la pastille rouge du profil et l’organisation.
- **Onglets de travail** (`.app-tab-strip`) : onglet fixe « Gestion des traductions » marqué d’une étoile, puis les fiches ouvertes avec un bouton de fermeture. Un seul onglet porte `aria-current=page`. Les noms trop longs sont tronqués avec points de suspension (largeur maximale 340 px).
- **Navigation latérale** (`.app-sidebar`) : quatre espaces métier — Traductions, Validation, Imports, Publications — puis, selon les droits, les entrées d’administration. Le compteur de la validation affiche le nombre de propositions en attente. Sous la navigation : le contexte de travail (version myABI, organisation, langue de référence) et un pied discret.
- **Barre de contexte** (`.context-bar`) : fil d’Ariane à gauche ; à droite le bouton Compact, la langue d’interface (DE/FR/IT) et la déconnexion.
- **Pied de page** : portée et langues de référence, 10 px, filet supérieur.

Les écrans d’authentification (`.guest-shell`) gardent le bandeau, masquent la navigation et centrent un panneau de 460 px au maximum.

## Composants

### Boutons

| Variante | Classe | Aspect |
|---|---|---|
| Secondaire (défaut) | `.button` | fond blanc, bordure `--line`, texte bleu 12 px gras ; survol `--tint` |
| Principal | `.button.primary` | fond `#1764b5`, texte blanc ; une seule action principale par zone |
| Discret | `.button.quiet` | sans fond ni bordure |
| Dangereux | `.button.danger` | texte `--error` ; toujours accompagné d’une confirmation ou d’un motif |
| Petit | `.button.small` | 25 px, 11 px |
| Texte | `.text-button` | lien sans cadre, souligné au survol |

Une icône de 16 px peut précéder le libellé ; un bouton d’icône seule porte un `aria-label`. Désactivé : opacité 0.45, curseur interdit.

### Champs et filtres

- `.field` empile un libellé gris (12 px) au-dessus du contrôle ; `.field-hint` ajoute une aide de 11 px.
- `.input` : 29 px, bordure `#cad5e0`, rayon 3 px ; la zone de texte fait 95 px de haut, 14 px, redimensionnable verticalement. Lecture seule : fond `--soft`.
- Recherche : icône loupe bleue placée dans le champ, retrait gauche de 31 px.
- `.toolbar` : barre grise `#f8fafc` alignant recherche (base 220 px) et filtres (base 160 px), puis « Plus de filtres » dans un `details` qui ouvre une grille de quatre colonnes.
- `.type-counts` : sous la barre d’outils, un onglet par type DEVCONF avec son compteur ; le type courant est blanc, encadré et en gras.

### Barre de langues

Au-dessus du catalogue et de la validation, `.language-bar` liste les langues de traduction (nom + code régional en 10 px). La langue courante prend le fond `--tint` et une bordure `#a9ccef`. Quand elle précède les filtres, elle se soude au bloc (pas de marge ni de bordure inférieure).

### Tableaux

- Toujours dans `.table-wrap`, qui porte la bordure et le défilement horizontal propre au tableau ; la page elle-même ne défile jamais horizontalement.
- En-tête gris `#dce3e9`, 12 px gras ; cellules 12 px alignées en haut, filets verticaux très clairs ; lignes paires `#fbfcfd` ; survol et focus interne en `--tint`.
- Largeurs minimales : 920 px pour le catalogue et la validation, 650 px pour les autres tableaux sur mobile.
- Cellule de terme : texte source en lien bleu gras, clé technique dessous en 10 px gris (`.key`), type ou légende en `.cell-caption`.
- Valeur absente : `.empty-value`, 11 px gris, jamais une cellule vide.
- Édition dans le tableau : zone de texte de 50 px, actions alignées à droite, erreurs client masquées tant qu’elles sont vides.
- `.pagination` se soude sous le tableau : compteur à gauche, petits boutons à droite.
- `.bulk-bar` se soude au-dessus : nombre de lignes sélectionnées et actions groupées.

### Panneaux

- `.surface` : fond blanc, bordure `--line`, sans rayon.
- `.block` : 14 px de marge interne ; son premier titre devient un en-tête gris pleine largeur avec filet inférieur.
- `.panel-label` : libellé de section 12 px gras gris, actions éventuelles à droite.
- `.meta` : liste de définitions en deux colonnes (100 px pour le terme, 90 px sur mobile).
- `.divider` : filet `#e0e6ed`, 16 px au-dessus et au-dessous.

### Messages et retours

- `.notice` (information, `.success`, `.error`, `.warning`) en tête de page ou de panneau ; les listes d’erreurs y sont des `ul`.
- Les retours d’une action passent par une zone `role="status"` / `aria-live="polite"`.
- `.client-error` : erreur de saisie sous le champ, 12 px, couleur `--error`.
- `.empty-state` : panneau centré, titre 16 px, texte gris de 510 px au maximum, action éventuelle.

### Badges

`.badge` et `.badge-<état>` : 10 px, rayon 2 px, point de couleur avant le libellé, sans retour à la ligne. Tonalités : voir [États](#états).

### Étapes, import et statistiques

- `.steps` : trois étapes en grille ; l’étape courante (`aria-current=step`) est bleue, fond `#f1f7fe`, filet inférieur de 2 px. Le numéro est dans un carré de 23 px.
- `.upload` : zone de dépôt grise à bordure pointillée `#b7c8d9`, 24 px de marge.
- `.stats` : quatre indicateurs séparés par des filets, chiffre bleu 25 px, libellé 11 px gris ; deux colonnes sur mobile.
- `progress` et `meter` prennent la couleur bleue.

### Fiche terme et historique

- `.detail-grid` : deux colonnes (0.9 / 1.3) — à gauche le texte source et ses métadonnées, à droite la valeur en vigueur, la proposition et l’éditeur. Une seule colonne sous 1150 px.
- Les variables détectées s’affichent en `.token` ; l’éditeur signale une variable manquante ou ajoutée avant la soumission.
- `.edit-meta` sous l’éditeur : état du brouillon (bleu) et compteur.
- `.history` : liste à filets, valeur en 12 px puis auteur et date en 11 px gris.
- Comparaisons : `ins` sur `--tint` souligné, `del` gris barré ; `.compare-grid` sur deux colonnes.

### Icônes

Jeu interne dans [resources/views/partials/icon.blade.php](resources/views/partials/icon.blade.php) : `viewBox` 24, trait 1.7, extrémités arrondies, `currentColor`, `aria-hidden`. Taille 16 px, 20 px dans le bandeau. Noms disponibles : search, home, user, help, grid, languages, check-list, upload, download, settings, history, rows, close, entre autres. Toute nouvelle icône y est ajoutée dans le même style ; pas de police d’icônes ni de bibliothèque externe.

### Logo

Le logo retenu le 23.09.2026 associe deux tuiles « A » (marine) et « 文 » (bleu `#2266a4`) reliées par deux flèches, avec le mot-symbole « myABI » au-dessus de « Translate ». Dans le bandeau, la variante claire utilise le blanc et le bleu ciel `#8FCBFF` sur marine. Le cadrage CSS conserve les proportions et masque les marges verticales ; le lien d’accueil a pour nom accessible « myABI Translate ». La favicon est [public/favicon.svg](public/favicon.svg), servie avec une URL versionnée. Les fichiers sources et les prompts de création sont dans [docs/branding](docs/branding/README.md).

## Écrans

| Espace | Contenu |
|---|---|
| **Traductions** | Barre de langues, recherche et filtres, compteurs par type, tableau : terme et clé, type, valeur de référence, traduction en vigueur, proposition et état. Ouverture d’une fiche dans un nouvel onglet de travail. |
| **Fiche terme** | Source et métadonnées (clé, type, portée, contexte), valeur en vigueur, proposition, saisie avec contrôle des variables, historique des révisions. |
| **Validation** | File des propositions par langue : en-tête du terme, colonnes source / valeur en vigueur / proposition avec différences mises en évidence, auteur, validation ou rejet motivé, actions groupées. |
| **Imports** | Parcours en trois étapes : dépôt du fichier, analyse et rapport, application en propositions. |
| **Publications** | Catalogues publiés par organisation et version, figés, téléchargeables ; création d’une nouvelle publication. |
| Profil, administration, audit, aide | Même cadre, mêmes panneaux et tableaux. |

L’interface rappelle visuellement qu’une proposition reste distincte de la valeur en vigueur jusqu’à sa validation, que la validation crée une révision commune aux langues du terme et que les catalogues publiés ne changent plus.

## Adaptation aux écrans

| Seuil | Changements |
|---|---|
| ≤ 1150 px | Navigation de 180 px, titre du bandeau masqué, fil d’Ariane réduit à la page courante, recherche sur toute la largeur, filtres sur deux colonnes, fiche sur une colonne |
| ≤ 760 px | Navigation au-dessus du contenu en boutons répartis sur la largeur, sans icônes ni contexte ; organisation du profil masquée ; formulaires et comparaisons sur une colonne ; statistiques sur deux colonnes ; en-têtes de page empilés |
| ≤ 490 px | Logo 136 × 38 px, navigation en 11 px, onglets limités à 165 px quand plusieurs sont ouverts, filtres et étapes empilés |
| Pointeur tactile | Cibles de 44 px au minimum ; champs de 40 px en 16 px pour éviter le zoom automatique |

Contrôle de référence : bureau et 390 px de large, sans débordement horizontal de la page.

## Accessibilité

- Focus visible sur tous les éléments interactifs (`:focus-visible`, contour bleu de 2 px).
- `aria-current` pour la page, l’onglet, la langue et l’étape courants ; `aria-pressed` pour les bascules.
- Lien d’évitement vers le contenu (`.skip-link`) et classe `.sr-only` pour les libellés masqués.
- Tableaux défilants nommés par un `aria-label` ou une `caption`.
- Animations limitées à des transitions de couleur de 0.12 s, uniquement avec `prefers-reduced-motion: no-preference`.
- Interface disponible en allemand, français et italien : prévoir des libellés jusqu’à 40 % plus longs que le français, sans largeur fixe sur le texte.
- Les valeurs issues des données sont toujours échappées à l’affichage, y compris dans les titres d’onglet.

## Règles pour une nouvelle page

1. Utiliser `layouts/app` et le partial `heading` ; ne pas recréer de cadre.
2. Composer avec les classes existantes (`.surface`, `.block`, `.toolbar`, `.table-wrap`, `.notice`, `.badge-*`) avant d’en créer une nouvelle.
3. Ajouter tout nouveau style dans `public/css/app.css`, avec les jetons existants ; une nouvelle couleur doit d’abord être ajoutée à ce document.
4. Vérifier le rendu en DE/FR/IT, en mode compact et confortable, sur bureau et à 390 px.
5. N’utiliser que des données fictives dans les captures et la documentation.
