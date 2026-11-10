"""Sandboxed host Chrome against Styles served by the disposable Linux runtime."""
import argparse, difflib, hashlib, json, os, pathlib, re, shutil, subprocess, sys, tempfile

p=argparse.ArgumentParser()
p.add_argument('--candidate',type=pathlib.Path,required=True)
p.add_argument('--origin',required=True)
p.add_argument('--report',type=pathlib.Path,required=True)
p.add_argument('--chrome',type=pathlib.Path,required=True)
a=p.parse_args(); root=a.candidate.resolve(); report=a.report.resolve()
assert os.environ.get('RELEASE_DISPOSABLE_TESTS')=='1'
assert re.fullmatch(r'http://127\.0\.0\.1:[1-9][0-9]{0,4}',a.origin)
assert a.chrome.is_file()
assert not report.exists() or not any(report.iterdir())
report.mkdir(parents=True,exist_ok=True)
def write(path,value):path.write_text(json.dumps(value,ensure_ascii=False,indent=2),encoding='utf-8',newline='\n')
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
outcomes=[];adapters=[]
with tempfile.TemporaryDirectory(prefix='fokus-host-styles-') as temporary:
    copy=pathlib.Path(temporary)/'source'
    shutil.copytree(root,copy,ignore=shutil.ignore_patterns('.git','node_modules','vendor','storage','test-results','playwright-report','.env','.env.*'))
    (copy/'node_modules').symlink_to(root/'tests/Deployment/styles-qa/node_modules',target_is_directory=True)
    app=root/'node_modules/playwright'
    assert json.loads((app/'package.json').read_text(encoding='utf-8'))['version']==json.loads((root/'package-lock.json').read_text(encoding='utf-8'))['packages']['node_modules/playwright']['version']
    assert json.loads((root/'tests/Deployment/styles-qa/node_modules/playwright/package.json').read_text(encoding='utf-8'))['version']=='1.57.0'
    def adapt(relative,transform):
        target=copy/relative; original=target.read_text(encoding='utf-8'); derived=transform(original)
        target.write_text(derived,encoding='utf-8',newline='\n')
        adapters.append({'path':relative,'original_sha256':sha(root/relative),'adapted_sha256':sha(target),'diff':''.join(difflib.unified_diff(original.splitlines(True),derived.splitlines(True)))})
    def catalog(text):
        start=text.index('const root = resolve(');end=text.index('try {\n const page',start)
        replacement="const base=process.env.STYLES_ORIGIN;\nconst browser=await chromium.launch({executablePath:process.env.HOST_CHROME,headless:true,chromiumSandbox:true});\n"
        text=text[:start]+replacement+text[end:]
        assert 'server.close();' in text
        text=text.replace('server.close();','')
        readiness="await child.waitForLoadState('load');"
        assert text.count(readiness)==1
        # Preserve the assertion and 30 second timeout. Offscreen frames can
        # suspend requestAnimationFrame; explicit polling was verified twice.
        return text.replace(readiness,readiness+"\n   await child.waitForFunction(() => document.readyState === 'complete' && typeof FokusStyles === 'object', null, {polling:100,timeout:30000});")
    adapt('tools/styles-showcase/catalog.browser.mjs',catalog)
    for relative in ['tests/Frontend/law-process-confidentiality-browser.cjs','tests/Frontend/law-tasks-dom.cjs']:
        def law(text):
            text=text.replace("require('@playwright/test')",'require('+json.dumps(str(root/'node_modules/@playwright/test'))+')')
            text=text.replace("require('playwright')",'require('+json.dumps(str(app))+')')
            text=text.replace("channel: 'chrome'",'executablePath: process.env.HOST_CHROME')
            return text
        adapt(relative,law)
    config="import base from './tools/styles-showcase/playwright.config.mjs';\nexport default {...base, webServer:undefined, testDir:'./tools/styles-showcase', workers:2, outputDir:"+json.dumps(str(report/'playwright-results'))+", reporter:[['list'],['json',{outputFile:"+json.dumps(str(report/'playwright-results.json'))+"}]], use:{...base.use,baseURL:process.env.STYLES_ORIGIN,launchOptions:{executablePath:process.env.HOST_CHROME,chromiumSandbox:true}}};\n"
    (copy/'host.playwright.config.mjs').write_text(config,encoding='utf-8',newline='\n')
    write(report/'adaptations.json',adapters)
    env=dict(os.environ,STYLES_ORIGIN=a.origin,HOST_CHROME=str(a.chrome),LAW_CHROME_PATH=str(a.chrome),CI='true')
    commands=[('styles-playwright',['node',str(root/'tests/Deployment/styles-qa/node_modules/@playwright/test/cli.js'),'test','--config','host.playwright.config.mjs']),('catalog-browser',['node','tools/styles-showcase/catalog.browser.mjs']),('law-confidentiality',['node','tests/Frontend/law-process-confidentiality-browser.cjs']),('law-tasks',['node','tests/Frontend/law-tasks-dom.cjs'])]
    for name,command in commands:
        with (report/(name+'.log')).open('w',encoding='utf-8') as log:
            result=subprocess.run(command,cwd=copy,env=env,stdout=log,stderr=subprocess.STDOUT,timeout=1200)
        outcomes.append({'name':name,'exit_code':result.returncode});write(report/'commands.json',outcomes)
    results=json.loads((report/'playwright-results.json').read_text(encoding='utf-8'))
    stats=results['stats']
    checks={'91_expected':stats['expected']==91,'no_unexpected':stats['unexpected']==0,'no_flaky':stats['flaky']==0,'no_skips':stats['skipped']==0,'no_runner_errors':not results.get('errors'),'all_commands_passed':all(x['exit_code']==0 for x in outcomes),'catalog_56_contracts':'PASS: 56 contracts' in (report/'catalog-browser.log').read_text(encoding='utf-8')}
    write(report/'result.json',{'status':'passed' if all(checks.values()) else 'failed','checks':checks,'stats':stats,'scope':'Sandboxed host Chrome against Linux Styles; HTTP only','host_platform':sys.platform,'chrome':subprocess.check_output([str(a.chrome),'--version'],text=True).strip(),'node':subprocess.check_output(['node','--version'],text=True).strip(),'playwright_styles':'1.57.0','playwright_app':json.loads((app/'package.json').read_text(encoding='utf-8'))['version'],'runner':{k:os.environ.get(k) for k in ['ImageOS','ImageVersion','RUNNER_OS','RUNNER_ARCH']}})
    sys.exit(0 if all(checks.values()) else 1)
