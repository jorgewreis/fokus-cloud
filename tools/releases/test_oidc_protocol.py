#!/usr/bin/env python3
"""Protocol contract tests with no network, tokens, or activation."""
import json
import os
import socket
import time
import subprocess
import threading
import tempfile
from contextlib import ExitStack
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

import oidc_protocol as protocol


class Protocol(unittest.TestCase):
    def setUp(self):
        self.request = {'operation':'challenge', 'action':'activate', 'candidate':'a'*40, 'artifact':'b'*64}
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.slot_dir=Path(self.temp.name)/'slots';self.slot_dir.mkdir(mode=0o700)
        for index in range(protocol.protocol_slots.SLOTS):
            (self.slot_dir/('slot-'+str(index)+'.lock')).touch(mode=0o600)
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
    def test_complete_frame_does_not_wait_for_eof(self):
        payload=json.dumps(self.request).encode()
        process=subprocess.Popen([sys.executable,str(Path(protocol.__file__)),'--state-dir','/synthetic','--slot-dir',str(self.slot_dir)],
                                 stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        try:
            process.stdin.write(len(payload).to_bytes(4,'big')+payload)
            process.stdin.flush()
            self.assertNotEqual(process.wait(timeout=3),0)
        finally:
            if process.poll() is None:
                process.kill();process.wait()
            process.stdin.close();process.stdout.close();process.stderr.close()

    def test_partial_frame_without_eof_expires_with_absolute_deadline(self):
        reader,writer=socket.socketpair()
        try:
            writer.sendall((20).to_bytes(4,'big')+b'{')
            start=time.monotonic()
            with patch.object(protocol,'REQUEST_TIMEOUT_SECONDS',.15):
                with self.assertRaisesRegex(ValueError,'request_timeout'):
                    protocol.read_frame(reader.fileno())
            self.assertLess(time.monotonic()-start,1.5)
        finally:
            reader.close();writer.close()

    def test_slow_drip_does_not_extend_deadline(self):
        reader,writer=socket.socketpair()
        def drip():
            try:
                writer.sendall((20).to_bytes(4,'big'))
                for _ in range(5):
                    time.sleep(.07)
                    writer.sendall(b'x')
            except OSError:
                pass
        thread=threading.Thread(target=drip)
        thread.start()
        try:
            start=time.monotonic()
            with patch.object(protocol,'REQUEST_TIMEOUT_SECONDS',.15):
                with self.assertRaisesRegex(ValueError,'request_timeout'):
                    protocol.read_frame(reader.fileno())
            self.assertLess(time.monotonic()-start,1.5)
        finally:
            reader.close();writer.close();thread.join(timeout=2)

    def test_global_deadline_interrupts_work_after_read(self):
        with patch.object(protocol,'TOTAL_TIMEOUT_SECONDS',.15):
            start=time.monotonic()
            with self.assertRaisesRegex(TimeoutError,'operation_timeout'):
                with protocol.total_deadline():
                    time.sleep(2)
            self.assertLess(time.monotonic()-start,1.5)

    def test_global_deadline_terminates_worker_after_frame(self):
        with patch.object(protocol,'TOTAL_TIMEOUT_SECONDS',.15):
            start=time.monotonic()
            with self.assertRaisesRegex(TimeoutError,'operation_timeout'):
                with protocol.total_deadline():
                    protocol.run_supervised(lambda:time.sleep(2))
            self.assertLess(time.monotonic()-start,1.5)

    def test_supervisor_never_returns_worker_exception_text(self):
        def fail(): raise ValueError('CANARY_SECRET_VALUE')
        with self.assertRaisesRegex(ValueError,'worker_failed'):
            with protocol.total_deadline():protocol.run_supervised(fail)

    def test_slot_capacity_is_bounded_and_released(self):
        with ExitStack() as stack:
            for _ in range(protocol.protocol_slots.SLOTS):
                stack.enter_context(protocol.protocol_slots.acquire(self.slot_dir))
            with self.assertRaisesRegex(ValueError,'protocol_capacity_exhausted'):
                with protocol.protocol_slots.acquire(self.slot_dir): pass
        with protocol.protocol_slots.acquire(self.slot_dir): pass

    def test_frame_length_bounds_and_incomplete_payload(self):
        for header,payload in ((0,b''),(protocol.MAX_REQUEST+1,b''),(8,b'bad')):
            reader,writer=socket.socketpair()
            try:
                writer.sendall(header.to_bytes(4,'big')+payload)
                writer.shutdown(socket.SHUT_WR)
                with self.assertRaises(ValueError):protocol.read_frame(reader.fileno())
            finally:
                reader.close();writer.close()

    def test_cli_failure_does_not_echo_sensitive_input(self):
        payload = b'{"token":"CANARY_SECRET_VALUE","bad":true}'
        result = subprocess.run([sys.executable, str(Path(protocol.__file__)), '--state-dir', '/synthetic', '--slot-dir', str(self.slot_dir)],
                                input=len(payload).to_bytes(4,'big')+payload,capture_output=True,timeout=10)
        self.assertNotEqual(result.returncode,0)
        self.assertNotIn(b'CANARY_SECRET_VALUE',result.stdout+result.stderr)


if __name__ == '__main__': unittest.main()
