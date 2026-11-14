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
import types

EXECUTOR_ROOT = Path(__file__).resolve().parents[2]


def load_dependencies(source, environment):
    """Check local project code against the exact SHA before executing imports.

    The launcher itself and the Git executable remain in the trust boundary.
    """
    if not sys.flags.isolated:
        raise ValueError('isolated_python_required')
    expected = environment.get('GITHUB_SHA', '')
    if not re.fullmatch(r'[0-9a-f]{40}', expected):
        raise ValueError('missing_executor_sha')
    head = subprocess.check_output(['/usr/bin/git', '-C', str(source), 'rev-parse', '--verify', 'HEAD'],
                                   text=True, stderr=subprocess.DEVNULL).strip()
    if head != expected:
        raise ValueError('executor_sha_mismatch')
    names = (('preparation_guard', 'tools/releases/preparation_guard.py'),
             ('publication_gate', 'deploy/publication_gate.py'),
             ('derived_assets', 'tools/releases/derived_assets.py'),
             ('artifact_ssh', 'tools/releases/artifact_ssh.py'))
    verified = []
    for name, relative in (('_launcher', 'tools/releases/publication_entry.py'), *names):
        path = source / relative
        if path != path.resolve(strict=True):
            raise ValueError('executor_symlink')
        committed = subprocess.check_output(['/usr/bin/git', '-C', str(source), 'show', head + ':' + relative],
                                            stderr=subprocess.DEVNULL)
        if path.read_bytes() != committed:
            raise ValueError('executor_import_mismatch')
        if name != '_launcher':
            verified.append((name, path, committed))
    modules = {}
    for name, path, code in verified:
        if name in sys.modules:
            raise ValueError('executor_module_already_loaded')
        module = types.ModuleType(name)
        module.__file__ = str(path)
        sys.modules[name] = module
        exec(compile(code, str(path), 'exec'), module.__dict__)
        modules[name] = module
    return modules['publication_gate'], modules['derived_assets'], modules['artifact_ssh']


def run(source, ssh_options, prepare, environment):
    source = Path(source).resolve(strict=True)
    if source != EXECUTOR_ROOT:
        raise ValueError('executor_source_mismatch')
    publication_gate, derived_assets, artifact_ssh = load_dependencies(source, environment)
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
