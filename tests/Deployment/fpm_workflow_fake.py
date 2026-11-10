"""Executable SSH/SCP/rsync/curl doubles for actual workflow-shell tests, no network."""
import json
import os
from pathlib import Path
import runpy
import sys

state_path = Path(os.environ['FPM_WORKFLOW_STATE'])
state = json.loads(state_path.read_text())
program = os.environ['FPM_FAKE_PROGRAM']
args = sys.argv[1:]
if program == 'ssh' and args[-1].startswith('LC_ALL=C '):
    assert args[:-1] == [
        '-p', '2222', '-o', 'ConnectionAttempts=1', '-o', 'BatchMode=yes',
        '-o', 'ConnectTimeout=15', '-o', 'StrictHostKeyChecking=yes',
        'fixture@fixture.invalid']
    # Reuse every existing guard scenario after checking the real CLI transport.
    sys.argv = ['fpm_fake_ssh.py', str(state_path), '-o', 'BatchMode=yes',
                '-o', 'ConnectTimeout=15', '-o', 'StrictHostKeyChecking=yes',
                'fixture.invalid', args[-1]]
    runpy.run_path(str(Path(__file__).resolve().with_name('fpm_fake_ssh.py')), run_name='__main__')
    sys.exit(0)
if program == 'ssh':
    assert args[-2] == 'fixture@fixture.invalid'
    assert args[args.index('-p') + 1] == '2222'
    event = ['workflow-ssh', args[-1]]
    # The existing workflow polls a synthetic Composer status via SSH.
    if args[-1].startswith("test -f '/tmp/fokuscloud-composer-install.status'"):
        print('0')
elif program in ('scp', 'rsync'):
    assert args[-1].startswith('fixture@fixture.invalid:/srv/fixture/') or (
        program == 'scp' and args[-1].startswith('fixture@fixture.invalid:/tmp/fokuscloud-law-schema-'))
    event = [program, *args]
elif program == 'curl':
    assert 'https://api.cloudflare.com/client/v4/zones/fixture-zone/purge_cache' in args
    event = ['purge']
else:
    raise AssertionError(program)
state['events'].append(event)
state_path.write_text(json.dumps(state))
if state['scenario'] == 'schema_failure' and program == 'ssh' and 'mkdir -m 700' in args[-1]:
    sys.exit(1)
