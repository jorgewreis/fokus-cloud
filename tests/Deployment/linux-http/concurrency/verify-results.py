"""Independent offline verification of actual HTTP/SQL/state evidence, no database access."""
import hashlib,json,pathlib,sys
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]));import runner
ROOT=runner.ROOT
report=pathlib.Path(sys.argv[1]).resolve()
if not report.is_relative_to(runner.REPORT/'concurrency'):raise RuntimeError('Unsafe report path')
count=0
def check(value,label):
 global count
 if not value:raise RuntimeError(label)
 count+=1;print('PASS '+label)
def read(name):return json.loads((report/name).read_text(encoding='utf-8-sig'))
summary=read('summary.json')
check(summary['status']=='passed','runner status passed')
check(len(summary['scenarios'])==9,'nine scenarios executed')
targets={'manual-import':'subscriptions','import-import':'subscriptions','same-batch':'subscriptions','capacity':'subscriptions','task-create':'company_memberships','task-update':'law_tasks','publication-v0':'law_cases','publication-v1':'law_cases'}
for scenario in summary['scenarios']:
 name=scenario['scenario'];check(scenario['passed'],name+' aggregate checks passed')
 if name not in targets:continue
 check(scenario['statuses']==scenario['expected'],name+' exact expected statuses')
 release=scenario['released_at']
 check(scenario['both_blocked_before_release'] and all(t<release for t in scenario['attempted_at']),name+' both requests reached held lock before release')
 check(release-max(scenario['attempted_at'])>=.49,name+' held after both attempts for at least 0.49s')
 check(all(x['end']>release for x in scenario['responses']) and scenario['overlap_seconds']>0,name+' real HTTP response intervals overlap')
 for worker in ['a','b']:
  rows=[json.loads(line) for line in (report/(name+'-'+worker+'-sql.jsonl')).read_text().splitlines()]
  matches=[row for row in rows if 'for update' in row['sql'].lower() and '`'+targets[name]+'`' in row['sql']]
  before=next(row for row in matches if row['phase']=='before');after=next(row for row in matches if row['phase']=='after')
  check(before['at']<release<after['at'],name+' worker '+worker+' target SELECT truly crossed release')
  check(after['milliseconds']>=400,name+' worker '+worker+' SQL wait duration recorded')
 check('Released synthetic row lock' in (report/(name+'-holder.log')).read_text(),name+' holder released its own transaction')
before=read('rollback-before-state.json');after=read('rollback-after-state.json')
check(before['contacts']==after['contacts'],'failed multi-row import preserved all contact rows')
check(before['batches']==after['batches'],'failed multi-row import preserved import batches and expiration')
created=read('task-create-after-state.json');updated=read('task-update-after-state.json')
check(len(created['tasks'])==1,'same request key produced exactly one task')
check(len(created['events'])==1,'task create emitted exactly one event')
check(len(updated['tasks'])==1 and updated['tasks'][0]['version']==2,'task CAS produced exactly one version increment')
check(len(updated['events'])==2,'task update emitted exactly one additional event')
for version in [0,1]:
 state=read('publication-v'+str(version)+'-state.json')
 check(len(state['reviews'])==1 and state['reviews'][0]['version']==version+1,'publication CAS '+str(version)+' left one review at expected version')
 check(len([e for e in state['case_events'] if e['event_type']=='publication_review_updated'])==version+1,'publication CAS '+str(version)+' emitted one additional event')
snapshot=json.loads((runner.REPORT/'source-before.json').read_text())
for row in snapshot['files']:
 if hashlib.sha256((ROOT/row['path']).read_bytes()).hexdigest()!=row['sha256']:raise RuntimeError('Frozen source changed: '+row['path'])
check(True,'all frozen source bytes remain identical to manifest')
result={'status':'passed','assertions':count,'candidate_source_sha256':snapshot['source_sha256'],'scenarios':9,'network':'real HTTP loopback','authentication':'real sessions, two CSRF-negative 419 checks followed by authenticated requests','application_source_modified':False,'fixture_retained_locally':True}
(report/'verified-result.json').write_text(json.dumps(result,indent=2))
print('ASSERTIONS',count)
print('CANDIDATE_SOURCE_SHA256',snapshot['source_sha256'])
