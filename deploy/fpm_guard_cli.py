"""Runner entry point for the existing guard; no destination or port defaults."""
import argparse
import os
import re
import subprocess
import sys

from fpm_guard import GuardError, SSH, activate, preflight


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


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('phase', choices=('preflight', 'activate'))
    args = parser.parse_args(argv)
    try:
        remote = remote_from_environment()
        {'preflight': preflight, 'activate': activate}[args.phase](remote)
    except (GuardError, ValueError, OSError, subprocess.TimeoutExpired):
        # Do not expose destination, credentials or remote output in CI logs.
        print(f'FPM {args.phase} failed; publication must stop.', file=sys.stderr)
        return 1
    print(f'FPM {args.phase} passed.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
