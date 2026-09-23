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
use Tests\TestCase;

class ReconciliationAndRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Config::set('services.mercado_pago.access_token', 'sandbox-token');
    }

    public function test_reconciliation_is_opened_and_only_superadmin_can_correct_it(): void
    {
        $fixture = $this->fixture('aprovado');
        Http::fake(['https://api.mercadopago.com/preapproval/pre-reconcile' => Http::response(['status' => 'paused'], 200)]);
        $this->artisan('fokus:reconcile-mercado-pago')->assertSuccessful();
        $alert = DB::table('payment_reconciliation_alerts')->first();
        $this->assertNotNull($alert);
        $commercial = $this->admin('administrador_comercial');
        $super = $this->admin('superadministrador');
        $this->actingAs($commercial, 'platform')->patchJson('/api/backoffice/reconciliation/'.$alert->id, ['action' => 'corrigir', 'reason' => 'Revisão'])->assertForbidden();
        $this->actingAs($super, 'platform')->patchJson('/api/backoffice/reconciliation/'.$alert->id, ['action' => 'corrigir', 'reason' => 'Estado remoto confirmado'])->assertOk();
        $this->assertDatabaseHas('subscriptions', ['id' => $fixture['subscription_id'], 'status' => 'suspensa']);
    }

    public function test_refund_requires_approval_and_marks_full_payment_as_refunded(): void
    {
        $fixture = $this->fixture('aprovado');
        $commercial = $this->admin('administrador_comercial');
        $super = $this->admin('superadministrador');
        $response = $this->actingAs($commercial, 'platform')->postJson('/api/backoffice/refunds', ['payment_id' => $fixture['payment_id'], 'amount' => 64.70, 'allowed_case' => 'erro_tecnico', 'reason' => 'Cobrança duplicada identificada.'])->assertCreated();
        $refundId = $response->json('id');
        $this->actingAs($super, 'platform')->patchJson('/api/backoffice/refunds/'.$refundId, ['action' => 'aprovar', 'reason' => 'Aprovado'])->assertOk();
        Http::fake(['https://api.mercadopago.com/v1/payments/pay-refund/refunds' => Http::response(['id' => 'refund-1', 'status' => 'approved'], 201)]);
        $this->actingAs($super, 'platform')->patchJson('/api/backoffice/refunds/'.$refundId, ['action' => 'executar', 'reason' => 'Executado'])->assertOk();
        $this->assertDatabaseHas('refund_requests', ['id' => $refundId, 'status' => 'executado', 'provider_refund_id' => 'refund-1']);
        $this->assertDatabaseHas('payments', ['id' => $fixture['payment_id'], 'status' => 'estornado']);
    }

    public function test_refund_requester_cannot_approve_own_request(): void
    {
        $fixture = $this->fixture('aprovado');
        $requester = $this->admin('superadministrador');
        $approver = $this->admin('superadministrador');
        $refund = $this->actingAs($requester, 'platform')->postJson('/api/backoffice/refunds', [
            'payment_id' => $fixture['payment_id'], 'amount' => 20, 'allowed_case' => 'erro_tecnico', 'reason' => 'Solicitação para teste de segregação.',
        ])->assertCreated()->json();

        $this->actingAs($requester, 'platform')->patchJson('/api/backoffice/refunds/'.$refund['id'], [
            'action' => 'aprovar', 'reason' => 'Tentativa do próprio solicitante.',
        ])->assertForbidden();
        $this->actingAs($requester, 'platform')->patchJson('/api/backoffice/refunds/'.$refund['id'], [
            'action' => 'recusar', 'reason' => 'Tentativa do próprio solicitante.',
        ])->assertForbidden();
        $this->assertDatabaseHas('refund_requests', ['id' => $refund['id'], 'status' => 'solicitado']);

        $this->actingAs($approver, 'platform')->patchJson('/api/backoffice/refunds/'.$refund['id'], [
            'action' => 'aprovar', 'reason' => 'Solicitação conferida por outra pessoa.',
        ])->assertOk();
        $this->assertDatabaseHas('refund_requests', ['id' => $refund['id'], 'status' => 'aprovado', 'approved_by_platform_admin_id' => $approver->id]);
    }

    public function test_billing_lists_filter_server_side_and_keep_paginated_details(): void
    {
        $fixture = $this->fixture('aprovado');
        $commercial = $this->admin('administrador_comercial');
        $today = now()->toDateString();

        $this->actingAs($commercial, 'platform')
            ->getJson('/api/backoffice/payments?q=Empresa%20Financeira&status=aprovado&date_from='.$today.'&date_to='.$today.'&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('data.0.company_name', 'Empresa Financeira')
            ->assertJsonPath('data.0.provider_payment_id', 'pay-refund');

        $this->actingAs($commercial, 'platform')
            ->getJson('/api/backoffice/payments?q=Empresa%20Financeira&date_from='.$today.'&date_to='.$today.'&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonCount(0, 'data');

        $alertId = PrefixedUlid::make('RCA');
        DB::table('payment_reconciliation_alerts')->insert([
            'id' => $alertId, 'company_id' => $this->companyIdForPayment($fixture['payment_id']),
            'subscription_id' => $fixture['subscription_id'], 'payment_id' => $fixture['payment_id'],
            'fingerprint' => hash('sha256', $alertId), 'type' => 'payment_status',
            'internal_status' => 'recusado', 'mercado_pago_status' => 'approved', 'impact' => 'alto',
            'status' => 'aberta', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($commercial, 'platform')
            ->getJson('/api/backoffice/reconciliation?q=Empresa%20Financeira&status=aberta&impact=alto&date_from='.$today.'&date_to='.$today)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.company_name', 'Empresa Financeira');
        $this->actingAs($commercial, 'platform')
            ->getJson('/api/backoffice/reconciliation/'.$alertId)
            ->assertOk()
            ->assertJsonPath('company_name', 'Empresa Financeira');

        $refund = $this->actingAs($commercial, 'platform')->postJson('/api/backoffice/refunds', [
            'payment_id' => $fixture['payment_id'], 'amount' => 20, 'allowed_case' => 'erro_tecnico', 'reason' => 'Falha técnica confirmada.',
        ])->assertCreated()->json();
        $this->actingAs($commercial, 'platform')
            ->getJson('/api/backoffice/refunds?q=pay-refund&status=solicitado&date_from='.$today.'&date_to='.$today)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $refund['id'])
            ->assertJsonPath('data.0.company_name', 'Empresa Financeira')
            ->assertJsonPath('data.0.provider_payment_id', 'pay-refund');
        $this->actingAs($commercial, 'platform')
            ->getJson('/api/backoffice/refunds/'.$refund['id'])
            ->assertOk()
            ->assertJsonPath('company_name', 'Empresa Financeira');
    }

    public function test_billing_list_date_range_must_be_valid_and_ordered(): void
    {
        $commercial = $this->admin('administrador_comercial');
        $this->actingAs($commercial, 'platform')
            ->getJson('/api/backoffice/payments?date_from=2026-09-20&date_to=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
        $this->actingAs($commercial, 'platform')
            ->getJson('/api/backoffice/refunds?date_from=not-a-date')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');
    }

    private function companyIdForPayment(string $paymentId): string
    {
        return (string) DB::table('payments')->where('id', $paymentId)->value('company_id');
    }

    private function admin(string $role): PlatformAdmin
    {
        return PlatformAdmin::create(['id' => PrefixedUlid::make('PAD'), 'name' => 'Billing Admin', 'email' => $role.'-'.PlatformAdmin::count().'@example.test', 'password' => Hash::make('Senha!2026'), 'status' => 'ativo', 'platform_role_id' => PlatformRole::where('code', $role)->value('id'), 'email_verified_at' => now()]);
    }

    private function fixture(string $paymentStatus): array
    {
        $userId = PrefixedUlid::make('USR');
        DB::table('users')->insert(['id' => $userId, 'name' => 'Cliente Financeiro', 'cpf' => '52998224725', 'email' => 'cliente-financeiro-'.DB::table('users')->count().'@example.test', 'password' => Hash::make('Senha!2026'), 'status' => 'ativa', 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $companyId = PrefixedUlid::make('COM');
        $product = DB::table('products')->where('code', 'law')->first();
        DB::table('companies')->insert(['id' => $companyId, 'document_type' => 'cnpj', 'document_number' => '22345678000100', 'legal_name' => 'Empresa Financeira', 'status' => 'ativa', 'version' => 1, 'created_by' => $userId, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        $subscriptionId = PrefixedUlid::make('ASS');
        DB::table('subscriptions')->insert(['id' => $subscriptionId, 'company_id' => $companyId, 'product_id' => $product->id, 'status' => 'ativa', 'open_company_product' => $companyId.'-'.$product->id, 'version' => 1, 'billing_cycle' => 'monthly', 'current_period_starts_at' => now(), 'current_period_ends_at' => now()->addMonth(), 'provider_subscription_id' => 'pre-reconcile', 'created_by' => $userId, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        $paymentId = PrefixedUlid::make('PAG');
        DB::table('payments')->insert(['id' => $paymentId, 'company_id' => $companyId, 'subscription_id' => $subscriptionId, 'provider' => 'mercado_pago', 'provider_payment_id' => 'pay-refund', 'status' => $paymentStatus, 'amount' => 64.70, 'currency' => 'BRL', 'provider_subscription_id' => 'pre-reconcile', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return ['subscription_id' => $subscriptionId, 'payment_id' => $paymentId];
    }
}
