#!/usr/bin/env sh
set -eu
umask 0007
# Preserve stdout for CLI commands that return machine-readable data.
php scripts/check-php-runtime.php >/dev/null
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/uploads storage/logs
exec "$@"
