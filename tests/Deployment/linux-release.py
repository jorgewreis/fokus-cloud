"""Runs isolated Linux release validation locally or in GitHub Actions. Never deploys."""
import argparse,hashlib,json,os,pathlib,re,subprocess,tarfile,tempfile,time,uuid
ROOT=pathlib.Path(__file__).resolve().parents[2]
parser=argparse.ArgumentParser()
parser.add_argument('--image',default='fokus-law-linux-qa:20261107-node2413')
parser.add_argument('--report',type=pathlib.Path,default=ROOT/'test-results/linux-release-host')
parser.add_argument('--build',action='store_true')
parser.add_argument('--backend-only',action='store_true')
parser.add_argument('--profile',choices=['compatibility','target'],default='compatibility')
parser.add_argument('--dockerfile',type=pathlib.Path,default=ROOT/'tests/Deployment/linux-runtime/Dockerfile')
args=parser.parse_args();args.report=args.report.resolve();args.report.mkdir(parents=True,exist_ok=True)
assert os.environ.get('RELEASE_DISPOSABLE_TESTS')=='1','Explicit disposable test opt-in required'
tag='fokus-law-linux-'+uuid.uuid4().hex[:12]
volume=tag+'-files';network=tag+'-internal';setup=tag+'-setup';mysql=tag+'-mysql';redis=tag+'-redis';test=tag+'-tests'
profile=json.loads((ROOT/'tests/Deployment/linux-runtime/profiles.json').read_text(encoding='utf-8'))[args.profile]
MYSQL=profile['mysql_image'];REDIS=profile['redis_image']
CACHE_STORE=profile['cache_store']
assert CACHE_STORE in {'redis','database'}, 'Invalid profile cache store'
assert profile['cache_limiter']==CACHE_STORE and profile['session_driver']=='database', 'Profile cache/session mismatch'
assert all(isinstance(x,str) and re.fullmatch(r'[a-z0-9./_-]+@sha256:[0-9a-f]{64}',x) for x in [MYSQL,REDIS]), 'Reviewed official database/cache pins incomplete'
assert args.image==profile['runtime_image'], 'Runtime image differs from profile'
assert args.dockerfile.resolve()==(ROOT/profile['dockerfile']).resolve(), 'Dockerfile differs from profile'
containers=[];results=[]
def run(label,command,check=True):
    start=time.time()
    with (args.report/(label+'.log')).open('w',encoding='utf-8') as log:
        p=subprocess.run(command,stdout=log,stderr=subprocess.STDOUT)
    results.append({'name':label,'exit_code':p.returncode,'seconds':round(time.time()-start,3)})
    (args.report/'commands.json').write_text(json.dumps(results,indent=2))
    print(label,p.returncode,flush=True)
    if check and p.returncode:raise RuntimeError(label+' failed; see isolated report')
    return p.returncode
def docker(*argv):return ['docker',*argv]
def capture(*argv):return subprocess.check_output(docker(*argv),text=True)
before=capture('ps','-a','--format','{{.ID}} {{.Names}} {{.Status}}')
(args.report/'containers-before.txt').write_text(before)
sourcefiles=[os.fsdecode(x) for x in subprocess.check_output(['git','ls-files','--cached','--others','--exclude-standard','-z'],cwd=ROOT).split(b'\0') if x]
sourcefiles=sorted(p for p in set(sourcefiles) if (ROOT/p).is_file() and '__pycache__' not in pathlib.PurePosixPath(p).parts)
manifest=[{'path':p,'sha256':hashlib.sha256((ROOT/p).read_bytes()).hexdigest()} for p in sourcefiles]
identity=hashlib.sha256(json.dumps([(x['path'],x['sha256']) for x in manifest],separators=(',',':')).encode()).hexdigest()
(args.report/'source-manifest.json').write_text(json.dumps({'source_sha256':identity,'files':manifest},indent=2))
(args.report/'resources.json').write_text(json.dumps({'prefix':tag,'volume':volume,'network':network,'image':args.image,'profile':args.profile,'profile_versions':profile,'mysql':MYSQL,'redis':REDIS,'source_sha256':identity,'no_host_mounts':True,'no_docker_socket':True,'test_network':'internal-only shared loopback namespace'},indent=2))
try:
    if args.build:run('build-runtime',docker('build','--tag',args.image,'--file',str(args.dockerfile),str(args.dockerfile.parent)))
    for role,image in [('mysql',MYSQL),('redis',REDIS)]:run('pull-'+role,docker('pull',image))
    run('runtime-image-identities',docker('image','inspect',args.image,MYSQL,REDIS))
    run('create-volume',docker('volume','create','--label','fokus.release.disposable=true',volume))
    run('create-network',docker('network','create','--internal','--label','fokus.release.disposable=true',network))
    def create(name,image,networkmode,extra=None):
        memory='1g' if image==MYSQL else ('256m' if image==REDIS else '3g')
        cpu='1' if image==REDIS else '2'
        command=docker('run','-d','--name',name,'--restart=no','--cpus='+cpu,'--memory='+memory,'--network',networkmode)
        if extra:command+=extra
        command+=[image]
        if image==args.image:command+=['sleep','infinity']
        run('start-'+name.rsplit('-',1)[-1],command);containers.append(name)
    create(setup,args.image,'bridge',['--mount','type=volume,src='+volume+',dst=/work'])
    with tempfile.TemporaryDirectory(prefix='fokus-linux-input-') as temp:
        archive=pathlib.Path(temp)/'source.tar'
        with tarfile.open(archive,'w') as tar:
            for p in sourcefiles:tar.add(ROOT/p,arcname='release/'+p,recursive=False)
            # Local clone has no credentials; CI checkout must persist-credentials:false.
            tar.add(ROOT/'.git',arcname='release/.git')
            tar.add(args.report/'source-manifest.json',arcname='source-manifest.json')
        run('copy-input',docker('cp',str(archive),setup+':/work/source.tar'))
    run('materialize',docker('exec',setup,'sh','-ec','cd /work && tar -xf source.tar && mkdir -p reports release/storage/framework/cache release/storage/framework/sessions release/storage/framework/views release/storage/logs release/bootstrap/cache && chmod -R u+rwX release/storage release/bootstrap/cache'))
    run('verify-input',docker('exec','-w','/work/release',setup,'python3','-c',"import json,pathlib,hashlib; m=json.load(open('/work/source-manifest.json')); assert all(hashlib.sha256(pathlib.Path(x['path']).read_bytes()).hexdigest()==x['sha256'] for x in m['files']); print('Verified',len(m['files']),'source files')"))
    install='mkdir -p /work/browsers && composer install --no-interaction --prefer-dist --optimize-autoloader && npm ci --ignore-scripts && npm ci --prefix tests/Deployment/styles-qa --ignore-scripts'
    if not args.backend_only:install+=' && node node_modules/@playwright/test/cli.js install chromium && node tests/Deployment/styles-qa/node_modules/@playwright/test/cli.js install chromium'
    run('install-locked-dependencies',docker('exec','-e','PLAYWRIGHT_BROWSERS_PATH=/work/browsers','-e','APP_ENV=testing','-e','APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=','-e','APP_MAINTENANCE_DRIVER=file','-e','DB_CONNECTION=sqlite','-e','DB_DATABASE=:memory:','-e','CACHE_STORE=array','-e','SESSION_DRIVER=array','-e','QUEUE_CONNECTION=sync','-w','/work/release',setup,'sh','-ec',install))
    run('copy-runtime-evidence',docker('exec',setup,'sh','-ec','cp -a /opt/runtime-evidence /work/reports/runtime && du -sh /work/release/vendor /work/release/node_modules /work/release/tests/Deployment/styles-qa/node_modules /work/browsers > /work/reports/dependency-sizes.txt'))
    run('prepare-synthetic-ownership',docker('exec',setup,'sh','-ec','mkdir -p /work/home && chown -R 1000:1000 /work'))
    run('stop-download-environment',docker('stop',setup))
    create(mysql,MYSQL,network,['-e','MYSQL_ROOT_PASSWORD=security-test-only','-e','MYSQL_DATABASE=fokuscloud_security'])
    create(redis,REDIS,'container:'+mysql)
    for attempt in range(90):
        p=subprocess.run(docker('exec',mysql,'mysqladmin','ping','-h127.0.0.1','-uroot','--password=security-test-only'),stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        if p.returncode==0:break
        time.sleep(2)
    else:raise RuntimeError('Disposable MySQL did not start')
    env={'RELEASE_DISPOSABLE_TESTS':'1','RELEASE_REPORT_DIR':'/work/reports','PLAYWRIGHT_BROWSERS_PATH':'/work/browsers','CI':'true','APP_ENV':'testing','APP_DEBUG':'false','APP_KEY':'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=','DB_CONNECTION':'mysql','DB_HOST':'127.0.0.1','DB_PORT':'3306','DB_DATABASE':'fokuscloud_security','DB_USERNAME':'root','DB_PASSWORD':'security-test-only','REDIS_HOST':'127.0.0.1','REDIS_PORT':'6379','CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync','MAIL_MAILER':'array','BCRYPT_ROUNDS':'4','LAW_TASKS_ENABLED':'false'}
    env.update(HOME='/work/home',PYTHONDONTWRITEBYTECODE='1',RELEASE_CACHE_STORE=CACHE_STORE,CACHE_STORE=CACHE_STORE,CACHE_LIMITER=CACHE_STORE,SESSION_DRIVER='database')
    extra=['--mount','type=volume,src='+volume+',dst=/work','--user','1000:1000','-w','/work/release']
    for key,value in env.items():extra+=['-e',key+'='+value]
    create(test,args.image,'container:'+mysql,extra)
    run('namespace-isolation',docker('exec',test,'python3','-c',"import socket; s=socket.socket(); s.settimeout(2); assert s.connect_ex(('1.1.1.1',443)) != 0; print('Outbound connection blocked; loopback services only')"))
    gates=[]
    for label,script,options in [('static','linux-static.py',['--backend-only'] if args.backend_only else []),('rsync','linux-rsync.py',[]),('suite-sqlite','linux-release-suite.py',['--driver','sqlite']),('suite-mysql','linux-release-suite.py',['--driver','mysql']),('upgrade-sqlite','linux-upgrade.py',['--driver','sqlite']),('upgrade-mysql','linux-upgrade.py',['--driver','mysql'])]:
        gates.append(run(label,docker('exec',test,'python3','tests/Deployment/'+script,*options),check=False))
    if (ROOT/'tests/Deployment/linux-http/run-all.py').is_file():
        gates.append(run('http',docker('exec','-e','RELEASE_REPORT_DIR=/work/reports/http',test,'python3','tests/Deployment/linux-http/run-all.py',*(['--concurrency-only'] if args.backend_only else [])),check=False))
    else:raise RuntimeError('HTTP release harness missing; cannot mark gate passed')
    gates.append(run('application-canary-scan',docker('exec',test,'node','tools/check-security-canaries.mjs'),check=False))
    gates.append(run('release-canary-scan',docker('exec',test,'python3','tests/Deployment/scan-release-canaries.py','--root','/work/release/storage/logs','--root','/work/reports','--output','/work/reports/security-canary-scan.json'),check=False))
    run('collect-evidence',docker('cp',test+':/work/reports',str(args.report/'container-evidence')))
    (args.report/'gate.json').write_text(json.dumps({'passed':all(x==0 for x in gates),'source_sha256':identity,'gate_exit_codes':gates,'backend_only':args.backend_only,'release_passed':False,'scope':'Backend validation only; host-browser aggregate is mandatory; no deploy or HTTPS proof'},indent=2))
    if any(gates):raise RuntimeError('Linux gate failed; inspect per-stage evidence')
finally:
    for name in reversed(containers):run('stop-'+name.rsplit('-',1)[-1],docker('stop',name),check=False)
    (args.report/'containers-after.txt').write_text(capture('ps','-a','--format','{{.ID}} {{.Names}} {{.Status}}'))
    # Leave only stopped disposable containers and exclusive volume for auditable recovery.
    # Cleanup is a separately scoped step after evidence is collected.
