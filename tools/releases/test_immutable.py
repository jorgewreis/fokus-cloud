#!/usr/bin/env python3
"""Filesystem/subprocess tests, synthetic data only; no VPS, database or PHP claim."""
import hashlib
import importlib.util
import io
import json
import os
import pwd
import re
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('immutable', Path(__file__).with_name('immutable.py'))
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class ImmutableTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.preparer = self.base / 'prepare-release.py'
        self.preparer.write_bytes(subprocess.check_output(['git', 'show', 'b271260d1e9cf4778bf3304e961db73751a297f1:tools/ci/prepare-release.py']))
        self.verifier = self.base / 'verify-deployment.py'
        self.verifier.write_bytes(subprocess.check_output(['git', 'show', 'a8cc9729181471c9f46ccb15d51383e1aceb61ab:tools/ci/verify-deployment.py']))
        self.source = self.base / 'source'; self.source.mkdir()
        self.git('init', '-q')
        for name in ('app/a.php', 'routes/console.php', 'public/index.php', 'bootstrap/app.php', 'config/app.php', 'artisan', 'composer.lock', 'package-lock.json'):
            p = self.source / name; p.parent.mkdir(parents=True, exist_ok=True); p.write_text('{}')
        self.git('add', '.')
        self.git('-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-qm', 'fixture')
        self.sha = self.git('rev-parse', 'HEAD').strip()
        (self.source / 'vendor').mkdir(); (self.source / 'vendor/autoload.php').write_text('<?php // synthetic')
        self.extras = self.base / 'extras.json'; self.extras.write_text('[]')
        self.artifact_manifest = self.base / 'transport.json'
        self.artifact = self.base / 'artifact.tar'
        self.release_parent = self.base / 'releases'; self.release_parent.mkdir()
        self.root = self.release_parent / self.sha
        for name in ('activation.lock', 'scheduler.lock'):
            (self.base / name).touch()

    def tearDown(self):
        # Only these test fixtures are made writable for TemporaryDirectory cleanup.
        for directory, dirs, names in os.walk(self.base, followlinks=False):
            Path(directory).chmod(0o700)
            for name in names:
                p = Path(directory) / name
                if not p.is_symlink(): p.chmod(0o600)

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.source), *args], text=True, stderr=subprocess.DEVNULL)

    def package(self):
        return m.package(self.source, self.artifact, self.extras)

    def prepared(self):
        result = self.package()
        m.unpack(self.artifact, result['artifact_sha256'], self.root, self.artifact_manifest)
        return result

    def fixture_receipt(self, root):
        directory = self.base / 'receipts'; directory.mkdir(exist_ok=True)
        receipt = directory / (root.name + '.json')
        cache = root / 'bootstrap/cache'; cache.mkdir(parents=True, exist_ok=True)
        for name in ('packages.php', 'services.php'): (cache / name).write_text('<?php return [];')
        m.seal(root, self.source, self.preparer, m.digest(self.preparer.read_bytes()), {}, receipt, self.artifact_manifest)
        return receipt

    def activation_fixture(self, verifier_exit=0):
        self.prepared()
        receipt = self.fixture_receipt(self.root)
        old = self.release_parent / ('b' * 40); old.mkdir(); (old / 'artisan').write_text('old')
        (self.base / 'current').symlink_to(old)
        # Activation state-machine injection only; NOT evidence of HTTP/FPM identity.
        def synthetic_verifier(policy, root):
            process = subprocess.run([sys.executable, '-c', f'import sys; sys.exit({verifier_exit})'])
            m.require(process.returncode == 0, 'post_activation_verification_failed')
        stub = patch.object(m, 'run_verifier', synthetic_verifier); stub.start(); self.addCleanup(stub.stop)
        p = {k: True for k in ('target_binding_verified', 'php_cli_platform_verified', 'php_fpm_platform_verified', 'extensions_verified', 'fastcgi_physical_root_verified', 'permissions_acl_verified', 'runtime_cannot_write_release', 'workers_drained', 'shared_drivers_verified', 'schema_compatible', 'recovery_verified', 'publication_authorized', 'caches_finalized', 'laravel_boot_verified', 'assets_complete_verified', 'scheduler_descendants_absent_verified', 'build_provenance_verified')}
        p.update(schema=m.SCHEMA, release=self.sha, fpm_opcache_enabled=False, preload_configured_or_loaded=False, runtime_uid=pwd.getpwnam('nobody').pw_uid, php_binary=str(Path(sys.executable).resolve()), base=str(self.base), root=str(self.root), receipt_sha256=m.digest(receipt.read_bytes()), node_sha256=m.node_fingerprint(), operator_uid=os.geteuid(), verifier_script=str(self.verifier), public_base_url='https://fixture.invalid', release_url='https://fixture.invalid/_deployment/release', fpm_reload_mode='verify-only', fpm_service='', evidence_reference='synthetic fixture, NOT runtime attestation', expires_at_epoch=time.time()+60)
        policy = self.base / 'policy.json'; policy.write_bytes(m.encoded(p))
        return receipt, old, policy

    def scheduler_evidence(self, php):
        # State-machine injection only: this executor cannot switch to another UID.
        def synthetic_launch(php, root, evidence, timeout):
            return subprocess.run([str(php), str(root/'artisan'), 'schedule:run'], cwd=root, timeout=timeout)
        stub = patch.object(m, 'launch_scheduler', synthetic_launch); stub.start(); self.addCleanup(stub.stop)
        folder = self.base / 'scheduler-evidence'; folder.mkdir(exist_ok=True)
        receipt = self.base / 'receipts' / (self.sha + '.json')
        (folder / (self.sha + '.json')).write_bytes(m.encoded({'base': str(self.base), 'root': str(self.root), 'receipt_sha256': m.digest(receipt.read_bytes()), 'node_sha256': m.node_fingerprint(), 'php_binary': str(php), 'php_binary_sha256': m.digest(php.read_bytes()), 'platform_verified': True, 'no_descendants_verified': True, 'runtime_uid': pwd.getpwnam('nobody').pw_uid, 'runtime_gid': pwd.getpwnam('nobody').pw_gid, 'runtime_permissions_verified': True, 'expires_at_epoch': time.time()+60}))
        return folder

    def test_reproducible_package_and_exact_unpack(self):
        result = self.prepared()
        other = self.base / 'again.tar'
        again = m.package(self.source, other, self.extras)
        self.assertEqual(result, again)
        self.assertEqual(m.files(self.root)['vendor/autoload.php']['sha256'], m.digest(b'<?php // synthetic'))

    def test_tracked_build_change_refused(self):
        (self.source / 'public/index.php').write_text('changed')
        with self.assertRaisesRegex(m.Refusal, 'dirty_source'): self.package()
        self.assertFalse(self.artifact.exists())

    def test_law_generated_manifest_refused(self):
        (self.source / 'config/fokus-law-assets.json').write_text('{}')
        self.extras.write_text('["config/fokus-law-assets.json"]')
        with self.assertRaisesRegex(m.Refusal, 'unapproved_generated_output'): self.package()

    def test_generated_assets_packaged_exactly(self):
        (self.source / 'public/a.css').write_text('body {}')
        self.extras.write_text('["public/a.css"]')
        self.prepared()
        self.assertEqual((self.root / 'public/a.css').read_text(), 'body {}')

    def test_secrets_not_copied(self):
        (self.source / '.env').write_text('CANARY_SECRET=hidden')
        self.prepared()
        self.assertFalse((self.root / '.env').exists())
        self.assertNotIn(b'CANARY_SECRET', self.artifact.read_bytes())

    def test_generated_php_refused(self):
        (self.source / 'public/evil.php').write_text('<?php')
        self.extras.write_text('["public/evil.php"]')
        with self.assertRaises(m.Refusal): self.package()

    def test_vendor_symlink_refused(self):
        (self.source / 'vendor/link').symlink_to(self.extras)
        with self.assertRaisesRegex(m.Refusal, 'unexpected_link'): self.package()

    def test_duplicate_outputs_refused(self):
        self.extras.write_text('["public/a.css","public/a.css"]')
        with self.assertRaises(m.Refusal): self.package()

    def test_digest_mismatch_no_destination(self):
        self.package()
        with self.assertRaisesRegex(m.Refusal, 'artifact_digest_mismatch'): m.unpack(self.artifact, '0'*64, self.root, self.artifact_manifest)
        self.assertFalse(self.root.exists())

    def test_wrong_sha_no_destination(self):
        result = self.package()
        with self.assertRaisesRegex(m.Refusal, 'destination_sha_mismatch'): m.unpack(self.artifact, result['artifact_sha256'], self.release_parent / ('c'*40), self.artifact_manifest)

    def test_existing_release_not_overwritten(self):
        result = self.prepared()
        with self.assertRaises(m.Refusal): m.unpack(self.artifact, result['artifact_sha256'], self.root, self.artifact_manifest)

    def test_archive_traversal_and_links_rejected(self):
        for name, kind in (('../escape', tarfile.REGTYPE), ('link', tarfile.SYMTYPE)):
            p = self.base / ('bad'+str(len(name))+'.tar')
            with tarfile.open(p, 'w') as t:
                x = tarfile.TarInfo(name); x.type = kind; x.linkname = '/tmp'; t.addfile(x, io.BytesIO(b''))
            with self.assertRaises(m.Refusal): m.unpack(p, m.digest(p.read_bytes()), self.root, self.artifact_manifest)
            self.assertFalse(self.root.exists())

    def test_shared_link_preserves_external_configuration(self):
        self.prepared(); storage = self.base / 'shared'; storage.mkdir(); env = self.base / 'config'; env.write_text('CANARY_SECRET')
        links = m.shared_links(self.root, storage, env)
        m.protect(self.root)
        self.assertTrue((self.root / '.env').is_symlink())
        self.assertEqual(env.read_text(), 'CANARY_SECRET')
        self.assertEqual(links['storage'], str(storage))
        self.assertEqual(storage.stat().st_mode & 0o700, 0o700)

    def test_shared_existing_path_not_replaced(self):
        self.prepared(); (self.root / '.env').write_text('existing'); storage = self.base / 'shared'; storage.mkdir()
        with self.assertRaises(m.Refusal): m.shared_links(self.root, storage, self.extras)
        self.assertEqual((self.root / '.env').read_text(), 'existing')

    def test_integrity_mutation_refused(self):
        self.prepared(); receipt = self.fixture_receipt(self.root)
        self.assertEqual(m.verify(self.root, receipt)['source_sha'], self.sha)
        p = self.root / 'app/a.php'; p.chmod(0o600); p.write_text('corrupt')
        with self.assertRaises(m.Refusal): m.verify(self.root, receipt)

    def test_writable_directory_refused(self):
        self.prepared(); receipt = self.fixture_receipt(self.root); (self.root / 'app').chmod(0o755)
        with self.assertRaisesRegex(m.Refusal, 'release_directory_writable'): m.verify(self.root, receipt)

    def test_relocation_refused(self):
        self.prepared(); receipt = self.fixture_receipt(self.root); new = self.base / 'other'; new.mkdir(); moved = new / self.sha; self.root.chmod(0o755); self.root.rename(moved)
        with self.assertRaisesRegex(m.Refusal, 'receipt_binding'): m.verify(moved, receipt)

    def test_activation_success_keeps_previous(self):
        receipt, old, policy = self.activation_fixture()
        out = m.activate(self.base, self.root, receipt, policy, old)
        self.assertEqual(out['state'], 'verified'); self.assertTrue(old.exists()); self.assertEqual((self.base / 'current').resolve(), self.root)

    def test_verifier_failure_is_not_success_or_automatic_rollback(self):
        receipt, old, policy = self.activation_fixture(7)
        with self.assertRaisesRegex(m.Refusal, 'post_activation_verification_failed'): m.activate(self.base, self.root, receipt, policy, old)
        self.assertEqual((self.base / 'current').resolve(), self.root)
        journals = self.activation_journals()
        self.assertEqual(len(journals), 1)
        self.assertEqual(json.loads(journals[0].read_text())['state'], 'activation_unverified_manual_recovery_required')
        self.assertEqual(json.loads((self.base / 'activation-blocked.json').read_text())['state'], 'switch_intent')
        self.assertTrue(old.exists())

    def activation_journals(self):
        # The blocker is deliberately NOT an operation journal. Never depend on readdir order.
        return [p for p in self.base.iterdir() if re.fullmatch(r'activation-[0-9a-f]{32}\.json', p.name)]

    def test_old_glob_can_select_blocker_before_failure_journal(self):
        receipt, old, policy = self.activation_fixture(7)
        with self.assertRaises(m.Refusal): m.activate(self.base, self.root, receipt, policy, old)
        entries = sorted(self.base.glob('activation-*.json'), key=lambda p: p.name != 'activation-blocked.json')
        self.assertEqual(entries[0].name, 'activation-blocked.json')
        self.assertNotIn('manual_recovery_required', entries[0].read_text())
        self.assertIn('manual_recovery_required', self.activation_journals()[0].read_text())

    def interrupted_activation(self, stage):
        receipt, old, policy = self.activation_fixture()
        child = os.fork()
        if child == 0:
            original_write, original_replace = m.durable_json, m.os.replace
            def write(path, value):
                original_write(path, value)
                if ((stage == 'latch' and path.name == 'activation-blocked.json')
                    or (stage == 'journal' and re.fullmatch(r'activation-[0-9a-f]{32}\.json', path.name))
                    or (stage == 'verified' and value['state'] == 'verified')):
                    os._exit(87)  # Real abrupt process exit: no exception cleanup/finally.
            def replace(src, dst):
                original_replace(src, dst)
                if stage == 'switched' and Path(dst).name == 'current': os._exit(87)
            m.durable_json, m.os.replace = write, replace
            try: m.activate(self.base, self.root, receipt, policy, old)
            except BaseException: os._exit(88)
            os._exit(89)
        _, status = os.waitpid(child, 0)
        self.assertEqual(os.waitstatus_to_exitcode(status), 87)
        self.assertTrue(old.exists())
        self.assertTrue((self.base / 'activation-blocked.json').exists())
        self.assertEqual((self.base / 'current').resolve(), self.root if stage in ('switched', 'verified') else old)
        with self.assertRaisesRegex(m.Refusal, 'activation_recovery_required'):
            m.activate(self.base, self.root, receipt, policy, old)
        return receipt, old, policy

    def test_interruption_after_latch_before_journal(self):
        self.interrupted_activation('latch')
        self.assertEqual(self.activation_journals(), [])

    def test_interruption_after_journal_before_switch(self):
        self.interrupted_activation('journal')
        self.assertEqual(json.loads(self.activation_journals()[0].read_text())['state'], 'switch_intent')

    def test_interruption_after_switch_before_verification(self):
        self.interrupted_activation('switched')
        self.assertEqual(json.loads(self.activation_journals()[0].read_text())['state'], 'switch_intent')

    def test_interruption_after_verified_journal_still_requires_recovery(self):
        self.interrupted_activation('verified')
        self.assertEqual(json.loads(self.activation_journals()[0].read_text())['state'], 'verified')

    def test_incomplete_journal_does_not_clear_blocker(self):
        receipt, old, policy = self.interrupted_activation('journal')
        self.activation_journals()[0].write_text('{')
        with self.assertRaisesRegex(m.Refusal, 'activation_recovery_required'):
            m.activate(self.base, self.root, receipt, policy, old)
        self.assertTrue(old.exists())

    def test_manual_guarded_resumption_after_pre_switch_interruption(self):
        receipt, old, policy = self.interrupted_activation('journal')
        data = json.loads(policy.read_text()); data.update(rollback_code_compatible=True, data_restore_required=False)
        policy.write_bytes(m.encoded(data))
        self.assertTrue(m.activate(self.base, self.root, receipt, policy, old, rollback=True)['code_rollback'])
        self.assertFalse((self.base / 'activation-blocked.json').exists())
        self.assertTrue(old.exists())

    def test_scheduler_actual_timeout_is_not_success(self):
        self.prepared(); self.fixture_receipt(self.root); (self.base / 'current').symlink_to(self.root)
        exe = self.base / 'fake-php'; exe.write_text('#!/bin/sh\nexec sleep 3\n'); exe.chmod(0o755)
        with self.assertRaises(subprocess.TimeoutExpired):
            m.schedule(self.base, exe, self.base / 'receipts', self.scheduler_evidence(exe), timeout=0.03)
        self.assertEqual(json.loads((self.base / 'activation-blocked.json').read_text())['state'], 'scheduler_failure_runtime_review_required')
        self.assertFalse((self.base / 'scheduler-last-result.json').exists())

    def test_null_runtime_blocks_before_switch(self):
        receipt, old, policy = self.activation_fixture()
        p = json.loads(policy.read_text()); p['extensions_verified'] = None; policy.write_bytes(m.encoded(p))
        with self.assertRaisesRegex(m.Refusal, 'runtime_evidence_incomplete'): m.activate(self.base, self.root, receipt, policy, old)
        self.assertEqual((self.base / 'current').resolve(), old)

    def test_opcache_preload_each_block(self):
        receipt, old, policy = self.activation_fixture(); original = json.loads(policy.read_text())
        for key in ('fpm_opcache_enabled', 'preload_configured_or_loaded'):
            p = dict(original); p[key] = True; policy.write_bytes(m.encoded(p))
            with self.assertRaisesRegex(m.Refusal, 'identity_runtime_incompatible'): m.activate(self.base, self.root, receipt, policy, old)
            self.assertEqual((self.base / 'current').resolve(), old)

    def test_same_runtime_uid_blocks(self):
        receipt, old, policy = self.activation_fixture(); p = json.loads(policy.read_text()); p['runtime_uid'] = os.geteuid(); policy.write_bytes(m.encoded(p))
        with self.assertRaises(m.Refusal): m.activate(self.base, self.root, receipt, policy, old)

    def test_expired_evidence_blocks(self):
        receipt, old, policy = self.activation_fixture(); p = json.loads(policy.read_text()); p['expires_at_epoch'] = 0; policy.write_bytes(m.encoded(p))
        with self.assertRaisesRegex(m.Refusal, 'evidence_expired'): m.activate(self.base, self.root, receipt, policy, old)

    def test_previous_mismatch_blocks(self):
        receipt, old, policy = self.activation_fixture()
        with self.assertRaisesRegex(m.Refusal, 'previous_changed'): m.activate(self.base, self.root, receipt, policy, self.base / 'unexpected')
        self.assertEqual((self.base / 'current').resolve(), old)

    def test_scheduler_lock_blocks_switch(self):
        receipt, old, policy = self.activation_fixture()
        with m.lock(self.base / 'scheduler.lock'):
            with self.assertRaises(BlockingIOError): m.activate(self.base, self.root, receipt, policy, old)
        self.assertEqual((self.base / 'current').resolve(), old)

    def test_activation_lock_blocks_switch(self):
        receipt, old, policy = self.activation_fixture()
        with m.lock(self.base / 'activation.lock'):
            with self.assertRaises(BlockingIOError): m.activate(self.base, self.root, receipt, policy, old)

    def test_rollback_requires_explicit_data_compatibility(self):
        receipt, old, policy = self.activation_fixture()
        with self.assertRaisesRegex(m.Refusal, 'unsafe_code_rollback'): m.activate(self.base, self.root, receipt, policy, old, rollback=True)

    def test_safe_code_rollback_same_guards(self):
        receipt, old, policy = self.activation_fixture(); p = json.loads(policy.read_text()); p.update(rollback_code_compatible=True, data_restore_required=False); policy.write_bytes(m.encoded(p))
        self.assertTrue(m.activate(self.base, self.root, receipt, policy, old, rollback=True)['code_rollback'])

    def test_scheduler_subprocess_failure_propagated(self):
        self.prepared(); receipt = self.fixture_receipt(self.root); (self.base / 'current').symlink_to(self.root)
        # Real subprocess, synthetic executable; does not claim a PHP test.
        exe = self.base / 'fake-php'; exe.write_text('#!/bin/sh\nexit 9\n'); exe.chmod(0o755)
        with self.assertRaisesRegex(m.Refusal, 'scheduler_failed'): m.schedule(self.base, exe, self.base / 'receipts', self.scheduler_evidence(exe))

    def test_scheduler_resolves_generation_and_runs_once(self):
        self.prepared(); receipt = self.fixture_receipt(self.root); (self.base / 'current').symlink_to(self.root)
        exe = self.base / 'fake-php'; exe.write_text('#!/bin/sh\ntest "$2" = schedule:run\n'); exe.chmod(0o755)
        self.assertEqual(m.schedule(self.base, exe, self.base / 'receipts', self.scheduler_evidence(exe))['release'], self.sha)
        with m.lock(self.base / 'scheduler.lock'):
            with self.assertRaises(BlockingIOError): m.schedule(self.base, exe, self.base / 'receipts', self.scheduler_evidence(exe))

    def test_unverified_activation_latches_scheduler_and_next_activation(self):
        receipt, old, policy = self.activation_fixture(3)
        with self.assertRaises(m.Refusal): m.activate(self.base, self.root, receipt, policy, old)
        self.assertTrue((self.base / 'activation-blocked.json').exists())
        with self.assertRaisesRegex(m.Refusal, 'activation_recovery_required'):
            m.schedule(self.base, Path(sys.executable).resolve(), self.base / 'receipts', self.scheduler_evidence(Path(sys.executable).resolve()))
        with self.assertRaisesRegex(m.Refusal, 'activation_recovery_required'):
            m.activate(self.base, self.root, receipt, policy, old)

    def test_background_scheduler_current_application_is_rejected(self):
        self.prepared()
        code = 'Schedule::command("queue:work")->runInBackground();'
        (self.source / 'routes/console.php').write_text(code)
        self.git('add', 'routes/console.php'); self.git('-c', 'user.name=T', '-c', 'user.email=t@example.invalid', 'commit', '-qm', 'background')
        self.sha = self.git('rev-parse', 'HEAD').strip()
        self.root = self.release_parent / self.sha
        self.artifact = self.base / 'background.tar'; self.artifact_manifest = self.base / 'background-transport.json'; self.prepared()
        receipt = self.fixture_receipt(self.root); (self.base / 'current').symlink_to(self.root)
        with self.assertRaisesRegex(m.Refusal, 'background_scheduler_not_supported'):
            m.schedule(self.base, Path(sys.executable).resolve(), self.base / 'receipts', self.scheduler_evidence(Path(sys.executable).resolve()))

    def test_missing_verifier_blocks_before_switch(self):
        receipt, old, policy = self.activation_fixture(); p = json.loads(policy.read_text())
        p['verifier_script'] = str(self.base / 'missing'); policy.write_bytes(m.encoded(p))
        with self.assertRaisesRegex(m.Refusal, 'verifier_script_mismatch'): m.activate(self.base, self.root, receipt, policy, old)
        self.assertEqual((self.base / 'current').resolve(), old)
        self.assertFalse((self.base / 'activation-blocked.json').exists())

    def test_invalid_endpoint_blocks_before_switch(self):
        receipt, old, policy = self.activation_fixture(); p = json.loads(policy.read_text())
        p['public_base_url'] = 'http://fixture.invalid'; policy.write_bytes(m.encoded(p))
        with self.assertRaisesRegex(m.Refusal, 'verifier_preflight_failed'): m.activate(self.base, self.root, receipt, policy, old)
        self.assertEqual((self.base / 'current').resolve(), old)

    def test_target_and_receipt_bound_policy(self):
        receipt, old, policy = self.activation_fixture(); original = json.loads(policy.read_text())
        for key in ('base', 'root', 'node_sha256', 'receipt_sha256'):
            p = dict(original); p[key] = 'wrong'; policy.write_bytes(m.encoded(p))
            with self.assertRaisesRegex(m.Refusal, 'policy_target_binding'): m.activate(self.base, self.root, receipt, policy, old)
            self.assertEqual((self.base / 'current').resolve(), old)

    def test_receipt_without_identity_rejected(self):
        self.prepared(); receipt = self.fixture_receipt(self.root); receipt.chmod(0o600)
        data = json.loads(receipt.read_text()); data['files'].pop('.release/identity.php'); receipt.write_bytes(m.encoded(data))
        with self.assertRaisesRegex(m.Refusal, 'identity_missing'): m.verify(self.root, receipt)

    def test_receipt_cannot_bless_application_symlink(self):
        self.prepared(); receipt = self.fixture_receipt(self.root); receipt.chmod(0o600)
        data = json.loads(receipt.read_text()); data['links']['app'] = str(self.source / 'app'); receipt.write_bytes(m.encoded(data))
        with self.assertRaisesRegex(m.Refusal, 'shared_link_allowlist'): m.verify(self.root, receipt)

    def test_real_pr59_rejects_untracked_config(self):
        self.prepared(); (self.root / 'config/fokus-law-assets.json').write_text('{}')
        with self.assertRaisesRegex(m.Refusal, 'unapproved_local_addition'): self.fixture_receipt(self.root)
        self.assertFalse((self.base / 'receipts' / (self.sha+'.json')).exists())

    def test_real_pr59_shared_links_and_cache_seal(self):
        self.prepared(); storage = self.base / 'shared'; storage.mkdir(); env = self.base / 'environment'; env.write_text('SYNTHETIC=1')
        links = m.shared_links(self.root, storage, env)
        cache = self.root / 'bootstrap/cache'; cache.mkdir(parents=True, exist_ok=True)
        for name in ('packages.php', 'services.php'): (cache / name).write_text('<?php return [];')
        receipt = self.base / 'shared-receipt.json'
        m.seal(self.root, self.source, self.preparer, m.digest(self.preparer.read_bytes()), links, receipt, self.artifact_manifest)
        self.assertEqual(m.verify(self.root, receipt)['links'], links)
        (storage / 'new-upload').write_text('synthetic')
        self.assertEqual(m.verify(self.root, receipt)['source_sha'], self.sha)

    def test_sealing_never_expands_read_permissions_for_cached_secrets(self):
        self.prepared(); cache = self.root / 'bootstrap/cache'; cache.mkdir(parents=True, exist_ok=True)
        secret = cache / 'config.php'; secret.write_text('<?php return ["secret" => "CANARY"];'); secret.chmod(0o600)
        self.fixture_receipt(self.root)
        self.assertEqual(secret.stat().st_mode & 0o777, 0o400)
        self.assertEqual(self.root.stat().st_mode & 0o777, 0o500)

    def test_cli_argument_error_does_not_echo_sensitive_argument(self):
        p = subprocess.run([sys.executable, str(Path(__file__).with_name('immutable.py')), 'CANARY_SECRET_ARGUMENT'], capture_output=True, text=True)
        self.assertNotEqual(p.returncode, 0); self.assertNotIn('CANARY', p.stdout+p.stderr)

    def test_missing_bootstrap_manifests_refuse_seal(self):
        self.prepared()
        with self.assertRaisesRegex(m.Refusal, 'bootstrap_manifests_required'):
            m.seal(self.root, self.source, self.preparer, m.digest(self.preparer.read_bytes()), {}, self.base/'receipt', self.artifact_manifest)

    def test_scheduler_failure_latches_future_operations(self):
        self.prepared(); self.fixture_receipt(self.root); (self.base / 'current').symlink_to(self.root)
        exe = self.base / 'fake-php'; exe.write_text('#!/bin/sh\nexit 9\n'); exe.chmod(0o755)
        with self.assertRaises(m.Refusal): m.schedule(self.base, exe, self.base/'receipts', self.scheduler_evidence(exe))
        self.assertTrue((self.base/'activation-blocked.json').exists())

    def test_runtime_root_forbidden(self):
        receipt, old, policy = self.activation_fixture(); p = json.loads(policy.read_text()); p['runtime_uid'] = 0; policy.write_bytes(m.encoded(p))
        with self.assertRaisesRegex(m.Refusal, 'separate_runtime_principal_required'): m.activate(self.base, self.root, receipt, policy, old)

    def test_scheduler_rejects_controller_identity(self):
        self.prepared(); self.fixture_receipt(self.root); (self.base/'current').symlink_to(self.root)
        exe = self.base/'fake-php'; exe.write_text('#!/bin/sh\nexit 0\n'); exe.chmod(0o755)
        folder = self.scheduler_evidence(exe); f = folder/(self.sha+'.json'); e = json.loads(f.read_text()); e['runtime_uid'] = os.geteuid(); f.write_bytes(m.encoded(e))
        with self.assertRaisesRegex(m.Refusal, 'scheduler_runtime_identity_required'):
            m.schedule(self.base, exe, self.base/'receipts', folder)

    def test_cli_error_sanitized_and_nonzero(self):
        p = subprocess.run([sys.executable, str(Path(__file__).with_name('immutable.py')), 'unpack', '--artifact', '/CANARY_PRIVATE_PATH', '--expected-digest', '0'*64, '--destination', '/CANARY_OTHER', '--manifest-output', '/CANARY_MANIFEST'], capture_output=True, text=True)
        self.assertNotEqual(p.returncode, 0); self.assertNotIn('CANARY', p.stdout+p.stderr)


if __name__ == '__main__':
    unittest.main()
