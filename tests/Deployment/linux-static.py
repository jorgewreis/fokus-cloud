"""Linux-only release checks; never reads external services or deploys."""
import hashlib, json, os, pathlib, shutil, stat, subprocess, sys, tempfile, time

ROOT = pathlib.Path(__file__).resolve().parents[2]
REPORT = pathlib.Path(os.environ.get('RELEASE_REPORT_DIR', ROOT / 'test-results/linux-release')).resolve()
REPORT.mkdir(parents=True, exist_ok=True)
assert sys.platform == 'linux', 'Real Linux required'
assert os.environ.get('RELEASE_DISPOSABLE_TESTS') == '1', 'Disposable runtime opt-in required'
BACKEND_ONLY = '--backend-only' in sys.argv
results = []
deferred = []

def run(name, args, cwd=ROOT, env=None):
    started = time.time()
    with (REPORT / (name + '.log')).open('w') as output:
        try:
            p = subprocess.run(args, cwd=cwd, env=env, stdout=output, stderr=subprocess.STDOUT,timeout=900 if 'playwright' in name else 300)
        except subprocess.TimeoutExpired:
            output.write('\nTIMEOUT: process terminated; this check did not pass.\n')
            p = subprocess.CompletedProcess(args,124)
    results.append({'name': name, 'exit_code': p.returncode, 'seconds': round(time.time()-started, 3)})
    (REPORT / 'static-results.json').write_text(json.dumps(results, indent=2))
    print(name, p.returncode, flush=True)
    return p.returncode

def sha(p): return hashlib.sha256(p.read_bytes()).hexdigest()

with tempfile.TemporaryDirectory(prefix='fokus-linux-case-') as tmp:
    p = pathlib.Path(tmp)
    (p/'Case').write_bytes(b'A'); (p/'case').write_bytes(b'B')
    assert (p/'Case').read_bytes() == b'A' and (p/'case').read_bytes() == b'B'
    mode = p/'mode'; mode.write_bytes(b'test'); mode.chmod(0o640)
    assert stat.S_IMODE(mode.stat().st_mode) == 0o640
    results.append({'name':'case-sensitive-and-posix-modes', 'exit_code':0})

run('psr4-strict', ['composer','dump-autoload','--optimize','--strict-psr','--no-scripts'])
run('php-lint', ['bash','-o','pipefail','-c',"find app bootstrap config database routes tests -type f -name '*.php' -not -path '*/node_modules/*' -print0 | xargs -0 -n1 php -l"])
run('law-build', ['node','tests/Deployment/font-cache.mjs','offline'])
run('law-assets-rsync', ['node','tests/Deployment/fokus-law-assets-rsync.mjs'], env={**os.environ,'CI':'true'})
run('seo', ['node','tools/sync-seo.mjs','--check'])
run('tokens', ['node','tools/validate-css-tokens.mjs'])
run('backoffice', ['node','tools/check-backoffice-ui-contract.mjs'])
os.environ['LAW_CHROME_PATH'] = subprocess.check_output(['node','-e',"process.stdout.write(require('playwright').chromium.executablePath())"],cwd=ROOT,text=True)
for name in ['law-contact-import-dom','law-contact-sensitive-details','law-portfolio-dom','law-process-confidentiality-dom','law-process-confidentiality-browser','law-tasks-dom']:
    ext = '.cjs'
    assert (ROOT/'tests/Frontend'/(name+ext)).is_file(), 'Required contract missing: '+name
    if BACKEND_ONLY and name in {'law-process-confidentiality-browser','law-tasks-dom'}:
        deferred.append(name); continue
    run(name, ['node','tests/Frontend/'+name+ext])
for script in ROOT.rglob('*.sh'):
    if not any(part in {'vendor','node_modules','.git'} for part in script.relative_to(ROOT).parts):
        run('shell-'+hashlib.sha256(str(script).encode()).hexdigest()[:12], ['bash','-n',str(script)])

# Styles uses its reviewed lock independently of the app's Playwright lock.
with tempfile.TemporaryDirectory(prefix='fokus-styles-') as temp:
    styles = pathlib.Path(temp)/'source'
    shutil.copytree(ROOT, styles, ignore=shutil.ignore_patterns('.git','vendor','node_modules','test-results','playwright-report','storage','.env','.env.*'))
    (styles/'node_modules').symlink_to(ROOT/'tests/Deployment/styles-qa/node_modules', target_is_directory=True)
    catalog = styles/'tools/styles-showcase/catalog.browser.mjs'
    catalog_before = sha(catalog)
    text = catalog.read_text()
    assert text.count("args:['--no-sandbox']") == 1
    catalog.write_text(text.replace("args:['--no-sandbox']", 'chromiumSandbox:true'))
    (REPORT/'styles-browser-adaptation.json').write_text(json.dumps({'source_sha256':catalog_before,'adapted_sha256':sha(catalog),'change':'Remove legacy no-sandbox flag and require Chromium sandbox; assertions unchanged'},indent=2))
    (styles/'tools/styles-showcase/linux.playwright.config.mjs').write_text("import base from './playwright.config.mjs';\nexport default {...base, use:{...base.use,launchOptions:{chromiumSandbox:true}}};\n")
    env = dict(os.environ, CI='true')
    for name, args in [
        ('deployment',['node','--test','tools/styles-showcase/deployment.test.mjs']),
        ('dom',['node','tools/styles-showcase/test-dom.mjs']),
        ('documentation',['node','--test','tools/styles-showcase/documentation.test.mjs']),
        ('visual-coherence',['node','--test','tools/styles-showcase/visual-coherence.test.mjs']),
        ('catalog',['node','--test','tools/styles-showcase/catalog.test.mjs']),
        ('catalog-dom',['node','tools/styles-showcase/catalog.dom.mjs']),
        ('catalog-browser',['node','tools/styles-showcase/catalog.browser.mjs']),
        ('playwright',['node',str(ROOT/'tests/Deployment/styles-qa/node_modules/@playwright/test/cli.js'),'test','--config','tools/styles-showcase/linux.playwright.config.mjs','--workers=2']),
    ]:
        if BACKEND_ONLY and name in {'catalog-browser','playwright'}:
            deferred.append('styles-'+name); continue
        run('styles-'+name, args, styles, env)
    archive = styles/'public/styles/examples/fokus-styles-examples.zip'
    before = sha(archive)
    code = run('styles-examples-build',['python3','tools/styles-showcase/build-examples.py'],styles,env)
    after = sha(archive)
    results.append({'name':'styles-examples-exact-bytes','exit_code':0 if code == 0 and before == after else 1,'before':before,'after':after})
    (REPORT/'styles-examples-identity.json').write_text(json.dumps(results[-1],indent=2))
    for folder in ['playwright-report','test-results']:
        if (styles/folder).exists(): shutil.copytree(styles/folder,REPORT/('styles-'+folder),dirs_exist_ok=True)

(REPORT/'static-results.json').write_text(json.dumps(results,indent=2))
(REPORT/'static-scope.json').write_text(json.dumps({'backend_only':BACKEND_ONLY,'deferred_to_required_host_gate':deferred,'release_passed':False},indent=2))
sys.exit(1 if any(x['exit_code'] for x in results) else 0)
