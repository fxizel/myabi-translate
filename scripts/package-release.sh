#!/usr/bin/env bash
set -euo pipefail
umask 077
version=${1:?Release tag required}
commit=${2:?Commit SHA required}
output=${3:?Output archive required}
[[ "$version" =~ ^[a-zA-Z0-9._-]+$ ]] || { echo 'Invalid release tag' >&2; exit 1; }
[[ "$commit" =~ ^[a-fA-F0-9]{40}$ ]] || { echo 'Invalid commit SHA' >&2; exit 1; }
test -f vendor/autoload.php
test ! -d vendor/phpunit
test ! -d vendor/fakerphp
test -s public/css/app.css
test -s public/js/app.js
temporary_root=$(realpath -e -- "${TMPDIR:-/tmp}")
stage=$(mktemp -d "$temporary_root/myabi-package.XXXXXX")
stage=$(realpath -e -- "$stage")
[[ "$stage" == "$temporary_root/myabi-package."* ]] || { echo 'Unsafe package staging path' >&2; exit 1; }
trap 'rm -rf -- "$stage"' EXIT
for path in app bootstrap config database lang public resources routes scripts vendor artisan composer.json composer.lock; do
    test ! -e "$path" || cp -aL -- "$path" "$stage/"
done
# The release is constructed from an allowlist, then runtime/development caches are removed.
rm -rf -- "$stage/database/factories" "$stage/database/seeders" "$stage/resources/js" "$stage/resources/css"
find "$stage/bootstrap/cache" -type f ! -name .gitignore -delete
find "$stage" -type f \( -name '.env' -o -name '.env.*' -o -name '*.sqlite' -o -name '*.sqlite-*' \) -delete
find "$stage" -type f \( -name '*.pyc' -o -name '*.pyo' \) -delete
find "$stage" -type d -name '__pycache__' -empty -delete
python3 - "$stage" "$version" "$commit" <<'PY'
import json, pathlib, sys
root, version, commit = sys.argv[1:]
pathlib.Path(root, 'RELEASE.json').write_text(json.dumps({'version': version, 'commit': commit}, indent=2) + '\n')
PY
tar -czf "$output" -C "$stage" .
echo "Production release packaged: $output"
