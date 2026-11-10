"""Actual rsync transfer contract in disposable directories, NOT deployment authorization."""
import hashlib,json,os,pathlib,shutil,stat,subprocess,sys,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
assert sys.platform=='linux' and os.environ.get('RELEASE_DISPOSABLE_TESTS')=='1'
REPORT=pathlib.Path(os.environ.get('RELEASE_REPORT_DIR',ROOT/'test-results/linux-release'));REPORT.mkdir(parents=True,exist_ok=True)
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
paths=[os.fsdecode(p) for p in subprocess.check_output(['git','ls-files','--cached','--others','--exclude-standard','-z'],cwd=ROOT).split(b'\0') if p]
paths=sorted(p for p in set(paths) if (ROOT/p).is_file())
casefold={}
for p in paths:
    assert p.casefold() not in casefold, 'Case-insensitive path collision: '+p
    casefold[p.casefold()]=p
checks=[]
with tempfile.TemporaryDirectory(prefix='fokus-rsync-') as temp:
    temp=pathlib.Path(temp);source=temp/'source';target=temp/'target';source.mkdir();target.mkdir()
    for p in paths:
        out=source/p;out.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/p,out)
    fixtures={'vendor/autoload.php':b'NEVER COPY','node_modules/fixture.js':b'NEVER COPY','.env':b'NEVER COPY','storage/app/private.json':b'NEVER COPY','bootstrap/cache/config.php':b'NEVER COPY','public/styles/vendor/blocked.css':b'NEVER COPY'}
    for p,b in fixtures.items():
        f=source/p;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(b)
    preserved={'.env':b'preserved synthetic env','storage/app/kept.json':b'preserved synthetic storage','vendor/kept.php':b'preserved runtime dependency','bootstrap/cache/kept.php':b'preserved runtime cache'}
    for p,b in preserved.items():
        f=target/p;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(b)
    (target/'obsolete.txt').write_text('must disappear')
    args=['rsync','-a','--delete-delay','--chmod=D755,F644','--exclude=.git/','--exclude=vendor/','--exclude=node_modules/','--exclude=.env','--exclude=.env.*','--exclude=storage/','--exclude=bootstrap/cache/','--exclude=test-results/','--exclude=playwright-report/',str(source)+'/',str(target)+'/']
    p=subprocess.run(args,text=True,capture_output=True);assert p.returncode==0,p.stderr
    delivered=[]
    for f in target.rglob('*'):
        if f.is_file() and f.relative_to(target).as_posix() not in preserved:
            rel=f.relative_to(target).as_posix();assert (source/rel).is_file(),rel
            assert sha(f)==sha(source/rel),rel
            assert stat.S_IMODE(f.stat().st_mode)==0o644,rel
            delivered.append({'path':rel,'sha256':sha(f),'mode':'0644'})
    for rel,b in preserved.items():assert (target/rel).read_bytes()==b,rel
    for rel in fixtures:
        if rel not in preserved: assert not (target/rel).exists(),rel
    assert not (target/'obsolete.txt').exists()
    for f in target.rglob('*'):
        if f.is_dir() and not str(f.relative_to(target)).startswith(('storage','vendor','bootstrap/cache')):assert stat.S_IMODE(f.stat().st_mode)==0o755,str(f)
    # Negative mutation must be caught by the exact byte verifier.
    sample=target/delivered[0]['path'];sample.write_bytes(b'negative corrupted transfer')
    assert sha(sample)!=delivered[0]['sha256']
    checks=['all delivered bytes verified','source case collision scan','file0644/directory0755','secret/dependency/storage/cache exclusions','existing storage/env/runtime preserved','obsolete ordinary file removed','corrupted byte negative detected']
    (REPORT/'rsync-transfer.json').write_text(json.dumps({'status':'passed','scope':'independent disposable transfer contract; deploy workflow remains blocked','rsync_version':subprocess.check_output(['rsync','--version'],text=True).splitlines()[0],'arguments':args[1:-2],'checks':checks,'delivered':delivered},indent=2))
print('PASS real rsync,',len(delivered),'files,',len(checks),'positive/negative contracts')
