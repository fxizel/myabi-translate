# Habillage myABI — 22 septembre 2026

La présentation de l’application Blade reprend la maquette myABI : bandeau bleu nuit, onglets de travail, navigation latérale blanche, surfaces gris clair, commandes compactes et tableaux à filets fins. Le même socle couvre le catalogue, les fiches, la validation, les imports, les publications, le profil, l’administration, le journal d’audit et l’authentification.

Le réglage Compact modifie l’espacement des lignes sans recharger la page ni remplacer les champs de saisie. Seule cette préférence est enregistrée dans le navigateur. Les valeurs métier et les contrôles de soumission conservent leur fonctionnement existant.

## Vérifications

- `php artisan test` : 160 tests réussis, 1 913 assertions ; 4 tests de permissions Unix ignorés sur Windows.
- `node --test tests/JavaScript/drafts.test.cjs` : 13 tests réussis.
- `php scripts/test_devconf_roundtrip.php --validate-semantics` : réussi. Aucun original modifié.
- `php vendor/bin/pint --test --dirty` : réussi.
- Tests de vues ajoutés : navigation selon les droits, unicité de l’onglet actif, échappement de la source ou de la clé affichée dans l’onglet et conservation de la langue au retour.
- Après les dernières corrections, `php artisan test --filter=ViewSmokeTest` : 13 tests réussis, 254 assertions. Le fichier, la langue, le lot et la version de publication restent visibles dans les en-têtes concernés.

La recette visuelle utilise les vraies vues rendues par Laravel avec SQLite en mémoire et des données fictives, dans un espace local isolé. Les formulaires de cet aperçu ne déclenchent aucune opération métier ; les contrats serveur sont couverts par les tests PHP.

Contrôles navigateur : catalogue et fiche sur ordinateur et à 390 px, validation, imports, publications, connexion, navigation lecteur/gestionnaire, libellés FR/DE/IT et changement de densité sans perte d’une saisie. Les tableaux restent dans leur propre zone de défilement sur mobile ; les autres pages ne débordent pas horizontalement. Aucune erreur JavaScript relevée.

Les ressources sont locales : CSS et JavaScript statiques, icônes SVG incluses dans les vues. Aucun service ou outil supplémentaire n’est requis en production. Cette recette porte sur le code local ; aucun déploiement distant n’est inclus.
