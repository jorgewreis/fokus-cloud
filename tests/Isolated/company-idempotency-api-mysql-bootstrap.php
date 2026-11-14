<?php

use Illuminate\Foundation\Application;

// Opt-in only: this PHPUnit bootstrap precedes tests that run migrate:fresh.
// The test process and the dedicated MySQL must share a network namespace with
// loopback alone. Both the effective connection and server UUID are checked.
$interfaces = is_dir('/sys/class/net') ? array_values(array_diff(scandir('/sys/class/net') ?: [], ['.', '..'])) : [];
sort($interfaces);
if (getenv('FOKUS_COMPANY_IDEMPOTENCY_LAB') !== '1' || getenv('APP_ENV') !== 'testing'
    || getenv('APP_BASE_PATH') !== '/workspace' || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '3306'
    || getenv('DB_DATABASE') !== 'fokus_idempotency_api_test' || $interfaces !== ['lo']
    || (getenv('DB_URL') !== false && getenv('DB_URL') !== '')
    || (getenv('DB_SOCKET') !== false && getenv('DB_SOCKET') !== '')
    || ! preg_match('/\A[0-9a-f-]{36}\z/D', (string) getenv('LAB_MYSQL_UUID'))) {
    throw new RuntimeException('Disposable API laboratory configuration required.');
}
require '/test-bootstrap.php';
if (is_file('/workspace/bootstrap/cache/config.php') || is_file('/workspace/.env')) {
    throw new RuntimeException('Cached configuration or environment file is not allowed in the API laboratory.');
}
// Constructing the container supplies database_path() without bootstrapping
// Laravel or registering error/exception handlers before PHPUnit starts.
new Application('/workspace');
$configuration = require '/workspace/config/database.php';
$effective = $configuration['connections']['mysql'] ?? null;
if (! is_array($effective) || ($effective['driver'] ?? null) !== 'mysql'
    || ($effective['host'] ?? null) !== '127.0.0.1' || (string) ($effective['port'] ?? '') !== '3306'
    || ($effective['database'] ?? null) !== 'fokus_idempotency_api_test'
    || ($effective['username'] ?? null) !== getenv('DB_USERNAME')
    || ! in_array($effective['url'] ?? null, [null, ''], true)
    || ! in_array($effective['unix_socket'] ?? null, [null, ''], true)) {
    throw new RuntimeException('Effective connection is not the disposable API laboratory.');
}
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=fokus_idempotency_api_test', (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $actual = $pdo->query('SELECT @@server_uuid AS server_uuid, DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable) {
    throw new RuntimeException('Disposable API laboratory could not be verified.');
}
if ($actual['server_uuid'] !== getenv('LAB_MYSQL_UUID') || $actual['database_name'] !== 'fokus_idempotency_api_test'
    || $tables !== []) {
    throw new RuntimeException('Fresh, identified disposable database required.');
}
$pdo = null;
