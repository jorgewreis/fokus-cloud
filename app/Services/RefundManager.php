<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RefundManager
{
    public const CASES = ['cobranca_duplicada', 'erro_tecnico', 'acordo_comercial', 'arrependimento_7_dias'];

    public function request(array $data, object $admin): object
    {
        return DB::transaction(function () use ($data, $admin): object {
            $payment = DB::table('payments')->where('id', $data['payment_id'])->lockForUpdate()->first();
            abort_unless($payment, 404, 'Pagamento não encontrado.');
            abort_unless($payment->status === 'aprovado', 422, 'Somente pagamentos aprovados podem ser reembolsados.');
            abort_unless(in_array($data['allowed_case'], self::CASES, true), 422, 'Caso de reembolso inválido.');
            $amount = (float) $data['amount'];
            abort_unless(is_finite($amount) && $amount >= 0.01 && $amount === round($amount, 2), 422, 'O valor deve ser positivo e ter no máximo duas casas decimais.');
            if ($data['allowed_case'] === 'arrependimento_7_dias') {
                abort_unless(now()->lte($payment->created_at ? Carbon::parse($payment->created_at)->addDays(7) : now()), 422, 'O prazo de arrependimento expirou.');
            }
            $already = (float) DB::table('refund_requests')->where('payment_id', $payment->id)->whereIn('status', ['solicitado', 'aprovado', 'executando', 'executado'])->sum('amount');
            abort_unless((float) $data['amount'] > 0 && (float) $data['amount'] <= max(0, (float) $payment->amount - $already), 422, 'O valor excede o saldo disponível para reembolso.');
            abort_if(DB::table('refund_requests')->where('payment_id', $payment->id)->where('amount', round((float) $data['amount'], 2))->whereIn('status', ['solicitado', 'aprovado', 'executando', 'executado'])->exists(), 422, 'Já existe um reembolso para este valor e pagamento.');

            $id = PrefixedUlid::make('RFD');
            DB::table('refund_requests')->insert([
                'id' => $id, 'company_id' => $payment->company_id, 'subscription_id' => $payment->subscription_id, 'payment_id' => $payment->id,
                'requested_by_platform_admin_id' => $admin->id, 'reason' => app(AuditSanitizer::class)->sanitizeText((string) $data['reason']), 'allowed_case' => $data['allowed_case'],
                'amount' => round((float) $data['amount'], 2), 'status' => 'solicitado', 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('refund_requests')->where('id', $id)->first();
        });
    }

    public function action(string $id, string $action, string $reason, object $admin, MercadoPagoClient $client, PlatformAudit $audit): object
    {
        if (in_array($action, ['executar', 'consultar'], true)) {
            $this->assertProviderRefundUniqueness();
        }

        if ($action === 'consultar') {
            return $this->consultPending($id, $reason, $admin, $client, $audit);
        }

        if ($action !== 'executar') {
            return DB::transaction(function () use ($id, $action, $reason, $admin, $audit): object {
                $refund = DB::table('refund_requests')->where('id', $id)->lockForUpdate()->first();
                abort_unless($refund, 404, 'Solicitação de reembolso não encontrada.');
                abort_unless(in_array($action, ['aprovar', 'recusar'], true), 422, 'Ação de reembolso inválida.');
                abort_unless($refund->requested_by_platform_admin_id !== $admin->id, 403, 'Quem solicitou o reembolso não pode decidir sobre a própria solicitação.');
                if ($action === 'aprovar') {
                    abort_unless($refund->status === 'solicitado', 422, 'A solicitação não está pendente de aprovação.');
                    DB::table('refund_requests')->where('id', $id)->update(['status' => 'aprovado', 'approved_by_platform_admin_id' => $admin->id, 'approved_at' => now(), 'updated_at' => now()]);
                    $audit->record($admin->id, 'billing.refund_approved', 'refund_request', $id, $refund->company_id, $reason, before: ['status' => $refund->status], after: ['status' => 'aprovado', 'amount' => $refund->amount]);
                } else {
                    abort_unless(in_array($refund->status, ['solicitado', 'aprovado'], true), 422, 'A solicitação não pode ser recusada neste estado.');
                    DB::table('refund_requests')->where('id', $id)->update(['status' => 'recusado', 'approved_by_platform_admin_id' => $admin->id, 'refused_at' => now(), 'updated_at' => now()]);
                    $audit->record($admin->id, 'billing.refund_refused', 'refund_request', $id, $refund->company_id, $reason, before: ['status' => $refund->status], after: ['status' => 'recusado']);
                }

                return DB::table('refund_requests')->where('id', $id)->first();
            });
        }

        // The claim must commit before any irreversible provider call. A surrounding
        // transaction would turn it into a savepoint and could erase that claim.
        abort_if(DB::transactionLevel() !== 0, 409, 'A execução de reembolso não pode iniciar dentro de outra transação.');
        [$refund, $providerPaymentId] = DB::transaction(function () use ($id, $reason, $admin, $audit): array {
            $refund = DB::table('refund_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($refund, 404, 'Solicitação de reembolso não encontrada.');
            abort_unless($refund->status === 'aprovado', 409, 'O reembolso precisa estar aprovado e sem execução pendente.');
            $providerPaymentId = DB::table('payments')->where('id', $refund->payment_id)->value('provider_payment_id');
            abort_unless($providerPaymentId, 422, 'O pagamento não possui identificador no gateway.');
            DB::table('refund_requests')->where('id', $id)->update([
                'status' => 'executando',
                'provider_payload_sanitized' => json_encode(['fokus_payment_binding_sha256' => $this->paymentBinding($refund->id, (string) $providerPaymentId)]),
                'updated_at' => now(),
            ]);
            $audit->record($admin->id, 'billing.refund_execution_started', 'refund_request', $id, $refund->company_id, $reason, before: ['status' => 'aprovado'], after: ['status' => 'executando']);

            return [$refund, (string) $providerPaymentId];
        });

        // A timeout or an unrecognized response is ambiguous: the provider may
        // already have processed the request. Never automatically resend or release it.
        try {
            $remote = $client->createRefund($providerPaymentId, (float) $refund->amount, 'refund-'.$refund->id);
        } catch (\Throwable $exception) {
            $audit->record($admin->id, 'billing.refund_execution_unconfirmed', 'refund_request', $id, $refund->company_id, $reason, after: ['status' => 'executando']);

            return DB::table('refund_requests')->where('id', $id)->first();
        }

        return $this->applyProviderResponse($id, $providerPaymentId, $remote, $reason, $admin, $client, $audit);
    }

    private function assertProviderRefundUniqueness(): void
    {
        // The database constraint is the concurrency guard, including on older installations.
        // Unknown drivers, prefix/partial indexes and metadata failures fail closed.
        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'mysql') {
                $indexes = DB::select("SELECT COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique, SUB_PART AS sub_part FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refund_requests' AND INDEX_NAME = 'refund_requests_provider_refund_id_unique' ORDER BY SEQ_IN_INDEX");
                $valid = count($indexes) === 1 && $indexes[0]->column_name === 'provider_refund_id'
                    && (int) $indexes[0]->non_unique === 0 && $indexes[0]->sub_part === null;
            } elseif ($driver === 'sqlite') {
                $indexes = DB::select("PRAGMA index_list('refund_requests')");
                $index = collect($indexes)->first(fn ($item) => $item->name === 'refund_requests_provider_refund_id_unique');
                $columns = $index ? DB::select("PRAGMA index_info('refund_requests_provider_refund_id_unique')") : [];
                $valid = $index && (int) $index->unique === 1 && (int) $index->partial === 0
                    && count($columns) === 1 && $columns[0]->name === 'provider_refund_id';
            } else {
                $valid = false;
            }
        } catch (\Throwable $exception) {
            $valid = false;
        }

        abort_unless($valid, 503, 'Proteção de unicidade de reembolso indisponível; acione a operação antes de executar ou consultar.');
    }

    private function consultPending(string $id, string $reason, object $admin, MercadoPagoClient $client, PlatformAudit $audit): object
    {
        $refund = DB::table('refund_requests')->where('id', $id)->first();
        abort_unless($refund, 404, 'Solicitação de reembolso não encontrada.');
        abort_unless($refund->status === 'executando' && $refund->provider_refund_id, 409, 'Não há identificador do provedor para consultar esta execução.');
        $providerPaymentId = DB::table('payments')->where('id', $refund->payment_id)->value('provider_payment_id');
        abort_unless($providerPaymentId, 409, 'Pagamento sem identificador no provedor.');
        abort_unless($this->matchesPaymentBinding($refund, (string) $providerPaymentId), 409, 'O identificador do pagamento mudou ou não pode ser comprovado; a execução exige análise operacional.');
        try {
            $remote = $client->getRefund((string) $providerPaymentId, (string) $refund->provider_refund_id);
        } catch (\Throwable $exception) {
            $audit->record($admin->id, 'billing.refund_lookup_unconfirmed', 'refund_request', $id, $refund->company_id, $reason, after: ['status' => 'executando']);

            return DB::table('refund_requests')->where('id', $id)->first();
        }

        return $this->applyProviderResponse($id, (string) $providerPaymentId, $remote, $reason, $admin, $client, $audit);
    }

    private function applyProviderResponse(string $id, string $providerPaymentId, array $remote, string $reason, object $admin, MercadoPagoClient $client, PlatformAudit $audit): object
    {
        try {
            return DB::transaction(function () use ($id, $providerPaymentId, $remote, $reason, $admin, $client, $audit): object {
                $refund = DB::table('refund_requests')->where('id', $id)->lockForUpdate()->first();
                abort_unless($refund, 404, 'Solicitação de reembolso não encontrada.');
                if ($refund->status === 'executado') {
                    return $refund;
                }
                abort_unless($refund->status === 'executando', 409, 'O estado do reembolso mudou durante a execução.');
                $providerRefundId = $remote['id'] ?? null;
                $remoteAmount = $remote['amount'] ?? null;
                $payment = DB::table('payments')->where('id', $refund->payment_id)->lockForUpdate()->first();
                $matches = (is_string($providerRefundId) || is_int($providerRefundId))
                    && trim((string) $providerRefundId) !== '' && strlen((string) $providerRefundId) <= 128
                    && (! $refund->provider_refund_id || (string) $refund->provider_refund_id === (string) $providerRefundId)
                    && (is_string($remote['payment_id'] ?? null) || is_int($remote['payment_id'] ?? null))
                    && (string) $remote['payment_id'] === $providerPaymentId
                    && $payment && (string) $payment->provider_payment_id === $providerPaymentId
                    && $this->matchesPaymentBinding($refund, $providerPaymentId)
                    && is_numeric($remoteAmount) && is_finite((float) $remoteAmount)
                    && (float) $remoteAmount === round((float) $remoteAmount, 2)
                    && (float) $remoteAmount === round((float) $refund->amount, 2);
                if ($matches && DB::table('refund_requests')->where('provider_refund_id', (string) $providerRefundId)->where('id', '!=', $id)->exists()) {
                    $matches = false;
                }
                $existing = json_decode((string) $refund->provider_payload_sanitized, true) ?: [];
                $safePayload = $client->sanitizePayload($remote) ?: [];
                if (isset($existing['fokus_payment_binding_sha256'])) {
                    $safePayload['fokus_payment_binding_sha256'] = $existing['fokus_payment_binding_sha256'];
                }
                $sanitized = json_encode($safePayload);
                if (! $matches || ($remote['status'] ?? null) !== 'approved') {
                    $update = ['provider_payload_sanitized' => $sanitized, 'updated_at' => now()];
                    // Only an ID returned for this payment and amount is a candidate for a later GET.
                    if ($matches && ! $refund->provider_refund_id) {
                        $update['provider_refund_id'] = (string) $providerRefundId;
                    }
                    DB::table('refund_requests')->where('id', $id)->update($update);
                    $audit->record($admin->id, 'billing.refund_execution_unconfirmed', 'refund_request', $id, $refund->company_id, $reason, after: ['status' => 'executando']);

                    return DB::table('refund_requests')->where('id', $id)->first();
                }
                DB::table('refund_requests')->where('id', $id)->update([
                    'status' => 'executado', 'provider_refund_id' => (string) $providerRefundId,
                    'provider_payload_sanitized' => $sanitized, 'executed_at' => now(), 'updated_at' => now(),
                ]);
                $refunded = (float) DB::table('refund_requests')->where('payment_id', $refund->payment_id)->where('status', 'executado')->sum('amount');
                if ($payment && $refunded >= (float) $payment->amount) {
                    DB::table('payments')->where('id', $payment->id)->update(['status' => 'estornado', 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
                    $audit->record($admin->id, 'billing.payment_refunded', 'payment', $payment->id, $payment->company_id, $reason,
                        before: ['status' => $payment->status, 'amount' => $payment->amount],
                        after: ['status' => 'estornado', 'amount' => $payment->amount, 'refund_amount' => $refunded]);
                }
                $audit->record($admin->id, 'billing.refund_executed', 'refund_request', $id, $refund->company_id, $reason, before: ['status' => 'executando'], after: ['status' => 'executado', 'provider_refund_id' => $providerRefundId]);

                return DB::table('refund_requests')->where('id', $id)->first();
            });
        } catch (QueryException $exception) {
            if (! $this->isProviderRefundIdCollision($exception)) {
                throw $exception;
            }

            // The failed transaction has rolled back; the previously committed
            // execution claim remains reserved. Record the uncertain outcome
            // without retrying the provider request or releasing that claim.
            return DB::transaction(function () use ($id, $reason, $admin, $audit, $exception): object {
                $refund = DB::table('refund_requests')->where('id', $id)->lockForUpdate()->first();
                if (! $refund || $refund->status !== 'executando') {
                    throw $exception;
                }
                $audit->record($admin->id, 'billing.refund_provider_id_collision', 'refund_request', $id, $refund->company_id, $reason, after: ['status' => 'executando']);

                return $refund;
            });
        }
    }

    private function isProviderRefundIdCollision(QueryException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? '');
        $code = (int) ($exception->errorInfo[1] ?? 0);
        $detail = (string) ($exception->errorInfo[2] ?? '');

        return $state === '23000' && (
            ($code === 1062 && str_contains($detail, 'refund_requests_provider_refund_id_unique'))
            || (in_array($code, [19, 2067], true) && str_contains($detail, 'UNIQUE constraint failed: refund_requests.provider_refund_id'))
        );
    }

    private function paymentBinding(string $refundId, string $providerPaymentId): string
    {
        return hash('sha256', $refundId."\0".$providerPaymentId);
    }

    private function matchesPaymentBinding(object $refund, string $providerPaymentId): bool
    {
        $payload = json_decode((string) $refund->provider_payload_sanitized, true) ?: [];
        $binding = $payload['fokus_payment_binding_sha256'] ?? null;

        return is_string($binding) && hash_equals($this->paymentBinding($refund->id, $providerPaymentId), $binding);
    }

    public function payload(object $refund): array
    {
        return [
            'id' => $refund->id, 'payment_id' => $refund->payment_id, 'subscription_id' => $refund->subscription_id,
            'requested_by_platform_admin_id' => $refund->requested_by_platform_admin_id,
            'approved_by_platform_admin_id' => $refund->approved_by_platform_admin_id,
            'amount' => (float) $refund->amount, 'allowed_case' => $refund->allowed_case, 'reason' => $refund->reason,
            'status' => $refund->status, 'provider_refund_id' => $refund->provider_refund_id, 'requested_at' => $refund->requested_at,
            'approved_at' => $refund->approved_at, 'executed_at' => $refund->executed_at, 'refused_at' => $refund->refused_at,
        ];
    }
}
