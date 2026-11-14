<?php

namespace Tests\Unit;

use App\Services\CompanyIdempotencyFactory;
use App\Services\CompanyIdempotencyStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CompanyIdempotencyFactoryTest extends TestCase
{
    public function test_explicit_synthetic_configuration_builds_a_store(): void
    {
        $result = (new CompanyIdempotencyFactory)->build($this->validConfig());
        self::assertInstanceOf(CompanyIdempotencyStore::class, $result['store']);
        self::assertSame(600, $result['lifetime_seconds']);
    }

    public function test_disabled_missing_or_malformed_configuration_fails_closed_without_echoing_material(): void
    {
        $base = $this->validConfig();
        $secret = base64_encode(str_repeat('x', 32));
        $cases = [
            ['enabled' => false],
            ['enabled' => 'true'],
            ['keyring_json' => null],
            ['keyring_json' => '{"v1":"'.$secret.'","v1":"'.$secret.'"}'],
            ['keyring_json' => '{ "v1":"'.$secret.'"}'],
            ['keyring_json' => '{"v1":"'.$secret.'"'],
            ['keyring_json' => '{"v1":"not-base64"}'],
            ['active_kid' => 'unknown'],
            ['revoked_kids_json' => null],
            ['revoked_kids_json' => '["v1"]'],
            ['revoked_kids_json' => '["other","other"]'],
            ['revoked_kids_json' => '[{}]'],
            ['max_outstanding' => '0'],
            ['max_outstanding' => '1001'],
            ['max_issues_per_window' => '-1'],
            ['issuance_window_seconds' => '1e3'],
            ['token_lifetime_seconds' => '86401'],
        ];
        foreach ($cases as $case) {
            try {
                (new CompanyIdempotencyFactory)->build(array_replace($base, $case));
                self::fail('Invalid configuration was accepted.');
            } catch (InvalidArgumentException $e) {
                self::assertSame('Company idempotency is unavailable.', $e->getMessage());
                self::assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    private function validConfig(): array
    {
        return [
            'enabled' => true,
            'keyring_json' => '{"v1":"'.base64_encode(str_repeat('x', 32)).'"}',
            'active_kid' => 'v1',
            'revoked_kids_json' => '[]',
            'max_outstanding' => '2',
            'max_issues_per_window' => '3',
            'issuance_window_seconds' => '60',
            'token_lifetime_seconds' => '600',
        ];
    }
}
