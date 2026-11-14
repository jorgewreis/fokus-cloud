"""Exercise the destructive concurrency harness guard before bootstrap."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


SOURCE = Path(__file__).with_name('interest-index-concurrency.php')


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


if __name__ == '__main__':
    unittest.main()
