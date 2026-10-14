# Groupes de termes et campagnes de traduction

Date : 13 octobre 2026.

Statut du document : spécification fonctionnelle consolidée à partir des décisions prises pendant le cadrage et la revue. Le développement demandé le 13 octobre 2026 est documenté dans la [mise en œuvre technique](../technique/groupes-campagnes-implementation.md). Les décisions métier restant ouvertes sont regroupées en section 9.

## 1. Objectif et lexique

La fonctionnalité distingue le classement thématique des termes et l’organisation du travail de traduction.

| Notion | Définition |
| --- | --- |
| Groupe de termes | Regroupement thématique réutilisable de termes du référentiel. |
| Campagne de traduction | Travail organisé sur une sélection de termes, pour une langue cible, avec des consignes, des personnes assignées, une échéance et un suivi. |
| Statut du terme dans le référentiel | Statut existant, qui conserve ses règles métier et son circuit de validation. |
| Statut de traitement dans la campagne | Statut propre à la participation d’un terme à une campagne. |

Les menus portent les noms **Groupes de termes** et **Campagnes de traduction**. Dans leur contexte, les formes courtes « groupe » et « campagne » peuvent être utilisées. Le mot « lot » ne sert pas de synonyme, afin d’éviter la confusion avec les lots d’import.

## 2. Groupes de termes

### 2.1. Attributs

| Attribut | Description |
| --- | --- |
| Titre | Nom du sujet regroupant les termes. |
| Couleur | Repère visuel, accompagné du titre. |
| Description | Présentation du sujet et du périmètre du groupe. |
| Nombre de termes | Nombre calculé des termes appartenant au groupe. |

Le titre est obligatoire et la description facultative. La couleur est choisie dans une palette avec une valeur par défaut.

Un groupe n’a pas d’assignation, d’échéance, de statut de travail ou de pourcentage d’avancement. Ces informations appartiennent aux campagnes.

### 2.2. Appartenance des termes

- Un terme appartient à **zéro ou un groupe de termes**.
- Un groupe peut contenir plusieurs termes.
- « Groupe de termes » devient un attribut du terme.
- Cet attribut est visible dans le catalogue et sur la fiche du terme.
- Le catalogue permet de filtrer par groupe et de rechercher les termes **Sans groupe**.
- L’affectation d’un terme à un groupe ne modifie pas son identité ni son statut dans le référentiel.

Cette règle remplace l’hypothèse initiale d’appartenance d’un terme à plusieurs groupes.

## 3. Campagnes de traduction

### 3.1. Attributs

| Attribut | Description |
| --- | --- |
| Titre | Nom de la campagne. |
| Description / consignes | Objectif du travail et instructions aux participants. |
| Objectif | Traduction ou Révision. |
| Langue cible | Une seule langue par campagne. |
| Assignation | Une ou plusieurs personnes chargées du travail. |
| Échéance | Délai de réalisation de la campagne. |
| Statut | À faire, En cours ou Terminé. |
| Nombre de termes | Nombre calculé des termes inclus dans la campagne. |
| Avancement du travail | Pourcentage des termes avec une proposition envoyée ou une traduction confirmée. |
| Part des traductions confirmées | Pourcentage des termes dont la traduction est confirmée. |

Le titre, la langue cible et l’objectif sont obligatoires. La description et l’échéance sont facultatives. Une campagne peut être préparée sans assignation, mais doit avoir au moins une personne assignée pour démarrer. Elle ne possède pas de couleur propre.

La langue cible et l’objectif sont modifiables uniquement tant qu’aucun terme n’a jamais été admis dans la campagne. La première admission, par un groupe synchronisé ou un ajout direct, les verrouille définitivement, même si la campagne est encore À faire. Le retrait ultérieur de tous les termes ne lève pas ce verrouillage, y compris lors d’une réouverture. Les participants effectuent leur travail dans la campagne après son démarrage.

Le suivi concerne **l’attribut principal du terme affiché dans le catalogue**, dans la langue cible. Chaque terme possède un seul statut de traitement et au plus une proposition suivie à un instant donné dans la campagne. Les autres attributs restent accessibles sur la fiche, hors du suivi de cette campagne.

### 3.2. Composition

Une campagne peut s’appuyer sur un ou plusieurs groupes de termes, ou sur une sélection directe dans le catalogue. Un groupe peut servir à plusieurs campagnes successives ou simultanées.

Un même terme peut participer à plusieurs campagnes. Son statut de traitement est indépendant dans chacune d’elles. Ses traductions et propositions restent attachées au terme dans le référentiel ; la campagne suit la proposition liée à son propre travail.

Exemple fictif : le groupe « Gestion des personnes » peut servir à une campagne de traduction italienne, puis à une campagne de révision française.

Les campagnes fondées sur des groupes sont **synchronisées avec leur contenu** tant qu’elles sont À faire ou En cours. La synchronisation s’arrête au statut **Terminé** : une modification ultérieure des groupes ne modifie pas le périmètre de la campagne terminée et ne la rouvre pas automatiquement.

La sélection peut combiner des groupes synchronisés et des termes ajoutés directement, sans doublons. Un terme ajouté directement reste inclus même s’il quitte son groupe. Lorsqu’un terme inclus uniquement par les groupes quitte tous les groupes sélectionnés par une campagne synchronisée, il est retiré du travail actif et des compteurs. Son historique de participation et ses propositions sont conservés. Ce retrait ne supprime pas le terme du référentiel et n’annule pas ses propositions.

Un réexamen explicite par un Validateur de la langue ou un Gestionnaire rouvre une campagne terminée et relance la synchronisation avec les groupes.

Si le terme faisant l’objet du réexamen n’appartient plus à aucun groupe sélectionné et n’est pas déjà ajouté directement, la demande de réexamen le conserve dans la campagne comme un ajout direct, enregistré avant la reprise de la synchronisation. Il reste ainsi actif et À traiter, même s’il a quitté son groupe. Cette inclusion et son motif sont conservés dans l’historique.

Tout terme précédemment retiré qui revient dans la campagne repart systématiquement **À traiter**, sans reprise de son ancien statut. Son historique reste consultable. Le rattachement automatique d’une proposition existante ne s’applique pas à ce retour ; une proposition pertinente en attente peut être rattachée explicitement. Ce rattachement relève des droits de travail des participants assignés ; en présence de propositions concurrentes, le choix relève de l’arbitrage.

### 3.3. Objectifs

Deux objectifs sont proposés :

| Objectif | Travail attendu |
| --- | --- |
| **Traduction** | Traduire les termes sans traduction validée dans la langue cible. |
| **Révision** | Inclure les termes avec une traduction validée dans la langue cible, puis les examiner pour les confirmer sans changement ou proposer une correction. |

La distinction des objectifs sert à sélectionner les termes à leur admission, y compris lors des synchronisations et des ajouts directs, selon l’attribut principal et la langue cible. Elle ne remplace pas les quatre statuts de traitement. Un terme déjà inclus n’est pas retiré au seul motif que sa traduction évolue. La validation d’une proposition liée reste enregistrée dans la campagne comme une traduction confirmée et ne fait pas disparaître le travail accompli.

Pour les deux objectifs, l’admission exige également que la ligne source possède une cellule pour l’attribut principal dans la langue cible. Une cellule présente mais vide n’est pas une cellule absente : le terme reste soumis au critère de son objectif. Un terme sans cette cellule est exclu de l’admission, lors de la sélection initiale, d’un ajout direct ou d’une synchronisation. Le bilan d’admission indique le nombre de termes exclus et le motif « Colonne absente dans la langue cible ». Ces exclusions ne créent pas de participation et ne contribuent pas aux compteurs de la campagne.

## 4. Statut d’un terme dans une campagne

### 4.1. Les quatre statuts

| Statut | Signification |
| --- | --- |
| **À traiter** | Le terme doit être examiné et le travail reste à effectuer. |
| **À arbitrer** | Une décision est nécessaire pour poursuivre : sens ambigu, désaccord, choix entre traductions ou contexte insuffisant. Un motif doit être renseigné. |
| **Proposition envoyée** | Une proposition a été soumise pour ce travail et suit le circuit de validation habituel. |
| **Traduction confirmée** | La traduction a été confirmée soit par vérification de la valeur actuelle sans changement, soit par validation de la proposition liée à la campagne. |

### 4.2. Confirmation sans changement

L’action **Confirmer la traduction actuelle** permet de reconnaître le travail d’examen d’un terme dont la traduction est déjà correcte.

Elle ne crée aucune proposition et ne modifie pas la traduction. Elle enregistre la personne, la date et la version de la traduction examinée. Elle ne confère aucun droit de validation supplémentaire.

Si une proposition en attente est suivie pour ce terme dans la campagne, la confirmation met fin à son rattachement actif dans cette campagne. La confirmation et l’arrêt du suivi sont enregistrés ensemble. Le rattachement précédent reste dans l’historique. La proposition reste en attente dans le référentiel et son éventuel suivi dans d’autres campagnes est conservé ; cette action ne la valide ni ne la rejette.

L’historique doit distinguer une confirmation sans changement d’une confirmation issue de la validation d’une proposition.

### 4.3. Évolution après une décision

| Événement | Statut résultant dans la campagne |
| --- | --- |
| Envoi d’une proposition liée au travail de la campagne | Proposition envoyée |
| Validation de la proposition actuellement suivie | Traduction confirmée |
| Rejet de la proposition actuellement suivie | À traiter, avec le motif du rejet visible |
| Décision humaine de demander un arbitrage | À arbitrer, avec un motif |
| Envoi d’une nouvelle proposition après correction | Proposition envoyée |
| Confirmation de la traduction actuelle sans changement | Traduction confirmée ; arrêt du rattachement actif à la proposition éventuellement suivie |

Un rejet nécessitant un arbitrage ne déclenche pas automatiquement le statut « À arbitrer » : ce passage est manuel.

La validation ou le rejet d’une proposition non suivie ne déclenche pas les transitions liées à la proposition de campagne. Le suivi porte sur le rattachement actif ; un ancien rattachement conservé dans l’historique ne suffit pas à déclencher ces transitions. Le rejet ultérieur d’une proposition dont le suivi a cessé lors d’une confirmation sans changement laisse donc le terme Traduction confirmée. Si une décision extérieure modifie une traduction déjà confirmée, les règles distinctes de réexamen de la section 4.6 s’appliquent.

### 4.4. Propositions déjà en attente

Lors de la première admission d’un terme dans une campagne, si une seule proposition pertinente est en attente pour la langue cible et l’attribut principal, elle est automatiquement rattachée à son travail dans la campagne. Le terme prend alors le statut **Proposition envoyée**. Sa validation ou son rejet suit les transitions de la section 4.3 tant que ce rattachement reste actif. Le retour d’un terme retiré suit l’exception définie en section 3.2.

Ce rattachement ne crée pas de nouvelle proposition et ne vaut pas validation. Les règles de pertinence doivent respecter le contexte et les contrôles existants du référentiel. En l’absence de proposition pertinente, le terme entre au statut **À traiter**.

S’il existe plusieurs propositions pertinentes en attente pour le terme, la langue et l’attribut concernés au moment de son entrée, le terme est placé **À arbitrer**, avec un motif indiquant la concurrence. Aucune proposition n’est choisie automatiquement. Un Validateur de la langue ou un Gestionnaire sélectionne la proposition à suivre dans le cadre de la résolution de l’arbitrage.

Une même proposition peut être suivie par plusieurs campagnes de la même langue. Sa validation ou son rejet actualise le traitement dans chacune des campagnes qui la suivent encore. Cela ne duplique pas la proposition et ne fusionne pas les historiques de participation. L’arrêt du suivi à la suite d’une confirmation sans changement dans une campagne ne met pas fin au suivi dans les autres campagnes.

### 4.5. Résolution d’un arbitrage

Un Validateur de la langue ou un Gestionnaire résout l’arbitrage par une décision motivée, conservée dans l’historique avec son auteur et sa date.

| Issue de la décision | Statut résultant |
| --- | --- |
| Demander une reprise du travail avec des consignes | À traiter |
| Rattacher une proposition pertinente en attente | Proposition envoyée |
| Confirmer la traduction actuelle sans changement | Traduction confirmée |

Le choix d’une proposition à suivre ne la valide pas ; la validation conserve ses droits et ses contrôles existants.

### 4.6. Réexamen d’une confirmation

Si la traduction examinée ou sa référence source change après une confirmation, le terme revient automatiquement **À traiter** dans les campagnes **À faire** ou **En cours** concernées. La confirmation précédente reste dans l’historique. La validation de la proposition suivie produit elle-même une nouvelle confirmation fondée sur la traduction validée : elle n’est pas immédiatement annulée par le changement qu’elle vient d’apporter.

La validation ultérieure d’une proposition dont le suivi a cessé lors d’une confirmation sans changement est une décision extérieure pour cette campagne. Si elle modifie la traduction confirmée, elle déclenche le réexamen selon les règles de cette section, et non la transition de validation d’une proposition suivie.

Dans une campagne **Terminé**, ces changements extérieurs ne modifient pas automatiquement les statuts, les compteurs ou le périmètre : la campagne conserve la trace du travail achevé. Un Validateur de la langue ou un Gestionnaire peut demander explicitement un réexamen. Le terme revient alors À traiter, la campagne repasse En cours et sa synchronisation avec les groupes reprend. Si le terme a quitté les groupes sélectionnés, il est conservé comme ajout direct selon la section 3.2 ; la synchronisation ne le retire pas.

## 5. Indicateurs d’avancement

Soit :

- **N** : nombre total de termes actifs de la campagne, hors participations retirées ;
- **P** : nombre de termes au statut Proposition envoyée ;
- **C** : nombre de termes au statut Traduction confirmée.

Pour une campagne non vide :

**Avancement du travail = (P + C) / N × 100**

**Part des traductions confirmées = C / N × 100**

Chaque terme compte une seule fois dans chaque calcul. Plusieurs propositions sur un terme n’augmentent pas son poids.

Les termes « À traiter » et « À arbitrer » ne contribuent pas à l’avancement du travail. Un compteur distinct affiche le nombre de termes à arbitrer.

Exemple fictif : sur 20 termes, 8 propositions envoyées et 7 traductions confirmées donnent 75 % d’avancement du travail et 35 % de traductions confirmées. Les 5 autres termes restent à traiter ou à arbitrer.

Le rejet d’une proposition encore suivie fait baisser l’avancement du travail, puisque le terme revient à traiter. La validation d’une proposition encore suivie fait augmenter la part confirmée sans changer l’avancement du travail. Après l’arrêt du suivi lors d’une confirmation sans changement, un rejet ne modifie pas ces indicateurs ; une validation qui change la traduction relève du réexamen décrit en section 4.6.

Une campagne vide affiche **Aucun terme**, sans calcul de pourcentage ni interprétation comme campagne achevée. Les deux pourcentages sont affichés à une décimale ; un ratio inférieur à 100 % ne doit jamais être affiché comme 100 % à cause de l’arrondi. La fin de campagne repose sur les statuts réels des termes, et non sur un pourcentage arrondi. Les nombres de termes dans chacun des quatre statuts sont également affichés.

## 6. Statut de la campagne

### 6.1. Les trois statuts

| Statut | Signification |
| --- | --- |
| **À faire** | La campagne est préparée, mais le travail n’a pas commencé. |
| **En cours** | La traduction, les arbitrages ou les validations sont en cours. |
| **Terminé** | Tous les termes de la campagne sont au statut Traduction confirmée. |

Le statut « En pause » n’est pas retenu.

### 6.2. Transitions

- Le démarrage de la campagne, de **À faire** vers **En cours**, est déclenché manuellement par le Gestionnaire.
- Le démarrage exige au moins un terme actif et une personne assignée.
- Une campagne démarrée et non vide passe automatiquement à **Terminé** lorsque tous ses termes sont **Traduction confirmée**.
- Une campagne vide ne passe pas automatiquement à Terminé.
- Si tous les termes actifs sont retirés après le démarrage, la campagne reste En cours et affiche Aucun terme.
- Un réexamen explicite par un Validateur de la langue ou un Gestionnaire fait repasser une campagne terminée à **En cours**, maintient le terme demandé dans son périmètre selon la section 3.2 et relance sa synchronisation. Les changements extérieurs d’une traduction ou de sa référence ne la rouvrent pas automatiquement.
- L’ajout d’un terme à un groupe ne rouvre pas une campagne terminée : sa synchronisation est arrêtée. Cette règle remplace la réouverture automatique à la suite d’une modification des groupes envisagée initialement.

Une campagne peut afficher **100 % d’avancement du travail** et rester **En cours** : toutes les propositions ont été envoyées, mais certaines attendent encore une validation.

Le passage à Terminé repose sur la confirmation de tous les termes, pas sur le seul envoi des propositions.

## 7. Consultation et Kanban

Le menu **Groupes de termes** permet de consulter les regroupements thématiques et leurs termes.

Le menu **Campagnes de traduction** présente une vue Kanban avec trois colonnes :

**À faire → En cours → Terminé**

Les cartes représentent les campagnes. Elles présentent leur titre, les personnes assignées, l’échéance, le nombre de termes, l’avancement du travail et la part des traductions confirmées.

La fiche d’une campagne donne accès aux consignes, aux termes concernés et à leur statut de traitement propre à la campagne. Le statut du référentiel doit rester identifiable séparément, afin d’éviter toute confusion avec le statut de campagne.

La vue propose une recherche par titre et les filtres langue cible, objectif, groupe, personne assignée, **Mes campagnes** et retard. Mes campagnes correspond aux campagnes auxquelles le compte est assigné.

Une campagne est en retard si son échéance est dépassée et qu’elle n’est pas terminée. Une campagne sans échéance n’est pas signalée en retard.

Le Gestionnaire peut démarrer la campagne par un bouton **Démarrer** ou par déplacement de la carte de À faire vers En cours. Les deux commandes appliquent les mêmes droits et conditions. Le passage à Terminé est automatique : un déplacement de carte ne permet pas de le forcer. Une commande accessible sans glisser-déposer est toujours disponible. Une réouverture s’effectue par un réexamen explicite autorisé, et non par un simple déplacement de carte.

### 7.1. Archivage et suppression

Le Gestionnaire peut archiver une campagne terminée. Elle conserve son historique et est masquée dans la vue courante ; elle reste consultable dans les archives. L’archivage est une propriété distincte du statut : il n’ajoute pas de quatrième colonne au Kanban.

Une campagne vide et sans historique peut être supprimée. Une campagne ayant un historique ne peut pas être supprimée selon cette règle.

Un groupe ne peut être supprimé que s’il ne contient plus de termes et n’est lié à aucune campagne. Sinon, il peut être archivé : il est conservé dans les campagnes déjà liées et n’est plus sélectionnable pour de nouvelles campagnes. L’archivage ne retire pas ses termes des campagnes existantes et ne modifie pas l’appartenance des termes au groupe.

## 8. Compatibilité avec le référentiel

### 8.1. Répartition des droits

| Acteur | Actions autorisées dans le cadre de cette fonctionnalité |
| --- | --- |
| Gestionnaire | Créer et modifier les groupes et campagnes, composer les campagnes, assigner les personnes, démarrer les campagnes et gérer leur archivage ou suppression dans les conditions définies. |
| Participant assigné | Proposer une traduction, confirmer sans changement et demander un arbitrage, dans ses langues autorisées. |
| Validateur de la langue ou Gestionnaire | Résoudre les arbitrages et demander un réexamen. |

Les droits actuels de validation restent inchangés. Cette répartition ne permet pas de contourner les restrictions de langue, d’assignation ou de validation du référentiel.

### 8.2. Règles préservées

- Le classement en groupe ne modifie pas le statut métier du terme.
- Le statut de traitement dans la campagne ne remplace pas le circuit de validation existant.
- Une proposition envoyée ne modifie pas la valeur en vigueur avant une validation autorisée.
- La confirmation sans changement constate une vérification et arrête le suivi actif d’une proposition éventuellement rattachée dans cette campagne ; elle ne valide ni ne rejette une proposition en attente.
- L’assignation à une campagne ne confère aucun droit supplémentaire sur une langue, une traduction ou une validation.
- Les identités ambiguës et les blocages du contrat technique restent préservés.

Cette spécification complète les [exigences fonctionnelles](exigences-fonctionnelles.md) et les [spécifications fonctionnelles de référence](../../specifications-fonctionnelles-referentiel-traductions-arge-abi.md), sans modifier les règles existantes d’import, de validation et de publication.

### 8.3. Référentiel commun du 13.10.2026

Un terme est identifié par son type et sa clé exacte, sans organisation propriétaire. Une traduction validée par attribut et langue est commune à toutes les organisations ; les groupes et campagnes s’appuient sur cette valeur et ne créent aucune variante.

Les utilisateurs autorisés consultent les groupes, campagnes, consignes, historiques et compteurs du catalogue partagé. La contribution en campagne exige toujours l’assignation, la langue et un compte actif ; l’assignation ne confère aucun droit de validation. L’organisation documente la création et les contributions. Ce chantier adapte l’existant au modèle commun sans ajouter de fonctionnalité de groupes ou de campagnes.

## 9. Décisions restantes et détails de conception

### 9.1. Décisions métier restant à préciser

La consultation commune est actée dans la section 8.3 ; aucune variante organisationnelle n’est à définir.

### 9.2. Détails de conception à préciser

Les autres décisions métier précédentes sont adoptées. Les détails suivants devront être précisés pendant la conception, sans modifier ces règles :

- Limites de longueur et éventuelle unicité des titres, couleurs exactes de la palette et couleur par défaut, conformément à DESIGN.md.
- Disposition des formulaires, des motifs, des consignes, de l’historique et des filtres sur les différents écrans.
- Modalités de désarchivage et de réexamen d’une campagne archivée, ainsi que gestion du retrait de la dernière personne assignée après démarrage.
- Contrôles de pertinence d’une proposition dont la référence ou le contexte a changé, en respectant les blocages existants.
- Traitement d’une modification de référence pendant qu’une proposition est suivie, et consultation des participations retirées dont une proposition a été décidée depuis le retrait.
- Modalités de retrait manuel ou d’exclusion individuelle d’un terme provenant d’un groupe synchronisé, si cette commande est proposée.

La demande de développement du 13 octobre 2026 autorise la mise en œuvre de ce contrat. Les choix de conception retenus, avec alignement sur le référentiel commun, sont décrits dans la [mise en œuvre technique](../technique/groupes-campagnes-implementation.md).

## 10. Scénarios d’acceptation fonctionnelle

| Scénario | Résultat attendu |
| --- | --- |
| Affecter un terme à un groupe | Le groupe apparaît sur le terme ; celui-ci ne possède pas deux groupes simultanément. Son statut dans le référentiel est inchangé. |
| Filtrer le catalogue par groupe ou Sans groupe | Seuls les termes correspondant au choix sont affichés. |
| Inclure un terme dans deux campagnes | Les statuts de traitement sont indépendants dans les deux campagnes. |
| Ajouter un terme admissible à un groupe lié à une campagne À faire ou En cours | Le terme entre dans la campagne selon son objectif. |
| Sélectionner ou synchroniser un terme sans colonne pour l’attribut principal dans la langue cible | Le terme n’est pas admis ; le bilan indique son exclusion et le motif Colonne absente dans la langue cible. Aucun statut ni compteur de participation n’est créé pour ce terme. |
| Préparer une campagne dont tous les termes sélectionnés sont exclus de l’admission | La campagne reste vide, sans pourcentage ; le bilan présente les exclusions. En l’absence de toute admission antérieure, la langue et l’objectif restent modifiables. |
| Retirer un terme inclus uniquement par les groupes de tous les groupes sélectionnés | Sa participation est retirée du travail actif et des compteurs ; son historique et ses propositions sont conservés. |
| Faire quitter son groupe à un terme également ajouté directement | Sa participation active dans la campagne est conservée. |
| Réinclure un terme précédemment retiré | Il repart À traiter ; son historique est conservé et aucune proposition n’est rattachée automatiquement. |
| Inclure un terme avec une seule proposition pertinente en attente | La proposition est automatiquement rattachée et le terme prend le statut Proposition envoyée. |
| Inclure un terme avec plusieurs propositions pertinentes en attente | Le terme est À arbitrer avec un motif ; aucune proposition n’est choisie automatiquement. |
| Décider une proposition suivie par plusieurs campagnes | La décision actualise chacune des participations qui suivent cette proposition. |
| Confirmer une traduction actuelle correcte | Le terme devient Traduction confirmée dans la campagne, sans nouvelle proposition ni modification de la valeur. |
| Confirmer la traduction actuelle alors qu’une proposition en attente est suivie dans la campagne | Le terme devient Traduction confirmée et le rattachement actif cesse dans la même action. Le rattachement reste dans l’historique ; la proposition reste en attente dans le référentiel. |
| Rejeter ensuite cette proposition dont le suivi a cessé | Le terme reste Traduction confirmée dans la campagne ; le rejet ne déclenche pas la transition vers À traiter. |
| Valider ensuite cette proposition et changer la traduction dans une campagne non terminée | Le terme revient À traiter selon les règles de réexamen ; la confirmation précédente reste dans l’historique. |
| Valider ensuite cette proposition et changer la traduction après la fin de la campagne | La campagne reste terminée avec ses statuts et compteurs conservés ; un réexamen explicite est nécessaire. |
| Confirmer sans changement dans une campagne puis rejeter une proposition encore suivie par une autre campagne | La première campagne conserve sa confirmation ; la participation de l’autre campagne revient À traiter. |
| Envoyer une proposition liée à une campagne | Le terme devient Proposition envoyée ; la validation existante reste nécessaire. |
| Valider la proposition actuellement suivie | Le terme devient Traduction confirmée ; la confirmation issue de validation est identifiable dans l’historique. |
| Rejeter la proposition actuellement suivie | Le terme revient À traiter avec le motif visible ; l’avancement du travail diminue. |
| Demander un arbitrage | Le terme devient À arbitrer et un motif est enregistré. |
| Décider une proposition non suivie | Les transitions de la proposition suivie ne sont pas déclenchées ; si la traduction confirmée change, les règles de réexamen s’appliquent. |
| Modifier une traduction confirmée ou sa référence dans une campagne non terminée | Le terme revient À traiter ; la confirmation précédente reste dans l’historique. |
| Modifier une traduction confirmée ou sa référence après la fin de la campagne | La campagne reste terminée et conserve son historique ; un réexamen explicite est nécessaire pour la rouvrir. |
| Envoyer toutes les propositions sans les valider | L’avancement du travail atteint 100 %, mais la campagne reste En cours. |
| Confirmer tous les termes d’une campagne démarrée | La campagne non vide passe automatiquement à Terminé. |
| Consulter une campagne vide | Aucun terme est affiché ; aucun pourcentage n’est calculé et la campagne n’est pas terminée automatiquement. |
| Démarrer sans personne assignée ou sans terme actif | Le démarrage est refusé. |
| Retirer tous les termes actifs d’une campagne démarrée | Elle reste En cours avec Aucun terme. |
| Modifier la langue ou l’objectif avant toute première admission | La modification est autorisée ; l’admission ultérieure utilise les paramètres ainsi choisis. |
| Admettre le premier terme puis modifier la langue ou l’objectif, même en À faire | La modification est refusée. |
| Retirer tous les termes puis modifier la langue ou l’objectif | La modification reste refusée ; le verrouillage lié à la première admission est conservé. |
| Modifier la langue ou l’objectif après démarrage ou réouverture | La modification est refusée. |
| Calculer un ratio qui s’arrondirait à 100 % sans l’atteindre | L’affichage à une décimale reste inférieur à 100 % ; la terminaison dépend des statuts réels. |
| Archiver une campagne terminée | Elle disparaît de la vue courante et conserve son historique dans les archives. |
| Supprimer un groupe contenant des termes ou lié à une campagne | La suppression est refusée ; l’archivage reste possible. |
| Modifier les termes des groupes d’une campagne terminée | Le périmètre et les compteurs de la campagne restent inchangés ; elle reste terminée. |
| Demander explicitement le réexamen d’un terme confirmé dans une campagne terminée | Le terme revient À traiter, la campagne repasse En cours et reprend sa synchronisation avec les groupes. |
| Demander ce réexamen après que le terme a quitté tous les groupes sélectionnés | Le terme est conservé comme ajout direct avant la synchronisation ; il reste actif et À traiter. L’inclusion et son motif sont enregistrés dans l’historique. |

Ces scénarios décrivent le comportement attendu ; ils ne constituent pas des tests exécutés ni une preuve d’implémentation.
