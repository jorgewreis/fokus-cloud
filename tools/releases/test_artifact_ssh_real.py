#!/usr/bin/env python3
"""Disposable CI-only loopback SSH handshake and pinned host-key proof."""
import getpass
import hashlib
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest

import artifact_ssh


class RealSSH(unittest.TestCase):
    def test_pinned_fd_handshake_and_wrong_host_key(self):
        self.assertEqual(os.environ.get('GITHUB_ACTIONS'), 'true', 'CI-only disposable runner required')
        self.assertEqual(os.environ.get('RUNNER_OS'), 'Linux', 'Linux runner required')
        with tempfile.TemporaryDirectory(prefix='fokus-ssh-proof-', dir='/tmp') as directory:
            folder = Path(directory)
            for name in ('host', 'wrong-host', 'client'):
                subprocess.run(['/usr/bin/ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(folder / name)], check=True)
            authorized = folder / 'authorized_keys'
            authorized.write_bytes((folder / 'client.pub').read_bytes())
            authorized.chmod(0o600)
            staging = folder / 'staging'
            staging.mkdir(mode=0o700)
            artifact = folder / 'artifact.tar'
            data = b'synthetic immutable artifact over loopback SSH'
            artifact.write_bytes(data)
            with socket.socket() as listener:
                listener.bind(('127.0.0.1', 0))
                port = listener.getsockname()[1]
            def known_file(name, public_key):
                result = folder / name
                key = (folder / public_key).read_text().split()
                result.write_text(f'[127.0.0.1]:{port} {key[0]} {key[1]}\n')
                result.chmod(0o600)
                return result
            known = known_file('known_hosts', 'host.pub')
            wrong = known_file('wrong_known_hosts', 'wrong-host.pub')
            config = folder / 'sshd_config'
            config.write_text('\n'.join([
                f'Port {port}', 'ListenAddress 127.0.0.1', f'HostKey {folder / "host"}',
                f'PidFile {folder / "sshd.pid"}', f'AuthorizedKeysFile {authorized}',
                'UsePAM yes', 'StrictModes yes', 'PubkeyAuthentication yes',
                'PasswordAuthentication no', 'KbdInteractiveAuthentication no',
                'PermitRootLogin no', 'AllowTcpForwarding no', 'X11Forwarding no',
                'PermitTTY no', 'UseDNS no', 'LogLevel ERROR', f'AllowUsers {getpass.getuser()}',
                '']))
            subprocess.run(['sudo', '-n', 'mkdir', '-p', '/run/sshd'], check=True)
            log = folder / 'sshd.log'
            with log.open('wb') as output:
                daemon = subprocess.Popen(['sudo', '-n', '/usr/sbin/sshd', '-D', '-e', '-f', str(config)],
                                          stdout=output, stderr=subprocess.STDOUT, start_new_session=True)
                try:
                    for _ in range(100):
                        if daemon.poll() is not None:
                            self.fail('sshd failed before readiness: ' + log.read_text())
                        try:
                            with socket.create_connection(('127.0.0.1', port), timeout=.1):
                                break
                        except OSError:
                            time.sleep(.05)
                    else:
                        self.fail('sshd loopback fixture did not start')
                    options = dict(artifact=artifact, expected_digest=hashlib.sha256(data).hexdigest(),
                                   host='127.0.0.1', user=getpass.getuser(), port=port, identity=folder / 'client',
                                   remote_python='/usr/bin/python3',
                                   remote_receiver=str(Path(__file__).with_name('artifact_receiver.py').resolve()),
                                   remote_directory=str(staging), remote_name='received.tar')
                    with self.assertRaisesRegex(ValueError, 'transport_failed'):
                        artifact_ssh.send(**options, known_hosts=wrong)
                    self.assertFalse((staging / 'received.tar').exists())
                    diagnostics = []
                    def diagnostic_run(command, **kwargs):
                        kwargs['stderr'] = subprocess.PIPE
                        result = subprocess.run([command[0], '-v', *command[1:]], **kwargs)
                        diagnostics.append(result.stderr.decode(errors='replace'))
                        return result
                    try:
                        result = artifact_ssh.send(**options, known_hosts=known, run=diagnostic_run)
                    except ValueError as error:
                        self.fail('synthetic SSH failure: ' + str(error) + '\n' + ''.join(diagnostics) + '\nsshd: ' + log.read_text())
                    self.assertEqual(result['state'], 'ssh_completed_unverified')
                    self.assertEqual((staging / 'received.tar').read_bytes(), data)
                finally:
                    if daemon.poll() is None:
                        subprocess.run(['sudo', '-n', 'kill', '-TERM', '--', '-' + str(daemon.pid)], check=False)
                    daemon.wait(timeout=10)


if __name__ == '__main__':
    unittest.main()
