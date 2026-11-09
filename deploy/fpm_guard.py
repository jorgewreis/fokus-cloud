"""Unwired deployment scaffold. No CLI, host, credentials or publication defaults."""
import re
import shlex
import subprocess

SERVICE = 'php8.5-fpm.service'
SYSTEMCTL = '/usr/bin/systemctl'
RELOAD = (SYSTEMCTL, 'reload', SERVICE)


class GuardError(RuntimeError):
    pass


class SSH:
    """Runner-side transport; callers must supply an approved host explicitly."""

    def __init__(self, host, executable=('ssh',)):
        if not re.fullmatch(r'[A-Za-z0-9_.@-]+', host) or host.startswith('-'):
            raise ValueError('Invalid SSH destination')
        self.host = host
        self.executable = tuple(executable)

    def run(self, *command):
        result = subprocess.run(
            [*self.executable, '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15',
             '-o', 'StrictHostKeyChecking=yes', self.host,
             'LC_ALL=C ' + shlex.join(command)],
            capture_output=True, text=True, timeout=60, check=False,
        )
        if result.returncode:
            # Do not echo remote output, host or credentials into CI diagnostics.
            raise GuardError(f'Remote command failed ({result.returncode}): {command[0]}')
        return result.stdout.strip()


def check_unit(remote):
    output = remote.run(SYSTEMCTL, 'show', SERVICE, '--no-pager',
                        '--property=Id,LoadState,ActiveState,CanReload')
    values = {}
    for line in output.splitlines():
        key, separator, value = line.partition('=')
        if not separator or key in values:
            raise GuardError('Malformed unit properties')
        values[key] = value
    if values != {'Id': SERVICE, 'LoadState': 'loaded',
                  'ActiveState': 'active', 'CanReload': 'yes'}:
        raise GuardError('Expected loaded, active, reloadable PHP 8.5 unit')


def check_permission(remote):
    # Listing never performs the reload. Success alone is insufficient: listpw
    # can allow a listing even when the actual command would require a password.
    output = remote.run('sudo', '-n', '-ll')
    entries = output.split('Sudoers entry:')
    if len(entries) != 2:
        raise GuardError('Ambiguous sudo policy listing')
    fields = {}
    current = None
    for line in entries[1].splitlines():
        line = line.strip()
        if not line:
            continue
        match = re.fullmatch(r'(RunAsUsers|RunAsGroups|Options|Commands):\s*(.*)', line)
        if match:
            current, value = match.groups()
            if current in fields:
                raise GuardError('Duplicate sudo field')
            fields[current] = value
        elif current == 'Commands':
            fields[current] += ('\n' if fields[current] else '') + line
        else:
            raise GuardError('Unsupported sudo policy format')
    options = {part.strip() for part in fields.get('Options', '').split(',')}
    if (fields.get('RunAsUsers') != 'root'
            or fields.get('RunAsGroups', '') not in ('', 'root')
            or '!authenticate' not in options or 'authenticate' in options
            or fields.get('Commands') != shlex.join(RELOAD)):
        raise GuardError('Exact passwordless root reload permission not established')
    if remote.run('sudo', '-n', '-l', '--', *RELOAD) != shlex.join(RELOAD):
        raise GuardError('Exact reload command not authorized')


def preflight(remote):
    check_unit(remote)
    check_permission(remote)


def activate(remote):
    """Reload once and require the expected unit to remain active."""
    check_unit(remote)
    remote.run('sudo', '-n', '--', *RELOAD)
    check_unit(remote)


def run_candidate(remote, *, transfer, mutate, verify_application, reopen):
    """Scaffold only: callbacks raise on failure; no rollback/restart fallback.

    Future integration must keep every remote mutation, including maintenance,
    backup and transfer, inside callbacks after preflight. Reopen is unreachable
    after activation failure. Real callbacks are deliberately not supplied here.
    """
    preflight(remote)
    transfer()
    mutate()
    activate(remote)
    verify_application()
    reopen()
