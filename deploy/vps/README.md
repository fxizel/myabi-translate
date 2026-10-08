# Modèles de déploiement natif sur VPS

Cette variante utilise Nginx, PHP 8.4 FPM et MariaDB 11.4 installés sur le système. Sur un VPS qui héberge déjà d'autres services, la variante isolée décrite dans le guide principal est prioritaire. Ces modèles natifs restent une option après choix d'un système pris en charge et inspection des services existants. Ils ne modifient rien à distance.

Le nom de site est `myabi.example.org`, le répertoire `/srv/myabi`. Les chemins des exécutables sont ceux de paquets Debian/Ubuntu ; les vérifier sur le système retenu. Ne pas remplacer la configuration globale Nginx, MariaDB ou PHP et ne pas arrêter les services d'autres sites. Aucun processus Laravel permanent ni Node n'est nécessaire : FPM sert les requêtes, les commandes métier sont ponctuelles.

| Modèle | Emplacement proposé |
| --- | --- |
| `env.vps.example` | `/srv/myabi/shared/.env`, après remplacement des `REPLACE_*` |
| `nginx-http.conf` | Configuration du seul site, pendant l'émission du certificat |
| `nginx-https.conf` | Remplace le précédent une fois le certificat réel disponible |
| `php-fpm.conf`, `php-pool.conf` | `/etc/myabi/`, service FPM dédié |
| `myabi-php-fpm.service` | `/etc/systemd/system/` |
| `myabi-work.service`, `myabi-work.timer` | `/etc/systemd/system/` |
| `myabi-notifications.service`, `myabi-notifications.timer` | `/etc/systemd/system/` |

## Comptes et chemins privés

Le compte de livraison `myabi-deploy` est distinct du compte FPM `myabi-web`, qui exécute aussi les commandes métier ponctuelles. Le groupe partagé est `myabi-web`. Les commandes métier ne peuvent ainsi pas lire les secrets de migration et de sauvegarde. Ne pas ajouter le compte Nginx `www-data` à ce groupe : il doit uniquement pouvoir traverser l'arborescence des versions et lire `public`, par des ACL ciblées. Installer l'outil `setfacl` si cette variante est retenue.

| Chemin | Propriétaire / groupe | Droits attendus |
| --- | --- | --- |
| `/srv/myabi/{releases,incoming,shared}` | `myabi-deploy:myabi-web` | Traversée web de `releases` seulement ; pas d'accès Nginx aux données privées |
| Version livrée et son code | `myabi-deploy:myabi-web` | Répertoires `2750`, fichiers `0640` ; lecture `public` par ACL `www-data` |
| `shared/.env` | `myabi-deploy:myabi-web` | `0640` |
| `shared/migration.env`, `shared/backup.cnf` et clés de sauvegarde | `myabi-deploy:myabi-web` | `0600`, donc illisibles par FPM |
| `shared/backup-hook.sh` | `myabi-deploy:myabi-web` | `0700` |
| `shared/storage` et `bootstrap/cache` de chaque version | `myabi-deploy:myabi-web` | Répertoires `2770`, fichiers `0660`, héritage du groupe |
| `/var/log/myabi-php` | `root:myabi-web` | `0750`, journal FPM privé |
| `/var/log/myabi-nginx` | `root:www-data` | `0750`, journal d'erreur privé |
| `/var/lib/myabi-nginx/{body,fastcgi}` | `www-data:www-data` | `0700`, fichiers de transfert temporaires |
| `/var/www/letsencrypt/.well-known/acme-challenge` | Compte ACME / lecture Nginx | Ne contient que les preuves ACME publiques |

Créer aussi `shared/storage/framework/uploads`, `sessions`, `views`, `cache/data`, `shared/storage/app/private` et `shared/storage/logs` avec les droits partagés. Précréer `worker.log`, `notifications.log` et `php-errors.log` en `0660 myabi-deploy:myabi-web`. Prévoir une rotation privée de ces journaux et de ceux de Nginx/FPM ; conserver les événements d'audit métier en base. Le journal maître FPM peut être rouvert avec `SIGUSR1` envoyé uniquement au service dédié après rotation. Les noms, recherches et paramètres HTTP ne doivent pas être ajoutés à des journaux publics ; les modèles désactivent le journal d'accès du site.

Tous les services applicatifs utilisent `UMask=0007`. Les commandes manuelles et la livraison doivent conserver le même partage du runtime ; les fichiers de secrets de migration/sauvegarde gardent leurs droits spécifiques. `PRIVATE_SHARED_GROUP=true` active les modes privés partagés dans l'application. Réparer les anciens fichiers `0600`/répertoires `0700` par leur propriétaire avant la première utilisation de deux comptes distincts. Aucune permission globale `0777` n'est nécessaire.

## HTTPS et activation

Servir d'abord uniquement `nginx-http.conf` sur le port 80. Obtenir le certificat réel avec le client ACME retenu, par exemple Certbot en mode `webroot` avec `/var/www/letsencrypt` pour ce seul domaine. Puis remplacer le site HTTP par `nginx-https.conf`, tester la configuration et recharger Nginx. Ne pas installer un faux certificat pour contourner cette étape. Conserver le chemin ACME pour le renouvellement, tester le renouvellement et prévoir un rechargement Nginx après émission. Le mode `webroot` évite de réécrire les autres sites. [Documentation Certbot](https://eff-certbot.readthedocs.io/en/stable/using.html#webroot).

Le socket FPM est local, accessible à `www-data`. Le pool impose mémoire 256 Mio, fichier 100 Mio, POST 110 Mio et requête PHP 120 secondes. Nginx tamponne le transfert avant PHP ; une absence de données du client pendant 1 800 secondes provoque un abandon. Compter les fichiers temporaires Nginx/PHP dans la capacité et tester le téléversement du DEVCONF Incident code complet. Les quatre enfants FPM constituent un point de départ à mesurer selon les ressources restantes des autres services. [Configuration FPM](https://www.php.net/manual/en/install.fpm.configuration.php), [directives Nginx](https://nginx.org/en/docs/http/ngx_http_core_module.html).

Le passage de `current` à une nouvelle version utilise un chemin réel dans les paramètres FastCGI. Le service FPM conserve l'accès au parent stable `releases` ; les droits Unix limitent l'écriture de son compte au cache de chaque version. Ne pas modifier ces droits pour rendre tout le code inscriptible par le compte FPM. [Paramètres FastCGI](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_param).

## Tâches ponctuelles

Activer les deux timers uniquement après la livraison et les vérifications de santé : `myabi-work.timer` traite une opération par minute et `myabi-notifications.timer` exécute le résumé à 07:00, heure suisse. Un timer ne démarre pas une seconde instance du même service encore actif. `Persistent=true` rattrape un déclenchement manqué lorsque le timer est réactivé. Le résumé a son propre service pour ne pas manquer 07:00 pendant un import long. [Timers systemd](https://github.com/systemd/systemd/blob/main/man/systemd.timer.xml).

Cette variante **remplace entièrement** le cron `artisan schedule:run`. Les deux commandes sont les seules tâches actuellement déclarées dans `routes/console.php` ; toute nouvelle tâche devra être reportée dans ce choix de planification. Les conditions empêchent le démarrage en maintenance. Un résumé tombant pendant une maintenance est ignoré : le relancer après réouverture ; l'application évite les envois déjà confirmés.

Le traitement est limité à deux heures par `TimeoutStartSec`, afin de tolérer un import qui progresse. La livraison attend son verrou pendant 35 minutes seulement ; elle échoue proprement si le traitement dure davantage, au lieu de le tuer pour déployer. À la limite des deux heures, systemd termine le processus ; les transactions SQL non validées sont annulées à la fermeture de la connexion et les checkpoints déjà écrits sont conservés. Le timer peut reprendre les états reprenables à l'échéance suivante ; examiner l'état de l'opération et les journaux en cas de dépassement. Ne pas considérer cette limite comme une preuve de performance ou tuer un import pour respecter la fenêtre de déploiement. [Services systemd](https://github.com/systemd/systemd/blob/main/man/systemd.service.xml).

Le résumé prend le même verrou en mode partagé avant de démarrer ; il attend donc les imports, sauvegardes et livraisons en cours. Après acquisition, il vérifie à nouveau la maintenance et résout la version `current`, qui a pu changer pendant l'attente. Son délai maximal est également de deux heures, attente comprise. Un dépassement doit être examiné et le résumé relancé après réouverture.

## Vérifications avant activation sur le système retenu

Après création des comptes, chemins, secrets et certificats, exécuter avec le compte d'administration les contrôles ci-dessous. Ils ne certifient pas les performances, les droits effectifs ou l'accès SMTP tant qu'ils n'ont pas été réalisés sur le VPS.

```sh
/usr/sbin/php-fpm8.4 --test --fpm-config /etc/myabi/php-fpm.conf
nginx -t
systemd-analyze verify /etc/systemd/system/myabi-php-fpm.service \
  /etc/systemd/system/myabi-work.service /etc/systemd/system/myabi-work.timer \
  /etc/systemd/system/myabi-notifications.service /etc/systemd/system/myabi-notifications.timer
systemd-analyze calendar '*-*-* *:*:00'
systemd-analyze calendar '*-*-* 07:00:00 Europe/Zurich'
sudo -u myabi-web test -r /srv/myabi/shared/.env
sudo -u myabi-web test ! -r /srv/myabi/shared/migration.env
sudo -u myabi-web test ! -r /srv/myabi/shared/backup.cnf
sudo -u myabi-web test -w /srv/myabi/shared/storage/app/private
sudo -u www-data test ! -r /srv/myabi/shared/.env
sudo -u myabi-deploy /usr/bin/php8.4 /srv/myabi/current/scripts/operations.php health
```

Vérifier également une création/lecture de fichier privé par les deux comptes, le verrou commun, un téléchargement authentifié, le refus des URLs privées, l'envoi SMTP, le renouvellement TLS et la restauration d'une sauvegarde hors VPS. Les fichiers `migration.env`, `backup.cnf`, le hook de sauvegarde et `initial-quota.json` suivent le guide principal ; aucune valeur secrète ni quota supposé n'est fourni ici.

Lors de la préparation, les deux modèles Nginx ont passé `nginx -t` dans Nginx 1.30, en conteneurs locaux éphémères sans réseau. Le contrôle HTTPS a utilisé un certificat de test éphémère, supprimé avec son volume après le test ; aucun certificat ni service n'a été installé sur le VPS. La configuration native FPM a passé `php-fpm --test` avec PHP 8.4 ; les neuf unités natives/Docker ont passé `systemd-analyze verify` avec systemd 252.39. Les deux calendriers ont été reconnus, dont 07:00 Europe/Zurich. Les prérequis propres au conteneur de contrôle (chemins PHP et dépendance du service Docker) étaient des fixtures locales ; aucun service n'a été démarré. Les unités ont été copiées avec des octets identiques en mode `0644`, car le montage Windows expose les fichiers en `0777`. L'exécution des services et les droits effectifs restent à vérifier sur le système finalement retenu. Le script d'inventaire `scripts/vps-preflight.sh` a passé `bash -n` et ShellCheck ; il n'a pas été exécuté sur le VPS.
