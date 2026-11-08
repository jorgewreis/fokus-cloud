#!/usr/bin/env python3
"""Real local Laravel HTTP integration against the exact PR #57 verifier.

No production requests, no TLS weakening: localhost HTTP is used only by constructing
an internal test Configuration, never by relaxing the verifier's public CLI preflight.
"""
import contextlib
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import types
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
VERIFIER_COMMIT = 'a8cc9729181471c9f46ccb15d51383e1aceb61ab'
VERIFIER_BLOB = '1a3275aa5619b1651a2d77d3e51c3d1d8b9c778d'
NONCE = 'a' * 48
PROBE_PATHS = ('/_deployment/release', '/_deployment/release/', '//_deployment/release', '///_deployment/release///')


def verifier():
    candidate = ROOT / 'tools/ci/verify-deployment.py'
    if candidate.exists():
        data = candidate.read_bytes()
    else:
        data = subprocess.check_output(['git', 'show', VERIFIER_COMMIT + ':tools/ci/verify-deployment.py'], cwd=ROOT)
    blob = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
    if blob != VERIFIER_BLOB:
        raise AssertionError('PR57 verifier drift: review the contract and explicitly update pin')
    module = types.ModuleType('deployment_verifier')
    sys.modules[module.__name__] = module
    exec(compile(data, 'pinned-pr57-verifier', 'exec'), module.__dict__)
    return module


def command(*args, cwd=ROOT, env=None):
    subprocess.run(args, cwd=cwd, env=env, check=True, stdout=subprocess.DEVNULL)


@contextlib.contextmanager
def server(root, env, opcache=False):
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with tempfile.TemporaryFile() as log:
        process = subprocess.Popen(['php', '-d', 'opcache.enable=' + str(int(opcache)), '-d', 'opcache.enable_cli=' + str(int(opcache)),
                                    '-S', '127.0.0.1:' + str(port), '-t', str(root / 'public'), str(root / 'public/index.php')],
                                   cwd=root, env=env, stdout=log, stderr=log)
        base = 'http://127.0.0.1:' + str(port)
        try:
            for _ in range(100):
                if process.poll() is not None:
                    raise AssertionError('local PHP server failed')
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.05)
            else:
                raise AssertionError('local PHP server timed out')
            yield base
        finally:
            process.terminate()
            process.wait(timeout=10)


def response(base, query='?deploy_probe=' + NONCE, path='/_deployment/release'):
    try:
        result = urllib.request.urlopen(base + path + query, timeout=6)
    except urllib.error.HTTPError as error:
        result = error
    with result:
        body = result.read()
        assert b'DO_NOT_LEAK_SECRET' not in body
        assert 'no-store' in result.headers.get('Cache-Control', '')
        assert result.headers.get_content_type() == 'application/json'
        payload = json.loads(body)
        assert set(payload) == {'status', 'release', 'probe'}
        return result.status, payload


def unavailable(base, path='/_deployment/release'):
    status, body = response(base, path=path)
    assert status == 503, (status, body)
    assert body == dict(status='unavailable', release=None, probe=None)


def rejected(module, config, reason, **kwargs):
    try:
        module.verify(config, sleeper=lambda _: None, report=lambda _: None, **kwargs)
    except module.VerificationError as error:
        assert str(error) == reason, str(error)
    else:
        raise AssertionError('verifier accepted invalid evidence')



def adversarial_samples(module, config):
    # Fault injection wraps actual HTTP responses; production verifier remains unchanged.
    for fault, reason in (('cached', 'release_probe_mismatch'),
                          ('mixed', 'release_samples_inconsistent'),
                          ('http', 'release_http_not_ok'),
                          ('cache_header', 'release_cache_policy_invalid'),
                          ('leak', 'release_contract_invalid')):
        samples = []
        def fetcher(url, timeout):
            status, headers, body = module.fetch(url, timeout)
            if '?deploy_probe=' not in url:
                return status, headers, body
            samples.append((status, headers, body))
            if len(samples) == 1:
                return status, headers, body
            if fault == 'cached':
                return samples[0]
            if fault == 'http':
                return 502, headers, body
            if fault == 'cache_header':
                headers = dict(headers, **{'cache-control': 'public, max-age=600'})
            if fault in ('mixed', 'leak'):
                payload = json.loads(body)
                if fault == 'mixed':
                    payload['release'] = 'f' * 40 if payload['release'] != 'f' * 40 else 'a' * 40
                else:
                    payload['path'] = 'DO_NOT_LEAK_SECRET'
                body = json.dumps(payload).encode()
            return status, headers, body
        # Probe-current specifically catches inconsistent instances without requiring expected SHA.
        rejected(module, config, reason, fetcher=fetcher, probe_current=fault == 'mixed')


def main():
    module = verifier()
    sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    env = dict(os.environ, APP_ENV='testing', APP_DEBUG='true', APP_KEY='base64:' + 'YWFh' * 10 + 'YWE=',
               DB_CONNECTION='sqlite', DB_DATABASE=':memory:', SESSION_DRIVER='array', CACHE_STORE='array', LOG_CHANNEL='stderr')
    # Ensure this is a real OPcache test, not an accidental pass without extension.
    command('php', '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1', '-r',
            'exit(function_exists("opcache_get_status") && opcache_get_status(false) !== false ? 0 : 1);')
    with tempfile.TemporaryDirectory(prefix='release-integration-') as temp:
        # Both cache modes use a fresh artifact, never reseal an existing root.
        for cached in (False, True):
            parent = Path(temp) / ('cached' if cached else 'uncached')
            parent.mkdir()
            root = parent / sha
            command(sys.executable, 'tools/ci/prepare-release.py', 'prepare', '--source', str(ROOT), '--root', str(root))
            with server(root, env) as base:
                unavailable(base)
            if cached:
                command('php', 'artisan', 'config:cache', cwd=root, env=env)
                command('php', 'artisan', 'route:cache', cwd=root, env=env)
            command(sys.executable, 'tools/ci/prepare-release.py', 'seal', '--source', str(ROOT), '--root', str(root))
            with server(root, env) as base:
                config = module.Configuration(base, base + '/_deployment/release', sha, 'verify-only', '')
                started = time.monotonic()
                module.verify(config, sleeper=lambda _: None, report=lambda _: None)
                print('real_laravel_samples=3 cached=' + str(cached).lower() + ' elapsed_seconds=%.3f' % (time.monotonic() - started))
                adversarial_samples(module, config)
                for path in PROBE_PATHS:
                    assert response(base, path=path)[1] == dict(status='ok', release=sha, probe=NONCE)
                for query in ('', '?deploy_probe=secret', '?deploy_probe[]=secret', '?deploy_probe=' + 'A' * 48):
                    assert response(base, query) == (400, dict(status='invalid_probe', release=None, probe=None))
                wrong = module.Configuration(base, config.release_url, 'f' * 40 if sha != 'f' * 40 else 'a' * 40, 'verify-only', '')
                rejected(module, wrong, 'active_release_mismatch')
                for relative, replacement in (
                    ('.release/manifest.json', b'{}'),
                    ('.release/identity.php', b'<?php throw new RuntimeException("DO_NOT_LEAK_SECRET");'),
                    ('.release/identity.php', b'<?php this is invalid DO_NOT_LEAK_SECRET'),
                    ('bootstrap/app.php', b'<?php throw new RuntimeException("DO_NOT_LEAK_SECRET");'),
                    ('app/Support/Deployment/ReleaseIdentity.php', None),
                ):
                    path = root / relative
                    original = path.read_bytes()
                    try:
                        if replacement is None:
                            path.unlink()
                        else:
                            path.write_bytes(replacement)
                        # Every accepted path variant must share the pre-bootstrap
                        # boundary, including APP_DEBUG exceptions containing a canary.
                        for probe_path in PROBE_PATHS:
                            unavailable(base, path=probe_path)
                    finally:
                        path.write_bytes(original)
                path = root / '.release/identity.php'
                original = path.read_bytes()
                try:
                    path.write_bytes(original.replace(sha.encode(), b'f' * 40))
                    unavailable(base)
                finally:
                    path.write_bytes(original)
                # Added runtime/cache files cannot be ignored by the manifest.
                extra = root / 'bootstrap/cache/unexpected.php'
                extra.write_text('<?php return [];')
                try:
                    unavailable(base)
                finally:
                    extra.unlink()
            with server(root, env, opcache=True) as base:
                unavailable(base)
        # Real transport unavailability after the local server is stopped.
        rejected(module, config, 'transport_or_tls_error')
    print('release_identity_integration=passed')


if __name__ == '__main__':
    main()
