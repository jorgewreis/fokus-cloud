<?php

namespace Tests\Isolated;

use App\Http\Middleware\RejectUnimplementedCompanyIdempotency;
use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Services\CompanyIdempotencyFactory;
use App\Services\PlatformSessionAuthority;
use App\Services\PrefixedUlid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use Throwable;

require_once __DIR__.'/CompanyIdempotencyMySqlLabGuard.php';

/** Run only with the guarded, empty MySQL API laboratory bootstrap. */
class CompanyIdempotencyApiConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runDatabaseMigrations();
        $this->seed();
        $this->withoutMiddleware(RejectUnimplementedCompanyIdempotency::class);
        config()->set('platform_session_authority.enabled', true);
        config()->set('session.driver', 'database');
        config()->set('session.connection', null);
        config()->set('session.table', 'sessions');
        config()->set('session.lifetime', 120);
        config(['company_idempotency' => [
            'enabled' => true,
            'keyring_json' => '{"v1":"'.base64_encode(str_repeat('x', 32)).'"}',
            'active_kid' => 'v1', 'revoked_kids_json' => '[]',
            'max_outstanding' => '5', 'max_issues_per_window' => '20',
            'issuance_window_seconds' => '600', 'token_lifetime_seconds' => '600',
        ]]);
    }

    /** Deliberately avoids DatabaseMigrations: these migrations are forward-only. */
    public function runDatabaseMigrations(): void
    {
        CompanyIdempotencyMySqlLabGuard::assertBeforeFresh();
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    public function test_two_independent_requests_replay_after_one_commits_during_preflight(): void
    {
        self::assertSame('mysql', DB::connection()->getDriverName());
        self::assertTrue(function_exists('pcntl_fork'));
        $admin = PlatformAdmin::create([
            'id' => PrefixedUlid::make('PAD'), 'name' => 'Synthetic Admin',
            'email' => 'concurrency-admin@example.test', 'password' => Hash::make('SyntheticPassword!2026'),
            'status' => 'ativo', 'platform_role_id' => PlatformRole::where('code', 'superadministrador')->value('id'),
            'email_verified_at' => now(),
        ]);
        $store = (new CompanyIdempotencyFactory)->build(config('company_idempotency'))['store'];
        $token = $store->issue($admin->id, 'company.create', 600);
        $payload = [
            'document_type' => 'cnpj', 'document_number' => '11.222.333/0001-81', 'legal_name' => 'Synthetic company',
            'name' => 'Synthetic Administrator', 'cpf' => '11144477735', 'email' => 'concurrency-created@example.test',
            'password' => 'SenhaSegura!2026', 'terms' => '1', 'privacy' => '1',
        ];
        DB::disconnect(); // No PDO is inherited across the fork.
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create request barrier.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 60);
            if (trim((string) fgets($pair[1])) !== 'begin') {
                exit(2);
            }
            try {
                Http::preventStrayRequests();
                Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
                $response = $this->actingAsWithAuthority($admin, str_repeat('c', 40))
                    ->withHeader('Idempotency-Key', $token)->postJson('/api/backoffice/companies', $payload);
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
            $child = null;
            Http::preventStrayRequests();
            Http::fake(['api.pwnedpasswords.com/*' => function () use ($pair, &$child) {
                fwrite($pair[0], "begin\n");
                $child = json_decode((string) fgets($pair[0]), true, 512, JSON_THROW_ON_ERROR);

                return Http::response('', 200);
            }]);
            $response = $this->actingAsWithAuthority($admin, str_repeat('p', 40))
                ->withHeader('Idempotency-Key', $token)->postJson('/api/backoffice/companies', $payload);
            $response->assertCreated();
            self::assertIsArray($child);
            self::assertSame(201, $child['status']);
            self::assertSame($child['id'], $response->json('company_id'));
            self::assertSame(1, DB::table('companies')->where('document_number', '11222333000181')->count());
            self::assertSame(1, DB::table('platform_audit_events')->where('action', 'backoffice.company_created')->where('entity_id', $child['id'])->count());
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
        } finally {
            fclose($pair[0]);
        }
    }

    /** Attach a durable, database-backed authority to this synthetic request process. */
    private function actingAsWithAuthority(PlatformAdmin $admin, string $sessionId): static
    {
        $session = app('session.store');
        $session->setId($sessionId);
        $session->start();
        DB::table('sessions')->updateOrInsert(
            ['id' => $sessionId],
            [
                'user_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit MySQL lab',
                'payload' => '',
                'last_activity' => now()->timestamp,
            ],
        );
        $request = Request::create('/api/backoffice/companies', 'POST');
        $request->setLaravelSession($session);
        app(PlatformSessionAuthority::class)->activate($request, $admin);
        // actingAs() may initialize the test session; it must observe the
        // deterministic ID already associated with the durable authority.
        $this->actingAs($admin, 'platform');
        $this->withCookie((string) config('session.cookie'), $sessionId)->withCredentials();

        return $this;
    }
}
