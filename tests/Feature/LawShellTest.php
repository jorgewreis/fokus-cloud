<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LawShellTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->user = User::create([
            'id' => PrefixedUlid::make('USR'), 'name' => 'Érica Menezes', 'cpf' => '11144477735',
            'email' => 'erica@example.test', 'password' => Hash::make('SenhaSegura!2026'),
            'status' => 'ativa', 'email_verified_at' => now(),
        ]);
        $this->companyId = PrefixedUlid::make('COM');
        DB::table('companies')->insert([
            'id' => $this->companyId, 'document_type' => 'cpf', 'document_number' => '11144477735',
            'legal_name' => 'Érica Menezes Advocacia', 'status' => 'ativa', 'version' => 1,
            'created_by' => $this->user->id, 'updated_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $adminRoleId = DB::table('roles')->where('code', 'admin')->value('id');
        DB::table('company_memberships')->insert([
            'id' => PrefixedUlid::make('MEM'), 'company_id' => $this->companyId, 'user_id' => $this->user->id,
            'role_id' => $adminRoleId, 'status' => 'ativo', 'version' => 1,
            'created_by' => $this->user->id, 'updated_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_shell_route_requires_verified_user_and_active_company(): void
    {
        $this->get('/portal/fokus-law')->assertRedirect('/?acesso=cliente');

        $unverified = User::findOrFail($this->user->id);
        $unverified->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($unverified)->get('/portal/fokus-law')->assertRedirect('/verificar-email');

        $unverified->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($unverified)->get('/portal/fokus-law')->assertRedirect('/portal/empresas');
    }

    public function test_shell_view_is_served_only_after_context_checks(): void
    {
        $response = $this->actingAs($this->user)->withSession(['active_company_id' => $this->companyId])->get('/portal/fokus-law');

        $response->assertOk()->assertViewIs('portal.fokus-law');
        $this->assertStringContainsString('Fokus Law', $response->getContent());
        $this->get('/portal/fokus-law.html')->assertNotFound();
    }

    public function test_company_profile_page_and_api_require_company_admin(): void
    {
        $session = ['active_company_id' => $this->companyId];
        $this->actingAs($this->user)->withSession($session)
            ->get('/portal/fokus-law/empresa')->assertOk()->assertSee('data-initial-page="company"', false);
        $this->actingAs($this->user)->withSession($session)
            ->getJson('/api/law/company-profile')->assertOk()->assertJsonPath('company.legal_name', 'Érica Menezes Advocacia');

        $manager = User::create([
            'id' => PrefixedUlid::make('USR'), 'name' => 'Gestor', 'cpf' => '11144477736',
            'email' => 'gestor@example.test', 'password' => Hash::make('SenhaSegura!2026'),
            'status' => 'ativa', 'email_verified_at' => now(),
        ]);
        DB::table('company_memberships')->insert([
            'id' => PrefixedUlid::make('MEM'), 'company_id' => $this->companyId, 'user_id' => $manager->id,
            'role_id' => DB::table('roles')->where('code', 'gestor')->value('id'), 'status' => 'ativo', 'version' => 1,
            'created_by' => $this->user->id, 'updated_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($manager)->withSession($session)
            ->get('/portal/fokus-law/empresa')->assertForbidden();
        $this->actingAs($manager)->withSession($session)
            ->getJson('/api/law/company-profile')->assertForbidden();
        $this->actingAs($manager)->withSession($session)
            ->patchJson('/api/law/company-profile', ['version' => 1, 'legal_name' => 'Alteração indevida'])->assertForbidden();
    }

    public function test_company_admin_can_update_institutional_profile_and_audit_changes_with_version_check(): void
    {
        $session = ['active_company_id' => $this->companyId];
        $payload = [
            'version' => 1,
            'legal_name' => 'Érica Menezes Advocacia Atualizada',
            'display_name' => 'Escritório Menezes',
            'contact_email' => 'contato@example.test',
            'contact_phone' => '(71) 3333-2222',
            'website' => 'https://example.test',
            'address_postal_code' => '40000-000',
            'address_street' => 'Rua Exemplo',
            'address_number' => '10',
            'address_complement' => 'Sala 2',
            'address_district' => 'Centro',
            'address_city' => 'Salvador',
            'address_state' => 'BA',
        ];

        $this->actingAs($this->user)->withSession($session)
            ->patchJson('/api/law/company-profile', $payload)
            ->assertOk()->assertJsonPath('company.version', 2)->assertJsonPath('company.display_name', 'Escritório Menezes');
        $this->assertDatabaseHas('companies', [
            'id' => $this->companyId, 'legal_name' => $payload['legal_name'], 'display_name' => 'Escritório Menezes',
            'contact_email' => 'contato@example.test', 'address_city' => 'Salvador', 'address_state' => 'BA', 'version' => 2,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'company_id' => $this->companyId, 'entity_type' => 'company_profile', 'operation' => 'update', 'actor_user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user)->withSession($session)
            ->patchJson('/api/law/company-profile', ['version' => 1, 'legal_name' => 'Versão antiga'])
            ->assertConflict();
        $this->assertDatabaseHas('companies', ['id' => $this->companyId, 'legal_name' => $payload['legal_name'], 'version' => 2]);
    }

    public function test_profile_opens_inside_the_law_shell_and_legacy_route_redirects_there(): void
    {
        $response = $this->actingAs($this->user)->withSession(['active_company_id' => $this->companyId])
            ->get('/portal/fokus-law/perfil');

        $response->assertOk()->assertViewIs('portal.fokus-law')
            ->assertSee('data-initial-page="profile"', false)
            ->assertSee('law-topbar', false)
            ->assertSee('law-profile-template', false);

        $this->get('/portal/perfil')->assertRedirect('/portal/fokus-law/perfil');
    }

    public function test_shell_context_returns_empty_modules_without_active_law_entitlements(): void
    {
        $this->actingAs($this->user)->withSession(['active_company_id' => $this->companyId])
            ->getJson('/api/law/shell-context')
            ->assertOk()
            ->assertJsonPath('company.name', 'Érica Menezes Advocacia')
            ->assertJsonPath('permissions.manage_company_users', true)
            ->assertJsonPath('subscription', null)
            ->assertJsonCount(0, 'modules');
    }

    public function test_shell_context_lists_only_contracted_published_modules(): void
    {
        $product = DB::table('products')->whereIn('code', ['law', 'fokus-law'])->firstOrFail();
        $contacts = DB::table('modules')->where('product_id', $product->id)->where('module_code', 'contatos')->firstOrFail();
        $processes = DB::table('modules')->where('product_id', $product->id)->where('module_code', 'processos')->firstOrFail();
        DB::table('modules')->whereIn('id', [$contacts->id, $processes->id])->update(['status' => 'ativo', 'publication_state' => 'publicado']);

        $subscriptionId = PrefixedUlid::make('ASS');
        DB::table('subscriptions')->insert([
            'id' => $subscriptionId, 'company_id' => $this->companyId, 'product_id' => $product->id,
            'status' => 'ativa', 'open_company_product' => $this->companyId.'-'.$product->id,
            'public_name' => 'Érica Menezes', 'commercial_snapshot' => json_encode(['plan_name' => 'Essencial']),
            'version' => 1, 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([$contacts, $processes] as $module) {
            DB::table('subscription_items')->insert([
                'id' => PrefixedUlid::make('ITM'), 'company_id' => $this->companyId, 'subscription_id' => $subscriptionId,
                'module_id' => $module->id, 'name_snapshot' => $module->name, 'quantity' => 1,
                'unit_price_snapshot' => $module->monthly_price, 'version' => 1,
                'created_by' => $this->user->id, 'updated_by' => $this->user->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->actingAs($this->user)->withSession(['active_company_id' => $this->companyId])
            ->getJson('/api/law/shell-context')
            ->assertOk()
            ->assertJsonPath('company.name', 'Érica Menezes')
            ->assertJsonPath('subscription.plan_name', 'Essencial')
            ->assertJsonCount(2, 'modules');
    }
}
