#!/usr/bin/env python3
"""Experimental locked activation evidence; never activates or publishes.

All paths and policy are provisioned by trusted server bootstrap. No public
entrypoint is installed. An evidence result is explicitly not authorization.
"""
import re

import generation_activation as gate
import oidc_evidence_flow


def inspect(contract, base, candidate, receipt, policy_file, previous, previous_receipt,
            boundary_file, *, audience, oidc_token, read_token, rollback=False):
    gate.protected(base,True)
    for value in (receipt,policy_file,previous_receipt,boundary_file):gate.protected(value)
    base,candidate,previous,receipt,previous_receipt,boundary_file=map(contract.canonical,
        (base,candidate,previous,receipt,previous_receipt,boundary_file))
    gate.g.require(candidate.is_relative_to(base/'releases') and candidate!=previous,'candidate_layout')
    with contract.lock(base/'activation.lock'):
      with contract.lock(base/'scheduler.lock'):
        policy=contract.policy(policy_file,candidate,base,receipt)
        if rollback:
            gate.g.require(policy.get('rollback_code_compatible') is True and policy.get('data_restore_required') is False,'unsafe_code_rollback')
        gate.g.require(contract.current_target(base)==previous,'previous_changed')
        sealed=contract.verify(candidate,receipt)
        artifact=sealed.get('artifact_sha256')
        gate.g.require(isinstance(artifact,str) and re.fullmatch(r'[a-f0-9]{64}',artifact),'sealed_artifact_missing')
        gate.check(contract,base,previous,previous_receipt,boundary_file)
        gate.runtime_gate_policy(contract,policy,candidate,receipt)
        result=oidc_evidence_flow.assess(base/'authorization',audience,oidc_token,read_token,
            'rollback' if rollback else 'activate',candidate.name,artifact)
        gate.g.require(result.get('state')=='evidence_consistent_not_authorized','evidence_failed')
        # Revalidate the actual release and previous pointer after network reads.
        gate.g.require(contract.current_target(base)==previous,'previous_changed')
        gate.g.require(contract.verify(candidate,receipt).get('artifact_sha256')==artifact,'receipt_changed')
        policy_after=contract.policy(policy_file,candidate,base,receipt)
        gate.g.require(policy_after==policy,'policy_changed')
        gate.check(contract,base,previous,previous_receipt,boundary_file)
        gate.runtime_gate_policy(contract,policy_after,candidate,receipt)
        return {'state':'evidence_consistent_not_authorized','candidate':candidate.name,'artifact':artifact}
