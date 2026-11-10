"""Run unchanged browser assertions against explicitly handed-off Linux PHP servers.

Does not start containers/servers or modify the release. No browser security bypass.
"""
import argparse, difflib, hashlib, json, os, pathlib, re, shutil, subprocess, sys, time

parser=argparse.ArgumentParser()
parser.add_argument('--candidate',type=pathlib.Path,required=True)
parser.add_argument('--dependency-root',type=pathlib.Path,required=True)
parser.add_argument('--handoff',type=pathlib.Path,required=True)
parser.add_argument('--report',type=pathlib.Path,required=True)
parser.add_argument('--expected-source-sha',required=True)
parser.add_argument('--chrome',type=pathlib.Path,default=pathlib.Path(shutil.which('google-chrome') or shutil.which('chromium') or r'C:\Program Files\Google\Chrome\Application\chrome.exe'))
args=parser.parse_args()
if sys.platform not in {'linux','win32'}:raise RuntimeError('Supported host is Linux or Windows')
if os.environ.get('RELEASE_DISPOSABLE_TESTS')!='1':raise RuntimeError('Explicit disposable-test opt-in required')
root=args.candidate.resolve(); handoff=args.handoff.resolve(); report=args.report.resolve(); dependencies=args.dependency_root.resolve()
if not args.chrome.is_file():raise RuntimeError('Installed Chrome executable absent')
if report.exists() and any(report.iterdir()):raise RuntimeError('Use a fresh report directory for every attempt')
report.mkdir(parents=True,exist_ok=True)

def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def write(p,value):p.write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n',encoding='utf-8',newline='\n')
contract=json.loads((handoff/'handoff.json').read_text(encoding='utf-8'))
if contract.get('synthetic_only') is not True or contract.get('transport')!='docker-cp-byte-exact':raise RuntimeError('Synthetic handoff contract missing')
if not re.fullmatch('[a-f0-9]{64}',args.expected_source_sha):raise RuntimeError('Expected immutable source hash required')
for name in ['fixture.json','source-before.json']:
    if sha(handoff/name)!=contract['files'][name]['sha256']:raise RuntimeError('Transferred file hash differs: '+name)
manifest=json.loads((handoff/'source-before.json').read_text(encoding='utf-8'))
if manifest['source_sha256']!=args.expected_source_sha or contract['candidate_source_sha256']!=args.expected_source_sha:raise RuntimeError('Unexpected Linux candidate identity')

def verify_source():
    for row in manifest['files']:
        p=(root/row['path']).resolve()
        if not p.is_relative_to(root) or not p.is_file() or sha(p)!=row['sha256']:raise RuntimeError('Host release differs from Linux manifest: '+row['path'])
verify_source()
for key in ['enabled_origin','disabled_origin']:
    if not re.fullmatch(r'http://127\.0\.0\.1:[1-9][0-9]{0,4}',contract[key]):raise RuntimeError('Only explicit IPv4 loopback HTTP origins are accepted')
if contract['enabled_origin']==contract['disabled_origin']:raise RuntimeError('Use independent servers for true/false task modes')
expected_version=json.loads((root/'package-lock.json').read_text(encoding='utf-8'))['packages']['node_modules/playwright']['version']
actual_version=json.loads((dependencies/'node_modules/playwright/package.json').read_text(encoding='utf-8'))['version']
if expected_version!=actual_version:raise RuntimeError('Host Playwright differs from the release lock')
(report/'source-before.json').write_bytes((handoff/'source-before.json').read_bytes())
(report/'fixture.json').write_bytes((handoff/'fixture.json').read_bytes())
write(report/'handoff-received.json',{'contract':contract,'handoff_sha256':sha(handoff/'handoff.json'),'chrome_path':str(args.chrome),'playwright_version':actual_version})

outcomes=[];adaptations=[]
for enabled,name,key in [(True,'run.cjs','enabled_origin'),(False,'flag-disabled.cjs','disabled_origin')]:
    source=root/'tests/Deployment/linux-http/browser'/name
    original=source.read_text(encoding='utf-8');text=original
    for needle,replacement in [
        ("path.resolve(__dirname, '../../../..')",json.dumps(str(root))),
        ("path.resolve(__dirname,'../../../..')",json.dumps(str(root))),
        ("path.join(root, 'package.json')",json.dumps(str(dependencies/'package.json'))),
        ("path.join(root,'package.json')",json.dumps(str(dependencies/'package.json'))),
        ('chromium.launch({headless:true,chromiumSandbox:true})','chromium.launch({executablePath:'+json.dumps(str(args.chrome))+',headless:true,chromiumSandbox:true})'),
        ('Lockfile Playwright Chromium on Linux, chromiumSandbox:true','Installed host Chrome via lockfile Playwright, chromiumSandbox:true; PHP Linux'),
    ]:text=text.replace(needle,replacement)
    if 'executablePath:' not in text or 'chromiumSandbox:true' not in text or 'chromiumSandbox:false' in text:raise RuntimeError('Expected sandbox-preserving adapter did not match')
    derived=report/name;derived.write_text(text,encoding='utf-8',newline='\n')
    adaptations.append({'source':str(source.relative_to(root)),'source_sha256':sha(source),'adapter_sha256':sha(derived),'diff':''.join(difflib.unified_diff(original.splitlines(True),text.splitlines(True),fromfile='release/'+name,tofile='host/'+name))})
    write(report/'adaptations.json',adaptations)
    env={k:os.environ[k] for k in ['PATH','SYSTEMROOT','WINDIR','TEMP','TMP','USERPROFILE','LOCALAPPDATA','APPDATA'] if k in os.environ}
    env['RELEASE_REPORT_DIR']=str(report)
    start=time.time()
    with (report/('browser-tasks-'+str(enabled).lower()+'.log')).open('w',encoding='utf-8') as log:
        p=subprocess.run(['node',str(derived),contract[key],str(report/'fixture.json'),str(report)],cwd=root,env=env,stdout=log,stderr=subprocess.STDOUT)
    outcomes.append({'tasks_enabled':enabled,'origin':contract[key],'exit_code':p.returncode,'seconds':round(time.time()-start,3),'script':name})
    write(report/'host-launcher-result.json',outcomes)

verify_source()
main=json.loads((report/'browser-result.json').read_text(encoding='utf-8')); disabled=json.loads((report/'feature-disabled-result.json').read_text(encoding='utf-8'))
expected={'real-login-session-and-csrf','contacts-cancel-create-repeat-save','contacts-import-rejects-alphabetic-cpf-without-persistence','contacts-real-export-import-preview-confirm','portfolio-real-preview-confirm-preserves-existing','tasks-create-cancel-repeated-submit-edit-back-forward','publication-triage-real-ui-cas','confidentiality-authorized-vs-no-grant'}
checks={
 'source_identity':main['candidate_source_sha256']==args.expected_source_sha,
 'eight_browser_scenarios':{t['name'] for t in main['tests']}==expected,
 'substantive_assertions':all(t['status']=='passed' and t['assertions']>0 for t in main['tests']),
 'no_uncaught_js':not main['consoleErrors'],
 'disabled_four_assertions':disabled['status']=='passed' and disabled['assertions']==4,
 'reviewed_59_assertions':main['assertions']+disabled['assertions']==59,
 'two_processes_successful':len(outcomes)==2 and all(x['exit_code']==0 for x in outcomes),
 'versions_recorded':all(main['versions'].get(k) for k in ['chrome','node','playwright']),
}
passed=all(checks.values())
write(report/'host-verified-result.json',{'status':'passed' if passed else 'failed','scenarios':len(main['tests'])+1,'assertions':main['assertions']+disabled['assertions'],'checks':checks,'candidate_source_sha256':args.expected_source_sha,'versions':main['versions'],'server_cleanup':'Pending Linux coordinator proof; Host adapter does not inspect or kill container PIDs','scope':'Linux PHP/MySQL/Redis over published host IPv4 loopback HTTP; real sandboxed host Chrome; no HTTPS proof'})
print(json.dumps({'status':'passed' if passed else 'failed','assertions':main['assertions']+disabled['assertions'],'server_cleanup':'Linux coordinator must separately verify owned processes exited'}))
sys.exit(0 if passed else 1)
