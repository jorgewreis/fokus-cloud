#!/usr/bin/env python3
"""Send a digest-bound artifact to a preprovisioned receiver over pinned SSH.

The authenticated metadata and receiver installation are provisioned separately.
This tool never runs activation or changes a release pointer.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shlex
import stat
import subprocess
import sys

MAX_ARTIFACT_BYTES = 576 * 1024 * 1024


def require(condition, reason):
    if not condition:
        raise ValueError(reason)


def remote_path(value):
    require(re.fullmatch(r'/[A-Za-z0-9_./-]+', value) is not None and
            all(x not in ('', '.', '..') for x in value[1:].split('/')), 'invalid_remote_path')
    return value


def private_file(value, private=False):
    path = Path(value).absolute()
    require(path == path.resolve() and re.fullmatch(r'/[A-Za-z0-9_./-]+', str(path)) is not None, 'invalid_local_path')
    s = path.lstat()
    require(stat.S_ISREG(s.st_mode) and s.st_nlink == 1 and s.st_uid == os.geteuid(), 'unsafe_input_file')
    require(not s.st_mode & (0o077 if private else 0o022), 'unsafe_input_permissions')
    require(s.st_size > 0, 'empty_input_file')
    return path


def send(*, artifact, expected_digest, host, user, port, known_hosts, identity,
         remote_python, remote_receiver, remote_directory, remote_name, prepare=None, run=subprocess.run):
    require(re.fullmatch(r'[a-f0-9]{64}', expected_digest) is not None, 'invalid_digest')
    require(re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9.-]*', host) is not None, 'invalid_host')
    require(re.fullmatch(r'[A-Za-z_][A-Za-z0-9_-]*', user) is not None, 'invalid_user')
    require(type(port) is int and 1 <= port <= 65535, 'invalid_port')
    require(re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,127}', remote_name) is not None, 'invalid_name')
    remote_python, remote_receiver, remote_directory = map(remote_path, (remote_python, remote_receiver, remote_directory))
    known_hosts, identity = private_file(known_hosts), private_file(identity, private=True)
    artifact = private_file(artifact)
    fd = os.open(artifact, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_size <= MAX_ARTIFACT_BYTES and info.st_size > 0, 'artifact_budget')
        with os.fdopen(fd, 'rb', closefd=False) as stream:
            h = hashlib.sha256()
            for chunk in iter(lambda: stream.read(1024 * 1024), b''):
                h.update(chunk)
            require(h.hexdigest() == expected_digest, 'artifact_digest_mismatch')
            stream.seek(0)
            if prepare is None:
                remote_args = (remote_python, remote_receiver, '--directory', remote_directory,
                               '--name', remote_name, '--sha256', expected_digest, '--size', str(info.st_size))
            else:
                path_fields = ('source', 'candidate', 'evidence', 'base', 'root', 'storage',
                               'configuration', 'receipt', 'transport')
                require(set(prepare) == set(path_fields) | {'runtime_uid', 'runtime_gid'}, 'invalid_prepare_options')
                require(all(type(prepare[k]) is int and prepare[k] > 0 for k in ('runtime_uid', 'runtime_gid')), 'invalid_runtime_identity')
                remote_args = [remote_python, remote_receiver, 'receive-prepare', '--staging-directory', remote_directory,
                               '--artifact-name', remote_name, '--artifact-size', str(info.st_size), '--expected-digest', expected_digest]
                for field in path_fields:
                    remote_args.extend(('--' + field, remote_path(prepare[field])))
                for field in ('runtime_uid', 'runtime_gid'):
                    remote_args.extend(('--' + field.replace('_', '-'), str(prepare[field])))
            remote_command = shlex.join(remote_args)
            command = ['/usr/bin/ssh', '-F', '/dev/null', '-T', '-o', 'BatchMode=yes',
                       '-o', 'StrictHostKeyChecking=yes', '-o', 'GlobalKnownHostsFile=/dev/null',
                       '-o', 'UserKnownHostsFile=' + str(known_hosts), '-o', 'IdentitiesOnly=yes',
                       '-o', 'IdentityFile=' + str(identity), '-o', 'ClearAllForwardings=yes',
                       '-o', 'ConnectTimeout=20', '-o', 'ConnectionAttempts=1',
                       '-p', str(port), '--', user + '@' + host, remote_command]
            result = run(command, stdin=stream, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                         timeout=900, check=False, env={'PATH': '/usr/bin:/bin', 'LC_ALL': 'C'})
            require(result.returncode == 0, 'transport_failed')
    finally:
        os.close(fd)
    return {'state': 'sealed_not_active' if prepare is not None else 'received_not_active', 'sha256': expected_digest, 'size': info.st_size}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('artifact', 'expected-digest', 'host', 'user', 'known-hosts', 'identity',
                 'remote-python', 'remote-receiver', 'remote-directory', 'remote-name'):
        parser.add_argument('--' + name, required=True)
    parser.add_argument('--port', required=True, type=int)
    for field in ('source', 'candidate', 'evidence', 'base', 'root', 'storage', 'configuration', 'receipt', 'transport'):
        parser.add_argument('--prepare-' + field)
    parser.add_argument('--prepare-runtime-uid', type=int)
    parser.add_argument('--prepare-runtime-gid', type=int)
    try:
        options = vars(parser.parse_args())
        prepare = {key.removeprefix('prepare_'): options.pop(key) for key in list(options) if key.startswith('prepare_')}
        if all(value is None for value in prepare.values()):
            prepare = None
        print(json.dumps(send(**options, prepare=prepare), sort_keys=True))
        return 0
    except Exception:
        print(json.dumps({'state': 'blocked', 'reason': 'authenticated_transport_failed'}), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
