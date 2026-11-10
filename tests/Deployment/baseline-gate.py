"""Fail-closed release baseline validator; no implicit parent fallback."""
import json
import pathlib
import re
import subprocess
import sys


def validate(root, baseline, candidate='HEAD', plan=None):
    root = pathlib.Path(root)
    if not baseline or not re.fullmatch(r'[0-9a-f]{40}', baseline):
        raise ValueError('baseline_sha must be an explicit full lowercase SHA')
    def git(*args):
        return subprocess.run(['git', '-C', str(root), *args], text=True, capture_output=True)
    if git('cat-file', '-e', baseline + '^{commit}').returncode:
        raise ValueError('baseline commit does not exist')
    resolved = git('rev-parse', '--verify', candidate + '^{commit}')
    if resolved.returncode or git('merge-base', '--is-ancestor', baseline, resolved.stdout.strip()).returncode:
        raise ValueError('baseline must be an ancestor of candidate')
    approved = json.loads((pathlib.Path(plan) if plan else root / 'tests/Deployment/release-plan.json').read_text())
    if approved['baseline_sha'] != baseline:
        raise ValueError('baseline differs from reviewed release plan')
    return {'baseline_sha': baseline, 'candidate_sha': resolved.stdout.strip()}


if __name__ == '__main__':
    try:
        print(json.dumps(validate(pathlib.Path(__file__).resolve().parents[2], sys.argv[1] if len(sys.argv) > 1 else '')))
    except (ValueError, KeyError, OSError) as error:
        sys.exit(str(error))
