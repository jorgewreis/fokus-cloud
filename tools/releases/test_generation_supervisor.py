#!/usr/bin/env python3
"""Real Linux processes and filesystem, synthetic commands. No PHP claims here."""
import importlib.util,json,os,signal,subprocess,sys,tempfile,time,unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('g',Path(__file__).with_name('generation_supervisor.py'));g=importlib.util.module_from_spec(spec);spec.loader.exec_module(g)

class ProbeFixture(unittest.TestCase):
    def test_actual_laravel_setup_copies_exact_non_autoloaded_fixture(self):
        import ast
        import hashlib
        import shutil
        source = Path(__file__).resolve().parents[2]
        fixture = source / 'tests/Fixtures/Generation/GenerationProbe.php.stub'
        self.assertFalse(fixture.with_suffix('').exists())
        self.assertEqual(hashlib.sha256(fixture.read_bytes()).hexdigest(), '556ee9898385d33bf8b2ccaf01fe602176b2fde814f6ae79ba7427764fd42e2c')
        script = source / 'tools/releases/test_generation_laravel.py'
        copies = [node for node in ast.walk(ast.parse(script.read_text()))
                  if isinstance(node, ast.Expr) and isinstance(node.value, ast.Call)
                  and ast.unparse(node.value.func) == 'shutil.copyfile'
                  and 'GenerationProbe.php.stub' in ast.unparse(node)]
        self.assertEqual(len(copies), 1)
        with tempfile.TemporaryDirectory() as directory:
            clone = Path(directory)
            (clone / 'app/Jobs').mkdir(parents=True)
            exec(compile(ast.Module(body=copies, type_ignores=[]), str(script), 'exec'),
                 {'source': source, 'clone': clone, 'shutil': shutil})
            self.assertEqual((clone / 'app/Jobs/GenerationProbe.php').read_bytes(), fixture.read_bytes())

class Processes(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup);self.base=Path(self.temp.name)
        self.root=self.base/'releases'/('a'*40);self.root.mkdir(parents=True);(self.base/'current').symlink_to(self.root)
        self.events=self.base/'events';self.events.touch()
    def child(self,code,timeout=2,slot=1,validation=None):
        script=self.base/'fixture.py'
        script.write_text("import os,json,time,sys\nfrom pathlib import Path\np=Path(os.environ['FOKUS_GENERATION_EVENTS'])\ndef emit(state,event='b'*64,code=0):\n with p.open('a') as f:f.write(json.dumps({'run':os.environ['FOKUS_GENERATION_RUN'],'release':os.environ['FOKUS_GENERATION_SHA'],'state':state,'event':event,'code':code})+'\\n')\nemit('ready')\n"+code)
        pid=os.fork()
        if pid==0:
            try:g.supervise(self.base,self.root,[sys.executable,str(script)],self.events,timeout,{'PATH':os.environ['PATH']},slot=slot,validate=validation)
            except BaseException as e:
                (self.base/'error').write_text(str(e));os._exit(1)
            os._exit(0)
        return pid
    def wait(self,pid): return os.waitstatus_to_exitcode(os.waitpid(pid,0)[1])
    def test_distributed_scope_is_not_a_local_failure(self):
        self.assertEqual(self.wait(self.child("emit('start');emit('finish');emit('scope_unverified')")),0)
    def test_normal(self):self.assertEqual(self.wait(self.child("emit('start');emit('finish')")),0)
    def test_background_drain_and_activation_lock(self):
        pid=self.child("emit('start')\nif os.fork()==0:\n time.sleep(.2);emit('finish');os._exit(0)\nos._exit(0)")
        for _ in range(100):
            if (self.base/'activation-blocked.json').exists():break
            time.sleep(.01)
        with self.assertRaises(BlockingIOError):
            with g.lock(self.base/'scheduler.lock'):pass
        self.assertEqual(self.wait(pid),0)
    def test_double_fork_setsid(self):
        start=time.monotonic();pid=self.child("emit('start')\nif os.fork()==0:\n os.setsid()\n if os.fork()==0:\n  time.sleep(.15);emit('finish');os._exit(0)\n os._exit(0)\nos._exit(0)")
        self.assertEqual(self.wait(pid),0);self.assertGreater(time.monotonic()-start,.14)
    def test_hidden_child_failure_reported(self):
        self.assertEqual(self.wait(self.child("emit('start');emit('finish',code=7)")),1)
        self.assertIn('scheduled_event_failed',(self.base/'error').read_text())
    def test_queue_failure_reported(self):self.assertEqual(self.wait(self.child("emit('queue_failure')")),1)
    def test_missing_terminal(self):self.assertEqual(self.wait(self.child("emit('start')")),1)
    def test_descendant_nonzero(self):self.assertEqual(self.wait(self.child("if os.fork()==0:os._exit(8)\nos._exit(0)")),1)
    def test_timeout_drains_but_latches(self):
        start=time.monotonic();self.assertEqual(self.wait(self.child("time.sleep(.15)",timeout=.03)),1)
        state=json.loads((self.base/'activation-blocked.json').read_bytes());self.assertTrue(state['drained']);self.assertGreater(time.monotonic()-start,.14)
    def test_duplicate_slot_after_success(self):
        self.assertEqual(self.wait(self.child("pass")),0);self.events.write_text('')
        self.assertEqual(self.wait(self.child("pass")),1);self.assertIn('duplicate_scheduler_slot',(self.base/'error').read_text())
    def test_controller_death_stays_unknown(self):
        pid=self.child("time.sleep(.2)")
        for _ in range(100):
            journals=[x for x in self.base.glob('scheduler-*.json') if not x.name.startswith('scheduler-slot-')]
            if journals and json.loads(journals[0].read_bytes()).get('state')=='running':break
            time.sleep(.01)
        os.kill(pid,signal.SIGKILL);self.wait(pid)
        journal=next(self.base.glob('scheduler-'+json.loads((self.base/'activation-blocked.json').read_bytes())['run']+'.json'))
        with self.assertRaises(g.Blocked):g.recover_drained(self.base,journal,json.loads(journal.read_bytes())['run'])
        time.sleep(.25) # Synthetic child exits naturally; never kill production work.
    def test_recovery_requires_drained_and_explicit_run(self):
        self.assertEqual(self.wait(self.child("emit('start');emit('finish',code=4)")),1)
        state=json.loads((self.base/'activation-blocked.json').read_bytes());journal=self.base/('scheduler-'+state['run']+'.json')
        with self.assertRaises(g.Blocked):g.recover_drained(self.base,journal,'wrong')
        g.recover_drained(self.base,journal,state['run']);self.assertFalse((self.base/'activation-blocked.json').exists())
        self.events.write_text('');self.assertEqual(self.wait(self.child('pass',slot=2)),0)
    def test_incomplete_journal_stays_blocked(self):
        (self.base/'activation-blocked.json').write_text('{');journal=self.base/'scheduler-invalid.json';journal.write_text('{')
        with self.assertRaises(Exception):g.recover_drained(self.base,journal,'unknown')
        self.assertEqual(self.wait(self.child('pass')),1)
    def test_integrity_revalidated_after_drain(self):
        target=self.root/'checked';target.write_text('original')
        def validate():g.require(target.read_text()=='original','integrity_changed')
        pid=self.child("Path('checked').write_text('mutated')",validation=validate)
        self.assertEqual(self.wait(pid),1)
        state=json.loads((self.base/'activation-blocked.json').read_bytes());self.assertTrue(state['drained'])
        self.assertIn('integrity_changed',(self.base/'error').read_text())
    def test_prelaunch_validation_prevents_command(self):
        def validate():raise g.Blocked('unverified_runtime')
        self.assertEqual(self.wait(self.child("Path('unexpected').touch()",validation=validate)),1)
        self.assertFalse((self.root/'unexpected').exists());self.assertFalse((self.base/'activation-blocked.json').exists())
    def test_writable_controls_rejected(self):
        self.base.chmod(0o777)
        self.assertEqual(self.wait(self.child('pass')),1)
        self.assertIn('unsafe_control_path',(self.base/'error').read_text())
    def test_observer_missing_or_malformed_blocked(self):
        self.events.write_text('')
        with self.assertRaises(g.Blocked):g.event_result(self.events,'a'*32,'b'*40)
        self.events.write_text('{')
        with self.assertRaises(Exception):g.event_result(self.events,'a'*32,'b'*40)
    def test_interruption_before_journal_stays_unknown(self):
        latch=self.base/'activation-blocked.json';g.record(latch,{'state':'starting'})
        with self.assertRaises(Exception):g.recover_drained(self.base,self.base/'missing.json','unknown')
        self.assertEqual(self.wait(self.child('pass')),1)
    def test_event_inode_replacement_after_ready_refused(self):
        self.assertEqual(self.wait(self.child("old=p.read_bytes();q=p.with_name('replacement');q.write_bytes(old);os.replace(q,p)")),1)
        self.assertIn('events_rebound',(self.base/'error').read_text())
    def test_event_symlink_after_ready_refused(self):
        self.assertEqual(self.wait(self.child("old=p.read_bytes();q=p.with_name('replacement');q.write_bytes(old);p.unlink();p.symlink_to(q)")),1)
        self.assertTrue((self.base/'activation-blocked.json').exists())
    def test_contending_duplicate_refused(self):
        with g.lock(self.base/'scheduler.lock'):
            pid=self.child('pass');self.assertEqual(self.wait(pid),1)

if __name__=='__main__':unittest.main()
