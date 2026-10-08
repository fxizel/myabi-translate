#!/usr/bin/env sh
set -eu
umask 0007
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/uploads storage/logs
exec "$@"
