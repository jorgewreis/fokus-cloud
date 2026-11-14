<?php

// This isolated smoke test creates no files and never accepts a DB target.
// Pass an installed Composer autoloader as the sole argument.
if ($argc !== 2 || ! is_file($argv[1])) {
    throw new RuntimeException('An installed Composer autoloader is required.');
}
require $argv[1];
require __DIR__.'/../../app/Services/PlatformSessionAuthority.php';

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
$db->statement('CREATE TABLE platform_admins (id TEXT PRIMARY KEY)');
$db->statement('CREATE TABLE platform_session_authorizations (binding_hash TEXT PRIMARY KEY, actor_id TEXT NOT NULL, expires_at_epoch INTEGER NOT NULL, revoked INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT)');
$db->statement('CREATE TABLE synthetic_effects (id TEXT PRIMARY KEY)');
$db->table('platform_admins')->insert([['id' => 'actor-a'], ['id' => 'actor-b']]);
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
$expired = str_repeat('c', 64);
try {
    $authority->registerAfterMfa('actor-a', $expired, time() - 1);
    throw new RuntimeException('Expired binding was accepted.');
} catch (InvalidArgumentException) {
    $check($db->table('platform_session_authorizations')->where('binding_hash', hash('sha256', $expired))->count() === 0);
}
fwrite(STDOUT, "SQLite in-memory smoke: {$assertions} assertions passed.\n");
