#!/usr/bin/env python3
"""Offline B+C primitives. No SSH, service changes, migrations or automatic recovery."""
import argparse
from contextlib import contextmanager
import fcntl
import hashlib
import io
import json
import os
import pwd
import socket
from pathlib import Path
import re
import stat
import subprocess
import sys
import tarfile
import uuid

SCHEMA = 'fokus-immutable-v1'
MAX_FILES = 50000
MAX_BYTES = 512 * 1024 * 1024
SHA = re.compile(r'[0-9a-f]{40}\Z')
DIGEST = re.compile(r'[0-9a-f]{64}\Z')


class Refusal(ValueError):
    pass


def require(condition, code):
    if not condition:
        raise Refusal(code)


def canonical(path):
    p = Path(path).absolute()
    require(p == p.resolve(), 'noncanonical_path')
    return p


def digest(data):
    return hashlib.sha256(data).hexdigest()


def encoded(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':')).encode()


def relative(name):
    p = Path(name)
    require(name and not p.is_absolute() and p.as_posix() == name and all(x not in ('.', '..') for x in p.parts), 'unsafe_member')
    require(re.fullmatch(r'[A-Za-z0-9_./@+, -]+', name) is not None, 'unsupported_path')
    return p


def regular(path):
    s = path.lstat()
    require(stat.S_ISREG(s.st_mode) and s.st_nlink == 1, 'regular_single_link_required')
    return s


def read_json(path):
    regular(path)
    require(path.stat().st_size <= 8 * 1024 * 1024, 'metadata_too_large')
    def pairs(items):
        result = {}
        for k, v in items:
            require(k not in result, 'duplicate_json_key')
            result[k] = v
        return result
    return json.loads(path.read_bytes(), object_pairs_hook=pairs)


def files(root, allowed_links=None):
    """Exact regular-file inventory; never follows directory links."""
    root = canonical(root)
    result, total = {}, 0
    for directory, dirs, names in os.walk(root, followlinks=False):
        for name in sorted(dirs + names):
            path = Path(directory) / name
            rel = path.relative_to(root).as_posix()
            relative(rel)
            if path.is_symlink():
                require(allowed_links is not None and rel in allowed_links and os.readlink(path) == allowed_links[rel], 'unexpected_link')
                continue
            if path.is_dir():
                continue
            s = regular(path)
            total += s.st_size
            require(total <= MAX_BYTES and len(result) < MAX_FILES, 'inventory_budget_exceeded')
            require(not s.st_mode & 0o7000, 'special_mode')
            result[rel] = {'sha256': digest(path.read_bytes()), 'size': s.st_size, 'executable': bool(s.st_mode & 0o111)}
    return result


def git(source, *args):
    return subprocess.check_output(['git', '-C', str(source), *args], stderr=subprocess.DEVNULL)


def package(source, output, extras):
    """Package already-built bytes; never install dependencies or run build scripts."""
    source, output = canonical(source), canonical(output)
    require(not output.exists() and not output.is_relative_to(source), 'new_external_output_required')
    sha = git(source, 'rev-parse', 'HEAD').decode().strip()
    require(SHA.fullmatch(sha), 'full_sha_required')
    require(not git(source, 'status', '--porcelain', '--untracked-files=no'), 'dirty_source')
    archive = tarfile.open(fileobj=io.BytesIO(git(source, 'archive', '--format=tar', sha)))
    payload = {}
    for m in archive.getmembers():
        relative(m.name.rstrip('/'))
        require(m.isdir() or m.isfile(), 'archive_link_or_special')
        if m.isfile():
            require(m.name != '.env' and not m.name.startswith(('.env.', '.release', 'storage/')) or m.name == '.env.example', 'private_or_mutable_source')
            p = source / m.name
            regular(p)
            require(p.resolve() == p.absolute(), 'source_link')
            data = archive.extractfile(m).read()
            require(data == p.read_bytes(), 'build_changed_source')
            payload[m.name] = (data, bool(m.mode & 0o111))
    extra = read_json(canonical(extras))
    require(isinstance(extra, list) and len(extra) == len(set(extra)), 'exact_generated_list_required')
    vendor = files(source / 'vendor')
    require('autoload.php' in vendor, 'vendor_required')
    for name in [f'vendor/{p}' for p in vendor] + extra:
        relative(name)
        require(name not in payload, 'duplicate_payload')
        # No arbitrary ignored PHP/config; #59 refuses those too. Exact assets only.
        require(name.startswith('vendor/') or (name.startswith('public/') and Path(name).suffix in ('.js', '.css', '.json', '.svg', '.woff', '.woff2', '.ttf', '.png', '.jpg', '.webp', '.ico')), 'unapproved_generated_output')
        require(name not in ('public/hot',) and not name.startswith('public/storage/'), 'mutable_output')
        p = source / name
        regular(p)
        require(p.resolve() == p.absolute(), 'generated_link')
        payload[name] = (p.read_bytes(), bool(p.stat().st_mode & 0o111))
    require(len(payload) <= MAX_FILES and sum(len(x[0]) for x in payload.values()) <= MAX_BYTES, 'artifact_budget_exceeded')
    manifest = {'schema': SCHEMA, 'source_sha': sha, 'files': {n: {'sha256': digest(d), 'size': len(d), 'executable': e} for n, (d, e) in sorted(payload.items())}}
    # Uncompressed deterministic tar: dependency provenance is external to its digest.
    with output.open('xb') as out, tarfile.open(fileobj=out, mode='w') as tar:
        for name, (data, executable) in sorted({**payload, 'artifact-manifest.json': (encoded(manifest), False)}.items()):
            require(name not in payload or name != 'artifact-manifest.json', 'reserved_member')
            member = tarfile.TarInfo(name)
            member.size, member.mode, member.mtime = len(data), 0o755 if executable else 0o644, 0
            tar.addfile(member, io.BytesIO(data))
    return {'source_sha': sha, 'artifact_sha256': digest(output.read_bytes()), 'files': len(payload)}


def unpack(artifact, expected_digest, destination, manifest_output):
    artifact, destination, manifest_output = map(canonical, (artifact, destination, manifest_output))
    require(not manifest_output.exists() and not manifest_output.is_relative_to(destination), 'external_new_manifest_required')
    regular(artifact)
    require(DIGEST.fullmatch(expected_digest) and artifact.stat().st_size <= MAX_BYTES + 64 * 1024 * 1024, 'artifact_digest_or_budget')
    snapshot = artifact.read_bytes()
    require(digest(snapshot) == expected_digest, 'artifact_digest_mismatch')
    require(not destination.exists(), 'new_destination_required')
    with tarfile.open(fileobj=io.BytesIO(snapshot), mode='r:') as tar:
        members = tar.getmembers()
        require(len(members) <= MAX_FILES + 1 and len({m.name for m in members}) == len(members), 'duplicate_or_excess_members')
        require(sum(m.size for m in members) <= MAX_BYTES, 'extraction_budget')
        for m in members:
            relative(m.name)
            require(m.isfile() and m.mode in (0o644, 0o755), 'unsafe_archive_member')
        manifests = [m for m in members if m.name == 'artifact-manifest.json']
        require(len(manifests) == 1, 'manifest_required')
        manifest = json.loads(tar.extractfile(manifests[0]).read())
        require(manifest.get('schema') == SCHEMA and SHA.fullmatch(manifest.get('source_sha', '')), 'manifest_schema')
        require(destination.name == manifest['source_sha'], 'destination_sha_mismatch')
        require(set(manifest['files']) == {m.name for m in members} - {'artifact-manifest.json'}, 'artifact_members_mismatch')
        for m in members:
            if m.name == 'artifact-manifest.json':
                continue
            data = tar.extractfile(m).read()
            require(manifest['files'][m.name] == {'sha256': digest(data), 'size': len(data), 'executable': bool(m.mode & 0o111)}, 'artifact_member_mismatch')
        destination.mkdir(mode=0o700)
        for m in members:
            if m.name == 'artifact-manifest.json':
                continue
            p = destination / m.name
            p.parent.mkdir(parents=True, exist_ok=True)
            with p.open('xb') as f:
                f.write(tar.extractfile(m).read())
            p.chmod(m.mode)
    manifest['artifact_sha256'] = expected_digest
    manifest_output.write_bytes(encoded(manifest)); manifest_output.chmod(0o400)
    return {'state': 'unpacked_not_sealed', 'source_sha': manifest['source_sha']}


def shared_links(root, storage, configuration):
    root, storage, configuration = canonical(root), canonical(storage), canonical(configuration)
    require(storage.is_dir() and configuration.is_file(), 'shared_targets_required')
    require(not storage.is_relative_to(root) and not configuration.is_relative_to(root), 'shared_outside_release_required')
    regular(configuration)  # No contents read or copied.
    links = {'storage': str(storage), '.env': str(configuration)}
    for name in links:
        require(not (root / name).exists() and not (root / name).is_symlink(), 'shared_path_would_be_replaced')
    for name, target in links.items():
        (root / name).symlink_to(target)
    return links


def protect(root):
    """Remove write bits in candidate only. Never follows links; not ACL enforcement."""
    for directory, dirs, names in os.walk(root, topdown=False, followlinks=False):
        for name in names:
            p = Path(directory) / name
            if not p.is_symlink():
                s = regular(p)
                p.chmod(stat.S_IMODE(s.st_mode) & ~0o222)
        for name in dirs:
            p = Path(directory) / name
            if not p.is_symlink():
                p.chmod(stat.S_IMODE(p.stat().st_mode) & ~0o222)
    root.chmod(stat.S_IMODE(root.stat().st_mode) & ~0o222)


def seal(root, source, preparer, preparer_digest, links, receipt, artifact_manifest):
    root, source, preparer, receipt = map(canonical, (root, source, preparer, receipt))
    require(not receipt.exists() and not receipt.is_relative_to(root), 'external_new_receipt_required')
    require(preparer_digest == 'e2edc3133f20318a442fc08da15344872daaeba05aad763eda4d50a8a9c4d125' and digest(preparer.read_bytes()) == preparer_digest, 'identity_preparer_mismatch')
    transport = read_json(canonical(artifact_manifest))
    require(transport.get('source_sha') == root.name and DIGEST.fullmatch(transport.get('artifact_sha256', '')), 'transport_binding')
    validate_links(root, links)
    require(all((root / 'bootstrap/cache' / name).is_file() for name in ('packages.php', 'services.php')), 'bootstrap_manifests_required')
    before = files(root, links)
    require(all(before.get(name) == entry for name, entry in transport['files'].items()), 'transport_bytes_changed')
    require(all(name in transport['files'] or name.startswith('bootstrap/cache/') for name in before), 'unapproved_local_addition')
    # This calls the independently audited #59 contract, never reimplements/weakens it.
    process = subprocess.run([sys.executable, '-I', '-B', str(preparer), 'seal', '--source', str(source), '--root', str(root)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    require(process.returncode == 0, 'identity_seal_failed')
    validate_links(root, links)
    inventory = files(root, links)
    require('.release/identity.php' in inventory and '.release/manifest.json' in inventory, 'identity_missing')
    protect(root)
    receipt.write_bytes(encoded({'schema': SCHEMA, 'root': str(root), 'source_sha': root.name, 'artifact_sha256': transport['artifact_sha256'], 'files': inventory, 'links': links}))
    receipt.chmod(0o400)
    return {'state': 'prepared_not_active', 'source_sha': root.name}


def validate_links(root, links):
    require(isinstance(links, dict) and set(links).issubset({'storage', '.env'}), 'shared_link_allowlist')
    for name, value in links.items():
        target = canonical(value)
        require(not target.is_relative_to(root), 'shared_outside_release_required')
        require(target.is_dir() if name == 'storage' else target.is_file(), 'shared_target_type')


def verify(root, receipt):
    root, receipt = canonical(root), canonical(receipt)
    r = read_json(receipt)
    require(r.get('schema') == SCHEMA and r.get('root') == str(root) and r.get('source_sha') == root.name and SHA.fullmatch(root.name), 'receipt_binding')
    validate_links(root, r.get('links'))
    require(set(('.release/identity.php', '.release/manifest.json', '.release/verify.lock')).issubset(r.get('files', {})), 'identity_missing')
    identity_manifest = read_json(root / '.release/manifest.json')
    require(identity_manifest.get('root') == str(root) and identity_manifest.get('release') == root.name, 'identity_manifest_binding')
    require(files(root, r['links']) == r['files'], 'release_integrity_mismatch')
    for rel, target in r['links'].items():
        require((root / rel).is_symlink() and os.readlink(root / rel) == target and Path(target).exists(), 'shared_link_mismatch')
    for directory, dirs, names in os.walk(root, followlinks=False):
        require(not Path(directory).stat().st_mode & 0o222, 'release_directory_writable')
        for name in names:
            p = Path(directory) / name
            if not p.is_symlink():
                require(not p.stat().st_mode & 0o222, 'release_file_writable')
    return r


@contextmanager
def lock(path, exclusive=True, nonblocking=True):
    path = canonical(path)
    regular(path)  # Provision lock outside releases; never replaces an existing path.
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        fcntl.flock(fd, (fcntl.LOCK_EX if exclusive else fcntl.LOCK_SH) | (fcntl.LOCK_NB if nonblocking else 0))
        yield
    finally:
        os.close(fd)


def current_target(base):
    link = base / 'current'
    require(link.is_symlink(), 'existing_current_required_no_bootstrap')
    target = link.resolve(strict=True)
    require(target.is_relative_to(base / 'releases') and SHA.fullmatch(target.name) and target.is_dir(), 'current_outside_releases')
    return target


def policy(path, root, base, receipt):
    p = read_json(canonical(path))
    required_true = ('target_binding_verified', 'php_cli_platform_verified', 'php_fpm_platform_verified', 'extensions_verified', 'fastcgi_physical_root_verified', 'permissions_acl_verified', 'runtime_cannot_write_release', 'workers_drained', 'shared_drivers_verified', 'schema_compatible', 'recovery_verified', 'publication_authorized', 'caches_finalized', 'laravel_boot_verified', 'assets_complete_verified', 'scheduler_descendants_absent_verified', 'build_provenance_verified')
    require(p.get('schema') == SCHEMA and p.get('release') == root.name, 'policy_release_binding')
    require(p.get('base') == str(base) and p.get('root') == str(root)
            and p.get('receipt_sha256') == digest(canonical(receipt).read_bytes())
            and p.get('node_sha256') == node_fingerprint()
            and p.get('operator_uid') == os.geteuid(), 'policy_target_binding')
    require(all(p.get(k) is True for k in required_true), 'runtime_evidence_incomplete')
    require(p.get('fpm_opcache_enabled') is False and p.get('preload_configured_or_loaded') is False, 'identity_runtime_incompatible')
    require(type(p.get('runtime_uid')) is int and p['runtime_uid'] != os.geteuid() and p['runtime_uid'] > 0, 'separate_runtime_principal_required')
    pwd.getpwuid(p['runtime_uid'])
    require(base.stat().st_uid != p['runtime_uid'] and (base / 'releases').stat().st_uid != p['runtime_uid'], 'runtime_owns_control_directory')
    require(all(Path(directory).stat().st_uid != p['runtime_uid'] for directory, _, _ in os.walk(root, followlinks=False)), 'runtime_owns_release_directory')
    require(all((Path(directory) / name).stat().st_uid != p['runtime_uid'] for directory, _, names in os.walk(root, followlinks=False) for name in names if not (Path(directory) / name).is_symlink()), 'runtime_owns_release_file')
    require(p.get('php_binary') and canonical(p['php_binary']).is_file(), 'explicit_php_binary_required')
    require(p.get('evidence_reference') and p.get('expires_at_epoch', 0) >= __import__('time').time(), 'evidence_expired')
    require(p.get('fpm_reload_mode') == 'verify-only' and not p.get('fpm_service'), 'service_action_not_implemented')
    validate_verifier(p)
    return p


def node_fingerprint():
    # Private machine binding, never printed in status output. Missing ID is a blocker.
    return digest(Path('/etc/machine-id').read_bytes() + socket.gethostname().encode())


def validate_verifier(p):
    script = canonical(p.get('verifier_script', ''))
    require(script.is_file() and digest(script.read_bytes()) == 'f84142159b72e813d950a7135d538ced93ceaaae4a3a7cd5ba951192bc31dfdf', 'verifier_script_mismatch')
    environment = verifier_environment(p, p['release'])
    result = subprocess.run([sys.executable, '-I', '-B', str(script), 'preflight'], env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=30)
    require(result.returncode == 0, 'verifier_preflight_failed')


def verifier_environment(p, release):
    return {'PATH': '/usr/bin:/bin', 'LC_ALL': 'C', 'EXPECTED_RELEASE': release,
            'DEPLOY_PUBLIC_BASE_URL': p.get('public_base_url') or '',
            'DEPLOY_RELEASE_URL': p.get('release_url') or '',
            'FPM_RELOAD_MODE': p.get('fpm_reload_mode') or '',
            'FPM_SERVICE': p.get('fpm_service') or ''}


def run_verifier(p, root):
    script = canonical(p.get('verifier_script', ''))
    require(script.is_file() and digest(script.read_bytes()) == 'f84142159b72e813d950a7135d538ced93ceaaae4a3a7cd5ba951192bc31dfdf', 'verifier_script_mismatch')
    # Exact PR57 protocol and script; no arbitrary hooks, payloads or inherited env.
    environment = verifier_environment(p, root.name)
    result = subprocess.run([sys.executable, '-I', '-B', str(script), 'verify'], env=environment,
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=120)
    require(result.returncode == 0, 'post_activation_verification_failed')


def sync_directory(directory):
    fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def durable_json(path, value):
    temporary = path.with_name('.record-' + uuid.uuid4().hex)
    fd = os.open(temporary, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as out:
        out.write(encoded(value)); out.flush(); os.fsync(out.fileno())
    os.replace(temporary, path)
    sync_directory(path.parent)


def activate(base, candidate, receipt, policy_file, expected_previous, rollback=False):
    base, candidate, expected_previous = map(canonical, (base, candidate, expected_previous))
    require(candidate.is_relative_to(base / 'releases') and candidate != expected_previous, 'candidate_layout')
    with lock(base / 'activation.lock'):
        # Hold exclusive scheduler lock until post-verification; jobs cannot mix generations.
        with lock(base / 'scheduler.lock'):
            p = policy(policy_file, candidate, base, receipt)
            if rollback:
                require(p.get('rollback_code_compatible') is True and p.get('data_restore_required') is False, 'unsafe_code_rollback')
            latch = base / 'activation-blocked.json'
            require(rollback or not latch.exists(), 'activation_recovery_required')
            require(current_target(base) == expected_previous, 'previous_changed')
            verify(candidate, receipt)
            scheduler_contract(candidate)
            require(candidate.stat().st_dev == base.stat().st_dev, 'cross_device_activation')
            journal = base / ('activation-' + uuid.uuid4().hex + '.json')
            durable_json(latch, {'state': 'switch_intent', 'candidate': str(candidate), 'previous': str(expected_previous)})
            durable_json(journal, {'state': 'switch_intent', 'candidate': str(candidate), 'previous': str(expected_previous)})
            temporary = base / ('.current-' + uuid.uuid4().hex)
            temporary.symlink_to(candidate)
            require(p['expires_at_epoch'] >= __import__('time').time(), 'evidence_expired')
            os.replace(temporary, base / 'current')
            sync_directory(base)  # Persist the pointer before observing the new release.
            try:
                run_verifier(p, candidate)
                verify(candidate, receipt)
                require(p['expires_at_epoch'] >= __import__('time').time(), 'evidence_expired')
                require(current_target(base) == candidate, 'current_changed_during_verification')
            except Exception:
                durable_json(journal, {'state': 'activation_unverified_manual_recovery_required', 'candidate': str(candidate), 'previous': str(expected_previous)})
                raise
            durable_json(journal, {'state': 'verified', 'candidate': str(candidate), 'previous': str(expected_previous)})
            latch.unlink()
            sync_directory(base)
    return {'state': 'verified', 'release': candidate.name, 'code_rollback': rollback}


def scheduler_contract(root):
    console = root / 'routes/console.php'
    require(console.is_file() and b'runInBackground' not in console.read_bytes(), 'background_scheduler_not_supported')


def scheduler_identity(evidence, base, root):
    uid, gid = evidence.get('runtime_uid'), evidence.get('runtime_gid')
    require(type(uid) is int and uid > 0 and uid != os.geteuid() and type(gid) is int and gid >= 0, 'scheduler_runtime_identity_required')
    require(pwd.getpwuid(uid).pw_gid == gid, 'scheduler_primary_group_mismatch')
    require(base.stat().st_uid != uid and (base / 'releases').stat().st_uid != uid, 'scheduler_runtime_owns_control')
    for directory, _, names in os.walk(root, followlinks=False):
        require(Path(directory).stat().st_uid != uid, 'scheduler_runtime_owns_code')
        for name in names:
            path = Path(directory) / name
            if not path.is_symlink():
                require(path.stat().st_uid != uid, 'scheduler_runtime_owns_code')
    require(evidence.get('runtime_permissions_verified') is True, 'scheduler_permissions_unverified')


def launch_scheduler(php, root, evidence, timeout):
    # A privileged, separately authorized controller is needed to change UID/GID.
    # Never fall back to the controller identity if the OS denies credential switch.
    return subprocess.run([str(php), str(root / 'artisan'), 'schedule:run'], cwd=root,
                          user=evidence['runtime_uid'], group=evidence['runtime_gid'], extra_groups=[], umask=0o077,
                          env={'PATH': '/usr/bin:/bin', 'LC_ALL': 'C'}, stdout=subprocess.DEVNULL,
                          stderr=subprocess.DEVNULL, timeout=timeout, start_new_session=True)


def schedule(base, php, receipt_directory, evidence_directory, timeout=3600):
    base, php = canonical(base), canonical(php)
    require(php.is_file() and os.access(php, os.X_OK), 'explicit_php_required')
    with lock(base / 'scheduler.lock'):
        require(not (base / 'activation-blocked.json').exists(), 'activation_recovery_required')
        root = current_target(base)  # Resolve exactly once under the same switch lock.
        receipt_directory = canonical(receipt_directory)
        receipt = receipt_directory / root.relative_to(base / 'releases').with_suffix('.json')
        verify(root, receipt)
        evidence = read_json(canonical(evidence_directory) / root.relative_to(base / 'releases').with_suffix('.json'))
        require(evidence.get('base') == str(base) and evidence.get('root') == str(root)
                and evidence.get('receipt_sha256') == digest(receipt.read_bytes())
                and evidence.get('node_sha256') == node_fingerprint(), 'scheduler_target_binding')
        require(evidence.get('php_binary') == str(php) and evidence.get('php_binary_sha256') == digest(php.read_bytes()), 'scheduler_php_binding')
        require(evidence.get('platform_verified') is True and evidence.get('no_descendants_verified') is True
                and evidence.get('expires_at_epoch', 0) >= __import__('time').time(), 'scheduler_evidence_incomplete')
        scheduler_contract(root)
        scheduler_identity(evidence, base, root)
        latch = base / 'activation-blocked.json'
        durable_json(latch, {'state': 'scheduler_in_flight', 'root': str(root)})
        try:
            result = launch_scheduler(php, root, evidence, timeout)
            require(result.returncode == 0, 'scheduler_failed')
            durable_json(base / 'scheduler-last-result.json', {'state': 'finished', 'root': str(root)})
            latch.unlink()
            sync_directory(base)
        except Exception:
            durable_json(base / 'activation-blocked.json', {'state': 'scheduler_failure_runtime_review_required', 'root': str(root)})
            raise
    return {'state': 'scheduler_finished', 'release': root.name}


class SafeParser(argparse.ArgumentParser):
    def error(self, message):
        self.exit(2, '{"state":"blocked","reason":"invalid_arguments"}\n')


def main():
    # The historical primitives remain available for pinned contract checks,
    # but their standalone CLI cannot activate without the supervised runtime gate.
    if len(sys.argv) > 1 and sys.argv[1] in ('activate', 'rollback'):
        print(json.dumps({'state': 'blocked', 'reason': 'legacy_activation_disabled'}))
        return 1
    ap = SafeParser(description=__doc__)
    sp = ap.add_subparsers(dest='command', required=True)
    p = sp.add_parser('package'); p.add_argument('--source', required=True); p.add_argument('--output', required=True); p.add_argument('--extras', required=True)
    p = sp.add_parser('unpack'); p.add_argument('--artifact', required=True); p.add_argument('--expected-digest', required=True); p.add_argument('--destination', required=True); p.add_argument('--manifest-output', required=True)
    p = sp.add_parser('link-shared'); p.add_argument('--root', required=True); p.add_argument('--storage', required=True); p.add_argument('--configuration', required=True)
    p = sp.add_parser('seal'); p.add_argument('--root', required=True); p.add_argument('--source', required=True); p.add_argument('--preparer', required=True); p.add_argument('--preparer-digest', required=True); p.add_argument('--links-json', required=True); p.add_argument('--receipt', required=True); p.add_argument('--artifact-manifest', required=True)
    for name in ('activate', 'rollback'):
        p = sp.add_parser(name)
        for key in ('base', 'candidate', 'receipt', 'policy-file', 'expected-previous'):
            p.add_argument('--' + key, required=True)
    p = sp.add_parser('schedule')
    for key in ('base', 'php', 'receipt-directory', 'evidence-directory'):
        p.add_argument('--' + key, required=True)
    args = vars(ap.parse_args()); command = args.pop('command')
    try:
        if command == 'seal':
            args['links'] = read_json(canonical(args.pop('links_json')))
        if command in ('activate', 'rollback'):
            result = activate(**args, rollback=command == 'rollback')
        else:
            result = globals()[{'link-shared': 'shared_links'}.get(command, command)](**args)
        print(json.dumps(result, sort_keys=True))
        return 0
    except Refusal as error:
        print(json.dumps({'state': 'blocked', 'reason': str(error)}))
    except Exception:
        print(json.dumps({'state': 'blocked', 'reason': 'operation_failed_sanitized'}))
    return 1


if __name__ == '__main__':
    sys.exit(main())
