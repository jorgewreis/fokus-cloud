"""The legacy deploy cannot mutate even a sealed synthetic release."""
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
GUARD = ROOT / 'deploy/legacy_publication_guard.py'
WORKFLOW = ROOT / '.github/workflows/deploy.yml'


class LegacyPublicationGuard(unittest.TestCase):
    def test_refuses_without_touching_a_sealed_root_and_has_no_environment_bypass(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / ('a' * 40)
            seal = root / '.release' / 'manifest.json'
            seal.parent.mkdir(parents=True)
            seal.write_bytes(b'synthetic sealed release')
            for bypass in ('', '1', 'true', 'force', 'production'):
                env = dict(os.environ, DEPLOY_PATH=str(root), ALLOW_INPLACE=bypass,
                           FOKUS_DEPLOY_FORCE=bypass, FPM_RELOAD_MODE='reload')
                result = subprocess.run([sys.executable, str(GUARD)], env=env,
                                        capture_output=True, text=True, check=False)
                self.assertEqual(result.returncode, 1)
                self.assertEqual(result.stdout, '')
                self.assertEqual(result.stderr.strip(), 'publication_blocked=legacy_in_place_mutates_active_release')
                self.assertEqual(seal.read_bytes(), b'synthetic sealed release')

    def test_workflow_requires_the_guard_before_every_remote_operation(self):
        workflow = WORKFLOW.read_text(encoding='utf-8')
        guard = '      - name: Block legacy in-place publication\n'
        self.assertEqual(workflow.count(guard), 1)
        start = workflow.index(guard)
        end = workflow.index('      - name:', start + len(guard))
        block = workflow[start:end]
        self.assertIn('        run: python3 deploy/legacy_publication_guard.py\n', block)
        self.assertNotIn('continue-on-error', block)
        self.assertNotIn('        if:', block)
        for remote in ('      - name: Configure SSH',
                       '      - name: Verify Law schema before publishing application code',
                       '      - name: Stage immutable Fokus Law assets before application references',
                       '      - name: Sync application source',
                       '      - name: Install dependencies on server'):
            self.assertLess(start, workflow.index(remote))


if __name__ == '__main__':
    unittest.main()
