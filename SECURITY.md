# Politique de sécurité

## Signaler une vulnérabilité

Ne pas ouvrir d'issue publique pour une faille de sécurité. Utiliser le signalement privé de GitHub (onglet **Security** → **Report a vulnerability**) ou écrire à l'auteur du dépôt, indiqué dans l'historique Git.

Merci de joindre les étapes de reproduction, la version ou le commit concerné et l'impact estimé. Un accusé de réception est envoyé dans un délai raisonnable ; la correction est publiée avant toute divulgation détaillée.

## Périmètre

- Application Laravel (authentification, MFA, rôles, audit, imports, publications).
- Scripts et configurations de déploiement (`deploy/`, `scripts/`).
- Extension navigateur (`extension/`).

N'envoyez jamais de données métier réelles, de secrets ou de fichiers `.env` dans un signalement public. Voir aussi [docs/security-deployment.md](docs/security-deployment.md).
