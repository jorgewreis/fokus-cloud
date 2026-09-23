<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SubscriptionBillingManager
{
    public function applyPayment(string $paymentId, array $remote, string $status): ?object
    {
        return DB::transaction(function () use ($paymentId, $remote, $status): ?object {
            $payment = DB::table('payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (! $payment) {
                return null;
            }

            $subscription = DB::table('subscriptions')->where('id', $payment->subscription_id)->lockForUpdate()->first();
            if ($subscription && ! $subscription->provider_subscription_id && DB::table('voucher_redemptions as redemption')
                ->join('vouchers as voucher', 'voucher.id', '=', 'redemption.voucher_id')
                ->where('redemption.subscription_id', $subscription->id)->where('voucher.discount_type', 'trial_free')
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
                $paymentUpdates['billing_period_starts_at'] = $remote['date_created'];
            }
            if (isset($remote['date_approved'])) {
                $paymentUpdates['paid_at'] = $remote['date_approved'];
            }
            DB::table('payments')->where('id', $payment->id)->update($paymentUpdates);

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
