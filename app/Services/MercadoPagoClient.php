<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MercadoPagoClient
{
    private function request(): PendingRequest
    {
        $token = (string) config('services.mercado_pago.access_token');
        if ($token === '') {
            throw new RuntimeException('Mercado Pago não configurado.');
        }

        $request = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.mercado_pago.timeout', 10));

        return $request;
    }

    public function createPreapproval(array $payload, ?string $idempotencyKey = null): array
    {
        return $this->send('post', '/preapproval', $payload, $idempotencyKey);
    }

    public function createPreference(array $payload, ?string $idempotencyKey = null): array
    {
        return $this->send('post', '/checkout/preferences', $payload, $idempotencyKey);
    }

    public function payerEmail(string $customerEmail): string
    {
        if (config('services.mercado_pago.environment') === 'sandbox') {
            // O Mercado Pago não aceita o e-mail real do cliente no sandbox.
            // O fallback documentado permite que o checkout continue funcional
            // mesmo quando a variável opcional não foi definida no servidor.
            $testPayerEmail = trim((string) config('services.mercado_pago.test_payer_email', 'test@testuser.com'));

            return filter_var($testPayerEmail, FILTER_VALIDATE_EMAIL)
                ? $testPayerEmail
                : 'test@testuser.com';
        }

        $customerEmail = trim($customerEmail);
        if (! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('O e-mail do cliente não é válido para criar a assinatura.');
        }

        return $customerEmail;
    }

    public function getPreapproval(string $providerId): array
    {
        return $this->send('get', '/preapproval/'.rawurlencode($providerId));
    }

    public function updatePreapproval(string $providerId, array $payload, ?string $idempotencyKey = null): array
    {
        return $this->send('put', '/preapproval/'.rawurlencode($providerId), $payload, $idempotencyKey);
    }

    public function getPayment(string $providerId): array
    {
        return $this->send('get', '/v1/payments/'.rawurlencode($providerId));
    }

    public function getAuthorizedPayment(string $providerId): array
    {
        return $this->send('get', '/authorized_payments/'.rawurlencode($providerId));
    }

    public function searchAuthorizedPayments(string $providerSubscriptionId, int $limit = 50): array
    {
        return $this->send('get', '/authorized_payments/search', query: [
            'preapproval_id' => $providerSubscriptionId,
            'limit' => min(max($limit, 1), 100),
        ]);
    }

    public function createRefund(string $providerPaymentId, ?float $amount, string $idempotencyKey): array
    {
        $payload = $amount === null ? [] : ['amount' => round($amount, 2)];

        return $this->send(
            'post',
            '/v1/payments/'.rawurlencode($providerPaymentId).'/refunds',
            $payload,
            $idempotencyKey,
        );
    }

    public function getRefund(string $providerPaymentId, string $providerRefundId): array
    {
        return $this->send('get', '/v1/payments/'.rawurlencode($providerPaymentId).'/refunds/'.rawurlencode($providerRefundId));
    }

    public function listRefunds(string $providerPaymentId): array
    {
        return $this->send('get', '/v1/payments/'.rawurlencode($providerPaymentId).'/refunds');
    }

    public function sanitizePayload(mixed $payload): mixed
    {
        return app(AuditSanitizer::class)->providerPayload($payload);
    }

    private function send(string $method, string $path, array $payload = [], ?string $idempotencyKey = null, array $query = []): array
    {
        $url = rtrim((string) config('services.mercado_pago.api_base_url', 'https://api.mercadopago.com'), '/').$path;
        $request = $this->request();
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $request = $request->withHeaders(['X-Idempotency-Key' => $idempotencyKey]);
        }

        try {
            $response = $method === 'get'
                ? $request->get($url, $query ?: $payload)
                : $request->{$method}($url, $payload);
            if ($response->failed()) {
                throw new RuntimeException('Mercado Pago respondeu com status HTTP '.$response->status().'.');
            }
            return $response->json() ?? [];
        } catch (RuntimeException $exception) {
            if (str_starts_with($exception->getMessage(), 'Mercado Pago respondeu com status HTTP')) throw $exception;
            throw new RuntimeException('Falha na comunicação com Mercado Pago.');
        } catch (\Throwable) {
            throw new RuntimeException('Falha na comunicação com Mercado Pago.');
        }
    }
}
