<?php

namespace Tests\Unit;

use App\Services\CompanyIdempotencyKeys;
use App\Services\PlatformSessionBinding;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CompanyIdempotencyKeysTest extends TestCase
{
    public function test_envelope_alone_does_not_revoke_a_previously_loaded_session_snapshot(): void
    {
        $session = new Store('synthetic', new ArraySessionHandler(120));
        $session->start();
        $binding = new PlatformSessionBinding;
        $binding->rotate($session);
        $oldSnapshot = $binding->current($session);
        $keys = new CompanyIdempotencyKeys(['a' => base64_encode(str_repeat('a', 32))], 'a', []);
        $token = $keys->issue('admin', 'company.create', 1000, 60);
        $envelope = $keys->bindSession($token, $oldSnapshot);
        $session->invalidate();
        self::assertNull($binding->current($session));
        // A concurrent request can retain this previously loaded value. The
        // signature alone cannot determine that logout has since occurred.
        self::assertSame($token, $keys->unbindSession($envelope, $oldSnapshot));
    }

    public function test_session_envelope_rejects_other_sessions_legacy_tokens_and_revoked_keys(): void
    {
        $ring = ['a' => base64_encode(str_repeat('a', 32)), 'b' => base64_encode(str_repeat('b', 32))];
        $keys = new CompanyIdempotencyKeys($ring, 'a', []);
        $token = $keys->issue('admin', 'company.create', 1000, 60);
        $binding = str_repeat('a', 64);
        $envelope = $keys->bindSession($token, $binding);
        self::assertSame($token, $keys->unbindSession($envelope, $binding));
        $rotated = new CompanyIdempotencyKeys($ring, 'b', []);
        self::assertSame($token, $rotated->unbindSession($envelope, $binding));
        $revoked = new CompanyIdempotencyKeys($ring, 'b', ['a']);
        foreach ([[$keys, $envelope, str_repeat('b', 64)], [$keys, $token, $binding],
            [$keys, $envelope.'x', $binding], [$keys, $envelope, 'invalid'], [$revoked, $envelope, $binding]] as [$verifier, $candidate, $session]) {
            try {
                $verifier->unbindSession($candidate, $session);
                self::fail('Invalid session envelope was accepted.');
            } catch (InvalidArgumentException $e) {
                self::assertStringNotContainsString($binding, $e->getMessage());
                self::assertStringNotContainsString($envelope, $e->getMessage());
            }
        }
    }

    public function test_token_is_bound_to_actor_operation_and_expiry(): void
    {
        $keys = new CompanyIdempotencyKeys(['a' => base64_encode(str_repeat('a', 32))], 'a', []);
        $token = $keys->issue('admin-a', 'company.create', 1000, 60);
        self::assertSame(['kid' => 'a', 'issued_at' => 1000, 'expires_at' => 1060], $keys->verify($token, 'admin-a', 'company.create', 1001));
        foreach ([['admin-b', 'company.create', 1001], ['admin-a', 'company.update', 1001], ['admin-a', 'company.create', 1060], ['admin-a', 'company.create', 999]] as [$actor, $operation, $now]) {
            try {
                $keys->verify($token, $actor, $operation, $now);
                self::fail('Invalid token was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_rotation_and_revocation_never_verify_a_revoked_token(): void
    {
        $ring = ['a' => base64_encode(str_repeat('a', 32)), 'b' => base64_encode(str_repeat('b', 32))];
        $token = (new CompanyIdempotencyKeys($ring, 'a', []))->issue('admin', 'company.update', 1000, 60);
        $rotated = new CompanyIdempotencyKeys($ring, 'b', []);
        self::assertSame('a', $rotated->verify($token, 'admin', 'company.update', 1001)['kid']);
        $revoked = new CompanyIdempotencyKeys($ring, 'b', ['a']);
        $this->expectException(InvalidArgumentException::class);
        $revoked->verify($token, 'admin', 'company.update', 1001);
    }

    public function test_payload_digest_is_stable_ordered_and_sensitive_to_secrets(): void
    {
        $keys = new CompanyIdempotencyKeys(['a' => base64_encode(str_repeat('a', 32))], 'a', []);
        $first = $keys->payloadDigest('a', 'company.create', null, ['password' => 'secret-one', 'nested' => ['b' => 2, 'a' => 1]]);
        self::assertSame($first, $keys->payloadDigest('a', 'company.create', null, ['nested' => ['a' => 1, 'b' => 2], 'password' => 'secret-one']));
        self::assertNotSame($first, $keys->payloadDigest('a', 'company.create', null, ['nested' => ['a' => 1, 'b' => 2], 'password' => 'secret-two']));
        self::assertNotSame($first, $keys->payloadDigest('a', 'company.update', 'EMP-1', ['password' => 'secret-one', 'nested' => ['b' => 2, 'a' => 1]]));
    }

    public function test_malformed_and_tampered_tokens_fail_closed(): void
    {
        $keys = new CompanyIdempotencyKeys(['a' => base64_encode(str_repeat('a', 32))], 'a', []);
        $token = $keys->issue('admin', 'company.create', 1000, 60);
        foreach ([$token.'x', str_replace('.1060.', '.1061.', $token), str_repeat('a', 257), 'v1.a.01000.1060.a.a'] as $bad) {
            try {
                $keys->verify($bad, 'admin', 'company.create', 1001);
                self::fail('Malformed token was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_invalid_key_configurations_are_rejected_without_echoing_material(): void
    {
        $secret = 'malformed-secret-value';
        foreach ([[['a' => $secret], 'a', []], [['a' => base64_encode(str_repeat('x', 31))], 'a', []], [['a' => base64_encode(str_repeat('x', 32))], 'missing', []], [['a' => base64_encode(str_repeat('x', 32))], 'a', ['a']]] as [$ring, $active, $revoked]) {
            try {
                new CompanyIdempotencyKeys($ring, $active, $revoked);
                self::fail('Invalid configuration was accepted.');
            } catch (InvalidArgumentException $e) {
                self::assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    public function test_new_tokens_have_independent_nonces(): void
    {
        $keys = new CompanyIdempotencyKeys(['a' => base64_encode(str_repeat('a', 32))], 'a', []);
        self::assertNotSame($keys->issue('admin', 'company.create', 1000, 60), $keys->issue('admin', 'company.create', 1000, 60));
    }

    public function test_expired_token_can_be_used_only_for_status_during_grace_period(): void
    {
        $keys = new CompanyIdempotencyKeys(['a' => base64_encode(str_repeat('a', 32))], 'a', []);
        $token = $keys->issue('admin', 'company.create', 1000, 60);
        self::assertSame('a', $keys->verifyForStatus($token, 'admin', 'company.create', 1060)['kid']);
        try {
            $keys->verify($token, 'admin', 'company.create', 1060);
            self::fail('Expired token allowed mutation.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->expectException(InvalidArgumentException::class);
        $keys->verifyForStatus($token, 'admin', 'company.create', 1060 + 604800);
    }
}
