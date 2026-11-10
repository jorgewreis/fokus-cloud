"""Local reconstruction evidence gate. Never grants permission to publish.

Snapshot format: {"files": [{"path": "relative/path", "sha256": "..."}]}.
Snapshot creation belongs to the freeze coordinator, not this verifier.
The legacy Git harnesses remain unchanged and require a separately approved Git run.
"""
import argparse
import hashlib
import json
import pathlib
import sys

BASELINE = '26daac7c27d246eaef64bb3f0e59499376993f8f'
EXPECTED_NEW_MIGRATIONS = [
    '2026_11_06_000200_separate_law_case_confidentiality.php',
    '2026_11_06_140000_create_law_case_publication_reviews.php',
    '2026_11_06_160000_create_law_contact_import_batches_table.php',
    '2026_11_06_170000_create_law_tasks.php',
    '2026_11_07_000100_repair_payments_provider_checkout_url.php',
]
EXCLUDED = {'.git', 'vendor', 'node_modules', 'storage', 'test-results', 'playwright-report', '__pycache__'}
# These are distinct verified execution results: absence of automatic rollback in
# delivery, demonstrated destructive loss in an isolated reversal, and a validated
# backup restore. Migrations themselves do not universally prohibit down().
CHECKS = {
    'populated-upgrade', 'old-data-integrity', 'five-migration-ledger',
    'repair-column-forward-only',
    'schema-confidentiality', 'schema-publication-reviews', 'schema-import-batches',
    'schema-tasks-events-permissions', 'idempotence', 'roll-forward-invariants',
    'automatic-destructive-rollback-disabled', 'reversal-risk-demonstrated',
    'backup-restore-verified', 'controlled-failure', 'mysql-concurrency',
    'php-suite', 'browser-authenticated', 'redis', 'assets-build',
}


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def safe_file(root, value):
    path = pathlib.PurePosixPath(value)
    if path.is_absolute() or '..' in path.parts or '\\' in value or ':' in value:
        raise ValueError('Unsafe evidence/manifest path: ' + value)
    result = (root / value).resolve()
    if not result.is_relative_to(root.resolve()) or not result.is_file():
        raise ValueError('Missing or escaping file: ' + value)
    return result


def source_files(root, baseline_manifest):
    """Inventory source; runtime placeholders are allowed only by reviewed baseline."""
    if baseline_manifest.get('baseline') != BASELINE:
        raise ValueError('Source inventory requires the explicit reviewed baseline manifest')
    tracked_placeholders = {
        row['path'] for row in baseline_manifest['files']
        if pathlib.PurePosixPath(row['path']).name == '.gitignore'
        and row['path'].startswith(('storage/', 'bootstrap/cache/'))
    }
    result = set()
    for p in root.rglob('*'):
        if not p.is_file():
            continue
        relative = p.relative_to(root).as_posix()
        if relative in tracked_placeholders:
            safe_file(root, relative)
            result.add(relative)
            continue
        if (any(part in EXCLUDED for part in p.relative_to(root).parts)
                or (p.name.startswith('.env') and not p.name.endswith('.example'))
                or p.name.endswith('.pyc')
                or relative.startswith(('bootstrap/cache/', 'public/build/', 'public/portal/assets/immutable/'))):
            continue
        result.add(relative)
    # Missing tracked placeholders must not disappear silently from a complete manifest.
    for relative in tracked_placeholders:
        safe_file(root, relative)
    return result


def verify_manifest(root, manifest, exact=False, baseline_manifest=None):
    rows = manifest['files']
    names = [row['path'] for row in rows]
    if not rows or len(names) != len(set(names)):
        raise ValueError('Empty or duplicate manifest')
    for row in rows:
        if digest(safe_file(root, row['path'])) != row['sha256']:
            raise ValueError('Source changed: ' + row['path'])
    if exact and set(names) != source_files(root, baseline_manifest or {}):
        raise ValueError('Candidate manifest does not cover the complete source tree')
    return hashlib.sha256(json.dumps(sorted((r['path'], r['sha256']) for r in rows), separators=(',', ':')).encode()).hexdigest()


def verify(baseline_dir, candidate_dir, baseline_manifest, candidate_manifest, evidence_path):
    base = json.loads(baseline_manifest.read_text(encoding='utf-8-sig'))
    snap = json.loads(candidate_manifest.read_text(encoding='utf-8-sig'))
    evidence = json.loads(evidence_path.read_text(encoding='utf-8-sig'))
    if base.get('baseline') != BASELINE or evidence.get('baseline_sha') != BASELINE:
        raise ValueError('Explicit baseline differs from reviewed 26daac7 full SHA')
    baseline_hash = verify_manifest(baseline_dir, base)
    candidate_hash = verify_manifest(candidate_dir, snap, exact=True, baseline_manifest=base)
    if evidence.get('candidate_source_sha256') != candidate_hash or evidence.get('baseline_source_sha256') != baseline_hash:
        raise ValueError('Evidence belongs to another source snapshot')
    baseline_migrations = {p.name for p in (baseline_dir / 'database/migrations').glob('*.php')}
    candidate_migrations = {p.name for p in (candidate_dir / 'database/migrations').glob('*.php')}
    new = sorted(candidate_migrations - baseline_migrations)
    if (new != EXPECTED_NEW_MIGRATIONS
            or baseline_migrations - candidate_migrations or evidence.get('new_migrations') != new):
        raise ValueError('Expected exactly five named new migrations including the additive repair and no removed baseline migration')
    if evidence.get('recovery_strategy') not in ('roll-forward', 'validated-backup-restore'):
        raise ValueError('Unsafe or missing recovery strategy')
    if evidence.get('tasks_enabled') is not False or evidence.get('publication_authorized') is not False:
        raise ValueError('Tasks must remain disabled and publication unauthorized')
    checks = evidence.get('checks', {})
    if set(checks) != CHECKS:
        raise ValueError('Required evidence checks missing or unexpected: ' + str(sorted(CHECKS ^ set(checks))))
    for name in sorted(CHECKS):
        item = checks[name]
        if item.get('exit_code') != 0 or item.get('status') != 'passed' or not item.get('command') or not item.get('assertions', 0) > 0:
            raise ValueError('Missing actual successful execution: ' + name)
        log = safe_file(evidence_path.parent, item['log'])
        if not log.stat().st_size or digest(log) != item.get('log_sha256'):
            raise ValueError('Missing or changed execution log: ' + name)
        if item.get('candidate_source_sha256') != candidate_hash:
            raise ValueError('Stale test result: ' + name)
    # Exit status / hashes prove consistency, not truth or sufficiency of supplied assertions.
    # Independent review of assertion bodies and logs remains mandatory.
    return {'status': 'local-evidence-consistent-review-required', 'baseline_sha': BASELINE,
            'candidate_source_sha256': candidate_hash, 'candidate_commit': None,
            'publication_authorized': False, 'new_migrations': new}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--baseline-dir', type=pathlib.Path, required=True)
    parser.add_argument('--candidate-dir', type=pathlib.Path, required=True)
    parser.add_argument('--baseline-manifest', type=pathlib.Path, required=True)
    parser.add_argument('--candidate-manifest', type=pathlib.Path, required=True)
    parser.add_argument('--evidence', type=pathlib.Path, required=True)
    parser.add_argument('--publish', action='store_true', help='Always refused for this reconstruction')
    args = parser.parse_args()
    if args.publish:
        parser.error('Publication blocked: separate authorization and committed-source CI are pending')
    try:
        print(json.dumps(verify(args.baseline_dir.resolve(), args.candidate_dir.resolve(),
                                args.baseline_manifest, args.candidate_manifest, args.evidence), indent=2))
    except (ValueError, KeyError, TypeError, OSError) as error:
        sys.exit('BLOCKED: ' + str(error))


if __name__ == '__main__':
    main()
