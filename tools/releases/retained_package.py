"""Build-only immutable package. No host activation, credentials or receipts."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import tempfile


def build_package(source, output, expected_sha):
    source = Path(source).absolute()
    output = Path(output).absolute()
    if not re.fullmatch(r'[0-9a-f]{40}', expected_sha):
        raise ValueError('full_sha_required')
    if source != source.resolve(strict=True) or output != output.resolve():
        raise ValueError('canonical_paths_required')
    if output.exists() or output.is_relative_to(source):
        raise ValueError('new_external_output_required')
    spec = importlib.util.spec_from_file_location('package_builder', Path(__file__).with_name('derived_assets.py'))
    builder = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(builder)
    revision, _, _ = builder.source_snapshot(source)
    if revision != expected_sha:
        raise ValueError('requested_sha_mismatch')
    output.mkdir()
    with tempfile.TemporaryDirectory(prefix='fokus-retained-package-') as work:
        candidate = Path(work) / 'candidate'
        proof = builder.build(source, candidate)
        if proof['source_sha'] != expected_sha:
            raise ValueError('build_sha_mismatch')
        evidence = output / 'build-proof.json'
        evidence.write_bytes(builder.encoded(proof))
        artifact = output / 'package.tar'
        # Existing package() performs independent rebuild + provenance validation.
        result = builder.package(source, candidate, evidence, artifact)
        if result['source_sha'] != expected_sha:
            raise ValueError('package_sha_mismatch')
        summary = {'state': 'packaged_not_deployed', 'source_sha': expected_sha,
                   'artifact_sha256': hashlib.sha256(artifact.read_bytes()).hexdigest(),
                   'provenance_sha256': hashlib.sha256(evidence.read_bytes()).hexdigest()}
        (output / 'package-summary.json').write_text(json.dumps(summary, sort_keys=True, indent=2))
        return summary


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--source', required=True)
    p.add_argument('--output', required=True)
    p.add_argument('--expected-sha', required=True)
    args = p.parse_args()
    print(json.dumps(build_package(args.source, args.output, args.expected_sha), sort_keys=True))


if __name__ == '__main__':
    main()
