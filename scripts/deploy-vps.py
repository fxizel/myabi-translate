#!/usr/bin/env python3
"""Deploy an exact Git commit to the existing Docker VPS without schema changes."""
from __future__ import annotations

import argparse
import glob
import gzip
import hashlib
import importlib.util
import json
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import shlex
import shutil
import subprocess
import sys
import tarfile
from urllib.parse import urlsplit
import uuid


class DeploymentError(Exception):
    """Messages deliberately exclude subprocess output and application data."""


def digest(path):
    result = hashlib.sha256()
    with Path(path).open('rb') as source:
        for block in iter(lambda: source.read(1024 * 1024), b''):
            result.update(block)
    return result.hexdigest()


def write_json(path, value):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    temporary = path.with_suffix(path.suffix + '.tmp')
    temporary.write_text(json.dumps(value, indent=2) + '\n', encoding='utf-8')
    temporary.chmod(0o600)
    temporary.replace(path)


def validate_config(data, base_dir=Path('.')):
    if not isinstance(data, dict):
        raise DeploymentError('Configuration JSON invalide.')
    result = dict(data)
    for key in ('ssh_host', 'ssh_user', 'ssh_key', 'remote_root', 'site_url'):
        if not isinstance(result.get(key), str) or not result[key]:
            raise DeploymentError('Configuration requise absente ou invalide.')
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9.-]*', result['ssh_host']):
        raise DeploymentError('Hôte SSH invalide.')
    if not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_-]{0,31}', result['ssh_user']):
        raise DeploymentError('Utilisateur SSH invalide.')
    root = PurePosixPath(result['remote_root'])
    if (not root.is_absolute() or len(root.parts) < 3 or '..' in root.parts
            or not re.fullmatch(r'/[A-Za-z0-9_./-]+', result['remote_root'])):
        raise DeploymentError('Racine distante invalide.')
    result['remote_root'] = str(root)
    url = urlsplit(result['site_url'])
    try:
        port = url.port
    except ValueError:
        raise DeploymentError('URL HTTPS invalide.') from None
    if (url.scheme != 'https' or not url.hostname or url.username or url.password
            or url.query or url.fragment or url.path not in ('', '/') or port not in (None, 443)):
        raise DeploymentError('Une URL HTTPS canonique sans identifiants est requise.')
    result['site_url'] = result['site_url'].rstrip('/')
    key = Path(os.path.expanduser(result['ssh_key']))
    if not key.is_absolute():
        key = Path(base_dir) / key
    if not key.is_file():
        raise DeploymentError('Fichier de clé SSH introuvable.')
    result['ssh_key'] = str(key.resolve())
    if 'originals_dir' in result:
        if not isinstance(result['originals_dir'], str) or not result['originals_dir']:
            raise DeploymentError('Dossier des originaux invalide.')
        originals = Path(os.path.expanduser(result['originals_dir']))
        if not originals.is_absolute():
            originals = Path(base_dir) / originals
        if not originals.is_dir():
            raise DeploymentError('Dossier local des originaux introuvable.')
        result['originals_dir'] = str(originals.resolve())
    result.setdefault('project', 'myabi-translate')
    if result['project'] != 'myabi-translate':
        raise DeploymentError('Cette commande est réservée au projet myabi-translate.')
    result.setdefault('ssh_port', 22)
    if type(result['ssh_port']) is not int or not 1 <= result['ssh_port'] <= 65535:
        raise DeploymentError('Port SSH invalide.')
    result.setdefault('timer_names', ['myabi-docker-work.timer', 'myabi-docker-notifications.timer'])
    if (not isinstance(result['timer_names'], list) or not result['timer_names']
            or any(not isinstance(name, str) or name not in {
                'myabi-docker-work.timer', 'myabi-docker-notifications.timer'} for name in result['timer_names'])
            or len(result['timer_names']) != len(set(result['timer_names']))):
        raise DeploymentError('Liste de timers myABI invalide.')
    for key, default in [('min_free_bytes', 3 * 1024**3), ('operations_timeout_seconds', 2100)]:
        result.setdefault(key, default)
        if type(result[key]) is not int or result[key] <= 0:
            raise DeploymentError('Limite de déploiement invalide.')
    if result['operations_timeout_seconds'] > 7200:
        raise DeploymentError('Le délai des opérations doit être inférieur ou égal à 7200 secondes.')
    result.setdefault('test_commands', [['php', 'artisan', 'test'],
                                      ['node', '--test', 'tests/JavaScript/*.test.cjs'],
                                      [sys.executable, '-m', 'unittest', 'discover', '-s', 'tests/Operations', '-p', 'test_*.py'],
                                      ['php', 'scripts/test_devconf_roundtrip.php', '--validate-semantics']])
    if (not isinstance(result['test_commands'], list) or not result['test_commands']
            or any(not isinstance(command, list) or not command
                   or any(not isinstance(arg, str) or not arg for arg in command)
                   for command in result['test_commands'])):
        raise DeploymentError('Les contrôles locaux doivent être des listes de commandes non vides.')
    return result


class Runner:
    def __init__(self, directory):
        self.directory = Path(directory)

    def _log(self, name):
        self.directory.mkdir(parents=True, exist_ok=True, mode=0o700)
        return self.directory / name

    def capture(self, args, cwd=None):
        with self._log('commands.log').open('ab') as log:
            completed = subprocess.run(args, cwd=cwd, stdout=subprocess.PIPE, stderr=log)
        if completed.returncode:
            raise DeploymentError('Commande échouée ; consulter les journaux privés.')
        return completed.stdout.decode('utf-8').strip()

    def run(self, args, cwd=None, log_path=None):
        path = Path(log_path) if log_path else self._log('commands.log')
        path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        with path.open('ab') as log:
            if subprocess.run(args, cwd=cwd, stdout=log, stderr=log).returncode:
                raise DeploymentError('Commande échouée ; consulter les journaux privés.')


class Transport:
    def __init__(self, config, remote_script, logs):
        self.config = config
        self.source = Path(remote_script).read_text(encoding='utf-8')
        self.logs = Path(logs)
        self.ssh = ['ssh', '-i', config['ssh_key'], '-p', str(config['ssh_port']),
                    '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes',
                    '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=15',
                    '-o', 'ServerAliveInterval=30', '-o', 'ServerAliveCountMax=10',
                    config['ssh_user'] + '@' + config['ssh_host']]

    def _payload(self, action, release, params):
        remote_keys = ('remote_root', 'project', 'site_url', 'timer_names',
                       'operations_timeout_seconds', 'min_free_bytes')
        payload = {'action': action, 'config': {key: self.config[key] for key in remote_keys},
                   'release': release or {}, 'params': params or {}}
        return (self.source + '\nemit(' + repr(payload) + ')\n').encode('utf-8')

    def _stderr(self):
        self.logs.mkdir(parents=True, exist_ok=True, mode=0o700)
        return (self.logs / 'ssh.log').open('ab')

    def call(self, action, release=None, params=None):
        with self._stderr() as log:
            result = subprocess.run(self.ssh + ['sudo -n python3 -u -'],
                input=self._payload(action, release, params), stdout=subprocess.PIPE, stderr=log)
        try:
            response = json.loads(result.stdout)
        except (ValueError, UnicodeError):
            raise DeploymentError('Réponse distante invalide ; consulter les journaux privés.') from None
        if result.returncode or response.get('ok') is not True:
            # Never echo arbitrary remote exception text.
            raise DeploymentError('Étape distante refusée : ' + action + '. Consulter le rapport privé du serveur.')
        return response['data']

    def send_file(self, source, destination):
        expected_prefix = self.config['remote_root'] + '/operations/deploy-'
        if (not destination.startswith(expected_prefix) or '..' in PurePosixPath(destination).parts
                or not destination.endswith('/images.tar.gz')):
            raise DeploymentError('Destination de transfert invalide.')
        command = 'sudo -n sh -c ' + shlex.quote('umask 077; cat > ' + shlex.quote(destination))
        with Path(source).open('rb') as handle, self._stderr() as log:
            result = subprocess.run(self.ssh + [command], stdin=handle, stdout=log, stderr=log)
        if result.returncode:
            raise DeploymentError('Transfert des images interrompu.')

    def backup(self, release, target):
        with Path(target).open('xb') as archive, self._stderr() as log:
            result = subprocess.run(self.ssh + ['sudo -n python3 -u -'],
                input=self._payload('backup', release, {}), stdout=archive, stderr=log)
        if result.returncode:
            raise DeploymentError('Sauvegarde interrompue ; aucune bascule autorisée.')


BUILD_PATHS = ['.dockerignore', 'app', 'bootstrap', 'config', 'database/migrations',
               'lang', 'public', 'resources/views', 'routes', 'scripts', 'artisan',
               'composer.json', 'composer.lock', 'deploy/docker']
CRITICAL_PATHS = ['database/migrations', 'deploy/docker/Dockerfile', 'deploy/docker/compose.yml',
                  'deploy/docker/compose.caddy.yml', 'deploy/docker/php.ini', 'deploy/docker/php-fpm.conf',
                  'deploy/docker/nginx.conf.template', 'deploy/docker/entrypoint.sh',
                  'deploy/docker/provision.php', 'deploy/docker/migrate.php', 'deploy/docker/backup.sh',
                  'app/Services/BrowserExtension/SearchProjection.php',
                  'app/Services/BrowserExtension/TextNormalizer.php']


class Deployment:
    def __init__(self, repo, config, runner=None, transport=None, verifier=None):
        self.repo = Path(repo).resolve()
        self.config = validate_config(config, self.repo)
        self.cache = self.repo / '.runtime/vps-deploy'
        self.runner = runner or Runner(self.cache / 'logs')
        remote = Path(__file__).with_name('vps-deploy-remote.py')
        self.transport = transport or Transport(self.config, remote, self.cache / 'logs')
        if verifier is None:
            verify_path = self.repo / 'scripts/verify-backup.py'
            spec = importlib.util.spec_from_file_location('vps_backup_verifier', verify_path)
            module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)
            verifier = module.verify
        self.verifier = verifier

    def git(self, *args):
        return self.runner.capture(['git', *args], cwd=self.repo)

    def _tests(self, revision):
        tree = self.git('ls-tree', '-r', revision, '--', 'app', 'bootstrap', 'config', 'database',
                        'lang', 'resources', 'routes', 'public', 'composer.json', 'composer.lock',
                        'phpunit.xml', 'tests', 'scripts')
        key = hashlib.sha256((tree + json.dumps(self.config['test_commands'], sort_keys=True)).encode()).hexdigest()
        tools = []
        for command in self.config['test_commands']:
            executable = shutil.which(command[0])
            if executable:
                stat = Path(executable).stat()
                tools.append((str(Path(executable).resolve()), stat.st_size, stat.st_mtime_ns))
        key = hashlib.sha256((key + json.dumps(tools)).encode()).hexdigest()
        local_inputs = ['.env', 'vendor/composer/installed.json', 'bootstrap/cache/config.php']
        local_checksums = [(name, digest(self.repo / name)) for name in local_inputs if (self.repo / name).is_file()]
        key = hashlib.sha256((key + json.dumps(local_checksums)).encode()).hexdigest()
        originals = Path(self.config.get('originals_dir', self.repo / 'data/imports'))
        if originals.is_dir():
            original_checksums = [(p.name, digest(p)) for p in sorted(originals.glob('*.csv'))]
            key = hashlib.sha256((key + json.dumps(original_checksums)).encode()).hexdigest()
        receipt = self.cache / 'tests' / (key + '.json')
        if receipt.exists() and json.loads(receipt.read_text(encoding='utf-8')).get('passed') is True:
            print('Contrôles locaux déjà validés pour ces fichiers ; résultat réutilisé.', flush=True)
            return
        print('Validation locale avant déploiement...', flush=True)
        testing = {'APP_ENV': 'testing', 'APP_KEY': 'base64:' + __import__('base64').b64encode(secrets.token_bytes(32)).decode(),
                   'APP_CONFIG_CACHE': str(self.cache / ('testing-config-' + uuid.uuid4().hex + '.php')),
                   'DB_CONNECTION': 'sqlite', 'DB_DATABASE': ':memory:', 'DB_URL': '',
                   'MAIL_MAILER': 'array', 'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array',
                   'QUEUE_CONNECTION': 'sync', 'MFA_ENABLED': 'false'}
        previous = {key: os.environ.get(key) for key in [*testing, 'MYABI_ISOLATED_TESTS', 'AUTH_PASSWORD_MIN_LENGTH']}
        env_file = self.repo / '.env'
        created = False
        try:
            os.environ.update(testing)
            os.environ.pop('MYABI_ISOLATED_TESTS', None)
            # This policy must remain mutable through Laravel's dotenv repository
            # so its default/override regression can exercise the configuration.
            os.environ.pop('AUTH_PASSWORD_MIN_LENGTH', None)
            if not env_file.exists():
                with env_file.open('x', encoding='utf-8', newline='\n') as target:
                    fixture = {**testing, 'AUTH_PASSWORD_MIN_LENGTH': '8'}
                    target.write('\n'.join(key + '=' + value for key, value in fixture.items()) + '\n')
                env_file.chmod(0o600)
                created = True
            for index, command in enumerate(self.config['test_commands']):
                expanded = []
                for argument in command:
                    matches = sorted(glob.glob(argument, root_dir=self.repo)) if '*' in argument or '?' in argument else []
                    expanded.extend(matches or [argument])
                self.runner.run(expanded, cwd=self.repo, log_path=self.cache / 'logs' / ('tests-' + str(index) + '.log'))
            write_json(receipt, {'passed': True, 'fingerprint': key, 'revision': revision})
        finally:
            if created:
                env_file.unlink(missing_ok=True)
            Path(testing['APP_CONFIG_CACHE']).unlink(missing_ok=True)
            for key, value in previous.items():
                if value is None:
                    os.environ.pop(key, None)
                else:
                    os.environ[key] = value

    def _build(self, revision, tag):
        folder = self.cache / 'releases' / revision
        folder.mkdir(parents=True, exist_ok=True, mode=0o700)
        receipt = folder / 'build.json'
        if receipt.exists():
            recorded = json.loads(receipt.read_text(encoding='utf-8'))
            try:
                for item in recorded['images']:
                    actual = json.loads(self.runner.capture(['docker', 'image', 'inspect', item['ref']]))[0]
                    if actual['Id'] != item['id']:
                        raise DeploymentError('Image locale différente du reçu.')
                return recorded['images']
            except (DeploymentError, ValueError, KeyError):
                pass
        archive = folder / 'source.tar'
        self.runner.run(['git', 'archive', '--format=tar', '--output=' + str(archive), revision, '--', *BUILD_PATHS], cwd=self.repo)
        source = folder / 'source'
        source.mkdir(exist_ok=True)
        with tarfile.open(archive) as incoming:
            incoming.extractall(source, filter='data')
        images = []
        for target, name, service in [('app', 'myabi-translate-app', 'app'), ('web', 'myabi-translate-web', 'myabi-web')]:
            reference = name + ':' + tag
            self.runner.run(['docker', 'build', '--file', 'deploy/docker/Dockerfile', '--target', target,
                             '--build-arg', 'APP_REVISION=' + revision, '--build-arg', 'APP_VERSION=' + tag,
                             '--tag', reference, '.'], cwd=source, log_path=folder / ('build-' + target + '.log'))
            metadata = json.loads(self.runner.capture(['docker', 'image', 'inspect', reference]))[0]
            if metadata['Architecture'] != 'amd64' or metadata['Os'] != 'linux':
                raise DeploymentError('Images Linux amd64 requises.')
            images.append({'ref': reference, 'id': metadata['Id'], 'service': service})
        write_json(receipt, {'revision': revision, 'images': images})
        return images

    def _archive_images(self, missing):
        identities = [(ref, json.loads(self.runner.capture(['docker', 'image', 'inspect', ref]))[0]['Id'])
                      for ref in sorted(missing)]
        fingerprint = hashlib.sha256(json.dumps(identities).encode()).hexdigest()
        folder = self.cache / 'images'
        folder.mkdir(parents=True, exist_ok=True, mode=0o700)
        archive = folder / (fingerprint + '.tar.gz')
        if not archive.exists():
            temporary = folder / (fingerprint + '.' + uuid.uuid4().hex + '.partial')
            raw = temporary.with_suffix('.tar')
            try:
                self.runner.run(['docker', 'image', 'save', '--output', str(raw), *missing])
                with raw.open('rb') as source, temporary.open('xb') as target:
                    with gzip.GzipFile(filename='', fileobj=target, mode='wb', compresslevel=1, mtime=0) as compressed:
                        shutil.copyfileobj(source, compressed, length=1024 * 1024)
                temporary.replace(archive)
            finally:
                raw.unlink(missing_ok=True)
                temporary.unlink(missing_ok=True)
        return archive

    def run(self, ref='HEAD', plan=False):
        if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_./~^-]*', ref) or '..' in ref:
            raise DeploymentError('Référence Git invalide.')
        dirty = bool(self.git('status', '--porcelain'))
        if dirty and not plan:
            raise DeploymentError('Créer un commit et conserver un dépôt propre avant de déployer.')
        revision = self.git('rev-parse', '--verify', ref + '^{commit}')
        if not re.fullmatch(r'[a-f0-9]{40}', revision):
            raise DeploymentError('Commit Git invalide.')
        status = self.transport.call('status')
        if status.get('maintenance') or status.get('lease') or not status.get('healthy'):
            raise DeploymentError('Installation en maintenance, indisponible ou déploiement incomplet : récupération requise.')
        if status['revision'] == revision:
            return {'status': 'plan' if plan else 'noop', 'revision': revision, 'already_active': True, 'dirty': dirty}
        if not re.fullmatch(r'[a-f0-9]{40}', status['revision']):
            raise DeploymentError('Version active non identifiée.')
        self.git('cat-file', '-e', status['revision'] + '^{commit}')
        old = self.git('ls-tree', '-r', status['revision'], '--', *CRITICAL_PATHS)
        new = self.git('ls-tree', '-r', revision, '--', *CRITICAL_PATHS)
        if old != new:
            raise DeploymentError('Schéma, projection ou configuration Docker modifiés : utiliser la procédure complète avant toute maintenance.')
        if status.get('available_bytes', 0) < self.config['min_free_bytes']:
            raise DeploymentError('Marge disque distante insuffisante.')
        if plan:
            return {'status': 'plan', 'revision': revision, 'previous_revision': status['revision'],
                    'dirty': dirty, 'schema_unchanged': True, 'already_active': False}
        if self.git('rev-parse', '--verify', 'HEAD^{commit}') != revision:
            raise DeploymentError('Placer le checkout sur la référence demandée avant de valider et déployer ses fichiers.')
        self._tests(revision)
        tag = 'sha-' + revision[:12]
        print('Construction Docker avec réutilisation du cache...', flush=True)
        images = self._build(revision, tag)
        if self.git('status', '--porcelain') or self.git('rev-parse', '--verify', 'HEAD^{commit}') != revision:
            raise DeploymentError('Le checkout a changé pendant la préparation ; aucune bascule autorisée.')
        release = {'run_id': uuid.uuid4().hex, 'revision': revision, 'tag': tag,
                   'previous_revision': status['revision'], 'previous_tag': status['tag'], 'images': images}
        run_folder = self.cache / 'runs' / release['run_id']
        write_json(run_folder / 'release.json', release)
        staged = False
        try:
            missing = self.transport.call('image-status', release)['missing_refs']
            if set(missing) - {item['ref'] for item in images}:
                raise DeploymentError('Inventaire distant des images invalide.')
            # Stage can acquire its durable lease even if the SSH response is lost.
            staged = True
            self.transport.call('stage', release)
            params = {'sha256': None, 'bytes': 0}
            if missing:
                print('Transfert des seules images absentes du serveur...', flush=True)
                archive = self._archive_images(missing)
                destination = self.config['remote_root'] + '/operations/deploy-' + release['run_id'] + '/images.tar.gz'
                self.transport.send_file(archive, destination)
                params = {'sha256': digest(archive), 'bytes': archive.stat().st_size}
            self.transport.call('load', release, params)
            print('Maintenance et sauvegarde privée hors VPS...', flush=True)
            self.transport.call('freeze', release)
            backups = self.cache / 'backups'
            backups.mkdir(parents=True, exist_ok=True, mode=0o700)
            target = backups / (release['run_id'] + '.partial.zip')
            self.transport.backup(release, target)
            backup_manifest = self.verifier(target)
            if (backup_manifest.get('release', {}).get('commit') != release['previous_revision']
                    or backup_manifest.get('release', {}).get('version') != release['previous_tag']):
                raise DeploymentError('La sauvegarde vérifiée ne correspond pas à la version active attendue.')
            receipt = {'sha256': digest(target), 'bytes': target.stat().st_size}
            verified = target.with_name(release['run_id'] + '.zip')
            target.rename(verified)
            receipt['local_path'] = str(verified)
            write_json(run_folder / 'backup.json', receipt)
            self.transport.call('backup-verified', release, {'sha256': receipt['sha256'], 'bytes': receipt['bytes']})
            print('Bascule, contrôles et réouverture...', flush=True)
            self.transport.call('switch', release)
            proof = self.transport.call('finalize', release)
            write_json(run_folder / 'success.json', proof)
            return {'status': 'deployed', 'revision': revision, 'run_id': release['run_id'],
                    'backup': str(verified), 'transferred_images': len(missing)}
        except BaseException:
            if staged:
                try:
                    recovery = self.transport.call('recover', release)
                    if recovery.get('phase') == 'complete':
                        write_json(run_folder / 'success.json', recovery)
                        return {'status': 'deployed', 'revision': revision, 'run_id': release['run_id'],
                                'backup': str(verified), 'transferred_images': len(missing),
                                'completion_recovered': True}
                    print('La version précédente et ses timers ont été rétablis.', flush=True)
                except Exception:
                    print('Récupération requise : relancer avec --recover ' + release['run_id'], flush=True)
            raise

    def recover(self, run_id):
        if not re.fullmatch(r'[a-f0-9]{32}', run_id):
            raise DeploymentError('Identifiant de récupération invalide.')
        path = self.cache / 'runs' / run_id / 'release.json'
        if not path.is_file():
            raise DeploymentError('Reçu local de déploiement introuvable.')
        release = json.loads(path.read_text(encoding='utf-8'))
        if release.get('run_id') != run_id:
            raise DeploymentError('Reçu local invalide.')
        return self.transport.call('recover', release)


def main(argv=None):
    parser = argparse.ArgumentParser(description='Livraison Docker myABI rapide, sans changement de schéma.')
    parser.add_argument('--config', default='.runtime/vps-deploy.json', help='Configuration privée ignorée par Git')
    parser.add_argument('--ref', default='HEAD', help='Référence attendue du checkout à livrer')
    parser.add_argument('--plan', action='store_true', help='Lecture seule : inventorier et afficher le plan')
    parser.add_argument('--recover', metavar='RUN_ID', help='Rétablir un déploiement interrompu appartenant à ce poste')
    args = parser.parse_args(argv)
    try:
        repo = Path(__file__).resolve().parent.parent
        config_path = Path(args.config)
        if not config_path.is_absolute():
            config_path = repo / config_path
        config = validate_config(json.loads(config_path.read_text(encoding='utf-8-sig')), config_path.parent)
        command = Deployment(repo, config)
        result = command.recover(args.recover) if args.recover else command.run(args.ref, args.plan)
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0
    except DeploymentError as error:
        print('Déploiement arrêté : ' + str(error), file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        print('Déploiement interrompu. Consulter le reçu privé et utiliser --recover si nécessaire.', file=sys.stderr)
        return 130
    except Exception as error:
        # Raw exceptions can contain SSH details, private paths, SQL or test output.
        print('Déploiement arrêté (' + type(error).__name__ + '). Consulter les journaux privés.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
