"""Owned Linux server lifecycle for host Chrome; no application edits."""
import sys,os,pathlib,json,subprocess,time,hashlib,urllib.request
ROOT=pathlib.Path('/work/release');PRIVATE=pathlib.Path('/work/private');REPORT=pathlib.Path('/work/reports')
sys.path.insert(0,str(ROOT/'tests/Deployment/linux-http'));import runner
PRIVATE.mkdir(exist_ok=True);REPORT.mkdir(exist_ok=True)
env=runner.environment();env.update(HOMOLOGATION_ROOT=str(ROOT),HOMOLOGATION_RUN=str(PRIVATE),HOMOLOGATION_WORKER='host-browser',HOMOLOGATION_BROWSER_REPORT=str(REPORT),PYTHONDONTWRITEBYTECODE='1')
processes=[];logs=[];created=False;steps=[]
def run(name,command):
 with (REPORT/(name+'.log')).open('w') as log:p=subprocess.run(command,cwd=ROOT,env=env,stdout=log,stderr=subprocess.STDOUT)
 steps.append({'name':name,'exit_code':p.returncode});(REPORT/'setup-steps.json').write_text(json.dumps(steps,indent=2))
 if p.returncode:raise RuntimeError(name+' failed')
admin="if(getenv('RELEASE_DISPOSABLE_TESTS')!=='1')exit(91);$pdo=new PDO('mysql:host=127.0.0.1;port=3306','root','security-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);"
before=None
try:
 run('create-owned-db',['php','-r',admin+"$pdo->exec('CREATE DATABASE fokus_synthetic CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');"]);created=True
 run('migrate-seed',runner.php()+['artisan','migrate:fresh','--seed','--force','--no-interaction'])
 run('seed-fixture',runner.php()+[str(ROOT/'tests/Deployment/linux-http/concurrency/fixture.php'),'seed'])
 before=runner.manifest();(PRIVATE/'source-before.json').write_text(json.dumps(before,indent=2))
 (REPORT/'source-before.json').write_bytes((PRIVATE/'source-before.json').read_bytes())
 origins={True:os.environ['ENABLED_ORIGIN'],False:os.environ['DISABLED_ORIGIN']}
 for enabled,port in [(True,18081),(False,18082)]:
  serverenv=dict(env,LAW_TASKS_ENABLED='true' if enabled else 'false',APP_URL=origins[enabled])
  log=(REPORT/('server-'+str(enabled).lower()+'.log')).open('w');logs.append(log)
  proc=subprocess.Popen(runner.php()+['-S','0.0.0.0:'+str(port),'-t',str(ROOT/'public'),str(ROOT/'tests/Deployment/linux-http/browser/router.php')],cwd=ROOT,env=serverenv,stdout=log,stderr=subprocess.STDOUT);processes.append(proc)
  opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
  for i in range(100):
   try:
    with opener.open('http://127.0.0.1:'+str(port)+'/api/csrf-token',timeout=2) as response:
     if response.status==200:break
   except OSError:time.sleep(.1)
  else:raise RuntimeError('Server not ready '+str(port))
 style_log=(REPORT/'styles-server.log').open('w');logs.append(style_log)
 style_code="import importlib.util; s=importlib.util.spec_from_file_location('styles_serve','/work/release/tools/styles-showcase/serve.py'); m=importlib.util.module_from_spec(s); s.loader.exec_module(m); m.ThreadingHTTPServer(('0.0.0.0',18083),m.Handler).serve_forever()"
 processes.append(subprocess.Popen(['python3','-c',style_code],cwd=ROOT,env=env,stdout=style_log,stderr=subprocess.STDOUT))
 handoff={'synthetic_only':True,'transport':'docker-cp-byte-exact','candidate_source_sha256':before['source_sha256'],'enabled_origin':origins[True],'disabled_origin':origins[False],'files':{n:{'sha256':hashlib.sha256((PRIVATE/n).read_bytes()).hexdigest()} for n in ['fixture.json','source-before.json']}}
 (PRIVATE/'handoff.json').write_text(json.dumps(handoff,indent=2));(REPORT/'handoff-proof.json').write_text(json.dumps({'handoff_sha256':hashlib.sha256((PRIVATE/'handoff.json').read_bytes()).hexdigest(),'files':handoff['files'],'candidate_source_sha256':before['source_sha256']},indent=2))
 (REPORT/'ready.json').write_text(json.dumps({'pids':[p.pid for p in processes],'enabled_origin':origins[True],'disabled_origin':origins[False],'source_sha256':before['source_sha256']}))
 end=time.time()+1800
 while not (PRIVATE/'stop').exists() and time.time()<end:
  if any(p.poll() is not None for p in processes):raise RuntimeError('Owned server exited early')
  time.sleep(1)
 if not (PRIVATE/'stop').exists():raise RuntimeError('Coordinator timeout; auto-cleanup')
finally:
 for p in processes:
  if p.poll() is None:
   p.terminate()
   try:p.wait(timeout=10)
   except subprocess.TimeoutExpired:p.kill();p.wait(timeout=10)
 for log in logs:log.close()
 if created:run('drop-owned-db',['php','-r',admin+"$pdo->exec('DROP DATABASE fokus_synthetic');"])
 after=runner.manifest();(REPORT/'source-after.json').write_text(json.dumps(after,indent=2))
 (REPORT/'cleanup.json').write_text(json.dumps({'owned_pids':[p.pid for p in processes],'owned_returncodes':[p.returncode for p in processes],'all_exited':all(p.poll() is not None for p in processes),'database_dropped':created,'source_unchanged':before==after if before else None},indent=2))
