import sys,pathlib,subprocess,json,os,types
from unittest.mock import patch
r=pathlib.Path(__file__).resolve().parent;sys.path.insert(0,str(r))
import generation_supervisor, generation_activation, derived_assets
checks=[]
for name in ('generation_activation.py','generation_supervisor.py'):
 p=subprocess.run([sys.executable,str(r/name),'--help'],capture_output=True,text=True,timeout=10)
 assert p.returncode==1 and 'usage:' in p.stdout and 'blocked' in p.stderr,(name,p.stderr)
 checks.append(name+' CLI imports and displays help; existing BaseException handler exits 1')
# Same module imported by supervisor's completion path. Real gate, no loader dependency.
sys.modules['publication_gate']=types.SimpleNamespace(context=lambda *a: (_ for _ in ()).throw(AssertionError('ambient gate used')))
before=list(sys.path)
with patch.object(derived_assets,'source_snapshot',return_value=('a'*40,None,None)),patch.dict(os.environ,{},clear=True):
 try:generation_activation.publication_gate_policy(pathlib.Path('a'*40),pathlib.Path('b'*40),False);raise AssertionError('unauthorized allowed')
 except RuntimeError as e:assert str(e)=='Manual dispatch required'
assert sys.path==before
checks.append('direct/supervisor shared activation rejects missing context; ignores ambient gate')
print(json.dumps(checks))

