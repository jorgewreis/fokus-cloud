<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Isolated\CompanyIdempotencyMySqlLabGuard;

require_once dirname(__DIR__).'/Isolated/CompanyIdempotencyMySqlLabGuard.php';

class CompanyIdempotencyLabGuardTest extends TestCase
{
    public function test_environment_guard_rejects_unsafe_targets_without_connecting(): void
    {
        $base = $this->environment();
        CompanyIdempotencyMySqlLabGuard::checkEnvironment($base, ['lo'], false, false);
        self::assertTrue(true);
        $cases = [
            ['APP_ENV' => 'production'], ['FOKUS_COMPANY_IDEMPOTENCY_LAB' => '0'],
            ['APP_BASE_PATH' => '/production'], ['DB_CONNECTION' => 'sqlite'],
            ['DB_HOST' => 'localhost'], ['DB_PORT' => '3307'], ['DB_DATABASE' => 'production'],
            ['DB_URL' => '0'], ['DB_SOCKET' => '0'], ['DB_USERNAME' => ''], ['DB_PASSWORD' => ''],
            ['LAB_MYSQL_UUID' => 'malformed'],
        ];
        foreach ($cases as $case) {
            $this->assertGuardRejects(fn () => CompanyIdempotencyMySqlLabGuard::checkEnvironment(array_replace($base, $case), ['lo'], false, false));
        }
        $this->assertGuardRejects(fn () => CompanyIdempotencyMySqlLabGuard::checkEnvironment($base, ['lo', 'eth0'], false, false));
        $this->assertGuardRejects(fn () => CompanyIdempotencyMySqlLabGuard::checkEnvironment($base, [], false, false));
        $this->assertGuardRejects(fn () => CompanyIdempotencyMySqlLabGuard::checkEnvironment($base, ['lo'], true, false));
        $this->assertGuardRejects(fn () => CompanyIdempotencyMySqlLabGuard::checkEnvironment($base, ['lo'], false, true));
    }

    public function test_effective_config_guard_rejects_redirection_and_missing_approval_without_connecting(): void
    {
        $env = $this->environment();
        $valid = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'fokus_idempotency_api_test',
            'username' => $env['DB_USERNAME'], 'password' => $env['DB_PASSWORD'], 'url' => null, 'unix_socket' => ''];
        CompanyIdempotencyMySqlLabGuard::checkConnection($valid, $env);
        self::assertTrue(true);
        foreach ([['driver' => 'sqlite'], ['host' => 'localhost'], ['port' => '3307'], ['database' => 'production'],
            ['username' => 'other'], ['password' => 'wrong'], ['url' => '0'], ['unix_socket' => '0']] as $case) {
            $this->assertGuardRejects(fn () => CompanyIdempotencyMySqlLabGuard::checkConnection(array_replace($valid, $case), $env));
        }
        $this->assertGuardRejects(fn () => CompanyIdempotencyMySqlLabGuard::checkApproval(null, $env['LAB_MYSQL_UUID']));
        $this->assertGuardRejects(fn () => CompanyIdempotencyMySqlLabGuard::checkApproval($env['LAB_MYSQL_UUID'], 'different'));
    }

    public function test_sqlite_guard_accepts_only_in_memory_testing_connection(): void
    {
        $valid = ['driver' => 'sqlite', 'database' => ':memory:', 'url' => null];
        CompanyIdempotencyMySqlLabGuard::checkInMemorySqlite('testing', 'sqlite', $valid);
        self::assertTrue(true);
        $this->assertGuardRejects(fn () => CompanyIdempotencyMySqlLabGuard::checkInMemorySqlite('production', 'sqlite', $valid));
        $this->assertGuardRejects(fn () => CompanyIdempotencyMySqlLabGuard::checkInMemorySqlite('testing', 'mysql', $valid));
        $this->assertGuardRejects(fn () => CompanyIdempotencyMySqlLabGuard::checkInMemorySqlite('testing', 'sqlite', array_replace($valid, ['database' => '/tmp/persistent.sqlite'])));
        $this->assertGuardRejects(fn () => CompanyIdempotencyMySqlLabGuard::checkInMemorySqlite('testing', 'sqlite', array_replace($valid, ['url' => 'sqlite:/tmp/persistent.sqlite'])));
    }

    public function test_target_identity_and_freshness_are_required_without_connecting(): void
    {
        $uuid = $this->environment()['LAB_MYSQL_UUID'];
        $actual = ['server_uuid' => $uuid, 'database_name' => 'fokus_idempotency_api_test'];
        CompanyIdempotencyMySqlLabGuard::checkEmptyTarget($actual, $uuid, []);
        self::assertTrue(true);
        $this->assertGuardRejects(fn () => CompanyIdempotencyMySqlLabGuard::checkEmptyTarget($actual, 'other-uuid', []));
        $this->assertGuardRejects(fn () => CompanyIdempotencyMySqlLabGuard::checkEmptyTarget(['server_uuid' => $uuid, 'database_name' => 'other'], $uuid, []));
        $this->assertGuardRejects(fn () => CompanyIdempotencyMySqlLabGuard::checkEmptyTarget($actual, $uuid, ['existing_table']));
        $this->assertGuardRejects(fn () => CompanyIdempotencyMySqlLabGuard::checkEmptyTarget(null, $uuid, []));
    }

    private function environment(): array
    {
        return ['FOKUS_COMPANY_IDEMPOTENCY_LAB' => '1', 'APP_ENV' => 'testing', 'APP_BASE_PATH' => '/workspace',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
            'DB_DATABASE' => 'fokus_idempotency_api_test', 'DB_URL' => false, 'DB_SOCKET' => false,
            'DB_USERNAME' => 'synthetic', 'DB_PASSWORD' => 'synthetic-password', 'LAB_MYSQL_UUID' => '00000000-0000-0000-0000-000000000000'];
    }

    private function assertGuardRejects(callable $check): void
    {
        try {
            $check();
            self::fail('Unsafe laboratory target was accepted.');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString('synthetic-password', $e->getMessage());
        }
    }
}
