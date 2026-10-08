# Contrats des fichiers DEVCONF

Annexe aux [spécifications techniques simplifiées](../specifications-techniques-referentiel-traductions-arge-abi.md), version du 21.09.2026. Les formats observés restent des contraintes de compatibilité avec myABI, indépendantes de la technologie retenue.

Les fichiers originaux sont conservés localement dans `data/imports/` (hors du dépôt public, voir [data/README.md](../data/README.md)) et ne doivent pas être modifiés. Les clés ci-dessous sont candidates tant que LogObject n’a pas confirmé leur sémantique. Les décisions provisoires de traitement des ambiguïtés figurent à la fin de cette annexe.

## Formats de fichier DEVCONF

Les 7 fichiers fournis révèlent 5 mises en page de colonnes différentes (les trois types NLS partagent la même), qui se ramènent à trois schémas de langues, de 1 à 12 attributs traduisibles par terme et des clés d'identité de 1 à 4 colonnes. L'application prend en charge ces 7 types au moyen de formats prédéfinis, maintenus et testés avec son code.

### Structure de chaque type

Analyse des fichiers DEVCONF du 17.09.2026.

| Type | Fichier | Clé d'identité | Attributs traduisibles | Disposition des langues | Attributs techniques conservés |
| --- | --- | --- | --- | --- | --- |
| mRic-js | 01-DEVCONF-NLS-MRIC | Name | 1 (libellé) | Colonnes fixes Default, Caption, de_CH, it_CH, fr_CH, en_US, sa_IN | Description, Create Time, Create User, Update Time, Update User, Application |
| MonoMOT | 02-DEVCONF-NLS-MONOMOT | Name | 1 (libellé) | Idem mRic-js | Idem mRic-js |
| Server | 03-DEVCONF-NLS-SERVER | Name | 1 (message, souvent avec placeholders `{}` et `{field}`) | Idem mRic-js | Idem mRic-js |
| Form | 04-DEVCONF-NLS-FORM | Form Type + Form Template Name + Control Name + Column Name (label, help_text, watermark, default_value, title) | 1 (valeur de la colonne) | Default Language + Default Label, puis 4 paires Language n / Translation n (it_CH, fr_CH, en_US, sa_IN) | Create Dt, Create User, Update Dt, Update User, Application, Active |
| Incident Workflow | 05-DEVCONF-NLS-INCIDENTWORKFLOW | Workflow + Control type (Workflow, Node, Link) + Control Name + Column (name, description, label) | 1 (valeur de la colonne) | Idem Form | Update Dt, Update User, Application |
| Law Catalog | 06-DEVCONF-NLS-LAWCATALOG | ID# | 3 : Cause Text, Short Justification, Legal Remedies | Par attribut : Default, de_CH, it_CH, fr_CH, en_US | Name, BU External ID, Cause Id, PHV, UpdateUser, UpdateDt |
| Incident code | 07-DEVCONF-INCIDENTCODE | GROUPTYPE + CODEVALUE + MASTERTYPE + MASTERVALUE | 12 : TEXT, SHORTTEXT, ALTERNATIVE_TEXT_1, ALTERNATIVE_TEXT_2, ADDINFO, ADDINFO1 à ADDINFO6, USAGE_POLICY | Suffixe par attribut : _DE, _EN, _FR, _IT | ACTIVE, BUEXTERNALID, ID, SHOWONMOT, SHOWSEQUENCE, SOURCECODE, VALIDFROMDT, VALIDTODT, CODE_ICON_NAME, CODE_FILL_COLOR, CODE_BORDER_COLOR, ATTRIBUTES (JSON), CREATETIME, CREATEUSER, UPDATE_DT, UPDATE_USER |

### Formats de fichier communs

- Encodage Windows-1252 (ANSI) sans BOM pour les 7 fichiers ; séparateur point-virgule ; séparateurs d’enregistrements CRLF ; valeurs multilignes autorisées entre guillemets. Les retours à la ligne internes, y compris les LF isolés du fichier Form, sont conservés.
- Fichiers 01 à 06 : toutes les valeurs entre guillemets. Fichier 07 : mélange de valeurs entourées ou non de guillemets, y compris des guillemets facultatifs à préserver.
- Des points-virgules figurent à l’intérieur de valeurs : un analyseur CSV prenant en charge le séparateur point-virgule, les guillemets échappés et les champs multilignes est obligatoire. Un découpage par ligne physique ou par point-virgule ne convient pas.
- Dates : « dd.MM.yyyy HH:mm » (NLS), « dd.MM.yyyy HH:mm:ss » (Form, Workflow, Law Catalog), « yyyy-MM-dd HH:mm:ss.fff » (Incident code).
- Codes de langue : de_CH, it_CH, fr_CH, en_US dans six types, plus sa_IN dans cinq d'entre eux (absente de Law Catalog) ; DE, EN, FR, IT dans Incident code. Le Référentiel utilise les codes internes de, fr, it, en et une table de correspondance par type.

## Formats prédéfinis

Les développeurs maintiennent les 7 formats avec l'application. Chaque évolution est versionnée, testée sur des fichiers d'exemple et livrée avec l'application. L'ajout d'un type ou la modification d'un format passe par une évolution de l'application ; aucun éditeur de formats n'est prévu dans l'interface du Gestionnaire.

Pour chaque type, la définition du format précise :

1. Le code, le libellé et la visibilité dans la gestion des traductions myABI.
2. L'encodage, le séparateur, les guillemets, les fins de ligne, l'en-tête et l'ordre des colonnes.
3. Les colonnes de la clé d’identité, leur sensibilité à la casse et les composantes vides admises (notamment Control Name dans Form et les champs de parent dans Incident code). Une composante vide ne signifie pas automatiquement une clé invalide.
4. Les attributs traduisibles et leurs colonnes par langue. Les trois dispositions existantes sont prises en charge : colonnes fixes (mRic-js), paires « colonne langue / colonne valeur » (Form), colonne par attribut et par langue (Law Catalog, Incident code).
5. La valeur de référence et ses replis : de_CH sinon Default, puis Caption (mRic-js, MonoMOT, Server) ; Default Label, dont la langue est donnée par Default Language (Form, Incident Workflow) ; « attribut de_CH » sinon « attribut Default » (Law Catalog) ; « attribut_DE » (Incident code).
6. Les colonnes techniques à conserver et à réexporter telles qu'importées, y compris Update User et Update Dt.
7. Les préfixes connus de Form Template Name ou de Workflow qui proposent une portée cantonale, modifiable terme par terme. Les marqueurs PHV, WIP, TEST et OLD restent non arbitrés (question 8 du §15 fonctionnel) : en tête du nom, suivis d’un séparateur ou seuls, ils imposent une confirmation de portée par le Gestionnaire, quelle que soit leur casse. Aucune organisation homonyme n’est attribuée automatiquement ; une décision explicite déjà enregistrée reste conservée.
8. La règle d’identité et de collision retenue après clarification avec LogObject. Dans la proposition actuelle, les clés restent exactes ; une collision est conservée et signalée, sans choix automatique de première ou dernière ligne.
9. Les contrôles applicables : longueur maximale si connue, placeholders à préserver et avertissement si la traduction est identique à la source.

Chaque lot conserve la version du format utilisée. Une publication conserve ses fichiers définitifs et la version de l’application qui les a produits ; un téléchargement sert ces fichiers sans exécuter un ancien moteur d’export.

## Audit reproductible du 21.09.2026

Exécuter `python scripts/audit_devconf.py` depuis la racine du dépôt, avec Python 3.11 ou supérieur et sa bibliothèque standard. Le [script d’audit](../scripts/audit_devconf.py) lit uniquement les sept originaux, produit des statistiques JSON sans afficher de valeurs métier et ne génère aucun fichier d’export. Il ne constitue pas l’importeur de la future application.

| Fichier | Octets | Enregistrements hors en-tête | Colonnes | Doublons exacts | Doublons sans casse |
| --- | ---: | ---: | ---: | ---: | ---: |
| 01 – mRic-js | 9 624 326 | 38 562 | 14 | 0 | 99 |
| 02 – MonoMOT | 738 694 | 3 867 | 14 | 0 | 6 |
| 03 – Server | 275 378 | 941 | 14 | 0 | 2 |
| 04 – Form | 21 293 638 | 98 974 | 20 | 0 | 14 |
| 05 – Incident Workflow | 4 278 551 | 22 830 | 17 | 0 | 44 |
| 06 – Law Catalog | 247 603 | 147 | 22 | 0 | 0 |
| 07 – Incident code | 71 838 828 | 415 821 | 68 | 1 | 15 |
| **Total** | **108 297 018** | **581 142** | | **1** | **180** |

Un doublon désigne une occurrence après la première d’une même clé composite. « Sans casse » utilise `str.casefold()` comme diagnostic, sans supposer que myABI applique cette règle. Le nombre de colonnes est constant dans chaque fichier. La sensibilité des clés doit être confirmée par LogObject avant utilisation des exports dans myABI.

Avec une lecture stricte Windows-1252 et une réécriture Python CSV (`QUOTE_ALL` pour 01–06, `QUOTE_MINIMAL` pour 07, séparateur `;`, CRLF), les fichiers 01–06 sont identiques octet pour octet. Le fichier 07 passe de 71 838 828 à 70 299 490 octets : le lecteur conserve les valeurs mais l’écrivain retire les guillemets facultatifs. Cela invalide l’ancienne description « sans guillemets sauf nécessité » et impose de préserver la représentation brute des cellules inchangées.

L’importeur doit donc garder l’original et la position de ses enregistrements ; un export sans modification le restitue exactement. Un export modifié préserve les cellules non touchées, y compris leurs guillemets, espaces et retours à la ligne, et applique les règles CSV aux seules cellules remplacées. Ces deux cas font l’objet de tests distincts.

L’unique collision exacte Incident code contient des valeurs divergentes : la clé actuelle ne suffit pas pour décider quelle ligne modifier. Les deux lignes sont conservées dans l’import et le rapport. La publication du type concerné est bloquée tant qu’une règle confirmée ne permet pas de les traiter. Aucun ajout automatique de `ID`, aucune fusion et aucune suppression ne sont supposés résoudre ce cas.

L’analyse des collisions lit les groupes et leurs présences par lots de 300, puis compare les clés exactes aux enregistrements originaux. Le résumé JSON conserve au plus 1 000 numéros de ligne dans `collision_rows`, avec `collision_rows_total` et `collision_rows_truncated` pour signaler les lignes restantes. `collisions` continue de compter les occurrences après la première de chaque clé. La liste paginée et le rapport CSV privé téléchargeable conservent le détail de toutes les présences et de tous les blocages ; le plafond du résumé ne retire aucune ligne ni aucun blocage. En cas de collision du hash entre clés différentes, des passages séparés vérifient les identités exactes, sans fusion automatique.


