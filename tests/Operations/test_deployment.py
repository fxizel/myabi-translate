"""Exercise the native deployment boundary with a synthetic release and fake services."""
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import unittest

try:
    import grp
except ImportError:  # Windows can still execute the portable artifact tests.
    grp = None


SCRIPTS = Path(__file__).resolve().parents[2] / 'scripts'
LINUX_TOOLS = all(shutil.which(tool) for tool in ['bash', 'flock', 'setfacl', 'getfacl', 'python3'])


@unittest.skipUnless(sys.platform.startswith('linux') and LINUX_TOOLS, 'Linux deployment utilities required')
class NativeDeploymentTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'application'
        self.shared = self.root / 'shared'
        self.shared.mkdir(parents=True)
        (self.root / 'incoming').mkdir()
        self.trace = self.root / 'trace'
        self.tools = self.root / 'tools'
        self.tools.mkdir()
        self.commit = 'a' * 40
        self.candidate = self.root / 'releases' / self.commit
        quota = {'database_bytes': 0, 'external_bytes': 0, 'quota': 10**12, 'url': 'https://synthetic.invalid'}
        (self.shared / 'initial-quota.json').write_text(json.dumps(quota))
        for name in ['.env', 'migration.env', 'backup.cnf']:
            (self.shared / name).write_text('')
            (self.shared / name).chmod(0o600)
        for name, event in [('backup-hook.sh', 'backup'), ('post-migrate-hook.sh', 'grants')]:
            self.executable(self.shared / name, '#!/usr/bin/env bash\necho ' + event + ' >> "$TRACE"\n' +
                            ('test "${FAIL_GRANTS:-0}" != 1\n' if event == 'grants' else ''))
        self.executable(self.tools / 'php', '''#!/usr/bin/env python3
import json, os, pathlib, sys
args = sys.argv[1:]
if args[0] == '-r':
    print('b' * 48 if 'random_bytes' in args[1] else json.loads(args[2])['url'])
    sys.exit(0)
event = args[1]
with open(os.environ['TRACE'], 'a') as trace: trace.write(event + '\\n')
down = pathlib.Path(os.environ['ROOT']) / 'shared/storage/framework/down'
if event == 'down': down.write_text('maintenance')
if event == 'up': down.unlink(missing_ok=True)
''')
        self.executable(self.tools / 'curl', '''#!/usr/bin/env bash
echo curl >> "$TRACE"
if [[ "$*" != *--fail* && "${FAIL_TLS:-0}" == 1 ]]; then exit 60; fi
''')
        stage = self.root / 'fixture'
        for directory in ['vendor', 'public', 'scripts', 'bootstrap/cache', 'database/migrations']:
            (stage / directory).mkdir(parents=True, exist_ok=True)
        for name in ['artisan', 'composer.lock', 'vendor/autoload.php', 'public/index.php', 'scripts/operations.php']:
            (stage / name).write_text('synthetic fixture')
        shutil.copyfile(SCRIPTS / 'inspect-release.py', stage / 'scripts/inspect-release.py')
        (stage / 'RELEASE.json').write_text(json.dumps({'commit': self.commit}))
        self.archive = self.root / 'incoming/release.tar.gz'
        with tarfile.open(self.archive, 'w:gz') as archive:
            archive.add(stage, arcname='.')
        self.env = dict(os.environ, PATH=str(self.tools) + os.pathsep + os.environ['PATH'],
                        PHP_BIN=str(self.tools / 'php'), DEPLOY_GROUP=grp.getgrgid(os.getgid()).gr_name,
                        DEPLOY_PUBLIC_GROUP='', ROOT=str(self.root), TRACE=str(self.trace))

    def executable(self, path, content):
        path.write_text(content)
        path.chmod(0o700)

    def deploy(self, **environment):
        return subprocess.run(['bash', str(SCRIPTS / 'deploy.sh'), str(self.root), str(self.archive)],
                              env=dict(self.env, **environment), capture_output=True, text=True)

    def mode(self, path):
        return stat.S_IMODE(path.stat().st_mode)

    def test_shared_runtime_keeps_code_and_operational_secrets_protected(self):
        lock = self.shared / 'storage/app/private/operations.lock'
        lock.parent.mkdir(parents=True)
        lock.write_text('')
        inode = lock.stat().st_ino
        result = self.deploy()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(self.candidate, (self.root / 'current').resolve())
        self.assertEqual(inode, lock.stat().st_ino)
        self.assertEqual(0o660, self.mode(lock))
        self.assertEqual(0o640, self.mode(self.candidate / 'artisan'))
        self.assertEqual(0o2770, self.mode(self.candidate / 'bootstrap/cache'))
        self.assertEqual(0o640, self.mode(self.shared / '.env'))
        for name in ['migration.env', 'backup.cnf']:
            self.assertEqual(0o600, self.mode(self.shared / name))
        for name in ['backup-hook.sh', 'post-migrate-hook.sh']:
            self.assertEqual(0o700, self.mode(self.shared / name))
        events = self.trace.read_text().splitlines()
        self.assertLess(events.index('backup'), events.index('migrate'))
        self.assertLess(events.index('migrate'), events.index('grants'))
        self.assertLess(events.index('grants'), events.index('health'))
        self.assertFalse((self.shared / 'storage/framework/down').exists())
        self.assertFalse(self.archive.exists())

    def test_tls_bootstrap_failure_does_not_enter_maintenance(self):
        result = self.deploy(FAIL_TLS='1')
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(['curl'], self.trace.read_text().splitlines())
        self.assertFalse((self.shared / 'storage/framework/down').exists())
        self.assertFalse((self.root / 'current').exists())
        self.assertTrue(self.archive.exists())
        self.assertEqual([], list((self.root / 'incoming').glob('check.*')))

    def test_public_group_reads_static_assets_without_private_configuration(self):
        try:
            group = grp.getgrnam('www-data')
        except KeyError:
            self.skipTest('The web-server group is not installed')
        if group.gr_gid == os.getgid():
            self.skipTest('The public group must differ from the deploy user group')
        result = self.deploy(DEPLOY_PUBLIC_GROUP=group.gr_name)
        self.assertEqual(0, result.returncode, result.stderr)
        def acl(path):
            return subprocess.check_output(['getfacl', '-cp', str(path)], text=True)
        self.assertIn('group:www-data:--x', acl(self.root))
        self.assertIn('group:www-data:r--', acl(self.candidate / 'public/index.php'))
        self.assertNotIn('group:www-data:', acl(self.shared / '.env'))
        self.assertNotIn('group:www-data:', acl(self.shared / 'backup.cnf'))

    @unittest.skipUnless(hasattr(os, 'geteuid') and os.geteuid() == 0 and shutil.which('runuser'),
                         'A disposable Linux root environment is required to impersonate FPM')
    def test_distinct_web_user_can_share_runtime_but_cannot_read_deployment_secrets(self):
        result = self.deploy()
        self.assertEqual(0, result.returncode, result.stderr)
        Path(self.temp.name).chmod(0o711)
        # This changes only the synthetic child process identity; no system users are created.
        result = subprocess.run(['runuser', '-u', 'nobody', '-g', self.env['DEPLOY_GROUP'], '--',
                                 'bash', '-c', '''
set -e
test -r "$1/current/artisan"
test ! -w "$1/current/artisan"
test -r "$1/shared/.env"
test ! -w "$1/shared/.env"
for name in migration.env backup.cnf backup-hook.sh post-migrate-hook.sh; do
    test ! -r "$1/shared/$name"
done
test -w "$1/current/bootstrap/cache"
printf synthetic > "$1/shared/storage/app/private/from-web"
exec 9<> "$1/shared/storage/app/private/operations.lock"
flock -n -x 9
''', 'fixture', str(self.root)], capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(0o660, self.mode(self.shared / 'storage/app/private/from-web'))
        self.assertEqual('synthetic', (self.shared / 'storage/app/private/from-web').read_text())

    def test_failed_final_grants_keep_maintenance_and_never_activate(self):
        result = self.deploy(FAIL_GRANTS='1')
        self.assertNotEqual(0, result.returncode)
        self.assertTrue((self.shared / 'storage/framework/down').exists())
        self.assertFalse((self.root / 'current').exists())
        self.assertNotIn('health', self.trace.read_text().splitlines())
        self.assertTrue(self.archive.exists())

    def test_existing_maintenance_is_never_overridden(self):
        down = self.shared / 'storage/framework/down'
        down.parent.mkdir(parents=True)
        down.write_text('existing maintenance')
        result = self.deploy()
        self.assertNotEqual(0, result.returncode)
        self.assertEqual('existing maintenance', down.read_text())
        self.assertFalse(self.trace.exists())

    def test_legacy_single_user_profile_remains_available(self):
        result = self.deploy(DEPLOY_GROUP='')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(0o600, self.mode(self.shared / '.env'))
        self.assertEqual(0o600, self.mode(self.shared / 'storage/app/private/operations.lock'))


if __name__ == '__main__':
    unittest.main()
