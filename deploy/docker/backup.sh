#!/usr/bin/env bash
# Writes only a binary ZIP to stdout. Run with `compose run --rm -T backup`.
set -euo pipefail
umask 0007
exec 9>storage/app/private/operations.lock
flock -w 2100 -x 9 || { echo 'Active operations did not finish' >&2; exit 1; }
was_down=0
test ! -f storage/framework/down || was_down=1
php artisan down --retry=60 --quiet >&2
cleanup() {
    status=$?
    rm -f /tmp/myabi-backup.cnf
    if [[ "$was_down" == 0 ]]; then php artisan up >&2 || status=1; fi
    exit "$status"
}
trap cleanup EXIT
python3 - <<'PY'
from pathlib import Path
password = Path('/run/secrets/db_backup_password').read_text().rstrip('\r\n')
if len(password) < 20 or '\n' in password or '\r' in password:
    raise SystemExit('Invalid backup credential')
quoted = password.replace('\\', '\\\\').replace('"', '\\"')
path = Path('/tmp/myabi-backup.cnf')
path.write_text('[client]\nhost=db\nuser=myabi_backup\npassword="' + quoted + '"\n')
path.chmod(0o600)
PY
python3 scripts/backup-stream.py /var/www/html /var/www/html/storage/app/private /tmp/myabi-backup.cnf myabi
