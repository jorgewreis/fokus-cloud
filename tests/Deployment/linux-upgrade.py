"""Exercise origin-shaped history, five migrations, reversal and synthetic restore."""
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
from linux_release_common import ROOT, REPORT, BASELINE, guard, environment, php, run, adapt, write, sha, safe_extract_source_archive

from linux_vendor import copy_verified_vendor

ORIGIN_METADATA = {
    'mysql_version': '8.4.10-10',
    'migration_ledger_count': 79,
    'migration_names_sorted_sha256': 'cf89b9fb8d75b4456165f05e1f04aefed383dae439bcdb59134763eb76a992d1',
    'migration_ledger_sha256': '9ad2b5f92c759eed475ac19dad2352b22363186f420c11bcce50243d2e6bdd45',
    'schema_column_count': 1110,
    'schema_metadata_sha256': '323ce5b8442395943f1cfdff9e49f9cfd7710eac6643a42653f279e172798d7f',
}
REPAIR_MIGRATION = '2026_11_07_000100_repair_payments_provider_checkout_url.php'
EXPECTED_NEW_MIGRATIONS = [
    '2026_11_06_000200_separate_law_case_confidentiality.php',
    '2026_11_06_140000_create_law_case_publication_reviews.php',
    '2026_11_06_160000_create_law_contact_import_batches_table.php',
    '2026_11_06_170000_create_law_tasks.php',
    REPAIR_MIGRATION,
]


def require_new_migrations(names):
    if names != EXPECTED_NEW_MIGRATIONS:
        raise RuntimeError('Expected exactly five named candidate migrations including the additive checkout repair')


def require_origin_shape(result):
    """A batch/version difference stays explicit; schema/name drift stops the run."""
    observed = result.get('observed_synthetic', {})
    required = ('migration_ledger_count', 'migration_names_sorted_sha256', 'schema_column_count', 'schema_metadata_sha256')
    if any(observed.get(key) != ORIGIN_METADATA[key] for key in required):
        raise RuntimeError('Synthetic origin fixture does not match reviewed names/schema metadata')
    return {'origin_shape': 'exact-reviewed-names-and-global-column-metadata',
            'ledger_batch_equivalence': observed.get('migration_ledger_sha256') == ORIGIN_METADATA['migration_ledger_sha256'],
            'batch_limit': 'Origin batches were not supplied; synthetic batches retained without fabrication.',
            'production_release_gate': 'not-approved-by-synthetic-test'}


def metadata_digest(rows):
    # Mirrors PHP JSON_UNESCAPED_SLASHES: compact, escaped Unicode, original types/order.
    return hashlib.sha256(json.dumps(rows, ensure_ascii=True, separators=(',', ':')).encode('utf-8')).hexdigest()


def assess_baseline_metadata(data, expected=ORIGIN_METADATA):
    required = {'mysql_version', 'migration_ledger', 'schema_columns', 'migration_ledger_sha256', 'schema_metadata_sha256'}
    if not isinstance(data, dict) or set(data) != required:
        raise ValueError('Unexpected synthetic metadata fields')
    version = data['mysql_version']
    if not isinstance(version, str) or not version or len(version) > 100 or any(not (c.isascii() and (c.isalnum() or c in '.+_:~ -')) for c in version):
        raise ValueError('Invalid MySQL version metadata')
    ledger, columns = data['migration_ledger'], data['schema_columns']
    if not isinstance(ledger, list) or not isinstance(columns, list) or not ledger or not columns:
        raise ValueError('Missing synthetic ledger/schema metadata')
    for row in ledger:
        if not isinstance(row, list) or len(row) != 2 or not isinstance(row[0], str) or not row[0] or not all(c.isascii() and (c.isalnum() or c == '_') for c in row[0]):
            raise ValueError('Invalid migration metadata row')
        if type(row[1]) not in (str, int) or not str(row[1]).isdigit() or int(row[1]) < 1:
            raise ValueError('Invalid migration batch metadata')
    if [r[0] for r in ledger] != sorted(r[0] for r in ledger) or len({r[0] for r in ledger}) != len(ledger):
        raise ValueError('Migration metadata is unordered or duplicated')
    for row in columns:
        if not isinstance(row, list) or len(row) != 4 or any(not isinstance(v, str) or not v or len(v) > 4096 or any(ord(c) < 32 or ord(c) == 127 for c in v) for v in row) or row[3] not in ('YES', 'NO'):
            raise ValueError('Invalid column metadata row')
        if any(not all(c.isascii() and (c.isalnum() or c == '_') for c in v) for v in row[:2]):
            raise ValueError('Invalid schema identifier')
    if [r[0] for r in columns] != sorted(r[0] for r in columns) or len({tuple(r[:2]) for r in columns}) != len(columns):
        raise ValueError('Column metadata is unordered or duplicated')
    if data['migration_ledger_sha256'] != metadata_digest(ledger) or data['schema_metadata_sha256'] != metadata_digest(columns):
        raise ValueError('Synthetic metadata digest does not match captured rows')
    observed = {'mysql_version': version, 'migration_ledger_count': len(ledger), 'migration_ledger_sha256': data['migration_ledger_sha256'], 'schema_column_count': len(columns), 'schema_metadata_sha256': data['schema_metadata_sha256']}
    observed['migration_names_sorted_sha256'] = metadata_digest(sorted(r[0] for r in ledger))
    differences = [{'field': key, 'expected_origin': value, 'observed_synthetic': observed[key]} for key, value in expected.items() if observed[key] != value]
    metadata_match = all(observed[k] == expected[k] for k in expected if k != 'mysql_version')
    return {'origin_equivalence': 'blocked' if differences else 'metadata-and-version-match-only',
            'production_release_gate': 'not-approved-by-synthetic-test',
            'literal_metadata_match': metadata_match, 'mysql_version_match': version == expected['mysql_version'],
            'expected_origin': expected, 'observed_synthetic': observed, 'sanitized_differences': differences,
            'migration_names_sorted_sha256': metadata_digest(sorted(r[0] for r in ledger)),
            'schema_column_names_sorted_sha256': metadata_digest(sorted([r[:2] for r in columns])),
            'comparison_limitations': ['Only reviewed origin digests are bundled here; detailed origin metadata is compared separately, never treated as application fixture data.', 'Ledger hash includes batch and PDO value types; historical batch differences require explicit review.', 'Schema query preserves table_name,ordinal_position order; no row data is queried.', 'Even a match does not approve production backup, runtime, release or deployment.']}


def capture_baseline_metadata(base, env, sandbox, prefix):
    # Exact read-only SQL/JSON hashing algorithm from the reviewed origin probe.
    script = sandbox / 'baseline-metadata.php'
    script.write_text(r'''<?php
try {
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1' || !preg_match('/^fokus_upgrade_[a-f0-9]{16}$/D', getenv('DB_DATABASE'))) throw new RuntimeException('guard');
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.getenv('DB_DATABASE').';charset=utf8mb4', getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>5, Pdo\Mysql::ATTR_MULTI_STATEMENTS=>false]);
    $pdo->exec('START TRANSACTION READ ONLY');
    $version = $pdo->query('SELECT VERSION()')->fetchColumn();
    $rows = $pdo->query('SELECT migration,batch FROM migrations ORDER BY migration,batch')->fetchAll(PDO::FETCH_NUM);
    $schema = $pdo->query("SELECT table_name,column_name,column_type,is_nullable FROM information_schema.columns WHERE table_schema=DATABASE() ORDER BY table_name,ordinal_position")->fetchAll(PDO::FETCH_NUM);
    $pdo->rollBack();
    echo json_encode(['mysql_version'=>$version, 'migration_ledger'=>$rows, 'schema_columns'=>$schema, 'migration_ledger_sha256'=>hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)), 'schema_metadata_sha256'=>hash('sha256',json_encode($schema,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR, 'Synthetic baseline metadata capture failed'.PHP_EOL); exit(1); }
''', encoding='utf-8')
    try:
        run(php() + [str(script)], prefix + 'baseline-metadata', env, base)
        data = json.loads((REPORT / (prefix + 'baseline-metadata.log')).read_text(encoding='utf-8'))
        result = assess_baseline_metadata(data)
        write(prefix + 'baseline-synthetic-metadata.json', data)
    except (RuntimeError, ValueError, OSError) as error:
        result = {'origin_equivalence': 'blocked', 'production_release_gate': 'not-approved-by-synthetic-test', 'capture_or_validation_error': type(error).__name__, 'expected_origin': ORIGIN_METADATA}
    try:
        run(php() + [str(ROOT / 'tests/Deployment/baseline-target-metadata.php')], prefix + 'baseline-target-metadata', env, base)
        result['detailed_metadata'] = {'status': 'captured-synthetic-only', 'log': prefix + 'baseline-target-metadata.log', 'comparison': 'External comparison required; no origin rows embedded in repository'}
    except RuntimeError:
        result['detailed_metadata'] = {'status': 'blocked-capture-failed'}
        result['origin_equivalence'] = 'blocked'
    result['profile_cache_store'] = os.environ.get('RELEASE_CACHE_STORE')
    result['origin_evidence_source'] = 'Reviewed ledger-allowlist-result.txt; origin production probe read-only metadata only'
    write(prefix + 'origin-metadata-comparison.json', result)
    return result


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
    env.update(CACHE_STORE='array', CACHE_LIMITER='array', SESSION_DRIVER='array', RELEASE_DISPOSABLE_TESTS='1')
    fixture = ROOT / 'tests/Deployment/upgrade-fixture.php'
    origin_fixture = ROOT / 'tests/Deployment/origin-shaped-fixture.php'
    reversal = ROOT / 'tests/Deployment/reversal-harness.php'
    evidence.update(fixture_sha256=sha(fixture), reversal_source_sha256=sha(reversal), steps=[])
    evidence['origin_fixture_sources'] = {path.name: sha(path) for path in (origin_fixture, ROOT / 'tests/Deployment/origin-schema-contract.php')}
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
            evidence['baseline_archive_extraction'] = safe_extract_source_archive(stream, base)
        evidence['baseline_archive_sha256'] = sha(archive)
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
        dependencies = pathlib.Path(os.environ.get('RELEASE_DEPENDENCY_ROOT', ROOT.parent / 'release-dependencies')).resolve()
        evidence['dependency_pairs'] = {}
        for role, runtime in [('baseline', base), ('candidate', candidate)]:
            evidence['dependency_pairs'][role] = copy_verified_vendor(dependencies / role, runtime)
            for part in ('bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'storage/app/private', 'storage/app/public'):
                (runtime / part).mkdir(parents=True, exist_ok=True)
            if (runtime / '.env').exists() or (runtime / 'bootstrap/cache/config.php').exists():
                raise RuntimeError('Unsafe runtime configuration copied')
        new = sorted(p.name for p in (candidate / 'database/migrations').glob('*.php') if not (base / 'database/migrations' / p.name).exists())
        require_new_migrations(new)
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
            execute([str(origin_fixture), str(candidate), 'fresh-noop'], 'repair-fresh-noop')
            execute([str(origin_fixture), str(candidate), 'prepare-origin'], 'prepare-origin-shape')
            if args.driver == 'mysql':
                evidence['origin_metadata_comparison'] = capture_baseline_metadata(base, env, sandbox, prefix)
                evidence['origin_shape_gate'] = require_origin_shape(evidence['origin_metadata_comparison'])
            execute([str(origin_fixture), str(candidate), 'billing-before'], 'repair-billing-before')
            execute(['artisan', 'migrate', '--force', '--no-interaction'], 'five-migrations')
            execute([str(fixture), str(candidate), 'verify-up'], 'verify-up')
            execute([str(origin_fixture), str(candidate), 'billing-after'], 'repair-billing-after')
            execute([str(origin_fixture), str(candidate), 'verify-up'], 'repair-origin-verify')
            execute(['artisan', 'migrate', '--force', '--no-interaction'], 'idempotence-migrate')
            execute([str(fixture), str(candidate), 'verify-up'], 'idempotence-verify')
            execute([str(origin_fixture), str(candidate), 'verify-idempotent'], 'repair-origin-idempotent')
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
            if (sandbox / 'origin-reversal-delta.json').exists():
                evidence['reversal_authorized_delta'] = json.loads((sandbox / 'origin-reversal-delta.json').read_text(encoding='utf-8'))
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
            execute([str(origin_fixture), str(candidate), 'verify-idempotent'], 'restored-backup-repair-verify')
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
                for name in ('origin-schema-before', 'origin-schema-after', 'origin-reversal-delta', 'origin-temporal-before'):
                    path = sandbox / (name + '.json')
                    if path.is_file():
                        write(prefix + name + '.json', json.loads(path.read_text(encoding='utf-8')))
                write(prefix + 'evidence.json', evidence)


if __name__ == '__main__':
    main()
