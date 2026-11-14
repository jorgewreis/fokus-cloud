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
require __DIR__.'/../../app/Services/CompanyIdempotencyFactory.php';
require __DIR__.'/../../app/Services/PlatformSessionBinding.php';
require __DIR__.'/../../app/Services/PlatformSessionLifecycle.php';
require __DIR__.'/../../app/Services/WebPrincipalGeneration.php';
require __DIR__.'/../../app/Http/Controllers/Api/AuthController.php';

use App\Http\Controllers\Api\AuthController;
use App\Models\User;
use App\Services\CompanyIdempotencyAuthorization;
use App\Services\CompanyIdempotencyFactory;
use App\Services\CompanyIdempotencyKeys;
use App\Services\CompanyIdempotencyStore;
use App\Services\PlatformSessionAuthority;
use App\Services\PlatformSessionBinding;
use App\Services\PlatformSessionLifecycle;
use App\Services\WebPrincipalGeneration;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;

$app = new Application(__DIR__);
$app->instance('config', new Repository(['company_idempotency' => ['session_authority_lifecycle' => true, 'session_authority_max_age_seconds' => 120], 'session' => ['driver' => 'database', 'connection' => null, 'table' => 'sessions', 'lifetime' => 2]]));
$capsule = new Capsule($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'other');
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
$db->statement('CREATE TABLE platform_session_authorizations (binding_hash TEXT PRIMARY KEY, actor_id TEXT NOT NULL, principal_known INTEGER NOT NULL DEFAULT 0, web_user_id TEXT, web_generation INTEGER, expires_at_epoch INTEGER NOT NULL, revoked INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE synthetic_effects (id TEXT PRIMARY KEY)');
$db->statement('CREATE TABLE sessions (id TEXT PRIMARY KEY, payload TEXT, last_activity INTEGER NOT NULL)');
$db->statement('CREATE TABLE synthetic_challenges (id TEXT PRIMARY KEY, used INTEGER NOT NULL DEFAULT 0)');
$db->statement('CREATE TABLE synthetic_passwords (id TEXT PRIMARY KEY, password TEXT NOT NULL)');
$db->table('synthetic_passwords')->insert(['id' => 'synthetic-user', 'password' => 'before']);
$sessionId = str_repeat('S', 40);
$db->table('sessions')->insert(['id' => $sessionId, 'payload' => base64_encode('synthetic'), 'last_activity' => time()]);
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
// Synthetic test fixture only: no production path certifies platform-only context yet.
$fixturePlatformOnly = function (string $marker) use ($db): void {
    $db->table('platform_session_authorizations')->where('binding_hash', hash('sha256', $marker))->update(['principal_known' => true]);
};
try {
    $authority->withActiveSession('actor-a', $markerA, $sessionId, fn () => null);
    throw new LogicException('Unknown principal was accepted.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 503);
}
$unknownConsumed = false;
foreach ([
    fn () => $authority->rotate('actor-a', $markerA, str_repeat('4', 64), $expiry),
    function () use ($authority, $expiry, $markerA, &$unknownConsumed): void {
        $authority->completeMfa('actor-a', str_repeat('3', 64), $expiry, 'actor-a', $markerA, function () use (&$unknownConsumed): void {
            $unknownConsumed = true;
        });
    },
] as $unknownOperation) {
    try {
        $unknownOperation();
        throw new LogicException('Unknown principal transition was accepted.');
    } catch (HttpException $e) {
        $check($e->getStatusCode() === 503);
    }
}
$check(! $unknownConsumed);
$fixturePlatformOnly($markerA);
$db->statement('CREATE TABLE users (id TEXT PRIMARY KEY, status TEXT NOT NULL DEFAULT \'ativa\', locked_until TEXT, password TEXT)');
$db->statement('CREATE TABLE web_principal_generations (user_id TEXT PRIMARY KEY, generation INTEGER NOT NULL, updated_at TEXT NOT NULL)');
$db->table('users')->insert(['id' => 'principal', 'password' => 'synthetic-hash']);
$generations = new WebPrincipalGeneration;
$loginUser = (new User)->newFromBuilder(['id' => 'principal', 'password' => 'synthetic-hash']);
$check($generations->snapshotForLogin($loginUser) === ['user_id' => 'principal', 'generation' => 0]);
$firstSnapshot = $generations->snapshotForLogin($loginUser);
$generations->advance('principal', fn () => null);
$check($firstSnapshot['generation'] === 0);
$check($generations->snapshotForLogin($loginUser)['generation'] === 1);
$db->table('web_principal_generations')->where('user_id', 'principal')->delete();
$db->table('users')->where('id', 'principal')->update(['password' => 'changed-hash']);
try {
    $generations->snapshotForLogin($loginUser);
    throw new LogicException('Stale credentials were accepted for snapshot.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 401);
}
$db->table('users')->where('id', 'principal')->update(['password' => 'synthetic-hash']);
$db->table('synthetic_challenges')->insert(['id' => 'initial-mfa', 'used' => 0]);
$initialMarker = str_repeat('2', 64);
try {
    $authority->completeInitialMfaForPrincipal('actor-a', $initialMarker, $expiry, 'principal', 0, function () use ($db): void {
        $db->table('synthetic_challenges')->where('id', 'initial-mfa')->update(['used' => 1]);
        throw new RuntimeException('initial challenge failure');
    });
    throw new LogicException('MFA failure was not propagated.');
} catch (RuntimeException $e) {
    $check($e->getMessage() === 'initial challenge failure');
}
$check((int) $db->table('synthetic_challenges')->where('id', 'initial-mfa')->value('used') === 0);
$check($db->table('platform_session_authorizations')->where('binding_hash', hash('sha256', $initialMarker))->count() === 0);
$authority->completeInitialMfaForPrincipal('actor-a', $initialMarker, $expiry, 'principal', 0, function () use ($db): void {
    $db->table('synthetic_challenges')->where('id', 'initial-mfa')->update(['used' => 1]);
});
$check($authority->withActivePrincipalSession('actor-a', $initialMarker, $sessionId, 'principal', fn () => 'initial') === 'initial');
$initialPlatformMarker = str_repeat('1', 64);
$authority->completeInitialMfaForPrincipal('actor-a', $initialPlatformMarker, $expiry, null, null, fn () => null);
$check($authority->withActiveSession('actor-a', $initialPlatformMarker, $sessionId, fn () => 'platform-only') === 'platform-only');
$check($generations->withLockedPrincipal('principal', fn (int $generation): int => $generation) === 0);
try {
    $generations->advance('principal', function () use ($db): void {
        $db->table('synthetic_passwords')->where('id', 'synthetic-user')->update(['password' => 'after']);
        throw new RuntimeException('synthetic generation failure');
    });
    throw new LogicException('Generation failure was not propagated.');
} catch (RuntimeException $e) {
    $check($e->getMessage() === 'synthetic generation failure');
}
$check($db->table('web_principal_generations')->where('user_id', 'principal')->count() === 0);
$check($db->table('synthetic_passwords')->where('id', 'synthetic-user')->value('password') === 'before');
$generations->advance('principal', fn () => null);
$check($generations->withLockedPrincipal('principal', fn (int $generation): int => $generation) === 1);
$staleConsumed = false;
try {
    $authority->completeInitialMfaForPrincipal('actor-a', str_repeat('0', 64), $expiry, 'principal', 0, function () use (&$staleConsumed): void {
        $staleConsumed = true;
    });
    throw new LogicException('Stale login generation was accepted.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 401);
}
$check(! $staleConsumed);
$db->table('users')->where('id', 'principal')->update(['status' => 'bloqueada']);
try {
    $authority->completeInitialMfaForPrincipal('actor-a', str_repeat('0', 64), $expiry, 'principal', 1, fn () => null);
    throw new LogicException('Ineligible user was accepted.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 401);
}
$db->table('users')->where('id', 'principal')->update(['status' => 'ativa']);
$principalMarker = str_repeat('9', 64);
$authority->registerPrincipalAfterMfa('actor-a', $principalMarker, $expiry, 'principal', 1);
$check($authority->withActivePrincipalSession('actor-a', $principalMarker, $sessionId, 'principal', fn () => 'allowed') === 'allowed');
$mfaConsumed = false;
foreach ([
    fn () => $authority->withActiveSession('actor-a', $principalMarker, $sessionId, fn () => null),
    fn () => $authority->rotate('actor-a', $principalMarker, str_repeat('8', 64), $expiry),
    function () use ($authority, $expiry, $principalMarker, &$mfaConsumed): void {
        $authority->completeMfa('actor-a', str_repeat('7', 64), $expiry, 'actor-a', $principalMarker, function () use (&$mfaConsumed): void {
            $mfaConsumed = true;
        });
    },
] as $mixedOperation) {
    try {
        $mixedOperation();
        throw new LogicException('Legacy mixed-context operation was accepted.');
    } catch (HttpException $e) {
        $check($e->getStatusCode() === 503);
    }
}
$check($db->table('platform_session_authorizations')->where('binding_hash', hash('sha256', $principalMarker))->value('revoked') === 0);
$check($db->table('platform_session_authorizations')->where('binding_hash', hash('sha256', str_repeat('7', 64)))->count() === 0);
$check(! $mfaConsumed);
try {
    $authority->completeMfa('actor-a', str_repeat('6', 64), $expiry, 'actor-a', str_repeat('5', 64), function () use (&$mfaConsumed): void {
        $mfaConsumed = true;
    });
    throw new LogicException('Missing prior authority was accepted.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 503);
}
$check(! $mfaConsumed);
$db->table('users')->insert(['id' => 'other-principal']);
try {
    $authority->withActivePrincipalSession('actor-a', $principalMarker, $sessionId, 'other-principal', fn () => null);
    throw new LogicException('Wrong principal hint was accepted.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 401);
}
$generations->advance('principal', fn () => null);
try {
    $authority->withActivePrincipalSession('actor-a', $principalMarker, $sessionId, 'principal', fn () => null);
    throw new LogicException('Old principal generation was accepted.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 401);
}
try {
    $generations->withLockedPrincipal('missing', fn () => null);
    throw new LogicException('Missing principal was accepted.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 401);
}
try {
    $db->transaction(fn () => $generations->advance('principal', fn () => null));
    throw new RuntimeException('Nested principal generation was accepted.');
} catch (LogicException) {
    $check(true);
}
$db->statement('CREATE TABLE company_memberships (id TEXT PRIMARY KEY, user_id TEXT, status TEXT, version INTEGER, updated_at TEXT)');
$db->statement('CREATE TABLE law_unit_memberships (id TEXT PRIMARY KEY, company_membership_id TEXT, status TEXT, version INTEGER, updated_at TEXT)');
$db->statement('CREATE TABLE company_invitations (id TEXT PRIMARY KEY, membership_id TEXT, accepted_at TEXT, updated_at TEXT)');
$db->table('company_memberships')->insert(['id' => 'member', 'user_id' => 'synthetic-user', 'status' => 'pendente', 'version' => 1]);
$db->table('law_unit_memberships')->insert(['id' => 'unit', 'company_membership_id' => 'member', 'status' => 'pendente', 'version' => 1]);
$db->table('company_invitations')->insert(['id' => 'invite', 'membership_id' => 'member']);
$controller = (new ReflectionClass(AuthController::class))->newInstanceWithoutConstructor();
$activate = new ReflectionMethod($controller, 'activatePasswordMembership');
$user = new User;
$user->id = 'synthetic-user';
$token = (object) ['payload' => json_encode(['membership_id' => 'member'])];
$db->statement("CREATE TRIGGER synthetic_fail_invite BEFORE UPDATE ON company_invitations BEGIN SELECT RAISE(FAIL, 'synthetic invite failure'); END");
try {
    $db->transaction(function () use ($db, $activate, $controller, $token, $user): void {
        $db->table('synthetic_passwords')->where('id', 'synthetic-user')->update(['password' => 'after']);
        $activate->invoke($controller, $token, $user);
    });
    throw new RuntimeException('Invitation failure was not propagated.');
} catch (QueryException) {
    $check($db->table('company_memberships')->where('id', 'member')->value('status') === 'pendente');
    $check($db->table('law_unit_memberships')->where('id', 'unit')->value('status') === 'pendente');
    $check($db->table('synthetic_passwords')->where('id', 'synthetic-user')->value('password') === 'before');
}
$db->statement('DROP TRIGGER synthetic_fail_invite');
$db->transaction(fn () => $activate->invoke($controller, $token, $user));
$check($db->table('company_memberships')->where('id', 'member')->value('status') === 'ativo');
$check($db->table('law_unit_memberships')->where('id', 'unit')->value('status') === 'ativo');
$check($db->table('company_invitations')->where('id', 'invite')->value('accepted_at') !== null);
$denied = function (callable $call) use ($check): void {
    try {
        $call();
    } catch (HttpException $e) {
        $check($e->getStatusCode() === 401);

        return;
    }
    throw new RuntimeException('Expected inactive session to be rejected.');
};
$check($authority->withActiveSession('actor-a', $markerA, $sessionId, function () use ($db): string {
    $db->table('synthetic_effects')->insert(['id' => 'writer-before-revoke']);

    return 'writer';
}) === 'writer');
$denied(fn () => $authority->withActiveSession('actor-b', $markerA, $sessionId, fn () => 'wrong actor'));
$authority->rotate('actor-a', $markerA, $markerB, $expiry);
$denied(fn () => $authority->withActiveSession('actor-a', $markerA, $sessionId, fn () => 'stale'));
$check($authority->withActiveSession('actor-a', $markerB, $sessionId, fn () => 'new') === 'new');
$authority->revoke('actor-a', $markerA); // stale logout cannot revoke the new binding
$check($authority->withActiveSession('actor-a', $markerB, $sessionId, fn () => 'still active') === 'still active');
$authority->revokeAll('actor-a');
$denied(fn () => $authority->withActiveSession('actor-a', $markerB, $sessionId, fn () => $db->table('synthetic_effects')->insert(['id' => 'writer-after-revoke'])));
$check($db->table('synthetic_effects')->where('id', 'writer-before-revoke')->count() === 1);
$check($db->table('synthetic_effects')->where('id', 'writer-after-revoke')->count() === 0);
$check($db->table('platform_session_authorizations')->where('binding_hash', hash('sha256', $markerA))->count() === 1);
$check($db->table('platform_session_authorizations')->where('binding_hash', $markerA)->count() === 0);
$markerD = str_repeat('d', 64);
$authority->registerAfterMfa('actor-a', $markerD, $expiry);
$fixturePlatformOnly($markerD);
$keys = new CompanyIdempotencyKeys(['a' => base64_encode(str_repeat('k', 32))], 'a', []);
$store = new CompanyIdempotencyStore($keys, new CompanyIdempotencyAuthorization, 10, 10, 60, $authority, $markerD, $sessionId);
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
$fixturePlatformOnly($markerE);
$orderedStore = new CompanyIdempotencyStore($keys, new CompanyIdempotencyAuthorization, 10, 10, 60, $authority, $markerE, $sessionId);
$statements = [];
$db->beforeExecuting(function (string $query) use (&$statements): void {
    $statements[] = strtolower($query);
});
$checkOrder = function () use (&$statements, $check): void {
    $actor = $binding = $sessionRow = $tokenRow = null;
    foreach ($statements as $index => $query) {
        if ($actor === null && str_contains($query, 'platform_admins')) {
            $actor = $index;
        }
        if ($binding === null && str_contains($query, 'platform_session_authorizations')) {
            $binding = $index;
        }
        if ($sessionRow === null && str_contains($query, '"sessions"')) {
            $sessionRow = $index;
        }
        if ($tokenRow === null && str_contains($query, 'company_idempotency_requests')) {
            $tokenRow = $index;
        }
    }
    $check($actor !== null && $binding !== null && $sessionRow !== null && $tokenRow !== null && $actor < $binding && $binding < $sessionRow && $sessionRow < $tokenRow);
    $statements = [];
};
$orderedToken = $orderedStore->issue('actor-a', 'company.create', 60);
$checkOrder();
$factoryConfig = [
    'enabled' => true, 'keyring_json' => '{"a":"'.base64_encode(str_repeat('k', 32)).'"}',
    'active_kid' => 'a', 'revoked_kids_json' => '[]', 'max_outstanding' => 10,
    'max_issues_per_window' => 10, 'issuance_window_seconds' => 60, 'token_lifetime_seconds' => 60,
];
$factory = new CompanyIdempotencyFactory;
try {
    $factory->build($factoryConfig);
    throw new RuntimeException('Factory accepted an absent session marker.');
} catch (InvalidArgumentException $e) {
    $check($e->getMessage() === 'Company idempotency is unavailable.');
}
$unknown = $factory->build($factoryConfig, str_repeat('f', 64), $sessionId)['store'];
$denied(fn () => $unknown->issue('actor-a', 'company.create', 60));
$factoryStore = $factory->build($factoryConfig, $markerE, $sessionId)['store'];
$factoryToken = $factoryStore->issue('actor-a', 'company.create', 60);
$check($factoryStore->status($factoryToken, 'actor-a', 'company.create', time())['state'] === 'issued');
$statements = [];
$orderedStore->bind($orderedToken, 'actor-a', 'company.create', null, ['name' => 'Ordered'], time());
$checkOrder();
$orderedStore->complete($orderedToken, 'actor-a', 'company.create', null, ['name' => 'Ordered'], fn () => ['id' => 'ordered', 'status' => 201]);
$checkOrder();
$orderedStore->status($orderedToken, 'actor-a', 'company.create', time());
$checkOrder();
$orderedStore->revoke($orderedToken, 'actor-a', 'company.create', time(), fn () => null);
$checkOrder();
$db->table('sessions')->where('id', $sessionId)->delete();
$denied(fn () => $orderedStore->issue('actor-a', 'company.create', 60));
$db->table('sessions')->insert(['id' => $sessionId, 'payload' => base64_encode('synthetic'), 'last_activity' => time() - 121]);
$denied(fn () => $orderedStore->issue('actor-a', 'company.create', 60));
$db->table('sessions')->where('id', $sessionId)->update(['last_activity' => time()]);
$app['config']->set('session.connection', 'other');
try {
    $orderedStore->issue('actor-a', 'company.create', 60);
    throw new RuntimeException('Separate session connection was accepted.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 503);
}
$app['config']->set('session.connection', null);
$app['config']->set('session.driver', 'array');
try {
    $orderedStore->issue('actor-a', 'company.create', 60);
    throw new RuntimeException('Non-database session was accepted.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 503);
}
$app['config']->set('session.driver', 'database');
$lifecycle = new PlatformSessionLifecycle($authority);
$pendingUser = (new User)->newFromBuilder(['id' => 'principal', 'status' => 'pendente', 'password' => 'synthetic-hash']);
$check($lifecycle->captureWebLogin($pendingUser) === null);
$staleLogin = (new User)->newFromBuilder(['id' => 'principal', 'status' => 'ativa', 'password' => 'stale-hash']);
$check($lifecycle->captureWebLogin($staleLogin) === null);
try {
    $lifecycle->ensurePasswordResetReady();
    throw new RuntimeException('Experimental password reset was accepted.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 503);
}
$app['config']->set('company_idempotency.session_authority_lifecycle', false);
$lifecycle->ensurePasswordResetReady();
$check(true);
$app['config']->set('company_idempotency.session_authority_lifecycle', true);
$app['config']->set('company_idempotency.session_authority_max_age_seconds', null);
try {
    $lifecycle->ensureConfigurationReady();
    throw new RuntimeException('Missing lifecycle policy was accepted.');
} catch (LogicException) {
    $check(true);
}
$app['config']->set('company_idempotency.session_authority_max_age_seconds', 120);
try {
    $lifecycle->ensureConfigurationReady();
    throw new LogicException('Uncertified MFA issuer was accepted.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 503);
}
$app->instance('auth', new class
{
    public function guard(string $guard): object
    {
        if ($guard !== 'web') {
            throw new LogicException('Unexpected synthetic guard.');
        }

        return new class
        {
            public function id(): string
            {
                return 'principal';
            }
        };
    }
});
$guardSession = new Store('synthetic-guard', new ArraySessionHandler(2));
$guardSession->start();
$guardSession->setId($sessionId);
$missingSnapshotConsumed = false;
try {
    $lifecycle->authenticatedFromGuards($guardSession, 'actor-a', null, function () use (&$missingSnapshotConsumed): void {
        $missingSnapshotConsumed = true;
    });
    throw new LogicException('Missing login generation was accepted.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 503);
}
$check(! $missingSnapshotConsumed);
$guardSession->put('platform_web_principal_snapshot', ['user_id' => 'principal', 'generation' => 2]);
$lifecycle->authenticatedFromGuards($guardSession, 'actor-a', null, fn () => null);
$guardMarker = (new PlatformSessionBinding)->current($guardSession);
$check($authority->withActivePrincipalSession('actor-a', $guardMarker, $sessionId, 'principal', fn () => 'guard') === 'guard');
$guardConsumed = false;
try {
    $lifecycle->authenticatedFromGuards($guardSession, 'actor-a', 'actor-a', function () use (&$guardConsumed): void {
        $guardConsumed = true;
    });
    throw new LogicException('Existing binding was accepted for initial MFA.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 503);
}
$check(! $guardConsumed);
$session = new Store('synthetic', new ArraySessionHandler(2));
$session->start();
$session->setId($sessionId);
$lifecycle->ensureRegistrationReady($session, null);
try {
    $lifecycle->ensureRegistrationReady($session, 'actor-b');
    throw new LogicException('Mixed registration was accepted.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 503);
}
$lifecycle->authenticated($session, 'actor-b', null, fn () => null);
$sessionBinding = (new PlatformSessionBinding)->current($session);
$check(is_string($sessionBinding) && strlen($sessionBinding) === 64);
$fixturePlatformOnly($sessionBinding);
$check($authority->withActiveSession('actor-b', $sessionBinding, $sessionId, fn () => 'authenticated') === 'authenticated');
$lifecycle->rotateIfPresent($session, 'actor-b');
$rotatedBinding = (new PlatformSessionBinding)->current($session);
$check(is_string($rotatedBinding) && $rotatedBinding !== $sessionBinding);
$denied(fn () => $authority->withActiveSession('actor-b', $sessionBinding, $sessionId, fn () => 'old context'));
$check($authority->withActiveSession('actor-b', $rotatedBinding, $sessionId, fn () => 'new context') === 'new context');
$lifecycle->retire($session, 'actor-b');
$check((new PlatformSessionBinding)->current($session) === null);
$denied(fn () => $authority->withActiveSession('actor-b', $rotatedBinding, $sessionId, fn () => 'after logout'));
$lifecycle->rotateIfPresent($session, 'actor-b');
$check((new PlatformSessionBinding)->current($session) === null);
$lifecycle->authenticated($session, 'actor-b', null, fn () => null);
$currentBinding = (new PlatformSessionBinding)->current($session);
$fixturePlatformOnly($currentBinding);
$app['config']->set('company_idempotency.session_authority_lifecycle', false);
$lifecycle->rotateIfEnabled($session, null);
$check((new PlatformSessionBinding)->current($session) === $currentBinding);
$app['config']->set('company_idempotency.session_authority_lifecycle', true);
try {
    $lifecycle->retire($session, null);
    throw new RuntimeException('Unknown session owner was accepted.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 503);
}
$check((new PlatformSessionBinding)->current($session) === $currentBinding);
$db->table('synthetic_challenges')->insert(['id' => 'challenge', 'used' => 0]);
try {
    $lifecycle->authenticated($session, 'actor-b', 'actor-b', function () use ($db): void {
        $db->table('synthetic_challenges')->where('id', 'challenge')->update(['used' => 1]);
        throw new RuntimeException('synthetic challenge failure');
    });
    throw new LogicException('Challenge failure was not propagated.');
} catch (RuntimeException $e) {
    $check($e->getMessage() === 'synthetic challenge failure');
}
$check((int) $db->table('synthetic_challenges')->where('id', 'challenge')->value('used') === 0);
$check((new PlatformSessionBinding)->current($session) === $currentBinding);
$check($authority->withActiveSession('actor-b', $currentBinding, $sessionId, fn () => 'still active') === 'still active');
$lifecycle->authenticated($session, 'actor-b', 'actor-b', function () use ($db): void {
    $db->table('synthetic_challenges')->where('id', 'challenge')->update(['used' => 1]);
});
$check((int) $db->table('synthetic_challenges')->where('id', 'challenge')->value('used') === 1);
$newBinding = (new PlatformSessionBinding)->current($session);
$check(is_string($newBinding) && $newBinding !== $currentBinding);
$fixturePlatformOnly($newBinding);
$denied(fn () => $authority->withActiveSession('actor-b', $currentBinding, $sessionId, fn () => 'after reauthentication'));
$currentBinding = $newBinding;
$db->table('synthetic_challenges')->where('id', 'challenge')->update(['used' => 0]);
try {
    $lifecycle->retireAndRun($session, 'actor-b', function () use ($db): void {
        $db->table('synthetic_challenges')->where('id', 'challenge')->update(['used' => 1]);
        throw new RuntimeException('synthetic password failure');
    });
    throw new LogicException('Password failure was not propagated.');
} catch (RuntimeException $e) {
    $check($e->getMessage() === 'synthetic password failure');
}
$check((int) $db->table('synthetic_challenges')->where('id', 'challenge')->value('used') === 0);
$check((new PlatformSessionBinding)->current($session) === $currentBinding);
$check($authority->withActiveSession('actor-b', $currentBinding, $sessionId, fn () => 'still valid') === 'still valid');
$db->statement("CREATE TRIGGER synthetic_fail_delete BEFORE DELETE ON sessions BEGIN SELECT RAISE(FAIL, 'synthetic delete failure'); END");
try {
    $lifecycle->retireAndRun($session, 'actor-b', function () use ($db, $sessionId): void {
        $db->table('synthetic_challenges')->where('id', 'challenge')->update(['used' => 1]);
        $db->table('synthetic_passwords')->where('id', 'synthetic-user')->update(['password' => 'after']);
        $db->table('sessions')->where('id', $sessionId)->delete();
    });
    throw new LogicException('Session deletion failure was not propagated.');
} catch (QueryException) {
    $check((int) $db->table('synthetic_challenges')->where('id', 'challenge')->value('used') === 0);
}
$check($db->table('synthetic_passwords')->where('id', 'synthetic-user')->value('password') === 'before');
$check($authority->withActiveSession('actor-b', $currentBinding, $sessionId, fn () => 'rollback') === 'rollback');
$check((new PlatformSessionBinding)->current($session) === $currentBinding);
$db->statement('DROP TRIGGER synthetic_fail_delete');
$app['config']->set('session.connection', 'other');
try {
    $lifecycle->transactionalSessionTable();
    throw new RuntimeException('Separate reset session connection was accepted.');
} catch (HttpException $e) {
    $check($e->getStatusCode() === 503);
}
$check((int) $db->table('synthetic_challenges')->where('id', 'challenge')->value('used') === 0);
$check($db->table('synthetic_passwords')->where('id', 'synthetic-user')->value('password') === 'before');
$app['config']->set('session.connection', null);
$lifecycle->retireAndRun($session, 'actor-b', function () use ($db): void {
    $db->table('synthetic_challenges')->where('id', 'challenge')->update(['used' => 1]);
});
$check((int) $db->table('synthetic_challenges')->where('id', 'challenge')->value('used') === 1);
$check((new PlatformSessionBinding)->current($session) === null);
$denied(fn () => $authority->withActiveSession('actor-b', $currentBinding, $sessionId, fn () => 'retired'));
$lifecycle->authenticated($session, 'actor-b', null, fn () => null);
$currentBinding = (new PlatformSessionBinding)->current($session);
$db->table('platform_admins')->where('id', 'actor-b')->update(['status' => 'suspenso']);
$denied(fn () => $authority->registerAfterMfa('actor-b', str_repeat('f', 64), $expiry));
$denied(fn () => $authority->rotate('actor-b', $currentBinding, str_repeat('f', 64), $expiry));
$lifecycle->revokeAll('actor-b');
$denied(fn () => $authority->withActiveSession('actor-b', $currentBinding, $sessionId, fn () => 'after bulk revoke'));
$expired = str_repeat('c', 64);
try {
    $authority->registerAfterMfa('actor-a', $expired, time() - 1);
    throw new RuntimeException('Expired binding was accepted.');
} catch (InvalidArgumentException) {
    $check($db->table('platform_session_authorizations')->where('binding_hash', hash('sha256', $expired))->count() === 0);
}
$handler = new DatabaseSessionHandler($db, 'sessions', 2);
$check($handler->read($sessionId) === 'synthetic');
$handler->destroy($sessionId);
$handler->write($sessionId, 'stale-write');
$check($db->table('sessions')->where('id', $sessionId)->count() === 0);
$authority->revoke('actor-a', $markerE);
$newSessionId = str_repeat('N', 40);
$handler->setExists(false);
$handler->write($newSessionId, 'regenerated-session');
$check($db->table('sessions')->where('id', $newSessionId)->count() === 1);
$denied(fn () => $authority->withActiveSession('actor-a', $markerE, $newSessionId, fn () => 'revived'));
fwrite(STDOUT, "SQLite in-memory smoke: {$assertions} assertions passed.\n");
