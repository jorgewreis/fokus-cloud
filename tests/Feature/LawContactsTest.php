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

    public function test_creates_standalone_hierarchy_units_as_additional_capacity_and_normalizes_names_and_tags(): void
    {
        $session = ['active_company_id' => $this->sessionCompanyId];
        $organization = $this->actingAs($this->admin)->withSession($session)
            ->postJson('/api/law/contacts', [
                'legal_nature' => 'pj', 'display_name' => 'SECRETARIA DE JUSTIÇA', 'legal_name' => 'SECRETARIA DE JUSTIÇA DO ESTADO',
                'classifications' => ['public_body'], 'tags' => [' Setor público ', 'SETOR   PÚBLICO'],
            ])->assertCreated()->assertJsonPath('contact.display_name', 'Secretaria de Justiça')->json('contact');
        $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'record_kind' => 'unit', 'display_name' => 'Contabilidade', 'parent_contact_id' => $organization['id'],
            'channels' => [['type' => 'email', 'value' => 'contabilidade@example.test', 'label' => 'Recepção']],
        ])->assertCreated()->assertJsonPath('contact.record_kind', 'unit')->assertJsonPath('contact.parent.id', $organization['id']);

        $this->assertDatabaseCount('law_contacts', 2);
        $this->assertDatabaseCount('law_contact_departments', 0);
        $this->assertDatabaseCount('law_contact_tags', 1);
        $this->assertDatabaseCount('law_contact_tag_assignments', 1);
        $this->assertSame(2, app(\App\Services\LawUsageMeter::class)->countContacts($this->companyId));
    }

    public function test_links_people_and_companies_bidirectionally_and_persists_company_acronym(): void
    {
        $session = ['active_company_id' => $this->sessionCompanyId];
        $person = $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pf', 'display_name' => 'Ana de Souza',
        ])->assertCreated()->json('contact');

        $company = $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pj', 'display_name' => 'Empresa Exemplo', 'acronym' => 'EX',
            'linked_contact_ids' => [$person['id']],
            'classifications' => ['public_body'],
            'institutional_data' => [
                ['type' => 'public_body', 'administrative_sphere' => 'Estadual', 'official_code' => 'ORG-22', 'issuing_system' => 'Cadastro Estadual'],
                ['type' => 'court_unit', 'cnj_code' => '1234567-89.2026.8.05.0001', 'competencies' => ['Criminal', 'Fazenda Pública']],
            ],
            'linked_relationships' => [[
                'contact_id' => $person['id'],
                'roles' => [
                    ['code' => 'public_servant', 'starts_on' => '2020-01-01'],
                    ['code' => 'legal_representative', 'starts_on' => '2022-01-01'],
                ],
                'designations' => [
                    ['name' => 'DPC', 'starts_on' => '2020-01-01', 'ends_on' => '2023-12-31'],
                    ['name' => 'Delegado titular', 'starts_on' => '2024-01-01'],
                ],
            ]],
        ])->assertCreated()->assertJsonPath('contact.acronym', 'EX')->assertJsonCount(2, 'contact.institutional_data')->assertJsonPath('contact.linked_contacts.0.id', $person['id'])->assertJsonCount(2, 'contact.linked_contacts.0.roles')->assertJsonCount(2, 'contact.linked_contacts.0.designations')->json('contact');

        $this->actingAs($this->admin)->withSession($session)->getJson('/api/law/contacts/'.$person['id'])
            ->assertOk()->assertJsonPath('contact.linked_contacts.0.id', $company['id'])
            ->assertJsonPath('contact.linked_contacts.0.acronym', 'EX');
        $this->assertDatabaseHas('law_contact_company_links', [
            'company_id' => $this->companyId, 'person_contact_id' => $person['id'], 'company_contact_id' => $company['id'],
        ]);
        $this->assertDatabaseCount('law_contact_relationship_roles', 2);
        $this->assertDatabaseCount('law_contact_relationship_designations', 2);
        $this->assertDatabaseCount('law_contact_institutional_data', 2);
        $this->assertDatabaseHas('law_contact_institutional_data', ['company_id' => $this->companyId, 'data_type' => 'court_unit', 'cnj_code' => '12345678920268050001']);
        $registrationDate = \Illuminate\Support\Carbon::parse(DB::table('law_contacts')->where('id', $person['id'])->value('created_at'))->toDateString();
        $this->assertSame([$registrationDate, $registrationDate], DB::table('law_contact_relationship_roles')->orderBy('role_code')->pluck('starts_on')->all());
        $this->assertSame([$registrationDate, $registrationDate], DB::table('law_contact_relationship_designations')->orderBy('name')->pluck('starts_on')->all());
        $this->assertDatabaseMissing('law_contact_relationship_roles', ['link_id' => DB::table('law_contact_company_links')->where('person_contact_id', $person['id'])->value('id'), 'ends_on' => today()->toDateString()]);

        $this->actingAs($this->admin)->withSession($session)->deleteJson('/api/law/contacts/'.$person['id'])->assertNoContent();
        $this->assertDatabaseHas('law_contacts', ['id' => $person['id'], 'status' => 'excluido']);
        $this->assertSame([today()->toDateString(), today()->toDateString()], DB::table('law_contact_relationship_roles')->orderBy('role_code')->pluck('ends_on')->all());
    }

    public function test_contact_quality_summary_and_duplicate_suggestions_use_professional_channels(): void
    {
        $session = ['active_company_id' => $this->sessionCompanyId];
        $existing = $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pf', 'display_name' => 'Ana Souza',
            'channels' => [['type' => 'email', 'value' => 'ana.souza@example.test', 'personal' => false]],
        ])->assertCreated()->json('contact');

        $this->actingAs($this->admin)->withSession($session)->getJson('/api/law/contacts')
            ->assertOk()->assertJsonPath('summary.quality.without_phone', 1)->assertJsonPath('summary.quality.without_email', 0);
        $this->actingAs($this->admin)->withSession($session)->getJson('/api/law/contacts/quality/review?type=without_phone')
            ->assertOk()->assertJsonPath('contacts.0.id', $existing['id']);

        $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts/quality/suggestions', [
            'legal_nature' => 'pf', 'display_name' => 'Ana Souza',
            'channels' => [['type' => 'email', 'value' => 'ana.souza@example.test', 'personal' => false]],
        ])->assertOk()->assertJsonPath('candidates.0.id', $existing['id'])->assertJsonPath('candidates.0.reason', 'Nome semelhante e E-mail profissional coincidente');
    }

    public function test_pj_can_store_cnpj_and_state_registration_with_uf(): void
    {
        $session = ['active_company_id' => $this->sessionCompanyId];
        $documents = [
            ['type' => 'cnpj', 'number' => '11.222.333/0001-81'],
            ['type' => 'state_registration', 'number' => '123456789', 'state' => 'BA'],
        ];
        $contact = $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pj', 'display_name' => 'Empresa Documentada', 'documents' => $documents,
        ])->assertCreated()->json('contact');

        $byType = array_column($contact['documents'], null, 'type');
        $this->assertEqualsCanonicalizing(['cnpj', 'state_registration'], array_keys($byType));
        $this->assertSame('11222333000181', $byType['cnpj']['number']);
        $this->assertSame('BA', $byType['state_registration']['state']);
        $this->actingAs($this->admin)->withSession($session)->getJson('/api/law/contacts/'.$contact['id'])
            ->assertOk()->assertJsonFragment(['type' => 'state_registration', 'state' => 'BA']);
        $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pj', 'display_name' => 'Empresa sem UF',
            'documents' => [['type' => 'state_registration', 'number' => '987654321']],
        ])->assertUnprocessable();
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

    public function test_companies_must_both_opt_in_to_share_selected_natures_and_professions(): void
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
            'legal_nature' => 'pf', 'display_name' => 'Advogada Compartilhada', 'professions' => ['Advogada'],
            'channels' => [['type' => 'email', 'value' => 'advogada@example.test', 'personal' => false]],
        ])->assertCreated()->json('contact');
        $this->actingAs($this->admin)->withSession($sourceSession)->putJson('/api/law/contact-sharing', ['policies' => [[
            'recipient_company_id' => $recipientCompanyId, 'legal_natures' => ['pf'], 'profession_names' => ['advogada'], 'shared_fields' => ['professional_channels'],
        ]]])->assertOk();

        $recipientSession = ['active_company_id' => $recipientCompanyId];
        $this->actingAs($recipientAdmin)->withSession($recipientSession)->getJson('/api/law/contacts')
            ->assertOk()->assertJsonPath('contacts', []);
        $this->actingAs($recipientAdmin)->withSession($recipientSession)->putJson('/api/law/contact-sharing', ['policies' => [[
            'recipient_company_id' => $this->companyId, 'legal_natures' => ['pj'], 'profession_names' => [], 'shared_fields' => [],
        ]]])->assertOk();

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
