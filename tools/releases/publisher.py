#!/usr/bin/env python3
"""Explicit immutable preparation and activation; no SSH, migrations or defaults."""
import argparse
import json
import os
from pathlib import Path
import sys

import derived_assets as derived
import generation_activation as activation
import runtime_evidence


def prepare(source, candidate, evidence, artifact, expected_digest, base, root,
            storage, configuration, receipt, transport, runtime_uid, runtime_gid):
    """Unpack a new candidate, attach approved shared paths and seal it.

    Failure can leave a partial *inactive* root for manual investigation. This
    function never deletes it or changes the active pointer.
    """
    contract = derived.contract(source)
    source, candidate, evidence, artifact, base, root, storage, configuration, receipt, transport = map(
        contract.canonical,
        (source, candidate, evidence, artifact, base, root, storage, configuration, receipt, transport),
    )
    releases = contract.canonical(base / 'releases')
    contract.require(base.is_dir() and releases.is_dir(), 'existing_release_base_required')
    contract.require(source.is_dir() and candidate.is_dir() and evidence.is_file() and artifact.is_file(), 'prepared_input_required')
    contract.require(root.is_relative_to(releases) and root != releases and root.parent.is_dir(), 'new_release_root_required')
    contract.require(not root.exists() and not root.is_symlink(), 'new_destination_required')
    contract.require(not root.is_relative_to(source) and not root.is_relative_to(candidate), 'release_inside_build_tree')
    contract.require(storage.is_dir() and configuration.is_file(), 'shared_targets_required')
    contract.regular(configuration)
    contract.require(not storage.is_relative_to(releases) and not configuration.is_relative_to(releases), 'shared_outside_releases_required')
    contract.require(all(not target.is_relative_to(tree) for target in (storage, configuration) for tree in (source, candidate)), 'shared_outside_build_tree_required')
    for path in (receipt, transport):
        contract.require(not path.exists() and not path.is_symlink() and path.parent.is_dir(), 'new_external_record_required')
        contract.require(not path.is_relative_to(releases) and not path.is_relative_to(storage), 'record_outside_release_and_storage_required')
        contract.require(not path.is_relative_to(source) and not path.is_relative_to(candidate), 'record_outside_build_tree_required')
    contract.require(receipt != transport, 'distinct_records_required')
    contract.require(contract.DIGEST.fullmatch(expected_digest) is not None, 'artifact_digest_required')
    # Linux-only control boundary: private owner/ACL-checked parents and a
    # pre-provisioned exclusive lock remain held through unpack, links and seal.
    # Operator and root are trusted; runtime evidence is still required to switch.
    from preparation_guard import grant_runtime_read, runtime_groups, secure_preparation, verify_runtime_read
    runtime_groups(runtime_uid, runtime_gid)
    with secure_preparation(base, root.parent, receipt.parent, transport.parent,
                            configuration, storage, runtime_uid) as (checkpoint, check_tree):
        unpacked = contract.unpack(artifact, expected_digest, root, transport)
        checkpoint(); check_tree(root, sealed=False)
        grant_runtime_read(root, runtime_uid, runtime_gid)
        checkpoint(); check_tree(root, sealed=False)
        links = contract.shared_links(root, storage, configuration)
        checkpoint(); check_tree(root, sealed=False)
        sealed = derived.seal(source, candidate, evidence, root, receipt, transport, links=links)
        checkpoint(); check_tree(root, sealed=True)
        verify_runtime_read(root, runtime_uid, runtime_gid)
        contract.verify(root, receipt)
        contract.require(unpacked['source_sha'] == sealed['source_sha'] == root.name, 'prepared_identity_mismatch')
    return {'state': 'sealed_not_active', 'source_sha': root.name}


class RuntimeEvidenceContract:
    """Add runtime evidence to the original policy without replacing its checks."""
    def __init__(self, contract):
        self.contract = contract

    def __getattr__(self, name):
        return getattr(self.contract, name)

    def policy(self, policy_file, root, base, receipt):
        policy = self.contract.policy(policy_file, root, base, receipt)
        expiry = runtime_evidence.read_verified(
            policy, root=root, receipt=receipt, node_sha256=self.contract.node_fingerprint(),
            boot_id=activation.boot(), operator_uid=os.geteuid(), protect=activation.protected,
        )
        from preparation_guard import verify_runtime_read
        runtime_evidence.require(Path(root).stat().st_gid == policy['runtime_gid'], 'runtime_root_group_mismatch')
        verify_runtime_read(root, policy['runtime_uid'], policy['runtime_gid'])
        # The existing post-switch expiry check must also cover this evidence.
        policy = dict(policy)
        policy['expires_at_epoch'] = min(policy['expires_at_epoch'], expiry)
        return policy


def switch(source, base, candidate, receipt, policy, previous, previous_receipt, boundary, rollback=False):
    """Delegate switch or code-only rollback to the existing supervised gate."""
    contract = RuntimeEvidenceContract(derived.contract(source))
    return activation.activate(contract, base, candidate, receipt, policy, previous,
                               previous_receipt, boundary, rollback=rollback)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='command', required=True)
    build = commands.add_parser('prepare')
    for name in ('source', 'candidate', 'evidence', 'artifact', 'expected-digest', 'base', 'root',
                 'storage', 'configuration', 'receipt', 'transport'):
        build.add_argument('--' + name, required=True)
    build.add_argument('--runtime-uid', required=True, type=int)
    build.add_argument('--runtime-gid', required=True, type=int)
    for command in ('activate', 'rollback'):
        command_parser = commands.add_parser(command)
        for name in ('source', 'base', 'candidate', 'receipt', 'policy', 'previous', 'previous-receipt', 'boundary'):
            command_parser.add_argument('--' + name, required=True)
    options = vars(parser.parse_args())
    command = options.pop('command')
    try:
        result = prepare(**options) if command == 'prepare' else switch(**options, rollback=command == 'rollback')
        print(json.dumps(result, sort_keys=True))
        return 0
    except Exception:
        # Do not leak local paths, configuration or credentials to CI logs.
        print(json.dumps({'state': 'blocked', 'reason': 'immutable_publication_failed'}), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
