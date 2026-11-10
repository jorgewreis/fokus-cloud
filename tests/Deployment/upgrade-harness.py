"""Runs real historical runtime then candidate in a new disposable DB. Never resets an existing DB."""
import argparse
import hashlib
import importlib.util
import json
import os
import pathlib
import secrets
import shutil
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('baseline_gate', ROOT / 'tests/Deployment/baseline-gate.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
parser = argparse.ArgumentParser()
parser.add_argument('--baseline-sha', required=True)
parser.add_argument('--driver', choices=['sqlite', 'mysql'], default='sqlite')
parser.add_argument('--reuse-installed-vendor', action='store_true', help='Offline only, requires identical baseline/candidate lockfiles')
parser.add_argument('--inject-failure', choices=['after-baseline', 'after-upgrade', 'fixture-assertion'])
args = parser.parse_args()
evidence = gate.validate(ROOT, args.baseline_sha)
evidence['candidate_worktree_diff_sha256'] = hashlib.sha256(subprocess.check_output(['git', 'diff', 'HEAD'], cwd=ROOT)).hexdigest()
source_hash = hashlib.sha256()
for filename in sorted(set(subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=ROOT).split(b'\0'))):
    if filename and (ROOT / os.fsdecode(filename)).is_file():
        source_hash.update(filename + b'\0' + hashlib.sha256((ROOT / os.fsdecode(filename)).read_bytes()).digest())
evidence['candidate_source_sha256'] = source_hash.hexdigest()
if args.driver == 'mysql' and os.environ.get('UPGRADE_DISPOSABLE_MYSQL') != '1':
    raise RuntimeError('MySQL requires explicit disposable-service opt-in')
# Ignore all inherited production DB URLs, keys, configuration caches and services.
with tempfile.TemporaryDirectory(prefix='fokus-upgrade-') as directory:
    sandbox = pathlib.Path(directory)
    (sandbox / '.disposable-upgrade').touch()
    (sandbox / 'upgrade.sqlite').touch()
    safe_environment = {key: os.environ[key] for key in ['PATH', 'HOME', 'TMPDIR', 'COMPOSER_CACHE_DIR'] if key in os.environ}
    env = dict(safe_environment, APP_ENV='testing', APP_DEBUG='false', APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', DB_CONNECTION='sqlite', DB_DATABASE=str(sandbox / 'upgrade.sqlite'), DB_URL='', DATABASE_URL='', CACHE_STORE='array', SESSION_DRIVER='array', QUEUE_CONNECTION='sync', MAIL_MAILER='array', LAW_TASKS_ENABLED='false', UPGRADE_SANDBOX=str(sandbox), DATAJUD_API_KEY='', MERCADO_PAGO_ACCESS_TOKEN='test-only', MERCADO_PAGO_WEBHOOK_SECRET='test-only', FOKUS_USAGE_INGESTION_SECRET='test-only')
    def run(command, cwd=ROOT):
        subprocess.run(command, cwd=cwd, env=env, check=True)
    baseline = sandbox / 'baseline'
    candidate = sandbox / 'candidate'
    baseline.mkdir()
    archive = subprocess.check_output(['git', 'archive', args.baseline_sha], cwd=ROOT)
    subprocess.run(['tar', '-x', '-C', str(baseline)], input=archive, check=True)
    shutil.copytree(ROOT, candidate, ignore=shutil.ignore_patterns('.git', '.env', '.env.*', 'vendor', 'node_modules', 'test-results'))
    for runtime in [baseline, candidate]:
        for cache in (runtime / 'bootstrap/cache').glob('*.php'):
            cache.unlink()
        for part in ['storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs']:
            (runtime / part).mkdir(parents=True, exist_ok=True)
        if args.reuse_installed_vendor:
            if (runtime / 'composer.lock').read_bytes() != (ROOT / 'composer.lock').read_bytes():
                raise RuntimeError('Offline vendor reuse requires identical lockfiles')
            shutil.copytree(ROOT / 'vendor', runtime / 'vendor')
        else:
            run(['composer', 'install', '--no-interaction', '--prefer-dist', '--optimize-autoloader'], runtime)
    if args.driver == 'mysql':
        # Only explicitly opted-in CI service on loopback; never an inherited DB target.
        if os.environ.get('UPGRADE_DISPOSABLE_MYSQL') != '1':
            raise RuntimeError('MySQL requires explicit disposable-service opt-in')
        name = 'fokus_upgrade_' + secrets.token_hex(8)
        env.update(DB_CONNECTION='mysql', DB_HOST='127.0.0.1', DB_PORT='3306', DB_USERNAME='root', DB_PASSWORD='security-test-only', DB_DATABASE=name)
        mysql_env = dict(env, MYSQL_PWD='security-test-only')
        def sql(statement):
            subprocess.run(['mysql', '-h127.0.0.1', '-uroot', '-e', statement], env=mysql_env, check=True)
        sql('CREATE DATABASE `' + name + '`')
    else:
        (sandbox / 'upgrade.sqlite').touch()
    try:
        print(json.dumps(evidence), flush=True)
        run(['php', str(ROOT / 'tests/Deployment/upgrade-fixture.php'), str(baseline), 'versions'])
        run(['php', 'artisan', 'migrate', '--seed', '--force', '--no-interaction'], baseline)
        fixture = str(ROOT / 'tests/Deployment/upgrade-fixture.php')
        run(['php', fixture, str(baseline), 'seed'])
        if args.inject_failure == 'after-baseline':
            raise RuntimeError('controlled failure after baseline')
        run(['php', 'artisan', 'migrate', '--force', '--no-interaction'], candidate)
        if args.inject_failure == 'fixture-assertion':
            env['UPGRADE_INJECT_ASSERTION_FAILURE'] = '1'
        run(['php', fixture, str(candidate), 'verify-up'])
        if args.inject_failure == 'after-upgrade':
            raise RuntimeError('controlled failure after upgrade')
        run(['php', 'artisan', 'migrate', '--force', '--no-interaction'], candidate)
        run(['php', fixture, str(candidate), 'verify-up'])
        run(['php', fixture, str(candidate), 'verify-recovery'])
        run(['php', str(ROOT / 'tests/Deployment/reversal-harness.php'), str(candidate)])
        print('UPGRADE AND REVERSAL PASSED (before any RefreshDatabase)', flush=True)
    finally:
        if args.driver == 'mysql':
            sql('DROP DATABASE `' + name + '`')
