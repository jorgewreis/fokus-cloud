"""Own only two loopback PHP processes and a sandboxed Chrome run; never migrate/reset."""
import argparse,json,pathlib,socket,subprocess,sys,time,urllib.request,urllib.error
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]))
import runner
HERE=pathlib.Path(__file__).resolve().parent
parser=argparse.ArgumentParser();parser.add_argument('fixture',type=pathlib.Path);parser.add_argument('--report',type=pathlib.Path,default=runner.REPORT/'browser');args=parser.parse_args()
runner.require_disposable()
if not (runner.REPORT/'source-before.json').is_file():raise RuntimeError('Missing candidate manifest')
if not args.fixture.is_file():raise RuntimeError('Missing synthetic fixture')
args.report.mkdir(parents=True,exist_ok=True)
env=runner.environment('mysql');env.update(HOMOLOGATION_ROOT=str(runner.CANDIDATE),HOMOLOGATION_RUN=str(args.fixture.parent),HOMOLOGATION_WORKER='browser',HOMOLOGATION_BROWSER_REPORT=str(args.report),SESSION_DRIVER='database',SESSION_SECURE_COOKIE='false');env.pop('HOMOLOGATION_AUTOLOAD',None)
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
outcomes=[]
for enabled,script in [('true','run.cjs'),('false','flag-disabled.cjs')]:
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 origin=f'http://127.0.0.1:{port}';serverenv={**env,'APP_URL':origin,'LAW_TASKS_ENABLED':enabled}
 log=(args.report/f'server-tasks-{enabled}.log').open('w',encoding='utf-8')
 server=subprocess.Popen(runner.php()+['-S',f'127.0.0.1:{port}','-t',str(runner.CANDIDATE/'public'),str(HERE/'router.php')],cwd=runner.CANDIDATE,env=serverenv,stdout=log,stderr=subprocess.STDOUT,creationflags=0)
 try:
  for _ in range(100):
   try:
    with opener.open(origin+'/api/csrf-token',timeout=2) as response:
     if response.status==200:break
   except (OSError,urllib.error.URLError):time.sleep(.1)
  else:raise RuntimeError('Loopback PHP server did not become ready')
  command=['node',str(HERE/script),origin,str(args.fixture),str(args.report)]
  with (args.report/f'browser-tasks-{enabled}.log').open('w',encoding='utf-8') as output:
   result=subprocess.run(command,cwd=runner.CANDIDATE,env=serverenv,stdout=output,stderr=subprocess.STDOUT)
  outcomes.append({'tasks_enabled':enabled=='true','origin':origin,'owned_server_pid':server.pid,'exit_code':result.returncode,'script':script})
 finally:
  if server.poll() is None:
   server.terminate()
   try:server.wait(timeout=10)
   except subprocess.TimeoutExpired:server.kill();server.wait(timeout=10)
  log.close()
(args.report/'launcher-result.json').write_text(json.dumps(outcomes,indent=2))
print(json.dumps(outcomes));sys.exit(1 if any(o['exit_code'] for o in outcomes) else 0)
