"""Fail-closed FPM primitives; runner entry point lives in fpm_guard_cli.py."""
import re
import shlex
import subprocess

SERVICE_PATTERN = r'(?:clp-php-fpm|php(?:[0-9]+\.[0-9]+)?-fpm)\.service'
SYSTEMCTL = '/usr/bin/systemctl'

def reload_command(service):
    if not isinstance(service, str) or re.fullmatch(SERVICE_PATTERN, service) is None:
        raise ValueError('Explicit reviewed FPM service required')
    return (SYSTEMCTL, 'reload', '--', service)


class GuardError(RuntimeError):
    pass


class SSH:
    """Runner-side transport; callers must supply an approved host explicitly."""

    def __init__(self, host, executable=('ssh',)):
        if not re.fullmatch(r'[A-Za-z0-9_.@-]+', host) or host.startswith('-'):
            raise ValueError('Invalid SSH destination')
        self.host = host
        self.executable = tuple(executable)

    def run(self, *command, stdin=None):
        result = subprocess.run(
            [*self.executable, '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15',
             '-o', 'StrictHostKeyChecking=yes', self.host,
             'LC_ALL=C ' + shlex.join(command)],
            input=stdin, capture_output=True, text=True, timeout=60, check=False,
        )
        if result.returncode:
            # Do not echo remote output, host or credentials into CI diagnostics.
            raise GuardError(f'Remote command failed ({result.returncode}): {command[0]}')
        return result.stdout.strip()


def check_unit(remote, service):
    reload_command(service)
    output = remote.run(SYSTEMCTL, 'show', service, '--no-pager',
                        '--property=Id,LoadState,ActiveState,CanReload')
    values = {}
    for line in output.splitlines():
        key, separator, value = line.partition('=')
        if not separator or key in values:
            raise GuardError('Malformed unit properties')
        values[key] = value
    if values != {'Id': service, 'LoadState': 'loaded',
                  'ActiveState': 'active', 'CanReload': 'yes'}:
        raise GuardError('Expected selected loaded, active, reloadable FPM unit')


def check_permission(remote, service):
    command = reload_command(service)
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
            or fields.get('Commands') != shlex.join(command)):
        raise GuardError('Exact passwordless root reload permission not established')
    if remote.run('sudo', '-n', '-l', '--', *command) != shlex.join(command):
        raise GuardError('Exact reload command not authorized')


def preflight(remote, service):
    check_unit(remote, service)
    check_permission(remote, service)


def activate(remote, service):
    """Reload once and require the expected unit to remain active."""
    check_unit(remote, service)
    remote.run('sudo', '-n', '--', *reload_command(service))
    check_unit(remote, service)


def run_candidate(remote, service, *, transfer, mutate, verify_application, reopen):
    """Scaffold only: callbacks raise on failure; no rollback/restart fallback.

    Future integration must keep every remote mutation, including maintenance,
    backup and transfer, inside callbacks after preflight. Reopen is unreachable
    after activation failure. Real callbacks are deliberately not supplied here.
    """
    preflight(remote, service)
    transfer()
    mutate()
    activate(remote, service)
    verify_application()
    reopen()
