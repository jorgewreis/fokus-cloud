<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LawAuthorizationService;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LawHearingsApiTest extends TestCase
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
        $hearingModule = DB::table('modules')->where('product_id', $product->id)->where('module_code', 'audiencias')->whereIn('context_code', ['judiciario', 'vara_criminal'])->where('status', 'ativo')->where('publication_state', 'publicado')->firstOrFail();
        DB::table('subscription_items')->insert([
            'id' => PrefixedUlid::make('ITM'), 'company_id' => $this->companyId, 'subscription_id' => $subscriptionId,
            'module_id' => $hearingModule->id, 'name_snapshot' => $hearingModule->name, 'quantity' => 1,
            'unit_price_snapshot' => $hearingModule->monthly_price, 'conditions_snapshot' => '{}',
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

    private function createHearing(?string $case = null): string
    {
        $this->login();

        return $this->postJson('/api/law/hearings', ['title' => 'Synthetic hearing', 'hearing_type' => 'instruction', 'scheduled_at' => '2027-01-10 12:00:00', 'modality' => 'virtual', 'law_case_id' => $case])->assertCreated()->json('id');
    }

    public function test_existing_api_lifecycle_has_version_conflicts_and_history(): void
    {
        $id = $this->createHearing();
        $this->patchJson('/api/law/hearings/'.$id, ['version' => 1, 'title' => 'Updated'])->assertOk()->assertJsonPath('version', 2);
        $this->patchJson('/api/law/hearings/'.$id, ['version' => 1, 'title' => 'Stale'])->assertConflict();
        $this->postJson('/api/law/hearings/'.$id.'/status', ['version' => 1, 'status' => 'cancelled'])->assertConflict();
        $this->postJson('/api/law/hearings/'.$id.'/status', ['version' => 2, 'status' => 'cancelled'])->assertOk()->assertJsonPath('version', 3);
        $this->assertSame(2, DB::table('law_hearing_status_history')->where('law_hearing_id', $id)->count());
        $this->assertDatabaseHas('law_hearing_status_history', ['law_hearing_id' => $id, 'previous_status' => 'scheduled', 'new_status' => 'cancelled']);
    }

    public function test_missing_or_invalid_version_does_not_mutate_existing_hearing_contract(): void
    {
        $id = $this->createHearing();
        $before = (array) DB::table('law_hearings')->where('id', $id)->first();
        $history = DB::table('law_hearing_status_history')->where('law_hearing_id', $id)->count();
        foreach ([[], ['version' => null], ['version' => 0], ['version' => 'invalid']] as $version) {
            $this->patchJson('/api/law/hearings/'.$id, $version + ['title' => 'Do not save'])->assertUnprocessable();
            $this->postJson('/api/law/hearings/'.$id.'/status', $version + ['status' => 'cancelled'])->assertUnprocessable();
        }
        $this->assertSame($before, (array) DB::table('law_hearings')->where('id', $id)->first());
        $this->assertSame($history, DB::table('law_hearing_status_history')->where('law_hearing_id', $id)->count());
    }

    public function test_linked_case_requires_process_module_and_revoked_named_access_is_hidden(): void
    {
        $case = $this->makeCase('cnj_5');
        $this->grant($case);
        $id = $this->createHearing($case);
        $standalone = $this->createHearing();
        DB::table('law_confidential_case_accesses')->where('law_case_id', $case)->update(['revoked_at' => now()]);
        $this->getJson('/api/law/hearings/'.$id)->assertNotFound();
        $this->getJson('/api/law/hearings')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/law/hearings/'.$standalone)->assertOk();
        DB::table('law_confidential_case_accesses')->where('law_case_id', $case)->update(['revoked_at' => null]);
        DB::table('subscription_items')->whereIn('module_id', DB::table('modules')->where('module_code', 'processos')->select('id'))->update(['deleted_at' => now()]);
        $this->getJson('/api/law/hearings/'.$id)->assertNotFound();
        $this->getJson('/api/law/hearings/'.$standalone)->assertOk();
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
