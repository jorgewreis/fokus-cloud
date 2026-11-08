#!/usr/bin/env python3
"""Build/seal a NEW offline release. Never operates on a live deployment."""
import argparse
import hashlib
import io
import json
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tarfile

TREES = ('app', 'bootstrap', 'config', 'routes', 'vendor', 'public')


def git(source, *args):
    return subprocess.check_output(['git', '-C', str(source), *args], stderr=subprocess.DEVNULL)


def snapshot(source):
    sha = git(source, 'rev-parse', 'HEAD').decode().strip()
    if not re.fullmatch('[0-9a-f]{40}', sha) or git(source, 'status', '--porcelain', '--untracked-files=no'):
        raise ValueError('source_not_clean')
    archive = tarfile.open(fileobj=io.BytesIO(git(source, 'archive', '--format=tar', sha)))
    return sha, archive


def checked_root(root, sha):
    if root.is_symlink() or root.absolute() != root.resolve() or root.name != sha:
        raise ValueError('unique_release_root_required')


def prepare(source, root):
    sha, archive = snapshot(source)
    checked_root(root, sha)
    if root.exists():
        raise ValueError('destination_already_exists')
    if not (source / 'vendor/autoload.php').is_file():
        raise ValueError('prepared_vendor_required')
    # Reject links before extraction/copy; no paths may escape the new directory.
    members = archive.getmembers()
    if any(not (m.isdir() or m.isfile()) or m.name.startswith('/') or '..' in Path(m.name).parts for m in members):
        raise ValueError('archive_links_not_supported')
    vendor_root = (source / 'vendor').resolve()
    for path in vendor_root.rglob('*'):
        if path.is_symlink() and not path.resolve().is_relative_to(vendor_root):
            raise ValueError('external_vendor_link_not_supported')
    root.mkdir(parents=False)
    archive.extractall(root, filter='data')
    shutil.copytree(source / 'vendor', root / 'vendor')
    for directory in ('storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'):
        (root / directory).mkdir(parents=True, exist_ok=True)


def seal(source, root):
    sha, archive = snapshot(source)
    checked_root(root, sha)
    if not root.is_dir() or (root / '.release').exists():
        raise ValueError('new_unsealed_root_required')
    # A caller cannot attach a different SHA to modified tracked application code.
    tracked = {member.name for member in archive.getmembers() if member.isfile()}
    for member in archive.getmembers():
        if member.isfile():
            path = root / member.name
            if path.is_symlink() or not path.is_file() or path.read_bytes() != archive.extractfile(member).read():
                raise ValueError('tracked_source_mismatch')
    files = {}
    total_bytes = 0
    for directory in TREES:
        for path in sorted((root / directory).rglob('*')):
            if path.is_symlink():
                # Laravel's conventional asset link is not executable release code.
                if path == root / 'public/storage':
                    continue
                raise ValueError('runtime_link_not_supported')
            if path.is_file() and (directory != 'public' or path.suffix == '.php'):
                relative = path.relative_to(root).as_posix()
                if relative not in tracked and not relative.startswith(('vendor/', 'bootstrap/cache/')):
                    raise ValueError('untracked_application_code')
                if '..' in relative or not re.fullmatch(r'[A-Za-z0-9_. /@+,-]+', relative):
                    raise ValueError('unsupported_runtime_path')
                total_bytes += path.stat().st_size
                if total_bytes > 128 * 1024 * 1024 or len(files) >= 20000:
                    raise ValueError('runtime_integrity_budget_exceeded')
                files[relative] = hashlib.sha256(path.read_bytes()).hexdigest()
    if not all(name in files for name in ('public/index.php', 'bootstrap/app.php', 'vendor/autoload.php')):
        raise ValueError('incomplete_release')
    manifest = json.dumps({'release': sha, 'root': str(root), 'files': files}, sort_keys=True, separators=(',', ':')).encode()
    if len(manifest) > 4 * 1024 * 1024:
        raise ValueError('manifest_budget_exceeded')
    identity = {'release': sha, 'root': str(root), 'manifest': hashlib.sha256(manifest).hexdigest()}
    # mkdir is exclusive. Interrupted sealing leaves a non-serving, non-reusable artifact.
    staging = root / '.release-pending'
    staging.mkdir()
    (staging / 'verify.lock').touch()
    (staging / 'manifest.json').write_bytes(manifest)
    literals = ', '.join("'%s' => '%s'" % (k, v.replace('\\', '\\\\').replace("'", "\\'")) for k, v in identity.items())
    (staging / 'identity.php').write_text('<?php\n\nreturn [' + literals + '];\n')
    staging.rename(root / '.release')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=('prepare', 'seal'))
    parser.add_argument('--source', required=True, type=Path)
    parser.add_argument('--root', required=True, type=Path)
    args = parser.parse_args()
    try:
        globals()[args.command](args.source.resolve(), args.root.absolute())
    except Exception:
        print('release_preparation=failed', file=sys.stderr)
        return 1
    print('release_preparation=ok')
    return 0


if __name__ == '__main__':
    sys.exit(main())
