"""Native integration tests: subprocess transport, synthetic commands, no SSH."""
import importlib.util
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('fpm_guard', ROOT / 'deploy/fpm_guard.py')
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)


class FpmGuardTests(unittest.TestCase):
    def exercise(self, scenario):
        with tempfile.TemporaryDirectory(prefix='fokus-fpm-double-') as directory:
            state_path = pathlib.Path(directory) / 'state.json'
            state_path.write_text(json.dumps({'scenario': scenario, 'events': []}))
            remote = guard.SSH('fixture.invalid', executable=(
                sys.executable, str(ROOT / 'tests/Deployment/fpm_fake_ssh.py'), str(state_path)))

            def callback(name):
                def run():
                    state = json.loads(state_path.read_text())
                    state['events'].append([name])
                    state_path.write_text(json.dumps(state))
                    if scenario == name + '_failure':
                        raise RuntimeError(name)
                return run

            error = None
            try:
                guard.run_candidate(remote, transfer=callback('transfer'),
                                    mutate=callback('mutate'),
                                    verify_application=callback('verify'), reopen=callback('reopen'))
            except (guard.GuardError, RuntimeError) as failure:
                error = failure
            return json.loads(state_path.read_text())['events'], error

    def test_success_orders_every_step(self):
        events, error = self.exercise('success')
        self.assertIsNone(error)
        self.assertEqual([event[0] for event in events], [
            '/usr/bin/systemctl', 'sudo', 'sudo', 'transfer', 'mutate',
            '/usr/bin/systemctl', 'sudo', '/usr/bin/systemctl', 'verify', 'reopen'])
        self.assertEqual(events[1], ['sudo', '-n', '-ll'])
        self.assertEqual(events[2], ['sudo', '-n', '-l', '--', *guard.RELOAD])
        self.assertEqual(events[6], ['sudo', '-n', '--', *guard.RELOAD])

    def test_preflight_failures_prevent_all_publication(self):
        for scenario in ('ssh_failure', 'missing', 'inactive', 'divergent', 'no_reload',
                         'sudo_denied', 'sudo_password', 'sudo_password_policy',
                         'sudo_wildcard', 'sudo_ambiguous', 'sudo_exact_denied'):
            with self.subTest(scenario=scenario):
                events, error = self.exercise(scenario)
                self.assertIsNotNone(error)
                self.assertFalse(any(event[0] in ('transfer', 'mutate', 'verify', 'reopen') for event in events))
                self.assertNotIn(['sudo', '-n', '--', *guard.RELOAD], events)

    def test_activation_failures_never_verify_or_reopen(self):
        for scenario in ('pre_activation_failure', 'reload_denied', 'post_failure', 'post_inactive'):
            with self.subTest(scenario=scenario):
                events, error = self.exercise(scenario)
                self.assertIsNotNone(error)
                self.assertIn(['mutate'], events)
                self.assertNotIn(['verify'], events)
                self.assertNotIn(['reopen'], events)
                self.assertFalse(any('restart' in event for event in events))

    def test_callback_failures_propagate(self):
        for scenario in ('transfer_failure', 'mutate_failure', 'verify_failure'):
            with self.subTest(scenario=scenario):
                events, error = self.exercise(scenario)
                self.assertIsNotNone(error)
                self.assertNotIn(['reopen'], events)
                if scenario != 'verify_failure':
                    self.assertNotIn(['sudo', '-n', '--', *guard.RELOAD], events)

    def test_timeout_propagates_before_mutation(self):
        def forbidden():
            self.fail('Mutation after transport timeout')
        with patch.object(guard.subprocess, 'run', side_effect=subprocess.TimeoutExpired('fake', 60)):
            with self.assertRaises(subprocess.TimeoutExpired):
                guard.run_candidate(guard.SSH('fixture.invalid'), transfer=forbidden,
                                    mutate=forbidden, verify_application=forbidden, reopen=forbidden)

    def test_rejects_host_injection(self):
        for host in ('-oProxyCommand=x', 'host; command', 'host\ncommand'):
            with self.assertRaises(ValueError):
                guard.SSH(host)

    def test_permission_parser_rejects_ambiguous_rules(self):
        valid = ('Sudoers entry:\nRunAsUsers: root\nOptions: !authenticate\n'
                 'Commands:\n/usr/bin/systemctl reload php8.5-fpm.service')
        for output in (valid.replace('root', 'daemon'),
                       valid.replace('!authenticate', '!authenticate, authenticate'),
                       valid + '\n/usr/bin/systemctl restart php8.5-fpm.service',
                       valid.replace('/usr/bin/systemctl', '!/usr/bin/systemctl'),
                       valid + '\nRunAsUsers: root',
                       valid.replace('Options:', 'Unknown:'),
                       valid.replace('php8.5', 'php8.1')):
            with self.subTest(output=output):
                with patch.object(guard.SSH, 'run', return_value=output):
                    with self.assertRaises(guard.GuardError):
                        guard.check_permission(guard.SSH('fixture.invalid'))
        with patch.object(guard.SSH, 'run', side_effect=[valid, 'unexpected command']):
            with self.assertRaises(guard.GuardError):
                guard.check_permission(guard.SSH('fixture.invalid'))

    def test_reload_timeout_propagates_without_reopening(self):
        events = []
        with patch.object(guard, 'preflight'), patch.object(guard, 'check_unit'):
            with patch.object(guard.SSH, 'run', side_effect=subprocess.TimeoutExpired('fake', 60)):
                with self.assertRaises(subprocess.TimeoutExpired):
                    guard.run_candidate(guard.SSH('fixture.invalid'),
                                        transfer=lambda: events.append('transfer'),
                                        mutate=lambda: events.append('mutate'),
                                        verify_application=lambda: events.append('verify'),
                                        reopen=lambda: events.append('reopen'))
        self.assertEqual(events, ['transfer', 'mutate'])

    def test_reopen_failure_is_not_reported_as_success(self):
        events, error = self.exercise('reopen_failure')
        self.assertIsNotNone(error)
        self.assertEqual(events[-1], ['reopen'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
