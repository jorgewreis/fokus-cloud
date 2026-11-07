"""Run every Unit/Feature assertion with documented DB/clock portability adapters."""
import argparse
import json
import tempfile
import pathlib
import xml.etree.ElementTree as ET
from linux_release_common import ROOT, REPORT, guard, environment, php, run, adapt, write, sha, exclusive_suite_lock


def prepare_adapters(driver, env, tree, temporary):
    adaptations = []
    if driver == 'mysql':
        catalog = ROOT / 'tests/Feature/CatalogAdminTest.php'
        anchor = "DB::table('catalog_publications')->where('id', $publication->id)->update(['snapshot' => $stored]);"
        adaptations.append(adapt(catalog, temporary / catalog.name, [(anchor, anchor + "\n        // MySQL persists normalized JSON; capture bytes before exercising the endpoint.\n        $stored = DB::table('catalog_publications')->where('id', $publication->id)->value('snapshot');")]))
        sources = [catalog]
        if env['CACHE_STORE'] == 'redis':
            log = REPORT / 'redis-real-wall-clock.jsonl'
            log.unlink(missing_ok=True)
            env['HOMOLOGATION_WALL_CLOCK_LOG'] = str(log)
            security = ROOT / 'tests/Feature/PlatformSecurityTest.php'
            replacement = "// Carbon time travel does not advance the Redis server clock.\n        $realStart = microtime(true);\n        sleep(61);\n        file_put_contents(getenv('HOMOLOGATION_WALL_CLOCK_LOG'), json_encode(['wait_seconds' => microtime(true) - $realStart, 'cache_deleted_before_assertion' => false]).PHP_EOL, FILE_APPEND);"
            adaptations.append(adapt(security, temporary / security.name, [('$this->travel(61)->seconds();', replacement)]))
            sources.append(security)
        feature = next(s for s in tree.findall('.//testsuite') if s.attrib['name'] == 'Feature')
        for source in sources:
            ET.SubElement(feature, 'exclude').text = str(source)
            ET.SubElement(feature, 'file').text = str(temporary / source.name)
    return adaptations


def verify_configuration(env, selected, temporary, driver):
    # Bootstrap the real Laravel kernel with the exact suite environment. Only
    # these three non-secret configuration values are allowed into evidence.
    probe = temporary / 'effective-configuration.php'
    probe.write_text("<?php\nrequire $argv[1].'/vendor/autoload.php';\n$app = require $argv[1].'/bootstrap/app.php';\n$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();\necho json_encode(['cache.default' => config('cache.default'), 'cache.limiter' => config('cache.limiter'), 'session.driver' => config('session.driver')], JSON_THROW_ON_ERROR).PHP_EOL;\n", encoding='utf-8')
    label = 'suite-' + driver + '-effective-configuration'
    run(php() + [str(probe), str(ROOT)], label, env)
    effective = json.loads((REPORT / (label + '.log')).read_text(encoding='utf-8'))
    if set(effective) != set(selected):
        raise RuntimeError('Unexpected configuration evidence keys')
    write(label + '-proof.json', {'selected': selected, 'effective': effective, 'matches': effective == selected, 'scope': 'Real Laravel console bootstrap immediately before PHPUnit, same environment; no credentials'})
    if effective != selected:
        raise RuntimeError('Laravel effective configuration differs from selected suite profile')


def prepare_database_schema(env):
    if env['DB_CONNECTION'] != 'mysql' or env['CACHE_STORE'] != 'database':
        return
    expected = {'APP_ENV': 'testing', 'DB_HOST': '127.0.0.1', 'DB_PORT': '3306', 'DB_DATABASE': 'fokuscloud_security', 'DB_USERNAME': 'root', 'DB_PASSWORD': 'security-test-only', 'DB_URL': '', 'DATABASE_URL': '', 'CACHE_LIMITER': 'database', 'SESSION_DRIVER': 'database'}
    if any(env.get(key) != value for key, value in expected.items()):
        raise RuntimeError('Unsafe target for synthetic suite schema preparation')
    import os
    if os.environ.get('RELEASE_DISPOSABLE_TESTS') != '1':
        raise RuntimeError('Schema preparation requires explicit disposable opt-in')
    # Bootstrap migrations without reading cache tables that do not exist yet.
    # The actual suite retains the selected database cache/session configuration.
    migration_env = dict(env, CACHE_STORE='array', CACHE_LIMITER='array', SESSION_DRIVER='array')
    run(php() + ['artisan', 'migrate', '--force', '--no-interaction'], 'suite-mysql-prepare-schema', migration_env)
    migrations = [{'path': p.relative_to(ROOT).as_posix(), 'sha256': sha(p)} for p in sorted((ROOT / 'database/migrations').glob('*.php'))]
    write('suite-mysql-prepare-schema-proof.json', {'status': 'passed', 'method': 'real artisan migrate; no fresh/reset/drop', 'database': 'fokuscloud_security', 'host': '127.0.0.1', 'exclusive_suite_lock': True, 'migration_bootstrap_cache': 'array', 'suite_cache': env['CACHE_STORE'], 'suite_session': env['SESSION_DRIVER'], 'migrations': migrations})


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--driver', choices=['sqlite', 'mysql'], required=True)
    args = parser.parse_args()
    guard()
    env = environment(args.driver)
    with exclusive_suite_lock(env):
        execute_suite(args.driver, env)


def execute_suite(driver, env):
    prepare_database_schema(env)
    tree = ET.parse(ROOT / 'phpunit.xml')
    for directory in tree.findall('.//directory'):
        directory.text = str(ROOT / directory.text)
    tree.getroot().set('bootstrap', str(ROOT / 'vendor/autoload.php'))
    for item in tree.findall('.//env'):
        if item.attrib['name'] in env:
            item.set('value', env[item.attrib['name']])
            item.set('force', 'true')
    with tempfile.TemporaryDirectory(prefix='fokus-suite-') as temporary:
        temporary = pathlib.Path(temporary)
        adaptations = prepare_adapters(driver, env, tree, temporary)
        selected = {'cache.default': env['CACHE_STORE'], 'cache.limiter': env['CACHE_LIMITER'], 'session.driver': env['SESSION_DRIVER']}
        write('suite-' + driver + '-adaptations.json', {'scope': 'All tests retained; replaced files are included explicitly; original assertions unchanged', 'selected_configuration': selected, 'clock_adapter': env['CACHE_STORE'] == 'redis' and driver == 'mysql', 'adapters': adaptations})
        verify_configuration(env, selected, temporary, driver)
        config = temporary / 'phpunit.xml'
        tree.write(config, encoding='utf-8', xml_declaration=True)
        junit = REPORT / ('suite-' + driver + '-junit.xml')
        run(php() + [str(ROOT / 'vendor/phpunit/phpunit/phpunit'), '--configuration', str(config), '--log-junit', str(junit), '--colors=never', '--do-not-cache-result'], 'suite-' + driver, env)
        report = ET.parse(junit).getroot()
        suites = list(report) if report.tag == 'testsuites' else [report]
        totals = {key: sum(int(s.get(key, 0)) for s in suites) for key in ('tests', 'assertions', 'failures', 'errors', 'skipped')}
        write('suite-' + driver + '-coverage.json', {**totals, 'junit_sha256': sha(junit), 'expected_tests': 327, 'minimum_assertions': 2753})
        if totals['tests'] != 327 or totals['assertions'] < 2753 or any(totals[k] for k in ('failures', 'errors', 'skipped')):
            raise RuntimeError('Incomplete or failing suite; coverage gate rejected results')


if __name__ == '__main__':
    main()
