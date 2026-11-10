"""One-shot Linux HTTP concurrency and browser gate. Owns only a new synthetic DB."""
import json, os, pathlib, subprocess, sys, time
import runner

CONCURRENCY_ONLY = '--concurrency-only' in sys.argv
runner.require_disposable()
if (runner.ROOT/'bootstrap/cache/config.php').exists():raise RuntimeError('Cached app configuration is not permitted in the disposable HTTP gate')
R=runner.REPORT
if R.exists() and any(R.iterdir()):raise RuntimeError('Use a new empty RELEASE_REPORT_DIR; prior attempts must remain intact')
R.mkdir(parents=True,exist_ok=True)
env=runner.environment()
os.environ['RELEASE_REPORT_DIR']=str(R)
stages=[]

def run(name, command, extra=None):
    start=time.time()
    with (R/(name+'.log')).open('w') as output:
        proc=subprocess.run(command,cwd=runner.ROOT,env={**env,**(extra or {})},stdout=output,stderr=subprocess.STDOUT)
    stages.append({'name':name,'exit_code':proc.returncode,'seconds':round(time.time()-start,3)})
    (R/'stages.json').write_text(json.dumps(stages,indent=2))
    print(name,proc.returncode,flush=True)
    if proc.returncode:raise RuntimeError(name+' failed; evidence retained')

# CREATE without IF NOT EXISTS is intentional: never reset an inherited database.
create="new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'security-test-only', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);"
admin="if(getenv('RELEASE_DISPOSABLE_TESTS')!=='1')exit(91);$pdo="+create
created=False
try:
    run('create-database',['php','-r',admin+"$pdo->exec('CREATE DATABASE fokus_synthetic CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');"])
    created=True
    run('migrate-seed',runner.php()+['artisan','migrate:fresh','--seed','--force','--no-interaction'])
    before=runner.manifest();(R/'source-before.json').write_text(json.dumps(before,indent=2))
    run('concurrency',[sys.executable,str(runner.HERE/'concurrency/run.py')])
    summaries=list((R/'concurrency').glob('*/summary.json'))
    if len(summaries)!=1:raise RuntimeError('Expected one owned concurrency result')
    run('concurrency-verify',[sys.executable,str(runner.HERE/'concurrency/verify-results.py'),str(summaries[0].parent)])
    fixtures=list((R/'runtime/concurrency').glob('*/fixture.json'))
    if len(fixtures)!=1:raise RuntimeError('Expected one owned synthetic fixture')
    concurrent=json.loads((summaries[0].parent/'verified-result.json').read_text())
    if concurrent['scenarios']!=9 or concurrent['assertions']!=94:raise RuntimeError('Concurrency coverage differs from reviewed 9/94')
    browser=None
    if not CONCURRENCY_ONLY:
        run('browser',[sys.executable,str(runner.HERE/'browser/launch.py'),str(fixtures[0])])
        run('browser-verify',[sys.executable,str(runner.HERE/'browser/verify.py')])
        browser=json.loads((R/'browser/verified-result.json').read_text())
        if browser['scenarios']!=9 or browser['assertions']!=59:raise RuntimeError('Browser coverage differs from reviewed 9/59')
    after=runner.manifest();(R/'source-after.json').write_text(json.dumps(after,indent=2))
    if before!=after:raise RuntimeError('Source identity changed during HTTP gate')
    (R/'result.json').write_text(json.dumps({'status':'concurrency-passed-browser-required' if CONCURRENCY_ONLY else 'passed','candidate_source_sha256':before['source_sha256'],'concurrency':concurrent,'browser':browser,'release_passed':False,'scope':'Real Linux loopback HTTP only, not HTTPS or production'},indent=2))
finally:
    if created:run('drop-owned-database',['php','-r',admin+"$pdo->exec('DROP DATABASE fokus_synthetic');"])
