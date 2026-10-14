# Mise en œuvre des groupes et campagnes

Date : 13 octobre 2026.

Contrat fonctionnel : [Groupes de termes et campagnes de traduction](../fonctionnel/groupes-et-campagnes-traduction.md).

## Découpage réalisé

1. Stockage : groupe unique du terme ; campagnes, groupes sélectionnés, personnes assignées, participations actives ou retirées et événements historiques. Migration `2026_10_13_090000_create_translation_campaigns.php`.
2. Métier : `GroupService` et `CampaignService` centralisent les mutations transactionnelles, les droits, l’admission, la synchronisation et les transitions. Le circuit existant `WorkflowService` conserve la validation et le contrôle auteur–validateur.
3. Consultation et travail : menus Groupes de termes et Campagnes de traduction, catalogue filtrable, composition depuis la sélection du catalogue, Kanban, fiches, formulaires, historiques et archives. Les vues et messages sont localisés en allemand, français et italien.
4. Vérification : tests métier, intégration avec des CSV fictifs, tests HTTP des droits et de la visibilité, contrôles du dépôt et revue visuelle sur bureau et à 390 pixels.

## Choix de conception

- Titres obligatoires, sans unicité imposée, limités à 200 caractères ; descriptions et consignes à 10 000. Palette et couleur par défaut définies dans [DESIGN.md](../../DESIGN.md).
- La consultation suit le catalogue commun depuis le 13.10.2026 : groupes, campagnes, consignes et historiques sont visibles aux comptes autorisés, quelle que soit l’organisation de création. Les opérations de travail conservent les droits de langue, l’assignation et les contrôles d’activation. Les empreintes de pertinence ne dépendent plus d’un propriétaire de terme. Les participations historiques conservent l’accès à leur ancienne lignée ; aucun nouveau parcours de campagne n’est ajouté.
- La première admission est conservée dans `first_admitted_at` : retirer tous les termes ne déverrouille pas la langue ou l’objectif.
- La présence de la cellule cible et la valeur validée du premier attribut DEVCONF déterminent l’admission. Les exclusions sont conservées dans le dernier bilan, distinct des compteurs de participation.
- La synchronisation reste active pour À faire et En cours. Les changements d’appartenance et de source utilisent une synchronisation du terme concerné ; la composition et le réexamen actualisent le périmètre sélectionné. Les campagnes terminées sont figées.
- Retirer un ajout direct laisse le terme actif si un groupe sélectionné le fournit encore. Aucune exclusion individuelle permanente d’un terme provenant d’un groupe n’est proposée.
- Les propositions nouvelles conservent une empreinte de la référence principale, de la cellule cible, de l’identité et du contexte métier source. Les contrôles existants de cellule, de variables, d’encodage, d’intégrité et de collision restent applicables. Une ancienne proposition sans contexte démontrable n’est pas rattachée automatiquement ; une correction autorisée peut actualiser son contexte.
- Une proposition suivie devenue non pertinente après un changement de source est détachée ; le traitement revient À traiter. La proposition et les événements précédents sont conservés. Les décisions ultérieures de propositions historiques restent consultables sans simuler une transition active.
- Confirmer sans changement enregistre auteur, date, révision et empreinte, puis arrête seulement le suivi actif dans cette campagne. La validation d’une proposition suivie produit un événement de confirmation distinct.
- L’envoi d’une nouvelle proposition est refusé si une proposition pertinente attend déjà une décision. Le participant peut rattacher une proposition unique ; les choix concurrents passent par l’arbitrage autorisé.
- Une campagne commencée conserve au moins une personne assignée. Le retrait de la dernière personne est refusé atomiquement. Les droits de chaque personne sont à nouveau contrôlés au moment du travail.
- Le désarchivage restitue la campagne terminée sans la rouvrir. Un réexamen motivé autorisé la désarchive et la rouvre ; le terme demandé est conservé comme ajout direct s’il a quitté les groupes sélectionnés.
- Le démarrage se fait par bouton accessible. La terminaison repose sur les statuts réels ; aucune commande de déplacement ne permet de la forcer. Les pourcentages sont calculés sur les seules participations actives et plafonnés à 99,9 % lorsque le ratio est inférieur à 100 %.

## Contrôles et limites

Les tests spécifiques sont dans `TranslationCampaignTest`, `CampaignImportIntegrationTest` et `CampaignAccessTest`. Ils couvrent l’admission et ses exclusions, le verrouillage des paramètres, les rattachements et décisions multi-campagnes, les confirmations, les arbitrages, les retraits et retours, le gel et le réexamen, les archives, les compteurs et les refus de droits sans effet métier.

Résultats au 13 octobre 2026 : 382 tests PHP réussis, 13 ignorés selon les exigences MariaDB ou Unix ; 28 tests JavaScript réussis. Le format PHP et `git diff --check` sont conformes. Les cinq nouveaux écrans ont été rendus avec des données fictives en DE/FR/IT ; les 60 combinaisons bureau/mobile et compact/confortable ne présentent pas de débordement horizontal de page. Les contrôles ont également corrigé et testé la conservation des déclencheurs de recherche et d’état lors de la migration SQLite et de son retour arrière.

Commandes de vérification :

```text
php artisan test
node --test tests/JavaScript/*.test.cjs
php scripts/test_devconf_roundtrip.php --validate-semantics
```

Sous Windows, PHPUnit peut nécessiter une exécution hors du bac à sable lorsque `is_readable` refuse des fichiers pourtant accessibles. Les tests utilisent SQLite ; les contrôles de concurrence spécifiques à MariaDB et de permissions Unix restent conditionnés à ces environnements.

La vérification complète DEVCONF sur les originaux nécessite les sept CSV privés dans `data/imports`. Lors de cette mise en œuvre, ce répertoire contient seulement `.gitkeep` : la commande a été exécutée, mais ce contrôle ne peut pas réussir sans les originaux. Les tests synthétiques DEVCONF et les imports fictifs restent exécutables. Aucun original n’a été ajouté, déplacé ou modifié.
