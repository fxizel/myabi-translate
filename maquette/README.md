# Maquette du référentiel des traductions myABI

La présentation **myABI du 22.09.2026**, réalisée à partir des captures fournies, remplace la présentation Swiss. Son application aux écrans Laravel a ensuite été demandée : bandeau, onglets, navigation latérale, composants et densité reprennent désormais cette référence. La variante Swiss avait été approuvée le **20.09.2026** ; cette évolution visuelle ne vaut pas validation des spécifications fonctionnelles ou techniques.

Pour la consulter, ouvrir [index.html](index.html) dans un navigateur : ce fichier redirige vers [maquette-myabi.html](maquette-myabi.html), qui contient la structure et les interactions. Les styles sont dans [maquette-myabi.css](maquette-myabi.css). La maquette fonctionne sans dépendances distantes ; les icônes sont intégrées en SVG.

La présentation reprend le bandeau bleu marine `#102348`, les onglets de travail, les panneaux blancs sur fond gris clair et la grille compacte des captures myABI. La navigation latérale conserve les quatre sections : Traductions, Validation, Imports et Publications. Le bouton « Compact » permet de modifier l’espacement des lignes. À petite largeur, la navigation passe au-dessus du contenu, les panneaux s’empilent et le tableau propose un défilement horizontal.

Les interactions couvrent la recherche et les filtres, les langues et la pagination, la fiche terme et son historique, la saisie et la soumission avec contrôle des variables, la validation ou le rejet motivé, ainsi que les parcours d’import et d’export.

Une proposition reste distincte de la valeur en vigueur jusqu’à sa validation. La validation crée une nouvelle révision du terme, commune à ses langues. L’historique conserve les valeurs précédentes et les catalogues déjà publiés restent figés.

Cette démonstration utilise des données fictives, sans backend. Les imports et les exports sont simulés ; aucun fichier myABI réel n’est traité ou généré.

Vérification dans le navigateur le 22.09.2026 : recherche, filtres, pagination, changement de langue et de densité, fiche et contrôle des variables, validation, rejet motivé, import et export simulés. Le catalogue et la fiche ont été contrôlés sur écran de bureau et à 390 px ; le tableau conserve son propre défilement horizontal sur mobile.
