<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionBillingManager
{
    public function checkoutChange(string $subscriptionId, array $result, string $payerEmail, string $returnPath, ?string $adminId = null): array
    {
        if ($result['status'] !== 'aguardando_pagamento') return [...$result, 'checkout_url' => null];
        $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->first();
        $client = app(MercadoPagoClient::class);
        $paymentId = null;
        try {
            if ((float) $result['proration_amount'] > 0) {
                $paymentId = PrefixedUlid::make('PAG');
                DB::table('payments')->insert([
                    'id' => $paymentId, 'company_id' => $subscription->company_id, 'subscription_id' => $subscriptionId,
                    'subscription_change_id' => $result['id'], 'provider' => 'mercado_pago', 'status' => 'aguardando_pagamento',
                    'amount' => $result['proration_amount'], 'currency' => 'BRL', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $returnUrl = rtrim(config('app.url'), '/').$returnPath;
                $preference = $client->createPreference([
                    'external_reference' => $paymentId,
                    'items' => [['id' => $result['id'], 'title' => 'Ampliação da assinatura — diferença proporcional',
                        'quantity' => 1, 'currency_id' => 'BRL', 'unit_price' => (float) $result['proration_amount']]],
                    'payer' => ['email' => $client->payerEmail($payerEmail)],
                    'notification_url' => rtrim(config('app.url'), '/').'/api/webhooks/mercado-pago',
                    'back_urls' => ['success' => $returnUrl.'?checkout=success', 'pending' => $returnUrl.'?checkout=pending', 'failure' => $returnUrl.'?checkout=failure'],
                    'auto_return' => 'approved',
                ], $paymentId);
                $url = $preference['init_point'] ?? $preference['sandbox_init_point'] ?? null;
                abort_unless(is_string($url) && $url !== '' && ! empty($preference['id']), 502, 'Não foi possível obter o link de pagamento.');
                DB::table('payments')->where('id', $paymentId)->update(['provider_preference_id' => $preference['id'],
                    'provider_checkout_url' => $url, 'provider_payload_sanitized' => json_encode($client->sanitizePayload($preference)), 'updated_at' => now()]);
                return [...$result, 'checkout_url' => $url];
            }
            if ($subscription->provider_subscription_id) {
                $target = $result['after'];
                $client->updatePreapproval((string) $subscription->provider_subscription_id, ['auto_recurring' => [
                    'frequency' => ($target['billing_cycle'] ?? $subscription->billing_cycle) === 'annual' ? 12 : 1,
                    'frequency_type' => 'months', 'transaction_amount' => (float) $target['amount'], 'currency_id' => 'BRL',
                ]], 'law-change-'.$result['id']);
            }
            $applied = app(SubscriptionChangeManager::class)->applyApprovedChange($result['id'], $adminId);
            return [...$result, 'status' => 'aplicada', 'after' => $applied['after'], 'checkout_url' => null];
        } catch (\Throwable $exception) {
            DB::table('subscription_changes')->where('id', $result['id'])->where('status', 'aguardando_pagamento')->update(['status' => 'falhou', 'updated_at' => now()]);
            if ($paymentId) DB::table('payments')->where('id', $paymentId)->update(['status' => 'cancelado', 'updated_at' => now()]);
            $this->releaseChangeVoucher($result['after'] ?? []);
            throw $exception;
        }
    }

    private function releaseChangeVoucher(array $snapshot): void
    {
        if (! empty($snapshot['upgrade_voucher_reservation_id'])) app(VoucherManager::class)->release($snapshot['upgrade_voucher_reservation_id']);
    }

    public function applyPayment(string $paymentId, array $remote, string $status, ?string $correlationId = null): ?object
    {
        return DB::transaction(function () use ($paymentId, $remote, $status, $correlationId): ?object {
            $payment = DB::table('payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (! $payment) {
                return null;
            }

            $subscription = DB::table('subscriptions')->where('id', $payment->subscription_id)->lockForUpdate()->first();
            if (empty($payment->subscription_change_id) && $subscription && ! $subscription->provider_subscription_id && DB::table('voucher_redemptions as redemption')
                ->join('vouchers as voucher', 'voucher.id', '=', 'redemption.voucher_id')
                ->where('redemption.subscription_id', $subscription->id)->where(fn ($query) => \App\Services\VoucherManager::freeBenefit($query))
                ->exists()) {
                return $subscription;
            }
            $now = now();
            $paymentUpdates = [
                'provider_payment_id' => isset($remote['id']) ? (string) $remote['id'] : $payment->provider_payment_id,
                'provider_subscription_id' => $remote['preapproval_id'] ?? $remote['subscription_id'] ?? $payment->provider_subscription_id,
                'status' => $status,
                'provider_payload_sanitized' => json_encode($this->sanitizedPayment($remote)),
                'paid_at' => $status === 'aprovado' ? ($payment->paid_at ?: $now) : $payment->paid_at,
                'updated_at' => $now,
                'version' => DB::raw('version + 1'),
            ];
            if (isset($remote['date_created'])) {
                $paymentUpdates['billing_period_starts_at'] = Carbon::parse($remote['date_created'])->toDateTimeString();
            }
            if (isset($remote['date_approved'])) {
                $paymentUpdates['paid_at'] = Carbon::parse($remote['date_approved'])->toDateTimeString();
            }
            DB::table('payments')->where('id', $payment->id)->update($paymentUpdates);
            if ($payment->status !== $status) {
                app(AuditRecorder::class)->company($payment->company_id, null, 'payment', $payment->id, 'update',
                    ['status' => $payment->status, 'amount' => (float) $payment->amount], ['status' => $status, 'amount' => (float) $payment->amount],
                    reason: 'Estado confirmado pelo Mercado Pago.', actorType: 'gateway', channel: 'webhook', originContext: '/api/webhooks/mercado-pago', correlationId: $correlationId);
                app(AuditRecorder::class)->platform(null, 'billing.payment_status_updated', 'payment', $payment->id, $payment->company_id,
                    'Estado confirmado pelo Mercado Pago.', metadata: ['source' => 'mercado_pago'], before: ['status' => $payment->status], after: ['status' => $status],
                    actorType: 'gateway', channel: 'webhook', originContext: '/api/webhooks/mercado-pago', correlationId: $correlationId);
            }

            if (! empty($payment->subscription_change_id)) {
                $change = DB::table('subscription_changes')->where('id', $payment->subscription_change_id)->lockForUpdate()->first();
                if ($change && $change->status === 'cancelada' && $status === 'aprovado') {
                    if (! empty($remote['id'])) {
                        app(MercadoPagoClient::class)->createRefund((string) $remote['id'], null, 'law-cancelled-change-'.$change->id);
                        DB::table('payments')->where('id', $payment->id)->update(['status' => 'estornado', 'updated_at' => $now, 'version' => DB::raw('version + 1')]);
                    }
                    return $subscription;
                }
                if ($change && $change->status === 'aguardando_pagamento') {
                    if ($status === 'aprovado') {
                        $after = json_decode((string) $change->after_snapshot, true) ?: [];
                        if (! empty($after['upgrade_voucher_reservation_id'])) {
                            $reservation = DB::table('voucher_redemption_reservations')->where('id', $after['upgrade_voucher_reservation_id'])->lockForUpdate()->first();
                            if (! $reservation || $reservation->status !== 'pending' || now()->gt($reservation->expires_at)) {
                                abort_unless(! empty($remote['id']), 422, 'Pagamento sem identificação para devolver a cobrança de voucher expirado.');
                                app(MercadoPagoClient::class)->createRefund((string) $remote['id'], null, 'expired-voucher-change-'.$change->id);
                                DB::table('payments')->where('id', $payment->id)->update(['status' => 'estornado', 'updated_at' => $now]);
                                DB::table('subscription_changes')->where('id', $change->id)->update(['status' => 'falhou', 'updated_at' => $now]);
                                $this->releaseChangeVoucher($after);
                                return $subscription;
                            }
                        }
                        if ($subscription?->provider_subscription_id) {
                            $cycle = $after['billing_cycle'] ?? $subscription->billing_cycle ?? 'monthly';
                            app(MercadoPagoClient::class)->updatePreapproval((string) $subscription->provider_subscription_id, [
                                'auto_recurring' => [
                                    'frequency' => $cycle === 'annual' ? 12 : 1,
                                    'frequency_type' => 'months',
                                    'transaction_amount' => (float) ($after['amount'] ?? 0),
                                    'currency_id' => 'BRL',
                                ],
                            ], 'law-change-'.$change->id);
                        }
                        app(SubscriptionChangeManager::class)->applyApprovedChange($change->id);
                    } elseif (in_array($status, ['recusado', 'cancelado', 'estornado'], true)) {
                        $this->releaseChangeVoucher(json_decode((string) $change->after_snapshot, true) ?: []);
                        DB::table('subscription_changes')->where('id', $change->id)->update(['status' => 'falhou', 'updated_at' => $now, 'version' => DB::raw('version + 1')]);
                    }
                }

                return $subscription;
            }

            if ($subscription) {
                $subscriptionUpdates = ['provider_synced_at' => $now, 'updated_at' => $now, 'version' => DB::raw('version + 1')];
                if ($status === 'aprovado') {
                    $subscriptionUpdates['status'] = 'ativa';
                    $subscriptionUpdates['delinquent_at'] = null;
                    $subscriptionUpdates['grace_ends_at'] = null;
                } elseif ($status === 'recusado') {
                    $subscriptionUpdates['status'] = 'inadimplente';
                    $subscriptionUpdates['delinquent_at'] = $subscription->delinquent_at ?: $now;
                    $subscriptionUpdates['grace_ends_at'] = $subscription->grace_ends_at ?: $now->copy()->addDays(7);
                } elseif (in_array($status, ['cancelado', 'estornado'], true) && $subscription->status !== 'encerrada') {
                    $subscriptionUpdates['status'] = 'cancelamento_agendado';
                }
                DB::table('subscriptions')->where('id', $subscription->id)->update($subscriptionUpdates);
                if (isset($subscriptionUpdates['status']) && $subscriptionUpdates['status'] !== $subscription->status) {
                    app(AuditRecorder::class)->company($subscription->company_id, null, 'subscription', $subscription->id, 'update',
                        ['status' => $subscription->status], ['status' => $subscriptionUpdates['status']], reason: 'Estado atualizado após confirmação do pagamento pelo Mercado Pago.',
                        actorType: 'gateway', channel: 'webhook', originContext: '/api/webhooks/mercado-pago', correlationId: $correlationId);
                    app(AuditRecorder::class)->platform(null, 'billing.subscription_status_updated', 'subscription', $subscription->id, $subscription->company_id,
                        'Estado atualizado após confirmação do pagamento pelo Mercado Pago.', before: ['status' => $subscription->status], after: ['status' => $subscriptionUpdates['status']],
                        actorType: 'gateway', channel: 'webhook', originContext: '/api/webhooks/mercado-pago', correlationId: $correlationId);
                }
            }

            return $subscription;
        });
    }

    public function expireTolerance(): int
    {
        return DB::transaction(function (): int {
            $subscriptions = DB::table('subscriptions')->where('status', 'inadimplente')->whereNotNull('grace_ends_at')->where('grace_ends_at', '<=', now())->lockForUpdate()->get();
            foreach ($subscriptions as $subscription) {
                DB::table('subscriptions')->where('id', $subscription->id)->update([
                    'status' => 'suspensa',
                    'updated_at' => now(),
                    'version' => DB::raw('version + 1'),
                ]);
                app(AuditRecorder::class)->company($subscription->company_id, null, 'subscription', $subscription->id, 'update',
                    ['status' => $subscription->status, 'grace_ends_at' => $subscription->grace_ends_at], ['status' => 'suspensa'],
                    reason: 'Prazo de tolerância de pagamento expirado.', actorType: 'system', channel: 'scheduler', originContext: 'fokus:expire-subscription-tolerance');
                app(AuditRecorder::class)->platform(null, 'billing.subscription_suspended_for_delinquency', 'subscription', $subscription->id, $subscription->company_id,
                    'Prazo de tolerância de pagamento expirado.', before: ['status' => $subscription->status, 'grace_ends_at' => $subscription->grace_ends_at], after: ['status' => 'suspensa'],
                    actorType: 'system', channel: 'scheduler', originContext: 'fokus:expire-subscription-tolerance');
            }

            return $subscriptions->count();
        });
    }

    private function sanitizedPayment(array $remote): array
    {
        return app(MercadoPagoClient::class)->sanitizePayload([
            'id' => $remote['id'] ?? null,
            'status' => $remote['status'] ?? null,
            'status_detail' => $remote['status_detail'] ?? null,
            'external_reference' => $remote['external_reference'] ?? null,
            'preapproval_id' => $remote['preapproval_id'] ?? null,
            'subscription_id' => $remote['subscription_id'] ?? null,
            'transaction_amount' => $remote['transaction_amount'] ?? $remote['amount'] ?? null,
            'date_created' => $remote['date_created'] ?? null,
            'date_approved' => $remote['date_approved'] ?? null,
        ]);
    }
}
