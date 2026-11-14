#!/usr/bin/env python3
"""Protocol contract tests with no network, tokens, or activation."""
import json
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

import oidc_protocol as protocol


class Protocol(unittest.TestCase):
    def setUp(self):
        self.request = {'operation':'challenge', 'action':'activate', 'candidate':'a'*40, 'artifact':'b'*64}
    def test_challenge_binds_values_and_state_directory_is_not_from_request(self):
        with patch.object(protocol.store, 'challenge', return_value='fixture-audience') as challenge:
            result = protocol.handle('/trusted/state', json.dumps(self.request).encode())
        challenge.assert_called_once_with('/trusted/state', 'activate', 'a'*40, 'b'*64)
        self.assertEqual(result, {'state':'challenge_issued', 'audience':'fixture-audience'})
        with self.assertRaises(ValueError):
            protocol.handle('/trusted/state', json.dumps({**self.request, 'state_dir':'/other'}).encode())
    def test_consume_passes_token_without_returning_it(self):
        request = {**self.request, 'operation':'consume', 'audience':'fixture-audience', 'token':'synthetic-secret'}
        response = {'state':'verified_once','action':'activate','candidate':'a'*40,'artifact':'b'*64,'run_id':'1','run_attempt':'1','jti':'not-returned'}
        with patch.object(protocol.store, 'consume', return_value=response) as consume:
            result = protocol.handle('/trusted/state', json.dumps(request).encode())
        consume.assert_called_once_with('/trusted/state','fixture-audience','synthetic-secret','activate','a'*40,'b'*64)
        self.assertNotIn('synthetic-secret', json.dumps(result))
        self.assertNotIn('jti',result)
    def test_invalid_duplicate_oversized_and_extra_fields_fail(self):
        cases = [b'{"operation":"challenge","operation":"consume"}', b'x'*(protocol.MAX_REQUEST+1),
                 json.dumps({**self.request,'extra':True}).encode(), b'[]']
        for value in cases:
            with self.subTest(value=value[:80]):
                with self.assertRaises(ValueError): protocol.handle('/trusted/state',value)
    def test_cli_failure_does_not_echo_sensitive_input(self):
        payload = b'{"token":"CANARY_SECRET_VALUE","bad":true}'
        result = subprocess.run([sys.executable, str(Path(protocol.__file__)), '--state-dir', '/synthetic'],
                                input=payload,capture_output=True,timeout=10)
        self.assertNotEqual(result.returncode,0)
        self.assertNotIn(b'CANARY_SECRET_VALUE',result.stdout+result.stderr)


if __name__ == '__main__': unittest.main()
