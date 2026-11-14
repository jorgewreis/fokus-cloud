<?php

namespace App\Services;

use InvalidArgumentException;

/** Cryptographic primitives only; no endpoint or idempotency guarantee. */
final class CompanyIdempotencyKeys
{
    private const DOMAIN = 'fokus/cloud/backoffice/company-idempotency/';

    /** @var array<string, string> */
    private array $keys = [];

    /** @param array<string, string> $keyring Base64-encoded 32-byte keys. */
    public function __construct(array $keyring, private readonly string $activeKid, private readonly array $revokedKids)
    {
        foreach ($keyring as $kid => $encoded) {
            if (! is_string($kid) || ! preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D', $kid) || ! is_string($encoded)) {
                throw new InvalidArgumentException('Invalid key configuration.');
            }
            $raw = base64_decode($encoded, true);
            if ($raw === false || strlen($raw) !== 32 || base64_encode($raw) !== $encoded) {
                throw new InvalidArgumentException('Invalid key configuration.');
            }
            $this->keys[$kid] = $raw;
        }
        if (! isset($this->keys[$activeKid]) || in_array($activeKid, $revokedKids, true)) {
            throw new InvalidArgumentException('Invalid key configuration.');
        }
        foreach ($revokedKids as $kid) {
            if (! is_string($kid) || ! preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D', $kid)) {
                throw new InvalidArgumentException('Invalid key configuration.');
            }
        }
    }

    public function issue(string $actor, string $operation, int $now, int $lifetimeSeconds): string
    {
        $this->validateContext($actor, $operation);
        if ($now < 0 || $lifetimeSeconds < 1 || $lifetimeSeconds > 86400 || $now > 9999999999 - $lifetimeSeconds) {
            throw new InvalidArgumentException('Invalid token request.');
        }
        $prefix = implode('.', ['v1', $this->activeKid, (string) $now, (string) ($now + $lifetimeSeconds), self::encode(random_bytes(16))]);

        return $prefix.'.'.self::encode(hash_hmac('sha256', $this->signedMessage($prefix, $actor, $operation), $this->subkey($this->activeKid, 'token'), true));
    }

    /** @return array{kid:string,issued_at:int,expires_at:int} */
    public function verify(string $token, string $actor, string $operation, int $now, int $allowedClockSkewSeconds = 0): array
    {
        return $this->verifyToken($token, $actor, $operation, $now, $allowedClockSkewSeconds, 0);
    }

    /** Verify a token for status only, within seven days following its expiry. */
    public function verifyForStatus(string $token, string $actor, string $operation, int $now, int $allowedClockSkewSeconds = 0): array
    {
        return $this->verifyToken($token, $actor, $operation, $now, $allowedClockSkewSeconds, 604800);
    }

    /** @return array{kid:string,issued_at:int,expires_at:int} */
    private function verifyToken(string $token, string $actor, string $operation, int $now, int $allowedClockSkewSeconds, int $statusGraceSeconds): array
    {
        $this->validateContext($actor, $operation);
        if (strlen($token) > 256 || $now < 0 || $allowedClockSkewSeconds < 0 || $allowedClockSkewSeconds > 300) {
            throw new InvalidArgumentException('Invalid token.');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 6 || $parts[0] !== 'v1' || ! preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D', $parts[1])
            || ! preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $parts[2]) || ! preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $parts[3])) {
            throw new InvalidArgumentException('Invalid token.');
        }
        [, $kid, $issued, $expires, $nonce, $signature] = $parts;
        $iat = (int) $issued;
        $exp = (int) $expires;
        if (! isset($this->keys[$kid]) || in_array($kid, $this->revokedKids, true) || $iat > $now + $allowedClockSkewSeconds
            || $exp <= $iat || $exp - $iat > 86400 || $now >= $exp + $statusGraceSeconds || strlen(self::decode($nonce)) !== 16 || strlen(self::decode($signature)) !== 32) {
            throw new InvalidArgumentException('Invalid token.');
        }
        $prefix = implode('.', array_slice($parts, 0, 5));
        $expected = hash_hmac('sha256', $this->signedMessage($prefix, $actor, $operation), $this->subkey($kid, 'token'), true);
        if (! hash_equals($expected, self::decode($signature))) {
            throw new InvalidArgumentException('Invalid token.');
        }

        return ['kid' => $kid, 'issued_at' => $iat, 'expires_at' => $exp];
    }

    /** @param array<string, mixed> $canonicalPayload Caller must normalize and whitelist fields. */
    public function payloadDigest(string $kid, string $operation, ?string $target, array $canonicalPayload): string
    {
        if (! isset($this->keys[$kid]) || in_array($kid, $this->revokedKids, true)) {
            throw new InvalidArgumentException('Invalid key configuration.');
        }
        if (($operation === 'company.create' && $target !== null)
            || ($operation === 'company.update' && ($target === null || $target === '' || strlen($target) > 128))
            || ! in_array($operation, ['company.create', 'company.update'], true)) {
            throw new InvalidArgumentException('Invalid token request.');
        }
        $payload = self::canonicalize(['version' => 1, 'operation' => $operation, 'target' => $target, 'data' => $canonicalPayload]);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', $json, $this->subkey($kid, 'payload'));
    }

    private function subkey(string $kid, string $purpose): string
    {
        return hash_hkdf('sha256', $this->keys[$kid], 32, self::DOMAIN.$purpose.'/v1', hash('sha256', self::DOMAIN.'hkdf/v1', true));
    }

    private function signedMessage(string $prefix, string $actor, string $operation): string
    {
        return json_encode([$prefix, $actor, $operation], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function validateContext(string $actor, string $operation): void
    {
        if ($actor === '' || strlen($actor) > 128 || ! in_array($operation, ['company.create', 'company.update'], true)) {
            throw new InvalidArgumentException('Invalid token request.');
        }
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        if ($value === '' || ! preg_match('/\A[A-Za-z0-9_-]+\z/D', $value)) {
            throw new InvalidArgumentException('Invalid token.');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false || self::encode($decoded) !== $value) {
            throw new InvalidArgumentException('Invalid token.');
        }

        return $decoded;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::canonicalize(...), $value);
        }
        if (! is_string($value) && ! is_int($value) && ! is_bool($value) && $value !== null) {
            throw new InvalidArgumentException('Invalid canonical payload.');
        }

        return $value;
    }
}
