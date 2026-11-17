"""Conservative archive/proof screening, not a certification or upload approval."""
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import tarfile

MAX_BYTES=512*1024*1024
MAX_FILES=25000
PATTERNS=[
    rb'-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----',
    rb'(?<![A-Z0-9])AKIA[0-9A-Z]{16}(?![A-Z0-9])',
    rb'gh[pousr]_[A-Za-z0-9]{30,}',
    rb'github_pat_[A-Za-z0-9_]{40,}',
    rb'xox[baprs]-[A-Za-z0-9-]{20,}',
    rb'(?i)(?:password|passwd|client_secret|api_key|access_token|secret_key)\s*[=:]\s*[\x22\x27]?[A-Za-z0-9_+/=-]{12,}',
]

def audit(artifact,proof,expected_sha):
    counts={'entries':0,'bytes':0,'secret_pattern_hits':0,'unsafe_entries':0}
    artifact=Path(artifact);proof=Path(proof)
    if artifact.is_symlink() or proof.is_symlink():raise ValueError('symlink_input')
    if artifact.stat().st_size>MAX_BYTES or proof.stat().st_size>16*1024*1024:raise ValueError('input_budget')
    evidence=proof.read_bytes()
    if json.loads(evidence).get('source_sha')!=expected_sha:raise ValueError('proof_sha_mismatch')
    # Only fixed labels and counts may leave the runner: never paths or values.
    categories={}
    labels=('private_key_marker','aws_key_shape','github_token_shape','github_pat_shape','slack_token_shape','assignment_shape')
    if len(labels)!=len(PATTERNS):raise ValueError('pattern_labels_mismatch')
    def record_hits(content,area):
        for label,pattern in zip(labels,PATTERNS):
            hits=len(re.findall(pattern,content))
            if hits:
                key=area+':'+label
                categories[key]=categories.get(key,0)+hits
                counts['secret_pattern_hits']+=hits
    record_hits(evidence,'proof')
    seen=set()
    with tarfile.open(artifact,'r:*') as archive:
        for member in archive:
            counts['entries']+=1;counts['bytes']+=member.size
            if counts['entries']>MAX_FILES or counts['bytes']>MAX_BYTES:raise ValueError('archive_budget')
            path=PurePosixPath(member.name)
            unsafe=(member.name in seen or path.is_absolute() or '..' in path.parts or '\\' in member.name or not member.isfile())
            seen.add(member.name)
            if any(part in ('.git','.ssh') for part in path.parts):unsafe=True
            if path.name=='.env' or path.suffix in ('.sql','.sqlite','.sqlite3','.p12','.pfx','.key'):unsafe=True
            area=path.parts[0] if path.parts and path.parts[0] in ('docs','tests','vendor','tools','public','app','config','database') else 'other'
            if unsafe:
                counts['unsafe_entries']+=1
                reason='restricted_sql' if path.suffix=='.sql' else 'restricted_entry'
                key=area+':'+reason
                categories[key]=categories.get(key,0)+1
                continue
            content=archive.extractfile(member).read()
            record_hits(content,area)
    passed=not counts['secret_pattern_hits'] and not counts['unsafe_entries']
    return {'state':'screened_not_approved_for_upload' if passed else 'blocked_by_screening',
            'source_sha':expected_sha,'artifact_sha256':hashlib.sha256(artifact.read_bytes()).hexdigest(),
            'counts':counts,'categories':categories,'upload_allowed':False,
            'limitations':'Heuristic screen can miss secrets/personal data; separate content review and retention approval required.'}

if __name__=='__main__':
    import argparse
    p=argparse.ArgumentParser();p.add_argument('--artifact',required=True);p.add_argument('--proof',required=True);p.add_argument('--expected-sha',required=True)
    a=p.parse_args()
    try:
        result=audit(a.artifact,a.proof,a.expected_sha);print(json.dumps(result,sort_keys=True))
        raise SystemExit(0 if result['state']=='screened_not_approved_for_upload' else 1)
    except (ValueError,OSError,tarfile.TarError):
        print('{"state":"blocked_by_screening","reason":"invalid_input","upload_allowed":false}')
        raise SystemExit(1)
