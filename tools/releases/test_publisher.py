#!/usr/bin/env python3
"""No-network safety checks for immutable publication orchestration."""
import importlib.util
from pathlib import Path
import re
import sys
import tempfile
import types
import unittest
from unittest.mock import Mock, patch

MODULE = Path(__file__).with_name('publisher.py')
sys.path.insert(0, str(MODULE.parent))
# The real activation module uses Linux-only fcntl; the delegation contract is
# verified here with a double and exercised separately with real Linux processes.
activation_double = types.ModuleType('generation_activation')
activation_double.activate = Mock()
with patch.dict(sys.modules, {'generation_activation': activation_double}):
    spec = importlib.util.spec_from_file_location('publisher_under_test', MODULE)
    publisher = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(publisher)


class Contract:
    DIGEST = re.compile(r'[a-f0-9]{64}')

    def __init__(self):
        self.unpack = Mock(side_effect=AssertionError('unpack must not be reached'))

    @staticmethod
    def canonical(value):
        return Path(value).resolve()

    @staticmethod
    def require(condition, code):
        if not condition:
            raise ValueError(code)

    @staticmethod
    def regular(path):
        if not path.is_file():
            raise ValueError('regular_file_required')


class PublisherPreflight(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='publisher-proof-')
        self.addCleanup(self.temp.cleanup)
        base = Path(self.temp.name)
        self.control = base / 'control'
        self.releases = self.control / 'releases'
        self.releases.mkdir(parents=True)
        self.storage = base / 'shared'
        self.storage.mkdir()
        self.configuration = base / 'synthetic.env'
        self.configuration.write_text('# synthetic')
        source = base / 'source'
        source.mkdir()
        (base / 'build').mkdir()
        (base / 'evidence').write_text('synthetic evidence')
        (base / 'artifact').write_bytes(b'synthetic artifact')
        self.args = dict(source=source, candidate=base / 'build', evidence=base / 'evidence',
                         artifact=base / 'artifact', expected_digest='a'*64, base=self.control,
                         root=self.releases / ('b'*40), storage=self.storage,
                         configuration=self.configuration, receipt=base / 'receipt',
                         transport=base / 'transport')
        self.contract = Contract()

    def invoke(self):
        with patch.object(publisher.derived, 'contract', return_value=self.contract):
            return publisher.prepare(**self.args)

    def test_rejects_existing_root_without_unpack(self):
        self.args['root'].mkdir()
        with self.assertRaisesRegex(ValueError, 'new_destination_required'):
            self.invoke()
        self.contract.unpack.assert_not_called()

    def test_rejects_shared_storage_inside_release_tree(self):
        inside = self.releases / 'shared'
        inside.mkdir()
        self.args['storage'] = inside
        with self.assertRaisesRegex(ValueError, 'shared_outside_releases_required'):
            self.invoke()
        self.contract.unpack.assert_not_called()

    def test_rejects_shared_storage_inside_source_tree(self):
        inside = self.args['source'] / 'storage'
        inside.mkdir()
        self.args['storage'] = inside
        with self.assertRaisesRegex(ValueError, 'shared_outside_build_tree_required'):
            self.invoke()
        self.contract.unpack.assert_not_called()

    def test_rejects_record_inside_source_tree(self):
        self.args['receipt'] = self.args['source'] / 'receipt'
        with self.assertRaisesRegex(ValueError, 'record_outside_build_tree_required'):
            self.invoke()
        self.contract.unpack.assert_not_called()

    def test_rejects_receipt_inside_release_tree(self):
        self.args['receipt'] = self.releases / 'receipt'
        with self.assertRaisesRegex(ValueError, 'record_outside_release_and_storage_required'):
            self.invoke()
        self.contract.unpack.assert_not_called()

    def test_rejects_invalid_digest_without_unpack(self):
        self.args['expected_digest'] = 'bad'
        with self.assertRaisesRegex(ValueError, 'artifact_digest_required'):
            self.invoke()
        self.contract.unpack.assert_not_called()

    def test_unpack_failure_never_links_seals_or_switches(self):
        self.contract.unpack.side_effect = ValueError('artifact_digest_mismatch')
        self.contract.shared_links = Mock()
        with patch.object(publisher.derived, 'contract', return_value=self.contract), \
             patch.object(publisher.derived, 'seal') as seal, \
             patch.object(publisher.activation, 'activate') as activate:
            with self.assertRaisesRegex(ValueError, 'artifact_digest_mismatch'):
                publisher.prepare(**self.args)
        self.contract.shared_links.assert_not_called()
        seal.assert_not_called()
        activate.assert_not_called()
        self.assertFalse(self.args['root'].exists())

    def test_code_rollback_is_delegated_to_supervised_gate(self):
        gate = Mock(return_value={'state': 'verified'})
        with patch.object(publisher.derived, 'contract', return_value=self.contract), \
             patch.object(publisher.activation, 'activate', gate):
            result = publisher.switch('source', 'base', 'candidate', 'receipt', 'policy',
                                      'previous', 'previous-receipt', 'boundary', rollback=True)
        self.assertEqual(result, {'state': 'verified'})
        gate.assert_called_once_with(self.contract, 'base', 'candidate', 'receipt', 'policy',
                                     'previous', 'previous-receipt', 'boundary', rollback=True)


if __name__ == '__main__':
    unittest.main()
