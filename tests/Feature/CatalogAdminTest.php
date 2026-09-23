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

    public function test_only_superadmin_can_create_a_paused_product_and_cannot_generate_catalog_versions_as_commercial(): void
    {
        $commercial = $this->admin('administrador_comercial');
        $this->actingAs($commercial, 'platform')->postJson('/api/backoffice/catalog/products', [
            'code' => 'academy', 'name' => 'Fokus Cloud Academy',
        ])->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/products', [
            'code' => 'academy',
            'name' => 'Fokus Cloud Academy',
            'status' => 'ativo',
        ])->assertCreated();

        $productId = DB::table('products')->where('code', 'academy')->value('id');
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'status' => 'pausado',
            'active' => false,
        ]);

        $this->actingAs($commercial, 'platform')->postJson("/api/backoffice/catalog/{$productId}/publish", [
            'reason' => 'Tentativa comercial.',
        ])->assertForbidden();
    }

    public function test_catalog_options_follow_the_current_product_code_aliases(): void
    {
        DB::table('products')->where('code', 'law')->update(['code' => 'fokus-law']);
        $admin = $this->admin();

        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/catalog')
            ->assertOk()
            ->assertJsonPath('options.families.fokus-law.0.code', 'processos')
            ->assertJsonPath('options.segments.fokus-law.0.code', 'advocacia')
            ->assertJsonPath('options.contexts.fokus-law.advocacia.0.code', 'escritorio');

        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/modules', [
            'product_id' => DB::table('products')->where('code', 'fokus-law')->value('id'),
            'module_code' => 'processos',
            'name' => 'Módulo com código de produto atual',
            'monthly_price' => 10,
            'segments' => ['advocacia'],
            'context_code' => 'escritorio',
        ])->assertCreated();
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

    public function test_plan_edit_blocks_the_public_catalog_until_a_new_version_is_published(): void
    {
        $admin = $this->admin();
        $plan = DB::table('plans')->where('code', 'law-advocacia')->first();
        $before = $this->getJson('/api/catalog/law')->assertOk()->json();

        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/plans/{$plan->id}/pause")->assertOk();
        $this->patchJson("/api/backoffice/catalog/plans/{$plan->id}", [
            'name' => 'Advocacia Alterada',
        ])->assertOk();

        $this->getJson('/api/catalog/law')->assertUnprocessable();
        $this->postJson("/api/backoffice/catalog/plans/{$plan->id}/activate")->assertOk();
        $this->postJson("/api/backoffice/catalog/plans/{$plan->id}/publish")->assertOk();
        $this->getJson('/api/catalog/law')->assertUnprocessable();
        $this->postJson("/api/backoffice/catalog/{$plan->product_id}/publish")->assertOk();

        $after = $this->getJson('/api/catalog/law')->assertOk()->json();
        $this->assertSame($before['published_version'] + 1, $after['published_version']);
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'published_version' => 2]);
        $this->assertContains('Fokus Law - Advocacia Alterada', collect($after['plans'])->pluck('name')->all());
    }

    public function test_module_change_requires_catalog_republication_before_new_contracts(): void
    {
        $admin = $this->admin();
        $module = DB::table('modules')->where('code', 'processos-advocacia')->first();
        $beforeVersion = (int) DB::table('products')->where('id', $module->product_id)->value('published_catalog_version');

        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/modules/{$module->id}/pause")->assertOk();
        $this->patchJson("/api/backoffice/catalog/modules/{$module->id}", ['name' => 'Processos Atualizados'])->assertOk();
        $this->postJson("/api/backoffice/catalog/modules/{$module->id}/activate")->assertOk();
        $this->postJson("/api/backoffice/catalog/modules/{$module->id}/publish")->assertOk();
        $this->getJson('/api/catalog/law')->assertUnprocessable();

        $this->postJson("/api/backoffice/catalog/{$module->product_id}/publish")
            ->assertOk()
            ->assertJsonPath('version', $beforeVersion + 1);
        $modules = $this->getJson('/api/catalog/law')->assertOk()->json('modules');
        $this->assertContains('Processos Atualizados', collect($modules)->pluck('name')->all());
    }

    public function test_active_catalog_items_cannot_be_edited_or_have_plan_composition_changed(): void
    {
        $admin = $this->admin();
        $productId = DB::table('products')->where('code', 'law')->value('id');
        $moduleId = DB::table('modules')->where('code', 'processos-advocacia')->value('id');
        $planId = DB::table('plans')->where('code', 'law-advocacia')->value('id');
        $moduleIds = DB::table('plan_modules')->where('plan_id', $planId)->pluck('module_id')->all();

        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/catalog/products/{$productId}", ['name' => 'Novo nome'])->assertUnprocessable();
        $this->patchJson("/api/backoffice/catalog/modules/{$moduleId}", ['name' => 'Novo módulo'])->assertUnprocessable();
        $this->patchJson("/api/backoffice/catalog/plans/{$planId}", ['name' => 'Novo plano'])->assertUnprocessable();
        $this->putJson("/api/backoffice/catalog/plans/{$planId}/modules", ['module_ids' => $moduleIds])->assertUnprocessable();

        $this->postJson("/api/backoffice/catalog/products/{$productId}/pause")->assertOk();
        $this->postJson("/api/backoffice/catalog/modules/{$moduleId}/pause")->assertOk();
        $this->postJson("/api/backoffice/catalog/plans/{$planId}/pause")->assertOk();

        $this->patchJson("/api/backoffice/catalog/products/{$productId}", ['name' => 'Novo nome'])->assertOk();
        $this->patchJson("/api/backoffice/catalog/modules/{$moduleId}", ['name' => 'Novo módulo'])->assertOk();
        $this->patchJson("/api/backoffice/catalog/plans/{$planId}", ['name' => 'Novo plano'])->assertOk();
        $this->putJson("/api/backoffice/catalog/plans/{$planId}/modules", ['module_ids' => $moduleIds])->assertOk();
        $this->patchJson("/api/backoffice/catalog/plans/{$planId}", ['status' => 'ativo'])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('products', ['id' => $productId, 'status' => 'pausado', 'publication_pending' => true]);
        $this->assertDatabaseHas('modules', ['id' => $moduleId, 'status' => 'inativo']);
        $this->assertDatabaseHas('plans', ['id' => $planId, 'status' => 'inativo']);
    }

    public function test_product_change_requires_a_new_catalog_version_after_activation(): void
    {
        $admin = $this->admin();
        $productId = DB::table('products')->where('code', 'law')->value('id');
        $beforeVersion = (int) DB::table('products')->where('id', $productId)->value('published_catalog_version');

        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/products/{$productId}/pause")->assertOk();
        $this->patchJson("/api/backoffice/catalog/products/{$productId}", ['name' => 'Fokus Law Atualizado'])->assertOk();
        $this->postJson("/api/backoffice/catalog/products/{$productId}/activate")->assertOk();
        $this->getJson('/api/catalog/law')->assertUnprocessable();

        $this->postJson("/api/backoffice/catalog/{$productId}/publish")
            ->assertOk()
            ->assertJsonPath('version', $beforeVersion + 1);

        $this->assertDatabaseHas('products', ['id' => $productId, 'publication_pending' => false, 'published_catalog_version' => $beforeVersion + 1]);
        $this->getJson('/api/catalog/law')->assertOk()->assertJsonPath('product.name', 'Fokus Law Atualizado');
    }

    public function test_product_display_order_is_persisted_and_reflected_in_catalog_listing(): void
    {
        $admin = $this->admin();
        $productCount = DB::table('products')->count();
        $firstProduct = DB::table('products')->orderBy('display_order')->orderBy('name')->first(['id', 'code']);
        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/products/{$firstProduct->id}/pause")->assertOk();

        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/catalog/products/{$firstProduct->id}", [
            'display_order' => $productCount + 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('display_order');

        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/catalog/products/{$firstProduct->id}", [
            'display_order' => $productCount,
        ])->assertOk();

        $products = $this->actingAs($admin, 'platform')->getJson('/api/backoffice/catalog')
            ->assertOk()
            ->json('products');

        $this->assertSame($firstProduct->code, $products[$productCount - 1]['code']);
        $this->assertSame($productCount, DB::table('products')->where('id', $firstProduct->id)->value('display_order'));
    }

    public function test_product_display_order_collision_reorders_subsequent_products(): void
    {
        $admin = $this->admin();
        $productCount = DB::table('products')->count();

        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/products', [
            'code' => 'academy',
            'name' => 'Fokus Cloud Academy',
            'display_order' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('display_order');

        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/products', [
            'code' => 'academy',
            'name' => 'Fokus Cloud Academy',
        ])->assertCreated();

        $products = $this->actingAs($admin, 'platform')->getJson('/api/backoffice/catalog')
            ->assertOk()
            ->json('products');

        $this->assertSame('academy', $products[$productCount]['code']);
        $this->assertSame($productCount + 1, DB::table('products')->where('code', 'academy')->value('display_order'));
    }

    public function test_product_reordering_keeps_a_unique_continuous_sequence(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/products', [
            'code' => 'academy',
            'name' => 'Fokus Cloud Academy',
        ])->assertCreated();

        $products = DB::table('products')->orderBy('display_order')->get(['id', 'code']);
        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/catalog/products/{$products->last()->id}", [
            'display_order' => 1,
        ])->assertOk();
        $this->assertSame(range(1, $products->count()), DB::table('products')->orderBy('display_order')->pluck('display_order')->map(fn ($order) => (int) $order)->all());
        $this->assertSame('academy', DB::table('products')->orderBy('display_order')->value('code'));

        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/catalog/products/{$products->last()->id}", [
            'display_order' => $products->count(),
        ])->assertOk();
        $this->assertSame(range(1, $products->count()), DB::table('products')->orderBy('display_order')->pluck('display_order')->map(fn ($order) => (int) $order)->all());
        $this->assertSame('academy', DB::table('products')->orderByDesc('display_order')->value('code'));
    }

    public function test_module_display_order_is_scoped_to_the_product_and_reorders_the_sequence(): void
    {
        $admin = $this->admin();
        $productId = DB::table('products')->where('code', 'law')->value('id');
        $modules = DB::table('modules')->where('product_id', $productId)->orderBy('display_order')->get(['id', 'code']);
        $module = $modules->first();
        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/modules/{$module->id}/pause")->assertOk();

        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/catalog/modules/{$module->id}", [
            'display_order' => $modules->count() + 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('display_order');

        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/catalog/modules/{$module->id}", [
            'display_order' => $modules->count(),
        ])->assertOk();

        $ordered = DB::table('modules')->where('product_id', $productId)->orderBy('display_order')->get(['id', 'code', 'display_order']);
        $this->assertSame(range(1, $ordered->count()), $ordered->pluck('display_order')->map(fn ($order) => (int) $order)->all());
        $this->assertSame($module->id, $ordered->last()->id);
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

        $this->actingAs($super, 'platform')->postJson("/api/backoffice/catalog/plans/{$planId}/activate", [
            'reason' => 'Reativação homologada.',
        ])->assertOk()->assertJsonPath('message', 'Plano ativado.');

        $this->assertDatabaseHas('plans', [
            'id' => $planId,
            'status' => 'ativo',
            'publication_state' => 'pausado',
        ]);
    }

    public function test_superadmin_can_publish_an_active_plan(): void
    {
        $super = $this->admin();
        $planId = DB::table('plans')->where('code', 'law-cartorio-criminal')->value('id');

        DB::table('plans')->where('id', $planId)->update(['status' => 'ativo', 'publication_state' => 'rascunho']);

        $this->actingAs($super, 'platform')->postJson("/api/backoffice/catalog/plans/{$planId}/publish")
            ->assertOk()
            ->assertJsonPath('message', 'Plano publicado.');

        $this->assertDatabaseHas('plans', [
            'id' => $planId,
            'status' => 'ativo',
            'publication_state' => 'publicado',
        ]);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.catalog_plan_published', 'entity_id' => $planId]);
    }

    public function test_plan_details_include_product_name_and_the_plans_own_publication_version(): void
    {
        $admin = $this->admin();
        $planId = DB::table('plans')->where('code', 'law-cartorio-criminal')->value('id');

        $plans = $this->actingAs($admin, 'platform')->getJson('/api/backoffice/plans')->assertOk()->json();
        $plan = collect($plans)->firstWhere('id', $planId);
        $this->assertSame('Fokus Law', $plan['product_name']);
        $this->assertSame(1, $plan['published_version']);

        $this->postJson("/api/backoffice/catalog/plans/{$planId}/pause")->assertOk();
        $this->postJson("/api/backoffice/catalog/plans/{$planId}/activate")->assertOk();
        $this->postJson("/api/backoffice/catalog/plans/{$planId}/publish")->assertOk();
        $this->postJson("/api/backoffice/catalog/plans/{$planId}/publish")->assertOk();

        $this->assertDatabaseHas('plans', ['id' => $planId, 'published_version' => 2]);
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
            'status' => 'inativo',
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

    public function test_product_activation_lifecycle_is_audited_and_restricted_to_superadmin(): void
    {
        $commercial = $this->admin('administrador_comercial');
        $super = $this->admin();
        $productId = $this->actingAs($super, 'platform')->postJson('/api/backoffice/catalog/products', [
            'code' => 'produto-ciclo',
            'name' => 'Produto de ciclo',
            'status' => 'ativo',
        ])->assertCreated()->json('id');

        $this->actingAs($commercial, 'platform')->postJson("/api/backoffice/catalog/products/{$productId}/pause")->assertForbidden();
        $this->actingAs($super, 'platform')->postJson("/api/backoffice/catalog/products/{$productId}/activate")->assertOk();
        $this->assertDatabaseHas('products', ['id' => $productId, 'status' => 'ativo', 'active' => true]);
        $this->actingAs($super, 'platform')->postJson("/api/backoffice/catalog/products/{$productId}/pause")->assertOk();
        $this->assertDatabaseHas('products', ['id' => $productId, 'status' => 'pausado', 'active' => false]);

        $this->actingAs($super, 'platform')->postJson("/api/backoffice/catalog/products/{$productId}/activate")->assertOk();
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.catalog_product_paused']);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.catalog_product_activated']);
    }

    public function test_paused_product_blocks_the_public_catalog_without_changing_its_technical_snapshot(): void
    {
        $admin = $this->admin();
        $productId = DB::table('products')->where('code', 'law')->value('id');

        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/products/{$productId}/pause")->assertOk();

        $this->assertDatabaseHas('products', ['id' => $productId, 'status' => 'pausado', 'active' => false]);
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

        $this->actingAs($admin, 'platform')->deleteJson("/api/backoffice/catalog/products/{$productId}")->assertOk();
        $this->assertDatabaseMissing('products', ['id' => $productId]);

        $lawId = DB::table('products')->where('code', 'law')->value('id');
        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/catalog/products/{$lawId}/pause")->assertOk();
        $this->actingAs($admin, 'platform')->deleteJson("/api/backoffice/catalog/products/{$lawId}")
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
        $this->assertDatabaseHas('modules', ['id' => $inactiveId, 'status' => 'inativo', 'publication_state' => 'pausado']);

        $archivedId = $this->actingAs($admin, 'platform')->postJson('/api/backoffice/catalog/modules', [
            'product_id' => $productId,
            'code' => 'modulo-arquivado',
            'module_code' => 'processos',
            'name' => 'Módulo arquivado',
            'monthly_price' => 10,
            'segments' => ['advocacia'],
            'context_code' => 'escritorio',
        ])->assertCreated()->json('id');
        $this->assertDatabaseHas('modules', ['id' => $archivedId, 'status' => 'inativo', 'publication_state' => 'pausado']);
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
