#!/usr/bin/env python3
"""Restore a verified archive into an EMPTY database and EMPTY private directory."""
import importlib.util
import os
import pathlib
import re
import shutil
import subprocess
import sys
import zipfile

spec = importlib.util.spec_from_file_location('verify_backup', pathlib.Path(__file__).with_name('verify-backup.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def restore(archive_path, private_path, defaults, database):
    if not re.fullmatch(r'[A-Za-z0-9_]+', database):
        raise ValueError('Invalid target database name')
    manifest = module.verify(archive_path)
    target = pathlib.Path(private_path).resolve()
    if target.exists() and any(target.iterdir()):
        raise ValueError('Restore target directory must be empty')
    command = [os.environ.get('MARIADB_BIN', 'mariadb'), '--defaults-extra-file=' + str(pathlib.Path(defaults).resolve(strict=True)), '--batch', '--skip-column-names', database]
    result = subprocess.run(command + ['--execute', 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'], check=True, capture_output=True, text=True)
    if result.stdout.strip() != '0':
        raise ValueError('Restore target database must be empty; existing databases are never overwritten')
    target.mkdir(parents=True, exist_ok=True, mode=0o700)
    with zipfile.ZipFile(archive_path) as archive:
        process = subprocess.Popen(command, stdin=subprocess.PIPE)
        try:
            with archive.open('database.sql') as source:
                shutil.copyfileobj(source, process.stdin, 1024 * 1024)
            process.stdin.close()
            if process.wait() != 0:
                raise RuntimeError('Database restore failed; application must remain in maintenance')
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()
        for name in manifest['files']:
            if not name.startswith('private/'):
                continue
            destination = target / pathlib.PurePosixPath(name).relative_to('private')
            destination.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            with archive.open(name) as source, destination.open('xb') as output:
                shutil.copyfileobj(source, output, 1024 * 1024)
            destination.chmod(0o600)
    print('Restore complete in staging. Verify matching release, APP_KEY, SQL grants and publications before switching configuration.')


if __name__ == '__main__':
    if len(sys.argv) != 5:
        raise SystemExit('Usage: restore-backup.py BACKUP_ZIP EMPTY_PRIVATE_DIR MYSQL_DEFAULTS_FILE EMPTY_DATABASE')
    restore(*sys.argv[1:])
