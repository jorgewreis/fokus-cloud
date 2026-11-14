#!/usr/bin/env python3
"""Non-destructive checks before sealing a derived artifact with shared links."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('derived', Path(__file__).with_name('derived_assets.py'))
derived = importlib.util.module_from_spec(spec)
spec.loader.exec_module(derived)


class StubContract:
    @staticmethod
    def validate_links(root, links):
        if set(links) - {'storage', '.env'}:
            raise ValueError('shared_link_allowlist')


class DerivedSharedLinkPreflight(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='derived-link-proof-')
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.root = self.base / ('a' * 40)
        self.root.mkdir()
        self.target = self.base / 'shared.env'
        self.target.write_bytes(b'synthetic configuration')
        self.receipt = self.base / 'receipt.json'
        self.proof = {'source_sha': self.root.name, 'mode': 'complete-runtime', 'outputs': {}}

    def seal(self, links):
        with patch.object(derived, 'verified_build', return_value=self.proof), \
             patch.object(derived, 'contract', return_value=StubContract()):
            return derived.seal(self.base, self.base, self.base, self.root, self.receipt,
                                self.base / 'transport.json', links=links)

    def test_declared_missing_link_is_rejected_before_writing_or_chmod(self):
        before = self.root.stat().st_mode
        with self.assertRaisesRegex(ValueError, 'shared_link_mismatch'):
            self.seal({'.env': str(self.target)})
        self.assertEqual(self.root.stat().st_mode, before)
        self.assertFalse((self.root / '.release').exists())
        self.assertFalse(self.receipt.exists())
        self.assertEqual(self.target.read_bytes(), b'synthetic configuration')

    def test_unknown_link_is_rejected_before_writing(self):
        with self.assertRaisesRegex(ValueError, 'shared_link_allowlist'):
            self.seal({'unexpected': str(self.target)})
        self.assertFalse((self.root / '.release').exists())
        self.assertFalse(self.receipt.exists())
        self.assertEqual(self.target.read_bytes(), b'synthetic configuration')


if __name__ == '__main__':
    unittest.main()
