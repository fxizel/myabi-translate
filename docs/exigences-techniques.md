# Exigences techniques du pilote ARGE-ABI

Version 1.0 du 21.09.2026, associée aux [spécifications techniques v3.0](../specifications-techniques-referentiel-traductions-arge-abi.md).

Cette liste sert de référence de réalisation et de recette. **D** identifie une décision issue des échanges ; **T** identifie sa traduction technique ou une contrainte métier conservée. Les choix sont documentés, mais aucun critère d’exécution n’est déclaré vérifié : l’application reste à réaliser.

## 1. Décisions issues des échanges

| ID | Exigence | Vérification attendue |
| --- | --- | --- |
| D-01 | Réaliser un pilote interne pour l’organisation, avec des collègues, sans but lucratif. | Cadre du pilote indiqué dans sa documentation. |
| D-02 | Maintenir un coût d’hébergement de 0 €, sans passage payant implicite. | Offre gratuite, dépenses GitHub bloquées, aucun service indispensable facturé. |
| D-03 | Utiliser alwaysdata Free comme cible des essais ; réévaluer si la capacité est insuffisante. | Test réel sur Free ; fournisseur non présenté comme déjà validé en charge. |
| D-04 | Employer un hébergement géré, sans administration de serveur par l’équipe. | Aucun besoin de droits root, de maintenance du système ou d’installation de MariaDB. |
| D-05 | Accepter un hébergement européen pour le pilote, avec une préférence suisse. | Localisation française de la cible documentée. |
| D-06 | Donner accès par Internet, avec HTTPS et comptes nominatifs, sans VPN. | Connexion externe ; refus d’accès aux données sans authentification. |
| D-07 | Développer en PHP et MySQL/MariaDB ; retenir Laravel, Blade et MariaDB. | Une application PHP et une base MariaDB ; versions documentées. |
| D-08 | Gérer plusieurs organisations et 1 à 5 rédacteurs ou validateurs au départ. | Plusieurs organisations et scénario avec cinq utilisateurs actifs ; lecteurs hors de ce chiffre. |
| D-09 | Prendre en charge les sept fichiers et le catalogue complet dès le pilote utilisable. | 581 142 enregistrements ; aucun échantillonnage présenté comme validation du volume. |
| D-10 | Permettre des imports hebdomadaires, jusqu’à 30 minutes, avec consultation et progression. | Chronométrage du §7 technique, analyse et application comprises ; réimport complet. |
| D-11 | Déployer automatiquement depuis GitHub lors de la publication d’une version validée. | Déclenchement par publication ; aucun transfert manuel du code requis. |

## 2. Exigences de réalisation et de qualité

| ID | Exigence | Vérification attendue |
| --- | --- | --- |
| T-01 | Livrer un seul projet Laravel/Blade, sans service supplémentaire à exploiter. | Code, migrations, ressources et tests communs ; aucun Docker, Redis ou Node.js requis chez l’hébergeur. |
| T-02 | Verrouiller et maintenir les dépendances ; aligner PHP web, CLI et tests. | Versions, extensions et fichiers de verrouillage vérifiés avant livraison. |
| T-03 | Respecter 1 Go de stockage global, 256 Mo de RAM et ¼ CPU sur Free. | Mesures incluant index, révisions, fichiers, temporaires, publications et déploiement. |
| T-04 | Avertir à 80 % du stockage et refuser une opération sans marge suffisante. | Alerte et refus avant mutation ; aucune purge automatique de données métier. |
| T-05 | Lire les CSV en flux et traiter les données par lots bornés. | Transfert du fichier de 72 Mo, mémoire mesurée, aucun chargement intégral du catalogue en PHP. |
| T-06 | Utiliser une tâche planifiée et un seul traitement lourd actif. | Import poursuivi après fermeture du navigateur ; verrou testé ; aucun processus permanent. |
| T-07 | Rendre l’analyse reprenable et l’application d’un fichier transactionnelle. | Interruptions : reprise d’analyse, annulation d’application incomplète et relance sans doublon. |
| T-08 | Maintenir la consultation et une progression fiable pendant l’import. | Navigation, compteurs, erreurs et dernière activité accessibles ; suspension des écritures annoncée. |
| T-09 | Conserver chaque original, son empreinte, sa provenance et son format. | Original récupérable et SHA-256 identique après import, livraison et restauration. |
| T-10 | Préserver les formats DEVCONF et les cellules non modifiées. | Comparaison binaire sur les sept fichiers ; seules les cellules autorisées changent après validation. |
| T-11 | Conserver les collisions et comparer les clés exactement. | Cas de casse, espaces finaux, clés composites et collision Incident code ; aucune fusion automatique. |
| T-12 | Séparer proposition et valeur validée ; conserver les contrôles métier. | Import sans validation implicite, quatre yeux, rejet motivé, placeholders et concurrence contrôlés. |
| T-13 | Préserver les révisions complètes, leur comparaison et l’historique. | Anciennes valeurs consultables ; réimport inchangé sans révision inutile ; aucune suppression liée au quota. |
| T-14 | Produire des publications immuables et traçables par destinataire. | Manifeste, sources, révisions et empreintes ; mêmes octets téléchargés après évolution du catalogue. |
| T-15 | Bloquer un export ambigu ou sans source technique complète. | Diagnostic visible ; aucune valeur technique inventée ni diffusion interorganisation non autorisée. |
| T-16 | Filtrer, rechercher et paginer dans MariaDB, au plus 100 lignes par page. | Objectif inférieur à deux secondes sur le catalogue complet ; accents et casse testés. |
| T-17 | Contrôler les droits côté serveur, par rôle, langue et organisation. | Tests d’accès direct aux pages, actions, fichiers et rapports hors périmètre. |
| T-18 | Conserver l’authentification locale et les règles de session fonctionnelles ; piloter le MFA globalement par `MFA_ENABLED=false` par défaut. | Absence de défi et d’obligation MFA avec `false`, règles TOTP par rôle avec `true`, conservation des secrets et reprise des sessions à la réactivation, invitations, récupération de compte, expiration et révocation testées. |
| T-19 | Protéger les secrets et fichiers ; servir uniquement le dossier public Laravel. | HTTPS, CSRF, échappement HTML, SQL paramétré, fichiers privés inaccessibles par URL. |
| T-20 | Conserver un journal d’audit en ajout seul et ses auteurs. | Compte applicatif incapable de modifier/supprimer l’audit ; accès de migration distinct ; dates UTC. |
| T-21 | Préparer les livraisons hors du compte Free et sécuriser leur transfert. | Paquet de production construit dans GitHub Actions, clé dédiée, empreinte SSH vérifiée, données exclues. |
| T-22 | Éviter les chevauchements de livraison et de traitement ; documenter le retour. | Tests, verrou, contrôle après migration et retour compatible avec le schéma. |
| T-23 | Tester une restauration cohérente de la base et des fichiers. | Restauration documentée ; copie indépendante avant migration sur stockage existant désigné. |
| T-24 | Conserver la maquette et les fonctions métier non arbitrées. | Quatre espaces, interface DE/FR/IT, recherche, révisions et notifications conformes au lot prévu. |
| T-25 | Garder une exploitation simple et une possibilité de migration. | Responsable et suppléant, procédure courte ; code PHP, sauvegarde MariaDB et fichiers récupérables. |

## 3. Conditions pour conclure les essais

Le rapport de recette indique pour chaque exigence : **vérifiée**, **non satisfaite** ou **à vérifier**, avec preuve et date. Il comprend durées, pics de ressources et estimation de croissance après deux imports complets et les publications du pilote.

Un dépassement des quotas ou du temps d’import est un résultat à traiter, pas une autorisation de payer, de supprimer l’historique ou de retirer des fichiers. Les ambiguïtés du §9 technique restent visibles dans la recette. La restitution brute peut être testée avant leur résolution ; la diffusion concernée reste conditionnée à leur traitement.
