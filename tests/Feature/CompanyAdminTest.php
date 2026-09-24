<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Models\User;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Illuminate\Support\Facades\Http;

class CompanyAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    public function test_company_query_is_paginated_and_exposes_document_and_email(): void
    {
        $admin = $this->platformAdmin();
        $this->companyFixture('Empresa Beta', '98765432000100', 'beta@example.test');

        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/companies?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.document_number', '98765432000100')
            ->assertJsonPath('data.0.admin_email', 'beta@example.test');
    }

    public function test_user_without_company_permission_cannot_query_companies(): void
    {
        $admin = $this->platformAdmin('administrador_comercial');
        DB::table('platform_role_permissions')->where('platform_role_id', $admin->platform_role_id)
            ->where('platform_permission_id', DB::table('platform_permissions')->where('code', 'platform.companies.view')->value('id'))->delete();

        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/companies')->assertForbidden();
    }

    public function test_superadmin_can_create_company_with_immediate_access_and_legal_acceptances(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/companies', [
            'document_type' => 'cnpj',
            'document_number' => '11.222.333/0001-81',
            'legal_name' => 'Empresa Criada no Backoffice',
            'name' => 'Administrador Criado',
            'cpf' => '11144477735',
            'email' => 'criado@example.test',
            'password' => 'SenhaSegura!2026',
            'terms' => '1',
            'privacy' => '1',
        ])->assertCreated();

        $this->assertDatabaseHas('companies', ['legal_name' => 'Empresa Criada no Backoffice', 'status' => 'ativa']);
        $this->assertDatabaseHas('users', ['email' => 'criado@example.test', 'status' => 'ativa']);
        $this->assertDatabaseCount('legal_acceptances', 2);
    }

    public function test_company_operations_are_listed_in_dashboard_recent_activity(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/companies', [
            'document_type' => 'cnpj',
            'document_number' => '11.222.333/0001-81',
            'legal_name' => 'Empresa Exibida na Atividade',
            'name' => 'Administrador da Atividade',
            'cpf' => '11144477735',
            'email' => 'atividade@example.test',
            'password' => 'SenhaSegura!2026',
            'terms' => '1',
            'privacy' => '1',
        ])->assertCreated();

        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/dashboard')
            ->assertOk()
            ->assertJsonPath('metrics.active_companies', 1)
            ->assertJsonPath('metrics.active_people', 1)
            ->assertJsonPath('recent_activity.0.kind', 'backoffice.company_created')
            ->assertJsonPath('recent_activity.0.area', 'Empresas')
            ->assertJsonPath('recent_activity.0.icon', 'companies')
            ->assertJsonPath('recent_activity.0.tone', 'creation')
            ->assertJsonPath('recent_activity.0.title', 'Criado em Empresas')
            ->assertJsonPath('recent_activity.0.description', 'Empresa: Empresa Exibida na Atividade');
    }

    public function test_superadmin_can_edit_deactivate_reactivate_and_remove_company_without_subscriptions(): void
    {
        $admin = $this->platformAdmin();
        $companyId = $this->companyFixture('Empresa Editável', '98765432000100', 'editavel@example.test');
        $version = DB::table('companies')->where('id', $companyId)->value('version');

        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/companies/{$companyId}", [
            'legal_name' => 'Empresa Editada', 'name' => 'Administrador Editado', 'cpf' => '11144477735', 'email' => 'editado@example.test', 'version' => $version,
        ])->assertOk();
        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/companies/{$companyId}/deactivate")->assertOk();
        $this->assertDatabaseHas('companies', ['id' => $companyId, 'legal_name' => 'Empresa Editada', 'status' => 'suspensa']);
        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/companies/{$companyId}/activate")->assertOk();
        $this->actingAs($admin, 'platform')->deleteJson("/api/backoffice/companies/{$companyId}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Somente empresas suspensas podem ser removidas.');
        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/companies/{$companyId}/deactivate")->assertOk();
        $this->actingAs($admin, 'platform')->deleteJson("/api/backoffice/companies/{$companyId}")->assertOk();
        $this->assertSoftDeleted('companies', ['id' => $companyId]);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.company_deleted', 'entity_id' => $companyId]);
    }

    public function test_company_with_subscription_cannot_be_suspended_or_removed(): void
    {
        $admin = $this->platformAdmin();
        $companyId = $this->companyFixture('Empresa Com Assinatura', '11222333000181', 'assinatura@example.test');
        $productId = DB::table('products')->value('id');
        DB::table('subscriptions')->insert([
            'id' => PrefixedUlid::make('SUB'), 'company_id' => $companyId, 'product_id' => $productId, 'status' => 'ativa', 'open_company_product' => $companyId.'-'.$productId,
            'version' => 1, 'billing_cycle' => 'monthly', 'created_by' => $admin->id, 'updated_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/companies/{$companyId}/deactivate")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Não é possível suspender uma empresa que possui assinaturas.');
        DB::table('companies')->where('id', $companyId)->update(['status' => 'suspensa']);
        $this->actingAs($admin, 'platform')->deleteJson("/api/backoffice/companies/{$companyId}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Não é possível remover uma empresa que possui assinaturas.');
        $this->assertDatabaseHas('companies', ['id' => $companyId, 'deleted_at' => null]);
    }

    public function test_commercial_admin_cannot_mutate_companies(): void
    {
        $admin = $this->platformAdmin('administrador_comercial');

        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/companies', [])->assertForbidden();
    }

    private function platformAdmin(string $role = 'superadministrador'): PlatformAdmin
    {
        return PlatformAdmin::create([
            'id' => PrefixedUlid::make('PAD'),
            'name' => 'Equipe Fokus',
            'email' => $role.'-'.PlatformAdmin::count().'@example.test',
            'password' => Hash::make('SenhaInterna!2026'),
            'status' => 'ativo',
            'platform_role_id' => PlatformRole::where('code', $role)->value('id'),
            'email_verified_at' => now(),
        ]);
    }

    private function companyFixture(string $name, string $document, string $email): string
    {
        $user = User::create([
            'id' => PrefixedUlid::make('USR'),
            'name' => 'Administrador Beta',
            'cpf' => '52998224725',
            'email' => $email,
            'password' => Hash::make('SenhaCliente!2026'),
            'status' => 'ativa',
            'email_verified_at' => now(),
        ]);
        $companyId = PrefixedUlid::make('COM');
        DB::table('companies')->insert([
            'id' => $companyId,
            'document_type' => 'cnpj',
            'document_number' => $document,
            'legal_name' => $name,
            'status' => 'ativa',
            'version' => 1,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('company_memberships')->insert([
            'id' => PrefixedUlid::make('MBS'),
            'company_id' => $companyId,
            'user_id' => $user->id,
            'role_id' => DB::table('roles')->where('code', 'admin')->value('id'),
            'status' => 'ativo',
            'active_admin_company_id' => $companyId,
            'version' => 1,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $companyId;
    }
}
