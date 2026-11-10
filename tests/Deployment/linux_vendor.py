"""Separate, lock-bound dependency snapshots for historical and candidate runtimes."""
import argparse
import hashlib
import io
import json
import os
import pathlib
import shutil
import subprocess
import tarfile
from linux_release_common import ROOT, BASELINE, safe_extract_source_archive, sha


def package_identity(package):
    return {'name': package['name'], 'version': package['version'],
            'reference': (package.get('dist') or package.get('source') or {}).get('reference')}


def vendor_manifest(root):
    vendor = root / 'vendor'
    if not vendor.is_dir():
        raise RuntimeError('Missing isolated vendor')
    rows = []
    for path in sorted(vendor.rglob('*')):
        if path.is_symlink():
            raise RuntimeError('Vendor snapshots reject all symbolic links, including internal directory links')
        if path.is_file():
            rows.append({'path': path.relative_to(vendor).as_posix(), 'sha256': sha(path), 'bytes': path.stat().st_size})
    if not rows:
        raise RuntimeError('Empty vendor snapshot')
    return rows


def check_packages(root):
    lock = json.loads((root / 'composer.lock').read_text())
    expected = sorted([package_identity(p) for p in lock.get('packages', []) + lock.get('packages-dev', [])], key=lambda p: p['name'])
    installed = json.loads((root / 'vendor/composer/installed.json').read_text())
    if not isinstance(installed, dict) or installed.get('dev') is not True:
        raise RuntimeError('Complete development dependencies required')
    actual = sorted([package_identity(p) for p in installed['packages']], key=lambda p: p['name'])
    if actual != expected:
        raise RuntimeError('Installed packages do not match their paired lockfile')
    return actual


def receipt(root, origin):
    packages = check_packages(root)
    rows = vendor_manifest(root)
    result = {'composer_json_sha256': sha(root / 'composer.json'), 'composer_lock_sha256': sha(root / 'composer.lock'),
              'installed_json_sha256': sha(root / 'vendor/composer/installed.json'), 'packages': packages,
              'vendor_files': rows, 'vendor_tree_sha256': hashlib.sha256(json.dumps(rows, separators=(',', ':')).encode()).hexdigest(),
              'origin': origin}
    (root / 'dependency-receipt.json').write_text(json.dumps(result, indent=2) + '\n', encoding='utf-8')
    return result


def verify_snapshot(snapshot, runtime):
    """Check both immutable source lock and every installed byte before reuse."""
    expected = json.loads((snapshot / 'dependency-receipt.json').read_text())
    for name, field in [('composer.json', 'composer_json_sha256'), ('composer.lock', 'composer_lock_sha256')]:
        if sha(runtime / name) != expected[field] or sha(snapshot / name) != expected[field]:
            raise RuntimeError('Historical/candidate dependency lock pairing mismatch')
    if check_packages(snapshot) != expected['packages'] or vendor_manifest(snapshot) != expected['vendor_files']:
        raise RuntimeError('Dependency snapshot bytes changed after setup')
    if sha(snapshot / 'vendor/composer/installed.json') != expected['installed_json_sha256']:
        raise RuntimeError('Installed package evidence changed')
    return expected


def copy_verified_vendor(snapshot, runtime):
    expected = verify_snapshot(snapshot, runtime)
    if (runtime / 'vendor').exists():
        raise RuntimeError('Refusing to overwrite runtime vendor')
    shutil.copytree(snapshot / 'vendor', runtime / 'vendor', symlinks=True)
    if vendor_manifest(runtime) != expected['vendor_files']:
        raise RuntimeError('Copied vendor byte verification failed')
    return {'snapshot': str(snapshot), 'composer_lock_sha256': expected['composer_lock_sha256'],
            'vendor_tree_sha256': expected['vendor_tree_sha256'], 'package_count': len(expected['packages']),
            'vendor_file_count': len(expected['vendor_files']), 'receipt_sha256': sha(snapshot / 'dependency-receipt.json'),
            'origin': expected['origin'], 'copy_byte_verified': True}


def setup(destination):
    if os.environ.get('RELEASE_DISPOSABLE_TESTS') != '1' or os.environ.get('RELEASE_DEPENDENCY_SETUP') != '1':
        raise RuntimeError('Dependency setup requires both explicit disposable and setup opt-ins')
    if destination.exists():
        raise RuntimeError('Dependency snapshot destination already exists; never reuse uncertain setup')
    destination.mkdir(parents=True)
    baseline, candidate = destination / 'baseline', destination / 'candidate'
    baseline.mkdir(); candidate.mkdir()
    archive = subprocess.check_output(['git', 'archive', BASELINE], cwd=ROOT)
    with tarfile.open(fileobj=io.BytesIO(archive)) as source:
        safe_extract_source_archive(source, baseline)
    # The exact historical source, not the updated candidate lock, drives install.
    for name in ('composer.json', 'composer.lock'):
        expected = subprocess.check_output(['git', 'show', BASELINE + ':' + name], cwd=ROOT)
        if (baseline / name).read_bytes() != expected:
            raise RuntimeError('Historical dependency source differs from reviewed commit')
    command = ['composer', 'install', '--no-interaction', '--prefer-dist', '--optimize-autoloader', '--no-scripts', '--no-plugins']
    subprocess.run(command, cwd=baseline, check=True)
    baseline_result = receipt(baseline, {'kind': 'official-composer-locked-install', 'baseline_commit': BASELINE,
                                      'archive_sha256': hashlib.sha256(archive).hexdigest(), 'command': command,
                                      'composer_version': subprocess.check_output(['composer', '--version', '--no-ansi'], text=True).strip()})
    # Freeze a separate candidate vendor before static checks regenerate autoload files.
    check_packages(ROOT)
    for name in ('composer.json', 'composer.lock'):
        shutil.copyfile(ROOT / name, candidate / name)
    before = vendor_manifest(ROOT)
    shutil.copytree(ROOT / 'vendor', candidate / 'vendor', symlinks=True)
    if vendor_manifest(candidate) != before:
        raise RuntimeError('Candidate dependency snapshot copy changed bytes')
    candidate_result = receipt(candidate, {'kind': 'verified-candidate-locked-install-snapshot', 'source': str(ROOT / 'vendor'),
                                         'copy_byte_verified': True})
    result = {role: {k: value[k] for k in ('composer_json_sha256', 'composer_lock_sha256', 'installed_json_sha256', 'vendor_tree_sha256', 'origin')}
              for role, value in [('baseline', baseline_result), ('candidate', candidate_result)]}
    (destination / 'setup-evidence.json').write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result), flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['setup'])
    parser.add_argument('--destination', type=pathlib.Path, required=True)
    arguments = parser.parse_args()
    setup(arguments.destination.resolve())
