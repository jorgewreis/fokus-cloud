"""Run every Unit/Feature assertion with documented DB/clock portability adapters."""
import argparse
import json
import tempfile
import pathlib
import xml.etree.ElementTree as ET
from linux_release_common import ROOT, REPORT, guard, environment, php, run, adapt, write, sha, exclusive_suite_lock



# Measured main composition plus three interest cases.
EXPECTED_TESTS = 373 + 3
MINIMUM_ASSERTIONS = 2753 + 23 + 58 + 202 + 36
MAIN_IDENTITY_TESTS = {'Tests\\Unit\\Deployment\\ReleaseIdentityTest::test_valid_snapshot_is_accepted': 1, 'Tests\\Unit\\Deployment\\ReleaseIdentityTest::test_missing_identity_is_rejected': 1, 'Tests\\Unit\\Deployment\\ReleaseIdentityTest::test_invalid_release_is_rejected_without_echoing_input': 1, 'Tests\\Unit\\Deployment\\ReleaseIdentityTest::test_stale_captured_manifest_is_rejected': 1, 'Tests\\Unit\\Deployment\\ReleaseIdentityTest::test_in_place_code_change_is_rejected': 1, 'Tests\\Unit\\Deployment\\ReleaseIdentityTest::test_added_code_is_rejected': 1, 'Tests\\Unit\\Deployment\\ReleaseIdentityTest::test_asset_storage_link_does_not_change_runtime_identity': 1, 'Tests\\Feature\\Deployment\\DeploymentIdentityTest::test_unsealed_checkout_fails_closed_without_debug_details': 4, 'Tests\\Feature\\Deployment\\DeploymentIdentityTest::test_invalid_or_missing_nonce_is_not_reflected': 11, 'Tests\\Feature\\Deployment\\DeploymentIdentityTest::test_existing_health_endpoint_is_preserved': 1}

LEAD_TESTS = {'Tests\\Feature\\CompanyAdminTest::test_company_detail_exposes_separate_lead_usage_only_after_platform_authorization': 5, 'Tests\\Feature\\CompanyAdminTest::test_company_detail_permission_is_checked_before_reading_lead_usage': 2, 'Tests\\Feature\\LeadConnectionsTest::test_superuser_credentials_never_execute_application_operation': 7, 'Tests\\Feature\\LeadConnectionsTest::test_runtime_context_is_transaction_local_and_disconnects_on_application_failure': 10, 'Tests\\Feature\\LeadConnectionsTest::test_insecure_configuration_is_rejected_before_connecting': 1, 'Tests\\Feature\\LeadConnectionsTest::test_transaction_pooler_and_missing_ca_are_rejected_before_connecting': 3, 'Tests\\Feature\\LeadConnectionsTest::test_disabled_runtime_never_opens_connection': 1, 'Tests\\Feature\\LeadDatabaseTest::test_context_comes_from_server_session_and_fresh_membership': 2, 'Tests\\Feature\\LeadDatabaseTest::test_manager_role_is_resolved_from_current_membership': 2, 'Tests\\Feature\\LeadDatabaseTest::test_access_revocations_are_checked_before_any_lead_connection': 12, 'Tests\\Feature\\LeadDatabaseTest::test_support_and_cross_company_context_are_blocked': 4, 'Tests\\Feature\\LeadUsageReaderTest::test_unconfigured_metrics_are_unavailable_without_connecting': 1, 'Tests\\Feature\\LeadUsageReaderTest::test_reader_filters_company_and_distinguishes_missing_company_from_zero_usage': 5, 'Tests\\Feature\\LeadUsageReaderTest::test_outage_has_no_fabricated_counts': 3}
BILLING_TESTS = {
    'Tests\\Feature\\ReconciliationAndRefundTest::test_closed_or_stale_reconciliation_cannot_change_subscription': 5,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_reconciliation_rechecks_provider_before_applying': 3,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_refund_unconfirmed_response_is_not_executed_or_retried': 10,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_refund_rejects_amount_below_one_cent_or_more_than_two_decimals': 4,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_refund_timeout_remains_pending_and_cannot_be_declined_or_reexecuted': 9,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_refund_mismatched_provider_response_never_completes': 17,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_refund_execution_refuses_existing_transaction_before_provider_call': 6,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_overlapping_refund_execution_sends_only_one_provider_request': 8,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_pending_refund_with_provider_id_can_be_checked_without_another_post': 15,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_refund_without_provider_identifier_cannot_be_guessed_or_consulted': 6,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_refund_does_not_confirm_if_payment_provider_identifier_changes_in_flight': 7,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_pending_refund_cannot_be_checked_against_changed_provider_payment': 9,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_provider_refund_identifier_cannot_be_reused_between_requests': 11,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_overlapping_requests_cannot_link_the_same_provider_refund': 12,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_provider_id_unique_conflict_after_claim_is_audited_and_remains_uncertain': 9,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_other_unique_errors_are_not_mistaken_for_provider_id_collision': 6,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_refund_execution_and_lookup_fail_closed_without_the_expected_unique_index': 11,
}
INTEREST_TESTS = {
    'Tests\\Feature\\ProductInterestTest::test_public_interest_is_created_and_same_email_is_updated': 8,
    'Tests\\Feature\\ProductInterestTest::test_interest_product_options_respect_catalog_permission_and_keep_historical_codes': 16,
    'Tests\\Feature\\ProductInterestTest::test_index_rebuild_fails_closed_for_invalid_history_and_writer_rolls_back': 7,
    'Tests\\Feature\\ProductInterestTest::test_indexed_suggestions_are_paginated_and_allow_prefix_search': 12,
}

# Added since e3dee0c6; each identity remains required, not just the total.
BACKOFFICE_TESTS = {
    'Tests\\Feature\\SubscriptionAdminTest::test_dashboard_aggregates_active_subscriptions_in_one_snapshot': 6,
    'Tests\\Feature\\BackofficeSecurityTest::test_vouchers_paginate_and_filter_beyond_the_first_hundred': 10,
    'Tests\\Feature\\BackofficeSecurityTest::test_access_audit_filters_before_paginating_all_events': 3,
    'Tests\\Feature\\CompanyAdminTest::test_company_only_view_skips_unneeded_subscription_history_and_keeps_legacy_contract': 12,
    'Tests\\Feature\\CompanyAdminTest::test_global_search_respects_permissions_for_each_result_group': 23,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_closed_or_stale_reconciliation_cannot_change_subscription': 5,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_reconciliation_rechecks_provider_before_applying': 3,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_refund_unconfirmed_response_is_not_executed_or_retried': 10,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_refund_rejects_amount_below_one_cent_or_more_than_two_decimals': 4,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_refund_timeout_remains_pending_and_cannot_be_declined_or_reexecuted': 9,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_refund_mismatched_provider_response_never_completes': 17,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_refund_execution_refuses_existing_transaction_before_provider_call': 6,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_overlapping_refund_execution_sends_only_one_provider_request': 8,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_pending_refund_with_provider_id_can_be_checked_without_another_post': 15,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_refund_without_provider_identifier_cannot_be_guessed_or_consulted': 6,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_refund_does_not_confirm_if_payment_provider_identifier_changes_in_flight': 7,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_pending_refund_cannot_be_checked_against_changed_provider_payment': 9,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_provider_refund_identifier_cannot_be_reused_between_requests': 11,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_overlapping_requests_cannot_link_the_same_provider_refund': 12,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_provider_id_unique_conflict_after_claim_is_audited_and_remains_uncertain': 9,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_other_unique_errors_are_not_mistaken_for_provider_id_collision': 6,
    'Tests\\Feature\\ReconciliationAndRefundTest::test_refund_execution_and_lookup_fail_closed_without_the_expected_unique_index': 11,
}

def coverage(report):
    suites = list(report) if report.tag == 'testsuites' else [report]
    totals = {key: sum(int(s.get(key, 0)) for s in suites)
              for key in ('tests', 'assertions', 'failures', 'errors', 'skipped')}
    cases = list(report.iter('testcase'))
    identities = [case.get('class', '') + '::' + case.get('name', '') for case in cases]
    if (totals['tests'] != EXPECTED_TESTS or totals['assertions'] < MINIMUM_ASSERTIONS
            or any(totals[key] for key in ('failures', 'errors', 'skipped'))
            or len(cases) != EXPECTED_TESTS or len(set(identities)) != len(cases)
            or any(case.find(kind) is not None for case in cases for kind in ('failure', 'error', 'skipped'))):
        raise RuntimeError('Incomplete or failing suite; coverage gate rejected results')
    requirements = {}
    for group in (MAIN_IDENTITY_TESTS, LEAD_TESTS, BILLING_TESTS, BACKOFFICE_TESTS, INTEREST_TESTS):
        for name, minimum in group.items():
            requirements[name] = max(requirements.get(name, 0), minimum)
    for name, minimum in requirements.items():
        matches = [case for case, identity in zip(cases, identities) if identity == name]
        if len(matches) != 1 or int(matches[0].get('assertions', 0)) < minimum:
            raise RuntimeError('Missing or weakened required identity, Lead or backoffice test')
    return totals


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
        totals = coverage(report)
        write('suite-' + driver + '-coverage.json', {**totals, 'junit_sha256': sha(junit),
              'expected_tests': EXPECTED_TESTS, 'minimum_assertions': MINIMUM_ASSERTIONS,
              'main_identity_tests': MAIN_IDENTITY_TESTS})


if __name__ == '__main__':
    main()
