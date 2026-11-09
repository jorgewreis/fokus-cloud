#!/usr/bin/env python3
"""Real PHP/Laravel database queue/cache and sealed #59/#61/#62 composition."""
import importlib.util,json,os,shutil,sqlite3,subprocess,sys,tempfile,time
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
    php=shutil.which('php')
    for migration in ('0001_01_01_000001_create_cache_table.php','0001_01_01_000002_create_jobs_table.php'):
        subprocess.run([php,'artisan','migrate','--force','--path=database/migrations/'+migration],cwd=root,env=env,check=True)
    def run(mode,slot,expect):
        currentenv={**env,'FOKUS_SYNTHETIC_MODE':mode}
        if mode in ('success','queue-fail'):
            subprocess.run([php,'artisan','generation:enqueue'],cwd=root,env=currentenv,check=True)
        events=temp/('events-'+mode);events.touch();pid=os.fork()
        if pid==0:
            try:g.supervise(base,root,[php,str(root/'artisan'),'schedule:run'],events,10,currentenv,slot=slot,validate=lambda:c.verify(root,receipt))
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
            try:c.activate(base,base/'releases'/('b'*40),receipt,temp/'nonexistent-policy',root)
            except BlockingIOError:pass
            else:raise AssertionError('activation entered while scheduler active')
        finally:
            code=os.waitstatus_to_exitcode(os.waitpid(pid,0)[1])
        if mode in ('overlap-skip','filter-skip','distributed'):
            rows=[json.loads(line) for line in events.read_text().splitlines()]
            assert any(row['state']==('scope_unverified' if mode=='distributed' else 'skipped') for row in rows), 'real Laravel skip missing'
        assert code==expect,(mode,code,(temp/('error-'+mode)).read_text() if code else '')
        assert (base/'current').resolve()==root
        assert not [path for path in base.glob('activation-*.json') if path.name!='activation-blocked.json']
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
    run('overlap-skip',2,0)
    subprocess.run([php,'artisan','generation:mutex','unlock'],cwd=root,env=env,check=True)
    run('queue-fail',3,1)
    run('foreground-fail',4,1)
    run('filter-skip',5,0)
    assert not Path(str(marker)+'.filtered').exists()
    run('distributed',6,0)
    conn=sqlite3.connect(db);assert conn.execute('select count(*) from failed_jobs').fetchone()[0]==1
    assert conn.execute('select count(*) from jobs').fetchone()[0]==0
    print(json.dumps({'state':'real_laravel_generation_verified','source_sha':root.name,'artifact_sha256':pack['artifact_sha256'],'queue_driver':'database','cache_driver':'database','database':'disposable SQLite','background_preserved':True,'legacy_activation_guard':'still closed'}))
    for p in root.rglob('*'):
        if not p.is_symlink():p.chmod(0o700 if p.is_dir() else 0o600)
    root.chmod(0o700)
