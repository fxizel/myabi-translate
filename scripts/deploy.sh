#!/usr/bin/env bash
set -euo pipefail
umask 077
root=$(realpath -e -- "${1:?Absolute application root required}")
archive=$(realpath -e -- "${2:?Release archive required}")
[[ "$root" != / && "$root" != "$HOME" && "$archive" == "$root/incoming/"* ]] || { echo 'Unsafe deployment paths' >&2; exit 1; }
shared="$root/shared"
php_bin=${PHP_BIN:-php}
deploy_group=${DEPLOY_GROUP:-}
public_group=${DEPLOY_PUBLIC_GROUP:-}
[[ "$php_bin" =~ ^[a-zA-Z0-9_./-]+$ && "$php_bin" != *".."* ]] || { echo 'Invalid PHP executable' >&2; exit 1; }
[[ -z "$deploy_group" || "$deploy_group" =~ ^[a-z_][a-z0-9_-]*$ ]] || { echo 'Invalid private deployment group' >&2; exit 1; }
[[ -z "$public_group" || "$public_group" =~ ^[a-z_][a-z0-9_-]*$ ]] || { echo 'Invalid public deployment group' >&2; exit 1; }
[[ -z "$public_group" || ( -n "$deploy_group" && "$public_group" != "$deploy_group" ) ]] || { echo 'Public and private groups must be distinct' >&2; exit 1; }
test -f "$shared/.env"
test -f "$shared/migration.env"
test -x "$shared/backup-hook.sh"
test ! -f "$shared/storage/framework/down" || { echo 'Existing maintenance must be resolved before deployment' >&2; exit 1; }
mkdir -p "$root/releases" "$shared/storage/app/private" "$shared/storage/framework/cache/data" "$shared/storage/framework/sessions" "$shared/storage/framework/views" "$shared/storage/logs"
if [[ -n "$deploy_group" ]]; then
    command -v setfacl >/dev/null
    getent group "$deploy_group" >/dev/null
    # The deploy user owns code/configuration; FPM shares runtime data only.
    for directory in "$root" "$root/releases" "$shared"; do
        chgrp "$deploy_group" "$directory"
        chmod 2750 "$directory"
    done
    setfacl -b -k "$shared"
    private_files=("$shared/migration.env" "$shared/backup.cnf" "$shared/backup-hook.sh")
    if [[ -e "$shared/post-migrate-hook.sh" ]]; then private_files+=("$shared/post-migrate-hook.sh"); fi
    for private in "${private_files[@]}"; do
        [[ -f "$private" && ! -L "$private" && "$(stat -c %u "$private")" == "$(id -u)" ]] || { echo 'Operational secrets must be regular deploy-owned files' >&2; exit 1; }
        setfacl -b "$private"
        chmod 600 "$private"
    done
    chmod 700 "$shared/backup-hook.sh"
    if [[ -f "$shared/post-migrate-hook.sh" ]]; then chmod 700 "$shared/post-migrate-hook.sh"; fi
    [[ ! -L "$shared/.env" && "$(stat -c %u "$shared/.env")" == "$(id -u)" ]] || { echo 'Application environment must be a regular deploy-owned file' >&2; exit 1; }
    setfacl -b "$shared/.env"
    chgrp "$deploy_group" "$shared/.env"
    chmod 640 "$shared/.env"
    # Existing FPM-owned files already inherit these rights; only their owner may chmod them.
    find "$shared/storage" -user "$(id -u)" -type d -exec chgrp "$deploy_group" {} + -exec chmod 2770 {} + -exec setfacl -m d:u::rwx,d:g::rwx,d:m::rwx,d:o::--- {} +
    find "$shared/storage" -user "$(id -u)" -type f -exec chgrp "$deploy_group" {} + -exec chmod 660 {} +
    umask 007
    if [[ -n "$public_group" ]]; then
        getent group "$public_group" >/dev/null
        setfacl -m "g:$public_group:--x" "$root" "$root/releases"
    fi
fi
# Keep this inode permanently: unlinking a held lock would let a second writer in.
touch "$shared/storage/app/private/operations.lock"
exec 9<>"$shared/storage/app/private/operations.lock"
flock -w 2100 -x 9 || { echo 'Timed out waiting for active operations' >&2; exit 1; }
previous=$(readlink -f "$root/current" || true)
scratch=$(mktemp -d "$root/incoming/check.XXXXXX")
rollback_safe=0
maintenance_started=0
success=0
cleanup() {
    status=$?
    trap - EXIT
    rm -rf -- "$scratch"
    if [[ "$success" == 0 && "$maintenance_started" == 1 ]]; then
        if [[ "$rollback_safe" == 1 && -f "$previous/artisan" ]]; then
            ln -s "$previous" "$root/current.rollback"
            mv -Tf "$root/current.rollback" "$root/current"
            "$php_bin" "$previous/artisan" up || true
            echo 'Previous code restored; schema was unchanged.' >&2
        else
            echo 'Deployment failed. Maintenance remains enabled. Restore the coherent backup or verify schema compatibility before reopening.' >&2
        fi
    fi
    exit "$status"
}
trap cleanup EXIT
# Only trusted operational inspectors are extracted before full path validation.
tar -xzf "$archive" -C "$scratch" ./scripts/inspect-release.py ./scripts/operations.php ./RELEASE.json
expanded=$(python3 "$scratch/scripts/inspect-release.py" "$archive")
release_id=$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(d["commit"])' "$scratch/RELEASE.json")
[[ "$release_id" =~ ^[a-fA-F0-9]{40}$ ]] || { echo 'Invalid release identity' >&2; exit 1; }
candidate="$root/releases/$release_id"
test ! -e "$candidate" || { echo 'Release directory already exists; inspect previous attempt' >&2; exit 1; }
if [[ -f "$previous/artisan" ]]; then
    quota_json=$("$php_bin" "$previous/scripts/operations.php" quota)
else
    # Initial deployment must have its quota/database figures supplied by the operator.
    test -f "$shared/initial-quota.json"
    quota_json=$(cat "$shared/initial-quota.json")
fi
used=$(du -sb "$root" | cut -f1)
available=$(df -PB1 "$root" | awk 'NR==2 {print $4}')
python3 - "$quota_json" "$used" "$expanded" "$available" <<'PY'
import json, sys
q=json.loads(sys.argv[1]); used,expanded,free=map(int,sys.argv[2:])
margin=20*1024*1024
if used+expanded+int(q['database_bytes'])+int(q['external_bytes'])+margin > int(q['quota']) or expanded+margin > free:
    raise SystemExit('Insufficient quota for existing releases, incoming archive, expanded release, database and safety margin')
PY
mkdir "$candidate"
tar -xzf "$archive" -C "$candidate" --no-same-owner --no-same-permissions
ln -s "$shared/.env" "$candidate/.env"
ln -s "$shared/storage" "$candidate/storage"
mkdir -p "$candidate/bootstrap/cache"
if [[ -n "$deploy_group" ]]; then
    chgrp -h -R "$deploy_group" "$candidate"
    find "$candidate" -type d -exec chmod 2750 {} +
    find "$candidate" -type f -exec chmod 640 {} +
    chmod 2770 "$candidate/bootstrap/cache"
    setfacl -m d:u::rwx,d:g::rwx,d:m::rwx,d:o::--- "$candidate/bootstrap/cache"
    if [[ -n "$public_group" ]]; then
        setfacl -m "g:$public_group:--x" "$candidate"
        setfacl -R -m "g:$public_group:r-X" "$candidate/public"
    fi
fi
if [[ -d "$previous/database/migrations" ]] && diff -qr "$previous/database/migrations" "$candidate/database/migrations" >/dev/null; then rollback_safe=1; fi
app_url=$("$php_bin" -r '$a=json_decode($argv[1],true); echo rtrim($a["url"],"/");' "$quota_json")
[[ "$app_url" == https://* ]] || { echo 'HTTPS URL required' >&2; exit 1; }
# Bootstrap DNS, Nginx and a valid TLS certificate first. Any HTTP status is OK
# before initial activation, but a TLS/network failure must precede maintenance.
curl --proto '=https' --tlsv1.2 --max-time 30 --silent --show-error "$app_url/" --output /dev/null
secret=$("$php_bin" -r 'echo bin2hex(random_bytes(24));')
# Laravel otherwise prints the bypass URL, exposing this secret in the CI log.
"$php_bin" "$candidate/artisan" down --retry=60 --secret="$secret" --quiet
maintenance_started=1
# The hook runs under the existing exclusive lock and must stream a verified backup
# to the organization's designated durable storage, never a GitHub artifact.
"$shared/backup-hook.sh" "$candidate" "$shared" "$previous"
(
    set -a
    # Administrator-owned shell environment: ONLY DB_* migration credentials.
    source "$shared/migration.env"
    set +a
    "$php_bin" "$candidate/artisan" migrate --force --no-interaction
)
# Optional operator-owned hook installs the final least-privilege SQL grants.
# Its failure keeps maintenance enabled, just like a failed migration.
if [[ -e "$shared/post-migrate-hook.sh" ]]; then
    test -x "$shared/post-migrate-hook.sh"
    "$shared/post-migrate-hook.sh" "$candidate" "$shared" "$previous"
fi
"$php_bin" "$candidate/artisan" package:discover --no-interaction
"$php_bin" "$candidate/artisan" config:cache
"$php_bin" "$candidate/artisan" view:cache
"$php_bin" "$candidate/scripts/operations.php" health
"$php_bin" "$candidate/artisan" security:check-audit
ln -s "$candidate" "$root/current.next"
mv -Tf "$root/current.next" "$root/current"
cookie="$scratch/health-cookie"
curl --proto '=https' --tlsv1.2 --max-time 30 --fail --silent --show-error --cookie-jar "$cookie" "$app_url/$secret" --output /dev/null
curl --proto '=https' --tlsv1.2 --max-time 30 --fail --silent --show-error --cookie "$cookie" "$app_url/up" --output /dev/null
curl --proto '=https' --tlsv1.2 --max-time 30 --fail --silent --show-error --cookie "$cookie" "$app_url/login" --output /dev/null
"$php_bin" "$candidate/artisan" up
success=1
# Retain current and previous code; never purge business files or publications.
for old in "$root"/releases/*; do
    [[ -d "$old" && "$old" != "$candidate" && "$old" != "$previous" && "$(basename "$old")" =~ ^[a-fA-F0-9]{40}$ ]] || continue
    rm -rf -- "$old"
done
rm -f -- "$archive" "$archive.sha256"
echo "Release $release_id is healthy and active."
