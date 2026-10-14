"""Portable deployment safety tests: synthetic data and no real subprocesses."""
import contextlib
import copy
import gzip
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile


ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / 'scripts'
OLD = 'a' * 40
TARGET = 'b' * 40
OLD_TAG = 'sha-' + OLD[:12]
SECRET = 'SYNTHETIC_DB_PASSWORD_DO_NOT_PRINT'


def load_script(filename, name):
    paths = [SCRIPTS / filename, Path(__file__).parent / filename]
    path = next((item for item in paths if item.is_file()), paths[0])
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module  # dataclasses resolve annotations through this registry.
    spec.loader.exec_module(module)
    return module


def backup_bytes(corrupt=False, previous_commit=OLD, previous_tag=OLD_TAG):
    """Recreate the public backup format independently of the deployment code."""
    members = {
        'database.sql': b'-- synthetic SQL\r\nCREATE TABLE fixture(id INT);\r\n',
        'private/synthetic.devconf': b'key;value\r\nSYNTHETIC;"bytes \xff\x00"\r\n',
    }
    manifest = {
        'format': 1,
        'created_at': '2026-01-01T00:00:00+00:00',
        'database': 'synthetic_test',
        'release': {'commit': previous_commit, 'version': previous_tag},
        'files': {
            name: {'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()}
            for name, data in members.items()
        },
    }
    output = io.BytesIO()
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
        for name, data in members.items():
            # ZIP CRC remains valid: the manifest checksum must detect corruption.
            archive.writestr(name, b'changed SQL' if corrupt and name == 'database.sql' else data)
        archive.writestr('manifest.json', json.dumps(manifest))
    return output.getvalue()


class FakeRunner:
    def __init__(self):
        self.calls = []
        self.dirty = ''
        self.invalid_ref = False
        self.changed_path = None
        self.head_revision = TARGET

    def capture(self, args, cwd=None):
        args = list(map(str, args))
        self.calls.append(('capture', args, cwd))
        if args[:3] == ['git', 'status', '--porcelain']:
            return self.dirty
        if args[:3] == ['git', 'rev-parse', '--verify']:
            if self.invalid_ref:
                raise subprocess.CalledProcessError(128, args)
            return self.head_revision if args[3] == 'HEAD^{commit}' else TARGET
        if args[:3] == ['git', 'cat-file', '-e']:
            return ''
        if args[:3] == ['git', 'ls-tree', '-r']:
            commit = args[3]
            files = [
                'database/migrations/2026_01_01_000000_fixture.php',
                'deploy/docker/Dockerfile',
                'app/Services/BrowserExtension/SearchProjection.php',
                'app/Services/BrowserExtension/TextNormalizer.php',
            ]
            return '\n'.join(
                '100644 blob ' + ('e' * 40 if commit == TARGET and path == self.changed_path else 'd' * 40) + '\t' + path
                for path in files
            )
        raise AssertionError('Unexpected process request in isolated test: ' + repr(args))

    def run(self, args, cwd=None, log_path=None):
        args = list(map(str, args))
        self.calls.append(('run', args, cwd))
        if args[:3] == ['git', 'cat-file', '-e']:
            return
        raise AssertionError('Unexpected process request in isolated test: ' + repr(args))


class FakeTransport:
    def __init__(self, trace):
        self.trace = trace
        self.calls = []
        self.sent_files = []
        self.current = {
            'revision': OLD, 'tag': OLD_TAG, 'healthy': True,
            'maintenance': False, 'lease': None, 'available_bytes': 10 * 1024**3,
        }
        self.missing_refs = None
        self.corrupt_backup = False
        self.fail_backup = False
        self.fail_action = None
        self.backup_release_override = {}
        self.finalize_reply_lost = False
        self.completed = False

    def call(self, action, release=None, params=None):
        self.trace.append(action)
        self.calls.append((action, copy.deepcopy(release), copy.deepcopy(params)))
        if action == 'stage':
            self.current['lease'] = release['run_id']
        if action == self.fail_action:
            raise RuntimeError('Child command failed with password=' + SECRET)
        if action == 'status':
            return copy.deepcopy(self.current)
        if action == 'image-status':
            refs = self.missing_refs
            if refs is None:
                refs = ['myabi-translate-app:sha-' + TARGET[:12], 'myabi-translate-web:sha-' + TARGET[:12]]
            return {'missing_refs': list(refs)}
        if action == 'freeze':
            self.current['maintenance'] = True
        if action == 'switch':
            self.current['revision'] = TARGET
            self.current['tag'] = 'sha-' + TARGET[:12]
        if action == 'finalize':
            self.current['maintenance'] = False
            self.current['lease'] = None
            self.completed = True
            if self.finalize_reply_lost:
                raise RuntimeError('Final reply lost with password=' + SECRET)
        if action.startswith('recover'):
            if self.completed:
                return {'phase': 'complete', 'revision': TARGET}
            self.current.update(revision=OLD, tag=OLD_TAG, maintenance=False, lease=None)
        return {}

    def send_file(self, local_path, remote_path):
        self.trace.append('send-file')
        self.sent_files.append((Path(local_path), str(remote_path)))

    def backup(self, release, target):
        self.trace.append('backup')
        target = Path(target)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(backup_bytes(corrupt=self.corrupt_backup, **self.backup_release_override))
        if self.fail_backup:
            # A readable archive does not turn failed SSH transport into success.
            raise RuntimeError('Backup SSH failed with password=' + SECRET)


class VpsDeploymentTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        with contextlib.ExitStack() as guards:
            for name in ['run', 'Popen', 'check_output', 'check_call']:
                guards.enter_context(patch('subprocess.' + name, side_effect=AssertionError('Real subprocess forbidden during import')))
            cls.client = load_script('deploy-vps.py', 'myabi_test_deploy_vps')
            cls.backup_verifier = load_script('verify-backup.py', 'myabi_test_verify_vps_backup')

    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='myabi deploy test ')
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.repo = self.root / 'source avec espaces'
        self.repo.mkdir()
        (self.repo / '.git').mkdir()
        self.key = self.root / 'synthetic key'
        self.key.write_text('SYNTHETIC PRIVATE KEY FIXTURE ONLY', encoding='utf-8')
        self.raw_config = {
            'ssh_host': 'vps.example.org', 'ssh_user': 'deploy',
            'ssh_key': str(self.key), 'remote_root': '/opt/myabi',
            'site_url': 'https://myabi.example.org',
        }
        self.trace = []
        self.runner = FakeRunner()
        self.transport = FakeTransport(self.trace)
        self.builds = []
        self.archived = []
        self.fail_tests = False
        self.change_during_build = None
        # The injected fakes must be honoured. Any accidental real process fails closed.
        for name in ['run', 'Popen', 'check_output', 'check_call']:
            guard = patch('subprocess.' + name, side_effect=AssertionError('Real subprocess forbidden in deployment test'))
            guard.start()
            self.addCleanup(guard.stop)

    def deployment(self):
        outer = self

        class IsolatedDeployment(self.client.Deployment):
            def _tests(self, revision):
                outer.trace.append('tests')
                if outer.fail_tests:
                    raise outer.client.DeploymentError('Synthetic validation failed.')

            def _build(self, revision, tag):
                outer.trace.append('build')
                outer.builds.append((revision, tag))
                if outer.change_during_build == 'dirty':
                    outer.runner.dirty = ' M public/js/app.js\n'
                if outer.change_during_build == 'head':
                    outer.runner.head_revision = OLD
                return [
                    {'service': 'app', 'ref': 'myabi-translate-app:' + tag, 'id': 'sha256:' + '1' * 64},
                    {'service': 'myabi-web', 'ref': 'myabi-translate-web:' + tag, 'id': 'sha256:' + '2' * 64},
                ]

            def _archive_images(self, missing):
                outer.trace.append('archive-images')
                outer.archived.append(copy.deepcopy(missing))
                archive = outer.root / 'missing images.tar.gz'
                archive.write_bytes(b'synthetic image stream')
                return archive

        config = self.client.validate_config(self.raw_config, base_dir=self.root)
        return IsolatedDeployment(self.repo, config, runner=self.runner,
                                  transport=self.transport, verifier=self.backup_verifier.verify)

    def public_failure(self, deployment, **kwargs):
        config_path = self.root / 'deployment config.json'
        config_path.write_text(json.dumps(self.raw_config), encoding='utf-8')
        arguments = ['--config', str(config_path)]
        if 'ref' in kwargs:
            arguments.extend(['--ref', kwargs['ref']])
        if kwargs.get('plan'):
            arguments.append('--plan')
        stdout, stderr = io.StringIO(), io.StringIO()
        with patch.object(self.client, 'Deployment', return_value=deployment), \
                contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
            status = self.client.main(arguments)
        self.assertNotEqual(0, status)
        public = stdout.getvalue() + stderr.getvalue()
        self.assertNotIn(SECRET, public)
        return public

    def run_success(self, deployment, **kwargs):
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            return deployment.run(**kwargs)

    def validation_deployment(self, fail=False, **config_overrides):
        observations = []

        class ValidationRunner(FakeRunner):
            def run(self, args, cwd=None, log_path=None):
                if args != ['synthetic-myabi-check-no-executable']:
                    return super().run(args, cwd=cwd, log_path=log_path)
                observations.append({key: os.environ.get(key) for key in
                                     ['APP_ENV', 'MAIL_MAILER', 'DB_DATABASE', 'MYABI_ISOLATED_TESTS', 'APP_CONFIG_CACHE']})
                observations[-1]['env_exists'] = (Path(cwd) / '.env').is_file()
                if fail:
                    raise error_type('Synthetic validation failed.')

        error_type = self.client.DeploymentError
        config = dict(self.raw_config, test_commands=[['synthetic-myabi-check-no-executable']])
        config.update(config_overrides)
        command = self.client.Deployment(self.repo, config, runner=ValidationRunner(),
                                         transport=self.transport, verifier=self.backup_verifier.verify)
        return command, observations

    def archive_deployment(self):
        error_type = self.client.DeploymentError

        class ImageRunner(FakeRunner):
            def __init__(self):
                super().__init__()
                self.image_id = 'sha256:' + '4' * 64
                self.payload = b'SYNTHETIC TAR IMAGE A\x00\xff\r\n'
                self.saves = []
                self.fail_save = False

            def capture(self, args, cwd=None):
                if list(args)[:3] == ['docker', 'image', 'inspect']:
                    return json.dumps([{'Id': self.image_id}])
                return super().capture(args, cwd=cwd)

            def run(self, args, cwd=None, log_path=None):
                if list(args)[:3] != ['docker', 'image', 'save']:
                    return super().run(args, cwd=cwd, log_path=log_path)
                self.saves.append(list(args))
                Path(args[args.index('--output') + 1]).write_bytes(self.payload)
                if self.fail_save:
                    raise error_type('Synthetic image export failed.')

        runner = ImageRunner()
        command = self.client.Deployment(self.repo, self.raw_config, runner=runner,
                                         transport=self.transport, verifier=self.backup_verifier.verify)
        return command, runner

    def test_configuration_rejects_unsafe_target_without_processes(self):
        cases = [
            ('ssh_host', ''), ('ssh_host', '-oProxyCommand=evil'),
            ('ssh_host', 'vps.example.org;command'), ('ssh_host', 'vps.example.org\nother'),
            ('ssh_user', '-root'), ('remote_root', '/'), ('remote_root', 'relative/path'),
            ('remote_root', '/opt/myabi/../../etc'), ('remote_root', '/opt/myabi\nother'),
            ('site_url', 'http://myabi.example.org'),
            ('site_url', 'https://user:password@myabi.example.org'),
            ('site_url', 'https://myabi.example.org/?token=secret'),
            ('site_url', 'https://myabi.example.org/#fragment'),
        ]
        for field, value in cases:
            with self.subTest(field=field, value=value):
                config = dict(self.raw_config, **{field: value})
                with self.assertRaises(self.client.DeploymentError):
                    self.client.validate_config(config, base_dir=self.root)
        self.assertEqual([], self.runner.calls)
        self.assertEqual([], self.transport.calls)

    def test_configuration_resolves_existing_key_relative_to_config_directory(self):
        config = dict(self.raw_config, ssh_key=self.key.name)
        validated = self.client.validate_config(config, base_dir=self.root)
        self.assertEqual(self.key.resolve(), Path(validated['ssh_key']))

    def test_missing_private_key_is_rejected_before_transport(self):
        with self.assertRaises(self.client.DeploymentError):
            self.client.validate_config(dict(self.raw_config, ssh_key='missing-key'), base_dir=self.root)
        self.assertEqual([], self.transport.calls)

    def test_configuration_limits_timers_and_operation_timeout(self):
        cases = [
            {'timer_names': ['other-project.timer']},
            {'timer_names': ['myabi-docker-work.timer', 'myabi-docker-work.timer']},
            {'timer_names': [42]}, {'timer_names': []},
            {'operations_timeout_seconds': 7201}, {'operations_timeout_seconds': 0},
            {'operations_timeout_seconds': True},
        ]
        for changes in cases:
            with self.subTest(changes=changes), self.assertRaises(self.client.DeploymentError):
                self.client.validate_config(dict(self.raw_config, **changes), base_dir=self.root)
        valid = self.client.validate_config(dict(self.raw_config, operations_timeout_seconds=7200), base_dir=self.root)
        self.assertEqual(7200, valid['operations_timeout_seconds'])

    def test_external_original_directory_is_resolved_and_missing_directory_refused(self):
        external = self.root / 'external originals'
        external.mkdir()
        valid = self.client.validate_config(dict(self.raw_config, originals_dir=external.name), base_dir=self.root)
        self.assertEqual(external.resolve(), Path(valid['originals_dir']))
        for value in ['missing-directory', '', 42]:
            with self.subTest(value=value), self.assertRaises(self.client.DeploymentError):
                self.client.validate_config(dict(self.raw_config, originals_dir=value), base_dir=self.root)

    def test_dirty_checkout_is_rejected_before_any_remote_contact(self):
        for dirty in [' M app/changed.php\n', 'M  app/staged.php\n', '?? untracked.txt\n']:
            with self.subTest(status=dirty):
                self.runner.dirty = dirty
                self.public_failure(self.deployment())
                self.assertEqual([], self.transport.calls)
                self.assertEqual([], self.builds)

    def test_unknown_ref_is_rejected_before_remote_contact(self):
        self.runner.invalid_ref = True
        self.public_failure(self.deployment(), ref='missing-ref')
        self.assertEqual([], self.transport.calls)
        self.assertEqual([], self.builds)

    def test_requested_ref_is_resolved_and_built_as_that_commit(self):
        result = self.run_success(self.deployment(), ref='release-candidate')
        self.assertEqual('deployed', result['status'])
        references = [args[3] for kind, args, _ in self.runner.calls
                      if kind == 'capture' and args[:3] == ['git', 'rev-parse', '--verify']]
        self.assertIn('release-candidate^{commit}', references)
        self.assertEqual([(TARGET, 'sha-' + TARGET[:12])], self.builds)

    def test_current_healthy_commit_is_noop_without_build_transfer_or_freeze(self):
        self.transport.current.update(revision=TARGET, tag='sha-' + TARGET[:12])
        result = self.run_success(self.deployment())
        self.assertEqual('noop', result['status'])
        self.assertEqual([], self.builds)
        self.assertEqual([], self.transport.sent_files)
        self.assertEqual(['status'], self.trace)

    def test_different_checkout_cannot_be_certified_as_requested_commit(self):
        self.runner.head_revision = OLD
        self.public_failure(self.deployment(), ref='release-candidate')
        self.assertEqual([], self.builds)
        self.assertNotIn('tests', self.trace)
        self.assertNotIn('stage', self.trace)

    def test_local_validation_failure_never_stages_or_enters_maintenance(self):
        self.fail_tests = True
        self.public_failure(self.deployment())
        self.assertIn('tests', self.trace)
        self.assertNotIn('build', self.trace)
        self.assertNotIn('stage', self.trace)
        self.assertNotIn('freeze', self.trace)

    def test_checkout_change_during_build_prevents_staging(self):
        for change in ['dirty', 'head']:
            with self.subTest(change=change):
                self.trace.clear()
                self.runner.dirty = ''
                self.runner.head_revision = TARGET
                self.change_during_build = change
                self.public_failure(self.deployment())
                self.assertIn('build', self.trace)
                self.assertNotIn('stage', self.trace)
                self.assertNotIn('freeze', self.trace)
                self.assertNotIn('switch', self.trace)

    def test_plan_for_other_checkout_remains_readonly(self):
        self.runner.head_revision = OLD
        result = self.run_success(self.deployment(), ref='release-candidate', plan=True)
        self.assertEqual('plan', result['status'])
        self.assertEqual(['status'], self.trace)

    def test_insufficient_disk_margin_prevents_validation_and_maintenance(self):
        self.transport.current['available_bytes'] = 0
        self.public_failure(self.deployment())
        self.assertEqual(['status'], self.trace)
        self.assertFalse(self.builds)

    def test_already_active_commit_with_foreign_lease_is_not_noop(self):
        self.transport.current.update(revision=TARGET, tag='sha-' + TARGET[:12], lease='c' * 32)
        self.public_failure(self.deployment())
        self.assertEqual(['status'], self.trace)
        self.assertEqual('c' * 32, self.transport.current['lease'])

    def test_dirty_plan_remains_readonly(self):
        self.runner.dirty = ' M app/changed.php\n'
        result = self.run_success(self.deployment(), plan=True)
        self.assertEqual('plan', result['status'])
        self.assertTrue(result['dirty'])
        self.assertFalse(self.builds)
        self.assertFalse(self.transport.sent_files)
        self.assertTrue(set(self.trace) <= {'status'})

    def test_migration_or_other_critical_changes_fail_before_maintenance(self):
        for path in ['database/migrations/2026_01_01_000000_fixture.php',
                     'deploy/docker/Dockerfile',
                     'app/Services/BrowserExtension/TextNormalizer.php']:
            with self.subTest(path=path):
                self.trace.clear()
                self.transport.calls.clear()
                self.runner.changed_path = path
                self.public_failure(self.deployment())
                self.assertNotIn('freeze', self.trace)
                self.assertNotIn('switch', self.trace)
                self.assertNotIn('stage', self.trace)
                self.assertFalse(self.transport.current['maintenance'])

    def test_only_missing_image_is_archived_and_transferred(self):
        missing_ref = 'myabi-translate-app:sha-' + TARGET[:12]
        self.transport.missing_refs = [missing_ref]
        result = self.run_success(self.deployment())
        self.assertEqual('deployed', result['status'])
        self.assertEqual(1, len(self.archived))
        refs = [item['ref'] if isinstance(item, dict) else item for item in self.archived[0]]
        self.assertEqual([missing_ref], refs)
        self.assertEqual(1, len(self.transport.sent_files))
        self.assertIn('load', self.trace)

    def test_existing_images_require_no_archive_or_image_transfer(self):
        self.transport.missing_refs = []
        result = self.run_success(self.deployment())
        self.assertEqual('deployed', result['status'])
        self.assertEqual([], self.archived)
        self.assertEqual([], self.transport.sent_files)

    def test_unrelated_missing_image_is_rejected_before_staging(self):
        self.transport.missing_refs = ['other-project:private-tag']
        self.public_failure(self.deployment())
        self.assertNotIn('stage', self.trace)
        self.assertNotIn('freeze', self.trace)
        self.assertEqual([], self.transport.sent_files)

    def test_corrupt_backup_never_verifies_or_switches(self):
        self.transport.corrupt_backup = True
        self.public_failure(self.deployment())
        self.assertIn('backup', self.trace)
        self.assertNotIn('backup-verified', self.trace)
        self.assertNotIn('switch', self.trace)
        self.assertNotIn('finalize', self.trace)
        self.assertEqual(OLD, self.transport.current['revision'])
        self.assertFalse(self.transport.current['maintenance'])
        self.assertIn('recover', self.trace)

    def test_failed_backup_transport_cannot_switch_even_after_writing_valid_zip(self):
        self.transport.fail_backup = True
        self.public_failure(self.deployment())
        self.assertIn('backup', self.trace)
        self.assertNotIn('backup-verified', self.trace)
        self.assertNotIn('switch', self.trace)
        self.assertNotIn('finalize', self.trace)
        self.assertFalse(self.transport.current['maintenance'])
        self.assertIn('recover', self.trace)

    def test_valid_backup_for_different_active_release_never_switches(self):
        for metadata in [{'previous_commit': 'c' * 40}, {'previous_tag': 'sha-other-version'}]:
            with self.subTest(metadata=metadata):
                self.trace.clear()
                self.transport.backup_release_override = metadata
                command = self.deployment()
                self.public_failure(command)
                self.assertNotIn('backup-verified', self.trace)
                self.assertNotIn('switch', self.trace)
                self.assertIn('recover', self.trace)
                archive = sorted((command.cache / 'backups').glob('*.partial.zip'))[-1]
                self.assertEqual(1, self.backup_verifier.verify(archive)['format'])
                self.assertEqual(OLD, self.transport.current['revision'])

    def test_lost_stage_response_is_redacted_and_recovers_owned_run(self):
        self.transport.fail_action = 'stage'
        self.public_failure(self.deployment())
        self.assertNotIn('freeze', self.trace)
        self.assertNotIn('switch', self.trace)
        self.assertNotIn('finalize', self.trace)
        self.assertIn('recover', self.trace)
        self.assertIsNone(self.transport.current['lease'])

    def test_success_verifies_backup_before_switch_and_finalization(self):
        result = self.run_success(self.deployment())
        self.assertEqual('deployed', result['status'])
        self.assertLess(self.trace.index('backup'), self.trace.index('backup-verified'))
        self.assertLess(self.trace.index('backup-verified'), self.trace.index('switch'))
        self.assertLess(self.trace.index('switch'), self.trace.index('finalize'))
        verified = next(params for action, _, params in self.transport.calls if action == 'backup-verified')
        self.assertEqual(64, len(verified['sha256']))
        self.assertGreater(verified['bytes'], 0)
        self.assertEqual(TARGET, self.transport.current['revision'])
        self.assertFalse(self.transport.current['maintenance'])

    def test_lost_finalize_reply_recovers_completed_success_without_rollback(self):
        self.transport.finalize_reply_lost = True
        stdout, stderr = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
            result = self.deployment().run()
        self.assertEqual('deployed', result['status'])
        self.assertTrue(result['completion_recovered'])
        self.assertEqual(TARGET, self.transport.current['revision'])
        self.assertFalse(self.transport.current['maintenance'])
        self.assertIn('recover', self.trace)
        self.assertNotIn('version précédente', stdout.getvalue())
        self.assertNotIn(SECRET, stdout.getvalue() + stderr.getvalue())

    def real_transport(self):
        remote_script = self.root / 'synthetic remote.py'
        remote_script.write_text('def emit(payload): pass\n', encoding='utf-8')
        config = self.client.validate_config(self.raw_config, base_dir=self.root)
        return self.client.Transport(config, remote_script, self.root / 'private logs')

    def test_remote_error_response_never_exposes_secret_text(self):
        transport = self.real_transport()
        response = subprocess.CompletedProcess([], 1, stdout=json.dumps({'ok': False, 'error': SECRET}).encode())
        with patch.object(self.client.subprocess, 'run', return_value=response) as command:
            with self.assertRaises(self.client.DeploymentError) as error:
                transport.call('status')
        self.assertNotIn(SECRET, str(error.exception))
        self.assertIn('StrictHostKeyChecking=yes', command.call_args.args[0])
        self.assertIsInstance(command.call_args.kwargs['input'], bytes)

    def test_real_backup_transport_rejects_failed_ssh_after_valid_zip_stream(self):
        target = self.root / 'failed but valid backup.zip'

        def failed_stream(args, **kwargs):
            kwargs['stdout'].write(backup_bytes())
            return subprocess.CompletedProcess(args, 23)

        with patch.object(self.client.subprocess, 'run', side_effect=failed_stream):
            with self.assertRaises(self.client.DeploymentError):
                self.real_transport().backup({'run_id': 'c' * 32}, target)
        self.assertEqual(1, self.backup_verifier.verify(target)['format'])

    def test_transfer_rejects_destination_outside_owned_release_before_ssh(self):
        transport = self.real_transport()
        source = self.root / 'synthetic images.tar.gz'
        source.write_bytes(b'fixture')
        for destination in ['/etc/images.tar.gz', '/opt/other/operations/deploy-x/images.tar.gz',
                            '/opt/myabi/operations/deploy-x/../../images.tar.gz']:
            with self.subTest(destination=destination), patch.object(self.client.subprocess, 'run') as command:
                with self.assertRaises(self.client.DeploymentError):
                    transport.send_file(source, destination)
                command.assert_not_called()

    def test_validation_reuses_cache_and_changed_original_invalidates_it(self):
        imports = self.repo / 'data/imports'
        imports.mkdir(parents=True)
        original = imports / 'synthetic.csv'
        original.write_bytes(b'key;value\r\nTEST;one\r\n')
        command, observations = self.validation_deployment()
        with contextlib.redirect_stdout(io.StringIO()):
            command._tests(TARGET)
            command._tests(TARGET)
        self.assertEqual(1, len(observations))
        original.write_bytes(b'key;value\r\nTEST;two\r\n')
        with contextlib.redirect_stdout(io.StringIO()):
            command._tests(TARGET)
        self.assertEqual(2, len(observations))
        self.assertFalse((self.repo / '.env').exists())

    def test_failed_validation_removes_created_env_restores_process_env_and_is_not_cached(self):
        before = {'APP_ENV': 'fixture-before', 'MAIL_MAILER': 'fixture-mail',
                  'DB_DATABASE': 'fixture-db', 'MYABI_ISOLATED_TESTS': '1',
                  'APP_CONFIG_CACHE': 'synthetic-original-cache.php'}
        command, observations = self.validation_deployment(fail=True)
        with patch.dict(os.environ, before), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(self.client.DeploymentError):
                command._tests(TARGET)
            for key, value in before.items():
                self.assertEqual(value, os.environ.get(key))
        self.assertTrue(observations[0]['env_exists'])
        self.assertEqual('testing', observations[0]['APP_ENV'])
        self.assertEqual('array', observations[0]['MAIL_MAILER'])
        self.assertEqual(':memory:', observations[0]['DB_DATABASE'])
        self.assertIsNone(observations[0]['MYABI_ISOLATED_TESTS'])
        self.assertFalse((self.repo / '.env').exists())
        self.assertEqual([], list((command.cache / 'tests').glob('*.json')))

    def test_failed_validation_never_replaces_preexisting_env(self):
        env_file = self.repo / '.env'
        existing = b'APP_ENV=fixture\r\nDB_PASSWORD=SYNTHETIC_EXISTING_VALUE\r\n'
        env_file.write_bytes(existing)
        command, _ = self.validation_deployment(fail=True)
        with contextlib.redirect_stdout(io.StringIO()), self.assertRaises(self.client.DeploymentError):
            command._tests(TARGET)
        self.assertEqual(existing, env_file.read_bytes())

    def test_changed_env_vendor_metadata_or_config_cache_invalidates_validation_cache(self):
        command, observations = self.validation_deployment()
        for relative in ['.env', 'vendor/composer/installed.json', 'bootstrap/cache/config.php']:
            with self.subTest(relative=relative):
                path = self.repo / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(b'SYNTHETIC INPUT A')
                with contextlib.redirect_stdout(io.StringIO()):
                    command._tests(TARGET)
                    command._tests(TARGET)
                before = len(observations)
                path.write_bytes(b'SYNTHETIC INPUT B')
                with contextlib.redirect_stdout(io.StringIO()):
                    command._tests(TARGET)
                self.assertEqual(before + 1, len(observations))
                self.assertEqual(b'SYNTHETIC INPUT B', path.read_bytes())

    def test_changed_external_csv_invalidates_cache_without_touching_external_files(self):
        external = self.root / 'external originals'
        external.mkdir()
        original = external / 'fixture.csv'
        original.write_bytes(b'key;value\r\nSYNTHETIC;one\r\n')
        command, observations = self.validation_deployment(originals_dir=str(external))
        with contextlib.redirect_stdout(io.StringIO()):
            command._tests(TARGET)
            command._tests(TARGET)
        self.assertEqual(1, len(observations))
        original.write_bytes(b'key;value\r\nSYNTHETIC;two\r\n')
        changed = original.read_bytes()
        with contextlib.redirect_stdout(io.StringIO()):
            command._tests(TARGET)
        self.assertEqual(2, len(observations))
        self.assertEqual(changed, original.read_bytes())

    def test_validation_isolates_compiled_config_and_preserves_original_cache(self):
        cached = self.repo / 'bootstrap/cache/config.php'
        cached.parent.mkdir(parents=True)
        existing = b'<?php return ["synthetic_original" => true];\n'
        cached.write_bytes(existing)
        command, observations = self.validation_deployment()
        with patch.dict(os.environ, {'APP_CONFIG_CACHE': 'synthetic-previous-cache.php'}), \
                contextlib.redirect_stdout(io.StringIO()):
            command._tests(TARGET)
            self.assertEqual('synthetic-previous-cache.php', os.environ['APP_CONFIG_CACHE'])
        isolated = Path(observations[0]['APP_CONFIG_CACHE'])
        self.assertTrue(isolated.is_absolute())
        self.assertFalse(isolated.exists())
        self.assertNotEqual(cached, isolated)
        self.assertEqual(existing, cached.read_bytes())

    def test_interrupted_compression_never_publishes_cache_and_retry_exports_again(self):
        command, runner = self.archive_deployment()
        references = ['myabi-translate-app:fixture']

        def interrupted_copy(source, target, **kwargs):
            self.assertEqual([], list((command.cache / 'images').glob('*.tar.gz')))
            target.write(source.read(5))
            raise KeyboardInterrupt('Synthetic interruption')

        with patch.object(self.client.shutil, 'copyfileobj', side_effect=interrupted_copy):
            with self.assertRaises(KeyboardInterrupt):
                command._archive_images(references)
        self.assertEqual([], list((command.cache / 'images').iterdir()))
        archive = command._archive_images(references)
        self.assertEqual(2, len(runner.saves))
        with gzip.open(archive, 'rb') as source:
            self.assertEqual(runner.payload, source.read())

    def test_failed_image_export_removes_raw_and_temporary_cache_files(self):
        command, runner = self.archive_deployment()
        runner.fail_save = True
        with self.assertRaises(self.client.DeploymentError):
            command._archive_images(['myabi-translate-app:fixture'])
        self.assertEqual([], list((command.cache / 'images').iterdir()))

    def test_changed_image_id_for_same_ref_builds_new_archive_without_reusing_old_bytes(self):
        command, runner = self.archive_deployment()
        references = ['myabi-translate-app:fixture']
        original_payload = runner.payload
        first = command._archive_images(references)
        self.assertEqual(first, command._archive_images(references))
        self.assertEqual(1, len(runner.saves))
        runner.image_id = 'sha256:' + '5' * 64
        runner.payload = b'SYNTHETIC TAR IMAGE B\x00\xff\r\n'
        second = command._archive_images(references)
        self.assertNotEqual(first, second)
        self.assertEqual(2, len(runner.saves))
        with gzip.open(first, 'rb') as source:
            self.assertEqual(original_payload, source.read())
        with gzip.open(second, 'rb') as source:
            self.assertEqual(runner.payload, source.read())


if __name__ == '__main__':
    unittest.main()
