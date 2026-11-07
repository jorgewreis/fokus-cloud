"""Non-destructive negative controls for release harness boundaries and adapters."""
import os
import importlib.util
import json
import xml.etree.ElementTree as ET
import pathlib
import tempfile
import unittest
from unittest.mock import patch
import linux_release_common as common

spec = importlib.util.spec_from_file_location('linux_suite', common.ROOT / 'tests/Deployment/linux-release-suite.py')
suite = importlib.util.module_from_spec(spec)
spec.loader.exec_module(suite)


class HarnessContracts(unittest.TestCase):
    def test_missing_disposable_opt_in_fails(self):
        with patch.dict(os.environ, {}, clear=True):
            with self.assertRaisesRegex(RuntimeError, 'RELEASE_DISPOSABLE_TESTS'):
                common.guard()

    def test_non_linux_host_fails(self):
        with patch.dict(os.environ, {'RELEASE_DISPOSABLE_TESTS': '1'}), patch.object(common.os, 'name', 'nt'):
            with self.assertRaisesRegex(RuntimeError, 'Linux'):
                common.guard()

    def test_environment_does_not_inherit_production_targets(self):
        with patch.dict(os.environ, {'RELEASE_CACHE_STORE': 'database', 'DB_URL': 'do-not-use', 'DB_HOST': 'production.example', 'DATABASE_URL': 'do-not-use', 'REDIS_HOST': 'production.example', 'APP_KEY': 'do-not-use', 'DATAJUD_API_KEY': 'do-not-use'}):
            env = common.environment('mysql')
        self.assertEqual(env['DB_HOST'], '127.0.0.1')
        self.assertEqual(env['REDIS_HOST'], '127.0.0.1')
        self.assertEqual(env['DB_URL'], '')
        self.assertEqual(env['DATABASE_URL'], '')
        self.assertEqual(env['DATAJUD_API_KEY'], '')
        self.assertEqual(env['DB_DATABASE'], 'fokuscloud_security')
        self.assertNotEqual(env['APP_KEY'], 'do-not-use')

    def test_mysql_requires_explicit_valid_cache_store(self):
        for value in (None, '', 'array', 'file', 'Redis', 'database ', 'production'):
            with self.subTest(value=value), patch.dict(os.environ, {}, clear=True):
                if value is not None:
                    os.environ['RELEASE_CACHE_STORE'] = value
                with self.assertRaisesRegex(RuntimeError, 'RELEASE_CACHE_STORE'):
                    common.environment('mysql')

    def test_both_mysql_profiles_select_database_sessions(self):
        for store in ('database', 'redis'):
            with self.subTest(store=store), patch.dict(os.environ, {'RELEASE_CACHE_STORE': store}):
                env = common.environment('mysql')
                self.assertEqual(env['CACHE_STORE'], store)
                self.assertEqual(env['CACHE_LIMITER'], store)
                self.assertEqual(env['SESSION_DRIVER'], 'database')

    def test_database_has_json_adapter_but_no_clock_adapter(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {'RELEASE_CACHE_STORE': 'database'}):
            temporary = pathlib.Path(directory)
            env = common.environment('mysql')
            tree = ET.parse(common.ROOT / 'phpunit.xml')
            adapters = suite.prepare_adapters('mysql', env, tree, temporary)
            self.assertEqual([a['source'] for a in adapters], ['tests/Feature/CatalogAdminTest.php'])
            self.assertNotIn('HOMOLOGATION_WALL_CLOCK_LOG', env)
            self.assertFalse((temporary / 'PlatformSecurityTest.php').exists())
            self.assertEqual(len(tree.findall('.//exclude')), 1)
            original = (common.ROOT / 'tests/Feature/PlatformSecurityTest.php').read_text(encoding='utf-8')
            self.assertIn('$this->travel(61)->seconds();', original)
            self.assertNotIn('sleep(61)', original)

    def test_redis_has_json_and_real_clock_adapters(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {'RELEASE_CACHE_STORE': 'redis'}):
            temporary = pathlib.Path(directory)
            env = common.environment('mysql')
            tree = ET.parse(common.ROOT / 'phpunit.xml')
            with patch.object(suite, 'REPORT', temporary):
                adapters = suite.prepare_adapters('mysql', env, tree, temporary)
            self.assertEqual(len(adapters), 2)
            adapted = (temporary / 'PlatformSecurityTest.php').read_text(encoding='utf-8')
            self.assertIn('sleep(61);', adapted)
            self.assertNotIn('$this->travel(61)->seconds();', adapted)
            self.assertIn("'cache_deleted_before_assertion' => false", adapted)
            self.assertEqual(len(tree.findall('.//exclude')), 2)
            for record in adapters:
                source = common.ROOT / record['source']
                # Adapter text cannot remove or weaken any original assertion.
                original_assertions = [line.strip() for line in source.read_text(encoding='utf-8').splitlines() if 'assert' in line]
                adapted_assertions = [line.strip() for line in (temporary / source.name).read_text(encoding='utf-8').splitlines() if 'assert' in line and 'cache_deleted_before_assertion' not in line]
                self.assertEqual(original_assertions, adapted_assertions)

    def test_effective_configuration_mismatch_fails_closed(self):
        selected = {'cache.default': 'database', 'cache.limiter': 'database', 'session.driver': 'database'}
        with tempfile.TemporaryDirectory() as directory:
            temporary = pathlib.Path(directory)
            (temporary / 'suite-mysql-effective-configuration.log').write_text(json.dumps({**selected, 'cache.default': 'redis'}))
            with patch.object(suite, 'REPORT', temporary), patch.object(suite, 'run'), patch.object(suite, 'write') as writer:
                with self.assertRaisesRegex(RuntimeError, 'differs'):
                    suite.verify_configuration({}, selected, temporary, 'mysql')
                self.assertFalse(writer.call_args.args[1]['matches'])

    def test_effective_configuration_rejects_unknown_keys(self):
        selected = {'cache.default': 'database', 'cache.limiter': 'database', 'session.driver': 'database'}
        with tempfile.TemporaryDirectory() as directory:
            temporary = pathlib.Path(directory)
            (temporary / 'suite-mysql-effective-configuration.log').write_text(json.dumps({**selected, 'unexpected': 'value'}))
            with patch.object(suite, 'REPORT', temporary), patch.object(suite, 'run'), patch.object(suite, 'write') as writer:
                with self.assertRaisesRegex(RuntimeError, 'Unexpected'):
                    suite.verify_configuration({}, selected, temporary, 'mysql')
                writer.assert_not_called()

    def test_unknown_or_duplicate_adapter_anchor_fails(self):
        with tempfile.TemporaryDirectory() as directory:
            directory = pathlib.Path(directory)
            source = directory / 'input.php'
            destination = directory / 'output.php'
            source.write_text('needle needle')
            with self.assertRaisesRegex(RuntimeError, 'anchor changed'):
                common.adapt(source, destination, [('needle', 'replacement')])
            with self.assertRaisesRegex(RuntimeError, 'anchor changed'):
                common.adapt(source, destination, [('missing', 'replacement')])
            self.assertFalse(destination.exists())

    def test_original_adapter_anchors_remain_unique(self):
        anchors = {
            'tests/Feature/CatalogAdminTest.php': "DB::table('catalog_publications')->where('id', $publication->id)->update(['snapshot' => $stored]);",
            'tests/Feature/PlatformSecurityTest.php': '$this->travel(61)->seconds();',
            'tests/Deployment/reversal-harness.php': 'str_contains(strtolower($event->sql), $failAt)',
        }
        for filename, anchor in anchors.items():
            with self.subTest(filename=filename):
                self.assertEqual((common.ROOT / filename).read_text(encoding='utf-8').count(anchor), 1)


if __name__ == '__main__':
    unittest.main()
