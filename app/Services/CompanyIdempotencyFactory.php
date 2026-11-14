<?php

namespace App\Services;

use InvalidArgumentException;
use JsonException;
use stdClass;

/** Parse isolated configuration; this does not activate a public endpoint. */
final class CompanyIdempotencyFactory
{
    /** @return array{store:CompanyIdempotencyStore,lifetime_seconds:int} */
    public function build(array $config, ?string $sessionMarker = null, ?string $sessionId = null): array
    {
        if (($config['enabled'] ?? false) !== true || ! is_string($sessionMarker)
            || preg_match('/\A[0-9a-f]{64}\z/D', $sessionMarker) !== 1
            || ! is_string($sessionId) || preg_match('/\A[A-Za-z0-9]{40}\z/D', $sessionId) !== 1) {
            throw new InvalidArgumentException('Company idempotency is unavailable.');
        }

        try {
            $ring = $this->json($config['keyring_json'] ?? null);
            $revoked = $this->json($config['revoked_kids_json'] ?? null);
            if (! $ring instanceof stdClass || ! is_array($revoked) || ! array_is_list($revoked)
                || ! is_string($config['active_kid'] ?? null)) {
                throw new InvalidArgumentException;
            }
            foreach ($revoked as $kid) {
                if (! is_string($kid)) {
                    throw new InvalidArgumentException;
                }
            }
            if (count(array_unique($revoked, SORT_STRING)) !== count($revoked)) {
                throw new InvalidArgumentException;
            }
            $outstanding = $this->positiveInteger($config['max_outstanding'] ?? null);
            $issues = $this->positiveInteger($config['max_issues_per_window'] ?? null);
            $window = $this->positiveInteger($config['issuance_window_seconds'] ?? null);
            $lifetime = $this->positiveInteger($config['token_lifetime_seconds'] ?? null);
            if ($lifetime > 86400) {
                throw new InvalidArgumentException;
            }
            $keys = new CompanyIdempotencyKeys(get_object_vars($ring), $config['active_kid'], $revoked);
            $store = new CompanyIdempotencyStore($keys, new CompanyIdempotencyAuthorization, $outstanding, $issues, $window, new PlatformSessionAuthority, $sessionMarker, $sessionId);

            return ['store' => $store, 'session_keys' => $keys, 'lifetime_seconds' => $lifetime];
        } catch (InvalidArgumentException|JsonException) {
            // Never leak secret-bearing configuration through exceptions or logs.
            throw new InvalidArgumentException('Company idempotency is unavailable.');
        }
    }

    private function json(mixed $input): mixed
    {
        if (! is_string($input) || $input === '' || strlen($input) > 65536) {
            throw new InvalidArgumentException;
        }
        $value = json_decode($input, false, 32, JSON_THROW_ON_ERROR);
        // Canonical input makes duplicate JSON members unrepresentable: decoding
        // and re-encoding must retain the exact bytes and ordering supplied.
        if (json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) !== $input) {
            throw new InvalidArgumentException;
        }

        return $value;
    }

    private function positiveInteger(mixed $value): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value)) {
            $number = (int) $value;
            if ((string) $number !== $value) {
                throw new InvalidArgumentException;
            }
        } else {
            throw new InvalidArgumentException;
        }
        if ($number < 1) {
            throw new InvalidArgumentException;
        }

        return $number;
    }
}
