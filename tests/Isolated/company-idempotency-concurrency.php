<?php

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

use App\Services\CompanyIdempotencyKeys;
use App\Services\CompanyIdempotencyStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    || Schema::hasTable('company_idempotency_requests') || Schema::hasTable('synthetic_effects')) {
    throw new RuntimeException('Fresh, identified disposable database required.');
}
(require database_path('migrations/2026_11_14_000001_create_company_idempotency_requests.php'))->up();
Schema::create('synthetic_effects', function (Blueprint $table): void {
    $table->string('id')->primary();
});
$keys = new CompanyIdempotencyKeys(['lab' => base64_encode(str_repeat('x', 32))], 'lab', []);
$store = new CompanyIdempotencyStore($keys);
$token = $store->issue('admin-lab', 'company.create', now()->getTimestamp(), 600);
$payload = ['name' => 'synthetic'];
$store->bind($token, 'admin-lab', 'company.create', null, $payload, now()->getTimestamp(), fn () => true);
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
        $result = $store->complete($token, 'admin-lab', 'company.create', null, $payload, fn () => true, function (): array {
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
    $result = $store->complete($token, 'admin-lab', 'company.create', null, $payload, fn () => true, function () use ($sockets): array {
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
$store->bind($token2, 'admin-lab', 'company.create', null, $payload, now()->getTimestamp(), fn () => true);
[$first, $second] = compete(
    fn ($barrier) => $store->complete($token2, 'admin-lab', 'company.create', null, $payload, fn () => true, function () use ($barrier): array {
        DB::table('synthetic_effects')->insert(['id' => 'mutation-before-revocation']);
        $barrier();

        return ['id' => 'mutation-before-revocation', 'status' => 201];
    }),
    fn () => $store->revoke($token2, 'admin-lab', 'company.create', now()->getTimestamp(), fn () => true, fn () => DB::table('synthetic_effects')->insert(['id' => 'audit-after-mutation']))
);
if ($first !== ['id' => 'mutation-before-revocation', 'status' => 201] || $second !== ['state' => 'completed', 'revoked' => true]) {
    throw new RuntimeException('Mutation-before-revocation invariant failed.');
}

$token3 = $store->issue('admin-lab', 'company.create', now()->getTimestamp(), 600);
$store->bind($token3, 'admin-lab', 'company.create', null, $payload, now()->getTimestamp(), fn () => true);
[$first, $second] = compete(
    fn ($barrier) => $store->revoke($token3, 'admin-lab', 'company.create', now()->getTimestamp(), fn () => true, function () use ($barrier): void {
        DB::table('synthetic_effects')->insert(['id' => 'audit-before-mutation']);
        $barrier();
    }),
    function () use ($store, $token3, $payload): array {
        try {
            $store->complete($token3, 'admin-lab', 'company.create', null, $payload, fn () => true, function (): array {
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
