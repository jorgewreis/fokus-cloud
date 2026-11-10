"""Mandatory host-browser half of the Linux gate. No production access or deploy."""
import argparse, hashlib, json, os, pathlib, re, shutil, subprocess, sys, tarfile, tempfile, time, urllib.request, uuid

ROOT=pathlib.Path(__file__).resolve().parents[3]
HERE=pathlib.Path(__file__).resolve().parent
p=argparse.ArgumentParser();p.add_argument('--backend-report',type=pathlib.Path,required=True);p.add_argument('--report',type=pathlib.Path,required=True);p.add_argument('--chrome',type=pathlib.Path,required=True)
a=p.parse_args();R=a.report.resolve();B=a.backend_report.resolve()
assert os.environ.get('RELEASE_DISPOSABLE_TESTS')=='1' and sys.platform=='linux'
assert not R.exists() or not any(R.iterdir());R.mkdir(parents=True,exist_ok=True)
backend=json.loads((B/'gate.json').read_text(encoding='utf-8'))
assert backend['passed'] and backend.get('backend_only'), 'Complete backend-only evidence required'
resources=json.loads((B/'resources.json').read_text(encoding='utf-8'))
source_volume=resources['volume'];assert re.fullmatch(r'fokus-law-linux-[0-9a-f]{12}-files',source_volume)
profile=json.loads((ROOT/'tests/Deployment/linux-runtime/profiles.json').read_text(encoding='utf-8'))[resources['profile']]
image=resources['image'];assert image==profile['runtime_image']
CACHE_STORE=profile['cache_store']
assert CACHE_STORE in {'redis','database'}, 'Invalid profile cache store'
assert profile['cache_limiter']==CACHE_STORE and profile['session_driver']=='database', 'Profile cache/session mismatch'
assert resources['profile_versions']['cache_store']==CACHE_STORE, 'Backend/host cache profile differs'
MYSQLIMAGE=resources['mysql'];REDISIMAGE=resources['redis']
assert MYSQLIMAGE==profile['mysql_image'] and REDISIMAGE==profile['redis_image']
tag='fokus-law-hosthttp-'+uuid.uuid4().hex[:10];vol=tag+'-files';net=tag+'-bridge';mysql=tag+'-mysql';redis=tag+'-redis';app=tag+'-app'
containers=[];manager=None;handle=None;outcomes=[];error=None
def write(path,data):path.write_text(json.dumps(data,indent=2),encoding='utf-8',newline='\n')
def cap(*args):return subprocess.check_output(['docker',*args],text=True)
def run(name,command,check=True):
    with (R/(name+'.log')).open('w',encoding='utf-8') as output:result=subprocess.run(command,stdout=output,stderr=subprocess.STDOUT)
    outcomes.append({'name':name,'exit_code':result.returncode});write(R/'commands.json',outcomes)
    if check and result.returncode:raise RuntimeError(name+' failed')
    return result.returncode
def docker(name,*args,check=True):return run(name,['docker',*args],check)
def exists(path):return subprocess.run(['docker','exec',app,'test','-f',path],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode==0

with tempfile.TemporaryDirectory(prefix='fokus-private-handoff-') as private:
    private=pathlib.Path(private)
    try:
        docker('volume','volume','create','--label','fokus.release.disposable=true',vol)
        docker('network','network','create','--driver','bridge','--label','fokus.release.disposable=true',net)
        docker('mysql','run','-d','--name',mysql,'--restart=no','--network',net,'--memory=1g','--cpus=2','-p','127.0.0.1::18081','-p','127.0.0.1::18082','-p','127.0.0.1::18083','-e','MYSQL_ROOT_PASSWORD=security-test-only',MYSQLIMAGE);containers.append(mysql)
        docker('redis','run','-d','--name',redis,'--restart=no','--network','container:'+mysql,'--memory=256m','--cpus=1',REDISIMAGE);containers.append(redis)
        docker('app','run','-d','--name',app,'--restart=no','--network','container:'+mysql,'--memory=3g','--cpus=2','--mount','type=volume,src='+vol+',dst=/work','--mount','type=volume,src='+source_volume+',dst=/input,readonly','-e','RELEASE_DISPOSABLE_TESTS=1','-e','RELEASE_CACHE_STORE='+CACHE_STORE,'-e','CACHE_STORE='+CACHE_STORE,'-e','CACHE_LIMITER='+CACHE_STORE,'-e','SESSION_DRIVER=database','-e','PYTHONDONTWRITEBYTECODE=1','-e','HOME=/work/home','-e','RELEASE_REPORT_DIR=/work/reports','-w','/work/release',image,'sleep','infinity');containers.append(app)
        docker('copy-runtime','exec',app,'bash','-o','pipefail','-ec',"mkdir -p /work/release /work/private /work/reports /work/home; cd /input/release; tar --exclude='*/__pycache__' --exclude='*.pyc' --exclude='*.pyo' --exclude=./storage --exclude=./test-results --exclude=./playwright-report --exclude='./bootstrap/cache/*.php' -cf - . | tar -xf - -C /work/release; mkdir -p /work/release/storage/framework/cache/data /work/release/storage/framework/sessions /work/release/storage/framework/views /work/release/storage/logs /work/release/storage/app/private /work/release/storage/app/public /work/release/bootstrap/cache; chown -R 1000:1000 /work")
        docker('copy-helper','cp',str(HERE/'linux-servers.py'),app+':/work/linux-servers.py')
        bindings={str(port):cap('port',mysql,str(port)+'/tcp').strip() for port in [18081,18082,18083]}
        assert all(re.fullmatch(r'127\.0\.0\.1:[1-9][0-9]{0,4}',v) for v in bindings.values())
        write(R/'resources.json',{'volume':vol,'network':net,'containers':containers,'bindings':bindings,'source_volume_read_only':source_volume,'cache_store':CACHE_STORE,'cache_limiter':CACHE_STORE,'session_driver':'database','redis_role':profile['redis_role'],'security_changes':False})
        for _ in range(150):
            ready=subprocess.run(['docker','exec',mysql,'mysqladmin','ping','-h127.0.0.1','-uroot','--password=security-test-only'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            if ready.returncode==0:break
            time.sleep(2)
        else:raise RuntimeError('MySQL readiness failed')
        handle=(R/'linux-manager.log').open('w',encoding='utf-8')
        manager=subprocess.Popen(['docker','exec','--user','1000:1000','-e','ENABLED_ORIGIN=http://'+bindings['18081'],'-e','DISABLED_ORIGIN=http://'+bindings['18082'],app,'python3','/work/linux-servers.py'],stdout=handle,stderr=subprocess.STDOUT)
        for _ in range(3600):
            if exists('/work/reports/ready.json'):break
            if manager.poll() is not None:raise RuntimeError('Server manager failed')
            time.sleep(1)
        else:raise RuntimeError('Server readiness failed')
        for name in ['fixture.json','source-before.json','handoff.json']:docker('copy-'+name,'cp',app+':/work/private/'+name,str(private/name))
        contract=json.loads((private/'handoff.json').read_text(encoding='utf-8'))
        # Source Git and built HTML/assets are separate byte identities.
        docker('archive-built-artifact','exec',app,'bash','-o','pipefail','-ec',"cd /work/release; tar --exclude='*/__pycache__' --exclude='*.pyc' --exclude='*.pyo' --exclude=./.git --exclude=./vendor --exclude='*/node_modules' --exclude=./storage --exclude=./test-results --exclude=./playwright-report --exclude='./bootstrap/cache/*.php' --exclude=./.env --exclude=./.env.testing -cf /work/private/artifact.tar .; sha256sum /work/private/artifact.tar > /work/reports/artifact-tar.sha256")
        docker('copy-built-artifact','cp',app+':/work/private/artifact.tar',str(private/'artifact.tar'))
        docker('copy-artifact-hash','cp',app+':/work/reports/artifact-tar.sha256',str(private/'artifact-tar.sha256'))
        artifact_sha=hashlib.sha256((private/'artifact.tar').read_bytes()).hexdigest()
        assert artifact_sha==(private/'artifact-tar.sha256').read_text(encoding='utf-8').split()[0]
        artifact=private/'artifact';artifact.mkdir()
        with tarfile.open(private/'artifact.tar') as archive:archive.extractall(artifact,filter='data')
        inventory=[{'path':str(x.relative_to(artifact)).replace('\\','/'),'sha256':hashlib.sha256(x.read_bytes()).hexdigest()} for x in sorted(artifact.rglob('*')) if x.is_file()]
        write(R/'artifact-manifest.json',{'source_git_manifest_sha256':backend['source_sha256'],'built_source_manifest_sha256':contract['candidate_source_sha256'],'transferred_archive_sha256':artifact_sha,'files':inventory})
        (artifact/'node_modules').symlink_to(ROOT/'node_modules',target_is_directory=True)
        (artifact/'tests/Deployment/styles-qa/node_modules').symlink_to(ROOT/'tests/Deployment/styles-qa/node_modules',target_is_directory=True)
        host_status=run('http-host',[sys.executable,str(HERE/'run-host.py'),'--candidate',str(artifact),'--dependency-root',str(ROOT),'--handoff',str(private),'--report',str(private/'browser'),'--expected-source-sha',contract['candidate_source_sha256'],'--chrome',str(a.chrome)],check=False)
        for path in (private/'browser').rglob('*'):
            if path.is_file() and path.name!='fixture.json':
                target=R/'http'/path.relative_to(private/'browser');target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(path,target)
        origin='http://'+bindings['18083']
        opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
        for _ in range(30):
            try:
                with opener.open(origin+'/styles/index.html',timeout=2) as response:
                    if response.status==200:break
            except OSError:time.sleep(1)
        else:raise RuntimeError('Styles server readiness failed')
        styles_status=run('styles-host',[sys.executable,str(HERE/'run-styles.py'),'--candidate',str(artifact),'--origin',origin,'--report',str(R/'styles'),'--chrome',str(a.chrome)],check=False)
        if host_status or styles_status:raise RuntimeError('Host browser contracts failed')
    except BaseException as e:
        error=type(e).__name__+': '+str(e)
    finally:
        if app in containers:
            docker('signal-stop','exec',app,'touch','/work/private/stop',check=False)
            if manager:
                try:manager.wait(timeout=45)
                except subprocess.TimeoutExpired:manager.terminate();manager.wait(timeout=10)
            if handle:handle.close()
            docker('collect-linux-evidence','cp',app+':/work/reports',str(R/'linux-evidence'),check=False)
        for name in reversed(containers):docker('stop-'+name.rsplit('-',1)[-1],'stop',name,check=False)

try:
    cleanup=json.loads((R/'linux-evidence/cleanup.json').read_text(encoding='utf-8'))
    http=json.loads((R/'http/host-verified-result.json').read_text(encoding='utf-8'))
    styles=json.loads((R/'styles/result.json').read_text(encoding='utf-8'))
    manifest=json.loads((B/'source-manifest.json').read_text(encoding='utf-8'))
    unchanged=all(hashlib.sha256((ROOT/x['path']).read_bytes()).hexdigest()==x['sha256'] for x in manifest['files'])
    checks={'backend_passed':backend['passed'],'host_http_9_59':http['status']=='passed' and http['scenarios']==9 and http['assertions']==59,'styles_91_catalog_law':styles['status']=='passed','all_owned_servers_exited':cleanup['all_exited'],'owned_database_removed':cleanup['database_dropped'],'container_sources_unchanged':cleanup['source_unchanged'],'host_source_bytes_match_backend':unchanged,'manager_success':manager is not None and manager.returncode==0,'no_orchestration_error':error is None,'all_commands_successful':all(x['exit_code']==0 for x in outcomes)}
except BaseException as e:
    checks={'complete_evidence':False};error=error or type(e).__name__+': '+str(e)
passed=all(checks.values())
write(R/'release-gate.json',{'passed':passed,'checks':checks,'source_git_manifest_sha256':backend['source_sha256'],'built_source_manifest_sha256':contract['candidate_source_sha256'] if 'contract' in locals() else None,'built_archive_sha256':artifact_sha if 'artifact_sha' in locals() else None,'commit':subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),'error':error,'scope':'Linux backend and sandboxed Ubuntu host Chrome; synthetic HTTP only; production/HTTPS readiness remains separate'})
sys.exit(0 if passed else 1)
