"""Actual workflow shells and CLI with subprocess doubles only; no remote access."""
import contextlib
import io
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'deploy'))
import fpm_guard_cli as cli
from fpm_guard import RELOAD

WORKFLOW = ROOT / '.github/workflows/deploy.yml'
PREFLIGHT = 'Preflight PHP-FPM before any remote mutation'
ACTIVATE = 'Activate PHP-FPM with fail-closed guard'


def deployment_steps():
    # Deliberately narrow parser, tied to the exact independently pinned workflow
    # in Styles and the baseline-transformation contract in test_deployment_guard.
    text = WORKFLOW.read_text().split('  deploy:\n', 1)[1]
    result = []
    for block in text.split('      - name: ')[1:]:
        name, _, body = block.partition('\n')
        run = re.search(r'^        run: (.+)\n', body, re.M)
        if not run:
            continue
        if run[1] == '|':
            lines = body[run.end():].splitlines()
            script = '\n'.join(line[10:] for line in lines if line.startswith('          ')) + '\n'
        else:
            script = run[1] + '\n'
        result.append((name, script, body))
    return result


class RunnerCli(unittest.TestCase):
    def test_configuration_is_explicit_and_port_is_preserved(self):
        with patch.dict(os.environ, DEPLOY_HOST='fixture.invalid', DEPLOY_USER='fixture', DEPLOY_PORT='2222'):
            remote = cli.remote_from_environment()
        self.assertEqual(remote.host, 'fixture@fixture.invalid')
        self.assertEqual(remote.executable, ('ssh', '-p', '2222', '-o', 'ConnectionAttempts=1'))

    def test_missing_invalid_or_injected_configuration_never_opens_transport(self):
        valid = dict(DEPLOY_HOST='fixture.invalid', DEPLOY_USER='fixture', DEPLOY_PORT='2222')
        cases = [(name, '') for name in valid] + [
            ('DEPLOY_HOST', '-oProxyCommand=command'), ('DEPLOY_HOST', 'host;command'),
            ('DEPLOY_HOST', 'other@host'), ('DEPLOY_HOST', 'host\ncommand'),
            ('DEPLOY_USER', 'user@host'), ('DEPLOY_USER', '-root'),
            ('DEPLOY_PORT', '0'), ('DEPLOY_PORT', '65536'), ('DEPLOY_PORT', '22 -o command')]
        for name, value in cases:
            with self.subTest(name=name, value=value), patch.dict(os.environ, {**valid, name: value}, clear=True):
                with patch.object(cli, 'SSH') as transport, contextlib.redirect_stderr(io.StringIO()):
                    self.assertEqual(cli.main(['preflight']), 1)
                    transport.assert_not_called()

    def test_transport_timeout_and_failure_are_fatal_without_diagnostic_leaks(self):
        for phase in ('preflight', 'activate'):
            for error in (cli.GuardError('private remote details'), OSError('private host'),
                          subprocess.TimeoutExpired('private command', 60)):
                with self.subTest(phase=phase, error=type(error).__name__):
                    output = io.StringIO()
                    with patch.object(cli, 'remote_from_environment'), patch.object(cli, phase, side_effect=error):
                        with contextlib.redirect_stderr(output):
                            self.assertEqual(cli.main([phase]), 1)
                    self.assertEqual(output.getvalue(), f'FPM {phase} failed; publication must stop.\n')


class WorkflowExecution(unittest.TestCase):
    def exercise(self, scenario, cloudflare=None):
        with tempfile.TemporaryDirectory(prefix='fokus-workflow-double-') as directory:
            root = Path(directory)
            state_path = root / 'state.json'
            state_path.write_text(json.dumps({'scenario': scenario, 'events': []}))
            fake = ROOT / 'tests/Deployment/fpm_workflow_fake.py'
            for name in ('ssh', 'scp', 'rsync', 'curl'):
                executable = root / name
                executable.write_text(f'#!{sys.executable}\nimport os, runpy\nos.environ["FPM_FAKE_PROGRAM"] = {name!r}\nrunpy.run_path({str(fake)!r}, run_name="__main__")\n')
                executable.chmod(0o700)
            assets = root / 'fokus-law-assets'
            assets.mkdir()
            (assets / 'checksums.sha256').write_text('synthetic checksum stdin\n')
            env = {key: os.environ[key] for key in ('PATH', 'HOME') if key in os.environ}
            env.update(PATH=str(root) + os.pathsep + env.get('PATH', ''),
                       FPM_WORKFLOW_STATE=str(state_path), RUNNER_TEMP=str(root),
                       DEPLOY_HOST='fixture.invalid', DEPLOY_USER='fixture', DEPLOY_PORT='2222',
                       DEPLOY_PATH='/srv/fixture', GITHUB_RUN_ID='123', GITHUB_RUN_ATTEMPT='1',
                       CLOUDFLARE_API_TOKEN='synthetic', CLOUDFLARE_ZONE_ID='fixture-zone',
                       DATAJUD_API_KEY='synthetic', PYTHONDONTWRITEBYTECODE='1')
            if cloudflare is not None:
                env.update(cloudflare)
            steps = deployment_steps()
            start = next(i for i, step in enumerate(steps) if step[0] == PREFLIGHT)
            completed = []
            status = 0
            for name, script, _ in steps[start:]:
                # GitHub's default success() stops subsequent steps on failure.
                # Conditions and failure overrides are separately asserted below.
                result = subprocess.run(['bash', '--noprofile', '--norc', '-e', '-o', 'pipefail', '-c', script],
                                        cwd=ROOT, env=env, capture_output=True, text=True, timeout=30)
                completed.append(name)
                status = result.returncode
                if status:
                    break
            return json.loads(state_path.read_text())['events'], completed, status

    def test_success_uses_actual_workflow_shells_and_exact_guard_transport(self):
        events, completed, status = self.exercise('success')
        self.assertEqual(status, 0, (completed, events))
        kinds = [event[0] for event in events]
        self.assertEqual(kinds[:3], ['/usr/bin/systemctl', 'sudo', 'sudo'])
        self.assertEqual(events[2], ['sudo', '-n', '-l', '--', *RELOAD])
        self.assertEqual(kinds[3], 'workflow-ssh')
        self.assertIn('mkdir -m 700', events[3][1])
        self.assertEqual(kinds[-4:], ['/usr/bin/systemctl', 'sudo', '/usr/bin/systemctl', 'purge'])
        self.assertEqual(sum(event[:3] == ['sudo', '-n', '--'] for event in events), 1)
        self.assertFalse(any('restart' in word for event in events for word in event))
        self.assertEqual(completed[-2:], [ACTIVATE, 'Purge Cloudflare cache'])

    def test_every_preflight_failure_prevents_schema_source_and_purge(self):
        for scenario in ('ssh_failure', 'missing', 'inactive', 'divergent', 'no_reload',
                         'sudo_denied', 'sudo_password', 'sudo_password_policy',
                         'sudo_wildcard', 'sudo_ambiguous', 'sudo_exact_denied'):
            with self.subTest(scenario=scenario):
                events, completed, status = self.exercise(scenario)
                self.assertNotEqual(status, 0)
                self.assertEqual(completed, [PREFLIGHT])
                self.assertTrue(events, "The actual guard command must reach the double")
                self.assertFalse(any(event[0] in ('workflow-ssh', 'scp', 'rsync', 'purge') for event in events))
                self.assertFalse(any(event[:3] == ['sudo', '-n', '--'] for event in events))

    def test_activation_failure_is_fatal_without_restart_or_purge(self):
        for scenario in ('pre_activation_failure', 'reload_denied', 'post_failure', 'post_inactive'):
            with self.subTest(scenario=scenario):
                events, completed, status = self.exercise(scenario)
                self.assertNotEqual(status, 0)
                self.assertEqual(completed[-1], ACTIVATE)
                self.assertIn('rsync', [event[0] for event in events])
                self.assertNotIn(['purge'], events)
                self.assertLessEqual(sum(event[:3] == ['sudo', '-n', '--'] for event in events), 1)
                self.assertFalse(any('restart' in word for event in events for word in event))

    def test_schema_failure_does_not_activate_or_continue_publication(self):
        events, completed, status = self.exercise('schema_failure')
        self.assertNotEqual(status, 0)
        self.assertEqual(completed[-1], 'Verify Law schema before publishing application code')
        self.assertFalse(any(event[0] in ('scp', 'rsync', 'purge') for event in events))
        self.assertFalse(any(event[:3] == ['sudo', '-n', '--'] for event in events))

    def test_purge_remains_optional_with_step_scoped_secrets(self):
        for missing in ({'CLOUDFLARE_API_TOKEN': ''}, {'CLOUDFLARE_ZONE_ID': ''},
                        {'CLOUDFLARE_API_TOKEN': '', 'CLOUDFLARE_ZONE_ID': ''}):
            with self.subTest(missing=list(missing)):
                events, completed, status = self.exercise('success', missing)
                self.assertEqual(status, 0)
                self.assertIn(ACTIVATE, completed)
                self.assertNotIn(['purge'], events)

    def test_workflow_keeps_manual_main_gate_and_success_semantics(self):
        workflow = WORKFLOW.read_text()
        production = workflow.split('  deploy:\n', 1)[1]
        self.assertIn("    if: github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main' && needs.validate.result == 'success' && needs.classify.result == 'success' && needs.classify.outputs.docs_only == 'false'\n", production)
        self.assertIn('    needs: [validate, classify]\n', production)
        self.assertNotIn('continue-on-error', production)
        self.assertNotIn('always()', production)
        self.assertNotIn('restart', production)
        steps = deployment_steps()
        names = [step[0] for step in steps]
        self.assertEqual(names[:5], ['Generate immutable assets and one-shot deploy payload',
                                   'Require exact main revision and latest successful Linux validation',
                                   'Configure SSH', PREFLIGHT,
                                   'Verify Law schema before publishing application code'])
        self.assertEqual(names[-2:], [ACTIVATE, 'Purge Cloudflare cache'])
        self.assertEqual(steps[3][1], 'python3 deploy/fpm_guard_cli.py preflight\n')
        self.assertEqual(steps[-2][1], 'python3 deploy/fpm_guard_cli.py activate\n')
        for name, _, body in steps:
            self.assertNotRegex(body, r'(?m)^        if:', name)
        purge = steps[-1][1]
        self.assertIn('if [ -z "$CLOUDFLARE_API_TOKEN" ] || [ -z "$CLOUDFLARE_ZONE_ID" ]; then', purge)
        self.assertLess(purge.index('exit 0'), purge.index('curl --fail-with-body'))
        # Setup performs only a remote no-op before preflight; the exact body is
        # separately pinned to the preserved main source by WorkflowContract.
        self.assertIn('"$DEPLOY_USER@$DEPLOY_HOST" exit', steps[2][1])
        self.assertIn("run: python3 -m unittest discover -s tests/Deployment -p 'test_fpm*.py' -v", workflow)


if __name__ == '__main__':
    unittest.main(verbosity=2)
