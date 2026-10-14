<?php

return [
    'title' => 'Utiliser le référentiel myABI',
    'description' => 'Retrouver un terme, proposer une traduction et suivre son parcours jusqu’à la publication.',
    'start' => 'Un référentiel commun',
    'common' => 'Les termes et les traductions validées sont communs aux organisations. L’organisation permet d’identifier les personnes, l’origine des propositions et les destinataires des publications ; elle ne crée pas une variante de traduction.',
    'permissions' => 'Les actions dépendent de vos rôles et des langues autorisées : le Lecteur consulte, le Traducteur propose, le Validateur décide et le Gestionnaire organise les groupes, les campagnes, les imports et les publications. L’Administrateur gère les comptes et les organisations ; ce rôle seul ne donne pas de droits de traduction ou de validation.',
    'contents' => 'Sommaire de l’aide',
    'rules' => 'Règles à retenir',
    'sections' => [
        'catalogue' => [
            'title' => 'Rechercher et consulter',
            'action' => 'Ouvrir les traductions',
            'paragraphs' => [
                'Dans Traductions, choisissez la langue cible : DE, FR, IT ou EN. Elle est indépendante de la langue de l’interface, réglable dans le bandeau en DE, FR ou IT.',
                'Recherchez une clé, un texte de référence ou une traduction. Utilisez les filtres de type DEVCONF, de version, d’état, de groupe ou de campagne ; Sans groupe retrouve les termes non classés. Réinitialiser efface les filtres.',
                'Ouvrez la fiche depuis la clé ou la référence pour consulter les attributs, les propositions, l’historique et la présence dans les versions. La valeur en vigueur et la proposition sont affichées séparément.',
            ],
        ],
        'translate' => [
            'title' => 'Proposer une traduction',
            'action' => 'Accéder au catalogue',
            'paragraphs' => [
                'Saisissez votre texte dans la colonne Proposition ou dans la fiche du terme. Conservez exactement les variables de la référence, puis utilisez Proposer ou Ctrl + Entrée (⌘ + Entrée sur Mac). Pour envoyer plusieurs saisies du catalogue, cochez les lignes concernées puis utilisez Proposer dans la barre de sélection.',
                'Une saisie non envoyée n’est pas enregistrée comme proposition. Le navigateur avertit avant de quitter une page contenant des modifications ; envoyez vos saisies avant de changer de page ou de langue.',
                'Si une proposition est déjà en attente, ouvrez les détails pour l’examiner. Une confirmation de la valeur actuelle ne la modifie pas et ne remplace pas la validation d’une proposition.',
            ],
        ],
        'groups' => [
            'title' => 'Classer les termes en groupes',
            'action' => 'Ouvrir les groupes de termes',
            'paragraphs' => [
                'Un groupe réunit les termes d’un même sujet, avec un titre, une couleur et une description. Chaque terme appartient à zéro ou un groupe. Ce classement ne change ni son identité ni sa traduction.',
                'Le Gestionnaire crée les groupes et affecte les termes depuis leur fiche ou les cases de sélection du catalogue. Choisir Sans groupe retire l’affectation. Les groupes servent ensuite de filtres et de base aux campagnes.',
            ],
        ],
        'campaigns' => [
            'title' => 'Travailler dans une campagne',
            'action' => 'Ouvrir les campagnes de traduction',
            'paragraphs' => [
                'Le Gestionnaire prépare une campagne pour une langue cible, avec des consignes, des personnes assignées et une échéance. L’objectif Traduction sélectionne les termes sans traduction validée ; Révision sélectionne ceux ayant une traduction validée à examiner. Le périmètre combine des groupes synchronisés et des termes ajoutés directement.',
                'Après le démarrage, les participants assignés traitent les termes : À traiter, À arbitrer, Proposition envoyée ou Traduction confirmée. Proposez une correction, confirmez une traduction correcte sans changement ou demandez un arbitrage motivé. Le Validateur de la langue ou le Gestionnaire résout les arbitrages ; les propositions gardent le circuit de validation habituel.',
                'L’avancement du travail compte les propositions envoyées et les traductions confirmées. La part confirmée compte uniquement les confirmations : 100 % d’avancement ne signifie donc pas que toutes les traductions sont validées. Une campagne démarrée se termine lorsque tous ses termes sont confirmés.',
                'Les groupes restent synchronisés tant que la campagne est À faire ou En cours. Une campagne terminée conserve son périmètre et son résultat ; un réexamen explicite par un Validateur de la langue ou un Gestionnaire la rouvre.',
            ],
        ],
        'validate' => [
            'title' => 'Examiner et décider',
            'action' => 'Ouvrir la validation',
            'paragraphs' => [
                'Dans Validation, comparez la référence, la valeur en vigueur et chaque proposition. Valider met à jour la traduction et crée une révision. Rejeter exige un motif et laisse la valeur en vigueur inchangée. Les anomalies bloquantes doivent être corrigées avant validation.',
                'La sélection par cases permet de décider sur les lignes choisies, jusqu’à 500 propositions par envoi. La validation de toutes les propositions filtrées porte aussi sur les résultats non affichés : vérifiez les filtres et le nombre annoncé, puis consultez le rapport. Les propositions non conformes ou concurrentes pour une même cellule sont exclues.',
                'Vous ne validez pas votre propre proposition. Seul le Gestionnaire peut demander une dérogation explicite, enregistrée dans le journal.',
            ],
        ],
        'import' => [
            'title' => 'Importer un fichier DEVCONF',
            'action' => 'Ouvrir les imports',
            'paragraphs' => [
                'Le Gestionnaire choisit le fichier CSV, son type, la version myABI et son origine. L’analyse à blanc produit un rapport sans modifier les termes. Examinez les erreurs et les avertissements avant d’appliquer l’import.',
                'L’application intègre les termes au référentiel commun et crée des propositions pour les valeurs traduites. Elle ne les valide pas automatiquement. La validation initiale en masse est une action distincte, soumise aux droits et aux contrôles de chaque proposition.',
                'Le traitement continue en arrière-plan ; la page affiche sa progression et son rapport. Les identités ambiguës restent bloquées et doivent être résolues avant de poursuivre.',
            ],
        ],
        'deliver' => [
            'title' => 'Exporter ou télécharger une publication',
            'action' => 'Ouvrir les publications',
            'paragraphs' => [
                'Exports permet au Gestionnaire de générer un fichier DEVCONF avec tous les termes connus du type choisi. Les traductions validées sont utilisées en priorité ; les cellules sans traduction validée peuvent reprendre une valeur importée de repli. Cet export ne crée pas de publication.',
                'Une publication est un catalogue figé créé par le Gestionnaire pour les destinataires autorisés. Elle inclut les termes ayant au moins une traduction validée et utilise uniquement les traductions validées ; les autres cellules cibles restent vides, sans reprise des propositions ou des valeurs importées.',
                'Dans Publications, ouvrez la publication pour consulter sa couverture et télécharger ses fichiers ou l’archive complète. Les validations ultérieures ne changent pas ces fichiers : il faut créer une nouvelle publication pour les intégrer.',
            ],
        ],
        'profile' => [
            'title' => 'Régler son profil et son affichage',
            'action' => 'Ouvrir mon profil',
            'paragraphs' => [
                'Dans Mon profil, consultez vos informations et réglez la langue de l’interface, les notifications quotidiennes et votre mot de passe. Configurez le second facteur lorsque votre rôle l’exige et conservez vos codes de récupération en lieu sûr.',
                'Le bouton Compact du bandeau adapte l’espacement des tableaux. Colonnes affichées permet de masquer certaines colonnes du catalogue. Ces réglages d’affichage ne modifient pas les données.',
                'Si MyAB Translation Inspector est activé et installé dans votre navigateur, créez un jeton personnel dans Mon profil et copiez-le immédiatement dans les paramètres de l’extension : il n’est affiché qu’une fois. Configurez l’adresse API et les domaines MyAB autorisés, activez le mode inspecteur puis utilisez Alt + clic sur un texte pour retrouver sa clé et ouvrir sa traduction. Vous pouvez révoquer le jeton dans votre profil.',
            ],
        ],
    ],
];
