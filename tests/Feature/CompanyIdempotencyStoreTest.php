<?php

namespace Tests\Feature;

use App\Services\CompanyIdempotencyAuthorization;
use App\Services\CompanyIdempotencyKeys;
use App\Services\CompanyIdempotencyMutation;
use App\Services\CompanyIdempotencyStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Isolated\CompanyIdempotencyMySqlLabGuard;
use Tests\TestCase;

class CompanyIdempotencyStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__).'/Isolated/CompanyIdempotencyMySqlLabGuard.php';
        CompanyIdempotencyMySqlLabGuard::checkInMemorySqlite(config('app.env'), config('database.default'), config('database.connections.sqlite'));
        Schema::create('platform_admins', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('status');
            $table->string('platform_role_id')->nullable();
            $table->timestamp('manual_blocked_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('locked_until')->nullable();
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
        DB::table('platform_admins')->insert(['id' => 'admin-a', 'status' => 'ativo', 'platform_role_id' => 'role-a']);
        DB::table('platform_permissions')->insert(['id' => 'perm-a', 'code' => 'platform.companies.manage']);
        DB::table('platform_role_permissions')->insert(['platform_role_id' => 'role-a', 'platform_permission_id' => 'perm-a']);
        $migration = require database_path('migrations/2026_11_14_000001_create_company_idempotency_requests.php');
        $migration->up();
        Schema::create('synthetic_effects', function (Blueprint $table): void {
            $table->string('id')->primary();
        });
    }

    public function test_completed_request_replays_without_repeating_the_mutation(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', 600);
        $payload = ['legal_name' => 'Synthetic'];
        self::assertNull($store->bind($token, 'admin-a', 'company.create', null, $payload, now()->getTimestamp()));
        $writer = function (): array {
            DB::table('synthetic_effects')->insert(['id' => 'EMP-synthetic']);

            return ['id' => 'EMP-synthetic', 'status' => 201];
        };
        self::assertSame(['id' => 'EMP-synthetic', 'status' => 201], $store->complete($token, 'admin-a', 'company.create', null, $payload, $writer));
        self::assertSame(['id' => 'EMP-synthetic', 'status' => 201], $store->bind($token, 'admin-a', 'company.create', null, $payload, now()->getTimestamp()));
        self::assertSame(['id' => 'EMP-synthetic', 'status' => 201], $store->complete($token, 'admin-a', 'company.create', null, $payload, $writer));
        self::assertSame(1, DB::table('synthetic_effects')->count());
        $row = DB::table('company_idempotency_requests')->first();
        self::assertNotSame($token, $row->token_hash);
        self::assertStringNotContainsString('Synthetic', json_encode((array) $row, JSON_THROW_ON_ERROR));
    }

    public function test_changed_payload_is_rejected_before_mutation(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', 600);
        $store->bind($token, 'admin-a', 'company.create', null, ['name' => 'A'], now()->getTimestamp());
        try {
            $store->bind($token, 'admin-a', 'company.create', null, ['name' => 'B'], now()->getTimestamp());
            self::fail('Changed payload was accepted.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->getStatusCode());
        }
        self::assertSame(0, DB::table('synthetic_effects')->count());
    }

    public function test_mutation_and_completion_roll_back_together(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', 600);
        $store->bind($token, 'admin-a', 'company.create', null, [], now()->getTimestamp());
        try {
            $store->complete($token, 'admin-a', 'company.create', null, [], function (): array {
                DB::table('synthetic_effects')->insert(['id' => 'EMP-synthetic']);
                throw new RuntimeException('Synthetic failure');
            });
            self::fail('Failure was not propagated.');
        } catch (RuntimeException $e) {
            self::assertSame('Synthetic failure', $e->getMessage());
        }
        self::assertSame(0, DB::table('synthetic_effects')->count());
        self::assertSame('bound', DB::table('company_idempotency_requests')->value('state'));
    }

    public function test_revocation_and_status_are_serialized_and_audited_once(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', 600);
        self::assertSame(['state' => 'issued', 'revoked' => false], $store->status($token, 'admin-a', 'company.create', now()->getTimestamp()));
        $store->bind($token, 'admin-a', 'company.create', null, [], now()->getTimestamp());
        self::assertSame(['state' => 'observed', 'revoked' => false], $store->status($token, 'admin-a', 'company.create', now()->getTimestamp()));
        $audit = function (): void {
            DB::table('synthetic_effects')->insert(['id' => 'audit']);
        };
        self::assertSame(['state' => 'observed', 'revoked' => true], $store->revoke($token, 'admin-a', 'company.create', now()->getTimestamp(), $audit));
        $store->revoke($token, 'admin-a', 'company.create', now()->getTimestamp(), $audit);
        self::assertSame(1, DB::table('synthetic_effects')->count());
        self::assertSame(['state' => 'observed', 'revoked' => true], $store->status($token, 'admin-a', 'company.create', now()->getTimestamp()));
        try {
            $store->complete($token, 'admin-a', 'company.create', null, [], fn () => ['id' => 'EMP-synthetic', 'status' => 201]);
            self::fail('Revoked token accepted.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->getStatusCode());
        }
    }

    public function test_fresh_authorization_is_required_even_for_replay(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', 600);
        $store->bind($token, 'admin-a', 'company.create', null, [], now()->getTimestamp());
        $store->complete($token, 'admin-a', 'company.create', null, [], fn () => ['id' => 'EMP-synthetic', 'status' => 201]);
        DB::table('platform_role_permissions')->delete();
        try {
            $store->bind($token, 'admin-a', 'company.create', null, [], now()->getTimestamp());
            self::fail('Replay was allowed without permission.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
    }

    public function test_revocation_audit_failure_rolls_back_the_revocation(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', 600);
        try {
            $store->revoke($token, 'admin-a', 'company.create', now()->getTimestamp(), function (): void {
                throw new RuntimeException('Synthetic audit failure');
            });
            self::fail('Audit failure was not propagated.');
        } catch (RuntimeException $e) {
            self::assertSame('Synthetic audit failure', $e->getMessage());
        }
        self::assertSame(['state' => 'issued', 'revoked' => false], $store->status($token, 'admin-a', 'company.create', now()->getTimestamp()));
    }

    public function test_lost_record_is_reported_absent_without_inferring_outcome(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', 600);
        DB::table('company_idempotency_requests')->delete();
        self::assertSame(['state' => 'absent', 'revoked' => null], $store->status($token, 'admin-a', 'company.create', now()->getTimestamp()));
        try {
            $store->bind($token, 'admin-a', 'company.create', null, [], now()->getTimestamp());
            self::fail('A missing issuance record must not allow mutation.');
        } catch (HttpException $e) {
            self::assertSame(503, $e->getStatusCode());
        }
    }

    public function test_issue_rechecks_permission_and_enforces_outstanding_quota(): void
    {
        $keys = new CompanyIdempotencyKeys(['synthetic' => base64_encode(str_repeat('x', 32))], 'synthetic', []);
        $store = new CompanyIdempotencyStore($keys, new CompanyIdempotencyAuthorization, 1, 50, 3600);
        $store->issue('admin-a', 'company.create', 600);
        try {
            $store->issue('admin-a', 'company.create', 600);
            self::fail('Quota must reject a second outstanding issuance.');
        } catch (HttpException $e) {
            self::assertSame(429, $e->getStatusCode());
        }
        DB::table('platform_role_permissions')->delete();
        try {
            $store->issue('admin-a', 'company.create', 600);
            self::fail('Issue must recheck permission.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
        self::assertSame(1, DB::table('company_idempotency_requests')->count());
    }

    public function test_window_counts_completed_revoked_and_expired_issuances_without_deleting_them(): void
    {
        $start = Carbon::parse('2026-01-01 00:00:00', 'UTC');
        Carbon::setTestNow($start);
        try {
            $keys = new CompanyIdempotencyKeys(['synthetic' => base64_encode(str_repeat('x', 32))], 'synthetic', []);
            $store = new CompanyIdempotencyStore($keys, new CompanyIdempotencyAuthorization, 5, 3, 60);
            $store->issue('admin-a', 'company.create', 1);
            $completed = $store->issue('admin-a', 'company.create', 600);
            $store->bind($completed, 'admin-a', 'company.create', null, [], now()->getTimestamp());
            $store->complete($completed, 'admin-a', 'company.create', null, [], fn () => ['id' => 'EMP-synthetic', 'status' => 201]);
            $revoked = $store->issue('admin-a', 'company.create', 600);
            $store->revoke($revoked, 'admin-a', 'company.create', now()->getTimestamp(), fn () => null);
            Carbon::setTestNow($start->copy()->addSeconds(2));
            try {
                $store->issue('admin-a', 'company.create', 600);
                self::fail('Past completions, revocations and expiry must consume the window.');
            } catch (HttpException $e) {
                self::assertSame(429, $e->getStatusCode());
            }
            Carbon::setTestNow($start->copy()->addSeconds(61));
            $store->issue('admin-a', 'company.create', 600);
            self::assertSame(4, DB::table('company_idempotency_requests')->count());
            self::assertSame(1, DB::table('company_idempotency_requests')->where('state', 'completed')->count());
            self::assertSame(1, DB::table('company_idempotency_requests')->where('revoked', true)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_issuance_window_is_scoped_to_the_authenticated_actor(): void
    {
        DB::table('platform_admins')->insert(['id' => 'admin-b', 'status' => 'ativo', 'platform_role_id' => 'role-a']);
        $keys = new CompanyIdempotencyKeys(['synthetic' => base64_encode(str_repeat('x', 32))], 'synthetic', []);
        $store = new CompanyIdempotencyStore($keys, new CompanyIdempotencyAuthorization, 5, 1, 60);
        $store->issue('admin-a', 'company.create', 600);
        $store->issue('admin-b', 'company.create', 600);
        self::assertSame(2, DB::table('company_idempotency_requests')->count());
    }

    public function test_migration_down_fails_closed_even_when_table_is_empty(): void
    {
        $migration = require database_path('migrations/2026_11_14_000001_create_company_idempotency_requests.php');
        try {
            $migration->down();
            self::fail('Automatic rollback must not drop the table.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Automatic rollback', $e->getMessage());
        }
        self::assertTrue(Schema::hasTable('company_idempotency_requests'));
    }

    public function test_update_rejects_a_result_for_a_different_company_and_rolls_back(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.update', 600);
        $store->bind($token, 'admin-a', 'company.update', 'EMP-target', ['version' => 1], now()->getTimestamp());
        try {
            $store->complete($token, 'admin-a', 'company.update', 'EMP-target', ['version' => 1], function (): array {
                DB::table('synthetic_effects')->insert(['id' => 'wrong-target']);

                return ['id' => 'EMP-other', 'status' => 200];
            });
            self::fail('A result belonging to another target must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, DB::table('synthetic_effects')->count());
            self::assertSame('bound', DB::table('company_idempotency_requests')->value('state'));
        }
    }

    public function test_completed_record_with_inconsistent_status_is_not_replayed(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', 600);
        $store->bind($token, 'admin-a', 'company.create', null, [], now()->getTimestamp());
        $store->complete($token, 'admin-a', 'company.create', null, [], fn () => ['id' => 'EMP-synthetic', 'status' => 201]);
        DB::table('company_idempotency_requests')->update(['result_status' => 200]);
        try {
            $store->bind($token, 'admin-a', 'company.create', null, [], now()->getTimestamp());
            self::fail('Inconsistent success must not be replayed.');
        } catch (HttpException $e) {
            self::assertSame(503, $e->getStatusCode());
        }
    }

    public function test_completed_replay_does_not_repeat_preflight_or_writer(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', 600);
        $store->bind($token, 'admin-a', 'company.create', null, [], now()->getTimestamp());
        $store->complete($token, 'admin-a', 'company.create', null, [], fn () => ['id' => 'EMP-synthetic', 'status' => 201]);
        $never = fn () => throw new RuntimeException('Unexpected repeated work');
        $result = (new CompanyIdempotencyMutation($store))->run($token, 'admin-a', 'company.create', null, [], $never, $never);
        self::assertSame(['id' => 'EMP-synthetic', 'status' => 201], $result);
    }

    public function test_preflight_failure_rechecks_completion_from_another_request(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', 600);
        $preflight = function () use ($store, $token): void {
            $store->complete($token, 'admin-a', 'company.create', null, [], function (): array {
                DB::table('synthetic_effects')->insert(['id' => 'EMP-synthetic']);

                return ['id' => 'EMP-synthetic', 'status' => 201];
            });
            throw new RuntimeException('Synthetic preflight failure');
        };
        $writer = fn () => throw new RuntimeException('Writer must not repeat');
        $result = (new CompanyIdempotencyMutation($store))->run($token, 'admin-a', 'company.create', null, [], $preflight, $writer);
        self::assertSame(['id' => 'EMP-synthetic', 'status' => 201], $result);
        self::assertSame(1, DB::table('synthetic_effects')->count());
    }

    public function test_preflight_failure_without_completion_never_claims_success(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', 600);
        try {
            (new CompanyIdempotencyMutation($store))->run($token, 'admin-a', 'company.create', null, [], fn () => throw new RuntimeException('Synthetic failure'), fn () => ['id' => 'EMP-synthetic', 'status' => 201]);
            self::fail('Preflight failure must not be treated as success.');
        } catch (HttpException $e) {
            self::assertSame(503, $e->getStatusCode());
            self::assertStringContainsString('confirmar', $e->getMessage());
        }
        self::assertSame('bound', DB::table('company_idempotency_requests')->value('state'));
        self::assertSame(0, DB::table('synthetic_effects')->count());
    }

    public function test_writer_rejection_after_binding_is_not_reported_as_definitive(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', 600);
        try {
            (new CompanyIdempotencyMutation($store))->run($token, 'admin-a', 'company.create', null, [], fn () => null, function (): array {
                DB::table('synthetic_effects')->insert(['id' => 'rolled-back']);
                abort(409, 'Synthetic duplicate');
            });
            self::fail('A writer rejection must not be treated as a definitive result.');
        } catch (HttpException $e) {
            self::assertSame(503, $e->getStatusCode());
            self::assertStringContainsString('confirmar', $e->getMessage());
        }
        self::assertSame(0, DB::table('synthetic_effects')->count());
        self::assertSame('bound', DB::table('company_idempotency_requests')->value('state'));
    }

    private function store(): CompanyIdempotencyStore
    {
        return new CompanyIdempotencyStore(new CompanyIdempotencyKeys(['synthetic' => base64_encode(str_repeat('x', 32))], 'synthetic', []), new CompanyIdempotencyAuthorization, 5, 50, 3600);
    }
}
