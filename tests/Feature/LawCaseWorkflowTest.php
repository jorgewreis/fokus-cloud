<?php

namespace Tests\Feature;

use App\Jobs\SyncLawCaseDatajud;
use App\Jobs\SyncLawCaseDjen;
use App\Models\User;
use App\Services\LawAuthorizationService;
use App\Services\LawCaseManagementService;
use App\Services\LawCaseWorkflow;
use App\Services\LawDjenClient;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LawCaseWorkflowTest extends TestCase
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

    private function createCase(): string
    {
        $id = $this->makeCase();
        DB::table('law_cases')->where('id', $id)->update(['case_number' => '80106693520268050103']);
        $this->login();

        return $id;
    }

    public function test_portfolio_preview_does_not_write_and_confirmation_revalidates_without_overwrite_or_grants(): void
    {
        Bus::fake();
        $this->login();
        $payload = ['numbers' => ['80106693520268050103', '80106693520268050103', 'bad'], 'law_unit_id' => $this->unitId, 'internal_confidentiality_level' => 'secret', 'confirmed' => false];
        $before = DB::table('law_case_events')->count();
        $this->postJson('/api/law/cases/portfolio/import', $payload)->assertOk()->assertJsonPath('summary.new', 1)->assertJsonPath('summary.duplicate', 1)->assertJsonPath('summary.invalid', 1);
        $this->assertDatabaseCount('law_cases', 0);
        $this->assertSame($before, DB::table('law_case_events')->count());
        Bus::assertNothingDispatched();
        $payload['confirmed'] = true;
        $this->postJson('/api/law/cases/portfolio/import', $payload)->assertOk()->assertJsonPath('created_count', 1);
        $row = DB::table('law_cases')->first();
        $this->assertSame('secret', $row->internal_confidentiality_level);
        $this->assertDatabaseCount('law_confidential_case_accesses', 0);
        $this->postJson('/api/law/cases/portfolio/import', $payload)->assertOk()->assertJsonPath('created_count', 0)->assertJsonPath('summary.existing', 1);
        $this->assertEquals($row, DB::table('law_cases')->first());
        Bus::assertDispatchedTimes(SyncLawCaseDatajud::class, 1);
        Bus::assertDispatchedTimes(SyncLawCaseDjen::class, 1);
    }

    public function test_review_version_zero_then_cas_and_audit_preserve_internal_notes(): void
    {
        $id = $this->createCase();
        DB::table('law_cases')->where('id', $id)->update(['djen_metadata' => json_encode(['publications' => [['id' => 'one', 'excerpt' => 'Resumo']]])]);
        $first = $this->getJson('/api/law/cases/'.$id)->assertOk()->json('workflow.publications.0');
        $this->assertSame(0, $first['version']);
        $this->assertTrue($first['content_identity_unverified']);
        $url = '/api/law/cases/'.$id.'/publications/'.$first['key'];
        $this->patchJson($url, ['status' => 'pending', 'version' => 0, 'next_action' => 'Nota interna'])->assertOk()->assertJsonPath('workflow.publications.0.version', 1);
        $this->patchJson($url, ['status' => 'reviewed', 'version' => 0])->assertConflict();
        $this->patchJson($url, ['status' => 'reviewed', 'version' => 1])->assertOk()->assertJsonPath('workflow.publications.0.next_action', 'Nota interna');
        $this->assertDatabaseCount('law_case_publication_reviews', 1);
        $this->assertSame(2, DB::table('law_case_events')->where('event_type', 'publication_review_updated')->count());
        $this->assertStringNotContainsString('Nota interna', DB::table('law_cases')->where('id', $id)->value('djen_metadata'));
        DB::table('law_cases')->where('id', $id)->update(['internal_confidentiality_level' => 'secret', 'confidentiality_level' => 'secret']);
        $this->patchJson($url, ['status' => 'pending', 'version' => 2])->assertNotFound();
    }

    public function test_projection_is_idempotent_preserves_reviews_and_legacy_columns(): void
    {
        $id = $this->createCase();
        DB::table('law_cases')->where('id', $id)->update(['datajud_metadata' => json_encode(['recent_movements' => [['occurred_at' => '2026-11-01T13:00:00-03:00'], ['occurred_at' => '2026-11-04T12:30:00Z'], ['occurred_at' => 'invalid']]]), 'djen_metadata' => json_encode(['publications' => [['hash' => 'A'], ['hash' => 'B'], ['id' => 'C']]])]);
        $before = (array) DB::table('law_cases')->where('id', $id)->first();
        LawCaseWorkflow::refreshProjection($this->companyId, $id);
        $this->assertDatabaseCount('law_case_publication_reviews', 3);
        DB::table('law_case_publication_reviews')->where('law_case_id', $id)->update(['status' => 'reviewed', 'next_action' => 'Preservar', 'version' => 4]);
        $reviews = DB::table('law_case_publication_reviews')->orderBy('id')->get()->toJson();
        LawCaseWorkflow::refreshProjection($this->companyId, $id);
        $this->assertSame($reviews, DB::table('law_case_publication_reviews')->orderBy('id')->get()->toJson());
        $after = (array) DB::table('law_cases')->where('id', $id)->first();
        $this->assertSame('2026-11-04 12:30:00', $after['last_official_movement_at']);
        unset($before['last_official_movement_at'], $after['last_official_movement_at']);
        $this->assertSame($before, $after);
    }

    public function test_migration_down_preflight_keeps_index_column_and_reviews_then_resumes_partial_schema(): void
    {
        $id = $this->createCase();
        DB::table('law_cases')->where('id', $id)->update(['djen_metadata' => json_encode(['publications' => [['id' => 'one']]])]);
        LawCaseWorkflow::refreshProjection($this->companyId, $id);
        $migration = require database_path('migrations/2026_11_06_140000_create_law_case_publication_reviews.php');
        Schema::rename('law_case_publication_reviews', 'reviews_hold');
        try {
            $migration->down();
            $this->fail('Expected fail-closed preflight.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Review data is already missing', $error->getMessage());
        } finally {
            Schema::rename('reviews_hold', 'law_case_publication_reviews');
        }
        $this->assertTrue(Schema::hasIndex('law_cases', 'law_cases_last_official_movement_at_index'));
        $this->assertDatabaseCount('law_case_publication_reviews', 1);
        Schema::table('law_cases', fn ($table) => $table->dropIndex('law_cases_last_official_movement_at_index'));
        $migration->down();
        $this->assertFalse(Schema::hasTable('law_case_publication_reviews'));
        $migration->down(); // Fully reversed schema with retained ledger is resumable.
        $migration->up();
        $this->assertDatabaseCount('law_case_publication_reviews', 1);
    }

    public function test_first_full_text_version_preserves_both_historical_key_formats_without_inheriting_review(): void
    {
        $id = $this->createCase();
        $legacy = ['id' => 'same-id', 'hash' => 'provider-stable', 'excerpt' => 'Mesmo resumo'];
        $legacyKeys = [
            LawCaseWorkflow::legacyPublicationKey($legacy),
            LawCaseWorkflow::previousPublicationKey($legacy),
        ];
        foreach ($legacyKeys as $key) {
            DB::table('law_case_publication_reviews')->insert([
                'id' => PrefixedUlid::make('LPR'), 'company_id' => $this->companyId, 'law_case_id' => $id,
                'publication_key' => $key, 'status' => 'reviewed', 'next_action' => 'Nota preservada',
                'reviewed_at' => now(), 'version' => 4, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $current = $legacy + ['content_fingerprint_version' => 1, 'content_fingerprint' => hash('sha256', 'Mesmo resumo')];
        DB::table('law_cases')->where('id', $id)->update(['djen_metadata' => json_encode(['publications' => [$current]])]);
        LawCaseWorkflow::refreshProjection($this->companyId, $id);
        LawCaseWorkflow::refreshProjection($this->companyId, $id);
        $rows = collect($this->getJson('/api/law/cases/'.$id)->assertOk()->assertJsonCount(3, 'workflow.publications')->json('workflow.publications'));
        $active = $rows->firstWhere('key', LawCaseWorkflow::publicationKey($current));
        $this->assertSame('pending', $active['status']);
        $this->assertEqualsCanonicalizing($legacyKeys, $active['previous_publication_keys']);
        foreach ($legacyKeys as $key) {
            $historical = $rows->firstWhere('key', $key);
            $this->assertSame('reviewed', $historical['status']);
            $this->assertSame('Nota preservada', $historical['next_action']);
            $this->assertSame(4, $historical['version']);
            $this->assertTrue($historical['legacy_identity_unverified']);
        }
    }

    public function test_reviewed_full_text_becomes_pending_after_change_beyond_excerpt_and_is_idempotent(): void
    {
        $id = $this->createCase();
        $item = ['id' => 'same', 'hash' => 'stable', 'numeroProcesso' => '80106693520268050103'];
        $original = $item + ['texto' => str_repeat('a', 1100).' original'];
        $changed = $item + ['texto' => str_repeat('a', 1100).' corrigido'];
        Http::fake(['*' => Http::sequence()
            ->push(['count' => 1, 'items' => [$original]])
            ->push(['count' => 1, 'items' => [$changed]])
            ->push(['count' => 2, 'items' => [$changed, $changed]])]);
        $sync = fn () => app(LawCaseManagementService::class)->syncDjen(DB::table('law_cases')->where('id', $id)->first(), app(LawDjenClient::class));
        $this->assertSame('synced', $sync()['status']);
        $first = $this->getJson('/api/law/cases/'.$id)->assertOk()->json('workflow.publications.0');
        $this->patchJson('/api/law/cases/'.$id.'/publications/'.$first['key'], ['status' => 'reviewed', 'version' => $first['version']])->assertOk();
        $this->assertSame('synced', $sync()['status']);
        $this->assertSame('synced', $sync()['status']);
        $rows = collect($this->getJson('/api/law/cases/'.$id)->assertOk()->assertJsonCount(2, 'workflow.publications')->json('workflow.publications'));
        $this->assertSame('reviewed', $rows->firstWhere('key', $first['key'])['status']);
        $this->assertTrue($rows->firstWhere('key', $first['key'])['missing_from_current_source']);
        $this->assertSame('pending', $rows->firstWhere('key', '!=', $first['key'])['status']);
        $this->assertSame(2, DB::table('law_case_publication_reviews')->where('law_case_id', $id)->count());
    }

    public function test_insufficient_identity_response_preserves_previous_publications_and_reviews(): void
    {
        $id = $this->createCase();
        $base = ['numeroProcesso' => '80106693520268050103'];
        Http::fake(['*' => Http::sequence()
            ->push(['count' => 1, 'items' => [$base + ['id' => 'verified', 'texto' => 'Texto integral original']]])
            ->push(['count' => 2, 'items' => [
                $base + ['tipoComunicacao' => 'Intimação', 'nomeOrgao' => 'Vara A'],
                $base + ['tipoComunicacao' => 'Intimação', 'nomeOrgao' => 'Vara B'],
            ]])]);
        $sync = fn () => app(LawCaseManagementService::class)->syncDjen(DB::table('law_cases')->where('id', $id)->first(), app(LawDjenClient::class));
        $this->assertSame('synced', $sync()['status']);
        $first = $this->getJson('/api/law/cases/'.$id)->assertOk()->json('workflow.publications.0');
        $this->patchJson('/api/law/cases/'.$id.'/publications/'.$first['key'], ['status' => 'reviewed', 'version' => $first['version']])->assertOk();
        $before = DB::table('law_cases')->where('id', $id)->value('djen_metadata');
        $this->assertSame('error', $sync()['status']);
        $this->assertSame($before, DB::table('law_cases')->where('id', $id)->value('djen_metadata'));
        $row = $this->getJson('/api/law/cases/'.$id)->assertOk()->assertJsonCount(1, 'workflow.publications')->json('workflow.publications.0');
        $this->assertSame($first['key'], $row['key']);
        $this->assertSame('reviewed', $row['status']);
        $this->assertSame(1, DB::table('law_case_publication_reviews')->where('law_case_id', $id)->count());
    }

    public function test_populated_migration_backfills_24_reviews_without_touching_old_columns(): void
    {
        $migration = require database_path('migrations/2026_11_06_140000_create_law_case_publication_reviews.php');
        $migration->down();
        $snapshots = [];
        foreach (['public', 'cnj_1', 'cnj_2', 'cnj_3', 'cnj_4', 'cnj_5', 'secret', 'confidential'] as $level) {
            $id = $this->makeCase($level);
            DB::table('law_cases')->where('id', $id)->update(['djen_metadata' => json_encode(['publications' => [['id' => 'one'], ['hash' => 'two'], ['hash' => 'three']]]),
                'datajud_metadata' => json_encode(['recent_movements' => [['occurred_at' => '2026-11-04T12:30:00Z'], ['occurred_at' => 'invalid']]])]);
            $snapshots[$id] = (array) DB::table('law_cases')->where('id', $id)->first();
        }
        $migration->up();
        $this->assertDatabaseCount('law_case_publication_reviews', 24);
        $this->assertSame(24, DB::table('law_case_publication_reviews')->where('status', 'pending')->count());
        foreach ($snapshots as $id => $old) {
            $new = (array) DB::table('law_cases')->where('id', $id)->first();
            $this->assertSame('2026-11-04 12:30:00', $new['last_official_movement_at']);
            unset($new['last_official_movement_at']);
            $this->assertSame($old, $new);
        }
    }

    public function test_down_checks_extra_dependencies_before_any_ddl(): void
    {
        $migration = require database_path('migrations/2026_11_06_140000_create_law_case_publication_reviews.php');
        Schema::table('law_cases', fn ($table) => $table->index('last_official_movement_at', 'custom_projection_dependency'));
        try {
            $migration->down();
            $this->fail('Unexpected dependency was not rejected.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Unexpected index', $error->getMessage());
        }
        $this->assertTrue(Schema::hasIndex('law_cases', 'law_cases_last_official_movement_at_index'));
        $this->assertTrue(Schema::hasTable('law_case_publication_reviews'));
        Schema::table('law_cases', fn ($table) => $table->dropIndex('custom_projection_dependency'));
    }

    public function test_view_only_role_cannot_import_or_review_and_stale_preview_never_overwrites(): void
    {
        Bus::fake();
        $this->login();
        $payload = ['numbers' => ['80106693520268050103'], 'law_unit_id' => $this->unitId, 'internal_confidentiality_level' => 'public', 'confirmed' => false];
        $this->postJson('/api/law/cases/portfolio/import', $payload)->assertOk()->assertJsonPath('summary.new', 1);
        $id = $this->createCase();
        DB::table('law_cases')->where('id', $id)->update(['operational_priority' => 'urgent', 'djen_metadata' => json_encode(['publications' => [['id' => 'visible']]])]);
        $before = DB::table('law_cases')->where('id', $id)->first();
        $payload['confirmed'] = true;
        $this->postJson('/api/law/cases/portfolio/import', $payload)->assertOk()->assertJsonPath('created_count', 0)->assertJsonPath('summary.existing', 1);
        $this->assertEquals($before, DB::table('law_cases')->where('id', $id)->first());
        Bus::assertNothingDispatched();
        $viewer = $this->user('Viewer', '52998224725', 'workflow-viewer@example.test');
        $membership = $this->membership($viewer, 'usuario');
        $this->lawMembership($membership, $this->roles['viewer']);
        DB::table('law_user_active_units')->insert(['user_id' => $viewer->id, 'company_id' => $this->companyId, 'law_unit_id' => $this->unitId, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($viewer)->withSession(['active_company_id' => $this->companyId]);
        $key = $this->getJson('/api/law/cases/'.$id)->assertOk()->json('workflow.publications.0.key');
        $this->postJson('/api/law/cases/portfolio/import', $payload)->assertForbidden();
        $this->patchJson('/api/law/cases/'.$id.'/publications/'.$key, ['status' => 'reviewed', 'version' => 0])->assertForbidden();
        $this->assertDatabaseCount('law_case_publication_reviews', 0);
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
