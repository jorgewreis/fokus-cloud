<?php

namespace Tests\Feature;

use App\Services\CompanyIdempotencyKeys;
use App\Services\CompanyIdempotencyStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CompanyIdempotencyStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $migration = require database_path('migrations/2026_11_14_000001_create_company_idempotency_requests.php');
        $migration->up();
        Schema::create('synthetic_effects', function (Blueprint $table): void {
            $table->string('id')->primary();
        });
    }

    public function test_completed_request_replays_without_repeating_the_mutation(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', now()->getTimestamp(), 600);
        $payload = ['legal_name' => 'Synthetic'];
        self::assertNull($store->bind($token, 'admin-a', 'company.create', null, $payload, now()->getTimestamp(), fn () => true));
        $writer = function (): array {
            DB::table('synthetic_effects')->insert(['id' => 'EMP-synthetic']);

            return ['id' => 'EMP-synthetic', 'status' => 201];
        };
        self::assertSame(['id' => 'EMP-synthetic', 'status' => 201], $store->complete($token, 'admin-a', 'company.create', null, $payload, fn () => true, $writer));
        self::assertSame(['id' => 'EMP-synthetic', 'status' => 201], $store->bind($token, 'admin-a', 'company.create', null, $payload, now()->getTimestamp(), fn () => true));
        self::assertSame(['id' => 'EMP-synthetic', 'status' => 201], $store->complete($token, 'admin-a', 'company.create', null, $payload, fn () => true, $writer));
        self::assertSame(1, DB::table('synthetic_effects')->count());
        $row = DB::table('company_idempotency_requests')->first();
        self::assertNotSame($token, $row->token_hash);
        self::assertStringNotContainsString('Synthetic', json_encode((array) $row, JSON_THROW_ON_ERROR));
    }

    public function test_changed_payload_is_rejected_before_mutation(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', now()->getTimestamp(), 600);
        $store->bind($token, 'admin-a', 'company.create', null, ['name' => 'A'], now()->getTimestamp(), fn () => true);
        try {
            $store->bind($token, 'admin-a', 'company.create', null, ['name' => 'B'], now()->getTimestamp(), fn () => true);
            self::fail('Changed payload was accepted.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->getStatusCode());
        }
        self::assertSame(0, DB::table('synthetic_effects')->count());
    }

    public function test_mutation_and_completion_roll_back_together(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', now()->getTimestamp(), 600);
        $store->bind($token, 'admin-a', 'company.create', null, [], now()->getTimestamp(), fn () => true);
        try {
            $store->complete($token, 'admin-a', 'company.create', null, [], fn () => true, function (): array {
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
        $token = $store->issue('admin-a', 'company.create', now()->getTimestamp(), 600);
        self::assertSame(['state' => 'issued', 'revoked' => false], $store->status($token, 'admin-a', 'company.create', now()->getTimestamp(), fn () => true));
        $store->bind($token, 'admin-a', 'company.create', null, [], now()->getTimestamp(), fn () => true);
        self::assertSame(['state' => 'observed', 'revoked' => false], $store->status($token, 'admin-a', 'company.create', now()->getTimestamp(), fn () => true));
        $audit = function (): void {
            DB::table('synthetic_effects')->insert(['id' => 'audit']);
        };
        self::assertSame(['state' => 'observed', 'revoked' => true], $store->revoke($token, 'admin-a', 'company.create', now()->getTimestamp(), fn () => true, $audit));
        $store->revoke($token, 'admin-a', 'company.create', now()->getTimestamp(), fn () => true, $audit);
        self::assertSame(1, DB::table('synthetic_effects')->count());
        self::assertSame(['state' => 'observed', 'revoked' => true], $store->status($token, 'admin-a', 'company.create', now()->getTimestamp(), fn () => true));
        try {
            $store->complete($token, 'admin-a', 'company.create', null, [], fn () => true, fn () => ['id' => 'EMP-synthetic', 'status' => 201]);
            self::fail('Revoked token accepted.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->getStatusCode());
        }
    }

    public function test_fresh_authorization_is_required_even_for_replay(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', now()->getTimestamp(), 600);
        $store->bind($token, 'admin-a', 'company.create', null, [], now()->getTimestamp(), fn () => true);
        $store->complete($token, 'admin-a', 'company.create', null, [], fn () => true, fn () => ['id' => 'EMP-synthetic', 'status' => 201]);
        try {
            $store->bind($token, 'admin-a', 'company.create', null, [], now()->getTimestamp(), fn () => false);
            self::fail('Replay was allowed without permission.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
    }

    public function test_revocation_audit_failure_rolls_back_the_revocation(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', now()->getTimestamp(), 600);
        try {
            $store->revoke($token, 'admin-a', 'company.create', now()->getTimestamp(), fn () => true, function (): void {
                throw new RuntimeException('Synthetic audit failure');
            });
            self::fail('Audit failure was not propagated.');
        } catch (RuntimeException $e) {
            self::assertSame('Synthetic audit failure', $e->getMessage());
        }
        self::assertSame(['state' => 'issued', 'revoked' => false], $store->status($token, 'admin-a', 'company.create', now()->getTimestamp(), fn () => true));
    }

    public function test_lost_record_is_reported_absent_without_inferring_outcome(): void
    {
        $store = $this->store();
        $token = $store->issue('admin-a', 'company.create', now()->getTimestamp(), 600);
        DB::table('company_idempotency_requests')->delete();
        self::assertSame(['state' => 'absent', 'revoked' => null], $store->status($token, 'admin-a', 'company.create', now()->getTimestamp(), fn () => true));
        try {
            $store->bind($token, 'admin-a', 'company.create', null, [], now()->getTimestamp(), fn () => true);
            self::fail('A missing issuance record must not allow mutation.');
        } catch (HttpException $e) {
            self::assertSame(503, $e->getStatusCode());
        }
    }

    private function store(): CompanyIdempotencyStore
    {
        return new CompanyIdempotencyStore(new CompanyIdempotencyKeys(['synthetic' => base64_encode(str_repeat('x', 32))], 'synthetic', []));
    }
}
