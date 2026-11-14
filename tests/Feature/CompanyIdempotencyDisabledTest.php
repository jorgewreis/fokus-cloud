<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CompanyIdempotencyDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_keyed_company_writes_fail_closed_without_echoing_the_key_or_writing(): void
    {
        $admin = $this->admin();
        $beforeCompanies = DB::table('companies')->count();
        $beforeUsers = DB::table('users')->count();
        foreach (['synthetic-secret-key', '', '0'] as $key) {
            $this->actingAs($admin, 'platform')->withHeader('Idempotency-Key', $key)
                ->postJson('/api/backoffice/companies', [])->assertStatus(503)->assertDontSee('synthetic-secret-key');
            $this->actingAs($admin, 'platform')->withHeader('Idempotency-Key', $key)
                ->patchJson('/api/backoffice/companies/EMP-synthetic', [])->assertStatus(503)->assertDontSee('synthetic-secret-key');
        }
        self::assertSame($beforeCompanies, DB::table('companies')->count());
        self::assertSame($beforeUsers, DB::table('users')->count());
    }

    public function test_legacy_requests_without_key_keep_validation_contract(): void
    {
        $this->actingAs($this->admin(), 'platform')->postJson('/api/backoffice/companies', [])->assertUnprocessable();
        $this->actingAs($this->admin(), 'platform')->patchJson('/api/backoffice/companies/EMP-synthetic', [])->assertUnprocessable();
    }

    public function test_authentication_and_permission_are_checked_before_disabled_guard(): void
    {
        $this->withHeader('Idempotency-Key', 'synthetic')->postJson('/api/backoffice/companies', [])->assertUnauthorized();
        $admin = $this->admin();
        $permissionId = DB::table('platform_permissions')->where('code', 'platform.companies.manage')->value('id');
        DB::table('platform_role_permissions')->where('platform_role_id', $admin->platform_role_id)->where('platform_permission_id', $permissionId)->delete();
        $this->actingAs($admin, 'platform')->withHeader('Idempotency-Key', 'synthetic')
            ->postJson('/api/backoffice/companies', [])->assertForbidden();
    }

    public function test_management_endpoints_remain_closed_even_if_configuration_is_enabled(): void
    {
        config(['company_idempotency.enabled' => true]);
        $this->actingAs($this->admin(), 'platform');
        foreach (['issue', 'status', 'revoke'] as $action) {
            $this->postJson('/api/backoffice/company-idempotency/'.$action, ['operation' => 'company.create'])->assertStatus(503);
        }
        self::assertSame(0, DB::table('company_idempotency_requests')->count());
    }

    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::create([
            'id' => PrefixedUlid::make('PAD'),
            'name' => 'Synthetic Admin',
            'email' => 'synthetic-'.PlatformAdmin::count().'@example.test',
            'password' => Hash::make('SyntheticPassword!2026'),
            'status' => 'ativo',
            'platform_role_id' => PlatformRole::where('code', 'superadministrador')->value('id'),
            'email_verified_at' => now(),
        ]);
    }
}
