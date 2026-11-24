"""Contract for the manual read-only production diagnostic workflow.

No existing test enumerates an allowlist of workflow files. deploy.yml and
application-quality.yml stay pinned elsewhere and are not modified here.
"""
from pathlib import Path
import re
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
DIAGNOSTIC = ROOT / '.github/workflows/server-readonly-diagnostics.yml'
DEPLOY = ROOT / '.github/workflows/deploy.yml'
SSH_STEP = '      - name: Configure SSH\n'


def step(text, name):
    marker = '      - name: ' + name + '\n'
    start = text.index(marker)
    rest = text[start + len(marker):]
    following = rest.find('\n      - name: ')
    return text[start:start + len(marker) + (len(rest) if following < 0 else following)]


def remote_script(text):
    start = text.index("<<'REMOTE'\n") + len("<<'REMOTE'\n")
    end = text.index('\n          REMOTE\n', start)
    lines = []
    for line in text[start:end].splitlines():
        if line:
            if not line.startswith('          '):
                raise AssertionError('remote script lost its workflow indentation')
            line = line[10:]
        lines.append(line)
    return '\n'.join(lines) + '\n'


class ReadOnlyDiagnosticWorkflow(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = DIAGNOSTIC.read_text(encoding='utf-8')
        cls.deploy = DEPLOY.read_text(encoding='utf-8')
        cls.remote = remote_script(cls.workflow)

    def test_reuses_the_deploy_ssh_setup_and_production_environment(self):
        self.assertEqual(step(self.deploy, 'Configure SSH'), step(self.workflow, 'Configure SSH'))
        self.assertEqual(self.workflow.count('environment:'), 1)
        self.assertIn('\n    environment: production\n', self.workflow)
        self.assertIn('\n"on":\n  workflow_dispatch:\n', self.workflow)
        for trigger in ('push:', 'pull_request:', 'schedule:', 'workflow_call:', 'workflow_run:', 'repository_dispatch:'):
            self.assertNotRegex(self.workflow, r'(?m)^' + re.escape(trigger))
        self.assertEqual(set(re.findall(r'secrets\.([A-Z0-9_]+)', self.workflow)),
                         {'DEPLOY_HOST', 'DEPLOY_PORT', 'DEPLOY_USER', 'DEPLOY_SSH_KEY', 'DEPLOY_KNOWN_HOSTS'})
        self.assertEqual(re.findall(r'vars\.([A-Z0-9_]+)', self.workflow), [])
        self.assertNotIn('production-deploy', self.workflow)
        self.assertNotIn('contents: write', self.workflow)
        self.assertIn('contents: read', self.workflow)
        self.assertNotIn('StrictHostKeyChecking=no', self.workflow)
        probe = 'ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=20 -o ConnectionAttempts=1 -p "$DEPLOY_PORT" "$DEPLOY_USER@$DEPLOY_HOST"'
        self.assertEqual(self.workflow.count(probe), 2)

    def test_remote_commands_are_read_only_and_do_not_dump_env(self):
        script = self.remote
        self.assertIn("printf '%s\\n' '==== BASE ===='", script)
        self.assertIn("printf '%s\\n' '==== FIM ===='", script)
        self.assertLess(script.index('==== BASE ===='), script.index('==== FIM ===='))
        for command in (
            'BASE=/home/admin-jorge/htdocs/fokuscloud-releases',
            'run realpath "$BASE"',
            "run stat -c 'type=%F mode=%a owner=%U group=%G path=%n' \"$BASE\"",
            'run readlink /home/admin-jorge/htdocs/fokuscloud',
            'run readlink "$BASE/current"',
            'run realpath "$BASE/current"',
            'run ls -la "$BASE/releases"',
            "run stat -c 'type=%F mode=%a owner=%U group=%G path=%n' \"$BASE/shared/.env\"",
            "run stat -c 'type=%F mode=%a owner=%U group=%G path=%n' \"$BASE/shared/storage\"",
            "run stat -c 'type=%F mode=%a owner=%U group=%G path=%n' \"$BASE/shared/storage/app\"",
            "run stat -c 'type=%F mode=%a owner=%U group=%G path=%n' \"$BASE/shared/storage/app/public\"",
            "run grep -nE 'root |SCRIPT_FILENAME|realpath_root|pagespeed|X-Page-Speed' \"$vhost\"",
            "nginx -V 2>&1 </dev/null | tr ' ' '\\n' | grep -i page || echo FAILED",
            'run grep -n pagespeed /etc/nginx/nginx.conf',
            'run grep -n pagespeed /etc/nginx/conf.d/*.conf',
            "run grep -nE 'listen|opcache' \"$pool\"",
            "run grep -nE 'opcache.enable|preload' \"$ini\"",
            "run grep -nE 'opcache.enable|preload' \"$phpconf\"/*.conf",
            'run systemctl show php8.5-fpm -p ActiveState -p SubState -p UnitFileState',
            'run systemctl show php8.5-fpm.service --no-pager --property=Id,LoadState,ActiveState,CanReload',
            'run sudo -n -l',
            'run sudo -n -ll',
            'run sudo -V | head -1 || echo FAILED',
            'database/migrations/2026_10_30_160000_add_encrypted_details_to_law_contacts.php',
            'LOG_CHANNEL=stderr timeout 25 php artisan migrate:status --no-ansi --no-interaction --path="$rel"',
        ):
            self.assertIn(command, script)
        for line in script.splitlines():
            if 'prepublish-law-schema.php' in line:
                self.assertTrue(line.lstrip().startswith('#'), line)
        self.assertNotIn('artisan migrate ', script)
        self.assertNotIn('artisan migrate\n', script)
        version_probe = 'run sudo -V | head -1 || echo FAILED'
        self.assertEqual(script.count(version_probe), 1)
        scanned = script.replace(version_probe, 'run sudo -V || echo FAILED', 1)
        for pattern in (
            r'\brsync\b', r'\bscp\b', r'\bcurl\b', r'\bwget\b', r'\bmysql\b', r'\bmariadb\b',
            r'\bmysqldump\b', r'\bchmod\b', r'\bchown\b', r'\bmkdir\b', r'\brm\b', r'\bmv\b',
            r'\bcp\b', r'\bln\b', r'\btee\b', r'\bdd\b', r'\btouch\b', r'\btruncate\b',
            r'\bsed\b', r'\bperl\b', r'\bpython\b', r'\bgit\b', r'\bcomposer\b', r'\bnpm\b',
            r'\breload\b', r'\brestart\b', r'\bpurge\b', r'\bcat\b', r'\bhead\b', r'\btail\b',
            r'\bprintenv\b', r'\bbase64\b', r'migrate:fresh', r'migrate:rollback', r'migrate:reset',
            r'>>', r'\bsource\b',
        ):
            self.assertIsNone(re.search(pattern, scanned), pattern)
        writable = script.replace('2>&1', '').replace('</dev/null', '').replace('2>/dev/null', '')
        self.assertNotIn('>', writable)
        self.assertNotIn('<', writable)
        self.assertEqual(script.count('.env'), 1)
        self.assertIn('run() { "$@" </dev/null || echo FAILED; }', script)
        self.assertIn('set +e', script)

    def test_status_filter_drops_secret_lines_and_keeps_the_migration_row(self):
        pattern = re.search(r"grep -E '([^']*Migration name[^']*)'", self.remote).group(1)
        sample = '\n'.join((
            'DB_PASSWORD=supersecret APP_KEY=base64:secret',
            '  Migration name .............................................. Batch / Status',
            '  2026_10_30_160000_add_encrypted_details_to_law_contacts  Pending',
            'SQLSTATE connection password=supersecret',
        )) + '\n'
        result = subprocess.run(['grep', '-E', pattern], input=sample, capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0)
        self.assertNotIn('supersecret', result.stdout)
        self.assertIn('2026_10_30_160000_add_encrypted_details_to_law_contacts', result.stdout)
        self.assertIn('Migration name', result.stdout)

    def test_remote_script_reaches_the_end_when_probes_fail(self):
        syntax = subprocess.run(['sh', '-n'], input=self.remote, capture_output=True, text=True, check=False)
        self.assertEqual(syntax.returncode, 0, syntax.stderr)
        result = subprocess.run(['sh'], input=self.remote, capture_output=True, text=True, check=False, timeout=40)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertLess(result.stdout.index('==== BASE ===='), result.stdout.index('==== FIM ===='))
        self.assertIn('law_migration_file=absent', result.stdout)
        self.assertIn('FAILED', result.stdout)
        self.assertNotIn('supersecret', result.stdout)
        self.assertNotIn('APP_KEY=', result.stdout)
        self.assertNotIn('DB_PASSWORD', result.stdout)


if __name__ == '__main__':
    unittest.main()
