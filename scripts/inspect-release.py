#!/usr/bin/env python3
"""Validate a release before extraction and print its expanded byte count."""
import pathlib
import sys
import tarfile

ROOTS = {'app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'scripts', 'vendor', 'artisan', 'composer.json', 'composer.lock', 'RELEASE.json'}


def inspect(path):
    total = 0
    names = set()
    with tarfile.open(path, 'r:gz') as archive:
        for member in archive:
            name = member.name.removeprefix('./')
            if name in ('', '.'):
                continue
            p = pathlib.PurePosixPath(name)
            if p.is_absolute() or '..' in p.parts or '\\' in name or p.parts[0] not in ROOTS:
                raise ValueError(f'Unexpected release path: {name}')
            if not (member.isfile() or member.isdir()):
                raise ValueError(f'Links and special files are forbidden: {name}')
            if any(part == '.env' or part.startswith('.env.') for part in p.parts):
                raise ValueError('An environment file is present in the release')
            if '__pycache__' in p.parts or p.suffix in ('.pyc', '.pyo'):
                raise ValueError('A Python runtime cache is present in the release')
            if p.suffix in ('.sqlite', '.csv', '.xlsx') and p.parts[0] != 'vendor':
                raise ValueError(f'Possible business data in release: {name}')
            if p.parts[:2] in [('vendor', 'phpunit'), ('vendor', 'fakerphp'), ('database', 'factories'), ('database', 'seeders')]:
                raise ValueError(f'Development dependency in release: {name}')
            names.add(name)
            total += member.size
    for required in ['artisan', 'composer.lock', 'vendor/autoload.php', 'public/index.php', 'RELEASE.json']:
        if required not in names:
            raise ValueError(f'Missing release file: {required}')
    return total


if __name__ == '__main__':
    print(inspect(sys.argv[1]))
