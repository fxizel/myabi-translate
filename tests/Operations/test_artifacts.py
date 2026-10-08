import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import Mock, patch
import zipfile

SCRIPTS = Path(__file__).resolve().parents[2] / 'scripts'


def load(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), SCRIPTS / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


release_inspector = load('inspect-release')
backup = load('backup-stream')
backup_verifier = load('verify-backup')
restore = load('restore-backup')


class NonSeekableOutput:
    def __init__(self):
        self.buffer = io.BytesIO()

    def write(self, data):
        return self.buffer.write(data)

    def flush(self):
        pass

    def getvalue(self):
        return self.buffer.getvalue()


class ArtifactSafetyTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.addCleanup(self.temp.cleanup)

    def release(self, extra=None):
        path = self.root / 'release.tar.gz'
        with tarfile.open(path, 'w:gz') as archive:
            for name in ['artisan', 'composer.lock', 'vendor/autoload.php', 'public/index.php', 'RELEASE.json']:
                info = tarfile.TarInfo('./' + name)
                info.size = 4
                archive.addfile(info, io.BytesIO(b'test'))
            if extra is not None:
                archive.addfile(extra, io.BytesIO(b'x' * extra.size) if extra.isfile() else None)
        return path

    def test_release_expanded_size_is_computed(self):
        self.assertEqual(20, release_inspector.inspect(self.release()))

    def test_release_rejects_secrets_traversal_business_data_and_dev_dependencies(self):
        for name in ['../escape.php', '/tmp/escape', '.env', 'app/.env.backup', 'database/original.csv', 'vendor/phpunit/code.php', 'scripts/__pycache__/check.pyc', 'scripts/check.pyc']:
            with self.subTest(name=name), self.assertRaises(ValueError):
                release_inspector.inspect(self.release(tarfile.TarInfo(name)))

    def test_release_rejects_symlinks(self):
        link = tarfile.TarInfo('app/linked')
        link.type = tarfile.SYMTYPE
        link.linkname = '/etc/passwd'
        with self.assertRaises(ValueError):
            release_inspector.inspect(self.release(link))

    def coherent_backup(self):
        release = self.root / 'release'
        private = self.root / 'private'
        release.mkdir()
        private.mkdir()
        (release / 'RELEASE.json').write_text(json.dumps({'version': 'v1', 'commit': 'a' * 40}))
        (private / 'sample.devconf').write_bytes(b'Original\r\n"quoted";value\r\n')
        (private / 'operations.lock').write_text('')
        defaults = self.root / 'backup.cnf'
        defaults.write_text('[client]\npassword=private\n')
        process = Mock(stdout=io.BytesIO(b'-- synthetic SQL\nCREATE TABLE example(id INT);\n'))
        process.wait.return_value = 0
        process.poll.return_value = 0
        # SSH stdout cannot seek; exercise the exact streaming ZIP mode used by backup.sh.
        output = NonSeekableOutput()
        with patch.object(backup.subprocess, 'Popen', return_value=process):
            backup.stream(release, private, defaults, 'synthetic_test', output)
        path = self.root / 'backup.zip'
        path.write_bytes(output.getvalue())
        return path

    def test_backup_stream_preserves_bytes_and_excludes_locks_and_secrets(self):
        path = self.coherent_backup()
        manifest = backup_verifier.verify(path)
        self.assertEqual({'database.sql', 'private/sample.devconf'}, set(manifest['files']))
        with zipfile.ZipFile(path) as archive:
            self.assertEqual(b'Original\r\n"quoted";value\r\n', archive.read('private/sample.devconf'))
            self.assertNotIn(b'password=private', b''.join(archive.read(name) for name in archive.namelist()))

    def test_backup_verification_rejects_changed_members_before_restore(self):
        original = self.coherent_backup()
        corrupt = self.root / 'corrupt.zip'
        with zipfile.ZipFile(original) as source, zipfile.ZipFile(corrupt, 'w') as target:
            for name in source.namelist():
                target.writestr(name, b'corrupted' if name == 'database.sql' else source.read(name))
        with self.assertRaises(ValueError):
            backup_verifier.verify(corrupt)

    def test_restore_refuses_nonempty_directory_without_running_database_commands(self):
        archive = self.coherent_backup()
        target = self.root / 'restore'
        target.mkdir()
        (target / 'keep.txt').write_text('existing')
        with patch.object(restore.subprocess, 'run') as command, self.assertRaises(ValueError):
            restore.restore(archive, target, self.root / 'backup.cnf', 'synthetic_restore')
        command.assert_not_called()
        self.assertEqual('existing', (target / 'keep.txt').read_text())

    def test_restore_refuses_nonempty_database(self):
        archive = self.coherent_backup()
        with patch.object(restore.subprocess, 'run', return_value=Mock(stdout='2\n')), self.assertRaises(ValueError):
            restore.restore(archive, self.root / 'restore', self.root / 'backup.cnf', 'synthetic_restore')
        self.assertFalse((self.root / 'restore').exists())


if __name__ == '__main__':
    unittest.main()
