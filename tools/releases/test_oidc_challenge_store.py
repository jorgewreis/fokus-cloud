#!/usr/bin/env python3
"""Disposable Linux challenge store tests with synthetic RSA signatures."""
import json
from pathlib import Path
import tempfile
import threading
import unittest
from unittest.mock import patch

import github_oidc as oidc
import oidc_challenge_store as store
import test_github_oidc as fixture


class ChallengeStore(unittest.TestCase):
    @classmethod
    def setUpClass(cls): fixture.OIDC.setUpClass()
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        (self.base/'authorization.lock').touch(mode=0o600)
        self.sha = 'b'*40
        (self.base/'policy.json').write_text(json.dumps({'repository':'example/project','repository_id':'123','workflow_sha':self.sha}))
        (self.base/'policy.json').chmod(0o600)
        self.candidate, self.artifact = 'c'*40, 'd'*64
        self.fake = fixture.OIDC()
        self.fake.setUp()
        self.jwks = fixture.OIDC.jwks
        keys = patch.object(oidc, 'fetch_jwks', return_value=self.jwks)
        keys.start(); self.addCleanup(keys.stop)
    def new(self): return store.challenge(self.base, 'activate', self.candidate, self.artifact)
    def token(self, audience, jti='fixture_token_1234567890'):
        self.fake.claims.update(aud=audience, jti=jti)
        return self.fake.token()
    def consume(self, audience, token, action='activate', candidate=None, artifact=None):
        return store.consume(self.base, audience, token, action, candidate or self.candidate, artifact or self.artifact)
    def test_protocol_uses_real_store_and_signed_fixture_without_echoing_token(self):
        import oidc_protocol
        request = {'operation':'challenge','action':'activate','candidate':self.candidate,'artifact':self.artifact}
        challenge = oidc_protocol.handle(self.base, json.dumps(request).encode())
        token = self.token(challenge['audience'])
        response = oidc_protocol.handle(self.base, json.dumps({**request,'operation':'consume',
                                          'audience':challenge['audience'],'token':token}).encode())
        self.assertEqual(response['state'],'verified_once')
        self.assertNotIn(token,json.dumps(response))
        with self.assertRaises(FileExistsError):
            oidc_protocol.handle(self.base, json.dumps({**request,'operation':'consume',
                                 'audience':challenge['audience'],'token':token}).encode())

    def test_valid_once_and_same_challenge_cannot_be_reused(self):
        aud=self.new()
        self.assertEqual(self.consume(aud,self.token(aud))['state'], 'verified_once')
        with self.assertRaises(FileExistsError): self.consume(aud,self.token(aud,'different_token_123456'))
    def test_jti_replay_across_challenges_is_rejected(self):
        first, second = self.new(), self.new()
        self.consume(first,self.token(first))
        with self.assertRaises(FileExistsError): self.consume(second,self.token(second))
    def test_wrong_action_artifact_candidate_or_expiry_refused(self):
        aud=self.new()
        for opts in ({'action':'rollback'}, {'candidate':'e'*40}, {'artifact':'f'*64}):
            with self.subTest(opts=opts):
                with self.assertRaisesRegex(ValueError,'challenge_binding'): self.consume(aud,self.token(aud),**opts)
        with patch.object(store.time,'time',return_value=self.fake.now+121):
            with self.assertRaisesRegex(ValueError,'challenge_expired'): self.consume(aud,self.token(aud))
    def test_changed_policy_or_untrusted_jwks_refused(self):
        aud=self.new()
        (self.base/'policy.json').write_text(json.dumps({'repository':'example/project','repository_id':'999','workflow_sha':self.sha}))
        with self.assertRaisesRegex(ValueError,'challenge_binding'): self.consume(aud,self.token(aud))
        (self.base/'policy.json').write_text(json.dumps({'repository':'example/project','repository_id':'123','workflow_sha':self.sha}))
        with patch.object(oidc,'fetch_jwks',return_value={'keys':[]}):
            with self.assertRaisesRegex(ValueError,'untrusted_key'): self.consume(aud,self.token(aud))
    def test_competing_consumers_have_at_most_one_winner(self):
        aud=self.new(); token=self.token(aud); barrier=threading.Barrier(2); outcomes=[]
        def call():
            barrier.wait()
            try: self.consume(aud,token); outcomes.append('verified')
            except (ValueError,OSError): outcomes.append('blocked')
        threads=[threading.Thread(target=call) for _ in range(2)]
        for thread in threads: thread.start()
        for thread in threads: thread.join()
        self.assertCountEqual(outcomes,['verified','blocked'])
    def test_quota_expiry_pruning_and_clock_rollback_refusal(self):
        with patch.object(store, 'MAX_CHALLENGES', 2):
            first = self.new(); self.new()
            with self.assertRaisesRegex(ValueError, 'challenge_quota'): self.new()
            with patch.object(store.time, 'time', return_value=self.fake.now + 421):
                self.new()
                self.assertEqual(len(list(self.base.glob('challenge-*.json'))), 1)
            with self.assertRaisesRegex(ValueError, 'clock_moved_backwards'): self.new()

    def test_expired_jti_marker_is_pruned_only_after_retention(self):
        aud=self.new(); self.consume(aud,self.token(aud))
        self.assertEqual(len(list(self.base.glob('jti-*.json'))),1)
        with patch.object(store.time,'time',return_value=self.fake.now+421):
            self.new()
            self.assertEqual(len(list(self.base.glob('jti-*.json'))),0)

    def test_network_fetch_is_unlocked_and_changed_policy_is_rejected(self):
        aud=self.new(); token=self.token(aud)
        def fetch():
            # This would fail if consume held the same nonblocking flock.
            self.new()
            (self.base/'policy.json').write_text(json.dumps({'repository':'example/project','repository_id':'999','workflow_sha':self.sha}))
            return self.jwks
        with patch.object(oidc,'fetch_jwks',side_effect=fetch):
            with self.assertRaisesRegex(ValueError,'authorization_changed'):
                self.consume(aud,token)
        self.assertFalse(list(self.base.glob('jti-*.json')))

    def test_clock_advance_during_fetch_then_recede_rechecks_iat_and_nbf(self):
        from types import SimpleNamespace
        from unittest.mock import Mock
        for field in ('iat', 'nbf'):
            with self.subTest(field=field):
                aud = self.new()
                nonce = aud.rsplit(':', 1)[-1]
                record = json.loads((self.base / ('challenge-' + nonce + '.json')).read_text())
                start = record['created']
                self.fake.claims.update(iat=start-1, nbf=start-1, exp=start+100)
                self.fake.claims[field] = start+20
                token = self.token(aud)
                clock = SimpleNamespace(time=Mock(side_effect=[start, start+30, start]))
                with patch.object(store, 'time', clock):
                    with self.assertRaisesRegex(ValueError, 'expired_or_future_token'):
                        self.consume(aud, token)
                self.assertFalse(list(self.base.glob('jti-*.json')))
                self.assertFalse(list(self.base.glob('consumed-*.json')))

    def test_unsafe_directory_refused(self):
        self.base.chmod(0o777)
        with self.assertRaises(ValueError): self.new()


if __name__ == '__main__': unittest.main()
