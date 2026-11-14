<?php

namespace Tests\Isolated;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/** Fail-closed guard for tests that execute migrate:fresh. */
final class CompanyIdempotencyMySqlLabGuard
{
    private static ?string $approvedUuid = null;

    /** Tests using migrate:fresh with SQLite must use an in-memory database. */
    public static function checkInMemorySqlite(mixed $environment, mixed $default, mixed $connection): void
    {
        if ($environment !== 'testing' || $default !== 'sqlite' || ! is_array($connection)
            || ($connection['driver'] ?? null) !== 'sqlite' || ($connection['database'] ?? null) !== ':memory:'
            || ! in_array($connection['url'] ?? null, [null, ''], true)) {
            throw new RuntimeException('An in-memory SQLite test database is required.');
        }
    }

    /** Pure validation; safe to exercise without a database or network. */
    public static function checkEnvironment(array $env, array $interfaces, bool $cachedConfig, bool $environmentFile): void
    {
        sort($interfaces);
        if (($env['FOKUS_COMPANY_IDEMPOTENCY_LAB'] ?? null) !== '1' || ($env['APP_ENV'] ?? null) !== 'testing'
            || ($env['APP_BASE_PATH'] ?? null) !== '/workspace' || ($env['DB_CONNECTION'] ?? null) !== 'mysql'
            || ($env['DB_HOST'] ?? null) !== '127.0.0.1' || ($env['DB_PORT'] ?? null) !== '3306'
            || ($env['DB_DATABASE'] ?? null) !== 'fokus_idempotency_api_test' || $interfaces !== ['lo']
            || ! in_array($env['DB_URL'] ?? false, [false, ''], true)
            || ! in_array($env['DB_SOCKET'] ?? false, [false, ''], true)
            || ! is_string($env['DB_USERNAME'] ?? null) || ($env['DB_USERNAME'] ?? '') === ''
            || ! is_string($env['DB_PASSWORD'] ?? null) || ($env['DB_PASSWORD'] ?? '') === ''
            || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', (string) ($env['LAB_MYSQL_UUID'] ?? ''))
            || $cachedConfig || $environmentFile) {
            throw new RuntimeException('Disposable API laboratory configuration required.');
        }
    }

    /** Pure validation of the effective Laravel connection, without connecting. */
    public static function checkConnection(mixed $effective, array $env): void
    {
        if (! is_array($effective) || ($effective['driver'] ?? null) !== 'mysql'
            || ($effective['host'] ?? null) !== '127.0.0.1' || (string) ($effective['port'] ?? '') !== '3306'
            || ($effective['database'] ?? null) !== 'fokus_idempotency_api_test'
            || ($effective['username'] ?? null) !== ($env['DB_USERNAME'] ?? null)
            || ! is_string($effective['password'] ?? null) || ! is_string($env['DB_PASSWORD'] ?? null)
            || ! hash_equals($effective['password'], $env['DB_PASSWORD'])
            || ! in_array($effective['url'] ?? null, [null, ''], true)
            || ! in_array($effective['unix_socket'] ?? null, [null, ''], true)) {
            throw new RuntimeException('Effective connection is not the disposable API laboratory.');
        }
    }

    /** Bootstrap-only approval: the database must be empty before first test. */
    public static function approveEmptyTarget(PDO $pdo, string $expectedUuid): void
    {
        if (self::$approvedUuid !== null) {
            throw new RuntimeException('Laboratory target already approved.');
        }
        try {
            $actual = $pdo->query('SELECT @@server_uuid AS server_uuid, DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            throw new RuntimeException('Disposable API laboratory could not be verified.');
        }
        self::checkEmptyTarget($actual, $expectedUuid, $tables);
        self::$approvedUuid = $expectedUuid;
    }

    public static function checkEmptyTarget(mixed $actual, string $expectedUuid, array $tables): void
    {
        self::checkIdentity($actual, $expectedUuid);
        if ($tables !== []) {
            throw new RuntimeException('Fresh, identified disposable database required.');
        }
    }

    /** Called by each test immediately before the destructive migrate:fresh. */
    public static function assertBeforeFresh(): void
    {
        $env = self::environment();
        self::checkApproval(self::$approvedUuid, $env['LAB_MYSQL_UUID']);
        self::checkEnvironment($env, self::interfaces(), is_file('/workspace/bootstrap/cache/config.php'), is_file('/workspace/.env'));
        if (config('app.env') !== 'testing' || config('database.default') !== 'mysql') {
            throw new RuntimeException('Disposable API laboratory changed after approval.');
        }
        self::checkConnection(config('database.connections.mysql'), $env);
        try {
            $actual = (array) DB::selectOne('SELECT @@server_uuid AS server_uuid, DATABASE() AS database_name');
        } catch (Throwable) {
            throw new RuntimeException('Disposable API laboratory could not be verified.');
        }
        self::checkIdentity($actual, self::$approvedUuid);
    }

    public static function checkApproval(?string $approvedUuid, mixed $requestedUuid): void
    {
        if ($approvedUuid === null || $requestedUuid !== $approvedUuid) {
            throw new RuntimeException('Fresh database was not approved by the laboratory bootstrap.');
        }
    }

    public static function environment(): array
    {
        $env = [];
        foreach (['FOKUS_COMPANY_IDEMPOTENCY_LAB', 'APP_ENV', 'APP_BASE_PATH', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_URL', 'DB_SOCKET', 'DB_USERNAME', 'DB_PASSWORD', 'LAB_MYSQL_UUID'] as $name) {
            $env[$name] = getenv($name);
        }

        return $env;
    }

    public static function interfaces(): array
    {
        return is_dir('/sys/class/net') ? array_values(array_diff(scandir('/sys/class/net') ?: [], ['.', '..'])) : [];
    }

    private static function checkIdentity(mixed $actual, string $expectedUuid): void
    {
        if (! is_array($actual) || ($actual['server_uuid'] ?? null) !== $expectedUuid
            || ($actual['database_name'] ?? null) !== 'fokus_idempotency_api_test') {
            throw new RuntimeException('Fresh, identified disposable database required.');
        }
    }
}
