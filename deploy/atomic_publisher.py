#!/usr/bin/env python3
"""Seal a new release directory and switch current atomically.

The active tree is never a write target. A failed post-switch verification
restores the previous symlink. Database changes are not reversed.
"""
import argparse
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import sys

LAW_MANIFEST = 'config/fokus-law-assets.json'
IN_PLACE = 'legacy_in_place_mutates_active_release'
RELEASE_NAME = re.compile(r'[0-9a-f]{40}')
ABSOLUTE_PATH = re.compile(r'/[A-Za-z0-9_./-]+')


class PublicationError(Exception):
    def __init__(self, reason):
        super().__init__(reason)
        self.reason = reason


def safe_absolute(value):
    if not isinstance(value, str) or ABSOLUTE_PATH.fullmatch(value) is None:
        raise PublicationError('invalid_base')
    parts = value.split('/')
    if any(part in ('', '.', '..') for part in parts[1:]):
        raise PublicationError('invalid_base')
    return value


def require_base(path):
    base = Path(path).absolute()
    if base.is_symlink() or not base.is_dir() or base.resolve() != base:
        raise PublicationError('invalid_base')
    return base


def canonical(path):
    path = Path(path).absolute()
    if path.exists() or path.is_symlink():
        return path.resolve()
    return path.parent.resolve() / path.name


def destination_mutates_active_release(active, destination):
    """True when destination is the served tree or either path contains the other."""
    if not (Path(active).exists() or Path(active).is_symlink()):
        return False
    active_real = Path(active).resolve()
    active_path = Path(active).absolute()
    dest = canonical(destination)
    return (dest == active_real or dest == active_path
            or dest.is_relative_to(active_real) or active_real.is_relative_to(dest)
            or dest.is_relative_to(active_path) or active_path.is_relative_to(dest))


def dumps(payload):
    return json.dumps(payload, sort_keys=True, separators=(',', ':')).encode()


def identity_text(release, root, manifest_digest):
    identity = {'release': release, 'root': root, 'manifest': manifest_digest}
    literals = ', '.join(
        "'%s' => '%s'" % (key, value.replace('\\', '\\\\').replace("'", "\\'"))
        for key, value in identity.items())
    return '<?php\n\nreturn [' + literals + '];\n'


def write_identity(root, release, identity_root, manifest_bytes):
    digest = hashlib.sha256(manifest_bytes).hexdigest()
    seal = root / '.release'
    seal.mkdir(exist_ok=True)
    (seal / 'manifest.json').write_bytes(manifest_bytes)
    (seal / 'identity.php').write_text(identity_text(release, identity_root, digest))
    if not (seal / 'verify.lock').exists():
        (seal / 'verify.lock').touch()


def load_manifest(root):
    try:
        payload = json.loads((root / '.release' / 'manifest.json').read_bytes())
    except (OSError, UnicodeError, json.JSONDecodeError):
        raise PublicationError('release_identity_unbound') from None
    if (not isinstance(payload, dict) or set(payload) != {'release', 'root', 'files'}
            or not isinstance(payload['files'], dict)):
        raise PublicationError('release_identity_unbound')
    return payload


def require_bound_identity(candidate, release):
    if candidate.name != release or candidate.is_symlink() or not candidate.is_dir():
        raise PublicationError('release_directory_missing')
    payload = load_manifest(candidate)
    body = dumps(payload)
    if (payload['release'] != release or payload['root'] != str(candidate.resolve())
            or (candidate / '.release' / 'manifest.json').read_bytes() != body):
        raise PublicationError('release_identity_unbound')
    expected = identity_text(release, payload['root'], hashlib.sha256(body).hexdigest())
    if (candidate / '.release' / 'identity.php').read_text() != expected:
        raise PublicationError('release_identity_unbound')


def law_payload(path):
    try:
        payload = json.loads(path.read_text(encoding='utf-8'))
    except (OSError, UnicodeError, json.JSONDecodeError):
        raise PublicationError('law_manifest_invalid') from None
    if (not isinstance(payload, dict) or set(payload) != {'version', 'algorithm', 'assets'}
            or payload['version'] != 1 or payload['algorithm'] != 'sha256'
            or not isinstance(payload['assets'], dict) or not payload['assets']):
        raise PublicationError('law_manifest_invalid')
    for source, entry in payload['assets'].items():
        if not isinstance(source, str) or not isinstance(entry, dict) or set(entry) != {'path', 'sha256'}:
            raise PublicationError('law_manifest_invalid')
        relative = entry['path']
        digest = entry['sha256']
        if (not isinstance(relative, str) or not isinstance(digest, str)
                or not re.fullmatch(r'[A-Za-z0-9_./-]+', relative)
                or relative.startswith('/') or '..' in Path(relative).parts or '.' in Path(relative).parts
                or not re.fullmatch(r'[0-9a-f]{64}', digest) or digest not in Path(relative).name):
            raise PublicationError('law_manifest_invalid')
    return payload


def copy_generated_assets(source, root):
    manifest = source / LAW_MANIFEST
    if not manifest.is_file() or manifest.is_symlink():
        raise PublicationError('law_manifest_missing')
    raw = manifest.read_bytes()
    payload = law_payload(manifest)
    for entry in payload['assets'].values():
        origin = source / 'public' / entry['path']
        destination = root / 'public' / entry['path']
        if not origin.is_file() or origin.is_symlink():
            raise PublicationError('law_asset_missing')
        if hashlib.sha256(origin.read_bytes()).hexdigest() != entry['sha256']:
            raise PublicationError('law_asset_mismatch')
        if destination.exists() or destination.is_symlink():
            if destination.is_symlink() or hashlib.sha256(destination.read_bytes()).hexdigest() != entry['sha256']:
                raise PublicationError('law_asset_mismatch')
            continue
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(origin, destination)
    return raw


def attach_law_manifest(root, manifest_bytes):
    destination = root / LAW_MANIFEST
    if destination.exists() or destination.is_symlink():
        raise PublicationError('law_manifest_invalid')
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_bytes(manifest_bytes)
    try:
        payload = law_payload(destination)
        for entry in payload['assets'].values():
            asset = root / 'public' / entry['path']
            if not asset.is_file() or asset.is_symlink():
                raise PublicationError('law_asset_missing')
            if hashlib.sha256(asset.read_bytes()).hexdigest() != entry['sha256']:
                raise PublicationError('law_asset_mismatch')
        sealed = load_manifest(root)
        extra = []
        for path in sorted((root / 'config').rglob('*')):
            if path.is_file() and not path.is_symlink():
                relative = path.relative_to(root).as_posix()
                if relative not in sealed['files']:
                    extra.append(relative)
        if extra != [LAW_MANIFEST]:
            raise PublicationError('untracked_application_code')
    except PublicationError:
        destination.unlink(missing_ok=True)
        raise
    sealed['files'][LAW_MANIFEST] = hashlib.sha256(manifest_bytes).hexdigest()
    write_identity(root, sealed['release'], sealed['root'], dumps(sealed))


def rebind_root(root, identity_root):
    identity_root = safe_absolute(identity_root)
    payload = load_manifest(root)
    if Path(identity_root).name != payload['release'] or payload['release'] != root.name:
        raise PublicationError('remote_root_invalid')
    if not identity_root.endswith('/releases/' + payload['release']):
        raise PublicationError('remote_root_invalid')
    files = dict(payload['files'])
    payload['root'] = identity_root
    body = dumps(payload)
    write_identity(root, payload['release'], identity_root, body)
    rebound = load_manifest(root)
    if rebound['files'] != files or rebound['root'] != identity_root:
        raise PublicationError('remote_root_invalid')


def discover_packages(root, source):
    env_file = root / '.env'
    example = source / '.env.example'
    if example.is_file() and not example.is_symlink():
        shutil.copyfile(example, env_file)
    env = os.environ.copy()
    env.update({
        'APP_ENV': 'production',
        'APP_DEBUG': 'false',
        'APP_KEY': 'base64:' + base64.b64encode(b'\x00' * 32).decode(),
        'DB_CONNECTION': 'sqlite',
        'DB_DATABASE': ':memory:',
        'CACHE_STORE': 'array',
        'SESSION_DRIVER': 'array',
        'QUEUE_CONNECTION': 'sync',
    })
    result = subprocess.run(
        ['php', 'artisan', 'package:discover', '--no-interaction'],
        cwd=root, env=env, capture_output=True, text=True, check=False,
    )
    if env_file.exists() or env_file.is_symlink():
        env_file.unlink()
    packages = root / 'bootstrap' / 'cache' / 'packages.php'
    if result.returncode != 0 or (packages.is_file() and b'APP_KEY' in packages.read_bytes()):
        raise PublicationError('package_discovery_failed')


def load_preparer():
    path = Path(__file__).resolve().parents[1] / 'tools' / 'ci' / 'prepare-release.py'
    spec = importlib.util.spec_from_file_location('fokus_prepare_release', path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def seal_local(source, work, output, remote_root, release):
    if RELEASE_NAME.fullmatch(release) is None:
        raise PublicationError('invalid_release')
    remote_root = safe_absolute(remote_root)
    if Path(remote_root).name != release or not remote_root.endswith('/releases/' + release):
        raise PublicationError('remote_root_invalid')
    source = Path(source).resolve()
    head = subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip()
    if head != release:
        raise PublicationError('release_sha_mismatch')
    local = Path(work) / release
    output = Path(output)
    if output.exists() or local.exists():
        raise PublicationError('seal_destination_exists')
    local.parent.mkdir(parents=True, exist_ok=True)
    preparer = load_preparer()
    try:
        preparer.prepare(source, local)
        law_bytes = copy_generated_assets(source, local)
        discover_packages(local, source)
        if (local / '.env').exists():
            raise PublicationError('release_env_must_be_absent')
        preparer.seal(source, local)
        attach_law_manifest(local, law_bytes)
        rebind_root(local, remote_root)
        shutil.copytree(local, output, symlinks=True)
    except PublicationError:
        raise
    except ValueError as error:
        code = str(error)
        if re.fullmatch(r'[a-z0-9_]+', code):
            raise PublicationError(code) from None
        raise PublicationError('release_seal_failed') from None
    except (OSError, subprocess.CalledProcessError):
        raise PublicationError('release_seal_failed') from None


def replace_symlink(base, link, target):
    temporary = base / ('.current-' + secrets.token_hex(8))
    temporary.symlink_to(target)
    os.replace(temporary, link)
    descriptor = os.open(base, os.O_RDONLY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def link_shared(base, candidate):
    storage_link = base / 'shared' / 'storage'
    env_file = base / 'shared' / '.env'
    if storage_link.is_symlink() or not storage_link.is_dir() or not (storage_link / 'app' / 'public').is_dir():
        raise PublicationError('shared_storage_required')
    if env_file.is_symlink() or not env_file.is_file():
        raise PublicationError('shared_configuration_required')
    storage = storage_link.resolve()
    configuration = env_file.resolve()
    if destination_mutates_active_release(base / 'current', storage) or storage.is_relative_to(candidate.resolve()):
        raise PublicationError('shared_storage_required')
    release_storage = candidate / 'storage'
    if release_storage.is_symlink():
        raise PublicationError('release_storage_not_empty')
    if release_storage.exists():
        if any(path.is_file() or path.is_symlink() for path in release_storage.rglob('*')):
            raise PublicationError('release_storage_not_empty')
        shutil.rmtree(release_storage)
    release_storage.symlink_to(storage)
    env_link = candidate / '.env'
    if env_link.exists() or env_link.is_symlink():
        raise PublicationError('release_env_must_be_absent')
    env_link.symlink_to(configuration)
    public_storage = candidate / 'public' / 'storage'
    if public_storage.is_symlink() and os.readlink(public_storage) == '../storage/app/public':
        return
    if public_storage.exists() or public_storage.is_symlink():
        raise PublicationError('public_storage_link_unexpected')
    public_storage.symlink_to(Path('../storage/app/public'))


def run_migrations(candidate):
    result = subprocess.run(
        ['php', 'artisan', 'migrate', '--force', '--no-interaction'],
        cwd=candidate, capture_output=True, text=True, check=False,
    )
    if result.returncode != 0:
        raise PublicationError('migration_failed')


def switch(base, release, *, bootstrap, migrate=run_migrations):
    if RELEASE_NAME.fullmatch(release) is None:
        raise PublicationError('invalid_release')
    if not isinstance(bootstrap, bool):
        raise PublicationError('invalid_bootstrap_flag')
    base = require_base(base)
    current = base / 'current'
    if not current.is_symlink():
        raise PublicationError('current_symlink_required')
    candidate = base / 'releases' / release
    if destination_mutates_active_release(current, candidate):
        raise PublicationError(IN_PLACE)
    require_bound_identity(candidate, release)
    raw_previous = os.readlink(current)
    previous = Path(raw_previous)
    previous_resolved = previous.resolve() if previous.is_absolute() else (base / previous).resolve()
    if not previous_resolved.is_dir():
        raise PublicationError('current_target_missing')
    sealed = (previous_resolved / '.release' / 'identity.php').is_file()
    if sealed and bootstrap:
        raise PublicationError('bootstrap_refused_sealed_current')
    if not sealed and not bootstrap:
        raise PublicationError('bootstrap_required_for_unsealed_current')
    link_shared(base, candidate)
    if migrate is not None:
        migrate(candidate)
    state = {
        'bootstrap': bootstrap,
        'candidate': str(candidate.resolve()),
        'previous': raw_previous,
        'release': release,
        'schema': 1,
    }
    temporary = base / ('.switch-state-' + secrets.token_hex(8))
    temporary.write_bytes(dumps(state))
    os.replace(temporary, base / 'switch-state.json')
    replace_symlink(base, current, str(candidate.resolve()))
    if bootstrap:
        print('bootstrap_first_sealed_release=accepted reason=unsealed_current_tree')
    else:
        print('bootstrap_first_sealed_release=not_used')
    print('atomic_switch=completed')
    return state


def rollback(base, release):
    if RELEASE_NAME.fullmatch(release) is None:
        raise PublicationError('invalid_release')
    base = require_base(base)
    try:
        state = json.loads((base / 'switch-state.json').read_bytes())
    except (OSError, UnicodeError, json.JSONDecodeError):
        raise PublicationError('switch_state_missing') from None
    if not isinstance(state, dict) or state.get('schema') != 1 or state.get('release') != release:
        raise PublicationError('rollback_release_mismatch')
    current = base / 'current'
    if not current.is_symlink() or Path(os.readlink(current)).resolve() != Path(state['candidate']).resolve():
        raise PublicationError('rollback_current_mismatch')
    replace_symlink(base, current, state['previous'])
    print('atomic_rollback=restored_previous_release')


def seal_local_from_environment(env):
    release = env.get('EXPECTED_RELEASE', '')
    base = safe_absolute(env.get('DEPLOY_PATH', ''))
    remote = base + '/releases/' + release
    runner = Path(env.get('RUNNER_TEMP', ''))
    if not runner.is_absolute():
        raise PublicationError('invalid_base')
    seal_local(Path.cwd(), runner / 'seal-work', runner / 'sealed-release', remote, release)
    print('release_seal=ok')


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='command', required=True)
    commands.add_parser('seal-local')
    for name in ('switch', 'rollback'):
        command = commands.add_parser(name)
        command.add_argument('--base', required=True)
        command.add_argument('--release', required=True)
        if name == 'switch':
            command.add_argument('--bootstrap', required=True, choices=('true', 'false'))
    args = parser.parse_args(argv)
    try:
        if args.command == 'seal-local':
            seal_local_from_environment(os.environ)
        elif args.command == 'switch':
            switch(safe_absolute(args.base), args.release, bootstrap=args.bootstrap == 'true')
        else:
            rollback(safe_absolute(args.base), args.release)
    except PublicationError as error:
        print('atomic_publication=failed reason=' + error.reason, file=sys.stderr)
        return 1
    except Exception:
        print('atomic_publication=failed reason=unexpected_publication_error', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
