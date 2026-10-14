# Documentation myABI Translate

La documentation est organisée par usage : fonctionnement du produit, réalisation technique, déploiement et identité visuelle.

```text
docs/
├── README.md
├── fonctionnel/
│   ├── PRD.md
│   ├── personas.md
│   ├── cas-utilisation.md
│   ├── exigences-fonctionnelles.md
│   ├── parcours-ui-ux.md
│   └── groupes-et-campagnes-traduction.md
├── technique/
│   ├── formats-devconf.md
│   ├── exigences-techniques.md
│   ├── recette.md
│   ├── recette-design-myabi.md
│   └── groupes-campagnes-implementation.md
├── deploiement/
│   ├── deploiement-vps.md
│   ├── operations.md
│   ├── security-deployment.md
│   └── recette-operations.md
└── branding/
    ├── README.md
    ├── logo-v2.md
    ├── myabi-translate-logo-v2.png
    └── myabi-translate-logo-v2-transparent.png
```

Le [PRD fonctionnel](fonctionnel/PRD.md) présente le produit existant et donne accès aux personas, cas d’utilisation, exigences et parcours. Le [guide de branding](branding/README.md) regroupe les sources du logo et les variantes visuelles.

La [spécification des groupes de termes et campagnes de traduction](fonctionnel/groupes-et-campagnes-traduction.md) définit le contrat de cette fonctionnalité. Son découpage, les choix retenus et les vérifications sont décrits dans la [mise en œuvre technique](technique/groupes-campagnes-implementation.md).

## Technique

| Document | Contenu |
| --- | --- |
| [Formats DEVCONF](technique/formats-devconf.md) | Contrat des sept formats, identités et ambiguïtés à préserver. |
| [Exigences techniques](technique/exigences-techniques.md) | Décisions de réalisation et critères de qualité du pilote. |
| [Recette du lot 1](technique/recette.md) | Preuves métier, performances, capacité et contrôles restant à effectuer. |
| [Recette visuelle myABI](technique/recette-design-myabi.md) | Vérifications de l’habillage de l’interface. |
| [Groupes et campagnes](technique/groupes-campagnes-implementation.md) | Découpage réalisé, transitions, droits, choix de conception et contrôles. |
| [Référentiel commun](technique/transition-termes-communs.md) | Lots réalisés, diagnostic privé, reprise des historiques et procédure de bascule. |

## Déploiement et exploitation

| Document | Contenu |
| --- | --- |
| [Guide VPS](deploiement/deploiement-vps.md) | Inventaire préalable, installation Docker Compose et alternative native. |
| [Opérations](deploiement/operations.md) | Livraison, sauvegarde, restauration et retour arrière. |
| [Sécurité du déploiement](deploiement/security-deployment.md) | Comptes SQL, sessions, MFA et journal d’audit. |
| [Recette des opérations](deploiement/recette-operations.md) | Preuves locales de sécurité et d’exploitation, conditions avant activation. |

## Références à la racine

Les [spécifications fonctionnelles historiques](../specifications-fonctionnelles-referentiel-traductions-arge-abi.md) et les [spécifications techniques historiques](../specifications-techniques-referentiel-traductions-arge-abi.md) définissent le contrat du projet avec l’annexe DEVCONF. [DESIGN.md](../DESIGN.md) reste la référence visuelle. Le [README du dépôt](../README.md) décrit l’installation locale, les commandes et les vérifications.
