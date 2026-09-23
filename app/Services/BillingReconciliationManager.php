<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BillingReconciliationManager
{
    public function reconcile(?string $subscriptionId = null, bool $dryRun = false): int
    {
        $query = DB::table('subscriptions')->whereNotNull('provider_subscription_id');
        if ($subscriptionId) $query->where('id', $subscriptionId);
        $created = 0;
        foreach ($query->get() as $subscription) {
            $remote = app(MercadoPagoClient::class)->getPreapproval($subscription->provider_subscription_id);
            $remoteStatus = (string) ($remote['status'] ?? '');
            $localStatus = $subscription->status;
            $expected = match ($remoteStatus) {
                'authorized' => 'ativa', 'paused' => 'suspensa', 'cancelled' => 'encerrada', default => 'aguardando_pagamento',
            };
            if ($expected !== $localStatus) {
                $created += $this->open($subscription, 'subscription_status', $localStatus, $remoteStatus, $expected === 'ativa' ? 'alto' : 'medio', $dryRun);
            }
        }
        return $created;
    }

    public function list(array $filters = []): array
    {
        $query = DB::table('payment_reconciliation_alerts as alert')
            ->leftJoin('companies as company', 'company.id', '=', 'alert.company_id')
            ->select('alert.*', 'company.legal_name as company_name')
            ->orderByDesc('alert.opened_at');
        if (! empty($filters['status'])) $query->where('alert.status', $filters['status']);
        if (! empty($filters['impact'])) $query->where('alert.impact', $filters['impact']);
        if (! empty($filters['q'])) {
            $like = '%'.trim((string) $filters['q']).'%';
            $query->where(function ($search) use ($like): void {
                $search->where('company.legal_name', 'like', $like)
                    ->orWhere('alert.id', 'like', $like)
                    ->orWhere('alert.company_id', 'like', $like)
                    ->orWhere('alert.payment_id', 'like', $like)
                    ->orWhere('alert.subscription_id', 'like', $like);
            });
        }
        if (! empty($filters['date_from'])) $query->whereDate('alert.opened_at', '>=', $filters['date_from']);
        if (! empty($filters['date_to'])) $query->whereDate('alert.opened_at', '<=', $filters['date_to']);
        $paginator = $query->paginate(min(max((int) ($filters['per_page'] ?? 15), 1), 100));
        return ['data' => $paginator->items(), 'meta' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()]];
    }

    public function action(string $id, string $action, string $reason, object $admin, PlatformAudit $audit): object
    {
        return DB::transaction(function () use ($id, $action, $reason, $admin, $audit): object {
            $alert = DB::table('payment_reconciliation_alerts')->where('id', $id)->lockForUpdate()->first();
            abort_unless($alert, 404, 'Divergência não encontrada.');
            abort_unless(in_array($action, ['revisar', 'descartar', 'corrigir'], true), 422, 'Ação de conciliação inválida.');
            if ($action === 'corrigir') {
                abort_unless($admin->hasPermission('platform.reconciliation.manage'), 403, 'Somente o superadministrador pode corrigir divergências.');
                if ($alert->payment_id) {
                    $paymentStatus = match ($alert->mercado_pago_status) { 'approved' => 'aprovado', 'rejected' => 'recusado', 'cancelled', 'expired' => 'cancelado', 'refunded' => 'estornado', default => null };
                    if ($paymentStatus) DB::table('payments')->where('id', $alert->payment_id)->update(['status' => $paymentStatus, 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
                }
                if ($alert->subscription_id) {
                    abort_if(DB::table('subscriptions')->where('id', $alert->subscription_id)->whereNull('provider_subscription_id')->exists()
                        && DB::table('voucher_redemptions as redemption')->join('vouchers as voucher', 'voucher.id', '=', 'redemption.voucher_id')
                            ->where('redemption.subscription_id', $alert->subscription_id)->where('voucher.discount_type', 'trial_free')->exists(),
                        422, 'A assinatura foi ativada por voucher gratuito; a divergência antiga não pode alterar seu estado.');
                    $subscriptionStatus = match ($alert->mercado_pago_status) { 'authorized' => 'ativa', 'paused' => 'suspensa', 'cancelled' => 'encerrada', default => null };
                    if ($subscriptionStatus) DB::table('subscriptions')->where('id', $alert->subscription_id)->update(['status' => $subscriptionStatus, 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
                }
                $status = 'corrigida';
                $auditAction = 'billing.reconciliation_corrected';
            } elseif ($action === 'descartar') {
                abort_unless($admin->hasPermission('platform.reconciliation.manage'), 403, 'Somente o superadministrador pode descartar divergências.');
                $status = 'descartada';
                $auditAction = 'billing.reconciliation_discarded';
            } else {
                $status = 'em_revisao';
                $auditAction = 'billing.reconciliation_reviewed';
            }
            DB::table('payment_reconciliation_alerts')->where('id', $id)->update([
                'status' => $status, 'reviewed_by_platform_admin_id' => $admin->id, 'corrected_by_platform_admin_id' => $action === 'corrigir' ? $admin->id : $alert->corrected_by_platform_admin_id,
                'correction_reason' => $reason, 'correction_snapshot' => json_encode(['mercado_pago_status' => $alert->mercado_pago_status, 'action' => $action]),
                'reviewed_at' => now(), 'corrected_at' => $action === 'corrigir' ? now() : $alert->corrected_at, 'discarded_at' => $action === 'descartar' ? now() : $alert->discarded_at, 'updated_at' => now(),
            ]);
            $audit->record($admin->id, $auditAction, 'payment_reconciliation_alert', $id, $alert->company_id, $reason, before: ['status' => $alert->status], after: ['status' => $status, 'mercado_pago_status' => $alert->mercado_pago_status]);
            return DB::table('payment_reconciliation_alerts')->where('id', $id)->first();
        });
    }

    private function open(object $subscription, string $type, string $internal, string $remote, string $impact, bool $dryRun): int
    {
        $fingerprint = hash('sha256', implode('|', [$subscription->id, $type, $internal, $remote]));
        if ($dryRun || DB::table('payment_reconciliation_alerts')->where('fingerprint', $fingerprint)->whereIn('status', ['aberta', 'em_revisao'])->exists()) return 0;
        DB::table('payment_reconciliation_alerts')->insert([
            'id' => PrefixedUlid::make('RCA'), 'company_id' => $subscription->company_id, 'subscription_id' => $subscription->id, 'fingerprint' => $fingerprint,
            'type' => $type, 'internal_status' => $internal, 'mercado_pago_status' => $remote, 'impact' => $impact, 'status' => 'aberta', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return 1;
    }
}
