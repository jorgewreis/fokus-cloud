<?php

namespace App\Services;

class AuditSanitizer
{
    private const SENSITIVE_KEY = '/password|passwd|secret|token|authorization|cookie|mfa|otp|verification.?code|access.?code|security.?code|cvv|cvc|card.?number|pan|cpf|cnpj|document.?number|identification.?number|raw.?payload/i';

    public function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($value instanceof \Throwable) {
            return ['exception_class' => $value::class, 'message' => $this->sanitizeText($value->getMessage())];
        }
        if ($key !== null && preg_match(self::SENSITIVE_KEY, $key)) {
            return '[redigido]';
        }

        if (is_object($value)) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $childKey => $child) {
                $result[$childKey] = $this->sanitize($child, (string) $childKey);
            }
            return $result;
        }
        if (is_string($value)) {
            if ($key !== null && str_contains(strtolower($key), 'email')) {
                return preg_replace('/^(.{2}).+(@.+)$/', '$1***$2', $value) ?? '[redigido]';
            }
            return $this->sanitizeText($value);
        }
        return $value;
    }

    public function sanitizeText(string $value): string
    {
        $value = preg_replace('/(mfa|otp|code|c[oó]digo(?:\s+de\s+acesso)?)\s+\d{4,8}\b/iu', '$1 [redigido]', $value) ?? $value;
        $value = preg_replace('/(password|senha|token|secret|authorization|mfa|otp|codigo|código)\s*[:= ]\s*[^\s,;]+/iu', '$1=[redigido]', $value) ?? $value;
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [redigido]', $value) ?? $value;
        $value = preg_replace('/\b(?:APP_USR|TEST|PROD)[-_][A-Za-z0-9_-]{16,}\b/i', '[credencial redigida]', $value) ?? $value;
        $value = preg_replace('/(?<!\d)(?:\d[ .\/-]?){10}\d(?!\d)|(?<!\d)(?:\d[ .\/-]?){13,18}\d(?!\d)/', '[documento/cartão redigido]', $value) ?? $value;
        $value = preg_replace('/([\w.+-])[\w.+-]*(@[\w.-]+\.[A-Za-z]{2,})/', '$1***$2', $value) ?? $value;
        return trim($value);
    }

    public function sanitizeLogValue(mixed $value): mixed
    {
        if ($value instanceof \Throwable) {
            return ['exception_class' => $value::class, 'message' => $this->sanitizeText($value->getMessage())];
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $child) $safe[$key] = $child instanceof \Throwable
                ? $this->sanitizeLogValue($child)
                : $this->sanitizeLogValue($this->sanitize($child, (string) $key));
            return $safe;
        }
        if (is_object($value)) return $this->sanitizeLogValue((array) $value);
        return is_string($value) ? $this->sanitizeText($value) : $value;
    }

    public function providerPayload(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            return null;
        }

        $allowed = '/^(id|type|topic|action|status|status_detail|external_reference|preapproval_id|subscription_id|payment_id|refund_id|date_[a-z_]+|transaction_amount|amount|currency_id|currency|payer_email|reason|collector_id|live_mode|api_version)$/i';
        $result = [];
        foreach ($value as $key => $child) {
            $name = (string) $key;
            if (preg_match(AuditSanitizer::SENSITIVE_KEY, $name)) {
                $result[$name] = '[REDACTED]';
            } elseif (preg_match($allowed, $name)) {
                $result[$name] = is_array($child) || is_object($child) ? $this->providerPayload($child) : $this->sanitize($child, $name);
            }
        }
        return $result;
    }
}
