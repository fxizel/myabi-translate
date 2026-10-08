# Spécifications techniques – Référentiel des traductions ARGE-ABI

> **Évolution du 22.09.2026 :** à la demande du propriétaire, la préparation du déploiement cible désormais un VPS existant (exemple : `myabi.example.org`). Le [guide VPS](docs/deploiement-vps.md) précise la proposition Docker Compose, l'alternative native, les ressources et les vérifications restantes. Les contraintes propres à alwaysdata Free décrites ci-dessous constituent la référence historique du pilote ; elles sont remplacées pour cette nouvelle cible. Les règles métier, de sécurité, de traçabilité et de recette restent applicables.

| Élément | Valeur |
| --- | --- |
| Version | 3.0 – architecture du pilote gratuit |
| Date | 21.09.2026 |
| Objectif | Livrer rapidement une application utilisable avec une infrastructure simple |
| Base retenue | PHP, Laravel, Blade, MariaDB ; hébergement alwaysdata Free |
| Statut | Spécifications issues des échanges ; faisabilité à mesurer, application non réalisée |

## 1. Les décisions retenues

**Une application PHP, une base MariaDB et des fichiers privés, dans un seul compte d’hébergement géré par alwaysdata.** Les utilisateurs travaillent dans leur navigateur. Le parcours est : importer → proposer une traduction → valider → publier → télécharger.

| Sujet | Décision issue des échanges |
| --- | --- |
| Usage | Pilote pour l’organisation, testé avec des collègues, sans but lucratif |
| Participants | Plusieurs organisations ; 1 à 5 rédacteurs ou validateurs au départ, hors lecteurs |
| Accès | Adresse Internet en HTTPS, avec comptes nominatifs ; aucun VPN requis |
| Localisation | Suisse préférée, Europe acceptée ; alwaysdata en France retenu pour les essais |
| Budget | Hébergement strictement gratuit ; si les quotas sont insuffisants, réévaluer la solution gratuite |
| Administration | Hébergement géré ; aucune administration de serveur par l’équipe |
| Technologies | PHP et MySQL/MariaDB demandés ; Laravel + Blade + MariaDB retenus comme base de réalisation |
| Données | Les sept fichiers et le catalogue complet dès le pilote utilisable |
| Imports | Environ une fois par semaine ; jusqu’à 30 minutes acceptées si la consultation et la progression restent disponibles |
| Livraison | Déploiement automatique depuis GitHub à la publication d’une version validée |

Le budget nul concerne les services nécessaires à l’hébergement du pilote. Le développement et la maintenance applicative demandent du travail humain. Aucun abonnement payant, domaine payant ou service facturé à l’usage n’est nécessaire à la solution décrite.

La [liste des exigences techniques](docs/exigences-techniques.md) fournit les critères de vérification. Les [spécifications fonctionnelles](specifications-fonctionnelles-referentiel-traductions-arge-abi.md) restent la référence métier. La [maquette Swiss](maquette/README.md) conserve ses quatre espaces : Traductions, Validation, Imports, Publications. La refonte de l’infrastructure ne supprime pas de fonctions métier pour réduire le stockage.

## 2. Fichiers et volume à prendre en charge

L’[annexe DEVCONF](docs/formats-devconf.md) décrit les sept formats, leurs colonnes et les ambiguïtés observées. Ces formats sont livrés et versionnés avec le code, sans éditeur de correspondance de colonnes dans l’application.

Les originaux comptent **581 142 enregistrements pour 108 297 018 octets**, soit environ 108 Mo. Incident code représente **415 821 enregistrements et 71 838 828 octets**, soit environ 72 Mo. Ces volumes constituent la base du test ; la taille finale de MariaDB reste inconnue.

Les originaux restent intacts. Le lecteur CSV traite les guillemets, points-virgules internes et champs multilignes. L’export conserve Windows-1252, les en-têtes, colonnes techniques, séparateurs CRLF et retours à la ligne internes. Chaque cellule non modifiée garde sa représentation brute, guillemets facultatifs et espaces compris. Une réécriture CSV standard ne suffit pas pour Incident code.

Les clés sont comparées exactement, sans changement de casse ni suppression d’espaces. Les collisions sont conservées et signalées, sans choix automatique de première ou dernière ligne. L’unique collision exacte Incident code reste à résoudre avec LogObject avant publication du type concerné. Le test de restitution brute reste possible pendant cette clarification.

## 3. Architecture et composants

~~~mermaid
flowchart LR
    U[Navigateur] -->|HTTPS| A
    G[Version validée sur GitHub] -->|Livraison SSH| A
    subgraph H[Compte alwaysdata Free]
        A[Application PHP / Laravel / Blade] --> D[(MariaDB)]
        A --> F[Fichiers privés]
        C[Tâche planifiée PHP] --> D
        C --> F
    end
~~~

| Composant | Choix de réalisation |
| --- | --- |
| Application | Laravel 13 et PHP 8.4 ; versions correctives verrouillées et maintenues |
| Interface | Pages HTML Blade, CSS et JavaScript léger ; reprise de la maquette |
| Base | MariaDB gérée par alwaysdata, cible 11.4 ; tables transactionnelles InnoDB, texte en UTF-8 complet |
| Fichiers | Dossier privé pour originaux, rapports, temporaires et publications |
| Sessions et cache | Composants Laravel utilisant MariaDB ou les fichiers locaux |
| Traitements longs | Une commande PHP du même projet, déclenchée par une tâche planifiée |
| Courriels | SMTP de l’hébergement pour invitations, récupération de compte et notifications prévues |

Laravel 13 exige PHP 8.3 au minimum. PHP web et PHP en ligne de commande utilisent la même version. Les extensions Laravel, PDO MySQL, la conversion Windows-1252 et la production ZIP sont vérifiées à l’installation. [Prérequis Laravel](https://laravel.com/framework/docs/13.x/deployment), [PHP alwaysdata](https://help.alwaysdata.com/fr/docs/hebergement-web/langages/php/), [MariaDB alwaysdata](https://help.alwaysdata.com/fr/docs/hebergement-web/bases-de-donnees/mariadb/).

L’hébergement ne nécessite ni VM à administrer, ni Docker, serveur Node.js, Redis ou processus de traitement permanent. Un seul dépôt contient le code, les migrations, formats et tests. Les ressources et dépendances sont préparées sur le poste de développement ou dans GitHub Actions. Les secrets et données réelles ne font pas partie du paquet livré.

## 4. Données, recherche et historique

| Ensemble | Contenu |
| --- | --- |
| Utilisateurs et organisations | Comptes, rôles, langues et portées autorisées |
| Versions et imports | Version myABI, source, format, empreinte, original, état et rapport |
| Présence des lignes | Import, position de l’enregistrement, identité exacte et lien vers le terme |
| Termes et valeurs courantes | Clés, portée, attributs, références, traductions validées et états |
| Propositions | Valeur, auteur, origine, statut, décision et motif |
| Révisions | États complets et numérotés du terme, auteur, date, origine et différences |
| Publications | Révisions, sources de repli, destinataires, manifeste et fichiers figés |
| Journal et traitements | Actions métier ; étapes, progression, erreurs et reprise des opérations |

Les clés de recherche, langues, statuts et relations sont indexés. Les attributs variables et états de révision peuvent être regroupés en JSON. Le schéma ne matérialise pas toutes les cellules vides possibles et ne recopie pas tout le catalogue à chaque import : un terme inchangé ne reçoit pas de nouvelle révision.

L’identité métier tient compte du type et de sa portée ; elle reste indépendante de la recherche insensible à la casse. Pour les clés longues, une empreinte indexée retrouve les candidats, puis les composantes exactes sont comparées, espaces finaux compris. Une empreinte identique ne suffit pas à fusionner deux lignes.

Les révisions restent immuables, consultables et comparables. Les propositions rejetées et décisions sont conservées. La déduplication physique de fichiers identiques est possible par empreinte, avec des références distinctes pour la provenance et les droits.

La colonne historique `import_batches.is_complete` est conservée pour la compatibilité du schéma, sans être utilisée par le traitement des imports ni par la sélection des sources de publication. Les données existantes ne sont pas reclassées.

Toutes les listes sont filtrées et paginées dans MariaDB, au plus 100 lignes par page. Les index de clés et de texte sont vérifiés sur les données réelles, notamment accents, casse et petits mots ; aucun moteur externe n’est requis. Les quatre champs de création/modification restent affichés. Les dates sont stockées en UTC et affichées en heure suisse.

## 5. Importer, travailler et publier

### Imports hebdomadaires

1. Le Gestionnaire dépose un fichier, choisit version et source. Un seul mode d’import s’applique ; aucun choix de mode n’est présenté. L’application conserve l’original et son empreinte, puis enregistre le travail. Le traitement ne reste pas attaché à la requête web.
2. Une tâche planifiée lance la commande PHP, initialement chaque minute. Elle sort immédiatement sans travail. Un verrou partagé avec publications et déploiements limite à un seul traitement lourd. Les services permanents appartiennent aux offres payantes. [Tâches alwaysdata](https://help.alwaysdata.com/fr/docs/hebergement-web/taches-planifiees/), [services](https://help.alwaysdata.com/fr/docs/hebergement-web/services/).
3. L’analyse lit les CSV progressivement, prépare son résultat hors du catalogue visible et écrit par lots de taille réglable. Étape, position, compteurs et erreurs sont persistés. Une analyse interrompue reprend au dernier point complet.
4. Le Gestionnaire examine le rapport et demande l’application. Une analyse devenue obsolète est recalculée. **L’application d’un fichier est une transaction MariaDB** : les lots internes ne sont pas validés séparément dans le catalogue visible.
5. Les nouvelles modifications métier sont suspendues pendant cette transaction, avec un message explicite ; les écritures déjà commencées terminent avant son ouverture. La consultation reste possible. Une application interrompue est annulée puis recommencée depuis une analyse encore valide, sans doubles propositions. Les sept fichiers sont suivis individuellement ; leur ensemble n’est pas annoncé comme appliqué si l’un reste en échec.

La progression affiche étape, nombre d’enregistrements, erreurs et dernière activité. Pendant l’application, ce suivi est écrit hors de la transaction métier, dans un petit fichier privé remplacé atomiquement, pour rester visible avant le commit. L’avancement est provisoire jusqu’à la confirmation de la transaction. Le navigateur le relit périodiquement, sans connexion permanente. Fermer l’onglet n’annule pas l’import. Le coût de la transaction finale fait partie du test de faisabilité.

Le transfert du fichier de 72 Mo est testé depuis le navigateur. Les limites PHP et HTTP sont ajustées aux fichiers réels. Lectures, exports et écritures SQL restent bornés en mémoire ; aucun chargement du catalogue entier dans une collection PHP n’est admis.

Un import crée des propositions sans valider de traduction. Il conserve les valeurs validées et signale les références modifiées. Il traite les lignes présentes sans retirer ni rendre obsolètes les termes absents du fichier ; leur état, leurs présences antérieures, leurs valeurs et leur historique sont conservés. Les termes portant déjà l’état historique Obsolète le conservent jusqu’à leur réapparition dans un import, qui les réactive.

### Traduction et validation

Les contrôles serveur couvrent droits, placeholders, caractères exportables, valeur examinée et principe des quatre yeux. Une validation enregistre valeur, révision et décision dans une transaction. Le verrouillage de la cellule et un numéro de modification évitent d’approuver une valeur devenue obsolète. Rejets motivés, confirmations et validations en masse suivent le document fonctionnel.

### Publication et téléchargement

La publication capture révisions, lots de repli et destinataires dans une transaction à lecture cohérente, afin que les validations simultanées ne produisent pas un mélange d’états. La génération en flux utilise ensuite ces références immuables, sous le verrou des traitements lourds. Elle conserve les CSV définitifs, leur ZIP et un manifeste : versions, sources, nombres de lignes et empreintes SHA-256.

Tout lot appliqué, ancien ou nouveau, peut être sélectionné comme source selon les règles de version, type et organisation. Le drapeau historique `is_complete` n’entre pas dans cette sélection. L’intégrité du lot et sa couverture réelle des termes actifs de la version sont contrôlées ; une source manquante, altérée ou ne couvrant pas ces termes reste bloquante. Les contrôles d’identité et de source technique du §9 restent applicables.

Les fichiers sont préparés dans un dossier privé temporaire, vérifiés puis déplacés sur le même volume vers leur destination définitive. Une transaction rend ensuite la publication disponible. Une interruption laisse des fichiers privés identifiables pour reprise ou nettoyage ; aucun résultat incomplet n’est téléchargeable.

L’export remplace seulement les cellules autorisées par les valeurs validées figées. Une identité ambiguë ou une source technique manquante bloque le fichier concerné. Le téléchargement contrôle le destinataire et ses droits, journalise l’action et sert les fichiers conservés, sans recalcul depuis le catalogue courant. Les arbitrages de portée, de version et d’encodage figurent au §9.

### Export simple d'un type

Les routes `GET /exports` et `POST /exports/download` sont réservées au Gestionnaire authentifié, avec les mêmes contrôles de session et de MFA que les autres écrans métier. Le formulaire choisit un type DEVCONF, une version admissible et une organisation active. `DevconfExportService` mutualise avec les publications la sélection des sources, les contrôles du §9 et la résolution des révisions validées ; `RawExporter` conserve les cellules techniques et la représentation brute des cellules inchangées.

`SimpleExportService` prépare le CSV sous le verrou exclusif des traitements lourds, dans une transaction de lecture cohérente, avec lectures SQL par lots bornés. Le quota est vérifié avant création du fichier dans `storage/app/private/exports`. La réponse n'envoie aucun octet avant la fin des contrôles. Elle sert une pièce jointe CSV Windows-1252 avec `Cache-Control: private, no-store`, puis supprime le fichier temporaire ; les erreurs de génération suppriment le résultat incomplet. L'événement `export.downloaded` conserve les identifiants, compteurs et empreintes, sans valeurs métier. Aucune publication ni marqueur de publication n'est créé ou modifié. Les téléchargements de publications existantes continuent à servir leurs fichiers figés.

## 6. Comptes et sécurité

Les comptes sont nominatifs, créés par l’Administrateur et rattachés à une organisation. L’authentification locale utilise les composants Laravel, notamment Fortify. Les exigences fonctionnelles sont conservées : invitations de 72 heures, mot de passe d’au moins 8 caractères par défaut, TOTP obligatoire pour Validateur, Gestionnaire et Administrateur lorsque le MFA est activé sur le serveur, sessions de 60 minutes d’inactivité et 12 heures au maximum, révocation et limitation des tentatives.

La longueur minimale des mots de passe est réglable par `AUTH_PASSWORD_MIN_LENGTH=8` dans `.env`, lu via `config('auth.password_min_length')`, avec 8 par défaut en l’absence de variable. Cette politique commune s’applique à l’installation, aux invitations, à la récupération, au profil et aux définitions par l’Administrateur. Après modification, exécuter `php artisan config:clear` en développement, ou `php artisan config:cache` au déploiement et recharger PHP-FPM.

Le booléen `MFA_ENABLED` de `.env`, lu via `config('fortify.mfa_enabled')`, vaut `false` par défaut, y compris en l’absence de variable. Avec `false`, aucun utilisateur n’est soumis à un défi TOTP ou à l’obligation de configuration ; les routes MFA sont indisponibles et le profil masque clés, QR code, codes de récupération et actions MFA. Les secrets existants restent chiffrés en base. Avec `true`, les obligations par rôle s’appliquent et tout compte ayant confirmé son TOTP, même Lecteur, doit passer le défi à la connexion. À la réactivation, une session ouverte sans MFA doit se reconnecter pour un compte déjà configuré ; un compte soumis à l’obligation mais non configuré est dirigé vers le profil. Le réglage n’est pas exposé dans l’interface d’administration. Après modification, effacer uniquement le cache de configuration en développement (`php artisan config:clear`), ou le reconstruire au déploiement (`php artisan config:cache`) et recharger PHP-FPM ; conserver le cache applicatif des limites et de la protection anti-rejeu.

L’Administrateur peut aussi activer un compte et définir son mot de passe sans invitation par e-mail. Cette opération transactionnelle conserve `email_verified_at`, clôt l’invitation, invalide les liens de récupération, révoque les sessions et conserve la configuration TOTP. Le compte technique et les organisations inactives sont exclus ; l’audit identifie l’administrateur sans enregistrer le mot de passe ni son empreinte.

Seul le dossier public de Laravel est servi sur Internet. Fichiers privés, sauvegardes, configurations et journaux restent hors de ce dossier. HTTPS, cookies sécurisés, protection CSRF, échappement HTML et SQL paramétré s’appliquent dès le pilote. Les droits sont contrôlés côté serveur par rôle, langue et organisation, y compris pour rapports et téléchargements.

Les secrets restent hors de Git et des journaux. MariaDB utilise l’hôte et les paramètres fournis par alwaysdata ; aucune base locale ni réseau privé ne sont supposés. Les accès SQL, SSH et au panneau sont limités aux responsables. Le compte SQL applicatif doit pouvoir lire et ajouter au journal d’audit, sans modifier ni supprimer ses entrées ; les migrations utilisent un accès distinct. La configuration de ces droits est vérifiée sur le compte choisi.

Les fichiers comportent des identifiants de collaborateurs : le caractère non lucratif ne change pas leurs règles d’accès. La classification et l’usage institutionnel restent à confirmer dans le cadrage fonctionnel. Aucun test n’expose les originaux au public.

## 7. Vérifier la faisabilité

Ces critères sont des résultats attendus, pas des performances déjà mesurées.

| Contrôle | Résultat attendu |
| --- | --- |
| Installation | PHP web/CLI, MariaDB, HTTPS, SMTP, tâche planifiée et droits SQL vérifiés sur Free |
| Catalogue complet | Sept fichiers, 581 142 enregistrements pris en charge sans réduction de périmètre |
| Durée | Objectif de 30 minutes maximum pour le traitement serveur des sept fichiers, analyse et application comprises ; transfert et attente humaine mesurés séparément |
| Consultation | Recherche et page courante visées en moins de 2 secondes ; consultation et progression utilisables pendant un import avec 1 à 5 utilisateurs actifs |
| Capacité | Respect des quotas ; pics mémoire et stockage mesurés pendant import, réimport, publication et livraison |
| Compatibilité | Restitution sans changement identique octet pour octet des sept originaux ; seules les cellules autorisées changent après validation |
| Retour myABI | Après résolution des ambiguïtés, réimport et contrôle d’au moins 100 traductions françaises dans myABI de test |
| Workflow | Droits, quatre yeux, révisions, rejets et concurrence vérifiés ; 500 propositions traitables dans une session |
| Interruption | Analyse, application et publication interrompues puis relancées sans résultat partiel visible ni duplication |
| Livraison et restauration | Version livrée automatiquement, restauration cohérente testée, anciennes publications toujours identiques |

L’essai inclut deux imports successifs de l’ensemble des sept fichiers, des modifications représentatives, les index, les révisions et une publication pour chaque organisation du pilote. Il mesure aussi la marge nécessaire à la livraison suivante et estime la croissance hebdomadaire. La taille des CSV ne permet pas de promettre combien de semaines tiendront dans 1 Go.

Les tests automatiques ciblent formats, workflow, droits et reprises. GitHub Actions utilise des jeux synthétiques ou anonymisés ; les essais sur les originaux restent dans les environnements autorisés. Les performances sont mesurées chez alwaysdata ; les tests MariaDB utilisent la version du pilote. Le script [audit_devconf.py](scripts/audit_devconf.py) documente les entrées sans remplacer les tests de la future application.

## 8. Hébergement, livraison et exploitation

### Offre gratuite et capacité

alwaysdata Free fournit actuellement **1 Go pour fichiers, courriels et bases réunis, 256 Mo de RAM et ¼ CPU**. Le site utilise l’adresse du compte en alwaysdata.net. Les usages lucratifs sont exclus ; le pilote a été déclaré non lucratif. Cela ne vaut pas confirmation individuelle du fournisseur pour le compte de l’organisation. [Offre et restrictions](https://help.alwaysdata.com/fr/docs/admin-facturation/facturation/prix-cloud-public/).

Les données sont hébergées en France, conformément à l’acceptation de l’Europe pour les essais. [Architecture alwaysdata](https://help.alwaysdata.com/fr/docs/caracteristiques-techniques/architecture/). Le fournisseur gère l’infrastructure ; l’équipe reste responsable du code, des dépendances, comptes, erreurs et restaurations.

Le suivi du stockage inclut index, fichiers de travail, publications et versions applicatives. Une alerte apparaît à 80 % du quota. Avant chaque opération lourde, la marge est contrôlée ; une opération sans espace suffisant est refusée avant modification des données. Seuls les temporaires inutiles et journaux techniques soumis à rotation sont nettoyés automatiquement. Historique métier et publications ne sont pas purgés.

**Si le catalogue complet ne tient pas ou fonctionne mal, alwaysdata Free n’est pas validé.** La solution doit être réévaluée avec le budget de 0 € ; ni passage payant ni réduction des données ne sont approuvés. La conservation illimitée d’un volume croissant dans ce quota n’est pas promise.

### Déploiement depuis GitHub

1. Le responsable publie une version validée dans GitHub, ce qui déclenche GitHub Actions. Une simple modification de branche n’est pas une livraison au pilote.
2. Un environnement Linux exécute les tests, installe les dépendances de production verrouillées et prépare CSS/JavaScript. Le paquet exclut secrets et données métier.
3. Le paquet est transféré par SSH avec clé dédiée et empreinte serveur vérifiée. Le déploiement attend les traitements lourds et empêche de nouveaux départs.
4. Une courte maintenance protège migrations et changement de version. Données et configuration privées sont conservées ; des contrôles de santé précèdent la réouverture.
5. Un échec est signalé. Le retour au code précédent exige un schéma compatible ; sinon une sauvegarde cohérente est restaurée. L’espace pour les deux versions applicatives entre dans le contrôle de quota.

[Déclencheurs GitHub Actions](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows) et [SSH alwaysdata](https://help.alwaysdata.com/fr/docs/hebergement-web/acces-distant/ssh/) documentent ces mécanismes. Minutes et artefacts restent dans les quotas gratuits GitHub ; les dépenses supplémentaires doivent être bloquées. Aucune protection de branche ou d’environnement réservée à un forfait payant n’est requise. [Quotas GitHub](https://docs.github.com/fr/billing/reference/product-usage-included).

### Sauvegardes et entretien

alwaysdata effectue des sauvegardes quotidiennes ; Free en conserve trois jours, hors quota disque. Leur restauration doit être testée avec les fichiers et la base de l’application. [Sauvegardes alwaysdata](https://help.alwaysdata.com/fr/docs/hebergement-web/sauvegardes/).

Avant une migration, une copie cohérente de la base et des fichiers référencés est conservée sur un poste ou espace existant de l’organisation, à désigner. Les écritures sont suspendues, les traitements actifs terminés, puis les données exportées et transférées par canal chiffré. Les secrets nécessaires à la restauration sont conservés séparément. Cette copie ne dépend pas d’un artefact GitHub temporaire.

Un responsable et un suppléant suivent erreurs, stockage, sauvegardes et mises à jour applicatives. Une procédure courte décrit installation, livraison, reprise et restauration. Développement et tests courants restent locaux ; aucun second hébergement payant n’est nécessaire.

## 9. Réalisation et points ouverts

| Étape | Résultat attendu |
| --- | --- |
| 1. Faisabilité | Modèle représentatif, sept fichiers complets, import/réimport, historique, export et ressources mesurés sur Free |
| 2. Application | Cycle métier complet sur les sept types, comptes et droits, maquette intégrée, révisions et publications |
| 3. Mise à disposition | Déploiement GitHub, restauration testée, documentation courte et essais avec les collègues |

Le code peut être développé progressivement ; le pilote utilisable reste conditionné aux sept formats. Les lots 2 et 3 fonctionnels sont conservés. Les reports supplémentaires suggérés en technique v2.0 ne sont pas des décisions acceptées : comparaison des révisions, recherche, interface DE/FR/IT et notifications gardent leurs exigences fonctionnelles.

| Point | Traitement dans ce cadrage |
| --- | --- |
| Capacité Free | À mesurer selon le §7, réimports et espace transitoire compris |
| Identité des lignes | Réponse LogObject sur casse et collision exacte ; toutes les lignes conservées |
| Portée des exports | Le fonctionnel limite la visibilité par organisation mais prévoit toutes les portées à l’export ; harmonisation avant diffusion interorganisation |
| Source technique incomplète | Exiger une source complète identifiée par ligne, sans inventer de colonnes |
| Anciennes versions | Publications existantes téléchargeables ; choix des révisions pour une nouvelle publication d’une ancienne version à clarifier |
| UTF-8 et substitution | Le fonctionnel propose conversion et remplacement par « ? » dans certains cas ; arbitrage conservé. Aucun export altéré par substitution n’est diffusé dans l’attente |
| Conservation | Historique et publications conservés ; un éventuel archivage hors compte devra préserver leur accès et être défini explicitement |
| Responsabilités | Titulaire du compte, responsables, emplacement de la copie de sauvegarde et classification des données à renseigner |

Cette version décrit le pilote ; elle ne constate ni déploiement, ni réussite de test de charge, ni validation de mise en production institutionnelle.
