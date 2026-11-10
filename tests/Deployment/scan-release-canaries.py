"""Fail closed on raw synthetic security canaries; never print their values."""
import argparse,json,pathlib,sys
ROOT=pathlib.Path(__file__).resolve().parents[2]
p=argparse.ArgumentParser();p.add_argument('--root',type=pathlib.Path,action='append',required=True);p.add_argument('--output',type=pathlib.Path,required=True)
a=p.parse_args()
canaries=json.loads((ROOT/'tests/fixtures/security-log-canaries.json').read_text(encoding='utf-8'))
assert canaries and all(isinstance(x,str) and x for x in canaries.values())
needles=[x.encode('utf-8') for x in canaries.values()]
scanned=0;found=[];errors=[]
for root in a.root:
    if not root.exists():continue
    for file in sorted(root.rglob('*')) if root.is_dir() else [root]:
        if not file.is_file():continue
        try:
            if file.is_symlink():raise RuntimeError('Unexpected report symlink')
            data=file.read_bytes();scanned+=1
            if any(x in data for x in needles):found.append(str(file.relative_to(root)) if root.is_dir() else file.name)
        except (OSError,RuntimeError):errors.append(file.name)
result={'passed':not found and not errors,'files_scanned':scanned,'files_with_raw_canaries':found,'read_errors':errors,'values_printed':False}
a.output.parent.mkdir(parents=True,exist_ok=True)
a.output.write_text(json.dumps(result,indent=2),encoding='utf-8',newline='\n')
print('Security canary scan:', 'PASS' if result['passed'] else 'FAIL', 'files:',scanned)
sys.exit(0 if result['passed'] else 1)
