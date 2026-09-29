<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LawContactsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $companyId;
    private string $sessionCompanyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::create([
            'id' => PrefixedUlid::make('USR'), 'name' => 'Admin contatos', 'cpf' => '11144477735',
            'email' => 'contacts-admin@example.test', 'password' => Hash::make('SenhaSegura!2026'),
            'status' => 'ativa', 'email_verified_at' => now(),
        ]);
        $this->companyId = PrefixedUlid::make('COM');
        DB::table('companies')->insert([
            'id' => $this->companyId, 'document_type' => 'cpf', 'document_number' => '11144477735',
            'legal_name' => 'Empresa Contatos', 'display_name' => 'Empresa Contatos', 'status' => 'ativa', 'version' => 1,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $membershipId = PrefixedUlid::make('MEM');
        DB::table('company_memberships')->insert([
            'id' => $membershipId, 'company_id' => $this->companyId, 'user_id' => $this->admin->id,
            'role_id' => DB::table('roles')->where('code', 'admin')->value('id'), 'status' => 'ativo', 'version' => 1,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = DB::table('products')->where('code', 'law')->firstOrFail();
        $module = DB::table('modules')->where('product_id', $product->id)->where('module_code', 'contatos')->firstOrFail();
        $subscriptionId = PrefixedUlid::make('ASS');
        DB::table('subscriptions')->insert([
            'id' => $subscriptionId, 'company_id' => $this->companyId, 'product_id' => $product->id, 'status' => 'ativa',
            'open_company_product' => $this->companyId.'-'.$product->id, 'version' => 1, 'billing_cycle' => 'monthly',
            'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth(),
            'commercial_snapshot' => json_encode(['items' => [['module_id' => $module->id, 'conditions' => ['personalizations' => [['type_code' => 'contatos_cadastrados', 'value' => 3]]]]]]),
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscription_items')->insert([
            'id' => PrefixedUlid::make('ITM'), 'company_id' => $this->companyId, 'subscription_id' => $subscriptionId,
            'module_id' => $module->id, 'name_snapshot' => $module->name, 'quantity' => 1, 'unit_price_snapshot' => $module->monthly_price,
            'conditions_snapshot' => json_encode([]), 'version' => 1, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->sessionCompanyId = $this->companyId;
    }

    public function test_authenticated_user_can_look_up_a_brazilian_address_by_cep(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response([
            'cep' => '01001-000', 'logradouro' => 'Praça da Sé', 'complemento' => 'lado ímpar',
            'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP',
        ])]);

        $this->actingAs($this->admin)->withSession(['active_company_id' => $this->sessionCompanyId])
            ->getJson('/api/law/addresses/cep/01001000')
            ->assertOk()
            ->assertExactJson([
                'cep' => '01001-000', 'logradouro' => 'Praça da Sé', 'complemento' => 'lado ímpar',
                'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP',
            ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://viacep.com.br/ws/01001000/json/');
    }

    public function test_creates_pj_departments_as_additional_capacity_and_normalizes_names_and_tags(): void
    {
        $this->actingAs($this->admin)->withSession(['active_company_id' => $this->sessionCompanyId])
            ->postJson('/api/law/contacts', [
                'legal_nature' => 'pj', 'display_name' => 'SECRETARIA DE JUSTIÇA', 'legal_name' => 'SECRETARIA DE JUSTIÇA DO ESTADO',
                'classifications' => ['public_body', 'court_unit'], 'tags' => [' Setor público ', 'SETOR   PÚBLICO'],
                'departments' => [['name' => 'Contabilidade', 'channels' => [['type' => 'email', 'value' => 'contabilidade@example.test']]]],
            ])->assertCreated()->assertJsonPath('contact.display_name', 'Secretaria de Justiça');

        $this->assertDatabaseCount('law_contacts', 1);
        $this->assertDatabaseCount('law_contact_departments', 1);
        $this->assertDatabaseCount('law_contact_tags', 1);
        $this->assertDatabaseCount('law_contact_tag_assignments', 1);
        $this->assertSame(2, app(\App\Services\LawUsageMeter::class)->countContacts($this->companyId));
    }

    public function test_blocks_invalid_or_duplicate_cpf_and_capacity_overflow(): void
    {
        $session = ['active_company_id' => $this->sessionCompanyId];
        $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pf', 'display_name' => 'Maria de Souza', 'documents' => [['type' => 'cpf', 'number' => '11111111111']],
        ])->assertUnprocessable();

        $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pf', 'display_name' => 'Maria de Souza', 'documents' => [['type' => 'cpf', 'number' => '529.982.247-25']],
        ])->assertCreated();
        $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pf', 'display_name' => 'Maria Souza', 'documents' => [['type' => 'cpf', 'number' => '52998224725']],
        ])->assertStatus(409);

        $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pj', 'display_name' => 'Empresa A', 'departments' => [['name' => 'Contabilidade'], ['name' => 'Secretaria']],
        ])->assertUnprocessable();
    }

    public function test_contacts_api_is_unavailable_without_the_signed_module(): void
    {
        DB::table('subscriptions')->where('company_id', $this->companyId)->update(['status' => 'cancelada']);
        $this->actingAs($this->admin)->withSession(['active_company_id' => $this->sessionCompanyId])->getJson('/api/law/contacts')->assertForbidden();
    }

    public function test_company_can_share_selected_classifications_with_an_active_subscribed_company_and_revoke_access(): void
    {
        $recipientAdmin = User::create([
            'id' => PrefixedUlid::make('USR'), 'name' => 'Admin destino', 'cpf' => '52998224725', 'email' => 'contacts-recipient@example.test',
            'password' => Hash::make('SenhaSegura!2026'), 'status' => 'ativa', 'email_verified_at' => now(),
        ]);
        $recipientCompanyId = PrefixedUlid::make('COM');
        DB::table('companies')->insert([
            'id' => $recipientCompanyId, 'document_type' => 'cpf', 'document_number' => '52998224725', 'legal_name' => 'Empresa Destino',
            'display_name' => 'Empresa Destino', 'status' => 'ativa', 'version' => 1, 'created_by' => $recipientAdmin->id,
            'updated_by' => $recipientAdmin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('company_memberships')->insert([
            'id' => PrefixedUlid::make('MEM'), 'company_id' => $recipientCompanyId, 'user_id' => $recipientAdmin->id,
            'role_id' => DB::table('roles')->where('code', 'admin')->value('id'), 'status' => 'ativo', 'version' => 1,
            'created_by' => $recipientAdmin->id, 'updated_by' => $recipientAdmin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = DB::table('products')->where('code', 'law')->firstOrFail();
        $module = DB::table('modules')->where('product_id', $product->id)->where('module_code', 'contatos')->firstOrFail();
        $subscriptionId = PrefixedUlid::make('ASS');
        DB::table('subscriptions')->insert([
            'id' => $subscriptionId, 'company_id' => $recipientCompanyId, 'product_id' => $product->id, 'status' => 'ativa',
            'open_company_product' => $recipientCompanyId.'-'.$product->id, 'version' => 1, 'billing_cycle' => 'monthly',
            'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth(),
            'commercial_snapshot' => json_encode(['items' => [['module_id' => $module->id, 'conditions' => ['personalizations' => [['type_code' => 'contatos_cadastrados', 'value' => 10]]]]]]),
            'created_by' => $recipientAdmin->id, 'updated_by' => $recipientAdmin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscription_items')->insert([
            'id' => PrefixedUlid::make('ITM'), 'company_id' => $recipientCompanyId, 'subscription_id' => $subscriptionId,
            'module_id' => $module->id, 'name_snapshot' => $module->name, 'quantity' => 1, 'unit_price_snapshot' => $module->monthly_price,
            'conditions_snapshot' => json_encode([]), 'version' => 1, 'created_by' => $recipientAdmin->id, 'updated_by' => $recipientAdmin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sourceSession = ['active_company_id' => $this->sessionCompanyId];
        $contact = $this->actingAs($this->admin)->withSession($sourceSession)->postJson('/api/law/contacts', [
            'legal_nature' => 'pf', 'display_name' => 'Advogada Compartilhada', 'classifications' => ['lawyer'],
            'channels' => [['type' => 'email', 'value' => 'advogada@example.test', 'personal' => false]],
        ])->assertCreated()->json('contact');
        $this->actingAs($this->admin)->withSession($sourceSession)->putJson('/api/law/contact-sharing', ['policies' => [[
            'recipient_company_id' => $recipientCompanyId, 'classification_codes' => ['lawyer'], 'shared_fields' => ['professional_channels'],
        ]]])->assertOk();

        $recipientSession = ['active_company_id' => $recipientCompanyId];
        $this->actingAs($recipientAdmin)->withSession($recipientSession)->getJson('/api/law/contacts')
            ->assertOk()->assertJsonPath('contacts.0.is_shared', true)
            ->assertJsonPath('contacts.0.source_company_name', 'Empresa Contatos')
            ->assertJsonPath('contacts.0.channels.0.value', 'advogada@example.test');

        $this->actingAs($this->admin)->withSession($sourceSession)->putJson('/api/law/contact-sharing', ['policies' => []])->assertOk();
        $this->actingAs($recipientAdmin)->withSession($recipientSession)->getJson('/api/law/contacts')
            ->assertOk()->assertJsonPath('contacts', []);
        $this->assertDatabaseHas('law_contact_sharing_policies', ['source_company_id' => $this->companyId, 'recipient_company_id' => $recipientCompanyId, 'is_active' => false]);
    }

    public function test_operator_without_sensitive_permission_cannot_read_or_replace_protected_fields(): void
    {
        $session = ['active_company_id' => $this->sessionCompanyId];
        $created = $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pf', 'display_name' => 'Maria de Souza',
            'documents' => [['type' => 'cpf', 'number' => '52998224725']],
            'channels' => [
                ['type' => 'phone', 'value' => '71999990000', 'personal' => true],
                ['type' => 'email', 'value' => 'maria@example.test', 'personal' => false],
            ],
            'addresses' => [
                ['type' => 'residential', 'street' => 'Rua Um', 'city' => 'Salvador', 'state' => 'BA'],
                ['type' => 'business', 'street' => 'Rua Dois', 'city' => 'Salvador', 'state' => 'BA'],
            ],
        ])->assertCreated()->json('contact');

        $operator = User::create([
            'id' => PrefixedUlid::make('USR'), 'name' => 'Operador', 'cpf' => '52998224725', 'email' => 'contacts-operator@example.test',
            'password' => Hash::make('SenhaSegura!2026'), 'status' => 'ativa', 'email_verified_at' => now(),
        ]);
        $membershipId = PrefixedUlid::make('MEM');
        DB::table('company_memberships')->insert([
            'id' => $membershipId, 'company_id' => $this->companyId, 'user_id' => $operator->id,
            'role_id' => DB::table('roles')->where('code', 'usuario')->value('id'), 'status' => 'ativo', 'version' => 1,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $unitId = PrefixedUlid::make('LUN');
        DB::table('law_units')->insert(['id' => $unitId, 'company_id' => $this->companyId, 'name' => 'Setor', 'status' => 'ativo', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $roles = app(\App\Services\LawAuthorizationService::class)->provisionUnitRoles($this->companyId, $unitId, $this->admin->id);
        DB::table('law_unit_memberships')->insert([
            'id' => PrefixedUlid::make('LUM'), 'company_id' => $this->companyId, 'law_unit_id' => $unitId,
            'company_membership_id' => $membershipId, 'law_access_role_id' => $roles['operator'], 'status' => 'ativo', 'version' => 1,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('law_user_active_units')->insert(['user_id' => $operator->id, 'company_id' => $this->companyId, 'law_unit_id' => $unitId, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($operator)->withSession($session)->getJson('/api/law/contacts/'.$created['id'])->assertOk()
            ->assertJsonPath('contact.documents.0.number', '•••••••4725')
            ->assertJsonMissing(['street' => 'Rua Um'])->assertJsonMissing(['value' => '71999990000']);
        $this->actingAs($operator)->withSession($session)->patchJson('/api/law/contacts/'.$created['id'], [
            'display_name' => 'Maria de Oliveira', 'addresses' => [['type' => 'residential', 'street' => 'Rua Intrusa', 'city' => 'Salvador', 'state' => 'BA']],
        ])->assertForbidden();
        $this->assertDatabaseHas('law_contact_addresses', ['law_contact_id' => $created['id'], 'street' => 'Rua Um']);
        $this->assertDatabaseHas('law_contact_documents', ['law_contact_id' => $created['id']]);
        $this->assertDatabaseHas('law_contact_channels', ['law_contact_id' => $created['id'], 'channel_value' => '71999990000', 'is_personal' => true]);
    }
}
