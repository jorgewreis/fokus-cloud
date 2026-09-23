<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class BillingWebhookProcessor
{
    public function __construct(
        private readonly MercadoPagoClient $client,
        private readonly SubscriptionBillingManager $billing,
        private readonly VoucherManager $vouchers,
    ) {
    }

    public function process(Request $request): string
    {
        $type = (string) ($request->input('type') ?: $request->input('topic'));
        $action = (string) $request->input('action');
        $resourceId = (string) ($request->query('data_id') ?: $request->query('data.id') ?: $request->input('data.id'));
        $requestId = (string) $request->header('x-request-id');
        $notificationId = (string) ($request->input('id') ?: $request->header('x-notification-id'));
        $eventIdentity = $notificationId ?: implode('|', [$type, $action, $resourceId]);
        $eventKey = hash('sha256', implode('|', ['mercado_pago', $eventIdentity]));
        $event = DB::table('billing_provider_events')->where('event_key', $eventKey)->first();
        if ($event?->status === 'processed' || $event?->status === 'ignored') {
            return 'duplicate';
        }

        $eventId = $event?->id ?: PrefixedUlid::make('BPE');
        if (! $event) {
            DB::table('billing_provider_events')->insert([
                'id' => $eventId,
                'provider' => 'mercado_pago',
                'event_key' => $eventKey,
                'notification_id' => $notificationId ?: null,
                'request_id' => $requestId ?: null,
                'event_type' => $type ?: null,
                'action' => $action ?: null,
                'resource_id' => $resourceId ?: null,
                'status' => 'received',
                'payload_sanitized' => json_encode($this->client->sanitizePayload($request->all())),
                'signature_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        try {
            if (! $resourceId) {
                $this->mark($eventId, 'ignored');
                return 'ignored';
            }
            if (in_array($type, ['subscription_preapproval', 'preapproval'], true)) {
                $this->processPreapproval($resourceId);
            } elseif ($type === 'subscription_authorized_payment') {
                $this->processAuthorizedPayment($resourceId);
            } elseif ($type === 'payment') {
                $this->processPayment($resourceId);
            } else {
                $this->mark($eventId, 'ignored');
                return 'ignored';
            }
            $this->mark($eventId, 'processed');
            return 'processed';
        } catch (\Throwable $exception) {
            $this->mark($eventId, 'failed', mb_substr($exception->getMessage(), 0, 1000));
            throw $exception;
        }
    }

    private function processPreapproval(string $providerId): void
    {
        $remote = $this->client->getPreapproval($providerId);
        $status = match ($remote['status'] ?? null) {
            'authorized' => 'ativa',
            'paused' => 'suspensa',
            'cancelled' => 'encerrada',
            default => 'aguardando_pagamento',
        };
        $subscription = DB::table('subscriptions')->where('provider_subscription_id', $providerId)->lockForUpdate()->first();
        if (! $subscription) {
            return;
        }
        if ($status === 'encerrada' && $subscription->status === 'aguardando_pagamento'
            && DB::table('voucher_redemption_reservations as reservation')
                ->join('vouchers as voucher', 'voucher.id', '=', 'reservation.voucher_id')
                ->where('reservation.subscription_id', $subscription->id)->where('reservation.status', 'pending')
                ->where('voucher.discount_type', 'trial_free')->exists()) {
            return;
        }
        DB::table('subscriptions')->where('id', $subscription->id)->update([
            'status' => $status,
            'provider_status' => $remote['status'] ?? null,
            'provider_payload_sanitized' => json_encode($this->client->sanitizePayload($remote)),
            'provider_synced_at' => now(),
            'updated_at' => now(),
            'version' => DB::raw('version + 1'),
        ]);
        if ($status === 'ativa') {
            $this->vouchers->confirmForSubscription($subscription->id);
        } elseif ($status === 'encerrada') {
            $this->vouchers->releaseForSubscription($subscription->id);
        }
    }

    private function processAuthorizedPayment(string $authorizedId): void
    {
        $remote = $this->client->getAuthorizedPayment($authorizedId);
        $paymentRemote = is_array($remote['payment'] ?? null) ? $remote['payment'] : $remote;
        $status = $this->mapPaymentStatus($paymentRemote['status'] ?? $remote['status'] ?? null);
        $providerPaymentId = isset($paymentRemote['id']) ? (string) $paymentRemote['id'] : null;
        $payment = DB::table('payments')->when($providerPaymentId, fn ($q) => $q->where('provider_payment_id', $providerPaymentId))->when(! $providerPaymentId, fn ($q) => $q->where('provider_authorized_payment_id', $authorizedId))->first();
        if (! $payment) {
            $preapprovalId = $remote['preapproval_id'] ?? $paymentRemote['preapproval_id'] ?? null;
            $payment = DB::table('payments')->where('provider_subscription_id', $preapprovalId)->where('status', 'aguardando_pagamento')->orderBy('created_at')->first();
        }
        if (! $payment) {
            return;
        }
        $this->billing->applyPayment($payment->id, [...$paymentRemote, 'preapproval_id' => $remote['preapproval_id'] ?? $paymentRemote['preapproval_id'] ?? null], $status);
        DB::table('payments')->where('id', $payment->id)->update([
            'provider_authorized_payment_id' => $authorizedId,
            'provider_payload_sanitized' => json_encode($this->client->sanitizePayload($remote)),
            'updated_at' => now(),
        ]);
        if (in_array($status, ['recusado', 'cancelado'], true)) {
            $this->vouchers->releaseForSubscription($payment->subscription_id);
        } elseif ($status === 'aprovado') {
            $this->vouchers->confirmForSubscription($payment->subscription_id);
        }
    }

    private function processPayment(string $providerId): void
    {
        $remote = $this->client->getPayment($providerId);
        $status = $this->mapPaymentStatus($remote['status'] ?? null);
        $payment = DB::table('payments')->where('provider_payment_id', (string) $providerId)->first();
        if (! $payment && ! empty($remote['external_reference'])) {
            $payment = DB::table('payments')->where('id', $remote['external_reference'])->first();
        }
        if (! $payment) {
            return;
        }
        $this->billing->applyPayment($payment->id, $remote, $status);
        if (in_array($status, ['recusado', 'cancelado'], true)) {
            $this->vouchers->releaseForSubscription($payment->subscription_id);
        } elseif ($status === 'aprovado') {
            $this->vouchers->confirmForSubscription($payment->subscription_id);
        }
    }

    private function mapPaymentStatus(?string $status): string
    {
        return match ($status) {
            'approved' => 'aprovado',
            'rejected' => 'recusado',
            'cancelled', 'expired' => 'cancelado',
            'refunded' => 'estornado',
            'charged_back', 'in_mediation' => 'em_disputa',
            default => 'aguardando_pagamento',
        };
    }

    private function mark(string $eventId, string $status, ?string $error = null): void
    {
        DB::table('billing_provider_events')->where('id', $eventId)->update([
            'status' => $status,
            'error_message' => $error,
            'processed_at' => in_array($status, ['processed', 'ignored'], true) ? now() : null,
            'updated_at' => now(),
        ]);
    }
}
