<?php

use App\Http\Controllers\Api\PlatformAdminController;
use App\Models\PlatformAdmin;
use App\Services\CompanyIdempotencyAuthorization;
use App\Services\CompanyIdempotencyKeys;
use App\Services\CompanyIdempotencyStore;
use App\Services\PlatformAudit;
use App\Services\PlatformSecurity;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Opt-in lab only: use a new Docker MySQL without network and without real data.
if (getenv('FOKUS_COMPANY_IDEMPOTENCY_LAB') !== '1' || getenv('APP_ENV') !== 'testing'
    || getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'fokus_idempotency_test' || getenv('DB_PORT') !== '3306'
    || (getenv('DB_URL') !== false && getenv('DB_URL') !== '')
    || (getenv('DB_SOCKET') !== false && getenv('DB_SOCKET') !== '')
    || ! preg_match('/\A[0-9a-f-]{36}\z/D', (string) getenv('LAB_MYSQL_UUID'))
    || ! function_exists('pcntl_fork')) {
    throw new RuntimeException('Disposable laboratory configuration required.');
}

require '/test-bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$effective = config('database.connections.mysql');
if (! is_array($effective) || ($effective['driver'] ?? null) !== 'mysql'
    || ($effective['host'] ?? null) !== '127.0.0.1' || (string) ($effective['port'] ?? '') !== '3306'
    || ($effective['database'] ?? null) !== 'fokus_idempotency_test'
    || ! in_array($effective['url'] ?? null, [null, ''], true)
    || ! in_array($effective['unix_socket'] ?? null, [null, ''], true)) {
    throw new RuntimeException('Effective connection is not the disposable laboratory.');
}
$actual = DB::selectOne('SELECT @@server_uuid AS server_uuid, DATABASE() AS database_name');
if ($actual->server_uuid !== getenv('LAB_MYSQL_UUID') || $actual->database_name !== 'fokus_idempotency_test'
    || Schema::hasTable('company_idempotency_requests') || Schema::hasTable('synthetic_effects')
    || Schema::hasTable('platform_admins') || Schema::hasTable('platform_permissions') || Schema::hasTable('platform_role_permissions')
    || Schema::hasTable('platform_roles') || Schema::hasTable('sessions')) {
    throw new RuntimeException('Fresh, identified disposable database required.');
}
(require database_path('migrations/2026_11_14_000001_create_company_idempotency_requests.php'))->up();
Schema::create('synthetic_effects', function (Blueprint $table): void {
    $table->string('id')->primary();
});
Schema::create('platform_admins', function (Blueprint $table): void {
    $table->string('id')->primary();
    $table->string('status');
    $table->string('email');
    $table->string('name');
    $table->string('platform_role_id')->nullable();
    $table->timestamp('manual_blocked_at')->nullable();
    $table->string('manual_blocked_by')->nullable();
    $table->string('blocked_reason')->nullable();
    $table->timestamp('deactivated_at')->nullable();
    $table->timestamp('locked_until')->nullable();
    $table->timestamps();
});
Schema::create('platform_roles', function (Blueprint $table): void {
    $table->string('id')->primary();
    $table->string('code')->unique();
    $table->string('name');
    $table->timestamps();
});
Schema::create('sessions', function (Blueprint $table): void {
    $table->string('id')->primary();
    $table->string('user_id')->nullable();
});
Schema::create('platform_permissions', function (Blueprint $table): void {
    $table->string('id')->primary();
    $table->string('code')->unique();
});
Schema::create('platform_role_permissions', function (Blueprint $table): void {
    $table->string('platform_role_id');
    $table->string('platform_permission_id');
    $table->primary(['platform_role_id', 'platform_permission_id']);
});
DB::table('platform_roles')->insert(['id' => 'role-lab', 'code' => 'administrador_comercial', 'name' => 'Synthetic']);
DB::table('platform_admins')->insert(['id' => 'admin-lab', 'name' => 'Synthetic', 'email' => 'synthetic@example.test', 'status' => 'ativo', 'platform_role_id' => 'role-lab']);
DB::table('platform_permissions')->insert(['id' => 'perm-lab', 'code' => 'platform.companies.manage']);
DB::table('platform_role_permissions')->insert(['platform_role_id' => 'role-lab', 'platform_permission_id' => 'perm-lab']);
$keys = new CompanyIdempotencyKeys(['lab' => base64_encode(str_repeat('x', 32))], 'lab', []);
$store = new CompanyIdempotencyStore($keys, new CompanyIdempotencyAuthorization, 10);
$token = $store->issue('admin-lab', 'company.create', now()->getTimestamp(), 600);
$payload = ['name' => 'synthetic'];
$store->bind($token, 'admin-lab', 'company.create', null, $payload, now()->getTimestamp());
DB::disconnect(); // No inherited live PDO connection across fork.

$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($sockets === false || ($pid = pcntl_fork()) === -1) {
    throw new RuntimeException('Unable to create concurrency barrier.');
}
if ($pid === 0) {
    fclose($sockets[0]);
    stream_set_timeout($sockets[1], 45);
    if (trim((string) fgets($sockets[1])) !== 'begin') {
        exit(2);
    }
    fwrite($sockets[1], "started\n");
    try {
        $result = $store->complete($token, 'admin-lab', 'company.create', null, $payload, function (): array {
            DB::table('synthetic_effects')->insert(['id' => 'duplicate-writer']);

            return ['id' => 'duplicate-writer', 'status' => 201];
        });
        fwrite($sockets[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
        exit(0);
    } catch (Throwable) {
        fwrite($sockets[1], "child-error\n");
        exit(3);
    }
}
fclose($sockets[1]);
stream_set_timeout($sockets[0], 45);
try {
    $result = $store->complete($token, 'admin-lab', 'company.create', null, $payload, function () use ($sockets): array {
        DB::table('synthetic_effects')->insert(['id' => 'winner']);
        fwrite($sockets[0], "begin\n");
        if (trim((string) fgets($sockets[0])) !== 'started') {
            throw new RuntimeException('Child not ready.');
        }
        $deadline = microtime(true) + 30;
        do {
            $waits = (int) DB::selectOne('SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits')->n;
            if ($waits > 0) {
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        if ($waits < 1) {
            throw new RuntimeException('No lock wait observed.');
        }

        return ['id' => 'winner', 'status' => 201];
    });
    $childResult = json_decode((string) fgets($sockets[0]), true, 512, JSON_THROW_ON_ERROR);
    pcntl_waitpid($pid, $status);
    if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0 || $result !== $childResult
        || DB::table('synthetic_effects')->count() !== 1
        || DB::table('synthetic_effects')->value('id') !== 'winner') {
        throw new RuntimeException('Concurrent replay invariant failed.');
    }
    echo "PASS: observed InnoDB lock wait, identical replay and exactly one synthetic mutation.\n";
} finally {
    fclose($sockets[0]);
}

/** Execute two operations in separate processes and release only after the row-lock wait is observed. */
function compete(callable $parent, callable $child): array
{
    DB::disconnect();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false || ($pid = pcntl_fork()) === -1) {
        throw new RuntimeException('Unable to create concurrency barrier.');
    }
    if ($pid === 0) {
        fclose($pair[0]);
        stream_set_timeout($pair[1], 45);
        if (trim((string) fgets($pair[1])) !== 'begin') {
            exit(2);
        }
        fwrite($pair[1], "started\n");
        try {
            $result = $child();
            fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
            exit(0);
        } catch (Throwable) {
            fwrite($pair[1], "child-error\n");
            exit(3);
        }
    }
    fclose($pair[1]);
    stream_set_timeout($pair[0], 45);
    try {
        $barrier = function () use ($pair): void {
            fwrite($pair[0], "begin\n");
            if (trim((string) fgets($pair[0])) !== 'started') {
                throw new RuntimeException('Child not ready.');
            }
            $deadline = microtime(true) + 30;
            do {
                $waits = (int) DB::selectOne('SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits')->n;
                if ($waits > 0) {
                    return;
                }
                usleep(100000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('No lock wait observed.');
        };
        $parentResult = $parent($barrier);
        $childResult = json_decode((string) fgets($pair[0]), true, 512, JSON_THROW_ON_ERROR);
        pcntl_waitpid($pid, $status);
        if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('Competing process failed.');
        }

        return [$parentResult, $childResult];
    } finally {
        fclose($pair[0]);
    }
}

$token2 = $store->issue('admin-lab', 'company.create', now()->getTimestamp(), 600);
$store->bind($token2, 'admin-lab', 'company.create', null, $payload, now()->getTimestamp());
[$first, $second] = compete(
    fn ($barrier) => $store->complete($token2, 'admin-lab', 'company.create', null, $payload, function () use ($barrier): array {
        DB::table('synthetic_effects')->insert(['id' => 'mutation-before-revocation']);
        $barrier();

        return ['id' => 'mutation-before-revocation', 'status' => 201];
    }),
    fn () => $store->revoke($token2, 'admin-lab', 'company.create', now()->getTimestamp(), fn () => DB::table('synthetic_effects')->insert(['id' => 'audit-after-mutation']))
);
if ($first !== ['id' => 'mutation-before-revocation', 'status' => 201] || $second !== ['state' => 'completed', 'revoked' => true]) {
    throw new RuntimeException('Mutation-before-revocation invariant failed.');
}

$token3 = $store->issue('admin-lab', 'company.create', now()->getTimestamp(), 600);
$store->bind($token3, 'admin-lab', 'company.create', null, $payload, now()->getTimestamp());
[$first, $second] = compete(
    fn ($barrier) => $store->revoke($token3, 'admin-lab', 'company.create', now()->getTimestamp(), function () use ($barrier): void {
        DB::table('synthetic_effects')->insert(['id' => 'audit-before-mutation']);
        $barrier();
    }),
    function () use ($store, $token3, $payload): array {
        try {
            $store->complete($token3, 'admin-lab', 'company.create', null, $payload, function (): array {
                DB::table('synthetic_effects')->insert(['id' => 'revoked-mutation']);

                return ['id' => 'revoked-mutation', 'status' => 201];
            });
        } catch (HttpException $e) {
            return ['status' => $e->getStatusCode()];
        }

        return ['status' => 200];
    }
);
if ($first !== ['state' => 'observed', 'revoked' => true] || $second !== ['status' => 409]
    || DB::table('synthetic_effects')->where('id', 'revoked-mutation')->exists()) {
    throw new RuntimeException('Revocation-before-mutation invariant failed.');
}
echo "PASS: both lock orders preserve committed mutation or prevent revoked mutation.\n";

// Race a real administrator status update against the store's authorization lock.
function blockUsingActualController(): array
{
    $request = Request::create('/synthetic', 'POST', ['reason' => 'Synthetic concurrency test']);
    $request->setUserResolver(fn () => (object) ['id' => 'operator-lab']);
    $audit = Mockery::mock(PlatformAudit::class);
    $audit->shouldReceive('record')->once()->andReturnNull();
    $response = (new PlatformAdminController)->block($request, PlatformAdmin::findOrFail('admin-lab'), $audit, new PlatformSecurity);

    return ['status' => $response->getStatusCode()];
}

$token4 = $store->issue('admin-lab', 'company.create', now()->getTimestamp(), 600);
$store->bind($token4, 'admin-lab', 'company.create', null, $payload, now()->getTimestamp());
[$first, $second] = compete(
    fn ($barrier) => $store->complete($token4, 'admin-lab', 'company.create', null, $payload, function () use ($barrier): array {
        DB::table('synthetic_effects')->insert(['id' => 'authorized-before-block']);
        $barrier();

        return ['id' => 'authorized-before-block', 'status' => 201];
    }),
    fn () => blockUsingActualController()
);
if ($first !== ['id' => 'authorized-before-block', 'status' => 201] || $second !== ['status' => 200]
    || DB::table('platform_admins')->where('id', 'admin-lab')->value('manual_blocked_at') === null) {
    throw new RuntimeException('Mutation-before-admin-block invariant failed.');
}
DB::table('platform_admins')->where('id', 'admin-lab')->update(['manual_blocked_at' => null, 'manual_blocked_by' => null, 'blocked_reason' => null]);

$token5 = $store->issue('admin-lab', 'company.create', now()->getTimestamp(), 600);
$store->bind($token5, 'admin-lab', 'company.create', null, $payload, now()->getTimestamp());
[$first, $second] = compete(
    fn ($barrier) => DB::transaction(function () use ($barrier): array {
        $result = blockUsingActualController();
        $barrier();

        return $result;
    }),
    function () use ($store, $token5, $payload): array {
        try {
            $store->complete($token5, 'admin-lab', 'company.create', null, $payload, function (): array {
                DB::table('synthetic_effects')->insert(['id' => 'unauthorized-after-block']);

                return ['id' => 'unauthorized-after-block', 'status' => 201];
            });
        } catch (HttpException $e) {
            return ['status' => $e->getStatusCode()];
        }

        return ['status' => 200];
    }
);
if ($first !== ['status' => 200] || $second !== ['status' => 403]
    || DB::table('synthetic_effects')->where('id', 'unauthorized-after-block')->exists()) {
    throw new RuntimeException('Admin-block-before-mutation invariant failed.');
}
DB::table('platform_admins')->where('id', 'admin-lab')->update(['manual_blocked_at' => null, 'manual_blocked_by' => null, 'blocked_reason' => null]);

// Grant deletion must also wait for the permission record shared lock.
$token6 = $store->issue('admin-lab', 'company.create', now()->getTimestamp(), 600);
$store->bind($token6, 'admin-lab', 'company.create', null, $payload, now()->getTimestamp());
[$first, $second] = compete(
    fn ($barrier) => $store->complete($token6, 'admin-lab', 'company.create', null, $payload, function () use ($barrier): array {
        DB::table('synthetic_effects')->insert(['id' => 'authorized-before-grant-removal']);
        $barrier();

        return ['id' => 'authorized-before-grant-removal', 'status' => 201];
    }),
    fn () => ['deleted' => DB::table('platform_role_permissions')->where('platform_role_id', 'role-lab')->where('platform_permission_id', 'perm-lab')->delete()]
);
if ($first !== ['id' => 'authorized-before-grant-removal', 'status' => 201] || $second !== ['deleted' => 1]) {
    throw new RuntimeException('Mutation-before-grant-removal invariant failed.');
}
try {
    $store->bind($token6, 'admin-lab', 'company.create', null, $payload, now()->getTimestamp());
    throw new RuntimeException('Replay must not bypass removed permission.');
} catch (HttpException $e) {
    if ($e->getStatusCode() !== 403) {
        throw $e;
    }
}
DB::table('platform_role_permissions')->insert(['platform_role_id' => 'role-lab', 'platform_permission_id' => 'perm-lab']);
$token7 = $store->issue('admin-lab', 'company.create', now()->getTimestamp(), 600);
$store->bind($token7, 'admin-lab', 'company.create', null, $payload, now()->getTimestamp());
[$first, $second] = compete(
    fn ($barrier) => DB::transaction(function () use ($barrier): array {
        $deleted = DB::table('platform_role_permissions')->where('platform_role_id', 'role-lab')->where('platform_permission_id', 'perm-lab')->delete();
        $barrier();

        return ['deleted' => $deleted];
    }),
    function () use ($store, $token7, $payload): array {
        try {
            $store->complete($token7, 'admin-lab', 'company.create', null, $payload, function (): array {
                DB::table('synthetic_effects')->insert(['id' => 'unauthorized-after-grant-removal']);

                return ['id' => 'unauthorized-after-grant-removal', 'status' => 201];
            });
        } catch (HttpException $e) {
            return ['status' => $e->getStatusCode()];
        }

        return ['status' => 200];
    }
);
if ($first !== ['deleted' => 1] || $second !== ['status' => 403]
    || DB::table('synthetic_effects')->where('id', 'unauthorized-after-grant-removal')->exists()) {
    throw new RuntimeException('Grant-removal-before-mutation invariant failed.');
}
echo "PASS: administrator block and grant changes serialize with authorization and replay.\n";

// A stale REPEATABLE READ snapshot must not override the current locking read.
DB::table('platform_role_permissions')->insert(['platform_role_id' => 'role-lab', 'platform_permission_id' => 'perm-lab']);
if (DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level !== 'REPEATABLE-READ') {
    throw new RuntimeException('Expected REPEATABLE READ laboratory isolation.');
}
DB::disconnect();
$snapshotSockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($snapshotSockets === false || ($snapshotPid = pcntl_fork()) === -1) {
    throw new RuntimeException('Unable to create snapshot barrier.');
}
if ($snapshotPid === 0) {
    fclose($snapshotSockets[0]);
    stream_set_timeout($snapshotSockets[1], 45);
    if (trim((string) fgets($snapshotSockets[1])) !== 'begin') {
        exit(2);
    }
    try {
        $outcome = blockUsingActualController();
        fwrite($snapshotSockets[1], json_encode($outcome, JSON_THROW_ON_ERROR)."\n");
        exit(0);
    } catch (Throwable) {
        fwrite($snapshotSockets[1], "child-error\n");
        exit(3);
    }
}
fclose($snapshotSockets[1]);
stream_set_timeout($snapshotSockets[0], 45);
try {
    $snapshotResult = DB::transaction(function () use ($snapshotSockets): array {
        if (DB::table('platform_admins')->where('id', 'admin-lab')->value('manual_blocked_at') !== null) {
            throw new RuntimeException('Unexpected initial admin state.');
        }
        fwrite($snapshotSockets[0], "begin\n");
        $outcome = json_decode((string) fgets($snapshotSockets[0]), true, 512, JSON_THROW_ON_ERROR);
        if ($outcome !== ['status' => 200]) {
            throw new RuntimeException('Controller block failed.');
        }
        if (DB::table('platform_admins')->where('id', 'admin-lab')->value('manual_blocked_at') !== null) {
            throw new RuntimeException('Expected stale consistent read not observed.');
        }
        try {
            (new CompanyIdempotencyAuthorization)->lockAuthorizedActor('admin-lab');
        } catch (HttpException $e) {
            return ['status' => $e->getStatusCode()];
        }

        return ['status' => 200];
    });
    pcntl_waitpid($snapshotPid, $snapshotStatus);
    if ($snapshotResult !== ['status' => 403] || ! pcntl_wifexited($snapshotStatus) || pcntl_wexitstatus($snapshotStatus) !== 0) {
        throw new RuntimeException('Snapshot authorization invariant failed.');
    }
    echo "PASS: real controller block overrides stale REPEATABLE READ snapshot.\n";
} finally {
    fclose($snapshotSockets[0]);
}

// An administrator-row lock serializes the quota check across two issuers.
DB::table('platform_admins')->insert(['id' => 'quota-lab', 'name' => 'Synthetic', 'email' => 'quota@example.test', 'status' => 'ativo', 'platform_role_id' => 'role-lab']);
$quotaStore = new CompanyIdempotencyStore($keys, new CompanyIdempotencyAuthorization, 1);
DB::disconnect();
$quotaPairs = [stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP), stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP)];
if ($quotaPairs[0] === false || $quotaPairs[1] === false) {
    throw new RuntimeException('Quota barriers unavailable.');
}
$quotaPids = [];
for ($i = 0; $i < 2; $i++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Quota process unavailable.');
    }
    if ($pid === 0) {
        foreach ($quotaPairs as $j => $pair) {
            fclose($pair[0]);
            if ($i !== $j) {
                fclose($pair[1]);
            }
        }
        $socket = $quotaPairs[$i][1];
        stream_set_timeout($socket, 45);
        if (trim((string) fgets($socket)) !== 'begin') {
            exit(2);
        }
        fwrite($socket, "started\n");
        try {
            $quotaStore->issue('quota-lab', 'company.create', now()->getTimestamp(), 600);
            fwrite($socket, "200\n");
            exit(0);
        } catch (HttpException $e) {
            fwrite($socket, $e->getStatusCode()."\n");
            exit(0);
        } catch (Throwable) {
            fwrite($socket, "error\n");
            exit(3);
        }
    }
    $quotaPids[] = $pid;
}
foreach ($quotaPairs as $pair) {
    fclose($pair[1]);
    stream_set_timeout($pair[0], 45);
}
try {
    DB::transaction(function () use ($quotaPairs): void {
        DB::table('platform_admins')->where('id', 'quota-lab')->lockForUpdate()->first();
        foreach ($quotaPairs as $pair) {
            fwrite($pair[0], "begin\n");
        }
        foreach ($quotaPairs as $pair) {
            if (trim((string) fgets($pair[0])) !== 'started') {
                throw new RuntimeException('Quota child not ready.');
            }
        }
        $deadline = microtime(true) + 30;
        do {
            $waits = (int) DB::selectOne('SELECT COUNT(DISTINCT r.THREAD_ID) AS n FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks r ON r.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID')->n;
            if ($waits >= 2) {
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Two separate quota lock waits were not observed.');
    });
    $outcomes = array_map(fn ($pair) => trim((string) fgets($pair[0])), $quotaPairs);
    sort($outcomes);
    foreach ($quotaPids as $pid) {
        pcntl_waitpid($pid, $status);
        if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('Quota process failed.');
        }
    }
    if ($outcomes !== ['200', '429'] || DB::table('company_idempotency_requests')->where('actor_id', 'quota-lab')->count() !== 1) {
        throw new RuntimeException('Concurrent issuance quota invariant failed.');
    }
    echo "PASS: two waiting issuers respect the shared administrator quota.\n";
} finally {
    foreach ($quotaPairs as $pair) {
        fclose($pair[0]);
    }
}
