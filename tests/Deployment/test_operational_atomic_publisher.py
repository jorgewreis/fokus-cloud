"""Atomic release switch, first-publication bootstrap and pointer rollback.

Synthetic directories only. No SSH, sudo, production path or secret is used.
"""
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('atomic_publisher', ROOT / 'deploy/atomic_publisher.py')
PUBLISHER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PUBLISHER)
RELEASE = 'a' * 40
OTHER = 'b' * 40


def bound_release(root, release, files=None):
    root.mkdir(parents=True)
    (root / 'public').mkdir()
    payload = {
        'files': files or {'public/index.php': 'c' * 64},
        'release': release,
        'root': str(root.resolve()),
    }
    PUBLISHER.write_identity(root, release, payload['root'], PUBLISHER.dumps(payload))
    return root


class AtomicPublicationTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='fokus-atomic-')
        self.base = Path(self.temporary.name)
        self.legacy = self.base / 'legacy'
        self.legacy.mkdir()
        (self.legacy / 'marker.txt').write_text('legacy-bytes')
        (self.base / 'current').symlink_to(self.legacy.resolve())
        storage = self.base / 'shared' / 'storage'
        (storage / 'app' / 'public').mkdir(parents=True)
        (self.base / 'shared' / '.env').write_text('APP_KEY=synthetic\n')
        (self.base / 'releases').mkdir()
        self.candidate = bound_release(self.base / 'releases' / RELEASE, RELEASE)

    def tearDown(self):
        self.temporary.cleanup()

    def current_target(self):
        return Path(os.readlink(self.base / 'current')).resolve()

    def test_bootstrap_switches_from_an_unsealed_tree_without_writing_inside_it(self):
        output = io.StringIO()
        with redirect_stdout(output):
            PUBLISHER.switch(self.base, RELEASE, bootstrap=True, migrate=None)
        self.assertEqual(self.current_target(), self.candidate.resolve())
        self.assertEqual((self.legacy / 'marker.txt').read_text(), 'legacy-bytes')
        self.assertIn('bootstrap_first_sealed_release=accepted reason=unsealed_current_tree', output.getvalue())
        self.assertIn('atomic_switch=completed', output.getvalue())
        self.assertNotIn(str(self.base), output.getvalue())
        self.assertTrue((self.candidate / '.env').is_symlink())
        self.assertTrue((self.candidate / 'storage').is_symlink())
        self.assertFalse((self.legacy / '.env').exists())

    def test_unsealed_current_is_refused_without_the_bootstrap_flag(self):
        before = os.readlink(self.base / 'current')
        with self.assertRaises(PUBLISHER.PublicationError) as caught:
            PUBLISHER.switch(self.base, RELEASE, bootstrap=False, migrate=None)
        self.assertEqual(caught.exception.reason, 'bootstrap_required_for_unsealed_current')
        self.assertEqual(os.readlink(self.base / 'current'), before)
        self.assertEqual((self.legacy / 'marker.txt').read_text(), 'legacy-bytes')
        self.assertFalse((self.candidate / '.env').exists())

    def test_bootstrap_is_refused_when_current_is_already_sealed(self):
        (self.legacy / '.release').mkdir()
        (self.legacy / '.release' / 'identity.php').write_text('<?php return [];\n')
        before = os.readlink(self.base / 'current')
        with self.assertRaises(PUBLISHER.PublicationError) as caught:
            PUBLISHER.switch(self.base, RELEASE, bootstrap=True, migrate=None)
        self.assertEqual(caught.exception.reason, 'bootstrap_refused_sealed_current')
        self.assertEqual(os.readlink(self.base / 'current'), before)
        self.assertEqual((self.legacy / 'marker.txt').read_text(), 'legacy-bytes')

    def test_sealed_current_switches_without_bootstrap_and_stays_after_success(self):
        previous = bound_release(self.base / 'releases' / OTHER, OTHER)
        (self.base / 'current').unlink()
        (self.base / 'current').symlink_to(previous.resolve())
        (previous / 'marker.txt').write_text('previous-bytes')
        PUBLISHER.switch(self.base, RELEASE, bootstrap=False, migrate=None)
        self.assertEqual(self.current_target(), self.candidate.resolve())
        self.assertEqual((previous / 'marker.txt').read_text(), 'previous-bytes')
        self.assertFalse((previous / 'storage').is_symlink())

    def test_failed_verification_restores_the_previous_symlink_and_keeps_the_candidate(self):
        PUBLISHER.switch(self.base, RELEASE, bootstrap=True, migrate=None)
        self.assertEqual(self.current_target(), self.candidate.resolve())
        with redirect_stdout(io.StringIO()) as output:
            PUBLISHER.rollback(self.base, RELEASE)
        self.assertEqual(self.current_target(), self.legacy.resolve())
        self.assertEqual((self.legacy / 'marker.txt').read_text(), 'legacy-bytes')
        self.assertTrue(self.candidate.is_dir())
        self.assertIn('atomic_rollback=restored_previous_release', output.getvalue())
        with self.assertRaises(PUBLISHER.PublicationError) as caught:
            PUBLISHER.rollback(self.base, RELEASE)
        self.assertEqual(caught.exception.reason, 'rollback_current_mismatch')
        self.assertEqual(self.current_target(), self.legacy.resolve())

    def test_in_place_destination_is_refused_and_the_active_tree_is_unchanged(self):
        (self.base / 'current').unlink()
        (self.base / 'current').symlink_to(self.candidate.resolve())
        (self.candidate / 'marker.txt').write_text('active-bytes')
        with self.assertRaises(PUBLISHER.PublicationError) as caught:
            PUBLISHER.switch(self.base, RELEASE, bootstrap=False, migrate=None)
        self.assertEqual(caught.exception.reason, PUBLISHER.IN_PLACE)
        self.assertEqual((self.candidate / 'marker.txt').read_text(), 'active-bytes')
        self.assertFalse((self.candidate / '.env').exists())
        nested = self.legacy / 'nested'
        self.assertTrue(PUBLISHER.destination_mutates_active_release(self.base / 'legacy', nested))

    def test_cli_refusal_and_rollback_do_not_echo_paths(self):
        error = io.StringIO()
        with redirect_stderr(error):
            status = PUBLISHER.main(['switch', '--base', str(self.base), '--release', RELEASE, '--bootstrap', 'false'])
        self.assertEqual(status, 1)
        self.assertIn('reason=bootstrap_required_for_unsealed_current', error.getvalue())
        self.assertNotIn(str(self.base), error.getvalue())
        PUBLISHER.switch(self.base, RELEASE, bootstrap=True, migrate=None)
        output = io.StringIO()
        with redirect_stdout(output):
            self.assertEqual(0, PUBLISHER.main(['rollback', '--base', str(self.base), '--release', RELEASE]))
        self.assertEqual(self.current_target(), self.legacy.resolve())
        self.assertNotIn(str(self.base), output.getvalue())

    def test_law_manifest_is_bound_and_a_mismatch_does_not_change_the_seal(self):
        digest = 'e' * 64
        asset = self.candidate / 'public' / ('app.' + digest + '.js')
        asset.write_bytes(b'asset')
        self.assertNotEqual(__import__('hashlib').sha256(b'asset').hexdigest(), digest)
        before = (self.candidate / '.release' / 'manifest.json').read_bytes()
        manifest = json.dumps({
            'version': 1,
            'algorithm': 'sha256',
            'assets': {'assets/js/app.js': {'path': 'app.' + digest + '.js', 'sha256': digest}},
        }).encode()
        with self.assertRaises(PUBLISHER.PublicationError) as caught:
            PUBLISHER.attach_law_manifest(self.candidate, manifest)
        self.assertEqual(caught.exception.reason, 'law_asset_mismatch')
        self.assertEqual((self.candidate / '.release' / 'manifest.json').read_bytes(), before)
        self.assertFalse((self.candidate / 'config' / 'fokus-law-assets.json').exists())
        real = __import__('hashlib').sha256(b'asset').hexdigest()
        asset.rename(self.candidate / 'public' / ('app.' + real + '.js'))
        manifest = json.dumps({
            'version': 1,
            'algorithm': 'sha256',
            'assets': {'assets/js/app.js': {'path': 'app.' + real + '.js', 'sha256': real}},
        }).encode()
        remote = '/srv/fixture/releases/' + RELEASE
        PUBLISHER.attach_law_manifest(self.candidate, manifest)
        PUBLISHER.rebind_root(self.candidate, remote)
        payload = json.loads((self.candidate / '.release' / 'manifest.json').read_bytes())
        self.assertEqual(payload['root'], remote)
        self.assertEqual(payload['files']['config/fokus-law-assets.json'], __import__('hashlib').sha256(manifest).hexdigest())
        self.assertEqual(payload['files']['public/index.php'], 'c' * 64)
        self.assertIn(remote, (self.candidate / '.release' / 'identity.php').read_text())

    def test_guard_script_still_refuses_without_touching_a_seal(self):
        seal = self.candidate / '.release' / 'manifest.json'
        before = seal.read_bytes()
        result = subprocess.run([sys.executable, str(ROOT / 'deploy/legacy_publication_guard.py')],
                                capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stderr.strip(), 'publication_blocked=' + PUBLISHER.IN_PLACE)
        self.assertEqual(seal.read_bytes(), before)


if __name__ == '__main__':
    unittest.main()
