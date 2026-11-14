<?php

use Illuminate\Foundation\Application;
use Tests\Isolated\CompanyIdempotencyMySqlLabGuard;

// Opt-in only: this PHPUnit bootstrap precedes tests that run migrate:fresh.
// The test process and the dedicated MySQL must share a network namespace with
// loopback alone. Both the effective connection and server UUID are checked.
require_once __DIR__.'/CompanyIdempotencyMySqlLabGuard.php';
$env = CompanyIdempotencyMySqlLabGuard::environment();
CompanyIdempotencyMySqlLabGuard::checkEnvironment($env, CompanyIdempotencyMySqlLabGuard::interfaces(), is_file('/workspace/bootstrap/cache/config.php'), is_file('/workspace/.env'));
require '/test-bootstrap.php';
// Constructing the container supplies database_path() without bootstrapping
// Laravel or registering error/exception handlers before PHPUnit starts.
new Application('/workspace');
$configuration = require '/workspace/config/database.php';
$effective = $configuration['connections']['mysql'] ?? null;
CompanyIdempotencyMySqlLabGuard::checkConnection($effective, $env);
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=fokus_idempotency_api_test', (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable) {
    throw new RuntimeException('Disposable API laboratory could not be verified.');
}
CompanyIdempotencyMySqlLabGuard::approveEmptyTarget($pdo, $env['LAB_MYSQL_UUID']);
$pdo = null;
