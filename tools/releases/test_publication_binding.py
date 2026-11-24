import sys,json,pathlib,hashlib
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parent))
import test_generation_activation as t
x=t.Gate();x.setUp()
try:
 x.ready();p=x.base/'scheduler-ready.json';data=json.loads(p.read_text());new=t.a.source_binding()
 assert data['source_binding']==new
 # Binding of reviewed baseline 9e812769; not a production attestation.
 old='e4de25ee3275861fd9ecf41d67fcce82a72494021c048ab3b3ec3f6ecf7ca9ac:36d4dc7433f3ece55265a96ecb8f66026754ab3f21447ee36d607f33f6a31160'
 assert old!=new
 data['source_binding']=old;p.write_text(json.dumps(data));x.rejected()
 print(json.dumps({'fresh_binding':new,'old_binding':old,'new_proof_generated':True,'old_proof_rejected':True,'pointer_unchanged':True,'scope':'isolated fixture; no production attestation'}))
finally:x.doCleanups()
