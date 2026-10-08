#!/usr/bin/env bash
set -euo pipefail
umask 077
root=$(realpath -e -- "${1:?Application root required}")
shared="$root/shared"
php_bin=${PHP_BIN:-php}
defaults=${BACKUP_MYSQL_CNF:-"$shared/backup.cnf"}
test -f "$defaults"
exec 9>"$shared/storage/app/private/operations.lock"
flock -w 2100 -x 9 || { echo 'Active operations did not finish' >&2; exit 1; }
# Deployment may have switched current (and removed the previous release) while waiting.
release=$(realpath -e -- "$root/current")
was_down=0
test ! -f "$shared/storage/framework/down" || was_down=1
"$php_bin" "$release/artisan" down --retry=60 >&2
trap 'if [[ "$was_down" == 0 ]]; then "$php_bin" "$release/artisan" up >&2; fi' EXIT
database=$("$php_bin" "$release/scripts/operations.php" database-name)
python3 "$release/scripts/backup-stream.py" "$release" "$shared/storage/app/private" "$defaults" "$database"
