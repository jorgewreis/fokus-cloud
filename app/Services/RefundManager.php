<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

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
        return DB::transaction(function () use ($id, $action, $reason, $admin, $client, $audit): object {
            $refund = DB::table('refund_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($refund, 404, 'Solicitação de reembolso não encontrada.');
            if ($action === 'aprovar') {
                abort_unless($refund->status === 'solicitado', 422, 'A solicitação não está pendente de aprovação.');
                abort_unless($refund->requested_by_platform_admin_id !== $admin->id, 403, 'Quem solicitou o reembolso não pode aprovar a própria solicitação.');
                DB::table('refund_requests')->where('id', $id)->update(['status' => 'aprovado', 'approved_by_platform_admin_id' => $admin->id, 'approved_at' => now(), 'updated_at' => now()]);
                $audit->record($admin->id, 'billing.refund_approved', 'refund_request', $id, $refund->company_id, $reason, before: ['status' => $refund->status], after: ['status' => 'aprovado', 'amount' => $refund->amount]);
            } elseif ($action === 'recusar') {
                abort_unless(in_array($refund->status, ['solicitado', 'aprovado'], true), 422, 'A solicitação não pode ser recusada neste estado.');
                abort_unless($refund->requested_by_platform_admin_id !== $admin->id, 403, 'Quem solicitou o reembolso não pode decidir sobre a própria solicitação.');
                DB::table('refund_requests')->where('id', $id)->update(['status' => 'recusado', 'approved_by_platform_admin_id' => $admin->id, 'refused_at' => now(), 'updated_at' => now()]);
                $audit->record($admin->id, 'billing.refund_refused', 'refund_request', $id, $refund->company_id, $reason, before: ['status' => $refund->status], after: ['status' => 'recusado']);
            } elseif ($action === 'executar') {
                abort_unless($refund->status === 'aprovado', 422, 'O reembolso precisa ser aprovado antes da execução.');
                $key = 'refund-'.$refund->id;
                $providerPaymentId = DB::table('payments')->where('id', $refund->payment_id)->value('provider_payment_id');
                abort_unless($providerPaymentId, 422, 'O pagamento não possui identificador no gateway.');
                $remote = $client->createRefund((string) $providerPaymentId, (float) $refund->amount, $key);
                $providerRefundId = $remote['id'] ?? $remote['refund_id'] ?? null;
                DB::table('refund_requests')->where('id', $id)->update([
                    'status' => 'executado', 'provider_refund_id' => $providerRefundId, 'provider_payload_sanitized' => json_encode($client->sanitizePayload($remote)),
                    'executed_at' => now(), 'updated_at' => now(),
                ]);
                $payment = DB::table('payments')->where('id', $refund->payment_id)->lockForUpdate()->first();
                $refunded = (float) DB::table('refund_requests')->where('payment_id', $refund->payment_id)->where('status', 'executado')->sum('amount');
                if ($payment && $refunded >= (float) $payment->amount) {
                    DB::table('payments')->where('id', $payment->id)->update(['status' => 'estornado', 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
                    $audit->record($admin->id, 'billing.payment_refunded', 'payment', $payment->id, $payment->company_id, $reason,
                        before: ['status' => $payment->status, 'amount' => $payment->amount],
                        after: ['status' => 'estornado', 'amount' => $payment->amount, 'refund_amount' => $refunded]);
                }
                $audit->record($admin->id, 'billing.refund_executed', 'refund_request', $id, $refund->company_id, $reason, before: ['status' => $refund->status], after: ['status' => 'executado', 'provider_refund_id' => $providerRefundId]);
            } else {
                abort(422, 'Ação de reembolso inválida.');
            }
            return DB::table('refund_requests')->where('id', $id)->first();
        });
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
