#!/usr/bin/env python3
"""Generation handoff gate. No production policy defaults or legacy bypass."""
import hashlib, json, os, re, time, uuid, stat
from pathlib import Path
import generation_supervisor as g
import runtime_evidence

SCHEMA='fokus-generation-handoff-v1'
BOUNDARIES=('all_scheduler_entrypoints_controlled','external_workers_absent_verified','no_external_process_delegation_verified','pre_supervision_work_absent_verified','permanent_workers_absent_verified','queue_generation_compatibility_verified')

def digest(path): return hashlib.sha256(Path(path).read_bytes()).hexdigest()
def source_binding(): return digest(Path(g.__file__)) + ':' + digest(Path(__file__))
def strict_events(path):
    states=[json.loads(line).get('state') for line in Path(path).read_bytes().splitlines()]
    g.require(states.count('scope_checked')==1,'scheduler_lifecycle_unproven')
    g.require(all(state not in ('skipped','scope_unverified') for state in states),'overlap_work_unproven')
def boot(): return Path('/proc/sys/kernel/random/boot_id').read_text().strip()
def protected(path,directory=False):
    path=Path(path).absolute();g.require(path==path.resolve(),'control_symlink')
    g.control(path,directory)
    for parent in path.parents:
        st=parent.lstat()
        # Ancestors need not be root-owned in containers/user namespaces;
        # they must be non-writable, or sticky if shared-writable (for example /tmp).
        g.require(stat.S_ISDIR(st.st_mode) and (not st.st_mode & 0o022 or st.st_mode & stat.S_ISVTX),'mutable_control_ancestor')
    return path
def read(path):
    protected(path)
    return json.loads(Path(path).read_bytes())

def boundary(c,base,root,receipt,path):
    p=read(path)
    g.require(p.get('schema')==SCHEMA and p.get('base')==str(base) and p.get('root')==str(root) and p.get('receipt_sha256')==digest(receipt) and p.get('node_sha256')==c.node_fingerprint() and p.get('boot')==boot() and p.get('operator_uid')==os.geteuid(),'boundary_binding')
    g.require(all(p.get(k) is True for k in BOUNDARIES),'uncontrolled_work')
    g.require(isinstance(p.get('evidence_reference'),str) and bool(p['evidence_reference'].strip()),'boundary_reference_required')
    now=time.time()
    g.require(type(p.get('verified_at')) in (int,float) and type(p.get('expires_at')) in (int,float) and p['verified_at']<=now<p['expires_at']<=p['verified_at']+300,'boundary_stale')
    return p

def completion(c,base,root,receipt,boundary_file):
    """Called only by supervisor after ECHILD/reconciliation, under scheduler lock.
    Operator controls are the trust boundary, not signatures against root compromise.
    """
    base,root,receipt,boundary_file=map(lambda p:Path(p).absolute(),(base,root,receipt,boundary_file))
    protected(base,True);protected(receipt);protected(boundary_file)
    def finish(state,journal,events):
        b=boundary(c,base,root,receipt,boundary_file)
        c.verify(root,receipt)
        g.require(state.get('state')=='drained_verified' and state.get('drained') is True and state.get('root')==str(root) and state.get('boot')==boot(),'supervision_not_complete')
        g.require(bool(state.get('exits')) and all(x.get('code')==0 for x in state['exits']),'process_results_missing')
        expected=tuple(state.get('event_identity',[]));fd=os.open(events,os.O_RDONLY|os.O_NOFOLLOW)
        with os.fdopen(fd,'rb') as stream:
            st=os.fstat(stream.fileno());g.require(stat.S_ISREG(st.st_mode) and st.st_nlink==1 and (st.st_dev,st.st_ino)==expected,'events_rebound')
            snapshot=stream.read(1024*1024+1)
        g.require(len(snapshot)<=1024*1024,'events_unreadable')
        now=time.time()
        record={'schema':SCHEMA,'run':state['run'],'root':str(root),'release':root.name,'base':str(base),'boot':boot(),'node_sha256':c.node_fingerprint(),'receipt_sha256':digest(receipt),'journal_sha256':digest(journal),'events_sha256':hashlib.sha256(snapshot).hexdigest(),'boundary_sha256':digest(boundary_file),'created_at':now,'expires_at':min(now+120,b['expires_at']),'operator_uid':os.geteuid(),'source_binding':source_binding(),'monotonic_at':time.monotonic()}
        # Immutable controller-owned copy prevents later runtime ledger mutation.
        archive=base/('handoff-events-'+state['run']+'.jsonl')
        fd=os.open(archive,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        with os.fdopen(fd,'wb') as out:out.write(snapshot);out.flush();os.fsync(out.fileno())
        g.sync(base)
        g.require(digest(archive)==record['events_sha256'],'events_changed')
        strict_events(archive)
        g.require(g.event_result(archive,state['run'],root.name)==state['events'],'event_reconciliation_changed')
        g.record(base/'scheduler-ready.json',record)
    return finish

def check(c,base,root,receipt,boundary_file):
    """Caller holds both activation and scheduler locks through consumption/switch."""
    g.control(base,True);g.require(not (base/'activation-blocked.json').exists(),'pending_failure')
    p=read(base/'scheduler-ready.json');now=time.time()
    g.require(p.get('schema')==SCHEMA and re.fullmatch('[0-9a-f]{32}',p.get('run','')),'proof_format')
    g.require(p.get('root')==str(root) and p.get('release')==root.name and p.get('base')==str(base) and p.get('boot')==boot() and p.get('node_sha256')==c.node_fingerprint() and p.get('operator_uid')==os.geteuid(),'proof_environment')
    g.require(type(p.get('created_at')) in (int,float) and type(p.get('expires_at')) in (int,float) and p['created_at']<=now<p['expires_at']<=p['created_at']+120,'proof_expired')
    g.require(p.get('source_binding')==source_binding() and type(p.get('monotonic_at')) in (int,float) and 0<=time.monotonic()-p['monotonic_at']<120,'proof_runtime_changed')
    c.verify(root,receipt);g.require(p['receipt_sha256']==digest(receipt),'proof_receipt')
    boundary(c,base,root,receipt,boundary_file);g.require(p['boundary_sha256']==digest(boundary_file),'boundary_changed')
    journal=base/('scheduler-'+p['run']+'.json');j=read(journal)
    g.require(digest(journal)==p['journal_sha256'] and j.get('state')=='drained_verified' and j.get('drained') is True and j.get('run')==p['run'] and j.get('root')==str(root) and j.get('boot')==boot(),'journal_inconsistent')
    slot=read(base/('scheduler-slot-'+str(j.get('slot'))+'.json'))
    g.require(slot=={'run':p['run'],'release':root.name,'slot':j['slot']},'slot_inconsistent')
    g.require(bool(j.get('exits')) and all(x.get('code')==0 for x in j['exits']),'unreconciled_exits')
    archive=base/('handoff-events-'+p['run']+'.jsonl');g.control(archive);strict_events(archive)
    g.require(digest(archive)==p['events_sha256'] and g.event_result(archive,p['run'],root.name)==j['events'],'ledger_inconsistent')
    # Any later/incomplete run invalidates old proof, even if a latch was lost.
    for path in base.glob('scheduler-*.json'):
        if path.name.startswith('scheduler-slot-') or path.name=='scheduler-ready.json':continue
        other=read(path)
        g.require(other.get('state') in ('drained_verified','failure_acknowledged'),'other_journal_pending')
        if other.get('root')==str(root):g.require(other.get('slot',-1)<=j['slot'],'newer_run_exists')
    for path in base.glob('activation-*.json'):
        if path.name=='activation-blocked.json':continue
        previous=read(path)
        g.require(previous.get('state') in ('verified','manually_verified_current'),'activation_journal_pending')
    g.require(not (base/('consumed-'+p['run']+'.json')).exists(),'proof_already_consumed')
    return p

def runtime_gate_policy(c,policy,candidate,receipt):
    """Require operator-reviewed runtime evidence inside the activation locks."""
    expiry=runtime_evidence.read_verified(policy,root=candidate,receipt=receipt,
        node_sha256=c.node_fingerprint(),boot_id=boot(),operator_uid=os.geteuid(),protect=protected)
    from preparation_guard import verify_runtime_read
    runtime_evidence.require(candidate.stat().st_gid==policy['runtime_gid'],'runtime_root_group_mismatch')
    verify_runtime_read(candidate,policy['runtime_uid'],policy['runtime_gid'])
    bounded=dict(policy);bounded['expires_at_epoch']=min(policy['expires_at_epoch'],expiry)
    return bounded

def activate(c,base,candidate,receipt,policy_file,expected_previous,previous_receipt,boundary_file,rollback=False):
    protected(base,True)
    for value in (receipt,policy_file,previous_receipt,boundary_file):protected(value)
    base,candidate,expected_previous,previous_receipt,boundary_file=map(c.canonical,(base,candidate,expected_previous,previous_receipt,boundary_file))
    g.require(candidate.is_relative_to(base/'releases') and candidate!=expected_previous,'candidate_layout')
    with c.lock(base/'activation.lock'):
      with c.lock(base/'scheduler.lock'):
        p=c.policy(policy_file,candidate,base,receipt)
        if rollback:g.require(p.get('rollback_code_compatible') is True and p.get('data_restore_required') is False,'unsafe_code_rollback')
        g.require(c.current_target(base)==expected_previous,'previous_changed')
        c.verify(candidate,receipt)
        proof=check(c,base,expected_previous,previous_receipt,boundary_file)
        p=runtime_gate_policy(c,p,candidate,receipt)
        g.require(candidate.stat().st_dev==base.stat().st_dev,'cross_device_activation')
        run=uuid.uuid4().hex;journal=base/('activation-'+run+'.json');latch=base/'activation-blocked.json'
        state={'schema':SCHEMA,'run':run,'state':'switch_intent','candidate':str(candidate),'previous':str(expected_previous),'handoff_run':proof['run'],'handoff_sha256':digest(base/'scheduler-ready.json'),'node_sha256':c.node_fingerprint(),'boot':boot()}
        g.record(latch,state);g.record(journal,state)
        # Consume before pointer mutation. Interruption cannot reuse approval.
        os.rename(base/'scheduler-ready.json',base/('consumed-'+proof['run']+'.json'));g.sync(base)
        temporary=base/('.current-'+run);temporary.symlink_to(candidate)
        try:
            g.require(p['expires_at_epoch']>=time.time() and proof['expires_at']>time.time(),'evidence_expired')
            os.replace(temporary,base/'current');g.sync(base)
            c.run_verifier(p,candidate);c.verify(candidate,receipt)
            g.require(p['expires_at_epoch']>=time.time(),'evidence_expired')
            g.require(c.current_target(base)==candidate,'current_changed_during_verification')
        except BaseException:
            state['state']='activation_unverified_manual_recovery_required';g.record(journal,state);g.record(latch,state);raise
        state['state']='verified';g.record(journal,state);g.record(latch,state);latch.unlink();g.sync(base)
        return {'state':'verified','release':candidate.name,'handoff_run':proof['run'],'code_rollback':rollback}

def acknowledge_current(c,base,journal,expected_run,receipt,policy_file):
    """Explicit manual acknowledgement, not rollback, retry or data restoration.
    Requires actual current identity verification and unchanged consumed proof.
    """
    protected(base,True)
    for value in (journal,receipt,policy_file):protected(value)
    base,journal=map(c.canonical,(base,journal))
    with c.lock(base/'activation.lock'):
      with c.lock(base/'scheduler.lock'):
        a=read(base/'activation-blocked.json');j=read(journal)
        g.require(a.get('schema')==SCHEMA and a.get('run')==expected_run and journal==base/('activation-'+expected_run+'.json'),'recovery_binding')
        pending=('switch_intent','activation_unverified_manual_recovery_required')
        terminal=('verified','manually_verified_current')
        same_intent={k:v for k,v in a.items() if k!='state'}=={k:v for k,v in j.items() if k not in ('state','verified_current')}
        reconciled_terminal=(a.get('state') in pending and j.get('state') in terminal or
                             a.get('state')=='verified' and j.get('state')=='manually_verified_current') and same_intent
        g.require((a==j and a.get('state') in pending+terminal) or reconciled_terminal,'recovery_binding')
        g.require(a.get('boot')==boot() and a.get('node_sha256')==c.node_fingerprint(),'unknown_recovery')
        consumed=base/('consumed-'+a['handoff_run']+'.json')
        g.control(consumed);g.require(digest(consumed)==a['handoff_sha256'],'consumption_unproven')
        root=c.current_target(base);g.require(str(root) in (a['candidate'],a['previous']),'unexpected_current')
        if j.get('state')=='manually_verified_current':g.require(j.get('verified_current')==str(root),'recovery_state_changed')
        p=c.policy(policy_file,root,base,receipt);c.verify(root,receipt);c.run_verifier(p,root);c.verify(root,receipt)
        g.require(c.current_target(base)==root and p['expires_at_epoch']>=time.time(),'recovery_changed')
        a.update(state='manually_verified_current',verified_current=str(root));g.record(journal,a);g.record(base/'activation-blocked.json',a)
        (base/'activation-blocked.json').unlink();g.sync(base)

def cli():
    import argparse
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command',choices=('activate','recover'))
    for key in ('base','receipt','policy','immutable-contract'):parser.add_argument('--'+key,required=True)
    for key in ('candidate','previous','previous-receipt','boundary','journal','run'):parser.add_argument('--'+key)
    parser.add_argument('--code-rollback',action='store_true')
    a=parser.parse_args();path=Path(a.immutable_contract);code=path.read_bytes()
    g.require(hashlib.sha256(code).hexdigest()==g.CONTRACT_DIGEST,'contract_mismatch')
    ns={'__name__':'immutable_contract','__file__':str(path)};exec(compile(code,str(path),'exec'),ns)
    c=type('Contract',(),ns)
    for target in (a.base,):g.control(Path(target).resolve(),True)
    for target in (a.receipt,a.policy):g.control(Path(target).resolve())
    if a.command=='activate':
        g.require(all((a.candidate,a.previous,a.previous_receipt,a.boundary)),'activation_arguments')
        result=activate(c,a.base,a.candidate,a.receipt,a.policy,a.previous,a.previous_receipt,a.boundary,a.code_rollback)
    else:
        g.require(bool(a.journal and a.run) and not a.code_rollback,'recovery_arguments')
        acknowledge_current(c,a.base,a.journal,a.run,a.receipt,a.policy)
        result={'state':'manually_verified_current'}
    print(json.dumps(result))

if __name__=='__main__':
    try:cli()
    except runtime_evidence.MissingRuntimeEvidence:
        import sys
        print('{"state":"blocked","reason":"runtime_evidence_rejected"}',file=sys.stderr);sys.exit(1)
    except BaseException:
        import sys
        print('{"state":"blocked","reason":"generation_gate_failed"}',file=sys.stderr);sys.exit(1)
