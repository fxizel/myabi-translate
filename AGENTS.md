# Référentiel myABI

Laravel 13 / PHP 8.4 / MariaDB 11.4, pages Blade et ressources statiques. Les spécifications à la racine et docs/formats-devconf.md définissent le contrat métier. La maquette myABI du 22.09.2026 est la référence visuelle (maquette/README.md).

- Préserver data/imports et ne pas afficher de valeurs métier dans les journaux publics.
- Secrets, originaux, rapports et publications restent hors de public et des livraisons.
- Les imports créent des propositions ; seules les décisions autorisées valident.
- Ne jamais fusionner les identités ambiguës ; conserver les blocages du §9 technique.
- Tests : php artisan test ; node --test tests/JavaScript/*.test.cjs ; contrôle complet : php scripts/test_devconf_roundtrip.php --validate-semantics. Node 24 sert seulement aux tests hors production.
- extension/ : extension navigateur MyAB Translation Inspector (Chrome/Firefox, TypeScript). Node 20.19+ requis seulement pour la développer ; voir extension/README.md (cd extension && npm run check). Elle est hors des livraisons Laravel.
- Les runtimes .runtime sont ignorés. Aucun Node ni Redis n'est requis en production.
- La préparation VPS demandée le 22 septembre 2026 propose Docker Compose isolé et conserve une variante native. Voir docs/deploiement-vps.md ; inventorier le frontal et les services existants avant tout déploiement. Les commandes métier restent ponctuelles, planifiées séparément.
- Dépôt public (AGPL-3.0) : aucune donnée réelle, aucun identifiant d'infrastructure (domaine, IP, hôte) ni secret dans Git. data/imports reste local et ignoré ; la documentation utilise des valeurs d'exemple (example.org, 203.0.113.0/24).
