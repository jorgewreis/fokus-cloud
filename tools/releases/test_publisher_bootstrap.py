import importlib.util,pathlib,shutil,tempfile,sys,json,os
from unittest.mock import patch
root=pathlib.Path(__file__).resolve().parents[2];checks=[]
def load(p):
 s=importlib.util.spec_from_file_location('entry_candidate',p);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
m=load(root/'tools/releases/publisher_entry.py'); before=list(sys.path);p=m.load_dependencies();assert before==sys.path;checks.append('loaded pinned gate without sys.path changes')
a=sys.modules['generation_activation'];gate=sys.modules['publication_gate']; derived=sys.modules['derived_assets']
# Only repository snapshot is synthetic here. Context enforcement is the real GitHub gate.
with patch.object(derived,'source_snapshot',return_value=('a'*40,None,None)),patch.dict(os.environ,{},clear=True),patch.object(gate.urllib.request,'urlopen',side_effect=AssertionError('network forbidden')):
 try:a.publication_gate_policy(pathlib.Path('a'*40),pathlib.Path('b'*40),False);raise AssertionError('missing auth allowed')
 except RuntimeError as e:assert str(e)=='Manual dispatch required'
assert before==sys.path;checks.append('real activation-to-GitHub context gate rejects missing auth without network')
for n in m.DEPENDENCY_DIGESTS:sys.modules.pop(n,None)
with tempfile.TemporaryDirectory() as td:
 d=pathlib.Path(td)/'tree';(d/'tools').mkdir(parents=True);shutil.copytree(root/'tools/releases',d/'tools/releases');(d/'deploy').mkdir();shutil.copyfile(root/'deploy/publication_gate.py',d/'deploy/publication_gate.py')
 entry=d/'tools/releases/publisher_entry.py'; f=d/'deploy/publication_gate.py'
 f.write_text(f.read_text()+'\nraise RuntimeError("MALICIOUS")\n')
 try:load(entry).load_dependencies();raise AssertionError('tamper accepted')
 except ValueError as e:assert str(e)=='dependency_mismatch'
 assert not any(n in sys.modules for n in m.DEPENDENCY_DIGESTS);checks.append('gate tamper rejected before any imports')
 shutil.copyfile(root/'deploy/publication_gate.py',f)
 alias=entry.with_name('alias.py');alias.symlink_to(entry)
 try:load(alias).load_dependencies();raise AssertionError('launcher symlink accepted')
 except ValueError as e:assert str(e)=='launcher_symlink'
 checks.append('launcher-file symlink rejected')
 sys.modules['publication_gate']=object()
 try:load(entry).load_dependencies();raise AssertionError('preload accepted')
 except ValueError as e:assert str(e)=='dependency_already_loaded'
 sys.modules.pop('publication_gate');checks.append('preloaded gate rejected')
print(json.dumps({'passed':checks}))

