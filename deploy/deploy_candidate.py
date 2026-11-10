"""OFFLINE plan derived from main c92ef4e. Backend is mandatory; no live CLI.

The backend must implement run(Operation)->Result and pause(seconds). This
module imports no process/network tools and supplies no backend implementation.
Input references are opaque names, never credentials or file contents.
"""
from dataclasses import dataclass
import pathlib
import re
import shlex

try:
    from . import fpm_guard
except ImportError:
    import fpm_guard

# Explicit historical offline fixture, never the production destination default.
SERVICE = 'php8.5-fpm.service'
RELOAD = fpm_guard.reload_command(SERVICE)

MAIN_SHA = 'c92ef4e5ad99966a367054fe776988b9383ec68d'
MAIN_BLOB = 'fb5ab45ab05f61d79e5dec2c1e4360518806a5bf'
MIGRATION = 'database/migrations/2026_10_30_160000_add_encrypted_details_to_law_contacts.php'
MIGRATION_HASH = '2db13e1a8d350ac81577c042e915bdfea4bafe821b188ded125f2201e3e8b4d9'
EXCLUDES = ('.git/', '.github/', '.gitignore', '.env', '.env.*',
            'node_modules/', 'vendor/', 'storage/')


@dataclass(frozen=True)
class Operation:
    stage: str
    argv: tuple[str, ...]
    stdin_ref: str | None = None
    secret_refs: tuple[str, ...] = ()
    mutates: bool = False
    timeout: int = 60


@dataclass(frozen=True)
class Result:
    returncode: int = 0
    stdout: str = ''


class PlanError(RuntimeError):
    pass


def _path(value, remote=False):
    if not isinstance(value, str) or not value.startswith('/') or value == '/':
        raise ValueError('Absolute non-root POSIX path required')
    if any(ord(c) < 32 or ord(c) == 127 for c in value) or '\\' in value:
        raise ValueError('Unsafe path')
    if any(part in ('', '.', '..') for part in value[1:].split('/')):
        raise ValueError('Noncanonical path')
    if remote and not re.fullmatch(r'/[A-Za-z0-9_./-]+', value):
        raise ValueError('Unsupported remote path')
    return value


@dataclass(frozen=True)
class Inputs:
    host: str
    user: str
    port: int
    deploy_path: str
    source_root: str
    asset_root: str
    run_id: str
    attempt: str
    purge_zone: str | None = None

    def __post_init__(self):
        if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9.-]*', self.host):
            raise ValueError('Invalid host')
        if not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_-]*', self.user):
            raise ValueError('Invalid user')
        if type(self.port) is not int or not 1 <= self.port <= 65535:
            raise ValueError('Invalid port')
        _path(self.deploy_path, remote=True)
        _path(self.source_root)
        _path(self.asset_root)
        if not re.fullmatch(r'[1-9][0-9]{0,19}', self.run_id):
            raise ValueError('Invalid run id')
        if not re.fullmatch(r'[1-9][0-9]{0,5}', self.attempt):
            raise ValueError('Invalid attempt')
        if self.purge_zone is not None and not re.fullmatch(r'[0-9a-f]{32}', self.purge_zone):
            raise ValueError('Invalid purge zone')


class Candidate:
    def __init__(self, inputs, *, backend):
        if not isinstance(inputs, Inputs):
            raise TypeError('Validated Inputs required')
        if backend is None or not callable(getattr(backend, 'run', None)) or not callable(getattr(backend, 'pause', None)):
            raise TypeError('Explicit backend with run and pause required')
        self.inputs, self.backend = inputs, backend
        self.target = inputs.user + '@' + inputs.host
        self.ssh_options = ('-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
                            '-o', 'ConnectTimeout=20', '-o', 'ConnectionAttempts=1')
        self.cwd = 'set -eu; cd ' + shlex.quote(inputs.deploy_path) + '; '

    def effect(self, stage, argv, *, stdin_ref=None, secret_refs=(), mutates=False, timeout=60):
        op = Operation(stage, tuple(argv), stdin_ref, tuple(secret_refs), mutates, timeout)
        result = self.backend.run(op)
        if not isinstance(result, Result) or type(result.returncode) is not int or not isinstance(result.stdout, str):
            raise PlanError('Invalid backend result: ' + stage)
        if result.returncode:
            raise PlanError('Stage failed: ' + stage)
        return result.stdout

    def remote(self, stage, script, **kwargs):
        return self.effect(stage, ('ssh', *self.ssh_options, '-p', str(self.inputs.port),
                                   self.target, 'LC_ALL=C ' + script), **kwargs)

    def guard_adapter(self, phase):
        owner = self

        class Adapter:
            count = 0

            def run(self, *command):
                self.count += 1
                return owner.remote(f'{phase}.{self.count}', shlex.join(command),
                                    mutates=command == ('sudo', '-n', '--', *RELOAD)).strip()
        return Adapter()

    def execute(self):
        i = self.inputs
        # Must precede even the schema step's temporary mkdir and scp.
        fpm_guard.preflight(self.guard_adapter('preflight'), SERVICE)
        self.effect('schema.checksum', ('sha256sum', '--check', '--status'),
                    stdin_ref='reviewed-migration-checksum:' + MIGRATION_HASH + ':' + i.source_root + '/' + MIGRATION)
        remote_dir = f'/tmp/fokuscloud-law-schema-{i.run_id}-{i.attempt}'
        q = shlex.quote
        self.remote('schema.prepare', 'set -eu; ' + shlex.join(('test', '-f', i.deploy_path + '/vendor/autoload.php'))
                    + '; ' + shlex.join(('test', '-f', i.deploy_path + '/.env'))
                    + '; ' + shlex.join(('mkdir', '-m', '700', remote_dir)), mutates=True)
        self.effect('schema.copy', ('scp', *self.ssh_options, '-P', str(i.port),
                    i.source_root + '/' + MIGRATION, i.source_root + '/deploy/prepublish-law-schema.php',
                    self.target + ':' + remote_dir + '/'), mutates=True)
        self.remote('schema.prepublish', self.cwd + shlex.join(('php', remote_dir + '/prepublish-law-schema.php', i.deploy_path))
                    + '; ' + shlex.join(('rm', '-f', remote_dir + '/prepublish-law-schema.php', remote_dir + '/' + pathlib.PurePosixPath(MIGRATION).name))
                    + '; ' + shlex.join(('rmdir', remote_dir)), mutates=True)
        transport = shlex.join(('ssh', *self.ssh_options, '-p', str(i.port)))
        destination = self.target + ':' + i.deploy_path + '/'
        self.effect('assets.copy', ('rsync', '-rlDz', '--relative', '--files-from=' + i.asset_root + '/files.txt',
                    '-e', transport, i.asset_root + '/', destination), mutates=True)
        self.remote('assets.checksum', self.cwd + 'sha256sum --check -', stdin_ref='asset-checksums')
        self.remote('source.mkdir', shlex.join(('mkdir', '-p', i.deploy_path)), mutates=True)
        exclusions = tuple(part for pattern in EXCLUDES for part in ('--exclude', pattern))
        self.effect('source.sync', ('rsync', '-rlDzvc', '--delete-delay', '--filter=merge ' + i.asset_root + '/protect.rules',
                    *exclusions, '-e', transport, i.source_root + '/', destination), mutates=True)
        self.remote('runtime', self.cwd + "printf 'user='; id -un; printf 'php='; php -r 'echo PHP_VERSION, PHP_EOL;'; "
                    "printf 'composer='; composer --version; test -f composer.json; test -f composer.lock; test -f .env")
        log = f'/tmp/fokuscloud-composer-{i.run_id}-{i.attempt}.log'
        status = f'/tmp/fokuscloud-composer-{i.run_id}-{i.attempt}.status'
        install = 'composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader; result=$?; printf "%s\\n" "$result" > ' + q(status)
        self.remote('composer.start', self.cwd + 'test -f .env; mkdir -p resources/views storage/framework/cache storage/framework/sessions storage/framework/views storage/logs; '
                    'find storage bootstrap/cache -type d -exec chmod ug+rwx {} +; rm -f bootstrap/cache/*.php '
                    + q(log) + ' ' + q(status) + '; nohup sh -c ' + q(install) + ' > ' + q(log) + ' 2>&1 </dev/null &', mutates=True)
        for attempt in range(45):
            output = self.remote('composer.poll', 'if test -f ' + q(status) + '; then cat ' + q(status) + '; else printf "PENDING\\n"; fi').strip()
            if output == 'PENDING':
                if attempt == 44:
                    raise PlanError('Composer status timeout')
                self.backend.pause(2)
                continue
            if not re.fullmatch(r'(0|[1-9][0-9]{0,2})', output) or int(output) > 255:
                raise PlanError('Invalid Composer completion status')
            self.remote('composer.log', shlex.join(('cat', log)))
            if output != '0':
                raise PlanError('Composer install failed')
            break
        self.remote('migrations', self.cwd + 'php artisan migrate --force --no-interaction', mutates=True)
        self.remote('datajud.configure', self.cwd + 'php deploy/configure-datajud.php', stdin_ref='datajud-api-key', mutates=True)
        self.remote('optimize', self.cwd + 'php artisan optimize', mutates=True)
        self.remote('age-tags', self.cwd + 'php artisan law:sync-contact-age-tags --no-interaction', mutates=True)
        self.remote('datajud.status', self.cwd + 'php artisan law:datajud-status')
        self.remote('datajud.probe', self.cwd + 'php artisan law:datajud-status --probe')
        self.remote('scheduler.install', self.cwd + 'bash deploy/install-scheduler.sh', mutates=True)
        schedule = self.remote('scheduler.verify', self.cwd + 'php artisan schedule:list --no-ansi')
        if 'law-datajud' not in schedule:
            raise PlanError('Expected scheduler entry missing')
        fpm_guard.activate(self.guard_adapter('activation'), SERVICE)
        if i.purge_zone is not None:
            self.effect('purge', ('curl', '--fail-with-body', '--request', 'POST',
                        'https://api.cloudflare.com/client/v4/zones/' + i.purge_zone + '/purge_cache',
                        '--header', 'Authorization: Bearer <injected:cloudflare-api-token>',
                        '--header', 'Content-Type: application/json', '--data', '{"purge_everything":true}'),
                        secret_refs=('cloudflare-api-token',), mutates=True)
        return True
