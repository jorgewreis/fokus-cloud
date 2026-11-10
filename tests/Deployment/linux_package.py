"""Lock-bound production assembly and offline smoke. Staging only, never deployment."""
import argparse
import hashlib
import json
import os
import pathlib
import re
import shutil
import stat
import subprocess
import tarfile
import tempfile

from linux_release_common import ROOT, sha, safe_extract_source_archive
from linux_vendor import package_identity, vendor_manifest

SOURCE_DIRS = {'app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes'}
SOURCE_FILES = {'artisan', 'composer.json', 'composer.lock', 'LICENSE.md'}
UNSAFE_PARTS = {'.git', '.github', 'node_modules', '__pycache__', 'storage', 'uploads', 'backups',
                'test-results', 'playwright-report', 'tests', '.ssh', '.aws'}


def write(path, data):
    path.write_text(json.dumps(data, indent=2) + '\n', encoding='utf-8')


def digest(rows):
    return hashlib.sha256(json.dumps(rows, separators=(',', ':')).encode()).hexdigest()


def safe_relative(value):
    if not isinstance(value, str) or not value or value.startswith('/') or '\\' in value or ':' in value:
        raise RuntimeError('Unsafe package path')
    if any(p in ('', '.', '..') for p in value.split('/')):
        raise RuntimeError('Unsafe package path')
    return pathlib.PurePosixPath(value)


def source_allowed(value):
    path = safe_relative(value)
    if path.parts[0] not in SOURCE_DIRS and value not in SOURCE_FILES:
        return False
    if any(p in UNSAFE_PARTS or p == 'vendor' or p.startswith('.env') for p in path.parts):
        return False
    if value.startswith('bootstrap/cache/') or value in ('public/hot', 'database/database.sqlite'):
        return False
    if path.name.startswith('.') and value != 'public/.htaccess':
        return False
    if path.suffix.lower() in {'.pyc', '.pyo', '.pem', '.key', '.sql', '.sqlite', '.log', '.bak'}:
        return False
    return True


def real_file(root, relative):
    path = safe_relative(relative)
    current = root
    for part in path.parts:
        current = current / part
        if current.is_symlink():
            raise RuntimeError('Symbolic link rejected: ' + relative)
    if not current.is_file() or not stat.S_ISREG(current.stat().st_mode):
        raise RuntimeError('Not a regular package file: ' + relative)
    return current


def production_packages(root):
    lock = json.loads((root / 'composer.lock').read_text())
    installed = json.loads((root / 'vendor/composer/installed.json').read_text())
    if not isinstance(installed, dict) or installed.get('dev') is not False:
        raise RuntimeError('Production vendor must explicitly have dev=false')
    expected = sorted((package_identity(p) for p in lock['packages']), key=lambda p: p['name'])
    actual = sorted((package_identity(p) for p in installed['packages']), key=lambda p: p['name'])
    if not expected or actual != expected:
        raise RuntimeError('Production installed packages differ from lock packages (no packages-dev allowed)')
    if set(p['name'] for p in actual) & set(p['name'] for p in lock.get('packages-dev', [])):
        raise RuntimeError('Development dependency leaked into production')
    return actual


def receipt(root):
    rows = vendor_manifest(root)
    return {'composer_json_sha256': sha(root / 'composer.json'), 'composer_lock_sha256': sha(root / 'composer.lock'),
            'installed_json_sha256': sha(root / 'vendor/composer/installed.json'),
            'packages': production_packages(root), 'vendor_files': rows, 'vendor_tree_sha256': digest(rows)}


def verify_snapshot(snapshot, source):
    expected = json.loads((snapshot / 'production-receipt.json').read_text())
    actual = receipt(snapshot)
    if actual != expected['snapshot']:
        raise RuntimeError('Production vendor snapshot changed after setup')
    for name in ('composer.json', 'composer.lock'):
        if sha(source / name) != actual[name.replace('.', '_') + '_sha256']:
            raise RuntimeError('Production vendor paired with another source lock')
    return actual


def isolated_environment(home, runtime):
    # Never inherit credentials, integration URLs, DB targets or Composer authentication.
    env = {k: os.environ[k] for k in ('PATH', 'TMPDIR') if k in os.environ}
    env.update(HOME=str(home), COMPOSER_HOME=str(home / 'composer'), COMPOSER_CACHE_DIR=str(home / 'composer-cache'),
               COMPOSER_ALLOW_SUPERUSER='1', COMPOSER_DISABLE_NETWORK='1', PYTHONDONTWRITEBYTECODE='1',
               RELEASE_DISPOSABLE_TESTS='1', APP_ENV='production', APP_DEBUG='false',
               APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', APP_URL='http://localhost',
               APP_MAINTENANCE_DRIVER='file', DB_CONNECTION='sqlite', DB_DATABASE=':memory:',
               DB_URL='', DATABASE_URL='', CACHE_STORE='database', CACHE_LIMITER='database', SESSION_DRIVER='database',
               QUEUE_CONNECTION='sync', MAIL_MAILER='array', BROADCAST_CONNECTION='null', FILESYSTEM_DISK='local',
               LAW_TASKS_ENABLED='false', LOG_CHANNEL='stderr', BCRYPT_ROUNDS='4',
               APP_CONFIG_CACHE=str(runtime / 'config.php'), APP_EVENTS_CACHE=str(runtime / 'events.php'),
               APP_PACKAGES_CACHE=str(runtime / 'packages.php'), APP_SERVICES_CACHE=str(runtime / 'services.php'),
               APP_ROUTES_CACHE=str(runtime / 'routes.php'), VIEW_COMPILED_PATH=str(runtime / 'views'),
               DATAJUD_API_KEY='', MERCADO_PAGO_ACCESS_TOKEN='', MERCADO_PAGO_WEBHOOK_SECRET='',
               FOKUS_USAGE_INGESTION_SECRET='', PULSE_ENABLED='false', TELESCOPE_ENABLED='false', NIGHTWATCH_ENABLED='false')
    return env


def command(argv, cwd, env, report, name):
    with (report / (name + '.log')).open('wb') as output:
        result = subprocess.run(argv, cwd=cwd, env=env, stdout=output, stderr=subprocess.STDOUT, timeout=600)
    if result.returncode:
        raise RuntimeError(name + ' failed; see package report')


def setup(destination):
    if os.environ.get('RELEASE_PRODUCTION_SETUP') != '1':
        raise RuntimeError('Production setup requires RELEASE_PRODUCTION_SETUP=1')
    if destination.exists():
        raise RuntimeError('Refusing to overwrite/reuse production dependency destination')
    destination.mkdir(parents=True)
    # Source is needed for a genuinely optimized application autoloader, not an empty skeleton.
    for folder in ('app', 'database'):
        for source in sorted((ROOT / folder).rglob('*')):
            relative = source.relative_to(ROOT).as_posix()
            if source.is_file() and source_allowed(relative):
                target = destination / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(real_file(ROOT, relative), target)
    for name in ('composer.json', 'composer.lock'):
        shutil.copyfile(ROOT / name, destination / name)
    before = {name: sha(destination / name) for name in ('composer.json', 'composer.lock')}
    with tempfile.TemporaryDirectory(prefix='production-composer-') as private:
        env = isolated_environment(pathlib.Path(private), pathlib.Path(private))
        env.pop('COMPOSER_DISABLE_NETWORK')  # Setup is downloads only; no scripts/plugins.
        install = ['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist',
                   '--optimize-autoloader', '--no-scripts', '--no-plugins']
        command(install, destination, env, destination, 'composer-production-install')
    if before != {name: sha(destination / name) for name in before}:
        raise RuntimeError('Composer setup changed reviewed input')
    result = {'snapshot': receipt(destination), 'command': install, 'source_locks_unchanged': True,
              'composer_version': subprocess.check_output(['composer', '--version', '--no-ansi'], text=True).strip()}
    write(destination / 'production-receipt.json', result)
    print('Production snapshot:', len(result['snapshot']['packages']), 'locked runtime packages', flush=True)


def generated_paths(root, report):
    law = json.loads(real_file(root, 'config/fokus-law-assets.json').read_text())
    if law.get('algorithm') != 'sha256' or law.get('version') != 1 or len(law['assets']) != 15:
        raise RuntimeError('Unexpected Fokus Law asset manifest')
    paths = {'config/fokus-law-assets.json'}
    for original, item in law['assets'].items():
        source = real_file(root, 'public/' + str(safe_relative(original)))
        path = 'public/' + str(safe_relative(item['path']))
        if not source_allowed(path) or item['sha256'] != sha(source) or sha(real_file(root, path)) != item['sha256']:
            raise RuntimeError('Content-addressed asset mismatch')
        if '.' + item['sha256'] + '.' not in path:
            raise RuntimeError('Immutable asset lacks content hash')
        paths.add(path)
    paths.add('public/build/manifest.json')
    vite = json.loads(real_file(root, 'public/build/manifest.json').read_text())
    for item in vite.values():
        for asset in [item['file'], *item.get('css', []), *item.get('assets', [])]:
            paths.add('public/build/' + str(safe_relative(asset)))
    # Every Vite output, including emitted fonts, is covered in the exact byte inventory.
    for item in (root / 'public/build').rglob('*'):
        if item.is_symlink():
            raise RuntimeError('Vite output symbolic link rejected')
        if item.is_file():
            relative = item.relative_to(root).as_posix()
            if not source_allowed(relative) or item.suffix.lower() not in {'.json', '.css', '.js', '.woff', '.woff2'}:
                raise RuntimeError('Unexpected Vite output')
            paths.add(relative)
    font_evidence = json.loads((report.parent / 'font-cache-manifest.json').read_text())
    expected_fonts = {row['sha256'] for row in font_evidence['files'] if row['url'] != font_evidence['css_url']}
    fonts = [real_file(root, p) for p in paths if pathlib.Path(p).suffix in ('.woff', '.woff2')]
    if not fonts or not all(sha(p) in expected_fonts for p in fonts):
        raise RuntimeError('Generated fonts differ from reviewed cache')
    offline = json.loads((report.parent / 'font-cache-offline.json').read_text())
    if offline.get('present_cache_exit') != 0 or not offline.get('fetch_blocked') or not offline.get('cache_bytes_unchanged'):
        raise RuntimeError('Offline build evidence required')
    license_file = report.parent / 'instrument-sans-OFL.txt'
    if sha(license_file) != font_evidence['license']['sha256']:
        raise RuntimeError('Font license identity mismatch')
    return paths, {'fonts': [{'path': str(p.relative_to(root)), 'sha256': sha(p)} for p in fonts],
                   'offline_build_sha256': sha(report.parent / 'font-cache-offline.json'),
                   'cache_manifest_sha256': sha(report.parent / 'font-cache-manifest.json')}, license_file


def verify_build_templates(snapshot, root):
    # resources/css/app.css explicitly scans these vendor views. A vendor-less
    # build can succeed but omit pagination CSS, so compare the actual source bytes.
    relative = pathlib.Path('vendor/laravel/framework/src/Illuminate/Pagination/resources/views')
    expected = sorted((snapshot / relative).glob('*.blade.php'))
    actual = sorted((root / relative).glob('*.blade.php'))
    if not expected or [p.name for p in actual] != [p.name for p in expected]:
        raise RuntimeError('Required locked Laravel pagination build templates missing')
    rows = []
    for path in expected:
        name = (relative / path.name).as_posix()
        if sha(real_file(root, name)) != sha(path):
            raise RuntimeError('Laravel pagination build template differs from production lock')
        rows.append({'path': name, 'sha256': sha(path)})
    return rows


def build_output_allowlist(root, source_rows):
    # These are the exact destinations of the three reviewed generators in npm run build.
    # Vite and content-addressed new files are independently checked by generated_paths().
    allowed = {'public/assets/css/shared/fokus.css': 'tools/sync-fokus-styles.mjs'}
    for row in json.loads(real_file(root, 'resources/seo/pages.json').read_text()):
        path = 'public/' + str(safe_relative(row['file']))
        if path not in source_rows or not path.endswith('.html'):
            raise RuntimeError('SEO output is not a reviewed source HTML page')
        allowed[path] = 'tools/sync-seo.mjs + resources/seo/pages.json'
    for path in source_rows:
        if path.startswith('public/styles/') and path.endswith('.html'):
            allowed[path] = 'tools/cache-bust-styles-pages.mjs (tracked Styles HTML only)'
    return allowed


def inventory(root):
    rows = []
    for path in sorted(root.rglob('*')):
        relative = path.relative_to(root).as_posix()
        if path.is_symlink() or not (path.is_file() or path.is_dir()):
            raise RuntimeError('Unsafe package node: ' + relative)
        expected_mode = 0o755 if path.is_dir() else 0o644
        if stat.S_IMODE(path.stat().st_mode) != expected_mode:
            raise RuntimeError('Unexpected package mode: ' + relative)
        rows.append({'path': relative, 'type': 'directory' if path.is_dir() else 'file',
                     'mode': format(expected_mode, '04o'), **({'bytes': path.stat().st_size, 'sha256': sha(path)} if path.is_file() else {})})
    return rows


def verify_package_boundary(root):
    for row in inventory(root):
        relative = row['path']
        if relative == 'vendor' or relative.startswith('vendor/'):
            continue  # Independently exact-match the pristine production vendor receipt.
        if relative == 'licenses' or relative == 'licenses/Instrument-Sans-OFL.txt':
            continue
        if not source_allowed(relative):
            raise RuntimeError('Forbidden packaged runtime or QA input: ' + relative)
    if any((root / 'bootstrap/cache').iterdir()):
        raise RuntimeError('Packaged bootstrap/cache must be empty')


def archive_package(root, destination):
    with tarfile.open(destination, 'w', format=tarfile.USTAR_FORMAT) as archive:
        for row in inventory(root):
            info = tarfile.TarInfo(row['path'])
            info.mode = int(row['mode'], 8)
            info.mtime = 0; info.uid = 0; info.gid = 0; info.uname = ''; info.gname = ''
            if row['type'] == 'directory':
                info.type = tarfile.DIRTYPE
                archive.addfile(info)
            else:
                info.size = row['bytes']
                with (root / row['path']).open('rb') as handle:
                    archive.addfile(info, handle)


def smoke(package, report):
    with tempfile.TemporaryDirectory(prefix='production-smoke-') as private:
        private = pathlib.Path(private)
        runtime = private / 'runtime'; runtime.mkdir(); (runtime / 'views').mkdir()
        copy = private / 'app'; shutil.copytree(package, copy)
        for folder in ('bootstrap/cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data',
                       'storage/framework/sessions', 'storage/framework/views', 'storage/logs'):
            (copy / folder).mkdir(parents=True, exist_ok=True)
        env = isolated_environment(private, runtime)
        env['RELEASE_PRODUCTION_ROOT'] = str(copy)
        env['RELEASE_PRODUCTION_REPORT'] = str(report / 'smoke.json')
        command(['composer', '--no-plugins', 'check-platform-reqs', '--no-dev'], copy, env, report, 'composer-platform')
        disabled = 'curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,exec,shell_exec,system,passthru,popen,proc_open'
        command(['php', '-d', 'allow_url_fopen=0', '-d', 'disable_functions=' + disabled,
                 str(ROOT / 'tests/Deployment/production-smoke.php')], copy, env, report, 'production-smoke')
        # Boot above uses the unmodified delivered autoloader. Regeneration is diagnostic,
        # and must leave every vendor byte identical, not silently repair the package.
        original_vendor = vendor_manifest(copy)
        command(['composer', '--no-plugins', 'dump-autoload', '--no-dev', '--optimize', '--strict-psr', '--no-scripts'], copy, env, report, 'composer-autoload')
        if vendor_manifest(copy) != original_vendor:
            raise RuntimeError('Strict autoload regeneration changed delivered production vendor')
        production_packages(copy)


def validate(snapshot, destination, source_manifest, report):
    if destination.exists():
        raise RuntimeError('Refusing to overwrite/reuse production staging')
    report.mkdir(parents=True, exist_ok=True)
    locked = verify_snapshot(snapshot, ROOT)
    build_templates = verify_build_templates(snapshot, ROOT)
    source = json.loads(source_manifest.read_text())
    if source['source_sha256'] != digest([(x['path'], x['sha256']) for x in source['files']]):
        raise RuntimeError('Source manifest identity mismatch')
    source_rows = {}
    for row in source['files']:
        safe_relative(row['path'])
        if row['path'] in source_rows:
            raise RuntimeError('Duplicate source manifest path')
        source_rows[row['path']] = row['sha256']
    generated, font_evidence, license_file = generated_paths(ROOT, report)
    allowed_changes = build_output_allowlist(ROOT, source_rows)
    selected = {p for p in source_rows if source_allowed(p)} | generated
    casefold = set(); changed = []
    for relative in sorted(selected):
        if relative.casefold() in casefold:
            raise RuntimeError('Case-insensitive package path collision')
        casefold.add(relative.casefold())
        path = real_file(ROOT, relative)
        if relative in source_rows and sha(path) != source_rows[relative]:
            if relative not in allowed_changes:
                raise RuntimeError('Non-build source changed: ' + relative)
            changed.append({'path': relative, 'source_sha256': source_rows[relative], 'built_sha256': sha(path),
                            'generator': allowed_changes[relative]})
    # Fail closed if unexpected files appeared inside deployable source areas.
    for folder in SOURCE_DIRS:
        for path in (ROOT / folder).rglob('*'):
            relative = path.relative_to(ROOT).as_posix()
            if path.is_symlink() and source_allowed(relative):
                raise RuntimeError('Unexpected source symlink')
            if path.is_file() and source_allowed(relative) and relative not in selected:
                raise RuntimeError('Unproven deployment input: ' + relative)
    destination.mkdir(parents=True)
    selected_manifest = []
    for relative in sorted(selected):
        target = destination / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(real_file(ROOT, relative), target)
        if sha(target) != sha(ROOT / relative):
            raise RuntimeError('Source copy changed bytes')
        selected_manifest.append({'path': relative, 'sha256': sha(target)})
    shutil.copytree(snapshot / 'vendor', destination / 'vendor')
    if vendor_manifest(destination) != locked['vendor_files'] or production_packages(destination) != locked['packages']:
        raise RuntimeError('Production vendor copy changed bytes')
    licenses = destination / 'licenses'; licenses.mkdir()
    shutil.copyfile(license_file, licenses / 'Instrument-Sans-OFL.txt')
    (destination / 'bootstrap/cache').mkdir(parents=True, exist_ok=True)
    for path in destination.rglob('*'):
        path.chmod(0o755 if path.is_dir() else 0o644)
    destination.chmod(0o755)
    verify_package_boundary(destination)
    before = inventory(destination)
    smoke(destination, report)
    if inventory(destination) != before or verify_snapshot(snapshot, ROOT) != locked:
        raise RuntimeError('Immutable staging/vendor mutated during smoke')
    archive = report / 'production-package.tar'
    archive_package(destination, archive)
    with tempfile.TemporaryDirectory(prefix='production-roundtrip-') as temporary:
        temporary = pathlib.Path(temporary)
        with tarfile.open(archive) as handle:
            safe_extract_source_archive(handle, temporary)
        if inventory(temporary) != before:
            raise RuntimeError('Archive round-trip byte/mode mismatch')
        second = report / 'production-package-repeated.tar'
        archive_package(temporary, second)
        if sha(archive) != sha(second):
            raise RuntimeError('Package archive is not reproducible')
        second.unlink()
    write(report / 'production-package-manifest.json', {'files': before, 'tree_sha256': digest(before)})
    write(report / 'production-vendor-receipt.json', locked)
    write(report / 'package-gate.json', {'passed': True, 'source_sha256': source['source_sha256'],
          'built_application_sha256': digest(selected_manifest), 'built_application_files': selected_manifest,
          'source_build_changes': changed, 'archive_sha256': sha(archive), 'tree_sha256': digest(before),
          'vendor_tree_sha256': locked['vendor_tree_sha256'], 'runtime_packages': len(locked['packages']),
          'font_evidence': font_evidence, 'file_count': sum(r['type'] == 'file' for r in before),
          'locked_vendor_build_templates': build_templates,
          'checks': ['no-dev lock exact', 'platform', 'strict autoload', 'production boot and synthetic HTTP/database smoke',
                     'immutable staged bytes', 'deterministic archive roundtrip', '0644 files/0755 directories',
                     'source and generated asset identities', 'font cache byte comparison'],
          'preservation_contract': ['No deployment or rsync executed', 'No existing destination, storage, upload, env or assets touched',
                                    'Fresh package only; historical destination assets must be retained by deployment'],
          'installation_requirements': ['bootstrap/cache is empty; target must provide appropriate owner/group write access',
                                        'storage is deliberately absent: provision/preserve shared storage and runtime subdirectories',
                                        'Real environment and secrets are target-owned and not included',
                                        'Do not reuse stale config/routes/events/packages/services caches across releases',
                                        'Smoke provisions disposable storage/cache paths; TAR alone is not an installed service'],
          'scope': 'Production-mode CLI/kernel smoke in disposable runtime; not FPM/proxy/HTTPS or live schema readiness'})
    print('PASS production package:', len(before), 'nodes;', len(locked['packages']), 'runtime packages', flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['setup', 'validate'])
    parser.add_argument('--destination', type=pathlib.Path, required=True)
    parser.add_argument('--dependency-root', type=pathlib.Path)
    parser.add_argument('--source-manifest', type=pathlib.Path)
    parser.add_argument('--report', type=pathlib.Path)
    arguments = parser.parse_args()
    if os.environ.get('RELEASE_DISPOSABLE_TESTS') != '1' or os.name != 'posix':
        raise RuntimeError('Explicit isolated Linux disposable opt-in required')
    if arguments.action == 'setup':
        setup(arguments.destination.resolve())
    else:
        if not all((arguments.dependency_root, arguments.source_manifest, arguments.report)):
            parser.error('validate requires dependency-root, source-manifest and report')
        validate(arguments.dependency_root.resolve(), arguments.destination.resolve(),
                 arguments.source_manifest.resolve(), arguments.report.resolve())
