"""Verify final real-browser evidence and bind it to the frozen source manifest."""
import hashlib,json,pathlib,sys,os
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]));import runner
ROOT=runner.ROOT
REPORT=runner.REPORT/'browser'
EXPECTED=json.loads((runner.REPORT/'source-before.json').read_text())['source_sha256']
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
main=json.loads((REPORT/'browser-result.json').read_text())
disabled=json.loads((REPORT/'feature-disabled-result.json').read_text())
launcher=json.loads((REPORT/'launcher-result.json').read_text())
manifest=json.loads((runner.REPORT/'source-before.json').read_text())
messages=[]
def check(value,label):
 if not value:raise RuntimeError(label)
 messages.append('PASS '+label)
check(manifest['source_sha256']==EXPECTED==main['candidate_source_sha256'],'final frozen source identity matches browser evidence')
for item in manifest['files']:
 check(sha(ROOT/item['path'])==item['sha256'],'source unchanged '+item['path'])
expected={'real-login-session-and-csrf','contacts-cancel-create-repeat-save','contacts-import-rejects-alphabetic-cpf-without-persistence','contacts-real-export-import-preview-confirm','portfolio-real-preview-confirm-preserves-existing','tasks-create-cancel-repeated-submit-edit-back-forward','publication-triage-real-ui-cas','confidentiality-authorized-vs-no-grant'}
check({t['name'] for t in main['tests']}==expected,'all eight real-browser scenarios executed')
check(all(t['status']=='passed' and t['assertions']>0 for t in main['tests']),'every real-browser scenario passed substantive assertions')
check(main['assertions']>0 and not main['consoleErrors'],'browser assertions passed without uncaught JS errors')
check(disabled['status']=='passed' and disabled['assertions']==4,'four real HTTP assertions prove tasks disabled')
check(len(launcher)==2 and all(t['exit_code']==0 for t in launcher),'both launcher stages exited0')
check(all(main['versions'].get(key) for key in ['chrome','node','playwright']),'Chrome Node Playwright versions recorded')
# Query process handles, never kill processes by name or touch unrelated services.
for row in launcher:
 try:os.kill(row['owned_server_pid'],0)
 except ProcessLookupError:check(True,'owned PHP server no longer exists '+str(row['owned_server_pid']))
 else:check(False,'owned PHP server still exists '+str(row['owned_server_pid']))
log=REPORT/'verified-browser.log'
parts=[(REPORT/'browser-tasks-true.log').read_text(),(REPORT/'browser-tasks-false.log').read_text(),json.dumps(main['versions']), '\n'.join(messages),'LIMIT: HTTP loopback with real auth/session/CSRF and Redis; no TLS/production Secure-cookie proof; no certificate warnings bypassed.','ADAPTATIONS: real contact navigation via shell; PHP dev-server portal index.html SCRIPT_NAME normalized only in external router to public/index.php; invalid/non-ready import selection rejects409 per controller contract; fresh synthetic CNJ per attempt avoids prior fixture collision. All failed harness attempts retained; no source/middleware/security bypass.']
log.write_text('\n'.join(parts),encoding='utf-8')
result={'status':'passed','exit_code':0,'assertions':main['assertions']+disabled['assertions'],'scenarios':len(main['tests'])+1,'command':'python preparation/homologation/browser/verify.py','log':'browser/verified-browser.log','log_sha256':sha(log),'candidate_source_sha256':EXPECTED,'versions':main['versions'],'publication_authorized':False,'attempts_preserved':'Each invocation requires a fresh report directory; failures are retained'}
(REPORT/'verified-result.json').write_text(json.dumps(result,indent=2))
(REPORT/'FINAL_BROWSER_FINISHED').write_text('All owned PHP processes checked exited; Chrome closed by scripts; fixture and Redis preserved.\n')
print(json.dumps(result))
