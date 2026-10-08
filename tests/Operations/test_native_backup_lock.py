"""A deployment may replace current while native backup/restore waits for the lock."""
import io
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import sys
import tempfile
import time
import unittest
import zipfile

try:
    import fcntl
except ImportError:
    fcntl = None

SCRIPTS = Path(__file__).resolve().parents[2] / 'scripts'
LINUX_TOOLS = all(shutil.which(tool) for tool in ['bash', 'flock', 'python3'])


@unittest.skipUnless(sys.platform.startswith('linux') and LINUX_TOOLS, 'Linux locking utilities required')
class NativeBackupLockTest(unittest.TestCase):
    def exercise_switch(self, operation, remove_previous):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            private = root / 'shared/storage/app/private'
            private.mkdir(parents=True)
            (root / 'shared/storage/framework').mkdir()
            defaults = root / 'shared/backup.cnf'
            defaults.write_text('[client]\n')
            trace = root / 'trace'
            waiting = root / 'waiting'
            tools = root / 'tools'
            tools.mkdir()

            def executable(name, source):
                path = tools / name
                path.write_text(source)
                path.chmod(0o700)
                return str(path)

            php = executable('php', '''#!/usr/bin/env python3
import os, pathlib, sys
path = pathlib.Path(sys.argv[1])
assert path.exists(), 'Selected release disappeared'
with open(os.environ['TRACE'], 'a') as trace: trace.write(str(path) + '\\n')
if sys.argv[2] == 'database-name': print('synthetic_backup')
''')
            executable('flock', '#!/usr/bin/env bash\nprintf ready > "$WAITING"\nexec ' + shutil.which('flock') + ' "$@"\n')
            dump = executable('mariadb-dump', '#!/usr/bin/env bash\nprintf -- "-- synthetic SQL\\n"\n')
            for version in ['before', 'after']:
                release = root / 'releases' / version
                (release / 'scripts').mkdir(parents=True)
                (release / 'artisan').touch()
                (release / 'scripts/operations.php').touch()
                (release / 'RELEASE.json').write_text(json.dumps({'version': version}))
                shutil.copyfile(SCRIPTS / 'backup-stream.py', release / 'scripts/backup-stream.py')
                (release / 'scripts/restore-backup.py').write_text(
                    'import os, pathlib\n'
                    'with open(os.environ["TRACE"], "a") as trace: trace.write(str(pathlib.Path(__file__)) + "\\n")\n')
            current = root / 'current'
            previous = root / 'releases/before'
            current.symlink_to(previous, target_is_directory=True)
            archive = root / 'verified.zip'
            archive.touch()
            command = ['bash', str(SCRIPTS / (operation + '.sh')), str(root)]
            if operation == 'restore':
                command += [str(archive), str(root / 'restore'), str(defaults), 'synthetic_restore']
            environment = dict(os.environ, PATH=str(tools) + os.pathsep + os.environ['PATH'],
                               PHP_BIN=php, MARIADB_DUMP_BIN=dump, TRACE=str(trace), WAITING=str(waiting))
            with (private / 'operations.lock').open('w') as lock:
                fcntl.flock(lock, fcntl.LOCK_EX)
                process = subprocess.Popen(command, env=environment, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                           start_new_session=True)
                try:
                    deadline = time.monotonic() + 5
                    while not waiting.exists() and process.poll() is None and time.monotonic() < deadline:
                        time.sleep(0.01)
                    self.assertTrue(waiting.exists(), 'Operation did not reach the protected boundary')
                    self.assertIsNone(process.poll(), 'Operation must wait until deployment releases the lock')
                    self.assertFalse(trace.exists(), 'No version-dependent command may execute before the lock')
                    replacement = root / 'next'
                    replacement.symlink_to(root / 'releases/after', target_is_directory=True)
                    replacement.replace(current)
                    if remove_previous:
                        shutil.rmtree(previous)
                    fcntl.flock(lock, fcntl.LOCK_UN)
                    stdout, stderr = process.communicate(timeout=10)
                    self.assertEqual(0, process.returncode, stderr.decode())
                finally:
                    fcntl.flock(lock, fcntl.LOCK_UN)
                    if process.poll() is None:
                        os.killpg(process.pid, signal.SIGKILL)
                    process.communicate(timeout=5)
            paths = trace.read_text().splitlines()
            self.assertTrue(paths)
            self.assertTrue(all('/releases/after/' in path for path in paths), paths)
            if operation == 'backup':
                with zipfile.ZipFile(io.BytesIO(stdout)) as backup:
                    self.assertEqual('after', json.loads(backup.read('manifest.json'))['release']['version'])
            else:
                self.assertTrue(paths[-1].endswith('/after/scripts/restore-backup.py'))

    def test_backup_resolves_release_after_waiting(self):
        self.exercise_switch('backup', False)

    def test_backup_succeeds_when_previous_release_disappears_while_waiting(self):
        self.exercise_switch('backup', True)

    def test_restore_chooses_scripts_from_the_newly_current_release(self):
        self.exercise_switch('restore', False)

    def test_restore_succeeds_when_previous_release_disappears_while_waiting(self):
        self.exercise_switch('restore', True)


if __name__ == '__main__':
    unittest.main()
