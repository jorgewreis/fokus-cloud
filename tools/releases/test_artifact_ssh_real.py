#!/usr/bin/env python3
"""Disposable CI-only loopback SSH handshake and pinned host-key proof."""
import getpass
import hashlib
import json
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
            for name in ('host', 'wrong-host', 'client', 'client-forced'):
                subprocess.run(['/usr/bin/ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(folder / name)], check=True)
            authorized = folder / 'authorized_keys'
            state = folder / 'oidc-state'; state.mkdir(mode=0o700)
            (state/'authorization.lock').touch(mode=0o600)
            (state/'policy.json').write_text(json.dumps({'repository':'example/project','repository_id':'123','workflow_sha':'b'*40}))
            (state/'policy.json').chmod(0o600)
            slots = folder / 'oidc-slots'; slots.mkdir(mode=0o700)
            for index in range(4): (slots/('slot-'+str(index)+'.lock')).touch(mode=0o600)
            protocol = Path(__file__).with_name('oidc_protocol.py').resolve()
            forced = f'/usr/bin/python3 {protocol} --state-dir {state} --slot-dir {slots}'
            authorized.write_bytes((folder/'client.pub').read_bytes() +
                ('restrict,command="' + forced + '" ').encode() + (folder/'client-forced.pub').read_bytes())
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
                # Fixture key path is under /tmp; the real client checks its own paths/ACLs.
                'UsePAM yes', 'StrictModes no', 'PubkeyAuthentication yes',
                'PasswordAuthentication no', 'KbdInteractiveAuthentication no',
                'PermitRootLogin no', 'AllowTcpForwarding no', 'X11Forwarding no',
                'PermitTTY no', 'UseDNS no', 'LogLevel VERBOSE', f'AllowUsers {getpass.getuser()}',
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
                    diagnostics = []
                    def diagnostic_run(command, **kwargs):
                        kwargs['stderr'] = subprocess.PIPE
                        result = subprocess.run([command[0], '-v', *command[1:]], **kwargs)
                        diagnostics.append(result.stderr.decode(errors='replace'))
                        return result
                    with self.assertRaisesRegex(ValueError, 'transport_failed'):
                        artifact_ssh.send(**options, known_hosts=wrong, run=diagnostic_run)
                    self.assertIn('Host key verification failed', diagnostics[-1])
                    self.assertFalse((staging / 'received.tar').exists())
                    diagnostics.clear()
                    try:
                        result = artifact_ssh.send(**options, known_hosts=known, run=diagnostic_run)
                    except ValueError as error:
                        self.fail('synthetic SSH failure: ' + str(error) + '\n' + ''.join(diagnostics) + '\nsshd: ' + log.read_text())
                    self.assertEqual(result['state'], 'ssh_completed_unverified')
                    self.assertEqual((staging / 'received.tar').read_bytes(), data)
                    # A key-restricted command ignores an attacker-supplied command and
                    # handles only the framed, synthetic challenge protocol.
                    sentinel=folder/'should-not-exist'
                    request=json.dumps({'operation':'challenge','action':'activate','candidate':'a'*40,'artifact':'c'*64}).encode()
                    frame=len(request).to_bytes(4,'big')+request
                    command=['/usr/bin/ssh','-F','/dev/null','-T','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes',
                             '-o','GlobalKnownHostsFile=/dev/null','-o','UserKnownHostsFile='+str(known),
                             '-o','IdentitiesOnly=yes','-i',str(folder/'client-forced'),'-p',str(port),
                             getpass.getuser()+'@127.0.0.1','touch '+str(sentinel)]
                    forced_result=subprocess.run(command,input=frame,capture_output=True,timeout=15)
                    self.assertEqual(forced_result.returncode,0,forced_result.stderr.decode(errors='replace'))
                    self.assertEqual(json.loads(forced_result.stdout)['state'],'challenge_issued')
                    self.assertFalse(sentinel.exists())
                finally:
                    if daemon.poll() is None:
                        subprocess.run(['sudo', '-n', 'kill', '-TERM', '--', '-' + str(daemon.pid)], check=False)
                    daemon.wait(timeout=10)


if __name__ == '__main__':
    unittest.main()
