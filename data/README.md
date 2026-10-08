# Fichiers de données myABI

```text
data/
├── imports/   # Fichiers CSV à importer dans le Référentiel
└── exports/   # Fichiers CSV générés pour être réimportés dans myABI
```

`imports/` reçoit, **uniquement en local**, les 7 fichiers DEVCONF réels fournis par LogObject. Ils contiennent des données métier de l'éditeur, des identifiants d'utilisateurs et des adresses de contact : ils sont exclus de Git (`.gitignore`) et ne doivent jamais être publiés ni committés. Les tests du dépôt utilisent des fixtures synthétiques. Conserver les originaux sans les modifier ; écrire les résultats dans `exports/`.

`exports/` est réservé aux fichiers produits par la solution. Son contenu généré est ignoré par Git.

Les formats attendus (encodage Windows-1252, séparateur point-virgule, séparateurs d’enregistrements CRLF et colonnes propres à chaque type) sont décrits dans l’[annexe des formats DEVCONF](../docs/formats-devconf.md). L’architecture du pilote Laravel/Blade/MariaDB sur alwaysdata gratuit figure dans les [spécifications techniques](../specifications-techniques-referentiel-traductions-arge-abi.md), avec une [liste d’exigences](../docs/exigences-techniques.md). La faisabilité doit être vérifiée sur les sept fichiers complets.

Un audit en lecture seule est disponible avec `python scripts/audit_devconf.py`, depuis la racine du dépôt (Python 3.11 ou supérieur, sans dépendance externe). Il vérifie les volumes, les doublons selon la casse et la fidélité d’une réécriture CSV standard, sans modifier les originaux ni créer d’exports.
