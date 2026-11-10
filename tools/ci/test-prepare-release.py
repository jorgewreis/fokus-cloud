#!/usr/bin/env python3
"""Offline preparer tests with synthetic Git/vendor fixtures; no dependency installs."""
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location('prepare_release', Path(__file__).with_name('prepare-release.py'))
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class PreparationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.source = Path(self.temp.name) / 'source'
        self.source.mkdir()
        self.git('init', '-q')
        for name in ('app/example.php', 'bootstrap/app.php', 'config/app.php', 'routes/web.php', 'public/index.php'):
            path = self.source / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('<?php return [];')
        self.git('add', '.')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'synthetic fixture')
        self.sha = self.git('rev-parse', 'HEAD').strip()
        vendor = self.source / 'vendor'
        vendor.mkdir()
        (vendor / 'autoload.php').write_text('<?php // fixture only')
        self.root = Path(self.temp.name) / self.sha

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.source), *args], text=True, stderr=subprocess.DEVNULL)

    def test_sealed_manifest_binds_git_root_and_all_runtime_files(self):
        MODULE.prepare(self.source, self.root)
        MODULE.seal(self.source, self.root)
        manifest = json.loads((self.root / '.release/manifest.json').read_bytes())
        self.assertEqual(manifest['release'], self.sha)
        self.assertEqual(manifest['root'], str(self.root))
        self.assertIn('vendor/autoload.php', manifest['files'])
        self.assertIn('public/index.php', manifest['files'])
        with self.assertRaises(ValueError):
            MODULE.seal(self.source, self.root)
        with self.assertRaises(ValueError):
            MODULE.prepare(self.source, self.root)

    def test_mutated_tracked_source_cannot_claim_original_sha(self):
        MODULE.prepare(self.source, self.root)
        (self.root / 'app/example.php').write_text('<?php // modified')
        with self.assertRaises(ValueError):
            MODULE.seal(self.source, self.root)

    def test_untracked_application_code_cannot_claim_source_sha(self):
        MODULE.prepare(self.source, self.root)
        (self.root / 'app/injected.php').write_text('<?php // not in Git')
        with self.assertRaises(ValueError):
            MODULE.seal(self.source, self.root)

    def test_dirty_checkout_and_wrong_root_rejected(self):
        with self.assertRaises(ValueError):
            MODULE.prepare(self.source, Path(self.temp.name) / ('f' * 40))
        (self.source / 'app/example.php').write_text('<?php // dirty')
        with self.assertRaises(ValueError):
            MODULE.prepare(self.source, self.root)

    def test_external_vendor_symlink_rejected(self):
        (self.source / 'vendor/external').symlink_to('/tmp')
        with self.assertRaises(ValueError):
            MODULE.prepare(self.source, self.root)
        self.assertFalse(self.root.exists())

    def test_runtime_symlink_and_new_cache_are_accounted_for(self):
        MODULE.prepare(self.source, self.root)
        link = self.root / 'app/external.php'
        link.symlink_to(self.source / 'app/example.php')
        with self.assertRaises(ValueError):
            MODULE.seal(self.source, self.root)
        link.unlink()
        cache = self.root / 'bootstrap/cache/config.php'
        cache.write_text('<?php return [];')
        MODULE.seal(self.source, self.root)
        self.assertIn('bootstrap/cache/config.php', json.loads((self.root / '.release/manifest.json').read_text())['files'])


if __name__ == '__main__':
    unittest.main()
