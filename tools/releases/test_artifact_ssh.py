#!/usr/bin/env python3
"""Network-free SSH adapter tests with a real disposable receiver."""
import hashlib
import os
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
            known_option = next(x for x in command if x.startswith('UserKnownHostsFile='))
            identity_option = next(x for x in command if x.startswith('IdentityFile='))
            known_fd = int(known_option.rsplit('/', 1)[1])
            identity_fd = int(identity_option.rsplit('/', 1)[1])
            self.assertIn('/proc/' + str(os.getpid()) + '/fd/', known_option)
            self.assertIn('/proc/' + str(os.getpid()) + '/fd/', identity_option)
            self.assertEqual(os.pread(known_fd, 32, 0), b'fixture only')
            self.assertEqual(os.pread(identity_fd, 32, 0), b'fixture only')
            self.assertIn('IdentitiesOnly=yes', command)
            self.assertEqual(command[-2], 'synthetic@fixture.invalid')
            remote = shlex.split(command[-1])
            self.assertEqual(remote[0:2], ['/usr/bin/python3', '/opt/fokus/receiver.py'])
            self.assertEqual(remote[2:], ['--directory', str(self.staging), '--name', 'fixture.tar',
                                          '--sha256', self.args['expected_digest'], '--size', str(len(self.data))])
            artifact_receiver.receive(kwargs['stdin'], self.staging, remote[5], remote[7], int(remote[9]))
            return types.SimpleNamespace(returncode=0)
        result = artifact_ssh.send(**self.args, run=fake_run)
        self.assertEqual(result['state'], 'ssh_completed_unverified')
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
        self.assertEqual(result['state'], 'ssh_completed_unverified')
        with self.assertRaisesRegex(ValueError, 'invalid_prepare_options'):
            artifact_ssh.send(**self.args, prepare={'source': '/synthetic/source'}, run=fake_run)

    def test_force_command_zero_is_never_reported_as_received_or_sealed(self):
        # Models an SSH ForceCommand that ignores the intended command and returns zero.
        result = artifact_ssh.send(**self.args, run=lambda *a, **kw: types.SimpleNamespace(returncode=0))
        self.assertEqual(result['state'], 'ssh_completed_unverified')
        self.assertEqual(list(self.staging.iterdir()), [])

    def test_replaced_key_and_known_hosts_do_not_change_open_files(self):
        def swap_then_run(command, **kwargs):
            for path in (self.known, self.key):
                replacement = path.with_name(path.name + '.replacement')
                replacement.write_text('different untrusted fixture')
                replacement.replace(path)
            known_fd = int(next(x for x in command if x.startswith('UserKnownHostsFile=')).rsplit('/', 1)[1])
            identity_fd = int(next(x for x in command if x.startswith('IdentityFile=')).rsplit('/', 1)[1])
            self.assertEqual(os.pread(known_fd, 32, 0), b'fixture only')
            self.assertEqual(os.pread(identity_fd, 32, 0), b'fixture only')
            return types.SimpleNamespace(returncode=0)
        result = artifact_ssh.send(**self.args, run=swap_then_run)
        self.assertEqual(result['state'], 'ssh_completed_unverified')

    def test_writable_ancestor_refuses_before_ssh(self):
        self.base.chmod(0o777)
        with self.assertRaises(ValueError):
            artifact_ssh.send(**self.args, run=lambda *a, **kw: self.fail('must not run'))

    def test_failed_ssh_does_not_report_success(self):
        with self.assertRaisesRegex(ValueError, 'transport_failed'):
            artifact_ssh.send(**self.args, run=lambda *a, **kw: types.SimpleNamespace(returncode=255))
        self.assertEqual(list(self.staging.iterdir()), [])


if __name__ == '__main__':
    unittest.main()
