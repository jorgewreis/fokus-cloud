"""Synthetic Git fixtures only: no production, npm, secrets, or external services."""
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import sys
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('guard', ROOT / 'tools/ci/classify-deployment.py')
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)


class DeploymentGuard(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.previous = Path.cwd()
        os.chdir(self.root)
        self.git('init', '-q')
        self.git('config', 'user.email', 'synthetic@example.invalid')
        self.git('config', 'user.name', 'Synthetic test')
        self.write('app/code.php', '<?php // synthetic')
        self.write('docs/engineering/base.md', '# Knowledge')
        self.base = self.commit()
        self.audit_patch = patch.object(guard, 'AUDIT_DIGEST', guard.audit_digest(self.base))
        self.audit_patch.start()

    def tearDown(self):
        self.audit_patch.stop()
        os.chdir(self.previous)
        self.temp.cleanup()

    def git(self, *args):
        return subprocess.check_output(['git', *args], stderr=subprocess.PIPE).decode().strip()

    def write(self, path, text='# Synthetic'):
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text)

    def commit(self):
        self.git('add', '-A')
        self.git('commit', '-qm', 'synthetic fixture')
        return self.git('rev-parse', 'HEAD')

    def classify(self, head=None):
        return guard.classify('push', {'before': self.base, 'after': head or self.commit()})[0]

    def test_A_documentation_candidate(self):
        self.write('docs/engineering/new.md')
        self.assertTrue(self.classify())

    def test_B_skill_candidate(self):
        for file in ('SKILL.md', 'agents/openai.yaml', 'references/test.md'):
            self.write('.agents/skills/engineering-research/' + file)
        self.assertTrue(self.classify())

    def test_C_application(self):
        self.write('app/code.php', 'changed')
        self.assertFalse(self.classify())

    def test_D_mixed_multiple_commits(self):
        self.write('app/code.php', 'changed')
        self.commit()
        self.write('docs/engineering/new.md')
        self.assertFalse(self.classify())

    def test_E_sensitive_and_unaudited_paths(self):
        for path in ('AGENTS.md', 'README.md', 'docs/templates/page.md', '.github/workflows/deploy.yml',
                     'database/migrations/a.php', 'package.json', 'public/styles/docs/a.md',
                     '.agents/skills/engineering-research/scripts/run.py',
                     '.agents/skills/unknown/SKILL.md', 'docs/engineering/AGENTS.md'):
            self.assertFalse(guard.knowledge_path(path), path)

    def test_F_invalid_comparisons(self):
        for event in ({}, {'before': '0' * 40, 'after': self.base},
                      {'before': 'a' * 40, 'after': self.base},
                      {'before': '--help', 'after': self.base},
                      {'before': self.base, 'after': self.base}):
            self.assertFalse(guard.classify('push', event)[0])
        self.assertFalse(guard.classify('unknown', {})[0])
        self.assertFalse(guard.classify('workflow_dispatch', {})[0])

    def test_renames_inspect_both_paths(self):
        self.git('mv', 'app/code.php', 'docs/engineering/code.md')
        self.assertFalse(self.classify())

    def test_deletion_and_nul_filename_handling(self):
        (self.root / 'docs/engineering/base.md').unlink()
        self.write('docs/engineering/space\nand tab\t.md')
        self.assertTrue(self.classify())

    def test_symlink_and_executable_rejected(self):
        target = self.root / 'docs/engineering/link.md'
        target.symlink_to('../../app/code.php')
        self.assertFalse(self.classify())
        target.unlink()
        self.write('docs/engineering/link.md')
        target.chmod(0o755)
        self.assertFalse(self.classify())

    def test_binary_rejected(self):
        self.write('docs/engineering/binary.md', '\0')
        self.assertFalse(self.classify())

    def test_pr_merge_base_ignores_unrelated_base_updates(self):
        self.git('checkout', '-qb', 'feature')
        self.write('docs/engineering/new.md')
        head = self.commit()
        self.git('checkout', '-qb', 'main-tip', self.base)
        self.write('app/unrelated.php')
        base = self.commit()
        event = {'pull_request': {'base': {'sha': base}, 'head': {'sha': head}}}
        self.assertTrue(guard.classify('pull_request', event)[0])
        self.git('merge', '--no-ff', '-qm', 'merge fixture', 'feature')
        self.assertFalse(guard.classify('push', {'before': base, 'after': self.git('rev-parse', 'HEAD')})[0])

    def test_force_push_rejected(self):
        self.write('docs/engineering/new.md')
        head = self.commit()
        self.assertFalse(guard.classify('push', {'before': self.base, 'after': head, 'forced': True})[0])
        self.assertFalse(guard.classify('push', {'before': head, 'after': self.base})[0])

    def test_generated_ignored_build_files_compared(self):
        before = guard.snapshot(self.root)
        self.write('public/build/assets/app.css', '.text-red { color: red; }')
        self.assertNotEqual(before, guard.snapshot(self.root))
        self.assertNotIn('docs/engineering/base.md', before)

    def test_build_failures_never_exempt(self):
        self.write('docs/engineering/new.md')
        head = self.commit()
        with patch.object(guard.subprocess, 'run', side_effect=OSError('synthetic npm unavailable')):
            # Cleanup has a run too: use no worktree creation for this failure fixture.
            with patch.object(guard, 'git', side_effect=OSError('synthetic git unavailable')):
                with self.assertRaises(OSError):
                    guard.prove_build_equivalence('push', {'before': self.base, 'after': head})

    def test_future_consumer_drift_invalidates_docs_exemption(self):
        self.write('app/new-consumer.php', 'read engineering knowledge at runtime')
        base = self.commit()
        self.write('docs/engineering/new.md')
        head = self.commit()
        self.assertFalse(guard.classify('push', {'before': base, 'after': head})[0])

    def test_snapshot_errors_and_special_files_raise(self):
        def failed_walk(root, **kwargs):
            kwargs['onerror'](PermissionError('synthetic unreadable directory'))
            return iter(())
        with patch.object(guard.os, 'walk', side_effect=failed_walk):
            with self.assertRaises(PermissionError):
                guard.snapshot(self.root)
        os.mkfifo(self.root / 'unsupported-fifo')
        with self.assertRaises(ValueError):
            guard.snapshot(self.root)

    def test_actual_build_comparison_equal_different_failed(self):
        self.write('docs/engineering/new.md')
        head = self.commit()
        real_run = guard.subprocess.run
        for outcome in ('same', 'different', 'error'):
            def fake_run(command, **kwargs):
                if command[0] != 'npm':
                    return real_run(command, **kwargs)
                if outcome == 'error':
                    raise subprocess.CalledProcessError(1, command)
                if command == ['npm', 'run', 'build']:
                    output = Path(kwargs['cwd']) / 'public/build/generated.css'
                    output.parent.mkdir(parents=True)
                    output.write_text(Path(kwargs['cwd']).name if outcome == 'different' else 'same')
                return subprocess.CompletedProcess(command, 0)
            with patch.object(guard.subprocess, 'run', side_effect=fake_run):
                result, _ = guard.prove_build_equivalence('push', {'before': self.base, 'after': head})
            self.assertEqual(result, outcome == 'same')

    def test_pr_build_uses_current_base_and_verified_merge(self):
        self.git('checkout', '-qb', 'feature')
        self.write('docs/engineering/new.md')
        head = self.commit()
        self.git('checkout', '-qb', 'main-tip', self.base)
        self.write('app/new-consumer.php', 'new consumer')
        base = self.commit()
        self.git('merge', '--no-ff', '-qm', 'integration', 'feature')
        merged = self.git('rev-parse', 'HEAD')
        event = {'pull_request': {'base': {'sha': base}, 'head': {'sha': head}}}
        real_run = guard.subprocess.run
        builds = []
        def fake_run(command, **kwargs):
            if command[0] != 'npm':
                return real_run(command, **kwargs)
            builds.append((Path(kwargs['cwd']) / 'app/new-consumer.php').read_text())
            return subprocess.CompletedProcess(command, 0)
        with patch.dict(os.environ, GITHUB_SHA=merged), patch.object(guard, 'AUDIT_DIGEST', guard.audit_digest(base)):
            with patch.object(guard.subprocess, 'run', side_effect=fake_run):
                self.assertTrue(guard.prove_build_equivalence('pull_request', event)[0])
        self.assertEqual(builds, ['new consumer'] * 4)
        with patch.dict(os.environ, GITHUB_SHA=head):
            self.assertFalse(guard.prove_build_equivalence('pull_request', event)[0])

    def test_main_cli_requires_proven_build_equivalence(self):
        self.write('docs/engineering/new.md')
        head = self.commit()
        import json
        event = self.root / 'event.json'
        event.write_text(json.dumps({'before': self.base, 'after': head}))
        output = self.root / 'output'
        for proof in (False, True):
            output.write_text('')
            with patch.dict(os.environ, GITHUB_EVENT_NAME='push', GITHUB_EVENT_PATH=str(event), GITHUB_OUTPUT=str(output)):
                with patch.object(guard, 'prove_build_equivalence', return_value=(proof, 'synthetic build comparison')):
                    guard.main()
            self.assertEqual(output.read_text(), f'docs_only={str(proof).lower()}\n')


class WorkflowContract(unittest.TestCase):
    def test_production_gate_truth_table(self):
        # Contract for the exact expression asserted below; GitHub service execution
        # is still required before adoption, and is never simulated with real secrets.
        for ref in ('refs/heads/main', 'refs/pull/55/merge'):
            for validation in ('success', 'failure', 'cancelled', 'skipped'):
                for classification in ('success', 'failure', 'cancelled', 'skipped'):
                    for output in ('true', 'false', ''):
                        publish = (ref == 'refs/heads/main' and validation == 'success'
                                   and classification == 'success' and output == 'false')
                        if publish:
                            self.assertEqual((ref, validation, classification, output),
                                             ('refs/heads/main', 'success', 'success', 'false'))
                        if output == 'true' or validation != 'success' or classification != 'success':
                            self.assertFalse(publish)

    def test_existing_job_bodies_preserved(self):
        original = subprocess.check_output(['git', 'show', '8dd9835053b7dc986343e8cfc44b5c35bdde920a:.github/workflows/deploy.yml'], cwd=ROOT).decode()
        current = (ROOT / '.github/workflows/deploy.yml').read_text()
        self.assertEqual(original.split('  validate:\n')[1].split('  deploy:\n')[0], current.split('  validate:\n')[1].split('  deploy:\n')[0])
        self.assertEqual(original.split('    environment: production')[1], current.split('    environment: production')[1])
        self.assertEqual(original.split('concurrency:\n')[1].split('jobs:\n')[0], current.split('concurrency:\n')[1].split('jobs:\n')[0])
        self.assertIn('needs: [validate, classify]', current)
        self.assertIn("github.ref == 'refs/heads/main' && needs.validate.result == 'success' && needs.classify.result == 'success' && needs.classify.outputs.docs_only == 'false'", current)
        self.assertIn('fetch-depth: 0', current)
        self.assertIn('persist-credentials: false', current)
        self.assertNotIn('secrets.', current.split('  classify:\n')[1].split('  validate:\n')[0])
        quality = '.github/workflows/application-quality.yml'
        self.assertEqual(subprocess.check_output(['git', 'show', '8dd9835053b7dc986343e8cfc44b5c35bdde920a:' + quality], cwd=ROOT), (ROOT / quality).read_bytes())


if __name__ == '__main__':
    unittest.main()
