"""Portable synthetic-only environment for the preserved HTTP test harnesses."""
import hashlib, json, os, pathlib, subprocess, sys

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parents[2]
CANDIDATE = ROOT
REPORT = pathlib.Path(os.environ.get('RELEASE_REPORT_DIR', ROOT/'test-results/linux-release/http')).resolve()

def require_disposable():
    if sys.platform != 'linux' or os.environ.get('RELEASE_DISPOSABLE_TESTS') != '1':
        raise RuntimeError('Real Linux and RELEASE_DISPOSABLE_TESTS=1 required')

def environment(driver='mysql'):
    require_disposable()
    if driver != 'mysql': raise RuntimeError('HTTP suite requires synthetic MySQL')
    cache_store=os.environ.get('RELEASE_CACHE_STORE')
    if cache_store not in {'redis','database'}:raise RuntimeError('Explicit RELEASE_CACHE_STORE must be redis or database')
    env = {k:os.environ[k] for k in ['PATH','HOME','TMPDIR','PLAYWRIGHT_BROWSERS_PATH'] if k in os.environ}
    env.update(APP_ENV='testing', APP_DEBUG='false', APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', APP_URL='http://127.0.0.1', DB_CONNECTION='mysql', DB_HOST='127.0.0.1', DB_PORT='3306', DB_DATABASE='fokus_synthetic', DB_USERNAME='root', DB_PASSWORD='security-test-only', DB_URL='', DATABASE_URL='', CACHE_STORE=cache_store, CACHE_LIMITER=cache_store, SESSION_DRIVER='database', SESSION_SECURE_COOKIE='false', REDIS_CLIENT='phpredis', REDIS_HOST='127.0.0.1', REDIS_PORT='6379', REDIS_PASSWORD='', REDIS_DB='10', REDIS_CACHE_DB='11', REDIS_PREFIX='linux-http-', CACHE_PREFIX='linux-http-cache-', MAIL_MAILER='array', QUEUE_CONNECTION='sync', BROADCAST_CONNECTION='null', APP_MAINTENANCE_DRIVER='file', FILESYSTEM_DISK='local', LOG_CHANNEL='single', LOG_LEVEL='debug', BCRYPT_ROUNDS='4', LAW_TASKS_ENABLED='false', PULSE_ENABLED='false', TELESCOPE_ENABLED='false', NIGHTWATCH_ENABLED='false', RELEASE_REPORT_DIR=str(REPORT), RELEASE_DISPOSABLE_TESTS='1', RELEASE_CACHE_STORE=cache_store, DATAJUD_API_KEY='', MERCADO_PAGO_ACCESS_TOKEN='test-only', MERCADO_PAGO_WEBHOOK_SECRET='test-only', FOKUS_USAGE_INGESTION_SECRET='test-only')
    record_effective_configuration(env)
    return env

def php():
    return ['php','-d','date.timezone=UTC','-d','memory_limit=512M','-d','allow_url_fopen=0','-d','disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,exec,shell_exec,system,passthru,popen,proc_open']

def record_effective_configuration(env):
    """Read effective Laravel config before the owned synthetic DB is created.

    No providers or database connection are booted. Only three allowlisted
    cache/session driver names leave the PHP process.
    """
    if any((ROOT/name).exists() for name in ['.env','.env.testing','bootstrap/cache/config.php']):
        raise RuntimeError('Inherited environment/configuration is forbidden in the HTTP gate')
    expected={'cache.default':env['CACHE_STORE'],'cache.limiter':env['CACHE_LIMITER'],'session.driver':env['SESSION_DRIVER']}
    code=r'''require $argv[1].'/vendor/autoload.php';
$app=require $argv[1].'/bootstrap/app.php';
$app->bootstrapWith([Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,Illuminate\Foundation\Bootstrap\LoadConfiguration::class]);
$c=$app->make('config');
$result=['cache.default'=>$c->get('cache.default'),'cache.limiter'=>$c->get('cache.limiter'),'session.driver'=>$c->get('session.driver')];
foreach($result as $value){if(!in_array($value,['redis','database'],true)){fwrite(STDERR,'Unexpected synthetic cache/session configuration');exit(94);}}
echo json_encode($result,JSON_THROW_ON_ERROR);'''
    result=subprocess.run(php()+['-r',code,str(ROOT)],cwd=ROOT,env=env,capture_output=True,text=True)
    if result.returncode:raise RuntimeError('HTTP effective cache/session configuration probe failed')
    effective=json.loads(result.stdout)
    if not isinstance(effective,dict) or set(effective)!=set(expected) or any(value not in {'redis','database'} for value in effective.values()):
        raise RuntimeError('HTTP configuration probe returned values outside the allowlist')
    matches=effective==expected
    REPORT.mkdir(parents=True,exist_ok=True)
    proof={'selected':expected,'effective':effective,'matches':matches,'probe_scope':'Laravel configuration loaded without providers or database access','redis_role':'active-cache-and-limiter' if env['CACHE_STORE']=='redis' else 'installed-not-active-cache'}
    (REPORT/'http-effective-configuration-proof.json').write_text(json.dumps(proof,indent=2),encoding='utf-8',newline='\n')
    if not matches:raise RuntimeError('HTTP effective cache/session configuration differs from the selected profile')


def manifest():
    names = subprocess.check_output(['git','ls-files','--cached','--others','--exclude-standard','-z'],cwd=ROOT).decode().split('\0')
    rows=[]
    for name in sorted(set(names)):
        p=ROOT/name
        if not name or not p.is_file() or any(part in {'node_modules','vendor','.git','test-results','playwright-report','__pycache__','storage'} for part in p.relative_to(ROOT).parts):continue
        rows.append({'path':name,'sha256':hashlib.sha256(p.read_bytes()).hexdigest()})
    return {'files':rows,'source_sha256':hashlib.sha256(json.dumps([(x['path'],x['sha256']) for x in rows],separators=(',',':')).encode()).hexdigest()}
