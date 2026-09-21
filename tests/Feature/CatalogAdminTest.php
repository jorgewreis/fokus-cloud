<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CatalogAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
    }

    public function test_commercial_admin_can_create_an_active_product_but_cannot_generate_catalog_versions(): void
    {
        $admin = $this->admin('administrador_comercial');

        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/products', [
            'code' => 'academy',
            'name' => 'Fokus Cloud Academy',
            'status' => 'ativo',
        ])->assertCreated();

        $productId = DB::table('products')->where('code', 'academy')->value('id');
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'status' => 'ativo',
        ]);

        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/{$productId}/publish", [
            'reason' => 'Tentativa comercial.',
        ])->assertForbidden();
    }

    public function test_superadmin_publishes_a_versioned_public_catalog_snapshot(): void
    {
        $admin = $this->admin();
        $productId = DB::table('products')->where('code', 'law')->value('id');

        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/{$productId}/publish", [
            'reason' => 'Publicação homologada do Marco 3.',
        ])->assertOk()->assertJsonPath('version', 2);

        $this->assertDatabaseHas('catalog_publications', [
            'product_id' => $productId,
            'version' => 2,
            'published_by_platform_admin_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.catalog_published']);

        $this->getJson('/api/catalog/law')
            ->assertOk()
            ->assertJsonPath('contract_version', '0.1.0')
            ->assertJsonPath('published_version', 2)
            ->assertJsonStructure(['product', 'modules', 'plans', 'published_at']);
    }

    public function test_publication_refuses_active_plan_without_modules(): void
    {
        $admin = $this->admin();
        $productId = DB::table('products')->where('code', 'law')->value('id');

        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/plans', [
            'product_id' => $productId,
            'code' => 'law-sem-modulos',
            'name' => 'Sem módulos',
            'status' => 'ativo',
        ])->assertCreated();

        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/{$productId}/publish", [
            'reason' => 'Deve falhar.',
        ])->assertUnprocessable();
    }

    public function test_public_catalog_keeps_the_last_snapshot_when_a_plan_is_changed_as_draft(): void
    {
        $admin = $this->admin();
        $plan = DB::table('plans')->where('code', 'law-advocacia')->first();
        $before = $this->getJson('/api/catalog/law')->assertOk()->json();

        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/catalog/plans/{$plan->id}", [
            'name' => 'Advocacia Alterada',
            'status' => 'ativo',
        ])->assertOk();

        $after = $this->getJson('/api/catalog/law')->assertOk()->json();
        $this->assertSame($before['published_version'], $after['published_version']);
        $this->assertContains('Fokus Law - Advocacia', collect($after['plans'])->pluck('name')->all());
        $this->assertNotContains('Fokus Law - Advocacia Alterada', collect($after['plans'])->pluck('name')->all());
    }

    public function test_product_display_order_is_persisted_and_reflected_in_catalog_listing(): void
    {
        $admin = $this->admin();
        $lawId = DB::table('products')->where('code', 'law')->value('id');
        $leadId = DB::table('products')->where('code', 'lead')->value('id');

        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/catalog/products/{$lawId}", [
            'display_order' => 9,
        ])->assertOk();
        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/catalog/products/{$leadId}", [
            'display_order' => 1,
        ])->assertOk();

        $products = $this->actingAs($admin, 'platform')->getJson('/api/backoffice/catalog')
            ->assertOk()
            ->json('products');

        $this->assertSame('lead', $products[0]['code']);
        $this->assertSame(10, DB::table('products')->where('id', $lawId)->value('display_order'));
        $this->assertSame(1, DB::table('products')->where('id', $leadId)->value('display_order'));
    }

    public function test_product_display_order_collision_reorders_subsequent_products(): void
    {
        $admin = $this->admin();
        $lawId = DB::table('products')->where('code', 'law')->value('id');
        $leadId = DB::table('products')->where('code', 'lead')->value('id');

        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/catalog/products/{$leadId}", [
            'display_order' => 1,
        ])->assertOk();

        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/products', [
            'code' => 'academy',
            'name' => 'Fokus Cloud Academy',
            'status' => 'ativo',
            'display_order' => 1,
        ])->assertCreated();

        $products = $this->actingAs($admin, 'platform')->getJson('/api/backoffice/catalog')
            ->assertOk()
            ->json('products');

        $this->assertSame(['academy', 'lead', 'law'], collect($products)->pluck('code')->all());
        $this->assertSame(3, DB::table('products')->where('id', $lawId)->value('display_order'));
        $this->assertSame(2, DB::table('products')->where('id', $leadId)->value('display_order'));
    }

    public function test_superadmin_can_pause_public_items_but_commercial_admin_cannot(): void
    {
        $commercial = $this->admin('administrador_comercial');
        $super = $this->admin();
        $planId = DB::table('plans')->where('code', 'law-cartorio-criminal')->value('id');

        $this->actingAs($commercial, 'platform')->postJson("/api/backoffice/catalog/plans/{$planId}/pause", [
            'reason' => 'Sem permissão.',
        ])->assertForbidden();

        $this->actingAs($super, 'platform')->postJson("/api/backoffice/catalog/plans/{$planId}/pause", [
            'reason' => 'Pausa homologada.',
        ])->assertOk();

        $this->assertDatabaseHas('plans', [
            'id' => $planId,
            'status' => 'inativo',
            'publication_state' => 'pausado',
        ]);
    }

    public function test_superadmin_can_archive_modules_and_plans_but_commercial_admin_cannot(): void
    {
        $commercial = $this->admin('administrador_comercial');
        $super = $this->admin();
        $moduleId = DB::table('modules')->where('code', 'expedicoes-cartorio')->value('id');
        $planId = DB::table('plans')->where('code', 'law-cartorio-criminal')->value('id');

        $this->actingAs($commercial, 'platform')->postJson("/api/backoffice/catalog/modules/{$moduleId}/archive", [
            'reason' => 'Sem permissão.',
        ])->assertForbidden();

        $this->actingAs($super, 'platform')->postJson("/api/backoffice/catalog/modules/{$moduleId}/archive", [
            'reason' => 'Funcionalidade descontinuada.',
        ])->assertOk();

        $this->actingAs($super, 'platform')->postJson("/api/backoffice/catalog/plans/{$planId}/archive", [
            'reason' => 'Plano descontinuado.',
        ])->assertOk();

        $this->assertDatabaseHas('modules', [
            'id' => $moduleId,
            'status' => 'arquivado',
            'publication_state' => 'arquivado',
        ]);
        $this->assertDatabaseHas('plans', [
            'id' => $planId,
            'status' => 'inativo',
            'publication_state' => 'arquivado',
        ]);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.catalog_item_archived']);
    }

    public function test_superadmin_can_delete_a_catalog_publication_and_public_catalog_falls_back_to_previous_version(): void
    {
        $admin = $this->admin();
        $productId = DB::table('products')->where('code', 'law')->value('id');

        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/{$productId}/publish", [
            'reason' => 'Publicação temporária.',
        ])->assertOk()->assertJsonPath('version', 2);

        $publicationId = DB::table('catalog_publications')
            ->where('product_id', $productId)
            ->where('version', 2)
            ->value('id');

        $this->actingAs($this->admin('administrador_comercial'), 'platform')
            ->deleteJson("/api/backoffice/catalog/publications/{$publicationId}", ['reason' => 'Sem permissão.'])
            ->assertForbidden();

        $this->actingAs($admin, 'platform')
            ->deleteJson("/api/backoffice/catalog/publications/{$publicationId}", ['reason' => 'Remoção homologada.'])
            ->assertOk();

        $this->assertDatabaseMissing('catalog_publications', ['id' => $publicationId]);
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'published_catalog_version' => 1,
        ]);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.catalog_publication_deleted']);

        $this->getJson('/api/catalog/law')
            ->assertOk()
            ->assertJsonPath('published_version', 1);
    }

    public function test_plan_form_payload_accepts_base_name_and_updates_composition(): void
    {
        $admin = $this->admin();
        $productId = DB::table('products')->where('code', 'law')->value('id');
        $moduleId = DB::table('modules')->where('code', 'processos-advocacia')->value('id');

        $response = $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/plans', [
            'product_id' => $productId,
            'code' => 'law-base-name',
            'base_name' => 'Plano criado pela interface',
            'status' => 'ativo',
            'module_ids' => [$moduleId],
        ])->assertCreated();

        $planId = $response->json('id');
        $this->assertDatabaseHas('plans', ['id' => $planId, 'name' => 'Plano criado pela interface']);
        $this->assertDatabaseHas('plan_modules', ['plan_id' => $planId, 'module_id' => $moduleId]);

        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/catalog/plans/{$planId}", [
            'base_name' => 'Plano editado pela interface',
            'module_ids' => [$moduleId],
        ])->assertOk();

        $this->assertDatabaseHas('plans', ['id' => $planId, 'name' => 'Plano editado pela interface']);
    }

    public function test_catalog_form_values_are_persisted_as_decimal_amounts(): void
    {
        $admin = $this->admin();
        $productId = DB::table('products')->where('code', 'law')->value('id');

        $moduleId = $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/modules', [
            'product_id' => $productId,
            'code' => 'modulo-preco-decimal',
            'module_code' => 'processos',
            'name' => 'Módulo com preço decimal',
            'monthly_price' => 149.90,
        ])->assertCreated()->json('id');

        $planId = $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/plans', [
            'product_id' => $productId,
            'code' => 'plano-preco-decimal',
            'base_name' => 'Plano com valor decimal',
            'monthly_amount' => 299.90,
            'status' => 'ativo',
            'module_ids' => [$moduleId],
        ])->assertCreated()->json('id');

        $this->assertSame(149.90, (float) DB::table('modules')->where('id', $moduleId)->value('monthly_price'));
        $this->assertSame(299.90, (float) DB::table('plans')->where('id', $planId)->value('monthly_amount'));

        $modules = collect($this->actingAs($admin, 'platform')->getJson('/api/backoffice/catalog')->json('products'))
            ->flatMap(fn (array $product) => $product['modules']);
        $this->assertSame(149.90, (float) $modules->firstWhere('id', $moduleId)['monthly_price']);
    }

    public function test_catalog_accepts_localized_currency_values_from_masked_inputs(): void
    {
        $admin = $this->admin();
        $productId = DB::table('products')->where('code', 'law')->value('id');

        $moduleId = $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/modules', [
            'product_id' => $productId,
            'code' => 'modulo-preco-localizado',
            'module_code' => 'processos',
            'name' => 'Módulo com moeda localizada',
            'monthly_price' => 'R$ 149,90',
        ])->assertCreated()->json('id');

        $planId = $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/plans', [
            'product_id' => $productId,
            'code' => 'plano-preco-localizado',
            'base_name' => 'Plano com moeda localizada',
            'monthly_amount' => 'R$ 299,90',
            'status' => 'ativo',
            'module_ids' => [$moduleId],
        ])->assertCreated()->json('id');

        $this->assertSame(149.90, (float) DB::table('modules')->where('id', $moduleId)->value('monthly_price'));
        $this->assertSame(299.90, (float) DB::table('plans')->where('id', $planId)->value('monthly_amount'));
    }

    public function test_module_capabilities_and_personalizations_are_persisted_for_management_page(): void
    {
        $admin = $this->admin();
        $productId = DB::table('products')->where('code', 'law')->value('id');

        $moduleId = $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/modules', [
            'product_id' => $productId,
            'code' => 'modulo-capacidades',
            'module_code' => 'processos',
            'name' => 'Processos com capacidades',
            'monthly_price' => 'R$ 99,90',
            'segments' => ['advocacia'],
            'context_code' => 'escritorio',
            'capability_codes' => ['classes_assuntos_processuais', 'prioridades_sigilo'],
            'personalizations' => [[
                'type_code' => 'processos_ativos', 'required' => true, 'active' => true,
                'tiers' => [
                    ['value' => 100, 'additional_monthly_amount' => 0, 'active' => true],
                    ['value' => 500, 'additional_monthly_amount' => 25, 'active' => true],
                ],
            ]],
            'price_is_estimate' => true,
        ])->assertCreated()->json('id');

        $module = collect($this->actingAs($admin, 'platform')->getJson('/api/backoffice/catalog')->json('products'))
            ->flatMap(fn (array $product) => $product['modules'])
            ->firstWhere('id', $moduleId);

        $this->assertSame(['classes_assuntos_processuais', 'prioridades_sigilo'], $module['capability_codes']);
        $this->assertSame(['advocacia'], $module['segments']);
        $this->assertSame('escritorio', $module['context_code']);
        $this->assertCount(1, $module['personalizations']);
        $this->assertCount(2, $module['personalizations'][0]['tiers']);
        $this->assertFalse($module['available_standalone']);
        $this->assertTrue($module['price_is_estimate']);
    }

    public function test_physical_deletion_is_allowed_without_dependencies_and_blocked_with_dependencies(): void
    {
        $admin = $this->admin();
        $productId = DB::table('products')->where('code', 'law')->value('id');

        $moduleId = $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/modules', [
            'product_id' => $productId,
            'code' => 'modulo-descartavel',
            'module_code' => 'processos',
            'name' => 'Módulo descartável',
            'monthly_price' => 10,
        ])->assertCreated()->json('id');

        $this->actingAs($admin, 'platform')->deleteJson("/api/backoffice/catalog/modules/{$moduleId}", ['reason' => 'Limpeza de teste.'])->assertOk();
        $this->assertDatabaseMissing('modules', ['id' => $moduleId]);

        $linkedModuleId = DB::table('modules')->where('code', 'processos-advocacia')->value('id');
        $this->actingAs($admin, 'platform')->deleteJson("/api/backoffice/catalog/modules/{$linkedModuleId}", ['reason' => 'Tentativa inválida.'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'vínculos'));
        $this->assertDatabaseHas('modules', ['id' => $linkedModuleId]);
    }

    public function test_product_activation_lifecycle_is_audited_and_requires_publish_permission(): void
    {
        $commercial = $this->admin('administrador_comercial');
        $super = $this->admin();
        $productId = $this->actingAs($commercial, 'platform')->postJson('/api/backoffice/catalog/products', [
            'code' => 'produto-ciclo',
            'name' => 'Produto de ciclo',
            'status' => 'ativo',
        ])->assertCreated()->json('id');

        $this->actingAs($commercial, 'platform')->postJson("/api/backoffice/catalog/products/{$productId}/deactivate", ['reason' => 'Sem permissão.'])->assertForbidden();
        $this->actingAs($super, 'platform')->postJson("/api/backoffice/catalog/products/{$productId}/deactivate", ['reason' => 'Desativação homologada.'])->assertOk();
        $this->assertDatabaseHas('products', ['id' => $productId, 'status' => 'inativo', 'active' => false]);

        $this->actingAs($super, 'platform')->postJson("/api/backoffice/catalog/products/{$productId}/activate", ['reason' => 'Reativação homologada.'])->assertOk();
        $this->assertDatabaseHas('products', ['id' => $productId, 'status' => 'ativo']);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.catalog_product_deactivated']);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.catalog_product_activated']);
    }

    public function test_inactive_product_blocks_the_public_catalog_without_changing_its_technical_snapshot(): void
    {
        $admin = $this->admin();
        $productId = DB::table('products')->where('code', 'law')->value('id');

        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/products/{$productId}/deactivate", [
            'reason' => 'Indisponibilidade temporária.',
        ])->assertOk();

        $this->assertDatabaseHas('products', ['id' => $productId, 'status' => 'inativo', 'active' => false]);
        $this->getJson('/api/catalog/law')->assertUnprocessable();
        $this->assertDatabaseHas('catalog_publications', ['product_id' => $productId, 'version' => 1]);
    }

    public function test_module_reactivation_preserves_pause_until_explicit_publication(): void
    {
        $admin = $this->admin();
        $moduleId = DB::table('modules')->where('code', 'processos-advocacia')->value('id');

        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/modules/{$moduleId}/pause")->assertOk();
        $this->assertDatabaseHas('modules', ['id' => $moduleId, 'status' => 'inativo', 'publication_state' => 'pausado']);

        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/modules/{$moduleId}/activate")->assertOk();
        $this->assertDatabaseHas('modules', ['id' => $moduleId, 'status' => 'ativo', 'publication_state' => 'pausado']);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.catalog_module_activated', 'entity_id' => $moduleId]);

        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/modules/{$moduleId}/publish")->assertOk();
        $this->assertDatabaseHas('modules', ['id' => $moduleId, 'status' => 'ativo', 'publication_state' => 'publicado']);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.catalog_module_published', 'entity_id' => $moduleId]);
    }

    public function test_product_deletion_is_allowed_only_without_catalog_dependencies(): void
    {
        $admin = $this->admin();
        $productId = $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/products', [
            'code' => 'produto-exclusao',
            'name' => 'Produto para exclusão',
        ])->assertCreated()->json('id');

        $this->actingAs($admin, 'platform')->deleteJson("/api/backoffice/catalog/products/{$productId}", ['reason' => 'Limpeza homologada.'])->assertOk();
        $this->assertDatabaseMissing('products', ['id' => $productId]);

        $lawId = DB::table('products')->where('code', 'law')->value('id');
        $this->actingAs($admin, 'platform')->deleteJson("/api/backoffice/catalog/products/{$lawId}", ['reason' => 'Tentativa inválida.'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'vínculos'));
    }

    public function test_module_status_edit_synchronizes_publication_without_auto_publishing(): void
    {
        $admin = $this->admin();
        $moduleId = DB::table('modules')->where('code', 'processos-advocacia')->value('id');

        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/modules/{$moduleId}/pause")->assertOk();
        $this->assertDatabaseHas('modules', ['id' => $moduleId, 'status' => 'inativo', 'publication_state' => 'pausado']);

        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/modules/{$moduleId}/activate")->assertOk();
        $this->assertDatabaseHas('modules', ['id' => $moduleId, 'status' => 'ativo', 'publication_state' => 'pausado']);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.catalog_item_paused', 'entity_id' => $moduleId]);
    }

    public function test_creating_inactive_or_archived_module_synchronizes_publication(): void
    {
        $admin = $this->admin();
        $productId = DB::table('products')->where('code', 'law')->value('id');

        $inactiveId = $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/modules', [
            'product_id' => $productId,
            'code' => 'modulo-inativo',
            'module_code' => 'processos',
            'name' => 'Módulo inativo',
            'monthly_price' => 10,
            'segments' => ['advocacia'],
            'context_code' => 'escritorio',
        ])->assertCreated()->json('id');
        $this->assertDatabaseHas('modules', ['id' => $inactiveId, 'status' => 'rascunho', 'publication_state' => 'rascunho']);

        $archivedId = $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/modules', [
            'product_id' => $productId,
            'code' => 'modulo-arquivado',
            'module_code' => 'processos',
            'name' => 'Módulo arquivado',
            'monthly_price' => 10,
            'segments' => ['advocacia'],
            'context_code' => 'escritorio',
        ])->assertCreated()->json('id');
        $this->assertDatabaseHas('modules', ['id' => $archivedId, 'status' => 'rascunho', 'publication_state' => 'rascunho']);
    }

    private function admin(string $role = 'superadministrador'): PlatformAdmin
    {
        return PlatformAdmin::create([
            'id' => PrefixedUlid::make('PAD'),
            'name' => 'Equipe Fokus',
            'email' => $role.PlatformAdmin::count().'@example.test',
            'password' => Hash::make('SenhaInterna!2026'),
            'status' => 'ativo',
            'platform_role_id' => PlatformRole::where('code', $role)->value('id'),
            'email_verified_at' => now(),
        ]);
    }
}
