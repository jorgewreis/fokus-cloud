"""Disposable InnoDB lock prototype; not a Laravel or production test.

Requires COMPANY_IDEMPOTENCY_DISPOSABLE=1 and an already-installed MySQL 8
image ID in COMPANY_IDEMPOTENCY_MYSQL_IMAGE. Never connects to an existing DB.
"""
import os,subprocess,time,secrets,json,sys
if os.environ.get('COMPANY_IDEMPOTENCY_DISPOSABLE') != '1':
    raise SystemExit('Set COMPANY_IDEMPOTENCY_DISPOSABLE=1 only for this disposable probe.')
image = os.environ.get('COMPANY_IDEMPOTENCY_MYSQL_IMAGE', '')
if not image or not image.startswith('sha256:'):
    raise SystemExit('Provide the full sha256 ID of an existing MySQL 8 image in COMPANY_IDEMPOTENCY_MYSQL_IMAGE.')
name='fokus-idem-qa-'+secrets.token_hex(6)
base=['docker','exec','-i','-e','MYSQL_PWD=synthetic-idem-only',name,'mysql','-uroot','--batch','--skip-column-names','--unbuffered']
def run(args,**kw): return subprocess.run(args,text=True,capture_output=True,**kw)
def query(sql):
 p=run(base,input=sql+'\n',timeout=15)
 if p.returncode: raise RuntimeError('query failed: '+p.stderr[-700:])
 return p.stdout.strip()
def start(sql):
 p=subprocess.Popen(base,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,bufsize=1)
 p.stdin.write(sql+" SELECT CONCAT('READY:',CONNECTION_ID());\n");p.stdin.flush()
 line=p.stdout.readline().strip()
 if not line.startswith('READY:'): raise RuntimeError('missing readiness marker '+repr(line))
 return p,int(line.split(':')[1])
def contender(sql):
 p=subprocess.Popen(base,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
 p.stdin.write(sql+'\n');p.stdin.close();return p
def wait_for_lock(proc):
 for _ in range(50):
  if proc.poll() is not None: return False
  if query('SELECT COUNT(*) FROM performance_schema.data_lock_waits;') != '0': return True
  time.sleep(.1)
 return False
result={}; created=False
try:
 p=run(['docker','run','-d','--rm','--name',name,'--network','none','--tmpfs','/var/lib/mysql:rw,nosuid,size=256m','-e','MYSQL_ROOT_PASSWORD=synthetic-idem-only',image],timeout=30)
 if p.returncode: raise RuntimeError('container creation failed: '+p.stderr[-500:])
 created=True
 for _ in range(90):
  q=run(base,input='SELECT 1;\n',timeout=5)
  if q.returncode==0:break
  time.sleep(1)
 else:raise RuntimeError('mysql readiness timeout')
 query("CREATE DATABASE idemtest; CREATE TABLE idemtest.operations (admin_id VARCHAR(30) NOT NULL, key_hash CHAR(64) NOT NULL, request_mac CHAR(64) NOT NULL, company_id VARCHAR(30) NULL, PRIMARY KEY(admin_id,key_hash)) ENGINE=InnoDB; CREATE TABLE idemtest.companies (id VARCHAR(30) PRIMARY KEY, document_ref VARCHAR(30) UNIQUE) ENGINE=InnoDB")
 key='a'*64; mac='b'*64
 first,cid=start("START TRANSACTION; INSERT INTO idemtest.operations VALUES('admin1','"+key+"','"+mac+"',NULL);")
 second=contender("START TRANSACTION; INSERT INTO idemtest.operations VALUES('admin1','"+key+"','"+mac+"',NULL); COMMIT;")
 blocked=wait_for_lock(second)
 first.stdin.write("INSERT INTO idemtest.companies VALUES('company1','doc1'); UPDATE idemtest.operations SET company_id='company1' WHERE admin_id='admin1' AND key_hash='"+key+"'; COMMIT; SELECT 'COMMITTED';\n");first.stdin.flush();marker=first.stdout.readline().strip();first.stdin.close();first.wait(timeout=10);second.wait(timeout=10); err=second.stderr.read();stored=query("SELECT CONCAT(request_mac,':',company_id) FROM idemtest.operations WHERE admin_id='admin1' AND key_hash='"+key+"'; SELECT COUNT(*) FROM idemtest.companies;")
 result['concurrent_commit']={'second_waited':blocked,'first_marker':marker,'duplicate_rc':second.returncode,'duplicate_1062':'1062' in err,'stored':stored}
 if not(blocked and marker=='COMMITTED' and second.returncode!=0 and '1062' in err and stored==mac+':company1\n1'):raise RuntimeError('commit invariant failed')
 key2='c'*64
 first2,cid2=start("START TRANSACTION; INSERT INTO idemtest.operations VALUES('admin1','"+key2+"','"+mac+"',NULL);")
 second2=contender("START TRANSACTION; INSERT INTO idemtest.operations VALUES('admin1','"+key2+"','"+mac+"','company2'); INSERT INTO idemtest.companies VALUES('company2','doc2'); COMMIT; SELECT 'RECOVERED';")
 blocked2=wait_for_lock(second2);query('KILL CONNECTION '+str(cid2)+';');first2.stdin.close();second2.wait(timeout=10);out2=second2.stdout.read().strip();err2=second2.stderr.read();first2.wait(timeout=10);stored2=query("SELECT CONCAT(request_mac,':',company_id) FROM idemtest.operations WHERE admin_id='admin1' AND key_hash='"+key2+"'; SELECT COUNT(*) FROM idemtest.companies;")
 result['connection_killed']={'second_waited':blocked2,'first_rc':first2.returncode,'second_rc':second2.returncode,'second_result':out2,'stored':stored2}
 if not(blocked2 and second2.returncode==0 and out2=='RECOVERED' and stored2==mac+':company2\n2'):raise RuntimeError('recovery invariant failed: '+err2[-400:])
 query('ALTER TABLE idemtest.operations ADD COLUMN revoked BOOLEAN NOT NULL DEFAULT 0;')
 key3='d'*64
 query("INSERT INTO idemtest.operations (admin_id,key_hash,request_mac) VALUES ('admin1','"+key3+"','"+mac+"');")
 first3,_=start("START TRANSACTION; INSERT INTO idemtest.companies VALUES('company3','doc3'); UPDATE idemtest.operations SET company_id='company3' WHERE admin_id='admin1' AND key_hash='"+key3+"';")
 revoke=contender("START TRANSACTION; UPDATE idemtest.operations SET revoked=1 WHERE admin_id='admin1' AND key_hash='"+key3+"'; COMMIT; SELECT 'REVOKED';")
 blocked3=wait_for_lock(revoke)
 first3.stdin.write("COMMIT; SELECT 'COMMITTED';\n");first3.stdin.flush();marker3=first3.stdout.readline().strip();first3.stdin.close();first3.wait(timeout=10);revoke.wait(timeout=10)
 revokeout=revoke.stdout.read().strip();state3=query("SELECT CONCAT(company_id,':',revoked) FROM idemtest.operations WHERE admin_id='admin1' AND key_hash='"+key3+"'; SELECT COUNT(*) FROM idemtest.companies WHERE id='company3';")
 result['mutation_before_revocation']={'revocation_waited':blocked3,'first_marker':marker3,'revocation_result':revokeout,'stored':state3}
 if not(blocked3 and marker3=='COMMITTED' and revoke.returncode==0 and revokeout=='REVOKED' and state3=='company3:1\n1'):raise RuntimeError('mutation-before-revocation invariant failed')
 key4='e'*64
 query("INSERT INTO idemtest.operations (admin_id,key_hash,request_mac) VALUES ('admin1','"+key4+"','"+mac+"');")
 first4,_=start("START TRANSACTION; UPDATE idemtest.operations SET revoked=1 WHERE admin_id='admin1' AND key_hash='"+key4+"';")
 mutation=contender("START TRANSACTION; INSERT INTO idemtest.companies (id,document_ref) SELECT 'company4','doc4' FROM idemtest.operations WHERE admin_id='admin1' AND key_hash='"+key4+"' AND revoked=0; SELECT ROW_COUNT(); COMMIT;")
 blocked4=wait_for_lock(mutation)
 first4.stdin.write("COMMIT; SELECT 'REVOKED';\n");first4.stdin.flush();marker4=first4.stdout.readline().strip();first4.stdin.close();first4.wait(timeout=10);mutation.wait(timeout=10)
 mutationout=mutation.stdout.read().strip();state4=query("SELECT COUNT(*) FROM idemtest.companies WHERE id='company4';")
 result['revocation_before_mutation']={'mutation_waited':blocked4,'revocation_marker':marker4,'inserted_rows':mutationout,'company_count':state4}
 if not(blocked4 and marker4=='REVOKED' and mutation.returncode==0 and mutationout=='0' and state4=='0'):raise RuntimeError('revocation-before-mutation invariant failed')
 print(json.dumps(result,ensure_ascii=False))
finally:
 if created:
  p=run(['docker','rm','-f',name],timeout=20)
  if p.returncode:print('CLEANUP_FAILED '+name+' '+p.stderr,file=sys.stderr)
