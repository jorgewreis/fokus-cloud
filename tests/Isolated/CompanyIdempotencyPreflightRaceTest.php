<?php

namespace Tests\Isolated;

use App\Http\Middleware\RejectUnimplementedCompanyIdempotency;
use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Services\CompanyIdempotencyFactory;
use App\Services\CompanyIdempotencyMutation;
use App\Services\PlatformSessionAuthority;
use App\Services\PlatformSessionBinding;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

require_once __DIR__.'/CompanyIdempotencyMySqlLabGuard.php';

/** Guarded MySQL lab: only its opt-in, UUID-verified bootstrap may run this. */
class CompanyIdempotencyPreflightRaceTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        CompanyIdempotencyMySqlLabGuard::assertBeforeFresh();
        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_failed_preflight_is_not_definitive_while_matching_request_can_commit(): void
    {
        self::assertSame('mysql', DB::connection()->getDriverName());
        self::assertTrue(function_exists('pcntl_fork'));
        $this->seed();
        $this->withoutMiddleware(RejectUnimplementedCompanyIdempotency::class);
        config(['company_idempotency' => [
            'enabled' => true, 'keyring_json' => '{"v1":"'.base64_encode(str_repeat('x', 32)).'"}',
            'active_kid' => 'v1', 'revoked_kids_json' => '[]', 'max_outstanding' => '5',
            'max_issues_per_window' => '20', 'issuance_window_seconds' => '600', 'token_lifetime_seconds' => '600',
        ]]);
        $admin = PlatformAdmin::create([
            'id' => PrefixedUlid::make('PAD'), 'name' => 'Synthetic Admin',
            'email' => 'preflight-admin@example.test', 'password' => Hash::make('SyntheticPassword!2026'),
            'status' => 'ativo', 'platform_role_id' => PlatformRole::where('code', 'superadministrador')->value('id'),
            'email_verified_at' => now(),
        ]);
        (new PlatformSessionAuthority)->registerAfterMfa($admin->id, str_repeat('a', 64), now()->addMinutes(10)->timestamp);
        $settings = (new CompanyIdempotencyFactory)->build(config('company_idempotency'), str_repeat('a', 64), session()->getId());
        $token = $settings['session_keys']->bindSession($settings['store']->issue($admin->id, 'company.create', 600), str_repeat('a', 64));
        $this->withSession([PlatformSessionBinding::SESSION_KEY => str_repeat('a', 64)]);
        $payload = [
            'document_type' => 'cnpj', 'document_number' => '11.222.333/0001-81', 'legal_name' => 'Synthetic company',
            'name' => 'Synthetic Administrator', 'cpf' => '11144477735', 'email' => 'preflight-created@example.test',
            'password' => 'SenhaSegura!2026', 'terms' => '1', 'privacy' => '1',
        ];
        DB::disconnect();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Barrier unavailable.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 60);
            if (trim((string) fgets($pair[1])) !== 'begin') {
                exit(2);
            }
            try {
                Http::preventStrayRequests();
                Http::fake(['api.pwnedpasswords.com/*' => function () use ($pair) {
                    fwrite($pair[1], "waiting\n");
                    if (trim((string) fgets($pair[1])) !== 'continue') {
                        throw new RuntimeException('Barrier closed.');
                    }

                    return Http::response('', 200);
                }]);
                $response = $this->actingAs($admin, 'platform')->withHeader('Idempotency-Key', $token)->postJson('/api/backoffice/companies', $payload);
                fwrite($pair[1], json_encode(['status' => $response->status(), 'id' => $response->json('company_id')], JSON_THROW_ON_ERROR)."\n");
                exit(0);
            } catch (Throwable) {
                fwrite($pair[1], "child-error\n");
                exit(3);
            }
        }
        fclose($pair[1]);
        stream_set_timeout($pair[0], 60);
        try {
            Http::preventStrayRequests();
            Http::fake(['api.pwnedpasswords.com/*' => function () use ($pair) {
                fwrite($pair[0], "begin\n");
                if (trim((string) fgets($pair[0])) !== 'waiting') {
                    throw new RuntimeException('Child did not reach preflight.');
                }

                return Http::response('', 500);
            }]);
            $first = $this->actingAs($admin, 'platform')->withHeader('Idempotency-Key', $token)->postJson('/api/backoffice/companies', $payload);
            fwrite($pair[0], "continue\n");
            $second = json_decode((string) fgets($pair[0]), true, 512, JSON_THROW_ON_ERROR);
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
            self::assertSame(503, $first->status());
            self::assertSame(201, $second['status']);
            self::assertSame(1, DB::table('companies')->where('document_number', '11222333000181')->count());
            self::assertSame(1, DB::table('platform_audit_events')->where('action', 'backoffice.company_created')->where('entity_id', $second['id'])->count());
        } finally {
            fclose($pair[0]);
        }
    }

    public function test_failed_writer_is_not_definitive_while_matching_request_waits_for_lock(): void
    {
        self::assertSame('mysql', DB::connection()->getDriverName());
        self::assertTrue(function_exists('pcntl_fork'));
        $this->seed();
        config(['company_idempotency' => [
            'enabled' => true, 'keyring_json' => '{"v1":"'.base64_encode(str_repeat('x', 32)).'"}',
            'active_kid' => 'v1', 'revoked_kids_json' => '[]', 'max_outstanding' => '5',
            'max_issues_per_window' => '20', 'issuance_window_seconds' => '600', 'token_lifetime_seconds' => '600',
        ]]);
        $admin = PlatformAdmin::create([
            'id' => PrefixedUlid::make('PAD'), 'name' => 'Synthetic Admin',
            'email' => 'writer-admin@example.test', 'password' => Hash::make('SyntheticPassword!2026'),
            'status' => 'ativo', 'platform_role_id' => PlatformRole::where('code', 'superadministrador')->value('id'),
            'email_verified_at' => now(),
        ]);
        (new PlatformSessionAuthority)->registerAfterMfa($admin->id, str_repeat('a', 64), now()->addMinutes(10)->timestamp);
        $store = (new CompanyIdempotencyFactory)->build(config('company_idempotency'), str_repeat('a', 64), session()->getId())['store'];
        $mutation = new CompanyIdempotencyMutation($store);
        $token = $store->issue($admin->id, 'company.create', 600);
        $payload = ['synthetic' => 'writer'];
        DB::disconnect();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Barrier unavailable.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 60);
            if (trim((string) fgets($pair[1])) !== 'begin') {
                exit(2);
            }
            fwrite($pair[1], "started\n");
            try {
                $result = $mutation->run($token, $admin->id, 'company.create', null, $payload, fn () => null, function (): array {
                    DB::table('companies')->insert([
                        'id' => 'EMP-synthetic-writer', 'document_type' => 'cnpj', 'document_number' => '11222333000181',
                        'legal_name' => 'Synthetic', 'status' => 'ativa', 'version' => 1,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);

                    return ['id' => 'EMP-synthetic-writer', 'status' => 201];
                });
                fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
                exit(0);
            } catch (Throwable) {
                fwrite($pair[1], "child-error\n");
                exit(3);
            }
        }
        fclose($pair[1]);
        stream_set_timeout($pair[0], 60);
        try {
            try {
                $result = $mutation->run($token, $admin->id, 'company.create', null, $payload, fn () => null, function () use ($pair): array {
                    fwrite($pair[0], "begin\n");
                    if (trim((string) fgets($pair[0])) !== 'started') {
                        throw new RuntimeException('Child did not start.');
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
                        throw new RuntimeException('Lock wait not observed.');
                    }
                    abort(409, 'Synthetic conflicting write.');
                });
                $firstStatus = $result['status'];
            } catch (HttpException $e) {
                $firstStatus = $e->getStatusCode();
            }
            $second = json_decode((string) fgets($pair[0]), true, 512, JSON_THROW_ON_ERROR);
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
            self::assertContains($firstStatus, [201, 503]);
            self::assertSame(['id' => 'EMP-synthetic-writer', 'status' => 201], $second);
            self::assertSame(1, DB::table('companies')->where('id', 'EMP-synthetic-writer')->count());
        } finally {
            fclose($pair[0]);
        }
    }
}
