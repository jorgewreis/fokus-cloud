<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LawAuthorizationService;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LawPermissionsTest extends TestCase
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
        $contactsModule = DB::table('modules')->where('product_id', $product->id)->where('module_code', 'contatos')
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

    public function test_permissions_are_enforced_on_direct_api_calls_and_scoped_to_the_active_unit(): void
    {
        $viewer = $this->user('Visualizador', '52998224725', 'viewer@example.test');
        $membershipId = $this->membership($viewer, 'usuario');
        $this->lawMembership($membershipId, $this->roles['viewer']);
        DB::table('law_user_active_units')->insert([
            'user_id' => $viewer->id, 'company_id' => $this->companyId, 'law_unit_id' => $this->unitId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $session = ['active_company_id' => $this->companyId];
        $this->actingAs($viewer)->withSession($session)->getJson('/api/law/notifications')->assertOk();
        $this->actingAs($viewer)->withSession($session)->patchJson('/api/law/notifications/NOT-inexistente/read')->assertForbidden();
        $this->actingAs($viewer)->withSession($session)->getJson('/api/portal/users')->assertForbidden();

        $otherUnit = PrefixedUlid::make('LUN');
        DB::table('law_units')->insert([
            'id' => $otherUnit, 'company_id' => $this->companyId, 'name' => 'Outro setor',
            'status' => 'ativo', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($viewer)->withSession($session)->postJson('/api/law/active-unit', ['unit_id' => $otherUnit])->assertForbidden();
    }

    public function test_local_role_manager_cannot_escalate_permissions_or_edit_protected_roles(): void
    {
        $unitAdmin = $this->user('Admin local', '52998224725', 'unit-admin@example.test');
        $membershipId = $this->membership($unitAdmin, 'gestor');
        $this->lawMembership($membershipId, $this->roles['unit_admin']);
        DB::table('law_user_active_units')->insert([
            'user_id' => $unitAdmin->id, 'company_id' => $this->companyId, 'law_unit_id' => $this->unitId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $session = ['active_company_id' => $this->companyId];

        $this->actingAs($unitAdmin)->withSession($session)->patchJson('/api/law/access/roles/'.$this->roles['operator'], [
            'name' => 'Nome alterado', 'version' => 1,
        ])->assertUnprocessable();

        $this->actingAs($unitAdmin)->withSession($session)->postJson('/api/law/access/roles', [
            'law_unit_id' => $this->unitId, 'name' => 'Perfil acima do alcance',
            'permission_codes' => ['law.subscription.manage'],
        ])->assertForbidden();

        $this->actingAs($unitAdmin)->withSession($session)->postJson('/api/law/access/roles', [
            'law_unit_id' => $this->unitId, 'name' => 'Perfil operacional',
            'permission_codes' => ['law.contacts.view'],
        ])->assertCreated();
    }

    public function test_user_directory_does_not_return_full_cpf_and_invite_requires_explicit_unit_role(): void
    {
        $session = ['active_company_id' => $this->companyId];
        $this->actingAs($this->admin)->withSession($session)->getJson('/api/portal/users')
            ->assertOk()->assertJsonMissingPath('0.cpf');

        $this->actingAs($this->admin)->withSession($session)->postJson('/api/portal/users', [
            'name' => 'Pessoa convidada', 'cpf' => '52998224725', 'email' => 'invite@example.test',
        ])->assertUnprocessable();

        $this->actingAs($this->admin)->withSession($session)->postJson('/api/portal/users', [
            'name' => 'Pessoa convidada', 'cpf' => '52998224725', 'email' => 'invite@example.test',
            'law_assignments' => [['unit_id' => $this->unitId, 'role_id' => $this->roles['operator']]],
        ])->assertCreated();
        $membership = DB::table('company_memberships')->where('company_id', $this->companyId)->where('status', 'pendente')->first();
        $this->assertNotNull($membership);
        $this->assertDatabaseHas('law_unit_memberships', [
            'company_membership_id' => $membership->id, 'law_unit_id' => $this->unitId,
            'law_access_role_id' => $this->roles['operator'], 'status' => 'pendente',
        ]);
        $this->assertDatabaseMissing('law_unit_memberships', [
            'company_membership_id' => $membership->id, 'status' => 'ativo',
        ]);
    }

    public function test_transfer_acceptance_keeps_previous_admin_as_operator_when_requested(): void
    {
        $target = $this->user('Sucessor', '52998224725', 'successor@example.test');
        $targetMembership = $this->membership($target, 'usuario');
        DB::table('company_memberships')->where('id', DB::table('company_memberships')->where('user_id', $this->admin->id)->value('id'))
            ->update(['active_admin_company_id' => $this->companyId]);
        $token = $this->transferToken($target, $targetMembership, true);

        $this->postJson('/api/auth/accept-admin-transfer', ['token' => $token])->assertOk();

        $this->assertDatabaseHas('company_memberships', [
            'user_id' => $this->admin->id, 'company_id' => $this->companyId,
            'role_id' => DB::table('roles')->where('code', 'usuario')->value('id'), 'status' => 'ativo', 'active_admin_company_id' => null,
        ]);
        $this->assertDatabaseHas('company_memberships', [
            'id' => $targetMembership, 'role_id' => DB::table('roles')->where('code', 'admin')->value('id'),
            'status' => 'ativo', 'active_admin_company_id' => $this->companyId,
        ]);
        $oldMembershipId = DB::table('company_memberships')->where('user_id', $this->admin->id)->where('company_id', $this->companyId)->value('id');
        $this->assertDatabaseHas('law_unit_memberships', [
            'company_membership_id' => $oldMembershipId, 'law_unit_id' => $this->unitId,
            'law_access_role_id' => $this->roles['operator'], 'status' => 'ativo',
        ]);
    }

    public function test_transfer_acceptance_removes_previous_admin_when_access_is_not_kept(): void
    {
        $target = $this->user('Sucessor', '52998224725', 'successor@example.test');
        $targetMembership = $this->membership($target, 'usuario');
        $oldMembershipId = DB::table('company_memberships')->where('user_id', $this->admin->id)->where('company_id', $this->companyId)->value('id');
        DB::table('company_memberships')->where('id', $oldMembershipId)->update(['active_admin_company_id' => $this->companyId]);
        $token = $this->transferToken($target, $targetMembership, false);

        $this->postJson('/api/auth/accept-admin-transfer', ['token' => $token])->assertOk();

        $this->assertDatabaseHas('company_memberships', ['id' => $oldMembershipId, 'status' => 'removido', 'active_admin_company_id' => null]);
        $this->assertDatabaseHas('company_memberships', [
            'id' => $targetMembership, 'role_id' => DB::table('roles')->where('code', 'admin')->value('id'),
            'status' => 'ativo', 'active_admin_company_id' => $this->companyId,
        ]);
        $this->assertDatabaseMissing('law_unit_memberships', ['company_membership_id' => $oldMembershipId, 'status' => 'ativo']);
    }

    public function test_transfer_can_be_declined_without_changing_admin_and_is_audited(): void
    {
        $target = $this->user('Sucessor', '52998224725', 'successor@example.test');
        $targetMembership = $this->membership($target, 'usuario');
        $oldMembershipId = DB::table('company_memberships')->where('user_id', $this->admin->id)->where('company_id', $this->companyId)->value('id');
        DB::table('company_memberships')->where('id', $oldMembershipId)->update(['active_admin_company_id' => $this->companyId]);
        $token = $this->transferToken($target, $targetMembership, true);

        $this->postJson('/api/auth/decline-admin-transfer', ['token' => $token])->assertOk()
            ->assertJsonPath('message', 'A transferência foi recusada. O admin atual permanece responsável pela empresa.');

        $this->assertDatabaseHas('company_memberships', ['id' => $oldMembershipId, 'role_id' => DB::table('roles')->where('code', 'admin')->value('id'), 'status' => 'ativo', 'active_admin_company_id' => $this->companyId]);
        $this->assertDatabaseHas('company_memberships', ['id' => $targetMembership, 'role_id' => DB::table('roles')->where('code', 'usuario')->value('id'), 'status' => 'ativo', 'active_admin_company_id' => null]);
        $this->assertDatabaseHas('audit_events', ['company_id' => $this->companyId, 'entity_type' => 'company_membership', 'entity_id' => $targetMembership, 'operation' => 'admin_transfer_declined']);
    }

    public function test_permissions_migration_is_safe_to_rerun_and_maps_legacy_gestores_once(): void
    {
        $manager = $this->user('Gestor legado', '52998224725', 'legacy-manager@example.test');
        $membershipId = $this->membership($manager, 'gestor');
        $migration = require database_path('migrations/2026_09_28_000100_create_customer_law_permissions.php');

        $migration->up();
        $migration->up();

        $this->assertDatabaseCount('customer_permissions', 22);
        $this->assertDatabaseCount('law_access_roles', 4);
        $this->assertDatabaseCount('law_unit_memberships', 1);
        $roleId = DB::table('law_unit_memberships')->where('company_membership_id', $membershipId)->value('law_access_role_id');
        $this->assertDatabaseHas('law_access_roles', ['id' => $roleId, 'code' => 'chief_clerk', 'is_system' => true]);
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

    private function transferToken(User $target, string $targetMembership, bool $keepAccess): string
    {
        $plain = bin2hex(random_bytes(32));
        DB::table('security_tokens')->insert([
            'id' => PrefixedUlid::make('TKN'), 'user_id' => $target->id, 'purpose' => 'admin_transfer',
            'token_hash' => hash('sha256', $plain), 'payload' => json_encode([
                'company_id' => $this->companyId,
                'from_membership_id' => DB::table('company_memberships')->where('user_id', $this->admin->id)->where('company_id', $this->companyId)->value('id'),
                'to_membership_id' => $targetMembership, 'keep_previous_access' => $keepAccess,
            ]), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $plain;
    }
}
