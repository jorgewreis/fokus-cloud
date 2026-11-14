"""Synthetic local-evidence controls; never authorize publication."""
import importlib.util
import json
import pathlib
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('release_gate', pathlib.Path(__file__).with_name('release-gate.py'))
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class ReleaseEvidenceContracts(unittest.TestCase):
    def exercise(self, count=6, repair=True, missing_check=None, substitute=False):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            base, candidate = root / 'base', root / 'candidate'
            for directory in (base, candidate):
                (directory / 'database/migrations').mkdir(parents=True)
                (directory / 'database/migrations/old.php').write_text('baseline')
            names = list(gate.EXPECTED_NEW_MIGRATIONS[:count])
            if not repair:
                names[names.index('2026_11_07_000100_repair_payments_provider_checkout_url.php')] = 'unexpected.php'
            if substitute:
                names[0] = 'unexpected.php'
            for name in names:
                (candidate / 'database/migrations' / name).write_text('synthetic migration')
            def manifest(directory):
                return {'baseline': gate.BASELINE, 'files': [
                    {'path': p.relative_to(directory).as_posix(), 'sha256': gate.digest(p)}
                    for p in sorted(directory.rglob('*')) if p.is_file()]}
            b, c = manifest(base), manifest(candidate)
            bh = gate.verify_manifest(base, b)
            ch = gate.verify_manifest(candidate, c, exact=True, baseline_manifest=b)
            log = root / 'synthetic.log'
            log.write_text('Actual evidence is required in real use; this is only a unit fixture.')
            checks = {name: {'exit_code': 0, 'status': 'passed', 'command': 'synthetic unit fixture',
                            'assertions': 1, 'log': log.name, 'log_sha256': gate.digest(log),
                            'candidate_source_sha256': ch} for name in gate.CHECKS}
            if missing_check:
                del checks[missing_check]
            evidence = {'baseline_sha': gate.BASELINE, 'baseline_source_sha256': bh,
                        'candidate_source_sha256': ch, 'new_migrations': sorted(names),
                        'recovery_strategy': 'roll-forward', 'tasks_enabled': False,
                        'publication_authorized': False, 'checks': checks}
            for name, data in [('base.json', b), ('candidate.json', c), ('evidence.json', evidence)]:
                (root / name).write_text(json.dumps(data))
            return gate.verify(base, candidate, root / 'base.json', root / 'candidate.json', root / 'evidence.json')

    def test_six_migrations_require_independent_review_not_publication(self):
        result = self.exercise()
        self.assertFalse(result['publication_authorized'])
        self.assertEqual(result['status'], 'local-evidence-consistent-review-required')

    def test_five_migrations_rejected(self):
        with self.assertRaisesRegex(ValueError, 'exactly six'):
            self.exercise(count=5)

    def test_six_without_repair_rejected(self):
        with self.assertRaisesRegex(ValueError, 'additive repair'):
            self.exercise(repair=False)

    def test_repair_evidence_cannot_be_omitted(self):
        with self.assertRaisesRegex(ValueError, 'Required evidence checks'):
            self.exercise(missing_check='repair-column-forward-only')

    def test_each_interest_index_evidence_is_mandatory(self):
        for check in ('interest-index-sixth-migration', 'interest-index-pre-migration-compat',
                      'interest-index-backfill', 'interest-index-concurrency-rollback', 'interest-index-deadlock-retry'):
            with self.subTest(check=check), self.assertRaisesRegex(ValueError, 'Required evidence checks'):
                self.exercise(missing_check=check)

    def test_other_named_migration_cannot_be_substituted(self):
        with self.assertRaisesRegex(ValueError, 'exactly six named'):
            self.exercise(substitute=True)


if __name__ == '__main__':
    unittest.main()
