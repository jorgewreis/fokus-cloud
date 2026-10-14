<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LeadShellTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->user = User::create(['id' => PrefixedUlid::make('USR'), 'name' => 'Pessoa Lead', 'cpf' => '11144477735', 'email' => 'lead@example.test', 'password' => Hash::make('SenhaSegura!2026'), 'status' => 'ativa', 'email_verified_at' => now()]);
        $this->companyId = $this->company('Empresa Lead');
        $this->membership($this->companyId, $this->user->id);
        $this->leadSubscription($this->companyId, $this->user->id);
    }

    public function test_lead_shell_routes_require_active_user_verified_email_membership_and_subscription(): void
    {
        $session = ['active_company_id' => $this->companyId];
        $this->get('/portal/fokus-lead')->assertRedirect('/?acesso=cliente');
        $this->user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->user)->get('/portal/fokus-lead/perfil')->assertRedirect('/verificar-email?return_to=%2Fportal%2Ffokus-lead%2Fperfil');

        $this->user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($this->user)->withSession($session)->get('/portal/fokus-lead')->assertOk()->assertViewIs('portal.fokus-lead')->assertSee('data-initial-page="dashboard"', false);
        $this->actingAs($this->user)->withSession($session)->get('/portal/fokus-lead/perfil')->assertOk()->assertViewIs('portal.fokus-lead')->assertSee('data-initial-page="profile"', false);

        DB::table('subscriptions')->where('company_id', $this->companyId)->update(['status' => 'suspensa']);
        $this->actingAs($this->user)->withSession($session)->get('/portal/fokus-lead')->assertForbidden();
        $this->actingAs($this->user)->withSession($session)->getJson('/api/lead/shell-context')->assertForbidden();
    }

    public function test_lead_shell_context_filters_companies_to_active_lead_entitlements(): void
    {
        $other = $this->company('Sem Fokus Lead');
        $this->membership($other, $this->user->id);
        $this->actingAs($this->user)->withSession(['active_company_id' => $this->companyId])->getJson('/api/lead/shell-context')
            ->assertOk()->assertJsonPath('user.name', 'Pessoa Lead')->assertJsonPath('company.name', 'Empresa Lead')
            ->assertJsonPath('subscription.label', 'Fokus Lead')->assertJsonCount(1, 'companies')->assertJsonPath('companies.0.id', $this->companyId);
    }

    public function test_lead_shell_context_blocks_missing_company_context_and_unverified_user(): void
    {
        $this->getJson('/api/lead/shell-context')->assertUnauthorized();
        $this->actingAs($this->user)->getJson('/api/lead/shell-context')->assertStatus(409);
        $unlinked = $this->company('Empresa sem vínculo');
        $this->actingAs($this->user)->withSession(['active_company_id' => $unlinked])->getJson('/api/lead/shell-context')->assertForbidden();
        $this->user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->user)->withSession(['active_company_id' => $this->companyId])->getJson('/api/lead/shell-context')->assertForbidden();
        $this->user->forceFill(['email_verified_at' => now(), 'status' => 'desativada'])->save();
        $this->actingAs($this->user)->withSession(['active_company_id' => $this->companyId])->getJson('/api/lead/shell-context')->assertForbidden();
    }

    private function company(string $name): string
    {
        $id = PrefixedUlid::make('EMP');
        $document = match ($name) { 'Empresa Lead' => '11222333000181', 'Sem Fokus Lead' => '11222333000182', default => '11222333000183' };
        DB::table('companies')->insert(['id' => $id, 'document_type' => 'cnpj', 'document_number' => $document, 'legal_name' => $name, 'status' => 'ativa', 'version' => 1, 'created_by' => $this->user->id, 'updated_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    private function membership(string $companyId, string $userId): void
    {
        DB::table('company_memberships')->insert(['id' => PrefixedUlid::make('MEM'), 'company_id' => $companyId, 'user_id' => $userId, 'role_id' => DB::table('roles')->where('code', 'admin')->value('id'), 'status' => 'ativo', 'version' => 1, 'created_by' => $this->user->id, 'updated_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function leadSubscription(string $companyId, string $userId): void
    {
        $product = DB::table('products')->where('code', 'lead')->firstOrFail();
        DB::table('subscriptions')->insert(['id' => PrefixedUlid::make('ASS'), 'company_id' => $companyId, 'product_id' => $product->id, 'status' => 'ativa', 'open_company_product' => $companyId.'-'.$product->id, 'public_name' => 'Fokus Lead', 'commercial_snapshot' => json_encode(['plan_name' => '']), 'version' => 1, 'created_by' => $userId, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
    }
}
