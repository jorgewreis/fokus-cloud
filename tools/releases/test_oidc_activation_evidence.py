#!/usr/bin/env python3
"""Contract tests for proof-only activation evidence; no mutation or network."""
from contextlib import contextmanager, nullcontext, ExitStack
import importlib.util
import os
import signal
import time
import tempfile
from pathlib import Path
import sys
import types
import unittest
from unittest.mock import Mock, patch

MODULE=Path(__file__).with_name('oidc_activation_evidence.py')
gate=types.ModuleType('generation_activation')
gate.protected=Mock();gate.check=Mock();gate.runtime_gate_policy=Mock()
gate.g=types.SimpleNamespace(require=lambda condition,reason: None if condition else (_ for _ in ()).throw(ValueError(reason)))
flow=types.ModuleType('oidc_evidence_flow');flow.assess=Mock();flow.recheck=Mock()
protocol=types.ModuleType('oidc_protocol')
protocol.total_deadline=lambda:nullcontext()
protocol.protocol_slots=types.SimpleNamespace(acquire=lambda _path:nullcontext())
protocol.run_supervised=lambda operation:operation()
with patch.dict(sys.modules,{'generation_activation':gate,'oidc_evidence_flow':flow,'oidc_protocol':protocol}):
    spec=importlib.util.spec_from_file_location('activation_evidence_test',MODULE)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)


class Contract:
    def __init__(self):
        self.held=[];self.root=Path('/synthetic/control/releases')/('a'*40)
        self.previous=Path('/synthetic/control/releases')/('b'*40)
        self.current=self.previous;self.artifact='c'*64
    @staticmethod
    def canonical(path):return Path(path)
    @contextmanager
    def lock(self,path):
        self.held.append(path.name)
        try:yield
        finally:self.held.pop()
    def policy(self,*args):return {'rollback_code_compatible':True,'data_restore_required':False}
    def current_target(self,base):return self.current
    def verify(self,root,receipt):return {'artifact_sha256':self.artifact}


class Evidence(unittest.TestCase):
    def setUp(self):
        self.c=Contract();flow.assess.reset_mock(side_effect=True);flow.recheck.reset_mock(side_effect=True);gate.check.reset_mock();gate.runtime_gate_policy.reset_mock()
        def assessment(*args):
            self.assertEqual(self.c.held,[])
            self.assertEqual(args[0],Path('/synthetic/control/authorization'))
            self.assertEqual(args[-1],self.c.artifact)
            return {'state':'evidence_consistent_not_authorized','candidate':self.c.root.name,'artifact':'c'*64}
        flow.assess.side_effect=assessment
    def inspect(self,rollback=False):
        return module.inspect(self.c,Path('/synthetic/control'),self.c.root,Path('/synthetic/receipt'),
            Path('/synthetic/policy'),self.c.previous,Path('/synthetic/previous-receipt'),Path('/synthetic/boundary'),
            slot_dir='/synthetic/slots',audience='synthetic',oidc_token='synthetic',read_token='synthetic',rollback=rollback)
    def test_server_receipt_is_rechecked_and_network_does_not_hold_locks(self):
        self.assertEqual(self.inspect()['state'],'evidence_consistent_not_authorized')
        self.assertEqual(self.c.current,self.c.previous)
        self.assertEqual(gate.check.call_count,2);self.assertEqual(gate.runtime_gate_policy.call_count,2)
    def test_public_entry_always_uses_process_supervisor(self):
        with patch.object(protocol,'run_supervised',side_effect=lambda operation:operation()) as supervisor:
            self.assertEqual(self.inspect()['state'],'evidence_consistent_not_authorized')
        supervisor.assert_called_once()

    def test_bad_receipt_never_calls_evidence(self):
        self.c.artifact='bad'
        with self.assertRaisesRegex(ValueError,'sealed_artifact_missing'):self.inspect()
        flow.assess.assert_not_called()
    def test_previous_changes_during_evidence_refuses(self):
        def change(*args):
            self.c.current=self.c.root
            return {'state':'evidence_consistent_not_authorized','candidate':self.c.root.name,'artifact':'c'*64}
        flow.assess.side_effect=change
        with self.assertRaisesRegex(ValueError,'previous_changed'):self.inspect()
    def test_receipt_digest_changes_during_evidence_refuses(self):
        def change(*args):
            self.c.artifact='d'*64
            return {'state':'evidence_consistent_not_authorized','candidate':self.c.root.name,'artifact':'c'*64}
        flow.assess.side_effect=change
        with self.assertRaisesRegex(ValueError,'receipt_changed'):self.inspect()
    def test_expired_or_changed_internal_evidence_is_refused(self):
        flow.recheck.side_effect=ValueError('authorization_changed')
        with self.assertRaisesRegex(ValueError,'authorization_changed'):self.inspect()

    def test_network_over_deadline_is_refused_before_reacquisition(self):
        with patch.object(module.time,'monotonic',side_effect=[0,31]):
            with self.assertRaisesRegex(ValueError,'evidence_timeout'):self.inspect()
        flow.recheck.assert_not_called()

    def test_explicit_rollback_bound_to_rollback_action(self):
        self.inspect(rollback=True)
        self.assertEqual(flow.assess.call_args.args[-3],'rollback')


@unittest.skipUnless(hasattr(os,'fork'), 'requires POSIX fork and flock')
class ConcurrentEvidence(unittest.TestCase):
    def test_other_process_can_lock_during_network_and_changed_pointer_is_refused(self):
        import fcntl
        with tempfile.TemporaryDirectory() as temp:
            base=Path(temp);previous=base/'releases'/('b'*40);candidate=base/'releases'/('a'*40)
            state=base/'synthetic-current';state.write_text(str(previous))
            class RealContract(Contract):
                def __init__(self):
                    self.held=[];self.root=candidate;self.previous=previous;self.artifact='c'*64
                @contextmanager
                def lock(self,path):
                    fd=os.open(path,os.O_RDWR|os.O_CREAT,0o600)
                    try:
                        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
                        yield
                    finally:os.close(fd)
                def current_target(self,_base):return Path(state.read_text())
            contract=RealContract()
            def network(*args):
                pid=os.fork()
                if pid==0:
                    try:
                        with contract.lock(base/'activation.lock'):
                            with contract.lock(base/'scheduler.lock'):
                                state.write_text(str(candidate))
                        os._exit(0)
                    except BaseException:os._exit(2)
                _,status=os.waitpid(pid,0)
                self.assertEqual(os.waitstatus_to_exitcode(status),0,'network held activation locks')
                return {'state':'evidence_consistent_not_authorized','candidate':candidate.name,'artifact':contract.artifact}
            with patch.object(flow,'assess',side_effect=network):
                with self.assertRaisesRegex(ValueError,'previous_changed'):
                    module.inspect(contract,base,candidate,base/'receipt',base/'policy',previous,
                                   base/'previous-receipt',base/'boundary',audience='synthetic',
                                   slot_dir='/synthetic/slots',oidc_token='synthetic',read_token='synthetic')
            self.assertFalse((base/'activation-blocked.json').exists())



@unittest.skipUnless(hasattr(os,'fork'), 'requires POSIX process supervision')
class SupervisedEvidence(unittest.TestCase):
    def test_blocked_network_is_killed_and_releases_locks_and_slot(self):
        import fcntl
        import oidc_protocol as real_protocol
        with tempfile.TemporaryDirectory() as temp:
            base=Path(temp);previous=base/'releases'/('b'*40);candidate=base/'releases'/('a'*40)
            slots=base/'slots';slots.mkdir(mode=0o700)
            for index in range(real_protocol.protocol_slots.SLOTS):
                (slots/('slot-'+str(index)+'.lock')).touch(mode=0o600)
            marker=base/'network-entered';outcome=base/'outcome'
            class RealContract(Contract):
                def __init__(self):
                    self.held=[];self.root=candidate;self.previous=previous;self.current=previous;self.artifact='c'*64
                @contextmanager
                def lock(self,path):
                    fd=os.open(path,os.O_RDWR|os.O_CREAT,0o600)
                    try:
                        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB);yield
                    finally:os.close(fd)
            contract=RealContract()
            def blocked(*args):
                marker.write_text('synthetic');time.sleep(10)
                return {'state':'evidence_consistent_not_authorized','candidate':candidate.name,'artifact':contract.artifact}
            with patch.object(module,'protocol',real_protocol), \
                 patch.object(real_protocol,'TOTAL_TIMEOUT_SECONDS',.8), \
                 patch.object(flow,'assess',side_effect=blocked):
                pid=os.fork()
                if pid==0:
                    try:
                        module.inspect(contract,base,candidate,base/'receipt',base/'policy',previous,
                            base/'previous-receipt',base/'boundary',slot_dir=slots,audience='synthetic',
                            oidc_token='synthetic',read_token='synthetic')
                    except (TimeoutError,ValueError):
                        outcome.write_text('blocked');os._exit(0)
                    os._exit(2)
                try:
                    deadline=time.monotonic()+3
                    while not marker.exists() and time.monotonic()<deadline:time.sleep(.01)
                    self.assertTrue(marker.exists(),'synthetic network did not start')
                    with contract.lock(base/'activation.lock'):
                        with contract.lock(base/'scheduler.lock'):pass
                    deadline=time.monotonic()+3;status=None
                    while time.monotonic()<deadline:
                        waited,status=os.waitpid(pid,os.WNOHANG)
                        if waited:break
                        time.sleep(.01)
                    else:
                        os.kill(pid,signal.SIGKILL);os.waitpid(pid,0);self.fail('supervisor did not finish')
                    self.assertEqual(os.waitstatus_to_exitcode(status),0)
                    self.assertEqual(outcome.read_text(),'blocked')
                    with ExitStack() as stack:
                        for _ in range(real_protocol.protocol_slots.SLOTS):
                            stack.enter_context(real_protocol.protocol_slots.acquire(slots))
                    self.assertFalse((base/'activation-blocked.json').exists())
                finally:
                    try:
                        waited,_=os.waitpid(pid,os.WNOHANG)
                        if not waited:os.kill(pid,signal.SIGKILL);os.waitpid(pid,0)
                    except ChildProcessError:pass



if __name__=='__main__':unittest.main()
