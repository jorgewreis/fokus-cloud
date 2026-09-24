<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Models\User;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubscriptionAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_assisted_checkout_and_free_voucher_activate_pending_subscription_until_expiry(): void
    {
        config(['services.mercado_pago.access_token' => 'test-token', 'services.mercado_pago.test_payer_email' => 'test_user_123@testuser.com']);
        $admin = $this->platformAdmin();
        $fixture = $this->subscriptionFixture();
        DB::table('subscriptions')->where('id', $fixture['subscription_id'])->update(['status' => 'encerrada', 'open_company_product' => null]);
        $product = DB::table('products')->where('code', 'law')->first();
        DB::table('products')->where('id', $product->id)->update(['code' => 'fokus-law', 'publication_pending' => true]);
        $plan = DB::table('plans')->where('product_id', $product->id)->where('code', 'law-advocacia')->first();
        Http::fake(function ($request) {
            static $created = 0;
            if ($request->method() === 'POST') return Http::response(['id' => ++$created === 1 ? 'pre-assisted' : 'pre-assisted-2', 'init_point' => 'https://mercadopago.test/checkout'], 201);
            if ($request->method() === 'GET') return Http::response(['status' => 'pending'], 200);
            return Http::response(['status' => 'cancelled'], 200);
        });

        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/subscriptions/checkout-options?q=Alpha')
            ->assertOk()->assertJsonPath('companies.0.id', $fixture['company_id'])
            ->assertJsonPath('products.0.code', 'fokus-law')
            ->assertJsonPath('products.0.plans.0.code', 'law-advocacia');
        $response = $this->actingAs($admin, 'platform')->postJson('/api/backoffice/subscriptions/checkout', [
            'company_id' => $fixture['company_id'], 'product_code' => 'fokus-law', 'plan_code' => 'law-advocacia', 'cycle' => 'monthly',
        ])->assertCreated()->assertJsonPath('checkout_url', 'https://mercadopago.test/checkout');
        $subscriptionId = $response->json('subscription_id');
        $this->assertDatabaseHas('subscriptions', ['id' => $subscriptionId, 'status' => 'aguardando_pagamento', 'created_by' => $fixture['user_id']]);
        $originalAmount = (float) DB::table('payments')->where('subscription_id', $subscriptionId)->value('amount');
        $commercialSnapshot = json_decode((string) DB::table('subscriptions')->where('id', $subscriptionId)->value('commercial_snapshot'), true);
        $this->assertSame($plan->id, $commercialSnapshot['plan_id']);
        $this->assertSame($plan->name, $commercialSnapshot['plan_name']);
        $this->assertEqualsWithDelta($originalAmount, (float) $commercialSnapshot['amount'], 0.01);
        $this->assertEqualsWithDelta($originalAmount, (float) $commercialSnapshot['monthly_amount'], 0.01);
        $this->assertSame(1, $commercialSnapshot['publication_versions']['product_catalog_version']);
        $this->assertSame(1, $commercialSnapshot['publication_versions']['plan_version']);
        $this->assertSame(1, $commercialSnapshot['publication_versions']['module_versions']['processos-advocacia']);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.subscription_checkout_created', 'entity_id' => $subscriptionId]);

        DB::table('vouchers')->insert([
            'id' => PrefixedUlid::make('VCH'), 'code' => 'FREE7', 'name' => 'Sete dias gratuitos',
            'discount_type' => 'trial_free', 'discount_value' => 100, 'product_id' => $product->id,
            'plan_id' => $plan->id, 'benefit_duration' => 'd7', 'status' => 'ativa',
            'created_by_platform_admin_id' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs(User::find($fixture['user_id']))->postJson("/api/backoffice/subscriptions/{$subscriptionId}/free-voucher", ['voucher_code' => 'FREE7'])
            ->assertUnauthorized();
        $this->actingAs($admin, 'platform')->postJson("/api/backoffice/subscriptions/{$subscriptionId}/free-voucher", ['voucher_code' => 'FREE7'])
            ->assertOk()->assertJsonPath('subscription_id', $subscriptionId);
        $this->assertDatabaseHas('subscriptions', ['id' => $subscriptionId, 'status' => 'ativa', 'provider_subscription_id' => null]);
        $activeSnapshot = json_decode((string) DB::table('subscriptions')->where('id', $subscriptionId)->value('commercial_snapshot'), true);
        $this->assertSame($plan->id, $activeSnapshot['plan_id']);
        $this->assertSame($plan->name, $activeSnapshot['plan_name']);
        $this->assertSame(0.0, (float) $activeSnapshot['amount']);
        $this->assertSame(0.0, (float) $activeSnapshot['monthly_amount']);
        $this->assertEqualsWithDelta($originalAmount, (float) $activeSnapshot['base_amount'], 0.01);
        $subscriptionDetails = $this->actingAs($admin, 'platform')->getJson("/api/backoffice/subscriptions/{$subscriptionId}")
            ->assertOk()
            ->assertJsonPath('amount', 0)
            ->assertJsonPath('monthly_amount', 0)
            ->assertJsonPath('has_active_free_benefit', true)
            ->assertJsonPath('items.0.unit_price', 0);
        $this->assertEqualsWithDelta($originalAmount, (float) $subscriptionDetails->json('base_amount'), 0.01);
        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/dashboard')
            ->assertOk()
            ->assertJsonPath('metrics.mrr', 0);
        $listedCompany = $this->actingAs($admin, 'platform')->getJson('/api/backoffice/companies?q=Alpha')->assertOk();
        $this->assertSame(1, $listedCompany->json('data.0.active_subscriptions'));
        $this->assertSame($plan->name, $listedCompany->json('data.0.plan_name'));
        $catalogPlan = app(\App\Services\CatalogManager::class)->managementPlans()->firstWhere('id', $plan->id);
        $this->assertSame(2, $catalogPlan['subscription_count']);
        $this->assertSame(1, $catalogPlan['active_subscription_count']);
        $this->postJson('/api/auth/law-context', ['email' => User::find($fixture['user_id'])->email])
            ->assertOk()->assertJsonPath('systems.0.value', $fixture['company_id']);
        $this->assertDatabaseHas('voucher_redemptions', ['subscription_id' => $subscriptionId]);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.subscription_free_voucher_activated', 'entity_id' => $subscriptionId]);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request->url() === 'https://api.mercadopago.com/preapproval/pre-assisted' && $request['status'] === 'cancelled');
        $paymentId = DB::table('payments')->where('subscription_id', $subscriptionId)->value('id');
        $this->assertEqualsWithDelta($originalAmount, (float) DB::table('payments')->where('id', $paymentId)->value('amount'), 0.01);
        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/payments')
            ->assertOk()->assertJsonFragment(['id' => $paymentId, 'status' => 'cancelado']);
        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/payments?status=cancelado')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $paymentId)
            ->assertJsonPath('data.0.status', 'cancelado');
        app(\App\Services\SubscriptionBillingManager::class)->applyPayment($paymentId, ['id' => 'late-payment', 'preapproval_id' => 'pre-assisted'], 'cancelado');
        $this->assertDatabaseHas('subscriptions', ['id' => $subscriptionId, 'status' => 'ativa']);

        $this->travel(8)->days();
        $this->artisan('fokus:expire-free-voucher-subscriptions')->assertSuccessful();
        $this->assertDatabaseHas('subscriptions', ['id' => $subscriptionId, 'status' => 'suspensa']);
        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/subscriptions/{$subscriptionId}", ['action' => 'reativacao', 'reason' => 'Tentativa sem novo pagamento.'])
            ->assertUnprocessable();
        $newCheckout = $this->actingAs($admin, 'platform')->postJson('/api/backoffice/subscriptions/checkout', [
            'company_id' => $fixture['company_id'], 'product_code' => 'fokus-law', 'plan_code' => 'law-advocacia', 'cycle' => 'monthly',
        ])->assertCreated();
        $this->assertDatabaseHas('subscriptions', ['id' => $subscriptionId, 'status' => 'encerrada']);
        $this->assertDatabaseHas('subscriptions', ['id' => $newCheckout->json('subscription_id'), 'status' => 'aguardando_pagamento']);
    }

    public function test_internal_admin_can_list_masked_companies_and_subscription_detail(): void
    {
        $admin = $this->platformAdmin();
        $fixture = $this->subscriptionFixture();

        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/companies')
            ->assertOk()
            ->assertJsonPath('data.0.legal_name', 'Empresa Alpha')
            ->assertJsonPath('data.0.document_number', '12345678000100')
            ->assertJsonPath('data.0.admin_email', 'cliente-0@example.test');

        $this->actingAs($admin, 'platform')->getJson('/api/backoffice/subscriptions/'.$fixture['subscription_id'])
            ->assertOk()
            ->assertJsonPath('company_name', 'Empresa Alpha')
            ->assertJsonPath('items.0.name', 'Gestão de Processos para Advogados')
            ->assertJsonPath('payments.0.status', 'aguardando_pagamento')
            ->assertJsonPath('history', []);
    }

    public function test_dashboard_subscription_list_returns_five_highest_value_active_items(): void
    {
        $admin = $this->platformAdmin();
        foreach (range(1, 7) as $index) {
            $fixture = $this->subscriptionFixture(['cpf' => str_pad((string) $index, 11, '0', STR_PAD_LEFT)]);
            DB::table('companies')->where('id', $fixture['company_id'])->update([
                'document_number' => sprintf('12345678000%03d', $index),
                'legal_name' => "Empresa {$index}",
            ]);
            $amount = $index * 10;
            DB::table('subscriptions')->where('id', $fixture['subscription_id'])->update([
                'status' => $index === 7 ? 'encerrada' : 'ativa',
                'commercial_snapshot' => json_encode(['plan_name' => "Plano {$index}", 'amount' => $amount, 'monthly_amount' => $amount]),
            ]);
        }

        $response = $this->actingAs($admin, 'platform')->getJson('/api/backoffice/subscriptions?dashboard_top_value=1&status=ativa&per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.total', 6)
            ->assertJsonCount(5, 'data');

        $this->assertSame([60, 50, 40, 30, 20], array_map(fn (array $item): int => (int) $item['amount'], $response->json('data')));
        $this->assertNotContains('Empresa 7', array_column($response->json('data'), 'company_name'));
    }

    public function test_admin_can_pause_reactivate_and_schedule_cancellation_with_audit(): void
    {
        $admin = $this->platformAdmin();
        $fixture = $this->subscriptionFixture();

        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/subscriptions/'.$fixture['subscription_id'], [
            'action' => 'suspensao',
            'reason' => 'Revisão comercial solicitada.',
        ])->assertOk()->assertJsonPath('status', 'aplicada');
        $this->assertDatabaseHas('subscriptions', ['id' => $fixture['subscription_id'], 'status' => 'suspensa']);

        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/subscriptions/'.$fixture['subscription_id'], [
            'action' => 'reativacao',
            'reason' => 'Revisão concluída.',
        ])->assertOk();
        $this->assertDatabaseHas('subscriptions', ['id' => $fixture['subscription_id'], 'status' => 'ativa']);

        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/subscriptions/'.$fixture['subscription_id'], [
            'action' => 'cancelamento',
            'reason' => 'Encerramento solicitado pelo cliente.',
        ])->assertOk()->assertJsonPath('status', 'aplicada');
        $this->assertDatabaseHas('subscriptions', ['id' => $fixture['subscription_id'], 'status' => 'cancelamento_agendado']);
        $this->assertDatabaseCount('subscription_changes', 3);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.subscription_cancelamento']);
    }

    public function test_upgrade_uses_published_plan_and_waits_for_payment(): void
    {
        $admin = $this->platformAdmin();
        $fixture = $this->subscriptionFixture();
        $targetPlanId = DB::table('plans')->where('code', 'law-cartorio-criminal')->value('id');

        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/subscriptions/'.$fixture['subscription_id'], [
            'action' => 'upgrade',
            'reason' => 'Ampliação contratada.',
            'target_plan_id' => $targetPlanId,
            'billing_cycle' => 'annual',
            'amount' => 0,
        ])->assertOk()->assertJsonPath('status', 'aguardando_pagamento');

        $this->assertDatabaseHas('subscriptions', ['id' => $fixture['subscription_id'], 'status' => 'ativa']);
        $this->assertDatabaseHas('subscription_changes', ['subscription_id' => $fixture['subscription_id'], 'status' => 'aguardando_pagamento']);
        $snapshot = json_decode((string) DB::table('subscription_changes')->where('subscription_id', $fixture['subscription_id'])->value('after_snapshot'), true);
        $this->assertSame('law-cartorio-criminal', $snapshot['plan_code']);
        $this->assertSame('annual', $snapshot['billing_cycle']);
        $this->assertSame(1, $snapshot['publication_versions']['product_catalog_version']);
        $this->assertSame(1, $snapshot['publication_versions']['plan_version']);
    }

    public function test_immediate_cancellation_ends_subscription_and_preserves_history(): void
    {
        $admin = $this->platformAdmin();
        $fixture = $this->subscriptionFixture();

        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/subscriptions/'.$fixture['subscription_id'], [
            'action' => 'cancelamento_imediato',
            'reason' => 'Encerramento imediato aprovado.',
        ])->assertOk()->assertJsonPath('status', 'aplicada');

        $this->assertDatabaseHas('subscriptions', [
            'id' => $fixture['subscription_id'],
            'status' => 'encerrada',
            'open_company_product' => null,
        ]);
        $this->assertDatabaseHas('subscription_changes', [
            'subscription_id' => $fixture['subscription_id'],
            'type' => 'cancelamento',
            'status' => 'aplicada',
            'reason' => 'Encerramento imediato aprovado.',
        ]);
    }

    public function test_company_admin_can_cancel_unpaid_subscription_immediately(): void
    {
        config(['services.mercado_pago.access_token' => 'test-token']);
        Http::fake(['https://api.mercadopago.com/preapproval/pre-unpaid' => Http::response(['status' => 'cancelled'], 200)]);
        $fixture = $this->subscriptionFixture(['status' => 'aguardando_pagamento', 'provider_subscription_id' => 'pre-unpaid']);
        $user = User::find($fixture['user_id']);

        $this->actingAs($user)->withSession(['active_company_id' => $fixture['company_id']])
            ->postJson('/api/subscriptions/'.$fixture['subscription_id'].'/change', ['type' => 'cancelamento_imediato'])
            ->assertOk()->assertJsonPath('message', 'Assinatura cancelada imediatamente.');

        $this->assertDatabaseHas('subscriptions', ['id' => $fixture['subscription_id'], 'status' => 'encerrada', 'open_company_product' => null]);
        $this->assertDatabaseHas('subscription_changes', ['subscription_id' => $fixture['subscription_id'], 'requested_by_user_id' => $user->id, 'status' => 'aplicada']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.mercadopago.com/preapproval/pre-unpaid' && $request['status'] === 'cancelled');
    }

    public function test_company_admin_cancel_button_schedules_paid_subscription_at_period_end(): void
    {
        $fixture = $this->subscriptionFixture(['status' => 'ativa']);
        $user = User::find($fixture['user_id']);
        DB::table('payments')->where('subscription_id', $fixture['subscription_id'])->update(['status' => 'aprovado']);
        $periodEndsAt = DB::table('subscriptions')->where('id', $fixture['subscription_id'])->value('current_period_ends_at');

        $this->actingAs($user)->withSession(['active_company_id' => $fixture['company_id']])
            ->postJson('/api/subscriptions/'.$fixture['subscription_id'].'/change', ['type' => 'cancelamento'])
            ->assertOk()->assertJsonPath('message', 'Assinatura cancelada ao final do período vigente.');

        $this->assertDatabaseHas('subscriptions', [
            'id' => $fixture['subscription_id'],
            'status' => 'ativa',
            'cancel_at' => $periodEndsAt,
        ]);
        $this->assertDatabaseHas('subscription_changes', [
            'subscription_id' => $fixture['subscription_id'],
            'type' => 'cancelamento',
            'status' => 'agendada',
        ]);
    }

    public function test_company_admin_cancel_unpaid_subscription_locally_when_provider_is_unavailable(): void
    {
        config(['services.mercado_pago.access_token' => 'test-token']);
        Http::fake(['https://api.mercadopago.com/preapproval/pre-unavailable' => Http::response(['message' => 'temporary failure'], 500)]);
        $fixture = $this->subscriptionFixture(['status' => 'aguardando_pagamento', 'provider_subscription_id' => 'pre-unavailable']);
        $user = User::find($fixture['user_id']);

        $this->actingAs($user)->withSession(['active_company_id' => $fixture['company_id']])
            ->postJson('/api/subscriptions/'.$fixture['subscription_id'].'/change', ['type' => 'cancelamento'])
            ->assertOk()
            ->assertJsonPath('message', 'Assinatura não paga cancelada imediatamente.')
            ->assertJsonPath('provider_sync_pending', true);

        $this->assertDatabaseHas('subscriptions', [
            'id' => $fixture['subscription_id'],
            'status' => 'encerrada',
            'open_company_product' => null,
        ]);
    }

    public function test_company_admin_can_delete_only_closed_subscription(): void
    {
        $fixture = $this->subscriptionFixture(['status' => 'encerrada']);
        $user = User::find($fixture['user_id']);

        $this->actingAs($user)->withSession(['active_company_id' => $fixture['company_id']])
            ->deleteJson('/api/subscriptions/'.$fixture['subscription_id'])
            ->assertOk()->assertJsonPath('message', 'Assinatura excluída definitivamente.');

        $this->assertDatabaseMissing('subscriptions', ['id' => $fixture['subscription_id']]);
        $this->assertDatabaseMissing('subscription_items', ['subscription_id' => $fixture['subscription_id']]);
        $this->assertDatabaseMissing('payments', ['subscription_id' => $fixture['subscription_id']]);
    }

    public function test_company_admin_cannot_delete_active_subscription(): void
    {
        $fixture = $this->subscriptionFixture(['status' => 'ativa']);
        $user = User::find($fixture['user_id']);

        $this->actingAs($user)->withSession(['active_company_id' => $fixture['company_id']])
            ->deleteJson('/api/subscriptions/'.$fixture['subscription_id'])
            ->assertStatus(422);
        $this->assertDatabaseHas('subscriptions', ['id' => $fixture['subscription_id'], 'status' => 'ativa']);
    }

    public function test_downgrade_is_scheduled_and_command_applies_it_at_period_end(): void
    {
        $admin = $this->platformAdmin();
        $fixture = $this->subscriptionFixture(['period_ends_at' => now()->subMinute()]);
        $targetPlanId = DB::table('plans')->where('code', 'law-cartorio-criminal')->value('id');

        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/subscriptions/'.$fixture['subscription_id'], [
            'action' => 'downgrade',
            'reason' => 'Redução de escopo.',
            'target_plan_id' => $targetPlanId,
        ])->assertOk()->assertJsonPath('status', 'agendada');

        $this->artisan('fokus:apply-subscription-changes')->expectsOutput('Alterações aplicadas: 1')->assertSuccessful();
        $this->assertDatabaseHas('subscriptions', ['id' => $fixture['subscription_id'], 'status' => 'ativa']);
        $this->assertDatabaseHas('subscription_changes', ['subscription_id' => $fixture['subscription_id'], 'status' => 'aplicada']);
        $this->assertDatabaseHas('subscription_items', ['subscription_id' => $fixture['subscription_id'], 'name_snapshot' => 'Gestão de Processos para Varas Criminais', 'deleted_at' => null]);
    }

    public function test_override_is_restricted_to_superadmin_and_records_before_after_snapshots(): void
    {
        $commercial = $this->platformAdmin('administrador_comercial');
        $superadmin = $this->platformAdmin();
        $fixture = $this->subscriptionFixture();
        $payload = ['action' => 'override', 'reason' => 'Acordo comercial aprovado.', 'override' => ['monthly_amount' => 499.90, 'billing_cycle' => 'monthly']];

        $this->actingAs($commercial, 'platform')->patchJson('/api/backoffice/subscriptions/'.$fixture['subscription_id'], $payload)->assertForbidden();
        $this->withoutExceptionHandling()->actingAs($superadmin, 'platform')->patchJson('/api/backoffice/subscriptions/'.$fixture['subscription_id'], $payload)->assertOk();

        $change = DB::table('subscription_changes')->where('subscription_id', $fixture['subscription_id'])->where('type', 'override')->first();
        $this->assertNotNull($change);
        $this->assertSame(499.90, (float) json_decode($change->after_snapshot, true)['monthly_amount']);
        $this->assertNotSame($change->before_snapshot, $change->after_snapshot);
        $this->assertDatabaseHas('subscriptions', ['id' => $fixture['subscription_id'], 'status' => 'ativa']);
    }

    public function test_subscription_public_name_can_only_be_changed_by_superadmin_and_is_audited(): void
    {
        $commercial = $this->platformAdmin('administrador_comercial');
        $superadmin = $this->platformAdmin();
        $fixture = $this->subscriptionFixture();
        $url = '/api/backoffice/subscriptions/'.$fixture['subscription_id'].'/public-name';

        $this->actingAs($commercial, 'platform')->patchJson($url, ['public_name' => 'Empresa Alpha'])->assertForbidden();
        $this->actingAs($superadmin, 'platform')->patchJson($url, ['public_name' => '  Empresa Alpha  '])->assertOk()->assertJsonPath('public_name', 'Empresa Alpha');
        $this->assertDatabaseHas('subscriptions', ['id' => $fixture['subscription_id'], 'public_name' => 'Empresa Alpha']);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.subscription_public_name_updated', 'entity_id' => $fixture['subscription_id']]);
        $this->actingAs($superadmin, 'platform')->getJson('/api/backoffice/dashboard')
            ->assertOk()
            ->assertJsonPath('recent_activity.0.title', 'Assinatura atualizada')
            ->assertJsonPath('recent_activity.0.description', 'Assinatura: Advocacia · Empresa Alpha · Nome público alterado de “sem nome público” para “Empresa Alpha”.');
        $this->actingAs($superadmin, 'platform')->patchJson($url, ['public_name' => null])->assertOk()->assertJsonPath('public_name', null);
        $this->actingAs($superadmin, 'platform')->patchJson($url, ['public_name' => str_repeat('x', 121)])->assertUnprocessable();
    }

    private function platformAdmin(string $role = 'superadministrador'): PlatformAdmin
    {
        return PlatformAdmin::create([
            'id' => PrefixedUlid::make('PAD'),
            'name' => 'Equipe Fokus',
            'email' => $role.'-'.PlatformAdmin::count().'@example.test',
            'password' => Hash::make('SenhaInterna!2026'),
            'status' => 'ativo',
            'platform_role_id' => PlatformRole::where('code', $role)->value('id'),
            'email_verified_at' => now(),
        ]);
    }

    private function subscriptionFixture(array $options = []): array
    {
        $product = DB::table('products')->where('code', 'law')->first();
        $module = DB::table('modules')->where('code', 'processos-advocacia')->first();
        $user = User::create(['id' => PrefixedUlid::make('USR'), 'name' => 'Administrador Alpha', 'cpf' => $options['cpf'] ?? '52998224725', 'email' => 'cliente-'.User::count().'@example.test', 'password' => Hash::make('SenhaCliente!2026'), 'status' => 'ativa', 'email_verified_at' => now()]);
        $companyId = PrefixedUlid::make('COM');
        DB::table('companies')->insert(['id' => $companyId, 'document_type' => 'cnpj', 'document_number' => '12345678000100', 'legal_name' => 'Empresa Alpha', 'status' => 'ativa', 'version' => 1, 'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_memberships')->insert(['id' => PrefixedUlid::make('MBS'), 'company_id' => $companyId, 'user_id' => $user->id, 'role_id' => DB::table('roles')->where('code', 'admin')->value('id'), 'status' => 'ativo', 'active_admin_company_id' => $companyId, 'version' => 1, 'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $subscriptionId = PrefixedUlid::make('ASS');
        $endsAt = $options['period_ends_at'] ?? now()->addMonth();
        $snapshot = ['plan_id' => DB::table('plans')->where('code', 'law-advocacia')->value('id'), 'plan_code' => 'law-advocacia', 'plan_name' => 'Advocacia', 'billing_cycle' => 'monthly', 'monthly_amount' => 64.70, 'amount' => 64.70, 'current_period_starts_at' => now()->toISOString(), 'current_period_ends_at' => $endsAt->toISOString(), 'status' => 'ativa', 'items' => [['module_id' => $module->id, 'name' => $module->name, 'quantity' => 1, 'unit_price' => (float) $module->monthly_price, 'conditions' => ['plan_code' => 'law-advocacia']]]];
        DB::table('subscriptions')->insert(['id' => $subscriptionId, 'company_id' => $companyId, 'product_id' => $product->id, 'status' => $options['status'] ?? 'ativa', 'open_company_product' => $companyId.'-'.$product->id, 'version' => 1, 'billing_cycle' => 'monthly', 'current_period_starts_at' => now(), 'current_period_ends_at' => $endsAt, 'provider_subscription_id' => $options['provider_subscription_id'] ?? null, 'commercial_snapshot' => json_encode($snapshot), 'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subscription_items')->insert(['id' => PrefixedUlid::make('ITM'), 'company_id' => $companyId, 'subscription_id' => $subscriptionId, 'module_id' => $module->id, 'name_snapshot' => $module->name, 'quantity' => 1, 'unit_price_snapshot' => $module->monthly_price, 'conditions_snapshot' => json_encode(['plan_code' => 'law-advocacia']), 'version' => 1, 'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payments')->insert(['id' => PrefixedUlid::make('PAG'), 'company_id' => $companyId, 'subscription_id' => $subscriptionId, 'provider' => 'mercado_pago', 'status' => 'aguardando_pagamento', 'amount' => 64.70, 'currency' => 'BRL', 'provider_payload_sanitized' => json_encode(['test' => true]), 'version' => 1, 'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        return ['company_id' => $companyId, 'subscription_id' => $subscriptionId, 'user_id' => $user->id];
    }
}
