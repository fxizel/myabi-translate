#!/usr/bin/env python3
"""Stream one coherent SQL/private-file backup to stdout; caller holds operations.lock."""
import datetime
import hashlib
import json
import os
import pathlib
import re
import subprocess
import sys
import zipfile

CHUNK = 1024 * 1024


def stream(release, private, defaults, database, output):
    if not re.fullmatch(r'[A-Za-z0-9_]+', database):
        raise ValueError('Invalid database name')
    release, private, defaults = (pathlib.Path(p).resolve(strict=True) for p in (release, private, defaults))
    metadata = {'format': 1, 'created_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                'database': database, 'release': json.loads((release / 'RELEASE.json').read_text()), 'files': {}}
    command = [os.environ.get('MARIADB_DUMP_BIN', 'mariadb-dump'), '--defaults-extra-file=' + str(defaults),
               '--single-transaction', '--quick', '--hex-blob', '--skip-lock-tables', '--routines', '--triggers', database]
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=1, allowZip64=True) as archive:
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=sys.stderr)
        checksum, size = hashlib.sha256(), 0
        try:
            with archive.open('database.sql', 'w', force_zip64=True) as target:
                while chunk := process.stdout.read(CHUNK):
                    target.write(chunk)
                    checksum.update(chunk)
                    size += len(chunk)
            if process.wait() != 0:
                raise RuntimeError('MariaDB backup failed')
        finally:
            process.stdout.close()
            if process.poll() is None:
                process.kill()
                process.wait()
        metadata['files']['database.sql'] = {'sha256': checksum.hexdigest(), 'bytes': size}
        for path in sorted(private.rglob('*')):
            if path.is_symlink():
                raise ValueError('Private data symlinks require operator review: ' + str(path))
            if not path.is_file() or path.name == 'operations.lock':
                continue
            name = 'private/' + path.relative_to(private).as_posix()
            checksum, size = hashlib.sha256(), 0
            with path.open('rb') as source, archive.open(name, 'w', force_zip64=True) as target:
                while chunk := source.read(CHUNK):
                    target.write(chunk)
                    checksum.update(chunk)
                    size += len(chunk)
            metadata['files'][name] = {'sha256': checksum.hexdigest(), 'bytes': size}
        archive.writestr('manifest.json', json.dumps(metadata, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    if len(sys.argv) != 5:
        raise SystemExit('Usage: backup-stream.py RELEASE_DIR PRIVATE_DIR MYSQL_DEFAULTS_FILE DATABASE > backup.zip')
    stream(*sys.argv[1:], sys.stdout.buffer)
