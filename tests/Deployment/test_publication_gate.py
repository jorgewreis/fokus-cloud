"""Synthetic GitHub responses only. Never contacts GitHub, SSH or production."""
import contextlib
from copy import deepcopy
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch
import urllib.error

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'deploy'))
import publication_gate as gate

SHA = 'a' * 40
CTX = gate.Context('fixture/repository', 123, SHA)
ENV = dict(EXPECTED_CANDIDATE=SHA, GITHUB_SHA=SHA, GITHUB_WORKFLOW_SHA=SHA,
           GITHUB_EVENT_NAME='workflow_dispatch', GITHUB_REF='refs/heads/main',
           GITHUB_REPOSITORY=CTX.repository, GITHUB_REPOSITORY_ID='123', GH_TOKEN='synthetic-token')


def run(number=2, **changes):
    value = dict(id=100 + number, run_number=number, run_attempt=1, workflow_id=456,
                 path=gate.WORKFLOW_PATH, head_sha=SHA, event='workflow_dispatch',
                 repository=dict(id=123, full_name=CTX.repository),
                 head_repository=dict(id=123, full_name=CTX.repository), head_commit=dict(id=SHA),
                 created_at=f'2026-11-09T00:0{number}:00Z',
                 run_started_at=f'2026-11-09T00:0{number}:01Z',
                 updated_at=f'2026-11-09T00:0{number}:02Z', status='completed', conclusion='success')
    value.update(changes)
    return value


class FakeAPI:
    def __init__(self, runs=None):
        self.runs = runs if runs is not None else [run()]
        self.calls = []
        self.history_reads = 0
        self.main_reads = 0
        self.mutate = lambda path, response: response

    def get(self, path):
        self.calls.append(path)
        if path == '/git/ref/heads/main':
            self.main_reads += 1
            response = dict(ref='refs/heads/main', object=dict(type='commit', sha=SHA))
        elif path == '/actions/workflows/security-homologation.yml':
            response = dict(id=456, path=gate.WORKFLOW_PATH, state='active')
        elif path.startswith('/actions/workflows/456/runs?'):
            self.history_reads += 1
            assert 'head_sha=' + SHA in path
            assert 'status=' not in path and 'event=' not in path
            response = dict(total_count=len(self.runs), workflow_runs=deepcopy(self.runs))
        elif path.startswith('/actions/runs/'):
            response = deepcopy(next(value for value in self.runs if value['id'] == int(path.rsplit('/', 1)[1])))
        else:
            raise AssertionError(path)
        return self.mutate(path, response)


class PublicationProof(unittest.TestCase):
    def test_success_binds_exact_main_workflow_commit_repository_and_attempt(self):
        api = FakeAPI()
        self.assertEqual(gate.verify(CTX, api), (102, 1))
        self.assertEqual(api.main_reads, 2)
        self.assertEqual(api.history_reads, 2)
        self.assertIn('/actions/workflows/security-homologation.yml', api.calls)
        self.assertIn('/actions/runs/102', api.calls)

    def test_old_failure_does_not_override_newest_success(self):
        self.assertEqual(gate.verify(CTX, FakeAPI([run(1, conclusion='failure'), run()])), (102, 1))

    def test_latest_failure_cancel_or_unfinished_never_falls_back_to_old_success(self):
        outcomes = [('completed', outcome) for outcome in (
            'failure', 'cancelled', 'timed_out', 'skipped', 'neutral', 'action_required', 'stale')]
        outcomes += [(status, None) for status in ('queued', 'in_progress', 'waiting', 'requested', 'pending')]
        for status, conclusion in outcomes:
            with self.subTest(status=status, conclusion=conclusion):
                api = FakeAPI([run(1), run(status=status, conclusion=conclusion)])
                with self.assertRaises(gate.GateError):
                    gate.verify(CTX, api)
                self.assertFalse(any(path.startswith('/actions/runs/') for path in api.calls))

    def test_name_alone_other_sha_fork_or_wrong_workflow_is_never_proof(self):
        variants = [dict(head_sha='b' * 40), dict(workflow_id=999),
                    dict(path='.github/workflows/application-quality.yml'),
                    dict(event='pull_request_target'), dict(head_commit=dict(id='b' * 40)),
                    dict(repository=dict(id=999, full_name=CTX.repository)),
                    dict(head_repository=dict(id=123, full_name='fork/repository'))]
        for changes in variants:
            with self.subTest(changes=changes), self.assertRaises(gate.GateError):
                gate.verify(CTX, FakeAPI([run(name='Isolated Linux release validation (no deploy)', **changes)]))

    def test_identity_numbers_reject_floats_booleans_and_strings(self):
        for value in (456.0, True, '456'):
            with self.subTest(workflow_id=value), self.assertRaises(gate.GateError):
                gate.verify(CTX, FakeAPI([run(workflow_id=value)]))
        for key in ('repository', 'head_repository'):
            for value in (123.0, True, '123'):
                with self.subTest(key=key, value=value), self.assertRaises(gate.GateError):
                    gate.verify(CTX, FakeAPI([run(**{key: dict(id=value, full_name=CTX.repository)})]))

    def test_missing_malformed_or_duplicate_runs_are_rejected(self):
        for key in run():
            value = run()
            del value[key]
            with self.subTest(missing=key), self.assertRaises(gate.GateError):
                gate.verify(CTX, FakeAPI([value]))
        for values in ([], [None], [run(), run()], [run(id=True)], [run(run_attempt=0)],
                       [run(status='unknown')], [run(status='queued', conclusion='success')],
                       [run(created_at='2026-11-99T00:00:00Z')], [run(updated_at='2026-11-01T00:00:00Z')]):
            with self.subTest(values=values), self.assertRaises(gate.GateError):
                gate.verify(CTX, FakeAPI(values))

    def test_ambiguous_concurrent_or_older_rerun_requires_new_unambiguous_validation(self):
        for old in (run(1, status='in_progress', conclusion=None),
                    run(1, run_attempt=2, run_started_at='2026-11-09T00:03:00Z', updated_at='2026-11-09T00:03:01Z'),
                    run(1, run_attempt=2, conclusion='failure', updated_at='2026-11-09T00:03:01Z'),
                    run(1, created_at=run()['created_at'], run_started_at=run()['run_started_at'])):
            with self.subTest(old=old), self.assertRaises(gate.GateError):
                gate.verify(CTX, FakeAPI([old, run()]))

    def test_main_must_match_both_before_and_after_proof(self):
        for drift_at in (1, 2):
            api = FakeAPI()
            def mutate(path, response):
                if path == '/git/ref/heads/main' and api.main_reads == drift_at:
                    response['object']['sha'] = 'b' * 40
                return response
            api.mutate = mutate
            with self.subTest(drift_at=drift_at), self.assertRaises(gate.GateError):
                gate.verify(CTX, api)

    def test_workflow_identity_and_state_are_not_inferred_from_display_name(self):
        for changes in (dict(id=None), dict(id=True), dict(state='disabled_manually'),
                        dict(path='.github/workflows/different.yml')):
            api = FakeAPI()
            api.mutate = lambda path, response: {**response, **changes} if path.endswith('homologation.yml') else response
            with self.subTest(changes=changes), self.assertRaises(gate.GateError):
                gate.verify(CTX, api)

    def test_detail_rerun_or_history_drift_is_fatal(self):
        for drift in ('attempt', 'conclusion', 'new-run', 'pending'):
            api = FakeAPI()
            def mutate(path, response):
                if path.startswith('/actions/runs/'):
                    if drift == 'attempt': response['run_attempt'] += 1
                    if drift == 'conclusion': response['conclusion'] = 'cancelled'
                if api.history_reads == 2 and path.startswith('/actions/workflows/456/runs?'):
                    if drift == 'new-run':
                        response['workflow_runs'].append(run(3))
                        response['total_count'] += 1
                    if drift == 'pending':
                        response['workflow_runs'][0].update(status='queued', conclusion=None)
                return response
            api.mutate = mutate
            with self.subTest(drift=drift), self.assertRaises(gate.GateError):
                gate.verify(CTX, api)

    def test_all_pages_are_required_without_filtering_failed_or_pending_runs(self):
        # Deliberately return newest on page two; do not trust ordering or page one.
        values = [run(1)] + [run()]
        class Paged(FakeAPI):
            def get(self, path):
                if path.startswith('/actions/workflows/456/runs?'):
                    self.calls.append(path)
                    page = int(path.rsplit('page=', 1)[1])
                    return dict(total_count=2, workflow_runs=[values[page - 1]])
                return super().get(path)
        api = Paged(values)
        self.assertEqual(gate.verify(CTX, api), (102, 1))
        self.assertEqual(sum(path.endswith('page=2') for path in api.calls), 2)
        values[-1]['conclusion'] = 'failure'
        with self.assertRaises(gate.GateError): gate.verify(CTX, Paged(values))

    def test_pagination_count_change_excess_missing_page_and_duplicates_fail(self):
        for response in (dict(total_count=1001, workflow_runs=[run()]),
                         dict(total_count=True, workflow_runs=[run()]),
                         dict(total_count=1, workflow_runs=[]),
                         dict(total_count=1, workflow_runs=[run(), run(1)]),
                         dict(total_count=2, workflow_runs=[run()])):
            api = FakeAPI()
            api.mutate = lambda path, original: response if '/runs?' in path else original
            with self.subTest(response=response), self.assertRaises(gate.GateError): gate.verify(CTX, api)
        api = FakeAPI()
        def changed(path, original):
            if '/runs?' in path:
                return dict(total_count=2 if path.endswith('page=1') else 3,
                            workflow_runs=[run() if path.endswith('page=1') else run(1)])
            return original
        api.mutate = changed
        with self.assertRaises(gate.GateError): gate.verify(CTX, api)


class EntryPoint(unittest.TestCase):
    def test_dispatch_main_and_exact_sha_are_mandatory_before_any_api_read(self):
        changes = [('GITHUB_EVENT_NAME', 'push'), ('GITHUB_EVENT_NAME', 'pull_request'),
                   ('GITHUB_REF', 'refs/heads/candidate'), ('EXPECTED_CANDIDATE', ''),
                   ('EXPECTED_CANDIDATE', 'a' * 7), ('EXPECTED_CANDIDATE', 'A' * 40),
                   ('GITHUB_SHA', 'b' * 40), ('GITHUB_WORKFLOW_SHA', 'b' * 40),
                   ('GITHUB_REPOSITORY', '../invalid'), ('GITHUB_REPOSITORY_ID', '')]
        for name, value in changes:
            with self.subTest(name=name), patch.dict(os.environ, {**ENV, name: value}, clear=True):
                with patch.object(gate.subprocess, 'check_output', return_value=SHA), patch.object(gate, 'GitHub') as api:
                    with contextlib.redirect_stderr(io.StringIO()): self.assertEqual(gate.main(), 1)
                    api.assert_not_called()
        with self.assertRaises(gate.GateError): gate.context(ENV, 'b' * 40)
        self.assertEqual(gate.context(ENV, SHA), CTX)

    def test_cli_success_and_failure_exit_codes_do_not_expose_tokens(self):
        for api, expected in ((FakeAPI(), 0), (FakeAPI([run(conclusion='failure')]), 1)):
            output = io.StringIO()
            with patch.dict(os.environ, ENV, clear=True), patch.object(gate.subprocess, 'check_output', return_value=SHA):
                with patch.object(gate, 'GitHub', return_value=api):
                    with contextlib.redirect_stdout(output), contextlib.redirect_stderr(output):
                        self.assertEqual(gate.main(), expected)
            self.assertNotIn('synthetic-token', output.getvalue())
        with patch.object(gate.subprocess, 'check_output', side_effect=subprocess.CalledProcessError(1, 'git')):
            with contextlib.redirect_stderr(io.StringIO()): self.assertEqual(gate.main(), 1)

    def test_workflow_checks_after_local_preparation_before_ssh_with_no_destination_secrets(self):
        source = (ROOT / '.github/workflows/deploy.yml').read_text()
        deploy = source.split('  deploy:\n')[1]
        gate_name = '      - name: Require exact main revision and latest successful Linux validation\n'
        before, after = deploy.split(gate_name)
        self.assertNotIn('secrets.', before)
        self.assertNotIn('DEPLOY_HOST', before)
        self.assertIn('      actions: read\n', before)
        self.assertIn('          persist-credentials: false\n', before)
        self.assertIn("if: github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main'", before)
        step, rest = after.split('      - name: Configure SSH\n')
        self.assertIn('EXPECTED_CANDIDATE: ${{ inputs.candidate_sha }}', step)
        self.assertIn('GH_TOKEN: ${{ github.token }}', step)
        self.assertIn('run: python3 deploy/publication_gate.py', step)
        self.assertNotIn('secrets.', step)
        self.assertNotIn('continue-on-error', deploy)
        self.assertNotIn('always()', deploy)
        self.assertIn('Preflight PHP-FPM before any remote mutation', rest)
        self.assertNotIn('actions: write', source)
        self.assertEqual(source.count('actions: read'), 1)


class HttpAdapter(unittest.TestCase):
    def client(self):
        with patch.object(gate.urllib.request, 'build_opener'):
            return gate.GitHub(CTX, 'synthetic-token')

    def response(self, body=b'{}', status=200, content_type='application/json'):
        from unittest.mock import MagicMock
        response = MagicMock()
        response.__enter__.return_value = response
        response.status = status
        response.headers.get_content_type.return_value = content_type
        response.read.return_value = body
        return response

    def test_transport_is_get_only_fixed_origin_no_redirects_and_bounded(self):
        client = self.client()
        client.opener.open.return_value = self.response()
        self.assertEqual(client.get('/git/ref/heads/main'), {})
        args, kwargs = client.opener.open.call_args
        self.assertEqual(args[0].full_url, 'https://api.github.com/repos/fixture/repository/git/ref/heads/main')
        self.assertEqual(args[0].method, 'GET')
        self.assertEqual(kwargs['timeout'], 20)
        self.assertEqual(args[0].get_header('Authorization'), 'Bearer synthetic-token')
        self.assertIsNone(gate.NoRedirect().redirect_request(None, None, 302, '', {}, 'https://other.invalid'))
        with self.assertRaises(gate.GateError): client.get('//other.invalid')
        with self.assertRaises(gate.GateError): gate.GitHub(CTX, '')

    def test_http_failures_malformed_json_duplicates_and_unbounded_data_fail_closed(self):
        for response in (self.response(status=403), self.response(status=302),
                         self.response(content_type='text/html'), self.response(body=b'not-json'),
                         self.response(body=b'[]'), self.response(body=b'{"id":1,"id":2}'),
                         self.response(body=b'x' * (4 * 1024 * 1024 + 1))):
            client = self.client()
            client.opener.open.return_value = response
            with self.assertRaises(gate.GateError): client.get('/test')
        for error in (urllib.error.URLError('synthetic-token'), TimeoutError('synthetic-token')):
            client = self.client()
            client.opener.open.side_effect = error
            with self.assertRaisesRegex(gate.GateError, '^GitHub read failed; no deployment permitted$'):
                client.get('/test')


if __name__ == '__main__':
    unittest.main(verbosity=2)
