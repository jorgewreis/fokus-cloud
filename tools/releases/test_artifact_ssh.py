#!/usr/bin/env python3
"""Network-free SSH adapter tests with a real disposable receiver."""
import hashlib
from pathlib import Path
import shlex
import tempfile
import types
import unittest

import artifact_receiver
import artifact_ssh


class Transport(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.staging = self.base / 'staging'
        self.staging.mkdir(mode=0o700)
        self.data = b'synthetic transport data' * 1024
        self.artifact = self.base / 'artifact'
        self.artifact.write_bytes(self.data)
        self.known = self.base / 'known_hosts'
        self.known.write_text('fixture only')
        self.key = self.base / 'identity'
        self.key.write_text('fixture only')
        self.key.chmod(0o600)
        self.args = dict(artifact=self.artifact, expected_digest=hashlib.sha256(self.data).hexdigest(),
                         host='fixture.invalid', user='synthetic', port=2222, known_hosts=self.known,
                         identity=self.key, remote_python='/usr/bin/python3', remote_receiver='/opt/fokus/receiver.py',
                         remote_directory=str(self.staging), remote_name='fixture.tar')

    def test_sender_provisions_pinned_ssh_and_receiver_verifies_bytes(self):
        def fake_run(command, **kwargs):
            self.assertEqual(command[0:3], ['/usr/bin/ssh', '-F', '/dev/null'])
            self.assertIn('StrictHostKeyChecking=yes', command)
            self.assertIn('UserKnownHostsFile=' + str(self.known), command)
            self.assertIn('IdentityFile=' + str(self.key), command)
            self.assertIn('IdentitiesOnly=yes', command)
            self.assertEqual(command[-2], 'synthetic@fixture.invalid')
            remote = shlex.split(command[-1])
            self.assertEqual(remote[0:2], ['/usr/bin/python3', '/opt/fokus/receiver.py'])
            self.assertEqual(remote[2:], ['--directory', str(self.staging), '--name', 'fixture.tar',
                                          '--sha256', self.args['expected_digest'], '--size', str(len(self.data))])
            artifact_receiver.receive(kwargs['stdin'], self.staging, remote[5], remote[7], int(remote[9]))
            return types.SimpleNamespace(returncode=0)
        result = artifact_ssh.send(**self.args, run=fake_run)
        self.assertEqual(result['state'], 'received_not_active')
        self.assertEqual((self.staging / 'fixture.tar').read_bytes(), self.data)

    def test_bad_digest_or_unsafe_inputs_never_open_transport(self):
        def fail(*args, **kwargs):
            self.fail('transport must not run')
        for update in ({'expected_digest': '0'*64}, {'host': '-oProxyCommand=x'},
                       {'remote_directory': '/tmp/../etc'}, {'remote_name': 'a;touch'}):
            with self.subTest(update=update):
                with self.assertRaises(ValueError):
                    artifact_ssh.send(**{**self.args, **update}, run=fail)
        self.assertEqual(list(self.staging.iterdir()), [])

    def test_prepare_entrypoint_has_explicit_inputs_and_never_activates(self):
        fields = ('source', 'candidate', 'evidence', 'base', 'root', 'storage', 'configuration', 'receipt', 'transport')
        prepare = {name: '/synthetic/' + name for name in fields}
        prepare.update(runtime_uid=12345, runtime_gid=12345)
        def fake_run(command, **kwargs):
            remote = shlex.split(command[-1])
            self.assertEqual(remote[2], 'receive-prepare')
            self.assertNotIn('activate', remote)
            for field in fields:
                self.assertEqual(remote[remote.index('--' + field) + 1], prepare[field])
            self.assertEqual(remote[remote.index('--runtime-uid') + 1], '12345')
            return types.SimpleNamespace(returncode=0)
        result = artifact_ssh.send(**self.args, prepare=prepare, run=fake_run)
        self.assertEqual(result['state'], 'sealed_not_active')
        with self.assertRaisesRegex(ValueError, 'invalid_prepare_options'):
            artifact_ssh.send(**self.args, prepare={'source': '/synthetic/source'}, run=fake_run)

    def test_failed_ssh_does_not_report_success(self):
        with self.assertRaisesRegex(ValueError, 'transport_failed'):
            artifact_ssh.send(**self.args, run=lambda *a, **kw: types.SimpleNamespace(returncode=255))
        self.assertEqual(list(self.staging.iterdir()), [])


if __name__ == '__main__':
    unittest.main()
