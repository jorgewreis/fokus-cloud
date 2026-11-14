<?php

// This isolated smoke test creates no files and never accepts a DB target.
// Pass an installed Composer autoloader as the sole argument.
if ($argc !== 2 || ! is_file($argv[1])) {
    throw new RuntimeException('An installed Composer autoloader is required.');
}
require $argv[1];
require __DIR__.'/../../app/Services/PlatformSessionAuthority.php';
require __DIR__.'/../../app/Services/CompanyIdempotencyKeys.php';
require __DIR__.'/../../app/Services/CompanyIdempotencyAuthorization.php';
require __DIR__.'/../../app/Services/CompanyIdempotencyStore.php';

use App\Services\CompanyIdempotencyAuthorization;
use App\Services\CompanyIdempotencyKeys;
use App\Services\CompanyIdempotencyStore;
use App\Services\PlatformSessionAuthority;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;

$app = new Application(__DIR__);
$capsule = new Capsule($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setEventDispatcher(new Dispatcher($app));
$app->instance('db', $capsule->getDatabaseManager());
Facade::setFacadeApplication($app);
$db = $capsule->getConnection();
if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
    throw new RuntimeException('Only an in-memory SQLite connection is permitted.');
}
$db->statement('CREATE TABLE platform_admins (id TEXT PRIMARY KEY, status TEXT, manual_blocked_at TEXT, deactivated_at TEXT, locked_until TEXT, platform_role_id TEXT)');
$db->statement('CREATE TABLE platform_permissions (id TEXT PRIMARY KEY, code TEXT)');
$db->statement('CREATE TABLE platform_role_permissions (platform_role_id TEXT, platform_permission_id TEXT)');
$db->statement('CREATE TABLE company_idempotency_requests (token_hash TEXT PRIMARY KEY, key_id TEXT, actor_id TEXT, operation TEXT, issued_at_epoch INTEGER, expires_at_epoch INTEGER, recorded_at_epoch INTEGER, state TEXT, revoked INTEGER DEFAULT 0, payload_digest TEXT, result_id TEXT, result_status INTEGER, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE platform_session_authorizations (binding_hash TEXT PRIMARY KEY, actor_id TEXT NOT NULL, expires_at_epoch INTEGER NOT NULL, revoked INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE synthetic_effects (id TEXT PRIMARY KEY)');
$db->table('platform_admins')->insert([['id' => 'actor-a', 'status' => 'ativo', 'platform_role_id' => 'role'], ['id' => 'actor-b', 'status' => 'ativo', 'platform_role_id' => 'role']]);
$db->table('platform_permissions')->insert(['id' => 'permission', 'code' => 'platform.companies.manage']);
$db->table('platform_role_permissions')->insert(['platform_role_id' => 'role', 'platform_permission_id' => 'permission']);
$authority = new PlatformSessionAuthority;
$markerA = str_repeat('a', 64);
$markerB = str_repeat('b', 64);
$expiry = time() + 120;
$authority->registerAfterMfa('actor-a', $markerA, $expiry);
$assertions = 0;
$check = function (bool $condition) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException('Session authority assertion failed.');
    }
};
$denied = function (callable $call) use ($check): void {
    try {
        $call();
    } catch (HttpException $e) {
        $check($e->getStatusCode() === 401);

        return;
    }
    throw new RuntimeException('Expected inactive session to be rejected.');
};
$check($authority->withActiveSession('actor-a', $markerA, function () use ($db): string {
    $db->table('synthetic_effects')->insert(['id' => 'writer-before-revoke']);

    return 'writer';
}) === 'writer');
$denied(fn () => $authority->withActiveSession('actor-b', $markerA, fn () => 'wrong actor'));
$authority->rotate('actor-a', $markerA, $markerB, $expiry);
$denied(fn () => $authority->withActiveSession('actor-a', $markerA, fn () => 'stale'));
$check($authority->withActiveSession('actor-a', $markerB, fn () => 'new') === 'new');
$authority->revoke('actor-a', $markerA); // stale logout cannot revoke the new binding
$check($authority->withActiveSession('actor-a', $markerB, fn () => 'still active') === 'still active');
$authority->revokeAll('actor-a');
$denied(fn () => $authority->withActiveSession('actor-a', $markerB, fn () => $db->table('synthetic_effects')->insert(['id' => 'writer-after-revoke'])));
$check($db->table('synthetic_effects')->where('id', 'writer-before-revoke')->count() === 1);
$check($db->table('synthetic_effects')->where('id', 'writer-after-revoke')->count() === 0);
$check($db->table('platform_session_authorizations')->where('binding_hash', hash('sha256', $markerA))->count() === 1);
$check($db->table('platform_session_authorizations')->where('binding_hash', $markerA)->count() === 0);
$markerD = str_repeat('d', 64);
$authority->registerAfterMfa('actor-a', $markerD, $expiry);
$keys = new CompanyIdempotencyKeys(['a' => base64_encode(str_repeat('k', 32))], 'a', []);
$store = new CompanyIdempotencyStore($keys, new CompanyIdempotencyAuthorization, 10, 10, 60, $authority, $markerD);
$token = $store->issue('actor-a', 'company.create', 60);
$check($store->bind($token, 'actor-a', 'company.create', null, ['name' => 'Synthetic'], time()) === null);
$result = $store->complete($token, 'actor-a', 'company.create', null, ['name' => 'Synthetic'], function () use ($db): array {
    $db->table('synthetic_effects')->insert(['id' => 'composed-writer']);

    return ['id' => 'synthetic-company', 'status' => 201];
});
$check($result === ['id' => 'synthetic-company', 'status' => 201]);
$check($store->status($token, 'actor-a', 'company.create', time())['state'] === 'completed');
$check($store->revoke($token, 'actor-a', 'company.create', time(), fn () => null)['revoked'] === true);
$rollbackToken = $store->issue('actor-a', 'company.create', 60);
$store->bind($rollbackToken, 'actor-a', 'company.create', null, ['name' => 'Rollback'], time());
try {
    $store->complete($rollbackToken, 'actor-a', 'company.create', null, ['name' => 'Rollback'], function () use ($db): array {
        $db->table('synthetic_effects')->insert(['id' => 'rolled-back']);
        throw new RuntimeException('synthetic writer failure');
    });
    throw new LogicException('Writer failure was not propagated.');
} catch (RuntimeException $e) {
    $check($e->getMessage() === 'synthetic writer failure');
}
$check($db->table('synthetic_effects')->where('id', 'rolled-back')->count() === 0);
$check($store->status($rollbackToken, 'actor-a', 'company.create', time())['state'] === 'observed');
$db->table('platform_role_permissions')->delete();
try {
    $store->status($rollbackToken, 'actor-a', 'company.create', time());
    throw new RuntimeException('Revoked grant was accepted.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 403);
}
$db->table('platform_role_permissions')->insert(['platform_role_id' => 'role', 'platform_permission_id' => 'permission']);
$db->beginTransaction();
foreach ([
    fn () => $store->issue('actor-a', 'company.create', 60),
    fn () => $store->bind($rollbackToken, 'actor-a', 'company.create', null, ['name' => 'Rollback'], time()),
    fn () => $store->complete($rollbackToken, 'actor-a', 'company.create', null, ['name' => 'Rollback'], fn () => ['id' => 'wrong', 'status' => 201]),
    fn () => $store->status($rollbackToken, 'actor-a', 'company.create', time()),
    fn () => $store->revoke($rollbackToken, 'actor-a', 'company.create', time(), fn () => null),
] as $nestedCall) {
    try {
        $nestedCall();
        throw new RuntimeException('Nested transaction was accepted.');
    } catch (LogicException) {
        $check($db->transactionLevel() === 1);
    }
}
$db->rollBack();
$authority->revoke('actor-a', $markerD);
$denied(fn () => $store->status($token, 'actor-a', 'company.create', time()));
$denied(fn () => $store->bind($token, 'actor-a', 'company.create', null, ['name' => 'Synthetic'], time()));
$denied(fn () => $store->complete($token, 'actor-a', 'company.create', null, ['name' => 'Synthetic'], fn () => ['id' => 'wrong', 'status' => 201]));
$denied(fn () => $store->revoke($token, 'actor-a', 'company.create', time(), fn () => null));
$denied(fn () => $store->issue('actor-a', 'company.create', 60));
$markerE = str_repeat('e', 64);
$authority->registerAfterMfa('actor-a', $markerE, $expiry);
$orderedStore = new CompanyIdempotencyStore($keys, new CompanyIdempotencyAuthorization, 10, 10, 60, $authority, $markerE);
$statements = [];
$db->beforeExecuting(function (string $query) use (&$statements): void {
    $statements[] = strtolower($query);
});
$checkOrder = function () use (&$statements, $check): void {
    $actor = $binding = $tokenRow = null;
    foreach ($statements as $index => $query) {
        if ($actor === null && str_contains($query, 'platform_admins')) {
            $actor = $index;
        }
        if ($binding === null && str_contains($query, 'platform_session_authorizations')) {
            $binding = $index;
        }
        if ($tokenRow === null && str_contains($query, 'company_idempotency_requests')) {
            $tokenRow = $index;
        }
    }
    $check($actor !== null && $binding !== null && $tokenRow !== null && $actor < $binding && $binding < $tokenRow);
    $statements = [];
};
$orderedToken = $orderedStore->issue('actor-a', 'company.create', 60);
$checkOrder();
$orderedStore->bind($orderedToken, 'actor-a', 'company.create', null, ['name' => 'Ordered'], time());
$checkOrder();
$orderedStore->complete($orderedToken, 'actor-a', 'company.create', null, ['name' => 'Ordered'], fn () => ['id' => 'ordered', 'status' => 201]);
$checkOrder();
$orderedStore->status($orderedToken, 'actor-a', 'company.create', time());
$checkOrder();
$orderedStore->revoke($orderedToken, 'actor-a', 'company.create', time(), fn () => null);
$checkOrder();
$expired = str_repeat('c', 64);
try {
    $authority->registerAfterMfa('actor-a', $expired, time() - 1);
    throw new RuntimeException('Expired binding was accepted.');
} catch (InvalidArgumentException) {
    $check($db->table('platform_session_authorizations')->where('binding_hash', hash('sha256', $expired))->count() === 0);
}
fwrite(STDOUT, "SQLite in-memory smoke: {$assertions} assertions passed.\n");
