"""Process boundaries with the real PHP/Laravel runtime and disposable MySQL only."""
import os
from pathlib import Path
import subprocess
import sys
import tempfile


def main():
    root, helper = map(lambda value: Path(value).resolve(), sys.argv[1:])
    harness = Path(__file__).with_name('law-schema-preflight-test.php')
    if (os.environ.get('CI'), os.environ.get('DB_HOST'), os.environ.get('DB_DATABASE')) != (
        'true', '127.0.0.1', 'fokuscloud_schema_test'
    ):
        raise RuntimeError('Refusing to run outside the disposable CI database.')

    def reject(label, command, reason, env=None):
        result = subprocess.run(command, env={**os.environ, **(env or {})},
                                capture_output=True, text=True, timeout=45)
        print(f'Boundary {label}: exit={result.returncode}, expected=1, '
              f'stdout_bytes={len(result.stdout)}, stderr_bytes={len(result.stderr)}', flush=True)
        if result.returncode != 1 or reason not in result.stderr:
            raise RuntimeError(f'{label}: incorrect process failure contract')
        combined = result.stdout + result.stderr
        if 'SCHEMA_SYNTHETIC_SECRET' in combined or 'prerequisite verified.' in combined or 'both divergence guards passed.' in combined:
            raise RuntimeError(f'{label}: secret or false success marker escaped')
        return result

    reject('missing release', ['php', str(helper)], 'Existing release or staged migration missing.')
    reject('missing key', ['php', str(helper), str(root)],
           'Application encryption key is not configured.', {'APP_KEY': ''})
    rejected = reject('database authentication after bootstrap', ['php', str(helper), str(root)],
                      'Could not inspect the existing schema.',
                      {'DB_USERNAME': 'SCHEMA_SYNTHETIC_SECRET', 'DB_PASSWORD': 'SCHEMA_SYNTHETIC_SECRET'})
    if rejected.stdout or rejected.stderr != 'Law schema prepublication failed: Could not inspect the existing schema.\n':
        raise RuntimeError('Database exception details were not sanitized')
    reject('harness isolation guard', ['php', str(harness), str(root), str(helper)],
           'Schema preflight regression failed: disposable database guard', {'CI': 'false'})

    # A false-success helper must cause an assertion AFTER Laravel installs its handler.
    # The parent remains outside Laravel, so its own failure cannot be swallowed by that handler.
    with tempfile.TemporaryDirectory(prefix='law-schema-process-') as directory:
        fake = Path(directory) / 'helper.php'
        for label, source in [('helper false success', '<?php exit(0);'),
                              ('helper wrong failure', '<?php fwrite(STDERR, "wrong guard"); exit(1);')]:
            fake.write_text(source)
            reject(label, ['php', str(harness), str(root), str(fake)],
                   'Schema preflight regression failed:')
    print('Schema CLI process boundaries passed.', flush=True)


if __name__ == '__main__':
    main()
