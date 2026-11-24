"""Synthetic only: no production SSH, credentials, endpoints or data."""
import contextlib
import importlib.util
import io
import http.server
import http.client
import ssl
import threading
import urllib.parse
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import urllib.error
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('verify_deployment', ROOT / 'tools/ci/verify-deployment.py')
VERIFY = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = VERIFY
SPEC.loader.exec_module(VERIFY)
SHA = 'a' * 40
BASE = {
    'DEPLOY_PUBLIC_BASE_URL': 'https://example.test',
    'DEPLOY_RELEASE_URL': 'https://example.test/deployment-release',
    'EXPECTED_RELEASE': SHA,
    'FPM_RELOAD_MODE': 'verify-only',
    'FPM_SERVICE': '',
}


class ConfigurationTests(unittest.TestCase):
    def test_valid_preflight_has_no_network_or_mutations(self):
        with mock.patch.object(VERIFY, 'fetch', side_effect=AssertionError('network')), contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(0, VERIFY.main(['preflight'], BASE))
        self.assertIn('deployment_configuration=valid', output.getvalue())

    def test_missing_configuration_fails(self):
        for key in ('DEPLOY_PUBLIC_BASE_URL', 'DEPLOY_RELEASE_URL', 'EXPECTED_RELEASE', 'FPM_RELOAD_MODE'):
            with self.subTest(key=key):
                env = BASE.copy()
                del env[key]
                with self.assertRaises(VERIFY.VerificationError):
                    VERIFY.configuration(env)

    def test_url_policy_rejects_unsafe_or_ambiguous_values(self):
        for value in ('http://example.test/release', 'https://u:SECRET@example.test/release',
                      'https://other.test/release', 'https://example.test/release?token=SECRET',
                      'https://example.test/release#secret', 'https://example.test:bad/release',
                      'https://example.test:0/release', 'https://example.test/\nrelease',
                      'https://example.test\\evil/release', 'https://example.test/up',
                      'https://example.test/'):
            with self.subTest(value=value), self.assertRaises(VERIFY.VerificationError):
                VERIFY.configuration({**BASE, 'DEPLOY_RELEASE_URL': value})

    def test_base_must_be_origin(self):
        with self.assertRaises(VERIFY.VerificationError):
            VERIFY.configuration({**BASE, 'DEPLOY_PUBLIC_BASE_URL': 'https://example.test/path'})

    def test_default_https_port_same_origin(self):
        VERIFY.configuration({**BASE, 'DEPLOY_RELEASE_URL': 'https://EXAMPLE.test:443/release'})

    def test_other_port_is_other_origin(self):
        with self.assertRaises(VERIFY.VerificationError):
            VERIFY.configuration({**BASE, 'DEPLOY_RELEASE_URL': 'https://example.test:8443/release'})

    def test_sha_must_be_full_lowercase_hex(self):
        for sha in ('', 'a' * 7, 'g' * 40, 'A' * 40, SHA + '\n'):
            with self.subTest(sha=sha), self.assertRaises(VERIFY.VerificationError):
                VERIFY.configuration({**BASE, 'EXPECTED_RELEASE': sha})

    def test_runtime_policy(self):
        for mode, service in [('reload', ''), ('restart', 'php8.4-fpm.service'),
                              ('reload', 'ssh.service'), ('reload', 'php8.4-fpm.service;echo pwn'),
                              ('verify-only', 'php8.4-fpm.service')]:
            with self.subTest(mode=mode, service=service), self.assertRaises(VERIFY.VerificationError):
                VERIFY.configuration({**BASE, 'FPM_RELOAD_MODE': mode, 'FPM_SERVICE': service})
        for service in ('php-fpm.service', 'php8.4-fpm.service', 'clp-php-fpm.service'):
            VERIFY.configuration({**BASE, 'FPM_RELOAD_MODE': 'reload', 'FPM_SERVICE': service})

    def test_invalid_config_never_echoes_sensitive_values(self):
        with contextlib.redirect_stderr(io.StringIO()) as output:
            self.assertEqual(1, VERIFY.main(['preflight'], {**BASE, 'DEPLOY_RELEASE_URL': 'https://u:SECRET@host/'}))
        self.assertNotIn('SECRET', output.getvalue())

    def test_invalid_arguments_never_echo_values(self):
        with contextlib.redirect_stderr(io.StringIO()) as output, self.assertRaises(SystemExit) as result:
            VERIFY.main(['verify', '--secret', 'SENSITIVE'], BASE)
        self.assertEqual(2, result.exception.code)
        self.assertNotIn('SENSITIVE', output.getvalue())


class VerificationTests(unittest.TestCase):
    def setUp(self):
        self.config = VERIFY.configuration(BASE)
        self.calls = []
        self.outputs = []
        self.nonces = iter(f'{i:048x}' for i in range(1, 20))

    def fetch(self, url, timeout):
        self.calls.append((url, timeout))
        if url.endswith('/up'):
            return 200, {}, b'healthy'
        nonce = url.split('deploy_probe=')[1]
        return 200, {'content-type': 'application/json; charset=utf-8', 'cache-control': 'private, no-store'}, json.dumps({
            'status': 'ok', 'release': SHA, 'probe': nonce,
        }).encode()

    def run_probe(self, fetcher=None, **kwargs):
        options = {'sleeper': lambda _: None, 'nonce_factory': lambda: next(self.nonces),
                   'report': self.outputs.append}
        options.update(kwargs)
        VERIFY.verify(self.config, fetcher=fetcher or self.fetch, **options)

    def mutate(self, *, status=None, headers=None, body=None, payload=None, health=False):
        def fetch(url, timeout):
            code, original_headers, original_body = self.fetch(url, timeout)
            if url.endswith('/up') != health:
                return code, original_headers, original_body
            if payload is not None:
                original_body = json.dumps({**json.loads(original_body), **payload}).encode()
            return (code if status is None else status,
                    original_headers if headers is None else headers,
                    original_body if body is None else body)
        return fetch

    def test_three_consecutive_samples(self):
        self.run_probe()
        self.assertEqual(6, len(self.calls))
        self.assertEqual(3, len(set(url for url, _ in self.calls if '?deploy_probe=' in url)))
        self.assertEqual('active_release=verified', self.outputs[-1])

    def test_http200_alone_does_not_prove_release(self):
        with self.assertRaisesRegex(VERIFY.VerificationError, 'release_content_type_invalid'):
            self.run_probe(lambda url, timeout: (200, {}, b'ok'))
        self.assertNotIn('active_release=verified', self.outputs)

    def test_failed_health_blocks_release_request(self):
        with self.assertRaisesRegex(VERIFY.VerificationError, 'health_not_ok'):
            self.run_probe(self.mutate(status=500, health=True))
        self.assertEqual(1, len(self.calls))

    def test_release_error_status(self):
        for status in (301, 302, 401, 404, 500):
            with self.subTest(status=status), self.assertRaisesRegex(VERIFY.VerificationError, 'release_http_not_ok'):
                self.run_probe(self.mutate(status=status))

    def html_404(self, url, timeout):
        self.calls.append((url, timeout))
        if url.endswith('/up'):
            return 200, {}, b'healthy'
        return 404, {'content-type': 'text/html; charset=UTF-8'}, b'<html>SECRET_BODY</html>'

    def test_bootstrap_accepts_only_three_unsealed_html_404_samples_and_logs_it(self):
        self.run_probe(self.html_404, probe_current=True, bootstrap=True)
        self.assertEqual(6, len(self.calls))
        self.assertEqual(self.outputs.count('bootstrap_first_sealed_release=accepted reason=unsealed_release_http_404'), 3)
        self.assertEqual('current_release_contract=unsealed_bootstrap', self.outputs[-1])
        self.assertNotIn('active_release=verified', self.outputs)
        self.assertNotIn('SECRET_BODY', '\n'.join(self.outputs))

    def test_bootstrap_flag_does_not_accept_404_on_the_candidate_check(self):
        with self.assertRaisesRegex(VERIFY.VerificationError, 'release_http_not_ok'):
            self.run_probe(self.html_404, probe_current=False, bootstrap=True)
        self.assertNotIn('bootstrap_first_sealed_release=accepted reason=unsealed_release_http_404', self.outputs)

    def test_bootstrap_rejects_other_404_shapes_and_mixed_samples(self):
        for headers in ({'content-type': 'application/json'}, {'content-type': 'text/plain'}, {}):
            with self.subTest(headers=headers), self.assertRaisesRegex(VERIFY.VerificationError, 'release_http_not_ok'):
                self.run_probe(self.mutate(status=404, headers=headers), probe_current=True, bootstrap=True)
        count = 0
        def mixed(url, timeout):
            nonlocal count
            status, headers, body = self.html_404(url, timeout)
            if not url.endswith('/up'):
                count += 1
                if count == 2:
                    return self.fetch(url, timeout)
            return status, headers, body
        with self.assertRaisesRegex(VERIFY.VerificationError, 'release_samples_inconsistent'):
            self.run_probe(mixed, probe_current=True, bootstrap=True)

    def test_bootstrap_is_not_applicable_when_a_sealed_release_already_answers(self):
        self.run_probe(probe_current=True, bootstrap=True)
        self.assertEqual('bootstrap_first_sealed_release=not_applicable reason=sealed_release_present', self.outputs[-2])
        self.assertEqual('current_release_contract=verified', self.outputs[-1])

    @contextlib.contextmanager
    def local_routes(self, routes):
        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def do_GET(self):
                status, headers, body = routes[urllib.parse.urlsplit(self.path).path]
                self.send_response(status)
                for key, value in headers.items():
                    self.send_header(key, value)
                self.send_header('Content-Length', str(len(body)))
                self.end_headers()
                try:
                    self.wfile.write(body)
                except (BrokenPipeError, ConnectionResetError):
                    pass

        server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            yield 'http://127.0.0.1:%d' % server.server_address[1]
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=3)

    def http_config(self, origin):
        return VERIFY.Configuration(origin, origin + '/deployment-release', SHA, 'verify-only', '')

    def run_real_probe(self, config, **kwargs):
        VERIFY.verify(config, sleeper=lambda _: None, nonce_factory=lambda: next(self.nonces),
                      report=self.outputs.append, **kwargs)

    def test_bootstrap_large_html_404_is_absent_without_the_full_body(self):
        secret = b'SECRET_TAIL'
        html = (b'A' * VERIFY.UNSEALED_HTML_PREFIX) + secret + (b'B' * (VERIFY.MAX_BODY + 1))
        routes = {
            '/up': (200, {'Content-Type': 'text/plain'}, b'healthy'),
            '/deployment-release': (404, {'Content-Type': 'text/html; charset=UTF-8'}, html),
        }
        with self.local_routes(routes) as origin:
            status, headers, body = VERIFY.fetch(origin + '/deployment-release?deploy_probe=' + ('c' * 48), 3,
                                                  bootstrap_release_probe=True)
            self.assertEqual(404, status)
            self.assertEqual('text/html; charset=UTF-8', headers['content-type'])
            self.assertEqual(VERIFY.UNSEALED_HTML_PREFIX, len(body))
            self.assertNotIn(secret, body)
            self.run_real_probe(self.http_config(origin), probe_current=True, bootstrap=True)
        self.assertEqual(self.outputs.count('bootstrap_first_sealed_release=accepted reason=unsealed_release_http_404'), 3)
        self.assertEqual('current_release_contract=unsealed_bootstrap', self.outputs[-1])
        self.assertNotIn(secret.decode(), '\n'.join(self.outputs))
        self.assertNotIn('active_release=verified', self.outputs)

    def test_non_bootstrap_404_fails(self):
        small = {
            '/up': (200, {'Content-Type': 'text/plain'}, b'healthy'),
            '/deployment-release': (404, {'Content-Type': 'text/html'}, b'<html>SECRET_BODY</html>'),
        }
        large = {
            '/up': (200, {'Content-Type': 'text/plain'}, b'healthy'),
            '/deployment-release': (404, {'Content-Type': 'text/html; charset=UTF-8'},
                                    b'A' * (VERIFY.MAX_BODY + 64)),
        }
        with self.local_routes(small) as origin:
            config = self.http_config(origin)
            for kwargs in ({}, {'probe_current': True}, {'probe_current': False, 'bootstrap': True}):
                with self.subTest(body='small', **kwargs), self.assertRaisesRegex(VERIFY.VerificationError, '^release_http_not_ok$'):
                    self.run_real_probe(config, **kwargs)
                self.assertNotIn('SECRET_BODY', '\n'.join(self.outputs))
        with self.local_routes(large) as origin:
            config = self.http_config(origin)
            for kwargs in ({}, {'probe_current': True}, {'probe_current': False, 'bootstrap': True}):
                with self.subTest(body='large', **kwargs), self.assertRaisesRegex(VERIFY.VerificationError, '^response_too_large$'):
                    self.run_real_probe(config, **kwargs)
            with self.assertRaisesRegex(VERIFY.VerificationError, '^response_too_large$'):
                VERIFY.fetch(origin + '/deployment-release', 3)

    def test_oversized_http_200_stays_response_too_large(self):
        oversized = b'{' + (b'x' * VERIFY.MAX_BODY)
        routes = {
            '/up': (200, {'Content-Type': 'text/plain'}, b'healthy'),
            '/deployment-release': (200, {
                'Content-Type': 'application/json',
                'Cache-Control': 'no-store',
            }, oversized),
        }
        with self.local_routes(routes) as origin:
            with self.assertRaisesRegex(VERIFY.VerificationError, '^response_too_large$'):
                self.run_real_probe(self.http_config(origin), probe_current=True, bootstrap=True)
            with self.assertRaisesRegex(VERIFY.VerificationError, '^response_too_large$'):
                VERIFY.fetch(origin + '/deployment-release', 3, bootstrap_release_probe=True)
            self.assertNotIn('current_release_contract=verified', self.outputs)
        html_200 = (200, {'Content-Type': 'text/html'}, b'A' * (VERIFY.MAX_BODY + 8))
        json_404 = (404, {'Content-Type': 'application/json'}, oversized)
        with self.local_routes({'/page': html_200}) as origin:
            with self.assertRaisesRegex(VERIFY.VerificationError, '^response_too_large$'):
                VERIFY.fetch(origin + '/page', 3, bootstrap_release_probe=True)
        with self.local_routes({'/page': json_404}) as origin:
            with self.assertRaisesRegex(VERIFY.VerificationError, '^response_too_large$'):
                VERIFY.fetch(origin + '/page', 3, bootstrap_release_probe=True)

    def test_cli_bootstrap_is_explicit_and_ignored_by_final_verification(self):
        calls = []

        def fetch(url, timeout, *, bootstrap_release_probe=False):
            calls.append((url.endswith('/up'), bootstrap_release_probe))
            if url.endswith('/up'):
                return 200, {}, b'healthy'
            return 404, {'content-type': 'text/html'}, b'<html>SECRET_BODY</html>'
        enabled = {**BASE, 'BOOTSTRAP_FIRST_SEALED_RELEASE': 'true'}
        with mock.patch.object(VERIFY, 'fetch', side_effect=fetch):
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                self.assertEqual(0, VERIFY.main(['probe-current'], enabled))
            self.assertEqual(calls, [(True, False), (False, True)] * 3)
            self.assertIn('bootstrap_first_sealed_release=accepted reason=unsealed_release_http_404', output.getvalue())
            self.assertNotIn('SECRET_BODY', output.getvalue())
            calls.clear()
            error = io.StringIO()
            with contextlib.redirect_stderr(error):
                self.assertEqual(1, VERIFY.main(['verify'], enabled))
            self.assertEqual(calls, [(True, False), (False, False)])
            self.assertIn('reason=release_http_not_ok', error.getvalue())
            self.assertNotIn('SECRET_BODY', error.getvalue())
        for value in ('1', 'yes', 'TRUE', 'force'):
            with self.subTest(value=value), contextlib.redirect_stderr(io.StringIO()) as error:
                self.assertEqual(1, VERIFY.main(['preflight'], {**BASE, 'BOOTSTRAP_FIRST_SEALED_RELEASE': value}))
            self.assertIn('reason=invalid_bootstrap_flag', error.getvalue())

    def test_stale_release(self):
        with self.assertRaisesRegex(VERIFY.VerificationError, 'active_release_mismatch'):
            self.run_probe(self.mutate(payload={'release': 'b' * 40}))

    def test_probe_current_accepts_other_well_formed_release(self):
        self.run_probe(self.mutate(payload={'release': 'b' * 40}), probe_current=True)
        self.assertEqual('current_release_contract=verified', self.outputs[-1])
        self.assertNotIn('active_release=verified', self.outputs)

    def test_probe_current_rejects_mixed_running_releases(self):
        count = 0
        def fetch(url, timeout):
            nonlocal count
            status, headers, body = self.fetch(url, timeout)
            if not url.endswith('/up'):
                count += 1
                payload = json.loads(body)
                payload['release'] = ('b' if count == 1 else 'c') * 40
                body = json.dumps(payload).encode()
            return status, headers, body
        with self.assertRaisesRegex(VERIFY.VerificationError, 'release_samples_inconsistent'):
            self.run_probe(fetch, probe_current=True)

    def test_probe_current_rejects_missing_identity(self):
        with self.assertRaisesRegex(VERIFY.VerificationError, 'release_identity_invalid'):
            self.run_probe(self.mutate(payload={'release': 'unknown'}), probe_current=True)

    def test_nonce_mismatch_rejects_cached_or_static_body(self):
        with self.assertRaisesRegex(VERIFY.VerificationError, 'release_probe_mismatch'):
            self.run_probe(self.mutate(payload={'probe': 'old'}))

    def test_no_store_required(self):
        with self.assertRaisesRegex(VERIFY.VerificationError, 'release_cache_policy_invalid'):
            self.run_probe(self.mutate(headers={'content-type': 'application/json', 'cache-control': 'public, max-age=60'}))

    def test_payload_malformed_or_extra_information(self):
        for body in (b'{invalid', b'[]', b'null', b'"hello"', b'\xff', b'{' + b'"status":"ok",' * 2 + b'"release":"x","probe":"x"}'):
            with self.subTest(body=body), self.assertRaises(VERIFY.VerificationError):
                self.run_probe(self.mutate(body=body))
        with self.assertRaisesRegex(VERIFY.VerificationError, 'release_contract_invalid'):
            self.run_probe(self.mutate(payload={'secret': 'SENSITIVE'}))

    def test_failed_release_status(self):
        with self.assertRaisesRegex(VERIFY.VerificationError, 'release_status_not_ok'):
            self.run_probe(self.mutate(payload={'status': 'degraded'}))

    def test_oversized_payload(self):
        with self.assertRaisesRegex(VERIFY.VerificationError, 'response_too_large'):
            self.run_probe(self.mutate(body=b'x' * (VERIFY.MAX_BODY + 1)))

    def test_later_sample_failure_prevents_success(self):
        count = 0
        def fetch(url, timeout):
            nonlocal count
            count += 1
            if count == 6:
                return 500, {}, b'failure'
            return self.fetch(url, timeout)
        with self.assertRaises(VERIFY.VerificationError):
            self.run_probe(fetch)
        self.assertNotIn('active_release=verified', self.outputs)

    def test_bounded_samples_and_deadline(self):
        with self.assertRaisesRegex(VERIFY.VerificationError, 'invalid_sample_count'):
            self.run_probe(attempts=1)
        times = iter((0, VERIFY.DEADLINE_SECONDS + 1))
        with self.assertRaisesRegex(VERIFY.VerificationError, 'verification_deadline_exceeded'):
            self.run_probe(clock=lambda: next(times))
        self.assertEqual([], self.calls)

    def release_body(self, url):
        nonce = url.split('deploy_probe=', 1)[1]
        return 200, {'content-type': 'application/json', 'cache-control': 'no-store'}, json.dumps({
            'status': 'ok', 'release': SHA, 'probe': nonce,
        }).encode()

    def test_one_transport_failure_then_success_passes_with_a_fresh_nonce(self):
        release_attempts = []

        def fetch(url, timeout):
            self.calls.append((url, timeout))
            if url.endswith('/up'):
                if len(self.calls) == 1:
                    raise VERIFY.VerificationError('transport_or_tls_error')
                return 200, {}, b'healthy'
            release_attempts.append(url.split('deploy_probe=', 1)[1])
            if len(release_attempts) == 1:
                raise VERIFY.VerificationError('transport_or_tls_error')
            return self.release_body(url)

        sleeps = []
        self.run_probe(fetch, sleeper=sleeps.append)
        self.assertEqual([
            VERIFY.TRANSPORT_BACKOFF_SECONDS, VERIFY.TRANSPORT_BACKOFF_SECONDS,
            VERIFY.SAMPLE_INTERVAL, VERIFY.SAMPLE_INTERVAL,
        ], sleeps)
        self.assertEqual(self.outputs.count('transport_retry=1 target=health'), 1)
        self.assertEqual(self.outputs.count('transport_retry=1 target=release'), 1)
        self.assertNotIn('transport_retry=2 target=health', self.outputs)
        self.assertEqual(len(release_attempts), len(set(release_attempts)))
        self.assertEqual('active_release=verified', self.outputs[-1])
        self.assertTrue(all(timeout <= VERIFY.REQUEST_TIMEOUT for _, timeout in self.calls))
        self.assertNotIn('example.test', '\n'.join(self.outputs))

    def test_probe_current_uses_the_same_transport_retries(self):
        def fetch(url, timeout):
            self.calls.append((url, timeout))
            if url.endswith('/up') and len(self.calls) == 1:
                raise VERIFY.VerificationError('transport_or_tls_error')
            if url.endswith('/up'):
                return 200, {}, b'healthy'
            return self.release_body(url)
        self.run_probe(fetch, probe_current=True)
        self.assertEqual(self.outputs.count('transport_retry=1 target=health'), 1)
        self.assertEqual('current_release_contract=verified', self.outputs[-1])

    def test_persistent_transport_failure_stays_transport_or_tls_error(self):
        def fetch(url, timeout):
            self.calls.append((url, timeout))
            raise VERIFY.VerificationError('transport_or_tls_error')
        with self.assertRaisesRegex(VERIFY.VerificationError, '^transport_or_tls_error$'):
            self.run_probe(fetch, clock=lambda: 0)
        self.assertEqual(VERIFY.TRANSPORT_ATTEMPTS, len(self.calls))
        self.assertTrue(all(url.endswith('/up') for url, _ in self.calls))
        self.assertEqual(['transport_retry=1 target=health', 'transport_retry=2 target=health'], self.outputs)

    def test_http_status_content_and_redirect_errors_are_never_retried(self):
        def recording(fetch):
            def wrapped(url, timeout):
                self.calls.append((url, timeout))
                return fetch(url, timeout)
            return wrapped

        def redirect(url, timeout):
            if url.endswith('/up'):
                return 200, {}, b'healthy'
            raise VERIFY.VerificationError('redirect_rejected')

        cases = (
            ('health_not_ok', lambda url, timeout: (500, {}, b'SECRET')),
            ('release_http_not_ok', self.mutate(status=404, body=b'SECRET')),
            ('release_content_type_invalid', self.mutate(headers={'content-type': 'text/plain', 'cache-control': 'no-store'})),
            ('response_too_large', self.mutate(body=b'x' * (VERIFY.MAX_BODY + 1))),
            ('active_release_mismatch', self.mutate(payload={'release': 'b' * 40})),
            ('redirect_rejected', redirect),
        )
        for reason, fetcher in cases:
            with self.subTest(reason=reason):
                self.calls.clear()
                self.outputs.clear()
                # mutate() already records through self.fetch. The others do not.
                wrapped = recording(fetcher) if reason in ('health_not_ok', 'redirect_rejected') else fetcher
                with self.assertRaisesRegex(VERIFY.VerificationError, '^%s$' % reason):
                    self.run_probe(wrapped)
                self.assertFalse(any(line.startswith('transport_retry=') for line in self.outputs))
                self.assertNotIn('SECRET', '\n'.join(self.outputs))
                release_calls = [url for url, _ in self.calls if '?deploy_probe=' in url]
                self.assertEqual(0 if reason == 'health_not_ok' else 1, len(release_calls))
                self.assertEqual(1 if reason == 'health_not_ok' else 2, len(self.calls))

    def test_release_retries_reject_a_reused_or_stale_nonce(self):
        nonces = iter([f'{1:048x}', f'{1:048x}'])

        def fetch(url, timeout):
            self.calls.append((url, timeout))
            if url.endswith('/up'):
                return 200, {}, b'healthy'
            raise VERIFY.VerificationError('transport_or_tls_error')

        with self.assertRaisesRegex(VERIFY.VerificationError, '^invalid_probe_nonce$'):
            self.run_probe(fetch, nonce_factory=lambda: next(nonces))
        self.assertEqual(1, len([url for url, _ in self.calls if '?deploy_probe=' in url]))

        self.calls.clear()
        self.outputs.clear()
        self.nonces = iter(f'{i:048x}' for i in range(1, 20))
        seen = []

        def stale(url, timeout):
            self.calls.append((url, timeout))
            if url.endswith('/up'):
                return 200, {}, b'healthy'
            nonce = url.split('deploy_probe=', 1)[1]
            seen.append(nonce)
            if len(seen) == 1:
                raise VERIFY.VerificationError('transport_or_tls_error')
            body = json.dumps({'status': 'ok', 'release': SHA, 'probe': seen[0]}).encode()
            return 200, {'content-type': 'application/json', 'cache-control': 'no-store'}, body

        with self.assertRaisesRegex(VERIFY.VerificationError, '^release_probe_mismatch$'):
            self.run_probe(stale)
        self.assertEqual(2, len(seen))
        self.assertNotEqual(seen[0], seen[1])
        self.assertIn('transport_retry=1 target=release', self.outputs)

    def test_deadline_blocks_another_transport_try(self):
        times = iter((0, 0, VERIFY.DEADLINE_SECONDS))

        def fetch(url, timeout):
            self.calls.append((url, timeout))
            raise VERIFY.VerificationError('transport_or_tls_error')

        sleeps = []
        with self.assertRaisesRegex(VERIFY.VerificationError, '^verification_deadline_exceeded$'):
            self.run_probe(fetch, clock=lambda: next(times), sleeper=sleeps.append)
        self.assertEqual(1, len(self.calls))
        self.assertEqual([], sleeps)
        self.assertNotIn('transport_retry=1 target=health', self.outputs)

    def test_http_404_is_returned_to_the_caller_without_logging_the_body(self):
        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def do_GET(self):
                body = b'<html>SECRET_BODY</html>'
                self.send_response(404)
                self.send_header('Content-Type', 'text/html')
                self.send_header('Content-Length', str(len(body)))
                self.end_headers()
                self.wfile.write(body)
        server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            status, headers, body = VERIFY.fetch('http://127.0.0.1:%d/release' % server.server_address[1], 3)
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=3)
        self.assertEqual(404, status)
        self.assertEqual('text/html', headers['content-type'])
        self.assertIn(b'SECRET_BODY', body)

    def test_transport_tls_timeout_errors_are_sanitized(self):
        for failure in (urllib.error.URLError('SECRET'), TimeoutError('SECRET'), OSError('SECRET'),
                        http.client.IncompleteRead(b'SECRET'), ValueError('SECRET')):
            with mock.patch('urllib.request.build_opener') as build:
                build.return_value.open.side_effect = failure
                with self.assertRaisesRegex(VERIFY.VerificationError, '^transport_or_tls_error$'):
                    VERIFY.fetch('https://example.test/up', 1)

    def test_real_redirect_handler_rejects_target(self):
        with self.assertRaisesRegex(VERIFY.VerificationError, '^redirect_rejected$'):
            VERIFY.NoRedirect().redirect_request(None, None, 302, 'SECRET', {}, 'https://evil.test')


class RuntimeReloadTests(unittest.TestCase):
    def run_reload(self, mode, service='', status=0):
        with tempfile.TemporaryDirectory(prefix='fokus-synthetic-runtime-') as directory:
            root = Path(directory)
            sudo = root / 'sudo'
            sudo.write_text('#!/bin/sh\nprintf "%s\\n" "$@" > "$CALL_LOG"\necho SENSITIVE >&2\nexit "$FAKE_EXIT"\n')
            sudo.chmod(0o700)
            env = {**os.environ, 'PATH': str(root) + ':' + os.environ['PATH'],
                   'CALL_LOG': str(root / 'calls'), 'FAKE_EXIT': str(status),
                   'FPM_RELOAD_MODE': '', 'FPM_SERVICE': ''}
            result = subprocess.run(['bash', str(ROOT / 'deploy/reload-runtime.sh'), mode, service],
                                    env=env, capture_output=True, text=True, check=False)
            calls = (root / 'calls').read_text() if (root / 'calls').exists() else None
            self.assertNotIn('SENSITIVE', result.stdout + result.stderr)
            return result, calls

    def test_explicit_verify_only_has_no_privileged_call(self):
        result, calls = self.run_reload('verify-only')
        self.assertEqual(0, result.returncode)
        self.assertIsNone(calls)
        self.assertIn('not_requested', result.stdout)

    def test_reload_exact_command_without_restart(self):
        result, calls = self.run_reload('reload', 'php8.4-fpm.service')
        self.assertEqual(0, result.returncode)
        self.assertEqual('-n\n--\n/usr/bin/systemctl\nreload\n--\nphp8.4-fpm.service\n', calls)

    def test_denied_reload_preserves_failure_without_fallback(self):
        for status in (1, 5, 126, 127, 255):
            with self.subTest(status=status):
                result, calls = self.run_reload('reload', 'clp-php-fpm.service', status)
                self.assertEqual(status, result.returncode)
                self.assertNotIn('restart', calls)
                self.assertNotIn('completed', result.stdout)

    def test_invalid_configuration_never_executes(self):
        for mode, service in [('', ''), ('restart', 'php8.4-fpm.service'), ('reload', ''),
                              ('reload', 'sshd.service'), ('reload', 'php8.4-fpm.service;touch pwn'),
                              ('verify-only', 'php8.4-fpm.service')]:
            with self.subTest(mode=mode, service=service):
                result, calls = self.run_reload(mode, service)
                self.assertEqual(64, result.returncode)
                self.assertIsNone(calls)


class LocalHTTPSIntegrationTests(unittest.TestCase):
    """Real TLS and HTTP on ephemeral loopback, with a disposable test-only CA."""
    @classmethod
    def setUpClass(cls):
        cls.directory = tempfile.TemporaryDirectory(prefix='fokus-local-tls-')
        root = Path(cls.directory.name)
        cls.cert = root / 'cert.pem'
        key = root / 'key.pem'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                        '-keyout', str(key), '-out', str(cls.cert), '-days', '1',
                        '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost,IP:127.0.0.1'],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def do_GET(self):
                path = urllib.parse.urlsplit(self.path)
                if path.path == '/redirect':
                    self.send_response(302)
                    self.send_header('Location', '/release')
                    self.end_headers()
                    return
                if path.path == '/up':
                    body = b'healthy'
                else:
                    query = urllib.parse.parse_qs(path.query)
                    body = json.dumps({'status': 'ok', 'release': SHA,
                                       'probe': query.get('deploy_probe', [''])[0]}).encode()
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Cache-Control', 'no-store')
                self.send_header('Content-Length', str(len(body)))
                self.end_headers()
                self.wfile.write(body)
        cls.server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cls.cert, key)
        cls.server.socket = context.wrap_socket(cls.server.socket, server_side=True)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        cls.origin = 'https://127.0.0.1:' + str(cls.server.server_address[1])

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join(timeout=3)
        cls.directory.cleanup()

    def test_untrusted_tls_is_rejected_without_body_or_certificate_leak(self):
        with self.assertRaisesRegex(VERIFY.VerificationError, '^transport_or_tls_error$'):
            VERIFY.fetch(self.origin + '/up', 3)

    def test_real_redirect_is_not_followed(self):
        with mock.patch.dict(os.environ, {'SSL_CERT_FILE': str(self.cert)}):
            with self.assertRaisesRegex(VERIFY.VerificationError, '^redirect_rejected$'):
                VERIFY.fetch(self.origin + '/redirect', 3)

    def test_cli_verifies_three_real_https_samples(self):
        env = {**os.environ, **BASE, 'SSL_CERT_FILE': str(self.cert),
               'DEPLOY_PUBLIC_BASE_URL': self.origin,
               'DEPLOY_RELEASE_URL': self.origin + '/release'}
        result = subprocess.run([sys.executable, str(ROOT / 'tools/ci/verify-deployment.py'), 'verify'],
                                env=env, capture_output=True, text=True, timeout=20, check=False)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(3, result.stdout.count('active_release_sample=ok'))
        self.assertIn('active_release=verified', result.stdout)
        self.assertNotIn(SHA, result.stdout)
        self.assertNotIn(self.origin, result.stdout)


if __name__ == '__main__':
    unittest.main()
