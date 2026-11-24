#!/usr/bin/env python3
"""Fail-closed deployment evidence; never log remote payloads or configuration."""
import argparse
import http.client
import json
import os
import re
import secrets
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass

MAX_BODY = 4096
# A legacy text/html 404 can exceed MAX_BODY. Bootstrap probe-current classifies
# that page from status and content-type and reads only this prefix.
UNSEALED_HTML_PREFIX = 512
REQUEST_TIMEOUT = 5
DEADLINE_SECONDS = 60
SAMPLE_INTERVAL = 2
# One logical request may repeat a transport failure this many times. The
# budget stays inside DEADLINE_SECONDS: three timed-out tries on every sample
# would pass 60s and fail closed on the deadline. A single stall, which is
# what the gate saw, costs one 5s timeout plus this backoff and still fits.
# Raising either limit would make a dead origin occupy the two-minute step
# for longer before the same fail-closed result.
TRANSPORT_ATTEMPTS = 3
TRANSPORT_BACKOFF_SECONDS = 1
SERVICE_PATTERN = r"(?:clp-php-fpm|php(?:[0-9]+\.[0-9]+)?-fpm)\.service"


class VerificationError(Exception):
    """Contains only a fixed, safe reason code, never remote content."""


@dataclass(frozen=True)
class Configuration:
    base_url: str
    release_url: str
    release: str
    mode: str
    service: str


def validated_url(value):
    try:
        if not value or any(ord(char) <= 32 or ord(char) == 127 for char in value):
            raise ValueError
        parts = urllib.parse.urlsplit(value)
        if (parts.scheme != 'https' or not parts.hostname or parts.username is not None
                or parts.password is not None or parts.query or parts.fragment
                or '\\' in value or parts.port == 0):
            raise ValueError
        return parts
    except ValueError:
        raise VerificationError('invalid_https_url') from None


def configuration(env):
    base = env.get('DEPLOY_PUBLIC_BASE_URL', '')
    release_url = env.get('DEPLOY_RELEASE_URL', '')
    base_parts = validated_url(base)
    release_parts = validated_url(release_url)
    if base_parts.path not in ('', '/'):
        raise VerificationError('base_url_must_be_origin')
    origin = lambda parts: (parts.hostname.lower(), parts.port or 443)
    if origin(base_parts) != origin(release_parts):
        raise VerificationError('release_origin_mismatch')
    if release_parts.path in ('', '/', '/up'):
        raise VerificationError('dedicated_release_path_required')
    release = env.get('EXPECTED_RELEASE', '')
    if re.fullmatch(r'[0-9a-f]{40}', release) is None:
        raise VerificationError('invalid_expected_release')
    mode = env.get('FPM_RELOAD_MODE', '')
    service = env.get('FPM_SERVICE', '')
    if mode not in ('reload', 'verify-only'):
        raise VerificationError('explicit_runtime_mode_required')
    if mode == 'reload' and re.fullmatch(SERVICE_PATTERN, service) is None:
        raise VerificationError('invalid_fpm_service')
    if mode == 'verify-only' and service:
        raise VerificationError('service_not_allowed_in_verify_only')
    return Configuration(base.rstrip('/'), release_url, release, mode, service)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise VerificationError('redirect_rejected')


def normalized_headers(message):
    return {key.lower(): value for key, value in message.items()}


def unsealed_html_404(status, headers):
    content_type = headers.get('content-type', '').split(';', 1)[0].strip().lower()
    return status == 404 and content_type == 'text/html'


def read_response_body(stream, status, headers, *, bootstrap_release_probe):
    # Decide from status and content-type before applying the size cap.
    # Only a bootstrap release probe may treat a non-JSON text/html 404 as the
    # absent unsealed page. HTTP 200, JSON and every other response stay capped.
    bounded_prefix = bootstrap_release_probe and unsealed_html_404(status, headers)
    try:
        body = stream.read(UNSEALED_HTML_PREFIX if bounded_prefix else MAX_BODY + 1)
    except (OSError, http.client.HTTPException, ValueError):
        raise VerificationError('transport_or_tls_error') from None
    if not bounded_prefix and len(body) > MAX_BODY:
        raise VerificationError('response_too_large')
    return body


def close_response(response):
    try:
        response.close()
    except (OSError, http.client.HTTPException, ValueError):
        pass


def fetch(url, timeout, *, bootstrap_release_probe=False):
    request = urllib.request.Request(url, headers={
        'Accept': 'application/json',
        'Cache-Control': 'no-cache, no-store',
        'User-Agent': 'Fokus-Deployment-Verification/1',
    })
    try:
        # HTTPS uses the platform trust store. No unverified TLS context or redirect.
        with urllib.request.build_opener(NoRedirect()).open(request, timeout=timeout) as response:
            headers = normalized_headers(response.headers)
            body = read_response_body(response, response.status, headers,
                                       bootstrap_release_probe=bootstrap_release_probe)
            return response.status, headers, body
    except VerificationError:
        raise
    except urllib.error.HTTPError as error:
        try:
            headers = normalized_headers(error.headers)
            body = read_response_body(error, error.code, headers,
                                       bootstrap_release_probe=bootstrap_release_probe)
            return error.code, headers, body
        finally:
            # Drop the unread tail of a legacy error page instead of draining it.
            close_response(error)
    except (urllib.error.URLError, TimeoutError, OSError, http.client.HTTPException, ValueError):
        raise VerificationError('transport_or_tls_error') from None


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise VerificationError('duplicate_json_key')
        result[key] = value
    return result


UNSEALED_HTML_404 = 'unsealed_html_404'


def bootstrap_requested(env):
    value = env.get('BOOTSTRAP_FIRST_SEALED_RELEASE', '')
    if value == '':
        return False
    if value not in ('true', 'false'):
        raise VerificationError('invalid_bootstrap_flag')
    return value == 'true'


def verify(config, attempts=3, *, fetcher=None, sleeper=time.sleep,
           clock=time.monotonic, nonce_factory=lambda: secrets.token_hex(24), report=print,
           probe_current=False, bootstrap=False):
    if fetcher is None:
        release_url = config.release_url
        # The candidate check never receives this allowance, even when the flag is set.
        allow_unsealed_prefix = probe_current and bootstrap

        def fetcher(url, timeout):
            return fetch(url, timeout, bootstrap_release_probe=bool(
                allow_unsealed_prefix and url.startswith(release_url) and '?deploy_probe=' in url))
    if not 3 <= attempts <= 10:
        raise VerificationError('invalid_sample_count')
    deadline = clock() + DEADLINE_SECONDS
    used_nonces = set()
    observed_release = None

    def transport_failure(error):
        return isinstance(error, VerificationError) and error.args == ('transport_or_tls_error',)

    def fetch_attempt(url_factory, target):
        # url_factory runs once per try. A release retry therefore mints a new
        # nonce and never resends the nonce whose connection failed.
        for attempt in range(1, TRANSPORT_ATTEMPTS + 1):
            remaining = deadline - clock()
            if remaining <= 0:
                raise VerificationError('verification_deadline_exceeded')
            url = url_factory()
            try:
                return fetcher(url, min(REQUEST_TIMEOUT, remaining))
            except VerificationError as error:
                if not transport_failure(error) or attempt == TRANSPORT_ATTEMPTS:
                    raise
                remaining = deadline - clock()
                if remaining <= 0:
                    raise VerificationError('verification_deadline_exceeded')
                report('transport_retry=%d target=%s' % (attempt, target))
                sleeper(min(TRANSPORT_BACKOFF_SECONDS, remaining))

    for sample in range(attempts):
        if sample:
            sleeper(SAMPLE_INTERVAL)
        status, _, _ = fetch_attempt(lambda: config.base_url + '/up', 'health')
        if status != 200:
            raise VerificationError('health_not_ok')
        report('application_health=ok')
        issued = []

        def release_probe_url():
            nonce = nonce_factory()
            if re.fullmatch(r'[0-9a-f]{48}', nonce) is None or nonce in used_nonces:
                raise VerificationError('invalid_probe_nonce')
            used_nonces.add(nonce)
            issued.append(nonce)
            return config.release_url + '?deploy_probe=' + nonce

        status, headers, body = fetch_attempt(release_probe_url, 'release')
        nonce = issued[-1]
        if unsealed_html_404(status, headers):
            # The final candidate check never accepts this 404, even if the flag is set.
            if not (probe_current and bootstrap):
                raise VerificationError('release_http_not_ok')
            if observed_release not in (None, UNSEALED_HTML_404):
                raise VerificationError('release_samples_inconsistent')
            observed_release = UNSEALED_HTML_404
            if clock() > deadline:
                raise VerificationError('verification_deadline_exceeded')
            report('bootstrap_first_sealed_release=accepted reason=unsealed_release_http_404')
            continue
        if status != 200:
            raise VerificationError('release_http_not_ok')
        if headers.get('content-type', '').split(';', 1)[0].strip().lower() != 'application/json':
            raise VerificationError('release_content_type_invalid')
        directives = {item.strip().lower() for item in headers.get('cache-control', '').split(',')}
        if 'no-store' not in directives:
            raise VerificationError('release_cache_policy_invalid')
        if len(body) > MAX_BODY:
            raise VerificationError('response_too_large')
        try:
            payload = json.loads(body, object_pairs_hook=unique_object)
        except (ValueError, UnicodeError, RecursionError):
            raise VerificationError('release_json_invalid') from None
        if not isinstance(payload, dict) or set(payload) != {'status', 'release', 'probe'}:
            raise VerificationError('release_contract_invalid')
        if payload['status'] != 'ok':
            raise VerificationError('release_status_not_ok')
        if not isinstance(payload['release'], str) or re.fullmatch(r'[0-9a-f]{40}', payload['release']) is None:
            raise VerificationError('release_identity_invalid')
        if not probe_current and payload['release'] != config.release:
            raise VerificationError('active_release_mismatch')
        if observed_release is not None and observed_release != payload['release']:
            raise VerificationError('release_samples_inconsistent')
        observed_release = payload['release']
        if payload['probe'] != nonce:
            raise VerificationError('release_probe_mismatch')
        if clock() > deadline:
            raise VerificationError('verification_deadline_exceeded')
        report('active_release_sample=ok')
    if observed_release == UNSEALED_HTML_404:
        report('current_release_contract=unsealed_bootstrap')
        return
    if probe_current and bootstrap:
        report('bootstrap_first_sealed_release=not_applicable reason=sealed_release_present')
    report('current_release_contract=verified' if probe_current else 'active_release=verified')


class SafeParser(argparse.ArgumentParser):
    def error(self, message):
        self.exit(2, 'deployment_verification=failed reason=invalid_arguments\n')


def main(argv=None, env=None):
    parser = SafeParser(description=__doc__)
    parser.add_argument('command', choices=('preflight', 'probe-current', 'verify'))
    parser.add_argument('--attempts', type=int, default=3, choices=range(3, 11))
    args = parser.parse_args(argv)
    try:
        environment = os.environ if env is None else env
        # Invalid values fail every command. Only probe-current may use a true flag,
        # and only to accept the unsealed HTML 404. verify never does.
        bootstrap = bootstrap_requested(environment)
        config = configuration(environment)
        if args.command == 'preflight':
            print('deployment_configuration=valid')
            print('runtime_reload=' + ('required' if config.mode == 'reload' else 'not_requested'))
        else:
            verify(config, args.attempts, probe_current=args.command == 'probe-current',
                   bootstrap=bootstrap and args.command == 'probe-current')
        return 0
    except VerificationError as error:
        print('deployment_verification=failed reason=' + str(error), file=sys.stderr)
        return 1
    except Exception:
        print('deployment_verification=failed reason=unexpected_verification_error', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
