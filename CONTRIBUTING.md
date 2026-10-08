# Contribuer

1. Lire le [README](README.md) et [AGENTS.md](AGENTS.md) (règles du dépôt).
2. Ne jamais committer de secret, de fichier `.env`, de données DEVCONF réelles (`data/imports/`), d'export, de rapport ou de publication. Les tests n'utilisent que des fixtures synthétiques.
3. Avant une demande de fusion :

   ```sh
   php artisan test
   node --test tests/JavaScript/*.test.cjs
   cd extension && npm ci && npm run check   # si l'extension est modifiée
   ```

4. Les contributions sont publiées sous la licence [AGPL-3.0](LICENSE) du dépôt.
