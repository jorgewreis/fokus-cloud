#!/usr/bin/env python3
"""Require fresh operator-reviewed runtime evidence before supervised switching.

This verifies binding and expiry, not the truth of the operator's observations.
It must never be described as automatic inspection of FPM or its processes.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import time


class MissingRuntimeEvidence(ValueError):
    pass


def require(condition, code):
    if not condition:
        raise MissingRuntimeEvidence(code)


def validate_record(record, *, root, receipt_sha256, node_sha256, boot_id,
                    operator_uid, runtime_uid, runtime_gid, now):
    require(isinstance(record, dict) and record.get('schema') == 'fokus-runtime-access-v1', 'runtime_evidence_schema')
    require(record.get('root') == str(root) and record.get('receipt_sha256') == receipt_sha256
            and record.get('node_sha256') == node_sha256 and record.get('boot') == boot_id
            and record.get('operator_uid') == operator_uid, 'runtime_evidence_binding')
    require(type(runtime_uid) is int and runtime_uid > 0 and runtime_uid != operator_uid
            and type(runtime_gid) is int and runtime_gid > 0
            and record.get('runtime_uid') == runtime_uid and record.get('runtime_gid') == runtime_gid,
            'runtime_principal_binding')
    groups = record.get('effective_groups')
    require(isinstance(groups, list) and groups and all(type(group) is int and group > 0 for group in groups)
            and len(set(groups)) == len(groups) and runtime_gid in groups, 'runtime_groups_incomplete')
    capabilities = record.get('capabilities')
    require(isinstance(capabilities, dict) and set(capabilities) == {'effective', 'permitted', 'inheritable', 'ambient'}
            and all(type(value) is int and value == 0 for value in capabilities.values()), 'runtime_capabilities_not_clear')
    for key in ('all_workers_covered', 'groups_privileges_reviewed', 'acl_and_write_access_reviewed'):
        require(record.get(key) is True, 'runtime_review_incomplete')
    reference = record.get('evidence_reference')
    require(isinstance(reference, str) and bool(reference.strip()), 'runtime_reference_required')
    observed = record.get('observed_at')
    expires = record.get('expires_at')
    require(type(observed) in (int, float) and type(expires) in (int, float)
            and observed <= now < expires <= observed + 300, 'runtime_evidence_expired')
    return expires


def read_verified(policy, *, root, receipt, node_sha256, boot_id, operator_uid, protect, now=None):
    path_value = policy.get('runtime_evidence_path')
    digest = policy.get('runtime_evidence_sha256')
    require(isinstance(path_value, str) and bool(path_value) and isinstance(digest, str)
            and re.fullmatch('[0-9a-f]{64}', digest) is not None, 'runtime_evidence_required')
    require(Path(path_value).is_absolute(), 'runtime_evidence_path_not_absolute')
    path = Path(path_value).absolute()
    protect(path)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == operator_uid
                and not info.st_mode & 0o077 and info.st_size <= 65536, 'runtime_evidence_file_unsafe')
        with os.fdopen(fd, 'rb', closefd=False) as stream:
            data = stream.read(65537)
    finally:
        os.close(fd)
    require(len(data) <= 65536 and hashlib.sha256(data).hexdigest() == digest, 'runtime_evidence_digest')
    def unique_pairs(pairs):
        result = {}
        for key, value in pairs:
            require(key not in result, 'runtime_evidence_duplicate_key')
            result[key] = value
        return result
    try:
        record = json.loads(data, object_pairs_hook=unique_pairs)
    except (ValueError, UnicodeError) as error:
        raise MissingRuntimeEvidence('runtime_evidence_invalid_json') from error
    return validate_record(record, root=root, receipt_sha256=hashlib.sha256(Path(receipt).read_bytes()).hexdigest(),
                           node_sha256=node_sha256, boot_id=boot_id, operator_uid=operator_uid,
                           runtime_uid=policy.get('runtime_uid'), runtime_gid=policy.get('runtime_gid'),
                           now=time.time() if now is None else now)
