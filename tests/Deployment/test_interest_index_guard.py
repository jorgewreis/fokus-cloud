"""Exercise the destructive concurrency harness guard before bootstrap."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


SOURCE = Path(__file__).with_name('interest-index-concurrency.php')
DEADLOCK_SOURCE = Path(__file__).with_name('interest-index-deadlock.php')


class InterestIndexGuardTest(unittest.TestCase):
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
