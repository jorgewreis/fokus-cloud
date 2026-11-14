#!/usr/bin/env python3
"""Synthetic cryptographic OIDC contract tests; no GitHub token or network."""
import base64
import json
import time
import unittest
from unittest.mock import patch
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import padding, rsa
import github_oidc as oidc


def enc(data): return base64.urlsafe_b64encode(data).rstrip(b'=').decode()
def obj(value): return enc(json.dumps(value, separators=(',', ':')).encode())
def integer(value): return enc(value.to_bytes((value.bit_length()+7)//8, 'big'))


class OIDC(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        public = cls.key.public_key().public_numbers()
        cls.jwks = {'keys': [{'kid': 'fixture', 'kty': 'RSA', 'alg': 'RS256', 'use': 'sig',
                              'n': integer(public.n), 'e': integer(public.e)}]}
    def setUp(self):
        self.now = int(time.time())
        self.audience = 'urn:fokus:publication:' + 'a'*64
        self.sha = 'b'*40
        self.claims = dict(iss=oidc.ISSUER, aud=self.audience, repository='example/project', repository_id='123',
                           sha=self.sha, workflow_sha=self.sha, ref='refs/heads/main', event_name='workflow_dispatch',
                           environment='production', workflow_ref='example/project/.github/workflows/deploy.yml@refs/heads/main',
                           sub='repo:example/project:environment:production', run_id='12', run_attempt='1', check_run_id='34',
                           jti='fixture_token_1234567890', iat=self.now-1, nbf=self.now-1, exp=self.now+120)
    def token(self, claims=None, header=None, key=None):
        content = obj(header or {'alg':'RS256', 'typ':'JWT', 'kid':'fixture'}) + '.' + obj(claims or self.claims)
        signature = (key or self.key).sign(content.encode(), padding.PKCS1v15(), hashes.SHA256())
        return content + '.' + enc(signature)
    def verify(self, token, jwks=None):
        return oidc.verify(token, jwks or self.jwks, audience=self.audience, repository='example/project',
                           repository_id='123', sha=self.sha, now=self.now)
    def test_jwks_fetch_pins_origin_and_rejects_large_response(self):
        class Headers:
            def get_content_type(self): return 'application/json'
        class Response:
            status = 200
            headers = Headers()
            def __init__(self, body): self.body = body
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def read(self, size): return self.body[:size]
        class Opener:
            def __init__(self, body): self.body = body
            def open(self, request, timeout):
                self_url = request.full_url
                assert self_url == oidc.JWKS_URL and timeout == 20 and request.method == 'GET'
                return Response(self.body)
        with patch.object(oidc.urllib.request, 'build_opener', return_value=Opener(b'{"keys":[]}')):
            self.assertEqual(oidc.fetch_jwks(), {'keys': []})
        with patch.object(oidc.urllib.request, 'build_opener', return_value=Opener(b'x'*65537)):
            with self.assertRaisesRegex(ValueError, 'jwks_fetch_failed'): oidc.fetch_jwks()

    def test_valid_signature_and_exact_claims(self):
        self.assertEqual(self.verify(self.token())['run_id'], '12')
    def test_forged_signature_and_untrusted_key_refused(self):
        forged = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        with self.assertRaisesRegex(ValueError, 'invalid_signature'): self.verify(self.token(key=forged))
        with self.assertRaisesRegex(ValueError, 'untrusted_key'): self.verify(self.token(), {'keys': []})
        with self.assertRaisesRegex(ValueError, 'invalid_header'): self.verify(self.token(header={'alg':'none','kid':'fixture'}))
    def test_rejects_wrong_claims_and_expired_or_long_lived_tokens(self):
        for field, value in [('aud','wrong'), ('repository_id','999'), ('environment','staging'),
                             ('workflow_ref','example/project/.github/workflows/other.yml@refs/heads/main'),
                             ('sha','c'*40), ('event_name','push'), ('exp',self.now-1),
                             ('iat',self.now-301), ('run_attempt','0')]:
            with self.subTest(field=field):
                with self.assertRaises(ValueError): self.verify(self.token({**self.claims, field:value}))
    def test_duplicate_keys_refused_before_signature(self):
        duplicate = enc(b'{"alg":"RS256","alg":"none","kid":"fixture"}') + '.' + obj(self.claims) + '.AA'
        with self.assertRaisesRegex(ValueError, 'invalid_token_json'): self.verify(duplicate)


if __name__ == '__main__': unittest.main()
