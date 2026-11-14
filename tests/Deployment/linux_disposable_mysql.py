"""Reject destructive MySQL lab operations without orchestrator identity.

The trusted host/daemon must create the isolated network and plant the file in
the test container. This is not an attestation against a malicious host.
"""
import os
import pathlib
import re
import stat
import subprocess
import tempfile

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
        probe = subprocess.run(['mysql', '--no-defaults', '--protocol=TCP', '-h127.0.0.1', '-P3306', '-uroot', '-Nse', 'SELECT @@server_uuid'],
                               env=env, text=True, capture_output=True, timeout=10, check=True)
        if probe.stdout.strip().lower() != lines[0]:
            raise ValueError('server identity mismatch')
        return lines[0]
    except (OSError, ValueError, subprocess.SubprocessError) as error:
        raise RuntimeError('refuse non-disposable mysql identity') from error


def init_statement(identity):
    if not isinstance(identity, str) or not UUID.fullmatch(identity):
        raise ValueError('invalid MySQL UUID')
    return ("SET @fokus_identity_guard = IF(@@server_uuid = '" + identity
            + "', 1, JSON_EXTRACT('invalid json', '$'))")


def mysql84_command(identity):
    return ['mysql84', '--no-defaults', '--protocol=TCP', '-h127.0.0.1', '-P3306', '-uroot',
            '--skip-reconnect', '--init-command=' + init_statement(identity)]


def mysqldump84_command(identity, name):
    if not isinstance(name, str) or not re.fullmatch(r'fokus_upgrade_[a-f0-9]{16}', name):
        raise ValueError('invalid synthetic database')
    return ['mysql84-mysqldump', '--no-defaults', '--protocol=TCP', '-h127.0.0.1', '-P3306', '-uroot',
            '--init-command=' + init_statement(identity), '--single-transaction', '--hex-blob',
            '--no-tablespaces', name]


def execute_disposable_sql(statement, env):
    identity = assert_disposable_mysql(env)
    subprocess.run(mysql84_command(identity) + ['-e', statement],
                   env=env, check=True, stdout=subprocess.DEVNULL)


def dump_disposable_mysql(name, env, target, errors):
    """Publish a synthetic backup only after the guarded dump succeeds."""
    identity = assert_disposable_mysql(env)
    command = mysqldump84_command(identity, name)
    target = pathlib.Path(target)
    if target.exists() or target.is_symlink():
        raise RuntimeError('refuse existing backup target')
    fd, temporary = tempfile.mkstemp(prefix='.mysql-dump-', dir=target.parent)
    temporary = pathlib.Path(temporary)
    try:
        with os.fdopen(fd, 'wb') as stream:
            subprocess.run(command, env=env, stdout=stream, stderr=errors, check=True)
        temporary.replace(target)
    finally:
        temporary.unlink(missing_ok=True)
