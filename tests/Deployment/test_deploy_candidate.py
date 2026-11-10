"""Offline command-level integration. No process, SSH, network or Docker."""
import ast
import dataclasses
import hashlib
import importlib.util
import pathlib
import shlex
import sys
import types
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'deploy'))
import deploy_candidate as plan


class Fake:
    def __init__(self, fail=None, timeout=False, statuses=None):
        self.events, self.pauses = [], []
        self.fail, self.timeout = fail, timeout
        self.statuses = iter(statuses or ['0'])

    def run(self, op):
        self.events.append(op)
        if op.stage == self.fail:
            if self.timeout:
                raise TimeoutError(op.stage)
            return plan.Result(23)
        if op.stage in ('preflight.1', 'activation.1', 'activation.3'):
            self.last_command = shlex.split(op.argv[-1])[1:]
            assert self.last_command == ['/usr/bin/systemctl', 'show', 'php8.5-fpm.service', '--no-pager', '--property=Id,LoadState,ActiveState,CanReload']
            return plan.Result(stdout='Id=php8.5-fpm.service\nLoadState=loaded\nActiveState=active\nCanReload=yes\n')
        if op.stage == 'preflight.2':
            assert shlex.split(op.argv[-1])[1:] == ['sudo', '-n', '-ll']
            return plan.Result(stdout='Sudoers entry:\nRunAsUsers: root\nOptions: !authenticate\nCommands:\n/usr/bin/systemctl reload php8.5-fpm.service\n')
        if op.stage == 'preflight.3':
            assert shlex.split(op.argv[-1])[1:] == ['sudo', '-n', '-l', '--', *plan.fpm_guard.RELOAD]
            return plan.Result(stdout='/usr/bin/systemctl reload php8.5-fpm.service\n')
        if op.stage == 'activation.2':
            assert shlex.split(op.argv[-1])[1:] == ['sudo', '-n', '--', *plan.fpm_guard.RELOAD]
        if op.stage == 'composer.poll':
            return plan.Result(stdout=next(self.statuses, 'PENDING'))
        if op.stage == 'scheduler.verify':
            return plan.Result(stdout='law-datajud')
        return plan.Result()

    def pause(self, seconds):
        self.pauses.append(seconds)


def inputs(**changes):
    values = dict(host='fixture.invalid', user='fixture', port=2222,
                  deploy_path='/srv/fixture', source_root='/work/source',
                  asset_root='/work/assets', run_id='123', attempt='1', purge_zone='a' * 32)
    values.update(changes)
    return plan.Inputs(**values)


class Integration(unittest.TestCase):
    def test_order_and_concrete_commands(self):
        fake = Fake()
        self.assertTrue(plan.Candidate(inputs(), backend=fake).execute())
        self.assertEqual([e.stage for e in fake.events], [
            'preflight.1', 'preflight.2', 'preflight.3', 'schema.checksum',
            'schema.prepare', 'schema.copy', 'schema.prepublish', 'assets.copy',
            'assets.checksum', 'source.mkdir', 'source.sync', 'runtime',
            'composer.start', 'composer.poll', 'composer.log', 'migrations',
            'datajud.configure', 'optimize', 'age-tags', 'datajud.status', 'datajud.probe',
            'scheduler.install', 'scheduler.verify', 'activation.1', 'activation.2',
            'activation.3', 'purge'])
        events = {op.stage: op for op in fake.events}
        self.assertEqual(next(op.stage for op in fake.events if op.mutates), 'schema.prepare')
        self.assertIn('mkdir -m 700 /tmp/fokuscloud-law-schema-123-1', events['schema.prepare'].argv[-1])
        self.assertEqual(events['schema.copy'].argv[-3:], (
            '/work/source/' + plan.MIGRATION, '/work/source/deploy/prepublish-law-schema.php',
            'fixture@fixture.invalid:/tmp/fokuscloud-law-schema-123-1/'))
        sync = events['source.sync'].argv
        self.assertEqual([sync[i + 1] for i, a in enumerate(sync) if a == '--exclude'], list(plan.EXCLUDES))
        self.assertIn('--filter=merge /work/assets/protect.rules', sync)
        self.assertLess(sync.index('--delete-delay'), sync.index('--filter=merge /work/assets/protect.rules'))
        self.assertEqual(sync[-2:], ('/work/source/', 'fixture@fixture.invalid:/srv/fixture/'))
        self.assertEqual(events['assets.copy'].argv[1:4], ('-rlDz', '--relative', '--files-from=/work/assets/files.txt'))
        self.assertIn('composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader', events['composer.start'].argv[-1])
        self.assertIn('/tmp/fokuscloud-composer-123-1.status', events['composer.poll'].argv[-1])
        self.assertEqual(events['datajud.configure'].stdin_ref, 'datajud-api-key')
        self.assertEqual(events['purge'].secret_refs, ('cloudflare-api-token',))
        self.assertNotIn('restart', repr(fake.events))

    def test_failure_and_timeout_at_every_operation_stops_sequence(self):
        baseline = Fake()
        plan.Candidate(inputs(), backend=baseline).execute()
        stages = [e.stage for e in baseline.events]
        for index, stage in enumerate(stages):
            for timeout in (False, True):
                with self.subTest(stage=stage, timeout=timeout):
                    fake = Fake(fail=stage, timeout=timeout)
                    with self.assertRaises((plan.PlanError, TimeoutError)):
                        plan.Candidate(inputs(), backend=fake).execute()
                    self.assertEqual([e.stage for e in fake.events], stages[:index + 1])
                    if stage.startswith('preflight'):
                        self.assertFalse(any(e.mutates for e in fake.events))

    def test_composer_pending_invalid_nonzero_and_exhaustion(self):
        for statuses in ([''], ['garbage'], ['0\n1'], ['-1'], ['256'], ['00'], ['1'], ['PENDING'] * 45):
            with self.subTest(statuses=statuses[:2]):
                fake = Fake(statuses=statuses)
                with self.assertRaises(plan.PlanError):
                    plan.Candidate(inputs(), backend=fake).execute()
                self.assertNotIn('migrations', [op.stage for op in fake.events])
                self.assertLessEqual(len(fake.pauses), 44)
        fake = Fake(statuses=['PENDING', 'PENDING', '0'])
        plan.Candidate(inputs(), backend=fake).execute()
        self.assertEqual(fake.pauses, [2, 2])

    def test_missing_scheduler_and_malformed_backend_result(self):
        for stage, result in [('scheduler.verify', plan.Result(stdout='other-job')),
                              ('schema.prepare', None), ('schema.prepare', plan.Result('0'))]:
            fake = Fake()
            original = fake.run
            def run(op):
                normal = original(op)
                return result if op.stage == stage else normal
            fake.run = run
            with self.assertRaises(plan.PlanError):
                plan.Candidate(inputs(), backend=fake).execute()
            self.assertEqual(fake.events[-1].stage, stage)

    def test_requires_injected_backend_and_validated_inputs(self):
        with self.assertRaises(TypeError):
            plan.Candidate(types.SimpleNamespace(), backend=Fake())
        with self.assertRaises(TypeError):
            plan.Candidate(inputs())
        with self.assertRaises(TypeError):
            plan.Candidate(inputs(), backend=None)
        for key, value in [('host', '-oProxyCommand=x'), ('host', 'a;id'), ('user', 'a@b'),
                           ('port', '22'), ('port', True), ('port', 0), ('run_id', '../1'),
                           ('attempt', '0'), ('purge_zone', 'x'), ('deploy_path', '/'),
                           ('deploy_path', '/a/../b'), ('deploy_path', "/a' ;id"),
                           ('source_root', '/a\nsecret'), ('asset_root', '/a//b')]:
            with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                inputs(**{key: value})
        fake = Fake()
        plan.Candidate(inputs(source_root='/work/source space', asset_root='/work/asset space', purge_zone=None), backend=fake).execute()
        self.assertNotIn('purge', [e.stage for e in fake.events])
        copy = next(e for e in fake.events if e.stage == 'schema.copy')
        self.assertIn('/work/source space/' + plan.MIGRATION, copy.argv)
        sync = next(e for e in fake.events if e.stage == 'source.sync')
        self.assertEqual(shlex.split(sync.argv[sync.argv.index('-e') + 1])[-2:], ['-p', '2222'])

    def test_pause_failure_stops_polling_and_publication(self):
        fake = Fake(statuses=['PENDING'])
        def pause(seconds):
            raise TimeoutError('pause')
        fake.pause = pause
        with self.assertRaises(TimeoutError):
            plan.Candidate(inputs(), backend=fake).execute()
        self.assertEqual(fake.events[-1].stage, 'composer.poll')
        self.assertNotIn('migrations', [e.stage for e in fake.events])

    def test_fixture_identity_and_stage_provenance(self):
        self.assertEqual(hashlib.sha256((ROOT / plan.MIGRATION).read_bytes()).hexdigest(), plan.MIGRATION_HASH)
        for script in ('prepublish-law-schema.php', 'configure-datajud.php', 'install-scheduler.sh'):
            self.assertTrue((ROOT / 'deploy' / script).is_file())
        data = (ROOT / 'tests/Deployment/fixtures/main-c92ef4e-deploy.yml').read_bytes()
        self.assertEqual(hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest(), plan.MAIN_BLOB)
        main = data.decode()
        for command in ['prepublish-law-schema.php', 'composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader',
                        'php artisan migrate --force --no-interaction', 'php deploy/configure-datajud.php',
                        'php artisan optimize', 'php artisan law:sync-contact-age-tags --no-interaction',
                        'php artisan law:datajud-status', 'bash deploy/install-scheduler.sh', 'purge_everything']:
            self.assertIn(command, main)
        source = (ROOT / 'deploy/deploy_candidate.py').read_text()
        for forbidden in ('subprocess', 'urllib', 'requests', 'socket', '__main__'):
            self.assertNotIn(forbidden, source)
        self.assertNotIn('workflow_dispatch', source)


class GateContract(unittest.TestCase):
    def test_gates_are_unconditional_and_failure_reaches_aggregate(self):
        tree = ast.parse((ROOT / 'tests/Deployment/linux-release.py').read_text())
        loops = [n for n in ast.walk(tree) if isinstance(n, ast.For) and isinstance(n.iter, ast.List)
                 and any(isinstance(x, ast.Tuple) and isinstance(x.elts[0], ast.Constant)
                         and x.elts[0].value == 'deploy-guard' for x in n.iter.elts)]
        self.assertEqual(len(loops), 1)
        loop = loops[0]
        items = {x.elts[0].value: x for x in loop.iter.elts}
        self.assertEqual(items['deploy-guard'].elts[1].value, 'test_fpm_guard.py')
        self.assertEqual(items['deploy-integration'].elts[1].value, 'test_deploy_candidate.py')
        self.assertEqual(items['deploy-workflow'].elts[1].value, 'test_fpm_workflow.py')
        self.assertEqual(items['publication-gate'].elts[1].value, 'test_publication_gate.py')
        # All four live directly in the gate loop, not under an optional if.
        self.assertEqual(len(loop.body), 1)
        self.assertIsInstance(loop.body[0], ast.Expr)
        self.assertEqual(ast.unparse(loop.body[0].value.func), 'gates.append')
        parents = {child: parent for parent in ast.walk(tree) for child in ast.iter_child_nodes(parent)}
        ancestor = parents[loop]
        while not isinstance(ancestor, (ast.FunctionDef, ast.Module)):
            self.assertNotIsInstance(ancestor, ast.If)
            ancestor = parents[ancestor]
        aggregates = [n for n in ast.walk(tree) if isinstance(n, ast.Dict)
                      and any(isinstance(k, ast.Constant) and k.value == 'gate_exit_codes' for k in n.keys)]
        self.assertEqual(len(aggregates), 1)
        failure = next(n for n in ast.walk(tree) if isinstance(n, ast.If) and ast.unparse(n.test) == 'any(gates)')
        for failed in ('deploy-guard', 'deploy-integration', 'deploy-workflow', 'publication-gate'):
            for backend_only in (True, False):
                calls = []
                def run(label, command, check=False):
                    calls.append((label, command))
                    return 31 if label == failed else 0
                scope = dict(gates=[], args=types.SimpleNamespace(backend_only=backend_only),
                             test='fake-container', docker=lambda *args: args, run=run, identity='fixture')
                exec(compile(ast.Module(body=[loop], type_ignores=[]), '<gate loop>', 'exec'), scope)
                result = eval(compile(ast.Expression(aggregates[0]), '<aggregate>', 'eval'), scope)
                self.assertFalse(result['passed'])
                self.assertIn(31, result['gate_exit_codes'])
                self.assertTrue({'deploy-guard', 'deploy-integration', 'deploy-workflow', 'publication-gate'} <= {x[0] for x in calls})
                with self.assertRaisesRegex(RuntimeError, 'Linux gate failed'):
                    exec(compile(ast.Module(body=[failure], type_ignores=[]), '<gate failure>', 'exec'), scope)


if __name__ == '__main__':
    unittest.main(verbosity=2)
