"""Conservative, local Git classifier. No network, secrets, shell or dependencies."""
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import tempfile

AUDIT_DIGEST = '2fafc14ff178547291b57a0dc4e491f44de4a4eef4deefc244d0ffe44016ac3e'

SKILLS = frozenset(('engineering-research', 'secure-code-audit',
                    'software-architecture-review', 'testing-quality-engineering',
                    'code-review-refactoring'))



def git(*args):
    return subprocess.check_output(['git', *args], stderr=subprocess.PIPE)


def commit(value):
    if not isinstance(value, str) or not re.fullmatch(r'[0-9a-f]{40}', value) or value == '0' * 40:
        raise ValueError('missing or invalid commit')
    if git('rev-parse', '--verify', value + '^{commit}').decode().strip() != value:
        raise ValueError('not a commit')
    return value


def knowledge_path(path):
    parts = PurePosixPath(path).parts
    if any(p in ('.', '..', 'AGENTS.md') for p in parts):
        return False
    if path.startswith('docs/engineering/') and path.endswith('.md'):
        return True
    if len(parts) < 4 or parts[:2] != ('.agents', 'skills') or parts[2] not in SKILLS:
        return False
    tail = '/'.join(parts[3:])
    return tail == 'SKILL.md' or tail == 'agents/openai.yaml' or (tail.startswith('references/') and tail.endswith('.md'))


def audit_digest(ref):
    """Invalidate exemption when any tracked non-knowledge consumer changes.

    The classifier is excluded to avoid a self-referential digest; its own changes
    are always deployment eligible and require review of this policy.
    """
    digest = hashlib.sha256()
    for entry in filter(None, git('ls-tree', '-r', '-z', ref).split(b'\0')):
        metadata, raw_path = entry.split(b'\t', 1)
        path = raw_path.decode('utf-8')
        if path == 'tools/ci/classify-deployment.py' or knowledge_path(path):
            continue
        digest.update(metadata + b'\t' + raw_path + b'\0')
    return digest.hexdigest()


def classify(event_name, event):
    if event_name == 'workflow_dispatch':
        return False, 'manual dispatch preserves normal deployment'
    try:
        if event_name == 'push':
            base, head = commit(event['before']), commit(event['after'])
            if event.get('deleted') or event.get('forced'):
                raise ValueError('deleted or forced push')
            git('merge-base', '--is-ancestor', base, head)
        elif event_name == 'pull_request':
            pr = event['pull_request']
            base, head = commit(pr['base']['sha']), commit(pr['head']['sha'])
            base = git('merge-base', base, head).decode().strip()
        else:
            raise ValueError('unsupported event')
        if any(audit_digest(ref) != AUDIT_DIGEST for ref in (base, head)):
            return False, 'audited source boundary changed; normal deployment retained'
        # Disable rename detection: both deleted source and added destination must qualify.
        raw = git('diff', '--no-ext-diff', '--no-renames', '--name-only', '-z', base, head, '--')
        paths = [p.decode('utf-8') for p in raw.split(b'\0') if p]
        if not paths:
            raise ValueError('empty comparison')
        for path in paths:
            if not knowledge_path(path):
                return False, 'application, operational or unaudited path changed'
            # Inspect both trees: symlink/executable/submodule transitions and deletions.
            for ref in (base, head):
                entries = git('ls-tree', '-z', ref, '--', path).split(b'\0')
                for entry in filter(None, entries):
                    metadata, actual = entry.split(b'\t', 1)
                    if actual.decode('utf-8') != path or metadata.split()[:2] != [b'100644', b'blob']:
                        return False, 'non-regular knowledge file changed'
                    content = git('cat-file', 'blob', metadata.split()[2].decode())
                    if b'\0' in content:
                        return False, 'binary knowledge file changed'
                    content.decode('utf-8')
        return True, f'{len(paths)} regular knowledge file(s); build comparison still required'
    except (KeyError, TypeError, ValueError, UnicodeError, subprocess.CalledProcessError) as error:
        return False, 'indeterminate comparison; normal deployment retained (' + type(error).__name__ + ')'


def snapshot(root):
    """Include ignored generated output, not merely Git-tracked files."""
    result = {}
    def traversal_error(error):
        raise error

    for directory, dirs, files in os.walk(root, followlinks=False, onerror=traversal_error):
        relative = Path(directory).relative_to(root)
        if relative == Path('.'):
            dirs[:] = [name for name in dirs if name not in ('.git', 'node_modules')]
        for name in dirs + files:
            item = Path(directory) / name
            path = item.relative_to(root).as_posix()
            if path == '.git':
                continue
            mode = item.lstat().st_mode
            if knowledge_path(path):
                if not stat.S_ISREG(mode) or stat.S_IMODE(mode) != 0o644:
                    raise ValueError('unsupported knowledge object in build snapshot')
                continue
            if stat.S_ISLNK(mode):
                result[path] = ('symlink', os.readlink(item))
            elif stat.S_ISREG(mode):
                result[path] = (stat.S_IMODE(mode), hashlib.sha256(item.read_bytes()).hexdigest())
            elif stat.S_ISDIR(mode):
                # Knowledge directory additions are not runtime output.
                if not (path in ('docs', 'docs/engineering', '.agents', '.agents/skills')
                        or path.startswith('docs/engineering/') or path.startswith('.agents/skills/')):
                    result[path] = ('directory', stat.S_IMODE(mode))
            else:
                raise ValueError('unsupported filesystem object in build snapshot')
    return result


def prove_build_equivalence(event_name, event):
    """A candidate is exempt only after identical complete non-knowledge build trees.

    Tailwind v4 automatically scans Markdown: path allowlists alone are unsafe.
    Failures (including font/network/dependency errors) retain normal deployment.
    """
    if event_name == 'push':
        base, head = commit(event['before']), commit(event['after'])
    else:
        pr = event['pull_request']
        base = commit(pr['base']['sha'])
        pr_head = commit(pr['head']['sha'])
        head = commit(os.environ.get('GITHUB_SHA'))
        parents = git('show', '-s', '--format=%P', head).decode().split()
        if parents != [base, pr_head]:
            return False, 'PR integration tree unavailable; normal deployment retained'
    if any(audit_digest(ref) != AUDIT_DIGEST for ref in (base, head)):
        return False, 'audited integration source boundary changed; normal deployment retained'
    worktrees = []
    try:
        with tempfile.TemporaryDirectory(prefix='deployment-build-') as directory:
            trees = []
            for label, ref in (('base', base), ('head', head)):
                root = Path(directory) / label
                git('worktree', 'add', '--detach', str(root), ref)
                worktrees.append(root)
                with (Path(directory) / (label + '.log')).open('wb') as log:
                    for command in (['npm', 'ci'], ['npm', 'run', 'build']):
                        subprocess.run(command, cwd=root, stdout=log, stderr=subprocess.STDOUT,
                                       check=True, timeout=600)
                trees.append(snapshot(root))
            if trees[0] != trees[1]:
                return False, 'build or non-knowledge output differs; normal deployment retained'
            return True, 'regular knowledge files only and identical non-knowledge build output'
    except (OSError, ValueError, subprocess.SubprocessError):
        return False, 'build comparison failed; normal deployment retained'
    finally:
        for root in worktrees:
            # Disposable worktrees only; never modifies the caller checkout.
            subprocess.run(['git', 'worktree', 'remove', '--force', str(root)],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)


def main():
    try:
        event = json.loads(Path(os.environ['GITHUB_EVENT_PATH']).read_text())
        event_name = os.environ.get('GITHUB_EVENT_NAME', '')
        docs_only, reason = classify(event_name, event)
        if docs_only:
            docs_only, reason = prove_build_equivalence(event_name, event)
    except (OSError, KeyError, ValueError):
        docs_only, reason = False, 'event unavailable; normal deployment retained'
    result = 'true' if docs_only else 'false'
    print(f'docs_only={result}: {reason}')
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a') as output:
            output.write(f'docs_only={result}\n')
    if os.environ.get('GITHUB_STEP_SUMMARY'):
        with open(os.environ['GITHUB_STEP_SUMMARY'], 'a') as summary:
            summary.write(f'## Deployment classification\n\nKnowledge only: **{result}**. {reason}.\n\n'
                          'All application validation jobs remain required. Only a successful '
                          'classification with true can omit publication. Infrastructure failure '
                          'blocks dependent publication; it never grants the exception.\n')


if __name__ == '__main__':
    main()
