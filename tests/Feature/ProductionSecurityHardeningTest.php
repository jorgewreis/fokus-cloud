<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MercadoPagoClient;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ProductionSecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_api_requires_a_valid_csrf_token(): void
    {
        app()->instance('env', 'local');
        $payload = ['cpf' => '12345678901'];

        $this->withSession(['_token' => 'valid-csrf-token'])
            ->postJson('/api/auth/request-password-reset', $payload)->assertStatus(419);
        $this->withSession(['_token' => 'valid-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'invalid-token')
            ->postJson('/api/auth/request-password-reset', $payload)->assertStatus(419);
        $this->withSession(['_token' => 'valid-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'valid-csrf-token')
            ->postJson('/api/auth/request-password-reset', $payload)->assertOk();
    }

    public function test_webhook_bypasses_browser_csrf_but_is_rate_limited(): void
    {
        app()->instance('env', 'local');
        Config::set('services.mercado_pago.webhook_secret', 'test-secret');
        Config::set('services.mercado_pago.webhook_rate_limit', 2);

        $this->postJson('/api/webhooks/mercado-pago', [])->assertUnauthorized();
        $this->postJson('/api/webhooks/mercado-pago', [])->assertUnauthorized();
        $this->postJson('/api/webhooks/mercado-pago', [])->assertStatus(429);
    }

    public function test_integration_secrets_can_be_rotated_with_a_short_overlap(): void
    {
        Config::set('services.mercado_pago.webhook_secret', 'new-webhook-secret');
        Config::set('services.mercado_pago.webhook_previous_secrets', ['old-webhook-secret']);
        $timestamp = now()->timestamp;
        $requestId = 'rotation-test';
        $dataId = 'unused-rotation-event';
        $signature = hash_hmac('sha256', "id:{$dataId};request-id:{$requestId};ts:{$timestamp};", 'old-webhook-secret');
        $headers = ['x-signature' => "ts={$timestamp},v1={$signature}", 'x-request-id' => $requestId];
        $this->withHeaders($headers)->postJson("/api/webhooks/mercado-pago?data.id={$dataId}", ['type' => 'unknown'])->assertOk();

        Config::set('services.mercado_pago.webhook_previous_secrets', []);
        $this->withHeaders($headers)->postJson("/api/webhooks/mercado-pago?data.id={$dataId}", ['type' => 'unknown'])->assertUnauthorized();

        Config::set('services.usage.ingestion_secret', 'new-usage-secret');
        Config::set('services.usage.ingestion_previous_secrets', ['old-usage-secret']);
        $this->withHeader('X-Fokus-Usage-Secret', 'old-usage-secret')
            ->postJson('/api/integrations/usage', [])->assertUnprocessable();
        Config::set('services.usage.ingestion_previous_secrets', []);
        $this->withHeader('X-Fokus-Usage-Secret', 'old-usage-secret')
            ->postJson('/api/integrations/usage', [])->assertUnauthorized();
    }

    public function test_security_headers_cover_public_and_unauthorized_api_responses(): void
    {
        app()->instance('env', 'production');

        foreach (['/', '/api/backoffice/auth/me'] as $path) {
            $response = $this->get($path);
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
            $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
            $this->assertStringContainsString("object-src 'none'", $response->headers->get('Content-Security-Policy'));
        }
    }

    public function test_anonymous_api_request_returns_401_without_accept_header(): void
    {
        $this->get('/api/auth/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_password_change_revokes_older_customer_sessions(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        $user = $this->customer();
        $this->otherSession($user);

        $this->actingAs($user)->patchJson('/api/auth/profile', [
            'password' => 'SenhaNova!2026ABC',
            'password_confirmation' => 'SenhaNova!2026ABC',
            'current_password' => 'SenhaCliente!2026',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'older-customer-session']);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_password_reset_revokes_all_customer_sessions(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        $user = $this->customer();
        $this->otherSession($user);
        $token = str_repeat('a', 64);
        DB::table('security_tokens')->insert([
            'id' => PrefixedUlid::make('STK'), 'user_id' => $user->id,
            'purpose' => 'password_reset', 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->postJson('/api/auth/set-password', [
            'token' => $token,
            'password' => 'SenhaNova!2026ABC',
            'password_confirmation' => 'SenhaNova!2026ABC',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'older-customer-session']);
        $this->assertGuest('web');
        $this->assertTrue(Hash::check('SenhaNova!2026ABC', $user->fresh()->password));
    }

    public function test_orphan_checkout_remains_blocked_until_gateway_cancellation_is_confirmed(): void
    {
        $user = $this->customer();
        $companyId = PrefixedUlid::make('COM');
        DB::table('companies')->insert([
            'id' => $companyId, 'document_type' => 'cnpj', 'document_number' => '12345678000100',
            'legal_name' => 'Empresa para compensação', 'status' => 'ativa', 'version' => 1,
            'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $attemptId = PrefixedUlid::make('BCA');
        DB::table('billing_checkout_attempts')->insert([
            'id' => $attemptId, 'company_id' => $companyId, 'user_id' => $user->id,
            'request_key' => hash('sha256', 'orphan-checkout'), 'status' => 'compensation_pending',
            'provider_subscription_id' => 'preapproval-orphan', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $unavailable = Mockery::mock(MercadoPagoClient::class);
        $unavailable->shouldReceive('getPreapproval')->once()->andThrow(new \RuntimeException('Gateway indisponível.'));
        app()->instance(MercadoPagoClient::class, $unavailable);
        $this->artisan('fokus:compensate-checkout-orphans')->assertExitCode(0);
        $this->assertDatabaseHas('billing_checkout_attempts', ['id' => $attemptId, 'status' => 'compensation_pending']);
        $this->assertDatabaseHas('payment_reconciliation_alerts', ['company_id' => $companyId, 'type' => 'checkout_orphan', 'status' => 'aberta']);

        $cancelled = Mockery::mock(MercadoPagoClient::class);
        $cancelled->shouldReceive('getPreapproval')->once()->with('preapproval-orphan')->andReturn(['status' => 'cancelled']);
        app()->instance(MercadoPagoClient::class, $cancelled);
        $this->artisan('fokus:compensate-checkout-orphans')->assertExitCode(0);
        $this->assertDatabaseHas('billing_checkout_attempts', ['id' => $attemptId, 'status' => 'failed']);
        $this->assertDatabaseHas('payment_reconciliation_alerts', ['company_id' => $companyId, 'type' => 'checkout_orphan', 'status' => 'corrigida']);
    }

    private function customer(): User
    {
        return User::create([
            'id' => PrefixedUlid::make('USR'), 'name' => 'Cliente', 'cpf' => '12345678901',
            'email' => 'cliente@example.test', 'password' => 'SenhaCliente!2026', 'status' => 'ativa',
        ]);
    }

    private function otherSession(User $user): void
    {
        DB::table('sessions')->insert([
            'id' => 'older-customer-session', 'user_id' => $user->id,
            'payload' => '', 'last_activity' => now()->timestamp,
        ]);
    }
}
