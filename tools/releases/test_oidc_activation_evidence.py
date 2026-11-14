#!/usr/bin/env python3
"""Contract tests for proof-only activation evidence; no mutation or network."""
from contextlib import contextmanager
import importlib.util
from pathlib import Path
import sys
import types
import unittest
from unittest.mock import Mock, patch

MODULE=Path(__file__).with_name('oidc_activation_evidence.py')
gate=types.ModuleType('generation_activation')
gate.protected=Mock();gate.check=Mock();gate.runtime_gate_policy=Mock()
gate.g=types.SimpleNamespace(require=lambda condition,reason: None if condition else (_ for _ in ()).throw(ValueError(reason)))
flow=types.ModuleType('oidc_evidence_flow');flow.assess=Mock()
with patch.dict(sys.modules,{'generation_activation':gate,'oidc_evidence_flow':flow}):
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
        self.c=Contract();flow.assess.reset_mock();gate.check.reset_mock();gate.runtime_gate_policy.reset_mock()
        def assessment(*args):
            self.assertEqual(self.c.held,['activation.lock','scheduler.lock'])
            self.assertEqual(args[0],Path('/synthetic/control/authorization'))
            self.assertEqual(args[-1],self.c.artifact)
            return {'state':'evidence_consistent_not_authorized'}
        flow.assess.side_effect=assessment
    def inspect(self,rollback=False):
        return module.inspect(self.c,Path('/synthetic/control'),self.c.root,Path('/synthetic/receipt'),
            Path('/synthetic/policy'),self.c.previous,Path('/synthetic/previous-receipt'),Path('/synthetic/boundary'),
            audience='synthetic',oidc_token='synthetic',read_token='synthetic',rollback=rollback)
    def test_server_receipt_supplies_digest_under_both_locks(self):
        self.assertEqual(self.inspect()['state'],'evidence_consistent_not_authorized')
        self.assertEqual(self.c.current,self.c.previous)
        self.assertEqual(gate.check.call_count,2);self.assertEqual(gate.runtime_gate_policy.call_count,2)
    def test_bad_receipt_never_calls_evidence(self):
        self.c.artifact='bad'
        with self.assertRaisesRegex(ValueError,'sealed_artifact_missing'):self.inspect()
        flow.assess.assert_not_called()
    def test_previous_changes_during_evidence_refuses(self):
        def change(*args):
            self.c.current=self.c.root
            return {'state':'evidence_consistent_not_authorized'}
        flow.assess.side_effect=change
        with self.assertRaisesRegex(ValueError,'previous_changed'):self.inspect()
    def test_receipt_digest_changes_during_evidence_refuses(self):
        def change(*args):
            self.c.artifact='d'*64
            return {'state':'evidence_consistent_not_authorized'}
        flow.assess.side_effect=change
        with self.assertRaisesRegex(ValueError,'receipt_changed'):self.inspect()
    def test_explicit_rollback_bound_to_rollback_action(self):
        self.inspect(rollback=True)
        self.assertEqual(flow.assess.call_args.args[-3],'rollback')


if __name__=='__main__':unittest.main()
