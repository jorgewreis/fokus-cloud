<?php

namespace Tests\Feature;

use App\Http\Middleware\RejectCompanyIdempotencyEndpoints;
use App\Http\Middleware\RejectUnimplementedCompanyIdempotency;
use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Services\CompanyIdempotencyFactory;
use App\Services\PlatformSessionAuthority;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Isolated\CompanyIdempotencyMySqlLabGuard;
use Tests\TestCase;

/** Synthetic lab only; production route guard remains closed. */
class CompanyIdempotencyApiLabTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        require_once dirname(__DIR__).'/Isolated/CompanyIdempotencyMySqlLabGuard.php';
        if (config('database.default') === 'sqlite') {
            CompanyIdempotencyMySqlLabGuard::checkInMemorySqlite(config('app.env'), config('database.default'), config('database.connections.sqlite'));
        } else {
            CompanyIdempotencyMySqlLabGuard::assertBeforeFresh();
        }
        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        $this->withoutMiddleware([RejectUnimplementedCompanyIdempotency::class, RejectCompanyIdempotencyEndpoints::class]);
        config()->set('platform_session_authority.enabled', true);
        config()->set('session.driver', 'database');
        config()->set('session.connection', null);
        config()->set('session.table', 'sessions');
        config()->set('session.lifetime', 120);
        config(['company_idempotency' => [
            'enabled' => true,
            'keyring_json' => '{"v1":"'.base64_encode(str_repeat('x', 32)).'"}',
            'active_kid' => 'v1',
            'revoked_kids_json' => '[]',
            'max_outstanding' => '5',
            'max_issues_per_window' => '20',
            'issuance_window_seconds' => '600',
            'token_lifetime_seconds' => '600',
        ]]);
    }

    public function test_create_replay_is_one_company_one_audit_and_one_password_check(): void
    {
        $admin = $this->admin();
        $token = $this->issue($admin, 'company.create');
        $this->actingAsWithAuthority($admin)->withHeader('Idempotency-Key', $token);
        $first = $this->postJson('/api/backoffice/companies', $this->payload())->assertCreated();
        $companyId = $first->json('company_id');
        $this->postJson('/api/backoffice/companies', $this->payload())->assertCreated()->assertJsonPath('company_id', $companyId);
        self::assertSame(1, DB::table('companies')->where('id', $companyId)->count());
        self::assertSame(1, DB::table('platform_audit_events')->where('action', 'backoffice.company_created')->where('entity_id', $companyId)->count());
        Http::assertSentCount(1);
        $stored = (array) DB::table('company_idempotency_requests')->first();
        self::assertStringNotContainsString('SenhaSegura!2026', json_encode($stored, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($token, json_encode($stored, JSON_THROW_ON_ERROR));
    }

    public function test_changed_payload_or_actor_cannot_replay_a_create(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $token = $this->issue($admin, 'company.create');
        $this->actingAsWithAuthority($admin)->withHeader('Idempotency-Key', $token)->postJson('/api/backoffice/companies', $this->payload())->assertCreated();
        $changed = array_replace($this->payload(), ['name' => 'Outro nome']);
        $this->actingAs($admin, 'platform')->withHeader('Idempotency-Key', $token)->postJson('/api/backoffice/companies', $changed)->assertStatus(409);
        $this->actingAsWithAuthority($other)->withHeader('Idempotency-Key', $token)->postJson('/api/backoffice/companies', $this->payload())->assertStatus(503)->assertDontSee($token);
        self::assertSame(1, DB::table('companies')->where('document_number', '11222333000181')->count());
    }

    public function test_update_replay_precedes_optimistic_version_check(): void
    {
        $admin = $this->admin();
        $initial = $this->actingAsWithAuthority($admin)->postJson('/api/backoffice/companies', $this->payload())->assertCreated();
        $companyId = $initial->json('company_id');
        $token = $this->issue($admin, 'company.update');
        $payload = ['legal_name' => 'Synthetic updated', 'name' => 'Synthetic Administrator', 'cpf' => '11144477735', 'email' => 'created@example.test', 'version' => 1];
        $this->actingAsWithAuthority($admin)->withHeader('Idempotency-Key', $token)->patchJson('/api/backoffice/companies/'.$companyId, $payload)->assertOk();
        $this->patchJson('/api/backoffice/companies/'.$companyId, $payload)->assertOk();
        self::assertSame(2, (int) DB::table('companies')->where('id', $companyId)->value('version'));
        self::assertSame(1, DB::table('platform_audit_events')->where('action', 'backoffice.company_updated')->where('entity_id', $companyId)->count());
    }

    public function test_disabled_or_malformed_config_never_mutates_when_guard_is_bypassed_in_lab(): void
    {
        $admin = $this->admin();
        $token = $this->issue($admin, 'company.create');
        config(['company_idempotency.enabled' => false]);
        $this->actingAsWithAuthority($admin)->withHeader('Idempotency-Key', $token)->postJson('/api/backoffice/companies', $this->payload())->assertStatus(503);
        self::assertSame(0, DB::table('companies')->where('document_number', '11222333000181')->count());
    }

    public function test_permission_removed_during_password_preflight_prevents_the_mutation(): void
    {
        $admin = $this->admin();
        $token = $this->issue($admin, 'company.create');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => function () use ($admin) {
            $permissionId = DB::table('platform_permissions')->where('code', 'platform.companies.manage')->value('id');
            DB::table('platform_role_permissions')->where('platform_role_id', $admin->platform_role_id)->where('platform_permission_id', $permissionId)->delete();

            return Http::response('', 200);
        }]);
        $this->actingAsWithAuthority($admin)->withHeader('Idempotency-Key', $token)
            ->postJson('/api/backoffice/companies', $this->payload())->assertForbidden();
        self::assertSame(0, DB::table('companies')->where('document_number', '11222333000181')->count());
        self::assertSame('bound', DB::table('company_idempotency_requests')->value('state'));
    }

    public function test_issue_status_and_revoke_are_actor_bound_and_do_not_expose_key_in_audit(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $this->actingAsWithAuthority($admin);
        $issued = $this->postJson('/api/backoffice/company-idempotency/issue', ['operation' => 'company.create'])->assertCreated();
        $token = $issued->headers->get('Idempotency-Key');
        self::assertIsString($token);
        self::assertNotSame('', $token);
        self::assertStringContainsString('no-store', (string) $issued->headers->get('Cache-Control'));
        $this->withHeader('Idempotency-Key', $token)->postJson('/api/backoffice/company-idempotency/status', ['operation' => 'company.create'])
            ->assertOk()->assertJsonPath('state', 'issued')->assertJsonPath('revoked', false);
        $this->postJson('/api/backoffice/company-idempotency/revoke', ['operation' => 'company.create'])
            ->assertOk()->assertJsonPath('state', 'issued')->assertJsonPath('revoked', true);
        $this->postJson('/api/backoffice/company-idempotency/revoke', ['operation' => 'company.create'])->assertOk();
        $this->postJson('/api/backoffice/company-idempotency/status', ['operation' => 'company.create'])
            ->assertOk()->assertJsonPath('revoked', true);
        $events = DB::table('platform_audit_events')->where('action', 'backoffice.company_idempotency_revoked')->get();
        self::assertCount(1, $events);
        self::assertStringNotContainsString($token, json_encode($events, JSON_THROW_ON_ERROR));
        $this->actingAsWithAuthority($other)->postJson('/api/backoffice/company-idempotency/status', ['operation' => 'company.create'])
            ->assertStatus(422)->assertDontSee($token);
    }

    private function issue(PlatformAdmin $admin, string $operation): string
    {
        $settings = (new CompanyIdempotencyFactory)->build(config('company_idempotency'));

        return $settings['store']->issue($admin->id, $operation, $settings['lifetime_seconds']);
    }

    private function actingAsWithAuthority(PlatformAdmin $admin): static
    {
        $this->actingAs($admin, 'platform');
        $session = app('session.store');
        $session->start();
        DB::table('sessions')->updateOrInsert(
            ['id' => $session->getId()],
            ['user_id' => null, 'ip_address' => '127.0.0.1', 'user_agent' => 'PHPUnit', 'payload' => '', 'last_activity' => now()->timestamp],
        );
        $request = Request::create('/api/backoffice/test', 'POST');
        $request->setLaravelSession($session);
        app(PlatformSessionAuthority::class)->activate($request, $admin);
        $this->withCookie((string) config('session.cookie'), $session->getId())->withCredentials();

        return $this;
    }

    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::create([
            'id' => PrefixedUlid::make('PAD'), 'name' => 'Synthetic Admin',
            'email' => 'synthetic-'.PlatformAdmin::count().'@example.test',
            'password' => Hash::make('SyntheticPassword!2026'), 'status' => 'ativo',
            'platform_role_id' => PlatformRole::where('code', 'superadministrador')->value('id'),
            'email_verified_at' => now(),
        ]);
    }

    private function payload(): array
    {
        return [
            'document_type' => 'cnpj', 'document_number' => '11.222.333/0001-81', 'legal_name' => 'Synthetic company',
            'name' => 'Synthetic Administrator', 'cpf' => '11144477735', 'email' => 'created@example.test',
            'password' => 'SenhaSegura!2026', 'terms' => '1', 'privacy' => '1',
        ];
    }
}
