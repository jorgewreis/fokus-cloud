"""Utilities for opt-in, synthetic-only Linux release evidence."""
import hashlib
import contextlib
import shutil
import tempfile
import json
import os
import pathlib
import subprocess
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
REPORT = pathlib.Path(os.environ.get('RELEASE_REPORT_DIR', ROOT / 'test-results/linux-release')).resolve()
BASELINE = '26daac7c27d246eaef64bb3f0e59499376993f8f'


def guard():
    if os.environ.get('RELEASE_DISPOSABLE_TESTS') != '1':
        raise RuntimeError('Explicit RELEASE_DISPOSABLE_TESTS=1 is required')
    if os.name != 'posix':
        raise RuntimeError('This harness requires the isolated Linux runtime')
    for name in ('.env', '.env.testing', 'bootstrap/cache/config.php'):
        if (ROOT / name).exists():
            raise RuntimeError('Unexpected inherited environment/configuration: ' + name)
    REPORT.mkdir(parents=True, exist_ok=True)


def sha(path):
    return hashlib.sha256(pathlib.Path(path).read_bytes()).hexdigest()


def write(name, data):
    (REPORT / name).write_text(json.dumps(data, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')


def environment(driver):
    env = {k: os.environ[k] for k in ('PATH', 'HOME', 'TMPDIR') if k in os.environ}
    env.update(APP_ENV='testing', APP_DEBUG='false', APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
               APP_URL='http://127.0.0.1', APP_MAINTENANCE_DRIVER='file', BCRYPT_ROUNDS='4',
               DB_CONNECTION=driver, DB_DATABASE=':memory:', DB_URL='', DATABASE_URL='',
               DB_HOST='127.0.0.1', DB_PORT='3306', DB_USERNAME='root', DB_PASSWORD='security-test-only',
               CACHE_STORE='array', CACHE_LIMITER='array', SESSION_DRIVER='array', QUEUE_CONNECTION='sync',
               MAIL_MAILER='array', BROADCAST_CONNECTION='null', FILESYSTEM_DISK='local',
               REDIS_CLIENT='phpredis', REDIS_HOST='127.0.0.1', REDIS_PORT='6379', REDIS_PASSWORD='null',
               REDIS_DB='10', REDIS_CACHE_DB='11', REDIS_PREFIX='release-qa-', CACHE_PREFIX='release-qa-cache-',
               LAW_TASKS_ENABLED='false', PULSE_ENABLED='false', TELESCOPE_ENABLED='false', NIGHTWATCH_ENABLED='false',
               LOG_CHANNEL='single', DATAJUD_API_KEY='', MERCADO_PAGO_ACCESS_TOKEN='test-token-not-a-credential',
               MERCADO_PAGO_WEBHOOK_SECRET='test-webhook-not-a-credential', MERCADO_PAGO_WEBHOOK_RATE_LIMIT='2',
               FOKUS_USAGE_INGESTION_SECRET='test-usage-not-a-credential')
    if driver == 'mysql':
        store = os.environ.get('RELEASE_CACHE_STORE')
        if store not in ('database', 'redis'):
            raise RuntimeError('MySQL requires explicit RELEASE_CACHE_STORE=database or redis')
        env.update(DB_DATABASE='fokuscloud_security', CACHE_STORE=store, CACHE_LIMITER=store, SESSION_DRIVER='database')
    return env


def php():
    return ['php', '-d', 'memory_limit=512M', '-d', 'allow_url_fopen=0', '-d',
            'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,exec,shell_exec,system,passthru,popen,proc_open',
            '-d', 'auto_prepend_file=' + str(ROOT / 'tests/Deployment/linux-network-guard.php')]


def run(command, name, env, cwd=ROOT, expected=0):
    log = REPORT / (name + '.log')
    start = time.monotonic()
    with log.open('wb') as output:
        result = subprocess.run(command, cwd=cwd, env=env, stdout=output, stderr=subprocess.STDOUT)
    record = {'exit_code': result.returncode, 'expected_exit_code': expected, 'duration_seconds': round(time.monotonic() - start, 3),
              'log': log.name, 'log_sha256': sha(log)}
    write(name + '.json', record)
    print(name, record, flush=True)
    if result.returncode != expected:
        raise RuntimeError(name + ': unexpected exit; retained ' + str(log))
    return record


def adapt(source, destination, replacements):
    text = source.read_text(encoding='utf-8')
    for old, new in replacements:
        if text.count(old) != 1:
            raise RuntimeError('Adapter anchor changed: ' + str(source))
        text = text.replace(old, new)
    destination.write_text(text, encoding='utf-8')
    return {'source': source.relative_to(ROOT).as_posix(), 'source_sha256': sha(source),
            'adapted_sha256': sha(destination), 'replacements': [{'old': old, 'new': new} for old, new in replacements]}


def safe_extract_source_archive(archive, destination):
    """Compatible with Python before tarfile extraction filters; reject links/devices."""
    destination = pathlib.Path(destination)
    if destination.is_symlink() or not destination.is_dir() or any(destination.iterdir()):
        raise RuntimeError('Archive destination must be an empty real directory')
    root = destination.resolve()
    members = archive.getmembers()
    seen = set()
    for member in members:
        name = member.name.rstrip('/')
        parts = name.split('/')
        if not name or name.startswith('/') or '\\' in name or ':' in name or any(p in ('', '.', '..') for p in parts):
            raise RuntimeError('Unsafe archive path')
        if not (member.isdir() or member.isfile()) or member.issym() or member.islnk():
            raise RuntimeError('Only regular files and directories are allowed in source archives')
        if name in seen:
            raise RuntimeError('Duplicate archive path')
        seen.add(name)
        target = root.joinpath(*parts)
        if root not in target.resolve().parents:
            raise RuntimeError('Archive path escapes destination')
    # Validate all members before creating any output. Never use extract/extractall.
    for member in members:
        target = root.joinpath(*member.name.rstrip('/').split('/'))
        if member.isdir():
            target.mkdir(parents=True, exist_ok=True)
            target.chmod(0o755)
        else:
            target.parent.mkdir(parents=True, exist_ok=True)
            with archive.extractfile(member) as source, target.open('xb') as output:
                shutil.copyfileobj(source, output)
            target.chmod(0o755 if member.mode & 0o100 else 0o644)
    return {'members': len(members), 'policy': 'regular-files-and-directories-only; full prevalidation; no ownership or special bits'}


@contextlib.contextmanager
def exclusive_suite_lock(env):
    # Linux-only advisory lock held for the whole run, not just schema preparation.
    import fcntl
    path = pathlib.Path(tempfile.gettempdir()) / ('fokus-release-suite-' + env['DB_CONNECTION'] + '.lock')
    with path.open('a') as handle:
        try:
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise RuntimeError('Another release suite owns the disposable database') from error
        try:
            yield
        finally:
            fcntl.flock(handle, fcntl.LOCK_UN)
