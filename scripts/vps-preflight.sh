#!/usr/bin/env bash
# Read-only host inventory. Run on the VPS; this script installs/changes nothing.
# No environment dump, broad container inspect, secrets or business files.
# An optional selected Caddy container exposes ONLY its network names/IPs.
set -euo pipefail

domain=${1:-myabi.example.org}
site_root=${2:-/opt/myabi}
caddy_container=${3:-}
if [[ ! "$domain" =~ ^[a-zA-Z0-9.-]+$ || "$domain" == -* || "$site_root" != /* ||
      ( -n "$caddy_container" && ! "$caddy_container" =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]*$ ) ]]; then
    printf 'Usage: bash scripts/vps-preflight.sh [DNS_NAME] [/absolute/site/path] [CADDY_CONTAINER]\n' >&2
    exit 2
fi

section() { printf '\n%s\n' "$1"; }
available() { command -v "$1" >/dev/null 2>&1; }

section 'Systeme'
if [[ -r /etc/os-release ]]; then
    sed -n '/^\(PRETTY_NAME\|ID\|VERSION_ID\)=/p' /etc/os-release
fi
uname -srm
if [[ -r /proc/meminfo ]]; then
    awk '/^(MemTotal|MemAvailable|SwapTotal|SwapFree):/ {print}' /proc/meminfo
fi

section 'Disque (octets ; fichiers de tous les services inclus)'
probe=$site_root
while [[ ! -d "$probe" && "$probe" != / ]]; do
    probe=$(dirname -- "$probe")
done
df -B1 --output=source,size,used,avail,pcent,target -- "$probe"
df -i -- "$probe"

section 'Ports TCP 80, 443 et 18080 deja ecoutes'
if available ss; then
    ss -lntp '( sport = :80 or sport = :443 or sport = :18080 )' || true
else
    printf 'ss indisponible ; inventaire des ports a completer.\n'
fi

section 'DNS'
printf 'Nom attendu: %s ; IPv4 cible communiquee: 203.0.113.10\n' "$domain"
if available getent; then
    getent ahostsv4 "$domain" | awk '{print $1}' | sort -u || true
    # The system resolver may filter IPv6 when the host has no IPv6 interface.
    printf 'IPv6 visible par le resolveur du systeme (peut etre vide):\n'
    getent ahostsv6 "$domain" | awk '{print $1}' | sort -u || true
else
    printf 'getent indisponible ; resolution a verifier separement.\n'
fi

section 'Docker et Compose'
if available docker; then
    docker --version
    docker compose version || true
    if docker info --format 'Docker root: {{.DockerRootDir}}' 2>/dev/null; then
        docker version --format 'Serveur Docker: {{.Server.Version}}' || true
        printf '\nConteneurs actifs (noms, images, ports uniquement):\n'
        docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Ports}}' || true
        if [[ -n "$caddy_container" ]]; then
            printf '\nReseaux et adresses du conteneur Caddy selectionne: %s\n' "$caddy_container"
            docker inspect --type container --format '{{range $name, $settings := .NetworkSettings.Networks}}{{printf "%s IPv4=%s IPv6=%s\n" $name $settings.IPAddress $settings.GlobalIPv6Address}}{{end}}' "$caddy_container" || true
        fi
        printf '\nImages presentes:\n'
        docker image ls --format 'table {{.Repository}}\t{{.Tag}}\t{{.Size}}' || true
        printf '\nOccupation Docker (ne comprend pas les donnees des bind mounts):\n'
        docker system df || true
    else
        printf 'Daemon inaccessible a ce compte ; controles Docker a executer par un administrateur autorise.\n'
    fi
else
    printf 'Docker absent du PATH.\n'
fi

section 'Outils utiles deja presents'
for program in caddy nginx php8.4 mariadb certbot systemctl setfacl flock curl python3; do
    if available "$program"; then
        command -v "$program"
    fi
done

section 'Conclusion'
printf '%s\n' \
    'Inventaire seulement : aucune installation, ouverture de port, modification DNS ou lecture de secret.' \
    'Avant preparation finale : confirmer le proxy existant, ses reseaux Docker, la marge disque/RAM et un OS pris en charge.' \
    'Les bind mounts MariaDB, sauvegardes et donnees metier doivent etre comptes dans la capacite sans exposer leur contenu.'
