"""Non-destructive negative controls for release harness boundaries and adapters."""
import ast
import os
import io
import tarfile
import importlib.util
import json
import xml.etree.ElementTree as ET
import pathlib
import tempfile
import unittest
from unittest.mock import patch
import linux_vendor as dependencies
import linux_release_common as common

spec = importlib.util.spec_from_file_location('linux_suite', common.ROOT / 'tests/Deployment/linux-release-suite.py')
suite = importlib.util.module_from_spec(spec)
spec.loader.exec_module(suite)
upgrade_spec = importlib.util.spec_from_file_location('linux_upgrade', common.ROOT / 'tests/Deployment/linux-upgrade.py')
upgrade = importlib.util.module_from_spec(upgrade_spec)
upgrade_spec.loader.exec_module(upgrade)


class CoverageComposition(unittest.TestCase):
    def report(self):
        report = ET.Element('testsuites')
        group = ET.SubElement(report, 'testsuite', tests=str(suite.EXPECTED_TESTS),
                              assertions=str(suite.MINIMUM_ASSERTIONS), failures='0', errors='0', skipped='0')
        for identity, assertions in {**suite.MAIN_IDENTITY_TESTS, **suite.LEAD_TESTS}.items():
            klass, name = identity.split('::')
            ET.SubElement(group, 'testcase', {'class': klass, 'name': name, 'assertions': str(assertions)})
        for number in range(327):
            ET.SubElement(group, 'testcase', {'class': 'SyntheticLawCoverage', 'name': str(number), 'assertions': '1'})
        return report

    def test_measured_composition_preserves_all_law_and_main_tests(self):
        self.assertEqual(suite.EXPECTED_TESTS, 351)
        self.assertEqual(suite.MINIMUM_ASSERTIONS, 2834)
        self.assertEqual(len(suite.MAIN_IDENTITY_TESTS), 10)
        self.assertEqual(sum(suite.MAIN_IDENTITY_TESTS.values()), 23)
        self.assertEqual(suite.coverage(self.report())['tests'], 351)

    def test_omission_duplicate_or_weakened_identity_is_rejected_even_with_green_totals(self):
        for mutation in ('omit', 'duplicate', 'rename', 'assertions'):
            with self.subTest(mutation=mutation):
                report = self.report(); group = report[0]; first, second = group[0], group[1]
                if mutation == 'omit': group.remove(first)
                elif mutation == 'duplicate': second.attrib.update(first.attrib)
                elif mutation == 'rename': first.set('name', 'another_green_test')
                else: first.set('assertions', '0')
                with self.assertRaises(RuntimeError): suite.coverage(report)

    def test_every_lead_case_is_required_with_its_assertions(self):
        self.assertEqual(len(suite.LEAD_TESTS), 14)
        self.assertEqual(sum(suite.LEAD_TESTS.values()), 58)
        for name in suite.LEAD_TESTS:
            for mutation in ('omit', 'rename', 'assertions'):
                with self.subTest(name=name, mutation=mutation):
                    report = self.report(); group = report[0]
                    case = next(c for c in group if c.get('class') + '::' + c.get('name') == name)
                    if mutation == 'omit': group.remove(case)
                    elif mutation == 'rename': case.set('name', 'unrelated_passing_case')
                    else: case.set('assertions', '0')
                    with self.assertRaises(RuntimeError): suite.coverage(report)

    def test_old_or_incomplete_totals_failures_errors_skips_and_hidden_failures_are_rejected(self):
        for key, value in [('tests','327'),('tests','336'),('tests','350'),('tests','352'),('assertions','2833'),
                           ('failures','1'),('errors','1'),('skipped','1')]:
            with self.subTest(key=key):
                report = self.report(); report[0].set(key,value)
                with self.assertRaises(RuntimeError): suite.coverage(report)
        for kind in ('failure', 'error', 'skipped'):
            report = self.report(); ET.SubElement(report[0][0], kind)
            with self.assertRaises(RuntimeError): suite.coverage(report)


class HarnessContracts(unittest.TestCase):
    def test_materialization_uses_extractor_ownership_before_qa_handoff(self):
        source = (common.ROOT / 'tests/Deployment/linux-release.py').read_text(encoding='utf-8')
        stages = {
            node.args[0].value: node for node in ast.walk(ast.parse(source))
            if isinstance(node, ast.Call) and isinstance(node.func, ast.Name)
            and node.func.id == 'run' and node.args
            and isinstance(node.args[0], ast.Constant)
        }
        materialize = stages['materialize']
        handoff = stages['prepare-synthetic-ownership']
        self.assertIn('tar --no-same-owner -xf source.tar', ast.literal_eval(materialize.args[1].args[-1]))
        self.assertNotIn('safe.directory', source)
        self.assertIn('chown -R 1000:1000 /work', ast.literal_eval(handoff.args[1].args[-1]))
        self.assertLess(materialize.lineno, stages['prepare-isolated-upgrade-dependencies'].lineno)
        self.assertLess(stages['prepare-isolated-upgrade-dependencies'].lineno, handoff.lineno)

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

    def test_database_schema_uses_real_non_destructive_migrations(self):
        with patch.dict(os.environ, {'RELEASE_CACHE_STORE': 'database', 'RELEASE_DISPOSABLE_TESTS': '1'}):
            env = common.environment('mysql')
            with patch.object(suite, 'run') as runner, patch.object(suite, 'write') as writer:
                suite.prepare_database_schema(env)
            command, label, migration_env = runner.call_args.args
            self.assertEqual(command[-4:], ['artisan', 'migrate', '--force', '--no-interaction'])
            self.assertNotIn('migrate:fresh', command)
            self.assertEqual(migration_env['CACHE_STORE'], 'array')
            self.assertEqual(env['CACHE_STORE'], 'database')
            self.assertTrue(writer.call_args.args[1]['exclusive_suite_lock'])

    def test_schema_rejects_non_synthetic_target_or_missing_opt_in(self):
        with patch.dict(os.environ, {'RELEASE_CACHE_STORE': 'database', 'RELEASE_DISPOSABLE_TESTS': '1'}):
            env = common.environment('mysql')
            for key, value in [('DB_HOST', 'remote'), ('DB_DATABASE', 'real'), ('DB_URL', 'remote'), ('APP_ENV', 'production'), ('DB_PORT', '3307')]:
                with self.subTest(key=key), patch.object(suite, 'run') as runner:
                    with self.assertRaisesRegex(RuntimeError, 'Unsafe target'):
                        suite.prepare_database_schema({**env, key: value})
                    runner.assert_not_called()
            with patch.dict(os.environ, {'RELEASE_DISPOSABLE_TESTS': '0'}), patch.object(suite, 'run') as runner:
                with self.assertRaisesRegex(RuntimeError, 'opt-in'):
                    suite.prepare_database_schema(env)
                runner.assert_not_called()

    def test_redis_does_not_prepare_database_cache_schema(self):
        with patch.dict(os.environ, {'RELEASE_CACHE_STORE': 'redis'}), patch.object(suite, 'run') as runner:
            suite.prepare_database_schema(common.environment('mysql'))
            runner.assert_not_called()

    def archive(self, members):
        data = io.BytesIO()
        with tarfile.open(fileobj=data, mode='w') as archive:
            for name, kind, payload in members:
                member = tarfile.TarInfo(name)
                member.type = kind
                member.mode = 0o4777
                if kind in (tarfile.SYMTYPE, tarfile.LNKTYPE):
                    member.linkname = payload
                if kind == tarfile.REGTYPE:
                    member.size = len(payload)
                    archive.addfile(member, io.BytesIO(payload))
                else:
                    archive.addfile(member)
        data.seek(0)
        return tarfile.open(fileobj=data, mode='r')

    def test_safe_archive_roundtrip_without_extractall(self):
        with tempfile.TemporaryDirectory() as directory:
            target = pathlib.Path(directory)
            with self.archive([('src/', tarfile.DIRTYPE, b''), ('src/test.php', tarfile.REGTYPE, b'<?php echo 1;')]) as archive, patch.object(archive, 'extractall', side_effect=AssertionError('unsafe extractor called')):
                evidence = common.safe_extract_source_archive(archive, target)
            self.assertEqual((target / 'src/test.php').read_bytes(), b'<?php echo 1;')
            self.assertEqual(evidence['members'], 2)

    def test_safe_archive_rejects_all_unsafe_members_before_writes(self):
        cases = [('..//escape', tarfile.REGTYPE, b'x'), ('../escape', tarfile.REGTYPE, b'x'), ('/absolute', tarfile.REGTYPE, b'x'), ('C:/absolute', tarfile.REGTYPE, b'x'), ('folder\\escape', tarfile.REGTYPE, b'x'), ('symlink', tarfile.SYMTYPE, '../outside'), ('hardlink', tarfile.LNKTYPE, 'ordinary'), ('device', tarfile.CHRTYPE, b''), ('block', tarfile.BLKTYPE, b''), ('fifo', tarfile.FIFOTYPE, b'')]
        for case in cases:
            with self.subTest(case=case), tempfile.TemporaryDirectory() as directory:
                target = pathlib.Path(directory)
                with self.archive([('ordinary', tarfile.REGTYPE, b'ok'), case]) as archive:
                    with self.assertRaises(RuntimeError):
                        common.safe_extract_source_archive(archive, target)
                self.assertEqual(list(target.iterdir()), [])

    def test_safe_archive_rejects_duplicate_and_nonempty_destination(self):
        with tempfile.TemporaryDirectory() as directory:
            target = pathlib.Path(directory)
            with self.archive([('same', tarfile.REGTYPE, b'1'), ('same', tarfile.REGTYPE, b'2')]) as archive:
                with self.assertRaisesRegex(RuntimeError, 'Duplicate'):
                    common.safe_extract_source_archive(archive, target)
            (target / 'existing').write_text('preserve')
            with self.archive([('new', tarfile.REGTYPE, b'1')]) as archive:
                with self.assertRaisesRegex(RuntimeError, 'empty real directory'):
                    common.safe_extract_source_archive(archive, target)
            self.assertEqual((target / 'existing').read_text(), 'preserve')

    def synthetic_metadata(self):
        ledger = [['2026_01_01_000000_create_examples', 1]]
        columns = [['examples', 'id', 'bigint unsigned', 'NO']]
        data = {'mysql_version': '8.4.10-10', 'migration_ledger': ledger, 'schema_columns': columns, 'migration_ledger_sha256': upgrade.metadata_digest(ledger), 'schema_metadata_sha256': upgrade.metadata_digest(columns)}
        expected = {'mysql_version': data['mysql_version'], 'migration_ledger_count': 1, 'migration_ledger_sha256': data['migration_ledger_sha256'], 'schema_column_count': 1, 'schema_metadata_sha256': data['schema_metadata_sha256']}
        return data, expected

    def test_metadata_exact_match_still_does_not_approve_production(self):
        data, expected = self.synthetic_metadata()
        result = upgrade.assess_baseline_metadata(data, expected)
        self.assertEqual(result['origin_equivalence'], 'metadata-and-version-match-only')
        self.assertTrue(result['literal_metadata_match'])
        self.assertEqual(result['production_release_gate'], 'not-approved-by-synthetic-test')

    def test_metadata_same_count_different_batch_is_blocked(self):
        data, expected = self.synthetic_metadata()
        before = upgrade.assess_baseline_metadata(data, expected)
        data['migration_ledger'][0][1] = 2
        data['migration_ledger_sha256'] = upgrade.metadata_digest(data['migration_ledger'])
        result = upgrade.assess_baseline_metadata(data, expected)
        self.assertEqual(result['origin_equivalence'], 'blocked')
        self.assertFalse(result['literal_metadata_match'])
        self.assertEqual(before['migration_names_sorted_sha256'], result['migration_names_sorted_sha256'])
        self.assertEqual(result['observed_synthetic']['migration_ledger_count'], expected['migration_ledger_count'])

    def test_metadata_schema_or_version_difference_blocks_equivalence(self):
        for kind in ('schema', 'version'):
            with self.subTest(kind=kind):
                data, expected = self.synthetic_metadata()
                if kind == 'schema':
                    data['schema_columns'][0][2] = 'varchar(255)'
                    data['schema_metadata_sha256'] = upgrade.metadata_digest(data['schema_columns'])
                else:
                    data['mysql_version'] = '8.4.13'
                result = upgrade.assess_baseline_metadata(data, expected)
                self.assertEqual(result['origin_equivalence'], 'blocked')
                self.assertTrue(result['sanitized_differences'])

    def test_metadata_invalid_fields_hash_rows_and_order_rejected(self):
        for kind in ('field', 'hash', 'batch', 'identifier', 'nullability', 'duplicate', 'order'):
            with self.subTest(kind=kind):
                data, expected = self.synthetic_metadata()
                if kind == 'field': data['business_data'] = 'not-permitted'
                elif kind == 'hash': data['migration_ledger_sha256'] = '0' * 64
                elif kind == 'batch': data['migration_ledger'][0][1] = -1
                elif kind == 'identifier': data['schema_columns'][0][0] = 'bad\nname'
                elif kind == 'nullability': data['schema_columns'][0][3] = 'unknown'
                elif kind == 'duplicate': data['migration_ledger'] *= 2
                elif kind == 'order': data['migration_ledger'].append(['0000_first', 1])
                with self.assertRaises(ValueError):
                    upgrade.assess_baseline_metadata(data, expected)

    def test_metadata_capture_failure_is_blocked_without_stopping_rehearsal(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(upgrade, 'run', side_effect=RuntimeError('probe failed')), patch.object(upgrade, 'write'):
            result = upgrade.capture_baseline_metadata(pathlib.Path(directory), {}, pathlib.Path(directory), 'upgrade-mysql-')
            self.assertEqual(result['origin_equivalence'], 'blocked')
            self.assertEqual(result['capture_or_validation_error'], 'RuntimeError')

    def test_origin_names_digest_is_compared_independently_of_batch(self):
        data, expected = self.synthetic_metadata()
        expected['migration_names_sorted_sha256'] = upgrade.metadata_digest(sorted(r[0] for r in data['migration_ledger']))
        self.assertFalse(upgrade.assess_baseline_metadata(data, expected)['sanitized_differences'])
        expected['migration_names_sorted_sha256'] = '0' * 64
        result = upgrade.assess_baseline_metadata(data, expected)
        self.assertEqual(result['origin_equivalence'], 'blocked')
        self.assertEqual(result['sanitized_differences'][0]['field'], 'migration_names_sorted_sha256')

    def test_origin_shape_requires_every_reviewed_count_and_digest(self):
        for field in ('migration_ledger_count', 'migration_names_sorted_sha256', 'schema_column_count', 'schema_metadata_sha256'):
            with self.subTest(field=field):
                observed = dict(upgrade.ORIGIN_METADATA)
                observed[field] = 'wrong'
                with self.assertRaisesRegex(RuntimeError, 'reviewed names/schema'):
                    upgrade.require_origin_shape({'observed_synthetic': observed})
        with self.assertRaises(RuntimeError):
            upgrade.require_origin_shape({'capture_or_validation_error': 'RuntimeError'})

    def test_origin_shape_discloses_batch_difference_without_fabrication(self):
        observed = dict(upgrade.ORIGIN_METADATA, migration_ledger_sha256='synthetic-batches')
        result = upgrade.require_origin_shape({'observed_synthetic': observed})
        self.assertFalse(result['ledger_batch_equivalence'])
        self.assertIn('without fabrication', result['batch_limit'])
        self.assertEqual(result['production_release_gate'], 'not-approved-by-synthetic-test')

    def test_origin_rehearsal_orders_failure_repair_idempotence_and_restore(self):
        source = (common.ROOT / 'tests/Deployment/linux-upgrade.py').read_text(encoding='utf-8')
        labels = ['repair-fresh-noop', 'prepare-origin-shape', 'repair-billing-before', 'five-migrations',
                  'repair-billing-after', 'repair-origin-verify', 'repair-origin-idempotent', 'restored-backup-repair-verify']
        positions = [source.index("'" + label + "'") for label in labels]
        self.assertEqual(positions, sorted(positions))
        self.assertIn('require_new_migrations(new)', source)
        self.assertIn("evidence['reversal_authorized_delta']", source)

    def test_upgrade_requires_exact_five_names_not_just_count(self):
        upgrade.require_new_migrations(upgrade.EXPECTED_NEW_MIGRATIONS)
        for names in (upgrade.EXPECTED_NEW_MIGRATIONS[:-1], list(reversed(upgrade.EXPECTED_NEW_MIGRATIONS)),
                      ['2026_11_06_000200_unreviewed.php', *upgrade.EXPECTED_NEW_MIGRATIONS[1:]]):
            with self.subTest(names=names), self.assertRaises(RuntimeError):
                upgrade.require_new_migrations(names)

    def test_origin_fixture_has_explicit_disposable_guards_and_fake_gateway(self):
        source = (common.ROOT / 'tests/Deployment/origin-shaped-fixture.php').read_text(encoding='utf-8')
        for anchor in ("getenv('RELEASE_DISPOSABLE_TESTS') === '1'", "getenv('APP_ENV') === 'testing'",
                       "is_file($sandbox.'/.disposable-upgrade')", '/^fokus_upgrade_[a-f0-9]{16}$/D',
                       'Http::preventStrayRequests()', 'Http::recorded()->count() === 0',
                       "'charges_created' => 0"):
            self.assertIn(anchor, source)
        for qualified, alias in (('App\\Services\\SubscriptionBillingManager', 'SubscriptionBillingManager'),
                                 ('App\\Http\\Controllers\\Api\\LawSubscriptionController', 'LawSubscriptionController')):
            imported = 'use ' + qualified + ';'
            constructed = 'new ' + alias
            self.assertIn(imported, source)
            self.assertRegex(source, r'\bnew\s+' + alias + r'\b')
            self.assertLess(source.index(imported), source.index(constructed))
        self.assertLess(source.index('explicit disposable guard'), source.index("$mode === 'prepare-origin'"))

    def test_fixture_kernel_imports_precede_bootstrap(self):
        for name in ('origin-shaped-fixture.php', 'reversal-harness.php', 'upgrade-fixture.php'):
            with self.subTest(name=name):
                source = (common.ROOT / 'tests/Deployment' / name).read_text(encoding='utf-8')
                imported = 'use Illuminate\\Contracts\\Console\\Kernel;'
                bootstrap = '->make(Kernel::class)->bootstrap()'
                self.assertIn(imported, source)
                self.assertIn(bootstrap, source)
                self.assertLess(source.index(imported), source.index(bootstrap))

    def test_origin_reversal_records_retained_column_instead_of_baseline_equality(self):
        source = (common.ROOT / 'tests/Deployment/reversal-harness.php').read_text(encoding='utf-8')
        self.assertRegex(source, r"\['--step'\s*=>\s*5,\s*'--force'\s*=>\s*true\]")
        self.assertRegex(source, r"Schema::hasColumn\('payments',\s*'provider_checkout_url'\)")
        self.assertIn("$delta['repair_ledger_absent'] = true", source)
        self.assertIn("$delta['payments_and_urls_unchanged'] = true", source)
        self.assertIn('EXPLICIT AUTHORIZED ROLLBACK DELTA', source)
        self.assertNotIn("dropColumn('provider_checkout_url')", source)
        contract = (common.ROOT / 'tests/Deployment/origin-schema-contract.php').read_text(encoding='utf-8')
        self.assertIn("$reversed ? [['payments', 'provider_checkout_url', 'varchar(512)', 'YES']]", contract)
        self.assertIn("$retained === $before[$kind]", contract)
        self.assertRegex(contract, r"finally\s*\{\s*DB::statement\(\"SET SESSION time_zone='\+00:00'\"\);\s*\}")

    def dependency_fixture(self, root, version):
        root.mkdir(parents=True)
        (root / 'vendor/composer').mkdir(parents=True)
        package = {'name': 'synthetic/package', 'version': version, 'dist': {'reference': 'ref-' + version}}
        (root / 'composer.json').write_text('{}')
        (root / 'composer.lock').write_text(json.dumps({'packages': [package], 'packages-dev': []}))
        (root / 'vendor/composer/installed.json').write_text(json.dumps({'dev': True, 'packages': [package]}))
        (root / 'vendor/payload.php').write_text('synthetic bytes ' + version)
        dependencies.receipt(root, {'kind': 'synthetic-unit-fixture'})

    def test_distinct_dependency_pairs_copy_exact_matching_vendor(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            for role, version in [('baseline', '1.0'), ('candidate', '2.0')]:
                snapshot = root / role
                self.dependency_fixture(snapshot, version)
                runtime = root / (role + '-runtime'); runtime.mkdir()
                for name in ('composer.json', 'composer.lock'):
                    (runtime / name).write_bytes((snapshot / name).read_bytes())
                evidence = dependencies.copy_verified_vendor(snapshot, runtime)
                self.assertTrue(evidence['copy_byte_verified'])
                self.assertEqual((runtime / 'vendor/payload.php').read_text(), 'synthetic bytes ' + version)
            self.assertNotEqual((root / 'baseline/composer.lock').read_bytes(), (root / 'candidate/composer.lock').read_bytes())

    def test_dependency_pair_rejects_wrong_lock_and_tampered_bytes(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            self.dependency_fixture(root / 'baseline', '1.0')
            self.dependency_fixture(root / 'candidate', '2.0')
            with self.assertRaisesRegex(RuntimeError, 'pairing mismatch'):
                dependencies.verify_snapshot(root / 'candidate', root / 'baseline')
            (root / 'baseline/vendor/payload.php').write_text('changed')
            with self.assertRaisesRegex(RuntimeError, 'bytes changed'):
                dependencies.verify_snapshot(root / 'baseline', root / 'baseline')

    def test_dependency_manifest_rejects_even_internal_symlinks(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory) / 'snapshot'
            self.dependency_fixture(root, '1.0')
            original = pathlib.Path.is_symlink
            with patch.object(pathlib.Path, 'is_symlink', lambda path: path.name == 'composer' or original(path)):
                with self.assertRaisesRegex(RuntimeError, 'reject all symbolic links'):
                    dependencies.vendor_manifest(root)

    def test_dependency_packages_must_match_lock(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory) / 'snapshot'
            self.dependency_fixture(root, '1.0')
            installed = root / 'vendor/composer/installed.json'
            data = json.loads(installed.read_text()); data['packages'][0]['version'] = '2.0'
            installed.write_text(json.dumps(data))
            with self.assertRaisesRegex(RuntimeError, 'paired lockfile'):
                dependencies.check_packages(root)

    def test_metadata_probes_use_nondeprecated_mysql_constant(self):
        for name in ('linux-upgrade.py', 'baseline-target-metadata.php'):
            with self.subTest(name=name):
                source = (common.ROOT / 'tests/Deployment' / name).read_text(encoding='utf-8')
                if name.endswith('.php'):
                    imported = 'use Pdo\\Mysql;'
                    self.assertIn(imported, source)
                    self.assertRegex(source, r'(?<![\w\\])Mysql::ATTR_MULTI_STATEMENTS\s*=>\s*false\b')
                    self.assertLess(source.index(imported), source.index('Mysql::ATTR_MULTI_STATEMENTS'))
                else:
                    self.assertIn('Pdo\\Mysql::ATTR_MULTI_STATEMENTS=>false', source)
                self.assertNotIn('PDO::MYSQL_ATTR_MULTI_STATEMENTS', source)
                self.assertNotIn('@json_encode', source)
                self.assertNotIn('error_reporting(0)', source)
        source = (common.ROOT / 'tests/Deployment/linux-upgrade.py').read_text(encoding='utf-8')
        self.assertIn("data = json.loads((REPORT / (prefix + 'baseline-metadata.log')).read_text(encoding='utf-8'))", source)

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
