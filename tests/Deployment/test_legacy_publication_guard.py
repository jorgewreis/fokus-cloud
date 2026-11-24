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

    def test_workflow_publishes_by_atomic_switch_and_keeps_the_inplace_refusal(self):
        workflow = WORKFLOW.read_text(encoding='utf-8')
        self.assertNotIn('Block legacy in-place publication', workflow)
        self.assertNotIn('Sync application source', workflow)
        self.assertNotIn('"$DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH/"', workflow)
        for step in ('Seal inactive release at the remote root',
                     'Transfer sealed release into a new directory',
                     'Switch the current symlink atomically',
                     'Verify application health and expected active release'):
            block = workflow.split('      - name: ' + step + '\n', 1)[1].split('      - name:', 1)[0]
            self.assertNotIn('continue-on-error', block)
            self.assertNotIn('\n        if:', block)
        self.assertLess(workflow.index('deploy/publication_gate.py'), workflow.index('probe-current'))
        self.assertLess(workflow.index('probe-current'), workflow.index('Configure SSH'))
        self.assertLess(workflow.index('releases/$EXPECTED_RELEASE/'), workflow.index('atomic_publisher.py\' switch'))
        self.assertLess(workflow.index('atomic_publisher.py\' switch'), workflow.index('fpm_guard_cli.py activate'))
        self.assertLess(workflow.index('verify-deployment.py verify'), workflow.index('atomic_publisher.py\' rollback'))
        self.assertIn('bootstrap_first_sealed_release', workflow)
        self.assertIn("bootstrap_first_sealed_release == true && 'true' || 'false'", workflow)


if __name__ == '__main__':
    unittest.main()
