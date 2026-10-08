#!/usr/bin/env python3
"""Verify every member before restoration; reject unexpected files and path traversal."""
import hashlib
import json
import pathlib
import sys
import zipfile


def verify(path):
    with zipfile.ZipFile(path) as archive:
        names = archive.namelist()
        if len(names) != len(set(names)):
            raise ValueError('Duplicate backup members')
        for name in names:
            p = pathlib.PurePosixPath(name)
            if p.is_absolute() or '..' in p.parts or '\\' in name or not (name in ('manifest.json', 'database.sql') or name.startswith('private/')):
                raise ValueError('Unexpected backup member: ' + name)
        if archive.getinfo('manifest.json').file_size > 128 * 1024 * 1024:
            raise ValueError('Backup manifest exceeds the expected size')
        manifest = json.loads(archive.read('manifest.json'))
        if manifest.get('format') != 1 or set(manifest['files']) != set(names) - {'manifest.json'} or 'database.sql' not in manifest['files']:
            raise ValueError('Incomplete backup manifest')
        for name, expected in manifest['files'].items():
            digest, size = hashlib.sha256(), 0
            with archive.open(name) as source:
                while chunk := source.read(1024 * 1024):
                    digest.update(chunk)
                    size += len(chunk)
            if digest.hexdigest() != expected['sha256'] or size != expected['bytes']:
                raise ValueError('Corrupt backup member: ' + name)
        return manifest


if __name__ == '__main__':
    manifest = verify(sys.argv[1])
    print(json.dumps({'verified_files': len(manifest['files']), 'created_at': manifest['created_at'], 'release': manifest['release']}))
