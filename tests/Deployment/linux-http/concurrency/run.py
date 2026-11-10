"""HTTP concurrency against dedicated synthetic MySQL. No migration/reset in this adapter."""
import base64,concurrent.futures,http.cookiejar,json,pathlib,socket,subprocess,sys,time,urllib.request,urllib.error,uuid
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]));import runner
from readiness import require_csrf_ready
HERE=pathlib.Path(__file__).resolve().parent
REPORT=runner.REPORT/'concurrency'
RUN=runner.REPORT/'runtime/concurrency'
def dump(path,data):path.write_text(json.dumps(data,ensure_ascii=False,indent=2),encoding='utf-8')
def freeport():
 with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
def wait(path,seconds=20):
 end=time.time()+seconds
 while not path.exists():
  if time.time()>end:raise RuntimeError('Timeout awaiting '+path.name)
  time.sleep(.03)
class Client:
 def __init__(self,port):self.base=f'http://127.0.0.1:{port}';self.opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.token=''
 def request(self,method,path,payload=None,headers=None):
  headers={'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':self.token,**(headers or {})}
  req=urllib.request.Request(self.base+path,data=json.dumps(payload).encode() if payload is not None else None,method=method,headers=headers)
  start=time.time()
  try:response=self.opener.open(req,timeout=30)
  except urllib.error.HTTPError as error:response=error
  raw=response.read().decode('utf-8');
  try:body=json.loads(raw)
  except ValueError:body={'unparsed':raw[:1500]}
  return {'status':response.status,'body':body,'start':start,'end':time.time()}
 def login(self,f):
  self.token=self.request('GET','/api/csrf-token')['body']['token']
  denied=self.request('POST','/api/auth/law-login',{'email':f['email'],'password':f['password'],'company_id':f['company'],'profile':'admin'},{'X-CSRF-TOKEN':''})
  assert denied['status']==419,denied
  result=self.request('POST','/api/auth/law-login',{'email':f['email'],'password':f['password'],'company_id':f['company'],'profile':'admin'})
  assert result['status']==200,result
  self.token=self.request('GET','/api/csrf-token')['body']['token']
def main():
 global RUN,REPORT
 runner.require_disposable()
 tag=time.strftime('%Y%m%d-%H%M%S')+'-'+uuid.uuid4().hex[:6]
 RUN=RUN/tag;REPORT=REPORT/tag
 REPORT.mkdir(parents=True,exist_ok=True);RUN.mkdir(parents=True,exist_ok=True)
 env=runner.environment('mysql');env.update(HOMOLOGATION_ROOT=str(runner.CANDIDATE),HOMOLOGATION_RUN=str(RUN),SESSION_DRIVER='database',SESSION_SECURE_COOKIE='false',LAW_TASKS_ENABLED='true');env.pop('HOMOLOGATION_AUTOLOAD',None)
 def php(args,label):
  process=subprocess.run(runner.php()+[str(HERE/'fixture.php')]+args,cwd=runner.CANDIDATE,env=env,capture_output=True,text=True)
  (REPORT/(label+'.log')).write_text(process.stdout+process.stderr,encoding='utf-8')
  if process.returncode:raise RuntimeError('Fixture '+label+' failed; see log')
  return process.stdout
 php(['seed'],'fixture');f=json.loads((RUN/'fixture.json').read_text(encoding='utf-8-sig'))
 servers=[];handles=[];results=[]
 def inspect(label):
  state=json.loads(php(['inspect'],'state-'+label));dump(REPORT/(label+'-state.json'),state);return state
 try:
  clients=[]
  for worker in ['a','b']:
   port=freeport();e={**env,'APP_URL':f'http://127.0.0.1:{port}','HOMOLOGATION_WORKER':worker};out=(REPORT/f'server-{worker}.log').open('w',encoding='utf-8');handles.append(out)
   p=subprocess.Popen(runner.php()+['-S',f'127.0.0.1:{port}','-t',str(runner.CANDIDATE/'public'),str(HERE/'router.php')],cwd=runner.CANDIDATE,env=e,stdout=out,stderr=subprocess.STDOUT,creationflags=0);servers.append(p)
   c=Client(port)
   for _ in range(100):
    try:
     with c.opener.open(urllib.request.Request(c.base+'/api/csrf-token',headers={'Accept':'application/json'}),timeout=30) as response:
      if require_csrf_ready(response):break
    except (OSError,urllib.error.URLError,ValueError):time.sleep(.1)
   else:raise RuntimeError('Server did not become healthy')
   c.login(f);clients.append(c)
  dump(RUN/'server-contract.json',{'ports':[int(c.base.rsplit(':',1)[1]) for c in clients],'pids':[p.pid for p in servers]})
  def pair(label,table,row,requests,expected):
   holderLog=(REPORT/(label+'-holder.log')).open('w',encoding='utf-8')
   holder=subprocess.Popen(runner.php()+[str(HERE/'hold-lock.php'),table,row,label],env=env,cwd=runner.CANDIDATE,stdout=holderLog,stderr=subprocess.STDOUT,creationflags=0)
   try:
    wait(RUN/(label+'-holder-ready'))
    with concurrent.futures.ThreadPoolExecutor(2) as pool:
     futures=[pool.submit(c.request,*request,{'X-Homologation-Barrier':label,'X-Homologation-Table':table}) for c,request in zip(clients,requests)]
     for worker in ['a','b']:wait(RUN/(label+'-'+worker+'-attempted'))
     time.sleep(.5);blocked=all(not future.done() for future in futures)
     (RUN/(label+'-release')).write_text(str(time.time()))
     responses=[future.result() for future in futures]
    attempts=[float((RUN/(label+'-'+w+'-attempted')).read_text()) for w in ['a','b']]
    release=float((RUN/(label+'-release')).read_text());statuses=sorted(x['status'] for x in responses)
    result={'scenario':label,'responses':responses,'statuses':statuses,'expected':expected,'attempted_at':attempts,'released_at':release,'both_blocked_before_release':blocked,'overlap_seconds':min(x['end'] for x in responses)-max(x['start'] for x in responses),'passed':statuses==expected and blocked and all(t<release for t in attempts) and all(x['end']>release for x in responses)}
    results.append(result);dump(REPORT/(label+'-http.json'),result)
    for w in ['a','b']:
     src=RUN/(label+'-'+w+'-sql.jsonl')
     (REPORT/src.name).write_bytes(src.read_bytes())
    return result
   finally:
    (RUN/(label+'-release')).touch();
    try:holder.wait(timeout=5)
    except subprocess.TimeoutExpired:holder.terminate();holder.wait(timeout=5)
    holderLog.close()
  def preview(names):
   csv='display_name,legal_nature,legal_name,cpf,cnpj,email,phone\n'+''.join(name+',pf,,,,,\n' for name in names)
   response=clients[0].request('POST','/api/law/contacts/import/preview',{'format':'csv','content_base64':base64.b64encode(csv.encode()).decode()})
   assert response['status']==200,response
   assert all(row['status']=='ready' for row in response['body']['rows']),response
   return response['body']['batch_id']
  commit=lambda batch,rows=[0]:('POST','/api/law/contacts/import/commit',{'batch_id':batch,'rows':rows,'confirmed':True})
  for label in ['manual-import','import-import','same-batch']:
   before=inspect(label+'-before');name='Synthetic '+label+' '+uuid.uuid4().hex[:7];batch=preview([name])
   first=('POST','/api/law/contacts',{'display_name':name,'legal_nature':'pf'}) if label=='manual-import' else commit(batch)
   second=commit(batch if label=='same-batch' else preview([name]))
   result=pair(label,'subscriptions',f['subscription'],[first,second],[201,409]);after=inspect(label+'-after')
   result['single_contact']=len(after['contacts'])==len(before['contacts'])+1 and sum(c['display_name'].casefold()==name.casefold() for c in after['contacts'])==1;result['passed'] &= result['single_contact'];dump(REPORT/(label+'-result.json'),result)
  before=inspect('capacity-before');php(['capacity',str(len(before['contacts'])+1)],'set-capacity')
  name='Synthetic capacity '+uuid.uuid4().hex[:6];batch=preview([name+' import']);result=pair('capacity','subscriptions',f['subscription'],[('POST','/api/law/contacts',{'display_name':name+' manual','legal_nature':'pf'}),commit(batch)],[201,422]);after=inspect('capacity-after');result['single_slot']=len(after['contacts'])==len(before['contacts'])+1;result['passed'] &= result['single_slot'];dump(REPORT/'capacity-result.json',result)
  php(['capacity','100'],'restore-capacity')
  # Batch begins ready, then one row is created elsewhere. Commit must add neither row.
  names=['Synthetic rollback '+uuid.uuid4().hex[:8], 'Synthetic rollback '+uuid.uuid4().hex[:8]];batch=preview(names)
  assert clients[0].request('POST','/api/law/contacts',{'display_name':names[1],'legal_nature':'pf'})['status']==201
  before=inspect('rollback-before');response=clients[1].request(*commit(batch,[0,1]));after=inspect('rollback-after');results.append({'scenario':'integral-rollback','status':response['status'],'passed':response['status']==409 and after['contacts']==before['contacts'],'assertions':2})
  # Same idempotency key with distinct payloads: exactly one initial create and one conflict.
  key=str(uuid.uuid4());result=pair('task-create','company_memberships',f['membership'],[('POST','/api/law/tasks',{'title':'Synthetic A','request_key':key}),('POST','/api/law/tasks',{'title':'Synthetic B','request_key':key})],[201,409]);after=inspect('task-create-after');task=next(t for t in after['tasks'] if t['request_key']==key);result['single_event']=len([e for e in after['events'] if e['law_task_id']==task['id']])==1;result['passed'] &= result['single_event']
  result=pair('task-update','law_tasks',task['id'],[('PATCH','/api/law/tasks/'+task['id'],{'title':'Synthetic Update A','version':1}),('PATCH','/api/law/tasks/'+task['id'],{'title':'Synthetic Update B','version':1})],[200,409]);after=inspect('task-update-after');result['single_new_event']=len([e for e in after['events'] if e['law_task_id']==task['id']])==2;result['passed'] &= result['single_new_event']
  url='/api/law/cases/'+f['cases']['public']+'/publications/'+f['publications']['public']
  for version in [0,1]:
   result=pair('publication-v'+str(version),'law_cases',f['cases']['public'],[('PATCH',url,{'status':'reviewed','version':version,'next_action':'Synthetic A'}),('PATCH',url,{'status':'reviewed','version':version,'next_action':'Synthetic B'})],[200,409]);after=inspect('publication-v'+str(version));reviews=[x for x in after['reviews'] if x['publication_key']==f['publications']['public']];result['one_review_correct_version']=len(reviews)==1 and reviews[0]['version']==version+1;result['single_case_event']=len([e for e in after['case_events'] if e['event_type']=='publication_review_updated'])==version+1;result['passed'] &= result['one_review_correct_version'] and result['single_case_event']
  dump(REPORT/'summary.json',{'status':'passed' if all(r['passed'] for r in results) else 'failed','scenarios':results,'scope':'Real independent HTTP workers, authentication and CSRF middleware, MySQL InnoDB REPEATABLE READ, shared Redis, controlled lock overlap. No application edits or disabled foreign keys.'})
  print('SCENARIOS',len(results),'PASS',sum(r['passed'] for r in results),'FAIL',sum(not r['passed'] for r in results))
  if not all(item['passed'] for item in results): raise RuntimeError('One or more concurrency scenarios failed; see summary.json')
 finally:
  for server in servers:
   server.terminate()
   try:server.wait(timeout=5)
   except subprocess.TimeoutExpired:server.kill();server.wait(timeout=5)
  for handle in handles:handle.close()
if __name__=='__main__':main()


