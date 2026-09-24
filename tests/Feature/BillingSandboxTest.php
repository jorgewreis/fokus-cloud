<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BillingSandboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Config::set('services.mercado_pago.access_token', 'sandbox-token');
        Config::set('services.mercado_pago.webhook_secret', 'test-secret');
    }

    public function test_valid_signed_payment_webhook_is_idempotent_and_activates_subscription(): void
    {
        $fixture = $this->fixture();
        Http::fake(['https://api.mercadopago.com/v1/payments/pay-sandbox' => Http::response([
            'id' => 'pay-sandbox', 'status' => 'approved', 'external_reference' => $fixture['payment_id'], 'preapproval_id' => 'pre-sandbox', 'transaction_amount' => 64.70,
        ], 200)]);
        $timestamp = (string) now()->timestamp;
        $signature = 'ts='.$timestamp.',v1='.hash_hmac('sha256', 'id:pay-sandbox;request-id:req-sandbox;ts:'.$timestamp.';', 'test-secret');
        $headers = ['x-signature' => $signature, 'x-request-id' => 'req-sandbox'];

        $sensitivePayload = ['type' => 'payment', 'access_token' => 'webhook-secret', 'identification' => ['number' => '12345678901'], 'card' => ['number' => '4111 1111 1111 1111']];
        $this->withHeaders($headers)->postJson('/api/webhooks/mercado-pago?data.id=pay-sandbox', $sensitivePayload)->assertOk();
        $this->withHeaders($headers)->postJson('/api/webhooks/mercado-pago?data.id=pay-sandbox', $sensitivePayload)->assertOk();

        $this->assertDatabaseHas('payments', ['id' => $fixture['payment_id'], 'status' => 'aprovado', 'provider_payment_id' => 'pay-sandbox']);
        $this->assertDatabaseHas('subscriptions', ['id' => $fixture['subscription_id'], 'status' => 'ativa']);
        $this->assertDatabaseCount('billing_provider_events', 1);
        $platformEvents = DB::table('platform_audit_events')->where('action', 'billing.payment_status_updated')->get();
        $this->assertCount(1, $platformEvents);
        $this->assertSame('gateway', $platformEvents->first()->actor_type);
        $this->assertSame('webhook', $platformEvents->first()->origin_channel);
        $this->assertSame('req-sandbox', $platformEvents->first()->correlation_id);
        $eventPayload = DB::table('billing_provider_events')->value('payload_sanitized');
        $this->assertStringNotContainsString('webhook-secret', $eventPayload);
        $this->assertStringNotContainsString('12345678901', $eventPayload);
        $this->assertStringNotContainsString('4111 1111 1111 1111', $eventPayload);
        $this->assertDatabaseHas('audit_events', ['company_id' => $fixture['company_id'], 'entity_type' => 'payment', 'entity_id' => $fixture['payment_id'], 'actor_type' => 'gateway']);
    }

    public function test_invalid_signature_does_not_call_gateway_or_change_data(): void
    {
        $fixture = $this->fixture();
        Http::fake();
        $this->withHeaders(['x-signature' => 'ts='.now()->timestamp.',v1=invalid', 'x-request-id' => 'req-invalid'])
            ->postJson('/api/webhooks/mercado-pago?data.id=pay-invalid', ['type' => 'payment'])->assertUnauthorized();
        Http::assertNothingSent();
        $this->assertDatabaseHas('payments', ['id' => $fixture['payment_id'], 'status' => 'aguardando_pagamento']);
        $this->assertDatabaseCount('billing_provider_events', 0);
    }

    public function test_authorized_payment_webhook_updates_payment_and_subscription(): void
    {
        $fixture = $this->fixture();
        Http::fake([
            'https://api.mercadopago.com/authorized_payments/auth-sandbox' => Http::response([
                'id' => 'auth-sandbox',
                'preapproval_id' => 'pre-sandbox',
                'payment' => [
                    'id' => 'pay-authorized',
                    'status' => 'approved',
                    'transaction_amount' => 64.70,
                    'date_approved' => now()->toISOString(),
                ],
            ], 200),
        ]);

        $this->withoutExceptionHandling()->withHeaders($this->signedHeaders('auth-sandbox', 'req-authorized'))
            ->postJson('/api/webhooks/mercado-pago?data.id=auth-sandbox', ['type' => 'subscription_authorized_payment'])
            ->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $fixture['payment_id'],
            'status' => 'aprovado',
            'provider_payment_id' => 'pay-authorized',
            'provider_authorized_payment_id' => 'auth-sandbox',
        ]);
        $this->assertDatabaseHas('subscriptions', ['id' => $fixture['subscription_id'], 'status' => 'ativa']);
    }

    public function test_rejected_payment_starts_seven_day_tolerance_and_command_suspends_after_expiry(): void
    {
        $fixture = $this->fixture();
        Http::fake([
            'https://api.mercadopago.com/v1/payments/pay-rejected' => Http::response([
                'id' => 'pay-rejected',
                'status' => 'rejected',
                'external_reference' => $fixture['payment_id'],
                'preapproval_id' => 'pre-sandbox',
            ], 200),
        ]);

        $this->withHeaders($this->signedHeaders('pay-rejected', 'req-rejected'))
            ->postJson('/api/webhooks/mercado-pago?data.id=pay-rejected', ['type' => 'payment'])
            ->assertOk();

        $this->assertDatabaseHas('subscriptions', ['id' => $fixture['subscription_id'], 'status' => 'inadimplente']);
        $graceEndsAt = Carbon::parse(DB::table('subscriptions')->where('id', $fixture['subscription_id'])->value('grace_ends_at'));
        $this->assertTrue($graceEndsAt->greaterThan(now()->addDays(6)));

        $this->travel(8)->days();
        $this->artisan('fokus:expire-subscription-tolerance')->assertSuccessful();
        $this->assertDatabaseHas('subscriptions', ['id' => $fixture['subscription_id'], 'status' => 'suspensa']);
    }

    private function signedHeaders(string $dataId, string $requestId): array
    {
        $timestamp = (string) now()->timestamp;
        return [
            'x-signature' => 'ts='.$timestamp.',v1='.hash_hmac('sha256', 'id:'.$dataId.';request-id:'.$requestId.';ts:'.$timestamp.';', 'test-secret'),
            'x-request-id' => $requestId,
        ];
    }

    private function fixture(): array
    {
        $admin = PlatformAdmin::create(['id' => PrefixedUlid::make('PAD'), 'name' => 'Billing Admin', 'email' => 'billing-'.PlatformAdmin::count().'@example.test', 'password' => Hash::make('Senha!2026'), 'status' => 'ativo', 'platform_role_id' => PlatformRole::where('code', 'superadministrador')->value('id'), 'email_verified_at' => now()]);
        $userId = PrefixedUlid::make('USR');
        DB::table('users')->insert(['id' => $userId, 'name' => 'Cliente Billing', 'cpf' => '52998224725', 'email' => 'cliente-billing@example.test', 'password' => Hash::make('Senha!2026'), 'status' => 'ativa', 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $companyId = PrefixedUlid::make('COM');
        $product = DB::table('products')->where('code', 'law')->first();
        DB::table('companies')->insert(['id' => $companyId, 'document_type' => 'cnpj', 'document_number' => '12345678000100', 'legal_name' => 'Empresa Billing', 'status' => 'ativa', 'version' => 1, 'created_by' => $userId, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        $subscriptionId = PrefixedUlid::make('ASS');
        DB::table('subscriptions')->insert(['id' => $subscriptionId, 'company_id' => $companyId, 'product_id' => $product->id, 'status' => 'aguardando_pagamento', 'open_company_product' => $companyId.'-'.$product->id, 'version' => 1, 'billing_cycle' => 'monthly', 'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth(), 'provider_subscription_id' => 'pre-sandbox', 'created_by' => $userId, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        $paymentId = PrefixedUlid::make('PAG');
        DB::table('payments')->insert(['id' => $paymentId, 'company_id' => $companyId, 'subscription_id' => $subscriptionId, 'provider' => 'mercado_pago', 'status' => 'aguardando_pagamento', 'amount' => 64.70, 'currency' => 'BRL', 'provider_subscription_id' => 'pre-sandbox', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return ['admin' => $admin, 'company_id' => $companyId, 'subscription_id' => $subscriptionId, 'payment_id' => $paymentId];
    }
}
