<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Models\User;
use App\Services\PrefixedUlid;
use App\Services\VoucherManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VoucherRedemptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
        Config::set('services.mercado_pago.access_token', 'test-token');
        Config::set('services.mercado_pago.webhook_secret', 'test-secret');
        Config::set('services.mercado_pago.test_payer_email', 'test_user_1234567890@testuser.com');
    }

    public function test_checkout_reserves_voucher_and_webhook_confirms_full_snapshot(): void
    {
        [$user, $companyId] = $this->customerContext();
        $admin = $this->platformAdmin();
        $product = DB::table('products')->where('code', 'law')->first();
        $plan = DB::table('plans')->where('product_id', $product->id)->where('code', 'law-advocacia')->first();
        $voucherId = PrefixedUlid::make('VCH');
        DB::table('vouchers')->insert([
            'id' => $voucherId,
            'code' => 'CREDITO500',
            'name' => 'Crédito comercial',
            'discount_type' => 'commercial_credit',
            'discount_value' => 500,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'base_amount' => 64.70,
            'benefit_duration' => 'm1',
            'redemption_limit' => 1,
            'redemption_limit_per_company' => 1,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => 'ativa',
            'created_by_platform_admin_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://api.mercadopago.com/preapproval' => Http::response(['id' => 'preapproval-test', 'init_point' => 'https://mercadopago.test/checkout'], 201),
            'https://api.mercadopago.com/preapproval/preapproval-test' => Http::response(['status' => 'authorized'], 200),
        ]);

        $response = $this->actingAs($user)->withSession(['active_company_id' => $companyId])->postJson('/api/subscriptions/checkout', [
            'product_code' => 'law',
            'items' => [
                ['module_code' => 'processos-advocacia', 'quantity' => 1],
                ['module_code' => 'contatos-advocacia', 'quantity' => 1],
                ['module_code' => 'tarefas-advocacia', 'quantity' => 1],
            ],
            'cycle' => 'monthly',
            'selection_mode' => 'plan',
            'plan_code' => 'law-advocacia',
            'voucher_code' => 'CREDITO500',
        ])->assertCreated();

        Http::assertSent(fn ($request) => $request->url() === 'https://api.mercadopago.com/preapproval'
            && $request['payer_email'] === 'test_user_1234567890@testuser.com');

        $subscriptionId = $response->json('subscription_id');
        $this->assertSame(0, DB::table('voucher_redemptions')->count());
        $this->assertDatabaseHas('voucher_redemption_reservations', [
            'voucher_id' => $voucherId,
            'subscription_id' => $subscriptionId,
            'status' => 'pending',
        ]);

        $timestamp = (string) now()->timestamp;
        $signature = 'ts='.$timestamp.',v1='.hash_hmac('sha256', 'id:preapproval-test;request-id:req-1;ts:'.$timestamp.';', 'test-secret');
        $this->withHeaders(['x-signature' => $signature, 'x-request-id' => 'req-1'])
            ->postJson('/api/webhooks/mercado-pago?data.id=preapproval-test', ['type' => 'preapproval'])
            ->assertOk();

        $this->assertDatabaseHas('voucher_redemption_reservations', ['subscription_id' => $subscriptionId, 'status' => 'confirmed']);
        $this->assertDatabaseHas('voucher_redemptions', ['voucher_id' => $voucherId, 'subscription_id' => $subscriptionId]);
        $snapshot = json_decode((string) DB::table('voucher_redemptions')->where('subscription_id', $subscriptionId)->value('snapshot'), true);
        $this->assertSame('commercial_credit', $snapshot['discount_type']);
        $this->assertSame('CREDITO500', $snapshot['code']);
        $this->assertSame('law-advocacia', $snapshot['plan_code']);
        $this->assertSame($companyId, $snapshot['company_id']);
        $this->assertNotNull($snapshot['benefit_starts_at']);
        $this->assertNotNull($snapshot['benefit_ends_at']);
    }

    public function test_failed_gateway_checkout_releases_the_voucher_reservation_without_persisting_commercial_data(): void
    {
        [$user, $companyId] = $this->customerContext();
        $admin = $this->platformAdmin();
        [$product, $plan, $voucherId] = $this->voucher($admin, 'FAILCHECKOUT');

        Http::fake([
            'https://api.mercadopago.com/preapproval' => Http::response(['message' => 'sandbox indisponível'], 500),
        ]);

        $this->actingAs($user)->withSession(['active_company_id' => $companyId])->postJson('/api/subscriptions/checkout', [
            'product_code' => $product->code,
            'items' => $this->planItems(),
            'cycle' => 'monthly',
            'selection_mode' => 'plan',
            'plan_code' => $plan->code,
            'voucher_code' => 'FAILCHECKOUT',
        ])->assertStatus(502);

        $this->assertDatabaseHas('voucher_redemption_reservations', [
            'voucher_id' => $voucherId,
            'company_id' => $companyId,
            'status' => 'released',
        ]);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_repeated_checkout_with_the_same_idempotency_key_returns_the_original_result(): void
    {
        [$user, $companyId] = $this->customerContext();
        $admin = $this->platformAdmin();
        [$product, $plan] = $this->voucher($admin, 'CHECKOUTREPLAY');
        Http::fake([
            'https://api.mercadopago.com/preapproval' => Http::response([
                'id' => 'preapproval-replay',
                'init_point' => 'https://mercadopago.test/checkout',
            ], 201),
        ]);
        $payload = [
            'product_code' => $product->code,
            'items' => $this->planItems(),
            'cycle' => 'monthly',
            'selection_mode' => 'plan',
            'plan_code' => $plan->code,
            'voucher_code' => 'CHECKOUTREPLAY',
        ];
        $headers = ['Idempotency-Key' => 'checkout-replay-key'];

        $first = $this->actingAs($user)->withSession(['active_company_id' => $companyId])
            ->withHeaders($headers)->postJson('/api/subscriptions/checkout', $payload)->assertCreated();
        $second = $this->withoutExceptionHandling()->actingAs($user)->withSession(['active_company_id' => $companyId])
            ->withHeaders($headers)->postJson('/api/subscriptions/checkout', $payload)->assertCreated();

        $this->assertSame($first->json(), $second->json());
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('payments', 1);
        Http::assertSentCount(1);
    }

    public function test_repeated_preapproval_webhook_does_not_change_subscription_version_or_duplicate_redemption(): void
    {
        [$user, $companyId] = $this->customerContext();
        $admin = $this->platformAdmin();
        [$product, $plan] = $this->voucher($admin, 'IDEMPOTENT');

        Http::fake([
            'https://api.mercadopago.com/preapproval' => Http::response(['id' => 'preapproval-idempotent', 'init_point' => 'https://mercadopago.test/checkout'], 201),
            'https://api.mercadopago.com/preapproval/preapproval-idempotent' => Http::response(['status' => 'authorized'], 200),
        ]);

        $response = $this->actingAs($user)->withSession(['active_company_id' => $companyId])->postJson('/api/subscriptions/checkout', [
            'product_code' => $product->code,
            'items' => $this->planItems(),
            'cycle' => 'monthly',
            'selection_mode' => 'plan',
            'plan_code' => $plan->code,
            'voucher_code' => 'IDEMPOTENT',
        ])->assertCreated();

        $subscriptionId = $response->json('subscription_id');
        $timestamp = (string) now()->timestamp;
        $signature = 'ts='.$timestamp.',v1='.hash_hmac('sha256', 'id:preapproval-idempotent;request-id:req-idempotent;ts:'.$timestamp.';', 'test-secret');
        $headers = ['x-signature' => $signature, 'x-request-id' => 'req-idempotent'];

        $this->withHeaders($headers)->postJson('/api/webhooks/mercado-pago?data.id=preapproval-idempotent', ['type' => 'preapproval'])->assertOk();
        $version = DB::table('subscriptions')->where('id', $subscriptionId)->value('version');
        $this->withHeaders($headers)->postJson('/api/webhooks/mercado-pago?data.id=preapproval-idempotent', ['type' => 'preapproval'])->assertOk();

        $this->assertSame($version, DB::table('subscriptions')->where('id', $subscriptionId)->value('version'));
        $this->assertDatabaseCount('voucher_redemptions', 1);
    }

    public function test_expired_voucher_reservation_is_released_and_no_longer_consumes_a_limit(): void
    {
        [, $companyId] = $this->customerContext();
        $admin = $this->platformAdmin();
        [$product, $plan, $voucherId] = $this->voucher($admin, 'EXPIRED', limit: 1);
        $voucher = DB::table('vouchers')->where('id', $voucherId)->first();

        $reservation = app(VoucherManager::class)->reserve($voucher, $companyId, 'request-expired', [
            'product_id' => $product->id,
            'plan_code' => $plan->code,
        ]);
        DB::table('voucher_redemption_reservations')->where('id', $reservation->id)->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(1, app(VoucherManager::class)->expireReservations());
        $this->assertDatabaseHas('voucher_redemption_reservations', ['id' => $reservation->id, 'status' => 'expired']);
        $this->assertSame($voucherId, app(VoucherManager::class)->findEligible('EXPIRED', $product->id, $companyId, $this->moduleCodes(), $plan->code)->id);
    }

    public function test_redeemed_voucher_cannot_be_edited_and_pending_reservation_cannot_be_deleted(): void
    {
        [$user, $companyId] = $this->customerContext();
        $admin = $this->platformAdmin();
        [$product, $plan, $voucherId] = $this->voucher($admin, 'GOVERNED');

        Http::fake([
            'https://api.mercadopago.com/preapproval' => Http::sequence()
                ->push(['id' => 'preapproval-governed', 'init_point' => 'https://mercadopago.test/checkout'], 201)
                ->push(['id' => 'preapproval-pending', 'init_point' => 'https://mercadopago.test/checkout'], 201),
            'https://api.mercadopago.com/preapproval/preapproval-governed' => Http::response(['status' => 'authorized'], 200),
        ]);
        $response = $this->actingAs($user)->withSession(['active_company_id' => $companyId])->postJson('/api/subscriptions/checkout', [
            'product_code' => $product->code,
            'items' => $this->planItems(),
            'cycle' => 'monthly',
            'selection_mode' => 'plan',
            'plan_code' => $plan->code,
            'voucher_code' => 'GOVERNED',
        ])->assertCreated();

        $subscriptionId = $response->json('subscription_id');
        $timestamp = (string) now()->timestamp;
        $signature = 'ts='.$timestamp.',v1='.hash_hmac('sha256', 'id:preapproval-governed;request-id:req-governed;ts:'.$timestamp.';', 'test-secret');
        $this->withHeaders(['x-signature' => $signature, 'x-request-id' => 'req-governed'])
            ->postJson('/api/webhooks/mercado-pago?data.id=preapproval-governed', ['type' => 'preapproval'])
            ->assertOk();

        $this->actingAs($admin, 'platform')->patchJson("/api/backoffice/vouchers/{$voucherId}", ['discount_value' => 10])
            ->assertUnprocessable();

        [$pendingUser, $pendingCompanyId] = $this->customerContext();
        [$pendingProduct, $pendingPlan, $pendingVoucherId] = $this->voucher($admin, 'PENDINGDELETE');
        $this->actingAs($pendingUser)->withSession(['active_company_id' => $pendingCompanyId])->postJson('/api/subscriptions/checkout', [
            'product_code' => $pendingProduct->code,
            'items' => $this->planItems(),
            'cycle' => 'monthly',
            'selection_mode' => 'plan',
            'plan_code' => $pendingPlan->code,
            'voucher_code' => 'PENDINGDELETE',
        ])->assertCreated();

        $this->actingAs($admin, 'platform')->deleteJson("/api/backoffice/vouchers/{$pendingVoucherId}", ['reason' => 'Reserva pendente.'])
            ->assertUnprocessable();
    }

    public function test_voucher_bound_to_plan_cannot_be_used_on_another_plan(): void
    {
        [$user, $companyId] = $this->customerContext();
        $admin = $this->platformAdmin();
        $product = DB::table('products')->where('code', 'law')->first();
        $plan = DB::table('plans')->where('product_id', $product->id)->where('code', 'law-advocacia')->first();
        DB::table('vouchers')->insert([
            'id' => PrefixedUlid::make('VCH'), 'code' => 'PLANONLY', 'name' => 'Plano específico',
            'discount_type' => 'percentage', 'discount_value' => 10, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => 'ativa', 'created_by_platform_admin_id' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->withSession(['active_company_id' => $companyId])->postJson('/api/subscriptions/checkout', [
            'product_code' => 'law',
            'items' => [['module_code' => 'processos-advocacia', 'quantity' => 1]],
            'cycle' => 'monthly', 'selection_mode' => 'modules', 'voucher_code' => 'PLANONLY',
        ])->assertUnprocessable()->assertJsonPath('message', 'Voucher não é elegível para este plano.');
    }

    public function test_all_voucher_discount_types_are_supported_and_capped(): void
    {
        $manager = new VoucherManager();
        $this->assertSame(100.0, $manager->discount((object) ['discount_type' => 'trial_free', 'discount_value' => 100], 100));
        $this->assertSame(25.0, $manager->discount((object) ['discount_type' => 'percentage', 'discount_value' => 25], 100));
        $this->assertSame(40.0, $manager->discount((object) ['discount_type' => 'fixed', 'discount_value' => 40], 100));
        $this->assertSame(100.0, $manager->discount((object) ['discount_type' => 'commercial_credit', 'discount_value' => 150], 100));
    }

    private function customerContext(): array
    {
        $cpf = '111444777'.str_pad((string) (35 + User::count()), 2, '0', STR_PAD_LEFT);
        $user = User::create([
            'id' => PrefixedUlid::make('USR'), 'name' => 'Cliente Teste', 'cpf' => $cpf,
            'email' => 'cliente-'.User::count().'@example.test', 'password' => Hash::make('SenhaSegura!2026'),
            'status' => 'ativa', 'email_verified_at' => now(),
        ]);
        $companyId = PrefixedUlid::make('EMP');
        $documentNumber = '11222333000'.str_pad((string) (181 + DB::table('companies')->count()), 3, '0', STR_PAD_LEFT);
        DB::table('companies')->insert([
            'id' => $companyId, 'document_type' => 'cnpj', 'document_number' => $documentNumber,
            'legal_name' => 'Empresa de Teste', 'status' => 'ativa', 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $roleId = DB::table('roles')->where('code', 'admin')->value('id');
        DB::table('company_memberships')->insert([
            'id' => PrefixedUlid::make('MBR'), 'company_id' => $companyId, 'user_id' => $user->id,
            'role_id' => $roleId, 'status' => 'ativo', 'version' => 1, 'created_by' => $user->id,
            'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $companyId];
    }

    private function platformAdmin(): PlatformAdmin
    {
        return PlatformAdmin::create([
            'id' => PrefixedUlid::make('PAD'), 'name' => 'Equipe Fokus',
            'email' => 'plataforma-'.PlatformAdmin::count().'@example.test',
            'password' => Hash::make('SenhaInterna!2026'), 'status' => 'ativo',
            'platform_role_id' => PlatformRole::where('code', 'superadministrador')->value('id'),
            'email_verified_at' => now(),
        ]);
    }

    private function voucher(PlatformAdmin $admin, string $code, int $limit = 10): array
    {
        $product = DB::table('products')->where('code', 'law')->first();
        $plan = DB::table('plans')->where('product_id', $product->id)->where('code', 'law-advocacia')->first();
        $voucherId = PrefixedUlid::make('VCH');
        DB::table('vouchers')->insert([
            'id' => $voucherId,
            'code' => $code,
            'name' => $code,
            'discount_type' => 'commercial_credit',
            'discount_value' => 500,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'base_amount' => 64.70,
            'benefit_duration' => 'm1',
            'redemption_limit' => $limit,
            'redemption_limit_per_company' => 1,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => 'ativa',
            'created_by_platform_admin_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$product, $plan, $voucherId];
    }

    private function planItems(): array
    {
        return [
            ['module_code' => 'processos-advocacia', 'quantity' => 1],
            ['module_code' => 'contatos-advocacia', 'quantity' => 1],
            ['module_code' => 'tarefas-advocacia', 'quantity' => 1],
        ];
    }

    private function moduleCodes(): array
    {
        return array_column($this->planItems(), 'module_code');
    }
}
