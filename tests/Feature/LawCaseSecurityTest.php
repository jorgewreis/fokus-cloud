<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LawAuthorizationService;
use App\Services\LawCaseManagementService;
use App\Services\LawDatajudClient;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LawCaseSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $companyId;

    private string $unitId;

    private array $roles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = $this->user('Admin', '11144477735', 'law-admin@example.test');
        $this->companyId = PrefixedUlid::make('COM');
        DB::table('companies')->insert([
            'id' => $this->companyId, 'document_type' => 'cpf', 'document_number' => '11144477735',
            'legal_name' => 'Empresa Law', 'status' => 'ativa', 'version' => 1,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->membership($this->admin, 'admin');
        $product = DB::table('products')->where('code', 'law')->firstOrFail();
        $contactsModule = DB::table('modules')->where('product_id', $product->id)->where('module_code', 'processos')->whereIn('context_code', ['judiciario', 'vara_criminal'])
            ->where('status', 'ativo')->where('publication_state', 'publicado')->firstOrFail();
        $subscriptionId = PrefixedUlid::make('ASS');
        DB::table('subscriptions')->insert([
            'id' => $subscriptionId, 'company_id' => $this->companyId, 'product_id' => $product->id,
            'status' => 'ativa', 'open_company_product' => $this->companyId.'-'.$product->id, 'version' => 1,
            'billing_cycle' => 'monthly', 'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth(),
            'commercial_snapshot' => json_encode(['segment' => 'setor_publico']), 'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscription_items')->insert([
            'id' => PrefixedUlid::make('ITM'), 'company_id' => $this->companyId, 'subscription_id' => $subscriptionId,
            'module_id' => $contactsModule->id, 'name_snapshot' => $contactsModule->name, 'quantity' => 1,
            'unit_price_snapshot' => $contactsModule->monthly_price, 'conditions_snapshot' => json_encode(['segment_code' => 'setor_publico']),
            'version' => 1, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->unitId = PrefixedUlid::make('LUN');
        DB::table('law_units')->insert([
            'id' => $this->unitId, 'company_id' => $this->companyId, 'name' => 'Setor Central',
            'status' => 'ativo', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->roles = app(LawAuthorizationService::class)->provisionUnitRoles($this->companyId, $this->unitId, $this->admin->id);
        DB::table('law_user_active_units')->insert([
            'user_id' => $this->admin->id, 'company_id' => $this->companyId, 'law_unit_id' => $this->unitId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeCase(string $level = 'public', ?string $unit = null): string
    {
        $id = PrefixedUlid::make('LCS');
        DB::table('law_cases')->insert([
            'id' => $id, 'company_id' => $this->companyId, 'law_unit_id' => $unit ?? $this->unitId,
            'case_number' => str_pad((string) (DB::table('law_cases')->count() + 1), 20, '0', STR_PAD_LEFT),
            'confidentiality_level' => $level, 'internal_confidentiality_level' => $level,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function grant(string $case, bool $revoked = false): void
    {
        DB::table('law_confidential_case_accesses')->insert([
            'id' => PrefixedUlid::make('LCA'), 'company_id' => $this->companyId, 'law_case_id' => $case,
            'company_membership_id' => DB::table('company_memberships')->where('user_id', $this->admin->id)->value('id'),
            'granted_by' => $this->admin->id, 'revoked_at' => $revoked ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function login(): void
    {
        $this->actingAs($this->admin)->withSession(['active_company_id' => $this->companyId]);
    }

    public function test_datajud_changes_only_source_level_and_never_creates_or_restores_grants(): void
    {
        foreach (['public', 'cnj_1', 'cnj_2', 'cnj_3', 'cnj_4', 'cnj_5', 'secret', 'confidential'] as $level) {
            $id = $this->makeCase($level);
            $this->grant($id, true);
            foreach ([0, 5] as $officialLevel) {
                $client = $this->createStub(LawDatajudClient::class);
                $client->method('lookup')->willReturn(['status' => 'synced', 'metadata' => ['confidentiality_level_cnj' => $officialLevel]]);
                $row = DB::table('law_cases')->where('id', $id)->first();
                app(LawCaseManagementService::class)->syncDatajud($row, $this->admin->id, $client);
                $this->assertDatabaseHas('law_cases', ['id' => $id, 'confidentiality_level' => $level, 'internal_confidentiality_level' => $level, 'source_confidentiality_level' => $officialLevel === 0 ? 'public' : 'cnj_5']);
                $this->assertNotNull(DB::table('law_confidential_case_accesses')->where('law_case_id', $id)->value('revoked_at'));
            }
        }
        $id = $this->makeCase();
        $client = $this->createStub(LawDatajudClient::class);
        $client->method('lookup')->willReturn(['status' => 'synced', 'metadata' => ['confidentiality_level_cnj' => 5]]);
        app(LawCaseManagementService::class)->syncDatajud(DB::table('law_cases')->where('id', $id)->first(), $this->admin->id, $client);
        $this->assertFalse(DB::table('law_confidential_case_accesses')->where('law_case_id', $id)->exists());
    }

    public function test_source_never_controls_nominal_access_and_admin_has_no_bypass(): void
    {
        $id = $this->makeCase('secret');
        DB::table('law_cases')->where('id', $id)->update(['source_confidentiality_level' => 'public']);
        $this->login();
        $this->getJson('/api/law/cases/'.$id)->assertNotFound();
        $this->grant($id);
        $this->getJson('/api/law/cases/'.$id)->assertOk()->assertJsonPath('case.internal_confidentiality_level', 'secret');
        $public = $this->makeCase();
        DB::table('law_cases')->where('id', $public)->update(['source_confidentiality_level' => 'cnj_5']);
        $this->getJson('/api/law/cases/'.$public)->assertOk();
    }

    public function test_edit_does_not_regrant_and_keeps_alias_synchronized(): void
    {
        $id = $this->makeCase();
        $this->grant($id, true);
        $this->login();
        $this->patchJson('/api/law/cases/'.$id, ['version' => 1, 'internal_confidentiality_level' => 'cnj_5'])->assertOk();
        $this->assertDatabaseHas('law_cases', ['id' => $id, 'confidentiality_level' => 'cnj_5', 'internal_confidentiality_level' => 'cnj_5']);
        $this->assertNotNull(DB::table('law_confidential_case_accesses')->where('law_case_id', $id)->value('revoked_at'));
        $this->getJson('/api/law/cases/'.$id)->assertNotFound();
        $other = $this->makeCase();
        $this->patchJson('/api/law/cases/'.$other, ['version' => 1, 'confidentiality_level' => 'secret'])->assertOk();
        $this->assertFalse(DB::table('law_confidential_case_accesses')->where('law_case_id', $other)->exists());
    }

    public function test_spoofed_source_or_conflicting_alias_is_rejected(): void
    {
        $id = $this->makeCase();
        $this->login();
        $this->patchJson('/api/law/cases/'.$id, ['version' => 1, 'source_confidentiality_level' => 'public'])->assertUnprocessable();
        $this->patchJson('/api/law/cases/'.$id, ['version' => 1, 'confidentiality_level' => 'public', 'internal_confidentiality_level' => 'secret'])->assertUnprocessable();
    }

    public function test_deleted_company_membership_has_no_access(): void
    {
        $id = $this->makeCase();
        DB::table('company_memberships')->where('user_id', $this->admin->id)->update(['deleted_at' => now()]);
        $this->login();
        $this->getJson('/api/law/cases/'.$id)->assertForbidden();
    }

    public function test_module_revocation_and_inactive_unit_block_access(): void
    {
        $id = $this->makeCase();
        $this->login();
        DB::table('law_units')->where('id', $this->unitId)->update(['status' => 'inativo']);
        $this->getJson('/api/law/cases/'.$id)->assertNotFound();
        DB::table('law_units')->where('id', $this->unitId)->update(['status' => 'ativo']);
        DB::table('subscription_items')->where('company_id', $this->companyId)->update(['deleted_at' => now()]);
        $this->getJson('/api/law/cases/'.$id)->assertForbidden();
    }

    public function test_operator_cannot_read_edit_or_reference_another_unit(): void
    {
        $own = $this->makeCase();
        $otherUnit = PrefixedUlid::make('LUN');
        DB::table('law_units')->insert(['id' => $otherUnit, 'company_id' => $this->companyId, 'name' => 'Outra unidade', 'status' => 'ativo', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $other = $this->makeCase('public', $otherUnit);
        $user = $this->user('Operador', '52998224725', 'operator-security@example.test');
        $membership = $this->membership($user, 'usuario');
        $this->lawMembership($membership, $this->roles['operator']);
        DB::table('law_user_active_units')->insert(['user_id' => $user->id, 'company_id' => $this->companyId, 'law_unit_id' => $this->unitId, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->withSession(['active_company_id' => $this->companyId]);
        DB::table('law_case_relations')->insert(['id' => PrefixedUlid::make('LCR'), 'company_id' => $this->companyId, 'law_case_id' => $own, 'related_law_case_id' => $other, 'relation_type' => 'apenso', 'created_by' => $this->admin->id, 'created_at' => now()]);
        $this->getJson('/api/law/cases/'.$own)->assertOk()->assertJsonCount(0, 'relations');
        $this->getJson('/api/law/cases/search?q=00')->assertOk()->assertJsonCount(1, 'cases');
        $this->getJson('/api/law/cases/'.$other)->assertNotFound();
        $this->getJson('/api/law/cases')->assertOk()->assertJsonCount(1, 'cases');
        $this->patchJson('/api/law/cases/'.$other, ['version' => 1, 'operational_priority' => 'urgent'])->assertNotFound();
        $this->getJson('/api/law/cases/references?law_unit_id='.$otherUnit)->assertForbidden();
        $this->postJson('/api/law/cases', ['law_unit_id' => $otherUnit, 'case_number' => '00000000000000000000'])->assertForbidden();
        DB::table('law_unit_memberships')->where('company_membership_id', $membership)->update(['deleted_at' => now()]);
        $this->getJson('/api/law/cases/'.$own)->assertForbidden();
    }

    public function test_foreign_tenant_case_is_not_exposed_or_mutated(): void
    {
        $foreignCompany = PrefixedUlid::make('COM');
        $foreignUnit = PrefixedUlid::make('LUN');
        DB::table('companies')->insert(['id' => $foreignCompany, 'document_type' => 'cpf', 'document_number' => '52998224725', 'legal_name' => 'Tenant sintético', 'status' => 'ativa', 'version' => 1, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('law_units')->insert(['id' => $foreignUnit, 'company_id' => $foreignCompany, 'name' => 'Unidade externa', 'status' => 'ativo', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $id = $this->makeCase();
        DB::table('law_cases')->where('id', $id)->update(['company_id' => $foreignCompany, 'law_unit_id' => $foreignUnit]);
        $this->login();
        $this->getJson('/api/law/cases/'.$id)->assertNotFound();
        $this->getJson('/api/law/cases')->assertOk()->assertJsonCount(0, 'cases');
        $this->patchJson('/api/law/cases/'.$id, ['version' => 1, 'operational_priority' => 'urgent'])->assertNotFound();
        $this->assertDatabaseHas('law_cases', ['id' => $id, 'operational_priority' => 'normal', 'version' => 1]);
    }

    public function test_explicit_grant_endpoint_can_restore_revoked_access(): void
    {
        $id = $this->makeCase('cnj_5');
        $this->grant($id, true);
        $this->login();
        $membership = DB::table('company_memberships')->where('user_id', $this->admin->id)->value('id');
        $this->postJson('/api/law/cases/'.$id.'/access', ['company_membership_id' => $membership])->assertCreated();
        $this->getJson('/api/law/cases/'.$id)->assertOk();
        $this->assertNull(DB::table('law_confidential_case_accesses')->where('law_case_id', $id)->value('revoked_at'));
    }

    public function test_split_migration_preserves_literals_received_after_the_baseline(): void
    {
        // The full baseline already ran 000100. These legacy fixtures are inserted
        // after it; 000200 preserves its input, not labels previously transformed.
        $migration = require database_path('migrations/2026_11_06_000200_separate_law_case_confidentiality.php');
        $migration->down(); // Synthetic in-memory database only.
        foreach (['public', 'cnj_1', 'cnj_2', 'cnj_3', 'cnj_4', 'cnj_5', 'secret', 'confidential'] as $i => $level) {
            DB::table('law_cases')->insert(['id' => PrefixedUlid::make('LCS'), 'company_id' => $this->companyId, 'law_unit_id' => $this->unitId, 'case_number' => str_pad((string) $i, 20, '0', STR_PAD_LEFT), 'confidentiality_level' => $level, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $migration->up();
        foreach (DB::table('law_cases')->get() as $case) {
            $this->assertSame($case->confidentiality_level, $case->internal_confidentiality_level);
            $this->assertNull($case->source_confidentiality_level);
        }
    }

    private function user(string $name, string $cpf, string $email): User
    {
        return User::create([
            'id' => PrefixedUlid::make('USR'), 'name' => $name, 'cpf' => $cpf, 'email' => $email,
            'password' => Hash::make('SenhaSegura!2026'), 'status' => 'ativa', 'email_verified_at' => now(),
        ]);
    }

    private function membership(User $user, string $role): string
    {
        $id = PrefixedUlid::make('MEM');
        DB::table('company_memberships')->insert([
            'id' => $id, 'company_id' => $this->companyId, 'user_id' => $user->id,
            'role_id' => DB::table('roles')->where('code', $role)->value('id'), 'status' => 'ativo', 'version' => 1,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function lawMembership(string $membershipId, string $roleId): void
    {
        DB::table('law_unit_memberships')->insert([
            'id' => PrefixedUlid::make('LUM'), 'company_id' => $this->companyId,
            'law_unit_id' => $this->unitId, 'company_membership_id' => $membershipId,
            'law_access_role_id' => $roleId, 'status' => 'ativo', 'version' => 1,
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
