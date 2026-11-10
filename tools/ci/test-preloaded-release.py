#!/usr/bin/env python3
"""Real PHP subprocess regression: disabling OPcache does not unload preloaded code."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'app/Support/Deployment/ReleaseIdentity.php'
RELEASE = 'a' * 40

RUNNER = r'''<?php
$root = $argv[1];
$mode = $argv[2];
$identity = json_decode(file_get_contents($root.'/.release/identity.json'), true, flags: JSON_THROW_ON_ERROR);
if ($mode === 'preloaded') {
    // Prove the engine actually preloaded this class, rather than autoloading it.
    if (! class_exists(\App\Support\Deployment\ReleaseIdentity::class, false)) {
        throw new RuntimeException('fixture_not_preloaded');
    }
    ini_set('opcache.enable', '0');
    if (filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)) {
        throw new RuntimeException('fixture_opcache_not_disabled');
    }
    if (! class_exists(\App\Support\Deployment\ReleaseIdentity::class, false)) {
        throw new RuntimeException('fixture_class_unexpectedly_unloaded');
    }
} else {
    require $root.'/app/Support/Deployment/ReleaseIdentity.php';
}
try {
    $release = (new \App\Support\Deployment\ReleaseIdentity($identity, $root))->verifiedRelease();
    if ($mode === 'preloaded' || $release !== str_repeat('a', 40)) {
        throw new RuntimeException('preloaded_identity_incorrectly_accepted');
    }
} catch (RuntimeException $error) {
    if ($mode !== 'preloaded' || $error->getMessage() !== 'release_unavailable') {
        throw $error;
    }
}
echo 'preload_regression='.$mode.'_passed'.PHP_EOL;
'''


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php', help='Existing PHP executable; no installation is performed')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='preloaded-release-') as temp:
        root = Path(temp) / RELEASE
        for directory in ('app/Support/Deployment', 'bootstrap', 'config', 'routes', 'vendor', 'public', '.release'):
            (root / directory).mkdir(parents=True, exist_ok=True)
        target = root / 'app/Support/Deployment/ReleaseIdentity.php'
        target.write_bytes(SOURCE.read_bytes())
        files = {'app/Support/Deployment/ReleaseIdentity.php': hashlib.sha256(target.read_bytes()).hexdigest()}
        manifest = json.dumps(dict(release=RELEASE, root=str(root), files=files)).encode()
        (root / '.release/manifest.json').write_bytes(manifest)
        (root / '.release/verify.lock').touch()
        identity = dict(release=RELEASE, root=str(root), manifest=hashlib.sha256(manifest).hexdigest())
        (root / '.release/identity.json').write_text(json.dumps(identity))
        runner = Path(temp) / 'runner.php'
        runner.write_text(RUNNER)
        # Baseline proves the exact same identity/root/manifest/lock is otherwise valid.
        subprocess.run([args.php, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-d', 'opcache.preload=',
                        str(runner), str(root), 'baseline'], check=True)
        subprocess.run([args.php, '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1', '-d', 'opcache.preload=' + str(target),
                        str(runner), str(root), 'preloaded'], check=True)
        # The entrypoint must reject before loading identity/autoload or invoking a
        # potentially OLD preloaded guard. A downstream marker proves this boundary.
        (root / 'public/index.php').write_bytes((ROOT / 'public/index.php').read_bytes())
        (root / '.release/identity.php').write_text(
            "<?php file_put_contents(__DIR__.'/unexpected-load', 'loaded'); return [];"
        )
        (root / 'vendor/autoload.php').write_text(
            "<?php file_put_contents(__DIR__.'/unexpected-load', 'loaded');"
        )
        boundary = Path(temp) / 'boundary.php'
        boundary.write_text(r'''<?php
if (! class_exists(\App\Support\Deployment\ReleaseIdentity::class, false)) {
    throw new RuntimeException('fixture_not_preloaded');
}
ini_set('opcache.enable', '0');
if (filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)) {
    throw new RuntimeException('fixture_opcache_not_disabled');
}
$_SERVER['REQUEST_URI'] = '/_deployment/release?deploy_probe='.str_repeat('a', 48);
require $argv[1].'/public/index.php';
if (http_response_code() !== 503) {
    throw new RuntimeException('preloaded_entrypoint_status_invalid');
}
''')
        result = subprocess.run([args.php, '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1',
                                 '-d', 'opcache.preload=' + str(target), str(boundary), str(root)],
                                check=True, capture_output=True, text=True)
        assert json.loads(result.stdout) == dict(status='unavailable', release=None, probe=None)
        assert not (root / '.release/unexpected-load').exists(), 'preloaded_entrypoint_loaded_identity'
        assert not (root / 'vendor/unexpected-load').exists(), 'preloaded_entrypoint_loaded_autoload'
        print('preload_regression=prebootstrap_boundary_passed')


if __name__ == '__main__':
    main()
