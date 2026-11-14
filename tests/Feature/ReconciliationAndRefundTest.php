<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Services\PrefixedUlid;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconciliationAndRefundTest extends TestCase
{
    use DatabaseMigrations;

    // Refund execution must commit its claim before contacting the provider.
    // Avoid RefreshDatabase's enclosing transaction and SQLite rollback of named FKs.
    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

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
        Http::fake(['https://api.mercadopago.com/v1/payments/pay-refund/refunds' => Http::response(['id' => 'refund-1', 'payment_id' => 'pay-refund', 'amount' => 64.70, 'status' => 'approved'], 201)]);
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

    public function test_closed_or_stale_reconciliation_cannot_change_subscription(): void
    {
        $fixture = $this->fixture('aprovado');
        $admin = $this->admin('superadministrador');
        $id = PrefixedUlid::make('RCA');
        DB::table('payment_reconciliation_alerts')->insert([
            'id' => $id, 'company_id' => $this->companyIdForPayment($fixture['payment_id']),
            'subscription_id' => $fixture['subscription_id'], 'fingerprint' => hash('sha256', $id),
            'type' => 'subscription_status', 'internal_status' => 'ativa', 'mercado_pago_status' => 'paused',
            'impact' => 'medio', 'status' => 'descartada', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake(['https://api.mercadopago.com/preapproval/pre-reconcile' => Http::response(['status' => 'paused'], 200)]);
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/reconciliation/'.$id, ['action' => 'corrigir', 'reason' => 'Análise'])->assertStatus(409);
        DB::table('payment_reconciliation_alerts')->where('id', $id)->update(['status' => 'aberta']);
        DB::table('subscriptions')->where('id', $fixture['subscription_id'])->update(['status' => 'aguardando_pagamento']);
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/reconciliation/'.$id, ['action' => 'corrigir', 'reason' => 'Análise'])->assertStatus(409);
        $this->assertDatabaseHas('subscriptions', ['id' => $fixture['subscription_id'], 'status' => 'aguardando_pagamento']);
        $this->assertDatabaseHas('payment_reconciliation_alerts', ['id' => $id, 'status' => 'aberta']);
    }

    public function test_reconciliation_rechecks_provider_before_applying(): void
    {
        $fixture = $this->fixture('aprovado');
        $admin = $this->admin('superadministrador');
        $id = PrefixedUlid::make('RCA');
        DB::table('payment_reconciliation_alerts')->insert([
            'id' => $id, 'company_id' => $this->companyIdForPayment($fixture['payment_id']),
            'subscription_id' => $fixture['subscription_id'], 'fingerprint' => hash('sha256', $id),
            'type' => 'subscription_status', 'internal_status' => 'ativa', 'mercado_pago_status' => 'paused',
            'impact' => 'medio', 'status' => 'aberta', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake(['https://api.mercadopago.com/preapproval/pre-reconcile' => Http::response(['status' => 'authorized'], 200)]);
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/reconciliation/'.$id, ['action' => 'corrigir', 'reason' => 'Análise'])->assertStatus(409);
        $this->assertDatabaseHas('subscriptions', ['id' => $fixture['subscription_id'], 'status' => 'ativa']);
    }

    public function test_refund_unconfirmed_response_is_not_executed_or_retried(): void
    {
        $fixture = $this->fixture('aprovado');
        $requester = $this->admin('administrador_comercial');
        $admin = $this->admin('superadministrador');
        $id = $this->actingAs($requester, 'platform')->postJson('/api/backoffice/refunds', ['payment_id' => $fixture['payment_id'], 'amount' => 64.70, 'allowed_case' => 'erro_tecnico', 'reason' => 'Teste'])->assertCreated()->json('id');
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'aprovar', 'reason' => 'Teste'])->assertOk();
        Http::fake(['https://api.mercadopago.com/v1/payments/pay-refund/refunds' => Http::response([], 200)]);
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'executar', 'reason' => 'Teste'])->assertStatus(202)->assertJsonPath('status', 'executando');
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'executar', 'reason' => 'Teste'])->assertStatus(409);
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'recusar', 'reason' => 'Teste'])->assertUnprocessable();
        Http::assertSentCount(1);
        $this->assertDatabaseHas('refund_requests', ['id' => $id, 'status' => 'executando', 'provider_refund_id' => null]);
        $this->assertDatabaseHas('payments', ['id' => $fixture['payment_id'], 'status' => 'aprovado']);
    }

    public function test_refund_rejects_amount_below_one_cent_or_more_than_two_decimals(): void
    {
        $fixture = $this->fixture('aprovado');
        $admin = $this->admin('administrador_comercial');
        foreach ([0.001, 1.234] as $amount) {
            $this->actingAs($admin, 'platform')->postJson('/api/backoffice/refunds', ['payment_id' => $fixture['payment_id'], 'amount' => $amount, 'allowed_case' => 'erro_tecnico', 'reason' => 'Teste'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('refund_requests', 0);
    }

    public function test_refund_timeout_remains_pending_and_cannot_be_declined_or_reexecuted(): void
    {
        $fixture = $this->fixture('aprovado');
        $requester = $this->admin('administrador_comercial');
        $admin = $this->admin('superadministrador');
        $id = $this->actingAs($requester, 'platform')->postJson('/api/backoffice/refunds', ['payment_id' => $fixture['payment_id'], 'amount' => 10, 'allowed_case' => 'erro_tecnico', 'reason' => 'Teste'])->assertCreated()->json('id');
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'aprovar', 'reason' => 'Teste'])->assertOk();
        Http::fake(fn () => throw new \RuntimeException('timeout simulado'));
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'executar', 'reason' => 'Teste'])->assertStatus(202)->assertJsonPath('status', 'executando');
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'executar', 'reason' => 'Teste'])->assertStatus(409);
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'recusar', 'reason' => 'Teste'])->assertUnprocessable();
        $this->assertDatabaseHas('payments', ['id' => $fixture['payment_id'], 'status' => 'aprovado']);
        $this->assertDatabaseHas('refund_requests', ['id' => $id, 'status' => 'executando']);
    }

    public function test_refund_mismatched_provider_response_never_completes(): void
    {
        $fixture = $this->fixture('aprovado');
        $requester = $this->admin('administrador_comercial');
        $admin = $this->admin('superadministrador');
        $cases = [
            ['id' => 'r-1', 'payment_id' => 'wrong', 'amount' => 10, 'status' => 'approved'],
            ['id' => 'r-2', 'payment_id' => 'pay-refund', 'amount' => 11.01, 'status' => 'approved'],
            ['id' => 'r-3', 'payment_id' => 'pay-refund', 'amount' => 12, 'status' => 'pending'],
        ];
        foreach ($cases as $i => $payload) {
            $id = $this->actingAs($requester, 'platform')->postJson('/api/backoffice/refunds', ['payment_id' => $fixture['payment_id'], 'amount' => 10 + $i, 'allowed_case' => 'erro_tecnico', 'reason' => 'Teste'])->assertCreated()->json('id');
            $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'aprovar', 'reason' => 'Teste'])->assertOk();
            Http::fake(['https://api.mercadopago.com/v1/payments/pay-refund/refunds' => Http::response($payload, 200)]);
            $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'executar', 'reason' => 'Teste'])->assertStatus(202)->assertJsonPath('status', 'executando');
            $this->assertDatabaseHas('refund_requests', ['id' => $id, 'status' => 'executando']);
        }
        $this->assertDatabaseHas('payments', ['id' => $fixture['payment_id'], 'status' => 'aprovado']);
    }

    public function test_refund_execution_refuses_existing_transaction_before_provider_call(): void
    {
        $fixture = $this->fixture('aprovado');
        $requester = $this->admin('administrador_comercial');
        $admin = $this->admin('superadministrador');
        $id = $this->actingAs($requester, 'platform')->postJson('/api/backoffice/refunds', ['payment_id' => $fixture['payment_id'], 'amount' => 10, 'allowed_case' => 'erro_tecnico', 'reason' => 'Teste'])->assertCreated()->json('id');
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'aprovar', 'reason' => 'Teste'])->assertOk();
        Http::fake();
        DB::beginTransaction();
        try {
            $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'executar', 'reason' => 'Teste'])->assertStatus(409);
        } finally {
            DB::rollBack();
        }
        Http::assertNothingSent();
        $this->assertDatabaseHas('refund_requests', ['id' => $id, 'status' => 'aprovado']);
    }

    public function test_overlapping_refund_execution_sends_only_one_provider_request(): void
    {
        $fixture = $this->fixture('aprovado');
        $requester = $this->admin('administrador_comercial');
        $admin = $this->admin('superadministrador');
        $id = $this->actingAs($requester, 'platform')->postJson('/api/backoffice/refunds', ['payment_id' => $fixture['payment_id'], 'amount' => 10, 'allowed_case' => 'erro_tecnico', 'reason' => 'Teste'])->assertCreated()->json('id');
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'aprovar', 'reason' => 'Teste'])->assertOk();
        $overlapStatus = null;
        $defaultConnection = DB::getDefaultConnection();
        $mysql = DB::connection()->getDriverName() === 'mysql';
        $firstConnectionId = $mysql ? DB::selectOne('SELECT CONNECTION_ID() AS id')->id : null;
        if ($mysql) {
            Config::set('database.connections.billing_overlap', config('database.connections.'.$defaultConnection));
        }
        $secondConnectionId = null;
        Http::fake(function () use ($admin, $id, $mysql, $defaultConnection, &$overlapStatus, &$secondConnectionId) {
            try {
                if ($mysql) {
                    DB::setDefaultConnection('billing_overlap');
                    $secondConnectionId = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
                }
                $overlapStatus = $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'executar', 'reason' => 'Segundo'])->status();
            } finally {
                DB::setDefaultConnection($defaultConnection);
            }

            return Http::response(['id' => 'refund-overlap', 'payment_id' => 'pay-refund', 'amount' => 10, 'status' => 'approved'], 201);
        });
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'executar', 'reason' => 'Primeiro'])->assertOk()->assertJsonPath('status', 'executado');
        $this->assertSame(409, $overlapStatus);
        if ($mysql) {
            $this->assertNotSame($firstConnectionId, $secondConnectionId);
            DB::purge('billing_overlap');
        }
        Http::assertSentCount(1);
        $this->assertDatabaseHas('refund_requests', ['id' => $id, 'status' => 'executado', 'provider_refund_id' => 'refund-overlap']);
    }

    public function test_pending_refund_with_provider_id_can_be_checked_without_another_post(): void
    {
        $fixture = $this->fixture('aprovado');
        $requester = $this->admin('administrador_comercial');
        $admin = $this->admin('superadministrador');
        $id = $this->actingAs($requester, 'platform')->postJson('/api/backoffice/refunds', ['payment_id' => $fixture['payment_id'], 'amount' => 64.70, 'allowed_case' => 'erro_tecnico', 'reason' => 'Teste'])->assertCreated()->json('id');
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'aprovar', 'reason' => 'Teste'])->assertOk();
        Http::fake([
            'https://api.mercadopago.com/v1/payments/pay-refund/refunds/refund-pending' => Http::sequence()
                ->push(['id' => 'outro', 'payment_id' => 'pay-refund', 'amount' => 64.70, 'status' => 'approved'], 200)
                ->push([], 500)
                ->push(['id' => 'refund-pending', 'payment_id' => 'pay-refund', 'amount' => 64.70, 'status' => 'approved'], 200),
            'https://api.mercadopago.com/v1/payments/pay-refund/refunds' => Http::response(['id' => 'refund-pending', 'payment_id' => 'pay-refund', 'amount' => 64.70, 'status' => 'pending'], 200),
        ]);
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'executar', 'reason' => 'Teste'])->assertStatus(202)->assertJsonPath('provider_refund_id', 'refund-pending');
        $this->actingAs($requester, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'consultar', 'reason' => 'Teste'])->assertForbidden();
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'consultar', 'reason' => 'Teste'])->assertStatus(202)->assertJsonPath('provider_refund_id', 'refund-pending');
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'consultar', 'reason' => 'Teste'])->assertStatus(202)->assertJsonPath('status', 'executando');
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'consultar', 'reason' => 'Teste'])->assertOk()->assertJsonPath('status', 'executado');
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/refunds/refund-pending'));
        $this->assertDatabaseHas('payments', ['id' => $fixture['payment_id'], 'status' => 'estornado']);
    }

    public function test_refund_without_provider_identifier_cannot_be_guessed_or_consulted(): void
    {
        $fixture = $this->fixture('aprovado');
        $requester = $this->admin('administrador_comercial');
        $admin = $this->admin('superadministrador');
        $id = $this->actingAs($requester, 'platform')->postJson('/api/backoffice/refunds', ['payment_id' => $fixture['payment_id'], 'amount' => 10, 'allowed_case' => 'erro_tecnico', 'reason' => 'Teste'])->assertCreated()->json('id');
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'aprovar', 'reason' => 'Teste'])->assertOk();
        Http::fake(['*' => Http::response([], 200)]);
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'executar', 'reason' => 'Teste'])->assertStatus(202);
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'consultar', 'reason' => 'Teste', 'provider_refund_id' => 'guessed'])->assertStatus(409);
        Http::assertSentCount(1);
    }

    public function test_refund_does_not_confirm_if_payment_provider_identifier_changes_in_flight(): void
    {
        $fixture = $this->fixture('aprovado');
        $requester = $this->admin('administrador_comercial');
        $admin = $this->admin('superadministrador');
        $id = $this->actingAs($requester, 'platform')->postJson('/api/backoffice/refunds', ['payment_id' => $fixture['payment_id'], 'amount' => 64.70, 'allowed_case' => 'erro_tecnico', 'reason' => 'Teste'])->assertCreated()->json('id');
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'aprovar', 'reason' => 'Teste'])->assertOk();
        Http::fake(function () use ($fixture) {
            DB::table('payments')->where('id', $fixture['payment_id'])->update(['provider_payment_id' => 'pay-changed']);

            return Http::response(['id' => 'refund-1', 'payment_id' => 'pay-refund', 'amount' => 64.70, 'status' => 'approved'], 201);
        });
        $this->actingAs($admin, 'platform')->patchJson('/api/backoffice/refunds/'.$id, ['action' => 'executar', 'reason' => 'Teste'])->assertStatus(202)->assertJsonPath('provider_refund_id', null);
        $this->assertDatabaseHas('payments', ['id' => $fixture['payment_id'], 'status' => 'aprovado', 'provider_payment_id' => 'pay-changed']);
        $this->assertDatabaseHas('refund_requests', ['id' => $id, 'status' => 'executando']);
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
