"""Exercise the historical runtime, four migrations, reversal and synthetic backup restore."""
import argparse
import hashlib
import importlib.util
import json
import os
import pathlib
import secrets
import shutil
import subprocess
import tarfile
import tempfile
from linux_release_common import ROOT, REPORT, BASELINE, guard, environment, php, run, adapt, write, sha


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--driver', choices=['sqlite', 'mysql'], required=True)
    parser.add_argument('--baseline-sha', default=BASELINE)
    args = parser.parse_args()
    guard()
    spec = importlib.util.spec_from_file_location('baseline_gate', ROOT / 'tests/Deployment/baseline-gate.py')
    gate = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(gate)
    evidence = gate.validate(ROOT, args.baseline_sha)
    if args.baseline_sha != BASELINE:
        raise RuntimeError('Unreviewed historical baseline')
    prefix = 'upgrade-' + args.driver + '-'
    env = environment(args.driver)
    env.update(CACHE_STORE='array', CACHE_LIMITER='array', SESSION_DRIVER='array')
    fixture = ROOT / 'tests/Deployment/upgrade-fixture.php'
    reversal = ROOT / 'tests/Deployment/reversal-harness.php'
    evidence.update(fixture_sha256=sha(fixture), reversal_source_sha256=sha(reversal), steps=[])
    with tempfile.TemporaryDirectory(prefix='fokus-upgrade-') as directory:
        sandbox = pathlib.Path(directory)
        (sandbox / '.disposable-upgrade').touch()
        db = sandbox / 'upgrade.sqlite'
        db.touch()
        env.update(UPGRADE_SANDBOX=sandbox.as_posix(), DB_DATABASE=db.as_posix())
        base, candidate = sandbox / 'baseline', sandbox / 'candidate'
        base.mkdir()
        archive = sandbox / 'baseline.tar'
        with archive.open('wb') as stream:
            subprocess.run(['git', 'archive', args.baseline_sha], cwd=ROOT, stdout=stream, check=True)
        with tarfile.open(archive) as stream:
            stream.extractall(base, filter='data')
        tracked = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=ROOT).split(b'\0')
        rows = []
        for encoded in sorted(set(tracked)):
            if not encoded:
                continue
            relative = pathlib.Path(os.fsdecode(encoded))
            if any(p in {'.git', 'vendor', 'node_modules', 'test-results', 'playwright-report', '__pycache__'} for p in relative.parts) or relative.name.startswith('.env') and not relative.name.endswith('.example'):
                continue
            source = ROOT / relative
            if not source.is_file() or str(relative).replace('\\', '/').startswith('bootstrap/cache/') and source.name != '.gitignore':
                continue
            target = candidate / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, target)
            rows.append({'path': relative.as_posix(), 'sha256': sha(source)})
        evidence['candidate_source_sha256'] = hashlib.sha256(json.dumps(rows, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
        evidence['candidate_source_hash_method'] = 'sha256 of sorted JSON path+sha256 rows; not a Git commit or another manifest format'
        write(prefix + 'source.json', {'files': rows, 'source_sha256': evidence['candidate_source_sha256']})
        for runtime in (base, candidate):
            if (runtime / 'composer.lock').read_bytes() != (ROOT / 'composer.lock').read_bytes():
                raise RuntimeError('Offline vendor reuse requires identical lockfiles')
            shutil.copytree(ROOT / 'vendor', runtime / 'vendor')
            for part in ('bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'storage/app/private', 'storage/app/public'):
                (runtime / part).mkdir(parents=True, exist_ok=True)
            if (runtime / '.env').exists() or (runtime / 'bootstrap/cache/config.php').exists():
                raise RuntimeError('Unsafe runtime configuration copied')
        new = sorted(p.name for p in (candidate / 'database/migrations').glob('*.php') if not (base / 'database/migrations' / p.name).exists())
        if len(new) != 4:
            raise RuntimeError('Expected exactly four candidate migrations')
        evidence['new_migrations'] = new
        created = False
        mysql_env = dict(env, MYSQL_PWD='security-test-only')
        name = 'fokus_upgrade_' + secrets.token_hex(8)
        def sql(statement):
            subprocess.run(['mysql', '--protocol=TCP', '-h127.0.0.1', '-P3306', '-uroot', '-e', statement], env=mysql_env, check=True, stdout=subprocess.DEVNULL)
        def execute(command, label, runtime=candidate, expected=0):
            result = run(php() + command, prefix + label, env, runtime, expected)
            evidence['steps'].append({'name': label, **result})
        backup = sandbox / ('backup.sql' if args.driver == 'mysql' else 'backup.sqlite')
        try:
            if args.driver == 'mysql':
                env.update(DB_DATABASE=name)
                mysql_env.update(DB_DATABASE=name)
                sql('CREATE DATABASE `' + name + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
                created = True
                adapted = sandbox / 'reversal-mysql.php'
                old = 'str_contains(strtolower($event->sql), $failAt)'
                new = "(str_contains(strtolower($event->sql), $failAt) || ($failAt === 'drop column' && str_contains(strtolower($event->sql), 'drop `last_official_movement_at`')))"
                evidence['reversal_adapter'] = adapt(reversal, adapted, [(old, new), ("$failAt = null;\n        throw new RuntimeException('injected structural interruption');", "echo 'INJECT after actual DDL: '.$event->sql.PHP_EOL;\n        $failAt = null;\n        throw new RuntimeException('injected structural interruption');")])
                reversal = adapted
            execute([str(fixture), str(base), 'versions'], 'versions', base)
            execute(['artisan', 'migrate', '--seed', '--force', '--no-interaction'], 'baseline-migrate-seed', base)
            execute([str(fixture), str(base), 'seed'], 'populate-baseline', base)
            execute(['artisan', 'migrate', '--force', '--no-interaction'], 'four-migrations')
            execute([str(fixture), str(candidate), 'verify-up'], 'verify-up')
            execute(['artisan', 'migrate', '--force', '--no-interaction'], 'idempotence-migrate')
            execute([str(fixture), str(candidate), 'verify-up'], 'idempotence-verify')
            if args.driver == 'mysql':
                with backup.open('wb') as stream, (REPORT / (prefix + 'dump.log')).open('wb') as errors:
                    subprocess.run(['mysqldump', '--protocol=TCP', '-h127.0.0.1', '-P3306', '-uroot', '--single-transaction', '--hex-blob', '--no-tablespaces', name], env=mysql_env, stdout=stream, stderr=errors, check=True)
            else:
                # Every PHP process exited; no live SQLite writer exists at this point.
                shutil.copyfile(db, backup)
            evidence['backup_sha256'] = sha(backup)
            evidence['backup_bytes'] = backup.stat().st_size
            execute([str(fixture), str(candidate), 'verify-recovery'], 'roll-forward')
            execute([str(reversal), str(candidate)], 'reversal')
            if sha(backup) != evidence['backup_sha256']:
                raise RuntimeError('Backup bytes changed before restoration')
            if args.driver == 'mysql':
                with backup.open('rb') as stream, (REPORT / (prefix + 'restore.log')).open('wb') as output:
                    subprocess.run(['mysql', '--protocol=TCP', '-h127.0.0.1', '-P3306', '-uroot', name], env=mysql_env, stdin=stream, stdout=output, stderr=subprocess.STDOUT, check=True)
            else:
                shutil.copyfile(backup, db)
                if sha(db) != sha(backup):
                    raise RuntimeError('SQLite restore byte verification failed')
                evidence['restored_database_sha256_before_read'] = sha(db)
            execute([str(fixture), str(candidate), 'verify-up'], 'restored-backup-verify')
            env['UPGRADE_INJECT_ASSERTION_FAILURE'] = '1'
            execute([str(fixture), str(candidate), 'verify-up'], 'controlled-failure', expected=1)
            if 'controlled fixture failure' not in (REPORT / (prefix + 'controlled-failure.log')).read_text():
                raise RuntimeError('Negative test failed for the wrong reason')
            evidence['status'] = 'passed'
        except BaseException:
            evidence['status'] = 'failed'
            raise
        finally:
            try:
                if created:
                    sql('DROP DATABASE `' + name + '`')
                    evidence['synthetic_database_removed'] = name
            except BaseException as error:
                evidence['status'] = 'failed'
                evidence['cleanup_failure_class'] = type(error).__name__
                evidence['synthetic_database_needing_cleanup'] = name
                raise
            finally:
                write(prefix + 'evidence.json', evidence)


if __name__ == '__main__':
    main()
