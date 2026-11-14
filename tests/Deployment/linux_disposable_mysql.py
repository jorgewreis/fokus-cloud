"""Reject destructive MySQL lab operations without orchestrator identity.

The trusted host/daemon must create the isolated network and plant the file in
the test container. This is not an attestation against a malicious host.
"""
import os
import pathlib
import re
import stat
import subprocess

IDENTITY = pathlib.Path('/run/fokus-release-mysql.identity')
UUID = re.compile(r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\Z')


def assert_disposable_mysql(env):
    if os.name != 'posix' or not hasattr(os, 'geteuid') or os.geteuid() == 0:
        raise RuntimeError('refuse non-disposable mysql process')
    try:
        meta = IDENTITY.lstat()
        if not stat.S_ISREG(meta.st_mode) or meta.st_uid != 0 or meta.st_mode & 0o222:
            raise ValueError('unsafe identity ownership')
        parent = IDENTITY.parent.lstat()
        if not stat.S_ISDIR(parent.st_mode) or parent.st_uid != 0 or parent.st_mode & 0o022:
            raise ValueError('unsafe identity directory')
        lines = IDENTITY.read_text(encoding='ascii').splitlines()
        if len(lines) != 2 or not UUID.fullmatch(lines[0]) or not re.fullmatch(r'net:\[[0-9]+\]', lines[1]):
            raise ValueError('invalid identity')
        if os.readlink('/proc/self/ns/net') != lines[1]:
            raise ValueError('network namespace mismatch')
        if any(env.get(k, '') not in ('', None) for k in ('DB_URL', 'DATABASE_URL', 'DB_SOCKET')):
            raise ValueError('connection override')
        if env.get('DB_HOST') != '127.0.0.1' or env.get('DB_PORT') != '3306' or env.get('DB_USERNAME') != 'root':
            raise ValueError('connection mismatch')
        probe = subprocess.run(['mysql', '--protocol=TCP', '-h127.0.0.1', '-P3306', '-uroot', '-Nse', 'SELECT @@server_uuid'],
                               env=env, text=True, capture_output=True, timeout=10, check=True)
        if probe.stdout.strip().lower() != lines[0]:
            raise ValueError('server identity mismatch')
    except (OSError, ValueError, subprocess.SubprocessError) as error:
        raise RuntimeError('refuse non-disposable mysql identity') from error


def execute_disposable_sql(statement, env):
    assert_disposable_mysql(env)
    subprocess.run(['mysql', '--protocol=TCP', '-h127.0.0.1', '-P3306', '-uroot', '-e', statement],
                   env=env, check=True, stdout=subprocess.DEVNULL)
