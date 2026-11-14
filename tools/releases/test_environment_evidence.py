#!/usr/bin/env python3
"""Synthetic GitHub environment API contracts; no network or real approvals."""
import copy
import unittest
import environment_evidence as evidence
from dataclasses import replace
from github_oidc import VerifiedIdentity


class FakeAPI:
    def __init__(self, data): self.data=data; self.calls=0
    def get(self,path): self.calls+=1; return copy.deepcopy(self.data[path])


class Evidence(unittest.TestCase):
    def setUp(self):
        self.sha='a'*40
        self.identity=VerifiedIdentity('example/project','123',self.sha,'12','1','34','22',1,1,2)
        self.policy={'repository':'example/project','repository_id':'123','environment_id':55,'reviewer_ids':[77]}
        repo={'id':123,'full_name':'example/project'}
        self.data={'/git/ref/heads/main':{'ref':'refs/heads/main','object':{'type':'commit','sha':self.sha}},
          '/actions/workflows/deploy.yml':{'id':99,'path':'.github/workflows/deploy.yml','state':'active'},
          '/environments/production':{'id':55,'name':'production','can_admins_bypass':False,'updated_at':'2026-01-01T00:00:00Z',
              'protection_rules':[{'type':'required_reviewers','prevent_self_review':True,'reviewers':[{'type':'User','reviewer':{'id':77}}]}]},
          '/actions/runs/12':{'id':12,'run_attempt':1,'workflow_id':99,'head_sha':self.sha,'head_branch':'main',
              'event':'workflow_dispatch','status':'in_progress','repository':repo,'head_repository':repo,'actor':{'id':22},
              'check_suite_id':66,'created_at':'2026-01-02T00:00:00Z'},
          '/check-runs/34':{'id':34,'head_sha':self.sha,'name':'Publish Laravel application','status':'in_progress',
              'check_suite':{'id':66},'app':{'slug':'github-actions'}},
          '/actions/runs/12/approvals':[{'state':'approved','user':{'id':77},'environments':[{'id':55,'name':'production'}]}]}
    def check(self): return evidence.check(self.identity,self.policy,FakeAPI(self.data))
    def test_consistent_snapshot_requires_two_read_passes(self):
        api=FakeAPI(self.data)
        self.assertEqual(evidence.check(self.identity,self.policy,api)['state'],'evidence_consistent')
        self.assertEqual(api.calls,12)
    def test_spoofed_dictionary_and_other_repository_are_refused(self):
        with self.assertRaisesRegex(ValueError,'invalid_input'):
            evidence.check({'run_id':'12'},self.policy,FakeAPI(self.data))
        self.identity=replace(self.identity,repository='attacker/project')
        with self.assertRaisesRegex(ValueError,'identity_repository_mismatch'):self.check()

    def test_rerun_is_refused_as_ambiguous(self):
        self.identity=replace(self.identity,run_attempt='2')
        with self.assertRaisesRegex(ValueError,'rerun_approval_ambiguous'):self.check()
    def test_missing_or_unsafe_protection_and_approvals_are_refused(self):
        cases=[('/environments/production','can_admins_bypass',True),
               ('/environments/production','protection_rules',[]),
               ('/environments/production','updated_at','2026-01-03T00:00:00Z'),
               ('/actions/runs/12/approvals',None,[]),
               ('/check-runs/34','status','completed'), ('/actions/runs/12','actor',{'id':23}),
               ('/git/ref/heads/main','object',{'type':'commit','sha':'b'*40})]
        for path,key,value in cases:
            with self.subTest(path=path,key=key):
                original=copy.deepcopy(self.data[path])
                if key is None:self.data[path]=value
                else:self.data[path][key]=value
                with self.assertRaises(ValueError):self.check()
                self.data[path]=original
    def test_self_approval_wrong_reviewer_or_environment_refused(self):
        for change in ({'user':{'id':22}}, {'user':{'id':78}}, {'environments':[{'id':56,'name':'production'}]}):
            with self.subTest(change=change):
                original=copy.deepcopy(self.data['/actions/runs/12/approvals'][0])
                self.data['/actions/runs/12/approvals'][0].update(change)
                with self.assertRaises(ValueError):self.check()
                self.data['/actions/runs/12/approvals'][0]=original
    def test_evidence_change_during_read_is_refused(self):
        class Changing(FakeAPI):
            def get(self,path):
                result=super().get(path)
                if self.calls>6 and path=='/git/ref/heads/main':result['object']['sha']='b'*40
                return result
        with self.assertRaisesRegex(ValueError,'github_evidence_changed'):
            evidence.check(self.identity,self.policy,Changing(self.data))


if __name__=='__main__':unittest.main()
