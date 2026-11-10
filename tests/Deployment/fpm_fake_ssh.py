"""Subprocess-only SSH/sudo/systemctl double. Never opens a network connection."""
import json
import pathlib
import shlex
import sys

state_path = pathlib.Path(sys.argv[1])
state = json.loads(state_path.read_text())
scenario = state['scenario']
assert sys.argv[2:-1] == ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15',
                        '-o', 'StrictHostKeyChecking=yes', 'fixture.invalid']
command = shlex.split(sys.argv[-1])
assert command.pop(0) == 'LC_ALL=C'
state['events'].append(command)
state_path.write_text(json.dumps(state))
if scenario == 'ssh_failure':
    sys.exit(255)
service = 'php8.5-fpm.service'
reload_command = ['/usr/bin/systemctl', 'reload', service]
if command[0] == '/usr/bin/systemctl':
    assert command == ['/usr/bin/systemctl', 'show', service, '--no-pager',
                       '--property=Id,LoadState,ActiveState,CanReload']
    count = sum(event[0] == '/usr/bin/systemctl' for event in state['events'])
    values = dict(Id=service, LoadState='loaded', ActiveState='active', CanReload='yes')
    for name, key, value in [('missing', 'LoadState', 'not-found'),
                             ('inactive', 'ActiveState', 'inactive'),
                             ('divergent', 'Id', 'clp-php-fpm.service'),
                             ('no_reload', 'CanReload', 'no')]:
        if scenario == name:
            values[key] = value
    if scenario == 'pre_activation_failure' and count == 2:
        values['ActiveState'] = 'inactive'
    if scenario == 'post_failure' and count == 3:
        sys.exit(1)
    if scenario == 'post_inactive' and count == 3:
        values['ActiveState'] = 'inactive'
    print('\n'.join(f'{key}={value}' for key, value in values.items()))
elif command == ['sudo', '-n', '-ll']:
    if scenario in ('sudo_denied', 'sudo_password'):
        sys.exit(1)
    options = 'authenticate' if scenario == 'sudo_password_policy' else '!authenticate'
    allowed = '/usr/bin/systemctl reload *' if scenario == 'sudo_wildcard' else ' '.join(reload_command)
    output = f'Sudoers entry:\n    RunAsUsers: root\n    Options: {options}\n    Commands:\n        {allowed}'
    if scenario == 'sudo_ambiguous':
        output += '\n' + output
    print(output)
elif command[:4] == ['sudo', '-n', '-l', '--']:
    assert command[4:] == reload_command
    if scenario == 'sudo_exact_denied':
        sys.exit(1)
    print(' '.join(reload_command))
elif command[:3] == ['sudo', '-n', '--']:
    assert command[3:] == reload_command
    if scenario == 'reload_denied':
        sys.exit(1)
else:
    raise AssertionError(command)
