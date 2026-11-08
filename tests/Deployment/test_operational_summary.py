"""Regression checks for phase evidence and the workflow's safety boundaries."""

import importlib.util
from pathlib import Path
import os
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("summary", ROOT / "tools/ci/summarize-deployment.py")
SUMMARY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SUMMARY)


class PublicationSummaryTests(unittest.TestCase):
    def successful(self):
        return {**{"PHASE_" + key: "success" for key, _ in SUMMARY.PHASES}, "FPM_RELOAD_MODE": "reload"}

    def test_success_requires_every_mandatory_phase(self):
        report, complete = SUMMARY.render(self.successful())
        self.assertTrue(complete)
        self.assertIn("Automated publication checks passed.", report)
        for key, _ in SUMMARY.PHASES:
            for outcome in ("failure", "cancelled", "unknown", "", "skipped"):
                with self.subTest(phase=key, outcome=outcome):
                    env = self.successful()
                    env["PHASE_" + key] = outcome
                    _, complete = SUMMARY.render(env)
                    self.assertEqual(complete, key == "CDN" and outcome == "skipped")

    def test_missing_or_injected_outcomes_never_leak_or_pass(self):
        env = self.successful()
        env["PHASE_FILES"] = "SECRET_TOKEN_OR_REMOTE_BODY"
        report, complete = SUMMARY.render(env)
        self.assertFalse(complete)
        self.assertNotIn("SECRET_TOKEN", report)
        self.assertFalse(SUMMARY.render({})[1])

    def test_verify_only_does_not_claim_reload_or_opcache(self):
        env = self.successful()
        env["FPM_RELOAD_MODE"] = "verify-only"
        report, complete = SUMMARY.render(env)
        self.assertTrue(complete)
        self.assertIn("not requested (verify-only); no OPcache claim", report)
        env["FPM_RELOAD_MODE"] = "unreviewed"
        self.assertFalse(SUMMARY.render(env)[1])

    def test_partial_publication_is_not_rollback_success(self):
        env = self.successful()
        env["PHASE_RELEASE"] = "failure"
        report, complete = SUMMARY.render(env)
        self.assertFalse(complete)
        self.assertIn("Remote writes may have occurred", report)
        self.assertIn("do not automatically roll back migrations", report)


class WorkflowBoundaryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = (ROOT / ".github/workflows/deploy.yml").read_text()
        cls.deployment = cls.workflow.split("  deploy:\n", 1)[1]

    def test_policy_and_required_application_checks_remain(self):
        self.assertIn("if: github.ref == 'refs/heads/main'", self.deployment)
        self.assertRegex(self.deployment, r"needs: (validate|\[[^\]]*validate[^\]]*\])")
        self.assertIn("environment: production", self.deployment)
        self.assertIn("cancel-in-progress: false", self.workflow)
        for command in ("php artisan test", "npm run tokens:check", "npm run seo:check", "npm run backoffice:contract:check", "npm run test:backoffice:visual"):
            self.assertIn(command, self.workflow)
        self.assertNotIn("continue-on-error", self.workflow)
        self.assertNotIn("sudo -n systemctl restart", self.workflow)
        self.assertNotIn("continuing deployment", self.workflow)

    def test_preflight_precedes_all_remote_mutations(self):
        preflight = self.deployment.index("verify-deployment.py preflight")
        probe = self.deployment.index("verify-deployment.py probe-current")
        ssh = self.deployment.index("- name: Configure SSH")
        schema = self.deployment.index("- name: Verify Law schema")
        self.assertLess(preflight, probe)
        self.assertLess(probe, ssh)
        self.assertLess(ssh, schema)

    def test_old_outer_echo_reproduces_false_green(self):
        result = subprocess.run(["bash", "-e", "-c", "(exit 255) || echo 'continuing deployment'"], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0)

    def test_workflow_preserves_ssh_failure_and_never_runs_restart(self):
        step = self.deployment.split("- name: Apply explicitly selected PHP-FPM reload policy", 1)[1].split("      - name:", 1)[0]
        script = step.split("        run: |\n", 1)[1]
        script = "\n".join(line[10:] for line in script.splitlines() if line.startswith("          "))
        with tempfile.TemporaryDirectory(prefix="fokus-synthetic-ssh-") as temporary:
            fake = Path(temporary) / "ssh"
            fake.write_text('#!/bin/sh\ncat >/dev/null\nexit "$SYNTHETIC_SSH_STATUS"\n')
            fake.chmod(0o700)
            for code in (0, 1, 42, 255):
                with self.subTest(exit_code=code):
                    env = {**os.environ, "PATH": temporary + ":" + os.environ["PATH"], "SYNTHETIC_SSH_STATUS": str(code), "DEPLOY_HOST": "synthetic.invalid", "DEPLOY_USER": "synthetic", "DEPLOY_PORT": "2222", "FPM_RELOAD_MODE": "reload", "FPM_SERVICE": "php8.4-fpm.service"}
                    result = subprocess.run(["bash", "-e", "-c", script], cwd=ROOT, env=env, capture_output=True, text=True)
                    self.assertEqual(result.returncode, code)
                    self.assertNotIn("continuing", result.stdout)

    def test_summary_is_always_and_release_after_runtime(self):
        self.assertLess(self.deployment.index("id: services"), self.deployment.index("id: release"))
        self.assertLess(self.deployment.index("id: release"), self.deployment.index("- name: Report each publication phase"))
        summary = self.deployment.split("- name: Report each publication phase", 1)[1]
        self.assertIn("if: ${{ always() }}", summary)
        for key, _ in SUMMARY.PHASES:
            self.assertIn("PHASE_" + key + ":", summary)


if __name__ == "__main__":
    unittest.main()
