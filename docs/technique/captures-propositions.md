# Captures des propositions

Une proposition possède au plus une capture facultative, pour son terme, attribut et langue. La capture fournit un contexte de lecture et ne valide aucune traduction. Elle est choisie ou collée volontairement ; aucune capture automatique ni retouche n’est effectuée.

## Contrat et accès

Les créations et corrections acceptent `screenshot_action=keep|replace|remove` (défaut `keep`) et un fichier `screenshot` uniquement avec `replace`. Les requêtes sont multipart ; l’édition utilise POST avec `_method=PATCH`. Sans JavaScript, choisir explicitement l’action Ajouter ou remplacer avant de sélectionner le fichier. Les anciens clients sans ces champs continuent à fonctionner.

`keep` conserve l’image lors d’une correction en attente. Lors d’une correction de rejet avec `supersedes_id`, il copie les fichiers de la proposition rejetée, après vérification du triplet terme/attribut/langue et du statut. `remove` permet de ne pas reprendre cette image. Chaque proposition possède ses fichiers indépendants.

Les routes `proposals.screenshot` et `proposals.screenshot.thumbnail` exigent la session, les protections MFA configurées et les mêmes droits de consultation que la fiche du terme. Le paramètre `version` doit correspondre à `lock_version` ; une version périmée renvoie 409. Une absence de capture ou de fichier renvoie 404. Les réponses sont privées, non mises en cache, avec type contrôlé et `nosniff`. Aucun chemin privé ne figure dans les réponses JSON.

## Validation et stockage

- PNG/JPEG uniquement ; **5 Mio = 5 242 880 octets**, fichier non vide.
- Chaque côté est limité à 8 192 pixels ; le produit largeur × hauteur à 12 000 000 pixels, contrôlés avant décodage.
- Fileinfo, en-tête et décodage complet GD doivent être cohérents. Nom et MIME client ne font pas autorité. Les erreurs du décodeur sont rejetées sans publier leur contenu.
- L’original est conservé sans réencodage ; sa taille, ses dimensions, son MIME et son SHA-256 accompagnent le chemin relatif en base. Le nom utilisateur n’est pas conservé. Les six colonnes sont facultatives pour les propositions antérieures.
- La miniature PNG conserve les proportions, sans agrandissement ni recadrage, dans 320 × 240 pixels et 512 Kio. La transparence est conservée et l’orientation EXIF des JPEG est appliquée à la miniature.

Le disque local privé contient `proposal-screenshots/<identifiant aléatoire>/image.png` ou `image.jpg`, et `thumbnail.png`. Les permissions suivent `PrivateStoragePermissions`, y compris le groupe partagé explicitement configuré. Aucun lien public de stockage n’est créé. Les images restent exclues de Git, des livraisons, des DEVCONF et des publications.

## Transactions et concurrence

La préparation de l’image précède son installation. Les opérations modifiant les fichiers prennent le verrou d’exploitation exclusif, une seule fois au niveau extérieur. Le quota existant inclut l’ancien fichier, le nouveau fichier, sa miniature et une marge de 1 Mio. Une écriture partielle ou un échec de permissions est un échec de l’enregistrement.

Les nouveaux fichiers sont supprimés au rollback, y compris au rollback d’une transaction extérieure de campagne ou lors d’un réessai. Un remplacement ou retrait ne supprime l’ancien dossier qu’après commit extérieur. Le nettoyage vérifie à nouveau l’absence de référence ; un échec laisse un orphelin sans présenter un enregistrement réussi comme un échec.

Chaque correction incrémente `lock_version` et la génération du catalogue, même si seul le contexte visuel change. Les décisions individuelles, sélections groupées et confirmations filtrées périmées sont refusées. Les droits d’auteur, la reprise d’un import et les quatre yeux restent applicables. L’audit conserve les métadonnées de modification, sans contenu d’image ni chemin privé dans les journaux techniques.

Après validation, rejet ou rejet pour proposition supplantée, la capture reste figée. Le retrait d’une capture d’une nouvelle proposition ne touche pas au rejet d’origine. Le rollback de migration refuse de supprimer des références encore utilisées : établir la compatibilité ou restaurer une sauvegarde cohérente.

## Entretien et restauration

`php artisan proposals:screenshots:prune` contrôle les références et compte les dossiers orphelins sans supprimer. `--apply` supprime uniquement les dossiers reconnus, sans lien symbolique, sans référence et âgés de plus de 24 heures (dossier et fichiers). La commande prend le verrou exclusif et renvoie un échec si une capture référencée manque. Elle est ponctuelle ; aucune nouvelle tâche planifiée n’est installée.

Les sauvegardes existantes incluent déjà SQL, images et miniatures avec leurs empreintes. Attendre la fin des écritures sous verrou commun, conserver la maintenance pendant le dump et la copie du stockage privé, vérifier l’archive et restaurer ensemble base et fichiers dans des destinations vides. Ne jamais tester une restauration sur la base active. Vérifier après restauration les chemins référencés, empreintes, permissions et accès authentifiés des propositions en attente et décidées. `APP_KEY` et les accès restent conservés séparément.

## Prérequis et recette

PHP doit satisfaire la politique du dépôt. GD avec PNG/JPEG, EXIF et Fileinfo sont requis en CLI, FPM et tests ; `composer check-platform-reqs --no-dev` contrôle les extensions et un essai PNG/JPEG vérifie les codecs. Docker les construit dans l’image ; en natif installer les extensions de la branche PHP retenue puis redémarrer uniquement le FPM dédié. Aucun Node, Redis ou service d’images n’est requis en production.

Exécuter les trois contrôles d’AGENTS.md, les tests `ProposalScreenshotTest`, les tests JavaScript et les tests d’exploitation. Pour la concurrence réelle, le lanceur existant accepte `php tests/Support/run-browser-extension-concurrency.php proposal-screenshots` avec `MYABI_ISOLATED_TESTS=1`, une connexion à une MariaDB portable dédiée et les contrôles de datadir du lanceur. Le lanceur crée puis supprime une base jetable ; il ne doit jamais viser une base applicative.

La recette navigateur couvre fichier, collage dans la zone dédiée, aperçu, retrait, remplacement, conservation de la saisie après erreur, avertissement de départ, absence de double envoi, consultation clavier et Échap, lignes de validation chargées progressivement, DE/FR/IT et 390 px. Mesurer le décodage et les miniatures avec `memory_limit=256M`. Les images et comptes de recette sont synthétiques.
