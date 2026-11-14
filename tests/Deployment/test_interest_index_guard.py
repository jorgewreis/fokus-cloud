"""Exercise the destructive concurrency harness guard before bootstrap."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


SOURCE = Path(__file__).with_name('interest-index-concurrency.php')
DEADLOCK_SOURCE = Path(__file__).with_name('interest-index-deadlock.php')


class InterestIndexGuardTest(unittest.TestCase):
    def test_actual_php_attestation_rejects_missing_identity_before_connection(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            helper = root / 'guard.php'
            source = Path(__file__).with_name('release-disposable-mysql.php').read_text(encoding='utf-8')
            # Change only the identity path in a temporary copy; never touch /run.
            marker = '/run/fokus-release-mysql.identity'
            self.assertEqual(source.count(marker), 1)
            missing = (root / 'missing-identity').as_posix()
            helper.write_text(source.replace(marker, missing), encoding='utf-8')
            process = subprocess.run(['php', '-r', 'require $argv[1]; assertReleaseDisposableMysql();', str(helper)],
                                     capture_output=True, text=True, timeout=15)
            self.assertNotEqual(process.returncode, 0)
            self.assertIn('refuse non-disposable mysql identity', process.stderr)
            self.assertNotIn('Class "Illuminate', process.stderr)

    def test_concurrency_collision_never_deletes_unowned_row(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            target = root / 'tests' / 'Deployment' / SOURCE.name
            target.parent.mkdir(parents=True)
            shutil.copyfile(SOURCE, target)
            (target.parent / 'release-disposable-mysql.php').write_text('<?php function assertReleaseDisposableMysql(): void {}')
            (root / 'vendor').mkdir()
            shutil.copyfile(Path(__file__).with_name('interest-concurrency-fake-bootstrap.fixture'), root / 'vendor' / 'autoload.php')
            (root / 'bootstrap').mkdir()
            (root / 'bootstrap' / 'app.php').write_text('<?php return new class { public function make($name) { return new class { public function bootstrap() {} }; } };')
            log = root / 'operations.log'
            log.write_text('')
            env = {'PATH': os.environ.get('PATH', ''), 'APP_ENV': 'testing',
                   'FOKUS_INTEREST_INDEX_DISPOSABLE': '1', 'DB_CONNECTION': 'mysql',
                   'DB_HOST': 'mysql', 'DB_DATABASE': 'synthetic', 'DB_PORT': '3306',
                   'FAKE_LOG': str(log), 'TMP': str(root), 'TEMP': str(root), 'TMPDIR': str(root)}
            result = subprocess.run(['php', str(target)], env=env, capture_output=True, text=True, timeout=15)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('synthetic id collision', result.stderr)
            self.assertEqual(log.read_text(), '')

    def test_deadlock_cleanup_only_removes_owned_objects_on_collision(self):
        self.assertIsNotNone(shutil.which('php'))
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            target = root / 'tests' / 'Deployment' / DEADLOCK_SOURCE.name
            target.parent.mkdir(parents=True)
            shutil.copyfile(DEADLOCK_SOURCE, target)
            # Ownership is tested independently of the real server attestation.
            # This stub exists only in the temporary copy, never in the source tree.
            (target.parent / 'release-disposable-mysql.php').write_text('<?php function assertReleaseDisposableMysql(): void {}')
            (root / 'vendor').mkdir()
            shutil.copyfile(Path(__file__).with_name('interest-deadlock-fake-bootstrap.fixture'), root / 'vendor' / 'autoload.php')
            (root / 'bootstrap').mkdir()
            (root / 'bootstrap' / 'app.php').write_text('<?php return new class { public function make($name) { return new class { public function bootstrap() {} }; } };')
            sandbox = root / 'sandbox'
            sandbox.mkdir()
            (sandbox / '.disposable-upgrade').touch()
            log = root / 'operations.log'
            base = {'PATH': os.environ.get('PATH', ''), 'APP_ENV': 'testing',
                    'FOKUS_INTEREST_INDEX_DISPOSABLE': '1', 'RELEASE_DISPOSABLE_TESTS': '1',
                    'DB_CONNECTION': 'mysql', 'DB_HOST': '127.0.0.1',
                    'DB_DATABASE': 'fokus_upgrade_0123456789abcdef', 'DB_PORT': '3306',
                    'UPGRADE_SANDBOX': str(sandbox), 'FAKE_LOG': str(log),
                    'TMP': str(root), 'TEMP': str(root), 'TMPDIR': str(root)}
            for case in ('range', 'table', 'row'):
                with self.subTest(case=case):
                    log.write_text('')
                    result = subprocess.run(['php', str(target)], env={**base, 'FAKE_CASE': case},
                                            capture_output=True, text=True, timeout=15)
                    self.assertNotEqual(result.returncode, 0)
                    self.assertIn({'range': 'fixture range is occupied', 'table': 'table occupied', 'row': 'row occupied'}[case], result.stderr)
                    operations = log.read_text().splitlines()
                    if case == 'range':
                        self.assertEqual(operations, [])
                    elif case == 'table':
                        self.assertEqual(len(operations), 1)
                        self.assertTrue(operations[0].startswith('CREATE TABLE'))
                    else:
                        inserts = [line.removeprefix('INSERT ') for line in operations if line.startswith('INSERT ')]
                        deletes = [line for line in operations if line.startswith('DELETE product_interests ')]
                        self.assertEqual(len(inserts), 1)
                        self.assertEqual(deletes, ['DELETE product_interests '+json.dumps(inserts, separators=(',', ':'))])
                        self.assertEqual(sum(line.startswith('DROP TABLE ') for line in operations), 1)

    def test_connection_overrides_are_rejected_before_bootstrap(self):
        self.assertIsNotNone(shutil.which('php'), 'PHP is required for the guard check')
        with tempfile.TemporaryDirectory() as temporary:
            target = Path(temporary) / 'tests' / 'Deployment' / SOURCE.name
            target.parent.mkdir(parents=True)
            shutil.copyfile(SOURCE, target)
            # No vendor, environment file, database, or network is provided.
            # A guard failure must occur before an attempt to load autoload.php.
            base = {'PATH': os.environ.get('PATH', ''), 'APP_ENV': 'testing',
                    'FOKUS_INTEREST_INDEX_DISPOSABLE': '1', 'DB_CONNECTION': 'mysql',
                    'DB_HOST': 'mysql', 'DB_DATABASE': 'synthetic', 'DB_PORT': '3306'}
            for variable, value in [('DB_URL', 'mysql://invalid.invalid/real'),
                                    ('DB_URL', '0'),
                                    ('DB_SOCKET', '/tmp/other.sock'),
                                    ('DB_SOCKET', '0'), ('DB_PORT', '0'),
                                    ('DB_PORT', '3307'), ('DB_CONNECTION', 'sqlite')]:
                with self.subTest(variable=variable):
                    process = subprocess.run(['php', str(target)], env={**base, variable: value},
                                             capture_output=True, text=True, timeout=15)
                    self.assertNotEqual(process.returncode, 0)
                    self.assertIn('refuse non-disposable context', process.stderr)
                    self.assertNotIn('autoload.php', process.stderr)

    def test_deadlock_connection_overrides_are_rejected_before_bootstrap(self):
        self.assertIsNotNone(shutil.which('php'), 'PHP is required for the guard check')
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            target = root / 'tests' / 'Deployment' / DEADLOCK_SOURCE.name
            target.parent.mkdir(parents=True)
            shutil.copyfile(DEADLOCK_SOURCE, target)
            sandbox = root / 'sandbox'
            sandbox.mkdir()
            (sandbox / '.disposable-upgrade').touch()
            base = {'PATH': os.environ.get('PATH', ''), 'APP_ENV': 'testing',
                    'FOKUS_INTEREST_INDEX_DISPOSABLE': '1', 'RELEASE_DISPOSABLE_TESTS': '1',
                    'DB_CONNECTION': 'mysql', 'DB_HOST': '127.0.0.1',
                    'DB_DATABASE': 'fokus_upgrade_0123456789abcdef', 'DB_PORT': '3306',
                    'UPGRADE_SANDBOX': str(sandbox)}
            for variable, value in [('DB_URL', 'mysql://invalid.invalid/real'), ('DB_URL', '0'),
                                    ('DB_SOCKET', '/tmp/other.sock'), ('DB_SOCKET', '0'),
                                    ('DB_PORT', '0'), ('DB_PORT', '3307')]:
                with self.subTest(variable=variable, value=value):
                    process = subprocess.run(['php', str(target)], env={**base, variable: value},
                                             capture_output=True, text=True, timeout=15)
                    self.assertNotEqual(process.returncode, 0)
                    self.assertIn('refuse non-disposable context', process.stderr)
                    self.assertNotIn('autoload.php', process.stderr)
            # Prove the other prerequisites are valid; there is intentionally no
            # autoloader, so an allowed target cannot reach any database.
            baseline = subprocess.run(['php', str(target)], env=base,
                                      capture_output=True, text=True, timeout=15)
            self.assertNotEqual(baseline.returncode, 0)
            self.assertIn('autoload.php', baseline.stderr)
            self.assertNotIn('refuse non-disposable context', baseline.stderr)


if __name__ == '__main__':
    unittest.main()
