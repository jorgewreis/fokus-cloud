<?php

namespace Tests\Feature;

use App\Models\User;
use App\Jobs\SyncLawCaseDjen;
use App\Services\LawCaseManagementService;
use App\Services\LawDjenClient;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_contact_linked_cases_resolve_class_from_catalogs_and_djen_without_exposing_secret_cases(): void
    {
        $session = ['active_company_id' => $this->sessionCompanyId];
        $contact = $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pf', 'display_name' => 'Pessoa vinculada',
        ])->assertCreated()->json('contact');
        $unitId = PrefixedUlid::make('LUN');
        DB::table('law_units')->insert(['id' => $unitId, 'company_id' => $this->companyId, 'name' => 'Unidade', 'status' => 'ativo', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('law_cnj_metadata_options')->insert(['id' => PrefixedUlid::make('LCN'), 'type' => 'class', 'code' => 'TEST-CNJ', 'name' => 'Classe CNJ', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('law_case_metadata_options')->insert(['id' => PrefixedUlid::make('LCM'), 'company_id' => $this->companyId, 'type' => 'class', 'code' => 'TEST-EMPRESA', 'name' => 'Classe da empresa', 'created_at' => now(), 'updated_at' => now()]);
        $fixtures = [
            ['number' => '00000010020268050103', 'case_class_code' => 'TEST-EMPRESA', 'manual_metadata' => json_encode(['case_class_code' => 'TEST-CNJ']), 'expected' => 'Classe CNJ'],
            ['number' => '00000020020268050103', 'case_class_code' => 'TEST-EMPRESA', 'expected' => 'Classe da empresa'],
            ['number' => '00000030020268050103', 'case_class' => 'Classe cadastrada', 'expected' => 'Classe cadastrada'],
            ['number' => '00000040020268050103', 'djen_metadata' => json_encode(['process_data' => ['case_class' => 'AÇÃO PENAL - PROCEDIMENTO ORDINÁRIO']]), 'expected' => 'Ação Penal - Procedimento Ordinário'],
            ['number' => '00000050020268050103', 'case_class' => 'Classe sigilosa', 'secret' => true, 'expected' => null],
        ];
        foreach ($fixtures as $fixture) {
            $caseId = PrefixedUlid::make('LCS');
            DB::table('law_cases')->insert([
                'id' => $caseId, 'company_id' => $this->companyId, 'law_unit_id' => $unitId,
                'case_number' => $fixture['number'], 'case_class' => $fixture['case_class'] ?? null,
                'case_class_code' => $fixture['case_class_code'] ?? null, 'manual_metadata' => $fixture['manual_metadata'] ?? null,
                'djen_metadata' => $fixture['djen_metadata'] ?? null, 'confidentiality_level' => ! empty($fixture['secret']) ? 'secret' : 'public',
                'created_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('law_case_contacts')->insert([
                'id' => PrefixedUlid::make('LCC'), 'company_id' => $this->companyId, 'law_unit_id' => $unitId,
                'law_case_id' => $caseId, 'law_contact_id' => $contact['id'], 'case_role' => 'parte', 'case_role_label' => 'Parte',
                'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $response = $this->actingAs($this->admin)->withSession($session)->getJson('/api/law/contacts/'.$contact['id'])->assertOk();
        $linkedCases = collect($response->json('contact.cases'))->keyBy('case_number');
        $this->assertCount(4, $linkedCases);
        foreach (array_slice($fixtures, 0, 4) as $fixture) {
            $this->assertSame($fixture['expected'], $linkedCases[$fixture['number']]['case_class']);
        }
        $this->assertFalse($linkedCases->has($fixtures[4]['number']));
    }

    public function test_djen_scheduler_refreshes_daily_and_retries_failures_without_waiting_a_month(): void
    {
        Queue::fake();
        $module = DB::table('modules')->where('module_code', 'processos')->whereIn('context_code', ['judiciario', 'vara_criminal', 'escritorio'])->where('status', 'ativo')->where('publication_state', 'publicado')->first();
        $this->assertNotNull($module);
        $subscriptionId = DB::table('subscriptions')->where('company_id', $this->companyId)->value('id');
        DB::table('subscription_items')->insert([
            'id' => PrefixedUlid::make('ITM'), 'company_id' => $this->companyId, 'subscription_id' => $subscriptionId,
            'module_id' => $module->id, 'name_snapshot' => $module->name, 'quantity' => 1,
            'unit_price_snapshot' => $module->monthly_price, 'conditions_snapshot' => '{}', 'version' => 1,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $unitId = PrefixedUlid::make('LUN');
        DB::table('law_units')->insert(['id' => $unitId, 'company_id' => $this->companyId, 'name' => 'Unidade', 'status' => 'ativo', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $fixtures = [
            ['status' => 'synced', 'synced' => now()->subHours(25), 'checked' => now()->subHours(25), 'due' => true],
            ['status' => 'not_found', 'synced' => now()->subHours(25), 'checked' => now()->subHours(25), 'due' => true],
            ['status' => 'synced', 'synced' => now()->subHours(23), 'checked' => now()->subHours(23), 'due' => false],
            ['status' => 'error', 'synced' => now()->subDays(10), 'checked' => now()->subMinutes(61), 'due' => true],
            ['status' => 'error', 'synced' => now()->subDays(10), 'checked' => now()->subMinutes(30), 'due' => false],
            ['status' => 'not_requested', 'synced' => null, 'checked' => null, 'due' => true],
        ];
        foreach ($fixtures as $index => &$fixture) {
            $fixture['id'] = PrefixedUlid::make('LCS');
            DB::table('law_cases')->insert([
                'id' => $fixture['id'], 'company_id' => $this->companyId, 'law_unit_id' => $unitId,
                'case_number' => str_pad((string) ($index + 1), 20, '0', STR_PAD_LEFT), 'confidentiality_level' => 'public',
                'djen_sync_status' => $fixture['status'], 'djen_retryable' => true, 'djen_retry_count' => 1,
                'last_djen_synced_at' => $fixture['synced'], 'last_djen_checked_at' => $fixture['checked'],
                'created_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        unset($fixture);
        $this->artisan('law:sync-case-djen')->assertSuccessful();
        Queue::assertPushed(SyncLawCaseDjen::class, 4);
        foreach ($fixtures as $fixture) {
            $this->assertSame($fixture['due'] ? 'pending' : $fixture['status'], DB::table('law_cases')->where('id', $fixture['id'])->value('djen_sync_status'));
        }
        $this->assertLessThan(config('queue.connections.law-datajud.retry_after'), (new SyncLawCaseDjen($this->companyId, $fixtures[0]['id']))->timeout);
    }

    public function test_failed_djen_sync_preserves_previously_saved_publications_and_success_time(): void
    {
        $unitId = PrefixedUlid::make('LUN');
        DB::table('law_units')->insert(['id' => $unitId, 'company_id' => $this->companyId, 'name' => 'Unidade', 'status' => 'ativo', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $caseId = PrefixedUlid::make('LCS');
        $previous = json_encode(['publications' => [['id' => 'documento-anterior']]]);
        $lastSuccess = now()->subDays(3)->startOfSecond();
        DB::table('law_cases')->insert([
            'id' => $caseId, 'company_id' => $this->companyId, 'law_unit_id' => $unitId, 'case_number' => '10016228320244013301',
            'djen_sync_status' => 'pending', 'djen_metadata' => $previous, 'last_djen_synced_at' => $lastSuccess,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $client = \Mockery::mock(LawDjenClient::class);
        $client->shouldReceive('lookup')->once()->andReturn(['status' => 'error', 'code' => 'invalid_response', 'message' => 'Resposta inválida', 'metadata' => []]);
        app(LawCaseManagementService::class)->syncDjen(DB::table('law_cases')->where('id', $caseId)->first(), $client);
        $saved = DB::table('law_cases')->where('id', $caseId)->first();
        $this->assertSame(json_decode($previous, true), json_decode($saved->djen_metadata, true));
        $this->assertSame($lastSuccess->toDateTimeString(), (string) $saved->last_djen_synced_at);
        $this->assertSame('error', $saved->djen_sync_status);
        $this->assertTrue((bool) $saved->djen_retryable);
        $this->assertSame(1, (int) $saved->djen_retry_count);
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
            ])->assertCreated()->assertJsonPath('contact.display_name', 'Secretaria de Justiça')->assertJsonMissingPath('contact.classifications')->json('contact');
        $this->assertDatabaseMissing('law_contact_classifications', ['company_id' => $this->companyId, 'law_contact_id' => $organization['id']]);
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
            'sensitive_details' => ['birth_date' => '1985-02-03', 'pix_keys' => [['type' => 'email', 'value' => 'private-pix@example.test']]],
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
            ->assertJsonPath('contacts.0.channels.0.value', 'advogada@example.test')
            ->assertJsonMissingPath('contacts.0.sensitive_details');
        $this->actingAs($recipientAdmin)->withSession($recipientSession)->getJson('/api/law/contacts/'.$contact['id'])
            ->assertOk()->assertJsonMissingPath('contact.sensitive_details')->assertJsonMissing(['value' => 'private-pix@example.test']);
        $this->actingAs($recipientAdmin)->withSession($recipientSession)->patchJson('/api/law/contacts/'.$contact['id'], [
            'sensitive_details' => ['birth_date' => '1990-01-01'],
        ])->assertNotFound();

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
            'sensitive_details' => ['birth_date' => '1980-01-01', 'bank_accounts' => [['bank' => 'Banco Sintético', 'account' => '98765-4']]],
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
            ->assertJsonMissingPath('contact.sensitive_details')
            ->assertJsonMissing(['street' => 'Rua Um'])->assertJsonMissing(['value' => '71999990000']);
        $this->actingAs($operator)->withSession($session)->patchJson('/api/law/contacts/'.$created['id'], [
            'display_name' => 'Maria de Oliveira', 'addresses' => [['type' => 'residential', 'street' => 'Rua Intrusa', 'city' => 'Salvador', 'state' => 'BA']],
        ])->assertForbidden();
        $ciphertext = DB::table('law_contacts')->where('id', $created['id'])->value('sensitive_details_encrypted');
        $this->actingAs($operator)->withSession($session)->patchJson('/api/law/contacts/'.$created['id'], [
            'sensitive_details' => ['birth_date' => null],
        ])->assertForbidden();
        $this->actingAs($operator)->withSession($session)->patchJson('/api/law/contacts/'.$created['id'], [
            'display_name' => 'Maria de Oliveira',
        ])->assertOk()->assertJsonMissingPath('contact.sensitive_details');
        $this->assertSame($ciphertext, DB::table('law_contacts')->where('id', $created['id'])->value('sensitive_details_encrypted'));
        $this->assertDatabaseHas('law_contact_addresses', ['law_contact_id' => $created['id'], 'street' => 'Rua Um']);
        $this->assertDatabaseHas('law_contact_documents', ['law_contact_id' => $created['id']]);
        $this->assertDatabaseHas('law_contact_channels', ['law_contact_id' => $created['id'], 'channel_value' => '71999990000', 'is_personal' => true]);
    }

    public function test_sensitive_details_are_encrypted_and_omission_preserves_them(): void
    {
        $session = ['active_company_id' => $this->sessionCompanyId];
        $details = [
            'birth_date' => '1988-07-10',
            'parentage' => [['name' => 'Pessoa Sintética', 'relationship' => 'Mãe']],
            'bank_accounts' => [['bank' => 'Banco Sintético', 'branch' => '0001', 'account' => '123456-7', 'account_type' => 'Corrente', 'holder_name' => 'Cliente Sintético']],
            'pix_keys' => [['type' => 'email', 'value' => 'synthetic-pix@example.test']],
        ];
        $contact = $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pf', 'display_name' => 'Cliente Sintético', 'sensitive_details' => $details,
        ])->assertCreated()->assertJsonPath('contact.sensitive_details.birth_date', '1988-07-10')->json('contact');
        $ciphertext = DB::table('law_contacts')->where('id', $contact['id'])->value('sensitive_details_encrypted');
        $this->assertIsString($ciphertext);
        $this->assertStringNotContainsString('synthetic-pix', $ciphertext);
        $this->assertStringNotContainsString('123456-7', $ciphertext);
        $this->assertSame($details, json_decode(Crypt::decryptString($ciphertext), true));

        $this->actingAs($this->admin)->withSession($session)->getJson('/api/law/contacts')
            ->assertOk()->assertJsonMissingPath('contacts.0.sensitive_details');
        $this->actingAs($this->admin)->withSession($session)->patchJson('/api/law/contacts/'.$contact['id'], [
            'display_name' => 'Cliente Renomeado',
        ])->assertOk()->assertJsonPath('contact.sensitive_details.pix_keys.0.value', 'synthetic-pix@example.test');
        $this->assertSame($ciphertext, DB::table('law_contacts')->where('id', $contact['id'])->value('sensitive_details_encrypted'));
        $this->actingAs($this->admin)->withSession($session)->getJson('/api/law/contacts/'.$contact['id'])
            ->assertOk()->assertJsonPath('contact.sensitive_details.bank_accounts.0.account', '123456-7');
        foreach (DB::table('audit_events')->where('company_id', $this->companyId)->get() as $event) {
            $this->assertStringNotContainsString('synthetic-pix', json_encode($event));
            $this->assertStringNotContainsString('123456-7', json_encode($event));
        }
    }

    public function test_sensitive_details_reject_incompatible_natures_and_unknown_secret_fields(): void
    {
        $session = ['active_company_id' => $this->sessionCompanyId];
        $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pj', 'display_name' => 'Organização Teste', 'sensitive_details' => ['birth_date' => '1990-01-01'],
        ])->assertUnprocessable();
        $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pf', 'display_name' => 'Cliente Teste', 'sensitive_details' => ['gov_password' => 'never-store-this'],
        ])->assertUnprocessable();
        $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pf', 'display_name' => 'Cliente Teste', 'sensitive_details' => ['birth_date' => '2999-01-01'],
        ])->assertUnprocessable();
        $parent = $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pj', 'display_name' => 'Organização Teste',
        ])->assertCreated()->json('contact');
        $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'record_kind' => 'unit', 'display_name' => 'Unidade Teste', 'parent_contact_id' => $parent['id'],
            'sensitive_details' => ['pix_keys' => [['type' => 'email', 'value' => 'unit@example.test']]],
        ])->assertUnprocessable();
    }

    public function test_sensitive_detail_collection_limits_and_nested_keys_are_validated(): void
    {
        $session = ['active_company_id' => $this->sessionCompanyId];
        $invalidDetails = [
            ['parentage' => array_fill(0, 5, ['name' => 'Ascendente Sintético', 'relationship' => 'Mãe'])],
            ['bank_accounts' => array_fill(0, 6, ['bank' => 'Banco Sintético', 'account' => '12345'])],
            ['pix_keys' => array_fill(0, 11, ['type' => 'email', 'value' => 'synthetic@example.test'])],
            ['pix_keys' => [['type' => 'unsupported', 'value' => 'synthetic@example.test']]],
            ['bank_accounts' => [['bank' => 'Banco Sintético', 'account' => '12345', 'password' => 'never-store-this']]],
        ];
        foreach ($invalidDetails as $details) {
            $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
                'legal_nature' => 'pf', 'display_name' => 'Cliente Limites', 'sensitive_details' => $details,
            ])->assertUnprocessable();
        }
        $this->assertDatabaseCount('law_contacts', 0);
    }

    public function test_expanded_document_and_email_limits_keep_identifiers_distinct(): void
    {
        $session = ['active_company_id' => $this->sessionCompanyId];
        $documents = array_map(fn ($type) => ['type' => $type, 'number' => '12345678901'], ['pis', 'pasep', 'nit', 'nis', 'cadunico', 'cnh', 'cin']);
        for ($i = count($documents); $i < 10; $i++) $documents[] = ['type' => 'other', 'number' => 'TEST-'.$i];
        $channels = array_map(fn ($i) => ['type' => 'email', 'value' => 'synthetic'.$i.'@example.test'], range(1, 10));
        $contact = $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pf', 'display_name' => 'Múltiplos Documentos', 'documents' => $documents, 'channels' => $channels,
        ])->assertCreated()->assertJsonCount(10, 'contact.documents')->assertJsonCount(10, 'contact.channels')->json('contact');
        foreach (['pis', 'nit', 'nis', 'cadunico'] as $type) {
            $this->assertDatabaseHas('law_contact_documents', ['company_id' => $this->companyId, 'law_contact_id' => $contact['id'], 'document_type' => $type]);
        }
        $this->actingAs($this->admin)->withSession($session)->patchJson('/api/law/contacts/'.$contact['id'], [
            'documents' => [...$documents, ['type' => 'other', 'number' => 'OVER-LIMIT']],
        ])->assertUnprocessable();
        $this->actingAs($this->admin)->withSession($session)->patchJson('/api/law/contacts/'.$contact['id'], [
            'channels' => [...$channels, ['type' => 'email', 'value' => 'overflow@example.test']],
        ])->assertUnprocessable();
        $this->actingAs($this->admin)->withSession($session)->patchJson('/api/law/contacts/'.$contact['id'], [
            'channels' => array_fill(0, 5, ['type' => 'phone', 'value' => '71999990000']),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('law_contact_documents', 10);
        $this->assertDatabaseCount('law_contact_channels', 10);
    }

    public function test_nature_change_requires_explicit_prior_review_of_personal_details(): void
    {
        $session = ['active_company_id' => $this->sessionCompanyId];
        $contact = $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pf', 'display_name' => 'Mudança Sintética', 'sensitive_details' => ['birth_date' => '1980-01-01'],
        ])->assertCreated()->json('contact');
        $this->actingAs($this->admin)->withSession($session)->patchJson('/api/law/contacts/'.$contact['id'], [
            'legal_nature' => 'pj', 'sensitive_details' => ['birth_date' => null, 'parentage' => []],
        ])->assertUnprocessable();
        $this->actingAs($this->admin)->withSession($session)->getJson('/api/law/contacts/'.$contact['id'])
            ->assertOk()->assertJsonPath('contact.sensitive_details.birth_date', '1980-01-01');
        $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pj', 'display_name' => 'Documento Incompatível', 'documents' => [['type' => 'nit', 'number' => '12345678901']],
        ])->assertUnprocessable();
    }

    public function test_merge_does_not_silently_drop_sensitive_details(): void
    {
        $session = ['active_company_id' => $this->sessionCompanyId];
        $source = $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pf', 'display_name' => 'Origem Sintética', 'sensitive_details' => ['birth_date' => '1980-01-01'],
        ])->assertCreated()->json('contact');
        $target = $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts', [
            'legal_nature' => 'pf', 'display_name' => 'Destino Sintético',
        ])->assertCreated()->json('contact');
        $response = $this->actingAs($this->admin)->withSession($session)->postJson('/api/law/contacts/'.$source['id'].'/merge', [
            'target_contact_id' => $target['id'], 'reason' => 'Revisão sintética de duplicidade',
        ]);
        $this->assertContains($response->status(), [409, 422]);
        $this->assertDatabaseHas('law_contacts', ['id' => $source['id'], 'merged_into_id' => null, 'deleted_at' => null]);
        $this->actingAs($this->admin)->withSession($session)->getJson('/api/law/contacts/'.$source['id'])
            ->assertOk()->assertJsonPath('contact.sensitive_details.birth_date', '1980-01-01');
    }

}

