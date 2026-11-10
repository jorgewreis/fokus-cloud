"""Runner entry point for the existing guard; no destination or port defaults."""
import argparse
import os
from pathlib import Path
import re
import subprocess
import sys

from fpm_guard import GuardError, SSH, check_unit, preflight, reload_command


def remote_from_environment():
    """Use the same explicit SSH destination as the surrounding deploy steps."""
    host = os.environ.get('DEPLOY_HOST', '')
    user = os.environ.get('DEPLOY_USER', '')
    port = os.environ.get('DEPLOY_PORT', '')
    if not re.fullmatch(r'[A-Za-z0-9_][A-Za-z0-9_.-]*', host):
        raise ValueError('DEPLOY_HOST must be an explicit SSH hostname or IPv4 address')
    if not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_.-]*', user):
        raise ValueError('DEPLOY_USER must be an explicit SSH username')
    if not re.fullmatch(r'[1-9][0-9]{0,4}', port) or int(port) > 65535:
        raise ValueError('DEPLOY_PORT must be an explicit port between 1 and 65535')
    return SSH(f'{user}@{host}', executable=(
        'ssh', '-p', port, '-o', 'ConnectionAttempts=1'))


def selected_policy(environment):
    mode = environment.get('FPM_RELOAD_MODE', '')
    service = environment.get('FPM_SERVICE', '')
    if mode == 'verify-only' and service == '':
        return mode, service
    if mode != 'reload':
        raise ValueError('Explicit runtime mode required')
    reload_command(service)
    return mode, service


def activate(remote, service):
    # Preserve the main runtime script as the actual publication executor. Its
    # canonical sudo argv is checked against reload_command by regression tests.
    check_unit(remote, service)
    remote.run('bash', '-s', '--', 'reload', service,
               stdin=Path(__file__).with_name('reload-runtime.sh').read_text())
    check_unit(remote, service)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('phase', choices=('preflight', 'activate'))
    args = parser.parse_args(argv)
    try:
        mode, service = selected_policy(os.environ)
        if mode == 'verify-only':
            print(f'FPM {args.phase} not requested (verify-only); no unit, sudo or OPcache claim; HTTP identity still required.')
            return 0
        remote = remote_from_environment()
        {'preflight': preflight, 'activate': activate}[args.phase](remote, service)
    except (GuardError, ValueError, OSError, subprocess.TimeoutExpired):
        # Do not expose destination, credentials or remote output in CI logs.
        print(f'FPM {args.phase} failed; publication must stop.', file=sys.stderr)
        return 1
    print(f'FPM {args.phase} passed.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
