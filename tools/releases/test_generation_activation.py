#!/usr/bin/env python3
"""Real process/state-machine regressions; contract/HTTP fixture is NOT runtime proof."""
import json,os,sys,tempfile,time,unittest
from pathlib import Path
from unittest.mock import patch
import generation_supervisor as g
import generation_activation as a

class ContractEntrypoints(unittest.TestCase):
    def test_current_contract_is_pinned_and_both_clis_reject_modification(self):
        import hashlib
        contract = Path(__file__).with_name('immutable.py')
        self.assertEqual(hashlib.sha256(contract.read_bytes()).hexdigest(), g.CONTRACT_DIGEST)
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            altered = base / 'altered.py'
            altered.write_bytes(contract.read_bytes() + b'\n# altered\n')
            common = ['--base', str(base / 'missing'), '--receipt', str(base / 'receipt'),
                      '--policy', str(base / 'policy')]
            commands = [(g.cli, common + ['--root', str(base / 'root'), '--events', str(base / 'events')]),
                        (a.cli, ['activate'] + common)]
            for cli, args in commands:
                with self.subTest(cli=cli.__module__):
                    with patch.object(sys, 'argv', ['entrypoint'] + args + ['--immutable-contract', str(contract)]):
                        with self.assertRaises(FileNotFoundError):
                            cli()  # Correct digest passes contract load and reaches missing fixture input.
                    with patch.object(sys, 'argv', ['entrypoint'] + args + ['--immutable-contract', str(altered)]):
                        with self.assertRaisesRegex(g.Blocked, '^contract_mismatch$'):
                            cli()

class Contract:
    canonical=staticmethod(lambda p:Path(p).resolve())
    lock=staticmethod(g.lock)
    node_fingerprint=staticmethod(lambda:'synthetic-node')
    current_target=staticmethod(lambda b:(b/'current').resolve())
    @staticmethod
    def verify(root,receipt):
        g.require(json.loads(receipt.read_text())['root']==str(root),'receipt_binding')
    @staticmethod
    def policy(path,root,base,receipt):return {'expires_at_epoch':time.time()+60,'rollback_code_compatible':False,'data_restore_required':True}
    @staticmethod
    def run_verifier(p,root):pass

class Gate(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.base=Path(self.tmp.name)
        # Unit fixtures stub only runtime inspection; dedicated tests exercise the real gate.
        runtime=patch.object(a,'runtime_gate_policy',side_effect=lambda _c,p,_root,_receipt:p);runtime.start();self.addCleanup(runtime.stop)
        self.root=self.base/'releases'/('a'*40);self.root.mkdir(parents=True);self.new=self.base/'releases'/('b'*40);self.new.mkdir()
        (self.base/'current').symlink_to(self.root);self.receipt=self.base/'old.json';self.receipt.write_text(json.dumps({'root':str(self.root)}));self.newreceipt=self.base/'new.json';self.newreceipt.write_text(json.dumps({'root':str(self.new)}))
        self.scope=self.base/'scope.json';self.scope.write_text(json.dumps(dict(schema=a.SCHEMA,base=str(self.base),root=str(self.root),receipt_sha256=a.digest(self.receipt),node_sha256=Contract.node_fingerprint(),boot=a.boot(),operator_uid=os.geteuid(),verified_at=time.time(),expires_at=time.time()+240,evidence_reference='synthetic isolated process namespace only',**{k:True for k in a.BOUNDARIES})))
        (self.base/'policy').write_text('{}')
        self.events=self.base/'events';self.events.touch()
    def runjob(self,code='',slot=1,scope=True):
        script=self.base/'job.py';script.write_text("import os,json,time\nfrom pathlib import Path\np=Path(os.environ['FOKUS_GENERATION_EVENTS'])\ndef emit(state):\n with p.open('a') as f:f.write(json.dumps({'run':os.environ['FOKUS_GENERATION_RUN'],'release':os.environ['FOKUS_GENERATION_SHA'],'state':state,'event':'c'*64,'code':0})+'\\n')\nemit('ready')\n"+("emit('scope_checked')\n" if scope else "")+code)
        pid=os.fork()
        if pid==0:
            try:g.supervise(self.base,self.root,[sys.executable,str(script)],self.events,2,{},slot=slot,validate=lambda:Contract.verify(self.root,self.receipt),complete=a.completion(Contract,self.base,self.root,self.receipt,self.scope))
            except BaseException:os._exit(1)
            os._exit(0)
        return pid
    def ready(self):self.assertEqual(os.waitstatus_to_exitcode(os.waitpid(self.runjob(),0)[1]),0)
    def activate(self):return a.activate(Contract,self.base,self.new,self.newreceipt,self.base/'policy',self.root,self.receipt,self.scope)
    def rejected(self):
        with self.assertRaises(Exception):self.activate()
        self.assertEqual((self.base/'current').resolve(),self.root)
    def alter(self,path,**fields):
        p=json.loads(path.read_text());p.update(fields);path.write_text(json.dumps(p))
    def test_distributed_scope_after_local_completion_blocks_handoff(self):
        code=os.waitstatus_to_exitcode(os.waitpid(self.runjob("emit('start');emit('finish');emit('scope_unverified')"),0)[1])
        self.assertEqual(code,1)
        self.assertFalse((self.base/'scheduler-ready.json').exists())
        self.assertEqual((self.base/'current').resolve(),self.root)
    def test_missing_console_lifecycle_blocks_handoff(self):
        code=os.waitstatus_to_exitcode(os.waitpid(self.runjob("emit('start');emit('finish')",scope=False),0)[1])
        self.assertEqual(code,1)
        self.assertFalse((self.base/'scheduler-ready.json').exists())
    def test_duplicate_console_lifecycle_blocks_handoff(self):
        code=os.waitstatus_to_exitcode(os.waitpid(self.runjob("emit('scope_checked')"),0)[1])
        self.assertEqual(code,1)
        self.assertFalse((self.base/'scheduler-ready.json').exists())
    def test_success_and_duplicate(self):
        self.ready();self.assertEqual(self.activate()['state'],'verified')
        with self.assertRaises(Exception):self.activate()
        self.assertEqual(len(list(self.base.glob('consumed-*'))),1)
    def test_missing_proof(self):self.rejected()
    def test_active_descendant_blocks(self):
        pid=self.runjob("if os.fork()==0:\n time.sleep(.15);os._exit(0)\nos._exit(0)")
        for _ in range(100):
            if (self.base/'activation-blocked.json').exists():break
            time.sleep(.002)
        self.rejected();self.assertEqual(os.waitstatus_to_exitcode(os.waitpid(pid,0)[1]),0)
    def test_direct_activation_stops_before_switch_when_runtime_evidence_is_rejected(self):
        self.ready()
        with patch.object(a,'runtime_gate_policy',side_effect=ValueError('runtime_evidence_required')):
            with self.assertRaisesRegex(ValueError,'runtime_evidence_required'):self.activate()
        self.assertEqual((self.base/'current').resolve(),self.root)
        self.assertFalse((self.base/'activation-blocked.json').exists())
        self.assertTrue((self.base/'scheduler-ready.json').exists())

    def test_double_fork_then_accept(self):
        pid=self.runjob("if os.fork()==0:\n os.setsid()\n if os.fork()==0:\n  time.sleep(.1);os._exit(0)\n os._exit(0)\nos._exit(0)")
        self.assertEqual(os.waitstatus_to_exitcode(os.waitpid(pid,0)[1]),0);self.activate()
    def test_expired(self):self.ready();self.alter(self.base/'scheduler-ready.json',expires_at=0);self.rejected()
    def test_wrong_node(self):self.ready();self.alter(self.base/'scheduler-ready.json',node_sha256='other');self.rejected()
    def test_source_binding(self):self.ready();self.alter(self.base/'scheduler-ready.json',source_binding='other');self.rejected()
    def test_missing_slot(self):self.ready();(self.base/'scheduler-slot-1.json').unlink();self.rejected()
    def test_wrong_slot(self):self.ready();self.alter(self.base/'scheduler-slot-1.json',run='other');self.rejected()
    def test_changed_receipt(self):self.ready();self.receipt.write_text(self.receipt.read_text()+' ');self.rejected()
    def test_changed_ledger(self):
        self.ready();next(self.base.glob('handoff-events-*')).write_text('{}');self.rejected()
    def test_unknown_external(self):self.ready();self.alter(self.scope,external_workers_absent_verified=False);self.rejected()
    def test_unknown_activation_journal(self):self.ready();(self.base/'activation-unknown.json').write_text('{"state":"switch_intent"}');self.rejected()
    def test_overlap_cannot_qualify(self):self.assertEqual(os.waitstatus_to_exitcode(os.waitpid(self.runjob("emit('skipped')"),0)[1]),1);self.rejected()
    def test_new_failed_attempt_invalidates(self):
        self.ready();self.events.write_text('');self.assertEqual(os.waitstatus_to_exitcode(os.waitpid(self.runjob('os._exit(3)',2),0)[1]),1)
        self.assertFalse((self.base/'scheduler-ready.json').exists());self.rejected()
    def test_pending_failure_not_rollback_bypass(self):
        self.ready();(self.base/'activation-blocked.json').write_text('{}')
        with patch.object(Contract,'policy',return_value={'expires_at_epoch':time.time()+60,'rollback_code_compatible':True,'data_restore_required':False}):
            with self.assertRaisesRegex(g.Blocked,'pending_failure'):a.activate(Contract,self.base,self.new,self.newreceipt,self.base/'policy',self.root,self.receipt,self.scope,rollback=True)
        self.assertEqual((self.base/'current').resolve(),self.root)
    def test_post_failure_manual_ack(self):
        self.ready()
        with patch.object(Contract,'run_verifier',side_effect=RuntimeError('synthetic failure')):
            with self.assertRaises(RuntimeError):self.activate()
        self.assertEqual((self.base/'current').resolve(),self.new)
        latch=json.loads((self.base/'activation-blocked.json').read_text());journal=self.base/('activation-'+latch['run']+'.json')
        a.acknowledge_current(Contract,self.base,journal,latch['run'],self.newreceipt,self.base/'policy')
        self.assertFalse((self.base/'activation-blocked.json').exists());self.assertFalse((self.base/'scheduler-ready.json').exists())
    def pending_recovery(self):
        self.ready()
        with patch.object(Contract,'run_verifier',side_effect=RuntimeError('synthetic failure')):
            with self.assertRaises(RuntimeError):self.activate()
        latch=self.base/'activation-blocked.json';state=json.loads(latch.read_text())
        return latch,self.base/('activation-'+state['run']+'.json'),state['run']
    def activated_terminal_latch(self):
        self.ready();latch=self.base/'activation-blocked.json';original=Path.unlink
        def fail_latch(path,*args,**kwargs):
            if path==latch:raise OSError('synthetic activation latch unlink failure')
            return original(path,*args,**kwargs)
        with patch.object(Path,'unlink',fail_latch):
            with self.assertRaisesRegex(OSError,'activation latch unlink failure'):self.activate()
        state=json.loads(latch.read_text())
        return latch,self.base/('activation-'+state['run']+'.json'),state['run']
    def test_verified_journal_before_latch_update_is_recoverable(self):
        self.ready();latch=self.base/'activation-blocked.json';original=g.record
        def interrupt_latch(path,value):
            if path==latch and value.get('state')=='verified':raise OSError('synthetic interruption before terminal latch')
            return original(path,value)
        with patch.object(g,'record',side_effect=interrupt_latch):
            with self.assertRaisesRegex(OSError,'terminal latch'):self.activate()
        state=json.loads(latch.read_text());journal=self.base/('activation-'+state['run']+'.json')
        self.assertEqual(state['state'],'switch_intent')
        self.assertEqual(json.loads(journal.read_text())['state'],'verified')
        a.acknowledge_current(Contract,self.base,journal,state['run'],self.newreceipt,self.base/'policy')
        self.assertFalse(latch.exists())
        self.assertEqual(json.loads(journal.read_text())['state'],'manually_verified_current')
    def test_activation_latch_unlink_failure_is_recoverable(self):
        self.ready();latch=self.base/'activation-blocked.json';original=Path.unlink
        def fail_latch(path,*args,**kwargs):
            if path==latch:raise OSError('synthetic activation latch unlink failure')
            return original(path,*args,**kwargs)
        with patch.object(Path,'unlink',fail_latch):
            with self.assertRaisesRegex(OSError,'activation latch unlink failure'):self.activate()
        state=json.loads(latch.read_text());journal=self.base/('activation-'+state['run']+'.json')
        self.assertEqual((self.base/'current').resolve(),self.new)
        self.assertEqual(state['state'],'verified')
        self.assertEqual(json.loads(journal.read_text())['state'],'verified')
        a.acknowledge_current(Contract,self.base,journal,state['run'],self.newreceipt,self.base/'policy')
        self.assertFalse(latch.exists())
    def test_manual_journal_before_latch_update_is_recoverable(self):
        latch,journal,run=self.pending_recovery();original=g.record
        def interrupt_journal(path,value):
            result=original(path,value)
            if path==journal and value.get('state')=='manually_verified_current':raise OSError('synthetic recovery interruption')
            return result
        with patch.object(g,'record',side_effect=interrupt_journal):
            with self.assertRaisesRegex(OSError,'recovery interruption'):
                a.acknowledge_current(Contract,self.base,journal,run,self.newreceipt,self.base/'policy')
        self.assertEqual(json.loads(latch.read_text())['state'],'activation_unverified_manual_recovery_required')
        self.assertEqual(json.loads(journal.read_text())['state'],'manually_verified_current')
        a.acknowledge_current(Contract,self.base,journal,run,self.newreceipt,self.base/'policy')
        self.assertFalse(latch.exists())
        self.assertEqual(json.loads(journal.read_text())['state'],'manually_verified_current')
    def test_successive_terminal_journal_interruptions_are_recoverable(self):
        latch,journal,run=self.activated_terminal_latch();original=g.record
        def interrupt_manual_journal(path,value):
            result=original(path,value)
            if path==journal and value.get('state')=='manually_verified_current':raise OSError('synthetic second interruption')
            return result
        with patch.object(g,'record',side_effect=interrupt_manual_journal):
            with self.assertRaisesRegex(OSError,'second interruption'):
                a.acknowledge_current(Contract,self.base,journal,run,self.newreceipt,self.base/'policy')
        self.assertEqual(json.loads(latch.read_text())['state'],'verified')
        self.assertEqual(json.loads(journal.read_text())['state'],'manually_verified_current')
        a.acknowledge_current(Contract,self.base,journal,run,self.newreceipt,self.base/'policy')
        self.assertFalse(latch.exists())
    def test_terminal_reconciliation_rejects_changed_intent(self):
        latch,journal,run=self.activated_terminal_latch();state=json.loads(latch.read_text())
        state['handoff_sha256']='not-the-consumed-proof';latch.write_text(json.dumps(state))
        with self.assertRaisesRegex(g.Blocked,'recovery_binding'):
            a.acknowledge_current(Contract,self.base,journal,run,self.newreceipt,self.base/'policy')
        self.assertTrue(latch.exists())
        self.assertEqual(json.loads(journal.read_text())['state'],'verified')
    def test_terminal_reconciliation_requires_fresh_identity_verifier(self):
        latch,journal,run=self.activated_terminal_latch()
        with patch.object(Contract,'run_verifier',side_effect=RuntimeError('synthetic identity verification failure')):
            with self.assertRaisesRegex(RuntimeError,'identity verification failure'):
                a.acknowledge_current(Contract,self.base,journal,run,self.newreceipt,self.base/'policy')
        self.assertTrue(latch.exists())
        self.assertEqual(json.loads(latch.read_text())['state'],'verified')
        self.assertEqual(json.loads(journal.read_text())['state'],'verified')
    def test_latch_unlink_failure_is_retryable(self):
        latch,journal,run=self.pending_recovery();original=Path.unlink
        def fail_latch(path,*args,**kwargs):
            if path==latch:raise OSError('synthetic latch unlink failure')
            return original(path,*args,**kwargs)
        with patch.object(Path,'unlink',fail_latch):
            with self.assertRaisesRegex(OSError,'latch unlink failure'):
                a.acknowledge_current(Contract,self.base,journal,run,self.newreceipt,self.base/'policy')
        self.assertEqual(json.loads(latch.read_text())['state'],'manually_verified_current')
        self.assertEqual(json.loads(journal.read_text())['state'],'manually_verified_current')
        a.acknowledge_current(Contract,self.base,journal,run,self.newreceipt,self.base/'policy')
        self.assertFalse(latch.exists())
    def test_interruption_before_pointer(self):
        self.ready();original=a.os.replace
        def replace(src,dst):
            if Path(dst)==self.base/'current':raise RuntimeError('interruption')
            return original(src,dst)
        with patch.object(a.os,'replace',replace):
            with self.assertRaises(RuntimeError):self.activate()
        self.assertEqual((self.base/'current').resolve(),self.root);self.assertTrue((self.base/'activation-blocked.json').exists());self.assertFalse((self.base/'scheduler-ready.json').exists())
    def abrupt(self,after):
        self.ready();pid=os.fork()
        if pid==0:
            original=a.os.replace
            def replace(src,dst):
                if Path(dst)==self.base/'current':
                    if after:original(src,dst)
                    os._exit(77)
                return original(src,dst)
            a.os.replace=replace;self.activate();os._exit(0)
        self.assertEqual(os.waitstatus_to_exitcode(os.waitpid(pid,0)[1]),77)
        self.assertEqual((self.base/'current').resolve(),self.new if after else self.root)
        self.assertTrue((self.base/'activation-blocked.json').exists());self.assertFalse((self.base/'scheduler-ready.json').exists())
        with self.assertRaises(Exception):self.activate()
    def test_abrupt_before_pointer(self):self.abrupt(False)
    def test_abrupt_after_pointer(self):self.abrupt(True)
    def test_mutable_ancestor(self):
        self.ready();self.base.chmod(0o777);self.rejected();self.base.chmod(0o700)
    def test_control_symlink(self):
        self.ready();link=self.base/'scope-link';link.symlink_to(self.scope)
        with self.assertRaisesRegex(g.Blocked,'control_symlink'):a.activate(Contract,self.base,self.new,self.newreceipt,self.base/'policy',self.root,self.receipt,link)
    def test_activation_lock_contention(self):
        self.ready()
        with g.lock(self.base/'activation.lock'):self.rejected()

if __name__=='__main__':unittest.main()
