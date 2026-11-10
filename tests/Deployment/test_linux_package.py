"""Fail-closed production package boundary controls; no external services."""
import json
import os
import pathlib
import tempfile
import unittest
from unittest.mock import patch
import linux_package as package


class ProductionPackageContracts(unittest.TestCase):
    def test_unsafe_paths_rejected(self):
        for value in ('../x', '/x', 'a//b', 'a/./b', 'a/../b', 'C:/x', 'a\\b', ''):
            with self.subTest(value=value), self.assertRaises(RuntimeError):
                package.safe_relative(value)

    def test_private_qa_and_inherited_runtime_excluded(self):
        for path in ('.env', '.env.production', '.git/config', 'storage/app/private.json', 'vendor/autoload.php',
                     'node_modules/x', 'tests/x.php', 'public/uploads/private.pdf', 'bootstrap/cache/config.php',
                     'public/__pycache__/x.pyc', 'app/.env', 'config/private.key', 'database/database.sqlite',
                     'public/backups/database.sql', 'public/hot'):
            with self.subTest(path=path):
                self.assertFalse(package.source_allowed(path))

    def test_required_production_source_allowed(self):
        for path in ('composer.json', 'composer.lock', 'artisan', 'app/Models/User.php', 'routes/web.php',
                     'public/.htaccess', 'public/index.php', 'resources/views/portal/fokus-law.blade.php',
                     'database/migrations/example.php', 'public/build/assets/app-123.css'):
            self.assertTrue(package.source_allowed(path), path)

    def test_environment_does_not_inherit_real_credentials(self):
        with patch.dict(os.environ, {'DB_HOST': 'real-host', 'DB_DATABASE': 'real-data', 'COMPOSER_AUTH': 'secret',
                                     'MERCADO_PAGO_ACCESS_TOKEN': 'real-token', 'APP_ENV': 'testing'}):
            env = package.isolated_environment(pathlib.Path('/tmp/fake'), pathlib.Path('/tmp/runtime'))
        self.assertNotIn('DB_HOST', env)
        self.assertNotIn('COMPOSER_AUTH', env)
        self.assertEqual(env['APP_ENV'], 'production')
        self.assertEqual(env['DB_DATABASE'], ':memory:')
        self.assertEqual(env['MERCADO_PAGO_ACCESS_TOKEN'], '')

    def make_snapshot(self, root):
        runtime = {'name': 'runtime/pkg', 'version': '1.0.0', 'dist': {'reference': 'abc'}}
        development = {'name': 'development/pkg', 'version': '1.0.0', 'dist': {'reference': 'def'}}
        (root / 'vendor/composer').mkdir(parents=True)
        (root / 'composer.json').write_text('{}')
        (root / 'composer.lock').write_text(json.dumps({'packages': [runtime], 'packages-dev': [development]}))
        installed = {'dev': False, 'packages': [runtime]}
        (root / 'vendor/composer/installed.json').write_text(json.dumps(installed))
        return installed, development

    def test_no_dev_package_identity(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            installed, _ = self.make_snapshot(root)
            self.assertEqual(len(package.production_packages(root)), 1)
            installed['dev'] = True
            (root / 'vendor/composer/installed.json').write_text(json.dumps(installed))
            with self.assertRaisesRegex(RuntimeError, 'dev=false'):
                package.production_packages(root)

    def test_extra_dev_or_changed_package_rejected(self):
        for mutation in ('dev-package', 'changed-reference', 'missing-package'):
            with tempfile.TemporaryDirectory() as temporary:
                root = pathlib.Path(temporary)
                installed, development = self.make_snapshot(root)
                if mutation == 'dev-package': installed['packages'].append(development)
                elif mutation == 'changed-reference': installed['packages'][0]['dist']['reference'] = 'bad'
                else: installed['packages'] = []
                (root / 'vendor/composer/installed.json').write_text(json.dumps(installed))
                with self.assertRaisesRegex(RuntimeError, 'differ from lock'):
                    package.production_packages(root)

    def test_snapshot_corruption_and_lock_pairing_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            self.make_snapshot(root)
            package.write(root / 'production-receipt.json', {'snapshot': package.receipt(root)})
            package.verify_snapshot(root, root)
            (root / 'vendor/extra.php').write_text('corrupted')
            with self.assertRaisesRegex(RuntimeError, 'changed after setup'):
                package.verify_snapshot(root, root)
            (root / 'vendor/extra.php').unlink()
            other = root / 'other'; other.mkdir()
            (other / 'composer.json').write_text('not paired')
            with self.assertRaisesRegex(RuntimeError, 'another source lock'):
                package.verify_snapshot(root, other)

    @unittest.skipUnless(os.name == 'posix', 'POSIX symbolic links and modes required')
    def test_symlink_and_modes_fail_closed(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            (root / 'a').write_text('a'); (root / 'a').chmod(0o644)
            (root / 'link').symlink_to(root / 'a')
            with self.assertRaisesRegex(RuntimeError, 'Symbolic link'):
                package.real_file(root, 'link')
            (root / 'link').unlink(); (root / 'a').chmod(0o666)
            with self.assertRaisesRegex(RuntimeError, 'mode'):
                package.inventory(root)

    @unittest.skipUnless(os.name == 'posix', 'POSIX modes required')
    def test_deterministic_archives(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary) / 'source'; root.mkdir()
            (root / 'a').write_text('payload'); (root / 'a').chmod(0o644)
            first = root.parent / 'a.tar'; second = root.parent / 'b.tar'
            package.archive_package(root, first)
            os.utime(root / 'a', (1234, 1234))
            package.archive_package(root, second)
            self.assertEqual(package.sha(first), package.sha(second))

    def test_host_archives_exclude_python_cache(self):
        source = (package.ROOT / 'tests/Deployment/host-browser/run-ci-host.py').read_text()
        self.assertEqual(source.count("--exclude='*/__pycache__' --exclude='*.pyc' --exclude='*.pyo'"), 2)

    def test_changed_build_outputs_have_generator_allowlist(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            (root / 'resources/seo').mkdir(parents=True)
            (root / 'resources/seo/pages.json').write_text('[{"file":"index.html"}]')
            source = {'public/index.html': '', 'public/styles/example.html': '', 'public/assets/js/api.js': ''}
            allowed = package.build_output_allowlist(root, source)
            self.assertIn('public/index.html', allowed)
            self.assertIn('public/styles/example.html', allowed)
            self.assertNotIn('public/assets/js/api.js', allowed)
            self.assertNotIn('public/unknown.html', allowed)

    def test_build_requires_exact_vendor_pagination_templates(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            snapshot = root / 'snapshot'; source = root / 'source'; source.mkdir()
            relative = pathlib.Path('vendor/laravel/framework/src/Illuminate/Pagination/resources/views/default.blade.php')
            target = snapshot / relative; target.parent.mkdir(parents=True); target.write_text('reviewed')
            with self.assertRaisesRegex(RuntimeError, 'templates missing'):
                package.verify_build_templates(snapshot, source)
            target = source / relative; target.parent.mkdir(parents=True); target.write_text('reviewed')
            self.assertEqual(len(package.verify_build_templates(snapshot, source)), 1)
            target.write_text('changed')
            with self.assertRaisesRegex(RuntimeError, 'differs from production lock'):
                package.verify_build_templates(snapshot, source)

    def test_smoke_uses_delivered_autoload_before_regeneration(self):
        source = (package.ROOT / 'tests/Deployment/linux_package.py').read_text()
        smoke = source[source.index('def smoke('):source.index('def validate(')]
        self.assertLess(smoke.index("'production-smoke')"), smoke.index("'composer-autoload')"))
        self.assertIn('vendor_manifest(copy) != original_vendor', smoke)

    @unittest.skipUnless(os.name == 'posix', 'POSIX modes required')
    def test_forbidden_final_package_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            (root / 'bootstrap/cache').mkdir(parents=True)
            for p in root.rglob('*'): p.chmod(0o755)
            package.verify_package_boundary(root)
            (root / '.env').write_text('do not package'); (root / '.env').chmod(0o644)
            with self.assertRaisesRegex(RuntimeError, 'Forbidden packaged'):
                package.verify_package_boundary(root)


if __name__ == '__main__':
    unittest.main()
