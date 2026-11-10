"""Executable SSH/SCP/rsync/curl doubles for actual workflow-shell tests, no network."""
import json
import os
from pathlib import Path
import runpy
import shlex
import subprocess
import urllib.parse
import sys

state_path = Path(os.environ['FPM_WORKFLOW_STATE'])
state = json.loads(state_path.read_text())
program = os.environ['FPM_FAKE_PROGRAM']
args = sys.argv[1:]
if program == 'python3':
    if args[0] == 'tools/ci/verify-deployment.py':
        assert args[1:] == ['verify']
        state['events'].append(['http-verify'])
        state_path.write_text(json.dumps(state))
        module = runpy.run_path(args[0], run_name='fixture_verify')
        def fetch(url, timeout):
            if url.endswith('/up'):
                return 200, {}, b'healthy'
            nonce = urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['deploy_probe'][0]
            release = 'b' * 40 if state['scenario'] == 'http_old_sha' else os.environ['EXPECTED_RELEASE']
            headers = {'content-type': 'application/json', 'cache-control': 'no-store'}
            if state['scenario'] == 'http_cached': headers['cache-control'] = 'public, max-age=600'
            return 200, headers, json.dumps({'status': 'ok', 'release': release, 'probe': nonce}).encode()
        module['verify'].__kwdefaults__.update(fetcher=fetch, sleeper=lambda seconds: None)
        sys.exit(module['main'](['verify']))
    if args[0] == 'tools/ci/summarize-deployment.py':
        state['events'].append(['summary'])
        state_path.write_text(json.dumps(state))
    os.execv(sys.executable, [sys.executable, *args])
if program == 'sudo':
    assert args == ['-n', '--', '/usr/bin/systemctl', 'reload', '--', state['service']]
    state['events'].append(['sudo', *args])
    state_path.write_text(json.dumps(state))
    sys.exit(1 if state['scenario'] == 'reload_denied' else 0)
if program == 'ssh' and args[-1].startswith('LC_ALL=C '):
    assert args[:-1] == [
        '-p', '2222', '-o', 'ConnectionAttempts=1', '-o', 'BatchMode=yes',
        '-o', 'ConnectTimeout=15', '-o', 'StrictHostKeyChecking=yes',
        'fixture@fixture.invalid']
    command = shlex.split(args[-1])
    if command[1:4] == ['bash', '-s', '--']:
        assert command == ['LC_ALL=C', 'bash', '-s', '--', 'reload', state['service']]
        script = sys.stdin.read()
        assert script == Path('deploy/reload-runtime.sh').read_text()
        state['events'].append(['runtime-script', state['service']])
        state_path.write_text(json.dumps(state))
        result = subprocess.run(['bash', '-s', '--', 'reload', state['service']], input=script, text=True)
        sys.exit(result.returncode)
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
