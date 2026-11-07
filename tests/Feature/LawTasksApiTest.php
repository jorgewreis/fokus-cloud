<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LawAuthorizationService;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class LawTasksApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $companyId;

    private string $unitId;

    private array $roles;

    protected function setUp(): void
    {
        parent::setUp();
        config(['law-features.tasks_enabled' => true]);
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
        $contactsModule = DB::table('modules')->where('product_id', $product->id)->where('module_code', 'tarefas')
            ->where('context_code', 'escritorio')->where('status', 'ativo')->where('publication_state', 'publicado')->firstOrFail();
        $subscriptionId = PrefixedUlid::make('ASS');
        DB::table('subscriptions')->insert([
            'id' => $subscriptionId, 'company_id' => $this->companyId, 'product_id' => $product->id,
            'status' => 'ativa', 'open_company_product' => $this->companyId.'-'.$product->id, 'version' => 1,
            'billing_cycle' => 'monthly', 'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth(),
            'commercial_snapshot' => json_encode(['segment' => 'advocacia']), 'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscription_items')->insert([
            'id' => PrefixedUlid::make('ITM'), 'company_id' => $this->companyId, 'subscription_id' => $subscriptionId,
            'module_id' => $contactsModule->id, 'name_snapshot' => $contactsModule->name, 'quantity' => 1,
            'unit_price_snapshot' => $contactsModule->monthly_price, 'conditions_snapshot' => json_encode(['segment_code' => 'advocacia']),
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

    private function createTask(array $extra = []): array
    {
        return $this->actingAs($this->admin)->withSession(['active_company_id' => $this->companyId])->postJson('/api/law/tasks', $extra + ['title' => 'Tarefa sintética', 'request_key' => (string) Str::uuid()])->assertCreated()->json('task');
    }

    public function test_feature_flag_and_contract_are_required(): void
    {
        $this->actingAs($this->admin)->withSession(['active_company_id' => $this->companyId]);
        config(['law-features.tasks_enabled' => false]);
        $this->getJson('/api/law/tasks')->assertNotFound();
        config(['law-features.tasks_enabled' => true]);
        DB::table('subscription_items')->where('company_id', $this->companyId)->update(['deleted_at' => now()]);
        $this->getJson('/api/law/tasks')->assertForbidden();
    }

    public function test_idempotency_replay_changes_and_delete_tombstone(): void
    {
        $key = (string) Str::uuid();
        $task = $this->createTask(['request_key' => $key]);
        $this->postJson('/api/law/tasks', ['title' => 'Tarefa sintética', 'request_key' => $key])->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('task.id', $task['id']);
        $this->postJson('/api/law/tasks', ['title' => 'Alterada', 'request_key' => $key])->assertConflict();
        $this->assertDatabaseCount('law_tasks', 1);
        $this->assertDatabaseCount('law_task_events', 1);
        $this->deleteJson('/api/law/tasks/'.$task['id'], ['version' => 1])->assertNoContent();
        $this->getJson('/api/law/tasks/'.$task['id'])->assertNotFound();
        $this->postJson('/api/law/tasks', ['title' => 'Tarefa sintética', 'request_key' => $key])->assertConflict();
        $this->assertDatabaseCount('law_tasks', 1);
        $this->assertDatabaseCount('law_task_events', 2);
    }

    public function test_optimistic_version_and_payload_validation(): void
    {
        $task = $this->createTask();
        $url = '/api/law/tasks/'.$task['id'];
        $this->patchJson($url, ['version' => 1, 'title' => 'Atualizada'])->assertOk()->assertJsonPath('task.version', 2);
        $this->patchJson($url, ['version' => 1, 'title' => 'Perdida'])->assertConflict();
        $this->postJson($url.'/status', ['version' => 2, 'status' => 'invalid'])->assertUnprocessable();
        $this->postJson($url.'/status', ['version' => 2, 'status' => 'completed'])->assertOk()->assertJsonPath('task.version', 3);
        $this->deleteJson($url, ['version' => 2])->assertConflict();
        foreach ([['title' => str_repeat('a', 181)], ['description' => str_repeat('a', 5001)], ['due_on' => '2026-02-30'], ['company_id' => 'arbitrary'], ['law_unit_id' => $this->unitId], ['responsible_membership_id' => str_repeat('x', 31)]] as $bad) {
            $this->patchJson($url, $bad + ['version' => 3])->assertUnprocessable();
        }
    }

    public function test_unavailable_responsible_can_only_be_preserved_on_current_task(): void
    {
        $member = $this->user('Responsável', '52998224725', 'responsible@example.test');
        $mid = $this->membership($member, 'admin');
        $task = $this->createTask(['responsible_membership_id' => $mid]);
        $url = '/api/law/tasks/'.$task['id'];
        DB::table('company_memberships')->where('id', $mid)->update(['status' => 'suspenso']);
        $this->getJson('/api/law/tasks/references')->assertOk()->assertJsonMissing(['id' => $mid]);
        $this->patchJson($url, ['version' => 1, 'title' => 'Só título'])->assertOk()->assertJsonPath('task.responsible_membership_id', $mid);
        $this->patchJson($url, ['version' => 2, 'title' => 'Preservada', 'responsible_membership_id' => $mid])->assertOk()->assertJsonPath('task.responsible_membership_id', $mid);
        foreach (DB::table('law_task_events')->where('event_type', 'updated')->get() as $event) {
            $this->assertArrayNotHasKey('responsible_membership_id', json_decode($event->after_state, true));
        }
        $this->postJson('/api/law/tasks', ['title' => 'Nova inválida', 'responsible_membership_id' => $mid, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->patchJson($url, ['version' => 3, 'responsible_membership_id' => null])->assertOk()->assertJsonPath('task.responsible_membership_id', null);
        $event = DB::table('law_task_events')->where('law_task_id', $task['id'])->orderByDesc('id')->first();
        $this->assertSame(['responsible_membership_id' => null], json_decode($event->after_state, true));
        $this->patchJson($url, ['version' => 4, 'responsible_membership_id' => $mid])->assertUnprocessable();
        DB::table('company_memberships')->where('id', $mid)->update(['status' => 'ativo', 'deleted_at' => now()]);
        $this->patchJson($url, ['version' => 4, 'responsible_membership_id' => $mid])->assertUnprocessable();
        DB::table('company_memberships')->where('id', $mid)->update(['deleted_at' => null]);
        DB::table('users')->where('id', $member->id)->update(['email_verified_at' => null]);
        $this->patchJson($url, ['version' => 4, 'responsible_membership_id' => $mid])->assertUnprocessable();
        DB::table('users')->where('id', $member->id)->update(['email_verified_at' => now()]);
        $this->patchJson($url, ['version' => 4, 'responsible_membership_id' => $mid])->assertOk()->assertJsonPath('task.responsible_membership_id', $mid);
        $event = DB::table('law_task_events')->where('law_task_id', $task['id'])->orderByDesc('id')->first();
        $this->assertSame(['responsible_membership_id' => $mid], json_decode($event->after_state, true));
    }

    public function test_viewer_needs_explicit_grant_and_cannot_write_or_bypass_unit(): void
    {
        $task = $this->createTask();
        $viewer = $this->user('Viewer', '52998224725', 'task-viewer@example.test');
        $mid = $this->membership($viewer, 'usuario');
        $this->lawMembership($mid, $this->roles['viewer']);
        DB::table('law_user_active_units')->insert(['company_id' => $this->companyId, 'user_id' => $viewer->id, 'law_unit_id' => $this->unitId, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($viewer)->getJson('/api/law/tasks')->assertForbidden();
        DB::table('law_access_role_permissions')->insert(['law_access_role_id' => $this->roles['viewer'], 'customer_permission_id' => DB::table('customer_permissions')->where('code', 'law.tasks.view')->value('id')]);
        $this->getJson('/api/law/tasks')->assertOk()->assertJsonPath('total', 1);
        $this->patchJson('/api/law/tasks/'.$task['id'], ['title' => 'Forbidden', 'version' => 1])->assertForbidden();
        DB::table('law_unit_memberships')->where('company_membership_id', $mid)->update(['status' => 'inativo']);
        $this->getJson('/api/law/tasks')->assertForbidden();
    }

    public function test_pagination_reaches_past_200_and_independent_tasks_need_no_process_module(): void
    {
        $task = $this->createTask();
        $row = (array) DB::table('law_tasks')->where('id', $task['id'])->first();
        for ($i = 0; $i < 205; $i++) {
            DB::table('law_tasks')->insert(array_replace($row, ['id' => PrefixedUlid::make('LTA'), 'request_key' => null, 'request_fingerprint' => null]));
        }
        $this->getJson('/api/law/tasks?per_page=100&page=3')->assertOk()->assertJsonPath('total', 206)->assertJsonCount(6, 'data');
        $this->getJson('/api/law/tasks?per_page=101')->assertUnprocessable();
        $this->getJson('/api/law/tasks/'.$task['id'])->assertOk();
        $this->patchJson('/api/law/tasks/'.$task['id'], ['version' => 1, 'title' => 'Sem processos'])->assertOk();
    }

    public function test_cross_tenant_and_wrong_unit_tasks_are_hidden(): void
    {
        $task = $this->createTask();
        $other = PrefixedUlid::make('COM');
        $company = (array) DB::table('companies')->where('id', $this->companyId)->first();
        DB::table('companies')->insert(array_replace($company, ['id' => $other, 'document_number' => '52998224725']));
        $unit = PrefixedUlid::make('LUN');
        DB::table('law_units')->insert(['id' => $unit, 'company_id' => $other, 'name' => 'Outro tenant', 'status' => 'ativo', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $row = (array) DB::table('law_tasks')->where('id', $task['id'])->first();
        $otherTask = PrefixedUlid::make('LTA');
        DB::table('law_tasks')->insert(array_replace($row, ['id' => $otherTask, 'company_id' => $other, 'law_unit_id' => $unit, 'request_key' => null]));
        foreach ([$otherTask, 'nonexistent'] as $id) {
            $this->getJson('/api/law/tasks/'.$id)->assertNotFound();
            $this->patchJson('/api/law/tasks/'.$id, ['version' => 1, 'title' => 'x'])->assertNotFound();
        }
        $localUnit = PrefixedUlid::make('LUN');
        DB::table('law_units')->insert(['id' => $localUnit, 'company_id' => $this->companyId, 'name' => 'Outra unidade', 'status' => 'ativo', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('law_user_active_units')->where('user_id', $this->admin->id)->update(['law_unit_id' => $localUnit]);
        $this->getJson('/api/law/tasks/'.$task['id'])->assertNotFound();
    }

    public function test_linked_task_visibility_revocation_immutable_link_and_module_loss(): void
    {
        $independent = $this->createTask();
        $module = DB::table('modules')->where('module_code', 'processos')->where('context_code', 'escritorio')->where('publication_state', 'publicado')->firstOrFail();
        $item = (array) DB::table('subscription_items')->where('company_id', $this->companyId)->first();
        $itemId = PrefixedUlid::make('ITM');
        DB::table('subscription_items')->insert(array_replace($item, ['id' => $itemId, 'module_id' => $module->id]));
        $case = PrefixedUlid::make('LCA');
        DB::table('law_cases')->insert(['id' => $case, 'company_id' => $this->companyId, 'law_unit_id' => $this->unitId, 'case_number' => '10016228320244013301', 'confidentiality_level' => 'public', 'internal_confidentiality_level' => 'cnj_5', 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/law/tasks', ['title' => 'Restrita', 'law_case_id' => $case, 'request_key' => (string) Str::uuid()])->assertNotFound();
        $mid = DB::table('company_memberships')->where('company_id', $this->companyId)->where('user_id', $this->admin->id)->value('id');
        DB::table('law_confidential_case_accesses')->insert(['id' => PrefixedUlid::make('LCA'), 'company_id' => $this->companyId, 'law_case_id' => $case, 'company_membership_id' => $mid, 'granted_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $task = $this->createTask(['law_case_id' => $case]);
        $url = '/api/law/tasks/'.$task['id'];
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'events');
        $this->patchJson($url, ['version' => 1, 'law_case_id' => null])->assertUnprocessable();
        $this->getJson('/api/law/tasks/references?case_q=1001622')->assertOk()->assertJsonPath('cases.total', 1);
        DB::table('law_confidential_case_accesses')->where('law_case_id', $case)->update(['revoked_at' => now()]);
        $this->getJson('/api/law/tasks')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/law/tasks/references')->assertOk()->assertJsonPath('cases.total', 0);
        $this->getJson($url)->assertNotFound();
        $this->patchJson($url, ['version' => 1, 'title' => 'x'])->assertNotFound();
        $this->postJson($url.'/status', ['version' => 1, 'status' => 'completed'])->assertNotFound();
        $this->deleteJson($url, ['version' => 1])->assertNotFound();
        DB::table('law_confidential_case_accesses')->where('law_case_id', $case)->update(['revoked_at' => null]);
        DB::table('subscription_items')->where('id', $itemId)->update(['deleted_at' => now()]);
        $this->getJson($url)->assertNotFound();
        $this->getJson('/api/law/tasks/'.$independent['id'])->assertOk();
        $this->patchJson('/api/law/tasks/'.$independent['id'], ['version' => 1, 'title' => 'Continua independente'])->assertOk();
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
