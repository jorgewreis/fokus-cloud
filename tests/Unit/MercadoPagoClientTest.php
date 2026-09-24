<?php

namespace Tests\Unit;

use App\Services\MercadoPagoClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoClientTest extends TestCase
{
    public function test_client_sends_token_and_idempotency_key_and_sanitizes_payload(): void
    {
        Config::set('services.mercado_pago.access_token', 'sandbox-token');
        Http::fake(['https://api.mercadopago.com/v1/payments/*' => Http::response(['id' => 'pay-1'], 200)]);

        $client = app(MercadoPagoClient::class);
        $this->assertSame('pay-1', $client->getPayment('pay-1')['id']);
        $client->createRefund('pay-1', 10.50, 'refund-key');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sandbox-token') && $request->hasHeader('X-Idempotency-Key', 'refund-key'));
        $this->assertSame('[REDACTED]', $client->sanitizePayload(['access_token' => 'secret'])['access_token']);
        $safe = $client->sanitizePayload(['id' => 'pay-1', 'status' => 'approved', 'identification' => ['number' => '12345678901'], 'card' => ['number' => '4111 1111 1111 1111'], 'raw_customer_field' => 'must-not-persist']);
        $this->assertSame('pay-1', $safe['id']);
        $this->assertArrayNotHasKey('identification', $safe);
        $this->assertArrayNotHasKey('card', $safe);
        $this->assertArrayNotHasKey('raw_customer_field', $safe);
    }

    public function test_sandbox_uses_the_documented_test_email_when_not_configured(): void
    {
        Config::set('services.mercado_pago.environment', 'sandbox');
        Config::set('services.mercado_pago.test_payer_email', null);

        $this->assertSame('test@testuser.com', app(MercadoPagoClient::class)->payerEmail('customer@example.com'));
    }

    public function test_production_uses_the_verified_customer_email(): void
    {
        Config::set('services.mercado_pago.environment', 'production');

        $this->assertSame('customer@example.com', app(MercadoPagoClient::class)->payerEmail(' customer@example.com '));
    }
}
