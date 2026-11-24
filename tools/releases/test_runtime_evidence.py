#!/usr/bin/env python3
"""Fail-closed checks for separately reviewed runtime access evidence."""
import copy
import hashlib
import json
import os
import tempfile
import time
import unittest
from pathlib import Path

import runtime_evidence as evidence


class RuntimeEvidenceContract(unittest.TestCase):
    def setUp(self):
        self.now = 1000
        self.expected = dict(root=Path('/synthetic/release'), receipt_sha256='a'*64,
                             node_sha256='b'*64, boot_id='synthetic-boot',
                             operator_uid=1000, runtime_uid=1001, runtime_gid=1002, now=self.now)
        self.record = dict(schema='fokus-runtime-access-v1', root=str(self.expected['root']),
                           receipt_sha256='a'*64, node_sha256='b'*64, boot='synthetic-boot',
                           operator_uid=1000, runtime_uid=1001, runtime_gid=1002,
                           effective_groups=[1002, 1003],
                           capabilities=dict(effective=0, permitted=0, inheritable=0, ambient=0),
                           all_workers_covered=True, groups_privileges_reviewed=True,
                           acl_and_write_access_reviewed=True,
                           evidence_reference='synthetic operator-reviewed fixture',
                           observed_at=990, expires_at=1100)

    def test_complete_bound_evidence_is_accepted_only_until_its_expiry(self):
        self.assertEqual(evidence.validate_record(self.record, **self.expected), 1100)
        with self.assertRaisesRegex(evidence.MissingRuntimeEvidence, 'runtime_evidence_expired'):
            evidence.validate_record(self.record, **{**self.expected, 'now': 1100})

    def test_missing_wrong_or_unreviewed_data_is_rejected(self):
        cases = [
            ('schema', 'wrong', 'runtime_evidence_schema'),
            ('root', '/other', 'runtime_evidence_binding'),
            ('receipt_sha256', 'c'*64, 'runtime_evidence_binding'),
            ('node_sha256', 'c'*64, 'runtime_evidence_binding'),
            ('boot', 'other', 'runtime_evidence_binding'),
            ('operator_uid', 1, 'runtime_evidence_binding'),
            ('runtime_uid', 4, 'runtime_principal_binding'),
            ('runtime_gid', 4, 'runtime_principal_binding'),
            ('effective_groups', [], 'runtime_groups_incomplete'),
            ('effective_groups', [0, 1002], 'runtime_groups_incomplete'),
            ('effective_groups', [1002, 1002], 'runtime_groups_incomplete'),
            ('effective_groups', [1003], 'runtime_groups_incomplete'),
            ('capabilities', {'effective': 1, 'permitted': 0, 'inheritable': 0, 'ambient': 0}, 'runtime_capabilities_not_clear'),
            ('capabilities', {'effective': 0}, 'runtime_capabilities_not_clear'),
            ('all_workers_covered', False, 'runtime_review_incomplete'),
            ('groups_privileges_reviewed', False, 'runtime_review_incomplete'),
            ('acl_and_write_access_reviewed', False, 'runtime_review_incomplete'),
            ('evidence_reference', ' ', 'runtime_reference_required'),
            ('observed_at', 1001, 'runtime_evidence_expired'),
            ('expires_at', 1291, 'runtime_evidence_expired'),
        ]
        for key, value, expected in cases:
            with self.subTest(key=key, value=value):
                changed = copy.deepcopy(self.record)
                changed[key] = value
                with self.assertRaisesRegex(evidence.MissingRuntimeEvidence, expected):
                    evidence.validate_record(changed, **self.expected)

    def test_root_identity_and_privileged_uid_gid_are_never_accepted(self):
        for key in ('runtime_uid', 'runtime_gid'):
            with self.subTest(key=key):
                with self.assertRaisesRegex(evidence.MissingRuntimeEvidence, 'runtime_principal_binding'):
                    evidence.validate_record(self.record, **{**self.expected, key: 0})
        with self.assertRaisesRegex(evidence.MissingRuntimeEvidence, 'runtime_principal_binding'):
            evidence.validate_record(self.record, **{**self.expected, 'runtime_uid': 1000})


@unittest.skipUnless(hasattr(os, 'O_NOFOLLOW') and hasattr(os, 'geteuid'), 'requires POSIX file controls')
class RuntimeEvidenceFile(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='runtime-evidence-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.receipt = self.root / 'receipt.json'
        self.receipt.write_bytes(b'synthetic receipt')
        self.path = self.root / 'runtime.json'
        self.operator = os.geteuid()
        self.uid = self.operator + 10001
        self.record = dict(schema='fokus-runtime-access-v1', root=str(self.root),
                           receipt_sha256=hashlib.sha256(self.receipt.read_bytes()).hexdigest(),
                           node_sha256='b'*64, boot='synthetic-boot', operator_uid=self.operator,
                           runtime_uid=self.uid, runtime_gid=1002, effective_groups=[1002],
                           capabilities=dict(effective=0, permitted=0, inheritable=0, ambient=0),
                           all_workers_covered=True, groups_privileges_reviewed=True,
                           acl_and_write_access_reviewed=True, evidence_reference='synthetic',
                           observed_at=990, expires_at=1100)
        self.write(json.dumps(self.record).encode())

    def write(self, data):
        self.path.write_bytes(data)
        self.path.chmod(0o600)
        self.digest = hashlib.sha256(data).hexdigest()

    def read(self, **overrides):
        policy = dict(runtime_evidence_path=str(self.path), runtime_evidence_sha256=self.digest,
                      runtime_uid=self.uid, runtime_gid=1002)
        policy.update(overrides)
        return evidence.read_verified(policy, root=self.root, receipt=self.receipt,
                                      node_sha256='b'*64, boot_id='synthetic-boot',
                                      operator_uid=self.operator, protect=lambda path: None, now=1000)

    def test_private_matching_file_is_accepted(self):
        self.assertEqual(self.read(), 1100)

    def test_tamper_world_readable_and_duplicate_keys_are_rejected(self):
        with self.assertRaisesRegex(evidence.MissingRuntimeEvidence, 'runtime_evidence_digest'):
            self.read(runtime_evidence_sha256='0'*64)
        self.path.chmod(0o644)
        with self.assertRaisesRegex(evidence.MissingRuntimeEvidence, 'runtime_evidence_file_unsafe'):
            self.read()
        raw=json.dumps(self.record).encode()[:-1]+b',"schema":"fokus-runtime-access-v1"}'
        self.write(raw)
        with self.assertRaisesRegex(evidence.MissingRuntimeEvidence, 'runtime_evidence_invalid_json'):
            self.read()


if __name__ == '__main__':
    unittest.main()
