#!/usr/bin/env python3
"""Verify GitHub OIDC claims offline; not an activation authorization by itself.

Caller must obtain keys only from the pinned GitHub issuer and authenticate a
fresh remote challenge. Replay consumption and environment configuration are
separate mandatory controls. No network, token output or mutation here.
"""
import base64
import json
import re
import urllib.error
import urllib.request

ISSUER = 'https://token.actions.githubusercontent.com'
JWKS_URL = ISSUER + '/.well-known/jwks'
MAX_TOKEN = 16384


def require(condition, reason):
    if not condition:
        raise ValueError(reason)


def unique(pairs):
    out = {}
    for key, value in pairs:
        require(key not in out, 'duplicate_json_key')
        out[key] = value
    return out


def decode(value):
    require(isinstance(value, str) and re.fullmatch(r'[A-Za-z0-9_-]+', value), 'invalid_base64url')
    return base64.b64decode(value + '=' * (-len(value) % 4), altchars=b'-_', validate=True)


def document(value):
    try:
        result = json.loads(decode(value), object_pairs_hook=unique)
    except (ValueError, UnicodeError) as error:
        raise ValueError('invalid_token_json') from error
    require(isinstance(result, dict), 'invalid_token_json')
    return result


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


def fetch_jwks():
    """Fetch only the issuer's fixed HTTPS JWKS; reject redirects and oversized data."""
    opener = urllib.request.build_opener(NoRedirect())
    request = urllib.request.Request(JWKS_URL, headers={'Accept': 'application/json'}, method='GET')
    try:
        with opener.open(request, timeout=20) as response:
            require(response.status == 200 and response.headers.get_content_type() == 'application/json', 'invalid_jwks_response')
            body = response.read(65537)
            require(len(body) <= 65536, 'oversized_jwks')
        result = json.loads(body, object_pairs_hook=unique)
        require(isinstance(result, dict), 'invalid_jwks_response')
        return result
    except (OSError, ValueError, UnicodeError, urllib.error.URLError) as error:
        raise ValueError('jwks_fetch_failed') from error


def verify(token, jwks, *, audience, repository, repository_id, sha, now):
    """Validate a signature and claims; supplied keys must already be trusted."""
    from cryptography.hazmat.primitives import hashes
    from cryptography.hazmat.primitives.asymmetric import padding, rsa
    require(isinstance(token, str) and len(token) <= MAX_TOKEN, 'invalid_token')
    parts = token.split('.')
    require(len(parts) == 3, 'invalid_token')
    header, claims = document(parts[0]), document(parts[1])
    require(set(header) <= {'alg', 'kid', 'typ'} and header.get('alg') == 'RS256'
            and isinstance(header.get('kid'), str) and header.get('typ', 'JWT') == 'JWT', 'invalid_header')
    keys = jwks.get('keys') if isinstance(jwks, dict) else None
    require(isinstance(keys, list) and len(keys) <= 32, 'invalid_jwks')
    matches = [key for key in keys if isinstance(key, dict) and key.get('kid') == header['kid']]
    require(len(matches) == 1, 'untrusted_key')
    key = matches[0]
    require(key.get('kty') == 'RSA' and key.get('alg') == 'RS256' and key.get('use') == 'sig', 'untrusted_key')
    n, e = int.from_bytes(decode(key.get('n')), 'big'), int.from_bytes(decode(key.get('e')), 'big')
    require(2048 <= n.bit_length() <= 4096 and e == 65537, 'weak_key')
    try:
        rsa.RSAPublicNumbers(e, n).public_key().verify(decode(parts[2]), (parts[0] + '.' + parts[1]).encode('ascii'),
                                                        padding.PKCS1v15(), hashes.SHA256())
    except Exception as error:
        raise ValueError('invalid_signature') from error
    require(isinstance(audience, str) and re.fullmatch(r'urn:fokus:publication:[a-f0-9]{64}', audience), 'invalid_challenge')
    require(isinstance(repository, str) and re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repository), 'invalid_repository')
    require(isinstance(repository_id, str) and re.fullmatch(r'[1-9][0-9]*', repository_id), 'invalid_repository_id')
    require(isinstance(sha, str) and re.fullmatch(r'[a-f0-9]{40}', sha), 'invalid_sha')
    expected = {'iss': ISSUER, 'aud': audience, 'repository': repository, 'repository_id': repository_id,
                'sha': sha, 'workflow_sha': sha, 'ref': 'refs/heads/main', 'event_name': 'workflow_dispatch',
                'environment': 'production', 'workflow_ref': repository + '/.github/workflows/deploy.yml@refs/heads/main',
                'sub': 'repo:' + repository + ':environment:production'}
    require(all(claims.get(key) == value for key, value in expected.items()), 'claim_mismatch')
    for key in ('run_id', 'run_attempt', 'check_run_id'):
        require(isinstance(claims.get(key), str) and re.fullmatch(r'[1-9][0-9]*', claims[key]), 'invalid_run_identity')
    require(isinstance(claims.get('jti'), str) and re.fullmatch(r'[A-Za-z0-9_-]{16,128}', claims['jti']), 'invalid_jti')
    for key in ('iat', 'nbf', 'exp'):
        require(type(claims.get(key)) is int, 'invalid_time')
    require(type(now) is int and claims['iat'] <= now and claims['nbf'] <= now < claims['exp']
            and claims['exp'] - claims['iat'] <= 300 and claims['iat'] >= now - 300, 'expired_or_future_token')
    return {'jti': claims['jti'], 'run_id': claims['run_id'], 'run_attempt': claims['run_attempt'],
            'check_run_id': claims['check_run_id'], 'sha': sha}
