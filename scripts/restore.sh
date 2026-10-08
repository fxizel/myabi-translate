#!/usr/bin/env bash
set -euo pipefail
umask 077
root=$(realpath -e -- "${1:?Application root required}")
archive=$(realpath -e -- "${2:?Verified backup ZIP required}")
target=${3:?Empty private restore directory required}
defaults=$(realpath -e -- "${4:?Restore MariaDB defaults file required}")
database=${5:?Empty restore database required}
php_bin=${PHP_BIN:-php}
exec 9>"$root/shared/storage/app/private/operations.lock"
flock -w 2100 -x 9 || { echo 'Active operations did not finish' >&2; exit 1; }
release=$(realpath -e -- "$root/current")
"$php_bin" "$release/artisan" down --retry=60
python3 "$release/scripts/restore-backup.py" "$archive" "$target" "$defaults" "$database"
echo 'Maintenance remains enabled. Verify the restored staging database/files, then switch shared configuration and reopen explicitly.'
