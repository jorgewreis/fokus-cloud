#!/usr/bin/env python3
"""Gated immutable build and SSH preparation; never activates a release.

GitHub environment and token are mandatory. Remote prepare inputs must be
independently provisioned and verified; an SSH exit is not a receipt proof.
"""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'deploy'))
import publication_gate
import derived_assets
import artifact_ssh

EXECUTOR_ROOT = Path(__file__).resolve().parents[2]


def run(source, ssh_options, prepare, environment):
    source = Path(source).resolve(strict=True)
    if source != EXECUTOR_ROOT:
        raise ValueError('executor_source_mismatch')
    revision, _, _ = derived_assets.source_snapshot(source)
    context = publication_gate.context(environment, revision)
    api = publication_gate.GitHub(context, environment.get('GH_TOKEN', ''))
    validation = publication_gate.verify(context, api)
    if not re.fullmatch(r'[0-9a-f]{40}', context.sha):
        raise ValueError('candidate_identity')
    if Path(prepare['root']).name != context.sha:
        raise ValueError('candidate_root_identity')
    with tempfile.TemporaryDirectory(prefix='fokus-publication-build-') as directory:
        work = Path(directory)
        candidate, evidence, artifact = work / 'candidate', work / 'evidence.json', work / 'artifact.tar'
        proof = derived_assets.build(source, candidate)
        if proof.get('source_sha') != context.sha or proof.get('mode') != 'complete-runtime':
            raise ValueError('build_identity')
        with evidence.open('xb') as stream:
            stream.write(derived_assets.encoded(proof))
        package = derived_assets.package(source, candidate, evidence, artifact)
        if package.get('source_sha') != context.sha:
            raise ValueError('package_identity')
        # Do not use a validation run that changed, failed or was superseded during build.
        if publication_gate.verify(context, api) != validation:
            raise ValueError('validation_changed')
        result = artifact_ssh.send(artifact=artifact, expected_digest=package['artifact_sha256'],
                                   prepare=prepare, **ssh_options)
    if result.get('state') != 'ssh_completed_unverified':
        raise ValueError('transport_result')
    return {'state': 'ssh_completed_unverified', 'candidate_sha': context.sha,
            'validation_run': validation[0], 'validation_attempt': validation[1]}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', required=True)
    for field in ('host', 'user', 'known-hosts', 'identity', 'remote-python', 'remote-receiver',
                  'remote-directory', 'remote-name'):
        parser.add_argument('--' + field, required=True)
    parser.add_argument('--port', required=True, type=int)
    for field in ('source', 'candidate', 'evidence', 'base', 'root', 'storage', 'configuration', 'receipt', 'transport'):
        parser.add_argument('--prepare-' + field, required=True)
    parser.add_argument('--prepare-runtime-uid', required=True, type=int)
    parser.add_argument('--prepare-runtime-gid', required=True, type=int)
    options = vars(parser.parse_args())
    source = options.pop('source')
    prepare = {key.removeprefix('prepare_'): options.pop(key) for key in list(options) if key.startswith('prepare_')}
    try:
        print(json.dumps(run(source, options, prepare, os.environ), sort_keys=True))
        return 0
    except Exception:
        print(json.dumps({'state': 'blocked', 'reason': 'immutable_entrypoint_failed'}), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
