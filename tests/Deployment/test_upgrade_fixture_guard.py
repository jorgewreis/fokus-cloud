"""Exercise upgrade fixture guards without loading Laravel or connecting to a database."""
import os
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

SOURCE = Path(__file__).with_name('upgrade-fixture.php')

class UpgradeFixtureGuardTest(unittest.TestCase):
    def test_effective_connection_redirect_fails_before_query(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            sandbox = root / 'sandbox'
            sandbox.mkdir()
            (sandbox / '.disposable-upgrade').touch()
            (root / 'vendor').mkdir()
            (root / 'bootstrap').mkdir()
            (root / 'vendor' / 'autoload.php').write_text('''<?php
namespace Illuminate\\Contracts\\Console { interface Kernel {} }
namespace Illuminate\\Support\\Facades {
 class DB {
  public static function connection() { return new class { public function getConfig() { return json_decode(getenv('FAKE_CONFIG'), true); } }; }
  public static function selectOne($sql) { throw new \\RuntimeException('unexpected database query'); }
 }
}
namespace { function config($key) { return 'mysql'; } }
''')
            (root / 'bootstrap' / 'app.php').write_text('<?php return new class { public function make($name) { return new class { public function bootstrap() {} }; } };')
            base = {'PATH': os.environ.get('PATH', ''), 'APP_ENV': 'testing',
                    'RELEASE_DISPOSABLE_TESTS': '1', 'UPGRADE_SANDBOX': str(sandbox),
                    'DB_CONNECTION': 'mysql', 'DB_HOST': '127.0.0.1', 'DB_PORT': '3306',
                    'DB_DATABASE': 'fokus_upgrade_0123456789abcdef'}
            config = {'driver': 'mysql', 'host': '127.0.0.1', 'port': '3306',
                      'database': base['DB_DATABASE'], 'unix_socket': '', 'url': None}
            for key, value in [('host', 'other.invalid'), ('port', '3307'), ('database', 'production'),
                               ('unix_socket', '/tmp/other.sock'), ('url', 'mysql://other.invalid/db'),
                               ('read', {'host': 'other.invalid'}), ('write', {'host': 'other.invalid'})]:
                with self.subTest(key=key):
                    env = {**base, 'FAKE_CONFIG': json.dumps({**config, key: value})}
                    result = subprocess.run(['php', str(SOURCE), str(root), 'versions'], env=env,
                                            capture_output=True, text=True, timeout=15)
                    self.assertNotEqual(result.returncode, 0)
                    self.assertIn('guard: disposable database', result.stderr)
                    self.assertNotIn('unexpected database query', result.stderr)

    def test_redirects_and_inherited_configuration_fail_before_autoload(self):
        self.assertIsNotNone(shutil.which('php'))
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            sandbox = root / 'sandbox'
            sandbox.mkdir()
            (sandbox / '.disposable-upgrade').touch()
            (sandbox / 'upgrade.sqlite').touch()
            base = {'PATH': os.environ.get('PATH', ''), 'APP_ENV': 'testing',
                    'RELEASE_DISPOSABLE_TESTS': '1', 'UPGRADE_SANDBOX': str(sandbox),
                    'DB_CONNECTION': 'mysql', 'DB_HOST': '127.0.0.1', 'DB_PORT': '3306',
                    'DB_DATABASE': 'fokus_upgrade_0123456789abcdef'}
            def invoke(env):
                return subprocess.run(['php', str(SOURCE), str(root), 'versions'], env=env,
                                      capture_output=True, text=True, timeout=15)
            for key, value in [('DB_URL', 'mysql://example.invalid/other'), ('DB_URL', '0'),
                               ('DB_SOCKET', '/tmp/other.sock'), ('DB_SOCKET', '0'),
                               ('DB_PORT', '3307'), ('DB_PORT', '0'), ('DB_HOST', 'example.invalid'),
                               ('DB_DATABASE', 'production'), ('DB_CONNECTION', 'pgsql'),
                               ('APP_ENV', 'production'), ('RELEASE_DISPOSABLE_TESTS', '0'),
                               ('APP_CONFIG_CACHE', '0'), ('APP_CONFIG_CACHE', '/tmp/other-config.php')]:
                with self.subTest(key=key, value=value):
                    result = invoke({**base, key: value})
                    self.assertNotEqual(result.returncode, 0)
                    self.assertIn('refuse non-disposable upgrade fixture', result.stderr)
                    self.assertNotIn('autoload.php', result.stderr)
            for path in (root / '.env', root / '.env.testing', root / 'bootstrap' / 'cache' / 'config.php'):
                path.parent.mkdir(parents=True, exist_ok=True)
                path.touch()
                result = invoke(base)
                self.assertIn('refuse non-disposable upgrade fixture', result.stderr)
                self.assertNotIn('autoload.php', result.stderr)
                path.unlink()
            for env in (base, {**base, 'DB_CONNECTION': 'sqlite', 'DB_DATABASE': str(sandbox / 'upgrade.sqlite')}):
                result = invoke(env)
                self.assertIn('autoload.php', result.stderr)
                self.assertNotIn('refuse non-disposable upgrade fixture', result.stderr)
            other = root / 'other.sqlite'
            other.touch()
            result = invoke({**base, 'DB_CONNECTION': 'sqlite', 'DB_DATABASE': str(other)})
            self.assertIn('refuse non-disposable upgrade fixture', result.stderr)
            self.assertNotIn('autoload.php', result.stderr)

    def test_broken_inherited_configuration_symlinks_fail_before_autoload(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            sandbox = root / 'sandbox'
            sandbox.mkdir()
            (sandbox / '.disposable-upgrade').touch()
            base = {'PATH': os.environ.get('PATH', ''), 'APP_ENV': 'testing',
                    'RELEASE_DISPOSABLE_TESTS': '1', 'UPGRADE_SANDBOX': str(sandbox),
                    'DB_CONNECTION': 'mysql', 'DB_HOST': '127.0.0.1', 'DB_PORT': '3306',
                    'DB_DATABASE': 'fokus_upgrade_0123456789abcdef'}
            for path in (root / '.env', root / '.env.testing', root / 'bootstrap' / 'cache' / 'config.php'):
                with self.subTest(path=str(path)):
                    path.parent.mkdir(parents=True, exist_ok=True)
                    try:
                        path.symlink_to(root / 'nonexistent-target')
                    except (OSError, NotImplementedError) as error:
                        self.skipTest('creating symlinks is unavailable: '+str(error))
                    try:
                        result = subprocess.run(['php', str(SOURCE), str(root), 'versions'], env=base,
                                                capture_output=True, text=True, timeout=15)
                        self.assertIn('refuse non-disposable upgrade fixture', result.stderr)
                        self.assertNotIn('autoload.php', result.stderr)
                    finally:
                        path.unlink()

if __name__ == '__main__':
    unittest.main()
