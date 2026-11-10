#!/usr/bin/env python3
"""Law asset gate with real generator/source bytes; synthetic Vite manifests, no full build."""
import importlib.util
import json
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('derived', Path(__file__).with_name('derived_assets.py'))
d = importlib.util.module_from_spec(spec)
spec.loader.exec_module(d)
SOURCE = Path(__file__).resolve().parents[2]
TEMPLATE = 'resources/views/portal/fokus-law.blade.php'
TASKS = 'portal/assets/fokus-law-tasks.js'


class LawFunctionalContractTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        self.original = {TEMPLATE: (SOURCE / TEMPLATE).read_bytes()}
        self.refs = re.findall(r"FokusLawAssets::url\('([^']+)'\)", self.original[TEMPLATE].decode())
        for name in self.refs:
            target = self.root / 'public' / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(SOURCE / 'public' / name, target)
        subprocess.run(['node', str(SOURCE / 'tools/generate-fokus-law-assets.mjs'),
                        '--source-root', str(SOURCE), '--output-root', str(self.root)],
                       check=True, capture_output=True)
        build = self.root / 'public/build'
        build.mkdir()
        (build / 'app.css').write_text('/* synthetic Vite fixture */')
        (build / 'app.js').write_text('// synthetic Vite fixture')
        (build / 'manifest.json').write_text(json.dumps({
            'resources/css/app.css': {'file': 'app.css'},
            'resources/js/app.js': {'file': 'app.js'},
        }))
        (build / 'fonts-manifest.json').write_text('{"version":1}')
        self.manifest = json.loads((self.root / d.LAW).read_bytes())

    def validate(self):
        return d.validate_functional(self.root, self.original)

    def save_manifest(self):
        (self.root / d.LAW).write_text(json.dumps(self.manifest))

    def test_fifteen_unique_assets_include_tasks(self):
        self.assertEqual(len(self.refs), 15)
        self.assertEqual(len(set(self.refs)), 15)
        self.assertIn(TASKS, self.refs)
        self.assertEqual(set(self.manifest['assets']), set(self.refs))
        generated = self.validate()
        self.assertIn('public/' + self.manifest['assets'][TASKS]['path'], generated)

    def test_missing_manifest_asset_rejected(self):
        del self.manifest['assets'][TASKS]
        self.save_manifest()
        with self.assertRaisesRegex(ValueError, 'law_completeness'):
            self.validate()

    def test_extra_manifest_asset_rejected(self):
        self.manifest['assets']['portal/assets/extra.js'] = self.manifest['assets'][TASKS]
        self.save_manifest()
        with self.assertRaisesRegex(ValueError, 'law_completeness'):
            self.validate()

    def test_duplicate_reference_at_same_count_rejected(self):
        self.original[TEMPLATE] = self.original[TEMPLATE].replace(TASKS.encode(), self.refs[0].encode())
        with self.assertRaisesRegex(ValueError, 'law_completeness'):
            self.validate()

    def test_missing_reference_and_matching_manifest_rejected(self):
        self.original[TEMPLATE] = self.original[TEMPLATE].replace(
            ("FokusLawAssets::url('" + TASKS + "')").encode(), b"'removed'")
        del self.manifest['assets'][TASKS]
        self.save_manifest()
        with self.assertRaisesRegex(ValueError, 'law_completeness'):
            self.validate()

    def test_extra_reference_and_matching_manifest_rejected(self):
        self.original[TEMPLATE] += b"FokusLawAssets::url('portal/assets/extra.js')"
        self.manifest['assets']['portal/assets/extra.js'] = self.manifest['assets'][TASKS]
        self.save_manifest()
        with self.assertRaisesRegex(ValueError, 'law_completeness'):
            self.validate()

    def test_manifest_hash_tampering_rejected(self):
        self.manifest['assets'][TASKS]['sha256'] = '0' * 64
        self.save_manifest()
        with self.assertRaisesRegex(ValueError, 'law_provenance'):
            self.validate()

    def test_manifest_path_tampering_rejected(self):
        self.manifest['assets'][TASKS]['path'] = TASKS
        self.save_manifest()
        with self.assertRaisesRegex(ValueError, 'law_provenance'):
            self.validate()

    def test_original_bytes_tampering_rejected(self):
        (self.root / 'public' / TASKS).write_text('tampered')
        with self.assertRaisesRegex(ValueError, 'law_provenance'):
            self.validate()

    def test_hashed_bytes_tampering_rejected(self):
        (self.root / 'public' / self.manifest['assets'][TASKS]['path']).write_text('tampered')
        with self.assertRaisesRegex(ValueError, 'law_content'):
            self.validate()

    def test_missing_hashed_file_rejected(self):
        (self.root / 'public' / self.manifest['assets'][TASKS]['path']).unlink()
        with self.assertRaises(FileNotFoundError):
            self.validate()


if __name__ == '__main__':
    unittest.main()
