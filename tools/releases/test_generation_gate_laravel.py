#!/usr/bin/env python3
"""Real PHP/Laravel database queue/cache and sealed #59/#61/#62 composition."""
import importlib.util,json,os,shutil,sqlite3,subprocess,sys,tempfile,time,pwd,socket,urllib.request,urllib.parse,runpy
import generation_activation as gate
from pathlib import Path

def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
source=Path(__file__).resolve().parents[2];g=module('g',source/'tools/releases/generation_supervisor.py')
with tempfile.TemporaryDirectory(prefix='rei26-laravel-') as temp:
    temp=Path(temp);clone=temp/'source'
    subprocess.run(['git','clone','--quiet','--no-hardlinks',str(source),str(clone)],check=True)
    shutil.copyfile(source/'tests/Fixtures/Generation/console.php',clone/'routes/console.php')
    shutil.copyfile(source/'tests/Fixtures/Generation/GenerationProbe.php',clone/'app/Jobs/GenerationProbe.php')
    subprocess.run(['git','-C',str(clone),'add','.'],check=True)
    subprocess.run(['git','-C',str(clone),'-c','user.name=Synthetic','-c','user.email=synthetic@example.invalid','commit','-qm','Synthetic scheduler fixtures only'],check=True)
    d=module('d',clone/'tools/releases/derived_assets.py');c=d.contract(clone)
    candidate=temp/'candidate';proof=d.build(clone,candidate);evidence=temp/'proof.json';evidence.write_bytes(d.encoded(proof))
    artifact=temp/'artifact.tar';pack=d.package(clone,candidate,evidence,artifact)
    base=temp/'control';base.mkdir();(base/'releases').mkdir();root=base/'releases'/proof['source_sha'];transport=temp/'transport.json'
    # #61 requires pre-provisioned regular locks, outside immutable releases.
    # Do not let fixture omissions masquerade as scheduler contention evidence.
    for name in ('activation.lock','scheduler.lock'):
        fd=os.open(base/name,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600);os.close(fd)
        with c.lock(base/name):pass
    c.unpack(artifact,pack['artifact_sha256'],root,transport);receipt=temp/'receipt.json';d.seal(clone,candidate,evidence,root,receipt,transport)
    (base/'current').symlink_to(root);db=temp/'database.sqlite';db.touch();marker=temp/'marker'
    # Exercise normal Artisan lifecycle: APP_ENV=testing disables CommandFinished in Laravel.
    # Isolation is supplied by this disposable tree/SQLite/queue, not the environment name.
    env={'PATH':os.environ['PATH'],'HOME':str(temp),'APP_ENV':'fokus-synthetic','APP_KEY':'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=','DB_CONNECTION':'sqlite','DB_DATABASE':str(db),'CACHE_STORE':'database','SESSION_DRIVER':'array','LOG_CHANNEL':'stderr','FOKUS_SYNTHETIC_MARKER':str(marker)}
    php_path=shutil.which('php')
    assert php_path, 'PHP executable unavailable'
    php=str(Path(php_path).resolve(strict=True))
    for migration in ('0001_01_01_000001_create_cache_table.php','0001_01_01_000002_create_jobs_table.php'):
        subprocess.run([php,'artisan','migrate','--force','--path=database/migrations/'+migration],cwd=root,env=env,check=True)
    def run(mode,slot,expect):
        prior_journals={path.name:path.read_bytes() for path in base.glob('activation-*.json') if path.name!='activation-blocked.json'}
        currentenv={**env,'FOKUS_SYNTHETIC_MODE':mode}
        if mode=='missing-scope':currentenv['APP_ENV']='testing'
        if mode in ('success','queue-fail'):
            subprocess.run([php,'artisan','generation:enqueue'],cwd=root,env=currentenv,check=True)
        events=temp/('events-'+mode+'-'+str(slot));events.touch()
        scope=temp/'scope.json';scope.write_text(json.dumps(dict(schema=gate.SCHEMA,base=str(base),root=str(root),receipt_sha256=gate.digest(receipt),node_sha256=c.node_fingerprint(),boot=gate.boot(),operator_uid=os.geteuid(),verified_at=time.time(),expires_at=time.time()+240,evidence_reference='Disposable fixture scope only; no production attestation',**{k:True for k in gate.BOUNDARIES})))
        pid=os.fork()
        if pid==0:
            try:g.supervise(base,root,[php,str(root/'artisan'),'schedule:run'],events,10,currentenv,slot=slot,validate=lambda:c.verify(root,receipt),complete=gate.completion(c,base,root,receipt,scope))
            except BaseException as error:
                (temp/('error-'+mode)).write_text(str(error));os._exit(1)
            os._exit(0)
        # Observe the real supervisor holding the provisioned lock; always reap before cleanup.
        try:
            for _ in range(2000):
                try:
                    with c.lock(base/'scheduler.lock'):pass
                except BlockingIOError:break
                time.sleep(.002)
            else:raise AssertionError('scheduler lock not observed')
            dummy_policy=temp/'dummy-policy.json';dummy_policy.write_text('{}')
            try:gate.activate(c,base,base/'releases'/('b'*40),receipt,dummy_policy,root,receipt,scope)
            except BlockingIOError:pass
            else:raise AssertionError('activation entered while scheduler active')
        finally:
            code=os.waitstatus_to_exitcode(os.waitpid(pid,0)[1])
        if mode in ('overlap-skip','filter-skip','distributed'):
            rows=[json.loads(line) for line in events.read_text().splitlines()]
            skipped=[row['event'] for row in rows if row['state']==('scope_unverified' if mode=='distributed' else 'skipped')]
            assert skipped, 'real Laravel overlap skip event was not observed'
            if mode!='distributed':assert not any(row.get('event') in skipped and row['state'] in ('start','finish') for row in rows), 'filtered task unexpectedly executed'
            assert not (base/'scheduler-ready.json').exists(), 'skip qualified for activation'
        if mode=='missing-scope':
            rows=[json.loads(line) for line in events.read_text().splitlines()]
            assert not any(row['state']=='scope_checked' for row in rows)
            assert 'scheduler_lifecycle_unproven' in (temp/('error-'+mode)).read_text()
            assert not (base/'scheduler-ready.json').exists()
        assert code==expect,(mode,code,(temp/('error-'+mode)).read_text() if code else '')
        assert (base/'current').resolve()==root
        assert prior_journals=={path.name:path.read_bytes() for path in base.glob('activation-*.json') if path.name!='activation-blocked.json'}
        c.verify(root,receipt)
        if expect:
            latch=json.loads((base/'activation-blocked.json').read_bytes());assert latch['drained'] is True
            g.recover_drained(base,base/('scheduler-'+latch['run']+'.json'),latch['run'])
        else:assert not (base/'activation-blocked.json').exists()
    run('success',1,0)
    assert marker.read_text().splitlines()==[root.name]
    # Existing #61 background guard deliberately remains: no false production enablement.
    try:c.scheduler_contract(root)
    except c.Refusal:pass
    else:raise AssertionError('legacy guard unexpectedly removed')
    subprocess.run([php,'artisan','generation:mutex','lock'],cwd=root,env=env,check=True)
    run('overlap-skip',2,1)
    subprocess.run([php,'artisan','generation:mutex','unlock'],cwd=root,env=env,check=True)
    run('queue-fail',3,1)
    run('foreground-fail',4,1)
    run('filter-skip',5,1)
    assert not Path(str(marker)+'.filtered').exists()
    run('distributed',6,1)
    run('missing-scope',7,1)
    conn=sqlite3.connect(db);assert conn.execute('select count(*) from failed_jobs').fetchone()[0]==1
    assert conn.execute('select count(*) from jobs').fetchone()[0]==0
    old=root;oldreceipt=receipt
    # Second real built release differs only in synthetic documentation.
    (clone/'README.md').write_text((clone/'README.md').read_text()+'\nSynthetic next generation.\n')
    subprocess.run(['git','-C',str(clone),'add','README.md'],check=True)
    subprocess.run(['git','-C',str(clone),'-c','user.name=Synthetic','-c','user.email=synthetic@example.invalid','commit','-qm','Synthetic next generation'],check=True)
    built=temp/'next-build';nextproof=d.build(clone,built);nextproofpath=temp/'next-proof.json';nextproofpath.write_bytes(d.encoded(nextproof))
    nextartifact=temp/'next.tar';nextpack=d.package(clone,built,nextproofpath,nextartifact)
    newroot=base/'releases'/nextproof['source_sha'];nexttransport=temp/'next-transport.json';c.unpack(nextartifact,nextpack['artifact_sha256'],newroot,nexttransport)
    newreceipt=temp/'next-receipt.json';d.seal(clone,built,nextproofpath,newroot,newreceipt,nexttransport)
    verifier=temp/'verifier.py';verifier.write_bytes(subprocess.check_output(['git','show','a8cc9729181471c9f46ccb15d51383e1aceb61ab:tools/ci/verify-deployment.py'],cwd=source))
    vp=runpy.run_path(str(verifier))
    serve_old=[True]
    def real_http(p,target):
        served=old if serve_old[0] else target
        # Exact verifier protocol with HTTP loopback transport injection only. Not TLS/FPM proof.
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        with (temp/'php-http.log').open('ab') as log:
            server=subprocess.Popen([php,'-d','opcache.enable=0','-d','opcache.enable_cli=0','-S',f'127.0.0.1:{port}','-t',str(served/'public')],cwd=served,env=env,stdout=log,stderr=log)
            try:
                time.sleep(.5)
                def fetch(url,timeout):
                    parsed=urllib.parse.urlsplit(url);url=f'http://127.0.0.1:{port}'+parsed.path+('?' + parsed.query if parsed.query else '')
                    with urllib.request.urlopen(url,timeout=timeout) as response:return response.status,{k.lower():v for k,v in response.headers.items()},response.read(4097)
                cfg=vp['configuration'](c.verifier_environment(p,target.name));vp['verify'](cfg,fetcher=fetch)
            finally:server.terminate();server.wait(timeout=10)
    c.run_verifier=real_http
    required=('target_binding_verified','php_cli_platform_verified','php_fpm_platform_verified','extensions_verified','fastcgi_physical_root_verified','permissions_acl_verified','runtime_cannot_write_release','workers_drained','shared_drivers_verified','schema_compatible','recovery_verified','publication_authorized','caches_finalized','laravel_boot_verified','assets_complete_verified','scheduler_descendants_absent_verified','build_provenance_verified')
    p={k:True for k in required}
    p.update(schema=c.SCHEMA,release=newroot.name,base=str(base),root=str(newroot),receipt_sha256=c.digest(newreceipt.read_bytes()),node_sha256=c.node_fingerprint(),operator_uid=os.geteuid(),runtime_uid=pwd.getpwnam('nobody').pw_uid,php_binary=php,expires_at_epoch=time.time()+120,evidence_reference='Synthetic policy only, not VPS runtime',fpm_opcache_enabled=False,preload_configured_or_loaded=False,fpm_reload_mode='verify-only',fpm_service='',verifier_script=str(verifier),public_base_url='https://fixture.invalid',release_url='https://fixture.invalid/_deployment/release')
    policy=temp/'activation-policy.json';policy.write_text(json.dumps(p))
    # Validate exact #61 policy before asserting any post-switch failure scenario.
    c.policy(policy,newroot,base,newreceipt)
    run('success',8,0)
    try:gate.activate(c,base,newroot,newreceipt,policy,old,oldreceipt,temp/'scope.json')
    except vp['VerificationError'] as error:
        assert str(error)=='active_release_mismatch', ('unexpected verifier reason',str(error))
        print('post_switch_failure=active_release_mismatch')
    else:raise AssertionError('real stale endpoint accepted')
    assert (base/'current').resolve()==newroot
    blocked=json.loads((base/'activation-blocked.json').read_text());assert blocked['state']=='activation_unverified_manual_recovery_required'
    serve_old[0]=False
    gate.acknowledge_current(c,base,base/('activation-'+blocked['run']+'.json'),blocked['run'],newreceipt,policy)
    assert not (base/'activation-blocked.json').exists()
    # Explicit synthetic code-only rollback, never automatic data rollback.
    root=newroot;receipt=newreceipt;run('success',9,0)
    p.update(release=old.name,root=str(old),receipt_sha256=c.digest(oldreceipt.read_bytes()),rollback_code_compatible=True,data_restore_required=False,expires_at_epoch=time.time()+120)
    policy.write_text(json.dumps(p))
    result=gate.activate(c,base,old,oldreceipt,policy,newroot,newreceipt,temp/'scope.json',rollback=True)
    assert result['state']=='verified' and (base/'current').resolve()==old
    assert not (base/'scheduler-ready.json').exists()
    try:gate.activate(c,base,old,oldreceipt,policy,newroot,newreceipt,temp/'scope.json',rollback=True)
    except Exception:pass
    else:raise AssertionError('duplicate activation allowed')
    print(json.dumps({'gate':'real_artifact_laravel_activation_verified','previous':old.name,'candidate':newroot.name,'candidate_artifact_sha256':nextpack['artifact_sha256'],'previous_artifact_sha256':pack['artifact_sha256'],'http_transport':'loopback injection, no TLS/FPM claim'}))
    for item in newroot.rglob('*'):
        if not item.is_symlink():item.chmod(0o700 if item.is_dir() else 0o600)
    newroot.chmod(0o700)
    print(json.dumps({'state':'real_laravel_generation_verified','source_sha':old.name,'artifact_sha256':pack['artifact_sha256'],'queue_driver':'database','cache_driver':'database','database':'disposable SQLite','background_preserved':True,'legacy_activation_guard':'still closed'}))
    root=old
    for p in root.rglob('*'):
        if not p.is_symlink():p.chmod(0o700 if p.is_dir() else 0o600)
    root.chmod(0o700)
