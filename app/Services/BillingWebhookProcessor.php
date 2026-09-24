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
        $type = $this->safeEventLabel((string) ($request->input('type') ?: $request->input('topic')));
        $action = $this->safeEventLabel((string) $request->input('action'));
        $resourceId = $this->safeIdentifier((string) ($request->query('data_id') ?: $request->query('data.id') ?: $request->input('data.id')));
        $requestId = $this->safeIdentifier((string) $request->header('x-request-id'));
        $notificationId = $this->safeIdentifier((string) ($request->input('id') ?: $request->header('x-notification-id')));
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
                $this->processPreapproval($resourceId, $requestId);
            } elseif ($type === 'subscription_authorized_payment') {
                $this->processAuthorizedPayment($resourceId, $requestId);
            } elseif ($type === 'payment') {
                $this->processPayment($resourceId, $requestId);
            } else {
                $this->mark($eventId, 'ignored');
                return 'ignored';
            }
            $this->mark($eventId, 'processed');
            return 'processed';
        } catch (\Throwable $exception) {
            $this->mark($eventId, 'failed', app(AuditSanitizer::class)->sanitizeText(mb_substr($exception->getMessage(), 0, 1000)));
            throw $exception;
        }
    }

    private function processPreapproval(string $providerId, ?string $requestId = null): void
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
        if ($status !== $subscription->status) {
            app(AuditRecorder::class)->company($subscription->company_id, null, 'subscription', $subscription->id, 'update', ['status' => $subscription->status], ['status' => $status],
                reason: 'Estado confirmado pelo Mercado Pago.', actorType: 'gateway', channel: 'webhook', originContext: '/api/webhooks/mercado-pago', correlationId: $requestId);
            app(AuditRecorder::class)->platform(null, 'billing.subscription_status_updated', 'subscription', $subscription->id, $subscription->company_id,
                'Estado confirmado pelo Mercado Pago.', before: ['status' => $subscription->status], after: ['status' => $status], actorType: 'gateway', channel: 'webhook',
                originContext: '/api/webhooks/mercado-pago', correlationId: $requestId);
        }
        if ($status === 'ativa') {
            $this->vouchers->confirmForSubscription($subscription->id, channel: 'webhook', correlationId: $requestId);
        } elseif ($status === 'encerrada') {
            $this->vouchers->releaseForSubscription($subscription->id, $requestId);
        }
    }

    private function processAuthorizedPayment(string $authorizedId, ?string $requestId = null): void
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
        $this->billing->applyPayment($payment->id, [...$paymentRemote, 'preapproval_id' => $remote['preapproval_id'] ?? $paymentRemote['preapproval_id'] ?? null], $status, $requestId);
        DB::table('payments')->where('id', $payment->id)->update([
            'provider_authorized_payment_id' => $authorizedId,
            'provider_payload_sanitized' => json_encode($this->client->sanitizePayload($remote)),
            'updated_at' => now(),
        ]);
        if (in_array($status, ['recusado', 'cancelado'], true)) {
            $this->vouchers->releaseForSubscription($payment->subscription_id, $requestId);
        } elseif ($status === 'aprovado') {
            $this->vouchers->confirmForSubscription($payment->subscription_id, channel: 'webhook', correlationId: $requestId);
        }
    }

    private function processPayment(string $providerId, ?string $requestId = null): void
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
        $this->billing->applyPayment($payment->id, $remote, $status, $requestId);
        if (in_array($status, ['recusado', 'cancelado'], true)) {
            $this->vouchers->releaseForSubscription($payment->subscription_id, $requestId);
        } elseif ($status === 'aprovado') {
            $this->vouchers->confirmForSubscription($payment->subscription_id, channel: 'webhook', correlationId: $requestId);
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

    private function safeIdentifier(string $value): ?string
    {
        $value = app(AuditSanitizer::class)->sanitizeText(trim($value));
        if ($value === '' || ! preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $value) || str_contains($value, '[redigido]')) return null;
        return $value;
    }

    private function safeEventLabel(string $value): ?string
    {
        $value = app(AuditSanitizer::class)->sanitizeText(trim($value));
        $value = preg_replace('/[^A-Za-z0-9_.:-]/', '', $value) ?? '';
        return $value === '' ? null : mb_substr($value, 0, 160);
    }
}
