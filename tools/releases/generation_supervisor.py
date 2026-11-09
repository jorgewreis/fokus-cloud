#!/usr/bin/env python3
"""Linux descendant supervisor. One dedicated process, one generation, no deploy."""
import argparse, ctypes, errno, fcntl, hashlib, json, os, re, signal, subprocess, sys, time, uuid
from contextlib import contextmanager
from pathlib import Path

class Blocked(RuntimeError): pass

def require(condition, reason):
    if not condition: raise Blocked(reason)

def encode(value): return json.dumps(value,sort_keys=True,separators=(',',':')).encode()

def sync(directory):
    fd=os.open(directory,os.O_RDONLY|os.O_DIRECTORY)
    try: os.fsync(fd)
    finally: os.close(fd)

def record(path,value):
    temp=path.with_name('.record-'+uuid.uuid4().hex)
    fd=os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'wb') as stream: stream.write(encode(value));stream.flush();os.fsync(stream.fileno())
    os.replace(temp,path);sync(path.parent)

@contextmanager
def lock(path):
    fd=os.open(path,os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
    try:
        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB);yield
    finally: os.close(fd)

def subreaper():
    require(sys.platform=='linux','linux_required')
    libc=ctypes.CDLL(None,use_errno=True)
    require(libc.prctl(36,1,0,0,0)==0,'subreaper_unavailable')
    value=ctypes.c_int()
    require(libc.prctl(37,ctypes.byref(value),0,0,0)==0 and value.value==1,'subreaper_not_confirmed')
    # A dedicated supervisor must not adopt unrelated existing children.
    try:
        os.waitpid(-1,os.WNOHANG)
    except ChildProcessError: return
    raise Blocked('preexisting_children')

def event_result(path, run_id, release):
    require(path.is_file() and not path.is_symlink() and path.stat().st_size<=1024*1024,'events_unreadable')
    starts={};ends={};ready=False
    for line in path.read_bytes().splitlines():
        row=json.loads(line)
        require(row.get('run')==run_id and row.get('release')==release,'event_generation_mismatch')
        state=row.get('state');key=row.get('event')
        require(state!='queue_failure','queue_job_failed')
        if state=='ready':ready=True;continue
        require(isinstance(key,str) and re.fullmatch('[0-9a-f]{64}',key),'invalid_event')
        if state=='start':
            require(key not in starts,'repeated_event_unsupported');starts[key]=True
        elif state=='finish':
            require(key not in ends and type(row.get('code')) is int,'invalid_terminal');ends[key]=row['code']
        elif state=='failure':raise Blocked('scheduled_event_failed')
        else:raise Blocked('unknown_event_state')
    require(ready,'observer_missing')
    require(set(starts)==set(ends),'event_result_missing')
    require(all(code==0 for code in ends.values()),'scheduled_event_failed')
    return len(starts)

def supervise(base,root,command,events,timeout=120,env=None,uid=None,gid=None,slot=None,validate=None):
    """Core used by tests and authorized adapter; caller must bind receipt/policy first.
    Never use this function alone as a production authorization or artifact verifier.
    """
    base,root,events=map(lambda p:Path(p).absolute(),(base,root,events))
    require(base==base.resolve() and root==root.resolve(),'canonical_paths_required')
    require(re.fullmatch('[0-9a-f]{40}',root.name),'full_release_sha')
    require(timeout>0 and timeout<=3600,'bounded_execution_required')
    require(events.is_file() and not events.is_symlink() and events.stat().st_size==0,'new_event_log_required')
    subreaper()
    with lock(base/'scheduler.lock'):
        latch=base/'activation-blocked.json'
        require(not latch.exists(),'manual_recovery_required')
        if validate is not None:validate()
        require((base/'current').resolve()==root,'generation_changed')
        slot=int(time.time()//60) if slot is None else slot
        require(type(slot) is int and slot>=0,'invalid_slot')
        slot_path=base/('scheduler-slot-'+str(slot)+'.json')
        require(not slot_path.exists(),'duplicate_scheduler_slot')
        run_id=uuid.uuid4().hex;journal=base/('scheduler-'+run_id+'.json')
        state={'schema':'fokus-generation-v1','run':run_id,'release':root.name,'root':str(root),'boot':Path('/proc/sys/kernel/random/boot_id').read_text().strip(),'state':'starting'}
        state['slot']=slot
        record(latch,state);record(journal,state);record(slot_path,{'run':run_id,'release':root.name,'slot':slot})
        environment=dict(env or {})
        environment.update(FOKUS_GENERATION_RUN=run_id,FOKUS_GENERATION_SHA=root.name,FOKUS_GENERATION_ROOT=str(root),FOKUS_GENERATION_EVENTS=str(events))
        options={}
        if uid is not None: options.update(user=uid,group=gid,extra_groups=[],umask=0o077)
        proc=None;statuses=[];timed_out=False;deadline=time.monotonic()+timeout
        try:
            proc=subprocess.Popen(command,cwd=root,env=environment,stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True,**options)
            state.update(state='running',leader=proc.pid);record(journal,state)
            # waitpid(ECHILD), not a PID scan, proves all our descendants were reaped.
            # Orphans/double-forks are adopted by this dedicated Linux subreaper.
            while True:
                try: pid,status=os.waitpid(-1,os.WNOHANG)
                except ChildProcessError: break
                if pid:
                    code=os.waitstatus_to_exitcode(status);statuses.append({'pid':pid,'code':code})
                    if pid==proc.pid:proc.returncode=code
                else:time.sleep(.01)
                if not timed_out and time.monotonic()>deadline:
                    timed_out=True;state.update(state='timeout_draining',timeout=True);record(journal,state);record(latch,state)
                    # Do not kill jobs or abandon descendants. Remain the reaper until drained.
            state.update(drained=True,exits=statuses)
            require(not timed_out,'execution_timeout')
            require(proc.returncode==0 and all(x['code']==0 for x in statuses),'descendant_failed')
            count=event_result(events,run_id,root.name)
            if validate is not None:validate()
            require((base/'current').resolve()==root,'generation_changed')
            state.update(state='drained_verified',events=count);record(journal,state)
            latch.unlink();sync(base)
            return state
        except BaseException:
            # Even after ECHILD, failed/unknown work requires explicit recovery.
            state.update(state='manual_recovery_required',drained=state.get('drained',False));record(journal,state);record(latch,state)
            raise

def recover_drained(base,journal,expected_run):
    """Only a terminal, proven drained failure may be acknowledged. Never PID guessing."""
    base,journal=Path(base).resolve(),Path(journal).resolve()
    require(journal.parent==base,'journal_location')
    with lock(base/'scheduler.lock'):
        latch=base/'activation-blocked.json'
        a=json.loads(latch.read_bytes());b=json.loads(journal.read_bytes())
        require(a==b and a.get('schema')=='fokus-generation-v1' and a.get('run')==expected_run,'recovery_binding')
        require(a.get('state')=='manual_recovery_required' and a.get('drained') is True,'drainage_unproven')
        require(a.get('boot')==Path('/proc/sys/kernel/random/boot_id').read_text().strip(),'reboot_requires_review')
        require((base/'current').resolve()==Path(a['root']),'generation_changed')
        a.update(state='failure_acknowledged');record(journal,a);latch.unlink();sync(base)

def cli():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base',required=True);parser.add_argument('--root',required=True)
    parser.add_argument('--events',required=True);parser.add_argument('--policy',required=True)
    parser.add_argument('--receipt',required=True);parser.add_argument('--immutable-contract',required=True)
    args=parser.parse_args()
    # Production adapter requires reviewed #61 code and all its evidence gates.
    contract_path=Path(args.immutable_contract);code=contract_path.read_bytes()
    require(hashlib.sha256(code).hexdigest()==CONTRACT_DIGEST,'contract_mismatch')
    ns={'__name__':'immutable_contract','__file__':str(contract_path)};exec(compile(code,str(contract_path),'exec'),ns)
    c=type('Contract',(),ns);base,root=Path(args.base).resolve(),Path(args.root).resolve()
    p=c.read_json(c.canonical(args.policy));receipt=Path(args.receipt).resolve()
    def validate():
        c.verify(root,receipt);c.scheduler_identity(p,base,root)
        require(p.get('base')==str(base) and p.get('root')==str(root) and p.get('receipt_sha256')==c.digest(receipt.read_bytes()) and p.get('node_sha256')==c.node_fingerprint() and p.get('operator_uid')==os.geteuid(),'policy_binding')
        require(p.get('expires_at_epoch',0)>time.time() and p.get('platform_verified') is True and p.get('all_scheduler_entrypoints_controlled') is True and p.get('external_workers_absent_verified') is True and p.get('no_external_process_delegation_verified') is True,'runtime_evidence_incomplete')
        require(c.digest(php.read_bytes())==p.get('php_binary_sha256'),'php_mismatch')
        require(events==events.resolve() and not events.is_relative_to(root),'external_events_required')
        require(events.stat().st_uid==p['runtime_uid'] and events.stat().st_mode & 0o777==0o600,'event_log_owner')
    php=Path(p['php_binary']).resolve();events=Path(args.events).absolute()
    supervise(base,root,[str(php),str(root/'artisan'),'schedule:run'],events,p.get('timeout_seconds',120),{'PATH':'/usr/bin:/bin','LC_ALL':'C'},p['runtime_uid'],p['runtime_gid'],validate=validate)

CONTRACT_DIGEST='0d1a6c1d833d1aa262763c784e17d32464ac7a83c8a6f3fb64a63c4d14489c52'
if __name__=='__main__':
    try:cli()
    except BaseException:
        print('{"state":"blocked","reason":"generation_execution_failed"}',file=sys.stderr);sys.exit(1)
