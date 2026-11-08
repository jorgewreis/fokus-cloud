"""Dependency-free structural checks for the FEKS Markdown and metadata subset."""
import re
from pathlib import Path
import subprocess
from urllib.parse import unquote, urlsplit


def validate(root):
    errors = []
    files = subprocess.check_output(['git', 'ls-files', '-z', 'docs/engineering', '.agents/skills'], cwd=root).split(b'\0')
    for raw in filter(None, files):
        path = root / raw.decode('utf-8')
        if path.is_symlink():
            errors.append(f'{path.relative_to(root)}: symlinks are not knowledge')
            continue
        if path.suffix not in ('.md', '.yaml'):
            continue  # Executable/unknown files are deployment eligible, never exempt.
        text = path.read_text(encoding='utf-8')
        if '\0' in text or not text.strip():
            errors.append(f'{path.relative_to(root)}: empty or binary content')
        if path.name == 'SKILL.md':
            front = re.match(r'\A---\n(.*?)\n---(?:\n|$)', text, re.S)
            if not front:
                errors.append(f'{path.relative_to(root)}: missing frontmatter')
            else:
                name = re.search(r'^name: ([a-z0-9-]+)$', front[1], re.M)
                description = re.search(r'^description: (\S.*)$', front[1], re.M)
                if not name or name[1] != path.parent.name or not description:
                    errors.append(f'{path.relative_to(root)}: invalid name or description')
        if path.name == 'openai.yaml':
            # Deliberately validate the simple scalar interface subset used by FEKS.
            expected = {'display_name', 'short_description', 'default_prompt'}
            lines = [line for line in text.splitlines() if line.strip() and not line.lstrip().startswith('#')]
            keys = set()
            if not lines or lines[0] != 'interface:':
                errors.append(f'{path.relative_to(root)}: expected interface metadata')
            for line in lines[1:]:
                match = re.fullmatch(r'  ([a-z_]+): "([^"\n]+)"', line)
                if not match or match[1] in keys:
                    errors.append(f'{path.relative_to(root)}: unsupported or duplicate metadata')
                else:
                    keys.add(match[1])
            if keys != expected:
                errors.append(f'{path.relative_to(root)}: missing or unknown metadata fields')
        if path.suffix == '.md':
            prose = re.sub(r'```.*?```', '', text, flags=re.S)
            for target in re.findall(r'\[[^\]]*\]\(([^\s)]+)(?:\s+"[^"]*")?\)', prose):
                url = urlsplit(target.strip('<>'))
                if url.scheme or url.netloc or not url.path:
                    continue
                candidate = (path.parent / unquote(url.path)).resolve()
                if not candidate.is_relative_to(root) or not candidate.exists():
                    errors.append(f'{path.relative_to(root)}: missing relative link {target}')
    return errors


if __name__ == '__main__':
    errors = validate(Path.cwd().resolve())
    for error in errors:
        print(error)
    print(f'Engineering knowledge validation: {len(errors)} error(s).')
    raise SystemExit(bool(errors))
