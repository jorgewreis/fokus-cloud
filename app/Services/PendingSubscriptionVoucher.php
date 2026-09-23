<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PendingSubscriptionVoucher
{
    public function __construct(private readonly VoucherManager $vouchers, private readonly MercadoPagoClient $mercadoPago, private readonly SubscriptionChangeManager $changes) {}

    public function activate(string $subscriptionId, string $code): array
    {
        $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->first();
        abort_unless($subscription, 404, 'Assinatura não encontrada.');
        abort_unless($subscription->status === 'aguardando_pagamento', 422, 'A assinatura precisa aguardar pagamento.');
        abort_if(DB::table('payments')->where('subscription_id', $subscriptionId)->where('status', 'aprovado')->exists(), 422, 'Esta assinatura já possui pagamento aprovado.');
        abort_if(DB::table('voucher_redemptions')->where('subscription_id', $subscriptionId)->exists(), 422, 'Esta assinatura já utilizou um voucher.');
        abort_if(DB::table('voucher_redemption_reservations')->where('subscription_id', $subscriptionId)->where('status', 'pending')->exists(),
            422, 'Esta assinatura já possui um voucher reservado no checkout. Aguarde a conclusão ou liberação dessa reserva.');
        $snapshot = $this->changes->snapshot($subscription);
        $moduleCodes = DB::table('subscription_items as item')->join('modules as module', 'module.id', '=', 'item.module_id')
            ->where('item.subscription_id', $subscriptionId)->whereNull('item.deleted_at')->pluck('module.code')->all();
        $voucher = $this->vouchers->findEligible($code, $subscription->product_id, $subscription->company_id, $moduleCodes, $snapshot['plan_code'] ?? null);
        abort_unless($voucher->discount_type === 'trial_free' && $voucher->benefit_duration, 422, 'Informe um voucher de assinatura gratuita com duração definida.');
        $payment = DB::table('payments')->where('subscription_id', $subscriptionId)->latest('created_at')->first();
        abort_unless($payment && $payment->status === 'aguardando_pagamento', 422, 'Pagamento pendente não encontrado.');
        abort_unless($subscription->provider_subscription_id, 422, 'Pré-aprovação do pagamento não encontrada.');
        $reservation = $this->vouchers->reserve($voucher, $subscription->company_id, 'trial-'.$subscriptionId.'-'.Str::uuid(), [
            'voucher_id' => $voucher->id, 'code' => $voucher->code, 'name' => $voucher->name,
            'product_id' => $subscription->product_id, 'plan_code' => $snapshot['plan_code'] ?? null,
            'discount_type' => 'trial_free', 'discount_value' => 100,
            'base_amount' => (float) $payment->amount, 'discount_amount' => (float) $payment->amount, 'final_amount' => 0,
            'billing_cycle' => $subscription->billing_cycle, 'benefit_duration' => $voucher->benefit_duration,
            'company_id' => $subscription->company_id, 'subscription_id' => $subscriptionId, 'module_codes' => $moduleCodes,
        ]);
        $this->vouchers->attachSubscription($reservation->id, $subscriptionId);
        try {
            $remote = $this->mercadoPago->getPreapproval((string) $subscription->provider_subscription_id);
            abort_unless(in_array($remote['status'] ?? null, ['pending', 'cancelled'], true), 422, 'A pré-aprovação já mudou de estado. Atualize a assinatura antes de aplicar o voucher.');
            if (($remote['status'] ?? null) === 'pending') {
                $this->mercadoPago->updatePreapproval((string) $subscription->provider_subscription_id, ['status' => 'cancelled'], 'trial-'.$subscriptionId);
            }
        } catch (\Throwable $exception) {
            $this->vouchers->release($reservation->id);
            throw $exception;
        }

        try {
            DB::transaction(function () use ($subscriptionId, $subscription, $payment, $reservation): void {
            $current = DB::table('subscriptions')->where('id', $subscriptionId)->lockForUpdate()->first();
            abort_unless($current && $current->status === 'aguardando_pagamento' && $current->provider_subscription_id === $subscription->provider_subscription_id, 409, 'A assinatura mudou durante a ativação.');
            $this->vouchers->confirmForSubscription($subscriptionId);
            $redemption = DB::table('voucher_redemptions')->where('subscription_id', $subscriptionId)->latest('created_at')->first();
            abort_unless($redemption && $redemption->benefit_ends_at, 422, 'Não foi possível confirmar o benefício gratuito.');
            $commercial = $this->changes->snapshot($current);
            DB::table('subscriptions')->where('id', $subscriptionId)->update([
                'status' => 'ativa', 'provider_subscription_id' => null, 'provider_status' => 'cancelled',
                'current_period_starts_at' => $redemption->benefit_starts_at,
                'current_period_ends_at' => $redemption->benefit_ends_at,
                'commercial_snapshot' => json_encode([...$commercial, 'status' => 'ativa', 'current_period_starts_at' => $redemption->benefit_starts_at, 'current_period_ends_at' => $redemption->benefit_ends_at]),
                'updated_at' => now(), 'version' => DB::raw('version + 1'),
            ]);
            DB::table('payments')->where('id', $payment->id)->update([
                'status' => 'cancelado', 'provider_subscription_id' => null,
                'provider_payload_sanitized' => json_encode(['preapproval_id' => $subscription->provider_subscription_id, 'cancelled_for_trial_free_voucher' => true]),
                'updated_at' => now(), 'version' => DB::raw('version + 1'),
            ]);
            });
        } catch (\Throwable $exception) {
            $this->vouchers->release($reservation->id);
            throw $exception;
        }
        $redemption = DB::table('voucher_redemptions')->where('subscription_id', $subscriptionId)->latest('created_at')->first();
        return ['subscription_id' => $subscriptionId, 'voucher_redemption_id' => $redemption->id, 'benefit_ends_at' => $redemption->benefit_ends_at];
    }

    public function suspendExpired(): int
    {
        $ids = DB::table('voucher_redemptions as redemption')->join('vouchers as voucher', 'voucher.id', '=', 'redemption.voucher_id')
            ->join('subscriptions as subscription', 'subscription.id', '=', 'redemption.subscription_id')
            ->where('voucher.discount_type', 'trial_free')->where('subscription.status', 'ativa')
            ->whereNull('subscription.provider_subscription_id')->where('redemption.benefit_ends_at', '<=', now())
            ->pluck('subscription.id');
        return DB::table('subscriptions')->whereIn('id', $ids)->where('status', 'ativa')->update([
            'status' => 'suspensa', 'updated_at' => now(), 'version' => DB::raw('version + 1'),
        ]);
    }
}
