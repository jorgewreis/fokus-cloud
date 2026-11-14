#!/usr/bin/env python3
"""No-network gating and orchestration checks for immutable publication entry."""
import importlib.util
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest.mock import Mock, patch

MODULE = Path(__file__).with_name('publication_entry.py')
sys.path.insert(0, str(MODULE.parent))
derived = types.ModuleType('derived_assets')
derived.build = Mock()
derived.source_snapshot = Mock()
derived.package = Mock()
derived.encoded = lambda value: b'{}'
ssh = types.ModuleType('artifact_ssh')
ssh.send = Mock()
with patch.dict(sys.modules, {'derived_assets': derived, 'artifact_ssh': ssh}):
    spec = importlib.util.spec_from_file_location('publication_entry_test', MODULE)
    entry = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(entry)
    sys.path.insert(0, str(MODULE.resolve().parents[2] / 'deploy'))
    import publication_gate
    entry.publication_gate = publication_gate


class Bootstrap(unittest.TestCase):
    def test_modified_import_is_rejected_before_any_project_code_runs(self):
        with tempfile.TemporaryDirectory() as temp:
            source = Path(temp)
            paths = ('tools/releases/publication_entry.py', 'tools/releases/preparation_guard.py', 'deploy/publication_gate.py',
                     'tools/releases/derived_assets.py', 'tools/releases/artifact_ssh.py')
            for name in paths:
                path = source / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(b'raise RuntimeError("import executed")\n')
            sha = 'a' * 40
            def git(command, **kwargs):
                return sha if 'rev-parse' in command else b'expected reviewed code\n'
            with patch.object(entry.sys, 'flags', types.SimpleNamespace(isolated=1)), \
                 patch.object(entry.subprocess, 'check_output', side_effect=git):
                with self.assertRaisesRegex(ValueError, 'executor_import_mismatch'):
                    entry.load_dependencies(source, {'GITHUB_SHA': sha})

    def test_nonisolated_python_is_rejected(self):
        with patch.object(entry.sys, 'flags', types.SimpleNamespace(isolated=0)):
            with self.assertRaisesRegex(ValueError, 'isolated_python_required'):
                entry.load_dependencies(Path('.'), {})

class Entrypoint(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.sha = 'a' * 40
        self.env = {'GITHUB_EVENT_NAME': 'workflow_dispatch', 'GITHUB_REF': 'refs/heads/main',
                    'EXPECTED_CANDIDATE': self.sha, 'GITHUB_SHA': self.sha, 'GITHUB_WORKFLOW_SHA': self.sha,
                    'GITHUB_REPOSITORY': 'example/project', 'GITHUB_REPOSITORY_ID': '42', 'GH_TOKEN': 'synthetic'}
        self.prepare = {'root': '/synthetic/releases/' + self.sha}
        derived.source_snapshot.return_value = (self.sha, b'', {})
        self.patchers = [patch.object(entry, 'EXECUTOR_ROOT', Path(self.temp.name)),
                         patch.object(entry.publication_gate, 'GitHub', return_value=object()),
                         patch.object(entry.publication_gate, 'verify', return_value=(123, 1))]
        self.mocks = [item.start() for item in self.patchers]
        dependency_patch = patch.object(entry, 'load_dependencies', return_value=(publication_gate, derived, ssh))
        dependency_patch.start()
        self.addCleanup(dependency_patch.stop)
        for item in self.patchers: self.addCleanup(item.stop)
        derived.build.reset_mock(return_value=True, side_effect=True)
        derived.package.reset_mock(return_value=True, side_effect=True)
        ssh.send.reset_mock(return_value=True, side_effect=True)
        derived.build.return_value = {'source_sha': self.sha, 'mode': 'complete-runtime'}
        derived.package.return_value = {'source_sha': self.sha, 'artifact_sha256': 'b' * 64}
        ssh.send.return_value = {'state': 'ssh_completed_unverified'}

    def invoke(self):
        return entry.run(self.temp.name, {}, self.prepare, self.env)

    def test_rechecks_exact_validation_before_transport_and_does_not_claim_seal(self):
        result = self.invoke()
        self.assertEqual(self.mocks[2].call_count, 2)
        self.assertEqual(result['state'], 'ssh_completed_unverified')
        self.assertEqual(result['candidate_sha'], self.sha)
        self.assertEqual(ssh.send.call_args.kwargs['expected_digest'], 'b' * 64)

    def test_different_executor_source_refuses_before_gate_or_build(self):
        with patch.object(entry, 'EXECUTOR_ROOT', Path(self.temp.name) / 'different'):
            with self.assertRaisesRegex(ValueError, 'executor_source_mismatch'):
                self.invoke()
        derived.build.assert_not_called()
        self.mocks[2].assert_not_called()
        ssh.send.assert_not_called()

    def test_dirty_executor_refuses_before_gate_or_build(self):
        derived.source_snapshot.side_effect = ValueError('dirty_source')
        self.addCleanup(lambda: setattr(derived.source_snapshot, 'side_effect', None))
        with self.assertRaisesRegex(ValueError, 'dirty_source'): self.invoke()
        derived.build.assert_not_called()
        ssh.send.assert_not_called()

    def test_untrusted_event_is_rejected_before_build(self):
        self.env['GITHUB_EVENT_NAME'] = 'push'
        with self.assertRaises(entry.publication_gate.GateError): self.invoke()
        derived.build.assert_not_called()
        ssh.send.assert_not_called()

    def test_changed_or_failed_validation_never_opens_transport(self):
        for outcomes in ([(123, 1), (124, 1)], [(123, 1), entry.publication_gate.GateError('failed')]):
            self.mocks[2].side_effect = outcomes
            with self.assertRaises(Exception): self.invoke()
            ssh.send.assert_not_called()

    def test_wrong_root_build_or_package_identity_never_opens_transport(self):
        for change in ('root', 'build', 'package'):
            with self.subTest(change=change):
                self.prepare['root'] = '/synthetic/releases/' + ('c' * 40 if change == 'root' else self.sha)
                derived.build.return_value['source_sha'] = 'c' * 40 if change == 'build' else self.sha
                derived.package.return_value['source_sha'] = 'c' * 40 if change == 'package' else self.sha
                with self.assertRaises(ValueError): self.invoke()
                ssh.send.assert_not_called()


if __name__ == '__main__':
    unittest.main()
