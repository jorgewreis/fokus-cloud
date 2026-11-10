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


class AdoptionLifecycle(unittest.TestCase):
    """Explicit synthetic audit fixture, not proof that the live pin is current."""
    ORIGINAL_MAIN = '8dd9835053b7dc986343e8cfc44b5c35bdde920a'

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='guard-adoption-')
        self.root = Path(self.temp.name) / 'repo'
        self.previous = Path.cwd()
        self.fixture_tree = subprocess.check_output(['git', 'write-tree'], cwd=ROOT).decode().strip()
        subprocess.run(['git', 'clone', '--shared', '--no-checkout', str(ROOT), str(self.root)],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        os.chdir(self.root)
        self.git('config', 'user.email', 'synthetic@example.invalid')
        self.git('config', 'user.name', 'Synthetic adoption test')
        # Unit policy only: explicitly audit this temporary fixture. Never rewrite
        # the deployed script/pin or require every operational PR to refresh it.
        import types
        self.policy = types.ModuleType('synthetic_adoption_policy')
        exec((ROOT / 'tools/ci/classify-deployment.py').read_text(), self.policy.__dict__)
        self.live_pin = self.policy.AUDIT_DIGEST
        self.policy.AUDIT_DIGEST = self.policy.audit_digest(self.fixture_tree)

    def tearDown(self):
        os.chdir(self.previous)
        self.temp.cleanup()

    def git(self, *args):
        return subprocess.check_output(['git', *args], stderr=subprocess.PIPE).decode().strip()

    def change(self, path, text):
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        with target.open('a') as output:
            output.write(text)
        self.git('add', '-f', '--', path)
        self.git('commit', '-qm', 'synthetic lifecycle change')
        return self.git('rev-parse', 'HEAD')

    def integrate(self, tree):
        feature = self.git('commit-tree', tree, '-p', self.ORIGINAL_MAIN,
                           '-m', 'synthetic guard feature')
        merge = self.git('commit-tree', tree, '-p', self.ORIGINAL_MAIN,
                         '-p', feature, '-m', 'synthetic guard integration')
        self.git('checkout', '--detach', merge)
        return merge

    def record(self, module, label, base, head, expected):
        candidate, reason = module.classify('push', {'before': base, 'after': head})
        self.assertEqual(candidate, expected, label)
        import json
        print('ADOPTION_EVIDENCE ' + json.dumps({
            'algorithm_version': 'revised-normalized-self',
            'fingerprint_scope': 'synthetic-audit-fixture',
            'live_pin': self.live_pin, 'fixture_pin_matches_live': module.AUDIT_DIGEST == self.live_pin,
            'scenario': label, 'before_digest': module.audit_digest(base),
            'after_digest': module.audit_digest(head), 'expected_digest': module.AUDIT_DIGEST,
            'candidate': candidate, 'reason': reason, 'real_build_executed': False,
            'publication_policy': ('not yet exempt: requires successful real build equality'
                                   if candidate else 'normal deployment after successful validation'),
        }, sort_keys=True), flush=True)

    def test_uses_only_durable_main_historical_reference(self):
        import re
        fixed_commits = set(re.findall(r'(?<![0-9a-f])[0-9a-f]{40}(?![0-9a-f])',
                                       Path(__file__).read_text()))
        self.assertEqual(fixed_commits, {self.ORIGINAL_MAIN})

    def test_single_parent_squash_or_rebase_adoption(self):
        integrated = self.git('commit-tree', self.fixture_tree, '-p', self.ORIGINAL_MAIN,
                              '-m', 'synthetic single-parent adoption')
        self.git('checkout', '--detach', integrated)
        self.record(self.policy, 'single-parent adoption', self.ORIGINAL_MAIN, integrated, False)
        docs = self.change('docs/engineering/lifecycle-synthetic.md', '# Synthetic knowledge\n')
        self.record(self.policy, 'docs after single-parent adoption', integrated, docs, True)

    def test_revised_adoption_and_operational_invalidation(self):
        self.assertEqual(self.policy.audit_digest(self.fixture_tree), self.policy.AUDIT_DIGEST)
        self.assertNotEqual(self.policy.audit_digest(self.ORIGINAL_MAIN), self.policy.AUDIT_DIGEST)
        integrated = self.integrate(self.fixture_tree)
        self.record(self.policy, 'revised adoption', self.ORIGINAL_MAIN, integrated, False)
        docs = self.change('docs/engineering/lifecycle-synthetic.md', '# Synthetic knowledge\n')
        self.record(self.policy, 'revised post-adoption docs', integrated, docs, True)
        skills = self.change('.agents/skills/engineering-research/references/lifecycle.md', '# Synthetic reference\n')
        self.record(self.policy, 'revised post-adoption Skills', docs, skills, True)
        for path in ('tools/ci/classify-deployment.py', '.github/workflows/deploy.yml',
                     'tools/ci/validate-knowledge.py', 'tests/Deployment/test_deployment_guard.py',
                     'config/app.php', 'app/MarkdownConsumer.php'):
            self.git('checkout', '--detach', skills)
            operational = self.change(path, '\n# Synthetic operational drift\n')
            self.record(self.policy, 'operational change: ' + path, skills, operational, False)
            later_docs = self.change('docs/engineering/lifecycle-synthetic.md', '\nMore knowledge.\n')
            self.record(self.policy, 'docs after operational change: ' + path, operational, later_docs, False)
        self.git('checkout', '--detach', skills)
        self.change('app/MarkdownConsumer.php', '<?php // synthetic consumer\n')
        mixed = self.change('docs/engineering/lifecycle-synthetic.md', '\nMixed knowledge.\n')
        self.record(self.policy, 'mixed push', skills, mixed, False)

    def test_stale_live_pin_keeps_successful_normal_deployment_path(self):
        import json
        integrated = self.integrate(self.fixture_tree)
        operational = self.change('app/SyntheticOperationalChange.php', '<?php // synthetic\n')
        self.assertNotEqual(guard.audit_digest(operational), self.live_pin)
        event = Path(self.temp.name) / 'event.json'
        output = Path(self.temp.name) / 'output'
        summary = Path(self.temp.name) / 'summary'
        event.write_text(json.dumps({'before': integrated, 'after': operational}))
        env = dict(os.environ, GITHUB_EVENT_NAME='push', GITHUB_EVENT_PATH=str(event),
                   GITHUB_OUTPUT=str(output), GITHUB_STEP_SUMMARY=str(summary))
        result = subprocess.run([sys.executable, 'tools/ci/classify-deployment.py'],
                                cwd=self.root, env=env, capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(output.read_text(), 'docs_only=false\n')
        # Exercise the permanent adoption regression under a genuinely stale live
        # script pin, not just a boolean mock of its outcome. No npm/build is run.
        suite = subprocess.run([sys.executable, '-m', 'unittest', 'discover',
                                '-s', 'tests/Deployment', '-p', 'test_deployment_guard.py',
                                '-k', 'test_revised_adoption_and_operational_invalidation', '-v'],
                               cwd=self.root, capture_output=True, text=True, timeout=60)
        self.assertEqual(suite.returncode, 0, suite.stdout + suite.stderr)
        print('STALE_PIN_EVIDENCE classifier_exit=0 docs_only=false '
              'adoption_regression_exit=0 real_build_executed=false', flush=True)

    def test_normalizes_only_exact_digest_literal(self):
        integrated = self.integrate(self.fixture_tree)
        path = self.root / 'tools/ci/classify-deployment.py'
        before = guard.audit_digest(integrated)
        import re
        original = path.read_text()
        path.write_text(re.sub(r"(?m)^AUDIT_DIGEST = '[0-9a-f]{64}'$",
                               "AUDIT_DIGEST = '" + '0' * 64 + "'", original))
        replaced = self.change('tools/ci/classify-deployment.py', '')
        self.assertEqual(guard.audit_digest(replaced), before)
        changed = self.change('tools/ci/classify-deployment.py', '\n# Additional source byte\n')
        self.assertNotEqual(guard.audit_digest(changed), before)
        path.chmod(0o755)
        executable = self.change('tools/ci/classify-deployment.py', '')
        with self.assertRaises(ValueError):
            guard.audit_digest(executable)


class WorkflowContract(unittest.TestCase):
    def test_production_gate_truth_table(self):
        # Exact workflow condition below; SHA and Linux evidence are separately
        # required inside the deploy job before any destination credentials.
        import itertools
        states = ('success', 'failure', 'cancelled', 'skipped')
        for event, ref, validation, classification, output in itertools.product(
                ('push', 'pull_request', 'workflow_dispatch'),
                ('refs/heads/main', 'refs/pull/55/merge', 'refs/heads/candidate'),
                states, states, ('true', 'false', '')):
            publish = (event == 'workflow_dispatch' and ref == 'refs/heads/main'
                       and validation == classification == 'success' and output == 'false')
            self.assertEqual(publish, (event, ref, validation, classification, output) ==
                             ('workflow_dispatch', 'refs/heads/main', 'success', 'success', 'false'))

    def test_existing_job_bodies_preserved_except_authorized_fpm_glue(self):
        original = subprocess.check_output(['git', 'show', '8dd9835053b7dc986343e8cfc44b5c35bdde920a:.github/workflows/deploy.yml'], cwd=ROOT).decode()
        current = (ROOT / '.github/workflows/deploy.yml').read_text()
        test_step = """      - name: Test fail-closed FPM workflow integration without network
        run: python3 -m unittest discover -s tests/Deployment -p 'test_fpm*.py' -v

      - name: Test manual publication gate without GitHub or server access
        run: python3 -m unittest discover -s tests/Deployment -p 'test_publication_gate.py' -v

"""
        expected_validate = original.split('  validate:\n')[1].split('  deploy:\n')[0]
        expected_validate = expected_validate.replace(
            '      - name: Generate content-addressed Fokus Law assets for tests',
            test_step + '      - name: Generate content-addressed Fokus Law assets for tests')
        self.assertEqual(expected_validate, current.split('  validate:\n')[1].split('  deploy:\n')[0])
        preflight = """      - name: Preflight PHP-FPM before any remote mutation
        env:
          DEPLOY_HOST: ${{ secrets.DEPLOY_HOST }}
          DEPLOY_PORT: ${{ secrets.DEPLOY_PORT }}
          DEPLOY_USER: ${{ secrets.DEPLOY_USER }}
        run: python3 deploy/fpm_guard_cli.py preflight

"""
        activation = """      - name: Activate PHP-FPM with fail-closed guard
        env:
          DEPLOY_HOST: ${{ secrets.DEPLOY_HOST }}
          DEPLOY_PORT: ${{ secrets.DEPLOY_PORT }}
          DEPLOY_USER: ${{ secrets.DEPLOY_USER }}
        run: python3 deploy/fpm_guard_cli.py activate

"""
        expected_deploy = original.split('    environment: production')[1]
        legacy_start = expected_deploy.index('      - name: Reload PHP-FPM opcache on server')
        legacy_end = expected_deploy.index('      - name: Purge Cloudflare cache', legacy_start)
        expected_deploy = expected_deploy[:legacy_start] + activation + expected_deploy[legacy_end:]
        expected_deploy = expected_deploy.replace(
            '      - name: Verify Law schema before publishing application code',
            preflight + '      - name: Verify Law schema before publishing application code')
        # Authorized manual gate: only read access, after preparation, before
        # destination credentials. Cloudflare secrets become purge-step scoped.
        cloudflare_env = """    env:
      CLOUDFLARE_API_TOKEN: ${{ secrets.CLOUDFLARE_API_TOKEN }}
      CLOUDFLARE_ZONE_ID: ${{ secrets.CLOUDFLARE_ZONE_ID }}
"""
        expected_deploy = expected_deploy.replace(cloudflare_env, '')
        expected_deploy = expected_deploy.replace('      contents: read\n', '      contents: read\n      actions: read\n', 1)
        expected_deploy = expected_deploy.replace('        uses: actions/checkout@v5\n',
            '        uses: actions/checkout@v5\n        with:\n          persist-credentials: false\n', 1)
        publication_gate = """      - name: Require exact main revision and latest successful Linux validation
        env:
          EXPECTED_CANDIDATE: ${{ inputs.candidate_sha }}
          GH_TOKEN: ${{ github.token }}
        run: python3 deploy/publication_gate.py

"""
        expected_deploy = expected_deploy.replace('      - name: Configure SSH\n', publication_gate + '      - name: Configure SSH\n')
        expected_deploy = expected_deploy.replace("""      - name: Purge Cloudflare cache
        if: ${{ env.CLOUDFLARE_API_TOKEN != '' && env.CLOUDFLARE_ZONE_ID != '' }}
        run: |
""", """      - name: Purge Cloudflare cache
        env:
          CLOUDFLARE_API_TOKEN: ${{ secrets.CLOUDFLARE_API_TOKEN }}
          CLOUDFLARE_ZONE_ID: ${{ secrets.CLOUDFLARE_ZONE_ID }}
        run: |
          if [ -z "$CLOUDFLARE_API_TOKEN" ] || [ -z "$CLOUDFLARE_ZONE_ID" ]; then
            echo "Cloudflare purge not configured; skipping."
            exit 0
          fi
""")
        # No unrelated body changes are permitted: #56 schema, immutable assets,
        # Composer, migrations, scheduler and purge remain exactly as reviewed.
        self.assertEqual(expected_deploy, current.split('    environment: production')[1])
        self.assertEqual(original.split('concurrency:\n')[1].split('jobs:\n')[0], current.split('concurrency:\n')[1].split('jobs:\n')[0])
        self.assertIn('needs: [validate, classify]', current)
        self.assertIn("if: github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main' && needs.validate.result == 'success' && needs.classify.result == 'success' && needs.classify.outputs.docs_only == 'false'", current)
        self.assertIn('fetch-depth: 0', current)
        self.assertIn('persist-credentials: false', current)
        self.assertNotIn('secrets.', current.split('  classify:\n')[1].split('  validate:\n')[0])
        quality = '.github/workflows/application-quality.yml'
        self.assertEqual(subprocess.check_output(['git', 'show', '8dd9835053b7dc986343e8cfc44b5c35bdde920a:' + quality], cwd=ROOT), (ROOT / quality).read_bytes())


if __name__ == '__main__':
    unittest.main()
